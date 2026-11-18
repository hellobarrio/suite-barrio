<?php

namespace BarrioSuite\Seo\Api;

use BarrioSuite\I18n;
use BarrioSuite\Seo\Editor;
use BarrioSuite\Seo\Meta;
use BarrioSuite\Seo\Module;
use BarrioSuite\Seo\Repository\RedirectRepository;
use WP_Error;
use WP_Query;
use WP_REST_Post_Meta_Fields;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Term_Meta_Fields;

/**
 * SEO → Contenuti (barrio_manage_seo): i contenuti o i termini di un tipo, con ciò che serve al pannello SEO e
 * al punteggio, che si calcola nel browser. Le modifiche passano dalla REST del core (meta). Anche i numeri
 * della card SEO nella Bacheca.
 */
final class RestContents {

	public static function register(): void {
		$manage = static fn(): bool => current_user_can( 'barrio_manage_seo' );
		register_rest_route(
			'barrio/v1',
			'/seo/contents/(?P<group>post_types|taxonomies)/(?P<name>[a-z0-9_-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list' ],
				'permission_callback' => $manage,
			]
		);
		register_rest_route(
			'barrio/v1',
			'/seo/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'stats' ],
				'permission_callback' => $manage,
			]
		);
	}

	/**
	 * Tutti i contenuti del tipo che chi guarda può modificare, tranne quelli nel cestino; tutti i termini. Con
	 * più lingue ogni riga ha la sua, e ogni lingua il suo contesto (descrizione predefinita, nome del sito).
	 * ponytail: tutto in una risposta, con filtri e ordinamento nel browser (localQuery); oltre ~2000 per tipo
	 * si passa al server, come per le registrazioni.
	 */
	public static function list( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$group = (string) $request['group'];
		$name  = (string) $request['name'];
		$posts = 'post_types' === $group && isset( Module::post_types()[ $name ] );
		if ( ! $posts && ! ( 'taxonomies' === $group && isset( Module::taxonomies()[ $name ] ) ) ) {
			return new WP_Error( 'barrio_seo_unknown_type', __( 'This content type does not exist.', 'barrio-suite' ), [ 'status' => 404 ] );
		}
		$rows     = [];
		$contexts = [];
		// Una lingua alla volta, la principale per prima. Un contenuto che il plugin multilingua mostra in più
		// lingue (un tipo che non traduce, o l'originale dove manca la traduzione) resta della prima.
		foreach ( I18n::languages() as $lang ) {
			[ $contexts[ $lang ], $in_lang ] = I18n::in( $lang, static fn(): array => [ Editor::context( $group, $name ), $posts ? self::posts( $name ) : self::terms( $name ) ] );
			foreach ( $in_lang as $row ) {
				$rows[ (int) $row['id'] ] ??= $row + [ 'lang' => $lang ];
			}
		}
		return new WP_REST_Response(
			[
				'contexts' => $contexts,
				'rows'     => array_values( $rows ),
			]
		);
	}

	/** @return list<array<string, mixed>> */
	private static function posts( string $type ): array {
		$fields = new WP_REST_Post_Meta_Fields( $type );
		$rows   = [];
		$posts  = get_posts(
			[
				'post_type'        => $type,
				'post_status'      => [ 'publish', 'future', 'draft', 'pending', 'private' ],
				'posts_per_page'   => -1,
				// Con i filtri, perché il plugin multilingua tenga la lingua attuale.
				'suppress_filters' => false,
			]
		);
		foreach ( $posts as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$rows[] = [
					'id'     => $post->ID,
					'status' => $post->post_status,
					'page'   => Editor::post_page( $post ),
					'meta'   => Editor::meta( $fields, $post->ID ),
					'view'   => 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post ),
					'edit'   => get_edit_post_link( $post, 'raw' ),
				];
			}
		}
		return $rows;
	}

	/** @return list<array<string, mixed>> */
	private static function terms( string $taxonomy ): array {
		$fields = new WP_REST_Term_Meta_Fields( $taxonomy );
		$rows   = [];
		$terms  = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			]
		);
		foreach ( is_array( $terms ) ? $terms : [] as $term ) {
			if ( current_user_can( 'edit_term', $term->term_id ) ) {
				$page   = Editor::term_page( $term );
				$rows[] = [
					'id'     => $term->term_id,
					'status' => 'publish',
					'page'   => $page,
					'meta'   => Editor::meta( $fields, $term->term_id ),
					'view'   => $page['url'],
					'edit'   => get_edit_term_link( $term ),
				];
			}
		}
		return $rows;
	}

	// Contenuti pubblicati indicizzabili, quanti di questi senza una descrizione loro, e i redirect. In tutte le
	// lingue: senza i filtri delle query il plugin multilingua non tiene solo quella della richiesta.
	public static function stats(): WP_REST_Response {
		$indexed = 0;
		$missing = 0;
		foreach ( array_keys( Module::post_types() ) as $type ) {
			$args     = [
				'post_type'        => $type,
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters -- senza i filtri del plugin multilingua si contano tutte le lingue.
				'suppress_filters' => true,
			] + Meta::indexable( 'post_types', $type );
			$indexed += ( new WP_Query( $args ) )->found_posts;
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- la descrizione di un contenuto è il suo meta.
			$args['meta_query'] = [
				$args['meta_query'],
				[
					'relation' => 'OR',
					[
						'key'     => '_barrio_seo_description',
						'compare' => 'NOT EXISTS',
					],
					[
						'key'   => '_barrio_seo_description',
						'value' => '',
					],
				],
			];
			$missing           += ( new WP_Query( $args ) )->found_posts;
		}
		return new WP_REST_Response(
			[
				'indexed'             => $indexed,
				'without_description' => $missing,
				'redirects'           => RedirectRepository::count(),
			]
		);
	}
}
