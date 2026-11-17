<?php
/**
 * Plugin Name:       Barrio Suite WPML
 * Description:       Languages of the Barrio Suite forms from WPML: translations in the builder, forms and emails in the visitor's language.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Text Domain:       barrio-suite-wpml
 * Domain Path:       /languages
 * Requires Plugins:  barrio-suite
 */

// L'unico codice che conosce WPML. Legge i suoi filtri e risponde a quelli di Barrio Suite
// (BarrioSuite\Forms\I18n); senza WPML attivo i filtri di WPML restituiscono null e non cambia niente.

defined( 'ABSPATH' ) || exit;

load_plugin_textdomain( 'barrio-suite-wpml', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

/** @return array<string, array<string, mixed>> Le lingue attive per codice (it, en-us, pt-br). */
function barrio_wpml_languages(): array {
	return (array) apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WPML.
}

add_filter(
	'barrio_forms_languages',
	static function ( array $languages ): array {
		$default = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WPML.
		return is_string( $default ) ? [ $default, ...array_keys( barrio_wpml_languages() ) ] : $languages;
	}
);

add_filter(
	'barrio_forms_current_language',
	static function ( mixed $current ): mixed {
		// In admin può essere "all": Barrio Suite la scarta e usa la lingua principale.
		return apply_filters( 'wpml_current_language', null ) ?? $current; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WPML.
	}
);

add_filter(
	'barrio_forms_locale',
	static fn( string $locale, string $lang ): string => (string) ( barrio_wpml_languages()[ $lang ]['default_locale'] ?? '' ) ?: $locale,
	10,
	2
);

add_filter(
	'barrio_forms_language_flags',
	static fn( array $flags ): array => array_filter( array_map( static fn( array $language ): string => (string) ( $language['country_flag_url'] ?? '' ), barrio_wpml_languages() ) ) + $flags
);

// Nelle pagine di Barrio Suite la lingua si sceglie nel builder: il selettore di WPML nella barra di
// WordPress sceglierebbe solo i contenuti mostrati in admin, e confonderebbe. WPML lo decide in init,
// prima che la schermata sia nota: si guarda la pagina richiesta.
add_filter(
	'wpml_show_admin_language_switcher', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WPML.
	static fn( mixed $show ): mixed => str_starts_with( sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ), 'barrio-' ) ? false : $show // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- serve solo a sapere quale pagina è.
);
