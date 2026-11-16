<?php

// Carica WordPress con Barrio Suite e le sue estensioni (e WPGraphQL se WP_GRAPHQL è impostata).

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
		// Inerti finché un test non simula i filtri di WPML (WpmlTest) o non le configura.
		require dirname( __DIR__ ) . '/extensions/barrio-suite-wpml/barrio-suite-wpml.php';
		require dirname( __DIR__ ) . '/extensions/barrio-suite-mailchimp/barrio-suite-mailchimp.php';
		require dirname( __DIR__ ) . '/extensions/barrio-suite-recaptcha/barrio-suite-recaptcha.php';
	}
);

require $barrio_tests_dir . '/includes/bootstrap.php';
