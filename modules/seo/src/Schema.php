<?php

namespace BarrioSuite\Seo;

use BarrioSuite\I18n;
use WP_Post;
use WP_Term;

/**
 * Il grafo JSON-LD di una pagina indicizzabile: l'organizzazione (le impostazioni SEO), il sito, la pagina
 * e il suo breadcrumb. Le pagine con un post type in _barrio_seo_collection sono CollectionPage con
 * l'ItemList di quel tipo; gli archivi sono CollectionPage.
 *
 * @phpstan-import-type Resolved from Meta
 */
final class Schema {

	/**
	 * @param Resolved|null        $meta    Null per il solo sito: l'organizzazione e WebSite, senza la pagina.
	 * @param WP_Post|WP_Term|null $queried Il contenuto o il termine della pagina; null per gli altri archivi.
	 * @return array<string, mixed>
	 */
	public static function graph( ?array $meta, WP_Post|WP_Term|null $queried = null ): array {
		$home  = Meta::url( home_url( '/' ) );
		$lang  = str_replace( '_', '-', I18n::locale( I18n::current() ) );
		$graph = [
			self::organization( Settings::get()['organization'], $home, Settings::text( 'organization_description', I18n::current() ) ),
			self::clean(
				[
					'@type'       => 'WebSite',
					'@id'         => "{$home}#website",
					'url'         => $home,
					'name'        => Meta::text( get_bloginfo( 'name' ) ),
					'description' => Meta::default_description(),
					'inLanguage'  => $lang,
					'publisher'   => [ '@id' => "{$home}#organization" ],
				]
			),
		];
		if ( null !== $meta ) {
			$crumbs = self::breadcrumb( $meta, $queried, $home );
			$page   = [
				'@type'              => $queried instanceof WP_Post ? 'WebPage' : 'CollectionPage',
				'@id'                => "{$meta['canonical']}#webpage",
				'url'                => $meta['canonical'],
				'name'               => $meta['title'],
				'description'        => $meta['description'],
				'inLanguage'         => $lang,
				'isPartOf'           => [ '@id' => "{$home}#website" ],
				'primaryImageOfPage' => self::image( $meta['image'] ),
				'keywords'           => implode( ', ', $meta['keywords'] ),
				'breadcrumb'         => $crumbs ? [ '@id' => $crumbs['@id'] ] : null,
			];
			if ( $queried instanceof WP_Post ) {
				$page['datePublished'] = get_post_time( DATE_W3C, true, $queried );
				$page['dateModified']  = get_post_modified_time( DATE_W3C, true, $queried );
				$list                  = self::item_list( (string) get_post_meta( $queried->ID, '_barrio_seo_collection', true ) );
				if ( $list ) {
					$page['@type']      = 'CollectionPage';
					$page['mainEntity'] = $list;
				}
			}
			$graph[] = self::clean( $page );
			$graph[] = $crumbs;
		}

		return [
			'@context' => 'https://schema.org',
			'@graph'   => array_values( array_filter( $graph ) ),
		];
	}

	/**
	 * @param array<string, mixed> $o L'organizzazione delle impostazioni SEO.
	 * @return array<string, mixed>
	 */
	public static function organization( array $o, string $home, string $description ): array {
		$address = self::clean(
			[
				'@type'           => 'PostalAddress',
				'streetAddress'   => $o['address']['street'],
				'postalCode'      => $o['address']['postal_code'],
				'addressLocality' => $o['address']['locality'],
				'addressRegion'   => $o['address']['region'],
				'addressCountry'  => $o['address']['country'],
			]
		);
		$offers  = array_map(
			static fn( array $item ): array => self::clean(
				[
					'@type'         => 'Offer',
					'itemOffered'   => self::clean(
						[
							'@type'       => $item['type'],
							'name'        => $item['name'],
							'description' => $item['description'],
							'category'    => $item['category'],
							'url'         => $item['url'],
							'image'       => $item['image'] ? wp_get_attachment_image_url( $item['image'], 'full' ) : null,
						]
					),
					'url'           => $item['url'],
					'price'         => $item['price'],
					'priceCurrency' => null === $item['price'] ? null : $o['offers']['currency'],
					'availability'  => $item['availability'] ? "https://schema.org/{$item['availability']}" : null,
				]
			),
			$o['offers']['items']
		);
		$geo     = $o['geo'];

		return self::clean(
			[
				'@type'           => $o['type'],
				'@id'             => "{$home}#organization",
				'name'            => $o['name'] ?: Meta::text( get_bloginfo( 'name' ) ),
				'url'             => $home,
				'description'     => $description,
				'logo'            => self::image( $o['logo'] ),
				'image'           => self::image( $o['image'] ),
				'telephone'       => $o['phone'],
				'email'           => $o['email'],
				'vatID'           => $o['vat_id'],
				'address'         => count( $address ) > 1 ? $address : null,
				'geo'             => null === $geo['latitude'] || null === $geo['longitude'] ? null : [
					'@type'     => 'GeoCoordinates',
					'latitude'  => $geo['latitude'],
					'longitude' => $geo['longitude'],
				],
				'areaServed'      => array_map(
					static fn( array $area ): array => [
						'@type' => $area['type'],
						'name'  => $area['name'],
					],
					$o['area_served']
				),
				'knowsAbout'      => $o['knows_about'],
				'sameAs'          => $o['same_as'],
				'hasOfferCatalog' => $offers ? self::clean(
					[
						'@type'           => 'OfferCatalog',
						'name'            => $o['offers']['name'],
						'itemListElement' => $offers,
					]
				) : null,
			]
		);
	}

