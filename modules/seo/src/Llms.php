<?php

namespace BarrioSuite\Seo;

use BarrioSuite\I18n;
use WP;
use WP_Post;
use WP_Term;

/**
 * llms.txt e llms-full.txt: li genera WordPress alla prima richiesta e li tiene in cache finché un
 * contenuto, un termine, un menu o le impostazioni non cambiano. Solo contenuti indicizzabili e mai quelli
 * protetti da password. Un file vero nella cartella del sito ha la precedenza: il web server lo serve prima.
 */
final class Llms {

	private const FILES = [
		'llms.txt'      => false,
		'llms-full.txt' => true,
	];

	// parse_request.
	public static function serve( WP $wp ): void {
		if ( ! isset( self::FILES[ $wp->request ] ) ) {
			return;
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo self::get( self::FILES[ $wp->request ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- testo semplice, non HTML.
		exit;
	}

	public static function get( bool $full ): string {
		$key  = $full ? 'barrio_seo_llms_full' : 'barrio_seo_llms';
		$text = get_transient( $key );
		if ( ! is_string( $text ) ) {
			$text = self::build( $full );
			// Al più un giorno, per ciò che cambia senza passare dagli hook di flush().
			set_transient( $key, $text, DAY_IN_SECONDS );
		}
		return $text;
	}

	public static function flush(): void {
		delete_transient( 'barrio_seo_llms' );
		delete_transient( 'barrio_seo_llms_full' );
	}

	// Con più lingue un blocco per lingua, la principale per prima, come nel prototipo.
	public static function build( bool $full ): string {
		$languages = I18n::languages();
		return implode(
			"---\n\n",
			array_map( static fn( string $lang ): string => I18n::in( $lang, static fn(): string => self::language( $full, count( $languages ) > 1 ? $lang : '' ) ), $languages )
		);
	}

	/**
	 * Intestazione e introduzione, una sezione per tipo e per tassonomia secondo le impostazioni (Includi,
	 * Solo link, Escludi), poi i menu, nella lingua attuale. llms.txt ha 10 voci per sezione e i riassunti,
	 * llms-full.txt 500 voci e i contenuti.
	 *
	 * @param string $lang Il codice nel titolo, con più lingue.
	 */
	private static function language( bool $full, string $lang ): string {
		$settings = Settings::get()['llms'];
		$limit    = $full ? 500 : 10;
		$intro    = Settings::text( 'llms_intro', I18n::current() );
		// ponytail: con WPML la riga > è l'indirizzo del sito in ogni lingua, perché WPML converte home_url
		// con la lingua della richiesta; i link dei contenuti sono quelli della lingua.
		$md = '# ' . Meta::text( get_bloginfo( 'name' ) ) . ( '' === $lang ? '' : ' (' . strtoupper( $lang ) . ')' )
			. "\n\n> " . Meta::url( home_url( '/' ) ) . "\n\n" . ( '' === $intro ? '' : "{$intro}\n\n" );

		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
			$mode = $settings['post_types'][ $type->name ] ?? 'include';
			/** @var list<WP_Post> $posts */
			$posts = 'attachment' === $type->name || 'exclude' === $mode ? [] : get_posts(
				[
					'post_type'        => $type->name,
					'numberposts'      => $limit,
					'orderby'          => $type->hierarchical ? [
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					] : [ 'date' => 'DESC' ],
					// Con i filtri, perché il plugin multilingua tenga la lingua del blocco.
					'suppress_filters' => false,
				] + Meta::indexable( 'post_types', $type->name )
			);
			if ( $posts ) {
				$md .= "## {$type->labels->name}\n\n";
				foreach ( $posts as $post ) {
					$md .= self::post( $post, $mode, $full, $settings['taxonomies'] );
				}
				$md .= 'links' === $mode ? "\n" : '';
			}
		}

		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $taxonomy ) {
			$mode = $settings['taxonomies'][ $taxonomy->name ] ?? 'include';
			/** @var list<WP_Term>|\WP_Error $terms */
			$terms = 'exclude' === $mode ? [] : get_terms(
				[
					'taxonomy' => $taxonomy->name,
					'number'   => $full ? 0 : $limit,
				] + Meta::indexable( 'taxonomies', $taxonomy->name )
			);
			if ( is_array( $terms ) && $terms ) {
				$md .= "## {$taxonomy->labels->name}\n\n";
				foreach ( $terms as $term ) {
					$description = 'include' === $mode ? Meta::text( $term->description ) : '';
					$md         .= '- ' . self::link( $term->name, get_term_link( $term ) ) . ( '' === $description ? '' : " — {$description}" ) . "\n";
				}
				$md .= "\n";
			}
		}

		$menus = '';
		foreach ( wp_get_nav_menus() as $menu ) {
			$links = [];
			foreach ( wp_get_nav_menu_items( $menu->term_id ) ?: [] as $item ) {
				if ( $item->url && $item->title ) {
					$links[] = self::link( $item->title, $item->url );
				}
			}
			$menus .= $links ? "**{$menu->name}:** " . implode( ' · ', $links ) . "\n" : '';
		}
		return $md . ( '' === $menus ? '' : '## ' . __( 'Navigation', 'barrio-suite' ) . "\n\n{$menus}" );
	}

	/** @param array<string, string> $tax_modes */
	private static function post( WP_Post $post, string $mode, bool $full, array $tax_modes ): string {
		$link = self::link( Meta::post_title( $post ), get_permalink( $post ) );
		if ( 'links' === $mode ) {
			return "- {$link}\n";
		}
		$text = $full ? mb_substr( Meta::text( $post->post_content ), 0, 4000 ) : Meta::post( $post )['description'];
		$info = 'post' === $post->post_type ? '**' . __( 'Date', 'barrio-suite' ) . ':** ' . get_the_date( 'Y-m-d', $post ) . "  \n" : '';
		foreach ( get_object_taxonomies( $post, 'objects' ) as $taxonomy ) {
			$tax_mode = $tax_modes[ $taxonomy->name ] ?? 'include';
			$terms    = $taxonomy->public && 'exclude' !== $tax_mode ? get_the_terms( $post, $taxonomy->name ) : false;
			if ( is_array( $terms ) ) {
				$names = array_map( static fn( WP_Term $term ): string => 'links' === $tax_mode ? self::link( $term->name, get_term_link( $term ) ) : Meta::text( $term->name ), $terms );
				$info .= "**{$taxonomy->labels->name}:** " . implode( ', ', $names ) . "  \n";
			}
		}
		return "### {$link}\n\n" . ( '' === $text ? '' : "{$text}\n\n" ) . ( '' === $info ? '' : "{$info}\n" );
	}

	private static function link( string $text, mixed $url ): string {
		$text = Meta::text( $text );
		return is_string( $url ) && '' !== $url ? "[{$text}](" . Meta::url( $url ) . ')' : $text;
	}
}
