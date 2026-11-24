<?php
/**
 * Plugin Name:       Barrio Suite reCAPTCHA
 * Description:       Invisible reCAPTCHA Enterprise check on the Barrio Suite forms, against spam bots.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Text Domain:       barrio-suite-recaptcha
 * Requires Plugins:  barrio-suite
 */

// reCAPTCHA Enterprise v3 come nel prototipo, con il service account di Barrio Suite (Barrio → Impostazioni) al posto
// della chiave API: prima di ogni invio il form chiede a Google un token invisibile (recaptcha.js), che il server
// verifica con un assessment prima di salvare. Spento finché non si sceglie una chiave del sito. Senza una risposta di
// Google l'invio non passa, e gli amministratori vedono l'errore in admin. Usa solo l'API di Barrio Suite
// (docs/hooks.md). Un frontend headless deve inviare il token come _barrio_recaptcha.

use BarrioSuite\Google;

defined( 'ABSPATH' ) || exit;

const BARRIO_RECAPTCHA_ACTION = 'barrio_form';
// L'ultimo errore di Google, finché un invio non viene verificato di nuovo.
const BARRIO_RECAPTCHA_ERROR = 'barrio_recaptcha_error';

add_action(
	'barrio_forms_loaded',
	static function (): void {
		barrio_forms_register_integration(
			'recaptcha',
			[
				'label'           => 'reCAPTCHA',
				'description'     => __( 'An invisible check by Google reCAPTCHA Enterprise on every form, with the Google service account of Barrio → Settings. It is off until a site key is chosen.', 'barrio-suite-recaptcha' ),
				'global_settings' => [
					'site_key' => [
						'type'        => 'string',
						'title'       => __( 'Site key', 'barrio-suite-recaptcha' ),
						'description' => __( 'The score-based keys of the Google Cloud project of the service account. The service account needs the reCAPTCHA Enterprise Agent role, to check the submissions, and the reCAPTCHA Enterprise Viewer role, to list the keys; the key must allow the domains of the site.', 'barrio-suite-recaptcha' ),
						'options'     => '/barrio/v1/recaptcha/keys',
					],
				],
			]
		);
		add_action( 'rest_api_init', 'barrio_recaptcha_register' );
		add_action( 'barrio_forms_render', 'barrio_recaptcha_enqueue' );
		add_filter( 'barrio_forms_spam_check', 'barrio_recaptcha_check', 10, 2 );
		add_action( 'admin_notices', 'barrio_recaptcha_notice' );
	}
);

// La chiave del sito; null senza, o senza service account.
function barrio_recaptcha_site_key(): ?string {
	$site_key = trim( (string) ( barrio_forms_integration_settings( 'recaptcha' )['site_key'] ?? '' ) );
	return '' === $site_key || null === Google::account() ? null : $site_key;
}

function barrio_recaptcha_register(): void {
	register_rest_route(
		'barrio/v1',
		'/recaptcha/keys',
		[
			'methods'             => 'GET',
			'callback'            => 'barrio_recaptcha_keys',
			'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
		]
	);
}

/**
 * Le chiavi a punteggio (quelle di execute()) del progetto del service account, come { value, label }, per il menu
 * delle impostazioni.
 *
 * @return WP_REST_Response|WP_Error
 */
function barrio_recaptcha_keys(): WP_REST_Response|WP_Error {
	$account = Google::account();
	if ( null === $account ) {
		return new WP_Error( 'barrio_recaptcha_account', __( 'Add the Google service account in Barrio → Settings first.', 'barrio-suite-recaptcha' ), [ 'status' => 400 ] );
	}
	// ponytail: una pagina di 100 chiavi; con più serve pageToken.
	$body = barrio_recaptcha_request( 'GET', 'keys?pageSize=100' );
	if ( is_wp_error( $body ) ) {
		// Il 403 di Google è lungo e tecnico: basta dire quale ruolo manca, e dove. Con l'API spenta il messaggio di
		// Google lo dice già.
		$data    = (array) $body->get_error_data();
		$message = 403 === ( $data['code'] ?? 0 ) && ! in_array( 'SERVICE_DISABLED', (array) ( $data['reasons'] ?? [] ), true )
			/* translators: %s: ID of a Google Cloud project. */
			? sprintf( __( 'The service account cannot read the reCAPTCHA keys: give it the reCAPTCHA Enterprise Viewer role in the Google Cloud project %s.', 'barrio-suite-recaptcha' ), $account['project_id'] )
			: $body->get_error_message();
		return new WP_Error( 'barrio_recaptcha_google', $message, [ 'status' => 502 ] );
	}
	$options = [];
	foreach ( (array) ( $body['keys'] ?? [] ) as $key ) {
		$id = basename( (string) ( $key['name'] ?? '' ) );
		if ( 'SCORE' === ( $key['webSettings']['integrationType'] ?? '' ) && '' !== $id ) {
			$options[] = [
				'value' => $id,
				'label' => sprintf( '%s (%s)', sanitize_text_field( (string) ( $key['displayName'] ?? '' ) ), $id ),
			];
		}
	}
	return new WP_REST_Response( $options );
}

/**
 * Una richiesta all'API di reCAPTCHA Enterprise nel progetto del service account, con il suo token.
 *
 * @param array<string, mixed>|null $body
 * @return array<string, mixed>|WP_Error La risposta, o l'errore di Google (per esempio un ruolo che manca).
 */
