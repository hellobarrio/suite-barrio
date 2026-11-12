<?php

namespace BarrioSuite\Forms;

use BarrioSuite\Forms\Api\RestAdmin;
use BarrioSuite\Forms\Api\RestPublic;
use BarrioSuite\Forms\Frontend\Render;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;

final class Module {

	public static function boot(): void {
		add_action( 'deleted_user', [ self::class, 'forget_user' ] );
		add_action( 'init', [ Render::class, 'register' ] );
		add_action( 'rest_api_init', [ RestPublic::class, 'register' ] );
		add_action( 'rest_api_init', [ RestAdmin::class, 'register' ] );
		add_action( 'admin_menu', [ Admin::class, 'menu' ] );
		add_action( 'barrio_forms_after_submission', [ Notifications::class, 'on_submission' ], 10, 2 );
	}

	// Niente FK verso le tabelle di WordPress (docs/database.md): i riferimenti agli utenti si azzerano qui.
	// ponytail: su multisite azzera solo il sito corrente; per gli altri siti serve un giro su get_sites().
	public static function forget_user( int $user_id ): void {
		FormRepository::forget_user( $user_id );
		EntryRepository::forget_user( $user_id );
	}
}
