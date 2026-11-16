<?php

namespace BarrioSuite\Forms;

/**
 * Menu "Barrio", che ospiterà anche i moduli futuri. Una sola app React (assets/src/admin, compilata
 * in build/) per tutte le pagine: la Bacheca, con una card per modulo; Form, con le schede Form (elenco
 * e builder), Registrazioni, che basta agli editor, e Impostazioni, che richiede manage_options perché
 * l'SMTP vale per tutte le email del sito; Estensioni e Impostazioni del plugin (gli aggiornamenti),
 * che non sono dei form (src/Extensions.php).
 * ponytail: menu e app stanno nel modulo Forms finché è l'unico; con un secondo modulo passano al core.
 */
final class Admin {

	private const PAGE = 'barrio';

	// L'icona del prototipo: WordPress ricolora l'SVG come le altre icone del menu.
	private const ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjIwIiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSIgcm9sZT0iaW1nIj48cGF0aCBmaWxsPSJibGFjayIgZD0iTTUsNWgyLjV2Mi41SDVaTTAsOC43NWgyLjV2Mi41SDBaTTIuNSwxMS4yNWgyLjV2Mi41SDIuNVpNNSwxMy43NWgxMHYyLjVINVpNMTcuNSw4Ljc1aDIuNXYyLjVIMTcuNVpNMTUsMTEuMjVoMi41djIuNUgxNVpNMTIuNSw1aDIuNXYyLjVIMTIuNVoiLz48L3N2Zz4=';

	// Una voce per modulo. Bacheca e Form si aprono con una delle due capability, che i ruoli hanno
	// anche separate (Form → Impostazioni → Permessi): ognuno ci trova solo ciò che può fare
	// (assets/src/admin/index.js).
	// ponytail: la Bacheca ha le capability del solo modulo; con un secondo modulo diventa 'read' e
	// ogni card controlla la sua.
	public static function menu(): void {
		$forms = current_user_can( 'barrio_manage_entries' ) ? 'barrio_manage_entries' : 'barrio_manage_forms';
		add_menu_page( 'Barrio Suite', 'Barrio', $forms, self::PAGE, [ self::class, 'page' ], self::ICON, 30 );
		$pages = [
			self::PAGE          => [ __( 'Dashboard', 'barrio-suite' ), $forms ],
			'barrio-forms'      => [ __( 'Forms', 'barrio-suite' ), $forms ],
			// Installare e aggiornare richiedono altre capability (src/Extensions.php).
			'barrio-extensions' => [ __( 'Extensions', 'barrio-suite' ), 'activate_plugins' ],
			// Per ora solo gli aggiornamenti: la stessa capability di Estensioni, che ne legge i dati.
			'barrio-settings'   => [ __( 'Settings', 'barrio-suite' ), 'activate_plugins' ],
		];
		foreach ( $pages as $slug => [ $title, $capability ] ) {
			$hook = add_submenu_page( self::PAGE, $title, $title, $capability, $slug, [ self::class, 'page' ] );
			if ( $hook ) {
				add_action( "load-{$hook}", [ self::class, 'enqueue' ] );
			}
		}
	}

	public static function enqueue(): void {
		$root       = dirname( __DIR__, 3 ) . '/barrio-suite.php';
		$asset_file = dirname( $root ) . '/build/forms-admin.asset.php';
		if ( ! is_file( $asset_file ) ) {
			return;
		}
		/** @var array{dependencies: list<non-empty-string>, version: string} $asset */
		$asset = require $asset_file;
		wp_enqueue_script( 'barrio-forms-admin', plugins_url( 'build/forms-admin.js', $root ), $asset['dependencies'], $asset['version'], true );
		wp_add_inline_script( 'barrio-forms-admin', 'window.barrioAdmin = ' . wp_json_encode( self::data() ) . ';', 'before' );
		wp_set_script_translations( 'barrio-forms-admin', 'barrio-suite' );
		wp_enqueue_style( 'barrio-forms-admin', plugins_url( 'build/forms-admin.css', $root ), [ 'wp-components' ], $asset['version'] );
		// Per scegliere il logo delle email dalla libreria dei media.
		wp_enqueue_media();
	}

	// L'intestazione, compreso il titolo, la mostra React. wp-header-end tiene gli avvisi di
	// WordPress sopra la pagina.
	public static function page(): void {
		echo '<div class="wrap"><hr class="wp-header-end">';
		if ( ! is_file( dirname( __DIR__, 3 ) . '/build/forms-admin.js' ) ) {
			// Solo in sviluppo: il pacchetto del plugin contiene la build.
			echo '<div class="notice notice-error"><p>' . esc_html__( 'This page has not been built: run npm run build.', 'barrio-suite' ) . '</p></div>';
		}
		echo '<div id="barrio-forms-admin"></div></div>';
	}

	/**
	 * Scelte pronte per select, radio e checkbox. Un'estensione ne aggiunge con il filtro
	 * barrio_forms_presets: 'id' => [ 'label' => 'Nome', 'choices' => [ [ 'valore', 'Etichetta' ], … ] ].
	 * Coppie e non { valore: etichetta }: in JavaScript le chiavi numeriche cambierebbero ordine.
	 * ponytail: etichette in italiano, come nel prototipo; per un sito con un'altra lingua principale
	 * le nazioni si possono generare nel browser con Intl.DisplayNames (i valori sono codici ISO).
	 *
	 * @return array<string, array{label: string, choices: list<array{string, string}>}>
	 */
	public static function presets(): array {
		$choices = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/presets.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file del plugin.
		$labels  = [
			'countries'      => __( 'Countries', 'barrio-suite' ),
			'regions_it'     => __( 'Italian regions', 'barrio-suite' ),
			'provinces_it'   => __( 'Italian provinces', 'barrio-suite' ),
			'us_states'      => __( 'US states', 'barrio-suite' ),
			'continents'     => __( 'Continents', 'barrio-suite' ),
			'days'           => __( 'Days of the week', 'barrio-suite' ),
			'months'         => __( 'Months', 'barrio-suite' ),
			'languages'      => __( 'Languages', 'barrio-suite' ),
			'education'      => __( 'Education', 'barrio-suite' ),
			'marital_status' => __( 'Marital status', 'barrio-suite' ),
			'gender'         => __( 'Gender', 'barrio-suite' ),
		];
		$presets = [];
		foreach ( $labels as $id => $label ) {
			$presets[ $id ] = [
				'label'   => $label,
				'choices' => $choices[ $id ],
			];
		}
		return apply_filters( 'barrio_forms_presets', $presets );
	}

	/** @return array<string, mixed> */
	private static function data(): array {
		$types = [];
		foreach ( FieldTypes::labels() as $type => $label ) {
			$types[ $type ] = [
				'label'  => $label,
				'schema' => FieldTypes::schema( $type ),
			];
		}
		return [
			// La prima è la principale; le altre si scelgono nella barra del builder per tradurre i testi.
			'languages'        => I18n::languages(),
			// Un oggetto anche se vuoto: [] in JSON sarebbe una lista.
			'flags'            => (object) I18n::flags(),
			'types'            => $types,
			'presets'          => self::presets(),
			'integrations'     => (object) Integrations::definitions(),
			'canManageForms'   => current_user_can( 'barrio_manage_forms' ),
			'canManageEntries' => current_user_can( 'barrio_manage_entries' ),
			'canManageOptions' => current_user_can( 'manage_options' ),
			'version'          => BARRIO_SUITE_VERSION,
			// Per i download (file ed export CSV), che sono link e non passano da apiFetch.
			'rest'             => [
				'url'   => rest_url( 'barrio/v1/' ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			],
		];
	}
}
