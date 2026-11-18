<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Modules;
use BarrioSuite\Seo\RedirectCode;
use BarrioSuite\Seo\Repository\RedirectRepository;
use BarrioSuite\Seo\Settings;
use WP_UnitTestCase;

// Qui e non in bootstrap.php: con il prototipo già al boot, l'estensione spegnerebbe il modulo SEO in ogni test.
require_once dirname( __DIR__ ) . '/prototype.php';

// Il passaggio del SEO del prototipo (extensions/barrio-suite-migration/seo.php): opzioni, meta di contenuti e
// termini, redirect, e lo scambio tra il SEO del prototipo e quello di Barrio Suite.
final class SeoMigrationTest extends WP_UnitTestCase {

	private int $image = 0;

	private int $post = 0;

	private int $page = 0;

	private int $other = 0;

	private int $term = 0;

	public function set_up(): void {
		parent::set_up();
		$GLOBALS['barrio_test_revalidated'] = [];
		$this->image                        = self::factory()->attachment->create_object(
			[
				'file'           => '2026/11/logo.jpg',
				'post_mime_type' => 'image/jpeg',
			]
		);
		$this->post                         = self::factory()->post->create();
		$this->page                         = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$this->other                        = self::factory()->post->create();
		$this->term                         = self::factory()->category->create();
		$this->prototype();
	}

	public function tear_down(): void {
		wp_delete_file( ABSPATH . 'llms.txt' );
		parent::tear_down();
	}

	public function test_the_options_become_the_sections_of_barrio_seo(): void {
		$this->assertIsArray( $this->migrate() );
		$settings = Settings::get();

		$this->assertSame(
			[
				'separator' => '|',
				'order'     => 'site_page',
			],
			$settings['title']
		);
		$this->assertSame( $this->image, $settings['default_image'] );
		$this->assertSame(
			[
				'index'  => false,
				'follow' => true,
			],
			$settings['index']['taxonomies']['category']
		);
		// Il prototipo non aveva gli archivi degli autori.
		$this->assertFalse( $settings['index']['archives']['author']['index'] );
		$this->assertSame(
			[
				'post_types' => [
					'post' => 'links',
					'page' => 'include',
				],
				'taxonomies' => [ 'post_tag' => 'exclude' ],
			],
			$settings['llms']
		);
		$this->assertSame(
			[
				'default_description'      => 'Descrizione predefinita',
				'organization_description' => 'Cucina di mare',
				'llms_intro'               => '',
			],
			$settings['texts']['en']
		);

		$organization = $settings['organization'];
		$this->assertSame( [ 'Restaurant', 'Trattoria', $this->image, 0 ], [ $organization['type'], $organization['name'], $organization['logo'], $organization['image'] ] );
		$this->assertSame( 'IT', $organization['address']['country'] );
		$this->assertSame(
			[
				'latitude'  => 40.85,
				'longitude' => 14.27,
			],
			$organization['geo']
		);
		$this->assertSame(
			[
				[
					'type' => 'City',
					'name' => 'Napoli',
				],
				[
					'type' => 'Place',
					'name' => 'Campania',
				],
				[
					'type' => 'Place',
					'name' => 'Provincia di Salerno',
				],
				[
					'type' => 'AdministrativeArea',
					'name' => 'Campania',
				],
			],
			$organization['area_served']
		);
		$this->assertSame( [ 'Pesce', 'Pizza' ], $organization['knows_about'] );
		$this->assertSame( [ 'https://facebook.com/trattoria' ], $organization['same_as'] );
		$this->assertSame( [ 8.5, null ], array_column( $organization['offers']['items'], 'price' ) );
		$this->assertSame( 'https://www.trattoria.it', get_option( 'graphql_general_settings' )['barrio_frontend_url'] );
	}

	public function test_a_schema_language_other_than_the_site_one_is_reported(): void {
		$report = $this->migrate();

		// Il sito dei test è in inglese, lo schema del prototipo in italiano.
		$this->assertContains( 'Schema: the language was it-IT, Barrio Suite uses the one of the site (en-US). If it is wrong, change it in Settings → General.', $report['warnings'] );
	}

	public function test_without_schema_the_company_info_fills_the_organization(): void {
		delete_option( 'suite_barrio_schema_settings' );
		update_option(
			'suite_barrio_company_info',
			[
				'ragione_sociale' => 'Trattoria Srl',
				'partita_iva'     => 'IT0123',
				'sede_citta'      => 'Napoli',
				'instagram'       => 'https://instagram.com/trattoria',
			]
		);
		$this->migrate();

		$organization = Settings::get()['organization'];
		$this->assertSame( [ 'LocalBusiness', 'Trattoria Srl', 'IT0123', 'Napoli' ], [ $organization['type'], $organization['name'], $organization['vat_id'], $organization['address']['locality'] ] );
		$this->assertSame( [ 'https://instagram.com/trattoria' ], $organization['same_as'] );
	}

