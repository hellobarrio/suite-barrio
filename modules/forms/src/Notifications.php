<?php

namespace BarrioSuite\Forms;

use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Model\NoteLevel;
use BarrioSuite\Forms\Model\Notification;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Forms\Submission\FileStore;
use BarrioSuite\Mail\Smtp;
use Throwable;
use WP_Error;

/**
 * Email inviate dopo ogni registrazione, con il template globale (logo, colori, footer). L'esito di
 * ogni notifica, riuscita o no, resta nelle note della registrazione, e la notifica si può rinviare.
 *
 * @phpstan-type Template array{logo: string, accent_color: string, background_color: string, footer: string}
 * @phpstan-type Email array{to: list<string>, subject: string, heading: string, body: string, reply_to: string|null, attachments: array<string, string>}
 */
final class Notifications {

	private const OPTION   = 'barrio_forms_email';
	private const DEFAULTS = [
		'logo'             => '',
		'accent_color'     => '#2271b1',
		'background_color' => '#f0f0f1',
		'footer'           => '',
	];

	// Listener di barrio_forms_after_submission: le notifiche attive con le condizioni soddisfatte.
	public static function on_submission( Entry $entry, Form $form ): void {
		I18n::in(
			$entry->lang,
			static function () use ( $entry, $form ): void {
				$tags = MergeTags::of( $entry, $form );
				foreach ( $form->notifications as $notification ) {
					if ( $notification->enabled && Conditions::passes( $notification->conditions, $tags->values ) ) {
						self::send( $notification, $entry, $tags );
					}
				}
			}
		);
	}

	// Rinvio dall'admin, con la configurazione attuale della notifica (un destinatario corretto nel
	// frattempo vale). Lo chiede una persona, quindi non contano né le condizioni né se è attiva.
	public static function resend( int $entry_id, int $notification_id ): ?WP_Error {
		$entry = EntryRepository::get( $entry_id );
		$form  = null === $entry ? null : FormRepository::get( $entry->form_id );
		if ( null !== $entry && null !== $form ) {
			foreach ( $form->notifications as $notification ) {
				if ( $notification->id === $notification_id ) {
					$error = I18n::in( $entry->lang, static fn(): ?WP_Error => self::send( $notification, $entry, MergeTags::of( $entry, $form ), get_current_user_id() ?: null ) );
					return null === $error ? null : new WP_Error( 'barrio_mail_failed', $error->get_error_message(), [ 'status' => 502 ] );
				}
			}
		}
		return new WP_Error( 'barrio_not_found', __( 'Entry or notification not found.', 'barrio-suite' ), [ 'status' => 404 ] );
	}

