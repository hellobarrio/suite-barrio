<?php
// Il passaggio dell'ordinamento del prototipo: i tipi e le tassonomie abilitati, e l'ordine dei termini, che dal meta
// suite_barrio_term_order passa a _barrio_order. L'ordine dei contenuti è già menu_order, del core.

use BarrioSuite\Database\Db;
use BarrioSuite\Ordering\OrderRepository;

defined( 'ABSPATH' ) || exit;

/** @param BarrioMigrationReport $report */
function barrio_migration_ordering_run( bool $dry_run, array &$report ): ?WP_Error {
	global $wpdb;
	$saved = get_option( 'suite_barrio_orders_settings' );
	$found = (int) Db::var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key = %s', $wpdb->termmeta, 'suite_barrio_term_order' ) );
	if ( false === $saved && ! $found ) {
		return new WP_Error( 'barrio_migration_no_prototype', 'There are no ordering settings of Suite Barrio in this database.' );
	}

	if ( false !== get_option( 'barrio_ordering' ) ) {
		$report['notes'][] = 'Ordering: Barrio Suite already has its settings, they stay as they are.';
	} else {
		$valid             = static fn( mixed $names ): array => array_values( array_unique( array_filter( (array) $names, static fn( mixed $name ): bool => is_string( $name ) && preg_match( '/^[a-z0-9_-]{1,32}$/', $name ) ) ) );
		$settings          = [
			'post_types' => $valid( ( (array) $saved )['enabled_post_types'] ?? [] ),
			'taxonomies' => $valid( ( (array) $saved )['enabled_taxonomies'] ?? [] ),
		];
		$report['notes'][] = 'Ordering: manual order for ' . ( implode( ', ', [ ...$settings['post_types'], ...$settings['taxonomies'] ] ) ?: 'nothing' ) . '.';
		if ( ! $dry_run ) {
			update_option( 'barrio_ordering', $settings, true );
		}
	}

	// I termini che hanno già un ordine di Barrio Suite lo tengono. Una sola istruzione: o tutti o nessuno.
	$select = $wpdb->prepare( 'FROM %i AS old LEFT JOIN %i AS new ON new.term_id = old.term_id AND new.meta_key = %s WHERE old.meta_key = %s AND new.meta_id IS NULL', $wpdb->termmeta, $wpdb->termmeta, OrderRepository::TERM_META, 'suite_barrio_term_order' );
	$ids    = array_map( 'intval', Db::col( "SELECT DISTINCT old.term_id {$select}" ) );
	if ( ! $dry_run && $ids ) {
		Db::query( $wpdb->prepare( 'INSERT INTO %i (term_id, meta_key, meta_value) SELECT old.term_id, %s, CAST(old.meta_value AS SIGNED) ', $wpdb->termmeta, OrderRepository::TERM_META ) . $select );
		wp_cache_delete_multiple( $ids, 'term_meta' );
		wp_cache_set_terms_last_changed();
	}
	$report['counts'][] = [
		'data'     => 'term order',
		'found'    => $found,
		'migrated' => count( $ids ),
		'skipped'  => $found - count( $ids ),
	];
	return null;
}
