<?php
// Il SEO con lo schema del prototipo: seo sui contenuti, hbSeoSite, hbSchemaRaw, hbSitemap, hbRedirects, hbLlms e
// hbLlmsFull, sopra la risoluzione unica del modulo (Seo\Meta); e l'indirizzo del frontend in GraphQL → Settings.

use BarrioSuite\Seo\Llms;
use BarrioSuite\Seo\Meta;
use BarrioSuite\Seo\Module;
use BarrioSuite\Seo\Repository\RedirectRepository;
use BarrioSuite\Seo\Schema;
use BarrioSuite\Seo\Settings;
use BarrioSuite\Seo\Sitemaps;
use GraphQL\Deferred;
use WPGraphQL\AppContext;
use WPGraphQL\Model\Post;
use WPGraphQL\Model\Term;

defined( 'ABSPATH' ) || exit;

function barrio_wpgraphql_seo_boot(): void {
	// Priorità 9, come per i form: anche il prototipo registra lo schema SEO con il suo modulo spento.
	add_action( 'graphql_register_types', 'barrio_wpgraphql_register_seo', 9 );
	add_filter( 'barrio_seo_url', 'barrio_wpgraphql_frontend_url' );
	register_graphql_settings_field(
		'graphql_general_settings',
		[
			'name'              => 'barrio_frontend_url',
			'label'             => __( 'Frontend address', 'barrio-suite-wpgraphql' ),
			'desc'              => __( 'The address of the headless site, like https://www.example.com. In GraphQL the links of the Barrio Suite SEO (canonical, JSON-LD, llms.txt) point there instead of WordPress; images stay on WordPress.', 'barrio-suite-wpgraphql' ),
			'type'              => 'url',
			'sanitize_callback' => static fn( mixed $value ): string => untrailingslashit( esc_url_raw( (string) $value, [ 'http', 'https' ] ) ),
		]
	);
}

