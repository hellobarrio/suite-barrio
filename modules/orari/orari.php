<?php
/**
 * WP Barrio — Orari
 * Sincronizzazione automatica degli orari di apertura da Google Places API.
 * Visualizza un pannello admin per configurare le credenziali e aggiornare
 * gli orari manualmente o tramite cron giornaliero.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('WPB_ORARI_VERSION', '1.0.0');
define('WPB_ORARI_OPTION', 'wpb_orari_settings');   // credenziali globali
define('WPB_ORARI_DATA_OPTION', 'wpb_orari_data');        // dati orari salvati
define('WPB_ORARI_CRON_HOOK', 'wpb_cron_aggiorna_orari');

/* ─────────────────────────────────────────
   MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    add_submenu_page(
        'wp-barrio',
        'Orari — WP Barrio',
        'Orari',
        'manage_options',
        'wp-barrio-orari',
        'wpb_orari_admin_page'
    );
}, 15);

/* ─────────────────────────────────────────
   CRON: schedulazione giornaliera ore 01:00
   ───────────────────────────────────────── */
add_action('init', function () {
    if (!wp_next_scheduled(WPB_ORARI_CRON_HOOK)) {
        $orario = strtotime('today 01:00');
        if ($orario <= time()) {
            $orario = strtotime('tomorrow 01:00');
        }
        wp_schedule_event($orario, 'daily', WPB_ORARI_CRON_HOOK);
    }
});

add_action(WPB_ORARI_CRON_HOOK, 'wpb_orari_fetch_and_save');

/* ─────────────────────────────────────────
   HELPER: recupera e salva gli orari da Google
   ───────────────────────────────────────── */
function wpb_orari_fetch_and_save(): array
{
    $settings = get_option(WPB_ORARI_OPTION, []);
    $api_key = trim($settings['google_api_key'] ?? '');
    $place_id = trim($settings['place_id'] ?? '');

    if (!$api_key || !$place_id) {
        return ['success' => false, 'message' => 'API Key o Place ID mancanti.'];
    }

    $url = "https://places.googleapis.com/v1/places/{$place_id}?fields=currentOpeningHours,displayName&key={$api_key}";

    $response = wp_remote_get($url, [
        'timeout' => 15,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Goog-Api-Locale' => 'it',
        ],
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => 'Errore HTTP: ' . $response->get_error_message()];
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($http_code !== 200) {
        $err = $body['error']['message'] ?? "HTTP {$http_code}";
        return ['success' => false, 'message' => "Google API error: {$err}"];
    }

    if (!isset($body['currentOpeningHours'])) {
        return ['success' => false, 'message' => 'Nessun dato orari ricevuto da Google.'];
    }

    $oh = $body['currentOpeningHours'];

    $giorni_it = [
        1 => 'Lunedì',
        2 => 'Martedì',
        3 => 'Mercoledì',
        4 => 'Giovedì',
        5 => 'Venerdì',
        6 => 'Sabato',
        0 => 'Domenica',
    ];

    $settimana = [];
    foreach ($giorni_it as $nome) {
        $settimana[$nome] = [];
    }

    foreach ($oh['periods'] ?? [] as $period) {
        $day_idx = $period['open']['day'];
        $giorno_nome = $giorni_it[$day_idx];
        // Sempre salvato in formato 24h "HH:MM - HH:MM".
        // La traduzione (nomi giorni + formato 12h/24h) avviene nel resolver GraphQL via l'argomento `lang`.
        $settimana[$giorno_nome][] = sprintf(
            '%02d:%02d - %02d:%02d',
            $period['open']['hour'],
            $period['open']['minute'],
            $period['close']['hour'],
            $period['close']['minute']
        );
    }

    $display_name = $body['displayName']['text'] ?? '';

    $data = [
        'ultimo_aggiornamento' => current_time('d/m/Y H:i'),
        'display_name' => $display_name,
        'orari_settimanali' => $settimana,
    ];

    update_option(WPB_ORARI_DATA_OPTION, $data, false);

    // Invalida cache Vercel se la funzione è disponibile
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }

    return ['success' => true, 'message' => 'Orari aggiornati con successo.', 'data' => $data];
}