	/**
	 * Home, antenati (le pagine genitore, o i termini genitore), pagina. La home non ne ha.
	 *
	 * @param Resolved $meta
	 * @return array<string, mixed>|null
	 */
	private static function breadcrumb( array $meta, WP_Post|WP_Term|null $queried, string $home ): ?array {
		if ( $meta['canonical'] === $home ) {
			return null;
		}
		$crumbs = [ [ Meta::text( get_bloginfo( 'name' ) ), $home ] ];
		if ( $queried instanceof WP_Post ) {
			foreach ( array_reverse( get_post_ancestors( $queried ) ) as $id ) {
				$crumbs[] = [ Meta::text( get_the_title( $id ) ), Meta::url( (string) get_permalink( $id ) ) ];
			}
		} elseif ( $queried instanceof WP_Term ) {
			foreach ( array_reverse( get_ancestors( $queried->term_id, $queried->taxonomy, 'taxonomy' ) ) as $id ) {
				$term     = get_term( $id, $queried->taxonomy );
				$link     = get_term_link( $id, $queried->taxonomy );
				$crumbs[] = [ $term instanceof WP_Term ? Meta::text( $term->name ) : '', is_string( $link ) ? Meta::url( $link ) : '' ];
			}
		}
		$name = match ( true ) {
			$queried instanceof WP_Post => Meta::text( $queried->post_title ),
			$queried instanceof WP_Term => Meta::text( $queried->name ),
			default                     => $meta['title'],
		};
		$crumbs[] = [ $name, $meta['canonical'] ];

		return [
			'@type'           => 'BreadcrumbList',
			'@id'             => "{$meta['canonical']}#breadcrumb",
			'itemListElement' => array_map(
				static fn( array $crumb, int $i ): array => [
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => $crumb[0],
					'item'     => $crumb[1],
				],
				$crumbs,
				array_keys( $crumbs )
			),
		];
	}

	/**
	 * I contenuti indicizzabili di un post type nella lingua della pagina, per una CollectionPage.
	 * ponytail: al più 200 voci; oltre, un ItemList a pagine.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function item_list( string $post_type ): ?array {
		if ( '' === $post_type || ! is_post_type_viewable( $post_type ) ) {
			return null;
		}
		/** @var list<WP_Post> $posts */
		$posts = get_posts(
			[
				'post_type'        => $post_type,
				'numberposts'      => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- il limite dell'ItemList.
				// Con i filtri, perché il plugin multilingua tenga la lingua della pagina.
				'suppress_filters' => false,
				'orderby'          => [
					'menu_order' => 'ASC',
					'date'       => 'DESC',
				],
			] + Meta::indexable( 'post_types', $post_type )
		);
		return $posts ? [
			'@type'           => 'ItemList',
			'numberOfItems'   => count( $posts ),
			'itemListElement' => array_map(
				static fn( WP_Post $post, int $i ): array => [
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => Meta::post_title( $post ),
					'url'      => Meta::url( (string) get_permalink( $post ) ),
				],
				$posts,
				array_keys( $posts )
			),
		] : null;
	}

	/** @return array<string, mixed>|null Le immagini restano su WordPress, anche nei siti headless. */
	private static function image( int $id ): ?array {
		$image = $id ? wp_get_attachment_image_src( $id, 'full' ) : false;
		return $image ? [
			'@type'  => 'ImageObject',
			'url'    => $image[0],
			'width'  => $image[1],
			'height' => $image[2],
		] : null;
	}

	/**
	 * Senza le proprietà vuote; 0 resta (un prezzo, una coordinata).
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private static function clean( array $node ): array {
		return array_filter( $node, static fn( mixed $value ): bool => null !== $value && '' !== $value && [] !== $value && false !== $value );
	}
}
