<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Db;
use RuntimeException;
use WP_UnitTestCase;
use wpdb;

// I test di WordPress girano con autocommit spento, quindi lì Db::transaction() usa i savepoint.
// Qui una connessione separata con autocommit attivo, come in produzione, e una tabella
// temporanea che sparisce con la connessione.
final class DbTest extends WP_UnitTestCase {

	private wpdb $main;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->main = $wpdb;
		$wpdb       = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb->query( 'CREATE TEMPORARY TABLE barrio_tx_test (id INT) ENGINE=InnoDB' );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->close();
		$wpdb = $this->main; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	public function test_commits_or_rolls_back(): void {
		$this->fail_inside( static fn() => Db::query( 'INSERT INTO barrio_tx_test VALUES (1)' ) );
		Db::transaction( static fn() => Db::query( 'INSERT INTO barrio_tx_test VALUES (2)' ) );

		$this->assertSame( [ '2' ], $this->ids() );
	}

	public function test_nested_failure_rolls_back_only_the_inner_part(): void {
		Db::transaction(
			function (): void {
				Db::query( 'INSERT INTO barrio_tx_test VALUES (1)' );
				$this->fail_inside( static fn() => Db::query( 'INSERT INTO barrio_tx_test VALUES (2)' ) );
				Db::query( 'INSERT INTO barrio_tx_test VALUES (3)' );
			}
		);

		$this->assertSame( [ '1', '3' ], $this->ids() );
	}

	public function test_database_errors_become_exceptions(): void {
		$this->expectException( RuntimeException::class );
		global $wpdb;
		$wpdb->suppress_errors();
		Db::query( 'INSERT INTO barrio_missing_table VALUES (1)' );
	}

	private function fail_inside( callable $work ): void {
		try {
			Db::transaction(
				static function () use ( $work ): void {
					$work();
					throw new RuntimeException( 'Rollback.' );
				}
			);
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Rollback.', $e->getMessage() );
		}
	}

	/** @return list<string> */
	private function ids(): array {
		global $wpdb;
		return $wpdb->get_col( 'SELECT id FROM barrio_tx_test ORDER BY id' );
	}
}
