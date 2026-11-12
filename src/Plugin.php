<?php

namespace BarrioSuite;

use BarrioSuite\Database\Migrator;

final class Plugin {

	private const MODULES = [ 'forms' ];

	public static function activate(): void {
		$errors = Requirements::errors();
		if ( $errors ) {
			wp_die(
				esc_html( implode( ' ', $errors ) ),
				esc_html__( 'Barrio Suite cannot be activated', 'barrio-suite' ),
				[ 'back_link' => true ]
			);
		}
		self::boot();
	}

	public static function boot(): void {
		foreach ( self::MODULES as $module ) {
			$error = ( new Migrator( $module, dirname( __DIR__ ) . "/modules/{$module}/migrations" ) )->run();
			if ( $error ) {
				add_action(
					'admin_notices',
					static function () use ( $error ): void {
						if ( current_user_can( 'manage_options' ) ) {
							printf(
								'<div class="notice notice-error"><p>%s</p></div>',
								esc_html(
									sprintf(
										/* translators: %s: messaggio di errore del database. */
										__( 'Barrio Suite could not update its database tables: %s. It will try again on the next page load.', 'barrio-suite' ),
										$error->get_error_message()
									)
								)
							);
						}
					}
				);
			}
		}
	}
}
