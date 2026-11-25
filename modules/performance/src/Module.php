<?php

namespace BarrioSuite\Performance;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Prestazioni con ciò che WordPress sa già fare, senza servizi esterni: il formato
 * delle immagini, il caricamento speculativo e un head più leggero. Un modulo a sé e non nel SEO, perché serve
 * anche ai siti con Yoast o Rank Math. Si sceglie nella sua pagina, Barrio → Prestazioni; accese di default solo
 * le funzioni che non cambiano file né comportamento.
 *
 * @phpstan-type Settings array{image_format: ''|'image/webp'|'image/avif', speculation_mode: 'auto'|'prefetch'|'prerender', speculation_eagerness: 'auto'|'conservative'|'moderate'|'eager', emoji: bool, generator: bool, rsd: bool, shortlink: bool, dashicons: bool}
 */
final class Module {

	// In autoload: si legge a ogni pagina.
	private const OPTION = 'barrio_performance';

	// image_format vuoto: le dimensioni restano nel formato caricato. auto: la scelta del core.
	private const DEFAULTS = [
		'image_format'          => '',
		'speculation_mode'      => 'auto',
		'speculation_eagerness' => 'auto',
		'emoji'                 => true,
		'generator'             => true,
		'rsd'                   => true,
		'shortlink'             => true,
		// Spento: alcuni temi usano le icone Dashicons anche nel sito.
		'dashicons'             => false,
	];

	private const SCHEMA = [
		'image_format'          => [
			'type' => 'string',
			'enum' => [ '', 'image/webp', 'image/avif' ],
		],
		'speculation_mode'      => [
			'type' => 'string',
			'enum' => [ 'auto', 'prefetch', 'prerender' ],
		],
		// Il core non accetta immediate per le regole del documento.
		'speculation_eagerness' => [
			'type' => 'string',
			'enum' => [ 'auto', 'conservative', 'moderate', 'eager' ],
		],
		'emoji'                 => [ 'type' => 'boolean' ],
		'generator'             => [ 'type' => 'boolean' ],
		'rsd'                   => [ 'type' => 'boolean' ],
		'shortlink'             => [ 'type' => 'boolean' ],
		'dashicons'             => [ 'type' => 'boolean' ],
	];

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_filter( 'barrio_admin_data', [ self::class, 'admin_data' ] );
		add_filter( 'wp_speculation_rules_configuration', [ self::class, 'speculation' ] );

		$settings = self::settings();
		if ( '' !== $settings['image_format'] ) {
			add_filter( 'image_editor_output_format', [ self::class, 'image_format' ] );
		}
		// Le azioni del core sono già in default-filters.php, caricato prima dei plugin.
		if ( $settings['emoji'] ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			// Come chiede il core: senza questa, wp_enqueue_emoji_styles non aggiunge gli stili.
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
		}
		if ( $settings['generator'] ) {
			remove_action( 'wp_head', 'wp_generator' );
		}
		if ( $settings['rsd'] ) {
			remove_action( 'wp_head', 'rsd_link' );
		}
		if ( $settings['shortlink'] ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}
		if ( $settings['dashicons'] ) {
			add_action( 'wp_enqueue_scripts', [ self::class, 'dashicons' ], 100 );
		}
	}

	public static function deactivate(): void {}

	// L'opzione la elimina il core (src/Uninstall.php).
	public static function uninstall(): void {}

	public static function menu(): void {
		\BarrioSuite\Admin::add_page( 'barrio-performance', __( 'Performance', 'barrio-suite' ), 'manage_options' );
	}

	/**
	 * In window.barrioAdmin.performance, per la card della Bacheca. Senza, il JavaScript sa che il modulo è spento.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function admin_data( array $data ): array {
		return $data + [ 'performance' => self::settings() ];
	}

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'barrio/v1',
			'/performance',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get' ],
					'permission_callback' => $admin,
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'put' ],
					'permission_callback' => $admin,
					'args'                => self::SCHEMA,
				],
			]
		);
	}

	/** @return Settings */
	public static function settings(): array {
		/** @var Settings */
		return array_intersect_key( (array) get_option( self::OPTION, [] ), self::DEFAULTS ) + self::DEFAULTS;
	}

	// formats: quelli che l'editor di immagini del server sa scrivere, tra WebP e AVIF.
	public static function get(): WP_REST_Response {
		return new WP_REST_Response(
			self::settings() + [
				'formats' => array_values(
					array_filter(
						[ 'image/webp', 'image/avif' ],
						static fn( string $mime ): bool => wp_image_editor_supports( [ 'mime_type' => $mime ] )
					)
				),
			]
		);
	}

	// Un'impostazione assente resta com'è. Vale dalla richiesta successiva: gli hook si scelgono al boot.
	public static function put( WP_REST_Request $request ): WP_REST_Response {
		$values = array_intersect_key( $request->get_params(), self::DEFAULTS );
		update_option( self::OPTION, $values + self::settings(), true );
		return self::get();
	}

	/**
	 * Le dimensioni dei JPEG (e degli HEIC, che il core porta in JPEG) nel formato scelto, al caricamento e
	 * rigenerandole. L'originale resta com'è (original_image nei metadati). I PNG no: sono di solito loghi e
	 * grafiche, dove la compressione lossy si vede. Se l'editor del server non sa scrivere il formato, il core
	 * tiene quello del file.
	 *
	 * @param array<string, string> $formats
	 * @return array<string, string>
	 */
	public static function image_format( array $formats ): array {
		$format = self::settings()['image_format'];
		return array_map(
			static fn( string $to ): string => 'image/jpeg' === $to ? $format : $to,
			$formats + [ 'image/jpeg' => 'image/jpeg' ]
		);
	}

	/**
	 * Le scelte diverse da auto. null resta null: il core spegne il caricamento speculativo per chi ha fatto
	 * l'accesso e senza permalink. Le esclusioni sono del core: wp-admin, wp-*.php (anche il logout) e, con i
	 * permalink, ogni URL con una query (add-to-cart…).
	 *
	 * @param array<string, string>|null $config
	 * @return array<string, string>|null
	 */
	public static function speculation( ?array $config ): ?array {
		if ( null === $config ) {
			return null;
		}
		$settings = self::settings();
		$chosen   = [
			'mode'      => $settings['speculation_mode'],
			'eagerness' => $settings['speculation_eagerness'],
		];
		return array_filter( $chosen, static fn( string $value ): bool => 'auto' !== $value ) + $config;
	}

	// Solo per chi non vede la barra di amministrazione, che li usa.
	public static function dashicons(): void {
		if ( ! is_admin_bar_showing() ) {
			wp_dequeue_style( 'dashicons' );
		}
	}
}
