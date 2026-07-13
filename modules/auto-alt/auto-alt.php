<?php
/**
 * Suite Barrio — Auto Alt
 * Genera automaticamente alt text e title delle immagini tramite AI.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   HELPER: scrivi una riga nel log
   ───────────────────────────────────────── */
function suite_barrio_log($attachment_id, $status, $alt = '', $title = '', $note = '')
{
    if (!get_option('wp_alt_ai_log_enabled', 1))
        return;
    $log = get_option(SUITE_BARRIO_LOG_OPTION, []);
    $entry = [
        'ts' => current_time('timestamp'),
        'id' => $attachment_id,
        'file' => basename(get_attached_file($attachment_id) ?: ''),
        'status' => $status,   // 'success' | 'error' | 'manual'
        'alt' => $alt,
        'title' => $title,
        'note' => $note,
    ];
    array_unshift($log, $entry);
    if (count($log) > SUITE_BARRIO_LOG_MAX) {
        $log = array_slice($log, 0, SUITE_BARRIO_LOG_MAX);
    }
    update_option(SUITE_BARRIO_LOG_OPTION, $log, false);
}

/* ─────────────────────────────────────────
   BACKGROUND JOB: processa un batch di immagini
   ───────────────────────────────────────── */
add_action(SUITE_BARRIO_CRON_HOOK, 'suite_barrio_process_batch');

function suite_barrio_get_job()
{
    return get_option(SUITE_BARRIO_JOB_OPTION, null);
}

function suite_barrio_save_job($job)
{
    update_option(SUITE_BARRIO_JOB_OPTION, $job, false);
}

function suite_barrio_clear_job()
{
    delete_option(SUITE_BARRIO_JOB_OPTION);
    wp_clear_scheduled_hook(SUITE_BARRIO_CRON_HOOK);
}

function suite_barrio_process_batch()
{
    global $wpdb;

    $job = suite_barrio_get_job();
    if (!$job || $job['status'] !== 'running')
        return;

    // Estrai atomicamente il prossimo batch dalla coda
    // e segna subito questi ID come "in lavorazione" per prevenire esecuzioni parallele
    $pending = $job['pending'] ?? [];
    if (empty($pending)) {
        $job['status'] = 'done';
        $job['completed'] = current_time('timestamp');
        suite_barrio_save_job($job);
        return;
    }

    $batch = array_splice($pending, 0, SUITE_BARRIO_BATCH_SIZE);
    $job['pending'] = $pending;
    // Salva subito con la coda ridotta — altri processi vedranno la coda già aggiornata
    suite_barrio_save_job($job);

    $batch_done = 0;
    $batch_errors = 0;

    $lang = get_option('wp_alt_ai_language', 'it');
    $lang_map = ['it' => 'italiano', 'en' => 'English', 'es' => 'español', 'fr' => 'français', 'de' => 'Deutsch'];
    $lang_name = $lang_map[$lang] ?? 'italiano';

    foreach ($batch as $id) {
        $id = intval($id);
        if (!$id)
            continue;

        $image_path = get_attached_file($id);
        if ($image_path && file_exists($image_path)) {
            $image_data = file_get_contents($image_path);
        } else {
            $image_url = wp_get_attachment_url($id);
            $image_data = $image_url ? @file_get_contents($image_url) : false;
        }

        if (!$image_data) {
            suite_barrio_log($id, 'error', '', '', 'Batch: impossibile leggere il file');
            $batch_errors++;
            continue;
        }

        $mime = get_post_mime_type($id) ?: 'image/jpeg';

        // Determina quali campi mancano per questo specifico allegato
        $existing_alt = get_post_meta($id, '_wp_attachment_image_alt', true);
        $existing_title = get_post($id)->post_title ?? '';
        $has_alt = !empty(trim($existing_alt));
        $has_title = !empty(trim($existing_title));

        if ($has_alt && $has_title) {
            // Niente da fare — non dovrebbe essere in coda, ma per sicurezza skippa
            $batch_done++;
            continue;
        } elseif ($has_alt && !$has_title) {
            $fields = 'title_only';
        } elseif (!$has_alt && $has_title) {
            $fields = 'alt_only';
        } else {
            $fields = 'both';
        }

        $result = suite_barrio_call_api($id, $image_data, $mime, $lang_name, $fields);

        if (isset($result['error'])) {
            suite_barrio_log($id, 'error', '', '', 'Batch: ' . $result['error']);
            $batch_errors++;
        } else {
            // Salva solo i campi che erano mancanti
            if (!$has_alt && !empty($result['alt'])) {
                update_post_meta($id, '_wp_attachment_image_alt', $result['alt']);
            }
            if (!$has_title && !empty($result['title'])) {
                wp_update_post(['ID' => $id, 'post_title' => $result['title']]);
            }
            if ((!$has_alt && !empty($result['alt'])) || (!$has_title && !empty($result['title']))) {
                if (function_exists('invalidate_vercel_cache')) {
                    $parent_id = wp_get_post_parent_id($id);
                    invalidate_vercel_cache($parent_id ?: 'wp:global');
                }
            }
            $provider = get_option('wp_alt_ai_provider', 'anthropic');
            $saved_fields = implode(' + ', array_filter([
                !$has_alt ? 'alt' : '',
                !$has_title ? 'title' : '',
            ]));
            suite_barrio_log(
                $id,
                'success',
                $result['alt'],
                $result['title'],
                'Batch background (' . $provider . ') — generato: ' . $saved_fields
            );
            $batch_done++;
        }

        usleep(300000); // 0.3s tra richieste API
    }

    // Rileggi il job e aggiorna i contatori con i soli valori di QUESTO batch
    // (altri processi paralleli potrebbero aver già scritto i propri contatori)
    $job = suite_barrio_get_job();
    if (!$job)
        return;

    $job['done'] = ($job['done'] ?? 0) + $batch_done;
    $job['errors'] = ($job['errors'] ?? 0) + $batch_errors;

    if (empty($job['pending'])) {
        $job['status'] = 'done';
        $job['completed'] = current_time('timestamp');
    } else {
        $job['status'] = 'running';
        // Rischedula solo se non è già schedulato
        if (!wp_next_scheduled(SUITE_BARRIO_CRON_HOOK)) {
            wp_schedule_single_event(time() + 3, SUITE_BARRIO_CRON_HOOK);
        }
    }

    suite_barrio_save_job($job);
}

/* ─────────────────────────────────────────
   HELPER: chiave API del provider attivo
   ───────────────────────────────────────── */
function suite_barrio_active_key()
{
    $provider = get_option('wp_alt_ai_provider', 'anthropic');
    if ($provider === 'gemini') {
        return get_option('wp_alt_ai_gemini_key', '');
    }
    return get_option('wp_alt_ai_api_key', '');
}

/* ─────────────────────────────────────────
   HELPER: IDs immagini senza alt OPPURE senza title
   ───────────────────────────────────────── */
function suite_barrio_get_incomplete_ids($limit = -1, $offset = 0)
{
    global $wpdb;

    // Un'immagine è "incompleta" se alt è vuoto/assente OPPURE post_title è vuoto
    $sql = "
        SELECT DISTINCT p.ID
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
        WHERE p.post_type   = 'attachment'
          AND p.post_status = 'inherit'
          AND p.post_mime_type LIKE 'image/%'
          AND (
              pm.meta_value IS NULL
              OR pm.meta_value = ''
              OR p.post_title  = ''
          )
        ORDER BY p.ID DESC
    ";

    if ($limit > 0) {
        $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $limit, $offset);
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- nomi tabella fissi ({$wpdb->posts}/{$wpdb->postmeta}), nessun input utente; query interna del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_col($sql);
}

function suite_barrio_count_incomplete()
{
    global $wpdb;
    $sql = "
        SELECT COUNT(DISTINCT p.ID)
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
        WHERE p.post_type   = 'attachment'
          AND p.post_status = 'inherit'
          AND p.post_mime_type LIKE 'image/%'
          AND (
              pm.meta_value IS NULL
              OR pm.meta_value = ''
              OR p.post_title  = ''
          )
    ";
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- nomi tabella fissi ({$wpdb->posts}/{$wpdb->postmeta}), nessun input utente; query interna del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return (int) $wpdb->get_var($sql);
}

/* ─────────────────────────────────────────
   HELPER: chiama l'API del provider scelto
   ───────────────────────────────────────── */
