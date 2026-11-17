<?php

namespace BarrioSuite;

use WP_REST_Request;
use WP_REST_Response;

/**
 * I moduli si accendono e si spengono in Barrio → Impostazioni. Sono tutti accesi di default, anche
 * quelli che arriveranno con gli aggiornamenti: l'opzione salva solo quelli spenti. Un modulo spento
 * non registra niente e non migra il suo database, ma i suoi dati restano, come disattivando il plugin.
 */
final class Modules {

	public const ALL = [
		'forms' => Forms\Module::class,
		'seo'   => Seo\Module::class,
	];

	private const OPTION = 'barrio_disabled_modules';

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
	}

	public static function is_enabled( string $module ): bool {
		return isset( self::ALL[ $module ] ) && ! in_array( $module, (array) get_option( self::OPTION, [] ), true );
	}

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'barrio/v1',
			'/modules',
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
					// Un modulo assente resta com'è.
					'args'                => array_map( static fn(): array => [ 'type' => 'boolean' ], self::ALL ),
				],
			]
		);
	}

	public static function get(): WP_REST_Response {
		$modules = [];
		foreach ( array_keys( self::ALL ) as $module ) {
			$modules[ $module ] = self::is_enabled( $module );
		}
		return new WP_REST_Response( $modules );
	}

	// Un modulo appena spento toglie i suoi eventi di WP-Cron; riacceso, migra e riparte dalla richiesta successiva.
	public static function save( WP_REST_Request $request ): WP_REST_Response {
		$disabled = [];
		foreach ( self::ALL as $module => $module_class ) {
			$was = self::is_enabled( $module );
			if ( ! ( is_bool( $request[ $module ] ) ? $request[ $module ] : $was ) ) {
				$disabled[] = $module;
				if ( $was ) {
					$module_class::deactivate();
				}
			}
		}
		update_option( self::OPTION, $disabled );
		return self::get();
	}
}
