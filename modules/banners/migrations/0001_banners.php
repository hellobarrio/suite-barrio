<?php

use BarrioSuite\Database\Db;

defined( 'ABSPATH' ) || exit;

// I banner e i loro testi per lingua. Stesse regole dello schema dei form (docs/database.md).
return static function ( wpdb $wpdb ): void {
	$p       = $wpdb->prefix;
	$t       = "{$p}barrio_";
	$charset = $wpdb->get_charset_collate();

	// Periodo in UTC, NULL = senza inizio o senza fine. L'indice serve agli accesi non scaduti, letti nel sito.
	Db::query(
		"CREATE TABLE IF NOT EXISTS {$t}banners (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			is_enabled  TINYINT(1)      NOT NULL DEFAULT 0,
			new_tab     TINYINT(1)      NOT NULL DEFAULT 0,
			starts_at   DATETIME        NULL,
			ends_at     DATETIME        NULL,
			created_at  DATETIME        NOT NULL,
			updated_at  DATETIME        NOT NULL,
			PRIMARY KEY (id),
			KEY enabled_ends (is_enabled, ends_at),
			CONSTRAINT {$p}bb_enabled_chk CHECK (is_enabled IN (0, 1)),
			CONSTRAINT {$p}bb_tab_chk CHECK (new_tab IN (0, 1)),
			CONSTRAINT {$p}bb_period_chk CHECK (ends_at > starts_at)
		) ENGINE=InnoDB {$charset}"
	);

	// Una riga completa per lingua, compresa la principale. link_url NULL = il banner non è un link.
	Db::query(
		"CREATE TABLE IF NOT EXISTS {$t}banners_i18n (
			banner_id  BIGINT UNSIGNED NOT NULL,
			lang       VARCHAR(16)     CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			text       VARCHAR(255)    NOT NULL,
			link_url   VARCHAR(2048)   NULL,
			PRIMARY KEY (banner_id, lang),
			CONSTRAINT {$p}bbi_banner_fk FOREIGN KEY (banner_id)
				REFERENCES {$t}banners (id) ON DELETE CASCADE,
			CONSTRAINT {$p}bbi_lang_chk CHECK (lang REGEXP '^[a-z]{2,3}(-[a-z0-9]{2,8})*$'),
			CONSTRAINT {$p}bbi_text_chk CHECK (CHAR_LENGTH(text) > 0),
			CONSTRAINT {$p}bbi_url_chk CHECK (link_url REGEXP '^(/|https?://)')
		) ENGINE=InnoDB {$charset}"
	);
};
