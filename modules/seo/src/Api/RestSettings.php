<?php

namespace BarrioSuite\Seo\Api;

use BarrioSuite\Roles;
use BarrioSuite\Seo\Meta;
use BarrioSuite\Seo\Module;
use BarrioSuite\Seo\Schema;
use BarrioSuite\Seo\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// SEO → Impostazioni (manage_options, come le impostazioni dei form): le sezioni di barrio_seo, cosa
// può fare ogni ruolo e quanti indirizzi ha la sitemap.
final class RestSettings {

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'barrio/v1',
			'/seo/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'get' ],
					'permission_callback' => $admin,
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ self::class, 'save' ],
					'permission_callback' => $admin,
					'args'                => Settings::schema() + [ 'roles' => Roles::schema( Module::CAPS ) ],
				],
			]
		);
		// L'anteprima del JSON-LD dell'organizzazione mentre si compila, prima di salvare.
		register_rest_route(
			'barrio/v1',
			'/seo/settings/schema',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'schema' ],
				'permission_callback' => $admin,
				'args'                => [
					'organization' => [ 'required' => true ] + Settings::schema()['organization'],
					'description'  => [
						'type'    => 'string',
						'default' => '',
					],
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/seo/settings/sitemap',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'sitemap' ],
				'permission_callback' => $admin,
			]
		);
	}

	/**
	 * Quanti indirizzi ha la sitemap per tipo, tassonomia e autori, contati dai provider del core con i filtri
	 * del modulo: sono quelli che legge Google. Se il sito chiede ai motori di non indicizzarlo il core non
	 * registra i provider: si registrano per questa richiesta, per mostrare cosa conterrà.
	 * ponytail: legge tutti gli URL per contarli, una query per pagina della sitemap (2000 URL); su siti enormi
	 * si contano con le query dei provider.
	 */
	public static function sitemap(): WP_REST_Response {
		$server = wp_sitemaps_get_server();
		if ( ! $server->registry->get_providers() ) {
			$server->register_sitemaps();
		}
		$groups = [
			'posts'      => 'post_types',
			'taxonomies' => 'taxonomies',
			'users'      => 'archives',
		];
		$counts = array_fill_keys( $groups, [] );
		$total  = 0;
		foreach ( $server->registry->get_providers() as $name => $provider ) {
			// Gli autori non hanno sottotipi.
			foreach ( array_keys( $provider->get_object_subtypes() ?: [ '' => null ] ) as $subtype ) {
				$count = 0;
				$pages = $provider->get_max_num_pages( (string) $subtype );
				for ( $page = 1; $page <= $pages; $page++ ) {
					$count += count( $provider->get_url_list( $page, (string) $subtype ) );
				}
				$total += $count;
				if ( isset( $groups[ $name ] ) ) {
					$counts[ $groups[ $name ] ][ 'users' === $name ? 'author' : $subtype ] = $count;
				}
			}
		}
		return new WP_REST_Response(
			[
				'counts' => array_map( static fn( array $group ): object => (object) $group, $counts ),
				'total'  => $total,
			]
		);
	}

	public static function schema( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( Schema::organization( $request['organization'], Meta::url( home_url( '/' ) ), (string) $request['description'] ) );
	}

	public static function get(): WP_REST_Response {
		return new WP_REST_Response( Settings::get() + Roles::get( Module::CAPS ) );
	}

	public static function save( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_params();
		Settings::save( array_intersect_key( $params, Settings::schema() ) );
		if ( is_array( $request['roles'] ) ) {
			Roles::save( Module::CAPS, $request['roles'] );
		}
		do_action( 'barrio_seo_changed', 'settings' );
		return self::get();
	}
}
