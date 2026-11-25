<?php
/**
 * Plugin Name:       Barrio Suite Migration
 * Description:       Moves the data of Suite Barrio, the previous plugin, to Barrio Suite from Barrio → Migration, one module at a time: forms, SEO, company details, popups, banners, opening hours, blocks, ordering, duplication, alt text and analytics. Once done, it can be removed.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Text Domain:       barrio-suite-migration
 * Domain Path:       /languages
 * Requires Plugins:  barrio-suite
 */

// Un file per modulo. I form si collegano solo con il loro modulo acceso; gli altri sempre, perché tengono spento il
// modulo finché il prototipo non è migrato.
//
// Ogni barrio_migration_{modulo}_run( $dry_run, &$report ) riempie il report (BarrioMigrationReport in
// phpstan.neon.dist) e restituisce null, o un WP_Error se non c'è niente da migrare.

use BarrioSuite\Admin;
use BarrioSuite\Database\Db;
use BarrioSuite\Modules;

defined( 'ABSPATH' ) || exit;

load_plugin_textdomain( 'barrio-suite-migration', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

require __DIR__ . '/forms.php';
require __DIR__ . '/seo.php';
require __DIR__ . '/popups.php';
require __DIR__ . '/banners.php';
require __DIR__ . '/hours.php';
require __DIR__ . '/company.php';
require __DIR__ . '/blocks.php';
require __DIR__ . '/ordering.php';
require __DIR__ . '/duplicate.php';
require __DIR__ . '/alt.php';
require __DIR__ . '/analytics.php';

// I moduli di Barrio Suite con il nome del loro modulo nel prototipo (Suite Barrio → Strumenti). stats e info_azienda
// non sono tra gli strumenti: nel prototipo sono sempre accesi. Nell'ordine della pagina Migrazione, dopo Forms e SEO:
// Dati aziendali prende Info Azienda e, dove non ha niente, lo schema del SEO.
const BARRIO_MIGRATION_MODULES = [
	'company'   => 'info_azienda',
	'popups'    => 'popups',
	'banners'   => 'banners',
	'hours'     => 'orari',
	'blocks'    => 'blocks',
	'ordering'  => 'orders',
	'duplicate' => 'duplicate',
	'alt'       => 'auto_alt',
	'analytics' => 'stats',
];

add_action( 'barrio_forms_loaded', 'barrio_migration_forms_boot' );

// Prima del boot di Barrio Suite (plugins_loaded, 10), che non carica i moduli spenti. Questo plugin si carica prima
// di Barrio Suite (ordine alfabetico), quindi le sue classi si controllano qui.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( Modules::class ) ) {
			barrio_migration_seo_boot();
			barrio_migration_modules_boot();
			add_action( 'admin_notices', 'barrio_migration_notice' );
			// Prima di Estensioni e Impostazioni (Admin::menu_end(), 11, aggiunta dopo).
			add_action( 'admin_menu', 'barrio_migration_menu', 11 );
			add_action( 'rest_api_init', 'barrio_migration_rest' );
			add_filter( 'barrio_admin_data', 'barrio_migration_admin_data' );
		}
	},
	9
);

function barrio_migration_modules_boot(): void {
	foreach ( BARRIO_MIGRATION_MODULES as $module => $prototype ) {
		// Altrimenti il sito avrebbe due popup, due link Duplica, due sincronizzazioni degli orari, e in GraphQL i tipi
		// del prototipo. Una volta sola: chi riaccende il modulo prima della migrazione lo sceglie.
		if ( barrio_migration_prototype( $prototype ) && false === get_option( "barrio_migration_{$module}" ) ) {
			Modules::set( $module, false );
			update_option( "barrio_migration_{$module}", 'held' );
		}
	}
	barrio_migration_popups_boot();
	barrio_migration_banners_boot();
}

// Il prototipo è attivo con quel modulo acceso.
function barrio_migration_prototype( string $module ): bool {
	return function_exists( 'suite_barrio_tools_module_enabled' ) && suite_barrio_tools_module_enabled( $module );
}

