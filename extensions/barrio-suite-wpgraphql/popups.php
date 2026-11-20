<?php
// Il popup con lo schema del prototipo e i campi che il prototipo non aveva (giorni e periodo): hbPopup, lo stesso del
// sito (PopupRepository::shown()). Mai i programmati: un frontend in cache li vede con la revalidation al loro inizio.

use BarrioSuite\I18n;
use BarrioSuite\Period;
use BarrioSuite\Popups\Module;
use BarrioSuite\Popups\PopupRepository;
use WPGraphQL\Model\Post;

defined( 'ABSPATH' ) || exit;

function barrio_wpgraphql_popups_boot(): void {
	// Priorità 9, come per i form: anche il prototipo registra hbPopup con il suo modulo spento.
	add_action( 'graphql_register_types', 'barrio_wpgraphql_register_popups', 9 );
}

function barrio_wpgraphql_register_popups(): void {
	register_graphql_object_type(
		'HBPopupImage',
		[
			'description' => 'The image of the popup.',
			'fields'      => [ 'node' => [ 'type' => 'MediaItem' ] ],
		]
	);
	register_graphql_object_type(
		'HBPopupCta',
		[
			'description' => 'The button of the popup.',
			'fields'      => [
				'url'    => [ 'type' => 'String' ],
				'title'  => [
					'type'        => 'String',
					'description' => 'The text of the button, or the standard one in the requested language.',
				],
				'target' => [
					'type'        => 'String',
					'description' => '_self or _blank.',
				],
			],
		]
	);
	register_graphql_object_type(
		'HBPopup',
		[
			'description' => 'The popup of the site in the requested language.',
			'fields'      => [
				'id'       => [ 'type' => [ 'non_null' => 'Int' ] ],
				'title'    => [ 'type' => 'String' ],
				'content'  => [
					'type'        => 'String',
					'description' => 'HTML. The only shortcode left is that of a form, like [barrio_form id="3"]: read it with hbForm and send it with hbSubmitForm. Without the Forms module it is not there.',
				],
				'link'     => [
					'type'        => 'HBPopupCta',
					'description' => 'Null without a button.',
				],
				'image'    => [
					'type'        => 'HBPopupImage',
					'description' => 'Null without an image.',
				],
				'days'     => [
					'type'        => 'Int',
					'description' => 'Once closed, it opens again for the same visitor after these days; with 0, on the next page. Once a form in it is sent, never again.',
				],
				'startsAt' => [
					'type'        => 'String',
					'description' => 'When it starts showing, ISO 8601 in UTC; null without a start. A cached frontend can check it in the browser.',
				],
				'endsAt'   => [
					'type'        => 'String',
					'description' => 'When it stops showing, ISO 8601 in UTC; null without an end.',
				],
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbPopup',
		[
			'type'        => 'HBPopup',
			'description' => 'The newest popup that is on and within its period, the same as on WordPress; null if there is none.',
			'args'        => [
				'lang' => [
					'type'        => 'String',
					'description' => 'A language code, like en. Without it, or if it is not active, the main language.',
				],
			],
			'resolve'     => static function ( mixed $root, array $args ): ?array {
				$popup = PopupRepository::shown();
				return $popup ? barrio_wpgraphql_popup( $popup, I18n::resolve( $args['lang'] ?? null ) ) : null;
			},
		]
	);
}

/**
 * @param array<string, mixed> $popup Di PopupRepository.
 * @return array<string, mixed>
 */
function barrio_wpgraphql_popup( array $popup, string $lang ): array {
	$text  = I18n::pick( $popup['i18n'], $lang );
	$image = $popup['image_id'] ? get_post( $popup['image_id'] ) : null;
	// Gli altri shortcode li sa mostrare solo WordPress; quello del form lo separa il frontend.
	$form    = static fn( array $tags ): array => array_diff( $tags, [ 'barrio_form' ] );
	add_filter( 'strip_shortcodes_tagnames', $form );
	$content = strip_shortcodes( Module::content( $text ) );
	remove_filter( 'strip_shortcodes_tagnames', $form );
	return [
		'id'       => $popup['id'],
		'title'    => $text['title'] ?? '',
		'content'  => $content,
		'link'     => empty( $text['link_url'] ) ? null : [
			'url'    => $text['link_url'],
			'title'  => I18n::in( $lang, static fn(): string => Module::label( $text ) ),
			'target' => $popup['settings']['new_tab'] ? '_blank' : '_self',
		],
		// Un'immagine eliminata non c'è più, come nel sito.
		'image'    => $image && 'attachment' === $image->post_type ? [ 'node' => new Post( $image ) ] : null,
		'days'     => $popup['settings']['days'],
		'startsAt' => Period::iso( $popup['starts_at'] ),
		'endsAt'   => Period::iso( $popup['ends_at'] ),
	];
}