	// HTML completo di un'email con il template globale; $content è già HTML sicuro.
	public static function render( string $heading, string $content, string $lang ): string {
		ob_start();
		( require dirname( __DIR__ ) . '/templates/email.php' )( $heading, $content, $lang, self::settings(), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		return (string) ob_get_clean();
	}

	/** @return array<string, mixed> JSON Schema del template globale. */
	public static function schema(): array {
		$color = [
			'type'   => 'string',
			'format' => 'hex-color',
		];
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				// Vuoto = il nome del sito al posto del logo. WordPress non valida il formato uri ma
				// ripulisce l'URL: uno con un protocollo non ammesso (javascript:) diventa vuoto.
				'logo'             => [
					'type'   => 'string',
					'format' => 'uri',
				],
				'accent_color'     => $color,
				'background_color' => $color,
				// HTML limitato; vuoto = il nome del sito con il link alla home.
				'footer'           => [
					'type'      => 'string',
					'maxLength' => 2000,
				],
			],
		];
	}

	/** @return Template */
	public static function settings(): array {
		/** @var Template */
		return array_intersect_key( (array) get_option( self::OPTION, [] ), self::DEFAULTS ) + self::DEFAULTS;
	}

	/** @param array<string, mixed> $settings Già validate con schema(); quelle assenti restano come sono. */
	public static function save_settings( array $settings ): void {
		update_option( self::OPTION, array_intersect_key( $settings, self::DEFAULTS ) + self::settings(), false );
	}

	/**
	 * L'email di una notifica del form non salvato, con una registrazione d'esempio: per il builder.
	 * Senza il filtro barrio_forms_notification, perché la registrazione non esiste.
	 *
	 * @return array{to: list<string>, reply_to: string|null, subject: string, html: string}
	 */
	public static function preview( Form $form, Notification $notification, string $lang ): array {
		return I18n::in( $lang, static fn(): array => self::sample_email( $form, $notification, $lang ) );
	}

	/** @return array{to: list<string>, reply_to: string|null, subject: string, html: string} */
	private static function sample_email( Form $form, Notification $notification, string $lang ): array {
		$fields = [];
		$values = [];
		$files  = [];
		foreach ( $form->current->fields as $i => $field ) {
			// MergeTags trova i valori per id del campo: quelli del form non salvato sono finti.
			$id       = $i + 1;
			$fields[] = new Field( $field->key, $field->type, $field->i18n, $field->required, $field->config, $field->conditions, $id );
			$sample   = self::sample( $field, $lang );
			if ( 'file' === $field->type ) {
				$files[] = [
					'field_id'      => $id,
					'stored_path'   => '',
					'original_name' => 'document.pdf',
					'mime_type'     => 'application/pdf',
					'size_bytes'    => 1,
				];
			} elseif ( $sample ) {
				$values[ $id ] = $sample;
			}
		}
		$entry = new Entry( $form->id, 0, $lang, $values, $files, source_url: home_url( '/' ), id: 123 );
		$email = self::compose( $notification, $entry, new MergeTags( $entry, $form, new FormVersion( $fields ) ) );
		return [
			'to'       => $email['to'],
			'reply_to' => $email['reply_to'],
			'subject'  => $email['subject'],
			'html'     => self::render( $email['heading'], $email['body'], $lang ),
		];
	}

	/** @return Email Prima del filtro barrio_forms_notification. */
	private static function compose( Notification $notification, Entry $entry, MergeTags $tags ): array {
		$text = I18n::pick( $notification->i18n, $entry->lang );
		return [
			'to'          => self::addresses( $notification->recipients, $tags ),
			// Gli a capo di un valore inviato non devono arrivare negli header.
			'subject'     => self::line( $tags->text( $text['subject'] ?? '' ) ),
			'heading'     => self::line( $tags->text( $text['heading'] ?? $text['subject'] ?? '' ) ),
			// Il testo della notifica si filtra prima di inserire i valori, che hanno già l'escape.
			'body'        => $tags->html( wpautop( wp_kses_post( $text['body'] ?? '' ) ) ),
			'reply_to'    => self::addresses( (string) $notification->reply_to, $tags )[0] ?? null,
			'attachments' => $notification->attach_files ? self::attachments( $entry ) : [],
		];
	}

	/**
	 * Un valore verosimile per l'anteprima; per i testi liberi l'etichetta tra parentesi quadre.
	 *
	 * @return list<string>
	 */
	private static function sample( Field $field, string $lang ): array {
		$choices = array_map( 'strval', array_values( (array) ( $field->config['choices'] ?? [] ) ) );
		return match ( $field->type ) {
			'email'            => [ 'name@example.com' ],
			'tel'              => [ '+39 333 123 4567' ],
			'number'           => [ (string) ( $field->config['min'] ?? 10 ) ],
			'datepicker'       => [ gmdate( 'Y-m-d' ) ],
			'select', 'radio'  => array_slice( $choices, 0, 1 ),
			'checkbox'         => array_slice( $choices, 0, 2 ),
			'consent'          => [ '1' ],
			'file', 'page_break' => [],
			default            => [ '[' . wp_strip_all_tags( I18n::pick( $field->i18n, $lang )['label'] ?? $field->key ) . ']' ],
		};
	}

	private static function send( Notification $notification, Entry $entry, MergeTags $tags, ?int $user_id = null ): ?WP_Error {
		$to = [];
		try {
			/** @var Email $email */
			$email = apply_filters( 'barrio_forms_notification', self::compose( $notification, $entry, $tags ), $notification, $entry );
			$to    = $email['to'];
			$error = $to
				? Smtp::send(
					$to,
					$email['subject'],
					self::render( $email['heading'], $email['body'], $entry->lang ),
					null === $email['reply_to'] ? [] : [ "Reply-To: {$email['reply_to']}" ],
					$email['attachments']
				)
				: new WP_Error( 'barrio_no_recipients', __( 'no valid recipient.', 'barrio-suite' ) );
		} catch ( Throwable $e ) {
			// Per esempio da un filtro o da phpmailer_init: le altre notifiche partono lo stesso.
			$error = new WP_Error( 'barrio_notification_failed', $e->getMessage() );
		}

		// L'email è nella lingua di chi ha compilato, la nota in quella del sito.
		I18n::in(
			I18n::languages()[0],
			static fn() => EntryRepository::add_note(
				(int) $entry->id,
				'notification',
				null === $error ? NoteLevel::Success : NoteLevel::Error,
				null === $error
					/* translators: 1: nome della notifica; 2: destinatari. */
					? sprintf( __( 'Notification "%1$s" sent to %2$s.', 'barrio-suite' ), $notification->name, implode( ', ', $to ) )
					/* translators: 1: nome della notifica; 2: messaggio di errore. */
					: sprintf( __( 'Notification "%1$s" could not be sent: %2$s', 'barrio-suite' ), $notification->name, $error->get_error_message() ),
				$user_id
			)
		);
		return $error;
	}

	/**
	 * Gli indirizzi validi di un template come "info@sito.it, {{email}}". I merge tag si sostituiscono
	 * dopo aver diviso sulle virgole, e ogni parte deve essere un solo indirizzo: un valore inviato
	 * con più indirizzi non aggiunge destinatari.
	 *
	 * @return list<string>
	 */
	private static function addresses( string $template, MergeTags $tags ): array {
		$addresses = [];
		foreach ( explode( ',', $template ) as $part ) {
			$address = trim( $tags->text( $part ) );
			if ( is_email( $address ) ) {
				$addresses[] = $address;
			}
		}
		return array_values( array_unique( $addresses ) );
	}

	// ponytail: PHPMailer ricava il tipo dal percorso, che non ha estensione, e dichiara gli allegati
	// application/octet-stream: i client li aprono dal nome. Se uno non ci riesce, il mime_type di
	// entry_files va passato ad addAttachment() su phpmailer_init.
	/** @return array<string, string> Nome del file nell'email => percorso nella cartella protetta. */
	private static function attachments( Entry $entry ): array {
		$files = [];
		foreach ( $entry->files as $i => $file ) {
			// Due file con lo stesso nome: il secondo non deve sostituire il primo.
			$name           = isset( $files[ $file['original_name'] ] ) ? ( $i + 1 ) . '-' . $file['original_name'] : $file['original_name'];
			$files[ $name ] = FileStore::dir() . '/' . $file['stored_path'];
		}
		return $files;
	}

	private static function line( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
