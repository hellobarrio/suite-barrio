<?php

// Capability del modulo: barrio_manage_forms agli amministratori, barrio_manage_entries anche agli
// editor. In una migrazione e non all'attivazione: così arrivano anche agli altri siti di un multisite
// e alle installazioni già attive. add_cap() non fa nulla se la capability c'è già.
return static function (): void {
	get_role( 'administrator' )?->add_cap( 'barrio_manage_forms' );
	foreach ( [ 'administrator', 'editor' ] as $role ) {
		get_role( $role )?->add_cap( 'barrio_manage_entries' );
	}
};
