<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Popups\Module;
use BarrioSuite\Popups\PopupRepository;
use ReflectionProperty;
use WP_REST_Request;
use WP_REST_Response;
use WP_Script_Modules;
use WP_UnitTestCase;

// Il modulo Popup: quale popup si apre e quando, anche in due lingue; la riga della lingua principale; il transient che
// tiene il sito senza query; REST, anteprima e permessi.
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
			'days negative'      => $this->popup( [ 'days' => -1 ] ),
			'days over a year'   => $this->popup( [ 'days' => 366 ] ),
			'unknown setting'    => $this->popup( [ 'where' => [ 'mode' => 'all' ] ] ),
			'opening removed'    => $this->popup( [ 'trigger' => [ 'type' => 'delay' ] ] ),
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
		$anchor = $this->request( 'POST', '/barrio/v1/popups', $this->popup( [], [ 'i18n' => [ 'it' => $this->text( 'Newsletter', '#contatti' ) ] ] ) );
		$this->assertSame( 'The link must be a path like /contacts/ or an address that starts with http:// or https://.', $anchor->as_error()->get_error_message(), 'In words, not the regular expression of the schema.' );
		$this->assertSame( [], PopupRepository::all(), 'Nothing was written.' );
	}

	public function test_a_setting_saved_before_it_was_removed_is_dropped(): void {
		global $wpdb;
		$id = $this->create();
		$wpdb->update( Db::table( 'popups' ), [ 'settings' => '{"trigger": {"type": "scroll", "delay": 2, "scroll": 40}, "new_tab": false, "days": 7}' ], [ 'id' => $id ] );

		$popup = PopupRepository::get( $id );
		// assertEquals: MySQL 8.0 riordina le chiavi delle colonne JSON.
		$this->assertEquals(
			[
				'new_tab' => false,
				'days'    => 7,
			],
			$popup['settings'] ?? null
		);
		$this->assertSame( $id, PopupRepository::save( $id, $popup ), 'The editor saves it again.' );
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
		$id = $this->request( 'POST', '/barrio/v1/popups', $this->popup() )->get_data()['id'];
		$this->assertSame( 200, $this->request( 'PUT', "/barrio/v1/popups/{$id}", $this->popup( [ 'new_tab' => true ] ) )->get_status() );
		$this->assertTrue( PopupRepository::get( $id )['settings']['new_tab'] ?? null );
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

	public function test_a_popup_in_two_languages_opens_only_when_it_must(): void {
		$id   = $this->create();
		$page = get_permalink( self::factory()->post->create( [ 'post_type' => 'page' ] ) );

		$this->assertStringContainsString( '<h2 id="barrio-popup-' . $id . '-title" class="barrio-popup__title">Newsletter</h2>', $this->page( $page ) );
		$this->assertTrue( wp_style_is( 'barrio-popups' ) );
		$this->lang = 'en';
		$this->assertStringContainsString( '>Subscribe<', $this->page( home_url( '/' ) ) );
		$this->lang = 'de';
		$this->assertStringContainsString( '>Newsletter<', $this->page( $page ), 'A language without texts: the main one.' );

		$hour = static fn( int $hours ): string => gmdate( 'Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS );
		PopupRepository::save( $id, $this->popup( [], [ 'starts_at' => $hour( 1 ) ] ) );
		wp_dequeue_style( 'barrio-popups' );
		$this->assertSame( '', $this->page( $page ), 'Not started yet.' );
		$this->assertFalse( wp_style_is( 'barrio-popups' ), 'No asset without a popup.' );
		PopupRepository::save( $id, $this->popup( [], [ 'ends_at' => $hour( -1 ) ] ) );
		$this->assertSame( '', $this->page( $page ), 'Ended.' );
		PopupRepository::save(
			$id,
			$this->popup(
				[],
				[
					'starts_at' => $hour( -1 ),
					'ends_at'   => $hour( 1 ),
				]
			)
		);
		$this->assertMatchesRegularExpression( '/data-barrio-popup-rules="[^"]*&quot;end&quot;:\d+/', $this->page( $page ), 'The end for the script, with page caching: what is printed has already started.' );
		PopupRepository::set_enabled( false, $id );
		$this->assertSame( '', $this->page( $page ), 'Off.' );
	}

	public function test_the_most_recent_popup_on_wins(): void {
		$always = $this->create();
		$this->assertStringContainsString( '>Newsletter<', $this->page( home_url( '/' ) ) );

		$promo = $this->create( [], [ 'i18n' => [ 'it' => $this->text( 'Black Friday' ) ] ] );
		$html  = $this->page( home_url( '/' ) );
		$this->assertStringContainsString( '>Black Friday<', $html );
		$this->assertStringNotContainsString( '>Newsletter<', $html, 'One popup per page.' );

		PopupRepository::save( $promo, $this->popup( [], [ 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ] ) );
		$this->assertStringContainsString( '>Newsletter<', $this->page( home_url( '/' ) ), 'A newer one not started yet does not count.' );
		$this->assertSame( $always, PopupRepository::shown()['id'] ?? null );
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
		PopupRepository::shown();
		$this->assertSame( $queries, $wpdb->num_queries );
	}

	public function test_the_popup_is_a_closed_dialog_with_its_rules_and_every_value_escaped(): void {
		$image = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$id    = $this->create(
			[
				'new_tab' => true,
				'days'    => 3,
			],
			[
				'image_id' => $image,
				'i18n'     => [ 'it' => $this->text( 'Saldi "estivi"', 'https://example.org/saldi?a=1&b=2' ) ],
			]
		);
		$html  = $this->page( home_url( '/' ) );

		$this->assertStringContainsString( "<dialog id=\"barrio-popup-{$id}\" class=\"barrio-popup\" data-barrio-popup-rules=", $html );
		$this->assertStringContainsString( "aria-labelledby=\"barrio-popup-{$id}-title\"><div", $html, 'Closed until the script opens it.' );
		$this->assertStringContainsString( 'class="barrio-popup__image', $html );
		$this->assertStringContainsString( '>Saldi &quot;estivi&quot;</h2>', $html );
		$this->assertStringContainsString( '<a class="barrio-popup__button" href="https://example.org/saldi?a=1&#038;b=2" target="_blank" rel="noopener">Learn more</a>', $html, 'Without a text, the standard one.' );
		preg_match( '/data-barrio-popup-rules="([^"]+)"/', $html, $match );
		$this->assertSame(
			[
				'key'  => "barrio_popups_{$id}",
				'end'  => null,
				'days' => 3,
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
		// Come lo lascia Plugin::boot() senza il modulo Form.
		add_shortcode( 'barrio_form', '__return_empty_string' );
		$html = $this->page( home_url( '/' ) );
		add_shortcode( 'barrio_form', $shortcode );
		$this->assertStringContainsString( '<div class="barrio-popup__content"><p>Iscriviti</p></div>', $html, 'Without the Forms module the shortcode prints nothing.' );
	}

	public function test_the_preview_is_the_open_popup_even_unsaved(): void {
		// Ogni richiesta REST parte da zero; nei test gli asset li ha già stampati un test precedente.
		wp_styles()->done = [];
		( new ReflectionProperty( WP_Script_Modules::class, 'done' ) )->setValue( wp_script_modules(), [] );
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

		$this->assertStringContainsString( 'aria-labelledby="barrio-popup-0-title" open>', $html );
		$this->assertStringContainsString( '<div class="barrio-preview-backdrop"></div>', $html );
		$this->assertStringContainsString( '>The title of the popup</h2>', $html );
		$this->assertStringContainsString( 'data-barrio-form data-barrio-preview', $html, 'The form does not send.' );
		$this->assertStringContainsString( 'frontend/form.js', $html );
		$this->assertSame( [], PopupRepository::all(), 'Nothing saved.' );

		$settings['days'] = 366;
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
						'new_tab' => false,
						'days'    => 7,
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
