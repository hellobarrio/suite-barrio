<?php
/**
 * Plugin Name:       Barrio Suite WPML
 * Description:       Languages of Barrio Suite from WPML: translated forms, emails and SEO texts, and the SEO data of every language.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/hellobarrio/suite-barrio
 * Text Domain:       barrio-suite-wpml
 * Domain Path:       /languages
 * Requires Plugins:  barrio-suite
 */

// L'unico codice che conosce WPML. Legge i suoi filtri e risponde a quelli di Barrio Suite
// (BarrioSuite\I18n); senza WPML attivo i filtri di WPML restituiscono null e non cambia niente.

defined( 'ABSPATH' ) || exit;

load_plugin_textdomain( 'barrio-suite-wpml', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

/** @return array<string, array<string, mixed>> Le lingue attive per codice (it, en-us, pt-br). */
function barrio_wpml_languages(): array {
	return (array) apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WPML.
}

add_filter(
	'barrio_languages',
	static function ( array $languages ): array {
		$default = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WPML.
		return is_string( $default ) ? [ $default, ...array_keys( barrio_wpml_languages() ) ] : $languages;
	}
);

add_filter(
	'barrio_current_language',
	static function ( mixed $current ): mixed {
		// In admin può essere "all": Barrio Suite la scarta e usa la lingua principale.
		return apply_filters( 'wpml_current_language', null ) ?? $current; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WPML.
	}
);

add_filter(
	'barrio_locale',
	static fn( string $locale, string $lang ): string => (string) ( barrio_wpml_languages()[ $lang ]['default_locale'] ?? '' ) ?: $locale,
	10,
	2
);

// WPML tiene una pila: null torna alla lingua di prima.
add_action(
	'barrio_switch_language',
	static function ( ?string $lang ): void {
		do_action( 'wpml_switch_language', $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- azione di WPML.
	}
);

// Con WPML ogni lingua ha la sua sitemap del core (/en/wp-sitemap.xml) con i suoi contenuti: robots.txt le
// elenca tutte, dopo quella della lingua principale che aggiunge WordPress.
add_action(
	'barrio_seo_loaded',
	static function (): void {
		add_filter(
			'robots_txt',
			static function ( string $output ): string {
				if ( ! wp_sitemaps_get_server()->sitemaps_enabled() ) {
					return $output;
				}
				$default = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WPML.
				foreach ( array_keys( barrio_wpml_languages() ) as $code ) {
					if ( $code !== $default ) {
						$output .= 'Sitemap: ' . esc_url( (string) apply_filters( 'wpml_permalink', get_sitemap_url( 'index' ), $code ) ) . "\n"; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WPML.
					}
				}
				return $output;
			}
		);
	}
);

// Le regole dei popup salvano gli ID nella lingua principale: una pagina tradotta vale come l'originale. Senza
// traduzione l'ID resta com'è.
add_filter(
	'barrio_main_language_id',
	static fn( int $id, string $type ): int => (int) apply_filters( 'wpml_object_id', $id, $type, true, apply_filters( 'wpml_default_language', null ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtri di WPML.
	10,
	2
);

add_filter(
	'barrio_language_flags',
	static fn( array $flags ): array => array_filter( array_map( static fn( array $language ): string => (string) ( $language['country_flag_url'] ?? '' ), barrio_wpml_languages() ) ) + $flags
);

// Nelle pagine di Barrio Suite (la Bacheca è barrio, le altre barrio-…) la lingua si sceglie nella barra
// della pagina: il selettore di WPML nella barra di WordPress sceglierebbe solo i contenuti mostrati in
// admin, e confonderebbe. WPML lo decide in init, prima che la schermata sia nota: si guarda la pagina richiesta.
add_filter(
	'wpml_show_admin_language_switcher', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WPML.
	static fn( mixed $show ): mixed => preg_match( '/^barrio(-|$)/', sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) ? false : $show // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- serve solo a sapere quale pagina è.
);
