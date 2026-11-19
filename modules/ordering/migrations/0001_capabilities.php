<?php

defined( 'ABSPATH' ) || exit;

// barrio_reorder a chi poteva già riordinare: chi modifica i contenuti degli altri o gestisce le categorie, anche
// nei ruoli di altri plugin.
return static function (): void {
	foreach ( wp_roles()->role_objects as $role ) {
		if ( $role->has_cap( 'edit_others_posts' ) || $role->has_cap( 'manage_categories' ) ) {
			$role->add_cap( 'barrio_reorder' );
		}
	}
};
