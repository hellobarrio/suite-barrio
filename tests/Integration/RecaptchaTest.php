<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Forms\Integrations;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Repository\FormRepository;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

// L'estensione barrio-suite-recaptcha (caricata da tests/bootstrap.php), con le risposte di Google
// simulate su pre_http_request.
final class RecaptchaTest extends WP_UnitTestCase {

	private const SETTINGS = [
		'project_id' => 'barrio-site',
		'site_key'   => 'site-key-123',
		'api_key'    => 'api-key-456',
	];

	private Form $form;

	/** @var list<array{url: string, body: array<string, mixed>}> */
	private array $requests = [];

	/** @var array{code: int, body: array<string, mixed>}|WP_Error */
	private array|WP_Error $response = [
		'code' => 200,
		'body' => [],
	];

	public function set_up(): void {
		parent::set_up();
		$this->form = FormRepository::create( 'Contact', [ 'it' => [ 'submit_label' => 'Invia' ] ], [], new FormVersion( [ new Field( 'name', 'text', [ 'it' => [ 'label' => 'Nome' ] ] ) ] ) );
		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array|WP_Error {
				$this->requests[] = [
					'url'  => $url,
					'body' => json_decode( $args['body'], true ),
				];
				return $this->response instanceof WP_Error ? $this->response : [
					'headers'  => [],
					'body'     => (string) wp_json_encode( $this->response['body'] ),
					'response' => [ 'code' => $this->response['code'] ],
					'cookies'  => [],
				];
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		wp_dequeue_script( 'barrio-recaptcha' );
		parent::tear_down();
	}

	public function test_without_all_the_settings_nothing_changes(): void {
		Integrations::save_settings( [ 'recaptcha' => [ 'site_key' => 'site-key-123' ] ] );

		do_shortcode( "[barrio_form id=\"{$this->form->id}\"]" );

		$this->assertFalse( wp_script_is( 'barrio-recaptcha' ) );
		$this->assertSame( 201, $this->post() );
		$this->assertSame( [], $this->requests );
		// Il controllo è spento: gli amministratori lo sanno (anche con la chiave API illeggibile dopo un clone).
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertStringContainsString( 'reCAPTCHA is off', $this->notice() );
	}

	public function test_the_google_script_is_loaded_only_with_a_form(): void {
		Integrations::save_settings( [ 'recaptcha' => self::SETTINGS ] );
		$this->assertFalse( wp_script_is( 'barrio-recaptcha' ) );

		do_shortcode( "[barrio_form id=\"{$this->form->id}\"]" );

		$this->assertTrue( wp_script_is( 'barrio-recaptcha' ) );
		$this->assertSame( 'https://www.google.com/recaptcha/enterprise.js?render=site-key-123', wp_scripts()->registered['barrio-recaptcha']->src );
		$this->assertStringContainsString( '"siteKey":"site-key-123"', implode( '', wp_scripts()->get_data( 'barrio-recaptcha', 'before' ) ) );
	}

	public function test_a_submission_needs_a_valid_token_with_a_good_score(): void {
		Integrations::save_settings( [ 'recaptcha' => self::SETTINGS ] );
		$assessment = static fn( bool $valid, string $action, float $score ): array => [
			'code' => 200,
			'body' => [
				'tokenProperties' => [
					'valid'  => $valid,
					'action' => $action,
				],
				'riskAnalysis'    => [ 'score' => $score ],
			],
		];

		$this->assertSame( 400, $this->post(), 'Without a token.' );
		$this->assertSame( [], $this->requests );

		$this->response = $assessment( true, 'barrio_form', 0.9 );
		$this->assertSame( 201, $this->post( 'token-abc' ) );
		$this->assertSame(
			[
				'url'  => 'https://recaptchaenterprise.googleapis.com/v1/projects/barrio-site/assessments?key=api-key-456',
				'body' => [
					'event' => [
						'token'          => 'token-abc',
						'siteKey'        => 'site-key-123',
						'expectedAction' => 'barrio_form',
					],
				],
			],
			$this->requests[0]
		);

		foreach ( [
			'low score'      => $assessment( true, 'barrio_form', 0.1 ),
			'another action' => $assessment( true, 'login', 0.9 ),
			'invalid token'  => $assessment( false, '', 0 ),
		] as $what => $response ) {
			$this->response = $response;
			$this->assertSame( 400, $this->post( 'token-abc' ), $what );
		}
	}

	public function test_without_an_answer_from_google_the_submission_is_refused_and_admins_are_told(): void {
		Integrations::save_settings( [ 'recaptcha' => self::SETTINGS ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->response = new WP_Error( 'http_request_failed', 'cURL error 28' );
		$this->assertSame( 503, $this->post( 'token-abc' ) );
		$this->assertStringContainsString( 'cURL error 28', $this->notice() );

		$this->response = [
			'code' => 400,
			'body' => [ 'error' => [ 'message' => 'API key not valid. Please pass a valid API key.' ] ],
		];
		$this->assertSame( 503, $this->post( 'token-abc' ) );
		$this->assertStringContainsString( 'HTTP 400 API key not valid.', $this->notice() );

		// Solo gli amministratori lo vedono.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( '', $this->notice() );

		// Con Google di nuovo raggiungibile l'avviso sparisce, anche se il token è rifiutato.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->response = [
			'code' => 200,
			'body' => [ 'tokenProperties' => [ 'valid' => false ] ],
		];
		$this->assertSame( 400, $this->post( 'token-abc' ) );
		$this->assertSame( '', $this->notice() );
	}

	public function test_a_bot_caught_by_the_honeypot_does_not_reach_google(): void {
		Integrations::save_settings( [ 'recaptcha' => self::SETTINGS ] );

		$this->assertSame( 400, $this->post( 'token-abc', [ '_barrio_hp' => 'spam' ] ) );
		$this->assertSame( [], $this->requests );
	}

	// Solo l'avviso dell'estensione: con WPGraphQL c'è anche il suo, sulla raccolta di dati.
	private function notice(): string {
		$this->assertSame( 10, has_action( 'admin_notices', 'barrio_recaptcha_notice' ) );
		ob_start();
		barrio_recaptcha_notice();
		return (string) ob_get_clean();
	}

	/** @param array<string, string> $input */
	private function post( ?string $token = null, array $input = [] ): int {
		$request = new WP_REST_Request( 'POST', "/barrio/v1/forms/{$this->form->id}/entries" );
		$request->set_body_params( $input + [ '_barrio_js' => '1' ] + ( null === $token ? [] : [ '_barrio_recaptcha' => $token ] ) );
		return rest_do_request( $request )->get_status();
	}
}
