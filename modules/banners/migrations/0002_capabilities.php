<?php

defined( 'ABSPATH' ) || exit;

// barrio_manage_banners agli amministratori e agli editor: i banner sono un contenuto del sito.
return static function (): void {
	foreach ( [ 'administrator', 'editor' ] as $role ) {
		get_role( $role )?->add_cap( 'barrio_manage_banners' );
	}
};