// I moduli trattenuti, con il link alla pagina che li migra.
function barrio_migration_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$titles = barrio_migration_titles();
	$held   = array_filter( [ 'seo', ...array_keys( BARRIO_MIGRATION_MODULES ) ], static fn( string $module ): bool => 'held' === get_option( "barrio_migration_{$module}" ) && ! Modules::is_enabled( $module ) );
	if ( $held ) {
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %s: list of module names. */
					__( 'These modules of Barrio Suite stay off until the same modules of Suite Barrio are migrated, so the site does not get them twice: %s.', 'barrio-suite-migration' ),
					implode( ', ', array_map( static fn( string $module ): string => $titles[ $module ][0], $held ) )
				)
			),
			esc_url( admin_url( 'admin.php?page=barrio-migration' ) ),
			esc_html__( 'Migrate them', 'barrio-suite-migration' )
		);
	}
}

/**
 * Nome e descrizione di ogni passaggio, nell'ordine della pagina.
 *
 * @return array<string, array{string, string}>
 */
function barrio_migration_titles(): array {
	return [
		'forms'     => [ __( 'Forms', 'barrio-suite-migration' ), __( 'Forms with the same ID, their entries and uploaded files, notifications and settings. Last, the old shortcodes in contents, meta and widgets become [barrio_form].', 'barrio-suite-migration' ) ],
		'seo'       => [ __( 'SEO', 'barrio-suite-migration' ), __( 'Settings, schema of the organization, meta of contents and terms, redirects.', 'barrio-suite-migration' ) ],
		'company'   => [ __( 'Company details', 'barrio-suite-migration' ), __( 'The company details of Suite Barrio (Info Azienda) first, then the SEO schema where Info Azienda has nothing. Only in the empty fields.', 'barrio-suite-migration' ) ],
		'popups'    => [ __( 'Popups', 'barrio-suite-migration' ), __( 'Popups with their translations: the active one stays on.', 'barrio-suite-migration' ) ],
		'banners'   => [ __( 'Banners', 'barrio-suite-migration' ), __( 'Banners with their translations: the active one stays on.', 'barrio-suite-migration' ) ],
		'hours'     => [ __( 'Opening hours', 'barrio-suite-migration' ), __( 'The Google Place ID and the last week read from Google.', 'barrio-suite-migration' ) ],
		'blocks'    => [ __( 'Blocks', 'barrio-suite-migration' ), __( 'The blocks allowed to each role and the editor of each content type.', 'barrio-suite-migration' ) ],
		'ordering'  => [ __( 'Ordering', 'barrio-suite-migration' ), __( 'The sortable types and taxonomies, with the order of the terms.', 'barrio-suite-migration' ) ],
		'duplicate' => [ __( 'Duplication', 'barrio-suite-migration' ), __( 'The content types with the Duplicate link.', 'barrio-suite-migration' ) ],
		'alt'       => [ __( 'Alt text', 'barrio-suite-migration' ), __( 'The alt text written on upload.', 'barrio-suite-migration' ) ],
		'analytics' => [ __( 'Analytics', 'barrio-suite-migration' ), __( 'The Google Analytics property, checked with the service account.', 'barrio-suite-migration' ) ],
	];
}

/**
 * I passaggi di Barrio → Migration, nell'ordine in cui farli. status: pending, migrated o empty (niente da migrare);
 * items: ciò che la pagina chiede uno per richiesta (i form), prima di chiudere con una senza item.
 *
 * @return list<array{id: string, title: string, description: string, status: string, path: string, items: list<int>, columns: array<string, string>}>
 */
function barrio_migration_steps(): array {
	$titles = barrio_migration_titles();
	$counts = [
		'data'     => __( 'Data', 'barrio-suite-migration' ),
		'found'    => __( 'Found', 'barrio-suite-migration' ),
		'migrated' => __( 'Migrated', 'barrio-suite-migration' ),
		'skipped'  => __( 'Skipped', 'barrio-suite-migration' ),
	];
	$steps  = [];
	foreach ( barrio_migration_ids() as $id ) {
		$columns = $counts;
		$items   = [];
		if ( 'forms' === $id ) {
			[ $items, $pending ] = barrio_migration_forms_ids();
			$status              = $pending ? 'pending' : ( $items ? 'migrated' : 'empty' );
			$columns             = [
				'id'            => __( 'ID', 'barrio-suite-migration' ),
				'title'         => __( 'Form', 'barrio-suite-migration' ),
				'entries'       => __( 'Entries', 'barrio-suite-migration' ),
				'migrated'      => __( 'Migrated', 'barrio-suite-migration' ),
				'files'         => __( 'Files', 'barrio-suite-migration' ),
				'missing_files' => __( 'Missing files', 'barrio-suite-migration' ),
				'result'        => __( 'Result', 'barrio-suite-migration' ),
			];
		} else {
			$state  = get_option( "barrio_migration_{$id}" );
			$status = match ( true ) {
				'migrated' === $state => 'migrated',
				'held' === $state || barrio_migration_prototype( BARRIO_MIGRATION_MODULES[ $id ] ?? 'seo' ) => 'pending',
				default => 'empty',
			};
			if ( 'seo' === $id ) {
				$columns = array_slice( $counts, 0, 3 ) + [ 'default' => __( 'Default', 'barrio-suite-migration' ) ] + $counts;
			}
		}
		$steps[] = [
			'id'          => $id,
			'title'       => $titles[ $id ][0],
			'description' => $titles[ $id ][1],
			'status'      => $status,
			'path'        => "/barrio/v1/migration/{$id}",
			'items'       => $items,
			'columns'     => $columns,
		];
	}
	return $steps;
}

