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

	// Le query di hello_standard (lib/data/queries, lib/fragments.ts), con i nomi del foglio di porting.
	private const SEO_FRAGMENT = 'fragment SeoFragment on HbSeo {
		title description keywords robots canonical ogImage ogType twitterCard isIndexed isInSitemap
		ogDescription ogTitle twitterImage twitterTitle
	}';

	private const GET_SEO = '{ hbSeoSettings { siteTitle defaultDescription separator titleOrder favicon ogImage } }';

	private const GET_SCHEMA = 'query ($uri: String){ hbSeoSchema(uri: $uri) }';

	private const GET_SITEMAP = '{ hbSitemap { loc lastmod } }';

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

	// La fotografia dello schema, stampata con WpGraphqlTest::sdl().
	public function test_the_schema_matches_the_snapshot(): void {
		$this->assertStringEqualsFile(
			dirname( __DIR__ ) . '/fixtures/wpgraphql-seo-schema.txt',
			WpGraphqlTest::sdl(
				[
					'RootQuery' => [ 'hbLlms', 'hbLlmsFull', 'hbRedirects', 'hbSeoSchema', 'hbSeoSettings', 'hbSitemap' ],
					'Page'      => [ 'seo' ],
					'Post'      => [ 'seo' ],
					'Category'  => [ 'seo' ],
					'Tag'       => [ 'seo' ],
				]
			)
		);
	}

	public function test_without_the_seo_module_nothing_is_registered(): void {
		// barrio_seo_loaded non parte con il modulo spento (ModulesTest).
		remove_action( 'graphql_register_types', 'barrio_wpgraphql_register_seo', 9 );
		$this->assertFalse( \WPGraphQL::get_schema()->getType( 'RootQuery' )->hasField( 'hbSeoSettings' ) );
		$this->assertFalse( \WPGraphQL::get_schema()->getType( 'Page' )->hasField( 'seo' ) );
		$this->assertFalse( \WPGraphQL::get_schema()->getType( 'Category' )->hasField( 'seo' ) );

		\WPGraphQL::clear_schema();
		do_action( 'barrio_seo_loaded' );
		$this->assertTrue( \WPGraphQL::get_schema()->getType( 'RootQuery' )->hasField( 'hbSeoSettings' ) );
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
				'keywords'      => [],
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
		$this->assertSame( [ 'pizza', 'forno' ], $this->seo( '/chi-siamo/' )['keywords'], 'The keywords only for those who can edit the content.' );
		$seo = $this->seo( '/segreta/' );
		$this->assertSame( 'Pizza napoletana a Torino.', $seo['description'], 'Never the protected content.' );
		$this->assertSame( [ 'noindex,follow', false, false, 'article', null ], [ $seo['robots'], $seo['isIndexed'], $seo['isInSitemap'], $seo['ogType'], $seo['ogImage'] ] );
	}

	// WPGraphQL nasconde a un autore la bozza di un altro e il contenuto con password: seo non dà ciò che ne deriva.
	public function test_seo_is_null_for_a_content_that_the_user_cannot_read(): void {
		$draft = self::factory()->post->create(
			[
				'post_status'  => 'draft',
				'post_author'  => self::factory()->user->create( [ 'role' => 'editor' ] ),
				'post_title'   => 'Lancio',
				'post_content' => 'Il lancio segreto di marzo.',
				'post_excerpt' => '',
			]
		);
		$query = 'query ($id: ID!) { post(id: $id, idType: DATABASE_ID) { databaseId seo { title description } } }';

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertNull( $this->query( $query, [ 'id' => $draft ] )['post']['seo'] ?? null );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 'Il lancio segreto di marzo.', $this->query( $query, [ 'id' => $draft ] )['post']['seo']['description'] );
	}

	public function test_a_term_has_the_data_of_the_head(): void {
		$term = self::factory()->category->create(
			[
				'name'        => 'Pizze',
				'slug'        => 'pizze',
				'description' => 'Le pizze del forno.',
			]
		);
		update_term_meta( $term, '_barrio_seo_keywords', [ 'pizze', 'forno' ] );
		self::factory()->post->create( [ 'post_category' => [ $term ] ] );
		$empty = self::factory()->tag->create( [ 'name' => 'Vuoto' ] );

		$this->assertSame(
			[
				'title'         => 'Pizze',
				'description'   => 'Le pizze del forno.',
				'keywords'      => [],
				'robots'        => 'index,follow',
				'canonical'     => 'http://example.org/category/pizze/',
				'ogImage'       => null,
				'ogType'        => 'website',
				'twitterCard'   => 'summary_large_image',
				'isIndexed'     => true,
				'isInSitemap'   => true,
				'ogDescription' => 'Le pizze del forno.',
				'ogTitle'       => 'Pizze',
				'twitterImage'  => null,
				'twitterTitle'  => 'Pizze',
			],
			$this->seo( '/category/pizze/' )
		);
		$tag = $this->query( 'query ($id: ID!) { tag(id: $id, idType: DATABASE_ID) { seo { isIndexed isInSitemap } } }', [ 'id' => $empty ] )['tag']['seo'];
		$this->assertSame( [ true, false ], array_values( $tag ), 'An empty term is not in the sitemap of WordPress.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( [ 'pizze', 'forno' ], $this->seo( '/category/pizze/' )['keywords'], 'The keywords only for those who can edit the term.' );
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
				'siteTitle'          => 'Pizzeria & Forno',
				'defaultDescription' => 'Pizza napoletana a Torino.',
				'separator'          => '|',
				'titleOrder'         => 'SITE_PAGE',
				'favicon'            => wp_get_attachment_image_url( $image, 'full' ),
				'ogImage'            => wp_get_attachment_image_url( $image, 'full' ),
			],
			$this->query( self::GET_SEO )['hbSeoSettings']
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
		update_option( 'barrio_company', [ 'name' => 'Pizzeria Mario' ] );

		$raw = $this->query( self::GET_SCHEMA, [ 'uri' => '/servizi/pizze/' ] )['hbSeoSchema'];
		$this->assertStringNotContainsString( '</script>', $raw, 'Safe inside <script>.' );
		$graph = json_decode( $raw, true )['@graph'];
		$this->assertSame( [ 'LocalBusiness', 'WebSite', 'WebPage', 'BreadcrumbList' ], array_column( $graph, '@type' ) );
		$this->assertSame( 'Pizzeria Mario', $graph[0]['name'] );
		$this->assertSame( 'http://example.org/servizi/pizze/#webpage', $graph[2]['@id'] );
		$this->assertSame( [ 'Pizzeria & Forno', 'Servizi', 'Pizze </script>' ], array_column( $graph[3]['itemListElement'], 'name' ) );

		foreach ( [ null, '/interna/', '/non-esiste/' ] as $uri ) {
			$graph = json_decode( $this->query( self::GET_SCHEMA, [ 'uri' => $uri ] )['hbSeoSchema'], true )['@graph'];
			$this->assertSame( [ 'LocalBusiness', 'WebSite' ], array_column( $graph, '@type' ), (string) $uri );
		}

		$term = self::factory()->category->create(
			[
				'name' => 'Pizze',
				'slug' => 'pizze',
			]
		);
		self::factory()->post->create( [ 'post_category' => [ $term ] ] );
		$graph = json_decode( $this->query( self::GET_SCHEMA, [ 'uri' => '/category/pizze/' ] )['hbSeoSchema'], true )['@graph'];
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

		$sitemap = $this->query( '{ hbSitemap { loc lastmod type subtype databaseId title } }' )['hbSitemap'];
		$entry   = $sitemap[ array_search( '/margherita/', array_column( $sitemap, 'loc' ), true ) ];
		$this->assertSame(
			[
				'loc'        => '/margherita/',
				'lastmod'    => get_post_modified_time( DATE_W3C, true, $post ),
				'type'       => 'post',
				'subtype'    => 'post',
				'databaseId' => $post,
				'title'      => 'Margherita',
			],
			$entry
		);
		$locs = array_column( $this->query( self::GET_SITEMAP )['hbSitemap'], 'loc' );
		$this->assertNotContains( '/nascosta/', $locs );
		$this->assertNotContains( '/protetta/', $locs );
		$this->assertContains( '/category/uncategorized/', $locs );
		$this->assertSame( 2, count( array_keys( $locs, '/margherita/', true ) ), 'Once per language: the multilingual plugin filters the queries.' );
	}

	// Il campo è pubblico: una richiesta anonima non rilegge tutti gli URL, nemmeno con gli alias.
	public function test_the_sitemap_comes_from_a_cache_emptied_when_a_content_changes(): void {
		self::factory()->post->create( [ 'post_name' => 'margherita' ] );
		$this->query( self::GET_SITEMAP );

		$queries = get_num_queries();
		$twice   = $this->query( '{ a: hbSitemap { loc } b: hbSitemap { loc } }' );
		$this->assertSame( $queries, get_num_queries() );
		$this->assertSame( $twice['a'], $twice['b'] );

		self::factory()->post->create( [ 'post_name' => 'marinara' ] );
		$this->assertContains( '/marinara/', array_column( $this->query( self::GET_SITEMAP )['hbSitemap'], 'loc' ) );
	}

	public function test_redirects_have_the_path_of_the_frontend(): void {
		$id = RedirectRepository::create( '/vecchia', '/nuova/', RedirectCode::MovedPermanently, 'Dal vecchio sito' );
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

		$this->assertSame( $id, $this->query( '{ hbRedirects { databaseId } }' )['hbRedirects'][3]['databaseId'] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 'Dal vecchio sito', $this->query( self::GET_REDIRECTS )['hbRedirects'][3]['note'] );
	}

	public function test_llms_comes_from_the_cache_with_its_date(): void {
		self::factory()->post->create( [ 'post_title' => 'Margherita' ] );
		$llms = $this->query( self::GET_LLMS );

		$this->assertSame( Llms::build( false ), $llms['hbLlms']['content'] );
		$this->assertSame( Llms::build( true ), $llms['hbLlmsFull']['content'] );
		$this->assertSame( strlen( Llms::build( true ) ), $llms['hbLlmsFull']['bytes'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $llms['hbLlms']['updatedAt'] );
		$this->assertEqualsWithDelta( time(), strtotime( $llms['hbLlms']['updatedAt'] ), 5 );
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
		update_option( 'barrio_frontend', [ 'url' => 'https://www.pizzeria.it' ] );
		// Prima un llms.txt di WordPress, con i suoi link: GraphQL non deve riceverlo dalla cache.
		$wordpress = Llms::get( false )['text'];

		$seo = $this->seo( '/chi-siamo/' );
		$this->assertSame( 'https://www.pizzeria.it/chi-siamo/', $seo['canonical'] );
		$this->assertSame( wp_get_attachment_image_url( $image, 'full' ), $seo['ogImage'], 'Images stay on WordPress.' );
		$graph = json_decode( $this->query( self::GET_SCHEMA, [ 'uri' => '/chi-siamo/' ] )['hbSeoSchema'], true )['@graph'];
		$this->assertSame( 'https://www.pizzeria.it/', $graph[1]['url'] );
		$this->assertSame( 'https://www.pizzeria.it/chi-siamo/', $graph[2]['url'] );
		$llms = $this->query( self::GET_LLMS )['hbLlms']['content'];
		$this->assertStringContainsString( '(https://www.pizzeria.it/chi-siamo/)', $llms );
		$this->assertStringNotContainsString( 'http://example.org/chi-', $llms );

		$this->assertSame( $wordpress, Llms::get( false )['text'], 'Outside GraphQL the links stay on WordPress.' );
		$this->assertStringContainsString( '(http://example.org/chi-siamo/)', $wordpress );
	}

	/** @return array<string, mixed> */
	private function seo( string $uri ): array {
		$node = $this->query( 'query ($uri: String!) { nodeByUri(uri: $uri) { ... on Page { seo { ...SeoFragment } } ... on Post { seo { ...SeoFragment } } ... on Category { seo { ...SeoFragment } } } } ' . self::SEO_FRAGMENT, [ 'uri' => $uri ] )['nodeByUri'];
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
