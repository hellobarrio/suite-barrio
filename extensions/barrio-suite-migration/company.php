<?php
// Il passaggio di Info Azienda del prototipo (suite_barrio_company_info) in Dati aziendali, poi nome, contatti, partita
// IVA, indirizzo e profili dello schema del SEO del prototipo dove Info Azienda non li ha. Ogni dato riempie solo un
// campo vuoto, e dove i due valori sono diversi resta quello già scritto, con un avviso: si può rilanciare.

use BarrioSuite\Company\Module as Company;

defined( 'ABSPATH' ) || exit;

// Le chiavi di Info Azienda nel prototipo e i contatti per sede di un prototipo modificato sul server: le altre danno
// un avviso.
const BARRIO_MIGRATION_COMPANY_KEYS = [ 'ragione_sociale', 'sito_web', 'telefono', 'cellulare', 'email', 'email_privacy', 'partita_iva', 'codice_fiscale', 'rea', 'capitale_sociale', 'pec', 'codice_univoco', 'sede_via', 'sede_citta', 'sede_cap', 'sede_provincia', 'sede_google_maps', 'sede_telefoni', 'sede_email', 'sede_operativa_diversa', 'sede_op_via', 'sede_op_citta', 'sede_op_cap', 'sede_op_provincia', 'sede_op_google_maps', 'sede_op_telefoni', 'sede_op_email', 'facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'telegram', 'tiktok' ];

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
	barrio_migration_unknown_options( 'Company details', [ 'suite_barrio_company_' ], [ 'suite_barrio_company_info' ], $report['warnings'] );
	barrio_migration_unknown( 'Company details', $info, BARRIO_MIGRATION_COMPANY_KEYS, $report['warnings'] );
	$text  = static fn( string $key, int $max = 255 ): string => mb_substr( sanitize_text_field( (string) ( $info[ $key ] ?? '' ) ), 0, $max );
	$email = static function ( string $key ) use ( $info ): string {
		$email = sanitize_email( (string) ( $info[ $key ] ?? '' ) );
		return is_email( $email ) ? $email : '';
	};
	$url   = static function ( string $key ) use ( $info ): string {
		$url = esc_url_raw( trim( (string) ( $info[ $key ] ?? '' ) ), [ 'http', 'https' ] );
		return preg_match( '#^https?://\S+$#', $url ) && strlen( $url ) <= 2048 ? $url : '';
	};
	// I contatti per sede di un prototipo modificato sul server: liste di {label, value} o di soli valori, senza le righe vuote. Ciò che
	// non si capisce dà un avviso invece di sparire.
	$contacts = static function ( string $key, bool $is_email ) use ( $info, &$report ): array {
		$list = $info[ $key ] ?? [];
		$list = is_string( $list ) ? json_decode( $list, true ) : $list;
		$rows = [];
		foreach ( is_array( $list ) ? $list : [] as $row ) {
			$row   = is_scalar( $row ) ? [ 'value' => $row ] : (array) $row;
			$value = $is_email ? sanitize_email( (string) ( $row['value'] ?? '' ) ) : mb_substr( sanitize_text_field( (string) ( $row['value'] ?? '' ) ), 0, 64 );
			if ( '' !== $value && ( ! $is_email || is_email( $value ) ) ) {
				$rows[] = [
					'label' => mb_substr( sanitize_text_field( (string) ( $row['label'] ?? '' ) ), 0, 255 ),
					'value' => $value,
				];
			}
		}
		if ( ! $rows && ! empty( $info[ $key ] ) ) {
			$report['warnings'][] = "Company details, {$key}: Barrio Suite does not understand its value, it was not migrated. Add the contacts in Barrio → Company details.";
		}
		return $rows;
	};
	$counts   = [
		'data'     => 'company details',
		'found'    => 0,
		'migrated' => 0,
		'skipped'  => 0,
	];
	$fill     = static function ( string $what, string $value, string $current, bool $warn = true ) use ( &$counts, &$report ): bool {
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
	$company  = Company::settings();
	$texts    = [
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
	$place = static fn( string $prefix ): array => [
		'street'      => $text( "{$prefix}via" ),
		'postal_code' => $text( "{$prefix}cap" ),
		'locality'    => $text( "{$prefix}citta" ),
		'region'      => $text( "{$prefix}provincia" ),
	];
	/**
	 * Una sede del prototipo su una di Barrio Suite. Si confrontano in una riga: il prototipo non ha il paese. Il link
	 * alla mappa e i contatti solo sulla stessa sede.
	 */
	$office = static function ( array $current, string $prefix, string $what ) use ( $place, $fill, $url, $contacts ): array {
		/** @var array{street: string, postal_code: string, locality: string, region: string} $new */
		$new = $place( $prefix );
		/** @var array{street: string, postal_code: string, locality: string, region: string, country: string, maps_url: string, phones: array<mixed>, emails: array<mixed>} $current */
		if ( $fill( $what, Company::line( $new ), Company::line( $current ) ) ) {
			$current = $new + $current;
		}
		if ( Company::line( $current ) !== Company::line( $new ) ) {
			return $current;
		}
		$map = $url( "{$prefix}google_maps" );
		if ( $fill( "{$what} map", $map, $current['maps_url'] ) ) {
			$current['maps_url'] = $map;
		}
		foreach ( [ 'phones', 'emails' ] as $list ) {
			$found = $contacts( $prefix . ( 'phones' === $list ? 'telefoni' : 'email' ), 'emails' === $list );
			if ( $fill( "{$what} {$list}", implode( ', ', array_column( $found, 'value' ) ), implode( ', ', array_column( $current[ $list ], 'value' ) ) ) ) {
				$current[ $list ] = $found;
			}
		}
		return $current;
	};

	$company['address'] = $office( $company['address'], 'sede_', 'address' );
	// La sede operativa solo con "sede operativa diversa" acceso (spento, il prototipo teneva i campi pieni): va sulla
	// sede operativa con lo stesso indirizzo, o è la prima se non ce ne sono.
	$operating = Company::line( $place( 'sede_op_' ) );
	if ( ! empty( $info['sede_operativa_diversa'] ) && '' !== $operating ) {
		$lines = array_map( [ Company::class, 'line' ], $company['offices'] );
		$index = array_search( $operating, $lines, true );
		if ( false === $index && $company['offices'] ) {
			$fill( 'operating office', $operating, implode( '; ', $lines ) );
		} else {
			$index                        = (int) $index;
			$company['offices'][ $index ] = $office( $company['offices'][ $index ] ?? Company::OFFICE, 'sede_op_', 'operating office' );
		}
	}
	$profiles = array_values( array_filter( array_map( $url, [ 'facebook', 'instagram', 'linkedin', 'twitter', 'youtube', 'telegram', 'tiktok' ] ) ) );
	if ( $fill( 'profiles', implode( ', ', $profiles ), implode( ', ', $company['profiles'] ) ) ) {
		$company['profiles'] = $profiles;
	}

	// Poi lo schema del SEO del prototipo, solo dove Info Azienda non ha niente: su molti siti era ancora quello del
	// modello, mentre Info Azienda era ciò che il sito mostrava. Per questo la migrazione del SEO non lo scrive qui.
	$schema = array_filter( (array) get_option( 'suite_barrio_schema_settings', [] ) );
	$seo    = $schema ? barrio_migration_seo_company( $schema, $report['warnings'] ) : [];
	foreach ( [ 'name', 'phone', 'email', 'vat_id' ] as $key ) {
		if ( isset( $seo[ $key ] ) && $fill( "{$key} of the SEO schema", $seo[ $key ], $company[ $key ] ) ) {
			$company[ $key ] = $seo[ $key ];
		}
	}
	if ( isset( $seo['address'] ) ) {
		/** @var array{street: string, postal_code: string, locality: string, region: string, country: string} $schema_address */
		$schema_address = array_intersect_key( $seo['address'], array_flip( [ 'street', 'postal_code', 'locality', 'region', 'country' ] ) );
		$line           = Company::line( $schema_address );
		if ( $fill( 'address of the SEO schema', $line, Company::line( $company['address'] ) ) ) {
			$company['address'] = $schema_address + $company['address'];
		} elseif ( '' !== $line && Company::line( $company['address'] ) === $line && '' === $company['address']['country'] ) {
			// Il paese c'era solo nello schema.
			$company['address']['country'] = $schema_address['country'];
		}
	}
	if ( isset( $seo['profiles'] ) && $fill( 'profiles of the SEO schema', implode( ', ', $seo['profiles'] ), implode( ', ', $company['profiles'] ) ) ) {
		$company['profiles'] = $seo['profiles'];
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
	if ( '' !== $frontend ) {
		$report['notes'][] = "Frontend address (Barrio → Settings): {$frontend}.";
		if ( ! $dry_run ) {
			update_option( 'barrio_frontend', [ 'url' => $frontend ] + (array) get_option( 'barrio_frontend', [] ), true );
		}
	}
	if ( ! $dry_run ) {
		update_option( 'barrio_company', $company, true );
		// Da qui hbCompanyInfo risponde con i dati di Barrio Suite.
		do_action( 'barrio_company_changed' );
	}
	return null;
}
