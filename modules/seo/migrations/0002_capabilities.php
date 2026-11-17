<?php

defined( 'ABSPATH' ) || exit;

// Capability del modulo: barrio_manage_seo (Contenuti e Redirect) agli amministratori, barrio_edit_seo
// (il pannello SEO di un contenuto) anche agli editor. In una migrazione come per i form.
return static function (): void {
	get_role( 'administrator' )?->add_cap( 'barrio_manage_seo' );
	foreach ( [ 'administrator', 'editor' ] as $role ) {
		get_role( $role )?->add_cap( 'barrio_edit_seo' );
	}
};
