<?php

namespace BarrioSuite\Forms\Submission;

use BarrioSuite\Forms\Model\Field;
use RuntimeException;

/**
 * Cartella protetta dei file inviati con i form. I file hanno un nome casuale senza estensione,
 * quindi non si eseguono e non si indovinano; .htaccess e web.config bloccano l'accesso diretto su
 * Apache e IIS. Si scaricano solo dall'admin, con le capability (fase 5).
 */
final class FileStore {

	public static function dir(): string {
		return wp_upload_dir( null, false )['basedir'] . '/barrio-forms-private';
	}

	/**
	 * @param mixed $upload Un elemento di $_FILES, o un file temporaneo creato dal plugin: tmp_name
	 *                      non deve mai venire dai dati inviati dal client.
	 * @return array{tmp_name: string, original_name: string, mime_type: string, size_bytes: int}|string
	 *         Il file controllato, o il messaggio di errore.
	 */
	public static function check( mixed $upload, Field $field ): array|string {
		$error = is_array( $upload ) ? ( $upload['error'] ?? null ) : null;
		if ( in_array( $error, [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true ) ) {
			return self::too_large( $field );
		}
		if ( UPLOAD_ERR_OK !== $error || ! is_string( $upload['tmp_name'] ?? null ) || ! is_string( $upload['name'] ?? null ) || ! is_file( $upload['tmp_name'] ) ) {
			return __( 'The file could not be uploaded. Please try again.', 'barrio-suite' );
		}

		$size = (int) filesize( $upload['tmp_name'] );
		if ( 0 === $size ) {
			return __( 'The file is empty.', 'barrio-suite' );
		}
		if ( $size > self::max_size( $field ) ) {
			return self::too_large( $field );
		}

		// WordPress confronta l'estensione con il contenuto del file (finfo) e rifiuta quelle pericolose.
		$name    = mb_substr( sanitize_file_name( wp_basename( $upload['name'] ) ), -255 );
		$checked = wp_check_filetype_and_ext( $upload['tmp_name'], $name );
		if ( ! $checked['type'] || ! in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), self::extensions( $field ), true ) ) {
			return sprintf(
				/* translators: %s: estensioni ammesse, per esempio "PDF, DOCX". */
				__( 'This file type is not allowed. Accepted formats: %s.', 'barrio-suite' ),
				strtoupper( implode( ', ', self::extensions( $field ) ) )
			);
		}

		return [
			'tmp_name'      => $upload['tmp_name'],
			'original_name' => $name,
			'mime_type'     => $checked['type'],
			'size_bytes'    => $size,
		];
	}

	// Sposta un file già controllato nella cartella protetta e restituisce lo stored_path.
	public static function store( string $tmp_name ): string {
		self::protect();
		$path = gmdate( 'Y/m/' ) . bin2hex( random_bytes( 16 ) );
		$file = self::dir() . '/' . $path;
		// rename() e non move_uploaded_file(): accetta anche i file temporanei (estensione barrio-suite-wpgraphql).
		if ( ! wp_mkdir_p( dirname( $file ) ) || ! rename( $tmp_name, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			throw new RuntimeException( 'Could not store the uploaded file.' );
		}
		return $path;
	}

	public static function delete( string $stored_path ): void {
		$file = self::dir() . '/' . $stored_path;
		wp_delete_file( $file );
		if ( file_exists( $file ) ) {
			throw new RuntimeException( esc_html( "Could not delete {$file}." ) );
		}
	}

	/** @return list<string> Le estensioni ammesse dal campo e da WordPress. */
	public static function extensions( Field $field ): array {
		$allowed = explode( '|', implode( '|', array_keys( get_allowed_mime_types() ) ) );
		return array_values( array_intersect( (array) ( $field->config['extensions'] ?? [] ), $allowed ) );
	}

	// In byte: il limite del campo, se c'è, entro quello del server.
	public static function max_size( Field $field ): int {
		$limit = isset( $field->config['max_size_mb'] ) ? (int) ( (float) $field->config['max_size_mb'] * MB_IN_BYTES ) : PHP_INT_MAX;
		return min( $limit, wp_max_upload_size() );
	}

	private static function too_large( Field $field ): string {
		/* translators: %s: dimensione massima, per esempio "5 MB". */
		return sprintf( __( 'The file is too large. Maximum size: %s.', 'barrio-suite' ), size_format( self::max_size( $field ) ) );
	}

	// Al primo file. Su Nginx .htaccess non conta: protegge il nome casuale (da segnalare nelle Impostazioni).
	private static function protect(): void {
		$dir = self::dir();
		if ( is_file( "{$dir}/index.php" ) ) {
			return;
		}
		wp_mkdir_p( $dir );
		$files = [
			'.htaccess'  => "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<security>\n\t\t\t<authorization>\n\t\t\t\t<remove users=\"*\" roles=\"\" verbs=\"\" />\n\t\t\t\t<add accessType=\"Deny\" users=\"*\" />\n\t\t\t</authorization>\n\t\t</security>\n\t</system.webServer>\n</configuration>\n",
			// Per ultimo: è il segno che la cartella è già protetta.
			'index.php'  => "<?php\n// Silence is golden.\n",
		];
		foreach ( $files as $name => $content ) {
			file_put_contents( "{$dir}/{$name}", $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}
}