function suite_barrio_call_api($attachment_id, $image_data, $mime, $lang_name, $fields = 'both')
{
    $provider = get_option('wp_alt_ai_provider', 'anthropic');
    $model = get_option('wp_alt_ai_model', 'claude-opus-4-6') ?: 'claude-opus-4-6';

    $allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed_mime))
        $mime = 'image/jpeg';
    $b64 = base64_encode($image_data);

    // Costruzione prompt dinamica in base alle impostazioni
    $tone = get_option('wp_alt_ai_tone', 'neutral');
    $style = get_option('wp_alt_ai_style', 'descriptive');
    $context = get_option('wp_alt_ai_context', '');
    $keywords = get_option('wp_alt_ai_keywords', '');

    $tone_map = [
        'neutral' => 'neutro e oggettivo',
        'professional' => 'professionale e formale',
        'friendly' => 'amichevole e accessibile',
        'technical' => 'tecnico e preciso',
        'creative' => 'creativo e coinvolgente',
        'seo' => 'ottimizzato SEO, ricco di parole chiave rilevanti',
    ];
    $style_map = [
        'descriptive' => 'descrittivo — elenca gli elementi visivi principali',
        'contextual' => 'contestuale — interpreta il significato e lo scopo dell\'immagine',
        'emotional' => 'evocativo — trasmette sensazioni e atmosfera',
        'journalistic' => 'giornalistico — sintetico e informativo come una didascalia',
    ];

    $tone_str = $tone_map[$tone] ?? 'neutro e oggettivo';
    $style_str = $style_map[$style] ?? 'descrittivo';

    // Determina quali campi generare in base a cosa manca
    $need_alt = in_array($fields, ['both', 'alt_only']);
    $need_title = in_array($fields, ['both', 'title_only']);

    if ($need_alt && $need_title) {
        $json_format = '{"alt":"...","title":"..."}';
    } elseif ($need_alt) {
        $json_format = '{"alt":"...","title":""}';
    } else {
        $json_format = '{"alt":"","title":"..."}';
    }

    $prompt = 'Rispondi ESCLUSIVAMENTE con un oggetto JSON valido, senza backtick, senza markdown, senza testo aggiuntivo prima o dopo. ';
    $prompt .= 'Formato esatto: ' . $json_format . ' ';

    if ($need_alt) {
        $prompt .= 'alt: testo alternativo accessibile, max 125 caratteri, in ' . $lang_name . '. ';
        $prompt .= 'Registro linguistico: ' . $tone_str . '. ';
        $prompt .= 'Stile: ' . $style_str . '. ';
        $prompt .= 'Non iniziare con "immagine di", "foto di" o simili. ';
    } else {
        $prompt .= 'alt: lascia il campo vuoto, stringa vuota. ';
    }

    if ($need_title) {
        $prompt .= 'title: titolo sintetico, max 60 caratteri, in ' . $lang_name . '. ';
    } else {
        $prompt .= 'title: lascia il campo vuoto, stringa vuota. ';
    }

    if (!empty($context)) {
        $prompt .= 'Contesto del sito: ' . $context . '. ';
    }
    if (!empty($keywords) && $need_alt) {
        $prompt .= 'Parole chiave da includere se pertinenti: ' . $keywords . '. ';
    }

    $raw = '';

    if ($provider === 'gemini') {
        $api_key = get_option('wp_alt_ai_gemini_key', '');
        if (empty($api_key))
            return ['error' => 'Chiave API Gemini non configurata'];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . $api_key;
        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'contents' => [
                    [
                        'parts' => [
                            ['inline_data' => ['mime_type' => $mime, 'data' => $b64]],
                            ['text' => $prompt],
                        ],
                    ]
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 1024,
                    'temperature' => 0.1,
                ],
            ]),
        ]);

        if (is_wp_error($response))
            return ['error' => 'Connessione fallita: ' . $response->get_error_message()];
        $http = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        if ($http !== 200) {
            $d = json_decode($body_raw, true);
            $msg = $d['error']['message'] ?? $body_raw;
            return ['error' => 'Gemini HTTP ' . $http . ': ' . $msg];
        }
        $body = json_decode($body_raw, true);
        $raw = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($raw === '') {
            $finish = $body['candidates'][0]['finishReason'] ?? 'UNKNOWN';
            return ['error' => 'Gemini risposta vuota (finishReason: ' . $finish . '). Body: ' . substr($body_raw, 0, 300)];
        }

    } else {
        // Anthropic (default)
        $api_key = get_option('wp_alt_ai_api_key', '');
        if (empty($api_key))
            return ['error' => 'Chiave API Anthropic non configurata'];

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'max_tokens' => 512,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]],
                            ['type' => 'text', 'text' => $prompt],
                        ],
                    ]
                ],
            ]),
        ]);

        if (is_wp_error($response))
            return ['error' => 'Connessione fallita: ' . $response->get_error_message()];
        $http = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        if ($http !== 200) {
            $d = json_decode($body_raw, true);
            $msg = $d['error']['message'] ?? $body_raw;
            return ['error' => 'Anthropic HTTP ' . $http . ': ' . $msg];
        }
        $body = json_decode($body_raw, true);
        $raw = $body['content'][0]['text'] ?? '';
        if ($raw === '') {
            return ['error' => 'Anthropic risposta vuota. Body: ' . substr($body_raw, 0, 300)];
        }
    }

    // ── Parsing robusto ─────────────────────────────────────────────────────
    // 1. Rimuovi eventuali backtick markdown
    $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
    $clean = preg_replace('/\s*```$/', '', $clean);
    $clean = trim($clean);

    // 2. Parsing diretto
    $result = json_decode($clean, true);

    // 3. Cerca { ... } anche con chiusura parziale/mancante
    $needs_alt = in_array($fields, ['both', 'alt_only']);
    $needs_title = in_array($fields, ['both', 'title_only']);

    $result_invalid = !is_array($result)
        || ($needs_alt && empty($result['alt']))
        || ($needs_title && !isset($result['title']));

    if ($result_invalid) {
        if (preg_match('/\{(.+)/s', $clean, $m)) {
            $fragment = '{' . $m[1];
            $open = substr_count($fragment, '{');
            $close = substr_count($fragment, '}');
            $fragment .= str_repeat('}', max(0, $open - $close));
            $result = json_decode($fragment, true);
        }
    }

    // 4. Fallback con regex su singoli valori, anche se JSON è troncato
    $result_invalid = !is_array($result)
        || ($needs_alt && empty($result['alt']))
        || ($needs_title && !isset($result['title']));

    if ($result_invalid) {
        $alt_val = '';
        $title_val = '';
        if (preg_match('/"alt"\s*:\s*"((?:[^"\\]|\\.)*)"\/s', $raw, $ma))
            $alt_val = $ma[1];
        if (preg_match('/"title"\s*:\s*"((?:[^"\\]|\\.)*)"\/s', $raw, $mt))
            $title_val = $mt[1];
        if ($needs_alt && empty($alt_val) && preg_match('/"alt"\s*:\s*"([^"]{3,})/s', $raw, $ma)) {
            $alt_val = $ma[1];
        }
        if ($needs_title && empty($title_val) && preg_match('/"title"\s*:\s*"([^"]{3,})/s', $raw, $mt)) {
            $title_val = $mt[1];
        }
        if (($needs_alt && !empty($alt_val)) || ($needs_title && !empty($title_val))) {
            $result = ['alt' => $alt_val, 'title' => $title_val];
        }
    }

    // Validazione finale: almeno il campo richiesto deve essere presente
    $valid = is_array($result)
        && (!$needs_alt || !empty($result['alt']))
        && (!$needs_title || isset($result['title']));

    if (!$valid) {
        return ['error' => 'JSON non valido da ' . $provider . '. Risposta: ' . substr($raw, 0, 300)];
    }
    return [
        'alt' => sanitize_text_field($result['alt']),
        'title' => sanitize_text_field($result['title'] ?? ''),
    ];
}


/* ─────────────────────────────────────────
   HELPER: recupera modelli dal provider con cache 24h
   ───────────────────────────────────────── */
