<?php
/**
 * WP Barrio — Auto Alt
 * Genera automaticamente alt text e title delle immagini tramite AI.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   HELPER: scrivi una riga nel log
   ───────────────────────────────────────── */
function wp_alt_ai_log($attachment_id, $status, $alt = '', $title = '', $note = '')
{
    if (!get_option('wp_alt_ai_log_enabled', 1))
        return;
    $log = get_option(WP_ALT_AI_LOG_OPTION, []);
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
    if (count($log) > WP_ALT_AI_LOG_MAX) {
        $log = array_slice($log, 0, WP_ALT_AI_LOG_MAX);
    }
    update_option(WP_ALT_AI_LOG_OPTION, $log, false);
}

/* ─────────────────────────────────────────
   BACKGROUND JOB: processa un batch di immagini
   ───────────────────────────────────────── */
add_action(WP_ALT_AI_CRON_HOOK, 'wp_alt_ai_process_batch');

function wp_alt_ai_get_job()
{
    return get_option(WP_ALT_AI_JOB_OPTION, null);
}

function wp_alt_ai_save_job($job)
{
    update_option(WP_ALT_AI_JOB_OPTION, $job, false);
}

function wp_alt_ai_clear_job()
{
    delete_option(WP_ALT_AI_JOB_OPTION);
    wp_clear_scheduled_hook(WP_ALT_AI_CRON_HOOK);
}

function wp_alt_ai_process_batch()
{
    global $wpdb;

    $job = wp_alt_ai_get_job();
    if (!$job || $job['status'] !== 'running')
        return;

    // Estrai atomicamente il prossimo batch dalla coda
    // e segna subito questi ID come "in lavorazione" per prevenire esecuzioni parallele
    $pending = $job['pending'] ?? [];
    if (empty($pending)) {
        $job['status'] = 'done';
        $job['completed'] = current_time('timestamp');
        wp_alt_ai_save_job($job);
        return;
    }

    $batch = array_splice($pending, 0, WP_ALT_AI_BATCH_SIZE);
    $job['pending'] = $pending;
    // Salva subito con la coda ridotta — altri processi vedranno la coda già aggiornata
    wp_alt_ai_save_job($job);

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
            wp_alt_ai_log($id, 'error', '', '', 'Batch: impossibile leggere il file');
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

        $result = wp_alt_ai_call_api($id, $image_data, $mime, $lang_name, $fields);

        if (isset($result['error'])) {
            wp_alt_ai_log($id, 'error', '', '', 'Batch: ' . $result['error']);
            $batch_errors++;
        } else {
            // Salva solo i campi che erano mancanti
            if (!$has_alt && !empty($result['alt'])) {
                update_post_meta($id, '_wp_attachment_image_alt', $result['alt']);
            }
            if (!$has_title && !empty($result['title'])) {
                wp_update_post(['ID' => $id, 'post_title' => $result['title']]);
            }
            $provider = get_option('wp_alt_ai_provider', 'anthropic');
            $saved_fields = implode(' + ', array_filter([
                !$has_alt ? 'alt' : '',
                !$has_title ? 'title' : '',
            ]));
            wp_alt_ai_log(
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
    $job = wp_alt_ai_get_job();
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
        if (!wp_next_scheduled(WP_ALT_AI_CRON_HOOK)) {
            wp_schedule_single_event(time() + 3, WP_ALT_AI_CRON_HOOK);
        }
    }

    wp_alt_ai_save_job($job);
}

/* ─────────────────────────────────────────
   HELPER: chiave API del provider attivo
   ───────────────────────────────────────── */
function wp_alt_ai_active_key()
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
function wp_alt_ai_get_incomplete_ids($limit = -1, $offset = 0)
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

    return $wpdb->get_col($sql);
}

function wp_alt_ai_count_incomplete()
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
    return (int) $wpdb->get_var($sql);
}

/* ─────────────────────────────────────────
   HELPER: chiama l'API del provider scelto
   ───────────────────────────────────────── */
function wp_alt_ai_call_api($attachment_id, $image_data, $mime, $lang_name, $fields = 'both')
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
function wp_alt_ai_fetch_models($provider)
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
add_action('wp_ajax_wp_alt_ai_fetch_models', function () {
    check_ajax_referer('wp_alt_ai_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $provider = sanitize_key($_POST['provider'] ?? 'anthropic');
    $force = !empty($_POST['force']); // force = ignora cache
    if ($force)
        delete_transient('wp_alt_ai_models_' . $provider);

    $models = wp_alt_ai_fetch_models($provider);
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
        'wp-barrio',
        'Auto Alt — WP Barrio',
        'Auto Alt',
        'manage_options',
        'wp-barrio-auto-alt',
        'wp_alt_ai_admin_page'
    );
});

/* ─────────────────────────────────────────
   2. IMPOSTAZIONI
   ───────────────────────────────────────── */
add_action('admin_init', function () {
    register_setting('wp_alt_ai_settings', 'wp_alt_ai_provider', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wp_alt_ai_settings', 'wp_alt_ai_api_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wp_alt_ai_settings', 'wp_alt_ai_gemini_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wp_alt_ai_settings', 'wp_alt_ai_language', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wp_alt_ai_settings', 'wp_alt_ai_auto_upload', ['sanitize_callback' => 'absint']);
    register_setting('wp_alt_ai_settings', 'wp_alt_ai_model', ['sanitize_callback' => 'sanitize_text_field']);
    // Impostazioni prompt
    register_setting('wp_alt_ai_settings', 'wp_alt_ai_tone', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'neutral']);
    register_setting('wp_alt_ai_settings', 'wp_alt_ai_style', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'descriptive']);
    register_setting('wp_alt_ai_settings', 'wp_alt_ai_context', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
    register_setting('wp_alt_ai_settings', 'wp_alt_ai_keywords', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
    register_setting('wp_alt_ai_settings', 'wp_alt_ai_log_enabled', ['sanitize_callback' => 'absint', 'default' => 1]);
});

/* ─────────────────────────────────────────
   3. HOOK AUTO SU UPLOAD (condizionale)
   ───────────────────────────────────────── */
add_action('add_attachment', function ($attachment_id) {

    if (!get_option('wp_alt_ai_auto_upload', 0))
        return;
    if (strpos(get_post_mime_type($attachment_id), 'image/') === false)
        return;

    $api_key = wp_alt_ai_active_key();
    if (empty($api_key)) {
        $provider = get_option('wp_alt_ai_provider', 'anthropic');
        wp_alt_ai_log($attachment_id, 'error', '', '', 'Chiave API non configurata per il provider: ' . $provider);
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
        wp_alt_ai_log($attachment_id, 'error', '', '', 'Impossibile leggere il file immagine');
        return;
    }

    $mime = get_post_mime_type($attachment_id) ?: 'image/jpeg';
    $lang = get_option('wp_alt_ai_language', 'it');
    $lang_map = ['it' => 'italiano', 'en' => 'English', 'es' => 'español', 'fr' => 'français', 'de' => 'Deutsch'];
    $lang_name = $lang_map[$lang] ?? 'italiano';

    $result = wp_alt_ai_call_api($attachment_id, $image_data, $mime, $lang_name);

    if (isset($result['error'])) {
        wp_alt_ai_log($attachment_id, 'error', '', '', $result['error']);
        return;
    }

    update_post_meta($attachment_id, '_wp_attachment_image_alt', $result['alt']);
    wp_update_post(['ID' => $attachment_id, 'post_title' => $result['title']]);

    wp_alt_ai_log($attachment_id, 'success', $result['alt'], $result['title'], 'Auto-generato su upload');
});

/* ─────────────────────────────────────────
   4. ADMIN NOTICE + STILI
   ───────────────────────────────────────── */

// Notice persistente su tutte le pagine admin quando il job è attivo
add_action('admin_notices', function () {
    if (!current_user_can('manage_options'))
        return;
    $job = wp_alt_ai_get_job();
    if (!$job)
        return;

    $total = $job['total'] ?? 0;
    $done = $job['done'] ?? 0;
    $errors = $job['errors'] ?? 0;
    $pending = count($job['pending'] ?? []);
    $status = $job['status'] ?? 'running';

    if ($status === 'done') {
        $msg = sprintf(
            '<strong>WP Barrio — Auto Alt</strong>: elaborazione completata. %d salvate%s. <a href="%s">Vai al Log</a> | <a href="%s" id="wpb-dismiss-job">Chiudi</a>',
            $done,
            $errors > 0 ? ', ' . $errors . ' errori' : '',
            esc_url(admin_url('admin.php?page=wp-barrio-auto-alt&tab=log')),
            esc_url(admin_url('admin-ajax.php'))
        );
        echo '<div class="notice notice-success is-dismissible wpb-job-notice"><p>' . $msg . '</p></div>';
    } elseif ($status === 'running') {
        $processed = $done + $errors;
        $pct = $total > 0 ? min(100, round(($processed / $total) * 100)) : 0;
        $msg = sprintf(
            '<strong>WP Barrio — Auto Alt</strong>: elaborazione in corso — %d / %d immagini (%d%%). Puoi continuare a lavorare. <a href="%s">Vedi progresso</a>',
            min($processed, $total),
            $total,
            $pct,
            esc_url(admin_url('admin.php?page=wp-barrio-auto-alt&tab=review'))
        );
        echo '<div class="notice notice-info wpb-job-notice"><p>' . $msg . '</p></div>';
    }
});

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'wp-barrio-auto-alt') === false)
        return;
    wp_enqueue_media();
});

