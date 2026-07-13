<?php
/**
 * Suite Barrio — SEO / Redirects
 * Gestione redirect 301/302/307/410 lato headless.
 * I redirect vengono esportati via GraphQL come URI relativi
 * e gestiti dal frontend (Next.js, Nuxt, ecc.).
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

if (!defined('SUITE_BARRIO_REDIRECTS_OPTION'))
    define('SUITE_BARRIO_REDIRECTS_OPTION', 'wpb_redirects');

/* ─────────────────────────────────────────
   MENU
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!suite_barrio_tools_module_enabled('seo'))
        return;

    add_submenu_page(
        'suite-barrio',
        'Redirects — Suite Barrio',
        'Redirects',
        'manage_options',
        'suite-barrio-redirects',
        'suite_barrio_redirects_admin_page'
    );
}, 14);

/* ─────────────────────────────────────────
   ENQUEUE — CSS menu nascosto + JS pagina redirect
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    // Nasconde la voce di sottomenu dal menu di navigazione (pagina raggiungibile solo via link diretto)
    wp_add_inline_style(
        'suite-barrio-admin',
        '#adminmenu a[href="admin.php?page=suite-barrio-redirects"]{display:none!important}'
    );

    // Script della pagina Redirects — solo sulla pagina stessa
    if ($hook !== 'suite-barrio_page_suite-barrio-redirects')
        return;

    wp_register_script('suite-barrio-redirects-admin', '', [], SUITE_BARRIO_VERSION, true);
    wp_enqueue_script('suite-barrio-redirects-admin');

    $suite_barrio_redirects_js = '(function () {
    var nonce = SUITE_BARRIO_REDIRECTS_NONCE;
    var ajaxUrl = SUITE_BARRIO_REDIRECTS_AJAX_URL;

    var modal = document.getElementById(\'suite-barrio-rd-modal\');
    var modalTitle = document.getElementById(\'suite-barrio-rd-modal-title\');
    var modalError = document.getElementById(\'suite-barrio-rd-modal-error\');
    var editId = document.getElementById(\'suite-barrio-rd-edit-id\');
    var fromInput = document.getElementById(\'suite-barrio-rd-from\');
    var toInput = document.getElementById(\'suite-barrio-rd-to\');
    var codeSelect = document.getElementById(\'suite-barrio-rd-code\');
    var noteInput = document.getElementById(\'suite-barrio-rd-note\');
    var saveBtn = document.getElementById(\'suite-barrio-rd-save-btn\');
    var tbody = document.getElementById(\'suite-barrio-rd-tbody\');
    var countEl = document.getElementById(\'suite-barrio-rd-count\');
    var searchInput = document.getElementById(\'suite-barrio-rd-search\');
    var tableWrap = document.getElementById(\'suite-barrio-rd-table-wrap\');

    function openModal(data) {
        data = data || {};
        editId.value = data.id || \'\';
        fromInput.value = data.from || \'\';
        toInput.value = data.to || \'\';
        codeSelect.value = data.code || \'301\';
        noteInput.value = data.note || \'\';
        toInput.disabled = (data.code === \'410\');
        modalTitle.textContent = data.id ? \'Modifica redirect\' : \'Aggiungi redirect\';
        modalError.style.display = \'none\';
        modal.classList.add(\'open\');
        fromInput.focus();
    }
    function closeModal() { modal.classList.remove(\'open\'); }

    document.getElementById(\'suite-barrio-rd-add-btn\').addEventListener(\'click\', function () { openModal(); });
    var addBtnEmpty = document.getElementById(\'suite-barrio-rd-add-btn-empty\');
    if (addBtnEmpty) addBtnEmpty.addEventListener(\'click\', function () { openModal(); });
    document.getElementById(\'suite-barrio-rd-modal-close\').addEventListener(\'click\', closeModal);
    document.getElementById(\'suite-barrio-rd-cancel-btn\').addEventListener(\'click\', closeModal);
    modal.addEventListener(\'click\', function (e) { if (e.target === modal) closeModal(); });

    codeSelect.addEventListener(\'change\', function () {
        var gone = this.value === \'410\';
        toInput.disabled = gone;
        if (gone) toInput.value = \'\';
    });

    saveBtn.addEventListener(\'click\', function () {
        var from = fromInput.value.trim();
        var to = toInput.value.trim();
        var code = codeSelect.value;
        if (!from) { showError(\'Il campo "Da" è obbligatorio.\'); return; }
        if (code !== \'410\' && !to) { showError(\'Il campo "A" è obbligatorio per questo codice.\'); return; }

        saveBtn.disabled = true;
        saveBtn.innerHTML = \'<div style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:suiteBarrioRdSpin .65s linear infinite;vertical-align:middle;margin-right:4px;"></div> Salvataggio…\';

        var data = new FormData();
        data.append(\'action\', \'suite_barrio_redirect_save\');
        data.append(\'nonce\', nonce);
        data.append(\'id\', editId.value);
        data.append(\'from\', from);
        data.append(\'to\', to);
        data.append(\'code\', code);
        data.append(\'note\', noteInput.value.trim());

        fetch(ajaxUrl, { method: \'POST\', body: data })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) { closeModal(); renderTable(res.data.rows); }
                else { showError(res.data || \'Errore sconosciuto.\'); }
            })
            .catch(function () { showError(\'Errore di rete.\'); })
            .finally(function () {
                saveBtn.disabled = false;
                saveBtn.innerHTML = \'<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> Salva redirect\';
            });
    });

    document.addEventListener(\'click\', function (e) {
        if (e.target.classList.contains(\'suite-barrio-rd-delete-btn\')) {
            var row = e.target.closest(\'tr\');
            if (!confirm(\'Eliminare il redirect da \' + row.dataset.from + \'?\')) return;
            var data = new FormData();
            data.append(\'action\', \'suite_barrio_redirect_delete\');
            data.append(\'nonce\', nonce);
            data.append(\'id\', row.dataset.id);
            fetch(ajaxUrl, { method: \'POST\', body: data })
                .then(function (r) { return r.json(); })
                .then(function (res) { if (res.success) renderTable(res.data.rows); });
        }
        if (e.target.classList.contains(\'suite-barrio-rd-edit-btn\')) {
            var row = e.target.closest(\'tr\');
            openModal({ id: row.dataset.id, from: row.dataset.from, to: row.dataset.to, code: row.dataset.code, note: row.dataset.note });
        }
    });

    if (searchInput) {
        searchInput.addEventListener(\'input\', function () {
            var q = this.value.toLowerCase();
            var rows = document.querySelectorAll(\'#suite-barrio-rd-tbody tr\');
            var visible = 0;
            rows.forEach(function (tr) {
                var show = !q || (tr.dataset.from + \' \' + tr.dataset.to + \' \' + tr.dataset.note).toLowerCase().includes(q);
                tr.style.display = show ? \'\' : \'none\';
                if (show) visible++;
            });
            if (countEl) countEl.textContent = visible + \' redirect\';
        });
    }

    function renderTable(rows) {
        if (!rows || rows.length === 0) {
            tableWrap.innerHTML = \'<div class="suite-barrio-rd-empty"><span class="dashicons dashicons-randomize"></span><p>Nessun redirect configurato.</p><button class="suite-barrio-rd-add-btn" id="suite-barrio-rd-add-btn-empty">+ Aggiungi il primo redirect</button></div>\';
            var b = document.getElementById(\'suite-barrio-rd-add-btn-empty\');
            if (b) b.addEventListener(\'click\', function () { openModal(); });
            if (countEl) countEl.textContent = \'0 redirect\';
            return;
        }
        var codeClass = { 301: \'suite-barrio-rd-code-301\', 302: \'suite-barrio-rd-code-302\', 307: \'suite-barrio-rd-code-307\', 308: \'suite-barrio-rd-code-308\', 410: \'suite-barrio-rd-code-410\' };
        var html = \'<table class="suite-barrio-rd-table" id="suite-barrio-rd-table"><thead><tr><th style="width:35%">Da URI</th><th style="width:35%">A URI</th><th style="width:90px">Codice</th><th>Nota</th><th style="width:110px"></th></tr></thead><tbody id="suite-barrio-rd-tbody">\';
        rows.forEach(function (row) {
            var code = row.code || 301;
            var isGone = code === 410;
            html += \'<tr data-id="\' + esc(row.id) + \'" data-from="\' + esc(row.from) + \'" data-to="\' + esc(row.to || \'\') + \'" data-code="\' + esc(String(code)) + \'" data-note="\' + esc(row.note || \'\') + \'">\' +
                \'<td><span class="suite-barrio-rd-uri">\' + esc(row.from) + \'</span></td>\' +
                \'<td>\' + (isGone ? \'<span style="color:#9ca3af;font-size:12px;font-style:italic;">rimossa</span>\' : \'<span class="suite-barrio-rd-uri">\' + esc(row.to || \'\') + \'</span>\') + \'</td>\' +
                \'<td><span class="suite-barrio-rd-code \' + (codeClass[code] || \'\') + \'">\' + code + \'</span></td>\' +
                \'<td>\' + (row.note ? \'<span class="suite-barrio-rd-note">\' + esc(row.note) + \'</span>\' : \'\') + \'</td>\' +
                \'<td><div class="suite-barrio-rd-actions"><button class="suite-barrio-rd-action-btn suite-barrio-rd-edit-btn">Modifica</button><button class="suite-barrio-rd-action-btn suite-barrio-rd-delete-btn">&#x2715;</button></div></td>\' +
                \'</tr>\';
        });
        html += \'</tbody></table>\';
        tableWrap.innerHTML = html;
        if (countEl) countEl.textContent = rows.length + \' redirect\';
    }

    function showError(msg) { modalError.textContent = msg; modalError.style.display = \'block\'; }
    function esc(s) { return String(s).replace(/&/g, \'&amp;\').replace(/</g, \'&lt;\').replace(/>/g, \'&gt;\').replace(/"/g, \'&quot;\'); }
})();';

    $suite_barrio_redirects_js = str_replace(
        ['SUITE_BARRIO_REDIRECTS_NONCE', 'SUITE_BARRIO_REDIRECTS_AJAX_URL'],
        [wp_json_encode(wp_create_nonce('suite_barrio_redirects_nonce')), wp_json_encode(admin_url('admin-ajax.php'))],
        $suite_barrio_redirects_js
    );

    wp_add_inline_script('suite-barrio-redirects-admin', $suite_barrio_redirects_js);
});

add_filter('submenu_file', function ($submenu_file) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    if (isset($_GET['page']) && $_GET['page'] === 'suite-barrio-redirects')
        return 'suite-barrio-seo';
    return $submenu_file;
});

/* ─────────────────────────────────────────
   HELPER: legge tutti i redirect ordinati
   ───────────────────────────────────────── */
