<?php

namespace BarrioSuite\Forms\Api;

use BarrioSuite\Forms\Integrations;
use BarrioSuite\Forms\Notifications;
use BarrioSuite\Forms\Privacy;
use BarrioSuite\Roles;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Le impostazioni dei form (Form → Impostazioni), che richiedono manage_options: template delle email,
 * privacy, quelle globali delle integrazioni, con i segreti in sola scrittura, e cosa può fare ogni
 * ruolo. L'SMTP è del core (src/Mail/Smtp.php).
 */
final class RestAdmin {

	// Le capability del modulo, che si assegnano ai ruoli nei Permessi.
	public const CAPS = [ 'barrio_manage_forms', 'barrio_manage_entries' ];

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'barrio/v1',
			'/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'settings' ],
					'permission_callback' => $admin,
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ self::class, 'save_settings' ],
					'permission_callback' => $admin,
					'args'                => [
						'email'        => Notifications::schema(),
						'privacy'      => Privacy::schema(),
						'integrations' => Integrations::schema(),
						'roles'        => Roles::schema( self::CAPS ),
					],
				],
			]
		);
	}

	public static function settings(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'email'   => Notifications::settings(),
				'privacy' => Privacy::settings(),
			] + Integrations::view() + Roles::get( self::CAPS )
		);
	}

	public static function save_settings( WP_REST_Request $request ): WP_REST_Response {
		if ( is_array( $request['email'] ) ) {
			Notifications::save_settings( $request['email'] );
		}
		if ( is_array( $request['privacy'] ) ) {
			Privacy::save_settings( $request['privacy'] );
		}
		if ( is_array( $request['integrations'] ) ) {
			Integrations::save_settings( $request['integrations'] );
		}
		if ( is_array( $request['roles'] ) ) {
			Roles::save( self::CAPS, $request['roles'] );
		}
		return self::settings();
	}
}
