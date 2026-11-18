<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Modules;
use BarrioSuite\Plugin;
use WP_Hook;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Il modulo Prestazioni: formato delle immagini, caricamento speculativo, head più leggero. Spento, il core com'è.
final class PerformanceTest extends WP_UnitTestCase {

	/** @var array<string, WP_Hook> */
	private array $hooks;

	/** @var array{\WP_Styles|null, \WP_Scripts|null} */
	private array $assets;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->hooks  = array_map( static fn( WP_Hook $hook ): WP_Hook => clone $hook, $GLOBALS['wp_filter'] );
		$this->assets = [ $GLOBALS['wp_styles'] ?? null, $GLOBALS['wp_scripts'] ?? null ];
		$this->set_permalink_structure( '/%postname%/' );
	}

	public function tear_down(): void {
		$this->remove_added_uploads();
		unset( $GLOBALS['show_admin_bar'] );
		[ $GLOBALS['wp_styles'], $GLOBALS['wp_scripts'] ] = $this->assets; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$GLOBALS['wp_filter']      = $this->hooks; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- gli hook di prima del test.
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		parent::tear_down();
	}

	public function test_only_what_changes_no_files_or_behaviour_is_on_by_default(): void {
		$data = rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/performance' ) )->get_data();

		$this->assertSame(
			[
				'image_format'          => '',
				'speculation_mode'      => 'auto',
				'speculation_eagerness' => 'auto',
				'emoji'                 => true,
				'generator'             => true,
				'rsd'                   => true,
				'shortlink'             => true,
				'dashicons'             => false,
			],
			array_diff_key( $data, [ 'formats' => true ] )
		);
		$this->assertContains( 'image/webp', $data['formats'], 'The image editor of the test server writes WebP.' );
		$this->assertSame( array_diff_key( $data, [ 'formats' => true ] ), apply_filters( 'barrio_admin_data', [] )['performance'] ?? null, 'For the card of the Dashboard.' );
	}

	public function test_settings_are_validated_and_an_absent_one_stays(): void {
		$this->assertSame( 400, $this->put( [ 'image_format' => 'image/gif' ] )->get_status() );
		$this->assertSame( 400, $this->put( [ 'speculation_eagerness' => 'immediate' ] )->get_status() );

		$this->put( [ 'speculation_mode' => 'prerender' ] );
		$data = $this->put( [ 'rsd' => false ] )->get_data();
		$this->assertSame( 'prerender', $data['speculation_mode'] );
		$this->assertFalse( $data['rsd'] );
		$this->assertTrue( $data['generator'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/performance' ) )->get_status() );
		$this->assertSame( 403, $this->put( [ 'rsd' => true ] )->get_status() );
	}

	public function test_the_head_has_no_emoji_generator_rsd_or_shortlink(): void {
		$post = self::factory()->post->create();
		$this->boot();
		$head = $this->head( (string) get_permalink( $post ) );

		$this->assertStringNotContainsString( 'wp-smiley', $head );
		$this->assertFalse( has_action( 'wp_head', 'print_emoji_detection_script' ), 'The script, which the core prints in the footer.' );
		$this->assertStringNotContainsString( 'name="generator"', $head );
		$this->assertStringNotContainsString( 'EditURI', $head );
		$this->assertStringNotContainsString( 'shortlink', $head );
		$this->assertFalse( has_action( 'template_redirect', 'wp_shortlink_header' ), 'Not even as an HTTP header.' );
	}

	public function test_dashicons_go_only_for_those_without_the_admin_bar(): void {
		$this->put( [ 'dashicons' => true ] );
		$this->boot();
		// Come un tema che le usa.
		add_action( 'wp_enqueue_scripts', static fn() => wp_enqueue_style( 'dashicons' ) );

		show_admin_bar( false );
		$this->head( home_url( '/' ) );
		$this->assertFalse( wp_style_is( 'dashicons' ) );

		show_admin_bar( true );
		$this->head( home_url( '/' ) );
		$this->assertTrue( wp_style_is( 'dashicons' ) );
	}

	public function test_new_jpeg_sizes_are_webp_and_the_original_stays(): void {
		$this->put( [ 'image_format' => 'image/webp' ] );
		$this->boot();
		$source = DIR_TESTDATA . '/images/canola.jpg';
		$image  = self::factory()->attachment->create_upload_object( $source );
		$meta   = wp_get_attachment_metadata( $image );

		$this->assertIsArray( $meta );
		$this->assertStringEndsWith( '.webp', $meta['file'] );
		$this->assertNotEmpty( $meta['sizes'] );
		$this->assertSame( [ 'image/webp' ], array_values( array_unique( array_column( $meta['sizes'], 'mime-type' ) ) ) );
		$this->assertSame( md5_file( $source ), md5_file( (string) wp_get_original_image_path( $image ) ), 'The uploaded JPEG is intact.' );

		// I PNG restano PNG: loghi e grafiche.
		$png = wp_get_attachment_metadata( self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.png' ) );
		$this->assertIsArray( $png );
		$this->assertStringEndsWith( '.png', $png['file'] );
	}

	public function test_speculative_loading_has_the_chosen_mode_and_the_core_exclusions(): void {
		wp_set_current_user( 0 );
		$this->assertSame(
			[
				'mode'      => 'prefetch',
				'eagerness' => 'conservative',
			],
			wp_get_speculation_rules_configuration(),
			'auto is the choice of the core.'
		);

		update_option(
			'barrio_performance',
			[
				'speculation_mode'      => 'prerender',
				'speculation_eagerness' => 'moderate',
			]
		);
		$this->assertSame(
			[
				'mode'      => 'prerender',
				'eagerness' => 'moderate',
			],
			wp_get_speculation_rules_configuration()
		);
		$rules = (string) wp_json_encode( wp_get_speculation_rules() );
		$this->assertStringContainsString( '"prerender"', $rules );
		$this->assertStringContainsString( '\/wp-admin\/*', $rules );
		// wp-login.php?action=logout, e con i permalink ogni URL con una query (add-to-cart…).
		$this->assertStringContainsString( '\/wp-*.php', $rules );
		$this->assertStringContainsString( '\/*\\\\?(.+)', $rules );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertNull( wp_get_speculation_rules_configuration(), 'Off for those who are logged in, as in the core.' );
	}

	public function test_turned_off_everything_is_as_in_the_core(): void {
		$this->put(
			[
				'image_format'     => 'image/webp',
				'speculation_mode' => 'prerender',
				'dashicons'        => true,
			]
		);
		Modules::set( 'performance', false );
		$this->boot();
		$this->assertArrayNotHasKey( 'performance', apply_filters( 'barrio_admin_data', [] ), 'No page, no card in the Dashboard.' );
		$post = self::factory()->post->create();
		add_action( 'wp_enqueue_scripts', static fn() => wp_enqueue_style( 'dashicons' ) );
		wp_set_current_user( 0 );
		show_admin_bar( false );

		$head = $this->head( (string) get_permalink( $post ) );
		foreach ( [ 'wp-smiley', 'name="generator"', 'EditURI', 'shortlink' ] as $tag ) {
			$this->assertStringContainsString( $tag, $head );
		}
		$this->assertTrue( wp_style_is( 'dashicons' ) );
		$this->assertSame( 'prefetch', wp_get_speculation_rules_configuration()['mode'] ?? null );
		$meta = wp_get_attachment_metadata( self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' ) );
		$this->assertIsArray( $meta );
		$this->assertStringEndsWith( '.jpg', $meta['file'] );
	}

	/** @param array<string, mixed> $body */
	private function put( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', '/barrio/v1/performance' );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	// Come all'inizio di una richiesta: gli hook del core, poi il plugin, che li sceglie dalle impostazioni salvate.
	private function boot(): void {
		$GLOBALS['wp_filter']      = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		require ABSPATH . WPINC . '/default-filters.php';
		Plugin::boot();
	}

	// Con stili e script da capo, come in una richiesta nuova.
	private function head( string $url ): string {
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->go_to( $url );
		ob_start();
		wp_head();
		return (string) ob_get_clean();
	}
}
