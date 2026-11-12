<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormStatus;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Model\Notification;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use InvalidArgumentException;
use RuntimeException;
use WP_Error;
use WP_UnitTestCase;

final class FormRepositoryTest extends WP_UnitTestCase {

	public function test_round_trip(): void {
		$i18n          = [
			'it' => [
				'submit_label'    => 'Invia',
				'success_message' => 'Grazie!',
			],
			'en' => [
				'submit_label' => 'Send',
				'success_url'  => '/en/thanks/',
			],
		];
		$notifications = [
			new Notification(
				'Admin',
				'info@example.com, {{email}}',
				[
					'it' => [
						'subject' => 'Nuova richiesta',
						'heading' => 'Dal sito',
						'body'    => '{{all_fields}}',
					],
				],
				reply_to: '{{email}}',
				attach_files: true,
			),
			new Notification(
				'Torino',
				'torino@example.com',
				[
					'it' => [
						'subject' => 'Richiesta da Torino',
						'body'    => '{{name}}',
					],
				],
				enabled: false,
				conditions: [
					'logic' => 'all',
					'rules' => [
						[
							'field' => 'province',
							'op'    => 'is',
							'value' => 'to',
						],
					],
				],
			),
		];
		$fields        = [
			new Field(
				'email',
				'email',
				[
					'it' => [
						'label'         => 'Email',
						'placeholder'   => 'tu@esempio.it',
						'error_message' => 'Serve un indirizzo valido',
					],
					'en' => [
						'label'       => 'E-mail',
						'placeholder' => 'you@example.com',
					],
				],
				true
			),
			new Field(
				'province',
				'select',
				[
					'it' => [
						'label'   => 'Provincia',
						'options' => [
							'to' => 'Torino',
							'cn' => 'Cuneo',
						],
					],
				],
				config: [ 'choices' => [ 'to', 'cn' ] ],
				conditions: [
					'logic' => 'any',
					'rules' => [],
				],
			),
			new Field( 'privacy', 'consent', [ 'it' => [ 'label' => 'Accetto la <a href="/privacy">privacy</a>' ] ], true ),
		];
		$created       = FormRepository::create( 'Contatti è', $i18n, $notifications, new FormVersion( $fields ) );
		wp_cache_flush();

		$form = FormRepository::get( $created->id );

		$this->assertInstanceOf( Form::class, $form );
		$this->assertSame( [ 'Contatti è', FormStatus::Active, 1 ], [ $form->title, $form->status, $form->current->version ] );
		$this->assertSame( self::describe( $i18n ), self::describe( $form->i18n ) );
		$this->assertSame( self::describe( $notifications ), self::describe( $form->notifications ) );
		$this->assertSame( self::describe( $fields ), self::describe( $form->current->fields ) );
		// L'ordine delle scelte viene dalla lista in config: è l'unico che il database conserva.
		$this->assertSame( [ 'to', 'cn' ], $form->current->fields[1]->config['choices'] );
		$this->assertNotContains( null, array_column( $form->current->fields, 'id' ) );
		$this->assertNotContains( null, array_column( $form->notifications, 'id' ) );
	}

