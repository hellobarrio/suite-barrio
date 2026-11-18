<?php

namespace BarrioSuite;

use WP_Post_Type;

/**
 * Menu "Barrio" e la sua app React (assets/src, compilata in build/) per tutte le pagine: la Bacheca,
 * una voce per ogni modulo acceso, Estensioni e Impostazioni. Un modulo aggiunge la sua pagina con
 * add_page() su admin_menu e i suoi dati per il JavaScript con il filtro barrio_admin_data.
 */
final class Admin {

	private const PAGE = 'barrio';

	// L'icona del prototipo: WordPress ricolora l'SVG come le altre icone del menu.
	private const ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjIwIiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSIgcm9sZT0iaW1nIj48cGF0aCBmaWxsPSJibGFjayIgZD0iTTUsNWgyLjV2Mi41SDVaTTAsOC43NWgyLjV2Mi41SDBaTTIuNSwxMS4yNWgyLjV2Mi41SDIuNVpNNSwxMy43NWgxMHYyLjVINVpNMTcuNSw4Ljc1aDIuNXYyLjVIMTcuNVpNMTUsMTEuMjVoMi41djIuNUgxNVpNMTIuNSw1aDIuNXYyLjVIMTIuNVoiLz48L3N2Zz4=';

	public static function boot(): void {
		// Prima e dopo le pagine dei moduli, che si aggiungono con la priorità di default.
		add_action( 'admin_menu', [ self::class, 'menu' ], 9 );
		add_action( 'admin_menu', [ self::class, 'menu_end' ], 11 );
	}

	// La Bacheca si apre con read: ogni card controlla la capability del suo modulo.
	public static function menu(): void {
		add_menu_page( 'Barrio Suite', 'Barrio', 'read', self::PAGE, [ self::class, 'page' ], self::ICON, 30 );
		self::add_page( self::PAGE, __( 'Dashboard', 'barrio-suite' ), 'read' );
	}

	public static function menu_end(): void {
		// Installare e aggiornare richiedono altre capability (src/Extensions.php).
		self::add_page( 'barrio-extensions', __( 'Extensions', 'barrio-suite' ), 'activate_plugins' );
		self::add_page( 'barrio-settings', __( 'Settings', 'barrio-suite' ), 'manage_options' );
	}

	/**
	 * Barrio → Editor: gli strumenti dell'editor (Blocchi, Ordinamento, Duplicazione, Testi alternativi) in una
	 * pagina sola, con una scheda per modulo acceso. Ogni modulo la chiede su admin_menu con la capability della
	 * sua scheda: la pagina si aggiunge una volta, con la prima che chi guarda ha, e il JavaScript mostra solo le
	 * schede che può aprire. Con tutti e quattro spenti non c'è.
	 */
	public static function editor_tab( string $capability ): void {
		if ( current_user_can( $capability ) && ! get_plugin_page_hook( 'barrio-editor', self::PAGE ) ) {
			self::add_page( 'barrio-editor', __( 'Editor', 'barrio-suite' ), $capability );
		}
	}

	/**
	 * I tipi di contenuto e le tassonomie degli strumenti dell'editor: quelli con l'interfaccia di WordPress, senza
	 * gli allegati. Le tassonomie solo di quei tipi (non le categorie dei link), con i nomi dei tipi che le usano:
	 * due possono chiamarsi entrambe "Categorie".
	 *
	 * @return array{post_types: list<array{name: string, label: string}>, taxonomies: list<array{name: string, label: string, types: list<string>}>}
	 */
	public static function content_types(): array {
		$types = get_post_types( [ 'show_ui' => true ], 'objects' );
		unset( $types['attachment'] );
		$label      = static fn( WP_Post_Type $type ): string => $type->labels->name;
		$taxonomies = [];
		foreach ( get_taxonomies( [ 'show_ui' => true ], 'objects' ) as $taxonomy ) {
			$used = array_intersect_key( $types, array_flip( $taxonomy->object_type ) );
			if ( $used ) {
				$taxonomies[] = [
					'name'  => $taxonomy->name,
					'label' => $taxonomy->labels->name,
					'types' => array_values( array_map( $label, $used ) ),
				];
			}
		}
		return [
			'post_types' => array_values(
				array_map(
					static fn( WP_Post_Type $type ): array => [
						'name'  => $type->name,
						'label' => $label( $type ),
					],
					$types
				)
			),
			'taxonomies' => $taxonomies,
		];
	}

	// L'hook della pagina, per caricare altro con load-{$hook}; false se chi guarda non può aprirla.
	public static function add_page( string $slug, string $title, string $capability ): string|false {
		$hook = add_submenu_page( self::PAGE, $title, $title, $capability, $slug, [ self::class, 'page' ] );
		if ( $hook ) {
			add_action( "load-{$hook}", [ self::class, 'enqueue' ] );
		}
		return $hook;
	}

	public static function enqueue(): void {
		$root       = dirname( __DIR__ ) . '/barrio-suite.php';
		$asset_file = dirname( __DIR__ ) . '/build/barrio-admin.asset.php';
		if ( ! is_file( $asset_file ) ) {
			return;
		}
		/** @var array{dependencies: list<non-empty-string>, version: string} $asset */
		$asset = require $asset_file;
		wp_enqueue_script( 'barrio-admin', plugins_url( 'build/barrio-admin.js', $root ), $asset['dependencies'], $asset['version'], true );
		wp_add_inline_script( 'barrio-admin', 'window.barrioAdmin = ' . wp_json_encode( self::data() ) . ';', 'before' );
		wp_set_script_translations( 'barrio-admin', 'barrio-suite', dirname( __DIR__ ) . '/languages' );
		wp_enqueue_style( 'barrio-admin', plugins_url( 'build/barrio-admin.css', $root ), [ 'wp-components' ], $asset['version'] );
		wp_style_add_data( 'barrio-admin', 'rtl', 'replace' );
		// Le immagini (il logo delle email…) si scelgono nelle impostazioni dei moduli e nella messa online.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- solo quale pagina caricare.
		if ( 'settings' === sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ) || isset( $_GET['launch'] ) ) {
			// phpcs:enable
			wp_enqueue_media();
		}
	}

	// L'intestazione, compreso il titolo, la mostra React. wp-header-end tiene gli avvisi di
	// WordPress sopra la pagina.
	public static function page(): void {
		echo '<div class="wrap"><hr class="wp-header-end">';
		if ( ! is_file( dirname( __DIR__ ) . '/build/barrio-admin.js' ) ) {
			// Solo in sviluppo: il pacchetto del plugin contiene la build.
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This page has not been built: run npm run build.', 'barrio-suite' ) . '</p></div>';
		}
		echo '<div id="barrio-admin"></div></div>';
	}

	/** @return array<string, mixed> */
	private static function data(): array {
		return apply_filters(
			'barrio_admin_data',
			[
				// La prima è la principale; le altre si scelgono nella barra delle pagine per tradurre i testi.
				'languages'        => I18n::languages(),
				// Un oggetto anche se vuoto: [] in JSON sarebbe una lista.
				'flags'            => (object) I18n::flags(),
				'canManageOptions' => current_user_can( 'manage_options' ),
				'contentTypes'     => self::content_types(),
				'version'          => BARRIO_SUITE_VERSION,
				// Per i download (file ed export CSV), che sono link e non passano da apiFetch.
				'rest'             => [
					'url'   => rest_url( 'barrio/v1/' ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
				],
			]
		);
	}
}
