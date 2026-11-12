<?php
/**
 * Plugin Name:       Barrio Suite
 * Description:       Forms and site tools by Hellobarrio.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Text Domain:       barrio-suite
 */

defined( 'ABSPATH' ) || exit;

define( 'BARRIO_SUITE_VERSION', '0.1.0' );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, 'BarrioSuite\\' ) ) {
			$file = __DIR__ . '/src/' . str_replace( [ 'BarrioSuite\\', '\\' ], [ '', '/' ], $class_name ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
		}
	}
);

register_activation_hook( __FILE__, [ BarrioSuite\Plugin::class, 'activate' ] );
add_action( 'plugins_loaded', [ BarrioSuite\Plugin::class, 'boot' ] );
