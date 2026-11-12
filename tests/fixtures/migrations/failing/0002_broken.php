<?php

return static function (): void {
	throw new RuntimeException( 'Table already exists' );
};
