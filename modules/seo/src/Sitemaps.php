<?php

namespace BarrioSuite\Seo;

use BarrioSuite\I18n;

/**
 * La sitemap è quella del core (wp-sitemap.xml, con lastmod): qui solo cosa contiene. In sitemap c'è ciò
 * che è indicizzabile, senza un interruttore a parte; niente priority né changefreq, che Google ignora.
 */
final class Sitemaps {

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function posts( array $args, string $post_type ): array {
		return Meta::indexable( 'post_types', $post_type ) + $args;
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function terms( array $args, string $taxonomy ): array {
		return Meta::indexable( 'taxonomies', $taxonomy ) + $args;
	}

	// Gli autori solo con i loro archivi indicizzati.
	public static function provider( mixed $provider, string $name ): mixed {
		return 'users' === $name && ! Meta::robots( 'archives', 'author' )['index'] ? false : $provider;
	}

	/**
	 * Tutti gli URL della sitemap, dai provider del core con i filtri del modulo: sono quelli che legge Google.
	 * Li usano i conteggi di SEO → Impostazioni e hbSitemap dell'estensione WPGraphQL. Se il sito chiede ai
	 * motori di non indicizzarlo il core non registra i provider: si registrano per questa richiesta, per
	 * mostrare cosa conterrà (e per i frontend headless, il cui WordPress spesso non va indicizzato). Con più
	 * lingue una alla volta: il plugin multilingua filtra le query, e WPML dà a ogni lingua la sua sitemap
	 * (/en/wp-sitemap.xml).
	 * ponytail: legge tutti gli URL, una query per pagina della sitemap (2000 URL); su siti enormi i conteggi
	 * si fanno con le query dei provider.
	 *
	 * @param callable(string, string, array<int, array<string, mixed>>): void $callback Il provider (posts,
	 *        taxonomies, users), il sottotipo ('' per gli autori) e una pagina di URL: anche vuota, se il
	 *        sottotipo non ne ha.
	 */
	public static function each( callable $callback ): void {
		$server = wp_sitemaps_get_server();
		if ( ! $server->registry->get_providers() ) {
			$server->register_sitemaps();
		}
		foreach ( I18n::languages() as $lang ) {
			I18n::in(
				$lang,
				static function () use ( $server, $callback ): void {
					foreach ( $server->registry->get_providers() as $name => $provider ) {
						foreach ( array_keys( $provider->get_object_subtypes() ?: [ '' => null ] ) as $subtype ) {
							$pages = $provider->get_max_num_pages( (string) $subtype );
							foreach ( $pages ? range( 1, $pages ) : [ 0 ] as $page ) {
								$callback( $name, (string) $subtype, $page ? $provider->get_url_list( $page, (string) $subtype ) : [] );
							}
						}
					}
				}
			);
		}
	}
}
