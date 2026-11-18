<?php

namespace BarrioSuite;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * I modelli Gemini di Vertex AI, con il service account di Google (src/Google.php): Vertex AI accetta i suoi token,
 * l'API Gemini di AI Studio solo le chiavi API. Modello e regione si scelgono dalle liste che dà l'API, quindi
 * nessun nome di modello o di regione è scritto qui. Finché mancano il service account o il modello, i "Genera"
 * dei moduli sono spenti (is_ready()) e generate() risponde con un errore.
 *
 * @phpstan-type Settings array{model: string, location: string, instructions: string}
 */
final class Ai {

	private const OPTION = 'barrio_ai';
	private const SCOPE  = 'https://www.googleapis.com/auth/cloud-platform';
	private const API    = 'https://aiplatform.googleapis.com/';
	// Il nome di un modello di Google come lo dà la lista: publishers/google/models/gemini-…
	private const MODEL = '^publishers/google/models/[A-Za-z0-9._-]+$';
	// L'ID di una regione (europe-west8): finisce nel nome dell'host.
	private const LOCATION = '^[a-z]+(-[a-z0-9]+)+$';

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'barrio/v1',
			'/ai',
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
						'model'        => [
							'type'    => 'string',
							'pattern' => '(' . self::MODEL . ')|^$',
						],
						// Vuota = l'endpoint globale, dove Google sceglie la regione.
						'location'     => [
							'type'    => 'string',
							'pattern' => '(' . self::LOCATION . ')|^$',
						],
						'instructions' => [
							'type'      => 'string',
							'maxLength' => 2000,
						],
					],
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/ai/models',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'models' ],
				'permission_callback' => $admin,
			]
		);
		register_rest_route(
			'barrio/v1',
			'/ai/locations',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'locations' ],
				'permission_callback' => $admin,
			]
		);
	}

	/** @return Settings */
	public static function settings(): array {
		$saved = (array) get_option( self::OPTION, [] );
		return [
			'model'        => (string) ( $saved['model'] ?? '' ),
			'location'     => (string) ( $saved['location'] ?? '' ),
			'instructions' => (string) ( $saved['instructions'] ?? '' ),
		];
	}

	/** @param array<string, string> $values model, location, instructions; quelli assenti restano come sono. */
	public static function save( array $values ): void {
		$settings                 = array_intersect_key( $values, self::settings() ) + self::settings();
		$settings['instructions'] = sanitize_textarea_field( $settings['instructions'] );
		update_option( self::OPTION, $settings, false );
	}

	public static function is_ready(): bool {
		return '' !== self::settings()['model'] && null !== Google::account();
	}

	public static function get(): WP_REST_Response {
		return new WP_REST_Response( self::settings() );
	}

	public static function put( WP_REST_Request $request ): WP_REST_Response {
		self::save( array_filter( array_intersect_key( $request->get_params(), self::settings() ), 'is_string' ) );
		return self::get();
	}

	/**
	 * I modelli Gemini di Google in Vertex AI, come { value, label }. La lista non dice quali scrivono testo: senza
	 * gli altri (Imagen, Veo, embedding) non si sceglie un modello che darebbe errore solo generando.
	 */
	public static function models(): WP_REST_Response|WP_Error {
		$models = self::pages( 'v1beta1/publishers/google/models', 'publisherModels' );
		if ( $models instanceof WP_Error ) {
			return $models;
		}
		$options = [];
		foreach ( $models as $model ) {
			$name = (string) ( $model['name'] ?? '' );
			if ( preg_match( '#' . self::MODEL . '#', $name ) && preg_match( '#/gemini-(?!embedding)#', $name ) ) {
				$options[ $name ] = [
					'value' => $name,
					'label' => basename( $name ),
				];
			}
		}
		ksort( $options );
		return new WP_REST_Response( array_values( $options ) );
	}

	// Le regioni di Vertex AI per il progetto del service account, come { value, label } ("Milan (europe-west8)").
	public static function locations(): WP_REST_Response|WP_Error {
		$project   = Google::account()['project_id'] ?? '';
		$locations = self::pages( "v1/projects/{$project}/locations", 'locations' );
		if ( $locations instanceof WP_Error ) {
			return $locations;
		}
		$options = [];
		foreach ( $locations as $location ) {
			$id = (string) ( $location['locationId'] ?? '' );
			if ( preg_match( '#' . self::LOCATION . '#', $id ) ) {
				$city           = (string) ( $location['displayName'] ?? '' );
				$options[ $id ] = [
					'value' => $id,
					'label' => '' === $city ? $id : "{$city} ({$id})",
				];
			}
		}
		uasort( $options, static fn( array $a, array $b ): int => strcmp( $a['label'], $b['label'] ) );
		return new WP_REST_Response( array_values( $options ) );
	}

	/**
	 * Il testo che il modello scelto scrive per $prompt, con la descrizione del sito in fondo. $config va in
	 * generationConfig: per esempio la risposta in JSON con il suo schema.
	 *
	 * @param array<string, mixed> $config
	 */
	public static function generate( string $prompt, array $config = [] ): string|WP_Error {
		$settings = self::settings();
		$account  = Google::account();
		if ( null === $account || '' === $settings['model'] ) {
			return new WP_Error( 'barrio_ai_setup', __( 'Set up Google AI in Barrio → Settings: the service account and the model.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		if ( '' !== $settings['instructions'] ) {
			$prompt .= "\n\nAbout the site, from its owner:\n{$settings['instructions']}";
		}
		// Senza una regione, l'endpoint globale: Google sceglie quella con capacità libera.
		$location = $settings['location'] ?: 'global';
		$host     = '' === $settings['location'] ? self::API : "https://{$location}-aiplatform.googleapis.com/";
		$body     = self::request(
			"{$host}v1/projects/{$account['project_id']}/locations/{$location}/{$settings['model']}:generateContent",
			[
				'contents' => [
					[
						'role'  => 'user',
						'parts' => [ [ 'text' => $prompt ] ],
					],
				],
			] + ( $config ? [ 'generationConfig' => $config ] : [] )
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		// I modelli che ragionano possono restituire anche i pensieri: si tengono solo le parti della risposta.
		$text = '';
		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? [] ) as $part ) {
			$text .= empty( $part['thought'] ) ? (string) ( $part['text'] ?? '' ) : '';
		}
		if ( '' === trim( $text ) ) {
			// Bloccata dai filtri di sicurezza o troncata: il motivo è in blockReason o finishReason.
			$reason = (string) ( $body['promptFeedback']['blockReason'] ?? $body['candidates'][0]['finishReason'] ?? '' );
			/* translators: %s: reason given by Google, for example SAFETY or MAX_TOKENS. */
			return new WP_Error( 'barrio_ai_empty', sprintf( __( 'Google returned no text (%s). Try again, or choose another model.', 'barrio-suite' ), $reason ), [ 'status' => 502 ] );
		}
		return trim( $text );
	}

	/**
	 * Gli elementi $key di tutte le pagine di una lista dell'API.
	 *
	 * @return list<array<string, mixed>>|WP_Error
	 */
	private static function pages( string $path, string $key ): array|WP_Error {
		$items = [];
		$page  = '';
		// ponytail: al più 10 pagine da 100, più dei modelli e delle regioni di Google di oggi.
		for ( $i = 0; $i < 10; $i++ ) {
			$body = self::request( self::API . "{$path}?pageSize=100" . ( '' === $page ? '' : '&pageToken=' . rawurlencode( $page ) ) );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$items = [ ...$items, ...array_values( array_filter( (array) ( $body[ $key ] ?? [] ), 'is_array' ) ) ];
			$page  = (string) ( $body['nextPageToken'] ?? '' );
			if ( '' === $page ) {
				break;
			}
		}
		return $items;
	}

	/**
	 * GET senza $body, POST con, con il token del service account.
	 *
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>|WP_Error
	 */
	private static function request( string $url, ?array $body = null ): array|WP_Error {
		$token = Google::token( self::SCOPE );
		if ( $token instanceof WP_Error ) {
			return $token;
		}
		$response = wp_remote_request(
			$url,
			[
				'method'  => null === $body ? 'GET' : 'POST',
				// I modelli che ragionano possono metterci decine di secondi.
				'timeout' => null === $body ? 15 : 60,
				'headers' => [
					'Authorization' => "Bearer {$token}",
					'Content-Type'  => 'application/json',
				],
				'body'    => null === $body ? '' : (string) wp_json_encode( $body ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'barrio_ai_http', $response->get_error_message(), [ 'status' => 502 ] );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $json ) ) {
			// Per esempio l'API di Vertex AI non attiva nel progetto, o il ruolo che manca al service account.
			$message = is_array( $json ) ? (string) ( $json['error']['message'] ?? '' ) : '';
			/* translators: 1: HTTP status code, 2: error message from Google. */
			return new WP_Error( 'barrio_ai_http', trim( sprintf( __( 'Google answered with error %1$d. %2$s', 'barrio-suite' ), $code, $message ) ), [ 'status' => 502 ] );
		}
		return $json;
	}
}
