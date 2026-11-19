<?php

namespace BarrioSuite\Banners;

use BarrioSuite\Database\Db;
use BarrioSuite\I18n;
use BarrioSuite\Visibility;
use WP_Error;

/**
 * L'unico punto che legge e scrive le tabelle dei banner. Valida ogni scrittura con schema() e garantisce la riga dei
 * testi nella lingua principale, che nessun vincolo SQL può imporre. Ogni scrittura svuota il transient degli accesi,
 * letto nel sito, e fa partire barrio_banners_changed.
 *
 * @phpstan-type Texts array<string, array{text: string, link_url: string|null}>
 * @phpstan-type Settings array{position: string, scroll: bool, closable: bool, new_tab: bool, colors: array{background: string, text: string}, where: array{mode: string, home: bool, posts: list<int>, types: list<string>, terms: list<int>}, who: array{device: string, users: string}, days: int}
 * @phpstan-type Banner array{id: int, is_enabled: bool, starts_at: string|null, ends_at: string|null, settings: Settings, i18n: Texts, created_at: string, updated_at: string}
 */
final class BannerRepository {

	// Gli accesi non scaduti con i testi: senza scadenza, così nel sito non c'è nessuna query. Vuoto con zero banner.
	public const LIVE = 'barrio_banners_live';

