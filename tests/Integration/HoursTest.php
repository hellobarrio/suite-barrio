<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Crypto;
use BarrioSuite\Hours\Module;
use BarrioSuite\Hours\Render;
use BarrioSuite\Modules;
use BarrioSuite\Seo\Schema;
use BarrioSuite\Seo\Settings;
use DateTimeImmutable;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Il modulo Orari: lo schema dell'opzione, il blocco e lo stato con la cache di pagina, Google simulato su
// pre_http_request (la firma del token la prova AiTest), WP-Cron, i permessi e lo schema SEO.
final class HoursTest extends WP_UnitTestCase {

	private static string $private_key = '';

	/** @var list<array{url: string, args: array<string, mixed>}> Le richieste a Places. */
	private array $requests = [];

	/** @var array{0: int, 1: array<string, mixed>} La risposta di Places: codice e corpo. */
	private array $place = [ 200, [] ];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		openssl_pkey_export( openssl_pkey_new( [ 'private_key_bits' => 2048 ] ), self::$private_key );
	}

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		update_option( 'timezone_string', 'Europe/Rome' );
		update_option( 'time_format', 'H:i' );
		update_option( 'date_format', 'j F Y' );
		update_option( 'start_of_week', 1 );
		$account = [
			'project_id'   => 'trattoria-mario',
			'client_email' => 'barrio@trattoria-mario.iam.gserviceaccount.com',
			'private_key'  => self::$private_key,
		];
		update_option( 'barrio_google', Crypto::encrypt( (string) wp_json_encode( $account ) ) );
		add_filter( 'pre_http_request', [ $this, 'google' ], 10, 3 );
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		wp_unschedule_hook( 'barrio_hours_sync' );
		parent::tear_down();
		self::flush_cache();
		wp_roles()->for_site();
	}

	/** @param array<string, mixed> $args */
	public function google( mixed $pre, array $args, string $url ): array {
		$body = [
			'access_token' => 'ya29.test',
			'expires_in'   => 3599,
		];
		$code = 200;
		if ( 'https://oauth2.googleapis.com/token' !== $url ) {
			$this->requests[] = compact( 'url', 'args' );
			[ $code, $body ]  = $this->place;
		}
		return [
			'headers'  => [],
			'body'     => (string) wp_json_encode( $body ),
			'response' => [ 'code' => $code ],
			'cookies'  => [],
		];
	}

	public function test_the_schema_refuses_invalid_hours_days_and_dates(): void {
		$week = $this->week( [ 1 => [ $this->slot( '09:00', '13:00' ) ] ] );
		$this->assertSame( 200, $this->request( 'PUT', '/barrio/v1/hours', [ 'week' => $week ] )->get_status() );

		$invalid = [
			'hour 24'        => [ 'week' => $this->week( [ 1 => [ $this->slot( '09:00', '24:00' ) ] ] ) ],
			'no leading 0'   => [ 'week' => $this->week( [ 1 => [ $this->slot( '9:00', '13:00' ) ] ] ) ],
			'day 8'          => [ 'week' => $this->week( [] ) + [ 8 => [] ] ],
			'missing day'    => [ 'week' => array_slice( $this->week( [] ), 0, 6, true ) ],
			'extra property' => [ 'week' => $this->week( [ 1 => [ $this->slot( '09:00', '13:00' ) + [ 'note' => 'x' ] ] ] ) ],
			'february 30'    => [ 'exceptions' => [ $this->special( '2030-02-30', '2030-02-30' ) ] ],
			'month 13'       => [ 'exceptions' => [ $this->special( '2030-13-01', '2030-13-01' ) ] ],
			'ends before'    => [ 'exceptions' => [ $this->special( '2030-12-26', '2030-12-24' ) ] ],
			'place id'       => [
				'source'   => 'google',
				'place_id' => 'ChIJ/../x',
			],
		];
		foreach ( $invalid as $name => $body ) {
			$this->assertSame( 400, $this->request( 'PUT', '/barrio/v1/hours', $body )->get_status(), $name );
		}
		$this->assertSame( [ $this->slot( '09:00', '13:00' ) ], Module::settings()['week'][1], 'Nothing was written.' );
	}

	public function test_a_week_with_a_closing_after_midnight_and_a_special_day_in_the_block_and_the_status(): void {
		$this->request(
			'PUT',
			'/barrio/v1/hours',
			[
				'week'       => $this->week(
					[
						1 => [ $this->slot( '15:00', '19:00' ), $this->slot( '09:00', '13:00' ) ],
						2 => [ $this->slot( '09:00', '13:00' ), $this->slot( '15:00', '19:00' ) ],
						5 => [ $this->slot( '20:00', '02:00' ) ],
						7 => [ $this->slot( '00:00', '00:00' ) ],
					]
				),
				'exceptions' => [ $this->special( '2099-12-24', '2099-12-26', [], [ 'en' => 'Christmas' ] ) ],
			]
		);
		$saved = Module::settings();
		$this->assertSame( [ $this->slot( '09:00', '13:00' ), $this->slot( '15:00', '19:00' ) ], $saved['week'][1], 'The hours of a day in order.' );

		$week = do_blocks( '<!-- wp:barrio/hours {"group":true} /-->' );
		$this->assertStringContainsString( '<tr><th scope="row">Mon–Tue</th><td><span>09:00–13:00</span><span>15:00–19:00</span></td></tr>', $week );
		$this->assertStringContainsString( '<tr><th scope="row">Wed–Thu</th><td><span>Closed</span></td></tr>', $week );
		$this->assertStringContainsString( '<tr><th scope="row">Fri</th><td><span>20:00–02:00</span></td></tr>', $week );
		$this->assertStringContainsString( '<tr><th scope="row">Sun</th><td><span>Open 24 hours</span></td></tr>', $week );
		$this->assertMatchesRegularExpression( '#<caption>Special opening hours</caption><tbody><tr data-until="\d+"><th scope="row">24 December 2099 – 26 December 2099</th><td><span>Closed</span><span class="barrio-hours__note">Christmas</span></td></tr></tbody>#', $week, 'Another year: the date format of the site.' );
		$this->assertSame( $week, do_shortcode( '[barrio_hours group="1"]' ), 'The shortcode is the same as the block.' );
		$this->assertStringContainsString( '<th scope="row">Monday</th>', do_shortcode( '[barrio_hours]' ) );
		$this->assertTrue( wp_style_is( 'barrio-hours' ) );
		$card = apply_filters( 'barrio_admin_data', [] )['hours'];
		$this->assertSame( '24 December 2099 – 26 December 2099', $card['next'] );
		$this->assertSame( 'manual', $card['source'] );
		$today = current_datetime()->format( 'Y-m-d' );
		$this->assertSame( wp_date( 'M j' ), Render::dates( $this->special( $today, $today ), Render::short_date() ), 'This year without the year, to fit in one row.' );

		// La pagina scritta un venerdì, letta dal browser nei giorni dopo, come con la cache.
		$friday = new DateTimeImmutable( '2099-12-18', wp_timezone() );
		$data   = Render::window( $saved, $friday );
		$at     = static fn( string $time ): int => ( new DateTimeImmutable( $time, wp_timezone() ) )->getTimestamp();
		$this->assertSame( 'Today: 20:00–02:00', $data['days'][0]['today'] );
		$this->assertSame( 'Today: Closed · Christmas', $data['days'][6]['today'] );
		$this->assertSame( [ 'Open · closes at 02:00', 'Open · closes at 02:00', 'Closed · opens tomorrow at 00:00', 'Open · closes at 00:00', 'Closed · opens Sunday at 00:00' ], array_column( array_map( static fn( string $time ): ?array => Render::status( $data, $at( $time ) ), [ '2099-12-18 21:00', '2099-12-19 01:59', '2099-12-19 03:00', '2099-12-20 12:00', '2099-12-23 20:00' ] ), 'text' ) );

		$status = do_shortcode( '[barrio_hours show="status"]' );
		$this->assertMatchesRegularExpression( '#^<p class="barrio-hours is-status is-(open|closed)" data-hours="[^"]+"><span>(Open|Closed)#', $status );
		$this->assertStringContainsString( 'modules/hours/assets/frontend/hours.js', get_echo( [ wp_script_modules(), 'print_enqueued_script_modules' ] ) );
	}

	public function test_nothing_is_shown_without_hours_and_past_special_days_go(): void {
		$this->assertSame( '', do_shortcode( '[barrio_hours]' ) );

		update_option(
			'barrio_hours',
			[
				'exceptions' => [
					$this->special( current_datetime()->modify( '-1 day' )->format( 'Y-m-d' ), current_datetime()->modify( '-1 day' )->format( 'Y-m-d' ) ),
					$this->special( current_datetime()->format( 'Y-m-d' ), current_datetime()->format( 'Y-m-d' ) ),
				],
			]
		);
		$this->assertSame( [ current_datetime()->format( 'Y-m-d' ) ], array_column( $this->request( 'GET', '/barrio/v1/hours' )->get_data()['exceptions'], 'from' ) );
	}

	public function test_google_writes_the_week_and_the_special_days_and_an_error_reaches_the_dashboard(): void {
		$special     = current_datetime()->modify( '+2 days' );
		$closed      = current_datetime()->modify( '+3 days' );
		$date        = static fn( DateTimeImmutable $day ): array => [
			'year'  => (int) $day->format( 'Y' ),
			'month' => (int) $day->format( 'n' ),
			'day'   => (int) $day->format( 'j' ),
		];
		$point       = static fn( int $day, int $hour, int $minute = 0 ): array => [
			'day'    => $day,
			'hour'   => $hour,
			'minute' => $minute,
		];
		$this->place = [
			200,
			[
				'displayName'         => [ 'text' => 'Trattoria <b>Mario</b>' ],
				'regularOpeningHours' => [
					'periods' => [
						[
							'open'  => $point( 0, 12 ),
							'close' => $point( 0, 15 ),
						],
						[
							'open'  => $point( 5, 19 ),
							'close' => $point( 6, 1, 30 ),
						],
						// Dal sabato alla domenica: la settimana di Places ricomincia.
						[
							'open'  => $point( 6, 19 ),
							'close' => $point( 0, 1 ),
						],
						// Più lungo di un giorno: si divide a mezzanotte.
						[
							'open'  => $point( 1, 8 ),
							'close' => $point( 3, 20 ),
						],
					],
				],
				'currentOpeningHours' => [
					'periods'     => [
						[
							'open'  => $point( 4, 10 ) + [ 'date' => $date( $special ) ],
							'close' => $point( 4, 14 ) + [ 'date' => $date( $special ) ],
						],
					],
					'specialDays' => [ [ 'date' => $date( $special ) ], [ 'date' => $date( $closed ) ] ],
				],
			],
		];

		$response = $this->request(
			'PUT',
			'/barrio/v1/hours',
			[
				'source'   => 'google',
				'place_id' => 'ChIJ_test-1',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		[ $request ] = $this->requests;
		$this->assertSame( 'https://places.googleapis.com/v1/places/ChIJ_test-1?languageCode=en', $request['url'] );
		$this->assertSame( 'Bearer ya29.test', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'trattoria-mario', $request['args']['headers']['X-Goog-User-Project'] );
		$this->assertStringContainsString( 'regularOpeningHours.periods', $request['args']['headers']['X-Goog-FieldMask'] );
		$settings = Module::settings();
		$this->assertSame(
			[
				1 => [ $this->slot( '08:00', '00:00' ) ],
				2 => [ $this->slot( '00:00', '00:00' ) ],
				3 => [ $this->slot( '00:00', '20:00' ) ],
				4 => [],
				5 => [ $this->slot( '19:00', '01:30' ) ],
				6 => [ $this->slot( '19:00', '01:00' ) ],
				7 => [ $this->slot( '12:00', '15:00' ) ],
			],
			$settings['week']
		);
		$this->assertSame(
			[
				$this->special( $special->format( 'Y-m-d' ), $special->format( 'Y-m-d' ), [ $this->slot( '10:00', '14:00' ) ] ),
				$this->special( $closed->format( 'Y-m-d' ), $closed->format( 'Y-m-d' ) ),
			],
			$settings['exceptions']
		);
		$this->assertSame( 'Trattoria Mario', $settings['google']['name'] );
		$this->assertEqualsWithDelta( time(), $settings['google']['synced_at'], 5 );

		// Con Google la settimana non si modifica da qui.
		$this->request( 'PUT', '/barrio/v1/hours', [ 'week' => $this->week( [] ) ] );
		$this->assertSame( $settings['week'], Module::settings()['week'] );

		$this->place = [ 403, [ 'error' => [ 'message' => 'Places API (New) has not been used in project trattoria-mario.' ] ] ];
		$error       = $this->request( 'POST', '/barrio/v1/hours/sync' );
		$this->assertSame( 502, $error->get_status() );
		$message = 'Google did not return the opening hours. Places API (New) has not been used in project trattoria-mario.';
		$this->assertSame( $message, $error->as_error()->get_error_message() );
		$this->assertSame( $settings['week'], Module::settings()['week'], 'The hours of before stay.' );
		$this->assertSame( $message, apply_filters( 'barrio_admin_data', [] )['hours']['error'] );

		// Sempre aperto: un periodo senza close.
		$this->place = [ 200, [ 'regularOpeningHours' => [ 'periods' => [ [ 'open' => $point( 0, 0 ) ] ] ] ] ];
		$this->request( 'POST', '/barrio/v1/hours/sync' );
		$this->assertSame( array_fill( 1, 7, [ $this->slot( '00:00', '00:00' ) ] ), Module::settings()['week'] );
		$this->assertSame( '', Module::settings()['google']['error'] );
	}

	public function test_the_nightly_sync_runs_at_3_site_time_only_with_google(): void {
		Module::schedule();
		$this->assertFalse( wp_next_scheduled( 'barrio_hours_sync' ) );

		update_option(
			'barrio_hours',
			[
				'source'   => 'google',
				'place_id' => 'ChIJ_test-1',
			]
		);
		Module::schedule();
		$next = wp_next_scheduled( 'barrio_hours_sync' );
		$this->assertIsInt( $next );
		$this->assertSame( '03:00', wp_date( 'H:i', $next ) );
		$this->assertGreaterThan( time(), $next );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS, $next );

		$this->request( 'PUT', '/barrio/v1/hours', [ 'source' => 'manual' ] );
		$this->assertFalse( wp_next_scheduled( 'barrio_hours_sync' ) );

		update_option( 'barrio_hours', [ 'source' => 'google' ] );
		Module::schedule();
		Modules::set( 'hours', false );
		$this->assertFalse( wp_next_scheduled( 'barrio_hours_sync' ), 'Turning the module off removes it.' );
	}

	public function test_editors_edit_the_hours_but_not_the_source_and_authors_nothing(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 200, $this->request( 'GET', '/barrio/v1/hours' )->get_status() );
		$this->assertArrayNotHasKey( 'roles', $this->request( 'GET', '/barrio/v1/hours' )->get_data() );
		$this->assertSame( 200, $this->request( 'PUT', '/barrio/v1/hours', [ 'week' => $this->week( [ 3 => [ $this->slot( '10:00', '12:00' ) ] ] ) ] )->get_status() );
		$this->assertSame( 403, $this->request( 'PUT', '/barrio/v1/hours', [ 'source' => 'manual' ] )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/barrio/v1/hours/sync' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/barrio/v1/hours' )->get_status() );
		$this->assertArrayNotHasKey( 'hours', apply_filters( 'barrio_admin_data', [] ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->request( 'PUT', '/barrio/v1/hours', [ 'roles' => [ 'editor' => [] ] ] );
		$this->assertFalse( get_role( 'editor' )->has_cap( Module::CAP ) );
	}

	public function test_the_organization_has_the_opening_hours_as_google_wants_them(): void {
		$this->request(
			'PUT',
			'/barrio/v1/hours',
			[
				'week'       => $this->week(
					[
						1 => [ $this->slot( '09:00', '18:00' ) ],
						2 => [ $this->slot( '09:00', '18:00' ) ],
						6 => [ $this->slot( '18:00', '02:00' ) ],
						7 => [ $this->slot( '00:00', '00:00' ) ],
					]
				),
				'exceptions' => [
					$this->special( '2099-12-25', '2099-12-25' ),
					$this->special( '2099-12-31', '2099-12-31', [ $this->slot( '10:00', '14:00' ) ] ),
				],
			]
		);
		$organization = Settings::get()['organization'];

		$this->assertSame(
			[
				[
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => [ 'Monday', 'Tuesday' ],
					'opens'     => '09:00',
					'closes'    => '18:00',
				],
				[
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => [ 'Saturday' ],
					'opens'     => '18:00',
					'closes'    => '02:00',
				],
				[
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => [ 'Sunday' ],
					'opens'     => '00:00',
					'closes'    => '23:59',
				],
				[
					'@type'        => 'OpeningHoursSpecification',
					'validFrom'    => '2099-12-25',
					'validThrough' => '2099-12-25',
					'opens'        => '00:00',
					'closes'       => '00:00',
				],
				[
					'@type'        => 'OpeningHoursSpecification',
					'validFrom'    => '2099-12-31',
					'validThrough' => '2099-12-31',
					'opens'        => '10:00',
					'closes'       => '14:00',
				],
			],
			Schema::organization( $organization, home_url( '/' ), '' )['openingHoursSpecification']
		);
		$this->assertArrayNotHasKey( 'openingHoursSpecification', Schema::organization( [ 'type' => 'Organization' ] + $organization, home_url( '/' ), '' ), 'Not a property of Organization.' );
		Modules::set( 'hours', false );
		$this->assertArrayNotHasKey( 'openingHoursSpecification', Schema::organization( $organization, home_url( '/' ), '' ) );
	}

	public function test_the_preview_renders_hours_not_saved_yet(): void {
		$html = $this->request(
			'POST',
			'/barrio/v1/hours/preview',
			[
				'week'       => $this->week( [ 4 => [ $this->slot( '08:30', '12:30' ) ] ] ),
				'exceptions' => [],
			]
		)->get_data()['html'];

		$this->assertStringContainsString( 'is-status', $html );
		$this->assertStringContainsString( '<tr><th scope="row">Thursday</th><td><span>08:30–12:30</span></td></tr>', $html );
		$this->assertSame( [], array_filter( Module::settings()['week'] ) );
	}

	/** @return array{open: string, close: string} */
	private function slot( string $open, string $close ): array {
		return compact( 'open', 'close' );
	}

	/**
	 * @param array<int, mixed> $days
	 * @return array<int, mixed>
	 */
	private function week( array $days ): array {
		return array_replace( array_fill( 1, 7, [] ), $days );
	}

	/**
	 * @param list<array{open: string, close: string}> $hours
	 * @param array<string, string>                    $note
	 * @return array<string, mixed>
	 */
	private function special( string $from, string $to, array $hours = [], array $note = [] ): array {
		return compact( 'from', 'to', 'hours', 'note' );
	}

	/** @param array<string, mixed> $params */
	private function request( string $method, string $path, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		return rest_do_request( $request );
	}
}
