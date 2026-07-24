<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    // 'wpb/v1/cookie-banner' resta registrato come alias retrocompatibile:
    // tag GTM Custom HTML già pubblicati sui siti live puntano a quell'URL.
    foreach (['suite-barrio/v1/cookie-banner', 'wpb/v1/cookie-banner'] as $ns) {
    register_rest_route($ns, '/config', [
        'methods'             => 'GET',
        'callback'            => function () {
            $r = new WP_REST_Response(suite_barrio_cb_build_frontend_config(), 200);
            $r->header('Cache-Control', 'no-store');
            return $r;
        },
        'permission_callback' => '__return_true',
    ]);

    register_rest_route($ns, '/preferences-html', [
        'methods'             => 'GET',
        'callback'            => fn() => new WP_REST_Response(['html' => suite_barrio_cb_generate_preferences_html()], 200),
        'permission_callback' => '__return_true',
    ]);

    register_rest_route($ns, '/scan', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $req) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- alcuni host disabilitano set_time_limit(); @ evita il warning, necessario per operazioni batch lunghe
            if (function_exists('set_time_limit')) @set_time_limit(120);
            $results = SUITE_BARRIO_Scanner::run();
            return isset($results['error'])
                ? new WP_REST_Response(['error' => $results['error']], 500)
                : new WP_REST_Response($results, 200);
        },
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);

    register_rest_route($ns, '/gtm-snippet', [
        'methods'             => 'GET',
        'callback'            => function () {
            $engine_url = suite_barrio_cb_engine_url();
            $rest_base  = rtrim(rest_url(), '/');
            $snippet = suite_barrio_cb_build_gtm_snippet($engine_url, $rest_base);
            return new WP_REST_Response(['snippet' => $snippet], 200);
        },
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);

    register_rest_route($ns, '/scan/status', [
        'methods'             => 'GET',
        'callback'            => function () {
            $progress = get_transient(SUITE_BARRIO_Scanner::PROGRESS_TRANSIENT);
            if ($progress === false) {
                $results = SUITE_BARRIO_Scanner::get_results();
                return new WP_REST_Response(['status' => empty($results) ? 'idle' : 'done', 'results' => $results], 200);
            }
            return new WP_REST_Response($progress, 200);
        },
        'permission_callback' => fn() => current_user_can('manage_options'),
    ]);

    register_rest_route($ns, '/consent-log', [
        'methods'             => 'POST',
        'callback'            => 'suite_barrio_cb_handle_consent_log',
        'permission_callback' => 'suite_barrio_cb_consent_log_permission',
    ]);
    }
});

/* ── Permission callback del consent-log: l'endpoint resta accessibile ai
      visitatori anonimi (il consenso va registrato anche senza login), ma
      richiede il nonce restituito da /config, cosi' solo chi ha prima
      caricato la config dal nostro sito puo' scrivere un log — non uno
      script esterno che invia POST alla cieca. ── */
function suite_barrio_cb_consent_log_permission(WP_REST_Request $req) {
    $nonce = $req->get_header('X-WP-Nonce');
    if (!$nonce || !wp_verify_nonce($nonce, 'suite_barrio_cb_consent_log')) {
        return new WP_Error(
            'suite_barrio_cb_forbidden',
            __('Richiesta non valida.', 'suite-barrio'),
            ['status' => 403]
        );
    }
    return true;
}

