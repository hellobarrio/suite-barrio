<?php

namespace BarrioSuite\Seo;

use BarrioSuite\I18n;
use WP_Post;
use WP_Post_Type;
use WP_Term;
use WP_User;

/**
 * La risoluzione unica di titolo, descrizione, immagine, parole chiave, robots e canonical: la usano head,
 * sitemap, schema, llms.txt e l'estensione WPGraphQL. Un meta assente eredita dal contenuto o dal termine,
 * poi dalle impostazioni del tipo o della tassonomia, poi da quelle del sito. I testi sono senza HTML né
 * entità: l'escape si fa quando si stampano. Gli URL delle pagine passano dal filtro barrio_seo_url.
 *
 * @phpstan-type Resolved array{title: string, description: string, image: int, keywords: list<string>, index: bool, follow: bool, canonical: string}
 */
final class Meta {

	/** @return Resolved La pagina che si sta mostrando. Ricerca e 404 non hanno canonical. */
	public static function current(): array {
		$queried = get_queried_object();
		if ( is_404() || is_search() ) {
			$title = is_404()
				? __( 'Page not found', 'barrio-suite' )
				/* translators: %s: search terms. */
				: sprintf( __( 'Search results for “%s”', 'barrio-suite' ), get_search_query( false ) );
			return self::archive( $title, '', false, true );
		}
		if ( $queried instanceof WP_Post && is_singular() ) {
			$meta = self::post( $queried );
			// Come quella che stampa il core (rel_canonical), con le pagine di un contenuto diviso.
			$meta['canonical'] = self::url( (string) wp_get_canonical_url( $queried ) );
			return $meta;
		}

		if ( $queried instanceof WP_Post ) {
			// La pagina degli articoli.
			$meta = self::post( $queried );
		} elseif ( $queried instanceof WP_Term ) {
			$meta = self::term( $queried );
		} elseif ( $queried instanceof WP_Post_Type ) {
			$robots = self::robots( 'post_types', $queried->name );
			$meta   = self::archive( self::archive_title(), (string) get_post_type_archive_link( $queried->name ), $robots['index'], $robots['follow'] );
		} elseif ( $queried instanceof WP_User ) {
			$robots = self::robots( 'archives', 'author' );
			$meta   = self::archive( self::archive_title(), get_author_posts_url( $queried->ID ), $robots['index'], $robots['follow'] );
		} elseif ( is_date() ) {
			$robots = self::robots( 'archives', 'date' );
			$year   = (int) get_the_date( 'Y' );
			$link   = match ( true ) {
				is_day()   => get_day_link( $year, (int) get_the_date( 'm' ), (int) get_the_date( 'd' ) ),
				is_month() => get_month_link( $year, (int) get_the_date( 'm' ) ),
				default    => get_year_link( $year ),
			};
			$meta = self::archive( self::archive_title(), $link, $robots['index'], $robots['follow'] );
		} elseif ( is_home() ) {
			// La home con gli articoli: il motto, come fanno il core e gli altri plugin SEO.
			$meta = self::archive( self::text( get_bloginfo( 'description' ) ) ?: self::text( get_bloginfo( 'name' ) ), home_url( '/' ), true, true );
		} else {
			// Una pagina che il modulo non conosce (feed, embed…): il titolo resta al core.
			return self::archive( '', '', true, true );
		}
		return self::paged( $meta, (int) get_query_var( 'paged' ) );
	}

	/** @return Resolved */
	public static function post( WP_Post $post ): array {
		$meta     = static fn( string $key ): mixed => get_post_meta( $post->ID, "_barrio_seo_{$key}", true );
		$robots   = self::robots( 'post_types', $post->post_type );
		$keywords = $meta( 'keywords' );
		return [
			'title'       => self::post_title( $post ),
			// Il contenuto di un post protetto da password non esce mai.
			'description' => self::text( (string) $meta( 'description' ) )
				?: ( '' === $post->post_password ? self::excerpt( $post->post_excerpt ?: $post->post_content ) : '' )
				?: self::default_description(),
			'image'       => (int) $meta( 'image' ) ?: (int) get_post_thumbnail_id( $post ) ?: (int) Settings::get()['default_image'],
			'keywords'    => is_array( $keywords ) ? array_values( $keywords ) : [],
			'index'       => self::flag( $meta( 'index' ), 'index', $robots['index'] ),
			'follow'      => self::flag( $meta( 'follow' ), 'follow', $robots['follow'] ),
			'canonical'   => self::url( (string) get_permalink( $post ) ),
		];
	}

	/** @return Resolved */
	public static function term( WP_Term $term ): array {
		$meta     = static fn( string $key ): mixed => get_term_meta( $term->term_id, "_barrio_seo_{$key}", true );
		$robots   = self::robots( 'taxonomies', $term->taxonomy );
		$keywords = $meta( 'keywords' );
		$link     = get_term_link( $term );
		return [
			'title'       => self::text( (string) $meta( 'title' ) ) ?: self::text( $term->name ),
			'description' => self::text( (string) $meta( 'description' ) ) ?: self::excerpt( $term->description ) ?: self::default_description(),
			'image'       => (int) $meta( 'image' ) ?: (int) Settings::get()['default_image'],
			'keywords'    => is_array( $keywords ) ? array_values( $keywords ) : [],
			'index'       => self::flag( $meta( 'index' ), 'index', $robots['index'] ),
			'follow'      => self::flag( $meta( 'follow' ), 'follow', $robots['follow'] ),
			'canonical'   => is_string( $link ) ? self::url( $link ) : '',
		];
	}

