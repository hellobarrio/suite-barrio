<?php
/**
 * Plugin Name:       Barrio Suite Mailchimp
 * Description:       Adds who fills in a Barrio Suite form to a Mailchimp audience.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/hellobarrio/barrio-suite
 * Text Domain:       barrio-suite-mailchimp
 */

// Le impostazioni del prototipo: audience (anche per lingua), campo email, merge field, tag e tag di
// consenso. Usa solo l'API di Barrio Suite (docs/hooks.md): senza Barrio Suite non fa niente.

use BarrioSuite\Forms\MergeTags;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Form;

defined( 'ABSPATH' ) || exit;

add_action(
	'barrio_forms_loaded',
	static function (): void {
		barrio_forms_register_integration(
			'mailchimp',
			[
				'label'           => 'Mailchimp',
				'description'     => __( 'Adds who fills in the form to a Mailchimp audience, or updates the contact if it is already there.', 'barrio-suite-mailchimp' ),
				'global_settings' => [
					'api_key' => [
						'format'      => 'password',
						'title'       => __( 'API key', 'barrio-suite-mailchimp' ),
						'description' => __( 'From Profile → Extras → API keys in Mailchimp. It ends with the data center, like -us21.', 'barrio-suite-mailchimp' ),
					],
				],
				'form_settings'   => [
					'list_id'       => [
						'type'         => 'string',
						'translatable' => true,
						'title'        => __( 'Audience ID', 'barrio-suite-mailchimp' ),
						'description'  => __( 'From Audience → Settings → Audience name and defaults. It can be different in every language.', 'barrio-suite-mailchimp' ),
					],
					'email_field'   => [
						'format' => 'field:email',
						'title'  => __( 'Email field', 'barrio-suite-mailchimp' ),
					],
					'merge_fields'  => [
						'format'      => 'field_map',
						'title'       => __( 'Merge fields', 'barrio-suite-mailchimp' ),
						'description' => __( 'The Mailchimp merge tag, like FNAME or PHONE, and the field that fills it. Choices are sent with their label.', 'barrio-suite-mailchimp' ),
					],
					'tags'          => [
						'type'         => 'string',
						'translatable' => true,
						'title'        => __( 'Tags', 'barrio-suite-mailchimp' ),
						'description'  => __( 'Added to every contact; separated by commas.', 'barrio-suite-mailchimp' ),
					],
					'consent_field' => [
						'format'      => 'field:consent,checkbox',
						'title'       => __( 'Marketing consent field', 'barrio-suite-mailchimp' ),
						'description' => __( 'Optional. The contact is added anyway; the consent tags only when this field is checked. To add only who consents, use the conditional logic.', 'barrio-suite-mailchimp' ),
					],
					'consent_tags'  => [
						'type'         => 'string',
						'translatable' => true,
						'title'        => __( 'Consent tags', 'barrio-suite-mailchimp' ),
						'description'  => __( 'Added only with the marketing consent, or always without a consent field; separated by commas.', 'barrio-suite-mailchimp' ),
					],
				],
				'process'         => 'barrio_mailchimp_subscribe',
			]
		);
	}
);

/**
 * Aggiunge o aggiorna il contatto (PUT sull'hash dell'email), poi i tag.
 *
 * @param array<string, mixed> $settings Del form, nella lingua della registrazione.
 * @param array<string, mixed> $account  Le impostazioni globali: la chiave API.
 */
