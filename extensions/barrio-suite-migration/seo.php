<?php
// Il passaggio dal modulo SEO del prototipo: legge le sue opzioni, i meta dei contenuti e dei termini e i
// redirect, e scrive nell'opzione barrio_seo, nei meta _barrio_seo_* e nella tabella dei redirect. La prova a
// vuoto calcola tutto e non scrive niente. Si può rilanciare: ciò che Barrio Suite ha già resta com'è. Finché il
// SEO del prototipo è acceso quello di Barrio Suite resta spento; alla fine si scambiano.

use BarrioSuite\Database\Db;
use BarrioSuite\I18n;
use BarrioSuite\Modules;
use BarrioSuite\Seo\RedirectCode;
use BarrioSuite\Seo\Redirects;
use BarrioSuite\Seo\Repository\RedirectRepository;
use BarrioSuite\Seo\Settings;

defined( 'ABSPATH' ) || exit;

// I meta del prototipo, senza _suite_barrio_seo_ (o il vecchio _wpb_seo_) → quelli di Barrio Suite. sitemap non ha un equivalente.
const BARRIO_MIGRATION_SEO_META = [
	'title'       => 'title',
	'description' => 'description',
	'og_image_id' => 'image',
	'keywords'    => 'keywords',
	'index'       => 'index',
	'follow'      => 'follow',
	'sitemap'     => 'sitemap',
];
const BARRIO_MIGRATION_SEO_SLUG = '/^[a-z0-9_-]{1,32}$/';

function barrio_migration_seo_boot(): void {
	// Altrimenti i meta uscirebbero due volte, e in GraphQL vincerebbero le impostazioni di Barrio Suite, ancora
	// vuote. Una volta sola: chi riaccende il modulo prima della migrazione lo sceglie.
	if ( barrio_migration_seo_prototype() && false === get_option( 'barrio_migration_seo' ) ) {
		Modules::set( 'seo', false );
		update_option( 'barrio_migration_seo', 'held', false );
	}
	add_action( 'admin_notices', 'barrio_migration_seo_notice' );
	add_action( 'barrio_seo_changed', 'barrio_migration_seo_revalidate', 10, 2 );
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command(
			'barrio seo migrate',
			'barrio_migration_seo_command',
			[
				'shortdesc' => 'Moves the SEO settings, content meta and redirects of Suite Barrio to Barrio Suite, then turns the SEO of Suite Barrio off and the one of Barrio Suite on.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Shows the counts and warnings without saving anything.',
					],
				],
			]
		);
	}
}

// Il prototipo è attivo con il suo modulo SEO acceso (Suite Barrio → Strumenti).
function barrio_migration_seo_prototype(): bool {
	return function_exists( 'suite_barrio_tools_module_enabled' ) && suite_barrio_tools_module_enabled( 'seo' );
}

function barrio_migration_seo_notice(): void {
	if ( 'held' === get_option( 'barrio_migration_seo' ) && ! Modules::is_enabled( 'seo' ) && current_user_can( 'manage_options' ) ) {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			sprintf(
				/* translators: 1: dry run command, 2: migration command. */
				esc_html__( 'The SEO module of Barrio Suite stays off until the SEO of Suite Barrio is migrated: run %1$s, then %2$s. Turned on before, it prints the meta tags twice.', 'barrio-suite-migration' ),
				'<code>wp barrio seo migrate --dry-run</code>',
				'<code>wp barrio seo migrate</code>'
			)
		);
	}
}

// Il ponte verso la revalidation di Next.js del prototipo, finché è attivo, con i suoi tag.
function barrio_migration_seo_revalidate( string $what, int $id = 0 ): void {
	if ( ! function_exists( 'suite_barrio_invalidate_vercel_cache' ) ) {
		return;
	}
	$tags = match ( $what ) {
		// Il noindex di un contenuto lo toglie anche dalla sitemap.
		'post'      => [ $id, 'wp:sitemap' ],
		'redirects' => [ 'wp:redirects' ],
		default     => [ 'wp:seo,wp:schema,wp:sitemap,wp:llms' ],
	};
	foreach ( $tags as $tag ) {
		suite_barrio_invalidate_vercel_cache( $tag );
	}
}

/**
 * @param list<string>          $args
 * @param array<string, string> $assoc
 */
