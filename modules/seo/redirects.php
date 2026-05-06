<?php
/**
 * WP Barrio — SEO / Redirects
 * Gestione redirect 301/302/307/410 lato headless.
 * I redirect vengono esportati via GraphQL come URI relativi
 * e gestiti dal frontend (Next.js, Nuxt, ecc.).
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

if (!defined('WPB_REDIRECTS_OPTION'))
    define('WPB_REDIRECTS_OPTION', 'wpb_redirects');

/* ─────────────────────────────────────────
   MENU
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!wpb_tools_module_enabled('seo'))
        return;

    add_submenu_page(
        'wp-barrio',
        'Redirects — WP Barrio',
        'Redirects',
        'manage_options',
        'wp-barrio-redirects',
        'wpb_redirects_admin_page'
    );
}, 14);

add_action('admin_head', function () {
    echo '<style>#adminmenu a[href="admin.php?page=wp-barrio-redirects"]{display:none!important}</style>';
});

add_filter('submenu_file', function ($submenu_file) {
    if (isset($_GET['page']) && $_GET['page'] === 'wp-barrio-redirects')
        return 'wp-barrio-seo';
    return $submenu_file;
});

/* ─────────────────────────────────────────
   HELPER: legge tutti i redirect ordinati
   ───────────────────────────────────────── */
function wpb_redirects_get_all(): array
{
    $rows = get_option(WPB_REDIRECTS_OPTION, []);
    if (!is_array($rows)) return [];

    // Ordina per from_uri alfabetico
    usort($rows, fn($a, $b) => strcmp($a['from'] ?? '', $b['from'] ?? ''));
    return $rows;
}

/* ─────────────────────────────────────────
   HELPER: normalizza URI (sempre /path)
   ───────────────────────────────────────── */
function wpb_redirects_normalize_uri(string $input): string
{
    $input = trim($input);

    // Se è un URL assoluto estrai solo il path
    if (preg_match('#^https?://#i', $input)) {
        $parsed = wp_parse_url($input);
        $input  = ($parsed['path'] ?? '/');
        if (!empty($parsed['query']))  $input .= '?' . $parsed['query'];
        if (!empty($parsed['fragment'])) $input .= '#' . $parsed['fragment'];
    }

    // Assicura che inizi con /
    if ($input !== '' && $input[0] !== '/') {
        $input = '/' . $input;
    }

    return $input ?: '/';
}

/* ─────────────────────────────────────────
   AJAX: salva un singolo redirect (add/edit)
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_redirect_save', function () {
    check_ajax_referer('wpb_redirects_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

    $id   = sanitize_text_field($_POST['id']   ?? '');
    $from = wpb_redirects_normalize_uri(sanitize_text_field($_POST['from'] ?? ''));
    $to   = wpb_redirects_normalize_uri(sanitize_text_field($_POST['to']   ?? ''));
    $code = (int) ($_POST['code'] ?? 301);
    $note = sanitize_text_field($_POST['note'] ?? '');

    if (empty($from) || empty($to))
        wp_send_json_error('from e to sono obbligatori');
    if (!in_array($code, [301, 302, 307, 308, 410], true))
        wp_send_json_error('Codice non valido');

    $rows = get_option(WPB_REDIRECTS_OPTION, []);
    if (!is_array($rows)) $rows = [];

    if ($id) {
        // Edit
        foreach ($rows as &$row) {
            if (($row['id'] ?? '') === $id) {
                $row = ['id' => $id, 'from' => $from, 'to' => $to, 'code' => $code, 'note' => $note];
                break;
            }
        }
        unset($row);
    } else {
        // Add
        // Controlla duplicato su "from"
        foreach ($rows as $row) {
            if (($row['from'] ?? '') === $from)
                wp_send_json_error('Esiste già un redirect per ' . esc_html($from));
        }
        $rows[] = [
            'id'   => wp_generate_uuid4(),
            'from' => $from,
            'to'   => $to,
            'code' => $code,
            'note' => $note,
        ];
    }

    update_option(WPB_REDIRECTS_OPTION, $rows, false);
    wp_send_json_success(['rows' => wpb_redirects_get_all()]);
});

/* ─────────────────────────────────────────
   AJAX: elimina un redirect
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_redirect_delete', function () {
    check_ajax_referer('wpb_redirects_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized', 403);

    $id   = sanitize_text_field($_POST['id'] ?? '');
    $rows = get_option(WPB_REDIRECTS_OPTION, []);
    if (!is_array($rows)) $rows = [];

    $rows = array_values(array_filter($rows, fn($r) => ($r['id'] ?? '') !== $id));
    update_option(WPB_REDIRECTS_OPTION, $rows, false);
    wp_send_json_success(['rows' => wpb_redirects_get_all()]);
});

/* ─────────────────────────────────────────
   RILEVAMENTO CAMBIO SLUG — QUICK EDIT
   WordPress invia action=inline-save che chiama
   wp_ajax_inline_save → edit_post → post_updated.
   L'hook post_updated già gestisce il transient,
   ma il polling JS parte troppo presto. Usiamo
   un hook aggiuntivo su save_post per segnalare
   esplicitamente che è una inline-save.
   ───────────────────────────────────────── */
