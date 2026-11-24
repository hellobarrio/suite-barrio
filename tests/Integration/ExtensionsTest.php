<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Extensions;
use ReflectionProperty;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use ZipArchive;

// Catalogo delle release GitHub, aggiornamenti in Bacheca e pagina Estensioni. GitHub è simulato su
// pre_http_request; l'installazione passa davvero dall'installatore di WordPress, con uno zip finto
// (barrio-suite-demo) al posto del download.
final class ExtensionsTest extends WP_UnitTestCase {

	private const DEMO = 'barrio-suite-demo/barrio-suite-demo.php';

	/** @var array<string, array<string, string>>|WP_Error Il catalogo che GitHub restituisce. */
	private array|WP_Error $catalog = [];

	/** @var list<string> */
	private array $requests = [];

	/** @var string La versione dello zip finto. */
	private string $zip_version = '0.1.0';

	public function set_up(): void {
		parent::set_up();
		// Il catalogo resta in memoria per tutta la richiesta: ogni test parte senza.
		( new ReflectionProperty( Extensions::class, 'catalog' ) )->setValue( null, null );
		delete_site_transient( 'barrio_extensions_catalog' );
		$this->catalog = [
			'barrio-suite'         => self::item( 'Barrio Suite', '0.1.0' ),
			'barrio-suite-example' => self::item( 'Barrio Suite Example', '0.2.0' ),
			'barrio-suite-demo'    => self::item( 'Barrio Suite Demo', '0.1.0' ),
		];
		add_filter(
			'pre_http_request',
			function ( mixed $pre, array $args, string $url ): array|WP_Error {
				// Dopo un'installazione WordPress chiede gli aggiornamenti a wordpress.org: niente rete nei test.
				if ( ! str_starts_with( $url, Extensions::REPO ) ) {
					return new WP_Error( 'http_request_blocked', 'No network in the tests.' );
				}
				$this->requests[] = $url;
				return $this->catalog instanceof WP_Error ? $this->catalog : [
					'headers'  => [],
					'body'     => (string) wp_json_encode( [ 'plugins' => $this->catalog ] ),
					'response' => [ 'code' => 200 ],
					'cookies'  => [],
				];
			},
			10,
			3
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		// In una rete i plugin li installa e li aggiorna solo il super admin.
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}
		// I test scrivono nella cartella dei plugin dell'installazione di prova.
		add_filter( 'filesystem_method', static fn(): string => 'direct' );
		add_filter( 'upgrader_pre_download', fn(): string => $this->zip() );
	}

	public function tear_down(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		$GLOBALS['wp_filesystem']->delete( WP_PLUGIN_DIR . '/barrio-suite-demo', true );
		wp_clean_plugins_cache();
		parent::tear_down();
	}

	public function test_every_plugin_points_to_the_repository_for_updates(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( [ "{$root}/barrio-suite.php", ...glob( "{$root}/extensions/*/barrio-suite-*.php" ) ] as $file ) {
			$this->assertSame( Extensions::REPO, get_file_data( $file, [ 'uri' => 'Update URI' ] )['uri'], $file );
		}
	}

	public function test_wordpress_finds_updates_of_our_plugins_in_the_catalog(): void {
		// Lo zip è sempre quello della release: un catalogo alterato non fa scaricare altro.
		$this->catalog['barrio-suite-example']['package'] = 'https://evil.example.com/x.zip';
		$update = static fn( string $uri, string $file ): mixed => apply_filters(
			'update_plugins_github.com', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- filtro di WordPress.
			false,
			[
				'UpdateURI' => $uri,
				'Version'   => '0.1.0',
			],
			$file,
			[]
		);

		$this->assertFalse( $update( 'https://github.com/someone/else', 'else/else.php' ) );
		$this->assertSame( [], $this->requests, 'Another plugin on GitHub does not load the catalog.' );

		$this->assertSame(
			[
				'slug'         => 'barrio-suite-example',
				'version'      => '0.2.0',
				'package'      => Extensions::REPO . '/releases/latest/download/barrio-suite-example.zip',
				'url'          => Extensions::REPO,
				'requires'     => '6.6',
				'requires_php' => '8.2',
			],
			$update( Extensions::REPO, 'barrio-suite-example/barrio-suite-example.php' )
		);
		$this->assertSame( '0.1.0', $update( Extensions::REPO, 'barrio-suite/barrio-suite.php' )['version'] );
		$this->assertFalse( $update( Extensions::REPO, 'barrio-suite-other/barrio-suite-other.php' ), 'Not in the catalog.' );
		// Un solo download per richiesta, e poi la copia dell'ultima ora.
		$this->assertSame( [ Extensions::REPO . '/releases/latest/download/catalog.json' ], $this->requests );
		$this->assertArrayHasKey( 'barrio-suite-example', get_site_transient( 'barrio_extensions_catalog' ) );
	}

