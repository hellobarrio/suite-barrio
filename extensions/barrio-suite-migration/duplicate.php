<?php
// Il passaggio della duplicazione del prototipo, che salvava i tipi e le tassonomie abilitati (nessuno di default):
// Barrio Suite salva quelli spenti, quindi tutti gli altri si spengono e il sito resta com'era.

use BarrioSuite\Admin;

defined( 'ABSPATH' ) || exit;

/** @param BarrioMigrationReport $report */
function barrio_migration_duplicate_run( bool $dry_run, array &$report ): ?WP_Error {
	$saved = get_option( 'suite_barrio_duplicate_settings' );
	if ( false === $saved && ! barrio_migration_prototype( 'duplicate' ) ) {
		return new WP_Error( 'barrio_migration_no_prototype', 'There are no duplicate settings of Suite Barrio in this database.' );
	}
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
		$settings[ $group ] = array_values( array_diff( array_column( $types[ $group ], 'name' ), $names ) );
	}
	$on                = array_intersect( [ ...array_column( $types['post_types'], 'name' ), ...array_column( $types['taxonomies'], 'name' ) ], [ ...$enabled['post_types'], ...$enabled['taxonomies'] ] );
	$report['notes'][] = 'Duplicate: only on ' . ( implode( ', ', $on ) ?: 'nothing' ) . ', as in Suite Barrio. The others can be turned on in Barrio → Contents → Duplicate.';
	if ( ! $dry_run ) {
		update_option( 'barrio_duplicate', $settings, false );
	}
	return null;
}
