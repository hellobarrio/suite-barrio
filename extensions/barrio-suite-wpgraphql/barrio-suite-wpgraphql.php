<?php
/**
 * Plugin Name:       Barrio Suite WPGraphQL
 * Description:       The Barrio Suite forms in WPGraphQL, with the names of the previous plugin (hbForm, hbForms, hbSubmitForm), so headless frontends keep working unchanged.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Text Domain:       barrio-suite-wpgraphql
 * Domain Path:       /languages
 * Requires Plugins:  barrio-suite
 */

// L'unico codice che conosce WPGraphQL: lo schema hb* del prototipo con gli stessi nomi e tipi
// (tests/fixtures/wpgraphql-schema.txt), sopra la pipeline di invio del core. Senza WPGraphQL
// graphql_register_types non parte e non succede niente. Le descrizioni dello schema non si traducono:
// lo schema deve essere lo stesso in ogni lingua.

use BarrioSuite\Forms\FieldTypes;
use BarrioSuite\Forms\I18n;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\FormStatus;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Forms\Submission\Submission;
use GraphQL\Error\UserError;

defined( 'ABSPATH' ) || exit;

load_plugin_textdomain( 'barrio-suite-wpgraphql', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

// Il pattern che i frontend del prototipo usano per controllare le email nel browser, vicino a is_email().
const BARRIO_WPGRAPHQL_EMAIL = "^[A-Za-z0-9!#$%&'*+/=?^_`{|}~.-]+@[A-Za-z0-9-]+(\\.[A-Za-z0-9-]+)+$";

add_action(
	'barrio_forms_loaded',
	static function (): void {
		// Priorità 9: prima del prototipo (10), che registra gli stessi nomi anche con il suo modulo Forms
		// disattivato. WPGraphQL tiene la prima registrazione e scarta le altre.
		add_action( 'graphql_register_types', 'barrio_wpgraphql_register', 9 );
	}
);

function barrio_wpgraphql_register(): void {
	$string = static fn( string $description = '' ): array => [
		'type'        => 'String',
		'description' => $description,
	];

	register_graphql_object_type(
		'HBFormOption',
		[
			'description' => 'A choice of a select, radio or checkbox field.',
			'fields'      => [
				'value' => $string( 'The value to submit.' ),
				'label' => $string( 'The label in the requested language.' ),
			],
		]
	);
	register_graphql_object_type(
		'HBConditionalLogicRule',
		[
			'description' => 'A rule of the conditional logic.',
			'fields'      => [
				'fieldName' => $string( 'The name of the field the rule reads.' ),
				'operator'  => $string( '=, !=, >, <, contains, starts_with, ends_with, empty, not_empty.' ),
				'value'     => $string(),
			],
		]
	);
	register_graphql_object_type(
		'HBConditionalLogic',
		[
			'description' => 'When the field is shown. The server applies the same logic: hidden fields are neither required nor saved.',
			'fields'      => [
				'enabled'   => [
					'type'        => 'Boolean',
					'description' => 'Always true: a field without conditional logic has none.',
				],
				'action'    => $string( 'show or hide, when the rules are met.' ),
				'logicType' => $string( 'all or any of the rules.' ),
				'rules'     => [ 'type' => [ 'list_of' => 'HBConditionalLogicRule' ] ],
			],
		]
	);
	register_graphql_object_type(
		'HBFormField',
		[
			'description' => 'A field of a form.',
			'fields'      => [
				'id'               => $string( 'Same as name.' ),
				'name'             => $string( 'The key of the field: the name to submit its value with.' ),
				'type'             => $string( 'text, email, textarea, tel, number, datepicker, select, radio, checkbox, file, hidden or the type of an extension. A consent is a checkbox with one choice, whose label has the links.' ),
				'label'            => $string( 'May contain links, strong, em and br (the privacy policy link of a consent).' ),
				'placeholder'      => $string(),
				'errorMessage'     => $string( 'The custom error message, if any.' ),
				'required'         => [ 'type' => 'Boolean' ],
				'pattern'          => $string( 'A regular expression the whole value must match.' ),
				'min'              => $string( 'Number fields.' ),
				'max'              => $string( 'Number fields.' ),
				'format'           => $string( 'How a datepicker shows and submits the date: dd/MM/yyyy (yyyy-MM-dd is accepted too).' ),
				'options'          => [ 'type' => [ 'list_of' => 'HBFormOption' ] ],
				'conditionalLogic' => [
					'type'        => 'HBConditionalLogic',
					'description' => 'Null when the field is always shown.',
				],
			],
		]
	);
	register_graphql_object_type(
		'HBFormSettings',
		[
			'description' => 'What happens on submit.',
			'fields'      => [
				'submitText'      => $string(),
				'successMessage'  => $string( 'Empty for the standard message.' ),
				'errorMessage'    => $string( 'Always empty: errors come with the submission.' ),
				'afterSubmit'     => $string( 'message or redirect.' ),
				'redirectUrl'     => $string(),
				'displayPageInfo' => [
					'type'        => 'Boolean',
					'description' => 'Always true: send pageUrl, saved without query string and fragment.',
				],
			],
		]
	);
	register_graphql_object_type(
		'HBFormStep',
		[
			'description' => 'A page of a multistep form.',
			'fields'      => [
				'fields' => [ 'type' => [ 'list_of' => 'HBFormField' ] ],
			],
		]
	);
	register_graphql_object_type(
		'HBForm',
		[
			'description' => 'A form in the requested language.',
			'fields'      => [
				'id'        => [ 'type' => [ 'non_null' => 'Int' ] ],
				'title'     => $string(),
				'createdAt' => $string( 'Y-m-d H:i:s in the site timezone.' ),
				'updatedAt' => $string( 'Y-m-d H:i:s in the site timezone.' ),
				'fields'    => [
					'type'        => [ 'list_of' => 'HBFormField' ],
					'description' => 'All the fields, without page breaks.',
				],
				'steps'     => [
					'type'        => [ 'list_of' => 'HBFormStep' ],
					'description' => 'The fields split by page breaks: one step without them.',
				],
				'settings'  => [ 'type' => 'HBFormSettings' ],
			],
		]
	);
	register_graphql_object_type(
		'HBFormListItem',
		[
			'description' => 'A form in the list.',
			'fields'      => [
				'id'          => [ 'type' => [ 'non_null' => 'Int' ] ],
				'title'       => $string(),
				'submissions' => [ 'type' => 'Int' ],
				'createdAt'   => $string( 'Y-m-d H:i:s in the site timezone.' ),
			],
		]
	);
	register_graphql_input_type(
		'HbFormFieldInput',
		[
			'description' => 'The value of a field.',
			'fields'      => [
				'name'  => [
					'type'        => [ 'non_null' => 'String' ],
					'description' => 'The name of the field. Values for extensions, like the reCAPTCHA token, start with _ (_barrio_recaptcha).',
				],
				'value' => $string( 'Checkboxes: the values separated by commas, or "Label: true | Label: false". Files: {"content": base64, "filename": "…"} as JSON.' ),
			],
		]
	);

	register_graphql_field(
		'RootQuery',
		'hbForm',
		[
			'type'        => 'HBForm',
			'description' => 'A form, null if it does not exist or is in the trash.',
			'args'        => [
				'id'   => [ 'type' => 'Int' ],
				'lang' => $string( 'A language code, like en. Without it, or if it is not active, the main language.' ),
			],
			'resolve'     => static fn( mixed $root, array $args ): ?array => barrio_wpgraphql_form( (int) ( $args['id'] ?? 0 ), $args['lang'] ?? null ),
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbForms',
		[
			'type'        => [ 'list_of' => 'HBFormListItem' ],
			'description' => 'The forms that are not in the trash, the newest first. Needs a user who can edit posts or manage the entries.',
			'resolve'     => 'barrio_wpgraphql_forms',
		]
	);
	register_graphql_mutation(
		'hbSubmitForm',
		[
			'description'         => 'Submits a form. A rejected submission comes back with success false, not as a GraphQL error.',
			'inputFields'         => [
				'formId'       => [ 'type' => [ 'non_null' => 'Int' ] ],
				'fields'       => [ 'type' => [ 'non_null' => [ 'list_of' => 'HbFormFieldInput' ] ] ],
				'lang'         => $string( 'The language the form was shown in.' ),
				'pageUrl'      => $string( 'The page of the form: saved without query string and fragment.' ),
				'pageTitle'    => $string( 'Ignored: not saved.' ),
				'pageReferrer' => $string( 'Ignored: not saved.' ),
			],
			'outputFields'        => [
				'success'      => [ 'type' => [ 'non_null' => 'Boolean' ] ],
				'message'      => $string( 'The success message, or what went wrong.' ),
				'submissionId' => [ 'type' => 'Int' ],
				'errors'       => [
					'type'        => [ 'list_of' => 'String' ],
					'description' => 'One per field, as "Label: error".',
				],
				'debug'        => [
					'type'        => [ 'list_of' => 'String' ],
					'description' => 'Always null: use the WPGraphQL debug mode.',
				],
			],
			'mutateAndGetPayload' => 'barrio_wpgraphql_submit',
		]
	);
}

/** @return array<string, mixed>|null */
function barrio_wpgraphql_form( int $id, mixed $lang ): ?array {
	$form = FormRepository::get( $id );
	if ( null === $form || FormStatus::Trash === $form->status ) {
		return null;
	}
	$lang  = I18n::resolve( $lang );
	$text  = I18n::pick( $form->i18n, $lang );
	$steps = [ [ 'fields' => [] ] ];
	foreach ( $form->current->fields as $field ) {
		if ( 'page_break' === $field->type ) {
			$steps[] = [ 'fields' => [] ];
		} else {
			$steps[ array_key_last( $steps ) ]['fields'][] = barrio_wpgraphql_field( $field, I18n::pick( $field->i18n, $lang ) );
		}
	}
	return [
		'id'        => $form->id,
		// Il titolo è interno, come in hbForms.
		'title'     => current_user_can( 'edit_posts' ) || current_user_can( 'barrio_manage_entries' ) ? $form->title : null,
		'createdAt' => get_date_from_gmt( $form->created_at ),
		'updatedAt' => get_date_from_gmt( $form->updated_at ),
		'fields'    => array_merge( ...array_column( $steps, 'fields' ) ),
		'steps'     => $steps,
		'settings'  => [
			'submitText'      => $text['submit_label'] ?? '',
			// Vuoto, come nel prototipo: il frontend mostra il suo messaggio standard, già tradotto.
			'successMessage'  => $text['success_message'] ?? '',
			'errorMessage'    => '',
			'afterSubmit'     => isset( $text['success_url'] ) ? 'redirect' : 'message',
			'redirectUrl'     => $text['success_url'] ?? '',
			// Così i frontend del prototipo inviano pageUrl, che diventa la pagina della registrazione.
			'displayPageInfo' => true,
		],
	];
}

/**
 * @param array{label?: string, placeholder?: string|null, error_message?: string|null, options?: array<string, string>|null} $text
 * @return array<string, mixed>
 */
function barrio_wpgraphql_field( Field $field, array $text ): array {
	$config = $field->config;
	// I frontend del prototipo non conoscono il consenso: lo mostrano come la checkbox con una sola scelta
	// che il prototipo usava per la privacy, con il link nell'etichetta della scelta.
	$consent = 'consent' === $field->type;
	return [
		'id'               => $field->key,
		'name'             => $field->key,
		'type'             => $consent ? 'checkbox' : $field->type,
		'label'            => $consent ? '' : FieldTypes::label_html( $text['label'] ?? '' ),
		'placeholder'      => $text['placeholder'] ?? '',
		'errorMessage'     => $text['error_message'] ?? '',
		'required'         => $field->required,
		'pattern'          => $config['pattern'] ?? ( 'email' === $field->type ? BARRIO_WPGRAPHQL_EMAIL : '' ),
		'min'              => isset( $config['min'] ) ? (string) $config['min'] : null,
		'max'              => isset( $config['max'] ) ? (string) $config['max'] : null,
		'format'           => 'datepicker' === $field->type ? 'dd/MM/yyyy' : '',
		'options'          => $consent ? [
			[
				'value' => '1',
				'label' => FieldTypes::label_html( $text['label'] ?? '' ),
			],
		] : FieldTypes::choices( $field, $text ),
		'conditionalLogic' => null === $field->conditions ? null : [
			'enabled'   => true,
			'action'    => $field->conditions['action'],
			'logicType' => $field->conditions['logic'],
			'rules'     => array_map(
				static fn( array $rule ): array => [
					'fieldName' => $rule['field'],
					'operator'  => $rule['operator'],
					'value'     => $rule['value'] ?? '',
				],
				(array) $field->conditions['rules']
			),
		],
	];
}

/** @return list<array<string, mixed>> */
function barrio_wpgraphql_forms(): array {
	// Come GET /barrio/v1/forms: titoli e conteggi non sono pubblici, al contrario del prototipo.
	if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'barrio_manage_entries' ) ) {
		throw new UserError( esc_html__( 'Sorry, you are not allowed to list the forms.', 'barrio-suite-wpgraphql' ) );
	}
	$forms = [];
	foreach ( FormRepository::all() as $form ) {
		if ( FormStatus::Trash->value !== $form['status'] ) {
			$forms[] = [
				'id'          => $form['id'],
				'title'       => $form['title'],
				'submissions' => current_user_can( 'barrio_manage_forms' ) || current_user_can( 'barrio_manage_entries' ) ? $form['entries'] : null,
				'createdAt'   => get_date_from_gmt( $form['created_at'] ),
			];
		}
	}
	return $forms;
}