	public function test_the_page_installs_updates_and_switches_an_extension(): void {
		$this->assertSame(
			[
				[ 'barrio-suite-demo', 'Barrio Suite Demo', '0.1.0', null, false ],
				[ 'barrio-suite-example', 'Barrio Suite Example', '0.2.0', null, false ],
			],
			$this->extensions( $this->request( 'GET' ) )
		);

		$this->assertSame( [ 'barrio-suite-demo', 'Barrio Suite Demo', '0.1.0', '0.1.0', true ], $this->extensions( $this->request( 'POST', 'install' ) )[0] );
		$this->assertTrue( is_plugin_active( self::DEMO ) );
		$this->assertSame( 409, $this->request( 'POST', 'install' )->get_status(), 'Already installed.' );

		$this->catalog['barrio-suite-demo'] = self::item( 'Barrio Suite Demo', '0.2.0' );
		$this->zip_version                  = '0.2.0';
		$this->request( 'GET', refresh: true );
		$backup = null;
		add_filter(
			'upgrader_pre_install',
			static function ( mixed $response, array $extra ) use ( &$backup ): mixed {
				$backup = $extra['temp_backup']['slug'] ?? null;
				return $response;
			},
			10,
			2
		);
		$this->assertSame( [ 'barrio-suite-demo', 'Barrio Suite Demo', '0.2.0', '0.2.0', true ], $this->extensions( $this->request( 'POST', 'update' ) )[0], 'Updated, and still active.' );
		$this->assertSame( 'barrio-suite-demo', $backup, 'WordPress keeps a copy of the old version to restore it if the update fails.' );

		$this->assertFalse( $this->extensions( $this->request( 'POST', 'deactivate' ) )[0][4] );
		$this->assertFalse( is_plugin_active( self::DEMO ) );
		$this->assertTrue( $this->extensions( $this->request( 'POST', 'activate' ) )[0][4] );
	}

	// Le impostazioni: Barrio Suite con la versione pubblicata, e gli aggiornamenti automatici di
	// WordPress per lei e per tutte le estensioni, anche quelle installate dopo.
	public function test_the_settings_page_shows_the_version_and_switches_automatic_updates(): void {
		// La suite di test di WordPress li spegne.
		add_filter( 'automatic_updater_disabled', '__return_false' );
		$suite = plugin_basename( dirname( __DIR__, 2 ) . '/barrio-suite.php' );
		update_site_option( 'auto_update_plugins', [ 'akismet/akismet.php' ] );
		$this->catalog['barrio-suite'] = self::item( 'Barrio Suite', '9.0.0' );

		$data = $this->request( 'GET' )->get_data();
		$this->assertSame(
			[
				'version'   => '9.0.0',
				'installed' => BARRIO_SUITE_VERSION,
			],
			$data['suite']
		);
		$this->assertFalse( $data['auto_update'] );
		$this->assertTrue( $data['can_auto_update'] );

		// Solo i plugin del test: nella cartella dei plugin del sito di prova può esserci un'estensione vera.
		$ours = static fn(): array => array_values( array_intersect( (array) get_site_option( 'auto_update_plugins' ), [ 'akismet/akismet.php', $suite, self::DEMO ] ) );
		$this->assertTrue( $this->auto_update( true )->get_data()['auto_update'] );
		$this->assertSame( [ 'akismet/akismet.php', $suite ], $ours() );

		$this->request( 'POST', 'install' );
		$this->assertSame( [ 'akismet/akismet.php', $suite, self::DEMO ], $ours() );

		$this->assertFalse( $this->auto_update( false )->get_data()['auto_update'] );
		$this->assertSame( [ 'akismet/akismet.php' ], get_site_option( 'auto_update_plugins' ), 'The other plugins keep theirs.' );

		add_filter( 'plugins_auto_update_enabled', '__return_false' );
		$this->assertFalse( $this->request( 'GET' )->get_data()['can_auto_update'] );
		$this->assertSame( 403, $this->auto_update( true )->get_status() );
	}

