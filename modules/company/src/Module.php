<?php

namespace BarrioSuite\Company;

use BarrioSuite\Admin;
use BarrioSuite\Roles;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Dati aziendali: nome, contatti, dati legali, sedi e profili dell'azienda, nell'opzione
 * barrio_company, validata con schema() prima di ogni scrittura. È l'unico posto dove si scrivono: lo schema del SEO
 * (Seo\Schema) li legge da qui, anche con il modulo spento. Nel sito il blocco barrio/company e lo shortcode
 * [barrio_company], con lo stesso HTML.
 *
 * @phpstan-type Contact array{label: string, value: string}
 * @phpstan-type Office array{name: string, street: string, postal_code: string, locality: string, region: string, country: string, maps_url: string, phones: list<Contact>, emails: list<Contact>}
 * @phpstan-type Settings array{name: string, legal_name: string, vat_id: string, tax_code: string, registration_number: string, share_capital: string, invoice_code: string, phone: string, mobile: string, email: string, certified_email: string, privacy_email: string, address: Office, offices: list<Office>, profiles: list<string>}
 * @phpstan-type Data array{name: string, legal_name: string, phone: string, mobile: string, email: string, privacy_email: string, certified_email: string, vat_id: string, tax_code: string, registration_number: string, share_capital: string, invoice_code: string, address: Office|null, offices: list<Office>}
 */
final class Module {

	// Chi modifica i dati aziendali (Permessi). Dalla migrazione 0002, agli amministratori.
	public const CAP = 'barrio_manage_company';

	// In autoload: la legge ogni pagina con il blocco o con lo schema. Nasce dalla migrazione 0001.
	private const OPTION = 'barrio_company';

	// Una sede: la legale (address) o una operativa (offices). Il nome è facoltativo ("Magazzino di Milano").
	public const OFFICE = [
		'name'        => '',
		'street'      => '',
		'postal_code' => '',
		'locality'    => '',
		'region'      => '',
		'country'     => '',
		'maps_url'    => '',
		// I contatti della sede, con l'etichetta facoltativa ("Commerciale").
		'phones'      => [],
		'emails'      => [],
	];

	// address è la sede legale; senza sedi operative è l'unica.
	private const DEFAULTS = [
		'name'                => '',
		'legal_name'          => '',
		'vat_id'              => '',
		'tax_code'            => '',
		'registration_number' => '',
		'share_capital'       => '',
		'invoice_code'        => '',
		'phone'               => '',
		'mobile'              => '',
		'email'               => '',
		'certified_email'     => '',
		'privacy_email'       => '',
		'address'             => self::OFFICE,
		'offices'             => [],
		'profiles'            => [],
	];

	// I dati del blocco senza fields: la riga legale del footer. Uguale al default di assets/block/block.json.
	private const LINE = [ 'legal_name', 'address', 'vat_id', 'tax_code', 'registration_number', 'share_capital' ];

	// I dati della card della Bacheca, nell'ordine in cui si vedono.
	private const CARD = [ 'legal_name', 'vat_id', 'address', 'email' ];

	public static function boot(): void {
		add_action( 'init', [ self::class, 'register_block' ] );
		add_action( 'rest_api_init', [ self::class, 'register' ] );
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_filter( 'barrio_admin_data', [ self::class, 'admin_data' ] );
		// Le estensioni del modulo (WPGraphQL) si collegano qui, come a barrio_hours_loaded.
		add_action( 'init', static fn() => do_action( 'barrio_company_loaded' ), 5 );
	}

	public static function deactivate(): void {}

	// L'opzione la elimina il core (src/Uninstall.php).
	public static function uninstall(): void {
		Roles::remove( [ self::CAP ] );
	}

	public static function can(): bool {
		return current_user_can( self::CAP );
	}

	public static function menu(): void {
		Admin::add_page( 'barrio-company', __( 'Company details', 'barrio-suite' ), self::CAP );
	}

