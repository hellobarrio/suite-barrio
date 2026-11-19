<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Popups\Module;
use BarrioSuite\Popups\PopupRepository;
use BarrioSuite\Popups\Rules;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Il modulo Popup e le regole del §1: dove, a chi e quando si apre un popup, anche in due lingue; il più recente per
// dispositivo; la riga della lingua principale; il transient che tiene il sito senza query; REST, anteprima e permessi.
final class PopupsTest extends WP_UnitTestCase {

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
		wp_dequeue_style( 'barrio-popups' );
		parent::tear_down();
		wp_roles()->for_site();
	}

	public function test_the_main_language_row_is_always_there(): void {
		$english = $this->popup( [], [ 'i18n' => [ 'en' => $this->text( 'Newsletter' ) ] ] );
		$error   = PopupRepository::save( null, $english );
		$this->assertWPError( $error );
		$this->assertSame( 'barrio_popup_main_language', $error->get_error_code() );
		$this->assertSame( 400, $this->request( 'POST', '/barrio/v1/popups', $english )->get_status() );
		$this->assertSame( [], PopupRepository::all() );

		$id = $this->create();
		$this->assertEqualsCanonicalizing( [ 'it', 'en' ], array_keys( PopupRepository::get( $id )['i18n'] ?? [] ) );
	}

	public function test_the_schema_refuses_invalid_settings_texts_and_dates(): void {
		$invalid = [
			'position'           => $this->popup( [ 'position' => 'top' ] ),
			'color'              => $this->popup( [ 'colors' => [ 'background' => 'red' ] ] ),
			'trigger'            => $this->popup( [ 'trigger' => [ 'type' => 'click' ] ] ),
			'scroll over 100'    => $this->popup( [ 'trigger' => [ 'scroll' => 101 ] ] ),
			'delay negative'     => $this->popup( [ 'trigger' => [ 'delay' => -1 ] ] ),
			'where mode'         => $this->popup( [ 'where' => [ 'mode' => 'some' ] ] ),
			'post id 0'          => $this->popup( [ 'where' => [ 'posts' => [ 0 ] ] ] ),
			'device'             => $this->popup( [ 'who' => [ 'device' => 'tv' ] ] ),
			'days over a year'   => $this->popup( [ 'days' => 366 ] ),
			'unknown setting'    => $this->popup( [ 'sound' => true ] ),
			'image 0'            => $this->popup( [], [ 'image_id' => 0 ] ),
			'javascript link'    => $this->popup( [], [ 'i18n' => [ 'it' => $this->text( 'Newsletter', 'javascript:alert(1)' ) ] ] ),
			'button without one' => $this->popup( [], [ 'i18n' => [ 'it' => $this->text( 'Newsletter', null, 'Iscriviti' ) ] ] ),
			'title only tags'    => $this->popup( [], [ 'i18n' => [ 'it' => $this->text( '<b></b>' ) ] ] ),
			'lang uppercase'     => $this->popup( [], [ 'i18n' => [ 'IT' => $this->text( 'Newsletter' ) ] ] ),
			'february 30'        => $this->popup( [], [ 'starts_at' => '2030-02-30T10:00' ] ),
			'ends before start'  => $this->popup(
				[],
				[
					'starts_at' => '2030-06-02T10:00',
					'ends_at'   => '2030-06-01T10:00',
				]
			),
		];
		foreach ( $invalid as $name => $body ) {
			$this->assertSame( 400, $this->request( 'POST', '/barrio/v1/popups', $body )->get_status(), $name );
		}
		$this->assertSame( [], PopupRepository::all(), 'Nothing was written.' );
	}

	public function test_texts_are_filtered_and_empty_ones_are_null(): void {
		$id   = $this->create(
			[],
			[
				'i18n' => [
					'it' => [
						'title'        => 'Iscriviti <script>alert(1)</script>',
						'content'      => '<p>Le <strong>novità</strong></p><script>alert(1)</script>',
						'button_label' => '',
						'link_url'     => ' /news/ ',
					],
				],
			]
		);
		$text = PopupRepository::get( $id )['i18n']['it'] ?? [];

		$this->assertSame( 'Iscriviti', $text['title'] );
		$this->assertSame( '<p>Le <strong>novità</strong></p>alert(1)', $text['content'] );
		$this->assertNull( $text['button_label'], 'Empty: the standard text.' );
		$this->assertSame( '/news/', $text['link_url'] );

		PopupRepository::save( $id, $this->popup( [], [ 'i18n' => [ 'it' => [ 'content' => '<p>&nbsp;</p>' ] + $this->text( 'Newsletter' ) ] ] ) );
		$this->assertArrayHasKey( 'it', PopupRepository::get( $id )['i18n'] ?? [] );
		$this->assertNull( PopupRepository::get( $id )['i18n']['it']['content'], 'An empty paragraph of the editor is no text.' );
	}

	public function test_dates_are_in_the_site_time_zone_and_the_status_follows_the_period(): void {
		$response = $this->request(
			'POST',
			'/barrio/v1/popups',
			$this->popup(
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
		$this->assertSame( '2099-06-01 08:00:00', PopupRepository::get( $data['id'] )['starts_at'] ?? null, 'UTC in the database.' );
	}

	public function test_popups_are_created_changed_duplicated_and_deleted_by_those_who_manage_them(): void {
		$page = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Chi siamo',
			]
		);
		$id   = $this->request( 'POST', '/barrio/v1/popups', $this->popup() )->get_data()['id'];
		$data = $this->request(
			'PUT',
			"/barrio/v1/popups/{$id}",
			$this->popup(
				[
					'where' => [
						'mode'  => 'only',
						'posts' => [ $page ],
					],
				]
			)
		)->get_data();
		$this->assertEquals( (object) [ $page => 'Chi siamo' ], $data['labels']['posts'], 'The names of the chosen pages, for the admin.' );
		$this->assertSame( 404, $this->request( 'PUT', '/barrio/v1/popups/999999', $this->popup() )->get_status() );

		$copy = $this->request( 'POST', "/barrio/v1/popups/{$id}/duplicate" )->get_data();
		$this->assertFalse( $copy['is_enabled'], 'The copy is off.' );
		$this->assertEquals( PopupRepository::get( $id )['settings'] ?? null, PopupRepository::get( $copy['id'] )['settings'] ?? null );
		$this->request(
			'POST',
			'/barrio/v1/popups/bulk',
			[
				'action' => 'enable',
				'ids'    => [ $id, $copy['id'] ],
			]
		);
		$this->assertSame( [ true, true ], array_column( PopupRepository::all(), 'is_enabled' ) );
		$this->assertSame( 200, $this->request( 'DELETE', "/barrio/v1/popups/{$copy['id']}" )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', "/barrio/v1/popups/{$copy['id']}" )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 200, $this->request( 'GET', '/barrio/v1/popups' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/barrio/v1/popups/settings' )->get_status(), 'Only administrators change the permissions.' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/barrio/v1/popups' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/barrio/v1/popups/preview', [] )->get_status() );
		$this->assertArrayNotHasKey( 'popups', apply_filters( 'barrio_admin_data', [] ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->request( 'PUT', '/barrio/v1/popups/settings', [ 'roles' => [ 'editor' => [] ] ] );
		$this->assertFalse( get_role( 'editor' )->has_cap( Module::CAP ) );
	}

	public function test_a_popup_in_two_languages_opens_only_where_and_when_it_must(): void {
		$page  = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$other = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$where = [
			'where' => [
				'mode'  => 'only',
				'posts' => [ $page ],
			],
		];
		$id    = $this->create( $where );

		$this->assertStringContainsString( '<h2 id="barrio-popup-' . $id . '-title" class="barrio-popup__title">Newsletter</h2>', $this->page( get_permalink( $page ) ) );
		$this->assertTrue( wp_style_is( 'barrio-popups' ) );
		$this->lang = 'en';
		$this->assertStringContainsString( '>Subscribe<', $this->page( get_permalink( $page ) ) );
		$this->lang = 'de';
		$this->assertStringContainsString( '>Newsletter<', $this->page( get_permalink( $page ) ), 'A language without texts: the main one.' );

		wp_dequeue_style( 'barrio-popups' );
		$this->assertSame( '', $this->page( get_permalink( $other ) ) );
		$this->assertFalse( wp_style_is( 'barrio-popups' ), 'No asset without a popup.' );

		// La traduzione di una pagina scelta vale come la pagina (con WPML lo dice barrio-suite-wpml).
		$translation = self::factory()->post->create( [ 'post_type' => 'page' ] );
		add_filter( 'barrio_main_language_id', static fn( int $post ): int => $translation === $post ? $page : $post );
		$this->assertNotSame( '', $this->page( get_permalink( $translation ) ) );

		$hour = static fn( int $hours ): string => gmdate( 'Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS );
		PopupRepository::save( $id, $this->popup( $where, [ 'starts_at' => $hour( 1 ) ] ) );
		$this->assertSame( '', $this->page( get_permalink( $page ) ), 'Not started yet.' );
		PopupRepository::save( $id, $this->popup( $where, [ 'ends_at' => $hour( -1 ) ] ) );
		$this->assertSame( '', $this->page( get_permalink( $page ) ), 'Ended.' );
		PopupRepository::save(
			$id,
			$this->popup(
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
		PopupRepository::set_enabled( false, $id );
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
		PopupRepository::save( $id, $this->popup( [ 'where' => [ 'mode' => 'except' ] + $where ] ) );
		$this->assertSame( [ false, false, false, false, true ], array_values( $shown() ) );
	}

	public function test_the_most_recent_that_applies_wins_on_each_device(): void {
		$always = $this->create();
		$phones = $this->create( [ 'who' => [ 'device' => 'mobile' ] ] );
		$picked = function (): array {
			$this->go_to( home_url( '/' ) );
			return array_map( static fn( array $pick ): array => [ $pick[0]['id'], $pick[1] ], Rules::pick( PopupRepository::live() ) );
		};
		$this->assertEqualsCanonicalizing( [ [ $phones, 'mobile' ], [ $always, 'desktop' ] ], $picked(), 'An older one for all devices stays on the desktop.' );
		$html = $this->page( home_url( '/' ) );
		$this->assertStringContainsString( 'data-device="mobile"', $html );
		$this->assertStringContainsString( 'data-device="desktop"', $html );

		$newest = $this->create();
		$this->assertSame( [ [ $newest, 'all' ] ], $picked(), 'One popup per page.' );
		PopupRepository::save( $newest, $this->popup( [ 'where' => [ 'mode' => 'except' ] + [ 'home' => true ] ] ) );
		$this->assertEqualsCanonicalizing( [ [ $phones, 'mobile' ], [ $always, 'desktop' ] ], $picked(), 'One that is not for this page does not count.' );
	}

	public function test_the_site_reads_the_popups_without_queries(): void {
		global $wpdb;
		$this->assertSame( [], PopupRepository::live() );
		$queries = $wpdb->num_queries;
		$this->assertSame( [], PopupRepository::live() );
		$this->assertSame( $queries, $wpdb->num_queries, 'Zero popups: an empty array, no query.' );

		$changed = did_action( 'barrio_popups_changed' );
		$id      = $this->create();
		$this->create( [], [ 'ends_at' => '2000-01-01 00:00:00' ] );
		$this->assertSame( $changed + 2, did_action( 'barrio_popups_changed' ) );
		$this->assertFalse( get_transient( PopupRepository::LIVE ), 'Every write empties it.' );
		$this->assertSame( [ $id ], array_column( PopupRepository::live(), 'id' ), 'Only those on and not expired.' );
		$queries = $wpdb->num_queries;
		PopupRepository::live();
		$this->assertSame( $queries, $wpdb->num_queries );
	}

	public function test_the_popup_is_a_closed_dialog_with_its_rules_and_every_value_escaped(): void {
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$id    = $this->create(
			[
				'position' => 'corner',
				'new_tab'  => true,
				'colors'   => [
					'background' => '#112233',
					'text'       => '#ffffff',
				],
				'trigger'  => [
					'type'   => 'scroll',
					'scroll' => 40,
				],
				'days'     => 3,
			],
			[
				'image_id' => $image,
				'i18n'     => [ 'it' => $this->text( 'Saldi "estivi"', 'https://example.org/saldi?a=1&b=2' ) ],
			]
		);
		$html  = $this->page( home_url( '/' ) );

		$this->assertStringContainsString( "<dialog id=\"barrio-popup-{$id}\" class=\"barrio-popup is-corner\" data-device=\"all\"", $html );
		$this->assertStringNotContainsString( ' open', substr( $html, 0, (int) strpos( $html, '>' ) ), 'Closed until the script opens it.' );
		$this->assertStringContainsString( "aria-labelledby=\"barrio-popup-{$id}-title\" style=\"--barrio-popup-background:#112233;--barrio-popup-color:#ffffff\"", $html );
		$this->assertStringContainsString( 'class="barrio-popup__image', $html );
		$this->assertStringContainsString( '>Saldi &quot;estivi&quot;</h2>', $html );
		$this->assertStringContainsString( '<a class="barrio-popup__button" href="https://example.org/saldi?a=1&#038;b=2" target="_blank" rel="noopener">Learn more</a>', $html, 'Without a text, the standard one.' );
		preg_match( '/data-barrio-popup-rules="([^"]+)"/', $html, $match );
		$this->assertSame(
			[
				'key'     => "barrio_popups_{$id}",
				'start'   => null,
				'end'     => null,
				'days'    => 3,
				'trigger' => [
					'type'   => 'scroll',
					'delay'  => 2,
					'scroll' => 40,
				],
			],
			json_decode( html_entity_decode( $match[1] ?? '' ), true )
		);

		wp_delete_attachment( $image, true );
		delete_transient( PopupRepository::LIVE );
		$this->assertStringNotContainsString( 'barrio-popup__image', $this->page( home_url( '/' ) ), 'A deleted image is ignored.' );
	}

	public function test_a_form_in_the_text_loads_its_assets_and_disappears_with_forms_off(): void {
		$form = FormRepository::create( 'Newsletter', [], [], new FormVersion( [ new Field( 'email', 'email', [ 'it' => [ 'label' => 'Email' ] ], true ) ] ) );
		$this->create( [], [ 'i18n' => [ 'it' => [ 'content' => "<p>Iscriviti</p>[barrio_form id=\"{$form->id}\"]" ] + $this->text( 'Newsletter' ) ] ] );

		$html = $this->page( home_url( '/' ) );
		$this->assertStringContainsString( 'data-barrio-form', $html );
		$this->assertTrue( wp_style_is( 'barrio-forms' ), 'Enqueued with the popup, before the head is printed.' );

		$shortcode = $GLOBALS['shortcode_tags']['barrio_form'];
		remove_shortcode( 'barrio_form' );
		$html = $this->page( home_url( '/' ) );
		add_shortcode( 'barrio_form', $shortcode );
		$this->assertStringContainsString( '<div class="barrio-popup__content"><p>Iscriviti</p></div>', $html, 'Without the Forms module the shortcode prints nothing.' );
	}

	public function test_the_preview_is_the_open_popup_even_unsaved(): void {
		$form     = FormRepository::create( 'Newsletter', [], [], new FormVersion( [ new Field( 'email', 'email', [ 'it' => [ 'label' => 'Email' ] ], true ) ] ) );
		$settings = $this->popup()['settings'];
		$response = $this->request(
			'POST',
			'/barrio/v1/popups/preview',
			[
				'lang'     => 'en',
				'image_id' => null,
				'settings' => $settings,
				'text'     => [
					'title'   => '',
					'content' => "[barrio_form id=\"{$form->id}\"]",
				],
			]
		);
		$this->assertSame( 200, $response->get_status() );
		$html = $response->get_data()['html'];

		$this->assertStringContainsString( 'class="barrio-popup is-center" data-device="all"', $html );
		$this->assertStringContainsString( ' open>', $html );
		$this->assertStringContainsString( '<div class="barrio-preview-backdrop"></div>', $html );
		$this->assertStringContainsString( '>The title of the popup</h2>', $html );
		$this->assertStringContainsString( 'data-barrio-form data-barrio-preview', $html, 'The form does not send.' );
		$this->assertStringContainsString( 'frontend/form.js', $html );
		$this->assertSame( [], PopupRepository::all(), 'Nothing saved.' );

		$settings['position'] = 'top';
		$this->assertSame( 400, $this->request( 'POST', '/barrio/v1/popups/preview', [ 'settings' => $settings ] )->get_status() );
	}

	/**
	 * Un popup valido, con le date come le scrive la REST (o in UTC per PopupRepository).
	 *
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $changes
	 * @return array<string, mixed>
	 */
	private function popup( array $settings = [], array $changes = [] ): array {
		return array_replace(
			[
				'is_enabled' => true,
				'starts_at'  => null,
				'ends_at'    => null,
				'image_id'   => null,
				'settings'   => array_replace_recursive(
					[
						'position' => 'center',
						'colors'   => [
							'background' => '',
							'text'       => '',
						],
						'trigger'  => [
							'type'   => 'delay',
							'delay'  => 2,
							'scroll' => 50,
						],
						'new_tab'  => false,
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
					'it' => $this->text( 'Newsletter' ),
					'en' => $this->text( 'Subscribe', '/en/news/', 'Go' ),
				],
			],
			$changes
		);
	}

	/** @return array{title: string, content: string|null, button_label: string|null, link_url: string|null} */
	private function text( string $title, ?string $link = null, ?string $button = null ): array {
		return [
			'title'        => $title,
			'content'      => null,
			'button_label' => $button,
			'link_url'     => $link,
		];
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $changes
	 */
	private function create( array $settings = [], array $changes = [] ): int {
		$id = PopupRepository::save( null, $this->popup( $settings, $changes ) );
		$this->assertIsInt( $id );
		return $id;
	}

	// Ciò che il sito stampa in fondo alla pagina.
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
