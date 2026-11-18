<?php
/**
 * Plugin Name:       Barrio Suite Migration
 * Description:       Moves forms, entries, files, settings and shortcodes from Suite Barrio, the previous plugin, with the WP-CLI command wp barrio forms migrate, and its SEO with wp barrio seo migrate. Once done, it can be removed.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/hellobarrio/suite-barrio
 * Text Domain:       barrio-suite-migration
 * Domain Path:       /languages
 * Requires Plugins:  barrio-suite
 */

// Un file per modulo, con la sua funzione _boot(). Quella dei form si collega solo con il modulo acceso;
// quella del SEO sempre, perché tiene spento il modulo SEO finché il prototipo non è migrato.

defined( 'ABSPATH' ) || exit;

load_plugin_textdomain( 'barrio-suite-migration', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

require __DIR__ . '/forms.php';
require __DIR__ . '/seo.php';

add_action( 'barrio_forms_loaded', 'barrio_migration_forms_boot' );

// Prima del boot di Barrio Suite (plugins_loaded, 10), che con il modulo SEO spento non lo carica. Questo plugin si
// carica prima di Barrio Suite (ordine alfabetico), quindi le sue classi si controllano qui.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( BarrioSuite\Modules::class ) ) {
			barrio_migration_seo_boot();
		}
	},
	9
);
