<?php

use BarrioSuite\Database\Db;

defined( 'ABSPATH' ) || exit;

// I popup e i loro testi per lingua. Stesse regole dello schema dei form (docs/database.md).
return static function ( wpdb $wpdb ): void {
	$p       = $wpdb->prefix;
	$t       = "{$p}barrio_";
	$charset = $wpdb->get_charset_collate();

	// Periodo in UTC, NULL = senza inizio o senza fine. image_id senza foreign key verso wp_posts: un'immagine eliminata
	// si ignora. settings è letto e scritto intero (nuova scheda, giorni dopo la chiusura). L'indice serve agli
	// accesi non scaduti, letti nel sito.
	Db::query(
		"CREATE TABLE IF NOT EXISTS {$t}popups (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			is_enabled  TINYINT(1)      NOT NULL DEFAULT 0,
			starts_at   DATETIME        NULL,
			ends_at     DATETIME        NULL,
			image_id    BIGINT UNSIGNED NULL,
			settings    JSON            NOT NULL,
			created_at  DATETIME        NOT NULL,
			updated_at  DATETIME        NOT NULL,
			PRIMARY KEY (id),
			KEY enabled_ends (is_enabled, ends_at),
			CONSTRAINT {$p}bp_enabled_chk CHECK (is_enabled IN (0, 1)),
			CONSTRAINT {$p}bp_period_chk CHECK (ends_at > starts_at),
			CONSTRAINT {$p}bp_image_chk CHECK (image_id > 0),
			CONSTRAINT {$p}bp_settings_chk CHECK (JSON_TYPE(settings) = 'OBJECT')
		) ENGINE=InnoDB {$charset}"
	);

	// Una riga completa per lingua, compresa la principale. content è HTML già filtrato (wp_kses_post); il pulsante ha
	// sempre un link, e senza testo usa quello standard.
	Db::query(
		"CREATE TABLE IF NOT EXISTS {$t}popups_i18n (
			popup_id      BIGINT UNSIGNED NOT NULL,
			lang          VARCHAR(16)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			title         VARCHAR(255)    NOT NULL,
			content       MEDIUMTEXT      NULL,
			button_label  VARCHAR(255)    NULL,
			link_url      VARCHAR(2048)   NULL,
			PRIMARY KEY (popup_id, lang),
			CONSTRAINT {$p}bpi_popup_fk FOREIGN KEY (popup_id)
				REFERENCES {$t}popups (id) ON DELETE CASCADE,
			CONSTRAINT {$p}bpi_lang_chk CHECK (lang REGEXP '^[a-z]{2,3}(-[a-z0-9]{2,8})*$'),
			CONSTRAINT {$p}bpi_title_chk CHECK (CHAR_LENGTH(title) > 0),
			CONSTRAINT {$p}bpi_url_chk CHECK (link_url REGEXP '^(/|https?://)'),
			CONSTRAINT {$p}bpi_button_chk CHECK (button_label IS NULL OR link_url IS NOT NULL)
		) ENGINE=InnoDB {$charset}"
	);
};
