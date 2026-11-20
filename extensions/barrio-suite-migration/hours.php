<?php
// Il passaggio degli orari del prototipo: il Place ID passa e la fonte diventa Google; la settimana parte dagli ultimi
// dati che il prototipo aveva preso da Google, fino alla prima sincronizzazione di Barrio Suite (ogni notte alle 3).

use BarrioSuite\Google;
use BarrioSuite\Hours\Module as Hours;

defined( 'ABSPATH' ) || exit;

// I giorni del prototipo, chiavi in italiano, come giorni ISO.
const BARRIO_MIGRATION_DAYS = [
	'Lunedì'    => 1,
	'Martedì'   => 2,
	'Mercoledì' => 3,
	'Giovedì'   => 4,
	'Venerdì'   => 5,
	'Sabato'    => 6,
	'Domenica'  => 7,
];

/** @param BarrioMigrationReport $report */
function barrio_migration_hours_run( bool $dry_run, array &$report ): ?WP_Error {
	$options = (array) get_option( 'suite_barrio_orari_settings', [] );
	$data    = (array) get_option( 'suite_barrio_orari_data', [] );
	if ( ! $options && ! $data ) {
		return new WP_Error( 'barrio_migration_no_prototype', 'There are no opening hours of Suite Barrio in this database.' );
	}
	if ( '' !== trim( (string) ( $options['google_api_key'] ?? '' ) ) ) {
		$report['warnings'][] = 'Opening hours: the API key of Suite Barrio is not migrated. Barrio Suite connects to Google with the service account of Barrio → Settings, with Places API (New) enabled in its project.' . ( null === Google::account() ? ' Add it before the next sync.' : '' );
	}
	if ( ! $dry_run ) {
		// Il cron del prototipo, che restava anche spegnendo il modulo.
		wp_clear_scheduled_hook( 'suite_barrio_cron_aggiorna_orari' );
	}
	if ( false !== get_option( 'barrio_hours' ) ) {
		$report['notes'][] = 'Opening hours: Barrio Suite already has its own, they stay as they are.';
		return null;
	}

	$week  = array_fill( 1, 7, [] );
	$slots = 0;
	foreach ( (array) ( $data['orari_settimanali'] ?? [] ) as $day => $ranges ) {
		foreach ( (array) $ranges as $range ) {
			if ( isset( BARRIO_MIGRATION_DAYS[ $day ] ) && preg_match( '/^(\d{2}:\d{2}) - (\d{2}:\d{2})$/', (string) $range, $match ) ) {
				$week[ BARRIO_MIGRATION_DAYS[ $day ] ][] = [
					'open'  => $match[1],
					'close' => $match[2],
				];
				++$slots;
			} else {
				$report['warnings'][] = "Opening hours: \"{$day}: {$range}\" is not a day and a time range, it is left out.";
			}
		}
	}
	$place  = trim( (string) ( $options['place_id'] ?? '' ) );
	$synced = DateTimeImmutable::createFromFormat( '!d/m/Y H:i', (string) ( $data['ultimo_aggiornamento'] ?? '' ), wp_timezone() );
	$hours  = [
		'source'     => '' === $place ? 'manual' : 'google',
		'place_id'   => $place,
		'week'       => $week,
		'exceptions' => [],
		'google'     => [
			'synced_at' => $synced ? $synced->getTimestamp() : null,
			'name'      => sanitize_text_field( (string) ( $data['display_name'] ?? '' ) ),
			'error'     => '',
		],
	];
	$valid  = rest_validate_value_from_schema(
		$hours,
		[
			'type'       => 'object',
			'properties' => Hours::schema(),
		]
	);
	if ( is_wp_error( $valid ) ) {
		return new WP_Error( 'barrio_migration_hours', 'The opening hours of Suite Barrio are not valid: ' . $valid->get_error_message() );
	}
	$report['counts'][] = [
		'data'     => 'time ranges',
		'found'    => $slots,
		'migrated' => $slots,
		'skipped'  => 0,
	];
	$report['notes'][]  = 'google' === $hours['source']
		? "Opening hours: from Google, Place ID {$place}. The week is the last one Suite Barrio took from Google; Barrio Suite takes the regular week and the special days every night, or now with Opening hours → Settings → Update now."
		: 'Opening hours: manual, because there is no Place ID.';
	if ( ! $dry_run ) {
		update_option( 'barrio_hours', $hours, true );
	}
	return null;
}
