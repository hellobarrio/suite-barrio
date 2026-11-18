<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Seo\Llms;
use BarrioSuite\Seo\Module;
use BarrioSuite\Seo\RedirectCode;
use BarrioSuite\Seo\Repository\RedirectRepository;
use BarrioSuite\Seo\Settings;
use WP_UnitTestCase;

// Il SEO in barrio-suite-wpgraphql. Gira solo con WPGraphQL, come WpGraphqlTest.
final class SeoGraphqlTest extends WP_UnitTestCase {

	// Le query di hello_standard (lib/data/queries, lib/fragments.ts), così come le invia.
	private const SEO_FRAGMENT = 'fragment SeoFragment on HBSeoPost {
		title description keywords robots canonical ogImage ogType twitterCard isIndexed isInSitemap
		ogDescription ogTitle twitterImage twitterTitle
	}';

	private const GET_SEO = '{ hbSeoSite { siteTitle defaultDesc separator favicon ogImage } }';

	private const GET_SCHEMA = 'query ($uri: String){ hbSchemaRaw(uri: $uri) }';

	private const GET_SITEMAP = '{ hbSitemap { loc lastmod priority } }';

	private const GET_REDIRECTS = '{ hbRedirects { from to code note } }';

	private const GET_LLMS = '{ hbLlms { content updatedAt bytes } hbLlmsFull { content updatedAt bytes } }';

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WPGraphQL' ) ) {
			$this->markTestSkipped( 'Needs WPGraphQL (WP_GRAPHQL=1).' );
		}
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		Module::register_meta();
		update_option( 'blogname', 'Pizzeria & Forno' );
		Settings::save( [ 'texts' => [ 'en' => [ 'default_description' => 'Pizza napoletana a Torino.' ] ] ] );
		\WPGraphQL::clear_schema();
	}

	public function tear_down(): void {
		if ( class_exists( 'WPGraphQL' ) ) {
			\WPGraphQL::clear_schema();
		}
		parent::tear_down();
	}

	// La fixture viene dal prototipo con WPGraphQL 2.x (wp-env, sito di sviluppo), stampata con WpGraphqlTest::sdl().
	public function test_the_schema_is_the_prototype_one(): void {
		$this->assertStringEqualsFile(
			dirname( __DIR__ ) . '/fixtures/wpgraphql-seo-schema.txt',
			WpGraphqlTest::sdl(
				[
					'RootQuery' => [ 'hbLlms', 'hbLlmsFull', 'hbRedirects', 'hbSchemaRaw', 'hbSeoSite', 'hbSitemap' ],
					'Page'      => [ 'seo' ],
					'Post'      => [ 'seo' ],
				]
			)
		);
	}

	public function test_without_the_seo_module_nothing_is_registered(): void {
		// barrio_seo_loaded non parte con il modulo spento (ModulesTest).
		remove_action( 'graphql_register_types', 'barrio_wpgraphql_register_seo', 9 );
		$this->assertFalse( \WPGraphQL::get_schema()->getType( 'RootQuery' )->hasField( 'hbSeoSite' ) );
		$this->assertFalse( \WPGraphQL::get_schema()->getType( 'Page' )->hasField( 'seo' ) );

		\WPGraphQL::clear_schema();
		do_action( 'barrio_seo_loaded' );
		$this->assertTrue( \WPGraphQL::get_schema()->getType( 'RootQuery' )->hasField( 'hbSeoSite' ) );
	}

	public function test_a_page_has_the_data_of_the_head(): void {
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$page  = self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_title'   => 'Chi siamo',
				'post_name'    => 'chi-siamo',
				'post_content' => 'La nostra storia.',
				'post_excerpt' => '',
			]
		);
		update_post_meta( $page, '_barrio_seo_title', 'La pizzeria' );
		update_post_meta( $page, '_barrio_seo_keywords', [ 'pizza', 'forno' ] );
		update_post_meta( $page, '_barrio_seo_image', $image );
		update_post_meta( $page, '_barrio_seo_follow', 'nofollow' );
		$post = self::factory()->post->create(
			[
				'post_title'    => 'Segreta',
				'post_name'     => 'segreta',
				'post_content'  => 'Il contenuto protetto.',
				'post_password' => 'pass',
			]
		);
		update_post_meta( $post, '_barrio_seo_index', 'noindex' );

		$this->assertSame(
			[
				'title'         => 'La pizzeria',
				'description'   => 'La nostra storia.',
				'keywords'      => [ 'pizza', 'forno' ],
				'robots'        => 'index,nofollow',
				'canonical'     => 'http://example.org/chi-siamo/',
				'ogImage'       => wp_get_attachment_image_url( $image, 'full' ),
				'ogType'        => 'website',
				'twitterCard'   => 'summary_large_image',
				'isIndexed'     => true,
				'isInSitemap'   => true,
				'ogDescription' => 'La nostra storia.',
				'ogTitle'       => 'La pizzeria',
				'twitterImage'  => wp_get_attachment_image_url( $image, 'full' ),
				'twitterTitle'  => 'La pizzeria',
			],
			$this->seo( '/chi-siamo/' )
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$seo = $this->seo( '/segreta/' );
		$this->assertSame( 'Pizza napoletana a Torino.', $seo['description'], 'Never the protected content.' );
		$this->assertSame( [ 'noindex,follow', false, false, 'article', null ], [ $seo['robots'], $seo['isIndexed'], $seo['isInSitemap'], $seo['ogType'], $seo['ogImage'] ] );
	}

	public function test_the_site_settings(): void {
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		Settings::save(
			[
				'title'         => [
					'separator' => '|',
					'order'     => 'site_page',
				],
				'default_image' => $image,
			]
		);
		update_option( 'site_icon', $image );

		$this->assertSame(
			[
				'siteTitle'   => 'Pizzeria & Forno',
				'defaultDesc' => 'Pizza napoletana a Torino.',
				'separator'   => '|',
				'favicon'     => wp_get_attachment_image_url( $image, 'full' ),
				'ogImage'     => wp_get_attachment_image_url( $image, 'full' ),
			],
			$this->query( self::GET_SEO )['hbSeoSite']
		);
	}

	public function test_the_schema_of_a_page_is_the_one_of_the_head(): void {
		$parent = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Servizi',
				'post_name'  => 'servizi',
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Pizze &lt;/script&gt;',
				'post_name'   => 'pizze',
				'post_parent' => $parent,
			]
		);
		$hidden = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'interna',
			]
		);
		update_post_meta( $hidden, '_barrio_seo_index', 'noindex' );
		Settings::save( [ 'organization' => [ 'name' => 'Pizzeria Mario' ] + Settings::get()['organization'] ] );

		$raw = $this->query( self::GET_SCHEMA, [ 'uri' => '/servizi/pizze/' ] )['hbSchemaRaw'];
		$this->assertStringNotContainsString( '</script>', $raw, 'Safe inside <script>.' );
		$graph = json_decode( $raw, true )['@graph'];
		$this->assertSame( [ 'LocalBusiness', 'WebSite', 'WebPage', 'BreadcrumbList' ], array_column( $graph, '@type' ) );
		$this->assertSame( 'Pizzeria Mario', $graph[0]['name'] );
		$this->assertSame( 'http://example.org/servizi/pizze/#webpage', $graph[2]['@id'] );
		$this->assertSame( [ 'Pizzeria & Forno', 'Servizi', 'Pizze </script>' ], array_column( $graph[3]['itemListElement'], 'name' ) );

		foreach ( [ null, '/interna/', '/non-esiste/' ] as $uri ) {
			$graph = json_decode( $this->query( self::GET_SCHEMA, [ 'uri' => $uri ] )['hbSchemaRaw'], true )['@graph'];
			$this->assertSame( [ 'LocalBusiness', 'WebSite' ], array_column( $graph, '@type' ), (string) $uri );
		}

		$term = self::factory()->category->create(
			[
				'name' => 'Pizze',
				'slug' => 'pizze',
			]
		);
		self::factory()->post->create( [ 'post_category' => [ $term ] ] );
		$graph = json_decode( $this->query( self::GET_SCHEMA, [ 'uri' => '/category/pizze/' ] )['hbSchemaRaw'], true )['@graph'];
		$this->assertSame( [ 'LocalBusiness', 'WebSite', 'CollectionPage', 'BreadcrumbList' ], array_column( $graph, '@type' ) );
	}

	public function test_the_sitemap_is_the_one_of_wordpress_in_every_language(): void {
		$post = self::factory()->post->create(
			[
				'post_title' => 'Margherita',
				'post_name'  => 'margherita',
			]
		);
		update_post_meta( self::factory()->post->create( [ 'post_name' => 'nascosta' ] ), '_barrio_seo_index', 'noindex' );
		self::factory()->post->create(
			[
				'post_name'     => 'protetta',
				'post_password' => 'pass',
			]
		);
		$this->set_languages();

		$sitemap = $this->query( '{ hbSitemap { loc lastmod type subtype id title priority } }' )['hbSitemap'];
		$entry   = $sitemap[ array_search( '/margherita/', array_column( $sitemap, 'loc' ), true ) ];
		$this->assertSame(
			[
				'loc'      => '/margherita/',
				'lastmod'  => get_post_modified_time( DATE_W3C, true, $post ),
				'type'     => 'post',
				'subtype'  => 'post',
				'id'       => $post,
				'title'    => 'Margherita',
				'priority' => null,
			],
			$entry
		);
		$locs = array_column( $this->query( self::GET_SITEMAP )['hbSitemap'], 'loc' );
		$this->assertNotContains( '/nascosta/', $locs );
		$this->assertNotContains( '/protetta/', $locs );
		$this->assertContains( '/category/uncategorized/', $locs );
		$this->assertSame( 2, count( array_keys( $locs, '/margherita/', true ) ), 'Once per language: the multilingual plugin filters the queries.' );
	}

	public function test_redirects_have_the_path_of_the_frontend(): void {
		RedirectRepository::create( '/vecchia', '/nuova/', RedirectCode::MovedPermanently, 'Dal vecchio sito' );
		RedirectRepository::create( '/pagina.html', 'https://example.com/', RedirectCode::Found );
		RedirectRepository::create( '/cerca?q=1', '/cerca/', RedirectCode::TemporaryRedirect );
		RedirectRepository::create( '/chiusa', null, RedirectCode::Gone );

		$expected = [
			[ '/cerca?q=1', '/cerca/', 307, null ],
			[ '/chiusa/', null, 410, null ],
			[ '/pagina.html', 'https://example.com/', 302, null ],
			[ '/vecchia/', '/nuova/', 301, null ],
		];
		$this->assertSame( $expected, array_map( 'array_values', $this->query( self::GET_REDIRECTS )['hbRedirects'] ), 'Notes are internal.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 'Dal vecchio sito', $this->query( self::GET_REDIRECTS )['hbRedirects'][3]['note'] );
	}

	public function test_llms_comes_from_the_cache_with_its_date(): void {
		self::factory()->post->create( [ 'post_title' => 'Margherita' ] );
		$llms = $this->query( self::GET_LLMS );

		$this->assertSame( Llms::build( false ), $llms['hbLlms']['content'] );
		$this->assertSame( Llms::build( true ), $llms['hbLlmsFull']['content'] );
		$this->assertSame( strlen( Llms::build( true ) ), $llms['hbLlmsFull']['bytes'] );
		$this->assertEqualsWithDelta( time(), (int) $llms['hbLlms']['updatedAt'], 5 );
	}

	public function test_page_links_point_to_the_frontend_address(): void {
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$page  = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Chi siamo',
				'post_name'  => 'chi-siamo',
			]
		);
		update_post_meta( $page, '_barrio_seo_image', $image );
		update_option( 'graphql_general_settings', [ 'barrio_frontend_url' => 'https://www.pizzeria.it' ] );
		// Prima un llms.txt di WordPress, con i suoi link: GraphQL non deve riceverlo dalla cache.
		$wordpress = Llms::get( false )['text'];

		$seo = $this->seo( '/chi-siamo/' );
		$this->assertSame( 'https://www.pizzeria.it/chi-siamo/', $seo['canonical'] );
		$this->assertSame( wp_get_attachment_image_url( $image, 'full' ), $seo['ogImage'], 'Images stay on WordPress.' );
		$graph = json_decode( $this->query( self::GET_SCHEMA, [ 'uri' => '/chi-siamo/' ] )['hbSchemaRaw'], true )['@graph'];
		$this->assertSame( 'https://www.pizzeria.it/', $graph[1]['url'] );
		$this->assertSame( 'https://www.pizzeria.it/chi-siamo/', $graph[2]['url'] );
		$llms = $this->query( self::GET_LLMS )['hbLlms']['content'];
		$this->assertStringContainsString( '(https://www.pizzeria.it/chi-siamo/)', $llms );
		$this->assertStringNotContainsString( 'http://example.org/chi-', $llms );

		$this->assertSame( $wordpress, Llms::get( false )['text'], 'Outside GraphQL the links stay on WordPress.' );
		$this->assertStringContainsString( '(http://example.org/chi-siamo/)', $wordpress );
	}

	public function test_the_frontend_address_is_saved_clean(): void {
		do_action( 'graphql_init_settings', $registry = new \WPGraphQL\Admin\Settings\SettingsRegistry() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- azione di WPGraphQL.
		$sanitize = $registry->get_sanitize_callback( 'barrio_frontend_url' );
		$this->assertIsCallable( $sanitize );
		$this->assertSame( 'https://www.pizzeria.it', $sanitize( 'https://www.pizzeria.it/' ) );
		$this->assertSame( '', $sanitize( 'javascript:alert(1)' ) );
	}

	/** @return array<string, mixed> */
	private function seo( string $uri ): array {
		$node = $this->query( 'query ($uri: String!) { nodeByUri(uri: $uri) { ... on Page { seo { ...SeoFragment } } ... on Post { seo { ...SeoFragment } } } } ' . self::SEO_FRAGMENT, [ 'uri' => $uri ] )['nodeByUri'];
		$this->assertIsArray( $node, $uri );
		return $node['seo'];
	}

	/**
	 * @param array<string, mixed> $variables
	 * @return array<string, mixed>
	 */
	private function query( string $query, array $variables = [] ): array {
		$response = graphql(
			[
				'query'     => $query,
				'variables' => $variables,
			]
		);
		$this->assertArrayNotHasKey( 'errors', $response, (string) wp_json_encode( $response['errors'] ?? null ) );
		return $response['data'];
	}

	// Due lingue, come con WPML: ogni lingua ha la sua sitemap (qui con gli stessi contenuti).
	private function set_languages(): void {
		add_filter( 'barrio_languages', static fn(): array => [ 'it', 'en' ] );
	}
}
