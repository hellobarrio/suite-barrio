<?php
/**
 * Suite Barrio — Popups
 * Gestione di popup configurabili. Solo uno può essere attivo alla volta.
 * Ogni popup ha: titolo, testo, link, immagine.
 * Esposto via GraphQL con la query hbPopup.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('SUITE_BARRIO_POPUPS_VERSION', '1.1.0');
define('SUITE_BARRIO_POPUPS_TABLE', 'wpb_popups');
define('SUITE_BARRIO_POPUPS_TRANS_TABLE', 'wpb_popups_translations');

/* ─────────────────────────────────────────
   INSTALL: crea le tabelle al primo avvio
   ───────────────────────────────────────── */
function suite_barrio_popups_install(): void
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // Tabella principale popup
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- nome tabella/charset fissi (non input utente); creazione tabella in fase di installazione, una tantum, nessun object-cache group dedicato
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}wpb_popups` (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title       VARCHAR(255)    NOT NULL DEFAULT '',
            content     LONGTEXT        NOT NULL DEFAULT '',
            link        VARCHAR(2048)   NOT NULL DEFAULT '',
            link_title  VARCHAR(255)    NOT NULL DEFAULT '',
            link_target VARCHAR(10)     NOT NULL DEFAULT '_self',
            image_url   VARCHAR(2048)   NOT NULL DEFAULT '',
            image_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            active      TINYINT(1)      NOT NULL DEFAULT 0,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};"
    );
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

    // Tabella traduzioni popup (contenuti testuali per lingua aggiuntiva)
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- nome tabella/charset fissi (non input utente); creazione tabella in fase di installazione, una tantum, nessun object-cache group dedicato
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}wpb_popups_translations` (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            popup_id    BIGINT UNSIGNED NOT NULL,
            lang        VARCHAR(10)     NOT NULL DEFAULT '',
            title       VARCHAR(255)    NOT NULL DEFAULT '',
            content     LONGTEXT        NOT NULL DEFAULT '',
            link        VARCHAR(2048)   NOT NULL DEFAULT '',
            link_title  VARCHAR(255)    NOT NULL DEFAULT '',
            link_target VARCHAR(10)     NOT NULL DEFAULT '_self',
            updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY popup_lang (popup_id, lang)
        ) {$charset};"
    );
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

register_activation_hook(SUITE_BARRIO_PLUGIN_DIR . 'suite-barrio.php', 'suite_barrio_popups_install');

add_action('plugins_loaded', function () {
    suite_barrio_popups_install();

    $installed_version = get_option('wpb_popups_db_version', '0');

    // Migrazione v1.0: aggiunge le colonne link_title e link_target se mancano
    global $wpdb;
    $table = $wpdb->prefix . SUITE_BARRIO_POPUPS_TABLE;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- nome tabella fisso, non input utente; query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
    if ($columns && !in_array('link_title', $columns, true)) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- nome tabella fisso, non input utente; aggiornamento schema in fase di installazione, una tantum, nessun object-cache group dedicato
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `link_title` VARCHAR(255) NOT NULL DEFAULT '' AFTER `link`");
    }
    if ($columns && !in_array('link_target', $columns, true)) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- nome tabella fisso, non input utente; aggiornamento schema in fase di installazione, una tantum, nessun object-cache group dedicato
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `link_target` VARCHAR(10) NOT NULL DEFAULT '_self' AFTER `link_title`");
    }

    // Migrazione v1.1: crea la tabella traduzioni se non esiste ancora
    if (version_compare($installed_version, '1.1.0', '<')) {
        suite_barrio_popups_install();
        update_option('wpb_popups_db_version', SUITE_BARRIO_POPUPS_VERSION, false);
    }
});

/* ─────────────────────────────────────────
   HELPER DB
   ───────────────────────────────────────── */
function suite_barrio_popups_get_all(): array
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_results(
        "SELECT id, title, active, created_at FROM {$wpdb->prefix}wpb_popups ORDER BY id DESC"
    ) ?: [];
}

function suite_barrio_popups_get(int $id): ?object
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wpb_popups WHERE id = %d",
        $id
    )) ?: null;
}

function suite_barrio_popups_get_active(): ?object
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_row(
        "SELECT * FROM {$wpdb->prefix}wpb_popups WHERE active = 1 LIMIT 1"
    ) ?: null;
}

/* ─────────────────────────────────────────
   HELPER DB — TRADUZIONI
   ───────────────────────────────────────── */

/**
 * Legge la traduzione di un popup per una lingua specifica.
 * Restituisce null se non esiste.
 */
function suite_barrio_popups_get_translation(int $popup_id, string $lang): ?object
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wpb_popups_translations
         WHERE popup_id = %d AND lang = %s",
        $popup_id,
        $lang
    )) ?: null;
}

/**
 * Upsert di una traduzione (inserisce o aggiorna).
 */
function suite_barrio_popups_save_translation(int $popup_id, string $lang, array $data): bool
{
    global $wpdb;
    $table = $wpdb->prefix . SUITE_BARRIO_POPUPS_TRANS_TABLE;

    $existing = suite_barrio_popups_get_translation($popup_id, $lang);

    $row = [
        'popup_id' => $popup_id,
        'lang' => $lang,
        'title' => $data['title'] ?? '',
        'content' => $data['content'] ?? '',
        'link' => $data['link'] ?? '',
        'link_title' => $data['link_title'] ?? '',
        'link_target' => $data['link_target'] ?? '_self',
    ];
    $format = ['%d', '%s', '%s', '%s', '%s', '%s', '%s'];

    if ($existing) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $ok = $wpdb->update($table, $row, ['id' => (int) $existing->id], $format, ['%d']);
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $ok = $wpdb->insert($table, $row, $format);
    }

    return $ok !== false;
}

/**
 * Elimina tutte le traduzioni di un popup (da chiamare prima di delete popup).
 */
function suite_barrio_popups_delete_translations(int $popup_id): void
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->delete(
        $wpdb->prefix . SUITE_BARRIO_POPUPS_TRANS_TABLE,
        ['popup_id' => $popup_id],
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
        $theme_file  = locate_template('suite-barrio/' . $name);
        $plugin_file = $plugin_templates_dir ? (rtrim($plugin_templates_dir, '/') . '/' . $name) : '';
        $file        = $theme_file ?: $plugin_file;

        if (!$file || !is_readable($file))
            return '';

        ob_start();
        extract($data, EXTR_SKIP);
        include $file;
        return ob_get_clean();
    }
}

/* ─────────────────────────────────────────
   SHORTCODE FRONT-END: [suite_barrio_popup]
   Stampa l'UNICO popup attivo (active = 1). Se non c'è popup attivo non
   stampa nulla. Con multilingua, usa la traduzione della lingua corrente
   (fallback ai campi della lingua principale).

   Attributi opzionali:
     delay  → ritardo (ms) prima dell'apertura automatica (default 800)
     days   → giorni di durata del cookie che memorizza la chiusura: dopo aver
              chiuso il popup non si riapre per N giorni (default 7).
              days="0" → nessun cookie: si riapre a ogni caricamento.

   Template override: suite-barrio/popup.php nel tema.
   Variabili disponibili nel template: $wrap_id, $title_id, $id, $delay, $days,
     $title, $content, $link, $link_title, $link_target, $rel, $image

   Es.: [suite_barrio_popup]  oppure  [suite_barrio_popup delay="1500" days="30"]
   ───────────────────────────────────────── */
add_shortcode('wpb_popup', 'suite_barrio_popup_shortcode');

/* ─────────────────────────────────────────
   ENQUEUE: CSS del popup frontend
   Registrata solo se esiste un popup attivo (stesso gate dello shortcode).
   ───────────────────────────────────────── */
