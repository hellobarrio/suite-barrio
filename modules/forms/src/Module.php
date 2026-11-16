<?php

namespace BarrioSuite\Forms;

use BarrioSuite\Forms\Api\RestAdmin;
use BarrioSuite\Forms\Api\RestEntries;
use BarrioSuite\Forms\Api\RestForms;
use BarrioSuite\Forms\Api\RestPublic;
use BarrioSuite\Forms\Frontend\Render;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Forms\Submission\FileStore;
use BarrioSuite\Mail\Smtp;

final class Module {

	public static function boot(): void {
		add_action( 'deleted_user', [ self::class, 'forget_user' ] );
		add_action( 'init', [ Render::class, 'register' ] );
		add_action( 'rest_api_init', [ RestPublic::class, 'register' ] );
		add_action( 'rest_api_init', [ RestAdmin::class, 'register' ] );
		add_action( 'rest_api_init', [ RestForms::class, 'register' ] );
		add_action( 'rest_api_init', [ RestEntries::class, 'register' ] );
		add_filter( 'rest_pre_serve_request', [ RestEntries::class, 'serve' ], 10, 2 );
		add_action( 'admin_menu', [ Admin::class, 'menu' ] );
		add_action( 'barrio_forms_after_submission', [ Notifications::class, 'on_submission' ], 10, 2 );
		add_action( 'barrio_forms_after_submission', [ Integrations::class, 'on_submission' ], 20, 2 );
		add_filter( 'wp_privacy_personal_data_exporters', [ Privacy::class, 'exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ Privacy::class, 'erasers' ] );
		add_action( 'init', [ Privacy::class, 'schedule' ] );
		add_action( Privacy::CRON, [ Privacy::class, 'purge' ] );
		add_filter( 'barrio_launch_steps', [ self::class, 'launch_steps' ] );

		// Le estensioni si registrano qui. Su init e non subito: i nomi che passano sono tradotti, e
		// prima di init WordPress segnala le traduzioni caricate troppo presto.
		require_once dirname( __DIR__ ) . '/functions.php';
		add_action( 'init', static fn() => do_action( 'barrio_forms_loaded' ), 5 );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( Privacy::CRON );
	}

	// Disinstallando, se il sito lo ha scelto: tabelle e opzioni le elimina il core (src/Uninstall.php).
	public static function uninstall(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( RestAdmin::CAPS as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		// Senza accesso diretto ai file (FTP) i file delle registrazioni restano in uploads.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			$GLOBALS['wp_filesystem']->delete( FileStore::dir(), true );
		}
	}

	/**
	 * Messa online (src/Launch.php): un solo passaggio per il modulo, fatto quando le email partono
	 * dall'SMTP (senza utente il server non chiede la password), hanno il logo e le registrazioni
	 * hanno una durata. Contro lo spam bastano honeypot e limite per IP; reCAPTCHA è facoltativo.
	 *
	 * @param array<string, bool> $steps
	 * @return array<string, bool>
	 */
	public static function launch_steps( array $steps ): array {
		$smtp = Smtp::settings();
		return $steps + [
			'forms' => '' !== $smtp['host'] && '' !== $smtp['from_email']
				&& ( '' === $smtp['username'] || in_array( Smtp::password_status(), [ 'saved', 'constant' ], true ) )
				&& '' !== Notifications::settings()['logo']
				&& Privacy::settings()['retention_days'] > 0,
		];
	}

	// Niente FK verso le tabelle di WordPress (docs/database.md): i riferimenti agli utenti si azzerano qui.
	// ponytail: su multisite azzera solo il sito corrente; per gli altri siti serve un giro su get_sites().
	public static function forget_user( int $user_id ): void {
		FormRepository::forget_user( $user_id );
		EntryRepository::forget_user( $user_id );
	}
}