function suite_barrio_redirects_get_all(): array
{
    $rows = get_option(SUITE_BARRIO_REDIRECTS_OPTION, []);
    if (!is_array($rows))
        return [];

    // Ordina per from_uri alfabetico
    usort($rows, fn($a, $b) => strcmp($a['from'] ?? '', $b['from'] ?? ''));
    return $rows;
}

/* ─────────────────────────────────────────
   HELPER: normalizza URI (sempre /path)
   ───────────────────────────────────────── */
function suite_barrio_redirects_normalize_uri(string $input): string
{
    $input = trim($input);

    // Se è un URL assoluto estrai solo il path
    if (preg_match('#^https?://#i', $input)) {
        $parsed = wp_parse_url($input);
        $input = ($parsed['path'] ?? '/');
        if (!empty($parsed['query']))
            $input .= '?' . $parsed['query'];
        if (!empty($parsed['fragment']))
            $input .= '#' . $parsed['fragment'];
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
add_action('wp_ajax_suite_barrio_redirect_save', function () {
    check_ajax_referer('suite_barrio_redirects_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Unauthorized', 403);

    $id = sanitize_text_field(wp_unslash($_POST['id'] ?? ''));
    $from = suite_barrio_redirects_normalize_uri(sanitize_text_field(wp_unslash($_POST['from'] ?? '')));
    $to = suite_barrio_redirects_normalize_uri(sanitize_text_field(wp_unslash($_POST['to'] ?? '')));
    $code = absint(wp_unslash($_POST['code'] ?? 301));
    $note = sanitize_text_field(wp_unslash($_POST['note'] ?? ''));

    if (empty($from) || empty($to))
        wp_send_json_error('from e to sono obbligatori');
    if (!in_array($code, [301, 302, 307, 308, 410], true))
        wp_send_json_error('Codice non valido');

    $rows = get_option(SUITE_BARRIO_REDIRECTS_OPTION, []);
    if (!is_array($rows))
        $rows = [];

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
            'id' => wp_generate_uuid4(),
            'from' => $from,
            'to' => $to,
            'code' => $code,
            'note' => $note,
        ];
    }

    update_option(SUITE_BARRIO_REDIRECTS_OPTION, $rows, false);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_send_json_success(['rows' => suite_barrio_redirects_get_all()]);
});

