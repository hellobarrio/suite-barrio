<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Seo\Editor;
use BarrioSuite\Seo\Meta;
use BarrioSuite\Seo\Module;
use BarrioSuite\Seo\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Il pannello SEO: titolo uguale in PHP e in JS, metabox dell'editor classico, termini e salvataggio dei meta.
final class SeoEditorTest extends WP_UnitTestCase {

	private int $editor;

	private int $author;

	public function set_up(): void {
		parent::set_up();
		Module::register_meta();
		$this->editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->author = self::factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $this->editor );
	}

	public function tear_down(): void {
		$_POST = [];
		unregister_post_type( 'book' );
		parent::tear_down();
	}

	public function test_the_title_is_the_same_in_php_and_js(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- una fixture locale.
		foreach ( json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/seo-title.json' ), true ) as $case ) {
			update_option( 'blogname', $case['site'] );
			Settings::save(
				[
					'title' => [
						'separator' => $case['separator'],
						'order'     => $case['order'],
					],
				]
			);
			$this->assertSame( $case['expected'], Meta::document_title( $case['title'], $case['page'] ), $case['name'] );
		}
	}

	public function test_the_block_editor_saves_every_meta_even_without_barrio_edit_seo(): void {
		$post = self::factory()->post->create( [ 'post_author' => $this->author ] );

		// L'editor a blocchi rimanda i meta come li ha letti, quando ne cambia uno qualsiasi: anche quelli
		// assenti, come null o vuoti.
		$meta = $this->rest( 'GET', "/wp/v2/posts/{$post}" )->get_data()['meta'];
		$this->assertCount( count( Module::META ), array_filter( $meta, static fn( string $key ): bool => str_starts_with( $key, '_barrio_seo_' ), ARRAY_FILTER_USE_KEY ) );
		foreach ( [ $this->editor, $this->author ] as $user ) {
			wp_set_current_user( $user );
			$response = $this->rest( 'POST', "/wp/v2/posts/{$post}", [ 'meta' => $meta ] );
			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		}
		$this->assertSame( [], $this->seo_meta( $post ), 'Nothing is stored for the absent meta.' );

		update_post_meta( $post, '_barrio_seo_title', 'Scelto da un editor' );
		$meta = $this->rest( 'GET', "/wp/v2/posts/{$post}" )->get_data()['meta'];
		$this->assertSame( 200, $this->rest( 'POST', "/wp/v2/posts/{$post}", [ 'meta' => $meta ] )->get_status(), 'An unchanged value is not a change.' );
		$this->assertSame( 403, $this->rest( 'POST', "/wp/v2/posts/{$post}", [ 'meta' => [ '_barrio_seo_title' => null ] ] )->get_status() );
		$this->assertSame( 'Scelto da un editor', get_post_meta( $post, '_barrio_seo_title', true ) );
	}

	public function test_a_post_type_without_custom_fields_saves_the_meta_through_rest(): void {
		register_post_type(
			'book',
			[
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => [ 'title', 'editor' ],
			]
		);
		Module::register_meta();
		// Le rotte dei post type si registrano quando la REST parte.
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.

		$book = self::factory()->post->create( [ 'post_type' => 'book' ] );

		$this->assertSame( 200, $this->rest( 'POST', "/wp/v2/book/{$book}", [ 'meta' => [ '_barrio_seo_index' => 'noindex' ] ] )->get_status() );
		$this->assertSame( 'noindex', get_post_meta( $book, '_barrio_seo_index', true ) );
	}

	public function test_the_classic_editor_has_a_meta_box_only_for_who_can_edit_seo(): void {
		add_filter( 'use_block_editor_for_post', '__return_false' );
		set_current_screen( 'post' );
		$post = get_post( self::factory()->post->create( [ 'post_author' => $this->author ] ) );

		do_action( 'add_meta_boxes', 'post', $post );
		$this->assertArrayHasKey( 'barrio-seo', $GLOBALS['wp_meta_boxes']['post']['normal']['high'] );

		$GLOBALS['wp_meta_boxes'] = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( $this->author );
		do_action( 'add_meta_boxes', 'post', $post );
		$this->assertArrayNotHasKey( 'barrio-seo', $GLOBALS['wp_meta_boxes']['post']['normal']['high'] ?? [] );

		ob_start();
		Editor::render_post( $post );
		$this->assertStringContainsString( 'id="barrio-seo-panel"', (string) ob_get_clean() );
		set_current_screen( 'front' );
	}

	public function test_the_classic_editor_saves_the_meta_with_the_post(): void {
		$post  = self::factory()->post->create( [ 'post_author' => $this->author ] );
		$image = self::factory()->attachment->create();
		update_post_meta( $post, '_barrio_seo_follow', 'nofollow' );

		$this->submit(
			"post-{$post}",
			[
				'_barrio_seo_title'    => 'Chi siamo',
				'_barrio_seo_image'    => $image,
				'_barrio_seo_keywords' => [ 'pizza', 'torino' ],
				'_barrio_seo_index'    => 'maybe',
				'_barrio_seo_follow'   => null,
				'_edit_lock'           => 'not ours',
			]
		);
		wp_update_post( [ 'ID' => $post ] );

		$this->assertSame(
			[
				'_barrio_seo_title'    => 'Chi siamo',
				'_barrio_seo_image'    => (string) $image,
				'_barrio_seo_keywords' => [ 'pizza', 'torino' ],
			],
			$this->seo_meta( $post ),
			'Valid values are saved, null goes back to inheriting, an invalid value is not saved.'
		);
		$this->assertSame( '', get_post_meta( $post, '_edit_lock', true ), 'The field writes only SEO meta.' );

		// Il nonce vale solo per quel contenuto, e serve barrio_edit_seo.
		$other = self::factory()->post->create( [ 'post_author' => $this->author ] );
		wp_update_post( [ 'ID' => $other ] );
		$this->assertSame( [], $this->seo_meta( $other ) );
		wp_set_current_user( $this->author );
		$this->submit( "post-{$post}", [ '_barrio_seo_title' => 'Da un autore' ] );
		wp_update_post( [ 'ID' => $post ] );
		$this->assertSame( 'Chi siamo', get_post_meta( $post, '_barrio_seo_title', true ) );
	}

	public function test_terms_have_the_panel_in_their_edit_page(): void {
		$term = get_term( self::factory()->category->create() );

		ob_start();
		Editor::render_term( $term );
		$this->assertStringContainsString( 'id="barrio-seo-panel"', (string) ob_get_clean() );

		$this->submit(
			"term-{$term->term_id}",
			[
				'_barrio_seo_description' => 'Le nostre pizze.',
				'_barrio_seo_collection'  => 'post',
			]
		);
		wp_update_term( $term->term_id, 'category', [ 'name' => 'Pizze' ] );
		$this->assertSame( 'Le nostre pizze.', get_term_meta( $term->term_id, '_barrio_seo_description', true ) );
		$this->assertFalse( metadata_exists( 'term', $term->term_id, '_barrio_seo_collection' ), 'Terms have no collection.' );

		wp_set_current_user( $this->author );
		ob_start();
		Editor::render_term( $term );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_changed_seo_meta_fire_barrio_seo_changed_once_per_object(): void {
		$post = self::factory()->post->create();
		$term = self::factory()->category->create();
		$seen = [];
		add_action(
			'barrio_seo_changed',
			static function ( string $what, int $id = 0 ) use ( &$seen ): void {
				$seen[] = [ $what, $id ];
			},
			10,
			2
		);

		update_post_meta( $post, '_edit_last', 1 );
		update_post_meta( $post, '_barrio_seo_title', 'Uno' );
		update_post_meta( $post, '_barrio_seo_title', 'Due' );
		delete_post_meta( $post, '_barrio_seo_title' );
		add_term_meta( $term, '_barrio_seo_index', 'noindex' );

		$this->assertSame( [ [ 'post', $post ], [ 'term', $term ] ], $seen );
	}

	/** @param array<string, mixed> $meta Il campo nascosto del pannello, come lo invia il form. */
	private function submit( string $key, array $meta ): void {
		$_POST = wp_slash(
			[
				'barrio_seo_nonce' => wp_create_nonce( "barrio_seo-{$key}" ),
				'barrio_seo'       => (string) wp_json_encode( $meta ),
			]
		);
	}

	/** @return array<string, mixed> */
	private function seo_meta( int $post ): array {
		return array_map(
			static fn( array $values ): mixed => maybe_unserialize( $values[0] ),
			array_filter( get_post_meta( $post ), static fn( string $key ): bool => str_starts_with( $key, '_barrio_seo_' ), ARRAY_FILTER_USE_KEY )
		);
	}

	/** @param array<string, mixed> $body */
	private function rest( string $method, string $route, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_param( 'context', 'edit' );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}
}