/* ─────────────────────────────────────────
   HELPER: aggiunge un redirect in modo sicuro
   evitando loop e conflitti con quelli esistenti
   ───────────────────────────────────────── */
function wpb_redirects_add_auto(string $from, string $to): void
{
    if (empty($from) || empty($to) || $from === $to) return;

    $rows = get_option(WPB_REDIRECTS_OPTION, []);
    if (!is_array($rows)) $rows = [];

    // 0. Pulizia preventiva: se $to è un URI che era già una "from"
    //    in un redirect esistente, quel redirect è ora obsoleto
    //    (la risorsa è tornata a esistere a quell'indirizzo)
    $rows = wpb_redirects_cleanup_for_uri($to, $rows);

    // 1. Non aggiungere se esiste già lo stesso "from"
    foreach ($rows as $row) {
        if (($row['from'] ?? '') === $from) return;
    }

    // 2. Rileva loop transitivi
    if (wpb_redirects_would_loop($from, $to, $rows)) return;

    // 3. Accorcia catene esistenti: A→B→C diventa A→C
    foreach ($rows as &$row) {
        if (($row['to'] ?? '') === $from) {
            $row['to'] = $to;
        }
    }
    unset($row);

    // 4. Aggiunge il nuovo redirect
    $rows[] = [
        'id'   => wp_generate_uuid4(),
        'from' => $from,
        'to'   => $to,
        'code' => 301,
        'note' => 'Aggiunto automaticamente al cambio slug',
    ];

    update_option(WPB_REDIRECTS_OPTION, $rows, false);
}

/* ─────────────────────────────────────────
   HELPER: rimuove i redirect obsoleti per un URI
   che ora esiste di nuovo come risorsa.

   Esempio: /chi-siamo → /about viene creato.
   Poi lo slug torna a /chi-siamo: il nuovo URI
   attivo è /chi-siamo, quindi il redirect
   /chi-siamo → /about va rimosso.

   Logica: se $uri compare come "from" in un
   redirect, quella risorsa è tornata attiva
   a quell'indirizzo → il redirect è obsoleto.
   ───────────────────────────────────────── */
function wpb_redirects_cleanup_for_uri(string $uri, array $rows): array
{
    if (empty($uri)) return $rows;

    $rows = array_values(array_filter($rows, fn($r) => ($r['from'] ?? '') !== $uri));

    update_option(WPB_REDIRECTS_OPTION, $rows, false);
    return $rows;
}

/* ─────────────────────────────────────────
   HELPER: verifica che from→to non crei loop
   ───────────────────────────────────────── */
function wpb_redirects_would_loop(string $from, string $to, array $rows): bool
{
    $map = [];
    foreach ($rows as $row) {
        if (!empty($row['from']) && !empty($row['to'])) {
            $map[$row['from']] = $row['to'];
        }
    }
    $map[$from] = $to;

    $current = $to;
    $visited = [$from => true];
    $steps   = 0;

    while ($steps++ < 20) {
        if ($current === $from)       return true;
        if (isset($visited[$current])) return true;
        $visited[$current] = true;
        if (!isset($map[$current]))   break;
        $current = $map[$current];
    }

    return false;
}

/* ─────────────────────────────────────────
   AUTO-REDIRECT — QUICK EDIT (inline-save)
   ───────────────────────────────────────── */