/* ─────────────────────────────────────────
   5. PAGINA ADMIN
   ───────────────────────────────────────── */
function wp_alt_ai_admin_page()
{
    $api_key = wp_alt_ai_active_key();
    $auto_upload = get_option('wp_alt_ai_auto_upload', 0);
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'review';
    $per_page = 12;
    $paged = max(1, intval($_GET['paged'] ?? 1));
    $offset = ($paged - 1) * $per_page;

    // Immagini incomplete: senza alt OPPURE senza title
    $total_items = wp_alt_ai_count_incomplete();
    $total_pages = $per_page > 0 ? (int) ceil($total_items / $per_page) : 1;
    $att_ids = wp_alt_ai_get_incomplete_ids($per_page, $offset);
    $attachments = $att_ids ? get_posts([
        'post_type' => 'attachment',
        'post__in' => $att_ids,
        'posts_per_page' => count($att_ids),
        'orderby' => 'post__in',
    ]) : [];
    $total_images = array_sum((array) wp_count_attachments());

    // LOG
    $log_entries = get_option(WP_ALT_AI_LOG_OPTION, []);
    $log_page = max(1, intval($_GET['log_page'] ?? 1));
    $log_per_page = 25;
    $log_total = count($log_entries);
    $log_total_pages = max(1, ceil($log_total / $log_per_page));
    $log_slice = array_slice($log_entries, ($log_page - 1) * $log_per_page, $log_per_page);
    $log_success = count(array_filter($log_entries, fn($e) => $e['status'] === 'success'));
    $log_errors = count(array_filter($log_entries, fn($e) => $e['status'] === 'error'));
    $log_manual = count(array_filter($log_entries, fn($e) => $e['status'] === 'manual'));
    ?>
    <style>
        /* WP Barrio — stile che estende l'admin WordPress nativo */
        #wpbarrio-wrap {
            max-width: 1100px;
        }

        /* Stats bar sotto il titolo */
        .wpb-stats-bar {
            display: flex;
            gap: 0;
            margin: 0 0 20px;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            overflow: hidden;
            background: #fff;
        }

        .wpb-stat {
            flex: 1;
            padding: 12px 20px;
            border-right: 1px solid #c3c4c7;
            text-align: center;
        }

        .wpb-stat:last-child {
            border-right: none;
        }

        .wpb-stat .wpb-stat-num {
            font-size: 28px;
            font-weight: 600;
            line-height: 1;
            color: #1d2327;
        }

        .wpb-stat .wpb-stat-num.red {
            color: #d63638;
        }

        .wpb-stat .wpb-stat-num.green {
            color: #00a32a;
        }

        .wpb-stat .wpb-stat-lbl {
            font-size: 11px;
            color: #646970;
            margin-top: 3px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Nav tabs WP-style */
        .wpb-nav-tab-wrapper {
            border-bottom: 1px solid #c3c4c7;
            margin-bottom: 20px;
        }

        .wpb-nav-tab-wrapper .nav-tab {
            margin-bottom: -1px;
        }

        /* Notice personalizzato */
        .wpb-notice-api {
            margin-bottom: 16px;
        }

        /* Card griglia immagini */
        .wpb-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .wpb-card {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            overflow: hidden;
            transition: box-shadow .15s;
        }

        .wpb-card:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, .1);
        }

        .wpb-card.is-saving {
            opacity: .6;
            pointer-events: none;
        }

        .wpb-card.is-saved {
            border-color: #00a32a;
        }

        .wpb-card__thumb {
            position: relative;
            width: 100%;
            aspect-ratio: 16/9;
            background: #f0f0f1;
            overflow: hidden;
        }

        .wpb-card__thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .wpb-card__ai-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(0, 0, 0, .65);
            color: #fff;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 2px;
            opacity: 0;
            transition: opacity .2s;
        }

        .wpb-card.ai-generated .wpb-card__ai-badge {
            opacity: 1;
        }

        .wpb-card__body {
            padding: 12px;
        }

        .wpb-card__filename {
            font-size: 11px;
            color: #646970;
            margin-bottom: 10px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .wpb-field {
            margin-bottom: 8px;
        }

        .wpb-field label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #50575e;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .wpb-field input[type=text] {
            width: 100%;
            padding: 5px 8px;
            border: 1px solid #8c8f94;
            border-radius: 3px;
            font-size: 12px;
            color: #1d2327;
            background: #fff;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, .07);
            transition: border-color .1s;
        }

        .wpb-field input[type=text]:focus {
            outline: none;
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
        }

        .wpb-field input.ai-filled {
            background: #f0fff4;
            border-color: #00a32a;
        }

        .wpb-card__actions {
            display: flex;
            gap: 6px;
            margin-top: 10px;
        }

        .wpb-btn-gen {
            flex: 1;
            padding: 5px 0;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit;
            background: #2271b1;
            color: #fff;
            border: 1px solid #2271b1;
            border-radius: 3px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            transition: background .1s;
        }

        .wpb-btn-gen:hover:not(:disabled) {
            background: #135e96;
            border-color: #135e96;
        }

        .wpb-btn-gen:disabled {
            background: #a7aaad;
            border-color: #a7aaad;
            cursor: not-allowed;
        }

        .wpb-btn-save {
            flex: 1;
            padding: 5px 0;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit;
            background: #fff;
            color: #1d2327;
            border: 1px solid #8c8f94;
            border-radius: 3px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            transition: border-color .1s;
        }

        .wpb-btn-save:hover {
            border-color: #1d2327;
        }

        .wpb-btn-save.saved {
            background: #f0fff4;
            border-color: #00a32a;
            color: #00a32a;
        }

        /* Spinner */
        @keyframes wpb-spin {
            to {
                transform: rotate(360deg);
            }
        }

        .wpb-spinner {
            width: 12px;
            height: 12px;
            border: 2px solid rgba(255, 255, 255, .3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: wpb-spin .7s linear infinite;
        }

        .wpb-btn-save .wpb-spinner {
            border-color: rgba(0, 0, 0, .15);
            border-top-color: #1d2327;
        }

        /* Bulk action bar */
        .wpb-bulk {
            background: #1d2327;
            color: #fff;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            border-radius: 3px;
            font-size: 13px;
        }

        .wpb-bulk strong {
            flex: 1;
        }

        .wpb-btn-bulk {
            padding: 5px 14px;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit;
            background: #fff;
            color: #1d2327;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }

        .wpb-btn-bulk:hover {
            background: #f0f0f1;
        }

        .wpb-btn-bulk:disabled {
            background: #50575e;
            color: #a7aaad;
            cursor: not-allowed;
        }

        .wpb-btn-bulk-save {
            background: #00a32a;
            color: #fff;
        }

        .wpb-btn-bulk-save:hover {
            background: #008a20;
        }

        /* Progress bar */
        .wpb-progress {
            display: none;
            margin-bottom: 16px;
            background: #f0f0f1;
            border-radius: 3px;
            overflow: hidden;
            border: 1px solid #c3c4c7;
        }

        .wpb-progress.active {
            display: block;
        }

        .wpb-progress-bar {
            height: 4px;
            background: #2271b1;
            width: 0%;
            transition: width .3s;
        }

        .wpb-progress-text {
            font-size: 11px;
            color: #646970;
            padding: 5px 10px 7px;
        }

        /* Paginazione WP-style */
        .wpb-pagination {
            margin-top: 20px;
            text-align: center;
        }

        .wpb-pagination .pagination-links {
            display: inline-flex;
            gap: 4px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: center;
        }

        .wpb-pagination a.page-numbers,
        .wpb-pagination span.page-numbers {
            display: inline-block;
            padding: 5px 12px;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            font-size: 13px;
            color: #1d2327;
            text-decoration: none;
            background: #fff;
            line-height: 1.4;
        }

        .wpb-pagination span.page-numbers.current {
            background: #2271b1;
            color: #fff;
            border-color: #2271b1;
            font-weight: 600;
        }

        .wpb-pagination a.page-numbers:hover {
            border-color: #2271b1;
            color: #2271b1;
        }

        .wpb-pagination .page-numbers.dots {
            border: none;
            background: none;
            color: #646970;
            padding: 5px 4px;
        }

        /* Empty state */
        .wpb-empty {
            text-align: center;
            padding: 48px 20px;
            color: #646970;
        }

        .wpb-empty .dashicons {
            font-size: 48px;
            width: 48px;
            height: 48px;
            color: #c3c4c7;
            margin-bottom: 12px;
        }

        .wpb-empty h3 {
            font-size: 15px;
            color: #1d2327;
            margin: 0 0 6px;
        }

        .wpb-empty p {
            font-size: 13px;
            margin: 0;
        }

        /* Log */
        .wpb-log-stats {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
        }

        .wpb-log-stat {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            padding: 12px 20px;
            flex: 1;
            text-align: center;
        }

        .wpb-log-stat .n {
            font-size: 26px;
            font-weight: 600;
            line-height: 1;
            color: #1d2327;
        }

        .wpb-log-stat .n.green {
            color: #00a32a;
        }

        .wpb-log-stat .n.red {
            color: #d63638;
        }

        .wpb-log-stat .l {
            font-size: 11px;
            color: #646970;
            margin-top: 3px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .wpb-log-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
            gap: 10px;
            flex-wrap: wrap;
        }

        .wpb-log-filters {
            display: flex;
            gap: 4px;
        }

        .wpb-log-filter {
            padding: 4px 12px;
            font-size: 12px;
            font-family: inherit;
            cursor: pointer;
            background: #fff;
            color: #646970;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
        }

        .wpb-log-filter.active {
            background: #1d2327;
            color: #fff;
            border-color: #1d2327;
        }

        .wpb-log-filter:hover:not(.active) {
            border-color: #1d2327;
            color: #1d2327;
        }

        .wpb-btn-clear {
            padding: 4px 12px;
            font-size: 12px;
            font-family: inherit;
            cursor: pointer;
            background: #fff;
            color: #d63638;
            border: 1px solid #d63638;
            border-radius: 3px;
        }

        .wpb-btn-clear:hover {
            background: #fcf0f1;
        }

        .wpb-log-table-wrap {
            border: 1px solid #c3c4c7;
            background: #fff;
            border-radius: 3px;
            overflow: hidden;
            overflow-x: auto;
        }

        .wpb-log-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 600px;
        }

        .wpb-log-table th {
            background: #f0f0f1;
            padding: 8px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: #50575e;
            border-bottom: 1px solid #c3c4c7;
        }

        .wpb-log-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #f0f0f1;
            vertical-align: middle;
        }

        .wpb-log-table tr:last-child td {
            border-bottom: none;
        }

        .wpb-log-table tr:hover td {
            background: #f6f7f7;
        }

        tr[data-status="error"] td {
            background: #fcf0f1 !important;
        }

        tr[data-status="error"]:hover td {
            background: #fce8e9 !important;
        }

        .wpb-log-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        .wpb-log-status.success {
            background: #edfaef;
            color: #00a32a;
        }

        .wpb-log-status.error {
            background: #fcf0f1;
            color: #d63638;
        }

        .wpb-log-thumb {
            width: 34px;
            height: 34px;
            object-fit: cover;
            border-radius: 2px;
            border: 1px solid #c3c4c7;
        }

        .wpb-log-filename {
            font-family: Consolas, monospace;
            font-size: 11px;
            color: #50575e;
        }

        .wpb-log-id {
            font-size: 10px;
            color: #a7aaad;
        }

        .wpb-log-alt {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: #1d2327;
        }

        .wpb-log-note {
            font-size: 11px;
            color: #646970;
            white-space: pre-wrap;
            word-break: break-word;
            line-height: 1.5;
        }

        .wpb-log-note-error {
            font-size: 11px;
            color: #d63638;
            font-weight: 500;
            white-space: pre-wrap;
            word-break: break-all;
            line-height: 1.5;
        }

        .wpb-log-time {
            font-size: 11px;
            color: #a7aaad;
            white-space: nowrap;
        }

        .wpb-log-empty {
            text-align: center;
            padding: 40px 20px;
            color: #646970;
            font-size: 13px;
        }

        /* Settings */
        .wpb-settings-table {
            max-width: 640px;
        }

        .wpb-settings-table th {
            width: 200px;
            vertical-align: top;
            padding-top: 14px;
        }

        .wpb-provider-block {
            margin-bottom: 8px;
        }

        .wpb-provider-card {
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            padding: 12px 16px;
            cursor: pointer;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            background: #fff;
            transition: border-color .1s, background .1s;
        }

        .wpb-provider-card.selected {
            border-color: #2271b1;
            background: #f0f6fc;
        }

        .wpb-provider-card input[type=radio] {
            margin-top: 3px;
            flex-shrink: 0;
        }

        .wpb-pc-logo {
            font-size: 18px;
            flex-shrink: 0;
            width: 24px;
            text-align: center;
        }

        .wpb-pc-name {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 2px;
            color: #1d2327;
        }

        .wpb-pc-desc {
            font-size: 12px;
            color: #646970;
        }

        .wpb-provider-keys {
            display: none;
            padding: 12px 16px 8px;
            border: 1px solid #c3c4c7;
            border-top: none;
            border-radius: 0 0 3px 3px;
            background: #fafafa;
        }

        .wpb-provider-keys.visible {
            display: block;
        }

        .wpb-key-group {
            margin-bottom: 12px;
        }

        .wpb-key-group>label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #50575e;
            margin-bottom: 4px;
        }

        .wpb-key-group input[type=password] {
            width: 100%;
            max-width: 400px;
            padding: 5px 8px;
            border: 1px solid #8c8f94;
            border-radius: 3px;
            font-size: 13px;
            font-family: Consolas, monospace;
            background: #fff;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, .07);
        }

        .wpb-key-group input[type=password]:focus {
            outline: none;
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
        }

        .wpb-key-hint {
            font-size: 12px;
            color: #646970;
            margin-top: 4px;
        }

        .wpb-key-hint a {
            color: #2271b1;
        }

        .wpb-model-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .wpb-model-label {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 12px;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            cursor: pointer;
            background: #fff;
            transition: border-color .1s, background .1s;
        }

        .wpb-model-label.selected {
            border-color: #2271b1;
            background: #f0f6fc;
        }

        .wpb-model-label input[type=radio] {
            margin-top: 2px;
            flex-shrink: 0;
        }

        .wpb-model-name {
            font-size: 12px;
            font-weight: 600;
            color: #1d2327;
            margin-bottom: 1px;
        }

        .wpb-model-desc {
            font-size: 11px;
            color: #646970;
        }

        .wpb-model-id {
            font-size: 10px;
            color: #a7aaad;
            font-family: Consolas, monospace;
        }

        .wpb-model-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }

        .wpb-model-status {
            font-size: 11px;
            color: #646970;
            flex: 1;
        }

        .wpb-model-status.ok {
            color: #00a32a;
        }

        .wpb-model-status.err {
            color: #d63638;
        }

        /* Toggle switch */
        .wpb-toggle-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            background: #fff;
        }

        .wpb-toggle {
            position: relative;
            width: 40px;
            height: 22px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .wpb-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .wpb-toggle-slider {
            position: absolute;
            inset: 0;
            background: #a7aaad;
            border-radius: 22px;
            cursor: pointer;
            transition: background .2s;
        }

        .wpb-toggle-slider:before {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            left: 3px;
            top: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform .2s;
        }

        .wpb-toggle input:checked+.wpb-toggle-slider {
            background: #00a32a;
        }

        .wpb-toggle input:checked+.wpb-toggle-slider:before {
            transform: translateX(18px);
        }

        .wpb-toggle-info {
            flex: 1;
        }

        .wpb-toggle-title {
            font-size: 13px;
            font-weight: 600;
            color: #1d2327;
            margin-bottom: 3px;
        }

        .wpb-toggle-desc {
            font-size: 12px;
            color: #646970;
            line-height: 1.5;
        }

        /* Toast */
        #wpb-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #1d2327;
            color: #fff;
            padding: 10px 18px;
            border-radius: 3px;
            font-size: 13px;
            font-weight: 500;
            z-index: 9999;
            opacity: 0;
            transform: translateY(6px);
            transition: all .2s;
            pointer-events: none;
            border-left: 4px solid #00a32a;
        }

        #wpb-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        #wpb-toast.error {
            border-left-color: #d63638;
        }
    </style>

    <div id="wpbarrio-wrap" class="wrap">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-format-image"
                style="font-size:26px;width:26px;height:26px;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
            WP Barrio
            <span style="font-size:15px;font-weight:400;color:#646970;margin-left:6px;">/ Auto Alt</span>
            <?php if ($auto_upload): ?>
                <span
                    style="display:inline-block;background:#00a32a;color:#fff;font-size:11px;font-weight:600;padding:2px 10px;border-radius:3px;vertical-align:middle;margin-left:8px;line-height:1.6;">&#x2713;
                    Auto ON</span>
            <?php endif; ?>
        </h1>
        <hr class="wp-header-end" style="margin-bottom:20px;">

        <!-- Stats -->
        <div class="wpb-stats-bar">
            <div class="wpb-stat">
                <div class="wpb-stat-num">
                    <?php echo number_format($total_images); ?>
                </div>
                <div class="wpb-stat-lbl">Totale immagini</div>
            </div>
            <div class="wpb-stat">
                <div class="wpb-stat-num red">
                    <?php echo number_format($total_items); ?>
                </div>
                <div class="wpb-stat-lbl">Senza alt text</div>
            </div>
            <div class="wpb-stat">
                <div class="wpb-stat-num green">
                    <?php echo number_format($total_images - $total_items); ?>
                </div>
                <div class="wpb-stat-lbl">Completate</div>
            </div>
        </div>

        <!-- Nav tabs -->
        <nav class="nav-tab-wrapper wpb-nav-tab-wrapper">
            <a href="?page=wp-barrio-auto-alt&tab=review"
                class="nav-tab <?php echo $tab === 'review' ? 'nav-tab-active' : ''; ?>">Revisione</a>
            <a href="?page=wp-barrio-auto-alt&tab=log"
                class="nav-tab <?php echo $tab === 'log' ? 'nav-tab-active' : ''; ?>">Log</a>
            <a href="?page=wp-barrio-auto-alt&tab=settings"
                class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">Impostazioni</a>
        </nav>


        <?php

        /* ══════════════════════════════════════
           TAB: REVISIONE
           ══════════════════════════════════════ */
        if ($tab === 'review'):

            if (empty($api_key)): ?>
                <div class="notice notice-warning inline wpb-notice-api">
                    ⚠️ Chiave API non configurata. <a href="?page=wp-barrio-auto-alt&tab=settings">Vai alle impostazioni →</a>
                </div>
            <?php endif;

            $job = wp_alt_ai_get_job();
            $job_running = $job && $job['status'] === 'running';
            $job_done = $job && $job['status'] === 'done';
            ?>
            <?php if (!empty($attachments) || $job_running): ?>
                <div class="wpb-bulk">
                    <?php if ($job_running): ?>
                        <strong id="bulk-status-text">
                            Elaborazione in corso:
                            <?php echo ($job['done'] + $job['errors']); ?> /
                            <?php echo $job['total']; ?>
                            immagini
                        </strong>
                        <button class="wpb-btn-bulk" id="bulk-stop-btn" style="background:#d63638;color:#fff;">
                            ✕ Interrompi
                        </button>
                    <?php else: ?>
                        <strong>
                            <?php echo $total_items; ?> immagini senza alt o title
                        </strong>
                        <button class="wpb-btn-bulk" id="bulk-generate-btn" <?php echo empty($api_key) ? 'disabled' : ''; ?>>
                            ✦ Genera e salva tutte in background (
                            <?php echo $total_items; ?>)
                        </button>
                    <?php endif; ?>
                </div>
                <div id="bulk-progress-wrap"
                    style="margin-bottom:16px;background:#f0f0f1;border:1px solid #c3c4c7;border-radius:3px;overflow:hidden;<?php echo $job_running || $job_done ? '' : 'display:none;'; ?>">
                    <?php
                    $pct = 0;
                    if ($job && $job['total'] > 0) {
                        $pct = min(100, round((($job['done'] + $job['errors']) / $job['total']) * 100));
                    }
                    ?>
                    <div id="bulk-progress-bar"
                        style="height:5px;background:<?php echo $job_done ? '#00a32a' : '#2271b1'; ?>;width:<?php echo $pct; ?>%;transition:width .4s;">
                    </div>
                    <div id="bulk-progress-text" style="font-size:12px;color:#646970;padding:6px 12px 8px;">
                        <?php if ($job_running): ?>
                            <?php echo ($job['done'] + $job['errors']); ?> /
                            <?php echo $job['total']; ?> elaborate —
                            <?php echo $job['done']; ?> ✓
                            <?php echo $job['errors'] > 0 ? '  ' . $job['errors'] . ' ✗' : ''; ?>
                        <?php elseif ($job_done): ?>
                            ✓ Completato —
                            <?php echo $job['done']; ?>
                            salvate
                            <?php echo $job['errors'] > 0 ? ', ' . $job['errors'] . ' errori (vedi Log)' : ''; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif;

            if (empty($attachments)): ?>
                <div class="notice notice-success inline"
                    style="margin:0;padding:12px 16px;display:flex;align-items:center;gap:12px;">
                    <span class="dashicons dashicons-yes-alt"
                        style="font-size:22px;width:22px;height:22px;color:#00a32a;flex-shrink:0;margin-top:1px;"></span>
                    <div>
                        <strong>Tutto a posto!</strong>
                        Tutte le immagini nella libreria media hanno già alt text e titolo. Nessuna azione richiesta.
                    </div>
                </div>
            <?php else: ?>
                <div class="wpb-grid" id="altai-grid">
                    <?php foreach ($attachments as $att):
                        $thumb = wp_get_attachment_image_url($att->ID, 'medium');
                        $full = wp_get_attachment_url($att->ID);
                        $alt = get_post_meta($att->ID, '_wp_attachment_image_alt', true);
                        $title = $att->post_title;
                        $fname = basename(get_attached_file($att->ID));
                        ?>
                        <div class="wpb-card" data-id="<?php echo $att->ID; ?>" data-url="<?php echo esc_attr($full); ?>">
                            <div class="wpb-card__thumb">
                                <?php if ($thumb): ?>
                                    <img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <div style="display:flex;align-items:center;justify-content:center;height:100%;font-size:32px">🖼️</div>
                                <?php endif; ?>
                                <span class="wpb-card__ai-badge">AI</span>
                            </div>
                            <div class="wpb-card__body">
                                <div class="wpb-card__filename">
                                    <?php echo esc_html($fname); ?>
                                </div>
                                <div class="wpb-field">
                                    <label>Alt Text</label>
                                    <input type="text" class="alt-input" value="<?php echo esc_attr($alt); ?>"
                                        placeholder="Descrizione immagine…">
                                </div>
                                <div class="wpb-field">
                                    <label>Title</label>
                                    <input type="text" class="title-input" value="<?php echo esc_attr($title); ?>"
                                        placeholder="Titolo immagine…">
                                </div>
                                <div class="wpb-card__actions">
                                    <button class="wpb-btn-gen" <?php echo empty($api_key) ? 'disabled' : ''; ?>>✦ Genera e
                                        salva</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="wpb-pagination">
                        <div class="pagination-links">
                            <?php echo paginate_links([
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'current' => $paged,
                                'total' => $total_pages,
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'type' => 'plain',
                            ]); ?>
                        </div>
                    </div>
                <?php endif;
            endif;

            /* ══════════════════════════════════════
               TAB: LOG
               ══════════════════════════════════════ */
        elseif ($tab === 'log'):

            if (!get_option('wp_alt_ai_log_enabled', 1)): ?>
                <div class="notice notice-warning inline">
                    <p>
                        <strong>Registrazione log disabilitata.</strong>
                        Le operazioni non vengono registrate. Per riattivare i log vai alle
                        <a href="?page=wp-barrio-auto-alt&tab=settings">Impostazioni</a>.
                    </p>
                </div>
            <?php else:

                if (isset($_POST['clear_log']) && check_admin_referer('wp_alt_ai_clear_log')) {
                    delete_option(WP_ALT_AI_LOG_OPTION);
                    $log_entries = $log_slice = [];
                    $log_total = $log_success = $log_errors = $log_manual = 0;
                    echo '<div class="notice notice-success inline">✓ Log svuotato con successo.</div>';
                }
                ?>

                <div class="wpb-log-stats">
                    <div class="wpb-log-stat">
                        <div class="n">
                            <?php echo $log_total; ?>
                        </div>
                        <div class="l">Totale operazioni</div>
                    </div>
                    <div class="wpb-log-stat">
                        <div class="n green">
                            <?php echo $log_success; ?>
                        </div>
                        <div class="l">Completate</div>
                    </div>
                    <div class="wpb-log-stat">
                        <div class="n red">
                            <?php echo $log_errors; ?>
                        </div>
                        <div class="l">Errori</div>
                    </div>

                </div>

                <div class="wpb-log-toolbar">
                    <div class="wpb-log-filters">
                        <button class="wpb-log-filter active" data-filter="all">Tutti</button>
                        <button class="wpb-log-filter" data-filter="success">Successi</button>
                        <button class="wpb-log-filter" data-filter="error">Errori</button>
                    </div>
                    <form method="post" onsubmit="return confirm('Svuotare tutto il log? L\'operazione è irreversibile.');"
                        style="display:inline">
                        <?php wp_nonce_field('wp_alt_ai_clear_log'); ?>
                        <button type="submit" name="clear_log" class="wpb-btn-clear">🗑 Svuota log</button>
                    </form>
                </div>

                <?php if (empty($log_entries)): ?>
                    <div class="wpb-log-empty">Nessuna operazione registrata ancora.</div>
                <?php else: ?>
                    <div class="wpb-log-table-wrap">
                        <table class="wpb-log-table" id="log-table">
                            <thead>
                                <tr>
                                    <th style="width:44px"></th>
                                    <th>File</th>
                                    <th>Stato</th>
                                    <th>Alt generato</th>
                                    <th>Nota</th>
                                    <th>Data / ora</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $loop_i = 0;
                                foreach ($log_slice as $entry):
                                    $loop_i++;
                                    $thumb_url = wp_get_attachment_image_url($entry['id'], 'thumbnail');
                                    $edit_link = get_edit_post_link($entry['id']);
                                    $label_map = ['success' => '✓ Successo', 'error' => '✗ Errore', 'manual' => '✎ Manuale'];
                                    $is_err = ($entry['status'] === 'error');
                                    ?>
                                    <tr data-status="<?php echo esc_attr($entry['status']); ?>">
                                        <td>
                                            <?php if ($thumb_url): ?>
                                                <img src="<?php echo esc_url($thumb_url); ?>" class="wpb-log-thumb" alt="">
                                            <?php else: ?>
                                                <span style="font-size:22px">🖼️</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($edit_link): ?>
                                                <a href="<?php echo esc_url($edit_link); ?>" class="wpb-log-filename" target="_blank"
                                                    onclick="event.stopPropagation()">
                                                    <?php echo esc_html($entry['file']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="wpb-log-filename">
                                                    <?php echo esc_html($entry['file'] ?: '—'); ?>
                                                </span>
                                            <?php endif; ?>
                                            <div class="wpb-log-id">ID
                                                <?php echo intval($entry['id']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="wpb-log-status <?php echo esc_attr($entry['status']); ?>">
                                                <?php echo $label_map[$entry['status']] ?? esc_html($entry['status']); ?>
                                            </span>

                                        </td>
                                        <td>
                                            <div class="wpb-log-alt" title="<?php echo esc_attr($entry['alt']); ?>">
                                                <?php echo esc_html($entry['alt'] ?: '—'); ?>
                                            </div>
                                        </td>
                                        <td class="log-note-cell">
                                            <?php if ($is_err): ?>
                                                <div class="wpb-log-note wpb-log-note-error">
                                                    <?php echo esc_html($entry['note'] ?: '—'); ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="wpb-log-note">
                                                    <?php echo esc_html($entry['note'] ?: '—'); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="wpb-log-time">
                                                <?php echo date_i18n('d/m/Y H:i', $entry['ts']); ?>
                                            </span></td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($log_total_pages > 1): ?>
                        <div class="wpb-pagination" style="margin-top:20px">
                            <?php for ($i = 1; $i <= $log_total_pages; $i++):
                                $url = add_query_arg(['page' => 'wp-barrio-auto-alt', 'tab' => 'log', 'log_page' => $i]);
                                if ($i === $log_page): ?><span class="current">
                                        <?php echo $i; ?>
                                    </span>
                                <?php else: ?><a href="<?php echo esc_url($url); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endif; endfor; ?>
                        </div>
                    <?php endif;
                endif; // log entries
            endif; // log_enabled
    
            /* ══════════════════════════════════════
               TAB: IMPOSTAZIONI
               ══════════════════════════════════════ */
        elseif ($tab === 'settings'): ?>

            <style>
                .provider-card {
                    border: 1.5px solid #e8e8e8;
                    border-radius: 10px;
                    padding: 16px 18px;
                    cursor: pointer;
                    transition: border-color .15s, background .15s;
                    background: #fff;
                    display: flex;
                    align-items: flex-start;
                    gap: 14px;
                    margin: 0;
                }

                .provider-card.selected {
                    border-color: #1a1a1a;
                    background: #fafafa;
                }

                .provider-card input[type=radio] {
                    margin-top: 3px;
                    accent-color: #1a1a1a;
                    flex-shrink: 0;
                }

                .pc-logo {
                    font-size: 22px;
                    flex-shrink: 0;
                    width: 28px;
                    text-align: center;
                }

                .pc-name {
                    font-size: 13px;
                    font-weight: 600;
                    margin-bottom: 2px;
                }

                .pc-desc {
                    font-size: 12px;
                    color: #888;
                }

                .provider-keys {
                    display: none;
                    padding: 16px 18px 4px;
                    border: 1.5px solid #e8e8e8;
                    border-top: none;
                    border-radius: 0 0 10px 10px;
                    background: #fdfdfd;
                    margin-bottom: 0;
                }

                .provider-keys.visible {
                    display: block;
                }

                .key-group {
                    margin-bottom: 14px;
                }

                .key-group>label {
                    display: block;
                    font-size: 11px;
                    font-weight: 600;
                    color: #555;
                    margin-bottom: 5px;
                    text-transform: uppercase;
                    letter-spacing: 0.4px;
                }

                .key-group input[type=password] {
                    width: 100%;
                    padding: 8px 12px;
                    border: 1px solid #e0e0e0;
                    border-radius: 7px;
                    font-size: 13px;
                    font-family: monospace;
                    background: #fafafa;
                }

                .key-group input[type=password]:focus {
                    outline: none;
                    border-color: #1a1a1a;
                    background: #fff;
                }

                .key-group .hint {
                    font-size: 11px;
                    color: #aaa;
                    margin-top: 4px;
                }

                .key-group .hint a {
                    color: #888;
                }

                .model-radio-group {
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                }

                .model-radio-label {
                    display: flex;
                    align-items: flex-start;
                    gap: 12px;
                    padding: 10px 13px;
                    border: 1.5px solid #e8e8e8;
                    border-radius: 8px;
                    cursor: pointer;
                    background: #fff;
                    transition: border-color .15s, background .15s;
                    margin: 0;
                }

                .model-radio-label.selected {
                    border-color: #1a1a1a;
                    background: #fafafa;
                }

                .model-radio-label input[type=radio] {
                    margin-top: 2px;
                    accent-color: #1a1a1a;
                    flex-shrink: 0;
                }

                .mr-name {
                    font-size: 13px;
                    font-weight: 600;
                    margin-bottom: 2px;
                }

                .mr-desc {
                    font-size: 12px;
                    color: #888;
                }

                .mr-id {
                    font-size: 10px;
                    color: #bbb;
                    margin-top: 2px;
                    font-family: monospace;
                }

                .provider-block {
                    margin-bottom: 10px;
                }

                .provider-block:last-child {
                    margin-bottom: 0;
                }

                .altai-settings-form {
                    max-width: 620px;
                }
            </style>

            <form method="post" action="options.php">
                <?php settings_fields('wp_alt_ai_settings'); ?>
                <?php
                $provider = get_option('wp_alt_ai_provider', 'anthropic');
                $model = get_option('wp_alt_ai_model', 'claude-opus-4-6');
                $key_anthropic = get_option('wp_alt_ai_api_key', '');
                $key_gemini = get_option('wp_alt_ai_gemini_key', '');

                $providers = [
                    'anthropic' => [
                        'logo' => '🟣',
                        'name' => 'Anthropic — Claude',
                        'desc' => 'Claude Opus, Sonnet, Haiku — eccellente per descrizioni contestuali',
                        'key_field' => 'wp_alt_ai_api_key',
                        'key_val' => $key_anthropic,
                        'key_ph' => 'sk-ant-api03-...',
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
                        'logo' => '🔵',
                        'name' => 'Google — Gemini',
                        'desc' => 'Gemini 1.5 / 2.0 Flash & Pro — ottimo rapporto qualità/costo',
                        'key_field' => 'wp_alt_ai_gemini_key',
                        'key_val' => $key_gemini,
                        'key_ph' => 'AIza...',
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

                <!-- ── PROVIDER + MODELLO ── -->
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row" style="width:200px;">Provider e modello AI</th>
                            <td>
                                <div id="provider-list">
                                    <?php foreach ($providers as $pval => $pdata):
                                        $is_sel = ($provider === $pval);
                                        $p_models = $pdata['models'];
                                        // quale modello è attivo per questo provider?
                                        $active_model = ($is_sel && in_array($model, array_keys($p_models))) ? $model : $pdata['default_model'];
                                        ?>
                                        <div class="wpb-provider-block">
                                            <label class="wpb-provider-card <?php echo $is_sel ? 'selected' : ''; ?>"
                                                data-provider="<?php echo esc_attr($pval); ?>">
                                                <input type="radio" name="wp_alt_ai_provider" value="<?php echo esc_attr($pval); ?>"
                                                    <?php checked($provider, $pval); ?>>
                                                <span class="wpb-pc-logo">
                                                    <?php echo $pdata['logo']; ?>
                                                </span>
                                                <div>
                                                    <div class="wpb-pc-name">
                                                        <?php echo esc_html($pdata['name']); ?>
                                                    </div>
                                                    <div class="wpb-pc-desc">
                                                        <?php echo esc_html($pdata['desc']); ?>
                                                    </div>
                                                </div>
                                            </label>
                                            <div class="wpb-provider-keys <?php echo $is_sel ? 'visible' : ''; ?>"
                                                id="keys-<?php echo esc_attr($pval); ?>">
                                                <div class="wpb-key-group">
                                                    <label>
                                                        <?php echo esc_html($pdata['key_label']); ?>
                                                    </label>
                                                    <input type="password" name="<?php echo esc_attr($pdata['key_field']); ?>"
                                                        value="<?php echo esc_attr($pdata['key_val']); ?>"
                                                        placeholder="<?php echo esc_attr($pdata['key_ph']); ?>" autocomplete="off">
                                                    <div class="wpb-key-hint">Ottieni la chiave su <a
                                                            href="<?php echo esc_url($pdata['key_url']); ?>" target="_blank">
                                                            <?php echo esc_url($pdata['key_url']); ?>
                                                        </a></div>
                                                </div>
                                                <div class="wpb-key-group">
                                                    <label>Modello</label>
                                                    <div class="wpb-model-group">
                                                        <?php foreach ($p_models as $mval => $mdata):
                                                            $m_checked = ($active_model === $mval);
                                                            ?>
                                                            <label class="wpb-model-label <?php echo $m_checked ? 'selected' : ''; ?>">
                                                                <input type="radio" name="wp_alt_ai_model"
                                                                    value="<?php echo esc_attr($mval); ?>" <?php checked($active_model, $mval); ?>
                                                                <?php echo !$is_sel ? 'disabled' : ''; ?>>
                                                                <div>
                                                                    <div class="wpb-model-name">
                                                                        <?php echo esc_html($mdata['name']); ?>
                                                                    </div>
                                                                    <div class="wpb-model-desc">
                                                                        <?php echo esc_html($mdata['desc']); ?>
                                                                    </div>
                                                                    <div class="wpb-model-id">
                                                                        <?php echo esc_html($mval); ?>
                                                                    </div>
                                                                </div>
                                                            </label>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
        </div>

        <!-- ── LINGUA ── -->
        <div class="form-group">
            <label class="wpb-field-label">Lingua dell'output</label>
            <?php $lang = get_option('wp_alt_ai_language', 'it'); ?>
            <select name="wp_alt_ai_language">
                <option value="it" <?php selected($lang, 'it'); ?>>🇮🇹 Italiano</option>
                <option value="en" <?php selected($lang, 'en'); ?>>🇬🇧 English</option>
                <option value="es" <?php selected($lang, 'es'); ?>>🇪🇸 Español</option>
                <option value="fr" <?php selected($lang, 'fr'); ?>>🇫🇷 Français</option>
                <option value="de" <?php selected($lang, 'de'); ?>>🇩🇪 Deutsch</option>
            </select></td>
            </tr>

            <!-- ── AUTO UPLOAD ── -->
            <tr>
                <th scope="row">Generazione automatica su upload</th>
                <td>
                    <div class="wpb-toggle-row">
                        <label class="wpb-toggle">
                            <input type="checkbox" name="wp_alt_ai_auto_upload" value="1" <?php checked($auto_upload, 1); ?>>
                            <span class="wpb-toggle-slider"></span>
                        </label>
                        <div class="wpb-toggle-info">
                            <div class="wpb-toggle-title">
                                <?php echo $auto_upload ? '✓ Attiva — ogni nuova immagine viene elaborata automaticamente' : 'Disattiva — elabora le immagini manualmente dalla scheda Revisione'; ?>
                            </div>
                            <div class="wpb-toggle-desc">
                                Quando attivo, il provider selezionato analizza ogni nuova immagine caricata
                                nella Libreria Media e genera automaticamente alt text e title in background.
                                Ogni operazione viene registrata nel Log.
                            </div>
                        </div>
                    </div>
        </div>
        </td>
        </tr>

        <!-- ── LOG ── -->
        <tr>
            <th scope="row">Registrazione log</th>
            <td>
                <?php $log_enabled = get_option('wp_alt_ai_log_enabled', 1); ?>
                <div class="wpb-toggle-row">
                    <label class="wpb-toggle">
                        <input type="checkbox" name="wp_alt_ai_log_enabled" value="1" <?php checked($log_enabled, 1); ?>>
                        <span class="wpb-toggle-slider"></span>
                    </label>
                    <div class="wpb-toggle-info">
                        <div class="wpb-toggle-title">
                            <?php echo $log_enabled ? '✓ Attivo — ogni operazione viene registrata nel Log' : 'Disattivo — le operazioni non vengono registrate'; ?>
                        </div>
                        <div class="wpb-toggle-desc">
                            Se attivo, ogni generazione AI (singola, bulk e automatica) viene salvata nella scheda Log con stato
                            e dettagli.
                        </div>
                    </div>
                </div>
                </div>
            </td>
        </tr>
        </tbody>
        </table>

        <h2 class="title" style="margin-top:24px;">Impostazioni prompt</h2>
        <p class="description">Personalizza il tono e lo stile del testo generato dall'AI.</p>
        <table class="form-table" role="presentation">
            <tbody>

                <tr>
                    <th scope="row"><label for="wp_alt_ai_tone">Registro linguistico</label></th>
                    <td>
                        <?php $tone = get_option('wp_alt_ai_tone', 'neutral'); ?>
                        <select name="wp_alt_ai_tone" id="wp_alt_ai_tone">
                            <option value="neutral" <?php selected($tone, 'neutral'); ?>>Neutro e oggettivo</option>
                            <option value="professional" <?php selected($tone, 'professional'); ?>>Professionale e formale
                            </option>
                            <option value="friendly" <?php selected($tone, 'friendly'); ?>>Amichevole e accessibile</option>
                            <option value="technical" <?php selected($tone, 'technical'); ?>>Tecnico e preciso</option>
                            <option value="creative" <?php selected($tone, 'creative'); ?>>Creativo e coinvolgente</option>
                            <option value="seo" <?php selected($tone, 'seo'); ?>>Ottimizzato SEO</option>
                        </select>
                        <p class="description">Definisce il tono del testo dell'alt e del titolo.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="wp_alt_ai_style">Stile descrittivo</label></th>
                    <td>
                        <?php $style = get_option('wp_alt_ai_style', 'descriptive'); ?>
                        <select name="wp_alt_ai_style" id="wp_alt_ai_style">
                            <option value="descriptive" <?php selected($style, 'descriptive'); ?>>Descrittivo — elenca gli
                                elementi visivi</option>
                            <option value="contextual" <?php selected($style, 'contextual'); ?>>Contestuale — interpreta
                                significato e scopo</option>
                            <option value="emotional" <?php selected($style, 'emotional'); ?>>Evocativo — trasmette sensazioni
                                e
                                atmosfera</option>
                            <option value="journalistic" <?php selected($style, 'journalistic'); ?>>Giornalistico — sintetico
                                come una didascalia</option>
                        </select>
                        <p class="description">Definisce l'approccio con cui l'AI descrive l'immagine.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="wp_alt_ai_context">Contesto del sito</label></th>
                    <td>
                        <input type="text" name="wp_alt_ai_context" id="wp_alt_ai_context"
                            value="<?php echo esc_attr(get_option('wp_alt_ai_context', '')); ?>" class="regular-text"
                            placeholder="es. e-commerce di moda sostenibile, blog di cucina italiana…">
                        <p class="description">Fornisce contesto all'AI per generare testi più pertinenti al tuo settore.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="wp_alt_ai_keywords">Parole chiave SEO</label></th>
                    <td>
                        <input type="text" name="wp_alt_ai_keywords" id="wp_alt_ai_keywords"
                            value="<?php echo esc_attr(get_option('wp_alt_ai_keywords', '')); ?>" class="regular-text"
                            placeholder="es. scarpe artigianali, pelle italiana, handmade…">
                        <p class="description">Parole chiave da includere nell'alt text se pertinenti all'immagine. Separate da
                            virgola.</p>
                    </td>
                </tr>

            </tbody>
        </table>

        <?php submit_button('Salva impostazioni', 'primary', 'submit', false); ?>
        </form>

        <script>
            (function () {
                var ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
                var nonce = '<?php echo wp_create_nonce("wp_alt_ai_nonce"); ?>';
                var currentModel = '<?php echo esc_js(get_option("wp_alt_ai_model", "")); ?>';

                var modelDefaults = {
                    anthropic: 'claude-opus-4-6',
                    gemini: 'gemini-2.5-flash'
                };

                function updateModelHighlight() {
                    document.querySelectorAll('.wpb-model-label').forEach(function (lbl) {
                        var r = lbl.querySelector('input[type=radio]');
                        lbl.classList.toggle('selected', r && r.checked && !r.disabled);
                    });
                }

                // Costruisce i radio del modello dentro un panel
                function renderModels(panel, models, selectedId) {
                    var group = panel.querySelector('.wpb-model-group');
                    if (!group) return;
                    var prov = panel.id.replace('keys-', '');
                    var chosen = selectedId || modelDefaults[prov] || Object.keys(models)[0];

                    group.innerHTML = '';
                    Object.keys(models).forEach(function (id) {
                        var m = models[id];
                        var chk = (id === chosen);
                        var lbl = document.createElement('label');
                        lbl.className = 'wpb-model-label' + (chk ? ' selected' : '');
                        lbl.innerHTML =
                            '<input type="radio" name="wp_alt_ai_model" value="' + id + '"' +
                            (chk ? ' checked' : '') + ' style="margin-top:2px;accent-color:#1a1a1a;flex-shrink:0;">' +
                            '<div>' +
                            '<div class="wpb-model-name">' + (m.name || id) + '</div>' +
                            (m.desc ? '<div class="wpb-model-desc">' + m.desc + '</div>' : '') +
                            '<div class="wpb-model-id">' + id + '</div>' +
                            '</div>';
                        lbl.querySelector('input').addEventListener('change', updateModelHighlight);
                        group.appendChild(lbl);
                    });
                    updateModelHighlight();
                }

                // Carica i modelli dal server per un provider
                function loadModels(provider, panel, force) {
                    var statusEl = panel.querySelector('.wpb-model-status');
                    if (statusEl) statusEl.textContent = 'Caricamento modelli...';

                    var body = new URLSearchParams({
                        action: 'wp_alt_ai_fetch_models',
                        nonce: nonce,
                        provider: provider,
                        force: force ? '1' : ''
                    });

                    fetch(ajaxUrl, { method: 'POST', body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (statusEl) statusEl.textContent = '';
                            if (data.success && data.data && Object.keys(data.data).length > 0) {
                                // Scegli modello: quello corrente se esiste tra i nuovi, altrimenti default
                                var sel = (currentModel && data.data[currentModel]) ? currentModel : null;
                                renderModels(panel, data.data, sel);
                                if (statusEl) {
                                    var n = Object.keys(data.data).length;
                                    statusEl.textContent = '✓ ' + n + ' modelli disponibili';
                                    statusEl.style.color = '#16a34a';
                                }
                            } else {
                                if (statusEl) {
                                    statusEl.textContent = data.data || 'Errore nel caricamento modelli — uso lista offline';
                                    statusEl.style.color = '#dc2626';
                                }
                            }
                        })
                        .catch(function () {
                            if (statusEl) {
                                statusEl.textContent = 'Errore di rete — uso lista offline';
                                statusEl.style.color = '#dc2626';
                            }
                        });
                }

                // Aggiungi barra stato + pulsante refresh a ogni panel
                document.querySelectorAll('.wpb-provider-keys').forEach(function (panel) {
                    var group = panel.querySelector('.wpb-model-group');
                    if (!group) return;
                    var bar = document.createElement('div');
                    bar.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:8px;';
                    bar.innerHTML =
                        '<span class="wpb-model-status" style="font-size:11px;color:#888;flex:1;"></span>' +
                        '<button type="button" class="button button-small btn-refresh-models" style="font-size:11px;padding:3px 10px;background:#fff;border:1px solid #e0e0e0;border-radius:5px;cursor:pointer;font-family:inherit;">↻ Aggiorna</button>';
                    group.parentNode.insertBefore(bar, group);

                    bar.querySelector('.btn-refresh-models').addEventListener('click', function () {
                        var prov = panel.id.replace('keys-', '');
                        currentModel = ''; // forza nuova selezione
                        loadModels(prov, panel, true);
                    });
                });

                // Al cambio provider: mostra/nasconde pannello e carica modelli
                document.querySelectorAll('input[name="wp_alt_ai_provider"]').forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        var sel = this.value;

                        document.querySelectorAll('.wpb-provider-card').forEach(function (c) {
                            c.classList.toggle('selected', c.dataset.provider === sel);
                        });

                        document.querySelectorAll('.wpb-provider-keys').forEach(function (panel) {
                            var prov = panel.id.replace('keys-', '');
                            var active = (prov === sel);
                            panel.classList.toggle('visible', active);
                            panel.querySelectorAll('input[type=radio][name="wp_alt_ai_model"]').forEach(function (r) {
                                r.disabled = !active;
                            });
                            if (active) {
                                currentModel = '';
                                loadModels(prov, panel, false);
                            }
                        });
                    });
                });

                document.querySelectorAll('.model-radio-label input[type=radio]').forEach(function (r) {
                    r.addEventListener('change', updateModelHighlight);
                });
                updateModelHighlight();

                // Carica subito i modelli del provider attivo (classe wpb-provider-keys visible)
                document.querySelectorAll('.wpb-provider-keys.visible').forEach(function (panel) {
                    var prov = panel.id.replace('keys-', '');
                    loadModels(prov, panel, false);
                });

            })();
        </script>

    <?php endif; ?>

    </div><!-- #wpbarrio-wrap -->

    <div id="wpb-toast"></div>

    <script>
        (function () {
            const apiNonce = '<?php echo wp_create_nonce("wp_alt_ai_nonce"); ?>';
            const ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';

            function toast(msg, type = 'success') {
                const t = document.getElementById('wpb-toast');
                t.textContent = msg;
                t.className = 'show' + (type === 'error' ? ' error' : '');
                setTimeout(() => t.className = '', 3000);
            }

            /* ── Genera e salva in un unico roundtrip server-side ── */
            async function generateForCard(card) {
                const btn = card.querySelector('.wpb-btn-gen');
                btn.disabled = true;
                btn.innerHTML = '<div class="wpb-spinner"></div> Elaborazione…';

                const body = new URLSearchParams({
                    action: 'wp_alt_ai_generate',
                    nonce: apiNonce,
                    attachment_id: card.dataset.id,
                    save: '1',
                });

                try {
                    const res = await fetch(ajaxUrl, { method: 'POST', body });
                    const data = await res.json();

                    if (!data.success) {
                        throw new Error(data.data || 'Errore sconosciuto');
                    }

                    // Aggiorna i campi visibili nella card
                    const altIn = card.querySelector('.alt-input');
                    const titIn = card.querySelector('.title-input');
                    if (altIn) altIn.value = data.data.alt || '';
                    if (titIn) titIn.value = data.data.title || '';
                    card.classList.add('ai-generated', 'is-saved');

                    // Rimuovi la card con animazione
                    setTimeout(() => {
                        card.style.transition = 'opacity 0.5s, transform 0.5s';
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.95)';
                        setTimeout(() => card.remove(), 500);
                    }, 600);

                } catch (e) {
                    toast('Errore: ' + e.message, 'error');
                    btn.innerHTML = '✦ Genera e salva';
                    btn.disabled = false;
                }
            }



            document.querySelectorAll('.wpb-btn-gen').forEach(b => b.addEventListener('click', () => generateForCard(b.closest('.wpb-card'))));

            // ── Gestione job bulk background ──────────────────────────────────────
            let pollInterval = null;

            function startPolling() {
                if (pollInterval) return;
                pollInterval = setInterval(pollJobStatus, 4000);
            }
            function stopPolling() {
                if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
            }

            async function pollJobStatus() {
                try {
                    const res = await fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'wp_alt_ai_job_status', nonce: apiNonce }) });
                    const data = await res.json();
                    if (!data.success) { stopPolling(); return; }

                    const job = data.data;
                    const wrap = document.getElementById('bulk-progress-wrap');
                    const bar = document.getElementById('bulk-progress-bar');
                    const txt = document.getElementById('bulk-progress-text');
                    const stxt = document.getElementById('bulk-status-text');

                    if (!wrap) { stopPolling(); return; }

                    const processed = (job.done || 0) + (job.errors || 0);
                    const total = job.total || 0;
                    const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;

                    wrap.style.display = 'block';
                    bar.style.width = pct + '%';

                    if (job.status === 'running') {
                        if (stxt) stxt.textContent = `Elaborazione in corso: ${processed} / ${total} immagini`;
                        txt.textContent = `${processed} / ${total} elaborate — ${job.done || 0} ✓${job.errors > 0 ? '  ' + job.errors + ' ✗' : ''}`;
                        bar.style.background = '#2271b1';
                    } else if (job.status === 'done') {
                        bar.style.width = '100%';
                        bar.style.background = job.errors > 0 ? '#d63638' : '#00a32a';
                        txt.textContent = `✓ Completato — ${job.done || 0} salvate${job.errors > 0 ? ', ' + job.errors + ' errori (vedi Log)' : ''}`;
                        if (stxt) stxt.textContent = 'Elaborazione completata';
                        stopPolling();
                        // Cancella il job sul server, poi ricarica una sola volta
                        setTimeout(async () => {
                            await fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'wp_alt_ai_dismiss_job', nonce: apiNonce }) });
                            location.reload();
                        }, 2500);
                    } else {
                        stopPolling();
                    }
                } catch (_) { /* silently ignore network errors during polling */ }
            }

                // Avvia polling se c'è un job in corso al caricamento della pagina
                <?php $job = wp_alt_ai_get_job(); ?>
                <?php if ($job && $job['status'] === 'running'): ?>
                    startPolling();
                <?php elseif ($job && $job['status'] === 'done'): ?>
                    // Job completato: pulisci dopo 4s senza ricaricare in loop
                    setTimeout(function () {
                        fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'wp_alt_ai_dismiss_job', nonce: apiNonce }) })
                            .then(function () { location.reload(); });
                    }, 4000);
                <?php endif; ?>

                const bulkGenBtn = document.getElementById('bulk-generate-btn');
            if (bulkGenBtn) {
                bulkGenBtn.addEventListener('click', async () => {
                    bulkGenBtn.disabled = true;
                    bulkGenBtn.innerHTML = '<div class="wpb-spinner" style="border-top-color:#1d2327;border-color:rgba(0,0,0,.15);display:inline-block;margin-right:6px;vertical-align:middle;width:12px;height:12px;"></div> Avvio…';

                    try {
                        const res = await fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'wp_alt_ai_bulk_start', nonce: apiNonce }) });
                        const data = await res.json();
                        if (!data.success) throw new Error(data.data || 'Errore avvio job');

                        toast('✓ Elaborazione avviata in background — puoi continuare a lavorare');
                        // Ricarica per mostrare la barra di stato
                        setTimeout(() => location.reload(), 800);
                    } catch (err) {
                        toast('Errore: ' + err.message, 'error');
                        bulkGenBtn.disabled = false;
                        bulkGenBtn.innerHTML = '✦ Genera e salva tutte in background';
                    }
                });
            }

            const bulkStopBtn = document.getElementById('bulk-stop-btn');
            if (bulkStopBtn) {
                bulkStopBtn.addEventListener('click', async () => {
                    if (!confirm('Interrompere l\'elaborazione in corso?')) return;
                    bulkStopBtn.disabled = true;
                    await fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'wp_alt_ai_bulk_stop', nonce: apiNonce }) });
                    stopPolling();
                    location.reload();
                });
            }



            /* ── Provider switcher: mostra/nascondi chiave e modelli ── */
            function switchProvider(pval) {
                document.querySelectorAll('.provider-key-group, .provider-model-group').forEach(el => {
                    el.style.display = el.dataset.provider === pval ? '' : 'none';
                });
                // Seleziona automaticamente il primo modello del provider appena attivato
                const activeGroup = document.querySelector('.provider-model-group[data-provider="' + pval + '"]');
                if (activeGroup) {
                    const radios = activeGroup.querySelectorAll('input[type=radio]');
                    const anyChecked = [...radios].some(r => r.checked);
                    if (!anyChecked && radios.length) radios[0].checked = true;
                    syncModelLabels(activeGroup);
                }
                // Highlight provider labels
                document.querySelectorAll('.provider-label').forEach(lbl => {
                    const checked = lbl.querySelector('input').checked;
                    lbl.style.borderColor = checked ? '#1a1a1a' : '#e8e8e8';
                    lbl.style.background = checked ? '#fafafa' : '#fff';
                });
            }
            document.querySelectorAll('input[name="wp_alt_ai_provider"]').forEach(radio => {
                radio.addEventListener('change', () => switchProvider(radio.value));
            });

            /* ── Highlight model radio on click ── */
            function syncModelLabels(scope) {
                (scope || document).querySelectorAll('.model-option-label').forEach(lbl => {
                    const checked = lbl.querySelector('input').checked;
                    lbl.style.borderColor = checked ? '#1a1a1a' : '#e8e8e8';
                    lbl.style.background = checked ? '#fafafa' : '#fff';
                });
            }
            document.querySelectorAll('input[name="wp_alt_ai_model"]').forEach(radio => {
                radio.addEventListener('change', () => syncModelLabels());
            });

            /* ── Filtro log ── */
            document.querySelectorAll('.wpb-log-filter').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.wpb-log-filter').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    const filter = btn.dataset.filter;
                    document.querySelectorAll('#log-table tbody tr').forEach(row => {
                        row.style.display = (filter === 'all' || row.dataset.status === filter) ? '' : 'none';
                    });
                });
            });
        })();
    </script>
    <?php
}

