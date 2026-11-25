<?php
// Il frontend headless: indirizzo e token in barrio_frontend, con le rotte /barrio/v1/frontend della card di Barrio →
// Impostazioni (assets/src/general/Frontend.js, nel core, che la mostra con barrioAdmin.frontend), e la revalidation di
// Next.js al posto di quella del prototipo, con la stessa chiamata e gli stessi tag, così i frontend non cambiano. Si
// collega sempre, anche con i moduli spenti: i tag di una richiesta partono alla fine, in una chiamata sola.

use BarrioSuite\Banners\BannerRepository;
use BarrioSuite\Popups\PopupRepository;

defined( 'ABSPATH' ) || exit;

function barrio_wpgraphql_frontend_boot(): void {
	add_action( 'rest_api_init', 'barrio_wpgraphql_frontend_rest' );
	add_filter( 'barrio_admin_data', static fn( array $data ): array => $data + [ 'frontend' => current_user_can( 'manage_options' ) ] );
	// Per ultima: con PHP-FPM chiude la risposta, e dopo gli altri callback di shutdown non potrebbero più stampare.
	add_action( 'shutdown', 'barrio_wpgraphql_revalidate_shutdown', PHP_INT_MAX );

	add_action( 'wp_after_insert_post', static fn( int $id, WP_Post $post, bool $update, ?WP_Post $before ) => barrio_wpgraphql_revalidate( ...barrio_wpgraphql_post_tags( $post, $before ) ), 10, 4 );
	add_action( 'before_delete_post', static fn( int $id, WP_Post $post ) => barrio_wpgraphql_revalidate( ...barrio_wpgraphql_post_tags( $post ) ), 10, 2 );
	add_action( 'wp_update_nav_menu', static fn() => barrio_wpgraphql_revalidate( 'wp:menu' ) );
	add_action( 'wp_delete_nav_menu', static fn() => barrio_wpgraphql_revalidate( 'wp:menu' ) );
	add_action( 'barrio_seo_changed', static fn( string $what, int $id = 0 ) => barrio_wpgraphql_revalidate( ...barrio_wpgraphql_seo_tags( $what, $id ) ), 10, 2 );
	add_action( 'barrio_forms_changed', static fn( int $id ) => barrio_wpgraphql_revalidate( "form_{$id}" ) );
	add_action( 'barrio_hours_changed', static fn() => barrio_wpgraphql_revalidate( 'wp:orari' ) );
	// wp:option nel frontend copre anche lo schema, che prende da qui nome, contatti e indirizzo.
	add_action( 'barrio_company_changed', static fn() => barrio_wpgraphql_revalidate( 'wp:option' ) );
	add_action( 'barrio_ordering_changed', static fn( string $kind, string $name ) => barrio_wpgraphql_revalidate( "wp:type:{$name}" ), 10, 2 );
	// Un alt cambiato, a mano o generato, è nelle pagine del contenuto a cui l'immagine è allegata.
	foreach ( [ 'added_post_meta', 'updated_post_meta' ] as $hook ) {
		add_action(
			$hook,
			static function ( mixed $meta_id, int $id, string $key ): void {
				$parent = '_wp_attachment_image_alt' === $key ? wp_get_post_parent_id( $id ) : 0;
				// get_post( 0 ) darebbe il contenuto della pagina.
				$parent = $parent ? get_post( $parent ) : null;
				if ( $parent ) {
					barrio_wpgraphql_revalidate( ...barrio_wpgraphql_post_tags( $parent ) );
				}
			},
			10,
			3
		);
	}
	$periods = [
		'popups'  => [ 'wp:popup', PopupRepository::class ],
		'banners' => [ 'wp:banner', BannerRepository::class ],
	];
	foreach ( $periods as $module => [ $tag, $repository ] ) {
		add_action(
			"barrio_{$module}_changed",
			static function () use ( $module, $tag, $repository ): void {
				barrio_wpgraphql_revalidate( $tag );
				barrio_wpgraphql_schedule( "barrio_wpgraphql_{$module}_period", [ $repository, 'all' ] );
			}
		);
		add_action( "barrio_wpgraphql_{$module}_period", static fn() => barrio_wpgraphql_revalidate( $tag ) );
	}
}

/** @return array{url: string, token: string} */
function barrio_wpgraphql_frontend(): array {
	$frontend = (array) get_option( 'barrio_frontend', [] );
	return [
		'url'   => (string) ( $frontend['url'] ?? '' ),
		'token' => (string) ( $frontend['token'] ?? '' ),
	];
}

/**
 * Per la card: error è l'ultima revalidation non riuscita, finché una non riesce.
 *
 * @return array{url: string, token: string, error: string}
 */
function barrio_wpgraphql_frontend_get(): array {
	return barrio_wpgraphql_frontend() + [ 'error' => (string) get_transient( 'barrio_frontend_error' ) ];
}

