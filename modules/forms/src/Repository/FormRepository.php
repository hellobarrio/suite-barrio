<?php

namespace BarrioSuite\Forms\Repository;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Conditions;
use BarrioSuite\Forms\FieldTypes;
use BarrioSuite\Forms\Integrations;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormStatus;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Model\Integration;
use BarrioSuite\Forms\Model\Notification;
use InvalidArgumentException;
use LogicException;
use WP_Error;

/**
 * @phpstan-type FormTexts array<string, array{submit_label: string, success_message?: string|null, success_url?: string|null}>
 */
final class FormRepository {

	// Con la versione: dopo un aggiornamento un object cache persistente darebbe un Form con le proprietà di prima.
	public const CACHE_GROUP = 'barrio_forms_' . BARRIO_SUITE_VERSION;

	/**
	 * @param FormTexts          $i18n
	 * @param list<Notification> $notifications
	 * @param list<Integration>  $integrations
	 * @param int|null           $id Per un'importazione che deve tenere gli ID (gli shortcode nelle pagine); null = il successivo.
	 */
	public static function create( string $title, array $i18n, array $notifications, FormVersion $version, array $integrations = [], ?int $id = null ): Form {
		$id = Db::transaction(
			static function () use ( $title, $i18n, $notifications, $version, $integrations, $id ): int {
				$now = gmdate( 'Y-m-d H:i:s' );
				$id  = Db::insert(
					Db::table( 'forms' ),
					( null === $id ? [] : [ 'id' => $id ] ) + [
						'title'      => $title,
						'status'     => FormStatus::Active->value,
						'created_at' => $now,
						'updated_at' => $now,
					]
				);
				self::insert_settings( $id, $i18n, $notifications, $integrations );
				self::insert_version( $id, 1, $version, $now );
				return $id;
			}
		);
		return self::get( $id ) ?? throw new LogicException( esc_html( "Form {$id} not found after creation." ) );
	}

	/**
	 * Ogni salvataggio crea una versione nuova, anche se cambiano solo testi o notifiche: il numero
	 * di versione è il blocco ottimistico. $base_version è quella da cui è partito chi salva: se nel
	 * frattempo qualcun altro ha salvato, risponde 409 invece di sovrascrivere.
	 *
	 * @param FormTexts          $i18n
	 * @param list<Notification> $notifications
	 * @param list<Integration>  $integrations
	 */
	public static function save( int $id, int $base_version, string $title, array $i18n, array $notifications, FormVersion $version, array $integrations = [] ): Form|WP_Error {
		global $wpdb;
		$error = Db::transaction(
			static function () use ( $wpdb, $id, $base_version, $title, $i18n, $notifications, $version, $integrations ): ?WP_Error {
				// La riga del form resta bloccata fino al commit: i salvataggi di uno stesso form
				// avvengono uno alla volta. FOR UPDATE legge l'ultimo dato confermato anche
				// dentro una transazione già aperta.
				if ( null === Db::var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', Db::table( 'forms' ), $id ) ) ) {
					return new WP_Error( 'barrio_form_not_found', __( 'Form not found.', 'barrio-suite' ), [ 'status' => 404 ] );
				}
				$current = (int) Db::var( $wpdb->prepare( 'SELECT MAX(version) FROM %i WHERE form_id = %d FOR UPDATE', Db::table( 'form_versions' ), $id ) );
				if ( $current !== $base_version ) {
					return new WP_Error(
						'barrio_version_conflict',
						__( 'Someone else saved this form in the meantime. Reload it to see their changes.', 'barrio-suite' ),
						[
							'status'          => 409,
							'current_version' => $current,
						]
					);
				}

				$now = gmdate( 'Y-m-d H:i:s' );
				Db::query( $wpdb->prepare( 'UPDATE %i SET title = %s, updated_at = %s WHERE id = %d', Db::table( 'forms' ), $title, $now, $id ) );
				// Testi, notifiche e integrazioni non sono versionati: si sostituiscono. Le traduzioni seguono in CASCADE.
				foreach ( [ 'form_i18n', 'form_notifications', 'form_integrations' ] as $table ) {
					Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE form_id = %d', Db::table( $table ), $id ) );
				}
				self::insert_settings( $id, $i18n, $notifications, $integrations );
				self::insert_version( $id, $current + 1, $version, $now );
				// Elimina le versioni precedenti che nessuna registrazione usa. Per quelle usate il
				// RESTRICT di entries fa fallire la cancellazione e IGNORE passa alla riga successiva.
				Db::query( $wpdb->prepare( 'DELETE IGNORE FROM %i WHERE form_id = %d AND version <= %d', Db::table( 'form_versions' ), $id, $current ) );
				return null;
			}
		);
		wp_cache_delete( $id, self::CACHE_GROUP );

		return $error ?? self::get( $id ) ?? throw new LogicException( esc_html( "Form {$id} not found after saving." ) );
	}

