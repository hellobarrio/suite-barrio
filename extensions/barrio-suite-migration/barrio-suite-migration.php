<?php
/**
 * Plugin Name:       Barrio Suite Migration
 * Description:       Moves forms, entries, files, settings and shortcodes from Suite Barrio, the previous plugin, with the WP-CLI command wp barrio forms migrate. Once done, it can be removed.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Hellobarrio
 * License:           GPL-2.0-or-later
 * Text Domain:       barrio-suite-migration
 * Domain Path:       /languages
 * Requires Plugins:  barrio-suite
 */

// Il passaggio dal modulo Forms del prototipo: legge le sue tabelle wpb_* e le sue opzioni e scrive
// con i Repository di Barrio Suite, che del prototipo non sa niente. Ogni form passa in una
// transazione, con lo stesso ID; la prova a vuoto (--dry-run) è la stessa importazione, annullata
// alla fine. I dati del prototipo restano dove sono, tranne i file caricati, che lasciano la cartella
// pubblica degli upload per quella protetta. IP, user agent e referrer non si importano. Alla fine
// gli shortcode del prototipo nei contenuti diventano [barrio_form]: poi l'estensione si può eliminare.

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Conditions;
use BarrioSuite\Forms\FieldTypes;
use BarrioSuite\Forms\Frontend\Render;
use BarrioSuite\Forms\Integrations;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Model\Integration;
use BarrioSuite\Forms\Model\Notification;
use BarrioSuite\Forms\Notifications;
use BarrioSuite\Forms\Privacy;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use BarrioSuite\Forms\Submission\FileStore;
use BarrioSuite\I18n;
use BarrioSuite\Mail\Smtp;

defined( 'ABSPATH' ) || exit;

load_plugin_textdomain( 'barrio-suite-migration', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

const BARRIO_MIGRATION_TYPES   = [ 'text', 'email', 'textarea', 'tel', 'number', 'datepicker', 'select', 'radio', 'checkbox', 'file', 'page_break' ];
const BARRIO_MIGRATION_CHOICES = [ 'select', 'radio', 'checkbox' ];
// Le estensioni che il prototipo elencava senza controllarle: un campo file di Barrio Suite vuole l'elenco.
const BARRIO_MIGRATION_EXTENSIONS = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip' ];

add_action(
	'barrio_forms_loaded',
	static function (): void {
		// Per gli shortcode che la migrazione non riscrive (nei file del tema) o non ancora riscritti. Dopo il
		// prototipo, che li registra al caricamento: se il suo modulo Forms è acceso restano suoi.
		add_action( 'init', 'barrio_migration_shortcodes', 20 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command(
				'barrio forms migrate',
				'barrio_migration_command',
				[
					'shortdesc' => 'Moves the forms, entries, files and settings of Suite Barrio to Barrio Suite.',
					'synopsis'  => [
						[
							'type'        => 'flag',
							'name'        => 'dry-run',
							'optional'    => true,
							'description' => 'Runs the whole migration and rolls it back: nothing is saved and no file is moved.',
						],
					],
				]
			);
		}
	}
);

function barrio_migration_shortcodes(): void {
	foreach ( [ 'suite_barrio_form', 'wpb_form' ] as $tag ) {
		if ( ! shortcode_exists( $tag ) ) {
			add_shortcode( $tag, static fn( mixed $atts ): string => Render::form( (int) ( ( (array) $atts )['id'] ?? 0 ) ) );
		}
	}
}

/**
 * @param list<string>          $args
 * @param array<string, string> $assoc
 */
function barrio_migration_command( array $args, array $assoc ): void {
	$dry_run = isset( $assoc['dry-run'] );
	$report  = barrio_migration_run( $dry_run );
	if ( $report instanceof WP_Error ) {
		WP_CLI::error( $report->get_error_message() );
	}
	if ( $report['forms'] ) {
		WP_CLI\Utils\format_items( 'table', $report['forms'], [ 'id', 'title', 'entries', 'migrated', 'files', 'missing_files', 'result' ] );
	}
	foreach ( $report['notes'] as $note ) {
		WP_CLI::log( $note );
	}
	foreach ( $report['warnings'] as $warning ) {
		WP_CLI::warning( $warning );
	}
	$failed = count( array_filter( $report['forms'], static fn( array $form ): bool => str_starts_with( $form['result'], 'error' ) ) );
	if ( $failed ) {
		WP_CLI::error( "{$failed} forms were not migrated (the others are" . ( $dry_run ? ' ready' : ' done' ) . '): fix them and run the command again. The shortcodes in the pages are rewritten once every form is in Barrio Suite.' );
	}

	// Gli shortcode del prototipo diventano [barrio_form] con lo stesso ID, ovunque WordPress li salvi:
	// contenuti, dati dei page builder, widget (search-replace gestisce i dati serializzati). Solo con
	// tutti i form migrati, perché un ID rimasto indietro mostrerebbe un altro form.
	foreach ( [ 'suite_barrio_form', 'wpb_form' ] as $tag ) {
		WP_CLI::runcommand( "search-replace '[{$tag}' '[barrio_form' --skip-columns=guid --report-changed-only" . ( $dry_run ? ' --dry-run' : '' ), [ 'launch' => false ] );
	}
	WP_CLI::success(
		$dry_run
			? 'Dry run: nothing was saved. Run the command without --dry-run to migrate.'
			: 'Migration complete. Turn off the Forms module of Suite Barrio and empty the page cache. This extension is no longer needed, unless a theme file calls the old shortcodes (search it for suite_barrio_form and wpb_form).'
	);
}

/**
 * Tutti i form, poi le impostazioni globali. Si può rilanciare: i form già migrati si saltano e le
 * impostazioni si copiano solo dove mancano.
 *
 * @return array{forms: list<array{id: int, title: string, entries: int, migrated: int, files: int, missing_files: int, result: string}>, notes: list<string>, warnings: list<string>}|WP_Error
 */
function barrio_migration_run( bool $dry_run ): array|WP_Error {
	global $wpdb;
	$tables = [
		'forms'   => "{$wpdb->prefix}wpb_forms",
		'entries' => "{$wpdb->prefix}wpb_form_submissions",
		// Dalla versione 1.1 del modulo: prima i form avevano una lingua sola.
		'i18n'    => "{$wpdb->prefix}wpb_forms_translations",
	];
	$exists = static fn( string $table ): bool => Db::var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	if ( ! $exists( $tables['forms'] ) || ! $exists( $tables['entries'] ) ) {
		return new WP_Error( 'barrio_migration_no_prototype', 'The tables of the Suite Barrio forms are not in this database.' );
	}
	if ( ! $exists( $tables['i18n'] ) ) {
		$tables['i18n'] = null;
	}

	$main   = I18n::languages()[0];
	$report = [
		'forms'    => [],
		'notes'    => [],
		'warnings' => [],
	];
	if ( function_exists( 'suite_barrio_forms_get' ) ) {
		$report['warnings'][] = 'The Forms module of Suite Barrio is still on: what it receives from now on stays there. Turn it off in Suite Barrio → Tools.';
	}
	$forms     = Db::rows( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $tables['forms'] ) );
	$languages = [ $main => true ];
	foreach ( $forms as $row ) {
		$report['forms'][] = barrio_migration_form( $row, $tables, $main, $dry_run, $report['warnings'], $languages );
	}
	$report['notes'][] = 1 === count( $languages ) ? "Language: {$main}." : 'Languages: ' . implode( ', ', array_keys( $languages ) ) . " ({$main} is the main one).";

	$orphans = (int) Db::var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE form_id NOT IN (SELECT id FROM %i)', $tables['entries'], $tables['forms'] ) );
	if ( $orphans ) {
		$report['warnings'][] = "Entries of forms deleted in Suite Barrio, not migrated: {$orphans}.";
	}
	barrio_migration_settings( $forms, $dry_run, $report );
	return $report;
}

