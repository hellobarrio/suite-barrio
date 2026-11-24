<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\I18n;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// L'estensione barrio-suite-wpml (caricata da tests/bootstrap.php). WPML non si installa in CI:
// si simulano i suoi filtri, l'unico punto di contatto.
final class WpmlTest extends WP_UnitTestCase {

	public function test_without_wpml_there_is_only_the_site_language(): void {
		$this->assertSame( [ 'en' ], I18n::languages() );
		$this->assertSame( 'en', I18n::current() );
		$this->assertSame( 'en', I18n::resolve( 'de' ) );
		$this->assertSame( 'en_US', I18n::locale( 'en' ) );
		$this->assertSame( [], I18n::flags() );
	}

	public function test_languages_current_language_and_locales_come_from_wpml(): void {
		add_filter( 'wpml_default_language', static fn(): string => 'it' );
		add_filter(
			'wpml_active_languages',
			static fn(): array => [
				'en-us' => [
					'default_locale'   => 'en_US',
					'country_flag_url' => 'https://example.org/flags/us.svg',
				],
				'de'    => [ 'default_locale' => 'de_DE' ],
				'it'    => [
					'default_locale'   => 'it_IT',
					'country_flag_url' => 'javascript:alert(1)',
				],
			]
		);
		$current = 'en-us';
		add_filter(
			'wpml_current_language',
			static function () use ( &$current ): string {
				return $current;
			}
		);

		$this->assertSame( [ 'it', 'en-us', 'de' ], I18n::languages() );
		$this->assertSame( 'en-us', I18n::current() );
		$this->assertSame( [ 'en_US', 'de_DE' ], [ I18n::locale( 'en-us' ), I18n::locale( 'de' ) ] );
		// Una lingua che WPML non conosce: il locale del sito.
		$this->assertSame( 'en_US', I18n::locale( 'fr' ) );
		// Solo le bandiere che ci sono, e solo con un URL valido.
		$this->assertSame( [ 'en-us' => 'https://example.org/flags/us.svg' ], I18n::flags() );
		// In admin WPML può rispondere "all": vale la lingua principale.
		$current = 'all';
		$this->assertSame( 'it', I18n::current() );
	}

	public function test_reorder_lists_only_the_items_of_the_admin_language(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		update_option( 'barrio_ordering', [ 'post_types' => [ 'page' ] ] );
		$pages = self::factory()->post->create_many( 3, [ 'post_type' => 'page' ] );
		// Come WPML: la traduzione nella lingua chiesta, la voce stessa se è di quella lingua, null se non ne ha.
		$language = [
			$pages[0] => 'it',
			$pages[1] => 'en',
			$pages[2] => 'it',
		];
		add_filter( 'wpml_object_id', static fn( int $id, string $type, bool $original, string $lang ): ?int => 'page' === $type && $lang === $language[ $id ] ? $id : null, 10, 4 );
		$current = 'it';
		add_filter(
			'wpml_current_language',
			static function () use ( &$current ): string {
				return $current;
			}
		);
		$request = static function ( string $method, array $ids = [] ): WP_REST_Response {
			$request = new WP_REST_Request( $method, '/barrio/v1/ordering/post/page' );
			$request->set_body_params( [ 'ids' => $ids ] );
			return rest_do_request( $request );
		};
		$listed  = static fn(): array => array_column( (array) $request( 'GET' )->get_data(), 'id' );

		$this->assertEqualsCanonicalizing( [ $pages[0], $pages[2] ], $listed() );
		$this->assertSame( 400, $request( 'PUT', [ $pages[1], $pages[0] ] )->get_status(), 'Not an item of this language.' );
		$this->assertSame( [ $pages[2], $pages[0] ], array_column( (array) $request( 'PUT', [ $pages[2], $pages[0] ] )->get_data(), 'id' ) );
		$this->assertSame( 0, (int) get_post_field( 'menu_order', $pages[1] ), 'The other language keeps its order.' );
		$current = 'en';
		$this->assertSame( [ $pages[1] ], $listed() );
		// "Tutte le lingue" nell'admin di WPML.
		$current = 'all';
		$this->assertCount( 3, $listed() );

		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
	}

	public function test_the_wpml_language_switcher_is_hidden_only_on_the_barrio_pages(): void {
		$show = static fn(): mixed => apply_filters( 'wpml_show_admin_language_switcher', true );
		$this->assertTrue( $show() );
		foreach ( [ 'barrio', 'barrio-forms' ] as $page ) {
			$_GET['page'] = $page;
			$this->assertFalse( $show(), $page );
		}
		foreach ( [ 'wc-orders', 'barrios' ] as $page ) {
			$_GET['page'] = $page;
			$this->assertTrue( $show(), $page );
		}
		unset( $_GET['page'] );
	}
}
