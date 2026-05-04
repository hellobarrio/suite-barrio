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

define('WPB_ORARI_VERSION',    '1.0.0');
define('WPB_ORARI_OPTION',     'wpb_orari_settings');   // credenziali globali
define('WPB_ORARI_DATA_OPTION','wpb_orari_data');        // dati orari salvati
define('WPB_ORARI_CRON_HOOK',  'wpb_cron_aggiorna_orari');

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
    $settings  = get_option(WPB_ORARI_OPTION, []);
    $api_key   = trim($settings['google_api_key'] ?? '');
    $place_id  = trim($settings['place_id'] ?? '');

    if (!$api_key || !$place_id) {
        return ['success' => false, 'message' => 'API Key o Place ID mancanti.'];
    }

    $url = "https://places.googleapis.com/v1/places/{$place_id}?fields=currentOpeningHours,displayName&key={$api_key}";

    $response = wp_remote_get($url, [
        'timeout' => 15,
        'headers' => [
            'Content-Type'      => 'application/json',
            'X-Goog-Api-Locale' => 'it',
        ],
    ]);

    if (is_wp_error($response)) {
        return ['success' => false, 'message' => 'Errore HTTP: ' . $response->get_error_message()];
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body      = json_decode(wp_remote_retrieve_body($response), true);

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
        $day_idx     = $period['open']['day'];
        $giorno_nome = $giorni_it[$day_idx];
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
        'display_name'         => $display_name,
        'orari_settimanali'    => $settimana,
    ];

    update_option(WPB_ORARI_DATA_OPTION, $data, false);

    // Invalida cache Vercel se la funzione è disponibile
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:orari');
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

    $api_key  = sanitize_text_field(wp_unslash($_POST['google_api_key'] ?? ''));
    $place_id = sanitize_text_field(wp_unslash($_POST['place_id'] ?? ''));

    update_option(WPB_ORARI_OPTION, [
        'google_api_key' => $api_key,
        'place_id'       => $place_id,
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
    $settings  = get_option(WPB_ORARI_OPTION, []);
    $orari     = get_option(WPB_ORARI_DATA_OPTION, []);
    $next_cron = wp_next_scheduled(WPB_ORARI_CRON_HOOK);
    $nonce     = wp_create_nonce('wpb_orari_nonce');
    $graphql_active = function_exists('register_graphql_field');
    ?>
    <style>
        #wpb-orari-wrap { max-width: 700px; }

        .wpb-orari-card {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            padding: 24px;
            margin-bottom: 20px;
        }

        .wpb-orari-card h3 {
            margin: 0 0 4px;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: #50575e;
        }

        .wpb-orari-card .wpb-card-desc {
            margin: 0 0 20px;
            font-size: 12px;
            color: #a7aaad;
        }

        .wpb-orari-field { margin-bottom: 20px; }

        .wpb-orari-field label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 5px;
        }

        .wpb-orari-field input {
            width: 100%;
            max-width: 420px;
        }

        .wpb-orari-field .description {
            margin-top: 4px;
            font-size: 12px;
            color: #646970;
        }

        /* Tabella orari */
        .wpb-orari-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 4px;
        }

        .wpb-orari-table th,
        .wpb-orari-table td {
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid #f0f0f1;
        }

        .wpb-orari-table th {
            font-weight: 600;
            color: #50575e;
            background: #f6f7f7;
            width: 130px;
        }

        .wpb-orari-table tr:last-child th,
        .wpb-orari-table tr:last-child td {
            border-bottom: none;
        }

        .wpb-orari-table td .wpb-slot {
            display: inline-block;
            background: #e8f5e9;
            color: #1b5e20;
            border-radius: 3px;
            padding: 2px 8px;
            font-size: 12px;
            margin: 2px 4px 2px 0;
            font-variant-numeric: tabular-nums;
        }

        .wpb-orari-table td .wpb-chiuso {
            color: #a7aaad;
            font-style: italic;
            font-size: 12px;
        }

        /* Status bar */
        .wpb-orari-status {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: #f6f7f7;
            border-radius: 3px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #50575e;
        }

        .wpb-orari-status .wpb-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #a7aaad;
            flex-shrink: 0;
        }

        .wpb-orari-status .wpb-dot.active { background: #00a32a; }

        #wpb-orari-refresh-result {
            display: none;
            margin-left: 12px;
            font-size: 13px;
        }

        #wpb-orari-refresh-result.success { color: #00a32a; }
        #wpb-orari-refresh-result.error   { color: #d63638; }
    </style>

    <div id="wpb-orari-wrap" class="wrap">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-clock"
                style="font-size:22px;width:22px;height:22px;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
            WP Barrio <span style="font-size:15px;font-weight:400;color:#646970;margin-left:4px;">/ Orari</span>
        </h1>
        <hr class="wp-header-end" style="margin-bottom:20px;">

        <!-- Stato cron -->
        <div class="wpb-orari-status">
            <span class="wpb-dot <?php echo $next_cron ? 'active' : ''; ?>"></span>
            <?php if ($next_cron): ?>
                Aggiornamento automatico attivo — prossima esecuzione:
                <strong><?php echo esc_html(date_i18n('d/m/Y \a\l\l\e H:i', $next_cron + get_option('gmt_offset') * 3600)); ?></strong>
            <?php else: ?>
                Aggiornamento automatico non schedulato.
            <?php endif; ?>
        </div>

        <!-- Card impostazioni -->
        <div class="wpb-orari-card">
            <h3>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"
                    style="vertical-align:middle;margin-right:5px;margin-bottom:1px;">
                    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5S13.38 11.5 12 11.5z"
                        fill="#50575e"/>
                </svg>
                Google Places API
            </h3>
            <p class="wpb-card-desc">
                Inserisci le credenziali per recuperare gli orari di apertura dal tuo profilo Google Business.
                Le credenziali vengono usate sia dall'aggiornamento manuale che dal cron giornaliero.
            </p>

            <div class="wpb-orari-field">
                <label for="wpb-orari-api-key">Google API Key</label>
                <input type="password" id="wpb-orari-api-key" class="regular-text"
                    value="<?php echo esc_attr($settings['google_api_key'] ?? ''); ?>"
                    placeholder="AIza..." autocomplete="new-password" spellcheck="false">
                <div style="display:inline-block;margin-left:8px;vertical-align:middle;">
                    <button type="button" class="button"
                        onclick="(function(){var i=document.getElementById('wpb-orari-api-key');i.type=i.type==='password'?'text':'password';})();"
                        style="font-size:12px;padding:3px 9px;">Mostra</button>
                </div>
                <p class="description">
                    Trovata su <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console → Credenziali</a>.
                    Deve avere accesso alle API <strong>Places (New)</strong>.
                </p>
            </div>

            <div class="wpb-orari-field">
                <label for="wpb-orari-place-id">Place ID</label>
                <input type="text" id="wpb-orari-place-id" class="regular-text"
                    value="<?php echo esc_attr($settings['place_id'] ?? ''); ?>"
                    placeholder="ChIJ..." autocomplete="off" spellcheck="false">
                <p class="description">
                    L'ID univoco del tuo locale su Google Maps. Cercalo su
                    <a href="https://developers.google.com/maps/documentation/javascript/examples/places-placeid-finder" target="_blank" rel="noopener">Place ID Finder</a>.
                </p>
            </div>

            <p style="margin:0;">
                <button type="button" class="button button-primary" id="wpb-orari-save-btn">
                    Salva impostazioni
                </button>
                <span id="wpb-orari-save-msg" style="display:none;margin-left:10px;color:#00a32a;font-size:13px;">✓ Salvato</span>
            </p>
        </div>

        <!-- Card aggiornamento manuale -->
        <div class="wpb-orari-card">
            <h3>
                <span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:5px;"></span>
                Aggiornamento orari
            </h3>
            <p class="wpb-card-desc">
                Recupera subito gli orari aggiornati da Google Places. L'operazione avviene anche
                automaticamente ogni notte alle 01:00.
            </p>

            <?php if (!empty($orari['ultimo_aggiornamento'])): ?>
                <p style="font-size:13px;color:#646970;margin:0 0 16px;">
                    Ultimo aggiornamento:
                    <strong><?php echo esc_html($orari['ultimo_aggiornamento']); ?></strong>
                    <?php if (!empty($orari['display_name'])): ?>
                        &mdash; <?php echo esc_html($orari['display_name']); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <p style="margin:0;">
                <button type="button" class="button button-primary" id="wpb-orari-refresh-btn">
                    <span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;margin-top:-1px;"></span>
                    Aggiorna orari da Google
                </button>
                <span id="wpb-orari-refresh-result"></span>
            </p>
        </div>

        <!-- Card orari correnti -->
        <?php if (!empty($orari['orari_settimanali'])): ?>
        <div class="wpb-orari-card">
            <h3>
                <span class="dashicons dashicons-calendar-alt" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:5px;"></span>
                Orari salvati
            </h3>
            <p class="wpb-card-desc" style="margin-bottom:12px;">
                Dati correntemente in uso. Aggiornati il <?php echo esc_html($orari['ultimo_aggiornamento'] ?? '—'); ?>.
            </p>
            <table class="wpb-orari-table">
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
        <?php endif; ?>

    </div>

    <script>
    (function () {
        var nonce   = <?php echo wp_json_encode($nonce); ?>;
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

        /* ── Salva impostazioni ── */
        document.getElementById('wpb-orari-save-btn').addEventListener('click', function () {
            var btn = this;
            var msg = document.getElementById('wpb-orari-save-msg');
            btn.disabled = true;
            btn.textContent = 'Salvataggio…';

            var fd = new URLSearchParams({
                action:         'wpb_orari_save_settings',
                nonce:          nonce,
                google_api_key: document.getElementById('wpb-orari-api-key').value.trim(),
                place_id:       document.getElementById('wpb-orari-place-id').value.trim(),
            });

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        msg.style.display = 'inline';
                        setTimeout(function () { msg.style.display = 'none'; }, 3000);
                    } else {
                        alert(res.data || 'Errore nel salvataggio.');
                    }
                })
                .catch(function () { alert('Errore di rete.'); })
                .finally(function () {
                    btn.disabled = false;
                    btn.textContent = 'Salva impostazioni';
                });
        });

        /* ── Aggiorna orari ── */
        document.getElementById('wpb-orari-refresh-btn').addEventListener('click', function () {
            var btn    = this;
            var result = document.getElementById('wpb-orari-refresh-result');

            btn.disabled = true;
            btn.innerHTML = '<span class="dashicons dashicons-update wpb-spin" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;margin-top:-1px;animation:wpb-spin .8s linear infinite;"></span> Aggiornamento…';
            result.style.display = 'none';
            result.className = '';

            var fd = new URLSearchParams({
                action: 'wpb_orari_refresh',
                nonce:  nonce,
            });

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    result.style.display = 'inline';
                    if (res.success) {
                        result.className = 'success';
                        result.textContent = '✓ Orari aggiornati — ricarica la pagina per vedere i dati.';
                    } else {
                        result.className = 'error';
                        result.textContent = '✗ ' + (res.data || 'Errore sconosciuto.');
                    }
                })
                .catch(function () {
                    result.style.display = 'inline';
                    result.className = 'error';
                    result.textContent = '✗ Errore di rete.';
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.innerHTML = '<span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;margin-top:-1px;"></span> Aggiorna orari da Google';
                });
        });
    })();
    </script>
    <style>
        @keyframes wpb-spin { to { transform: rotate(360deg); } }
    </style>
    <?php
}
