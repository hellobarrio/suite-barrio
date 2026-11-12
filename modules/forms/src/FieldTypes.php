<?php

namespace BarrioSuite\Forms;

use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Submission\FileStore;

/**
 * Tipi di campo core, con i nomi del prototipo più consent e hidden. Per ogni tipo: lo schema
 * della config (validato prima di ogni scrittura; il builder ne ricava il pannello),
 * come normalizzare e validare il valore inviato e come mostrarlo.
 * ponytail: i tipi sono dei match; il registro per le estensioni arriva con la loro API (fase 8).
 *
 * @phpstan-type FieldText array{label?: string, placeholder?: string|null, error_message?: string|null, options?: array<string, string>|null}
 */
final class FieldTypes {

	// Tag ammessi nelle etichette: servono al link alla privacy policy nel consenso.
	private const LABEL_HTML = [
		'a'      => [
			'href'   => true,
			'target' => true,
			'rel'    => true,
		],
		'strong' => [],
		'em'     => [],
		'br'     => [],
	];

	private const INPUT_TYPES = [
		'text'       => 'text',
		'email'      => 'email',
		'tel'        => 'tel',
		'number'     => 'number',
		'datepicker' => 'date',
	];

	/** @return array<string, string> Il nome di ogni tipo, nell'ordine del builder. */
	public static function labels(): array {
		return [
			'text'       => __( 'Text', 'barrio-suite' ),
			'email'      => __( 'Email', 'barrio-suite' ),
			'tel'        => __( 'Phone', 'barrio-suite' ),
			'number'     => __( 'Number', 'barrio-suite' ),
			'textarea'   => __( 'Paragraph', 'barrio-suite' ),
			'datepicker' => __( 'Date', 'barrio-suite' ),
			'select'     => __( 'Dropdown', 'barrio-suite' ),
			'radio'      => __( 'Radio buttons', 'barrio-suite' ),
			'checkbox'   => __( 'Checkboxes', 'barrio-suite' ),
			'file'       => __( 'File upload', 'barrio-suite' ),
			'consent'    => __( 'Consent', 'barrio-suite' ),
			'hidden'     => __( 'Hidden', 'barrio-suite' ),
			'page_break' => __( 'Page break', 'barrio-suite' ),
		];
	}

	/**
	 * title e description non contano per la validazione: il builder ne ricava il pannello.
	 *
	 * @return array<string, mixed>|null JSON Schema della config; null se il tipo non esiste.
	 */
	public static function schema( string $type ): ?array {
		$object  = static fn( array $properties = [], array $required = [] ): array => [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		];
		$list    = static fn( array $items, string $title ): array => [
			'type'        => 'array',
			'title'       => $title,
			'items'       => [ 'type' => 'string' ] + $items,
			'minItems'    => 1,
			'uniqueItems' => true,
		];
		$pattern = [
			'pattern' => [
				'type'        => 'string',
				'minLength'   => 1,
				'title'       => __( 'Pattern', 'barrio-suite' ),
				'description' => __( 'A regular expression the whole value must match, for example [0-9]{5}.', 'barrio-suite' ),
			],
		];
		return match ( $type ) {
			'text', 'tel'                 => $object( $pattern ),
			'number'                      => $object(
				[
					'min' => [
						'type'  => 'number',
						'title' => __( 'Minimum', 'barrio-suite' ),
					],
					'max' => [
						'type'  => 'number',
						'title' => __( 'Maximum', 'barrio-suite' ),
					],
				]
			),
			// I valori delle scelte, in ordine; le etichette tradotte stanno nei testi (options).
			'select', 'radio', 'checkbox' => $object(
				[
					'choices' => $list(
						[
							'minLength' => 1,
							'maxLength' => 255,
						],
						__( 'Choices', 'barrio-suite' )
					),
				],
				[ 'choices' ]
			),
			'file'                        => $object(
				[
					'extensions'  => $list( [ 'pattern' => '^[a-z0-9]+$' ], __( 'Allowed extensions', 'barrio-suite' ) ),
					'max_size_mb' => [
						'type'             => 'number',
						'minimum'          => 0,
						'exclusiveMinimum' => true,
						'title'            => __( 'Maximum size (MB)', 'barrio-suite' ),
						'description'      => __( 'Leave empty to use the server limit.', 'barrio-suite' ),
					],
				],
				[ 'extensions' ]
			),
			// Valore fisso, oppure preso dal parametro param dell'URL della pagina.
			'hidden'                      => $object(
				[
					'value' => [
						'type'  => 'string',
						'title' => __( 'Value', 'barrio-suite' ),
					],
					'param' => [
						'type'        => 'string',
						'pattern'     => '^[A-Za-z0-9_.-]+$',
						'title'       => __( 'URL parameter', 'barrio-suite' ),
						'description' => __( 'Takes the value from this parameter of the page URL, for example utm_campaign, instead of the fixed value.', 'barrio-suite' ),
					],
				]
			),
			'email', 'textarea', 'datepicker', 'consent', 'page_break' => $object(),
			default                       => null,
		};
	}