	public function test_meta_become_barrio_meta_and_explicit_defaults_become_default(): void {
		$report = $this->migrate();

		$this->assertSame( 'Titolo SEO', get_post_meta( $this->post, '_barrio_seo_title', true ) );
		$this->assertSame( 'Una descrizione con \\ e "virgolette"', get_post_meta( $this->post, '_barrio_seo_description', true ) );
		$this->assertSame( $this->image, (int) get_post_meta( $this->post, '_barrio_seo_image', true ) );
		$this->assertSame( [ 'pizza', 'napoli' ], get_post_meta( $this->post, '_barrio_seo_keywords', true ) );
		// index uguale al default del tipo: resta "predefinito".
		$this->assertFalse( metadata_exists( 'post', $this->post, '_barrio_seo_index' ) );
		$this->assertSame( 'nofollow', get_post_meta( $this->post, '_barrio_seo_follow', true ) );

		// Il vecchio prefisso _wpb_seo_, e _suite_barrio_seo_ quando ci sono tutti e due.
		$this->assertSame( 'Pagina', get_post_meta( $this->page, '_barrio_seo_title', true ) );
		$this->assertSame( 'Descrizione nuova', get_post_meta( $this->page, '_barrio_seo_description', true ) );
		$this->assertSame( 'noindex', get_post_meta( $this->page, '_barrio_seo_index', true ) );
		$this->assertSame( 'post', get_post_meta( $this->page, '_barrio_seo_collection', true ) );

		// Ciò che Barrio Suite ha già resta, e un'immagine che non c'è più non passa.
		$this->assertSame( 'Già', get_post_meta( $this->other, '_barrio_seo_title', true ) );
		$this->assertFalse( metadata_exists( 'post', $this->other, '_barrio_seo_image' ) );

		$this->assertFalse( metadata_exists( 'term', $this->term, '_barrio_seo_index' ) );
		$this->assertSame( [ 'uno', 'due' ], get_term_meta( $this->term, '_barrio_seo_keywords', true ) );

		$counts = array_column( $report['counts'], null, 'data' );
		$this->assertSame(
			[
				'data'     => 'post title',
				'found'    => 3,
				'migrated' => 2,
				'default'  => 0,
				'skipped'  => 1,
			],
			$counts['post title']
		);
		// Il post con l'index uguale al default e l'altro con l'index vuoto restano "predefinito".
		$this->assertSame( [ 3, 1, 2, 0 ], [ $counts['post index']['found'], $counts['post index']['migrated'], $counts['post index']['default'], $counts['post index']['skipped'] ] );
		foreach ( $report['counts'] as $count ) {
			$this->assertSame( $count['found'], $count['migrated'] + $count['default'] + $count['skipped'], $count['data'] );
		}
		// Il contenuto indicizzabile fuori dalla sitemap; la pagina noindex no.
		$this->assertContains( "Contents out of the sitemap but indexable, now in the sitemap: set them to noindex if they must stay out. IDs: {$this->post}.", $report['warnings'] );
	}

	public function test_redirects_go_to_the_table_without_duplicates_and_loops(): void {
		$report = $this->migrate();

		$redirects = RedirectRepository::all();
		$this->assertSame( [ '/promo?x=1', '/slug-vecchio', '/sparita', '/vecchia' ], array_column( $redirects, 'source' ) );
		$this->assertSame( [ 'https://shop.example/', '/slug-nuovo/', null, '/nuova/' ], array_column( $redirects, 'target' ) );
		$this->assertSame( [ RedirectCode::TemporaryRedirect, RedirectCode::MovedPermanently, RedirectCode::Gone, RedirectCode::MovedPermanently ], array_column( $redirects, 'code' ) );
		$this->assertSame( [ null, null, 'Chiusa', null ], array_column( $redirects, 'note' ) );
		$this->assertSame( [ false, true, false, false ], array_column( $redirects, 'is_auto' ) );

		$this->assertContains( 'Redirects not migrated (duplicate): /vecchia.', $report['warnings'] );
		$this->assertContains( 'Redirects not migrated (invalid code): /d.', $report['warnings'] );
		$this->assertContains( 'Redirects not migrated (loop): /a, /b, /c.', $report['warnings'] );
	}

