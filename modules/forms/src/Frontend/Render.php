<?php

namespace BarrioSuite\Forms\Frontend;

use BarrioSuite\Forms\FieldTypes;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormStatus;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\I18n;

// HTML del form, unico per il blocco barrio/form e lo shortcode [barrio_form id=""].
final class Render {

	public static function register(): void {
		$assets = self::assets();
		wp_register_style( 'barrio-forms', "{$assets}frontend/form.css", [], BARRIO_SUITE_VERSION );
		wp_register_script_module( '@barrio/forms-conditions', "{$assets}frontend/conditions.js", [], BARRIO_SUITE_VERSION );
		wp_register_script_module( '@barrio/forms', "{$assets}frontend/form.js", [ '@barrio/forms-conditions' ], BARRIO_SUITE_VERSION );

		add_shortcode( 'barrio_form', static fn( mixed $atts ): string => self::form( (int) ( ( (array) $atts )['id'] ?? 0 ) ) );
		$block = register_block_type(
			dirname( __DIR__, 2 ) . '/assets/block',
			[ 'render_callback' => static fn( array $attributes ): string => self::form( (int) ( $attributes['id'] ?? 0 ) ) ]
		);
		foreach ( $block ? $block->editor_script_handles : [] as $handle ) {
			wp_set_script_translations( $handle, 'barrio-suite', dirname( __DIR__, 4 ) . '/languages' );
		}
	}

	public static function form( int $id ): string {
		$form = $id ? FormRepository::get( $id ) : null;
		if ( null === $form || FormStatus::Trash === $form->status ) {
			return '';
		}
		if ( FormStatus::Closed === $form->status ) {
			return '<p class="barrio-form-closed">' . esc_html__( 'This form is no longer accepting submissions.', 'barrio-suite' ) . '</p>';
		}
		// Gli asset si caricano solo nelle pagine che hanno un form.
		wp_enqueue_style( 'barrio-forms' );
		wp_enqueue_script_module( '@barrio/forms' );
		// Per gli script delle estensioni (un captcha) nelle sole pagine con un form.
		do_action( 'barrio_forms_render', $form );
		return self::html( $form, I18n::current() );
	}

	/**
	 * Una pagina con il solo form, per l'anteprima del builder (in un iframe). Il form funziona
	 * come sul sito ma non invia: il JS si ferma dopo la validazione.
	 */
	public static function preview( Form $form, string $lang ): string {
		wp_enqueue_style( 'barrio-forms' );
		wp_enqueue_script_module( '@barrio/forms' );
		wp_add_inline_style( 'barrio-forms', 'body{margin:24px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}' );
		return self::page( I18n::in( $lang, static fn(): string => self::html( $form, $lang, true ) ) );
	}

	// Una pagina che non è di WordPress, per le anteprime in un iframe (builder e popup): CSS e JS accodati li stampa WordPress.
	public static function page( string $body ): string {
		ob_start();
		wp_styles()->do_items();
		wp_script_modules()->print_import_map();
		wp_script_modules()->print_enqueued_script_modules();
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">' . ob_get_clean() . '</head><body>' . $body . '</body></html>';
	}

