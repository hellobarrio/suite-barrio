<?php

namespace BarrioSuite\Blocks;

use BarrioSuite\Admin;
use WP_Block_Editor_Context;
use WP_Block_Type_Registry;
use WP_REST_Request;
use WP_REST_Response;
use WP_Theme_JSON;
use WP_Theme_JSON_Data;

/**
 * Cosa trova nell'editor chi scrive (PLAN-MODULI.md §5): i blocchi e le restrizioni per ruolo, l'editor per tipo di
 * contenuto, i contenuti esterni. Ognuna è un filtro del core, tranne l'H1 (assets/editor/no-h1.js). Nel sito e nei
 * contenuti già salvati non cambia niente. Si sceglie in Barrio → Contenuti → Blocchi.
 *
 * @phpstan-type Role array{disabled: list<string>, h1: bool, styles: bool, code: bool, lock: bool, panels: bool}
 * @phpstan-type Settings array{roles: array<string, Role>, editors: array<string, 'blocks'|'classic'|'none'>, remote_patterns: bool, openverse: bool}
 */
final class Module {

	// Non in autoload: si legge solo nell'editor e nella sua REST.
	private const OPTION = 'barrio_blocks';

	// Senza scelte l'editor è quello di WordPress. Un tipo senza editors vale la scelta di WordPress.
	private const DEFAULTS = [
		'roles'           => [],
		'editors'         => [],
		'remote_patterns' => true,
		'openverse'       => true,
	];

	// Per ruolo: i blocchi spenti (non quelli ammessi: un blocco nuovo resta disponibile) e le restrizioni.
	private const ROLE = [
		'disabled' => [],
		'h1'       => false,
		'styles'   => false,
		'code'     => false,
		'lock'     => false,
		'panels'   => false,
	];

	// I pannelli nascosti da panels: l'editor li mostra solo se il tipo li supporta.
	private const PANELS = [ 'author', 'revisions', 'page-attributes' ];

	private const SCHEMA = [
		'roles'           => [
			'type'                 => 'object',
			'additionalProperties' => [
				'type'                 => 'object',
				'properties'           => [
					'disabled' => [
						'type'        => 'array',
						'items'       => [
							'type'    => 'string',
							'pattern' => '^[a-z0-9-]+/[a-z0-9-]+$',
						],
						'uniqueItems' => true,
					],
					'h1'       => [ 'type' => 'boolean' ],
					'styles'   => [ 'type' => 'boolean' ],
					'code'     => [ 'type' => 'boolean' ],
					'lock'     => [ 'type' => 'boolean' ],
					'panels'   => [ 'type' => 'boolean' ],
				],
				'required'             => [ 'disabled', 'h1', 'styles', 'code', 'lock', 'panels' ],
				'additionalProperties' => false,
			],
		],
		'editors'         => [
			'type'                 => 'object',
			'additionalProperties' => [
				'type' => 'string',
				'enum' => [ 'blocks', 'classic', 'none' ],
			],
		],
		'remote_patterns' => [ 'type' => 'boolean' ],
		'openverse'       => [ 'type' => 'boolean' ],
	];

