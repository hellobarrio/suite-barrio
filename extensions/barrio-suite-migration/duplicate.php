<?php
// Il passaggio della duplicazione del prototipo, che salvava i tipi e le tassonomie abilitati (nessuno di default):
// Barrio Suite salva una scelta per ognuno, così il sito resta com'era.

use BarrioSuite\Admin;

defined( 'ABSPATH' ) || exit;

/** @param BarrioMigrationReport $report */
function barrio_migration_duplicate_run( bool $dry_run, array &$report ): ?WP_Error {
	$saved = get_option( 'suite_barrio_duplicate_settings' );
	if ( false === $saved && ! barrio_migration_prototype( 'duplicate' ) ) {
		return new WP_Error( 'barrio_migration_no_prototype', 'There are no duplicate settings of Suite Barrio in this database.' );
	}
	barrio_migration_unknown_options( 'Duplicate', [ 'suite_barrio_duplicate_' ], [ 'suite_barrio_duplicate_settings' ], $report['warnings'] );
	barrio_migration_unknown( 'Duplicate, suite_barrio_duplicate_settings', (array) $saved, [ 'enabled_post_types', 'enabled_taxonomies' ], $report['warnings'] );
	if ( false !== get_option( 'barrio_duplicate' ) ) {
		$report['notes'][] = 'Duplicate: Barrio Suite already has its settings, they stay as they are.';
		return null;
	}
	$types    = Admin::content_types();
	$enabled  = [
		'post_types' => (array) ( ( (array) $saved )['enabled_post_types'] ?? [] ),
		'taxonomies' => (array) ( ( (array) $saved )['enabled_taxonomies'] ?? [] ),
	];
	$settings = [];
	foreach ( $enabled as $group => $names ) {
		$all                = array_column( $types[ $group ], 'name' );
		$settings[ $group ] = array_combine( $all, array_map( static fn( string $name ): bool => in_array( $name, $names, true ), $all ) );
	}
	$on                = array_intersect( [ ...array_column( $types['post_types'], 'name' ), ...array_column( $types['taxonomies'], 'name' ) ], [ ...$enabled['post_types'], ...$enabled['taxonomies'] ] );
	$report['notes'][] = 'Duplicate: only on ' . ( implode( ', ', $on ) ?: 'nothing' ) . ', as in Suite Barrio. The others can be turned on in Barrio → Contents → Duplicate.';
	if ( ! $dry_run ) {
		update_option( 'barrio_duplicate', $settings, false );
	}
	return null;
}