/* ─────────────────────────────────────────
   6. AJAX: salva alt + title + log
   ───────────────────────────────────────── */
add_action('wp_ajax_wp_alt_ai_save', function () {
    check_ajax_referer('wp_alt_ai_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $id = intval($_POST['attachment_id'] ?? 0);
    $alt = sanitize_text_field($_POST['alt_text'] ?? '');
    $title = sanitize_text_field($_POST['title'] ?? '');
    $source = sanitize_key($_POST['source'] ?? 'manual');

    if (!$id || get_post_type($id) !== 'attachment')
        wp_send_json_error('ID non valido.');

    update_post_meta($id, '_wp_attachment_image_alt', $alt);
    wp_update_post(['ID' => $id, 'post_title' => $title]);

    $status = ($source === 'ai') ? 'success' : 'manual';
    $note = ($source === 'ai') ? 'Generato con AI, salvato dalla revisione' : 'Salvato manualmente dalla revisione';
    wp_alt_ai_log($id, $status, $alt, $title, $note);

    wp_send_json_success(['id' => $id]);
});

/* ─────────────────────────────────────────
   7. AJAX: genera alt+title via Claude (server-side, chiave sicura)
   ───────────────────────────────────────── */
add_action('wp_ajax_wp_alt_ai_generate', function () {
    check_ajax_referer('wp_alt_ai_nonce', 'nonce');
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
        wp_alt_ai_log($id, 'error', '', '', 'Impossibile leggere il file immagine');
        wp_send_json_error('Impossibile leggere il file immagine.');
    }

    $mime = get_post_mime_type($id) ?: 'image/jpeg';
    $lang = get_option('wp_alt_ai_language', 'it');
    $lang_map = ['it' => 'italiano', 'en' => 'English', 'es' => 'español', 'fr' => 'français', 'de' => 'Deutsch'];
    $lang_name = $lang_map[$lang] ?? 'italiano';

    // Rimuovi il controllo chiave API singola — ora gestito dentro wp_alt_ai_call_api()
    $result = wp_alt_ai_call_api($id, $image_data, $mime, $lang_name);

    if (isset($result['error'])) {
        wp_alt_ai_log($id, 'error', '', '', $result['error']);
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
        $note .= ' — auto-salvato';
    }

    wp_alt_ai_log($id, 'success', $result['alt'], $result['title'], $note);

    wp_send_json_success([
        'alt' => $result['alt'],
        'title' => $result['title'],
        'saved' => $save,
    ]);
});

