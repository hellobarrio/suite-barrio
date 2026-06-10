<?php
/**
 * WP Barrio — Statistiche
 * Integrazione Google Analytics 4 tramite OAuth 2.0 (Authorization Code Flow).
 *
 * Flusso:
 *  1. Admin inserisce Client ID + Client Secret (da Google Cloud Console).
 *  2. Clicca "Connetti con Google" → viene reindirizzato alla schermata OAuth di Google.
 *  3. Dopo l'autorizzazione, Google rimanda qui con un ?code=... che viene scambiato
 *     con un access token + refresh token (salvati in wp_options, criptati).
 *  4. Da quel momento i dati GA4 vengono recuperati automaticamente tramite la
 *     Google Analytics Data API v1beta, cachati 30 min in transient.
 *  5. La bacheca WordPress viene sostituita con KPI, grafico, top pages, sorgenti.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('WPB_STATS_VERSION',        '3.0.0');
define('WPB_STATS_OPTION',         'wpb_stats_settings');
define('WPB_STATS_TOKEN_OPTION',   'wpb_stats_tokens');
define('WPB_STATS_API_BASE',       'https://analyticsdata.googleapis.com/v1beta/properties/');
define('WPB_STATS_TOKEN_URL',      'https://oauth2.googleapis.com/token');
define('WPB_STATS_AUTH_URL',       'https://accounts.google.com/o/oauth2/v2/auth');
define('WPB_STATS_CACHE_TTL',       1800); // 30 minuti
define('WPB_STATS_OAUTH_SCOPE',    'https://www.googleapis.com/auth/analytics.readonly');
define('WPB_STATS_REDIRECT_PATH',  'admin.php?page=wp-barrio-stats&wpb_oauth_callback=1');

/* ─────────────────────────────────────────
   HELPERS — impostazioni e token
   ───────────────────────────────────────── */
function wpb_stats_settings(): array
{
    return get_option(WPB_STATS_OPTION, []);
}

function wpb_stats_tokens(): array
{
    return get_option(WPB_STATS_TOKEN_OPTION, []);
}

function wpb_stats_redirect_uri(): string
{
    return admin_url(WPB_STATS_REDIRECT_PATH);
}

function wpb_stats_is_configured(): bool
{
    $s = wpb_stats_settings();
    return !empty($s['client_id']) && !empty($s['client_secret']) && !empty($s['property_id']);
}

function wpb_stats_is_connected(): bool
{
    $t = wpb_stats_tokens();
    return !empty($t['refresh_token']);
}

function wpb_stats_replace_active(): bool
{
    $s = wpb_stats_settings();
    return !empty($s['replace_dashboard']) && wpb_stats_is_connected();
}

/* ─────────────────────────────────────────
   OAUTH — ottieni access token (con rinnovo automatico)
   ───────────────────────────────────────── */
function wpb_stats_get_access_token(): string|false
{
    $tokens = wpb_stats_tokens();
    if (empty($tokens['refresh_token'])) return false;

    // Usa il token in cache se ancora valido (scade tra più di 60 sec)
    if (!empty($tokens['access_token']) && !empty($tokens['expires_at'])) {
        if ($tokens['expires_at'] > time() + 60) {
            return $tokens['access_token'];
        }
    }

    // Rinnova tramite refresh_token
    $s = wpb_stats_settings();
    $response = wp_remote_post(WPB_STATS_TOKEN_URL, [
        'timeout' => 15,
        'body'    => [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id'     => $s['client_id'],
            'client_secret' => $s['client_secret'],
        ],
    ]);

    if (is_wp_error($response)) return false;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['access_token'])) return false;

    // Salva il nuovo access token
    $tokens['access_token'] = $body['access_token'];
    $tokens['expires_at']   = time() + (int)($body['expires_in'] ?? 3600);
    update_option(WPB_STATS_TOKEN_OPTION, $tokens);

    return $body['access_token'];
}

/* ─────────────────────────────────────────
   OAUTH — scambia authorization code con tokens
   ───────────────────────────────────────── */
function wpb_stats_exchange_code(string $code): bool
{
    $s = wpb_stats_settings();
    $response = wp_remote_post(WPB_STATS_TOKEN_URL, [
        'timeout' => 15,
        'body'    => [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => wpb_stats_redirect_uri(),
            'client_id'     => $s['client_id'],
            'client_secret' => $s['client_secret'],
        ],
    ]);

    if (is_wp_error($response)) return false;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['access_token'])) return false;

    update_option(WPB_STATS_TOKEN_OPTION, [
        'access_token'  => $body['access_token'],
        'refresh_token' => $body['refresh_token'] ?? '',
        'expires_at'    => time() + (int)($body['expires_in'] ?? 3600),
    ]);

    return true;
}

/* ─────────────────────────────────────────
   DATA API — chiama runReport
   ───────────────────────────────────────── */
function wpb_stats_run_report(
    string $property_id,
    array  $dimensions,
    array  $metrics,
    string $start_date,
    string $end_date,
    int    $limit = 10
): array|false {

    $token = wpb_stats_get_access_token();
    if (!$token) return false;

    $body = [
        'dateRanges' => [['startDate' => $start_date, 'endDate' => $end_date]],
        'metrics'    => $metrics,
        'limit'      => $limit,
    ];
    if (!empty($dimensions)) {
        $body['dimensions'] = $dimensions;
    }

    $response = wp_remote_post(
        WPB_STATS_API_BASE . $property_id . ':runReport',
        [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode($body),
        ]
    );

    if (is_wp_error($response)) return false;

    $http_code = wp_remote_retrieve_response_code($response);
    $parsed    = json_decode(wp_remote_retrieve_body($response), true);

    if ($http_code !== 200 || isset($parsed['error'])) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WPB Stats] API error ' . $http_code . ': ' . wp_json_encode($parsed['error'] ?? $parsed));
        }
        // Token revocato: pulisci i token salvati
        if ($http_code === 401) {
            delete_option(WPB_STATS_TOKEN_OPTION);
        }
        return false;
    }

    return $parsed;
}

/* ─────────────────────────────────────────
   DATA — recupera tutti i dati per la bacheca
   ───────────────────────────────────────── */
