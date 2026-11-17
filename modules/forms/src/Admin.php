<?php

namespace BarrioSuite\Forms;

/**
 * Menu "Barrio", che ospiterà anche i moduli futuri. Una sola app React (assets/src/admin, compilata
 * in build/) per tutte le pagine: la Bacheca, con una card per modulo; Form, con le schede Form (elenco
 * e builder), Registrazioni, che basta agli editor, e Impostazioni, che richiede manage_options perché
 * l'SMTP vale per tutte le email del sito; Estensioni e Impostazioni del plugin, che non sono dei form.
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
			'barrio-extensions' => [ __( 'Extensions', 'barrio-suite' ), 'activate_plugins' ],
			// Per ora solo i dati alla disinstallazione.
			'barrio-settings'   => [ __( 'Settings', 'barrio-suite' ), 'manage_options' ],
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
		wp_set_script_translations( 'barrio-forms-admin', 'barrio-suite', dirname( $root ) . '/languages' );
		wp_enqueue_style( 'barrio-forms-admin', plugins_url( 'build/forms-admin.css', $root ), [ 'wp-components' ], $asset['version'] );
		wp_style_add_data( 'barrio-forms-admin', 'rtl', 'replace' );
		// Il logo delle email, in Form → Impostazioni e nella Messa online.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- solo quale pagina caricare.
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
		$tab  = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
		if ( ( 'barrio-forms' === $page && 'settings' === $tab ) || ( self::PAGE === $page && isset( $_GET['launch'] ) ) ) {
			// phpcs:enable
			wp_enqueue_media();
		}
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
	 * Con intl (region, language, weekday, month) l'etichetta è vuota: la scrive il builder con Intl.
	 *
	 * @return array<string, array{label: string, choices: list<array{string, string}>, intl?: string}>
	 */
	public static function presets(): array {
		$data  = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/presets.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file del plugin.
		$pairs = static fn( array $labels ): array => array_map( null, array_keys( $labels ), array_values( $labels ) );
		$intl  = static fn( array $values ): array => array_map( static fn( string $value ): array => [ $value, '' ], $values );
		return apply_filters(
			'barrio_forms_presets',
			[
				'countries'      => [
					'label'   => __( 'Countries', 'barrio-suite' ),
					'intl'    => 'region',
					'choices' => $intl( $data['countries'] ),
				],
				'regions_it'     => [
					'label'   => __( 'Italian regions', 'barrio-suite' ),
					'choices' => $data['regions_it'],
				],
				'provinces_it'   => [
					'label'   => __( 'Italian provinces', 'barrio-suite' ),
					'choices' => $data['provinces_it'],
				],
				'us_states'      => [
					'label'   => __( 'US states', 'barrio-suite' ),
					'choices' => $data['us_states'],
				],
				'continents'     => [
					'label'   => __( 'Continents', 'barrio-suite' ),
					'choices' => $pairs(
						[
							'africa'        => __( 'Africa', 'barrio-suite' ),
							'antarctica'    => __( 'Antarctica', 'barrio-suite' ),
							'asia'          => __( 'Asia', 'barrio-suite' ),
							'europe'        => __( 'Europe', 'barrio-suite' ),
							'north_america' => __( 'North America', 'barrio-suite' ),
							'oceania'       => __( 'Oceania', 'barrio-suite' ),
							'south_america' => __( 'South America', 'barrio-suite' ),
						]
					),
				],
				'days'           => [
					'label'   => __( 'Days of the week', 'barrio-suite' ),
					'intl'    => 'weekday',
					'choices' => $intl( [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ] ),
				],
				'months'         => [
					'label'   => __( 'Months', 'barrio-suite' ),
					'intl'    => 'month',
					'choices' => $intl( [ 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december' ] ),
				],
				'languages'      => [
					'label'   => __( 'Languages', 'barrio-suite' ),
					'intl'    => 'language',
					'choices' => $intl( $data['languages'] ),
				],
				// I titoli di studio italiani.
				'education'      => [
					'label'   => __( 'Education', 'barrio-suite' ),
					'choices' => $pairs(
						[
							'primary'             => __( 'Primary school certificate', 'barrio-suite' ),
							'lower_secondary'     => __( 'Lower secondary school certificate', 'barrio-suite' ),
							'upper_secondary'     => __( 'Upper secondary school diploma', 'barrio-suite' ),
							'bachelor'            => __( 'Bachelor\'s degree', 'barrio-suite' ),
							'master'              => __( 'Master\'s degree', 'barrio-suite' ),
							'first_level_master'  => __( 'First-level master', 'barrio-suite' ),
							'second_level_master' => __( 'Second-level master', 'barrio-suite' ),
							'phd'                 => __( 'PhD', 'barrio-suite' ),
						]
					),
				],
				'marital_status' => [
					'label'   => __( 'Marital status', 'barrio-suite' ),
					'choices' => $pairs(
						[
							'single'     => __( 'Single', 'barrio-suite' ),
							'married'    => __( 'Married', 'barrio-suite' ),
							'separated'  => __( 'Separated', 'barrio-suite' ),
							'divorced'   => __( 'Divorced', 'barrio-suite' ),
							'widowed'    => __( 'Widowed', 'barrio-suite' ),
							'cohabiting' => __( 'Cohabiting', 'barrio-suite' ),
						]
					),
				],
				'gender'         => [
					'label'   => __( 'Gender', 'barrio-suite' ),
					'choices' => $pairs(
						[
							'man'               => __( 'Man', 'barrio-suite' ),
							'woman'             => __( 'Woman', 'barrio-suite' ),
							'other'             => __( 'Other', 'barrio-suite' ),
							'prefer_not_to_say' => __( 'Prefer not to say', 'barrio-suite' ),
						]
					),
				],
			]
		);
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
