<?php
// Il SEO in GraphQL: seo su contenuti e termini, hbSeoSettings, hbSeoSchema, hbSitemap, hbRedirects, hbLlms e
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
	// Gli hook che svuotano llms.txt (Seo\Module::boot()): con quelli cambia anche la sitemap.
	foreach ( [ 'clean_post_cache', 'clean_term_cache', 'add_option_barrio_seo', 'update_option_barrio_seo', 'update_option_blogname' ] as $hook ) {
		add_action( $hook, 'barrio_wpgraphql_sitemap_flush' );
	}
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
	register_graphql_object_type(
		'HbSeo',
		[
			'description' => 'The SEO data of a content or a term, the same as in the head of WordPress.',
			'fields'      => [
				'title'         => barrio_wpgraphql_string( 'The SEO title, or the title of the content or the name of the term: without the site name.' ),
				'description'   => barrio_wpgraphql_string( 'The SEO description, or the excerpt or the term description, or the start of the content, or the default description.' ),
				'robots'        => barrio_wpgraphql_string( 'index or noindex, then follow or nofollow, separated by a comma.' ),
				'canonical'     => barrio_wpgraphql_string( 'The absolute URL of the page, on the frontend address when it is set.' ),
				'ogTitle'       => barrio_wpgraphql_string( 'Same as title.' ),
				'ogDescription' => barrio_wpgraphql_string( 'Same as description.' ),
				'ogImage'       => barrio_wpgraphql_string( 'The SEO image, or the featured image, or the default one: on WordPress.' ),
				'ogType'        => barrio_wpgraphql_string( 'article for posts, website for the rest.' ),
				'twitterCard'   => barrio_wpgraphql_string( 'Always summary_large_image.' ),
				'twitterTitle'  => barrio_wpgraphql_string( 'Same as title.' ),
				'twitterImage'  => barrio_wpgraphql_string( 'Same as ogImage.' ),
				'isIndexed'     => barrio_wpgraphql_required( 'Boolean' ),
				'isInSitemap'   => barrio_wpgraphql_required( 'Boolean', 'Indexed and in wp-sitemap.xml: a published content without a password, a term with contents.' ),
				'keywords'      => [
					'type'        => [ 'non_null' => [ 'list_of' => [ 'non_null' => 'String' ] ] ],
					'description' => 'The first one is the main keyword. Only for users who can edit the content or the term.',
				],
			],
		]
	);
	$seo = static fn( callable $resolve ): array => [
		'type'        => 'HbSeo',
		'description' => 'The SEO data of Barrio Suite.',
		'resolve'     => $resolve,
	];
	foreach ( array_intersect_key( Module::post_types(), get_post_types( [ 'show_in_graphql' => true ] ) ) as $type ) {
		register_graphql_field(
			ucfirst( (string) ( $type->graphql_single_name ?? '' ) ),
			'seo',
			$seo( static fn( Post $post ): ?array => barrio_wpgraphql_seo( barrio_wpgraphql_seo_post( $post ) ) )
		);
	}
	foreach ( array_intersect_key( Module::taxonomies(), get_taxonomies( [ 'show_in_graphql' => true ] ) ) as $taxonomy ) {
		register_graphql_field(
			ucfirst( (string) ( $taxonomy->graphql_single_name ?? '' ) ),
			'seo',
			$seo(
				static function ( Term $node ): ?array {
					$term = get_term( $node->term_id );
					return barrio_wpgraphql_seo( $term instanceof WP_Term ? $term : null );
				}
			)
		);
	}

	register_graphql_enum_type(
		'HbSeoTitleOrderEnum',
		[
			'description' => 'The order of the page title and the site name in the title tag.',
			'values'      => [
				'PAGE_SITE' => [ 'value' => 'page_site' ],
				'SITE_PAGE' => [ 'value' => 'site_page' ],
			],
		]
	);
	register_graphql_object_type(
		'HbSeoSettings',
		[
			'description' => 'The SEO settings of the site.',
			'fields'      => [
				'siteTitle'          => barrio_wpgraphql_string( 'The site name.' ),
				'separator'          => barrio_wpgraphql_string( 'Between the page title and the site name.' ),
				'titleOrder'         => barrio_wpgraphql_required( 'HbSeoTitleOrderEnum' ),
				'defaultDescription' => barrio_wpgraphql_string( 'For the pages without a description of their own.' ),
				'ogImage'            => barrio_wpgraphql_string( 'The default sharing image.' ),
				'favicon'            => barrio_wpgraphql_string( 'The site icon.' ),
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbSeoSettings',
		[
			'type'    => 'HbSeoSettings',
			'resolve' => 'barrio_wpgraphql_seo_settings',
		]
	);

	register_graphql_field(
		'RootQuery',
		'hbSeoSchema',
		[
			'type'        => 'String',
			'description' => 'The JSON-LD for <script type="application/ld+json">: the organization and the site, plus the page and its breadcrumb when uri is an indexable content or term.',
			'args'        => [ 'uri' => barrio_wpgraphql_string( 'The URI of the page, like /about-us/.' ) ],
			// Il nodo di WPGraphQL arriva dopo, in un Deferred: la stessa risoluzione di nodeByUri.
			'resolve'     => static function ( mixed $root, array $args, AppContext $context ): mixed {
				$uri  = trim( (string) ( $args['uri'] ?? '' ) );
				$node = '' === $uri ? null : $context->node_resolver->resolve_uri( $uri );
				return $node instanceof Deferred ? $node->then( 'barrio_wpgraphql_schema' ) : barrio_wpgraphql_schema( $node );
			},
		]
	);

	register_graphql_object_type(
		'HbSitemapEntry',
		[
			'description' => 'An address of the sitemap of WordPress (wp-sitemap.xml).',
			'fields'      => [
				'loc'        => barrio_wpgraphql_required( 'String', 'The path, like /about-us/.' ),
				'lastmod'    => barrio_wpgraphql_string( 'ISO 8601, contents only.' ),
				'type'       => barrio_wpgraphql_required( 'String', 'post, term or user (author archives).' ),
				'subtype'    => barrio_wpgraphql_required( 'String', 'The post type, the taxonomy or author.' ),
				'databaseId' => barrio_wpgraphql_required( 'Int', 'The ID of the content, term or author; 0 for the home page with the latest posts.' ),
				'title'      => barrio_wpgraphql_string( 'The title of the content, the name of the term or of the author.' ),
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbSitemap',
		[
			'type'        => [ 'list_of' => [ 'non_null' => 'HbSitemapEntry' ] ],
			'description' => 'The indexable addresses of every language, like wp-sitemap.xml.',
			'resolve'     => 'barrio_wpgraphql_sitemap',
		]
	);

	register_graphql_object_type(
		'HbRedirect',
		[
			'description' => 'A redirect of the SEO.',
			'fields'      => [
				'databaseId' => barrio_wpgraphql_required( 'Int' ),
				'from'       => barrio_wpgraphql_required( 'String', 'The path, with the trailing slash of the permalinks unless it has a query string or a file extension.' ),
				'to'         => barrio_wpgraphql_string( 'A path or an absolute URL; null for 410.' ),
				'code'       => barrio_wpgraphql_required( 'Int', '301, 302, 307, 308 or 410.' ),
				'note'       => barrio_wpgraphql_string( 'Only for who manages the SEO.' ),
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbRedirects',
		[
			'type'    => [ 'list_of' => [ 'non_null' => 'HbRedirect' ] ],
			'resolve' => 'barrio_wpgraphql_redirects',
		]
	);

	register_graphql_object_type(
		'HbLlms',
		[
			'description' => 'llms.txt or llms-full.txt.',
			'fields'      => [
				'content'   => barrio_wpgraphql_required( 'String', 'Markdown.' ),
				'updatedAt' => barrio_wpgraphql_required( 'String', 'When it was generated: ISO 8601 in UTC.' ),
				'bytes'     => barrio_wpgraphql_required( 'Int' ),
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbLlms',
		[
			'type'    => 'HbLlms',
			'resolve' => static fn(): array => barrio_wpgraphql_llms( false ),
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbLlmsFull',
		[
			'type'    => 'HbLlms',
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
 * Il contenuto di un nodo, o null se chi chiede non lo può leggere: di una bozza altrui WPGraphQL dà solo il titolo.
 * Quel nodo non ha ID, ma ha databaseId; senza, get_post() darebbe il post globale.
 */
function barrio_wpgraphql_seo_post( Post $node ): ?WP_Post {
	$id   = (int) $node->databaseId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- il nome di WPGraphQL.
	$post = $id ? get_post( $id ) : null;
	return $post && ( is_post_publicly_viewable( $post ) || current_user_can( 'edit_post', $id ) ) ? $post : null;
}

/**
 * ponytail: i testi predefiniti sono nella lingua della richiesta, non in quella del contenuto, perché il core
 * non sa la lingua di un contenuto; servirà un filtro del plugin multilingua se un sito tradotto ne ha bisogno.
 *
 * @return array<string, mixed>|null
 */
function barrio_wpgraphql_seo( WP_Post|WP_Term|null $item ): ?array {
	if ( null === $item ) {
		return null;
	}
	$post   = $item instanceof WP_Post;
	$meta   = $post ? Meta::post( $item ) : Meta::term( $item );
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
		'ogType'        => $post && 'post' === $item->post_type ? 'article' : 'website',
		'twitterCard'   => 'summary_large_image',
		'twitterTitle'  => $meta['title'],
		'twitterImage'  => $image ?: null,
		'isIndexed'     => $meta['index'],
		// Come la sitemap (Seo\Meta::indexable()); i termini vuoti il core li lascia fuori (hide_empty).
		'isInSitemap'   => $meta['index'] && ( $post ? 'publish' === $item->post_status && '' === $item->post_password : $item->count > 0 ),
		// Come nella REST del core: servono al punteggio, non alle pagine.
		'keywords'      => ( $post ? current_user_can( 'edit_post', $item->ID ) : current_user_can( 'edit_term', $item->term_id ) ) ? $meta['keywords'] : [],
	];
}

/** @return array<string, string|null> */
function barrio_wpgraphql_seo_settings(): array {
	$settings = Settings::get();
	return [
		'siteTitle'          => Meta::text( get_bloginfo( 'name' ) ),
		'separator'          => $settings['title']['separator'],
		'titleOrder'         => $settings['title']['order'],
		'defaultDescription' => Meta::default_description(),
		'ogImage'            => wp_get_attachment_image_url( $settings['default_image'], 'full' ) ?: null,
		'favicon'            => wp_get_attachment_image_url( (int) get_option( 'site_icon' ), 'full' ) ?: null,
	];
}

/**
 * Il grafo di Seo\Schema. Come nell'head, la pagina c'è solo se è indicizzabile; restano organizzazione e sito.
 *
 * @param mixed $node Il nodo dell'URI secondo WPGraphQL: un contenuto, un termine, un archivio o null.
 */
function barrio_wpgraphql_schema( mixed $node ): string {
	$object = match ( true ) {
		$node instanceof Post => barrio_wpgraphql_seo_post( $node ),
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
 * l'autore che il core passa ai filtri delle voci. loc è relativo: il frontend aggiunge il suo dominio.
 *
 * Il campo è pubblico e leggere tutti gli URL costa: dalla cache, per un giorno al più, come llms.txt.
 * ponytail: tutta la sitemap in un transient; con decine di migliaia di URL serve una cache per pagina.
 *
 * @return list<array<string, mixed>>
 */
function barrio_wpgraphql_sitemap(): array {
	/** @var list<array<string, mixed>>|false $entries */
	$entries = get_transient( 'barrio_wpgraphql_sitemap_entries' );
	if ( is_array( $entries ) ) {
		return $entries;
	}
	$filters = [
		'wp_sitemaps_posts_entry'               => static fn( array $entry, WP_Post $post ): array => $entry + [
			'type'       => 'post',
			'subtype'    => $post->post_type,
			'databaseId' => $post->ID,
			'title'      => Meta::text( $post->post_title ),
		],
		// La home con gli ultimi articoli, prima delle pagine.
		'wp_sitemaps_posts_show_on_front_entry' => static fn( array $entry ): array => $entry + [
			'type'       => 'post',
			'subtype'    => 'page',
			'databaseId' => 0,
			'title'      => Meta::text( get_bloginfo( 'name' ) ),
		],
		'wp_sitemaps_taxonomies_entry'          => static fn( array $entry, int $term_id, string $taxonomy, WP_Term $term ): array => $entry + [
			'type'       => 'term',
			'subtype'    => $taxonomy,
			'databaseId' => $term_id,
			'title'      => Meta::text( $term->name ),
		],
		'wp_sitemaps_users_entry'               => static fn( array $entry, WP_User $user ): array => $entry + [
			'type'       => 'user',
			'subtype'    => 'author',
			'databaseId' => $user->ID,
			'title'      => Meta::text( $user->display_name ),
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
						'loc'        => wp_make_link_relative( (string) $url['loc'] ),
						'lastmod'    => $url['lastmod'] ?? null,
						'type'       => $url['type'] ?? $provider,
						'subtype'    => $url['subtype'] ?? $subtype,
						'databaseId' => $url['databaseId'] ?? 0,
						'title'      => $url['title'] ?? null,
					];
				}
			}
		);
	} finally {
		foreach ( $filters as $hook => $filter ) {
			remove_filter( $hook, $filter );
		}
	}
	set_transient( 'barrio_wpgraphql_sitemap_entries', $entries, DAY_IN_SECONDS );
	return $entries;
}

// Su ogni clean_post_cache: con la cache già vuota, nessuna query.
function barrio_wpgraphql_sitemap_flush(): void {
	if ( false !== get_transient( 'barrio_wpgraphql_sitemap_entries' ) ) {
		delete_transient( 'barrio_wpgraphql_sitemap_entries' );
	}
}

/** @return list<array<string, mixed>> */
function barrio_wpgraphql_redirects(): array {
	$notes = current_user_can( 'barrio_manage_seo' );
	return array_map(
		static fn( array $redirect ): array => [
			'databaseId' => $redirect['id'],
			// Le sorgenti sono senza / finale; i frontend le confrontano con il percorso della pagina, che ha
			// quella dei permalink (trailingSlash di Next.js). I file come /pagina.html non l'hanno.
			'from'       => '/' === $redirect['source'] || str_contains( $redirect['source'], '?' ) || str_contains( basename( $redirect['source'] ), '.' )
				? $redirect['source'] : user_trailingslashit( $redirect['source'] ),
			'to'         => $redirect['target'],
			'code'       => $redirect['code']->value,
			'note'       => $notes ? $redirect['note'] : null,
		],
		RedirectRepository::all()
	);
}

/** @return array{content: string, updatedAt: string, bytes: int} */
function barrio_wpgraphql_llms( bool $full ): array {
	$llms = Llms::get( $full );
	return [
		'content'   => $llms['text'],
		'updatedAt' => gmdate( 'c', $llms['time'] ),
		'bytes'     => strlen( $llms['text'] ),
	];
}
