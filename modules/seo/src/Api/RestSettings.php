<?php

namespace BarrioSuite\Seo\Api;

use BarrioSuite\Roles;
use BarrioSuite\Seo\Module;
use BarrioSuite\Seo\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

// SEO → Impostazioni (manage_options, come le impostazioni dei form): le sezioni di barrio_seo e cosa
// può fare ogni ruolo.
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
		return self::get();
	}
}
