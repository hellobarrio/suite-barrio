<?php
/**
 * Plugin Name:       Barrio Suite HubSpot
 * Description:       Sends who fills in a Barrio Suite form to a HubSpot form, with the privacy consent and the subscription.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/hellobarrio/suite-barrio
 * Text Domain:       barrio-suite-hubspot
 * Domain Path:       /languages
 * Requires Plugins:  barrio-suite
 */

// Forms API v3 di HubSpot, che non vuole un token: basta l'ID dell'account e quello del form. Usa solo l'API
// di Barrio Suite (docs/hooks.md): senza Barrio Suite non fa niente.

use BarrioSuite\Forms\MergeTags;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Form;

defined( 'ABSPATH' ) || exit;

load_plugin_textdomain( 'barrio-suite-hubspot', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

add_action(
	'barrio_forms_loaded',
	static function (): void {
		barrio_forms_register_integration(
			'hubspot',
			[
				'label'           => 'HubSpot',
				'description'     => __( 'Sends who fills in the form to a HubSpot form, which creates the contact or updates it.', 'barrio-suite-hubspot' ),
				'global_settings' => [
					'portal_id' => [
						'type'        => 'string',
						'title'       => __( 'Account ID', 'barrio-suite-hubspot' ),
						'description' => __( 'The Hub ID, in the account menu of HubSpot or as portalId in the embed code of a form.', 'barrio-suite-hubspot' ),
					],
				],
				'form_settings'   => [
					'form_id'            => [
						'type'         => 'string',
						'translatable' => true,
						'title'        => __( 'Form ID', 'barrio-suite-hubspot' ),
						'description'  => __( 'The formId in the embed code of the HubSpot form. It can be different in every language.', 'barrio-suite-hubspot' ),
					],
					'fields'             => [
						'format'      => 'field_map',
						'title'       => __( 'Properties', 'barrio-suite-hubspot' ),
						'description' => __( 'The internal name of the HubSpot contact property, like email or firstname, and the field that fills it. Choices are sent with their value, separated by semicolons.', 'barrio-suite-hubspot' ),
					],
					'consent_field'      => [
						'format'      => 'field:consent,checkbox',
						'title'       => __( 'Privacy consent field', 'barrio-suite-hubspot' ),
						'description' => __( 'When it is checked, HubSpot records the consent to process the data, with the text of the field. Without it, HubSpot gets no consent and no subscription.', 'barrio-suite-hubspot' ),
					],
					'subscription_id'    => [
						'type'        => 'string',
						'title'       => __( 'Subscription type ID', 'barrio-suite-hubspot' ),
						'description' => __( 'The HubSpot subscription type, like the newsletter, the contact subscribes to.', 'barrio-suite-hubspot' ),
					],
					'subscription_field' => [
						'format'      => 'field:consent,checkbox',
						'title'       => __( 'Subscription consent field', 'barrio-suite-hubspot' ),
						'description' => __( 'The contact subscribes only when it is checked, with the text of the field. In a newsletter form it can be the privacy consent field.', 'barrio-suite-hubspot' ),
					],
				],
				'process'         => 'barrio_hubspot_submit',
			]
		);
	}
);

/**
 * @param array<string, mixed> $settings Del form, nella lingua della registrazione.
 * @param array<string, mixed> $account  Le impostazioni globali: l'ID dell'account.
 * @param array<string, mixed> $input    I valori inviati: un frontend headless manda il cookie di HubSpot come _barrio_hutk.
 */
function barrio_hubspot_submit( Entry $entry, Form $form, array $settings, array $account, array $input = [] ): true|WP_Error {
	$portal = trim( (string) ( $account['portal_id'] ?? '' ) );
	$guid   = trim( (string) ( $settings['form_id'] ?? '' ) );
	if ( '' === $portal ) {
		return new WP_Error( 'barrio_hubspot_portal', __( 'the account ID is missing (Settings).', 'barrio-suite-hubspot' ) );
	}
	if ( '' === $guid ) {
		return new WP_Error( 'barrio_hubspot_form', __( 'the form ID is missing.', 'barrio-suite-hubspot' ) );
	}
	$tags = MergeTags::of( $entry, $form );

	$fields = [];
	foreach ( (array) ( $settings['fields'] ?? [] ) as [ $name, $field ] ) {
		if ( '' !== $name && ! empty( $tags->values[ $field ] ) ) {
			$fields[] = [
				'objectTypeId' => '0-1',
				'name'         => $name,
				'value'        => implode( ';', $tags->values[ $field ] ),
			];
		}
	}
	$body = [ 'fields' => $fields ];

	// Il cookie lega l'invio alle visite tracciate di chi compila: dalla richiesta, o da un frontend headless.
	$hutk    = $input['_barrio_hutk'] ?? sanitize_key( wp_unslash( $_COOKIE['hubspotutk'] ?? '' ) );
	$context = array_filter(
		[
			'hutk'    => is_string( $hutk ) && preg_match( '/^[a-f0-9]{32}$/', $hutk ) ? $hutk : '',
			'pageUri' => (string) $entry->source_url,
		]
	);
	if ( $context ) {
		$body['context'] = $context;
	}

	$consent = barrio_hubspot_checked( $tags, $settings['consent_field'] ?? '' );
	if ( '' !== $consent ) {
		$type = trim( (string) ( $settings['subscription_id'] ?? '' ) );
		$text = barrio_hubspot_checked( $tags, $settings['subscription_field'] ?? '' );

		$body['legalConsentOptions']['consent'] = [
			'consentToProcess' => true,
			'text'             => $consent,
		];
		// Mai value false: chi non spunta non perde un'iscrizione che aveva già.
		if ( '' !== $type && '' !== $text ) {
			$body['legalConsentOptions']['consent']['communications'] = [
				[
					'value'              => true,
					'subscriptionTypeId' => (int) $type,
					'text'               => $text,
				],
			];
		}
	}

	$response = wp_remote_post(
		'https://api.hsforms.com/submissions/v3/integration/submit/' . rawurlencode( $portal ) . '/' . rawurlencode( $guid ),
		[
			'timeout' => 10,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => (string) wp_json_encode( $body ),
		]
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code >= 200 && $code < 300 ) {
		return true;
	}
	// HubSpot spiega l'errore campo per campo in errors, altrimenti in message.
	$json    = (array) json_decode( wp_remote_retrieve_body( $response ), true );
	$details = array_column( (array) ( $json['errors'] ?? [] ), 'message' ) ?: [ (string) ( $json['message'] ?? '' ) ];
	return new WP_Error( 'barrio_hubspot_http', trim( "HTTP {$code} " . implode( ' ', $details ) ) );
}

// Il testo che chi ha compilato ha spuntato, '' se non l'ha spuntato: l'etichetta del campo, o le scelte di
// una checkbox senza etichetta, come quelle dei form migrati dal prototipo.
function barrio_hubspot_checked( MergeTags $tags, mixed $field ): string {
	$filled = $tags->fields[ (string) $field ] ?? null;
	return null === $filled ? '' : ( '' !== $filled['label'] ? $filled['label'] : $filled['value'] );
}
