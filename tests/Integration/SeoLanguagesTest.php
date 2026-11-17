<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\I18n;
use BarrioSuite\Seo\Llms;
use BarrioSuite\Seo\Meta;
use BarrioSuite\Seo\Module;
use BarrioSuite\Seo\Schema;
use BarrioSuite\Seo\Settings;
use SimpleXMLElement;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Il modulo SEO con più lingue, con l'estensione barrio-suite-wpml e un WPML simulato (wpml()): lingue it e
 * en-us, la pila di wpml_switch_language, e query di contenuti e termini filtrate sulla lingua attuale, che
 * qui è nel titolo ("Chi siamo [it]").
 */
final class SeoLanguagesTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		Module::register_meta();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		update_option( 'blogname', 'Pizzeria' );
		Settings::save(
			[
				'texts' => [
					'it'    => [
						'default_description'      => 'Pizza a Torino.',
						'organization_description' => 'La pizzeria di Mario.',
						'llms_intro'               => 'Introduzione.',
					],
					'en-us' => [
						'default_description'      => 'Pizza in Turin.',
						'organization_description' => '',
						'llms_intro'               => '',
					],
				],
			]
		);
	}

	public function test_without_a_multilingual_plugin_there_is_one_language(): void {
		self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Chi siamo',
			]
		);
		$data = rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/seo/contents/post_types/page' ) )->get_data();
		$this->assertSame( [ 'en' ], array_unique( array_column( $data['rows'], 'lang' ) ) );
		$this->assertStringStartsWith( "# Pizzeria\n\n> http://example.org/\n\n", Llms::build( false ), 'One block, without the language.' );
		$this->assertStringNotContainsString( '---', Llms::build( false ) );
		$this->assertSame( 1, substr_count( $this->robots(), 'Sitemap:' ) );
		// I testi salvati per lingue che non ci sono restano, ma vale quella del sito.
		$this->assertSame( '', Meta::default_description() );
	}

	public function test_each_language_has_its_texts_with_those_of_the_main_language_as_fallback(): void {
		$this->wpml();
		$this->assertSame( [ 'Pizza a Torino.', 'Pizza in Turin.' ], [ Meta::default_description(), I18n::in( 'en-us', [ Meta::class, 'default_description' ] ) ] );
		$this->assertSame( 'La pizzeria di Mario.', Settings::text( 'organization_description', 'en-us' ), 'An empty text is the main one.' );
		$this->assertSame( 'Pizza a Torino.', Settings::text( 'default_description', 'de' ) );

		$org = I18n::in( 'en-us', static fn(): array => Schema::graph( Meta::post( get_post( self::factory()->post->create() ) ), null )['@graph'][0] );
		$this->assertSame( 'La pizzeria di Mario.', $org['description'] );
		$this->assertSame( 'it', I18n::current(), 'I18n::in() goes back to the language before.' );
	}

	public function test_contents_llms_txt_item_lists_and_sitemap_counts_are_per_language(): void {
		$it    = self::factory()->post->create( [ 'post_title' => 'Ciao [it]' ] );
		$en    = self::factory()->post->create( [ 'post_title' => 'Hello [en-us]' ] );
		$pages = [
			'it'    => self::factory()->post->create(
				[
					'post_type'  => 'page',
					'post_title' => 'Notizie [it]',
				]
			),
			'en-us' => self::factory()->post->create(
				[
					'post_type'  => 'page',
					'post_title' => 'News [en-us]',
				]
			),
		];
		update_post_meta( $pages['en-us'], '_barrio_seo_collection', 'post' );
		$pizze = self::factory()->category->create( [ 'name' => 'Pizze [it]' ] );
		$pizza = self::factory()->category->create( [ 'name' => 'Pizzas [en-us]' ] );
		wp_set_post_categories( $it, [ $pizze ] );
		wp_set_post_categories( $en, [ $pizza ] );
		$this->wpml();

		$contents = rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/seo/contents/post_types/page' ) )->get_data();
		$this->assertSame(
			[
				$pages['it']    => 'it',
				$pages['en-us'] => 'en-us',
			],
			array_column( $contents['rows'], 'lang', 'id' )
		);
		$this->assertSame( [ 'Pizza a Torino.', 'Pizza in Turin.' ], array_column( $contents['contexts'], 'description' ) );
		$terms = rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/seo/contents/taxonomies/category' ) )->get_data()['rows'];
		$this->assertSame(
			[
				$pizze => 'it',
				$pizza => 'en-us',
			],
			array_column( $terms, 'lang', 'id' )
		);

		[ $italian, $english ] = explode( "---\n\n", Llms::build( false ) );
		$this->assertStringStartsWith( "# Pizzeria (IT)\n\n> http://example.org/\n\nIntroduzione.\n\n", $italian );
		$this->assertStringStartsWith( "# Pizzeria (EN-US)\n\n> http://example.org/\n\nIntroduzione.\n\n", $english, 'The intro of the main language.' );
		$this->assertStringContainsString( 'Ciao [it]', $italian );
		$this->assertStringContainsString( 'Pizze [it]', $italian );
		$this->assertStringNotContainsString( 'Hello', $italian );
		$this->assertStringContainsString( 'Hello [en-us]', $english );
		$this->assertStringContainsString( 'Pizzas [en-us]', $english );
		$this->assertStringNotContainsString( 'Ciao', $english );

		$list = I18n::in( 'en-us', static fn(): array => Schema::graph( Meta::post( get_post( $pages['en-us'] ) ), get_post( $pages['en-us'] ) )['@graph'][2]['mainEntity'] );
		$this->assertSame( [ get_permalink( $en ) ], array_column( $list['itemListElement'], 'url' ), 'The items of the language of the page.' );

		$counts = rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/seo/settings/sitemap' ) )->get_data()['counts'];
		$this->assertSame( [ 2, 2 ], [ $counts['post_types']->post, $counts['taxonomies']->category ], 'Every language has its sitemap.' );
	}

	public function test_robots_txt_lists_the_sitemap_of_every_language(): void {
		$this->wpml();
		$robots = $this->robots();
		$this->assertStringContainsString( "Sitemap: http://example.org/wp-sitemap.xml\n", $robots );
		$this->assertStringContainsString( "Sitemap: http://example.org/en-us/wp-sitemap.xml\n", $robots );
		$this->assertStringNotContainsString( '/it/', $robots );
	}

	// Ogni meta SEO ha la sua regola in wpml-config.xml: i testi si traducono, l'indicizzazione si copia.
	public function test_wpml_config_has_every_seo_meta(): void {
		$config = simplexml_load_file( dirname( __DIR__, 2 ) . '/extensions/barrio-suite-wpml/wpml-config.xml' );
		$rules  = static function ( SimpleXMLElement $fields ): array {
			$rules = [];
			foreach ( $fields as $field ) {
				$rules[ (string) $field ] = (string) $field['action'];
			}
			return $rules;
		};
		$posts  = $rules( $config->{'custom-fields'}->{'custom-field'} );
		$terms  = $rules( $config->{'custom-term-fields'}->{'custom-term-field'} );

		$this->assertEqualsCanonicalizing( array_map( static fn( string $key ): string => "_barrio_seo_{$key}", Module::META ), array_keys( $posts ) );
		$this->assertEqualsCanonicalizing( array_diff( array_keys( $posts ), [ '_barrio_seo_collection' ] ), array_keys( $terms ) );
		$this->assertSame( [ 'copy', 'copy', 'translate' ], [ $posts['_barrio_seo_index'], $terms['_barrio_seo_follow'], $terms['_barrio_seo_title'] ] );
	}

	private function robots(): string {
		return apply_filters( 'robots_txt', '', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- il filtro del core.
	}

	// I filtri di WPML che l'estensione legge, con le query filtrate sulla lingua attuale.
	private function wpml(): void {
		global $wpdb;
		$stack = [ 'it' ];
		add_filter( 'wpml_default_language', static fn(): string => 'it' );
		add_filter(
			'wpml_active_languages',
			static fn(): array => [
				'it'    => [ 'default_locale' => 'it_IT' ],
				'en-us' => [ 'default_locale' => 'en_US' ],
			]
		);
		add_action(
			'wpml_switch_language',
			static function ( ?string $lang ) use ( &$stack ): void {
				if ( null === $lang ) {
					array_pop( $stack );
				} else {
					$stack[] = $lang;
				}
			}
		);
		add_filter(
			'wpml_current_language',
			static function () use ( &$stack ): string {
				return (string) end( $stack );
			}
		);
		add_filter(
			'wpml_permalink',
			static fn( string $url, string $lang ): string => str_replace( home_url( '/' ), home_url( "/{$lang}/" ), $url ),
			10,
			2
		);
		$tag = static fn(): string => '%' . $wpdb->esc_like( '[' . I18n::current() . ']' ) . '%';
		add_filter( 'posts_where', static fn( string $where ): string => $where . $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s", $tag() ) );
		add_filter(
			'terms_clauses',
			static function ( array $clauses ) use ( $wpdb, $tag ): array {
				$clauses['where'] .= $wpdb->prepare( ' AND t.name LIKE %s', $tag() );
				return $clauses;
			}
		);
	}
}