/**
 * Un form con le sue registrazioni, in una transazione: o tutto o niente.
 *
 * @param array<string, string|null>                         $row
 * @param array{forms: string, entries: string, i18n: ?string} $tables
 * @param list<string>                                       $warnings
 * @param array<string, true>                                $languages
 * @return array{id: int, title: string, entries: int, migrated: int, files: int, missing_files: int, result: string}
 */
function barrio_migration_form( array $row, array $tables, string $main, bool $dry_run, array &$warnings, array &$languages ): array {
	global $wpdb;
	$id    = (int) $row['id'];
	$title = mb_substr( trim( (string) $row['title'] ), 0, 191 ) ?: "Form {$id}";
	$count = static fn(): int => (int) Db::var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE form_id = %d', $tables['entries'], $id ) );
	$stats = [
		'id'            => $id,
		'title'         => $title,
		'entries'       => $count(),
		'migrated'      => 0,
		'files'         => 0,
		'missing_files' => 0,
		'result'        => '',
	];
	if ( null !== FormRepository::get( $id ) ) {
		// Un altro form con questo ID prenderebbe il posto di questo nelle pagine e nei frontend.
		$stats['result'] = in_array( $id, array_map( 'intval', (array) get_option( 'barrio_migration_forms', [] ) ), true ) ? 'skipped: already migrated' : 'error: another Barrio Suite form has this ID';
		return $stats;
	}

	// I testi del prototipo in ogni lingua, completati con quelli della principale.
	$fields   = barrio_migration_json( $row['fields'] );
	$settings = barrio_migration_json( $row['settings'] );
	$texts    = [ $main => [ $fields, $settings ] ];
	if ( null !== $tables['i18n'] ) {
		foreach ( Db::rows( $wpdb->prepare( 'SELECT lang, fields_i18n, settings_i18n FROM %i WHERE form_id = %d', $tables['i18n'], $id ) ) as $translation ) {
			$lang = barrio_migration_lang( (string) $translation['lang'], $main );
			if ( $main !== $lang ) {
				$texts[ $lang ] = barrio_migration_translate( $fields, $settings, barrio_migration_json( $translation['fields_i18n'] ), barrio_migration_json( $translation['settings_i18n'] ) );
			}
		}
	}
	$languages += array_fill_keys( array_keys( $texts ), true );
	$form       = barrio_migration_definition( $title, $texts, $warnings );

	// La versione 1 è l'archivio: anche i campi che oggi non ci sono più ma che le registrazioni hanno.
	$extras = [];
	$used   = array_fill_keys( array_map( static fn( Field $field ): string => $field->key, $form['fields'] ), true );
	foreach ( barrio_migration_submissions( $tables['entries'], $id ) as $submission ) {
		foreach ( $submission['data'] as $name => $item ) {
			$name = (string) $name;
			$file = str_starts_with( $name, '_file_' );
			if ( ! is_array( $item ) || ( str_starts_with( $name, '_' ) && ! $file ) ) {
				continue; // _page_url, _page_title, _lang: servizio, non campi.
			}
			if ( ! isset( $form['targets'][ $name ] ) ) {
				$key                      = barrio_migration_key( $file ? substr( $name, 6 ) : $name, $used );
				$form['targets'][ $name ] = $key;
				$extras[ $key ]           = [
					'type'       => $file ? 'file' : 'textarea',
					'label'      => wp_strip_all_tags( (string) ( $item['label'] ?? $name ) ),
					'extensions' => [],
				];
				$warnings[]               = "Form \"{$title}\": the field \"{$extras[ $key ]['label']}\" is only in old entries, kept in version 1 as \"{$key}\".";
			}
			$key = $form['targets'][ $name ];
			if ( $file && isset( $extras[ $key ] ) ) {
				$extension = strtolower( pathinfo( (string) ( $item['value'] ?? '' ), PATHINFO_EXTENSION ) );
				if ( preg_match( '/^[a-z0-9]+$/', $extension ) ) {
					$extras[ $key ]['extensions'][ $extension ] = $extension;
				}
			}
		}
	}
	$archive = $form['fields'];
	foreach ( $extras as $key => $extra ) {
		$archive[] = new Field( $key, $extra['type'], [ $main => [ 'label' => $extra['label'] ] ], false, 'file' === $extra['type'] ? [ 'extensions' => array_values( $extra['extensions'] ) ?: BARRIO_MIGRATION_EXTENSIONS ] : [] );
	}

	$stored    = []; // Le copie nella cartella protetta, da eliminare se la transazione si annulla.
	$originals = []; // I file negli upload, da eliminare dopo il commit.
	$dry_done  = false; // L'unica eccezione che non è un errore: il rollback voluto della prova a vuoto.
	try {
		Db::transaction(
			static function () use ( $id, $title, $form, $archive, $extras, $tables, $main, $dry_run, $count, &$stats, &$stored, &$originals, &$dry_done ): void {
				$saved   = FormRepository::create( $title, $form['i18n'], $form['notifications'], new FormVersion( $archive ), $form['integrations'], $id );
				$version = $saved->current;
				$fields  = [];
				foreach ( $version->fields as $field ) {
					$fields[ $field->key ] = $field;
				}
				foreach ( barrio_migration_submissions( $tables['entries'], $id ) as $submission ) {
					EntryRepository::create( barrio_migration_entry( $submission, $id, $version, $fields, $form, $main, $dry_run, $stats['files'], $stats['missing_files'], $stored, $originals ) );
				}
				// La versione 2 è la definizione attuale; la 1 resta solo se qualche registrazione la usa.
				if ( $extras ) {
					$current = FormRepository::save( $id, $version->version, $title, $form['i18n'], $form['notifications'], new FormVersion( $form['fields'] ), $form['integrations'] );
					if ( $current instanceof WP_Error ) {
						throw new RuntimeException( esc_html( $current->get_error_message() ) );
					}
				}
				// I conteggi dal database, prima del commit: nella stessa transazione le due tabelle si leggono allo stesso istante.
				$stats['entries']  = $count();
				$stats['migrated'] = EntryRepository::page( $id, [], 'id', 'desc', 1, 1 )['total'];
				if ( $stats['migrated'] !== $stats['entries'] ) {
					throw new RuntimeException( esc_html( "{$stats['entries']} entries in Suite Barrio, {$stats['migrated']} in Barrio Suite." ) );
				}
				if ( $dry_run ) {
					$dry_done = true;
					throw new RuntimeException( 'Dry run: rolled back.' );
				}
			}
		);
	} catch ( Throwable $e ) {
		foreach ( $stored as $path ) {
			FileStore::delete( $path );
		}
		// La cache di FormRepository ha ancora il form che il rollback ha tolto.
		wp_cache_delete( $id, FormRepository::CACHE_GROUP );
		if ( ! $dry_done ) {
			$stats['result'] = 'error: ' . $e->getMessage();
			return $stats;
		}
	}
	foreach ( $originals as $file ) {
		wp_delete_file( $file );
	}
	if ( ! $dry_run ) {
		update_option( 'barrio_migration_forms', [ ...(array) get_option( 'barrio_migration_forms', [] ), $id ], false );
	}
	$stats['result'] = $dry_run ? 'ready' : 'migrated';
	return $stats;
}