function barrio_recaptcha_request( string $method, string $path, ?array $body = null ): array|WP_Error {
	$token = Google::token( 'https://www.googleapis.com/auth/cloud-platform' );
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$response = wp_remote_request(
		'https://recaptchaenterprise.googleapis.com/v1/projects/' . rawurlencode( (string) ( Google::account()['project_id'] ?? '' ) ) . "/{$path}",
		[
			'method'  => $method,
			'timeout' => 10,
			'headers' => [
				'Authorization' => "Bearer {$token}",
				'Content-Type'  => 'application/json',
			],
			'body'    => null === $body ? '' : (string) wp_json_encode( $body ),
		]
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$json = (array) json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 === $code ) {
		return $json;
	}
	// Google spiega in error.message, per esempio un permesso che manca; reason distingue l'API spenta.
	return new WP_Error(
		'barrio_recaptcha_google',
		trim( "HTTP {$code} " . ( $json['error']['message'] ?? '' ) ),
		[
			'code'    => $code,
			'reasons' => array_column( (array) ( $json['error']['details'] ?? [] ), 'reason' ),
		]
	);
}

// Dove si stampa un form: anche in ogni pagina del sito, se è nel testo del popup acceso.
function barrio_recaptcha_enqueue(): void {
	$site_key = barrio_recaptcha_site_key();
	if ( null === $site_key || wp_script_is( 'barrio-recaptcha' ) ) {
		return;
	}
	wp_enqueue_script( 'barrio-recaptcha', 'https://www.google.com/recaptcha/enterprise.js?render=' . rawurlencode( $site_key ), [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- la versione dello script la decide Google.
	wp_add_inline_script(
		'barrio-recaptcha',
		'window.barrioRecaptcha = ' . wp_json_encode(
			[
				'siteKey' => $site_key,
				'action'  => BARRIO_RECAPTCHA_ACTION,
			]
		) . ';',
		'before'
	);
	wp_enqueue_script_module( '@barrio/recaptcha', plugins_url( 'recaptcha.js', __FILE__ ), [ '@barrio/forms' ], '0.1.0' );
}

/**
 * Prima di salvare: un token valido, di questa azione, con un punteggio sufficiente.
 * Se Google non risponde o rifiuta le credenziali l'invio non passa: l'errore resta per gli
 * amministratori (barrio_recaptcha_notice()), perché con credenziali sbagliate nessun form si invia.
 *
 * @param mixed                $spam  Un WP_Error se un controllo precedente ha già rifiutato l'invio.
 * @param array<string, mixed> $input
 */
function barrio_recaptcha_check( mixed $spam, array $input ): mixed {
	$site_key = barrio_recaptcha_site_key();
	if ( $spam instanceof WP_Error || null === $site_key ) {
		return $spam;
	}
	$rejected = new WP_Error( 'barrio_spam', __( 'Your submission could not be accepted.', 'barrio-suite-recaptcha' ), [ 'status' => 400 ] );
	$token    = $input['_barrio_recaptcha'] ?? '';
	if ( ! is_string( $token ) || '' === $token ) {
		return $rejected;
	}
	// Senza l'IP del visitatore (privacy by default): Google lo vede già dallo script nella pagina.
	$body = barrio_recaptcha_request(
		'POST',
		'assessments',
		[
			'event' => [
				'token'          => $token,
				'siteKey'        => $site_key,
				'expectedAction' => BARRIO_RECAPTCHA_ACTION,
			],
		]
	);
	if ( is_wp_error( $body ) ) {
		set_transient( BARRIO_RECAPTCHA_ERROR, $body->get_error_message(), WEEK_IN_SECONDS );
		return new WP_Error( 'barrio_recaptcha_unavailable', __( 'The spam check is not available right now. Please try again in a few minutes.', 'barrio-suite-recaptcha' ), [ 'status' => 503 ] );
	}
	if ( false !== get_transient( BARRIO_RECAPTCHA_ERROR ) ) {
		delete_transient( BARRIO_RECAPTCHA_ERROR );
	}
	$valid = ! empty( $body['tokenProperties']['valid'] ) && BARRIO_RECAPTCHA_ACTION === ( $body['tokenProperties']['action'] ?? '' );
	$score = (float) ( $body['riskAnalysis']['score'] ?? 0 );
	return $valid && $score >= (float) apply_filters( 'barrio_recaptcha_threshold', 0.5 ) ? $spam : $rejected;
}

// In tutte le pagine admin: finché Google non risponde ogni invio dei form è rifiutato.
function barrio_recaptcha_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Anche con il service account illeggibile (salt diversi dopo un clone): il controllo è spento senza errori.
	if ( null === Google::account() && '' !== trim( (string) ( barrio_forms_integration_settings( 'recaptcha' )['site_key'] ?? '' ) ) ) {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'reCAPTCHA is off: there is no Google service account, or it can no longer be read (for example after the site was moved). Add it again in Barrio → Settings.', 'barrio-suite-recaptcha' )
		);
	}
	$error = get_transient( BARRIO_RECAPTCHA_ERROR );
	if ( false === $error ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: error from Google, for example "HTTP 403 Permission denied". */
				__( 'reCAPTCHA could not check the last form submission, so the form was not sent: %s. Check the service account in Barrio → Settings and the site key in Barrio → Forms → Settings.', 'barrio-suite-recaptcha' ),
				(string) $error
			)
		)
	);
}
