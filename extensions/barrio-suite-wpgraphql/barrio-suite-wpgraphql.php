<?php
/**
 * Plugin Name:       Barrio Suite WPGraphQL
 * Description:       The Barrio Suite forms and SEO in WPGraphQL, with the names of the previous plugin (hbForm, hbSubmitForm, seo, hbSeoSite, hbSitemap…), so headless frontends keep working unchanged.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/hellobarrio/suite-barrio
 * Text Domain:       barrio-suite-wpgraphql
 * Domain Path:       /languages
 * Requires Plugins:  barrio-suite
 */

// L'unico codice che conosce WPGraphQL: lo schema hb* del prototipo con gli stessi nomi e tipi
// (tests/fixtures/wpgraphql-schema.txt e wpgraphql-seo-schema.txt), sopra la pipeline di invio dei form e
// la risoluzione unica del SEO (Seo\Meta). Senza WPGraphQL graphql_register_types non parte e non succede
// niente. Le descrizioni dello schema non si traducono: lo schema deve essere lo stesso in ogni lingua.

use BarrioSuite\Forms\FieldTypes;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\FormStatus;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Forms\Submission\Submission;
use BarrioSuite\I18n;
use BarrioSuite\Seo\Llms;
use BarrioSuite\Seo\Meta;
use BarrioSuite\Seo\Repository\RedirectRepository;
use BarrioSuite\Seo\Schema;
use BarrioSuite\Seo\Settings;
use BarrioSuite\Seo\Sitemaps;
use GraphQL\Deferred;
use GraphQL\Error\UserError;
use WPGraphQL\AppContext;
use WPGraphQL\Model\Post;
use WPGraphQL\Model\Term;

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