// Forms solo con il suo modulo acceso: scrive con le sue classi e nelle sue tabelle.
/** @return list<string> */
function barrio_migration_ids(): array {
	return [ ...( Modules::is_enabled( 'forms' ) ? [ 'forms' ] : [] ), 'seo', ...array_keys( BARRIO_MIGRATION_MODULES ) ];
}

// Solo con qualcosa da migrare: un modulo del prototipo acceso, uno trattenuto o un form non ancora passato.
function barrio_migration_menu(): void {
	if ( current_user_can( 'manage_options' ) && in_array( 'pending', array_column( barrio_migration_steps(), 'status' ), true ) ) {
		Admin::add_page( 'barrio-migration', __( 'Migration', 'barrio-suite-migration' ), 'manage_options' );
	}
}

/**
 * Per la pagina Migrazione del core, in window.barrioAdmin.migration.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function barrio_migration_admin_data( array $data ): array {
	return current_user_can( 'manage_options' ) ? $data + [ 'migration' => barrio_migration_steps() ] : $data;
}

function barrio_migration_rest(): void {
	$admin = static fn(): bool => current_user_can( 'manage_options' );
	register_rest_route(
		'barrio/v1',
		'/migration/(?P<step>[a-z]+)',
		[
			'methods'             => 'POST',
			'callback'            => 'barrio_migration_rest_run',
			'permission_callback' => $admin,
			'args'                => [
				'step'    => [
					'type' => 'string',
					'enum' => barrio_migration_ids(),
				],
				'dry_run' => [
					'type'     => 'boolean',
					'required' => true,
				],
				'item'    => [
					'type'    => 'integer',
					'minimum' => 1,
				],
			],
		]
	);
}

/**
 * Un passaggio, o un suo item (un form), con il passaggio com'è dopo.
 *
 * @return array{rows: list<array<string, int|string>>, notes: list<string>, warnings: list<string>, step: array<string, mixed>}|WP_Error
 */
function barrio_migration_rest_run( WP_REST_Request $request ): array|WP_Error {
	global $wpdb;
	// Due richieste insieme creerebbero popup e banner due volte. Il lock cade con la connessione, quindi anche dopo un
	// tempo scaduto; il nome include il database, come in Migrator.
	$lock = 'barrio_migration_' . md5( DB_NAME . '.' . $wpdb->prefix ); // @phpstan-ignore constant.notFound (definita in wp-config.php)
	if ( '1' !== Db::var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
		return new WP_Error( 'barrio_migration_running', 'Another migration is running.', [ 'status' => 409 ] );
	}
	// Un form con molte registrazioni o file può durare: il limite si alza, mai si abbassa. Quello dell'hosting resta:
	// un form interrotto si annulla da solo e si rilancia.
	$limit = (int) ini_get( 'max_execution_time' );
	if ( $limit > 0 && $limit < 300 && function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 );
	}
	$step    = (string) $request['step'];
	$dry_run = (bool) $request['dry_run'];
	try {
		$report = match ( true ) {
			'forms' === $step => isset( $request['item'] ) ? barrio_migration_run( $dry_run, [ (int) $request['item'] ], false ) : barrio_migration_run( $dry_run, [] ),
			'seo' === $step => barrio_migration_seo_run( $dry_run ),
			array_key_exists( $step, BARRIO_MIGRATION_MODULES ) => barrio_migration_module_run( $step, $dry_run ),
			default => new WP_Error( 'rest_invalid_param', 'Unknown step.' ),
		};
	} catch ( Throwable $e ) {
		// Un errore del database (Db lancia RuntimeException) con il suo motivo, non come errore critico di WordPress.
		return new WP_Error( 'barrio_migration_error', $e->getMessage(), [ 'status' => 500 ] );
	} finally {
		Db::var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
	if ( $report instanceof WP_Error ) {
		return new WP_Error( $report->get_error_code(), $report->get_error_message(), [ 'status' => 400 ] );
	}
	return [
		'rows'     => $report['forms'] ?? $report['counts'],
		'notes'    => $report['notes'],
		'warnings' => $report['warnings'],
		'step'     => current( wp_list_filter( barrio_migration_steps(), [ 'id' => $step ] ) ),
	];
}

