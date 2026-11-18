<?php

namespace BarrioSuite\Seo;

use BarrioSuite\I18n;

/**
 * Le impostazioni SEO del sito, nell'opzione barrio_seo: le scrive solo PUT /barrio/v1/seo/settings
 * (Api/RestSettings.php), una sezione alla volta, dopo averla validata con schema(). Nome e icona del
 * sito restano le opzioni di WordPress (blogname, site_icon), senza copie. Le immagini sono ID di
 * allegati, 0 = nessuna.
 */
final class Settings {

	private const OPTION = 'barrio_seo';

	// Le 16 voci del prototipo.
	private const ORGANIZATION_TYPES = [ 'LocalBusiness', 'Organization', 'Restaurant', 'Hotel', 'Store', 'MedicalBusiness', 'LegalService', 'FinancialService', 'InteriorDesigner', 'Architect', 'Attorney', 'Dentist', 'Physician', 'RealEstateAgent', 'LodgingBusiness', 'ProfessionalService' ];

	// Una chiave assente nelle mappe per tipo e per tassonomia vale indicizzato e segui; per llms vedi Llms::preset().
	private const DEFAULTS = [
		'title'          => [
			'separator' => '—',
			'order'     => 'page_site',
		],
		'default_image'  => 0,
		'index'          => [
			// Le pagine degli allegati: i siti nati prima di WordPress 6.4 le hanno ancora (wp_attachment_pages_enabled).
			'post_types' => [
				'attachment' => [
					'index'  => false,
					'follow' => true,
				],
			],
			'taxonomies' => [],
			// Gli archivi per data ripetono i contenuti degli articoli.
			'archives'   => [
				'author' => [
					'index'  => true,
					'follow' => true,
				],
				'date'   => [
					'index'  => false,
					'follow' => true,
				],
			],
		],
		'organization'   => [
			'type'        => 'LocalBusiness',
			'name'        => '',
			'logo'        => 0,
			'image'       => 0,
			'phone'       => '',
			'email'       => '',
			'vat_id'      => '',
			'address'     => [
				'street'      => '',
				'postal_code' => '',
				'locality'    => '',
				'region'      => '',
				'country'     => '',
			],
			'geo'         => [
				'latitude'  => null,
				'longitude' => null,
			],
			'area_served' => [],
			'knows_about' => [],
			'same_as'     => [],
			'offers'      => [
				'name'     => '',
				'currency' => 'EUR',
				'items'    => [],
			],
		],
		'llms'           => [
			'post_types' => [],
			'taxonomies' => [],
		],
		'auto_redirects' => true,
		// {lingua: {default_description, organization_description, llms_intro}}: nessun testo ha una lingua implicita.
		'texts'          => [],
	];

	/** @return array<string, mixed> */
	public static function get(): array {
		return array_replace_recursive( self::DEFAULTS, (array) get_option( self::OPTION, [] ) );
	}

	// Un testo di texts nella lingua $lang; vuoto, quello della lingua principale.
	public static function text( string $name, string $lang ): string {
		$texts = self::get()['texts'];
		return (string) ( ( $texts[ $lang ][ $name ] ?? '' ) ?: ( $texts[ I18n::languages()[0] ][ $name ] ?? '' ) );
	}

	/** @param array<string, mixed> $sections Già validate con schema(); quelle assenti restano come sono. */
	public static function save( array $sections ): void {
		update_option( self::OPTION, array_intersect_key( $sections, self::DEFAULTS ) + (array) get_option( self::OPTION, [] ), true );
	}