/**
 * La pipeline di invio del core, come la REST. Un invio rifiutato torna con success false, come nel
 * prototipo; un'eccezione la gestisce WPGraphQL (il messaggio solo con il suo debug attivo).
 *
 * @param array{formId: int, fields: list<array{name: string, value?: string|null}>, lang?: string|null, pageUrl?: string|null} $input
 * @return array<string, mixed>
 */
function barrio_wpgraphql_submit( array $input ): array {
	$form = FormRepository::get( $input['formId'] );
	$lang = I18n::resolve( $input['lang'] ?? null );
	if ( null === $form || FormStatus::Trash === $form->status ) {
		return barrio_wpgraphql_failure( I18n::in( $lang, static fn(): string => __( 'Form not found.', 'barrio-suite-wpgraphql' ) ) );
	}

	$fields = [];
	foreach ( $form->current->fields as $field ) {
		$fields[ $field->key ] = $field;
	}
	$values = [];
	$files  = [];
	foreach ( $input['fields'] as $item ) {
		$key   = $item['name'];
		$value = (string) ( $item['value'] ?? '' );
		$type  = isset( $fields[ $key ] ) ? $fields[ $key ]->type : null;
		if ( 'file' === $type ) {
			// Con il campo ripetuto, il file temporaneo di una seconda copia non verrebbe mai cancellato.
			$files[ $key ] ??= barrio_wpgraphql_file( $value );
		} elseif ( 'checkbox' === $type ) {
			$values[ $key ] = barrio_wpgraphql_checked( $fields[ $key ], $value, $lang );
		} elseif ( 'consent' === $type ) {
			// La checkbox di barrio_wpgraphql_field(): "Etichetta: true" se spuntata, oppure il valore 1.
			$values[ $key ] = preg_match( '/: true$/', $value ) ? '1' : $value;
		} elseif ( 'datepicker' === $type ) {
			// Il formato di HBFormField.format; la pipeline vuole Y-m-d.
			$values[ $key ] = preg_replace( '#^(\d{2})[/.-](\d{2})[/.-](\d{4})$#', '$3-$2-$1', trim( $value ) );
		} else {
			$values[ $key ] = $value;
		}
	}
	$values['_barrio_lang'] = $lang;
	$values['_barrio_url']  = $input['pageUrl'] ?? null;
	// Il segno del JavaScript serve contro i bot che inviano l'HTML: un client GraphQL non ne ha. Restano
	// il limite per IP e i captcha delle estensioni (_barrio_recaptcha tra i campi).
	$values['_barrio_js'] = '1';
	$files                = array_filter( $files );

	// Con l'application password di un frontend headless l'IP è quello del suo server, lo stesso per tutti
	// i visitatori: il limite per IP bloccherebbe l'intero sito. Vale solo per le richieste anonime.
	$ip = is_user_logged_in() ? null : sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	try {
		$entry = I18n::in( $lang, static fn() => Submission::process( $form, $values, $files, $ip ?: null ) );
	} finally {
		// Quelli accettati sono già stati spostati nella cartella protetta.
		foreach ( $files as $file ) {
			wp_delete_file( $file['tmp_name'] ?? '' );
		}
	}

	if ( $entry instanceof WP_Error ) {
		$errors = [];
		foreach ( (array) ( $entry->get_error_data()['fields'] ?? [] ) as $key => $error ) {
			$label    = isset( $fields[ $key ] ) ? wp_strip_all_tags( I18n::pick( $fields[ $key ]->i18n, $lang )['label'] ?? '' ) : '';
			$errors[] = '' === $label ? (string) $error : "{$label}: {$error}";
		}
		return barrio_wpgraphql_failure( $errors[0] ?? $entry->get_error_message(), $errors );
	}
	return [
		'success'      => true,
		'message'      => I18n::pick( $form->i18n, $lang )['success_message'] ?? I18n::in( $lang, static fn(): string => __( 'Thank you, your submission has been received.', 'barrio-suite-wpgraphql' ) ),
		'submissionId' => $entry->id,
		'errors'       => [],
		'debug'        => null,
	];
}

