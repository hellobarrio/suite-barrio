<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Company\Module;
use BarrioSuite\Modules;
use BarrioSuite\Seo\Schema;
use BarrioSuite\Seo\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Il modulo Dati aziendali: l'anagrafica spostata dall'Organizzazione del SEO, lo schema dell'opzione, il blocco e lo
// shortcode, lo schema SEO e i Permessi.
final class CompanyTest extends WP_UnitTestCase {

	private const ADDRESS = [
		'street'      => '',
		'postal_code' => '',
		'locality'    => '',
		'region'      => '',
		'country'     => '',
		'maps_url'    => '',
	];

	private const OFFICE = [
		'street'      => 'Via Roma 1',
		'postal_code' => '80100',
		'locality'    => 'Napoli',
		'region'      => 'NA',
		'country'     => 'IT',
		'maps_url'    => 'https://maps.app.goo.gl/abc',
	];

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		parent::tear_down();
		self::flush_cache();
		wp_roles()->for_site();
	}

	public function test_the_first_migration_moves_the_details_of_the_organization_here(): void {
		$this->assertSame( [], get_option( 'barrio_company' ), 'A new site starts empty.' );
		$this->assertArrayHasKey( 'barrio_company', wp_load_alloptions(), 'In autoload: the block and the schema read it.' );

		delete_option( 'barrio_company' );
		$address = array_diff_key( self::OFFICE, [ 'maps_url' => '' ] );
		update_option(
			'barrio_seo',
			[
				'organization' => [
					'type'    => 'Restaurant',
					'name'    => 'Trattoria Mario',
					'phone'   => '081 1234567',
					'email'   => 'info@trattoriamario.it',
					'vat_id'  => 'IT01234567890',
					'address' => $address,
					'same_as' => [ 'https://www.instagram.com/trattoriamario' ],
					'logo'    => 7,
				],
			]
		);
		$migration = require dirname( __DIR__, 2 ) . '/modules/company/migrations/0001_option.php';
		$migration();

		$this->assertSame(
			[
				'name'     => 'Trattoria Mario',
				'phone'    => '081 1234567',
				'email'    => 'info@trattoriamario.it',
				'vat_id'   => 'IT01234567890',
				'address'  => $address + [ 'maps_url' => '' ],
				'profiles' => [ 'https://www.instagram.com/trattoriamario' ],
			],
			get_option( 'barrio_company' )
		);
		$this->assertSame(
			[
				'type' => 'Restaurant',
				'logo' => 7,
			],
			get_option( 'barrio_seo' )['organization']
		);
		$this->assertArrayHasKey( 'barrio_company', wp_load_alloptions() );

		update_option( 'barrio_seo', [ 'organization' => [ 'name' => 'Altro' ] ] );
		$migration();
		$this->assertSame( 'Trattoria Mario', Module::settings()['name'], 'Run again, nothing changes.' );
		$this->assertSame( [ 'name' => 'Altro' ], get_option( 'barrio_seo' )['organization'], 'Nothing goes from the organization if it cannot arrive here.' );
	}

	public function test_the_schema_refuses_invalid_emails_urls_and_countries(): void {
		$valid = [
			'email'             => 'info@trattoriamario.it',
			'certified_email'   => 'trattoriamario@pec.it',
			'privacy_email'     => '',
			'address'           => self::OFFICE,
			'operating_address' => [ 'country' => 'IT' ] + self::ADDRESS,
			'profiles'          => [ 'https://www.instagram.com/trattoriamario' ],
		];
		$this->assertSame( 200, $this->request( 'PUT', $valid )->get_status() );

		$invalid = [
			'email'           => [ 'email' => 'info.trattoriamario.it' ],
			'pec'             => [ 'certified_email' => 'trattoriamario.pec.it' ],
			'privacy email'   => [ 'privacy_email' => 'privacy@' ],
			'script map'      => [ 'address' => [ 'maps_url' => 'javascript:alert(1)' ] + self::OFFICE ],
			'map with spaces' => [ 'address' => [ 'maps_url' => 'https://maps google.com' ] + self::OFFICE ],
			'office map'      => [ 'operating_address' => [ 'maps_url' => 'ftp://maps.example' ] + self::ADDRESS ],
			'country'         => [ 'address' => [ 'country' => 'Italy' ] + self::OFFICE ],
			'partial office'  => [ 'operating_address' => [ 'street' => 'Via Roma 1' ] ],
			'unknown key'     => [ 'operating_address' => [ 'phone' => '081' ] + self::ADDRESS ],
			'script profile'  => [ 'profiles' => [ 'javascript:alert(1)' ] ],
			'long line'       => [ 'legal_name' => str_repeat( 'a', 256 ) ],
			'long phone'      => [ 'phone' => str_repeat( '1', 65 ) ],
		];
		foreach ( $invalid as $name => $body ) {
			$this->assertSame( 400, $this->request( 'PUT', $body )->get_status(), $name );
		}
		$this->assertSame( 'trattoriamario@pec.it', Module::settings()['certified_email'], 'Nothing invalid is saved.' );
	}

	public function test_the_data_fall_back_on_the_name_and_drop_empty_offices(): void {
		$changed = 0;
		add_action(
			'barrio_company_changed',
			static function () use ( &$changed ): void {
				++$changed;
			}
		);
		$this->request(
			'PUT',
			[
				'name'     => 'Trattoria Mario',
				'tax_code' => '01234567890',
				'address'  => self::OFFICE,
			]
		);
		$this->request( 'PUT', [ 'tax_code' => '01234567890' ] );
		$this->assertSame( 1, $changed, 'Only a real change.' );

		$data = Module::data();
		$this->assertSame( 'Trattoria Mario', $data['legal_name'], 'An empty company name is the name.' );
		$this->assertSame( self::OFFICE, $data['address'] );
		$this->assertNull( $data['operating_address'], 'Empty: the operating office is the registered office.' );
		$this->assertArrayNotHasKey( 'profiles', $data );

		$this->request(
			'PUT',
			[
				'legal_name'        => 'Trattoria Mario S.r.l.',
				'address'           => [ 'maps_url' => 'https://maps.app.goo.gl/abc' ] + self::ADDRESS,
				'operating_address' => [
					'street'   => 'Via Toledo 2',
					'locality' => 'Napoli',
				] + self::ADDRESS,
			]
		);
		$data = Module::data();
		$this->assertSame( [ 'Trattoria Mario S.r.l.', 'Via Toledo 2' ], [ $data['legal_name'], $data['operating_address']['street'] ?? null ] );
		$this->assertNull( $data['address'], 'A map without an address is no office.' );
	}

	public function test_block_and_shortcode_give_the_same_html_with_the_details_chosen(): void {
		$this->request(
			'PUT',
			[
				'name'                => 'Trattoria Mario',
				'legal_name'          => 'Trattoria <Mario> & Figli S.r.l.',
				'phone'               => '081 123 45 67',
				'email'               => 'info@trattoriamario.it',
				'vat_id'              => 'IT01234567890',
				'tax_code'            => '01234567890',
				'registration_number' => 'NA-123456',
				'share_capital'       => '€ 10.000 i.v.',
				'address'             => self::OFFICE,
			]
		);

		$line = '<p class="barrio-company is-line">Trattoria &lt;Mario&gt; &amp; Figli S.r.l. · Registered office: <a href="https://maps.app.goo.gl/abc">Via Roma 1, 80100 Napoli (NA)</a> · VAT number: IT01234567890 · Tax code: 01234567890 · REA number: NA-123456 · Share capital: € 10.000 i.v.</p>';
		$this->assertSame( $line, do_shortcode( '[barrio_company]' ) );
		$this->assertSame( $line, do_blocks( '<!-- wp:barrio/company /-->' ) );

		$list = '<ul class="barrio-company is-list"><li><a href="tel:0811234567">081 123 45 67</a></li><li><a href="mailto:info@trattoriamario.it">info@trattoriamario.it</a></li><li>Trattoria Mario</li></ul>';
		$this->assertSame( $list, do_shortcode( '[barrio_company fields="phone, email,mobile,unknown,name" layout="list" labels="0"]' ), 'In the order chosen, without the empty and the unknown ones.' );
		$this->assertSame( $list, do_blocks( '<!-- wp:barrio/company {"fields":["phone","email","mobile","unknown","name"],"layout":"list","labels":false} /-->' ) );
	}

	public function test_without_details_nothing_is_shown(): void {
		$this->assertSame( '', do_shortcode( '[barrio_company]' ) );
		$this->assertSame( '', do_blocks( '<!-- wp:barrio/company {"fields":["name","operating_address"]} /-->' ) );
	}

	public function test_the_organization_schema_reads_the_details_from_here_also_with_the_module_off(): void {
		$this->request(
			'PUT',
			[
				'name'       => 'Trattoria Mario',
				'legal_name' => 'Trattoria Mario S.r.l.',
				'phone'      => '081 1234567',
				'vat_id'     => 'IT01234567890',
				'tax_code'   => '01234567890',
				'address'    => self::OFFICE,
				'profiles'   => [ 'https://www.instagram.com/trattoriamario' ],
			]
		);
		$node = static fn(): array => Schema::organization( Settings::get()['organization'], home_url( '/' ), '' );
		$this->assertSame(
			[ 'Trattoria Mario', 'Trattoria Mario S.r.l.', '081 1234567', 'IT01234567890', '01234567890', 'Napoli', [ 'https://www.instagram.com/trattoriamario' ] ],
			[ $node()['name'], $node()['legalName'], $node()['telephone'], $node()['vatID'], $node()['taxID'], $node()['address']['addressLocality'], $node()['sameAs'] ]
		);

		$this->request( 'PUT', [ 'legal_name' => '' ] );
		$this->assertArrayNotHasKey( 'legalName', $node(), 'Not the name again.' );

		Modules::set( 'company', false );
		$this->assertSame( [ 'Trattoria Mario', '01234567890' ], [ $node()['name'], $node()['taxID'] ], 'They are written only there, so they stay.' );
	}

	public function test_editors_edit_them_only_with_the_permission_and_never_change_the_permissions(): void {
		$admin = get_current_user_id();
		$this->assertFalse( get_role( 'editor' )->has_cap( Module::CAP ), 'Only administrators at the start.' );
		$this->assertArrayHasKey( 'editor', $this->request( 'GET' )->get_data()['roles'] );

		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );
		$this->assertSame( 403, $this->request( 'GET' )->get_status() );
		$this->assertSame( 403, $this->request( 'PUT', [ 'tax_code' => '01234567890' ] )->get_status() );

		wp_set_current_user( $admin );
		$this->assertSame( 200, $this->request( 'PUT', [ 'roles' => [ 'editor' => [ Module::CAP ] ] ] )->get_status() );

		wp_set_current_user( $editor );
		$this->assertArrayNotHasKey( 'roles', $this->request( 'GET' )->get_data() );
		$this->assertSame( 200, $this->request( 'PUT', [ 'tax_code' => '01234567890' ] )->get_status() );
		$this->assertSame( 403, $this->request( 'PUT', [ 'roles' => [ 'editor' => [] ] ] )->get_status() );
		$this->assertTrue( get_role( 'editor' )->has_cap( Module::CAP ) );
	}

	public function test_the_dashboard_card_has_the_details_only_for_who_edits_them(): void {
		$this->assertSame( [ '' ], array_values( array_unique( Module::admin_data( [] )['company'] ) ), 'Empty: the card shows dashes.' );
		$this->request(
			'PUT',
			[
				'name'    => 'Trattoria Mario',
				'vat_id'  => 'IT01234567890',
				'email'   => 'info@mario.it',
				'address' => self::OFFICE,
			]
		);
		$this->assertSame(
			[
				'legal_name' => 'Trattoria Mario',
				'vat_id'     => 'IT01234567890',
				'address'    => 'Via Roma 1, 80100 Napoli (NA)',
				'email'      => 'info@mario.it',
			],
			Module::admin_data( [] )['company'],
			'As in the block, without links.'
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertArrayNotHasKey( 'company', Module::admin_data( [] ) );
	}

	/** @param array<string, mixed> $params */
	private function request( string $method, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/barrio/v1/company' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );
		return rest_do_request( $request );
	}
}