/* ─────────────────────────────────────────
   AJAX: elimina un redirect
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_redirect_delete', function () {
    check_ajax_referer('suite_barrio_redirects_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Unauthorized', 403);

    $id = sanitize_text_field(wp_unslash($_POST['id'] ?? ''));
    $rows = get_option(SUITE_BARRIO_REDIRECTS_OPTION, []);
    if (!is_array($rows))
        $rows = [];

    $rows = array_values(array_filter($rows, fn($r) => ($r['id'] ?? '') !== $id));
    update_option(SUITE_BARRIO_REDIRECTS_OPTION, $rows, false);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_send_json_success(['rows' => suite_barrio_redirects_get_all()]);
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
function suite_barrio_redirects_add_auto(string $from, string $to): void
{
    if (empty($from) || empty($to) || $from === $to)
        return;

    $rows = get_option(SUITE_BARRIO_REDIRECTS_OPTION, []);
    if (!is_array($rows))
        $rows = [];

    // 0. Pulizia preventiva: se $to è un URI che era già una "from"
    //    in un redirect esistente, quel redirect è ora obsoleto
    //    (la risorsa è tornata a esistere a quell'indirizzo)
    $rows = suite_barrio_redirects_cleanup_for_uri($to, $rows);

    // 1. Non aggiungere se esiste già lo stesso "from"
    foreach ($rows as $row) {
        if (($row['from'] ?? '') === $from)
            return;
    }

    // 2. Rileva loop transitivi
    if (suite_barrio_redirects_would_loop($from, $to, $rows))
        return;

    // 3. Accorcia catene esistenti: A→B→C diventa A→C
    foreach ($rows as &$row) {
        if (($row['to'] ?? '') === $from) {
            $row['to'] = $to;
        }
    }
    unset($row);

    // 4. Aggiunge il nuovo redirect
    $rows[] = [
        'id' => wp_generate_uuid4(),
        'from' => $from,
        'to' => $to,
        'code' => 301,
        'note' => 'Aggiunto automaticamente al cambio slug',
    ];

    update_option(SUITE_BARRIO_REDIRECTS_OPTION, $rows, false);
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
function suite_barrio_redirects_cleanup_for_uri(string $uri, array $rows): array
{
    if (empty($uri))
        return $rows;

    $rows = array_values(array_filter($rows, fn($r) => ($r['from'] ?? '') !== $uri));

    update_option(SUITE_BARRIO_REDIRECTS_OPTION, $rows, false);
    return $rows;
}

/* ─────────────────────────────────────────
   HELPER: verifica che from→to non crei loop
   ───────────────────────────────────────── */