	// Lo stato non è versionato: non tocca né la versione né il blocco ottimistico.
	public static function set_status( int $id, FormStatus $status ): bool {
		global $wpdb;
		$updated = Db::query( $wpdb->prepare( 'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d', Db::table( 'forms' ), $status->value, gmdate( 'Y-m-d H:i:s' ), $id ) );
		wp_cache_delete( $id, self::CACHE_GROUP );
		return $updated > 0;
	}

	/**
	 * I più recenti per primi, con le lingue in cui il form ha dei testi: dell'invio, delle notifiche
	 * o dei campi della versione corrente.
	 *
	 * @return list<array{id: int, title: string, status: string, created_at: string, updated_at: string, entries: int, unread: int, languages: list<string>}>
	 */
	public static function all(): array {
		global $wpdb;
		$languages = [];
		$rows      = Db::rows(
			$wpdb->prepare(
				'SELECT form_id, lang FROM %i
				UNION SELECT n.form_id, i.lang FROM %i i JOIN %i n ON n.id = i.notification_id
				UNION SELECT v.form_id, i.lang FROM %i i JOIN %i f ON f.id = i.field_id JOIN %i v ON v.id = f.version_id
					WHERE v.version = (SELECT MAX(version) FROM %i WHERE form_id = v.form_id)',
				Db::table( 'form_i18n' ),
				Db::table( 'form_notifications_i18n' ),
				Db::table( 'form_notifications' ),
				Db::table( 'form_fields_i18n' ),
				Db::table( 'form_fields' ),
				Db::table( 'form_versions' ),
				Db::table( 'form_versions' )
			)
		);
		foreach ( $rows as $row ) {
			$languages[ (int) $row['form_id'] ][] = (string) $row['lang'];
		}
		return array_map(
			static fn( array $row ): array => [
				'id'         => (int) $row['id'],
				'title'      => (string) $row['title'],
				'status'     => (string) $row['status'],
				'created_at' => (string) $row['created_at'],
				'updated_at' => (string) $row['updated_at'],
				'entries'    => (int) $row['entries'],
				'unread'     => (int) $row['unread'],
				'languages'  => $languages[ (int) $row['id'] ] ?? [],
			],
			Db::rows(
				$wpdb->prepare(
					// Conteggi per form sull'indice form_read, senza leggere le righe.
					'SELECT f.id, f.title, f.status, f.created_at, f.updated_at, (SELECT COUNT(*) FROM %i e WHERE e.form_id = f.id) AS entries,
						(SELECT COUNT(*) FROM %i e WHERE e.form_id = f.id AND e.is_read = 0) AS unread FROM %i f ORDER BY f.id DESC',
					Db::table( 'entries' ),
					Db::table( 'entries' ),
					Db::table( 'forms' )
				)
			)
		);
	}

	// Un form con registrazioni non si elimina: lo impedisce comunque il database (RESTRICT).
	public static function delete( int $id ): ?WP_Error {
		global $wpdb;
		if ( null !== Db::var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE form_id = %d LIMIT 1', Db::table( 'entries' ), $id ) ) ) {
			return new WP_Error( 'barrio_form_has_entries', __( 'Delete the entries of this form first.', 'barrio-suite' ), [ 'status' => 409 ] );
		}
		Db::query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', Db::table( 'forms' ), $id ) );
		wp_cache_delete( $id, self::CACHE_GROUP );
		return null;
	}