	/**
	 * In window.barrioAdmin.company, per la card della Bacheca, a chi modifica i dati: i dati principali come testo,
	 * scritti come nel blocco ma senza link (la sede in una riga), vuoti se mancano. Senza, il JavaScript sa che il
	 * modulo è spento.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function admin_data( array $data ): array {
		if ( ! self::can() ) {
			return $data;
		}
		$company = self::data();
		$text    = static fn( string $field ): string => wp_specialchars_decode( wp_strip_all_tags( self::value( $field, $company ) ), ENT_QUOTES );
		return $data + [ 'company' => array_combine( self::CARD, array_map( $text, self::CARD ) ) ];
	}

	public static function register(): void {
		register_rest_route(
			'barrio/v1',
			'/company',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'get' ],
					'permission_callback' => [ self::class, 'can' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ self::class, 'put' ],
					'permission_callback' => [ self::class, 'can' ],
					'args'                => self::schema() + [ 'roles' => Roles::schema( [ self::CAP ] ) ],
				],
			]
		);
	}

	public static function register_block(): void {
		add_shortcode( 'barrio_company', static fn( mixed $atts ): string => self::html( (array) $atts ) );
		$block = register_block_type( dirname( __DIR__ ) . '/assets/block', [ 'render_callback' => [ self::class, 'html' ] ] );
		foreach ( $block ? $block->editor_script_handles : [] as $handle ) {
			wp_set_script_translations( $handle, 'barrio-suite', dirname( __DIR__, 3 ) . '/languages' );
		}
	}

	/**
	 * Lo schema JSON di ciò che si scrive: anche la migrazione dal prototipo lo usa.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		$line    = [
			'type'      => 'string',
			'maxLength' => 255,
		];
		$code    = [
			'type'      => 'string',
			'maxLength' => 64,
		];
		$contact = static fn( array $value ): array => [
			'type'  => 'array',
			'items' => [
				'type'                 => 'object',
				'properties'           => [
					'label' => $line,
					'value' => $value,
				],
				'required'             => [ 'label', 'value' ],
				'additionalProperties' => false,
			],
		];
		$email   = [
			'type'  => 'string',
			'anyOf' => [
				[
					'type'   => 'string',
					'format' => 'email',
				],
				[
					'type'      => 'string',
					'maxLength' => 0,
				],
			],
		];
		$office  = [
			'type'                 => 'object',
			'properties'           => [
				'name'        => $line,
				'street'      => $line,
				'postal_code' => $line,
				'locality'    => $line,
				'region'      => $line,
				// ISO 3166-1, come vuole schema.org.
				'country'     => [
					'type'    => 'string',
					'pattern' => '^([A-Z]{2})?$',
				],
				'maps_url'    => [
					'type'      => 'string',
					'maxLength' => 2048,
					'pattern'   => '^(https?://\S+)?$',
				],
				'phones'      => $contact( [ 'minLength' => 1 ] + $code ),
				'emails'      => $contact(
					[
						'type'   => 'string',
						'format' => 'email',
					]
				),
			],
			'required'             => array_keys( self::OFFICE ),
			'additionalProperties' => false,
		];
		return [
			'name'                => $line,
			'legal_name'          => $line,
			'vat_id'              => $code,
			'tax_code'            => $line,
			'registration_number' => $line,
			'share_capital'       => $line,
			'invoice_code'        => $line,
			'phone'               => $code,
			'mobile'              => $code,
			'email'               => $email,
			'certified_email'     => $email,
			'privacy_email'       => $email,
			'address'             => $office,
			'offices'             => [
				'type'  => 'array',
				'items' => $office,
			],
			// I profili social e le schede dell'azienda (Facebook, Instagram, Google Business Profile…).
			'profiles'            => [
				'type'  => 'array',
				'items' => [
					'type'    => 'string',
					'format'  => 'uri',
					'pattern' => '^https?://',
				],
			],
		];
	}

	/** @return Settings */
	public static function settings(): array {
		$settings = array_intersect_key( (array) get_option( self::OPTION, [] ), self::DEFAULTS ) + self::DEFAULTS;
		// Le sedi salvate prima del nome e dei contatti non li hanno.
		$office              = static fn( mixed $place ): array => array_intersect_key( (array) $place, self::OFFICE ) + self::OFFICE;
		$settings['address'] = $office( $settings['address'] );
		$settings['offices'] = array_map( $office, array_values( (array) $settings['offices'] ) );
		/** @var Settings */
		return $settings;
	}

