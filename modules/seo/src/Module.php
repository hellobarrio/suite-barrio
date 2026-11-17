<?php

namespace BarrioSuite\Seo;

use BarrioSuite\Seo\Api\RestSettings;

final class Module {

	// Le capability del modulo, che si assegnano ai ruoli nei Permessi.
	public const CAPS = [ 'barrio_manage_seo', 'barrio_edit_seo' ];

	// I meta SEO di contenuti e termini, con il prefisso _barrio_seo_. Un meta assente vuol dire "eredita".
	public const META = [ 'title', 'description', 'image', 'keywords', 'index', 'follow', 'collection' ];

	public static function boot(): void {
		// Dopo i post type e le tassonomie dei temi e degli altri plugin, che si registrano su init.
		add_action( 'init', [ self::class, 'register_meta' ], 20 );
		add_action( 'rest_api_init', [ RestSettings::class, 'register' ] );
	}

	public static function deactivate(): void {}

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
