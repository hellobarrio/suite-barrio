<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Forms\Submission\FileStore;
use BarrioSuite\Forms\Submission\Submission;
use InvalidArgumentException;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// La pipeline di invio attraverso la REST API pubblica, come la usano il form e i frontend headless.
final class SubmissionTest extends WP_UnitTestCase {

	private const VALID = [
		'name'    => 'Ada',
		'email'   => 'ada@example.com',
		'request' => 'info',
		'privacy' => '1',
	];

	private Form $form;

	public function set_up(): void {
		parent::set_up();
		$this->form = FormRepository::create(
			'Contact',
			[
				'it' => [
					'submit_label'    => 'Invia',
					'success_message' => 'Grazie!',
				],
				'en' => [
					'submit_label'    => 'Send',
					'success_message' => 'Thanks!',
				],
			],
			[],
			new FormVersion( self::fields() )
		);
	}

	public function tear_down(): void {
		global $wpdb;
		// I file sul disco non tornano indietro con la transazione del test.
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT entry_id FROM %i', Db::table( 'entry_files' ) ) ) as $entry ) {
			EntryRepository::delete( (int) $entry );
		}
		parent::tear_down();
	}

	public function test_a_valid_submission_is_saved_with_its_context(): void {
		$response = $this->post(
			[
				'name'        => "  Ada \n  Lovelace ",
				'topics'      => [ 'news', 'events', 'news' ],
				// Valore fisso nella config: quello inviato non conta.
				'source'      => 'tampered',
				'_barrio_url' => 'https://example.org/contatti/?email=ada@example.com#form',
			] + self::VALID
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame(
			[
				'message'  => 'Thanks!',
				'redirect' => null,
			],
			$response->get_data()
		);
		$entry = $this->last_entry();
		$this->assertSame( [ 'en', (string) $this->form->current->id, 'https://example.org/contatti/' ], [ $entry['lang'], $entry['version_id'], $entry['source_url'] ] );
		$this->assertSame(
			[
				'name'    => [ 'Ada Lovelace' ],
				'email'   => [ 'ada@example.com' ],
				'request' => [ 'info' ],
				'topics'  => [ 'news', 'events' ],
				'privacy' => [ '1' ],
				'source'  => [ 'landing' ],
			],
			$this->saved( (int) $entry['id'] )
		);
	}

	public function test_a_field_hidden_by_conditions_is_neither_required_nor_saved(): void {
		// budget è obbligatorio e 10 è sotto il minimo, ma con request = info è nascosto.
		$response = $this->post( [ 'budget' => '10' ] + self::VALID );

		$this->assertSame( 201, $response->get_status() );
		$this->assertArrayNotHasKey( 'budget', $this->saved( (int) $this->last_entry()['id'] ) );
	}

	public function test_a_field_shown_by_conditions_is_validated(): void {
		$quote = [ 'request' => 'quote' ] + self::VALID;

		$this->assertSame( [ 'budget' => 'This field is required.' ], $this->errors( $this->post( $quote ) ) );
		$this->assertSame( [ 'budget' => 'Please enter a number greater than or equal to 500.' ], $this->errors( $this->post( [ 'budget' => '100' ] + $quote ) ) );
		$this->assertSame( 201, $this->post( [ 'budget' => '1500' ] + $quote )->get_status() );
		$this->assertSame( [ '1500' ], $this->saved( (int) $this->last_entry()['id'] )['budget'] );
	}

	public function test_all_errors_come_back_together_and_nothing_is_saved(): void {
		$response = $this->post(
			[
				'request' => 'other',
				'topics'  => [ 'news', 'spam' ],
				'privacy' => 'no',
			]
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'barrio_invalid', $response->get_data()['code'] );
		$this->assertSame(
			[
				'name'    => 'This field is required.',
				// Il messaggio personalizzato del campo sostituisce quello standard.
				'email'   => 'We need your email.',
				'request' => 'Please choose one of the available options.',
				'topics'  => 'Please choose one of the available options.',
				'privacy' => 'This field is required.',
			],
			$this->errors( $response )
		);
		$this->assertNull( $this->last_entry() );
	}

	public function test_spam_is_rejected(): void {
		$honeypot = $this->post( [ '_barrio_hp' => 'https://spam.example' ] + self::VALID );
		$no_js    = $this->post( [ '_barrio_js' => '' ] + self::VALID );

		$this->assertSame( [ 400, 'barrio_spam' ], [ $honeypot->get_status(), $honeypot->get_data()['code'] ] );
		$this->assertSame( [ 400, 'barrio_spam' ], [ $no_js->get_status(), $no_js->get_data()['code'] ] );
		$this->assertNull( $this->last_entry() );
	}

	public function test_submissions_are_limited_per_ip(): void {
		$remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
		try {
			$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
			for ( $i = 0; $i < 10; $i++ ) {
				$this->assertSame( 201, $this->post( self::VALID )->get_status() );
			}
			$this->assertSame( 429, $this->post( self::VALID )->get_status() );

			$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
			$this->assertSame( 201, $this->post( self::VALID )->get_status() );
		} finally {
			$_SERVER['REMOTE_ADDR'] = $remote_addr;
		}
	}

	public function test_closed_trashed_and_missing_forms(): void {
		$this->set_status( 'closed' );
		$closed = $this->post( self::VALID );
		$this->set_status( 'trash' );

		$this->assertSame( [ 403, 'barrio_form_closed' ], [ $closed->get_status(), $closed->get_data()['code'] ] );
		$this->assertSame( 404, $this->post( self::VALID )->get_status() );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'POST', '/barrio/v1/forms/999999/entries' ) )->get_status() );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', "/barrio/v1/forms/{$this->form->id}" ) )->get_status() );
	}

	public function test_an_accepted_file_is_stored_privately_with_a_random_name(): void {
		global $wpdb;
		$upload   = self::upload( 'Il mio CV.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" );
		$response = $this->post( self::VALID, [ 'cv' => $upload ] );

		$this->assertSame( 201, $response->get_status() );
		$file = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE entry_id = %d', Db::table( 'entry_files' ), $this->last_entry()['id'] ), ARRAY_A );
		$this->assertSame( [ 'Il-mio-CV.pdf', 'application/pdf', (string) $upload['size'] ], [ $file['original_name'], $file['mime_type'], $file['size_bytes'] ] );
		$this->assertMatchesRegularExpression( '#^\d{4}/\d{2}/[0-9a-f]{32}$#', $file['stored_path'] );
		$this->assertFileExists( FileStore::dir() . '/' . $file['stored_path'] );
		$this->assertFileDoesNotExist( $upload['tmp_name'] );
		foreach ( [ '.htaccess', 'web.config', 'index.php' ] as $protection ) {
			$this->assertFileExists( FileStore::dir() . "/{$protection}" );
		}
		// Il nome del file non è un valore del campo: sta solo in entry_files.
		$this->assertArrayNotHasKey( 'cv', $this->saved( (int) $this->last_entry()['id'] ) );
	}

	public function test_files_that_are_not_allowed_are_rejected(): void {
		$files = self::stored_files();
		foreach ( [
			'php'                 => [ self::upload( 'shell.php', '<?php echo 1;' ), 'This file type is not allowed. Accepted formats: PDF, TXT.' ],
			'allowed by WP only'  => [ self::upload( 'photo.jpg', "\xFF\xD8\xFF\xE0" . str_repeat( 'x', 100 ) ), 'This file type is not allowed. Accepted formats: PDF, TXT.' ],
			'executable as .pdf'  => [ self::upload( 'cv.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00\xB8" . str_repeat( "\x00", 100 ) . 'PE' . "\x00\x00" ), 'This file type is not allowed. Accepted formats: PDF, TXT.' ],
			'over the field size' => [ self::upload( 'big.txt', str_repeat( 'x', 2000 ) ), 'The file is too large. Maximum size: 1 KB.' ],
			'over the PHP limit'  => [ [ 'error' => UPLOAD_ERR_INI_SIZE ] + self::upload( 'big.txt', 'x' ), 'The file is too large. Maximum size: 1 KB.' ],
			'empty'               => [ self::upload( 'empty.txt', '' ), 'The file is empty.' ],
		] as $what => [ $upload, $message ] ) {
			$this->assertSame( [ 'cv' => $message ], $this->errors( $this->post( self::VALID, [ 'cv' => $upload ] ) ), $what );
		}
		$this->assertNull( $this->last_entry() );
		$this->assertSame( $files, self::stored_files() );
	}

	public function test_stored_files_are_removed_when_the_entry_cannot_be_saved(): void {
		$files = self::stored_files();
		add_filter( 'barrio_forms_entry_data', static fn( Entry $e ): Entry => new Entry( $e->form_id, 999999, $e->lang, $e->values, $e->files ) );

		try {
			Submission::process( $this->form, [ '_barrio_js' => '1' ] + self::VALID, [ 'cv' => self::upload( 'cv.txt', 'curriculum' ) ] );
			$this->fail( 'The entry should not be saved.' );
		} catch ( InvalidArgumentException ) {
			$this->assertSame( $files, self::stored_files() );
		}
	}

	public function test_the_entry_proves_the_version_the_visitor_saw(): void {
		$seen = (int) $this->form->current->id;
		// Una registrazione tiene in vita la versione 1 dopo il salvataggio.
		$this->post( self::VALID );
		$fields = [ ...self::fields(), new Field( 'phone', 'tel', [ 'en' => [ 'label' => 'Phone' ] ], true ) ];
		$form   = FormRepository::save( $this->form->id, 1, 'Contact', $this->form->i18n, [], new FormVersion( $fields ) );
		$this->assertInstanceOf( Form::class, $form );
		$other = FormRepository::create( 'Other', [], [], new FormVersion( self::fields() ) );

		// Pagina in cache con la versione 1: il nuovo campo obbligatorio non c'era.
		$this->assertSame( 201, $this->post( [ '_barrio_version' => $seen ] + self::VALID )->get_status() );
		$this->assertSame( (string) $seen, $this->last_entry()['version_id'] );
		// Versione corrente, inesistente o di un altro form: vale la corrente.
		foreach ( [ $form->current->id, 999999, $other->current->id ] as $version ) {
			$this->assertSame( [ 'phone' => 'This field is required.' ], $this->errors( $this->post( [ '_barrio_version' => $version ] + self::VALID ) ), (string) $version );
		}
	}

	public function test_the_language_is_the_submitted_one_when_active(): void {
		add_filter( 'wpml_default_language', static fn(): string => 'it' );
		add_filter(
			'wpml_active_languages',
			static fn(): array => [
				'it' => [],
				'en' => [],
			]
		);

		$this->assertSame( 'Thanks!', $this->post( [ '_barrio_lang' => 'en' ] + self::VALID )->get_data()['message'] );
		$this->assertSame( 'en', $this->last_entry()['lang'] );
		$this->assertSame( 'Grazie!', $this->post( [ '_barrio_lang' => 'fr' ] + self::VALID )->get_data()['message'] );
		$this->assertSame( 'it', $this->last_entry()['lang'] );
	}

	public function test_listeners_get_the_saved_entry_and_their_errors_become_notes(): void {
		global $wpdb;
		$received = null;
		add_action(
			'barrio_forms_after_submission',
			static function ( Entry $entry, Form $form ) use ( &$received ): void {
				$received = [ $entry->id, $form->id ];
				throw new RuntimeException( 'Mailchimp is down.' );
			},
			10,
			2
		);

		$this->assertSame( 201, $this->post( self::VALID )->get_status() );
		$entry = (int) $this->last_entry()['id'];
		$this->assertSame( [ $entry, $this->form->id ], $received );
		$this->assertSame(
			[ 'system', 'error', 'Mailchimp is down.' ],
			array_values( (array) $wpdb->get_row( $wpdb->prepare( 'SELECT source, level, message FROM %i WHERE entry_id = %d', Db::table( 'entry_notes' ), $entry ), ARRAY_A ) )
		);
	}

	public function test_get_returns_the_public_schema(): void {
		$data = rest_do_request( new WP_REST_Request( 'GET', "/barrio/v1/forms/{$this->form->id}" ) )->get_data();

		$this->assertSame( [ $this->form->id, 'active', 'en', $this->form->current->id, 'Send' ], [ $data['id'], $data['status'], $data['lang'], $data['version_id'], $data['submit_label'] ] );
		$this->assertSame( [ 'name', 'email', 'request', 'budget', 'topics', 'cv', 'next', 'privacy', 'source' ], array_column( $data['fields'], 'key' ) );
		$this->assertSame(
			[
				[
					'value' => 'info',
					'label' => 'Information',
				],
				[
					'value' => 'quote',
					'label' => 'quote',
				],
			],
			$data['fields'][2]['options']
		);
		$this->assertSame( 'request', $data['fields'][3]['conditions']['rules'][0]['field'] );
		$this->assertSame( 'I agree to the <a href="/privacy">policy</a>alert(1)', $data['fields'][7]['label'] );
	}

	/** @return list<Field> */
	private static function fields(): array {
		return [
			new Field( 'name', 'text', [ 'en' => [ 'label' => 'Name' ] ], true ),
			new Field(
				'email',
				'email',
				[
					'en' => [
						'label'         => 'Email',
						'error_message' => 'We need your email.',
					],
				],
				true
			),
			new Field(
				'request',
				'radio',
				[
					'en' => [
						'label'   => 'Request',
						'options' => [ 'info' => 'Information' ],
					],
				],
				true,
				[ 'choices' => [ 'info', 'quote' ] ]
			),
			new Field(
				'budget',
				'number',
				[ 'en' => [ 'label' => 'Budget' ] ],
				true,
				[ 'min' => 500 ],
				[
					'action' => 'show',
					'logic'  => 'all',
					'rules'  => [
						[
							'field'    => 'request',
							'operator' => '=',
							'value'    => 'quote',
						],
					],
				]
			),
			new Field( 'topics', 'checkbox', [ 'en' => [ 'label' => 'Topics' ] ], false, [ 'choices' => [ 'news', 'events' ] ] ),
			new Field(
				'cv',
				'file',
				[ 'en' => [ 'label' => 'CV' ] ],
				false,
				[
					'extensions'  => [ 'pdf', 'txt' ],
					'max_size_mb' => 0.001,
				]
			),
			// Obbligatorio per errore: un page_break non ha valori e non deve bloccare l'invio.
			new Field( 'next', 'page_break', [ 'en' => [ 'label' => 'Next' ] ], true ),
			new Field( 'privacy', 'consent', [ 'en' => [ 'label' => 'I agree to the <a href="/privacy">policy</a><script>alert(1)</script>' ] ], true ),
			new Field( 'source', 'hidden', [ 'en' => [ 'label' => 'Source' ] ], false, [ 'value' => 'landing' ] ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $files
	 */
	private function post( array $input, array $files = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', "/barrio/v1/forms/{$this->form->id}/entries" );
		$request->set_body_params( $input + [ '_barrio_js' => '1' ] );
		$request->set_file_params( $files );
		return rest_do_request( $request );
	}

	/** @return array<string, string> */
	private function errors( WP_REST_Response $response ): array {
		$this->assertSame( 422, $response->get_status() );
		return $response->get_data()['data']['fields'];
	}

	/** @return array<string, string|null>|null */
	private function last_entry(): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', Db::table( 'entries' ) ), ARRAY_A );
	}

	/** @return array<string, list<string>> */
	private function saved( int $entry ): array {
		global $wpdb;
		$values = [];
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT f.field_key, v.value FROM %i v JOIN %i f ON f.id = v.field_id WHERE v.entry_id = %d ORDER BY f.position, v.position',
				Db::table( 'entry_values' ),
				Db::table( 'form_fields' ),
				$entry
			)
		);
		foreach ( $rows as $row ) {
			$values[ $row->field_key ][] = $row->value;
		}
		return $values;
	}

	private function set_status( string $status ): void {
		global $wpdb;
		$wpdb->update( Db::table( 'forms' ), [ 'status' => $status ], [ 'id' => $this->form->id ] );
		wp_cache_delete( $this->form->id, 'barrio_forms' );
	}

	/** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
	private static function upload( string $name, string $content ): array {
		$tmp = wp_tempnam( $name );
		file_put_contents( $tmp, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return [
			'name'     => $name,
			'type'     => 'application/octet-stream',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $content ),
		];
	}

	/** @return list<string> */
	private static function stored_files(): array {
		$dir   = FileStore::dir();
		$files = is_dir( $dir ) ? array_keys( iterator_to_array( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) ) ) : [];
		sort( $files );
		return $files;
	}
}
