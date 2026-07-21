<?php
/**
 * Suite Barrio — Banners
 * Gestione di banner a scorrimento (marquee) configurabili. Solo uno può essere attivo alla volta.
 * Ogni banner ha: titolo, link (url + target).
 * Esposto via GraphQL con la query hbBanner.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('SUITE_BARRIO_BANNERS_VERSION', '1.1.0');
define('SUITE_BARRIO_BANNERS_TABLE', 'wpb_banners');
define('SUITE_BARRIO_BANNERS_TRANS_TABLE', 'wpb_banners_translations');

/* ─────────────────────────────────────────
   INSTALL: crea le tabelle al primo avvio
   ───────────────────────────────────────── */
function suite_barrio_banners_install(): void
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- nome tabella e charset collate fissi definiti dal plugin/da WP, non input utente; creazione tabella custom in fase di installazione/aggiornamento schema, eseguita una tantum, non un dato di runtime
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}wpb_banners` (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title       VARCHAR(255)    NOT NULL DEFAULT '',
            link        VARCHAR(2048)   NOT NULL DEFAULT '',
            link_target VARCHAR(10)     NOT NULL DEFAULT '_self',
            active      TINYINT(1)      NOT NULL DEFAULT 0,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};"
    );
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

    // Tabella traduzioni banner (title, link, link_target per lingua aggiuntiva)
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- nome tabella e charset collate fissi definiti dal plugin/da WP, non input utente; creazione tabella custom in fase di installazione/aggiornamento schema, eseguita una tantum, non un dato di runtime
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}wpb_banners_translations` (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            banner_id   BIGINT UNSIGNED NOT NULL,
            lang        VARCHAR(10)     NOT NULL DEFAULT '',
            title       VARCHAR(255)    NOT NULL DEFAULT '',
            link        VARCHAR(2048)   NOT NULL DEFAULT '',
            link_target VARCHAR(10)     NOT NULL DEFAULT '_self',
            updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY banner_lang (banner_id, lang)
        ) {$charset};"
    );
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

register_activation_hook(SUITE_BARRIO_PLUGIN_DIR . 'suite-barrio.php', 'suite_barrio_banners_install');

add_action('plugins_loaded', function () {
    suite_barrio_banners_install();

    $installed_version = get_option('wpb_banners_db_version', '0');

    // Migrazione v1.1: crea la tabella traduzioni se non esiste ancora
    if (version_compare($installed_version, '1.1.0', '<')) {
        suite_barrio_banners_install();
        update_option('wpb_banners_db_version', SUITE_BARRIO_BANNERS_VERSION, false);
    }
});

/* ─────────────────────────────────────────
   HELPER DB
   ───────────────────────────────────────── */
function suite_barrio_banners_get_all(): array
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_results(
        "SELECT id, title, active, created_at FROM {$wpdb->prefix}wpb_banners ORDER BY id DESC"
    ) ?: [];
}

function suite_barrio_banners_get(int $id): ?object
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wpb_banners WHERE id = %d",
        $id
    )) ?: null;
}

function suite_barrio_banners_get_active(): ?object
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_row(
        "SELECT * FROM {$wpdb->prefix}wpb_banners WHERE active = 1 LIMIT 1"
    ) ?: null;
}

/* ─────────────────────────────────────────
   HELPER DB — TRADUZIONI
   ───────────────────────────────────────── */

function suite_barrio_banners_get_translation(int $banner_id, string $lang): ?object
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wpb_banners_translations
         WHERE banner_id = %d AND lang = %s",
        $banner_id,
        $lang
    )) ?: null;
}

function suite_barrio_banners_save_translation(int $banner_id, string $lang, array $data): bool
{
    global $wpdb;
    $table = $wpdb->prefix . SUITE_BARRIO_BANNERS_TRANS_TABLE;
    $existing = suite_barrio_banners_get_translation($banner_id, $lang);

    $row = [
        'banner_id' => $banner_id,
        'lang' => $lang,
        'title' => $data['title'] ?? '',
        'link' => $data['link'] ?? '',
        'link_target' => $data['link_target'] ?? '_self',
    ];
    $format = ['%d', '%s', '%s', '%s', '%s'];

    if ($existing) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $ok = $wpdb->update($table, $row, ['id' => (int) $existing->id], $format, ['%d']);
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $ok = $wpdb->insert($table, $row, $format);
    }

    return $ok !== false;
}

function suite_barrio_banners_delete_translations(int $banner_id): void
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->delete(
        $wpdb->prefix . SUITE_BARRIO_BANNERS_TRANS_TABLE,
        ['banner_id' => $banner_id],
        ['%d']
    );
}

/* ─────────────────────────────────────────
   TEMPLATE HELPER
   Cerca il template prima nel tema (suite-barrio/<name>),
   poi nel plugin (<module>/templates/<name>).
   Il terzo parametro è la cartella templates del modulo chiamante.
   ───────────────────────────────────────── */
if (!function_exists('suite_barrio_get_template')) {
    function suite_barrio_get_template(string $name, array $data = [], string $plugin_templates_dir = ''): string
    {
        $theme_file = locate_template('suite-barrio/' . $name);
        $plugin_file = $plugin_templates_dir ? (rtrim($plugin_templates_dir, '/') . '/' . $name) : '';
        $file = $theme_file ?: $plugin_file;

        if (!$file || !is_readable($file))
            return '';

        ob_start();
        extract($data, EXTR_SKIP);
        include $file;
        return ob_get_clean();
    }
}

/* ─────────────────────────────────────────
   SHORTCODE: [suite_barrio_banner]
   Template override: suite-barrio/banner.php nel tema.
   Variabili disponibili nel template: $uid, $title, $link, $link_target, $rel
   ───────────────────────────────────────── */
add_shortcode('wpb_banner', 'suite_barrio_banner_shortcode');

function suite_barrio_banner_shortcode(): string
{
    $data = suite_barrio_banner_resolve_active();

    if (!$data)
        return '';

    // Registra/accoda CSS e JS del marquee (idempotente: se già fatto in
    // wp_enqueue_scripts per questa request, wp_add_inline_* non duplica nulla).
    suite_barrio_banner_enqueue_frontend_assets($data['uid']);

    return suite_barrio_get_template('banner.php', [
        'uid' => $data['uid'],
        'title' => $data['title'],
        'link' => $data['link'],
        'link_target' => $data['link_target'],
        'rel' => '_blank' === $data['link_target'] ? ' rel="noopener noreferrer"' : '',
    ], __DIR__ . '/templates');
}

