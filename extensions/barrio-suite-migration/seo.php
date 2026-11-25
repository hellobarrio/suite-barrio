<?php
// Il passaggio dal modulo SEO del prototipo: legge le sue opzioni, i meta dei contenuti e dei termini e i
// redirect, e scrive nell'opzione barrio_seo, nei meta _barrio_seo_* e nella tabella dei redirect. La prova a
// vuoto calcola tutto e non scrive niente. Si può rilanciare: ciò che Barrio Suite ha già resta com'è. Finché il
// SEO del prototipo è acceso quello di Barrio Suite resta spento; alla fine si scambiano.

use BarrioSuite\Ai;
use BarrioSuite\Company\Module as Company;
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
// Le opzioni SEO del prototipo in tutte le sue versioni, anche quelle che non si migrano: le altre danno un avviso.
const BARRIO_MIGRATION_SEO_OPTIONS     = [ 'suite_barrio_meta_settings', 'suite_barrio_meta_settings_og_image_id', 'suite_barrio_meta_content', 'suite_barrio_schema_settings', 'suite_barrio_sitemap_settings', 'suite_barrio_redirects', 'suite_barrio_llms_api_key', 'suite_barrio_llms_gemini_key', 'suite_barrio_llms_provider', 'suite_barrio_llms_model', 'suite_barrio_llms_language', 'suite_barrio_llms_tone', 'suite_barrio_llms_focus', 'suite_barrio_llms_pt_modes', 'suite_barrio_llms_tax_modes', 'suite_barrio_llms_last_generated', 'suite_barrio_llms_log', 'suite_barrio_llms_log_enabled', 'suite_barrio_advisor_api_key', 'suite_barrio_advisor_gemini_key', 'suite_barrio_advisor_provider', 'suite_barrio_advisor_model', 'suite_barrio_advisor_language', 'suite_barrio_advisor_tone', 'suite_barrio_advisor_style', 'suite_barrio_advisor_context', 'suite_barrio_advisor_keywords', 'suite_barrio_advisor_log', 'suite_barrio_advisor_log_enabled' ];
const BARRIO_MIGRATION_SEO_SOCIALS     = [ 'facebook', 'instagram', 'twitter', 'linkedin', 'behance' ];
const BARRIO_MIGRATION_SEO_SCHEMA_KEYS = [ 'business_type', 'business_name', 'description', 'url', 'logo', 'image', 'telephone', 'email', 'vat_id', 'street_address', 'postal_code', 'locality', 'region', 'country', 'latitude', 'longitude', 'facebook', 'instagram', 'twitter', 'linkedin', 'behance', 'language', 'area_served', 'knows_about', 'offer_catalog_name', 'offer_currency', 'offers' ];

function barrio_migration_seo_boot(): void {
	// Altrimenti i meta uscirebbero due volte, e in GraphQL vincerebbero le impostazioni di Barrio Suite, ancora
	// vuote. Una volta sola: chi riaccende il modulo prima della migrazione lo sceglie.
	if ( barrio_migration_prototype( 'seo' ) && false === get_option( 'barrio_migration_seo' ) ) {
		Modules::set( 'seo', false );
		update_option( 'barrio_migration_seo', 'held' );
	}
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
	// Con il SEO del prototipo acceso ma mai configurato si prosegue: senza lo scambio quello di Barrio Suite resterebbe spento.
	if ( ! $options && ! $report['counts'] && ! barrio_migration_prototype( 'seo' ) ) {
		return new WP_Error( 'barrio_migration_no_prototype', 'There are no SEO settings or meta of Suite Barrio in this database.' );
	}
	barrio_migration_seo_unknown( $report['warnings'] );

	// Le tabelle del modulo, anche spento: le crea la sua prima accensione, ma qui si scrive prima.
	$migrator = Modules::migrator( 'seo' );
	$error    = $dry_run ? null : $migrator->run();
	if ( $error || ( ! $dry_run && ! $migrator->is_current() ) ) {
		return $error ?? new WP_Error( 'barrio_migration_tables', 'The tables of the Barrio Suite SEO module are being created by another request: migrate again.' );
	}
	$redirects = barrio_migration_seo_redirects( $migrator->is_current(), $report );
	$settings  = barrio_migration_seo_settings( $report );

	$ai = barrio_migration_seo_ai( $report );
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
		// Solo nei campi di Dati aziendali ancora vuoti: quelli scritti a mano restano.
		$company = Company::settings();
		$empty   = static fn( mixed $value ): bool => ! array_filter( (array) $value );
		update_option( 'barrio_company', array_replace( $company, array_filter( $settings['company'], static fn( mixed $value, string $key ): bool => $empty( $company[ $key ] ), ARRAY_FILTER_USE_BOTH ) ), true );
		if ( '' !== $settings['frontend'] ) {
			update_option( 'barrio_frontend', [ 'url' => $settings['frontend'] ] + (array) get_option( 'barrio_frontend', [] ), true );
		}
	}
	if ( null !== $ai ) {
		Ai::save( [ 'instructions' => $ai ] );
	}
	foreach ( $files as $file ) {
		wp_delete_file( $file );
	}
	update_option( 'suite_barrio_tools_modules', [ 'seo' => 0 ] + (array) get_option( 'suite_barrio_tools_modules', [] ) );
	Modules::set( 'seo', true );
	update_option( 'barrio_migration_seo', 'migrated' );
	// Il frontend headless rilegge tutto (la revalidation di barrio-suite-wpgraphql).
	do_action( 'barrio_seo_changed', 'settings' );
	do_action( 'barrio_seo_changed', 'redirects' );
	return $report;
}