	// Form e versione corrente: si leggono a ogni render e invio, quindi passano dalla object cache.
	public static function get( int $id ): ?Form {
		$form = wp_cache_get( $id, self::CACHE_GROUP );
		if ( $form instanceof Form ) {
			return $form;
		}

		global $wpdb;
		$row = Db::rows(
			$wpdb->prepare(
				'SELECT f.*, v.id AS version_id FROM %i f JOIN %i v ON v.form_id = f.id WHERE f.id = %d ORDER BY v.version DESC LIMIT 1',
				Db::table( 'forms' ),
				Db::table( 'form_versions' ),
				$id
			)
		)[0] ?? null;
		if ( null === $row ) {
			return null;
		}

		$i18n = [];
		foreach ( Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d', Db::table( 'form_i18n' ), $id ) ) as $text ) {
			$i18n[ (string) $text['lang'] ] = [
				'submit_label'    => (string) $text['submit_label'],
				'success_message' => $text['success_message'],
				'success_url'     => $text['success_url'],
			];
		}

		$notification_i18n = [];
		$rows              = Db::rows(
			$wpdb->prepare(
				'SELECT i.* FROM %i i JOIN %i n ON n.id = i.notification_id WHERE n.form_id = %d',
				Db::table( 'form_notifications_i18n' ),
				Db::table( 'form_notifications' ),
				$id
			)
		);
		foreach ( $rows as $text ) {
			$notification_i18n[ (int) $text['notification_id'] ][ (string) $text['lang'] ] = [
				'subject' => (string) $text['subject'],
				'heading' => $text['heading'],
				'body'    => (string) $text['body'],
			];
		}

		$notifications = [];
		foreach ( Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d ORDER BY position', Db::table( 'form_notifications' ), $id ) ) as $notification ) {
			$notifications[] = new Notification(
				(string) $notification['name'],
				(string) $notification['recipients'],
				$notification_i18n[ (int) $notification['id'] ] ?? [],
				$notification['reply_to'],
				'1' === $notification['is_enabled'],
				'1' === $notification['attach_files'],
				self::decode( $notification['conditions'] ),
				(int) $notification['id']
			);
		}

		$integration_i18n = [];
		$rows             = Db::rows(
			$wpdb->prepare(
				'SELECT i.* FROM %i i JOIN %i g ON g.id = i.integration_id WHERE g.form_id = %d',
				Db::table( 'form_integrations_i18n' ),
				Db::table( 'form_integrations' ),
				$id
			)
		);
		foreach ( $rows as $text ) {
			$integration_i18n[ (int) $text['integration_id'] ][ (string) $text['lang'] ] = self::decode( $text['settings'] ) ?? [];
		}
		$integrations = [];
		foreach ( Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d ORDER BY id', Db::table( 'form_integrations' ), $id ) ) as $integration ) {
			$integrations[] = new Integration(
				(string) $integration['slug'],
				self::decode( $integration['settings'] ) ?? [],
				$integration_i18n[ (int) $integration['id'] ] ?? [],
				'1' === $integration['is_enabled'],
				self::decode( $integration['conditions'] )
			);
		}

		$form = new Form(
			$id,
			(string) $row['title'],
			FormStatus::from( (string) $row['status'] ),
			$i18n,
			$notifications,
			self::version( (int) $row['version_id'] ) ?? throw new LogicException( esc_html( "Version {$row['version_id']} not found." ) ),
			(string) $row['created_at'],
			(string) $row['updated_at'],
			$integrations
		);
		wp_cache_set( $id, $form, self::CACHE_GROUP, DAY_IN_SECONDS );
		return $form;
	}

	// Con $form_id, null anche se la versione è di un altro form.
	public static function version( int $id, ?int $form_id = null ): ?FormVersion {
		global $wpdb;
		$where = null === $form_id ? $wpdb->prepare( 'id = %d', $id ) : $wpdb->prepare( 'id = %d AND form_id = %d', $id, $form_id );
		return self::load_versions( $where )[0] ?? null;
	}