add_action('wp_ajax_inline-save', function () {
    $post_id = (int)($_POST['post_ID'] ?? 0);
    if (!$post_id) return;
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return;
    $uri = wp_make_link_relative(get_permalink($post_id) ?: '');
    if ($uri) update_post_meta($post_id, '_wpb_pre_update_link_uri', $uri);
    update_post_meta($post_id, '_wpb_pre_update_slug', $post->post_name);
}, 0);

/* ─────────────────────────────────────────
   AUTO-REDIRECT — POST / PAGE / CPT
   ───────────────────────────────────────── */

// Priority 9: salva URI prima dell'hook a priority 10
add_action('pre_post_update', function ($post_id, $data) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return;
    $uri = wp_make_link_relative(get_permalink($post_id) ?: '');
    if ($uri) update_post_meta($post_id, '_wpb_pre_update_link_uri', $uri);
    update_post_meta($post_id, '_wpb_pre_update_slug', $post->post_name);
}, 9, 2);

add_action('post_updated', function ($post_id, $post_after, $post_before) {
    if (!wpb_tools_module_enabled('seo')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    $old_slug = get_post_meta($post_id, '_wpb_pre_update_slug', true);
    $new_slug = $post_after->post_name;
    $old_uri  = get_post_meta($post_id, '_wpb_pre_update_link_uri', true);

    delete_post_meta($post_id, '_wpb_pre_update_slug');
    delete_post_meta($post_id, '_wpb_pre_update_link_uri');

    if (empty($old_slug) || $old_slug === $new_slug) return;
    if (empty($old_uri)) return;

    $new_uri = wp_make_link_relative(get_permalink($post_id) ?: '');
    if (empty($new_uri)) return;

    // Rimuove eventuali redirect obsoleti verso il nuovo URI
    // (es. /chi-siamo→/about viene rimosso quando lo slug torna a /chi-siamo)
    $rows = get_option(WPB_REDIRECTS_OPTION, []);
    wpb_redirects_cleanup_for_uri($new_uri, is_array($rows) ? $rows : []);

    if ($old_uri === $new_uri) return;

    wpb_redirects_add_auto($old_uri, $new_uri);
}, 10, 3);

/* ─────────────────────────────────────────
   AUTO-REDIRECT — TASSONOMIE
   ───────────────────────────────────────── */
add_action('edit_term', function ($term_id, $tt_id, $taxonomy) {
    if (!wpb_tools_module_enabled('seo')) return;
    $term = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term)) return;
    $link = get_term_link($term, $taxonomy);
    if (is_wp_error($link)) return;
    set_transient('wpb_term_pre_uri_' . $term_id, wp_make_link_relative($link), 60);
}, 10, 3);

