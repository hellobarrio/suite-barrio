<?php
/**
 * Suite Barrio — Conservazione registrazioni dei form.
 *
 * Ogni giorno (WP-Cron) elimina definitivamente le registrazioni più vecchie
 * della durata impostata in Forms → Impostazioni. Rileva da solo dove sono salvate:
 *  - tabella {prefix}wpb_form_submissions (modulo Forms di Suite Barrio),
 *    compresi i file caricati tramite i campi "file";
 *  - post del post type "registrazione" (siti legacy con ACF, es. Centro Ceramiche Canale).
 * Caricato sempre: il cron continua a girare anche se il modulo Forms viene disattivato.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('SUITE_BARRIO_RETENTION_OPTION', 'suite_barrio_forms_retention');
define('SUITE_BARRIO_RETENTION_CRON', 'suite_barrio_forms_retention_purge');
define('SUITE_BARRIO_RETENTION_DEFAULT_DAYS', 730); // 2 anni
define('SUITE_BARRIO_RETENTION_POST_TYPE', 'registrazione');

/* ─────────────────────────────────────────
   CRON giornaliero
   ───────────────────────────────────────── */
add_action(SUITE_BARRIO_RETENTION_CRON, 'suite_barrio_retention_purge');

add_action('init', function () {
    if (!wp_next_scheduled(SUITE_BARRIO_RETENTION_CRON))
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', SUITE_BARRIO_RETENTION_CRON);
});

register_deactivation_hook(SUITE_BARRIO_PLUGIN_DIR . 'suite-barrio.php', function () {
    wp_clear_scheduled_hook(SUITE_BARRIO_RETENTION_CRON);
});

/* ─────────────────────────────────────────
   HELPER
   ───────────────────────────────────────── */
function suite_barrio_retention_settings(): array
{
    $opts = (array) get_option(SUITE_BARRIO_RETENTION_OPTION, []);
    return [
        'enabled' => !empty($opts['enabled']),
        'days' => absint($opts['days'] ?? 0) ?: SUITE_BARRIO_RETENTION_DEFAULT_DAYS,
    ];
}

function suite_barrio_retention_table(): string
{
    global $wpdb;
    $table = $wpdb->prefix . 'wpb_form_submissions'; // = SUITE_BARRIO_FORMS_SUB_TABLE, non sempre caricata
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- verifica esistenza tabella custom
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table ? $table : '';
}

/**
 * Elimina i file caricati con una registrazione del modulo Forms
 * (voci "_file_*" con url dentro uploads).
 */
function suite_barrio_retention_delete_files(string $json): void
{
    $uploads = wp_get_upload_dir();
    $base = untrailingslashit((string) wp_parse_url($uploads['baseurl'], PHP_URL_PATH));
    foreach ((array) json_decode($json, true) as $entry) {
        $path = rawurldecode((string) wp_parse_url($entry['url'] ?? '', PHP_URL_PATH));
        if (empty($entry['is_file']) || !str_starts_with($path, $base . '/'))
            continue;
        // Verifica che il file resti dentro uploads prima di cancellarlo.
        wp_delete_file_from_directory($uploads['basedir'] . substr($path, strlen($base)), $uploads['basedir']);
    }
}

/* ─────────────────────────────────────────
   PURGE
   ───────────────────────────────────────── */
/**
 * @return int Numero di registrazioni eliminate.
 */
