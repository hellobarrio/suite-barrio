<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Mail\Smtp;
use PHPMailer\PHPMailer\PHPMailer;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Impostazioni SMTP e template attraverso la REST admin, e la configurazione di PHPMailer.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- proprietà di PHPMailer.
final class SettingsTest extends WP_UnitTestCase {

	private const SMTP = [
		'host'       => 'smtp.example.com',
		'port'       => 465,
		'encryption' => 'ssl',
		'username'   => 'forms@example.com',
		'from_email' => 'forms@example.com',
		'from_name'  => 'Example forms',
	];

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		reset_phpmailer_instance();
		parent::tear_down();
		// I ruoli restano in memoria anche dopo il rollback: si rileggono dal database.
		wp_roles()->for_site();
	}

	public function test_settings_are_saved_and_the_password_never_comes_back(): void {
		$this->assertSame(
			[
				'host'       => '',
				'port'       => 587,
				'encryption' => 'tls',
				'username'   => '',
				'from_email' => '',
				'from_name'  => '',
			],
			$this->get()['smtp']
		);
		$this->assertSame( 'none', $this->get()['password_status'] );

		$response = $this->put(
			[
				'smtp'     => self::SMTP,
				'password' => 's3cret-pass',
				'email'    => [
					'accent_color' => '#ff0066',
					'footer'       => 'Via Roma 1, Torino',
				],
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::SMTP, $response->get_data()['smtp'] );
		$this->assertSame( 'saved', $response->get_data()['password_status'] );
		$this->assertSame( [ '#ff0066', '#f0f0f1', 'Via Roma 1, Torino' ], [ $response->get_data()['email']['accent_color'], $response->get_data()['email']['background_color'], $response->get_data()['email']['footer'] ] );
		// Né la risposta né l'opzione salvata contengono la password in chiaro.
		$this->assertStringNotContainsString( 's3cret-pass', (string) wp_json_encode( $this->get() ) );
		$this->assertStringNotContainsString( 's3cret-pass', (string) get_option( 'barrio_smtp_password' ) );

		// Senza password e con solo una parte delle impostazioni: il resto non cambia.
		$this->put( [ 'smtp' => [ 'port' => 2525 ] ] );
		$this->assertSame( [ 'port' => 2525 ] + self::SMTP, $this->get()['smtp'] );
		$this->assertSame( 'saved', $this->get()['password_status'] );
		$this->assertSame( 's3cret-pass', $this->configured()->Password );
	}

	public function test_invalid_settings_are_rejected(): void {
		foreach ( [
			'port'           => [ 'smtp' => [ 'port' => 0 ] ],
			'encryption'     => [ 'smtp' => [ 'encryption' => 'starttls' ] ],
			'host'           => [ 'smtp' => [ 'host' => 'smtp.example.com; rm -rf' ] ],
			'from email'     => [ 'smtp' => [ 'from_email' => 'not an email' ] ],
			'from name'      => [ 'smtp' => [ 'from_name' => "Forms\r\nBcc: x@evil.test" ] ],
			'unknown'        => [ 'smtp' => [ 'debug' => true ] ],
			'color'          => [ 'email' => [ 'accent_color' => 'red' ] ],
			'password array' => [ 'password' => [ 'x' ] ],
		] as $what => $body ) {
			$this->assertSame( 400, $this->put( $body )->get_status(), $what );
		}
		$this->assertSame( '', $this->get()['smtp']['host'] );

		$this->put( [ 'email' => [ 'logo' => 'javascript:alert(1)' ] ] );
		$this->assertSame( '', $this->get()['email']['logo'] );
	}

	public function test_only_administrators_manage_settings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/settings' ) )->get_status() );
		$this->assertSame( 403, $this->put( [ 'smtp' => self::SMTP ] )->get_status() );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'POST', '/barrio/v1/settings/test-email' ) )->get_status() );
	}

	// Permessi: cosa può fare ogni ruolo, nei ruoli di WordPress. Gli amministratori hanno sempre tutto.
	public function test_each_role_gets_the_capabilities_chosen_in_the_settings(): void {
		$settings = $this->get();
		$this->assertSame( [ 'barrio_manage_entries' ], $settings['roles']['editor'], 'From migration 0002.' );
		$this->assertSame( [], $settings['roles']['author'] );
		$this->assertArrayNotHasKey( 'administrator', $settings['roles'] );
		$this->assertSame( 'Administrator', $settings['role_names']['administrator'] );

		$response = $this->put(
			[
				'roles' => [
					'editor'        => [ 'barrio_manage_forms' ],
					'author'        => [ 'barrio_manage_entries' ],
					'administrator' => [],
					'unknown'       => [ 'barrio_manage_forms' ],
				],
			]
		);

		$this->assertSame( [ 'barrio_manage_forms' ], $response->get_data()['roles']['editor'] );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'barrio_manage_entries' ) );
		$this->assertTrue( get_role( 'author' )->has_cap( 'barrio_manage_entries' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'barrio_manage_forms' ), 'Administrators keep everything.' );
		$this->assertSame( [], $response->get_data()['roles']['contributor'], 'Roles not sent stay as they are.' );
		$this->assertSame( 400, $this->put( [ 'roles' => [ 'editor' => [ 'manage_options' ] ] ] )->get_status(), 'Only the capabilities of the module.' );

		// Chi gestisce solo i form vede l'elenco, non le registrazioni.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/forms' ) )->get_status() );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/forms/1/entries' ) )->get_status() );
		$this->assertSame( 403, $this->put( [ 'roles' => [ 'editor' => [ 'barrio_manage_entries' ] ] ] )->get_status() );
	}

	public function test_phpmailer_is_configured_for_every_email(): void {
		// Senza host resta l'invio di WordPress, ma il mittente vale lo stesso, anche su quello di un plugin.
		Smtp::save( [ 'from_email' => 'forms@example.com' ] );
		add_filter( 'wp_mail_from', static fn(): string => 'shop@example.net' );
		$this->assertSame( 'mail', $this->configured()->Mailer );
		$this->assertSame( [ 'forms@example.com', 'WordPress' ], [ apply_filters( 'wp_mail_from', 'wordpress@localhost' ), apply_filters( 'wp_mail_from_name', 'WordPress' ) ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook di WordPress.

		Smtp::save( self::SMTP );
		Smtp::save_password( 'pw' );
		$mailer = $this->configured();
		$this->assertSame(
			[ 'smtp', 'smtp.example.com', 465, 'ssl', true, true, 'forms@example.com', 'pw', 10 ],
			[ $mailer->Mailer, $mailer->Host, $mailer->Port, $mailer->SMTPSecure, $mailer->SMTPAutoTLS, $mailer->SMTPAuth, $mailer->Username, $mailer->Password, $mailer->Timeout ]
		);
		$this->assertSame( 'Example forms', apply_filters( 'wp_mail_from_name', 'WordPress' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook di WordPress.

		Smtp::save(
			[
				'encryption' => 'none',
				'username'   => '',
			]
		);
		$mailer = $this->configured();
		$this->assertSame( [ '', false, false ], [ $mailer->SMTPSecure, $mailer->SMTPAutoTLS, $mailer->SMTPAuth ] );
	}

	public function test_a_password_saved_with_other_salts_is_reported(): void {
		Smtp::save_password( 'pw' );
		add_filter( 'salt', static fn( string $salt ): string => "{$salt}-changed" );

		$this->assertSame( 'unreadable', $this->get()['password_status'] );
		$this->assertSame( '', $this->configured()->Password );
	}

	public function test_the_test_email_uses_the_template(): void {
		$this->put(
			[
				'email' => [
					'logo'         => 'https://example.org/logo.png',
					'accent_color' => '#123456',
				],
			]
		);
		$response = $this->test_email( 'ada@example.com' );

		$this->assertSame( [ 'message' => 'Test email sent to ada@example.com.' ], $response->get_data() );
		$sent = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( [ 'ada@example.com', 'Test email from Test Blog' ], [ $sent->to[0][0], $sent->subject ] );
		$this->assertStringContainsString( 'Content-Type: text/html; charset=UTF-8', $sent->header );
		$this->assertStringContainsString( '<img src="https://example.org/logo.png" alt="Test Blog"', $sent->body );
		$this->assertStringContainsString( 'border-top:4px solid #123456', $sent->body );
		// Senza footer: il nome del sito con il link alla home.
		$this->assertStringContainsString( '<a href="http://example.org/">Test Blog</a>', $sent->body );

		// Senza destinatario va a chi la chiede.
		$this->test_email( null );
		$this->assertSame( wp_get_current_user()->user_email, tests_retrieve_phpmailer_instance()->get_sent( 1 )->to[0][0] );
	}

	public function test_a_failed_test_email_returns_the_smtp_error(): void {
		// PHPMailer vero verso una porta chiusa: il server rifiuta subito la connessione.
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		$GLOBALS['phpmailer'] = new PHPMailer( true ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- PHPMailer vero al posto del mock dei test.
		Smtp::save(
			[
				'host'       => '127.0.0.1',
				'port'       => 1,
				'encryption' => 'none',
			]
		);

		$response = $this->test_email( 'ada@example.com' );

		$this->assertSame( [ 502, 'barrio_mail_failed' ], [ $response->get_status(), $response->get_data()['code'] ] );
		$this->assertStringContainsString( 'SMTP', $response->get_data()['message'] );
	}

	/** @return array<string, mixed> */
	private function get(): array {
		return rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/settings' ) )->get_data();
	}

	/** @param array<string, mixed> $body */
	private function put( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', '/barrio/v1/settings' );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	private function test_email( ?string $to ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/barrio/v1/settings/test-email' );
		$request->set_body_params( null === $to ? [] : [ 'to' => $to ] );
		return rest_do_request( $request );
	}

	private function configured(): PHPMailer {
		$mailer = new PHPMailer( true );
		do_action_ref_array( 'phpmailer_init', [ &$mailer ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook di WordPress.
		return $mailer;
	}
}
