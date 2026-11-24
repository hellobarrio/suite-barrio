<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Banners\BannerRepository;
use BarrioSuite\Crypto;
use BarrioSuite\Modules;
use BarrioSuite\Popups\PopupRepository;
use DateTimeImmutable;
use WP_UnitTestCase;

// Qui e non in bootstrap.php: con il prototipo già al boot, l'estensione spegnerebbe i moduli in ogni test.
require_once dirname( __DIR__ ) . '/prototype.php';

// Il passaggio dei moduli del prototipo (extensions/barrio-suite-migration, un file per modulo): popup, banner, orari,
// blocchi, ordinamento, duplicazione, testi alternativi e statistiche, e lo scambio tra i moduli dei due plugin.
final class ModulesMigrationTest extends WP_UnitTestCase {

	private static string $private_key = '';

	private int $image = 0;

	// Tabelle vere, come quelle del prototipo: le temporanee che i test creano non compaiono in SHOW TABLES.
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		global $wpdb;
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpb_popups (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(255) NOT NULL DEFAULT '', content LONGTEXT NOT NULL, link VARCHAR(2048) NOT NULL DEFAULT '', link_title VARCHAR(255) NOT NULL DEFAULT '', link_target VARCHAR(10) NOT NULL DEFAULT '_self', image_url VARCHAR(2048) NOT NULL DEFAULT '', image_id BIGINT UNSIGNED NOT NULL DEFAULT 0, active TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpb_popups_translations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, popup_id BIGINT UNSIGNED NOT NULL, lang VARCHAR(10) NOT NULL DEFAULT '', title VARCHAR(255) NOT NULL DEFAULT '', content LONGTEXT NOT NULL, link VARCHAR(2048) NOT NULL DEFAULT '', link_title VARCHAR(255) NOT NULL DEFAULT '', link_target VARCHAR(10) NOT NULL DEFAULT '_self', PRIMARY KEY (id))" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpb_banners (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, title VARCHAR(255) NOT NULL DEFAULT '', link VARCHAR(2048) NOT NULL DEFAULT '', link_target VARCHAR(10) NOT NULL DEFAULT '_self', active TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wpb_banners_translations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, banner_id BIGINT UNSIGNED NOT NULL, lang VARCHAR(10) NOT NULL DEFAULT '', title VARCHAR(255) NOT NULL DEFAULT '', link VARCHAR(2048) NOT NULL DEFAULT '', link_target VARCHAR(10) NOT NULL DEFAULT '_self', PRIMARY KEY (id))" );
		openssl_pkey_export( openssl_pkey_new( [ 'private_key_bits' => 2048 ] ), self::$private_key );
	}

	public static function tear_down_after_class(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpb_popups, {$wpdb->prefix}wpb_popups_translations, {$wpdb->prefix}wpb_banners, {$wpdb->prefix}wpb_banners_translations" );
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		$GLOBALS['barrio_test_revalidated'] = [];
		add_filter( 'barrio_languages', static fn(): array => [ 'it', 'en' ] );
		$this->image = self::factory()->attachment->create_object(
			[
				'file'           => '2026/11/saldi.jpg',
				'post_mime_type' => 'image/jpeg',
			]
		);
		$this->prototype();
	}

	public function tear_down(): void {
		parent::tear_down();
		wp_roles()->for_site();
	}

	public function test_popups_keep_the_active_one_with_complete_translations(): void {
		$report  = $this->migrate( 'popups' );
		$popups  = array_reverse( PopupRepository::all() );
		$enabled = array_column( $popups, 'is_enabled' );

		$this->assertSame(
			[
				'data'     => 'popups',
				'found'    => 2,
				'migrated' => 2,
				'skipped'  => 0,
			],
			$report['counts'][0]
		);
		$this->assertSame( [ true, false ], $enabled );
		// MySQL 8.0 riordina le chiavi delle colonne JSON.
		$this->assertSame( [ true, 7 ], [ $popups[0]['settings']['new_tab'], $popups[0]['settings']['days'] ] );
		$this->assertSame( $this->image, $popups[0]['image_id'] );
		$this->assertSame(
			[
				// Testo e link vuoti nella traduzione: quelli della lingua principale.
				'en' => [
					'title'        => 'Sales',
					'content'      => "<p>Sconti</p>\n<p>fino al 50%</p>",
					'button_label' => 'Discover',
					'link_url'     => 'https://shop.it/saldi',
				],
				'it' => [
					'title'        => 'Saldi',
					'content'      => "<p>Sconti</p>\n<p>fino al 50%</p>",
					'button_label' => 'Scopri',
					'link_url'     => 'https://shop.it/saldi',
				],
			],
			$popups[0]['i18n']
		);
		// Senza titolo e con un'ancora come link: un titolo da cambiare, nessun pulsante.
		$this->assertSame(
			[
				'title'        => 'Popup 5',
				'content'      => '<p>Solo testo</p>',
				'button_label' => null,
				'link_url'     => null,
			],
			$popups[1]['i18n']['it']
		);
		$this->assertContains( 'Popup 5 has no title, which Barrio Suite shows and needs: it is "Popup 5", change it in Popups.', $report['warnings'] );
		$this->assertContains( 'Popup 5 (it): the link "#contatti" is not an address or a path, so the popup has no button.', $report['warnings'] );
	}

	public function test_banners_take_the_title_as_text(): void {
		$report  = $this->migrate( 'banners' );
		$banners = BannerRepository::all();

		$this->assertSame( [ 2, 1, 1 ], [ $report['counts'][0]['found'], $report['counts'][0]['migrated'], $report['counts'][0]['skipped'] ] );
		$this->assertCount( 1, $banners );
		$this->assertTrue( $banners[0]['is_enabled'] );
		$this->assertFalse( $banners[0]['new_tab'] );
		$this->assertSame(
			[
				'en' => [
					'text'     => 'Free shipping',
					'link_url' => '/spedizioni',
				],
				'it' => [
					'text'     => 'Spedizione gratuita',
					'link_url' => '/spedizioni',
				],
			],
			$banners[0]['i18n']
		);
		// Senza testo non c'è un banner.
		$this->assertStringStartsWith( 'Banner 4 not migrated: ', implode( "\n", $report['warnings'] ) );
	}

	public function test_shortcodes_are_listed_with_their_attributes_and_print_nothing(): void {
		$post   = self::factory()->post->create(
			[
				'post_title'   => 'Home',
				'post_content' => 'Ciao [suite_barrio_popup delay="3000" days="1"]',
			]
		);
		$report = $this->migrate( 'popups' );

		$this->assertContains( "Shortcodes that now print nothing, because the popup is in every page. If they set delay or days, set the days in Popups → Options: post {$post} \"Home\": [suite_barrio_popup delay=\"3000\" days=\"1\"].", $report['warnings'] );
		$this->assertSame( 'Ciao ', do_shortcode( 'Ciao [suite_barrio_popup delay="3000" days="1"][wpb_banner]' ) );
	}

	public function test_hours_come_from_google_with_the_last_week_of_the_prototype(): void {
		wp_schedule_event( time(), 'daily', 'suite_barrio_cron_aggiorna_orari' );
		$report = $this->migrate( 'hours' );
		$hours  = get_option( 'barrio_hours' );

		$this->assertSame( [ 'google', 'ChIJ123' ], [ $hours['source'], $hours['place_id'] ] );
		$this->assertSame(
			[
				1 => [],
				2 => [
					[
						'open'  => '12:00',
						'close' => '15:00',
					],
					[
						'open'  => '19:00',
						'close' => '01:00',
					],
				],
				3 => [],
				4 => [],
				5 => [],
				6 => [],
				7 => [
					[
						'open'  => '00:00',
						'close' => '00:00',
					],
				],
			],
			$hours['week']
		);
		$this->assertSame(
			[
				'synced_at' => ( new DateTimeImmutable( '2026-11-19 01:00', wp_timezone() ) )->getTimestamp(),
				'name'      => 'Trattoria da Mario',
				'error'     => '',
			],
			$hours['google']
		);
		$this->assertFalse( wp_next_scheduled( 'suite_barrio_cron_aggiorna_orari' ) );
		$this->assertStringStartsWith( 'Opening hours: the API key of Suite Barrio is not migrated.', $report['warnings'][0] );
	}

	public function test_blocks_become_disabled_blocks_and_restrictions_of_every_role_but_administrators(): void {
		$this->migrate( 'blocks' );
		$blocks = get_option( 'barrio_blocks' );
		$editor = $blocks['roles']['editor'];

		$this->assertArrayNotHasKey( 'administrator', $blocks['roles'] );
		$this->assertSame( [ 'editor', 'author', 'contributor', 'subscriber' ], array_keys( $blocks['roles'] ) );
		$this->assertContains( 'core/image', $editor['disabled'] );
		$this->assertNotContains( 'core/paragraph', $editor['disabled'] );
		// Un elenco ha di nuovo le sue voci.
		$this->assertNotContains( 'core/list-item', $editor['disabled'] );
		$this->assertSame( [ true, true, false, false, true ], [ $editor['h1'], $editor['styles'], $editor['code'], $editor['lock'], $editor['panels'] ] );
		$this->assertSame( 'none', $blocks['editors']['page'] );
		$this->assertArrayNotHasKey( 'post', $blocks['editors'] );
	}

	public function test_with_the_restriction_off_only_the_panels_stay_hidden(): void {
		update_option( 'suite_barrio_blocks_settings', [ 'enabled' => false ] + get_option( 'suite_barrio_blocks_settings' ) );
		$this->migrate( 'blocks' );
		$editor = get_option( 'barrio_blocks' )['roles']['editor'];

		$this->assertSame( [ [], false, false, true ], [ $editor['disabled'], $editor['h1'], $editor['styles'], $editor['panels'] ] );
	}

	public function test_ordering_moves_the_types_and_the_order_of_the_terms(): void {
		[ $first, $second, $kept ] = self::factory()->category->create_many( 3 );
		update_term_meta( $first, 'suite_barrio_term_order', 2 );
		update_term_meta( $second, 'suite_barrio_term_order', 1 );
		update_term_meta( $kept, 'suite_barrio_term_order', 5 );
		update_term_meta( $kept, '_barrio_order', 9 );

		$dry = $this->migrate( 'ordering', true );
		$this->assertSame( '', get_term_meta( $first, '_barrio_order', true ) );
		$report = $this->migrate( 'ordering' );

		$this->assertSame( $dry['counts'], $report['counts'] );
		$this->assertSame( [ 3, 2, 1 ], [ $report['counts'][0]['found'], $report['counts'][0]['migrated'], $report['counts'][0]['skipped'] ] );
		$this->assertSame( [ '2', '1', '9' ], [ get_term_meta( $first, '_barrio_order', true ), get_term_meta( $second, '_barrio_order', true ), get_term_meta( $kept, '_barrio_order', true ) ] );
		$this->assertSame(
			[
				'post_types' => [ 'page' ],
				'taxonomies' => [ 'category' ],
			],
			get_option( 'barrio_ordering' )
		);
	}

	public function test_duplicate_stays_only_where_the_prototype_had_it(): void {
		$this->migrate( 'duplicate' );
		$duplicate = get_option( 'barrio_duplicate' );

		$this->assertSame( [ false, true, false ], [ $duplicate['post_types']['post'], $duplicate['post_types']['page'], $duplicate['taxonomies']['category'] ] );
	}

	public function test_alt_text_keeps_the_generation_on_upload_and_not_the_keys(): void {
		$report = $this->migrate( 'alt' );

		$this->assertSame(
			[
				'on_upload' => true,
				'title'     => false,
			],
			get_option( 'barrio_alt' )
		);
		$this->assertStringStartsWith( 'Alt text: the provider, the API keys', $report['warnings'][0] );
	}

	public function test_analytics_take_the_property_check_the_access_and_delete_the_token(): void {
		update_option(
			'barrio_google',
			Crypto::encrypt(
				(string) wp_json_encode(
					[
						'project_id'   => 'trattoria',
						'client_email' => 'barrio@trattoria.iam.gserviceaccount.com',
						'private_key'  => self::$private_key,
					]
				)
			)
		);
		add_filter( 'pre_http_request', [ $this, 'google' ], 10, 3 );

		$dry = $this->migrate( 'analytics', true );
		$this->assertNotFalse( get_option( 'suite_barrio_stats_tokens' ) );
		$report = $this->migrate( 'analytics' );

		$this->assertSame( $dry['warnings'], $report['warnings'] );
		$this->assertSame(
			[
				'property'          => 'properties/123456',
				'replace_dashboard' => true,
			],
			get_option( 'barrio_analytics' )
		);
		$this->assertContains( 'Analytics: the service account does not see properties/123456. Add barrio@trattoria.iam.gserviceaccount.com as Viewer in GA4 → Admin → Property access management, with Google Analytics Data API and Google Analytics Admin API enabled in its project.', $report['warnings'] );
		$this->assertFalse( get_option( 'suite_barrio_stats_tokens' ) );
		$this->assertContains( 'Deleted: the Google refresh token of Suite Barrio. Delete its OAuth client 42.apps.googleusercontent.com in Google Cloud → APIs & Services → Credentials: Barrio Suite does not need it.', $report['notes'] );
	}

	/** @param array<string, mixed> $args */
	public function google( mixed $pre, array $args, string $url ): array {
		$body = str_contains( $url, 'accountSummaries' ) ? [ 'accountSummaries' => [ [ 'propertySummaries' => [ [ 'property' => 'properties/789' ] ] ] ] ] : [
			'access_token' => 'ya29.test',
			'expires_in'   => 3599,
		];
		return [
			'headers'  => [],
			'body'     => (string) wp_json_encode( $body ),
			'response' => [ 'code' => 200 ],
			'cookies'  => [],
		];
	}

	public function test_a_dry_run_reports_the_same_and_writes_nothing(): void {
		$dry = $this->migrate( 'popups', true );

		$this->assertSame( [], PopupRepository::all() );
		$this->assertFalse( get_option( 'barrio_migration_popups_ids' ) );
		$this->assertSame( $dry, $this->migrate( 'popups' ) );
	}

	public function test_a_second_run_keeps_what_is_already_there(): void {
		$this->migrate( 'popups' );
		$this->migrate( 'hours' );
		update_option( 'barrio_hours', [ 'source' => 'manual' ] + get_option( 'barrio_hours' ) );

		$popups = $this->migrate( 'popups' );
		$hours  = $this->migrate( 'hours' );

		$this->assertSame( [ 2, 0, 2 ], [ $popups['counts'][0]['found'], $popups['counts'][0]['migrated'], $popups['counts'][0]['skipped'] ] );
		$this->assertCount( 2, PopupRepository::all() );
		$this->assertContains( 'Opening hours: Barrio Suite already has its own, they stay as they are.', $hours['notes'] );
		$this->assertSame( 'manual', get_option( 'barrio_hours' )['source'] );
	}

	public function test_at_the_end_the_prototype_module_is_off_and_barrio_suite_one_is_on(): void {
		Modules::set( 'popups', false );
		$this->migrate( 'popups' );

		$this->assertSame( 0, get_option( 'suite_barrio_tools_modules' )['popups'] );
		$this->assertTrue( Modules::is_enabled( 'popups' ) );
		$this->assertSame( 'migrated', get_option( 'barrio_migration_popups' ) );
	}

	public function test_without_data_and_with_the_prototype_module_off_it_is_an_error(): void {
		delete_option( 'suite_barrio_orari_settings' );
		delete_option( 'suite_barrio_orari_data' );
		update_option( 'suite_barrio_tools_modules', [ 'orari' => 0 ] );
		Modules::set( 'hours', false );

		$this->assertWPError( barrio_migration_module_run( 'hours', false ) );
		$this->assertFalse( Modules::is_enabled( 'hours' ) );
	}

	// Altrimenti il modulo trattenuto spento non si riaccenderebbe mai, e l'avviso resterebbe.
	public function test_without_data_and_with_the_prototype_module_on_the_modules_are_swapped(): void {
		delete_option( 'suite_barrio_orari_settings' );
		delete_option( 'suite_barrio_orari_data' );
		Modules::set( 'hours', false );

		$dry = $this->migrate( 'hours', true );
		$this->assertFalse( Modules::is_enabled( 'hours' ) );

		$this->assertSame( $dry, $this->migrate( 'hours' ) );
		$this->assertSame( [ 'There are no opening hours of Suite Barrio in this database. Nothing to migrate.' ], $dry['notes'] );
		$this->assertFalse( suite_barrio_tools_module_enabled( 'orari' ) );
		$this->assertTrue( Modules::is_enabled( 'hours' ) );
		$this->assertSame( 'migrated', get_option( 'barrio_migration_hours' ) );
	}

	public function test_while_a_prototype_module_is_on_the_module_is_turned_off_once(): void {
		barrio_migration_modules_boot();

		$this->assertFalse( Modules::is_enabled( 'popups' ) );
		$this->assertSame( 'held', get_option( 'barrio_migration_popups' ) );

		// Riacceso prima della migrazione: è una scelta, e resta.
		Modules::set( 'popups', true );
		barrio_migration_modules_boot();
		$this->assertTrue( Modules::is_enabled( 'popups' ) );
	}

	public function test_with_the_prototype_module_off_the_module_stays_on(): void {
		update_option( 'suite_barrio_tools_modules', [ 'orders' => 0 ] );
		barrio_migration_modules_boot();

		$this->assertTrue( Modules::is_enabled( 'ordering' ) );
		$this->assertFalse( Modules::is_enabled( 'duplicate' ) );
	}

	public function test_changes_reach_the_revalidation_of_the_prototype_also_when_a_period_starts(): void {
		$start = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		PopupRepository::save(
			null,
			[
				'is_enabled' => true,
				'starts_at'  => $start,
				'ends_at'    => null,
				'image_id'   => null,
				'settings'   => [
					'new_tab' => false,
					'days'    => 7,
				],
				'i18n'       => [ 'it' => [ 'title' => 'Natale' ] ],
			]
		);
		do_action( 'barrio_ordering_changed', 'term', 'category' );
		do_action( 'barrio_hours_changed' );

		$this->assertSame( [ 'wp:popup', 'wp:type:category', 'wp:orari' ], $GLOBALS['barrio_test_revalidated'] );
		$time = (int) strtotime( "{$start} UTC" );
		$this->assertSame( $time, wp_next_scheduled( 'barrio_migration_popups_period', [ $time ] ) );
	}

	/** @return array{counts: list<array{data: string, found: int, migrated: int, skipped: int}>, notes: list<string>, warnings: list<string>} */
	private function migrate( string $module, bool $dry_run = false ): array {
		$report = barrio_migration_module_run( $module, $dry_run );
		$this->assertIsArray( $report );
		return $report;
	}

	// I dati dei moduli del prototipo, come li salvava.
	private function prototype(): void {
		global $wpdb;
		update_option( 'suite_barrio_tools_modules', [ 'popups' => 1 ] );
		$wpdb->insert(
			"{$wpdb->prefix}wpb_popups",
			[
				'id'          => 3,
				'title'       => 'Saldi',
				'content'     => "Sconti\n\nfino al 50%",
				'link'        => 'https://shop.it/saldi',
				'link_title'  => 'Scopri',
				'link_target' => '_blank',
				'image_url'   => wp_get_upload_dir()['baseurl'] . '/2026/11/saldi.jpg',
				'active'      => 1,
			]
		);
		$wpdb->insert(
			"{$wpdb->prefix}wpb_popups",
			[
				'id'         => 5,
				'title'      => '',
				'content'    => 'Solo testo',
				'link'       => '#contatti',
				'link_title' => 'Vai',
			]
		);
		$wpdb->insert(
			"{$wpdb->prefix}wpb_popups_translations",
			[
				'popup_id'   => 3,
				'lang'       => 'en',
				'title'      => 'Sales',
				'content'    => '',
				'link'       => '',
				'link_title' => 'Discover',
			]
		);
		$wpdb->insert(
			"{$wpdb->prefix}wpb_banners",
			[
				'id'     => 2,
				'title'  => 'Spedizione gratuita',
				'link'   => '/spedizioni',
				'active' => 1,
			]
		);
		$wpdb->insert(
			"{$wpdb->prefix}wpb_banners",
			[
				'id'    => 4,
				'title' => '',
			]
		);
		$wpdb->insert(
			"{$wpdb->prefix}wpb_banners_translations",
			[
				'banner_id' => 2,
				'lang'      => 'en',
				'title'     => 'Free shipping',
			]
		);
		update_option(
			'suite_barrio_orari_settings',
			[
				'google_api_key' => 'AIza-test',
				'place_id'       => 'ChIJ123',
			]
		);
		update_option(
			'suite_barrio_orari_data',
			[
				'ultimo_aggiornamento' => '19/11/2026 01:00',
				'display_name'         => 'Trattoria da Mario',
				'orari_settimanali'    => [
					'Lunedì'    => [],
					'Martedì'   => [ '12:00 - 15:00', '19:00 - 01:00' ],
					'Mercoledì' => [],
					'Giovedì'   => [],
					'Venerdì'   => [],
					'Sabato'    => [],
					// Un periodo senza chiusura: il prototipo lo scriveva così, aperto tutto il giorno.
					'Domenica'  => [ '00:00 - 00:00' ],
				],
			]
		);
		update_option(
			'suite_barrio_blocks_settings',
			[
				'enabled'              => true,
				'allowed'              => [ 'core/paragraph', 'core/list', 'core/heading' ],
				'gutenberg_post_types' => [ 'post' ],
			]
		);
		update_option(
			'suite_barrio_orders_settings',
			[
				'enabled_post_types' => [ 'page' ],
				'enabled_taxonomies' => [ 'category' ],
			]
		);
		update_option(
			'suite_barrio_duplicate_settings',
			[
				'enabled_post_types' => [ 'page' ],
				'enabled_taxonomies' => [],
			]
		);
		update_option( 'suite_barrio_alt_ai_auto_upload', 1 );
		update_option( 'suite_barrio_alt_ai_api_key', 'sk-ant-test' );
		update_option(
			'suite_barrio_stats_settings',
			[
				'client_id'         => '42.apps.googleusercontent.com',
				'client_secret'     => 'secret',
				'property_id'       => '123456',
				'replace_dashboard' => 1,
			]
		);
		update_option( 'suite_barrio_stats_tokens', [ 'refresh_token' => 'refresh' ] );
	}
}
