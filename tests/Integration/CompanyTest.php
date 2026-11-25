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
		'name'        => '',
		'street'      => '',
		'postal_code' => '',
		'locality'    => '',
		'region'      => '',
		'country'     => '',
		'maps_url'    => '',
		'phones'      => [],
		'emails'      => [],
	];

	private const OFFICE = [
		'name'        => '',
		'street'      => 'Via Roma 1',
		'postal_code' => '80100',
		'locality'    => 'Napoli',
		'region'      => 'NA',
		'country'     => 'IT',
		'maps_url'    => 'https://maps.app.goo.gl/abc',
		'phones'      => [],
		'emails'      => [],
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
		$address = array_diff_key( self::OFFICE, array_flip( [ 'name', 'maps_url', 'phones', 'emails' ] ) );
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
			'email'           => 'info@trattoriamario.it',
			'certified_email' => 'trattoriamario@pec.it',
			'privacy_email'   => '',
			'address'         => [
				'phones' => [
					[
						'label' => 'Sales',
						'value' => str_repeat( '1', 64 ),
					],
				],
			] + self::OFFICE,
			'offices'         => [
				[
					'name'    => 'Milan warehouse',
					'country' => 'IT',
					'emails'  => [
						[
							'label' => '',
							'value' => 'info@trattoriamario.it',
						],
					],
				] + self::ADDRESS,
			],
			'profiles'        => [ 'https://www.instagram.com/trattoriamario' ],
		];
		$this->assertSame( 200, $this->request( 'PUT', $valid )->get_status() );

		$invalid = [
			'email'            => [ 'email' => 'info.trattoriamario.it' ],
			'pec'              => [ 'certified_email' => 'trattoriamario.pec.it' ],
			'privacy email'    => [ 'privacy_email' => 'privacy@' ],
			'script map'       => [ 'address' => [ 'maps_url' => 'javascript:alert(1)' ] + self::OFFICE ],
			'map with spaces'  => [ 'address' => [ 'maps_url' => 'https://maps google.com' ] + self::OFFICE ],
			'office map'       => [ 'offices' => [ [ 'maps_url' => 'ftp://maps.example' ] + self::ADDRESS ] ],
			'country'          => [ 'address' => [ 'country' => 'Italy' ] + self::OFFICE ],
			'partial office'   => [ 'offices' => [ [ 'street' => 'Via Roma 1' ] ] ],
			'unknown key'      => [ 'offices' => [ [ 'phone' => '081' ] + self::ADDRESS ] ],
			'not a list'       => [ 'offices' => self::OFFICE ],
			'office email'     => [
				'address' => [
					'emails' => [
						[
							'label' => '',
							'value' => 'info@',
						],
					],
				] + self::OFFICE,
			],
			'long office tel'  => [
				'address' => [
					'phones' => [
						[
							'label' => '',
							'value' => str_repeat( '1', 65 ),
						],
					],
				] + self::OFFICE,
			],
			'empty office tel' => [
				'address' => [
					'phones' => [
						[
							'label' => 'Sales',
							'value' => '',
						],
					],
				] + self::OFFICE,
			],
			'contact no label' => [ 'address' => [ 'phones' => [ [ 'value' => '081' ] ] ] + self::OFFICE ],
			'old office'       => [ 'address' => array_diff_key( self::OFFICE, [ 'name' => '' ] ) ],
			'script profile'   => [ 'profiles' => [ 'javascript:alert(1)' ] ],
			'long line'        => [ 'legal_name' => str_repeat( 'a', 256 ) ],
			'long phone'       => [ 'phone' => str_repeat( '1', 65 ) ],
		];
		foreach ( $invalid as $name => $body ) {
			$this->assertSame( 400, $this->request( 'PUT', $body )->get_status(), $name );
		}
		$this->assertSame( 'trattoriamario@pec.it', Module::settings()['certified_email'], 'Nothing invalid is saved.' );
		$this->assertSame( [ $valid['address'], $valid['offices'] ], [ Module::settings()['address'], Module::settings()['offices'] ] );

		$old = array_diff_key( self::OFFICE, array_flip( [ 'name', 'phones', 'emails' ] ) );
		update_option(
			'barrio_company',
			[
				'address' => $old,
				'offices' => [ $old ],
			]
		);
		$this->assertEquals( [ self::OFFICE ], Module::settings()['offices'], 'An office saved before the name and the contacts gets them empty.' );
		$this->assertEquals( self::OFFICE, Module::settings()['address'] );
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
		$this->assertSame( [], $data['offices'], 'None: the registered office is the only one.' );
		$this->assertArrayNotHasKey( 'profiles', $data );

		$this->request(
			'PUT',
			[
				'legal_name' => 'Trattoria Mario S.r.l.',
				'address'    => [ 'maps_url' => 'https://maps.app.goo.gl/abc' ] + self::ADDRESS,
				'offices'    => [
					[ 'name' => 'Only a name' ] + self::ADDRESS,
					[
						'street'   => 'Via Toledo 2',
						'locality' => 'Napoli',
					] + self::ADDRESS,
				],
			]
		);
		$data = Module::data();
		$this->assertSame( [ 'Trattoria Mario S.r.l.', [ 'Via Toledo 2' ] ], [ $data['legal_name'], array_column( $data['offices'], 'street' ) ] );
		$this->assertNull( $data['address'], 'A map without an address is no office.' );

		$this->request(
			'PUT',
			[
				'address' => [
					'phones' => [
						[
							'label' => '',
							'value' => '081 1234567',
						],
					],
				] + self::ADDRESS,
			]
		);
		$this->assertNull( Module::data()['address'], 'Neither are contacts.' );
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

		$this->request(
			'PUT',
			[
				'address' => [
					'phones' => [
						[
							'label' => 'Sales <b>',
							'value' => '+39 011.48.13.111',
						],
						[
							'label' => '',
							'value' => '+39 011.48.13.133',
						],
					],
					'emails' => [
						[
							'label' => '',
							'value' => 'torino@trattoriamario.it',
						],
					],
				] + self::OFFICE,
			]
		);
		$office   = '<a href="https://maps.app.goo.gl/abc">Via Roma 1, 80100 Napoli (NA)</a>';
		$contacts = [ 'Sales &lt;b&gt;: <a href="tel:+390114813111">+39 011.48.13.111</a>', '<a href="tel:+390114813133">+39 011.48.13.133</a>', '<a href="mailto:torino@trattoriamario.it">torino@trattoriamario.it</a>' ];
		$line     = '<p class="barrio-company is-line">Registered office: ' . implode( ' · ', [ $office, ...$contacts ] ) . ' · VAT number: IT01234567890</p>';
		$this->assertSame( $line, do_shortcode( '[barrio_company fields="address,vat_id"]' ), 'The contacts follow their office.' );
		$this->assertSame( $line, do_blocks( '<!-- wp:barrio/company {"fields":["address","vat_id"]} /-->' ) );
		$list = '<ul class="barrio-company is-list"><li>' . implode( '<br>', [ $office, ...$contacts ] ) . '</li></ul>';
		$this->assertSame( $list, do_shortcode( '[barrio_company fields="address" layout="list" labels="0"]' ), 'In a list, under it.' );
		$this->assertSame( $list, do_blocks( '<!-- wp:barrio/company {"fields":["address"],"layout":"list","labels":false} /-->' ) );

		$this->request(
			'PUT',
			[
				'address' => [ 'name' => 'Head office' ] + self::OFFICE,
				'offices' => [
					[
						'name'     => 'Milan <warehouse>',
						'street'   => 'Via Meucci 3',
						'locality' => 'Sesto San Giovanni',
						'phones'   => [
							[
								'label' => '',
								'value' => '02 1234567',
							],
						],
					] + self::ADDRESS,
					[
						'street'   => 'Via Toledo 2',
						'locality' => 'Napoli',
					] + self::ADDRESS,
				],
			]
		);
		$list = '<ul class="barrio-company is-list"><li>Head office: ' . $office . '</li><li>Milan &lt;warehouse&gt;: Via Meucci 3, Sesto San Giovanni<br><a href="tel:021234567">02 1234567</a></li><li>Operating office: Via Toledo 2, Napoli</li></ul>';
		$this->assertSame( $list, do_shortcode( '[barrio_company fields="address,offices" layout="list"]' ), 'Every operating office on its own, the name in place of the label.' );
		$this->assertSame( $list, do_blocks( '<!-- wp:barrio/company {"fields":["address","offices"],"layout":"list"} /-->' ) );
		$this->assertSame(
			'<p class="barrio-company is-line">Via Meucci 3, Sesto San Giovanni · <a href="tel:021234567">02 1234567</a> · Via Toledo 2, Napoli</p>',
			do_shortcode( '[barrio_company fields="offices" labels="0"]' ),
			'Without labels, without names.'
		);
	}

	public function test_without_details_nothing_is_shown(): void {
		$this->assertSame( '', do_shortcode( '[barrio_company]' ) );
		$this->assertSame( '', do_blocks( '<!-- wp:barrio/company {"fields":["name","offices"]} /-->' ) );

		// Il solo paese non è una sede: niente "Registered office: " vuoto.
		update_option(
			'barrio_company',
			[
				'vat_id'  => 'IT0123',
				'address' => [ 'country' => 'IT' ],
			]
		);
		$this->assertSame( '<p class="barrio-company is-line">VAT number: IT0123</p>', do_shortcode( '[barrio_company fields="address,vat_id"]' ) );
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
