<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Banners\BannerRepository;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\FormStatus;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Popups\PopupRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// La revalidation di Next.js in barrio-suite-wpgraphql, con un frontend simulato da
// pre_http_request. Gira solo con WPGraphQL, come WpGraphqlTest: i contenuti del frontend sono quelli show_in_graphql.
final class RevalidationTest extends WP_UnitTestCase {

	/** @var list<array{url: string, headers: array<string, string>}> */
	private array $requests = [];

	// Il codice HTTP della risposta del frontend; 0 se non risponde.
	private int $code = 200;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WPGraphQL' ) ) {
			$this->markTestSkipped( 'Needs WPGraphQL (WP_GRAPHQL=1).' );
		}
		$this->set_permalink_structure( '/%postname%/' );
		update_option(
			'barrio_frontend',
			[
				'url'   => 'https://www.pizzeria.it',
				'token' => 's3greto',
			]
		);
		$GLOBALS['barrio_wpgraphql_tags'] = [];
		add_filter( 'barrio_languages', static fn(): array => [ 'it', 'en' ] );
		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array|WP_Error {
				$this->requests[] = [
					'url'     => $url,
					'headers' => $args['headers'],
				];
				if ( 0 === $this->code ) {
					return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
				}
				return [
					'headers'  => [],
					'body'     => '{"revalidated":true}',
					'response' => [
						'code'    => $this->code,
						'message' => '',
					],
					'cookies'  => [],
					'filename' => null,
				];
			},
			10,
			3
		);
	}

	public function test_a_content_sends_the_tags_of_the_prototype_once_with_the_token(): void {
		$parent = self::factory()->post->create(
			[
				'post_type' => 'page',
				'post_name' => 'servizi',
			]
		);
		$this->sent();
		$page = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'web-design',
				'post_parent' => $parent,
			]
		);
		wp_update_post(
			[
				'ID'         => $page,
				'post_title' => 'Web design',
			]
		);

		$this->assertSame( [ 'wp:uri:servizi_web-design', 'wp:type:page', "page_{$page}" ], $this->sent() );
		$this->assertSame( "https://www.pizzeria.it/api/revalidate/?tag=wp%3Auri%3Aservizi_web-design%2Cwp%3Atype%3Apage%2Cpage_{$page}", $this->requests[0]['url'] );
		$this->assertSame( [ 'Authorization' => 'Bearer s3greto' ], $this->requests[0]['headers'] );

		// La home, come la cerca il frontend (getUriCacheTag( "/" )).
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $parent );
		wp_update_post(
			[
				'ID'         => $parent,
				'post_title' => 'Home',
			]
		);
		$this->assertSame( [ 'wp:uri:', 'wp:type:page', "page_{$parent}" ], $this->sent() );

		// Con WordPress in una sottocartella l'URI di WPGraphQL non ha il percorso della home.
		update_option( 'home', 'http://example.org/cms' );
		wp_update_post(
			[
				'ID'         => $page,
				'post_title' => 'Web',
			]
		);
		$this->assertSame( [ 'wp:uri:servizi_web-design', 'wp:type:page', "page_{$page}" ], $this->sent() );
	}

	public function test_a_new_slug_the_bin_and_a_deletion_send_the_old_address(): void {
		$post = self::factory()->post->create( [ 'post_name' => 'pizza-margherita' ] );
		$this->sent();

		wp_update_post(
			[
				'ID'        => $post,
				'post_name' => 'margherita',
			]
		);
		// Il redirect automatico dal vecchio indirizzo, del SEO.
		$this->assertSame( [ 'wp:redirects', 'wp:uri:pizza-margherita', 'wp:uri:margherita', 'wp:type:post', "page_{$post}" ], $this->sent() );

		wp_trash_post( $post );
		$this->assertSame( [ 'wp:uri:margherita', 'wp:type:post', "page_{$post}" ], $this->sent() );

		$other = self::factory()->post->create( [ 'post_name' => 'diavola' ] );
		$this->sent();
		wp_delete_post( $other, true );
		$this->assertSame( [ 'wp:uri:diavola', 'wp:type:post', "page_{$other}" ], $this->sent() );

		$third = self::factory()->post->create( [ 'post_name' => 'capricciosa' ] );
		$this->sent();
		wp_update_post(
			[
				'ID'          => $third,
				'post_status' => 'draft',
			]
		);
		$this->assertSame( [ 'wp:uri:capricciosa', 'wp:type:post', "page_{$third}" ], $this->sent() );
	}

	public function test_drafts_revisions_autosaves_and_images_send_nothing(): void {
		$post = self::factory()->post->create();
		$this->sent();

		$draft = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		wp_update_post(
			[
				'ID'         => $draft,
				'post_title' => 'Ancora una bozza',
			]
		);
		// Nemmeno wp:sitemap: una bozza non c'è mai stata.
		update_post_meta( $draft, '_barrio_seo_index', 'noindex' );
		_wp_put_post_revision( get_post( $post ) );
		_wp_put_post_revision( get_post( $post ), true );
		self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post );

		$this->requests = [];
		$this->assertNull( barrio_wpgraphql_revalidate_send() );
		$this->assertSame( [], $this->requests );
	}

	public function test_an_alt_text_sends_the_tags_of_the_content_the_image_is_attached_to(): void {
		$post  = self::factory()->post->create( [ 'post_name' => 'margherita' ] );
		$image = self::factory()->attachment->create_object(
			[
				'file'           => 'margherita.jpg',
				'post_parent'    => $post,
				'post_mime_type' => 'image/jpeg',
			]
		);
		$loose = self::factory()->attachment->create_object(
			[
				'file'           => 'sciolta.jpg',
				'post_mime_type' => 'image/jpeg',
			]
		);
		$this->sent();

		update_post_meta( $loose, '_wp_attachment_image_alt', 'Di nessun contenuto' );
		$this->assertSame( [], $this->sent() );
		update_post_meta( $image, '_wp_attachment_image_alt', 'Una pizza' );
		$this->assertSame( [ 'wp:uri:margherita', 'wp:type:post', "page_{$post}" ], $this->sent() );
		update_post_meta( $image, '_wp_attachment_image_alt', 'Una pizza margherita' );
		$this->assertSame( [ 'wp:uri:margherita', 'wp:type:post', "page_{$post}" ], $this->sent() );
	}

	// Chi salva non aspetta un frontend spento una volta per blocco, e l'errore resta visibile finché non risponde.
	public function test_a_frontend_that_does_not_answer_is_called_once_and_the_error_stays_until_it_answers(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$error      = fn(): string => $this->request( 'GET', '/barrio/v1/frontend' )->get_data()['error'];
		$this->code = 0;

		barrio_wpgraphql_revalidate( ...array_map( static fn( int $id ): string => "page_{$id}", range( 1, 150 ) ) );
		barrio_wpgraphql_revalidate_shutdown();
		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( 'Could not resolve host', $error() );

		$refused = $this->request( 'POST', '/barrio/v1/frontend/clear', [ 'tags' => 'wp:menu' ] );
		$this->assertSame( 502, $refused->get_status() );
		$this->assertStringContainsString( 'Could not resolve host', $refused->as_error()->get_error_message() );

		$this->code = 401;
		do_action( 'barrio_hours_changed' );
		barrio_wpgraphql_revalidate_shutdown();
		$this->assertStringContainsString( '401', $error() );

		$this->code = 200;
		do_action( 'barrio_hours_changed' );
		barrio_wpgraphql_revalidate_shutdown();
		$this->assertSame( '', $error() );
	}

	public function test_without_the_frontend_address_nothing_is_sent_and_without_the_token_no_header(): void {
		update_option( 'barrio_frontend', [ 'token' => 's3greto' ] );
		self::factory()->post->create();
		$this->assertNull( barrio_wpgraphql_revalidate_send() );
		$this->assertSame( [], $this->requests );

		update_option( 'barrio_frontend', [ 'url' => 'https://www.pizzeria.it' ] );
		do_action( 'barrio_hours_changed' );
		$this->sent();
		$this->assertSame( [], $this->requests[0]['headers'] );
	}

	public function test_many_tags_go_in_calls_of_100(): void {
		barrio_wpgraphql_revalidate( ...array_map( static fn( int $id ): string => "page_{$id}", range( 1, 150 ) ) );
		barrio_wpgraphql_revalidate_send();
		$this->assertSame( [ 100, 50 ], array_map( fn( array $request ): int => count( $this->tags( $request['url'] ) ), $this->requests ) );
	}

	public function test_a_menu_sends_wp_menu(): void {
		$menu = wp_create_nav_menu( 'Principale' );
		wp_update_nav_menu_item(
			$menu,
			0,
			[
				'menu-item-title'  => 'Chi siamo',
				'menu-item-url'    => 'https://www.pizzeria.it/chi-siamo/',
				'menu-item-status' => 'publish',
			]
		);
		wp_update_nav_menu_object( $menu, [ 'menu-name' => 'Principale' ] );
		$this->assertSame( [ 'wp:menu' ], $this->sent() );

		wp_delete_nav_menu( $menu );
		$this->assertSame( [ 'wp:menu' ], $this->sent() );
	}

	public function test_each_change_of_barrio_suite_sends_the_tags_of_the_prototype(): void {
		$post = self::factory()->post->create( [ 'post_name' => 'margherita' ] );
		$form = FormRepository::create( 'Contatti', [], [], new FormVersion( [ new Field( 'email', 'email', [ 'it' => [ 'label' => 'Email' ] ] ) ] ) );
		$this->sent();

		update_post_meta( $post, '_barrio_seo_index', 'noindex' );
		do_action( 'barrio_seo_changed', 'settings' );
		do_action( 'barrio_seo_changed', 'redirects' );
		do_action( 'barrio_seo_changed', 'term', 3 );
		do_action( 'barrio_hours_changed' );
		do_action( 'barrio_company_changed' );
		do_action( 'barrio_ordering_changed', 'term', 'category' );
		$this->assertIsInt( PopupRepository::save( null, $this->popup() ) );
		$this->assertIsInt( BannerRepository::save( null, $this->banner() ) );
		FormRepository::set_status( $form->id, FormStatus::Closed );

		$this->assertSame(
			[ 'wp:uri:margherita', 'wp:type:post', "page_{$post}", 'wp:sitemap', 'wp:seo', 'wp:schema', 'wp:llms', 'wp:redirects', 'wp:orari', 'wp:option', 'wp:type:category', 'wp:popup', 'wp:banner', "form_{$form->id}" ],
			$this->sent()
		);

		FormRepository::delete( $form->id );
		$this->assertSame( [ "form_{$form->id}" ], $this->sent() );
	}

	public function test_popups_and_banners_revalidate_when_their_period_starts_and_ends(): void {
		$start = time() + DAY_IN_SECONDS;
		$end   = $start + DAY_IN_SECONDS;
		$dates = [
			'starts_at' => gmdate( 'Y-m-d H:i:s', $start ),
			'ends_at'   => gmdate( 'Y-m-d H:i:s', $end ),
		];
		PopupRepository::save( null, $dates + $this->popup() );
		BannerRepository::save( null, $dates + $this->banner() );
		// Spento: niente eventi.
		PopupRepository::save( null, [ 'is_enabled' => false ] + $dates + $this->popup() );

		foreach ( [ 'popups', 'banners' ] as $module ) {
			$events = array_filter( _get_cron_array(), static fn( array $hooks ): bool => isset( $hooks[ "barrio_wpgraphql_{$module}_period" ] ) );
			$this->assertSame( [ $start, $end ], array_keys( $events ), $module );
		}
		$this->sent();

		do_action( 'barrio_wpgraphql_popups_period', $start );
		do_action( 'barrio_wpgraphql_banners_period', $end );
		$this->assertSame( [ 'wp:popup', 'wp:banner' ], $this->sent() );
	}

	public function test_the_settings_page_saves_a_clean_address_and_only_for_administrators(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( apply_filters( 'barrio_admin_data', [] )['frontend'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core.

		$saved = $this->request(
			'PUT',
			'/barrio/v1/frontend',
			[
				'url'   => ' https://www.trattoria.it/ ',
				'token' => ' Nu0vo%2F.+/=_~- ',
			]
		);
		// Il token resta com'è: ripulito non sarebbe più quello del frontend.
		$this->assertSame( 400, $saved->get_status() );
		$saved = $this->request(
			'PUT',
			'/barrio/v1/frontend',
			[
				'url'   => ' www.trattoria.it/ ',
				'token' => ' Nu0vo.+/=_~- ',
			]
		);
		$this->assertSame(
			[
				'url'   => 'https://www.trattoria.it',
				'token' => 'Nu0vo.+/=_~-',
				'error' => '',
			],
			$saved->get_data()
		);
		$this->assertSame( $saved->get_data(), $this->request( 'GET', '/barrio/v1/frontend' )->get_data() );
		// Con una query /api/revalidate/ finirebbe dentro di lei, e risponderebbe la home.
		foreach ( [ 'javascript:alert(1)', '/foo', 'https://x.it/?a=', 'https://x.it/#a', 'https://io@x.it' ] as $url ) {
			$refused = $this->request(
				'PUT',
				'/barrio/v1/frontend',
				[
					'url'   => $url,
					'token' => '',
				]
			);
			$this->assertSame( 400, $refused->get_status(), $url );
		}
		$this->assertSame(
			400,
			$this->request(
				'PUT',
				'/barrio/v1/frontend',
				[
					'url'   => '',
					'token' => 'due parole',
				]
			)->get_status()
		);
		$this->assertSame( 'https://www.trattoria.it', barrio_wpgraphql_frontend()['url'] );
		// Il basePath di Next.js, e un frontend di sviluppo.
		$this->assertSame(
			'http://localhost:3000/it',
			$this->request(
				'PUT',
				'/barrio/v1/frontend',
				[
					'url'   => 'http://localhost:3000/it/',
					'token' => '',
				]
			)->get_data()['url']
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertFalse( apply_filters( 'barrio_admin_data', [] )['frontend'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro del core.
		$this->assertSame( 403, $this->request( 'GET', '/barrio/v1/frontend' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/barrio/v1/frontend/clear', [ 'tags' => 'wp:menu' ] )->get_status() );
	}

	public function test_the_cache_of_some_tags_is_cleared_by_hand(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$cleared = $this->request( 'POST', '/barrio/v1/frontend/clear', [ 'tags' => ' wp:menu, form_3 ,, ' ] );
		$this->assertSame( 200, $cleared->get_status() );
		$this->assertSame( [ 'wp:menu', 'form_3' ], $this->tags( $this->requests[0]['url'] ) );
		$this->assertSame( [ 'Authorization' => 'Bearer s3greto' ], $this->requests[0]['headers'] );

		$this->assertSame( 400, $this->request( 'POST', '/barrio/v1/frontend/clear', [ 'tags' => ' , ' ] )->get_status() );

		$this->code = 401;
		$refused    = $this->request( 'POST', '/barrio/v1/frontend/clear', [ 'tags' => 'wp:menu' ] );
		$this->assertSame( 502, $refused->get_status() );
		$this->assertStringContainsString( '401', $refused->as_error()->get_error_message() );
	}

	// L'opzione nasce dal primo salvataggio: letta a ogni richiesta, finché non c'è costerebbe una query.
	public function test_without_tags_the_option_is_not_read(): void {
		global $wpdb;
		delete_option( 'barrio_frontend' );
		wp_cache_delete( 'notoptions', 'options' );
		$queries = $wpdb->num_queries;

		barrio_wpgraphql_revalidate_shutdown();
		$this->assertSame( 'https://example.org/a/', barrio_wpgraphql_frontend_url( 'https://example.org/a/' ) );

		$this->assertSame( $queries, $wpdb->num_queries );
	}

	// La chiamata della richiesta, una sola, e i suoi tag; poi la coda è vuota.
	/** @return list<string> */
	private function sent(): array {
		$this->requests = [];
		barrio_wpgraphql_revalidate_send();
		$this->assertLessThanOrEqual( 1, count( $this->requests ) );
		return $this->requests ? $this->tags( $this->requests[0]['url'] ) : [];
	}

	/** @return list<string> */
	private function tags( string $url ): array {
		$this->assertStringStartsWith( 'https://www.pizzeria.it/api/revalidate/?tag=', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return explode( ',', (string) $query['tag'] );
	}

	/** @param array<string, mixed> $body */
	private function request( string $method, string $path, array $body = [] ): WP_REST_Response {
		$this->requests = [];
		$request        = new WP_REST_Request( $method, $path );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	/** @return array<string, mixed> */
	private function popup(): array {
		return [
			'is_enabled' => true,
			'starts_at'  => null,
			'ends_at'    => null,
			'image_id'   => null,
			'settings'   => [
				'new_tab' => false,
				'days'    => 7,
			],
			'i18n'       => [ 'it' => [ 'title' => 'Natale' ] ],
		];
	}

	/** @return array<string, mixed> */
	private function banner(): array {
		return [
			'is_enabled' => true,
			'new_tab'    => false,
			'starts_at'  => null,
			'ends_at'    => null,
			'i18n'       => [
				'it' => [
					'text'     => 'Saldi',
					'link_url' => null,
				],
			],
		];
	}
}
