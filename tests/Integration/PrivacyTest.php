<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Privacy;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Forms\Submission\FileStore;
use BarrioSuite\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Esportazione e cancellazione dagli strumenti Privacy di WordPress, chiamate come le chiama WordPress, e retention.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook di WordPress.
final class PrivacyTest extends WP_UnitTestCase {

	private Form $form;

	/** @var array<string, int> */
	private array $field = [];

	public function set_up(): void {
		parent::set_up();
		$this->form = FormRepository::create(
			'Contatti',
			[],
			[],
			new FormVersion(
				[
					new Field(
						'email',
						'email',
						[
							'it' => [ 'label' => 'Email' ],
							'en' => [ 'label' => 'Email address' ],
						]
					),
					new Field( 'note', 'text', [ 'it' => [ 'label' => 'Note' ] ] ),
					new Field(
						'topic',
						'radio',
						[
							'it' => [
								'label'   => 'Argomento',
								'options' => [ 'info' => 'Informazioni' ],
							],
							'en' => [
								'label'   => 'Topic',
								'options' => [ 'info' => 'Information' ],
							],
						],
						config: [ 'choices' => [ 'info' ] ]
					),
					new Field( 'cv', 'file', [ 'it' => [ 'label' => 'CV' ] ], config: [ 'extensions' => [ 'pdf' ] ] ),
				]
			)
		);
		foreach ( $this->form->current->fields as $field ) {
			$this->field[ $field->key ] = (int) $field->id;
		}
	}

