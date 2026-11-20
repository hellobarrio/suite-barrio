<?php

namespace BarrioSuite\Analytics;

use BarrioSuite\Admin;
use BarrioSuite\Google;
use BarrioSuite\Roles;
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Statistiche (PLAN-MODULI.md §9): i report di Google Analytics 4 della proprietà scelta, letti con il service account
 * del core (src/Google.php), che è Visualizzatore della proprietà. In Barrio → Statistiche, nella card della Bacheca e
 * in un widget della Bacheca di WordPress. Il plugin non tiene una copia dei dati: la risposta resta un'ora in un
 * transient, e Google si chiama solo quando qualcuno guarda.
 *
 * @phpstan-type Range array{startDate: string, endDate: string, name: string}
 * @phpstan-type Period array{ranges: array{0: Range, 1: Range}, start: string, end: string, days: int}
 * @phpstan-type Row array{0: array<string, string>, 1: list<float>}
 */
final class Module {

	// Chi legge le statistiche (Permessi). Dalla migrazione 0001.
	public const CAP = 'barrio_view_analytics';

	// Non in autoload: si legge solo nell'admin.
	private const OPTION = 'barrio_analytics';

	private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
	private const DATA  = 'https://analyticsdata.googleapis.com/v1beta/';
	private const ADMIN = 'https://analyticsadmin.googleapis.com/v1beta/';

	// Il primo giorno di Personalizzato: prima di GA4 (App + Web, 2019) nessuna proprietà ha dati.
	private const FIRST_DAY = '2019-01-01';

	// Il nome di una proprietà GA4 nelle API: finisce nell'URL.
	private const PROPERTY = '^properties/[0-9]{1,20}$';

	// I numeri in alto e le metriche di GA, nell'ordine della richiesta.
	private const METRICS = [
		'users'    => 'activeUsers',
		'views'    => 'screenPageViews',
		'sessions' => 'sessions',
		'duration' => 'averageSessionDuration',
		'bounce'   => 'bounceRate',
	];

	// Gli elenchi dei primi 10: le dimensioni e la metrica con cui si ordinano.
	private const LISTS = [
		'pages'     => [ [ 'hostName', 'pagePath' ], 'screenPageViews' ],
		'channels'  => [ [ 'sessionDefaultChannelGroup' ], 'sessions' ],
		'devices'   => [ [ 'deviceCategory' ], 'activeUsers' ],
		'countries' => [ [ 'countryId' ], 'activeUsers' ],
	];

