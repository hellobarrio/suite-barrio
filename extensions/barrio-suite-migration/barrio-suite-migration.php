<?php
/**
 * Plugin Name:       Barrio Suite Migration
 * Description:       Moves the data of Suite Barrio, the previous plugin, to Barrio Suite with one WP-CLI command per module: forms, SEO, popups, banners, opening hours, company details, blocks, ordering, duplicate, alt text and analytics. Once done, it can be removed.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/hellobarrio/suite-barrio
 * Text Domain:       barrio-suite-migration
 * Domain Path:       /languages
 * Requires Plugins:  barrio-suite
 */

// Un file per modulo. I form si collegano solo con il loro modulo acceso; gli altri sempre, perché tengono spento il
// modulo finché il prototipo non è migrato.
//
// Ogni barrio_migration_{modulo}_run( $dry_run, &$report ) riempie il report (BarrioMigrationReport in
// phpstan.neon.dist) e restituisce null, o un WP_Error se non c'è niente da migrare.

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
// non sono tra gli strumenti: nel prototipo sono sempre accesi.
const BARRIO_MIGRATION_MODULES = [
	'popups'    => 'popups',
	'banners'   => 'banners',
	'hours'     => 'orari',
	'company'   => 'info_azienda',
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
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command(
				"barrio {$module} migrate",
				static function ( array $args, array $assoc ) use ( $module ): void {
					barrio_migration_module_command( $module, isset( $assoc['dry-run'] ) );
				},
				[
					'shortdesc' => "Moves the {$module} data of Suite Barrio to Barrio Suite, then turns the module of Suite Barrio off and the one of Barrio Suite on.",
					'synopsis'  => [
						[
							'type'        => 'flag',
							'name'        => 'dry-run',
							'optional'    => true,
							'description' => 'Shows the counts and warnings without saving anything.',
						],
					],
				]
			);
		}
	}
	add_action( 'admin_notices', 'barrio_migration_modules_notice' );
	barrio_migration_popups_boot();
	barrio_migration_banners_boot();
	add_action( 'barrio_hours_changed', static fn() => barrio_migration_revalidate( 'wp:orari' ) );
	// wp:option nel frontend copre anche lo schema, che prende da qui nome, contatti e indirizzo.
	add_action( 'barrio_company_changed', static fn() => barrio_migration_revalidate( 'wp:option' ) );
	add_action( 'barrio_ordering_changed', static fn( string $kind, string $name ) => barrio_migration_revalidate( "wp:type:{$name}" ), 10, 2 );
}

// Il prototipo è attivo con quel modulo acceso.
function barrio_migration_prototype( string $module ): bool {
	return function_exists( 'suite_barrio_tools_module_enabled' ) && suite_barrio_tools_module_enabled( $module );
}

function barrio_migration_modules_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$held = array_filter( array_keys( BARRIO_MIGRATION_MODULES ), static fn( string $module ): bool => 'held' === get_option( "barrio_migration_{$module}" ) && ! Modules::is_enabled( $module ) );
	if ( $held ) {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			sprintf(
				/* translators: %s: list of WP-CLI commands. */
				esc_html__( 'These modules of Barrio Suite stay off until the same modules of Suite Barrio are migrated, so the site does not get them twice. Run each command with --dry-run first: %s.', 'barrio-suite-migration' ),
				implode( ', ', array_map( static fn( string $module ): string => "<code>wp barrio {$module} migrate</code>", $held ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nomi fissi dei moduli.
			)
		);
	}
}

// Il ponte verso la revalidation di Next.js del prototipo, finché è attivo, con i suoi tag.
function barrio_migration_revalidate( string $tag ): void {
	if ( function_exists( 'suite_barrio_invalidate_vercel_cache' ) ) {
		suite_barrio_invalidate_vercel_cache( $tag );
	}
}

// L'indirizzo del frontend headless (GraphQL → Settings) da un URL del prototipo; vuoto se è quello di WordPress.
function barrio_migration_frontend( string $url ): string {
	$url = trim( $url );
	$url = '' === $url ? '' : untrailingslashit( esc_url_raw( preg_match( '#^https?://#i', $url ) ? $url : "https://{$url}", [ 'http', 'https' ] ) );
	return untrailingslashit( home_url() ) === $url ? '' : $url;
}

/**
 * Una revalidation a ogni inizio e fine futuri degli accesi, perché lì cambia ciò che il frontend mostra senza che
 * niente si salvi. Il timestamp negli argomenti tiene distinti due eventi a meno di 10 minuti.
 *
 * @param callable(): list<array{is_enabled: bool, starts_at: string|null, ends_at: string|null}> $all
 */
function barrio_migration_schedule( string $hook, callable $all ): void {
	wp_unschedule_hook( $hook );
	if ( ! function_exists( 'suite_barrio_invalidate_vercel_cache' ) ) {
		return;
	}
	foreach ( array_filter( $all(), static fn( array $item ): bool => $item['is_enabled'] ) as $item ) {
		foreach ( [ $item['starts_at'], $item['ends_at'] ] as $date ) {
			$time = null === $date ? 0 : (int) strtotime( "{$date} UTC" );
			if ( $time > time() ) {
				wp_schedule_single_event( $time, $hook, [ $time ] );
			}
		}
	}
}

/** @param key-of<BARRIO_MIGRATION_MODULES> $module */
function barrio_migration_module_command( string $module, bool $dry_run ): void {
	$report = barrio_migration_module_run( $module, $dry_run );
	if ( $report instanceof WP_Error ) {
		WP_CLI::error( $report->get_error_message() );
	}
	if ( $report['counts'] ) {
		WP_CLI\Utils\format_items( 'table', $report['counts'], [ 'data', 'found', 'migrated', 'skipped' ] );
	}
	foreach ( $report['notes'] as $note ) {
		WP_CLI::log( $note );
	}
	foreach ( $report['warnings'] as $warning ) {
		WP_CLI::warning( $warning );
	}
	WP_CLI::success(
		$dry_run
			? 'Dry run: nothing was saved. Run the command without --dry-run to migrate.'
			: "Migration complete: the {$module} module of Suite Barrio is off and the one of Barrio Suite is on. Empty the page cache."
	);
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
		return $error ?? new WP_Error( 'barrio_migration_tables', "The tables of the Barrio Suite {$module} module are being created by another request: run the command again." );
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
 * Un link come lo vogliono popup e banner (un percorso o http/https), o null.
 */
function barrio_migration_link( string $link ): ?string {
	$link = esc_url_raw( trim( $link ) );
	return preg_match( '#^(/|https?://)#', $link ) && strlen( $link ) <= 2048 ? $link : null;
}