/**
 * Risolve il banner attivo (con eventuale traduzione per la lingua corrente)
 * e restituisce i dati necessari per il rendering, oppure null se non c'è
 * nessun banner da mostrare. Condivisa tra lo shortcode e l'hook
 * wp_enqueue_scripts, che deve girare PRIMA che wp_head stampi gli stili.
 */
function suite_barrio_banner_resolve_active(): ?array
{
    $banner = suite_barrio_banners_get_active();

    if (!$banner)
        return null;

    $title = (string) $banner->title;
    $link = (string) $banner->link;
    $link_target = $banner->link_target ?: '_self';

    $lang = '';
    if (defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE) {
        $lang = ICL_LANGUAGE_CODE;
    } elseif (function_exists('apply_filters')) {
        $lang = (string) apply_filters('wpml_current_language', '');
    }
    if ($lang) {
        $tr = suite_barrio_banners_get_translation((int) $banner->id, $lang);
        if ($tr) {
            if ('' !== $tr->title)
                $title = (string) $tr->title;
            if ('' !== $tr->link)
                $link = (string) $tr->link;
            if ('' !== $tr->link_target)
                $link_target = (string) $tr->link_target;
        }
    }

    if ('' === $title)
        return null;

    $link_target = in_array($link_target, ['_self', '_blank'], true) ? $link_target : '_self';

    return [
        'banner' => $banner,
        'title' => $title,
        'link' => $link,
        'link_target' => $link_target,
        'uid' => 'suite-barrio-banner-' . (int) $banner->id,
    ];
}

/**
 * Registra ed accoda CSS/JS del marquee banner frontend tramite le API di
 * enqueue di WordPress (wp_register_style/script + wp_add_inline_style/script),
 * al posto di stampare <style>/<script> direttamente nell'HTML.
 */
function suite_barrio_banner_enqueue_frontend_assets(string $uid): void
{
    wp_register_style('suite-barrio-banner', '', [], SUITE_BARRIO_BANNERS_VERSION);
    wp_enqueue_style('suite-barrio-banner');

    wp_register_script('suite-barrio-banner', '', [], SUITE_BARRIO_BANNERS_VERSION, true);
    wp_enqueue_script('suite-barrio-banner');

    // Questa funzione può essere richiamata due volte per la stessa request
    // (una volta in anticipo da wp_enqueue_scripts, una volta dallo shortcode
    // stesso): il flag evita di accodare due volte lo stesso CSS/JS inline.
    static $assets_added = false;
    if ($assets_added)
        return;
    $assets_added = true;

    $css = '.suite-barrio-banner {
    position: relative;
    overflow: hidden;
    width: 100%;
}
.suite-barrio-banner__inner {
    display: flex;
    align-items: center;
}
.suite-barrio-banner__track {
    display: flex;
    align-items: center;
    will-change: transform;
}
.suite-barrio-banner__item {
    white-space: nowrap;
    text-transform: uppercase;
}';
    wp_add_inline_style('suite-barrio-banner', $css);

    $js = sprintf(
        '(function () {
    var t1    = document.getElementById(%s);
    var t2    = document.getElementById(%s);
    var inner = document.getElementById(%s);
    if (!t1 || !t2 || !inner) return;

    var x = 0, targetV = 80, currentV = 80, lastTs = null, trackW = 0;

    function lerp(a, b, t) { return a + (b - a) * t; }

    inner.addEventListener(\'mouseenter\', function () { targetV = 0; });
    inner.addEventListener(\'mouseleave\', function () { targetV = 80; });

    function tick(ts) {
        if (lastTs === null) { lastTs = ts; requestAnimationFrame(tick); return; }
        var delta = ts - lastTs;
        lastTs = ts;

        if (trackW === 0) trackW = t1.getBoundingClientRect().width;

        currentV = lerp(currentV, targetV, 0.05);
        x -= (currentV * delta) / 1000;

        if (trackW > 0 && x <= -(trackW + 20)) x = 0;

        var tx = \'translateX(\' + x + \'px)\';
        t1.style.transform = tx;
        t2.style.transform = tx;

        requestAnimationFrame(tick);
    }

    requestAnimationFrame(tick);
})();',
        wp_json_encode($uid . '-t1'),
        wp_json_encode($uid . '-t2'),
        wp_json_encode($uid . '-inner')
    );

    wp_add_inline_script('suite-barrio-banner', $js);
}

/* ─────────────────────────────────────────
   FRONTEND: enqueue anticipato di CSS/JS del banner.
   Gira su wp_enqueue_scripts (prima che wp_head stampi gli stili),
   così lo <style> inline risulta comunque presente in pagina anche se
   lo shortcode [suite_barrio_banner] viene renderizzato più avanti nel <body>.
   ───────────────────────────────────────── */
add_action('wp_enqueue_scripts', function () {
    $data = suite_barrio_banner_resolve_active();
    if ($data) {
        suite_barrio_banner_enqueue_frontend_assets($data['uid']);
    }
});

