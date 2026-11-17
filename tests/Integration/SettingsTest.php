<?php

namespace BarrioSuite\Tests\Integration;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Form → Impostazioni attraverso la REST admin: template delle email, privacy e permessi.
final class SettingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		parent::tear_down();
		// I ruoli restano in memoria anche dopo il rollback: si rileggono dal database, non dalla cache.
		self::flush_cache();
		wp_roles()->for_site();
	}

	public function test_template_and_privacy_are_saved(): void {
		$response = $this->put(
			[
				'email'   => [
					'accent_color' => '#ff0066',
					'footer'       => 'Via Roma 1, Torino',
				],
				'privacy' => [ 'retention_days' => 365 ],
			]
		)->get_data();

		$this->assertSame( [ '#ff0066', '#f0f0f1', 'Via Roma 1, Torino' ], [ $response['email']['accent_color'], $response['email']['background_color'], $response['email']['footer'] ] );
		$this->assertSame( 365, $response['privacy']['retention_days'] );
		$this->assertArrayNotHasKey( 'smtp', $response, 'The SMTP is in Barrio → Settings.' );

		$this->assertSame( 400, $this->put( [ 'email' => [ 'accent_color' => 'red' ] ] )->get_status() );
		$this->put( [ 'email' => [ 'logo' => 'javascript:alert(1)' ] ] );
		$this->assertSame( '', $this->get()['email']['logo'] );
	}

	public function test_only_administrators_manage_settings(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/settings' ) )->get_status() );
		$this->assertSame( 403, $this->put( [ 'privacy' => [ 'retention_days' => 1 ] ] )->get_status() );
	}

	// Permessi: cosa può fare ogni ruolo, nei ruoli di WordPress. Gli amministratori hanno sempre tutto.
	public function test_each_role_gets_the_capabilities_chosen_in_the_settings(): void {
		$settings = $this->get();
		$this->assertSame( [ 'barrio_manage_entries' ], $settings['roles']['editor'], 'From migration 0002.' );
		$this->assertSame( [], $settings['roles']['author'] );
		$this->assertArrayNotHasKey( 'administrator', $settings['roles'] );
		$this->assertSame( 'Administrator', $settings['role_names']['administrator'] );

		$response = $this->put(
			[
				'roles' => [
					'editor'        => [ 'barrio_manage_forms' ],
					'author'        => [ 'barrio_manage_entries' ],
					'administrator' => [],
					'unknown'       => [ 'barrio_manage_forms' ],
				],
			]
		);

		$this->assertSame( [ 'barrio_manage_forms' ], $response->get_data()['roles']['editor'] );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'barrio_manage_entries' ) );
		$this->assertTrue( get_role( 'author' )->has_cap( 'barrio_manage_entries' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'barrio_manage_forms' ), 'Administrators keep everything.' );
		$this->assertSame( [], $response->get_data()['roles']['contributor'], 'Roles not sent stay as they are.' );
		$this->assertSame( 400, $this->put( [ 'roles' => [ 'editor' => [ 'manage_options' ] ] ] )->get_status(), 'Only the capabilities of the module.' );

		// Chi gestisce solo i form vede l'elenco, non le registrazioni.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/forms' ) )->get_status() );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/forms/1/entries' ) )->get_status() );
		$this->assertSame( 403, $this->put( [ 'roles' => [ 'editor' => [ 'barrio_manage_entries' ] ] ] )->get_status() );
	}

	/** @return array<string, mixed> */
	private function get(): array {
		return rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/settings' ) )->get_data();
	}

	/** @param array<string, mixed> $body */
	private function put( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', '/barrio/v1/settings' );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}
}