// L'indirizzo del frontend headless (barrio_frontend, di barrio-suite-wpgraphql) da un URL del prototipo; vuoto se è
// quello di WordPress, o se Barrio Suite ne ha già uno, che resta.
function barrio_migration_frontend( string $url ): string {
	$url = trim( $url );
	if ( '' === $url || ! empty( ( (array) get_option( 'barrio_frontend', [] ) )['url'] ) ) {
		return '';
	}
	$url = untrailingslashit( esc_url_raw( preg_match( '#^https?://#i', $url ) ? $url : "https://{$url}", [ 'http', 'https' ] ) );
	return untrailingslashit( home_url() ) === $url ? '' : $url;
}

/**
 * Il passaggio di un modulo, poi lo scambio tra il modulo del prototipo e quello di Barrio Suite. Si può rilanciare:
 * ciò che Barrio Suite ha già resta com'è. La prova a vuoto dà gli stessi conteggi e avvisi e non scrive niente.
 *
 * @param key-of<BARRIO_MIGRATION_MODULES> $module
 * @return BarrioMigrationReport|WP_Error
 */
function barrio_migration_module_run( string $module, bool $dry_run ): array|WP_Error {
	$report = [
		'counts'   => [],
		'notes'    => [],
		'warnings' => [],
	];
	$error  = match ( $module ) {
		'popups'    => barrio_migration_popups_run( $dry_run, $report ),
		'banners'   => barrio_migration_banners_run( $dry_run, $report ),
		'hours'     => barrio_migration_hours_run( $dry_run, $report ),
		'company'   => barrio_migration_company_run( $dry_run, $report ),
		'blocks'    => barrio_migration_blocks_run( $dry_run, $report ),
		'ordering'  => barrio_migration_ordering_run( $dry_run, $report ),
		'duplicate' => barrio_migration_duplicate_run( $dry_run, $report ),
		'alt'       => barrio_migration_alt_run( $dry_run, $report ),
		'analytics' => barrio_migration_analytics_run( $dry_run, $report ),
	};
	// Il modulo del prototipo acceso ma mai configurato: senza lo scambio quello di Barrio Suite resterebbe spento.
	if ( $error && 'barrio_migration_no_prototype' === $error->get_error_code() && barrio_migration_prototype( BARRIO_MIGRATION_MODULES[ $module ] ) ) {
		$report['notes'][] = $error->get_error_message() . ' Nothing to migrate.';
		$error             = null;
	}
	if ( $error || $dry_run ) {
		return $error ?? $report;
	}
	update_option( 'suite_barrio_tools_modules', [ BARRIO_MIGRATION_MODULES[ $module ] => 0 ] + (array) get_option( 'suite_barrio_tools_modules', [] ) );
	Modules::set( $module, true );
	update_option( "barrio_migration_{$module}", 'migrated' );
	return $report;
}

/**
 * Crea le tabelle di un modulo anche spento: le crea la sua prima accensione, ma qui si scrive prima.
 *
 * @param key-of<BARRIO_MIGRATION_MODULES> $module
 */
function barrio_migration_tables( string $module ): ?WP_Error {
	$migrator = Modules::migrator( $module );
	$error    = $migrator->run();
	if ( $error || ! $migrator->is_current() ) {
		return $error ?? new WP_Error( 'barrio_migration_tables', "The tables of the Barrio Suite {$module} module are being created by another request: migrate again." );
	}
	return null;
}

function barrio_migration_table_exists( string $table ): bool {
	global $wpdb;
	return Db::var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
}

