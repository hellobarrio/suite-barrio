<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Forms\I18n;
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

	public function test_the_wpml_language_switcher_is_hidden_only_on_the_barrio_pages(): void {
		$show         = static fn(): mixed => apply_filters( 'wpml_show_admin_language_switcher', true );
		$this->assertTrue( $show() );
		$_GET['page'] = 'barrio-extensions';
		$this->assertFalse( $show() );
		$_GET['page'] = 'wc-orders';
		$this->assertTrue( $show() );
		unset( $_GET['page'] );
	}
}
