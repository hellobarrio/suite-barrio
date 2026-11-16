<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\FieldTypes;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Repository\FormRepository;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// L'API per le estensioni (docs/hooks.md): integrazioni con impostazioni globali e per form, tipi di
// campo, azione barrio_forms_render. Un'integrazione finta, "crm", registra le chiamate a process().
final class IntegrationsTest extends WP_UnitTestCase {

	/** @var list<array{entry: Entry, settings: array<string, mixed>, global: array<string, mixed>}> */
	private static array $calls = [];

	/** @var true|WP_Error|RuntimeException Cosa fa la prossima chiamata a process(). */
	private static true|WP_Error|RuntimeException $result = true;

	public function set_up(): void {
		parent::set_up();
		self::$calls  = [];
		self::$result = true;
		add_filter( 'barrio_forms_languages', static fn(): array => [ 'it', 'en' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		barrio_forms_register_integration(
			'crm',
			[
				'label'           => 'CRM',
				'global_settings' => [
					'token'    => [ 'format' => 'password' ],
					'endpoint' => [
						'type'   => 'string',
						'format' => 'uri',
					],
				],
				'form_settings'   => [
					'list'   => [
						'type'         => 'string',
						'translatable' => true,
					],
					'email'  => [ 'format' => 'field:email' ],
					'map'    => [ 'format' => 'field_map' ],
					'double' => [ 'type' => 'boolean' ],
				],
				'process'         => static function ( Entry $entry, Form $form, array $settings, array $account ): true|WP_Error {
					self::$calls[] = [
						'entry'    => $entry,
						'settings' => $settings,
						'global'   => $account,
					];
					if ( self::$result instanceof RuntimeException ) {
						throw self::$result;
					}
					return self::$result;
				},
			]
		);
		// Le rotte si registrano una volta per server REST: lo schema delle impostazioni deve includere "crm".
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- globale di WordPress.
	}

	public function test_integrations_are_saved_in_the_format_of_the_builder(): void {
		$created = $this->request( 'POST', '/forms', self::form() );
		$this->assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$id = $created->get_data()['id'];

		$read = $this->json( $this->request( 'GET', "/forms/{$id}", query: [ 'context' => 'edit' ] )->get_data() );
		$this->assertEquals( self::form()['integrations'], $read['integrations'] );

		// Il duplicato le copia, come le notifiche.
		$copy = $this->json( $this->request( 'POST', "/forms/{$id}/duplicate" )->get_data() );
		$this->assertEquals( self::form()['integrations'], $copy['integrations'] );

		// Un salvataggio le sostituisce; senza integrations non ce ne sono più.
		$saved = $this->request( 'PUT', "/forms/{$id}", [ 'base_version' => 1 ] + array_diff_key( self::form(), [ 'integrations' => 0 ] ) );
		$this->assertSame( [ 200, [] ], [ $saved->get_status(), $saved->get_data()['integrations'] ] );
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i i JOIN %i g ON g.id = i.integration_id WHERE g.form_id = %d', Db::table( 'form_integrations_i18n' ), Db::table( 'form_integrations' ), $id ) ) );
	}

	public function test_settings_that_do_not_match_the_schema_are_rejected(): void {
		$crm = self::form()['integrations'][0];
		foreach ( [
			'unknown setting'          => [ 'settings' => [ 'debug' => true ] + $crm['settings'] ] + $crm,
			'wrong type'               => [ 'settings' => [ 'double' => 'yes' ] ] + $crm,
			'field map with a string'  => [ 'settings' => [ 'map' => [ 'FNAME' ] ] ] + $crm,
			'translatable as fixed'    => [ 'settings' => [ 'list' => 'x' ] ] + $crm,
			'fixed in a translation'   => [ 'i18n' => [ 'en' => [ 'email' => 'email' ] ] ] + $crm,
			'conditions without rules' => [ 'conditions' => [ 'action' => 'show' ] ] + $crm,
			'slug with uppercase'      => [ 'slug' => 'CRM' ] + $crm,
		] as $what => $integration ) {
			$response = $this->request( 'POST', '/forms', [ 'integrations' => [ $integration ] ] + self::form() );
			$this->assertSame( 400, $response->get_status(), $what );
		}
		$this->assertSame( 400, $this->request( 'POST', '/forms', [ 'integrations' => [ $crm, $crm ] ] + self::form() )->get_status(), 'twice' );
	}

	public function test_the_settings_of_an_inactive_extension_are_kept(): void {
		$inactive = [
			'slug'       => 'mailup',
			'enabled'    => true,
			'settings'   => [ 'whatever' => [ 1, 2 ] ],
			'conditions' => null,
			'i18n'       => [ 'it' => [ 'list' => '7' ] ],
		];
		$form     = $this->create( [ $inactive ] );

		$this->assertEquals( [ $inactive ], $this->json( $this->request( 'GET', "/forms/{$form->id}", query: [ 'context' => 'edit' ] )->get_data() )['integrations'] );
		// Dopo l'invio non succede niente: non c'è chi le usi.
		$this->submit( $form );
		$this->assertSame( [], self::$calls );
	}

	public function test_process_gets_the_settings_in_the_language_of_the_entry(): void {
		$form = $this->create();
		$this->request(
			'PUT',
			'/settings',
			[
				'integrations' => [
					'crm' => [
						'token'    => 's3cret',
						'endpoint' => 'https://crm.example.com',
					],
				],
			]
		);

		$this->submit( $form, 'en' );
		$this->submit( $form, 'it' );

		$this->assertCount( 2, self::$calls );
		$this->assertSame(
			[
				'list'  => 'Newsletter EN',
				'email' => 'email',
				'map'   => [ [ 'FNAME', 'name' ] ],
			],
			self::$calls[0]['settings']
		);
		$this->assertSame( 'Newsletter IT', self::$calls[1]['settings']['list'] );
		$this->assertSame(
			[
				'token'    => 's3cret',
				'endpoint' => 'https://crm.example.com',
			],
			self::$calls[0]['global']
		);
		$this->assertSame( [ [ 'crm', 'success', 'Sent to CRM.' ] ], $this->notes( (int) self::$calls[1]['entry']->id ) );
	}

	public function test_conditions_and_the_active_switch_decide_whether_process_runs(): void {
		$integration = self::form()['integrations'][0];
		$form        = $this->create(
			[
				[
					'conditions' => [
						'action' => 'show',
						'logic'  => 'all',
						'rules'  => [
							[
								'field'    => 'newsletter',
								'operator' => '=',
								'value'    => '1',
							],
						],
					],
				] + $integration,
			]
		);

		$this->submit( $form );
		$this->assertSame( [], self::$calls, 'Without the consent the rule is false.' );
		$this->assertSame( [], $this->notes( $this->last_entry() ), 'A skipped integration leaves no note.' );
		$this->submit( $form, 'it', [ 'newsletter' => '1' ] );
		$this->assertCount( 1, self::$calls );

		$disabled = $this->create( [ [ 'enabled' => false ] + $integration ] );
		$this->submit( $disabled );
		$this->assertCount( 1, self::$calls );
	}

	public function test_a_failure_is_noted_and_does_not_stop_the_submission(): void {
		$form = $this->create();

		self::$result = new WP_Error( 'crm_down', 'the CRM did not answer.' );
		$this->assertSame( 201, $this->submit( $form )->get_status() );
		$this->assertSame( [ [ 'crm', 'error', 'Could not send to CRM: the CRM did not answer.' ] ], $this->notes( $this->last_entry() ) );

		self::$result = new RuntimeException( 'Unexpected answer.' );
		$this->assertSame( 201, $this->submit( $form )->get_status() );
		$this->assertSame( [ [ 'crm', 'error', 'Could not send to CRM: Unexpected answer.' ] ], $this->notes( $this->last_entry() ) );
	}

	public function test_global_secrets_are_encrypted_and_never_come_back(): void {
		$put = $this->request(
			'PUT',
			'/settings',
			[
				'integrations' => [
					'crm' => [
						'token'    => 's3cret',
						'endpoint' => 'https://crm.example.com',
					],
				],
			]
		);
		$this->assertSame( 200, $put->get_status() );
		$data = $this->json( $put->get_data() );
		$this->assertSame( [ 'endpoint' => 'https://crm.example.com' ], $data['integrations']['crm'] );
		$this->assertSame( [ 'token' => 'saved' ], $data['secrets']['crm'] );
		$this->assertStringNotContainsString( 's3cret', (string) wp_json_encode( get_option( 'barrio_forms_integrations' ) ) );

		// Un segreto vuoto resta quello salvato.
		$this->request( 'PUT', '/settings', [ 'integrations' => [ 'crm' => [ 'token' => '' ] ] ] );
		$this->assertSame( 's3cret', barrio_forms_integration_settings( 'crm' )['token'] );

		// Salvato con altri salt: non si legge più e va inserito di nuovo.
		update_option( 'barrio_forms_integrations', [ 'crm' => [ 'token' => base64_encode( str_repeat( 'x', 64 ) ) ] ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertSame( [ 'token' => 'unreadable' ], $this->json( $this->request( 'GET', '/settings' )->get_data() )['secrets']['crm'] );
		$this->assertSame( '', barrio_forms_integration_settings( 'crm' )['token'] );

		foreach ( [
			'unknown integration' => [ 'mailup' => [ 'key' => 'x' ] ],
			'unknown setting'     => [ 'crm' => [ 'debug' => true ] ],
			'not a string'        => [ 'crm' => [ 'endpoint' => [ 'https://crm.example.com' ] ] ],
		] as $what => $integrations ) {
			$this->assertSame( 400, $this->request( 'PUT', '/settings', [ 'integrations' => $integrations ] )->get_status(), $what );
		}
	}

	// Un'impostazione tolta da una nuova versione dell'estensione resta nell'opzione, ma non torna
	// nella pagina: rimandata indietro, farebbe rifiutare tutte le Impostazioni.
	public function test_settings_the_extension_no_longer_declares_do_not_block_saving(): void {
		update_option(
			'barrio_forms_integrations',
			[
				'crm' => [
					'endpoint' => 'https://crm.example.com',
					'region'   => 'eu',
				],
			]
		);

		$view = $this->json( $this->request( 'GET', '/settings' )->get_data() )['integrations'];

		$this->assertSame( [ 'endpoint' => 'https://crm.example.com' ], $view['crm'] );
		$this->assertSame( 200, $this->request( 'PUT', '/settings', [ 'integrations' => $view ] )->get_status() );
	}

	public function test_an_extension_registers_a_field_type(): void {
		FieldTypes::register(
			'tax_code',
			[
				'label'     => 'Tax code',
				'normalize' => static fn( Field $field, mixed $raw ): array => [ strtoupper( trim( (string) $raw ) ) ],
				'validate'  => static fn( Field $field, string $value ): ?string => preg_match( '/^[A-Z0-9]{16}$/', $value ) ? null : 'Not a tax code.',
			]
		);
		$this->assertSame( 'Tax code', FieldTypes::labels()['tax_code'] );
		$this->assertSame( 'page_break', array_key_last( FieldTypes::labels() ), 'The page break stays last in the builder.' );

		$form = FormRepository::create( 'Tax', [ 'it' => [ 'submit_label' => 'Invia' ] ], [], new FormVersion( [ new Field( 'cf', 'tax_code', [ 'it' => [ 'label' => 'Codice fiscale' ] ], true ) ] ) );
		// Senza render è un campo di testo.
		$this->assertMatchesRegularExpression( '/<input type="text"[^>]* name="cf" required/', do_shortcode( "[barrio_form id=\"{$form->id}\"]" ) );

		$this->assertSame( [ 'cf' => 'Not a tax code.' ], $this->submit( $form, 'it', [ 'cf' => 'abc' ] )->get_data()['data']['fields'] );
		$this->assertSame( 201, $this->submit( $form, 'it', [ 'cf' => ' rssmra80a01l219x ' ] )->get_status() );
		global $wpdb;
		$this->assertSame( 'RSSMRA80A01L219X', $wpdb->get_var( $wpdb->prepare( 'SELECT value FROM %i ORDER BY entry_id DESC LIMIT 1', Db::table( 'entry_values' ) ) ) );
	}

	public function test_core_field_types_cannot_be_replaced(): void {
		$this->setExpectedIncorrectUsage( 'BarrioSuite\Forms\FieldTypes::register' );
		FieldTypes::register( 'email', [ 'label' => 'Fake email' ] );

		$this->assertSame( 'Email', FieldTypes::labels()['email'] );
	}

	public function test_extensions_know_when_a_form_is_rendered(): void {
		$form     = $this->create();
		$rendered = [];
		add_action(
			'barrio_forms_render',
			static function ( Form $form ) use ( &$rendered ): void {
				$rendered[] = $form->id;
			}
		);

		do_shortcode( "[barrio_form id=\"{$form->id}\"]" );

		$this->assertSame( [ $form->id ], $rendered );
	}

	/** @param list<array<string, mixed>>|null $integrations */
	private function create( ?array $integrations = null ): Form {
		$data = $this->request( 'POST', '/forms', [ 'integrations' => $integrations ?? self::form()['integrations'] ] + self::form() )->get_data();
		return FormRepository::get( $data['id'] ) ?? $this->fail( (string) wp_json_encode( $data ) );
	}

	/** @param array<string, string> $values */
	private function submit( Form $form, string $lang = 'it', array $values = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', "/barrio/v1/forms/{$form->id}/entries" );
		$request->set_body_params(
			$values + [
				'name'         => 'Ada',
				'email'        => 'ada@example.com',
				'_barrio_js'   => '1',
				'_barrio_lang' => $lang,
			]
		);
		return rest_do_request( $request );
	}

	private function last_entry(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', Db::table( 'entries' ) ) );
	}

	/** @return list<list<string>> source, level e messaggio. */
	private function notes( int $entry ): array {
		global $wpdb;
		return array_map( 'array_values', $wpdb->get_results( $wpdb->prepare( 'SELECT source, level, message FROM %i WHERE entry_id = %d ORDER BY id', Db::table( 'entry_notes' ), $entry ), ARRAY_A ) );
	}

	/** @return array<string, mixed> Nel formato di scrittura del builder. */
	private static function form(): array {
		$field = static fn( string $key, string $type, string $label ): array => [
			'key'        => $key,
			'type'       => $type,
			'required'   => 'email' === $type,
			'config'     => [],
			'conditions' => null,
			'i18n'       => [ 'it' => [ 'label' => $label ] ],
		];
		return [
			'title'         => 'Newsletter',
			'i18n'          => [ 'it' => [ 'submit_label' => 'Iscriviti' ] ],
			'notifications' => [],
			'fields'        => [ $field( 'name', 'text', 'Nome' ), $field( 'email', 'email', 'Email' ), $field( 'newsletter', 'consent', 'Voglio la newsletter' ) ],
			'integrations'  => [
				[
					'slug'       => 'crm',
					'enabled'    => true,
					'settings'   => [
						'email' => 'email',
						'map'   => [ [ 'FNAME', 'name' ] ],
					],
					'conditions' => null,
					'i18n'       => [
						'it' => [ 'list' => 'Newsletter IT' ],
						'en' => [ 'list' => 'Newsletter EN' ],
					],
				],
			],
		];
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
