<?php

namespace BarrioSuite;

use WP_Error;
use WP_REST_Response;

/**
 * Pagina Estensioni (Barrio → Extensions): le estensioni che Hellobarrio pubblica nella directory di
 * WordPress.org, più quelle installate a mano (la migrazione), con lo stato sul sito. Installazione,
 * attivazione ed eliminazione passano dalla REST di WordPress (/wp/v2/plugins), gli aggiornamenti dalla
 * schermata di WordPress, come per ogni plugin della directory.
 */
final class Extensions {

	private const AUTHOR = 'hellobarrio';
	private const CACHE  = 'barrio_extensions_catalog';

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		register_rest_route(
			'barrio/v1',
			'/extensions',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get' ],
				'permission_callback' => static fn(): bool => current_user_can( 'activate_plugins' ),
			]
		);
	}

	public static function get(): WP_REST_Response {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$catalog   = self::catalog();
		$installed = [];
		foreach ( get_plugins() as $file => $plugin ) {
			if ( str_starts_with( (string) $file, 'barrio-suite-' ) ) {
				$installed[ dirname( (string) $file ) ] = [ (string) $file, $plugin ];
			}
		}
		$list = is_array( $catalog ) ? $catalog : [];
		foreach ( $installed as $slug => [ , $plugin ] ) {
			$list[ $slug ] ??= [
				'name'        => $plugin['Name'],
				'description' => $plugin['Description'],
				'version'     => null,
			];
		}

		$updates    = get_site_transient( 'update_plugins' )->response ?? [];
		$extensions = [];
		foreach ( $list as $slug => $extension ) {
			$file         = $installed[ $slug ][0] ?? null;
			$extensions[] = $extension + [
				'slug'       => $slug,
				// Il nome che usa /wp/v2/plugins: cartella/file senza .php.
				'plugin'     => null === $file ? null : substr( $file, 0, -4 ),
				'installed'  => null === $file ? null : $installed[ $slug ][1]['Version'],
				'active'     => null !== $file && is_plugin_active( $file ),
				'update_url' => null !== $file && isset( $updates[ $file ] ) && current_user_can( 'update_plugins' )
					? wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file )
					: null,
			];
		}

		return new WP_REST_Response(
			[
				'extensions'  => $extensions,
				'error'       => $catalog instanceof WP_Error ? $catalog->get_error_message() : null,
				'can_install' => current_user_can( 'install_plugins' ),
				'can_delete'  => current_user_can( 'delete_plugins' ),
				'upload_url'  => self_admin_url( 'plugin-install.php?tab=upload' ),
			]
		);
	}

	/** @return array<string, array{name: string, description: string, version: string|null}>|WP_Error */
	private static function catalog(): array|WP_Error {
		$catalog = get_site_transient( self::CACHE );
		if ( is_array( $catalog ) ) {
			return $catalog;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$response = plugins_api(
			'query_plugins',
			[
				'author'   => self::AUTHOR,
				'per_page' => 100,
				'fields'   => [ 'short_description' => true ],
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$catalog = [];
		foreach ( (array) ( $response->plugins ?? [] ) as $plugin ) {
			$plugin = (array) $plugin;
			if ( 'barrio-suite' !== $plugin['slug'] ) {
				$catalog[ (string) $plugin['slug'] ] = [
					'name'        => html_entity_decode( (string) $plugin['name'], ENT_QUOTES ),
					'description' => html_entity_decode( (string) ( $plugin['short_description'] ?? '' ), ENT_QUOTES ),
					'version'     => (string) $plugin['version'],
				];
			}
		}
		set_site_transient( self::CACHE, $catalog, 12 * HOUR_IN_SECONDS );
		return $catalog;
	}
}
