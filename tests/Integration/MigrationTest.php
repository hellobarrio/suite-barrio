<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Forms\Integrations;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Notifications;
use BarrioSuite\Forms\Privacy;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Forms\Submission\FileStore;
use BarrioSuite\Mail\Smtp;
use WP_REST_Request;
use WP_UnitTestCase;

// L'estensione barrio-suite-migration (caricata da tests/bootstrap.php) su un form del prototipo con
// traduzioni, registrazioni dal sito e da GraphQL, un file e le impostazioni globali.
final class MigrationTest extends WP_UnitTestCase {

	private const ID = 7;

	private string $file = '';

	// Tabelle vere, come quelle del prototipo: le temporanee che i test creano non compaiono in SHOW TABLES.
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		global $wpdb;
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpb_forms (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(255) NOT NULL DEFAULT '', fields LONGTEXT NOT NULL, settings LONGTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id))" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpb_form_submissions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, form_id BIGINT UNSIGNED NOT NULL, data LONGTEXT NOT NULL, lang VARCHAR(10) NOT NULL DEFAULT '', ip VARCHAR(45) NOT NULL DEFAULT '', user_agent TEXT, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY form_id (form_id))" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpb_forms_translations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, form_id BIGINT UNSIGNED NOT NULL, lang VARCHAR(10) NOT NULL DEFAULT '', fields_i18n LONGTEXT NOT NULL, settings_i18n LONGTEXT NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY form_lang (form_id, lang))" );
	}

	public static function tear_down_after_class(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpb_forms, {$wpdb->prefix}wpb_form_submissions, {$wpdb->prefix}wpb_forms_translations" );
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		add_filter( 'barrio_languages', static fn(): array => [ 'it', 'en' ] );
		$this->prototype();
	}

	public function tear_down(): void {
		// I file sono sul disco: il rollback del test toglie solo le righe.
		EntryRepository::delete_many( self::ID, null );
		wp_delete_file( $this->file );
		parent::tear_down();
	}

	public function test_the_form_keeps_its_id_and_gets_fields_texts_notifications_and_integrations(): void {
		$report = barrio_migration_run( false );

		$this->assertIsArray( $report );
		$this->assertSame(
			[
				'id'            => self::ID,
				'title'         => 'Contatti',
				'entries'       => 2,
				'migrated'      => 2,
				'files'         => 1,
				'missing_files' => 0,
				'result'        => 'migrated',
			],
			$report['forms'][0]
		);
		$form = FormRepository::get( self::ID );
		$this->assertNotNull( $form );
		// La definizione attuale: senza il campo che c'è solo nelle registrazioni vecchie.
		$this->assertSame( [ 'nome', 'email', 'servizio', 'interessi', 'data', 'page_break', 'cv', 'privacy', 'sedi' ], array_column( $form->current->fields, 'key' ) );
		// La checkbox della privacy con il link è un consenso, con il link in ogni lingua.
		$privacy = $form->current->fields[7];
		$this->assertSame( [ 'consent', true, [] ], [ $privacy->type, $privacy->required, $privacy->config ] );
		$this->assertSame( 'Accetto la <a href="/privacy/">privacy</a>', $privacy->i18n['it']['label'] );
		$this->assertSame( 'I accept the <a href="/en/privacy/">privacy policy</a>', $privacy->i18n['en']['label'] );
		$this->assertSame( 2, $form->current->version );

		$service = $form->current->fields[2];
		$this->assertSame( [ 'choices' => [ 'Consulenza', 'Preventivo gratuito' ] ], $service->config );
		// assertEquals: MySQL 8.0 riordina le chiavi del JSON.
		$this->assertEquals(
			[
				'multiple' => true,
				'choices'  => [ 'Torino', 'Milano' ],
			],
			$form->current->fields[8]->config
		);
		$this->assertSame(
			[
				'Consulenza'          => 'Consulting',
				'Preventivo gratuito' => 'Free quote',
			],
			$service->i18n['en']['options']
		);
		$this->assertSame( 'Service', $service->i18n['en']['label'] );
		// Il placeholder non tradotto è quello della lingua principale.
		$this->assertSame( 'Il tuo nome', $form->current->fields[0]->i18n['en']['placeholder'] );
		// assertEquals: MySQL 8 riordina le chiavi degli oggetti JSON.
		$this->assertEquals(
			[
				'action' => 'show',
				'logic'  => 'all',
				'rules'  => [
					[
						'field'    => 'servizio',
						'operator' => '=',
						'value'    => 'Consulenza',
					],
				],
			],
			$form->current->fields[4]->conditions
		);
		$this->assertSame(
			[
				'en' => [
					'submit_label'    => 'Send',
					'success_message' => 'Thank you!',
					'success_url'     => null,
				],
				'it' => [
					'submit_label'    => 'Invia',
					'success_message' => 'Grazie!',
					'success_url'     => null,
				],
			],
			$form->i18n
		);

		$notification = $form->notifications[0];
		$this->assertSame( [ 'Admin', 'info@example.com', '{{email}}', true ], [ $notification->name, $notification->recipients, $notification->reply_to, $notification->attach_files ] );
		$this->assertSame( 'Nuovo contatto da {{nome}}', $notification->i18n['it']['subject'] );
		$this->assertSame( 'New contact from {{nome}}', $notification->i18n['en']['subject'] );
		$this->assertSame( "{{all_fields}}\n\n{{source_url}}", $notification->i18n['en']['body'] );

		[ $mailchimp, $mailup ] = $form->integrations;
		$this->assertEquals(
			[
				'email_field'   => 'email',
				'merge_fields'  => [ [ 'FNAME', 'nome' ] ],
				'status'        => 'subscribed',
				'consent_field' => 'interessi',
			],
			$mailchimp->settings
		);
		$this->assertSame( [ 'list-it', 'list-en' ], [ $mailchimp->i18n['it']['list_id'], $mailchimp->i18n['en']['list_id'] ] );
		// Come nel prototipo, ma il default di Barrio Suite è la conferma via email: chi migra lo deve sapere.
		$this->assertNotEmpty( preg_grep( '/Mailchimp subscribes new contacts without the confirmation email/', $report['warnings'] ) );
		// MailUp iscriveva solo con l'opt-in: è la condizione dell'integrazione, per la futura estensione.
		$this->assertSame(
			[
				'list_id'  => '1',
				'group_id' => '3',
			],
			$mailup->i18n['it']
		);
		$this->assertSame( 'not_empty', $mailup->conditions['rules'][0]['operator'] ?? null );
	}

	public function test_entries_keep_values_date_page_and_file_without_the_prototype_formats(): void {
		global $wpdb;
		barrio_migration_run( false );

		[ $site, $graphql ] = EntryRepository::page( self::ID, [], 'id', 'asc', 1, 20 )['entries'];
		$this->assertSame(
			[
				'nome'      => [ 'Ada' ],
				'email'     => [ 'ada@example.com' ],
				'servizio'  => [ 'Consulenza' ],
				'interessi' => [ 'News', 'Eventi' ],
				'data'      => [ '2026-05-04' ],
				'privacy'   => [ '1' ],
				'sedi'      => [ 'Milano' ],
			],
			$this->values( $site )
		);
		$this->assertSame( 'it', $site->lang );
		$this->assertTrue( $site->is_read );
		$this->assertSame( 'https://example.org/contatti/', $site->source_url );
		// Il prototipo scriveva l'ora della sessione MySQL; Barrio Suite la vuole in UTC.
		$this->assertSame( gmdate( 'Y-m-d H:i:s', (int) $wpdb->get_var( "SELECT UNIX_TIMESTAMP('2026-01-02 03:04:05')" ) ), $site->created_at );

		$this->assertCount( 1, $site->files );
		$file = $site->files[0];
		$this->assertSame( [ 'cv.pdf', 'application/pdf' ], [ $file['original_name'], $file['mime_type'] ] );
		$this->assertFileExists( FileStore::dir() . '/' . $file['stored_path'] );
		$this->assertFileDoesNotExist( $this->file );

		// Da GraphQL, in inglese: etichette tradotte, checkbox "Etichetta: true", data dd/mm/yyyy.
		$this->assertSame(
			[
				'nome'          => [ 'Bob' ],
				'servizio'      => [ 'Preventivo gratuito' ],
				'interessi'     => [ 'Eventi' ],
				'data'          => [ '2026-05-04' ],
				'privacy'       => [ '1' ],
				'sedi'          => [ 'Torino', 'Milano' ],
				'vecchio_campo' => [ 'Un valore' ],
			],
			$this->values( $graphql )
		);
		$this->assertSame( 'en', $graphql->lang );
		$this->assertNull( $graphql->source_url );
		// Le registrazioni puntano alla versione 1, l'archivio con il campo che oggi non c'è.
		$this->assertSame( 1, FormRepository::version( $graphql->version_id )?->version );
	}

	public function test_a_dry_run_reports_the_same_counts_and_changes_nothing(): void {
		$report = barrio_migration_run( true );

		$this->assertIsArray( $report );
		$this->assertSame( [ 2, 2, 1, 'ready' ], [ $report['forms'][0]['entries'], $report['forms'][0]['migrated'], $report['forms'][0]['files'], $report['forms'][0]['result'] ] );
		$this->assertNull( FormRepository::get( self::ID ) );
		$this->assertFileExists( $this->file );
		$this->assertSame( '', Smtp::settings()['host'] );
	}

	// Un'estensione che il sito non ammette farebbe rifiutare il form intero.
	public function test_file_fields_only_get_the_extensions_wordpress_allows(): void {
		add_filter(
			'upload_mimes',
			static fn(): array => [
				'pdf'      => 'application/pdf',
				'jpg|jpeg' => 'image/jpeg',
			]
		);

		$this->assertSame( 'migrated', barrio_migration_run( false )['forms'][0]['result'] );
		$extensions = array_column( array_filter( FormRepository::get( self::ID )->current->fields, static fn( $field ): bool => 'file' === $field->type ), 'config' );
		$this->assertSame( [ [ 'extensions' => [ 'jpg', 'jpeg', 'pdf' ] ] ], $extensions );
	}

	public function test_a_form_that_fails_is_rolled_back_with_the_copies_of_its_files(): void {
		global $wpdb;
		$wpdb->suppress_errors();
		$copies = static fn(): array => glob( FileStore::dir() . '/' . gmdate( 'Y/m/' ) . '*' ) ?: [];
		$before = $copies();
		// La seconda registrazione non entra, quando il file della prima è già nella cartella protetta.
		add_filter( 'query', static fn( string $query ): string => str_contains( $query, "'Bob'" ) ? 'SELECT broken' : $query );

		$report = barrio_migration_run( false );

		$this->assertIsArray( $report );
		$this->assertStringStartsWith( 'error: ', $report['forms'][0]['result'] );
		$this->assertNull( FormRepository::get( self::ID ) );
		$this->assertFileExists( $this->file );
		$this->assertSame( $before, $copies() );
	}

	// Un errore fatale (il tempo massimo) salta il catch: le copie restano senza una riga che le nomini, e un object
	// cache persistente tiene il form che il rollback ha tolto.
	public function test_after_a_fatal_error_the_copies_go_and_the_form_migrates_again(): void {
		global $wpdb;
		$copy = gmdate( 'Y/m/' ) . 'orfano.pdf';
		wp_mkdir_p( dirname( FileStore::dir() . '/' . $copy ) );
		file_put_contents( FileStore::dir() . '/' . $copy, 'pdf' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		barrio_migration_form_orphans( self::ID, [ $copy ] );
		$this->assertFileDoesNotExist( FileStore::dir() . '/' . $copy );

		$wpdb->query( 'SAVEPOINT interrotto' );
		FormRepository::create( 'Interrotto', [ 'it' => [ 'submit_label' => 'Invia' ] ], [], new FormVersion( [] ), [], self::ID );
		$wpdb->query( 'ROLLBACK TO SAVEPOINT interrotto' );
		$this->assertSame( 'migrated', barrio_migration_run( false )['forms'][0]['result'] );

		// Un form già confermato tiene i suoi file.
		$stored = array_merge( ...array_column( EntryRepository::page( self::ID, [], 'id', 'asc', 1, 20 )['entries'], 'files' ) )[0]['stored_path'];
		barrio_migration_form_orphans( self::ID, [ $stored ] );
		$this->assertFileExists( FileStore::dir() . '/' . $stored );
	}

	// Il form di Barrio Suite prenderebbe il posto di quello del prototipo nelle pagine e nei frontend.
	public function test_an_id_taken_by_another_barrio_suite_form_is_an_error(): void {
		FormRepository::create( 'Altro', [ 'it' => [ 'submit_label' => 'Invia' ] ], [], new FormVersion( [] ), [], self::ID );
		$post = self::factory()->post->create( [ 'post_content' => '[wpb_form id="' . self::ID . '"]' ] );

		$report = barrio_migration_run( false );

		$this->assertIsArray( $report );
		$this->assertSame( 'error: another Barrio Suite form has this ID', $report['forms'][0]['result'] );
		$this->assertSame( 'Altro', FormRepository::get( self::ID )?->title );
		// Lo shortcode riscritto mostrerebbe l'altro form.
		$this->assertContains( '1 forms are not migrated: fix them and migrate again. The shortcodes in the pages are rewritten once every form is in Barrio Suite.', $report['warnings'] );
		$this->assertSame( '[wpb_form id="' . self::ID . '"]', get_post( $post )->post_content );
	}

	public function test_a_second_run_skips_the_forms_already_migrated(): void {
		barrio_migration_run( false );

		$report = barrio_migration_run( false );

		$this->assertIsArray( $report );
		$this->assertSame( 'skipped: already migrated', $report['forms'][0]['result'] );
		$this->assertSame( 2, EntryRepository::page( self::ID, [], 'id', 'desc', 1, 1 )['total'] );
	}

	public function test_global_settings_go_where_barrio_suite_keeps_them(): void {
		$report = barrio_migration_run( false );

		$this->assertSame(
			[
				'host'       => 'in-v3.mailjet.com',
				'port'       => 587,
				'encryption' => 'tls',
				'username'   => 'mj-public',
				'from_email' => 'noreply@example.com',
				'from_name'  => 'Sito',
			],
			Smtp::settings()
		);
		$this->assertSame( 'saved', Smtp::password_status() );
		$this->assertSame( 'abc123-us21', Integrations::settings( 'mailchimp' )['api_key'] );
		$this->assertSame( [ 'site_key' => 'site-key-123' ], Integrations::settings( 'recaptcha' ), 'Not the project and the API key: the service account replaces them.' );
		$this->assertIsArray( $report );
		$this->assertNotEmpty( preg_grep( '/^reCAPTCHA: the API key of Suite Barrio is not migrated/', $report['warnings'] ) );
		$this->assertSame( 730, Privacy::settings()['retention_days'] );
		$this->assertSame( [ '#ffffff', '#123456' ], [ Notifications::settings()['background_color'], Notifications::settings()['accent_color'] ] );
	}

	public function test_the_old_shortcodes_show_the_migrated_form(): void {
		barrio_migration_run( false );

		$this->assertStringContainsString( 'barrio/v1/forms/' . self::ID . '/entries', do_shortcode( '[suite_barrio_form id="' . self::ID . '"]' ) );
		$this->assertStringContainsString( 'data-barrio-form', do_shortcode( '[wpb_form id="' . self::ID . '"]' ) );
	}

	// Dove WordPress li salva, anche serializzati: in un meta o in un'opzione la lunghezza del testo cambia.
	public function test_the_old_shortcodes_are_rewritten_in_contents_serialized_meta_and_widgets(): void {
		$post = self::factory()->post->create( [ 'post_content' => 'Scrivici: [wpb_form id="' . self::ID . '"]' ] );
		add_post_meta( $post, 'builder', [ 'blocks' => [ [ 'text' => '[suite_barrio_form id="' . self::ID . '"]' ] ] ] );
		// Alcuni page builder salvano oggetti.
		add_post_meta( $post, 'builder_object', (object) [ 'rows' => [ (object) [ 'text' => '[wpb_form id="' . self::ID . '"]' ] ] ] );
		update_option( 'widget_text', [ 2 => [ 'text' => '[wpb_form id="' . self::ID . '"]' ] ] );

		$dry = barrio_migration_run( true );

		$this->assertIsArray( $dry );
		$this->assertContains( 'Shortcodes to rewrite as [barrio_form]: 1 contents, 2 meta, 1 options.', $dry['notes'] );
		$this->assertSame( 'Scrivici: [wpb_form id="' . self::ID . '"]', get_post( $post )->post_content );

		$report = barrio_migration_run( false );

		$this->assertIsArray( $report );
		$this->assertContains( 'Shortcodes rewritten as [barrio_form]: 1 contents, 2 meta, 1 options.', $report['notes'] );
		$this->assertSame( 'Scrivici: [barrio_form id="' . self::ID . '"]', get_post( $post )->post_content );
		$this->assertSame( [ 'blocks' => [ [ 'text' => '[barrio_form id="' . self::ID . '"]' ] ] ], get_post_meta( $post, 'builder', true ) );
		$this->assertSame( '[barrio_form id="' . self::ID . '"]', get_post_meta( $post, 'builder_object', true )->rows[0]->text );
		$this->assertSame( [ 2 => [ 'text' => '[barrio_form id="' . self::ID . '"]' ] ], get_option( 'widget_text' ) );
	}

	// La pagina Migrazione: un form per richiesta, poi una senza form per il resto, come una migrazione sola.
	public function test_from_the_route_one_form_per_request_then_the_rest_give_the_report_of_one_run(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$whole = barrio_migration_run( true );
		$step  = current( wp_list_filter( barrio_migration_steps(), [ 'id' => 'forms' ] ) );

		$form = $this->post( [ 'item' => self::ID ] );
		$rest = $this->post( [] );

		$this->assertIsArray( $whole );
		$this->assertSame( [ 'pending', [ self::ID ] ], [ $step['status'], $step['items'] ] );
		$this->assertSame( $whole['forms'], $form['rows'] );
		$this->assertSame( [], $rest['rows'] );
		$this->assertSame( $whole['notes'], [ ...$form['notes'], ...$rest['notes'] ] );
		$this->assertSame( $whole['warnings'], [ ...$form['warnings'], ...$rest['warnings'] ] );
		$this->assertNull( FormRepository::get( self::ID ) );

		$this->assertSame( 'migrated', $this->post( [ 'item' => self::ID ], false )['rows'][0]['result'] );
		$this->assertSame( 'migrated', $this->post( [], false )['step']['status'] );
		$this->assertSame( 2, EntryRepository::page( self::ID, [], 'id', 'desc', 1, 1 )['total'] );
	}

	// Un prototipo modificato per un sito: ciò che il prototipo non ha mai avuto, pieno, un avviso per chiave.
	public function test_what_suite_barrio_never_had_gives_a_warning_each_also_in_a_dry_run(): void {
		global $wpdb;
		$table                                     = "{$wpdb->prefix}wpb_forms";
		$row                                       = $wpdb->get_row( $wpdb->prepare( 'SELECT fields, settings FROM %i WHERE id = %d', $table, self::ID ), ARRAY_A );
		$fields                                    = json_decode( $row['fields'], true );
		$settings                                  = json_decode( $row['settings'], true );
		$fields[0]['width']                        = 'half';
		$fields[0]['icon']                         = '';
		$settings['hubspot_enabled']               = true;
		$settings['hubspot_portal_id']             = '123';
		$settings['notifications'][0]['bcc_email'] = 'bcc@example.com';
		$wpdb->update(
			$table,
			[
				'fields'   => wp_json_encode( $fields ),
				'settings' => wp_json_encode( $settings ),
			],
			[ 'id' => self::ID ]
		);
		$entries = "{$wpdb->prefix}wpb_form_submissions";
		$entry   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, data FROM %i WHERE form_id = %d ORDER BY id LIMIT 1', $entries, self::ID ), ARRAY_A );
		$data    = json_decode( $entry['data'], true ) + [
			'_hutk' => [
				'label' => 'HubSpot',
				'value' => 'abc',
			],
			'hutk'  => 'abc',
		];
		$wpdb->update( $entries, [ 'data' => wp_json_encode( $data ) ], [ 'id' => $entry['id'] ] );
		update_option( 'suite_barrio_forms_hubspot', [ 'token' => 'segreto' ] );
		update_option( 'suite_barrio_forms_columns_' . self::ID, [ 'nome' ] );
		update_option( 'suite_barrio_forms_recaptcha', [ 'threshold' => '0.7' ] + get_option( 'suite_barrio_forms_recaptcha' ) );
		$never = static fn( string $where, string $key ): string => "{$where}: Suite Barrio never had \"{$key}\" (a version changed for this site?), it was not migrated.";

		$dry    = barrio_migration_run( true );
		$report = barrio_migration_run( false );

		$this->assertIsArray( $dry );
		$this->assertIsArray( $report );
		$this->assertSame(
			[
				$never( 'Form "Contatti"', 'hubspot_enabled' ),
				$never( 'Form "Contatti"', 'hubspot_portal_id' ),
				$never( 'Form "Contatti", field "Nome"', 'width' ),
				$never( 'Form "Contatti", notification "Admin"', 'bcc_email' ),
				$never( 'Form "Contatti", entries', '_hutk' ),
				$never( 'Form "Contatti", entries', 'hutk' ),
				$never( 'Forms', 'suite_barrio_forms_hubspot' ),
				$never( 'Forms, suite_barrio_forms_recaptcha', 'threshold' ),
			],
			array_values( preg_grep( '/Suite Barrio never had/', $report['warnings'] ) )
		);
		$this->assertSame( $dry['warnings'], $report['warnings'] );
		$this->assertStringNotContainsString( 'segreto', implode( "\n", $report['warnings'] ) );
	}

	// Una colonna in più è già una modifica, anche vuota. Le tabelle dei test sono temporanee: SHOW COLUMNS le vede.
	public function test_columns_suite_barrio_never_had_give_a_warning_each(): void {
		global $wpdb;
		$table    = "{$wpdb->prefix}wpb_prova";
		$warnings = [];
		$wpdb->query( "CREATE TABLE {$table} (id BIGINT UNSIGNED NOT NULL, title VARCHAR(255) NOT NULL DEFAULT '', colore VARCHAR(10) NULL)" );

		barrio_migration_unknown_columns( 'Popups', $table, [ 'id', 'title' ], $warnings );

		$this->assertSame( [ "Popups: Suite Barrio never had \"{$table}.colore\" (a version changed for this site?), it was not migrated." ], $warnings );
	}

	public function test_keys_become_valid_and_unique(): void {
		$used = [ 'nome' => true ];

		$this->assertSame(
			[ 'citta_e_cap', 'citta_e_cap_2', 'field_2_nome', 'field', 'nome_2' ],
			[ barrio_migration_key( 'Città e CAP', $used ), barrio_migration_key( 'citta-e-cap', $used ), barrio_migration_key( '2 nome', $used ), barrio_migration_key( '', $used ), barrio_migration_key( 'Nome', $used ) ]
		);
	}

	/**
	 * @param array<string, int> $params
	 * @return array<string, mixed>
	 */
	private function post( array $params, bool $dry_run = true ): array {
		$request = new WP_REST_Request( 'POST', '/barrio/v1/migration/forms' );
		$request->set_body_params( [ 'dry_run' => $dry_run ] + $params );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	/** @return array<string, list<string>> */
	private function values( Entry $entry ): array {
		$values = [];
		foreach ( FormRepository::version( $entry->version_id )->fields ?? [] as $field ) {
			if ( isset( $entry->values[ (int) $field->id ] ) ) {
				$values[ $field->key ] = $entry->values[ (int) $field->id ];
			}
		}
		return $values;
	}

	// I dati come li scriveva il prototipo: form, traduzione inglese, una registrazione dal sito e una da GraphQL.
	private function prototype(): void {
		global $wpdb;
		$fields = [
			[
				'name'        => 'nome',
				'type'        => 'text',
				'label'       => 'Nome',
				'placeholder' => 'Il tuo nome',
				'required'    => true,
			],
			[
				'name'  => 'email',
				'type'  => 'email',
				'label' => 'Email',
			],
			[
				'name'    => 'servizio',
				'type'    => 'radio',
				'label'   => 'Servizio',
				'options' => [ 'Consulenza', 'Preventivo <strong>gratuito</strong>' ],
			],
			[
				'name'    => 'interessi',
				'type'    => 'checkbox',
				'label'   => 'Interessi',
				'options' => [ 'News', 'Eventi' ],
			],
			[
				'name'             => 'data',
				'type'             => 'datepicker',
				'label'            => 'Data',
				'format'           => 'dd/MM/y',
				'conditionalLogic' => [
					'enabled'   => true,
					'action'    => 'show',
					'logicType' => 'all',
					'rules'     => [
						[
							'fieldName' => 'servizio',
							'operator'  => '=',
							'value'     => 'Consulenza',
						],
					],
				],
			],
			[ 'type' => 'page_break' ],
			[
				'name'  => 'cv',
				'type'  => 'file',
				'label' => 'CV',
			],
			[
				'name'     => 'privacy',
				'type'     => 'checkbox',
				'label'    => 'Privacy',
				'required' => true,
				'options'  => [ 'Accetto la <a href="/privacy/">privacy</a>' ],
			],
			// Solo un prototipo modificato sul server ha multiple.
			[
				'name'     => 'sedi',
				'type'     => 'select',
				'label'    => 'Sedi',
				'multiple' => true,
				'options'  => [ 'Torino', 'Milano' ],
			],
		];
		$settings = [
			'submit_text'           => 'Invia',
			'success_message'       => 'Grazie!',
			'after_submit'          => 'message',
			'display_page_info'     => true,
			'email_header_color'    => '#123456',
			'email_bg_color'        => '#ffffff',
			'notifications'         => [
				[
					'notification_name' => 'Admin',
					'to_email'          => 'info@example.com',
					'from_email'        => 'noreply@example.com',
					'from_name'         => 'Sito',
					'replyto_email'     => '{{email}}',
					'subject'           => 'Nuovo contatto da {{nome}}',
					'message_mode'      => 'auto',
				],
			],
			'mailchimp_enabled'     => true,
			'mailchimp_list_id'     => 'list-it',
			'mailchimp_email_field' => 'email',
			'mailchimp_optin_field' => 'interessi',
			'mailchimp_field_map'   => [
				[
					'form_field'           => 'nome',
					'mailchimp_field_name' => 'FNAME',
				],
			],
			'mailup_enabled'        => true,
			'mailup_group_id'       => '3',
			'mailup_optin_field'    => 'interessi',
		];
		$wpdb->insert(
			"{$wpdb->prefix}wpb_forms",
			[
				'id'       => self::ID,
				'title'    => 'Contatti',
				'fields'   => wp_json_encode( $fields ),
				'settings' => wp_json_encode( $settings ),
			]
		);
		$wpdb->insert(
			"{$wpdb->prefix}wpb_forms_translations",
			[
				'form_id'       => self::ID,
				'lang'          => 'en',
				'fields_i18n'   => wp_json_encode(
					[
						[
							'label'       => 'Name',
							'placeholder' => '',
						],
						[ 'label' => 'Email' ],
						[
							'label'   => 'Service',
							'options' => [ 'Consulting', 'Free quote' ],
						],
						[
							'label'   => 'Interests',
							'options' => [ 'News', 'Events' ],
						],
						[ 'label' => 'Date' ],
						[],
						[ 'label' => 'CV' ],
						[ 'options' => [ 'I accept the <a href="/en/privacy/">privacy policy</a>' ] ],
						[
							'label'   => 'Offices',
							'options' => [ 'Turin', 'Milan' ],
						],
					]
				),
				'settings_i18n' => wp_json_encode(
					[
						'submit_text'       => 'Send',
						'success_message'   => 'Thank you!',
						'mailchimp_list_id' => 'list-en',
						'notifications'     => [ [ 'subject' => 'New contact from {{nome}}' ] ],
					]
				),
			]
		);

		$uploads    = wp_upload_dir();
		$this->file = "{$uploads['path']}/cv.pdf";
		file_put_contents( $this->file, "%PDF-1.4\n%test\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$submissions = [
			// Dal sito (AJAX): checkbox come array, data Y-m-d, il file negli upload.
			[
				'it',
				'2026-01-02 03:04:05',
				[
					'nome'      => [
						'label' => 'Nome',
						'value' => 'Ada',
					],
					'email'     => [
						'label' => 'Email',
						'value' => 'ada@example.com',
					],
					'servizio'  => [
						'label' => 'Servizio',
						'value' => 'Consulenza',
					],
					'interessi' => [
						'label' => 'Interessi',
						'value' => [ 'News', 'Eventi' ],
					],
					'data'      => [
						'label' => 'Data',
						'value' => '2026-05-04',
					],
					'privacy'   => [
						'label' => 'Privacy',
						'value' => [ 'Accetto la privacy' ],
					],
					'sedi'      => [
						'label' => 'Sedi',
						'value' => [ 'Milano' ],
					],
					'_file_cv'  => [
						'label'   => 'CV',
						'value'   => 'cv.pdf',
						'url'     => "{$uploads['url']}/cv.pdf",
						'is_file' => true,
					],
					'_page_url' => [
						'label' => 'URL pagina',
						'value' => 'https://example.org/contatti/?utm_source=x',
					],
					'_lang'     => [
						'label' => 'Lingua',
						'value' => 'IT',
					],
				],
			],
			// Da GraphQL, in inglese, con un campo che il form non ha più.
			[
				'en',
				'2026-02-03 04:05:06',
				[
					'nome'          => [
						'label' => 'Name',
						'value' => 'Bob',
					],
					'servizio'      => [
						'label' => 'Service',
						'value' => 'Free quote',
					],
					'interessi'     => [
						'label' => 'Interests',
						'value' => 'News: false | Events: true',
					],
					'data'          => [
						'label' => 'Date',
						'value' => '04/05/2026',
					],
					'privacy'       => [
						'label' => 'Privacy',
						'value' => 'I accept the privacy policy: true',
					],
					// Il frontend di quel prototipo univa i valori con |.
					'sedi'          => [
						'label' => 'Offices',
						'value' => 'Turin|Milan',
					],
					'vecchio_campo' => [
						'label' => 'Old field',
						'value' => 'Un valore',
					],
				],
			],
		];
		foreach ( $submissions as [ $lang, $created, $data ] ) {
			$wpdb->insert(
				"{$wpdb->prefix}wpb_form_submissions",
				[
					'form_id'    => self::ID,
					'data'       => wp_json_encode( $data ),
					'lang'       => $lang,
					'ip'         => '203.0.113.7',
					'user_agent' => 'Mozilla/5.0',
					'created_at' => $created,
				]
			);
		}

		update_option(
			'suite_barrio_forms_mailjet',
			[
				'api_key'    => 'mj-public',
				'secret_key' => 'mj-secret',
			]
		);
		update_option( 'suite_barrio_forms_mailchimp', [ 'api_key' => 'abc123-us21' ] );
		update_option(
			'suite_barrio_forms_recaptcha',
			[
				'project_id' => 'sito-vecchio',
				'site_key'   => 'site-key-123',
				'api_key'    => 'AIza-vecchia',
			]
		);
		update_option(
			'suite_barrio_forms_retention',
			[
				'enabled' => true,
				'years'   => 2,
			]
		);
	}
}