/**
 * @param list<string> $errors
 * @return array<string, mixed>
 */
function barrio_wpgraphql_failure( string $message, array $errors = [] ): array {
	return [
		'success'      => false,
		'message'      => $message,
		'submissionId' => null,
		'errors'       => $errors ?: [ $message ],
		'debug'        => null,
	];
}

/**
 * Il file del prototipo, {"content": base64, "filename": "…"}, in un file temporaneo che passa da
 * FileStore::check() come un upload: il nome scelto dal client non decide né dove né come si salva.
 *
 * @return array{name: string, tmp_name?: string, error: int}|null Un elemento di $_FILES; null senza file.
 */
function barrio_wpgraphql_file( string $value ): ?array {
	if ( '' === $value ) {
		return null;
	}
	$data    = json_decode( $value, true );
	$name    = is_array( $data ) && is_string( $data['filename'] ?? null ) && '' !== $data['filename'] ? $data['filename'] : 'file';
	$encoded = is_array( $data ) && is_string( $data['content'] ?? null ) ? $data['content'] : null;
	// Prima di decodificare, che raddoppia la memoria.
	if ( strlen( (string) $encoded ) > wp_max_upload_size() * 4 / 3 + 4 ) {
		return [
			'name'  => $name,
			'error' => UPLOAD_ERR_INI_SIZE,
		];
	}
	$content = null === $encoded ? false : base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- il file inviato in base64.
	// tempnam() e non wp_tempnam(), che sta nei file dell'admin e in una richiesta GraphQL non c'è.
	$tmp = false === $content ? false : tempnam( get_temp_dir(), 'barrio' );
	if ( false === $tmp || false === file_put_contents( $tmp, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		// Un upload non riuscito: FileStore::check() risponde con il suo messaggio.
		return [
			'name'  => $name,
			'error' => UPLOAD_ERR_PARTIAL,
		];
	}
	return [
		'name'     => $name,
		'tmp_name' => $tmp,
		'error'    => UPLOAD_ERR_OK,
	];
}

/**
 * Le checkbox dei frontend del prototipo: "Etichetta: true | Etichetta: false", con le etichette nella
 * lingua del form, oppure i valori separati da virgole.
 *
 * @return list<string> I valori delle scelte; uno sconosciuto resta com'è e la pipeline lo rifiuta.
 */
function barrio_wpgraphql_checked( Field $field, string $value, string $lang ): array {
	$choices = FieldTypes::choices( $field, I18n::pick( $field->i18n, $lang ) );
	$values  = array_column( $choices, 'value', 'label' ) + array_column( $choices, 'value', 'value' );
	if ( isset( $values[ $value ] ) ) {
		return [ $values[ $value ] ];
	}
	$parts = explode( ' | ', $value );
	if ( preg_match( '/: (true|false)$/', $parts[0] ) ) {
		$checked = array_map( static fn( string $part ): string => substr( $part, 0, -6 ), array_filter( $parts, static fn( string $part ): bool => str_ends_with( $part, ': true' ) ) );
	} else {
		// ponytail: una scelta con una virgola si riconosce solo se è l'unica; altrimenti va inviata come "Etichetta: true".
		$checked = explode( ',', $value );
	}
	return array_values( array_map( static fn( string $choice ): string => $values[ trim( $choice ) ] ?? trim( $choice ), $checked ) );
}
