<?php

namespace BarrioSuite\Mail;

use BarrioSuite\Crypto;
use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * SMTP per tutte le email del sito, non solo per quelle dei form: wp_mail() usa il PHPMailer di
 * WordPress, che qui si configura su phpmailer_init. Con l'host vuoto resta l'invio di WordPress.
 * Si sceglie in Barrio → Impostazioni (GET e PUT /barrio/v1/smtp), anche con i moduli spenti.
 *
 * La password si definisce con la costante BARRIO_SMTP_PASSWORD in wp-config.php, oppure si salva
 * cifrata (Crypto): se i salt di WordPress cambiano va inserita di nuovo. DELETE /barrio/v1/smtp/password
 * la toglie dal database.
 *
 * @phpstan-type Settings array{host: string, port: int, encryption: 'none'|'ssl'|'tls', username: string, from_email: string, from_name: string}
 */
final class Smtp {

	private const OPTION          = 'barrio_smtp';
	private const PASSWORD_OPTION = 'barrio_smtp_password';
	private const DEFAULTS        = [
		'host'       => '',
		'port'       => 587,
		'encryption' => 'tls',
		'username'   => '',
		'from_email' => '',
		'from_name'  => '',
	];

	/** @return array<string, mixed> JSON Schema delle impostazioni, senza la password. */
	public static function schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'host'       => [
					'type'      => 'string',
					'maxLength' => 255,
					'pattern'   => '^[A-Za-z0-9.-]*$',
				],
				'port'       => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 65535,
				],
				'encryption' => [
					'type' => 'string',
					'enum' => [ 'none', 'ssl', 'tls' ],
				],
				'username'   => [
					'type'      => 'string',
					'maxLength' => 255,
				],
				// Vuoto = il mittente scelto da WordPress o dal plugin che invia.
				'from_email' => [
					'type'  => 'string',
					'anyOf' => [
						[
							'type'   => 'string',
							'format' => 'email',
						],
						[
							'type'      => 'string',
							'maxLength' => 0,
						],
					],
				],
				'from_name'  => [
					'type'      => 'string',
					'maxLength' => 255,
					'pattern'   => '^[^\r\n]*$',
				],
			],
		];
	}

	/** @return Settings */
	public static function settings(): array {
		/** @var Settings */
		return array_intersect_key( (array) get_option( self::OPTION, [] ), self::DEFAULTS ) + self::DEFAULTS;
	}

	/** @param array<string, mixed> $settings Già validate con schema(); quelle assenti restano come sono. */
	public static function save( array $settings ): void {
		update_option( self::OPTION, array_intersect_key( $settings, self::DEFAULTS ) + self::settings(), false );
	}

	public static function save_password( string $password ): void {
		update_option( self::PASSWORD_OPTION, Crypto::encrypt( $password ), false );
	}

	/** @return 'none'|'saved'|'unreadable'|'constant' unreadable: salvata con salt che non ci sono più. */
	public static function password_status(): string {
		if ( defined( 'BARRIO_SMTP_PASSWORD' ) ) {
			return 'constant';
		}
		if ( false === get_option( self::PASSWORD_OPTION ) ) {
			return 'none';
		}
		return null === self::password() ? 'unreadable' : 'saved';
	}

	// Messa online (src/Launch.php): le email partono dall'SMTP; senza utente il server non chiede la password.
	public static function is_configured(): bool {
		$smtp = self::settings();
		return '' !== $smtp['host'] && '' !== $smtp['from_email']
			&& ( '' === $smtp['username'] || in_array( self::password_status(), [ 'saved', 'constant' ], true ) );
	}

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
		add_action( 'phpmailer_init', [ self::class, 'configure' ] );
		// Il mittente con i filtri e non su phpmailer_init: wp_mail() lo imposta prima, e con quello di
		// default non valido (wordpress@localhost) fallirebbe ancora prima di arrivare a configure().
		// Per ultimi, così vale anche per le email che un plugin invia con un suo From: i server SMTP
		// di solito accettano solo mittenti autorizzati, e SPF e DMARC vogliono il dominio giusto.
		add_filter( 'wp_mail_from', [ self::class, 'from_email' ], PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', [ self::class, 'from_name' ], PHP_INT_MAX );
	}

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'barrio/v1',
			'/smtp',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get' ],
					'permission_callback' => $admin,
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'put' ],
					'permission_callback' => $admin,
					'args'                => [
						'smtp'     => self::schema(),
						// In sola scrittura: non torna mai indietro. Assente o vuota = resta quella salvata.
						'password' => [
							'type'      => 'string',
							'maxLength' => 255,
						],
					],
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/smtp/password',
			[
				'methods'             => 'DELETE',
				'callback'            => [ self::class, 'delete_password' ],
				'permission_callback' => $admin,
			]
		);
		register_rest_route(
			'barrio/v1',
			'/smtp/test-email',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'test_email' ],
				'permission_callback' => $admin,
				// Assente = all'indirizzo di chi la chiede.
				'args'                => [
					'to' => [
						'type'   => 'string',
						'format' => 'email',
					],
				],
			]
		);
	}

	public static function get(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'smtp'            => self::settings(),
				'password_status' => self::password_status(),
			]
		);
	}

	public static function put( WP_REST_Request $request ): WP_REST_Response {
		if ( is_array( $request['smtp'] ) ) {
			self::save( $request['smtp'] );
		}
		if ( is_string( $request['password'] ) && '' !== $request['password'] ) {
			self::save_password( $request['password'] );
		}
		return self::get();
	}

	public static function delete_password(): WP_REST_Response {
		delete_option( self::PASSWORD_OPTION );
		return self::get();
	}

	// Con le impostazioni salvate.
	public static function test_email( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$to    = is_string( $request['to'] ) ? $request['to'] : wp_get_current_user()->user_email;
		$error = self::send(
			$to,
			/* translators: %s: site name. */
			sprintf( __( 'Test email from %s', 'barrio-suite' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			wpautop( __( 'If you are reading this, the site can send email.', 'barrio-suite' ) )
		);
		if ( null !== $error ) {
			return new WP_Error( 'barrio_mail_failed', $error->get_error_message(), [ 'status' => 502 ] );
		}
		/* translators: %s: email address. */
		return new WP_REST_Response( [ 'message' => sprintf( __( 'Test email sent to %s.', 'barrio-suite' ), $to ) ] );
	}

	public static function from_email( string $email ): string {
		$from = self::settings()['from_email'];
		return is_email( $from ) ? $from : $email;
	}

	public static function from_name( string $name ): string {
		return self::settings()['from_name'] ?: $name;
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- proprietà di PHPMailer.
	public static function configure( PHPMailer $mailer ): void {
		$settings = self::settings();
		if ( '' === $settings['host'] ) {
			return;
		}
		$mailer->isSMTP();
		$mailer->Host       = $settings['host'];
		$mailer->Port       = $settings['port'];
		$mailer->SMTPSecure = 'none' === $settings['encryption'] ? '' : $settings['encryption'];
		// Con "none" niente STARTTLS automatico, che PHPMailer altrimenti usa se il server lo offre.
		$mailer->SMTPAutoTLS = 'none' !== $settings['encryption'];
		$mailer->SMTPAuth    = '' !== $settings['username'];
		$mailer->Username    = $settings['username'];
		$mailer->Password    = self::password() ?? '';
		// L'invio di un form aspetta le notifiche: 10 secondi invece dei 300 di PHPMailer.
		$mailer->Timeout = 10;
	}
	// phpcs:enable

	/**
	 * wp_mail() in HTML, con l'errore al posto di false: il messaggio di PHPMailer arriva solo con
	 * l'azione wp_mail_failed.
	 *
	 * @param string|list<string>   $to
	 * @param list<string>          $headers
	 * @param array<string, string> $attachments Nome del file nell'email => percorso sul disco.
	 */
	public static function send( string|array $to, string $subject, string $html, array $headers = [], array $attachments = [] ): ?WP_Error {
		$error = null;
		$catch = static function ( WP_Error $failure ) use ( &$error ): void {
			$error = $failure;
		};
		add_action( 'wp_mail_failed', $catch );
		try {
			$sent = wp_mail( $to, $subject, $html, [ 'Content-Type: text/html; charset=UTF-8', ...$headers ], $attachments );
		} finally {
			remove_action( 'wp_mail_failed', $catch );
		}
		return $sent ? null : $error ?? new WP_Error( 'barrio_mail_failed', __( 'The email could not be sent.', 'barrio-suite' ) );
	}

	private static function password(): ?string {
		if ( defined( 'BARRIO_SMTP_PASSWORD' ) ) {
			return (string) constant( 'BARRIO_SMTP_PASSWORD' );
		}
		$stored = get_option( self::PASSWORD_OPTION );
		return is_string( $stored ) ? Crypto::decrypt( $stored ) : null;
	}
}
