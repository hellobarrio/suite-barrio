<?php

namespace BarrioSuite;

use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WP_REST_Request;

/**
 * Il periodo di popup e banner: starts_at ed ends_at in UTC (Y-m-d H:i:s), NULL senza inizio o senza fine. Nell'admin
 * le date sono nel fuso del sito, come le scrive il campo datetime-local (Y-m-dTH:i).
 */
final class Period {

	public const UTC = [
		'type'    => [ 'string', 'null' ],
		'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$',
	];

	/**
	 * Calcolato dal periodo, non salvato.
	 *
	 * @return 'off'|'scheduled'|'active'|'expired'
	 */
	public static function status( bool $enabled, ?string $starts_at, ?string $ends_at ): string {
		$now = gmdate( 'Y-m-d H:i:s' );
		return match ( true ) {
			! $enabled                               => 'off',
			null !== $ends_at && $ends_at <= $now    => 'expired',
			null !== $starts_at && $starts_at > $now => 'scheduled',
			default                                  => 'active',
		};
	}

	// Per i frontend headless: ISO 8601 in UTC, come 2026-12-01T09:00:00+00:00.
	public static function iso( ?string $utc ): ?string {
		return null === $utc ? null : gmdate( 'c', (int) strtotime( "{$utc} UTC" ) );
	}

	public static function local( ?string $utc ): ?string {
		return null === $utc ? null : get_date_from_gmt( $utc, 'Y-m-d\TH:i' );
	}

	/** @return array{starts_at: string|null, ends_at: string|null}|WP_Error Le date della richiesta in UTC. */
	public static function input( WP_REST_Request $request ): array|WP_Error {
		$period = [
			'starts_at' => null,
			'ends_at'   => null,
		];
		foreach ( array_keys( $period ) as $key ) {
			$value = $request[ $key ];
			if ( null === $value || '' === $value ) {
				continue;
			}
			$date = is_string( $value ) ? DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() ) : false;
			if ( ! $date || $date->format( 'Y-m-d\TH:i' ) !== $value ) {
				/* translators: %s: a date as written, like 2026-02-30. */
				return new WP_Error( 'rest_invalid_param', sprintf( __( '%s is not a date.', 'barrio-suite' ), is_scalar( $value ) ? (string) $value : '' ), [ 'status' => 400 ] );
			}
			$period[ $key ] = $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}
		return $period;
	}
}