	/** @return array<string, array<string, mixed>> JSON Schema di ogni sezione, per gli args della rotta. */
	public static function schema(): array {
		$text   = [ 'type' => 'string' ];
		$line   = [
			'type'      => 'string',
			'maxLength' => 255,
		];
		$image  = [
			'type'    => 'integer',
			'minimum' => 0,
		];
		$slug   = '^[a-z0-9_-]{1,32}$';
		$robots = self::object(
			[
				'index'  => [ 'type' => 'boolean' ],
				'follow' => [ 'type' => 'boolean' ],
			]
		);
		$llms   = [
			'type' => 'string',
			'enum' => [ 'include', 'links', 'exclude' ],
		];

		return [
			'title'          => self::object(
				[
					'separator' => [
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 5,
					],
					'order'     => [
						'type' => 'string',
						'enum' => [ 'page_site', 'site_page' ],
					],
				]
			),
			'default_image'  => $image,
			'index'          => self::object(
				[
					'post_types' => self::map( $slug, $robots ),
					'taxonomies' => self::map( $slug, $robots ),
					'archives'   => self::object(
						[
							'author' => $robots,
							'date'   => $robots,
						]
					),
				]
			),
			'organization'   => self::object(
				[
					'type'        => [
						'type' => 'string',
						'enum' => self::ORGANIZATION_TYPES,
					],
					'name'        => $line,
					'logo'        => $image,
					'image'       => $image,
					'phone'       => [
						'type'      => 'string',
						'maxLength' => 64,
					],
					'email'       => [
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
					],
					'vat_id'      => [
						'type'      => 'string',
						'maxLength' => 64,
					],
					'address'     => self::object(
						[
							'street'      => $line,
							'postal_code' => $line,
							'locality'    => $line,
							'region'      => $line,
							// ISO 3166-1, come vuole schema.org.
							'country'     => [
								'type'    => 'string',
								'pattern' => '^([A-Z]{2})?$',
							],
						]
					),
					'geo'         => self::object(
						[
							'latitude'  => [
								'type'    => [ 'number', 'null' ],
								'minimum' => -90,
								'maximum' => 90,
							],
							'longitude' => [
								'type'    => [ 'number', 'null' ],
								'minimum' => -180,
								'maximum' => 180,
							],
						]
					),
					// Text e GeoShape del prototipo non sono luoghi con un nome per schema.org.
					'area_served' => self::list(
						self::object(
							[
								'type' => [
									'type' => 'string',
									'enum' => [ 'Place', 'AdministrativeArea', 'City', 'State', 'Country' ],
								],
								'name' => [ 'minLength' => 1 ] + $line,
							]
						)
					),
					'knows_about' => self::list( [ 'minLength' => 1 ] + $line ),
					// I profili social e le schede del sito (Facebook, Instagram, LinkedIn…).
					'same_as'     => self::list(
						[
							'type'    => 'string',
							'format'  => 'uri',
							'pattern' => '^https?://',
						]
					),
					'offers'      => self::object(
						[
							'name'     => $line,
							'currency' => [
								'type'    => 'string',
								'pattern' => '^[A-Z]{3}$',
							],
							'items'    => self::list(
								self::object(
									[
										'type'         => [
											'type' => 'string',
											'enum' => [ 'Service', 'Product' ],
										],
										'name'         => [ 'minLength' => 1 ] + $line,
										'description'  => $text,
										'category'     => $line,
										'url'          => [
											'type'    => 'string',
											'pattern' => '^(https?://.+)?$',
										],
										'image'        => $image,
										'price'        => [
											'type'    => [ 'number', 'null' ],
											'minimum' => 0,
										],
										'availability' => [
											'type' => 'string',
											'enum' => [ '', 'InStock', 'OutOfStock', 'PreOrder', 'LimitedAvailability', 'Discontinued' ],
										],
									]
								)
							),
						]
					),
				]
			),
			'llms'           => self::object(
				[
					'post_types' => self::map( $slug, $llms ),
					'taxonomies' => self::map( $slug, $llms ),
				]
			),
			'auto_redirects' => [ 'type' => 'boolean' ],
			'texts'          => self::map(
				'^[a-z]{2,3}(-[a-z0-9]{2,8})*$',
				self::object(
					[
						'default_description'      => $text,
						'organization_description' => $text,
						'llms_intro'               => $text,
					]
				)
			),
		];
	}

	/**
	 * Tutte le proprietà sono obbligatorie: ciò che si salva è sempre completo.
	 *
	 * @param array<string, array<string, mixed>> $properties
	 * @return array<string, mixed>
	 */
	private static function object( array $properties ): array {
		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		];
	}

	/**
	 * Un oggetto con chiavi libere che rispettano $pattern (slug, codici di lingua).
	 *
	 * @param array<string, mixed> $value
	 * @return array<string, mixed>
	 */
	private static function map( string $pattern, array $value ): array {
		return [
			'type'                 => 'object',
			'patternProperties'    => [ $pattern => $value ],
			'additionalProperties' => false,
		];
	}

	/**
	 * @param array<string, mixed> $items
	 * @return array<string, mixed>
	 */
	private static function list( array $items ): array {
		return [
			'type'  => 'array',
			'items' => $items,
		];
	}
}
