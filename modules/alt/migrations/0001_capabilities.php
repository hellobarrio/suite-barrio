<?php

defined( 'ABSPATH' ) || exit;

// barrio_generate_alt a chi poteva già generare: chi carica i media, anche nei ruoli di altri plugin.
return static function (): void {
	foreach ( wp_roles()->role_objects as $role ) {
		if ( $role->has_cap( 'upload_files' ) ) {
			$role->add_cap( 'barrio_generate_alt' );
		}
	}
};
