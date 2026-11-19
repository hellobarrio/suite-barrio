<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Banners\BannerRepository;
use BarrioSuite\Banners\Module;
use BarrioSuite\Visibility;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Il modulo Banner con le regole di Visibility: dove, a chi e quando compare un banner, anche in due lingue; la riga
// della lingua principale; il transient che tiene il sito senza query; REST e permessi.
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
		$english = $this->banner( [], [ 'i18n' => [ 'en' => $this->text( 'Sale' ) ] ] );
		$error   = BannerRepository::save( null, $english );
		$this->assertWPError( $error );
		$this->assertSame( 'barrio_banner_main_language', $error->get_error_code() );
		$this->assertSame( 400, $this->request( 'POST', '/barrio/v1/banners', $english )->get_status() );
		$this->assertSame( [], BannerRepository::all() );

		$id = $this->create();
		$this->assertEqualsCanonicalizing( [ 'it', 'en' ], array_keys( BannerRepository::get( $id )['i18n'] ?? [] ) );
	}

	public function test_the_schema_refuses_invalid_settings_texts_and_dates(): void {
		$invalid = [
			'position'          => $this->banner( [ 'position' => 'middle' ] ),
			'color name'        => $this->banner( [ 'colors' => [ 'background' => 'red' ] ] ),
			'negative days'     => $this->banner( [ 'days' => -1 ] ),
			'where mode'        => $this->banner( [ 'where' => [ 'mode' => 'some' ] ] ),
			'post 0'            => $this->banner( [ 'where' => [ 'posts' => [ 0 ] ] ] ),
			'device'            => $this->banner( [ 'who' => [ 'device' => 'tv' ] ] ),
			'unknown setting'   => $this->banner( [ 'sound' => true ] ),
			'javascript link'   => $this->banner( [], [ 'i18n' => [ 'it' => $this->text( 'Saldi', 'javascript:alert(1)' ) ] ] ),
			'only tags'         => $this->banner( [], [ 'i18n' => [ 'it' => $this->text( '<b></b>' ) ] ] ),
			'too long'          => $this->banner( [], [ 'i18n' => [ 'it' => $this->text( str_repeat( 'a', 256 ) ) ] ] ),
			'lang uppercase'    => $this->banner( [], [ 'i18n' => [ 'IT' => $this->text( 'Saldi' ) ] ] ),
			'february 30'       => $this->banner( [], [ 'starts_at' => '2030-02-30T10:00' ] ),
			'ends before start' => $this->banner(
				[],
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
				[],
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
		$this->assertSame( 'expired', Visibility::status( true, null, '2000-01-01 00:00:00' ) );
		$this->assertSame( 'active', Visibility::status( true, '2000-01-01 00:00:00', null ) );
		$this->assertSame( 'off', Visibility::status( false, null, null ) );
	}

	public function test_banners_are_created_changed_duplicated_and_deleted_by_those_who_manage_them(): void {
		$id = $this->request( 'POST', '/barrio/v1/banners', $this->banner() )->get_data()['id'];
		$this->assertSame( 200, $this->request( 'PUT', "/barrio/v1/banners/{$id}", $this->banner( [ 'position' => 'bottom' ] ) )->get_status() );
		$this->assertSame( 'bottom', BannerRepository::get( $id )['settings']['position'] ?? null );
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

	public function test_a_banner_in_two_languages_shows_up_only_where_and_when_it_must(): void {
		$page  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$other = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$where = [
			'where' => [
				'mode'  => 'only',
				'posts' => [ $page ],
			],
		];
		$id    = $this->create( $where );

		$this->assertStringContainsString( '<span class="barrio-banner__text">Saldi</span>', $this->page( get_permalink( $page ) ) );
		$this->assertTrue( wp_style_is( 'barrio-banners' ) );
		$this->lang = 'en';
		$this->assertStringContainsString( '<a class="barrio-banner__body" href="/en/sale/"><span class="barrio-banner__track"><span class="barrio-banner__text">Sale</span>', $this->page( get_permalink( $page ) ) );
		$this->lang = 'de';
		$this->assertStringContainsString( '>Saldi<', $this->page( get_permalink( $page ) ), 'A language without texts: the main one.' );

		wp_dequeue_style( 'barrio-banners' );
		$this->assertSame( '', $this->page( get_permalink( $other ) ) );
		$this->assertFalse( wp_style_is( 'barrio-banners' ), 'No asset without a banner.' );

		// La traduzione di una pagina scelta vale come la pagina (con WPML lo dice barrio-suite-wpml).
		$translation = self::factory()->post->create( [ 'post_type' => 'page' ] );
		add_filter( 'barrio_main_language_id', static fn( int $post ): int => $translation === $post ? $page : $post );
		$this->assertNotSame( '', $this->page( get_permalink( $translation ) ) );

		$hour = static fn( int $hours ): string => gmdate( 'Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS );
		BannerRepository::save( $id, $this->banner( $where, [ 'starts_at' => $hour( 1 ) ] ) );
		$this->assertSame( '', $this->page( get_permalink( $page ) ), 'Not started yet.' );
		BannerRepository::save( $id, $this->banner( $where, [ 'ends_at' => $hour( -1 ) ] ) );
		$this->assertSame( '', $this->page( get_permalink( $page ) ), 'Ended.' );
		BannerRepository::save(
			$id,
			$this->banner(
				$where + [ 'who' => [ 'users' => 'members' ] ],
				[
					'starts_at' => $hour( -1 ),
					'ends_at'   => $hour( 1 ),
				]
			)
		);
		$this->assertNotSame( '', $this->page( get_permalink( $page ) ) );
		wp_set_current_user( 0 );
		$this->assertSame( '', $this->page( get_permalink( $page ) ), 'Only for logged-in users.' );
		BannerRepository::set_enabled( false, $id );
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( '', $this->page( get_permalink( $page ) ), 'Off.' );
	}

	public function test_the_home_the_contents_of_a_type_and_a_term_with_its_archive(): void {
		$category = self::factory()->category->create();
		$post     = self::factory()->post->create( [ 'post_category' => [ $category ] ] );
		$plain    = self::factory()->post->create();
		$page     = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$where    = [
			'home'  => true,
			'types' => [ 'page' ],
			'terms' => [ $category ],
		];
		$id       = $this->create( [ 'where' => [ 'mode' => 'only' ] + $where ] );
		$urls     = [
			'home'                     => home_url( '/' ),
			'a page'                   => get_permalink( $page ),
			'a post with the category' => get_permalink( $post ),
			'the category archive'     => get_category_link( $category ),
			'a post without'           => get_permalink( $plain ),
		];
		$shown    = fn(): array => array_map( fn( string $url ): bool => '' !== $this->page( $url ), $urls );

		$this->assertSame( [ true, true, true, true, false ], array_values( $shown() ) );
		BannerRepository::save( $id, $this->banner( [ 'where' => [ 'mode' => 'except' ] + $where ] ) );
		$this->assertSame( [ false, false, false, false, true ], array_values( $shown() ) );
	}

	public function test_the_most_recent_that_applies_wins_on_each_device(): void {
		$always = $this->create();
		$phones = $this->create( [ 'who' => [ 'device' => 'mobile' ] ] );
		$picked = function (): array {
			$this->go_to( home_url( '/' ) );
			return array_map( static fn( array $pick ): array => [ $pick[0]['id'], $pick[1] ], Visibility::pick( BannerRepository::live() ) );
		};
		$this->assertEqualsCanonicalizing( [ [ $phones, 'mobile' ], [ $always, 'desktop' ] ], $picked(), 'An older one for all devices stays on the desktop.' );
		$html = $this->page( home_url( '/' ) );
		$this->assertStringContainsString( 'data-device="mobile"', $html );
		$this->assertStringContainsString( 'data-device="desktop"', $html );

		$newest = $this->create();
		$this->assertSame( [ [ $newest, 'all' ] ], $picked() );
		BannerRepository::save( $newest, $this->banner( [ 'where' => [ 'mode' => 'except' ] + [ 'home' => true ] ] ) );
		$this->assertEqualsCanonicalizing( [ [ $phones, 'mobile' ], [ $always, 'desktop' ] ], $picked(), 'One that is not for this page does not count.' );
	}

	public function test_the_site_reads_the_banners_without_queries(): void {
		global $wpdb;
		$this->assertSame( [], BannerRepository::live() );
		$queries = $wpdb->num_queries;
		$this->assertSame( [], BannerRepository::live() );
		$this->assertSame( $queries, $wpdb->num_queries, 'Zero banners: an empty array, no query.' );

		$changed = did_action( 'barrio_banners_changed' );
		$id      = $this->create();
		$this->create( [], [ 'ends_at' => '2000-01-01 00:00:00' ] );
		$this->assertSame( $changed + 2, did_action( 'barrio_banners_changed' ) );
		$this->assertFalse( get_transient( BannerRepository::LIVE ), 'Every write empties it.' );
		$this->assertSame( [ $id ], array_column( BannerRepository::live(), 'id' ), 'Only those on and not expired.' );
		$queries = $wpdb->num_queries;
		BannerRepository::live();
		$this->assertSame( $queries, $wpdb->num_queries );
	}

	public function test_the_text_is_read_once_and_every_value_is_escaped(): void {
		$this->create(
			[
				'scroll'  => true,
				'new_tab' => true,
				'colors'  => [
					'background' => '#112233',
					'text'       => '#ffffff',
				],
				'days'    => 3,
			],
			[ 'i18n' => [ 'it' => $this->text( 'Saldi <b>estivi</b>', 'https://example.org/saldi?a=1&b=2' ) ] ]
		);
		$html = $this->page( home_url( '/' ) );

		$this->assertStringContainsString( 'class="barrio-banner is-top is-scroll"', $html );
		$this->assertStringContainsString( 'style="--barrio-banner-background:#112233;--barrio-banner-color:#ffffff"', $html );
		$this->assertStringContainsString( 'href="https://example.org/saldi?a=1&#038;b=2" target="_blank" rel="noopener"', $html );
		$this->assertStringContainsString( '<button type="button" class="barrio-banner__close" aria-label="Close">', $html );
		$this->assertSame( 1, substr_count( $html, 'Saldi estivi' ), 'The copies of the scrolling text are added by the script, hidden from screen readers.' );
		$this->assertMatchesRegularExpression( '/data-barrio-visibility="([^"]+)"/', $html );
		preg_match( '/data-barrio-visibility="([^"]+)"/', $html, $match );
		$this->assertSame( 3, json_decode( html_entity_decode( $match[1] ), true )['days'] );
	}

	/**
	 * Un banner valido, con le date come le scrive la REST (o in UTC per BannerRepository).
	 *
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>
	 */
	private function banner( array $settings = [], array $changes = [] ): array {
		return array_replace(
			[
				'is_enabled' => true,
				'starts_at'  => null,
				'ends_at'    => null,
				'settings'   => array_replace_recursive(
					[
						'position' => 'top',
						'scroll'   => false,
						'closable' => true,
						'new_tab'  => false,
						'colors'   => [
							'background' => '',
							'text'       => '',
						],
						'where'    => [
							'mode'  => 'all',
							'home'  => false,
							'posts' => [],
							'types' => [],
							'terms' => [],
						],
						'who'      => [
							'device' => 'all',
							'users'  => 'all',
						],
						'days'     => 7,
					],
					$settings
				),
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

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $changes
	 */
	private function create( array $settings = [], array $changes = [] ): int {
		$id = BannerRepository::save( null, $this->banner( $settings, $changes ) );
		$this->assertIsInt( $id );
		return $id;
	}

	// Ciò che il sito stampa in una pagina, in cima e in fondo.
	private function page( string $url ): string {
		$this->go_to( $url );
		Module::enqueue();
		ob_start();
		Module::render( 'top' );
		Module::render( 'bottom' );
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
