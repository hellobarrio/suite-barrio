<?php
/**
 * WP Barrio — Banners
 * Gestione di banner a scorrimento (marquee) configurabili. Solo uno può essere attivo alla volta.
 * Ogni banner ha: titolo, link (url + target).
 * Esposto via GraphQL con la query hbBanner.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('WPB_BANNERS_VERSION', '1.1.0');
define('WPB_BANNERS_TABLE', 'wpb_banners');
define('WPB_BANNERS_TRANS_TABLE', 'wpb_banners_translations');

/* ─────────────────────────────────────────
   INSTALL: crea le tabelle al primo avvio
   ───────────────────────────────────────── */
function wpb_banners_install(): void
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

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

    // Tabella traduzioni banner (title, link, link_target per lingua aggiuntiva)
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
}

register_activation_hook(WP_ALT_AI_PLUGIN_DIR . 'wp-barrio.php', 'wpb_banners_install');

add_action('plugins_loaded', function () {
    wpb_banners_install();

    $installed_version = get_option('wpb_banners_db_version', '0');

    // Migrazione v1.1: crea la tabella traduzioni se non esiste ancora
    if (version_compare($installed_version, '1.1.0', '<')) {
        wpb_banners_install();
        update_option('wpb_banners_db_version', WPB_BANNERS_VERSION, false);
    }
});

/* ─────────────────────────────────────────
   HELPER DB
   ───────────────────────────────────────── */
function wpb_banners_get_all(): array
{
    global $wpdb;
    return $wpdb->get_results(
        "SELECT id, title, active, created_at FROM {$wpdb->prefix}wpb_banners ORDER BY id DESC"
    ) ?: [];
}

function wpb_banners_get(int $id): ?object
{
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wpb_banners WHERE id = %d",
        $id
    )) ?: null;
}

function wpb_banners_get_active(): ?object
{
    global $wpdb;
    return $wpdb->get_row(
        "SELECT * FROM {$wpdb->prefix}wpb_banners WHERE active = 1 LIMIT 1"
    ) ?: null;
}

/* ─────────────────────────────────────────
   HELPER DB — TRADUZIONI
   ───────────────────────────────────────── */

function wpb_banners_get_translation(int $banner_id, string $lang): ?object
{
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wpb_banners_translations
         WHERE banner_id = %d AND lang = %s",
        $banner_id,
        $lang
    )) ?: null;
}

function wpb_banners_save_translation(int $banner_id, string $lang, array $data): bool
{
    global $wpdb;
    $table = $wpdb->prefix . WPB_BANNERS_TRANS_TABLE;
    $existing = wpb_banners_get_translation($banner_id, $lang);

    $row = [
        'banner_id' => $banner_id,
        'lang' => $lang,
        'title' => $data['title'] ?? '',
        'link' => $data['link'] ?? '',
        'link_target' => $data['link_target'] ?? '_self',
    ];
    $format = ['%d', '%s', '%s', '%s', '%s'];

    if ($existing) {
        $ok = $wpdb->update($table, $row, ['id' => (int) $existing->id], $format, ['%d']);
    } else {
        $ok = $wpdb->insert($table, $row, $format);
    }

    return $ok !== false;
}

function wpb_banners_delete_translations(int $banner_id): void
{
    global $wpdb;
    $wpdb->delete(
        $wpdb->prefix . WPB_BANNERS_TRANS_TABLE,
        ['banner_id' => $banner_id],
        ['%d']
    );
}

/* ─────────────────────────────────────────
   TEMPLATE HELPER
   Cerca il template prima nel tema (wp-barrio/<name>),
   poi nel plugin (<module>/templates/<name>).
   Il terzo parametro è la cartella templates del modulo chiamante.
   ───────────────────────────────────────── */