/**
 * Campi, testi, notifiche e integrazioni di Barrio Suite da quelli del prototipo.
 *
 * @param non-empty-array<string, array{0: array<mixed>, 1: array<mixed>}> $texts Campi e impostazioni per lingua, la principale per prima.
 * @param list<string>                                                     $warnings
 * @return array{fields: list<Field>, targets: array<string, string>, choices: array<string, array<string, array<string, string>>>, i18n: array<string, array{submit_label: string, success_message: string|null, success_url: string|null}>, notifications: list<Notification>, integrations: list<Integration>}
 */
function barrio_migration_definition( string $title, array $texts, array &$warnings ): array {
	[ $fields, $settings ] = reset( $texts );
	$keys                  = []; // Nome nel prototipo → chiave.
	$targets               = []; // Chiave nei dati delle registrazioni (il nome, o _file_ e il nome) → chiave.
	$choices               = []; // Per chiave e lingua: etichetta inviata → valore della scelta.
	$used                  = [];
	$draft                 = [];
	foreach ( array_values( $fields ) as $i => $field ) {
		$field = (array) $field;
		$type  = (string) ( $field['type'] ?? 'text' );
		$name  = (string) ( $field['name'] ?? '' );
		$label = (string) ( $field['label'] ?? '' );
		if ( ! in_array( $type, BARRIO_MIGRATION_TYPES, true ) ) {
			$warnings[] = "Form \"{$title}\", field \"{$label}\": the type \"{$type}\" does not exist in Barrio Suite, it is now a text field.";
			$type       = 'text';
		}
		$key = barrio_migration_key( 'page_break' === $type ? 'page_break' : ( $name ?: $label ), $used );
		if ( 'page_break' !== $type && '' !== $name && $key !== $name ) {
			$warnings[] = "Form \"{$title}\": the field \"{$name}\" is now \"{$key}\", in merge tags too. Update a headless frontend that has the name in its code.";
		}
		if ( 'page_break' !== $type && '' !== $name ) {
			$keys    += [
				$name                 => $key,
				sanitize_key( $name ) => $key,
			];
			$targets += [ ( 'file' === $type ? '_file_' : '' ) . $name => $key ];
		}

		// La checkbox della privacy, con il link nell'unica scelta, diventa un consenso: Barrio Suite mostra
		// l'HTML solo nell'etichetta di un consenso, e il testo con il link resta nella versione come prova.
		$main_options = (array) ( $field['options'] ?? [] );
		if ( 'checkbox' === $type && 1 === count( $main_options ) && wp_strip_all_tags( (string) reset( $main_options ) ) !== trim( (string) reset( $main_options ) ) ) {
			$type       = 'consent';
			$warnings[] = "Form \"{$title}\", field \"{$label}\": the checkbox with a link is now a Consent field.";
		}

		// Il valore di una scelta è la sua etichetta nella lingua principale, senza HTML: è quello che il
		// prototipo salvava, quindi le registrazioni e le regole delle condizioni restano uguali.
		$values = [];
		foreach ( in_array( $type, BARRIO_MIGRATION_CHOICES, true ) ? $main_options : [] as $o => $option ) {
			$value = mb_substr( sanitize_text_field( wp_strip_all_tags( (string) $option ) ), 0, 255 );
			if ( '' !== $value && ! in_array( $value, $values, true ) ) {
				$values[ $o ] = $value;
			}
			if ( wp_strip_all_tags( (string) $option ) !== trim( (string) $option ) ) {
				$warnings[] = "Form \"{$title}\", field \"{$label}\": the choice \"{$value}\" had HTML (a link?), Barrio Suite shows it as text.";
			}
		}
		$config = match ( $type ) {
			// Nel prototipo un limite vuoto era ''.
			'number' => array_map( 'floatval', array_filter( array_intersect_key( $field, array_flip( [ 'min', 'max' ] ) ), 'is_numeric' ) ),
			'file'   => [ 'extensions' => BARRIO_MIGRATION_EXTENSIONS ],
			default  => $values ? [ 'choices' => array_values( $values ) ] : [],
		};
		if ( in_array( $type, BARRIO_MIGRATION_CHOICES, true ) && ! $values ) {
			$warnings[] = "Form \"{$title}\", field \"{$label}\": no choices, it is now a text field.";
			$type       = 'text';
		}

		$i18n = [];
		foreach ( $texts as $lang => [ $lang_fields ] ) {
			if ( 'page_break' === $type ) {
				$i18n[ $lang ] = [ 'label' => I18n::in( $lang, static fn(): string => __( 'Next', 'barrio-suite-migration' ) ) ];
				continue;
			}
			$text = (array) ( array_values( $lang_fields )[ $i ] ?? $field );
			if ( 'consent' === $type ) {
				$translated    = (array) ( $text['options'] ?? [] );
				$i18n[ $lang ] = [ 'label' => FieldTypes::label_html( (string) ( reset( $translated ) ?: reset( $main_options ) ) ) ];
				continue;
			}
			$i18n[ $lang ] = [
				'label'         => (string) ( $text['label'] ?? '' ),
				'placeholder'   => mb_substr( (string) ( $text['placeholder'] ?? '' ), 0, 255 ) ?: null,
				'error_message' => (string) ( $text['error_message'] ?? '' ) ?: null,
			];
			if ( $values ) {
				$options = (array) ( $text['options'] ?? [] );
				foreach ( $values as $o => $value ) {
					$option                             = wp_strip_all_tags( (string) ( $options[ $o ] ?? '' ) ?: (string) $main_options[ $o ] );
					$i18n[ $lang ]['options'][ $value ] = mb_substr( $option, 0, 1000 );
					$choices[ $key ][ $lang ][ sanitize_text_field( $option ) ] = $value;
				}
				$choices[ $key ][ $lang ] += array_combine( $values, $values );
			}
		}
		$draft[] = [ $key, $type, $i18n, ! empty( $field['required'] ) && 'page_break' !== $type, $config, $field['conditionalLogic'] ?? null ];
	}

	// Le condizioni dopo tutti i campi: una regola può leggere un campo che viene dopo.
	$fields = [];
	foreach ( $draft as [ $key, $type, $i18n, $required, $config, $logic ] ) {
		$rules = [];
		foreach ( is_array( $logic ) && ! empty( $logic['enabled'] ) ? (array) ( $logic['rules'] ?? [] ) : [] as $rule ) {
			$field = $keys[ (string) ( $rule['fieldName'] ?? '' ) ] ?? null;
			if ( null !== $field ) {
				$rules[] = [
					'field'    => $field,
					'operator' => in_array( $rule['operator'] ?? '', Conditions::OPERATORS, true ) ? $rule['operator'] : '=',
					'value'    => (string) ( $rule['value'] ?? '' ),
				];
			}
		}
		$conditions = $rules ? [
			'action' => 'hide' === ( $logic['action'] ?? '' ) ? 'hide' : 'show',
			'logic'  => 'any' === ( $logic['logicType'] ?? '' ) ? 'any' : 'all',
			'rules'  => $rules,
		] : null;
		$fields[]   = new Field( $key, $type, $i18n, $required, $config, $conditions );
	}

	// I merge tag {{nome}} con le chiavi nuove; quelli sconosciuti restano e diventano vuoti.
	$tags   = static fn( mixed $text ): string => (string) preg_replace_callback( '/\{\{([^}]+)\}\}/', static fn( array $m ): string => isset( $keys[ trim( $m[1] ) ] ) ? '{{' . $keys[ trim( $m[1] ) ] . '}}' : $m[0], trim( (string) $text ) );
	$key_of = static fn( mixed $name ): string => $keys[ sanitize_key( (string) $name ) ] ?? '';
	$map    = static function ( mixed $rows, string $column ) use ( $key_of ): array {
		$pairs = [];
		foreach ( (array) $rows as $row ) {
			$service = trim( (string) ( $row[ $column ] ?? '' ) );
			$field   = $key_of( $row['form_field'] ?? '' );
			if ( '' !== $service && '' !== $field ) {
				$pairs[] = [ $service, $field ];
			}
		}
		return $pairs;
	};

	$i18n = [];
	foreach ( $texts as $lang => [ , $lang_settings ] ) {
		$url           = trim( (string) ( $lang_settings['redirect_url'] ?? '' ) );
		$redirect      = 'redirect' === ( $settings['after_submit'] ?? '' );
		$i18n[ $lang ] = [
			// Il default del prototipo, quello che i visitatori vedevano.
			'submit_label'    => mb_substr( trim( (string) ( $lang_settings['submit_text'] ?? '' ) ), 0, 191 ) ?: 'Invia',
			'success_message' => trim( (string) ( $lang_settings['success_message'] ?? '' ) ) ?: null,
			'success_url'     => $redirect && preg_match( '#^(/|https?://)#', $url ) ? mb_substr( $url, 0, 2048 ) : null,
		];
		if ( $redirect && null === $i18n[ $lang ]['success_url'] ) {
			$warnings[] = "Form \"{$title}\" ({$lang}): the page after the submission \"{$url}\" is not a URL, the message is shown instead.";
		}
	}

	// Il prototipo allegava i file a ogni email.
	$attach        = in_array( 'file', array_map( static fn( Field $field ): string => $field->type, $fields ), true );
	$notifications = [];
	foreach ( array_values( (array) ( $settings['notifications'] ?? [] ) ) as $n => $notification ) {
		$notification = (array) $notification;
		$name         = mb_substr( trim( (string) ( $notification['notification_name'] ?? '' ) ), 0, 191 ) ?: 'Notification ' . ( $n + 1 );
		$to           = mb_substr( $tags( $notification['to_email'] ?? '' ), 0, 1024 );
		if ( '' === $to ) {
			$warnings[] = "Form \"{$title}\", notification \"{$name}\": no recipient, so it was never sent; not migrated.";
			continue;
		}
		if ( ! empty( $notification['from_email'] ) || ! empty( $notification['cc_email'] ) ) {
			$warnings[] = "Form \"{$title}\", notification \"{$name}\": the sender and the Bcc are not migrated, emails use the sender of the SMTP settings.";
		}
		$texts_by_lang = [];
		foreach ( $texts as $lang => [ , $lang_settings ] ) {
			$text = (array) ( array_values( (array) ( $lang_settings['notifications'] ?? [] ) )[ $n ] ?? $notification );
			// Il prototipo scriveva l'oggetto standard in italiano, anche senza lingue.
			$subject = 'it' === $lang || 1 === count( I18n::languages() ) ? '[{{site_name}}] Nuova registrazione: {{form_title}}' : '[{{site_name}}] New submission: {{form_title}}';
			$body    = 'custom' === ( $notification['message_mode'] ?? '' ) ? $tags( $text['message'] ?? '' ) : '{{all_fields}}' . ( empty( $settings['display_page_info'] ) ? '' : "\n\n{{source_url}}" );

			$texts_by_lang[ $lang ] = [
				'subject' => mb_substr( $tags( $text['subject'] ?? '' ) ?: $subject, 0, 255 ),
				'heading' => mb_substr( $tags( $text['email_title'] ?? '' ) ?: '{{form_title}}', 0, 255 ),
				'body'    => $body,
			];
		}
		$notifications[] = new Notification( $name, $to, $texts_by_lang, mb_substr( $tags( $notification['replyto_email'] ?? '' ), 0, 255 ) ?: null, true, $attach );
	}

	// Conservate anche senza l'estensione attiva: si usano quando la si attiva.
	$integrations = [];
	if ( ! empty( $settings['mailchimp_enabled'] ) ) {
		$consent        = $key_of( $settings['mailchimp_optin_field'] ?? '' );
		$integrations[] = new Integration(
			'mailchimp',
			array_filter(
				[
					'email_field'   => $key_of( ( $settings['mailchimp_email_field'] ?? '' ) ?: 'email' ),
					'merge_fields'  => $map( $settings['mailchimp_field_map'] ?? [], 'mailchimp_field_name' ),
					// Come nel prototipo: il consenso decide i tag di consenso, non l'iscrizione.
					'status'        => 'subscribed',
					'consent_field' => $consent,
				]
			),
			array_map(
				static function ( array $text ) use ( $consent ): array {
					$tags         = trim( (string) ( $text[1]['mailchimp_tags'] ?? '' ) );
					$consent_tags = trim( (string) ( $text[1]['mailchimp_consent_tags'] ?? '' ) );
					// Senza un campo di consenso il prototipo aggiungeva sempre i tag di consenso: diventano tag.
					if ( '' === $consent ) {
						$tags         = implode( ', ', array_filter( [ $tags, $consent_tags ] ) );
						$consent_tags = '';
					}
					return [
						'list_id'      => trim( (string) ( $text[1]['mailchimp_list_id'] ?? '' ) ),
						'tags'         => $tags,
						'consent_tags' => $consent_tags,
					];
				},
				$texts
			)
		);
	}
	if ( ! empty( $settings['mailup_enabled'] ) ) {
		$optin          = $key_of( $settings['mailup_optin_field'] ?? '' );
		$integrations[] = new Integration(
			'mailup',
			array_filter(
				[
					'email_field'  => $key_of( ( $settings['mailup_email_field'] ?? '' ) ?: 'email' ),
					'merge_fields' => $map( $settings['mailup_field_map'] ?? [], 'mailup_field_name' ),
				]
			),
			// Lista e gruppo 1 se vuoti, come nel prototipo.
			array_map(
				static fn( array $text ): array => [
					'list_id'  => (string) max( 1, (int) ( $text[1]['mailup_list_id'] ?? 0 ) ),
					'group_id' => (string) max( 1, (int) ( $text[1]['mailup_group_id'] ?? 0 ) ),
				],
				$texts
			),
			true,
			// Il prototipo iscriveva solo con l'opt-in spuntato: ora è la condizione dell'integrazione.
			'' === $optin ? null : [
				'action' => 'show',
				'logic'  => 'all',
				'rules'  => [
					[
						'field'    => $optin,
						'operator' => 'not_empty',
					],
				],
			]
		);
	}

	return [
		'fields'        => $fields,
		'targets'       => $targets,
		'choices'       => $choices,
		'i18n'          => $i18n,
		'notifications' => $notifications,
		'integrations'  => $integrations,
	];
}

