<?php

use BarrioSuite\Database\Db;

defined( 'ABSPATH' ) || exit;

// Redirect del modulo SEO: si leggono a ogni richiesta del frontend con la UNIQUE su source.
// Stesse regole dello schema dei form (docs/database.md).
return static function ( wpdb $wpdb ): void {
	$p       = $wpdb->prefix;
	$charset = $wpdb->get_charset_collate();

	// source è il percorso senza dominio e senza / finale (la radice resta /), con la query string:
	// in utf8mb4_bin perché gli URL distinguono le maiuscole. 768 caratteri sono i 3072 byte massimi
	// di una chiave InnoDB. target è un percorso o un URL assoluto; un 410 non ne ha.
	// is_auto: creato dal cambio di URL di un contenuto o di un termine.
	Db::query(
		"CREATE TABLE IF NOT EXISTS {$p}barrio_redirects (
			id          BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
			source      VARCHAR(768)      CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
			target      VARCHAR(2048)     NULL,
			code        SMALLINT UNSIGNED NOT NULL,
			note        VARCHAR(255)      NULL,
			is_auto     TINYINT(1)        NOT NULL DEFAULT 0,
			created_at  DATETIME          NOT NULL,
			updated_at  DATETIME          NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY source (source),
			CONSTRAINT {$p}br_source_chk CHECK (source LIKE '/%' AND source NOT REGEXP '^/[^?]*/([?]|$)'),
			CONSTRAINT {$p}br_code_chk CHECK (code IN (301, 302, 307, 308, 410)),
			CONSTRAINT {$p}br_target_chk CHECK ((code = 410) = (target IS NULL)),
			CONSTRAINT {$p}br_url_chk CHECK (target REGEXP '^(/|https?://)'),
			CONSTRAINT {$p}br_auto_chk CHECK (is_auto IN (0, 1))
		) ENGINE=InnoDB {$charset}"
	);
};
