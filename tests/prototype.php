<?php
// Le funzioni del prototipo che usa extensions/barrio-suite-migration/seo.php, come le scrive Suite Barrio.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- i nomi del prototipo.

function suite_barrio_tools_module_enabled( string $key ): bool {
	$options = get_option( 'suite_barrio_tools_modules', [] );
	return isset( $options[ $key ] ) ? (bool) $options[ $key ] : true;
}
