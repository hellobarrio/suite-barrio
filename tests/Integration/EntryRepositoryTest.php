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
use InvalidArgumentException;
use RuntimeException;
use WP_UnitTestCase;

final class EntryRepositoryTest extends WP_UnitTestCase {

	private Form $form;

	/** @var array<string, int> */
	private array $field = [];

	public function set_up(): void {
		parent::set_up();
		$this->form = FormRepository::create(
			'Contact',
			[],
			[],
			new FormVersion(
				[
					new Field( 'email', 'email', [ 'it' => [ 'label' => 'Email' ] ] ),
					new Field( 'topics', 'checkbox', [ 'it' => [ 'label' => 'Argomenti' ] ], config: [ 'choices' => [ 'news', 'eventi' ] ] ),
					new Field( 'cv', 'file', [ 'it' => [ 'label' => 'CV' ] ], config: [ 'extensions' => [ 'pdf' ] ] ),
				]
			)
		);
		foreach ( $this->form->current->fields as $field ) {
			$this->field[ $field->key ] = (int) $field->id;
		}
	}

	public function test_stores_one_row_per_value_and_the_context(): void {
		global $wpdb;
		$id = EntryRepository::create(
			$this->entry(
				[
					$this->field['email']  => [ 'ada@example.com' ],
					$this->field['topics'] => [ 'news', 'eventi' ],
				],
				lang: 'en',
				source_url: 'https://example.com/contatti/?gclid=abc&email=ada@example.com#form',
			)
		);

		$this->assertSame(
			[ [ 'email', '0', 'ada@example.com' ], [ 'topics', '0', 'news' ], [ 'topics', '1', 'eventi' ] ],
			$wpdb->get_results(
				$wpdb->prepare(
					'SELECT f.field_key, v.position, v.value FROM %i v JOIN %i f ON f.id = v.field_id WHERE v.entry_id = %d ORDER BY f.position, v.position',
					Db::table( 'entry_values' ),
					Db::table( 'form_fields' ),
					$id
				),
				ARRAY_N
			)
		);
		$entry = $wpdb->get_row( $wpdb->prepare( 'SELECT lang, is_read, source_url, created_at FROM %i WHERE id = %d', Db::table( 'entries' ), $id ), ARRAY_A );
		$this->assertSame( [ 'en', '0', 'https://example.com/contatti/' ], array_slice( array_values( (array) $entry ), 0, 3 ) );
		$this->assertEqualsWithDelta( time(), strtotime( $entry['created_at'] . ' UTC' ), 5 );
	}

	// Privacy by default: la query string può contenere dati personali e di tracciamento.
	public function test_source_url_loses_query_string_and_fragment_and_fits_the_column(): void {
		global $wpdb;
		$url = fn( ?string $source_url ): ?string => $wpdb->get_var(
			$wpdb->prepare( 'SELECT source_url FROM %i WHERE id = %d', Db::table( 'entries' ), EntryRepository::create( $this->entry( [], source_url: $source_url ) ) )
		);

		$this->assertSame( 'https://example.com/a', $url( 'https://example.com/a#b?c' ) );
		$this->assertNull( $url( '?utm_source=newsletter' ) );
		$this->assertNull( $url( null ) );
		$this->assertSame( 2048, mb_strlen( (string) $url( 'https://example.com/' . str_repeat( 'è', 3000 ) . '?x=1' ) ) );
	}

	public function test_rejects_fields_of_another_version(): void {
		global $wpdb;
		$old_email = $this->field['email'];
		$form      = FormRepository::save( $this->form->id, 1, 'Contact', [], [], new FormVersion( [ new Field( 'email', 'email', [ 'it' => [ 'label' => 'Email' ] ] ) ] ) );
		$this->assertInstanceOf( Form::class, $form );

		try {
			EntryRepository::create( new Entry( $form->id, (int) $form->current->id, 'it', [ $old_email => [ 'ada@example.com' ] ] ) );
			$this->fail( 'A field of another version should be rejected.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( (string) $old_email, $e->getMessage() );
		}
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Db::table( 'entries' ) ) ) );
	}

	public function test_failed_create_writes_nothing(): void {
		global $wpdb;
		EntryRepository::create( $this->entry( [], files: [ $this->file( 'same-name' ) ] ) );
		$wpdb->suppress_errors();

		try {
			// Registrazione e valori sono già scritti quando il file viola la UNIQUE su stored_path.
			EntryRepository::create( $this->entry( [ $this->field['email'] => [ 'b@example.com' ] ], files: [ $this->file( 'same-name' ) ] ) );
			$this->fail( 'A duplicate stored_path should fail.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'stored_path', $e->getMessage() );
		}

		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Db::table( 'entries' ) ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Db::table( 'entry_values' ) ) ) );
	}

	public function test_delete_removes_the_files_from_disk_and_then_the_rows(): void {
		global $wpdb;
		$name = 'test-' . wp_generate_password( 12, false );
		wp_mkdir_p( FileStore::dir() );
		file_put_contents( FileStore::dir() . "/{$name}", 'pdf' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$id = EntryRepository::create( $this->entry( [ $this->field['email'] => [ 'ada@example.com' ] ], files: [ $this->file( $name ) ] ) );

		EntryRepository::delete( $id );

		$this->assertFileDoesNotExist( FileStore::dir() . "/{$name}" );
		foreach ( [
			'entries'      => 'id',
			'entry_values' => 'entry_id',
			'entry_files'  => 'entry_id',
		] as $table => $column ) {
			$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE %i = %d', Db::table( $table ), $column, $id ) ), $table );
		}
	}

	public function test_deleting_a_user_forgets_them(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$form = FormRepository::save( $this->form->id, 1, 'Contact', [], [], new FormVersion( [ new Field( 'email', 'email', [ 'it' => [ 'label' => 'Email' ] ] ) ] ) );
		$this->assertInstanceOf( Form::class, $form );
		$this->assertSame( $user, $form->current->created_by );
		$entry = EntryRepository::create( new Entry( $form->id, (int) $form->current->id, 'it', [], user_id: $user ) );

		wp_delete_user( $user );

		$this->assertNull( FormRepository::version( (int) $form->current->id )?->created_by );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM %i WHERE id = %d', Db::table( 'entries' ), $entry ) ) );
	}

	/**
	 * @param array<int, list<string>>                                                                          $values
	 * @param list<array{field_id: int, stored_path: string, original_name: string, mime_type: string, size_bytes: int}> $files
	 */
	private function entry( array $values, array $files = [], string $lang = 'it', ?string $source_url = null ): Entry {
		return new Entry( $this->form->id, (int) $this->form->current->id, $lang, $values, $files, source_url: $source_url );
	}

	/** @return array{field_id: int, stored_path: string, original_name: string, mime_type: string, size_bytes: int} */
	private function file( string $stored_path ): array {
		return [
			'field_id'      => $this->field['cv'],
			'stored_path'   => $stored_path,
			'original_name' => 'cv.pdf',
			'mime_type'     => 'application/pdf',
			'size_bytes'    => 3,
		];
	}
}
