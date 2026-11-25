<?php

namespace BarrioSuite\Ordering;

use BarrioSuite\Database\Db;

/**
 * L'unico punto del modulo che usa $wpdb. L'ordine dei contenuti è menu_order, il campo del core che usano già temi,
 * menu e WPGraphQL; quello dei termini è il meta _barrio_order, perché WordPress non ne ha uno (come WooCommerce).
 * Le posizioni sono quelle dell'elenco intero, con i genitori prima dei figli: anche una query senza gerarchia esce
 * nell'ordine dell'albero.
 *
 * @phpstan-type Item array{id: int, title: string, status: string|null, parent: int}
 */
final class OrderRepository {

	public const TERM_META = '_barrio_order';

	/**
	 * Nell'ordine delle query (Module::posts()): menu_order, poi il più recente.
	 *
	 * @param list<string> $statuses
	 * @return list<Item>
	 */
	public static function posts( string $type, array $statuses ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- un %s per ogni stato.
		$sql = $wpdb->prepare( 'SELECT ID, post_title, post_status, post_parent FROM %i WHERE post_type = %s AND post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ') ORDER BY menu_order, post_date DESC', $wpdb->posts, $type, ...$statuses );
		return array_map(
			static fn( array $row ): array => [
				'id'     => (int) $row['ID'],
				'title'  => (string) $row['post_title'],
				'status' => (string) $row['post_status'],
				'parent' => (int) $row['post_parent'],
			],
			Db::rows( $sql )
		);
	}

	/**
	 * Nell'ordine delle query (term_clauses()): quelli senza ordine in fondo, per nome.
	 *
	 * @return list<Item>
	 */
	public static function terms( string $taxonomy ): array {
		global $wpdb;
		$clauses = self::term_clauses(
			[
				'join'    => $wpdb->prepare( ' JOIN %i AS tt ON tt.term_id = t.term_id', $wpdb->term_taxonomy ),
				'orderby' => '',
			]
		);
		return array_map(
			static fn( array $row ): array => [
				'id'     => (int) $row['term_id'],
				'title'  => (string) $row['name'],
				'status' => null,
				'parent' => (int) $row['parent'],
			],
			Db::rows( $wpdb->prepare( 'SELECT t.term_id, t.name, tt.parent FROM %i AS t', $wpdb->terms ) . $clauses['join'] . $wpdb->prepare( ' WHERE tt.taxonomy = %s ', $taxonomy ) . $clauses['orderby'] )
		);
	}

	/**
	 * Il JOIN e l'ORDER BY dell'ordine dei termini, per terms() e per WP_Term_Query (Module::terms()), dove la
	 * tabella dei termini è t. Il verso della query (order) si aggiunge in fondo e vale per il nome.
	 *
	 * @param array<string, string> $clauses
	 * @return array<string, string>
	 */
	public static function term_clauses( array $clauses ): array {
		global $wpdb;
		$clauses['join']   .= $wpdb->prepare( ' LEFT JOIN %i AS barrio_order ON barrio_order.term_id = t.term_id AND barrio_order.meta_key = %s', $wpdb->termmeta, self::TERM_META );
		$clauses['orderby'] = 'ORDER BY barrio_order.meta_value IS NULL, CAST(barrio_order.meta_value AS SIGNED), t.name';
		return $clauses;
	}

	/**
	 * Un'istruzione sola, atomica anche dove wp_posts non è InnoDB. Non tocca post_modified e
	 * non fa partire gli hook di salvataggio.
	 *
	 * @param list<int> $ids
	 */
	public static function save_posts( string $type, array $ids ): void {
		global $wpdb;
		if ( ! $ids ) {
			return;
		}
		$list = implode( ',', array_map( 'intval', $ids ) );
		Db::query( $wpdb->prepare( 'UPDATE %i SET menu_order = FIELD(ID, ', $wpdb->posts ) . $list . $wpdb->prepare( ') WHERE post_type = %s AND ID IN (', $type ) . $list . ')' );
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}
	}

	/**
	 * Due istruzioni in una transazione. Dove termmeta non è InnoDB un errore tra le due lascia questi termini senza
	 * ordine (in fondo, per nome), non con un ordine a metà.
	 *
	 * @param list<int> $ids
	 */
	public static function save_terms( string $taxonomy, array $ids ): void {
		global $wpdb;
		if ( ! $ids ) {
			return;
		}
		$values = array_map( static fn( int $id, int $index ): string => $wpdb->prepare( '(%d, %s, %d)', $id, self::TERM_META, $index + 1 ), $ids, array_keys( $ids ) );
		Db::transaction(
			static function () use ( $wpdb, $ids, $values ): void {
				Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key = %s AND term_id IN (', $wpdb->termmeta, self::TERM_META ) . implode( ',', array_map( 'intval', $ids ) ) . ')' );
				Db::query( $wpdb->prepare( 'INSERT INTO %i (term_id, meta_key, meta_value) VALUES ', $wpdb->termmeta ) . implode( ',', $values ) );
			}
		);
		wp_cache_delete_multiple( $ids, 'term_meta' );
		clean_term_cache( $ids, $taxonomy );
	}
}
