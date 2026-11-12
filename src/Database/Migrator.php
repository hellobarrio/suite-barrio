<?php

namespace BarrioSuite\Database;

use Throwable;
use WP_Error;

/**
 * Aggiorna lo schema di un modulo eseguendo in ordine i file NNNN_nome.php della sua cartella.
 * Ogni file restituisce `static function ( \wpdb $wpdb ): void` e lancia un'eccezione se fallisce.
 * Il DDL di MySQL non si annulla con un rollback: le migrazioni devono essere idempotenti.
 */
final class Migrator {

	public function __construct(
		private readonly string $module,
		private readonly string $dir,
	) {}

	public function version(): int {
		return (int) get_option( $this->option(), 0 );
	}

	public function is_current(): bool {
		return $this->version() >= (int) array_key_last( $this->files() );
	}

	public function run(): ?WP_Error {
		if ( $this->is_current() ) {
			return null;
		}

		global $wpdb;
		// I nomi dei lock valgono per tutto il server MySQL, quindi includono il database;
		// md5 li tiene entro i 64 caratteri ammessi.
		$lock = 'barrio_migrate_' . md5( DB_NAME . '.' . $wpdb->prefix . $this->module ); // @phpstan-ignore constant.notFound (definita in wp-config.php)
		if ( '1' !== $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return null; // Un'altra richiesta sta già migrando.
		}

		try {
			// Dal database e non dalla cache delle opzioni: un'altra richiesta può aver appena migrato.
			$current = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $this->option() )
			);
			foreach ( $this->files() as $version => $file ) {
				if ( $version <= $current ) {
					continue;
				}
				try {
					( require $file )( $wpdb );
				} catch ( Throwable $e ) {
					return new WP_Error( 'barrio_migration_failed', basename( $file ) . ': ' . $e->getMessage() );
				}
				update_option( $this->option(), $version, true );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}

		return null;
	}

	private function option(): string {
		return "barrio_{$this->module}_db_version";
	}

	/** @return array<int, string> */
	private function files(): array {
		$files = [];
		foreach ( glob( $this->dir . '/[0-9][0-9][0-9][0-9]_*.php' ) ?: [] as $path ) {
			$files[ (int) basename( $path ) ] = $path;
		}
		return $files;
	}
}
