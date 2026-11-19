<?php

namespace BarrioSuite\Banners;

use BarrioSuite\Admin;
use BarrioSuite\I18n;
use BarrioSuite\Period;
use BarrioSuite\Roles;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Banner (PLAN-MODULI.md §3): una riga di testo che scorre in cima a tutte le pagine (wp_body_open), il più recente tra
 * gli accesi nel loro periodo. Nel sito nessuna query (BannerRepository::live()), e CSS e JS solo con un banner.
 *
 * @phpstan-import-type Banner from BannerRepository
 */
final class Module {

	// Chi gestisce i banner (Permessi). Dalla migrazione 0002.
	public const CAP = 'barrio_manage_banners';

	/** @var Banner|null Quello della pagina, scelto su wp_enqueue_scripts. */
	private static ?array $shown = null;

	public static function boot(): void {
		add_action( 'init', [ self::class, 'register_assets' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'wp_body_open', [ self::class, 'render' ] );
		add_action( 'rest_api_init', [ self::class, 'register' ] );
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_filter( 'barrio_admin_data', [ self::class, 'admin_data' ] );
	}

	public static function deactivate(): void {}

	// Le tabelle e le opzioni le elimina il core (src/Uninstall.php); il transient non ha il prefisso barrio_.
	public static function uninstall(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( self::CAP );
		}
		delete_transient( BannerRepository::LIVE );
	}

	public static function can(): bool {
		return current_user_can( self::CAP );
	}

	public static function register_assets(): void {
		$assets = plugins_url( 'assets/frontend/', __DIR__ );
		wp_register_style( 'barrio-banners', "{$assets}banner.css", [], BARRIO_SUITE_VERSION );
		wp_register_script_module( '@barrio/banners', "{$assets}banner.js", [], BARRIO_SUITE_VERSION );
	}

	// Lo stile arriva nell'head: il banner in cima non si sposta.
	public static function enqueue(): void {
		self::$shown = BannerRepository::shown();
		if ( self::$shown ) {
			wp_enqueue_style( 'barrio-banners' );
			wp_enqueue_script_module( '@barrio/banners' );
		}
	}