function barrio_migration_seo_command( array $args, array $assoc ): void {
	$dry_run = isset( $assoc['dry-run'] );
	$report  = barrio_migration_seo_run( $dry_run );
	if ( $report instanceof WP_Error ) {
		WP_CLI::error( $report->get_error_message() );
	}
	WP_CLI\Utils\format_items( 'table', $report['counts'], [ 'data', 'found', 'migrated', 'default', 'skipped' ] );
	foreach ( $report['notes'] as $note ) {
		WP_CLI::log( $note );
	}
	foreach ( $report['warnings'] as $warning ) {
		WP_CLI::warning( $warning );
	}
	WP_CLI::success(
		$dry_run
			? 'Dry run: nothing was saved. Run the command without --dry-run to migrate.'
			: 'Migration complete: the SEO of Suite Barrio is off and the one of Barrio Suite is on. Empty the page cache.'
	);
}

/**
 * In found ci sono i valori del prototipo: migrated quelli scritti, default quelli uguali al default del tipo (che
 * restano assenti, cioè "predefinito"), skipped quelli non validi o che Barrio Suite ha già.
 *
 * @return array{counts: list<array{data: string, found: int, migrated: int, default: int, skipped: int}>, notes: list<string>, warnings: list<string>}|WP_Error
 */
function barrio_migration_seo_run( bool $dry_run ): array|WP_Error {
	$report  = [
		'counts'   => [],
		'notes'    => [],
		'warnings' => [],
	];
	$options = array_filter( [ 'suite_barrio_meta_settings', 'suite_barrio_meta_content', 'suite_barrio_schema_settings', 'suite_barrio_redirects' ], static fn( string $name ): bool => false !== get_option( $name ) );
	$content = (array) get_option( 'suite_barrio_meta_content', [] );
	$meta    = [ ...barrio_migration_seo_meta( 'post', $content, $report ), ...barrio_migration_seo_meta( 'term', $content, $report ) ];
	if ( ! $options && ! $report['counts'] ) {
		return new WP_Error( 'barrio_migration_no_prototype', 'There are no SEO settings or meta of Suite Barrio in this database.' );
	}

	// Le tabelle del modulo, anche spento: le crea la sua prima accensione, ma qui si scrive prima.
	$migrator = Modules::migrator( 'seo' );
	$error    = $dry_run ? null : $migrator->run();
	if ( $error || ( ! $dry_run && ! $migrator->is_current() ) ) {
		return $error ?? new WP_Error( 'barrio_migration_tables', 'The tables of the Barrio Suite SEO module are being created by another request: run the command again.' );
	}
	$redirects = barrio_migration_seo_redirects( $migrator->is_current(), $report );
	$settings  = barrio_migration_seo_settings( $report );

	if ( array_filter( array_map( 'get_option', [ 'suite_barrio_advisor_api_key', 'suite_barrio_advisor_gemini_key', 'suite_barrio_llms_api_key', 'suite_barrio_llms_gemini_key' ] ) ) ) {
		$report['warnings'][] = 'The AI keys of Suite Barrio are not migrated: Barrio Suite does not have its AI extension yet.';
	}
	// Il web server li servirebbe al posto di quelli di Barrio Suite; solo se li ha scritti il prototipo.
	$files = get_option( 'suite_barrio_llms_last_generated' ) ? array_filter( [ ABSPATH . 'llms.txt', ABSPATH . 'llms-full.txt' ], 'is_file' ) : [];
	if ( $files ) {
		$report['notes'][] = ( $dry_run ? 'To delete: ' : 'Deleted: ' ) . implode( ', ', $files ) . '.';
	}
	if ( $dry_run ) {
		return $report;
	}

	Db::transaction(
		static function () use ( $meta, $redirects ): void {
			foreach ( $meta as [ $type, $id, $key, $value ] ) {
				// update_metadata() toglie gli slash.
				update_metadata( $type, $id, "_barrio_seo_{$key}", wp_slash( $value ) );
			}
			foreach ( $redirects as $redirect ) {
				RedirectRepository::create( $redirect['source'], $redirect['target'], $redirect['code'], $redirect['note'], $redirect['is_auto'] );
			}
		}
	);
	if ( null !== $settings ) {
		Settings::save( $settings['sections'] );
		$graphql = (array) get_option( 'graphql_general_settings', [] );
		if ( '' !== $settings['frontend'] && empty( $graphql['barrio_frontend_url'] ) ) {
			update_option( 'graphql_general_settings', [ 'barrio_frontend_url' => $settings['frontend'] ] + $graphql );
		}
	}
	foreach ( $files as $file ) {
		wp_delete_file( $file );
	}
	update_option( 'suite_barrio_tools_modules', [ 'seo' => 0 ] + (array) get_option( 'suite_barrio_tools_modules', [] ) );
	Modules::set( 'seo', true );
	update_option( 'barrio_migration_seo', 'migrated', false );
	// Finché il prototipo è attivo, il ponte fa rileggere tutto al frontend headless.
	barrio_migration_seo_revalidate( 'settings' );
	barrio_migration_seo_revalidate( 'redirects' );
	return $report;
}