/**
 * I campi e le impostazioni del prototipo in un'altra lingua: un testo tradotto non vuoto prende il
 * posto di quello principale, per posizione, come in suite_barrio_forms_apply_translation().
 *
 * @param array<mixed> $fields
 * @param array<mixed> $settings
 * @param array<mixed> $fields_i18n
 * @param array<mixed> $settings_i18n
 * @return array{0: array<mixed>, 1: array<mixed>}
 */
function barrio_migration_translate( array $fields, array $settings, array $fields_i18n, array $settings_i18n ): array {
	$fields = array_values( $fields );
	foreach ( array_values( $fields_i18n ) as $i => $text ) {
		if ( ! isset( $fields[ $i ] ) ) {
			continue;
		}
		foreach ( (array) $text as $name => $value ) {
			if ( in_array( $name, [ 'label', 'placeholder', 'error_message' ], true ) ? '' !== (string) $value : 'options' === $name && $value ) {
				$fields[ $i ][ $name ] = $value;
			}
		}
	}
	foreach ( $settings_i18n as $name => $value ) {
		if ( is_string( $value ) && '' !== $value ) {
			$settings[ $name ] = $value;
		}
	}
	$settings['notifications'] = array_values( (array) ( $settings['notifications'] ?? [] ) );
	foreach ( array_values( (array) ( $settings_i18n['notifications'] ?? [] ) ) as $n => $text ) {
		foreach ( (array) $text as $name => $value ) {
			if ( isset( $settings['notifications'][ $n ] ) && is_string( $value ) && '' !== $value ) {
				$settings['notifications'][ $n ][ $name ] = $value;
			}
		}
	}
	return [ $fields, $settings ];
}

