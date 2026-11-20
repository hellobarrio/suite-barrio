<?php

defined( 'ABSPATH' ) || exit;

// barrio_view_analytics agli amministratori e agli editor: chi gestisce il sito guarda le sue visite.
return static function (): void {
	foreach ( [ 'administrator', 'editor' ] as $role ) {
		get_role( $role )?->add_cap( 'barrio_view_analytics' );
	}
};
