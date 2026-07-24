<?php
/**
 * Suite Barrio — Orari
 * Sincronizzazione automatica degli orari di apertura da Google Places API.
 * Visualizza un pannello admin per configurare le credenziali e aggiornare
 * gli orari manualmente o tramite cron giornaliero.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('SUITE_BARRIO_ORARI_VERSION', '1.0.0');
define('SUITE_BARRIO_ORARI_OPTION', 'suite_barrio_orari_settings');   // credenziali globali
define('SUITE_BARRIO_ORARI_DATA_OPTION', 'suite_barrio_orari_data');        // dati orari salvati
define('SUITE_BARRIO_ORARI_CRON_HOOK', 'suite_barrio_cron_aggiorna_orari');

/* ─────────────────────────────────────────
   MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    add_submenu_page(
        'suite-barrio',
        'Orari — Suite Barrio',
        'Orari',
        'manage_options',
        'suite-barrio-orari',
        'suite_barrio_orari_admin_page'
    );
}, 15);

/* ─────────────────────────────────────────
   ENQUEUE — script inline solo nella pagina Orari
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'suite-barrio_page_suite-barrio-orari')
        return;

    wp_register_script('suite-barrio-orari-admin', '', [], SUITE_BARRIO_ORARI_VERSION, true);
    wp_enqueue_script('suite-barrio-orari-admin');
    wp_add_inline_script('suite-barrio-orari-admin', suite_barrio_orari_inline_js());
});

/* ─────────────────────────────────────────
   CRON: schedulazione giornaliera ore 01:00
   ───────────────────────────────────────── */
add_action('init', function () {
    if (!wp_next_scheduled(SUITE_BARRIO_ORARI_CRON_HOOK)) {
        $orario = strtotime('today 01:00');
        if ($orario <= time()) {
            $orario = strtotime('tomorrow 01:00');
        }
        wp_schedule_event($orario, 'daily', SUITE_BARRIO_ORARI_CRON_HOOK);
    }
});

add_action(SUITE_BARRIO_ORARI_CRON_HOOK, 'suite_barrio_orari_fetch_and_save');

/* ─────────────────────────────────────────
   HELPER: recupera e salva gli orari da Google
   ───────────────────────────────────────── */
function suite_barrio_orari_fetch_and_save(): array
{
    $settings = get_option(SUITE_BARRIO_ORARI_OPTION, []);
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

    update_option(SUITE_BARRIO_ORARI_DATA_OPTION, $data, false);

    // Invalida cache Vercel se la funzione è disponibile
    if (function_exists('suite_barrio_invalidate_vercel_cache')) {
        suite_barrio_invalidate_vercel_cache('wp:orari');
    }

    return ['success' => true, 'message' => 'Orari aggiornati con successo.', 'data' => $data];
}

