<?php
if (!defined('ABSPATH')) exit;

class SUITE_BARRIO_Consent_Log {

    const DB_VERSION      = '1.0.0';
    const DB_VERSION_OPT  = 'wpb_cb_log_db_version';
    const THROTTLE_MAX    = 20;   // insert massimi per IP anonimizzato
    const THROTTLE_WINDOW = 60;   // finestra in secondi

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'wpb_consent_log';
    }

    /* ── Installazione tabella ── */
    public static function install(): void {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- nome tabella e charset fissi definiti dal plugin, non input utente; operazione di installazione/aggiornamento schema, eseguita una tantum, non un dato di runtime
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                id           VARCHAR(36)   NOT NULL,
                status       VARCHAR(10)   NOT NULL,
                ip_address   VARCHAR(45)   NOT NULL DEFAULT '',
                created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                url          VARCHAR(2048) NOT NULL DEFAULT '',
                user_agent   VARCHAR(512)  NOT NULL DEFAULT '',
                categories   VARCHAR(255)  NOT NULL DEFAULT '',
                consent_text TEXT          NOT NULL,
                PRIMARY KEY (id),
                KEY created_at (created_at)
            ) {$charset};"
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
    }

    public static function maybe_upgrade(): void {
        $installed = get_option(self::DB_VERSION_OPT, '0');
        if (version_compare($installed, self::DB_VERSION, '<')) {
            self::install();
            update_option(self::DB_VERSION_OPT, self::DB_VERSION, false);
        }
    }

    /* ── IP anonimizzato ── */
    public static function anonymize_ip(string $ip): string {
        if ($ip === '') return '';
        if (strpos($ip, ':') !== false) {
            // IPv6: azzera gli ultimi 80 bit (mantiene i primi 48 bit)
            $parts = explode(':', $ip);
            $parts = array_pad(array_slice($parts, 0, 3), count($parts), '0');
            return implode(':', $parts);
        }
        // IPv4: azzera l'ultimo ottetto
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = '0';
            return implode('.', $parts);
        }
        return $ip;
    }

    /* ── Anti-abuso: throttle per IP anonimizzato ── */
    public static function is_throttled(string $ip_anon): bool {
        if ($ip_anon === '') return false;
        $key   = 'wpb_cb_log_rate_' . md5($ip_anon);
        $count = (int) get_transient($key);
        if ($count >= self::THROTTLE_MAX) return true;
        set_transient($key, $count + 1, self::THROTTLE_WINDOW);
        return false;
    }

    /* ── Insert (created_at è affidato al DEFAULT CURRENT_TIMESTAMP della tabella) ── */
    public static function insert(array $row): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- insert su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $ok = $wpdb->insert(self::table(), [
            'id'           => $row['id'],
            'status'       => $row['status'],
            'ip_address'   => $row['ip_address'],
            'url'          => $row['url'],
            'user_agent'   => $row['user_agent'],
            'categories'   => $row['categories'],
            'consent_text' => $row['consent_text'],
        ]);
        if ($ok === false) {
            return false;
        }
        return true;
    }

    /* ── Query per export ── */
    public static function get_range(string $from, string $to): array {
        global $wpdb;
        $table = self::table();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- nome tabella fisso definito dal plugin, non input utente; query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, status, ip_address, created_at, url, user_agent, categories, consent_text
                 FROM `{$table}`
                 WHERE created_at BETWEEN %s AND %s
                 ORDER BY created_at ASC",
                $from . ' 00:00:00',
                $to . ' 23:59:59'
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }
}

register_activation_hook(SUITE_BARRIO_PLUGIN_DIR . 'suite-barrio.php', ['SUITE_BARRIO_Consent_Log', 'install']);
add_action('plugins_loaded', ['SUITE_BARRIO_Consent_Log', 'maybe_upgrade']);
