<?php

use BarrioSuite\Database\Db;

defined( 'ABSPATH' ) || exit;

// Due colonne che nessuno leggeva: l'autore di una versione e l'utente di una registrazione, sempre
// NULL dal form pubblico (la REST non ha nonce). MySQL non ha DROP COLUMN IF EXISTS.
return static function ( wpdb $wpdb ): void {
	foreach ( [
		'form_versions' => 'created_by',
		'entries'       => 'user_id',
	] as $table => $column ) {
		$exists = Db::var( (string) $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', Db::table( $table ), $column ) );
		if ( '1' === $exists ) {
			Db::query( (string) $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', Db::table( $table ), $column ) );
		}
	}
};
