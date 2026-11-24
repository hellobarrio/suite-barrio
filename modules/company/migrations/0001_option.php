<?php

defined( 'ABSPATH' ) || exit;

// L'opzione in autoload, con l'anagrafica che stava in SEO → Organizzazione: da qui si scrive solo in Dati aziendali.
// Una che non esiste costerebbe una query a ogni pagina.
return static function (): void {
	$seo          = (array) get_option( 'barrio_seo', [] );
	$organization = (array) ( $seo['organization'] ?? [] );
	$moved        = [ 'name', 'phone', 'email', 'vat_id', 'address', 'same_as' ];
	$company      = array_intersect_key( $organization, array_flip( $moved ) );
	if ( isset( $company['address'] ) ) {
		$company['address'] = (array) $company['address'] + [ 'maps_url' => '' ];
	}
	if ( isset( $company['same_as'] ) ) {
		$company['profiles'] = $company['same_as'];
		unset( $company['same_as'] );
	}
	// Dall'Organizzazione si tolgono solo se sono arrivati qui.
	if ( add_option( 'barrio_company', $company, '', true ) && $company ) {
		$seo['organization'] = array_diff_key( $organization, array_flip( $moved ) );
		update_option( 'barrio_seo', $seo );
	}
};