/* ─────────────────────────────────────────
   AJAX ADMIN: salva impostazioni
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_orari_save_settings', function () {
    check_ajax_referer('suite_barrio_orari_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $api_key = sanitize_text_field(wp_unslash($_POST['google_api_key'] ?? ''));
    $place_id = sanitize_text_field(wp_unslash($_POST['place_id'] ?? ''));

    update_option(SUITE_BARRIO_ORARI_OPTION, [
        'google_api_key' => $api_key,
        'place_id' => $place_id,
    ], false);

    wp_send_json_success('Impostazioni salvate.');
});

/* ─────────────────────────────────────────
   AJAX ADMIN: aggiornamento manuale
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_orari_refresh', function () {
    check_ajax_referer('suite_barrio_orari_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $result = suite_barrio_orari_fetch_and_save();

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result['message']);
    }
});

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function suite_barrio_orari_admin_page()
{
    $settings = get_option(SUITE_BARRIO_ORARI_OPTION, []);
    $orari = get_option(SUITE_BARRIO_ORARI_DATA_OPTION, []);
    $next_cron = wp_next_scheduled(SUITE_BARRIO_ORARI_CRON_HOOK);
    $graphql_active = function_exists('register_graphql_field');
    $configured = !empty(trim($settings['google_api_key'] ?? '')) && !empty(trim($settings['place_id'] ?? ''));
    ?>
<div id="suite-barrio-orari-wrap" class="wrap suite-barrio-wrap">

        <!-- Header -->
        <div class="suite-barrio-ori-header">
            <div class="suite-barrio-ori-header-icon"><span class="dashicons dashicons-clock"></span></div>
            <div>
                <h1>Suite Barrio <span class="suite-barrio-ori-breadcrumb">/ Orari</span></h1>
                <p class="suite-barrio-ori-sub">Sincronizzazione orari di apertura da Google Places</p>
            </div>
        </div>
        <hr class="suite-barrio-ori-divider">

        <!-- Cron status -->
        <div class="suite-barrio-ori-status">
            <span class="suite-barrio-ori-dot <?php echo $next_cron ? 'active' : ''; ?>"></span>
            <?php if ($next_cron): ?>
                Aggiornamento automatico attivo &mdash; prossima esecuzione:
                <strong><?php echo esc_html(date_i18n('d/m/Y \a\l\l\e H:i', $next_cron + get_option('gmt_offset') * 3600)); ?></strong>
            <?php else: ?>
                Aggiornamento automatico non schedulato.
            <?php endif; ?>
        </div>

        <!-- Card: Credenziali -->
        <div class="suite-barrio-ori-card">
            <div class="suite-barrio-ori-card-head">
                <span class="dashicons dashicons-admin-network"></span>
                <h3 class="suite-barrio-ori-card-title">Google Places API</h3>
            </div>
            <p class="suite-barrio-ori-card-desc">Inserisci le credenziali per recuperare gli orari di apertura dal tuo profilo
                Google Business. Vengono usate sia dall'aggiornamento manuale che dal cron giornaliero.</p>

            <div class="suite-barrio-ori-field">
                <label class="suite-barrio-ori-label" for="suite-barrio-orari-api-key">Google API Key</label>
                <div class="suite-barrio-ori-input-row">
                    <input type="password" id="suite-barrio-orari-api-key" class="suite-barrio-ori-input"
                        value="<?php echo esc_attr($settings['google_api_key'] ?? ''); ?>" placeholder="AIza…"
                        autocomplete="new-password" spellcheck="false">
                    <button type="button" class="suite-barrio-ori-btn suite-barrio-ori-btn-ghost"
                        onclick="(function(){var i=document.getElementById('suite-barrio-orari-api-key');i.type=i.type==='password'?'text':'password';})();"
                        style="padding:8px 14px;">
                        <span class="dashicons dashicons-visibility" style="font-size:15px;width:15px;height:15px;"></span>
                    </button>
                </div>
                <p class="suite-barrio-ori-hint">Trovata su <a href="https://console.cloud.google.com/apis/credentials"
                        target="_blank" rel="noopener">Google Cloud Console → Credenziali</a>. Deve avere accesso alle API
                    <strong>Places (New)</strong>.
                </p>
            </div>

            <div class="suite-barrio-ori-field">
                <label class="suite-barrio-ori-label" for="suite-barrio-orari-place-id">Place ID</label>
                <input type="text" id="suite-barrio-orari-place-id" class="suite-barrio-ori-input"
                    value="<?php echo esc_attr($settings['place_id'] ?? ''); ?>" placeholder="ChIJ…" autocomplete="off"
                    spellcheck="false">
                <p class="suite-barrio-ori-hint">L'ID univoco del tuo locale su Google Maps. Cercalo con il <a
                        href="https://developers.google.com/maps/documentation/javascript/examples/places-placeid-finder"
                        target="_blank" rel="noopener">Place ID Finder</a>.</p>
            </div>

            <div style="display:flex;align-items:center;margin-top:20px;">
                <button type="button" class="suite-barrio-ori-btn" id="suite-barrio-orari-save-btn">
                    <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                    Salva impostazioni
                </button>
                <span id="suite-barrio-orari-save-ok" class="suite-barrio-ori-save-ok">&#x2713; Salvato</span>
            </div>
        </div>

        <!-- Card: Aggiornamento manuale -->
        <div class="suite-barrio-ori-card">
            <div class="suite-barrio-ori-card-head">
                <span class="dashicons dashicons-update"></span>
                <h3 class="suite-barrio-ori-card-title">Aggiornamento orari</h3>
            </div>
            <p class="suite-barrio-ori-card-desc">Recupera subito gli orari aggiornati da Google Places. L'operazione avviene anche
                automaticamente ogni notte alle 01:00.</p>

            <?php if (!empty($orari['ultimo_aggiornamento'])): ?>
                <p class="suite-barrio-ori-meta">
                    Ultimo aggiornamento: <strong><?php echo esc_html($orari['ultimo_aggiornamento']); ?></strong>
                    <?php if (!empty($orari['display_name'])): ?>
                        &mdash; <?php echo esc_html($orari['display_name']); ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <div style="display:flex;align-items:center;">
                <button type="button" class="suite-barrio-ori-btn" id="suite-barrio-orari-refresh-btn" <?php echo $configured ? '' : 'disabled'; ?>>
                    <span class="dashicons dashicons-update" id="suite-barrio-refresh-icon"
                        style="font-size:16px;width:16px;height:16px;"></span>
                    Aggiorna orari da Google
                </button>
                <span id="suite-barrio-orari-refresh-result" class="suite-barrio-ori-feedback"></span>
            </div>

            <?php if (!$configured): ?>
                <p style="margin:12px 0 0;font-size:12px;color:#d97706;">
                    &#9888; Configura le credenziali API prima di aggiornare gli orari.
                </p>
            <?php endif; ?>
        </div>

        <!-- Card: Orari salvati -->
        <?php if (!empty($orari['orari_settimanali'])): ?>
            <div class="suite-barrio-ori-card">
                <div class="suite-barrio-ori-card-head">
                    <span class="dashicons dashicons-calendar-alt"></span>
                    <h3 class="suite-barrio-ori-card-title">Orari salvati</h3>
                </div>
                <p class="suite-barrio-ori-card-desc" style="margin-bottom:16px;">
                    Dati correntemente in uso. Aggiornati il <?php echo esc_html($orari['ultimo_aggiornamento'] ?? '—'); ?>.
                    <?php if ($graphql_active): ?>
                        <span style="color:#059669;">&#x2713; Disponibili via GraphQL (<code>hbOrari</code>)</span>
                    <?php else: ?>
                        <span style="color:#9ca3af;">WPGraphQL non attivo — gli orari non sono esposti via API.</span>
                    <?php endif; ?>
                </p>
                <div style="overflow-x:auto;">
                    <table class="suite-barrio-ori-table">
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
                                            <span class="suite-barrio-chiuso">Chiuso</span>
                                        <?php else: ?>
                                            <?php foreach ($slots as $slot): ?>
                                                <span class="suite-barrio-slot"><?php echo esc_html($slot); ?></span>
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

    </div><!-- #suite-barrio-orari-wrap -->
    <?php
}

/* ─────────────────────────────────────────
   JS INLINE: interazioni pagina admin (salva impostazioni + refresh)
   Agganciato via wp_add_inline_script() all'handle 'suite-barrio-orari-admin'
   registrato in admin_enqueue_scripts.
   ───────────────────────────────────────── */
