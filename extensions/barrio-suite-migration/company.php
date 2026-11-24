<?php
// Il passaggio di Info Azienda del prototipo (suite_barrio_company_info) in Dati aziendali. La migrazione del SEO ha
// già messo in barrio_company nome, contatti, partita IVA, indirizzo e profili dello schema del prototipo: qui ogni
// dato riempie solo un campo vuoto, e dove i due valori sono diversi resta quello già scritto, con un avviso.

use BarrioSuite\Company\Module as Company;

defined( 'ABSPATH' ) || exit;

/** @param BarrioMigrationReport $report */
function barrio_migration_company_run( bool $dry_run, array &$report ): ?WP_Error {
	$info = array_filter( (array) get_option( 'suite_barrio_company_info', [] ) );
	if ( ! $info ) {
		return new WP_Error( 'barrio_migration_no_prototype', 'There are no company details of Suite Barrio in this database.' );
	}
	// L'opzione nasce dalla migrazione 0001 del modulo, che ci sposta l'anagrafica di SEO → Organizzazione.
	$error = $dry_run ? null : barrio_migration_tables( 'company' );
	if ( $error ) {
		return $error;
	}
	$text    = static fn( string $key, int $max = 255 ): string => mb_substr( sanitize_text_field( (string) ( $info[ $key ] ?? '' ) ), 0, $max );
	$email   = static function ( string $key ) use ( $info ): string {
		$email = sanitize_email( (string) ( $info[ $key ] ?? '' ) );
		return is_email( $email ) ? $email : '';
	};
	$url     = static function ( string $key ) use ( $info ): string {
		$url = esc_url_raw( trim( (string) ( $info[ $key ] ?? '' ) ), [ 'http', 'https' ] );
		return preg_match( '#^https?://\S+$#', $url ) && strlen( $url ) <= 2048 ? $url : '';
	};
	$counts  = [
		'data'     => 'company details',
		'found'    => 0,
		'migrated' => 0,
		'skipped'  => 0,
	];
	$fill    = static function ( string $what, string $value, string $current, bool $warn = true ) use ( &$counts, &$report ): bool {
		if ( '' === $value ) {
			return false;
		}
		++$counts['found'];
		if ( '' === $current ) {
			++$counts['migrated'];
			return true;
		}
		++$counts['skipped'];
		if ( $warn && $value !== $current ) {
			$report['warnings'][] = "Company details, {$what}: Barrio Suite has \"{$current}\", which stays; Suite Barrio had \"{$value}\".";
		}
		return false;
	};
	$company = Company::settings();
	$texts   = [
		'legal_name'          => $text( 'ragione_sociale' ),
		// Il prototipo aveva solo la ragione sociale: è il nome se non ce n'è un altro, senza avviso se è diverso.
		'name'                => $text( 'ragione_sociale' ),
		'phone'               => $text( 'telefono', 64 ),
		'mobile'              => $text( 'cellulare', 64 ),
		'email'               => $email( 'email' ),
		'privacy_email'       => $email( 'email_privacy' ),
		'certified_email'     => $email( 'pec' ),
		'vat_id'              => $text( 'partita_iva', 64 ),
		'tax_code'            => $text( 'codice_fiscale' ),
		'registration_number' => $text( 'rea' ),
		'share_capital'       => $text( 'capitale_sociale' ),
		'invoice_code'        => $text( 'codice_univoco' ),
	];
	foreach ( $texts as $key => $value ) {
		if ( $fill( $key, $value, $company[ $key ], 'name' !== $key ) ) {
			$company[ $key ] = $value;
		}
	}
	// La sede operativa solo con "sede operativa diversa" acceso: spento, il prototipo teneva i campi pieni.
	$offices = empty( $info['sede_operativa_diversa'] ) ? [ 'address' => 'sede_' ] : [
		'address'           => 'sede_',
		'operating_address' => 'sede_op_',
	];
	foreach ( $offices as $key => $prefix ) {
		$place = [
			'street'      => $text( "{$prefix}via" ),
			'postal_code' => $text( "{$prefix}cap" ),
			'locality'    => $text( "{$prefix}citta" ),
			'region'      => $text( "{$prefix}provincia" ),
		];
		// Le sedi si confrontano in una riga: il prototipo non ha il paese.
		if ( $fill( $key, Company::line( $place ), Company::line( $company[ $key ] ) ) ) {
			$company[ $key ] = array_replace( $company[ $key ], $place );
		}
		// Il link alla mappa solo sulla stessa sede.
		$map = $url( "{$prefix}google_maps" );
		if ( Company::line( $company[ $key ] ) === Company::line( $place ) && $fill( "{$key} map", $map, $company[ $key ]['maps_url'] ) ) {
			$company[ $key ]['maps_url'] = $map;
		}
	}
	$profiles = array_values( array_filter( array_map( $url, [ 'facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'telegram', 'tiktok' ] ) ) );
	if ( $fill( 'profiles', implode( ', ', $profiles ), implode( ', ', $company['profiles'] ) ) ) {
		$company['profiles'] = $profiles;
	}

	$valid = rest_validate_value_from_schema(
		$company,
		[
			'type'       => 'object',
			'properties' => Company::schema(),
		]
	);
	if ( is_wp_error( $valid ) ) {
		return new WP_Error( 'barrio_migration_company', 'The company details of Suite Barrio are not valid: ' . $valid->get_error_message() );
	}
	$report['counts'][] = $counts;

	// Il prototipo usava il sito web anche come indirizzo del frontend per la revalidation.
	$frontend = barrio_migration_frontend( (string) ( $info['sito_web'] ?? '' ) );
	$graphql  = (array) get_option( 'graphql_general_settings', [] );
	if ( '' !== $frontend && empty( $graphql['barrio_frontend_url'] ) ) {
		$report['notes'][] = "Frontend address (GraphQL → Settings): {$frontend}.";
		if ( ! $dry_run ) {
			update_option( 'graphql_general_settings', [ 'barrio_frontend_url' => $frontend ] + $graphql );
		}
	}
	if ( ! $dry_run ) {
		update_option( 'barrio_company', $company, true );
		// Da qui hbCompanyInfo risponde con i dati di Barrio Suite.
		barrio_migration_revalidate( 'wp:option' );
	}
	return null;
}
