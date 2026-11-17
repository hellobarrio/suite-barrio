<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Forms\Notifications;
use BarrioSuite\Forms\Privacy;
use BarrioSuite\Mail\Smtp;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Messa online: ogni passaggio è fatto secondo le impostazioni vere, e i saltati restano saltati.
final class LaunchTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		update_option( 'timezone_string', '' );
		update_option( 'blog_public', '0' );
	}

	public function test_each_step_follows_the_real_settings(): void {
		$this->assertSame(
			[
				'timezone' => false,
				'search'   => false,
				'forms'    => false,
			],
			$this->get()['steps']
		);

		update_option( 'timezone_string', 'Europe/Rome' );
		update_option( 'blog_public', '1' );
		Smtp::save(
			[
				'host'       => 'smtp.example.com',
				'username'   => 'forms@example.com',
				'from_email' => 'forms@example.com',
			]
		);
		Notifications::save_settings( [ 'logo' => 'https://example.com/logo.png' ] );
		Privacy::save_settings( [ 'retention_days' => 730 ] );
		$this->assertFalse( $this->get()['steps']['forms'], 'With a username the password is needed.' );

		Smtp::save_password( 's3cret' );
		$steps = $this->get()['steps'];

		$this->assertSame( [], array_keys( array_filter( $steps, static fn( bool $done ): bool => ! $done ) ) );
		Privacy::save_settings( [ 'retention_days' => 0 ] );
		$this->assertFalse( $this->get()['steps']['forms'], 'Entries kept forever.' );
	}

	public function test_skipped_steps_and_search_engines_are_saved(): void {
		$data = $this->put(
			[
				'skipped'        => [ 'forms', 'search' ],
				'search_engines' => true,
			]
		)->get_data();

		$this->assertSame( [ 'forms', 'search' ], $data['skipped'] );
		$this->assertTrue( $data['steps']['search'] );
		$this->assertSame( '1', (string) get_option( 'blog_public' ) );
		$this->assertSame( 400, $this->put( [ 'skipped' => [ 'Spam!' ] ] )->get_status() );
	}

	public function test_only_administrators_see_the_launch(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/launch' ) )->get_status() );
		$this->assertSame( 403, $this->put( [ 'search_engines' => true ] )->get_status() );
	}

	/** @return array<string, mixed> */
	private function get(): array {
		return rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/launch' ) )->get_data();
	}

	/** @param array<string, mixed> $body */
	private function put( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', '/barrio/v1/launch' );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}
}
