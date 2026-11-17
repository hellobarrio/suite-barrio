<?php

use BarrioSuite\Database\Db;

defined( 'ABSPATH' ) || exit;

// Impostazioni per form delle integrazioni delle estensioni (Mailchimp…), il "feed" di Gravity Forms:
// configurazione corrente come le notifiche, quindi non versionata, e si sostituisce a ogni salvataggio.
// Stesse regole dello schema iniziale (docs/database.md).
return static function ( wpdb $wpdb ): void {
	$p       = $wpdb->prefix;
	$t       = "{$p}barrio_";
	$charset = $wpdb->get_charset_collate();
	$bin     = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';

	$tables = [
		// Un'integrazione per form al massimo. slug è quello con cui l'estensione si registra: aperto
		// come il tipo dei campi, quindi le impostazioni si validano in PHP con lo schema dell'estensione.
		// Se l'estensione non è attiva la riga resta e torna valida quando la si riattiva.
		// settings sono le impostazioni uguali in tutte le lingue; conditions NULL = parte sempre.
		'form_integrations'      => "
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id     BIGINT UNSIGNED NOT NULL,
			slug        VARCHAR(64)     {$bin} NOT NULL,
			is_enabled  TINYINT(1)      NOT NULL DEFAULT 1,
			settings    JSON            NOT NULL,
			conditions  JSON            NULL,
			PRIMARY KEY (id),
			UNIQUE KEY form_slug (form_id, slug),
			CONSTRAINT {$p}bfg_form_fk FOREIGN KEY (form_id)
				REFERENCES {$t}forms (id) ON DELETE CASCADE,
			CONSTRAINT {$p}bfg_slug_chk CHECK (slug REGEXP '^[a-z][a-z0-9_-]*$'),
			CONSTRAINT {$p}bfg_enabled_chk CHECK (is_enabled IN (0, 1)),
			CONSTRAINT {$p}bfg_settings_chk CHECK (JSON_TYPE(settings) = 'OBJECT'),
			CONSTRAINT {$p}bfg_cond_chk CHECK (JSON_TYPE(conditions) = 'OBJECT')",

		// Le impostazioni traducibili (per esempio l'audience di Mailchimp per lingua), una riga per
		// lingua come gli altri testi. Senza impostazioni traducibili l'integrazione non ha righe.
		'form_integrations_i18n' => "
			integration_id  BIGINT UNSIGNED NOT NULL,
			lang            VARCHAR(16)     {$bin} NOT NULL,
			settings        JSON            NOT NULL,
			PRIMARY KEY (integration_id, lang),
			CONSTRAINT {$p}bfgi_integ_fk FOREIGN KEY (integration_id)
				REFERENCES {$t}form_integrations (id) ON DELETE CASCADE,
			CONSTRAINT {$p}bfgi_lang_chk CHECK (lang REGEXP '^[a-z]{2,3}(-[a-z0-9]{2,8})*$'),
			CONSTRAINT {$p}bfgi_json_chk CHECK (JSON_TYPE(settings) = 'OBJECT')",
	];

	foreach ( $tables as $name => $columns ) {
		Db::query( "CREATE TABLE IF NOT EXISTS {$t}{$name} ({$columns}\n\t) ENGINE=InnoDB {$charset}" );
	}
};
