<?php

namespace BarrioSuite\Seo;

use BarrioSuite\Database\Db;
use BarrioSuite\Seo\Repository\RedirectRepository;

/**
 * I redirect nel sito, e quelli automatici quando cambia l'URL di un contenuto pubblicato o di un termine
 * (anche cambiando il genitore, con i discendenti). In WordPress i post hanno già il redirect dei vecchi
 * slug, ma un frontend headless no.
 *
 * @phpstan-import-type Redirect from RedirectRepository
 */
final class Redirects {

	// Una destinazione: un percorso del sito o un URL assoluto. //altro-sito.example/x porterebbe fuori dal sito.
	public const TARGET = '#^(/(?!/)|https?://)#';

	/** @var array<string, array<int, string>> Gli URL prima del salvataggio, per "post_ID" o "term_ID". */
	private static array $before = [];

	// template_redirect, priorità 0: prima di redirect_canonical e del redirect dei vecchi slug del core.
	public static function run(): void {
		global $wp_query;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- si normalizza in path() e serve solo a una query preparata.
		$redirect = self::match( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );
		if ( null === $redirect ) {
			return;
		}
		if ( null === $redirect['target'] ) {
			// 410: la pagina "non trovata" del tema, con il suo codice, senza che il core indovini un altro contenuto.
			$wp_query->set_404();
			status_header( 410 );
			nocache_headers();
			remove_action( 'template_redirect', 'redirect_canonical' );
			remove_action( 'template_redirect', 'wp_old_slug_redirect' );
			return;
		}
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- la destinazione la sceglie chi gestisce la SEO, anche su un altro dominio.
		if ( wp_redirect( $redirect['target'], $redirect['code']->value, 'Barrio Suite' ) ) {
			exit;
		}
	}

