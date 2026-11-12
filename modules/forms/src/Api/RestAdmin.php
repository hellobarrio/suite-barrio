<?php

namespace BarrioSuite\Forms\Api;

use BarrioSuite\Forms\I18n;
use BarrioSuite\Forms\Notifications;
use BarrioSuite\Mail\Smtp;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Rotte dell'admin. Le impostazioni valgono per tutto il sito (l'SMTP anche per le email di
 * WordPress e degli altri plugin), quindi richiedono manage_options.
 */
final class RestAdmin {

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'barrio/v1',
			'/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ self::class, 'settings' ],
					'permission_callback' => $admin,
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ self::class, 'save_settings' ],
					'permission_callback' => $admin,
					'args'                => [
						'smtp'     => Smtp::schema(),
						'email'    => Notifications::schema(),
						// In sola scrittura: non torna mai indietro. Assente o vuota = resta quella salvata.
						'password' => [
							'type'      => 'string',
							'maxLength' => 255,
						],
					],
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/settings/test-email',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'test_email' ],
				'permission_callback' => $admin,
				// Assente = all'indirizzo di chi la chiede.
				'args'                => [
					'to' => [
						'type'   => 'string',
						'format' => 'email',
					],
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/entries/(?P<id>\d+)/notifications/(?P<notification>\d+)/resend',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'resend' ],
				'permission_callback' => static fn(): bool => current_user_can( 'barrio_manage_entries' ),
			]
		);
	}

	public static function settings(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'smtp'            => Smtp::settings(),
				'password_status' => Smtp::password_status(),
				'email'           => Notifications::settings(),
			]
		);
	}

	public static function save_settings( WP_REST_Request $request ): WP_REST_Response {
		if ( is_array( $request['smtp'] ) ) {
			Smtp::save( $request['smtp'] );
		}
		if ( is_string( $request['password'] ) && '' !== $request['password'] ) {
			Smtp::save_password( $request['password'] );
		}
		if ( is_array( $request['email'] ) ) {
			Notifications::save_settings( $request['email'] );
		}
		return self::settings();
	}

	// Con le impostazioni salvate e il template delle notifiche, così si vede anche come appaiono.
	public static function test_email( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$to    = is_string( $request['to'] ) ? $request['to'] : wp_get_current_user()->user_email;
		$error = Smtp::send(
			$to,
			/* translators: %s: nome del sito. */
			sprintf( __( 'Test email from %s', 'barrio-suite' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			Notifications::render(
				__( 'Test email', 'barrio-suite' ),
				wpautop( __( 'If you are reading this, the site can send email. Form notifications look like this message.', 'barrio-suite' ) ),
				I18n::languages()[0]
			)
		);
		if ( null !== $error ) {
			return new WP_Error( 'barrio_mail_failed', $error->get_error_message(), [ 'status' => 502 ] );
		}
		/* translators: %s: indirizzo email. */
		return new WP_REST_Response( [ 'message' => sprintf( __( 'Test email sent to %s.', 'barrio-suite' ), $to ) ] );
	}

	// L'esito, riuscito o no, finisce comunque nelle note della registrazione.
	public static function resend( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return Notifications::resend( (int) $request['id'], (int) $request['notification'] ) ?? new WP_REST_Response( [ 'sent' => true ] );
	}
}