add_action(
	'barrio_seo_loaded',
	static function (): void {
		if ( ! function_exists( 'register_graphql_settings_field' ) ) {
			return;
		}
		// Priorità 9, come per i form: anche il prototipo registra lo schema SEO con il suo modulo spento.
		add_action( 'graphql_register_types', 'barrio_wpgraphql_register_seo', 9 );
		add_filter( 'barrio_seo_url', 'barrio_wpgraphql_frontend_url' );
		register_graphql_settings_field(
			'graphql_general_settings',
			[
				'name'              => 'barrio_frontend_url',
				'label'             => __( 'Frontend address', 'barrio-suite-wpgraphql' ),
				'desc'              => __( 'The address of the headless site, like https://www.example.com. In GraphQL the links of the Barrio Suite SEO (canonical, JSON-LD, llms.txt) point there instead of WordPress; images stay on WordPress.', 'barrio-suite-wpgraphql' ),
				'type'              => 'url',
				'sanitize_callback' => static fn( mixed $value ): string => untrailingslashit( esc_url_raw( (string) $value, [ 'http', 'https' ] ) ),
			]
		);
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

// Il SEO con lo schema del prototipo: seo sui contenuti, hbSeoSite, hbSchemaRaw, hbSitemap, hbRedirects, hbLlms e hbLlmsFull.
function barrio_wpgraphql_register_seo(): void {
	$string = static fn( string $description = '' ): array => [
		'type'        => 'String',
		'description' => $description,
	];

	register_graphql_object_type(
		'HBSeoPost',
		[
			'description' => 'The SEO data of a content, the same as in the head of WordPress.',
			'fields'      => [
				'title'         => $string( 'The SEO title, or the title of the content: without the site name.' ),
				'description'   => $string( 'The SEO description, or the excerpt, or the start of the content, or the default description.' ),
				'robots'        => $string( 'index or noindex, then follow or nofollow, separated by a comma.' ),
				'canonical'     => $string( 'The absolute URL of the page, on the frontend address when it is set.' ),
				'ogTitle'       => $string( 'Same as title.' ),
				'ogDescription' => $string( 'Same as description.' ),
				'ogImage'       => $string( 'The SEO image, or the featured image, or the default one: on WordPress.' ),
				'ogType'        => $string( 'article for posts, website for the rest.' ),
				'twitterCard'   => $string( 'Always summary_large_image.' ),
				'twitterTitle'  => $string( 'Same as title.' ),
				'twitterImage'  => $string( 'Same as ogImage.' ),
				'isIndexed'     => [ 'type' => 'Boolean' ],
				'isInSitemap'   => [
					'type'        => 'Boolean',
					'description' => 'Published, indexed and without a password.',
				],
				'keywords'      => [
					'type'        => [ 'list_of' => 'String' ],
					'description' => 'The first one is the main keyword.',
				],
			],
		]
	);
	foreach ( get_post_types(
		[
			'public'          => true,
			'show_in_graphql' => true,
		],
		'objects'
	) as $type ) {
		if ( 'attachment' !== $type->name ) {
			register_graphql_field(
				ucfirst( (string) ( $type->graphql_single_name ?? '' ) ),
				'seo',
				[
					'type'        => 'HBSeoPost',
					'description' => 'The SEO data of Barrio Suite.',
					'resolve'     => static fn( Post $post ): ?array => barrio_wpgraphql_seo( get_post( $post->ID ) ),
				]
			);
		}
	}

	register_graphql_object_type(
		'HBSeoSite',
		[
			'description' => 'The SEO settings of the site.',
			'fields'      => [
				'siteTitle'     => $string( 'The site name.' ),
				'separator'     => $string( 'Between the page title and the site name.' ),
				'titleOrder'    => $string( 'page_site or site_page.' ),
				'defaultDesc'   => $string( 'For the pages without a description of their own.' ),
				'robotsDefault' => $string( 'Always index,follow: every content says its own in seo.robots.' ),
				'ogImage'       => $string( 'The default sharing image.' ),
				'favicon'       => $string( 'The site icon.' ),
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbSeoSite',
		[
			'type'    => 'HBSeoSite',
			'resolve' => 'barrio_wpgraphql_seo_site',
		]
	);

	register_graphql_field(
		'RootQuery',
		'hbSchemaRaw',
		[
			'type'        => 'String',
			'description' => 'The JSON-LD for <script type="application/ld+json">: the organization and the site, plus the page and its breadcrumb when uri is an indexable content or term.',
			'args'        => [ 'uri' => $string( 'The URI of the page, like /about-us/.' ) ],
			// Il nodo di WPGraphQL arriva dopo, in un Deferred: la stessa risoluzione di nodeByUri.
			'resolve'     => static function ( mixed $root, array $args, AppContext $context ): mixed {
				$uri  = trim( (string) ( $args['uri'] ?? '' ) );
				$node = '' === $uri ? null : $context->node_resolver->resolve_uri( $uri );
				return $node instanceof Deferred ? $node->then( 'barrio_wpgraphql_schema' ) : barrio_wpgraphql_schema( $node );
			},
		]
	);

	register_graphql_object_type(
		'HBSitemapEntry',
		[
			'description' => 'An address of the sitemap of WordPress (wp-sitemap.xml).',
			'fields'      => [
				'loc'      => [
					'type'        => [ 'non_null' => 'String' ],
					'description' => 'The path, like /about-us/.',
				],
				'lastmod'  => $string( 'ISO 8601, contents only.' ),
				'type'     => [
					'type'        => [ 'non_null' => 'String' ],
					'description' => 'post, term or user (author archives).',
				],
				'subtype'  => [
					'type'        => [ 'non_null' => 'String' ],
					'description' => 'The post type, the taxonomy or author.',
				],
				'id'       => [
					'type'        => [ 'non_null' => 'Int' ],
					'description' => 'The ID of the content, term or author; 0 for the home page with the latest posts.',
				],
				'title'    => $string( 'The title of the content, the name of the term or of the author.' ),
				'priority' => [
					'type'        => 'Float',
					'description' => 'Always null: search engines ignore it.',
				],
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbSitemap',
		[
			'type'        => [ 'list_of' => 'HBSitemapEntry' ],
			'description' => 'The indexable addresses of every language, like wp-sitemap.xml.',
			'resolve'     => 'barrio_wpgraphql_sitemap',
		]
	);

	register_graphql_object_type(
		'HBRedirect',
		[
			'description' => 'A redirect of the SEO.',
			'fields'      => [
				'id'   => [ 'type' => [ 'non_null' => 'String' ] ],
				'from' => [
					'type'        => [ 'non_null' => 'String' ],
					'description' => 'The path, with the trailing slash of the permalinks unless it has a query string or a file extension.',
				],
				'to'   => $string( 'A path or an absolute URL; null for 410.' ),
				'code' => [
					'type'        => [ 'non_null' => 'Int' ],
					'description' => '301, 302, 307, 308 or 410.',
				],
				'note' => $string( 'Only for who manages the SEO.' ),
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbRedirects',
		[
			'type'    => [ 'list_of' => 'HBRedirect' ],
			'resolve' => 'barrio_wpgraphql_redirects',
		]
	);

	register_graphql_object_type(
		'LlmsContent',
		[
			'description' => 'llms.txt or llms-full.txt.',
			'fields'      => [
				'content'   => $string( 'Markdown.' ),
				'updatedAt' => $string( 'When it was generated, as a Unix timestamp.' ),
				'bytes'     => [ 'type' => 'Int' ],
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbLlms',
		[
			'type'    => 'LlmsContent',
			'resolve' => static fn(): array => barrio_wpgraphql_llms( false ),
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbLlmsFull',
		[
			'type'    => 'LlmsContent',
			'resolve' => static fn(): array => barrio_wpgraphql_llms( true ),
		]
	);
}

/**
 * Nelle risposte GraphQL gli URL delle pagine (barrio_seo_url) sono sull'indirizzo del frontend, se è impostato.
 * Con http o https: home_url() passa a https nelle richieste sicure anche se l'opzione dice http.
 */
function barrio_wpgraphql_frontend_url( string $url ): string {
	$frontend = (string) get_graphql_setting( 'barrio_frontend_url' );
	if ( '' === $frontend || ! is_graphql_request() ) {
		return $url;
	}
	$home = (string) preg_replace( '#^https?:#i', '', untrailingslashit( (string) get_option( 'home' ) ) );
	return preg_match( '#^https?:' . preg_quote( $home, '#' ) . '(?=$|[/?\#])#i', $url, $match ) ? $frontend . substr( $url, strlen( $match[0] ) ) : $url;
}

/**
 * ponytail: i testi predefiniti sono nella lingua della richiesta, non in quella del contenuto, perché il core
 * non sa la lingua di un contenuto; servirà un filtro del plugin multilingua se un sito tradotto ne ha bisogno.
 *
 * @return array<string, mixed>|null
 */
function barrio_wpgraphql_seo( ?WP_Post $post ): ?array {
	if ( null === $post ) {
		return null;
	}
	$meta   = Meta::post( $post );
	$image  = $meta['image'] ? wp_get_attachment_image_url( $meta['image'], 'full' ) : false;
	$robots = ( $meta['index'] ? 'index' : 'noindex' ) . ',' . ( $meta['follow'] ? 'follow' : 'nofollow' );
	return [
		'title'         => $meta['title'],
		'description'   => $meta['description'],
		'robots'        => $robots,
		'canonical'     => $meta['canonical'],
		'ogTitle'       => $meta['title'],
		'ogDescription' => $meta['description'],
		'ogImage'       => $image ?: null,
		'ogType'        => 'post' === $post->post_type ? 'article' : 'website',
		'twitterCard'   => 'summary_large_image',
		'twitterTitle'  => $meta['title'],
		'twitterImage'  => $image ?: null,
		'isIndexed'     => $meta['index'],
		// Come la sitemap (Seo\Meta::indexable()).
		'isInSitemap'   => $meta['index'] && 'publish' === $post->post_status && '' === $post->post_password,
		'keywords'      => $meta['keywords'],
	];
}

/** @return array<string, string|null> */
function barrio_wpgraphql_seo_site(): array {
	$settings = Settings::get();
	return [
		'siteTitle'     => Meta::text( get_bloginfo( 'name' ) ),
		'separator'     => $settings['title']['separator'],
		'titleOrder'    => $settings['title']['order'],
		'defaultDesc'   => Meta::default_description(),
		'robotsDefault' => 'index,follow',
		'ogImage'       => wp_get_attachment_image_url( $settings['default_image'], 'full' ) ?: null,
		'favicon'       => wp_get_attachment_image_url( (int) get_option( 'site_icon' ), 'full' ) ?: null,
	];
}

/**
 * Il grafo di Seo\Schema. Come nell'head, la pagina c'è solo se è indicizzabile; restano organizzazione e sito.
 *
 * @param mixed $node Il nodo dell'URI secondo WPGraphQL: un contenuto, un termine, un archivio o null.
 */
function barrio_wpgraphql_schema( mixed $node ): string {
	$object = match ( true ) {
		$node instanceof Post => get_post( $node->ID ),
		$node instanceof Term => get_term( $node->term_id ),
		default               => null,
	};
	$meta = match ( true ) {
		$object instanceof WP_Post => Meta::post( $object ),
		$object instanceof WP_Term => Meta::term( $object ),
		default                    => null,
	};
	$graph = null !== $meta && $meta['index'] ? Schema::graph( $meta, $object ) : Schema::graph( null );
	return (string) wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG );
}

/**
 * Gli indirizzi di wp-sitemap.xml in tutte le lingue (Seo\Sitemaps::each()), con il contenuto, il termine o
 * l'autore che il core passa ai filtri delle voci. loc è relativo, come nel prototipo: il frontend aggiunge il suo dominio.
 *
 * @return list<array<string, mixed>>
 */
function barrio_wpgraphql_sitemap(): array {
	$filters = [
		'wp_sitemaps_posts_entry'               => static fn( array $entry, WP_Post $post ): array => $entry + [
			'type'    => 'post',
			'subtype' => $post->post_type,
			'id'      => $post->ID,
			'title'   => Meta::text( $post->post_title ),
		],
		// La home con gli ultimi articoli, prima delle pagine.
		'wp_sitemaps_posts_show_on_front_entry' => static fn( array $entry ): array => $entry + [
			'type'    => 'post',
			'subtype' => 'page',
			'id'      => 0,
			'title'   => Meta::text( get_bloginfo( 'name' ) ),
		],
		'wp_sitemaps_taxonomies_entry'          => static fn( array $entry, int $term_id, string $taxonomy, WP_Term $term ): array => $entry + [
			'type'    => 'term',
			'subtype' => $taxonomy,
			'id'      => $term_id,
			'title'   => Meta::text( $term->name ),
		],
		'wp_sitemaps_users_entry'               => static fn( array $entry, WP_User $user ): array => $entry + [
			'type'    => 'user',
			'subtype' => 'author',
			'id'      => $user->ID,
			'title'   => Meta::text( $user->display_name ),
		],
	];
	foreach ( $filters as $hook => $filter ) {
		add_filter( $hook, $filter, 10, 4 );
	}
	$entries = [];
	try {
		Sitemaps::each(
			static function ( string $provider, string $subtype, array $urls ) use ( &$entries ): void {
				foreach ( $urls as $url ) {
					$entries[] = [
						'loc'      => wp_make_link_relative( (string) $url['loc'] ),
						'lastmod'  => $url['lastmod'] ?? null,
						'type'     => $url['type'] ?? $provider,
						'subtype'  => $url['subtype'] ?? $subtype,
						'id'       => $url['id'] ?? 0,
						'title'    => $url['title'] ?? null,
						'priority' => null,
					];
				}
			}
		);
	} finally {
		foreach ( $filters as $hook => $filter ) {
			remove_filter( $hook, $filter );
		}
	}
	return $entries;
}

/** @return list<array<string, mixed>> */
function barrio_wpgraphql_redirects(): array {
	$notes = current_user_can( 'barrio_manage_seo' );
	return array_map(
		static fn( array $redirect ): array => [
			'id'   => (string) $redirect['id'],
			// Le sorgenti sono senza / finale; i frontend del prototipo le confrontano con il percorso della pagina,
			// che ha quella dei permalink (trailingSlash di Next.js). I file come /pagina.html non l'hanno.
			'from' => '/' === $redirect['source'] || str_contains( $redirect['source'], '?' ) || str_contains( basename( $redirect['source'] ), '.' )
				? $redirect['source'] : user_trailingslashit( $redirect['source'] ),
			'to'   => $redirect['target'],
			'code' => $redirect['code']->value,
			'note' => $notes ? $redirect['note'] : null,
		],
		RedirectRepository::all()
	);
}

/** @return array{content: string, updatedAt: string, bytes: int} */
function barrio_wpgraphql_llms( bool $full ): array {
	$llms = Llms::get( $full );
	return [
		'content'   => $llms['text'],
		'updatedAt' => (string) $llms['time'],
		'bytes'     => strlen( $llms['text'] ),
	];
}
