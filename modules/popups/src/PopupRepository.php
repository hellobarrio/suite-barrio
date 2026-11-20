<?php

namespace BarrioSuite\Popups;

use BarrioSuite\Database\Db;
use BarrioSuite\Database\PeriodRepository;
use BarrioSuite\I18n;
use BarrioSuite\Period;
use WP_Error;

/**
 * L'unico punto che legge e scrive le tabelle dei popup. Valida ogni scrittura con schema() e garantisce la riga dei
 * testi nella lingua principale, che nessun vincolo SQL può imporre. Il resto, letture, accesi e transient, è in
 * PeriodRepository.
 *
 * @phpstan-type Settings array{new_tab: bool, days: int}
 * @phpstan-type Texts array<string, array{title: string, content: string|null, button_label: string|null, link_url: string|null}>
 * @phpstan-type Popup array{id: int, is_enabled: bool, starts_at: string|null, ends_at: string|null, image_id: int|null, settings: Settings, i18n: Texts, created_at: string, updated_at: string}
 */
final class PopupRepository {

	/** @use PeriodRepository<Popup> */
	use PeriodRepository;

	private const NAME = 'popups';

	// Gli accesi non scaduti con i testi (PeriodRepository::live()). Vuoto con zero popup.
	public const LIVE = 'barrio_popups_live';

	/**
	 * I testi come li scrive l'editor: HTML filtrato, vuoti a null.
	 *
	 * @return array{title: string, content: string|null, button_label: string|null, link_url: string|null}
	 */
	public static function texts( mixed $text ): array {
		$text    = (array) $text;
		$content = trim( wp_kses_post( (string) ( $text['content'] ?? '' ) ) );
		$label   = sanitize_text_field( (string) ( $text['button_label'] ?? '' ) );
		$link    = trim( (string) ( $text['link_url'] ?? '' ) );
		return [
			'title'        => sanitize_text_field( (string) ( $text['title'] ?? '' ) ),
			// Senza testo (un paragrafo vuoto dell'editor) non c'è contenuto.
			'content'      => '' === trim( str_replace( '&nbsp;', ' ', wp_strip_all_tags( $content ) ) ) ? null : $content,
			'button_label' => '' === $label ? null : $label,
			// Un link non valido diventa '' e lo schema lo rifiuta.
			'link_url'     => '' === $link ? null : esc_url_raw( $link ),
		];
	}

