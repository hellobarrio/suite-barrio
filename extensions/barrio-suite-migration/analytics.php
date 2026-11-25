<?php
// Il passaggio delle statistiche del prototipo: passano la proprietà GA4 e "sostituisci la bacheca". Client OAuth e
// token no, perché Barrio Suite legge GA4 con il service account; il refresh token, in chiaro, alla fine si elimina.

use BarrioSuite\Analytics\Module as Analytics;
use BarrioSuite\Google;

defined( 'ABSPATH' ) || exit;

/** @param BarrioMigrationReport $report */
function barrio_migration_analytics_run( bool $dry_run, array &$report ): ?WP_Error {
	$saved  = (array) get_option( 'suite_barrio_stats_settings', [] );
	$tokens = false !== get_option( 'suite_barrio_stats_tokens' );
	if ( ! $saved && ! $tokens ) {
		return new WP_Error( 'barrio_migration_no_prototype', 'There are no analytics settings of Suite Barrio in this database.' );
	}
	barrio_migration_unknown_options( 'Analytics', [ 'suite_barrio_stats_' ], [ 'suite_barrio_stats_settings', 'suite_barrio_stats_tokens' ], $report['warnings'] );
	barrio_migration_unknown( 'Analytics, suite_barrio_stats_settings', $saved, [ 'client_id', 'client_secret', 'property_id', 'replace_dashboard' ], $report['warnings'] );
	$number   = preg_replace( '/\D/', '', (string) ( $saved['property_id'] ?? '' ) );
	$property = '' === $number ? '' : "properties/{$number}";
	$replace  = ! empty( $saved['replace_dashboard'] );

	if ( false !== get_option( 'barrio_analytics' ) ) {
		$report['notes'][] = 'Analytics: Barrio Suite already has its settings, they stay as they are.';
	} else {
		$report['notes'][] = 'Analytics: property ' . ( $property ?: 'none' ) . ', replace the WordPress Dashboard ' . ( $replace ? 'on' : 'off' ) . '.';
		if ( $replace ) {
			$report['notes'][] = 'Analytics: the WordPress Dashboard is replaced only for who reads the statistics (barrio_view_analytics, administrators and editors, Analytics → Settings → Permissions): the others get the WordPress Dashboard back.';
		}
		if ( ! $dry_run ) {
			update_option(
				'barrio_analytics',
				[
					'property'          => $property,
					'replace_dashboard' => $replace,
				],
				false
			);
		}
	}
	barrio_migration_analytics_access( $property, $report['warnings'] );

	if ( $tokens ) {
		if ( ! $dry_run ) {
			delete_option( 'suite_barrio_stats_tokens' );
		}
		$client            = trim( (string) ( $saved['client_id'] ?? '' ) );
		$report['notes'][] = ( $dry_run ? 'To delete: ' : 'Deleted: ' ) . 'the Google refresh token of Suite Barrio. Delete its OAuth client' . ( '' === $client ? '' : " {$client}" ) . ' in Google Cloud → APIs & Services → Credentials: Barrio Suite does not need it.';
	}
	return null;
}

/**
 * Il service account deve vedere la proprietà, come Visualizzatore: se non la vede, il motivo e l'email da aggiungere.
 *
 * @param list<string> $warnings
 */
function barrio_migration_analytics_access( string $property, array &$warnings ): void {
	if ( '' === $property ) {
		return;
	}
	$email = Google::account()['client_email'] ?? null;
	if ( null === $email ) {
		$warnings[] = "Analytics: add the Google service account in Barrio → Settings, then add its email as Viewer of {$property} in GA4 → Admin → Property access management.";
		return;
	}
	$visible = Analytics::properties();
	if ( is_wp_error( $visible ) ) {
		$warnings[] = 'Analytics: Google did not list the properties: ' . $visible->get_error_message();
	} elseif ( ! in_array( $property, array_column( (array) $visible->get_data(), 'value' ), true ) ) {
		$warnings[] = "Analytics: the service account does not see {$property}. Add {$email} as Viewer in GA4 → Admin → Property access management, with Google Analytics Data API and Google Analytics Admin API enabled in its project.";
	}
}
