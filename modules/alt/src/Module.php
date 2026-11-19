<?php

namespace BarrioSuite\Alt;

use BarrioSuite\Admin;
use BarrioSuite\Ai;
use BarrioSuite\I18n;
use BarrioSuite\Roles;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Testi alternativi (PLAN-MODULI.md §8): l'alt delle immagini, e a scelta il titolo, scritti dall'AI del core
 * (src/Ai.php) guardando l'immagine. Dal pulsante nei dettagli dell'allegato, dalla pagina Barrio → Testi alternativi
 * e, se acceso, dopo il caricamento. Nessun dato nuovo: l'alt è il meta del core, il titolo è post_title.
 *
 * @phpstan-type Settings array{on_upload: bool, title: bool}
 * @phpstan-type Text array{alt: string, title: string}
 */
final class Module {

	// Chi genera (Permessi), oltre a upload_files e a edit_post sull'immagine. Dalla migrazione 0001.
	public const CAP = 'barrio_generate_alt';

	// Non in autoload: si legge nell'admin e dopo un caricamento.
	private const OPTION = 'barrio_alt';

	private const DEFAULTS = [
		'on_upload' => false,
		'title'     => false,
	];

	private const META = '_wp_attachment_image_alt';

	// L'evento singolo di WP-Cron dopo un caricamento: l'upload non aspetta Google.
	private const CRON = 'barrio_alt_generate';

	// Le immagini che Gemini legge; le altre (AVIF, GIF, HEIC) si convertono in JPEG.
	private const MIME = [ 'image/jpeg', 'image/png', 'image/webp' ];

