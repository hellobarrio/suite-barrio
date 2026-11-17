<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Seo\Llms;
use BarrioSuite\Seo\Meta;
use BarrioSuite\Seo\Module;
use BarrioSuite\Seo\RedirectCode;
use BarrioSuite\Seo\Redirects;
use BarrioSuite\Seo\Repository\RedirectRepository;
use BarrioSuite\Seo\Settings;
use DOMDocument;
use DOMXPath;
use WP_Sitemaps;
use WP_UnitTestCase;

// L'output del modulo SEO nel sito: head, JSON-LD, sitemap, redirect e llms.txt.
final class SeoOutputTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// Le regole di tassonomie e post type si aggiungono solo con i permalink già attivi.
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		register_post_type(
			'project',
			[
				'label'       => 'Projects',
				'public'      => true,
				'has_archive' => true,
			]
		);
		flush_rewrite_rules();
		Module::register_meta();
		// Come add_theme_support( 'title-tag' ), che dopo wp_loaded è troppo tardi.
		$GLOBALS['_wp_theme_features']['title-tag'] = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		update_option( 'blogname', 'Pizzeria & Forno' );
		update_option( 'blogdescription', 'Pizza a Torino' );
		Settings::save( [ 'texts' => [ 'en' => [ 'default_description' => 'Pizza napoletana a Torino.' ] ] ] );
	}

	public function tear_down(): void {
		unregister_post_type( 'project' );
		unset( $GLOBALS['_wp_theme_features']['title-tag'] );
		parent::tear_down();
	}

	public function test_every_kind_of_page_has_one_title_description_robots_and_canonical(): void {
		$author = self::factory()->user->create(
			[
				'role'          => 'author',
				'user_nicename' => 'mario',
			]
		);
		$news   = self::factory()->category->create( [ 'name' => 'News' ] );
		$tag    = self::factory()->tag->create( [ 'name' => 'Forno' ] );
		$post   = self::factory()->post->create(
			[
				'post_title'    => 'Ciao mondo',
				'post_author'   => $author,
				'post_category' => [ $news ],
				'tags_input'    => [ 'Forno' ],
				'post_date'     => '2026-03-10 10:00:00',
			]
		);
		self::factory()->post->create(
			[
				'post_category' => [ $news ],
				'post_date'     => '2026-03-11 10:00:00',
			]
		);
		update_post_meta( $post, '_barrio_seo_title', 'Ciao da Torino' );
		$page    = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Chi siamo',
			]
		);
		$project = self::factory()->post->create(
			[
				'post_type'  => 'project',
				'post_title' => 'Forno a legna',
			]
		);
		update_option( 'posts_per_page', 1 );
		$category = (string) get_term_link( $news );
		$home     = home_url( '/' );

		// URL => [ <title>, canonical (null = nessuna), og:type ].
		$pages = [
			$home                                   => [ 'Pizza a Torino — Pizzeria & Forno', $home, 'website' ],
			get_permalink( $page )                  => [ 'Chi siamo — Pizzeria & Forno', get_permalink( $page ), 'website' ],
			get_permalink( $post )                  => [ 'Ciao da Torino — Pizzeria & Forno', get_permalink( $post ), 'article' ],
			get_permalink( $project )               => [ 'Forno a legna — Pizzeria & Forno', get_permalink( $project ), 'website' ],
			get_post_type_archive_link( 'project' ) => [ 'Projects — Pizzeria & Forno', get_post_type_archive_link( 'project' ), 'website' ],
			$category                               => [ 'News — Pizzeria & Forno', $category, 'website' ],
			"{$category}page/2/"                    => [ 'News — Page 2 — Pizzeria & Forno', "{$category}page/2/", 'website' ],
			get_term_link( $tag )                   => [ 'Forno — Pizzeria & Forno', get_term_link( $tag ), 'website' ],
			get_author_posts_url( $author )         => [ get_the_author_meta( 'display_name', $author ) . ' — Pizzeria & Forno', get_author_posts_url( $author ), 'website' ],
			get_year_link( 2026 )                   => [ '2026 — Pizzeria & Forno', get_year_link( 2026 ), 'website' ],
			home_url( '/?s=pizza' )                 => [ 'Search results for “pizza” — Pizzeria & Forno', null, null ],
			home_url( '/non-esiste/' )              => [ 'Page not found — Pizzeria & Forno', null, null ],
		];
		foreach ( $pages as $url => [ $title, $canonical, $type ] ) {
			$this->assert_head( (string) $url, $title, $canonical ? (string) $canonical : null, $type );
		}

		// Home e pagina degli articoli come pagine.
		$blog = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Blog',
			]
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page );
		update_option( 'page_for_posts', $blog );
		$this->assert_head( $home, 'Chi siamo — Pizzeria & Forno', $home, 'website' );
		$this->assert_head( (string) get_permalink( $blog ), 'Blog — Pizzeria & Forno', (string) get_permalink( $blog ), 'website' );
	}

	public function test_title_order_separator_and_meta_description(): void {
		$long = self::factory()->post->create(
			[
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( 'Forno a legna ', 20 ) . '[gallery]</p><!-- /wp:paragraph -->',
			]
		);
		$own  = self::factory()->post->create( [ 'post_excerpt' => 'Fish &amp; <b>chips</b>' ] );
		$safe = self::factory()->post->create(
			[
				'post_excerpt'  => 'Il segreto della casa.',
				'post_password' => 'pizza',
			]
		);

		$description = Meta::post( get_post( $long ) )['description'];
		$this->assertLessThanOrEqual( 160, mb_strlen( $description ) );
		$this->assertStringEndsWith( 'legna Forno…', $description, 'Cut without breaking a word.' );
		$this->assertSame( 'Fish & chips', Meta::post( get_post( $own ) )['description'] );
		$this->assertSame( 'Pizza napoletana a Torino.', Meta::post( get_post( $safe ) )['description'], 'Protected content never leaks.' );

		Settings::save(
			[
				'title' => [
					'separator' => '|',
					'order'     => 'site_page',
				],
			]
		);
		$this->assertSame( 'Pizzeria & Forno | Blog | Page 3', Meta::document_title( 'Blog', 3 ) );
		$this->assertSame( 'Pizzeria & Forno', Meta::document_title( 'Pizzeria & Forno' ), 'The site name only once.' );
	}

	public function test_robots_inherit_from_the_type_and_a_meta_overrides_them(): void {
		$page  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$other = self::factory()->post->create( [ 'post_type' => 'page' ] );
		Settings::save(
			[
				'index' => [
					'post_types' => [
						'page' => [
							'index'  => false,
							'follow' => true,
						],
					],
					'taxonomies' => [],
					'archives'   => Settings::get()['index']['archives'],
				],
			]
		);
		update_post_meta( $other, '_barrio_seo_index', 'index' );
		update_post_meta( $other, '_barrio_seo_follow', 'nofollow' );

		$this->assertSame( 'max-image-preview:large, noindex', $this->robots( (string) get_permalink( $page ) ) );
		$this->assertSame( 'max-image-preview:large, nofollow', $this->robots( (string) get_permalink( $other ) ) );
		$this->assertSame( 0, $this->head( (string) get_permalink( $page ) )->query( '//script[@type="application/ld+json"]' )->length, 'No graph on noindex pages.' );
	}

	public function test_the_json_ld_graph(): void {
		Settings::save(
			[
				'organization' => [
					'name'   => 'Pizzeria da Mario',
					'phone'  => '+39 011 123456',
					'offers' => [
						'name'     => 'Menu',
						'currency' => 'EUR',
						'items'    => [
							[
								'type'         => 'Product',
								'name'         => 'Margherita',
								'description'  => '',
								'category'     => '',
								'url'          => '',
								'image'        => 0,
								'price'        => 0,
								'availability' => 'InStock',
							],
						],
					],
				] + Settings::get()['organization'],
			]
		);
		$parent  = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Servizi',
			]
		);
		$child   = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Progetti',
				'post_parent' => $parent,
			]
		);
		$visible = self::factory()->post->create(
			[
				'post_type'  => 'project',
				'post_title' => 'Forno',
			]
		);
		$hidden  = self::factory()->post->create( [ 'post_type' => 'project' ] );
		self::factory()->post->create(
			[
				'post_type'     => 'project',
				'post_password' => 'x',
			]
		);
		update_post_meta( $hidden, '_barrio_seo_index', 'noindex' );
		update_post_meta( $child, '_barrio_seo_collection', 'project' );
		update_post_meta( $child, '_barrio_seo_keywords', [ 'forni', 'torino' ] );

		$scripts = $this->head( (string) get_permalink( $child ) )->query( '//script[@type="application/ld+json"]' );
		$this->assertSame( 1, $scripts->length );
		$graph = json_decode( (string) $scripts->item( 0 )?->textContent, true )['@graph'];
		$this->assertSame( [ 'LocalBusiness', 'WebSite', 'CollectionPage', 'BreadcrumbList' ], array_column( $graph, '@type' ) );

		[ $business, , $page, $breadcrumb ] = $graph;
		$this->assertSame( [ 'Pizzeria da Mario', '+39 011 123456', 0, 'https://schema.org/InStock' ], [ $business['name'], $business['telephone'], $business['hasOfferCatalog']['itemListElement'][0]['price'], $business['hasOfferCatalog']['itemListElement'][0]['availability'] ] );
		$this->assertArrayNotHasKey( 'address', $business, 'Empty fields stay out.' );
		$this->assertSame( [ 'forni, torino', 'en-US' ], [ $page['keywords'], $page['inLanguage'] ] );
		$this->assertSame( [ get_permalink( $visible ) ], array_column( $page['mainEntity']['itemListElement'], 'url' ), 'Only indexable, unprotected items.' );
		$this->assertSame( [ 'Pizzeria & Forno', 'Servizi', 'Progetti' ], array_column( $breadcrumb['itemListElement'], 'name' ) );
		$this->assertSame( $page['breadcrumb']['@id'], $breadcrumb['@id'] );
	}

	public function test_the_sitemap_has_only_indexable_unprotected_content(): void {
		$cat       = self::factory()->category->create();
		$public    = self::factory()->post->create( [ 'post_category' => [ $cat ] ] );
		$noindex   = self::factory()->post->create( [ 'post_category' => [ $cat ] ] );
		$protected = self::factory()->post->create( [ 'post_password' => 'x' ] );
		$hidden    = self::factory()->category->create();
		self::factory()->post->create( [ 'post_category' => [ $hidden ] ] );
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$kept = self::factory()->post->create( [ 'post_type' => 'page' ] );
		update_post_meta( $noindex, '_barrio_seo_index', 'noindex' );
		update_term_meta( $hidden, '_barrio_seo_index', 'noindex' );
		update_post_meta( $kept, '_barrio_seo_index', 'index' );
		Settings::save(
			[
				'index' => [
					'post_types' => [
						'page' => [
							'index'  => false,
							'follow' => true,
						],
					],
					'taxonomies' => [],
					'archives'   => [
						'author' => [
							'index'  => false,
							'follow' => true,
						],
						'date'   => Settings::get()['index']['archives']['date'],
					],
				],
			]
		);

		$registry = wp_sitemaps_get_server()->registry;
		$posts    = array_column( $registry->get_provider( 'posts' )->get_url_list( 1, 'post' ), 'loc' );
		$this->assertContains( get_permalink( $public ), $posts );
		$this->assertNotContains( get_permalink( $noindex ), $posts );
		$this->assertNotContains( get_permalink( $protected ), $posts );
		$pages = array_column( $registry->get_provider( 'posts' )->get_url_list( 1, 'page' ), 'loc' );
		$this->assertSame( [ home_url( '/' ), get_permalink( $kept ) ], $pages, 'In a noindex type only what is indexed by hand.' );
		$this->assertNotContains( get_page_link( $page ), $pages );
		$terms = array_column( $registry->get_provider( 'taxonomies' )->get_url_list( 1, 'category' ), 'loc' );
		$this->assertContains( get_term_link( $cat ), $terms );
		$this->assertNotContains( get_term_link( $hidden ), $terms );

		$sitemaps = new WP_Sitemaps();
		$sitemaps->register_sitemaps();
		$this->assertNull( $sitemaps->registry->get_provider( 'users' ), 'No authors with noindex author archives.' );
	}

	public function test_redirects_run_on_the_frontend(): void {
		RedirectRepository::create( '/vecchia', '/nuova', RedirectCode::MovedPermanently );
		RedirectRepository::create( '/promo?id=1', 'https://example.org/', RedirectCode::Found );
		RedirectRepository::create( '/caffè', '/bar?menu=1', RedirectCode::TemporaryRedirect );
		RedirectRepository::create( '/chiuso', null, RedirectCode::Gone );

		$this->assertSame( [ '/nuova', 301 ], $this->redirect( '/vecchia/' ) );
		$this->assertSame( [ '/nuova?utm_source=x', 301 ], $this->redirect( '/vecchia?utm_source=x' ), 'The query goes to the target.' );
		$this->assertSame( [ 'https://example.org/', 302 ], $this->redirect( '/promo?id=1' ), 'An exact match with the query keeps the target.' );
		$this->assertSame( [ '/bar?menu=1&a=b', 307 ], $this->redirect( '/caff%C3%A8?a=b' ) );
		$this->assertNull( $this->redirect( '/Vecchia' ) );

		$status = 0;
		add_filter(
			'status_header',
			static function ( string $header, int $code ) use ( &$status ): string {
				$status = $code;
				return $header;
			},
			10,
			2
		);
		$page = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'chiuso',
			]
		);
		$this->go_to( (string) get_permalink( $page ) );
		$_SERVER['REQUEST_URI'] = '/chiuso/';
		Redirects::run();
		$this->assertSame( 410, $status );
		$this->assertTrue( is_404(), 'A 410 shows the 404 template, even for an existing page.' );
	}

	public function test_a_changed_url_gets_a_301_without_chains_or_loops(): void {
		$parent = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'chi-siamo',
			]
		);
		$child  = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'team',
				'post_parent' => $parent,
			]
		);
		$rename = static fn( string $slug ) => wp_update_post(
			[
				'ID'        => $parent,
				'post_name' => $slug,
			]
		);

		$rename( 'about' );
		$this->assertSame(
			[
				'/chi-siamo'      => '/about/',
				'/chi-siamo/team' => '/about/team/',
			],
			$this->redirects(),
			'To the real URL, without a second redirect for the slash.'
		);
		$this->assertTrue( RedirectRepository::find( '/chi-siamo' )['is_auto'] ?? false );

		$rename( 'storia' );
		$this->assertSame( '/storia/', $this->redirects()['/chi-siamo'], 'A→B→C becomes A→C.' );
		$this->assertSame( '/storia/', $this->redirects()['/about'] );

		$rename( 'chi-siamo' );
		$redirects = $this->redirects();
		$this->assertArrayNotHasKey( '/chi-siamo', $redirects, 'The page is back at its old URL.' );
		$this->assertSame( [ '/about', '/storia' ], array_keys( array_filter( $redirects, static fn( ?string $to ): bool => '/chi-siamo/' === $to ) ) );

		$moved = self::factory()->post->create( [ 'post_name' => 'bozza' ] );
		wp_update_post(
			[
				'ID'          => $moved,
				'post_status' => 'draft',
				'post_name'   => 'bozza-2',
			]
		);
		wp_trash_post( $child );
		$this->assertArrayNotHasKey( '/bozza', $this->redirects(), 'Unpublished content has no new URL.' );

		$term = self::factory()->category->create( [ 'slug' => 'news' ] );
		wp_update_term( $term, 'category', [ 'slug' => 'novita' ] );
		$this->assertSame( '/category/novita/', $this->redirects()['/category/news'] );

		Settings::save( [ 'auto_redirects' => false ] );
		$rename( 'altro' );
		$this->assertArrayNotHasKey( '/chi-siamo', $this->redirects() );
	}

	public function test_llms_txt_never_has_protected_or_noindex_content(): void {
		$cat = self::factory()->category->create(
			[
				'name'        => 'Pizze',
				'description' => 'Le nostre pizze.',
			]
		);
		self::factory()->post->create(
			[
				'post_title'    => 'Margherita',
				'post_content'  => 'Pomodoro e mozzarella.',
				'post_category' => [ $cat ],
			]
		);
		self::factory()->post->create(
			[
				'post_title'    => 'Segreta',
				'post_content'  => 'La ricetta segreta.',
				'post_password' => 'x',
			]
		);
		$noindex = self::factory()->post->create(
			[
				'post_title'   => 'Interna',
				'post_content' => 'Solo per lo staff.',
			]
		);
		update_post_meta( $noindex, '_barrio_seo_index', 'noindex' );
		self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Contatti',
			]
		);
		Settings::save(
			[
				'llms'  => [
					'post_types' => [
						'page'    => 'links',
						'project' => 'exclude',
					],
					'taxonomies' => [ 'post_tag' => 'exclude' ],
				],
				'texts' => [
					'en' => [
						'default_description' => '',
						'llms_intro'          => 'La pizzeria di Mario.',
					],
				],
			]
		);

		$full = Llms::build( true );
		$this->assertStringStartsWith( "# Pizzeria & Forno\n\n> " . home_url( '/' ) . "\n\nLa pizzeria di Mario.\n\n", $full );
		$this->assertStringContainsString( "Pomodoro e mozzarella.\n\n**Date:** ", $full );
		$this->assertStringContainsString( '**Categories:** Pizze', $full );
		$this->assertStringContainsString( '- [Contatti](' . get_permalink( get_page_by_path( 'contatti' ) ) . ")\n", $full );
		$this->assertStringContainsString( '- [Pizze](' . get_term_link( $cat ) . ') — Le nostre pizze.', $full );
		foreach ( [ 'segreta', 'Segreta', 'Interna', 'staff', '## Tags', '## Projects' ] as $hidden ) {
			$this->assertStringNotContainsString( $hidden, $full );
		}

		$this->assertSame( Llms::get( false ), Llms::get( false ) );
		$this->assertIsString( get_transient( 'barrio_seo_llms' ) );
		self::factory()->post->create();
		$this->assertFalse( get_transient( 'barrio_seo_llms' ), 'A changed content empties the cache.' );
	}

	// L'head di un URL: un solo title, description, robots e canonical, e Open Graph coerente.
	private function assert_head( string $url, string $title, ?string $canonical, ?string $type ): void {
		$head  = $this->head( $url );
		$count = static fn( string $xpath ): int => $head->query( $xpath )->length;
		$value = static fn( string $xpath ): ?string => $head->query( $xpath )->item( 0 )?->nodeValue;

		$this->assertSame( 1, $count( '//title' ), $url );
		$this->assertSame( $title, $value( '//title' ), $url );
		$this->assertSame( 1, $count( '//meta[@name="robots"]' ), $url );
		$this->assertSame( null === $canonical ? 0 : 1, $count( '//meta[@name="description"]' ), $url );
		$this->assertSame( null === $canonical ? 0 : 1, $count( '//link[@rel="canonical"]' ), $url );
		$this->assertSame( $canonical, $value( '//link[@rel="canonical"]/@href' ), $url );
		$this->assertSame( $canonical, $value( '//meta[@property="og:url"]/@content' ), $url );
		$this->assertSame( $type, $value( '//meta[@property="og:type"]/@content' ), $url );
		$this->assertSame( $value( '//meta[@name="description"]/@content' ), $value( '//meta[@property="og:description"]/@content' ), $url );
		if ( null !== $canonical ) {
			$this->assertSame( 'Pizzeria & Forno', $value( '//meta[@property="og:site_name"]/@content' ), $url );
			$this->assertSame( 'en_US', $value( '//meta[@property="og:locale"]/@content' ), $url );
		}
	}

	private function head( string $url ): DOMXPath {
		$this->go_to( $url );
		ob_start();
		wp_head();
		$html = (string) ob_get_clean();
		$dom  = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<!doctype html><html><head><meta charset="utf-8">' . $html . '</head></html>' );
		libxml_clear_errors();
		return new DOMXPath( $dom );
	}

	private function robots( string $url ): ?string {
		return $this->head( $url )->query( '//meta[@name="robots"]/@content' )->item( 0 )?->nodeValue;
	}

	/** @return array{string, int}|null Destinazione e codice del redirect, senza uscire. */
	private function redirect( string $request_uri ): ?array {
		$redirect = null;
		$capture  = static function ( string $location, int $status ) use ( &$redirect ): bool {
			$redirect = [ $location, $status ];
			return false;
		};
		add_filter( 'wp_redirect', $capture, 10, 2 );
		$_SERVER['REQUEST_URI'] = $request_uri;
		Redirects::run();
		remove_filter( 'wp_redirect', $capture );
		return $redirect;
	}

	/** @return array<string, string|null> Sorgente => destinazione. */
	private function redirects(): array {
		global $wpdb;
		return array_column( $wpdb->get_results( "SELECT source, target FROM {$wpdb->prefix}barrio_redirects ORDER BY source", ARRAY_A ), 'target', 'source' );
	}
}
