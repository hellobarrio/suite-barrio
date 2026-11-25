<?php
// Il passaggio dei banner del prototipo: le righe di wpb_banners e wpb_banners_translations diventano banner di Barrio
// Suite con BannerRepository, ognuno nella sua transazione. Il titolo del prototipo è il testo. I banner migrati si
// ricordano in barrio_migration_banners_ids (ID del prototipo → ID di Barrio Suite) e al giro dopo si saltano.

use BarrioSuite\Banners\BannerRepository;
use BarrioSuite\Database\Db;
use BarrioSuite\I18n;

defined( 'ABSPATH' ) || exit;

const BARRIO_MIGRATION_BANNER_TAGS = [ 'suite_barrio_banner', 'wpb_banner' ];

function barrio_migration_banners_boot(): void {
	// Vuoti: il banner è in cima a tutte le pagine. Se il modulo del prototipo è acceso restano suoi.
	add_action(
		'init',
		static function (): void {
			foreach ( BARRIO_MIGRATION_BANNER_TAGS as $tag ) {
				if ( ! shortcode_exists( $tag ) ) {
					add_shortcode( $tag, '__return_empty_string' );
				}
			}
		},
		20
	);
}

/** @param BarrioMigrationReport $report */
function barrio_migration_banners_run( bool $dry_run, array &$report ): ?WP_Error {
	global $wpdb;
	$table = "{$wpdb->prefix}wpb_banners";
	if ( ! barrio_migration_table_exists( $table ) ) {
		return new WP_Error( 'barrio_migration_no_prototype', 'The banners of Suite Barrio are not in this database.' );
	}
	$error = $dry_run ? null : barrio_migration_tables( 'banners' );
	if ( $error ) {
		return $error;
	}
	barrio_migration_unknown_columns( 'Banners', $table, [ 'id', 'title', 'link', 'link_target', 'active', 'created_at', 'updated_at' ], $report['warnings'] );
	if ( barrio_migration_table_exists( "{$table}_translations" ) ) {
		barrio_migration_unknown_columns( 'Banners', "{$table}_translations", [ 'id', 'banner_id', 'lang', 'title', 'link', 'link_target', 'updated_at' ], $report['warnings'] );
	}
	barrio_migration_unknown_options( 'Banners', [ 'suite_barrio_banners_' ], [ 'suite_barrio_banners_db_version', 'suite_barrio_banners_limit' ], $report['warnings'] );
	$main   = I18n::languages()[0];
	$texts  = barrio_migration_translations( "{$table}_translations", 'banner_id', $main );
	$map    = array_map( 'intval', (array) get_option( 'barrio_migration_banners_ids', [] ) );
	$counts = [
		'data'     => 'banners',
		'found'    => 0,
		'migrated' => 0,
		'skipped'  => 0,
	];
	foreach ( Db::rows( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ) ) as $row ) {
		$id = (int) $row['id'];
		++$counts['found'];
		if ( isset( $map[ $id ] ) ) {
			++$counts['skipped'];
			continue;
		}
		$banner = barrio_migration_banner( $row, $texts[ $id ] ?? [], $main, $report['warnings'] );
		$saved  = $dry_run ? rest_validate_value_from_schema( $banner, BannerRepository::schema(), 'banner' ) : BannerRepository::save( null, $banner );
		if ( is_wp_error( $saved ) ) {
			$report['warnings'][] = "Banner {$id} not migrated: " . $saved->get_error_message();
			++$counts['skipped'];
			continue;
		}
		if ( ! $dry_run ) {
			$map[ $id ] = (int) $saved;
			update_option( 'barrio_migration_banners_ids', $map, false );
		}
		++$counts['migrated'];
	}
	$report['counts'][] = $counts;
	$report['notes'][]  = 'The active banner stays on, the others stay off. The banner is at the top of every page (wp_body_open): a theme file that printed the old shortcode in a precise place of the header prints nothing now (search the theme for suite_barrio_banner and wpb_banner).';
	$uses               = barrio_migration_shortcode_uses( BARRIO_MIGRATION_BANNER_TAGS );
	if ( $uses ) {
		$report['warnings'][] = 'Shortcodes that now print nothing, because the banner is at the top of every page: ' . implode( '; ', $uses ) . '.';
	}
	return null;
}

/**
 * Un banner del prototipo come lo scrive BannerRepository, con le righe di testo complete come i popup.
 *
 * @param array<string, string|null>                $row
 * @param array<string, array<string, string|null>> $translations Per lingua, senza la principale.
 * @param list<string>                              $warnings
 * @return array<string, mixed>
 */
function barrio_migration_banner( array $row, array $translations, string $main, array &$warnings ): array {
	$id   = (int) $row['id'];
	$base = [
		'title' => trim( (string) $row['title'] ),
		'link'  => trim( (string) $row['link'] ),
	];
	$i18n = [];
	foreach ( [ $main => $base ] + $translations as $lang => $text ) {
		$text = [
			'title' => trim( (string) ( $text['title'] ?? '' ) ) ?: $base['title'],
			'link'  => trim( (string) ( $text['link'] ?? '' ) ) ?: $base['link'],
		];
		$link = barrio_migration_link( $text['link'] );
		if ( null === $link && '' !== $text['link'] ) {
			$warnings[] = "Banner {$id} ({$lang}): the link \"{$text['link']}\" is not an address or a path, so the banner has no link.";
		}
		$i18n[ $lang ] = [
			'text'     => sanitize_text_field( $text['title'] ),
			'link_url' => $link,
		];
	}
	return [
		'is_enabled' => '1' === $row['active'],
		'new_tab'    => '_blank' === $row['link_target'],
		'starts_at'  => null,
		'ends_at'    => null,
		'i18n'       => $i18n,
	];
}
