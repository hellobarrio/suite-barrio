<?php
// Gli orari in GraphQL: hbOpeningHours, con i nomi di Google Places e di schema.org. currentWeek è la settimana da
// lunedì con i giorni speciali dei prossimi 7 giorni, come quando la settimana del prototipo veniva da
// currentOpeningHours di Google; regularWeek è quella normale.

use BarrioSuite\Hours\Module;
use BarrioSuite\Hours\Render;
use BarrioSuite\I18n;

defined( 'ABSPATH' ) || exit;

function barrio_wpgraphql_hours_boot(): void {
	// Priorità 9, come negli altri file.
	add_action( 'graphql_register_types', 'barrio_wpgraphql_register_hours', 9 );
}

function barrio_wpgraphql_register_hours(): void {
	$hours = [
		'slots'    => barrio_wpgraphql_list( 'String', 'Like 09:00 - 13:00, or 9:00 AM - 1:00 PM in English. A closing not after the opening is on the next day: 00:00 - 00:00 is open all day. Empty when closed.' ),
		'periods'  => barrio_wpgraphql_list( 'HbOpeningHoursPeriod', 'The same hours as slots, as HH:MM to compute with.' ),
		'isClosed' => barrio_wpgraphql_required( 'Boolean' ),
	];
	register_graphql_enum_type(
		'HbOpeningHoursSourceEnum',
		[
			'description' => 'Where the opening hours come from: written by hand, or from Google every night.',
			'values'      => [
				'MANUAL' => [ 'value' => 'manual' ],
				'GOOGLE' => [ 'value' => 'google' ],
			],
		]
	);
	register_graphql_object_type(
		'HbOpeningHoursPeriod',
		[
			'description' => 'Opening hours, HH:MM in the site timezone (generalSettings.timezone). A closing not after the opening is on the next day: 00:00 - 00:00 is open all day.',
			'fields'      => [
				'opens'  => barrio_wpgraphql_required( 'String' ),
				'closes' => barrio_wpgraphql_required( 'String' ),
			],
		]
	);
	register_graphql_object_type(
		'HbOpeningHoursDay',
		[
			'description' => 'A day of the week.',
			'fields'      => [
				'day' => barrio_wpgraphql_required( 'String', 'The name of the day in the requested language.' ),
			] + $hours,
		]
	);
	register_graphql_object_type(
		'HbOpeningHoursSpecialDay',
		[
			'description' => 'A special day, or a range of them, with its own hours.',
			'fields'      => [
				'startDate' => barrio_wpgraphql_required( 'String', 'The first day, Y-m-d.' ),
				'endDate'   => barrio_wpgraphql_required( 'String', 'The last day, Y-m-d: the same as startDate for one day.' ),
				'note'      => [
					'type'        => 'String',
					'description' => 'Like Christmas, in the requested language or the main one; null without a note.',
				],
			] + $hours,
		]
	);
	register_graphql_object_type(
		'HbOpeningHours',
		[
			'description' => 'The opening hours of the business.',
			'fields'      => [
				'source'      => barrio_wpgraphql_required( 'HbOpeningHoursSourceEnum' ),
				'displayName' => [
					'type'        => 'String',
					'description' => 'The name of the place on Google.',
				],
				'syncedAt'    => [
					'type'        => 'String',
					'description' => 'The last update from Google, ISO 8601 in UTC. Null when the hours are written by hand.',
				],
				'currentWeek' => barrio_wpgraphql_list( 'HbOpeningHoursDay', 'The next 7 days from Monday: the week, with the hours of the special days among them. Empty without opening hours.' ),
				'regularWeek' => barrio_wpgraphql_list( 'HbOpeningHoursDay', 'The week from Monday, without the special days. Empty without opening hours.' ),
				'specialDays' => barrio_wpgraphql_list( 'HbOpeningHoursSpecialDay', 'From today on, by date.' ),
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbOpeningHours',
		[
			'type'        => 'HbOpeningHours',
			'description' => 'The opening hours, the same as the block on WordPress.',
			'args'        => [
				'language' => [
					'type'        => 'String',
					'description' => 'A language code, like en: the names of the days, the notes, and 12-hour times for English. Without it, or if it is not active, the main language.',
				],
			],
			'resolve'     => static fn( mixed $root, array $args ): array => barrio_wpgraphql_hours( $args['language'] ?? null ),
		]
	);
}

/** @return array<string, mixed> */
function barrio_wpgraphql_hours( mixed $lang ): array {
	$settings = Module::settings();
	$lang     = I18n::resolve( $lang );
	$twelve   = str_starts_with( $lang, 'en' );
	$time     = static fn( string $hm ): string => $twelve ? gmdate( 'g:i A', (int) strtotime( "2000-01-03 {$hm} UTC" ) ) : $hm;
	$hours    = static fn( array $slots ): array => [
		'slots'    => array_map( static fn( array $slot ): string => $time( $slot['open'] ) . ' - ' . $time( $slot['close'] ), $slots ),
		'periods'  => array_map(
			static fn( array $slot ): array => [
				'opens'  => $slot['open'],
				'closes' => $slot['close'],
			],
			$slots
		),
		'isClosed' => ! $slots,
	];
	// Le due settimane da lunedì: i prossimi 7 giorni, con i giorni speciali, e quella normale.
	$weeks  = static function () use ( $settings, $hours ): array {
		global $wp_locale;
		$today = current_datetime()->setTime( 0, 0 );
		$next  = [];
		$week  = [];
		for ( $i = 0; $i < 7; $i++ ) {
			$date = $today->modify( "+{$i} days" );
			$day  = (int) $date->format( 'N' );
			// I nomi di WP_Locale sono minuscoli in molte lingue (lunedì): il prototipo li aveva con la maiuscola.
			$name         = [ 'day' => ucfirst( $wp_locale->get_weekday( $day % 7 ) ) ];
			$next[ $day ] = $name + $hours( Render::slots( $settings, $date )[0] );
			$week[ $day ] = $name + $hours( $settings['week'][ $day ] );
		}
		ksort( $next );
		ksort( $week );
		return [ array_values( $next ), array_values( $week ) ];
	};
	$synced = $settings['google']['synced_at'];
	// Senza orari niente, come il blocco: non chiuso tutti i giorni.
	[ $next, $week ] = array_filter( $settings['week'] ) || $settings['exceptions'] ? I18n::in( $lang, $weeks ) : [ [], [] ];
	return [
		'source'      => $settings['source'],
		'displayName' => $settings['google']['name'] ?: null,
		'syncedAt'    => 'google' === $settings['source'] && $synced ? gmdate( 'c', $synced ) : null,
		'currentWeek' => $next,
		'regularWeek' => $week,
		'specialDays' => array_map(
			static fn( array $special ): array => [
				'startDate' => $special['from'],
				'endDate'   => $special['to'],
				'note'      => Render::note( $special, $lang ) ?: null,
			] + $hours( $special['hours'] ),
			$settings['exceptions']
		),
	];
}
