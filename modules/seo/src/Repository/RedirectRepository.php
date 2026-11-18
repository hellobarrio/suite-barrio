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

	/**
	 * Una pagina della tabella dell'admin, con il totale. La ricerca ignora le maiuscole anche sulla sorgente,
	 * che nel database le distingue.
	 *
	 * @param array{search: string, code: int|null, auto: bool|null} $filters
	 * @return array{redirects: list<Redirect>, total: int}
	 */
	public static function page( array $filters, string $sort, string $order, int $page, int $per_page ): array {
		global $wpdb;
		$table = Db::table( 'redirects' );
		$where = '1 = 1';
		if ( null !== $filters['code'] ) {
			$where .= $wpdb->prepare( ' AND code = %d', $filters['code'] );
		}
		if ( null !== $filters['auto'] ) {
			$where .= $wpdb->prepare( ' AND is_auto = %d', (int) $filters['auto'] );
		}
		$search = trim( $filters['search'] );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND (source COLLATE utf8mb4_unicode_ci LIKE %s OR target LIKE %s OR note LIKE %s)', $like, $like, $like );
		}
		$sort      = in_array( $sort, [ 'source', 'target', 'code', 'updated_at' ], true ) ? $sort : 'updated_at';
		$direction = 'asc' === $order ? 'ASC' : 'DESC';
		$total     = (int) Db::var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		// Oltre l'ultima pagina (dopo aver eliminato le sue righe) si mostra l'ultima, come localQuery().
		$page = min( $page, max( 1, (int) ceil( $total / $per_page ) ) );
		$rows = Db::rows( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$sort} {$direction}, id {$direction}" . $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, ( $page - 1 ) * $per_page ) );
		return [
			'redirects' => array_values( array_filter( array_map( self::hydrate( ... ), $rows ) ) ),
			'total'     => $total,
		];
	}

	/** @return list<Redirect> Tutti, per sorgente: per i frontend headless, che li eseguono da sé. */
	public static function all(): array {
		global $wpdb;
		return array_values( array_filter( array_map( self::hydrate( ... ), Db::rows( $wpdb->prepare( 'SELECT * FROM %i ORDER BY source', Db::table( 'redirects' ) ) ) ) ) );
	}

	public static function count(): int {
		global $wpdb;
		return (int) Db::var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Db::table( 'redirects' ) ) );
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

	// Quanti ne ha eliminati.
	public static function delete( int ...$ids ): int {
		global $wpdb;
		return Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (', Db::table( 'redirects' ) ) . ( implode( ',', $ids ) ?: '0' ) . ')' );
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