function wpb_stats_get_dashboard_data(bool $force = false): array|false
{
    $s           = wpb_stats_settings();
    $property_id = $s['property_id'] ?? '';
    if (!$property_id || !wpb_stats_is_connected()) return false;

    $cache_key = 'wpb_stats_dash_' . $property_id;

    if (!$force) {
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;
    }

    $data      = [];
    $any_error = false;

    // ── 1. Totali: utenti attivi + pageview per 3 periodi ────────────────
    foreach ([
        'today' => ['today',     'today'],
        'week'  => ['7daysAgo',  'today'],
        'month' => ['30daysAgo', 'today'],
    ] as $period => [$start, $end]) {
        $res = wpb_stats_run_report($property_id, [], [
            ['name' => 'activeUsers'],
            ['name' => 'screenPageViews'],
        ], $start, $end, 1);

        if ($res === false) {
            $any_error = true;
            $data['totals'][$period] = ['users' => 0, 'pageviews' => 0];
            continue;
        }
        $tv = $res['totals'][0]['metricValues']
           ?? $res['rows'][0]['metricValues']
           ?? [];
        $data['totals'][$period] = [
            'users'     => $tv[0]['value'] ?? 0,
            'pageviews' => $tv[1]['value'] ?? 0,
        ];
    }

    // ── 2. Top 10 pagine (30gg) ───────────────────────────────────────────
    $res_pages = wpb_stats_run_report($property_id,
        [['name' => 'pagePath'], ['name' => 'pageTitle']],
        [['name' => 'screenPageViews']],
        '30daysAgo', 'today', 10
    );
    if ($res_pages === false) $any_error = true;
    $data['top_pages'] = $res_pages['rows'] ?? [];

    // ── 3. Sorgenti di traffico (30gg) ────────────────────────────────────
    $res_src = wpb_stats_run_report($property_id,
        [['name' => 'sessionDefaultChannelGroup']],
        [['name' => 'sessions']],
        '30daysAgo', 'today', 8
    );
    if ($res_src === false) $any_error = true;
    $data['sources'] = $res_src['rows'] ?? [];

    // ── 4. Andamento giornaliero 30gg ─────────────────────────────────────
    $res_daily = wpb_stats_run_report($property_id,
        [['name' => 'date']],
        [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
        '30daysAgo', 'today', 31
    );
    if ($res_daily === false) $any_error = true;
    $data['daily'] = $res_daily['rows'] ?? [];

    // ── 5. Dispositivi (30gg) ─────────────────────────────────────────────
    $res_devices = wpb_stats_run_report($property_id,
        [['name' => 'deviceCategory']],
        [['name' => 'sessions'], ['name' => 'activeUsers']],
        '30daysAgo', 'today', 5
    );
    if ($res_devices === false) $any_error = true;
    $data['devices'] = $res_devices['rows'] ?? [];

    // ── 6. Tempo medio sulla pagina (30gg) ───────────────────────────────
    $res_time = wpb_stats_run_report($property_id,
        [],
        [['name' => 'averageSessionDuration'], ['name' => 'bounceRate']],
        '30daysAgo', 'today', 1
    );
    if ($res_time === false) $any_error = true;
    $tv_time = $res_time['totals'][0]['metricValues'] ?? $res_time['rows'][0]['metricValues'] ?? [];
    $data['avg_session_duration'] = round((float)($tv_time[0]['value'] ?? 0));
    $data['bounce_rate']          = round((float)($tv_time[1]['value'] ?? 0) * 100, 1);

    // ── 7. Dati demografici: età (30gg) ──────────────────────────────────
    // ── 7. Paesi di provenienza (30gg) ───────────────────────
    $res_countries = wpb_stats_run_report($property_id,
        [['name' => 'country']],
        [['name' => 'activeUsers'], ['name' => 'sessions']],
        '30daysAgo', 'today', 10
    );
    if ($res_countries === false) $any_error = true;
    $data['countries'] = $res_countries['rows'] ?? [];

    $data['fetched_at'] = time();
    $data['has_error']  = $any_error;

    if (!$any_error) {
        set_transient($cache_key, $data, WPB_STATS_CACHE_TTL);
    }

    return $data;
}

/* ─────────────────────────────────────────
   GESTIONE CALLBACK OAUTH
   Intercetta ?wpb_oauth_callback=1 prima che
   WordPress carichi la pagina admin.
   ───────────────────────────────────────── */
add_action('admin_init', function () {
    if (empty($_GET['wpb_oauth_callback'])) return;
    if (!current_user_can('manage_options')) return;

    // Errore restituito da Google
    if (!empty($_GET['error'])) {
        wp_redirect(admin_url('admin.php?page=wp-barrio-stats&oauth_error=' . urlencode($_GET['error'])));
        exit;
    }

    // Verifica state anti-CSRF
    $state = sanitize_text_field($_GET['state'] ?? '');
    if (!wp_verify_nonce($state, 'wpb_stats_oauth_state')) {
        wp_redirect(admin_url('admin.php?page=wp-barrio-stats&oauth_error=state_mismatch'));
        exit;
    }

    $code = sanitize_text_field($_GET['code'] ?? '');
    if (!$code) {
        wp_redirect(admin_url('admin.php?page=wp-barrio-stats&oauth_error=no_code'));
        exit;
    }

    $ok = wpb_stats_exchange_code($code);
    if (!$ok) {
        wp_redirect(admin_url('admin.php?page=wp-barrio-stats&oauth_error=exchange_failed'));
        exit;
    }

    // Successo
    $s = wpb_stats_settings();
    if (!empty($s['property_id'])) {
        delete_transient('wpb_stats_dash_' . $s['property_id']);
    }
    wp_redirect(admin_url('admin.php?page=wp-barrio-stats&oauth_success=1'));
    exit;
});

/* ─────────────────────────────────────────
   SALVATAGGIO IMPOSTAZIONI
   ───────────────────────────────────────── */
add_action('admin_post_wpb_stats_save', function () {
    if (!current_user_can('manage_options')) wp_die('Accesso negato.');
    check_admin_referer('wpb_stats_save');

    $existing    = wpb_stats_settings();
    $client_id   = sanitize_text_field(wp_unslash($_POST['client_id']   ?? ''));
    $client_secret = sanitize_text_field(wp_unslash($_POST['client_secret'] ?? ''));
    $property_id = preg_replace('/[^0-9]/', '', $_POST['property_id'] ?? '');
    $replace     = !empty($_POST['replace_dashboard']);

    // Mantieni valori esistenti se i campi sono vuoti
    if ($client_id     === '') $client_id     = $existing['client_id']     ?? '';
    if ($client_secret === '') $client_secret = $existing['client_secret'] ?? '';

    update_option(WPB_STATS_OPTION, [
        'client_id'          => $client_id,
        'client_secret'      => $client_secret,
        'property_id'        => $property_id,
        'replace_dashboard'  => (int) $replace,
    ]);

    // Se le credenziali cambiano, invalida i token esistenti
    if ($client_id !== ($existing['client_id'] ?? '') ||
        $client_secret !== ($existing['client_secret'] ?? '')) {
        delete_option(WPB_STATS_TOKEN_OPTION);
    }

    if ($property_id) {
        delete_transient('wpb_stats_dash_' . $property_id);
    }

    wp_redirect(admin_url('admin.php?page=wp-barrio-stats&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   DISCONNETTI account Google
   ───────────────────────────────────────── */
add_action('admin_post_wpb_stats_disconnect', function () {
    if (!current_user_can('manage_options')) wp_die('Accesso negato.');
    check_admin_referer('wpb_stats_disconnect');

    delete_option(WPB_STATS_TOKEN_OPTION);
    $s = wpb_stats_settings();
    if (!empty($s['property_id'])) {
        delete_transient('wpb_stats_dash_' . $s['property_id']);
    }

    wp_redirect(admin_url('admin.php?page=wp-barrio-stats&disconnected=1'));
    exit;
});

/* ─────────────────────────────────────────
   AJAX: refresh dati dashboard
   ───────────────────────────────────────── */
/* Periodi disponibili con relativo range date GA4 */
function wpb_stats_period_range(string $period): array
{
    return match ($period) {
        'today'   => ['today',      'today',       'Oggi'],
        'week'    => ['7daysAgo',   'today',       '7 giorni'],
        'month'   => ['30daysAgo',  'today',       '30 giorni'],
        'q'       => ['90daysAgo',  'today',       '90 giorni'],
        'half'    => ['180daysAgo', 'today',       '6 mesi'],
        'year'    => ['365daysAgo', 'today',       '12 mesi'],
        'custom'  => ['', '',                      'Personalizzato'],
        default   => ['30daysAgo',  'today',       '30 giorni'],
    };
}

add_action('wp_ajax_wpb_stats_refresh', function () {
    check_ajax_referer('wpb_stats_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('Permessi insufficienti.');

    $data = wpb_stats_get_dashboard_data(true);
    if ($data === false) wp_send_json_error('Impossibile recuperare i dati.');

    wp_send_json_success($data);
});

/* Fetch dati per un periodo specifico (o range custom) — non usa la cache globale */
add_action('wp_ajax_wpb_stats_fetch_period', function () {
    check_ajax_referer('wpb_stats_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('Permessi insufficienti.');

    $s           = wpb_stats_settings();
    $property_id = $s['property_id'] ?? '';
    if (!$property_id) wp_send_json_error('Property ID mancante.');

    $period     = sanitize_key($_POST['period'] ?? 'month');
    $custom_from = sanitize_text_field($_POST['date_from'] ?? '');
    $custom_to   = sanitize_text_field($_POST['date_to']   ?? '');

    if ($period === 'custom') {
        // Valida formato YYYY-MM-DD
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_from) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_to)) {
            wp_send_json_error('Date non valide.');
        }
        $start = $custom_from;
        $end   = $custom_to;
        $label = date('d/m/Y', strtotime($start)) . ' – ' . date('d/m/Y', strtotime($end));
    } else {
        [$start, $end, $label] = wpb_stats_period_range($period);
    }

    // Numero di giorni — determina la granularità del grafico
    $start_ts = strtotime($start === 'today' ? date('Y-m-d') : $start);
    $end_ts   = strtotime($end   === 'today' ? date('Y-m-d') : $end);
    $days     = (int) round(($end_ts - $start_ts) / 86400) + 1;

    $result = [];

    // Totali KPI
    $res_kpi = wpb_stats_run_report($property_id, [], [
        ['name' => 'activeUsers'],
        ['name' => 'screenPageViews'],
        ['name' => 'sessions'],
    ], $start, $end, 1);
    $tv = $res_kpi['totals'][0]['metricValues'] ?? $res_kpi['rows'][0]['metricValues'] ?? [];
    $result['kpi'] = [
        'users'     => $tv[0]['value'] ?? 0,
        'pageviews' => $tv[1]['value'] ?? 0,
        'sessions'  => $tv[2]['value'] ?? 0,
    ];

    // Andamento — sempre dimensione 'date' (ordinata da GA4 ASC)
    // Per periodi > 90gg aggreghiamo per settimana lato PHP
    // Per periodi > 365gg aggreghiamo per mese lato PHP
    $res_chart = wpb_stats_run_report($property_id,
        [['name' => 'date']],
        [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
        $start, $end, 500   // max 500 righe, copre oltre 1 anno
    );

    $raw_rows = $res_chart['rows'] ?? [];

    // Ordina per data ASC (GA4 di solito la restituisce già ordinata,
    // ma garantiamo l'ordinamento per sicurezza)
    usort($raw_rows, fn($a, $b) =>
        strcmp($a['dimensionValues'][0]['value'], $b['dimensionValues'][0]['value'])
    );

    // Aggrega in base alla granularità
    if ($days > 180) {
        // Aggrega per mese: chiave = YYYYMM
        $granularity = 'month';
        $buckets = [];
        foreach ($raw_rows as $row) {
            $date = $row['dimensionValues'][0]['value']; // YYYYMMDD
            $key  = substr($date, 0, 6);                // YYYYMM
            $buckets[$key]['pv']    = ($buckets[$key]['pv']    ?? 0) + (int)($row['metricValues'][0]['value'] ?? 0);
            $buckets[$key]['users'] = ($buckets[$key]['users'] ?? 0) + (int)($row['metricValues'][1]['value'] ?? 0);
        }
        ksort($buckets);
        $chart_points = [];
        foreach ($buckets as $key => $vals) {
            $month_names = ['01'=>'Gen','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mag','06'=>'Giu',
                            '07'=>'Lug','08'=>'Ago','09'=>'Set','10'=>'Ott','11'=>'Nov','12'=>'Dic'];
            $mm   = substr($key, 4, 2);
            $yyyy = substr($key, 0, 4);
            $chart_points[] = [
                'label' => ($month_names[$mm] ?? $mm) . ' ' . $yyyy,
                'pv'    => $vals['pv'],
                'users' => $vals['users'],
            ];
        }
    } elseif ($days > 60) {
        // Aggrega per settimana ISO (lunedì): chiave = YYYY-Www
        $granularity = 'week';
        $buckets = [];
        foreach ($raw_rows as $row) {
            $date = $row['dimensionValues'][0]['value']; // YYYYMMDD
            $ts   = mktime(0,0,0, (int)substr($date,4,2), (int)substr($date,6,2), (int)substr($date,0,4));
            // Lunedì della settimana
            $mon  = date('Y-m-d', strtotime('monday this week', $ts));
            $key  = $mon;
            $buckets[$key]['pv']    = ($buckets[$key]['pv']    ?? 0) + (int)($row['metricValues'][0]['value'] ?? 0);
            $buckets[$key]['users'] = ($buckets[$key]['users'] ?? 0) + (int)($row['metricValues'][1]['value'] ?? 0);
        }
        ksort($buckets);
        $chart_points = [];
        foreach ($buckets as $key => $vals) {
            $parts = explode('-', $key);  // [YYYY, MM, DD]
            $chart_points[] = [
                'label' => $parts[2] . '/' . $parts[1],   // gg/mm del lunedì
                'pv'    => $vals['pv'],
                'users' => $vals['users'],
            ];
        }
    } else {
        // Dati giornalieri
        $granularity = 'day';
        $chart_points = [];
        foreach ($raw_rows as $row) {
            $date = $row['dimensionValues'][0]['value']; // YYYYMMDD
            $chart_points[] = [
                'label' => substr($date,6,2) . '/' . substr($date,4,2),
                'pv'    => (int)($row['metricValues'][0]['value'] ?? 0),
                'users' => (int)($row['metricValues'][1]['value'] ?? 0),
            ];
        }
    }

    $result['chart_points'] = $chart_points;
    $result['granularity']  = $granularity;

    // Top pages
    $res_pages = wpb_stats_run_report($property_id,
        [['name' => 'pagePath'], ['name' => 'pageTitle']],
        [['name' => 'screenPageViews']],
        $start, $end, 10
    );
    $result['top_pages'] = $res_pages['rows'] ?? [];

    // Sorgenti
    $res_src = wpb_stats_run_report($property_id,
        [['name' => 'sessionDefaultChannelGroup']],
        [['name' => 'sessions']],
        $start, $end, 8
    );
    $result['sources'] = $res_src['rows'] ?? [];

    // Dispositivi
    $res_devices = wpb_stats_run_report($property_id,
        [['name' => 'deviceCategory']],
        [['name' => 'sessions'], ['name' => 'activeUsers']],
        $start, $end, 5
    );
    $result['devices'] = $res_devices['rows'] ?? [];

    // Tempo medio sulla pagina + bounce rate
    $res_time = wpb_stats_run_report($property_id,
        [],
        [['name' => 'averageSessionDuration'], ['name' => 'bounceRate']],
        $start, $end, 1
    );
    $tv_time = $res_time['totals'][0]['metricValues'] ?? $res_time['rows'][0]['metricValues'] ?? [];
    $result['avg_session_duration'] = round((float)($tv_time[0]['value'] ?? 0));
    $result['bounce_rate']          = round((float)($tv_time[1]['value'] ?? 0) * 100, 1);

    // Paesi di provenienza
    $res_countries = wpb_stats_run_report($property_id,
        [['name' => 'country']],
        [['name' => 'activeUsers'], ['name' => 'sessions']],
        $start, $end, 10
    );
    $result['countries'] = $res_countries['rows'] ?? [];

    $result['label']   = $label;
    $result['period']  = $period;

    wp_send_json_success($result);
});

/* ─────────────────────────────────────────
   MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    add_submenu_page(
        'wp-barrio',
        'Statistiche — WP Barrio',
        'Statistiche',
        'manage_options',
        'wp-barrio-stats',
        'wpb_stats_admin_page'
    );
}, 15);

/* ─────────────────────────────────────────
   BACHECA SOSTITUTIVA
   ───────────────────────────────────────── */
add_action('wp_dashboard_setup', function () {
    if (!wpb_stats_replace_active()) return;
    if (!current_user_can('edit_posts')) return;

    global $wp_meta_boxes;
    $wp_meta_boxes['dashboard'] = [];

    wp_add_dashboard_widget('wpb_stats_widget', 'Statistiche', 'wpb_stats_dashboard_widget');
}, 20);

add_action('wp_dashboard_setup', function () {
    if (!wpb_stats_replace_active()) return;
    add_action('admin_head', function () {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'dashboard') return;
        echo '<style>
            #wpb_stats_widget .hndle, #wpb_stats_widget .handle-actions,
            .wrap > h1.wp-heading-inline, #screen-meta, #screen-meta-links { display:none !important; }
            #wpbody-content .wrap { padding-top:0 !important; }
            #dashboard-widgets-wrap { margin-top:0 !important; }
            #wpb_stats_widget { margin:0; border:none; box-shadow:none; }
            #wpb_stats_widget .inside { padding:0; margin:0; }
            #dashboard-widgets .postbox-container { width:100% !important; }
            .postbox-container #normal-sortables { margin:0; }
        </style>';
    });
}, 21);

/* ─────────────────────────────────────────
   ENQUEUE
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    $is_dash     = ($hook === 'index.php');
    $is_settings = strpos($hook, 'wp-barrio-stats') !== false;

    if ($is_dash && wpb_stats_replace_active()) {
        wp_enqueue_script('wpb-stats-chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [], null, true
        );
        wp_add_inline_script('wpb-stats-chartjs', wpb_stats_dashboard_js());
        wp_add_inline_style('wp-admin', wpb_stats_dashboard_css());
    }

    if ($is_settings) {
        wp_add_inline_style('wp-admin', wpb_stats_settings_css());
    }
});

/* ─────────────────────────────────────────
   WIDGET BACHECA
   ───────────────────────────────────────── */
function wpb_stats_dashboard_widget()
{
    $data  = wpb_stats_get_dashboard_data();
    $nonce = wp_create_nonce('wpb_stats_nonce');
    $ajax  = admin_url('admin-ajax.php');

    if ($data === false): ?>
        <div class="wpb-stats-error">
            <span class="dashicons dashicons-warning"></span>
            Impossibile recuperare i dati.
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-stats')); ?>">
                Controlla la configurazione →
            </a>
        </div>
    <?php return; endif;

    $age_min = isset($data['fetched_at'])
        ? max(0, round((time() - $data['fetched_at']) / 60))
        : 0;
    ?>
    <div id="wpb-stats-board"
         data-nonce="<?php echo esc_attr($nonce); ?>"
         data-ajax="<?php echo esc_url($ajax); ?>">

        <!-- Toolbar: periodi + custom range + refresh -->
        <div class="wpb-stats-toolbar">
            <div class="wpb-stats-periods">
                <button class="wpb-period-btn" data-period="today">Oggi</button>
                <button class="wpb-period-btn" data-period="week">7 gg</button>
                <button class="wpb-period-btn active" data-period="month">30 gg</button>
                <button class="wpb-period-btn" data-period="q">90 gg</button>
                <button class="wpb-period-btn" data-period="half">6 mesi</button>
                <button class="wpb-period-btn" data-period="year">1 anno</button>
                <button class="wpb-period-btn" data-period="custom">Personalizzato</button>
            </div>
            <div class="wpb-stats-toolbar-right">
                <span class="wpb-stats-age" id="wpb-stats-age">
                    <?php echo $age_min === 0 ? 'Appena aggiornato' : 'Aggiornato ' . esc_html($age_min) . ' min fa'; ?>
                </span>
                <button class="wpb-stats-refresh" id="wpb-stats-refresh-btn" title="Aggiorna ora">
                    <span class="dashicons dashicons-update"></span>
                </button>
            </div>
        </div>

        <!-- Range date personalizzato (nascosto di default) -->
        <div class="wpb-stats-custom-range" id="wpb-custom-range" style="display:none;">
            <label>Da <input type="date" id="wpb-date-from" class="wpb-date-input"></label>
            <label>A &nbsp;<input type="date" id="wpb-date-to"   class="wpb-date-input"></label>
            <button class="button button-primary wpb-custom-apply" id="wpb-apply-custom">Applica</button>
        </div>

        <!-- KPI cards — periodo selezionato -->
        <div class="wpb-stats-kpis" id="wpb-kpis">
            <?php
            // Carica dati iniziali del periodo default (30gg)
            $s        = wpb_stats_settings();
            $init_kpi = wpb_stats_run_report(
                $s['property_id'],
                [],
                [['name'=>'activeUsers'],['name'=>'screenPageViews'],['name'=>'sessions'],['name'=>'averageSessionDuration'],['name'=>'bounceRate']],
                '30daysAgo', 'today', 1
            );
            $itv = $init_kpi['totals'][0]['metricValues'] ?? $init_kpi['rows'][0]['metricValues'] ?? [];
            $init_avg_sec   = round((float)($itv[3]['value'] ?? 0));
            $init_bounce    = round((float)($itv[4]['value'] ?? 0) * 100, 1);
            $init_avg_fmt   = sprintf('%d:%02d', intdiv($init_avg_sec, 60), $init_avg_sec % 60);
            ?>
            <div class="wpb-kpi-card">
                <div class="wpb-kpi-label">Utenti attivi</div>
                <div class="wpb-kpi-value" id="wpb-kpi-users"><?php echo esc_html(number_format((int)($itv[0]['value'] ?? 0))); ?></div>
                <div class="wpb-kpi-sub" id="wpb-kpi-period-label">30 giorni</div>
            </div>
            <div class="wpb-kpi-card">
                <div class="wpb-kpi-label">Pageview</div>
                <div class="wpb-kpi-value" id="wpb-kpi-pageviews"><?php echo esc_html(number_format((int)($itv[1]['value'] ?? 0))); ?></div>
                <div class="wpb-kpi-sub">30 giorni</div>
            </div>
            <div class="wpb-kpi-card">
                <div class="wpb-kpi-label">Sessioni</div>
                <div class="wpb-kpi-value" id="wpb-kpi-sessions"><?php echo esc_html(number_format((int)($itv[2]['value'] ?? 0))); ?></div>
                <div class="wpb-kpi-sub">30 giorni</div>
            </div>
            <div class="wpb-kpi-card">
                <div class="wpb-kpi-label">Durata media</div>
                <div class="wpb-kpi-value" id="wpb-kpi-avg-duration"><?php echo esc_html($init_avg_fmt); ?></div>
                <div class="wpb-kpi-sub">min:sec · 30 giorni</div>
            </div>
            <div class="wpb-kpi-card">
                <div class="wpb-kpi-label">Frequenza di rimbalzo</div>
                <div class="wpb-kpi-value" id="wpb-kpi-bounce"><?php echo esc_html($init_bounce); ?>%</div>
                <div class="wpb-kpi-sub">30 giorni</div>
            </div>
        </div>

        <!-- Griglia grafici + tabelle -->
        <div class="wpb-stats-grid">

            <!-- Grafico andamento -->
            <div class="wpb-stats-panel wpb-stats-panel--chart">
                <div class="wpb-stats-panel-title" id="wpb-chart-title">Andamento — 30 giorni</div>
                <div class="wpb-stats-chart-wrap">
                    <canvas id="wpb-stats-chart"></canvas>
                </div>
                <?php
                $daily_sorted = $data['daily'];
                usort($daily_sorted, fn($a, $b) =>
                    strcmp($a['dimensionValues'][0]['value'] ?? '', $b['dimensionValues'][0]['value'] ?? '')
                );
                $labels = $pvs = $users_arr = [];
                foreach ($daily_sorted as $row) {
                    $d = $row['dimensionValues'][0]['value'] ?? '';
                    $labels[]    = substr($d, 6, 2) . '/' . substr($d, 4, 2);
                    $pvs[]       = (int)($row['metricValues'][0]['value'] ?? 0);
                    $users_arr[] = (int)($row['metricValues'][1]['value'] ?? 0);
                }
                ?>
                <script>
                window.wpbStatsInitData = {
                    labels:    <?php echo json_encode($labels); ?>,
                    pageviews: <?php echo json_encode($pvs); ?>,
                    users:     <?php echo json_encode($users_arr); ?>
                };
                </script>
            </div>

            <!-- Top pages -->
            <div class="wpb-stats-panel">
                <div class="wpb-stats-panel-title" id="wpb-pages-title">Pagine più viste — 30 giorni</div>
                <table class="wpb-stats-table" id="wpb-pages-table">
                    <thead><tr><th>Pagina</th><th>Views</th></tr></thead>
                    <tbody id="wpb-pages-tbody">
                    <?php foreach ($data['top_pages'] as $row):
                        $path   = $row['dimensionValues'][0]['value'] ?? '/';
                        $ptitle = $row['dimensionValues'][1]['value'] ?? $path;
                        $views  = number_format((int)($row['metricValues'][0]['value'] ?? 0));
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(home_url($path)); ?>" target="_blank" rel="noopener"
                                   title="<?php echo esc_attr($path); ?>">
                                    <?php echo esc_html(mb_strimwidth($ptitle, 0, 40, '…')); ?>
                                </a>
                            </td>
                            <td class="wpb-stats-num"><?php echo esc_html($views); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Sorgenti -->
            <div class="wpb-stats-panel">
                <div class="wpb-stats-panel-title" id="wpb-src-title">Sorgenti di traffico — 30 giorni</div>
                <div class="wpb-stats-sources" id="wpb-sources">
                <?php
                $total_sess = array_sum(array_map(fn($r) => (int)($r['metricValues'][0]['value'] ?? 0), $data['sources']));
                $colors = ['Organic Search'=>'#2271b1','Direct'=>'#00a32a','Referral'=>'#d63638','Organic Social'=>'#dba617','Email'=>'#8c5a0b','Paid Search'=>'#9b59b6','Unassigned'=>'#a7aaad'];
                foreach ($data['sources'] as $row):
                    $ch   = $row['dimensionValues'][0]['value'] ?? '(other)';
                    $sess = (int)($row['metricValues'][0]['value'] ?? 0);
                    $pct  = $total_sess > 0 ? round($sess / $total_sess * 100) : 0;
                    $col  = $colors[$ch] ?? '#646970';
                ?>
                    <div class="wpb-stats-source-row">
                        <div class="wpb-stats-source-label"><?php echo esc_html($ch); ?></div>
                        <div class="wpb-stats-source-bar-wrap">
                            <div class="wpb-stats-source-bar" style="width:<?php echo esc_attr($pct); ?>%;background:<?php echo esc_attr($col); ?>;"></div>
                        </div>
                        <div class="wpb-stats-source-pct"><?php echo esc_html($pct); ?>%</div>
                        <div class="wpb-stats-source-num"><?php echo esc_html(number_format($sess)); ?></div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>

            <!-- Dispositivi -->
            <div class="wpb-stats-panel">
                <div class="wpb-stats-panel-title" id="wpb-devices-title">Dispositivi — 30 giorni</div>
                <div class="wpb-stats-sources" id="wpb-devices">
                <?php
                $dev_colors = ['desktop'=>'#2271b1','mobile'=>'#00a32a','tablet'=>'#dba617'];
                $total_dev  = array_sum(array_map(fn($r) => (int)($r['metricValues'][0]['value'] ?? 0), $data['devices']));
                foreach ($data['devices'] as $row):
                    $dev  = $row['dimensionValues'][0]['value'] ?? 'other';
                    $sess = (int)($row['metricValues'][0]['value'] ?? 0);
                    $usr  = (int)($row['metricValues'][1]['value'] ?? 0);
                    $pct  = $total_dev > 0 ? round($sess / $total_dev * 100) : 0;
                    $col  = $dev_colors[strtolower($dev)] ?? '#646970';
                    $label_dev = match(strtolower($dev)) { 'desktop'=>'Desktop','mobile'=>'Mobile','tablet'=>'Tablet', default=>ucfirst($dev) };
                ?>
                    <div class="wpb-stats-source-row">
                        <div class="wpb-stats-source-label"><?php echo esc_html($label_dev); ?></div>
                        <div class="wpb-stats-source-bar-wrap">
                            <div class="wpb-stats-source-bar" style="width:<?php echo esc_attr($pct); ?>%;background:<?php echo esc_attr($col); ?>;"></div>
                        </div>
                        <div class="wpb-stats-source-pct"><?php echo esc_html($pct); ?>%</div>
                        <div class="wpb-stats-source-num"><?php echo esc_html(number_format($usr)); ?> utenti</div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>

            <!-- Paesi di provenienza -->
            <div class="wpb-stats-panel wpb-stats-panel--wide">
                <div class="wpb-stats-panel-title" id="wpb-countries-title">Paesi di provenienza &mdash; 30 giorni</div>
                <div class="wpb-stats-sources" id="wpb-countries">
                <?php
                $total_ctry = array_sum(array_map(fn($r) => (int)($r['metricValues'][0]['value'] ?? 0), $data['countries']));
                $ctry_colors = ['#2271b1','#00a32a','#dba617','#d63638','#9b59b6','#8c5a0b','#0891b2','#a7aaad','#646970','#3d6b50'];
                foreach ($data['countries'] as $i => $row):
                    $ctry = $row['dimensionValues'][0]['value'] ?? '(unknown)';
                    $usr  = (int)($row['metricValues'][0]['value'] ?? 0);
                    $sess = (int)($row['metricValues'][1]['value'] ?? 0);
                    $pct  = $total_ctry > 0 ? round($usr / $total_ctry * 100) : 0;
                    $col  = $ctry_colors[$i % count($ctry_colors)];
                ?>
                    <div class="wpb-stats-source-row wpb-stats-source-row--country">
                        <div class="wpb-stats-source-label"><?php echo esc_html($ctry); ?></div>
                        <div class="wpb-stats-source-bar-wrap">
                            <div class="wpb-stats-source-bar" style="width:<?php echo esc_attr($pct); ?>%;background:<?php echo esc_attr($col); ?>;"></div>
                        </div>
                        <div class="wpb-stats-source-pct"><?php echo esc_html($pct); ?>%</div>
                        <div class="wpb-stats-source-num"><?php echo esc_html(number_format($usr)); ?> utenti</div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>

        </div>

        <?php if (current_user_can('manage_options')): ?>
        <div class="wpb-stats-footer">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-stats')); ?>">Impostazioni statistiche</a>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

/* ─────────────────────────────────────────
   CSS — bacheca
   ───────────────────────────────────────── */
function wpb_stats_dashboard_css(): string { return '
    #wpb-stats-board { font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; padding:20px 20px 8px; color:#1d2327; }

    /* ── Toolbar ── */
    .wpb-stats-toolbar { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:16px; flex-wrap:wrap; }
    .wpb-stats-periods { display:flex; align-items:center; gap:3px; flex-wrap:wrap; }
    .wpb-period-btn { padding:5px 11px; font-size:12px; font-weight:500; border:1px solid #c3c4c7; background:#fff; border-radius:3px; cursor:pointer; color:#646970; transition:all .15s; white-space:nowrap; }
    .wpb-period-btn:hover { border-color:#2271b1; color:#2271b1; }
    .wpb-period-btn.active { background:#2271b1; border-color:#2271b1; color:#fff; }
    .wpb-stats-toolbar-right { display:flex; align-items:center; gap:6px; }
    .wpb-stats-age { font-size:11px; color:#a7aaad; white-space:nowrap; }
    .wpb-stats-refresh { background:none; border:none; cursor:pointer; padding:2px 4px; color:#a7aaad; transition:color .15s; line-height:1; }
    .wpb-stats-refresh:hover { color:#2271b1; }
    .wpb-stats-refresh.spinning .dashicons { animation:wpb-spin .6s linear infinite; }
    @keyframes wpb-spin { to { transform:rotate(360deg); } }

    /* ── Custom date range ── */
    .wpb-stats-custom-range { display:flex; align-items:center; gap:10px; margin-bottom:14px; padding:10px 14px; background:#f6f7f7; border:1px solid #c3c4c7; border-radius:3px; font-size:12px; flex-wrap:wrap; }
    .wpb-stats-custom-range label { display:flex; align-items:center; gap:5px; color:#646970; }
    .wpb-date-input { border:1px solid #c3c4c7; border-radius:3px; padding:4px 7px; font-size:12px; color:#1d2327; }
    .wpb-custom-apply { font-size:12px !important; padding:3px 10px !important; height:auto !important; }

    /* ── KPI cards ── */
    .wpb-stats-kpis { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
    .wpb-kpi-card { flex:1; min-width:120px; background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:14px 18px; transition:opacity .2s; }
    .wpb-kpi-card.loading { opacity:.45; }
    .wpb-kpi-label { font-size:11px; color:#646970; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; }
    .wpb-kpi-value { font-size:28px; font-weight:700; color:#1d2327; line-height:1; }
    .wpb-kpi-sub   { font-size:11px; color:#a7aaad; margin-top:4px; }

    /* ── Grid + panels ── */
    .wpb-stats-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; }
    .wpb-stats-panel--chart { grid-column:1 / -1; }
    .wpb-stats-panel { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px 18px; transition:opacity .2s; }
    .wpb-stats-panel.loading { opacity:.45; pointer-events:none; }
    .wpb-stats-panel-title { font-size:12px; font-weight:600; color:#646970; text-transform:uppercase; letter-spacing:.4px; margin-bottom:14px; }
    .wpb-stats-chart-wrap { height:160px; position:relative; }

    /* ── Tabella pagine ── */
    .wpb-stats-table { width:100%; border-collapse:collapse; font-size:12px; }
    .wpb-stats-table thead th { text-align:left; font-size:11px; color:#a7aaad; font-weight:500; padding-bottom:6px; border-bottom:1px solid #f0f0f1; }
    .wpb-stats-table thead th:last-child { text-align:right; }
    .wpb-stats-table tbody tr:hover td { background:#f6f7f7; }
    .wpb-stats-table td { padding:6px 4px; border-bottom:1px solid #f6f7f7; color:#1d2327; }
    .wpb-stats-table a { color:#2271b1; text-decoration:none; }
    .wpb-stats-table a:hover { text-decoration:underline; }
    .wpb-stats-num { text-align:right; font-variant-numeric:tabular-nums; color:#646970; }

    /* ── Sorgenti ── */
    .wpb-stats-source-row { display:grid; grid-template-columns:130px 1fr 36px 52px; align-items:center; gap:8px; padding:5px 0; border-bottom:1px solid #f6f7f7; font-size:12px; }
    .wpb-stats-source-row:last-child { border-bottom:none; }
    .wpb-stats-source-label { color:#1d2327; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .wpb-stats-source-bar-wrap { background:#f0f0f1; border-radius:2px; height:6px; overflow:hidden; }
    .wpb-stats-source-bar { height:100%; border-radius:2px; }
    .wpb-stats-source-pct { text-align:right; color:#646970; font-size:11px; }
    .wpb-stats-source-num { text-align:right; color:#a7aaad; font-size:11px; font-variant-numeric:tabular-nums; }

    /* ── Footer ── */
    .wpb-stats-footer { font-size:12px; color:#a7aaad; padding-top:8px; }
    .wpb-stats-footer a { color:#646970; text-decoration:none; }
    .wpb-stats-footer a:hover { color:#2271b1; }

    /* ── Errore ── */
    .wpb-stats-error { padding:20px; color:#646970; font-size:13px; display:flex; align-items:center; gap:8px; }
    .wpb-stats-error .dashicons { color:#dba617; }
    .wpb-stats-error a { color:#2271b1; }
'; }

/* ─────────────────────────────────────────
   JS — bacheca
   ───────────────────────────────────────── */
function wpb_stats_dashboard_js(): string { return '
document.addEventListener("DOMContentLoaded", function () {
    var board = document.getElementById("wpb-stats-board");
    if (!board) return;
    var ajaxUrl = board.dataset.ajax;
    var nonce   = board.dataset.nonce;

    // Colori sorgenti
    var srcColors = {
        "Organic Search":"#2271b1","Direct":"#00a32a","Referral":"#d63638",
        "Organic Social":"#dba617","Email":"#8c5a0b","Paid Search":"#9b59b6","Unassigned":"#a7aaad"
    };

    // ── Grafico ────────────────────────────────────────────────────────
    var chartInst = null;
    function buildChart(labels, pvs, users) {
        var ctx = document.getElementById("wpb-stats-chart");
        if (!ctx) return;
        if (chartInst) chartInst.destroy();
        chartInst = new Chart(ctx, {
            type: "line",
            data: {
                labels: labels,
                datasets: [
                    { label:"Pageview", data:pvs,   borderColor:"#2271b1", backgroundColor:"rgba(34,113,177,.08)", borderWidth:2, pointRadius:0, fill:true, tension:.35 },
                    { label:"Utenti",   data:users, borderColor:"#00a32a", backgroundColor:"transparent", borderWidth:1.5, pointRadius:0, tension:.35, borderDash:[4,3] }
                ]
            },
            options: {
                responsive:true, maintainAspectRatio:false,
                interaction:{ mode:"index", intersect:false },
                plugins:{ legend:{ display:true, position:"top", labels:{ boxWidth:12, font:{size:11}, color:"#646970" } } },
                scales:{
                    x:{ ticks:{ font:{size:10}, color:"#a7aaad", maxTicksLimit:12 }, grid:{ display:false } },
                    y:{ ticks:{ font:{size:10}, color:"#a7aaad" }, grid:{ color:"#f0f0f1" }, beginAtZero:true }
                }
            }
        });
    }

    // Caricamento iniziale grafico
    var init = window.wpbStatsInitData;
    if (init) buildChart(init.labels, init.pageviews, init.users);

    // ── Funzione aggiornamento periodo ────────────────────────────────
    function fmt(n) { return Number(n).toLocaleString("it-IT"); }

    function setLoading(on) {
        document.querySelectorAll(".wpb-kpi-card, .wpb-stats-panel").forEach(function(el) {
            el.classList.toggle("loading", on);
        });
    }

    function applyPeriod(period, dateFrom, dateTo) {
        setLoading(true);
        var params = { action:"wpb_stats_fetch_period", nonce:nonce, period:period };
        if (period === "custom") { params.date_from = dateFrom; params.date_to = dateTo; }

        fetch(ajaxUrl, {
            method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"},
            body: new URLSearchParams(params)
        })
        .then(function(r){ return r.json(); })
        .then(function(res) {
            setLoading(false);
            if (!res.success) return;
            var d = res.data;
            var lbl = d.label;

            // KPI
            document.getElementById("wpb-kpi-users").textContent     = fmt(d.kpi.users);
            document.getElementById("wpb-kpi-pageviews").textContent  = fmt(d.kpi.pageviews);
            document.getElementById("wpb-kpi-sessions").textContent   = fmt(d.kpi.sessions);
            // Durata media (secondi → mm:ss)
            var avgSec = parseInt(d.avg_session_duration || 0);
            document.getElementById("wpb-kpi-avg-duration").textContent = Math.floor(avgSec/60) + ":" + String(avgSec%60).padStart(2,"0");
            // Bounce rate
            document.getElementById("wpb-kpi-bounce").textContent = (d.bounce_rate || 0) + "%";
            document.querySelectorAll(".wpb-kpi-sub").forEach(function(el){ el.textContent = lbl; });

            // Grafico — usa i punti già aggregati e ordinati dal PHP
            var gLabels = [], gPvs = [], gUsers = [];
            (d.chart_points || []).forEach(function(pt) {
                gLabels.push(pt.label);
                gPvs.push(pt.pv);
                gUsers.push(pt.users);
            });
            buildChart(gLabels, gPvs, gUsers);
            document.getElementById("wpb-chart-title").textContent = "Andamento — " + lbl;

            // Pagine
            document.getElementById("wpb-pages-title").textContent = "Pagine più viste — " + lbl;
            var tbody = document.getElementById("wpb-pages-tbody");
            tbody.innerHTML = "";
            (d.top_pages || []).forEach(function(row) {
                var path   = row.dimensionValues[0].value;
                var ptitle = row.dimensionValues[1].value || path;
                if (ptitle.length > 40) ptitle = ptitle.slice(0,40) + "…";
                var views  = fmt(row.metricValues[0].value);
                tbody.innerHTML += "<tr><td><a href=\"" + location.origin + path + "\" target=\"_blank\">" + ptitle + "</a></td><td class=\"wpb-stats-num\">" + views + "</td></tr>";
            });

            // Sorgenti
            document.getElementById("wpb-src-title").textContent = "Sorgenti di traffico — " + lbl;
            var totSess = (d.sources || []).reduce(function(s,r){ return s + parseInt(r.metricValues[0].value); }, 0);
            var srcWrap = document.getElementById("wpb-sources");
            srcWrap.innerHTML = "";
            (d.sources || []).forEach(function(row) {
                var ch   = row.dimensionValues[0].value;
                var sess = parseInt(row.metricValues[0].value);
                var pct  = totSess > 0 ? Math.round(sess / totSess * 100) : 0;
                var col  = srcColors[ch] || "#646970";
                srcWrap.innerHTML +=
                    "<div class=\"wpb-stats-source-row\">" +
                    "<div class=\"wpb-stats-source-label\">" + ch + "</div>" +
                    "<div class=\"wpb-stats-source-bar-wrap\"><div class=\"wpb-stats-source-bar\" style=\"width:" + pct + "%;background:" + col + ";\"></div></div>" +
                    "<div class=\"wpb-stats-source-pct\">" + pct + "%</div>" +
                    "<div class=\"wpb-stats-source-num\">" + fmt(sess) + "</div>" +
                    "</div>";
            });

            // Dispositivi
            document.getElementById("wpb-devices-title").textContent = "Dispositivi — " + lbl;
            var devColors = { desktop:"#2271b1", mobile:"#00a32a", tablet:"#dba617" };
            var devLabels = { desktop:"Desktop", mobile:"Mobile", tablet:"Tablet" };
            var totDev = (d.devices || []).reduce(function(s,r){ return s + parseInt(r.metricValues[0].value); }, 0);
            var devWrap = document.getElementById("wpb-devices");
            devWrap.innerHTML = "";
            (d.devices || []).forEach(function(row) {
                var dev  = (row.dimensionValues[0].value || "other").toLowerCase();
                var sess = parseInt(row.metricValues[0].value);
                var usr  = parseInt(row.metricValues[1].value);
                var pct  = totDev > 0 ? Math.round(sess / totDev * 100) : 0;
                var col  = devColors[dev] || "#646970";
                var lbl2 = devLabels[dev] || (dev.charAt(0).toUpperCase() + dev.slice(1));
                devWrap.innerHTML +=
                    "<div class=\"wpb-stats-source-row\">" +
                    "<div class=\"wpb-stats-source-label\">" + lbl2 + "</div>" +
                    "<div class=\"wpb-stats-source-bar-wrap\"><div class=\"wpb-stats-source-bar\" style=\"width:" + pct + "%;background:" + col + ";\"></div></div>" +
                    "<div class=\"wpb-stats-source-pct\">" + pct + "%</div>" +
                    "<div class=\"wpb-stats-source-num\">" + fmt(usr) + " utenti</div>" +
                    "</div>";
            });

            // Dati demografici: età
            // Paesi di provenienza
            document.getElementById("wpb-countries-title").textContent = "Paesi di provenienza — " + lbl;
            var ctryColors = ["#2271b1","#00a32a","#dba617","#d63638","#9b59b6","#8c5a0b","#0891b2","#a7aaad","#646970","#3d6b50"];
            var totCtry = (d.countries || []).reduce(function(s,r){ return s + parseInt(r.metricValues[0].value); }, 0);
            var ctryWrap = document.getElementById("wpb-countries");
            ctryWrap.innerHTML = "";
            (d.countries || []).forEach(function(row, i) {
                var ctry = row.dimensionValues[0].value || "(unknown)";
                var usr  = parseInt(row.metricValues[0].value);
                var sess = parseInt(row.metricValues[1].value);
                var pct  = totCtry > 0 ? Math.round(usr / totCtry * 100) : 0;
                var col  = ctryColors[i % ctryColors.length];
                ctryWrap.innerHTML +=
                    "<div class=\"wpb-stats-source-row wpb-stats-source-row--country\">" +
                    "<div class=\"wpb-stats-source-label\">" + ctry + "</div>" +
                    "<div class=\"wpb-stats-source-bar-wrap\"><div class=\"wpb-stats-source-bar\" style=\"width:" + pct + "%;background:" + col + ";\"></div></div>" +
                    "<div class=\"wpb-stats-source-pct\">" + pct + "%</div>" +
                    "<div class=\"wpb-stats-source-num\">" + fmt(usr) + " utenti</div>" +
                    "</div>";
            });
        })
        .catch(function(){ setLoading(false); });
    }

    // ── Pulsanti periodo ──────────────────────────────────────────────
    document.querySelectorAll(".wpb-period-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            document.querySelectorAll(".wpb-period-btn").forEach(function(b){ b.classList.remove("active"); });
            this.classList.add("active");
            var p = this.dataset.period;
            var cr = document.getElementById("wpb-custom-range");
            if (p === "custom") {
                cr.style.display = "flex";
            } else {
                cr.style.display = "none";
                applyPeriod(p, "", "");
            }
        });
    });

    // ── Range personalizzato ──────────────────────────────────────────
    document.getElementById("wpb-apply-custom") && document.getElementById("wpb-apply-custom").addEventListener("click", function() {
        var from = document.getElementById("wpb-date-from").value;
        var to   = document.getElementById("wpb-date-to").value;
        if (!from || !to) { alert("Seleziona entrambe le date."); return; }
        if (from > to) { alert("La data di inizio deve essere precedente alla fine."); return; }
        applyPeriod("custom", from, to);
    });

    // ── Refresh (forza ricalcolo cache + ricarica pagina) ─────────────
    document.getElementById("wpb-stats-refresh-btn") && document.getElementById("wpb-stats-refresh-btn").addEventListener("click", function() {
        var btn = this;
        btn.classList.add("spinning"); btn.disabled = true;
        fetch(ajaxUrl, {
            method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"},
            body: new URLSearchParams({ action:"wpb_stats_refresh", nonce:nonce })
        }).then(function(r){ return r.json(); }).then(function(res){
            btn.classList.remove("spinning"); btn.disabled = false;
            if (res.success) window.location.reload();
        }).catch(function(){ btn.classList.remove("spinning"); btn.disabled = false; });
    });
});
'; }

/* ─────────────────────────────────────────
   CSS — pagina impostazioni
   ───────────────────────────────────────── */
function wpb_stats_settings_css(): string { return '
    #wpb-stats-wrap { max-width:680px; }
    .wpb-stats-section { background:#fff; border:1px solid #c3c4c7; border-radius:3px; margin-bottom:16px; overflow:hidden; }
    .wpb-stats-section-head { padding:13px 20px; background:#f6f7f7; border-bottom:1px solid #c3c4c7; display:flex; align-items:center; gap:8px; }
    .wpb-stats-section-head .dashicons { color:#2271b1; font-size:16px; width:16px; height:16px; }
    .wpb-stats-section-title { font-size:13px; font-weight:600; color:#1d2327; margin:0; }
    .wpb-stats-section-body { padding:20px; }
    .wpb-stats-field { margin-bottom:16px; }
    .wpb-stats-field:last-child { margin-bottom:0; }
    .wpb-stats-field > label { display:block; font-size:13px; font-weight:600; color:#1d2327; margin-bottom:6px; }
    .wpb-stats-field input[type="text"], .wpb-stats-field input[type="password"] { width:100%; box-sizing:border-box; }
    .wpb-stats-desc { font-size:12px; color:#646970; margin-top:5px; line-height:1.6; }
    .wpb-stats-desc code { background:#f0f0f1; padding:1px 4px; border-radius:2px; font-size:11px; }
    .wpb-toggle { position:relative; width:40px; height:22px; flex-shrink:0; }
    .wpb-toggle input { opacity:0; width:0; height:0; position:absolute; }
    .wpb-toggle-slider { position:absolute; inset:0; background:#a7aaad; border-radius:22px; cursor:pointer; transition:background .2s; }
    .wpb-toggle-slider:before { content:""; position:absolute; width:16px; height:16px; left:3px; top:3px; background:#fff; border-radius:50%; transition:transform .2s; }
    .wpb-toggle input:checked + .wpb-toggle-slider { background:#2271b1; }
    .wpb-toggle input:checked + .wpb-toggle-slider:before { transform:translateX(18px); }
    .wpb-stats-toggle-row { display:flex; align-items:center; gap:10px; }
    .wpb-stats-toggle-label { font-size:13px; color:#1d2327; cursor:pointer; }
    .wpb-stats-status { display:flex; align-items:flex-start; gap:10px; padding:12px 16px; border-radius:3px; font-size:13px; margin-bottom:20px; border:1px solid; }
    .wpb-stats-status.ok   { background:#f0fdf4; border-color:#bbf7d0; color:#166534; }
    .wpb-stats-status.warn { background:#fcf9e8; border-color:#dba617; color:#6e4e00; }
    .wpb-stats-status.info { background:#f0f6fc; border-color:#c5d9f0; color:#1d2327; }
    .wpb-stats-status .dashicons { flex-shrink:0; font-size:18px; width:18px; height:18px; margin-top:1px; }
    .wpb-stats-steps { background:#f0f6fc; border:1px solid #c5d9f0; border-radius:3px; padding:14px 16px; margin-bottom:20px; font-size:12px; line-height:1.7; }
    .wpb-stats-steps ol { margin:8px 0 0 18px; padding:0; }
    .wpb-stats-steps li { margin-bottom:4px; }
    .wpb-stats-steps a { color:#2271b1; }
    .wpb-stats-connect-btn { display:inline-flex; align-items:center; gap:8px; padding:8px 18px; background:#fff; border:1px solid #dadce0; border-radius:4px; font-size:13px; font-weight:500; color:#3c4043; text-decoration:none; cursor:pointer; transition:box-shadow .15s; }
    .wpb-stats-connect-btn:hover { box-shadow:0 1px 3px rgba(0,0,0,.2); color:#3c4043; }
    .wpb-stats-connect-btn svg { flex-shrink:0; }
    .wpb-stats-disconnect { font-size:12px; color:#d63638; text-decoration:none; margin-left:12px; }
    .wpb-stats-disconnect:hover { text-decoration:underline; }
    .wpb-stats-connected-card {
        display:flex; align-items:center; justify-content:space-between;
        gap:16px; padding:14px 18px;
        background:#f0fdf4; border:1px solid #bbf7d0; border-radius:4px;
        margin-bottom:20px; flex-wrap:wrap;
    }
    .wpb-stats-connected-left { display:flex; align-items:center; gap:12px; flex:1; min-width:0; }
    .wpb-stats-connected-dot {
        width:10px; height:10px; border-radius:50%;
        background:#00a32a; flex-shrink:0;
        box-shadow:0 0 0 3px rgba(0,163,42,.15);
    }
    .wpb-stats-connected-title { font-size:13px; font-weight:600; color:#166534; margin-bottom:2px; }
    .wpb-stats-connected-meta  { font-size:12px; color:#3d6b50; }
    .wpb-stats-disconnect-btn {
        padding:5px 12px; font-size:12px; font-weight:500;
        background:#fff; border:1px solid #f0a8a8; border-radius:3px;
        color:#d63638; cursor:pointer; white-space:nowrap;
        transition:background .15s, border-color .15s;
    }
    .wpb-stats-disconnect-btn:hover { background:#fff8f8; border-color:#d63638; }
'; }

/* ─────────────────────────────────────────
   PAGINA ADMIN — Impostazioni
   ───────────────────────────────────────── */
function wpb_stats_admin_page()
{
    if (!current_user_can('manage_options')) wp_die('Accesso negato.');

    $s           = wpb_stats_settings();
    $connected   = wpb_stats_is_connected();
    $configured  = wpb_stats_is_configured();
    $client_id   = $s['client_id']   ?? '';
    $client_secret = $s['client_secret'] ?? '';
    $property_id = $s['property_id'] ?? '';
    $replace     = !empty($s['replace_dashboard']);

    // Messaggi di stato
    $saved       = isset($_GET['saved']);
    $disconnected = isset($_GET['disconnected']);
    $oauth_ok    = isset($_GET['oauth_success']);
    $oauth_err   = isset($_GET['oauth_error']) ? sanitize_text_field($_GET['oauth_error']) : '';

    // URL OAuth
    $oauth_url = '';
    if ($configured && !$connected) {
        $oauth_url = add_query_arg([
            'client_id'     => $s['client_id'],
            'redirect_uri'  => rawurlencode(wpb_stats_redirect_uri()),
            'response_type' => 'code',
            'scope'         => WPB_STATS_OAUTH_SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => wp_create_nonce('wpb_stats_oauth_state'),
        ], WPB_STATS_AUTH_URL);
    }
    ?>
    <div id="wpb-stats-wrap" class="wrap">

        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-chart-area"
                style="font-size:26px;width:26px;height:26px;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
            WP Barrio
            <span style="font-size:15px;font-weight:400;color:#646970;margin-left:6px;">/ Statistiche</span>
        </h1>
        <hr class="wp-header-end" style="margin-bottom:20px;">

        <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible" style="margin-bottom:16px;"><p>Impostazioni salvate.</p></div>
        <?php endif; ?>
        <?php if ($disconnected): ?>
            <div class="notice notice-info is-dismissible" style="margin-bottom:16px;"><p>Account Google disconnesso.</p></div>
        <?php endif; ?>
        <?php if ($oauth_ok): ?>
            <div class="notice notice-success is-dismissible" style="margin-bottom:16px;"><p>✓ Account Google collegato con successo.</p></div>
        <?php endif; ?>
        <?php if ($oauth_err): ?>
            <div class="notice notice-error is-dismissible" style="margin-bottom:16px;">
                <p>Errore durante l'autorizzazione: <code><?php echo esc_html($oauth_err); ?></code>. Riprova.</p>
            </div>
        <?php endif; ?>

        <!-- Stato connessione -->
        <?php if ($connected): ?>
            <div class="wpb-stats-connected-card">
                <div class="wpb-stats-connected-left">
                    <span class="wpb-stats-connected-dot"></span>
                    <div>
                        <div class="wpb-stats-connected-title">Account Google collegato</div>
                        <div class="wpb-stats-connected-meta">
                            Property ID <strong><?php echo esc_html($property_id); ?></strong>
                            <?php if ($replace): ?>
                                &nbsp;·&nbsp;
                                <span style="color:#00a32a;">&#x2713; Bacheca sostituita</span>
                            <?php else: ?>
                                &nbsp;·&nbsp;
                                <span style="color:#a7aaad;">Bacheca non sostituita</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wpb_stats_disconnect'); ?>
                    <input type="hidden" name="action" value="wpb_stats_disconnect">
                    <button type="submit" class="wpb-stats-disconnect-btn">Disconnetti</button>
                </form>
            </div>
        <?php elseif ($configured): ?>
            <div class="wpb-stats-status warn">
                <span class="dashicons dashicons-warning" style="color:#dba617;"></span>
                <div>
                    <strong>Credenziali salvate, ma account non collegato.</strong><br>
                    Clicca "Connetti con Google" qui sotto per autorizzare l'accesso ai dati Analytics.
                </div>
            </div>
        <?php else: ?>
            <div class="wpb-stats-status info">
                <span class="dashicons dashicons-info" style="color:#2271b1;"></span>
                <span>Inserisci le credenziali OAuth per iniziare. Segui le istruzioni qui sotto.</span>
            </div>
        <?php endif; ?>

        <!-- Istruzioni -->
        <div class="wpb-stats-steps">
            <strong>Come creare le credenziali OAuth in Google Cloud:</strong>
            <ol>
                <li>Vai su <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> → seleziona il progetto.</li>
                <li>Attiva <strong>Google Analytics Data API</strong> dalla Libreria API.</li>
                <li>Vai su <strong>API e servizi → Credenziali → Crea credenziali → ID client OAuth 2.0</strong>.</li>
                <li>Tipo applicazione: <strong>Applicazione web</strong>. Nome: es. "WP Barrio Stats".</li>
                <li>In <strong>URI di reindirizzamento autorizzati</strong> aggiungi esattamente:<br>
                    <code><?php echo esc_html(wpb_stats_redirect_uri()); ?></code>
                </li>
                <li>Clicca <strong>Crea</strong>, copia <strong>Client ID</strong> e <strong>Client secret</strong> e incollali qui sotto.</li>
                <li>Copia il <strong>Property ID</strong> da GA4 → Impostazioni proprietà (solo il numero, es. <code>123456789</code>).</li>
            </ol>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wpb_stats_save'); ?>
            <input type="hidden" name="action" value="wpb_stats_save">

            <!-- Credenziali OAuth -->
            <div class="wpb-stats-section">
                <div class="wpb-stats-section-head">
                    <span class="dashicons dashicons-lock"></span>
                    <div class="wpb-stats-section-title">Credenziali OAuth 2.0</div>
                </div>
                <div class="wpb-stats-section-body">

                    <div class="wpb-stats-field">
                        <label for="wpb-client-id">Client ID</label>
                        <input type="text" id="wpb-client-id" name="client_id"
                               value="<?php echo esc_attr($client_id); ?>"
                               placeholder="xxxxxxxxxxxx-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx.apps.googleusercontent.com">
                        <p class="wpb-stats-desc">Da Google Cloud → API e servizi → Credenziali → il tuo client OAuth.</p>
                    </div>

                    <div class="wpb-stats-field">
                        <label for="wpb-client-secret">Client Secret</label>
                        <input type="password" id="wpb-client-secret" name="client_secret"
                               value="<?php echo esc_attr($client_secret); ?>"
                               placeholder="GOCSPX-…"
                               autocomplete="new-password">
                        <p class="wpb-stats-desc">Lascia vuoto per mantenere il valore già salvato.</p>
                    </div>

                    <div class="wpb-stats-field">
                        <label for="wpb-property-id">Property ID GA4</label>
                        <input type="text" id="wpb-property-id" name="property_id"
                               value="<?php echo esc_attr($property_id); ?>"
                               placeholder="123456789" style="width:200px;">
                        <p class="wpb-stats-desc">Solo il numero — da GA4 → Impostazioni proprietà.</p>
                    </div>

                </div>
            </div>

            <!-- Bacheca -->
            <div class="wpb-stats-section">
                <div class="wpb-stats-section-head">
                    <span class="dashicons dashicons-dashboard"></span>
                    <div class="wpb-stats-section-title">Bacheca WordPress</div>
                </div>
                <div class="wpb-stats-section-body">
                    <div class="wpb-stats-field">
                        <div class="wpb-stats-toggle-row">
                            <label class="wpb-toggle" for="wpb-replace">
                                <input type="checkbox" id="wpb-replace" name="replace_dashboard"
                                       value="1" <?php checked($replace); ?>>
                                <span class="wpb-toggle-slider"></span>
                            </label>
                            <label class="wpb-stats-toggle-label" for="wpb-replace">
                                Sostituisci la bacheca con le statistiche GA4
                            </label>
                        </div>
                        <p class="wpb-stats-desc" style="margin-top:8px;">
                            Attivo solo dopo aver collegato l'account Google. I dati si aggiornano ogni 30 minuti automaticamente.
                        </p>
                    </div>
                </div>
            </div>

            <?php submit_button('Salva impostazioni', 'primary', 'submit', false); ?>
        </form>

        <!-- Pulsante connetti Google -->
        <?php if ($configured && !$connected): ?>
            <div style="margin-top:20px;padding:20px;background:#fff;border:1px solid #c3c4c7;border-radius:3px;">
                <p style="margin:0 0 12px;font-size:13px;color:#1d2327;font-weight:600;">
                    Collega il tuo account Google per accedere ai dati Analytics:
                </p>
                <a href="<?php echo esc_url($oauth_url); ?>" class="wpb-stats-connect-btn">
                    <!-- Google G logo -->
                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" fill="#4285F4"/>
                        <path d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z" fill="#34A853"/>
                        <path d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/>
                        <path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z" fill="#EA4335"/>
                    </svg>
                    Connetti con Google
                </a>
            </div>
        <?php endif; ?>

    </div>
    <?php
}
