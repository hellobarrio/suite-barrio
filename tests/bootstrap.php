<?php

// Carica WordPress con Barrio Suite (e WPGraphQL se WP_GRAPHQL è impostata).

require dirname( __DIR__ ) . '/vendor/autoload.php'; // wp-phpunit imposta qui WP_PHPUNIT__DIR.

$barrio_tests_dir = (string) getenv( 'WP_PHPUNIT__DIR' );
require $barrio_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		if ( getenv( 'WP_GRAPHQL' ) ) {
			require WP_PLUGIN_DIR . '/wp-graphql/wp-graphql.php';
		}
		require dirname( __DIR__ ) . '/barrio-suite.php';
	}
);

require $barrio_tests_dir . '/includes/bootstrap.php';