function suite_barrio_orari_inline_js(): string
{
    $nonce_json = wp_json_encode(wp_create_nonce('suite_barrio_orari_nonce'));
    $ajax_url_json = wp_json_encode(admin_url('admin-ajax.php'));

    $js = '(function () {
    var nonce = __SUITE_BARRIO_ORARI_NONCE__;
    var ajaxUrl = __SUITE_BARRIO_ORARI_AJAX_URL__;

    /* ── Salva impostazioni ── */
    document.getElementById(\'suite-barrio-orari-save-btn\').addEventListener(\'click\', function () {
        var btn = this;
        var ok = document.getElementById(\'suite-barrio-orari-save-ok\');
        btn.disabled = true;
        btn.innerHTML = \'<div class="suite-barrio-ori-spinning"></div> Salvataggio…\';

        fetch(ajaxUrl, {
            method: \'POST\',
            body: new URLSearchParams({
                action: \'suite_barrio_orari_save_settings\',
                nonce: nonce,
                google_api_key: document.getElementById(\'suite-barrio-orari-api-key\').value.trim(),
                place_id: document.getElementById(\'suite-barrio-orari-place-id\').value.trim(),
            })
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    ok.style.display = \'inline\';
                    setTimeout(function () { ok.style.display = \'none\'; }, 3000);
                } else {
                    alert(res.data || \'Errore nel salvataggio.\');
                }
            })
            .catch(function () { alert(\'Errore di rete.\'); })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = \'<span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span> Salva impostazioni\';
            });
    });

    /* ── Aggiorna orari ── */
    document.getElementById(\'suite-barrio-orari-refresh-btn\').addEventListener(\'click\', function () {
        var btn = this;
        var result = document.getElementById(\'suite-barrio-orari-refresh-result\');
        btn.disabled = true;
        btn.innerHTML = \'<div class="suite-barrio-ori-spinning"></div> Aggiornamento…\';
        result.style.display = \'none\';
        result.className = \'suite-barrio-ori-feedback\';

        fetch(ajaxUrl, {
            method: \'POST\',
            body: new URLSearchParams({ action: \'suite_barrio_orari_refresh\', nonce: nonce })
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                result.style.display = \'inline\';
                if (res.success) {
                    result.className = \'suite-barrio-ori-feedback success\';
                    result.textContent = \'✓ Orari aggiornati — ricarica la pagina per vedere i dati.\';
                } else {
                    result.className = \'suite-barrio-ori-feedback error\';
                    result.textContent = \'✗ \' + (res.data || \'Errore sconosciuto.\');
                }
            })
            .catch(function () {
                result.style.display = \'inline\';
                result.className = \'suite-barrio-ori-feedback error\';
                result.textContent = \'✗ Errore di rete.\';
            })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = \'<span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;"></span> Aggiorna orari da Google\';
            });
    });
})();';

    return str_replace(
        ['__SUITE_BARRIO_ORARI_NONCE__', '__SUITE_BARRIO_ORARI_AJAX_URL__'],
        [$nonce_json, $ajax_url_json],
        $js
    );
}