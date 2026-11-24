<?php

namespace BarrioSuite\Tests\Integration;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class ExtensionsTest extends WP_UnitTestCase {

	private int $calls = 0;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		// In una rete i plugin li gestisce solo il super admin.
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		delete_site_transient( 'barrio_extensions_catalog' );
		// La directory di WordPress.org: i plugin dell'autore, Barrio Suite compreso.
		add_filter(
			'plugins_api',
			function ( mixed $result, string $action, object $args ): mixed {
				if ( 'query_plugins' !== $action || 'hellobarrio' !== $args->author ) {
					return $result;
				}
				++$this->calls;
				return (object) [
					'plugins' => [
						[
							'slug'              => 'barrio-suite',
							'name'              => 'Barrio Suite',
							'short_description' => 'Forms.',
							'version'           => '0.1.0',
						],
						[
							'slug'              => 'barrio-suite-mailchimp',
							'name'              => 'Barrio Suite &#8211; Mailchimp',
							'short_description' => 'Adds who fills in a form to Mailchimp.',
							'version'           => '0.1.0',
						],
					],
				];
			},
			10,
			3
		);
		// Installata a mano, fuori dalla directory.
		wp_cache_set(
			'plugins',
			[
				'' => [
					'barrio-suite-migration/barrio-suite-migration.php' => [
						'Name'        => 'Barrio Suite Migration',
						'Description' => 'From the prototype.',
						'Version'     => '0.1.0',
					],
				],
			],
			'plugins'
		);
	}

	public function test_the_page_lists_the_directory_and_the_installed_extensions(): void {
		$data = $this->get()->get_data();

		$this->assertNull( $data['error'] );
		$this->assertSame(
			[
				[ 'barrio-suite-mailchimp', 'Barrio Suite – Mailchimp', '0.1.0', null, null, false ],
				[ 'barrio-suite-migration', 'Barrio Suite Migration', null, 'barrio-suite-migration/barrio-suite-migration', '0.1.0', false ],
			],
			array_map( static fn( array $e ): array => [ $e['slug'], $e['name'], $e['version'], $e['plugin'], $e['installed'], $e['active'] ], $data['extensions'] )
		);
		$this->get();
		$this->assertSame( 1, $this->calls, 'The directory is asked once, then cached.' );
	}

	public function test_without_the_directory_the_installed_extensions_remain(): void {
		add_filter( 'plugins_api', static fn(): WP_Error => new WP_Error( 'plugins_api_failed', 'Offline.' ), 20 );

		$data = $this->get()->get_data();

		$this->assertSame( 'Offline.', $data['error'] );
		$this->assertSame( [ 'barrio-suite-migration' ], array_column( $data['extensions'], 'slug' ) );
	}

	public function test_only_who_activates_plugins_sees_the_page(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 403, $this->get()->get_status() );
	}

	private function get(): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/extensions' ) );
	}
}