	public function test_texts_and_notifications_are_replaced_on_save(): void {
		global $wpdb;
		$form = FormRepository::create(
			'A',
			[
				'it' => [ 'submit_label' => 'Invia' ],
				'en' => [ 'submit_label' => 'Send' ],
			],
			[ self::notification( 'First' ), self::notification( 'Second' ) ],
			self::fields( 'name' )
		);

		$saved = FormRepository::save( $form->id, 1, 'A', [ 'it' => [ 'submit_label' => 'Manda' ] ], [ self::notification( 'Third' ) ], self::fields( 'name' ) );

		$this->assertInstanceOf( Form::class, $saved );
		$this->assertSame( [ 'it' ], array_keys( $saved->i18n ) );
		$this->assertSame( 'Manda', $saved->i18n['it']['submit_label'] );
		$this->assertSame( [ 'Third' ], array_column( $saved->notifications, 'name' ) );
		$this->assertSame(
			'1',
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i i JOIN %i n ON n.id = i.notification_id WHERE n.form_id = %d',
					Db::table( 'form_notifications_i18n' ),
					Db::table( 'form_notifications' ),
					$form->id
				)
			)
		);
	}

	public function test_every_save_is_a_new_version_and_unused_ones_are_dropped(): void {
		$v1 = FormRepository::create( 'A', [], [], self::fields( 'name' ) );

		$v2 = FormRepository::save( $v1->id, 1, 'B', [], [], self::fields( 'name', 'email' ) );

		$this->assertInstanceOf( Form::class, $v2 );
		$this->assertSame( [ 'B', 2, [ 'name', 'email' ] ], [ $v2->title, $v2->current->version, array_column( $v2->current->fields, 'key' ) ] );
		$this->assertNotSame( $v1->current->fields[0]->id, $v2->current->fields[0]->id );
		$this->assertNull( FormRepository::version( (int) $v1->current->id ), 'Version 1 had no entries and should be gone.' );
	}

	public function test_versions_with_entries_are_kept(): void {
		$v1 = FormRepository::create( 'A', [], [], self::fields( 'name' ) );
		EntryRepository::create( new Entry( $v1->id, (int) $v1->current->id, 'it', [ (int) $v1->current->fields[0]->id => [ 'Ada' ] ] ) );

		$v2 = FormRepository::save( $v1->id, 1, 'A', [], [], self::fields( 'name' ) );
		$this->assertInstanceOf( Form::class, $v2 );
		$v3 = FormRepository::save( $v1->id, 2, 'A', [], [], self::fields( 'name' ) );
		$this->assertInstanceOf( Form::class, $v3 );

		$this->assertNotNull( FormRepository::version( (int) $v1->current->id ) );
		$this->assertNull( FormRepository::version( (int) $v2->current->id ) );
		$this->assertSame( 3, $v3->current->version );
	}

	public function test_saving_from_an_outdated_version_is_a_conflict(): void {
		$form = FormRepository::create( 'A', [], [], self::fields( 'name' ) );
		FormRepository::save( $form->id, 1, 'B', [], [], self::fields( 'name' ) );

		$result = FormRepository::save( $form->id, 1, 'C', [], [], self::fields( 'email' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame(
			[
				'status'          => 409,
				'current_version' => 2,
			],
			$result->get_error_data()
		);
		$this->assertSame( 'B', FormRepository::get( $form->id )?->title );
	}

	public function test_saving_a_missing_form(): void {
		$result = FormRepository::save( 999999, 1, 'A', [], [], self::fields( 'name' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( [ 'status' => 404 ], $result->get_error_data() );
	}

	public function test_failed_save_writes_nothing(): void {
		global $wpdb;
		$form   = FormRepository::create( 'A', [ 'it' => [ 'submit_label' => 'Invia' ] ], [ self::notification( 'Admin' ) ], self::fields( 'name' ) );
		$tables = [ 'form_i18n', 'form_notifications', 'form_versions', 'form_fields' ];
		$count  = static fn( string $table ): string => (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Db::table( $table ) ) );
		$rows   = array_map( $count, $tables );
		$wpdb->suppress_errors();

		try {
			// Il secondo campo viola la UNIQUE (version_id, field_key) quando testi, notifiche, versione
			// e primo campo sono già scritti.
			FormRepository::save( $form->id, 1, 'B', [], [], self::fields( 'email', 'email' ) );
			$this->fail( 'A duplicate field key should fail.' );
		} catch ( RuntimeException $e ) {
			$this->assertStringContainsString( 'version_key', $e->getMessage() );
		}

		$this->assertSame( $rows, array_map( $count, $tables ) );
		$this->assertSame( 'A', $wpdb->get_var( $wpdb->prepare( 'SELECT title FROM %i WHERE id = %d', Db::table( 'forms' ), $form->id ) ) );
	}

	// Il database non può imporre che un campo o una notifica abbiano almeno una riga di testi: lo fa il Repository.
	public function test_fields_and_notifications_without_texts_are_rejected(): void {
		global $wpdb;

		foreach ( [
			'field'        => [ [], new FormVersion( [ new Field( 'name', 'text', [] ) ] ) ],
			'notification' => [ [ new Notification( 'Admin', 'a@example.com', [] ) ], self::fields( 'name' ) ],
		] as $what => [ $notifications, $version ] ) {
			try {
				FormRepository::create( 'A', [], $notifications, $version );
				$this->fail( "A {$what} without texts should be rejected." );
			} catch ( InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'has no texts', $e->getMessage() );
			}
		}
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Db::table( 'forms' ) ) ) );
	}

	public function test_a_form_with_entries_cannot_be_deleted(): void {
		$form  = FormRepository::create( 'A', [ 'it' => [ 'submit_label' => 'Invia' ] ], [ self::notification( 'Admin' ) ], self::fields( 'name' ) );
		$entry = EntryRepository::create( new Entry( $form->id, (int) $form->current->id, 'it', [] ) );

		$error = FormRepository::delete( $form->id );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( [ 'status' => 409 ], $error->get_error_data() );

		EntryRepository::delete( $entry );
		$this->assertNull( FormRepository::delete( $form->id ) );
		$this->assertNull( FormRepository::get( $form->id ) );
	}

	public function test_get_is_cached_until_the_next_save(): void {
		global $wpdb;
		$form = FormRepository::create( 'A', [], [], self::fields( 'name' ) );
		FormRepository::get( $form->id );
		$queries = $wpdb->num_queries;

		FormRepository::get( $form->id );
		$this->assertSame( $queries, $wpdb->num_queries );

		FormRepository::save( $form->id, 1, 'B', [], [], self::fields( 'name' ) );
		$this->assertSame( 2, FormRepository::get( $form->id )?->current->version );
	}

	private static function fields( string ...$keys ): FormVersion {
		return new FormVersion( array_map( static fn( string $key ): Field => new Field( $key, 'text', [ 'it' => [ 'label' => ucfirst( $key ) ] ] ), $keys ) );
	}

	private static function notification( string $name ): Notification {
		return new Notification(
			$name,
			'admin@example.com',
			[
				'it' => [
					'subject' => $name,
					'body'    => '{{all_fields}}',
				],
			]
		);
	}

	/**
	 * Confrontabile tra ciò che si salva e ciò che si rilegge: senza gli id, che assegna il database,
	 * con i testi opzionali assenti come null e le chiavi ordinate, perché MySQL riordina quelle degli oggetti JSON.
	 */
	private static function describe( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
			unset( $value['id'] );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( isset( $value['label'] ) ) {
			$value += array_fill_keys( [ 'placeholder', 'error_message', 'options' ], null );
		} elseif ( isset( $value['subject'] ) ) {
			$value += [ 'heading' => null ];
		} elseif ( isset( $value['submit_label'] ) ) {
			$value += array_fill_keys( [ 'success_message', 'success_url' ], null );
		}
		ksort( $value );
		return array_map( [ self::class, 'describe' ], $value );
	}
}
