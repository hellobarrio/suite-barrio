<?php
/**
 * Plugin Name:       Barrio Suite WPGraphQL
 * Description:       The Barrio Suite forms and SEO in WPGraphQL, with the names of the previous plugin (hbForm, hbSubmitForm, seo, hbSeoSite, hbSitemap…), so headless frontends keep working unchanged.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/hellobarrio/suite-barrio
 * Text Domain:       barrio-suite-wpgraphql
 * Domain Path:       /languages
 * Requires Plugins:  barrio-suite
 */

// L'unico codice che conosce WPGraphQL: lo schema hb* del prototipo con gli stessi nomi e tipi
// (tests/fixtures/wpgraphql-schema.txt e wpgraphql-seo-schema.txt). Un file per modulo, con la sua funzione
// _boot(): si collega solo se il modulo è acceso (barrio_<modulo>_loaded) e se c'è WPGraphQL, che si carica dopo
// questo plugin e quindi si controlla qui, su init. Le descrizioni dello schema non si traducono: lo schema deve
// essere lo stesso in ogni lingua.

defined( 'ABSPATH' ) || exit;

load_plugin_textdomain( 'barrio-suite-wpgraphql', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

require __DIR__ . '/forms.php';
require __DIR__ . '/seo.php';

add_action(
	'barrio_forms_loaded',
	static function (): void {
		if ( function_exists( 'register_graphql_field' ) ) {
			barrio_wpgraphql_forms_boot();
		}
	}
);

add_action(
	'barrio_seo_loaded',
	static function (): void {
		if ( function_exists( 'register_graphql_field' ) ) {
			barrio_wpgraphql_seo_boot();
		}
	}
);
