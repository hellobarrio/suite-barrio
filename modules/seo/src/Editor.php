<?php

namespace BarrioSuite\Seo;

use WP_Post;
use WP_REST_Meta_Fields;
use WP_REST_Post_Meta_Fields;
use WP_REST_Request;
use WP_REST_Term_Meta_Fields;
use WP_Term;

/**
 * Il pannello SEO (modules/seo/assets/src/editor) su tutti i tipi e le tassonomie pubblici, per chi ha
 * barrio_edit_seo e può modificare il contenuto. Nell'editor a blocchi è una sidebar (build/seo-sidebar.js)
 * e i meta si salvano con il contenuto. Nell'editor classico è una metabox e nei termini sta nella pagina di
 * modifica (build/seo-form.js): il pannello scrive i meta in un campo nascosto del form, e si salvano con la
 * classe dei meta della REST del core, con la sua validazione e i suoi permessi.
 */
final class Editor {

	private const FIELD = 'barrio_seo';

	// enqueue_block_editor_assets: anche nell'editor del sito e dei widget, dove non c'è un contenuto.
	public static function block_editor(): void {
		$post = get_post();
		if ( 'post' === get_current_screen()?->base && $post && self::can_post( $post ) ) {
			self::enqueue( 'seo-sidebar', self::post_data( $post ) );
		}
	}

	// add_meta_boxes: anche per i commenti, che non sono post.
	public static function meta_box( string $post_type, mixed $post ): void {
		if ( $post instanceof WP_Post && ! use_block_editor_for_post( $post ) && self::can_post( $post ) ) {
			add_meta_box( 'barrio-seo', 'SEO', [ self::class, 'render_post' ], null, 'normal', 'high' );
			$page = [
				'title'    => $post->post_title,
				'excerpt'  => $post->post_excerpt,
				'content'  => $post->post_content,
				'password' => $post->post_password,
				'featured' => (int) get_post_thumbnail_id( $post ),
				'url'      => (string) get_permalink( $post ),
			];
			self::form( self::post_data( $post ) + [ 'page' => $page ], new WP_REST_Post_Meta_Fields( $post->post_type ), $post->ID );
		}
	}

	public static function render_post( WP_Post $post ): void {
		self::mount( "post-{$post->ID}" );
	}

	public static function save_post( int $post_id, WP_Post $post ): void {
		$meta = self::submitted( "post-{$post_id}" );
		if ( null !== $meta ) {
			( new WP_REST_Post_Meta_Fields( $post->post_type ) )->update_value( Module::changes( 'post', $post_id, $meta ), $post_id );
		}
	}

	public static function term_screen(): void {
		add_action( get_current_screen()?->taxonomy . '_edit_form', [ self::class, 'render_term' ] );
	}

	public static function render_term( WP_Term $term ): void {
		if ( ! in_array( $term->taxonomy, get_taxonomies( [ 'public' => true ] ), true ) || ! current_user_can( 'barrio_edit_seo' ) || ! current_user_can( 'edit_term', $term->term_id ) ) {
			return;
		}
		$link = get_term_link( $term );
		$data = self::data( 'taxonomies', $term->taxonomy ) + [
			'page' => [
				'title'       => $term->name,
				'description' => $term->description,
				'url'         => is_string( $link ) ? $link : '',
			],
		];
		self::form( $data, new WP_REST_Term_Meta_Fields( $term->taxonomy ), $term->term_id );
		echo '<h2>SEO</h2>';
		self::mount( "term-{$term->term_id}" );
	}

	public static function save_term( int $term_id, int $tt_id, string $taxonomy ): void {
		$meta = self::submitted( "term-{$term_id}" );
		if ( null !== $meta ) {
			( new WP_REST_Term_Meta_Fields( $taxonomy ) )->update_value( Module::changes( 'term', $term_id, $meta ), $term_id );
		}
	}