/**
 * Le opzioni SEO e le loro chiavi che il prototipo non ha mai avuto. Dei redirect un avviso per chiave, non per riga.
 *
 * @param list<string> $warnings
 */
function barrio_migration_seo_unknown( array &$warnings ): void {
	barrio_migration_unknown_options( 'SEO', [ 'suite_barrio_meta_', 'suite_barrio_schema_', 'suite_barrio_sitemap_', 'suite_barrio_redirects', 'suite_barrio_llms_', 'suite_barrio_advisor_', 'suite_barrio_seo_' ], BARRIO_MIGRATION_SEO_OPTIONS, $warnings );
	barrio_migration_unknown( 'SEO, suite_barrio_meta_settings', (array) get_option( 'suite_barrio_meta_settings', [] ), [ 'separator', 'title_order', 'site_title', 'default_desc', 'og_image_id', 'favicon_id', 'robots_default', 'twitter_card' ], $warnings );
	$content = (array) get_option( 'suite_barrio_meta_content', [] );
	barrio_migration_unknown( 'SEO, suite_barrio_meta_content', $content, [ 'post_types', 'taxonomies' ], $warnings );
	foreach ( [ 'post_types', 'taxonomies' ] as $group ) {
		foreach ( (array) ( $content[ $group ] ?? [] ) as $slug => $robots ) {
			barrio_migration_unknown( "SEO, suite_barrio_meta_content, {$slug}", (array) $robots, [ 'index', 'follow' ], $warnings );
		}
	}
	$schema = (array) get_option( 'suite_barrio_schema_settings', [] );
	barrio_migration_unknown( 'SEO, suite_barrio_schema_settings', $schema, BARRIO_MIGRATION_SEO_SCHEMA_KEYS, $warnings );
	foreach ( array_values( (array) ( $schema['offers'] ?? [] ) ) as $n => $offer ) {
		barrio_migration_unknown( 'SEO, suite_barrio_schema_settings, offer ' . ( $n + 1 ), (array) $offer, [ 'type', 'name', 'description', 'category', 'url', 'image', 'price', 'availability' ], $warnings );
	}
	$redirects = [];
	foreach ( array_filter( (array) get_option( 'suite_barrio_redirects', [] ), 'is_array' ) as $row ) {
		$redirects += array_filter( $row );
	}
	barrio_migration_unknown( 'SEO, suite_barrio_redirects', $redirects, [ 'id', 'from', 'to', 'code', 'note' ], $warnings );
}

/**
 * Contesto, settore e parole chiave dell'advisor e di llms.txt del prototipo diventano la descrizione del sito
 * dell'AI, se Barrio Suite non ne ha già una. Le chiavi API non passano: Barrio Suite usa un service account.
 *
 * @param array{counts: list<array{data: string, found: int, migrated: int, default: int, skipped: int}>, notes: list<string>, warnings: list<string>} $report
 */
