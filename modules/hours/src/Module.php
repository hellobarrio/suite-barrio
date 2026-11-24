<?php

namespace BarrioSuite\Hours;

use BarrioSuite\Admin;
use BarrioSuite\Google;
use BarrioSuite\I18n;
use BarrioSuite\Roles;
use DateTimeImmutable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Orari (PLAN-MODULI.md §4): la settimana e i giorni speciali, scritti a mano o presi ogni notte da Google Places con
 * il service account del core. Nel sito il blocco barrio/hours e lo shortcode [barrio_hours] (Render) e, con il modulo
 * SEO, openingHoursSpecification dell'organizzazione. Un solo record, l'opzione barrio_hours, validata con schema()
 * prima di ogni scrittura.
 *
 * Una fascia con la chiusura non dopo l'apertura finisce il giorno dopo: 20:00–02:00 chiude dopo mezzanotte,
 * 00:00–00:00 è aperto tutto il giorno.
 *
 * @phpstan-type Slot array{open: string, close: string}
 * @phpstan-type Special array{from: string, to: string, hours: list<Slot>, note: array<string, string>}
 * @phpstan-type Settings array{source: 'manual'|'google', place_id: string, week: array<int, list<Slot>>, exceptions: list<Special>, google: array{synced_at: int|null, name: string, error: string}}
 */
final class Module {

	// Chi modifica gli orari (Permessi). Dalla migrazione 0001. Fonte e Place ID solo con manage_options.
	public const CAP = 'barrio_manage_hours';

	// In autoload: la legge ogni pagina con il blocco, e init per WP-Cron.
	private const OPTION = 'barrio_hours';

	// La sincronizzazione con Google, un evento singolo alle 3 di notte del sito, solo con la fonte Google.
	private const CRON = 'barrio_hours_sync';

	private const SCOPE  = 'https://www.googleapis.com/auth/cloud-platform';
	private const PLACES = 'https://places.googleapis.com/v1/places/';

	private const TIME = '^([01][0-9]|2[0-3]):[0-5][0-9]$';

	// Giorni ISO: 1 = lunedì.
	private const DEFAULTS = [
		'source'     => 'manual',
		'place_id'   => '',
		'week'       => [
			1 => [],
			2 => [],
			3 => [],
			4 => [],
			5 => [],
			6 => [],
			7 => [],
		],
		'exceptions' => [],
		'google'     => [
			'synced_at' => null,
			'name'      => '',
			'error'     => '',
		],
	];

	// I giorni di schema.org, per giorno ISO.
	private const DAYS = [
		1 => 'Monday',
		'Tuesday',
		'Wednesday',
		'Thursday',
		'Friday',
		'Saturday',
		'Sunday',
	];

	// Aperto tutto il giorno.
	public const ALL_DAY = [
		'open'  => '00:00',
		'close' => '00:00',
	];

	public static function boot(): void {
		add_action( 'init', [ Render::class, 'register' ] );
		add_action( 'init', [ self::class, 'schedule' ] );
		add_action(
			self::CRON,
			static function (): void {
				self::sync();
			}
		);
		add_action( 'rest_api_init', [ self::class, 'register' ] );
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_filter( 'barrio_admin_data', [ self::class, 'admin_data' ] );
		// Le estensioni del modulo (WPGraphQL) si collegano qui, come a barrio_forms_loaded.
		add_action( 'init', static fn() => do_action( 'barrio_hours_loaded' ), 5 );
	}

	public static function deactivate(): void {
		wp_unschedule_hook( self::CRON );
	}

	// L'opzione la elimina il core (src/Uninstall.php).
	public static function uninstall(): void {
		Roles::remove( [ self::CAP ] );
	}

	public static function can(): bool {
		return current_user_can( self::CAP );
	}

	public static function menu(): void {
		$hook = Admin::add_page( 'barrio-hours', __( 'Opening hours', 'barrio-suite' ), self::CAP );
		if ( $hook ) {
			// L'anteprima nella colonna destra, con gli stili del sito.
			add_action( "load-{$hook}", static fn() => wp_enqueue_style( 'barrio-hours' ) );
		}
	}