/**
 * Le registrazioni di un form, a blocchi.
 *
 * @return Generator<int, array{data: array<mixed>, lang: string, time: int}>
 */
function barrio_migration_submissions( string $table, int $form_id ): Generator {
	global $wpdb;
	$last = 0;
	do {
		// UNIX_TIMESTAMP() legge created_at nel fuso della sessione MySQL, quello in cui il prototipo l'ha scritto.
		$rows = Db::rows( $wpdb->prepare( 'SELECT id, data, lang, UNIX_TIMESTAMP(created_at) AS time FROM %i WHERE form_id = %d AND id > %d ORDER BY id LIMIT 500', $table, $form_id, $last ) );
		foreach ( $rows as $row ) {
			$last = (int) $row['id'];
			yield [
				'data' => barrio_migration_json( $row['data'] ),
				'lang' => (string) $row['lang'],
				'time' => (int) $row['time'],
			];
		}
		$more = 500 === count( $rows );
	} while ( $more );
}

/**
 * @param array{data: array<mixed>, lang: string, time: int}                                    $submission
 * @param array<string, Field>                                                                  $fields Per chiave, quelli salvati.
 * @param array{targets: array<string, string>, choices: array<string, array<string, array<string, string>>>} $form
 * @param list<string>                                                                          $stored
 * @param list<string>                                                                          $originals
 */
