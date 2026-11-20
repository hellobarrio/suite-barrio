<?php

namespace BarrioSuite\Database;

use BarrioSuite\Period;
use WP_Error;

/**
 * Ciò che hanno in comune i Repository di popup e banner: la tabella NAME con is_enabled, starts_at ed ends_at, i testi
 * per lingua in NAME_i18n (in CASCADE) e gli accesi in un transient letto nel sito. Ogni scrittura lo svuota e fa
 * partire barrio_{NAME}_changed. La classe dà NAME, LIVE, hydrate(), save() e not_found().
 *
 * @template T of array{id: int, is_enabled: bool, starts_at: string|null, ends_at: string|null}
 */
trait PeriodRepository {

	/** @return T|null */
	public static function get( int $id ): ?array {
		global $wpdb;
		return self::hydrate( Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Db::table( self::NAME ), $id ) ) )[0] ?? null;
	}

	/** @return list<T> I più recenti per primi. */
	public static function all(): array {
		global $wpdb;
		return self::hydrate( Db::rows( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', Db::table( self::NAME ) ) ) );
	}

	/**
	 * Gli accesi non scaduti, i più recenti per primi: quelli che il sito può mostrare. Nel transient senza scadenza,
	 * così nel sito non c'è nessuna query, con la versione del plugin: dopo un aggiornamento che cambia la forma delle
	 * righe si rileggono.
	 *
	 * @return list<T>
	 */
	public static function live(): array {
		$live = get_transient( self::LIVE );
		if ( is_array( $live ) && BARRIO_SUITE_VERSION === ( $live['version'] ?? null ) ) {
			/** @var list<T> */
			return $live['rows'];
		}
		global $wpdb;
		$rows = self::hydrate( Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE is_enabled = 1 AND (ends_at IS NULL OR ends_at > %s) ORDER BY id DESC', Db::table( self::NAME ), gmdate( 'Y-m-d H:i:s' ) ) ) );
		set_transient(
			self::LIVE,
			[
				'version' => BARRIO_SUITE_VERSION,
				'rows'    => $rows,
			]
		);
		return $rows;
	}

	/**
	 * Quello del sito adesso: tra gli accesi nel loro periodo il più recente, così una promozione creata dopo vince su
	 * quella di sempre.
	 *
	 * @return T|null
	 */
	public static function shown(): ?array {
		foreach ( self::live() as $item ) {
			if ( 'active' === self::status( $item ) ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * @param T $item
	 * @return 'off'|'scheduled'|'active'|'expired'
	 */
	public static function status( array $item ): string {
		return Period::status( $item['is_enabled'], $item['starts_at'], $item['ends_at'] );
	}

	// La copia è spenta: si accende dopo averla cambiata.
	public static function duplicate( int $id ): int|WP_Error {
		$item = self::get( $id );
		return null === $item ? self::not_found() : self::save( null, [ 'is_enabled' => false ] + $item );
	}

	// Quanti ne ha cambiati.
	public static function set_enabled( bool $enabled, int ...$ids ): int {
		global $wpdb;
		$count = Db::query( $wpdb->prepare( 'UPDATE %i SET is_enabled = %d, updated_at = %s WHERE id IN (', Db::table( self::NAME ), (int) $enabled, gmdate( 'Y-m-d H:i:s' ) ) . ( implode( ',', $ids ) ?: '0' ) . ')' );
		self::changed();
		return $count;
	}

	// Quanti ne ha eliminati; i testi seguono in CASCADE.
	public static function delete( int ...$ids ): int {
		global $wpdb;
		$count = Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (', Db::table( self::NAME ) ) . ( implode( ',', $ids ) ?: '0' ) . ')' );
		self::changed();
		return $count;
	}

	private static function changed(): void {
		delete_transient( self::LIVE );
		do_action( 'barrio_' . self::NAME . '_changed' );
	}
}
