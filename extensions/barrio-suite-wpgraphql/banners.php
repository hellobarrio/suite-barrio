<?php
// Il banner con lo schema del prototipo e il periodo, che il prototipo non aveva: hbBanner, lo stesso del sito
// (BannerRepository::shown()). Mai i programmati: un frontend in cache li vede con la revalidation al loro inizio.

use BarrioSuite\Banners\BannerRepository;
use BarrioSuite\I18n;
use BarrioSuite\Period;

defined( 'ABSPATH' ) || exit;

function barrio_wpgraphql_banners_boot(): void {
	// Priorità 9, come per i form: anche il prototipo registra hbBanner con il suo modulo spento.
	add_action( 'graphql_register_types', 'barrio_wpgraphql_register_banners', 9 );
}

function barrio_wpgraphql_register_banners(): void {
	register_graphql_object_type(
		'HBBannerCta',
		[
			'description' => 'The link of the banner.',
			'fields'      => [
				'url'    => [ 'type' => 'String' ],
				'target' => [
					'type'        => 'String',
					'description' => '_self or _blank.',
				],
			],
		]
	);
	register_graphql_object_type(
		'HBBanner',
		[
			'description' => 'The banner of the site in the requested language.',
			'fields'      => [
				'id'       => [ 'type' => 'Int' ],
				'title'    => [
					'type'        => 'String',
					'description' => 'The text of the banner.',
				],
				'link'     => [
					'type'        => 'HBBannerCta',
					'description' => 'Null without a link.',
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
		'hbBanner',
		[
			'type'        => 'HBBanner',
			'description' => 'The newest banner that is on and within its period, the same as on WordPress; null if there is none.',
			'args'        => [
				'lang' => [
					'type'        => 'String',
					'description' => 'A language code, like en. Without it, or if it is not active, the main language.',
				],
			],
			'resolve'     => static function ( mixed $root, array $args ): ?array {
				$banner = BannerRepository::shown();
				return $banner ? barrio_wpgraphql_banner( $banner, I18n::resolve( $args['lang'] ?? null ) ) : null;
			},
		]
	);
}

/**
 * @param array<string, mixed> $banner Di BannerRepository.
 * @return array<string, mixed>
 */
function barrio_wpgraphql_banner( array $banner, string $lang ): array {
	$text = I18n::pick( $banner['i18n'], $lang );
	return [
		'id'       => $banner['id'],
		'title'    => $text['text'] ?? '',
		'link'     => empty( $text['link_url'] ) ? null : [
			'url'    => $text['link_url'],
			'target' => $banner['new_tab'] ? '_blank' : '_self',
		],
		'startsAt' => Period::iso( $banner['starts_at'] ),
		'endsAt'   => Period::iso( $banner['ends_at'] ),
	];
}
