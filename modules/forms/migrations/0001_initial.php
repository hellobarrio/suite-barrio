<?php

use BarrioSuite\Database\Db;

defined( 'ABSPATH' ) || exit;

// Schema iniziale del modulo Forms. Regole, vincoli e catena delle cancellazioni: docs/database.md.
// Le tabelle si creano nell'ordine in cui compaiono: ogni foreign key punta a una tabella già creata.
return static function ( wpdb $wpdb ): void {
	// Prefisso delle tabelle del plugin. Anche i nomi dei vincoli hanno quello di WordPress ($p),
	// perché in MySQL sono unici per database.
	$p       = $wpdb->prefix;
	$t       = "{$p}barrio_";
	$charset = $wpdb->get_charset_collate();
	// Identificatori e insiemi chiusi si confrontano byte per byte: con la collation di WordPress,
	// che ignora le maiuscole, CHECK e UNIQUE accetterebbero 'Active' o 'EMAIL'.
	$bin = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';
	// Codice di lingua in minuscolo: it, en, fur, pt-br, zh-hans.
	$lang = "'^[a-z]{2,3}(-[a-z0-9]{2,8})*$'";

	$tables = [

		// --- Form e configurazione corrente --------------------------------------------------------
		// Si sostituisce a ogni salvataggio dal builder: non serve come prova di ciò che il visitatore ha letto.

		// Identità del form: ciò che resta uguale tra un salvataggio e l'altro. title è il nome interno, non tradotto.
		'forms'                   => "
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title       VARCHAR(191)    NOT NULL,
			status      VARCHAR(16)     {$bin} NOT NULL DEFAULT 'active',
			created_at  DATETIME        NOT NULL,
			updated_at  DATETIME        NOT NULL,
			PRIMARY KEY (id),
			CONSTRAINT {$p}bf_status_chk CHECK (status IN ('active', 'closed', 'trash'))",

		// Testi di invio e di conferma, una riga per lingua. Il visitatore li vede al momento dell'invio
		// e dopo. success_message NULL = messaggio standard; success_url NULL = resta sulla pagina.
		'form_i18n'               => "
			form_id          BIGINT UNSIGNED NOT NULL,
			lang             VARCHAR(16)     {$bin} NOT NULL,
			submit_label     VARCHAR(191)    NOT NULL,
			success_message  TEXT            NULL,
			success_url      VARCHAR(2048)   NULL,
			PRIMARY KEY (form_id, lang),
			CONSTRAINT {$p}bfi_form_fk FOREIGN KEY (form_id)
				REFERENCES {$t}forms (id) ON DELETE CASCADE,
			CONSTRAINT {$p}bfi_lang_chk CHECK (lang REGEXP {$lang}),
			CONSTRAINT {$p}bfi_url_chk CHECK (success_url REGEXP '^(/|https?://)')",

		// Email inviate dopo ogni registrazione. Il rinvio di una notifica usa questa configurazione,
		// quella corretta, non quella dell'epoca. recipients e reply_to sono template (indirizzi separati
		// da virgola, anche con merge tag); conditions NULL = parte sempre.
		'form_notifications'      => "
			id            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
			form_id       BIGINT UNSIGNED   NOT NULL,
			name          VARCHAR(191)      NOT NULL,
			is_enabled    TINYINT(1)        NOT NULL DEFAULT 1,
			recipients    VARCHAR(1024)     NOT NULL,
			reply_to      VARCHAR(255)      NULL,
			attach_files  TINYINT(1)        NOT NULL DEFAULT 0,
			conditions    JSON              NULL,
			position      SMALLINT UNSIGNED NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY form_position (form_id, position),
			CONSTRAINT {$p}bfn_form_fk FOREIGN KEY (form_id)
				REFERENCES {$t}forms (id) ON DELETE CASCADE,
			CONSTRAINT {$p}bfn_enabled_chk CHECK (is_enabled IN (0, 1)),
			CONSTRAINT {$p}bfn_attach_chk CHECK (attach_files IN (0, 1)),
			CONSTRAINT {$p}bfn_cond_chk CHECK (JSON_TYPE(conditions) = 'OBJECT')",

		// Oggetto e testo delle notifiche, una riga per lingua. heading NULL = uguale all'oggetto;
		// nel body {{all_fields}} è il riepilogo automatico di tutti i campi.
		'form_notifications_i18n' => "
			notification_id  BIGINT UNSIGNED NOT NULL,
			lang             VARCHAR(16)     {$bin} NOT NULL,
			subject          VARCHAR(255)    NOT NULL,
			heading          VARCHAR(255)    NULL,
			body             TEXT            NOT NULL,
			PRIMARY KEY (notification_id, lang),
			CONSTRAINT {$p}bfni_notif_fk FOREIGN KEY (notification_id)
				REFERENCES {$t}form_notifications (id) ON DELETE CASCADE,
			CONSTRAINT {$p}bfni_lang_chk CHECK (lang REGEXP {$lang})",

		// --- Versioni: ciò che il visitatore legge prima di inviare --------------------------------
		// Immutabili: ogni salvataggio ne crea una nuova; restano solo quelle usate da registrazioni.
		// Conservano etichette, scelte e testo del consenso come prova, in tutte le lingue.

		// Testata della versione. version numera le versioni di un form ed è il blocco ottimistico
		// del builder; form_id_id serve solo come destinazione della FK composta di entries.
		'form_versions'           => "
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id     BIGINT UNSIGNED NOT NULL,
			version     INT UNSIGNED    NOT NULL,
			created_at  DATETIME        NOT NULL,
			created_by  BIGINT UNSIGNED NULL,
			PRIMARY KEY (id),
			UNIQUE KEY form_version (form_id, version),
			UNIQUE KEY form_id_id (form_id, id),
			CONSTRAINT {$p}bfv_form_fk FOREIGN KEY (form_id)
				REFERENCES {$t}forms (id) ON DELETE CASCADE",

		// Campi della versione. field_key è l'identità stabile del campo tra le versioni (HTML, merge
		// tag, export); type è aperto alle estensioni, quindi si valida in PHP e non con un CHECK;
		// config dipende dal tipo e non si traduce (valori delle scelte, limiti, pattern…);
		// conditions NULL = sempre visibile.
		'form_fields'             => "
			id           BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
			version_id   BIGINT UNSIGNED   NOT NULL,
			field_key    VARCHAR(64)       {$bin} NOT NULL,
			type         VARCHAR(32)       {$bin} NOT NULL,
			position     SMALLINT UNSIGNED NOT NULL,
			is_required  TINYINT(1)        NOT NULL DEFAULT 0,
			config       JSON              NOT NULL,
			conditions   JSON              NULL,
			PRIMARY KEY (id),
			UNIQUE KEY version_key (version_id, field_key),
			UNIQUE KEY version_position (version_id, position),
			CONSTRAINT {$p}bff_version_fk FOREIGN KEY (version_id)
				REFERENCES {$t}form_versions (id) ON DELETE CASCADE,
			CONSTRAINT {$p}bff_key_chk CHECK (field_key REGEXP '^[a-z][a-z0-9_]*$'),
			CONSTRAINT {$p}bff_required_chk CHECK (is_required IN (0, 1)),
			CONSTRAINT {$p}bff_config_chk CHECK (JSON_TYPE(config) = 'OBJECT'),
			CONSTRAINT {$p}bff_cond_chk CHECK (JSON_TYPE(conditions) = 'OBJECT')",

		// Testi dei campi, una riga per lingua compresa la principale: nessun testo ha una lingua
		// implicita. label può contenere HTML limitato (il consenso con il link alla privacy);
		// placeholder ed error_message NULL = valore standard; options è {valore: etichetta} e
		// l'ordine delle scelte viene da config.
		'form_fields_i18n'        => "
			field_id       BIGINT UNSIGNED NOT NULL,
			lang           VARCHAR(16)     {$bin} NOT NULL,
			label          TEXT            NOT NULL,
			placeholder    VARCHAR(255)    NULL,
			error_message  TEXT            NULL,
			options        JSON            NULL,
			PRIMARY KEY (field_id, lang),
			CONSTRAINT {$p}bffi_field_fk FOREIGN KEY (field_id)
				REFERENCES {$t}form_fields (id) ON DELETE CASCADE,
			CONSTRAINT {$p}bffi_lang_chk CHECK (lang REGEXP {$lang}),
			CONSTRAINT {$p}bffi_options_chk CHECK (JSON_TYPE(options) = 'OBJECT')",

		// --- Registrazioni: ciò che il visitatore ha inviato ---------------------------------------
		// Immutabili: dopo l'invio cambiano solo is_read, le note e l'azzeramento di user_id.

		// Una registrazione, legata alla versione compilata (RESTRICT: la versione non si cancella).
		// form_id ripete il form della versione per gli indici dell'elenco: la FK composta garantisce
		// che coincidano. lang è la lingua in cui il visitatore ha letto il form. Privacy by default:
		// niente IP, user agent o referrer; source_url senza query string né frammento.
		'entries'                 => "
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id     BIGINT UNSIGNED NOT NULL,
			version_id  BIGINT UNSIGNED NOT NULL,
			lang        VARCHAR(16)     {$bin} NOT NULL,
			is_read     TINYINT(1)      NOT NULL DEFAULT 0,
			user_id     BIGINT UNSIGNED NULL,
			source_url  VARCHAR(2048)   NULL,
			created_at  DATETIME        NOT NULL,
			PRIMARY KEY (id),
			KEY form_created (form_id, created_at),
			KEY form_read (form_id, is_read),
			KEY form_version (form_id, version_id),
			KEY created (created_at),
			CONSTRAINT {$p}be_version_fk FOREIGN KEY (form_id, version_id)
				REFERENCES {$t}form_versions (form_id, id) ON DELETE RESTRICT,
			CONSTRAINT {$p}be_read_chk CHECK (is_read IN (0, 1)),
			CONSTRAINT {$p}be_lang_chk CHECK (lang REGEXP {$lang}),
			CONSTRAINT {$p}be_url_chk CHECK (source_url NOT REGEXP '[?#]')",

		// Un valore per riga: una checkbox con tre scelte occupa tre righe (position). Si salva il
		// valore dell'opzione, non l'etichetta, quindi filtri e statistiche non dipendono dalla lingua.
		// field_value serve a filtri, statistiche e ricerca GDPR per email.
		'entry_values'            => "
			entry_id  BIGINT UNSIGNED   NOT NULL,
			field_id  BIGINT UNSIGNED   NOT NULL,
			position  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			value     TEXT              NOT NULL,
			PRIMARY KEY (entry_id, field_id, position),
			KEY field_value (field_id, value(191)),
			CONSTRAINT {$p}bev_entry_fk FOREIGN KEY (entry_id)
				REFERENCES {$t}entries (id) ON DELETE CASCADE,
			CONSTRAINT {$p}bev_field_fk FOREIGN KEY (field_id)
				REFERENCES {$t}form_fields (id) ON DELETE RESTRICT",

		// File caricati, salvati nella cartella protetta con un nome casuale (stored_path); mime_type è
		// rilevato dal contenuto, non dichiarato dal client. RESTRICT verso la registrazione: sul disco
		// c'è un file, quindi prima lo elimina EntryRepository e poi si cancellano le righe.
		'entry_files'             => "
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id       BIGINT UNSIGNED NOT NULL,
			field_id       BIGINT UNSIGNED NOT NULL,
			stored_path    VARCHAR(191)    {$bin} NOT NULL,
			original_name  VARCHAR(255)    NOT NULL,
			mime_type      VARCHAR(127)    NOT NULL,
			size_bytes     INT UNSIGNED    NOT NULL,
			created_at     DATETIME        NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY stored_path (stored_path),
			KEY entry (entry_id),
			KEY field (field_id),
			CONSTRAINT {$p}befi_entry_fk FOREIGN KEY (entry_id)
				REFERENCES {$t}entries (id) ON DELETE RESTRICT,
			CONSTRAINT {$p}befi_field_fk FOREIGN KEY (field_id)
				REFERENCES {$t}form_fields (id) ON DELETE RESTRICT,
			CONSTRAINT {$p}befi_size_chk CHECK (size_bytes > 0)",

		// Storico della registrazione: esito di notifiche e integrazioni, note degli admin.
		// source è 'notification', 'admin' o lo slug di un'integrazione; user_id è NULL per le note
		// scritte dal plugin e per quelle di utenti poi cancellati.
		'entry_notes'             => "
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entry_id    BIGINT UNSIGNED NOT NULL,
			source      VARCHAR(64)     {$bin} NOT NULL,
			level       VARCHAR(8)      {$bin} NOT NULL,
			message     TEXT            NOT NULL,
			user_id     BIGINT UNSIGNED NULL,
			created_at  DATETIME        NOT NULL,
			PRIMARY KEY (id),
			KEY entry_created (entry_id, created_at),
			CONSTRAINT {$p}ben_entry_fk FOREIGN KEY (entry_id)
				REFERENCES {$t}entries (id) ON DELETE CASCADE,
			CONSTRAINT {$p}ben_level_chk CHECK (level IN ('info', 'success', 'error'))",
	];

	foreach ( $tables as $name => $columns ) {
		Db::query( "CREATE TABLE IF NOT EXISTS {$t}{$name} ({$columns}\n\t) ENGINE=InnoDB {$charset}" );
	}
};
