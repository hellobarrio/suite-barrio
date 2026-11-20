<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Banners\BannerRepository;
use BarrioSuite\Banners\Module;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Il modulo Banner: quale banner compare e quando, anche in due lingue; la riga della lingua principale; il transient
// che tiene il sito senza query; REST e permessi.
final class BannersTest extends WP_UnitTestCase {

	private string $lang = 'it';

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		update_option( 'timezone_string', 'Europe/Rome' );
		add_filter( 'barrio_languages', static fn(): array => [ 'it', 'en' ] );
		add_filter( 'barrio_current_language', fn(): string => $this->lang );
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		wp_dequeue_style( 'barrio-banners' );
		parent::tear_down();
		wp_roles()->for_site();
	}

	public function test_the_main_language_row_is_always_there(): void {
		$english = $this->banner( [ 'i18n' => [ 'en' => $this->text( 'Sale' ) ] ] );
		$error   = BannerRepository::save( null, $english );
		$this->assertWPError( $error );
		$this->assertSame( 'barrio_banner_main_language', $error->get_error_code() );
		$this->assertSame( 400, $this->request( 'POST', '/barrio/v1/banners', $english )->get_status() );
		$this->assertSame( [], BannerRepository::all() );

		$id = $this->create();
		$this->assertEqualsCanonicalizing( [ 'it', 'en' ], array_keys( BannerRepository::get( $id )['i18n'] ?? [] ) );
	}

	public function test_the_schema_refuses_invalid_texts_and_dates(): void {
		$invalid = [
			'new tab not a boolean' => $this->banner( [ 'new_tab' => 'sometimes' ] ),
			'javascript link'       => $this->banner( [ 'i18n' => [ 'it' => $this->text( 'Saldi', 'javascript:alert(1)' ) ] ] ),
			'only tags'             => $this->banner( [ 'i18n' => [ 'it' => $this->text( '<b></b>' ) ] ] ),
			'too long'              => $this->banner( [ 'i18n' => [ 'it' => $this->text( str_repeat( 'a', 256 ) ) ] ] ),
			'lang uppercase'        => $this->banner( [ 'i18n' => [ 'IT' => $this->text( 'Saldi' ) ] ] ),
			'february 30'           => $this->banner( [ 'starts_at' => '2030-02-30T10:00' ] ),
			'ends before start'     => $this->banner(
				[
					'starts_at' => '2030-06-02T10:00',
					'ends_at'   => '2030-06-01T10:00',
				]
			),
		];
		foreach ( $invalid as $name => $body ) {
			$this->assertSame( 400, $this->request( 'POST', '/barrio/v1/banners', $body )->get_status(), $name );
		}
		$this->assertSame( [], BannerRepository::all(), 'Nothing was written.' );
	}

	public function test_dates_are_in_the_site_time_zone_and_the_status_follows_the_period(): void {
		$response = $this->request(
			'POST',
			'/barrio/v1/banners',
			$this->banner(
				[
					'starts_at' => '2099-06-01T10:00',
					'ends_at'   => '2099-06-30T23:59',
				]
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( [ '2099-06-01T10:00', '2099-06-30T23:59', 'scheduled' ], [ $data['starts_at'], $data['ends_at'], $data['status'] ] );
		$this->assertSame( '2099-06-01 08:00:00', BannerRepository::get( $data['id'] )['starts_at'] ?? null, 'UTC in the database.' );

		$banner = BannerRepository::get( $data['id'] );
		$this->assertNotNull( $banner );
		$this->assertSame( 'expired', BannerRepository::status( [ 'ends_at' => '2000-01-01 00:00:00' ] + $banner ) );
		$this->assertSame( 'active', BannerRepository::status( [ 'starts_at' => '2000-01-01 00:00:00' ] + $banner ) );
		$this->assertSame( 'off', BannerRepository::status( [ 'is_enabled' => false ] + $banner ) );
	}

	public function test_banners_are_created_changed_duplicated_and_deleted_by_those_who_manage_them(): void {
		$id = $this->request( 'POST', '/barrio/v1/banners', $this->banner() )->get_data()['id'];
		$this->assertSame( 200, $this->request( 'PUT', "/barrio/v1/banners/{$id}", $this->banner( [ 'new_tab' => true ] ) )->get_status() );
		$this->assertTrue( BannerRepository::get( $id )['new_tab'] ?? null );
		$this->assertSame( 404, $this->request( 'PUT', '/barrio/v1/banners/999999', $this->banner() )->get_status() );

		$copy = $this->request( 'POST', "/barrio/v1/banners/{$id}/duplicate" )->get_data();
		$this->assertFalse( $copy['is_enabled'], 'The copy is off.' );
		$this->assertEquals( BannerRepository::get( $id )['i18n'] ?? null, BannerRepository::get( $copy['id'] )['i18n'] ?? null );
		$this->request(
			'POST',
			'/barrio/v1/banners/bulk',
			[
				'action' => 'enable',
				'ids'    => [ $id, $copy['id'] ],
			]
		);
		$this->assertSame( [ true, true ], array_column( BannerRepository::all(), 'is_enabled' ) );
		$this->assertSame( 200, $this->request( 'DELETE', "/barrio/v1/banners/{$copy['id']}" )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', "/barrio/v1/banners/{$copy['id']}" )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 200, $this->request( 'GET', '/barrio/v1/banners' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/barrio/v1/banners/settings' )->get_status(), 'Only administrators change the permissions.' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/barrio/v1/banners' )->get_status() );
		$this->assertArrayNotHasKey( 'banners', apply_filters( 'barrio_admin_data', [] ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->request( 'PUT', '/barrio/v1/banners/settings', [ 'roles' => [ 'editor' => [] ] ] );
		$this->assertFalse( get_role( 'editor' )->has_cap( Module::CAP ) );
	}

	public function test_a_banner_in_two_languages_shows_up_only_when_it_must(): void {
		$id   = $this->create();
		$page = get_permalink( self::factory()->post->create( [ 'post_type' => 'page' ] ) );

		$this->assertStringContainsString( '<div class="barrio-banner__body" tabindex="0"><span class="barrio-banner__track"><span class="barrio-banner__text">Saldi</span>', $this->page( $page ), 'Without a link it still takes the focus, which stops the text.' );
		$this->assertTrue( wp_style_is( 'barrio-banners' ) );
		$this->lang = 'en';
		$this->assertStringContainsString( '<a class="barrio-banner__body" href="/en/sale/"><span class="barrio-banner__track"><span class="barrio-banner__text">Sale</span>', $this->page( home_url( '/' ) ) );
		$this->lang = 'de';
		$this->assertStringContainsString( '>Saldi<', $this->page( $page ), 'A language without texts: the main one.' );

		$hour = static fn( int $hours ): string => gmdate( 'Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS );
		BannerRepository::save( $id, $this->banner( [ 'starts_at' => $hour( 1 ) ] ) );
		wp_dequeue_style( 'barrio-banners' );
		$this->assertSame( '', $this->page( $page ), 'Not started yet.' );
		$this->assertFalse( wp_style_is( 'barrio-banners' ), 'No asset without a banner.' );
		BannerRepository::save( $id, $this->banner( [ 'ends_at' => $hour( -1 ) ] ) );
		$this->assertSame( '', $this->page( $page ), 'Ended.' );
		BannerRepository::save(
			$id,
			$this->banner(
				[
					'starts_at' => $hour( -1 ),
					'ends_at'   => $hour( 1 ),
				]
			)
		);
		$this->assertMatchesRegularExpression( '/<section id="barrio-banner" class="barrio-banner" data-start="\d+" data-end="\d+"/', $this->page( $page ), 'The period for the script, with page caching.' );
		BannerRepository::set_enabled( false, $id );
		$this->assertSame( '', $this->page( $page ), 'Off.' );
	}

	public function test_the_most_recent_banner_on_wins(): void {
		$always = $this->create();
		$this->assertStringContainsString( '>Saldi<', $this->page( home_url( '/' ) ) );

		$promo = $this->create( [ 'i18n' => [ 'it' => $this->text( 'Black Friday' ) ] ] );
		$html  = $this->page( home_url( '/' ) );
		$this->assertStringContainsString( '>Black Friday<', $html );
		$this->assertStringNotContainsString( '>Saldi<', $html, 'One banner per page.' );

		BannerRepository::save( $promo, $this->banner( [ 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ] ) );
		$this->assertStringContainsString( '>Saldi<', $this->page( home_url( '/' ) ), 'A newer one not started yet does not count.' );
		$this->assertSame( $always, BannerRepository::shown()['id'] ?? null );
	}

	public function test_the_site_reads_the_banners_without_queries(): void {
		global $wpdb;
		$this->assertSame( [], BannerRepository::live() );
		$queries = $wpdb->num_queries;
		$this->assertSame( [], BannerRepository::live() );
		$this->assertSame( $queries, $wpdb->num_queries, 'Zero banners: an empty array, no query.' );

		$changed = did_action( 'barrio_banners_changed' );
		$id      = $this->create();
		$this->create( [ 'ends_at' => '2000-01-01 00:00:00' ] );
		$this->assertSame( $changed + 2, did_action( 'barrio_banners_changed' ) );
		$this->assertFalse( get_transient( BannerRepository::LIVE ), 'Every write empties it.' );
		$this->assertSame( [ $id ], array_column( BannerRepository::live(), 'id' ), 'Only those on and not expired.' );
		$queries = $wpdb->num_queries;
		BannerRepository::shown();
		$this->assertSame( $queries, $wpdb->num_queries );
	}

	public function test_the_text_is_read_once_and_every_value_is_escaped(): void {
		$this->create(
			[
				'new_tab' => true,
				'i18n'    => [ 'it' => $this->text( 'Saldi <b>estivi</b>', 'https://example.org/saldi?a=1&b=2' ) ],
			]
		);
		$html = $this->page( home_url( '/' ) );

		$this->assertStringContainsString( '<section id="barrio-banner" class="barrio-banner" aria-label="Announcement">', $html );
		$this->assertStringContainsString( 'href="https://example.org/saldi?a=1&#038;b=2" target="_blank" rel="noopener"', $html );
		$this->assertSame( 1, substr_count( $html, 'Saldi estivi' ), 'The copies of the scrolling text are added by the script, hidden from screen readers.' );
	}

	/**
	 * Un banner valido, con le date come le scrive la REST (o in UTC per BannerRepository).
	 *
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>
	 */
	private function banner( array $changes = [] ): array {
		return array_replace(
			[
				'is_enabled' => true,
				'new_tab'    => false,
				'starts_at'  => null,
				'ends_at'    => null,
				'i18n'       => [
					'it' => $this->text( 'Saldi' ),
					'en' => $this->text( 'Sale', '/en/sale/' ),
				],
			],
			$changes
		);
	}

	/** @return array{text: string, link_url: string|null} */
	private function text( string $text, ?string $link = null ): array {
		return [
			'text'     => $text,
			'link_url' => $link,
		];
	}

	/** @param array<string, mixed> $changes */
	private function create( array $changes = [] ): int {
		$id = BannerRepository::save( null, $this->banner( $changes ) );
		$this->assertIsInt( $id );
		return $id;
	}

	// Ciò che il sito stampa in cima alla pagina.
	private function page( string $url ): string {
		$this->go_to( $url );
		Module::enqueue();
		ob_start();
		Module::render();
		return (string) ob_get_clean();
	}

	/** @param array<string, mixed> $body */
	private function request( string $method, string $path, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_do_request( $request );
	}
}
