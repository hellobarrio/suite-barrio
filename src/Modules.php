<?php

namespace BarrioSuite;

use BarrioSuite\Database\Migrator;
use WP_REST_Request;
use WP_REST_Response;

/**
 * I moduli si accendono e si spengono in Barrio → Impostazioni. Sono tutti accesi di default, anche
 * quelli che arriveranno con gli aggiornamenti: l'opzione salva solo quelli spenti. Un modulo spento
 * non registra niente e non migra il suo database, ma i suoi dati restano, come disattivando il plugin.
 */
final class Modules {

	public const ALL = [
		// Prima di tutti: Statistiche è la prima pagina del menu dopo la Bacheca, e la prima card della Bacheca.
		'analytics'   => Analytics\Module::class,
		'forms'       => Forms\Module::class,
		'seo'         => Seo\Module::class,
		'alt'         => Alt\Module::class,
		'popups'      => Popups\Module::class,
		'banners'     => Banners\Module::class,
		'hours'       => Hours\Module::class,
		// Prima di Prestazioni: l'ordine dei moduli è quello del menu, con la pagina Contenuti e le sue schede.
		'blocks'      => Blocks\Module::class,
		'ordering'    => Ordering\Module::class,
		'duplicate'   => Duplicate\Module::class,
		'performance' => Performance\Module::class,
	];

	public const OPTION = 'barrio_disabled_modules';

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

	public static function save( WP_REST_Request $request ): WP_REST_Response {
		foreach ( array_keys( self::ALL ) as $module ) {
			if ( is_bool( $request[ $module ] ) ) {
				self::set( $module, $request[ $module ] );
			}
		}
		return self::get();
	}

	/**
	 * Un modulo appena spento toglie i suoi eventi di WP-Cron; riacceso, migra e riparte dalla richiesta successiva.
	 *
	 * @param key-of<self::ALL> $module
	 */
	public static function set( string $module, bool $enabled ): void {
		if ( ! $enabled && self::is_enabled( $module ) ) {
			self::ALL[ $module ]::deactivate();
		}
		$disabled = array_diff( (array) get_option( self::OPTION, [] ), [ $module ] );
		update_option( self::OPTION, $enabled ? array_values( $disabled ) : [ ...$disabled, $module ] );
	}

	// Anche per un modulo spento: un'importazione scrive nelle sue tabelle prima di accenderlo.
	public static function migrator( string $module ): Migrator {
		return new Migrator( $module, dirname( __DIR__ ) . "/modules/{$module}/migrations" );
	}
}
