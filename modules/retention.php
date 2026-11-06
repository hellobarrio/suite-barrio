<?php
/**
 * Suite Barrio — Conservazione registrazioni dei form.
 *
 * Ogni giorno (WP-Cron) elimina definitivamente le registrazioni più vecchie
 * della durata impostata in Strumenti (in anni). Rileva da solo dove sono salvate:
 *  - tabella {prefix}wpb_form_submissions (modulo Forms di Suite Barrio),
 *    compresi i file caricati tramite i campi "file";
 *  - post del post type "registrazione" (siti legacy con ACF, es. Centro Ceramiche Canale).
 * Da Strumenti si possono anche eliminare subito tutte le registrazioni.
 * Multilingua: si basa solo sulla data, quindi vale per tutte le lingue
 * (la tabella non filtra per "lang", la query sui post ignora i filtri WPML).
 * Caricato sempre, anche con il modulo Forms disattivato.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('SUITE_BARRIO_RETENTION_OPTION', 'suite_barrio_forms_retention');
define('SUITE_BARRIO_RETENTION_CRON', 'suite_barrio_forms_retention_purge');
define('SUITE_BARRIO_RETENTION_DEFAULT_YEARS', 2);
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
   AZIONE: elimina tutte le registrazioni (pulsante in Strumenti)
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_retention_delete_all', function () {
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');
    check_admin_referer('suite_barrio_retention_delete_all');
    $deleted = suite_barrio_retention_delete(0);
    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-tools&deleted=' . $deleted));
    exit;
});

/* ─────────────────────────────────────────
   HELPER
   ───────────────────────────────────────── */