	/**
	 * Crea ($id null) o sostituisce un popup. Date in UTC (Y-m-d H:i:s) o null; i testi per lingua, con la principale.
	 *
	 * @param array<string, mixed> $popup Come schema(): is_enabled, starts_at, ends_at, image_id, settings, i18n.
	 */
	public static function save( ?int $id, array $popup ): int|WP_Error {
		$popup['i18n'] = is_array( $popup['i18n'] ?? null ) ? array_map( [ self::class, 'texts' ], $popup['i18n'] ) : null;
		$valid         = rest_validate_value_from_schema( $popup, self::schema(), 'popup' );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'rest_invalid_param', $valid->get_error_message(), [ 'status' => 400 ] );
		}
		// Valori come "1" o "true" diventano del loro tipo.
		/** @var array{is_enabled: bool, starts_at: string|null, ends_at: string|null, image_id: int|null, settings: Settings, i18n: Texts} $popup */
		$popup = rest_sanitize_value_from_schema( $popup, self::schema(), 'popup' );
		if ( ! isset( $popup['i18n'][ I18n::languages()[0] ] ) ) {
			return new WP_Error( 'barrio_popup_main_language', __( 'Write the title of the popup in the main language.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		foreach ( $popup['i18n'] as $text ) {
			if ( null !== $text['button_label'] && null === $text['link_url'] ) {
				return new WP_Error( 'barrio_popup_button', __( 'A button needs a link.', 'barrio-suite' ), [ 'status' => 400 ] );
			}
		}
		if ( null !== $popup['starts_at'] && null !== $popup['ends_at'] && $popup['ends_at'] <= $popup['starts_at'] ) {
			return new WP_Error( 'barrio_popup_period', __( 'The end must come after the start.', 'barrio-suite' ), [ 'status' => 400 ] );
		}
		global $wpdb;
		$id = Db::transaction(
			static function () use ( $wpdb, $id, $popup ): ?int {
				$now = gmdate( 'Y-m-d H:i:s' );
				$row = [
					'is_enabled' => (int) $popup['is_enabled'],
					'starts_at'  => $popup['starts_at'],
					'ends_at'    => $popup['ends_at'],
					'image_id'   => $popup['image_id'],
					'settings'   => (string) wp_json_encode( $popup['settings'] ),
					'updated_at' => $now,
				];
				if ( null === $id ) {
					$id = Db::insert( Db::table( 'popups' ), $row + [ 'created_at' => $now ] );
				} elseif ( null === Db::var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', Db::table( 'popups' ), $id ) ) ) {
					return null;
				} else {
					Db::update( Db::table( 'popups' ), $row, [ 'id' => $id ] );
					Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE popup_id = %d', Db::table( 'popups_i18n' ), $id ) );
				}
				foreach ( $popup['i18n'] as $lang => $text ) {
					Db::insert( Db::table( 'popups_i18n' ), [ 'popup_id' => $id ] + compact( 'lang' ) + $text );
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

	/** @return array<string, mixed> Lo schema JSON di un popup da scrivere: fa i controlli che il database non sa fare. */
	public static function schema(): array {
		$text = [
			'type'      => [ 'string', 'null' ],
			'maxLength' => 255,
		];
		return [
			'type'                 => 'object',
			'properties'           => [
				'is_enabled' => [ 'type' => 'boolean' ],
				'starts_at'  => Period::UTC,
				'ends_at'    => Period::UTC,
				'image_id'   => [
					'type'    => [ 'integer', 'null' ],
					'minimum' => 1,
				],
				'settings'   => [
					'type'                 => 'object',
					'properties'           => [
						'new_tab' => [ 'type' => 'boolean' ],
						// Dopo la chiusura non torna per tanti giorni; 0 torna alla pagina dopo.
						'days'    => [
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 365,
						],
					],
					'required'             => [ 'new_tab', 'days' ],
					'additionalProperties' => false,
				],
				'i18n'       => [
					'type'                 => 'object',
					'patternProperties'    => [
						'^[a-z]{2,3}(-[a-z0-9]{2,8})*$' => [
							'type'                 => 'object',
							'properties'           => [
								'title'        => [
									'type'      => 'string',
									'minLength' => 1,
									'maxLength' => 255,
								],
								'content'      => [ 'type' => [ 'string', 'null' ] ],
								'button_label' => $text,
								'link_url'     => [
									'type'      => [ 'string', 'null' ],
									'maxLength' => 2048,
									'pattern'   => '^(/|https?://)',
								],
							],
							'required'             => [ 'title', 'content', 'button_label', 'link_url' ],
							'additionalProperties' => false,
						],
					],
					'additionalProperties' => false,
				],
			],
			'required'             => [ 'is_enabled', 'starts_at', 'ends_at', 'image_id', 'settings', 'i18n' ],
			'additionalProperties' => true,
		];
	}

	/**
	 * Le impostazioni salvate, senza quelle tolte dopo (l'apertura), che lo schema rifiuterebbe al prossimo salvataggio.
	 *
	 * @return Settings
	 */
	private static function settings( string $json ): array {
		/** @var Settings $settings */
		$settings = array_intersect_key( (array) json_decode( $json, true ), self::schema()['properties']['settings']['properties'] );
		return $settings;
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'barrio_popup_not_found', __( 'Popup not found.', 'barrio-suite' ), [ 'status' => 404 ] );
	}

	/**
	 * Le righe con i loro testi, in una query sola per tutte.
	 *
	 * @param list<array<string, string|null>> $rows
	 * @return list<Popup>
	 */
	private static function hydrate( array $rows ): array {
		if ( ! $rows ) {
			return [];
		}
		global $wpdb;
		$ids  = array_map( 'intval', array_column( $rows, 'id' ) );
		$i18n = [];
		foreach ( Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE popup_id IN (', Db::table( 'popups_i18n' ) ) . implode( ',', $ids ) . ')' ) as $text ) {
			$i18n[ (int) $text['popup_id'] ][ (string) $text['lang'] ] = [
				'title'        => (string) $text['title'],
				'content'      => $text['content'],
				'button_label' => $text['button_label'],
				'link_url'     => $text['link_url'],
			];
		}
		return array_map(
			static fn( array $row ): array => [
				'id'         => (int) $row['id'],
				'is_enabled' => '1' === $row['is_enabled'],
				'starts_at'  => $row['starts_at'],
				'ends_at'    => $row['ends_at'],
				'image_id'   => null === $row['image_id'] ? null : (int) $row['image_id'],
				'settings'   => self::settings( (string) $row['settings'] ),
				'i18n'       => $i18n[ (int) $row['id'] ] ?? [],
				'created_at' => (string) $row['created_at'],
				'updated_at' => (string) $row['updated_at'],
			],
			$rows
		);
	}
}