/**
 * I meta SEO del prototipo dei contenuti o dei termini, nel formato di Barrio Suite. Un index o follow uguale a
 * quello del tipo o della tassonomia diventa "predefinito": il prototipo li scriveva a ogni salvataggio.
 *
 * @param 'post'|'term' $type
 * @param array<mixed>  $content suite_barrio_meta_content: index e follow per tipo e tassonomia.
 * @param array{counts: list<array{data: string, found: int, migrated: int, default: int, skipped: int}>, notes: list<string>, warnings: list<string>} $report
 * @return list<array{0: 'post'|'term', 1: int, 2: string, 3: mixed}> Tipo, ID, chiave senza prefisso, valore.
 */
function barrio_migration_seo_meta( string $type, array $content, array &$report ): array {
	global $wpdb;
	$suite   = $wpdb->esc_like( '_suite_barrio_seo_' ) . '%';
	$wpb     = $wpdb->esc_like( '_wpb_seo_' ) . '%';
	$rows    = Db::rows(
		'post' === $type
			? $wpdb->prepare( 'SELECT m.post_id AS id, m.meta_key, m.meta_value, o.post_type AS subtype FROM %i m JOIN %i o ON o.ID = m.post_id WHERE m.meta_key LIKE %s OR m.meta_key LIKE %s OR m.meta_key = %s ORDER BY m.meta_id', $wpdb->postmeta, $wpdb->posts, $suite, $wpb, '_suite_barrio_schema_collection' )
			: $wpdb->prepare( 'SELECT m.term_id AS id, m.meta_key, m.meta_value, o.taxonomy AS subtype FROM %i m JOIN %i o ON o.term_id = m.term_id WHERE m.meta_key LIKE %s OR m.meta_key LIKE %s OR m.meta_key = %s ORDER BY m.meta_id', $wpdb->termmeta, $wpdb->term_taxonomy, $suite, $wpb, '_suite_barrio_schema_collection' )
	);
	$objects = [];
	foreach ( $rows as $row ) {
		$name = (string) $row['meta_key'];
		$old  = str_starts_with( $name, '_wpb_seo_' );
		$key  = '_suite_barrio_schema_collection' === $name ? 'collection' : ( BARRIO_MIGRATION_SEO_META[ substr( $name, $old ? 9 : 18 ) ] ?? null );
		$id   = (int) $row['id'];
		// _suite_barrio_seo_ è il nome più recente, se ci sono tutti e due.
		if ( null !== $key && ( ! $old || ! isset( $objects[ $id ]['values'][ $key ] ) ) ) {
			$objects[ $id ]['subtype']        = (string) $row['subtype'];
			$objects[ $id ]['values'][ $key ] = (string) $row['meta_value'];
		}
	}

	$group   = 'post' === $type ? 'post_types' : 'taxonomies';
	$counts  = [];
	$writes  = [];
	$outside = [];
	foreach ( $objects as $id => $object ) {
		$defaults = (array) ( $content[ $group ][ $object['subtype'] ] ?? [] );
		$indexed  = (bool) ( '' !== ( $object['values']['index'] ?? '' ) ? (int) $object['values']['index'] : ( $defaults['index'] ?? 1 ) );
		foreach ( $object['values'] as $key => $raw ) {
			$value = match ( $key ) {
				'title', 'description' => sanitize_text_field( $raw ),
				'image'                => 'attachment' === get_post_type( (int) $raw ) ? (int) $raw : '',
				'keywords'             => barrio_migration_seo_keywords( $raw ),
				'index'                => '' === $raw ? null : ( $raw ? 'index' : 'noindex' ),
				'follow'               => '' === $raw ? null : ( $raw ? 'follow' : 'nofollow' ),
				'collection'           => post_type_exists( $raw ) && preg_match( '/^[a-z0-9_-]{1,20}$/', $raw ) ? $raw : '',
				// Fuori dalla sitemap ma indicizzabile: in Barrio Suite in sitemap c'è ciò che è indicizzabile.
				'sitemap'              => '0' === $raw && $indexed ? '' : null,
			};
			$status = match ( true ) {
				null === $value || ( in_array( $key, [ 'index', 'follow' ], true ) && (bool) ( $defaults[ $key ] ?? 1 ) === (bool) (int) $raw ) => 'default',
				in_array( $value, [ '', [] ], true ) || metadata_exists( $type, $id, "_barrio_seo_{$key}" ) => 'skipped',
				default => 'migrated',
			};
			$counts[ $key ] ??= [
				'found'    => 0,
				'migrated' => 0,
				'default'  => 0,
				'skipped'  => 0,
			];
			++$counts[ $key ]['found'];
			++$counts[ $key ][ $status ];
			if ( 'migrated' === $status ) {
				$writes[] = [ $type, $id, $key, $value ];
			} elseif ( 'sitemap' === $key && 'skipped' === $status ) {
				$outside[] = $id;
			}
		}
	}
	foreach ( [ ...array_values( BARRIO_MIGRATION_SEO_META ), 'collection' ] as $key ) {
		if ( isset( $counts[ $key ] ) ) {
			$report['counts'][] = [ 'data' => "{$type} {$key}" ] + $counts[ $key ];
		}
	}
	if ( $outside ) {
		$report['warnings'][] = ( 'post' === $type ? 'Contents' : 'Terms' ) . ' out of the sitemap but indexable, now in the sitemap: set them to noindex if they must stay out. IDs: ' . implode( ', ', $outside ) . '.';
	}
	return $writes;
}

