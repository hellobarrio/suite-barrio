<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Forms\Submission\FileStore;
use BarrioSuite\Plugin;
use WP_REST_Request;
use WP_UnitTestCase;

// Disinstallazione: i dati restano, a meno che il sito non abbia scelto di eliminarli. I DROP TABLE
// si contano e non si eseguono: nei test WordPress li trasforma in DROP TEMPORARY TABLE.
final class UninstallTest extends WP_UnitTestCase {

	/** @var list<string> */
	private array $dropped = [];

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		add_filter(
			'query',
			function ( string $query ): string {
				if ( preg_match( '/^DROP (?:TEMPORARY )?TABLE IF EXISTS `?(\w+)`?/', $query, $m ) ) {
					$this->dropped[] = $m[1];
				}
				return $query;
			}
		);
		wp_mkdir_p( FileStore::dir() . '/2026/11' );
		file_put_contents( FileStore::dir() . '/2026/11/abc', 'data' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		update_option( 'barrio_smtp', [ 'host' => 'smtp.example.com' ] );
	}

	public function tear_down(): void {
		parent::tear_down();
		// I ruoli restano in memoria anche dopo il rollback: si rileggono dal database.
		wp_roles()->for_site();
	}

	public function test_by_default_nothing_is_deleted(): void {
		$this->assertFalse( rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/uninstall' ) )->get_data()['delete_data'] );

		Plugin::uninstall();

		$this->assertSame( [], $this->dropped );
		$this->assertSame( [ 'host' => 'smtp.example.com' ], get_option( 'barrio_smtp' ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'barrio_manage_entries' ) );
		$this->assertFileExists( FileStore::dir() . '/2026/11/abc' );
	}

	public function test_when_chosen_every_table_option_file_and_capability_goes(): void {
		global $wpdb;
		$request = new WP_REST_Request( 'PUT', '/barrio/v1/uninstall' );
		$request->set_body_params( [ 'delete_data' => true ] );
		$this->assertTrue( rest_do_request( $request )->get_data()['delete_data'] );
		$tables = $wpdb->get_col( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'barrio_' ) . '%' ) );

		Plugin::uninstall();

		$this->assertEqualsCanonicalizing( $tables, $this->dropped );
		$this->assertContains( $wpdb->prefix . 'barrio_entries', $this->dropped );
		$this->assertFalse( get_option( 'barrio_smtp' ) );
		$this->assertFalse( get_option( 'barrio_forms_db_version' ) );
		$this->assertFalse( get_option( 'barrio_delete_data' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'barrio_manage_forms' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'barrio_manage_entries' ) );
		$this->assertDirectoryDoesNotExist( FileStore::dir() );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT @@FOREIGN_KEY_CHECKS' ) );
	}

	public function test_only_administrators_choose(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new WP_REST_Request( 'PUT', '/barrio/v1/uninstall' );
		$request->set_body_params( [ 'delete_data' => true ] );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}
}