function barrio_migration_entry( array $submission, int $form_id, FormVersion $version, array $fields, array $form, string $main, bool $dry_run, int &$copied, int &$missing, array &$stored, array &$originals ): Entry {
	$lang   = barrio_migration_lang( $submission['lang'], $main );
	$values = [];
	$files  = [];
	foreach ( $submission['data'] as $name => $item ) {
		$field = $fields[ $form['targets'][ (string) $name ] ?? '' ] ?? null;
		if ( null === $field || ! is_array( $item ) ) {
			continue;
		}
		if ( 'file' !== $field->type ) {
			$labels = $form['choices'][ $field->key ][ $lang ] ?? $form['choices'][ $field->key ][ $main ] ?? [];
			$value  = barrio_migration_values( $field, $item['value'] ?? '', $labels );
			if ( $value ) {
				$values[ (int) $field->id ] = $value;
			}
			continue;
		}
		$file = barrio_migration_file( $item, $dry_run );
		if ( null === $file ) {
			++$missing;
			continue;
		}
		if ( ! $dry_run ) {
			$stored[]    = $file['stored_path'];
			$originals[] = $file['source'];
		}
		unset( $file['source'] );
		$files[] = [ 'field_id' => (int) $field->id ] + $file;
		++$copied;
	}
	$url = trim( (string) ( $submission['data']['_page_url']['value'] ?? '' ) );
	return new Entry(
		$form_id,
		(int) $version->id,
		$lang,
		$values,
		$files,
		source_url: '' === $url ? null : $url,
		created_at: gmdate( 'Y-m-d H:i:s', $submission['time'] ?: time() ),
		// Nel prototipo non c'era "da leggere": sono tutte già viste.
		is_read: true,
	);
}