/**
 * Le parole chiave del prototipo, in JSON (o separate da virgole): una lista senza vuoti e senza doppioni.
 *
 * @return list<string>
 */
function barrio_migration_seo_keywords( string $raw ): array {
	$list = json_decode( $raw, true );
	$list = is_array( $list ) ? $list : explode( ',', $raw );
	return array_values( array_unique( array_filter( array_map( static fn( mixed $keyword ): string => is_scalar( $keyword ) ? sanitize_text_field( (string) $keyword ) : '', $list ) ) ) );
}

/**
 * I redirect del prototipo nel formato della tabella. Non passano quelli non validi, i doppioni (resta il primo) e
 * quelli in un ciclo, che nel prototipo finivano in "troppi reindirizzamenti".
 *
 * @param array{counts: list<array{data: string, found: int, migrated: int, default: int, skipped: int}>, notes: list<string>, warnings: list<string>} $report
 * @return list<array{source: string, target: string|null, code: RedirectCode, note: string|null, is_auto: bool}>
 */
function barrio_migration_seo_redirects( bool $table, array &$report ): array {
	$rows    = array_filter( (array) get_option( 'suite_barrio_redirects', [] ), 'is_array' );
	$valid   = [];
	$skipped = [];
	foreach ( $rows as $row ) {
		$from   = trim( (string) ( $row['from'] ?? '' ) );
		$source = Redirects::path( $from );
		$code   = RedirectCode::tryFrom( (int) ( $row['code'] ?? 301 ) );
		// Il prototipo salvava un 410 con to='/'.
		$target = RedirectCode::Gone === $code ? null : trim( (string) ( $row['to'] ?? '' ) );
		$note   = trim( (string) ( $row['note'] ?? '' ) );
		$reason = match ( true ) {
			'' === $from || mb_strlen( $source ) > 768 => 'invalid source',
			null === $code => 'invalid code',
			null !== $target && ( ! preg_match( '#^(/|https?://)#', $target ) || mb_strlen( $target ) > 2048 ) => 'invalid target',
			isset( $valid[ $source ] ) => 'duplicate',
			$table && null !== RedirectRepository::find( $source ) => 'already in Barrio Suite',
			default => null,
		};
		if ( null !== $reason ) {
			$skipped[ $reason ][] = '' === $from ? '(empty)' : $from;
			continue;
		}
		$auto             = 'Aggiunto automaticamente al cambio slug' === $note;
		$valid[ $source ] = [
			'source'  => $source,
			'target'  => $target,
			'code'    => $code,
			'note'    => $auto || '' === $note ? null : mb_substr( $note, 0, 255 ),
			'is_auto' => $auto,
		];
	}

	// I cicli si cercano tra i percorsi: un URL assoluto chiude la catena.
	$next = [];
	foreach ( $valid as $source => $redirect ) {
		if ( str_starts_with( (string) $redirect['target'], '/' ) ) {
			$next[ $source ] = Redirects::path( (string) $redirect['target'] );
		}
	}
	$limit = count( $next );
	foreach ( array_keys( $next ) as $source ) {
		$at = $next[ $source ];
		for ( $steps = 1; $at !== $source && isset( $next[ $at ] ) && $steps < $limit; ++$steps ) {
			$at = $next[ $at ];
		}
		if ( $at === $source ) {
			$skipped['loop'][] = $source;
			unset( $valid[ $source ] );
		}
	}

	$report['counts'][] = [
		'data'     => 'redirects',
		'found'    => count( $rows ),
		'migrated' => count( $valid ),
		'default'  => 0,
		'skipped'  => count( $rows ) - count( $valid ),
	];
	foreach ( $skipped as $reason => $sources ) {
		$report['warnings'][] = "Redirects not migrated ({$reason}): " . implode( ', ', $sources ) . '.';
	}
	return array_values( $valid );
}

