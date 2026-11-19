<?php

namespace BarrioSuite\Popups;

use BarrioSuite\Admin;
use BarrioSuite\Forms\Frontend\Render;
use BarrioSuite\I18n;
use BarrioSuite\Period;
use BarrioSuite\Roles;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Popup (PLAN-MODULI.md §2): un <dialog> modale in wp_footer, in tutte le pagine, il più recente tra gli accesi nel loro
 * periodo. Nel sito nessuna query (PopupRepository::live()), e CSS e JS solo con un popup.
 *
 * @phpstan-import-type Popup from PopupRepository
 */
final class Module {

	// Chi gestisce i popup (Permessi). Dalla migrazione 0002.
	public const CAP = 'barrio_manage_popups';

	// L'HTML del popup, scritto su wp_enqueue_scripts: un form nel testo carica lì i suoi asset.
	private static string $html = '';

	public static function boot(): void {
		add_action( 'init', [ self::class, 'register_assets' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'wp_footer', [ self::class, 'render' ] );
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
		delete_transient( PopupRepository::LIVE );
	}

	public static function can(): bool {
		return current_user_can( self::CAP );
	}

	public static function register_assets(): void {
		$assets = plugins_url( 'assets/frontend/', __DIR__ );
		wp_register_style( 'barrio-popups', "{$assets}popup.css", [], BARRIO_SUITE_VERSION );
		wp_register_script_module( '@barrio/popups', "{$assets}popup.js", [], BARRIO_SUITE_VERSION );
	}

	public static function enqueue(): void {
		$popup      = PopupRepository::shown();
		self::$html = $popup ? self::html( $popup ) : '';
		if ( $popup ) {
			wp_enqueue_style( 'barrio-popups' );
			wp_enqueue_script_module( '@barrio/popups' );
		}
	}

	public static function render(): void {
		echo self::$html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() codifica ogni valore.
	}

	/**
	 * Chiuso finché il JavaScript non lo apre; $open per l'anteprima, che non ha JavaScript. Le regole per il browser:
	 * la chiave della chiusura in localStorage, inizio e fine in secondi, i giorni, l'apertura.
	 *
	 * @param Popup $popup
	 */
	public static function html( array $popup, bool $open = false ): string {
		$settings = $popup['settings'];
		$text     = I18n::pick( $popup['i18n'], I18n::current() );
		$id       = "barrio-popup-{$popup['id']}";
		$time     = static fn( ?string $utc ): ?int => null === $utc ? null : (int) strtotime( "{$utc} UTC" );
		$rules    = [
			'key'     => "barrio_popups_{$popup['id']}",
			'start'   => $time( $popup['starts_at'] ),
			'end'     => $time( $popup['ends_at'] ),
			'days'    => $settings['days'],
			'trigger' => $settings['trigger'],
		];
		// L'editor mette lo shortcode del form in un paragrafo, che lo lascerebbe vuoto sotto il form.
		$content = shortcode_unautop( (string) ( $text['content'] ?? '' ) );
		// Senza il modulo Form lo shortcode non stampa niente, invece del suo testo.
		$content = shortcode_exists( 'barrio_form' ) ? $content : (string) preg_replace( '/' . get_shortcode_regex( [ 'barrio_form' ] ) . '/', '', $content );
		$button  = empty( $text['link_url'] ) ? '' : sprintf(
			'<a class="barrio-popup__button" href="%s"%s>%s</a>',
			esc_url( $text['link_url'] ),
			$settings['new_tab'] ? ' target="_blank" rel="noopener"' : '',
			esc_html( $text['button_label'] ?? __( 'Learn more', 'barrio-suite' ) )
		);
		return sprintf(
			'<dialog id="%1$s" class="barrio-popup" data-barrio-popup-rules="%2$s" aria-labelledby="%1$s-title"%3$s><div class="barrio-popup__box">'
			. '<button type="button" class="barrio-popup__close" aria-label="%4$s"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>'
			. '%5$s<div class="barrio-popup__body"><h2 id="%1$s-title" class="barrio-popup__title">%6$s</h2>%7$s%8$s</div></div></dialog>',
			esc_attr( $id ),
			esc_attr( (string) wp_json_encode( $rules ) ),
			$open ? ' open' : '',
			esc_attr__( 'Close', 'barrio-suite' ),
			// Un'immagine eliminata non c'è più: il popup esce senza.
			$popup['image_id'] ? wp_get_attachment_image( $popup['image_id'], 'large', false, [ 'class' => 'barrio-popup__image' ] ) : '',
			esc_html( $text['title'] ?? '' ),
			'' === trim( $content ) ? '' : '<div class="barrio-popup__content">' . do_shortcode( $content ) . '</div>',
			$button
		);
	}

	public static function menu(): void {
		$hook = Admin::add_page( 'barrio-popups', __( 'Popups', 'barrio-suite' ), self::CAP );
		if ( $hook ) {
			// L'editor: TinyMCE per il testo e la libreria dei media per l'immagine.
			add_action(
				"load-{$hook}",
				static function (): void {
					wp_enqueue_editor();
					wp_enqueue_media();
				}
			);
		}
	}

	/**
	 * window.barrioAdmin.popups, a chi gestisce i popup. Senza, il JavaScript sa che il modulo è spento.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function admin_data( array $data ): array {
		return self::can() ? $data + [ 'popups' => true ] : $data;
	}

	public static function register(): void {
		$can = [ self::class, 'can' ];
		$id  = [ 'id' => [ 'type' => 'integer' ] ];
		register_rest_route(
			'barrio/v1',
			'/popups',
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
			'/popups/(?P<id>\d+)',
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
			'/popups/(?P<id>\d+)/duplicate',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'duplicate' ],
				'permission_callback' => $can,
				'args'                => $id,
			]
		);
		register_rest_route(
			'barrio/v1',
			'/popups/bulk',
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
		register_rest_route(
			'barrio/v1',
			'/popups/preview',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'preview' ],
				'permission_callback' => $can,
			]
		);
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'barrio/v1',
			'/popups/settings',
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
		return new WP_REST_Response( array_map( [ self::class, 'prepare' ], PopupRepository::all() ) );
	}

	public static function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$popup = self::input( $request );
		$id    = is_wp_error( $popup ) ? $popup : PopupRepository::save( null, $popup );
		return is_wp_error( $id ) ? $id : self::response( $id, 201 );
	}

	public static function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$popup = self::input( $request );
		$id    = is_wp_error( $popup ) ? $popup : PopupRepository::save( (int) $request['id'], $popup );
		return is_wp_error( $id ) ? $id : self::response( $id );
	}

	public static function delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return PopupRepository::delete( (int) $request['id'] )
			? new WP_REST_Response( [ 'deleted' => true ] )
			: new WP_Error( 'barrio_popup_not_found', __( 'Popup not found.', 'barrio-suite' ), [ 'status' => 404 ] );
	}

	public static function duplicate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = PopupRepository::duplicate( (int) $request['id'] );
		return is_wp_error( $id ) ? $id : self::response( $id, 201 );
	}

	public static function bulk( WP_REST_Request $request ): WP_REST_Response {
		$ids = array_map( 'intval', (array) $request['ids'] );
		return new WP_REST_Response( [ 'count' => 'delete' === $request['action'] ? PopupRepository::delete( ...$ids ) : PopupRepository::set_enabled( 'enable' === $request['action'], ...$ids ) ] );
	}

	/**
	 * Il popup dell'editor, anche non salvato, con i testi della lingua scelta: una pagina finta con il popup aperto,
	 * con lo stile del sito e, se il testo ha un form, i suoi asset. Il form non invia (data-barrio-preview, come nel
	 * builder).
	 */
	public static function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$schema   = PopupRepository::schema()['properties'];
		$settings = $request['settings'];
		$valid    = rest_validate_value_from_schema( $settings, $schema['settings'], 'settings' );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'rest_invalid_param', $valid->get_error_message(), [ 'status' => 400 ] );
		}
		$lang  = I18n::resolve( $request['lang'] );
		$texts = PopupRepository::texts( $request['text'] );
		/** @var Popup $popup */
		$popup = [
			'id'         => 0,
			'is_enabled' => true,
			'starts_at'  => null,
			'ends_at'    => null,
			'image_id'   => absint( $request['image_id'] ) ?: null,
			'settings'   => rest_sanitize_value_from_schema( $settings, $schema['settings'], 'settings' ),
			'i18n'       => [ $lang => [ 'title' => $texts['title'] ?: __( 'The title of the popup', 'barrio-suite' ) ] + $texts ],
		];
		$html  = I18n::in( $lang, static fn(): string => self::html( $popup, true ) );
		$forms = str_contains( $html, 'data-barrio-form' ) ? Render::head() : '';
		return new WP_REST_Response(
			[
				'html' => sprintf(
					'<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="%1$s">%2$s' // phpcs:ignore WordPress.WP.EnqueuedResources -- non è una pagina di WordPress.
					. '<style>body{margin:0;min-height:100vh;padding:24px;box-sizing:border-box;background:#f9fafb;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}.barrio-preview-line{height:10px;margin-bottom:14px;border-radius:5px;background:#e5e7eb}.barrio-preview-backdrop{position:fixed;inset:0;background:rgb(0 0 0/50%%)}.barrio-popup{animation:none}</style></head>'
					. '<body>%3$s%4$s%5$s</body></html>',
					esc_url( add_query_arg( 'ver', BARRIO_SUITE_VERSION, plugins_url( 'assets/frontend/popup.css', __DIR__ ) ) ),
					$forms,
					str_repeat( '<div class="barrio-preview-line"></div>', 12 ),
					'<div class="barrio-preview-backdrop"></div>',
					str_replace( ' data-barrio-form', ' data-barrio-form data-barrio-preview', $html )
				),
			]
		);
	}

	public static function settings(): WP_REST_Response {
		return new WP_REST_Response( Roles::get( [ self::CAP ] ) );
	}

	public static function save_settings( WP_REST_Request $request ): WP_REST_Response {
		Roles::save( [ self::CAP ], (array) $request['roles'] );
		return self::settings();
	}

	private static function response( int $id, int $status = 200 ): WP_REST_Response|WP_Error {
		$popup = PopupRepository::get( $id );
		return $popup
			? new WP_REST_Response( self::prepare( $popup ), $status )
			: new WP_Error( 'barrio_popup_not_found', __( 'Popup not found.', 'barrio-suite' ), [ 'status' => 404 ] );
	}

	/**
	 * @param Popup $popup
	 * @return array<string, mixed>
	 */
	private static function prepare( array $popup ): array {
		return [
			'id'         => $popup['id'],
			'is_enabled' => $popup['is_enabled'],
			'status'     => PopupRepository::status( $popup ),
			'starts_at'  => Period::local( $popup['starts_at'] ),
			'ends_at'    => Period::local( $popup['ends_at'] ),
			'image_id'   => $popup['image_id'],
			'settings'   => $popup['settings'],
			// Un oggetto anche vuoto: [] in JSON sarebbe una lista.
			'i18n'       => (object) $popup['i18n'],
		];
	}

	/** @return array<string, mixed>|WP_Error */
	private static function input( WP_REST_Request $request ): array|WP_Error {
		$period = Period::input( $request );
		return is_wp_error( $period ) ? $period : [
			'is_enabled' => $request['is_enabled'],
			'image_id'   => $request['image_id'],
			'settings'   => $request['settings'],
			'i18n'       => $request['i18n'],
		] + $period;
	}
}
