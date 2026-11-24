<?php
/**
 * Plugin Name:       Barrio Suite
 * Description:       Forms and site tools by Hellobarrio.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Text Domain:       barrio-suite
 */

defined( 'ABSPATH' ) || exit;

define( 'BARRIO_SUITE_VERSION', '0.1.0' );

spl_autoload_register(
	static function ( string $class_name ): void {
		// Ogni modulo ha il suo namespace; il core condiviso è BarrioSuite\ in src/.
		$roots = [
			'BarrioSuite\\Forms\\'       => '/modules/forms/src/',
			'BarrioSuite\\Seo\\'         => '/modules/seo/src/',
			'BarrioSuite\\Performance\\' => '/modules/performance/src/',
			'BarrioSuite\\Duplicate\\'   => '/modules/duplicate/src/',
			'BarrioSuite\\Ordering\\'    => '/modules/ordering/src/',
			'BarrioSuite\\Blocks\\'      => '/modules/blocks/src/',
			'BarrioSuite\\Alt\\'         => '/modules/alt/src/',
			'BarrioSuite\\Hours\\'       => '/modules/hours/src/',
			'BarrioSuite\\Banners\\'     => '/modules/banners/src/',
			'BarrioSuite\\Popups\\'      => '/modules/popups/src/',
			'BarrioSuite\\Analytics\\'   => '/modules/analytics/src/',
			'BarrioSuite\\'              => '/src/',
		];
		foreach ( $roots as $prefix => $dir ) {
			if ( str_starts_with( $class_name, $prefix ) ) {
				$file = __DIR__ . $dir . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
				if ( is_file( $file ) ) {
					require $file;
				}
				return;
			}
		}
	}
);

register_activation_hook( __FILE__, [ BarrioSuite\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ BarrioSuite\Plugin::class, 'deactivate' ] );
add_action( 'plugins_loaded', [ BarrioSuite\Plugin::class, 'boot' ] );
