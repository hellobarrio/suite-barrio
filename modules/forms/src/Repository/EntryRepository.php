<?php

namespace BarrioSuite\Forms\Repository;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\NoteLevel;
use BarrioSuite\Forms\Submission\FileStore;
use InvalidArgumentException;

/**
 * @phpstan-type Filters array{search?: string, read?: bool|null, lang?: string|null}
 */
final class EntryRepository {

	// Unico punto in cui si scrivono registrazioni. Qui, e non nel database, si garantisce che
	// valori e file appartengano a campi della versione compilata (docs/database.md).
	// Restituisce la registrazione salvata, con id e data: è quella che ricevono i listener.
	public static function create( Entry $entry ): Entry {
		global $wpdb;
		$fields  = Db::col( $wpdb->prepare( 'SELECT id FROM %i WHERE version_id = %d', Db::table( 'form_fields' ), $entry->version_id ) );
		$foreign = array_diff( [ ...array_keys( $entry->values ), ...array_column( $entry->files, 'field_id' ) ], array_map( 'intval', $fields ) );
		if ( $foreign ) {
			throw new InvalidArgumentException( esc_html( 'Fields ' . implode( ', ', $foreign ) . " do not belong to version {$entry->version_id}." ) );
		}

		$now        = gmdate( 'Y-m-d H:i:s' );
		$source_url = self::source_url( $entry->source_url );
		$created_at = $entry->created_at ?? $now;
		$id         = Db::transaction(
			static function () use ( $wpdb, $entry, $now, $source_url, $created_at ): int {
				$id = Db::insert(
					Db::table( 'entries' ),
					[
						'form_id'    => $entry->form_id,
						'version_id' => $entry->version_id,
						'lang'       => $entry->lang,
						'user_id'    => $entry->user_id,
						'source_url' => $source_url,
						'created_at' => $created_at,
					]
				);

				$rows = [];
				foreach ( $entry->values as $field_id => $values ) {
					foreach ( $values as $position => $value ) {
						$rows[] = $wpdb->prepare( '(%d, %d, %d, %s)', $id, $field_id, $position, $value );
					}
				}
				if ( $rows ) {
					Db::query( $wpdb->prepare( 'INSERT INTO %i (entry_id, field_id, position, value) VALUES ', Db::table( 'entry_values' ) ) . implode( ', ', $rows ) );
				}

				foreach ( $entry->files as $file ) {
					Db::insert(
						Db::table( 'entry_files' ),
						[
							...$file,
							'entry_id'   => $id,
							'created_at' => $now,
						]
					);
				}
				return $id;
			}
		);
		return new Entry( $entry->form_id, $entry->version_id, $entry->lang, $entry->values, $entry->files, $entry->user_id, $source_url, $created_at, $id );
	}