	/**
	 * In window.barrioAdmin.hours, per la card della Bacheca, a chi modifica gli orari: oggi, il prossimo giorno
	 * speciale (con la data breve, perché stia in una riga), la fonte e l'errore di Google. Senza, il JavaScript sa che
	 * il modulo è spento.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function admin_data( array $data ): array {
		if ( ! self::can() ) {
			return $data;
		}
		$settings = self::settings();
		$next     = $settings['exceptions'][0] ?? null;
		return $data + [
			'hours' => [
				'today'  => Render::day_text( $settings, current_datetime()->setTime( 0, 0 ) ),
				'next'   => $next ? Render::dates( $next ) : '',
				'source' => $settings['source'],
				'error'  => 'google' === $settings['source'] ? $settings['google']['error'] : '',
			],
		];
	}

	public static function register(): void {
		$admin  = static fn(): bool => current_user_can( 'manage_options' );
		$schema = self::schema();
		$args   = $schema + [ 'roles' => Roles::schema( [ self::CAP ] ) ];

		$args['week']['validate_callback']       = [ self::class, 'validate' ];
		$args['exceptions']['validate_callback'] = [ self::class, 'validate' ];
		register_rest_route(
			'barrio/v1',
			'/hours',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get' ],
					'permission_callback' => [ self::class, 'can' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'put' ],
					'permission_callback' => [ self::class, 'can' ],
					'args'                => $args,
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/hours/sync',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'sync_route' ],
				'permission_callback' => $admin,
			]
		);
		register_rest_route(
			'barrio/v1',
			'/hours/preview',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'preview' ],
				'permission_callback' => [ self::class, 'can' ],
				'args'                => [
					'week'       => $args['week'] + [ 'required' => true ],
					'exceptions' => $args['exceptions'] + [ 'required' => true ],
					'lang'       => [
						'type'    => 'string',
						'default' => '',
					],
				],
			]
		);
	}

	/**
	 * Lo schema JSON di ciò che si scrive: fa i controlli che in una tabella farebbero i CHECK (ore valide, giorni
	 * 1–7, date).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		$time  = [
			'type'    => 'string',
			'pattern' => self::TIME,
		];
		$date  = [
			'type'    => 'string',
			'pattern' => '^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$',
		];
		$slots = [
			'type'     => 'array',
			'maxItems' => 6,
			'items'    => [
				'type'                 => 'object',
				'properties'           => [
					'open'  => $time,
					'close' => $time,
				],
				'required'             => [ 'open', 'close' ],
				'additionalProperties' => false,
			],
		];
		return [
			'source'     => [
				'type' => 'string',
				'enum' => [ 'manual', 'google' ],
			],
			// Va nell'URL di Google, codificato: solo i caratteri dei Place ID.
			'place_id'   => [
				'type'    => 'string',
				'pattern' => '^[A-Za-z0-9_-]{0,512}$',
			],
			'week'       => [
				'type'                 => 'object',
				'properties'           => array_fill_keys( array_keys( self::DAYS ), $slots ),
				'required'             => array_map( 'strval', array_keys( self::DAYS ) ),
				'additionalProperties' => false,
			],
			'exceptions' => [
				'type'     => 'array',
				'maxItems' => 200,
				'items'    => [
					'type'                 => 'object',
					'properties'           => [
						'from'  => $date,
						'to'    => $date,
						// Nessuna fascia: chiuso.
						'hours' => $slots,
						// Per lingua; una lingua senza nota mostra quella della lingua principale.
						'note'  => [
							'type'                 => 'object',
							'additionalProperties' => [
								'type'      => 'string',
								'maxLength' => 100,
							],
						],
					],
					'required'             => [ 'from', 'to', 'hours', 'note' ],
					'additionalProperties' => false,
				],
			],
		];
	}

	/**
	 * La settimana e i giorni speciali di una richiesta. Le ore prima dello schema, che per un campo lasciato vuoto
	 * risponderebbe con la sua espressione regolare; dopo, le date che lo schema non controlla: esistono, e un
	 * intervallo non finisce prima di iniziare.
	 */
	public static function validate( mixed $value, WP_REST_Request $request, string $param ): true|WP_Error {
		$times = [];
		if ( is_array( $value ) ) {
			array_walk_recursive(
				$value,
				static function ( mixed $time, int|string $key ) use ( &$times ): void {
					if ( 'open' === $key || 'close' === $key ) {
						$times[] = $time;
					}
				}
			);
		}
		foreach ( $times as $time ) {
			if ( ! is_string( $time ) || ! preg_match( '/' . self::TIME . '/', $time ) ) {
				return new WP_Error( 'rest_invalid_param', __( 'Write every opening and closing time, like 09:00.', 'barrio-suite' ), [ 'status' => 400 ] );
			}
		}
		$valid = rest_validate_request_arg( $value, $request, $param );
		return is_wp_error( $valid ) || 'exceptions' !== $param ? $valid : self::check_dates( (array) $value );
	}

