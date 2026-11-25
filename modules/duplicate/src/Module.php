<?php

namespace BarrioSuite\Duplicate;

use BarrioSuite\Admin;
use BarrioSuite\Roles;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_Term;

/**
 * Duplica contenuti e termini: tra le azioni di riga delle liste, tra le azioni di massa dei
 * contenuti e nel pannello dello stato dell'editor. La copia è una bozza di chi duplica, con i meta e i termini
 * dell'originale. Si accende e si spegne per tipo e per tassonomia in Barrio → Contenuti → Duplicazione: di default
 * c'è dove il tipo ha pagine nel sito; gli altri (pattern, menu, tipi interni di altri plugin) spesso tengono dati
 * altrove, e la copia sarebbe a metà.
 *
 * @phpstan-type Settings array{post_types: array<string, bool>, taxonomies: array<string, bool>}
 */
final class Module {

	// Chi può duplicare (Permessi), oltre ai permessi di WordPress sull'originale. Dalla migrazione 0001.
	public const CAP = 'barrio_duplicate';

	// Non in autoload: si legge solo nell'admin. Salva solo le scelte, { nome: acceso }: un tipo nuovo ha il suo default.
	private const OPTION = 'barrio_duplicate';

	private const DEFAULTS = [
		'post_types' => [],
		'taxonomies' => [],
	];

	// L'azione di admin-post.php e delle azioni di massa, e il nonce.
	private const ACTION = 'barrio_duplicate';

	// Dell'originale e non della copia: chi lo sta modificando, chi l'ha modificato, i vecchi indirizzi. Anche
	// _wp_trash_meta_*, del cestino.
	private const SKIP_META = [ '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug' ];

