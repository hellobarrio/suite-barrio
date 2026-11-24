<?php

namespace BarrioSuite\Forms\Submission;

use BarrioSuite\Forms\Conditions;
use BarrioSuite\Forms\FieldTypes;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormStatus;
use BarrioSuite\Forms\Model\NoteLevel;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\I18n;
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

	// Tentativi per IP; barrio_forms_rate_limit lo cambia (0 lo spegne), per esempio dietro un proxy.
	private const RATE_LIMIT  = 10;
	private const RATE_WINDOW = 10 * MINUTE_IN_SECONDS;

	/**
	 * @param array<string, mixed> $input Valori per field_key e campi di servizio _barrio_*.
	 * @param array<string, mixed> $files Elementi di $_FILES per field_key (vedi FileStore::check()).
	 * @param string|null          $ip    Solo per il limite di invii: non si salva.
	 */
	public static function process( Form $form, array $input, array $files = [], ?string $ip = null ): Entry|WP_Error {
		if ( FormStatus::Active !== $form->status ) {
			return new WP_Error( 'barrio_form_closed', __( 'This form is no longer accepting submissions.', 'barrio-suite' ), [ 'status' => 403 ] );
		}

		$lang    = I18n::resolve( $input['_barrio_lang'] ?? null );
		$version = $form->current;
		$shown   = (int) self::scalar( $input['_barrio_version'] ?? null );
		// La versione che il visitatore ha letto, se esiste ancora: è quella che la registrazione deve
		// provare. Solo per la settimana di una cache di pagina, così una regola nuova non si aggira.
		// ponytail: la finestra parte dall'ultimo salvataggio, non da quando quella versione è stata sostituita.
		if ( $shown && $shown !== $version->id && strtotime( $version->created_at . ' UTC' ) > time() - WEEK_IN_SECONDS ) {
			$version = FormRepository::version( $shown, $form->id ) ?? $version;
		}

		$spam = apply_filters( 'barrio_forms_spam_check', self::spam( $input, $ip ), $input, $form );
		if ( $spam instanceof WP_Error ) {
			// Anche i respinti: ogni token finto di un captcha costa una chiamata al servizio.
			self::count( $ip );
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
			$entry    = new Entry( $form->id, (int) $version->id, $lang, $entry_values, $stored, '' === $url ? null : $url );
			$filtered = apply_filters( 'barrio_forms_entry_data', $entry, $form );
			$entry    = $filtered instanceof Entry ? $filtered : $entry;
			$entry    = EntryRepository::create( $entry );
		} catch ( Throwable $e ) {
			foreach ( $stored as $file ) {
				FileStore::delete( $file['stored_path'] );
			}
			throw $e;
		}

		self::count( $ip );

		// Notifiche e integrazioni: un loro errore resta nelle note e non fa ripetere l'invio.
		try {
			do_action( 'barrio_forms_after_submission', $entry, $form );
		} catch ( Throwable $e ) {
			EntryRepository::add_note( (int) $entry->id, 'system', NoteLevel::Error, $e->getMessage() );
		}
		return $entry;
	}

	/** @param array<string, mixed> $input */
	private static function spam( array $input, ?string $ip ): ?WP_Error {
		if ( '' !== self::scalar( $input['_barrio_hp'] ?? null ) || '1' !== self::scalar( $input['_barrio_js'] ?? null ) ) {
			return new WP_Error( 'barrio_spam', __( 'Your submission could not be accepted.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		$limit = (int) apply_filters( 'barrio_forms_rate_limit', self::RATE_LIMIT, $ip );
		if ( $ip && $limit > 0 && self::submissions( $ip ) >= $limit ) {
			return new WP_Error( 'barrio_rate_limited', __( 'Too many submissions. Please try again in a few minutes.', 'barrio-suite' ), [ 'status' => 429 ] );
		}
		return null;
	}

	private static function submissions( string $ip ): int {
		return self::window( $ip )[0];
	}

	/** @return array{int, int} I tentativi e la fine della finestra, che parte dal primo. */
	private static function window( string $ip ): array {
		$saved = get_transient( self::rate_key( $ip ) );
		return is_array( $saved ) && ( $saved[1] ?? 0 ) > time() ? [ (int) $saved[0], (int) $saved[1] ] : [ 0, time() + self::RATE_WINDOW ];
	}

	private static function count( ?string $ip ): void {
		if ( $ip ) {
			[ $count, $ends ] = self::window( $ip );
			// La scadenza resta la fine della finestra: riscriverla a ogni tentativo terrebbe bloccato chi riprova.
			// ponytail: lettura e scrittura separate, una raffica in parallelo passa il limite; un contatore
			// atomico chiede un object cache.
			set_transient( self::rate_key( $ip ), [ $count + 1, $ends ], $ends - time() );
		}
	}

	// Privacy by default: nel transient c'è solo un hash dell'IP, che scade dopo pochi minuti.
	private static function rate_key( string $ip ): string {
		return 'barrio_rate_' . hash_hmac( 'md5', $ip, wp_salt() );
	}

	private static function scalar( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
