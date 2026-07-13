<?php
if (!defined('ABSPATH')) exit;

/* ── Assets: JS/CSS dei tab Servizi, GTM e Banner, caricati solo sulla
   pagina/tab corrispondente della schermata Cookie e Privacy ── */
add_action('admin_enqueue_scripts', function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $page = sanitize_key($_GET['page'] ?? '');
    if ($page !== 'suite-barrio-cookie-privacy') return;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $tab = sanitize_key($_GET['tab'] ?? 'generate');

    if ($tab === 'servizi') {
        $css = '.suite-barrio-cb-table{width:100%;border-collapse:collapse;font-size:13px;}
.suite-barrio-cb-table th{text-align:left;padding:10px 12px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b;}
.suite-barrio-cb-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.suite-barrio-cb-table tr:last-child td{border-bottom:none;}
.suite-barrio-cb-badge{display:inline-block;font-size:10px;padding:2px 7px;border-radius:3px;font-weight:600;letter-spacing:.04em;}
.suite-barrio-cb-badge-scanner{background:#fef3c7;color:#92400e;}
.suite-barrio-cb-badge-manual{background:#e0f2fe;color:#0369a1;}
.suite-barrio-cb-badge-gtm{background:#f0fdf4;color:#166534;}
.suite-barrio-cb-section{background:#fff;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:20px;overflow:hidden;}
.suite-barrio-cb-section-head{padding:14px 18px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-weight:700;font-size:13px;display:flex;justify-content:space-between;align-items:center;}
.suite-barrio-cb-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:16px 18px;}
.suite-barrio-cb-form-grid label{font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
.suite-barrio-cb-form-grid input,.suite-barrio-cb-form-grid select,.suite-barrio-cb-form-grid textarea{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;}
.suite-barrio-cb-form-full{grid-column:1/-1;}
.suite-barrio-cb-actions-row{padding:12px 18px;border-top:1px solid #e2e8f0;display:flex;gap:8px;align-items:center;}';
        wp_add_inline_style('suite-barrio-admin', $css);

        $nonce       = suite_barrio_cb_nonce();
        $last_scan        = SUITE_BARRIO_Scanner::get_results();
        $registered_names = array_column(SUITE_BARRIO_Service_Registry::get_services(), 'name');
        $scan_found       = array_map(function ($item) use ($registered_names) {
            $item['in_registry'] = in_array($item['name'], $registered_names, true);
            return $item;
        }, $last_scan['found'] ?? []);

        $js = '(function(){
    var ajax  = __AJAX_URL__;
    var nonce = __NONCE__;

    function post(action, data, cb) {
        var fd = new FormData();
        fd.append(\'action\', action); fd.append(\'nonce\', nonce);
        Object.keys(data || {}).forEach(function(k){ fd.append(k, data[k]); });
        fetch(ajax, {method:\'POST\', body:fd}).then(function(r){ return r.json(); }).then(cb);
    }

    /* ── Tabella servizi ── */

    function refreshServicesTable() {
        post(\'suite_barrio_cb_service_list\', {}, function(res){
            if (res.success) document.getElementById(\'suite-barrio-cb-services-table\').innerHTML = res.data.html;
            bindServicesTable();
        });
    }

    function bindServicesTable() {
        document.querySelectorAll(\'[data-suite-barrio-delete]\').forEach(function(btn){
            btn.addEventListener(\'click\', function(){
                if (!confirm(\'Eliminare questo servizio?\')) return;
                post(\'suite_barrio_cb_service_delete\', {id: btn.dataset.suiteBarrioDelete}, function(){ refreshServicesTable(); });
            });
        });
        document.querySelectorAll(\'[data-suite-barrio-toggle]\').forEach(function(tog){
            tog.addEventListener(\'change\', function(){
                post(\'suite_barrio_cb_service_toggle\', {id: tog.dataset.suiteBarrioToggle, active: tog.checked ? \'1\' : \'0\'}, function(){});
            });
        });
    }
    bindServicesTable();

    /* ── Form aggiungi servizio ── */

    document.getElementById(\'suite-barrio-cb-add-btn\').addEventListener(\'click\', function(){
        var name = document.getElementById(\'suite-barrio-cb-f-name\').value.trim();
        if (!name) { document.getElementById(\'suite-barrio-cb-add-msg\').textContent = \'Nome obbligatorio.\'; return; }
        post(\'suite_barrio_cb_service_add\', {
            name:        name,
            category:    document.getElementById(\'suite-barrio-cb-f-cat\').value,
            provider:    document.getElementById(\'suite-barrio-cb-f-provider\').value,
            privacy_url: document.getElementById(\'suite-barrio-cb-f-privacy\').value,
            cookies:     document.getElementById(\'suite-barrio-cb-f-cookies\').value,
            script:      document.getElementById(\'suite-barrio-cb-f-script\').value,
        }, function(res){
            document.getElementById(\'suite-barrio-cb-add-msg\').textContent = res.success ? \'Aggiunto.\' : (res.data || \'Errore.\');
            if (res.success) {
                [\'name\',\'provider\',\'privacy_url\',\'cookies\',\'script\'].forEach(function(id){
                    document.getElementById(\'suite-barrio-cb-f-\' + id).value = \'\';
                });
                refreshServicesTable();
            }
        });
    });

    /* ── Scanner ── */

    function escHtml(s) {
        return String(s).replace(/[&<>"\']/g, function(c){
            return {\'&\':\'&amp;\',\'<\':\'&lt;\',\'>\':\'&gt;\',\'"\':\'&quot;\',"\'":\'&#39;\'}[c];
        });
    }

    function buildScanTable(found) {
        if (!found.length) {
            return \'<p style="padding:16px 18px;color:#9ca3af;font-size:13px;">Nessun provider rilevato.</p>\';
        }
        var rows = found.map(function(f) {
            var badge = f.in_registry
                ? \'<span class="suite-barrio-cb-badge suite-barrio-cb-badge-manual">Nel registry</span>\'
                : \'<button class="suite-barrio-cp-btn suite-barrio-cp-btn-ghost" data-confirm="\' + escHtml(f.pattern) + \'" style="padding:4px 10px;font-size:12px;">+ Aggiungi</button>\';
            var gtmTag = f.via_gtm
                ? \' <span class="suite-barrio-cb-badge suite-barrio-cb-badge-gtm" title="Container \' + escHtml(f.via_gtm) + \'">via GTM</span>\'
                : \'\';
            return \'<tr>\'
                + \'<td>\' + escHtml(f.name) + gtmTag + \'</td>\'
                + \'<td>\' + escHtml(f.category) + \'</td>\'
                + \'<td>\' + escHtml(f.provider) + \'</td>\'
                + \'<td style="font-size:11px;">\' + escHtml((f.found_on || []).join(\', \')) + \'</td>\'
                + \'<td>\' + badge + \'</td>\'
                + \'</tr>\';
        }).join(\'\');
        return \'<table class="suite-barrio-cb-table">\'
            + \'<thead><tr><th>Provider</th><th>Categoria</th><th>Azienda</th><th>Trovato su</th><th></th></tr></thead>\'
            + \'<tbody>\' + rows + \'</tbody></table>\';
    }

    function bindScanConfirm() {
        document.querySelectorAll(\'#suite-barrio-cb-scan-table [data-confirm]\').forEach(function(btn){
            btn.addEventListener(\'click\', function(){
                btn.disabled = true;
                btn.textContent = \'…\';
                post(\'suite_barrio_cb_confirm_service\', {pattern: btn.dataset.confirm}, function(res){
                    if (res.success) {
                        var badge = document.createElement(\'span\');
                        badge.className = \'suite-barrio-cb-badge suite-barrio-cb-badge-manual\';
                        badge.textContent = \'Nel registry\';
                        btn.replaceWith(badge);
                        refreshServicesTable();
                    } else {
                        btn.disabled = false;
                        btn.textContent = \'+ Aggiungi\';
                        alert(res.data || \'Errore.\');
                    }
                });
            });
        });
    }

    var scanBtn    = document.getElementById(\'suite-barrio-cb-scan-btn\');
    var progressEl = document.getElementById(\'suite-barrio-cb-scan-progress\');
    var scanMsgEl  = document.getElementById(\'suite-barrio-cb-scan-msg\');
    var resultsBox = document.getElementById(\'suite-barrio-cb-scan-results\');
    var scanTable  = document.getElementById(\'suite-barrio-cb-scan-table\');

    // Renderizza i risultati dell\'ultima scansione già al caricamento pagina
    var initialFound = __INITIAL_FOUND__;
    if (initialFound.length) {
        scanTable.innerHTML = buildScanTable(initialFound);
        bindScanConfirm();
    }

    scanBtn.addEventListener(\'click\', function(){
        scanBtn.disabled = true;
        progressEl.style.display = \'block\';
        scanMsgEl.textContent = \'Scansione in corso…\';

        post(\'suite_barrio_cb_run_scan\', {}, function(res){
            scanBtn.disabled = false;
            if (!res.success) {
                scanMsgEl.textContent = \'Errore: \' + (res.data && res.data.message ? res.data.message : \'sconosciuto\');
                return;
            }
            var found = res.data.found || [];
            scanMsgEl.textContent = \'Completata — \' + res.data.pages_scanned + \' pagine, \' + found.length + \' provider trovati.\';
            document.getElementById(\'suite-barrio-cb-scan-subtitle\').textContent = found.length + \' trovati\';
            scanTable.innerHTML = buildScanTable(found);
            resultsBox.style.display = \'\';
            bindScanConfirm();
            refreshServicesTable();
        });
    });

})();';
        $js = strtr($js, [
            '__AJAX_URL__'     => wp_json_encode(admin_url('admin-ajax.php')),
            '__NONCE__'        => wp_json_encode($nonce),
            '__INITIAL_FOUND__' => wp_json_encode($scan_found),
        ]);

        wp_register_script('suite-barrio-cb-servizi', '', [], SUITE_BARRIO_VERSION, true);
        wp_enqueue_script('suite-barrio-cb-servizi');
        wp_add_inline_script('suite-barrio-cb-servizi', $js);
    }

    if ($tab === 'gtm') {
        $js = 'document.getElementById(\'suite-barrio-gtm-copy\').addEventListener(\'click\', function () {
    var text = document.getElementById(\'suite-barrio-gtm-snippet\').textContent;
    var btn  = document.getElementById(\'suite-barrio-gtm-copy\');
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function () {
            btn.textContent = \'Copiato!\';
            setTimeout(function () { btn.textContent = \'Copia\'; }, 2000);
        });
    } else {
        var ta = document.createElement(\'textarea\');
        ta.value = text;
        ta.style.position = \'fixed\'; ta.style.opacity = \'0\';
        document.body.appendChild(ta); ta.select();
        document.execCommand(\'copy\');
        document.body.removeChild(ta);
        btn.textContent = \'Copiato!\';
        setTimeout(function () { btn.textContent = \'Copia\'; }, 2000);
    }
});';
        wp_register_script('suite-barrio-cb-gtm', '', [], SUITE_BARRIO_VERSION, true);
        wp_enqueue_script('suite-barrio-cb-gtm');
        wp_add_inline_script('suite-barrio-cb-gtm', $js);
    }

    if ($tab === 'banner') {
        $css = '.suite-barrio-cb-banner-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:20px;}
.suite-barrio-cb-banner-grid label{font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;}
.suite-barrio-cb-banner-grid input[type=text],.suite-barrio-cb-banner-grid input[type=color],.suite-barrio-cb-banner-grid select{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;}
.suite-barrio-cb-banner-grid input[type=color]{height:38px;padding:2px 4px;cursor:pointer;}
.suite-barrio-cb-color-row{display:flex;gap:8px;align-items:center;}
.suite-barrio-cb-color-row input[type=text]{flex:1;}
.suite-barrio-cb-color-row input[type=color]{width:44px;flex-shrink:0;}';
        wp_add_inline_style('suite-barrio-admin', $css);

        $nonce = suite_barrio_cb_nonce();

        $color_sync_tpl = '(function(){
    var picker = document.getElementById(\'suite-barrio-cb-b-__KEY__\');
    var text   = document.getElementById(\'suite-barrio-cb-b-__KEY__-text\');
    picker.addEventListener(\'input\', function(){ text.value = picker.value; });
    text.addEventListener(\'change\', function(){ if (/^#[0-9a-f]{6}$/i.test(text.value)) picker.value = text.value; });
})();
';
        $color_sync_js = '';
        foreach (suite_barrio_cb_banner_colors() as [$key, , ]) {
            $color_sync_js .= str_replace('__KEY__', esc_js($key), $color_sync_tpl);
        }

        $js = '(function(){
    var ajax  = __AJAX_URL__;
    var nonce = __NONCE__;

    // Sync color pickers ↔ text inputs
    __COLOR_SYNC__
    document.getElementById(\'suite-barrio-cb-banner-save\').addEventListener(\'click\', function(){
        var fields = [\'text_title\',\'text_body\',\'text_accept\',\'text_reject\',\'text_manage\',\'position\',\'color_bg\',\'color_text\',\'color_accent\',\'widget_pos\'];
        var fd = new FormData();
        fd.append(\'action\',\'suite_barrio_cb_banner_save\'); fd.append(\'nonce\', nonce);
        fields.forEach(function(f){
            var el = document.getElementById(\'suite-barrio-cb-b-\'+f+\'-text\') || document.getElementById(\'suite-barrio-cb-b-\'+f);
            if (el) fd.append(f, el.value);
        });
        fd.append(\'show_widget\', document.getElementById(\'suite-barrio-cb-b-show_widget\').checked ? \'1\' : \'0\');
        fetch(ajax,{method:\'POST\',body:fd}).then(function(r){return r.json();}).then(function(res){
            document.getElementById(\'suite-barrio-cb-banner-msg\').textContent = res.success ? \'Salvato.\' : \'Errore.\';
        });
    });
})();';
        $js = strtr($js, [
            '__AJAX_URL__'    => wp_json_encode(admin_url('admin-ajax.php')),
            '__NONCE__'       => wp_json_encode($nonce),
            '__COLOR_SYNC__'  => $color_sync_js,
        ]);

        wp_register_script('suite-barrio-cb-banner', '', [], SUITE_BARRIO_VERSION, true);
        wp_enqueue_script('suite-barrio-cb-banner');
        wp_add_inline_script('suite-barrio-cb-banner', $js);
    }
});

/* ── Helper: definizione colori configurabili del banner (condivisa fra
   il render del form e la generazione dello script di sync in JS) ── */
function suite_barrio_cb_banner_colors(): array {
    return [
        ['color_bg',    'Sfondo banner',      '#1a1a2e'],
        ['color_text',  'Testo',              '#FFFFFF'],
        ['color_accent','Bottone primario',   '#e94560'],
    ];
}

/* ── Registra tab nell'admin di cookie-privacy ── */
add_filter('suite_barrio_cp_tabs', function (array $tabs): array {
    $new = [
        'servizi'  => ['label' => 'Servizi',             'icon' => 'admin-plugins'],
        'banner'   => ['label' => 'Banner',              'icon' => 'megaphone'],
        'gtm'      => ['label' => 'Google Tag Manager',  'icon' => 'tag'],
        'consensi' => ['label' => 'Consensi',            'icon' => 'privacy'],
    ];
    // Inserisci dopo 'generate', prima di 'log'
    $result = [];
    foreach ($tabs as $key => $def) {
        $result[$key] = $def;
        if ($key === 'generate') {
            foreach ($new as $nk => $nd) $result[$nk] = $nd;
        }
    }
    return $result;
});

/* ── Render tab ── */
add_action('suite_barrio_cp_render_tab_servizi',  'suite_barrio_cb_render_tab_servizi');
add_action('suite_barrio_cp_render_tab_banner',   'suite_barrio_cb_render_tab_banner');
add_action('suite_barrio_cp_render_tab_gtm',      'suite_barrio_cb_render_tab_gtm');
add_action('suite_barrio_cp_render_tab_consensi', 'suite_barrio_cb_render_tab_consensi');

/* ── AJAX: nonce condiviso ── */
function suite_barrio_cb_nonce(): string {
    return wp_create_nonce('suite_barrio_cb_nonce');
}

function suite_barrio_cb_check_ajax(): void {
    check_ajax_referer('suite_barrio_cb_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permesso negato.', 403);
}

/* ── AJAX: aggiungi servizio ── */
add_action('wp_ajax_suite_barrio_cb_service_add', function () {
    suite_barrio_cb_check_ajax();
    $service = [
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
        'name'        => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
        'category'    => sanitize_key($_POST['category'] ?? 'necessary'),
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
        'provider'    => sanitize_text_field(wp_unslash($_POST['provider'] ?? '')),
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
        'privacy_url' => esc_url_raw(wp_unslash($_POST['privacy_url'] ?? '')),
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
        'cookies'     => array_filter(array_map('trim', explode(',', sanitize_text_field(wp_unslash($_POST['cookies'] ?? ''))))),
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione; campo script contiene markup/script intenzionale, sanitize_text_field lo corromperebbe
        'script'      => wp_unslash($_POST['script'] ?? ''),
        'active'      => true,
        'source'      => 'manual',
    ];
    if (empty($service['name'])) wp_send_json_error('Nome obbligatorio.');
    $id = SUITE_BARRIO_Service_Registry::add_service($service);
    wp_send_json_success(['id' => $id]);
});

/* ── AJAX: aggiorna servizio ── */
add_action('wp_ajax_suite_barrio_cb_service_update', function () {
    suite_barrio_cb_check_ajax();
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
    $id = sanitize_key($_POST['id'] ?? '');
    if (!$id) wp_send_json_error('ID mancante.');
    $data = [
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
        'name'        => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
        'category'    => sanitize_key($_POST['category'] ?? 'necessary'),
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
        'provider'    => sanitize_text_field(wp_unslash($_POST['provider'] ?? '')),
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
        'privacy_url' => esc_url_raw(wp_unslash($_POST['privacy_url'] ?? '')),
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
        'cookies'     => array_filter(array_map('trim', explode(',', sanitize_text_field(wp_unslash($_POST['cookies'] ?? ''))))),
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione; campo script contiene markup/script intenzionale, sanitize_text_field lo corromperebbe
        'script'      => wp_unslash($_POST['script'] ?? ''),
    ];
    SUITE_BARRIO_Service_Registry::update_service($id, $data);
    wp_send_json_success();
});

/* ── AJAX: elimina servizio ── */
add_action('wp_ajax_suite_barrio_cb_service_delete', function () {
    suite_barrio_cb_check_ajax();
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
    $id = sanitize_key($_POST['id'] ?? '');
    if (!$id) wp_send_json_error('ID mancante.');
    SUITE_BARRIO_Service_Registry::delete_service($id);
    wp_send_json_success();
});

/* ── AJAX: toggle attivo/inattivo ── */
add_action('wp_ajax_suite_barrio_cb_service_toggle', function () {
    suite_barrio_cb_check_ajax();
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
    $id = sanitize_key($_POST['id'] ?? '');
    if (!$id) wp_send_json_error('ID mancante.');
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
    $active = !empty($_POST['active']) && $_POST['active'] !== 'false';
    SUITE_BARRIO_Service_Registry::toggle_service($id, $active);
    wp_send_json_success();
});

/* ── AJAX: salva banner config ── */
add_action('wp_ajax_suite_barrio_cb_banner_save', function () {
    suite_barrio_cb_check_ajax();
    $allowed_fields = ['position','color_bg','color_text','color_accent','text_title','text_body','text_accept','text_reject','text_manage','widget_pos'];
    $config = [];
    foreach ($allowed_fields as $f) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
        $config[$f] = sanitize_text_field(wp_unslash($_POST[$f] ?? ''));
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
    $config['show_widget'] = !empty($_POST['show_widget']);
    update_option('wpb_cb_banner_config', $config, false);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_send_json_success();
});

/* ── Render: tab Servizi (include scanner integrato) ── */
function suite_barrio_cb_render_tab_servizi(): void {
    $categories  = SUITE_BARRIO_Service_Registry::get_categories();
    $services    = SUITE_BARRIO_Service_Registry::get_services();
    $next_cron   = wp_next_scheduled(SUITE_BARRIO_Cron::HOOK);
    $last_scan        = SUITE_BARRIO_Scanner::get_results();
    $registered_names = array_column(SUITE_BARRIO_Service_Registry::get_services(), 'name');
    $scan_found       = array_map(function ($item) use ($registered_names) {
        $item['in_registry'] = in_array($item['name'], $registered_names, true);
        return $item;
    }, $last_scan['found'] ?? []);
    $has_results = !empty($scan_found);

    $cat_options = '';
    foreach ($categories as $id => $cat) {
        $cat_options .= '<option value="' . esc_attr($id) . '">' . esc_html($cat['name']) . '</option>';
    }
    ?>
    <!-- Aggiungi servizio -->
    <div class="suite-barrio-cb-section">
        <div class="suite-barrio-cb-section-head"><span>Aggiungi servizio</span></div>
        <div class="suite-barrio-cb-form-grid">
            <div>
                <label>Nome *</label>
                <input type="text" id="suite-barrio-cb-f-name" placeholder="Google Analytics 4">
            </div>
            <div>
                <label>Categoria</label>
                <select id="suite-barrio-cb-f-cat"><?php echo wp_kses_post($cat_options); ?></select>
            </div>
            <div>
                <label>Provider</label>
                <input type="text" id="suite-barrio-cb-f-provider" placeholder="Google LLC">
            </div>
            <div>
                <label>Privacy URL</label>
                <input type="url" id="suite-barrio-cb-f-privacy" placeholder="https://policies.google.com/privacy">
            </div>
            <div>
                <label>Cookie (separati da virgola)</label>
                <input type="text" id="suite-barrio-cb-f-cookies" placeholder="_ga, _gid, _ga_*">
            </div>
            <div class="suite-barrio-cb-form-full">
                <label>Script (opzionale)</label>
                <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- testo placeholder statico, non uno script realmente inline ?>
                <textarea id="suite-barrio-cb-f-script" rows="2" style="font-family:monospace;font-size:12px;" placeholder="<script src='...'></script>"></textarea>
            </div>
        </div>
        <div class="suite-barrio-cb-actions-row">
            <button class="suite-barrio-cp-btn suite-barrio-cp-btn-primary" id="suite-barrio-cb-add-btn">Aggiungi servizio</button>
            <span id="suite-barrio-cb-add-msg" style="font-size:13px;color:#6b7280;"></span>
        </div>
    </div>

    <!-- Scansione cookie -->
    <div class="suite-barrio-cb-section">
        <div class="suite-barrio-cb-section-head">
            <span>Scansione cookie</span>
            <button class="suite-barrio-cp-btn suite-barrio-cp-btn-primary" id="suite-barrio-cb-scan-btn">Avvia scansione</button>
        </div>
        <div style="padding:12px 18px;font-size:13px;color:#6b7280;">
            <?php if ($next_cron): ?>
                Prossima automatica: <strong><?php echo esc_html(date_i18n('d/m/Y H:i', $next_cron)); ?></strong>
            <?php else: ?>
                Nessuna scansione schedulata.
            <?php endif; ?>
            <?php if (!empty($last_scan['ts'])): ?>
                &mdash; Ultima: <strong><?php echo esc_html(date_i18n('d/m/Y H:i', $last_scan['ts'])); ?></strong>
                (<?php echo intval($last_scan['pages_scanned'] ?? 0); ?> pagine)
            <?php endif; ?>
        </div>
        <div id="suite-barrio-cb-scan-progress" style="display:none;padding:0 18px 12px;font-size:13px;color:#374151;">
            <span id="suite-barrio-cb-scan-msg"></span>
        </div>
    </div>

    <!-- Risultati scansione -->
    <div class="suite-barrio-cb-section" id="suite-barrio-cb-scan-results" <?php echo $has_results ? '' : 'style="display:none"'; ?>>
        <div class="suite-barrio-cb-section-head">
            <span>Provider rilevati</span>
            <span id="suite-barrio-cb-scan-subtitle" style="font-weight:400;color:#6b7280;font-size:12px;">
                <?php if ($has_results): ?><?php echo count($scan_found); ?> trovati<?php endif; ?>
            </span>
        </div>
        <div id="suite-barrio-cb-scan-table"></div>
    </div>

    <!-- Servizi registrati -->
    <div class="suite-barrio-cb-section">
        <div class="suite-barrio-cb-section-head"><span>Servizi registrati</span></div>
        <div id="suite-barrio-cb-services-table">
            <?php echo wp_kses_post(suite_barrio_cb_services_table_html($services, $categories)); ?>
        </div>
    </div>
    <?php
}

/* ── Helper: HTML tabella servizi (riutilizzato da AJAX) ── */
function suite_barrio_cb_services_table_html(array $services, array $categories): string {
    if (empty($services)) {
        return '<p style="padding:16px 18px;color:#9ca3af;font-size:13px;">Nessun servizio registrato. Aggiungine uno sopra o avvia una scansione.</p>';
    }
    ob_start();
    ?>
    <table class="suite-barrio-cb-table">
        <thead><tr>
            <th>Attivo</th><th>Nome</th><th>Categoria</th><th>Provider</th><th>Cookie</th><th>Fonte</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($services as $s): ?>
        <tr>
            <td><input type="checkbox" data-suite-barrio-toggle="<?php echo esc_attr($s['id']); ?>" <?php checked(!empty($s['active'])); ?>></td>
            <td><?php echo esc_html($s['name']); ?></td>
            <td><?php echo esc_html($categories[$s['category']]['name'] ?? $s['category']); ?></td>
            <td><?php echo esc_html($s['provider']); ?></td>
            <td style="font-size:11px;font-family:monospace;"><?php echo esc_html(implode(', ', $s['cookies'])); ?></td>
            <td><span class="suite-barrio-cb-badge suite-barrio-cb-badge-<?php echo esc_attr($s['source'] ?? 'manual'); ?>"><?php echo $s['source'] === 'scanner' ? 'Scanner' : 'Manuale'; ?></span></td>
            <td><button class="suite-barrio-cp-btn suite-barrio-cp-btn-ghost" data-suite-barrio-delete="<?php echo esc_attr($s['id']); ?>" style="padding:4px 10px;font-size:12px;">Elimina</button></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

/* ── AJAX: lista servizi (per refresh tabella) ── */
add_action('wp_ajax_suite_barrio_cb_service_list', function () {
    suite_barrio_cb_check_ajax();
    $services   = SUITE_BARRIO_Service_Registry::get_services();
    $categories = SUITE_BARRIO_Service_Registry::get_categories();
    wp_send_json_success(['html' => suite_barrio_cb_services_table_html($services, $categories)]);
});

/* ── AJAX: avvia scansione manuale (sincrona con time limit esteso) ── */
add_action('wp_ajax_suite_barrio_cb_run_scan', function () {
    suite_barrio_cb_check_ajax();
    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- necessario per operazioni batch lunghe
    if (function_exists('set_time_limit')) @set_time_limit(120);
    $results = SUITE_BARRIO_Scanner::run();
    if (isset($results['error'])) {
        wp_send_json_error(['message' => $results['error']]);
    }
    wp_send_json_success($results);
});

/* ── AJAX: stato scansione in corso (polling) ── */
add_action('wp_ajax_suite_barrio_cb_scan_status', function () {
    suite_barrio_cb_check_ajax();
    $progress = get_transient(SUITE_BARRIO_Scanner::PROGRESS_TRANSIENT);
    if ($progress === false) {
        $results = SUITE_BARRIO_Scanner::get_results();
        wp_send_json_success(['status' => empty($results) ? 'idle' : 'done', 'results' => $results]);
        return;
    }
    wp_send_json_success($progress);
});

/* ── AJAX: promuovi servizio scanner → registry ── */
add_action('wp_ajax_suite_barrio_cb_confirm_service', function () {
    suite_barrio_cb_check_ajax();
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verificato da suite_barrio_cb_check_ajax() a inizio funzione
    $pattern = sanitize_text_field(wp_unslash($_POST['pattern'] ?? ''));
    if (!$pattern) wp_send_json_error('Pattern mancante.');
    // Cerca il provider nei risultati della scansione
    $results = SUITE_BARRIO_Scanner::get_results();
    $found   = array_filter($results['found'] ?? [], fn($f) => $f['pattern'] === $pattern);
    $item    = reset($found);
    if (!$item) wp_send_json_error('Provider non trovato nei risultati scan.');
    $id = SUITE_BARRIO_Service_Registry::add_service([
        'name'        => $item['name'],
        'category'    => $item['category'],
        'provider'    => $item['provider'],
        'privacy_url' => $item['privacy_url'],
        'cookies'     => [],
        'script'      => '',
        'active'      => true,
        'source'      => 'scanner',
    ]);
    wp_send_json_success(['id' => $id]);
});

/* ── Render: tab GTM ── */
function suite_barrio_cb_render_tab_gtm(): void {
    $engine_url = suite_barrio_cb_engine_url();
    $rest_base  = rtrim(rest_url(), '/');
    $snippet    = suite_barrio_cb_build_gtm_snippet($engine_url, $rest_base);
    ?>
    <div class="suite-barrio-cb-section">
        <div class="suite-barrio-cb-section-head">Snippet GTM &mdash; Custom HTML Tag</div>
        <div style="padding:16px 18px;">
            <p style="font-size:13px;color:#374151;margin:0 0 12px;">
                Copia questo codice e incollalo in GTM come <strong>Custom HTML Tag</strong> con trigger
                <strong style="color:#b91c1c;">Consent Initialization - All Pages</strong> (non "All Pages").<br>
                Questo trigger speciale garantisce che il consenso sia impostato <em>prima</em> di qualsiasi altro tag,
                incluso Google Analytics.
            </p>
            <div style="position:relative;">
                <pre id="suite-barrio-gtm-snippet" style="background:#1e293b;color:#e2e8f0;padding:16px 16px 16px 16px;border-radius:8px;font-size:11px;line-height:1.65;overflow-x:auto;margin:0;white-space:pre-wrap;word-break:break-all;"><?php echo esc_html($snippet); ?></pre>
                <button id="suite-barrio-gtm-copy" type="button" style="position:absolute;top:10px;right:10px;background:#334155;color:#e2e8f0;border:none;padding:5px 12px;border-radius:5px;font-size:11px;cursor:pointer;">Copia</button>
            </div>
        </div>
    </div>

    <div class="suite-barrio-cb-section">
        <div class="suite-barrio-cb-section-head">Come configurare GTM &mdash; passo per passo</div>
        <div style="padding:16px 18px;font-size:13px;color:#374151;line-height:1.8;">
            <ol style="margin:0;padding-left:20px;">
                <li>Apri <strong>Google Tag Manager</strong> &rarr; il tuo container.</li>
                <li>Vai su <strong>Tag &rarr; Nuovo</strong> &rarr; tipo <em>Custom HTML</em>.</li>
                <li>Incolla lo snippet sopra nel campo HTML.</li>
                <li>Assegna trigger: <strong style="color:#b91c1c;">Consent Initialization - All Pages</strong>
                    (Trigger &rarr; Nuovo &rarr; tipo "Consenso" &rarr; "Initialization - All Pages").<br>
                    <span style="font-size:12px;color:#6b7280;">Questo trigger è l&rsquo;unico che garantisce l&rsquo;esecuzione
                    <em>prima</em> di tutti gli altri tag GTM.</span></li>
                <li>Assegna un nome al tag, es. <code>Suite Barrio &ndash; Consent Engine</code>.</li>
                <li>Salva e poi <strong>Pubblica</strong> il container.</li>
            </ol>
            <hr style="margin:16px 0;border:none;border-top:1px solid #e2e8f0;">
            <p style="margin:0 0 6px;font-weight:700;">Come funziona (Google Consent Mode v2)</p>
            <p style="margin:0 0 10px;color:#374151;font-size:12px;line-height:1.6;">
                Lo snippet legge il cookie di consenso salvato e imposta immediatamente lo stato GTM corretto
                senza aspettare script asincroni. Per un <strong>nuovo visitatore</strong> tutti i tipi non tecnici
                partono come <code>denied</code>; per un <strong>visitatore di ritorno</strong> lo stato precedente
                è applicato al primo tick della pagina.<br>
                Ogni volta che l&rsquo;utente salva le preferenze il consent engine chiama automaticamente
                <code>gtag("consent", "update", &hellip;)</code> e pubblica l&rsquo;evento
                <code>wpb_consent_update</code> nel dataLayer.
            </p>

            <p style="margin:0 0 6px;font-weight:700;">Tag Google (GA4, Google Ads) — nessuna configurazione extra</p>
            <p style="margin:0 0 10px;color:#374151;font-size:12px;line-height:1.6;">
                I tag nativi Google leggono automaticamente i segnali di consenso dal dataLayer.
                Vedrai GA4 come &ldquo;attivato&rdquo; nel pannello GTM anche senza consenso: &egrave; corretto.
                In modalit&agrave; <code>denied</code> GA4 invia solo ping anonimi aggregati (senza cookie,
                senza identificatori). Verifica solo che il tag GA4 abbia
                <em>Consent Settings</em> impostato su <strong>Basic</strong> (non su &ldquo;Off&rdquo;).
            </p>

            <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:10px 12px;margin-bottom:10px;">
                <p style="margin:0 0 4px;font-size:12px;font-weight:700;color:#92400e;">Tag non-Google (Meta Pixel, Tawk.to, LinkedIn ecc.)</p>
                <p style="margin:0;font-size:12px;color:#78350f;line-height:1.6;">
                    Questi tag non supportano nativamente la Consent Mode. Hai due opzioni:<br>
                    <strong>A) Trigger condizionale</strong> — crea un trigger GTM che si attiva solo
                    sull&rsquo;evento <code>wpb_consent_update</code> con la variabile dataLayer
                    <code>wpb_consent.marketing</code> = <code>true</code>.<br>
                    <strong>B) Consent requirements</strong> — nel tag &rarr; <em>Impostazioni avanzate</em>
                    &rarr; <em>Consent Settings</em> &rarr; <strong>Require additional consent for tag to fire</strong>
                    &rarr; <code>ad_storage</code>. Il tag non si attiver&agrave; affatto senza consenso.
                </p>
            </div>
            <hr style="margin:16px 0;border:none;border-top:1px solid #e2e8f0;">
            <p style="margin:0 0 6px;font-weight:700;">Utilizzo su siti Next.js</p>
            <p style="margin:0 0 12px;color:#6b7280;font-size:12px;line-height:1.6;">
                Se il tuo sito Next.js ha gi&agrave; GTM installato, questo tag &egrave; sufficiente: non serve
                caricare il consent engine separatamente. GTM lo distribuisce su tutte le pagine.<br>
                &nbsp;&nbsp;&bull;&nbsp; Assicurati che il tag Suite Barrio si attivi <strong>prima</strong> del tag
                GTM principale (Google Analytics, Meta Pixel, ecc.) usando Tag Sequencing o Priority.
            </p>
            <?php
            $step0_nextjs = "// layout.tsx — dentro <head>, PRIMA del tag GTM\n"
                . "// Legge il cookie wpb_consent e imposta i default GTM in modo sincrono.\n"
                . "// Senza questo blocco GTM può attivare GA4/Ads PRIMA che il consenso venga letto.\n"
                . "<script dangerouslySetInnerHTML={{__html: `\n"
                . "(function(){\n"
                . "  var g='granted',d='denied',c=null;\n"
                . "  try{var m=document.cookie.match(/(?:^|;)\\s*wpb_consent=([^;]*)/);if(m)c=JSON.parse(decodeURIComponent(m[1]));}catch(e){}\n"
                . "  window.dataLayer=window.dataLayer||[];\n"
                . "  function gtag(){dataLayer.push(arguments);}\n"
                . "  gtag('consent','default',{\n"
                . "    security_storage:g,\n"
                . "    functionality_storage:(c&&c.functional)?g:d,\n"
                . "    personalization_storage:(c&&c.functional)?g:d,\n"
                . "    analytics_storage:(c&&c.analytics)?g:d,\n"
                . "    ad_storage:(c&&c.marketing)?g:d,\n"
                . "    ad_user_data:(c&&c.marketing)?g:d,\n"
                . "    ad_personalization:(c&&c.marketing)?g:d,\n"
                . "  });\n"
                . "})();\n"
                . "`}} />";
            ?>
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 16px;">
                <p style="margin:0 0 6px;font-size:13px;font-weight:700;color:#991b1b;">&#9888; Passo obbligatorio se usi GTM su Next.js</p>
                <p style="margin:0 0 10px;font-size:12px;color:#7f1d1d;line-height:1.6;">
                    Aggiungi questo blocco <strong>dentro <code>&lt;head&gt;</code>, prima del tag GTM</strong>.
                    Legge il cookie di consenso in modo sincrono e imposta i default GTM
                    prima che qualsiasi tag (GA4, Ads, Pixel) venga valutato.
                    Senza questo, GTM pu&ograve; attivare i tag e scrivere cookie prima ancora che il banner sia mostrato.
                </p>
                <div style="position:relative;">
                    <pre id="suite-barrio-gtm-step0" style="background:#1e293b;color:#e2e8f0;padding:14px 16px;border-radius:8px;font-size:11px;line-height:1.6;overflow-x:auto;margin:0;white-space:pre;"><?php echo esc_html($step0_nextjs); ?></pre>
                    <button type="button"
                        style="position:absolute;top:8px;right:8px;padding:4px 10px;border-radius:5px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#e2e8f0;font-size:11px;cursor:pointer;"
                        onclick="(function(b){var t=b.closest('div').querySelector('pre').textContent;navigator.clipboard.writeText(t).then(function(){b.textContent='Copiato!';setTimeout(function(){b.textContent='Copia';},1800);});})(this)">
                        Copia
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/* ── Render: tab Banner (Task 5) ── */
function suite_barrio_cb_render_tab_banner(): void {
    $cfg   = get_option('wpb_cb_banner_config', []);
    $f = function(string $key, string $default) use ($cfg): string {
        return esc_attr($cfg[$key] ?? $default);
    };
    ?>
    <div class="suite-barrio-cb-section">
        <div class="suite-barrio-cb-section-head">Testi e comportamento</div>
        <div class="suite-barrio-cb-banner-grid">
            <div>
                <label>Titolo banner</label>
                <input type="text" id="suite-barrio-cb-b-text_title" value="<?php echo $f('text_title','Utilizziamo i cookie'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- valore già escapato con esc_attr() dentro la closure $f() definita sopra ?>">
            </div>
            <div>
                <label>Testo breve</label>
                <input type="text" id="suite-barrio-cb-b-text_body" value="<?php echo $f('text_body','Usiamo cookie per migliorare la tua esperienza...'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- valore già escapato con esc_attr() dentro la closure $f() definita sopra ?>">
            </div>
            <div>
                <label>Label "Accetta tutti"</label>
                <input type="text" id="suite-barrio-cb-b-text_accept" value="<?php echo $f('text_accept','Accetta tutti'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- valore già escapato con esc_attr() dentro la closure $f() definita sopra ?>">
            </div>
            <div>
                <label>Label "Rifiuta tutti"</label>
                <input type="text" id="suite-barrio-cb-b-text_reject" value="<?php echo $f('text_reject','Rifiuta tutti'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- valore già escapato con esc_attr() dentro la closure $f() definita sopra ?>">
            </div>
            <div>
                <label>Label "Gestisci preferenze"</label>
                <input type="text" id="suite-barrio-cb-b-text_manage" value="<?php echo $f('text_manage','Gestisci preferenze'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- valore già escapato con esc_attr() dentro la closure $f() definita sopra ?>">
            </div>
            <div>
                <label>Posizione banner</label>
                <select id="suite-barrio-cb-b-position">
                    <?php foreach (['bottom'=>'In basso al centro','bottom-left'=>'In basso a sinistra','bottom-right'=>'In basso a destra'] as $v=>$l): ?>
                    <option value="<?php echo esc_attr($v); ?>" <?php selected(($cfg['position']??'bottom'),$v); ?>><?php echo esc_html($l); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="suite-barrio-cb-section">
        <div class="suite-barrio-cb-section-head">Colori</div>
        <div class="suite-barrio-cb-banner-grid">
            <?php
            foreach (suite_barrio_cb_banner_colors() as [$key, $label, $default]): ?>
            <div>
                <label><?php echo esc_html($label); ?></label>
                <div class="suite-barrio-cb-color-row">
                    <input type="text" id="suite-barrio-cb-b-<?php echo esc_attr($key); ?>-text" value="<?php echo $f($key, $default); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- valore già escapato con esc_attr() dentro la closure $f() definita sopra ?>">
                    <input type="color" id="suite-barrio-cb-b-<?php echo esc_attr($key); ?>" value="<?php echo $f($key, $default); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- valore già escapato con esc_attr() dentro la closure $f() definita sopra ?>">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="suite-barrio-cb-section">
        <div class="suite-barrio-cb-section-head">Widget floating</div>
        <div class="suite-barrio-cb-banner-grid">
            <div>
                <label>
                    <input type="checkbox" id="suite-barrio-cb-b-show_widget" <?php checked($cfg['show_widget'] ?? true); ?>>
                    Mostra widget per riaprire le preferenze
                </label>
            </div>
            <div>
                <label>Posizione widget</label>
                <select id="suite-barrio-cb-b-widget_pos">
                    <option value="bottom-right" <?php selected(($cfg['widget_pos']??'bottom-left'),'bottom-right'); ?>>In basso a destra</option>
                    <option value="bottom-left"  <?php selected(($cfg['widget_pos']??'bottom-left'),'bottom-left'); ?>>In basso a sinistra</option>
                </select>
            </div>
        </div>
    </div>

    <div class="suite-barrio-cb-actions-row" style="margin-bottom:0;">
        <button class="suite-barrio-cp-btn suite-barrio-cp-btn-primary" id="suite-barrio-cb-banner-save">Salva configurazione</button>
        <span id="suite-barrio-cb-banner-msg" style="line-height:2;font-size:13px;color:#6b7280;"></span>
    </div>
    <?php
}

/* ── Render: tab Consensi — export CSV del log ── */
function suite_barrio_cb_render_tab_consensi(): void {
    $today = current_time('Y-m-d');
    $from  = wp_date('Y-m-d', strtotime('-30 days', strtotime($today)));
    $nonce = wp_create_nonce('suite_barrio_cb_export_consent_log');
    ?>
    <div class="suite-barrio-cb-section">
        <div class="suite-barrio-cb-section-head"><span>Esporta log dei consensi</span></div>
        <div style="padding:16px 18px;">
            <p style="font-size:13px;color:#374151;margin:0 0 16px;">
                Scarica in CSV tutti gli eventi di consenso (accetta/rifiuta/preferenze) registrati
                dai visitatori nell'intervallo di date selezionato.
            </p>
            <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                <input type="hidden" name="action" value="suite_barrio_cb_export_consent_log">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                <div>
                    <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;">Da</label>
                    <input type="date" name="from" value="<?php echo esc_attr($from); ?>" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;">A</label>
                    <input type="date" name="to" value="<?php echo esc_attr($today); ?>" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                </div>
                <button type="submit" class="suite-barrio-cp-btn suite-barrio-cp-btn-primary">Esporta CSV</button>
            </form>
        </div>
    </div>
    <?php
}

/* ── Export CSV del log consensi ── */
add_action('admin_post_suite_barrio_cb_export_consent_log', function () {
    if (!current_user_can('manage_options')) wp_die('Permesso negato.', 403);
    check_admin_referer('suite_barrio_cb_export_consent_log');

    $from = sanitize_text_field(wp_unslash($_GET['from'] ?? ''));
    $to   = sanitize_text_field(wp_unslash($_GET['to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = wp_date('Y-m-d', strtotime('-30 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = wp_date('Y-m-d');
    if ($from > $to) [$from, $to] = [$to, $from];

    $rows = SUITE_BARRIO_Consent_Log::get_range($from, $to);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="consensi-' . $from . '_' . $to . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['id'],
            $row['status'],
            $row['ip_address'],
            $row['created_at'],
            $row['url'],
            $row['user_agent'],
            $row['categories'],
            $row['consent_text'],
        ]);
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- stream php://output per download diretto del CSV in risposta HTTP, non un file reale gestibile con WP_Filesystem
    fclose($out);
    exit;
});
