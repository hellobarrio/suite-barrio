<?php
/**
 * Suite Barrio — Statistiche
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
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('SUITE_BARRIO_STATS_VERSION', '3.0.0');
define('SUITE_BARRIO_STATS_OPTION', 'wpb_stats_settings');
define('SUITE_BARRIO_STATS_TOKEN_OPTION', 'wpb_stats_tokens');
define('SUITE_BARRIO_STATS_API_BASE', 'https://analyticsdata.googleapis.com/v1beta/properties/');
define('SUITE_BARRIO_STATS_TOKEN_URL', 'https://oauth2.googleapis.com/token');
define('SUITE_BARRIO_STATS_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth');
define('SUITE_BARRIO_STATS_CACHE_TTL', 1800); // 30 minuti
define('SUITE_BARRIO_STATS_OAUTH_SCOPE', 'https://www.googleapis.com/auth/analytics.readonly');
define('SUITE_BARRIO_STATS_REDIRECT_PATH', 'admin.php?page=wp-barrio-stats&wpb_oauth_callback=1');

/* ─────────────────────────────────────────
   HELPERS — impostazioni e token
   ───────────────────────────────────────── */
function suite_barrio_stats_settings(): array
{
    return get_option(SUITE_BARRIO_STATS_OPTION, []);
}

function suite_barrio_stats_tokens(): array
{
    return get_option(SUITE_BARRIO_STATS_TOKEN_OPTION, []);
}

function suite_barrio_stats_redirect_uri(): string
{
    return admin_url(SUITE_BARRIO_STATS_REDIRECT_PATH);
}

function suite_barrio_stats_is_configured(): bool
{
    $s = suite_barrio_stats_settings();
    return !empty($s['client_id']) && !empty($s['client_secret']) && !empty($s['property_id']);
}

function suite_barrio_stats_is_connected(): bool
{
    $t = suite_barrio_stats_tokens();
    return !empty($t['refresh_token']);
}

function suite_barrio_stats_replace_active(): bool
{
    $s = suite_barrio_stats_settings();
    return !empty($s['replace_dashboard']) && suite_barrio_stats_is_connected();
}

/* ─────────────────────────────────────────
   OAUTH — ottieni access token (con rinnovo automatico)
   ───────────────────────────────────────── */
function suite_barrio_stats_get_access_token(): string|false
{
    $tokens = suite_barrio_stats_tokens();
    if (empty($tokens['refresh_token']))
        return false;

    // Usa il token in cache se ancora valido (scade tra più di 60 sec)
    if (!empty($tokens['access_token']) && !empty($tokens['expires_at'])) {
        if ($tokens['expires_at'] > time() + 60) {
            return $tokens['access_token'];
        }
    }

    // Rinnova tramite refresh_token
    $s = suite_barrio_stats_settings();
    $response = wp_remote_post(SUITE_BARRIO_STATS_TOKEN_URL, [
        'timeout' => 15,
        'body' => [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => $s['client_id'],
            'client_secret' => $s['client_secret'],
        ],
    ]);

    if (is_wp_error($response))
        return false;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['access_token']))
        return false;

    // Salva il nuovo access token
    $tokens['access_token'] = $body['access_token'];
    $tokens['expires_at'] = time() + (int) ($body['expires_in'] ?? 3600);
    update_option(SUITE_BARRIO_STATS_TOKEN_OPTION, $tokens);

    return $body['access_token'];
}

/* ─────────────────────────────────────────
   OAUTH — scambia authorization code con tokens
   ───────────────────────────────────────── */
function suite_barrio_stats_exchange_code(string $code): bool
{
    $s = suite_barrio_stats_settings();
    $response = wp_remote_post(SUITE_BARRIO_STATS_TOKEN_URL, [
        'timeout' => 15,
        'body' => [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => suite_barrio_stats_redirect_uri(),
            'client_id' => $s['client_id'],
            'client_secret' => $s['client_secret'],
        ],
    ]);

    if (is_wp_error($response))
        return false;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['access_token']))
        return false;

    update_option(SUITE_BARRIO_STATS_TOKEN_OPTION, [
        'access_token' => $body['access_token'],
        'refresh_token' => $body['refresh_token'] ?? '',
        'expires_at' => time() + (int) ($body['expires_in'] ?? 3600),
    ]);

    return true;
}

/* ─────────────────────────────────────────
   DATA API — chiama runReport
   ───────────────────────────────────────── */
function suite_barrio_stats_run_report(
    string $property_id,
    array $dimensions,
    array $metrics,
    string $start_date,
    string $end_date,
    int $limit = 10
): array|false {

    $token = suite_barrio_stats_get_access_token();
    if (!$token)
        return false;

    $body = [
        'dateRanges' => [['startDate' => $start_date, 'endDate' => $end_date]],
        'metrics' => $metrics,
        'limit' => $limit,
    ];
    if (!empty($dimensions)) {
        $body['dimensions'] = $dimensions;
    }

    $response = wp_remote_post(
        SUITE_BARRIO_STATS_API_BASE . $property_id . ':runReport',
        [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
        ]
    );

    if (is_wp_error($response))
        return false;

    $http_code = wp_remote_retrieve_response_code($response);
    $parsed = json_decode(wp_remote_retrieve_body($response), true);

    if ($http_code !== 200 || isset($parsed['error'])) {
        // Token revocato: pulisci i token salvati
        if ($http_code === 401) {
            delete_option(SUITE_BARRIO_STATS_TOKEN_OPTION);
        }
        return false;
    }

    return $parsed;
}

/* ─────────────────────────────────────────
   DATA — recupera tutti i dati per la bacheca
   ───────────────────────────────────────── */