function barrio_wpgraphql_register_seo(): void {
	$string = static fn( string $description = '' ): array => [
		'type'        => 'String',
		'description' => $description,
	];

	register_graphql_object_type(
		'HBSeoPost',
		[
			'description' => 'The SEO data of a content, the same as in the head of WordPress.',
			'fields'      => [
				'title'         => $string( 'The SEO title, or the title of the content: without the site name.' ),
				'description'   => $string( 'The SEO description, or the excerpt, or the start of the content, or the default description.' ),
				'robots'        => $string( 'index or noindex, then follow or nofollow, separated by a comma.' ),
				'canonical'     => $string( 'The absolute URL of the page, on the frontend address when it is set.' ),
				'ogTitle'       => $string( 'Same as title.' ),
				'ogDescription' => $string( 'Same as description.' ),
				'ogImage'       => $string( 'The SEO image, or the featured image, or the default one: on WordPress.' ),
				'ogType'        => $string( 'article for posts, website for the rest.' ),
				'twitterCard'   => $string( 'Always summary_large_image.' ),
				'twitterTitle'  => $string( 'Same as title.' ),
				'twitterImage'  => $string( 'Same as ogImage.' ),
				'isIndexed'     => [ 'type' => 'Boolean' ],
				'isInSitemap'   => [
					'type'        => 'Boolean',
					'description' => 'Published, indexed and without a password.',
				],
				'keywords'      => [
					'type'        => [ 'list_of' => 'String' ],
					'description' => 'The first one is the main keyword. Only for users who can edit the content.',
				],
			],
		]
	);
	foreach ( array_intersect_key( Module::post_types(), get_post_types( [ 'show_in_graphql' => true ] ) ) as $type ) {
		register_graphql_field(
			ucfirst( (string) ( $type->graphql_single_name ?? '' ) ),
			'seo',
			[
				'type'        => 'HBSeoPost',
				'description' => 'The SEO data of Barrio Suite.',
				'resolve'     => static fn( Post $post ): ?array => barrio_wpgraphql_seo( get_post( $post->ID ) ),
			]
		);
	}

	register_graphql_object_type(
		'HBSeoSite',
		[
			'description' => 'The SEO settings of the site.',
			'fields'      => [
				'siteTitle'     => $string( 'The site name.' ),
				'separator'     => $string( 'Between the page title and the site name.' ),
				'titleOrder'    => $string( 'page_site or site_page.' ),
				'defaultDesc'   => $string( 'For the pages without a description of their own.' ),
				'robotsDefault' => $string( 'Always index,follow: every content says its own in seo.robots.' ),
				'ogImage'       => $string( 'The default sharing image.' ),
				'favicon'       => $string( 'The site icon.' ),
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbSeoSite',
		[
			'type'    => 'HBSeoSite',
			'resolve' => 'barrio_wpgraphql_seo_site',
		]
	);

	register_graphql_field(
		'RootQuery',
		'hbSchemaRaw',
		[
			'type'        => 'String',
			'description' => 'The JSON-LD for <script type="application/ld+json">: the organization and the site, plus the page and its breadcrumb when uri is an indexable content or term.',
			'args'        => [ 'uri' => $string( 'The URI of the page, like /about-us/.' ) ],
			// Il nodo di WPGraphQL arriva dopo, in un Deferred: la stessa risoluzione di nodeByUri.
			'resolve'     => static function ( mixed $root, array $args, AppContext $context ): mixed {
				$uri  = trim( (string) ( $args['uri'] ?? '' ) );
				$node = '' === $uri ? null : $context->node_resolver->resolve_uri( $uri );
				return $node instanceof Deferred ? $node->then( 'barrio_wpgraphql_schema' ) : barrio_wpgraphql_schema( $node );
			},
		]
	);

	register_graphql_object_type(
		'HBSitemapEntry',
		[
			'description' => 'An address of the sitemap of WordPress (wp-sitemap.xml).',
			'fields'      => [
				'loc'      => [
					'type'        => [ 'non_null' => 'String' ],
					'description' => 'The path, like /about-us/.',
				],
				'lastmod'  => $string( 'ISO 8601, contents only.' ),
				'type'     => [
					'type'        => [ 'non_null' => 'String' ],
					'description' => 'post, term or user (author archives).',
				],
				'subtype'  => [
					'type'        => [ 'non_null' => 'String' ],
					'description' => 'The post type, the taxonomy or author.',
				],
				'id'       => [
					'type'        => [ 'non_null' => 'Int' ],
					'description' => 'The ID of the content, term or author; 0 for the home page with the latest posts.',
				],
				'title'    => $string( 'The title of the content, the name of the term or of the author.' ),
				'priority' => [
					'type'        => 'Float',
					'description' => 'Always null: search engines ignore it.',
				],
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbSitemap',
		[
			'type'        => [ 'list_of' => 'HBSitemapEntry' ],
			'description' => 'The indexable addresses of every language, like wp-sitemap.xml.',
			'resolve'     => 'barrio_wpgraphql_sitemap',
		]
	);

	register_graphql_object_type(
		'HBRedirect',
		[
			'description' => 'A redirect of the SEO.',
			'fields'      => [
				'id'   => [ 'type' => [ 'non_null' => 'String' ] ],
				'from' => [
					'type'        => [ 'non_null' => 'String' ],
					'description' => 'The path, with the trailing slash of the permalinks unless it has a query string or a file extension.',
				],
				'to'   => $string( 'A path or an absolute URL; null for 410.' ),
				'code' => [
					'type'        => [ 'non_null' => 'Int' ],
					'description' => '301, 302, 307, 308 or 410.',
				],
				'note' => $string( 'Only for who manages the SEO.' ),
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbRedirects',
		[
			'type'    => [ 'list_of' => 'HBRedirect' ],
			'resolve' => 'barrio_wpgraphql_redirects',
		]
	);

	register_graphql_object_type(
		'LlmsContent',
		[
			'description' => 'llms.txt or llms-full.txt.',
			'fields'      => [
				'content'   => $string( 'Markdown.' ),
				'updatedAt' => $string( 'When it was generated, as a Unix timestamp.' ),
				'bytes'     => [ 'type' => 'Int' ],
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbLlms',
		[
			'type'    => 'LlmsContent',
			'resolve' => static fn(): array => barrio_wpgraphql_llms( false ),
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbLlmsFull',
		[
			'type'    => 'LlmsContent',
			'resolve' => static fn(): array => barrio_wpgraphql_llms( true ),
		]
	);
}

/**
 * Nelle risposte GraphQL gli URL delle pagine (barrio_seo_url) sono sull'indirizzo del frontend, se è impostato.
 * Con http o https: home_url() passa a https nelle richieste sicure anche se l'opzione dice http.
 */
function barrio_wpgraphql_frontend_url( string $url ): string {
	$frontend = (string) get_graphql_setting( 'barrio_frontend_url' );
	if ( '' === $frontend || ! is_graphql_request() ) {
		return $url;
	}
	$home = (string) preg_replace( '#^https?:#i', '', untrailingslashit( (string) get_option( 'home' ) ) );
	return preg_match( '#^https?:' . preg_quote( $home, '#' ) . '(?=$|[/?\#])#i', $url, $match ) ? $frontend . substr( $url, strlen( $match[0] ) ) : $url;
}

/**
 * ponytail: i testi predefiniti sono nella lingua della richiesta, non in quella del contenuto, perché il core
 * non sa la lingua di un contenuto; servirà un filtro del plugin multilingua se un sito tradotto ne ha bisogno.
 *
 * @return array<string, mixed>|null
 */
function barrio_wpgraphql_seo( ?WP_Post $post ): ?array {
	if ( null === $post ) {
		return null;
	}
	$meta   = Meta::post( $post );
	$image  = $meta['image'] ? wp_get_attachment_image_url( $meta['image'], 'full' ) : false;
	$robots = ( $meta['index'] ? 'index' : 'noindex' ) . ',' . ( $meta['follow'] ? 'follow' : 'nofollow' );
	return [
		'title'         => $meta['title'],
		'description'   => $meta['description'],
		'robots'        => $robots,
		'canonical'     => $meta['canonical'],
		'ogTitle'       => $meta['title'],
		'ogDescription' => $meta['description'],
		'ogImage'       => $image ?: null,
		'ogType'        => 'post' === $post->post_type ? 'article' : 'website',
		'twitterCard'   => 'summary_large_image',
		'twitterTitle'  => $meta['title'],
		'twitterImage'  => $image ?: null,
		'isIndexed'     => $meta['index'],
		// Come la sitemap (Seo\Meta::indexable()).
		'isInSitemap'   => $meta['index'] && 'publish' === $post->post_status && '' === $post->post_password,
		// Come nella REST del core: servono al punteggio, non alle pagine.
		'keywords'      => current_user_can( 'edit_post', $post->ID ) ? $meta['keywords'] : [],
	];
}

/** @return array<string, string|null> */
function barrio_wpgraphql_seo_site(): array {
	$settings = Settings::get();
	return [
		'siteTitle'     => Meta::text( get_bloginfo( 'name' ) ),
		'separator'     => $settings['title']['separator'],
		'titleOrder'    => $settings['title']['order'],
		'defaultDesc'   => Meta::default_description(),
		'robotsDefault' => 'index,follow',
		'ogImage'       => wp_get_attachment_image_url( $settings['default_image'], 'full' ) ?: null,
		'favicon'       => wp_get_attachment_image_url( (int) get_option( 'site_icon' ), 'full' ) ?: null,
	];
}

/**
 * Il grafo di Seo\Schema. Come nell'head, la pagina c'è solo se è indicizzabile; restano organizzazione e sito.
 *
 * @param mixed $node Il nodo dell'URI secondo WPGraphQL: un contenuto, un termine, un archivio o null.
 */
function barrio_wpgraphql_schema( mixed $node ): string {
	$object = match ( true ) {
		$node instanceof Post => get_post( $node->ID ),
		$node instanceof Term => get_term( $node->term_id ),
		default               => null,
	};
	$meta = match ( true ) {
		$object instanceof WP_Post => Meta::post( $object ),
		$object instanceof WP_Term => Meta::term( $object ),
		default                    => null,
	};
	$graph = null !== $meta && $meta['index'] ? Schema::graph( $meta, $object ) : Schema::graph( null );
	return (string) wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG );
}

/**
 * Gli indirizzi di wp-sitemap.xml in tutte le lingue (Seo\Sitemaps::each()), con il contenuto, il termine o
 * l'autore che il core passa ai filtri delle voci. loc è relativo, come nel prototipo: il frontend aggiunge il suo dominio.
 *
 * @return list<array<string, mixed>>
 */
function barrio_wpgraphql_sitemap(): array {
	$filters = [
		'wp_sitemaps_posts_entry'               => static fn( array $entry, WP_Post $post ): array => $entry + [
			'type'    => 'post',
			'subtype' => $post->post_type,
			'id'      => $post->ID,
			'title'   => Meta::text( $post->post_title ),
		],
		// La home con gli ultimi articoli, prima delle pagine.
		'wp_sitemaps_posts_show_on_front_entry' => static fn( array $entry ): array => $entry + [
			'type'    => 'post',
			'subtype' => 'page',
			'id'      => 0,
			'title'   => Meta::text( get_bloginfo( 'name' ) ),
		],
		'wp_sitemaps_taxonomies_entry'          => static fn( array $entry, int $term_id, string $taxonomy, WP_Term $term ): array => $entry + [
			'type'    => 'term',
			'subtype' => $taxonomy,
			'id'      => $term_id,
			'title'   => Meta::text( $term->name ),
		],
		'wp_sitemaps_users_entry'               => static fn( array $entry, WP_User $user ): array => $entry + [
			'type'    => 'user',
			'subtype' => 'author',
			'id'      => $user->ID,
			'title'   => Meta::text( $user->display_name ),
		],
	];
	foreach ( $filters as $hook => $filter ) {
		add_filter( $hook, $filter, 10, 4 );
	}
	$entries = [];
	try {
		Sitemaps::each(
			static function ( string $provider, string $subtype, array $urls ) use ( &$entries ): void {
				foreach ( $urls as $url ) {
					$entries[] = [
						'loc'      => wp_make_link_relative( (string) $url['loc'] ),
						'lastmod'  => $url['lastmod'] ?? null,
						'type'     => $url['type'] ?? $provider,
						'subtype'  => $url['subtype'] ?? $subtype,
						'id'       => $url['id'] ?? 0,
						'title'    => $url['title'] ?? null,
						'priority' => null,
					];
				}
			}
		);
	} finally {
		foreach ( $filters as $hook => $filter ) {
			remove_filter( $hook, $filter );
		}
	}
	return $entries;
}

/** @return list<array<string, mixed>> */
function barrio_wpgraphql_redirects(): array {
	$notes = current_user_can( 'barrio_manage_seo' );
	return array_map(
		static fn( array $redirect ): array => [
			'id'   => (string) $redirect['id'],
			// Le sorgenti sono senza / finale; i frontend del prototipo le confrontano con il percorso della pagina,
			// che ha quella dei permalink (trailingSlash di Next.js). I file come /pagina.html non l'hanno.
			'from' => '/' === $redirect['source'] || str_contains( $redirect['source'], '?' ) || str_contains( basename( $redirect['source'] ), '.' )
				? $redirect['source'] : user_trailingslashit( $redirect['source'] ),
			'to'   => $redirect['target'],
			'code' => $redirect['code']->value,
			'note' => $notes ? $redirect['note'] : null,
		],
		RedirectRepository::all()
	);
}

/** @return array{content: string, updatedAt: string, bytes: int} */
function barrio_wpgraphql_llms( bool $full ): array {
	$llms = Llms::get( $full );
	return [
		'content'   => $llms['text'],
		'updatedAt' => (string) $llms['time'],
		'bytes'     => strlen( $llms['text'] ),
	];
}
