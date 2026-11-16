<?php

namespace BarrioSuite;

use BarrioSuite\Database\Migrator;
use BarrioSuite\Mail\Smtp;

final class Plugin {

	private const MODULES = [ 'forms' => Forms\Module::class ];

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

	public static function deactivate(): void {
		Extensions::deactivate();
		foreach ( self::MODULES as $module_class ) {
			$module_class::deactivate();
		}
	}

	// Da uninstall.php: elimina i dati solo dove il sito lo ha scelto.
	public static function uninstall(): void {
		Uninstall::run( array_values( self::MODULES ) );
	}

	public static function boot(): void {
		// Per tutte le email del sito, anche con un modulo spento.
		Smtp::boot();
		// REST e aggiornamenti delle estensioni anche con lo schema non aggiornato; la pagina è del modulo Forms.
		Extensions::boot();
		Launch::boot();
		Uninstall::boot();
		foreach ( self::MODULES as $module => $module_class ) {
			$migrator = new Migrator( $module, dirname( __DIR__ ) . "/modules/{$module}/migrations" );
			$error    = $migrator->run();
			// Finché lo schema non è aggiornato il modulo resta spento: per esempio non accetta invii.
			if ( $migrator->is_current() ) {
				$module_class::boot();
			}
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