function barrio_wpgraphql_frontend_rest(): void {
	$admin = static fn(): bool => current_user_can( 'manage_options' );
	register_rest_route(
		'barrio/v1',
		'/frontend',
		[
			[
				'methods'             => 'GET',
				'callback'            => 'barrio_wpgraphql_frontend_get',
				'permission_callback' => $admin,
			],
			[
				'methods'             => 'PUT',
				'callback'            => 'barrio_wpgraphql_frontend_put',
				'permission_callback' => $admin,
				'args'                => [
					'url'   => [
						'type'     => 'string',
						'required' => true,
					],
					'token' => [
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 200,
					],
				],
			],
		]
	);
	register_rest_route(
		'barrio/v1',
		'/frontend/clear',
		[
			'methods'             => 'POST',
			'callback'            => 'barrio_wpgraphql_frontend_clear',
			'permission_callback' => $admin,
			'args'                => [
				'tags' => [
					'type'     => 'string',
					'required' => true,
				],
			],
		]
	);
}

/** @return array{url: string, token: string, error: string}|WP_Error */
function barrio_wpgraphql_frontend_put( WP_REST_Request $request ): array|WP_Error {
	$url = trim( (string) $request['url'] );
	// https per primo: è il protocollo che esc_url_raw() mette a un indirizzo scritto senza.
	$clean = '' === $url ? '' : untrailingslashit( esc_url_raw( $url, [ 'https', 'http' ] ) );
	$parts = (array) wp_parse_url( $clean );
	// Un percorso è ammesso (il basePath di Next.js); con una query /api/revalidate/ finirebbe dentro di lei.
	if ( '' !== $url && ( empty( $parts['host'] ) || array_intersect_key( $parts, array_flip( [ 'query', 'fragment', 'user' ] ) ) ) ) {
		return new WP_Error( 'barrio_frontend_url', __( 'The address of the frontend must start with https:// or http://.', 'barrio-suite-wpgraphql' ), [ 'status' => 400 ] );
	}
	// Salvato com'è, non ripulito: deve restare uguale a quello del frontend. Senza spazi né a capo, che in un header
	// non sono ammessi.
	$token = trim( (string) $request['token'] );
	if ( ! preg_match( '#^[A-Za-z0-9._~+/=-]*$#D', $token ) ) {
		return new WP_Error( 'barrio_frontend_token', __( 'The token can only have letters, numbers and the characters . _ ~ + / = - without spaces.', 'barrio-suite-wpgraphql' ), [ 'status' => 400 ] );
	}
	update_option(
		'barrio_frontend',
		[
			'url'   => $clean,
			'token' => $token,
		],
		true
	);
	return barrio_wpgraphql_frontend_get();
}

/**
 * Svuota a mano la cache di alcuni tag, come il pulsante del prototipo, con l'indirizzo e il token salvati.
 *
 * @return array{message: string}|WP_Error
 */
function barrio_wpgraphql_frontend_clear( WP_REST_Request $request ): array|WP_Error {
	$tags = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( (string) $request['tags'] ) ) ) );
	if ( ! $tags || '' === barrio_wpgraphql_frontend()['url'] ) {
		return new WP_Error( 'barrio_frontend_clear', __( 'Write the address of the frontend and at least one tag.', 'barrio-suite-wpgraphql' ), [ 'status' => 400 ] );
	}
	barrio_wpgraphql_revalidate( ...$tags );
	$error = barrio_wpgraphql_frontend_error( barrio_wpgraphql_revalidate_send() );
	return '' === $error ? [ 'message' => __( 'The frontend cleared its cache.', 'barrio-suite-wpgraphql' ) ] : new WP_Error( 'barrio_frontend_clear', $error, [ 'status' => 502 ] );
}

/**
 * Perché il frontend non ha svuotato la cache, o '' se l'ha fatto.
 *
 * @param array<string, mixed>|WP_Error|null $response
 */
