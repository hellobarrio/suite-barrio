<?php

namespace BarrioSuite\Forms\Submission;

use BarrioSuite\Forms\Conditions;
use BarrioSuite\Forms\FieldTypes;
use BarrioSuite\Forms\I18n;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormStatus;
use BarrioSuite\Forms\Model\NoteLevel;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use Throwable;
use WP_Error;

/**
 * Pipeline di invio, unica per REST e GraphQL. Oltre ai valori per field_key, l'input ha i campi di
 * servizio che il form aggiunge da sé (un frontend headless deve inviarli):
 *
 * - _barrio_lang     lingua in cui il visitatore ha letto il form;
 * - _barrio_version  id della versione mostrata: con la cache di pagina può non essere più la corrente;
 * - _barrio_url      pagina di invio;
 * - _barrio_js       "1", impostato dal JavaScript: un bot che invia l'HTML così com'è non lo ha;
 * - _barrio_hp       honeypot: un campo nascosto che solo un bot compila.
 */
final class Submission {

	// ponytail: limite fisso per IP; se un ufficio dietro un solo IP lo raggiunge, va reso configurabile.
	private const RATE_LIMIT  = 10;
	private const RATE_WINDOW = 10 * MINUTE_IN_SECONDS;

	/**
	 * @param array<string, mixed> $input Valori per field_key e campi di servizio _barrio_*.
	 * @param array<string, mixed> $files Elementi di $_FILES per field_key (vedi FileStore::check()).
	 * @param string|null          $ip    Solo per il limite di invii: non si salva.
	 */
	public static function process( Form $form, array $input, array $files = [], ?string $ip = null, ?int $user_id = null ): Entry|WP_Error {
		if ( FormStatus::Active !== $form->status ) {
			return new WP_Error( 'barrio_form_closed', __( 'This form is no longer accepting submissions.', 'barrio-suite' ), [ 'status' => 403 ] );
		}

		$lang    = I18n::resolve( self::scalar( $input['_barrio_lang'] ?? null ) );
		$version = $form->current;
		$shown   = (int) self::scalar( $input['_barrio_version'] ?? null );
		// La versione che il visitatore ha letto, se esiste ancora: è quella che la registrazione deve provare.
		if ( $shown && $shown !== $version->id ) {
			$version = FormRepository::version( $shown, $form->id ) ?? $version;
		}

		$spam = apply_filters( 'barrio_forms_spam_check', self::spam( $input, $ip ), $input, $form );
		if ( $spam instanceof WP_Error ) {
			return $spam;
		}

		$values = [];
		foreach ( $version->fields as $field ) {
			$values[ $field->key ] = FieldTypes::normalize( $field, 'file' === $field->type ? $files[ $field->key ] ?? null : $input[ $field->key ] ?? null );
		}

		// I campi nascosti dalla logica condizionale non sono obbligatori e non si salvano.
		$visible  = Conditions::visibility( $version->fields, $values );
		$errors   = [];
		$accepted = [];
		$uploads  = [];
		foreach ( $version->fields as $field ) {
			if ( ! $visible[ $field->key ] || 'page_break' === $field->type ) {
				continue;
			}
			$error = FieldTypes::validate( $field, $values[ $field->key ] );
			if ( null === $error && 'file' === $field->type && $values[ $field->key ] ) {
				$upload = FileStore::check( $files[ $field->key ] ?? null, $field );
				if ( is_string( $upload ) ) {
					$error = $upload;
				} else {
					$uploads[ (int) $field->id ] = $upload;
				}
			}
			if ( null !== $error ) {
				$errors[ $field->key ] = I18n::pick( $field->i18n, $lang )['error_message'] ?? $error;
			} else {
				$accepted[ $field->key ] = $values[ $field->key ];
			}
		}
		$errors = (array) apply_filters( 'barrio_forms_validate', $errors, $accepted, $form );
		if ( $errors ) {
			return new WP_Error(
				'barrio_invalid',
				__( 'Please correct the highlighted fields.', 'barrio-suite' ),
				[
					'status' => 422,
					'fields' => $errors,
				]
			);
		}

		$entry_values = [];
		foreach ( $version->fields as $field ) {
			if ( 'file' !== $field->type && ( $accepted[ $field->key ] ?? [] ) ) {
				$entry_values[ (int) $field->id ] = $accepted[ $field->key ];
			}
		}
		$url = esc_url_raw( self::scalar( $input['_barrio_url'] ?? null ), [ 'http', 'https' ] );

		// La registrazione si salva prima di qualsiasi effetto collaterale; se fallisce, i file appena
		// spostati si eliminano.
		$stored = [];
		try {
			foreach ( $uploads as $field_id => $upload ) {
				$stored[] = [
					'field_id'      => $field_id,
					'stored_path'   => FileStore::store( $upload['tmp_name'] ),
					'original_name' => $upload['original_name'],
					'mime_type'     => $upload['mime_type'],
					'size_bytes'    => $upload['size_bytes'],
				];
			}
			$entry    = new Entry( $form->id, (int) $version->id, $lang, $entry_values, $stored, $user_id, '' === $url ? null : $url );
			$filtered = apply_filters( 'barrio_forms_entry_data', $entry, $form );
			$entry    = $filtered instanceof Entry ? $filtered : $entry;
			$id       = EntryRepository::create( $entry );
		} catch ( Throwable $e ) {
			foreach ( $stored as $file ) {
				FileStore::delete( $file['stored_path'] );
			}
			throw $e;
		}
		$entry = new Entry( $entry->form_id, $entry->version_id, $entry->lang, $entry->values, $entry->files, $entry->user_id, $entry->source_url, $entry->created_at, $id );

		if ( $ip ) {
			set_transient( self::rate_key( $ip ), self::submissions( $ip ) + 1, self::RATE_WINDOW );
		}

		// Notifiche e integrazioni: un loro errore resta nelle note e non fa ripetere l'invio.
		try {
			do_action( 'barrio_forms_after_submission', $entry, $form );
		} catch ( Throwable $e ) {
			EntryRepository::add_note( $id, 'system', NoteLevel::Error, $e->getMessage() );
		}
		return $entry;
	}

	/** @param array<string, mixed> $input */
	private static function spam( array $input, ?string $ip ): ?WP_Error {
		if ( '' !== self::scalar( $input['_barrio_hp'] ?? null ) || '1' !== self::scalar( $input['_barrio_js'] ?? null ) ) {
			return new WP_Error( 'barrio_spam', __( 'Your submission could not be accepted.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		if ( $ip && self::submissions( $ip ) >= self::RATE_LIMIT ) {
			return new WP_Error( 'barrio_rate_limited', __( 'Too many submissions. Please try again in a few minutes.', 'barrio-suite' ), [ 'status' => 429 ] );
		}
		return null;
	}

	private static function submissions( string $ip ): int {
		return (int) get_transient( self::rate_key( $ip ) );
	}

	// Privacy by default: nel transient c'è solo un hash dell'IP, che scade dopo pochi minuti.
	private static function rate_key( string $ip ): string {
		return 'barrio_rate_' . hash_hmac( 'md5', $ip, wp_salt() );
	}

	private static function scalar( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
