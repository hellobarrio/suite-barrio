<?php

namespace BarrioSuite\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use WP_Error;

/**
 * SMTP per tutte le email del sito, non solo per quelle dei form: wp_mail() usa il PHPMailer di
 * WordPress, che qui si configura su phpmailer_init. Con l'host vuoto resta l'invio di WordPress.
 *
 * La password si definisce con la costante BARRIO_SMTP_PASSWORD in wp-config.php, oppure si salva
 * cifrata con una chiave derivata dai salt di WordPress: se i salt cambiano va inserita di nuovo.
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
		update_option( self::PASSWORD_OPTION, self::encrypt( $password ), false );
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

	public static function boot(): void {
		add_action( 'phpmailer_init', [ self::class, 'configure' ] );
		// Il mittente con i filtri e non su phpmailer_init: wp_mail() lo imposta prima, e con quello di
		// default non valido (wordpress@localhost) fallirebbe ancora prima di arrivare a configure().
		// Per ultimi, così vale anche per le email che un plugin invia con un suo From: i server SMTP
		// di solito accettano solo mittenti autorizzati, e SPF e DMARC vogliono il dominio giusto.
		add_filter( 'wp_mail_from', [ self::class, 'from_email' ], PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', [ self::class, 'from_name' ], PHP_INT_MAX );
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
		return is_string( $stored ) ? self::decrypt( $stored ) : null;
	}

	// sodium c'è sempre: WordPress include sodium_compat dove manca l'estensione.
	private static function key(): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- testo cifrato da salvare in un'opzione.
	}

	private static function decrypt( string $stored ): ?string {
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		return false === $plain ? null : $plain;
	}
}