	// Messa online: il passaggio degli aggiornamenti automatici, per chi gestisce i plugin.
	public function test_the_launch_has_the_automatic_updates_step(): void {
		$steps = static fn(): array => rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/launch' ) )->get_data()['steps'];
		$this->assertFalse( $steps()['updates'] );
		update_site_option( 'auto_update_plugins', [ plugin_basename( dirname( __DIR__, 2 ) . '/barrio-suite.php' ) ] );
		$this->assertTrue( $steps()['updates'] );

		add_filter( 'user_has_cap', static fn( array $caps ): array => [ 'activate_plugins' => false ] + $caps );
		if ( is_multisite() ) {
			revoke_super_admin( get_current_user_id() );
		}
		$this->assertArrayNotHasKey( 'updates', $steps(), 'Like the administrator of a site of a multisite.' );
	}

	public function test_an_inactive_extension_can_be_deleted(): void {
		$this->request( 'POST', 'install' );
		$this->assertSame( 409, $this->request( 'POST', 'delete' )->get_status(), 'Active: it is deactivated first.' );

		$this->request( 'POST', 'deactivate' );

		$this->assertSame( [ 'barrio-suite-demo', 'Barrio Suite Demo', '0.1.0', null, false ], $this->extensions( $this->request( 'POST', 'delete' ) )[0] );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/barrio-suite-demo' );
	}

