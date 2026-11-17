<?php

namespace BarrioSuite\Forms;

use BarrioSuite\Crypto;
use BarrioSuite\Forms\Model\Entry;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\Integration;
use BarrioSuite\Forms\Model\NoteLevel;
use BarrioSuite\Forms\Repository\EntryRepository;
use Throwable;
use WP_Error;

/**
 * Registro delle integrazioni delle estensioni (docs/hooks.md). Ognuna dichiara le sue impostazioni
 * come JSON Schema: globali (scheda Impostazioni) e per form (scheda Integrazioni del builder). Le
 * pagine admin ne ricavano i controlli e il server valida con lo stesso schema. Dopo ogni invio
 * process() riceve la registrazione, se l'integrazione è attiva nel form e le sue condizioni sono
 * soddisfatte; l'esito va nelle note.
 *
 * Formati delle proprietà, oltre a quelli di WordPress (l'estensione dichiara solo il formato):
 * - password: segreto globale, cifrato (Crypto) e mai restituito dalla REST;
 * - field, field:email, field:consent,checkbox: la chiave di un campo del form, dei tipi indicati;
 * - field_map: coppie [nome nel servizio, chiave del campo].
 *
 * @phpstan-type Schema array<string, mixed>
 * @phpstan-type Definition array{label: string, description: string, global_settings: array<string, Schema>, form_settings: array<string, Schema>, process: (callable(Entry, Form, array<string, mixed>, array<string, mixed>): mixed)|null}
 */
final class Integrations {

	private const OPTION = 'barrio_forms_integrations';

	/** @var array<string, Definition> */
	private static array $registered = [];

