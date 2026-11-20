<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Analytics\Module;
use BarrioSuite\Crypto;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Il modulo Statistiche, con Google simulato su pre_http_request: cosa si chiede a GA4, la cache, gli errori e chi
// legge i report.
final class AnalyticsTest extends WP_UnitTestCase {

	private const EMAIL = 'barrio@hotel-lago.iam.gserviceaccount.com';

	private static string $private_key = '';

	/** @var list<array{url: string, body: array<string, mixed>|null}> Le richieste alle API di Analytics. */
	private array $requests = [];

	/** @var array{0: int, 1: array<string, mixed>}|null Una risposta di errore di Google, al posto dei report. */
	private ?array $error = null;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		openssl_pkey_export( openssl_pkey_new( [ 'private_key_bits' => 2048 ] ), self::$private_key );
	}

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$account = [
			'project_id'   => 'hotel-lago',
			'client_email' => self::EMAIL,
			'private_key'  => self::$private_key,
		];
		update_option( 'barrio_google', Crypto::encrypt( (string) wp_json_encode( $account ) ) );
		update_option( 'barrio_analytics', [ 'property' => 'properties/123456' ] );
		add_filter( 'pre_http_request', [ $this, 'google' ], 10, 3 );
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		parent::tear_down();
		self::flush_cache();
		wp_roles()->for_site();
	}

	/** @param array<string, mixed> $args */
	public function google( mixed $pre, array $args, string $url ): array {
		$code = 200;
		$body = [
			'access_token' => 'ya29.test',
			'expires_in'   => 3599,
		];
		if ( 'https://oauth2.googleapis.com/token' !== $url ) {
			$this->requests[] = [
				'url'  => $url,
				'body' => json_decode( (string) $args['body'], true ),
			];
			[ $code, $body ]  = $this->error ?? [ 200, str_contains( $url, 'accountSummaries' ) ? $this->summaries() : $this->reports( (array) json_decode( (string) $args['body'], true ) ) ];
		}
		return [
			'headers'  => [],
			'body'     => (string) wp_json_encode( $body ),
			'response' => [ 'code' => $code ],
			'cookies'  => [],
		];
	}

	public function test_each_period_asks_its_dates_dimensions_and_comparison(): void {
		$this->report( '7' );
		$this->report( '90' );
		$this->report( '365' );
		$this->report(
			'custom',
			[
				'from' => '2025-01-01',
				'to'   => '2025-12-31',
			]
		);

		$this->assertCount( 8, $this->requests, 'Two batchRunReports for each page.' );
		$this->assertSame( 'https://analyticsdata.googleapis.com/v1beta/properties/123456:batchRunReports', $this->requests[0]['url'] );
		$requests = array_merge( ...array_map( static fn( array $request ): array => $request['body']['requests'], $this->requests ) );
		$this->assertCount( 24, $requests, 'Six reports for each page, five in the first call.' );
		[ $numbers, $trend ] = $requests;

		$this->assertSame(
			[
				[
					'startDate' => '7daysAgo',
					'endDate'   => 'yesterday',
					'name'      => 'current',
				],
				[
					'startDate' => '14daysAgo',
					'endDate'   => '8daysAgo',
					'name'      => 'previous',
				],
			],
			$numbers['dateRanges']
		);
		$this->assertSame( [ 'activeUsers', 'screenPageViews', 'sessions', 'averageSessionDuration', 'bounceRate' ], array_column( $numbers['metrics'], 'name' ) );
		$this->assertCount( 1, $trend['dateRanges'], 'The trend and the lists only for the period.' );
		$this->assertSame( [ 'date', 'date', 'isoYearIsoWeek', 'isoYearIsoWeek' ], array_map( static fn( int $page ): string => $requests[ $page * 6 + 1 ]['dimensions'][0]['name'], [ 0, 1, 2, 3 ] ), 'Up to 90 days by day, 365 days and a custom year by ISO week.' );
		$this->assertSame( '90daysAgo', $requests[6]['dateRanges'][0]['startDate'] );
		$this->assertSame( [ '730daysAgo', '366daysAgo' ], [ $requests[12]['dateRanges'][1]['startDate'], $requests[12]['dateRanges'][1]['endDate'] ] );
		$this->assertSame(
			[ '2025-01-01', '2025-12-31', '2024-01-02', '2024-12-31' ],
			[ $requests[18]['dateRanges'][0]['startDate'], $requests[18]['dateRanges'][0]['endDate'], $requests[18]['dateRanges'][1]['startDate'], $requests[18]['dateRanges'][1]['endDate'] ],
			'The custom period is compared with the 365 days just before it.'
		);
		$this->assertSame(
			[ [ 'hostName', 'pagePath' ], [ 'sessionDefaultChannelGroup' ], [ 'deviceCategory' ], [ 'countryId' ] ],
			array_map( static fn( array $report ): array => array_column( $report['dimensions'], 'name' ), array_slice( $requests, 2, 4 ) )
		);
		$this->assertSame( 10, $requests[5]['limit'] );
		$this->assertTrue( $requests[5]['orderBys'][0]['desc'] );
	}

	public function test_custom_dates_must_exist_be_in_order_and_go_from_2019_to_yesterday(): void {
		$yesterday = current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );
		$today     = current_datetime()->format( 'Y-m-d' );
		// 0002: un anno scritto a metà da tastiera.
		foreach ( [ [ '2026-02-30', '2026-03-01' ], [ '2025-03-02', '2025-03-01' ], [ $yesterday, $today ], [ '', $yesterday ], [ '2025-1-01', '2025-01-02' ], [ '0002-01-10', $yesterday ], [ '2018-12-31', $yesterday ] ] as [ $from, $to ] ) {
			$this->assertSame( 400, $this->report( 'custom', compact( 'from', 'to' ) )->get_status(), "{$from} – {$to}" );
		}
		$this->assertSame(
			200,
			$this->report(
				'custom',
				[
					'from' => '2019-01-01',
					'to'   => $yesterday,
				]
			)->get_status()
		);
		$this->assertSame( 400, $this->report( '30' )->get_status() );
	}

	public function test_the_answer_becomes_numbers_trend_and_lists(): void {
		$page = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Le camere',
				'post_name'  => 'camere',
			]
		);
		$this->set_permalink_structure( '/%postname%/' );

		$report = $this->report( '7' )->get_data();

		$this->assertSame(
			[
				'value'    => 120.0,
				'previous' => 100.0,
			],
			$report['numbers']['users']
		);
		$this->assertSame( 0.42, $report['numbers']['bounce']['value'] );
		$this->assertSame( 'day', $report['trend']['unit'] );
		$days = array_column( $report['trend']['points'], 'views', 'date' );
		$this->assertCount( 7, $days, 'Every day of the period, also those without visits.' );
		$this->assertSame( current_datetime()->modify( '-1 day' )->format( 'Y-m-d' ), array_key_last( $days ) );
		$this->assertSame( 30.0, $days[ current_datetime()->modify( '-3 days' )->format( 'Y-m-d' ) ] );
		$this->assertSame( 0.0, $days[ current_datetime()->modify( '-2 days' )->format( 'Y-m-d' ) ] );
		$this->assertSame(
			[
				[
					'name'  => 'Le camere',
					'path'  => '/camere/',
					'url'   => 'https://www.hotel-lago.it/camere/',
					'value' => 80.0,
				],
				[
					'name'  => '/vecchia-pagina/',
					'path'  => '/vecchia-pagina/',
					'url'   => '',
					'value' => 20.0,
				],
				[
					'name'  => 'Home page',
					'path'  => '/',
					'url'   => 'https://www.hotel-lago.it/',
					'value' => 10.0,
				],
			],
			$report['pages'],
			'The title from WordPress, or the path; the link to the host recorded by GA, none without a host.'
		);
		$this->assertSame( [ 'Organic Search', 'Direct' ], array_column( $report['channels'], 'name' ) );
		$this->assertSame( [ 'IT' ], array_column( $report['countries'], 'name' ) );
		$this->assertTrue( $report['thresholded'] );
		$this->assertNotSame( 0, $page );
	}

	public function test_weeks_and_months_start_on_monday_and_on_the_first(): void {
		$weeks  = array_column( $this->report( '365' )->get_data()['trend']['points'], 'date' );
		$months = array_column(
			$this->report(
				'custom',
				[
					'from' => '2024-01-15',
					'to'   => '2025-06-10',
				]
			)->get_data()['trend']['points'],
			'date'
		);

		$this->assertGreaterThanOrEqual( 53, count( $weeks ) );
		$this->assertSame( [ '1' ], array_values( array_unique( array_map( static fn( string $day ): string => gmdate( 'N', (int) strtotime( $day ) ), $weeks ) ) ) );
		$this->assertCount( 18, $months );
		$this->assertSame( [ '2024-01-01', '2025-06-01' ], [ $months[0], end( $months ) ] );
	}

	public function test_a_second_opening_reads_the_cache_and_another_property_does_not(): void {
		$this->report( '28' );
		$this->report( '28' );
		$this->assertCount( 2, $this->requests, 'The card and the widget ask the same report as the page: no more calls.' );

		$this->report( '7' );
		$this->assertCount( 4, $this->requests );

		$this->request( 'PUT', '/barrio/v1/analytics', [ 'property' => 'properties/999' ] );
		$this->report( '28' );
		$this->assertCount( 6, $this->requests );
		$this->assertStringContainsString( 'properties/999:', $this->requests[5]['url'] );
	}

	public function test_an_error_is_not_cached_and_the_missing_access_names_the_service_account(): void {
		$this->error = [
			403,
			[
				'error' => [
					'code'    => 403,
					'message' => 'User does not have sufficient permissions for this property.',
					'status'  => 'PERMISSION_DENIED',
				],
			],
		];
		$response    = $this->report( '28' );
		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( 'barrio_analytics_access', $response->as_error()->get_error_code() );
		$this->assertStringContainsString( self::EMAIL . ' as a Viewer', $response->as_error()->get_error_message() );

		$this->error = [
			403,
			[
				'error' => [
					'code'    => 403,
					'message' => 'Google Analytics Data API has not been used in project hotel-lago before or it is disabled.',
					'details' => [ [ 'reason' => 'SERVICE_DISABLED' ] ],
				],
			],
		];
		$this->assertStringContainsString( 'Data API has not been used', $this->report( '28' )->as_error()->get_error_message(), 'An API turned off: the message of Google.' );

		$this->error = null;
		$this->assertSame( 200, $this->report( '28' )->get_status() );
		$this->assertCount( 4, $this->requests, 'The errors were not cached: the third opening asks Google again.' );
	}

	public function test_without_service_account_or_property_nothing_calls_google_and_there_is_no_widget(): void {
		update_option( 'barrio_analytics', [ 'property' => '' ] );
		$this->assertSame( 'property', Module::admin_data( [] )['analytics']['missing'] );
		$this->assertSame( 'barrio_analytics_setup', $this->report( '28' )->as_error()->get_error_code() );
		$this->assertFalse( $this->widget() );

		delete_option( 'barrio_google' );
		update_option( 'barrio_analytics', [ 'property' => 'properties/123456' ] );
		$this->assertSame( 'account', Module::admin_data( [] )['analytics']['missing'] );
		$this->assertSame( 400, $this->report( '28' )->get_status() );
		$this->assertFalse( $this->widget() );

		$this->assertSame( [], $this->requests );
	}

	public function test_the_widget_does_not_wait_for_google(): void {
		$this->assertTrue( $this->widget() );
		$this->assertSame( [], $this->requests, 'The dashboard prints an empty box: the data come from the REST API.' );
		ob_start();
		Module::widget();
		$this->assertSame( '<div id="barrio-analytics-widget"></div>', ob_get_clean() );
	}

	public function test_editors_read_the_reports_authors_do_not_and_only_administrators_change_the_settings(): void {
		$this->assertTrue( get_role( 'editor' )->has_cap( 'barrio_view_analytics' ), 'From migration 0001.' );
		$this->assertFalse( get_role( 'author' )->has_cap( 'barrio_view_analytics' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 200, $this->report( '28' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/barrio/v1/analytics' )->get_status() );
		$this->assertSame( 403, $this->request( 'PUT', '/barrio/v1/analytics', [ 'property' => 'properties/1' ] )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/barrio/v1/analytics/properties' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertSame( 403, $this->report( '28' )->get_status() );
		$this->assertArrayNotHasKey( 'analytics', Module::admin_data( [] ) );
		$this->assertFalse( $this->widget() );
	}

	public function test_the_schema_rejects_an_invalid_property_and_the_permissions_choose_the_roles(): void {
		foreach ( [ '123456', 'properties/abc', 'properties/1/../2', 'accounts/1' ] as $property ) {
			$this->assertSame( 400, $this->request( 'PUT', '/barrio/v1/analytics', compact( 'property' ) )->get_status(), $property );
		}
		$this->assertSame( 'properties/123456', Module::settings()['property'] );

		$saved = $this->request(
			'PUT',
			'/barrio/v1/analytics',
			[
				'roles' => [
					'editor' => [],
					'author' => [ 'barrio_view_analytics' ],
				],
			]
		)->get_data();
		$this->assertSame( 'properties/123456', $saved['property'], 'A missing value stays as it is.' );
		$this->assertSame( self::EMAIL, $saved['email'] );
		$this->assertSame( [ 'barrio_view_analytics' ], $saved['roles']['author'] );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'barrio_view_analytics' ) );

		$this->assertSame( '', $this->request( 'PUT', '/barrio/v1/analytics', [ 'property' => '' ] )->get_data()['property'] );
		$this->assertArrayNotHasKey( 'barrio_analytics', wp_load_alloptions(), 'Read only in the admin.' );
	}

	public function test_the_overview_replaces_the_dashboard_only_for_who_can_see_it(): void {
		$this->assertFalse( $this->dashboard(), 'Off by default: the dashboard of WordPress stays.' );

		$saved = $this->request( 'PUT', '/barrio/v1/analytics', [ 'replace_dashboard' => true ] )->get_data();
		$this->assertTrue( $saved['replace_dashboard'] );
		$this->assertSame( 'properties/123456', $saved['property'], 'A missing value stays as it is.' );
		$this->assertTrue( $this->dashboard() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertTrue( $this->dashboard() );
		$this->assertFalse( $this->dashboard( 'dashboard-network' ), 'Not the dashboard of the network.' );
		$this->assertFalse( $this->dashboard( 'edit-post' ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertFalse( $this->dashboard(), 'Who cannot see the analytics keeps the dashboard.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		update_option(
			'barrio_analytics',
			[
				'property'          => '',
				'replace_dashboard' => true,
			]
		);
		$this->assertFalse( $this->dashboard(), 'Without a property the dashboard stays.' );
	}

	public function test_the_properties_come_from_the_account_summaries(): void {
		$this->assertSame(
			[
				[
					'value' => 'properties/123456',
					'label' => 'Hotel Lago (123456)',
				],
				[
					'value' => 'properties/789',
					'label' => 'Ristorante (789)',
				],
			],
			$this->request( 'GET', '/barrio/v1/analytics/properties' )->get_data()
		);
		$this->assertSame( 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200', $this->requests[0]['url'] );
	}

	public function test_turning_the_module_off_and_uninstalling_take_the_capability_away(): void {
		Module::uninstall();
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'barrio_view_analytics' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'barrio_view_analytics' ) );
	}

	/** @param array<string, string> $params */
	private function report( string $period, array $params = [] ): WP_REST_Response {
		return $this->request( 'GET', '/barrio/v1/analytics/report', [ 'period' => $period ] + $params );
	}

	/** @param array<string, mixed> $params */
	private function request( string $method, string $path, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		return rest_get_server()->dispatch( $request );
	}

	// Se la Bacheca di WordPress ($screen) diventa la Panoramica.
	private function dashboard( string $screen = 'dashboard' ): bool {
		set_current_screen( $screen );
		return Module::replaces();
	}

	// Se la Bacheca di WordPress ha il widget.
	private function widget(): bool {
		global $wp_meta_boxes;
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard' );
		$wp_meta_boxes = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- i widget di questa prova.
		Module::dashboard();
		return isset( $wp_meta_boxes['dashboard']['normal']['core']['barrio_analytics'] );
	}

	/** @return array<string, mixed> */
	private function summaries(): array {
		return [
			'accountSummaries' => [
				[
					'account'           => 'accounts/1',
					'displayName'       => 'Hellobarrio',
					'propertySummaries' => [
						[
							'property'    => 'properties/123456',
							'displayName' => 'Hotel Lago',
						],
						[
							'property'    => 'properties/789',
							'displayName' => 'Ristorante',
						],
					],
				],
			],
		];
	}

	/**
	 * Le risposte di batchRunReports, nell'ordine dei report chiesti: i numeri con i due periodi, l'andamento con un
	 * giorno senza visite, gli elenchi.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function reports( array $body ): array {
		$row     = static fn( array $dimensions, array $metrics ): array => [
			'dimensionValues' => array_map( static fn( string $value ): array => [ 'value' => $value ], $dimensions ),
			'metricValues'    => array_map( static fn( string $value ): array => [ 'value' => $value ], $metrics ),
		];
		$reports = [];
		foreach ( $body['requests'] as $request ) {
			$dimensions = array_column( $request['dimensions'] ?? [], 'name' );
			$rows       = match ( $dimensions ) {
				[] => [ $row( [ 'current' ], [ '120', '300', '150', '95.5', '0.42' ] ), $row( [ 'previous' ], [ '100', '280', '140', '90', '0.5' ] ) ],
				[ 'date' ] => [
					$row( [ current_datetime()->modify( '-4 days' )->format( 'Ymd' ) ], [ '40', '15' ] ),
					$row( [ current_datetime()->modify( '-3 days' )->format( 'Ymd' ) ], [ '30', '12' ] ),
				],
				[ 'hostName', 'pagePath' ] => [ $row( [ 'www.hotel-lago.it', '/camere/' ], [ '80' ] ), $row( [ '(not set)', '/vecchia-pagina/' ], [ '20' ] ), $row( [ 'www.hotel-lago.it', '/' ], [ '10' ] ) ],
				[ 'sessionDefaultChannelGroup' ] => [ $row( [ 'Organic Search' ], [ '90' ] ), $row( [ 'Direct' ], [ '40' ] ) ],
				[ 'countryId' ] => [ $row( [ 'IT' ], [ '100' ] ) ],
				default => [],
			};
			$headers   = [] === $dimensions ? [ 'dateRange' ] : $dimensions;
			$reports[] = [
				'dimensionHeaders' => array_map( static fn( string $name ): array => [ 'name' => $name ], $headers ),
				'rows'             => $rows,
			] + ( [ 'countryId' ] === $dimensions ? [ 'metadata' => [ 'subjectToThresholding' => true ] ] : [] );
		}
		return [ 'reports' => $reports ];
	}
}
