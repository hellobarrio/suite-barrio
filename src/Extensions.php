<?php

namespace BarrioSuite;

use Plugin_Upgrader;
use WP_Ajax_Upgrader_Skin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Le estensioni sono plugin separati, pubblicati con Barrio Suite come asset delle release GitHub del
 * repository (npm run release). Il catalog.json dell'ultima release dà nome e versione di ognuno; lo zip
 * è sempre l'asset {slug}.zip della stessa release, quindi un catalogo alterato non fa scaricare altro:
 *
 * - la pagina Estensioni (Barrio → Extensions) le installa, attiva, disattiva e aggiorna con l'installatore di WordPress;
 * - gli aggiornamenti, anche di Barrio Suite, compaiono in Bacheca → Aggiornamenti come quelli degli
 *   altri plugin: ognuno ha l'header "Update URI: {REPO}" e qui si risponde al filtro di WordPress
 *   per quell'host (update_plugins_github.com).
 *
 * @phpstan-type Item array{name: string, description: string, version: string, requires: string, requires_php: string}
 */
final class Extensions {

	// Anche nell'header Update URI di ogni plugin: ExtensionsTest controlla che coincidano.
	public const REPO      = 'https://github.com/hellobarrio/barrio-suite';
	private const DOWNLOAD = self::REPO . '/releases/latest/download/';
	private const CATALOG  = self::DOWNLOAD . 'catalog.json';
	private const CACHE    = 'barrio_extensions_catalog';

	/** @var array<string, Item>|WP_Error|null Per richiesta: anche un errore, così non si riprova per ogni plugin. */
	private static array|WP_Error|null $catalog = null;

	public static function boot(): void {
		add_filter( 'update_plugins_github.com', [ self::class, 'update' ], 10, 3 );
		add_action( 'rest_api_init', [ self::class, 'register' ] );
	}

	/**
	 * Per WordPress, quando controlla gli aggiornamenti: la versione nel catalogo di un nostro plugin.
	 * Il confronto con quella installata lo fa WordPress.
	 *
	 * @param array<string, mixed>|false $update
	 * @param array<string, string>      $plugin_data
	 * @return array<string, mixed>|false
	 */
	public static function update( mixed $update, array $plugin_data, string $plugin_file ): mixed {
		$catalog = self::REPO === ( $plugin_data['UpdateURI'] ?? '' ) ? self::catalog() : [];
		$item    = is_array( $catalog ) ? $catalog[ dirname( $plugin_file ) ] ?? null : null;
		return null === $item ? $update : [
			'slug'         => dirname( $plugin_file ),
			'version'      => $item['version'],
			'package'      => self::DOWNLOAD . dirname( $plugin_file ) . '.zip',
			'url'          => self::REPO,
			'requires'     => $item['requires'],
			'requires_php' => $item['requires_php'],
		];
	}