/* ── Salva un evento di consenso nel log DB (chiamato dal consent-engine) ── */
function suite_barrio_cb_handle_consent_log(WP_REST_Request $req): WP_REST_Response {
    $params = $req->get_json_params() ?: [];

    $ip_raw  = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
    $ip_anon = SUITE_BARRIO_Consent_Log::anonymize_ip($ip_raw);

    if (SUITE_BARRIO_Consent_Log::is_throttled($ip_anon)) {
        return new WP_REST_Response(['ok' => true], 200);
    }

    $id = sanitize_text_field($params['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
        $id = wp_generate_uuid4();
    }

    $status = ($params['status'] ?? '') === 'accept' ? 'accept' : 'reject';

    $valid_categories = array_keys(SUITE_BARRIO_Service_Registry::get_categories());
    $categories = array_values(array_intersect(
        array_map('sanitize_key', (array) ($params['categories'] ?? [])),
        $valid_categories
    ));
    $categories = array_diff($categories, ['necessary']);

    $url = substr(sanitize_text_field($params['url'] ?? ''), 0, 2048);
    $user_agent = substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 512);

    $banner_config = suite_barrio_cb_build_frontend_config();
    $consent_text  = $banner_config['banner']['text_body'] ?? '';

    SUITE_BARRIO_Consent_Log::insert([
        'id'           => $id,
        'status'       => $status,
        'ip_address'   => $ip_anon,
        'url'          => $url,
        'user_agent'   => $user_agent,
        'categories'   => implode(',', $categories),
        'consent_text' => $consent_text,
    ]);

    return new WP_REST_Response(['ok' => true], 200);
}

/* ── URL del consent engine con cache-busting da filemtime ── */
function suite_barrio_cb_engine_url(): string {
    $file = SUITE_BARRIO_CB_DIR . 'assets/consent-engine.js';
    $ver  = file_exists($file) ? filemtime($file) : '1';
    return SUITE_BARRIO_CB_URL . 'assets/consent-engine.js?ver=' . $ver;
}

/* ── Genera snippet GTM ── */
function suite_barrio_cb_build_gtm_snippet(string $engine_url, string $rest_base): string {
    // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript -- non è markup di pagina: è testo generato per essere copiato/incollato dall'admin in Google Tag Manager (Custom HTML Tag), un sistema esterno che richiede letteralmente il tag <script> come stringa. wp_enqueue_script() non è applicabile: non viene mai stampato in una pagina WP.
    return '<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}

// Legge il consenso già salvato dal cookie (sincrono, senza network)
(function(){
  var granted = "granted", denied = "denied";
  var c = null;
  try {
    var m = document.cookie.match(/(?:^|;)\s*wpb_consent=([^;]*)/);
    if (m) c = JSON.parse(decodeURIComponent(m[1]));
  } catch(e){}
  // Se c\'è già un consenso salvato lo applica subito come default;
  // altrimenti nega tutto: per i nuovi visitatori nessun tag si attiva
  // prima che il banner venga mostrato e l\'utente esprima la preferenza.
  gtag("consent", "default", {
    security_storage:        granted,
    functionality_storage:   (c && c.functional)  ? granted : denied,
    personalization_storage: (c && c.functional)  ? granted : denied,
    analytics_storage:       (c && c.analytics)   ? granted : denied,
    ad_storage:              (c && c.marketing)   ? granted : denied,
    ad_user_data:            (c && c.marketing)   ? granted : denied,
    ad_personalization:      (c && c.marketing)   ? granted : denied,
  });
})();

// Punta il consent engine all\'API WordPress
window.SuiteBarrioRestBase = "' . esc_js($rest_base) . '";
</script>
<script src="' . esc_url($engine_url) . '" async></script>';
    // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
}

/* ── CSS del widget preferenze, iniettato in pagina via wp_add_inline_style
      (vedi frontend.php) invece che con un tag <style> dentro il frammento HTML ── */