	public static function get( int $id ): ?Entry {
		global $wpdb;
		return self::hydrate( Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Db::table( 'entries' ), $id ) ) )[0] ?? null;
	}

	/**
	 * Una pagina dell'elenco, con il totale per la paginazione: la stessa query di localQuery() nel browser.
	 *
	 * @param Filters          $filters
	 * @param string|list<int> $sort 'id', 'created_at', 'lang', o gli id di un campo (uno per versione) per ordinare sul suo valore.
	 * @return array{entries: list<Entry>, total: int}
	 */
	public static function page( int $form_id, array $filters, string|array $sort, string $order, int $page, int $per_page ): array {
		global $wpdb;
		[ $table, $where ] = self::from( $form_id, $filters );
		$direction         = 'asc' === $order ? 'ASC' : 'DESC';
		$join              = '';
		$column            = 'e.' . ( in_array( $sort, [ 'id', 'lang' ], true ) ? $sort : 'created_at' );
		if ( is_array( $sort ) ) {
			// Una registrazione ha i campi di una sola versione: al massimo una riga per registrazione.
			// ponytail: ordina il testo salvato, quindi i numeri come stringhe e le scelte per valore, non per etichetta.
			$join   = $wpdb->prepare( ' LEFT JOIN %i s ON s.entry_id = e.id AND s.position = 0 AND s.field_id', Db::table( 'entry_values' ) ) . self::in( $sort );
			$column = 's.value';
		}
		$total = (int) Db::var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		// Oltre l'ultima pagina (per esempio dopo aver eliminato le sue righe) si mostra l'ultima, come localQuery().
		$page = min( $page, max( 1, (int) ceil( $total / $per_page ) ) );
		return [
			'entries' => self::hydrate( Db::rows( "SELECT e.* FROM {$table}{$join} WHERE {$where} ORDER BY {$column} {$direction}, e.id {$direction}" . $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, ( $page - 1 ) * $per_page ) ) ),
			'total'   => $total,
		];
	}

	/**
	 * Le $size registrazioni successive ad $after, dalla più vecchia: l'export le legge a blocchi
	 * sull'indice form_created, senza OFFSET.
	 *
	 * @param Filters $filters
	 * @return list<Entry>
	 */
	public static function chunk( int $form_id, array $filters, ?Entry $after, int $size = 500 ): array {
		global $wpdb;
		[ $table, $where ] = self::from( $form_id, $filters );
		if ( null !== $after ) {
			$where .= $wpdb->prepare( ' AND (e.created_at > %s OR (e.created_at = %s AND e.id > %d))', $after->created_at, $after->created_at, $after->id );
		}
		return self::hydrate( Db::rows( "SELECT e.* FROM {$table} WHERE {$where} ORDER BY e.created_at, e.id" . $wpdb->prepare( ' LIMIT %d', $size ) ) );
	}

	/**
	 * Le registrazioni con questo indirizzo in un campo email, per gli strumenti Privacy di WordPress.
	 * Con la collation di WordPress il confronto ignora le maiuscole; usa l'indice field_value.
	 *
	 * @return list<Entry> Dalla più vecchia.
	 */
	public static function by_email( string $email, int $limit, int $offset = 0 ): array {
		global $wpdb;
		return self::hydrate(
			Db::rows(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE id IN (SELECT v.entry_id FROM %i v JOIN %i f ON f.id = v.field_id WHERE f.type = %s AND v.value = %s) ORDER BY id LIMIT %d OFFSET %d',
					Db::table( 'entries' ),
					Db::table( 'entry_values' ),
					Db::table( 'form_fields' ),
					'email',
					$email,
					$limit,
					$offset
				)
			)
		);
	}

	/**
	 * Retention: le $limit registrazioni più vecchie inviate prima di $before (UTC), con i loro file.
	 *
	 * @return int Le registrazioni eliminate: meno di $limit quando non ne restano altre.
	 */
	public static function delete_older_than( string $before, int $limit = 200 ): int {
		global $wpdb;
		$ids = array_map( 'intval', Db::col( $wpdb->prepare( 'SELECT id FROM %i WHERE created_at < %s ORDER BY created_at LIMIT %d', Db::table( 'entries' ), $before, $limit ) ) );
		if ( $ids ) {
			self::delete( ...$ids );
		}
		return count( $ids );
	}

	/** @param list<int>|null $ids Null = tutte le registrazioni del form. */
	public static function set_read( int $form_id, ?array $ids, bool $read ): void {
		global $wpdb;
		Db::query( $wpdb->prepare( 'UPDATE %i SET is_read = %d WHERE form_id = %d', Db::table( 'entries' ), (int) $read, $form_id ) . self::only( $ids ) );
	}

	/**
	 * A blocchi, ognuna con i suoi file.
	 *
	 * @param list<int>|null $ids Null = tutte le registrazioni del form.
	 * @return int Le registrazioni eliminate.
	 */
	public static function delete_many( int $form_id, ?array $ids ): int {
		global $wpdb;
		$deleted = 0;
		do {
			$batch = array_map( 'intval', Db::col( $wpdb->prepare( 'SELECT id FROM %i WHERE form_id = %d', Db::table( 'entries' ), $form_id ) . self::only( $ids ) . ' LIMIT 500' ) );
			if ( $batch ) {
				self::delete( ...$batch );
				$deleted += count( $batch );
			}
		} while ( $batch );
		return $deleted;
	}

	// Prima i file sul disco, poi le righe: il RESTRICT su entry_files impedisce di eliminare
	// una registrazione che ha ancora file. Valori e note seguono in CASCADE.
	public static function delete( int ...$ids ): void {
		global $wpdb;
		$in = self::in( $ids );
		foreach ( Db::col( $wpdb->prepare( 'SELECT stored_path FROM %i WHERE entry_id', Db::table( 'entry_files' ) ) . $in ) as $path ) {
			FileStore::delete( $path );
		}
		Db::transaction(
			static function () use ( $wpdb, $in ): void {
				Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE entry_id', Db::table( 'entry_files' ) ) . $in );
				Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE id', Db::table( 'entries' ) ) . $in );
			}
		);
	}

	/** @return list<array{source: string, level: string, message: string, user_id: int|null, created_at: string}> Dalla più vecchia. */
	public static function notes( int $entry_id ): array {
		global $wpdb;
		return array_map(
			static fn( array $row ): array => [
				'source'     => (string) $row['source'],
				'level'      => (string) $row['level'],
				'message'    => (string) $row['message'],
				'user_id'    => null === $row['user_id'] ? null : (int) $row['user_id'],
				'created_at' => (string) $row['created_at'],
			],
			Db::rows( $wpdb->prepare( 'SELECT source, level, message, user_id, created_at FROM %i WHERE entry_id = %d ORDER BY created_at, id', Db::table( 'entry_notes' ), $entry_id ) )
		);
	}

	// $source: 'notification', 'admin', 'system' o lo slug di un'integrazione.
	public static function add_note( int $entry_id, string $source, NoteLevel $level, string $message, ?int $user_id = null ): void {
		Db::insert(
			Db::table( 'entry_notes' ),
			[
				'entry_id'   => $entry_id,
				'source'     => $source,
				'level'      => $level->value,
				'message'    => $message,
				'user_id'    => $user_id,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);
	}

	public static function forget_user( int $user_id ): void {
		global $wpdb;
		Db::transaction(
			static function () use ( $wpdb, $user_id ): void {
				Db::query( $wpdb->prepare( 'UPDATE %i SET user_id = NULL WHERE user_id = %d', Db::table( 'entries' ), $user_id ) );
				Db::query( $wpdb->prepare( 'UPDATE %i SET user_id = NULL WHERE user_id = %d', Db::table( 'entry_notes' ), $user_id ) );
			}
		);
	}

	/**
	 * Le registrazioni di queste righe di entries, con valori e file: due query per tutte.
	 *
	 * @param list<array<string, string|null>> $rows
	 * @return list<Entry>
	 */
	private static function hydrate( array $rows ): array {
		global $wpdb;
		if ( ! $rows ) {
			return [];
		}
		$in     = self::in( array_map( static fn( array $row ): int => (int) $row['id'], $rows ) );
		$values = [];
		foreach ( Db::rows( $wpdb->prepare( 'SELECT entry_id, field_id, value FROM %i WHERE entry_id', Db::table( 'entry_values' ) ) . $in . ' ORDER BY entry_id, field_id, position' ) as $value ) {
			$values[ (int) $value['entry_id'] ][ (int) $value['field_id'] ][] = (string) $value['value'];
		}
		$files = [];
		foreach ( Db::rows( $wpdb->prepare( 'SELECT entry_id, field_id, stored_path, original_name, mime_type, size_bytes FROM %i WHERE entry_id', Db::table( 'entry_files' ) ) . $in . ' ORDER BY id' ) as $file ) {
			$files[ (int) $file['entry_id'] ][] = [
				'field_id'      => (int) $file['field_id'],
				'stored_path'   => (string) $file['stored_path'],
				'original_name' => (string) $file['original_name'],
				'mime_type'     => (string) $file['mime_type'],
				'size_bytes'    => (int) $file['size_bytes'],
			];
		}

		return array_map(
			static fn( array $row ): Entry => new Entry(
				(int) $row['form_id'],
				(int) $row['version_id'],
				(string) $row['lang'],
				$values[ (int) $row['id'] ] ?? [],
				$files[ (int) $row['id'] ] ?? [],
				null === $row['user_id'] ? null : (int) $row['user_id'],
				$row['source_url'],
				$row['created_at'],
				(int) $row['id'],
				'1' === $row['is_read']
			),
			$rows
		);
	}

	/**
	 * FROM e WHERE delle registrazioni del form che passano i filtri, già preparati.
	 * ponytail: la ricerca libera usa LIKE sui valori salvati (una scelta si trova per valore, non per
	 * etichetta); oltre ~100k registrazioni si passa a un indice FULLTEXT su entry_values.value.
	 *
	 * @param Filters $filters
	 * @return array{string, string}
	 */
	private static function from( int $form_id, array $filters ): array {
		global $wpdb;
		$where = $wpdb->prepare( 'e.form_id = %d', $form_id );
		if ( null !== ( $filters['read'] ?? null ) ) {
			$where .= $wpdb->prepare( ' AND e.is_read = %d', (int) $filters['read'] );
		}
		if ( null !== ( $filters['lang'] ?? null ) ) {
			$where .= $wpdb->prepare( ' AND e.lang = %s', $filters['lang'] );
		}
		$search = trim( $filters['search'] ?? '' );
		if ( '' !== $search ) {
			$where .= $wpdb->prepare(
				' AND (e.id = %d OR EXISTS (SELECT 1 FROM %i v WHERE v.entry_id = e.id AND v.value LIKE %s))',
				ctype_digit( $search ) ? (int) $search : 0,
				Db::table( 'entry_values' ),
				'%' . $wpdb->esc_like( $search ) . '%'
			);
		}
		return [ $wpdb->prepare( '%i e', Db::table( 'entries' ) ), $where ];
	}

	/** @param list<int>|null $ids */
	private static function only( ?array $ids ): string {
		return null === $ids ? '' : ' AND id' . self::in( $ids );
	}

	// " IN (1,2,3)", da aggiungere a una query già preparata; mai vuota, che sarebbe un errore di sintassi.
	/** @param array<int> $ids */
	private static function in( array $ids ): string {
		return ' IN (' . ( implode( ',', array_map( 'intval', $ids ) ) ?: '0' ) . ')';
	}

	// Senza query string e frammento, che possono contenere dati personali o di tracciamento
	// (be_url_chk le rifiuta); troncato alla colonna invece di far fallire l'invio.
	private static function source_url( ?string $url ): ?string {
		$url = mb_substr( substr( (string) $url, 0, strcspn( (string) $url, '?#' ) ), 0, 2048 );
		return '' === $url ? null : $url;
	}
}