function suite_barrio_fetch_models($provider)
{
    $cache_key = 'wp_alt_ai_models_' . $provider;
    $cached = get_transient($cache_key);
    if ($cached !== false)
        return $cached;

    $models = [];

    if ($provider === 'anthropic') {
        $api_key = get_option('wp_alt_ai_api_key', '');
        if (empty($api_key))
            return null;

        $response = wp_remote_get('https://api.anthropic.com/v1/models', [
            'timeout' => 15,
            'headers' => [
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200)
            return null;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        // Filtra solo modelli vision (claude-*) escludendo embedding/moderation
        foreach ($data['data'] ?? [] as $m) {
            $id = $m['id'] ?? '';
            // Includi solo claude- con capacità vision (escludi haiku-3 e versioni molto vecchie)
            if (strpos($id, 'claude-') === 0 && !preg_match('/claude-(instant|2\.|3-haiku-2|3-sonnet-2|3-opus-2)/i', $id)) {
                $models[$id] = [
                    'name' => $m['display_name'] ?? $id,
                    'desc' => '',
                ];
            }
        }

    } elseif ($provider === 'gemini') {
        $api_key = get_option('wp_alt_ai_gemini_key', '');
        if (empty($api_key))
            return null;

        $response = wp_remote_get(
            'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key . '&pageSize=100',
            ['timeout' => 15]
        );
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200)
            return null;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $exclude = ['embedding', 'tts', 'imagen', 'veo', 'aqa', 'music', 'robotics', 'audio', 'live', 'image'];
        foreach ($data['models'] ?? [] as $m) {
            $id = str_replace('models/', '', $m['name'] ?? '');
            $input_types = $m['supportedGenerationMethods'] ?? [];
            // Deve supportare generateContent e deve avere input image (vision)
            if (!in_array('generateContent', $input_types))
                continue;
            // Escludi modelli non-vision per nome
            $skip = false;
            foreach ($exclude as $ex) {
                if (strpos($id, $ex) !== false) {
                    $skip = true;
                    break;
                }
            }
            // Includi solo gemini-2.x e gemini-3.x (1.5 deprecato)
            if (!preg_match('/^gemini-(2|3)/', $id))
                $skip = true;
            // Escludi varianti sperimentali e preview-datate
            if (preg_match('/exp|computer-use|pro-exp/', $id))
                $skip = true;
            if ($skip)
                continue;
            $models[$id] = [
                'name' => $m['displayName'] ?? $id,
                'desc' => '',
            ];
        }
    }

    if (empty($models))
        return null;

    // Ordina alfabeticamente per nome
    uksort($models, function ($a, $b) {
        return strcmp($a, $b);
    });

    set_transient($cache_key, $models, DAY_IN_SECONDS);
    return $models;
}

/* ─────────────────────────────────────────
   AJAX: restituisce modelli live al browser
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_fetch_models', function () {
    check_ajax_referer('suite_barrio_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $provider = sanitize_key($_POST['provider'] ?? 'anthropic');
    $force = !empty($_POST['force']); // force = ignora cache
    if ($force)
        delete_transient('wp_alt_ai_models_' . $provider);

    $models = suite_barrio_fetch_models($provider);
    if ($models === null) {
        wp_send_json_error('Impossibile recuperare i modelli. Verifica la chiave API.');
    }
    wp_send_json_success($models);
});

/* ─────────────────────────────────────────
   1. MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    // Auto Alt
    add_submenu_page(
        'suite-barrio',
        'Auto Alt — Suite Barrio',
        'Auto Alt',
        'manage_options',
        'suite-barrio-auto-alt',
        'suite_barrio_admin_page'
    );
});

/* ─────────────────────────────────────────
   2. IMPOSTAZIONI
   ───────────────────────────────────────── */
add_action('admin_init', function () {
    register_setting('suite_barrio_settings', 'wp_alt_ai_provider', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('suite_barrio_settings', 'wp_alt_ai_api_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('suite_barrio_settings', 'wp_alt_ai_gemini_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('suite_barrio_settings', 'wp_alt_ai_language', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('suite_barrio_settings', 'wp_alt_ai_auto_upload', ['sanitize_callback' => 'absint']);
    register_setting('suite_barrio_settings', 'wp_alt_ai_model', ['sanitize_callback' => 'sanitize_text_field']);
    // Impostazioni prompt
    register_setting('suite_barrio_settings', 'wp_alt_ai_tone', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'neutral']);
    register_setting('suite_barrio_settings', 'wp_alt_ai_style', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'descriptive']);
    register_setting('suite_barrio_settings', 'wp_alt_ai_context', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
    register_setting('suite_barrio_settings', 'wp_alt_ai_keywords', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
    register_setting('suite_barrio_settings', 'wp_alt_ai_log_enabled', ['sanitize_callback' => 'absint', 'default' => 1]);
});

/* ─────────────────────────────────────────
   3. HOOK AUTO SU UPLOAD (condizionale)
   ───────────────────────────────────────── */
add_action('add_attachment', function ($attachment_id) {

    if (!get_option('wp_alt_ai_auto_upload', 0))
        return;
    if (strpos(get_post_mime_type($attachment_id), 'image/') === false)
        return;

    $api_key = suite_barrio_active_key();
    if (empty($api_key)) {
        $provider = get_option('wp_alt_ai_provider', 'anthropic');
        suite_barrio_log($attachment_id, 'error', '', '', 'Chiave API non configurata per il provider: ' . $provider);
        return;
    }

    $image_path = get_attached_file($attachment_id);
    if ($image_path && file_exists($image_path)) {
        $image_data = file_get_contents($image_path);
    } else {
        $image_url = wp_get_attachment_url($attachment_id);
        $image_data = $image_url ? @file_get_contents($image_url) : false;
    }

    if (!$image_data) {
        suite_barrio_log($attachment_id, 'error', '', '', 'Impossibile leggere il file immagine');
        return;
    }

    $mime = get_post_mime_type($attachment_id) ?: 'image/jpeg';
    $lang = get_option('wp_alt_ai_language', 'it');
    $lang_map = ['it' => 'italiano', 'en' => 'English', 'es' => 'español', 'fr' => 'français', 'de' => 'Deutsch'];
    $lang_name = $lang_map[$lang] ?? 'italiano';

    $result = suite_barrio_call_api($attachment_id, $image_data, $mime, $lang_name);

    if (isset($result['error'])) {
        suite_barrio_log($attachment_id, 'error', '', '', $result['error']);
        return;
    }

    update_post_meta($attachment_id, '_wp_attachment_image_alt', $result['alt']);
    wp_update_post(['ID' => $attachment_id, 'post_title' => $result['title']]);

    if (function_exists('invalidate_vercel_cache')) {
        $parent_id = wp_get_post_parent_id($attachment_id);
        invalidate_vercel_cache($parent_id ?: 'wp:global');
    }

    suite_barrio_log($attachment_id, 'success', $result['alt'], $result['title'], 'Auto-generato su upload');
});

/* ─────────────────────────────────────────
   4. ADMIN NOTICE + STILI
   ───────────────────────────────────────── */

// Notice persistente su tutte le pagine admin quando il job è attivo
add_action('admin_notices', function () {
    if (!current_user_can('manage_options'))
        return;
    $job = suite_barrio_get_job();
    if (!$job)
        return;

    $total = $job['total'] ?? 0;
    $done = $job['done'] ?? 0;
    $errors = $job['errors'] ?? 0;
    $pending = count($job['pending'] ?? []);
    $status = $job['status'] ?? 'running';

    if ($status === 'done') {
        $dismiss_nonce = wp_create_nonce('suite_barrio_nonce');
        $msg = sprintf(
            '<strong>Suite Barrio — Auto Alt</strong>: elaborazione completata. %d salvate%s. <a href="%s">Vai al Log</a> | <a href="#" class="suite-barrio-dismiss-job" data-nonce="%s">Chiudi</a>',
            $done,
            $errors > 0 ? ', ' . $errors . ' errori' : '',
            esc_url(admin_url('admin.php?page=suite-barrio-auto-alt&tab=log')),
            esc_attr($dismiss_nonce)
        );
        echo '<div class="notice notice-success suite-barrio-job-notice"><p>' . wp_kses_post($msg) . '</p></div>';
        // Il JS di dismiss viene registrato/accodato via admin_enqueue_scripts
        // (vedi handle 'suite-barrio-auto-alt-job-notice' più sotto).
    } elseif ($status === 'running') {
        $processed = $done + $errors;
        $pct = $total > 0 ? min(100, round(($processed / $total) * 100)) : 0;
        $msg = sprintf(
            '<strong>Suite Barrio — Auto Alt</strong>: elaborazione in corso — %d / %d immagini (%d%%). Puoi continuare a lavorare. <a href="%s">Vedi progresso</a>',
            min($processed, $total),
            $total,
            $pct,
            esc_url(admin_url('admin.php?page=suite-barrio-auto-alt&tab=review'))
        );
        echo '<div class="notice notice-info suite-barrio-job-notice"><p>' . wp_kses_post($msg) . '</p></div>';
    }
});

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'suite-barrio-auto-alt') !== false) {
        wp_enqueue_media();

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'review';

        // JS della tab "Impostazioni" (selezione provider/modello AI)
        if ($tab === 'settings') {
            wp_register_script('suite-barrio-auto-alt-settings', '', [], SUITE_BARRIO_VERSION, true);
            wp_enqueue_script('suite-barrio-auto-alt-settings');

            $settings_js = '(function () {
    var ajaxUrl = %%AJAX_URL%%;
    var nonce = %%NONCE%%;
    var currentModel = %%CURRENT_MODEL%%;
    var modelDefaults = { anthropic: \'claude-opus-4-6\', gemini: \'gemini-2.5-flash\' };

    function updateModelHighlight() {
        document.querySelectorAll(\'.suite-barrio-model-label\').forEach(function (lbl) {
            var r = lbl.querySelector(\'input[type=radio]\');
            lbl.classList.toggle(\'selected\', r && r.checked && !r.disabled);
        });
    }

    function renderModels(panel, models, selectedId) {
        var group = panel.querySelector(\'.suite-barrio-model-group\');
        if (!group) return;
        var prov = panel.id.replace(\'keys-\', \'\');
        var chosen = selectedId || modelDefaults[prov] || Object.keys(models)[0];
        group.innerHTML = \'\';
        Object.keys(models).forEach(function (id) {
            var m = models[id];
            var chk = (id === chosen);
            var lbl = document.createElement(\'label\');
            lbl.className = \'suite-barrio-model-label\' + (chk ? \' selected\' : \'\');
            lbl.innerHTML =
                \'<input type="radio" name="wp_alt_ai_model" value="\' + id + \'"\' +
                (chk ? \' checked\' : \'\') + \' style="margin-top:2px;accent-color:#111827;flex-shrink:0;">\' +
                \'<div><div class="suite-barrio-model-name">\' + (m.name || id) + \'</div>\' +
                (m.desc ? \'<div class="suite-barrio-model-desc">\' + m.desc + \'</div>\' : \'\') +
                \'<div class="suite-barrio-model-id">\' + id + \'</div></div>\';
            lbl.querySelector(\'input\').addEventListener(\'change\', updateModelHighlight);
            group.appendChild(lbl);
        });
        updateModelHighlight();
    }

    function loadModels(provider, panel, force) {
        var statusEl = panel.querySelector(\'.suite-barrio-model-status\');
        if (statusEl) statusEl.textContent = \'Caricamento modelli…\';
        var body = new URLSearchParams({ action: \'suite_barrio_fetch_models\', nonce: nonce, provider: provider, force: force ? \'1\' : \'\' });
        fetch(ajaxUrl, { method: \'POST\', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (statusEl) { statusEl.textContent = \'\'; statusEl.className = \'suite-barrio-model-status\'; }
                if (data.success && data.data && Object.keys(data.data).length > 0) {
                    var sel = (currentModel && data.data[currentModel]) ? currentModel : null;
                    renderModels(panel, data.data, sel);
                    if (statusEl) { statusEl.textContent = \'&#x2713; \' + Object.keys(data.data).length + \' modelli\'; statusEl.className = \'suite-barrio-model-status ok\'; }
                } else {
                    if (statusEl) { statusEl.textContent = data.data || \'Errore caricamento\'; statusEl.className = \'suite-barrio-model-status err\'; }
                }
            })
            .catch(function () { if (statusEl) { statusEl.textContent = \'Errore di rete\'; statusEl.className = \'suite-barrio-model-status err\'; } });
    }

    document.querySelectorAll(\'.suite-barrio-provider-keys\').forEach(function (panel) {
        panel.querySelector(\'.btn-refresh-models\').addEventListener(\'click\', function () {
            currentModel = \'\';
            loadModels(panel.id.replace(\'keys-\', \'\'), panel, true);
        });
    });

    document.querySelectorAll(\'input[name="wp_alt_ai_provider"]\').forEach(function (radio) {
        radio.addEventListener(\'change\', function () {
            var sel = this.value;
            document.querySelectorAll(\'.suite-barrio-provider-card\').forEach(function (c) {
                c.classList.toggle(\'selected\', c.dataset.provider === sel);
            });
            document.querySelectorAll(\'.suite-barrio-provider-keys\').forEach(function (panel) {
                var prov = panel.id.replace(\'keys-\', \'\');
                var active = (prov === sel);
                panel.classList.toggle(\'visible\', active);
                panel.querySelectorAll(\'input[type=radio][name="wp_alt_ai_model"]\').forEach(function (r) { r.disabled = !active; });
                if (active) { currentModel = \'\'; loadModels(prov, panel, false); }
            });
        });
    });

    updateModelHighlight();
    document.querySelectorAll(\'.suite-barrio-provider-keys.visible\').forEach(function (panel) {
        loadModels(panel.id.replace(\'keys-\', \'\'), panel, false);
    });
})();';

            $settings_js = strtr($settings_js, [
                '%%AJAX_URL%%' => wp_json_encode(admin_url('admin-ajax.php')),
                '%%NONCE%%' => wp_json_encode(wp_create_nonce('suite_barrio_nonce')),
                '%%CURRENT_MODEL%%' => wp_json_encode(get_option('wp_alt_ai_model', '')),
            ]);

            wp_add_inline_script('suite-barrio-auto-alt-settings', $settings_js);
        }

        // JS sempre presente sulla pagina (toast, generazione singola, polling job, filtro log)
        wp_register_script('suite-barrio-auto-alt-page', '', [], SUITE_BARRIO_VERSION, true);
        wp_enqueue_script('suite-barrio-auto-alt-page');

        $page_job = suite_barrio_get_job();
        $page_poll_snippet = ($page_job && $page_job['status'] === 'running') ? 'startPolling();' : '';
        $page_dismiss_snippet = ($page_job && $page_job['status'] === 'done')
            ? "setTimeout(function () {\n                    fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'suite_barrio_dismiss_job', nonce: apiNonce }) }).then(() => location.reload());\n                }, 4000);"
            : '';

        $page_js = '(function () {
    const apiNonce = %%API_NONCE%%;
    const ajaxUrl = %%AJAX_URL%%;

    function toast(msg, type = \'success\') {
        const t = document.getElementById(\'suite-barrio-toast\');
        t.textContent = msg;
        t.className = \'show\' + (type === \'error\' ? \' error\' : \'\');
        setTimeout(() => t.className = \'\', 3400);
    }

    /* ── Genera e salva singola card ── */
    async function generateForCard(card) {
        const btn = card.querySelector(\'.suite-barrio-img-btn-gen\');
        btn.disabled = true;
        btn.innerHTML = \'<div class="suite-barrio-spinner"></div> Elaborazione…\';

        try {
            const res = await fetch(ajaxUrl, { method: \'POST\', body: new URLSearchParams({ action: \'suite_barrio_generate\', nonce: apiNonce, attachment_id: card.dataset.id, save: \'1\' }) });
            const data = await res.json();
            if (!data.success) throw new Error(data.data || \'Errore sconosciuto\');

            const altIn = card.querySelector(\'.alt-input\');
            const titIn = card.querySelector(\'.title-input\');
            if (altIn) altIn.value = data.data.alt || \'\';
            if (titIn) titIn.value = data.data.title || \'\';
            card.classList.add(\'ai-generated\', \'is-saved\');
            setTimeout(() => { card.style.transition = \'opacity .5s, transform .5s\'; card.style.opacity = \'0\'; card.style.transform = \'scale(.96)\'; setTimeout(() => card.remove(), 500); }, 700);
        } catch (e) {
            toast(\'Errore: \' + e.message, \'error\');
            btn.innerHTML = \'✦ Genera e salva\';
            btn.disabled = false;
        }
    }

    document.querySelectorAll(\'.suite-barrio-img-btn-gen\').forEach(b => b.addEventListener(\'click\', () => generateForCard(b.closest(\'.suite-barrio-img-card\'))));

    /* ── Polling job bulk ── */
    let pollInterval = null;
    const startPolling = () => { if (!pollInterval) pollInterval = setInterval(pollJobStatus, 4000); };
    const stopPolling = () => { if (pollInterval) { clearInterval(pollInterval); pollInterval = null; } };

    async function pollJobStatus() {
        try {
            const res = await fetch(ajaxUrl, { method: \'POST\', body: new URLSearchParams({ action: \'suite_barrio_job_status\', nonce: apiNonce }) });
            const data = await res.json();
            if (!data.success) { stopPolling(); return; }
            const job = data.data;
            const wrap = document.getElementById(\'bulk-progress-wrap\');
            const bar = document.getElementById(\'bulk-progress-bar\');
            const txt = document.getElementById(\'bulk-progress-text\');
            const stxt = document.getElementById(\'bulk-status-text\');
            if (!wrap) { stopPolling(); return; }

            const processed = (job.done || 0) + (job.errors || 0);
            const total = job.total || 0;
            const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
            wrap.style.display = \'block\';
            bar.style.width = pct + \'%\';

            if (job.status === \'running\') {
                if (stxt) stxt.textContent = \'Elaborazione in corso: \' + processed + \' / \' + total + \' immagini\';
                txt.textContent = processed + \' / \' + total + \' elaborate — \' + (job.done || 0) + \' ✓\' + (job.errors > 0 ? \'  \' + job.errors + \' ✗\' : \'\');
            } else if (job.status === \'done\') {
                bar.style.width = \'100%\';
                bar.style.background = job.errors > 0 ? \'#dc2626\' : \'#059669\';
                txt.textContent = \'✓ Completato — \' + (job.done || 0) + \' salvate\' + (job.errors > 0 ? \', \' + job.errors + \' errori (vedi Log)\' : \'\');
                if (stxt) stxt.textContent = \'Elaborazione completata\';
                stopPolling();
                setTimeout(async () => {
                    await fetch(ajaxUrl, { method: \'POST\', body: new URLSearchParams({ action: \'suite_barrio_dismiss_job\', nonce: apiNonce }) });
                    location.reload();
                }, 2800);
            } else { stopPolling(); }
        } catch (_) { }
    }

    %%POLL_SNIPPET%%
    %%DISMISS_SNIPPET%%

    const bulkGenBtn = document.getElementById(\'bulk-generate-btn\');
    if (bulkGenBtn) {
        bulkGenBtn.addEventListener(\'click\', async () => {
            bulkGenBtn.disabled = true;
            bulkGenBtn.innerHTML = \'<div class="suite-barrio-spinner" style="border-top-color:#111827;border-color:rgba(0,0,0,.15);display:inline-block;margin-right:6px;width:12px;height:12px;vertical-align:middle;"></div> Avvio…\';
            try {
                const res = await fetch(ajaxUrl, { method: \'POST\', body: new URLSearchParams({ action: \'suite_barrio_bulk_start\', nonce: apiNonce }) });
                const data = await res.json();
                if (!data.success) throw new Error(data.data || \'Errore avvio job\');
                toast(\'✓ Elaborazione avviata in background\');
                setTimeout(() => location.reload(), 800);
            } catch (err) {
                toast(\'Errore: \' + err.message, \'error\');
                bulkGenBtn.disabled = false;
                bulkGenBtn.innerHTML = \'✦ Genera tutte in background\';
            }
        });
    }

    const bulkStopBtn = document.getElementById(\'bulk-stop-btn\');
    if (bulkStopBtn) {
        bulkStopBtn.addEventListener(\'click\', async () => {
            if (!confirm(\'Interrompere l\\\'elaborazione in corso?\')) return;
            bulkStopBtn.disabled = true;
            await fetch(ajaxUrl, { method: \'POST\', body: new URLSearchParams({ action: \'suite_barrio_bulk_stop\', nonce: apiNonce }) });
            stopPolling();
            location.reload();
        });
    }

    /* ── Filtro log ── */
    document.querySelectorAll(\'.suite-barrio-chip\').forEach(btn => {
        btn.addEventListener(\'click\', () => {
            document.querySelectorAll(\'.suite-barrio-chip\').forEach(b => b.classList.remove(\'active\'));
            btn.classList.add(\'active\');
            const filter = btn.dataset.filter;
            document.querySelectorAll(\'#log-table tbody tr\').forEach(row => {
                row.style.display = (filter === \'all\' || row.dataset.status === filter) ? \'\' : \'none\';
            });
        });
    });
})();';

        $page_js = strtr($page_js, [
            '%%API_NONCE%%' => wp_json_encode(wp_create_nonce('suite_barrio_nonce')),
            '%%AJAX_URL%%' => wp_json_encode(admin_url('admin-ajax.php')),
            '%%POLL_SNIPPET%%' => $page_poll_snippet,
            '%%DISMISS_SNIPPET%%' => $page_dismiss_snippet,
        ]);

        wp_add_inline_script('suite-barrio-auto-alt-page', $page_js);
    }

    // Notifica "job completato" (può comparire su qualsiasi pagina admin)
    if (!current_user_can('manage_options')) {
        return;
    }
    $notice_job = suite_barrio_get_job();
    if ($notice_job && ($notice_job['status'] ?? 'running') === 'done') {
        wp_register_script('suite-barrio-auto-alt-job-notice', '', [], SUITE_BARRIO_VERSION, true);
        wp_enqueue_script('suite-barrio-auto-alt-job-notice');

        $notice_js = '(function () {
    var ajaxUrl = %%AJAX_URL%%;
    document.querySelectorAll(\'.suite-barrio-dismiss-job\').forEach(function (link) {
        if (link.dataset.bound === \'1\') return;
        link.dataset.bound = \'1\';
        link.addEventListener(\'click\', function (e) {
            e.preventDefault();
            var notice = link.closest(\'.notice\');
            if (notice) notice.style.opacity = \'.5\';
            fetch(ajaxUrl, {
                method: \'POST\',
                credentials: \'same-origin\',
                body: new URLSearchParams({
                    action: \'suite_barrio_dismiss_job\',
                    nonce: link.dataset.nonce
                })
            }).then(function () {
                if (notice && notice.parentNode) notice.parentNode.removeChild(notice);
            }).catch(function () {
                if (notice) notice.style.opacity = \'1\';
            });
        });
    });
})();';

        $notice_js = strtr($notice_js, [
            '%%AJAX_URL%%' => wp_json_encode(admin_url('admin-ajax.php')),
        ]);

        wp_add_inline_script('suite-barrio-auto-alt-job-notice', $notice_js);
    }
});

/* ─────────────────────────────────────────
   5. PAGINA ADMIN
   ───────────────────────────────────────── */
function suite_barrio_admin_page()
{
    $api_key = suite_barrio_active_key();
    $auto_upload = get_option('wp_alt_ai_auto_upload', 0);
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'review';
    $per_page = 12;
    $paged = max(1, intval($_GET['paged'] ?? 1));
    $offset = ($paged - 1) * $per_page;

    $total_items = suite_barrio_count_incomplete();
    $total_pages = $per_page > 0 ? (int) ceil($total_items / $per_page) : 1;
    $att_ids = suite_barrio_get_incomplete_ids($per_page, $offset);
    $attachments = $att_ids ? get_posts([
        'post_type' => 'attachment',
        'post__in' => $att_ids,
        'posts_per_page' => count($att_ids),
        'orderby' => 'post__in',
    ]) : [];
    $total_images = array_sum((array) wp_count_attachments());

    $log_entries = get_option(SUITE_BARRIO_LOG_OPTION, []);
    $log_page = max(1, intval($_GET['log_page'] ?? 1));
    $log_per_page = 25;
    $log_total = count($log_entries);
    $log_total_pages = max(1, ceil($log_total / $log_per_page));
    $log_slice = array_slice($log_entries, ($log_page - 1) * $log_per_page, $log_per_page);
    $log_success = count(array_filter($log_entries, fn($e) => $e['status'] === 'success'));
    $log_errors = count(array_filter($log_entries, fn($e) => $e['status'] === 'error'));
    ?>
    <div id="suite-barrio-autoalt-wrap" class="wrap suite-barrio-wrap">

        <!-- Header -->
        <div class="suite-barrio-header">
            <div class="suite-barrio-header-icon"><span class="dashicons dashicons-format-image"></span></div>
            <div>
                <h1>Suite Barrio <span class="suite-barrio-header-breadcrumb">/ Auto Alt</span><?php if ($auto_upload): ?>
                        <span class="suite-barrio-badge-on">&#x2713; Auto ON</span><?php endif; ?>
                </h1>
                <p class="suite-barrio-header-sub">Generazione automatica alt text &amp; title delle immagini</p>
            </div>
        </div>
        <hr class="suite-barrio-divider">

        <!-- KPI bar -->
        <div class="suite-barrio-kpi-bar">
            <div class="suite-barrio-kpi-item">
                <div class="suite-barrio-kpi-num"><?php echo number_format($total_images); ?></div>
                <div class="suite-barrio-kpi-lbl">Totale immagini</div>
            </div>
            <div class="suite-barrio-kpi-item">
                <div class="suite-barrio-kpi-num" style="color:#dc2626;"><?php echo number_format($total_items); ?></div>
                <div class="suite-barrio-kpi-lbl">Da completare</div>
            </div>
            <div class="suite-barrio-kpi-item">
                <div class="suite-barrio-kpi-num" style="color:#059669;">
                    <?php echo number_format(max(0, $total_images - $total_items)); ?>
                </div>
                <div class="suite-barrio-kpi-lbl">Completate</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="suite-barrio-tabs">
            <a href="?page=suite-barrio-auto-alt&tab=review"
                class="suite-barrio-tab <?php echo $tab === 'review' ? 'active' : ''; ?>"><span
                    class="dashicons dashicons-visibility"></span> Revisione</a>
            <a href="?page=suite-barrio-auto-alt&tab=log" class="suite-barrio-tab <?php echo $tab === 'log' ? 'active' : ''; ?>"><span
                    class="dashicons dashicons-list-view"></span> Log</a>
            <a href="?page=suite-barrio-auto-alt&tab=settings"
                class="suite-barrio-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>"><span
                    class="dashicons dashicons-admin-settings"></span> Impostazioni</a>
        </div>

        <?php

        /* ════════════════════════════════════════
           TAB: REVISIONE
           ════════════════════════════════════════ */
        if ($tab === 'review'):

            if (empty($api_key)): ?>
                <div class="suite-barrio-notice suite-barrio-notice-warning">
                    <span class="dashicons dashicons-warning"></span>
                    <div>Chiave API non configurata. <a href="?page=suite-barrio-auto-alt&tab=settings">Vai alle impostazioni
                            &rarr;</a></div>
                </div>
            <?php endif;

            $job = suite_barrio_get_job();
            $job_running = $job && $job['status'] === 'running';
            $job_done = $job && $job['status'] === 'done';
            ?>

            <?php if (!empty($attachments) || $job_running): ?>
                <div class="suite-barrio-bulk">
                    <?php if ($job_running): ?>
                        <strong id="bulk-status-text">
                            Elaborazione in corso: <?php echo esc_html($job['done'] + $job['errors']); ?> / <?php echo esc_html($job['total']); ?>
                            immagini
                        </strong>
                        <button class="suite-barrio-btn suite-barrio-btn-danger" id="bulk-stop-btn">&#x2715; Interrompi</button>
                    <?php else: ?>
                        <strong><?php echo esc_html($total_items); ?> immagini senza alt o title</strong>
                        <button class="suite-barrio-btn suite-barrio-btn-ghost" id="bulk-generate-btn" <?php echo empty($api_key) ? 'disabled' : ''; ?>>
                            ✦ Genera tutte in background (<?php echo esc_html($total_items); ?>)
                        </button>
                    <?php endif; ?>
                </div>

                <?php
                $pct = 0;
                if ($job && $job['total'] > 0) {
                    $pct = min(100, round((($job['done'] + $job['errors']) / $job['total']) * 100));
                }
                ?>
                <div id="bulk-progress-wrap" class="suite-barrio-progress-wrap"
                    style="<?php echo ($job_running || $job_done) ? '' : 'display:none;'; ?>">
                    <div id="bulk-progress-bar" class="suite-barrio-progress-bar"
                        style="width:<?php echo esc_attr($pct); ?>%;background:<?php echo $job_done ? '#059669' : '#111827'; ?>;"></div>
                    <div id="bulk-progress-text" class="suite-barrio-progress-text">
                        <?php if ($job_running): ?>
                            <?php echo esc_html($job['done'] + $job['errors']); ?> / <?php echo esc_html($job['total']); ?> elaborate —
                            <?php echo esc_html($job['done']); ?> ✓<?php echo esc_html($job['errors'] > 0 ? '  ' . $job['errors'] . ' ✗' : ''); ?>
                        <?php elseif ($job_done): ?>
                            ✓ Completato — <?php echo esc_html($job['done']); ?>
                            salvate<?php echo esc_html($job['errors'] > 0 ? ', ' . $job['errors'] . ' errori (vedi Log)' : ''); ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif;

            if (empty($attachments)): ?>
                <div class="suite-barrio-notice suite-barrio-notice-success">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <div><strong>Tutto a posto!</strong> Tutte le immagini hanno già alt text e titolo.</div>
                </div>
            <?php else: ?>
                <div class="suite-barrio-img-grid" id="altai-grid">
                    <?php foreach ($attachments as $att):
                        $thumb = wp_get_attachment_image_url($att->ID, 'medium');
                        $full = wp_get_attachment_url($att->ID);
                        $alt = get_post_meta($att->ID, '_wp_attachment_image_alt', true);
                        $title = $att->post_title;
                        $fname = basename(get_attached_file($att->ID));
                        ?>
                        <div class="suite-barrio-img-card" data-id="<?php echo esc_attr($att->ID); ?>" data-url="<?php echo esc_attr($full); ?>">
                            <div class="suite-barrio-img-thumb">
                                <?php if ($thumb): ?>
                                    <img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:32px;">🖼️
                                    </div>
                                <?php endif; ?>
                                <span class="suite-barrio-ai-badge">AI</span>
                            </div>
                            <div class="suite-barrio-img-body">
                                <div class="suite-barrio-img-filename"><?php echo esc_html($fname); ?></div>
                                <div class="suite-barrio-img-field">
                                    <label>Alt text</label>
                                    <input type="text" class="alt-input" value="<?php echo esc_attr($alt); ?>"
                                        placeholder="Descrizione immagine…">
                                </div>
                                <div class="suite-barrio-img-field">
                                    <label>Title</label>
                                    <input type="text" class="title-input" value="<?php echo esc_attr($title); ?>"
                                        placeholder="Titolo immagine…">
                                </div>
                                <div class="suite-barrio-img-actions">
                                    <button class="suite-barrio-img-btn-gen" <?php echo empty($api_key) ? 'disabled' : ''; ?>>✦ Genera e
                                        salva</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="suite-barrio-pagination">
                        <?php echo wp_kses_post(paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'current' => $paged,
                            'total' => $total_pages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'type' => 'plain',
                        ])); ?>
                    </div>
                <?php endif;
            endif;

            /* ════════════════════════════════════════
               TAB: LOG
               ════════════════════════════════════════ */
        elseif ($tab === 'log'):

            if (!get_option('wp_alt_ai_log_enabled', 1)): ?>
                <div class="suite-barrio-notice suite-barrio-notice-warning">
                    <span class="dashicons dashicons-warning"></span>
                    <div><strong>Registrazione log disabilitata.</strong> <a href="?page=suite-barrio-auto-alt&tab=settings">Riattiva
                            nelle impostazioni &rarr;</a></div>
                </div>
            <?php else:

                if (isset($_POST['clear_log']) && check_admin_referer('suite_barrio_clear_log')) {
                    delete_option(SUITE_BARRIO_LOG_OPTION);
                    $log_entries = $log_slice = [];
                    $log_total = $log_success = $log_errors = 0;
                    echo '<div class="suite-barrio-notice suite-barrio-notice-success" style="margin-bottom:16px;"><span class="dashicons dashicons-yes-alt"></span><div>Log svuotato.</div></div>';
                }
                ?>

                <!-- Log KPI -->
                <div class="suite-barrio-log-kpi">
                    <div class="suite-barrio-log-kpi-item">
                        <div class="suite-barrio-log-kpi-num"><?php echo esc_html($log_total); ?></div>
                        <div class="suite-barrio-log-kpi-lbl">Totale operazioni</div>
                    </div>
                    <div class="suite-barrio-log-kpi-item">
                        <div class="suite-barrio-log-kpi-num" style="color:#059669;"><?php echo esc_html($log_success); ?></div>
                        <div class="suite-barrio-log-kpi-lbl">Completate</div>
                    </div>
                    <div class="suite-barrio-log-kpi-item">
                        <div class="suite-barrio-log-kpi-num" style="color:#dc2626;"><?php echo esc_html($log_errors); ?></div>
                        <div class="suite-barrio-log-kpi-lbl">Errori</div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="suite-barrio-log-toolbar">
                    <div class="suite-barrio-log-chips">
                        <button class="suite-barrio-chip active" data-filter="all">Tutti</button>
                        <button class="suite-barrio-chip" data-filter="success">Successi</button>
                        <button class="suite-barrio-chip" data-filter="error">Errori</button>
                    </div>
                    <form method="post" onsubmit="return confirm('Svuotare tutto il log? L\'operazione è irreversibile.');"
                        style="display:inline;">
                        <?php wp_nonce_field('suite_barrio_clear_log'); ?>
                        <button type="submit" name="clear_log" class="suite-barrio-btn-clear-log">🗑 Svuota log</button>
                    </form>
                </div>

                <?php if (empty($log_entries)): ?>
                    <div class="suite-barrio-table-wrap">
                        <div class="suite-barrio-log-empty">Nessuna operazione registrata ancora.</div>
                    </div>
                <?php else: ?>
                    <div class="suite-barrio-table-wrap" style="overflow-x:auto;">
                        <table class="suite-barrio-table" id="log-table">
                            <thead>
                                <tr>
                                    <th style="width:44px;"></th>
                                    <th>File</th>
                                    <th>Stato</th>
                                    <th>Alt generato</th>
                                    <th>Nota</th>
                                    <th>Data / ora</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($log_slice as $entry):
                                    $thumb_url = wp_get_attachment_image_url($entry['id'], 'thumbnail');
                                    $edit_link = get_edit_post_link($entry['id']);
                                    $label_map = ['success' => '&#x2713; Successo', 'error' => '&#x2715; Errore', 'manual' => '&#x270E; Manuale'];
                                    $is_err = ($entry['status'] === 'error');
                                    ?>
                                    <tr data-status="<?php echo esc_attr($entry['status']); ?>">
                                        <td>
                                            <?php if ($thumb_url): ?>
                                                <img src="<?php echo esc_url($thumb_url); ?>" class="suite-barrio-log-thumb" alt="">
                                            <?php else: ?>
                                                <span style="font-size:22px;">🖼️</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($edit_link): ?>
                                                <a href="<?php echo esc_url($edit_link); ?>" class="suite-barrio-log-filename"
                                                    target="_blank"><?php echo esc_html($entry['file']); ?></a>
                                            <?php else: ?>
                                                <span class="suite-barrio-log-filename"><?php echo esc_html($entry['file'] ?: '—'); ?></span>
                                            <?php endif; ?>
                                            <div class="suite-barrio-log-id">ID <?php echo intval($entry['id']); ?></div>
                                        </td>
                                        <td>
                                            <span class="suite-barrio-status-pill <?php echo esc_attr($entry['status']); ?>">
                                                <?php echo wp_kses_post($label_map[$entry['status']] ?? esc_html($entry['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="suite-barrio-log-alt" title="<?php echo esc_attr($entry['alt']); ?>">
                                                <?php echo esc_html($entry['alt'] ?: '—'); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="<?php echo $is_err ? 'suite-barrio-log-note-err' : 'suite-barrio-log-note'; ?>">
                                                <?php echo esc_html($entry['note'] ?: '—'); ?>
                                            </div>
                                        </td>
                                        <td><span class="suite-barrio-log-time"><?php echo esc_html(date_i18n('d/m/Y H:i', $entry['ts'])); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($log_total_pages > 1): ?>
                        <div class="suite-barrio-pagination" style="margin-top:20px;">
                            <?php for ($i = 1; $i <= $log_total_pages; $i++):
                                $url = add_query_arg(['page' => 'suite-barrio-auto-alt', 'tab' => 'log', 'log_page' => $i]);
                                if ($i === $log_page): ?>
                                    <span class="current"><?php echo esc_html($i); ?></span>
                                <?php else: ?>
                                    <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($i); ?></a>
                                <?php endif;
                            endfor; ?>
                        </div>
                    <?php endif;
                endif; // log_enabled
    
            endif;

        elseif ($tab === 'settings'):
            $provider = get_option('wp_alt_ai_provider', 'anthropic');
            $model = get_option('wp_alt_ai_model', 'claude-opus-4-6');
            $key_anthropic = get_option('wp_alt_ai_api_key', '');
            $key_gemini = get_option('wp_alt_ai_gemini_key', '');

            $providers = [
                'anthropic' => [
                    'name' => 'Anthropic — Claude',
                    'desc' => 'Claude Opus, Sonnet, Haiku — eccellente per descrizioni contestuali',
                    'key_field' => 'wp_alt_ai_api_key',
                    'key_val' => $key_anthropic,
                    'key_ph' => 'sk-ant-api03-…',
                    'key_url' => 'https://console.anthropic.com/',
                    'key_label' => 'Chiave API Anthropic',
                    'models' => [
                        'claude-opus-4-6' => ['name' => 'Claude Opus 4.6', 'desc' => 'Massima qualità, descrizioni dettagliate'],
                        'claude-sonnet-4-6' => ['name' => 'Claude Sonnet 4.6', 'desc' => 'Equilibrio ottimale qualità/velocità'],
                        'claude-haiku-4-5-20251001' => ['name' => 'Claude Haiku 4.5', 'desc' => 'Veloce ed economico, grandi volumi'],
                    ],
                    'default_model' => 'claude-opus-4-6',
                ],
                'gemini' => [
                    'name' => 'Google — Gemini',
                    'desc' => 'Gemini 2.0 / 2.5 Flash & Pro — ottimo rapporto qualità/costo',
                    'key_field' => 'wp_alt_ai_gemini_key',
                    'key_val' => $key_gemini,
                    'key_ph' => 'AIza…',
                    'key_url' => 'https://aistudio.google.com/app/apikey',
                    'key_label' => 'Chiave API Google AI Studio',
                    'models' => [
                        'gemini-2.5-pro' => ['name' => 'Gemini 2.5 Pro', 'desc' => 'Massima qualità, ragionamento avanzato'],
                        'gemini-2.5-flash' => ['name' => 'Gemini 2.5 Flash', 'desc' => 'Ottimo equilibrio qualità/velocità'],
                        'gemini-2.0-flash' => ['name' => 'Gemini 2.0 Flash', 'desc' => 'Veloce ed economico, uso generale'],
                        'gemini-2.0-flash-lite' => ['name' => 'Gemini 2.0 Flash-Lite', 'desc' => 'Ultraveloce, alta frequenza'],
                    ],
                    'default_model' => 'gemini-2.5-flash',
                ],
            ];
            ?>
            <form method="post" action="options.php" style="max-width:640px;">
                <?php settings_fields('suite_barrio_settings'); ?>

                <!-- Provider & modello -->
                <div class="suite-barrio-section-box">
                    <div class="suite-barrio-section-head"><span class="dashicons dashicons-superhero-alt"></span> Provider e modello AI
                    </div>
                    <div class="suite-barrio-section-body">
                        <?php foreach ($providers as $pval => $pdata):
                            $is_sel = ($provider === $pval);
                            $active_model = ($is_sel && in_array($model, array_keys($pdata['models']))) ? $model : $pdata['default_model'];
                            ?>
                            <label class="suite-barrio-provider-card <?php echo $is_sel ? 'selected' : ''; ?>"
                                data-provider="<?php echo esc_attr($pval); ?>">
                                <input type="radio" name="wp_alt_ai_provider" value="<?php echo esc_attr($pval); ?>" <?php checked($provider, $pval); ?>>
                                <div>
                                    <div class="suite-barrio-pc-name"><?php echo esc_html($pdata['name']); ?></div>
                                    <div class="suite-barrio-pc-desc"><?php echo esc_html($pdata['desc']); ?></div>
                                </div>
                            </label>
                            <div class="suite-barrio-provider-keys <?php echo $is_sel ? 'visible' : ''; ?>"
                                id="keys-<?php echo esc_attr($pval); ?>">
                                <div class="suite-barrio-key-group">
                                    <label><?php echo esc_html($pdata['key_label']); ?></label>
                                    <input type="password" name="<?php echo esc_attr($pdata['key_field']); ?>"
                                        value="<?php echo esc_attr($pdata['key_val']); ?>"
                                        placeholder="<?php echo esc_attr($pdata['key_ph']); ?>" autocomplete="off">
                                    <div class="suite-barrio-key-hint">Ottieni la chiave su <a
                                            href="<?php echo esc_url($pdata['key_url']); ?>"
                                            target="_blank"><?php echo esc_html($pdata['key_url']); ?></a></div>
                                </div>
                                <div class="suite-barrio-key-group">
                                    <label>Modello</label>
                                    <div class="suite-barrio-model-bar">
                                        <span class="suite-barrio-model-status" id="model-status-<?php echo esc_attr($pval); ?>"></span>
                                        <button type="button" class="suite-barrio-btn suite-barrio-btn-ghost suite-barrio-btn-sm btn-refresh-models"
                                            style="font-size:11px;">↻ Aggiorna</button>
                                    </div>
                                    <div class="suite-barrio-model-group">
                                        <?php foreach ($pdata['models'] as $mval => $mdata):
                                            $m_checked = ($active_model === $mval);
                                            ?>
                                            <label class="suite-barrio-model-label <?php echo $m_checked ? 'selected' : ''; ?>">
                                                <input type="radio" name="wp_alt_ai_model" value="<?php echo esc_attr($mval); ?>" <?php checked($active_model, $mval); ?>                 <?php echo !$is_sel ? 'disabled' : ''; ?>>
                                                <div>
                                                    <div class="suite-barrio-model-name"><?php echo esc_html($mdata['name']); ?></div>
                                                    <div class="suite-barrio-model-desc"><?php echo esc_html($mdata['desc']); ?></div>
                                                    <div class="suite-barrio-model-id"><?php echo esc_html($mval); ?></div>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Lingua -->
                <div class="suite-barrio-section-box">
                    <div class="suite-barrio-section-head"><span class="dashicons dashicons-translation"></span> Lingua output</div>
                    <div class="suite-barrio-section-body">
                        <?php $lang = get_option('wp_alt_ai_language', 'it'); ?>
                        <label class="suite-barrio-field-label">Lingua dei testi generati</label>
                        <select name="wp_alt_ai_language" class="suite-barrio-select">
                            <option value="it" <?php selected($lang, 'it'); ?>>🇮🇹 Italiano</option>
                            <option value="en" <?php selected($lang, 'en'); ?>>🇬🇧 English</option>
                            <option value="es" <?php selected($lang, 'es'); ?>>🇪🇸 Español</option>
                            <option value="fr" <?php selected($lang, 'fr'); ?>>🇫🇷 Français</option>
                            <option value="de" <?php selected($lang, 'de'); ?>>🇩🇪 Deutsch</option>
                        </select>
                    </div>
                </div>

                <!-- Auto upload + Log -->
                <div class="suite-barrio-section-box">
                    <div class="suite-barrio-section-head"><span class="dashicons dashicons-admin-settings"></span> Comportamento</div>
                    <div class="suite-barrio-section-body" style="padding-bottom:12px;">

                        <div class="suite-barrio-toggle-row">
                            <label class="suite-barrio-toggle">
                                <input type="checkbox" name="wp_alt_ai_auto_upload" value="1" <?php checked($auto_upload, 1); ?>><span class="suite-barrio-toggle-track"></span>
                            </label>
                            <div class="suite-barrio-toggle-info">
                                <div class="suite-barrio-toggle-label">Generazione automatica su upload</div>
                                <div class="suite-barrio-toggle-desc">Ogni nuova immagine caricata nella Libreria Media viene elaborata
                                    automaticamente in background.</div>
                            </div>
                        </div>

                        <?php $log_enabled = get_option('wp_alt_ai_log_enabled', 1); ?>
                        <div class="suite-barrio-toggle-row">
                            <label class="suite-barrio-toggle">
                                <input type="checkbox" name="wp_alt_ai_log_enabled" value="1" <?php checked($log_enabled, 1); ?>><span class="suite-barrio-toggle-track"></span>
                            </label>
                            <div class="suite-barrio-toggle-info">
                                <div class="suite-barrio-toggle-label">Registrazione log</div>
                                <div class="suite-barrio-toggle-desc">Salva ogni operazione AI nella scheda Log con stato e dettagli.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Prompt -->
                <div class="suite-barrio-section-box">
                    <div class="suite-barrio-section-head"><span class="dashicons dashicons-edit-page"></span> Impostazioni prompt</div>
                    <div class="suite-barrio-section-body">

                        <div style="margin-bottom:18px;">
                            <label class="suite-barrio-field-label" for="wp_alt_ai_tone">Registro linguistico</label>
                            <?php $tone = get_option('wp_alt_ai_tone', 'neutral'); ?>
                            <select name="wp_alt_ai_tone" id="wp_alt_ai_tone" class="suite-barrio-select">
                                <option value="neutral" <?php selected($tone, 'neutral'); ?>>Neutro e oggettivo</option>
                                <option value="professional" <?php selected($tone, 'professional'); ?>>Professionale e formale
                                </option>
                                <option value="friendly" <?php selected($tone, 'friendly'); ?>>Amichevole e accessibile</option>
                                <option value="technical" <?php selected($tone, 'technical'); ?>>Tecnico e preciso</option>
                                <option value="creative" <?php selected($tone, 'creative'); ?>>Creativo e coinvolgente</option>
                                <option value="seo" <?php selected($tone, 'seo'); ?>>Ottimizzato SEO</option>
                            </select>
                            <p class="suite-barrio-field-desc">Tono dei testi generati per alt text e titolo.</p>
                        </div>

                        <div style="margin-bottom:18px;">
                            <label class="suite-barrio-field-label" for="wp_alt_ai_style">Stile descrittivo</label>
                            <?php $style = get_option('wp_alt_ai_style', 'descriptive'); ?>
                            <select name="wp_alt_ai_style" id="wp_alt_ai_style" class="suite-barrio-select">
                                <option value="descriptive" <?php selected($style, 'descriptive'); ?>>Descrittivo — elenca gli
                                    elementi visivi</option>
                                <option value="contextual" <?php selected($style, 'contextual'); ?>>Contestuale — interpreta
                                    significato e scopo</option>
                                <option value="emotional" <?php selected($style, 'emotional'); ?>>Evocativo — trasmette
                                    sensazioni e atmosfera</option>
                                <option value="journalistic" <?php selected($style, 'journalistic'); ?>>Giornalistico —
                                    sintetico come una didascalia</option>
                            </select>
                            <p class="suite-barrio-field-desc">Approccio con cui l'AI descrive l'immagine.</p>
                        </div>

                        <div style="margin-bottom:18px;">
                            <label class="suite-barrio-field-label" for="wp_alt_ai_context">Contesto del sito</label>
                            <input type="text" name="wp_alt_ai_context" id="wp_alt_ai_context" class="suite-barrio-input"
                                style="width:100%;max-width:460px;"
                                value="<?php echo esc_attr(get_option('wp_alt_ai_context', '')); ?>"
                                placeholder="es. e-commerce di moda sostenibile, blog di cucina italiana…">
                            <p class="suite-barrio-field-desc">Fornisce contesto all'AI per generare testi più pertinenti al tuo settore.
                            </p>
                        </div>

                        <div>
                            <label class="suite-barrio-field-label" for="wp_alt_ai_keywords">Parole chiave SEO</label>
                            <input type="text" name="wp_alt_ai_keywords" id="wp_alt_ai_keywords" class="suite-barrio-input"
                                style="width:100%;max-width:460px;"
                                value="<?php echo esc_attr(get_option('wp_alt_ai_keywords', '')); ?>"
                                placeholder="es. scarpe artigianali, pelle italiana, handmade…">
                            <p class="suite-barrio-field-desc">Parole chiave da includere nell'alt text se pertinenti. Separate da
                                virgola.</p>
                        </div>
                    </div>
                </div>

                <button type="submit" class="suite-barrio-save-btn">
                    <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span>
                    Salva impostazioni
                </button>
            </form>

            <?php // JS della tab "Impostazioni" caricato via admin_enqueue_scripts (handle 'suite-barrio-auto-alt-settings') ?>

        <?php endif; // end tabs ?>

    </div><!-- #suite-barrio-autoalt-wrap -->

    <div id="suite-barrio-toast"></div>

    <?php // JS sempre presente sulla pagina caricato via admin_enqueue_scripts (handle 'suite-barrio-auto-alt-page') ?>
    <?php
}

/* ─────────────────────────────────────────
   6. AJAX: salva alt + title + log
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_save', function () {
    check_ajax_referer('suite_barrio_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $id = intval($_POST['attachment_id'] ?? 0);
    $alt = sanitize_text_field(wp_unslash($_POST['alt_text'] ?? ''));
    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $source = sanitize_key($_POST['source'] ?? 'manual');

    if (!$id || get_post_type($id) !== 'attachment')
        wp_send_json_error('ID non valido.');

    update_post_meta($id, '_wp_attachment_image_alt', $alt);
    wp_update_post(['ID' => $id, 'post_title' => $title]);

    if (function_exists('invalidate_vercel_cache')) {
        $parent_id = wp_get_post_parent_id($id);
        invalidate_vercel_cache($parent_id ?: 'wp:global');
    }

    $status = ($source === 'ai') ? 'success' : 'manual';
    $note = ($source === 'ai') ? 'Generato con AI, salvato dalla revisione' : 'Salvato manualmente dalla revisione';
    suite_barrio_log($id, $status, $alt, $title, $note);

    wp_send_json_success(['id' => $id]);
});

/* ─────────────────────────────────────────
   7. AJAX: genera alt+title via Claude (server-side, chiave sicura)
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_generate', function () {
    check_ajax_referer('suite_barrio_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $id = intval($_POST['attachment_id'] ?? 0);
    if (!$id || get_post_type($id) !== 'attachment') {
        wp_send_json_error('ID attachment non valido.');
    }

    // Leggi immagine da filesystem (più veloce) o da URL
    $image_path = get_attached_file($id);
    if ($image_path && file_exists($image_path)) {
        $image_data = file_get_contents($image_path);
    } else {
        $image_url = wp_get_attachment_url($id);
        $image_data = $image_url ? @file_get_contents($image_url) : false;
    }

    if (!$image_data) {
        suite_barrio_log($id, 'error', '', '', 'Impossibile leggere il file immagine');
        wp_send_json_error('Impossibile leggere il file immagine.');
    }

    $mime = get_post_mime_type($id) ?: 'image/jpeg';
    $lang = get_option('wp_alt_ai_language', 'it');
    $lang_map = ['it' => 'italiano', 'en' => 'English', 'es' => 'español', 'fr' => 'français', 'de' => 'Deutsch'];
    $lang_name = $lang_map[$lang] ?? 'italiano';

    // Rimuovi il controllo chiave API singola — ora gestito dentro suite_barrio_call_api()
    $result = suite_barrio_call_api($id, $image_data, $mime, $lang_name);

    if (isset($result['error'])) {
        suite_barrio_log($id, 'error', '', '', $result['error']);
        wp_send_json_error($result['error']);
    }

    $provider = get_option('wp_alt_ai_provider', 'anthropic');
    $model = get_option('wp_alt_ai_model', '');
    $note = 'Generato con AI (' . $provider . ($model ? ' / ' . $model : '') . ')';

    // Se richiesto (es. bulk), salva direttamente qui
    $save = !empty($_POST['save']);
    if ($save) {
        update_post_meta($id, '_wp_attachment_image_alt', $result['alt']);
        wp_update_post(['ID' => $id, 'post_title' => $result['title']]);
        if (function_exists('invalidate_vercel_cache')) {
            $parent_id = wp_get_post_parent_id($id);
            invalidate_vercel_cache($parent_id ?: 'wp:global');
        }
        $note .= ' — auto-salvato';
    }

    suite_barrio_log($id, 'success', $result['alt'], $result['title'], $note);

    wp_send_json_success([
        'alt' => $result['alt'],
        'title' => $result['title'],
        'saved' => $save,
    ]);
});

/* ─────────────────────────────────────────
   8. AJAX: restituisce tutti gli ID immagini senza alt/title
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_get_missing_ids', function () {
    check_ajax_referer('suite_barrio_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $ids = suite_barrio_get_incomplete_ids();
    wp_send_json_success(array_map('intval', $ids));
});

/* ─────────────────────────────────────────
   9. AJAX: avvia job bulk in background
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_bulk_start', function () {
    check_ajax_referer('suite_barrio_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    // Se c'è già un job attivo non ne avviare un altro
    $existing = suite_barrio_get_job();
    if ($existing && $existing['status'] === 'running') {
        wp_send_json_error('Elaborazione già in corso.');
    }

    $ids = suite_barrio_get_incomplete_ids();
    if (empty($ids)) {
        wp_send_json_error('Nessuna immagine da elaborare.');
    }

    $job = [
        'status' => 'running',
        'total' => count($ids),
        'pending' => array_map('intval', $ids),
        'done' => 0,
        'errors' => 0,
        'started' => current_time('timestamp'),
    ];
    suite_barrio_save_job($job);

    // Schedula il primo batch immediatamente
    wp_schedule_single_event(time(), SUITE_BARRIO_CRON_HOOK);

    // Esegui il cron subito (senza attendere la visita successiva)
    wp_remote_get(add_query_arg('doing_wp_cron', '1', site_url('wp-cron.php')), ['timeout' => 0.01, 'blocking' => false, 'sslverify' => false]);

    wp_send_json_success(['total' => $job['total']]);
});

/* ─────────────────────────────────────────
   10. AJAX: stato del job in corso (polling)
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_job_status', function () {
    check_ajax_referer('suite_barrio_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $job = suite_barrio_get_job();
    if (!$job) {
        wp_send_json_error('Nessun job attivo.');
    }
    wp_send_json_success([
        'status' => $job['status'],
        'total' => $job['total'] ?? 0,
        'done' => $job['done'] ?? 0,
        'errors' => $job['errors'] ?? 0,
        'pending' => count($job['pending'] ?? []),
    ]);
});

/* ─────────────────────────────────────────
   11. AJAX: interrompi job in corso
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_bulk_stop', function () {
    check_ajax_referer('suite_barrio_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    suite_barrio_clear_job();
    wp_send_json_success();
});

/* ─────────────────────────────────────────
   12. AJAX: dismiss notifica job completato
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_dismiss_job', function () {
    check_ajax_referer('suite_barrio_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    suite_barrio_clear_job();
    wp_send_json_success();
});

/* ─────────────────────────────────────────
   13. PULSANTE GENERA nella pagina dettaglio media
       HTML puro nel filtro (niente <script>).
       Il JS e i dati di configurazione vengono caricati
       tramite wp_enqueue_script + wp_localize_script,
       che sono gli unici metodi sicuri rispetto al
       parser di Backbone/Underscore usato nel modale media.
   ───────────────────────────────────────── */
add_filter('attachment_fields_to_edit', function ($form_fields, $post) {
    if (strpos($post->post_mime_type, 'image/') === false)
        return $form_fields;

    // Mostra il pulsante solo all'admin con email specifica
    $current_user = wp_get_current_user();
    if (
        !current_user_can('administrator') ||
        $current_user->user_email !== 'support@hellobarrio.it'
    ) {
        return $form_fields;
    }

    $api_key = suite_barrio_active_key();
    $disabled = empty($api_key) ? ' disabled' : '';

    // HTML ridotto al minimo: nessun attributo title, nessun campo helps,
    // nessun carattere non-ASCII. Qualsiasi testo extra passa attraverso
    // _.template() di Underscore e puo' rompere il modale Backbone.
    $html = '<button type="button"'
        . ' class="button suite-barrio-gen-btn"'
        . ' data-id="' . esc_attr($post->ID) . '"'
        . $disabled . '>'
        . 'Genera con AI'
        . '</button>'
        . '<p class="suite-barrio-gen-result" style="margin:6px 0 0;font-size:12px;min-height:16px;"></p>';

    $form_fields['suite_barrio_auto_alt'] = [
        'label' => 'Auto Alt',
        'input' => 'html',
        'html' => $html,
    ];

    return $form_fields;
}, 10, 2);

// Enqueue del file JS fisico + localizzazione dati (nonce, ajaxurl)
// Usare un file .js reale e wp_localize_script e' l'unico modo
// per passare dati al JS senza toccare i template Backbone.
add_action('admin_enqueue_scripts', function () {
    $js_url = SUITE_BARRIO_PLUGIN_URL . 'modules/auto-alt/js/media-generate.js';
    $js_ver = SUITE_BARRIO_VERSION;

    wp_enqueue_script(
        'suite-barrio-media-generate',
        $js_url,
        ['jquery'],
        $js_ver,
        true   // in footer
    );

    wp_localize_script('suite-barrio-media-generate', 'suiteBarrioAutoAlt', [
        'nonce' => wp_create_nonce('suite_barrio_nonce'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
    ]);
});

/* ─────────────────────────────────────────
   14. AJAX: genera e salva FORZANDO sovrascrittura
       (usato dal pulsante nella pagina media)
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_generate_force', function () {
    check_ajax_referer('suite_barrio_nonce', 'nonce');
    if (!current_user_can('upload_files'))
        wp_send_json_error('Permesso negato.');

    $id = intval($_POST['attachment_id'] ?? 0);
    if (!$id || get_post_type($id) !== 'attachment')
        wp_send_json_error('ID attachment non valido.');
    if (strpos(get_post_mime_type($id), 'image/') === false)
        wp_send_json_error('Il file non è un\'immagine.');

    // Leggi immagine da filesystem o da URL
    $image_path = get_attached_file($id);
    if ($image_path && file_exists($image_path)) {
        $image_data = file_get_contents($image_path);
    } else {
        $image_url = wp_get_attachment_url($id);
        $image_data = $image_url ? @file_get_contents($image_url) : false;
    }

    if (!$image_data) {
        suite_barrio_log($id, 'error', '', '', 'Media detail: impossibile leggere il file immagine');
        wp_send_json_error('Impossibile leggere il file immagine.');
    }

    $mime = get_post_mime_type($id) ?: 'image/jpeg';
    $lang = get_option('wp_alt_ai_language', 'it');
    $lang_map = ['it' => 'italiano', 'en' => 'English', 'es' => 'español', 'fr' => 'français', 'de' => 'Deutsch'];
    $lang_name = $lang_map[$lang] ?? 'italiano';

    // Forza generazione di entrambi i campi ('both'), ignorando quelli già presenti
    $result = suite_barrio_call_api($id, $image_data, $mime, $lang_name, 'both');

    if (isset($result['error'])) {
        suite_barrio_log($id, 'error', '', '', 'Media detail: ' . $result['error']);
        wp_send_json_error($result['error']);
    }

    // Salva sovrascrivendo i valori esistenti
    update_post_meta($id, '_wp_attachment_image_alt', $result['alt']);
    wp_update_post(['ID' => $id, 'post_title' => $result['title']]);

    if (function_exists('invalidate_vercel_cache')) {
        $parent_id = wp_get_post_parent_id($id);
        invalidate_vercel_cache($parent_id ?: 'wp:global');
    }

    $provider = get_option('wp_alt_ai_provider', 'anthropic');
    $model = get_option('wp_alt_ai_model', '');
    suite_barrio_log(
        $id,
        'success',
        $result['alt'],
        $result['title'],
        'Generato con AI (' . $provider . ($model ? ' / ' . $model : '') . ') — sovrascrittura forzata da pagina media'
    );

    wp_send_json_success([
        'alt' => $result['alt'],
        'title' => $result['title'],
    ]);
});

/* ─────────────────────────────────────────
   15. BULK ACTION nella libreria media (upload.php)
       Aggiunge "Genera Alt + Title (AI)" al menu
       Azioni di massa e carica il JS dedicato.
   ───────────────────────────────────────── */

// Registra l'azione nel menu dropdown Azioni di massa
add_filter('bulk_actions-upload', function ($actions) {
    if (current_user_can('upload_files')) {
        $actions['suite_barrio_generate_alt'] = 'Genera Alt + Title (AI)';
    }
    return $actions;
});

// Enqueue JS bulk solo sulla pagina libreria media
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'upload.php')
        return;

    wp_enqueue_script(
        'suite-barrio-media-bulk',
        SUITE_BARRIO_PLUGIN_URL . 'modules/auto-alt/js/media-bulk.js',
        ['jquery'],
        SUITE_BARRIO_VERSION,
        true
    );

    wp_localize_script('suite-barrio-media-bulk', 'suiteBarrioAutoAlt', [
        'nonce' => wp_create_nonce('suite_barrio_nonce'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
    ]);
});