	/**
	 * I dati per il blocco e GraphQL: la ragione sociale vuota vale il nome, una sede senza indirizzo non c'è, anche con
	 * il nome e i contatti.
	 *
	 * @return Data
	 */
	public static function data(): array {
		$data = self::settings();
		unset( $data['profiles'] );
		$data['legal_name'] = $data['legal_name'] ?: $data['name'];
		$data['address']    = self::address( $data['address'] );
		$data['offices']    = array_values( array_filter( array_map( [ self::class, 'address' ], $data['offices'] ) ) );
		return $data;
	}

	/**
	 * @param Office $place
	 * @return Office|null
	 */
	private static function address( array $place ): ?array {
		// Il solo paese non è un indirizzo: line() non lo stampa.
		return array_filter( array_diff_key( $place, array_flip( [ 'name', 'country', 'maps_url', 'phones', 'emails' ] ) ) ) ? $place : null;
	}

	// I Permessi agli amministratori.
	public static function get(): WP_REST_Response {
		$data = self::settings();
		return new WP_REST_Response( current_user_can( 'manage_options' ) ? $data + Roles::get( [ self::CAP ] ) : $data );
	}

	// Un valore assente resta com'è.
	public static function put( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( $request->has_param( 'roles' ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Only administrators can change the permissions.', 'barrio-suite' ), [ 'status' => 403 ] );
		}
		$settings = array_replace( self::settings(), array_intersect_key( $request->get_params(), self::DEFAULTS ) );
		if ( update_option( self::OPTION, $settings, true ) ) {
			do_action( 'barrio_company_changed' );
		}
		if ( is_array( $request['roles'] ) ) {
			Roles::save( [ self::CAP ], $request['roles'] );
		}
		return self::get();
	}

	/**
	 * Il blocco e lo shortcode: i dati scelti, nel loro ordine (fields; senza, la riga legale), in una riga separati da
	 * " · " o in un elenco (layout), con le etichette (labels) tranne i nomi. Ogni sede operativa è un dato a sé, e il nome
	 * di una sede fa da etichetta; i suoi contatti la seguono, nell'elenco sotto. Un dato vuoto si salta; senza dati
	 * niente. Nessun CSS: il testo prende lo stile del tema.
	 *
	 * @param array<mixed> $atts Del blocco (fields è una lista) o dello shortcode (fields separati da virgole).
	 */
	public static function html( array $atts ): string {
		$fields = $atts['fields'] ?? [];
		$fields = array_filter( array_map( 'trim', is_array( $fields ) ? array_map( 'strval', $fields ) : explode( ',', (string) $fields ) ) ) ?: self::LINE;
		$labels = filter_var( $atts['labels'] ?? true, FILTER_VALIDATE_BOOLEAN ) ? self::labels() : [];
		$list   = 'list' === ( $atts['layout'] ?? 'line' );
		$data   = self::data();
		$items  = [];
		foreach ( $fields as $field ) {
			$places = match ( $field ) {
				'address' => array_filter( [ $data['address'] ] ),
				'offices' => $data['offices'],
				default   => null,
			};
			if ( null !== $places ) {
				foreach ( $places as $place ) {
					$label   = $labels ? ( $place['name'] ?: $labels[ $field ] ) : '';
					$item    = '' === $label ? self::office( $place ) : self::pair( $label, self::office( $place ) );
					$items[] = implode( $list ? '<br>' : ' · ', [ $item, ...self::contacts( $place ) ] );
				}
				continue;
			}
			$value = self::value( $field, $data );
			if ( '' !== $value ) {
				$items[] = isset( $labels[ $field ] ) ? self::pair( $labels[ $field ], $value ) : $value;
			}
		}
		if ( ! $items ) {
			return '';
		}
		return $list
			? '<ul class="barrio-company is-list"><li>' . implode( '</li><li>', $items ) . '</li></ul>'
			: '<p class="barrio-company is-line">' . implode( ' · ', $items ) . '</p>';
	}

	/**
	 * Le etichette nel sito, nella lingua della pagina. I nomi non ne hanno: aprono la riga.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return [
			'vat_id'              => __( 'VAT number', 'barrio-suite' ),
			'tax_code'            => __( 'Tax code', 'barrio-suite' ),
			'registration_number' => __( 'REA number', 'barrio-suite' ),
			'share_capital'       => __( 'Share capital', 'barrio-suite' ),
			'certified_email'     => __( 'Certified email', 'barrio-suite' ),
			'invoice_code'        => __( 'SDI code', 'barrio-suite' ),
			'phone'               => __( 'Phone', 'barrio-suite' ),
			'mobile'              => __( 'Mobile', 'barrio-suite' ),
			'email'               => __( 'Email', 'barrio-suite' ),
			'privacy_email'       => __( 'Privacy email', 'barrio-suite' ),
			'address'             => __( 'Registered office', 'barrio-suite' ),
			'offices'             => __( 'Operating office', 'barrio-suite' ),
		];
	}

	/**
	 * Una sede in una riga, come testo: anche la migrazione dal prototipo confronta le sedi così.
	 *
	 * @param array{street: string, postal_code: string, locality: string, region: string, ...} $place
	 */
	public static function line( array $place ): string {
		// ponytail: il formato italiano, senza il paese; un formato per paese se servono sedi all'estero.
		$city = trim( "{$place['postal_code']} {$place['locality']}" . ( '' === $place['region'] ? '' : " ({$place['region']})" ) );
		return implode( ', ', array_filter( [ $place['street'], $city ] ) );
	}

	/**
	 * Un dato in HTML: email e telefoni come link, la sede legale come self::office(). Vuoto per un dato vuoto o che
	 * non esiste.
	 *
	 * @param Data $data
	 */
	private static function value( string $field, array $data ): string {
		if ( 'address' === $field ) {
			return null === $data['address'] ? '' : self::office( $data['address'] );
		}
		$value = $data[ $field ] ?? '';
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		return match ( $field ) {
			'email', 'privacy_email', 'certified_email' => self::link( 'mailto', $value ),
			'phone', 'mobile' => self::link( 'tel', $value ),
			default => esc_html( $value ),
		};
	}

	/**
	 * Una sede in una riga ("Via Roma 1, 80100 Napoli (NA)"), con il link alla mappa.
	 *
	 * @param Office $place
	 */
	private static function office( array $place ): string {
		$text = esc_html( self::line( $place ) );
		return '' === $place['maps_url'] ? $text : sprintf( '<a href="%s">%s</a>', esc_url( $place['maps_url'] ), $text );
	}

	/**
	 * I telefoni e le email di una sede come link, con la loro etichetta.
	 *
	 * @param Office $place
	 * @return list<string>
	 */
	private static function contacts( array $place ): array {
		$items = [];
		foreach ( [ 'phones', 'emails' ] as $list ) {
			foreach ( $place[ $list ] as $contact ) {
				$link    = self::link( 'phones' === $list ? 'tel' : 'mailto', $contact['value'] );
				$items[] = '' === $contact['label'] ? $link : self::pair( $contact['label'], $link );
			}
		}
		return $items;
	}

	private static function link( string $scheme, string $value ): string {
		$href = 'tel' === $scheme ? preg_replace( '/[^0-9+]/', '', $value ) : $value;
		return sprintf( '<a href="%s">%s</a>', esc_url( "{$scheme}:{$href}" ), esc_html( $value ) );
	}

	// $html è già HTML, l'etichetta no.
	private static function pair( string $label, string $html ): string {
		/* translators: 1: name of a company detail, like VAT number; 2: its value. */
		return sprintf( __( '%1$s: %2$s', 'barrio-suite' ), esc_html( $label ), $html );
	}
}
