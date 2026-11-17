<?php

namespace BarrioSuite;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Messa online (Barrio → Bacheca): ciò che va configurato prima di pubblicare il sito. Ogni passaggio è
 * fatto o no secondo le impostazioni vere, non secondo una spunta, e si configura nella guida
 * (assets/src/launch). Ogni modulo aggiunge il suo passaggio con il filtro barrio_launch_steps.
 * Chi non vuole un passaggio lo salta: un passaggio saltato non blocca la messa online.
 */
final class Launch {

	private const SKIPPED = 'barrio_launch_skipped';

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
	}

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'barrio/v1',
			'/launch',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get' ],
					'permission_callback' => $admin,
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'save' ],
					'permission_callback' => $admin,
					'args'                => [
						'skipped'        => [
							'type'        => 'array',
							'items'       => [
								'type'    => 'string',
								'pattern' => '^[a-z_]+$',
							],
							'uniqueItems' => true,
						],
						// "Scoraggia i motori di ricerca" in Impostazioni → Lettura, al contrario.
						'search_engines' => [ 'type' => 'boolean' ],
					],
				],
			]
		);
	}

	/**
	 * Per passaggio: fatto o no. L'ordine lo decide la guida.
	 *
	 * @return array<string, bool>
	 */
	public static function steps(): array {
		return apply_filters(
			'barrio_launch_steps',
			[
				'timezone' => '' !== get_option( 'timezone_string' ),
				'search'   => '1' === (string) get_option( 'blog_public' ),
			]
		);
	}

	public static function get(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'steps'    => self::steps(),
				'skipped'  => array_values( (array) get_option( self::SKIPPED, [] ) ),
				'timezone' => (string) get_option( 'timezone_string' ),
			]
		);
	}

	public static function save( WP_REST_Request $request ): WP_REST_Response {
		if ( is_array( $request['skipped'] ) ) {
			update_option( self::SKIPPED, array_values( $request['skipped'] ), false );
		}
		if ( is_bool( $request['search_engines'] ) ) {
			update_option( 'blog_public', $request['search_engines'] ? '1' : '0' );
		}
		return self::get();
	}
}
