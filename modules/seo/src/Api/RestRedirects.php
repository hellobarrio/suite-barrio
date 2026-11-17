<?php

namespace BarrioSuite\Seo\Api;

use BarrioSuite\Seo\RedirectCode;
use BarrioSuite\Seo\Redirects;
use BarrioSuite\Seo\Repository\RedirectRepository;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * SEO → Redirect (barrio_manage_seo): ricerca, filtri, ordinamento e pagine sul server; creazione, modifica
 * ed eliminazione. La sorgente si normalizza come le richieste del sito (Redirects::path()).
 *
 * @phpstan-import-type Redirect from RedirectRepository
 */
final class RestRedirects {

	public const PER_PAGE = 20;

	public static function register(): void {
		$manage   = static fn(): bool => current_user_can( 'barrio_manage_seo' );
		$codes    = array_column( RedirectCode::cases(), 'value' );
		$redirect = [
			'source' => [
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 768,
				'required'  => true,
			],
			// Un percorso o un URL assoluto; senza, solo con il 410.
			'target' => [
				'type'      => [ 'string', 'null' ],
				'maxLength' => 2048,
			],
			'code'   => [
				'type'     => 'integer',
				'enum'     => $codes,
				'required' => true,
			],
			'note'   => [
				'type'      => [ 'string', 'null' ],
				'maxLength' => 255,
			],
		];
		register_rest_route(
			'barrio/v1',
			'/seo/redirects',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'list' ],
					'permission_callback' => $manage,
					'args'                => [
						'search' => [
							'type'    => 'string',
							'default' => '',
						],
						'code'   => [
							'type' => 'integer',
							'enum' => $codes,
						],
						// Assente = automatici e a mano.
						'auto'   => [ 'type' => 'boolean' ],
						'sort'   => [
							'type'    => 'string',
							'enum'    => [ 'source', 'target', 'code', 'updated_at' ],
							'default' => 'updated_at',
						],
						'order'  => [
							'type'    => 'string',
							'enum'    => [ 'asc', 'desc' ],
							'default' => 'desc',
						],
						'page'   => [
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						],
					],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'create' ],
					'permission_callback' => $manage,
					'args'                => $redirect,
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/seo/redirects/(?P<id>\d+)',
			[
				'methods'             => 'PUT',
				'callback'            => [ self::class, 'update' ],
				'permission_callback' => $manage,
				'args'                => $redirect,
			]
		);
		register_rest_route(
			'barrio/v1',
			'/seo/redirects/bulk',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'bulk' ],
				'permission_callback' => $manage,
				'args'                => [
					'action' => [
						'type'     => 'string',
						'enum'     => [ 'delete' ],
						'required' => true,
					],
					'ids'    => [
						'type'     => 'array',
						'items'    => [ 'type' => 'integer' ],
						'minItems' => 1,
						'required' => true,
					],
				],
			]
		);
	}

	public static function list( WP_REST_Request $request ): WP_REST_Response {
		$page = RedirectRepository::page(
			[
				'search' => (string) $request['search'],
				'code'   => null === $request['code'] ? null : (int) $request['code'],
				'auto'   => null === $request['auto'] ? null : (bool) $request['auto'],
			],
			(string) $request['sort'],
			(string) $request['order'],
			(int) $request['page'],
			self::PER_PAGE
		);
		return new WP_REST_Response(
			[
				'rows'  => array_map( [ self::class, 'format' ], $page['redirects'] ),
				'total' => $page['total'],
			]
		);
	}

	public static function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return self::write( $request, null );
	}

	public static function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];
		if ( null === RedirectRepository::get( $id ) ) {
			return new WP_Error( 'barrio_redirect_not_found', __( 'Redirect not found.', 'barrio-suite' ), [ 'status' => 404 ] );
		}
		return self::write( $request, $id );
	}

	public static function bulk( WP_REST_Request $request ): WP_REST_Response {
		$deleted = RedirectRepository::delete( ...array_map( 'intval', (array) $request['ids'] ) );
		if ( $deleted ) {
			do_action( 'barrio_seo_changed', 'redirects' );
		}
		return new WP_REST_Response( [ 'deleted' => $deleted ] );
	}

	private static function write( WP_REST_Request $request, ?int $id ): WP_REST_Response|WP_Error {
		$code   = RedirectCode::from( (int) $request['code'] );
		$source = Redirects::path( trim( (string) $request['source'] ) );
		$target = RedirectCode::Gone === $code ? null : trim( (string) $request['target'] );
		$note   = trim( (string) $request['note'] );
		$error  = static fn( string $message ): WP_Error => new WP_Error( 'barrio_invalid_redirect', $message, [ 'status' => 400 ] );

		if ( null !== $target && ! preg_match( '#^(/|https?://)#', $target ) ) {
			return $error( __( 'The destination must start with / or with http:// or https://.', 'barrio-suite' ) );
		}
		$host = wp_parse_url( (string) $target, PHP_URL_HOST );
		if ( null !== $target && ( null === $host || wp_parse_url( home_url(), PHP_URL_HOST ) === $host ) && Redirects::path( $target ) === $source ) {
			return $error( __( 'The destination is the same as the source.', 'barrio-suite' ) );
		}
		$existing = RedirectRepository::find( $source );
		if ( null !== $existing && $existing['id'] !== $id ) {
			/* translators: %s: the source address of a redirect, such as /old-page. */
			return $error( sprintf( __( 'There is already a redirect from %s.', 'barrio-suite' ), $source ) );
		}
		$note = '' === $note ? null : $note;
		try {
			if ( null === $id ) {
				$saved = RedirectRepository::get( RedirectRepository::create( $source, $target, $code, $note ) );
			} else {
				RedirectRepository::update( $id, $source, $target, $code, $note );
				$saved = RedirectRepository::get( $id );
			}
		} catch ( RuntimeException $e ) {
			return $error( $e->getMessage() );
		}
		do_action( 'barrio_seo_changed', 'redirects' );
		/** @var Redirect $saved */
		return new WP_REST_Response( self::format( $saved ), null === $id ? 201 : 200 );
	}

	/**
	 * @param Redirect $redirect
	 * @return array<string, mixed>
	 */
	private static function format( array $redirect ): array {
		return [
			'code'     => $redirect['code']->value,
			'modified' => (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( "{$redirect['updated_at']} UTC" ) ),
		] + $redirect;
	}
}
