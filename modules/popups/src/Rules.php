<?php

namespace BarrioSuite\Popups;

use WP_Post;
use WP_Post_Type;
use WP_Term;

/**
 * Dove e a chi compare un popup (PLAN-MODULI.md §1): una parte delle impostazioni JSON di ogni popup (SCHEMA); il
 * periodo sta nelle colonne starts_at ed ends_at, che si interrogano. Il server valuta pagina, utente e periodo; il
 * browser ricontrolla dispositivo, periodo e chiusura (assets/frontend/popup.js), perché con la cache di pagina l'HTML
 * può essere vecchio.
 *
 * Le regole salvano gli ID di contenuti e termini nella lingua principale: un plugin multilingua porta a quella l'ID
 * della pagina con il filtro barrio_main_language_id.
 *
 * @phpstan-type Where array{mode: string, home: bool, posts: list<int>, types: list<string>, terms: list<int>}
 * @phpstan-type Item array{id: int, starts_at: string|null, ends_at: string|null, settings: array{where: Where, who: array{device: string, users: string}, days: int}}
 */
final class Rules {

	public const SCHEMA = [
		'where' => [
			'type'                 => 'object',
			'properties'           => [
				// all: tutto il sito; only: solo nelle pagine scelte; except: ovunque tranne quelle.
				'mode'  => [
					'type' => 'string',
					'enum' => [ 'all', 'only', 'except' ],
				],
				'home'  => [ 'type' => 'boolean' ],
				'posts' => [
					'type'        => 'array',
					'items'       => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'uniqueItems' => true,
					'maxItems'    => 500,
				],
				// Tutti i contenuti di un tipo.
				'types' => [
					'type'        => 'array',
					'items'       => [
						'type'    => 'string',
						'pattern' => '^[a-z0-9_-]{1,20}$',
					],
					'uniqueItems' => true,
				],
				// I contenuti che hanno il termine e il suo archivio.
				'terms' => [
					'type'        => 'array',
					'items'       => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'uniqueItems' => true,
					'maxItems'    => 500,
				],
			],
			'required'             => [ 'mode', 'home', 'posts', 'types', 'terms' ],
			'additionalProperties' => false,
		],
		'who'   => [
			'type'                 => 'object',
			'properties'           => [
				'device' => [
					'type' => 'string',
					'enum' => [ 'all', 'desktop', 'mobile' ],
				],
				// guests: solo chi non ha fatto l'accesso; members: solo chi l'ha fatto.
				'users'  => [
					'type' => 'string',
					'enum' => [ 'all', 'guests', 'members' ],
				],
			],
			'required'             => [ 'device', 'users' ],
			'additionalProperties' => false,
		],
		// Dopo la chiusura non torna per tanti giorni; 0 torna alla pagina dopo.
		'days'  => [
			'type'    => 'integer',
			'minimum' => 0,
			'maximum' => 365,
		],
	];

	/**
	 * Cosa stampare nella pagina: per i desktop e per i telefoni il più recente tra i popup che valgono per la pagina,
	 * per chi guarda e per l'ora, così una promozione creata dopo vince su quella di sempre. Con il dispositivo su cui
	 * si apre: uno di sempre resta sui desktop se il più recente è solo per i telefoni. Al più due, uno se vale per
	 * entrambi.
	 *
	 * @template T of Item
	 * @param list<T> $items I più recenti per primi.
	 * @return list<array{0: T, 1: 'all'|'desktop'|'mobile'}>
	 */
	public static function pick( array $items ): array {
		$items   = array_values( array_filter( $items, [ self::class, 'applies' ] ) );
		$devices = [];
		foreach ( [ 'desktop', 'mobile' ] as $device ) {
			foreach ( $items as $index => $item ) {
				if ( in_array( $item['settings']['who']['device'], [ 'all', $device ], true ) ) {
					$devices[ $index ][] = $device;
					break;
				}
			}
		}
		$picked = [];
		foreach ( $devices as $index => $on ) {
			$picked[] = [ $items[ $index ], count( $on ) > 1 ? 'all' : $on[0] ];
		}
		return $picked;
	}

	/**
	 * I nomi dei contenuti e dei termini scelti, per l'admin; quelli eliminati mancano.
	 *
	 * @param Where $where
	 * @return array{posts: array<int, string>, terms: array<int, string>}
	 */
	public static function labels( array $where ): array {
		$labels = [
			'posts' => [],
			'terms' => [],
		];
		foreach ( $where['posts'] as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				$labels['posts'][ $id ] = $post->post_title;
			}
		}
		foreach ( $where['terms'] as $id ) {
			$term = get_term( $id );
			if ( $term instanceof WP_Term ) {
				$labels['terms'][ $id ] = $term->name;
			}
		}
		return $labels;
	}

	/** @return list<array{name: string, label: string}> I tipi di contenuto con una pagina nel sito, per "tutti i contenuti di un tipo". */
	public static function types(): array {
		$types = get_post_types( [ 'public' => true ], 'objects' );
		unset( $types['attachment'] );
		return array_values(
			array_map(
				static fn( WP_Post_Type $type ): array => [
					'name'  => $type->name,
					'label' => $type->labels->name,
				],
				$types
			)
		);
	}

	/** @param Item $item */
	private static function applies( array $item ): bool {
		$now   = gmdate( 'Y-m-d H:i:s' );
		$users = $item['settings']['who']['users'];
		$where = $item['settings']['where'];
		if ( ( null !== $item['starts_at'] && $item['starts_at'] > $now ) || ( null !== $item['ends_at'] && $item['ends_at'] <= $now ) ) {
			return false;
		}
		if ( 'all' !== $users && ( 'members' === $users ) !== is_user_logged_in() ) {
			return false;
		}
		return 'all' === $where['mode'] || ( 'only' === $where['mode'] ) === self::on_page( $where );
	}

	/** @param Where $where */
	private static function on_page( array $where ): bool {
		if ( $where['home'] && is_front_page() ) {
			return true;
		}
		$object = get_queried_object();
		$terms  = [];
		if ( is_singular() && $object instanceof WP_Post ) {
			if ( in_array( $object->post_type, $where['types'], true ) || in_array( self::main_id( $object->ID, $object->post_type ), $where['posts'], true ) ) {
				return true;
			}
			foreach ( $where['terms'] ? get_object_taxonomies( $object ) : [] as $taxonomy ) {
				$found = get_the_terms( $object, $taxonomy );
				$terms = is_array( $found ) ? [ ...$terms, ...$found ] : $terms;
			}
		} elseif ( ( is_category() || is_tag() || is_tax() ) && $object instanceof WP_Term ) {
			$terms = [ $object ];
		}
		foreach ( $terms as $term ) {
			if ( in_array( self::main_id( $term->term_id, $term->taxonomy ), $where['terms'], true ) ) {
				return true;
			}
		}
		return false;
	}

	// $type è il tipo di contenuto o la tassonomia: il plugin multilingua lo chiede (WPML, wpml_object_id).
	private static function main_id( int $id, string $type ): int {
		return (int) apply_filters( 'barrio_main_language_id', $id, $type );
	}
}
