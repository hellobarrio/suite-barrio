<?php

namespace BarrioSuite\Seo\Repository;

use BarrioSuite\Database\Db;
use BarrioSuite\Seo\RedirectCode;

/**
 * L'unico punto che legge e scrive la tabella dei redirect. Il formato della sorgente, i codici e il
 * 410 senza destinazione li garantisce il database (migrazione 0001): una scrittura che non li
 * rispetta, o una sorgente già usata, diventa un'eccezione di Db.
 *
 * @phpstan-type Redirect array{id: int, source: string, target: string|null, code: RedirectCode, note: string|null, is_auto: bool, created_at: string, updated_at: string}
 */
final class RedirectRepository {

	/** @return Redirect|null */
	public static function get( int $id ): ?array {
		global $wpdb;
		return self::hydrate( Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Db::table( 'redirects' ), $id ) )[0] ?? null );
	}

	/** @return Redirect|null Il redirect di una sorgente, con la UNIQUE. */
	public static function find( string $source ): ?array {
		global $wpdb;
		return self::hydrate( Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE source = %s', Db::table( 'redirects' ), $source ) )[0] ?? null );
	}

	public static function create( string $source, ?string $target, RedirectCode $code, ?string $note = null, bool $is_auto = false ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		return Db::insert(
			Db::table( 'redirects' ),
			[
				'source'     => $source,
				'target'     => $target,
				'code'       => $code->value,
				'note'       => $note,
				'is_auto'    => (int) $is_auto,
				'created_at' => $now,
				'updated_at' => $now,
			]
		);
	}

	// is_auto resta com'è: un redirect automatico modificato a mano resta riconoscibile.
	public static function update( int $id, string $source, ?string $target, RedirectCode $code, ?string $note ): void {
		Db::update(
			Db::table( 'redirects' ),
			[
				'source'     => $source,
				'target'     => $target,
				'code'       => $code->value,
				'note'       => $note,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ]
		);
	}

	// Chi portava a $from ora porta a $to: A→B→C diventa A→C.
	public static function retarget( string $from, string $to ): void {
		global $wpdb;
		Db::query( $wpdb->prepare( 'UPDATE %i SET target = %s, updated_at = %s WHERE target = %s COLLATE utf8mb4_bin', Db::table( 'redirects' ), $to, gmdate( 'Y-m-d H:i:s' ), $from ) );
	}

	public static function delete( int ...$ids ): void {
		global $wpdb;
		Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (', Db::table( 'redirects' ) ) . ( implode( ',', $ids ) ?: '0' ) . ')' );
	}

	/**
	 * @param array<string, string|null>|null $row
	 * @return Redirect|null
	 */
	private static function hydrate( ?array $row ): ?array {
		return null === $row ? null : [
			'id'         => (int) $row['id'],
			'source'     => (string) $row['source'],
			'target'     => $row['target'],
			'code'       => RedirectCode::from( (int) $row['code'] ),
			'note'       => $row['note'],
			'is_auto'    => '1' === $row['is_auto'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		];
	}
}