/* ─────────────────────────────────────────
   MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (current_user_can('manage_options')) {
        // Amministratori: sottovoce nel menu Suite Barrio
        add_submenu_page(
            'suite-barrio',
            'Banners — Suite Barrio',
            'Banners',
            'manage_options',
            'suite-barrio-banners',
            'suite_barrio_banners_admin_page'
        );
    } elseif (current_user_can('edit_others_posts')) {
        // Editor e ruoli con edit_others_posts: voce principale nel menu admin
        add_menu_page(
            'Banners — Suite Barrio',
            'Banners',
            'edit_others_posts',
            'suite-barrio-banners',
            'suite_barrio_banners_admin_page',
            'dashicons-flag',
            77
        );
    }
}, 12);

/* ─────────────────────────────────────────
   AJAX: salva banner (crea o aggiorna)
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_banners_save', function () {
    check_ajax_referer('suite_barrio_banners_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $table = $wpdb->prefix . SUITE_BARRIO_BANNERS_TABLE;

    $id = intval($_POST['banner_id'] ?? 0);
    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $link = sanitize_url(wp_unslash($_POST['link'] ?? ''));
    $link_target_raw = sanitize_text_field(wp_unslash($_POST['link_target'] ?? ''));
    $link_target = in_array($link_target_raw, ['_self', '_blank'], true)
        ? $link_target_raw
        : '_self';

    if ($title === '')
        wp_send_json_error('Il titolo è obbligatorio.');

    $data = compact('title', 'link', 'link_target');
    $format = ['%s', '%s', '%s'];

    if ($id > 0) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $ok = $wpdb->update($table, $data, ['id' => $id], $format, ['%d']);
    } else {
        $data['active'] = 0;
        $format[] = '%d';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $ok = $wpdb->insert($table, $data, $format);
        $id = (int) $wpdb->insert_id;
    }

    if ($ok === false)
        wp_send_json_error('Errore DB: ' . $wpdb->last_error);

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:banner');
    }

    wp_send_json_success(['id' => $id, 'message' => 'Banner salvato.']);
});

/* ─────────────────────────────────────────
   AJAX: imposta banner attivo
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_banners_set_active', function () {
    check_ajax_referer('suite_barrio_banners_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $table = $wpdb->prefix . SUITE_BARRIO_BANNERS_TABLE;
    $id = intval($_POST['banner_id'] ?? 0);

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- nome tabella fisso definito dal plugin, non input utente; query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->query("UPDATE {$table} SET active = 0");

    if ($id > 0) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $wpdb->update($table, ['active' => 1], ['id' => $id], ['%d'], ['%d']);
    }

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:banner');
    }

    wp_send_json_success('Banner attivo aggiornato.');
});

/* ─────────────────────────────────────────
   AJAX: elimina banner
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_banners_delete', function () {
    check_ajax_referer('suite_barrio_banners_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $id = intval($_POST['banner_id'] ?? 0);
    if (!$id)
        wp_send_json_error('ID non valido.');

    suite_barrio_banners_delete_translations($id);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->delete($wpdb->prefix . SUITE_BARRIO_BANNERS_TABLE, ['id' => $id], ['%d']);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:banner');
    }
    wp_send_json_success('Banner eliminato.');
});

/* ─────────────────────────────────────────
   AJAX: salva traduzione banner
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_banners_save_translation', function () {
    check_ajax_referer('suite_barrio_banners_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $banner_id = intval($_POST['banner_id'] ?? 0);
    $lang = sanitize_key($_POST['lang'] ?? '');

    if (!$banner_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    if (!suite_barrio_banners_get($banner_id))
        wp_send_json_error('Banner non trovato.');

    $allowed_langs = suite_barrio_multilang_translation_languages();
    if (!in_array($lang, $allowed_langs, true))
        wp_send_json_error('Lingua non consentita.');

    $data = [
        'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
        'link' => sanitize_url(wp_unslash($_POST['link'] ?? '')),
        'link_target' => (function () {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce già verificato con check_ajax_referer('suite_barrio_banners_nonce', 'nonce') a inizio di questa stessa callback (riga 429); falso positivo dello scanner dovuto alla closure annidata, che non "vede" il controllo nonce nella funzione esterna
            $link_target_raw = sanitize_text_field(wp_unslash($_POST['link_target'] ?? ''));
            return in_array($link_target_raw, ['_self', '_blank'], true) ? $link_target_raw : '_self';
        })(),
    ];

    if ($data['title'] === '')
        wp_send_json_error('Il titolo è obbligatorio.');

    $ok = suite_barrio_banners_save_translation($banner_id, $lang, $data);

    if (!$ok)
        wp_send_json_error('Errore DB.');

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:banner');
    }

    wp_send_json_success(['message' => 'Traduzione salvata.']);
});

/* ─────────────────────────────────────────
   AJAX: carica traduzione banner
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_banners_get_translation', function () {
    check_ajax_referer('suite_barrio_banners_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $banner_id = intval($_POST['banner_id'] ?? 0);
    $lang = sanitize_key($_POST['lang'] ?? '');

    if (!$banner_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    $row = suite_barrio_banners_get_translation($banner_id, $lang);

    wp_send_json_success([
        'exists' => (bool) $row,
        'title' => $row->title ?? '',
        'link' => $row->link ?? '',
        'link_target' => $row->link_target ?? '_self',
    ]);
});

/* ─────────────────────────────────────────
   AJAX: inizializza traduzione (copia dal principale)
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_banners_init_translation', function () {
    check_ajax_referer('suite_barrio_banners_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $banner_id = intval($_POST['banner_id'] ?? 0);
    $lang = sanitize_key($_POST['lang'] ?? '');

    if (!$banner_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    $allowed_langs = suite_barrio_multilang_translation_languages();
    if (!in_array($lang, $allowed_langs, true))
        wp_send_json_error('Lingua non consentita.');

    if (suite_barrio_banners_get_translation($banner_id, $lang))
        wp_send_json_success(['already_exists' => true]);

    $source = suite_barrio_banners_get($banner_id);
    if (!$source)
        wp_send_json_error('Banner non trovato.');

    $ok = suite_barrio_banners_save_translation($banner_id, $lang, [
        'title' => $source->title ?? '',
        'link' => $source->link ?? '',
        'link_target' => $source->link_target ?? '_self',
    ]);

    if (!$ok)
        wp_send_json_error('Errore DB.');

    wp_send_json_success(['already_exists' => false]);
});

/* ─────────────────────────────────────────
   AJAX ADMIN: salva limite banner
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_banners_save_limit', function () {
    check_ajax_referer('suite_barrio_banners_nonce', 'nonce');
    if (!suite_barrio_tools_can_access())
        wp_send_json_error('Permesso negato.');

    $limit = max(1, intval($_POST['banner_limit'] ?? 1));
    update_option('wpb_banners_limit', $limit, false);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:banner');
    }
    wp_send_json_success('Limite salvato.');
});

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */

/**
 * Calcola i dati di contesto per la pagina admin Banners: nonce, banner in
 * modifica e stato multilingua. Usata sia dal markup della pagina
 * (suite_barrio_banners_admin_page) sia dall'enqueue dello script inline via
 * admin_enqueue_scripts, che gira PRIMA della callback di rendering della
 * pagina e quindi non può leggere le variabili locali calcolate lì.
 */
function suite_barrio_banners_admin_page_context(): array
{
    $nonce = wp_create_nonce('suite_barrio_banners_nonce');

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $edit_id = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0;
    $editing = $edit_id ? suite_barrio_banners_get($edit_id) : null;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $qs_lang = isset($_GET['lang']) ? sanitize_key($_GET['lang']) : '';

    $ml_enabled = suite_barrio_multilang_is_enabled();
    $ml_primary = suite_barrio_multilang_primary_language();
    $ml_trans = suite_barrio_multilang_translation_languages();
    $ml_languages = suite_barrio_multilang_languages_with_flags();
    $ml_active = $ml_enabled && !empty($ml_trans);

    // Stessa logica usata in origine nel markup dell'editor: la lingua
    // iniziale della select ha senso solo modificando un banner esistente
    // (non "new") con multilingua attivo.
    $initial_lang = $ml_primary;
    if ($editing && $ml_active) {
        $banner_id_int = (int) $editing->id;
        $editor_langs = [$ml_primary => true];
        foreach ($ml_trans as $lc) {
            if (suite_barrio_banners_get_translation($banner_id_int, $lc)) {
                $editor_langs[$lc] = true;
            }
        }
        $initial_lang = isset($editor_langs[$qs_lang]) ? $qs_lang : $ml_primary;
    }

    return [
        'nonce' => $nonce,
        'editing' => $editing,
        'ml_active' => $ml_active,
        'ml_primary' => $ml_primary,
        'ml_languages' => $ml_languages,
        'initial_lang' => $initial_lang,
    ];
}

