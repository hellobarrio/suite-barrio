<?php

namespace BarrioSuite\Forms\Api;

use BarrioSuite\Forms\FieldTypes;
use BarrioSuite\Forms\I18n;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormStatus;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Forms\Submission\Submission;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Rotte pubbliche, usate dal form del plugin e dai frontend headless. Senza nonce, quindi
 * compatibili con la cache di pagina: l'antispam è nella pipeline di invio.
 */
final class RestPublic {

	public static function register(): void {
		register_rest_route(
			'barrio/v1',
			'/forms/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'form' ],
				// context=edit è la lettura del builder: il form completo, in tutte le lingue.
				'permission_callback' => static fn( WP_REST_Request $request ): bool => 'edit' !== $request['context'] || current_user_can( 'barrio_manage_forms' ),
				'args'                => [
					'lang'    => [ 'type' => 'string' ],
					'context' => [
						'type'    => 'string',
						'enum'    => [ 'view', 'edit' ],
						'default' => 'view',
					],
				],
			]
		);
		// multipart/form-data (con i file) o JSON.
		register_rest_route(
			'barrio/v1',
			'/forms/(?P<id>\d+)/entries',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'submit' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	// Lo schema pubblico del form nella lingua richiesta: ciò che serve per mostrarlo e inviarlo.
	public static function form( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = self::find( $request );
		if ( $form instanceof WP_Error ) {
			return $form;
		}
		if ( 'edit' === $request['context'] ) {
			return new WP_REST_Response( RestForms::view( $form ) );
		}
		$lang   = I18n::resolve( $request['lang'] );
		$fields = [];
		foreach ( $form->current->fields as $field ) {
			$fields[] = self::field( $field, I18n::pick( $field->i18n, $lang ) );
		}
		return new WP_REST_Response(
			[
				'id'           => $form->id,
				'status'       => $form->status->value,
				'lang'         => $lang,
				// Da rimandare come _barrio_version con l'invio.
				'version_id'   => $form->current->id,
				'submit_label' => I18n::pick( $form->i18n, $lang )['submit_label'] ?? __( 'Submit', 'barrio-suite' ),
				'fields'       => $fields,
			]
		);
	}

	public static function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = self::find( $request );
		if ( $form instanceof WP_Error ) {
			return $form;
		}
		// Non get_params(): l'id della rotta coprirebbe un campo con chiave "id".
		$input = $request->get_json_params() ?: $request->get_body_params();
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;
		// Errori e messaggio standard nella lingua del visitatore, non in quella della richiesta REST.
		return I18n::in(
			I18n::resolve( $input['_barrio_lang'] ?? null ),
			static function () use ( $form, $input, $request, $ip ): WP_REST_Response|WP_Error {
				$entry = Submission::process( $form, $input, $request->get_file_params(), $ip, get_current_user_id() ?: null );
				if ( $entry instanceof WP_Error ) {
					return $entry;
				}

				$text = I18n::pick( $form->i18n, $entry->lang );
				return new WP_REST_Response(
					apply_filters(
						'barrio_forms_rest_response',
						[
							'message'  => $text['success_message'] ?? __( 'Thank you, your submission has been received.', 'barrio-suite' ),
							// Null: si resta sulla pagina e si mostra il messaggio.
							'redirect' => $text['success_url'] ?? null,
						],
						$entry,
						$form
					),
					201
				);
			}
		);
	}

	/**
	 * @param array{label?: string, placeholder?: string|null, error_message?: string|null, options?: array<string, string>|null} $text
	 * @return array<string, mixed>
	 */
	private static function field( Field $field, array $text ): array {
		return [
			'key'           => $field->key,
			'type'          => $field->type,
			'required'      => $field->required,
			// HTML limitato (il link alla privacy nel consenso), già filtrato.
			'label'         => FieldTypes::label_html( $text['label'] ?? '' ),
			'placeholder'   => $text['placeholder'] ?? null,
			'error_message' => $text['error_message'] ?? null,
			'options'       => in_array( $field->type, [ 'select', 'radio', 'checkbox' ], true ) ? FieldTypes::choices( $field, $text ) : null,
			'config'        => (object) $field->config,
			'conditions'    => $field->conditions,
		];
	}

	private static function find( WP_REST_Request $request ): Form|WP_Error {
		$form = FormRepository::get( (int) $request['id'] );
		if ( null === $form || FormStatus::Trash === $form->status ) {
			return new WP_Error( 'barrio_form_not_found', __( 'Form not found.', 'barrio-suite' ), [ 'status' => 404 ] );
		}
		return $form;
	}
}
