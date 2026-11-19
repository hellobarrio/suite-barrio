<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Blocks\Module;
use BarrioSuite\Modules;
use BarrioSuite\Plugin;
use WP_Block_Editor_Context;
use WP_Hook;
use WP_REST_Request;
use WP_REST_Response;
use WP_Theme_JSON_Resolver;
use WP_UnitTestCase;
use WP_User;

// Il modulo Blocchi: cambia i controlli dell'editor ruolo per ruolo, mai i contenuti né il sito.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- hook di WordPress.
final class BlocksTest extends WP_UnitTestCase {

	/** @var array<string, WP_Hook> */
	private array $hooks;

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		$this->hooks = array_map( static fn( WP_Hook $hook ): WP_Hook => clone $hook, $GLOBALS['wp_filter'] );
	}

	public function tear_down(): void {
		$GLOBALS['wp_filter']      = $this->hooks; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- gli hook di prima del test.
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		add_post_type_support( 'post', 'editor' );
		wp_dequeue_script( 'barrio-blocks-no-h1' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_without_choices_the_editor_is_the_one_of_wordpress(): void {
		$data = $this->request( 'GET', '/blocks' )->get_data();
		$this->assertEquals(
			[
				'roles'           => (object) [],
				'editors'         => (object) [],
				'remote_patterns' => true,
				'openverse'       => true,
			],
			array_diff_key( $data, [ 'available' => 0 ] )
		);

		$this->as( 'editor' );
		set_current_screen( 'post' );
		$this->assertTrue( $this->allowed() );
		// Senza, valgono quelle dell'editor: tutto acceso.
		$this->assertSame( [], array_intersect_key( get_block_editor_settings( [], $this->context() ), array_flip( [ 'codeEditingEnabled', 'canLockBlocks', 'enableOpenverseMediaCategory' ] ) ) );
		$this->assertTrue( use_block_editor_for_post_type( 'page' ) );
		$this->assertTrue( $this->global_setting( 'color', 'custom' ) );
		$this->assertArrayHasKey( 'revisions', $this->supports( 'page' ) );
		do_action( 'enqueue_block_editor_assets' );
		$this->assertFalse( wp_script_is( 'barrio-blocks-no-h1' ) );
		$this->assertTrue( apply_filters( 'should_load_remote_block_patterns', true ) );
	}

	public function test_an_editor_does_not_find_the_disabled_blocks_but_a_list_keeps_its_items(): void {
		$available = $this->request( 'GET', '/blocks' )->get_data()['available'];
		$names     = array_column( $available['blocks'], 'name' );
		$this->assertContains( 'core/list', $names );
		$this->assertNotContains( 'core/list-item', $names, 'A block that exists only inside another follows it.' );
		$this->assertNotContains( 'core/missing', $names, 'A block that cannot be inserted is there to open the contents.' );
		$this->assertContains( 'text', array_column( $available['categories'], 'slug' ) );
		$this->assertSame( 'Administrator', array_values( $available['roles'] )[0], 'First, for the settings of every role.' );
		$this->assertSame( 'Editor', $available['roles']['editor'] );
		$this->assertSame(
			[
				[
					'name'   => 'post',
					'label'  => 'Posts',
					'blocks' => true,
				],
				[
					'name'   => 'page',
					'label'  => 'Pages',
					'blocks' => true,
				],
			],
			$available['types'],
			'Not the types of WordPress made for the block editor (patterns, navigation menus).'
		);

		$this->put( [ 'roles' => [ 'editor' => $this->role( [ 'disabled' => [ 'core/list', 'core/quote' ] ] ) ] ] );

		$this->as( 'editor' );
		$allowed = $this->allowed();
		$this->assertIsArray( $allowed );
		$this->assertNotContains( 'core/list', $allowed );
		$this->assertNotContains( 'core/quote', $allowed );
		$this->assertContains( 'core/paragraph', $allowed );
		$this->assertContains( 'core/list-item', $allowed, 'A list kept by another role still has its items.' );

		$this->as( 'author' );
		$this->assertTrue( $this->allowed(), 'Other roles have everything.' );
		wp_set_current_user( $this->admin );
		$this->assertTrue( $this->allowed() );

		// Come le capability, i ruoli si sommano: vale ciò che tutti tolgono.
		add_role( 'shop', 'Shop', [ 'edit_posts' => true ] );
		$this->put(
			[
				'roles' => [
					'editor' => $this->role( [ 'disabled' => [ 'core/list', 'core/quote' ] ] ),
					'shop'   => $this->role( [ 'disabled' => [ 'core/quote' ] ] ),
				],
			]
		);
		$user = $this->as( 'editor' );
		$user->add_role( 'shop' );
		$allowed = $this->allowed();
		$this->assertIsArray( $allowed );
		$this->assertContains( 'core/list', $allowed );
		$this->assertNotContains( 'core/quote', $allowed );
		$user->add_role( 'author' );
		$this->assertTrue( $this->allowed() );
		remove_role( 'shop' );
	}

	public function test_restrictions_change_the_controls_and_not_the_contents(): void {
		$all = [
			'h1'     => true,
			'styles' => true,
			'code'   => true,
			'lock'   => true,
			'panels' => true,
		];
		$this->put( [ 'roles' => [ 'editor' => $this->role( $all ) ] ] );

		$this->as( 'editor' );
		set_current_screen( 'post' );
		$settings = get_block_editor_settings( [], $this->context() );
		$this->assertFalse( $settings['codeEditingEnabled'] );
		$this->assertFalse( $settings['canLockBlocks'] );
		do_action( 'enqueue_block_editor_assets' );
		$this->assertTrue( wp_script_is( 'barrio-blocks-no-h1' ), 'The heading levels, in the editor.' );

		// Le impostazioni del tema, senza quelle personalizzate: la palette resta.
		$this->assertFalse( $this->global_setting( 'color', 'custom' ) );
		$this->assertFalse( $this->global_setting( 'typography', 'customFontSize' ) );
		$this->assertFalse( $this->global_setting( 'typography', 'lineHeight' ) );
		$this->assertNotEmpty( $this->global_setting( 'color', 'palette' ) );
		wp_set_current_user( $this->admin );
		$this->assertTrue( $this->global_setting( 'color', 'custom' ), 'Not for the administrator in the same request.' );
		$this->as( 'editor' );
		set_current_screen( 'front' );
		$this->assertTrue( $this->global_setting( 'color', 'custom' ), 'Not on the site.' );

		$supports = $this->supports( 'page' );
		$this->assertArrayNotHasKey( 'author', $supports );
		$this->assertArrayNotHasKey( 'revisions', $supports );
		$this->assertArrayNotHasKey( 'page-attributes', $supports );
		$this->assertArrayHasKey( 'editor', $supports );
		$this->assertTrue( post_type_supports( 'page', 'revisions' ), 'Revisions are still saved.' );

		$this->as( 'author' );
		$settings = get_block_editor_settings( [], $this->context() );
		$this->assertArrayNotHasKey( 'codeEditingEnabled', $settings );
		$this->assertArrayNotHasKey( 'canLockBlocks', $settings );
		$this->assertArrayHasKey( 'author', $this->supports( 'post' ) );
	}

	public function test_the_editor_of_each_content_type(): void {
		register_post_type(
			'book',
			[
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => [ 'title', 'editor' ],
			]
		);
		$this->put(
			[
				'editors' => [
					'page' => 'classic',
					'post' => 'none',
					'book' => 'blocks',
				],
			]
		);
		add_filter( 'use_block_editor_for_post_type', '__return_false', 5 );

		$this->assertFalse( use_block_editor_for_post_type( 'page' ) );
		$this->assertTrue( use_block_editor_for_post_type( 'book' ), 'Over the choice of another plugin.' );
		$this->assertFalse( use_block_editor_for_post_type( 'post' ) );

		$post    = self::factory()->post->create( [ 'post_content' => '<!-- wp:paragraph --><p>Testo</p><!-- /wp:paragraph -->' ] );
		$content = rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/posts/{$post}" ) )->get_data()['content']['rendered'] ?? '';
		$this->assertStringContainsString( 'Testo', $content, 'Without an editor the content stays in the REST API.' );
		$this->assertTrue( post_type_supports( 'post', 'editor' ) );

		set_current_screen( 'post' );
		do_action( 'load-post.php' );
		$this->assertFalse( post_type_supports( 'post', 'editor' ), 'Only in the screens that edit it.' );
		add_post_type_support( 'post', 'editor' );
		set_current_screen( 'page' );
		do_action( 'load-post-new.php' );
		$this->assertTrue( post_type_supports( 'page', 'editor' ) );
	}

	public function test_external_content_can_be_turned_off(): void {
		set_current_screen( 'post' );
		$this->put(
			[
				'remote_patterns' => false,
				'openverse'       => false,
			]
		);

		$this->assertFalse( apply_filters( 'should_load_remote_block_patterns', true ) );
		$this->assertFalse( get_block_editor_settings( [], $this->context() )['enableOpenverseMediaCategory'], 'For everyone, administrators too.' );
	}

	public function test_settings_are_validated_and_only_administrators_change_them(): void {
		$this->assertSame( 400, $this->put( [ 'roles' => [ 'editor' => $this->role( [ 'disabled' => [ 'paragraph' ] ] ) ] ] )->get_status() );
		$this->assertSame( 400, $this->put( [ 'roles' => [ 'editor' => $this->role( [ 'disabled' => [ 'core/list', 'core/list' ] ] ) ] ] )->get_status() );
		$this->assertSame( 400, $this->put( [ 'roles' => [ 'editor' => [ 'h1' => true ] ] ] )->get_status(), 'A role is saved whole.' );
		$this->assertSame( 400, $this->put( [ 'roles' => [ 'editor' => $this->role( [ 'colors' => true ] ) ] ] )->get_status() );
		$this->assertSame( 400, $this->put( [ 'editors' => [ 'page' => 'gutenberg' ] ] )->get_status() );
		$this->assertSame( 400, $this->put( [ 'openverse' => 'maybe' ] )->get_status() );
		$this->assertFalse( get_option( 'barrio_blocks' ) );

		$saved = $this->put(
			[
				'roles'   => [
					'administrator' => $this->role( [ 'code' => true ] ),
					'editor'        => $this->role( [ 'lock' => true ] ),
					'author'        => $this->role(),
				],
				'editors' => [ 'page' => 'classic' ],
			]
		)->get_data();
		$this->assertEquals( (object) [ 'editor' => $this->role( [ 'lock' => true ] ) ], $saved['roles'], 'Administrators always have everything, and a role without restrictions is not saved.' );
		$this->assertArrayNotHasKey( 'barrio_blocks', wp_load_alloptions(), 'Read only in the editor.' );
		$this->assertEquals( (object) [ 'page' => 'classic' ], $this->put( [ 'openverse' => false ] )->get_data()['editors'], 'What is not sent stays.' );

		$this->as( 'editor' );
		$this->assertSame( 403, $this->request( 'GET', '/blocks' )->get_status() );
		$this->assertSame( 403, $this->put( [ 'openverse' => true ] )->get_status() );

		wp_set_current_user( $this->admin );
		Modules::set( 'blocks', false );
		$GLOBALS['wp_filter']      = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		Plugin::boot();
		$this->assertFalse( has_filter( 'allowed_block_types_all', [ Module::class, 'allowed' ] ) );
		$this->assertFalse( has_filter( 'wp_theme_json_data_theme', [ Module::class, 'theme_json' ] ) );
		$this->assertArrayNotHasKey( 'blocks', apply_filters( 'barrio_admin_data', [] ) );
		$this->assertSame( 404, $this->request( 'GET', '/blocks' )->get_status() );
		$this->assertNotFalse( get_option( 'barrio_blocks' ), 'Its settings stay.' );
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function role( array $values = [] ): array {
		return $values + [
			'disabled' => [],
			'h1'       => false,
			'styles'   => false,
			'code'     => false,
			'lock'     => false,
			'panels'   => false,
		];
	}

	private function as( string $role ): WP_User {
		return wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
	}

	/** @return bool|string[] */
	private function allowed(): bool|array {
		return get_allowed_block_types( $this->context() );
	}

	private function context(): WP_Block_Editor_Context {
		return new WP_Block_Editor_Context( [ 'post' => get_post( self::factory()->post->create() ) ] );
	}

	// Come la legge l'editor, senza le cache della richiesta, che un utente vero non cambia a metà.
	private function global_setting( string ...$path ): mixed {
		WP_Theme_JSON_Resolver::clean_cached_data();
		wp_clean_theme_json_cache();
		return wp_get_global_settings( $path );
	}

	/** @return array<string, mixed> */
	private function supports( string $type ): array {
		$request = new WP_REST_Request( 'GET', "/wp/v2/types/{$type}" );
		$request->set_param( 'context', 'edit' );
		return (array) ( rest_do_request( $request )->get_data()['supports'] ?? [] );
	}

	/** @param array<string, mixed> $body */
	private function put( array $body ): WP_REST_Response {
		return $this->request( 'PUT', '/blocks', $body );
	}

	/** @param array<string, mixed> $body */
	private function request( string $method, string $path, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/barrio/v1' . $path );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_do_request( $request );
	}
}
