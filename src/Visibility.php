<?php

namespace BarrioSuite;

use WP_Post;
use WP_Post_Type;
use WP_Term;

/**
 * Dove, a chi e per quanto compaiono popup e banner (PLAN-MODULI.md §1), uguali per i due moduli. Le regole sono una
 * parte delle impostazioni JSON di ogni elemento (SCHEMA); il periodo sta nelle colonne starts_at ed ends_at, che si
 * interrogano. Il server valuta pagina, utente e periodo; il browser ricontrolla periodo e chiusura
 * (assets/frontend/visibility.js), perché con la cache di pagina l'HTML può essere vecchio.
 *
 * Le regole salvano gli ID di contenuti e termini nella lingua principale: un plugin multilingua porta a quella l'ID
 * della pagina con il filtro barrio_main_language_id.
 *
 * @phpstan-type Rules array{where: array{mode: string, home: bool, posts: list<int>, types: list<string>, terms: list<int>}, who: array{device: string, users: string}, days: int}
 * @phpstan-type Item array{id: int, starts_at: string|null, ends_at: string|null, settings: Rules}
 */
final class Visibility {

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
	 * Lo stato di un elemento, calcolato dal periodo.
	 *
	 * @return 'off'|'scheduled'|'active'|'expired'
	 */
	public static function status( bool $enabled, ?string $starts_at, ?string $ends_at ): string {
		$now = gmdate( 'Y-m-d H:i:s' );
		return match ( true ) {
			! $enabled                                => 'off',
			null !== $ends_at && $ends_at <= $now     => 'expired',
			null !== $starts_at && $starts_at > $now  => 'scheduled',
			default                                   => 'active',
		};
	}

	/**
	 * Cosa stampare nella pagina: per i desktop e per i telefoni il più recente tra gli elementi che valgono per la
	 * pagina, per chi guarda e per l'ora, così una promozione creata dopo vince su quella di sempre. Con il dispositivo
	 * su cui si mostra (il CSS nasconde gli altri): uno di sempre resta sui desktop se il più recente è solo per i
	 * telefoni. Al più due, uno se vale per entrambi.
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
	 * Le regole per il browser: la chiave della chiusura in localStorage, inizio e fine in secondi, i giorni.
	 *
	 * @param Item $item
	 */
	public static function data( string $module, array $item ): string {
		$time = static fn( ?string $utc ): ?int => null === $utc ? null : (int) strtotime( "{$utc} UTC" );
		return (string) wp_json_encode(
			[
				'key'   => "barrio_{$module}_{$item['id']}",
				'start' => $time( $item['starts_at'] ),
				'end'   => $time( $item['ends_at'] ),
				'days'  => $item['settings']['days'],
			]
		);
	}

	/**
	 * I nomi dei contenuti e dei termini scelti, per l'admin; quelli eliminati mancano.
	 *
	 * @param array{posts: list<int>, terms: list<int>} $where
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

	public static function register(): void {
		wp_register_script_module( '@barrio/visibility', plugins_url( 'assets/frontend/visibility.js', __DIR__ ), [], BARRIO_SUITE_VERSION );
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

	/** @param array{mode: string, home: bool, posts: list<int>, types: list<string>, terms: list<int>} $where */
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
