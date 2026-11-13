<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Api\RestEntries;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Model\Notification;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Forms\Submission\FileStore;
use SplFileObject;
use SplTempFileObject;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Le registrazioni dall'admin, come le usa un editor: elenco, dettaglio, azioni di massa, file ed export.
final class RestEntriesTest extends WP_UnitTestCase {

	private Form $form;

	public function set_up(): void {
		parent::set_up();
		$this->form = FormRepository::create(
			'Contatti & preventivi',
			[ 'en' => [ 'submit_label' => 'Send' ] ],
			[
				new Notification(
					'Admin',
					'{{admin_email}}',
					[
						'en' => [
							'subject' => 'New',
							'body'    => '{{all_fields}}',
						],
					]
				),
			],
			new FormVersion( self::fields() )
		);
		// Gli editor gestiscono le registrazioni ma non i form.
		wp_set_current_user(
			self::factory()->user->create(
				[
					'role'         => 'editor',
					'display_name' => 'Eva Editor',
				]
			)
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

	public function test_the_list_searches_sorts_filters_and_pages_on_the_server(): void {
		foreach ( range( 1, 25 ) as $n ) {
			$this->entry(
				[
					'name'  => "Persona {$n}",
					'topic' => 1 === $n % 2 ? 'quote' : 'info',
				],
				minutes_ago: 100 - $n,
				lang: $n > 20 ? 'it' : 'en'
			);
		}
		EntryRepository::set_read( $this->form->id, null, true );
		$unread = $this->entry(
			[
				'name'  => 'Zoe',
				'notes' => '100% sicuro',
			],
			minutes_ago: 1
		);

		$first = $this->list();
		$this->assertSame( [ 26, 20, $unread, false ], [ $first['total'], count( $first['rows'] ), $first['rows'][0]['id'], $first['rows'][0]['is_read'] ] );
		$this->assertSame( [ 'name', 'topic', 'notes', 'cv' ], array_column( $first['columns'], 'key' ) );
		// Le scelte con l'etichetta, non il valore salvato.
		$this->assertSame( 'A quote', $this->json( $first['rows'][1] )['values']['topic'] );
		$this->assertSame( [ 'Persona 6', 'Persona 5', 'Persona 4', 'Persona 3', 'Persona 2', 'Persona 1' ], $this->names( [ 'page' => 2 ] ) );
		$this->assertSame( $this->names( [ 'page' => 2 ] ), $this->names( [ 'page' => 9 ] ), 'Past the end, the last page.' );

		$this->assertSame( [ 'Persona 7' ], $this->names( [ 'search' => 'persona 7' ] ) );
		// I caratteri jolly di LIKE si cercano come testo.
		$this->assertSame( [ 'Zoe' ], $this->names( [ 'search' => '100%' ] ) );
		$this->assertSame( $unread, $this->list( [ 'search' => (string) $unread ] )['rows'][0]['id'] );
		$this->assertSame( [ 'Zoe' ], $this->names( [ 'read' => 'false' ] ) );
		$this->assertSame( [ 'Persona 25', 'Persona 24', 'Persona 23', 'Persona 22', 'Persona 21' ], $this->names( [ 'lang' => 'it' ] ) );
		// Ordinamento sul valore di un campo: è testo.
		$this->assertSame(
			[ 'Persona 1', 'Persona 10', 'Persona 11' ],
			array_slice(
				$this->names(
					[
						'sort'  => 'field:name',
						'order' => 'asc',
					]
				),
				0,
				3
			)
		);
		$this->assertSame( 'Zoe', $this->names( [ 'sort' => 'field:name' ] )[0] );
		$this->assertSame(
			'Persona 1',
			$this->names(
				[
					'sort'  => 'id',
					'order' => 'asc',
				]
			)[0]
		);
		$this->assertSame( 400, $this->request( 'GET', "/forms/{$this->form->id}/entries", query: [ 'sort' => 'value' ] )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/forms/999999/entries' )->get_status() );
	}

	public function test_fields_of_older_versions_keep_their_column(): void {
		$old  = $this->entry(
			[
				'name'  => 'Ada',
				'topic' => 'info',
			]
		);
		$form = FormRepository::save( $this->form->id, 1, 'Contatti', [ 'en' => [ 'submit_label' => 'Send' ] ], [], new FormVersion( [ self::fields()[0], new Field( 'phone', 'tel', [ 'en' => [ 'label' => 'Phone' ] ] ) ] ) );
		$this->assertInstanceOf( Form::class, $form );
		$this->form = $form;
		$this->entry(
			[
				'name'  => 'Bea',
				'phone' => '333',
			]
		);

		$list = $this->list(
			[
				'sort'  => 'field:name',
				'order' => 'asc',
			]
		);
		$this->assertSame( [ 'name', 'phone', 'topic', 'notes', 'cv' ], array_column( $list['columns'], 'key' ) );
		$this->assertSame( [ $old, 'Information' ], [ $list['rows'][0]['id'], $list['rows'][0]['values']->topic ] );
		// Lo stesso campo in due versioni ordina insieme.
		$this->assertSame(
			[ 'Ada', 'Bea' ],
			$this->names(
				[
					'sort'  => 'field:name',
					'order' => 'asc',
				]
			)
		);
	}

	public function test_bulk_actions_touch_only_the_entries_of_the_form(): void {
		$a     = $this->entry( [ 'name' => 'A' ] );
		$b     = $this->entry( [ 'name' => 'B' ] );
		$other = FormRepository::create( 'Other', [], [], new FormVersion( [ self::fields()[0] ] ) );
		$c     = (int) EntryRepository::create( new Entry( $other->id, (int) $other->current->id, 'en', [] ) )->id;
		$bulk  = fn( array $data ): WP_REST_Response => $this->request( 'POST', "/forms/{$this->form->id}/entries/bulk", $data );

		$this->assertSame(
			200,
			$bulk(
				[
					'action' => 'read',
					'ids'    => [ $a, $c ],
				]
			)->get_status()
		);
		$this->assertSame( [ true, false, false ], [ EntryRepository::get( $a )?->is_read, EntryRepository::get( $b )?->is_read, EntryRepository::get( $c )?->is_read ] );
		$bulk(
			[
				'action' => 'read',
				'all'    => true,
			]
		);
		$bulk(
			[
				'action' => 'unread',
				'ids'    => [ $a ],
			]
		);
		$this->assertSame( [ false, true ], [ EntryRepository::get( $a )?->is_read, EntryRepository::get( $b )?->is_read ] );

		$this->assertSame(
			[ 'deleted' => 1 ],
			$bulk(
				[
					'action' => 'delete',
					'ids'    => [ $a, $c ],
				]
			)->get_data()
		);
		$this->assertSame( [ null, $c ], [ EntryRepository::get( $a ), EntryRepository::get( $c )?->id ] );
		// Senza ids serve all: true.
		$this->assertSame( 400, $bulk( [ 'action' => 'delete' ] )->get_status() );
		$this->assertSame(
			400,
			$bulk(
				[
					'action' => 'delete',
					'ids'    => [],
				]
			)->get_status()
		);
		$this->assertSame(
			[ 'deleted' => 1 ],
			$bulk(
				[
					'action' => 'delete',
					'all'    => true,
				]
			)->get_data()
		);
		$this->assertSame( 0, $this->list()['total'] );
		$this->assertNotNull( EntryRepository::get( $c ) );
	}

	public function test_the_detail_has_every_field_of_the_compiled_version_and_the_history(): void {
		$id    = $this->entry(
			[
				'name'  => 'Ada',
				'topic' => 'quote',
			],
			files: [ $this->file( 'cv.pdf' ) ]
		);
		$entry = $this->request( 'GET', "/entries/{$id}" )->get_data();

		$this->assertSame(
			[
				[ 'name', 'Name', 'Ada', [] ],
				[ 'topic', 'Topic', 'A quote', [] ],
				[ 'notes', 'Notes', '', [] ],
				[
					'cv',
					'CV',
					'',
					[
						[
							'index' => 0,
							'name'  => 'cv.pdf',
							'size'  => '3 B',
						],
					],
				],
			],
			array_map( static fn( array $f ): array => [ $f['key'], $f['label'], $f['value'], $f['files'] ], $entry['fields'] )
		);
		$this->assertSame( [ false, 'en', 'https://example.org/contatti/', 1 ], [ $entry['is_read'], $entry['lang'], $entry['source_url'], $entry['form']['version'] ] );
		$this->assertSame( [ 'Admin' ], array_column( $entry['notifications'], 'name' ) );

		$notes = $this->request( 'POST', "/entries/{$id}/notes", [ 'message' => 'Richiamata.' ] );
		$this->assertSame( 201, $notes->get_status() );
		$this->assertSame( [ [ 'info', 'Richiamata.', 'Eva Editor' ] ], array_map( static fn( array $n ): array => [ $n['level'], $n['message'], $n['author'] ], $notes->get_data() ) );
		$this->assertCount( 1, $this->request( 'GET', "/entries/{$id}" )->get_data()['notes'] );
		$this->assertSame( 400, $this->request( 'POST', "/entries/{$id}/notes", [ 'message' => '' ] )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/entries/999999/notes', [ 'message' => 'x' ] )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/entries/999999' )->get_status() );
	}

	public function test_files_download_as_attachments_that_the_browser_does_not_sniff(): void {
		$id       = $this->entry( [], files: [ $this->file( 'Curriculum è "nuovo".html' ) ] );
		$response = $this->request( 'GET', "/entries/{$id}/files/0" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			[
				'Content-Type'           => 'text/html',
				'Content-Disposition'    => 'attachment; filename="Curriculum e _nuovo_.html"; filename*=UTF-8\'\'Curriculum%20%C3%A8%20%22nuovo%22.html',
				'X-Content-Type-Options' => 'nosniff',
				'Content-Length'         => '3',
			],
			$response->get_headers()
		);
		$this->assertSame( 'pdf', $this->served( $response ) );
		$this->assertSame( 404, $this->request( 'GET', "/entries/{$id}/files/1" )->get_status() );
	}

	public function test_the_csv_export_has_a_column_per_field_and_neutralizes_formulas(): void {
		global $wp_locale;
		// Tre registrazioni nello stesso secondo: i blocchi da 2 non devono saltarne o ripeterne.
		$this->entry(
			[
				'name'  => '=HYPERLINK("http://evil")',
				'notes' => "Riga 1\nRiga 2; \"citata\"",
			],
			minutes_ago: 5
		);
		$this->entry(
			[
				'name'  => '+39 333',
				'topic' => 'quote',
			],
			minutes_ago: 5
		);
		$this->entry( [ 'name' => 'Àda' ], files: [ $this->file( 'cv.pdf' ) ], minutes_ago: 5 );
		$this->entry( [ 'name' => 'Newest' ] );
		$csv = static function ( int $form_id, int $chunk ): string {
			$out = fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			RestEntries::csv(
				$out,
				$form_id,
				[
					'search' => '',
					'read'   => null,
					'lang'   => null,
				],
				$chunk
			);
			rewind( $out );
			return (string) stream_get_contents( $out );
		};

		$text = $csv( $this->form->id, 2 );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $text );
		$file = new SplTempFileObject();
		$file->fwrite( substr( $text, 3 ) );
		$file->rewind();
		$file->setFlags( SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD );
		$file->setCsvControl( ',', '"', '' );
		$rows = array_values( iterator_to_array( $file ) );
		$this->assertSame( [ 'ID', 'Date', 'Language', 'Page', 'Name', 'Topic', 'Notes', 'CV' ], $rows[0] );
		$this->assertSame( [ "'=HYPERLINK(\"http://evil\")", "'+39 333", 'Àda', 'Newest' ], array_column( array_slice( $rows, 1 ), 4 ) );
		$this->assertSame( [ '', 'A quote', '', '' ], array_column( array_slice( $rows, 1 ), 5 ) );
		$this->assertSame( [ 'https://example.org/contatti/', 'cv.pdf' ], [ $rows[3][3], $rows[3][7] ] );
		$this->assertStringContainsString( "\"Riga 1\nRiga 2; \"\"citata\"\"\"", $text );

		// Dove la virgola è il separatore decimale Excel si aspetta il punto e virgola.
		$wp_locale->number_format['decimal_point'] = ',';
		$this->assertStringStartsWith( "\xEF\xBB\xBFID;Date;Language;Page;Name;Topic;Notes;CV\n", $csv( $this->form->id, 500 ) );
		$wp_locale->number_format['decimal_point'] = '.';

		$response = $this->request( 'GET', "/forms/{$this->form->id}/entries/export", query: [ 'search' => 'Newest' ] );
		$this->assertSame( 'text/csv; charset=utf-8', $response->get_headers()['Content-Type'] );
		$this->assertStringStartsWith( 'attachment; filename="contatti-preventivi-' . wp_date( 'Y-m-d' ) . '.csv"', $response->get_headers()['Content-Disposition'] );
		$this->assertSame( 2, substr_count( $this->served( $response ), "\n" ), 'The header and one entry.' );
	}

	public function test_only_entry_managers_reach_the_entries(): void {
		$id = $this->entry( [ 'name' => 'Ada' ], files: [ $this->file( 'cv.pdf' ) ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		foreach ( [
			[ 'GET', "/forms/{$this->form->id}/entries", [] ],
			[ 'GET', "/forms/{$this->form->id}/entries/export", [] ],
			[
				'POST',
				"/forms/{$this->form->id}/entries/bulk",
				[
					'action' => 'delete',
					'all'    => true,
				],
			],
			[ 'GET', "/entries/{$id}", [] ],
			[ 'POST', "/entries/{$id}/notes", [ 'message' => 'x' ] ],
			[ 'GET', "/entries/{$id}/files/0", [] ],
		] as [ $method, $route, $body ] ) {
			$this->assertSame( 403, $this->request( $method, $route, $body )->get_status(), "{$method} {$route}" );
		}
		$this->assertNotNull( EntryRepository::get( $id ) );
	}

	/** @return list<Field> */
	private static function fields(): array {
		return [
			new Field( 'name', 'text', [ 'en' => [ 'label' => 'Name' ] ] ),
			new Field(
				'topic',
				'radio',
				[
					'en' => [
						'label'   => 'Topic',
						'options' => [
							'info'  => 'Information',
							'quote' => 'A quote',
						],
					],
				],
				config: [ 'choices' => [ 'info', 'quote' ] ]
			),
			new Field( 'notes', 'textarea', [ 'en' => [ 'label' => 'Notes' ] ] ),
			new Field( 'cv', 'file', [ 'en' => [ 'label' => '<strong>CV</strong>' ] ], config: [ 'extensions' => [ 'pdf' ] ] ),
		];
	}

	/**
	 * @param array<string, string> $values Per field_key, della versione corrente.
	 * @param list<array{field_id: int, stored_path: string, original_name: string, mime_type: string, size_bytes: int}> $files
	 */
	private function entry( array $values, array $files = [], int $minutes_ago = 0, string $lang = 'en' ): int {
		$ids = array_column( array_map( static fn( Field $f ): array => [ $f->key, $f->id ], $this->form->current->fields ), 1, 0 );
		return (int) EntryRepository::create(
			new Entry(
				$this->form->id,
				(int) $this->form->current->id,
				$lang,
				array_combine( array_map( static fn( string $key ): int => (int) $ids[ $key ], array_keys( $values ) ), array_map( static fn( string $v ): array => [ $v ], $values ) ),
				$files,
				source_url: 'https://example.org/contatti/',
				created_at: gmdate( 'Y-m-d H:i:s', time() - 60 * $minutes_ago ),
			)
		)->id;
	}

	/** @return array{field_id: int, stored_path: string, original_name: string, mime_type: string, size_bytes: int} */
	private function file( string $name ): array {
		$path = 'test-' . wp_generate_password( 12, false );
		wp_mkdir_p( FileStore::dir() );
		file_put_contents( FileStore::dir() . "/{$path}", 'pdf' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return [
			'field_id'      => (int) $this->form->current->fields[3]->id,
			'stored_path'   => $path,
			'original_name' => $name,
			'mime_type'     => str_ends_with( $name, '.html' ) ? 'text/html' : 'application/pdf',
			'size_bytes'    => 3,
		];
	}

	/**
	 * @param array<string, mixed> $query
	 * @return array{columns: list<array<string, string>>, rows: list<array<string, mixed>>, total: int}
	 */
	private function list( array $query = [] ): array {
		$response = $this->request( 'GET', "/forms/{$this->form->id}/entries", query: $query );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	/**
	 * @param array<string, mixed> $query
	 * @return list<string>
	 */
	private function names( array $query ): array {
		return array_map( static fn( array $row ): string => $row['values']->name ?? '', $this->list( $query )['rows'] );
	}

	// Il corpo che serve() invia al posto del JSON.
	private function served( WP_REST_Response $response ): string {
		ob_start();
		$this->assertTrue( RestEntries::serve( false, $response ) );
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $body
	 * @param array<string, mixed> $query
	 */
	private function request( string $method, string $route, array $body = [], array $query = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, "/barrio/v1{$route}" );
		$request->set_query_params( $query );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_do_request( $request );
	}

	// Come lo riceve il browser.
	private function json( mixed $data ): mixed {
		return json_decode( (string) wp_json_encode( $data ), true );
	}
}