function suite_barrio_cb_preferences_css(): string {
    return '
.suite-barrio-preferences{font-family:system-ui,sans-serif;max-width:100%;}
.suite-barrio-pref-category{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:12px;overflow:hidden;}
.suite-barrio-pref-header{padding:14px 16px;background:#f8fafc;}
.suite-barrio-pref-label{display:flex;align-items:center;gap:10px;font-weight:700;font-size:14px;cursor:pointer;margin:0;}
.suite-barrio-pref-label input[type=checkbox]{width:16px;height:16px;cursor:pointer;}
.suite-barrio-pref-badge{font-size:10px;background:#dcfce7;color:#166534;padding:2px 7px;border-radius:3px;font-weight:600;letter-spacing:.04em;}
.suite-barrio-pref-desc{margin:6px 0 0 26px;font-size:12px;color:#6b7280;line-height:1.5;}
.suite-barrio-pref-table-wrap{overflow-x:auto;border-top:1px solid #e2e8f0;}
.suite-barrio-pref-table{width:100%;border-collapse:collapse;font-size:13px;}
.suite-barrio-pref-table th{text-align:left;padding:8px 12px;background:#f1f5f9;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:#64748b;font-weight:600;}
.suite-barrio-pref-table td{padding:9px 12px;border-top:1px solid #f1f5f9;color:#374151;vertical-align:middle;}
.suite-barrio-pref-table code{background:#f1f5f9;padding:1px 5px;border-radius:3px;font-size:11px;}
.suite-barrio-pref-actions{display:flex;flex-wrap:wrap;gap:8px;padding:16px 0 0;}
.suite-barrio-pref-btn{padding:9px 18px;border-radius:6px;border:none;cursor:pointer;font-size:13px;font-weight:600;}
.suite-barrio-pref-btn-primary{background:#1A56DB;color:#fff;}
.suite-barrio-pref-btn-secondary{background:#f1f5f9;color:#374151;}
';
}

/* ── Genera HTML widget preferenze ── */
function suite_barrio_cb_generate_preferences_html(): string {
    $categories = SUITE_BARRIO_Service_Registry::get_categories();
    ob_start();
    ?>
<div class="suite-barrio-preferences" data-suite-barrio-preferences>
    <?php foreach ($categories as $cat_id => $cat):
        $services = SUITE_BARRIO_Service_Registry::get_services_by_category($cat_id);
        if ($cat_id !== 'necessary' && empty($services)) continue;
    ?>
    <div class="suite-barrio-pref-category" data-suite-barrio-category="<?php echo esc_attr($cat_id); ?>">
        <div class="suite-barrio-pref-header">
            <label class="suite-barrio-pref-label">
                <input type="checkbox"
                    name="wpb_consent[<?php echo esc_attr($cat_id); ?>]"
                    data-category="<?php echo esc_attr($cat_id); ?>"
                    data-suite-barrio-toggle="<?php echo esc_attr($cat_id); ?>"
                    <?php echo !empty($cat['always_active']) ? 'disabled checked' : ''; ?>>
                <?php echo esc_html($cat['name']); ?>
                <?php if (!empty($cat['always_active'])): ?>
                    <span class="suite-barrio-pref-badge">Sempre attivi</span>
                <?php endif; ?>
            </label>
            <p class="suite-barrio-pref-desc"><?php echo esc_html($cat['description']); ?></p>
        </div>
        <?php if (!empty($services)): ?>
        <div class="suite-barrio-pref-table-wrap">
            <table class="suite-barrio-pref-table">
                <thead><tr><th>Servizio</th><th>Provider</th><th>Cookie</th><th>Privacy</th></tr></thead>
                <tbody>
                <?php foreach ($services as $s): ?>
                <tr>
                    <td><?php echo esc_html($s['name']); ?></td>
                    <td><?php echo esc_html($s['provider']); ?></td>
                    <td><?php echo wp_kses_post(implode(' ', array_map(fn($c) => '<code>' . esc_html($c) . '</code>', $s['cookies']))); ?></td>
                    <td><?php if (!empty($s['privacy_url'])): ?>
                        <a href="<?php echo esc_url($s['privacy_url']); ?>" target="_blank" rel="noopener noreferrer" style="color:#1A56DB;">&#x2197;</a>
                    <?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <div class="suite-barrio-pref-actions">
        <button type="button" class="suite-barrio-pref-btn suite-barrio-pref-btn-primary" data-suite-barrio-action="accept-all">Accetta tutti</button>
        <button type="button" class="suite-barrio-pref-btn suite-barrio-pref-btn-secondary" data-suite-barrio-action="reject-all">Rifiuta tutti</button>
        <button type="button" class="suite-barrio-pref-btn suite-barrio-pref-btn-secondary" data-suite-barrio-action="save">Salva preferenze</button>
    </div>
</div>
    <?php
    return ob_get_clean();
}
