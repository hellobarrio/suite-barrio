<?php

namespace BarrioSuite;

// PHP e WordPress li controlla WordPress dall'header del plugin.
// MySQL 8.0.16 è la prima versione che applica i vincoli CHECK.
final class Requirements {

	/** @return list<string> */
	public static function errors(): array {
		global $wpdb;
		return self::database_errors(
			(string) $wpdb->get_var( 'SELECT VERSION()' ),
			$wpdb->get_var( "SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'" )
		);
	}

	/**
	 * @param string $version Da SELECT VERSION(): "8.0.36", "8.0.36-28" (Percona), "10.5.23-MariaDB-log"…
	 * @return list<string>
	 */
	public static function database_errors( string $version, ?string $innodb ): array {
		$errors  = [];
		$minimum = str_contains( strtolower( $version ), 'mariadb' ) ? '10.5' : '8.0.16';

		if ( version_compare( (string) strtok( $version, '-' ), $minimum, '<' ) ) {
			$errors[] = sprintf(
				/* translators: %s: versione del server di database, per esempio "5.7.44". */
				__( 'Barrio Suite requires MySQL 8.0.16 or later, or MariaDB 10.5 or later. This site uses %s.', 'barrio-suite' ),
				$version
			);
		}
		if ( ! in_array( $innodb, [ 'YES', 'DEFAULT' ], true ) ) {
			$errors[] = __( 'Barrio Suite requires the InnoDB storage engine, which is not available on this database server.', 'barrio-suite' );
		}

		return $errors;
	}
}