	/** @return list<FormVersion> Dalla più recente: la corrente e quelle che le registrazioni usano ancora. */
	public static function versions( int $form_id ): array {
		global $wpdb;
		return self::load_versions( $wpdb->prepare( 'form_id = %d', $form_id ) );
	}

	/**
	 * @param string $where Condizione già preparata su form_versions.
	 * @return list<FormVersion>
	 */
	private static function load_versions( string $where ): array {
		global $wpdb;
		$versions = Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE ', Db::table( 'form_versions' ) ) . $where . ' ORDER BY version DESC' );
		if ( ! $versions ) {
			return [];
		}
		$in = ' IN (' . implode( ',', array_map( 'intval', array_column( $versions, 'id' ) ) ) . ')';

		$field_i18n = [];
		$rows       = Db::rows(
			$wpdb->prepare(
				'SELECT i.* FROM %i i JOIN %i f ON f.id = i.field_id WHERE f.version_id',
				Db::table( 'form_fields_i18n' ),
				Db::table( 'form_fields' )
			) . $in
		);
		foreach ( $rows as $text ) {
			$field_i18n[ (int) $text['field_id'] ][ (string) $text['lang'] ] = [
				'label'         => (string) $text['label'],
				'placeholder'   => $text['placeholder'],
				'error_message' => $text['error_message'],
				'options'       => self::decode( $text['options'] ),
			];
		}

		$fields = [];
		foreach ( Db::rows( $wpdb->prepare( 'SELECT * FROM %i WHERE version_id', Db::table( 'form_fields' ) ) . $in . ' ORDER BY position' ) as $row ) {
			$fields[ (int) $row['version_id'] ][] = new Field(
				(string) $row['field_key'],
				(string) $row['type'],
				$field_i18n[ (int) $row['id'] ] ?? [],
				'1' === $row['is_required'],
				self::decode( $row['config'] ) ?? [],
				self::decode( $row['conditions'] ),
				(int) $row['id']
			);
		}

		return array_map(
			static fn( array $version ): FormVersion => new FormVersion(
				$fields[ (int) $version['id'] ] ?? [],
				(int) $version['id'],
				(int) $version['version'],
				(string) $version['created_at']
			),
			$versions
		);
	}

	/**
	 * @param FormTexts          $i18n
	 * @param list<Notification> $notifications
	 * @param list<Integration>  $integrations
	 */
	private static function insert_settings( int $form_id, array $i18n, array $notifications, array $integrations ): void {
		foreach ( $i18n as $lang => $text ) {
			Db::insert(
				Db::table( 'form_i18n' ),
				[
					'form_id'         => $form_id,
					'lang'            => $lang,
					'submit_label'    => $text['submit_label'],
					'success_message' => $text['success_message'] ?? null,
					'success_url'     => $text['success_url'] ?? null,
				]
			);
		}
		foreach ( $notifications as $position => $notification ) {
			// Il database non può imporre che una riga abbia almeno un figlio: senza testi non c'è un oggetto.
			if ( ! $notification->i18n ) {
				throw new InvalidArgumentException( esc_html( "Notification '{$notification->name}' has no texts." ) );
			}
			$error = Conditions::check( $notification->conditions );
			if ( null !== $error ) {
				throw new InvalidArgumentException( esc_html( "Notification '{$notification->name}': {$error}" ) );
			}
			$notification_id = Db::insert(
				Db::table( 'form_notifications' ),
				[
					'form_id'      => $form_id,
					'name'         => $notification->name,
					'is_enabled'   => (int) $notification->enabled,
					'recipients'   => $notification->recipients,
					'reply_to'     => $notification->reply_to,
					'attach_files' => (int) $notification->attach_files,
					'conditions'   => self::encode( $notification->conditions ),
					'position'     => $position,
				]
			);
			foreach ( $notification->i18n as $lang => $text ) {
				Db::insert(
					Db::table( 'form_notifications_i18n' ),
					[
						'notification_id' => $notification_id,
						'lang'            => $lang,
						'subject'         => $text['subject'],
						'heading'         => $text['heading'] ?? null,
						'body'            => $text['body'],
					]
				);
			}
		}
		$slugs = [];
		foreach ( $integrations as $integration ) {
			// Lo vieterebbe anche la UNIQUE form_slug, ma con un errore del database.
			if ( isset( $slugs[ $integration->slug ] ) ) {
				throw new InvalidArgumentException( esc_html( "Integration '{$integration->slug}' is used twice." ) );
			}
			$slugs[ $integration->slug ] = true;
			// Il JSON si valida con lo schema dell'estensione prima di ogni scrittura (docs/database.md).
			$error = Integrations::check( $integration );
			if ( null !== $error ) {
				throw new InvalidArgumentException( esc_html( "Integration '{$integration->slug}': {$error}" ) );
			}
			$integration_id = Db::insert(
				Db::table( 'form_integrations' ),
				[
					'form_id'    => $form_id,
					'slug'       => $integration->slug,
					'is_enabled' => (int) $integration->enabled,
					'settings'   => self::encode( $integration->settings ),
					'conditions' => self::encode( $integration->conditions ),
				]
			);
			// Una lingua senza impostazioni traducibili non ha la riga: varrebbero comunque quelle della principale.
			foreach ( array_filter( $integration->i18n ) as $lang => $settings ) {
				Db::insert(
					Db::table( 'form_integrations_i18n' ),
					[
						'integration_id' => $integration_id,
						'lang'           => $lang,
						'settings'       => self::encode( $settings ),
					]
				);
			}
		}
	}

