<?php

namespace BarrioSuite\Forms\Api;

use BarrioSuite\Forms\Conditions;
use BarrioSuite\Forms\FieldTypes;
use BarrioSuite\Forms\I18n;
use BarrioSuite\Forms\Notifications;
use BarrioSuite\Forms\Frontend\Render;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormStatus;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Model\Integration;
use BarrioSuite\Forms\Model\Notification;
use BarrioSuite\Forms\Repository\FormRepository;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Rotte del builder. Il form ha lo stesso formato in lettura (GET /forms/{id}?context=edit, in
 * RestPublic) e in scrittura: titolo, testi per lingua, notifiche, campi e integrazioni come nei
 * modelli. È anche il formato di dev/example-form.json.
 */
final class RestForms {

	public static function register(): void {
		$manage = static fn(): bool => current_user_can( 'barrio_manage_forms' );
		register_rest_route(
			'barrio/v1',
			'/forms',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'list' ],
					// Anche per il blocco barrio/form, che sceglie il form dall'elenco, e per le registrazioni.
					'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ) || current_user_can( 'barrio_manage_entries' ) || current_user_can( 'barrio_manage_forms' ),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'create' ],
					'permission_callback' => $manage,
					'args'                => self::schema(),
				],
			]
		);
		// Si aggiungono al GET pubblico della stessa rotta (RestPublic).
		register_rest_route(
			'barrio/v1',
			'/forms/(?P<id>\d+)',
			[
				[
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'save' ],
					'permission_callback' => $manage,
					'args'                => self::schema() + [
						'base_version' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ self::class, 'set_status' ],
					'permission_callback' => $manage,
					'args'                => [
						'status' => [
							'type'     => 'string',
							'enum'     => array_column( FormStatus::cases(), 'value' ),
							'required' => true,
						],
					],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ self::class, 'delete' ],
					'permission_callback' => $manage,
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/forms/(?P<id>\d+)/duplicate',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'duplicate' ],
				'permission_callback' => $manage,
			]
		);
		// Con notification (l'indice di una notifica) l'anteprima è della sua email; con lang, in quella lingua.
		register_rest_route(
			'barrio/v1',
			'/forms/preview',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'preview' ],
				'permission_callback' => $manage,
				'args'                => self::schema() + [
					'notification' => [
						'type'    => 'integer',
						'minimum' => 0,
					],
					'lang'         => [ 'type' => 'string' ],
				],
			]
		);
	}

	public static function list(): WP_REST_Response {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		// Chi ha solo edit_posts sceglie il form del blocco, senza vederne le registrazioni.
		$counts = current_user_can( 'barrio_manage_forms' ) || current_user_can( 'barrio_manage_entries' );
		$forms  = [];
		foreach ( FormRepository::all() as $form ) {
			if ( ! $counts ) {
				unset( $form['entries'], $form['unread'] );
			}
			// updated_at per ordinare, modified per leggere.
			$forms[] = $form + [ 'modified' => wp_date( $format, (int) strtotime( "{$form['updated_at']} UTC" ) ) ];
		}
		return new WP_REST_Response( $forms );
	}

	public static function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::write( $request, null );
	}

	// 409 se nel frattempo qualcuno ha salvato: base_version è la versione da cui è partito il builder.
	public static function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::write( $request, (int) $request['id'] );
	}

	public static function set_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$status = FormStatus::from( (string) $request['status'] );
		return FormRepository::set_status( (int) $request['id'], $status )
			? new WP_REST_Response( [ 'status' => $status->value ] )
			: self::not_found();
	}

	public static function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return FormRepository::delete( (int) $request['id'] ) ?? new WP_REST_Response( [ 'deleted' => true ] );
	}

	public static function duplicate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = FormRepository::get( (int) $request['id'] );
		if ( null === $form ) {
			return self::not_found();
		}
		/* translators: %s: title of the copied form. */
		$title = mb_substr( sprintf( __( '%s (copy)', 'barrio-suite' ), $form->title ), 0, 191 );
		// create() ignora gli id: campi, notifiche e integrazioni diventano righe nuove.
		return new WP_REST_Response( self::view( FormRepository::create( $title, $form->i18n, $form->notifications, $form->current, $form->integrations ) ), 201 );
	}

	// Il form non salvato, con il render del sito, o una sua notifica: per le anteprime del builder.
	public static function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$version = self::version( $request['fields'] );
		foreach ( $version->fields as $field ) {
			$error = FieldTypes::check( $field );
			if ( null !== $error ) {
				return new WP_Error( 'barrio_invalid_form', "Field '{$field->key}': {$error}", [ 'status' => 400 ] );
			}
		}
		$now           = gmdate( 'Y-m-d H:i:s' );
		$notifications = self::notifications( $request['notifications'] );
		$form          = new Form( 0, (string) $request['title'], FormStatus::Active, $request['i18n'], $notifications, $version, $now, $now );
		$lang          = I18n::resolve( $request['lang'] );
		if ( null === $request['notification'] ) {
			return new WP_REST_Response( [ 'html' => Render::preview( $form, $lang ) ] );
		}
		$notification = $notifications[ (int) $request['notification'] ] ?? null;
		return null === $notification
			? new WP_Error( 'barrio_notification_not_found', __( 'Notification not found.', 'barrio-suite' ), [ 'status' => 404 ] )
			: new WP_REST_Response( Notifications::preview( $form, $notification, $lang ) );
	}

	/** @return array<string, mixed> Il formato delle rotte di scrittura, più id, stato e versione. */
	public static function view( Form $form ): array {
		return [
			'id'            => $form->id,
			'title'         => $form->title,
			'status'        => $form->status->value,
			'version'       => $form->current->version,
			'i18n'          => $form->i18n,
			'notifications' => array_map(
				static fn( Notification $n ): array => [
					'name'         => $n->name,
					'recipients'   => $n->recipients,
					'reply_to'     => $n->reply_to,
					'enabled'      => $n->enabled,
					'attach_files' => $n->attach_files,
					'conditions'   => $n->conditions,
					'i18n'         => $n->i18n,
				],
				$form->notifications
			),
			'fields'        => array_map(
				static fn( Field $f ): array => [
					'key'        => $f->key,
					'type'       => $f->type,
					'required'   => $f->required,
					// Un oggetto anche se vuoto: [] in JSON sarebbe una lista.
					'config'     => (object) $f->config,
					'conditions' => $f->conditions,
					'i18n'       => $f->i18n,
				],
				$form->current->fields
			),
			'integrations'  => array_map(
				static fn( Integration $i ): array => [
					'slug'       => $i->slug,
					'enabled'    => $i->enabled,
					'settings'   => (object) $i->settings,
					'conditions' => $i->conditions,
					'i18n'       => (object) array_map( static fn( array $settings ): object => (object) $settings, $i->i18n ),
				],
				$form->integrations
			),
		];
	}

	/**
	 * Gli argomenti di scrittura, con le lunghezze delle colonne: un valore troppo lungo è un 400,
	 * non un errore del database. Config dei campi e condizioni le ricontrolla FormRepository.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schema(): array {
		static $schema = null;
		if ( null !== $schema ) {
			return $schema;
		}
		// I TEXT di MySQL contengono 65535 byte: 16000 caratteri anche di 4 byte.
		$text       = 16000;
		$string     = static fn( int $max, int $min = 0 ): array => [
			'type'      => 'string',
			'minLength' => $min,
			'maxLength' => $max,
		];
		$optional   = static fn( int $max ): array => [
			'type'      => [ 'string', 'null' ],
			'maxLength' => $max,
		];
		$i18n       = static fn( array $properties, array $required ): array => [
			'type'                 => 'object',
			'required'             => true,
			'minProperties'        => 1,
			// Lo stesso codice di lingua del CHECK delle tabelle _i18n.
			'patternProperties'    => [
				'^[a-z]{2,3}(-[a-z0-9]{2,8})*$' => [
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => $required,
					'additionalProperties' => false,
				],
			],
			'additionalProperties' => false,
		];
		$list       = static fn( array $properties, array $required ): array => [
			'type'     => 'array',
			'required' => true,
			'items'    => [
				'type'                 => 'object',
				'properties'           => $properties,
				'required'             => $required,
				'additionalProperties' => false,
			],
		];
		$conditions = [ 'type' => [ 'object', 'null' ] ] + Conditions::schema();

		$schema = [
			'title'         => [ 'required' => true ] + $string( 191, 1 ),
			'i18n'          => $i18n(
				[
					'submit_label'    => $string( 191, 1 ),
					'success_message' => $optional( $text ),
					'success_url'     => [ 'pattern' => '^(/|https?://)' ] + $optional( 2048 ),
				],
				[ 'submit_label' ]
			),
			'notifications' => $list(
				[
					'name'         => $string( 191, 1 ),
					'recipients'   => $string( 1024, 1 ),
					'reply_to'     => $optional( 255 ),
					'enabled'      => [ 'type' => 'boolean' ],
					'attach_files' => [ 'type' => 'boolean' ],
					'conditions'   => $conditions,
					'i18n'         => $i18n(
						[
							'subject' => $string( 255, 1 ),
							'heading' => $optional( 255 ),
							'body'    => $string( $text ),
						],
						[ 'subject', 'body' ]
					),
				],
				[ 'name', 'recipients', 'i18n' ]
			),
			'fields'        => $list(
				[
					'key'        => [
						'type'      => 'string',
						'pattern'   => '^[a-z][a-z0-9_]*$',
						'maxLength' => 64,
					],
					'type'       => [
						'type' => 'string',
						'enum' => array_keys( FieldTypes::labels() ),
					],
					'required'   => [ 'type' => 'boolean' ],
					'config'     => [ 'type' => 'object' ],
					'conditions' => $conditions,
					'i18n'       => $i18n(
						[
							'label'         => $string( $text ),
							'placeholder'   => $optional( 255 ),
							'error_message' => $optional( $text ),
							'options'       => [
								'type'                 => [ 'object', 'null' ],
								'additionalProperties' => $string( 1000 ),
							],
						],
						[ 'label' ]
					),
				],
				[ 'key', 'type', 'i18n' ]
			),
			// Le impostazioni le valida FormRepository con lo schema dell'estensione. Assente = nessuna.
			'integrations'  => [
				'required' => false,
				'default'  => [],
			] + $list(
				[
					'slug'       => [
						'type'      => 'string',
						'pattern'   => '^[a-z][a-z0-9_-]*$',
						'maxLength' => 64,
					],
					'enabled'    => [ 'type' => 'boolean' ],
					'settings'   => [ 'type' => 'object' ],
					'conditions' => $conditions,
					'i18n'       => [
						'type'                 => 'object',
						'patternProperties'    => [ '^[a-z]{2,3}(-[a-z0-9]{2,8})*$' => [ 'type' => 'object' ] ],
						'additionalProperties' => false,
					],
				],
				[ 'slug' ]
			),
		];
		return $schema;
	}

	// I dati arrivano già validati e ripuliti dallo schema degli argomenti.
	private static function write( WP_REST_Request $request, ?int $id ): WP_REST_Response|WP_Error {
		$notifications = self::notifications( $request['notifications'] );
		$integrations  = self::integrations( $request['integrations'] );
		try {
			$form = null === $id
				? FormRepository::create( $request['title'], $request['i18n'], $notifications, self::version( $request['fields'] ), $integrations )
				: FormRepository::save( $id, $request['base_version'], $request['title'], $request['i18n'], $notifications, self::version( $request['fields'] ), $integrations );
		} catch ( InvalidArgumentException $e ) {
			// Config dei campi, condizioni, chiavi ripetute, impostazioni delle integrazioni: errori di chi
			// scrive il form, non del server.
			return new WP_Error( 'barrio_invalid_form', $e->getMessage(), [ 'status' => 400 ] );
		}
		return $form instanceof WP_Error ? $form : new WP_REST_Response( self::view( $form ), null === $id ? 201 : 200 );
	}

	/**
	 * @param list<array<string, mixed>> $notifications
	 * @return list<Notification>
	 */
	private static function notifications( array $notifications ): array {
		return array_map(
			static fn( array $n ): Notification => new Notification( $n['name'], $n['recipients'], $n['i18n'], $n['reply_to'] ?? null, $n['enabled'] ?? true, $n['attach_files'] ?? false, $n['conditions'] ?? null ),
			$notifications
		);
	}

	/**
	 * @param list<array<string, mixed>> $integrations
	 * @return list<Integration>
	 */
	private static function integrations( array $integrations ): array {
		return array_map(
			static fn( array $i ): Integration => new Integration( $i['slug'], $i['settings'] ?? [], $i['i18n'] ?? [], $i['enabled'] ?? true, $i['conditions'] ?? null ),
			$integrations
		);
	}

	/** @param list<array<string, mixed>> $fields */
	private static function version( array $fields ): FormVersion {
		return new FormVersion(
			array_map(
				static fn( array $f ): Field => new Field( $f['key'], $f['type'], $f['i18n'], $f['required'] ?? false, $f['config'] ?? [], $f['conditions'] ?? null ),
				$fields
			)
		);
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'barrio_form_not_found', __( 'Form not found.', 'barrio-suite' ), [ 'status' => 404 ] );
	}
}