	public static function register(): void {
		register_rest_route(
			'barrio/v1',
			'/extensions',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list' ],
				'permission_callback' => static fn(): bool => current_user_can( 'activate_plugins' ),
				// refresh: senza la copia del catalogo dell'ultima ora ("Cerca aggiornamenti").
				'args'                => [ 'refresh' => [ 'type' => 'boolean' ] ],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/extensions/(?P<slug>[a-z0-9-]+)',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'act' ],
				// DISALLOW_FILE_MODS, o un sito di un multisite, toglie già install_plugins e update_plugins.
				'permission_callback' => static fn( WP_REST_Request $request ): bool => current_user_can(
					[
						'install'    => 'install_plugins',
						'update'     => 'update_plugins',
						'activate'   => 'activate_plugins',
						'deactivate' => 'activate_plugins',
					][ $request['action'] ] ?? 'do_not_allow'
				),
				'args'                => [
					'action' => [
						'type'     => 'string',
						'enum'     => [ 'install', 'update', 'activate', 'deactivate' ],
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Le estensioni del catalogo e quelle installate, con lo stato. Senza catalogo (GitHub non
	 * risponde) restano quelle installate, con l'errore.
	 */
	public static function list( WP_REST_Request $request ): WP_REST_Response {
		if ( $request['refresh'] ) {
			delete_site_transient( self::CACHE );
			self::$catalog = null;
		}
		$catalog   = self::catalog();
		$installed = self::installed();
		$items     = is_array( $catalog ) ? $catalog : [];
		$slugs     = array_diff( array_unique( [ ...array_keys( $items ), ...array_keys( $installed ) ] ), [ 'barrio-suite' ] );
		$list      = [];
		foreach ( $slugs as $slug ) {
			$local  = $installed[ $slug ] ?? null;
			$list[] = [
				'slug'        => $slug,
				'name'        => ( $local['Name'] ?? '' ) ?: $items[ $slug ]['name'] ?? $slug,
				'description' => ( $local['Description'] ?? '' ) ?: $items[ $slug ]['description'] ?? '',
				// L'ultima versione pubblicata e quella installata (null = non c'è).
				'version'     => $items[ $slug ]['version'] ?? null,
				'installed'   => $local['Version'] ?? null,
				'active'      => null !== $local && is_plugin_active( $local['file'] ),
			];
		}
		usort( $list, static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] ) );
		return new WP_REST_Response(
			[
				'extensions'  => $list,
				'error'       => $catalog instanceof WP_Error ? $catalog->get_error_message() : null,
				'can_install' => current_user_can( 'install_plugins' ),
				'can_update'  => current_user_can( 'update_plugins' ),
				'upload_url'  => network_admin_url( 'plugin-install.php?tab=upload' ),
			]
		);
	}

	// Risponde con l'elenco aggiornato, come GET /extensions.
	public static function act( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slug   = (string) $request['slug'];
		$action = (string) $request['action'];
		$local  = self::installed()[ $slug ] ?? null;
		$item   = in_array( $action, [ 'install', 'update' ], true ) ? self::catalog() : [];
		if ( $item instanceof WP_Error ) {
			return new WP_Error( $item->get_error_code(), $item->get_error_message(), [ 'status' => 502 ] );
		}
		$item = $item[ $slug ] ?? null;

		$error = null;
		if ( 'install' === $action && null === $local && null !== $item ) {
			$error = self::install( self::DOWNLOAD . "{$slug}.zip", false ) ?? activate_plugin( (string) ( self::installed()[ $slug ]['file'] ?? '' ) );
		} elseif ( 'update' === $action && null !== $local && null !== $item ) {
			$error = self::install( self::DOWNLOAD . "{$slug}.zip", true );
		} elseif ( 'activate' === $action && null !== $local ) {
			$error = activate_plugin( $local['file'] );
		} elseif ( 'deactivate' === $action && null !== $local && ! is_plugin_active_for_network( $local['file'] ) ) {
			// Non se è attiva in tutta la rete di un multisite: si disattiverebbe ovunque.
			deactivate_plugins( $local['file'] );
		} else {
			$error = new WP_Error( 'barrio_extension_state', __( 'This extension cannot do that now: reload the page.', 'barrio-suite' ), [ 'status' => 409 ] );
		}
		if ( $error instanceof WP_Error ) {
			$data = $error->get_error_data();
			return new WP_Error( $error->get_error_code(), $error->get_error_message(), [ 'status' => is_array( $data ) ? $data['status'] ?? 500 : 500 ] );
		}
		return self::list( new WP_REST_Request() );
	}

	/** @return array<string, Item>|WP_Error Per slug, cioè la cartella del plugin. */
	private static function catalog(): array|WP_Error {
		self::$catalog ??= self::fetch();
		return self::$catalog;
	}

	/** @return array<string, Item>|WP_Error */
	private static function fetch(): array|WP_Error {
		/** @var array<string, Item>|false $cached */
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get( self::CATALOG, [ 'timeout' => 10 ] );
		$plugins  = is_wp_error( $response ) ? null : ( json_decode( wp_remote_retrieve_body( $response ), true )['plugins'] ?? null );
		if ( ! is_array( $plugins ) ) {
			return new WP_Error(
				'barrio_catalog_unavailable',
				sprintf(
					/* translators: %s: errore, per esempio "HTTP 404". */
					__( 'The list of extensions could not be loaded from GitHub (%s). Try again later, or upload the zip of an extension.', 'barrio-suite' ),
					is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response )
				)
			);
		}
		// Lo slug finisce nell'URL dello zip e nella cartella del plugin.
		$catalog = [];
		foreach ( $plugins as $slug => $item ) {
			if ( is_array( $item ) && preg_match( '/^[a-z0-9-]+$/', (string) $slug ) ) {
				$catalog[ (string) $slug ] = array_map( static fn( mixed $value ): string => is_scalar( $value ) ? (string) $value : '', $item + array_fill_keys( [ 'name', 'description', 'version', 'requires', 'requires_php' ], '' ) );
			}
		}
		/** @var array<string, Item> $catalog */
		set_site_transient( self::CACHE, $catalog, HOUR_IN_SECONDS );
		return $catalog;
	}

	/** @return array<string, array<string, string>> I nostri plugin installati per cartella, con file e header. */
	private static function installed(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$installed = [];
		foreach ( get_plugins() as $file => $data ) {
			if ( self::REPO === ( $data['UpdateURI'] ?? '' ) ) {
				$installed[ dirname( $file ) ] = [ 'file' => $file ] + $data;
			}
		}
		return $installed;
	}

	// Con l'installatore di WordPress, come Plugin → Aggiungi nuovo; $overwrite sostituisce la versione installata.
	private static function install( string $package, bool $overwrite ): ?WP_Error {
		// Fuori da wp-admin, come in WP_REST_Plugins_Controller.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$skin   = new WP_Ajax_Upgrader_Skin();
		$result = ( new Plugin_Upgrader( $skin ) )->install( $package, [ 'overwrite_package' => $overwrite ] );
		wp_clean_plugins_cache();
		if ( true === $result ) {
			return null;
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $skin->get_errors()->has_errors() ) {
			return $skin->get_errors();
		}
		// Senza errori ma senza risultato: WordPress non può scrivere i file senza le credenziali FTP.
		return new WP_Error( 'barrio_filesystem', __( 'WordPress cannot write plugin files on this server. Download the zip of the extension and upload it from Plugins → Add New Plugin → Upload Plugin.', 'barrio-suite' ) );
	}
}
