<?php

namespace BarrioSuite\Forms\Api;

use BarrioSuite\Forms\I18n;
use BarrioSuite\Forms\MergeTags;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\NoteLevel;
use BarrioSuite\Forms\Model\Notification;
use BarrioSuite\Forms\Notifications;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Forms\Submission\FileStore;
use Closure;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Rotte delle registrazioni, per chi ha barrio_manage_entries (anche gli editor, che non usano il
 * builder). Etichette e valori sono nella lingua principale, quella di chi gestisce il sito.
 *
 * @phpstan-type Filters array{search: string, read: bool|null, lang: string|null}
 */
final class RestEntries {

	public const PER_PAGE = 20;

	public static function register(): void {
		$manage  = static fn(): bool => current_user_can( 'barrio_manage_entries' );
		$filters = [
			'search' => [
				'type'    => 'string',
				'default' => '',
			],
			// Assente = lette e non lette.
			'read'   => [ 'type' => 'boolean' ],
			'lang'   => [
				'type'    => 'string',
				'pattern' => '^[a-z]{2,3}(-[a-z0-9]{2,8})*$',
			],
		];
		// Si aggiunge al POST pubblico della stessa rotta (RestPublic).
		register_rest_route(
			'barrio/v1',
			'/forms/(?P<id>\d+)/entries',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list' ],
				'permission_callback' => $manage,
				'args'                => $filters + [
					// field:chiave ordina sul valore di un campo.
					'sort'  => [
						'type'    => 'string',
						'pattern' => '^(id|created_at|lang|field:[a-z][a-z0-9_]*)$',
						'default' => 'created_at',
					],
					'order' => [
						'type'    => 'string',
						'enum'    => [ 'asc', 'desc' ],
						'default' => 'desc',
					],
					'page'  => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					],
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/forms/(?P<id>\d+)/entries/export',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'export' ],
				'permission_callback' => $manage,
				'args'                => $filters,
			]
		);
		register_rest_route(
			'barrio/v1',
			'/forms/(?P<id>\d+)/entries/bulk',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'bulk' ],
				'permission_callback' => $manage,
				'args'                => [
					'action' => [
						'type'     => 'string',
						'enum'     => [ 'read', 'unread', 'delete' ],
						'required' => true,
					],
					'ids'    => [
						'type'     => 'array',
						'items'    => [ 'type' => 'integer' ],
						'minItems' => 1,
					],
					// Al posto di ids, esplicito: un ids dimenticato non deve diventare "tutte".
					'all'    => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/entries/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'entry' ],
				'permission_callback' => $manage,
			]
		);
		register_rest_route(
			'barrio/v1',
			'/entries/(?P<id>\d+)/notes',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'add_note' ],
				'permission_callback' => $manage,
				'args'                => [
					'message' => [
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 16000,
						'required'  => true,
					],
				],
			]
		);
		// file è la posizione del file nella registrazione, come la restituisce il dettaglio.
		register_rest_route(
			'barrio/v1',
			'/entries/(?P<id>\d+)/files/(?P<file>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'download' ],
				'permission_callback' => $manage,
			]
		);
		register_rest_route(
			'barrio/v1',
			'/entries/(?P<id>\d+)/notifications/(?P<notification>\d+)/resend',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'resend' ],
				'permission_callback' => $manage,
			]
		);
	}

	/** Una pagina dell'elenco, con le colonne: un campo per field_key, di tutte le versioni. */
	public static function list( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = self::form( $request );
		if ( $form instanceof WP_Error ) {
			return $form;
		}
		$lang                 = I18n::languages()[0];
		[ $columns, $fields ] = self::fields( $form->id );
		$sort                 = (string) $request['sort'];
		if ( str_starts_with( $sort, 'field:' ) ) {
			$key  = substr( $sort, 6 );
			$sort = array_keys( array_filter( $fields, static fn( Field $field ): bool => $field->key === $key ) );
		}
		$page = EntryRepository::page( $form->id, self::filters( $request ), $sort, (string) $request['order'], (int) $request['page'], self::PER_PAGE );

		return new WP_REST_Response(
			[
				'columns' => array_map(
					static fn( Field $field ): array => [
						'key'   => $field->key,
						'type'  => $field->type,
						'label' => self::label( $field, $lang ),
					],
					array_values( $columns )
				),
				'rows'    => array_map(
					static fn( Entry $entry ): array => [
						'id'      => $entry->id,
						'is_read' => $entry->is_read,
						'lang'    => $entry->lang,
						'date'    => self::date( (string) $entry->created_at ),
						// Un oggetto anche se vuoto: [] in JSON sarebbe una lista.
						'values'  => (object) self::values( $entry, $fields, $lang ),
					],
					$page['entries']
				),
				'total'   => $page['total'],
			]
		);
	}

	// Con gli stessi filtri dell'elenco.
	public static function export( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = self::form( $request );
		if ( $form instanceof WP_Error ) {
			return $form;
		}
		$filters = self::filters( $request );
		return self::stream(
			static function () use ( $form, $filters ): void {
				$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- stream della risposta.
				if ( false !== $out ) {
					self::csv( $out, $form->id, $filters );
				}
			},
			'text/csv; charset=utf-8',
			( sanitize_title( $form->title ) ?: "form-{$form->id}" ) . '-' . wp_date( 'Y-m-d' ) . '.csv'
		);
	}

	public static function bulk( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $request['all'] && ! is_array( $request['ids'] ) ) {
			return new WP_Error( 'rest_missing_callback_param', __( 'No entries selected.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		$ids     = $request['all'] ? null : array_values( array_map( 'intval', (array) $request['ids'] ) );
		$form_id = (int) $request['id'];
		if ( 'delete' === $request['action'] ) {
			return new WP_REST_Response( [ 'deleted' => EntryRepository::delete_many( $form_id, $ids ) ] );
		}
		EntryRepository::set_read( $form_id, $ids, 'read' === $request['action'] );
		return new WP_REST_Response( [ 'read' => 'read' === $request['action'] ] );
	}

	// Tutti i campi della versione compilata, anche quelli vuoti, con i testi che il visitatore ha letto.
	public static function entry( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$entry   = EntryRepository::get( (int) $request['id'] );
		$form    = null === $entry ? null : FormRepository::get( $entry->form_id );
		$version = null === $entry ? null : FormRepository::version( $entry->version_id );
		if ( null === $entry || null === $form || null === $version ) {
			return self::not_found();
		}

		$lang   = I18n::languages()[0];
		$fields = [];
		foreach ( $version->fields as $field ) {
			if ( 'page_break' === $field->type ) {
				continue;
			}
			$files = [];
			foreach ( $entry->files as $index => $file ) {
				if ( $file['field_id'] === $field->id ) {
					$files[] = [
						'index' => $index,
						'name'  => $file['original_name'],
						'size'  => size_format( $file['size_bytes'] ),
					];
				}
			}
			$values   = $entry->values[ (int) $field->id ] ?? [];
			$fields[] = [
				'key'   => $field->key,
				'label' => self::label( $field, $lang ),
				'value' => $values ? MergeTags::display( $field, I18n::pick( $field->i18n, $lang ), $values ) : '',
				'files' => $files,
			];
		}

		return new WP_REST_Response(
			[
				'id'            => $entry->id,
				'is_read'       => $entry->is_read,
				'date'          => self::date( (string) $entry->created_at ),
				'lang'          => $entry->lang,
				'source_url'    => $entry->source_url,
				'user'          => self::user( $entry->user_id ),
				'form'          => [
					'id'      => $form->id,
					'title'   => $form->title,
					'version' => $version->version,
				],
				'fields'        => $fields,
				// Si rinviano con la configurazione attuale: quelle del form, non della versione.
				'notifications' => array_map(
					static fn( Notification $notification ): array => [
						'id'   => $notification->id,
						'name' => $notification->name,
					],
					$form->notifications
				),
				'notes'         => self::notes( (int) $entry->id ),
			]
		);
	}

	public static function add_note( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];
		if ( null === EntryRepository::get( $id ) ) {
			return self::not_found();
		}
		EntryRepository::add_note( $id, 'admin', NoteLevel::Info, (string) $request['message'], get_current_user_id() ?: null );
		return new WP_REST_Response( self::notes( $id ), 201 );
	}

	public static function download( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$file = EntryRepository::get( (int) $request['id'] )?->files[ (int) $request['file'] ] ?? null;
		$path = null === $file ? '' : FileStore::dir() . '/' . $file['stored_path'];
		if ( null === $file || ! is_file( $path ) ) {
			return new WP_Error( 'barrio_file_not_found', __( 'File not found.', 'barrio-suite' ), [ 'status' => 404 ] );
		}
		$response = self::stream(
			static function () use ( $path ): void {
				readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- file della cartella protetta.
			},
			$file['mime_type'],
			$file['original_name']
		);
		$response->header( 'Content-Length', (string) filesize( $path ) );
		return $response;
	}

	// L'esito, riuscito o no, finisce comunque nelle note della registrazione.
	public static function resend( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return Notifications::resend( (int) $request['id'], (int) $request['notification'] ) ?? new WP_REST_Response( [ 'sent' => true ] );
	}

	// Filtro rest_pre_serve_request: le risposte di stream() inviano il corpo al posto del JSON.
	// WordPress ha già inviato stato e header.
	public static function serve( bool $served, WP_HTTP_Response $result ): bool {
		$body = $result->get_data();
		if ( $served || ! $body instanceof Closure ) {
			return $served;
		}
		$body();
		return true;
	}

	/**
	 * Una riga per registrazione, dalla più vecchia, letta a blocchi: la memoria non cresce con il
	 * numero di righe. Una colonna per campo, anche per quelli rimasti solo nelle versioni precedenti.
	 *
	 * @param resource $out
	 * @param Filters  $filters
	 */
	public static function csv( $out, int $form_id, array $filters, int $chunk = 500 ): void {
		global $wp_locale;
		$lang                 = I18n::languages()[0];
		[ $columns, $fields ] = self::fields( $form_id );
		// Come Excel: punto e virgola dove la virgola è il separatore decimale (in italiano, per esempio).
		$separator = ',' === $wp_locale->number_format['decimal_point'] ? ';' : ',';
		$write     = static fn( array $cells ): int|false => fputcsv( $out, array_map( self::cell( ... ), $cells ), $separator, '"', '' );

		// Senza il BOM Excel legge l'UTF-8 come Windows-1252 e rovina le lettere accentate.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$write( [ 'ID', __( 'Date', 'barrio-suite' ), __( 'Language', 'barrio-suite' ), __( 'Page', 'barrio-suite' ), ...array_map( static fn( Field $field ): string => self::label( $field, $lang ), array_values( $columns ) ) ] );
		$after = null;
		do {
			$entries = EntryRepository::chunk( $form_id, $filters, $after, $chunk );
			foreach ( $entries as $entry ) {
				$values = self::values( $entry, $fields, $lang );
				$write( [ $entry->id, self::date( (string) $entry->created_at, 'Y-m-d H:i:s' ), $entry->lang, $entry->source_url, ...array_map( static fn( string $key ): string => $values[ $key ] ?? '', array_keys( $columns ) ) ] );
			}
			$after = end( $entries ) ?: null;
			$more  = count( $entries ) === $chunk;
		} while ( $more );
	}

	/** @return Filters */
	private static function filters( WP_REST_Request $request ): array {
		return [
			'search' => (string) $request['search'],
			'read'   => null === $request['read'] ? null : (bool) $request['read'],
			'lang'   => is_string( $request['lang'] ) ? $request['lang'] : null,
		];
	}

	/**
	 * I campi di tutte le versioni del form: per id, per leggere i valori di ogni registrazione, e per
	 * field_key, uno per colonna con la definizione più recente. Prima quelli della versione corrente,
	 * poi quelli rimasti solo nelle versioni precedenti.
	 *
	 * @return array{array<string, Field>, array<int, Field>}
	 */
	private static function fields( int $form_id ): array {
		$columns = [];
		$fields  = [];
		foreach ( FormRepository::versions( $form_id ) as $version ) {
			foreach ( $version->fields as $field ) {
				if ( 'page_break' !== $field->type ) {
					$fields[ (int) $field->id ] = $field;
					$columns[ $field->key ]   ??= $field;
				}
			}
		}
		return [ $columns, $fields ];
	}

	/**
	 * Per field_key, come li legge una persona: per le scelte l'etichetta, per i file il nome.
	 *
	 * @param array<int, Field> $fields Per id, di tutte le versioni.
	 * @return array<string, string>
	 */
	private static function values( Entry $entry, array $fields, string $lang ): array {
		$values = [];
		foreach ( $entry->values as $id => $list ) {
			$field = $fields[ $id ] ?? null;
			if ( null !== $field ) {
				$values[ $field->key ] = MergeTags::display( $field, I18n::pick( $field->i18n, $lang ), $list );
			}
		}
		foreach ( $entry->files as $file ) {
			$key = ( $fields[ $file['field_id'] ] ?? null )?->key;
			if ( null !== $key ) {
				$values[ $key ] = isset( $values[ $key ] ) ? "{$values[ $key ]}, {$file['original_name']}" : $file['original_name'];
			}
		}
		return $values;
	}

	/** @return list<array{level: string, message: string, author: string|null, date: string}> */
	private static function notes( int $entry_id ): array {
		return array_map(
			static fn( array $note ): array => [
				'level'   => $note['level'],
				'message' => $note['message'],
				// Chi l'ha scritta o ha chiesto il rinvio; null per le note del plugin.
				'author'  => self::user( $note['user_id'] ),
				'date'    => self::date( $note['created_at'] ),
			],
			EntryRepository::notes( $entry_id )
		);
	}

	// Il body è una Closure che scrive il file: la invia serve(). nosniff: un file inviato da un
	// visitatore (un HTML dentro un .txt) non deve diventare una pagina del sito.
	private static function stream( Closure $body, string $type, string $name ): WP_REST_Response {
		// filename per i client che non conoscono filename* (RFC 6266), che ha il nome vero.
		$ascii = (string) preg_replace( '/[^\x20-\x7e]|["\\\\]/', '_', remove_accents( $name ) );
		return new WP_REST_Response(
			$body,
			200,
			[
				'Content-Type'           => $type,
				'Content-Disposition'    => sprintf( 'attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode( $name ) ),
				'X-Content-Type-Options' => 'nosniff',
			]
		);
	}

	// CSV injection: un foglio di calcolo esegue come formula una cella che inizia con = + - @ (o tab e a capo).
	private static function cell( mixed $value ): string {
		$value = (string) $value;
		return 1 === preg_match( '/^[=+\-@\t\r]/', $value ) ? "'{$value}" : $value;
	}

	private static function label( Field $field, string $lang ): string {
		return wp_strip_all_tags( I18n::pick( $field->i18n, $lang )['label'] ?? '' ) ?: $field->key;
	}

	// Salvate in UTC, mostrate nel fuso e nel formato del sito.
	private static function date( string $utc, ?string $format = null ): string {
		return (string) wp_date( $format ?? get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( "{$utc} UTC" ) );
	}

	// Null anche per un utente cancellato nel frattempo.
	private static function user( ?int $id ): ?string {
		$user = null === $id ? false : get_userdata( $id );
		return $user ? $user->display_name : null;
	}

	private static function form( WP_REST_Request $request ): Form|WP_Error {
		return FormRepository::get( (int) $request['id'] ) ?? new WP_Error( 'barrio_form_not_found', __( 'Form not found.', 'barrio-suite' ), [ 'status' => 404 ] );
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'barrio_not_found', __( 'Entry not found.', 'barrio-suite' ), [ 'status' => 404 ] );
	}
}
