<?php

defined( 'ABSPATH' ) || exit;

// barrio_manage_company ai soli amministratori: sono dati legali, non un contenuto del sito.
return static function (): void {
	get_role( 'administrator' )?->add_cap( 'barrio_manage_company' );
};
