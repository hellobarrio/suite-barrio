<?php
if (!defined('ABSPATH')) exit;

/* ── Registra tab nell'admin di cookie-privacy ── */
add_filter('wpb_cp_tabs', function (array $tabs): array {
    $new = [
        'servizi' => ['label' => 'Servizi',             'icon' => 'admin-plugins'],
        'banner'  => ['label' => 'Banner',              'icon' => 'megaphone'],
        'gtm'     => ['label' => 'Google Tag Manager',  'icon' => 'tag'],
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
add_action('wpb_cp_render_tab_servizi', 'wpb_cb_render_tab_servizi');
add_action('wpb_cp_render_tab_banner',  'wpb_cb_render_tab_banner');
add_action('wpb_cp_render_tab_gtm',     'wpb_cb_render_tab_gtm');

/* ── AJAX: nonce condiviso ── */
function wpb_cb_nonce(): string {
    return wp_create_nonce('wpb_cb_nonce');
}

function wpb_cb_check_ajax(): void {
    check_ajax_referer('wpb_cb_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permesso negato.', 403);
}

/* ── AJAX: aggiungi servizio ── */
add_action('wp_ajax_wpb_cb_service_add', function () {
    wpb_cb_check_ajax();
    $service = [
        'name'        => sanitize_text_field($_POST['name'] ?? ''),
        'category'    => sanitize_key($_POST['category'] ?? 'necessary'),
        'provider'    => sanitize_text_field($_POST['provider'] ?? ''),
        'privacy_url' => esc_url_raw($_POST['privacy_url'] ?? ''),
        'cookies'     => array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['cookies'] ?? '')))),
        'script'      => wp_unslash($_POST['script'] ?? ''),
        'active'      => true,
        'source'      => 'manual',
    ];
    if (empty($service['name'])) wp_send_json_error('Nome obbligatorio.');
    $id = WPB_Service_Registry::add_service($service);
    wp_send_json_success(['id' => $id]);
});

/* ── AJAX: aggiorna servizio ── */
add_action('wp_ajax_wpb_cb_service_update', function () {
    wpb_cb_check_ajax();
    $id = sanitize_key($_POST['id'] ?? '');
    if (!$id) wp_send_json_error('ID mancante.');
    $data = [
        'name'        => sanitize_text_field($_POST['name'] ?? ''),
        'category'    => sanitize_key($_POST['category'] ?? 'necessary'),
        'provider'    => sanitize_text_field($_POST['provider'] ?? ''),
        'privacy_url' => esc_url_raw($_POST['privacy_url'] ?? ''),
        'cookies'     => array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['cookies'] ?? '')))),
        'script'      => wp_unslash($_POST['script'] ?? ''),
    ];
    WPB_Service_Registry::update_service($id, $data);
    wp_send_json_success();
});

/* ── AJAX: elimina servizio ── */
add_action('wp_ajax_wpb_cb_service_delete', function () {
    wpb_cb_check_ajax();
    $id = sanitize_key($_POST['id'] ?? '');
    if (!$id) wp_send_json_error('ID mancante.');
    WPB_Service_Registry::delete_service($id);
    wp_send_json_success();
});

/* ── AJAX: toggle attivo/inattivo ── */
add_action('wp_ajax_wpb_cb_service_toggle', function () {
    wpb_cb_check_ajax();
    $id = sanitize_key($_POST['id'] ?? '');
    if (!$id) wp_send_json_error('ID mancante.');
    $active = !empty($_POST['active']) && $_POST['active'] !== 'false';
    WPB_Service_Registry::toggle_service($id, $active);
    wp_send_json_success();
});

/* ── AJAX: salva banner config ── */
add_action('wp_ajax_wpb_cb_banner_save', function () {
    wpb_cb_check_ajax();
    $allowed_fields = ['position','color_bg','color_text','color_accent','text_title','text_body','text_accept','text_reject','text_manage','widget_pos'];
    $config = [];
    foreach ($allowed_fields as $f) {
        $config[$f] = sanitize_text_field($_POST[$f] ?? '');
    }
    $config['show_widget'] = !empty($_POST['show_widget']);
    update_option('wpb_cb_banner_config', $config, false);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_send_json_success();
});

