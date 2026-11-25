<?php

namespace BarrioSuite\Ordering;

use BarrioSuite\Admin;
use BarrioSuite\Roles;
use RuntimeException;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * L'ordine manuale di contenuti e termini: si accende per tipo e per tassonomia in Barrio → Contenuti
 * → Ordinamento, si riordina trascinando nella modale di Riordina, accanto ad Aggiungi nella lista di ogni elenco, e
 * vale in ogni query che non chiede un altro ordine. Spegnerlo toglie l'ordine dalle query e lascia menu_order e i meta dei termini.
 *
 * @phpstan-import-type Item from OrderRepository
 * @phpstan-type Settings array{post_types: list<string>, taxonomies: list<string>}
 */
final class Module {

	// Chi può riordinare (Permessi), oltre ai permessi di WordPress sull'elenco. Dalla migrazione 0001.
	public const CAP = 'barrio_reorder';

	// In autoload: lo leggono le query del sito. Salva i tipi e le tassonomie in ordine manuale.
	private const OPTION = 'barrio_ordering';

	private const DEFAULTS = [
		'post_types' => [],
		'taxonomies' => [],
	];

	// Gli elenchi nella REST e nella modale: post/page, term/category.
	private const KINDS = [
		'post' => 'post_types',
		'term' => 'taxonomies',
	];

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_filter( 'barrio_admin_data', [ self::class, 'admin_data' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'list_screen' ] );
		add_action( 'pre_get_posts', [ self::class, 'posts' ] );
		add_filter( 'terms_clauses', [ self::class, 'terms' ], 10, 3 );
	}

	public static function deactivate(): void {}

	// L'opzione la elimina il core (src/Uninstall.php), che non vede i meta; menu_order è del core e resta.
	public static function uninstall(): void {
		Roles::remove( [ self::CAP ] );
		delete_metadata( 'term', 0, OrderRepository::TERM_META, '', true );
	}

	public static function menu(): void {
		Admin::contents_tab();
	}

	/**
	 * In window.barrioAdmin.ordering, per la scheda di Barrio → Contenuti e la card della Bacheca, con i tipi di
	 * window.barrioAdmin.contentTypes. Senza, il JavaScript sa che il modulo è spento.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function admin_data( array $data ): array {
		return $data + [ 'ordering' => [ 'manual' => self::settings() ] ];
	}

	// Riordina nella lista di un elenco in ordine manuale (edit.php, edit-tags.php), a chi può riordinarlo: lo script
	// aggiunge il pulsante accanto ad Aggiungi e apre la modale con l'elenco intero.
	public static function list_screen(): void {
		$screen = get_current_screen();
		$kind   = [
			'edit'      => 'post',
			'edit-tags' => 'term',
		][ $screen->base ?? '' ] ?? '';
		$name   = (string) ( 'term' === $kind ? $screen?->taxonomy : $screen?->post_type );
		$object = 'term' === $kind ? get_taxonomy( $name ) : get_post_type_object( $name );
		$dir    = dirname( __DIR__, 3 );
		if ( ! $object || ! in_array( $name, self::settings()[ self::KINDS[ $kind ] ?? 'post_types' ], true ) || ! self::can( $kind, $name ) || ! is_file( "{$dir}/build/barrio-reorder.asset.php" ) ) {
			return;
		}
		/** @var array{dependencies: list<non-empty-string>, version: string} $asset */
		$asset = require "{$dir}/build/barrio-reorder.asset.php";
		wp_enqueue_script( 'barrio-reorder', plugins_url( 'build/barrio-reorder.js', "{$dir}/barrio-suite.php" ), $asset['dependencies'], $asset['version'], true );
		$data = [
			'list'  => "{$kind}/{$name}",
			/* translators: %s: name of the content type or taxonomy, such as Pages. */
			'title' => sprintf( __( 'Reorder %s', 'barrio-suite' ), $object->labels->name ),
		];
		wp_add_inline_script( 'barrio-reorder', 'window.barrioReorder = ' . wp_json_encode( $data ) . ';', 'before' );
		wp_set_script_translations( 'barrio-reorder', 'barrio-suite', "{$dir}/languages" );
		Admin::style();
	}

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		$names = [
			'type'        => 'array',
			'items'       => [
				'type'    => 'string',
				'pattern' => '^[a-z0-9_-]{1,32}$',
			],
			'uniqueItems' => true,
		];
		register_rest_route(
			'barrio/v1',
			'/ordering/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get_settings' ],
					'permission_callback' => $admin,
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'put_settings' ],
					'permission_callback' => $admin,
					'args'                => [
						'post_types' => $names,
						'taxonomies' => $names,
						'roles'      => Roles::schema( [ self::CAP ] ),
					],
				],
			]
		);
		$can = static fn( WP_REST_Request $request ): bool => self::can( (string) $request['kind'], (string) $request['name'] );
		register_rest_route(
			'barrio/v1',
			'/ordering/(?P<kind>post|term)/(?P<name>[a-z0-9_-]{1,32})',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get' ],
					'permission_callback' => $can,
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'put' ],
					'permission_callback' => $can,
					'args'                => [
						'ids' => [
							'type'        => 'array',
							'items'       => [
								'type'    => 'integer',
								'minimum' => 1,
							],
							'uniqueItems' => true,
							'required'    => true,
						],
					],
				],
			]
		);
	}

	/** @return Settings */
	public static function settings(): array {
		/** @var Settings */
		return array_intersect_key( (array) get_option( self::OPTION, [] ), self::DEFAULTS ) + self::DEFAULTS;
	}

	public static function get_settings(): WP_REST_Response {
		return new WP_REST_Response( self::settings() + Roles::get( [ self::CAP ] ) );
	}

	// Un elenco assente resta com'è. Un tipo che non c'è più resta in ordine manuale, se torna.
	public static function put_settings( WP_REST_Request $request ): WP_REST_Response {
		$before = self::settings();
		$after  = array_intersect_key( $request->get_params(), self::DEFAULTS ) + $before;
		update_option( self::OPTION, $after, true );
		if ( is_array( $request['roles'] ) ) {
			Roles::save( [ self::CAP ], $request['roles'] );
		}
		foreach ( self::KINDS as $kind => $group ) {
			foreach ( [ ...array_diff( $after[ $group ], $before[ $group ] ), ...array_diff( $before[ $group ], $after[ $group ] ) ] as $name ) {
				do_action( 'barrio_ordering_changed', $kind, $name );
			}
		}
		return self::get_settings();
	}

	// Tutto l'elenco in una richiesta: 2.000 pagine sono 5 query e 29 ms. Il limite è il trascinamento (reorder.js).
	public static function get( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( self::items( (string) $request['kind'], (string) $request['name'] ) );
	}

	/**
	 * Gli ID nell'ordine nuovo, genitori prima dei figli. Il genitore resta quello del database: si cambia dal
	 * contenuto. Solo elementi dell'elenco, e per i contenuti solo quelli che chi riordina può modificare.
	 */
	public static function put( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$kind = (string) $request['kind'];
		$name = (string) $request['name'];
		/** @var list<int> $ids */
		$ids = $request['ids'];
		if ( array_diff( $ids, array_column( self::items( $kind, $name ), 'id' ) ) ) {
			return new WP_Error( 'barrio_ordering_unknown', __( 'Some items are no longer in this list. Reload the page and try again.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		if ( 'post' === $kind ) {
			_prime_post_caches( $ids, false, false );
			foreach ( $ids as $id ) {
				if ( ! current_user_can( 'edit_post', $id ) ) {
					return new WP_Error( 'barrio_ordering_forbidden', __( 'You are not allowed to move some of these contents.', 'barrio-suite' ), [ 'status' => 403 ] );
				}
			}
		}
		try {
			'post' === $kind ? OrderRepository::save_posts( $name, $ids ) : OrderRepository::save_terms( $name, $ids );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'barrio_ordering_failed', $e->getMessage(), [ 'status' => 500 ] );
		}
		do_action( 'barrio_ordering_changed', $kind, $name );
		return self::get( $request );
	}

	/**
	 * L'ordine manuale nelle query di contenuti in ordine manuale che non chiedono un ordine, o chiedono quello
	 * predefinito: la data decrescente, che mandano la REST e il blocco Query Loop. Nella ricerca vale la pertinenza, nel
	 * feed la data (chi lo legge prende i primi come i più nuovi); nelle liste dell'admin la data viene da un clic sulla
	 * colonna, e resta.
	 */
	public static function posts( WP_Query $query ): void {
		$manual = self::settings()['post_types'];
		if ( ! $manual || $query->is_search() || $query->is_feed() ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		$default = 'ASC' !== strtoupper( (string) $query->get( 'order' ) ) && (
			! $orderby || ( in_array( $orderby, [ 'date', 'post_date' ], true ) && ! ( is_admin() && $query->is_main_query() ) )
		);
		if ( $default && ! array_diff( self::types( $query ), $manual ) ) {
			$query->set(
				'orderby',
				[
					'menu_order' => 'ASC',
					'date'       => 'DESC',
				]
			);
		}
	}

	/**
	 * L'ordine manuale dei termini quando la query chiede il nome crescente, il predefinito, e tutte le sue tassonomie
	 * sono in ordine manuale. Nell'admin il nome può venire da un clic sulla colonna, e resta.
	 *
	 * @param array<string, string> $clauses
	 * @param string[]              $taxonomies
	 * @param array<string, mixed>  $args
	 * @return array<string, string>
	 */
	public static function terms( array $clauses, array $taxonomies, array $args ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se l'ordine è stato scelto.
		$chosen = is_admin() && isset( $_GET['orderby'] );
		// orderby vuoto: un conteggio.
		if ( ! $taxonomies || $chosen || '' === $clauses['orderby'] || 'name' !== $args['orderby'] || 'ASC' !== strtoupper( (string) $args['order'] ) || array_diff( $taxonomies, self::settings()['taxonomies'] ) ) {
			return $clauses;
		}
		return OrderRepository::term_clauses( $clauses );
	}

	/**
	 * I tipi della query. Senza, come WP_Query::get_posts(): quelli della tassonomia nel suo archivio, altrimenti gli
	 * articoli (anche negli archivi di categorie e tag).
	 *
	 * @return list<string>
	 */
	private static function types( WP_Query $query ): array {
		$types = array_values( array_filter( (array) $query->get( 'post_type' ) ) );
		if ( $types || ! $query->is_tax() ) {
			return $types ?: [ 'post' ];
		}
		$taxonomies = array_keys( $query->tax_query->queried_terms ?? [] );
		$types      = array_filter( get_post_types( [ 'exclude_from_search' => false ] ), static fn( string $type ): bool => (bool) array_intersect( $taxonomies, get_object_taxonomies( $type ) ) );
		return array_values( $types ) ?: [ 'any' ];
	}

	/**
	 * L'elenco da riordinare, nell'ordine di adesso: per i contenuti quelli di "Tutti" nella lista di WordPress. Il
	 * genitore conta solo nei tipi gerarchici, e uno che non è nell'elenco (nel cestino) vale la radice. Le query del
	 * Repository non passano dai filtri di WordPress: un plugin multilingua toglie qui le voci delle altre lingue
	 * (barrio_ordering_items), e ogni lingua ha il suo ordine.
	 *
	 * @return list<Item>
	 */
	private static function items( string $kind, string $name ): array {
		$post = 'post' === $kind;
		$rows = $post ? OrderRepository::posts( $name, array_values( get_post_stati( [ 'show_in_admin_all_list' => true ] ) ) ) : OrderRepository::terms( $name );
		/** @var list<Item> $rows */
		$rows  = array_values( (array) apply_filters( 'barrio_ordering_items', $rows, $kind, $name ) );
		$tree  = $post ? is_post_type_hierarchical( $name ) : is_taxonomy_hierarchical( $name );
		$found = array_flip( array_column( $rows, 'id' ) );
		return array_map(
			static fn( array $row ): array => [
				'id'     => $row['id'],
				'title'  => html_entity_decode( $row['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				// Il nome dello stato solo per chi non è pubblicato: Bozza, Privato…
				'status' => null === $row['status'] || 'publish' === $row['status'] ? null : (string) ( get_post_status_object( $row['status'] )->label ?? $row['status'] ),
				'parent' => $tree && isset( $found[ $row['parent'] ] ) ? $row['parent'] : 0,
			],
			$rows
		);
	}

	// Chi riordina, oltre a CAP, deve poter modificare i contenuti degli altri (un autore no) o i termini della
	// tassonomia.
	private static function can( string $kind, string $name ): bool {
		$capability = self::capability( $kind, $name );
		return $capability && current_user_can( $capability ) && current_user_can( self::CAP );
	}

	private static function capability( string $kind, string $name ): ?string {
		if ( ! isset( self::KINDS[ $kind ] ) || ! in_array( $name, array_column( Admin::content_types()[ self::KINDS[ $kind ] ], 'name' ), true ) ) {
			return null;
		}
		$capability = 'post' === $kind ? get_post_type_object( $name )?->cap->edit_others_posts : ( get_taxonomy( $name ) ?: null )?->cap->edit_terms;
		return is_string( $capability ) ? $capability : null;
	}
}