add_action('wp_enqueue_scripts', function () {
    if (!suite_barrio_popups_get_active()) {
        return;
    }

    wp_register_style('suite-barrio-popup', '');
    wp_enqueue_style('suite-barrio-popup');
    wp_add_inline_style('suite-barrio-popup', '
.suite-barrio-popup {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    opacity: 0;
    pointer-events: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    transition: opacity .3s ease-in-out;
}
.suite-barrio-popup.is-open {
    opacity: 1;
    pointer-events: all;
}
.suite-barrio-popup__overlay {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, .35);
    cursor: pointer;
}
.suite-barrio-popup__dialog {
    position: relative;
    display: flex;
    flex-wrap: wrap;
    z-index: 1;
    width: 100%;
    max-width: 760px;
    max-height: 90vh;
    transform: translateY(30px);
    overflow: auto;
    background: white;
    color: black;
    box-shadow: 0 30px 60px rgba(0, 0, 0, .35);
    transition: transform .3s ease-in-out;
}
.suite-barrio-popup.is-open .suite-barrio-popup__dialog {
    transform: translateY(0);
}
.suite-barrio-popup__close {
    position: absolute;
    top: 10px;
    right: 12px;
    z-index: 2;
    width: 40px;
    height: 40px;
    border: 0;
    background: white;
    border-radius: 50px;
    font-size: 30px;
    line-height: 1;
    color: black;
    cursor: pointer;
    transition: opacity .3s ease;
}
.suite-barrio-popup__close:hover {
    opacity: .6;
}
.suite-barrio-popup__image {
    display: block;
    width: 40%;
    height: auto;
    aspect-ratio: 1;
    object-fit: cover;
}
.suite-barrio-popup__body {
    padding: 40px;
}
.suite-barrio-popup__title {
    margin: 0 0 16px;
    font-weight: 400;
    font-size: 2rem;
    line-height: 1.2;
    color: black;
}
.suite-barrio-popup__content {
    margin: 0 0 24px;
    font-size: 1rem;
    line-height: 1.4;
    color: black;
}
.suite-barrio-popup__content :last-child {
    margin-bottom: 0;
}
.suite-barrio-popup__cta {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 20px;
    background: black;
    border: 1px solid black;
    color: white;
    font-size: 0.875rem;
    font-weight: 500;
    text-transform: uppercase;
    text-decoration: none;
    transition: all .5s ease;
}
.suite-barrio-popup__cta:hover {
    color: black;
    background: white;
}
@media (max-width: 639px) {
    .suite-barrio-popup__body {
        padding: 28px 24px;
    }
    .suite-barrio-popup__title {
        font-size: 1.5rem;
        line-height: 1.3;
    }
    .suite-barrio-popup__image {
        width: 100%;
        height: auto;
        aspect-ratio: 4/3;
    }
}
');
});