	public static function boot(): void {
		add_action( 'rest_api_init', [ self::class, 'register' ] );
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_filter( 'barrio_admin_data', [ self::class, 'admin_data' ] );
		add_filter( 'allowed_block_types_all', [ self::class, 'allowed' ] );
		add_filter( 'block_editor_settings_all', [ self::class, 'editor_settings' ] );
		add_filter( 'wp_theme_json_data_theme', [ self::class, 'theme_json' ] );
		add_filter( 'rest_prepare_post_type', [ self::class, 'post_type' ] );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'block_editor' ] );
		add_filter( 'use_block_editor_for_post_type', [ self::class, 'use_block_editor' ], 10, 2 );
		add_action( 'load-post.php', [ self::class, 'no_editor' ] );
		add_action( 'load-post-new.php', [ self::class, 'no_editor' ] );
		add_filter( 'should_load_remote_block_patterns', static fn( bool $load ): bool => $load && self::settings()['remote_patterns'] );
	}

	public static function deactivate(): void {}

	// L'opzione la elimina il core (src/Uninstall.php).
	public static function uninstall(): void {}

	public static function menu(): void {
		Admin::contents_tab( 'manage_options' );
	}

	/**
	 * In window.barrioAdmin.blocks, per la card Contenuti della Bacheca. Senza, il JavaScript sa che il modulo è spento.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function admin_data( array $data ): array {
		return $data + [ 'blocks' => self::output( self::settings() ) ];
	}

	public static function register(): void {
		$admin = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route(
			'barrio/v1',
			'/blocks',
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

	// available: i ruoli, i tipi di contenuto e i blocchi tra cui scegliere.
	public static function get(): WP_REST_Response {
		return new WP_REST_Response( self::output( self::settings() ) + [ 'available' => self::available() ] );
	}

	/**
	 * Un'impostazione assente resta com'è. Gli amministratori hanno sempre tutto, e un ruolo senza restrizioni non si
	 * salva.
	 */
	public static function put( WP_REST_Request $request ): WP_REST_Response {
		$values = array_intersect_key( $request->get_params(), self::DEFAULTS );
		if ( isset( $values['roles'] ) ) {
			unset( $values['roles']['administrator'] );
			$values['roles'] = array_filter( $values['roles'], static fn( array $role ): bool => (bool) array_filter( $role ) );
		}
		update_option( self::OPTION, $values + self::settings(), false );
		return self::get();
	}

	/**
	 * Tutti i blocchi registrati tranne quelli spenti. I blocchi registrati solo nel JavaScript non sono nell'elenco,
	 * quindi con un blocco spento spariscono anche loro (PLAN-MODULI.md §10).
	 *
	 * @param bool|string[] $allowed
	 * @return bool|string[]
	 */
	public static function allowed( bool|array $allowed ): bool|array {
		$disabled = self::restrictions()['disabled'];
		if ( ! $disabled || false === $allowed ) {
			return $allowed;
		}
		$names = true === $allowed ? array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ) : $allowed;
		return array_values( array_diff( $names, $disabled ) );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function editor_settings( array $settings ): array {
		$restrictions = self::restrictions();
		if ( $restrictions['code'] ) {
			$settings['codeEditingEnabled'] = false;
		}
		if ( $restrictions['lock'] ) {
			$settings['canLockBlocks'] = false;
		}
		if ( ! self::settings()['openverse'] ) {
			$settings['enableOpenverseMediaCategory'] = false;
		}
		return $settings;
	}

	/**
	 * Colori e tipografia del tema, senza quelli personalizzati: cambiano i controlli e non i blocchi, quindi un blocco
	 * colorato da un amministratore resta valido. Solo nell'admin: nel sito non serve, e il filtro gira a ogni pagina.
	 * Il risultato resta in WP_Theme_JSON_Resolver e nel gruppo di cache theme_json, che durano una richiesta.
	 */
	public static function theme_json( WP_Theme_JSON_Data $theme_json ): WP_Theme_JSON_Data {
		if ( ! is_admin() || ! self::restrictions()['styles'] ) {
			return $theme_json;
		}
		return $theme_json->update_with(
			[
				'version'  => WP_Theme_JSON::LATEST_SCHEMA,
				'settings' => [
					'color'      => [
						'custom'         => false,
						'customGradient' => false,
						'customDuotone'  => false,
					],
					'typography' => [
						'customFontSize' => false,
						'fontStyle'      => false,
						'fontWeight'     => false,
						'letterSpacing'  => false,
						'lineHeight'     => false,
						'textDecoration' => false,
						'textTransform'  => false,
						'writingMode'    => false,
					],
				],
			]
		);
	}

	// Autore, Revisioni e Genitore (attributi della pagina) l'editor li mostra solo se il tipo li supporta: per chi non
	// deve vederli la REST dei tipi non li elenca. Il supporto resta, e con lui revisioni, autore e genitore.
	public static function post_type( WP_REST_Response $response ): WP_REST_Response {
		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['supports'] ) && self::restrictions()['panels'] ) {
			$data['supports'] = array_diff_key( $data['supports'], array_flip( self::PANELS ) );
			$response->set_data( $data );
		}
		return $response;
	}

	// Senza H1 nei titoli. Anche nell'editor del sito e dei widgets.
	public static function block_editor(): void {
		if ( self::restrictions()['h1'] ) {
			wp_enqueue_script( 'barrio-blocks-no-h1', plugins_url( 'assets/editor/no-h1.js', __DIR__ ), [ 'wp-hooks' ], BARRIO_SUITE_VERSION, true );
		}
	}

	public static function use_block_editor( bool $block_editor, string $post_type ): bool {
		return match ( self::settings()['editors'][ $post_type ] ?? null ) {
			'blocks'          => true,
			'classic', 'none' => false,
			default           => $block_editor,
		};
	}

	// Nessun editor: il tipo perde il supporto solo nelle schermate di modifica, così il contenuto resta nella REST e
	// in WPGraphQL. La schermata si apre con l'editor classico, senza il campo del contenuto.
	public static function no_editor(): void {
		$type = get_current_screen()?->post_type;
		if ( $type && 'none' === ( self::settings()['editors'][ $type ] ?? null ) ) {
			remove_post_type_support( $type, 'editor' );
		}
	}

	/**
	 * Le restrizioni di chi guarda. I ruoli si sommano come le capability: vale solo ciò che tutti i suoi ruoli
	 * tolgono. Gli amministratori non hanno restrizioni.
	 *
	 * @return Role
	 */
	private static function restrictions(): array {
		$roles = array_values( wp_get_current_user()->roles );
		if ( ! $roles ) {
			return self::ROLE;
		}
		$saved = self::settings()['roles'];
		$each  = array_map( static fn( string $role ): array => $saved[ $role ] ?? self::ROLE, $roles );
		$all   = static fn( string $key ): bool => ! in_array( false, array_column( $each, $key ), true );
		return [
			'disabled' => array_values( array_intersect( ...array_column( $each, 'disabled' ) ) ),
			'h1'       => $all( 'h1' ),
			'styles'   => $all( 'styles' ),
			'code'     => $all( 'code' ),
			'lock'     => $all( 'lock' ),
			'panels'   => $all( 'panels' ),
		];
	}

	/**
	 * Gli elenchi vuoti come oggetti: in JSON [] sarebbe una lista.
	 *
	 * @param Settings $settings
	 * @return array<string, mixed>
	 */
	private static function output( array $settings ): array {
		return [
			'roles'   => (object) $settings['roles'],
			'editors' => (object) $settings['editors'],
		] + $settings;
	}

	/** @return array<string, mixed> */
	private static function available(): array {
		// Anche l'amministratore, che non si salva: nel menu dei ruoli è il primo e cambia ciò che vale per tutti.
		$roles = wp_roles()->get_names();
		// I tipi del core (pattern, menu di navigazione) esistono per l'editor a blocchi.
		$types  = array_filter(
			Admin::content_types()['post_types'],
			static fn( array $type ): bool => ! str_starts_with( $type['name'], 'wp_' ) && post_type_supports( $type['name'], 'editor' )
		);
		$blocks = [];
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $block ) {
			// Quelli che esistono solo dentro un altro (voce di elenco, colonna) seguono il loro blocco; quelli che non
			// si inseriscono (Non supportato) servono ad aprire i contenuti e restano.
			if ( ! $block->parent && ! $block->ancestor && false !== ( $block->supports['inserter'] ?? true ) ) {
				$blocks[] = [
					'name'     => $block->name,
					'title'    => $block->title ?: $block->name,
					'category' => $block->category,
				];
			}
		}
		return [
			'roles'      => array_map( 'translate_user_role', $roles ),
			'types'      => array_values(
				array_map(
					static fn( array $type ): array => $type + [ 'blocks' => (bool) get_post_type_object( $type['name'] )?->show_in_rest ],
					$types
				)
			),
			'blocks'     => $blocks,
			'categories' => array_map(
				static fn( array $category ): array => [
					'slug'  => $category['slug'],
					'title' => $category['title'],
				],
				get_block_categories( new WP_Block_Editor_Context( [ 'name' => 'core/edit-post' ] ) )
			),
		];
	}
}