	public function test_our_plugins_update_by_themselves_only_at_night(): void {
		add_filter( 'automatic_updater_disabled', '__return_false' );
		// wordpress.org, a cui WordPress chiede gli aggiornamenti degli altri plugin prima dei nostri.
		add_filter(
			'pre_http_request',
			static fn( mixed $pre, array $args, string $url ): mixed => str_contains( $url, 'api.wordpress.org/plugins/update-check' ) ? [
				'headers'  => [],
				'body'     => '{"plugins":[],"translations":[],"no_update":[]}',
				'response' => [ 'code' => 200 ],
				'cookies'  => [],
			] : $pre,
			20,
			3
		);
		$this->request( 'POST', 'install' );
		// Attiva, WordPress la proverebbe con una richiesta al sito dopo l'aggiornamento.
		$this->request( 'POST', 'deactivate' );
		$this->catalog['barrio-suite-demo'] = self::item( 'Barrio Suite Demo', '0.2.0' );
		$this->zip_version                  = '0.2.0';

		Extensions::nightly();
		$this->assertSame( '0.1.0', $this->extensions( $this->request( 'GET' ) )[0][3], 'Automatic updates are off.' );

		$this->auto_update( true );
		reset_phpmailer_instance();
		Extensions::nightly();
		$this->assertSame( '0.2.0', $this->extensions( $this->request( 'GET' ) )[0][3] );
		$this->assertStringContainsString( 'Barrio Suite Demo', tests_retrieve_phpmailer_instance()->get_sent()->body, 'The email of WordPress about automatic updates.' );

		// Di giorno, nel giro di WP-Cron di WordPress, no; fuori da WP-Cron il filtro non cambia niente.
		$ours = (object) [ 'id' => Extensions::REPO ];
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->assertFalse( apply_filters( 'auto_update_plugin', true, $ours ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WordPress.
		$this->assertTrue( apply_filters( 'auto_update_plugin', true, (object) [ 'id' => 'w.org/plugins/akismet' ] ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WordPress.
		remove_filter( 'wp_doing_cron', '__return_true' );
		$this->assertNull( apply_filters( 'auto_update_plugin', null, $ours ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WordPress.
	}

	public function test_the_nightly_update_is_at_midnight_of_the_site(): void {
		update_option( 'timezone_string', 'Europe/Rome' );
		wp_clear_scheduled_hook( 'barrio_nightly_update' );

		Extensions::schedule();

		$next = wp_next_scheduled( 'barrio_nightly_update' );
		$this->assertSame( '00:00', wp_date( 'H:i', $next ) );
		$this->assertLessThanOrEqual( DAY_IN_SECONDS, $next - time() );
	}

	public function test_without_the_catalog_the_installed_extensions_are_still_listed(): void {
		$this->request( 'POST', 'install' );
		( new ReflectionProperty( Extensions::class, 'catalog' ) )->setValue( null, null );
		$this->catalog = new WP_Error( 'http_request_failed', 'cURL error 6' );

		$response = $this->request( 'GET', refresh: true );

		$this->assertStringContainsString( 'cURL error 6', $response->get_data()['error'] );
		$this->assertSame( [ [ 'barrio-suite-demo', 'Barrio Suite Demo', null, '0.1.0', true ] ], $this->extensions( $response ) );
		$this->assertSame( 502, $this->request( 'POST', 'update' )->get_status() );
	}

	public function test_only_who_manages_plugins_can_use_the_page(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->assertSame( 403, $this->request( 'GET' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', 'install' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', 'delete' )->get_status() );
		$this->assertDirectoryDoesNotExist( WP_PLUGIN_DIR . '/barrio-suite-demo' );

		// Come in WordPress: chi può solo installare non attiva. In una rete installa solo il super admin, che può tutto.
		if ( is_multisite() ) {
			return;
		}
		wp_get_current_user()->add_cap( 'install_plugins' );
		$this->assertSame( 200, $this->request( 'POST', 'install' )->get_status() );
		$this->assertFileExists( WP_PLUGIN_DIR . '/' . self::DEMO );
		$this->assertFalse( is_plugin_active( self::DEMO ) );
	}

	/** @return array<string, string> */
	private static function item( string $name, string $version ): array {
		return [
			'name'         => $name,
			'description'  => "{$name} for the tests.",
			'version'      => $version,
			'requires'     => '6.6',
			'requires_php' => '8.2',
		];
	}

	// Uno zip nuovo a ogni download: WordPress lo elimina dopo l'installazione.
	private function zip(): string {
		$zip  = new ZipArchive();
		$path = wp_tempnam( 'barrio-suite-demo.zip' );
		$zip->open( $path, ZipArchive::OVERWRITE );
		$zip->addFromString( self::DEMO, "<?php\n/**\n * Plugin Name: Barrio Suite Demo\n * Version: {$this->zip_version}\n * Update URI: " . Extensions::REPO . "\n */\n" );
		$zip->close();
		return $path;
	}

	private function request( string $method, ?string $action = null, bool $refresh = false ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/barrio/v1/extensions' . ( $action ? '/barrio-suite-demo' : '' ) );
		$request->set_param( 'action', $action );
		$request->set_param( 'refresh', $refresh );
		return rest_do_request( $request );
	}

	private function auto_update( bool $on ): WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', '/barrio/v1/extensions' );
		$request->set_param( 'auto_update', $on );
		return rest_do_request( $request );
	}

	/** @return list<array{string, string, ?string, ?string, bool}> slug, nome, versione pubblicata, installata, attiva. */
	private function extensions( WP_REST_Response $response ): array {
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		// Solo quelle del test: nella cartella dei plugin del sito di prova può esserci un'estensione vera.
		$ours = array_filter( $response->get_data()['extensions'], static fn( array $x ): bool => in_array( $x['slug'], [ 'barrio-suite-demo', 'barrio-suite-example' ], true ) );
		return array_values( array_map( static fn( array $x ): array => [ $x['slug'], $x['name'], $x['version'], $x['installed'], $x['active'] ], $ours ) );
	}
}
