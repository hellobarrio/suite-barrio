<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Plugin;
use BarrioSuite\Requirements;
use WP_UnitTestCase;

final class PluginTest extends WP_UnitTestCase {

	public function test_boots_on_plugins_loaded(): void {
		$this->assertSame( 10, has_action( 'plugins_loaded', [ Plugin::class, 'boot' ] ) );
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