add_action('edited_term', function ($term_id, $tt_id, $taxonomy) {
    if (!wpb_tools_module_enabled('seo')) return;
    $old_uri = get_transient('wpb_term_pre_uri_' . $term_id);
    delete_transient('wpb_term_pre_uri_' . $term_id);
    if (!$old_uri) return;
    $term = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term)) return;
    $link = get_term_link($term, $taxonomy);
    if (is_wp_error($link)) return;
    $new_uri = wp_make_link_relative($link);

    // Rimuove eventuali redirect obsoleti verso il nuovo URI
    $rows = get_option(WPB_REDIRECTS_OPTION, []);
    wpb_redirects_cleanup_for_uri($new_uri, is_array($rows) ? $rows : []);

    if ($old_uri === $new_uri) return;
    wpb_redirects_add_auto($old_uri, $new_uri);
}, 10, 3);

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function wpb_redirects_admin_page()
{
    $rows = wpb_redirects_get_all();
    $code_labels = [
        301 => '301 — Moved Permanently',
        302 => '302 — Found (temporaneo)',
        307 => '307 — Temporary Redirect',
        308 => '308 — Permanent Redirect',
        410 => '410 — Gone',
    ];
    ?>
    <style>
        #wpb-redirects-wrap { max-width: 960px; }

        /* ── Toolbar ────────────────────────── */
        .wpb-rd-toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
        }
        .wpb-rd-toolbar input[type=search] {
            flex: 1;
            max-width: 300px;
        }

        /* ── Table ──────────────────────────── */
        .wpb-rd-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            font-size: 13px;
            overflow: hidden;
        }
        .wpb-rd-table th {
            padding: 10px 14px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: #646970;
            background: #f6f7f7;
            border-bottom: 1px solid #f0f0f1;
            white-space: nowrap;
        }
        .wpb-rd-table td {
            padding: 11px 14px;
            border-bottom: 1px solid #f0f0f1;
            vertical-align: middle;
        }
        .wpb-rd-table tbody tr:last-child td { border-bottom: none; }
        .wpb-rd-table tbody tr:hover td { background: #f9f9f9; }

        .wpb-rd-uri {
            font-family: monospace;
            font-size: 12px;
            color: #1d2327;
            word-break: break-all;
        }
        .wpb-rd-arrow {
            color: #c3c4c7;
            font-size: 14px;
            padding: 0 4px;
        }
        .wpb-rd-code {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 3px;
            white-space: nowrap;
        }
        .wpb-rd-code-301, .wpb-rd-code-308 { background: #edfaef; color: #00a32a; }
        .wpb-rd-code-302, .wpb-rd-code-307 { background: #e8f0fb; color: #2271b1; }
        .wpb-rd-code-410                    { background: #fce8e8; color: #d63638; }

        .wpb-rd-note { font-size: 11px; color: #a7aaad; margin-top: 2px; }

        .wpb-rd-actions { display: flex; gap: 6px; }
        .wpb-rd-actions .button { font-size: 12px; padding: 2px 8px; min-height: 26px; line-height: 22px; }

        /* ── Modale ─────────────────────────── */
        .wpb-rd-modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 99998;
            align-items: center;
            justify-content: center;
        }
        .wpb-rd-modal-overlay.open { display: flex; }
        .wpb-rd-modal {
            background: #fff;
            border-radius: 4px;
            padding: 24px 28px;
            width: 520px;
            max-width: 95vw;
            box-shadow: 0 8px 32px rgba(0,0,0,.18);
            position: relative;
        }
        .wpb-rd-modal h2 {
            font-size: 16px;
            font-weight: 700;
            color: #1d2327;
            margin: 0 0 20px;
        }
        .wpb-rd-modal-close {
            position: absolute;
            top: 16px; right: 16px;
            background: none; border: none;
            font-size: 20px; color: #a7aaad;
            cursor: pointer; line-height: 1;
        }
        .wpb-rd-modal-close:hover { color: #1d2327; }

        .wpb-rd-field { margin-bottom: 16px; }
        .wpb-rd-field label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #646970;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin-bottom: 5px;
        }
        .wpb-rd-field input[type=text],
        .wpb-rd-field select {
            width: 100%;
            font-size: 13px;
        }
        .wpb-rd-field .wpb-rd-hint {
            font-size: 11px; color: #a7aaad; margin-top: 4px;
        }

        .wpb-rd-modal-row {
            display: flex; gap: 10px; align-items: center;
        }
        .wpb-rd-modal-row .wpb-rd-field { flex: 1; margin-bottom: 0; }
        .wpb-rd-modal-arrow {
            font-size: 18px; color: #c3c4c7; flex-shrink: 0;
            margin-top: 18px;
        }

        .wpb-rd-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #f0f0f1;
        }

        .wpb-rd-error {
            background: #fce8e8; color: #d63638;
            padding: 8px 12px; border-radius: 3px;
            font-size: 12px; margin-bottom: 12px;
            display: none;
        }

        /* ── Empty state ─────────────────────── */
        .wpb-rd-empty {
            text-align: center;
            padding: 48px 24px;
            color: #646970;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
        }
        .wpb-rd-empty .dashicons {
            font-size: 40px; width: 40px; height: 40px;
            color: #c3c4c7; display: block; margin: 0 auto 12px;
        }
        .wpb-rd-empty p { margin: 0 0 16px; font-size: 14px; }

        /* ── Counter ─────────────────────────── */
        .wpb-rd-count {
            font-size: 13px; color: #646970; margin-left: auto;
        }
    </style>

    <div id="wpb-redirects-wrap" class="wrap">
        <h1>
            <span class="dashicons dashicons-randomize"
                style="font-size:24px;width:24px;height:24px;vertical-align:middle;margin-right:6px;color:#e6600d;"></span>
            WP Barrio
            <span style="font-size:15px;font-weight:400;color:#646970;margin-left:4px;">/ SEO / Redirects</span>
        </h1>
        <p class="description" style="font-size:14px;margin:4px 0 20px;">
            Gestisci i redirect del sito. Vengono esportati via GraphQL come URI relativi
            da gestire nel frontend headless.
        </p>

        <!-- Toolbar -->
        <div class="wpb-rd-toolbar">
            <input type="search" id="wpb-rd-search" placeholder="Filtra per URI o nota…">
            <span class="wpb-rd-count" id="wpb-rd-count">
                <?php echo count($rows); ?> redirect
            </span>
            <button class="button button-primary" id="wpb-rd-add-btn">
                + Aggiungi redirect
            </button>
        </div>

        <!-- Tabella -->
        <div id="wpb-rd-table-wrap">
        <?php if (empty($rows)): ?>
            <div class="wpb-rd-empty" id="wpb-rd-empty">
                <span class="dashicons dashicons-randomize"></span>
                <p>Nessun redirect configurato.</p>
                <button class="button button-primary" id="wpb-rd-add-btn-empty">+ Aggiungi il primo redirect</button>
            </div>
        <?php else: ?>
            <table class="wpb-rd-table" id="wpb-rd-table">
                <thead>
                    <tr>
                        <th style="width:35%">Da URI</th>
                        <th style="width:35%">A URI</th>
                        <th style="width:90px">Codice</th>
                        <th>Nota</th>
                        <th style="width:100px"></th>
                    </tr>
                </thead>
                <tbody id="wpb-rd-tbody">
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $code = (int)($row['code'] ?? 301);
                        $is_gone = $code === 410;
                        ?>
                        <tr data-id="<?php echo esc_attr($row['id']); ?>"
                            data-from="<?php echo esc_attr($row['from']); ?>"
                            data-to="<?php echo esc_attr($row['to'] ?? ''); ?>"
                            data-code="<?php echo esc_attr($code); ?>"
                            data-note="<?php echo esc_attr($row['note'] ?? ''); ?>">
                            <td>
                                <span class="wpb-rd-uri"><?php echo esc_html($row['from']); ?></span>
                            </td>
                            <td>
                                <?php if (!$is_gone): ?>
                                    <span class="wpb-rd-uri"><?php echo esc_html($row['to'] ?? ''); ?></span>
                                <?php else: ?>
                                    <span style="color:#a7aaad;font-size:12px;font-style:italic;">rimossa</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="wpb-rd-code wpb-rd-code-<?php echo $code; ?>">
                                    <?php echo $code; ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($row['note'])): ?>
                                    <span class="wpb-rd-note"><?php echo esc_html($row['note']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="wpb-rd-actions">
                                    <button class="button wpb-rd-edit-btn">Modifica</button>
                                    <button class="button wpb-rd-delete-btn"
                                        style="color:#d63638;border-color:#d63638;">✕</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
    </div>

    <!-- Modale aggiungi/modifica -->
    <div class="wpb-rd-modal-overlay" id="wpb-rd-modal">
        <div class="wpb-rd-modal">
            <button class="wpb-rd-modal-close" id="wpb-rd-modal-close">✕</button>
            <h2 id="wpb-rd-modal-title">Aggiungi redirect</h2>

            <div class="wpb-rd-error" id="wpb-rd-modal-error"></div>

            <input type="hidden" id="wpb-rd-edit-id" value="">

            <!-- Da → A -->
            <div class="wpb-rd-modal-row">
                <div class="wpb-rd-field">
                    <label for="wpb-rd-from">Da</label>
                    <input type="text" id="wpb-rd-from" placeholder="/vecchio-path/">
                    <div class="wpb-rd-hint">URI relativo di partenza (es. /about-us/)</div>
                </div>
                <div class="wpb-rd-modal-arrow">→</div>
                <div class="wpb-rd-field">
                    <label for="wpb-rd-to">A</label>
                    <input type="text" id="wpb-rd-to" placeholder="/nuovo-path/">
                    <div class="wpb-rd-hint">URI di destinazione (vuoto se 410 Gone)</div>
                </div>
            </div>

            <!-- Codice -->
            <div class="wpb-rd-field" style="margin-top:14px;">
                <label for="wpb-rd-code">Codice HTTP</label>
                <select id="wpb-rd-code">
                    <?php foreach ($code_labels as $c => $label): ?>
                        <option value="<?php echo $c; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Nota -->
            <div class="wpb-rd-field">
                <label for="wpb-rd-note">Nota (opzionale)</label>
                <input type="text" id="wpb-rd-note" placeholder="Es. migrazione sezione blog">
            </div>

            <div class="wpb-rd-modal-footer">
                <button class="button" id="wpb-rd-cancel-btn">Annulla</button>
                <button class="button button-primary" id="wpb-rd-save-btn">Salva redirect</button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var nonce    = <?php echo wp_json_encode(wp_create_nonce('wpb_redirects_nonce')); ?>;
        var ajaxUrl  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

        var modal       = document.getElementById('wpb-rd-modal');
        var modalTitle  = document.getElementById('wpb-rd-modal-title');
        var modalError  = document.getElementById('wpb-rd-modal-error');
        var editId      = document.getElementById('wpb-rd-edit-id');
        var fromInput   = document.getElementById('wpb-rd-from');
        var toInput     = document.getElementById('wpb-rd-to');
        var codeSelect  = document.getElementById('wpb-rd-code');
        var noteInput   = document.getElementById('wpb-rd-note');
        var saveBtn     = document.getElementById('wpb-rd-save-btn');
        var tbody       = document.getElementById('wpb-rd-tbody');
        var countEl     = document.getElementById('wpb-rd-count');
        var searchInput = document.getElementById('wpb-rd-search');
        var tableWrap   = document.getElementById('wpb-rd-table-wrap');

        // ── Apri modale ──────────────────────────────────────────────────
        function openModal(data) {
            data = data || {};
            editId.value       = data.id   || '';
            fromInput.value    = data.from || '';
            toInput.value      = data.to   || '';
            codeSelect.value   = data.code || '301';
            noteInput.value    = data.note || '';
            modalTitle.textContent = data.id ? 'Modifica redirect' : 'Aggiungi redirect';
            modalError.style.display = 'none';
            modal.classList.add('open');
            fromInput.focus();
        }
        function closeModal() { modal.classList.remove('open'); }

        document.getElementById('wpb-rd-add-btn').addEventListener('click', function () { openModal(); });
        var addBtnEmpty = document.getElementById('wpb-rd-add-btn-empty');
        if (addBtnEmpty) addBtnEmpty.addEventListener('click', function () { openModal(); });
        document.getElementById('wpb-rd-modal-close').addEventListener('click', closeModal);
        document.getElementById('wpb-rd-cancel-btn').addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

        // 410 Gone → svuota e disabilita campo "A"
        codeSelect.addEventListener('change', function () {
            var gone = this.value === '410';
            toInput.disabled = gone;
            if (gone) toInput.value = '';
        });

        // ── Salva ────────────────────────────────────────────────────────
        saveBtn.addEventListener('click', function () {
            var from = fromInput.value.trim();
            var to   = toInput.value.trim();
            var code = codeSelect.value;
            if (!from) { showError('Il campo "Da" è obbligatorio.'); return; }
            if (code !== '410' && !to) { showError('Il campo "A" è obbligatorio per questo codice.'); return; }

            saveBtn.disabled = true;
            saveBtn.textContent = 'Salvataggio…';

            var data = new FormData();
            data.append('action', 'wpb_redirect_save');
            data.append('nonce',  nonce);
            data.append('id',     editId.value);
            data.append('from',   from);
            data.append('to',     to);
            data.append('code',   code);
            data.append('note',   noteInput.value.trim());

            fetch(ajaxUrl, { method: 'POST', body: data })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        closeModal();
                        renderTable(res.data.rows);
                    } else {
                        showError(res.data || 'Errore sconosciuto.');
                    }
                })
                .catch(function () { showError('Errore di rete.'); })
                .finally(function () {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Salva redirect';
                });
        });

        // ── Elimina ──────────────────────────────────────────────────────
        document.addEventListener('click', function (e) {
            if (!e.target.classList.contains('wpb-rd-delete-btn')) return;
            var row = e.target.closest('tr');
            var from = row.dataset.from;
            if (!confirm('Eliminare il redirect da ' + from + '?')) return;

            var data = new FormData();
            data.append('action', 'wpb_redirect_delete');
            data.append('nonce',  nonce);
            data.append('id',     row.dataset.id);

            fetch(ajaxUrl, { method: 'POST', body: data })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) renderTable(res.data.rows);
                });
        });

        // ── Modifica ─────────────────────────────────────────────────────
        document.addEventListener('click', function (e) {
            if (!e.target.classList.contains('wpb-rd-edit-btn')) return;
            var row = e.target.closest('tr');
            openModal({
                id:   row.dataset.id,
                from: row.dataset.from,
                to:   row.dataset.to,
                code: row.dataset.code,
                note: row.dataset.note,
            });
        });

        // ── Ricerca ──────────────────────────────────────────────────────
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var q = this.value.toLowerCase();
                var rows = document.querySelectorAll('#wpb-rd-tbody tr');
                var visible = 0;
                rows.forEach(function (tr) {
                    var text = (tr.dataset.from + ' ' + tr.dataset.to + ' ' + tr.dataset.note).toLowerCase();
                    var show = !q || text.includes(q);
                    tr.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                if (countEl) countEl.textContent = visible + ' redirect';
            });
        }

        // ── Render tabella ───────────────────────────────────────────────
        function renderTable(rows) {
            if (!rows || rows.length === 0) {
                tableWrap.innerHTML =
                    '<div class="wpb-rd-empty" id="wpb-rd-empty">' +
                    '<span class="dashicons dashicons-randomize"></span>' +
                    '<p>Nessun redirect configurato.</p>' +
                    '<button class="button button-primary" id="wpb-rd-add-btn-empty">+ Aggiungi il primo redirect</button>' +
                    '</div>';
                var b = document.getElementById('wpb-rd-add-btn-empty');
                if (b) b.addEventListener('click', function () { openModal(); });
                if (countEl) countEl.textContent = '0 redirect';
                return;
            }

            var codeClass = { 301:'wpb-rd-code-301', 302:'wpb-rd-code-302', 307:'wpb-rd-code-307', 308:'wpb-rd-code-308', 410:'wpb-rd-code-410' };

            var html = '<table class="wpb-rd-table" id="wpb-rd-table">' +
                '<thead><tr>' +
                '<th style="width:35%">Da URI</th>' +
                '<th style="width:35%">A URI</th>' +
                '<th style="width:90px">Codice</th>' +
                '<th>Nota</th>' +
                '<th style="width:100px"></th>' +
                '</tr></thead><tbody id="wpb-rd-tbody">';

            rows.forEach(function (row) {
                var code   = row.code || 301;
                var isGone = code === 410;
                html += '<tr' +
                    ' data-id="'   + esc(row.id)           + '"' +
                    ' data-from="' + esc(row.from)         + '"' +
                    ' data-to="'   + esc(row.to   || '')   + '"' +
                    ' data-code="' + esc(String(code))     + '"' +
                    ' data-note="' + esc(row.note || '')   + '">' +
                    '<td><span class="wpb-rd-uri">' + esc(row.from) + '</span></td>' +
                    '<td>' + (isGone
                        ? '<span style="color:#a7aaad;font-size:12px;font-style:italic;">rimossa</span>'
                        : '<span class="wpb-rd-uri">' + esc(row.to || '') + '</span>') +
                    '</td>' +
                    '<td><span class="wpb-rd-code ' + (codeClass[code] || '') + '">' + code + '</span></td>' +
                    '<td>' + (row.note ? '<span class="wpb-rd-note">' + esc(row.note) + '</span>' : '') + '</td>' +
                    '<td><div class="wpb-rd-actions">' +
                    '<button class="button wpb-rd-edit-btn">Modifica</button>' +
                    '<button class="button wpb-rd-delete-btn" style="color:#d63638;border-color:#d63638;">✕</button>' +
                    '</div></td>' +
                    '</tr>';
            });

            html += '</tbody></table>';
            tableWrap.innerHTML = html;
            if (countEl) countEl.textContent = rows.length + ' redirect';
        }

        function showError(msg) {
            modalError.textContent = msg;
            modalError.style.display = 'block';
        }

        function esc(s) {
            return String(s)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;')
                .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
    })();
    </script>
    <?php
}