	private const PER_PAGE = 20;

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_filter( 'barrio_admin_data', [ self::class, 'admin_data' ] );
		add_filter( 'wp_generate_attachment_metadata', [ self::class, 'uploaded' ], 10, 3 );
		add_action( self::CRON, [ self::class, 'cron' ] );
		add_filter( 'attachment_fields_to_edit', [ self::class, 'fields' ], 10, 2 );
		add_action( 'wp_enqueue_media', [ self::class, 'enqueue' ] );
		// Dopo i campi dell'immagine, che WordPress stampa con la priorità di default.
		add_action( 'edit_form_after_title', [ self::class, 'edit_screen' ], 11 );
	}

	public static function deactivate(): void {
		wp_unschedule_hook( self::CRON );
	}

	// L'opzione la elimina il core (src/Uninstall.php); gli alt sono del core e restano.
	public static function uninstall(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( self::CAP );
		}
	}

	public static function menu(): void {
		if ( self::can() ) {
			Admin::add_page( 'barrio-alt', _x( 'Alt text', 'module name', 'barrio-suite' ), self::CAP );
		}
	}

	/**
	 * In window.barrioAdmin.alt, per la pagina e la card della Bacheca, a chi genera. Senza, il JavaScript sa che il
	 * modulo è spento.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function admin_data( array $data ): array {
		return self::can() ? $data + [ 'alt' => [ 'ai' => Ai::is_ready() ] ] : $data;
	}

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'barrio/v1',
			'/alt/images',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'images' ],
				'permission_callback' => [ self::class, 'can' ],
				'args'                => [
					'search' => [
						'type'    => 'string',
						'default' => '',
					],
					// missing: senza testo alternativo.
					'alt'    => [
						'type'    => 'string',
						'enum'    => [ '', 'missing' ],
						'default' => '',
					],
					'sort'   => [
						'type'    => 'string',
						'enum'    => [ 'date', 'title' ],
						'default' => 'date',
					],
					'order'  => [
						'type'    => 'string',
						'enum'    => [ 'asc', 'desc' ],
						'default' => 'desc',
					],
					'page'   => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					],
					// ids: tutte quelle trovate che chi chiede può generare, senza pagine, per la massa.
					'fields' => [
						'type'    => 'string',
						'enum'    => [ '', 'ids' ],
						'default' => '',
					],
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/alt/(?P<id>\d+)',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'generate_route' ],
				'permission_callback' => static fn( WP_REST_Request $request ): bool => self::can() && current_user_can( 'edit_post', (int) $request['id'] ),
				'args'                => [
					// Scrive i testi generati, solo dove mancano.
					'save' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/alt/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get' ],
					'permission_callback' => $admin,
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'put' ],
					'permission_callback' => $admin,
					'args'                => [
						'on_upload' => [ 'type' => 'boolean' ],
						'title'     => [ 'type' => 'boolean' ],
						'roles'     => Roles::schema( [ self::CAP ] ),
					],
				],
			]
		);
	}

	/** @return Settings */
	public static function settings(): array {
		/** @var Settings */
		return array_replace( self::DEFAULTS, array_intersect_key( (array) get_option( self::OPTION, [] ), self::DEFAULTS ) );
	}

	public static function get(): WP_REST_Response {
		return new WP_REST_Response( self::settings() + Roles::get( [ self::CAP ] ) );
	}

	// Un valore assente resta com'è.
	public static function put( WP_REST_Request $request ): WP_REST_Response {
		update_option( self::OPTION, array_intersect_key( $request->get_params(), self::DEFAULTS ) + self::settings(), false );
		if ( is_array( $request['roles'] ) ) {
			Roles::save( [ self::CAP ], $request['roles'] );
		}
		return self::get();
	}

	// La pagina, la sua REST e i pulsanti: chi carica i media, tra i ruoli scelti nei Permessi.
	public static function can(): bool {
		return current_user_can( 'upload_files' ) && current_user_can( self::CAP );
	}

	// Le immagini, una pagina alla volta, con ricerca, filtro e ordinamento sul server, come le registrazioni.
	public static function images( WP_REST_Request $request ): WP_REST_Response {
		$ids   = 'ids' === $request['fields'];
		$query = new WP_Query(
			self::query( 'missing' === $request['alt'] ) + [
				's'                      => (string) $request['search'],
				'orderby'                => (string) $request['sort'],
				'order'                  => strtoupper( (string) $request['order'] ),
				'posts_per_page'         => $ids ? -1 : self::PER_PAGE,
				'paged'                  => (int) $request['page'],
				'fields'                 => $ids ? 'ids' : 'all',
				'update_post_term_cache' => false,
			]
		);
		if ( $ids ) {
			// ponytail: un controllo per immagine; con decine di migliaia di immagini senza alt, a pagine.
			$can = static fn( int|WP_Post $id ): bool => is_int( $id ) && self::supported( (string) get_post_mime_type( $id ) ) && current_user_can( 'edit_post', $id );
			return new WP_REST_Response( [ 'ids' => array_values( array_filter( (array) $query->posts, $can ) ) ] );
		}
		$rows = [];
		foreach ( (array) $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$can    = current_user_can( 'edit_post', $post->ID );
			$rows[] = [
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'file'      => wp_basename( (string) get_attached_file( $post->ID ) ),
				'thumb'     => (string) wp_get_attachment_image_url( $post->ID ),
				'alt'       => (string) get_post_meta( $post->ID, self::META, true ),
				'parent'    => $post->post_parent ? get_the_title( $post->post_parent ) : '',
				'date'      => get_the_date( '', $post ),
				'can'       => $can,
				'supported' => self::supported( $post->post_mime_type ),
				'edit'      => $can ? (string) get_edit_post_link( $post->ID, 'raw' ) : '',
			];
		}
		return new WP_REST_Response(
			[
				'rows'   => $rows,
				'total'  => $query->found_posts,
				// Per i filtri: tutte le immagini e quelle senza alt, senza la ricerca.
				'counts' => [
					'all'     => self::count( false ),
					'missing' => self::count( true ),
				],
			]
		);
	}

	// POST /alt/{id}: i testi per il pulsante e la modale, che si salvano come ogni modifica; con save (la massa) si
	// scrivono subito, solo dove mancano.
	public static function generate_route( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = (int) $request['id'];
		$text = self::generate( $id );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		if ( $request['save'] ) {
			self::save( $id, $text );
		}
		return new WP_REST_Response( $text );
	}

	// Dopo che WordPress ha creato le dimensioni di un'immagine caricata: un evento singolo di WP-Cron, così l'upload
	// non aspetta Google.
	public static function uploaded( mixed $metadata, int $id, string $context = 'create' ): mixed {
		if ( 'create' === $context && self::settings()['on_upload'] && Ai::is_ready() && wp_attachment_is_image( $id ) ) {
			wp_schedule_single_event( time(), self::CRON, [ $id ] );
		}
		return $metadata;
	}

	// Nessun registro degli errori: un'immagine rimasta senza alt si vede nella tabella.
	public static function cron( int $id ): void {
		$text = self::generate( $id );
		if ( ! is_wp_error( $text ) ) {
			self::save( $id, $text );
		}
	}

	/**
	 * "Genera con AI" nei dettagli di un'immagine nella finestra dei media. Nella schermata di modifica i campi dei
	 * plugin stanno in una tabella stretta: lì lo stampa edit_screen().
	 *
	 * @param array<string, array<string, mixed>> $fields
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields( array $fields, WP_Post $post ): array {
		$button = self::button( $post );
		if ( '' !== $button ) {
			$fields['barrio_alt'] = [
				'label'        => '',
				'input'        => 'html',
				'html'         => $button,
				'show_in_edit' => false,
			];
		}
		return $fields;
	}

	// Nella schermata di modifica di un'immagine, sotto la descrizione e largo come i campi di WordPress.
	public static function edit_screen( WP_Post $post ): void {
		if ( 'attachment' === $post->post_type ) {
			echo self::button( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escape in button().
		}
	}

	// Il pulsante, largo quanto i campi sopra, a chi può generare per questa immagine; '' agli altri. Lo script
	// riempie i campi dell'alt e del titolo.
	private static function button( WP_Post $post ): string {
		if ( ! wp_attachment_is_image( $post ) || ! self::supported( $post->post_mime_type ) || ! self::can() || ! current_user_can( 'edit_post', $post->ID ) ) {
			return '';
		}
		// La schermata di modifica non carica la finestra dei media: lo script si aggiunge qui.
		self::enqueue();
		$ready = Ai::is_ready();
		return sprintf(
			'<div class="barrio-alt-field"><button type="button" class="button barrio-alt-generate" style="width: 100%%" data-id="%d"%s>%s</button><p class="help description barrio-alt-status" aria-live="polite">%s</p></div>',
			$post->ID,
			disabled( $ready, false, false ),
			esc_html__( 'Generate alt text with AI', 'barrio-suite' ),
			$ready ? '' : esc_html__( 'To use it, set up Google AI in Barrio → Settings.', 'barrio-suite' )
		);
	}

	public static function enqueue(): void {
		if ( wp_script_is( 'barrio-alt' ) || ! self::can() ) {
			return;
		}
		wp_enqueue_script( 'barrio-alt', plugins_url( 'assets/editor/alt.js', __DIR__ ), [ 'wp-api-fetch' ], BARRIO_SUITE_VERSION, true );
		wp_add_inline_script( 'barrio-alt', 'window.barrioAlt = ' . wp_json_encode( [ 'generating' => __( 'Generating…', 'barrio-suite' ) ] ) . ';', 'before' );
	}

	/**
	 * Alt e, se scelto nelle impostazioni, titolo di un'immagine, nella lingua principale del sito. A Google vanno
	 * l'immagine (image()), il nome del file e il titolo del contenuto a cui è allegata.
	 *
	 * @return Text|WP_Error
	 */
	public static function generate( int $id ): array|WP_Error {
		$post = get_post( $id );
		if ( ! $post || ! wp_attachment_is_image( $post ) ) {
			return new WP_Error( 'barrio_alt_not_image', __( 'This is not an image.', 'barrio-suite' ), [ 'status' => 404 ] );
		}
		$image = self::image( $post );
		if ( is_wp_error( $image ) ) {
			return $image;
		}
		$title      = self::settings()['title'];
		$properties = [ 'alt' => [ 'type' => 'STRING' ] ] + ( $title ? [ 'title' => [ 'type' => 'STRING' ] ] : [] );
		$json       = Ai::generate(
			'Write the alternative text of this image, in the language of the locale ' . I18n::locale( I18n::languages()[0] ) . ".\n"
			. '- alt: what the image shows, for people who cannot see it, in at most 125 characters. Do not start with words such as "image of" or "photo of", and do not list keywords.'
			. ( $title ? "\n- title: a short title for the image, at most 60 characters." : '' )
			. "\n\nFile name: " . wp_basename( (string) get_attached_file( $id ) )
			. ( $post->post_parent ? "\nIt is used in the content: " . wp_strip_all_tags( get_the_title( $post->post_parent ) ) : '' ),
			[
				'responseMimeType' => 'application/json',
				'responseSchema'   => [
					'type'       => 'OBJECT',
					'properties' => $properties,
					'required'   => array_keys( $properties ),
				],
			],
			[ [ 'inlineData' => $image ] ]
		);
		$json       = is_wp_error( $json ) ? $json : json_decode( $json, true );
		if ( ! is_array( $json ) ) {
			return $json instanceof WP_Error ? $json : new WP_Error( 'barrio_ai_json', __( 'The answer of Google is not valid. Try again, or choose another model.', 'barrio-suite' ), [ 'status' => 502 ] );
		}
		$text = static fn( string $key ): string => sanitize_text_field( is_string( $json[ $key ] ?? null ) ? $json[ $key ] : '' );
		return [
			'alt'   => $text( 'alt' ),
			'title' => $title ? $text( 'title' ) : '',
		];
	}

	/**
	 * Scrive solo dove manca: l'alt se è vuoto, il titolo se è ancora il nome del file. Un testo vuoto non si scrive.
	 *
	 * @param Text $text
	 */
	public static function save( int $id, array $text ): void {
		if ( '' !== $text['alt'] && '' === trim( (string) get_post_meta( $id, self::META, true ) ) ) {
			update_post_meta( $id, self::META, wp_slash( $text['alt'] ) );
		}
		if ( '' !== $text['title'] && self::default_title( $id ) ) {
			wp_update_post(
				wp_slash(
					[
						'ID'         => $id,
						'post_title' => $text['title'],
					]
				)
			);
		}
	}

	/** @return array<string, mixed> Le immagini nella libreria dei media, solo quelle senza alt con $missing. */
	private static function query( bool $missing ): array {
		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
		];
		if ( $missing ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- l'alt è un meta del core, sul suo indice meta_key.
			$args['meta_query'] = [
				'relation' => 'OR',
				[
					'key'     => self::META,
					'compare' => 'NOT EXISTS',
				],
				[
					'key'   => self::META,
					'value' => '',
				],
			];
		}
		return $args;
	}

	private static function count( bool $missing ): int {
		$query = new WP_Query(
			self::query( $missing ) + [
				'posts_per_page' => 1,
				'fields'         => 'ids',
			]
		);
		return $query->found_posts;
	}

	// I formati che Gemini legge, e quelli che l'editor di immagini del server converte. Non SVG: è un testo.
	private static function supported( string $mime ): bool {
		return in_array( $mime, self::MIME, true ) || ( str_starts_with( $mime, 'image/' ) && 'image/svg+xml' !== $mime && wp_image_editor_supports( [ 'mime_type' => $mime ] ) );
	}

	/**
	 * L'immagine per Google, come inlineData: la dimensione large, o il file caricato se è più piccolo, mai l'originale
	 * grande. In AVIF (il modulo Prestazioni), GIF o HEIC si converte in JPEG.
	 *
	 * @return array{mimeType: string, data: string}|WP_Error
	 */
	private static function image( WP_Post $post ): array|WP_Error {
		$file  = (string) get_attached_file( $post->ID );
		$large = image_get_intermediate_size( $post->ID, 'large' );
		if ( $large ) {
			$file = path_join( dirname( $file ), $large['file'] );
		}
		$mime = (string) wp_check_filetype( $file )['type'];
		$temp = '';
		if ( ! in_array( $mime, self::MIME, true ) ) {
			$editor = self::supported( $mime ) && is_file( $file ) ? wp_get_image_editor( $file ) : null;
			if ( null === $editor || is_wp_error( $editor ) ) {
				return self::unsupported();
			}
			// wp_tempnam() non c'è nella REST e in WP-Cron.
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$temp = wp_tempnam( 'barrio-alt' );
			// Prestazioni trasformerebbe il JPEG in WebP o AVIF: questa copia resta JPEG.
			$jpeg  = static fn( array $formats ): array => array_diff_key( $formats, [ 'image/jpeg' => true ] );
			add_filter( 'image_editor_output_format', $jpeg, PHP_INT_MAX );
			$saved = $editor->save( $temp, 'image/jpeg' );
			remove_filter( 'image_editor_output_format', $jpeg, PHP_INT_MAX );
			wp_delete_file( $temp );
			if ( is_wp_error( $saved ) ) {
				return self::unsupported();
			}
			[ $file, $temp, $mime ] = [ $saved['path'], $saved['path'], 'image/jpeg' ];
		}
		$data = is_file( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- un file dei media di questo sito.
		if ( '' !== $temp ) {
			wp_delete_file( $temp );
		}
		if ( false === $data ) {
			return new WP_Error( 'barrio_alt_file', __( 'The image file is missing.', 'barrio-suite' ), [ 'status' => 404 ] );
		}
		return [
			'mimeType' => $mime,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- il formato di inlineData di Gemini.
			'data'     => base64_encode( $data ),
		];
	}

	// Il titolo che WordPress dà a un'immagine caricata è il nome del file, senza il -2 di un nome già usato: quello si
	// sostituisce, uno scritto no.
	private static function default_title( int $id ): bool {
		$file = wp_get_original_image_path( $id );
		$name = $file ? pathinfo( $file, PATHINFO_FILENAME ) : '';
		return '' !== $name && in_array( sanitize_title( get_post_field( 'post_title', $id ) ), [ sanitize_title( $name ), sanitize_title( (string) preg_replace( '/-\d+$/', '', $name ) ) ], true );
	}

	private static function unsupported(): WP_Error {
		return new WP_Error( 'barrio_alt_format', __( 'This image format cannot be sent to Google.', 'barrio-suite' ), [ 'status' => 415 ] );
	}
}