	// Le dimensioni dell'andamento, con il nome per il JavaScript e il passo tra due punti.
	private const UNITS = [
		'date'           => [ 'day', '+1 day' ],
		'isoYearIsoWeek' => [ 'week', '+1 week' ],
		'yearMonth'      => [ 'month', '+1 month' ],
	];

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_filter( 'barrio_admin_data', [ self::class, 'admin_data' ] );
		add_action( 'wp_dashboard_setup', [ self::class, 'dashboard' ] );
		add_action( 'load-index.php', [ self::class, 'replace_dashboard' ] );
	}

	public static function deactivate(): void {}

	// L'opzione la elimina il core (src/Uninstall.php); i transient scadono da soli.
	public static function uninstall(): void {
		Roles::remove( [ self::CAP ] );
	}

	public static function can(): bool {
		return current_user_can( self::CAP );
	}

	public static function menu(): void {
		Admin::add_page( 'barrio-analytics', __( 'Analytics', 'barrio-suite' ), self::CAP );
	}

	/**
	 * In window.barrioAdmin.analytics, per la pagina e la card della Bacheca, a chi legge le statistiche: cosa manca e
	 * ieri e first, l'ultimo e il primo giorno che Personalizzato accetta. Senza, il JavaScript sa che il modulo è spento.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function admin_data( array $data ): array {
		return self::can() ? $data + [
			'analytics' => [
				'missing'   => self::missing(),
				'yesterday' => self::yesterday(),
				'first'     => self::FIRST_DAY,
			],
		] : $data;
	}

	// Cosa manca per leggere i report: il service account (account), la proprietà (property) o niente ('').
	public static function missing(): string {
		if ( null === Google::account() ) {
			return 'account';
		}
		return '' === self::settings()['property'] ? 'property' : '';
	}

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		$date  = [
			'type'    => 'string',
			'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$',
		];
		register_rest_route(
			'barrio/v1',
			'/analytics/report',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'report' ],
				'permission_callback' => [ self::class, 'can' ],
				'args'                => [
					// Giorni che finiscono ieri; 365 sono i 12 mesi.
					'period' => [
						'type'    => 'string',
						'enum'    => [ '7', '28', '90', '365', 'custom' ],
						'default' => '28',
					],
					// Solo con custom, nel fuso del sito.
					'from'   => $date,
					'to'     => $date,
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/analytics',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get' ],
					'permission_callback' => $admin,
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'put' ],
					'permission_callback' => $admin,
					'args'                => self::schema() + [ 'roles' => Roles::schema( [ self::CAP ] ) ],
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/analytics/properties',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'properties' ],
				'permission_callback' => $admin,
			]
		);
	}

	/**
	 * Lo schema JSON dell'opzione: la proprietà, vuota finché non si sceglie, e se le statistiche sostituiscono la
	 * Bacheca di WordPress.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		return [
			'property'          => [
				'type'    => 'string',
				'pattern' => '(' . self::PROPERTY . ')|^$',
			],
			'replace_dashboard' => [ 'type' => 'boolean' ],
		];
	}

	/** @return array{property: string, replace_dashboard: bool} */
	public static function settings(): array {
		$saved = (array) get_option( self::OPTION, [] );
		return [
			'property'          => (string) ( $saved['property'] ?? '' ),
			'replace_dashboard' => (bool) ( $saved['replace_dashboard'] ?? false ),
		];
	}

	// Con l'email del service account, da aggiungere come Visualizzatore; vuota senza service account.
	public static function get(): WP_REST_Response {
		return new WP_REST_Response( self::settings() + [ 'email' => Google::account()['client_email'] ?? '' ] + Roles::get( [ self::CAP ] ) );
	}

	// Un valore assente resta com'è. Un'altra proprietà ha altre chiavi di cache: i dati di prima non si mostrano.
	public static function put( WP_REST_Request $request ): WP_REST_Response {
		update_option( self::OPTION, array_intersect_key( $request->get_params(), self::settings() ) + self::settings(), false );
		if ( is_array( $request['roles'] ) ) {
			Roles::save( [ self::CAP ], $request['roles'] );
		}
		return self::get();
	}

	// Le proprietà GA4 che il service account vede, come { value, label }. Vuoto: non è Visualizzatore di nessuna.
	public static function properties(): WP_REST_Response|WP_Error {
		// ponytail: una pagina di 200 account; con più serve pageToken.
		$body = self::request( self::ADMIN . 'accountSummaries?pageSize=200' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$options = [];
		foreach ( (array) ( $body['accountSummaries'] ?? [] ) as $account ) {
			foreach ( (array) ( $account['propertySummaries'] ?? [] ) as $property ) {
				$name = (string) ( $property['property'] ?? '' );
				if ( preg_match( '#' . self::PROPERTY . '#', $name ) ) {
					$options[] = [
						'value' => $name,
						'label' => sprintf( '%s (%s)', sanitize_text_field( (string) ( $property['displayName'] ?? '' ) ), substr( $name, strlen( 'properties/' ) ) ),
					];
				}
			}
		}
		return new WP_REST_Response( $options );
	}

	/**
	 * I numeri, l'andamento e gli elenchi del periodo, dalla cache o da Google. Un errore non va in cache: la prossima
	 * apertura riprova.
	 */
	public static function report( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$missing = self::missing();
		if ( '' !== $missing ) {
			return new WP_Error(
				'barrio_analytics_setup',
				'account' === $missing ? __( 'Add the Google service account in Barrio → Settings.', 'barrio-suite' ) : __( 'Choose the Google Analytics property in Barrio → Analytics → Settings.', 'barrio-suite' ),
				[ 'status' => 400 ]
			);
		}
		$period = self::period( (string) $request['period'], (string) $request['from'], (string) $request['to'] );
		if ( is_wp_error( $period ) ) {
			return $period;
		}
		$property = self::settings()['property'];
		$key      = 'barrio_analytics_' . md5( (string) wp_json_encode( [ $property, $period['ranges'] ] ) );
		$report   = get_transient( $key );
		if ( ! is_array( $report ) ) {
			$report = self::fetch( $property, $period );
			if ( is_wp_error( $report ) ) {
				return $report;
			}
			set_transient( $key, $report, HOUR_IN_SECONDS );
		}
		return new WP_REST_Response( $report );
	}

	/**
	 * Le date del periodo e di quello subito prima, della stessa durata, come le vuole GA; il primo e l'ultimo giorno
	 * nel fuso del sito, per l'asse dell'andamento. I periodi fissi usano le date relative di GA, che valgono nel fuso
	 * della proprietà. Tutti finiscono ieri: oggi è a metà e falserebbe il confronto.
	 *
	 * @return Period|WP_Error
	 */
	public static function period( string $period, string $from = '', string $to = '' ): array|WP_Error {
		$utc = new DateTimeZone( 'UTC' );
		$end = new DateTimeImmutable( self::yesterday(), $utc );
		if ( 'custom' !== $period ) {
			$days    = (int) $period;
			$current = [ "{$days}daysAgo", 'yesterday' ];
			$before  = [ ( 2 * $days ) . 'daysAgo', ( $days + 1 ) . 'daysAgo' ];
			$start   = $end->modify( '-' . ( $days - 1 ) . ' days' );
		} else {
			$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $utc );
			$last  = DateTimeImmutable::createFromFormat( '!Y-m-d', $to, $utc );
			// Date che esistono (non 2026-02-30), in ordine, da FIRST_DAY a ieri.
			if ( ! $start || ! $last || $start->format( 'Y-m-d' ) !== $from || $last->format( 'Y-m-d' ) !== $to || $from < self::FIRST_DAY || $start > $last || $last > $end ) {
				return new WP_Error( 'rest_invalid_param', __( 'Choose a start and an end date from 2019 to yesterday, the end not before the start.', 'barrio-suite' ), [ 'status' => 400 ] );
			}
			$end      = $last;
			$days     = (int) $start->diff( $end )->days + 1;
			$previous = $start->modify( '-1 day' );
			$current  = [ $from, $to ];
			$before   = [ $previous->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' ), $previous->format( 'Y-m-d' ) ];
		}
		$range = static fn( array $dates, string $name ): array => [
			'startDate' => $dates[0],
			'endDate'   => $dates[1],
			'name'      => $name,
		];
		return [
			'ranges' => [ $range( $current, 'current' ), $range( $before, 'previous' ) ],
			'start'  => $start->format( 'Y-m-d' ),
			'end'    => $end->format( 'Y-m-d' ),
			'days'   => $days,
		];
	}

	// Il widget nella Bacheca di WordPress, che si aggiunge agli altri, a chi legge le statistiche, quando sono configurate.
	public static function dashboard(): void {
		if ( ! self::can() || '' !== self::missing() ) {
			return;
		}
		wp_add_dashboard_widget( 'barrio_analytics', __( 'Analytics', 'barrio-suite' ), [ self::class, 'widget' ] );
		$dir = dirname( __DIR__, 3 );
		// Solo in sviluppo manca: il pacchetto del plugin contiene la build.
		if ( ! is_file( "{$dir}/build/barrio-analytics.asset.php" ) ) {
			return;
		}
		/** @var array{dependencies: list<non-empty-string>, version: string} $asset */
		$asset = require "{$dir}/build/barrio-analytics.asset.php";
		wp_enqueue_script( 'barrio-analytics', plugins_url( 'build/barrio-analytics.js', "{$dir}/barrio-suite.php" ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'barrio-analytics', 'barrio-suite', "{$dir}/languages" );
		Admin::style();
	}

	/**
	 * Sostituisci la Bacheca di WordPress: per chi legge le statistiche, quando sono configurate, la Bacheca è la
	 * Panoramica di Barrio → Statistiche, senza le Impostazioni e senza i widget, anche quelli degli altri plugin. Gli
	 * altri utenti e la bacheca del network restano com'erano.
	 */
	public static function replaces(): bool {
		return self::settings()['replace_dashboard'] && self::can() && '' === self::missing() && 'dashboard' === get_current_screen()?->id;
	}

	// Su load-index.php, prima che WordPress prepari i widget: l'intestazione dell'admin, l'app delle pagine Barrio con la
	// sola Panoramica (assets/src/admin/index.js) e il piede.
	public static function replace_dashboard(): void {
		if ( ! self::replaces() ) {
			return;
		}
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- il titolo e la voce di menu della Bacheca, che index.php imposterebbe dopo.
		$GLOBALS['title']        = __( 'Dashboard', 'barrio-suite' );
		$GLOBALS['parent_file']  = 'index.php';
		$GLOBALS['submenu_file'] = 'index.php';
		// phpcs:enable
		Admin::enqueue();
		require_once ABSPATH . 'wp-admin/admin-header.php';
		Admin::page();
		require_once ABSPATH . 'wp-admin/admin-footer.php';
		exit;
	}

	// I dati li chiede lo script alla REST: la Bacheca non aspetta Google.
	public static function widget(): void {
		echo '<div id="barrio-analytics-widget"></div>';
	}

	/**
	 * I report della pagina in due chiamate batchRunReports, al più 5 report ciascuna. I numeri hanno anche il periodo
	 * prima, per il confronto; andamento ed elenchi solo il periodo.
	 *
	 * @param Period $period
	 * @return array<string, mixed>|WP_Error
	 */
	private static function fetch( string $property, array $period ): array|WP_Error {
		$names   = static fn( array $names ): array => array_map( static fn( string $name ): array => [ 'name' => $name ], $names );
		$current = [ $period['ranges'][0] ];
		// Chi torna più giorni conta una volta nella settimana e nel mese: le dimensioni di GA, non la somma dei giorni.
		$unit     = $period['days'] <= 92 ? 'date' : ( $period['days'] <= 366 ? 'isoYearIsoWeek' : 'yearMonth' );
		$requests = [
			[
				'dateRanges' => $period['ranges'],
				'metrics'    => $names( array_values( self::METRICS ) ),
			],
			[
				'dateRanges' => $current,
				'dimensions' => $names( [ $unit ] ),
				'metrics'    => $names( [ 'screenPageViews', 'activeUsers' ] ),
				'orderBys'   => [ [ 'dimension' => [ 'dimensionName' => $unit ] ] ],
			],
		];
		foreach ( self::LISTS as [ $dimensions, $metric ] ) {
			$requests[] = [
				'dateRanges' => $current,
				'dimensions' => $names( $dimensions ),
				'metrics'    => $names( [ $metric ] ),
				'orderBys'   => [
					[
						'metric' => [ 'metricName' => $metric ],
						'desc'   => true,
					],
				],
				'limit'      => 10,
			];
		}
		$reports = [];
		foreach ( array_chunk( $requests, 5 ) as $chunk ) {
			$body = self::request( self::DATA . "{$property}:batchRunReports", [ 'requests' => $chunk ] );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$reports = [ ...$reports, ...array_values( array_filter( (array) ( $body['reports'] ?? [] ), 'is_array' ) ) ];
		}

		$numbers = array_fill_keys(
			array_keys( self::METRICS ),
			[
				'value'    => 0.0,
				'previous' => 0.0,
			]
		);
		foreach ( self::rows( $reports[0] ?? [] ) as [ $dimensions, $values ] ) {
			$range = 'previous' === ( $dimensions['dateRange'] ?? '' ) ? 'previous' : 'value';
			foreach ( array_keys( self::METRICS ) as $index => $key ) {
				$numbers[ $key ][ $range ] = $values[ $index ] ?? 0.0;
			}
		}

		// Le pagine con titolo e link; negli altri il valore di GA (Organic Search, mobile, IT), che il JavaScript traduce.
		$lists = [];
		foreach ( array_keys( self::LISTS ) as $index => $list ) {
			$lists[ $list ] = array_map(
				static fn( array $row ): array => 'pages' === $list ? self::page( $row ) : [
					'name'  => (string) reset( $row[0] ),
					'value' => $row[1][0] ?? 0.0,
				],
				self::rows( $reports[ $index + 2 ] ?? [] )
			);
		}

		return [
			'numbers'     => $numbers,
			'trend'       => [
				'unit'   => self::UNITS[ $unit ][0],
				'points' => self::trend( $unit, self::rows( $reports[1] ?? [] ), $period ),
			],
			...$lists,
			// GA nasconde i gruppi con pochi utenti (Google Signals): i totali possono superare la somma degli elenchi.
			'thresholded' => [] !== array_filter( $reports, static fn( array $report ): bool => ! empty( $report['metadata']['subjectToThresholding'] ) ),
			'updated'     => wp_date( (string) get_option( 'time_format' ) ),
		];
	}

	/**
	 * L'andamento con un punto per giorno, settimana o mese, anche senza visite: GA non dà le righe vuote. L'asse va dal
	 * primo all'ultimo giorno del periodo nel fuso del sito, e oltre se GA, nel fuso della proprietà, ne dà uno prima o
	 * dopo. Ogni punto ha il primo giorno della sua settimana o del suo mese.
	 *
	 * @param list<Row> $rows
	 * @param Period    $period
	 * @return list<array{date: string, views: float, users: float}>
	 */
	private static function trend( string $unit, array $rows, array $period ): array {
		$utc    = new DateTimeZone( 'UTC' );
		$points = [];
		foreach ( $rows as [ $dimensions, $values ] ) {
			$key  = (string) ( $dimensions[ $unit ] ?? '' );
			$date = match ( $unit ) {
				'date'      => DateTimeImmutable::createFromFormat( '!Ymd', $key, $utc ),
				'yearMonth' => DateTimeImmutable::createFromFormat( '!Ym', $key, $utc ),
				// isoYearIsoWeek: 202645, l'anno ISO e la settimana.
				default     => preg_match( '/^[0-9]{6}$/', $key ) ? ( new DateTimeImmutable( 'today', $utc ) )->setISODate( (int) substr( $key, 0, 4 ), (int) substr( $key, 4 ) ) : false,
			};
			if ( $date ) {
				$points[ $date->format( 'Y-m-d' ) ] = [
					'views' => $values[0] ?? 0.0,
					'users' => $values[1] ?? 0.0,
				];
			}
		}
		// Il primo giorno della settimana ISO o del mese.
		$floor = static function ( string $day ) use ( $unit, $utc ): string {
			$date = new DateTimeImmutable( $day, $utc );
			$date = match ( $unit ) {
				'date'      => $date,
				'yearMonth' => $date->modify( 'first day of this month' ),
				default     => $date->setISODate( (int) $date->format( 'o' ), (int) $date->format( 'W' ) ),
			};
			return $date->format( 'Y-m-d' );
		};
		$keys  = [ ...array_keys( $points ), $floor( $period['start'] ), $floor( $period['end'] ) ];
		$key   = min( $keys );
		$last  = max( $keys );
		$trend = [];
		while ( $key <= $last ) {
			$trend[] = [ 'date' => $key ] + ( $points[ $key ] ?? [
				'views' => 0.0,
				'users' => 0.0,
			] );
			$key     = ( new DateTimeImmutable( $key, $utc ) )->modify( self::UNITS[ $unit ][1] )->format( 'Y-m-d' );
		}
		return $trend;
	}

	/**
	 * Una pagina più vista: il titolo del contenuto di WordPress con quel percorso, altrimenti il percorso ("Home page"
	 * per /, che con gli articoli in home non è un contenuto); il link all'host che GA ha registrato, che nei siti
	 * headless è il frontend.
	 *
	 * @param Row $row
	 * @return array{name: string, path: string, url: string, value: float}
	 */
	private static function page( array $row ): array {
		[ $dimensions, $values ] = $row;
		$path                    = (string) ( $dimensions['pagePath'] ?? '' );
		$host                    = (string) ( $dimensions['hostName'] ?? '' );
		$local                   = str_starts_with( $path, '/' );
		$id                      = $local ? url_to_postid( home_url( $path ) ) : 0;
		$title                   = $id ? trim( wp_strip_all_tags( get_post_field( 'post_title', $id ) ) ) : '';
		if ( '' === $title ) {
			$title = '/' === $path ? __( 'Home page', 'barrio-suite' ) : $path;
		}
		return [
			'name'  => $title,
			'path'  => $path,
			'url'   => $local && preg_match( '/^[a-z0-9.-]+(:[0-9]+)?$/i', $host ) ? esc_url_raw( "https://{$host}{$path}" ) : '',
			'value' => $values[0] ?? 0.0,
		];
	}

	/**
	 * Le righe di un report: le dimensioni per nome e le metriche in ordine. Con due periodi GA aggiunge la dimensione
	 * dateRange, con il nome del periodo.
	 *
	 * @param array<mixed> $report
	 * @return list<Row>
	 */
	private static function rows( array $report ): array {
		$names = array_column( (array) ( $report['dimensionHeaders'] ?? [] ), 'name' );
		$rows  = [];
		foreach ( (array) ( $report['rows'] ?? [] ) as $row ) {
			$values = array_map( 'strval', array_column( (array) ( $row['dimensionValues'] ?? [] ), 'value' ) );
			if ( count( $values ) === count( $names ) ) {
				$rows[] = [ (array) array_combine( $names, $values ), array_map( 'floatval', array_column( (array) ( $row['metricValues'] ?? [] ), 'value' ) ) ];
			}
		}
		return $rows;
	}

	// Ieri nel fuso del sito, Y-m-d: l'ultimo giorno dei periodi.
	private static function yesterday(): string {
		return current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );
	}

	/**
	 * GET senza $body, POST con, con il token del service account in sola lettura.
	 *
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>|WP_Error
	 */
	private static function request( string $url, ?array $body = null ): array|WP_Error {
		$token = Google::token( self::SCOPE );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$response = wp_remote_request(
			$url,
			[
				'method'  => null === $body ? 'GET' : 'POST',
				'timeout' => 20,
				'headers' => [
					'Authorization' => "Bearer {$token}",
					'Content-Type'  => 'application/json',
				],
				'body'    => null === $body ? '' : (string) wp_json_encode( $body ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'barrio_analytics_http', $response->get_error_message(), [ 'status' => 502 ] );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && is_array( $json ) ) {
			return $json;
		}
		$error = is_array( $json ) ? (array) ( $json['error'] ?? [] ) : [];
		// Un 403 sulla proprietà è l'accesso che manca; con l'API spenta nel progetto il messaggio di Google lo dice già.
		if ( 403 === $code && str_contains( $url, 'properties/' ) && ! in_array( 'SERVICE_DISABLED', array_column( (array) ( $error['details'] ?? [] ), 'reason' ), true ) ) {
			return new WP_Error(
				'barrio_analytics_access',
				/* translators: %s: email of the Google service account. */
				sprintf( __( 'The service account has no access to this property: add %s as a Viewer in Google Analytics → Admin → Property access management.', 'barrio-suite' ), Google::account()['client_email'] ?? '' ),
				[ 'status' => 502 ]
			);
		}
		/* translators: 1: HTTP status code, 2: error message from Google. */
		return new WP_Error( 'barrio_analytics_google', trim( sprintf( __( 'Google answered with error %1$d. %2$s', 'barrio-suite' ), $code, (string) ( $error['message'] ?? '' ) ) ), [ 'status' => 502 ] );
	}
}
