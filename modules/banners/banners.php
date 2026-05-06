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

define('WPB_BANNERS_VERSION', '1.0.0');
define('WPB_BANNERS_TABLE',   'wpb_banners');

/* ─────────────────────────────────────────
   INSTALL: crea la tabella al primo avvio
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
}

register_activation_hook(WP_ALT_AI_PLUGIN_DIR . 'wp-barrio.php', 'wpb_banners_install');
add_action('plugins_loaded', 'wpb_banners_install');
add_action('admin_init', function () {
    if (!get_option('wpb_banners_db_version')) {
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
   MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    add_submenu_page(
        'wp-barrio',
        'Banners — WP Barrio',
        'Banners',
        'manage_options',
        'wp-barrio-banners',
        'wpb_banners_admin_page'
    );
}, 17);

/* ─────────────────────────────────────────
   AJAX: salva banner (crea o aggiorna)
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_banners_save', function () {
    check_ajax_referer('wpb_banners_nonce', 'nonce');
    if (!current_user_can('manage_options'))
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
    if (!current_user_can('manage_options'))
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
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $id = intval($_POST['banner_id'] ?? 0);
    if (!$id)
        wp_send_json_error('ID non valido.');

    $wpdb->delete($wpdb->prefix . WPB_BANNERS_TABLE, ['id' => $id], ['%d']);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_send_json_success('Banner eliminato.');
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
                        <div class="wpb-banner-row" data-id="<?php echo intval($b->id); ?>">

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
                                <!-- Toggle attiva/disattiva -->
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

                        </div>
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
        (function () {
            var titleInput  = document.getElementById('wpb-banner-title');
            var linkInput   = document.getElementById('wpb-banner-link');

            function refreshMarquee() {
                var titleVal = titleInput ? titleInput.value.trim() || 'Titolo del banner' : '';
                var linkVal  = linkInput  ? linkInput.value.trim()  : '';

                // Aggiorna tutti i .wpb-prev-title nel marquee
                document.querySelectorAll('#wpb-marquee-track .wpb-prev-title').forEach(function (el) {
                    el.textContent = titleVal;
                });

                // Aggiorna / mostra / nasconde i link
                document.querySelectorAll('#wpb-marquee-track .wpb-prev-link').forEach(function (el) {
                    if (linkVal) {
                        el.style.display = '';
                        el.textContent = linkVal;
                    } else {
                        el.style.display = 'none';
                    }
                });
            }

            if (titleInput) titleInput.addEventListener('input', refreshMarquee);
            if (linkInput)  linkInput.addEventListener('input', refreshMarquee);

            // Inizializzazione
            refreshMarquee();
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

                var fd = new URLSearchParams({
                    action:      'wpb_banners_save',
                    nonce:       nonce,
                    banner_id:   document.getElementById('wpb-banner-id').value,
                    title:       title,
                    link:        document.getElementById('wpb-banner-link').value.trim(),
                    link_target: document.getElementById('wpb-banner-link-target').value,
                });

                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            msg.style.display = 'inline';
                            msg.style.color   = '#00a32a';
                            msg.textContent   = '✓ Banner salvato.';
                            if (document.getElementById('wpb-banner-id').value === '0') {
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
                        btn.textContent = 'Salva banner';
                    });
            });
        }

    })();
    </script>
<?php
}
