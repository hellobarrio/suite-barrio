<?php

// I valori di default sono quelli del container di test di wp-env; la CI passa i suoi
// con le stesse variabili d'ambiente.

define( 'ABSPATH', rtrim( getenv( 'WP_CORE_DIR' ) ?: '/var/www/html', '/' ) . '/' );
define( 'DB_NAME', getenv( 'WORDPRESS_DB_NAME' ) ?: 'tests-wordpress' );
define( 'DB_USER', getenv( 'WORDPRESS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) ?: 'password' );
define( 'DB_HOST', getenv( 'WORDPRESS_DB_HOST' ) ?: 'tests-mysql' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