	private static function insert_version( int $form_id, int $number, FormVersion $version, string $now ): void {
		$version_id = Db::insert(
			Db::table( 'form_versions' ),
			[
				'form_id'    => $form_id,
				'version'    => $number,
				'created_at' => $now,
			]
		);
		$keys       = [];
		foreach ( $version->fields as $position => $field ) {
			// Come per le notifiche: un campo senza testi non avrebbe un'etichetta in nessuna lingua.
			if ( ! $field->i18n ) {
				throw new InvalidArgumentException( esc_html( "Field '{$field->key}' has no texts." ) );
			}
			// Lo vieterebbe anche la UNIQUE, ma con un errore del database invece di un messaggio chiaro.
			if ( isset( $keys[ $field->key ] ) ) {
				/* translators: %s: field key. */
				throw new InvalidArgumentException( esc_html( sprintf( __( 'Field key \'%s\' is used twice.', 'barrio-suite' ), $field->key ) ) );
			}
			$keys[ $field->key ] = true;
			// Il JSON si valida con lo schema del tipo prima di ogni scrittura (docs/database.md).
			$error = FieldTypes::check( $field );
			if ( null !== $error ) {
				/* translators: 1: field key, 2: what is wrong with the field. */
				throw new InvalidArgumentException( esc_html( sprintf( __( 'Field \'%1$s\': %2$s', 'barrio-suite' ), $field->key, $error ) ) );
			}
			$field_id = Db::insert(
				Db::table( 'form_fields' ),
				[
					'version_id'  => $version_id,
					'field_key'   => $field->key,
					'type'        => $field->type,
					'position'    => $position,
					'is_required' => (int) $field->required,
					'config'      => self::encode( $field->config ),
					'conditions'  => self::encode( $field->conditions ),
				]
			);
			foreach ( $field->i18n as $lang => $text ) {
				Db::insert(
					Db::table( 'form_fields_i18n' ),
					[
						'field_id'      => $field_id,
						'lang'          => $lang,
						'label'         => $text['label'],
						'placeholder'   => $text['placeholder'] ?? null,
						'error_message' => $text['error_message'] ?? null,
						'options'       => self::encode( $text['options'] ?? null ),
					]
				);
			}
		}
	}

	// Sempre un oggetto: json_encode([]) darebbe un array, che il CHECK rifiuta.
	/** @param array<mixed>|null $value */
	private static function encode( ?array $value ): ?string {
		return null === $value ? null : (string) wp_json_encode( (object) $value );
	}

	/** @return array<string, mixed>|null */
	private static function decode( ?string $json ): ?array {
		$value = null === $json ? null : json_decode( $json, true );
		return is_array( $value ) ? $value : null;
	}
}
