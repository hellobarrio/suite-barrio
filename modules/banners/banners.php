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

define('WPB_BANNERS_VERSION',      '1.1.0');
define('WPB_BANNERS_TABLE',        'wpb_banners');
define('WPB_BANNERS_TRANS_TABLE',  'wpb_banners_translations');

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
        "SELECT * FROM {$wpdb->prefix}wpb_banners WHERE id = %d", $id
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
        $banner_id, $lang
    )) ?: null;
}

function wpb_banners_save_translation(int $banner_id, string $lang, array $data): bool
{
    global $wpdb;
    $table    = $wpdb->prefix . WPB_BANNERS_TRANS_TABLE;
    $existing = wpb_banners_get_translation($banner_id, $lang);

    $row    = [
        'banner_id'   => $banner_id,
        'lang'        => $lang,
        'title'       => $data['title']       ?? '',
        'link'        => $data['link']        ?? '',
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
}, 17);

/* ─────────────────────────────────────────
   AJAX: salva banner (crea o aggiorna)
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_banners_save', function () {
    check_ajax_referer('wpb_banners_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $table = $wpdb->prefix . WPB_BANNERS_TABLE;

    $id         = intval($_POST['banner_id'] ?? 0);
    $title      = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $link       = sanitize_url(wp_unslash($_POST['link'] ?? ''));
    $link_target = in_array($_POST['link_target'] ?? '', ['_self', '_blank'], true)
        ? $_POST['link_target']
        : '_self';

    if ($title === '')
        wp_send_json_error('Il titolo è obbligatorio.');

    $data   = compact('title', 'link', 'link_target');
    $format = ['%s', '%s', '%s'];

    if ($id > 0) {
        $ok = $wpdb->update($table, $data, ['id' => $id], $format, ['%d']);
    } else {
        $data['active'] = 0;
        $format[]       = '%d';
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
    $id    = intval($_POST['banner_id'] ?? 0);

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
    $lang      = sanitize_key($_POST['lang'] ?? '');

    if (!$banner_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    if (!wpb_banners_get($banner_id))
        wp_send_json_error('Banner non trovato.');

    $allowed_langs = wpb_multilang_translation_languages();
    if (!in_array($lang, $allowed_langs, true))
        wp_send_json_error('Lingua non consentita.');

    $data = [
        'title'       => sanitize_text_field(wp_unslash($_POST['title']       ?? '')),
        'link'        => sanitize_url(wp_unslash($_POST['link']               ?? '')),
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
    $lang      = sanitize_key($_POST['lang'] ?? '');

    if (!$banner_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    $row = wpb_banners_get_translation($banner_id, $lang);

    wp_send_json_success([
        'exists'      => (bool) $row,
        'title'       => $row->title       ?? '',
        'link'        => $row->link        ?? '',
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
    $lang      = sanitize_key($_POST['lang'] ?? '');

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
        'title'       => $source->title       ?? '',
        'link'        => $source->link        ?? '',
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
    $nonce        = wp_create_nonce('wpb_banners_nonce');
    $banners      = wpb_banners_get_all();
    $active       = wpb_banners_get_active();
    $active_id    = $active ? (int) $active->id : 0;
    $banner_limit = (int) get_option('wpb_banners_limit', 1);
    $list_tab     = isset($_GET['list_tab']) ? sanitize_key($_GET['list_tab']) : 'banners';

    $edit_id = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0;
    $editing = $edit_id ? wpb_banners_get($edit_id) : null;
    $qs_lang = isset($_GET['lang']) ? sanitize_key($_GET['lang']) : '';

    // Dati multilingua
    $ml_enabled   = wpb_multilang_is_enabled();
    $ml_primary   = wpb_multilang_primary_language();
    $ml_trans     = wpb_multilang_translation_languages();
    $ml_languages = wpb_multilang_languages_with_flags();
    $ml_active    = $ml_enabled && !empty($ml_trans);
    ?>
    <style>
        /* ── Toolbar ── */
        .wpb-banners-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        /* ── Card lista ── */
        .wpb-banner-row {
            display: grid;
            grid-template-columns: 1fr auto auto;
            align-items: center;
            gap: 16px;
            padding: 14px 18px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            margin-bottom: 4px;
            transition: box-shadow .15s;
        }
        .wpb-banner-row:hover { box-shadow: 0 1px 4px rgba(0,0,0,.08); }

        .wpb-banner-info .wpb-banner-title {
            font-size: 13px;
            font-weight: 600;
            color: #1d2327;
            margin-bottom: 2px;
        }
        .wpb-banner-info .wpb-banner-meta {
            font-size: 11px;
            color: #a7aaad;
        }

        .wpb-banner-actions { display: flex; gap: 8px; align-items: center; }

        /* Badge attivo */
        .wpb-badge-active {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 600;
            color: #00a32a;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 3px;
            padding: 2px 8px;
        }
        .wpb-badge-inactive {
            font-size: 11px;
            color: #a7aaad;
            padding: 2px 8px;
        }

        /* Toggle attivo */
        .wpb-toggle-active {
            position: relative;
            width: 36px;
            height: 20px;
            flex-shrink: 0;
            cursor: pointer;
        }
        .wpb-toggle-active input { opacity: 0; width: 0; height: 0; position: absolute; }
        .wpb-toggle-active-slider {
            position: absolute;
            inset: 0;
            background: #a7aaad;
            border-radius: 20px;
            transition: background .2s;
        }
        .wpb-toggle-active-slider:before {
            content: '';
            position: absolute;
            width: 14px; height: 14px;
            left: 3px; top: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform .2s;
        }
        .wpb-toggle-active input:checked + .wpb-toggle-active-slider { background: #00a32a; }
        .wpb-toggle-active input:checked + .wpb-toggle-active-slider:before { transform: translateX(16px); }

        /* ── Form editor ── */
        .wpb-banners-editor {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            padding: 24px;
        }

        .wpb-editor-field { margin-bottom: 20px; }
        .wpb-editor-field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .wpb-editor-field input[type="text"],
        .wpb-editor-field input[type="url"] {
            width: 100%;
            max-width: 540px;
        }
        .wpb-editor-field .description {
            font-size: 12px;
            color: #646970;
            margin-top: 4px;
        }

        /* Empty state */
        .wpb-banners-empty {
            text-align: center;
            padding: 48px 20px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            color: #646970;
        }
        .wpb-banners-empty .dashicons {
            font-size: 40px;
            width: 40px;
            height: 40px;
            color: #c3c4c7;
            display: block;
            margin: 0 auto 12px;
        }

        /* ── Marquee preview ── */
        .wpb-marquee-outer {
            width: 100%;
            background: #111;
            overflow: hidden;
            border-radius: 6px;
            padding: 0;
            position: relative;
        }
        .wpb-marquee-track {
            display: flex;
            white-space: nowrap;
            animation: wpbMarquee 12s linear infinite;
            cursor: default;
            user-select: none;
        }
        .wpb-marquee-outer:hover .wpb-marquee-track {
            animation-play-state: paused;
        }
        @keyframes wpbMarquee {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }
        .wpb-marquee-item {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            padding: 12px 14px 12px 0px;
            color: #fff;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 14px;
            font-weight: 500;
            letter-spacing: .01em;
        }
        .wpb-marquee-sep {
            display: inline-block;
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: rgba(255,255,255,.35);
            flex-shrink: 0;
        }
        .wpb-marquee-link {
            color: rgba(255,255,255,.6);
            font-size: 12px;
            text-decoration: none;
            border-bottom: 1px solid rgba(255,255,255,.25);
            pointer-events: none;
        }
        .wpb-preview-hint {
            font-size: 11px;
            color: #a7aaad;
            margin-top: 10px;
            text-align: center;
        }

        /* ── Select lingua nell'editor ── */
        .wpb-lang-select-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #f0f0f1;
        }
        .wpb-lang-select-wrap label {
            font-size: 13px;
            font-weight: 600;
            color: #1d2327;
            white-space: nowrap;
            margin: 0;
        }
        #wpb-lang-select {
            min-width: 180px;
            padding: 4px 8px;
            border: 1px solid #8c8f94;
            border-radius: 3px;
            font-size: 13px;
        }
        .wpb-trans-notice {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            background: #f0f6fc;
            border: 1px solid #c6d9ef;
            border-radius: 3px;
            font-size: 12px;
            color: #0a4b78;
            margin-bottom: 16px;
        }

        /* ── Riga lingue nella lista banner ── */
        .wpb-banner-langs {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px 10px;
            border-top: 1px solid #f6f7f7;
            background: #fafafa;
        }
        .wpb-banner-langs-label {
            font-size: 11px;
            color: #a7aaad;
            margin-right: 4px;
            flex-shrink: 0;
        }
        .wpb-lang-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 3px;
            border: 1px solid;
            cursor: default;
            transition: background .15s, border-color .15s;
        }
        .wpb-lang-pill.primary   { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
        .wpb-lang-pill.translated { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
        .wpb-lang-pill.missing   { background: #fff; border-color: #c3c4c7; color: #646970; cursor: pointer; }
        .wpb-lang-pill.missing:hover { background: #f0f6fc; border-color: #72aee6; color: #2271b1; }
        .wpb-lang-pill.missing .wpb-lang-add { font-size: 14px; line-height: 1; font-weight: 400; }
        .wpb-lang-pill.loading   { opacity: .6; cursor: wait; }
    </style>

    <div id="wpb-banners-wrap" class="wrap">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-flag"
                style="font-size:22px;width:22px;height:22px;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
            WP Barrio <span style="font-size:15px;font-weight:400;color:#646970;margin-left:4px;">/ Banners</span>
        </h1>

        <?php if (!$editing): ?>
            <?php $at_limit = count($banners) >= $banner_limit; ?>
            <?php if ($at_limit): ?>
                <span class="page-title-action" style="opacity:.5;cursor:not-allowed;"
                    title="Limite di <?php echo intval($banner_limit); ?> banner raggiunto">+ Nuovo banner</span>
            <?php else: ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners&edit_id=new')); ?>"
                    class="page-title-action">+ Nuovo banner</a>
            <?php endif; ?>
        <?php endif; ?>

        <hr class="wp-header-end" style="margin-bottom:24px;">

        <?php if ($editing || isset($_GET['edit_id']) && $_GET['edit_id'] === 'new'): ?>
            <?php
            $is_new = ($_GET['edit_id'] ?? '') === 'new';
            $banner  = $is_new ? null : $editing;
            ?>
            <!-- ── EDITOR ── -->
            <div style="margin-bottom:12px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners')); ?>"
                    style="font-size:13px;color:#646970;text-decoration:none;">
                    ← Torna alla lista
                </a>
            </div>

            <h2 style="font-size:16px;font-weight:600;margin:0 0 16px;">
                <?php echo $is_new ? 'Nuovo banner' : 'Modifica banner: ' . esc_html($banner->title ?? ''); ?>
            </h2>

            <div class="wpb-banners-editor">
                <input type="hidden" id="wpb-banner-id" value="<?php echo $is_new ? 0 : intval($banner->id ?? 0); ?>">

                <?php if ($ml_active && !$is_new):
                    $banner_id_int = (int)($banner->id ?? 0);
                    $editor_langs  = [ $ml_primary => ($ml_languages[$ml_primary] ?? $ml_primary) . ' (principale)' ];
                    foreach ($ml_trans as $lc) {
                        if (wpb_banners_get_translation($banner_id_int, $lc)) {
                            $editor_langs[$lc] = $ml_languages[$lc] ?? strtoupper($lc);
                        }
                    }
                    $initial_lang = isset($editor_langs[$qs_lang]) ? $qs_lang : $ml_primary;
                ?>
                <div class="wpb-lang-select-wrap">
                    <label for="wpb-lang-select">
                        <span class="dashicons dashicons-translation" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:3px;color:#2271b1;"></span>
                        Lingua:
                    </label>
                    <select id="wpb-lang-select">
                        <?php foreach ($editor_langs as $lc => $label): ?>
                            <option value="<?php echo esc_attr($lc); ?>"
                                    data-is-primary="<?php echo $lc === $ml_primary ? '1' : '0'; ?>"
                                    <?php selected($initial_lang, $lc); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="wpb-trans-notice" id="wpb-trans-notice"
                     style="display:<?php echo $initial_lang !== $ml_primary ? 'flex' : 'none'; ?>;">
                    <span class="dashicons dashicons-translation" style="font-size:16px;width:16px;height:16px;color:#2271b1;flex-shrink:0;"></span>
                    Stai modificando la versione in <strong id="wpb-trans-notice-lang"><?php echo esc_html($ml_languages[$initial_lang] ?? $initial_lang); ?></strong>.
                    Lo stato attivo è condiviso con la versione principale.
                </div>

                <?php
                if ($initial_lang !== $ml_primary) {
                    $trans_data = wpb_banners_get_translation($banner_id_int, $initial_lang);
                    if ($trans_data) {
                        $banner = $trans_data;
                    }
                }
                endif; ?>

                <div style="display:grid;grid-template-columns:1fr 480px;gap:32px;align-items:start;">

                <!-- ── COLONNA SINISTRA: campi ── -->
                <div>

                <!-- Titolo -->
                <div class="wpb-editor-field">
                    <label for="wpb-banner-title">Titolo <span style="color:#d63638;">*</span></label>
                    <input type="text" id="wpb-banner-title" class="regular-text"
                        value="<?php echo esc_attr($banner->title ?? ''); ?>"
                        placeholder="Es. Spedizione gratuita su tutti gli ordini">
                </div>

                <!-- Link -->
                <div class="wpb-editor-field">
                    <label>Link <span style="font-weight:400;color:#a7aaad;">(opzionale)</span></label>
                    <div style="display:grid;grid-template-columns:1fr 200px;gap:10px;align-items:start;max-width:540px;">
                        <div>
                            <input type="url" id="wpb-banner-link" class="regular-text" style="width:100%;"
                                value="<?php echo esc_attr($banner->link ?? ''); ?>"
                                placeholder="https://www.tuosito.it/">
                            <p class="description" style="margin-top:4px;">URL</p>
                        </div>
                        <div>
                            <select id="wpb-banner-link-target" style="height:30px;width:100%;padding:0 6px;border:1px solid #c3c4c7;border-radius:3px;font-size:13px;">
                                <option value="_self"  <?php selected(($banner->link_target ?? '_self'), '_self'); ?>>Stessa scheda</option>
                                <option value="_blank" <?php selected(($banner->link_target ?? '_self'), '_blank'); ?>>Nuova scheda</option>
                            </select>
                            <p class="description" style="margin-top:4px;">Target</p>
                        </div>
                    </div>
                </div>

                <!-- Salva -->
                <div style="display:flex;align-items:center;gap:12px;padding-top:8px;border-top:1px solid #f0f0f1;margin-top:8px;">
                    <button type="button" class="button button-primary" id="wpb-banner-save-btn">
                        Salva banner
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners')); ?>"
                        class="button">Annulla</a>
                    <span id="wpb-banner-save-msg" style="display:none;font-size:13px;"></span>
                </div>

                </div><!-- fine colonna sinistra -->

                <!-- ── COLONNA DESTRA: anteprima desktop ── -->
                <div style="position:sticky;top:32px;">

                    <div style="display:flex;align-items:center;gap:6px;margin-bottom:14px;">
                        <span style="font-size:12px;font-weight:600;color:#1d2327;">Anteprima</span>
                    </div>

                    <!-- Finta barra browser -->
                    <div style="background:#f0f0f1;border:1px solid #c3c4c7;border-bottom:none;border-radius:6px 6px 0 0;padding:8px 12px;display:flex;align-items:center;gap:6px;">
                        <div style="display:flex;gap:5px;">
                            <span style="width:10px;height:10px;border-radius:50%;background:#ff5f57;display:inline-block;"></span>
                            <span style="width:10px;height:10px;border-radius:50%;background:#febc2e;display:inline-block;"></span>
                            <span style="width:10px;height:10px;border-radius:50%;background:#28c840;display:inline-block;"></span>
                        </div>
                        <div style="flex:1;background:#fff;border:1px solid #c3c4c7;border-radius:3px;height:20px;margin-left:8px;"></div>
                    </div>

                    <!-- Banner marquee -->
                    <div class="wpb-marquee-outer" id="wpb-preview-marquee">
                        <div class="wpb-marquee-track" id="wpb-marquee-track">
                            <?php
                            // Ripetiamo il contenuto per creare l'effetto loop infinito
                            for ($i = 0; $i < 6; $i++):
                            ?>
                            <span class="wpb-marquee-item">
                                <span class="wpb-prev-title"><?php echo esc_html($banner->title ?? 'Titolo del banner'); ?></span>
                                <?php if (!empty($banner->link)): ?>
                                <a class="wpb-marquee-link wpb-prev-link" href="#" onclick="return false;">
                                    <?php echo esc_html($banner->link ?? ''); ?>
                                </a>
                                <?php else: ?>
                                <a class="wpb-marquee-link wpb-prev-link" href="#" onclick="return false;" style="display:none;"></a>
                                <?php endif; ?>
                                <span class="wpb-marquee-sep"></span>
                            </span>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Finta area pagina sotto il banner -->
                    <div style="background:#f9f9f9;border:1px solid #c3c4c7;border-top:none;border-radius:0 0 6px 6px;padding:20px 16px;min-height:80px;">
                        <div style="height:8px;background:#e0e0e0;border-radius:4px;margin-bottom:10px;width:70%;"></div>
                        <div style="height:8px;background:#e0e0e0;border-radius:4px;margin-bottom:10px;width:90%;"></div>
                        <div style="height:8px;background:#e0e0e0;border-radius:4px;width:50%;"></div>
                    </div>

                    <p class="wpb-preview-hint">
                        L'anteprima si aggiorna in tempo reale
                    </p>
                </div><!-- fine colonna destra -->

                </div><!-- fine grid -->
            </div>

        <?php else: ?>
            <!-- ── LISTA ── -->

            <?php if (wpb_tools_can_access()): ?>
            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners')); ?>"
                    class="nav-tab <?php echo $list_tab === 'banners' ? 'nav-tab-active' : ''; ?>">Banner</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners&list_tab=settings')); ?>"
                    class="nav-tab <?php echo $list_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Impostazioni</a>
            </nav>
            <?php endif; ?>

            <?php if ($list_tab === 'settings' && wpb_tools_can_access()): ?>

                <!-- ── TAB IMPOSTAZIONI ── -->
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:24px;max-width:480px;">
                    <h3 style="margin:0 0 4px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#50575e;">Limiti</h3>
                    <p style="margin:0 0 20px;font-size:12px;color:#a7aaad;">Imposta il numero massimo di banner che possono essere creati. Raggiunto il limite, il pulsante "+ Nuovo banner" viene disabilitato.</p>

                    <div style="margin-bottom:20px;">
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;" for="wpb-banner-limit-input">
                            Numero massimo di banner
                        </label>
                        <input type="number" id="wpb-banner-limit-input" min="1" max="999"
                            value="<?php echo intval($banner_limit); ?>"
                            style="width:100px;padding:5px 8px;border:1px solid #c3c4c7;border-radius:3px;font-size:13px;">
                        <p class="description" style="margin-top:4px;">Default: 1. Impostare a un valore alto per rimuovere il limite in pratica.</p>
                    </div>

                    <button type="button" class="button button-primary" id="wpb-save-limit-btn">Salva</button>
                    <span id="wpb-save-limit-msg" style="display:none;margin-left:10px;font-size:13px;color:#00a32a;">✓ Salvato</span>
                </div>

            <?php else: ?>

            <?php if (!empty($active)): ?>
                <div id="wpb-active-banner-notice" style="display:flex;align-items:center;gap:10px;padding:10px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:3px;margin-bottom:20px;font-size:13px;color:#166534;">
                    <span class="dashicons dashicons-yes-alt" style="color:#00a32a;font-size:18px;width:18px;height:18px;"></span>
                    Banner attivo: <strong><?php echo esc_html($active->title); ?></strong>
                </div>
            <?php else: ?>
                <div id="wpb-active-banner-notice" style="display:flex;align-items:center;gap:10px;padding:10px 16px;background:#fcf9e8;border:1px solid #dba617;border-radius:3px;margin-bottom:20px;font-size:13px;color:#6e4e00;">
                    <span class="dashicons dashicons-warning" style="color:#dba617;font-size:18px;width:18px;height:18px;"></span>
                    Nessun banner attivo al momento.
                </div>
            <?php endif; ?>

            <?php if (empty($banners)): ?>
                <div class="wpb-banners-empty">
                    <span class="dashicons dashicons-flag"></span>
                    <p style="font-size:14px;font-weight:600;margin:0 0 6px;">Nessun banner ancora</p>
                    <p style="font-size:13px;margin:0 0 16px;">Crea il tuo primo banner in pochi secondi.</p>
                    <?php if (count($banners) < $banner_limit): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners&edit_id=new')); ?>"
                            class="button button-primary">+ Nuovo banner</a>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <div id="wpb-banners-list">
                    <?php foreach ($banners as $b):
                        $is_active = (int) $b->id === $active_id;
                    ?>
                        <div class="wpb-banner-row" data-id="<?php echo intval($b->id); ?>"
                             style="display:block;padding:0;overflow:hidden;">

                            <!-- Riga principale -->
                            <div style="display:grid;grid-template-columns:1fr auto auto;align-items:center;gap:16px;padding:14px 18px;">

                            <!-- Info -->
                            <div class="wpb-banner-info">
                                <div class="wpb-banner-title"><?php echo esc_html($b->title); ?></div>
                                <div class="wpb-banner-meta">
                                    Creato il <?php echo esc_html(date_i18n('d/m/Y', strtotime($b->created_at))); ?>
                                </div>
                            </div>

                            <!-- Badge stato -->
                            <div>
                                <?php if ($is_active): ?>
                                    <span class="wpb-badge-active">
                                        <span style="width:6px;height:6px;border-radius:50%;background:#00a32a;display:inline-block;"></span>
                                        Attivo
                                    </span>
                                <?php else: ?>
                                    <span class="wpb-badge-inactive">Inattivo</span>
                                <?php endif; ?>
                            </div>

                            <!-- Azioni -->
                            <div class="wpb-banner-actions">
                                <label class="wpb-toggle-active" title="<?php echo $is_active ? 'Disattiva' : 'Attiva'; ?>">
                                    <input type="checkbox" class="wpb-toggle-active-cb"
                                        data-id="<?php echo intval($b->id); ?>"
                                        <?php checked($is_active); ?>>
                                    <span class="wpb-toggle-active-slider"></span>
                                </label>

                                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners&edit_id=' . $b->id)); ?>"
                                    class="button button-small" title="Modifica">
                                    <span class="dashicons dashicons-edit"
                                        style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span>
                                </a>

                                <button type="button" class="button button-small wpb-banner-delete-btn"
                                    data-id="<?php echo intval($b->id); ?>" title="Elimina"
                                    style="color:#d63638;border-color:#d63638;">
                                    <span class="dashicons dashicons-trash"
                                        style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span>
                                </button>
                            </div>

                            </div><!-- fine riga principale -->

                            <?php if ($ml_active): ?>
                            <div class="wpb-banner-langs">
                                <span class="wpb-banner-langs-label">Lingue:</span>

                                <span class="wpb-lang-pill primary" title="Lingua principale">
                                    <span class="dashicons dashicons-yes" style="font-size:12px;width:12px;height:12px;"></span>
                                    <?php echo esc_html($ml_languages[$ml_primary] ?? strtoupper($ml_primary)); ?>
                                </span>

                                <?php foreach ($ml_trans as $lang_code):
                                    $lang_label = $ml_languages[$lang_code] ?? strtoupper($lang_code);
                                    $has_trans  = (bool) wpb_banners_get_translation((int) $b->id, $lang_code);
                                ?>
                                    <?php if ($has_trans): ?>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-banners&edit_id=' . $b->id . '&lang=' . $lang_code)); ?>"
                                           class="wpb-lang-pill translated"
                                           title="Modifica traduzione in <?php echo esc_attr($lang_label); ?>">
                                            <span class="dashicons dashicons-yes" style="font-size:12px;width:12px;height:12px;"></span>
                                            <?php echo esc_html($lang_label); ?>
                                        </a>
                                    <?php else: ?>
                                        <button type="button"
                                                class="wpb-lang-pill missing wpb-lang-init-btn"
                                                data-banner-id="<?php echo intval($b->id); ?>"
                                                data-lang="<?php echo esc_attr($lang_code); ?>"
                                                data-lang-label="<?php echo esc_attr($lang_label); ?>"
                                                title="Inizializza traduzione in <?php echo esc_attr($lang_label); ?>">
                                            <span class="wpb-lang-add">+</span>
                                            <?php echo esc_html($lang_label); ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                        </div><!-- .wpb-banner-row -->
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <?php endif; ?>
    </div>

    <script>
    (function () {
        var nonce       = <?php echo wp_json_encode($nonce); ?>;
        var ajaxUrl     = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var urlEditBase = <?php echo wp_json_encode(admin_url('admin.php?page=wp-barrio-banners&edit_id=')); ?>;

        // Dati multilingua
        var mlActive    = <?php echo wp_json_encode($ml_active); ?>;
        var mlPrimary   = <?php echo wp_json_encode($ml_primary); ?>;
        var mlLanguages = <?php echo wp_json_encode($ml_languages); ?>;
        var currentLang = <?php echo wp_json_encode($initial_lang ?? $ml_primary); ?>;

        /* ════════════════════════════════════════
           MULTILINGUA — select lingua nell'editor
           ════════════════════════════════════════ */
        (function () {
            if (!mlActive) return;

            var langSelect = document.getElementById('wpb-lang-select');
            var notice     = document.getElementById('wpb-trans-notice');
            var noticeLang = document.getElementById('wpb-trans-notice-lang');
            var saveBtn    = document.getElementById('wpb-banner-save-btn');

            if (!langSelect) return;

            function cachePrimaryValues() {
                ['wpb-banner-title', 'wpb-banner-link', 'wpb-banner-link-target'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el && el.dataset.primary === undefined) el.dataset.primary = el.value;
                });
            }

            function loadPrimaryFields() {
                ['wpb-banner-title', 'wpb-banner-link', 'wpb-banner-link-target'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el && el.dataset.primary !== undefined) el.value = el.dataset.primary;
                });
                refreshMarqueeFromFields();
            }

            function fillTranslationFields(data) {
                var title      = document.getElementById('wpb-banner-title');
                var link       = document.getElementById('wpb-banner-link');
                var linkTarget = document.getElementById('wpb-banner-link-target');
                if (title)      title.value      = data.title       || '';
                if (link)       link.value       = data.link        || '';
                if (linkTarget) linkTarget.value = data.link_target || '_self';
                refreshMarqueeFromFields();
            }

            function switchLang(lang, isPrimary) {
                currentLang = lang;
                if (saveBtn) saveBtn.textContent = isPrimary ? 'Salva banner' : 'Salva traduzione';
                if (notice && noticeLang) {
                    if (isPrimary) {
                        notice.style.display = 'none';
                    } else {
                        noticeLang.textContent = mlLanguages[lang] || lang;
                        notice.style.display   = 'flex';
                    }
                }
                if (isPrimary) { loadPrimaryFields(); return; }

                var bannerId = document.getElementById('wpb-banner-id').value;
                if (!bannerId || bannerId === '0') return;

                langSelect.disabled = true;
                fetch(ajaxUrl, {
                    method: 'POST',
                    body: new URLSearchParams({
                        action:    'wpb_banners_get_translation',
                        nonce:     nonce,
                        banner_id: bannerId,
                        lang:      lang,
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (res) { if (res.success) fillTranslationFields(res.data); })
                .catch(function () {})
                .finally(function () { langSelect.disabled = false; });
            }

            langSelect.addEventListener('change', function () {
                var opt = this.options[this.selectedIndex];
                switchLang(this.value, opt.dataset.isPrimary === '1');
            });

            setTimeout(function () {
                var primaryData = <?php echo wp_json_encode([
                    'title'       => $editing->title       ?? '',
                    'link'        => $editing->link        ?? '',
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
                    if (saveBtn) saveBtn.textContent = 'Salva traduzione';
                }
            }, 300);
        })();

        /* ════════════════════════════════════════
           LISTA — inizializza traduzione dal "+"
           ════════════════════════════════════════ */
        document.querySelectorAll('.wpb-lang-init-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var bannerId  = this.dataset.bannerId;
                var lang      = this.dataset.lang;
                var langLabel = this.dataset.langLabel;
                var pill      = this;

                pill.classList.add('loading');
                pill.disabled = true;

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: new URLSearchParams({
                        action:    'wpb_banners_init_translation',
                        nonce:     nonce,
                        banner_id: bannerId,
                        lang:      lang,
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        var link = document.createElement('a');
                        link.href      = urlEditBase + bannerId + '&lang=' + lang;
                        link.className = 'wpb-lang-pill translated';
                        link.title     = 'Modifica traduzione in ' + langLabel;
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
           IMPOSTAZIONI — salva limite banner
           ════════════════════════════════════════ */
        var saveLimitBtn = document.getElementById('wpb-save-limit-btn');
        if (saveLimitBtn) {
            saveLimitBtn.addEventListener('click', function () {
                var btn = this;
                var msg = document.getElementById('wpb-save-limit-msg');
                var val = parseInt(document.getElementById('wpb-banner-limit-input').value, 10) || 1;
                btn.disabled = true;
                btn.textContent = 'Salvataggio…';
                var fd = new URLSearchParams({
                    action:       'wpb_banners_save_limit',
                    nonce:        nonce,
                    banner_limit: val,
                });
                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            msg.style.display = 'inline';
                            setTimeout(function () { msg.style.display = 'none'; }, 3000);
                        } else {
                            alert(res.data || 'Errore.');
                        }
                    })
                    .catch(function () { alert('Errore di rete.'); })
                    .finally(function () {
                        btn.disabled = false;
                        btn.textContent = 'Salva';
                    });
            });
        }

        /* ════════════════════════════════════════
           LISTA — toggle attiva/disattiva
           ════════════════════════════════════════ */
        document.querySelectorAll('.wpb-toggle-active-cb').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var bannerId = this.checked ? parseInt(this.dataset.id, 10) : 0;
                var allCbs   = document.querySelectorAll('.wpb-toggle-active-cb');

                allCbs.forEach(function (c) {
                    c.checked = false;
                    updateRowBadge(c.closest('.wpb-banner-row'), false);
                });
                if (bannerId) {
                    this.checked = true;
                    updateRowBadge(this.closest('.wpb-banner-row'), true);
                }

                var fd = new URLSearchParams({
                    action:    'wpb_banners_set_active',
                    nonce:     nonce,
                    banner_id: bannerId,
                });
                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) {
                            alert(res.data || 'Errore.');
                            location.reload();
                        } else {
                            updateActiveBannerNotice(bannerId);
                        }
                    })
                    .catch(function () { alert('Errore di rete.'); location.reload(); });
            });
        });

        function updateRowBadge(row, isActive) {
            if (!row) return;
            var badge = row.querySelector('.wpb-badge-active, .wpb-badge-inactive');
            if (!badge) return;
            if (isActive) {
                badge.className = 'wpb-badge-active';
                badge.innerHTML = '<span style="width:6px;height:6px;border-radius:50%;background:#00a32a;display:inline-block;"></span> Attivo';
            } else {
                badge.className = 'wpb-badge-inactive';
                badge.textContent = 'Inattivo';
            }
        }

        function updateActiveBannerNotice(activeId) {
            var notice = document.getElementById('wpb-active-banner-notice');
            if (!notice) return;
            if (!activeId) {
                notice.style.background = '#fcf9e8';
                notice.style.border     = '1px solid #dba617';
                notice.style.color      = '#6e4e00';
                notice.innerHTML = '<span class="dashicons dashicons-warning" style="color:#dba617;font-size:18px;width:18px;height:18px;"></span>'
                    + ' Nessun banner attivo al momento.';
                return;
            }
            var row = document.querySelector('.wpb-banner-row[data-id="' + activeId + '"]');
            var title = row ? row.querySelector('.wpb-banner-title').textContent : '';
            notice.style.background = '#f0fdf4';
            notice.style.border     = '1px solid #bbf7d0';
            notice.style.color      = '#166534';
            notice.innerHTML = '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;font-size:18px;width:18px;height:18px;"></span>'
                + ' Banner attivo: <strong>' + title + '</strong>';
        }

        /* ════════════════════════════════════════
           LISTA — elimina
           ════════════════════════════════════════ */
        document.querySelectorAll('.wpb-banner-delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('Eliminare questo banner?')) return;
                var id  = parseInt(this.dataset.id, 10);
                var row = this.closest('.wpb-banner-row');
                var fd  = new URLSearchParams({
                    action:    'wpb_banners_delete',
                    nonce:     nonce,
                    banner_id: id,
                });
                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            row.style.transition = 'opacity .3s';
                            row.style.opacity    = '0';
                            setTimeout(function () { row.remove(); }, 300);
                        } else {
                            alert(res.data || 'Errore.');
                        }
                    })
                    .catch(function () { alert('Errore di rete.'); });
            });
        });

        /* ════════════════════════════════════════
           EDITOR — anteprima live marquee
           ════════════════════════════════════════ */
        function refreshMarqueeFromFields() {
            var titleVal = (document.getElementById('wpb-banner-title') || {}).value || 'Titolo del banner';
            var linkVal  = (document.getElementById('wpb-banner-link')  || {}).value || '';
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
            var linkInput  = document.getElementById('wpb-banner-link');
            if (titleInput) titleInput.addEventListener('input', refreshMarqueeFromFields);
            if (linkInput)  linkInput.addEventListener('input', refreshMarqueeFromFields);
            refreshMarqueeFromFields();
        })();

        /* ════════════════════════════════════════
           EDITOR — salva
           ════════════════════════════════════════ */
        var saveBtn = document.getElementById('wpb-banner-save-btn');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var btn   = this;
                var msg   = document.getElementById('wpb-banner-save-msg');
                var title = document.getElementById('wpb-banner-title').value.trim();

                if (!title) {
                    msg.style.display = 'inline';
                    msg.style.color   = '#d63638';
                    msg.textContent   = '✗ Il titolo è obbligatorio.';
                    return;
                }

                btn.disabled    = true;
                btn.textContent = 'Salvataggio…';
                msg.style.display = 'none';

                var bannerId  = document.getElementById('wpb-banner-id').value;
                var isPrimary = !mlActive || currentLang === mlPrimary;
                var fd;

                if (isPrimary) {
                    fd = new URLSearchParams({
                        action:      'wpb_banners_save',
                        nonce:       nonce,
                        banner_id:   bannerId,
                        title:       title,
                        link:        document.getElementById('wpb-banner-link').value.trim(),
                        link_target: document.getElementById('wpb-banner-link-target').value,
                    });
                } else {
                    if (!bannerId || bannerId === '0') {
                        msg.style.display = 'inline';
                        msg.style.color   = '#d63638';
                        msg.textContent   = '✗ Salva prima il banner in lingua principale.';
                        btn.disabled    = false;
                        btn.textContent = 'Salva traduzione';
                        return;
                    }
                    fd = new URLSearchParams({
                        action:      'wpb_banners_save_translation',
                        nonce:       nonce,
                        banner_id:   bannerId,
                        lang:        currentLang,
                        title:       title,
                        link:        document.getElementById('wpb-banner-link').value.trim(),
                        link_target: document.getElementById('wpb-banner-link-target').value,
                    });
                }

                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            msg.style.display = 'inline';
                            msg.style.color   = '#00a32a';
                            msg.textContent   = isPrimary ? '✓ Banner salvato.' : '✓ Traduzione salvata.';
                            if (isPrimary && bannerId === '0') {
                                setTimeout(function () {
                                    window.location.href = urlEditBase + res.data.id;
                                }, 800);
                            }
                        } else {
                            msg.style.display = 'inline';
                            msg.style.color   = '#d63638';
                            msg.textContent   = '✗ ' + (res.data || 'Errore.');
                        }
                    })
                    .catch(function () {
                        msg.style.display = 'inline';
                        msg.style.color   = '#d63638';
                        msg.textContent   = '✗ Errore di rete.';
                    })
                    .finally(function () {
                        btn.disabled    = false;
                        btn.textContent = isPrimary ? 'Salva banner' : 'Salva traduzione';
                    });
            });
        }

    })();
    </script>
<?php
}