function suite_barrio_redirects_would_loop(string $from, string $to, array $rows): bool
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
    $steps = 0;

    while ($steps++ < 20) {
        if ($current === $from)
            return true;
        if (isset($visited[$current]))
            return true;
        $visited[$current] = true;
        if (!isset($map[$current]))
            break;
        $current = $map[$current];
    }

    return false;
}

/* ─────────────────────────────────────────
   AUTO-REDIRECT — QUICK EDIT (inline-save)
   ───────────────────────────────────────── */
add_action('wp_ajax_inline-save', function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nessun nonce specifico definito in questo file: l'azione 'wp_ajax_inline-save' è gestita nativamente da WordPress core, che verifica già il proprio nonce prima/dopo questo hook.
    $post_id = absint(wp_unslash($_POST['post_ID'] ?? 0));
    if (!$post_id)
        return;
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish')
        return;
    $uri = wp_make_link_relative(get_permalink($post_id) ?: '');
    if ($uri)
        update_post_meta($post_id, '_wpb_pre_update_link_uri', $uri);
    update_post_meta($post_id, '_wpb_pre_update_slug', $post->post_name);
}, 0);

/* ─────────────────────────────────────────
   AUTO-REDIRECT — POST / PAGE / CPT
   ───────────────────────────────────────── */

// Priority 9: salva URI prima dell'hook a priority 10
add_action('pre_post_update', function ($post_id, $data) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish')
        return;
    $uri = wp_make_link_relative(get_permalink($post_id) ?: '');
    if ($uri)
        update_post_meta($post_id, '_wpb_pre_update_link_uri', $uri);
    update_post_meta($post_id, '_wpb_pre_update_slug', $post->post_name);
}, 9, 2);

