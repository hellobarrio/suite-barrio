<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Alt\Module;
use BarrioSuite\Crypto;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Il modulo Testi alternativi, con Google simulato su pre_http_request (la firma del token la prova AiTest): l'immagine
// che parte, cosa si scrive e chi può generare.
final class AltTest extends WP_UnitTestCase {

	private static string $private_key = '';

	/** @var list<array<string, mixed>> I corpi delle richieste a Vertex AI. */
	private array $requests = [];

	/** @var list<string> Le risposte del modello, in ordine; finite, la stessa. */
	private array $answers = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		openssl_pkey_export( openssl_pkey_new( [ 'private_key_bits' => 2048 ] ), self::$private_key );
	}

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$account = [
			'project_id'   => 'hotel-lago',
			'client_email' => 'barrio@hotel-lago.iam.gserviceaccount.com',
			'private_key'  => self::$private_key,
		];
		update_option( 'barrio_google', Crypto::encrypt( (string) wp_json_encode( $account ) ) );
		update_option( 'barrio_ai', [ 'model' => 'publishers/google/models/gemini-test' ] );
		add_filter( 'pre_http_request', [ $this, 'google' ], 10, 3 );
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		parent::tear_down();
		// I ruoli restano in memoria anche dopo il rollback: si rileggono dal database, non dalla cache.
		self::flush_cache();
		wp_roles()->for_site();
	}

	/** @param array<string, mixed> $args */
	public function google( mixed $pre, array $args, string $url ): array {
		$body = [
			'access_token' => 'ya29.test',
			'expires_in'   => 3599,
		];
		if ( 'https://oauth2.googleapis.com/token' !== $url ) {
			$this->requests[] = json_decode( $args['body'], true );
			$text             = array_shift( $this->answers ) ?? '{"alt":"Un lago tra le montagne"}';
			$body             = [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => $text ] ] ] ] ] ];
		}
		return [
			'headers'  => [],
			'body'     => (string) wp_json_encode( $body ),
			'response' => [ 'code' => 200 ],
			'cookies'  => [],
		];
	}

	public function test_the_large_size_goes_to_google_and_the_button_does_not_save(): void {
		$parent = self::factory()->post->create( [ 'post_title' => 'Vacanze in montagna' ] );
		$image  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image-large.jpg', $parent );
		$large  = image_get_intermediate_size( $image, 'large' );
		$this->assertIsArray( $large );

		$response = $this->request( 'POST', "/barrio/v1/alt/{$image}" );

		$this->assertSame(
			[
				'alt'   => 'Un lago tra le montagne',
				'title' => '',
			],
			$response->get_data()
		);
		$this->assertSame( '', get_post_meta( $image, '_wp_attachment_image_alt', true ) );
		[ $picture, $text ] = $this->requests[0]['contents'][0]['parts'];
		$this->assertSame( 'image/jpeg', $picture['inlineData']['mimeType'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( base64_encode( (string) file_get_contents( path_join( dirname( (string) get_attached_file( $image ) ), $large['file'] ) ) ), $picture['inlineData']['data'] );
		$this->assertStringContainsString( 'locale en_US', $text['text'] );
		$this->assertStringContainsString( 'It is used in the content: Vacanze in montagna', $text['text'] );
		$this->assertSame( [ 'alt' ], $this->requests[0]['generationConfig']['responseSchema']['required'] );
	}

	public function test_saving_writes_only_an_empty_alt(): void {
		$image = $this->image( 'canola.jpg' );
		$typed = $this->image( 'canola.jpg' );
		update_post_meta( $typed, '_wp_attachment_image_alt', 'Scritto a mano' );

		$this->request( 'POST', "/barrio/v1/alt/{$image}", [ 'save' => true ] );
		$this->request( 'POST', "/barrio/v1/alt/{$typed}", [ 'save' => true ] );

		$this->assertSame( 'Un lago tra le montagne', get_post_meta( $image, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Scritto a mano', get_post_meta( $typed, '_wp_attachment_image_alt', true ) );
	}

	public function test_a_small_image_goes_as_it_is_other_formats_as_jpeg_and_svg_not_at_all(): void {
		$small = $this->image( 'canola.jpg' );
		$gif   = $this->image( 'test-image.gif' );
		$svg   = self::factory()->attachment->create_object(
			'logo.svg',
			0,
			[ 'post_mime_type' => 'image/svg+xml' ]
		);

		$this->request( 'POST', "/barrio/v1/alt/{$small}" );
		$this->request( 'POST', "/barrio/v1/alt/{$gif}" );

		$this->assertFalse( image_get_intermediate_size( $small, 'large' ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( base64_encode( (string) file_get_contents( (string) get_attached_file( $small ) ) ), $this->requests[0]['contents'][0]['parts'][0]['inlineData']['data'] );
		$jpeg = $this->requests[1]['contents'][0]['parts'][0]['inlineData'];
		$this->assertSame( 'image/jpeg', $jpeg['mimeType'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertSame( 'image/jpeg', getimagesizefromstring( base64_decode( $jpeg['data'] ) )['mime'] );
		$this->assertSame( 'barrio_alt_format', $this->request( 'POST', "/barrio/v1/alt/{$svg}" )->as_error()->get_error_code() );
		$this->assertCount( 2, $this->requests );
	}

	public function test_after_the_upload_a_cron_event_writes_the_alt_if_it_is_still_empty(): void {
		$off = $this->image( 'canola.jpg' );
		$this->assertFalse( wp_next_scheduled( 'barrio_alt_generate', [ $off ] ) );

		update_option( 'barrio_alt', [ 'on_upload' => true ] );
		$image = $this->image( 'canola.jpg' );
		$typed = $this->image( 'canola.jpg' );

		// L'upload non aspetta Google.
		$this->assertSame( [], $this->requests );
		$this->assertIsInt( wp_next_scheduled( 'barrio_alt_generate', [ $image ] ) );
		update_post_meta( $typed, '_wp_attachment_image_alt', 'Scritto mentre si aspettava' );
		do_action( 'barrio_alt_generate', $image );
		do_action( 'barrio_alt_generate', $typed );
		$this->assertSame( 'Un lago tra le montagne', get_post_meta( $image, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Scritto mentre si aspettava', get_post_meta( $typed, '_wp_attachment_image_alt', true ) );

		Module::deactivate();
		$this->assertFalse( wp_next_scheduled( 'barrio_alt_generate', [ $typed ] ) );
	}

	public function test_the_title_is_generated_on_request_and_replaces_only_the_file_name(): void {
		update_option( 'barrio_alt', [ 'title' => true ] );
		$this->answers = array_fill( 0, 2, '{"alt":"Colza in fiore","title":"Campo di colza"}' );
		$image         = $this->image( 'canola.jpg' );
		$named         = $this->image( 'canola.jpg' );
		// Il titolo che dà WordPress caricando canola.jpg, anche quando il file diventa canola-2.jpg.
		wp_update_post(
			[
				'ID'         => $image,
				'post_title' => 'canola',
			]
		);
		wp_update_post(
			[
				'ID'         => $named,
				'post_title' => 'Il campo della fattoria',
			]
		);

		$this->request( 'POST', "/barrio/v1/alt/{$image}", [ 'save' => true ] );
		$this->request( 'POST', "/barrio/v1/alt/{$named}", [ 'save' => true ] );

		$this->assertSame( 'Campo di colza', get_the_title( $image ) );
		$this->assertSame( 'Il campo della fattoria', get_the_title( $named ) );
		$this->assertSame( [ 'alt', 'title' ], $this->requests[0]['generationConfig']['responseSchema']['required'] );
	}

	public function test_only_who_can_edit_an_image_generates_and_only_administrators_change_the_settings(): void {
		$others = $this->image( 'canola.jpg' );
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author );
		$own = $this->image( 'canola.jpg' );
		$this->assertTrue( current_user_can( 'barrio_generate_alt' ), 'From migration 0001: whoever uploads files.' );

		$this->assertSame( 403, $this->request( 'POST', "/barrio/v1/alt/{$others}", [ 'save' => true ] )->get_status() );
		$this->assertSame( 200, $this->request( 'POST', "/barrio/v1/alt/{$own}" )->get_status() );
		$rows = array_column( $this->request( 'GET', '/barrio/v1/alt/images' )->get_data()['rows'], 'can', 'id' );
		$this->assertSame( [ $own => true ], array_filter( $rows ) );
		$this->assertFalse( $rows[ $others ] );
		$this->assertSame( [ $own ], $this->request( 'GET', '/barrio/v1/alt/images', [ 'fields' => 'ids' ] )->get_data()['ids'] );
		$this->assertSame( 403, $this->request( 'PUT', '/barrio/v1/alt/settings', [ 'on_upload' => true ] )->get_status() );
		$this->assertStringNotContainsString( 'barrio-alt-generate', $this->button( $others ) );
		$this->assertStringContainsString( 'barrio-alt-generate', $this->button( $own ) );
		$this->assertSame( '', get_post_meta( $others, '_wp_attachment_image_alt', true ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'contributor' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/barrio/v1/alt/images' )->get_status() );
		$this->assertArrayNotHasKey( 'alt', Module::admin_data( [] ) );
	}

	public function test_only_the_roles_chosen_in_the_permissions_generate(): void {
		$settings = $this->request( 'GET', '/barrio/v1/alt/settings' )->get_data();
		$this->assertSame( [ 'barrio_generate_alt' ], $settings['roles']['author'] );
		$this->assertSame( [], $settings['roles']['contributor'] );
		$this->assertArrayNotHasKey( 'administrator', $settings['roles'] );
		$this->assertSame( 400, $this->request( 'PUT', '/barrio/v1/alt/settings', [ 'roles' => [ 'author' => [ 'upload_files' ] ] ] )->get_status() );

		$this->request( 'PUT', '/barrio/v1/alt/settings', [ 'roles' => [ 'author' => [] ] ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$own = $this->image( 'canola.jpg' );

		$this->assertSame( 403, $this->request( 'GET', '/barrio/v1/alt/images' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', "/barrio/v1/alt/{$own}" )->get_status() );
		$this->assertArrayNotHasKey( 'alt', Module::admin_data( [] ) );
		$this->assertStringNotContainsString( 'barrio-alt-generate', $this->button( $own ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'barrio_generate_alt' ), 'Administrators keep everything.' );
	}

	public function test_in_the_edit_screen_the_button_is_after_the_fields_of_wordpress(): void {
		$image = $this->image( 'canola.jpg' );
		$post  = self::factory()->post->create();

		ob_start();
		Module::edit_screen( get_post( $image ) );
		Module::edit_screen( get_post( $post ) );
		$html = (string) ob_get_clean();

		$this->assertSame( 1, substr_count( $html, 'barrio-alt-generate' ) );
		$this->assertStringContainsString( 'width: 100%', $html );
		$this->assertStringNotContainsString( 'barrio-alt-generate', (string) get_compat_media_markup( $image )['item'], 'Not in the narrow table of the fields of the plugins.' );
	}

	public function test_the_list_shows_the_images_without_alt_with_search_and_counts(): void {
		$lake   = self::factory()->attachment->create_object(
			'lago.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Lago al tramonto',
			]
		);
		$forest = self::factory()->attachment->create_object(
			'bosco.jpg',
			0,
			[
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Bosco',
			]
		);
		$done   = self::factory()->attachment->create_object( 'fatto.jpg', 0, [ 'post_mime_type' => 'image/jpeg' ] );
		update_post_meta( $done, '_wp_attachment_image_alt', 'Una barca' );
		update_post_meta( $forest, '_wp_attachment_image_alt', '' );
		self::factory()->attachment->create_object( 'menu.pdf', 0, [ 'post_mime_type' => 'application/pdf' ] );

		$missing = $this->request( 'GET', '/barrio/v1/alt/images', [ 'alt' => 'missing' ] )->get_data();
		$search  = $this->request(
			'GET',
			'/barrio/v1/alt/images',
			[
				'alt'    => 'missing',
				'search' => 'tramonto',
				'sort'   => 'title',
				'order'  => 'asc',
			]
		)->get_data();

		$this->assertEqualsCanonicalizing( [ $forest, $lake ], array_column( $missing['rows'], 'id' ) );
		$this->assertSame(
			[
				'all'     => 3,
				'missing' => 2,
			],
			$missing['counts']
		);
		$this->assertSame( [ $lake ], array_column( $search['rows'], 'id' ) );
		$this->assertSame( 1, $search['total'] );
		$this->assertSame( 400, $this->request( 'GET', '/barrio/v1/alt/images', [ 'sort' => 'alt' ] )->get_status() );
	}

	public function test_the_settings_are_validated_and_a_missing_value_stays_as_it_is(): void {
		$this->assertSame( 400, $this->request( 'PUT', '/barrio/v1/alt/settings', [ 'on_upload' => 'sometimes' ] )->get_status() );
		$this->request( 'PUT', '/barrio/v1/alt/settings', [ 'title' => true ] );
		$this->request( 'PUT', '/barrio/v1/alt/settings', [ 'on_upload' => true ] );

		$this->assertSame(
			[
				'on_upload' => true,
				'title'     => true,
			],
			array_intersect_key( $this->request( 'GET', '/barrio/v1/alt/settings' )->get_data(), array_flip( [ 'on_upload', 'title' ] ) )
		);
	}

	public function test_without_the_ai_set_up_the_button_is_disabled_with_the_reason(): void {
		$image = $this->image( 'canola.jpg' );
		$this->assertStringNotContainsString( 'disabled', $this->button( $image ) );

		delete_option( 'barrio_ai' );

		$this->assertStringContainsString( "disabled='disabled'", $this->button( $image ) );
		$this->assertStringContainsString( 'Barrio → Settings', $this->button( $image ) );
		$this->assertTrue( wp_script_is( 'barrio-alt' ) );
		$this->assertSame( 'barrio_ai_setup', $this->request( 'POST', "/barrio/v1/alt/{$image}" )->as_error()->get_error_code() );
	}

	private function image( string $file ): int {
		return self::factory()->attachment->create_upload_object( DIR_TESTDATA . "/images/{$file}" );
	}

	// Il campo "Genera con AI" nei dettagli dell'immagine, come lo stampa la finestra dei media.
	private function button( int $image ): string {
		return (string) ( get_compat_media_markup( $image, [ 'in_modal' => true ] )['item'] ?? '' );
	}

	/** @param array<string, mixed> $params */
	private function request( string $method, string $path, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		return rest_do_request( $request );
	}
}