function barrio_mailchimp_subscribe( Entry $entry, Form $form, array $settings, array $account ): true|WP_Error {
	$key  = trim( (string) ( $account['api_key'] ?? '' ) );
	$list = trim( (string) ( $settings['list_id'] ?? '' ) );
	// Il data center è dopo l'ultimo trattino della chiave: …-us21 → us21.api.mailchimp.com.
	$dc = substr( (string) strrchr( $key, '-' ), 1 );
	if ( ! preg_match( '/^[a-z]+[0-9]+$/', $dc ) ) {
		return new WP_Error( 'barrio_mailchimp_key', __( 'the API key is missing or not valid (Settings).', 'barrio-suite-mailchimp' ) );
	}
	if ( '' === $list ) {
		return new WP_Error( 'barrio_mailchimp_list', __( 'the audience ID is missing.', 'barrio-suite-mailchimp' ) );
	}
	$tags  = MergeTags::of( $entry, $form );
	$email = $tags->values[ (string) ( $settings['email_field'] ?? '' ) ][0] ?? '';
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'barrio_mailchimp_email', __( 'no email address: the email field is empty or not chosen.', 'barrio-suite-mailchimp' ) );
	}

	// I valori come nelle email: per le scelte l'etichetta, nella lingua di chi ha compilato.
	$merge = [];
	foreach ( (array) ( $settings['merge_fields'] ?? [] ) as [ $name, $field ] ) {
		if ( '' !== $name && isset( $tags->fields[ $field ] ) ) {
			$merge[ $name ] = $tags->fields[ $field ]['value'];
		}
	}
	$labels  = barrio_mailchimp_tags( $settings['tags'] ?? '' );
	$consent = (string) ( $settings['consent_field'] ?? '' );
	// Senza un campo di consenso i tag di consenso valgono sempre: è un form di sola iscrizione.
	if ( '' === $consent || ! empty( $tags->values[ $consent ] ) ) {
		$labels = [ ...$labels, ...barrio_mailchimp_tags( $settings['consent_tags'] ?? '' ) ];
	}

	$url    = "https://{$dc}.api.mailchimp.com/3.0/lists/" . rawurlencode( $list ) . '/members/' . md5( strtolower( $email ) );
	$member = [
		'email_address' => $email,
		// Solo per i contatti nuovi: chi si era disiscritto non torna iscritto.
		'status_if_new' => 'subscribed',
	];
	$error  = barrio_mailchimp_request( 'PUT', $url, $key, $merge ? $member + [ 'merge_fields' => $merge ] : $member );
	if ( null !== $error ) {
		return $error;
	}
	if ( $labels ) {
		$error = barrio_mailchimp_request(
			'POST',
			"{$url}/tags",
			$key,
			[
				'tags' => array_map(
					static fn( string $name ): array => [
						'name'   => $name,
						'status' => 'active',
					],
					array_values( array_unique( $labels ) )
				),
			]
		);
		if ( null !== $error ) {
			/* translators: %s: errore di Mailchimp. */
			return new WP_Error( 'barrio_mailchimp_tags', sprintf( __( 'the contact was added, but not the tags: %s', 'barrio-suite-mailchimp' ), $error->get_error_message() ) );
		}
	}
	return true;
}

/** @param array<string, mixed> $body */
function barrio_mailchimp_request( string $method, string $url, string $key, array $body ): ?WP_Error {
	$response = wp_remote_request(
		$url,
		[
			'method'  => $method,
			'timeout' => 10,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( "barrio:{$key}" ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic, come vuole Mailchimp.
				'Content-Type'  => 'application/json',
			],
			'body'    => (string) wp_json_encode( $body ),
		]
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code >= 200 && $code < 300 ) {
		return null;
	}
	// Mailchimp spiega l'errore in detail e, per i merge field, campo per campo in errors.
	$json    = (array) json_decode( wp_remote_retrieve_body( $response ), true );
	$details = [ (string) ( $json['detail'] ?? $json['title'] ?? '' ) ];
	foreach ( (array) ( $json['errors'] ?? [] ) as $error ) {
		$details[] = ( $error['field'] ?? '' ) . ': ' . ( $error['message'] ?? '' );
	}
	return new WP_Error( 'barrio_mailchimp_http', trim( "HTTP {$code} " . implode( ' ', $details ) ) );
}

/** @return list<string> */
function barrio_mailchimp_tags( mixed $text ): array {
	return array_values( array_filter( array_map( 'trim', explode( ',', is_string( $text ) ? $text : '' ) ) ) );
}
