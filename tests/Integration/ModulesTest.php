<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Privacy;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Plugin;
use BarrioSuite\Seo\RedirectCode;
use BarrioSuite\Seo\Repository\RedirectRepository;
use Closure;
use ReflectionFunction;
use ReflectionMethod;
use WP_Hook;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Moduli accesi e spenti: uno spento non registra niente, ma i suoi dati restano.
final class ModulesTest extends WP_UnitTestCase {

	/** @var array<string, WP_Hook> */
	private array $hooks;

	/** @var array<string, callable> */
	private array $shortcodes;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->hooks      = array_map( static fn( WP_Hook $hook ): WP_Hook => clone $hook, $GLOBALS['wp_filter'] );
		$this->shortcodes = $GLOBALS['shortcode_tags'];
	}

	public function tear_down(): void {
		$GLOBALS['wp_filter']      = $this->hooks; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- gli hook di prima del test.
		$GLOBALS['shortcode_tags'] = $this->shortcodes; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		parent::tear_down();
	}

	public function test_every_module_is_on_by_default(): void {
		$this->assertFalse( get_option( 'barrio_disabled_modules' ) );
		$this->assertSame(
			[
				'forms'       => true,
				'seo'         => true,
				'duplicate'   => true,
				'performance' => true,
			],
			rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/modules' ) )->get_data()
		);
	}

	public function test_a_module_turned_off_registers_nothing_and_keeps_its_data(): void {
		$form = FormRepository::create( 'Contact', [], [], new FormVersion( [] ) );
		Privacy::schedule();
		$this->assertNotFalse( wp_next_scheduled( Privacy::CRON ) );

		$this->assertSame(
			[
				'forms'       => false,
				'seo'         => true,
				'duplicate'   => true,
				'performance' => true,
			],
			$this->put( [ 'forms' => false ] )->get_data()
		);
		$this->assertSame( [ 'forms' ], get_option( 'barrio_disabled_modules' ) );
		$this->assertFalse( wp_next_scheduled( Privacy::CRON ), 'Its WP-Cron events go.' );

		$this->boot();
		$this->assertSame( [], $this->callbacks( 'forms' ), 'No hook of the module, so no menu, blocks or extensions (barrio_forms_loaded).' );
		$routes = array_keys( rest_get_server()->get_routes( 'barrio/v1' ) );
		$this->assertSame( [], preg_grep( '#^/barrio/v1/(forms|entries|settings)#', $routes ) );
		$this->assertContains( '/barrio/v1/smtp', $routes, 'The email settings stay.' );
		$this->assertSame( '<p>Before  after</p>', trim( do_shortcode( '<p>Before [barrio_form id="' . $form->id . '"] after</p>' ) ) );

		$this->put( [ 'forms' => true ] );
		$this->boot();
		$this->assertNotSame( [], $this->callbacks( 'forms' ) );
		$this->assertSame( 'Contact', FormRepository::get( $form->id )?->title );
	}

	public function test_the_seo_module_turned_off_registers_nothing_and_keeps_its_data(): void {
		$post = self::factory()->post->create();
		update_post_meta( $post, '_barrio_seo_title', 'Title' );
		RedirectRepository::create( '/old', '/new', RedirectCode::MovedPermanently );

		$this->put( [ 'seo' => false ] );
		$this->boot();
		$this->assertSame( [], $this->callbacks( 'seo' ) );
		$this->assertSame( [], preg_grep( '#^/barrio/v1/seo#', array_keys( rest_get_server()->get_routes( 'barrio/v1' ) ) ) );
		$this->assertNotSame( [], $this->callbacks( 'forms' ), 'The other modules stay on.' );

		$this->put( [ 'seo' => true ] );
		$this->boot();
		$this->assertSame( 'Title', get_post_meta( $post, '_barrio_seo_title', true ) );
		$this->assertSame( '/new', RedirectRepository::find( '/old' )['target'] ?? null );
	}

	public function test_only_administrators_switch_modules(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/modules' ) )->get_status() );
		$this->assertSame( 403, $this->put( [ 'forms' => false ] )->get_status() );
		$this->assertSame( 400, $this->put( [ 'forms' => 'no' ] )->get_status() );
	}

	/** @param array<string, mixed> $body */
	private function put( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', '/barrio/v1/modules' );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	// Come all'inizio di una richiesta: senza gli hook di prima, anche quelli di WordPress.
	private function boot(): void {
		$GLOBALS['wp_filter']      = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		Plugin::boot();
	}

	/** @return list<string> Gli hook con una callback scritta nella cartella del modulo. */
	private function callbacks( string $module ): array {
		$found = [];
		foreach ( $GLOBALS['wp_filter'] as $name => $hook ) {
			foreach ( $hook->callbacks as $callbacks ) {
				foreach ( $callbacks as [ 'function' => $callback ] ) {
					$reflection = $callback instanceof Closure ? new ReflectionFunction( $callback ) : ( is_array( $callback ) ? new ReflectionMethod( $callback[0], $callback[1] ) : null );
					if ( $reflection && str_contains( (string) $reflection->getFileName(), "/modules/{$module}/" ) ) {
						$found[] = (string) $name;
					}
				}
			}
		}
		return array_values( array_unique( $found ) );
	}
}
