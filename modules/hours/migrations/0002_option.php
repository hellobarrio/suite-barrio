<?php

defined( 'ABSPATH' ) || exit;

// L'opzione vuota in autoload: la legge init a ogni richiesta, e una che non esiste costa una query a richiesta.
return static function (): void {
	add_option( 'barrio_hours', [], '', true );
};
