<?php

namespace BarrioSuite\Forms;

use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Repository\FormRepository;

/**
 * Merge tag delle notifiche, nella lingua della registrazione:
 *
 * - {{field_key}}  il valore del campo; per le scelte l'etichetta, per i file il nome;
 * - {{all_fields}} il riepilogo dei campi compilati;
 * - {{form_title}}, {{entry_id}}, {{entry_date}}, {{source_url}}, {{site_name}}, {{admin_email}}.
 *
 * Un tag sconosciuto, o un campo vuoto o nascosto dalla logica condizionale, diventa una stringa vuota.
 */
final readonly class MergeTags {

	/** @var array<string, list<string>> Valori per field_key come li vede la logica condizionale: per i file, i nomi. */
	public array $values;

	/** @var array<string, array{label: string, value: string}> Campi compilati, nell'ordine del form. */
	public array $fields;

	/** @var array<string, string> */
	private array $tags;

	public function __construct( Entry $entry, Form $form, FormVersion $version ) {
		$values = [];
		$fields = [];
		foreach ( $version->fields as $field ) {
			if ( 'page_break' === $field->type ) {
				continue;
			}
			$values[ $field->key ] = $entry->values[ (int) $field->id ]
				?? array_column( array_filter( $entry->files, static fn( array $file ): bool => $file['field_id'] === $field->id ), 'original_name' );
			if ( $values[ $field->key ] ) {
				$text                  = I18n::pick( $field->i18n, $entry->lang );
				$fields[ $field->key ] = [
					'label' => wp_strip_all_tags( $text['label'] ?? $field->key ),
					'value' => self::display( $field, $text, $values[ $field->key ] ),
				];
			}
		}
		$created = null === $entry->created_at ? time() : (int) strtotime( "{$entry->created_at} UTC" );

		$this->values = $values;
		$this->fields = $fields;
		// I tag fissi vincono su un campo con la stessa chiave: un visitatore non deve poter cambiare
		// {{admin_email}} in un destinatario.
		$this->tags = [
			'form_title'  => $form->title,
			'entry_id'    => (string) $entry->id,
			'entry_date'  => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created ) ?: '',
			'source_url'  => (string) $entry->source_url,
			'site_name'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'admin_email' => (string) get_option( 'admin_email' ),
		] + array_map( static fn( array $field ): string => $field['value'], $fields );
	}

	// Con la versione compilata, che con la cache di pagina può non essere la corrente.
	public static function of( Entry $entry, Form $form ): self {
		$version = $entry->version_id === $form->current->id ? $form->current : FormRepository::version( $entry->version_id );
		return new self( $entry, $form, $version ?? $form->current );
	}

	// I valori così come sono, per oggetto e indirizzi: chi li usa toglie gli a capo o valida.
	public function text( string $template ): string {
		return $this->replace( $template, fn( string $tag ): string => 'all_fields' === $tag ? $this->all_fields_text() : $this->tags[ $tag ] ?? '' );
	}

	// I valori con l'escape, per il corpo dell'email: $template è già HTML.
	public function html( string $template ): string {
		// {{all_fields}} da solo in un paragrafo di wpautop(): la tabella prende il posto del paragrafo.
		$template = (string) preg_replace( '#<p>\s*(\{\{\s*all_fields\s*\}\})\s*</p>#', '$1', $template );
		return $this->replace( $template, fn( string $tag ): string => 'all_fields' === $tag ? $this->all_fields_html() : self::escape( $this->tags[ $tag ] ?? '' ) );
	}

	/** @param callable(string): string $value */
	private function replace( string $template, callable $value ): string {
		return (string) preg_replace_callback( '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/', static fn( array $found ): string => $value( $found[1] ), $template );
	}

	private function all_fields_text(): string {
		return implode( "\n", array_map( static fn( array $field ): string => "{$field['label']}: {$field['value']}", $this->fields ) );
	}

	private function all_fields_html(): string {
		$rows = '';
		foreach ( $this->fields as $field ) {
			$rows .= '<tr><td style="padding:12px 0;border-bottom:1px solid #e0e0e0;"><strong>' . esc_html( $field['label'] ) . '</strong><br>' . self::escape( $field['value'] ) . '</td></tr>';
		}
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">' . $rows . '</table>';
	}

	/**
	 * Un valore come lo legge una persona: per le scelte l'etichetta nella lingua di $text.
	 *
	 * @param array{label?: string, placeholder?: string|null, error_message?: string|null, options?: array<string, string>|null} $text
	 * @param list<string> $values
	 */
	public static function display( Field $field, array $text, array $values ): string {
		$labels = array_column( FieldTypes::choices( $field, $text ), 'label', 'value' );
		return implode(
			', ',
			array_map(
				static fn( string $value ): string => match ( $field->type ) {
					'select', 'radio', 'checkbox' => $labels[ $value ] ?? $value,
					'consent'                     => __( 'Yes', 'barrio-suite' ),
					'datepicker'                  => (string) mysql2date( (string) get_option( 'date_format' ), $value ),
					default                       => $value,
				},
				$values
			)
		);
	}

	// Gli a capo di una textarea diventano <br> senza lasciare a capo, che wpautop() trasformerebbe.
	private static function escape( string $value ): string {
		return str_replace( "\n", '<br>', esc_html( $value ) );
	}
}
