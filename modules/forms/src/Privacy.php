<?php

namespace BarrioSuite\Forms;

use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Repository\EntryRepository;
use BarrioSuite\Forms\Repository\FormRepository;
use RuntimeException;

/**
 * Strumenti Privacy di WordPress (Strumenti → Esporta / Cancella i dati personali) e retention.
 * Una registrazione appartiene a un indirizzo se lo contiene in un campo email. L'utente collegato
 * non basta: un admin può compilare un form per conto di qualcun altro.
 *
 * @phpstan-type Settings array{retention_days: int}
 */
final class Privacy {

	public const CRON      = 'barrio_forms_retention';
	private const OPTION   = 'barrio_forms_privacy';
	private const DEFAULTS = [ 'retention_days' => 0 ];
	// Registrazioni per pagina: WordPress chiede esportazione e cancellazione una pagina alla volta.
	private const BATCH = 50;

	/**
	 * @param array<string, mixed> $exporters
	 * @return array<string, mixed>
	 */
	public static function exporters( array $exporters ): array {
		$exporters['barrio-forms'] = [
			'exporter_friendly_name' => __( 'Form entries', 'barrio-suite' ),
			'callback'               => [ self::class, 'export' ],
		];
		return $exporters;
	}

	/**
	 * @param array<string, mixed> $erasers
	 * @return array<string, mixed>
	 */
	public static function erasers( array $erasers ): array {
		$erasers['barrio-forms'] = [
			'eraser_friendly_name' => __( 'Form entries', 'barrio-suite' ),
			'callback'             => [ self::class, 'erase' ],
		];
		return $erasers;
	}

	/**
	 * I campi compilati, con etichette e scelte nella lingua in cui la persona ha letto il form.
	 * ponytail: dei file solo il nome (WordPress non ha un modo per allegarli all'esportazione); il file si scarica dal dettaglio della registrazione.
	 *
	 * @return array{data: list<array{group_id: string, group_label: string, group_description: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		$entries = EntryRepository::by_email( $email, self::BATCH, ( max( 1, $page ) - 1 ) * self::BATCH );
		$forms   = [];
		$data    = [];
		foreach ( $entries as $entry ) {
			$form = $forms[ $entry->form_id ] ??= FormRepository::get( $entry->form_id );
			if ( null === $form ) {
				continue;
			}
			$tags    = MergeTags::of( $entry, $form );
			$details = [
				[
					'name'  => __( 'Form', 'barrio-suite' ),
					'value' => $form->title,
				],
				[
					'name'  => __( 'Date', 'barrio-suite' ),
					'value' => $tags->text( '{{entry_date}}' ),
				],
				[
					'name'  => __( 'Page', 'barrio-suite' ),
					'value' => (string) $entry->source_url,
				],
				...array_map(
					static fn( array $field ): array => [
						'name'  => $field['label'],
						'value' => $field['value'],
					],
					array_values( $tags->fields )
				),
			];
			$data[]  = [
				'group_id'          => 'barrio-forms',
				'group_label'       => __( 'Form entries', 'barrio-suite' ),
				'group_description' => __( 'What you sent with the forms of this site.', 'barrio-suite' ),
				'item_id'           => "barrio-entry-{$entry->id}",
				'data'              => array_values( array_filter( $details, static fn( array $detail ): bool => '' !== $detail['value'] ) ),
			];
		}
		return [
			'data' => $data,
			'done' => count( $entries ) < self::BATCH,
		];
	}

	/**
	 * Elimina le registrazioni intere, con i file. WordPress passa anche il numero di pagina, che
	 * qui non serve: si cancella sempre la prima, perché le precedenti non ci sono più.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public static function erase( string $email ): array {
		$ids = array_map( static fn( Entry $entry ): int => (int) $entry->id, EntryRepository::by_email( $email, self::BATCH ) );
		try {
			if ( $ids ) {
				EntryRepository::delete( ...$ids );
			}
		} catch ( RuntimeException $e ) {
			return [
				'items_removed'  => false,
				'items_retained' => true,
				/* translators: %s: errore, per esempio un file che non si è potuto eliminare. */
				'messages'       => [ sprintf( __( 'Some form entries could not be erased: %s', 'barrio-suite' ), $e->getMessage() ) ],
				// Altrimenti WordPress chiederebbe di nuovo la stessa pagina, all'infinito.
				'done'           => true,
			];
		}
		return [
			'items_removed'  => (bool) $ids,
			'items_retained' => false,
			'messages'       => [],
			'done'           => count( $ids ) < self::BATCH,
		];
	}

	// Su init, come WordPress per i suoi eventi: arriva anche agli altri siti di un multisite.
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time(), 'daily', self::CRON );
		}
	}

	// Ogni giorno, con WP-Cron. Senza retention (0 giorni) non elimina nulla.
	public static function purge(): void {
		$days = self::settings()['retention_days'];
		if ( $days < 1 ) {
			return;
		}
		$before   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deadline = microtime( true ) + 20;
		while ( 200 === EntryRepository::delete_older_than( $before, 200 ) ) {
			// Un arretrato grande, per esempio la prima volta, non deve superare il tempo massimo di
			// PHP: il resto con un altro giro di WP-Cron, alla prossima richiesta.
			if ( microtime( true ) > $deadline ) {
				wp_schedule_single_event( time(), self::CRON );
				return;
			}
		}
	}

	/** @return array<string, mixed> JSON Schema delle impostazioni. */
	public static function schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				// 0 = si conservano finché qualcuno non le elimina.
				'retention_days' => [
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 3650,
				],
			],
		];
	}

	/** @return Settings */
	public static function settings(): array {
		/** @var Settings */
		return array_intersect_key( (array) get_option( self::OPTION, [] ), self::DEFAULTS ) + self::DEFAULTS;
	}

	/** @param array<string, mixed> $settings Già validate con schema(); quelle assenti restano come sono. */
	public static function save_settings( array $settings ): void {
		update_option( self::OPTION, array_intersect_key( $settings, self::DEFAULTS ) + self::settings(), false );
	}
}
