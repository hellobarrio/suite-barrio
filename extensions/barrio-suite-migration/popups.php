<?php
// Il passaggio dei popup del prototipo: le righe di wpb_popups e wpb_popups_translations diventano popup di Barrio
// Suite con PopupRepository, ognuno nella sua transazione. I popup migrati si ricordano in barrio_migration_popups_ids
// (ID del prototipo → ID di Barrio Suite) e al giro dopo si saltano.

use BarrioSuite\Database\Db;
use BarrioSuite\I18n;
use BarrioSuite\Popups\PopupRepository;

defined( 'ABSPATH' ) || exit;

const BARRIO_MIGRATION_POPUP_TAGS = [ 'suite_barrio_popup', 'wpb_popup' ];

function barrio_migration_popups_boot(): void {
	// Vuoti: il popup è in tutte le pagine. Se il modulo del prototipo è acceso restano suoi.
	add_action(
		'init',
		static function (): void {
			foreach ( BARRIO_MIGRATION_POPUP_TAGS as $tag ) {
				if ( ! shortcode_exists( $tag ) ) {
					add_shortcode( $tag, '__return_empty_string' );
				}
			}
		},
		20
	);
}

/** @param BarrioMigrationReport $report */
function barrio_migration_popups_run( bool $dry_run, array &$report ): ?WP_Error {
	global $wpdb;
	$table = "{$wpdb->prefix}wpb_popups";
	if ( ! barrio_migration_table_exists( $table ) ) {
		return new WP_Error( 'barrio_migration_no_prototype', 'The popups of Suite Barrio are not in this database.' );
	}
	$error = $dry_run ? null : barrio_migration_tables( 'popups' );
	if ( $error ) {
		return $error;
	}
	barrio_migration_unknown_columns( 'Popups', $table, [ 'id', 'title', 'content', 'image_id', 'image_url', 'link', 'link_title', 'link_target', 'active', 'created_at', 'updated_at' ], $report['warnings'] );
	if ( barrio_migration_table_exists( "{$table}_translations" ) ) {
		barrio_migration_unknown_columns( 'Popups', "{$table}_translations", [ 'id', 'popup_id', 'lang', 'title', 'content', 'link', 'link_title', 'link_target', 'updated_at' ], $report['warnings'] );
	}
	barrio_migration_unknown_options( 'Popups', [ 'suite_barrio_popups_' ], [ 'suite_barrio_popups_db_version', 'suite_barrio_popups_limit' ], $report['warnings'] );
	$main   = I18n::languages()[0];
	$texts  = barrio_migration_translations( "{$table}_translations", 'popup_id', $main );
	$map    = array_map( 'intval', (array) get_option( 'barrio_migration_popups_ids', [] ) );
	$counts = [
		'data'     => 'popups',
		'found'    => 0,
		'migrated' => 0,
		'skipped'  => 0,
	];
	foreach ( Db::rows( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $table ) ) as $row ) {
		$id = (int) $row['id'];
		++$counts['found'];
		if ( isset( $map[ $id ] ) ) {
			++$counts['skipped'];
			continue;
		}
		$popup = barrio_migration_popup( $row, $texts[ $id ] ?? [], $main, $report['warnings'] );
		$saved = $dry_run ? rest_validate_value_from_schema( $popup, PopupRepository::schema(), 'popup' ) : PopupRepository::save( null, $popup );
		if ( is_wp_error( $saved ) ) {
			$report['warnings'][] = "Popup {$id} not migrated: " . $saved->get_error_message();
			++$counts['skipped'];
			continue;
		}
		if ( ! $dry_run ) {
			$map[ $id ] = (int) $saved;
			update_option( 'barrio_migration_popups_ids', $map, false );
		}
		++$counts['migrated'];
	}
	$report['counts'][] = $counts;
	$report['notes'][]  = 'The active popup stays on, with 7 days before it comes back after closing (the default of Suite Barrio); the others stay off.';
	$uses               = barrio_migration_shortcode_uses( BARRIO_MIGRATION_POPUP_TAGS );
	if ( $uses ) {
		$report['warnings'][] = 'Shortcodes that now print nothing, because the popup is in every page. If they set delay or days, set the days in Popups → Options: ' . implode( '; ', $uses ) . '.';
	}
	return null;
}

/**
 * Un popup del prototipo come lo scrive PopupRepository. Le traduzioni sono righe complete: un campo vuoto prende
 * quello della lingua principale. Il testo passa da wpautop(), che il prototipo applicava nel sito.
 *
 * @param array<string, string|null>                $row
 * @param array<string, array<string, string|null>> $translations Per lingua, senza la principale.
 * @param list<string>                              $warnings
 * @return array<string, mixed>
 */
function barrio_migration_popup( array $row, array $translations, string $main, array &$warnings ): array {
	$id     = (int) $row['id'];
	$fields = [ 'title', 'content', 'link', 'link_title' ];
	$base   = array_map( static fn( string $field ): string => trim( (string) ( $row[ $field ] ?? '' ) ), array_combine( $fields, $fields ) );
	if ( '' === $base['title'] ) {
		$base['title'] = "Popup {$id}";
		$warnings[]    = "Popup {$id} has no title, which Barrio Suite shows and needs: it is \"{$base['title']}\", change it in Popups.";
	}
	$i18n = [];
	foreach ( [ $main => $base ] + $translations as $lang => $text ) {
		$text = array_map( static fn( string $field ): string => trim( (string) ( $text[ $field ] ?? '' ) ) ?: $base[ $field ], array_combine( $fields, $fields ) );
		$link = barrio_migration_link( $text['link'] );
		if ( null === $link && '' !== $text['link'] ) {
			$warnings[] = "Popup {$id} ({$lang}): the link \"{$text['link']}\" is not an address or a path, so the popup has no button.";
		}
		$i18n[ $lang ] = PopupRepository::texts(
			[
				'title'        => $text['title'],
				'content'      => wpautop( $text['content'] ),
				'button_label' => null === $link ? '' : $text['link_title'],
				'link_url'     => $link,
			]
		);
	}

	// Un'immagine scelta solo come URL diventa il suo allegato.
	$image = (int) $row['image_id'];
	$url   = trim( (string) $row['image_url'] );
	if ( 'attachment' !== get_post_type( $image ) ) {
		$image = '' === $url ? 0 : attachment_url_to_postid( $url );
		if ( '' !== $url && ! $image ) {
			$warnings[] = "Popup {$id}: the image {$url} is not in the media library, choose it in Popups.";
		}
	}
	return [
		'is_enabled' => '1' === $row['active'],
		'starts_at'  => null,
		'ends_at'    => null,
		'image_id'   => $image ?: null,
		'settings'   => [
			'new_tab' => '_blank' === $row['link_target'],
			'days'    => 7,
		],
		'i18n'       => $i18n,
	];
}