	public static function render(): void {
		if ( self::$shown ) {
			echo self::html( self::$shown ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() codifica ogni valore.
		}
	}

	/**
	 * Il testo una volta sola: le copie della scritta che scorre le aggiunge il JavaScript, nascoste agli screen reader.
	 * Inizio e fine in secondi, per il JavaScript: con la cache di pagina l'HTML può essere vecchio.
	 *
	 * @param Banner $banner
	 */
	public static function html( array $banner ): string {
		$text   = I18n::pick( $banner['i18n'], I18n::current() );
		$inner  = '<span class="barrio-banner__track"><span class="barrio-banner__text">' . esc_html( $text['text'] ?? '' ) . '</span></span>';
		$body   = empty( $text['link_url'] )
			? "<div class=\"barrio-banner__body\">{$inner}</div>"
			: sprintf( '<a class="barrio-banner__body" href="%s"%s>%s</a>', esc_url( $text['link_url'] ), $banner['new_tab'] ? ' target="_blank" rel="noopener"' : '', $inner );
		$period = '';
		$dates  = [
			'start' => $banner['starts_at'],
			'end'   => $banner['ends_at'],
		];
		foreach ( $dates as $name => $utc ) {
			$period .= null === $utc ? '' : sprintf( ' data-%s="%d"', $name, strtotime( "{$utc} UTC" ) );
		}
		return sprintf( '<section id="barrio-banner" class="barrio-banner"%s aria-label="%s">%s</section>', $period, esc_attr__( 'Announcement', 'barrio-suite' ), $body );
	}

	public static function menu(): void {
		$hook = Admin::add_page( 'barrio-banners', __( 'Banners', 'barrio-suite' ), self::CAP );
		if ( $hook ) {
			// L'anteprima dell'editor, con lo stile del sito.
			add_action( "load-{$hook}", static fn() => wp_enqueue_style( 'barrio-banners' ) );
		}
	}

	/**
	 * In window.barrioAdmin.banners, a chi gestisce i banner: lo script del sito, che l'anteprima importa per far scorrere
	 * la scritta come nel sito. Senza, il JavaScript sa che il modulo è spento.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function admin_data( array $data ): array {
		return self::can() ? $data + [ 'banners' => [ 'script' => add_query_arg( 'ver', BARRIO_SUITE_VERSION, plugins_url( 'assets/frontend/banner.js', __DIR__ ) ) ] ] : $data;
	}

	public static function register(): void {
		$can = [ self::class, 'can' ];
		$id  = [ 'id' => [ 'type' => 'integer' ] ];
		register_rest_route(
			'barrio/v1',
			'/banners',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'index' ],
					'permission_callback' => $can,
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'create' ],
					'permission_callback' => $can,
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/banners/(?P<id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => static fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => self::response( (int) $request['id'] ),
					'permission_callback' => $can,
					'args'                => $id,
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'update' ],
					'permission_callback' => $can,
					'args'                => $id,
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ self::class, 'delete' ],
					'permission_callback' => $can,
					'args'                => $id,
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/banners/(?P<id>\d+)/duplicate',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'duplicate' ],
				'permission_callback' => $can,
				'args'                => $id,
			]
		);
		register_rest_route(
			'barrio/v1',
			'/banners/bulk',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'bulk' ],
				'permission_callback' => $can,
				'args'                => [
					'action' => [
						'type'     => 'string',
						'enum'     => [ 'enable', 'disable', 'delete' ],
						'required' => true,
					],
					'ids'    => [
						'type'     => 'array',
						'items'    => [ 'type' => 'integer' ],
						'required' => true,
					],
				],
			]
		);
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'barrio/v1',
			'/banners/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'settings' ],
					'permission_callback' => $admin,
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'save_settings' ],
					'permission_callback' => $admin,
					'args'                => [ 'roles' => Roles::schema( [ self::CAP ] ) + [ 'required' => true ] ],
				],
			]
		);
	}

	public static function index(): WP_REST_Response {
		return new WP_REST_Response( array_map( [ self::class, 'prepare' ], BannerRepository::all() ) );
	}

	public static function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$banner = self::input( $request );
		$id     = is_wp_error( $banner ) ? $banner : BannerRepository::save( null, $banner );
		return is_wp_error( $id ) ? $id : self::response( $id, 201 );
	}

	public static function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$banner = self::input( $request );
		$id     = is_wp_error( $banner ) ? $banner : BannerRepository::save( (int) $request['id'], $banner );
		return is_wp_error( $id ) ? $id : self::response( $id );
	}

	public static function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return BannerRepository::delete( (int) $request['id'] )
			? new WP_REST_Response( [ 'deleted' => true ] )
			: new WP_Error( 'barrio_banner_not_found', __( 'Banner not found.', 'barrio-suite' ), [ 'status' => 404 ] );
	}

	public static function duplicate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = BannerRepository::duplicate( (int) $request['id'] );
		return is_wp_error( $id ) ? $id : self::response( $id, 201 );
	}

	public static function bulk( WP_REST_Request $request ): WP_REST_Response {
		$ids = array_map( 'intval', (array) $request['ids'] );
		return new WP_REST_Response( [ 'count' => 'delete' === $request['action'] ? BannerRepository::delete( ...$ids ) : BannerRepository::set_enabled( 'enable' === $request['action'], ...$ids ) ] );
	}

	public static function settings(): WP_REST_Response {
		return new WP_REST_Response( Roles::get( [ self::CAP ] ) );
	}

	public static function save_settings( WP_REST_Request $request ): WP_REST_Response {
		Roles::save( [ self::CAP ], (array) $request['roles'] );
		return self::settings();
	}

	private static function response( int $id, int $status = 200 ): WP_REST_Response|WP_Error {
		$banner = BannerRepository::get( $id );
		return $banner
			? new WP_REST_Response( self::prepare( $banner ), $status )
			: new WP_Error( 'barrio_banner_not_found', __( 'Banner not found.', 'barrio-suite' ), [ 'status' => 404 ] );
	}

	/**
	 * @param Banner $banner
	 * @return array<string, mixed>
	 */
	private static function prepare( array $banner ): array {
		return [
			'id'         => $banner['id'],
			'is_enabled' => $banner['is_enabled'],
			'new_tab'    => $banner['new_tab'],
			'status'     => BannerRepository::status( $banner ),
			'starts_at'  => Period::local( $banner['starts_at'] ),
			'ends_at'    => Period::local( $banner['ends_at'] ),
			// Un oggetto anche vuoto: [] in JSON sarebbe una lista.
			'i18n'       => (object) $banner['i18n'],
		];
	}

	/** @return array{is_enabled: mixed, new_tab: mixed, starts_at: string|null, ends_at: string|null, i18n: mixed}|WP_Error */
	private static function input( WP_REST_Request $request ): array|WP_Error {
		$period = Period::input( $request );
		return is_wp_error( $period ) ? $period : [
			'is_enabled' => $request['is_enabled'],
			'new_tab'    => $request['new_tab'],
			'i18n'       => $request['i18n'],
		] + $period;
	}
}