/**
 * Le opzioni del prototipo come sezioni di barrio_seo, validate con il loro schema; null se Barrio Suite ha già le
 * sue impostazioni. frontend è l'indirizzo del frontend headless, se c'è.
 *
 * @param array{counts: list<array{data: string, found: int, migrated: int, default: int, skipped: int}>, notes: list<string>, warnings: list<string>} $report
 * @return array{sections: array<string, mixed>, frontend: string}|null
 */
function barrio_migration_seo_settings( array &$report ): ?array {
	if ( false !== get_option( 'barrio_seo' ) ) {
		$report['notes'][] = 'Settings: Barrio Suite already has its SEO settings, they stay as they are.';
		return null;
	}
	$meta    = (array) get_option( 'suite_barrio_meta_settings', [] );
	$content = (array) get_option( 'suite_barrio_meta_content', [] );
	$schema  = array_filter( (array) get_option( 'suite_barrio_schema_settings', [] ) );
	$socials = [ 'facebook', 'instagram', 'twitter', 'linkedin', 'behance', 'youtube', 'telegram', 'tiktok' ];
	if ( ! $schema ) {
		// Senza schema, i dati aziendali del prototipo (Info Azienda).
		$company = (array) get_option( 'suite_barrio_company_info', [] );
		$schema  = array_filter(
			[
				'business_name'  => $company['ragione_sociale'] ?? '',
				'url'            => $company['sito_web'] ?? '',
				'telephone'      => $company['telefono'] ?? '',
				'email'          => $company['email'] ?? '',
				'vat_id'         => $company['partita_iva'] ?? '',
				'street_address' => $company['sede_via'] ?? '',
				'postal_code'    => $company['sede_cap'] ?? '',
				'locality'       => $company['sede_citta'] ?? '',
				'region'         => $company['sede_provincia'] ?? '',
			] + array_intersect_key( $company, array_flip( $socials ) )
		);
	}
	$main     = I18n::languages()[0];
	$settings = Settings::get();
	$schemas  = Settings::schema();
	$line     = static fn( mixed $value, int $max = 255 ): string => mb_substr( sanitize_text_field( (string) $value ), 0, $max );
	$lines    = static fn( mixed $value ): array => array_values( array_filter( array_map( static fn( string $item ): string => mb_substr( trim( $item ), 0, 255 ), preg_split( '/\R/', (string) $value ) ?: [] ) ) );
	$media    = static function ( mixed $url, string $what ) use ( &$report ): int {
		$id = '' === trim( (string) $url ) ? 0 : attachment_url_to_postid( (string) $url );
		if ( '' !== trim( (string) $url ) && ! $id ) {
			$report['warnings'][] = "Organization: the {$what} {$url} is not in the media library, choose it in SEO → Organization.";
		}
		return $id;
	};
	$point    = static function ( mixed $value, int $max ): ?float {
		$value = str_replace( ',', '.', trim( (string) $value ) );
		return is_numeric( $value ) && abs( (float) $value ) <= $max ? (float) $value : null;
	};

	// Titoli: separatore e ordine passano tali e quali. Nome e icona del sito erano già blogname e site_icon.
	$separator                 = trim( (string) ( $meta['separator'] ?? '' ) );
	$settings['title']         = [
		'separator' => '' !== $separator && mb_strlen( $separator ) <= 5 ? $separator : $settings['title']['separator'],
		'order'     => 'site_page' === ( $meta['title_order'] ?? '' ) ? 'site_page' : 'page_site',
	];
	$image                     = (int) ( ( $meta['og_image_id'] ?? 0 ) ?: get_option( 'suite_barrio_meta_settings_og_image_id', 0 ) );
	$settings['default_image'] = 'attachment' === get_post_type( $image ) ? $image : 0;

	foreach ( [ 'post_types', 'taxonomies' ] as $group ) {
		foreach ( (array) ( $content[ $group ] ?? [] ) as $slug => $robots ) {
			if ( is_array( $robots ) && preg_match( BARRIO_MIGRATION_SEO_SLUG, (string) $slug ) ) {
				$settings['index'][ $group ][ $slug ] = [
					'index'  => (bool) ( $robots['index'] ?? true ),
					'follow' => (bool) ( $robots['follow'] ?? true ),
				];
			}
		}
	}
	// Il prototipo non aveva gli archivi degli autori, che i frontend headless non mostrano.
	$settings['index']['archives']['author']['index'] = false;

	$type     = (string) ( $schema['business_type'] ?? '' );
	$email    = sanitize_email( (string) ( $schema['email'] ?? '' ) );
	$country  = strtoupper( trim( (string) ( $schema['country'] ?? '' ) ) );
	$currency = strtoupper( trim( (string) ( $schema['offer_currency'] ?? '' ) ) );
	$offers   = [];
	foreach ( (array) ( $schema['offers'] ?? [] ) as $offer ) {
		$offer = (array) $offer;
		$name  = $line( $offer['name'] ?? '' );
		if ( '' === $name ) {
			continue;
		}
		$price = str_replace( ',', '.', trim( (string) ( $offer['price'] ?? '' ) ) );
		if ( '' !== $price && ! is_numeric( $price ) ) {
			$report['warnings'][] = "Organization, offer \"{$name}\": the price \"{$price}\" is not a number, it is left empty.";
		}
		$url      = esc_url_raw( (string) ( $offer['url'] ?? '' ) );
		$offers[] = [
			'type'         => 'Product' === ( $offer['type'] ?? '' ) ? 'Product' : 'Service',
			'name'         => $name,
			'description'  => sanitize_textarea_field( (string) ( $offer['description'] ?? '' ) ),
			'category'     => $line( $offer['category'] ?? '' ),
			'url'          => preg_match( '#^https?://.#', $url ) ? $url : '',
			'image'        => $media( $offer['image'] ?? '', "image of the offer \"{$name}\"" ),
			'price'        => is_numeric( $price ) && (float) $price >= 0 ? (float) $price : null,
			'availability' => in_array( $offer['availability'] ?? '', [ 'InStock', 'OutOfStock', 'PreOrder', 'LimitedAvailability', 'Discontinued' ], true ) ? $offer['availability'] : '',
		];
	}
	$settings['organization'] = [
		'type'        => in_array( $type, $schemas['organization']['properties']['type']['enum'], true ) ? $type : 'LocalBusiness',
		'name'        => $line( $schema['business_name'] ?? '' ),
		'logo'        => $media( $schema['logo'] ?? '', 'logo' ),
		'image'       => $media( $schema['image'] ?? '', 'image' ),
		'phone'       => $line( $schema['telephone'] ?? '', 64 ),
		'email'       => is_email( $email ) ? $email : '',
		'vat_id'      => $line( $schema['vat_id'] ?? '', 64 ),
		'address'     => [
			'street'      => $line( $schema['street_address'] ?? '' ),
			'postal_code' => $line( $schema['postal_code'] ?? '' ),
			'locality'    => $line( $schema['locality'] ?? '' ),
			'region'      => $line( $schema['region'] ?? '' ),
			'country'     => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
		],
		'geo'         => [
			'latitude'  => $point( $schema['latitude'] ?? '', 90 ),
			'longitude' => $point( $schema['longitude'] ?? '', 180 ),
		],
		// "Tipo: Nome", come nel prototipo. Text e GeoShape non sono luoghi con un nome: diventano Place. City, State e
		// Country il prototipo non li riconosceva e scriveva "City: Napoli" come nome di un Place.
		'area_served' => array_map(
			static function ( string $item ): array {
				[ $type, $name ] = array_pad( array_map( 'trim', explode( ':', $item, 2 ) ), 2, '' );
				$type            = in_array( $type, [ 'Text', 'GeoShape' ], true ) ? 'Place' : $type;
				return '' !== $name && in_array( $type, [ 'Place', 'AdministrativeArea', 'City', 'State', 'Country' ], true ) ? [
					'type' => $type,
					'name' => $name,
				] : [
					'type' => 'Place',
					'name' => $item,
				];
			},
			$lines( $schema['area_served'] ?? '' )
		),
		'knows_about' => $lines( $schema['knows_about'] ?? '' ),
		'same_as'     => array_values( array_filter( array_map( static fn( string $social ): string => esc_url_raw( (string) ( $schema[ $social ] ?? '' ), [ 'http', 'https' ] ), $socials ) ) ),
		'offers'      => [
			'name'     => $line( $schema['offer_catalog_name'] ?? '' ),
			'currency' => preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'EUR',
			'items'    => $offers,
		],
	];

	// Come llms.txt del prototipo: 'include_links' è 'links'.
	$modes = [
		'post_types' => 'suite_barrio_llms_pt_modes',
		'taxonomies' => 'suite_barrio_llms_tax_modes',
	];
	foreach ( $modes as $group => $option ) {
		foreach ( (array) get_option( $option, [] ) as $slug => $mode ) {
			$mode = 'include_links' === $mode ? 'links' : $mode;
			if ( preg_match( BARRIO_MIGRATION_SEO_SLUG, (string) $slug ) && in_array( $mode, [ 'include', 'links', 'exclude' ], true ) ) {
				$settings['llms'][ $group ][ $slug ] = $mode;
			}
		}
	}

	$settings['texts'][ $main ] = [
		'default_description'      => sanitize_textarea_field( (string) ( $meta['default_desc'] ?? '' ) ),
		'organization_description' => sanitize_textarea_field( (string) ( $schema['description'] ?? '' ) ),
		'llms_intro'               => barrio_migration_seo_llms_intro(),
	];

	foreach ( $settings as $section => $value ) {
		$valid = rest_validate_value_from_schema( $value, $schemas[ $section ], $section );
		if ( is_wp_error( $valid ) ) {
			$report['warnings'][] = "Settings, {$section}: " . $valid->get_error_message() . ' It keeps the default of Barrio Suite.';
			unset( $settings[ $section ] );
		}
	}
	$report['notes'][] = 'Settings: ' . implode( ', ', array_keys( $settings ) ) . " (texts in {$main}).";
	// Nel prototipo la lingua dello schema era un'impostazione; in Barrio Suite è quella del sito.
	$language = (string) ( $schema['language'] ?? '' );
	$site     = get_bloginfo( 'language' );
	if ( '' !== $language && strtok( strtolower( $language ), '-' ) !== strtok( strtolower( $site ), '-' ) ) {
		$report['warnings'][] = "Schema: the language was {$language}, Barrio Suite uses the one of the site ({$site}). If it is wrong, change it in Settings → General.";
	}

	// Il prototipo metteva nello schema l'indirizzo del frontend headless al posto di quello di WordPress.
	$url      = trim( (string) ( $schema['url'] ?? '' ) );
	$url      = '' === $url ? '' : untrailingslashit( esc_url_raw( preg_match( '#^https?://#i', $url ) ? $url : "https://{$url}", [ 'http', 'https' ] ) );
	$frontend = untrailingslashit( home_url() ) === $url ? '' : $url;
	if ( '' !== $frontend ) {
		$report['notes'][] = "Frontend address (GraphQL → Settings): {$frontend}.";
	}
	return [
		'sections' => $settings,
		'frontend' => $frontend,
	];
}

// L'introduzione che l'AI aveva scritto nel llms.txt del prototipo, tra l'URL del sito e la prima sezione.
function barrio_migration_seo_llms_intro(): string {
	$file = ABSPATH . 'llms.txt';
	$text = is_file( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- un file locale.
	return preg_match( '/\A# [^\n]*\n\n> [^\n]*\n\n(.+?)\n(?:\n## |\n---\n|\z)/s', $text, $match ) ? trim( $match[1] ) : '';
}