	/** @param array<string, mixed> $definition label, description, global_settings, form_settings, process. */
	public static function register( string $slug, array $definition ): void {
		$definition += [
			'label'           => $slug,
			'description'     => '',
			'global_settings' => [],
			'form_settings'   => [],
			'process'         => null,
		];
		// Lo slug finisce nella colonna form_integrations.slug (bfg_slug_chk) e nelle note.
		if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/', $slug ) || ( $definition['form_settings'] && ! is_callable( $definition['process'] ) ) ) {
			_doing_it_wrong( __METHOD__, esc_html( "Integration \"{$slug}\": the slug must match ^[a-z][a-z0-9_-]*$, and form_settings need a process callback." ), '0.1.0' );
			return;
		}
		foreach ( [ 'global_settings', 'form_settings' ] as $group ) {
			$definition[ $group ] = array_map( [ self::class, 'expand' ], (array) $definition[ $group ] );
		}
		/** @var Definition $definition */
		self::$registered[ $slug ] = $definition;
	}

	/** @return array<string, array<string, mixed>> Per le pagine admin: nome, descrizione e schemi, senza process. */
	public static function definitions(): array {
		return array_map(
			static fn( array $definition ): array => [
				'label'           => $definition['label'],
				'description'     => $definition['description'],
				// Oggetti anche se vuoti: [] in JSON sarebbe una lista.
				'global_settings' => (object) $definition['global_settings'],
				'form_settings'   => (object) $definition['form_settings'],
			],
			self::$registered
		);
	}

	// Prima di ogni scrittura (FormRepository). Le impostazioni di un'estensione non attiva non si
	// possono validare: restano come sono e tornano valide quando la si riattiva.
	public static function check( Integration $integration ): ?string {
		$error      = Conditions::check( $integration->conditions );
		$definition = self::$registered[ $integration->slug ] ?? null;
		if ( null !== $error || null === $definition ) {
			return $error;
		}
		$translatable = array_filter( $definition['form_settings'], static fn( array $schema ): bool => ! empty( $schema['translatable'] ) );
		$valid        = rest_validate_value_from_schema( $integration->settings, self::object( array_diff_key( $definition['form_settings'], $translatable ) ), 'settings' );
		foreach ( $integration->i18n as $lang => $settings ) {
			$valid = is_wp_error( $valid ) ? $valid : rest_validate_value_from_schema( $settings, self::object( $translatable ), "i18n[{$lang}]" );
		}
		return is_wp_error( $valid ) ? $valid->get_error_message() : null;
	}

	// Listener di barrio_forms_after_submission, come le notifiche: nella lingua di chi ha compilato,
	// con le impostazioni di quella lingua. Un errore di un'integrazione non ferma le altre.
	public static function on_submission( Entry $entry, Form $form ): void {
		I18n::in(
			$entry->lang,
			static function () use ( $entry, $form ): void {
				$values = MergeTags::of( $entry, $form )->values;
				foreach ( $form->integrations as $integration ) {
					$definition = self::$registered[ $integration->slug ] ?? null;
					if ( ! $integration->enabled || null === $definition || null === $definition['process'] || ! Conditions::passes( $integration->conditions, $values ) ) {
						continue;
					}
					try {
						$result = ( $definition['process'] )( $entry, $form, I18n::pick( $integration->i18n, $entry->lang ) + $integration->settings, self::settings( $integration->slug ) );
					} catch ( Throwable $e ) {
						$result = new WP_Error( 'barrio_integration_failed', $e->getMessage() );
					}
					self::note( (int) $entry->id, $integration->slug, $definition['label'], $result );
				}
			}
		);
	}

	/**
	 * Le impostazioni globali con i segreti in chiaro, per process() e per le estensioni
	 * (barrio_forms_integration_settings()). Un segreto che non si legge più è vuoto.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings( string $slug ): array {
		$settings = (array) ( ( (array) get_option( self::OPTION, [] ) )[ $slug ] ?? [] );
		foreach ( self::secrets( $slug ) as $name ) {
			if ( isset( $settings[ $name ] ) ) {
				$settings[ $name ] = Crypto::decrypt( (string) $settings[ $name ] ) ?? '';
			}
		}
		return $settings;
	}

	/**
	 * Per GET /settings: i valori senza i segreti, e dei segreti solo lo stato.
	 *
	 * @return array{integrations: object, secrets: object}
	 */
	public static function view(): array {
		$saved   = (array) get_option( self::OPTION, [] );
		$values  = [];
		$secrets = [];
		foreach ( self::$registered as $slug => $definition ) {
			if ( ! $definition['global_settings'] ) {
				continue;
			}
			// Solo quelle che l'estensione dichiara ancora: le altre farebbero rifiutare il salvataggio da schema().
			$stored          = array_intersect_key( (array) ( $saved[ $slug ] ?? [] ), $definition['global_settings'] );
			$values[ $slug ] = (object) array_diff_key( $stored, array_flip( self::secrets( $slug ) ) );
			foreach ( self::secrets( $slug ) as $name ) {
				$secrets[ $slug ][ $name ] = match ( true ) {
					! isset( $stored[ $name ] ) => 'none',
					null === Crypto::decrypt( (string) $stored[ $name ] ) => 'unreadable',
					default => 'saved',
				};
			}
		}
		return [
			'integrations' => (object) $values,
			'secrets'      => (object) $secrets,
		];
	}

	/** @return array<string, mixed> L'argomento integrations di PUT /settings: le impostazioni globali per slug. */
	public static function schema(): array {
		$properties = [];
		foreach ( self::$registered as $slug => $definition ) {
			if ( $definition['global_settings'] ) {
				$properties[ $slug ] = self::object( $definition['global_settings'] );
			}
		}
		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		];
	}

	/** @param array<string, array<string, mixed>> $input Già validate con schema(); un segreto vuoto resta quello salvato. */
	public static function save_settings( array $input ): void {
		$saved = (array) get_option( self::OPTION, [] );
		foreach ( $input as $slug => $values ) {
			foreach ( self::secrets( $slug ) as $name ) {
				if ( '' === (string) ( $values[ $name ] ?? '' ) ) {
					unset( $values[ $name ] );
				} else {
					$values[ $name ] = Crypto::encrypt( (string) $values[ $name ] );
				}
			}
			$saved[ $slug ] = $values + (array) ( $saved[ $slug ] ?? [] );
		}
		update_option( self::OPTION, $saved, false );
	}

	/**
	 * I formati del plugin con il tipo JSON che implicano.
	 *
	 * @param array<string, mixed> $schema
	 * @return array<string, mixed>
	 */
	private static function expand( array $schema ): array {
		$format = (string) ( $schema['format'] ?? '' );
		if ( 'field_map' === $format ) {
			return [
				'type'  => 'array',
				'items' => [
					'type'     => 'array',
					'items'    => [ 'type' => 'string' ],
					'minItems' => 2,
					'maxItems' => 2,
				],
			] + $schema;
		}
		if ( 'password' === $format || 'field' === $format || str_starts_with( $format, 'field:' ) ) {
			return [ 'type' => 'string' ] + $schema;
		}
		return $schema;
	}

	/**
	 * @param array<string, mixed> $properties
	 * @return array<string, mixed>
	 */
	private static function object( array $properties ): array {
		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		];
	}

	/** @return list<string> Le impostazioni globali con il formato password. */
	private static function secrets( string $slug ): array {
		$settings = self::$registered[ $slug ]['global_settings'] ?? [];
		return array_keys( array_filter( $settings, static fn( array $schema ): bool => 'password' === ( $schema['format'] ?? '' ) ) );
	}

	// L'esito è nella lingua del sito, come le note delle notifiche.
	private static function note( int $entry_id, string $slug, string $label, mixed $result ): void {
		$error = $result instanceof WP_Error ? $result : null;
		I18n::in(
			I18n::languages()[0],
			static fn() => EntryRepository::add_note(
				$entry_id,
				$slug,
				null === $error ? NoteLevel::Success : NoteLevel::Error,
				null === $error
					/* translators: %s: integration name, for example Mailchimp. */
					? sprintf( __( 'Sent to %s.', 'barrio-suite' ), $label )
					/* translators: 1: integration name, for example Mailchimp; 2: error message. */
					: sprintf( __( 'Could not send to %1$s: %2$s', 'barrio-suite' ), $label, $error->get_error_message() )
			)
		);
	}
}
