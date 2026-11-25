<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Banners\BannerRepository;
use BarrioSuite\Crypto;
use BarrioSuite\Modules;
use BarrioSuite\Popups\PopupRepository;
use DateTimeImmutable;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use wpdb;

// Qui e non in bootstrap.php: con il prototipo già al boot, l'estensione spegnerebbe i moduli in ogni test.
require_once dirname( __DIR__ ) . '/prototype.php';

// Il passaggio dei moduli del prototipo (extensions/barrio-suite-migration, un file per modulo): popup, banner, orari,
// dati aziendali, blocchi, ordinamento, duplicazione, testi alternativi e statistiche, e lo scambio tra i moduli dei due
// plugin.
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
		$GLOBALS['barrio_wpgraphql_tags'] = [];
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

	// Come li lascia la migrazione del SEO: il nome, un altro telefono, la stessa email e la stessa sede, un profilo.
	public function test_company_details_fill_the_empty_fields_and_warn_where_they_differ(): void {
		$this->company();
		$report = $this->migrate( 'company' );

		$this->assertSame(
			[
				'data'     => 'company details',
				'found'    => 19,
				'migrated' => 14,
				'skipped'  => 5,
			],
			$report['counts'][0]
		);
		// L'ordine delle chiavi è quello dell'opzione che c'era.
		$this->assertEquals(
			[
				'name'                => 'Trattoria Mario',
				'legal_name'          => 'Trattoria da Mario S.r.l.',
				'vat_id'              => 'IT01234567890',
				'tax_code'            => '01234567890',
				'registration_number' => 'NA-123456',
				'share_capital'       => '10.000 € i.v.',
				'invoice_code'        => 'ABC1234',
				'phone'               => '081 7654321',
				'mobile'              => '333 1234567',
				'email'               => 'info@trattoriamario.it',
				'certified_email'     => 'trattoriamario@pec.it',
				'privacy_email'       => 'privacy@trattoriamario.it',
				'address'             => [
					'name'        => '',
					'street'      => 'Via Roma 1',
					'postal_code' => '80100',
					'locality'    => 'Napoli',
					'region'      => 'NA',
					'country'     => 'IT',
					'maps_url'    => 'https://maps.app.goo.gl/abc',
					'phones'      => [
						[
							'label' => '',
							'value' => '+39 081.123.4567',
						],
						[
							'label' => 'Prenotazioni',
							'value' => '081 7654321',
						],
					],
					'emails'      => [],
				],
				// La sede operativa del prototipo è la prima di Barrio Suite.
				'offices'             => [
					[
						'name'        => '',
						'street'      => 'Via Toledo 2',
						'postal_code' => '80134',
						'locality'    => 'Napoli',
						'region'      => 'NA',
						'country'     => '',
						'maps_url'    => '',
						'phones'      => [
							[
								'label' => '',
								'value' => '081 5555555',
							],
						],
						'emails'      => [
							[
								'label' => 'Eventi',
								'value' => 'eventi@trattoriamario.it',
							],
						],
					],
				],
				'profiles'            => [ 'https://www.facebook.com/trattoriamario' ],
			],
			get_option( 'barrio_company' )
		);
		// Il nome diverso dalla ragione sociale è normale: nessun avviso.
		$this->assertSame(
			[
				'Company details, phone: Barrio Suite has "081 7654321", which stays; Suite Barrio had "081 1234567".',
				'Company details, profiles: Barrio Suite has "https://www.facebook.com/trattoriamario", which stays; Suite Barrio had "https://www.facebook.com/trattoriamario, https://instagram.com/trattoriamario".',
			],
			$report['warnings']
		);
		$this->assertSame( [ 'Frontend address (Barrio → Settings): https://www.trattoriamario.it.' ], $report['notes'] );
		$this->assertSame( 'https://www.trattoriamario.it', get_option( 'barrio_frontend' )['url'] );
		$this->assertSame( [ 'wp:option' ], array_keys( $GLOBALS['barrio_wpgraphql_tags'] ) );
		$this->assertFalse( suite_barrio_tools_module_enabled( 'info_azienda' ) );
		$this->assertTrue( Modules::is_enabled( 'company' ) );
	}

	// Come su un sito vero: lo schema del SEO ancora quello del modello, Info Azienda con i dati che il sito mostrava.
	public function test_company_details_come_from_info_azienda_before_the_seo_schema(): void {
		update_option( 'barrio_company', [] );
		update_option(
			'suite_barrio_schema_settings',
			[
				'business_name'  => 'Hello Standard',
				'telephone'      => '+39 0173 33125',
				'email'          => 'info@hellobarrio.it',
				'street_address' => 'Via Duccio Galimberti 10b',
				'postal_code'    => '12051',
				'locality'       => 'Alba',
				'region'         => 'CN',
				'country'        => 'IT',
				'facebook'       => 'https://www.facebook.com/hellobarrio/',
			]
		);
		$report  = $this->migrate( 'company' );
		$company = get_option( 'barrio_company' );

		$this->assertSame(
			[ 'Trattoria da Mario S.r.l.', '081 1234567', 'info@trattoriamario.it', 'Via Roma 1', [ '+39 081.123.4567', '081 7654321' ] ],
			[ $company['name'], $company['phone'], $company['email'], $company['address']['street'], array_column( $company['address']['phones'], 'value' ) ]
		);
		$this->assertSame( [ 'https://www.facebook.com/trattoriamario', 'https://instagram.com/trattoriamario' ], $company['profiles'] );
		$this->assertContains( 'Company details, name of the SEO schema: Barrio Suite has "Trattoria da Mario S.r.l.", which stays; Suite Barrio had "Hello Standard".', $report['warnings'] );
		$this->assertContains( 'Company details, address of the SEO schema: Barrio Suite has "Via Roma 1, 80100 Napoli (NA)", which stays; Suite Barrio had "Via Duccio Galimberti 10b, 12051 Alba (CN)".', $report['warnings'] );

		// Lo schema riempie ciò che Info Azienda non ha, e con lo stesso indirizzo dà il paese.
		update_option( 'barrio_company', [] );
		$info = get_option( 'suite_barrio_company_info' );
		unset( $info['telefono'] );
		update_option( 'suite_barrio_company_info', $info );
		update_option(
			'suite_barrio_schema_settings',
			[
				'telephone'      => '081 0000000',
				'street_address' => 'Via Roma 1',
				'postal_code'    => '80100',
				'locality'       => 'Napoli',
				'region'         => 'NA',
				'country'        => 'IT',
			]
		);
		$report  = $this->migrate( 'company' );
		$company = get_option( 'barrio_company' );
		$this->assertSame( [ '081 0000000', 'IT' ], [ $company['phone'], $company['address']['country'] ] );
		$this->assertSame( [], $report['warnings'] );
	}

	public function test_company_contacts_as_plain_values_and_a_warning_for_what_is_not_understood(): void {
		update_option( 'barrio_company', [] );
		update_option(
			'suite_barrio_company_info',
			[
				'sede_telefoni'    => [ '011 1111111', '011 2222222' ],
				'sede_op_telefoni' => 'Ufficio: 02 333',
			] + get_option( 'suite_barrio_company_info' )
		);
		$report  = $this->migrate( 'company' );
		$company = get_option( 'barrio_company' );

		$this->assertSame( [ '011 1111111', '011 2222222' ], array_column( $company['address']['phones'], 'value' ) );
		$this->assertSame( [], $company['offices'][0]['phones'] );
		$this->assertContains( 'Company details, sede_op_telefoni: Barrio Suite does not understand its value, it was not migrated. Add the contacts in Barrio → Company details.', $report['warnings'] );
	}

	public function test_company_details_without_the_operating_office_toggle_and_run_again(): void {
		update_option( 'barrio_company', [] );
		update_option( 'suite_barrio_company_info', [ 'sede_operativa_diversa' => 0 ] + get_option( 'suite_barrio_company_info' ) );

		$dry = $this->migrate( 'company', true );
		$this->assertSame( [], get_option( 'barrio_company' ) );
		$this->assertSame( $dry, $this->migrate( 'company' ) );
		$company = get_option( 'barrio_company' );
		$this->assertSame( [ 'Trattoria da Mario S.r.l.', 'Trattoria da Mario S.r.l.', [] ], [ $company['name'], $company['legal_name'], $company['offices'] ] );
		$this->assertSame( [ 'https://www.facebook.com/trattoriamario', 'https://instagram.com/trattoriamario' ], $company['profiles'] );
		$this->assertSame( [], $dry['warnings'] );

		$again = $this->migrate( 'company' );
		$this->assertSame( [ 16, 0, 16 ], [ $again['counts'][0]['found'], $again['counts'][0]['migrated'], $again['counts'][0]['skipped'] ] );
		$this->assertSame( [], $again['warnings'] );
		$this->assertSame( $company, get_option( 'barrio_company' ) );
	}

	public function test_company_details_add_the_operating_office_once_and_keep_other_offices(): void {
		$toledo = [
			'street'      => 'Via Toledo 2',
			'postal_code' => '80134',
			'locality'    => 'Napoli',
			'region'      => 'NA',
		];
		update_option( 'barrio_company', [ 'offices' => [ [ 'name' => 'Toledo' ] + $toledo ] ] );
		$report  = $this->migrate( 'company' );
		$offices = get_option( 'barrio_company' )['offices'];
		$this->assertSame( [ 'Toledo', [ '081 5555555' ] ], [ $offices[0]['name'], array_column( $offices[0]['phones'], 'value' ) ], 'The same address: its contacts, no other office.' );
		$this->assertCount( 1, $offices );
		$this->assertSame( [], $report['warnings'] );

		$chiaia = [ 'street' => 'Via Chiaia 5' ] + $toledo;
		update_option( 'barrio_company', [ 'offices' => [ $chiaia ] ] );
		$report = $this->migrate( 'company' );
		$this->assertSame( [ 'Via Chiaia 5' ], array_column( get_option( 'barrio_company' )['offices'], 'street' ) );
		$this->assertContains( 'Company details, operating office: Barrio Suite has "Via Chiaia 5, 80134 Napoli (NA)", which stays; Suite Barrio had "Via Toledo 2, 80134 Napoli (NA)".', $report['warnings'] );
	}

	// Un prototipo modificato per un sito: ogni modulo avvisa per un'opzione sua e per una chiave delle sue opzioni che
	// il prototipo non ha mai avuto, uguale nella prova a vuoto. Le colonne delle tabelle in MigrationTest.
	public function test_every_module_warns_about_what_suite_barrio_never_had(): void {
		$never = static fn( string $where, string $key ): string => "{$where}: Suite Barrio never had \"{$key}\" (a version changed for this site?), it was not migrated.";
		$cases = [
			// Modulo, dove, un'opzione nuova, un'opzione con una chiave nuova e il suo dove.
			[ 'popups', 'Popups', 'suite_barrio_popups_style', null, '' ],
			[ 'banners', 'Banners', 'suite_barrio_banners_style', null, '' ],
			[ 'hours', 'Opening hours', 'suite_barrio_orari_note', 'suite_barrio_orari_data', 'Opening hours, suite_barrio_orari_data' ],
			[ 'company', 'Company details', 'suite_barrio_company_logo', 'suite_barrio_company_info', 'Company details' ],
			[ 'blocks', 'Blocks', 'suite_barrio_blocks_roles', 'suite_barrio_blocks_settings', 'Blocks, suite_barrio_blocks_settings' ],
			[ 'ordering', 'Ordering', 'suite_barrio_orders_mode', 'suite_barrio_orders_settings', 'Ordering, suite_barrio_orders_settings' ],
			[ 'duplicate', 'Duplicate', 'suite_barrio_duplicate_mode', 'suite_barrio_duplicate_settings', 'Duplicate, suite_barrio_duplicate_settings' ],
			[ 'alt', 'Alt text', 'suite_barrio_alt_ai_prompt', null, '' ],
			[ 'analytics', 'Analytics', 'suite_barrio_stats_view', 'suite_barrio_stats_settings', 'Analytics, suite_barrio_stats_settings' ],
		];
		foreach ( $cases as [ $module, $where, $option, $keys, $keys_where ] ) {
			update_option( $option, 'x' );
			$expected = [ $never( $where, $option ) ];
			if ( null !== $keys ) {
				update_option( $keys, [ 'extra' => 'x' ] + get_option( $keys ) );
				$expected[] = $never( $keys_where, 'extra' );
			}

			$dry    = $this->migrate( $module, true );
			$report = $this->migrate( $module );

			$this->assertSame( $expected, array_values( preg_grep( '/Suite Barrio never had/', $report['warnings'] ) ), $module );
			$this->assertSame( $expected, array_values( preg_grep( '/Suite Barrio never had/', $dry['warnings'] ) ), $module );
		}
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

	// Barrio → Migration: solo per chi ha manage_options e solo con qualcosa da migrare.
	public function test_the_page_is_only_for_administrators_and_only_with_something_to_migrate(): void {
		$page = static function (): bool {
			barrio_migration_menu();
			return false !== remove_submenu_page( 'barrio', 'barrio-migration' );
		};

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertFalse( $page() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( $page() );
		// Ogni modulo del prototipo spento, nessuno trattenuto e nessun form.
		update_option( 'suite_barrio_tools_modules', array_fill_keys( [ 'seo', ...array_values( BARRIO_MIGRATION_MODULES ) ], 0 ) );
		$this->assertFalse( $page() );
	}

	public function test_the_routes_give_the_reports_of_the_migration_only_to_administrators(): void {
		$request = static function ( string $method, string $route, array $params = [] ): WP_REST_Response {
			$request = new WP_REST_Request( $method, $route );
			$request->set_body_params( $params );
			return rest_get_server()->dispatch( $request );
		};
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 403, $request( 'POST', '/barrio/v1/migration/popups', [ 'dry_run' => true ] )->get_status() );
		$this->assertArrayNotHasKey( 'migration', apply_filters( 'barrio_admin_data', [] ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$steps = apply_filters( 'barrio_admin_data', [] )['migration'];
		// Senza dry_run una richiesta incompleta migrerebbe davvero.
		$this->assertSame( 400, $request( 'POST', '/barrio/v1/migration/popups' )->get_status() );
		$dry   = $this->migrate( 'popups', true );
		$tried = $request( 'POST', '/barrio/v1/migration/popups', [ 'dry_run' => true ] )->get_data();
		$done  = $request( 'POST', '/barrio/v1/migration/popups', [ 'dry_run' => false ] )->get_data();

		$this->assertSame( [ 'forms', 'seo', 'company', 'popups', 'banners', 'hours', 'blocks', 'ordering', 'duplicate', 'alt', 'analytics' ], array_column( $steps, 'id' ) );
		$this->assertSame( 'pending', $steps[3]['status'] );
		$this->assertSame( [ $dry['counts'], $dry['notes'], $dry['warnings'], 'pending' ], [ $tried['rows'], $tried['notes'], $tried['warnings'], $tried['step']['status'] ] );
		$this->assertSame( [ $dry['counts'], 'migrated' ], [ $done['rows'], $done['step']['status'] ] );
		$this->assertCount( 2, PopupRepository::all() );
		$this->assertSame( 400, $request( 'POST', '/barrio/v1/migration/nope', [ 'dry_run' => true ] )->get_status() );

		// Forms scrive con le classi del suo modulo: spento, il passaggio non c'è.
		Modules::set( 'forms', false );
		$this->assertNotContains( 'forms', array_column( barrio_migration_steps(), 'id' ) );
	}

	public function test_a_database_error_comes_with_its_reason_and_a_second_request_waits_for_the_first(): void {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$post = static function (): WP_REST_Response {
			$request = new WP_REST_Request( 'POST', '/barrio/v1/migration/popups' );
			$request->set_body_params( [ 'dry_run' => false ] );
			return rest_get_server()->dispatch( $request );
		};

		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->query( "SELECT GET_LOCK('barrio_migration_" . md5( DB_NAME . '.' . $wpdb->prefix ) . "', 0)" );
		$this->assertSame( [ 409, [] ], [ $post()->get_status(), PopupRepository::all() ] );
		$other->close();

		$wpdb->suppress_errors();
		add_filter( 'query', static fn( string $query ): string => str_starts_with( $query, 'INSERT' ) && str_contains( $query, 'barrio_popups' ) ? 'SELECT broken' : $query );
		$response = $post();
		$this->assertSame( [ 500, 'barrio_migration_error' ], [ $response->get_status(), $response->get_data()['code'] ] );
		$this->assertStringContainsString( 'broken', $response->get_data()['message'] );
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
		$this->assertFalse( Modules::is_enabled( 'company' ), 'Info Azienda is always on in Suite Barrio.' );

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

	private function company(): void {
		update_option(
			'barrio_company',
			[
				'name'     => 'Trattoria Mario',
				'phone'    => '081 7654321',
				'email'    => 'info@trattoriamario.it',
				'address'  => [
					'street'      => 'Via Roma 1',
					'postal_code' => '80100',
					'locality'    => 'Napoli',
					'region'      => 'NA',
					'country'     => 'IT',
					'maps_url'    => '',
				],
				'profiles' => [ 'https://www.facebook.com/trattoriamario' ],
			]
		);
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
		update_option(
			'suite_barrio_company_info',
			[
				'ragione_sociale'        => 'Trattoria da Mario S.r.l.',
				'sito_web'               => 'www.trattoriamario.it',
				'telefono'               => '081 1234567',
				'cellulare'              => '333 1234567',
				'email'                  => 'info@trattoriamario.it',
				'email_privacy'          => 'privacy@trattoriamario.it',
				'partita_iva'            => 'IT01234567890',
				'codice_fiscale'         => '01234567890',
				'rea'                    => 'NA-123456',
				'capitale_sociale'       => '10.000 € i.v.',
				'pec'                    => 'trattoriamario@pec.it',
				'codice_univoco'         => 'ABC1234',
				'sede_via'               => 'Via Roma 1',
				'sede_citta'             => 'Napoli',
				'sede_cap'               => '80100',
				'sede_provincia'         => 'NA',
				'sede_google_maps'       => 'https://maps.app.goo.gl/abc',
				'sede_operativa_diversa' => 1,
				'sede_op_via'            => 'Via Toledo 2',
				'sede_op_citta'          => 'Napoli',
				'sede_op_cap'            => '80134',
				'sede_op_provincia'      => 'NA',
				'sede_op_google_maps'    => '',
				// I contatti per sede di un prototipo modificato sul server.
				'sede_telefoni'          => [
					[
						'label' => '',
						'value' => '+39 081.123.4567',
					],
					[
						'label' => 'Prenotazioni',
						'value' => '081 7654321',
					],
					[
						'label' => 'Vuoto',
						'value' => '',
					],
				],
				'sede_email'             => [],
				'sede_op_telefoni'       => [
					[
						'label' => '',
						'value' => '081 5555555',
					],
				],
				'sede_op_email'          => wp_json_encode(
					[
						[
							'label' => 'Eventi',
							'value' => 'eventi@trattoriamario.it',
						],
						[
							'label' => '',
							'value' => 'non valida',
						],
					]
				),
				'facebook'               => 'https://www.facebook.com/trattoriamario',
				'instagram'              => 'https://instagram.com/trattoriamario',
				'linkedin'               => '',
			]
		);
	}
}
