<?php
// Le funzioni del prototipo che usa extensions/barrio-suite-migration/seo.php, come le scrive Suite Barrio.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- i nomi del prototipo.

function suite_barrio_tools_module_enabled( string $key ): bool {
	$options = get_option( 'suite_barrio_tools_modules', [] );
	return isset( $options[ $key ] ) ? (bool) $options[ $key ] : true;
}

// Al posto della chiamata al frontend Next.js, i tag in $GLOBALS['barrio_test_revalidated'].
function suite_barrio_invalidate_vercel_cache( int|string $tag = 0 ): void {
	$GLOBALS['barrio_test_revalidated'][] = $tag;
}