add_action('post_updated', function ($post_id, $post_after, $post_before) {
    if (!suite_barrio_tools_module_enabled('seo'))
        return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;
    if (wp_is_post_revision($post_id))
        return;

    $old_slug = get_post_meta($post_id, '_wpb_pre_update_slug', true);
    $new_slug = $post_after->post_name;
    $old_uri = get_post_meta($post_id, '_wpb_pre_update_link_uri', true);

    delete_post_meta($post_id, '_wpb_pre_update_slug');
    delete_post_meta($post_id, '_wpb_pre_update_link_uri');

    if (empty($old_slug) || $old_slug === $new_slug)
        return;
    if (empty($old_uri))
        return;

    $new_uri = wp_make_link_relative(get_permalink($post_id) ?: '');
    if (empty($new_uri))
        return;

    // Rimuove eventuali redirect obsoleti verso il nuovo URI
    // (es. /chi-siamo→/about viene rimosso quando lo slug torna a /chi-siamo)
    $rows = get_option(SUITE_BARRIO_REDIRECTS_OPTION, []);
    suite_barrio_redirects_cleanup_for_uri($new_uri, is_array($rows) ? $rows : []);

    if ($old_uri === $new_uri)
        return;

    suite_barrio_redirects_add_auto($old_uri, $new_uri);
}, 10, 3);

/* ─────────────────────────────────────────
   AUTO-REDIRECT — TASSONOMIE
   ───────────────────────────────────────── */
add_action('edit_term', function ($term_id, $tt_id, $taxonomy) {
    if (!suite_barrio_tools_module_enabled('seo'))
        return;
    $term = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term))
        return;
    $link = get_term_link($term, $taxonomy);
    if (is_wp_error($link))
        return;
    set_transient('wpb_term_pre_uri_' . $term_id, wp_make_link_relative($link), 60);
}, 10, 3);