	private static function html( Form $form, string $lang, bool $preview = false ): string {
		$prefix = wp_unique_id( 'barrio-form-' );
		// Ogni page_break chiude uno step; la sua etichetta è il testo del pulsante "avanti".
		$steps = [ '' ];
		$next  = [];
		foreach ( $form->current->fields as $field ) {
			$text = I18n::pick( $field->i18n, $lang );
			if ( 'page_break' === $field->type ) {
				$next[]  = $text['label'] ?? __( 'Next', 'barrio-suite' );
				$steps[] = '';
			} else {
				$steps[ array_key_last( $steps ) ] .= self::field( $field, $text, "{$prefix}-{$field->key}" );
			}
		}

		$submit = I18n::pick( $form->i18n, $lang )['submit_label'] ?? __( 'Submit', 'barrio-suite' );
		$html   = '';
		foreach ( $steps as $i => $fields ) {
			$back    = $i > 0 ? '<button type="button" class="barrio-form__back" data-barrio-back>' . esc_html__( 'Back', 'barrio-suite' ) . '</button>' : '';
			$forward = isset( $next[ $i ] )
				? '<button type="button" class="barrio-form__next" data-barrio-next>' . esc_html( $next[ $i ] ) . '</button>'
				: '<button type="submit" class="barrio-form__submit">' . esc_html( $submit ) . '</button>';
			// Dal secondo già nascosti: il JS è differito e il form intero comparirebbe per un attimo.
			$hidden = $i > 0 ? ' hidden' : '';
			$html  .= "<div class=\"barrio-form__step\" data-barrio-step{$hidden}>{$fields}<div class=\"barrio-form__nav\">{$back}{$forward}</div></div>";
		}

		// Campi di servizio della pipeline (Submission): _barrio_js e _barrio_url li compila il JS.
		$service = sprintf(
			'<input type="hidden" name="_barrio_lang" value="%1$s"><input type="hidden" name="_barrio_version" value="%2$d"><input type="hidden" name="_barrio_js" value=""><input type="hidden" name="_barrio_url" value="">'
			. '<div class="barrio-form__hp" aria-hidden="true"><label for="%3$s-hp">%4$s</label><input type="text" id="%3$s-hp" name="_barrio_hp" value="" tabindex="-1" autocomplete="off"></div>',
			esc_attr( $lang ),
			(int) $form->current->id,
			esc_attr( $prefix ),
			esc_html__( 'Leave this field empty', 'barrio-suite' )
		);
		// Messaggi del JS, tradotti qui: il file JS è statico e uguale per tutte le lingue.
		$messages = [
			'error'    => __( 'The form could not be sent. Please try again.', 'barrio-suite' ),
			'required' => __( 'This field is required.', 'barrio-suite' ),
			'fileSize' => __( 'The file is too large.', 'barrio-suite' ),
			'preview'  => __( 'Preview: the form is valid, but nothing has been sent.', 'barrio-suite' ),
			'sending'  => __( 'Sending…', 'barrio-suite' ),
			'outdated' => __( 'This form has changed. Please reload the page and try again.', 'barrio-suite' ),
		];

		return sprintf(
			'<form id="%s" class="barrio-form" action="%s" method="post" enctype="multipart/form-data" data-barrio-form%s data-messages="%s"><noscript><p class="barrio-form__message">%s</p></noscript>%s%s<div class="barrio-form__message" role="status"></div></form>',
			esc_attr( $prefix ),
			esc_url( rest_url( "barrio/v1/forms/{$form->id}/entries" ) ),
			$preview ? ' data-barrio-preview' : '',
			esc_attr( (string) wp_json_encode( $messages ) ),
			esc_html__( 'To send this form, please enable JavaScript in your browser.', 'barrio-suite' ),
			$service,
			$html
		);
	}

	// plugins_url() parte dalla cartella che contiene il secondo argomento: modules/forms/.
	private static function assets(): string {
		return plugins_url( 'assets/', dirname( __DIR__ ) );
	}

	/** @param array{label?: string, placeholder?: string|null, error_message?: string|null, options?: array<string, string>|null} $text */
	private static function field( Field $field, array $text, string $id ): string {
		return sprintf(
			'<div class="barrio-form__field barrio-form__field--%s" data-barrio-field="%s"%s%s>%s<p class="barrio-form__error" id="%s-error"></p></div>',
			esc_attr( $field->type ),
			esc_attr( $field->key ),
			null === $field->conditions ? '' : ' data-conditions="' . esc_attr( (string) wp_json_encode( $field->conditions ) ) . '"',
			empty( $text['error_message'] ) ? '' : ' data-error-message="' . esc_attr( $text['error_message'] ) . '"',
			FieldTypes::render( $field, $text, $id ),
			esc_attr( $id )
		);
	}
}
