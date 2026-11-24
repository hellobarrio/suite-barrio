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
		$this->assertSame( [ 'nome', 'email', 'servizio', 'interessi', 'data', 'page_break', 'cv', 'privacy' ], array_column( $form->current->fields, 'key' ) );
		// La checkbox della privacy con il link è un consenso, con il link in ogni lingua.
		$privacy = $form->current->fields[7];
		$this->assertSame( [ 'consent', true, [] ], [ $privacy->type, $privacy->required, $privacy->config ] );
		$this->assertSame( 'Accetto la <a href="/privacy/">privacy</a>', $privacy->i18n['it']['label'] );
		$this->assertSame( 'I accept the <a href="/en/privacy/">privacy policy</a>', $privacy->i18n['en']['label'] );
		$this->assertSame( 2, $form->current->version );

		$service = $form->current->fields[2];
		$this->assertSame( [ 'choices' => [ 'Consulenza', 'Preventivo gratuito' ] ], $service->config );
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

	// Il form di Barrio Suite prenderebbe il posto di quello del prototipo nelle pagine e nei frontend.
	public function test_an_id_taken_by_another_barrio_suite_form_is_an_error(): void {
		FormRepository::create( 'Altro', [ 'it' => [ 'submit_label' => 'Invia' ] ], [], new FormVersion( [] ), [], self::ID );

		$report = barrio_migration_run( false );

		$this->assertIsArray( $report );
		$this->assertSame( 'error: another Barrio Suite form has this ID', $report['forms'][0]['result'] );
		$this->assertSame( 'Altro', FormRepository::get( self::ID )?->title );
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

	public function test_keys_become_valid_and_unique(): void {
		$used = [ 'nome' => true ];

		$this->assertSame(
			[ 'citta_e_cap', 'citta_e_cap_2', 'field_2_nome', 'field', 'nome_2' ],
			[ barrio_migration_key( 'Città e CAP', $used ), barrio_migration_key( 'citta-e-cap', $used ), barrio_migration_key( '2 nome', $used ), barrio_migration_key( '', $used ), barrio_migration_key( 'Nome', $used ) ]
		);
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
		$fields   = [
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
