<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Seo\Module;
use BarrioSuite\Seo\RedirectCode;
use BarrioSuite\Seo\Repository\RedirectRepository;
use BarrioSuite\Seo\Settings;
use WP_REST_Posts_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// I dati del modulo SEO: meta dei contenuti e dei termini nella REST del core, impostazioni e redirect.
final class SeoDataTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// La suite di WordPress toglie i meta registrati alla fine di ogni test.
		Module::register_meta();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
	}

	public function tear_down(): void {
		parent::tear_down();
		// I ruoli restano in memoria anche dopo il rollback: si rileggono dal database, non dalla cache.
		self::flush_cache();
		wp_roles()->for_site();
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function invalid_meta(): array {
		return [
			'index outside the enum'  => [ [ '_barrio_seo_index' => 'maybe' ] ],
			'follow outside the enum' => [ [ '_barrio_seo_follow' => 'index' ] ],
			'image without an id'     => [ [ '_barrio_seo_image' => 0 ] ],
			'repeated keywords'       => [ [ '_barrio_seo_keywords' => [ 'pizza', 'pizza' ] ] ],
			'empty keyword'           => [ [ '_barrio_seo_keywords' => [ '' ] ] ],
			'collection not a slug'   => [ [ '_barrio_seo_collection' => 'Our Projects' ] ],
		];
	}

	/**
	 * @dataProvider invalid_meta
	 * @param array<string, mixed> $meta
	 */
	public function test_the_core_rest_api_rejects_invalid_meta( array $meta ): void {
		$post = self::factory()->post->create();

		$this->assertSame( 400, $this->update( "/wp/v2/posts/{$post}", $meta )->get_status() );
		$this->assertSame( [], array_filter( get_post_meta( $post ), static fn( string $key ): bool => str_starts_with( $key, '_barrio_seo_' ), ARRAY_FILTER_USE_KEY ) );
	}

	public function test_an_absent_meta_inherits_and_null_goes_back_to_inheriting(): void {
		$post  = self::factory()->post->create();
		$image = self::factory()->attachment->create();

		$response = $this->update(
			"/wp/v2/posts/{$post}",
			[
				'_barrio_seo_title'      => '<b>Chi siamo</b>',
				'_barrio_seo_image'      => $image,
				'_barrio_seo_keywords'   => [ 'pizza napoletana', 'torino' ],
				'_barrio_seo_index'      => 'noindex',
				'_barrio_seo_collection' => 'post',
			]
		);

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$meta = $response->get_data()['meta'];
		$this->assertSame( [ 'Chi siamo', $image, [ 'pizza napoletana', 'torino' ], 'noindex' ], [ $meta['_barrio_seo_title'], $meta['_barrio_seo_image'], $meta['_barrio_seo_keywords'], $meta['_barrio_seo_index'] ] );
		// Assente: un testo vuoto, che come un meta assente vuol dire "eredita"; null per gli altri.
		$this->assertSame( '', $meta['_barrio_seo_description'] );
		$this->assertNull( $meta['_barrio_seo_follow'] );

		$this->update( "/wp/v2/posts/{$post}", [ '_barrio_seo_index' => null ] );
		$this->assertFalse( metadata_exists( 'post', $post, '_barrio_seo_index' ) );

		// Un controller nuovo: quello del tipo tiene in cache lo schema, con i contesti dei meta, tra i test.
		wp_set_current_user( 0 );
		$meta = ( new WP_REST_Posts_Controller( 'post' ) )->prepare_item_for_response( get_post( $post ), new WP_REST_Request( 'GET', "/wp/v2/posts/{$post}" ) )->get_data()['meta'];
		$this->assertSame( [ false, 'Chi siamo' ], [ isset( $meta['_barrio_seo_keywords'] ), $meta['_barrio_seo_title'] ], 'The keywords only for those who edit the content.' );
	}

	public function test_terms_have_the_same_meta_except_the_collection(): void {
		$term = self::factory()->category->create();

		$this->assertSame( 200, $this->update( "/wp/v2/categories/{$term}", [ '_barrio_seo_follow' => 'nofollow' ] )->get_status() );
		$this->assertSame( 'nofollow', get_term_meta( $term, '_barrio_seo_follow', true ) );
		$this->assertSame( 400, $this->update( "/wp/v2/categories/{$term}", [ '_barrio_seo_follow' => 'no' ] )->get_status() );
		$this->assertFalse( registered_meta_key_exists( 'term', '_barrio_seo_collection', 'category' ) );
		$this->assertFalse( registered_meta_key_exists( 'post', '_barrio_seo_title', 'attachment' ), 'Attachments are never involved.' );
		$this->assertTrue( registered_meta_key_exists( 'post', '_barrio_seo_title', 'page' ) );
	}

	public function test_writing_seo_meta_needs_barrio_edit_seo(): void {
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		$post   = self::factory()->post->create( [ 'post_author' => $author ] );
		wp_set_current_user( $author );

		$this->assertSame( 403, $this->update( "/wp/v2/posts/{$post}", [ '_barrio_seo_title' => 'Title' ] )->get_status() );
		$this->assertSame( 200, $this->update( "/wp/v2/posts/{$post}", [], [ 'title' => 'Without SEO' ] )->get_status(), 'The rest of the post is saved as usual.' );

		get_role( 'author' )->add_cap( 'barrio_edit_seo' );
		$this->assertSame( 200, $this->update( "/wp/v2/posts/{$post}", [ '_barrio_seo_title' => 'Title' ] )->get_status() );
	}

	public function test_settings_have_defaults_and_are_saved_one_section_at_a_time(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$settings = $this->settings()->get_data();
		$this->assertSame(
			[ '—', 'page_site', false, true, 'LocalBusiness', [ 'barrio_edit_seo' ] ],
			[ $settings['title']['separator'], $settings['title']['order'], $settings['index']['archives']['date']['index'], $settings['auto_redirects'], $settings['organization']['type'], $settings['roles']['editor'] ]
		);

		$organization = [
			'type' => 'Restaurant',
			'geo'  => [
				'latitude'  => 45.07,
				'longitude' => 7.68,
			],
		] + $settings['organization'];
		$response     = $this->settings(
			[
				'title'        => [
					'separator' => '|',
					'order'     => 'site_page',
				],
				'organization' => $organization,
				'texts'        => [
					'it' => [
						'default_description'      => 'Pizza a Torino.',
						'organization_description' => '',
						'llms_intro'               => '',
					],
				],
			]
		);

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$saved = Settings::get();
		$this->assertSame( [ '|', 'Restaurant', 45.07, 'Pizza a Torino.' ], [ $saved['title']['separator'], $saved['organization']['type'], $saved['organization']['geo']['latitude'], $saved['texts']['it']['default_description'] ] );
		$this->assertTrue( $saved['auto_redirects'], 'Sections not sent stay as they are.' );

		$this->settings( [ 'auto_redirects' => false ] );
		$this->assertSame( 'site_page', Settings::get()['title']['order'] );
		$this->assertFalse( Settings::get()['auto_redirects'] );
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function invalid_settings(): array {
		$organization = static fn( array $changes ): array => [ 'organization' => $changes + Settings::get()['organization'] ];
		return [
			'empty separator'            => [
				[
					'title' => [
						'separator' => '',
						'order'     => 'page_site',
					],
				],
			],
			'incomplete section'         => [ [ 'title' => [ 'separator' => '|' ] ] ],
			'unknown organization type'  => [ $organization( [ 'type' => 'Pizzeria' ] ) ],
			'name, in Company details'   => [ $organization( [ 'name' => 'Pizzeria da Mario' ] ) ],
			'latitude out of range'      => [
				$organization(
					[
						'geo' => [
							'latitude'  => 91,
							'longitude' => 0,
						],
					]
				),
			],
			'texts of a lang in capital' => [
				[
					'texts' => [
						'IT' => [
							'default_description'      => '',
							'organization_description' => '',
							'llms_intro'               => '',
						],
					],
				],
			],
			'robots of a post type'      => [
				[
					'index' => [
						'post_types' => [ 'page' => [ 'index' => 'no' ] ],
						'taxonomies' => [],
						'archives'   => Settings::get()['index']['archives'],
					],
				],
			],
			'unknown llms mode'          => [
				[
					'llms' => [
						'post_types' => [ 'post' => 'all' ],
						'taxonomies' => [],
					],
				],
			],
		];
	}

	/**
	 * @dataProvider invalid_settings
	 * @param array<string, mixed> $body
	 */
	public function test_invalid_settings_are_rejected( array $body ): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( 400, $this->settings( $body )->get_status() );
		$this->assertSame( [], get_option( 'barrio_seo' ) );
	}

	public function test_only_administrators_manage_settings_and_permissions(): void {
		$this->assertSame( 403, $this->settings()->get_status() );
		$this->assertSame( 403, $this->settings( [ 'auto_redirects' => false ] )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$roles = $this->settings( [ 'roles' => [ 'author' => [ 'barrio_edit_seo' ] ] ] )->get_data()['roles'];
		$this->assertSame( [ 'barrio_edit_seo' ], $roles['author'] );
		$this->assertTrue( get_role( 'author' )->has_cap( 'barrio_edit_seo' ) );
		$this->assertSame( 400, $this->settings( [ 'roles' => [ 'author' => [ 'barrio_manage_forms' ] ] ] )->get_status(), 'Only the capabilities of the module.' );
	}

	public function test_redirects_are_written_and_read_by_the_repository(): void {
		$id = RedirectRepository::create( '/chi-siamo', '/about', RedirectCode::MovedPermanently, null, true );

		$redirect = RedirectRepository::find( '/chi-siamo' );
		$this->assertNotNull( $redirect );
		$this->assertSame( [ $id, '/about', RedirectCode::MovedPermanently, true ], [ $redirect['id'], $redirect['target'], $redirect['code'], $redirect['is_auto'] ] );
		$this->assertNull( RedirectRepository::find( '/Chi-Siamo' ), 'Paths are case-sensitive.' );

		RedirectRepository::update( $id, '/chi-siamo', null, RedirectCode::Gone, 'Closed' );
		$redirect = RedirectRepository::get( $id );
		$this->assertNotNull( $redirect );
		$this->assertSame( [ null, RedirectCode::Gone, 'Closed', true ], [ $redirect['target'], $redirect['code'], $redirect['note'], $redirect['is_auto'] ], 'is_auto stays.' );

		RedirectRepository::delete( $id );
		$this->assertNull( RedirectRepository::get( $id ) );
	}

	/**
	 * @param array<string, mixed> $meta
	 * @param array<string, mixed> $fields
	 */
	private function update( string $route, array $meta, array $fields = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $fields + ( $meta ? [ 'meta' => $meta ] : [] ) );
		return rest_do_request( $request );
	}

	/** @param array<string, mixed>|null $body Null = GET. */
	private function settings( ?array $body = null ): WP_REST_Response {
		$request = new WP_REST_Request( null === $body ? 'GET' : 'PUT', '/barrio/v1/seo/settings' );
		if ( null !== $body ) {
			$request->set_body_params( $body );
		}
		return rest_do_request( $request );
	}
}
