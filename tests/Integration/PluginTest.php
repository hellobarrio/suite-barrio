<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Admin;
use BarrioSuite\Forms\Module;
use BarrioSuite\Plugin;
use BarrioSuite\Requirements;
use WP_UnitTestCase;

final class PluginTest extends WP_UnitTestCase {

	public function test_boots_on_plugins_loaded(): void {
		$this->assertSame( 10, has_action( 'plugins_loaded', [ Plugin::class, 'boot' ] ) );
	}

	public function test_forms_module_boots_once_its_schema_is_current(): void {
		$this->assertSame( '4', get_option( 'barrio_forms_db_version' ) );
		$this->assertSame( 10, has_action( 'deleted_user', [ Module::class, 'forget_user' ] ) );
	}

	public function test_capabilities_are_assigned_by_a_migration(): void {
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'barrio_manage_forms' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'barrio_manage_entries' ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'barrio_manage_entries' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'barrio_manage_forms' ) );
		$this->assertFalse( get_role( 'author' )->has_cap( 'barrio_manage_entries' ) );
	}

	// Chi non ha la capability di alcun modulo avrebbe solo la Bacheca, vuota.
	public function test_the_menu_is_for_who_can_open_more_than_the_dashboard(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$names = [ 'menu', 'submenu', '_registered_pages', '_parent_pages', 'admin_page_hooks', '_wp_submenu_nopriv' ];
		$saved = array_map( static fn( string $name ): mixed => $GLOBALS[ $name ] ?? null, $names );
		$shown = [];
		foreach ( [ 'subscriber', 'contributor', 'author', 'editor', 'administrator' ] as $role ) {
			foreach ( $names as $name ) {
				$GLOBALS[ $name ] = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- i menu di WordPress.
			}
			remove_all_actions( 'load-toplevel_page_barrio' );
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
			do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook di WordPress.
			// Il menu, e la pagina che dal suo indirizzo risponde 403.
			$shown[ $role ] = [ in_array( 'barrio', array_column( $GLOBALS['menu'], 2 ), true ), isset( $GLOBALS['wp_filter']['load-toplevel_page_barrio'][0] ) ];
		}
		foreach ( $names as $index => $name ) {
			$GLOBALS[ $name ] = $saved[ $index ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- i menu di WordPress.
		}

		$this->assertSame( 10, has_action( 'load-toplevel_page_barrio', [ Admin::class, 'enqueue' ] ), 'The hook of the Dashboard.' );
		$this->assertSame(
			[
				'subscriber'    => [ false, true ],
				'contributor'   => [ false, true ],
				'author'        => [ true, false ],
				'editor'        => [ true, false ],
				'administrator' => [ true, false ],
			],
			$shown
		);
	}

	// In CI gira su MySQL 8.0 e MariaDB 10.5, le versioni minime.
	public function test_database_meets_requirements(): void {
		$this->assertSame( [], Requirements::errors() );
	}

	// Garantisce che il ramo "con WPGraphQL" della CI lo carichi davvero.
	public function test_wpgraphql_loaded_only_when_requested(): void {
		$this->assertSame( (bool) getenv( 'WP_GRAPHQL' ), class_exists( 'WPGraphQL' ) );
	}
}