/**
 * I valori come li salva Barrio Suite: per le scelte il valore, non l'etichetta tradotta.
 *
 * @param array<string, string> $labels Etichetta nella lingua della registrazione → valore della scelta.
 * @return list<string>
 */
function barrio_migration_values( Field $field, mixed $raw, array $labels ): array {
	if ( 'consent' === $field->type ) {
		// Spuntata: la scelta (dal sito) o "Etichetta: true" (da GraphQL); "Etichetta: false" non lo è.
		$checked = array_filter( (array) $raw, static fn( mixed $item ): bool => is_scalar( $item ) && '' !== trim( (string) $item ) && ! preg_match( '/:\s*false$/', trim( (string) $item ) ) );
		return $checked ? [ '1' ] : [];
	}
	$choice = in_array( $field->type, BARRIO_MIGRATION_CHOICES, true );
	$items  = (array) $raw;
	if ( ! $choice && is_array( $raw ) ) {
		$items = [ implode( ', ', array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) ) ];
	} elseif ( 'checkbox' === $field->type && is_string( $raw ) && preg_match( '/:\s*(true|false)\s*(\||$)/', $raw ) ) {
		// Dai frontend GraphQL: "Etichetta: true | Etichetta: false".
		$items = [];
		foreach ( explode( '|', $raw ) as $part ) {
			if ( preg_match( '/^(.*):\s*true$/', trim( $part ), $checked ) ) {
				$items[] = $checked[1];
			}
		}
	}
	$values = [];
	foreach ( $items as $item ) {
		$value = trim( is_scalar( $item ) ? (string) $item : '' );
		if ( $choice ) {
			$value = $labels[ $value ] ?? $value;
		} elseif ( 'datepicker' === $field->type ) {
			// Dai frontend GraphQL nel formato mostrato; <input type="date"> inviava già Y-m-d.
			$value = (string) preg_replace( '#^(\d{2})[/.-](\d{2})[/.-](\d{4})$#', '$3-$2-$1', $value );
		}
		if ( '' !== $value ) {
			$values[] = $value;
		}
	}
	return array_values( array_unique( $values ) );
}

/**
 * Il file di una registrazione, copiato nella cartella protetta. Si trova dall'URL salvato, anche se
 * nel frattempo il sito ha cambiato dominio o protocollo, e solo dentro la cartella degli upload.
 *
 * @param array<mixed> $item {label, value: il nome, url, is_file}.
 * @return array{stored_path: string, original_name: string, mime_type: string, size_bytes: int, source: string}|null Null se il file non c'è più.
 */
function barrio_migration_file( array $item, bool $dry_run ): ?array {
	$uploads = wp_upload_dir( null, false );
	$root    = realpath( $uploads['basedir'] );
	$base    = rtrim( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ), '/' ) . '/';
	$path    = (string) wp_parse_url( (string) ( $item['url'] ?? '' ), PHP_URL_PATH );
	$at      = strpos( $path, $base );
	$file    = false === $at || false === $root ? false : realpath( $root . '/' . rawurldecode( substr( $path, $at + strlen( $base ) ) ) );
	$size    = false === $file || ! str_starts_with( $file, $root . '/' ) || ! is_file( $file ) ? 0 : (int) filesize( $file );
	if ( false === $file || 0 === $size ) {
		return null;
	}

	$name   = mb_substr( sanitize_file_name( wp_basename( (string) ( $item['value'] ?? '' ) ) ) ?: wp_basename( $file ), -255 );
	$stored = 'dry-run/' . bin2hex( random_bytes( 16 ) );
	if ( ! $dry_run ) {
		$tmp = tempnam( get_temp_dir(), 'barrio' );
		if ( false === $tmp || ! copy( $file, $tmp ) ) {
			throw new RuntimeException( esc_html( "Could not copy {$file}." ) );
		}
		$stored = FileStore::store( $tmp );
	}
	return [
		'stored_path'   => $stored,
		'original_name' => $name,
		// Dal contenuto, come per i file inviati; un tipo non ammesso (un .php caricato nel prototipo) si scarica come dati.
		'mime_type'     => wp_check_filetype_and_ext( $file, $name )['type'] ?: 'application/octet-stream',
		'size_bytes'    => $size,
		'source'        => $file,
	];
}

/**
 * Le impostazioni globali del prototipo, solo dove Barrio Suite non ne ha già.
 *
 * @param list<array<string, string|null>>                          $forms
 * @param array{notes: list<string>, warnings: list<string>, forms: mixed} $report
 */