function suite_barrio_stats_get_dashboard_data(bool $force = false): array|false
{
    $s = suite_barrio_stats_settings();
    $property_id = $s['property_id'] ?? '';
    if (!$property_id || !suite_barrio_stats_is_connected())
        return false;

    $cache_key = 'wpb_stats_dash_' . $property_id;

    if (!$force) {
        $cached = get_transient($cache_key);
        if ($cached !== false)
            return $cached;
    }

    $data = [];
    $any_error = false;

    // ── 1. Totali: utenti attivi + pageview per 3 periodi ────────────────
    foreach ([
        'today' => ['today', 'today'],
        'week' => ['7daysAgo', 'today'],
        'month' => ['30daysAgo', 'today'],
    ] as $period => [$start, $end]) {
        $res = suite_barrio_stats_run_report($property_id, [], [
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
            'users' => $tv[0]['value'] ?? 0,
            'pageviews' => $tv[1]['value'] ?? 0,
        ];
    }

    // ── 2. Top 10 pagine (30gg) ───────────────────────────────────────────
    $res_pages = suite_barrio_stats_run_report(
        $property_id,
        [['name' => 'pagePath'], ['name' => 'pageTitle']],
        [['name' => 'screenPageViews']],
        '30daysAgo',
        'today',
        10
    );
    if ($res_pages === false)
        $any_error = true;
    $data['top_pages'] = $res_pages['rows'] ?? [];

    // ── 3. Sorgenti di traffico (30gg) ────────────────────────────────────
    $res_src = suite_barrio_stats_run_report(
        $property_id,
        [['name' => 'sessionDefaultChannelGroup']],
        [['name' => 'sessions']],
        '30daysAgo',
        'today',
        8
    );
    if ($res_src === false)
        $any_error = true;
    $data['sources'] = $res_src['rows'] ?? [];

    // ── 4. Andamento giornaliero 30gg ─────────────────────────────────────
    $res_daily = suite_barrio_stats_run_report(
        $property_id,
        [['name' => 'date']],
        [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
        '30daysAgo',
        'today',
        31
    );
    if ($res_daily === false)
        $any_error = true;
    $data['daily'] = $res_daily['rows'] ?? [];

    // ── 5. Dispositivi (30gg) ─────────────────────────────────────────────
    $res_devices = suite_barrio_stats_run_report(
        $property_id,
        [['name' => 'deviceCategory']],
        [['name' => 'sessions'], ['name' => 'activeUsers']],
        '30daysAgo',
        'today',
        5
    );
    if ($res_devices === false)
        $any_error = true;
    $data['devices'] = $res_devices['rows'] ?? [];

    // ── 6. Tempo medio sulla pagina (30gg) ───────────────────────────────
    $res_time = suite_barrio_stats_run_report(
        $property_id,
        [],
        [['name' => 'averageSessionDuration'], ['name' => 'bounceRate']],
        '30daysAgo',
        'today',
        1
    );
    if ($res_time === false)
        $any_error = true;
    $tv_time = $res_time['totals'][0]['metricValues'] ?? $res_time['rows'][0]['metricValues'] ?? [];
    $data['avg_session_duration'] = round((float) ($tv_time[0]['value'] ?? 0));
    $data['bounce_rate'] = round((float) ($tv_time[1]['value'] ?? 0) * 100, 1);

    // ── 7. Dati demografici: età (30gg) ──────────────────────────────────
    // ── 7. Paesi di provenienza (30gg) ───────────────────────
    $res_countries = suite_barrio_stats_run_report(
        $property_id,
        [['name' => 'country']],
        [['name' => 'activeUsers'], ['name' => 'sessions']],
        '30daysAgo',
        'today',
        10
    );
    if ($res_countries === false)
        $any_error = true;
    $data['countries'] = $res_countries['rows'] ?? [];

    $data['fetched_at'] = time();
    $data['has_error'] = $any_error;

    if (!$any_error) {
        set_transient($cache_key, $data, SUITE_BARRIO_STATS_CACHE_TTL);
    }

    return $data;
}

/* ─────────────────────────────────────────
   GESTIONE CALLBACK OAUTH
   Intercetta ?wpb_oauth_callback=1 prima che
   WordPress carichi la pagina admin.
   ───────────────────────────────────────── */
add_action('admin_init', function () {
    if (empty($_GET['wpb_oauth_callback']))
        return;
    if (!current_user_can('manage_options'))
        return;

    // Errore restituito da Google.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- callback OAuth di Google: la verifica CSRF avviene tramite il parametro 'state' (nonce 'suite_barrio_stats_oauth_state') controllato subito sotto; questo ramo si limita a rilanciare l'errore ricevuto, nessuna azione di stato.
    if (!empty($_GET['error'])) {
        wp_safe_redirect(admin_url('admin.php?page=suite-barrio-stats&oauth_error=' . urlencode(sanitize_text_field(wp_unslash($_GET['error'])))));
        exit;
    }

    // Verifica state anti-CSRF (funge da nonce per l'intero callback OAuth)
    $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
    if (!wp_verify_nonce($state, 'suite_barrio_stats_oauth_state')) {
        wp_safe_redirect(admin_url('admin.php?page=suite-barrio-stats&oauth_error=state_mismatch'));
        exit;
    }

    $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
    if (!$code) {
        wp_safe_redirect(admin_url('admin.php?page=suite-barrio-stats&oauth_error=no_code'));
        exit;
    }

    $ok = suite_barrio_stats_exchange_code($code);
    if (!$ok) {
        wp_safe_redirect(admin_url('admin.php?page=suite-barrio-stats&oauth_error=exchange_failed'));
        exit;
    }

    // Successo
    $s = suite_barrio_stats_settings();
    if (!empty($s['property_id'])) {
        delete_transient('wpb_stats_dash_' . $s['property_id']);
    }
    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-stats&oauth_success=1'));
    exit;
});

/* ─────────────────────────────────────────
   SALVATAGGIO IMPOSTAZIONI
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_stats_save', function () {
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');
    check_admin_referer('suite_barrio_stats_save');

    $existing = suite_barrio_stats_settings();
    $client_id = sanitize_text_field(wp_unslash($_POST['client_id'] ?? ''));
    $client_secret = sanitize_text_field(wp_unslash($_POST['client_secret'] ?? ''));
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- già sanitizzato: preg_replace filtra solo caratteri numerici.
    $property_id = preg_replace('/[^0-9]/', '', wp_unslash($_POST['property_id'] ?? ''));
    $replace = !empty($_POST['replace_dashboard']);

    // Mantieni valori esistenti se i campi sono vuoti
    if ($client_id === '')
        $client_id = $existing['client_id'] ?? '';
    if ($client_secret === '')
        $client_secret = $existing['client_secret'] ?? '';

    update_option(SUITE_BARRIO_STATS_OPTION, [
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'property_id' => $property_id,
        'replace_dashboard' => (int) $replace,
    ]);

    // Se le credenziali cambiano, invalida i token esistenti
    if (
        $client_id !== ($existing['client_id'] ?? '') ||
        $client_secret !== ($existing['client_secret'] ?? '')
    ) {
        delete_option(SUITE_BARRIO_STATS_TOKEN_OPTION);
    }

    if ($property_id) {
        delete_transient('wpb_stats_dash_' . $property_id);
    }

    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-stats&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   DISCONNETTI account Google
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_stats_disconnect', function () {
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');
    check_admin_referer('suite_barrio_stats_disconnect');

    delete_option(SUITE_BARRIO_STATS_TOKEN_OPTION);
    $s = suite_barrio_stats_settings();
    if (!empty($s['property_id'])) {
        delete_transient('wpb_stats_dash_' . $s['property_id']);
    }

    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-stats&disconnected=1'));
    exit;
});

/* ─────────────────────────────────────────
   AJAX: refresh dati dashboard
   ───────────────────────────────────────── */
/* Periodi disponibili con relativo range date GA4 */
function suite_barrio_stats_period_range(string $period): array
{
    return match ($period) {
        'today' => ['today', 'today', 'Oggi'],
        'week' => ['7daysAgo', 'today', '7 giorni'],
        'month' => ['30daysAgo', 'today', '30 giorni'],
        'q' => ['90daysAgo', 'today', '90 giorni'],
        'half' => ['180daysAgo', 'today', '6 mesi'],
        'year' => ['365daysAgo', 'today', '12 mesi'],
        'custom' => ['', '', 'Personalizzato'],
        default => ['30daysAgo', 'today', '30 giorni'],
    };
}

add_action('wp_ajax_suite_barrio_stats_refresh', function () {
    check_ajax_referer('suite_barrio_stats_nonce', 'nonce');
    if (!current_user_can('edit_posts'))
        wp_send_json_error('Permessi insufficienti.');

    $data = suite_barrio_stats_get_dashboard_data(true);
    if ($data === false)
        wp_send_json_error('Impossibile recuperare i dati.');

    wp_send_json_success($data);
});

/* Fetch dati per un periodo specifico (o range custom) — non usa la cache globale */
add_action('wp_ajax_suite_barrio_stats_fetch_period', function () {
    check_ajax_referer('suite_barrio_stats_nonce', 'nonce');
    if (!current_user_can('edit_posts'))
        wp_send_json_error('Permessi insufficienti.');

    $s = suite_barrio_stats_settings();
    $property_id = $s['property_id'] ?? '';
    if (!$property_id)
        wp_send_json_error('Property ID mancante.');

    $period = sanitize_key($_POST['period'] ?? 'month');
    $custom_from = sanitize_text_field(wp_unslash($_POST['date_from'] ?? ''));
    $custom_to = sanitize_text_field(wp_unslash($_POST['date_to'] ?? ''));

    if ($period === 'custom') {
        // Valida formato YYYY-MM-DD
        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_from) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_to)
        ) {
            wp_send_json_error('Date non valide.');
        }
        $start = $custom_from;
        $end = $custom_to;
        $label = wp_date('d/m/Y', strtotime($start)) . ' – ' . wp_date('d/m/Y', strtotime($end));
    } else {
        [$start, $end, $label] = suite_barrio_stats_period_range($period);
    }

    // Numero di giorni — determina la granularità del grafico
    $start_ts = strtotime($start === 'today' ? wp_date('Y-m-d') : $start);
    $end_ts = strtotime($end === 'today' ? wp_date('Y-m-d') : $end);
    $days = (int) round(($end_ts - $start_ts) / 86400) + 1;

    $result = [];

    // Totali KPI
    $res_kpi = suite_barrio_stats_run_report($property_id, [], [
        ['name' => 'activeUsers'],
        ['name' => 'screenPageViews'],
        ['name' => 'sessions'],
    ], $start, $end, 1);
    if ($res_kpi === false) $res_kpi = [];
    $tv = $res_kpi['totals'][0]['metricValues'] ?? $res_kpi['rows'][0]['metricValues'] ?? [];
    $result['kpi'] = [
        'users' => $tv[0]['value'] ?? 0,
        'pageviews' => $tv[1]['value'] ?? 0,
        'sessions' => $tv[2]['value'] ?? 0,
    ];

    // Andamento — sempre dimensione 'date' (ordinata da GA4 ASC)
    // Per periodi > 90gg aggreghiamo per settimana lato PHP
    // Per periodi > 365gg aggreghiamo per mese lato PHP
    $res_chart = suite_barrio_stats_run_report(
        $property_id,
        [['name' => 'date']],
        [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
        $start,
        $end,
        500   // max 500 righe, copre oltre 1 anno
    );

    $raw_rows = $res_chart['rows'] ?? [];

    // Ordina per data ASC (GA4 di solito la restituisce già ordinata,
    // ma garantiamo l'ordinamento per sicurezza)
    usort(
        $raw_rows,
        fn($a, $b) =>
        strcmp($a['dimensionValues'][0]['value'], $b['dimensionValues'][0]['value'])
    );

    // Aggrega in base alla granularità
    if ($days > 180) {
        // Aggrega per mese: chiave = YYYYMM
        $granularity = 'month';
        $buckets = [];
        foreach ($raw_rows as $row) {
            $date = $row['dimensionValues'][0]['value']; // YYYYMMDD
            $key = substr($date, 0, 6);                // YYYYMM
            $buckets[$key]['pv'] = ($buckets[$key]['pv'] ?? 0) + (int) ($row['metricValues'][0]['value'] ?? 0);
            $buckets[$key]['users'] = ($buckets[$key]['users'] ?? 0) + (int) ($row['metricValues'][1]['value'] ?? 0);
        }
        ksort($buckets);
        $chart_points = [];
        foreach ($buckets as $key => $vals) {
            $month_names = [
                '01' => 'Gen',
                '02' => 'Feb',
                '03' => 'Mar',
                '04' => 'Apr',
                '05' => 'Mag',
                '06' => 'Giu',
                '07' => 'Lug',
                '08' => 'Ago',
                '09' => 'Set',
                '10' => 'Ott',
                '11' => 'Nov',
                '12' => 'Dic'
            ];
            $mm = substr($key, 4, 2);
            $yyyy = substr($key, 0, 4);
            $chart_points[] = [
                'label' => ($month_names[$mm] ?? $mm) . ' ' . $yyyy,
                'pv' => $vals['pv'],
                'users' => $vals['users'],
            ];
        }
    } elseif ($days > 60) {
        // Aggrega per settimana ISO (lunedì): chiave = YYYY-Www
        $granularity = 'week';
        $buckets = [];
        foreach ($raw_rows as $row) {
            $date = $row['dimensionValues'][0]['value']; // YYYYMMDD
            $ts = mktime(0, 0, 0, (int) substr($date, 4, 2), (int) substr($date, 6, 2), (int) substr($date, 0, 4));
            // Lunedì della settimana
            $mon = wp_date('Y-m-d', strtotime('monday this week', $ts));
            $key = $mon;
            $buckets[$key]['pv'] = ($buckets[$key]['pv'] ?? 0) + (int) ($row['metricValues'][0]['value'] ?? 0);
            $buckets[$key]['users'] = ($buckets[$key]['users'] ?? 0) + (int) ($row['metricValues'][1]['value'] ?? 0);
        }
        ksort($buckets);
        $chart_points = [];
        foreach ($buckets as $key => $vals) {
            $parts = explode('-', $key);  // [YYYY, MM, DD]
            $chart_points[] = [
                'label' => $parts[2] . '/' . $parts[1],   // gg/mm del lunedì
                'pv' => $vals['pv'],
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
                'label' => substr($date, 6, 2) . '/' . substr($date, 4, 2),
                'pv' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
            ];
        }
    }

    $result['chart_points'] = $chart_points;
    $result['granularity'] = $granularity;

    // Top pages
    $res_pages = suite_barrio_stats_run_report(
        $property_id,
        [['name' => 'pagePath'], ['name' => 'pageTitle']],
        [['name' => 'screenPageViews']],
        $start,
        $end,
        10
    );
    $result['top_pages'] = $res_pages['rows'] ?? [];

    // Sorgenti
    $res_src = suite_barrio_stats_run_report(
        $property_id,
        [['name' => 'sessionDefaultChannelGroup']],
        [['name' => 'sessions']],
        $start,
        $end,
        8
    );
    $result['sources'] = $res_src['rows'] ?? [];

    // Dispositivi
    $res_devices = suite_barrio_stats_run_report(
        $property_id,
        [['name' => 'deviceCategory']],
        [['name' => 'sessions'], ['name' => 'activeUsers']],
        $start,
        $end,
        5
    );
    $result['devices'] = $res_devices['rows'] ?? [];

    // Tempo medio sulla pagina + bounce rate
    $res_time = suite_barrio_stats_run_report(
        $property_id,
        [],
        [['name' => 'averageSessionDuration'], ['name' => 'bounceRate']],
        $start,
        $end,
        1
    );
    $tv_time = $res_time['totals'][0]['metricValues'] ?? $res_time['rows'][0]['metricValues'] ?? [];
    $result['avg_session_duration'] = round((float) ($tv_time[0]['value'] ?? 0));
    $result['bounce_rate'] = round((float) ($tv_time[1]['value'] ?? 0) * 100, 1);

    // Paesi di provenienza
    $res_countries = suite_barrio_stats_run_report(
        $property_id,
        [['name' => 'country']],
        [['name' => 'activeUsers'], ['name' => 'sessions']],
        $start,
        $end,
        10
    );
    $result['countries'] = $res_countries['rows'] ?? [];

    $result['label'] = $label;
    $result['period'] = $period;

    wp_send_json_success($result);
});

