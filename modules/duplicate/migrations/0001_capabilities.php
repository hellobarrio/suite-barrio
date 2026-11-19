<?php

defined( 'ABSPATH' ) || exit;

// barrio_duplicate a chi poteva già duplicare: i ruoli che scrivono contenuti, anche quelli di altri plugin.
return static function (): void {
	foreach ( wp_roles()->role_objects as $role ) {
		if ( $role->has_cap( 'edit_posts' ) ) {
			$role->add_cap( 'barrio_duplicate' );
		}
	}
};