/* ─────────────────────────────────────────
   8. AJAX: restituisce tutti gli ID immagini senza alt/title
   ───────────────────────────────────────── */
add_action('wp_ajax_wp_alt_ai_get_missing_ids', function () {
    check_ajax_referer('wp_alt_ai_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $ids = wp_alt_ai_get_incomplete_ids();
    wp_send_json_success(array_map('intval', $ids));
});

/* ─────────────────────────────────────────
   9. AJAX: avvia job bulk in background
   ───────────────────────────────────────── */
add_action('wp_ajax_wp_alt_ai_bulk_start', function () {
    check_ajax_referer('wp_alt_ai_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    // Se c'è già un job attivo non ne avviare un altro
    $existing = wp_alt_ai_get_job();
    if ($existing && $existing['status'] === 'running') {
        wp_send_json_error('Elaborazione già in corso.');
    }

    $ids = wp_alt_ai_get_incomplete_ids();
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
    wp_alt_ai_save_job($job);

    // Schedula il primo batch immediatamente
    wp_schedule_single_event(time(), WP_ALT_AI_CRON_HOOK);

    // Esegui il cron subito (senza attendere la visita successiva)
    wp_remote_get(add_query_arg('doing_wp_cron', '1', site_url('wp-cron.php')), ['timeout' => 0.01, 'blocking' => false, 'sslverify' => false]);

    wp_send_json_success(['total' => $job['total']]);
});

/* ─────────────────────────────────────────
   10. AJAX: stato del job in corso (polling)
   ───────────────────────────────────────── */
add_action('wp_ajax_wp_alt_ai_job_status', function () {
    check_ajax_referer('wp_alt_ai_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $job = wp_alt_ai_get_job();
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
add_action('wp_ajax_wp_alt_ai_bulk_stop', function () {
    check_ajax_referer('wp_alt_ai_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    wp_alt_ai_clear_job();
    wp_send_json_success();
});

/* ─────────────────────────────────────────
   12. AJAX: dismiss notifica job completato
   ───────────────────────────────────────── */
add_action('wp_ajax_wp_alt_ai_dismiss_job', function () {
    check_ajax_referer('wp_alt_ai_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    wp_alt_ai_clear_job();
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

    $api_key  = wp_alt_ai_active_key();
    $disabled = empty($api_key) ? ' disabled' : '';

    // HTML ridotto al minimo: nessun attributo title, nessun campo helps,
    // nessun carattere non-ASCII. Qualsiasi testo extra passa attraverso
    // _.template() di Underscore e puo' rompere il modale Backbone.
    $html = '<button type="button"'
          . ' class="button wpb-gen-btn"'
          . ' data-id="' . esc_attr($post->ID) . '"'
          . $disabled . '>'
          . 'Genera con AI'
          . '</button>'
          . '<p class="wpb-gen-result" style="margin:6px 0 0;font-size:12px;min-height:16px;"></p>';

    $form_fields['wpb_auto_alt'] = [
        'label' => 'Auto Alt',
        'input' => 'html',
        'html'  => $html,
    ];

    return $form_fields;
}, 10, 2);

// Enqueue del file JS fisico + localizzazione dati (nonce, ajaxurl)
// Usare un file .js reale e wp_localize_script e' l'unico modo
// per passare dati al JS senza toccare i template Backbone.
add_action('admin_enqueue_scripts', function () {
    $js_url = WP_ALT_AI_PLUGIN_URL . 'modules/auto-alt/js/media-generate.js';
    $js_ver = WP_ALT_AI_VERSION;

    wp_enqueue_script(
        'wpb-media-generate',
        $js_url,
        ['jquery'],
        $js_ver,
        true   // in footer
    );

    wp_localize_script('wpb-media-generate', 'wpbAutoAlt', [
        'nonce'   => wp_create_nonce('wp_alt_ai_nonce'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
    ]);
});

/* ─────────────────────────────────────────
   14. AJAX: genera e salva FORZANDO sovrascrittura
       (usato dal pulsante nella pagina media)
   ───────────────────────────────────────── */
add_action('wp_ajax_wp_alt_ai_generate_force', function () {
    check_ajax_referer('wp_alt_ai_nonce', 'nonce');
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
        $image_url  = wp_get_attachment_url($id);
        $image_data = $image_url ? @file_get_contents($image_url) : false;
    }

    if (!$image_data) {
        wp_alt_ai_log($id, 'error', '', '', 'Media detail: impossibile leggere il file immagine');
        wp_send_json_error('Impossibile leggere il file immagine.');
    }

    $mime      = get_post_mime_type($id) ?: 'image/jpeg';
    $lang      = get_option('wp_alt_ai_language', 'it');
    $lang_map  = ['it' => 'italiano', 'en' => 'English', 'es' => 'español', 'fr' => 'français', 'de' => 'Deutsch'];
    $lang_name = $lang_map[$lang] ?? 'italiano';

    // Forza generazione di entrambi i campi ('both'), ignorando quelli già presenti
    $result = wp_alt_ai_call_api($id, $image_data, $mime, $lang_name, 'both');

    if (isset($result['error'])) {
        wp_alt_ai_log($id, 'error', '', '', 'Media detail: ' . $result['error']);
        wp_send_json_error($result['error']);
    }

    // Salva sovrascrivendo i valori esistenti
    update_post_meta($id, '_wp_attachment_image_alt', $result['alt']);
    wp_update_post(['ID' => $id, 'post_title' => $result['title']]);

    $provider = get_option('wp_alt_ai_provider', 'anthropic');
    $model    = get_option('wp_alt_ai_model', '');
    wp_alt_ai_log(
        $id,
        'success',
        $result['alt'],
        $result['title'],
        'Generato con AI (' . $provider . ($model ? ' / ' . $model : '') . ') — sovrascrittura forzata da pagina media'
    );

    wp_send_json_success([
        'alt'   => $result['alt'],
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
        $actions['wpb_generate_alt'] = 'Genera Alt + Title (AI)';
    }
    return $actions;
});

// Enqueue JS bulk solo sulla pagina libreria media
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'upload.php') return;

    wp_enqueue_script(
        'wpb-media-bulk',
        WP_ALT_AI_PLUGIN_URL . 'modules/auto-alt/js/media-bulk.js',
        ['jquery'],
        WP_ALT_AI_VERSION,
        true
    );

    wp_localize_script('wpb-media-bulk', 'wpbAutoAlt', [
        'nonce'   => wp_create_nonce('wp_alt_ai_nonce'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
    ]);
});
