<?php

return static function (): void {
	$GLOBALS['barrio_migrations_ran'][] = basename( __FILE__ );
};
