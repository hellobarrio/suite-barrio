<?php
// Il banner in GraphQL: hbBanner, lo stesso del sito (BannerRepository::shown()). Mai i programmati: un frontend in
// cache li vede con la revalidation al loro inizio.

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
		'HbBannerCta',
		[
			'description' => 'The link of the banner.',
			'fields'      => [
				'url'    => barrio_wpgraphql_required( 'String' ),
				'target' => barrio_wpgraphql_required( 'String', '_self or _blank.' ),
			],
		]
	);
	register_graphql_object_type(
		'HbBanner',
		[
			'description' => 'The banner of the site in the requested language.',
			'fields'      => [
				'databaseId' => barrio_wpgraphql_required( 'Int' ),
				'text'       => barrio_wpgraphql_string( 'The text of the banner.' ),
				'link'       => [
					'type'        => 'HbBannerCta',
					'description' => 'Null without a link.',
				],
				'startsAt'   => barrio_wpgraphql_string( 'When it starts showing, ISO 8601 in UTC; null without a start. A cached frontend can check it in the browser.' ),
				'endsAt'     => barrio_wpgraphql_string( 'When it stops showing, ISO 8601 in UTC; null without an end.' ),
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbBanner',
		[
			'type'        => 'HbBanner',
			'description' => 'The newest banner that is on and within its period, the same as on WordPress; null if there is none.',
			'args'        => [
				'language' => barrio_wpgraphql_string( 'A language code, like en. Without it, or if it is not active, the main language.' ),
			],
			'resolve'     => static function ( mixed $root, array $args ): ?array {
				$banner = BannerRepository::shown();
				return $banner ? barrio_wpgraphql_banner( $banner, I18n::resolve( $args['language'] ?? null ) ) : null;
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
		'databaseId' => $banner['id'],
		'text'       => $text['text'] ?? '',
		'link'       => empty( $text['link_url'] ) ? null : [
			'url'    => $text['link_url'],
			'target' => $banner['new_tab'] ? '_blank' : '_self',
		],
		'startsAt'   => Period::iso( $banner['starts_at'] ),
		'endsAt'     => Period::iso( $banner['ends_at'] ),
	];
}
