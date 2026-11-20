<?php

defined( 'ABSPATH' ) || exit;

// L'opzione vuota in autoload: la legge ogni pagina del sito, e una che non esiste costa una query a richiesta.
return static function (): void {
	add_option( 'barrio_seo', [], '', true );
};
