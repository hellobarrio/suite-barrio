<?php

namespace BarrioSuite;

use BarrioSuite\Mail\Smtp;

final class Plugin {

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
		foreach ( Modules::ALL as $module_class ) {
			$module_class::deactivate();
		}
	}

	// Da uninstall.php: elimina i dati solo dove il sito lo ha scelto.
	public static function uninstall(): void {
		Uninstall::run( array_values( Modules::ALL ) );
	}

	public static function boot(): void {
		// Per tutte le email del sito, anche con un modulo spento.
		Smtp::boot();
		Admin::boot();
		Modules::boot();
		Extensions::boot();
		Launch::boot();
		Uninstall::boot();
		foreach ( Modules::ALL as $module => $module_class ) {
			if ( ! Modules::is_enabled( $module ) ) {
				continue;
			}
			$migrator = Modules::migrator( $module );
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
										/* translators: %s: database error message. */
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
		// Con il modulo Forms spento uno shortcode di un form non resta come testo nella pagina.
		if ( ! Modules::is_enabled( 'forms' ) ) {
			add_shortcode( 'barrio_form', '__return_empty_string' );
		}
	}
}
