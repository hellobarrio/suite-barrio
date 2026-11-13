<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Admin;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Le rotte del builder, chiamate in JSON come fa il builder.
final class RestFormsTest extends WP_UnitTestCase {

	// Con tutte le chiavi facoltative: la lettura le restituisce sempre, anche se null.
	private const FORM = [
		'title'         => 'Contatti',
		'i18n'          => [
			'en' => [
				'submit_label'    => 'Send',
				'success_message' => null,
				'success_url'     => '/thanks/',
			],
			'it' => [
				'submit_label'    => 'Invia',
				'success_message' => 'Grazie!',
				'success_url'     => null,
			],
		],
		'notifications' => [
			[
				'name'         => 'Admin',
				'recipients'   => '{{admin_email}}',
				'reply_to'     => '{{email}}',
				'enabled'      => true,
				'attach_files' => false,
				'conditions'   => [
					'action' => 'show',
					'logic'  => 'all',
					'rules'  => [
						[
							'field'    => 'topic',
							'operator' => '=',
							'value'    => 'quote',
						],
					],
				],
				'i18n'         => [
					'en' => [
						'subject' => 'New request',
						'heading' => null,
						'body'    => '{{all_fields}}',
					],
				],
			],
		],
		'fields'        => [
			[
				'key'        => 'email',
				'type'       => 'email',
				'required'   => true,
				'config'     => [],
				'conditions' => null,
				'i18n'       => [
					'en' => [
						'label'         => 'Email',
						'placeholder'   => 'you@example.com',
						'error_message' => null,
						'options'       => null,
					],
				],
			],
			[
				'key'        => 'topic',
				'type'       => 'radio',
				'required'   => false,
				'config'     => [ 'choices' => [ 'info', 'quote' ] ],
				'conditions' => [
					'action' => 'hide',
					'logic'  => 'any',
					'rules'  => [
						[
							'field'    => 'email',
							'operator' => 'empty',
						],
					],
				],
				'i18n'       => [
					'en' => [
						'label'         => 'Topic',
						'placeholder'   => null,
						'error_message' => null,
						'options'       => [
							'info'  => 'Information',
							'quote' => 'A quote',
						],
					],
					'it' => [
						'label'         => 'Argomento',
						'placeholder'   => null,
						'error_message' => null,
						'options'       => [
							'info'  => 'Informazioni',
							'quote' => 'Un preventivo',
						],
					],
				],
			],
		],
	];

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_a_form_is_read_in_the_format_it_is_written(): void {
		$created = $this->request( 'POST', '/forms', self::FORM );
		$this->assertSame( 201, $created->get_status() );
		$id = $created->get_data()['id'];

		$read = $this->request( 'GET', "/forms/{$id}", query: [ 'context' => 'edit' ] )->get_data();
		$this->assertEquals( self::FORM, $this->json( array_intersect_key( $read, self::FORM ) ) );
		$this->assertSame( [ 'active', 1 ], [ $read['status'], $read['version'] ] );
		// Il blocco e l'elenco dei form.
		$this->assertSame( [ [ $id, 'Contatti', 'active' ] ], array_map( static fn( array $f ): array => [ $f['id'], $f['title'], $f['status'] ], $this->request( 'GET', '/forms' )->get_data() ) );
	}

	public function test_a_concurrent_save_is_a_conflict(): void {
		$id    = $this->request( 'POST', '/forms', self::FORM )->get_data()['id'];
		$first = $this->request( 'PUT', "/forms/{$id}", [ 'title' => 'Prima' ] + self::FORM + [ 'base_version' => 1 ] );
		$this->assertSame( [ 200, 2, 'Prima' ], [ $first->get_status(), $first->get_data()['version'], $first->get_data()['title'] ] );

		// Un altro builder era partito anche lui dalla versione 1.
		$second = $this->request( 'PUT', "/forms/{$id}", [ 'title' => 'Seconda' ] + self::FORM + [ 'base_version' => 1 ] );
		$this->assertSame( [ 409, 'barrio_version_conflict' ], [ $second->get_status(), $second->get_data()['code'] ] );
		$this->assertSame( 'Prima', $this->request( 'GET', "/forms/{$id}", query: [ 'context' => 'edit' ] )->get_data()['title'] );
	}