	private static function can_post( WP_Post $post ): bool {
		return 'attachment' !== $post->post_type && get_post_type_object( $post->post_type )?->public
			&& current_user_can( 'barrio_edit_seo' ) && current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Il nonce e il campo nascosto li legge submitted(): senza, il form salva il contenuto senza toccare i meta.
	 */
	private static function mount( string $key ): void {
		wp_nonce_field( self::FIELD . "-{$key}", self::FIELD . '_nonce', false );
		echo '<div id="barrio-seo-panel"></div>';
	}

	/** @return array<string, mixed>|null I meta SEO del campo nascosto, se il nonce è quello del contenuto o del termine. */
	private static function submitted( string $key ): ?array {
		$nonce = sanitize_key( wp_unslash( $_POST[ self::FIELD . '_nonce' ] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, self::FIELD . "-{$key}" ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, validato con lo schema dei meta.
		$meta = json_decode( wp_unslash( (string) ( $_POST[ self::FIELD ] ?? '' ) ), true );
		return is_array( $meta ) ? array_intersect_key( $meta, array_flip( array_map( static fn( string $name ): string => "_barrio_seo_{$name}", Module::META ) ) ) : null;
	}

	/** @return array<string, mixed> Nell'editor a blocchi titolo, contenuto e URL vengono dal suo store. */
	private static function post_data( WP_Post $post ): array {
		$data = self::data( 'post_types', $post->post_type );
		// Solo le pagine diventano l'archivio di un tipo (Schema::item_list).
		if ( 'page' === $post->post_type ) {
			foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
				if ( 'attachment' !== $type->name ) {
					$data['collections'][] = [
						'value' => $type->name,
						'label' => $type->labels->name,
					];
				}
			}
		}
		return $data;
	}

	/**
	 * Nei form i meta li porta la pagina, come li darebbe la REST del core.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function form( array $data, WP_REST_Meta_Fields $fields, int $object_id ): void {
		$meta         = $fields->get_value( $object_id, new WP_REST_Request() );
		$data['meta'] = array_filter( $meta, static fn( string $key ): bool => str_starts_with( $key, '_barrio_seo_' ), ARRAY_FILTER_USE_KEY );
		self::enqueue( 'seo-form', $data );
	}

	/**
	 * Ciò che il pannello eredita dal sito e dal tipo o dalla tassonomia.
	 *
	 * @return array<string, mixed>
	 */
	private static function data( string $group, string $name ): array {
		$settings = Settings::get();
		return [
			'site'        => [
				'name'      => Meta::text( get_bloginfo( 'name' ) ),
				'separator' => $settings['title']['separator'],
				'order'     => $settings['title']['order'],
				'home'      => home_url( '/' ),
			],
			'description' => Meta::default_description(),
			'image'       => (int) $settings['default_image'],
			'robots'      => Meta::robots( $group, $name ),
		];
	}

	/**
	 * Due build, perché l'editor classico e i termini non caricano l'editor a blocchi.
	 *
	 * @param 'seo-sidebar'|'seo-form' $build
	 * @param array<string, mixed>     $data
	 */
	private static function enqueue( string $build, array $data ): void {
		$dir = dirname( __DIR__, 3 );
		if ( ! is_file( "{$dir}/build/{$build}.asset.php" ) ) {
			return;
		}
		/** @var array{dependencies: list<non-empty-string>, version: string} $asset */
		$asset  = require "{$dir}/build/{$build}.asset.php";
		$url    = plugins_url( "build/{$build}", "{$dir}/barrio-suite.php" );
		$handle = "barrio-{$build}";
		wp_enqueue_script( $handle, "{$url}.js", $asset['dependencies'], $asset['version'], true );
		wp_add_inline_script( $handle, 'window.barrioSeo = ' . wp_json_encode( $data ) . ';', 'before' );
		wp_set_script_translations( $handle, 'barrio-suite', "{$dir}/languages" );
		wp_enqueue_style( $handle, "{$url}.css", [ 'wp-components' ], $asset['version'] );
		wp_style_add_data( $handle, 'rtl', 'replace' );
		if ( 'seo-form' === $build ) {
			// L'immagine si sceglie dalla libreria dei media, che i termini e alcuni tipi non caricano.
			wp_enqueue_media();
		}
	}
}