/* ── Render: tab Servizi (include scanner integrato) ── */
function wpb_cb_render_tab_servizi(): void {
    $categories  = WPB_Service_Registry::get_categories();
    $services    = WPB_Service_Registry::get_services();
    $nonce       = wpb_cb_nonce();
    $next_cron   = wp_next_scheduled(WPB_Cron::HOOK);
    $last_scan        = WPB_Scanner::get_results();
    $registered_names = array_column(WPB_Service_Registry::get_services(), 'name');
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
    <style>
    .wpb-cb-table{width:100%;border-collapse:collapse;font-size:13px;}
    .wpb-cb-table th{text-align:left;padding:10px 12px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b;}
    .wpb-cb-table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
    .wpb-cb-table tr:last-child td{border-bottom:none;}
    .wpb-cb-badge{display:inline-block;font-size:10px;padding:2px 7px;border-radius:3px;font-weight:600;letter-spacing:.04em;}
    .wpb-cb-badge-scanner{background:#fef3c7;color:#92400e;}
    .wpb-cb-badge-manual{background:#e0f2fe;color:#0369a1;}
    .wpb-cb-badge-gtm{background:#f0fdf4;color:#166534;}
    .wpb-cb-section{background:#fff;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:20px;overflow:hidden;}
    .wpb-cb-section-head{padding:14px 18px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-weight:700;font-size:13px;display:flex;justify-content:space-between;align-items:center;}
    .wpb-cb-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:16px 18px;}
    .wpb-cb-form-grid label{font-size:12px;font-weight:600;color:#374151;display:block;margin-bottom:4px;}
    .wpb-cb-form-grid input,.wpb-cb-form-grid select,.wpb-cb-form-grid textarea{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;}
    .wpb-cb-form-full{grid-column:1/-1;}
    .wpb-cb-actions-row{padding:12px 18px;border-top:1px solid #e2e8f0;display:flex;gap:8px;align-items:center;}
    </style>

    <!-- Aggiungi servizio -->
    <div class="wpb-cb-section">
        <div class="wpb-cb-section-head"><span>Aggiungi servizio</span></div>
        <div class="wpb-cb-form-grid">
            <div>
                <label>Nome *</label>
                <input type="text" id="wpb-cb-f-name" placeholder="Google Analytics 4">
            </div>
            <div>
                <label>Categoria</label>
                <select id="wpb-cb-f-cat"><?php echo $cat_options; ?></select>
            </div>
            <div>
                <label>Provider</label>
                <input type="text" id="wpb-cb-f-provider" placeholder="Google LLC">
            </div>
            <div>
                <label>Privacy URL</label>
                <input type="url" id="wpb-cb-f-privacy" placeholder="https://policies.google.com/privacy">
            </div>
            <div>
                <label>Cookie (separati da virgola)</label>
                <input type="text" id="wpb-cb-f-cookies" placeholder="_ga, _gid, _ga_*">
            </div>
            <div class="wpb-cb-form-full">
                <label>Script (opzionale)</label>
                <textarea id="wpb-cb-f-script" rows="2" style="font-family:monospace;font-size:12px;" placeholder="<script src='...'></script>"></textarea>
            </div>
        </div>
        <div class="wpb-cb-actions-row">
            <button class="wpb-cp-btn wpb-cp-btn-primary" id="wpb-cb-add-btn">Aggiungi servizio</button>
            <span id="wpb-cb-add-msg" style="font-size:13px;color:#6b7280;"></span>
        </div>
    </div>

    <!-- Scansione cookie -->
    <div class="wpb-cb-section">
        <div class="wpb-cb-section-head">
            <span>Scansione cookie</span>
            <button class="wpb-cp-btn wpb-cp-btn-primary" id="wpb-cb-scan-btn">Avvia scansione</button>
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
        <div id="wpb-cb-scan-progress" style="display:none;padding:0 18px 12px;font-size:13px;color:#374151;">
            <span id="wpb-cb-scan-msg"></span>
        </div>
    </div>

    <!-- Risultati scansione -->
    <div class="wpb-cb-section" id="wpb-cb-scan-results" <?php echo $has_results ? '' : 'style="display:none"'; ?>>
        <div class="wpb-cb-section-head">
            <span>Provider rilevati</span>
            <span id="wpb-cb-scan-subtitle" style="font-weight:400;color:#6b7280;font-size:12px;">
                <?php if ($has_results): ?><?php echo count($scan_found); ?> trovati<?php endif; ?>
            </span>
        </div>
        <div id="wpb-cb-scan-table"></div>
    </div>

    <!-- Servizi registrati -->
    <div class="wpb-cb-section">
        <div class="wpb-cb-section-head"><span>Servizi registrati</span></div>
        <div id="wpb-cb-services-table">
            <?php echo wpb_cb_services_table_html($services, $categories); ?>
        </div>
    </div>

    <script>
    (function(){
        var ajax  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;

        function post(action, data, cb) {
            var fd = new FormData();
            fd.append('action', action); fd.append('nonce', nonce);
            Object.keys(data || {}).forEach(function(k){ fd.append(k, data[k]); });
            fetch(ajax, {method:'POST', body:fd}).then(function(r){ return r.json(); }).then(cb);
        }

        /* ── Tabella servizi ── */

        function refreshServicesTable() {
            post('wpb_cb_service_list', {}, function(res){
                if (res.success) document.getElementById('wpb-cb-services-table').innerHTML = res.data.html;
                bindServicesTable();
            });
        }

        function bindServicesTable() {
            document.querySelectorAll('[data-wpb-delete]').forEach(function(btn){
                btn.addEventListener('click', function(){
                    if (!confirm('Eliminare questo servizio?')) return;
                    post('wpb_cb_service_delete', {id: btn.dataset.wpbDelete}, function(){ refreshServicesTable(); });
                });
            });
            document.querySelectorAll('[data-wpb-toggle]').forEach(function(tog){
                tog.addEventListener('change', function(){
                    post('wpb_cb_service_toggle', {id: tog.dataset.wpbToggle, active: tog.checked ? '1' : '0'}, function(){});
                });
            });
        }
        bindServicesTable();

        /* ── Form aggiungi servizio ── */

        document.getElementById('wpb-cb-add-btn').addEventListener('click', function(){
            var name = document.getElementById('wpb-cb-f-name').value.trim();
            if (!name) { document.getElementById('wpb-cb-add-msg').textContent = 'Nome obbligatorio.'; return; }
            post('wpb_cb_service_add', {
                name:        name,
                category:    document.getElementById('wpb-cb-f-cat').value,
                provider:    document.getElementById('wpb-cb-f-provider').value,
                privacy_url: document.getElementById('wpb-cb-f-privacy').value,
                cookies:     document.getElementById('wpb-cb-f-cookies').value,
                script:      document.getElementById('wpb-cb-f-script').value,
            }, function(res){
                document.getElementById('wpb-cb-add-msg').textContent = res.success ? 'Aggiunto.' : (res.data || 'Errore.');
                if (res.success) {
                    ['name','provider','privacy_url','cookies','script'].forEach(function(id){
                        document.getElementById('wpb-cb-f-' + id).value = '';
                    });
                    refreshServicesTable();
                }
            });
        });

        /* ── Scanner ── */

        function escHtml(s) {
            return String(s).replace(/[&<>"']/g, function(c){
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            });
        }

        function buildScanTable(found) {
            if (!found.length) {
                return '<p style="padding:16px 18px;color:#9ca3af;font-size:13px;">Nessun provider rilevato.</p>';
            }
            var rows = found.map(function(f) {
                var badge = f.in_registry
                    ? '<span class="wpb-cb-badge wpb-cb-badge-manual">Nel registry</span>'
                    : '<button class="wpb-cp-btn wpb-cp-btn-ghost" data-confirm="' + escHtml(f.pattern) + '" style="padding:4px 10px;font-size:12px;">+ Aggiungi</button>';
                var gtmTag = f.via_gtm
                    ? ' <span class="wpb-cb-badge wpb-cb-badge-gtm" title="Container ' + escHtml(f.via_gtm) + '">via GTM</span>'
                    : '';
                return '<tr>'
                    + '<td>' + escHtml(f.name) + gtmTag + '</td>'
                    + '<td>' + escHtml(f.category) + '</td>'
                    + '<td>' + escHtml(f.provider) + '</td>'
                    + '<td style="font-size:11px;">' + escHtml((f.found_on || []).join(', ')) + '</td>'
                    + '<td>' + badge + '</td>'
                    + '</tr>';
            }).join('');
            return '<table class="wpb-cb-table">'
                + '<thead><tr><th>Provider</th><th>Categoria</th><th>Azienda</th><th>Trovato su</th><th></th></tr></thead>'
                + '<tbody>' + rows + '</tbody></table>';
        }

        function bindScanConfirm() {
            document.querySelectorAll('#wpb-cb-scan-table [data-confirm]').forEach(function(btn){
                btn.addEventListener('click', function(){
                    btn.disabled = true;
                    btn.textContent = '…';
                    post('wpb_cb_confirm_service', {pattern: btn.dataset.confirm}, function(res){
                        if (res.success) {
                            var badge = document.createElement('span');
                            badge.className = 'wpb-cb-badge wpb-cb-badge-manual';
                            badge.textContent = 'Nel registry';
                            btn.replaceWith(badge);
                            refreshServicesTable();
                        } else {
                            btn.disabled = false;
                            btn.textContent = '+ Aggiungi';
                            alert(res.data || 'Errore.');
                        }
                    });
                });
            });
        }

        var scanBtn    = document.getElementById('wpb-cb-scan-btn');
        var progressEl = document.getElementById('wpb-cb-scan-progress');
        var scanMsgEl  = document.getElementById('wpb-cb-scan-msg');
        var resultsBox = document.getElementById('wpb-cb-scan-results');
        var scanTable  = document.getElementById('wpb-cb-scan-table');

        // Renderizza i risultati dell'ultima scansione già al caricamento pagina
        var initialFound = <?php echo wp_json_encode($scan_found); ?>;
        if (initialFound.length) {
            scanTable.innerHTML = buildScanTable(initialFound);
            bindScanConfirm();
        }

        scanBtn.addEventListener('click', function(){
            scanBtn.disabled = true;
            progressEl.style.display = 'block';
            scanMsgEl.textContent = 'Scansione in corso…';

            post('wpb_cb_run_scan', {}, function(res){
                scanBtn.disabled = false;
                if (!res.success) {
                    scanMsgEl.textContent = 'Errore: ' + (res.data && res.data.message ? res.data.message : 'sconosciuto');
                    return;
                }
                var found = res.data.found || [];
                scanMsgEl.textContent = 'Completata — ' + res.data.pages_scanned + ' pagine, ' + found.length + ' provider trovati.';
                document.getElementById('wpb-cb-scan-subtitle').textContent = found.length + ' trovati';
                scanTable.innerHTML = buildScanTable(found);
                resultsBox.style.display = '';
                bindScanConfirm();
                refreshServicesTable();
            });
        });

    })();
    </script>
    <?php
}

/* ── Helper: HTML tabella servizi (riutilizzato da AJAX) ── */
function wpb_cb_services_table_html(array $services, array $categories): string {
    if (empty($services)) {
        return '<p style="padding:16px 18px;color:#9ca3af;font-size:13px;">Nessun servizio registrato. Aggiungine uno sopra o avvia una scansione.</p>';
    }
    ob_start();
    ?>
    <table class="wpb-cb-table">
        <thead><tr>
            <th>Attivo</th><th>Nome</th><th>Categoria</th><th>Provider</th><th>Cookie</th><th>Fonte</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($services as $s): ?>
        <tr>
            <td><input type="checkbox" data-wpb-toggle="<?php echo esc_attr($s['id']); ?>" <?php checked(!empty($s['active'])); ?>></td>
            <td><?php echo esc_html($s['name']); ?></td>
            <td><?php echo esc_html($categories[$s['category']]['name'] ?? $s['category']); ?></td>
            <td><?php echo esc_html($s['provider']); ?></td>
            <td style="font-size:11px;font-family:monospace;"><?php echo esc_html(implode(', ', $s['cookies'])); ?></td>
            <td><span class="wpb-cb-badge wpb-cb-badge-<?php echo esc_attr($s['source'] ?? 'manual'); ?>"><?php echo $s['source'] === 'scanner' ? 'Scanner' : 'Manuale'; ?></span></td>
            <td><button class="wpb-cp-btn wpb-cp-btn-ghost" data-wpb-delete="<?php echo esc_attr($s['id']); ?>" style="padding:4px 10px;font-size:12px;">Elimina</button></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

/* ── AJAX: lista servizi (per refresh tabella) ── */
add_action('wp_ajax_wpb_cb_service_list', function () {
    wpb_cb_check_ajax();
    $services   = WPB_Service_Registry::get_services();
    $categories = WPB_Service_Registry::get_categories();
    wp_send_json_success(['html' => wpb_cb_services_table_html($services, $categories)]);
});

/* ── AJAX: avvia scansione manuale (sincrona con time limit esteso) ── */
add_action('wp_ajax_wpb_cb_run_scan', function () {
    wpb_cb_check_ajax();
    if (function_exists('set_time_limit')) @set_time_limit(120);
    $results = WPB_Scanner::run();
    if (isset($results['error'])) {
        wp_send_json_error(['message' => $results['error']]);
    }
    wp_send_json_success($results);
});

/* ── AJAX: stato scansione in corso (polling) ── */
add_action('wp_ajax_wpb_cb_scan_status', function () {
    wpb_cb_check_ajax();
    $progress = get_transient(WPB_Scanner::PROGRESS_TRANSIENT);
    if ($progress === false) {
        $results = WPB_Scanner::get_results();
        wp_send_json_success(['status' => empty($results) ? 'idle' : 'done', 'results' => $results]);
        return;
    }
    wp_send_json_success($progress);
});

/* ── AJAX: promuovi servizio scanner → registry ── */
add_action('wp_ajax_wpb_cb_confirm_service', function () {
    wpb_cb_check_ajax();
    $pattern = sanitize_text_field($_POST['pattern'] ?? '');
    if (!$pattern) wp_send_json_error('Pattern mancante.');
    // Cerca il provider nei risultati della scansione
    $results = WPB_Scanner::get_results();
    $found   = array_filter($results['found'] ?? [], fn($f) => $f['pattern'] === $pattern);
    $item    = reset($found);
    if (!$item) wp_send_json_error('Provider non trovato nei risultati scan.');
    $id = WPB_Service_Registry::add_service([
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
function wpb_cb_render_tab_gtm(): void {
    $engine_url = wpb_cb_engine_url();
    $rest_base  = rtrim(rest_url(), '/');
    $snippet    = wpb_cb_build_gtm_snippet($engine_url, $rest_base);
    ?>
    <div class="wpb-cb-section">
        <div class="wpb-cb-section-head">Snippet GTM &mdash; Custom HTML Tag</div>
        <div style="padding:16px 18px;">
            <p style="font-size:13px;color:#374151;margin:0 0 12px;">
                Copia questo codice e incollalo in GTM come <strong>Custom HTML Tag</strong> con trigger
                <strong style="color:#b91c1c;">Consent Initialization - All Pages</strong> (non "All Pages").<br>
                Questo trigger speciale garantisce che il consenso sia impostato <em>prima</em> di qualsiasi altro tag,
                incluso Google Analytics.
            </p>
            <div style="position:relative;">
                <pre id="wpb-gtm-snippet" style="background:#1e293b;color:#e2e8f0;padding:16px 16px 16px 16px;border-radius:8px;font-size:11px;line-height:1.65;overflow-x:auto;margin:0;white-space:pre-wrap;word-break:break-all;"><?php echo esc_html($snippet); ?></pre>
                <button id="wpb-gtm-copy" type="button" style="position:absolute;top:10px;right:10px;background:#334155;color:#e2e8f0;border:none;padding:5px 12px;border-radius:5px;font-size:11px;cursor:pointer;">Copia</button>
            </div>
        </div>
    </div>

    <div class="wpb-cb-section">
        <div class="wpb-cb-section-head">Come configurare GTM &mdash; passo per passo</div>
        <div style="padding:16px 18px;font-size:13px;color:#374151;line-height:1.8;">
            <ol style="margin:0;padding-left:20px;">
                <li>Apri <strong>Google Tag Manager</strong> &rarr; il tuo container.</li>
                <li>Vai su <strong>Tag &rarr; Nuovo</strong> &rarr; tipo <em>Custom HTML</em>.</li>
                <li>Incolla lo snippet sopra nel campo HTML.</li>
                <li>Assegna trigger: <strong style="color:#b91c1c;">Consent Initialization - All Pages</strong>
                    (Trigger &rarr; Nuovo &rarr; tipo "Consenso" &rarr; "Initialization - All Pages").<br>
                    <span style="font-size:12px;color:#6b7280;">Questo trigger è l&rsquo;unico che garantisce l&rsquo;esecuzione
                    <em>prima</em> di tutti gli altri tag GTM.</span></li>
                <li>Assegna un nome al tag, es. <code>WpBarrio &ndash; Consent Engine</code>.</li>
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
                &nbsp;&nbsp;&bull;&nbsp; Assicurati che il tag WpBarrio si attivi <strong>prima</strong> del tag
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
                    <pre id="wpb-gtm-step0" style="background:#1e293b;color:#e2e8f0;padding:14px 16px;border-radius:8px;font-size:11px;line-height:1.6;overflow-x:auto;margin:0;white-space:pre;"><?php echo esc_html($step0_nextjs); ?></pre>
                    <button type="button"
                        style="position:absolute;top:8px;right:8px;padding:4px 10px;border-radius:5px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#e2e8f0;font-size:11px;cursor:pointer;"
                        onclick="(function(b){var t=b.closest('div').querySelector('pre').textContent;navigator.clipboard.writeText(t).then(function(){b.textContent='Copiato!';setTimeout(function(){b.textContent='Copia';},1800);});})(this)">
                        Copia
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.getElementById('wpb-gtm-copy').addEventListener('click', function () {
        var text = document.getElementById('wpb-gtm-snippet').textContent;
        var btn  = document.getElementById('wpb-gtm-copy');
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () {
                btn.textContent = 'Copiato!';
                setTimeout(function () { btn.textContent = 'Copia'; }, 2000);
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            btn.textContent = 'Copiato!';
            setTimeout(function () { btn.textContent = 'Copia'; }, 2000);
        }
    });
    </script>
    <?php
}

/* ── Render: tab Banner (Task 5) ── */
function wpb_cb_render_tab_banner(): void {
    $cfg   = get_option('wpb_cb_banner_config', []);
    $nonce = wpb_cb_nonce();
    $f = function(string $key, string $default) use ($cfg): string {
        return esc_attr($cfg[$key] ?? $default);
    };
    ?>
    <style>
    .wpb-cb-banner-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:20px;}
    .wpb-cb-banner-grid label{font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:5px;}
    .wpb-cb-banner-grid input[type=text],.wpb-cb-banner-grid input[type=color],.wpb-cb-banner-grid select{width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;}
    .wpb-cb-banner-grid input[type=color]{height:38px;padding:2px 4px;cursor:pointer;}
    .wpb-cb-color-row{display:flex;gap:8px;align-items:center;}
    .wpb-cb-color-row input[type=text]{flex:1;}
    .wpb-cb-color-row input[type=color]{width:44px;flex-shrink:0;}
    </style>

    <div class="wpb-cb-section">
        <div class="wpb-cb-section-head">Testi e comportamento</div>
        <div class="wpb-cb-banner-grid">
            <div>
                <label>Titolo banner</label>
                <input type="text" id="wpb-cb-b-text_title" value="<?php echo $f('text_title','Utilizziamo i cookie'); ?>">
            </div>
            <div>
                <label>Testo breve</label>
                <input type="text" id="wpb-cb-b-text_body" value="<?php echo $f('text_body','Usiamo cookie per migliorare la tua esperienza...'); ?>">
            </div>
            <div>
                <label>Label "Accetta tutti"</label>
                <input type="text" id="wpb-cb-b-text_accept" value="<?php echo $f('text_accept','Accetta tutti'); ?>">
            </div>
            <div>
                <label>Label "Rifiuta tutti"</label>
                <input type="text" id="wpb-cb-b-text_reject" value="<?php echo $f('text_reject','Rifiuta tutti'); ?>">
            </div>
            <div>
                <label>Label "Gestisci preferenze"</label>
                <input type="text" id="wpb-cb-b-text_manage" value="<?php echo $f('text_manage','Gestisci preferenze'); ?>">
            </div>
            <div>
                <label>Posizione banner</label>
                <select id="wpb-cb-b-position">
                    <?php foreach (['bottom'=>'In basso al centro','bottom-left'=>'In basso a sinistra','bottom-right'=>'In basso a destra'] as $v=>$l): ?>
                    <option value="<?php echo esc_attr($v); ?>" <?php selected(($cfg['position']??'bottom'),$v); ?>><?php echo esc_html($l); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="wpb-cb-section">
        <div class="wpb-cb-section-head">Colori</div>
        <div class="wpb-cb-banner-grid">
            <?php
            $colors = [
                ['color_bg',    'Sfondo banner',      '#1a1a2e'],
                ['color_text',  'Testo',              '#FFFFFF'],
                ['color_accent','Bottone primario',   '#e94560'],
            ];
            foreach ($colors as [$key, $label, $default]): ?>
            <div>
                <label><?php echo esc_html($label); ?></label>
                <div class="wpb-cb-color-row">
                    <input type="text" id="wpb-cb-b-<?php echo esc_attr($key); ?>-text" value="<?php echo $f($key, $default); ?>">
                    <input type="color" id="wpb-cb-b-<?php echo esc_attr($key); ?>" value="<?php echo $f($key, $default); ?>">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="wpb-cb-section">
        <div class="wpb-cb-section-head">Widget floating</div>
        <div class="wpb-cb-banner-grid">
            <div>
                <label>
                    <input type="checkbox" id="wpb-cb-b-show_widget" <?php checked($cfg['show_widget'] ?? true); ?>>
                    Mostra widget per riaprire le preferenze
                </label>
            </div>
            <div>
                <label>Posizione widget</label>
                <select id="wpb-cb-b-widget_pos">
                    <option value="bottom-right" <?php selected(($cfg['widget_pos']??'bottom-left'),'bottom-right'); ?>>In basso a destra</option>
                    <option value="bottom-left"  <?php selected(($cfg['widget_pos']??'bottom-left'),'bottom-left'); ?>>In basso a sinistra</option>
                </select>
            </div>
        </div>
    </div>

    <div class="wpb-cb-actions-row" style="margin-bottom:0;">
        <button class="wpb-cp-btn wpb-cp-btn-primary" id="wpb-cb-banner-save">Salva configurazione</button>
        <span id="wpb-cb-banner-msg" style="line-height:2;font-size:13px;color:#6b7280;"></span>
    </div>

    <script>
    (function(){
        var ajax  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;

        // Sync color pickers ↔ text inputs
        <?php foreach ($colors as [$key,,]): ?>
        (function(){
            var picker = document.getElementById('wpb-cb-b-<?php echo esc_js($key); ?>');
            var text   = document.getElementById('wpb-cb-b-<?php echo esc_js($key); ?>-text');
            picker.addEventListener('input', function(){ text.value = picker.value; });
            text.addEventListener('change', function(){ if (/^#[0-9a-f]{6}$/i.test(text.value)) picker.value = text.value; });
        })();
        <?php endforeach; ?>

        document.getElementById('wpb-cb-banner-save').addEventListener('click', function(){
            var fields = ['text_title','text_body','text_accept','text_reject','text_manage','position','color_bg','color_text','color_accent','widget_pos'];
            var fd = new FormData();
            fd.append('action','wpb_cb_banner_save'); fd.append('nonce', nonce);
            fields.forEach(function(f){
                var el = document.getElementById('wpb-cb-b-'+f+'-text') || document.getElementById('wpb-cb-b-'+f);
                if (el) fd.append(f, el.value);
            });
            fd.append('show_widget', document.getElementById('wpb-cb-b-show_widget').checked ? '1' : '0');
            fetch(ajax,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(res){
                document.getElementById('wpb-cb-banner-msg').textContent = res.success ? 'Salvato.' : 'Errore.';
            });
        });
    })();
    </script>
    <?php
}