if (!function_exists('wpb_get_template')) {
    function wpb_get_template(string $name, array $data = [], string $plugin_templates_dir = ''): string
    {
        $theme_file  = locate_template('wp-barrio/' . $name);
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
   SHORTCODE: [wpb_banner]
   Template override: wp-barrio/banner.php nel tema.
   Variabili disponibili nel template: $uid, $title, $link, $link_target, $rel
   ───────────────────────────────────────── */
add_shortcode('wpb_banner', 'wpb_banner_shortcode');

function wpb_banner_shortcode(): string
{
    $banner = wpb_banners_get_active();

    if (!$banner)
        return '';

    $title       = (string) $banner->title;
    $link        = (string) $banner->link;
    $link_target = $banner->link_target ?: '_self';

    $lang = '';
    if (defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE) {
        $lang = ICL_LANGUAGE_CODE;
    } elseif (function_exists('apply_filters')) {
        $lang = (string) apply_filters('wpml_current_language', '');
    }
    if ($lang) {
        $tr = wpb_banners_get_translation((int) $banner->id, $lang);
        if ($tr) {
            if ('' !== $tr->title)       $title       = (string) $tr->title;
            if ('' !== $tr->link)        $link        = (string) $tr->link;
            if ('' !== $tr->link_target) $link_target = (string) $tr->link_target;
        }
    }

    if ('' === $title)
        return '';

    $link_target = in_array($link_target, ['_self', '_blank'], true) ? $link_target : '_self';

    return wpb_get_template('banner.php', [
        'uid'         => 'wpb-banner-' . (int) $banner->id,
        'title'       => $title,
        'link'        => $link,
        'link_target' => $link_target,
        'rel'         => '_blank' === $link_target ? ' rel="noopener noreferrer"' : '',
    ], __DIR__ . '/templates');
}

/* ─────────────────────────────────────────
   MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (current_user_can('manage_options')) {
        // Amministratori: sottovoce nel menu WP Barrio
        add_submenu_page(
            'wp-barrio',
            'Banners — WP Barrio',
            'Banners',
            'manage_options',
            'wp-barrio-banners',
            'wpb_banners_admin_page'
        );
    } elseif (current_user_can('edit_others_posts')) {
        // Editor e ruoli con edit_others_posts: voce principale nel menu admin
        add_menu_page(
            'Banners — WP Barrio',
            'Banners',
            'edit_others_posts',
            'wp-barrio-banners',
            'wpb_banners_admin_page',
            'dashicons-flag',
            77
        );
    }
}, 12);

/* ─────────────────────────────────────────
   AJAX: salva banner (crea o aggiorna)
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_banners_save', function () {
    check_ajax_referer('wpb_banners_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $table = $wpdb->prefix . WPB_BANNERS_TABLE;

    $id = intval($_POST['banner_id'] ?? 0);
    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $link = sanitize_url(wp_unslash($_POST['link'] ?? ''));
    $link_target = in_array($_POST['link_target'] ?? '', ['_self', '_blank'], true)
        ? $_POST['link_target']
        : '_self';

    if ($title === '')
        wp_send_json_error('Il titolo è obbligatorio.');

    $data = compact('title', 'link', 'link_target');
    $format = ['%s', '%s', '%s'];

    if ($id > 0) {
        $ok = $wpdb->update($table, $data, ['id' => $id], $format, ['%d']);
    } else {
        $data['active'] = 0;
        $format[] = '%d';
        $ok = $wpdb->insert($table, $data, $format);
        $id = (int) $wpdb->insert_id;
    }

    if ($ok === false)
        wp_send_json_error('Errore DB: ' . $wpdb->last_error);

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }

    wp_send_json_success(['id' => $id, 'message' => 'Banner salvato.']);
});

/* ─────────────────────────────────────────
   AJAX: imposta banner attivo
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_banners_set_active', function () {
    check_ajax_referer('wpb_banners_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $table = $wpdb->prefix . WPB_BANNERS_TABLE;
    $id = intval($_POST['banner_id'] ?? 0);

    $wpdb->query("UPDATE {$table} SET active = 0");

    if ($id > 0) {
        $wpdb->update($table, ['active' => 1], ['id' => $id], ['%d'], ['%d']);
    }

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }

    wp_send_json_success('Banner attivo aggiornato.');
});

/* ─────────────────────────────────────────
   AJAX: elimina banner
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_banners_delete', function () {
    check_ajax_referer('wpb_banners_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $id = intval($_POST['banner_id'] ?? 0);
    if (!$id)
        wp_send_json_error('ID non valido.');

    wpb_banners_delete_translations($id);
    $wpdb->delete($wpdb->prefix . WPB_BANNERS_TABLE, ['id' => $id], ['%d']);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_send_json_success('Banner eliminato.');
});

/* ─────────────────────────────────────────
   AJAX: salva traduzione banner
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_banners_save_translation', function () {
    check_ajax_referer('wpb_banners_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $banner_id = intval($_POST['banner_id'] ?? 0);
    $lang = sanitize_key($_POST['lang'] ?? '');

    if (!$banner_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    if (!wpb_banners_get($banner_id))
        wp_send_json_error('Banner non trovato.');

    $allowed_langs = wpb_multilang_translation_languages();
    if (!in_array($lang, $allowed_langs, true))
        wp_send_json_error('Lingua non consentita.');

    $data = [
        'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
        'link' => sanitize_url(wp_unslash($_POST['link'] ?? '')),
        'link_target' => in_array($_POST['link_target'] ?? '', ['_self', '_blank'], true)
            ? $_POST['link_target'] : '_self',
    ];

    if ($data['title'] === '')
        wp_send_json_error('Il titolo è obbligatorio.');

    $ok = wpb_banners_save_translation($banner_id, $lang, $data);

    if (!$ok)
        wp_send_json_error('Errore DB.');

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }

    wp_send_json_success(['message' => 'Traduzione salvata.']);
});

/* ─────────────────────────────────────────
   AJAX: carica traduzione banner
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_banners_get_translation', function () {
    check_ajax_referer('wpb_banners_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $banner_id = intval($_POST['banner_id'] ?? 0);
    $lang = sanitize_key($_POST['lang'] ?? '');

    if (!$banner_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    $row = wpb_banners_get_translation($banner_id, $lang);

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
add_action('wp_ajax_wpb_banners_init_translation', function () {
    check_ajax_referer('wpb_banners_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $banner_id = intval($_POST['banner_id'] ?? 0);
    $lang = sanitize_key($_POST['lang'] ?? '');

    if (!$banner_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    $allowed_langs = wpb_multilang_translation_languages();
    if (!in_array($lang, $allowed_langs, true))
        wp_send_json_error('Lingua non consentita.');

    if (wpb_banners_get_translation($banner_id, $lang))
        wp_send_json_success(['already_exists' => true]);

    $source = wpb_banners_get($banner_id);
    if (!$source)
        wp_send_json_error('Banner non trovato.');

    $ok = wpb_banners_save_translation($banner_id, $lang, [
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
add_action('wp_ajax_wpb_banners_save_limit', function () {
    check_ajax_referer('wpb_banners_nonce', 'nonce');
    if (!wpb_tools_can_access())
        wp_send_json_error('Permesso negato.');

    $limit = max(1, intval($_POST['banner_limit'] ?? 1));
    update_option('wpb_banners_limit', $limit, false);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_send_json_success('Limite salvato.');
});

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function wpb_banners_admin_page(): void
{
    $nonce = wp_create_nonce('wpb_banners_nonce');
    $banners = wpb_banners_get_all();
    $active = wpb_banners_get_active();
    $active_id = $active ? (int) $active->id : 0;
    $banner_limit = (int) get_option('wpb_banners_limit', 1);
    $list_tab = isset($_GET['list_tab']) ? sanitize_key($_GET['list_tab']) : 'banners';

    $edit_id = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0;
    $editing = $edit_id ? wpb_banners_get($edit_id) : null;
    $qs_lang = isset($_GET['lang']) ? sanitize_key($_GET['lang']) : '';

    $ml_enabled = wpb_multilang_is_enabled();
    $ml_primary = wpb_multilang_primary_language();
    $ml_trans = wpb_multilang_translation_languages();
    $ml_languages = wpb_multilang_languages_with_flags();
    $ml_active = $ml_enabled && !empty($ml_trans);
    ?>
    <div id="wpb-banners-wrap" class="wrap wpb-wrap">

        <!-- Header -->
        <div class="wpb-ban-header">
            <div class="wpb-ban-header-icon"><span class="dashicons dashicons-flag"></span></div>
            <div>
                <h1>WP Barrio <span class="wpb-ban-breadcrumb">/ Banners</span></h1>
                <p class="wpb-ban-sub">Gestisci banner marquee per il frontend</p>
            </div>
        </div>
        <hr class="wpb-ban-divider">

        <?php if ($editing || (isset($_GET['edit_id']) && $_GET['edit_id'] === 'new')):
            $is_new = ($_GET['edit_id'] ?? '') === 'new';
            $banner = $is_new ? null : $editing;
            ?>

            <!-- ── EDITOR ── -->
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners')); ?>" class="wpb-ban-back">
                <span class="dashicons dashicons-arrow-left-alt2" style="font-size:15px;width:15px;height:15px;"></span>
                Torna alla lista
            </a>

            <div class="wpb-ban-editor">
                <input type="hidden" id="wpb-banner-id" value="<?php echo $is_new ? 0 : intval($banner->id ?? 0); ?>">

                <?php if ($ml_active && !$is_new):
                    $banner_id_int = (int) ($banner->id ?? 0);
                    $editor_langs = [$ml_primary => ($ml_languages[$ml_primary] ?? $ml_primary) . ' (principale)'];
                    foreach ($ml_trans as $lc) {
                        if (wpb_banners_get_translation($banner_id_int, $lc)) {
                            $editor_langs[$lc] = $ml_languages[$lc] ?? strtoupper($lc);
                        }
                    }
                    $initial_lang = isset($editor_langs[$qs_lang]) ? $qs_lang : $ml_primary;
                    ?>
                    <div class="wpb-lang-bar">
                        <label for="wpb-lang-select">
                            <span class="dashicons dashicons-translation"
                                style="font-size:15px;width:15px;height:15px;vertical-align:middle;margin-right:3px;color:#6b7280;"></span>
                            Lingua:
                        </label>
                        <select id="wpb-lang-select">
                            <?php foreach ($editor_langs as $lc => $label): ?>
                                <option value="<?php echo esc_attr($lc); ?>"
                                    data-is-primary="<?php echo $lc === $ml_primary ? '1' : '0'; ?>" <?php selected($initial_lang, $lc); ?>><?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="wpb-trans-notice" id="wpb-trans-notice"
                        style="display:<?php echo $initial_lang !== $ml_primary ? 'flex' : 'none'; ?>;">
                        <span class="dashicons dashicons-translation"></span>
                        Stai modificando la versione in <strong
                            id="wpb-trans-notice-lang"><?php echo esc_html($ml_languages[$initial_lang] ?? $initial_lang); ?></strong>.
                        Lo stato attivo è condiviso con la versione principale.
                    </div>

                    <?php
                    if ($initial_lang !== $ml_primary) {
                        $trans_data = wpb_banners_get_translation($banner_id_int, $initial_lang);
                        if ($trans_data)
                            $banner = $trans_data;
                    }
                endif; ?>

                <div class="wpb-ban-editor-grid">

                    <!-- Colonna sinistra: campi -->
                    <div>
                        <div class="wpb-efield">
                            <label class="wpb-efield-label" for="wpb-banner-title">Titolo <span
                                    style="color:#dc2626;">*</span></label>
                            <input type="text" id="wpb-banner-title" value="<?php echo esc_attr($banner->title ?? ''); ?>"
                                placeholder="Es. Spedizione gratuita su tutti gli ordini">
                        </div>

                        <div class="wpb-efield">
                            <label class="wpb-efield-label">Link <span class="wpb-efield-optional">(opzionale)</span></label>
                            <div class="wpb-link-grid">
                                <div>
                                    <input type="url" id="wpb-banner-link" value="<?php echo esc_attr($banner->link ?? ''); ?>"
                                        placeholder="https://…">
                                    <p class="wpb-efield-desc">URL</p>
                                </div>
                                <div>
                                    <select id="wpb-banner-link-target">
                                        <option value="_self" <?php selected(($banner->link_target ?? '_self'), '_self'); ?>>
                                            Stessa scheda</option>
                                        <option value="_blank" <?php selected(($banner->link_target ?? '_self'), '_blank'); ?>>
                                            Nuova scheda</option>
                                    </select>
                                    <p class="wpb-efield-desc">Target</p>
                                </div>
                            </div>
                        </div>

                        <div class="wpb-editor-actions">
                            <button type="button" class="wpb-btn wpb-btn-primary" id="wpb-banner-save-btn">
                                <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                                Salva banner
                            </button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners')); ?>"
                                class="wpb-btn wpb-btn-ghost">Annulla</a>
                            <span id="wpb-banner-save-msg" class="wpb-editor-msg" style="display:none;"></span>
                        </div>
                    </div>

                    <!-- Colonna destra: anteprima -->
                    <div style="position:sticky;top:32px;">
                        <div class="wpb-preview-label">Anteprima</div>

                        <!-- Chrome mock -->
                        <div class="wpb-preview-browser-chrome">
                            <div class="wpb-preview-dots">
                                <span style="background:#ff5f57;"></span>
                                <span style="background:#febc2e;"></span>
                                <span style="background:#28c840;"></span>
                            </div>
                            <div class="wpb-preview-urlbar"></div>
                        </div>

                        <!-- Banner marquee -->
                        <div class="wpb-marquee-outer">
                            <div class="wpb-marquee-track" id="wpb-marquee-track">
                                <?php for ($i = 0; $i < 6; $i++): ?>
                                    <span class="wpb-marquee-item">
                                        <span
                                            class="wpb-prev-title"><?php echo esc_html($banner->title ?? 'Titolo del banner'); ?></span>
                                        <?php if (!empty($banner->link)): ?>
                                            <a class="wpb-marquee-link wpb-prev-link" href="#"
                                                onclick="return false;"><?php echo esc_html($banner->link); ?></a>
                                        <?php else: ?>
                                            <a class="wpb-marquee-link wpb-prev-link" href="#" onclick="return false;"
                                                style="display:none;"></a>
                                        <?php endif; ?>
                                        <span class="wpb-marquee-sep"></span>
                                    </span>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <!-- Finta pagina sotto -->
                        <div class="wpb-preview-page">
                            <div class="wpb-preview-line" style="width:70%;"></div>
                            <div class="wpb-preview-line" style="width:90%;"></div>
                            <div class="wpb-preview-line" style="width:50%;margin-bottom:0;"></div>
                        </div>

                        <p class="wpb-preview-hint">L'anteprima si aggiorna in tempo reale</p>
                    </div>

                </div><!-- .wpb-ban-editor-grid -->
            </div><!-- .wpb-ban-editor -->

        <?php else: ?>

            <!-- ── LISTA ── -->

            <?php if (wpb_tools_can_access()): ?>
                <div class="wpb-ban-tabs">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners')); ?>"
                        class="wpb-ban-tab <?php echo $list_tab === 'banners' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-flag" style="font-size:15px;width:15px;height:15px;"></span>
                        Banner
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners&list_tab=settings')); ?>"
                        class="wpb-ban-tab <?php echo $list_tab === 'settings' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-admin-settings" style="font-size:15px;width:15px;height:15px;"></span>
                        Impostazioni
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($list_tab === 'settings' && wpb_tools_can_access()): ?>

                <!-- Tab impostazioni -->
                <div class="wpb-ban-settings-card">
                    <h3 class="wpb-ban-settings-title">Limiti</h3>
                    <p class="wpb-ban-settings-desc">Numero massimo di banner che possono essere creati. Raggiunto il limite, il
                        pulsante «+ Nuovo» viene disabilitato.</p>
                    <div class="wpb-efield" style="margin-bottom:16px;">
                        <label class="wpb-efield-label" for="wpb-banner-limit-input">Numero massimo di banner</label>
                        <input type="number" id="wpb-banner-limit-input" min="1" max="999"
                            value="<?php echo intval($banner_limit); ?>"
                            style="width:110px;padding:8px 12px;border:1px solid #e5e7eb;border-radius:7px;font-size:13px;font-family:inherit;">
                        <p class="wpb-efield-desc">Default: 1. Valori alti rimuovono in pratica il limite.</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <button type="button" class="wpb-btn wpb-btn-primary wpb-btn-sm" id="wpb-save-limit-btn">
                            <span class="dashicons dashicons-yes" style="font-size:15px;width:15px;height:15px;"></span>
                            Salva
                        </button>
                        <span id="wpb-save-limit-msg" class="wpb-ban-save-ok">&#x2713; Salvato</span>
                    </div>
                </div>

            <?php else: ?>

                <!-- Toolbar lista -->
                <div class="wpb-ban-toolbar">
                    <?php $at_limit = count($banners) >= $banner_limit; ?>
                    <?php if ($at_limit): ?>
                        <button class="wpb-btn wpb-btn-primary" disabled
                            title="Limite di <?php echo intval($banner_limit); ?> banner raggiunto"
                            style="opacity:.45;cursor:not-allowed;">
                            + Nuovo banner
                        </button>
                    <?php else: ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners&edit_id=new')); ?>"
                            class="wpb-btn wpb-btn-primary">+ Nuovo banner</a>
                    <?php endif; ?>
                </div>

                <!-- Notice banner attivo -->
                <?php if (!empty($active)): ?>
                    <div id="wpb-active-banner-notice" class="wpb-ban-notice wpb-ban-notice-success">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <div>Banner attivo: <strong><?php echo esc_html($active->title); ?></strong></div>
                    </div>
                <?php else: ?>
                    <div id="wpb-active-banner-notice" class="wpb-ban-notice wpb-ban-notice-warning">
                        <span class="dashicons dashicons-warning"></span>
                        <div>Nessun banner attivo al momento.</div>
                    </div>
                <?php endif; ?>

                <?php if (empty($banners)): ?>
                    <div class="wpb-ban-empty">
                        <span class="dashicons dashicons-flag"></span>
                        <p class="wpb-ban-empty-title">Nessun banner ancora</p>
                        <p class="wpb-ban-empty-sub">Crea il tuo primo banner in pochi secondi.</p>
                        <?php if (count($banners) < $banner_limit): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners&edit_id=new')); ?>"
                                class="wpb-btn wpb-btn-primary">+ Nuovo banner</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>

                    <div id="wpb-banners-list">
                        <?php foreach ($banners as $b):
                            $is_active = (int) $b->id === $active_id;
                            ?>
                            <div class="wpb-ban-card" data-id="<?php echo intval($b->id); ?>">
                                <div class="wpb-ban-row">
                                    <!-- Info -->
                                    <div>
                                        <div class="wpb-ban-title"><?php echo esc_html($b->title); ?></div>
                                        <div class="wpb-ban-meta">Creato il
                                            <?php echo esc_html(date_i18n('d/m/Y', strtotime($b->created_at))); ?>
                                        </div>
                                    </div>
                                    <!-- Badge -->
                                    <div>
                                        <?php if ($is_active): ?>
                                            <span class="wpb-badge-on">
                                                <span class="wpb-badge-on-dot"></span>
                                                Attivo
                                            </span>
                                        <?php else: ?>
                                            <span class="wpb-badge-off">Inattivo</span>
                                        <?php endif; ?>
                                    </div>
                                    <!-- Azioni -->
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <label class="wpb-toggle-active" title="<?php echo $is_active ? 'Disattiva' : 'Attiva'; ?>">
                                            <input type="checkbox" class="wpb-toggle-active-cb" data-id="<?php echo intval($b->id); ?>"
                                                <?php checked($is_active); ?>>
                                            <span class="wpb-toggle-active-track"></span>
                                        </label>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners&edit_id=' . $b->id)); ?>"
                                            class="wpb-btn wpb-btn-ghost wpb-btn-sm" title="Modifica">
                                            <span class="dashicons dashicons-edit" style="font-size:14px;width:14px;height:14px;"></span>
                                        </a>
                                        <button type="button" class="wpb-btn wpb-btn-danger wpb-btn-sm wpb-banner-delete-btn"
                                            data-id="<?php echo intval($b->id); ?>" title="Elimina">
                                            <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span>
                                        </button>
                                    </div>
                                </div><!-- .wpb-ban-row -->

                                <?php if ($ml_active): ?>
                                    <div class="wpb-ban-langs">
                                        <span class="wpb-ban-langs-label">Lingue:</span>
                                        <span class="wpb-lang-pill primary" title="Lingua principale">
                                            <span class="dashicons dashicons-yes" style="font-size:12px;width:12px;height:12px;"></span>
                                            <?php echo esc_html($ml_languages[$ml_primary] ?? strtoupper($ml_primary)); ?>
                                        </span>
                                        <?php foreach ($ml_trans as $lang_code):
                                            $lang_label = $ml_languages[$lang_code] ?? strtoupper($lang_code);
                                            $has_trans = (bool) wpb_banners_get_translation((int) $b->id, $lang_code);
                                            ?>
                                            <?php if ($has_trans): ?>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners&edit_id=' . $b->id . '&lang=' . $lang_code)); ?>"
                                                    class="wpb-lang-pill translated"
                                                    title="Modifica traduzione in <?php echo esc_attr($lang_label); ?>">
                                                    <span class="dashicons dashicons-yes" style="font-size:12px;width:12px;height:12px;"></span>
                                                    <?php echo esc_html($lang_label); ?>
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="wpb-lang-pill missing wpb-lang-init-btn"
                                                    data-banner-id="<?php echo intval($b->id); ?>" data-lang="<?php echo esc_attr($lang_code); ?>"
                                                    data-lang-label="<?php echo esc_attr($lang_label); ?>"
                                                    title="Inizializza traduzione in <?php echo esc_attr($lang_label); ?>">
                                                    + <?php echo esc_html($lang_label); ?>
                                                </button>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                            </div><!-- .wpb-ban-card -->
                        <?php endforeach; ?>
                    </div>

                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

    </div><!-- #wpb-banners-wrap -->

    <script>
        (function () {
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var urlEditBase = <?php echo wp_json_encode(admin_url('admin.php?page=wp-barrio-banners&edit_id=')); ?>;

            var mlActive = <?php echo wp_json_encode($ml_active); ?>;
            var mlPrimary = <?php echo wp_json_encode($ml_primary); ?>;
            var mlLanguages = <?php echo wp_json_encode($ml_languages); ?>;
            var currentLang = <?php echo wp_json_encode($initial_lang ?? $ml_primary); ?>;

            /* ════════════════════════════════════════
               MULTILINGUA — select lingua nell'editor
               ════════════════════════════════════════ */
            (function () {
                if (!mlActive) return;

                var langSelect = document.getElementById('wpb-lang-select');
                var notice = document.getElementById('wpb-trans-notice');
                var noticeLang = document.getElementById('wpb-trans-notice-lang');
                var saveBtn = document.getElementById('wpb-banner-save-btn');

                if (!langSelect) return;

                function loadPrimaryFields() {
                    ['wpb-banner-title', 'wpb-banner-link', 'wpb-banner-link-target'].forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el && el.dataset.primary !== undefined) el.value = el.dataset.primary;
                    });
                    refreshMarqueeFromFields();
                }

                function fillTranslationFields(data) {
                    var title = document.getElementById('wpb-banner-title');
                    var link = document.getElementById('wpb-banner-link');
                    var linkTarget = document.getElementById('wpb-banner-link-target');
                    if (title) title.value = data.title || '';
                    if (link) link.value = data.link || '';
                    if (linkTarget) linkTarget.value = data.link_target || '_self';
                    refreshMarqueeFromFields();
                }

                function switchLang(lang, isPrimary) {
                    currentLang = lang;
                    if (saveBtn) {
                        var icon = '<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> ';
                        saveBtn.innerHTML = icon + (isPrimary ? 'Salva banner' : 'Salva traduzione');
                    }
                    if (notice && noticeLang) {
                        if (isPrimary) { notice.style.display = 'none'; }
                        else { noticeLang.textContent = mlLanguages[lang] || lang; notice.style.display = 'flex'; }
                    }
                    if (isPrimary) { loadPrimaryFields(); return; }

                    var bannerId = document.getElementById('wpb-banner-id').value;
                    if (!bannerId || bannerId === '0') return;

                    langSelect.disabled = true;
                    fetch(ajaxUrl, {
                        method: 'POST',
                        body: new URLSearchParams({ action: 'wpb_banners_get_translation', nonce: nonce, banner_id: bannerId, lang: lang })
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (res) { if (res.success) fillTranslationFields(res.data); })
                        .catch(function () { })
                        .finally(function () { langSelect.disabled = false; });
                }

                langSelect.addEventListener('change', function () {
                    var opt = this.options[this.selectedIndex];
                    switchLang(this.value, opt.dataset.isPrimary === '1');
                });

                setTimeout(function () {
                    var primaryData = <?php echo wp_json_encode([
                        'title' => $editing->title ?? '',
                        'link' => $editing->link ?? '',
                        'link_target' => $editing->link_target ?? '_self',
                    ]); ?>;
                    ['wpb-banner-title', 'wpb-banner-link', 'wpb-banner-link-target'].forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el) {
                            var key = id.replace('wpb-banner-', '').replace(/-/g, '_');
                            el.dataset.primary = primaryData[key] !== undefined ? primaryData[key] : el.value;
                        }
                    });
                    var initialOpt = langSelect.options[langSelect.selectedIndex];
                    if (initialOpt && initialOpt.dataset.isPrimary === '0') {
                        var icon = '<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> ';
                        if (saveBtn) saveBtn.innerHTML = icon + 'Salva traduzione';
                    }
                }, 300);
            })();

            /* ════════════════════════════════════════
               LISTA — inizializza traduzione
               ════════════════════════════════════════ */
            document.querySelectorAll('.wpb-lang-init-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var bannerId = this.dataset.bannerId;
                    var lang = this.dataset.lang;
                    var langLabel = this.dataset.langLabel;
                    var pill = this;

                    pill.classList.add('loading');
                    pill.disabled = true;

                    fetch(ajaxUrl, {
                        method: 'POST',
                        body: new URLSearchParams({ action: 'wpb_banners_init_translation', nonce: nonce, banner_id: bannerId, lang: lang })
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) {
                                var link = document.createElement('a');
                                link.href = urlEditBase + bannerId + '&lang=' + lang;
                                link.className = 'wpb-lang-pill translated';
                                link.title = 'Modifica traduzione in ' + langLabel;
                                link.innerHTML = '<span class="dashicons dashicons-yes" style="font-size:12px;width:12px;height:12px;"></span> ' + langLabel;
                                pill.replaceWith(link);
                            } else {
                                alert(res.data || 'Errore.');
                                pill.classList.remove('loading');
                                pill.disabled = false;
                            }
                        })
                        .catch(function () {
                            alert('Errore di rete.');
                            pill.classList.remove('loading');
                            pill.disabled = false;
                        });
                });
            });

            /* ════════════════════════════════════════
               IMPOSTAZIONI — salva limite
               ════════════════════════════════════════ */
            var saveLimitBtn = document.getElementById('wpb-save-limit-btn');
            if (saveLimitBtn) {
                saveLimitBtn.addEventListener('click', function () {
                    var btn = this;
                    var msg = document.getElementById('wpb-save-limit-msg');
                    var val = parseInt(document.getElementById('wpb-banner-limit-input').value, 10) || 1;
                    btn.disabled = true;
                    fetch(ajaxUrl, {
                        method: 'POST',
                        body: new URLSearchParams({ action: 'wpb_banners_save_limit', nonce: nonce, banner_limit: val })
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) {
                                msg.style.display = 'inline';
                                setTimeout(function () { msg.style.display = 'none'; }, 3000);
                            } else { alert(res.data || 'Errore.'); }
                        })
                        .catch(function () { alert('Errore di rete.'); })
                        .finally(function () { btn.disabled = false; });
                });
            }

            /* ════════════════════════════════════════
               LISTA — toggle attiva/disattiva
               ════════════════════════════════════════ */
            document.querySelectorAll('.wpb-toggle-active-cb').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var bannerId = this.checked ? parseInt(this.dataset.id, 10) : 0;
                    document.querySelectorAll('.wpb-toggle-active-cb').forEach(function (c) {
                        c.checked = false;
                        updateRowBadge(c.closest('.wpb-ban-card'), false);
                    });
                    if (bannerId) { this.checked = true; updateRowBadge(this.closest('.wpb-ban-card'), true); }

                    fetch(ajaxUrl, {
                        method: 'POST',
                        body: new URLSearchParams({ action: 'wpb_banners_set_active', nonce: nonce, banner_id: bannerId })
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (!res.success) { alert(res.data || 'Errore.'); location.reload(); }
                            else { updateActiveBannerNotice(bannerId); }
                        })
                        .catch(function () { alert('Errore di rete.'); location.reload(); });
                });
            });

            function updateRowBadge(card, isActive) {
                if (!card) return;
                var badge = card.querySelector('.wpb-badge-on, .wpb-badge-off');
                if (!badge) return;
                if (isActive) {
                    badge.className = 'wpb-badge-on';
                    badge.innerHTML = '<span class="wpb-badge-on-dot"></span> Attivo';
                } else {
                    badge.className = 'wpb-badge-off';
                    badge.textContent = 'Inattivo';
                }
            }

            function updateActiveBannerNotice(activeId) {
                var notice = document.getElementById('wpb-active-banner-notice');
                if (!notice) return;
                if (!activeId) {
                    notice.className = 'wpb-ban-notice wpb-ban-notice-warning';
                    notice.innerHTML = '<span class="dashicons dashicons-warning" style="font-size:17px;width:17px;height:17px;flex-shrink:0;margin-top:1px;color:#d97706;"></span><div>Nessun banner attivo al momento.</div>';
                    return;
                }
                var card = document.querySelector('.wpb-ban-card[data-id="' + activeId + '"]');
                var title = card ? card.querySelector('.wpb-ban-title').textContent : '';
                notice.className = 'wpb-ban-notice wpb-ban-notice-success';
                notice.innerHTML = '<span class="dashicons dashicons-yes-alt" style="font-size:17px;width:17px;height:17px;flex-shrink:0;margin-top:1px;color:#059669;"></span><div>Banner attivo: <strong>' + title + '</strong></div>';
            }

            /* ════════════════════════════════════════
               LISTA — elimina
               ════════════════════════════════════════ */
            document.querySelectorAll('.wpb-banner-delete-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!confirm('Eliminare questo banner?')) return;
                    var id = parseInt(this.dataset.id, 10);
                    var card = this.closest('.wpb-ban-card');
                    fetch(ajaxUrl, {
                        method: 'POST',
                        body: new URLSearchParams({ action: 'wpb_banners_delete', nonce: nonce, banner_id: id })
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) {
                                card.style.transition = 'opacity .3s';
                                card.style.opacity = '0';
                                setTimeout(function () { card.remove(); }, 300);
                            } else { alert(res.data || 'Errore.'); }
                        })
                        .catch(function () { alert('Errore di rete.'); });
                });
            });

            /* ════════════════════════════════════════
               EDITOR — anteprima live marquee
               ════════════════════════════════════════ */
            function refreshMarqueeFromFields() {
                var titleVal = (document.getElementById('wpb-banner-title') || {}).value || 'Titolo del banner';
                var linkVal = (document.getElementById('wpb-banner-link') || {}).value || '';
                document.querySelectorAll('#wpb-marquee-track .wpb-prev-title').forEach(function (el) {
                    el.textContent = titleVal.trim() || 'Titolo del banner';
                });
                document.querySelectorAll('#wpb-marquee-track .wpb-prev-link').forEach(function (el) {
                    if (linkVal.trim()) { el.style.display = ''; el.textContent = linkVal.trim(); }
                    else { el.style.display = 'none'; }
                });
            }

            (function () {
                var titleInput = document.getElementById('wpb-banner-title');
                var linkInput = document.getElementById('wpb-banner-link');
                if (titleInput) titleInput.addEventListener('input', refreshMarqueeFromFields);
                if (linkInput) linkInput.addEventListener('input', refreshMarqueeFromFields);
                refreshMarqueeFromFields();
            })();

            /* ════════════════════════════════════════
               EDITOR — salva
               ════════════════════════════════════════ */
            var saveBtn = document.getElementById('wpb-banner-save-btn');
            if (saveBtn) {
                saveBtn.addEventListener('click', function () {
                    var btn = this;
                    var msg = document.getElementById('wpb-banner-save-msg');
                    var title = document.getElementById('wpb-banner-title').value.trim();

                    if (!title) {
                        msg.className = 'wpb-editor-msg err'; msg.style.display = 'inline';
                        msg.textContent = '✗ Il titolo è obbligatorio.';
                        return;
                    }

                    btn.disabled = true;
                    btn.innerHTML = '<div style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:wpbMarquee .0001s,spin .65s linear infinite;vertical-align:middle;margin-right:4px;"></div> Salvataggio…';
                    msg.style.display = 'none';

                    var bannerId = document.getElementById('wpb-banner-id').value;
                    var isPrimary = !mlActive || currentLang === mlPrimary;
                    var fd;

                    if (isPrimary) {
                        fd = new URLSearchParams({
                            action: 'wpb_banners_save', nonce: nonce, banner_id: bannerId,
                            title: title,
                            link: document.getElementById('wpb-banner-link').value.trim(),
                            link_target: document.getElementById('wpb-banner-link-target').value,
                        });
                    } else {
                        if (!bannerId || bannerId === '0') {
                            msg.className = 'wpb-editor-msg err'; msg.style.display = 'inline';
                            msg.textContent = '✗ Salva prima il banner in lingua principale.';
                            btn.disabled = false;
                            btn.innerHTML = '<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> Salva traduzione';
                            return;
                        }
                        fd = new URLSearchParams({
                            action: 'wpb_banners_save_translation', nonce: nonce, banner_id: bannerId,
                            lang: currentLang, title: title,
                            link: document.getElementById('wpb-banner-link').value.trim(),
                            link_target: document.getElementById('wpb-banner-link-target').value,
                        });
                    }

                    fetch(ajaxUrl, { method: 'POST', body: fd })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            var icon = '<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> ';
                            if (res.success) {
                                msg.className = 'wpb-editor-msg ok'; msg.style.display = 'inline';
                                msg.textContent = isPrimary ? '✓ Banner salvato.' : '✓ Traduzione salvata.';
                                if (isPrimary && bannerId === '0') {
                                    setTimeout(function () { window.location.href = urlEditBase + res.data.id; }, 800);
                                }
                            } else {
                                msg.className = 'wpb-editor-msg err'; msg.style.display = 'inline';
                                msg.textContent = '✗ ' + (res.data || 'Errore.');
                            }
                            btn.disabled = false;
                            btn.innerHTML = icon + (isPrimary ? 'Salva banner' : 'Salva traduzione');
                        })
                        .catch(function () {
                            var icon = '<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> ';
                            msg.className = 'wpb-editor-msg err'; msg.style.display = 'inline';
                            msg.textContent = '✗ Errore di rete.';
                            btn.disabled = false;
                            btn.innerHTML = icon + (isPrimary ? 'Salva banner' : 'Salva traduzione');
                        });
                });
            }

        })();
    </script>
    <?php
}