	public function test_at_the_end_the_prototype_seo_is_off_and_barrio_suite_seo_is_on(): void {
		Modules::set( 'seo', false );
		update_option( 'suite_barrio_llms_last_generated', [ 'ts' => 1 ] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- il file che scriveva il prototipo.
		file_put_contents( ABSPATH . 'llms.txt', "# Trattoria\n\n> https://www.trattoria.it\n\nUna trattoria di mare.\nA Napoli.\n\n## Pagine\n\n- [Menu](https://www.trattoria.it/menu/)\n" );

		$this->migrate();

		$this->assertTrue( Modules::is_enabled( 'seo' ) );
		$this->assertSame( 0, get_option( 'suite_barrio_tools_modules' )['seo'] );
		$this->assertSame( 1, get_option( 'suite_barrio_tools_modules' )['forms'] );
		$this->assertSame( 'migrated', get_option( 'barrio_migration_seo' ) );
		$this->assertSame( "Una trattoria di mare.\nA Napoli.", Settings::get()['texts']['en']['llms_intro'] );
		$this->assertFileDoesNotExist( ABSPATH . 'llms.txt' );
		// Il frontend headless rilegge tutto.
		$this->assertContains( 'wp:seo,wp:schema,wp:sitemap,wp:llms', $GLOBALS['barrio_test_revalidated'] );
		$this->assertContains( 'wp:redirects', $GLOBALS['barrio_test_revalidated'] );
	}

	public function test_a_dry_run_reports_the_same_counts_and_writes_nothing(): void {
		Modules::set( 'seo', false );
		$dry = barrio_migration_seo_run( true );

		$this->assertIsArray( $dry );
		$this->assertFalse( get_option( 'barrio_seo' ) );
		$this->assertFalse( metadata_exists( 'post', $this->post, '_barrio_seo_title' ) );
		$this->assertSame( 0, RedirectRepository::count() );
		$this->assertFalse( Modules::is_enabled( 'seo' ) );
		$this->assertSame( 1, get_option( 'suite_barrio_tools_modules' )['seo'] );

		$this->assertSame( $dry, barrio_migration_seo_run( false ) );
	}

	public function test_a_second_run_keeps_what_is_already_there(): void {
		$this->migrate();
		update_option( 'suite_barrio_meta_settings', [ 'separator' => '/' ] + get_option( 'suite_barrio_meta_settings' ) );
		$second = $this->migrate();

		$this->assertSame( '|', Settings::get()['title']['separator'] );
		$this->assertSame( [ 0 ], array_unique( array_column( $second['counts'], 'migrated' ) ) );
		$this->assertSame( 4, RedirectRepository::count() );
		$this->assertContains( 'Settings: Barrio Suite already has its SEO settings, they stay as they are.', $second['notes'] );
	}

	public function test_without_data_of_the_prototype_it_is_an_error(): void {
		global $wpdb;
		foreach ( [ 'suite_barrio_meta_settings', 'suite_barrio_meta_content', 'suite_barrio_schema_settings', 'suite_barrio_redirects' ] as $option ) {
			delete_option( $option );
		}
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_suite\\_barrio%' OR meta_key LIKE '\\_wpb%'" );
		$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '\\_suite\\_barrio%'" );

		$this->assertWPError( barrio_migration_seo_run( false ) );
	}

	public function test_while_the_prototype_seo_is_on_the_module_is_turned_off_once(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		barrio_migration_seo_boot();

		$this->assertFalse( Modules::is_enabled( 'seo' ) );
		ob_start();
		barrio_migration_seo_notice();
		$this->assertStringContainsString( '<code>wp barrio seo migrate --dry-run</code>', (string) ob_get_clean() );

		// Chi lo riaccende prima della migrazione lo sceglie.
		Modules::set( 'seo', true );
		barrio_migration_seo_boot();
		$this->assertTrue( Modules::is_enabled( 'seo' ) );
	}

	public function test_with_the_prototype_seo_off_the_module_stays_on(): void {
		update_option( 'suite_barrio_tools_modules', [ 'seo' => 0 ] );
		barrio_migration_seo_boot();

		$this->assertTrue( Modules::is_enabled( 'seo' ) );
		$this->assertFalse( get_option( 'barrio_migration_seo' ) );
	}

	public function test_seo_changes_reach_the_revalidation_of_the_prototype(): void {
		update_post_meta( $this->post, '_barrio_seo_index', 'noindex' );
		do_action( 'barrio_seo_changed', 'redirects' );

		$this->assertSame( [ $this->post, 'wp:sitemap', 'wp:redirects' ], $GLOBALS['barrio_test_revalidated'] );
	}

	/** @return array{counts: list<array<string, mixed>>, notes: list<string>, warnings: list<string>} */
	private function migrate(): array {
		$report = barrio_migration_seo_run( false );
		$this->assertIsArray( $report );
		return $report;
	}

	// I dati del modulo SEO del prototipo, come li salvava.
	private function prototype(): void {
		update_option(
			'suite_barrio_tools_modules',
			[
				'forms' => 1,
				'seo'   => 1,
			]
		);
		update_option(
			'suite_barrio_meta_settings',
			[
				'site_title'   => 'Trattoria',
				'separator'    => '|',
				'title_order'  => 'site_page',
				'og_image_id'  => $this->image,
				'favicon_id'   => 0,
				'default_desc' => 'Descrizione predefinita',
			]
		);
		update_option(
			'suite_barrio_meta_content',
			[
				'post_types' => [
					'post' => [
						'index'  => 1,
						'follow' => 1,
					],
					'page' => [
						'index'  => 1,
						'follow' => 1,
					],
				],
				'taxonomies' => [
					'category' => [
						'index'  => 0,
						'follow' => 1,
					],
				],
			]
		);
		update_option(
			'suite_barrio_schema_settings',
			[
				'business_type'      => 'Restaurant',
				'business_name'      => 'Trattoria',
				'description'        => 'Cucina di mare',
				'url'                => 'https://www.trattoria.it/',
				'logo'               => wp_get_attachment_url( $this->image ),
				// Fuori dalla libreria dei media: resta da scegliere.
				'image'              => 'https://altrove.example/foto.jpg',
				'country'            => 'it',
				'latitude'           => '40,85',
				'longitude'          => '14.27',
				'facebook'           => 'https://facebook.com/trattoria',
				'instagram'          => '',
				'knows_about'        => "Pesce\n\nPizza\n",
				'area_served'        => "City: Napoli\nText: Campania\nProvincia di Salerno\nAdministrativeArea: Campania",
				'language'           => 'it-IT',
				'offer_catalog_name' => 'Menu',
				'offer_currency'     => 'EUR',
				'offers'             => [
					[
						'type'         => 'Product',
						'name'         => 'Pizza',
						'price'        => '8,50',
						'availability' => 'InStock',
					],
					[
						'type'  => 'Service',
						'name'  => 'Catering',
						'price' => 'su richiesta',
					],
				],
			]
		);
		update_option(
			'suite_barrio_llms_pt_modes',
			[
				'post' => 'include_links',
				'page' => 'include',
			]
		);
		update_option( 'suite_barrio_llms_tax_modes', [ 'post_tag' => 'exclude' ] );
		update_option(
			'suite_barrio_redirects',
			[
				[
					'from' => '/vecchia/',
					'to'   => '/nuova/',
					'code' => 301,
					'note' => '',
				],
				// Lo stesso percorso senza la / finale.
				[
					'from' => '/vecchia',
					'to'   => '/altra/',
					'code' => 302,
				],
				[
					'from' => '/sparita',
					'to'   => '/',
					'code' => 410,
					'note' => 'Chiusa',
				],
				[
					'from' => '/slug-vecchio',
					'to'   => '/slug-nuovo/',
					'code' => 301,
					'note' => 'Aggiunto automaticamente al cambio slug',
				],
				[
					'from' => '/a',
					'to'   => '/b/',
					'code' => 301,
				],
				[
					'from' => '/b',
					'to'   => '/a',
					'code' => 301,
				],
				[
					'from' => '/c',
					'to'   => '/c/',
					'code' => 301,
				],
				[
					'from' => '/d',
					'to'   => '/e',
					'code' => 303,
				],
				[
					'from' => '/promo?x=1',
					'to'   => 'https://shop.example/',
					'code' => 307,
				],
			]
		);

		$meta = [
			$this->post  => [
				'_suite_barrio_seo_title'       => 'Titolo SEO',
				'_suite_barrio_seo_description' => 'Una descrizione con \\ e "virgolette"',
				'_suite_barrio_seo_og_image_id' => $this->image,
				'_suite_barrio_seo_keywords'    => '["pizza","napoli","pizza"]',
				'_suite_barrio_seo_index'       => '1',
				'_suite_barrio_seo_follow'      => '0',
				'_suite_barrio_seo_sitemap'     => '0',
			],
			$this->page  => [
				'_wpb_seo_title'                  => 'Pagina',
				'_wpb_seo_description'            => 'Descrizione vecchia',
				'_suite_barrio_seo_description'   => 'Descrizione nuova',
				'_suite_barrio_seo_index'         => '0',
				'_suite_barrio_seo_sitemap'       => '0',
				'_suite_barrio_schema_collection' => 'post',
			],
			$this->other => [
				'_suite_barrio_seo_title'       => 'Nuovo',
				'_suite_barrio_seo_og_image_id' => 999999,
				'_suite_barrio_seo_index'       => '',
				'_barrio_seo_title'             => 'Già',
			],
		];
		foreach ( $meta as $id => $values ) {
			foreach ( $values as $key => $value ) {
				update_post_meta( $id, $key, wp_slash( $value ) );
			}
		}
		update_term_meta( $this->term, '_suite_barrio_seo_index', '0' );
		update_term_meta( $this->term, '_suite_barrio_seo_keywords', 'uno, due' );
		$GLOBALS['barrio_test_revalidated'] = [];
	}
}