	// Prima di ogni scrittura (FormRepository): tipo esistente, config e condizioni conformi agli schemi.
	public static function check( Field $field ): ?string {
		$schema = self::schema( $field->type );
		if ( null === $schema ) {
			return "unknown type \"{$field->type}\".";
		}
		$valid = rest_validate_value_from_schema( $field->config, $schema, 'config' );
		if ( is_wp_error( $valid ) ) {
			return $valid->get_error_message();
		}
		// Un pattern non valido farebbe rifiutare ogni invio: meglio scoprirlo qui che dal visitatore.
		if ( isset( $field->config['pattern'] ) && false === @preg_match( self::regex( (string) $field->config['pattern'] ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return 'config[pattern] is not a valid regular expression.';
		}
		return Conditions::check( $field->conditions );
	}

	/**
	 * Il valore inviato come lista di stringhe non vuote: una checkbox ne ha più d'uno, un campo
	 * vuoto nessuno. Per file $raw è l'elemento di $_FILES e il valore è il nome del file.
	 *
	 * @return list<string>
	 */
	public static function normalize( Field $field, mixed $raw ): array {
		$values = match ( $field->type ) {
			'checkbox'   => array_map( [ self::class, 'line' ], is_array( $raw ) ? array_values( $raw ) : [ $raw ] ),
			'textarea'   => [ self::text( $raw ) ],
			'consent'    => [ in_array( $raw, [ true, 1, '1', 'on', 'true' ], true ) ? '1' : '' ],
			// Il valore fisso viene dalla config, non dal client.
			'hidden'     => [ isset( $field->config['param'] ) ? self::line( $raw ) : (string) ( $field->config['value'] ?? '' ) ],
			'file'       => [ is_array( $raw ) && UPLOAD_ERR_NO_FILE !== ( $raw['error'] ?? UPLOAD_ERR_NO_FILE ) ? self::line( $raw['name'] ?? '' ) : '' ],
			'page_break' => [],
			default      => [ self::line( $raw ) ],
		};
		return array_values( array_unique( array_filter( $values, static fn( string $v ): bool => '' !== $v ) ) );
	}

	/**
	 * Il file in sé lo controlla FileStore::check().
	 *
	 * @param list<string> $values Da normalize().
	 * @return string|null Il messaggio di errore.
	 */
	public static function validate( Field $field, array $values ): ?string {
		if ( [] === $values ) {
			return $field->required ? __( 'This field is required.', 'barrio-suite' ) : null;
		}
		foreach ( $values as $value ) {
			$error = 'file' === $field->type ? null : self::error( $field, $value );
			if ( null !== $error ) {
				return $error;
			}
		}
		return null;
	}

	/**
	 * Etichetta e controllo; il contenitore e il messaggio di errore li aggiunge Render.
	 *
	 * @param FieldText $text
	 */
	public static function render( Field $field, array $text, string $id ): string {
		$label = self::label_html( $text['label'] ?? '' ) . ( $field->required ? ' <span class="barrio-form__required" aria-hidden="true">*</span>' : '' );
		$attrs = [
			'id'               => $id,
			'name'             => $field->key,
			'required'         => $field->required,
			'aria-describedby' => "{$id}-error",
		];
		$above = sprintf( '<label class="barrio-form__label" for="%s">%s</label>', esc_attr( $id ), $label );

		switch ( $field->type ) {
			case 'textarea':
				return $above . '<textarea' . self::attrs(
					$attrs + [
						'placeholder' => $text['placeholder'] ?? null,
						'rows'        => 5,
						'maxlength'   => self::max_length( $field ),
					]
				) . '></textarea>';

			case 'select':
				$options = '<option value="">' . esc_html( $text['placeholder'] ?? '' ) . '</option>';
				foreach ( self::choices( $field, $text ) as $choice ) {
					$options .= sprintf( '<option value="%s">%s</option>', esc_attr( $choice['value'] ), esc_html( $choice['label'] ) );
				}
				return $above . '<select' . self::attrs( $attrs ) . ">{$options}</select>";

			case 'radio':
			case 'checkbox':
				$choices = '';
				foreach ( self::choices( $field, $text ) as $i => $choice ) {
					$input    = [
						'type'     => $field->type,
						'id'       => "{$id}-{$i}",
						'name'     => 'checkbox' === $field->type ? "{$field->key}[]" : $field->key,
						'value'    => $choice['value'],
						// Su una checkbox required vorrebbe dire "tutte": "almeno una" la controlla il JS.
						'required' => 'radio' === $field->type && $field->required,
					];
					$choices .= sprintf( '<div class="barrio-form__choice"><input%s><label for="%s">%s</label></div>', self::attrs( $input ), esc_attr( "{$id}-{$i}" ), esc_html( $choice['label'] ) );
				}
				if ( 'checkbox' === $field->type && $field->required ) {
					$label .= '<span class="barrio-form__sr"> ' . esc_html__( '(required)', 'barrio-suite' ) . '</span>';
				}
				return sprintf(
					'<fieldset class="barrio-form__choices"%s><legend class="barrio-form__label">%s</legend>%s</fieldset>',
					self::attrs(
						[
							'aria-describedby' => "{$id}-error",
							'data-required'    => 'checkbox' === $field->type && $field->required,
						]
					),
					$label,
					$choices
				);

			case 'consent':
				return sprintf(
					'<div class="barrio-form__choice"><input%s><label for="%s">%s</label></div>',
					self::attrs(
						[
							'type'  => 'checkbox',
							'value' => '1',
						] + $attrs
					),
					esc_attr( $id ),
					$label
				);

			case 'hidden':
				// Il valore fisso serve al JS per la logica condizionale; il server usa quello della config.
				return '<input' . self::attrs(
					[
						'type'       => 'hidden',
						'name'       => $field->key,
						'value'      => isset( $field->config['param'] ) ? null : $field->config['value'] ?? null,
						'data-param' => $field->config['param'] ?? null,
					]
				) . '>';

			case 'file':
				$extensions = FileStore::extensions( $field );
				$max_size   = FileStore::max_size( $field );
				$hint       = sprintf(
					/* translators: 1: estensioni ammesse, per esempio "PDF, DOCX"; 2: dimensione massima, per esempio "5 MB". */
					__( 'Accepted formats: %1$s. Maximum size: %2$s.', 'barrio-suite' ),
					strtoupper( implode( ', ', $extensions ) ),
					size_format( $max_size )
				);
				return $above . '<input' . self::attrs(
					[
						'type'             => 'file',
						'accept'           => '.' . implode( ',.', $extensions ),
						'data-max-size'    => $max_size,
						'aria-describedby' => "{$id}-hint {$id}-error",
					] + $attrs
				) . sprintf( '><p class="barrio-form__hint" id="%s-hint">%s</p>', esc_attr( $id ), esc_html( $hint ) );

			default:
				return $above . '<input' . self::attrs(
					[
						'type'         => self::INPUT_TYPES[ $field->type ] ?? 'text',
						'placeholder'  => $text['placeholder'] ?? null,
						'maxlength'    => 'number' === $field->type || 'datepicker' === $field->type ? null : self::max_length( $field ),
						'pattern'      => $field->config['pattern'] ?? null,
						'min'          => $field->config['min'] ?? null,
						'max'          => $field->config['max'] ?? null,
						'step'         => 'number' === $field->type ? 'any' : null,
						'autocomplete' => [
							'email' => 'email',
							'tel'   => 'tel',
						][ $field->type ] ?? null,
					] + $attrs
				) . '>';
		}
	}

	/**
	 * Le scelte nell'ordine di config; una scelta senza etichetta mostra il valore.
	 *
	 * @param FieldText $text
	 * @return list<array{value: string, label: string}>
	 */
	public static function choices( Field $field, array $text ): array {
		$choices = [];
		foreach ( (array) ( $field->config['choices'] ?? [] ) as $value ) {
			$choices[] = [
				'value' => (string) $value,
				'label' => (string) ( $text['options'][ $value ] ?? $value ),
			];
		}
		return $choices;
	}

	public static function label_html( string $label ): string {
		return wp_kses( $label, self::LABEL_HTML );
	}

	private static function error( Field $field, string $value ): ?string {
		$config = $field->config;
		$max    = self::max_length( $field );
		if ( mb_strlen( $value ) > $max ) {
			/* translators: %d: numero massimo di caratteri. */
			return sprintf( __( 'Please use at most %d characters.', 'barrio-suite' ), $max );
		}
		switch ( $field->type ) {
			case 'email':
				return is_email( $value ) ? null : __( 'Please enter a valid email address.', 'barrio-suite' );

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return __( 'Please enter a number.', 'barrio-suite' );
				}
				if ( isset( $config['min'] ) && (float) $value < $config['min'] ) {
					/* translators: %s: valore minimo. */
					return sprintf( __( 'Please enter a number greater than or equal to %s.', 'barrio-suite' ), $config['min'] );
				}
				if ( isset( $config['max'] ) && (float) $value > $config['max'] ) {
					/* translators: %s: valore massimo. */
					return sprintf( __( 'Please enter a number less than or equal to %s.', 'barrio-suite' ), $config['max'] );
				}
				return null;

			case 'datepicker':
				// <input type="date"> invia sempre Y-m-d, qualunque sia il formato mostrato al visitatore.
				$valid = preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $date ) && checkdate( (int) $date[2], (int) $date[3], (int) $date[1] );
				return $valid ? null : __( 'Please enter a valid date.', 'barrio-suite' );

			case 'select':
			case 'radio':
			case 'checkbox':
				return in_array( $value, (array) $config['choices'], true ) ? null : __( 'Please choose one of the available options.', 'barrio-suite' );

			case 'text':
			case 'tel':
				$valid = ! isset( $config['pattern'] ) || preg_match( self::regex( (string) $config['pattern'] ), $value );
				return $valid ? null : __( 'Please match the requested format.', 'barrio-suite' );
		}
		return null;
	}

