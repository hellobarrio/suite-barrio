<?php

namespace BarrioSuite\Seo;

use BarrioSuite\I18n;
use BarrioSuite\Seo\Api\RestContents;
use BarrioSuite\Seo\Api\RestRedirects;
use BarrioSuite\Seo\Api\RestSettings;
use WP_REST_Posts_Controller;
use WP_REST_Request;
use WP_REST_Terms_Controller;

final class Module {

	// Le capability del modulo, che si assegnano ai ruoli nei Permessi.
	public const CAPS = [ 'barrio_manage_seo', 'barrio_edit_seo' ];

	// I meta SEO di contenuti e termini, con il prefisso _barrio_seo_. Un meta assente vuol dire "eredita".
	public const META = [ 'title', 'description', 'image', 'keywords', 'index', 'follow', 'collection' ];

	public static function boot(): void {
		// Dopo i post type e le tassonomie dei temi e degli altri plugin, che si registrano su init.
		add_action( 'init', [ self::class, 'register_meta' ], 20 );
		add_action( 'rest_api_init', [ RestSettings::class, 'register' ] );
		add_action( 'rest_api_init', [ RestContents::class, 'register' ] );
		add_action( 'rest_api_init', [ RestRedirects::class, 'register' ] );
		add_action( 'admin_menu', [ Admin::class, 'menu' ] );
		add_filter( 'barrio_admin_data', [ Admin::class, 'data' ] );
		add_filter( 'barrio_launch_steps', [ self::class, 'launch_steps' ] );
		add_filter( 'rest_request_before_callbacks', [ self::class, 'rest_meta' ], 10, 3 );

		add_filter( 'pre_get_document_title', [ Head::class, 'title' ] );
		add_filter( 'wp_robots', [ Head::class, 'robots' ] );
		add_action( 'wp_head', [ Head::class, 'print' ], 1 );

		add_filter( 'wp_sitemaps_posts_query_args', [ Sitemaps::class, 'posts' ], 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', [ Sitemaps::class, 'terms' ], 10, 2 );
		add_filter( 'wp_sitemaps_add_provider', [ Sitemaps::class, 'provider' ], 10, 2 );

		add_action( 'template_redirect', [ Redirects::class, 'run' ], 0 );
		add_action( 'pre_post_update', [ Redirects::class, 'before_post' ] );
		add_action( 'post_updated', [ Redirects::class, 'after_post' ] );
		add_action( 'edit_terms', [ Redirects::class, 'before_term' ], 10, 2 );
		add_action( 'edited_term', [ Redirects::class, 'after_term' ], 10, 3 );

		foreach ( [ 'post', 'term' ] as $type ) {
			foreach ( [ 'added', 'updated', 'deleted' ] as $change ) {
				add_action(
					"{$change}_{$type}_meta",
					static function ( mixed $ids, int $object_id, string $meta_key ) use ( $type ): void {
						if ( str_starts_with( $meta_key, '_barrio_seo_' ) ) {
							self::changed( $type, $object_id );
						}
					},
					10,
					3
				);
			}
		}

		add_action( 'enqueue_block_editor_assets', [ Editor::class, 'block_editor' ] );
		add_action( 'add_meta_boxes', [ Editor::class, 'meta_box' ], 10, 2 );
		add_action( 'save_post', [ Editor::class, 'save_post' ], 10, 2 );
		add_action( 'load-term.php', [ Editor::class, 'term_screen' ] );
		add_action( 'edited_term', [ Editor::class, 'save_term' ], 10, 3 );

		add_action( 'parse_request', [ Llms::class, 'serve' ] );
		// I menu sono termini e le loro voci sono post.
		foreach ( [ 'clean_post_cache', 'clean_term_cache', 'add_option_barrio_seo', 'update_option_barrio_seo', 'update_option_blogname' ] as $hook ) {
			add_action( $hook, [ Llms::class, 'flush' ] );
		}

		// Le estensioni del modulo si collegano qui, come a barrio_forms_loaded.
		add_action( 'init', static fn() => do_action( 'barrio_seo_loaded' ), 5 );
	}

	public static function deactivate(): void {}

	/**
	 * Messa online (src/Launch.php): fatto quando le pagine senza testi e immagini loro hanno quelli
	 * predefiniti, e lo schema ha il nome e il logo dell'organizzazione.
	 *
	 * @param array<string, bool> $steps
	 * @return array<string, bool>
	 */
	public static function launch_steps( array $steps ): array {
		$settings = Settings::get();
		return $steps + [
			'seo' => '' !== trim( Settings::text( 'default_description', I18n::languages()[0] ) ) && $settings['default_image'] > 0
				&& '' !== trim( $settings['organization']['name'] ) && $settings['organization']['logo'] > 0,
		];
	}

	// Disinstallando, se il sito lo ha scelto: tabelle e opzioni le elimina il core (src/Uninstall.php).
	public static function uninstall(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::CAPS as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		foreach ( self::META as $key ) {
			delete_metadata( 'post', 0, "_barrio_seo_{$key}", '', true );
			delete_metadata( 'term', 0, "_barrio_seo_{$key}", '', true );
		}
	}

	/**
	 * Su tutti i tipi e le tassonomie pubblici, allegati esclusi, e nella REST del core: la stessa
	 * validazione per l'editor, la tabella dei contenuti e le estensioni. Scrivere richiede
	 * barrio_edit_seo oltre al permesso di modificare il contenuto o il termine, che controlla WordPress.
	 */
	public static function register_meta(): void {
		$meta = self::meta_args();
		foreach ( get_post_types( [ 'public' => true ] ) as $post_type ) {
			if ( 'attachment' !== $post_type ) {
				// Senza custom-fields la REST del core non scrive i meta, e l'editor a blocchi non li salverebbe.
				add_post_type_support( $post_type, 'custom-fields' );
				foreach ( $meta as $key => $args ) {
					register_post_meta( $post_type, "_barrio_seo_{$key}", $args );
				}
			}
		}
		unset( $meta['collection'] );
		foreach ( get_taxonomies( [ 'public' => true ] ) as $taxonomy ) {
			foreach ( $meta as $key => $args ) {
				register_term_meta( $taxonomy, "_barrio_seo_{$key}", $args );
			}
		}
	}

	/**
	 * barrio_seo_changed quando cambiano i meta SEO di un contenuto o di un termine, dal pannello, dalla REST
	 * o da un altro plugin: una volta per richiesta, anche se ne cambiano più d'uno.
	 *
	 * @param 'post'|'term' $type
	 */
	private static function changed( string $type, int $object_id ): void {
		// ponytail: una volta per processo; un comando WP-CLI che cambia due volte lo stesso contenuto avvisa una volta sola.
		static $done = [];
		if ( ! isset( $done[ "{$type}-{$object_id}" ] ) ) {
			$done[ "{$type}-{$object_id}" ] = true;
			do_action( 'barrio_seo_changed', $type, $object_id );
		}
	}

	/**
	 * L'editor a blocchi rimanda tutti i meta quando ne cambia uno (le note a piè di pagina, un altro plugin),
	 * anche quelli assenti. Per il core un null cancella: chiede barrio_edit_seo, che un autore non ha, e
	 * rifiuta un meta assente il cui valore vuoto non rispetta lo schema (index, follow…). Un meta assente
	 * che resta assente non è una modifica, e il contenuto si deve salvare.
	 *
	 * @param array<string, mixed> $handler
	 */
	public static function rest_meta( mixed $response, array $handler, WP_REST_Request $request ): mixed {
		$controller = is_array( $handler['callback'] ?? null ) ? $handler['callback'][0] : null;
		$type       = match ( true ) {
			$controller instanceof WP_REST_Posts_Controller => 'post',
			$controller instanceof WP_REST_Terms_Controller => 'term',
			default                                         => '',
		};
		if ( '' !== $type && is_array( $request['meta'] ) ) {
			$request->set_param( 'meta', self::changes( $type, (int) $request['id'], $request['meta'] ) );
		}
		return $response;
	}

	/**
	 * I meta senza quelli SEO assenti che restano assenti: null, '' o [].
	 *
	 * @param 'post'|'term'        $type
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	public static function changes( string $type, int $object_id, array $meta ): array {
		return array_filter(
			$meta,
			static fn( mixed $value, string $key ): bool => ! str_starts_with( $key, '_barrio_seo_' )
				|| ! in_array( $value, [ null, '', [] ], true )
				|| metadata_exists( $type, $object_id, $key ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/** @return array<string, array<string, mixed>> */
	private static function meta_args(): array {
		$text = [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		];
		$enum = static fn( string ...$values ): array => [
			'type'         => 'string',
			'show_in_rest' => [ 'schema' => [ 'enum' => $values ] ],
		];
		$meta = [
			'title'       => $text,
			'description' => $text,
			// Un allegato; assente = l'immagine in evidenza, poi quella predefinita.
			'image'       => [
				'type'         => 'integer',
				'show_in_rest' => [ 'schema' => [ 'minimum' => 1 ] ],
			],
			// La prima è la principale. Servono al punteggio e allo schema, non a un <meta name="keywords">.
			'keywords'    => [
				'type'              => 'array',
				'sanitize_callback' => static fn( mixed $value ): mixed => map_deep( $value, 'sanitize_text_field' ),
				'show_in_rest'      => [
					'schema' => [
						'items'       => [
							'type'      => 'string',
							'minLength' => 1,
						],
						'uniqueItems' => true,
					],
				],
			],
			'index'       => $enum( 'index', 'noindex' ),
			'follow'      => $enum( 'follow', 'nofollow' ),
			// Lo slug di un post type: la pagina diventa la CollectionPage di quel tipo.
			'collection'  => [
				'type'         => 'string',
				'show_in_rest' => [ 'schema' => [ 'pattern' => '^[a-z0-9_-]{1,20}$' ] ],
			],
		];
		return array_map(
			static fn( array $args ): array => $args + [
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => static fn( bool $allowed, string $meta_key, int $object_id, int $user_id ): bool => user_can( $user_id, 'barrio_edit_seo' ),
			],
			$meta
		);
	}
}