function barrio_wpgraphql_frontend_error( array|WP_Error|null $response ): string {
	if ( null === $response ) {
		return '';
	}
	$code = is_array( $response ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
	if ( 0 === $code ) {
		return trim( __( 'The frontend did not answer: check its address.', 'barrio-suite-wpgraphql' ) . ' ' . ( is_wp_error( $response ) ? $response->get_error_message() : '' ) );
	}
	/* translators: %d: HTTP status code, like 401. */
	return $code >= 300 ? sprintf( __( 'The frontend answered with the error %d: with 401, the token is not the one of the frontend.', 'barrio-suite-wpgraphql' ), $code ) : '';
}

// Alla chiamata di fine richiesta, ognuno una volta: i tag sono le chiavi.
function barrio_wpgraphql_revalidate( string ...$tags ): void {
	global $barrio_wpgraphql_tags;
	$barrio_wpgraphql_tags = (array) $barrio_wpgraphql_tags + array_fill_keys( $tags, true );
}

function barrio_wpgraphql_revalidate_shutdown(): void {
	global $barrio_wpgraphql_tags;
	// Chi salva non aspetta il frontend: con PHP-FPM la risposta parte prima della chiamata.
	if ( $barrio_wpgraphql_tags && '' !== barrio_wpgraphql_frontend()['url'] && function_exists( 'fastcgi_finish_request' ) ) {
		fastcgi_finish_request();
	}
	barrio_wpgraphql_revalidate_send();
}

/**
 * La chiamata del prototipo, GET {frontend}/api/revalidate/?tag=a,b, con il token al posto del suo "Bearer YOUR_TOKEN".
 * Svuota la coda e dà l'ultima risposta; null se non c'era niente o manca l'indirizzo.
 *
 * @return array<string, mixed>|WP_Error|null
 */
function barrio_wpgraphql_revalidate_send(): array|WP_Error|null {
	global $barrio_wpgraphql_tags;
	$tags                  = array_keys( (array) $barrio_wpgraphql_tags );
	$barrio_wpgraphql_tags = [];
	// Prima di leggere l'opzione: finché nessuno la salva non esiste, e costerebbe una query a ogni richiesta.
	if ( ! $tags ) {
		return null;
	}
	$frontend = barrio_wpgraphql_frontend();
	if ( '' === $frontend['url'] ) {
		return null;
	}
	$response = null;
	// Una chiamata ogni 100 tag: con una modifica in blocco di molti contenuti un URL oltre gli 8 KB si può rifiutare.
	foreach ( array_chunk( $tags, 100 ) as $chunk ) {
		$response = wp_remote_get(
			"{$frontend['url']}/api/revalidate/?" . http_build_query( [ 'tag' => implode( ',', $chunk ) ] ),
			[ 'headers' => '' === $frontend['token'] ? [] : [ 'Authorization' => "Bearer {$frontend['token']}" ] ]
		);
		// Un frontend che non risponde non si aspetta una volta per blocco.
		if ( is_wp_error( $response ) ) {
			break;
		}
	}
	// L'ultimo errore resta per la card finché una chiamata non riesce: altrimenti il sito smette di aggiornarsi e
	// in WordPress non lo dice niente.
	$error = barrio_wpgraphql_frontend_error( $response );
	if ( '' === $error ) {
		delete_transient( 'barrio_frontend_error' );
	} else {
		set_transient( 'barrio_frontend_error', $error, WEEK_IN_SECONDS );
	}
	return $response;
}

/**
 * I tag di un contenuto che il frontend legge, se era o è pubblico: l'URI di prima e quello di adesso, perché il
 * frontend tiene in cache anche la pagina che un cestino, una bozza o un nuovo slug tolgono; poi il tipo e l'ID.
 * Le bozze, le revisioni e i salvataggi automatici non cambiano il frontend; le immagini nemmeno, e ogni caricamento
 * sarebbe una chiamata.
 *
 * @return list<string>
 */
function barrio_wpgraphql_post_tags( WP_Post $post, ?WP_Post $before = null ): array {
	if ( 'attachment' === $post->post_type || ! in_array( $post->post_type, get_post_types( [ 'show_in_graphql' => true ] ), true ) ) {
		return [];
	}
	$tags = [];
	// Con WordPress in una sottocartella l'URI che il frontend conosce, quello di WPGraphQL, non ha il percorso della home.
	$home = '#^' . preg_quote( trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ), '#' ) . '(/|$)#';
	foreach ( [ $before, $post ] as $item ) {
		if ( $item && is_post_status_viewable( (string) get_post_status( $item ) ) ) {
			// Come nel frontend: /servizi/web-design/ → wp:uri:servizi_web-design, la home → wp:uri:.
			$tags[] = 'wp:uri:' . str_replace( '/', '_', (string) preg_replace( $home, '', trim( wp_make_link_relative( (string) get_permalink( $item ) ), '/' ) ) );
		}
	}
	return $tags ? [ ...$tags, "wp:type:{$post->post_type}", "page_{$post->ID}" ] : [];
}

/** @return list<string> */
function barrio_wpgraphql_seo_tags( string $what, int $id ): array {
	$post = 'post' === $what ? get_post( $id ) : null;
	$tags = $post ? barrio_wpgraphql_post_tags( $post ) : [];
	return match ( $what ) {
		// Il noindex di un contenuto lo toglie anche dalla sitemap; una bozza non c'è mai stata.
		'post'      => $tags ? [ ...$tags, 'wp:sitemap' ] : [],
		'redirects' => [ 'wp:redirects' ],
		default     => [ 'wp:seo', 'wp:schema', 'wp:sitemap', 'wp:llms' ],
	};
}

/**
 * Una revalidation a ogni inizio e fine futuri degli accesi, perché lì cambia ciò che il frontend mostra senza che
 * niente si salvi. Il timestamp negli argomenti tiene distinti due eventi a meno di 10 minuti.
 *
 * @param callable(): list<array{is_enabled: bool, starts_at: string|null, ends_at: string|null}> $all
 */
function barrio_wpgraphql_schedule( string $hook, callable $all ): void {
	wp_unschedule_hook( $hook );
	foreach ( array_filter( $all(), static fn( array $item ): bool => $item['is_enabled'] ) as $item ) {
		foreach ( [ $item['starts_at'], $item['ends_at'] ] as $date ) {
			$time = null === $date ? 0 : (int) strtotime( "{$date} UTC" );
			if ( $time > time() ) {
				wp_schedule_single_event( $time, $hook, [ $time ] );
			}
		}
	}
}
