<?php
/**
 * Plugin Name:       Barrio Suite WPGraphQL
 * Description:       The Barrio Suite forms, SEO, popups, banners, opening hours and company details in WPGraphQL, for headless frontends.
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

// L'unico codice che conosce WPGraphQL: lo schema hb* (docs/hooks.md), fotografato in
// tests/fixtures/wpgraphql-*.txt. Un file per modulo, con la sua funzione _boot(): si collega solo se il modulo è
// acceso (barrio_<modulo>_loaded) e se c'è WPGraphQL, che si carica dopo questo plugin e quindi si controlla qui, su
// init. Le descrizioni dello schema non si traducono: lo schema deve essere lo stesso in ogni lingua. frontend.php
// (impostazioni del frontend e revalidation) si collega sempre.

defined( 'ABSPATH' ) || exit;

load_plugin_textdomain( 'barrio-suite-wpgraphql', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

// Un campo dello schema: una stringa che può mancare, un tipo che c'è sempre, una lista che c'è sempre.

/** @return array{type: string, description: string} */
function barrio_wpgraphql_string( string $description = '' ): array {
	return [
		'type'        => 'String',
		'description' => $description,
	];
}

/** @return array{type: array<string, mixed>, description: string} */
function barrio_wpgraphql_required( mixed $type, string $description = '' ): array {
	return [
		'type'        => [ 'non_null' => $type ],
		'description' => $description,
	];
}

/** @return array{type: array<string, mixed>, description: string} */
function barrio_wpgraphql_list( string $type, string $description = '' ): array {
	return barrio_wpgraphql_required( [ 'list_of' => [ 'non_null' => $type ] ], $description );
}

require __DIR__ . '/frontend.php';
barrio_wpgraphql_frontend_boot();

foreach ( [ 'forms', 'seo', 'popups', 'banners', 'hours', 'company' ] as $barrio_module ) {
	require __DIR__ . "/{$barrio_module}.php";
	add_action(
		"barrio_{$barrio_module}_loaded",
		static function () use ( $barrio_module ): void {
			if ( function_exists( 'register_graphql_field' ) ) {
				( "barrio_wpgraphql_{$barrio_module}_boot" )();
			}
		}
	);
}