	/**
	 * Il redirect di una richiesta: prima con la query string, poi senza, e allora la query passa alla destinazione.
	 *
	 * @return Redirect|null
	 */
	public static function match( string $request_uri ): ?array {
		$source = self::path( $request_uri );
		// Byte non UTF-8 (/caf%E9, un vecchio link in Latin-1): wpdb rifiuterebbe la query e Db lancerebbe un'eccezione.
		if ( ! wp_is_valid_utf8( $source ) ) {
			return null;
		}
		$redirect = RedirectRepository::find( $source );
		$query    = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );
		if ( null === $redirect && '' !== $query ) {
			$redirect = RedirectRepository::find( explode( '?', $source, 2 )[0] );
			if ( null !== $redirect && null !== $redirect['target'] ) {
				// Prima del frammento: dopo il # la query non arriverebbe al server.
				$parts              = explode( '#', $redirect['target'], 2 );
				$parts[0]          .= ( str_contains( $parts[0], '?' ) ? '&' : '?' ) . $query;
				$redirect['target'] = implode( '#', $parts );
			}
		}
		return $redirect;
	}

	// Un URL o un percorso come la sorgente di un redirect: senza dominio, decodificato, senza / finale (la radice resta /), con la query string.
	public static function path( string $url ): string {
		$parts = wp_parse_url( $url ) ?: [];
		$path  = '/' . trim( rawurldecode( $parts['path'] ?? '' ), '/' );
		return $path . ( empty( $parts['query'] ) ? '' : "?{$parts['query']}" );
	}

	// Il percorso del sito a cui porta una destinazione; null per il 410 e fuori dal sito.
	public static function local( ?string $target ): ?string {
		$host = null === $target ? false : wp_parse_url( $target, PHP_URL_HOST );
		return null === $host || wp_parse_url( home_url(), PHP_URL_HOST ) === $host ? self::path( (string) $target ) : null;
	}

	/**
	 * Se un redirect da $source verso $target ci riporta, seguendo la catena tra i percorsi del sito.
	 *
	 * @param callable(string): (string|null) $next La destinazione del redirect di un percorso, o null.
	 */
	public static function loops( string $source, ?string $target, callable $next ): bool {
		$seen = [];
		for ( $at = self::local( $target ); null !== $at && ! isset( $seen[ $at ] ); $at = self::local( $next( $at ) ) ) {
			// Anche con una query string in più: nel sito ricade sul redirect della sorgente senza query (match()).
			if ( $at === $source || explode( '?', $at, 2 )[0] === $source ) {
				return true;
			}
			$seen[ $at ] = true;
		}
		return false;
	}

	// pre_post_update: gli URL si leggono prima del salvataggio.
	public static function before_post( int $post_id ): void {
		self::$before[ "post_{$post_id}" ] = self::post_urls( $post_id );
	}

	// post_updated: dopo clean_post_cache, quindi con i nuovi URL.
	public static function after_post( int $post_id ): void {
		self::moved( self::$before[ "post_{$post_id}" ] ?? [], self::post_urls( $post_id ) );
		unset( self::$before[ "post_{$post_id}" ] );
	}

	// edit_terms: prima che cambino slug e genitore.
	public static function before_term( int $term_id, string $taxonomy ): void {
		self::$before[ "term_{$term_id}" ] = self::term_urls( $term_id, $taxonomy );
	}

	// edited_term: dopo clean_term_cache.
	public static function after_term( int $term_id, int $tt_id, string $taxonomy ): void {
		self::moved( self::$before[ "term_{$term_id}" ] ?? [], self::term_urls( $term_id, $taxonomy ) );
		unset( self::$before[ "term_{$term_id}" ] );
	}

	/**
	 * Un 301 per ogni URL cambiato, verso l'URL vero (con la / finale dei permalink, così WordPress non
	 * aggiunge un secondo redirect). Il redirect che partiva dal nuovo URL si toglie (la pagina è di nuovo
	 * lì), e chi portava al vecchio porta al nuovo, scritto con o senza / finale o come URL assoluto: niente
	 * catene, e quindi niente loop. Un redirect che parte già dal vecchio URL resta com'è.
	 *
	 * @param array<int, string> $before
	 * @param array<int, string> $after
	 */
	private static function moved( array $before, array $after ): void {
		$moved = array_filter( array_intersect_key( $before, $after ), static fn( string $from, int $id ): bool => $from !== $after[ $id ], ARRAY_FILTER_USE_BOTH );
		foreach ( $moved as $id => $from ) {
			$to = $after[ $id ];
			Db::transaction(
				static function () use ( $from, $to ): void {
					$stale = RedirectRepository::find( self::path( $to ) );
					if ( null !== $stale ) {
						RedirectRepository::delete( $stale['id'] );
					}
					$origin = (string) preg_replace( '#^((https?:)?//[^/]+).*#i', '$1', home_url() );
					RedirectRepository::retarget( [ $from, untrailingslashit( $from ), $origin . $from, $origin . untrailingslashit( $from ) ], $to );
					if ( null === RedirectRepository::find( self::path( $from ) ) ) {
						RedirectRepository::create( self::path( $from ), $to, RedirectCode::MovedPermanently, null, true );
					}
				}
			);
		}
		// Un contenuto appena pubblicato su un indirizzo lasciato da un altro: il redirect automatico che parte da lì lo nasconderebbe.
		$freed = 0;
		foreach ( array_diff_key( $after, $before ) as $to ) {
			$stale  = RedirectRepository::find( self::path( $to ) );
			$freed += null !== $stale && $stale['is_auto'] ? RedirectRepository::delete( $stale['id'] ) : 0;
		}
		if ( $moved || $freed ) {
			do_action( 'barrio_seo_changed', 'redirects' );
		}
	}

	/** @return array<int, string> Gli URL relativi di un contenuto pubblicato e dei suoi discendenti, per ID. */
	private static function post_urls( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! isset( Module::post_types()[ $post->post_type ] ) || ! Settings::get()['auto_redirects'] ) {
			return [];
		}
		$posts = [ $post ];
		if ( is_post_type_hierarchical( $post->post_type ) ) {
			$posts = array_merge(
				$posts,
				get_pages(
					[
						'child_of'  => $post_id,
						'post_type' => $post->post_type,
					]
				) ?: []
			);
		}
		$urls = [];
		foreach ( $posts as $item ) {
			$urls[ $item->ID ] = wp_make_link_relative( (string) get_permalink( $item ) );
		}
		return $urls;
	}

	/**
	 * Gli URL relativi di un termine e dei suoi discendenti, per ID.
	 * ponytail: non segue i contenuti con il termine nel permalink (%category%); servirà un giro su quei post.
	 *
	 * @return array<int, string>
	 */
	private static function term_urls( int $term_id, string $taxonomy ): array {
		if ( ! isset( Module::taxonomies()[ $taxonomy ] ) || ! Settings::get()['auto_redirects'] ) {
			return [];
		}
		$children = is_taxonomy_hierarchical( $taxonomy ) ? get_term_children( $term_id, $taxonomy ) : [];
		$urls     = [];
		foreach ( array_merge( [ $term_id ], is_array( $children ) ? $children : [] ) as $id ) {
			$link = get_term_link( (int) $id, $taxonomy );
			if ( is_string( $link ) ) {
				$urls[ (int) $id ] = wp_make_link_relative( $link );
			}
		}
		return $urls;
	}
}
