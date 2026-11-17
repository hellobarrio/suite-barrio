<?php

namespace BarrioSuite\Seo;

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
}
