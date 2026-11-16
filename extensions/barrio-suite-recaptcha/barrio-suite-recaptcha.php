<?php
/**
 * Plugin Name:       Barrio Suite reCAPTCHA
 * Description:       Invisible reCAPTCHA Enterprise check on the Barrio Suite forms, against spam bots.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/hellobarrio/suite-barrio
 * Text Domain:       barrio-suite-recaptcha
 */

// reCAPTCHA Enterprise v3 come nel prototipo, con le stesse credenziali: prima di ogni invio il form
// chiede a Google un token invisibile (recaptcha.js), che il server verifica con un assessment prima
// di salvare. Spento finché le tre impostazioni non sono complete. Senza una risposta di Google l'invio
// non passa, e gli amministratori vedono l'errore in admin. Usa solo l'API di Barrio Suite
// (docs/hooks.md). Un frontend headless deve inviare il token come _barrio_recaptcha.

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
				'description'     => __( 'An invisible check by Google reCAPTCHA Enterprise on every form. It is off until all three settings are filled in.', 'barrio-suite-recaptcha' ),
				'global_settings' => [
					'project_id' => [
						'type'  => 'string',
						'title' => __( 'Google Cloud project ID', 'barrio-suite-recaptcha' ),
					],
					'site_key'   => [
						'type'        => 'string',
						'title'       => __( 'Site key', 'barrio-suite-recaptcha' ),
						'description' => __( 'A score-based key that allows the domains of the site.', 'barrio-suite-recaptcha' ),
					],
					'api_key'    => [
						'format'      => 'password',
						'title'       => __( 'API key', 'barrio-suite-recaptcha' ),
						'description' => __( 'A Google Cloud API key that can use the reCAPTCHA Enterprise API.', 'barrio-suite-recaptcha' ),
					],
				],
			]
		);
		add_action( 'barrio_forms_render', 'barrio_recaptcha_enqueue' );
		add_filter( 'barrio_forms_spam_check', 'barrio_recaptcha_check', 10, 2 );
		add_action( 'admin_notices', 'barrio_recaptcha_notice' );
	}
);

/** @return array{project_id: string, site_key: string, api_key: string}|null Null finché ne manca una. */
function barrio_recaptcha_settings(): ?array {
	$saved    = barrio_forms_integration_settings( 'recaptcha' );
	$settings = [];
	foreach ( [ 'project_id', 'site_key', 'api_key' ] as $name ) {
		$settings[ $name ] = trim( (string) ( $saved[ $name ] ?? '' ) );
		if ( '' === $settings[ $name ] ) {
			return null;
		}
	}
	return $settings;
}

// Nelle sole pagine con un form.
function barrio_recaptcha_enqueue(): void {
	$settings = barrio_recaptcha_settings();
	if ( null === $settings ) {
		return;
	}
	wp_enqueue_script( 'barrio-recaptcha', 'https://www.google.com/recaptcha/enterprise.js?render=' . rawurlencode( $settings['site_key'] ), [], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- la versione dello script la decide Google.
	wp_add_inline_script(
		'barrio-recaptcha',
		'window.barrioRecaptcha = ' . wp_json_encode(
			[
				'siteKey' => $settings['site_key'],
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
	$settings = barrio_recaptcha_settings();
	if ( $spam instanceof WP_Error || null === $settings ) {
		return $spam;
	}
	$rejected = new WP_Error( 'barrio_spam', __( 'Your submission could not be accepted.', 'barrio-suite-recaptcha' ), [ 'status' => 400 ] );
	$token    = $input['_barrio_recaptcha'] ?? '';
	if ( ! is_string( $token ) || '' === $token ) {
		return $rejected;
	}
	// Senza l'IP del visitatore (privacy by default): Google lo vede già dallo script nella pagina.
	$response = wp_remote_post(
		'https://recaptchaenterprise.googleapis.com/v1/projects/' . rawurlencode( $settings['project_id'] ) . '/assessments?key=' . rawurlencode( $settings['api_key'] ),
		[
			'timeout' => 10,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => (string) wp_json_encode(
				[
					'event' => [
						'token'          => $token,
						'siteKey'        => $settings['site_key'],
						'expectedAction' => BARRIO_RECAPTCHA_ACTION,
					],
				]
			),
		]
	);
	$body     = (array) json_decode( wp_remote_retrieve_body( $response ), true );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		// Google spiega in error.message, per esempio una chiave API non valida.
		$error = is_wp_error( $response ) ? $response->get_error_message() : trim( 'HTTP ' . wp_remote_retrieve_response_code( $response ) . ' ' . ( $body['error']['message'] ?? '' ) );
		set_transient( BARRIO_RECAPTCHA_ERROR, $error, WEEK_IN_SECONDS );
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
	$error = get_transient( BARRIO_RECAPTCHA_ERROR );
	if ( false === $error || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: errore di Google, per esempio "HTTP 400 API key not valid". */
				__( 'reCAPTCHA could not check the last form submission, so the form was not sent: %s. Check the reCAPTCHA settings in Barrio → Forms → Settings.', 'barrio-suite-recaptcha' ),
				(string) $error
			)
		)
	);
}