	public function test_invalid_forms_are_rejected_without_writing(): void {
		$field = static fn( array $changes ): array => [ 'fields' => [ array_replace( self::FORM['fields'][1], $changes ) ] ] + self::FORM;
		foreach ( [
			'no title'           => [ 'title' => '' ] + self::FORM,
			'no texts'           => [ 'i18n' => [] ] + self::FORM,
			'language code'      => [ 'i18n' => [ 'EN' => self::FORM['i18n']['en'] ] ] + self::FORM,
			'success url'        => [ 'i18n' => [ 'en' => [ 'success_url' => 'javascript:alert(1)' ] + self::FORM['i18n']['en'] ] ] + self::FORM,
			'too long'           => [ 'title' => str_repeat( 'a', 192 ) ] + self::FORM,
			'field key'          => $field( [ 'key' => 'Topic' ] ),
			'field type'         => $field( [ 'type' => 'signature' ] ),
			'unknown property'   => $field( [ 'id' => 5 ] ),
			'field config'       => $field( [ 'config' => [ 'choices' => [] ] ] ),
			'field conditions'   => $field( [ 'conditions' => [ 'action' => 'show' ] ] ),
			'duplicate key'      => [ 'fields' => [ self::FORM['fields'][1], self::FORM['fields'][1] ] ] + self::FORM,
			'no recipients'      => [ 'notifications' => [ [ 'recipients' => '' ] + self::FORM['notifications'][0] ] ] + self::FORM,
			'fields missing'     => array_diff_key( self::FORM, [ 'fields' => true ] ),
			'notification texts' => [ 'notifications' => [ [ 'i18n' => [ 'en' => [ 'subject' => 'Hi' ] ] ] + self::FORM['notifications'][0] ] ] + self::FORM,
		] as $what => $form ) {
			$this->assertSame( 400, $this->request( 'POST', '/forms', $form )->get_status(), $what );
		}
		$this->assertSame( '0', Db::var( 'SELECT COUNT(*) FROM ' . Db::table( 'forms' ) ) );
	}

	public function test_status_duplicate_and_delete(): void {
		$id = $this->request( 'POST', '/forms', self::FORM )->get_data()['id'];

		$this->assertSame( 200, $this->request( 'PATCH', "/forms/{$id}", [ 'status' => 'closed' ] )->get_status() );
		$this->assertSame( 'closed', $this->request( 'GET', "/forms/{$id}" )->get_data()['status'] );
		$this->assertSame( 400, $this->request( 'PATCH', "/forms/{$id}", [ 'status' => 'deleted' ] )->get_status() );
		$this->assertSame( 404, $this->request( 'PATCH', '/forms/999999', [ 'status' => 'trash' ] )->get_status() );

		$copy = $this->request( 'POST', "/forms/{$id}/duplicate" );
		$this->assertSame( [ 201, 'Contatti (copy)', 'active', 1 ], [ $copy->get_status(), $copy->get_data()['title'], $copy->get_data()['status'], $copy->get_data()['version'] ] );
		$this->assertEquals( $this->json( self::FORM['fields'] ), $this->json( $copy->get_data()['fields'] ) );

		EntryRepository::create( new Entry( $id, (int) FormRepository::get( $id )?->current->id, 'en', [] ) );
		$deleted = $this->request( 'DELETE', "/forms/{$id}" );
		$this->assertSame( [ 409, 'barrio_form_has_entries' ], [ $deleted->get_status(), $deleted->get_data()['code'] ] );
		// L'elenco conta le registrazioni: l'admin propone il cestino invece dell'eliminazione.
		$entries = array_column( $this->request( 'GET', '/forms' )->get_data(), 'entries', 'id' );
		$this->assertSame( [ 0, 1 ], [ $entries[ $copy->get_data()['id'] ], $entries[ $id ] ] );

		$this->assertSame( 200, $this->request( 'DELETE', "/forms/{$copy->get_data()['id']}" )->get_status() );
		$this->assertSame( [ $id ], array_column( $this->request( 'GET', '/forms' )->get_data(), 'id' ) );
	}

	public function test_the_list_has_the_languages_of_the_current_texts(): void {
		$german                            = self::FORM;
		$german['fields'][0]['i18n']['de'] = [ 'label' => 'E-Mail' ];
		$id                                = $this->request( 'POST', '/forms', $german )->get_data()['id'];
		$languages                         = fn(): array => array_column( $this->request( 'GET', '/forms' )->get_data(), 'languages', 'id' )[ $id ];
		$this->assertEqualsCanonicalizing( [ 'en', 'it', 'de' ], $languages() );

		// Senza il tedesco: la versione 1 resta per la sua registrazione, ma non conta.
		EntryRepository::create( new Entry( $id, (int) FormRepository::get( $id )?->current->id, 'de', [] ) );
		$this->assertSame( 200, $this->request( 'PUT', "/forms/{$id}", [ 'base_version' => 1 ] + self::FORM )->get_status() );
		$this->assertEqualsCanonicalizing( [ 'en', 'it' ], $languages() );
	}

