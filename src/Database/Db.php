<?php

namespace BarrioSuite\Database;

use RuntimeException;
use Throwable;

// Le query dei Repository passano da qui: un errore del database diventa un'eccezione,
// così una scrittura fallita non passa inosservata come un `false` ignorato.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- le query arrivano già preparate dai Repository.
final class Db {

	private static int $depth = 0;

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'barrio_' . $name;
	}

	public static function query( string $sql ): int {
		global $wpdb;
		return (int) self::checked( $wpdb->query( $sql ) );
	}

	/** @param array<string, scalar|null> $row */
	public static function insert( string $table, array $row ): int {
		global $wpdb;
		self::checked( $wpdb->insert( $table, $row ) );
		return (int) $wpdb->insert_id;
	}

	public static function var( string $sql ): ?string {
		global $wpdb;
		$value = self::checked( $wpdb->get_var( $sql ) );
		return null === $value ? null : (string) $value;
	}

	/** @return list<string> */
	public static function col( string $sql ): array {
		global $wpdb;
		return array_values( array_map( 'strval', self::checked( $wpdb->get_col( $sql ) ) ) );
	}

	/** @return list<array<string, string|null>> */
	public static function rows( string $sql ): array {
		global $wpdb;
		/** @var list<array<string, string|null>>|null $rows */
		$rows = self::checked( $wpdb->get_results( $sql, ARRAY_A ) );
		return $rows ?? [];
	}

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public static function transaction( callable $callback ): mixed {
		global $wpdb;
		// START TRANSACTION conferma la transazione già aperta: dentro una nostra transazione,
		// o con autocommit spento (lo fanno i test di WordPress), si usa un savepoint.
		$savepoint = self::$depth > 0 || '0' === $wpdb->get_var( 'SELECT @@autocommit' ) ? 'barrio_' . self::$depth : null;
		self::query( $savepoint ? "SAVEPOINT {$savepoint}" : 'START TRANSACTION' );
		++self::$depth;
		try {
			$result = $callback();
			self::query( $savepoint ? "RELEASE SAVEPOINT {$savepoint}" : 'COMMIT' );
			return $result;
		} catch ( Throwable $e ) {
			$wpdb->query( $savepoint ? "ROLLBACK TO SAVEPOINT {$savepoint}" : 'ROLLBACK' );
			throw $e;
		} finally {
			--self::$depth;
		}
	}

	/**
	 * @template T
	 * @param T $result
	 * @return T
	 */
	private static function checked( mixed $result ): mixed {
		global $wpdb;
		if ( false === $result || '' !== $wpdb->last_error ) {
			throw new RuntimeException( esc_html( $wpdb->last_error ?: 'Unknown database error.' ) );
		}
		return $result;
	}
}
