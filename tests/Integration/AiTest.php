<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Ai;
use BarrioSuite\Google;
use BarrioSuite\Seo\Admin;
use BarrioSuite\Seo\Editor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Il service account di Google (src/Google.php), l'AI (src/Ai.php) e "Genera con AI" del SEO (Api/RestGenerate.php),
// con Google simulato su pre_http_request: l'endpoint dei token controlla la firma del JWT.
final class AiTest extends WP_UnitTestCase {

	private const MODEL = 'publishers/google/models/gemini-test';

	private static string $private_key = '';

	private static string $public_key = '';

	/** @var list<array{method: string, url: string, auth: string, body: mixed}> Le richieste a Vertex AI. */
	private array $requests = [];

	/** @var list<array<string, mixed>> I claim dei JWT ricevuti dall'endpoint dei token. */
	private array $tokens = [];

	/** @var list<array{code: int, body: array<string, mixed>}> Le risposte di Vertex AI, in ordine. */
	private array $responses = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$key = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
		openssl_pkey_export( $key, self::$private_key );
		self::$public_key = openssl_pkey_get_details( $key )['key'];
	}

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		update_option( 'blogname', 'Hotel Lago' );
		add_filter( 'pre_http_request', [ $this, 'google' ], 10, 3 );
	}

	/** @param array<string, mixed> $args */
	public function google( mixed $pre, array $args, string $url ): array {
		if ( 'https://oauth2.googleapis.com/token' === $url ) {
			[ $header, $claims, $signature ] = explode( '.', $args['body']['assertion'] );
			$decode                          = static fn( string $part ): string => (string) base64_decode( strtr( $part, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$valid                           = 1 === openssl_verify( "{$header}.{$claims}", $decode( $signature ), self::$public_key, OPENSSL_ALGO_SHA256 );
			$this->tokens[]                  = json_decode( $decode( $claims ), true );
			return $this->response(
				$valid ? 200 : 400,
				$valid ? [
					'access_token' => 'ya29.test',
					'expires_in'   => 3599,
				] : [
					'error'             => 'invalid_grant',
					'error_description' => 'Invalid JWT Signature.',
				]
			);
		}
		$this->requests[] = [
			'method' => $args['method'],
			'url'    => $url,
			'auth'   => $args['headers']['Authorization'],
			'body'   => json_decode( $args['body'], true ),
		];
		$response         = array_shift( $this->responses ) ?? [
			'code' => 500,
			'body' => [],
		];
		return $this->response( $response['code'], $response['body'] );
	}

	public function test_the_service_account_is_saved_encrypted_and_only_email_and_project_come_back(): void {
		$response = $this->request( 'PUT', '/barrio/v1/google', [ 'service_account' => $this->file() ] );

		$this->assertSame(
			[
				'status'  => 'saved',
				'email'   => 'barrio@hotel-lago.iam.gserviceaccount.com',
				'project' => 'hotel-lago',
			],
			$response->get_data()
		);
		$this->assertStringNotContainsString( 'PRIVATE KEY', (string) get_option( 'barrio_google' ) );
		$this->assertSame( [ 'client_email', 'private_key', 'project_id' ], array_keys( (array) Google::account() ) );

		update_option( 'barrio_google', 'saved-with-other-salts' );
		$this->assertSame( 'unreadable', $this->request( 'GET', '/barrio/v1/google' )->get_data()['status'] );
	}

	public function test_a_file_that_is_not_a_service_account_key_is_rejected(): void {
		$client = $this->request( 'PUT', '/barrio/v1/google', [ 'service_account' => (string) wp_json_encode( [ 'type' => 'authorized_user' ] ) ] );
		$broken = $this->request( 'PUT', '/barrio/v1/google', [ 'service_account' => $this->file( [ 'private_key' => "-----BEGIN PRIVATE KEY-----\nnope\n-----END PRIVATE KEY-----\n" ] ) ] );

		$this->assertSame( 'barrio_google_file', $client->as_error()->get_error_code() );
		$this->assertSame( 'barrio_google_key', $broken->as_error()->get_error_code() );
		$this->assertFalse( get_option( 'barrio_google' ) );
	}

	public function test_the_token_comes_from_a_signed_jwt_and_is_kept_until_it_expires(): void {
		// Il token_uri del file non conta: il JWT firmato va solo a Google.
		$this->request( 'PUT', '/barrio/v1/google', [ 'service_account' => $this->file( [ 'token_uri' => 'https://example.com/token' ] ) ] );

		$this->assertSame( 'ya29.test', Google::token( 'https://www.googleapis.com/auth/cloud-platform' ) );
		$this->assertSame( 'ya29.test', Google::token( 'https://www.googleapis.com/auth/cloud-platform' ) );

		$this->assertCount( 1, $this->tokens );
		$this->assertSame( 'barrio@hotel-lago.iam.gserviceaccount.com', $this->tokens[0]['iss'] );
		$this->assertSame( 'https://www.googleapis.com/auth/cloud-platform', $this->tokens[0]['scope'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $this->tokens[0]['aud'] );
		$this->assertSame( 3600, $this->tokens[0]['exp'] - $this->tokens[0]['iat'] );
		$this->assertStringNotContainsString( 'ya29', (string) get_transient( 'barrio_google_token_' . md5( 'barrio@hotel-lago.iam.gserviceaccount.comhttps://www.googleapis.com/auth/cloud-platform' ) ) );
	}

	public function test_a_service_account_refused_by_google_is_reported(): void {
		$other = openssl_pkey_new();
		openssl_pkey_export( $other, $key );
		$this->request( 'PUT', '/barrio/v1/google', [ 'service_account' => $this->file( [ 'private_key' => $key ] ) ] );

		$token = Google::token( 'https://www.googleapis.com/auth/cloud-platform' );

		$this->assertInstanceOf( WP_Error::class, $token );
		$this->assertSame( 'Google did not accept the service account. Invalid JWT Signature.', $token->get_error_message() );
	}

	public function test_only_administrators_manage_google_and_ai_and_seo_editors_generate(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/barrio/v1/google' )->get_status() );
		$this->assertSame( 403, $this->request( 'PUT', '/barrio/v1/ai', [ 'model' => self::MODEL ] )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/barrio/v1/ai/models' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/barrio/v1/ai/locations' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/barrio/v1/seo/generate/llms' )->get_status() );
		// Un editor ha barrio_edit_seo: senza configurazione l'errore dice cosa manca, non che non può.
		$this->assertSame( 'barrio_ai_setup', $this->request( 'POST', '/barrio/v1/seo/generate' )->as_error()->get_error_code() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertSame( 403, $this->request( 'POST', '/barrio/v1/seo/generate' )->get_status() );
		$this->assertSame( [], $this->requests );
	}

	public function test_the_ai_is_ready_with_the_service_account_and_a_model(): void {
		$ready = static fn(): array => [ Ai::is_ready(), Editor::context( 'post_types', 'page' )['ai'], Admin::data( [] )['seo']['ai'] ];
		$this->assertSame( [ false, false, false ], $ready() );

		$this->request( 'PUT', '/barrio/v1/ai', [ 'model' => self::MODEL ] );
		$this->assertSame( [ false, false, false ], $ready(), 'A model without the service account.' );

		$this->request( 'PUT', '/barrio/v1/google', [ 'service_account' => $this->file() ] );
		$this->assertSame( [ true, true, true ], $ready() );
	}

	public function test_a_model_or_a_region_that_is_not_a_google_name_is_rejected(): void {
		$this->assertSame( 400, $this->request( 'PUT', '/barrio/v1/ai', [ 'model' => 'publishers/google/models/../x' ] )->get_status() );
		$this->assertSame( 400, $this->request( 'PUT', '/barrio/v1/ai', [ 'model' => 'gemini-test' ] )->get_status() );
		// La regione finisce nel nome dell'host.
		$this->assertSame( 400, $this->request( 'PUT', '/barrio/v1/ai', [ 'location' => 'example.com/x' ] )->get_status() );
		$this->assertSame( [ '', '' ], [ Ai::settings()['model'], Ai::settings()['location'] ] );
	}

	public function test_the_regions_are_those_of_vertex_ai_for_the_project(): void {
		$this->request( 'PUT', '/barrio/v1/google', [ 'service_account' => $this->file() ] );
		$this->responses[] = [
			'code' => 200,
			'body' => [
				'locations' => [
					[
						'locationId'  => 'europe-west8',
						'displayName' => 'Milan',
					],
					// L'endpoint globale è la scelta "Automatica", già nella tendina.
					[ 'locationId' => 'global' ],
					[
						'locationId'  => 'us-central1',
						'displayName' => 'Iowa',
					],
				],
			],
		];

		$this->assertSame(
			[
				[
					'value' => 'us-central1',
					'label' => 'Iowa (us-central1)',
				],
				[
					'value' => 'europe-west8',
					'label' => 'Milan (europe-west8)',
				],
			],
			$this->request( 'GET', '/barrio/v1/ai/locations' )->get_data()
		);
		$this->assertSame( 'https://aiplatform.googleapis.com/v1/projects/hotel-lago/locations?pageSize=100', $this->requests[0]['url'] );
	}

	public function test_with_a_region_the_requests_go_to_its_endpoint(): void {
		$this->configure();
		$this->request( 'PUT', '/barrio/v1/ai', [ 'location' => 'europe-west8' ] );
		$this->responses[] = $this->answer( '{"title":"Camere","description":"","keywords":[]}' );

		$this->request( 'POST', '/barrio/v1/seo/generate' );

		$this->assertSame( 'https://europe-west8-aiplatform.googleapis.com/v1/projects/hotel-lago/locations/europe-west8/publishers/google/models/gemini-test:generateContent', $this->requests[0]['url'] );
	}

	public function test_the_models_are_those_of_google_in_vertex_ai(): void {
		$this->request( 'PUT', '/barrio/v1/google', [ 'service_account' => $this->file() ] );
		$this->responses = [
			[
				'code' => 200,
				'body' => [
					'publisherModels' => [
						[ 'name' => 'publishers/google/models/gemini-b' ],
						[ 'name' => 'publishers/other/models/x' ],
					],
					'nextPageToken'   => 'two',
				],
			],
			[
				'code' => 200,
				'body' => [ 'publisherModels' => [ [ 'name' => 'publishers/google/models/gemini-a' ] ] ],
			],
		];

		$models = $this->request( 'GET', '/barrio/v1/ai/models' )->get_data();

		$this->assertSame(
			[
				[
					'value' => 'publishers/google/models/gemini-a',
					'label' => 'gemini-a',
				],
				[
					'value' => 'publishers/google/models/gemini-b',
					'label' => 'gemini-b',
				],
			],
			$models
		);
		$this->assertSame(
			[ 'https://aiplatform.googleapis.com/v1beta1/publishers/google/models?pageSize=100', 'https://aiplatform.googleapis.com/v1beta1/publishers/google/models?pageSize=100&pageToken=two' ],
			array_column( $this->requests, 'url' )
		);
		$this->assertSame( 'Bearer ya29.test', $this->requests[0]['auth'] );
	}

	public function test_the_seo_of_a_page_is_generated_from_its_text(): void {
		$this->configure();
		$this->responses[] = $this->answer(
			(string) wp_json_encode(
				[
					'title'       => 'Camere vista lago — Hotel Lago',
					'description' => 'Camere sul lago con colazione.',
					'keywords'    => [ 'camere lago', 'camere lago', '', 'hotel lago' ],
				]
			)
		);

		$response = $this->request(
			'POST',
			'/barrio/v1/seo/generate',
			[
				'lang'  => 'en',
				'title' => 'Camere &amp; suite',
				'url'   => 'https://example.com/camere/',
				'text'  => "<!-- wp:paragraph --><p>Vista sul lago.</p><!-- /wp:paragraph -->\n\n[barrio_form id=1]",
			]
		);

		$this->assertSame(
			[
				// Il nome del sito lo aggiunge il <title>.
				'title'       => 'Camere vista lago',
				'description' => 'Camere sul lago con colazione.',
				'keywords'    => [ 'camere lago', 'hotel lago' ],
			],
			$response->get_data()
		);
		$request = $this->requests[0];
		$this->assertSame( 'https://aiplatform.googleapis.com/v1/projects/hotel-lago/locations/global/publishers/google/models/gemini-test:generateContent', $request['url'] );
		$this->assertSame( 'application/json', $request['body']['generationConfig']['responseMimeType'] );
		$prompt = $request['body']['contents'][0]['parts'][0]['text'];
		$this->assertStringContainsString( 'locale en_US', $prompt );
		// 60 caratteri meno " — Hotel Lago".
		$this->assertStringContainsString( 'at most 47 characters', $prompt );
		$this->assertStringContainsString( "Title: Camere & suite\nURL: https://example.com/camere/\nText:\nVista sul lago.", $prompt );
		$this->assertStringNotContainsString( 'barrio_form', $prompt );
		$this->assertStringEndsWith( "About the site, from its owner:\nFamily hotel, warm tone.", $prompt );
	}

	public function test_a_title_that_only_ends_with_the_site_name_is_kept(): void {
		update_option( 'blogname', 'Lago' );
		$this->configure();
		$this->responses[] = $this->answer( '{"title":"Vacanze sul Lago","description":"","keywords":[]}' );

		$this->assertSame( 'Vacanze sul Lago', $this->request( 'POST', '/barrio/v1/seo/generate' )->get_data()['title'] );
	}

	public function test_errors_of_google_are_reported(): void {
		$this->configure();
		$this->responses = [
			[
				'code' => 403,
				'body' => [ 'error' => [ 'message' => 'Vertex AI API has not been used in project hotel-lago.' ] ],
			],
			[
				'code' => 200,
				'body' => [ 'candidates' => [ [ 'finishReason' => 'SAFETY' ] ] ],
			],
			$this->answer( 'not json' ),
		];

		$disabled = $this->request( 'POST', '/barrio/v1/seo/generate' );
		$this->assertSame( 502, $disabled->get_status() );
		$this->assertSame( 'Google answered with error 403. Vertex AI API has not been used in project hotel-lago.', $disabled->as_error()->get_error_message() );
		$this->assertStringContainsString( '(SAFETY)', $this->request( 'POST', '/barrio/v1/seo/generate' )->as_error()->get_error_message() );
		$this->assertSame( 'barrio_ai_json', $this->request( 'POST', '/barrio/v1/seo/generate' )->as_error()->get_error_code() );
	}

	public function test_the_llms_introduction_is_written_from_the_llms_file(): void {
		$this->configure();
		self::factory()->post->create(
			[
				'post_title'  => 'Le nostre camere',
				'post_status' => 'publish',
			]
		);
		$this->responses[] = [
			'code' => 200,
			'body' => [
				'candidates' => [
					[
						'content' => [
							'parts' => [
								// I pensieri dei modelli che ragionano non sono la risposta.
								[
									'text'    => 'Thinking…',
									'thought' => true,
								],
								[ 'text' => "Hotel Lago is a family hotel.\n" ],
							],
						],
					],
				],
			],
		];

		$response = $this->request( 'POST', '/barrio/v1/seo/generate/llms', [ 'lang' => 'en' ] );

		$this->assertSame( [ 'text' => 'Hotel Lago is a family hotel.' ], $response->get_data() );
		$prompt = $this->requests[0]['body']['contents'][0]['parts'][0]['text'];
		$this->assertStringContainsString( "The llms.txt file now:\n# Hotel Lago", $prompt );
		$this->assertStringContainsString( 'Le nostre camere', $prompt );
		$this->assertArrayNotHasKey( 'generationConfig', $this->requests[0]['body'] );
	}

	private function configure(): void {
		$this->request( 'PUT', '/barrio/v1/google', [ 'service_account' => $this->file() ] );
		$this->request(
			'PUT',
			'/barrio/v1/ai',
			[
				'model'        => self::MODEL,
				'instructions' => 'Family hotel, warm tone.',
			]
		);
	}

	/** @param array<string, string> $changes */
	private function file( array $changes = [] ): string {
		return (string) wp_json_encode(
			$changes + [
				'type'         => 'service_account',
				'project_id'   => 'hotel-lago',
				'client_email' => 'barrio@hotel-lago.iam.gserviceaccount.com',
				'private_key'  => self::$private_key,
				'token_uri'    => 'https://oauth2.googleapis.com/token',
			]
		);
	}

	/** @return array{code: int, body: array<string, mixed>} */
	private function answer( string $text ): array {
		return [
			'code' => 200,
			'body' => [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => $text ] ] ] ] ] ],
		];
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function response( int $code, array $body ): array {
		return [
			'headers'  => [],
			'body'     => (string) wp_json_encode( $body ),
			'response' => [ 'code' => $code ],
			'cookies'  => [],
		];
	}

	/** @param array<string, mixed> $body */
	private function request( string $method, string $path, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}
}