	public static function post_title( WP_Post $post ): string {
		return self::text( (string) get_post_meta( $post->ID, '_barrio_seo_title', true ) ) ?: self::text( $post->post_title );
	}

	/**
	 * Il <title>: "{titolo} {sep} {sito}" o al contrario, con la pagina dopo la prima. Lo stesso
	 * dell'anteprima JS. Senza titolo, o con un titolo uguale al nome del sito, solo il sito.
	 */
	public static function document_title( string $title, int $page = 1 ): string {
		$settings = Settings::get()['title'];
		$sep      = " {$settings['separator']} ";
		/* translators: %d: page number. */
		$title = implode( $sep, array_filter( [ $title, $page > 1 ? sprintf( __( 'Page %d', 'barrio-suite' ), $page ) : '' ] ) );
		$parts = array_unique( array_filter( [ $title, self::text( get_bloginfo( 'name' ) ) ] ) );
		return implode( $sep, 'site_page' === $settings['order'] ? array_reverse( $parts ) : $parts );
	}

	/**
	 * Indicizza e segui di un tipo o di una tassonomia ('post_types', 'taxonomies') o di un archivio
	 * ('archives': 'author', 'date'). Una chiave assente vale sì.
	 *
	 * @return array{index: bool, follow: bool}
	 */
	public static function robots( string $group, string $name ): array {
		$robots = Settings::get()['index'][ $group ][ $name ] ?? [];
		return [
			'index'  => (bool) ( $robots['index'] ?? true ),
			'follow' => (bool) ( $robots['follow'] ?? true ),
		];
	}

	/**
	 * Gli argomenti di WP_Query o di WP_Term_Query per i soli contenuti o termini indicizzabili di un tipo
	 * o di una tassonomia, per sitemap, llms.txt e ItemList. In un tipo indicizzato tutti tranne i noindex,
	 * in uno noindex solo quelli indicizzati a mano; mai i contenuti protetti da password.
	 *
	 * @return array<string, mixed>
	 */
	public static function indexable( string $group, string $name ): array {
		$index = [
			'key'   => '_barrio_seo_index',
			'value' => 'index',
		];
		return [
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- l'indicizzazione di un contenuto è il suo meta.
			'meta_query' => self::robots( $group, $name )['index'] ? [
				'relation' => 'OR',
				[
					'key'     => '_barrio_seo_index',
					'compare' => 'NOT EXISTS',
				],
				$index,
			] : [ $index ],
		] + ( 'post_types' === $group ? [ 'has_password' => false ] : [] );
	}

	// L'URL di una pagina, in ogni punto in cui il modulo lo scrive: l'estensione WPGraphQL lo porta sul frontend.
	public static function url( string $url ): string {
		return (string) apply_filters( 'barrio_seo_url', $url );
	}

	// Testo semplice: senza shortcode, tag, entità e spazi ripetuti.
	public static function text( string $html ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( strip_shortcodes( $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	public static function default_description(): string {
		return Settings::text( 'default_description', I18n::current() );
	}

	// 160 caratteri al massimo, senza tagliare una parola.
	private static function excerpt( string $html ): string {
		$text = self::text( $html );
		return mb_strlen( $text ) > 160 ? preg_replace( '/\s+\S*$/u', '', mb_substr( $text, 0, 160 ) ) . '…' : $text;
	}

	// Un meta 'index' | 'noindex' (o 'follow' | 'nofollow'); assente eredita.
	private static function flag( mixed $value, string $yes, bool $inherited ): bool {
		return in_array( $value, [ $yes, "no{$yes}" ], true ) ? $yes === $value : $inherited;
	}

	// Il titolo dell'archivio del core, senza il prefisso ("Categoria:", "Autore:"…).
	private static function archive_title(): string {
		$no_prefix = static fn(): string => '';
		add_filter( 'get_the_archive_title_prefix', $no_prefix, PHP_INT_MAX );
		$title     = get_the_archive_title();
		remove_filter( 'get_the_archive_title_prefix', $no_prefix, PHP_INT_MAX );
		return self::text( $title );
	}

	/** @return Resolved */
	private static function archive( string $title, string $link, bool $index, bool $follow ): array {
		return [
			'title'       => $title,
			'description' => '' === $link ? '' : self::default_description(),
			'image'       => '' === $link ? 0 : (int) Settings::get()['default_image'],
			'keywords'    => [],
			'index'       => $index,
			'follow'      => $follow,
			'canonical'   => '' === $link ? '' : self::url( $link ),
		];
	}

	/**
	 * Dalla seconda pagina di un archivio la canonical è quella pagina.
	 *
	 * @param Resolved $meta
	 * @return Resolved
	 */
	private static function paged( array $meta, int $page ): array {
		global $wp_rewrite;
		if ( $page > 1 && '' !== $meta['canonical'] ) {
			$meta['canonical'] = $wp_rewrite->using_permalinks()
				? user_trailingslashit( trailingslashit( $meta['canonical'] ) . "{$wp_rewrite->pagination_base}/{$page}", 'paged' )
				: add_query_arg( 'paged', $page, $meta['canonical'] );
		}
		return $meta;
	}
}
