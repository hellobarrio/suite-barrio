<?php

namespace BarrioSuite;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Il service account di Google Cloud con cui il sito usa i servizi di Google: l'AI (Vertex AI, src/Ai.php) e, più
 * avanti, Analytics. Si carica il file JSON della chiave in Barrio → Impostazioni; si salvano cifrati (Crypto) solo
 * email, progetto e chiave privata. I token si chiedono a Google con un JWT firmato dalla chiave (il flusso dei
 * service account di OAuth 2.0) e si tengono in cache, cifrati, finché valgono.
 *
 * @phpstan-type Account array{client_email: string, private_key: string, project_id: string}
 */
final class Google {

	private const OPTION = 'barrio_google';
	// L'endpoint dei token di Google, sempre questo: quello del file non si usa, così una chiave modificata non
	// manda il JWT firmato altrove.
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'barrio/v1',
			'/google',
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
						// Il contenuto del file JSON della chiave. In sola scrittura: torna solo email e progetto.
						'service_account' => [
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 10000,
						],
					],
				],
			]
		);
	}

	/** @return array{status: 'none'|'saved'|'unreadable', email: string, project: string} */
	public static function status(): array {
		$account = self::account();
		$status  = false === get_option( self::OPTION ) ? 'none' : 'unreadable';
		return [
			'status'  => null === $account ? $status : 'saved',
			'email'   => $account['client_email'] ?? '',
			'project' => $account['project_id'] ?? '',
		];
	}

	public static function get(): WP_REST_Response {
		return new WP_REST_Response( self::status() );
	}

	public static function put( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$account = self::parse( (string) $request['service_account'] );
		if ( $account instanceof WP_Error ) {
			return $account;
		}
		update_option( self::OPTION, Crypto::encrypt( (string) wp_json_encode( $account ) ), false );
		return self::get();
	}

	/**
	 * Il file JSON di una chiave di un service account, ridotto a ciò che serve.
	 *
	 * @return Account|WP_Error
	 */
	public static function parse( string $json ): array|WP_Error {
		$file    = json_decode( $json, true );
		$account = [
			'client_email' => (string) ( $file['client_email'] ?? '' ),
			'private_key'  => (string) ( $file['private_key'] ?? '' ),
			'project_id'   => (string) ( $file['project_id'] ?? '' ),
		];
		if ( 'service_account' !== ( $file['type'] ?? '' ) || ! is_email( $account['client_email'] ) || ! preg_match( '/^[a-z][a-z0-9-]{4,28}[a-z0-9]$/', $account['project_id'] ) ) {
			return new WP_Error( 'barrio_google_file', __( 'This is not the JSON key of a Google Cloud service account.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		if ( ! function_exists( 'openssl_sign' ) || false === openssl_pkey_get_private( $account['private_key'] ) ) {
			return new WP_Error( 'barrio_google_key', __( 'The private key of the file cannot be read: download a new JSON key, or ask the hosting to turn on the OpenSSL extension of PHP.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		return $account;
	}

	/** @return Account|null Null se manca o non si legge più, perché salvato con altri salt di WordPress. */
	public static function account(): ?array {
		$stored = get_option( self::OPTION );
		$plain  = is_string( $stored ) ? Crypto::decrypt( $stored ) : null;
		$json   = null === $plain ? null : json_decode( $plain, true );
		/** @var Account|null */
		return is_array( $json ) ? $json : null;
	}

	/**
	 * Un token di accesso per $scope (per esempio https://www.googleapis.com/auth/cloud-platform), dalla cache
	 * finché vale.
	 */
	public static function token( string $scope ): string|WP_Error {
		$account = self::account();
		if ( null === $account ) {
			return new WP_Error( 'barrio_google_account', __( 'Add the Google service account in Barrio → Settings.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		$key    = 'barrio_google_token_' . md5( $account['client_email'] . $scope );
		$cached = get_transient( $key );
		$token  = is_string( $cached ) ? Crypto::decrypt( $cached ) : null;
		if ( null !== $token ) {
			return $token;
		}

		$now      = time();
		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'timeout' => 15,
				'body'    => [
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => self::jwt(
						[
							'iss'   => $account['client_email'],
							'scope' => $scope,
							'aud'   => self::TOKEN_URL,
							'iat'   => $now,
							'exp'   => $now + HOUR_IN_SECONDS,
						],
						$account['private_key']
					),
				],
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'barrio_google_http', $response->get_error_message(), [ 'status' => 502 ] );
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) || ! is_string( $json['access_token'] ?? null ) ) {
			// Google spiega in error_description: chiave revocata, service account eliminato…
			$reason = is_array( $json ) ? (string) ( $json['error_description'] ?? $json['error'] ?? '' ) : '';
			/* translators: %s: reason given by Google. */
			return new WP_Error( 'barrio_google_token', trim( sprintf( __( 'Google did not accept the service account. %s', 'barrio-suite' ), $reason ) ), [ 'status' => 502 ] );
		}
		// Un minuto prima della scadenza, per una richiesta che parte proprio allora.
		set_transient( $key, Crypto::encrypt( $json['access_token'] ), max( 60, (int) ( $json['expires_in'] ?? 3600 ) - 60 ) );
		return $json['access_token'];
	}

	/** @param array<string, mixed> $claims */
	private static function jwt( array $claims, string $private_key ): string {
		$encode = static fn( string $data ): string => rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url del JWT.
		$header = [
			'alg' => 'RS256',
			'typ' => 'JWT',
		];
		$input  = $encode( (string) wp_json_encode( $header ) ) . '.' . $encode( (string) wp_json_encode( $claims ) );
		openssl_sign( $input, $signature, $private_key, OPENSSL_ALGO_SHA256 );
		return $input . '.' . $encode( (string) $signature );
	}
}