/* ─────────────────────────────────────────
   AJAX ADMIN: salva impostazioni
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_orari_save_settings', function () {
    check_ajax_referer('wpb_orari_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $api_key = sanitize_text_field(wp_unslash($_POST['google_api_key'] ?? ''));
    $place_id = sanitize_text_field(wp_unslash($_POST['place_id'] ?? ''));

    update_option(WPB_ORARI_OPTION, [
        'google_api_key' => $api_key,
        'place_id' => $place_id,
    ], false);

    wp_send_json_success('Impostazioni salvate.');
});

/* ─────────────────────────────────────────
   AJAX ADMIN: aggiornamento manuale
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_orari_refresh', function () {
    check_ajax_referer('wpb_orari_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $result = wpb_orari_fetch_and_save();

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result['message']);
    }
});

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function wpb_orari_admin_page()
{
    $settings = get_option(WPB_ORARI_OPTION, []);
    $orari = get_option(WPB_ORARI_DATA_OPTION, []);
    $next_cron = wp_next_scheduled(WPB_ORARI_CRON_HOOK);
    $nonce = wp_create_nonce('wpb_orari_nonce');
    $graphql_active = function_exists('register_graphql_field');
    $configured = !empty(trim($settings['google_api_key'] ?? '')) && !empty(trim($settings['place_id'] ?? ''));
    ?>
    <style>
        /* ── WP Barrio Orari v7 ── */
        #wpb-orari-wrap {
            font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", sans-serif;
            max-width: 700px;
        }

        /* Header */
        .wpb-ori-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 8px;
        }

        .wpb-ori-header-icon {
            width: 40px;
            height: 40px;
            background: #111827;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .wpb-ori-header-icon .dashicons {
            font-size: 20px;
            width: 20px;
            height: 20px;
            color: #fff;
        }

        .wpb-ori-header h1 {
            font-size: 22px !important;
            font-weight: 700 !important;
            color: #111827 !important;
            margin: 0 !important;
            padding: 0 !important;
            line-height: 1.2 !important;
        }

        .wpb-ori-breadcrumb {
            font-size: 14px;
            font-weight: 400;
            color: #9ca3af;
            margin-left: 4px;
        }

        .wpb-ori-sub {
            font-size: 13px;
            color: #6b7280;
            margin: 2px 0 0;
        }

        .wpb-ori-divider {
            border: none;
            border-top: 1px solid #f3f4f6;
            margin: 14px 0 24px;
        }

        /* Cron status bar */
        .wpb-ori-status {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 18px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #374151;
        }

        .wpb-ori-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #d1d5db;
            flex-shrink: 0;
        }

        .wpb-ori-dot.active {
            background: #059669;
            box-shadow: 0 0 0 3px #dcfce7;
        }

        /* Section cards */
        .wpb-ori-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 22px 24px;
            margin-bottom: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .05);
        }

        .wpb-ori-card-head {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 5px;
        }

        .wpb-ori-card-head .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
            color: #6b7280;
        }

        .wpb-ori-card-title {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #374151;
            margin: 0;
        }

        .wpb-ori-card-desc {
            font-size: 12.5px;
            color: #9ca3af;
            margin: 0 0 20px;
            line-height: 1.5;
        }

        /* Form fields */
        .wpb-ori-field {
            margin-bottom: 20px;
        }

        .wpb-ori-field:last-of-type {
            margin-bottom: 0;
        }

        .wpb-ori-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .wpb-ori-input-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .wpb-ori-input {
            flex: 1;
            max-width: 420px;
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            font-size: 13px;
            color: #111827;
            background: #fff;
            font-family: ui-monospace, Consolas, monospace;
            transition: border-color .15s;
        }

        .wpb-ori-input:focus {
            outline: none;
            border-color: #111827;
        }

        .wpb-ori-hint {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 5px;
            line-height: 1.4;
        }

        .wpb-ori-hint a {
            color: #374151;
        }

        /* Buttons */
        .wpb-ori-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 600;
            background: #111827;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            transition: opacity .15s;
        }

        .wpb-ori-btn:hover {
            opacity: .85;
        }

        .wpb-ori-btn:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        .wpb-ori-btn .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
        }

        .wpb-ori-btn-ghost {
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #e5e7eb;
        }

        .wpb-ori-btn-ghost:hover {
            background: #e5e7eb;
        }

        /* Save/refresh feedback */
        .wpb-ori-feedback {
            display: none;
            margin-left: 12px;
            font-size: 13px;
            font-weight: 500;
        }

        .wpb-ori-feedback.success {
            color: #059669;
        }

        .wpb-ori-feedback.error {
            color: #dc2626;
        }

        .wpb-ori-save-ok {
            display: none;
            margin-left: 12px;
            font-size: 13px;
            color: #059669;
            font-weight: 500;
        }

        /* Last updated row */
        .wpb-ori-meta {
            font-size: 13px;
            color: #6b7280;
            margin: 0 0 18px;
        }

        .wpb-ori-meta strong {
            color: #111827;
        }

        /* Hours table */
        .wpb-ori-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
        }

        .wpb-ori-table thead th {
            background: #f9fafb;
            padding: 9px 14px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
        }

        .wpb-ori-table tbody th {
            font-weight: 600;
            color: #374151;
            background: transparent;
            padding: 9px 14px;
            border-bottom: 1px solid #f3f4f6;
            width: 130px;
        }

        .wpb-ori-table tbody td {
            padding: 9px 14px;
            border-bottom: 1px solid #f3f4f6;
        }

        .wpb-ori-table tbody tr:last-child th,
        .wpb-ori-table tbody tr:last-child td {
            border-bottom: none;
        }

        .wpb-ori-table tbody tr:hover th,
        .wpb-ori-table tbody tr:hover td {
            background: #f9fafb;
        }

        .wpb-slot {
            display: inline-flex;
            align-items: center;
            background: #dcfce7;
            color: #166534;
            font-weight: 600;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 12px;
            margin: 2px 4px 2px 0;
            font-variant-numeric: tabular-nums;
        }

        .wpb-chiuso {
            color: #9ca3af;
            font-style: italic;
            font-size: 12px;
        }

        /* Spinner */
        @keyframes wpb-ori-spin {
            to {
                transform: rotate(360deg);
            }
        }

        .wpb-ori-spinning {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, .3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: wpb-ori-spin .65s linear infinite;
            vertical-align: middle;
        }
    </style>

    <div id="wpb-orari-wrap" class="wrap">

        <!-- Header -->
        <div class="wpb-ori-header">
            <div class="wpb-ori-header-icon"><span class="dashicons dashicons-clock"></span></div>
            <div>
                <h1>WP Barrio <span class="wpb-ori-breadcrumb">/ Orari</span></h1>
                <p class="wpb-ori-sub">Sincronizzazione orari di apertura da Google Places</p>
            </div>
        </div>
        <hr class="wpb-ori-divider">

        <!-- Cron status -->
        <div class="wpb-ori-status">
            <span class="wpb-ori-dot <?php echo $next_cron ? 'active' : ''; ?>"></span>
            <?php if ($next_cron): ?>
                Aggiornamento automatico attivo &mdash; prossima esecuzione:
                <strong><?php echo esc_html(date_i18n('d/m/Y \a\l\l\e H:i', $next_cron + get_option('gmt_offset') * 3600)); ?></strong>
            <?php else: ?>
                Aggiornamento automatico non schedulato.
            <?php endif; ?>
        </div>

        <!-- Card: Credenziali -->
        <div class="wpb-ori-card">
            <div class="wpb-ori-card-head">
                <span class="dashicons dashicons-admin-network"></span>
                <h3 class="wpb-ori-card-title">Google Places API</h3>
            </div>
            <p class="wpb-ori-card-desc">Inserisci le credenziali per recuperare gli orari di apertura dal tuo profilo
                Google Business. Vengono usate sia dall'aggiornamento manuale che dal cron giornaliero.</p>

            <div class="wpb-ori-field">
                <label class="wpb-ori-label" for="wpb-orari-api-key">Google API Key</label>
                <div class="wpb-ori-input-row">
                    <input type="password" id="wpb-orari-api-key" class="wpb-ori-input"
                        value="<?php echo esc_attr($settings['google_api_key'] ?? ''); ?>" placeholder="AIza…"
                        autocomplete="new-password" spellcheck="false">
                    <button type="button" class="wpb-ori-btn wpb-ori-btn-ghost"
                        onclick="(function(){var i=document.getElementById('wpb-orari-api-key');i.type=i.type==='password'?'text':'password';})();"
                        style="padding:8px 14px;">
                        <span class="dashicons dashicons-visibility" style="font-size:15px;width:15px;height:15px;"></span>
                    </button>
                </div>
                <p class="wpb-ori-hint">Trovata su <a href="https://console.cloud.google.com/apis/credentials"
                        target="_blank" rel="noopener">Google Cloud Console → Credenziali</a>. Deve avere accesso alle API
                    <strong>Places (New)</strong>.
                </p>
            </div>

            <div class="wpb-ori-field">
                <label class="wpb-ori-label" for="wpb-orari-place-id">Place ID</label>
                <input type="text" id="wpb-orari-place-id" class="wpb-ori-input"
                    value="<?php echo esc_attr($settings['place_id'] ?? ''); ?>" placeholder="ChIJ…" autocomplete="off"
                    spellcheck="false">
                <p class="wpb-ori-hint">L'ID univoco del tuo locale su Google Maps. Cercalo con il <a
                        href="https://developers.google.com/maps/documentation/javascript/examples/places-placeid-finder"
                        target="_blank" rel="noopener">Place ID Finder</a>.</p>
            </div>

            <div style="display:flex;align-items:center;margin-top:20px;">
                <button type="button" class="wpb-ori-btn" id="wpb-orari-save-btn">
                    <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                    Salva impostazioni
                </button>
                <span id="wpb-orari-save-ok" class="wpb-ori-save-ok">&#x2713; Salvato</span>
            </div>
        </div>

        <!-- Card: Aggiornamento manuale -->
        <div class="wpb-ori-card">
            <div class="wpb-ori-card-head">
                <span class="dashicons dashicons-update"></span>
                <h3 class="wpb-ori-card-title">Aggiornamento orari</h3>
            </div>
            <p class="wpb-ori-card-desc">Recupera subito gli orari aggiornati da Google Places. L'operazione avviene anche
                automaticamente ogni notte alle 01:00.</p>

            <?php if (!empty($orari['ultimo_aggiornamento'])): ?>
                <p class="wpb-ori-meta">
                    Ultimo aggiornamento: <strong><?php echo esc_html($orari['ultimo_aggiornamento']); ?></strong>
                    <?php if (!empty($orari['display_name'])): ?>
                        &mdash; <?php echo esc_html($orari['display_name']); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <div style="display:flex;align-items:center;">
                <button type="button" class="wpb-ori-btn" id="wpb-orari-refresh-btn" <?php echo $configured ? '' : 'disabled'; ?>>
                    <span class="dashicons dashicons-update" id="wpb-refresh-icon"
                        style="font-size:16px;width:16px;height:16px;"></span>
                    Aggiorna orari da Google
                </button>
                <span id="wpb-orari-refresh-result" class="wpb-ori-feedback"></span>
            </div>

            <?php if (!$configured): ?>
                <p style="margin:12px 0 0;font-size:12px;color:#d97706;">
                    &#9888; Configura le credenziali API prima di aggiornare gli orari.
                </p>
            <?php endif; ?>
        </div>

        <!-- Card: Orari salvati -->
        <?php if (!empty($orari['orari_settimanali'])): ?>
            <div class="wpb-ori-card">
                <div class="wpb-ori-card-head">
                    <span class="dashicons dashicons-calendar-alt"></span>
                    <h3 class="wpb-ori-card-title">Orari salvati</h3>
                </div>
                <p class="wpb-ori-card-desc" style="margin-bottom:16px;">
                    Dati correntemente in uso. Aggiornati il <?php echo esc_html($orari['ultimo_aggiornamento'] ?? '—'); ?>.
                    <?php if ($graphql_active): ?>
                        <span style="color:#059669;">&#x2713; Disponibili via GraphQL (<code>hbOrari</code>)</span>
                    <?php else: ?>
                        <span style="color:#9ca3af;">WPGraphQL non attivo — gli orari non sono esposti via API.</span>
                    <?php endif; ?>
                </p>
                <div style="overflow-x:auto;">
                    <table class="wpb-ori-table">
                        <thead>
                            <tr>
                                <th>Giorno</th>
                                <th>Orari</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orari['orari_settimanali'] as $giorno => $slots): ?>
                                <tr>
                                    <th><?php echo esc_html($giorno); ?></th>
                                    <td>
                                        <?php if (empty($slots)): ?>
                                            <span class="wpb-chiuso">Chiuso</span>
                                        <?php else: ?>
                                            <?php foreach ($slots as $slot): ?>
                                                <span class="wpb-slot"><?php echo esc_html($slot); ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- #wpb-orari-wrap -->

    <script>
        (function () {
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

            /* ── Salva impostazioni ── */
            document.getElementById('wpb-orari-save-btn').addEventListener('click', function () {
                var btn = this;
                var ok = document.getElementById('wpb-orari-save-ok');
                btn.disabled = true;
                btn.innerHTML = '<div class="wpb-ori-spinning"></div> Salvataggio…';

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: new URLSearchParams({
                        action: 'wpb_orari_save_settings',
                        nonce: nonce,
                        google_api_key: document.getElementById('wpb-orari-api-key').value.trim(),
                        place_id: document.getElementById('wpb-orari-place-id').value.trim(),
                    })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            ok.style.display = 'inline';
                            setTimeout(function () { ok.style.display = 'none'; }, 3000);
                        } else {
                            alert(res.data || 'Errore nel salvataggio.');
                        }
                    })
                    .catch(function () { alert('Errore di rete.'); })
                    .finally(function () {
                        btn.disabled = false;
                        btn.innerHTML = '<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> Salva impostazioni';
                    });
            });

            /* ── Aggiorna orari ── */
            document.getElementById('wpb-orari-refresh-btn').addEventListener('click', function () {
                var btn = this;
                var result = document.getElementById('wpb-orari-refresh-result');
                btn.disabled = true;
                btn.innerHTML = '<div class="wpb-ori-spinning"></div> Aggiornamento…';
                result.style.display = 'none';
                result.className = 'wpb-ori-feedback';

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'wpb_orari_refresh', nonce: nonce })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        result.style.display = 'inline';
                        if (res.success) {
                            result.className = 'wpb-ori-feedback success';
                            result.textContent = '✓ Orari aggiornati — ricarica la pagina per vedere i dati.';
                        } else {
                            result.className = 'wpb-ori-feedback error';
                            result.textContent = '✗ ' + (res.data || 'Errore sconosciuto.');
                        }
                    })
                    .catch(function () {
                        result.style.display = 'inline';
                        result.className = 'wpb-ori-feedback error';
                        result.textContent = '✗ Errore di rete.';
                    })
                    .finally(function () {
                        btn.disabled = false;
                        btn.innerHTML = '<span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;"></span> Aggiorna orari da Google';
                    });
            });
        })();
    </script>
    <?php
}