function barrio_migration_seo_ai( array &$report ): ?string {
	if ( array_filter( array_map( 'get_option', [ 'suite_barrio_advisor_api_key', 'suite_barrio_advisor_gemini_key', 'suite_barrio_llms_api_key', 'suite_barrio_llms_gemini_key' ] ) ) ) {
		$report['warnings'][] = 'AI: the API keys of Suite Barrio are not migrated. Barrio Suite connects to Google with a service account: add it in Barrio → Settings and choose the model.';
	}
	$keywords     = trim( (string) get_option( 'suite_barrio_advisor_keywords' ) );
	$instructions = implode(
		"\n",
		array_filter(
			[
				trim( (string) get_option( 'suite_barrio_advisor_context' ) ),
				trim( (string) get_option( 'suite_barrio_llms_focus' ) ),
				'' === $keywords ? '' : "Parole chiave da includere se pertinenti: {$keywords}",
			]
		)
	);
	if ( '' === $instructions ) {
		return null;
	}
	if ( '' !== Ai::settings()['instructions'] ) {
		$report['notes'][] = 'AI: Barrio Suite already has its description of the site, it stays as it is.';
		return null;
	}
	$report['notes'][] = 'AI: the context and the keywords of the SEO advisor become the description of the site.';
	return mb_substr( $instructions, 0, 2000 );
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
	$unknown = [];
	foreach ( $rows as $row ) {
		$name = (string) $row['meta_key'];
		$old  = str_starts_with( $name, '_wpb_seo_' );
		$key  = '_suite_barrio_schema_collection' === $name ? 'collection' : ( BARRIO_MIGRATION_SEO_META[ substr( $name, $old ? 9 : 18 ) ] ?? null );
		$id   = (int) $row['id'];
		if ( null === $key && '' !== (string) $row['meta_value'] ) {
			$unknown[ $name ] = true;
		}
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
	barrio_migration_unknown( 'SEO, ' . ( 'post' === $type ? 'content' : 'term' ) . ' meta', $unknown, [], $report['warnings'] );
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
			null !== $target && ( ! preg_match( Redirects::TARGET, $target ) || mb_strlen( $target ) > 2048 ) => 'invalid target',
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

	// Tutti i redirect di un ciclo restano fuori.
	$next  = static fn( string $path ): ?string => $valid[ $path ]['target'] ?? null;
	$loops = array_filter( array_keys( $valid ), static fn( string $source ): bool => Redirects::loops( $source, $valid[ $source ]['target'], $next ) );
	foreach ( $loops as $source ) {
		$skipped['loop'][] = $source;
		unset( $valid[ $source ] );
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
 * Le opzioni del prototipo come sezioni di barrio_seo e come anagrafica di Dati aziendali (company), validate con il
 * loro schema; null se Barrio Suite ha già le sue impostazioni. frontend è l'indirizzo del frontend headless, se c'è.
 *
 * @param array{counts: list<array{data: string, found: int, migrated: int, default: int, skipped: int}>, notes: list<string>, warnings: list<string>} $report
 * @return array{sections: array<string, mixed>, company: array<string, mixed>, frontend: string}|null
 */
function barrio_migration_seo_settings( array &$report ): ?array {
	if ( get_option( 'barrio_seo' ) ) {
		$report['notes'][] = 'Settings: Barrio Suite already has its SEO settings, they stay as they are.';
		return null;
	}
	$meta     = (array) get_option( 'suite_barrio_meta_settings', [] );
	$content  = (array) get_option( 'suite_barrio_meta_content', [] );
	$schema   = array_filter( (array) get_option( 'suite_barrio_schema_settings', [] ) );
	$company  = array_filter( (array) get_option( 'suite_barrio_company_info', [] ) );
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
	$types    = $schemas['organization']['properties']['type']['enum'];
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
	// Architect e InteriorDesigner, che il prototipo offriva, non sono tipi di schema.org.
	if ( '' !== $type && ! in_array( $type, $types, true ) ) {
		$report['warnings'][] = "Organization: Barrio Suite does not have the type \"{$type}\", it becomes LocalBusiness. Choose another one in SEO → Organization.";
	}
	$settings['organization'] = [
		'type'        => in_array( $type, $types, true ) ? $type : 'LocalBusiness',
		'logo'        => $media( $schema['logo'] ?? '', 'logo' ),
		'image'       => $media( $schema['image'] ?? '', 'image' ),
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
	// Con Info Azienda l'anagrafica la scrive il suo passaggio, e lo schema riempie solo ciò che lì manca: su molti
	// siti lo schema era ancora quello del modello, mentre Info Azienda era ciò che il sito mostrava.
	if ( $company ) {
		$report['notes'][] = 'Company details: they come from Info Azienda, in the Company details step.';
	}
	// Nel prototipo la lingua dello schema era un'impostazione; in Barrio Suite è quella del sito.
	$language = (string) ( $schema['language'] ?? '' );
	$site     = get_bloginfo( 'language' );
	if ( '' !== $language && strtok( strtolower( $language ), '-' ) !== strtok( strtolower( $site ), '-' ) ) {
		$report['warnings'][] = "Schema: the language was {$language}, Barrio Suite uses the one of the site ({$site}). If it is wrong, change it in Settings → General.";
	}

	// Il prototipo metteva nello schema l'indirizzo del frontend headless al posto di quello di WordPress, ma faceva la
	// revalidation sul sito web di Info Azienda: se c'è, lo prende il passaggio dei Dati aziendali.
	$frontend = empty( $company['sito_web'] ) ? barrio_migration_frontend( (string) ( $schema['url'] ?? '' ) ) : '';
	if ( '' !== $frontend ) {
		$report['notes'][] = "Frontend address (Barrio → Settings): {$frontend}.";
	}
	return [
		'sections' => $settings,
		'company'  => $company ? [] : barrio_migration_seo_company( $schema, $report['warnings'] ),
		'frontend' => $frontend,
	];
}

/**
 * Nome, contatti, partita IVA, indirizzo e profili dello schema del prototipo, per Dati aziendali, ognuno validato con
 * il suo schema: uno non valido resta vuoto, con un avviso.
 *
 * @param array<string, mixed> $schema
 * @param list<string>         $warnings
 * @return array<string, mixed>
 */
function barrio_migration_seo_company( array $schema, array &$warnings ): array {
	$line    = static fn( mixed $value, int $max = 255 ): string => mb_substr( sanitize_text_field( (string) $value ), 0, $max );
	$email   = sanitize_email( (string) ( $schema['email'] ?? '' ) );
	$country = strtoupper( trim( (string) ( $schema['country'] ?? '' ) ) );
	$company = [
		'name'     => $line( $schema['business_name'] ?? '' ),
		'phone'    => $line( $schema['telephone'] ?? '', 64 ),
		'email'    => is_email( $email ) ? $email : '',
		'vat_id'   => $line( $schema['vat_id'] ?? '', 64 ),
		'address'  => [
			'name'        => '',
			'street'      => $line( $schema['street_address'] ?? '' ),
			'postal_code' => $line( $schema['postal_code'] ?? '' ),
			'locality'    => $line( $schema['locality'] ?? '' ),
			'region'      => $line( $schema['region'] ?? '' ),
			'country'     => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
			'maps_url'    => '',
			'phones'      => [],
			'emails'      => [],
		],
		'profiles' => array_values( array_filter( array_map( static fn( string $social ): string => esc_url_raw( (string) ( $schema[ $social ] ?? '' ), [ 'http', 'https' ] ), BARRIO_MIGRATION_SEO_SOCIALS ) ) ),
	];
	$fields  = Company::schema();
	return array_filter(
		$company,
		static function ( mixed $value, string $key ) use ( $fields, &$warnings ): bool {
			$valid = rest_validate_value_from_schema( $value, $fields[ $key ], $key );
			if ( is_wp_error( $valid ) ) {
				$warnings[] = "Company details, {$key}: " . $valid->get_error_message() . ' It is left empty.';
			}
			return ! is_wp_error( $valid );
		},
		ARRAY_FILTER_USE_BOTH
	);
}

// L'introduzione che l'AI aveva scritto nel llms.txt del prototipo, tra l'URL del sito e la prima sezione.
function barrio_migration_seo_llms_intro(): string {
	$file = ABSPATH . 'llms.txt';
	$text = is_file( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- un file locale.
	return preg_match( '/\A# [^\n]*\n\n> [^\n]*\n\n(.+?)\n(?:\n## |\n---\n|\z)/s', $text, $match ) ? trim( $match[1] ) : '';
}