	public function tear_down(): void {
		global $wpdb;
		// I file sul disco non tornano indietro con la transazione del test.
		remove_all_filters( 'wp_delete_file' );
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT entry_id FROM %i', Db::table( 'entry_files' ) ) ) as $entry ) {
			EntryRepository::delete( (int) $entry );
		}
		parent::tear_down();
	}

	public function test_the_export_has_the_entries_with_the_address_in_an_email_field(): void {
		$mine = $this->entry(
			[
				$this->field['email'] => [ 'Ada@Example.com' ],
				$this->field['topic'] => [ 'info' ],
			],
			lang: 'en',
			source_url: 'https://example.org/contact/',
			files: [ $this->file() ]
		);
		// L'indirizzo in un campo di testo non basta: la persona è quella del campo email.
		$this->entry(
			[
				$this->field['email'] => [ 'bob@example.com' ],
				$this->field['note']  => [ 'ada@example.com' ],
			]
		);

		$export = $this->export( 'ada@example.com', 1 );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 1, $export['data'] );
		$item = $export['data'][0];
		$this->assertSame( [ 'barrio-forms', "barrio-entry-{$mine}" ], [ $item['group_id'], $item['item_id'] ] );
		$details = array_column( $item['data'], 'value', 'name' );
		$this->assertNotEmpty( $details['Date'] );
		// Etichette e scelte nella lingua in cui la persona ha letto il form; del file il nome.
		$this->assertSame(
			[
				'Form'          => 'Contatti',
				'Page'          => 'https://example.org/contact/',
				'Email address' => 'Ada@Example.com',
				'Topic'         => 'Information',
				'CV'            => 'cv.pdf',
			],
			array_diff_key( $details, [ 'Date' => true ] )
		);
		$this->assertSame( [], $this->export( 'nobody@example.com', 1 )['data'] );
	}

	public function test_export_and_erasure_go_one_page_at_a_time(): void {
		$other = $this->entry( [ $this->field['email'] => [ 'bob@example.com' ] ] );
		for ( $i = 0; $i < 51; $i++ ) {
			$this->entry( [ $this->field['email'] => [ 'ada@example.com' ] ] );
		}

		$this->assertSame( [ 50, false ], [ count( $this->export( 'ada@example.com', 1 )['data'] ), $this->export( 'ada@example.com', 1 )['done'] ] );
		$this->assertSame( [ 1, true ], [ count( $this->export( 'ada@example.com', 2 )['data'] ), $this->export( 'ada@example.com', 2 )['done'] ] );

		$this->assertSame(
			[
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => [],
				'done'           => false,
			],
			$this->erase( 'ada@example.com', 1 )
		);
		$this->assertTrue( $this->erase( 'ada@example.com', 2 )['done'] );
		$this->assertSame( [ $other ], $this->ids() );
	}

	public function test_the_erasure_deletes_the_entries_and_their_files(): void {
		$file  = $this->file();
		$other = $this->entry( [ $this->field['email'] => [ 'bob@example.com' ] ] );
		$this->entry( [ $this->field['email'] => [ 'ada@example.com' ] ], files: [ $file ] );

		$erased = $this->erase( 'ADA@example.com', 1 );

		$this->assertSame( [ true, true ], [ $erased['items_removed'], $erased['done'] ] );
		$this->assertSame( [ $other ], $this->ids() );
		$this->assertFileDoesNotExist( FileStore::dir() . "/{$file['stored_path']}" );
		$this->assertFalse( $this->erase( 'ada@example.com', 1 )['items_removed'] );
	}

	// Un file che non si elimina: la registrazione resta, WordPress lo dice e non riprova all'infinito.
	public function test_an_entry_that_cannot_be_erased_is_reported(): void {
		$entry = $this->entry( [ $this->field['email'] => [ 'ada@example.com' ] ], files: [ $this->file() ] );
		add_filter( 'wp_delete_file', '__return_empty_string' );

		$erased = $this->erase( 'ada@example.com', 1 );

		$this->assertSame( [ false, true, true ], [ $erased['items_removed'], $erased['items_retained'], $erased['done'] ] );
		$this->assertStringContainsString( 'could not be erased', $erased['messages'][0] );
		$this->assertSame( [ $entry ], $this->ids() );
	}

	public function test_retention_deletes_the_entries_older_than_the_chosen_days(): void {
		$file = $this->file();
		$old  = $this->entry( [], files: [ $file ], created_at: gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) );
		$new  = $this->entry( [], created_at: gmdate( 'Y-m-d H:i:s', time() - 29 * DAY_IN_SECONDS ) );

		// Di default si conserva tutto.
		do_action( 'barrio_forms_retention' );
		$this->assertSame( [ $old, $new ], $this->ids() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 400, $this->settings( [ 'retention_days' => -1 ] )->get_status() );
		$this->assertSame( [ 'retention_days' => 30 ], $this->settings( [ 'retention_days' => 30 ] )->get_data()['privacy'] );
		do_action( 'barrio_forms_retention' );

		$this->assertSame( [ $new ], $this->ids() );
		$this->assertFileDoesNotExist( FileStore::dir() . "/{$file['stored_path']}" );
	}

	public function test_retention_runs_every_day_until_the_plugin_is_deactivated(): void {
		wp_clear_scheduled_hook( Privacy::CRON );
		Privacy::schedule();
		$this->assertSame( 'daily', wp_get_scheduled_event( Privacy::CRON )->schedule ?? null );

		Plugin::deactivate();
		$this->assertFalse( wp_next_scheduled( Privacy::CRON ) );
	}

	/**
	 * @param array<int, list<string>>                                                                          $values
	 * @param list<array{field_id: int, stored_path: string, original_name: string, mime_type: string, size_bytes: int}> $files
	 */
	private function entry( array $values, string $lang = 'it', ?string $source_url = null, array $files = [], ?string $created_at = null ): int {
		return (int) EntryRepository::create( new Entry( $this->form->id, (int) $this->form->current->id, $lang, $values, $files, source_url: $source_url, created_at: $created_at ) )->id;
	}

	/** @return array{field_id: int, stored_path: string, original_name: string, mime_type: string, size_bytes: int} Con il file vero nella cartella protetta. */
	private function file(): array {
		$name = 'test-' . wp_generate_password( 12, false );
		wp_mkdir_p( FileStore::dir() );
		file_put_contents( FileStore::dir() . "/{$name}", 'pdf' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return [
			'field_id'      => $this->field['cv'],
			'stored_path'   => $name,
			'original_name' => 'cv.pdf',
			'mime_type'     => 'application/pdf',
			'size_bytes'    => 3,
		];
	}

	/** @return array<string, mixed> */
	private function export( string $email, int $page ): array {
		return call_user_func( apply_filters( 'wp_privacy_personal_data_exporters', [] )['barrio-forms']['callback'], $email, $page );
	}

	/** @return array<string, mixed> */
	private function erase( string $email, int $page ): array {
		return call_user_func( apply_filters( 'wp_privacy_personal_data_erasers', [] )['barrio-forms']['callback'], $email, $page );
	}

	/** @param array<string, mixed> $privacy */
	private function settings( array $privacy ): WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', '/barrio/v1/settings' );
		$request->set_body_params( [ 'privacy' => $privacy ] );
		return rest_do_request( $request );
	}

	/** @return list<int> Le registrazioni rimaste. */
	private function ids(): array {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id', Db::table( 'entries' ) ) ) );
	}
}