function suite_barrio_popup_shortcode($atts = []): string
{
    $popup = suite_barrio_popups_get_active();

    if (!$popup) {
        return ''; // nessun popup attivo
    }

    $atts = shortcode_atts([
        'delay' => 2000,
        'days' => 7,
    ], $atts, 'suite_barrio_popup');

    // Campi di default (lingua principale)
    $title = (string) $popup->title;
    $content = (string) $popup->content;
    $link = (string) $popup->link;
    $link_title = (string) $popup->link_title;
    $link_target = $popup->link_target ?: '_self';

    // Override con la traduzione della lingua corrente, se presente.
    $lang = '';
    if (defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE) {
        $lang = ICL_LANGUAGE_CODE;
    } elseif (function_exists('apply_filters')) {
        $lang = (string) apply_filters('wpml_current_language', '');
    }
    if ($lang) {
        $tr = suite_barrio_popups_get_translation((int) $popup->id, $lang);
        if ($tr) {
            if ('' !== $tr->title) {
                $title = (string) $tr->title;
            }
            if ('' !== $tr->content) {
                $content = (string) $tr->content;
            }
            if ('' !== $tr->link) {
                $link = (string) $tr->link;
            }
            if ('' !== $tr->link_title) {
                $link_title = (string) $tr->link_title;
            }
            if ('' !== $tr->link_target) {
                $link_target = (string) $tr->link_target;
            }
        }
    }

    // Immagine: preferisci l'URL salvato, altrimenti ricava dall'attachment ID.
    $image = (string) $popup->image_url;
    if ('' === $image && !empty($popup->image_id)) {
        $image = (string) wp_get_attachment_image_url((int) $popup->image_id, 'large');
    }

    if ('' === $title && '' === trim($content) && '' === $image) {
        return ''; // niente da mostrare
    }

    $id = (int) $popup->id;
    $wrap_id = 'suite-barrio-popup-' . $id;
    $title_id = $wrap_id . '-title';
    $delay = max(0, (int) $atts['delay']);
    $days = max(0, (int) $atts['days']); // durata cookie (0 = nessuna memoria)
    $link_target = in_array($link_target, ['_self', '_blank'], true) ? $link_target : '_self';
    $rel = '_blank' === $link_target ? ' rel="noopener noreferrer"' : '';

    $html = suite_barrio_get_template('popup.php', [
        'wrap_id'     => $wrap_id,
        'title_id'    => $title_id,
        'id'          => $id,
        'delay'       => $delay,
        'days'        => $days,
        'title'       => $title,
        'content'     => $content,
        'link'        => $link,
        'link_title'  => $link_title,
        'link_target' => $link_target,
        'rel'         => $rel,
        'image'       => $image,
    ], __DIR__ . '/templates');

    $inline_script = sprintf(
        '
(function () {
    var el = document.getElementById(\'%s\');
    if (!el || el.dataset.suiteBarrioPopupInit) return;
    el.dataset.suiteBarrioPopupInit = \'1\';

    var cookieName = \'suite_barrio_popup_closed_%s\';
    var days  = parseInt(el.dataset.days, 10) || 0;
    var delay = parseInt(el.dataset.delay, 10) || 0;

    function hasClosedCookie() {
        return document.cookie.split(\'; \').some(function (c) {
            return c.indexOf(cookieName + \'=\') === 0;
        });
    }
    function setClosedCookie() {
        if (days <= 0) return;
        var d = new Date();
        d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
        var secure = location.protocol === \'https:\' ? \'; secure\' : \'\';
        document.cookie = cookieName + \'=1; expires=\' + d.toUTCString() + \'; path=/; samesite=lax\' + secure;
    }

    if (days > 0 && hasClosedCookie()) return;

    function open() {
        el.classList.add(\'is-open\');
        document.documentElement.style.overflow = \'hidden\';
    }
    function close() {
        el.classList.remove(\'is-open\');
        document.documentElement.style.overflow = \'\';
        setClosedCookie();
    }

    el.querySelectorAll(\'[data-suite-barrio-popup-close]\').forEach(function (b) {
        b.addEventListener(\'click\', close);
    });
    document.addEventListener(\'keydown\', function (e) {
        if (e.key === \'Escape\' && el.classList.contains(\'is-open\')) close();
    });

    if (delay > 0) { setTimeout(open, delay); } else { open(); }
})();
',
        esc_js($wrap_id),
        esc_js((string) $id)
    );

    if (!wp_script_is('suite-barrio-popup', 'registered')) {
        wp_register_script('suite-barrio-popup', '', [], SUITE_BARRIO_POPUPS_VERSION, true);
    }
    wp_enqueue_script('suite-barrio-popup');
    wp_add_inline_script('suite-barrio-popup', $inline_script);

    return $html;
}

/* ─────────────────────────────────────────
   MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (current_user_can('manage_options')) {
        // Amministratori: sottovoce nel menu Suite Barrio
        add_submenu_page(
            'suite-barrio',
            'Popups — Suite Barrio',
            'Popups',
            'manage_options',
            'suite-barrio-popups',
            'suite_barrio_popups_admin_page'
        );
    } elseif (current_user_can('edit_others_posts')) {
        // Editor e ruoli con edit_others_posts: voce principale nel menu admin
        add_menu_page(
            'Popups — Suite Barrio',
            'Popups',
            'edit_others_posts',
            'suite-barrio-popups',
            'suite_barrio_popups_admin_page',
            'dashicons-megaphone',
            79
        );
    }
}, 17);

/* ─────────────────────────────────────────
   ENQUEUE: media uploader e script admin solo sulla pagina popups
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'suite-barrio_page_suite-barrio-popups')
        return;
    wp_enqueue_media();
    wp_enqueue_editor();

    wp_register_script('suite-barrio-popups-admin', '', [], SUITE_BARRIO_POPUPS_VERSION, true);
    wp_enqueue_script('suite-barrio-popups-admin');
});

/* ─────────────────────────────────────────
   AJAX: salva popup (crea o aggiorna)
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_popups_save', function () {
    check_ajax_referer('suite_barrio_popups_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $table = $wpdb->prefix . SUITE_BARRIO_POPUPS_TABLE;

    $id = intval($_POST['popup_id'] ?? 0);
    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $content = wp_kses_post(wp_unslash($_POST['content'] ?? ''));
    $link = sanitize_url(wp_unslash($_POST['link'] ?? ''));
    $link_title = sanitize_text_field(wp_unslash($_POST['link_title'] ?? ''));
    $link_target_input = sanitize_text_field(wp_unslash($_POST['link_target'] ?? ''));
    $link_target = in_array($link_target_input, ['_self', '_blank'], true)
        ? $link_target_input
        : '_self';
    $image_url = sanitize_url(wp_unslash($_POST['image_url'] ?? ''));
    $image_id = intval($_POST['image_id'] ?? 0);

    if ($title === '')
        wp_send_json_error('Il titolo è obbligatorio.');

    $data = compact('title', 'content', 'link', 'link_title', 'link_target', 'image_url', 'image_id');
    $format = ['%s', '%s', '%s', '%s', '%s', '%s', '%d'];

    if ($id > 0) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $ok = $wpdb->update($table, $data, ['id' => $id], $format, ['%d']);
    } else {
        $data['active'] = 0;
        $format[] = '%d';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $ok = $wpdb->insert($table, $data, $format);
        $id = (int) $wpdb->insert_id;
    }

    if ($ok === false)
        wp_send_json_error('Errore DB: ' . $wpdb->last_error);

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }

    wp_send_json_success(['id' => $id, 'message' => 'Popup salvato.']);
});

/* ─────────────────────────────────────────
   AJAX: imposta popup attivo (deattiva tutti, attiva uno)
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_popups_set_active', function () {
    check_ajax_referer('suite_barrio_popups_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $table = $wpdb->prefix . SUITE_BARRIO_POPUPS_TABLE;
    $id = intval($_POST['popup_id'] ?? 0);

    // Disattiva tutti
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- nome tabella fisso, non input utente; query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->query("UPDATE {$table} SET active = 0");

    // Attiva quello richiesto (se id = 0 vuol dire "nessuno attivo")
    if ($id > 0) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $wpdb->update($table, ['active' => 1], ['id' => $id], ['%d'], ['%d']);
    }

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }

    wp_send_json_success('Popup attivo aggiornato.');
});

/* ─────────────────────────────────────────
   AJAX: elimina popup
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_popups_delete', function () {
    check_ajax_referer('suite_barrio_popups_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $id = intval($_POST['popup_id'] ?? 0);
    if (!$id)
        wp_send_json_error('ID non valido.');

    // Elimina prima le traduzioni collegate
    suite_barrio_popups_delete_translations($id);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->delete($wpdb->prefix . SUITE_BARRIO_POPUPS_TABLE, ['id' => $id], ['%d']);

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_send_json_success('Popup eliminato.');
});

/* ─────────────────────────────────────────
   AJAX: salva traduzione popup
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_popups_save_translation', function () {
    check_ajax_referer('suite_barrio_popups_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $popup_id = intval($_POST['popup_id'] ?? 0);
    $lang = sanitize_key($_POST['lang'] ?? '');

    if (!$popup_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    // Verifica che il popup esista
    if (!suite_barrio_popups_get($popup_id))
        wp_send_json_error('Popup non trovato.');

    // Verifica che la lingua sia una lingua di traduzione configurata
    $allowed_langs = suite_barrio_multilang_translation_languages();
    if (!in_array($lang, $allowed_langs, true))
        wp_send_json_error('Lingua non consentita.');

    $link_target_input = sanitize_text_field(wp_unslash($_POST['link_target'] ?? ''));

    $data = [
        'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
        'content' => wp_kses_post(wp_unslash($_POST['content'] ?? '')),
        'link' => sanitize_url(wp_unslash($_POST['link'] ?? '')),
        'link_title' => sanitize_text_field(wp_unslash($_POST['link_title'] ?? '')),
        'link_target' => in_array($link_target_input, ['_self', '_blank'], true)
            ? $link_target_input : '_self',
    ];

    if ($data['title'] === '')
        wp_send_json_error('Il titolo è obbligatorio.');

    $ok = suite_barrio_popups_save_translation($popup_id, $lang, $data);

    if (!$ok)
        wp_send_json_error('Errore DB.');

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }

    wp_send_json_success(['message' => 'Traduzione salvata.']);
});

/* ─────────────────────────────────────────
   AJAX: carica traduzione popup
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_popups_get_translation', function () {
    check_ajax_referer('suite_barrio_popups_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $popup_id = intval($_POST['popup_id'] ?? 0);
    $lang = sanitize_key($_POST['lang'] ?? '');

    if (!$popup_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    $row = suite_barrio_popups_get_translation($popup_id, $lang);

    wp_send_json_success([
        'exists' => (bool) $row,
        'title' => $row->title ?? '',
        'content' => $row->content ?? '',
        'link' => $row->link ?? '',
        'link_title' => $row->link_title ?? '',
        'link_target' => $row->link_target ?? '_self',
    ]);
});


/* ─────────────────────────────────────────
   AJAX: inizializza traduzione (crea record vuoto)
   Chiamato quando l'utente clicca "+" nella lista.
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_popups_init_translation', function () {
    check_ajax_referer('suite_barrio_popups_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $popup_id = intval($_POST['popup_id'] ?? 0);
    $lang = sanitize_key($_POST['lang'] ?? '');

    if (!$popup_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    if (!suite_barrio_popups_get($popup_id))
        wp_send_json_error('Popup non trovato.');

    $allowed_langs = suite_barrio_multilang_translation_languages();
    if (!in_array($lang, $allowed_langs, true))
        wp_send_json_error('Lingua non consentita.');

    if (suite_barrio_popups_get_translation($popup_id, $lang)) {
        wp_send_json_success(['already_exists' => true]);
    }

    $source = suite_barrio_popups_get($popup_id);
    $ok = suite_barrio_popups_save_translation($popup_id, $lang, [
        'title' => $source->title ?? '',
        'content' => $source->content ?? '',
        'link' => $source->link ?? '',
        'link_title' => $source->link_title ?? '',
        'link_target' => $source->link_target ?? '_self',
    ]);

    if (!$ok)
        wp_send_json_error('Errore DB.');

    wp_send_json_success(['already_exists' => false]);
});
/* ─────────────────────────────────────────
   AJAX ADMIN: salva limite popup
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_popups_save_limit', function () {
    check_ajax_referer('suite_barrio_popups_nonce', 'nonce');
    if (!suite_barrio_tools_can_access())
        wp_send_json_error('Permesso negato.');

    $limit = max(1, intval($_POST['popup_limit'] ?? 1));
    update_option('wpb_popups_limit', $limit, false);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_send_json_success('Limite salvato.');
});

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function suite_barrio_popups_admin_page(): void
{
    $nonce = wp_create_nonce('suite_barrio_popups_nonce');
    $popups = suite_barrio_popups_get_all();
    $active = suite_barrio_popups_get_active();
    $active_id = $active ? (int) $active->id : 0;
    $popup_limit = (int) get_option('wpb_popups_limit', 1);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $list_tab = isset($_GET['list_tab']) ? sanitize_key($_GET['list_tab']) : 'popups';

    $ml_enabled = suite_barrio_multilang_is_enabled();
    $ml_primary = suite_barrio_multilang_primary_language();
    $ml_trans = suite_barrio_multilang_translation_languages();
    $ml_languages = suite_barrio_multilang_languages_with_flags();
    $ml_active = $ml_enabled && !empty($ml_trans);

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $edit_id = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0;
    $editing = $edit_id ? suite_barrio_popups_get($edit_id) : null;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $qs_lang = isset($_GET['lang']) ? sanitize_key($_GET['lang']) : '';
    ?>
    <div id="suite-barrio-popups-wrap" class="wrap suite-barrio-wrap">

        <!-- Header -->
        <div class="suite-barrio-pop-header">
            <div class="suite-barrio-pop-header-icon"><span class="dashicons dashicons-megaphone"></span></div>
            <div>
                <h1>Suite Barrio <span class="suite-barrio-pop-breadcrumb">/ Popups</span></h1>
                <p class="suite-barrio-pop-sub">Gestisci popup per il frontend</p>
            </div>
        </div>
        <hr class="suite-barrio-pop-divider">

        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
        if ($editing || (isset($_GET['edit_id']) && $_GET['edit_id'] === 'new')):
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- confronto con stringa fissa 'new', sanitizzare rischierebbe di alterare il match; lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
            $is_new = wp_unslash($_GET['edit_id'] ?? '') === 'new';
            $popup = $is_new ? null : $editing;
            ?>

            <!-- ── EDITOR ── -->
            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-popups')); ?>" class="suite-barrio-pop-back">
                <span class="dashicons dashicons-arrow-left-alt2" style="font-size:15px;width:15px;height:15px;"></span>
                Torna alla lista
            </a>

            <div class="suite-barrio-pop-editor">
                <input type="hidden" id="suite-barrio-popup-id" value="<?php echo $is_new ? 0 : intval($popup->id ?? 0); ?>">

                <?php if ($ml_active && !$is_new):
                    $popup_id_int = (int) ($popup->id ?? 0);
                    $editor_langs = [$ml_primary => ($ml_languages[$ml_primary] ?? $ml_primary) . ' (principale)'];
                    foreach ($ml_trans as $lc) {
                        if (suite_barrio_popups_get_translation($popup_id_int, $lc))
                            $editor_langs[$lc] = $ml_languages[$lc] ?? strtoupper($lc);
                    }
                    $initial_lang = isset($editor_langs[$qs_lang]) ? $qs_lang : $ml_primary;
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
                        Immagine e stato attivo sono condivisi con la versione principale.
                    </div>
                    <?php
                    if ($initial_lang !== $ml_primary) {
                        $trans_data = suite_barrio_popups_get_translation($popup_id_int, $initial_lang);
                        if ($trans_data) {
                            $popup = $trans_data;
                            $popup->image_url = $editing->image_url ?? '';
                            $popup->image_id = $editing->image_id ?? 0;
                        }
                    }
                endif; ?>

                <div class="suite-barrio-pop-editor-grid" id="suite-barrio-editor-grid">

                    <!-- Colonna sinistra: campi -->
                    <div>
                        <div class="suite-barrio-efield">
                            <label class="suite-barrio-efield-label" for="suite-barrio-popup-title">Titolo <span
                                    style="color:#dc2626;">*</span></label>
                            <input type="text" id="suite-barrio-popup-title" value="<?php echo esc_attr($popup->title ?? ''); ?>"
                                placeholder="Es. Offerta di Benvenuto">
                        </div>

                        <div class="suite-barrio-efield">
                            <label class="suite-barrio-efield-label">Testo</label>
                            <?php
                            wp_editor(
                                $popup->content ?? '',
                                'suite-barrio-popup-content',
                                [
                                    'textarea_name' => 'suite_barrio_popup_content_unused',
                                    'textarea_rows' => 8,
                                    'media_buttons' => false,
                                    'teeny' => true,
                                    'quicktags' => true,
                                    'tinymce' => [
                                        'toolbar1' => 'bold,italic,underline,strikethrough,|,bullist,numlist,|,link,unlink,|,undo,redo',
                                        'toolbar2' => '',
                                    ],
                                ]
                            );
                            ?>
                        </div>

                        <div class="suite-barrio-efield">
                            <label class="suite-barrio-efield-label">Link CTA <span
                                    class="suite-barrio-efield-optional">(opzionale)</span></label>
                            <div style="margin-bottom:8px;">
                                <input type="url" id="suite-barrio-popup-link" value="<?php echo esc_attr($popup->link ?? ''); ?>"
                                    placeholder="https://esempio.com/offerta" style="max-width:100%;">
                                <p class="suite-barrio-efield-desc">URL</p>
                            </div>
                            <div class="suite-barrio-link-grid">
                                <div>
                                    <input type="text" id="suite-barrio-popup-link-title"
                                        value="<?php echo esc_attr($popup->link_title ?? ''); ?>" placeholder="Scopri di più">
                                    <p class="suite-barrio-efield-desc">Testo pulsante</p>
                                </div>
                                <div>
                                    <select id="suite-barrio-popup-link-target">
                                        <option value="_self" <?php selected(($popup->link_target ?? '_self'), '_self'); ?>>
                                            Stessa scheda</option>
                                        <option value="_blank" <?php selected(($popup->link_target ?? '_self'), '_blank'); ?>>
                                            Nuova scheda</option>
                                    </select>
                                    <p class="suite-barrio-efield-desc">Target</p>
                                </div>
                            </div>
                        </div>

                        <div class="suite-barrio-efield" id="suite-barrio-image-field-wrap">
                            <label class="suite-barrio-efield-label">Immagine <span
                                    class="suite-barrio-efield-optional">(opzionale)</span></label>
                            <input type="hidden" id="suite-barrio-popup-image-id" value="<?php echo intval($popup->image_id ?? 0); ?>">
                            <input type="hidden" id="suite-barrio-popup-image-url"
                                value="<?php echo esc_attr($popup->image_url ?? ''); ?>">
                            <button type="button" class="suite-barrio-btn suite-barrio-btn-ghost suite-barrio-btn-sm" id="suite-barrio-popup-image-btn">
                                <span class="dashicons dashicons-format-image"
                                    style="font-size:14px;width:14px;height:14px;"></span>
                                Scegli immagine
                            </button>
                            <div class="suite-barrio-pop-img-preview" id="suite-barrio-popup-image-preview"
                                style="<?php echo !empty($popup->image_url) ? 'display:block;' : ''; ?>">
                                <img id="suite-barrio-popup-image-thumb" src="<?php echo esc_attr($popup->image_url ?? ''); ?>" alt="">
                                <button type="button" class="suite-barrio-pop-img-remove" id="suite-barrio-popup-image-remove">
                                    <span class="dashicons dashicons-trash"
                                        style="font-size:13px;width:13px;height:13px;"></span>
                                    Rimuovi immagine
                                </button>
                            </div>
                        </div>

                        <div class="suite-barrio-editor-actions">
                            <button type="button" class="suite-barrio-btn suite-barrio-btn-primary" id="suite-barrio-popup-save-btn">
                                <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                                Salva popup
                            </button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-popups')); ?>"
                                class="suite-barrio-btn suite-barrio-btn-ghost">Annulla</a>
                            <span id="suite-barrio-popup-save-msg" class="suite-barrio-editor-msg" style="display:none;"></span>
                        </div>
                    </div>

                    <!-- Colonna destra: anteprima -->
                    <div style="position:sticky;top:32px;">
                        <div class="suite-barrio-preview-tabs">
                            <button type="button" class="suite-barrio-preview-tab active" id="suite-barrio-tab-desktop"
                                onclick="suiteBarrioSwitchPreview('desktop')">
                                <span class="dashicons dashicons-desktop"></span> Desktop
                            </button>
                            <button type="button" class="suite-barrio-preview-tab" id="suite-barrio-tab-mobile"
                                onclick="suiteBarrioSwitchPreview('mobile')">
                                <span class="dashicons dashicons-smartphone"></span> Mobile
                            </button>
                        </div>

                        <!-- Desktop preview -->
                        <div id="suite-barrio-preview-desktop">
                            <div class="suite-barrio-preview-card"
                                style="position:relative;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(0,0,0,.18);overflow:hidden;display:flex;flex-direction:row;min-height:180px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
                                <div class="suite-barrio-prev-img-d"
                                    style="display:<?php echo !empty($popup->image_url) ? 'block' : 'none'; ?>;width:45%;flex-shrink:0;background:#f3f4f6;overflow:hidden;">
                                    <img class="suite-barrio-prev-img" src="<?php echo esc_attr($popup->image_url ?? ''); ?>"
                                        style="width:100%;height:100%;object-fit:cover;display:block;" alt="">
                                </div>
                                <div style="padding:24px 24px 20px;flex:1;min-width:0;">
                                    <div
                                        style="position:absolute;top:12px;right:16px;font-size:20px;color:#9ca3af;line-height:1;font-weight:300;pointer-events:none;">
                                        ✕</div>
                                    <div class="suite-barrio-prev-title"
                                        style="font-size:17px;font-weight:600;color:#111827;margin-bottom:10px;line-height:1.3;">
                                        <?php echo esc_html($popup->title ?? ''); ?>
                                    </div>
                                    <div class="suite-barrio-prev-content"
                                        style="font-size:13px;color:#374151;line-height:1.6;margin-bottom:14px;">
                                        <?php echo wp_kses_post($popup->content ?? ''); ?>
                                    </div>
                                    <div class="suite-barrio-prev-link-wrap"
                                        style="display:<?php echo !empty($popup->link) ? 'block' : 'none'; ?>;">
                                        <a class="suite-barrio-prev-link" href="#" onclick="return false;"
                                            style="font-size:13px;color:#111827;text-decoration:underline;text-underline-offset:3px;"><?php echo esc_html($popup->link_title ?: ($popup->link ?? '')); ?></a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Mobile preview -->
                        <div id="suite-barrio-preview-mobile" style="display:none;">
                            <div
                                style="width:260px;margin:0 auto;border:3px solid #111827;border-radius:28px;padding:10px;background:#111827;box-shadow:0 8px 32px rgba(0,0,0,.25);">
                                <div style="width:60px;height:6px;background:#374151;border-radius:3px;margin:0 auto 8px;">
                                </div>
                                <div style="background:#f3f4f6;border-radius:18px;overflow:hidden;min-height:340px;">
                                    <div
                                        style="background:#fff;border-radius:18px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
                                        <div class="suite-barrio-preview-card"
                                            style="position:relative;background:#fff;border-radius:14px;box-shadow:0 4px 20px rgba(0,0,0,.15);overflow:hidden;margin:16px;">
                                            <div class="suite-barrio-prev-img-m"
                                                style="display:<?php echo !empty($popup->image_url) ? 'block' : 'none'; ?>;height:130px;background:#f3f4f6;overflow:hidden;">
                                                <img class="suite-barrio-prev-img" src="<?php echo esc_attr($popup->image_url ?? ''); ?>"
                                                    style="width:100%;height:100%;object-fit:cover;display:block;" alt="">
                                            </div>
                                            <div style="padding:12px 12px 10px;">
                                                <div
                                                    style="position:absolute;top:8px;right:10px;font-size:14px;color:#9ca3af;line-height:1;pointer-events:none;">
                                                    ✕</div>
                                                <div class="suite-barrio-prev-title"
                                                    style="font-size:12px;font-weight:600;color:#111827;margin-bottom:6px;line-height:1.3;padding-right:14px;">
                                                    <?php echo esc_html($popup->title ?? ''); ?>
                                                </div>
                                                <div class="suite-barrio-prev-content"
                                                    style="font-size:11px;color:#374151;line-height:1.5;margin-bottom:10px;">
                                                    <?php echo wp_kses_post($popup->content ?? ''); ?>
                                                </div>
                                                <div class="suite-barrio-prev-link-wrap"
                                                    style="display:<?php echo !empty($popup->link) ? 'block' : 'none'; ?>;">
                                                    <a class="suite-barrio-prev-link" href="#" onclick="return false;"
                                                        style="font-size:11px;color:#111827;text-decoration:underline;"><?php echo esc_html($popup->link_title ?: ($popup->link ?? '')); ?></a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div style="width:80px;height:4px;background:#374151;border-radius:2px;margin:8px auto 0;">
                                </div>
                            </div>
                        </div>

                        <p class="suite-barrio-preview-hint">L'anteprima si aggiorna in tempo reale</p>
                    </div>

                </div><!-- .suite-barrio-pop-editor-grid -->
            </div><!-- .suite-barrio-pop-editor -->

        <?php else: ?>

            <!-- ── LISTA ── -->
            <?php if (suite_barrio_tools_can_access()): ?>
                <div class="suite-barrio-pop-tabs">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-popups')); ?>"
                        class="suite-barrio-pop-tab <?php echo $list_tab === 'popups' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-megaphone" style="font-size:15px;width:15px;height:15px;"></span>
                        Popup
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-popups&list_tab=settings')); ?>"
                        class="suite-barrio-pop-tab <?php echo $list_tab === 'settings' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-admin-settings" style="font-size:15px;width:15px;height:15px;"></span>
                        Impostazioni
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($list_tab === 'settings' && suite_barrio_tools_can_access()): ?>

                <!-- Tab impostazioni -->
                <div class="suite-barrio-pop-settings-card">
                    <h3 class="suite-barrio-pop-settings-title">Limiti</h3>
                    <p class="suite-barrio-pop-settings-desc">Numero massimo di popup che possono essere creati. Raggiunto il limite, il
                        pulsante «+ Nuovo» viene disabilitato.</p>
                    <div style="margin-bottom:16px;">
                        <label class="suite-barrio-field-label" for="suite-barrio-popup-limit-input">Numero massimo di popup</label>
                        <input type="number" id="suite-barrio-popup-limit-input" min="1" max="999"
                            value="<?php echo intval($popup_limit); ?>"
                            style="width:110px;padding:8px 12px;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;font-family:inherit;">
                        <p class="suite-barrio-field-desc">Default: 1. Valori alti rimuovono in pratica il limite.</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <button type="button" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-btn-sm" id="suite-barrio-save-limit-btn">
                            <span class="dashicons dashicons-yes" style="font-size:15px;width:15px;height:15px;"></span>
                            Salva
                        </button>
                        <span id="suite-barrio-save-limit-msg" class="suite-barrio-pop-save-ok">&#x2713; Salvato</span>
                    </div>
                </div>

            <?php else: ?>

                <!-- Toolbar -->
                <div style="margin-bottom:20px;">
                    <?php $at_limit = count($popups) >= $popup_limit; ?>
                    <?php if ($at_limit): ?>
                        <button class="suite-barrio-btn suite-barrio-btn-primary" disabled
                            title="Limite di <?php echo intval($popup_limit); ?> popup raggiunto"
                            style="opacity:.45;cursor:not-allowed;">+ Nuovo popup</button>
                    <?php else: ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-popups&edit_id=new')); ?>"
                            class="suite-barrio-btn suite-barrio-btn-primary">+ Nuovo popup</a>
                    <?php endif; ?>
                </div>

                <!-- Notice attivo -->
                <?php if (!empty($active)): ?>
                    <div id="suite-barrio-active-banner" class="suite-barrio-pop-notice suite-barrio-pop-notice-success">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <div>Popup attivo: <strong><?php echo esc_html($active->title); ?></strong></div>
                    </div>
                <?php else: ?>
                    <div id="suite-barrio-active-banner" class="suite-barrio-pop-notice suite-barrio-pop-notice-warning">
                        <span class="dashicons dashicons-warning"></span>
                        <div>Nessun popup attivo al momento.</div>
                    </div>
                <?php endif; ?>

                <?php if (empty($popups)): ?>
                    <div class="suite-barrio-pop-empty">
                        <span class="dashicons dashicons-megaphone"></span>
                        <p class="suite-barrio-pop-empty-title">Nessun popup ancora</p>
                        <p class="suite-barrio-pop-empty-sub">Crea il tuo primo popup in pochi secondi.</p>
                        <?php if (count($popups) < $popup_limit): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-popups&edit_id=new')); ?>"
                                class="suite-barrio-btn suite-barrio-btn-primary">+ Nuovo popup</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>

                    <div id="suite-barrio-popups-list">
                        <?php foreach ($popups as $p):
                            $is_active = (int) $p->id === $active_id;
                            $full = suite_barrio_popups_get($p->id);
                            ?>
                            <div class="suite-barrio-pop-card" data-id="<?php echo intval($p->id); ?>">
                                <div class="suite-barrio-pop-row">
                                    <!-- Thumb -->
                                    <div class="suite-barrio-pop-thumb">
                                        <?php if (!empty($full->image_url)): ?>
                                            <img src="<?php echo esc_attr($full->image_url); ?>" alt="">
                                        <?php else: ?>
                                            <span class="dashicons dashicons-format-image"
                                                style="font-size:20px;width:20px;height:20px;color:#d1d5db;margin:auto;"></span>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Info -->
                                    <div>
                                        <div class="suite-barrio-pop-title"><?php echo esc_html($p->title); ?></div>
                                        <div class="suite-barrio-pop-meta">Creato il
                                            <?php echo esc_html(date_i18n('d/m/Y', strtotime($p->created_at))); ?>
                                        </div>
                                    </div>
                                    <!-- Badge -->
                                    <div>
                                        <?php if ($is_active): ?>
                                            <span class="suite-barrio-badge-on"><span class="suite-barrio-badge-on-dot"></span>Attivo</span>
                                        <?php else: ?>
                                            <span class="suite-barrio-badge-off">Inattivo</span>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Azioni -->
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <label class="suite-barrio-toggle-active" title="<?php echo $is_active ? 'Disattiva' : 'Attiva'; ?>">
                                            <input type="checkbox" class="suite-barrio-toggle-active-cb" data-id="<?php echo intval($p->id); ?>"
                                                <?php checked($is_active); ?>>
                                            <span class="suite-barrio-toggle-active-track"></span>
                                        </label>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-popups&edit_id=' . $p->id)); ?>"
                                            class="suite-barrio-btn suite-barrio-btn-ghost suite-barrio-btn-sm" title="Modifica">
                                            <span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;"></span>
                                        </a>
                                        <button type="button" class="suite-barrio-btn suite-barrio-btn-danger suite-barrio-btn-sm suite-barrio-popup-delete-btn"
                                            data-id="<?php echo intval($p->id); ?>" title="Elimina">
                                            <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span>
                                        </button>
                                    </div>
                                </div><!-- .suite-barrio-pop-row -->

                                <?php if ($ml_active): ?>
                                    <div class="suite-barrio-pop-langs">
                                        <span class="suite-barrio-pop-langs-label">Lingue:</span>
                                        <span class="suite-barrio-lang-pill primary" title="Lingua principale">
                                            <span class="dashicons dashicons-yes" style="font-size:12px;width:12px;height:12px;"></span>
                                            <?php echo esc_html($ml_languages[$ml_primary] ?? strtoupper($ml_primary)); ?>
                                        </span>
                                        <?php foreach ($ml_trans as $lang_code):
                                            $lang_label = $ml_languages[$lang_code] ?? strtoupper($lang_code);
                                            $has_trans = (bool) suite_barrio_popups_get_translation((int) $p->id, $lang_code);
                                            ?>
                                            <?php if ($has_trans): ?>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-popups&edit_id=' . $p->id . '&lang=' . $lang_code)); ?>"
                                                    class="suite-barrio-lang-pill translated"
                                                    title="Modifica traduzione in <?php echo esc_attr($lang_label); ?>">
                                                    <span class="dashicons dashicons-yes" style="font-size:12px;width:12px;height:12px;"></span>
                                                    <?php echo esc_html($lang_label); ?>
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="suite-barrio-lang-pill missing suite-barrio-lang-init-btn"
                                                    data-popup-id="<?php echo intval($p->id); ?>" data-lang="<?php echo esc_attr($lang_code); ?>"
                                                    data-lang-label="<?php echo esc_attr($lang_label); ?>"
                                                    title="Inizializza traduzione in <?php echo esc_attr($lang_label); ?>">
                                                    + <?php echo esc_html($lang_label); ?>
                                                </button>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                            </div><!-- .suite-barrio-pop-card -->
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

    </div><!-- #suite-barrio-popups-wrap -->

    <?php ob_start(); ?>
        /* Switch preview desktop/mobile — globale per onclick inline */
        function suiteBarrioSwitchPreview(mode) {
            var dPane = document.getElementById('suite-barrio-preview-desktop');
            var mPane = document.getElementById('suite-barrio-preview-mobile');
            var dTab = document.getElementById('suite-barrio-tab-desktop');
            var mTab = document.getElementById('suite-barrio-tab-mobile');
            if (!dPane || !mPane) return;
            var isDesktop = mode === 'desktop';
            dPane.style.display = isDesktop ? '' : 'none';
            mPane.style.display = isDesktop ? 'none' : '';
            dTab.className = 'suite-barrio-preview-tab' + (isDesktop ? ' active' : '');
            mTab.className = 'suite-barrio-preview-tab' + (isDesktop ? '' : ' active');
        }

        (function () {
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var urlEditBase = <?php echo wp_json_encode(admin_url('admin.php?page=suite-barrio-popups&edit_id=')); ?>;

            var mlActive = <?php echo wp_json_encode($ml_active); ?>;
            var mlPrimary = <?php echo wp_json_encode($ml_primary); ?>;
            var mlLanguages = <?php echo wp_json_encode($ml_languages); ?>;
            var currentLang = <?php echo wp_json_encode($initial_lang ?? $ml_primary); ?>;

            /* ═══ MULTILINGUA — select lingua nell'editor ══════════════════ */
            (function () {
                if (!mlActive) return;
                var langSelect = document.getElementById('suite-barrio-lang-select');
                var notice = document.getElementById('suite-barrio-trans-notice');
                var noticeLang = document.getElementById('suite-barrio-trans-notice-lang');
                var imageWrap = document.getElementById('suite-barrio-image-field-wrap');
                var saveBtn = document.getElementById('suite-barrio-popup-save-btn');
                if (!langSelect) return;

                function setImageFieldState(isPrimary) {
                    if (!imageWrap) return;
                    if (isPrimary) { imageWrap.classList.remove('suite-barrio-trans-field-disabled'); imageWrap.style.pointerEvents = ''; imageWrap.style.opacity = ''; }
                    else { imageWrap.classList.add('suite-barrio-trans-field-disabled'); imageWrap.style.pointerEvents = 'none'; imageWrap.style.opacity = '0.45'; }
                }

                function loadPrimaryFields() {
                    ['suite-barrio-popup-title', 'suite-barrio-popup-link', 'suite-barrio-popup-link-title', 'suite-barrio-popup-link-target'].forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el) el.value = el.dataset.primary !== undefined ? el.dataset.primary : el.value;
                    });
                    var ta = document.getElementById('suite-barrio-popup-content');
                    if (ta && ta.dataset.primary !== undefined) {
                        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('suite-barrio-popup-content')) tinyMCE.get('suite-barrio-popup-content').setContent(ta.dataset.primary);
                        else ta.value = ta.dataset.primary;
                    }
                }

                function fillTranslationFields(data) {
                    var title = document.getElementById('suite-barrio-popup-title'), link = document.getElementById('suite-barrio-popup-link');
                    var linkTitle = document.getElementById('suite-barrio-popup-link-title'), linkTarget = document.getElementById('suite-barrio-popup-link-target');
                    if (title) title.value = data.title || ''; if (link) link.value = data.link || '';
                    if (linkTitle) linkTitle.value = data.link_title || ''; if (linkTarget) linkTarget.value = data.link_target || '_self';
                    if (typeof tinyMCE !== 'undefined' && tinyMCE.get('suite-barrio-popup-content')) tinyMCE.get('suite-barrio-popup-content').setContent(data.content || '');
                    else { var ta = document.getElementById('suite-barrio-popup-content'); if (ta) ta.value = data.content || ''; }
                    var ev = new Event('input', { bubbles: true }); if (title) title.dispatchEvent(ev);
                    updateLink();
                }

                function switchLang(lang, isPrimary) {
                    currentLang = lang;
                    if (saveBtn) {
                        var icon = '<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> ';
                        saveBtn.innerHTML = icon + (isPrimary ? 'Salva popup' : 'Salva traduzione');
                    }
                    if (notice && noticeLang) {
                        if (isPrimary) notice.style.display = 'none';
                        else { noticeLang.textContent = mlLanguages[lang] || lang; notice.style.display = 'flex'; }
                    }
                    setImageFieldState(isPrimary);
                    if (isPrimary) { loadPrimaryFields(); return; }
                    var popupId = document.getElementById('suite-barrio-popup-id').value;
                    if (!popupId || popupId === '0') return;
                    langSelect.disabled = true;
                    fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'suite_barrio_popups_get_translation', nonce: nonce, popup_id: popupId, lang: lang }) })
                        .then(function (r) { return r.json(); }).then(function (res) { if (res.success) fillTranslationFields(res.data); })
                        .catch(function () { }).finally(function () { langSelect.disabled = false; });
                }

                langSelect.addEventListener('change', function () {
                    var opt = this.options[this.selectedIndex];
                    switchLang(this.value, opt.dataset.isPrimary === '1');
                });

                setTimeout(function () {
                    var primaryData = <?php echo wp_json_encode([
                        'title' => $editing->title ?? '',
                        'content' => $editing->content ?? '',
                        'link' => $editing->link ?? '',
                        'link_title' => $editing->link_title ?? '',
                        'link_target' => $editing->link_target ?? '_self',
                    ]); ?>;
                    ['suite-barrio-popup-title', 'suite-barrio-popup-link', 'suite-barrio-popup-link-title', 'suite-barrio-popup-link-target'].forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el) { var key = id.replace('suite-barrio-popup-', '').replace(/-/g, '_'); el.dataset.primary = primaryData[key] !== undefined ? primaryData[key] : el.value; }
                    });
                    var ta = document.getElementById('suite-barrio-popup-content');
                    if (ta) ta.dataset.primary = primaryData.content || '';
                    var initialOpt = langSelect.options[langSelect.selectedIndex];
                    if (initialOpt && initialOpt.dataset.isPrimary === '0') {
                        setImageFieldState(false);
                        var icon = '<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> ';
                        if (saveBtn) saveBtn.innerHTML = icon + 'Salva traduzione';
                    }
                }, 600);
            })();

            /* ═══ LISTA — inizializza traduzione ═══════════════════════════ */
            document.querySelectorAll('.suite-barrio-lang-init-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var popupId = this.dataset.popupId, lang = this.dataset.lang, langLabel = this.dataset.langLabel, pill = this;
                    pill.classList.add('loading'); pill.disabled = true;
                    fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'suite_barrio_popups_init_translation', nonce: nonce, popup_id: popupId, lang: lang }) })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) {
                                var link = document.createElement('a');
                                link.href = urlEditBase + popupId + '&lang=' + lang;
                                link.className = 'suite-barrio-lang-pill translated';
                                link.title = 'Modifica traduzione in ' + langLabel;
                                link.innerHTML = '<span class="dashicons dashicons-yes" style="font-size:12px;width:12px;height:12px;"></span> ' + langLabel;
                                pill.replaceWith(link);
                            } else { alert(res.data || 'Errore.'); pill.classList.remove('loading'); pill.disabled = false; }
                        }).catch(function () { alert('Errore di rete.'); pill.classList.remove('loading'); pill.disabled = false; });
                });
            });

            /* ═══ IMPOSTAZIONI — salva limite ══════════════════════════════ */
            var saveLimitBtn = document.getElementById('suite-barrio-save-limit-btn');
            if (saveLimitBtn) {
                saveLimitBtn.addEventListener('click', function () {
                    var btn = this, msg = document.getElementById('suite-barrio-save-limit-msg');
                    var val = parseInt(document.getElementById('suite-barrio-popup-limit-input').value, 10) || 1;
                    btn.disabled = true;
                    fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'suite_barrio_popups_save_limit', nonce: nonce, popup_limit: val }) })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) { msg.style.display = 'inline'; setTimeout(function () { msg.style.display = 'none'; }, 3000); }
                            else alert(res.data || 'Errore.');
                        }).catch(function () { alert('Errore di rete.'); }).finally(function () { btn.disabled = false; });
                });
            }

            /* ═══ LISTA — toggle attiva/disattiva ══════════════════════════ */
            document.querySelectorAll('.suite-barrio-toggle-active-cb').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var popupId = this.checked ? parseInt(this.dataset.id, 10) : 0;
                    document.querySelectorAll('.suite-barrio-toggle-active-cb').forEach(function (c) { c.checked = false; updateRowBadge(c.closest('.suite-barrio-pop-card'), false); });
                    if (popupId) { this.checked = true; updateRowBadge(this.closest('.suite-barrio-pop-card'), true); }
                    fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'suite_barrio_popups_set_active', nonce: nonce, popup_id: popupId }) })
                        .then(function (r) { return r.json(); })
                        .then(function (res) { if (!res.success) { alert(res.data || 'Errore.'); location.reload(); } else updateActiveBanner(popupId); })
                        .catch(function () { alert('Errore di rete.'); location.reload(); });
                });
            });

            function updateRowBadge(card, isActive) {
                if (!card) return;
                var badge = card.querySelector('.suite-barrio-badge-on, .suite-barrio-badge-off');
                if (!badge) return;
                if (isActive) { badge.className = 'suite-barrio-badge-on'; badge.innerHTML = '<span class="suite-barrio-badge-on-dot"></span>Attivo'; }
                else { badge.className = 'suite-barrio-badge-off'; badge.textContent = 'Inattivo'; }
            }

            function updateActiveBanner(activeId) {
                var banner = document.getElementById('suite-barrio-active-banner');
                if (!banner) return;
                if (!activeId) {
                    banner.className = 'suite-barrio-pop-notice suite-barrio-pop-notice-warning';
                    banner.innerHTML = '<span class="dashicons dashicons-warning" style="font-size:17px;width:17px;height:17px;flex-shrink:0;margin-top:1px;color:#d97706;"></span><div>Nessun popup attivo al momento.</div>';
                    return;
                }
                var card = document.querySelector('.suite-barrio-pop-card[data-id="' + activeId + '"]');
                var title = card ? card.querySelector('.suite-barrio-pop-title').textContent : '';
                banner.className = 'suite-barrio-pop-notice suite-barrio-pop-notice-success';
                banner.innerHTML = '<span class="dashicons dashicons-yes-alt" style="font-size:17px;width:17px;height:17px;flex-shrink:0;margin-top:1px;color:#059669;"></span><div>Popup attivo: <strong>' + title + '</strong></div>';
            }

            /* ═══ LISTA — elimina ══════════════════════════════════════════ */
            document.querySelectorAll('.suite-barrio-popup-delete-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm('Eliminare questo popup?')) return;
                    var id = parseInt(this.dataset.id, 10), card = this.closest('.suite-barrio-pop-card');
                    fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'suite_barrio_popups_delete', nonce: nonce, popup_id: id }) })
                        .then(function (r) { return r.json(); })
                        .then(function (res) { if (res.success) { card.style.transition = 'opacity .3s'; card.style.opacity = '0'; setTimeout(function () { card.remove(); }, 300); } else alert(res.data || 'Errore.'); })
                        .catch(function () { alert('Errore di rete.'); });
                });
            });

            /* ═══ EDITOR — anteprima live ══════════════════════════════════ */
            var updateLink = function () {
                var url = (document.getElementById('suite-barrio-popup-link') || {}).value || '';
                var label = (document.getElementById('suite-barrio-popup-link-title') || {}).value || '';
                document.querySelectorAll('.suite-barrio-prev-link-wrap').forEach(function (el) { el.style.display = url ? 'block' : 'none'; });
                document.querySelectorAll('.suite-barrio-prev-link').forEach(function (el) { el.textContent = label || url; });
            };

            (function () {
                function setAll(cls, fn) { document.querySelectorAll('.' + cls).forEach(fn); }
                var titleInput = document.getElementById('suite-barrio-popup-title');
                if (titleInput) titleInput.addEventListener('input', function () { setAll('suite-barrio-prev-title', function (el) { el.textContent = titleInput.value; }); });
                setInterval(function () {
                    var content = '';
                    if (typeof tinyMCE !== 'undefined' && tinyMCE.get('suite-barrio-popup-content')) content = tinyMCE.get('suite-barrio-popup-content').getContent();
                    else { var ta = document.getElementById('suite-barrio-popup-content'); if (ta) content = ta.value; }
                    setAll('suite-barrio-prev-content', function (el) { el.innerHTML = content; });
                }, 400);
                ['suite-barrio-popup-link', 'suite-barrio-popup-link-title'].forEach(function (id) { var el = document.getElementById(id); if (el) el.addEventListener('input', updateLink); });
                document.addEventListener('suite-barrio:image:changed', function (e) {
                    var url = e.detail && e.detail.url ? e.detail.url : '';
                    document.querySelectorAll('.suite-barrio-prev-img-d').forEach(function (w) { w.style.display = url ? 'block' : 'none'; });
                    document.querySelectorAll('.suite-barrio-prev-img-m').forEach(function (w) { w.style.display = url ? 'block' : 'none'; });
                    document.querySelectorAll('.suite-barrio-prev-img').forEach(function (img) { img.src = url; });
                });
            })();

            /* ═══ EDITOR — media uploader ══════════════════════════════════ */
            var imageBtn = document.getElementById('suite-barrio-popup-image-btn');
            if (imageBtn) {
                var mediaFrame = null;
                imageBtn.addEventListener('click', function () {
                    if (mediaFrame) { mediaFrame.open(); return; }
                    mediaFrame = wp.media({ title: 'Seleziona immagine popup', button: { text: 'Usa questa immagine' }, multiple: false, library: { type: 'image' } });
                    mediaFrame.on('select', function () {
                        var att = mediaFrame.state().get('selection').first().toJSON();
                        document.getElementById('suite-barrio-popup-image-id').value = att.id;
                        document.getElementById('suite-barrio-popup-image-url').value = att.url;
                        document.getElementById('suite-barrio-popup-image-thumb').src = att.url;
                        document.getElementById('suite-barrio-popup-image-preview').style.display = 'block';
                        document.dispatchEvent(new CustomEvent('suite-barrio:image:changed', { detail: { url: att.url } }));
                    });
                    mediaFrame.open();
                });
                var removeBtn = document.getElementById('suite-barrio-popup-image-remove');
                if (removeBtn) {
                    removeBtn.addEventListener('click', function () {
                        document.getElementById('suite-barrio-popup-image-id').value = 0;
                        document.getElementById('suite-barrio-popup-image-url').value = '';
                        document.getElementById('suite-barrio-popup-image-thumb').src = '';
                        document.getElementById('suite-barrio-popup-image-preview').style.display = 'none';
                        if (mediaFrame) mediaFrame = null;
                        document.dispatchEvent(new CustomEvent('suite-barrio:image:changed', { detail: { url: '' } }));
                    });
                }
            }

            /* ═══ EDITOR — salva ═══════════════════════════════════════════ */
            var saveBtn = document.getElementById('suite-barrio-popup-save-btn');
            if (saveBtn) {
                saveBtn.addEventListener('click', function () {
                    var btn = this, msg = document.getElementById('suite-barrio-popup-save-msg');
                    var title = document.getElementById('suite-barrio-popup-title').value.trim();
                    if (!title) {
                        msg.className = 'suite-barrio-editor-msg err'; msg.style.display = 'inline';
                        msg.textContent = '✗ Il titolo è obbligatorio.'; return;
                    }
                    btn.disabled = true;
                    btn.innerHTML = '<div style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:suiteBarrioPopSpin .65s linear infinite;vertical-align:middle;margin-right:4px;"></div> Salvataggio…';
                    msg.style.display = 'none';
                    var content = (typeof tinyMCE !== 'undefined' && tinyMCE.get('suite-barrio-popup-content'))
                        ? tinyMCE.get('suite-barrio-popup-content').getContent()
                        : (document.getElementById('suite-barrio-popup-content') ? document.getElementById('suite-barrio-popup-content').value : '');
                    var popupId = document.getElementById('suite-barrio-popup-id').value;
                    var isPrimary = !mlActive || currentLang === mlPrimary;
                    var fd;
                    if (isPrimary) {
                        fd = new URLSearchParams({
                            action: 'suite_barrio_popups_save', nonce: nonce, popup_id: popupId, title: title, content: content,
                            link: document.getElementById('suite-barrio-popup-link').value.trim(),
                            link_title: document.getElementById('suite-barrio-popup-link-title').value.trim(),
                            link_target: document.getElementById('suite-barrio-popup-link-target').value,
                            image_url: document.getElementById('suite-barrio-popup-image-url').value,
                            image_id: document.getElementById('suite-barrio-popup-image-id').value
                        });
                    } else {
                        if (!popupId || popupId === '0') {
                            msg.className = 'suite-barrio-editor-msg err'; msg.style.display = 'inline';
                            msg.textContent = '✗ Salva prima il popup in lingua principale.';
                            btn.disabled = false;
                            btn.innerHTML = '<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> Salva traduzione';
                            return;
                        }
                        fd = new URLSearchParams({
                            action: 'suite_barrio_popups_save_translation', nonce: nonce, popup_id: popupId, lang: currentLang,
                            title: title, content: content,
                            link: document.getElementById('suite-barrio-popup-link').value.trim(),
                            link_title: document.getElementById('suite-barrio-popup-link-title').value.trim(),
                            link_target: document.getElementById('suite-barrio-popup-link-target').value
                        });
                    }
                    var icon = '<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> ';
                    fetch(ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) {
                                msg.className = 'suite-barrio-editor-msg ok'; msg.style.display = 'inline';
                                msg.textContent = isPrimary ? '✓ Popup salvato.' : '✓ Traduzione salvata.';
                                if (isPrimary && popupId === '0') setTimeout(function () { window.location.href = urlEditBase + res.data.id; }, 800);
                            } else { msg.className = 'suite-barrio-editor-msg err'; msg.style.display = 'inline'; msg.textContent = '✗ ' + (res.data || 'Errore.'); }
                        }).catch(function () { msg.className = 'suite-barrio-editor-msg err'; msg.style.display = 'inline'; msg.textContent = '✗ Errore di rete.'; })
                        .finally(function () { btn.disabled = false; btn.innerHTML = icon + (isPrimary ? 'Salva popup' : 'Salva traduzione'); });
                });
            }

        })();
    <?php
    wp_add_inline_script('suite-barrio-popups-admin', ob_get_clean());
}