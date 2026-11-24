<?php
// I dati aziendali in GraphQL: hbCompanyInfo, con il nome del prototipo e i campi in inglese, dagli stessi dati del
// blocco (Company\Module::data()). Una stringa vuota è null, come nel prototipo.

use BarrioSuite\Company\Module;

defined( 'ABSPATH' ) || exit;

// Le reti riconosciute dal dominio del profilo, con i sottodomini (it.linkedin.com, m.facebook.com).
const BARRIO_WPGRAPHQL_NETWORKS = [
	'facebook'  => '(facebook|fb)\.com|fb\.me',
	'instagram' => 'instagram\.com',
	'linkedin'  => 'linkedin\.com',
	'x'         => '(x|twitter)\.com',
	'youtube'   => 'youtube\.com|youtu\.be',
	'telegram'  => 't\.me|telegram\.(me|org)',
	'tiktok'    => 'tiktok\.com',
	'whatsapp'  => 'wa\.me|whatsapp\.com',
	'pinterest' => 'pinterest\.[a-z.]+|pin\.it',
	'threads'   => 'threads\.(net|com)',
];

function barrio_wpgraphql_company_boot(): void {
	// Priorità 9: anche il prototipo registra hbCompanyInfo.
	add_action( 'graphql_register_types', 'barrio_wpgraphql_register_company', 9 );
}

function barrio_wpgraphql_register_company(): void {
	register_graphql_object_type(
		'HbCompanyAddress',
		[
			'description' => 'An office of the company.',
			'fields'      => [
				'street'     => barrio_wpgraphql_string( 'Street and number.' ),
				'postalCode' => barrio_wpgraphql_string(),
				'locality'   => barrio_wpgraphql_string( 'The city.' ),
				'region'     => barrio_wpgraphql_string( 'The province or region, like NA.' ),
				'country'    => barrio_wpgraphql_string( 'ISO 3166-1 alpha-2, like IT.' ),
				'mapsUrl'    => barrio_wpgraphql_string( 'A link to the office on a map.' ),
			],
		]
	);
	register_graphql_object_type(
		'HbCompanyProfile',
		[
			'description' => 'A profile of the company on a social network or another site.',
			'fields'      => [
				'network' => barrio_wpgraphql_string( 'Recognized from the address: facebook, instagram, linkedin, x, youtube, telegram, tiktok, whatsapp, pinterest or threads. Null for other sites.' ),
				'url'     => barrio_wpgraphql_required( 'String' ),
			],
		]
	);
	register_graphql_object_type(
		'HbCompanyInfo',
		[
			'description' => 'The company details of Barrio → Company details.',
			'fields'      => [
				'name'               => barrio_wpgraphql_string( 'The name the company is known by.' ),
				'legalName'          => barrio_wpgraphql_string( 'The company name; the name when it is empty.' ),
				'phone'              => barrio_wpgraphql_string(),
				'mobile'             => barrio_wpgraphql_string(),
				'email'              => barrio_wpgraphql_string(),
				'privacyEmail'       => barrio_wpgraphql_string( 'The email for privacy requests.' ),
				'certifiedEmail'     => barrio_wpgraphql_string( 'The certified email (PEC).' ),
				'vatId'              => barrio_wpgraphql_string( 'The VAT number.' ),
				'taxCode'            => barrio_wpgraphql_string( 'The tax code (codice fiscale).' ),
				'registrationNumber' => barrio_wpgraphql_string( 'The REA number.' ),
				'shareCapital'       => barrio_wpgraphql_string(),
				'invoiceCode'        => barrio_wpgraphql_string( 'The SDI code for electronic invoices.' ),
				'address'            => [
					'type'        => 'HbCompanyAddress',
					'description' => 'The registered office. Null without an address.',
				],
				'operatingAddress'   => [
					'type'        => 'HbCompanyAddress',
					'description' => 'The operating office. Null when it is the registered office.',
				],
				'profiles'           => [
					'type'        => [ 'non_null' => [ 'list_of' => [ 'non_null' => 'HbCompanyProfile' ] ] ],
					'description' => 'In the order of Company details.',
				],
			],
		]
	);
	register_graphql_field(
		'RootQuery',
		'hbCompanyInfo',
		[
			'type'        => 'HbCompanyInfo',
			'description' => 'The company details, the same as the block on WordPress.',
			'resolve'     => static fn(): array => barrio_wpgraphql_company(),
		]
	);
}

/** @return array<string, mixed> */
function barrio_wpgraphql_company(): array {
	$text    = static fn( string $value ): ?string => '' === trim( $value ) ? null : trim( $value );
	$address = static fn( ?array $place ): ?array => null === $place ? null : [
		'street'     => $text( $place['street'] ),
		'postalCode' => $text( $place['postal_code'] ),
		'locality'   => $text( $place['locality'] ),
		'region'     => $text( $place['region'] ),
		'country'    => $text( $place['country'] ),
		'mapsUrl'    => $text( $place['maps_url'] ),
	];
	$data    = Module::data();
	return [
		'name'               => $text( $data['name'] ),
		'legalName'          => $text( $data['legal_name'] ),
		'phone'              => $text( $data['phone'] ),
		'mobile'             => $text( $data['mobile'] ),
		'email'              => $text( $data['email'] ),
		'privacyEmail'       => $text( $data['privacy_email'] ),
		'certifiedEmail'     => $text( $data['certified_email'] ),
		'vatId'              => $text( $data['vat_id'] ),
		'taxCode'            => $text( $data['tax_code'] ),
		'registrationNumber' => $text( $data['registration_number'] ),
		'shareCapital'       => $text( $data['share_capital'] ),
		'invoiceCode'        => $text( $data['invoice_code'] ),
		'address'            => $address( $data['address'] ),
		'operatingAddress'   => $address( $data['operating_address'] ),
		'profiles'           => array_map(
			static fn( string $url ): array => [
				'network' => barrio_wpgraphql_network( $url ),
				'url'     => $url,
			],
			Module::settings()['profiles']
		),
	];
}

function barrio_wpgraphql_network( string $url ): ?string {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	foreach ( BARRIO_WPGRAPHQL_NETWORKS as $network => $domains ) {
		if ( preg_match( "/(^|\.)({$domains})$/", $host ) ) {
			return $network;
		}
	}
	return null;
}
