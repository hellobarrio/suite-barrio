<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Forms\Module;
use BarrioSuite\Plugin;
use BarrioSuite\Requirements;
use WP_UnitTestCase;

final class PluginTest extends WP_UnitTestCase {

	public function test_boots_on_plugins_loaded(): void {
		$this->assertSame( 10, has_action( 'plugins_loaded', [ Plugin::class, 'boot' ] ) );
	}

	public function test_forms_module_boots_once_its_schema_is_current(): void {
		$this->assertSame( '3', get_option( 'barrio_forms_db_version' ) );
		$this->assertSame( 10, has_action( 'deleted_user', [ Module::class, 'forget_user' ] ) );
	}

	public function test_capabilities_are_assigned_by_a_migration(): void {
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'barrio_manage_forms' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'barrio_manage_entries' ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( 'barrio_manage_entries' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'barrio_manage_forms' ) );
		$this->assertFalse( get_role( 'author' )->has_cap( 'barrio_manage_entries' ) );
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
