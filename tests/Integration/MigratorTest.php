<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Migrator;
use WP_UnitTestCase;
use wpdb;

final class MigratorTest extends WP_UnitTestCase {

	private const FIXTURES = __DIR__ . '/../fixtures/migrations';

	public function set_up(): void {
		parent::set_up();
		$GLOBALS['barrio_migrations_ran'] = [];
	}

	public function test_runs_pending_migrations_in_order_once(): void {
		$migrator = new Migrator( 'test', self::FIXTURES . '/ok' );
		$this->assertFalse( $migrator->is_current() );

		$this->assertNull( $migrator->run() );
		$this->assertNull( $migrator->run() );

		$this->assertSame( [ '0001_first.php', '0002_second.php' ], $GLOBALS['barrio_migrations_ran'] );
		$this->assertSame( 2, $migrator->version() );
		$this->assertTrue( $migrator->is_current() );
	}

	public function test_stops_at_failed_migration(): void {
		$migrator = new Migrator( 'test', self::FIXTURES . '/failing' );

		$error = $migrator->run();

		$this->assertSame( '0002_broken.php: Table already exists', $error?->get_error_message() );
		$this->assertSame( [ '0001_first.php' ], $GLOBALS['barrio_migrations_ran'] );
		$this->assertSame( 1, $migrator->version() );
	}

	public function test_does_nothing_while_another_connection_holds_the_lock(): void {
		global $wpdb;
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->query( "SELECT GET_LOCK('barrio_migrate_" . md5( DB_NAME . '.' . $wpdb->prefix . 'test' ) . "', 0)" );

		$this->assertNull( ( new Migrator( 'test', self::FIXTURES . '/ok' ) )->run() );
		$this->assertSame( [], $GLOBALS['barrio_migrations_ran'] );

		$other->close();
	}

	public function test_missing_folder_means_no_migrations(): void {
		global $wpdb;
		wp_cache_delete( 'notoptions', 'options' );
		$queries = $wpdb->num_queries;
		$this->assertTrue( ( new Migrator( 'test', self::FIXTURES . '/missing' ) )->is_current() );
		$this->assertSame( $queries, $wpdb->num_queries, 'No version to read.' );
	}
}
