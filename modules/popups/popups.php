<?php
/**
 * WP Barrio — Popups
 * Gestione di popup configurabili. Solo uno può essere attivo alla volta.
 * Ogni popup ha: titolo, testo, link, immagine.
 * Esposto via GraphQL con la query hbPopup.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('WPB_POPUPS_VERSION', '1.0.0');
define('WPB_POPUPS_TABLE',   'wpb_popups');

/* ─────────────────────────────────────────
   INSTALL: crea la tabella al primo avvio
   ───────────────────────────────────────── */
function wpb_popups_install(): void
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}wpb_popups` (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title      VARCHAR(255)    NOT NULL DEFAULT '',
            content    LONGTEXT        NOT NULL DEFAULT '',
            link       VARCHAR(2048)   NOT NULL DEFAULT '',
            link_title VARCHAR(255)    NOT NULL DEFAULT '',
            link_target VARCHAR(10)    NOT NULL DEFAULT '_self',
            image_url  VARCHAR(2048)   NOT NULL DEFAULT '',
            image_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            active     TINYINT(1)      NOT NULL DEFAULT 0,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};"
    );
}

register_activation_hook(WP_ALT_AI_PLUGIN_DIR . 'wp-barrio.php', 'wpb_popups_install');
add_action('plugins_loaded', 'wpb_popups_install');
add_action('admin_init', function () {
    if (!get_option('wpb_popups_db_version')) {
        wpb_popups_install();
        update_option('wpb_popups_db_version', WPB_POPUPS_VERSION, false);
    }

    // Migrazione: aggiunge le colonne link_title e link_target se mancano
    global $wpdb;
    $table   = $wpdb->prefix . WPB_POPUPS_TABLE;
    $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
    if ($columns && !in_array('link_title', $columns, true)) {
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `link_title` VARCHAR(255) NOT NULL DEFAULT '' AFTER `link`");
    }
    if ($columns && !in_array('link_target', $columns, true)) {
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `link_target` VARCHAR(10) NOT NULL DEFAULT '_self' AFTER `link_title`");
    }
});

/* ─────────────────────────────────────────
   HELPER DB
   ───────────────────────────────────────── */
function wpb_popups_get_all(): array
{
    global $wpdb;
    return $wpdb->get_results(
        "SELECT id, title, active, created_at FROM {$wpdb->prefix}wpb_popups ORDER BY id DESC"
    ) ?: [];
}

function wpb_popups_get(int $id): ?object
{
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wpb_popups WHERE id = %d", $id
    )) ?: null;
}

function wpb_popups_get_active(): ?object
{
    global $wpdb;
    return $wpdb->get_row(
        "SELECT * FROM {$wpdb->prefix}wpb_popups WHERE active = 1 LIMIT 1"
    ) ?: null;
}

/* ─────────────────────────────────────────
   MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    add_submenu_page(
        'wp-barrio',
        'Popups — WP Barrio',
        'Popups',
        'manage_options',
        'wp-barrio-popups',
        'wpb_popups_admin_page'
    );
}, 16);

/* ─────────────────────────────────────────
   ENQUEUE: media uploader solo sulla pagina popups
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'wp-barrio_page_wp-barrio-popups')
        return;
    wp_enqueue_media();
    wp_enqueue_editor();
});

/* ─────────────────────────────────────────
   AJAX: salva popup (crea o aggiorna)
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_popups_save', function () {
    check_ajax_referer('wpb_popups_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $table = $wpdb->prefix . WPB_POPUPS_TABLE;

    $id         = intval($_POST['popup_id'] ?? 0);
    $title      = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $content    = wp_kses_post(wp_unslash($_POST['content'] ?? ''));
    $link       = sanitize_url(wp_unslash($_POST['link'] ?? ''));
    $link_title = sanitize_text_field(wp_unslash($_POST['link_title'] ?? ''));
    $link_target = in_array($_POST['link_target'] ?? '', ['_self', '_blank'], true)
        ? $_POST['link_target']
        : '_self';
    $image_url  = sanitize_url(wp_unslash($_POST['image_url'] ?? ''));
    $image_id   = intval($_POST['image_id'] ?? 0);

    if ($title === '')
        wp_send_json_error('Il titolo è obbligatorio.');

    $data   = compact('title', 'content', 'link', 'link_title', 'link_target', 'image_url', 'image_id');
    $format = ['%s', '%s', '%s', '%s', '%s', '%s', '%d'];

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

    wp_send_json_success(['id' => $id, 'message' => 'Popup salvato.']);
});

/* ─────────────────────────────────────────
   AJAX: imposta popup attivo (deattiva tutti, attiva uno)
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_popups_set_active', function () {
    check_ajax_referer('wpb_popups_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $table = $wpdb->prefix . WPB_POPUPS_TABLE;
    $id    = intval($_POST['popup_id'] ?? 0);

    // Disattiva tutti
    $wpdb->query("UPDATE {$table} SET active = 0");

    // Attiva quello richiesto (se id = 0 vuol dire "nessuno attivo")
    if ($id > 0) {
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
add_action('wp_ajax_wpb_popups_delete', function () {
    check_ajax_referer('wpb_popups_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $id = intval($_POST['popup_id'] ?? 0);
    if (!$id)
        wp_send_json_error('ID non valido.');

    $wpdb->delete($wpdb->prefix . WPB_POPUPS_TABLE, ['id' => $id], ['%d']);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_send_json_success('Popup eliminato.');
});

/* ─────────────────────────────────────────
   AJAX ADMIN: salva limite popup
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_popups_save_limit', function () {
    check_ajax_referer('wpb_popups_nonce', 'nonce');
    if (!wpb_tools_can_access())
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
function wpb_popups_admin_page(): void
{
    $nonce     = wp_create_nonce('wpb_popups_nonce');
    $popups    = wpb_popups_get_all();
    $active    = wpb_popups_get_active();
    $active_id = $active ? (int) $active->id : 0;
    $popup_limit = (int) get_option('wpb_popups_limit', 1);
    $list_tab    = isset($_GET['list_tab']) ? sanitize_key($_GET['list_tab']) : 'popups';

    // Popup in modifica (dalla query string)
    $edit_id = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0;
    $editing = $edit_id ? wpb_popups_get($edit_id) : null;
    ?>
    <style>
        /* ── Toolbar ── */
        .wpb-popups-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        /* ── Card lista ── */
        .wpb-popup-row {
            display: grid;
            grid-template-columns: 56px 1fr auto auto;
            align-items: center;
            gap: 16px;
            padding: 14px 18px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            margin-bottom: 4px;
            transition: box-shadow .15s;
        }
        .wpb-popup-row:hover { box-shadow: 0 1px 4px rgba(0,0,0,.08); }

        .wpb-popup-thumb {
            width: 56px;
            height: 40px;
            object-fit: cover;
            border-radius: 3px;
            background: #f0f0f1;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #c3c4c7;
            flex-shrink: 0;
            overflow: hidden;
        }
        .wpb-popup-thumb img { width: 100%; height: 100%; object-fit: cover; }

        .wpb-popup-info .wpb-popup-title {
            font-size: 13px;
            font-weight: 600;
            color: #1d2327;
            margin-bottom: 2px;
        }
        .wpb-popup-info .wpb-popup-meta {
            font-size: 11px;
            color: #a7aaad;
        }

        .wpb-popup-actions { display: flex; gap: 8px; align-items: center; }

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
        .wpb-popups-editor {
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
        .wpb-editor-field input[type="url"],
        .wpb-editor-field textarea {
            width: 100%;
            max-width: 540px;
        }
        .wpb-editor-field .description {
            font-size: 12px;
            color: #646970;
            margin-top: 4px;
        }

        /* wp_editor dentro il pannello popups */
        .wpb-popups-editor .wp-editor-wrap {
            max-width: 540px;
        }
        .wpb-popups-editor .wp-editor-area {
            width: 100% !important;
        }

        /* Immagine preview */
        #wpb-popup-image-preview {
            margin-top: 10px;
            display: none;
        }
        #wpb-popup-image-preview img {
            max-width: 220px;
            max-height: 140px;
            object-fit: cover;
            border-radius: 3px;
            border: 1px solid #c3c4c7;
            display: block;
        }
        #wpb-popup-image-preview .wpb-remove-image {
            display: inline-block;
            margin-top: 6px;
            font-size: 12px;
            color: #d63638;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
        }

        /* Empty state */
        .wpb-popups-empty {
            text-align: center;
            padding: 48px 20px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            color: #646970;
        }
        .wpb-popups-empty .dashicons {
            font-size: 40px;
            width: 40px;
            height: 40px;
            color: #c3c4c7;
            display: block;
            margin: 0 auto 12px;
        }
    </style>

    <div id="wpb-popups-wrap" class="wrap">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-megaphone"
                style="font-size:22px;width:22px;height:22px;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
            WP Barrio <span style="font-size:15px;font-weight:400;color:#646970;margin-left:4px;">/ Popups</span>
        </h1>

        <?php if (!$editing): ?>
            <?php $at_limit = count($popups) >= $popup_limit; ?>
            <?php if ($at_limit): ?>
                <span class="page-title-action" style="opacity:.5;cursor:not-allowed;"
                    title="Limite di <?php echo intval($popup_limit); ?> popup raggiunto">+ Nuovo popup</span>
            <?php else: ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-popups&edit_id=new')); ?>"
                    class="page-title-action">+ Nuovo popup</a>
            <?php endif; ?>
        <?php endif; ?>

        <hr class="wp-header-end" style="margin-bottom:24px;">

        <?php if ($editing || $edit_id === -1 || isset($_GET['edit_id']) && $_GET['edit_id'] === 'new'): ?>
            <?php
            // Se edit_id = 'new' forziamo editing = null ma mostriamo il form vuoto
            $is_new = ($_GET['edit_id'] ?? '') === 'new';
            $popup  = $is_new ? null : $editing;
            ?>
            <!-- ── EDITOR ── -->
            <div style="margin-bottom:12px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-popups')); ?>"
                    style="font-size:13px;color:#646970;text-decoration:none;">
                    ← Torna alla lista
                </a>
            </div>

            <h2 style="font-size:16px;font-weight:600;margin:0 0 16px;">
                <?php echo $is_new ? 'Nuovo popup' : 'Modifica popup: ' . esc_html($popup->title ?? ''); ?>
            </h2>

            <div class="wpb-popups-editor">
                <input type="hidden" id="wpb-popup-id" value="<?php echo $is_new ? 0 : intval($popup->id ?? 0); ?>">

                <div style="display:grid;grid-template-columns:1fr 480px;gap:32px;align-items:start;">

                <!-- ── COLONNA SINISTRA: campi ── -->
                <div>

                <!-- Titolo -->
                <div class="wpb-editor-field">
                    <label for="wpb-popup-title">Titolo <span style="color:#d63638;">*</span></label>
                    <input type="text" id="wpb-popup-title" class="regular-text"
                        value="<?php echo esc_attr($popup->title ?? ''); ?>"
                        placeholder="Es. Offerta di Benvenuto">
                </div>

                <!-- Testo -->
                <div class="wpb-editor-field">
                    <label>Testo</label>
                    <?php
                    wp_editor(
                        $popup->content ?? '',
                        'wpb-popup-content',
                        [
                            'textarea_name' => 'wpb_popup_content_unused',
                            'textarea_rows' => 8,
                            'media_buttons' => false,
                            'teeny'         => true,
                            'quicktags'     => true,
                            'tinymce'       => [
                                'toolbar1' => 'bold,italic,underline,strikethrough,|,bullist,numlist,|,link,unlink,|,undo,redo',
                                'toolbar2' => '',
                            ],
                        ]
                    );
                    ?>
                </div>

                <!-- Link CTA -->
                <div class="wpb-editor-field">
                    <label>Link CTA <span style="font-weight:400;color:#a7aaad;">(opzionale)</span></label>
                    <div style="display:grid;grid-template-columns: repeat(2, minmax(0, 1fr));gap:10px;align-items:start;max-width:540px;">
                        <div style="grid-column: 1 / -1;">
                            <input type="url" id="wpb-popup-link" class="regular-text" style="width:100%;"
                                value="<?php echo esc_attr($popup->link ?? ''); ?>"
                                placeholder="https://esempio.com/offerta">
                            <p class="description" style="margin-top:4px;">URL</p>
                        </div>
                        <div>
                            <input type="text" id="wpb-popup-link-title" class="regular-text" style="width:100%;"
                                value="<?php echo esc_attr($popup->link_title ?? ''); ?>"
                                placeholder="Scopri di più">
                            <p class="description" style="margin-top:4px;">Testo pulsante</p>
                        </div>
                        <div>
                            <select id="wpb-popup-link-target" style="height:30px;width:100%;padding:0 6px;border:1px solid #c3c4c7;border-radius:3px;font-size:13px;">
                                <option value="_self"  <?php selected(($popup->link_target ?? '_self'), '_self'); ?>>Stessa scheda</option>
                                <option value="_blank" <?php selected(($popup->link_target ?? '_self'), '_blank'); ?>>Nuova scheda</option>
                            </select>
                            <p class="description" style="margin-top:4px;">Target</p>
                        </div>
                    </div>
                </div>

                <!-- Immagine -->
                <div class="wpb-editor-field">
                    <label>Immagine <span style="font-weight:400;color:#a7aaad;">(opzionale)</span></label>
                    <input type="hidden" id="wpb-popup-image-id"
                        value="<?php echo intval($popup->image_id ?? 0); ?>">
                    <input type="hidden" id="wpb-popup-image-url"
                        value="<?php echo esc_attr($popup->image_url ?? ''); ?>">
                    <div>
                        <button type="button" class="button" id="wpb-popup-image-btn">
                            <span class="dashicons dashicons-format-image"
                                style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
                            Scegli immagine
                        </button>
                    </div>
                    <div id="wpb-popup-image-preview"
                        style="<?php echo !empty($popup->image_url) ? 'display:block;' : ''; ?>">
                        <img id="wpb-popup-image-thumb"
                            src="<?php echo esc_attr($popup->image_url ?? ''); ?>"
                            alt="Anteprima immagine popup">
                        <button type="button" class="wpb-remove-image" id="wpb-popup-image-remove">
                            ✕ Rimuovi immagine
                        </button>
                    </div>
                </div>

                <!-- Salva -->
                <div style="display:flex;align-items:center;gap:12px;padding-top:8px;border-top:1px solid #f0f0f1;margin-top:8px;">
                    <button type="button" class="button button-primary" id="wpb-popup-save-btn">
                        Salva popup
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-popups')); ?>"
                        class="button">Annulla</a>
                    <span id="wpb-popup-save-msg" style="display:none;font-size:13px;"></span>
                </div>

                </div><!-- fine colonna sinistra -->

                <!-- ── COLONNA DESTRA: anteprima ── -->
                <div style="position:sticky;top:32px;">

                    <!-- Tab switcher -->
                    <div style="display:flex;gap:0;margin-bottom:14px;border:1px solid #c3c4c7;border-radius:4px;overflow:hidden;width:fit-content;">
                        <button type="button" id="wpb-tab-desktop"
                            onclick="wpbSwitchPreview('desktop')"
                            style="display:flex;align-items:center;gap:5px;padding:6px 14px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:#2271b1;color:#fff;">
                            <span class="dashicons dashicons-desktop" style="font-size:13px;width:13px;height:13px;"></span> Desktop
                        </button>
                        <button type="button" id="wpb-tab-mobile"
                            onclick="wpbSwitchPreview('mobile')"
                            style="display:flex;align-items:center;gap:5px;padding:6px 14px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:#f6f7f7;color:#646970;border-left:1px solid #c3c4c7;">
                            <span class="dashicons dashicons-smartphone" style="font-size:13px;width:13px;height:13px;"></span> Mobile
                        </button>
                    </div>

                    <!-- ── DESKTOP preview ── -->
                    <div id="wpb-preview-desktop">
                        <div class="wpb-preview-card" style="
                            position:relative;
                            background:#fff;
                            border-radius:16px;
                            box-shadow:0 8px 40px rgba(0,0,0,.18);
                            overflow:hidden;
                            display:flex;
                            flex-direction:row;
                            min-height:200px;
                            font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
                        ">
                            <!-- Immagine sinistra -->
                            <div class="wpb-prev-img-d" style="
                                display:<?php echo !empty($popup->image_url) ? 'block' : 'none'; ?>;
                                width:45%;
                                flex-shrink:0;
                                background:#f0f0f1;
                                overflow:hidden;
                            ">
                                <img class="wpb-prev-img" src="<?php echo esc_attr($popup->image_url ?? ''); ?>"
                                    style="width:100%;height:100%;object-fit:cover;display:block;" alt="">
                            </div>

                            <!-- Testi destra -->
                            <div style="padding:28px 28px 24px;flex:1;min-width:0;">
                                <!-- Chiudi decorativo -->
                                <div style="position:absolute;top:14px;right:18px;font-size:22px;color:#888;line-height:1;pointer-events:none;font-weight:300;">✕</div>
                                <div class="wpb-prev-title" style="font-size:18px;font-weight:600;color:#111;margin-bottom:10px;line-height:1.3;">
                                    <?php echo esc_html($popup->title ?? ''); ?>
                                </div>
                                <div class="wpb-prev-content" style="font-size:14px;color:#444;line-height:1.6;margin-bottom:16px;">
                                    <?php echo wp_kses_post($popup->content ?? ''); ?>
                                </div>
                                <div class="wpb-prev-link-wrap" style="display:<?php echo !empty($popup->link) ? 'block' : 'none'; ?>;">
                                    <a class="wpb-prev-link" href="#" onclick="return false;"
                                        style="font-size:14px;color:#111;text-decoration:underline;text-underline-offset:3px;">
                                        <?php echo esc_html($popup->link_title ?: ($popup->link ?? '')); ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── MOBILE preview ── -->
                    <div id="wpb-preview-mobile" style="display:none;">
                        <!-- Cornice telefono -->
                        <div style="
                            width:260px;
                            margin:0 auto;
                            border:3px solid #1d2327;
                            border-radius:28px;
                            padding:10px;
                            background:#1d2327;
                            box-shadow:0 8px 32px rgba(0,0,0,.25);
                        ">
                            <!-- Notch -->
                            <div style="width:60px;height:6px;background:#444;border-radius:3px;margin:0 auto 8px;"></div>
                            <!-- Screen -->
                            <div style="background:#f0f0f1;border-radius:18px;overflow:hidden;min-height:340px;">
                                <!-- Overlay scuro semitrasparente simulato -->
                                <div style="background:#fff;border-radius:18px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">

                                    <!-- Card popup mobile -->
                                    <div class="wpb-preview-card" style="
                                        position:relative;
                                        background:#fff;
                                        border-radius:14px;
                                        box-shadow:0 4px 20px rgba(0,0,0,.15);
                                        overflow:hidden;
                                        margin:16px;
                                    ">
                                        <!-- Immagine sopra -->
                                        <div class="wpb-prev-img-m" style="
                                            display:<?php echo !empty($popup->image_url) ? 'block' : 'none'; ?>;
                                            height:130px;
                                            background:#f0f0f1;
                                            overflow:hidden;
                                        ">
                                            <img class="wpb-prev-img" src="<?php echo esc_attr($popup->image_url ?? ''); ?>"
                                                style="width:100%;height:100%;object-fit:cover;display:block;" alt="">
                                        </div>

                                        <!-- Testi sotto -->
                                        <div style="padding:14px 14px 12px;">
                                            <!-- Chiudi decorativo -->
                                            <div style="position:absolute;top:10px;right:12px;font-size:16px;color:#888;line-height:1;pointer-events:none;font-weight:300;">✕</div>
                                            <div class="wpb-prev-title" style="font-size:13px;font-weight:600;color:#111;margin-bottom:6px;line-height:1.3;padding-right:16px;">
                                                <?php echo esc_html($popup->title ?? ''); ?>
                                            </div>
                                            <div class="wpb-prev-content" style="font-size:11px;color:#444;line-height:1.5;margin-bottom:10px;">
                                                <?php echo wp_kses_post($popup->content ?? ''); ?>
                                            </div>
                                            <div class="wpb-prev-link-wrap" style="display:<?php echo !empty($popup->link) ? 'block' : 'none'; ?>;">
                                                <a class="wpb-prev-link" href="#" onclick="return false;"
                                                    style="font-size:11px;color:#111;text-decoration:underline;text-underline-offset:2px;">
                                                    <?php echo esc_html($popup->link_title ?: ($popup->link ?? '')); ?>
                                                </a>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>
                            <!-- Home bar -->
                            <div style="width:80px;height:4px;background:#555;border-radius:2px;margin:8px auto 0;"></div>
                        </div>
                    </div>

                    <p style="font-size:11px;color:#a7aaad;margin-top:12px;text-align:center;">
                        L'anteprima si aggiorna in tempo reale
                    </p>
                </div><!-- fine colonna destra -->

                </div><!-- fine grid -->
            </div>

        <?php else: ?>
            <!-- ── LISTA ── -->

            <!-- Nav tabs (Impostazioni visibile solo a wpb_tools_can_access) -->
            <?php if (wpb_tools_can_access()): ?>
            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-popups')); ?>"
                    class="nav-tab <?php echo $list_tab === 'popups' ? 'nav-tab-active' : ''; ?>">Popup</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-popups&list_tab=settings')); ?>"
                    class="nav-tab <?php echo $list_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Impostazioni</a>
            </nav>
            <?php endif; ?>

            <?php if ($list_tab === 'settings' && wpb_tools_can_access()): ?>

                <!-- ── TAB IMPOSTAZIONI ── -->
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:24px;max-width:480px;">
                    <h3 style="margin:0 0 4px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#50575e;">Limiti</h3>
                    <p style="margin:0 0 20px;font-size:12px;color:#a7aaad;">Imposta il numero massimo di popup che possono essere creati. Raggiungo il limite, il pulsante "+ Nuovo popup" viene disabilitato.</p>

                    <div style="margin-bottom:20px;">
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;" for="wpb-popup-limit-input">
                            Numero massimo di popup
                        </label>
                        <input type="number" id="wpb-popup-limit-input" min="1" max="999"
                            value="<?php echo intval($popup_limit); ?>"
                            style="width:100px;padding:5px 8px;border:1px solid #c3c4c7;border-radius:3px;font-size:13px;">
                        <p class="description" style="margin-top:4px;">Default: 1. Impostare a un valore alto per rimuovere il limite in pratica.</p>
                    </div>

                    <button type="button" class="button button-primary" id="wpb-save-limit-btn">Salva</button>
                    <span id="wpb-save-limit-msg" style="display:none;margin-left:10px;font-size:13px;color:#00a32a;">✓ Salvato</span>
                </div>

            <?php else: ?>

            <?php if (!empty($active)): ?>
                <div id="wpb-active-banner" style="display:flex;align-items:center;gap:10px;padding:10px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:3px;margin-bottom:20px;font-size:13px;color:#166534;">
                    <span class="dashicons dashicons-yes-alt" style="color:#00a32a;font-size:18px;width:18px;height:18px;"></span>
                    Popup attivo: <strong><?php echo esc_html($active->title); ?></strong>
                </div>
            <?php else: ?>
                <div id="wpb-active-banner" style="display:flex;align-items:center;gap:10px;padding:10px 16px;background:#fcf9e8;border:1px solid #dba617;border-radius:3px;margin-bottom:20px;font-size:13px;color:#6e4e00;">
                    <span class="dashicons dashicons-warning" style="color:#dba617;font-size:18px;width:18px;height:18px;"></span>
                    Nessun popup attivo al momento.
                </div>
            <?php endif; ?>

            <?php if (empty($popups)): ?>
                <div class="wpb-popups-empty">
                    <span class="dashicons dashicons-megaphone"></span>
                    <p style="font-size:14px;font-weight:600;margin:0 0 6px;">Nessun popup ancora</p>
                    <p style="font-size:13px;margin:0 0 16px;">Crea il tuo primo popup in pochi secondi.</p>
                    <?php if (count($popups) < $popup_limit): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-popups&edit_id=new')); ?>"
                            class="button button-primary">+ Nuovo popup</a>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <div id="wpb-popups-list">
                    <?php foreach ($popups as $p):
                        $is_active = (int) $p->id === $active_id;
                    ?>
                        <div class="wpb-popup-row" data-id="<?php echo intval($p->id); ?>">

                            <!-- Thumb -->
                            <div class="wpb-popup-thumb">
                                <?php
                                $full = wpb_popups_get($p->id);
                                if (!empty($full->image_url)):
                                ?>
                                    <img src="<?php echo esc_attr($full->image_url); ?>" alt="">
                                <?php else: ?>
                                    <span class="dashicons dashicons-format-image"
                                        style="font-size:20px;width:20px;height:20px;color:#c3c4c7;margin:auto;"></span>
                                <?php endif; ?>
                            </div>

                            <!-- Info -->
                            <div class="wpb-popup-info">
                                <div class="wpb-popup-title"><?php echo esc_html($p->title); ?></div>
                                <div class="wpb-popup-meta">
                                    Creato il <?php echo esc_html(date_i18n('d/m/Y', strtotime($p->created_at))); ?>
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
                            <div class="wpb-popup-actions">
                                <!-- Toggle attiva/disattiva -->
                                <label class="wpb-toggle-active" title="<?php echo $is_active ? 'Disattiva' : 'Attiva'; ?>">
                                    <input type="checkbox" class="wpb-toggle-active-cb"
                                        data-id="<?php echo intval($p->id); ?>"
                                        <?php checked($is_active); ?>>
                                    <span class="wpb-toggle-active-slider"></span>
                                </label>

                                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-popups&edit_id=' . $p->id)); ?>"
                                    class="button button-small" title="Modifica">
                                    <span class="dashicons dashicons-edit"
                                        style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span>
                                </a>

                                <button type="button" class="button button-small wpb-popup-delete-btn"
                                    data-id="<?php echo intval($p->id); ?>" title="Elimina"
                                    style="color:#d63638;border-color:#d63638;">
                                    <span class="dashicons dashicons-trash"
                                        style="font-size:14px;width:14px;height:14px;vertical-align:middle;"></span>
                                </button>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?> <!-- fine lista popup -->

        <?php endif; ?> <!-- fine if editor/lista -->
        
        <?php endif; ?>
    </div>

    <script>
    /* ════════════════════════════════════════
       Switch anteprima desktop/mobile — deve essere globale
       perché chiamata dagli onclick inline degli elementi HTML
       ════════════════════════════════════════ */
    function wpbSwitchPreview(mode) {
        var dPane = document.getElementById('wpb-preview-desktop');
        var mPane = document.getElementById('wpb-preview-mobile');
        var dTab  = document.getElementById('wpb-tab-desktop');
        var mTab  = document.getElementById('wpb-tab-mobile');
        if (!dPane || !mPane) return;
        var isDesktop = mode === 'desktop';
        dPane.style.display = isDesktop ? '' : 'none';
        mPane.style.display = isDesktop ? 'none' : '';
        dTab.style.background = isDesktop ? '#2271b1' : '#f6f7f7';
        dTab.style.color      = isDesktop ? '#fff' : '#646970';
        mTab.style.background = isDesktop ? '#f6f7f7' : '#2271b1';
        mTab.style.color      = isDesktop ? '#646970' : '#fff';
    }

    (function () {
        var nonce      = <?php echo wp_json_encode($nonce); ?>;
        var ajaxUrl    = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var urlList    = <?php echo wp_json_encode(admin_url('admin.php?page=wp-barrio-popups')); ?>;
        var urlNew     = <?php echo wp_json_encode(admin_url('admin.php?page=wp-barrio-popups&edit_id=new')); ?>;
        var urlEditBase = <?php echo wp_json_encode(admin_url('admin.php?page=wp-barrio-popups&edit_id=')); ?>;

        /* ════════════════════════════════════════
           IMPOSTAZIONI — salva limite popup
           ════════════════════════════════════════ */
        var saveLimitBtn = document.getElementById('wpb-save-limit-btn');
        if (saveLimitBtn) {
            saveLimitBtn.addEventListener('click', function () {
                var btn = this;
                var msg = document.getElementById('wpb-save-limit-msg');
                var val = parseInt(document.getElementById('wpb-popup-limit-input').value, 10) || 1;
                btn.disabled = true;
                btn.textContent = 'Salvataggio…';
                var fd = new URLSearchParams({
                    action:       'wpb_popups_save_limit',
                    nonce:        nonce,
                    popup_limit:  val,
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
                var popupId = this.checked ? parseInt(this.dataset.id, 10) : 0;
                var allCbs  = document.querySelectorAll('.wpb-toggle-active-cb');

                // UI ottimistica: disattiva tutti, attiva solo quello cliccato
                allCbs.forEach(function (c) {
                    c.checked = false;
                    updateRowBadge(c.closest('.wpb-popup-row'), false);
                });
                if (popupId) {
                    this.checked = true;
                    updateRowBadge(this.closest('.wpb-popup-row'), true);
                }

                var fd = new URLSearchParams({
                    action:    'wpb_popups_set_active',
                    nonce:     nonce,
                    popup_id:  popupId,
                });
                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) {
                            alert(res.data || 'Errore.');
                            location.reload();
                        } else {
                            // Aggiorna il banner in cima senza ricaricare la pagina
                            updateActiveBanner(popupId);
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

        function updateActiveBanner(activeId) {
            var banner = document.getElementById('wpb-active-banner');
            if (!banner) return;
            if (!activeId) {
                banner.style.background   = '#fcf9e8';
                banner.style.border       = '1px solid #dba617';
                banner.style.color        = '#6e4e00';
                banner.innerHTML = '<span class="dashicons dashicons-warning" style="color:#dba617;font-size:18px;width:18px;height:18px;"></span>'
                    + ' Nessun popup attivo al momento.';
                return;
            }
            // Trova il titolo del popup appena attivato
            var row = document.querySelector('.wpb-popup-row[data-id="' + activeId + '"]');
            var title = row ? row.querySelector('.wpb-popup-title').textContent : '';
            banner.style.background   = '#f0fdf4';
            banner.style.border       = '1px solid #bbf7d0';
            banner.style.color        = '#166534';
            banner.innerHTML = '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;font-size:18px;width:18px;height:18px;"></span>'
                + ' Popup attivo: <strong>' + title + '</strong>';
        }

        /* ════════════════════════════════════════
           LISTA — elimina
           ════════════════════════════════════════ */
        document.querySelectorAll('.wpb-popup-delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!confirm('Eliminare questo popup?')) return;
                var id  = parseInt(this.dataset.id, 10);
                var row = this.closest('.wpb-popup-row');
                var fd  = new URLSearchParams({
                    action:   'wpb_popups_delete',
                    nonce:    nonce,
                    popup_id: id,
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
           EDITOR — anteprima live
           Aggiorna tutte le card (.wpb-preview-card) tramite classi condivise
           ════════════════════════════════════════ */
        (function () {
            // Aggiorna tutti gli elementi con la classe data in tutti e due i layout
            function setAll(cls, fn) {
                document.querySelectorAll('.' + cls).forEach(fn);
            }

            // Titolo
            var titleInput = document.getElementById('wpb-popup-title');
            if (titleInput) {
                titleInput.addEventListener('input', function () {
                    setAll('wpb-prev-title', function (el) { el.textContent = titleInput.value; });
                });
            }

            // Contenuto TinyMCE — polling 400ms
            setInterval(function () {
                var content = '';
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('wpb-popup-content')) {
                    content = tinyMCE.get('wpb-popup-content').getContent();
                } else {
                    var ta = document.getElementById('wpb-popup-content');
                    if (ta) content = ta.value;
                }
                setAll('wpb-prev-content', function (el) { el.innerHTML = content; });
            }, 400);

            // Link
            function updateLink() {
                var url   = (document.getElementById('wpb-popup-link')       || {}).value || '';
                var label = (document.getElementById('wpb-popup-link-title') || {}).value || '';
                setAll('wpb-prev-link-wrap', function (el) { el.style.display = url ? 'block' : 'none'; });
                setAll('wpb-prev-link',      function (el) { el.textContent = label || url; });
            }
            ['wpb-popup-link', 'wpb-popup-link-title'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.addEventListener('input', updateLink);
            });

            // Immagine
            document.addEventListener('wpb:image:changed', function (e) {
                var url = e.detail && e.detail.url ? e.detail.url : '';
                // Desktop: pannello laterale con classe wpb-prev-img-d
                document.querySelectorAll('.wpb-prev-img-d').forEach(function (wrap) {
                    wrap.style.display = url ? 'block' : 'none';
                });
                // Mobile: pannello sopra con classe wpb-prev-img-m
                document.querySelectorAll('.wpb-prev-img-m').forEach(function (wrap) {
                    wrap.style.display = url ? 'block' : 'none';
                });
                // Aggiorna src di tutte le img
                document.querySelectorAll('.wpb-prev-img').forEach(function (img) {
                    img.src = url;
                });
            });
        })();

        /* ════════════════════════════════════════
           EDITOR — media uploader
           ════════════════════════════════════════ */
        var imageBtn = document.getElementById('wpb-popup-image-btn');
        if (imageBtn) {
            var mediaFrame = null;

            imageBtn.addEventListener('click', function () {
                if (mediaFrame) { mediaFrame.open(); return; }

                mediaFrame = wp.media({
                    title:    'Seleziona immagine popup',
                    button:   { text: 'Usa questa immagine' },
                    multiple: false,
                    library:  { type: 'image' },
                });

                mediaFrame.on('select', function () {
                    var attachment = mediaFrame.state().get('selection').first().toJSON();
                    document.getElementById('wpb-popup-image-id').value  = attachment.id;
                    document.getElementById('wpb-popup-image-url').value = attachment.url;
                    document.getElementById('wpb-popup-image-thumb').src = attachment.url;
                    document.getElementById('wpb-popup-image-preview').style.display = 'block';
                    document.dispatchEvent(new CustomEvent('wpb:image:changed', { detail: { url: attachment.url } }));
                });

                mediaFrame.open();
            });

            var removeBtn = document.getElementById('wpb-popup-image-remove');
            if (removeBtn) {
                removeBtn.addEventListener('click', function () {
                    document.getElementById('wpb-popup-image-id').value  = 0;
                    document.getElementById('wpb-popup-image-url').value = '';
                    document.getElementById('wpb-popup-image-thumb').src = '';
                    document.getElementById('wpb-popup-image-preview').style.display = 'none';
                    if (mediaFrame) { mediaFrame = null; }
                    document.dispatchEvent(new CustomEvent('wpb:image:changed', { detail: { url: '' } }));
                });
            }
        }

        /* ════════════════════════════════════════
           EDITOR — salva
           ════════════════════════════════════════ */
        var saveBtn = document.getElementById('wpb-popup-save-btn');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                var btn = this;
                var msg = document.getElementById('wpb-popup-save-msg');
                var title = document.getElementById('wpb-popup-title').value.trim();

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
                    action:      'wpb_popups_save',
                    nonce:       nonce,
                    popup_id:    document.getElementById('wpb-popup-id').value,
                    title:       title,
                    content:     (typeof tinyMCE !== 'undefined' && tinyMCE.get('wpb-popup-content'))
                                     ? tinyMCE.get('wpb-popup-content').getContent()
                                     : (document.getElementById('wpb-popup-content') ? document.getElementById('wpb-popup-content').value : ''),
                    link:        document.getElementById('wpb-popup-link').value.trim(),
                    link_title:  document.getElementById('wpb-popup-link-title').value.trim(),
                    link_target: document.getElementById('wpb-popup-link-target').value,
                    image_url:   document.getElementById('wpb-popup-image-url').value,
                    image_id:    document.getElementById('wpb-popup-image-id').value,
                });

                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            msg.style.display = 'inline';
                            msg.style.color   = '#00a32a';
                            msg.textContent   = '✓ Popup salvato.';
                            // Se era nuovo, redirect all'editor con il nuovo ID
                            if (document.getElementById('wpb-popup-id').value === '0') {
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
                        btn.textContent = 'Salva popup';
                    });
            });
        }

    })();
    </script>
<?php
}