	public function test_the_preview_renders_the_unsaved_form(): void {
		$html = $this->request( 'POST', '/forms/preview', self::FORM )->get_data()['html'];

		$this->assertStringStartsWith( '<!doctype html>', $html );
		$this->assertStringContainsString( 'frontend/form.css', $html );
		$this->assertStringContainsString( 'data-barrio-preview', $html );
		$this->assertStringContainsString( '>Information</label>', $html );
		// Nella lingua scelta nella scheda Traduzioni, se è attiva.
		add_filter( 'barrio_forms_languages', static fn(): array => [ 'en', 'it' ] );
		$this->assertStringContainsString( '>Un preventivo</label>', $this->request( 'POST', '/forms/preview', self::FORM + [ 'lang' => 'it' ] )->get_data()['html'] );
		$this->assertStringContainsString( '>Information</label>', $this->request( 'POST', '/forms/preview', self::FORM + [ 'lang' => 'fr' ] )->get_data()['html'] );
		$this->assertSame( 400, $this->request( 'POST', '/forms/preview', [ 'fields' => [ [ 'config' => [] ] + self::FORM['fields'][1] ] ] + self::FORM )->get_status() );
		$this->assertSame( [], $this->request( 'GET', '/forms' )->get_data() );
	}

	public function test_the_preview_of_a_notification_uses_sample_values(): void {
		$email = $this->request( 'POST', '/forms/preview', self::FORM + [ 'notification' => 0 ] )->get_data();

		// I destinatari passano dai merge tag come nell'invio vero: {{email}} ha un valore d'esempio.
		$this->assertSame( [ [ get_option( 'admin_email' ) ], 'name@example.com', 'New request' ], [ $email['to'], $email['reply_to'], $email['subject'] ] );
		// {{all_fields}} con l'etichetta della prima scelta, nel template delle email.
		$this->assertStringContainsString( 'Information', $email['html'] );
		$this->assertStringContainsString( '<!DOCTYPE html>', $email['html'] );
		$this->assertSame( 404, $this->request( 'POST', '/forms/preview', self::FORM + [ 'notification' => 3 ] )->get_status() );
	}

	public function test_only_form_managers_use_the_builder(): void {
		$id = $this->request( 'POST', '/forms', self::FORM )->get_data()['id'];
		// Gli editor gestiscono le registrazioni (fase 5) e inseriscono il blocco, ma non modificano i form.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 200, $this->request( 'GET', '/forms' )->get_status() );
		$this->assertSame( 200, $this->request( 'GET', "/forms/{$id}" )->get_status() );
		foreach ( [
			[ 'GET', "/forms/{$id}", [], [ 'context' => 'edit' ] ],
			[ 'POST', '/forms', self::FORM, [] ],
			[ 'PUT', "/forms/{$id}", self::FORM + [ 'base_version' => 1 ], [] ],
			[ 'PATCH', "/forms/{$id}", [ 'status' => 'trash' ], [] ],
			[ 'DELETE', "/forms/{$id}", [], [] ],
			[ 'POST', "/forms/{$id}/duplicate", [], [] ],
			[ 'POST', '/forms/preview', self::FORM, [] ],
		] as [ $method, $route, $body, $query ] ) {
			$this->assertSame( 403, $this->request( $method, $route, $body, $query )->get_status(), "{$method} {$route}" );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/forms' )->get_status() );
	}

	public function test_presets_have_unique_values(): void {
		foreach ( Admin::presets() as $id => $preset ) {
			$values = array_column( $preset['choices'], 0 );
			$this->assertNotEmpty( $values, $id );
			$this->assertSame( array_unique( $values ), $values, $id );
			$this->assertSame( $values, array_filter( $values, 'strlen' ), $id );
		}
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

	// Come lo riceve il builder: gli oggetti vuoti diventano array vuoti.
	private function json( mixed $data ): mixed {
		return json_decode( (string) wp_json_encode( $data ), true );
	}
}