	/** @return Banner|null */
	public static function get( int $id ): ?array {
		global $wpdb;
		return self::hydrate( Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Db::table( 'banners' ), $id ) ) )[0] ?? null;
	}

	/** @return list<Banner> I più recenti per primi. */
	public static function all(): array {
		global $wpdb;
		return self::hydrate( Db::rows( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', Db::table( 'banners' ) ) ) );
	}

	/** @return list<Banner> Gli accesi non scaduti, i più recenti per primi: quelli che il sito può mostrare. */
	public static function live(): array {
		$live = get_transient( self::LIVE );
		if ( is_array( $live ) ) {
			/** @var list<Banner> $live */
			return $live;
		}
		global $wpdb;
		$live = self::hydrate( Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE is_enabled = 1 AND (ends_at IS NULL OR ends_at > %s) ORDER BY id DESC', Db::table( 'banners' ), gmdate( 'Y-m-d H:i:s' ) ) ) );
		set_transient( self::LIVE, $live );
		return $live;
	}

	/**
	 * Crea ($id null) o sostituisce un banner. Date in UTC (Y-m-d H:i:s) o null; i testi per lingua, con la principale.
	 *
	 * @param array<string, mixed> $banner Come schema(): is_enabled, starts_at, ends_at, settings, i18n.
	 */
	public static function save( ?int $id, array $banner ): int|WP_Error {
		$banner['i18n'] = array_map(
			static fn( mixed $text ): mixed => is_array( $text ) ? [
				'text'     => sanitize_text_field( (string) ( $text['text'] ?? '' ) ),
				'link_url' => isset( $text['link_url'] ) && '' !== trim( (string) $text['link_url'] ) ? esc_url_raw( trim( (string) $text['link_url'] ) ) : null,
			] : $text,
			(array) $banner['i18n']
		);
		$valid          = rest_validate_value_from_schema( $banner, self::schema(), 'banner' );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'rest_invalid_param', $valid->get_error_message(), [ 'status' => 400 ] );
		}
		// Valori come "1" o "true" diventano del loro tipo prima di finire nel JSON.
		/** @var array{is_enabled: bool, starts_at: string|null, ends_at: string|null, settings: array<string, mixed>, i18n: Texts} $banner */
		$banner = rest_sanitize_value_from_schema( $banner, self::schema(), 'banner' );
		if ( ! isset( $banner['i18n'][ I18n::languages()[0] ] ) ) {
			return new WP_Error( 'barrio_banner_main_language', __( 'Write the text of the banner in the main language.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		if ( null !== $banner['starts_at'] && null !== $banner['ends_at'] && $banner['ends_at'] <= $banner['starts_at'] ) {
			return new WP_Error( 'barrio_banner_period', __( 'The end must come after the start.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		global $wpdb;
		$id = Db::transaction(
			static function () use ( $wpdb, $id, $banner ): ?int {
				$now = gmdate( 'Y-m-d H:i:s' );
				$row = [
					'is_enabled' => (int) $banner['is_enabled'],
					'starts_at'  => $banner['starts_at'],
					'ends_at'    => $banner['ends_at'],
					'settings'   => (string) wp_json_encode( $banner['settings'] ),
					'updated_at' => $now,
				];
				if ( null === $id ) {
					$id = Db::insert( Db::table( 'banners' ), $row + [ 'created_at' => $now ] );
				} elseif ( null === Db::var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', Db::table( 'banners' ), $id ) ) ) {
					return null;
				} else {
					Db::update( Db::table( 'banners' ), $row, [ 'id' => $id ] );
					Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE banner_id = %d', Db::table( 'banners_i18n' ), $id ) );
				}
				foreach ( $banner['i18n'] as $lang => $text ) {
					Db::insert( Db::table( 'banners_i18n' ), [ 'banner_id' => $id ] + compact( 'lang' ) + $text );
				}
				return $id;
			}
		);
		if ( null === $id ) {
			return new WP_Error( 'barrio_banner_not_found', __( 'Banner not found.', 'barrio-suite' ), [ 'status' => 404 ] );
		}
		self::changed();
		return $id;
	}

	// La copia è spenta: si accende dopo averla cambiata.
	public static function duplicate( int $id ): int|WP_Error {
		$banner = self::get( $id );
		if ( null === $banner ) {
			return new WP_Error( 'barrio_banner_not_found', __( 'Banner not found.', 'barrio-suite' ), [ 'status' => 404 ] );
		}
		return self::save( null, [ 'is_enabled' => false ] + $banner );
	}

	// Quanti ne ha cambiati.
	public static function set_enabled( bool $enabled, int ...$ids ): int {
		global $wpdb;
		$count = Db::query( $wpdb->prepare( 'UPDATE %i SET is_enabled = %d, updated_at = %s WHERE id IN (', Db::table( 'banners' ), (int) $enabled, gmdate( 'Y-m-d H:i:s' ) ) . ( implode( ',', $ids ) ?: '0' ) . ')' );
		self::changed();
		return $count;
	}

	// Quanti ne ha eliminati; i testi seguono in CASCADE.
	public static function delete( int ...$ids ): int {
		global $wpdb;
		$count = Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE id IN (', Db::table( 'banners' ) ) . ( implode( ',', $ids ) ?: '0' ) . ')' );
		self::changed();
		return $count;
	}

	/** @return array<string, mixed> Lo schema JSON di un banner da scrivere: fa i controlli che il database non sa fare. */
	public static function schema(): array {
		$color = [
			'type'    => 'string',
			'pattern' => '^(#[0-9a-fA-F]{6})?$',
		];
		$utc   = [
			'type'    => [ 'string', 'null' ],
			'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$',
		];
		return [
			'type'                 => 'object',
			'properties'           => [
				'is_enabled' => [ 'type' => 'boolean' ],
				'starts_at'  => $utc,
				'ends_at'    => $utc,
				'settings'   => [
					'type'                 => 'object',
					'properties'           => [
						'position' => [
							'type' => 'string',
							'enum' => [ 'top', 'bottom' ],
						],
						// La scritta che scorre; altrimenti ferma.
						'scroll'   => [ 'type' => 'boolean' ],
						'closable' => [ 'type' => 'boolean' ],
						'new_tab'  => [ 'type' => 'boolean' ],
						// Vuoti: le custom property del CSS, che il tema può cambiare.
						'colors'   => [
							'type'                 => 'object',
							'properties'           => [
								'background' => $color,
								'text'       => $color,
							],
							'required'             => [ 'background', 'text' ],
							'additionalProperties' => false,
						],
					] + Visibility::SCHEMA,
					'required'             => [ 'position', 'scroll', 'closable', 'new_tab', 'colors', 'where', 'who', 'days' ],
					'additionalProperties' => false,
				],
				'i18n'       => [
					'type'                 => 'object',
					'patternProperties'    => [
						'^[a-z]{2,3}(-[a-z0-9]{2,8})*$' => [
							'type'                 => 'object',
							'properties'           => [
								'text'     => [
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 255,
								],
								'link_url' => [
									'type'      => [ 'string', 'null' ],
									'maxLength' => 2048,
									'pattern'   => '^(/|https?://)',
								],
							],
							'required'             => [ 'text', 'link_url' ],
							'additionalProperties' => false,
						],
					],
					'additionalProperties' => false,
				],
			],
			'required'             => [ 'is_enabled', 'starts_at', 'ends_at', 'settings', 'i18n' ],
			'additionalProperties' => true,
		];
	}

	private static function changed(): void {
		delete_transient( self::LIVE );
		do_action( 'barrio_banners_changed' );
	}

	/**
	 * Le righe con i loro testi, in una query sola per tutte.
	 *
	 * @param list<array<string, string|null>> $rows
	 * @return list<Banner>
	 */
	private static function hydrate( array $rows ): array {
		if ( ! $rows ) {
			return [];
		}
		global $wpdb;
		$ids  = array_map( 'intval', array_column( $rows, 'id' ) );
		$i18n = [];
		foreach ( Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE banner_id IN (', Db::table( 'banners_i18n' ) ) . implode( ',', $ids ) . ')' ) as $text ) {
			$i18n[ (int) $text['banner_id'] ][ (string) $text['lang'] ] = [
				'text'     => (string) $text['text'],
				'link_url' => $text['link_url'],
			];
		}
		return array_map(
			static function ( array $row ) use ( $i18n ): array {
				/** @var Settings $settings */
				$settings = json_decode( (string) $row['settings'], true );
				return [
					'id'         => (int) $row['id'],
					'is_enabled' => '1' === $row['is_enabled'],
					'starts_at'  => $row['starts_at'],
					'ends_at'    => $row['ends_at'],
					'settings'   => $settings,
					'i18n'       => $i18n[ (int) $row['id'] ] ?? [],
					'created_at' => (string) $row['created_at'],
					'updated_at' => (string) $row['updated_at'],
				];
			},
			$rows
		);
	}
}
