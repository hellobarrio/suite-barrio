<?php

namespace BarrioSuite\Forms;

use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;

final class Module {

	public static function boot(): void {
		add_action( 'deleted_user', [ self::class, 'forget_user' ] );
	}

	// Niente FK verso le tabelle di WordPress (docs/database.md): i riferimenti agli utenti si azzerano qui.
	// ponytail: su multisite azzera solo il sito corrente; per gli altri siti serve un giro su get_sites().
	public static function forget_user( int $user_id ): void {
		FormRepository::forget_user( $user_id );
		EntryRepository::forget_user( $user_id );
	}
}