	private static function max_length( Field $field ): int {
		return 'textarea' === $field->type ? 10000 : 255;
	}

	// Come l'attributo pattern dell'HTML: deve corrispondere tutto il valore.
	private static function regex( string $pattern ): string {
		return '#^(?:' . str_replace( '#', '\#', $pattern ) . ')$#u';
	}

	// Una riga: spazi e a capo compressi.
	private static function line( mixed $raw ): string {
		return (string) preg_replace( '/\s+/', ' ', self::text( $raw ) );
	}

	// Testo senza caratteri di controllo (tranne tab e a capo) né UTF-8 non valido. Per il resto si
	// salva com'è e si fa l'escape quando si mostra.
	private static function text( mixed $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		return trim( (string) preg_replace( '/[\x00-\x08\x0B-\x1F\x7F]/', '', str_replace( "\r\n", "\n", wp_check_invalid_utf8( (string) $raw, true ) ) ) );
	}

	/** @param array<string, string|int|float|bool|null> $attrs false e null omettono l'attributo. */
	private static function attrs( array $attrs ): string {
		$html = '';
		foreach ( $attrs as $name => $value ) {
			if ( true === $value ) {
				$html .= " {$name}";
			} elseif ( null !== $value && false !== $value ) {
				$html .= sprintf( ' %s="%s"', $name, esc_attr( (string) $value ) );
			}
		}
		return $html;
	}
}