function suite_barrio_banners_admin_page(): void
{
    // Dati calcolati anche in suite_barrio_banners_admin_page_context(), condivisa con
    // l'enqueue dello script via admin_enqueue_scripts (che gira prima di questa
    // callback, quindi non può leggere variabili locali calcolate qui).
    $ctx = suite_barrio_banners_admin_page_context();
    $nonce = $ctx['nonce'];
    $editing = $ctx['editing'];
    $ml_primary = $ctx['ml_primary'];
    $ml_languages = $ctx['ml_languages'];
    $ml_active = $ctx['ml_active'];

    $banners = suite_barrio_banners_get_all();
    $active = suite_barrio_banners_get_active();
    $active_id = $active ? (int) $active->id : 0;
    $banner_limit = (int) get_option('wpb_banners_limit', 1);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $list_tab = isset($_GET['list_tab']) ? sanitize_key($_GET['list_tab']) : 'banners';

    $ml_trans = suite_barrio_multilang_translation_languages();
    ?>
    <div id="suite-barrio-banners-wrap" class="wrap suite-barrio-wrap">

        <!-- Header -->
        <div class="suite-barrio-ban-header">
            <div class="suite-barrio-ban-header-icon"><span class="dashicons dashicons-flag"></span></div>
            <div>
                <h1>Suite Barrio <span class="suite-barrio-ban-breadcrumb">/ Banners</span></h1>
                <p class="suite-barrio-ban-sub">Gestisci banner marquee per il frontend</p>
            </div>
        </div>
        <hr class="suite-barrio-ban-divider">

        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
        if ($editing || (isset($_GET['edit_id']) && $_GET['edit_id'] === 'new')):
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
            $is_new = (sanitize_text_field(wp_unslash($_GET['edit_id'] ?? ''))) === 'new';
            $banner = $is_new ? null : $editing;
            ?>

            <!-- ── EDITOR ── -->
            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-banners')); ?>" class="suite-barrio-ban-back">
                <span class="dashicons dashicons-arrow-left-alt2" style="font-size:15px;width:15px;height:15px;"></span>
                Torna alla lista
            </a>

            <div class="suite-barrio-ban-editor">
                <input type="hidden" id="suite-barrio-banner-id" value="<?php echo $is_new ? 0 : intval($banner->id ?? 0); ?>">

                <?php if ($ml_active && !$is_new):
                    $banner_id_int = (int) ($banner->id ?? 0);
                    $editor_langs = [$ml_primary => ($ml_languages[$ml_primary] ?? $ml_primary) . ' (principale)'];
                    foreach ($ml_trans as $lc) {
                        if (suite_barrio_banners_get_translation($banner_id_int, $lc)) {
                            $editor_langs[$lc] = $ml_languages[$lc] ?? strtoupper($lc);
                        }
                    }
                    $initial_lang = $ctx['initial_lang'];
                    ?>
                    <div class="suite-barrio-lang-bar">
                        <label for="suite-barrio-lang-select">
                            <span class="dashicons dashicons-translation"
                                style="font-size:15px;width:15px;height:15px;vertical-align:middle;margin-right:3px;color:#6b7280;"></span>
                            Lingua:
                        </label>
                        <select id="suite-barrio-lang-select">
                            <?php foreach ($editor_langs as $lc => $label): ?>
                                <option value="<?php echo esc_attr($lc); ?>"
                                    data-is-primary="<?php echo $lc === $ml_primary ? '1' : '0'; ?>" <?php selected($initial_lang, $lc); ?>><?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="suite-barrio-trans-notice" id="suite-barrio-trans-notice"
                        style="display:<?php echo $initial_lang !== $ml_primary ? 'flex' : 'none'; ?>;">
                        <span class="dashicons dashicons-translation"></span>
                        Stai modificando la versione in <strong
                            id="suite-barrio-trans-notice-lang"><?php echo esc_html($ml_languages[$initial_lang] ?? $initial_lang); ?></strong>.
                        Lo stato attivo è condiviso con la versione principale.
                    </div>

                    <?php
                    if ($initial_lang !== $ml_primary) {
                        $trans_data = suite_barrio_banners_get_translation($banner_id_int, $initial_lang);
                        if ($trans_data)
                            $banner = $trans_data;
                    }
                endif; ?>

                <div class="suite-barrio-ban-editor-grid">

                    <!-- Colonna sinistra: campi -->
                    <div>
                        <div class="suite-barrio-efield">
                            <label class="suite-barrio-efield-label" for="suite-barrio-banner-title">Titolo <span
                                    style="color:#dc2626;">*</span></label>
                            <input type="text" id="suite-barrio-banner-title" value="<?php echo esc_attr($banner->title ?? ''); ?>"
                                placeholder="Es. Spedizione gratuita su tutti gli ordini">
                        </div>

                        <div class="suite-barrio-efield">
                            <label class="suite-barrio-efield-label">Link <span class="suite-barrio-efield-optional">(opzionale)</span></label>
                            <div class="suite-barrio-link-grid">
                                <div>
                                    <input type="url" id="suite-barrio-banner-link" value="<?php echo esc_attr($banner->link ?? ''); ?>"
                                        placeholder="https://…">
                                    <p class="suite-barrio-efield-desc">URL</p>
                                </div>
                                <div>
                                    <select id="suite-barrio-banner-link-target">
                                        <option value="_self" <?php selected(($banner->link_target ?? '_self'), '_self'); ?>>
                                            Stessa scheda</option>
                                        <option value="_blank" <?php selected(($banner->link_target ?? '_self'), '_blank'); ?>>
                                            Nuova scheda</option>
                                    </select>
                                    <p class="suite-barrio-efield-desc">Target</p>
                                </div>
                            </div>
                        </div>

                        <div class="suite-barrio-editor-actions">
                            <button type="button" class="suite-barrio-btn suite-barrio-btn-primary" id="suite-barrio-banner-save-btn">
                                <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                                Salva banner
                            </button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-banners')); ?>"
                                class="suite-barrio-btn suite-barrio-btn-ghost">Annulla</a>
                            <span id="suite-barrio-banner-save-msg" class="suite-barrio-editor-msg" style="display:none;"></span>
                        </div>
                    </div>

                    <!-- Colonna destra: anteprima -->
                    <div style="position:sticky;top:32px;">
                        <div class="suite-barrio-preview-label">Anteprima</div>

                        <!-- Chrome mock -->
                        <div class="suite-barrio-preview-browser-chrome">
                            <div class="suite-barrio-preview-dots">
                                <span style="background:#ff5f57;"></span>
                                <span style="background:#febc2e;"></span>
                                <span style="background:#28c840;"></span>
                            </div>
                            <div class="suite-barrio-preview-urlbar"></div>
                        </div>

                        <!-- Banner marquee -->
                        <div class="suite-barrio-marquee-outer">
                            <div class="suite-barrio-marquee-track" id="suite-barrio-marquee-track">
                                <?php for ($i = 0; $i < 6; $i++): ?>
                                    <span class="suite-barrio-marquee-item">
                                        <span
                                            class="suite-barrio-prev-title"><?php echo esc_html($banner->title ?? 'Titolo del banner'); ?></span>
                                        <?php if (!empty($banner->link)): ?>
                                            <a class="suite-barrio-marquee-link suite-barrio-prev-link" href="#"
                                                onclick="return false;"><?php echo esc_html($banner->link); ?></a>
                                        <?php else: ?>
                                            <a class="suite-barrio-marquee-link suite-barrio-prev-link" href="#" onclick="return false;"
                                                style="display:none;"></a>
                                        <?php endif; ?>
                                        <span class="suite-barrio-marquee-sep"></span>
                                    </span>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <!-- Finta pagina sotto -->
                        <div class="suite-barrio-preview-page">
                            <div class="suite-barrio-preview-line" style="width:70%;"></div>
                            <div class="suite-barrio-preview-line" style="width:90%;"></div>
                            <div class="suite-barrio-preview-line" style="width:50%;margin-bottom:0;"></div>
                        </div>

                        <p class="suite-barrio-preview-hint">L'anteprima si aggiorna in tempo reale</p>
                    </div>

                </div><!-- .suite-barrio-ban-editor-grid -->
            </div><!-- .suite-barrio-ban-editor -->

        <?php else: ?>

            <!-- ── LISTA ── -->

            <?php if (suite_barrio_tools_can_access()): ?>
                <div class="suite-barrio-ban-tabs">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-banners')); ?>"
                        class="suite-barrio-ban-tab <?php echo $list_tab === 'banners' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-flag" style="font-size:15px;width:15px;height:15px;"></span>
                        Banner
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-banners&list_tab=settings')); ?>"
                        class="suite-barrio-ban-tab <?php echo $list_tab === 'settings' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-admin-settings" style="font-size:15px;width:15px;height:15px;"></span>
                        Impostazioni
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($list_tab === 'settings' && suite_barrio_tools_can_access()): ?>

                <!-- Tab impostazioni -->
                <div class="suite-barrio-ban-settings-card">
                    <h3 class="suite-barrio-ban-settings-title">Limiti</h3>
                    <p class="suite-barrio-ban-settings-desc">Numero massimo di banner che possono essere creati. Raggiunto il limite, il
                        pulsante «+ Nuovo» viene disabilitato.</p>
                    <div class="suite-barrio-efield" style="margin-bottom:16px;">
                        <label class="suite-barrio-efield-label" for="suite-barrio-banner-limit-input">Numero massimo di banner</label>
                        <input type="number" id="suite-barrio-banner-limit-input" min="1" max="999"
                            value="<?php echo intval($banner_limit); ?>"
                            style="width:110px;padding:8px 12px;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;font-family:inherit;">
                        <p class="suite-barrio-efield-desc">Default: 1. Valori alti rimuovono in pratica il limite.</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <button type="button" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-btn-sm" id="suite-barrio-save-limit-btn">
                            <span class="dashicons dashicons-yes" style="font-size:15px;width:15px;height:15px;"></span>
                            Salva
                        </button>
                        <span id="suite-barrio-save-limit-msg" class="suite-barrio-ban-save-ok">&#x2713; Salvato</span>
                    </div>
                </div>

            <?php else: ?>

                <!-- Toolbar lista -->
                <div class="suite-barrio-ban-toolbar">
                    <?php $at_limit = count($banners) >= $banner_limit; ?>
                    <?php if ($at_limit): ?>
                        <button class="suite-barrio-btn suite-barrio-btn-primary" disabled
                            title="Limite di <?php echo intval($banner_limit); ?> banner raggiunto"
                            style="opacity:.45;cursor:not-allowed;">
                            + Nuovo banner
                        </button>
                    <?php else: ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-banners&edit_id=new')); ?>"
                            class="suite-barrio-btn suite-barrio-btn-primary">+ Nuovo banner</a>
                    <?php endif; ?>
                </div>

                <!-- Notice banner attivo -->
                <?php if (!empty($active)): ?>
                    <div id="suite-barrio-active-banner-notice" class="suite-barrio-ban-notice suite-barrio-ban-notice-success">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <div>Banner attivo: <strong><?php echo esc_html($active->title); ?></strong></div>
                    </div>
                <?php else: ?>
                    <div id="suite-barrio-active-banner-notice" class="suite-barrio-ban-notice suite-barrio-ban-notice-warning">
                        <span class="dashicons dashicons-warning"></span>
                        <div>Nessun banner attivo al momento.</div>
                    </div>
                <?php endif; ?>

                <?php if (empty($banners)): ?>
                    <div class="suite-barrio-ban-empty">
                        <span class="dashicons dashicons-flag"></span>
                        <p class="suite-barrio-ban-empty-title">Nessun banner ancora</p>
                        <p class="suite-barrio-ban-empty-sub">Crea il tuo primo banner in pochi secondi.</p>
                        <?php if (count($banners) < $banner_limit): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-banners&edit_id=new')); ?>"
                                class="suite-barrio-btn suite-barrio-btn-primary">+ Nuovo banner</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>

                    <div id="suite-barrio-banners-list">
                        <?php foreach ($banners as $b):
                            $is_active = (int) $b->id === $active_id;
                            ?>
                            <div class="suite-barrio-ban-card" data-id="<?php echo intval($b->id); ?>">
                                <div class="suite-barrio-ban-row">
                                    <!-- Info -->
                                    <div>
                                        <div class="suite-barrio-ban-title"><?php echo esc_html($b->title); ?></div>
                                        <div class="suite-barrio-ban-meta">Creato il
                                            <?php echo esc_html(date_i18n('d/m/Y', strtotime($b->created_at))); ?>
                                        </div>
                                    </div>
                                    <!-- Badge -->
                                    <div>
                                        <?php if ($is_active): ?>
                                            <span class="suite-barrio-badge-on">
                                                <span class="suite-barrio-badge-on-dot"></span>
                                                Attivo
                                            </span>
                                        <?php else: ?>
                                            <span class="suite-barrio-badge-off">Inattivo</span>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Azioni -->
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <label class="suite-barrio-toggle-active" title="<?php echo $is_active ? 'Disattiva' : 'Attiva'; ?>">
                                            <input type="checkbox" class="suite-barrio-toggle-active-cb" data-id="<?php echo intval($b->id); ?>"
                                                <?php checked($is_active); ?>>
                                            <span class="suite-barrio-toggle-active-track"></span>
                                        </label>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-banners&edit_id=' . $b->id)); ?>"
                                            class="suite-barrio-btn suite-barrio-btn-ghost suite-barrio-btn-sm" title="Modifica">
                                            <span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;"></span>
                                        </a>
                                        <button type="button" class="suite-barrio-btn suite-barrio-btn-danger suite-barrio-btn-sm suite-barrio-banner-delete-btn"
                                            data-id="<?php echo intval($b->id); ?>" title="Elimina">
                                            <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span>
                                        </button>
                                    </div>
                                </div><!-- .suite-barrio-ban-row -->

                                <?php if ($ml_active): ?>
                                    <div class="suite-barrio-ban-langs">
                                        <span class="suite-barrio-ban-langs-label">Lingue:</span>
                                        <span class="suite-barrio-lang-pill primary" title="Lingua principale">
                                            <span class="dashicons dashicons-yes" style="font-size:12px;width:12px;height:12px;"></span>
                                            <?php echo esc_html($ml_languages[$ml_primary] ?? strtoupper($ml_primary)); ?>
                                        </span>
                                        <?php foreach ($ml_trans as $lang_code):
                                            $lang_label = $ml_languages[$lang_code] ?? strtoupper($lang_code);
                                            $has_trans = (bool) suite_barrio_banners_get_translation((int) $b->id, $lang_code);
                                            ?>
                                            <?php if ($has_trans): ?>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-banners&edit_id=' . $b->id . '&lang=' . $lang_code)); ?>"
                                                    class="suite-barrio-lang-pill translated"
                                                    title="Modifica traduzione in <?php echo esc_attr($lang_label); ?>">
                                                    <span class="dashicons dashicons-yes" style="font-size:12px;width:12px;height:12px;"></span>
                                                    <?php echo esc_html($lang_label); ?>
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="suite-barrio-lang-pill missing suite-barrio-lang-init-btn"
                                                    data-banner-id="<?php echo intval($b->id); ?>" data-lang="<?php echo esc_attr($lang_code); ?>"
                                                    data-lang-label="<?php echo esc_attr($lang_label); ?>"
                                                    title="Inizializza traduzione in <?php echo esc_attr($lang_label); ?>">
                                                    + <?php echo esc_html($lang_label); ?>
                                                </button>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                            </div><!-- .suite-barrio-ban-card -->
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

    </div><!-- #suite-barrio-banners-wrap -->

    <?php
}

