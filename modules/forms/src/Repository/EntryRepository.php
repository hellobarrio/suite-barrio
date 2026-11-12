<?php

namespace BarrioSuite\Forms\Repository;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Submission\FileStore;
use InvalidArgumentException;

final class EntryRepository {

	// Unico punto in cui si scrivono registrazioni. Qui, e non nel database, si garantisce che
	// valori e file appartengano a campi della versione compilata (docs/database.md).
	public static function create( Entry $entry ): int {
		global $wpdb;
		$fields  = Db::col( $wpdb->prepare( 'SELECT id FROM %i WHERE version_id = %d', Db::table( 'form_fields' ), $entry->version_id ) );
		$foreign = array_diff( [ ...array_keys( $entry->values ), ...array_column( $entry->files, 'field_id' ) ], array_map( 'intval', $fields ) );
		if ( $foreign ) {
			throw new InvalidArgumentException( esc_html( 'Fields ' . implode( ', ', $foreign ) . " do not belong to version {$entry->version_id}." ) );
		}

		return Db::transaction(
			static function () use ( $wpdb, $entry ): int {
				$now = gmdate( 'Y-m-d H:i:s' );
				$id  = Db::insert(
					Db::table( 'entries' ),
					[
						'form_id'    => $entry->form_id,
						'version_id' => $entry->version_id,
						'lang'       => $entry->lang,
						'user_id'    => $entry->user_id,
						'source_url' => self::source_url( $entry->source_url ),
						'created_at' => $entry->created_at ?? $now,
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
