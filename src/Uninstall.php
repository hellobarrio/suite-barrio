<?php

namespace BarrioSuite;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Eliminando Barrio Suite da Plugin (uninstall.php) i dati restano, a meno che il sito non abbia scelto
 * di eliminarli in Barrio → Impostazioni: tabelle, opzioni, file e capability dei moduli. Su un
 * multisite lo sceglie ogni sito per i suoi. Disattivare non elimina niente.
 */
final class Uninstall {

	private const OPTION = 'barrio_delete_data';

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		register_rest_route(
			'barrio/v1',
			'/uninstall',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get' ],
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'save' ],
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					'args'                => [
						'delete_data' => [
							'type'     => 'boolean',
							'required' => true,
						],
					],
				],
			]
		);
	}

	public static function get(): WP_REST_Response {
		return new WP_REST_Response( [ 'delete_data' => (bool) get_option( self::OPTION ) ] );
	}

	public static function save( WP_REST_Request $request ): WP_REST_Response {
		update_option( self::OPTION, (bool) $request['delete_data'], false );
		return self::get();
	}

	/** @param list<class-string> $modules Le classi Module, ognuna con uninstall() per file e capability. */
	public static function run( array $modules ): void {
		$sites = is_multisite() ? get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		) : [ 0 ];
		foreach ( $sites as $site ) {
			if ( $site ) {
				switch_to_blog( (int) $site );
			}
			if ( get_option( self::OPTION ) ) {
				self::delete( $modules );
			}
			if ( $site ) {
				restore_current_blog();
			}
		}
	}

	/** @param list<class-string> $modules */
	private static function delete( array $modules ): void {
		global $wpdb;
		foreach ( $modules as $module_class ) {
			$module_class::uninstall();
		}
		// Le tabelle di tutti i moduli e delle estensioni, che hanno lo stesso prefisso. Senza i controlli
		// delle foreign key l'ordine non conta.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'barrio_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $tables as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// Tutte le opzioni hanno il prefisso barrio_. I transient no, ma scadono da soli.
		$options = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'barrio_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $options as $name ) {
			delete_option( $name );
		}
	}
}