/**
 * Costruisce il JS inline della pagina admin Banners a partire dal
 * contesto calcolato da suite_barrio_banners_admin_page_context(). I valori dinamici
 * sono sempre passati attraverso wp_json_encode(), come nell'originale.
 */
function suite_barrio_banners_admin_page_js(array $ctx): string
{
    $editing = $ctx['editing'];

    $js = '';
    $js .= '        (function () {
            var nonce = ';

    $js .= wp_json_encode($ctx['nonce']);

    $js .= ';
            var ajaxUrl = ';

    $js .= wp_json_encode(admin_url('admin-ajax.php'));

    $js .= ';
            var urlEditBase = ';

    $js .= wp_json_encode(admin_url('admin.php?page=suite-barrio-banners&edit_id='));

    $js .= ';

            var mlActive = ';

    $js .= wp_json_encode($ctx['ml_active']);

    $js .= ';
            var mlPrimary = ';

    $js .= wp_json_encode($ctx['ml_primary']);

    $js .= ';
            var mlLanguages = ';

    $js .= wp_json_encode($ctx['ml_languages']);

    $js .= ';
            var currentLang = ';

    $js .= wp_json_encode($ctx['initial_lang']);

    $js .= ';

            /* ════════════════════════════════════════
               MULTILINGUA — select lingua nell\'editor
               ════════════════════════════════════════ */
            (function () {
                if (!mlActive) return;

                var langSelect = document.getElementById(\'suite-barrio-lang-select\');
                var notice = document.getElementById(\'suite-barrio-trans-notice\');
                var noticeLang = document.getElementById(\'suite-barrio-trans-notice-lang\');
                var saveBtn = document.getElementById(\'suite-barrio-banner-save-btn\');

                if (!langSelect) return;

                function loadPrimaryFields() {
                    [\'suite-barrio-banner-title\', \'suite-barrio-banner-link\', \'suite-barrio-banner-link-target\'].forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el && el.dataset.primary !== undefined) el.value = el.dataset.primary;
                    });
                    refreshMarqueeFromFields();
                }

                function fillTranslationFields(data) {
                    var title = document.getElementById(\'suite-barrio-banner-title\');
                    var link = document.getElementById(\'suite-barrio-banner-link\');
                    var linkTarget = document.getElementById(\'suite-barrio-banner-link-target\');
                    if (title) title.value = data.title || \'\';
                    if (link) link.value = data.link || \'\';
                    if (linkTarget) linkTarget.value = data.link_target || \'_self\';
                    refreshMarqueeFromFields();
                }

                function switchLang(lang, isPrimary) {
                    currentLang = lang;
                    if (saveBtn) {
                        var icon = \'<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> \';
                        saveBtn.innerHTML = icon + (isPrimary ? \'Salva banner\' : \'Salva traduzione\');
                    }
                    if (notice && noticeLang) {
                        if (isPrimary) { notice.style.display = \'none\'; }
                        else { noticeLang.textContent = mlLanguages[lang] || lang; notice.style.display = \'flex\'; }
                    }
                    if (isPrimary) { loadPrimaryFields(); return; }

                    var bannerId = document.getElementById(\'suite-barrio-banner-id\').value;
                    if (!bannerId || bannerId === \'0\') return;

                    langSelect.disabled = true;
                    fetch(ajaxUrl, {
                        method: \'POST\',
                        body: new URLSearchParams({ action: \'suite_barrio_banners_get_translation\', nonce: nonce, banner_id: bannerId, lang: lang })
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (res) { if (res.success) fillTranslationFields(res.data); })
                        .catch(function () { })
                        .finally(function () { langSelect.disabled = false; });
                }

                langSelect.addEventListener(\'change\', function () {
                    var opt = this.options[this.selectedIndex];
                    switchLang(this.value, opt.dataset.isPrimary === \'1\');
                });

                setTimeout(function () {
                    var primaryData = ';

    $js .= wp_json_encode(['title' => $editing->title ?? '', 'link' => $editing->link ?? '', 'link_target' => $editing->link_target ?? '_self']);

    $js .= ';
                    [\'suite-barrio-banner-title\', \'suite-barrio-banner-link\', \'suite-barrio-banner-link-target\'].forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el) {
                            var key = id.replace(\'suite-barrio-banner-\', \'\').replace(/-/g, \'_\');
                            el.dataset.primary = primaryData[key] !== undefined ? primaryData[key] : el.value;
                        }
                    });
                    var initialOpt = langSelect.options[langSelect.selectedIndex];
                    if (initialOpt && initialOpt.dataset.isPrimary === \'0\') {
                        var icon = \'<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> \';
                        if (saveBtn) saveBtn.innerHTML = icon + \'Salva traduzione\';
                    }
                }, 300);
            })();

            /* ════════════════════════════════════════
               LISTA — inizializza traduzione
               ════════════════════════════════════════ */
            document.querySelectorAll(\'.suite-barrio-lang-init-btn\').forEach(function (btn) {
                btn.addEventListener(\'click\', function () {
                    var bannerId = this.dataset.bannerId;
                    var lang = this.dataset.lang;
                    var langLabel = this.dataset.langLabel;
                    var pill = this;

                    pill.classList.add(\'loading\');
                    pill.disabled = true;

                    fetch(ajaxUrl, {
                        method: \'POST\',
                        body: new URLSearchParams({ action: \'suite_barrio_banners_init_translation\', nonce: nonce, banner_id: bannerId, lang: lang })
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) {
                                var link = document.createElement(\'a\');
                                link.href = urlEditBase + bannerId + \'&lang=\' + lang;
                                link.className = \'suite-barrio-lang-pill translated\';
                                link.title = \'Modifica traduzione in \' + langLabel;
                                link.innerHTML = \'<span class="dashicons dashicons-yes" style="font-size:12px;width:12px;height:12px;"></span> \' + langLabel;
                                pill.replaceWith(link);
                            } else {
                                alert(res.data || \'Errore.\');
                                pill.classList.remove(\'loading\');
                                pill.disabled = false;
                            }
                        })
                        .catch(function () {
                            alert(\'Errore di rete.\');
                            pill.classList.remove(\'loading\');
                            pill.disabled = false;
                        });
                });
            });

            /* ════════════════════════════════════════
               IMPOSTAZIONI — salva limite
               ════════════════════════════════════════ */
            var saveLimitBtn = document.getElementById(\'suite-barrio-save-limit-btn\');
            if (saveLimitBtn) {
                saveLimitBtn.addEventListener(\'click\', function () {
                    var btn = this;
                    var msg = document.getElementById(\'suite-barrio-save-limit-msg\');
                    var val = parseInt(document.getElementById(\'suite-barrio-banner-limit-input\').value, 10) || 1;
                    btn.disabled = true;
                    fetch(ajaxUrl, {
                        method: \'POST\',
                        body: new URLSearchParams({ action: \'suite_barrio_banners_save_limit\', nonce: nonce, banner_limit: val })
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) {
                                msg.style.display = \'inline\';
                                setTimeout(function () { msg.style.display = \'none\'; }, 3000);
                            } else { alert(res.data || \'Errore.\'); }
                        })
                        .catch(function () { alert(\'Errore di rete.\'); })
                        .finally(function () { btn.disabled = false; });
                });
            }

            /* ════════════════════════════════════════
               LISTA — toggle attiva/disattiva
               ════════════════════════════════════════ */
            document.querySelectorAll(\'.suite-barrio-toggle-active-cb\').forEach(function (cb) {
                cb.addEventListener(\'change\', function () {
                    var bannerId = this.checked ? parseInt(this.dataset.id, 10) : 0;
                    document.querySelectorAll(\'.suite-barrio-toggle-active-cb\').forEach(function (c) {
                        c.checked = false;
                        updateRowBadge(c.closest(\'.suite-barrio-ban-card\'), false);
                    });
                    if (bannerId) { this.checked = true; updateRowBadge(this.closest(\'.suite-barrio-ban-card\'), true); }

                    fetch(ajaxUrl, {
                        method: \'POST\',
                        body: new URLSearchParams({ action: \'suite_barrio_banners_set_active\', nonce: nonce, banner_id: bannerId })
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (!res.success) { alert(res.data || \'Errore.\'); location.reload(); }
                            else { updateActiveBannerNotice(bannerId); }
                        })
                        .catch(function () { alert(\'Errore di rete.\'); location.reload(); });
                });
            });

            function updateRowBadge(card, isActive) {
                if (!card) return;
                var badge = card.querySelector(\'.suite-barrio-badge-on, .suite-barrio-badge-off\');
                if (!badge) return;
                if (isActive) {
                    badge.className = \'suite-barrio-badge-on\';
                    badge.innerHTML = \'<span class="suite-barrio-badge-on-dot"></span> Attivo\';
                } else {
                    badge.className = \'suite-barrio-badge-off\';
                    badge.textContent = \'Inattivo\';
                }
            }

            function updateActiveBannerNotice(activeId) {
                var notice = document.getElementById(\'suite-barrio-active-banner-notice\');
                if (!notice) return;
                if (!activeId) {
                    notice.className = \'suite-barrio-ban-notice suite-barrio-ban-notice-warning\';
                    notice.innerHTML = \'<span class="dashicons dashicons-warning" style="font-size:17px;width:17px;height:17px;flex-shrink:0;margin-top:1px;color:#d97706;"></span><div>Nessun banner attivo al momento.</div>\';
                    return;
                }
                var card = document.querySelector(\'.suite-barrio-ban-card[data-id="\' + activeId + \'"]\');
                var title = card ? card.querySelector(\'.suite-barrio-ban-title\').textContent : \'\';
                notice.className = \'suite-barrio-ban-notice suite-barrio-ban-notice-success\';
                notice.innerHTML = \'<span class="dashicons dashicons-yes-alt" style="font-size:17px;width:17px;height:17px;flex-shrink:0;margin-top:1px;color:#059669;"></span><div>Banner attivo: <strong>\' + title + \'</strong></div>\';
            }

            /* ════════════════════════════════════════
               LISTA — elimina
               ════════════════════════════════════════ */
            document.querySelectorAll(\'.suite-barrio-banner-delete-btn\').forEach(function (btn) {
                btn.addEventListener(\'click\', function () {
                    if (!confirm(\'Eliminare questo banner?\')) return;
                    var id = parseInt(this.dataset.id, 10);
                    var card = this.closest(\'.suite-barrio-ban-card\');
                    fetch(ajaxUrl, {
                        method: \'POST\',
                        body: new URLSearchParams({ action: \'suite_barrio_banners_delete\', nonce: nonce, banner_id: id })
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) {
                                card.style.transition = \'opacity .3s\';
                                card.style.opacity = \'0\';
                                setTimeout(function () { card.remove(); }, 300);
                            } else { alert(res.data || \'Errore.\'); }
                        })
                        .catch(function () { alert(\'Errore di rete.\'); });
                });
            });

            /* ════════════════════════════════════════
               EDITOR — anteprima live marquee
               ════════════════════════════════════════ */
            function refreshMarqueeFromFields() {
                var titleVal = (document.getElementById(\'suite-barrio-banner-title\') || {}).value || \'Titolo del banner\';
                var linkVal = (document.getElementById(\'suite-barrio-banner-link\') || {}).value || \'\';
                document.querySelectorAll(\'#suite-barrio-marquee-track .suite-barrio-prev-title\').forEach(function (el) {
                    el.textContent = titleVal.trim() || \'Titolo del banner\';
                });
                document.querySelectorAll(\'#suite-barrio-marquee-track .suite-barrio-prev-link\').forEach(function (el) {
                    if (linkVal.trim()) { el.style.display = \'\'; el.textContent = linkVal.trim(); }
                    else { el.style.display = \'none\'; }
                });
            }

            (function () {
                var titleInput = document.getElementById(\'suite-barrio-banner-title\');
                var linkInput = document.getElementById(\'suite-barrio-banner-link\');
                if (titleInput) titleInput.addEventListener(\'input\', refreshMarqueeFromFields);
                if (linkInput) linkInput.addEventListener(\'input\', refreshMarqueeFromFields);
                refreshMarqueeFromFields();
            })();

            /* ════════════════════════════════════════
               EDITOR — salva
               ════════════════════════════════════════ */
            var saveBtn = document.getElementById(\'suite-barrio-banner-save-btn\');
            if (saveBtn) {
                saveBtn.addEventListener(\'click\', function () {
                    var btn = this;
                    var msg = document.getElementById(\'suite-barrio-banner-save-msg\');
                    var title = document.getElementById(\'suite-barrio-banner-title\').value.trim();

                    if (!title) {
                        msg.className = \'suite-barrio-editor-msg err\'; msg.style.display = \'inline\';
                        msg.textContent = \'✗ Il titolo è obbligatorio.\';
                        return;
                    }

                    btn.disabled = true;
                    btn.innerHTML = \'<div style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:suiteBarrioMarquee .0001s,spin .65s linear infinite;vertical-align:middle;margin-right:4px;"></div> Salvataggio…\';
                    msg.style.display = \'none\';

                    var bannerId = document.getElementById(\'suite-barrio-banner-id\').value;
                    var isPrimary = !mlActive || currentLang === mlPrimary;
                    var fd;

                    if (isPrimary) {
                        fd = new URLSearchParams({
                            action: \'suite_barrio_banners_save\', nonce: nonce, banner_id: bannerId,
                            title: title,
                            link: document.getElementById(\'suite-barrio-banner-link\').value.trim(),
                            link_target: document.getElementById(\'suite-barrio-banner-link-target\').value,
                        });
                    } else {
                        if (!bannerId || bannerId === \'0\') {
                            msg.className = \'suite-barrio-editor-msg err\'; msg.style.display = \'inline\';
                            msg.textContent = \'✗ Salva prima il banner in lingua principale.\';
                            btn.disabled = false;
                            btn.innerHTML = \'<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> Salva traduzione\';
                            return;
                        }
                        fd = new URLSearchParams({
                            action: \'suite_barrio_banners_save_translation\', nonce: nonce, banner_id: bannerId,
                            lang: currentLang, title: title,
                            link: document.getElementById(\'suite-barrio-banner-link\').value.trim(),
                            link_target: document.getElementById(\'suite-barrio-banner-link-target\').value,
                        });
                    }

                    fetch(ajaxUrl, { method: \'POST\', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            var icon = \'<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> \';
                            if (res.success) {
                                msg.className = \'suite-barrio-editor-msg ok\'; msg.style.display = \'inline\';
                                msg.textContent = isPrimary ? \'✓ Banner salvato.\' : \'✓ Traduzione salvata.\';
                                if (isPrimary && bannerId === \'0\') {
                                    setTimeout(function () { window.location.href = urlEditBase + res.data.id; }, 800);
                                }
                            } else {
                                msg.className = \'suite-barrio-editor-msg err\'; msg.style.display = \'inline\';
                                msg.textContent = \'✗ \' + (res.data || \'Errore.\');
                            }
                            btn.disabled = false;
                            btn.innerHTML = icon + (isPrimary ? \'Salva banner\' : \'Salva traduzione\');
                        })
                        .catch(function () {
                            var icon = \'<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> \';
                            msg.className = \'suite-barrio-editor-msg err\'; msg.style.display = \'inline\';
                            msg.textContent = \'✗ Errore di rete.\';
                            btn.disabled = false;
                            btn.innerHTML = icon + (isPrimary ? \'Salva banner\' : \'Salva traduzione\');
                        });
                });
            }

        })();
';

    return $js;
}

/* ─────────────────────────────────────────
   ENQUEUE SCRIPT — pagina admin Banners
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'suite-barrio-banners') === false)
        return;

    wp_register_script('suite-barrio-banners-admin', '', [], SUITE_BARRIO_BANNERS_VERSION, true);
    wp_enqueue_script('suite-barrio-banners-admin');

    $ctx = suite_barrio_banners_admin_page_context();
    wp_add_inline_script('suite-barrio-banners-admin', suite_barrio_banners_admin_page_js($ctx));
});