/**
 * Le traduzioni di popup o banner del prototipo, per ID e lingua, senza la principale, che è nella riga del record.
 * La tabella c'è dalla versione 1.1 dei moduli: prima c'era una lingua sola.
 *
 * @return array<int, array<string, array<string, string|null>>>
 */
function barrio_migration_translations( string $table, string $key, string $main ): array {
	global $wpdb;
	$texts = [];
	if ( barrio_migration_table_exists( $table ) ) {
		foreach ( Db::rows( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ) ) as $text ) {
			$lang = barrio_migration_lang( (string) $text['lang'], $main );
			if ( $lang !== $main ) {
				$texts[ (int) $text[ $key ] ][ $lang ] = $text;
			}
		}
	}
	return $texts;
}

/**
 * I contenuti con gli shortcode del prototipo, con i loro attributi: dopo la migrazione non stampano niente.
 *
 * @param list<string> $tags
 * @return list<string>
 */
function barrio_migration_shortcode_uses( array $tags ): array {
	global $wpdb;
	$like  = implode( ' OR ', array_map( static fn( string $tag ): string => $wpdb->prepare( 'post_content LIKE %s', '%' . $wpdb->esc_like( "[{$tag}" ) . '%' ), $tags ) );
	$posts = Db::rows( $wpdb->prepare( 'SELECT ID, post_title, post_type, post_content FROM %i WHERE post_type <> %s AND post_status <> %s AND (', $wpdb->posts, 'revision', 'auto-draft' ) . $like . ') ORDER BY ID' );
	$uses  = [];
	foreach ( $posts as $post ) {
		preg_match_all( '/' . get_shortcode_regex( $tags ) . '/', (string) $post['post_content'], $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$uses[] = "{$post['post_type']} {$post['ID']} \"{$post['post_title']}\": {$match[0]}";
		}
	}
	return $uses;
}

/**
 * Un avviso per ogni dato pieno con una chiave che il prototipo, in tutta la storia del suo repo, non ha mai avuto: viene
 * da una versione modificata per un sito, e senza avviso si perderebbe. Solo il nome: il valore
 * può essere una chiave segreta.
 *
 * @param array<mixed>  $data
 * @param list<string>  $known
 * @param list<string>  $warnings
 */
function barrio_migration_unknown( string $where, array $data, array $known, array &$warnings ): void {
	foreach ( array_keys( array_diff_key( array_filter( $data ), array_flip( $known ) ) ) as $key ) {
		$warnings[] = "{$where}: Suite Barrio never had \"{$key}\" (a version changed for this site?), it was not migrated.";
	}
}

/**
 * Le opzioni del modulo, per prefisso, che il prototipo non ha mai avuto. Quelle per ID (le colonne scelte di un form)
 * valgono per il nome senza l'ID.
 *
 * @param list<string> $prefixes
 * @param list<string> $known
 * @param list<string> $warnings
 */
function barrio_migration_unknown_options( string $where, array $prefixes, array $known, array &$warnings ): void {
	global $wpdb;
	$options = [];
	foreach ( $prefixes as $prefix ) {
		foreach ( Db::col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_name', $wpdb->options, $wpdb->esc_like( $prefix ) . '%' ) ) as $name ) {
			if ( ! in_array( preg_replace( '/_\d+$/', '', $name ), $known, true ) ) {
				$options[ $name ] = get_option( $name );
			}
		}
	}
	barrio_migration_unknown( $where, $options, [], $warnings );
}

/**
 * Le colonne di una tabella del prototipo che il prototipo non ha mai avuto, anche vuote: sono già una modifica.
 *
 * @param list<string> $known
 * @param list<string> $warnings
 */
function barrio_migration_unknown_columns( string $where, string $table, array $known, array &$warnings ): void {
	global $wpdb;
	$columns = array_diff( Db::col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) ), $known );
	barrio_migration_unknown( $where, array_fill_keys( array_map( static fn( string $column ): string => "{$table}.{$column}", $columns ), true ), [], $warnings );
}

/**
 * Un link come lo vogliono popup e banner (un percorso o http/https), o null.
 */
function barrio_migration_link( string $link ): ?string {
	$link = esc_url_raw( trim( $link ) );
	return preg_match( '#^(/|https?://)#', $link ) && strlen( $link ) <= 2048 ? $link : null;
}
