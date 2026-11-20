<?php

namespace BarrioSuite\Banners;

use BarrioSuite\Database\Db;
use BarrioSuite\Database\PeriodRepository;
use BarrioSuite\I18n;
use BarrioSuite\Period;
use WP_Error;

/**
 * L'unico punto che legge e scrive le tabelle dei banner. Valida ogni scrittura con schema() e garantisce la riga dei
 * testi nella lingua principale, che nessun vincolo SQL può imporre. Il resto, letture, accesi e transient, è in
 * PeriodRepository.
 *
 * @phpstan-type Texts array<string, array{text: string, link_url: string|null}>
 * @phpstan-type Banner array{id: int, is_enabled: bool, new_tab: bool, starts_at: string|null, ends_at: string|null, i18n: Texts, created_at: string, updated_at: string}
 */
final class BannerRepository {

	/** @use PeriodRepository<Banner> */
	use PeriodRepository;

	private const NAME = 'banners';

	// Gli accesi non scaduti con i testi (PeriodRepository::live()). Vuoto con zero banner.
	public const LIVE = 'barrio_banners_live';

	/**
	 * Crea ($id null) o sostituisce un banner. Date in UTC (Y-m-d H:i:s) o null; i testi per lingua, con la principale.
	 *
	 * @param array<string, mixed> $banner Come schema(): is_enabled, new_tab, starts_at, ends_at, i18n.
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
		// Valori come "1" o "true" diventano del loro tipo.
		/** @var array{is_enabled: bool, new_tab: bool, starts_at: string|null, ends_at: string|null, i18n: Texts} $banner */
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
					'new_tab'    => (int) $banner['new_tab'],
					'starts_at'  => $banner['starts_at'],
					'ends_at'    => $banner['ends_at'],
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
			return self::not_found();
		}
		self::changed();
		return $id;
	}

	/** @return array<string, mixed> Lo schema JSON di un banner da scrivere: fa i controlli che il database non sa fare. */
	public static function schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'is_enabled' => [ 'type' => 'boolean' ],
				'starts_at'  => Period::UTC,
				'ends_at'    => Period::UTC,
				'new_tab'    => [ 'type' => 'boolean' ],
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
			'required'             => [ 'is_enabled', 'new_tab', 'starts_at', 'ends_at', 'i18n' ],
			'additionalProperties' => true,
		];
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'barrio_banner_not_found', __( 'Banner not found.', 'barrio-suite' ), [ 'status' => 404 ] );
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
			static fn( array $row ): array => [
				'id'         => (int) $row['id'],
				'is_enabled' => '1' === $row['is_enabled'],
				'new_tab'    => '1' === $row['new_tab'],
				'starts_at'  => $row['starts_at'],
				'ends_at'    => $row['ends_at'],
				'i18n'       => $i18n[ (int) $row['id'] ] ?? [],
				'created_at' => (string) $row['created_at'],
				'updated_at' => (string) $row['updated_at'],
			],
			$rows
		);
	}
}