/* ─────────────────────────────────────────
   MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    add_submenu_page(
        'suite-barrio',
        'Statistiche — Suite Barrio',
        'Statistiche',
        'manage_options',
        'suite-barrio-stats',
        'suite_barrio_stats_admin_page'
    );
}, 19);

/* ─────────────────────────────────────────
   BACHECA SOSTITUTIVA
   ───────────────────────────────────────── */
add_action('wp_dashboard_setup', function () {
    if (!suite_barrio_stats_replace_active())
        return;
    if (!current_user_can('edit_posts'))
        return;

    global $wp_meta_boxes;
    $wp_meta_boxes['dashboard'] = [];

    wp_add_dashboard_widget('suite_barrio_stats_widget', 'Statistiche', 'suite_barrio_stats_dashboard_widget');
}, 20);

/* ─────────────────────────────────────────
   ENQUEUE
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    $is_dash = ($hook === 'index.php');

    if ($is_dash && suite_barrio_stats_replace_active()) {
        wp_enqueue_script(
            'suite-barrio-stats-chartjs',
            SUITE_BARRIO_PLUGIN_URL . 'assets/js/chart.umd.min.js',
            [],
            '4.5.1',
            true
        );
        wp_add_inline_script('suite-barrio-stats-chartjs', suite_barrio_stats_dashboard_js());
        wp_add_inline_style('wp-admin', suite_barrio_stats_dashboard_css());
        wp_add_inline_style('wp-admin', suite_barrio_stats_widget_fullscreen_css());
    }
});

/* ─────────────────────────────────────────
   WIDGET BACHECA
   ───────────────────────────────────────── */