function suite_barrio_retention_purge(): int
{
    $settings = suite_barrio_retention_settings();
    if (!$settings['enabled'])
        return 0;
    $days = $settings['days'];
    $deleted = 0;

    global $wpdb;

    // 1. Modulo Forms. created_at usa l'orologio MySQL: anche il cutoff.
    $table = suite_barrio_retention_table();
    if ($table) {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabella custom del plugin, nome fisso
        $cutoff = $wpdb->get_var($wpdb->prepare('SELECT DATE_SUB(NOW(), INTERVAL %d DAY)', $days));
        $with_files = $wpdb->get_col($wpdb->prepare(
            "SELECT data FROM `{$table}` WHERE created_at < %s AND data LIKE %s",
            $cutoff,
            '%' . $wpdb->esc_like('"is_file":true') . '%'
        ));
        array_map('suite_barrio_retention_delete_files', $with_files);
        $deleted += (int) $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE created_at < %s", $cutoff));
        // phpcs:enable
    }

    // 2. Siti legacy: post type "registrazione" (anche se non registrato dal tema).
    // ponytail: 500 post per esecuzione, un arretrato maggiore si smaltisce nei giorni successivi.
    $ids = get_posts([
        'post_type' => SUITE_BARRIO_RETENTION_POST_TYPE,
        'post_status' => 'any',
        'date_query' => [['before' => $days . ' days ago']],
        'fields' => 'ids',
        'posts_per_page' => 500,
        'orderby' => 'date',
        'order' => 'ASC',
        'no_found_rows' => true,
    ]);
    foreach ($ids as $id)
        $deleted += wp_delete_post($id, true) ? 1 : 0;

    return $deleted;
}

/* ─────────────────────────────────────────
   UI: card nella tab Forms → Impostazioni (salvata via AJAX
   suite_barrio_forms_save_global_settings)
   ───────────────────────────────────────── */
function suite_barrio_retention_render(): void
{
    global $wpdb;
    $settings = suite_barrio_retention_settings();
    $sources = [];
    if (suite_barrio_retention_table())
        $sources[] = 'Forms Suite Barrio';
    // Query diretta: wp_count_posts() ignora i post type non registrati.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- controllo leggero solo nella tab Impostazioni di Forms
    if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", SUITE_BARRIO_RETENTION_POST_TYPE)))
        $sources[] = 'post type «' . SUITE_BARRIO_RETENTION_POST_TYPE . '»';
    ?>
    <div class="suite-barrio-section-box" style="margin-bottom:20px;">
        <div class="suite-barrio-section-head">
            <span class="dashicons dashicons-trash" style="font-size:18px;width:18px;height:18px;"></span>
            Conservazione registrazioni
        </div>
        <div class="suite-barrio-section-body">
            <p style="margin:0 0 16px;font-size:12px;color:#a7aaad;">Elimina definitivamente, una volta al giorno,
                le registrazioni (e i file caricati) più vecchie della durata impostata. Vale per
                <strong>tutti</strong> i form. La pulizia viene eseguita anche subito al salvataggio. Origini rilevate:
                <strong><?php echo esc_html($sources ? implode(', ', $sources) : 'nessuna'); ?></strong>.</p>
            <table class="form-table" style="max-width:100%;">
                <tbody>
                    <tr>
                        <th style="width:220px;">Autoeliminazione</th>
                        <td>
                            <label><input type="checkbox" id="suite-barrio-retention-enabled" value="1"
                                    <?php checked($settings['enabled']); ?>> Attiva</label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="suite-barrio-retention-days">Durata (giorni)</label></th>
                        <td>
                            <input type="number" id="suite-barrio-retention-days" class="small-text" min="1" step="1"
                                value="<?php echo esc_attr($settings['days']); ?>">
                            <p class="description">Predefinito
                                <?php echo esc_html(SUITE_BARRIO_RETENTION_DEFAULT_DAYS); ?> giorni (2 anni). Lascia
                                vuoto per usare il predefinito.</p>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p style="margin:16px 0 0;">
                <button type="button" class="suite-barrio-btn suite-barrio-btn-primary"
                    id="suite-barrio-btn-save-global-ret">
                    Salva impostazioni conservazione
                </button>
                <span id="suite-barrio-global-saved-msg-ret"
                    style="display:none;margin-left:10px;color:#059669;font-size:13px;">✓ Salvato</span>
            </p>
        </div>
    </div>
    <?php
}