	/**
	 * Gli orari salvati, senza i giorni speciali già passati: si tolgono da soli, e dal database alla scrittura dopo.
	 *
	 * @return Settings
	 */
	public static function settings(): array {
		/** @var Settings $settings */
		$settings               = array_intersect_key( (array) get_option( self::OPTION, [] ), self::DEFAULTS ) + self::DEFAULTS;
		$today                  = current_datetime()->format( 'Y-m-d' );
		$settings['exceptions'] = array_values( array_filter( $settings['exceptions'], static fn( array $special ): bool => $special['to'] >= $today ) );
		return $settings;
	}

	public static function get(): WP_REST_Response {
		$settings = self::settings();
		$synced   = $settings['google']['synced_at'];
		$data     = [
			...$settings,
			// Un oggetto anche vuoto: [] in JSON sarebbe una lista.
			'exceptions' => array_map( static fn( array $special ): array => array_replace( $special, [ 'note' => (object) $special['note'] ] ), $settings['exceptions'] ),
			'google'     => $settings['google'] + [ 'synced' => $synced ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $synced ) : '' ],
		];
		if ( current_user_can( 'manage_options' ) ) {
			$data += Roles::get( [ self::CAP ] ) + [ 'account' => null !== Google::account() ];
		}
		return new WP_REST_Response( $data );
	}

	/**
	 * Un valore assente resta com'è. Con la fonte Google settimana e giorni speciali sono quelli di Google: si
	 * modificano passando a manuale, che parte dagli ultimi dati. Scegliere Google, o un altro Place ID, li prende
	 * subito: un errore resta nella pagina.
	 */
	public static function put( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! current_user_can( 'manage_options' ) && ( $request->has_param( 'source' ) || $request->has_param( 'place_id' ) || $request->has_param( 'roles' ) ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Only administrators can change the source and the permissions.', 'barrio-suite' ), [ 'status' => 403 ] );
		}
		$params = $request->get_params();
		$before = self::settings();
		$keys   = 'manual' === ( $params['source'] ?? $before['source'] ) ? [ 'source', 'place_id', 'week', 'exceptions' ] : [ 'source', 'place_id' ];
		/** @var Settings $settings Già validati dagli args della rotta. */
		$settings = array_replace( $before, array_intersect_key( $params, array_flip( $keys ) ) );
		if ( 'google' === $settings['source'] && '' === $settings['place_id'] ) {
			return new WP_Error( 'barrio_hours_place', __( 'Enter the Place ID of the business to take the opening hours from Google.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		if ( 'manual' === $settings['source'] ) {
			wp_unschedule_hook( self::CRON );
		}
		self::write( $settings );
		if ( 'google' === $settings['source'] && ( 'google' !== $before['source'] || $settings['place_id'] !== $before['place_id'] ) ) {
			self::sync();
		}
		if ( is_array( $request['roles'] ) ) {
			Roles::save( [ self::CAP ], $request['roles'] );
		}
		return self::get();
	}

	// "Aggiorna ora": i dati nuovi, o l'errore di Google, che resta anche nella pagina.
	public static function sync_route(): WP_REST_Response|WP_Error {
		$synced = self::sync();
		return is_wp_error( $synced ) ? $synced : self::get();
	}

	// Come appare nel sito la settimana non ancora salvata, con i giorni speciali, nella lingua scelta.
	public static function preview( WP_REST_Request $request ): WP_REST_Response {
		/** @var Settings $settings */
		$settings = array_replace(
			self::settings(),
			[
				'week'       => array_replace( self::DEFAULTS['week'], (array) $request['week'] ),
				'exceptions' => self::sort( (array) $request['exceptions'] ),
			]
		);
		$html     = I18n::in( I18n::resolve( $request['lang'] ), static fn(): string => Render::html( $settings, 'week' ) );
		return new WP_REST_Response( [ 'html' => $html ] );
	}

	// Ogni notte alle 3 del fuso del sito: un evento alla volta, così con l'ora legale resta alle 3.
	public static function schedule(): void {
		if ( 'google' === self::settings()['source'] && ! wp_next_scheduled( self::CRON ) ) {
			$next = new DateTimeImmutable( 'today 03:00', wp_timezone() );
			wp_schedule_single_event( ( $next->getTimestamp() > time() ? $next : $next->modify( '+1 day' ) )->getTimestamp(), self::CRON );
		}
	}

	/**
	 * Settimana e giorni speciali da Google Places. Con un errore restano i dati di prima, e l'errore si vede nella
	 * pagina e nella Bacheca.
	 */
	public static function sync(): true|WP_Error {
		$settings = self::settings();
		if ( 'google' !== $settings['source'] ) {
			return new WP_Error( 'barrio_hours_source', __( 'Choose Google as the source first.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		$google = self::fetch( $settings['place_id'] );
		if ( is_wp_error( $google ) ) {
			$settings['google']['error'] = $google->get_error_message();
		} else {
			$settings = array_replace( $settings, $google );
		}
		self::write( $settings );
		return is_wp_error( $google ) ? $google : true;
	}

	/**
	 * La settimana da regularOpeningHours.periods di Places (day 0 = domenica). Un periodo senza close è un luogo
	 * sempre aperto; uno più lungo di un giorno si divide a mezzanotte, perché una fascia arriva al più al giorno dopo.
	 *
	 * @param array<mixed> $periods
	 * @return array<int, list<Slot>>
	 */
	public static function google_week( array $periods ): array {
		$week = self::DEFAULTS['week'];
		foreach ( $periods as $period ) {
			if ( ! isset( $period['close'] ) ) {
				return array_fill_keys( array_keys( self::DAYS ), [ self::ALL_DAY ] );
			}
			$start = self::minutes( (array) $period['open'] );
			$end   = self::minutes( (array) $period['close'] );
			if ( $end <= $start ) {
				$end += 7 * 1440;
			}
			while ( $end - $start > 1440 ) {
				$week[ self::iso( $start ) ][] = [
					'open'  => self::time( $start ),
					'close' => '00:00',
				];
				$start                         = ( intdiv( $start, 1440 ) + 1 ) * 1440;
			}
			$week[ self::iso( $start ) ][] = [
				'open'  => self::time( $start ),
				'close' => self::time( $end ),
			];
		}
		return array_map( [ self::class, 'sort_slots' ], $week );
	}

	/**
	 * I giorni speciali da currentOpeningHours (i prossimi 7 giorni): per ogni data di specialDays le fasce di quel
	 * giorno, nessuna se è chiuso. Come nella settimana, una fascia più lunga di un giorno si divide a mezzanotte, e il
	 * giorno speciale ne prende il suo pezzo: un luogo sempre aperto è un solo periodo lungo i 7 giorni.
	 *
	 * @param array<mixed> $current
	 * @return list<Special>
	 */
	public static function google_exceptions( array $current ): array {
		$exceptions = [];
		foreach ( (array) ( $current['specialDays'] ?? [] ) as $special ) {
			$date = self::date( (array) ( $special['date'] ?? [] ) );
			if ( null === $date ) {
				continue;
			}
			// Minuti dalla mezzanotte del giorno speciale, negativi nei giorni prima.
			$at    = static fn( array $point ): int => (int) round( ( strtotime( self::date( (array) ( $point['date'] ?? [] ) ) ?? $date ) - strtotime( $date ) ) / DAY_IN_SECONDS ) * 1440 + self::minutes( array_diff_key( $point, [ 'day' => 0 ] ) );
			$hours = [];
			foreach ( (array) ( $current['periods'] ?? [] ) as $period ) {
				$open  = (array) ( $period['open'] ?? [] );
				$close = (array) ( $period['close'] ?? [] );
				$from  = self::date( (array) ( $open['date'] ?? [] ) );
				if ( null === $from || $from > $date ) {
					continue;
				}
				$start = $at( $open );
				// Senza close, o con close tagliato alla fine dei 7 giorni (truncated), resta aperto.
				$end = $close && empty( $close['truncated'] ) ? $at( $close ) : 2 * 1440;
				if ( $start < 0 ) {
					// Aperto da prima: una notte resta del giorno in cui apre, come in google_week().
					if ( $end - max( $start, -1440 ) <= 1440 ) {
						continue;
					}
					$start = 0;
				}
				$hours[] = [
					'open'  => self::time( $start ),
					'close' => $end - $start > 1440 ? '00:00' : self::time( $end ),
				];
			}
			$exceptions[] = [
				'from'  => $date,
				'to'    => $date,
				'hours' => self::sort_slots( $hours ),
				'note'  => [],
			];
		}
		return $exceptions;
	}

	/**
	 * openingHoursSpecification dell'organizzazione (Seo\Schema): una voce per fascia con i giorni che la hanno, e i
	 * giorni speciali con validFrom e validThrough. Come vuole Google: aperto tutto il giorno è 00:00–23:59, chiuso
	 * 00:00–00:00.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function specification(): array {
		$settings = self::settings();
		$spec     = static fn( array $slot ): array => [
			'opens'  => $slot['open'],
			'closes' => self::ALL_DAY === $slot ? '23:59' : $slot['close'],
		];
		$week     = [];
		foreach ( $settings['week'] as $day => $slots ) {
			foreach ( $slots as $slot ) {
				$key                         = implode( '-', $slot );
				$week[ $key ]              ??= [
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => [],
				] + $spec( $slot );
				$week[ $key ]['dayOfWeek'][] = self::DAYS[ $day ];
			}
		}
		$list = array_values( $week );
		foreach ( $settings['exceptions'] as $special ) {
			$valid = [
				'@type'        => 'OpeningHoursSpecification',
				'validFrom'    => $special['from'],
				'validThrough' => $special['to'],
			];
			if ( ! $special['hours'] ) {
				$list[] = $valid + [
					'opens'  => '00:00',
					'closes' => '00:00',
				];
			}
			foreach ( $special['hours'] as $slot ) {
				$list[] = $valid + $spec( $slot );
			}
		}
		return $list;
	}

	/**
	 * Il luogo da Places (New), con il token del service account: Places lo accetta con il progetto, che paga la
	 * richiesta, in X-Goog-User-Project. I campi degli orari sono dello SKU Place Details Enterprise.
	 *
	 * @return array{week: array<int, list<Slot>>, exceptions: list<Special>, google: array{synced_at: int, name: string, error: string}}|WP_Error
	 */
	private static function fetch( string $place_id ): array|WP_Error {
		$token = Google::token( self::SCOPE );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = wp_remote_get(
			self::PLACES . rawurlencode( $place_id ) . '?languageCode=' . rawurlencode( I18n::languages()[0] ),
			[
				'timeout' => 15,
				'headers' => [
					'Authorization'       => "Bearer {$token}",
					'X-Goog-User-Project' => Google::account()['project_id'] ?? '',
					'X-Goog-FieldMask'    => 'displayName,regularOpeningHours.periods,currentOpeningHours.periods,currentOpeningHours.specialDays',
				],
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'barrio_hours_http', $response->get_error_message(), [ 'status' => 502 ] );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			$reason = is_array( $body ) ? (string) ( $body['error']['message'] ?? '' ) : '';
			/* translators: %s: reason given by Google. */
			return new WP_Error( 'barrio_hours_google', sprintf( __( 'Google did not return the opening hours. %s', 'barrio-suite' ), $reason ?: "HTTP {$code}" ), [ 'status' => 502 ] );
		}
		// Un elenco vuoto è un luogo che non apre mai; nessun elenco, un luogo senza orari su Google.
		if ( ! is_array( $body['regularOpeningHours']['periods'] ?? null ) ) {
			return new WP_Error( 'barrio_hours_none', __( 'This place has no opening hours on Google: add them to its Google Business Profile, or write them here with the manual source.', 'barrio-suite' ), [ 'status' => 502 ] );
		}
		$data  = [
			'week'       => self::google_week( $body['regularOpeningHours']['periods'] ),
			'exceptions' => self::google_exceptions( (array) ( $body['currentOpeningHours'] ?? [] ) ),
		];
		$valid = rest_validate_value_from_schema(
			$data,
			[
				'type'       => 'object',
				'properties' => array_intersect_key( self::schema(), $data ),
			]
		);
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'barrio_hours_answer', __( 'The answer of Google is not valid. Try again later.', 'barrio-suite' ), [ 'status' => 502 ] );
		}
		return $data + [
			'google' => [
				'synced_at' => time(),
				'name'      => sanitize_text_field( (string) ( $body['displayName']['text'] ?? '' ) ),
				'error'     => '',
			],
		];
	}

	/**
	 * Le fasce di ogni giorno e i giorni speciali in ordine; barrio_hours_changed se qualcosa è cambiato.
	 *
	 * @param Settings $settings
	 */
	private static function write( array $settings ): void {
		$settings['week']       = array_map( [ self::class, 'sort_slots' ], array_replace( self::DEFAULTS['week'], $settings['week'] ) );
		$settings['exceptions'] = self::sort( $settings['exceptions'] );
		if ( update_option( self::OPTION, $settings, true ) ) {
			do_action( 'barrio_hours_changed' );
		}
	}

	/**
	 * @param array<mixed> $exceptions Già validate con schema().
	 * @return list<Special>
	 */
	private static function sort( array $exceptions ): array {
		/** @var list<Special> $exceptions */
		$exceptions = array_values( $exceptions );
		usort( $exceptions, static fn( array $a, array $b ): int => [ $a['from'], $a['to'] ] <=> [ $b['from'], $b['to'] ] );
		return array_map( static fn( array $special ): array => array_replace( $special, [ 'hours' => self::sort_slots( $special['hours'] ) ] ), $exceptions );
	}

	/**
	 * @param list<Slot> $slots
	 * @return list<Slot>
	 */
	private static function sort_slots( array $slots ): array {
		usort( $slots, static fn( array $a, array $b ): int => strcmp( $a['open'], $b['open'] ) );
		return $slots;
	}

	/** @param array<mixed> $exceptions Già validate con schema(). */
	private static function check_dates( array $exceptions ): true|WP_Error {
		foreach ( $exceptions as $special ) {
			foreach ( [ $special['from'], $special['to'] ] as $date ) {
				if ( ! checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) ) ) {
					/* translators: %s: a date as written, like 2026-02-30. */
					return new WP_Error( 'rest_invalid_param', sprintf( __( '%s is not a date.', 'barrio-suite' ), $date ), [ 'status' => 400 ] );
				}
			}
			if ( $special['to'] < $special['from'] ) {
				return new WP_Error( 'rest_invalid_param', __( 'A special period cannot end before it starts.', 'barrio-suite' ), [ 'status' => 400 ] );
			}
		}
		return true;
	}

	/** @param array<mixed> $point Un punto di Places: day (0 = domenica), hour, minute. */
	private static function minutes( array $point ): int {
		return (int) ( $point['day'] ?? 0 ) * 1440 + (int) ( $point['hour'] ?? 0 ) * 60 + (int) ( $point['minute'] ?? 0 );
	}

	// Il giorno ISO di un minuto della settimana di Places, che parte da domenica.
	private static function iso( int $minutes ): int {
		return intdiv( $minutes, 1440 ) % 7 ?: 7;
	}

	private static function time( int $minutes ): string {
		return sprintf( '%02d:%02d', intdiv( $minutes % 1440, 60 ), $minutes % 60 );
	}

	/** @param array<mixed> $date Una data di Places: year, month, day. */
	private static function date( array $date ): ?string {
		[ $year, $month, $day ] = [ (int) ( $date['year'] ?? 0 ), (int) ( $date['month'] ?? 0 ), (int) ( $date['day'] ?? 0 ) ];
		return checkdate( $month, $day, $year ) ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) : null;
	}
}