add_action('edited_term', function ($term_id, $tt_id, $taxonomy) {
    if (!suite_barrio_tools_module_enabled('seo'))
        return;
    $old_uri = get_transient('wpb_term_pre_uri_' . $term_id);
    delete_transient('wpb_term_pre_uri_' . $term_id);
    if (!$old_uri)
        return;
    $term = get_term($term_id, $taxonomy);
    if (!$term || is_wp_error($term))
        return;
    $link = get_term_link($term, $taxonomy);
    if (is_wp_error($link))
        return;
    $new_uri = wp_make_link_relative($link);

    // Rimuove eventuali redirect obsoleti verso il nuovo URI
    $rows = get_option(SUITE_BARRIO_REDIRECTS_OPTION, []);
    suite_barrio_redirects_cleanup_for_uri($new_uri, is_array($rows) ? $rows : []);

    if ($old_uri === $new_uri)
        return;
    suite_barrio_redirects_add_auto($old_uri, $new_uri);
}, 10, 3);

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function suite_barrio_redirects_admin_page()
{
    $rows = suite_barrio_redirects_get_all();
    $code_labels = [
        301 => '301 — Moved Permanently',
        302 => '302 — Found (temporaneo)',
        307 => '307 — Temporary Redirect',
        308 => '308 — Permanent Redirect',
        410 => '410 — Gone',
    ];
    ?>
<div id="suite-barrio-redirects-wrap" class="wrap suite-barrio-wrap">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
            <div class="suite-barrio-rd-header">
                <div class="suite-barrio-rd-header-icon"><span class="dashicons dashicons-randomize"></span></div>
                <div>
                    <h1>Suite Barrio <span class="suite-barrio-rd-breadcrumb">/ SEO / Redirects</span></h1>
                    <p class="suite-barrio-rd-sub">Gestisci redirect del sito per il frontend headless</p>
                </div>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-seo')); ?>"
                style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;font-size:12.5px;font-weight:600;background:#f3f4f6;color:#374151 !important;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none !important;white-space:nowrap;flex-shrink:0;align-self:flex-start;">
                <span class="dashicons dashicons-arrow-left-alt2"
                    style="font-size:14px;width:14px;height:14px;margin-top:1px;"></span>
                Strumenti SEO
            </a>
        </div>
        <hr class="suite-barrio-rd-divider">

        <div class="suite-barrio-rd-toolbar">
            <input type="search" id="suite-barrio-rd-search" placeholder="Filtra per URI o nota…">
            <span class="suite-barrio-rd-count" id="suite-barrio-rd-count"><?php echo count($rows); ?> redirect</span>
            <button class="suite-barrio-rd-add-btn" id="suite-barrio-rd-add-btn">+ Aggiungi redirect</button>
        </div>

        <div id="suite-barrio-rd-table-wrap">
            <?php if (empty($rows)): ?>
                <div class="suite-barrio-rd-empty" id="suite-barrio-rd-empty">
                    <span class="dashicons dashicons-randomize"></span>
                    <p>Nessun redirect configurato.</p>
                    <button class="suite-barrio-rd-add-btn" id="suite-barrio-rd-add-btn-empty">+ Aggiungi il primo redirect</button>
                </div>
            <?php else: ?>
                <table class="suite-barrio-rd-table" id="suite-barrio-rd-table">
                    <thead>
                        <tr>
                            <th style="width:35%">Da URI</th>
                            <th style="width:35%">A URI</th>
                            <th style="width:90px">Codice</th>
                            <th>Nota</th>
                            <th style="width:110px"></th>
                        </tr>
                    </thead>
                    <tbody id="suite-barrio-rd-tbody">
                        <?php foreach ($rows as $row):
                            $code = (int) ($row['code'] ?? 301);
                            $is_gone = $code === 410;
                            ?>
                            <tr data-id="<?php echo esc_attr($row['id']); ?>" data-from="<?php echo esc_attr($row['from']); ?>"
                                data-to="<?php echo esc_attr($row['to'] ?? ''); ?>" data-code="<?php echo esc_attr($code); ?>"
                                data-note="<?php echo esc_attr($row['note'] ?? ''); ?>">
                                <td><span class="suite-barrio-rd-uri"><?php echo esc_html($row['from']); ?></span></td>
                                <td>
                                    <?php if (!$is_gone): ?>
                                        <span class="suite-barrio-rd-uri"><?php echo esc_html($row['to'] ?? ''); ?></span>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;font-size:12px;font-style:italic;">rimossa</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="suite-barrio-rd-code suite-barrio-rd-code-<?php echo esc_attr($code); ?>"><?php echo esc_html($code); ?></span></td>
                                <td><?php if (!empty($row['note'])): ?><span
                                            class="suite-barrio-rd-note"><?php echo esc_html($row['note']); ?></span><?php endif; ?></td>
                                <td>
                                    <div class="suite-barrio-rd-actions">
                                        <button class="suite-barrio-rd-action-btn suite-barrio-rd-edit-btn">Modifica</button>
                                        <button class="suite-barrio-rd-action-btn suite-barrio-rd-delete-btn">&#x2715;</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modale -->
    <div class="suite-barrio-rd-modal-overlay" id="suite-barrio-rd-modal">
        <div class="suite-barrio-rd-modal">
            <button class="suite-barrio-rd-modal-close" id="suite-barrio-rd-modal-close">&#x2715;</button>
            <h2 id="suite-barrio-rd-modal-title">Aggiungi redirect</h2>
            <div class="suite-barrio-rd-error" id="suite-barrio-rd-modal-error"></div>
            <input type="hidden" id="suite-barrio-rd-edit-id" value="">
            <div class="suite-barrio-rd-modal-row">
                <div class="suite-barrio-rd-field">
                    <label for="suite-barrio-rd-from">Da</label>
                    <input type="text" id="suite-barrio-rd-from" placeholder="/vecchio-path/">
                    <div class="suite-barrio-rd-hint">URI relativo di partenza (es. /about-us/)</div>
                </div>
                <div class="suite-barrio-rd-modal-arrow">→</div>
                <div class="suite-barrio-rd-field">
                    <label for="suite-barrio-rd-to">A</label>
                    <input type="text" id="suite-barrio-rd-to" placeholder="/nuovo-path/">
                    <div class="suite-barrio-rd-hint">URI di destinazione (vuoto se 410 Gone)</div>
                </div>
            </div>
            <div class="suite-barrio-rd-field" style="margin-top:14px;">
                <label for="suite-barrio-rd-code">Codice HTTP</label>
                <select id="suite-barrio-rd-code">
                    <?php foreach ($code_labels as $c => $label): ?>
                        <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="suite-barrio-rd-field">
                <label for="suite-barrio-rd-note">Nota (opzionale)</label>
                <input type="text" id="suite-barrio-rd-note" placeholder="Es. migrazione sezione blog">
            </div>
            <div class="suite-barrio-rd-modal-footer">
                <button class="suite-barrio-rd-modal-btn suite-barrio-rd-modal-btn-ghost" id="suite-barrio-rd-cancel-btn">Annulla</button>
                <button class="suite-barrio-rd-modal-btn suite-barrio-rd-modal-btn-primary" id="suite-barrio-rd-save-btn">
                    <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                    Salva redirect
                </button>
            </div>
        </div>
    </div>
<?php
    // JS della pagina registrato/iniettato via wp_add_inline_script() nell'hook admin_enqueue_scripts (vedi sopra).
}