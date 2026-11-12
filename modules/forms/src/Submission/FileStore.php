<?php

namespace BarrioSuite\Forms\Submission;

use RuntimeException;

// Cartella protetta dei file inviati con i form. Il salvataggio arriva con la pipeline di invio.
final class FileStore {

	public static function dir(): string {
		return wp_upload_dir( null, false )['basedir'] . '/barrio-forms-private';
	}

	public static function delete( string $stored_path ): void {
		$file = self::dir() . '/' . $stored_path;
		wp_delete_file( $file );
		if ( file_exists( $file ) ) {
			throw new RuntimeException( esc_html( "Could not delete {$file}." ) );
		}
	}
}
