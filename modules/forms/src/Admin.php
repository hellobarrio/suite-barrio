<?php

namespace BarrioSuite\Forms;

/**
 * Menu "Barrio", che ospiterà anche i moduli futuri, con la pagina Impostazioni (React, in
 * assets/src/settings). Le pagine Form e Registrazioni arrivano con le fasi 4 e 5.
 */
final class Admin {

	private const SETTINGS = 'barrio-settings';

	public static function menu(): void {
		$hook = (string) add_menu_page( 'Barrio', 'Barrio', 'manage_options', self::SETTINGS, [ self::class, 'settings_page' ], 'dashicons-feedback', 30 );
		add_submenu_page( self::SETTINGS, __( 'Barrio settings', 'barrio-suite' ), __( 'Settings', 'barrio-suite' ), 'manage_options', self::SETTINGS, [ self::class, 'settings_page' ] );
		add_action( "load-{$hook}", [ self::class, 'enqueue_settings' ] );
	}

	public static function enqueue_settings(): void {
		$asset_file = self::root() . '/build/forms-settings.asset.php';
		if ( ! is_file( $asset_file ) ) {
			return;
		}
		/** @var array{dependencies: list<non-empty-string>, version: string} $asset */
		$asset = require $asset_file;
		wp_enqueue_script( 'barrio-forms-settings', plugins_url( 'build/forms-settings.js', self::root() . '/barrio-suite.php' ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'barrio-forms-settings', 'barrio-suite' );
		wp_enqueue_style( 'wp-components' );
		// Per scegliere il logo dalla libreria dei media.
		wp_enqueue_media();
	}

	public static function settings_page(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Settings', 'barrio-suite' ) . '</h1>';
		if ( ! is_file( self::root() . '/build/forms-settings.js' ) ) {
			// Solo in sviluppo: il pacchetto del plugin contiene la build.
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The settings page has not been built: run npm run build.', 'barrio-suite' ) . '</p></div>';
		}
		echo '<div id="barrio-settings"></div></div>';
	}

	private static function root(): string {
		return dirname( __DIR__, 3 );
	}
}
