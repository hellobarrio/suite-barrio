<?php
/**
 * Solo sviluppo: il plugin non carica questo file, lo carica WP-CLI con --require.
 *
 *     npm run form -- dev/example-form.json
 *     npm run seed -- --entries=100000 [--forms=5]
 *
 * Il primo crea un form da un file JSON (finché non c'è il builder); il secondo genera form e
 * registrazioni realistiche per misurare le query (docs/database.md).
 */

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Model\Notification;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;

// Il JSON ha la forma dei modelli: title, i18n, notifications e fields (key, type, required, config,
// conditions, i18n). La validazione è quella di FormRepository, come per ogni altra scrittura.
WP_CLI::add_command(
	'barrio forms create',
	static function ( array $args ): void {
		$data = json_decode( (string) file_get_contents( $args[0] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			WP_CLI::error( "{$args[0]} is not valid JSON." );
		}
		try {
			$form = FormRepository::create(
				$data['title'],
				$data['i18n'] ?? [],
				array_map(
					static fn( array $n ): Notification => new Notification( $n['name'], $n['recipients'], $n['i18n'], $n['reply_to'] ?? null, $n['enabled'] ?? true, $n['attach_files'] ?? false, $n['conditions'] ?? null ),
					$data['notifications'] ?? []
				),
				new FormVersion(
					array_map(
						static fn( array $f ): Field => new Field( $f['key'], $f['type'], $f['i18n'], $f['required'] ?? false, $f['config'] ?? [], $f['conditions'] ?? null ),
						$data['fields']
					)
				)
			);
		} catch ( InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( "Form {$form->id} created: [barrio_form id=\"{$form->id}\"]" );
	}
);

WP_CLI::add_command(
	'barrio forms seed',
	static function ( array $args, array $assoc ): void {
		$forms    = max( 1, (int) ( $assoc['forms'] ?? 5 ) );
		$per_form = intdiv( (int) ( $assoc['entries'] ?? 1000 ), $forms );
		// Testi in italiano e inglese; options è {valore: etichetta}, i valori stanno in config.
		$field    = static function ( string $key, string $type, string $it, string $en, bool $required = false, array $choices = [] ): Field {
			$i18n = [
				'it' => [ 'label' => $it ],
				'en' => [ 'label' => $en ],
			];
			if ( $choices ) {
				$i18n['it']['options'] = array_combine( array_keys( $choices ), array_column( $choices, 0 ) );
				$i18n['en']['options'] = array_combine( array_keys( $choices ), array_column( $choices, 1 ) );
			}
			return new Field( $key, $type, $i18n, $required, $choices ? [ 'choices' => array_keys( $choices ) ] : [] );
		};
		$fields   = [
			$field( 'name', 'text', 'Nome e cognome', 'Full name', true ),
			$field( 'email', 'email', 'Indirizzo email', 'Email address', true ),
			$field(
				'province',
				'select',
				'Provincia',
				'Province',
				false,
				[
					'to' => [ 'Torino', 'Turin' ],
					'cn' => [ 'Cuneo', 'Cuneo' ],
					'at' => [ 'Asti', 'Asti' ],
					'al' => [ 'Alessandria', 'Alessandria' ],
					'mi' => [ 'Milano', 'Milan' ],
					'ge' => [ 'Genova', 'Genoa' ],
				]
			),
			$field(
				'topics',
				'checkbox',
				'Argomenti di interesse',
				'Topics',
				false,
				[
					'news'   => [ 'Notizie', 'News' ],
					'events' => [ 'Eventi', 'Events' ],
					'offers' => [ 'Offerte', 'Offers' ],
					'jobs'   => [ 'Lavora con noi', 'Careers' ],
				]
			),
			$field( 'message', 'textarea', 'Messaggio', 'Message' ),
			$field( 'privacy', 'consent', 'Ho letto l\'<a href="/privacy-policy/">informativa privacy</a>', 'I have read the <a href="/en/privacy-policy/">privacy policy</a>', true ),
		];
		$texts    = [
			'it' => [ 'submit_label' => 'Invia' ],
			'en' => [ 'submit_label' => 'Send' ],
		];
		$notify   = [
			new Notification(
				'Admin',
				(string) get_option( 'admin_email' ),
				[
					'it' => [
						'subject' => 'Nuova richiesta da {{name}}',
						'body'    => '{{all_fields}}',
					],
				],
				'{{email}}'
			),
		];
		$topics   = [ 'news', 'events', 'offers', 'jobs' ];
		$span     = 2 * YEAR_IN_SECONDS;
		$progress = WP_CLI\Utils\make_progress_bar( 'Entries', $forms * $per_form );

		for ( $f = 1; $f <= $forms; $f++ ) {
			$form = FormRepository::create( "Seed form {$f}", $texts, $notify, new FormVersion( $fields ) );
			// Lotti da 1000 in una transazione: un commit (e un fsync) per lotto, non per registrazione.
			foreach ( array_chunk( range( 0, $per_form - 1 ), 1000 ) as $chunk ) {
				Db::transaction(
					static function () use ( &$form, $chunk, $per_form, $field, $fields, $texts, $notify, $topics, $span, $f, $progress ): void {
						foreach ( $chunk as $i ) {
							// A metà, una nuova versione: le registrazioni si dividono su due versioni.
							if ( intdiv( $per_form, 2 ) === $i ) {
								$saved = FormRepository::save( $form->id, $form->current->version, $form->title, $texts, $notify, new FormVersion( [ ...$fields, $field( 'phone', 'tel', 'Telefono', 'Phone' ) ] ) );
								$form  = $saved instanceof Form ? $saved : WP_CLI::error( $saved->get_error_message() );
							}
							$id     = array_column( $form->current->fields, 'id', 'key' );
							$n      = $f * $per_form + $i;
							$picked = $topics;
							shuffle( $picked );
							EntryRepository::create(
								new Entry(
									$form->id,
									(int) $form->current->id,
									random_int( 1, 10 ) <= 7 ? 'it' : 'en',
									[
										$id['name']     => [ "Persona {$n}" ],
										$id['email']    => [ "persona{$n}@example.com" ],
										$id['province'] => [ [ 'to', 'cn', 'at', 'al', 'mi', 'ge' ][ $n % 6 ] ],
										$id['topics']   => array_slice( $picked, 0, random_int( 1, 3 ) ),
										$id['message']  => [ str_repeat( "Messaggio {$n}. ", random_int( 1, 20 ) ) ],
										$id['privacy']  => [ '1' ],
									],
									source_url: home_url( '/contatti/' ),
									// Date crescenti negli ultimi due anni, come le registrazioni vere.
									created_at: gmdate( 'Y-m-d H:i:s', time() - $span + intdiv( $span * $i, $per_form ) ),
								)
							);
							$progress->tick();
						}
					}
				);
			}
		}
		$progress->finish();

		// Le registrazioni vecchie sono già lette; restano da leggere quelle dell'ultimo mese.
		Db::query( 'UPDATE ' . Db::table( 'entries' ) . ' SET is_read = 1 WHERE created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY' );
		Db::query( 'ANALYZE TABLE ' . implode( ', ', array_map( [ Db::class, 'table' ], [ 'entries', 'entry_values', 'form_fields', 'form_versions' ] ) ) );
		WP_CLI::success( sprintf( '%d forms, %d entries.', $forms, $forms * $per_form ) );
	}
);
