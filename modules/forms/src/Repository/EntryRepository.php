<?php

namespace BarrioSuite\Forms\Repository;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\NoteLevel;
use BarrioSuite\Forms\Submission\FileStore;
use InvalidArgumentException;

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
		$row = Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Db::table( 'entries' ), $id ) )[0] ?? null;
		if ( null === $row ) {
			return null;
		}

		$values = [];
		foreach ( Db::rows( $wpdb->prepare( 'SELECT field_id, value FROM %i WHERE entry_id = %d ORDER BY field_id, position', Db::table( 'entry_values' ), $id ) ) as $value ) {
			$values[ (int) $value['field_id'] ][] = (string) $value['value'];
		}
		$files = [];
		foreach ( Db::rows( $wpdb->prepare( 'SELECT field_id, stored_path, original_name, mime_type, size_bytes FROM %i WHERE entry_id = %d ORDER BY id', Db::table( 'entry_files' ), $id ) ) as $file ) {
			$files[] = [
				'field_id'      => (int) $file['field_id'],
				'stored_path'   => (string) $file['stored_path'],
				'original_name' => (string) $file['original_name'],
				'mime_type'     => (string) $file['mime_type'],
				'size_bytes'    => (int) $file['size_bytes'],
			];
		}

		return new Entry(
			(int) $row['form_id'],
			(int) $row['version_id'],
			(string) $row['lang'],
			$values,
			$files,
			null === $row['user_id'] ? null : (int) $row['user_id'],
			$row['source_url'],
			$row['created_at'],
			$id
		);
	}

	// Prima i file sul disco, poi le righe: il RESTRICT su entry_files impedisce di eliminare
	// una registrazione che ha ancora file. Valori e note seguono in CASCADE.
	public static function delete( int $id ): void {
		global $wpdb;
		foreach ( Db::col( $wpdb->prepare( 'SELECT stored_path FROM %i WHERE entry_id = %d', Db::table( 'entry_files' ), $id ) ) as $path ) {
			FileStore::delete( $path );
		}
		Db::transaction(
			static function () use ( $wpdb, $id ): void {
				Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE entry_id = %d', Db::table( 'entry_files' ), $id ) );
				Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Db::table( 'entries' ), $id ) );
			}
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

	// Senza query string e frammento, che possono contenere dati personali o di tracciamento
	// (be_url_chk le rifiuta); troncato alla colonna invece di far fallire l'invio.
	private static function source_url( ?string $url ): ?string {
		$url = mb_substr( substr( (string) $url, 0, strcspn( (string) $url, '?#' ) ), 0, 2048 );
		return '' === $url ? null : $url;
	}
}
