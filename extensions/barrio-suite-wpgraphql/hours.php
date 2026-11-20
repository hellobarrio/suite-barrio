<?php
// Gli orari con lo schema del prototipo: hbOrari, la settimana da lunedì con i giorni speciali dei prossimi 7 giorni,
// come quando la settimana del prototipo veniva da currentOpeningHours di Google. In più quello che il prototipo non
// aveva: la settimana normale, i giorni speciali con le date e le note, le ore da leggere (HH:MM), la fonte.

use BarrioSuite\Hours\Module;
use BarrioSuite\Hours\Render;
use BarrioSuite\I18n;

defined( 'ABSPATH' ) || exit;

function barrio_wpgraphql_hours_boot(): void {
	// Priorità 9, come per i form: anche il prototipo registra hbOrari con il suo modulo spento.
	add_action( 'graphql_register_types', 'barrio_wpgraphql_register_hours', 9 );
}

function barrio_wpgraphql_register_hours(): void {
	$hours = [
		'slots'  => [
			'type'        => [ 'list_of' => 'String' ],
			'description' => 'Like 09:00 - 13:00, or 9:00 AM - 1:00 PM in English. A closing not after the opening is on the next day: 00:00 - 00:00 is open all day. Empty when closed.',
		],
		'fasce'  => [
			'type'        => [ 'list_of' => 'HBOrariFascia' ],
			'description' => 'The same hours as slots, as HH:MM to compute with.',
		],
		'chiuso' => [ 'type' => 'Boolean' ],
	];
	register_graphql_object_type(
		'HBOrariFascia',
		[
			'description' => 'Opening hours, HH:MM in the site timezone (generalSettings.timezone). A closing not after the opening is on the next day: 00:00 - 00:00 is open all day.',
			'fields'      => [
				'apertura' => [ 'type' => 'String' ],
				'chiusura' => [ 'type' => 'String' ],
			],
		]
	);
	register_graphql_object_type(
		'HBOrariGiorno',
		[
			'description' => 'A day of the week.',
			'fields'      => [
				'giorno' => [
					'type'        => 'String',
					'description' => 'The name of the day in the requested language.',
				],
			] + $hours,
		]
	);
	register_graphql_object_type(
		'HBOrariGiornoSpeciale',
		[
			'description' => 'A special day, or a range of them, with its own hours.',
			'fields'      => [
				'dal'  => [
					'type'        => 'String',
					'description' => 'The first day, Y-m-d.',
				],
				'al'   => [
					'type'        => 'String',
					'description' => 'The last day, Y-m-d: the same as dal for one day.',
				],
				'nota' => [
					'type'        => 'String',
					'description' => 'Like Christmas, in the requested language or the main one; null without a note.',
				],
			] + $hours,
		]
	);
	register_graphql_object_type(
		'HBOrari',
		[
			'description' => 'The opening hours of the business.',
			'fields'      => [
				'ultimoAggiornamento' => [
					'type'        => 'String',
					'description' => 'The last update from Google, d/m/Y H:i in the site timezone. Null when the hours are written by hand.',
				],
				'displayName'         => [
					'type'        => 'String',
					'description' => 'The name of the place on Google.',
				],
				'fonte'               => [
					'type'        => 'String',
					'description' => 'manual, or google when the hours come from Google every night.',
				],
				'settimana'           => [
					'type'        => [ 'list_of' => 'HBOrariGiorno' ],
					'description' => 'The next 7 days from Monday: the week, with the hours of the special days among them. Empty without opening hours.',
				],
				'settimanaNormale'    => [
					'type'        => [ 'list_of' => 'HBOrariGiorno' ],
					'description' => 'The week from Monday, without the special days. Empty without opening hours.',
				],
				'giorniSpeciali'      => [
					'type'        => [ 'list_of' => 'HBOrariGiornoSpeciale' ],
					'description' => 'From today on, by date.',
				],
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbOrari',
		[
			'type'        => 'HBOrari',
			'description' => 'The opening hours, the same as the block on WordPress.',
			'args'        => [
				'lang' => [
					'type'        => 'String',
					'description' => 'A language code, like en: the names of the days, the notes, and 12-hour times for English. Without it, or if it is not active, the main language.',
				],
			],
			'resolve'     => static fn( mixed $root, array $args ): array => barrio_wpgraphql_hours( $args['lang'] ?? null ),
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
		'slots'  => array_map( static fn( array $slot ): string => $time( $slot['open'] ) . ' - ' . $time( $slot['close'] ), $slots ),
		'fasce'  => array_map(
			static fn( array $slot ): array => [
				'apertura' => $slot['open'],
				'chiusura' => $slot['close'],
			],
			$slots
		),
		'chiuso' => ! $slots,
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
			$name         = [ 'giorno' => ucfirst( $wp_locale->get_weekday( $day % 7 ) ) ];
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
		'ultimoAggiornamento' => 'google' === $settings['source'] && $synced ? (string) wp_date( 'd/m/Y H:i', $synced ) : null,
		'displayName'         => $settings['google']['name'] ?: null,
		'fonte'               => $settings['source'],
		'settimana'           => $next,
		'settimanaNormale'    => $week,
		'giorniSpeciali'      => array_map(
			static fn( array $special ): array => [
				'dal'  => $special['from'],
				'al'   => $special['to'],
				'nota' => Render::note( $special, $lang ) ?: null,
			] + $hours( $special['hours'] ),
			$settings['exceptions']
		),
	];
}