	// I numeri dell'avviso dopo le azioni di massa.
	private const NOTICE = [ 'barrio_duplicated', 'barrio_not_duplicated' ];

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_filter( 'barrio_admin_data', [ self::class, 'admin_data' ] );
		add_action( 'admin_init', [ self::class, 'admin_init' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_filter( 'post_row_actions', [ self::class, 'post_row_actions' ], 10, 2 );
		add_filter( 'page_row_actions', [ self::class, 'post_row_actions' ], 10, 2 );
		add_action( 'admin_notices', [ self::class, 'notice' ] );
		add_filter( 'removable_query_args', static fn( array $args ): array => [ ...$args, ...self::NOTICE ] );
		add_action( 'post_submitbox_misc_actions', [ self::class, 'classic_editor' ] );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'block_editor' ] );
	}

	public static function deactivate(): void {}

	// L'opzione la elimina il core (src/Uninstall.php).
	public static function uninstall(): void {
		Roles::remove( [ self::CAP ] );
	}

	public static function menu(): void {
		Admin::contents_tab();
	}

	/**
	 * In window.barrioAdmin.duplicate, per la scheda di Barrio → Contenuti e la card della Bacheca, con i tipi di
	 * window.barrioAdmin.contentTypes. Senza, il JavaScript sa che il modulo è spento.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function admin_data( array $data ): array {
		return $data + [ 'duplicate' => [ 'chosen' => self::json( self::settings() ) ] ];
	}

	// Dopo init, quando tipi e tassonomie ci sono. Anche in admin-ajax.php, che ridisegna una riga dopo la modifica rapida.
	public static function admin_init(): void {
		$available = Admin::content_types();
		foreach ( array_column( $available['post_types'], 'name' ) as $type ) {
			if ( self::enabled( 'post_types', $type ) ) {
				add_filter( "bulk_actions-edit-{$type}", [ self::class, 'bulk_actions' ] );
				add_filter( "handle_bulk_actions-edit-{$type}", [ self::class, 'handle_bulk' ], 10, 3 );
			}
		}
		foreach ( array_column( $available['taxonomies'], 'name' ) as $taxonomy ) {
			add_filter( "{$taxonomy}_row_actions", [ self::class, 'term_row_actions' ], 10, 2 );
		}
	}

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		$names = [
			'type'                 => 'object',
			'patternProperties'    => [ '^[a-z0-9_-]{1,32}$' => [ 'type' => 'boolean' ] ],
			'additionalProperties' => false,
		];
		register_rest_route(
			'barrio/v1',
			'/duplicate',
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
						'post_types' => $names,
						'taxonomies' => $names,
						'roles'      => Roles::schema( [ self::CAP ] ),
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
		return new WP_REST_Response( self::json( self::settings() ) + Roles::get( [ self::CAP ] ) );
	}

	// Un elenco assente resta com'è. Un tipo che non c'è più tiene la sua scelta, se torna.
	public static function put( WP_REST_Request $request ): WP_REST_Response {
		update_option( self::OPTION, array_intersect_key( $request->get_params(), self::DEFAULTS ) + self::settings(), false );
		if ( is_array( $request['roles'] ) ) {
			Roles::save( [ self::CAP ], $request['roles'] );
		}
		return self::get();
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function post_row_actions( array $actions, WP_Post $post ): array {
		if ( 'trash' !== $post->post_status && self::can_post( $post ) ) {
			$actions[ self::ACTION ] = self::link( 'post', $post->ID, _draft_or_post_title( $post ) );
		}
		return $actions;
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function term_row_actions( array $actions, WP_Term $term ): array {
		if ( self::can_term( $term ) ) {
			$actions[ self::ACTION ] = self::link( 'term', $term->term_id, $term->name );
		}
		return $actions;
	}

	/**
	 * Non nel cestino, dove le azioni sono solo ripristina ed elimina.
	 *
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public static function bulk_actions( array $actions ): array {
		if ( 'trash' !== get_query_var( 'post_status' ) && current_user_can( self::CAP ) ) {
			$actions[ self::ACTION ] = __( 'Duplicate', 'barrio-suite' );
		}
		return $actions;
	}

	/**
	 * Il nonce lo controlla edit.php. Si torna alla lista con l'avviso di notice().
	 *
	 * @param int[] $post_ids
	 */
	public static function handle_bulk( string $sendback, string $action, array $post_ids ): string {
		if ( self::ACTION !== $action ) {
			return $sendback;
		}
		$done = count( array_filter( $post_ids, static fn( int $post_id ): bool => ! is_wp_error( self::post( $post_id ) ) ) );
		return add_query_arg( array_combine( self::NOTICE, [ $done, count( $post_ids ) - $done ] ), $sendback );
	}

	public static function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- solo i numeri da mostrare.
		if ( ! isset( $_GET['barrio_duplicated'] ) ) {
			return;
		}
		$done   = absint( $_GET['barrio_duplicated'] );
		$failed = absint( $_GET['barrio_not_duplicated'] ?? 0 );
		// phpcs:enable
		/* translators: %s: number of copies. */
		$message = sprintf( _n( '%s copy created as a draft.', '%s copies created as drafts.', $done, 'barrio-suite' ), number_format_i18n( $done ) );
		if ( $failed ) {
			/* translators: %s: number of contents. */
			$message .= ' ' . sprintf( _n( '%s content could not be duplicated.', '%s contents could not be duplicated.', $failed, 'barrio-suite' ), number_format_i18n( $failed ) );
		}
		wp_admin_notice(
			esc_html( $message ),
			[
				'type'        => $failed ? 'warning' : 'success',
				'dismissible' => true,
			]
		);
	}

	// Nel pannello Pubblica dell'editor classico, sotto lo stato. Un contenuto mai salvato non ha niente da copiare.
	public static function classic_editor( WP_Post $post ): void {
		if ( 'auto-draft' !== $post->post_status && self::can_post( $post ) ) {
			printf( '<div class="misc-pub-section"><a href="%s">%s</a></div>', esc_url( self::url( 'post', $post->ID ) ), esc_html__( 'Duplicate', 'barrio-suite' ) );
		}
	}

	// enqueue_block_editor_assets: anche nell'editor del sito e dei widget, dove non c'è un contenuto.
	public static function block_editor(): void {
		$post = get_post();
		if ( 'post' !== get_current_screen()?->base || ! $post || ! self::can_post( $post ) ) {
			return;
		}
		wp_enqueue_script( 'barrio-duplicate', plugins_url( 'assets/editor/duplicate.js', __DIR__ ), [ 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-plugins' ], BARRIO_SUITE_VERSION, true );
		$data = [
			'url'   => self::url( 'post', $post->ID ),
			'label' => __( 'Duplicate', 'barrio-suite' ),
		];
		wp_add_inline_script( 'barrio-duplicate', 'window.barrioDuplicate = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	// admin-post.php?action=barrio_duplicate, dal link di una riga o dell'editor: la copia si apre nel suo editor.
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- il nonce, che contiene l'ID, si controlla subito sotto.
		$kind = isset( $_GET['term'] ) ? 'term' : 'post';
		$id   = absint( $_GET[ $kind ] ?? 0 );
		// phpcs:enable
		check_admin_referer( self::ACTION . "-{$kind}-{$id}" );
		$copy = 'term' === $kind ? self::term( $id ) : self::post( $id );
		if ( is_wp_error( $copy ) ) {
			wp_die(
				esc_html( $copy->get_error_message() ),
				'',
				[
					'response'  => (int) ( $copy->get_error_data()['status'] ?? 500 ),
					'back_link' => true,
				]
			);
		}
		wp_safe_redirect( ( 'term' === $kind ? get_edit_term_link( $copy ) : get_edit_post_link( $copy, 'raw' ) ) ?? admin_url() );
		exit;
	}

	/**
	 * La copia di un contenuto: una bozza di chi duplica, datata ora, con il contenuto, i meta e i termini
	 * dell'originale. wp_insert_post() e add_post_meta() tolgono le barre rovesciate: senza wp_slash() si
	 * romperebbero gli attributi dei blocchi e i meta JSON. Niente transazione, perché le tabelle del core possono
	 * non essere InnoDB: se un passo fallisce, la copia a metà si elimina.
	 *
	 * @return int|WP_Error L'ID della copia.
	 */
	public static function post( int $post_id ): int|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post || ! self::can_post( $post ) ) {
			return self::forbidden();
		}
		$copy = wp_insert_post(
			wp_slash(
				[
					'post_type'      => $post->post_type,
					'post_status'    => 'draft',
					'post_author'    => get_current_user_id(),
					/* translators: %s: title of the original content. */
					'post_title'     => trim( sprintf( __( '%s (copy)', 'barrio-suite' ), $post->post_title ) ),
					'post_content'   => $post->post_content,
					'post_excerpt'   => $post->post_excerpt,
					'post_parent'    => $post->post_parent,
					'menu_order'     => $post->menu_order,
					'comment_status' => $post->comment_status,
					'ping_status'    => $post->ping_status,
					'post_password'  => $post->post_password,
				]
			),
			true
		);
		if ( is_wp_error( $copy ) ) {
			return $copy;
		}
		// Template, immagine in evidenza, SEO e campi ACF compresi, così come sono. Un plugin può averne già scritto
		// uno su save_post: resta solo il valore dell'originale.
		foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
			if ( self::skip_meta( (string) $key ) ) {
				continue;
			}
			delete_post_meta( $copy, wp_slash( (string) $key ) );
			foreach ( (array) $values as $value ) {
				if ( ! add_post_meta( $copy, wp_slash( (string) $key ), self::slash( maybe_unserialize( $value ) ) ) ) {
					wp_delete_post( $copy, true );
					return self::failed();
				}
			}
		}
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy, [ 'fields' => 'ids' ] );
			if ( is_wp_error( $terms ) || is_wp_error( wp_set_object_terms( $copy, $terms, $taxonomy ) ) ) {
				wp_delete_post( $copy, true );
				return self::failed();
			}
		}
		return $copy;
	}

	/**
	 * La copia di un termine, con descrizione, genitore e meta. Un nome che c'è già allo stesso livello è un errore
	 * di WordPress: la seconda copia è "(copia 2)", poi "(copia 3)"…
	 *
	 * @return int|WP_Error L'ID della copia.
	 */
	public static function term( int $term_id ): int|WP_Error {
		$term = get_term( $term_id );
		if ( ! $term instanceof WP_Term || ! self::can_term( $term ) ) {
			return self::forbidden();
		}
		/** @var non-empty-string $taxonomy Registrata: lo controlla can_term(). */
		$taxonomy = $term->taxonomy;
		$args     = [
			'description' => wp_slash( $term->description ),
			'parent'      => absint( $term->parent ),
		];
		$n        = 0;
		do {
			$name = 1 === ++$n
				/* translators: %s: name of the original term. */
				? sprintf( __( '%s (copy)', 'barrio-suite' ), $term->name )
				/* translators: 1: name of the original term, 2: number of the copy, from 2. */
				: sprintf( __( '%1$s (copy %2$d)', 'barrio-suite' ), $term->name, $n );
			$copy = wp_insert_term( wp_slash( $name ), $taxonomy, $args );
		} while ( is_wp_error( $copy ) && 'term_exists' === $copy->get_error_code() );
		if ( is_wp_error( $copy ) ) {
			return $copy;
		}
		foreach ( (array) get_term_meta( $term->term_id ) as $key => $values ) {
			// L'ordine manuale (modulo Ordinamento) no: un termine senza va in fondo.
			if ( '_barrio_order' === $key ) {
				continue;
			}
			delete_term_meta( $copy['term_id'], wp_slash( (string) $key ) );
			foreach ( (array) $values as $value ) {
				if ( ! add_term_meta( $copy['term_id'], wp_slash( (string) $key ), self::slash( maybe_unserialize( $value ) ) ) ) {
					wp_delete_term( $copy['term_id'], $taxonomy );
					return self::failed();
				}
			}
		}
		return $copy['term_id'];
	}

	/** @param 'post_types'|'taxonomies' $group */
	private static function enabled( string $group, string $name ): bool {
		$default = array_column( Admin::content_types()[ $group ], 'viewable', 'name' )[ $name ] ?? null;
		return null !== $default && ( self::settings()[ $group ][ $name ] ?? $default );
	}

	/**
	 * Gli elenchi come oggetti JSON anche vuoti.
	 *
	 * @param Settings $settings
	 * @return array{post_types: object, taxonomies: object}
	 */
	private static function json( array $settings ): array {
		return array_map( static fn( array $chosen ): object => (object) $chosen, $settings );
	}

	// Chi duplica, oltre a CAP, deve poter modificare l'originale (un collaboratore non legge così il privato di un
	// altro) e creare contenuti del tipo.
	private static function can_post( WP_Post $post ): bool {
		$type = get_post_type_object( $post->post_type );
		return $type && current_user_can( self::CAP ) && self::enabled( 'post_types', $post->post_type ) && current_user_can( 'edit_post', $post->ID ) && current_user_can( $type->cap->create_posts );
	}

	// edit_term vale edit_terms della tassonomia, che serve anche per aggiungere un termine.
	private static function can_term( WP_Term $term ): bool {
		return current_user_can( self::CAP ) && self::enabled( 'taxonomies', $term->taxonomy ) && current_user_can( 'edit_term', $term->term_id );
	}

	private static function skip_meta( string $key ): bool {
		return in_array( $key, self::SKIP_META, true ) || str_starts_with( $key, '_wp_trash_meta_' );
	}

	// Come wp_slash(), anche dentro gli oggetti, che wp_unslash() di add_metadata() attraversa.
	private static function slash( mixed $value ): mixed {
		return map_deep( $value, static fn( mixed $item ): mixed => is_string( $item ) ? wp_slash( $item ) : $item );
	}

	// Un link con il nonce, per le righe delle liste e l'editor.
	private static function url( string $kind, int $id ): string {
		return add_query_arg(
			[
				'action'   => self::ACTION,
				$kind      => $id,
				'_wpnonce' => wp_create_nonce( self::ACTION . "-{$kind}-{$id}" ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	private static function link( string $kind, int $id, string $name ): string {
		return sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( self::url( $kind, $id ) ),
			/* translators: %s: title of the content or name of the term. */
			esc_attr( sprintf( __( 'Duplicate &#8220;%s&#8221;', 'barrio-suite' ), $name ) ),
			esc_html__( 'Duplicate', 'barrio-suite' )
		);
	}

	private static function forbidden(): WP_Error {
		return new WP_Error( 'barrio_duplicate_forbidden', __( 'You are not allowed to duplicate this item.', 'barrio-suite' ), [ 'status' => 403 ] );
	}

	private static function failed(): WP_Error {
		return new WP_Error( 'barrio_duplicate_failed', __( 'The copy could not be completed and has been deleted. Please try again.', 'barrio-suite' ), [ 'status' => 500 ] );
	}
}