function suite_barrio_stats_dashboard_widget()
{
    $data = suite_barrio_stats_get_dashboard_data();
    $nonce = wp_create_nonce('suite_barrio_stats_nonce');
    $ajax = admin_url('admin-ajax.php');

    if ($data === false): ?>
        <div class="suite-barrio-stats-error">
            <span class="dashicons dashicons-warning"></span>
            Impossibile recuperare i dati.
            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-stats')); ?>">
                Controlla la configurazione →
            </a>
        </div>
        <?php return; endif;

    $age_min = isset($data['fetched_at'])
        ? max(0, round((time() - $data['fetched_at']) / 60))
        : 0;
    ?>
    <div id="suite-barrio-stats-board" data-nonce="<?php echo esc_attr($nonce); ?>" data-ajax="<?php echo esc_url($ajax); ?>">

        <!-- Toolbar: periodi + custom range + refresh -->
        <div class="suite-barrio-stats-toolbar">
            <div class="suite-barrio-stats-periods">
                <button class="suite-barrio-period-btn" data-period="today">Oggi</button>
                <button class="suite-barrio-period-btn" data-period="week">7 gg</button>
                <button class="suite-barrio-period-btn active" data-period="month">30 gg</button>
                <button class="suite-barrio-period-btn" data-period="q">90 gg</button>
                <button class="suite-barrio-period-btn" data-period="half">6 mesi</button>
                <button class="suite-barrio-period-btn" data-period="year">1 anno</button>
                <button class="suite-barrio-period-btn" data-period="custom">Personalizzato</button>
            </div>
            <div class="suite-barrio-stats-toolbar-right">
                <span class="suite-barrio-stats-age" id="suite-barrio-stats-age">
                    <?php echo $age_min === 0 ? 'Appena aggiornato' : 'Aggiornato ' . esc_html($age_min) . ' min fa'; ?>
                </span>
                <button class="suite-barrio-stats-refresh" id="suite-barrio-stats-refresh-btn" title="Aggiorna ora">
                    <span class="dashicons dashicons-update"></span>
                </button>
            </div>
        </div>

        <!-- Range date personalizzato (nascosto di default) -->
        <div class="suite-barrio-stats-custom-range" id="suite-barrio-custom-range" style="display:none;">
            <label>Da <input type="date" id="suite-barrio-date-from" class="suite-barrio-date-input"></label>
            <label>A &nbsp;<input type="date" id="suite-barrio-date-to" class="suite-barrio-date-input"></label>
            <button class="suite-barrio-custom-apply" id="suite-barrio-apply-custom"
                style="padding:5px 14px;font-size:12px;font-weight:600;background:#111827;color:#fff;border:none;border-radius:7px;cursor:pointer;font-family:inherit;">Applica</button>
        </div>

        <!-- KPI cards — periodo selezionato -->
        <div class="suite-barrio-stats-kpis" id="suite-barrio-kpis">
            <?php
            // Carica dati iniziali del periodo default (30gg)
            $s = suite_barrio_stats_settings();
            $init_kpi = suite_barrio_stats_run_report(
                $s['property_id'],
                [],
                [['name' => 'activeUsers'], ['name' => 'screenPageViews'], ['name' => 'sessions'], ['name' => 'averageSessionDuration'], ['name' => 'bounceRate']],
                '30daysAgo',
                'today',
                1
            );
            if ($init_kpi === false) $init_kpi = [];
            $itv = $init_kpi['totals'][0]['metricValues'] ?? $init_kpi['rows'][0]['metricValues'] ?? [];
            $init_avg_sec = round((float) ($itv[3]['value'] ?? 0));
            $init_bounce = round((float) ($itv[4]['value'] ?? 0) * 100, 1);
            $init_avg_fmt = sprintf('%d:%02d', intdiv($init_avg_sec, 60), $init_avg_sec % 60);
            ?>
            <div class="suite-barrio-kpi-card">
                <div class="suite-barrio-kpi-label">Utenti attivi</div>
                <div class="suite-barrio-kpi-value" id="suite-barrio-kpi-users">
                    <?php echo esc_html(number_format((int) ($itv[0]['value'] ?? 0))); ?>
                </div>
                <div class="suite-barrio-kpi-sub" id="suite-barrio-kpi-period-label">30 giorni</div>
            </div>
            <div class="suite-barrio-kpi-card">
                <div class="suite-barrio-kpi-label">Pageview</div>
                <div class="suite-barrio-kpi-value" id="suite-barrio-kpi-pageviews">
                    <?php echo esc_html(number_format((int) ($itv[1]['value'] ?? 0))); ?>
                </div>
                <div class="suite-barrio-kpi-sub">30 giorni</div>
            </div>
            <div class="suite-barrio-kpi-card">
                <div class="suite-barrio-kpi-label">Sessioni</div>
                <div class="suite-barrio-kpi-value" id="suite-barrio-kpi-sessions">
                    <?php echo esc_html(number_format((int) ($itv[2]['value'] ?? 0))); ?>
                </div>
                <div class="suite-barrio-kpi-sub">30 giorni</div>
            </div>
            <div class="suite-barrio-kpi-card">
                <div class="suite-barrio-kpi-label">Durata media</div>
                <div class="suite-barrio-kpi-value" id="suite-barrio-kpi-avg-duration"><?php echo esc_html($init_avg_fmt); ?></div>
                <div class="suite-barrio-kpi-sub">min:sec · 30 giorni</div>
            </div>
            <div class="suite-barrio-kpi-card">
                <div class="suite-barrio-kpi-label">Frequenza di rimbalzo</div>
                <div class="suite-barrio-kpi-value" id="suite-barrio-kpi-bounce"><?php echo esc_html($init_bounce); ?>%</div>
                <div class="suite-barrio-kpi-sub">30 giorni</div>
            </div>
        </div>

        <!-- Griglia grafici + tabelle -->
        <div class="suite-barrio-stats-grid">

            <!-- Grafico andamento -->
            <div class="suite-barrio-stats-panel suite-barrio-stats-panel--chart">
                <div class="suite-barrio-stats-panel-title" id="suite-barrio-chart-title">Andamento — 30 giorni</div>
                <div class="suite-barrio-stats-chart-wrap">
                    <canvas id="suite-barrio-stats-chart"></canvas>
                </div>
                <?php
                $daily_sorted = $data['daily'];
                usort(
                    $daily_sorted,
                    fn($a, $b) =>
                    strcmp($a['dimensionValues'][0]['value'] ?? '', $b['dimensionValues'][0]['value'] ?? '')
                );
                $labels = $pvs = $users_arr = [];
                foreach ($daily_sorted as $row) {
                    $d = $row['dimensionValues'][0]['value'] ?? '';
                    $labels[] = substr($d, 6, 2) . '/' . substr($d, 4, 2);
                    $pvs[] = (int) ($row['metricValues'][0]['value'] ?? 0);
                    $users_arr[] = (int) ($row['metricValues'][1]['value'] ?? 0);
                }
                wp_add_inline_script(
                    'suite-barrio-stats-chartjs',
                    sprintf(
                        'window.suiteBarrioStatsInitData = { labels: %s, pageviews: %s, users: %s };',
                        wp_json_encode($labels),
                        wp_json_encode($pvs),
                        wp_json_encode($users_arr)
                    )
                );
                ?>
            </div>

            <!-- Top pages -->
            <div class="suite-barrio-stats-panel">
                <div class="suite-barrio-stats-panel-title" id="suite-barrio-pages-title">Pagine più viste — 30 giorni</div>
                <table class="suite-barrio-stats-table" id="suite-barrio-pages-table">
                    <thead>
                        <tr>
                            <th>Pagina</th>
                            <th>Views</th>
                        </tr>
                    </thead>
                    <tbody id="suite-barrio-pages-tbody">
                        <?php foreach ($data['top_pages'] as $row):
                            $path = $row['dimensionValues'][0]['value'] ?? '/';
                            $ptitle = $row['dimensionValues'][1]['value'] ?? $path;
                            $views = number_format((int) ($row['metricValues'][0]['value'] ?? 0));
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url(home_url($path)); ?>" target="_blank" rel="noopener"
                                        title="<?php echo esc_attr($path); ?>">
                                        <?php echo esc_html(mb_strimwidth($ptitle, 0, 40, '…')); ?>
                                    </a>
                                </td>
                                <td class="suite-barrio-stats-num"><?php echo esc_html($views); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Sorgenti -->
            <div class="suite-barrio-stats-panel">
                <div class="suite-barrio-stats-panel-title" id="suite-barrio-src-title">Sorgenti di traffico — 30 giorni</div>
                <div class="suite-barrio-stats-sources" id="suite-barrio-sources">
                    <?php
                    $total_sess = array_sum(array_map(fn($r) => (int) ($r['metricValues'][0]['value'] ?? 0), $data['sources']));
                    $colors = ['Organic Search' => '#2271b1', 'Direct' => '#00a32a', 'Referral' => '#d63638', 'Organic Social' => '#dba617', 'Email' => '#8c5a0b', 'Paid Search' => '#9b59b6', 'Unassigned' => '#a7aaad'];
                    foreach ($data['sources'] as $row):
                        $ch = $row['dimensionValues'][0]['value'] ?? '(other)';
                        $sess = (int) ($row['metricValues'][0]['value'] ?? 0);
                        $pct = $total_sess > 0 ? round($sess / $total_sess * 100) : 0;
                        $col = $colors[$ch] ?? '#646970';
                        ?>
                        <div class="suite-barrio-stats-source-row">
                            <div class="suite-barrio-stats-source-label"><?php echo esc_html($ch); ?></div>
                            <div class="suite-barrio-stats-source-bar-wrap">
                                <div class="suite-barrio-stats-source-bar"
                                    style="width:<?php echo esc_attr($pct); ?>%;background:<?php echo esc_attr($col); ?>;">
                                </div>
                            </div>
                            <div class="suite-barrio-stats-source-pct"><?php echo esc_html($pct); ?>%</div>
                            <div class="suite-barrio-stats-source-num"><?php echo esc_html(number_format($sess)); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Dispositivi -->
            <div class="suite-barrio-stats-panel">
                <div class="suite-barrio-stats-panel-title" id="suite-barrio-devices-title">Dispositivi — 30 giorni</div>
                <div class="suite-barrio-stats-sources" id="suite-barrio-devices">
                    <?php
                    $dev_colors = ['desktop' => '#2271b1', 'mobile' => '#00a32a', 'tablet' => '#dba617'];
                    $total_dev = array_sum(array_map(fn($r) => (int) ($r['metricValues'][0]['value'] ?? 0), $data['devices']));
                    foreach ($data['devices'] as $row):
                        $dev = $row['dimensionValues'][0]['value'] ?? 'other';
                        $sess = (int) ($row['metricValues'][0]['value'] ?? 0);
                        $usr = (int) ($row['metricValues'][1]['value'] ?? 0);
                        $pct = $total_dev > 0 ? round($sess / $total_dev * 100) : 0;
                        $col = $dev_colors[strtolower($dev)] ?? '#646970';
                        $label_dev = match (strtolower($dev)) { 'desktop' => 'Desktop', 'mobile' => 'Mobile', 'tablet' => 'Tablet', default => ucfirst($dev)};
                        ?>
                        <div class="suite-barrio-stats-source-row">
                            <div class="suite-barrio-stats-source-label"><?php echo esc_html($label_dev); ?></div>
                            <div class="suite-barrio-stats-source-bar-wrap">
                                <div class="suite-barrio-stats-source-bar"
                                    style="width:<?php echo esc_attr($pct); ?>%;background:<?php echo esc_attr($col); ?>;">
                                </div>
                            </div>
                            <div class="suite-barrio-stats-source-pct"><?php echo esc_html($pct); ?>%</div>
                            <div class="suite-barrio-stats-source-num"><?php echo esc_html(number_format($usr)); ?> utenti</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Paesi di provenienza -->
            <div class="suite-barrio-stats-panel suite-barrio-stats-panel--wide">
                <div class="suite-barrio-stats-panel-title" id="suite-barrio-countries-title">Paesi di provenienza &mdash; 30 giorni</div>
                <div class="suite-barrio-stats-sources" id="suite-barrio-countries">
                    <?php
                    $total_ctry = array_sum(array_map(fn($r) => (int) ($r['metricValues'][0]['value'] ?? 0), $data['countries']));
                    $ctry_colors = ['#2271b1', '#00a32a', '#dba617', '#d63638', '#9b59b6', '#8c5a0b', '#0891b2', '#a7aaad', '#646970', '#3d6b50'];
                    foreach ($data['countries'] as $i => $row):
                        $ctry = $row['dimensionValues'][0]['value'] ?? '(unknown)';
                        $usr = (int) ($row['metricValues'][0]['value'] ?? 0);
                        $sess = (int) ($row['metricValues'][1]['value'] ?? 0);
                        $pct = $total_ctry > 0 ? round($usr / $total_ctry * 100) : 0;
                        $col = $ctry_colors[$i % count($ctry_colors)];
                        ?>
                        <div class="suite-barrio-stats-source-row suite-barrio-stats-source-row--country">
                            <div class="suite-barrio-stats-source-label"><?php echo esc_html($ctry); ?></div>
                            <div class="suite-barrio-stats-source-bar-wrap">
                                <div class="suite-barrio-stats-source-bar"
                                    style="width:<?php echo esc_attr($pct); ?>%;background:<?php echo esc_attr($col); ?>;">
                                </div>
                            </div>
                            <div class="suite-barrio-stats-source-pct"><?php echo esc_html($pct); ?>%</div>
                            <div class="suite-barrio-stats-source-num"><?php echo esc_html(number_format($usr)); ?> utenti</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <?php if (current_user_can('manage_options')): ?>
            <div class="suite-barrio-stats-footer">
                <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-stats')); ?>">Impostazioni statistiche</a>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/* ─────────────────────────────────────────
   CSS — bacheca sostitutiva (fullscreen)
   ───────────────────────────────────────── */
function suite_barrio_stats_widget_fullscreen_css(): string
{
    return '
    #suite_barrio_stats_widget .hndle, #suite_barrio_stats_widget .handle-actions,
    .wrap > h1.wp-heading-inline, #screen-meta, #screen-meta-links { display:none !important; }
    #wpbody-content .wrap { padding-top:0 !important; }
    #dashboard-widgets-wrap { margin-top:0 !important; }
    #suite_barrio_stats_widget { margin:0; border:none; box-shadow:none; }
    #suite_barrio_stats_widget .inside { padding:0; margin:0; }
    #dashboard-widgets .postbox-container { width:100% !important; }
    .postbox-container #normal-sortables { margin:0; }
    ';
}

/* ─────────────────────────────────────────
   CSS — bacheca
   ───────────────────────────────────────── */
function suite_barrio_stats_dashboard_css(): string
{
    return '
    #suite-barrio-stats-board { font-family:-apple-system,BlinkMacSystemFont,"Inter","Segoe UI",sans-serif; padding:20px 20px 8px; color:#111827; }

    /* ── Toolbar ── */
    .suite-barrio-stats-toolbar { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:18px; flex-wrap:wrap; }
    .suite-barrio-stats-periods { display:flex; align-items:center; gap:3px; flex-wrap:wrap; }
    .suite-barrio-period-btn { padding:6px 13px; font-size:12px; font-weight:600; border:1px solid #e5e7eb; background:#fff; border-radius:20px; cursor:pointer; color:#6b7280; transition:all .15s; white-space:nowrap; }
    .suite-barrio-period-btn:hover { border-color:#9ca3af; color:#111827; }
    .suite-barrio-period-btn.active { background:#111827; border-color:#111827; color:#fff; }
    .suite-barrio-stats-toolbar-right { display:flex; align-items:center; gap:6px; }
    .suite-barrio-stats-age { font-size:11px; color:#9ca3af; white-space:nowrap; }
    .suite-barrio-stats-refresh { background:none; border:none; cursor:pointer; padding:2px 4px; color:#9ca3af; transition:color .15s; line-height:1; }
    .suite-barrio-stats-refresh:hover { color:#111827; }
    .suite-barrio-stats-refresh.spinning .dashicons { animation:suite-barrio-spin .6s linear infinite; }
    @keyframes suite-barrio-spin { to { transform:rotate(360deg); } }

    /* ── Custom date range ── */
    .suite-barrio-stats-custom-range { display:flex; align-items:center; gap:10px; margin-bottom:14px; padding:10px 14px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; font-size:12px; flex-wrap:wrap; }
    .suite-barrio-stats-custom-range label { display:flex; align-items:center; gap:5px; color:#6b7280; }
    .suite-barrio-date-input { border:1px solid #e5e7eb; border-radius:7px; padding:5px 9px; font-size:12px; color:#111827; }
    .suite-barrio-custom-apply { font-size:12px !important; padding:5px 12px !important; height:auto !important; background:#111827 !important; color:#fff !important; border:none !important; border-radius:7px !important; }

    /* ── KPI cards ── */
    .suite-barrio-stats-kpis { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
    .suite-barrio-kpi-card { flex:1; min-width:120px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px 18px; transition:opacity .2s; box-shadow:0 1px 3px rgba(0,0,0,.05); }
    .suite-barrio-kpi-card.loading { opacity:.45; }
    .suite-barrio-kpi-label { font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; font-weight:700; margin-bottom:6px; }
    .suite-barrio-kpi-value { font-size:28px; font-weight:700; color:#111827; line-height:1; }
    .suite-barrio-kpi-sub   { font-size:11px; color:#9ca3af; margin-top:4px; }

    /* ── Grid + panels ── */
    .suite-barrio-stats-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; }
    .suite-barrio-stats-panel--chart { grid-column:1 / -1; }
    .suite-barrio-stats-panel { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px 18px; transition:opacity .2s; box-shadow:0 1px 3px rgba(0,0,0,.04); }
    .suite-barrio-stats-panel.loading { opacity:.45; pointer-events:none; }
    .suite-barrio-stats-panel-title { font-size:11px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.5px; margin-bottom:14px; }
    .suite-barrio-stats-chart-wrap { height:160px; position:relative; }
    .suite-barrio-stats-panel--wide { grid-column:1 / -1; }

    /* ── Tabella pagine ── */
    .suite-barrio-stats-table { width:100%; border-collapse:collapse; font-size:12px; }
    .suite-barrio-stats-table thead th { text-align:left; font-size:11px; color:#9ca3af; font-weight:700; padding-bottom:6px; border-bottom:1px solid #f3f4f6; text-transform:uppercase; letter-spacing:.4px; }
    .suite-barrio-stats-table thead th:last-child { text-align:right; }
    .suite-barrio-stats-table tbody tr:hover td { background:#f9fafb; }
    .suite-barrio-stats-table td { padding:7px 4px; border-bottom:1px solid #f3f4f6; color:#374151; }
    .suite-barrio-stats-table a { color:#111827; text-decoration:none; font-weight:500; }
    .suite-barrio-stats-table a:hover { text-decoration:underline; }
    .suite-barrio-stats-num { text-align:right; font-variant-numeric:tabular-nums; color:#6b7280; }

    /* ── Sorgenti ── */
    .suite-barrio-stats-source-row { display:grid; grid-template-columns:130px 1fr 36px 52px; align-items:center; gap:8px; padding:6px 0; border-bottom:1px solid #f3f4f6; font-size:12px; }
    .suite-barrio-stats-source-row:last-child { border-bottom:none; }
    .suite-barrio-stats-source-label { color:#374151; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-weight:500; }
    .suite-barrio-stats-source-bar-wrap { background:#f3f4f6; border-radius:20px; height:6px; overflow:hidden; }
    .suite-barrio-stats-source-bar { height:100%; border-radius:20px; background:#111827; }
    .suite-barrio-stats-source-pct { text-align:right; color:#6b7280; font-size:11px; font-weight:600; }
    .suite-barrio-stats-source-num { text-align:right; color:#9ca3af; font-size:11px; font-variant-numeric:tabular-nums; }

    /* ── Footer ── */
    .suite-barrio-stats-footer { font-size:12px; color:#9ca3af; padding-top:8px; }
    .suite-barrio-stats-footer a { color:#6b7280; text-decoration:none; }
    .suite-barrio-stats-footer a:hover { color:#111827; }

    /* ── Errore ── */
    .suite-barrio-stats-error { padding:20px; color:#6b7280; font-size:13px; display:flex; align-items:center; gap:8px; }
    .suite-barrio-stats-error .dashicons { color:#d97706; }
    .suite-barrio-stats-error a { color:#111827; }
';
}

/* ─────────────────────────────────────────
   JS — bacheca
   ───────────────────────────────────────── */
function suite_barrio_stats_dashboard_js(): string
{
    return '
document.addEventListener("DOMContentLoaded", function () {
    var board = document.getElementById("suite-barrio-stats-board");
    if (!board) return;
    var ajaxUrl = board.dataset.ajax;
    var nonce   = board.dataset.nonce;

    function esc(s) { return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;"); }

    // Colori sorgenti
    var srcColors = {
        "Organic Search":"#2271b1","Direct":"#00a32a","Referral":"#d63638",
        "Organic Social":"#dba617","Email":"#8c5a0b","Paid Search":"#9b59b6","Unassigned":"#a7aaad"
    };

    // ── Grafico ────────────────────────────────────────────────────────
    var chartInst = null;
    function buildChart(labels, pvs, users) {
        var ctx = document.getElementById("suite-barrio-stats-chart");
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
    var init = window.suiteBarrioStatsInitData;
    if (init) buildChart(init.labels, init.pageviews, init.users);

    // ── Funzione aggiornamento periodo ────────────────────────────────
    function fmt(n) { return Number(n).toLocaleString("it-IT"); }

    function setLoading(on) {
        document.querySelectorAll(".suite-barrio-kpi-card, .suite-barrio-stats-panel").forEach(function(el) {
            el.classList.toggle("loading", on);
        });
    }

    function applyPeriod(period, dateFrom, dateTo) {
        setLoading(true);
        var params = { action:"suite_barrio_stats_fetch_period", nonce:nonce, period:period };
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
            document.getElementById("suite-barrio-kpi-users").textContent     = fmt(d.kpi.users);
            document.getElementById("suite-barrio-kpi-pageviews").textContent  = fmt(d.kpi.pageviews);
            document.getElementById("suite-barrio-kpi-sessions").textContent   = fmt(d.kpi.sessions);
            // Durata media (secondi → mm:ss)
            var avgSec = parseInt(d.avg_session_duration || 0);
            document.getElementById("suite-barrio-kpi-avg-duration").textContent = Math.floor(avgSec/60) + ":" + String(avgSec%60).padStart(2,"0");
            // Bounce rate
            document.getElementById("suite-barrio-kpi-bounce").textContent = (d.bounce_rate || 0) + "%";
            document.querySelectorAll(".suite-barrio-kpi-sub").forEach(function(el){ el.textContent = lbl; });

            // Grafico — usa i punti già aggregati e ordinati dal PHP
            var gLabels = [], gPvs = [], gUsers = [];
            (d.chart_points || []).forEach(function(pt) {
                gLabels.push(pt.label);
                gPvs.push(pt.pv);
                gUsers.push(pt.users);
            });
            buildChart(gLabels, gPvs, gUsers);
            document.getElementById("suite-barrio-chart-title").textContent = "Andamento — " + lbl;

            // Pagine
            document.getElementById("suite-barrio-pages-title").textContent = "Pagine più viste — " + lbl;
            var tbody = document.getElementById("suite-barrio-pages-tbody");
            tbody.innerHTML = "";
            (d.top_pages || []).forEach(function(row) {
                var path   = row.dimensionValues[0].value;
                var ptitle = row.dimensionValues[1].value || path;
                if (ptitle.length > 40) ptitle = ptitle.slice(0,40) + "…";
                var views  = fmt(row.metricValues[0].value);
                tbody.innerHTML += "<tr><td><a href=\"" + esc(location.origin + path) + "\" target=\"_blank\">" + esc(ptitle) + "</a></td><td class=\"suite-barrio-stats-num\">" + views + "</td></tr>";
            });

            // Sorgenti
            document.getElementById("suite-barrio-src-title").textContent = "Sorgenti di traffico — " + lbl;
            var totSess = (d.sources || []).reduce(function(s,r){ return s + parseInt(r.metricValues[0].value); }, 0);
            var srcWrap = document.getElementById("suite-barrio-sources");
            srcWrap.innerHTML = "";
            (d.sources || []).forEach(function(row) {
                var ch   = row.dimensionValues[0].value;
                var sess = parseInt(row.metricValues[0].value);
                var pct  = totSess > 0 ? Math.round(sess / totSess * 100) : 0;
                var col  = srcColors[ch] || "#646970";
                srcWrap.innerHTML +=
                    "<div class=\"suite-barrio-stats-source-row\">" +
                    "<div class=\"suite-barrio-stats-source-label\">" + esc(ch) + "</div>" +
                    "<div class=\"suite-barrio-stats-source-bar-wrap\"><div class=\"suite-barrio-stats-source-bar\" style=\"width:" + pct + "%;background:" + col + ";\"></div></div>" +
                    "<div class=\"suite-barrio-stats-source-pct\">" + pct + "%</div>" +
                    "<div class=\"suite-barrio-stats-source-num\">" + fmt(sess) + "</div>" +
                    "</div>";
            });

            // Dispositivi
            document.getElementById("suite-barrio-devices-title").textContent = "Dispositivi — " + lbl;
            var devColors = { desktop:"#2271b1", mobile:"#00a32a", tablet:"#dba617" };
            var devLabels = { desktop:"Desktop", mobile:"Mobile", tablet:"Tablet" };
            var totDev = (d.devices || []).reduce(function(s,r){ return s + parseInt(r.metricValues[0].value); }, 0);
            var devWrap = document.getElementById("suite-barrio-devices");
            devWrap.innerHTML = "";
            (d.devices || []).forEach(function(row) {
                var dev  = (row.dimensionValues[0].value || "other").toLowerCase();
                var sess = parseInt(row.metricValues[0].value);
                var usr  = parseInt(row.metricValues[1].value);
                var pct  = totDev > 0 ? Math.round(sess / totDev * 100) : 0;
                var col  = devColors[dev] || "#646970";
                var lbl2 = devLabels[dev] || (dev.charAt(0).toUpperCase() + dev.slice(1));
                devWrap.innerHTML +=
                    "<div class=\"suite-barrio-stats-source-row\">" +
                    "<div class=\"suite-barrio-stats-source-label\">" + esc(lbl2) + "</div>" +
                    "<div class=\"suite-barrio-stats-source-bar-wrap\"><div class=\"suite-barrio-stats-source-bar\" style=\"width:" + pct + "%;background:" + col + ";\"></div></div>" +
                    "<div class=\"suite-barrio-stats-source-pct\">" + pct + "%</div>" +
                    "<div class=\"suite-barrio-stats-source-num\">" + fmt(usr) + " utenti</div>" +
                    "</div>";
            });

            // Dati demografici: età
            // Paesi di provenienza
            document.getElementById("suite-barrio-countries-title").textContent = "Paesi di provenienza — " + lbl;
            var ctryColors = ["#2271b1","#00a32a","#dba617","#d63638","#9b59b6","#8c5a0b","#0891b2","#a7aaad","#646970","#3d6b50"];
            var totCtry = (d.countries || []).reduce(function(s,r){ return s + parseInt(r.metricValues[0].value); }, 0);
            var ctryWrap = document.getElementById("suite-barrio-countries");
            ctryWrap.innerHTML = "";
            (d.countries || []).forEach(function(row, i) {
                var ctry = row.dimensionValues[0].value || "(unknown)";
                var usr  = parseInt(row.metricValues[0].value);
                var sess = parseInt(row.metricValues[1].value);
                var pct  = totCtry > 0 ? Math.round(usr / totCtry * 100) : 0;
                var col  = ctryColors[i % ctryColors.length];
                ctryWrap.innerHTML +=
                    "<div class=\"suite-barrio-stats-source-row suite-barrio-stats-source-row--country\">" +
                    "<div class=\"suite-barrio-stats-source-label\">" + esc(ctry) + "</div>" +
                    "<div class=\"suite-barrio-stats-source-bar-wrap\"><div class=\"suite-barrio-stats-source-bar\" style=\"width:" + pct + "%;background:" + col + ";\"></div></div>" +
                    "<div class=\"suite-barrio-stats-source-pct\">" + pct + "%</div>" +
                    "<div class=\"suite-barrio-stats-source-num\">" + fmt(usr) + " utenti</div>" +
                    "</div>";
            });
        })
        .catch(function(){ setLoading(false); });
    }

    // ── Pulsanti periodo ──────────────────────────────────────────────
    document.querySelectorAll(".suite-barrio-period-btn").forEach(function(btn) {
        btn.addEventListener("click", function() {
            document.querySelectorAll(".suite-barrio-period-btn").forEach(function(b){ b.classList.remove("active"); });
            this.classList.add("active");
            var p = this.dataset.period;
            var cr = document.getElementById("suite-barrio-custom-range");
            if (p === "custom") {
                cr.style.display = "flex";
            } else {
                cr.style.display = "none";
                applyPeriod(p, "", "");
            }
        });
    });

    // ── Range personalizzato ──────────────────────────────────────────
    document.getElementById("suite-barrio-apply-custom") && document.getElementById("suite-barrio-apply-custom").addEventListener("click", function() {
        var from = document.getElementById("suite-barrio-date-from").value;
        var to   = document.getElementById("suite-barrio-date-to").value;
        if (!from || !to) { alert("Seleziona entrambe le date."); return; }
        if (from > to) { alert("La data di inizio deve essere precedente alla fine."); return; }
        applyPeriod("custom", from, to);
    });

    // ── Refresh (forza ricalcolo cache + ricarica pagina) ─────────────
    document.getElementById("suite-barrio-stats-refresh-btn") && document.getElementById("suite-barrio-stats-refresh-btn").addEventListener("click", function() {
        var btn = this;
        btn.classList.add("spinning"); btn.disabled = true;
        fetch(ajaxUrl, {
            method:"POST", headers:{"Content-Type":"application/x-www-form-urlencoded"},
            body: new URLSearchParams({ action:"suite_barrio_stats_refresh", nonce:nonce })
        }).then(function(r){ return r.json(); }).then(function(res){
            btn.classList.remove("spinning"); btn.disabled = false;
            if (res.success) window.location.reload();
        }).catch(function(){ btn.classList.remove("spinning"); btn.disabled = false; });
    });
});
';
}

/* ─────────────────────────────────────────
   PAGINA ADMIN — Impostazioni
   ───────────────────────────────────────── */
function suite_barrio_stats_admin_page()
{
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');

    $s = suite_barrio_stats_settings();
    $connected = suite_barrio_stats_is_connected();
    $configured = suite_barrio_stats_is_configured();
    $client_id = $s['client_id'] ?? '';
    $client_secret = $s['client_secret'] ?? '';
    $property_id = $s['property_id'] ?? '';
    $replace = !empty($s['replace_dashboard']);

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di soli flag informativi per mostrare notice dopo un redirect (nessuna azione di stato eseguita qui).
    $saved = isset($_GET['saved']);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $disconnected = isset($_GET['disconnected']);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $oauth_ok = isset($_GET['oauth_success']);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $oauth_err = isset($_GET['oauth_error']) ? sanitize_text_field(wp_unslash($_GET['oauth_error'])) : '';

    $oauth_url = '';
    if ($configured && !$connected) {
        $oauth_url = add_query_arg([
            'client_id' => $s['client_id'],
            'redirect_uri' => rawurlencode(suite_barrio_stats_redirect_uri()),
            'response_type' => 'code',
            'scope' => SUITE_BARRIO_STATS_OAUTH_SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => wp_create_nonce('suite_barrio_stats_oauth_state'),
        ], SUITE_BARRIO_STATS_AUTH_URL);
    }
    ?>
    <div id="suite-barrio-stats-wrap" class="wrap suite-barrio-wrap">

        <!-- Header -->
        <div class="suite-barrio-st-header">
            <div class="suite-barrio-st-header-icon"><span class="dashicons dashicons-chart-area"></span></div>
            <div>
                <h1>Suite Barrio <span class="suite-barrio-st-breadcrumb">/ Statistiche</span></h1>
                <p class="suite-barrio-st-sub">Google Analytics 4 integrato nella bacheca WordPress</p>
            </div>
        </div>
        <hr class="suite-barrio-st-divider">

        <!-- Feedback notices -->
        <?php if ($saved): ?>
            <div class="suite-barrio-st-notice suite-barrio-st-notice-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <div>Impostazioni salvate.</div>
            </div>
        <?php endif; ?>
        <?php if ($disconnected): ?>
            <div class="suite-barrio-st-notice suite-barrio-st-notice-info">
                <span class="dashicons dashicons-info"></span>
                <div>Account Google disconnesso.</div>
            </div>
        <?php endif; ?>
        <?php if ($oauth_ok): ?>
            <div class="suite-barrio-st-notice suite-barrio-st-notice-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <div>&#x2713; Account Google collegato con successo.</div>
            </div>
        <?php endif; ?>
        <?php if ($oauth_err): ?>
            <div class="suite-barrio-st-notice suite-barrio-st-notice-error">
                <span class="dashicons dashicons-warning"></span>
                <div>Errore durante l'autorizzazione: <code><?php echo esc_html($oauth_err); ?></code>. Riprova.</div>
            </div>
        <?php endif; ?>

        <!-- Stato connessione -->
        <?php if ($connected): ?>
            <div class="suite-barrio-st-connected-card">
                <div class="suite-barrio-st-connected-left">
                    <span class="suite-barrio-st-connected-dot"></span>
                    <div>
                        <div class="suite-barrio-st-connected-title">&#x2713; Account Google collegato</div>
                        <div class="suite-barrio-st-connected-meta">
                            Property ID <strong><?php echo esc_html($property_id); ?></strong>
                            &nbsp;&middot;&nbsp;
                            <?php if ($replace): ?>
                                <span style="color:#059669;">&#x2713; Bacheca sostituita</span>
                            <?php else: ?>
                                <span style="color:#9ca3af;">Bacheca non sostituita</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('suite_barrio_stats_disconnect'); ?>
                    <input type="hidden" name="action" value="suite_barrio_stats_disconnect">
                    <button type="submit" class="suite-barrio-st-disconnect-btn">Disconnetti</button>
                </form>
            </div>
        <?php elseif ($configured): ?>
            <div class="suite-barrio-st-notice suite-barrio-st-notice-warning">
                <span class="dashicons dashicons-warning"></span>
                <div>
                    <strong>Credenziali salvate, ma account non ancora collegato.</strong><br>
                    Clicca «Connetti con Google» in fondo alla pagina per autorizzare l'accesso ai dati Analytics.
                </div>
            </div>
        <?php else: ?>
            <div class="suite-barrio-st-notice suite-barrio-st-notice-info">
                <span class="dashicons dashicons-info"></span>
                <div>Inserisci le credenziali OAuth per iniziare. Segui le istruzioni qui sotto.</div>
            </div>
        <?php endif; ?>

        <!-- Istruzioni -->
        <div class="suite-barrio-st-steps">
            <strong>Come creare le credenziali OAuth in Google Cloud:</strong>
            <ol>
                <li>Vai su <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud
                        Console</a> → seleziona il progetto.</li>
                <li>Attiva <strong>Google Analytics Data API</strong> dalla Libreria API.</li>
                <li>Vai su <strong>API e servizi → Credenziali → Crea credenziali → ID client OAuth 2.0</strong>.</li>
                <li>Tipo applicazione: <strong>Applicazione web</strong>. Nome: es. «Suite Barrio Stats».</li>
                <li>In <strong>URI di reindirizzamento autorizzati</strong> aggiungi esattamente:<br>
                    <code><?php echo esc_html(suite_barrio_stats_redirect_uri()); ?></code>
                </li>
                <li>Copia <strong>Client ID</strong> e <strong>Client Secret</strong> e incollali qui sotto.</li>
                <li>Copia il <strong>Property ID</strong> da GA4 → Impostazioni proprietà (solo il numero, es.
                    <code>123456789</code>).
                </li>
            </ol>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('suite_barrio_stats_save'); ?>
            <input type="hidden" name="action" value="suite_barrio_stats_save">

            <!-- Credenziali OAuth -->
            <div class="suite-barrio-st-section">
                <div class="suite-barrio-st-section-head">
                    <span class="dashicons dashicons-lock"></span>
                    <div class="suite-barrio-st-section-title">Credenziali OAuth 2.0</div>
                </div>
                <div class="suite-barrio-st-section-body">
                    <div class="suite-barrio-st-field">
                        <label for="suite-barrio-client-id">Client ID</label>
                        <input type="text" id="suite-barrio-client-id" name="client_id" value="<?php echo esc_attr($client_id); ?>"
                            placeholder="xxxxxxxxxxxx-xxxxxxxxxxxxxxxx.apps.googleusercontent.com">
                        <p class="suite-barrio-st-desc">Da Google Cloud → API e servizi → Credenziali → il tuo client OAuth.</p>
                    </div>
                    <div class="suite-barrio-st-field">
                        <label for="suite-barrio-client-secret">Client Secret</label>
                        <input type="password" id="suite-barrio-client-secret" name="client_secret"
                            value="<?php echo esc_attr($client_secret); ?>" placeholder="GOCSPX-…"
                            autocomplete="new-password">
                        <p class="suite-barrio-st-desc">Lascia vuoto per mantenere il valore già salvato.</p>
                    </div>
                    <div class="suite-barrio-st-field">
                        <label for="suite-barrio-property-id">Property ID GA4</label>
                        <input type="text" id="suite-barrio-property-id" name="property_id"
                            value="<?php echo esc_attr($property_id); ?>" placeholder="123456789" style="max-width:220px;">
                        <p class="suite-barrio-st-desc">Solo il numero — da GA4 → Impostazioni proprietà.</p>
                    </div>
                </div>
            </div>

            <!-- Bacheca -->
            <div class="suite-barrio-st-section">
                <div class="suite-barrio-st-section-head">
                    <span class="dashicons dashicons-dashboard"></span>
                    <div class="suite-barrio-st-section-title">Bacheca WordPress</div>
                </div>
                <div class="suite-barrio-st-section-body">
                    <div class="suite-barrio-st-field">
                        <div class="suite-barrio-st-toggle-row">
                            <label class="suite-barrio-toggle" for="suite-barrio-replace">
                                <input type="checkbox" id="suite-barrio-replace" name="replace_dashboard" value="1" <?php checked($replace); ?>>
                                <span class="suite-barrio-toggle-track"></span>
                            </label>
                            <label class="suite-barrio-st-toggle-label" for="suite-barrio-replace">
                                Sostituisci la bacheca WordPress con le statistiche GA4
                            </label>
                        </div>
                        <p class="suite-barrio-st-desc" style="margin-top:10px;padding-left:56px;">
                            Attivo solo dopo aver collegato l'account Google. I dati si aggiornano automaticamente ogni 30
                            minuti.
                        </p>
                    </div>
                </div>
            </div>

            <button type="submit" class="suite-barrio-st-save-btn">
                <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                Salva impostazioni
            </button>
        </form>

        <!-- Connetti con Google -->
        <?php if ($configured && !$connected): ?>
            <div class="suite-barrio-st-connect-wrap">
                <p class="suite-barrio-st-connect-title">Collega il tuo account Google per accedere ai dati Analytics:</p>
                <a href="<?php echo esc_url($oauth_url); ?>" class="suite-barrio-st-connect-btn">
                    <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.875 2.684-6.615z"
                            fill="#4285F4" />
                        <path
                            d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9 18z"
                            fill="#34A853" />
                        <path
                            d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z"
                            fill="#FBBC05" />
                        <path
                            d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z"
                            fill="#EA4335" />
                    </svg>
                    Connetti con Google
                </a>
            </div>
        <?php endif; ?>

    </div>
    <?php
}