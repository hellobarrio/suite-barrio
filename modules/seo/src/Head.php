<?php

namespace BarrioSuite\Seo;

use BarrioSuite\I18n;
use WP_Post;
use WP_Term;

/**
 * L'head delle pagine del sito. Titolo, robots e canonical dei singoli passano dalle API del core, così
 * non escono due volte; descrizione, canonical degli archivi, Open Graph, Twitter e JSON-LD li stampa
 * print(). Il titolo arriva nel <title> con i temi che dichiarano title-tag (tutti i temi a blocchi).
 */
final class Head {

	// pre_get_document_title: '' lascia il titolo al core.
	public static function title(): string {
		$title = Meta::current()['title'];
		return '' === $title ? '' : esc_html( Meta::document_title( $title, max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) ) ) );
	}

	/**
	 * Il core aggiunge già max-image-preview e il noindex di ricerca, embed e siti nascosti ai motori.
	 *
	 * @param array<string, bool|string> $robots
	 * @return array<string, bool|string>
	 */
	public static function robots( array $robots ): array {
		$meta = Meta::current();
		return $robots + array_filter(
			[
				'noindex'  => ! $meta['index'],
				'nofollow' => ! $meta['follow'],
			]
		);
	}

	public static function print(): void {
		$meta = Meta::current();
		if ( '' !== $meta['description'] ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $meta['description'] ) );
		}
		if ( '' === $meta['canonical'] ) {
			return;
		}
		if ( ! is_singular() ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $meta['canonical'] ) );
		}

		$image = $meta['image'] ? wp_get_attachment_image_src( $meta['image'], 'full' ) : false;
		$og    = [
			'og:type'         => is_singular( 'post' ) ? 'article' : 'website',
			'og:title'        => $meta['title'],
			'og:description'  => $meta['description'],
			'og:url'          => $meta['canonical'],
			'og:site_name'    => Meta::text( get_bloginfo( 'name' ) ),
			'og:locale'       => I18n::locale( I18n::current() ),
			'og:image'        => $image ? $image[0] : '',
			'og:image:width'  => $image ? (string) $image[1] : '',
			'og:image:height' => $image ? (string) $image[2] : '',
		];
		foreach ( array_filter( $og ) as $property => $content ) {
			printf( '<meta property="%s" content="%s" />' . "\n", esc_attr( $property ), esc_attr( $content ) );
		}
		// Titolo, descrizione e immagine X li legge da Open Graph.
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";

		if ( $meta['index'] ) {
			$queried = get_queried_object();
			wp_print_inline_script_tag(
				(string) wp_json_encode( Schema::graph( $meta, $queried instanceof WP_Post || $queried instanceof WP_Term ? $queried : null ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ),
				[ 'type' => 'application/ld+json' ]
			);
		}
	}
}