function barrio_migration_settings( array $forms, bool $dry_run, array &$report ): void {
	$apply = static function ( string $note, callable $save ) use ( $dry_run, &$report ): void {
		if ( ! $dry_run ) {
			$save();
		}
		$report['notes'][] = $note;
	};

	// Mailjet era un'API; Barrio Suite usa il suo SMTP, con le stesse chiavi.
	$mailjet = (array) get_option( 'suite_barrio_forms_mailjet', [] );
	$user    = trim( (string) ( $mailjet['api_key'] ?? '' ) );
	$secret  = trim( (string) ( $mailjet['secret_key'] ?? '' ) );
	if ( '' !== $user && '' !== $secret ) {
		if ( '' !== Smtp::settings()['host'] ) {
			$report['warnings'][] = 'Mailjet: the SMTP of Barrio Suite is already set up, so the Mailjet keys were not used.';
		} else {
			// Mailjet accetta solo mittenti verificati: quello delle notifiche, o l'email dell'amministratore come nel prototipo.
			$sender = [ (string) get_option( 'admin_email' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ];
			foreach ( $forms as $row ) {
				foreach ( (array) ( barrio_migration_json( $row['settings'] )['notifications'] ?? [] ) as $notification ) {
					if ( is_email( trim( (string) ( $notification['from_email'] ?? '' ) ) ) ) {
						$sender = [ trim( (string) $notification['from_email'] ), trim( (string) ( $notification['from_name'] ?? '' ) ) ];
						break 2;
					}
				}
			}
			$from = '' === Smtp::settings()['from_email'] ? [
				'from_email' => $sender[0],
				'from_name'  => $sender[1],
			] : [];
			$apply(
				'Email: SMTP of Mailjet (in-v3.mailjet.com:587) with the keys of Suite Barrio' . ( $from ? ", sender {$sender[0]}." : '.' ),
				static function () use ( $user, $secret, $from ): void {
					Smtp::save(
						[
							'host'       => 'in-v3.mailjet.com',
							'port'       => 587,
							'encryption' => 'tls',
							'username'   => $user,
						] + $from
					);
					Smtp::save_password( $secret );
				}
			);
		}
	}

	// Le chiavi si cifrano con lo schema dell'estensione, quindi servono le estensioni attive.
	$definitions = Integrations::definitions();
	$accounts    = [
		'mailchimp' => [ 'suite_barrio_forms_mailchimp', [ 'api_key' ] ],
		'recaptcha' => [ 'suite_barrio_forms_recaptcha', [ 'project_id', 'site_key', 'api_key' ] ],
	];
	foreach ( $accounts as $slug => [ $option, $names ] ) {
		$values = array_filter( array_map( static fn( mixed $value ): string => trim( (string) $value ), array_intersect_key( (array) get_option( $option, [] ), array_flip( $names ) ) ) );
		if ( ! $values || array_filter( Integrations::settings( $slug ) ) ) {
			continue;
		}
		if ( ! isset( $definitions[ $slug ] ) ) {
			$report['warnings'][] = "The {$slug} settings of Suite Barrio need the Barrio Suite " . ucfirst( $slug ) . ' extension: activate it and run the command again.';
			continue;
		}
		$apply( "{$definitions[ $slug ]['label']}: the account settings of Suite Barrio.", static fn() => Integrations::save_settings( [ $slug => $values ] ) );
	}

	// Il prototipo aveva logo e colori in ogni form: il template globale prende quelli del form modificato per ultimo.
	if ( false === get_option( 'barrio_forms_email' ) ) {
		usort( $forms, static fn( array $a, array $b ): int => strcmp( (string) $b['updated_at'], (string) $a['updated_at'] ) );
		foreach ( $forms as $row ) {
			$settings = barrio_migration_json( $row['settings'] );
			$template = array_filter(
				[
					'logo'             => esc_url_raw( (string) ( $settings['email_logo_url'] ?? '' ) ),
					'accent_color'     => (string) sanitize_hex_color( (string) ( $settings['email_header_color'] ?? '' ) ),
					'background_color' => (string) sanitize_hex_color( (string) ( $settings['email_bg_color'] ?? '' ) ),
					'footer'           => nl2br( esc_html( trim( (string) ( $settings['email_footer'] ?? '' ) ) ) ),
				]
			);
			if ( $template ) {
				$apply( "Email template: logo and colors of the form \"{$row['title']}\".", static fn() => Notifications::save_settings( $template ) );
				break;
			}
		}
	}

	$retention = (array) get_option( 'suite_barrio_forms_retention', [] );
	if ( ! empty( $retention['enabled'] ) && false === get_option( 'barrio_forms_privacy' ) ) {
		// Come il prototipo: anni, o i giorni della 9.1.4 arrotondati per eccesso; 2 di default.
		$years = absint( $retention['years'] ?? 0 ) ?: (int) ceil( absint( $retention['days'] ?? 0 ) / 365 ) ?: 2;
		$days  = min( 3650, $years * 365 );
		$apply( "Privacy: entries are deleted after {$days} days, as in Suite Barrio.", static fn() => Privacy::save_settings( [ 'retention_days' => $days ] ) );
	}
}

/**
 * Una chiave valida e non ancora usata nel form: ^[a-z][a-z0-9_]*$, al massimo 64 caratteri.
 *
 * @param array<string, true> $used
 */
function barrio_migration_key( string $name, array &$used ): string {
	$key = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', strtolower( remove_accents( $name ) ) ), '_' );
	$key = rtrim( substr( preg_match( '/^[a-z]/', $key ) ? $key : "field_{$key}", 0, 60 ), '_' );
	for ( $unique = $key, $n = 2; isset( $used[ $unique ] ); ++$n ) {
		$unique = "{$key}_{$n}";
	}
	$used[ $unique ] = true;
	return $unique;
}

// Il codice di WPML del prototipo; vuoto (sito senza lingue) o non valido è la lingua principale.
function barrio_migration_lang( string $lang, string $main ): string {
	$lang = str_replace( '_', '-', strtolower( trim( $lang ) ) );
	return preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/', $lang ) ? $lang : $main;
}

/** @return array<mixed> */
function barrio_migration_json( ?string $json ): array {
	$value = json_decode( (string) $json, true );
	return is_array( $value ) ? $value : [];
}