function suite_barrio_retention_settings(): array
{
    $opts = (array) get_option(SUITE_BARRIO_RETENTION_OPTION, []);
    // La 9.1.4 salvava la durata in giorni: la converte in anni arrotondando per eccesso,
    // così non si cancella mai prima di quanto impostato.
    $years = absint($opts['years'] ?? 0) ?: (int) ceil(absint($opts['days'] ?? 0) / 365);
    return [
        'enabled' => !empty($opts['enabled']),
        'years' => $years ?: SUITE_BARRIO_RETENTION_DEFAULT_YEARS,
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
   ELIMINAZIONE
   ───────────────────────────────────────── */
/**
 * Cron e salvataggio impostazioni: elimina le registrazioni scadute,
 * solo se l'autoeliminazione è attiva.
 *
 * @return int Numero di registrazioni eliminate.
 */
function suite_barrio_retention_purge(): int
{
    $settings = suite_barrio_retention_settings();
    return $settings['enabled'] ? suite_barrio_retention_delete($settings['years']) : 0;
}

/**
 * Elimina le registrazioni più vecchie di $years anni (0 = tutte).
 *
 * @return int Numero di registrazioni eliminate.
 */
function suite_barrio_retention_delete(int $years): int
{
    global $wpdb;
    $deleted = 0;

    // 1. Modulo Forms. created_at usa l'orologio MySQL: anche il cutoff.
    $table = suite_barrio_retention_table();
    if ($table) {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- tabella custom del plugin: prefisso + nome fisso, verificata con SHOW TABLES
        $cutoff = $years
            ? $wpdb->get_var($wpdb->prepare('SELECT DATE_SUB(NOW(), INTERVAL %d YEAR)', $years))
            : '9999-12-31 23:59:59';
        $with_files = $wpdb->get_col($wpdb->prepare(
            "SELECT data FROM `{$table}` WHERE created_at < %s AND data LIKE %s",
            $cutoff,
            '%' . $wpdb->esc_like('"is_file":true') . '%'
        ));
        array_map('suite_barrio_retention_delete_files', $with_files);
        $deleted += (int) $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE created_at < %s", $cutoff));
        // phpcs:enable
    }

    // 2. Siti legacy: post type "registrazione" (anche se non registrato dal tema), cestino compreso.
    // ponytail: il cron elimina 500 post per esecuzione (l'arretrato si smaltisce nei giorni
    // successivi); "elimina tutte" li fa in una richiesta: con molte migliaia di post può
    // andare in timeout, basta ripremere il pulsante per continuare.
    $query = [
        'post_type' => SUITE_BARRIO_RETENTION_POST_TYPE,
        'post_status' => ['any', 'trash'],
        'fields' => 'ids',
        'posts_per_page' => $years ? 500 : -1,
        'orderby' => 'date',
        'order' => 'ASC',
        'no_found_rows' => true,
        // get_posts() ha già suppress_filters = true: i filtri lingua di WPML non si applicano.
    ];
    if ($years)
        $query['date_query'] = [['before' => $years . ' years ago']];
    foreach (get_posts($query) as $id)
        $deleted += wp_delete_post($id, true) ? 1 : 0;

    return $deleted;
}

/* ─────────────────────────────────────────
   UI: sezione della pagina Strumenti, dentro il form "Salva impostazioni"
   (salvataggio in suite_barrio_tools_save)
   ───────────────────────────────────────── */
function suite_barrio_retention_render(): void
{
    global $wpdb;
    $settings = suite_barrio_retention_settings();
    $sources = [];
    if (suite_barrio_retention_table())
        $sources[] = 'Forms Suite Barrio';
    // Query diretta: wp_count_posts() ignora i post type non registrati.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- controllo leggero solo nella pagina Strumenti
    if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", SUITE_BARRIO_RETENTION_POST_TYPE)))
        $sources[] = 'post type «' . SUITE_BARRIO_RETENTION_POST_TYPE . '»';
    $delete_all_url = wp_nonce_url(
        admin_url('admin-post.php?action=suite_barrio_retention_delete_all'),
        'suite_barrio_retention_delete_all'
    );
    $confirm = 'Eliminare definitivamente TUTTE le registrazioni di tutti i form, file caricati compresi? L\'operazione non può essere annullata.';
    ?>
    <p class="suite-barrio-tools-section-title" style="margin-top:28px;">Conservazione dati</p>
    <p class="suite-barrio-tools-section-desc">
        Gestisci per quanto tempo conservare le registrazioni dei form. Origini rilevate:
        <strong><?php echo esc_html($sources ? implode(', ', $sources) : 'nessuna'); ?></strong>.
    </p>

    <div class="suite-barrio-modules-list">
        <div class="suite-barrio-module-row">
            <div class="suite-barrio-module-info">
                <div class="suite-barrio-module-label">Autoeliminazione registrazioni</div>
                <div class="suite-barrio-module-desc">
                    Ogni giorno elimina definitivamente le registrazioni (e i file caricati) più vecchie della durata
                    impostata. Vale per tutti i form e viene applicata subito al salvataggio.
                </div>
            </div>
            <label class="suite-barrio-toggle" title="Attiva o disattiva l'autoeliminazione">
                <input type="checkbox" name="retention_enabled" value="1" <?php checked($settings['enabled']); ?>>
                <span class="suite-barrio-toggle-track"></span>
            </label>
        </div>
        <div class="suite-barrio-module-row">
            <div class="suite-barrio-module-info">
                <div class="suite-barrio-module-label">Durata conservazione</div>
                <div class="suite-barrio-module-desc">
                    In anni. Predefinito <?php echo esc_html(SUITE_BARRIO_RETENTION_DEFAULT_YEARS); ?> anni.
                </div>
            </div>
            <span style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#374151;">
                <input type="number" name="retention_years" class="small-text" min="1" step="1"
                    aria-label="Durata conservazione in anni" value="<?php echo esc_attr($settings['years']); ?>">
                anni
            </span>
        </div>
        <div class="suite-barrio-module-row">
            <div class="suite-barrio-module-info">
                <div class="suite-barrio-module-label">Elimina tutte le registrazioni</div>
                <div class="suite-barrio-module-desc">
                    Cancella subito tutte le registrazioni di tutti i form e di tutte le lingue, file caricati
                    compresi. L'operazione non può essere annullata.<br>
                    Con molte migliaia di registrazioni la pagina può fermarsi con un errore di tempo scaduto:
                    quelle già eliminate restano eliminate, ricarica Strumenti e premi di nuovo il pulsante
                    finché il conteggio non arriva a 0.
                </div>
            </div>
            <a href="<?php echo esc_url($delete_all_url); ?>"
                class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-danger" style="white-space:nowrap;"
                onclick="return confirm(<?php echo esc_attr(wp_json_encode($confirm)); ?>);">
                <span class="dashicons dashicons-trash" style="font-size:16px;width:16px;height:16px;"></span>
                Elimina tutte
            </a>
        </div>
    </div>
    <?php
}
