<?php
/**
 * WP Barrio — SEO Advisor
 * Genera title, description e keywords tramite AI (Gemini o Anthropic)
 * analizzando il contenuto di qualsiasi post type o tassonomia.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   COSTANTI
   ───────────────────────────────────────── */
if (!defined('WPB_ADVISOR_LOG_OPTION'))
    define('WPB_ADVISOR_LOG_OPTION', 'wpb_advisor_log');
if (!defined('WPB_ADVISOR_LOG_MAX'))
    define('WPB_ADVISOR_LOG_MAX', 100);

/* ─────────────────────────────────────────
   MENU
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!wpb_tools_module_enabled('seo'))
        return;

    add_submenu_page(
        'wp-barrio',
        'SEO Advisor — WP Barrio',
        'SEO Advisor',
        'manage_options',
        'wp-barrio-seo-advisor',
        'wpb_advisor_admin_page'
    );
}, 16);

add_action('admin_head', function () {
    echo '<style>#adminmenu a[href="admin.php?page=wp-barrio-seo-advisor"]{display:none!important}</style>';
});

add_filter('submenu_file', function ($submenu_file) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    if (isset($_GET['page']) && $_GET['page'] === 'wp-barrio-seo-advisor')
        return 'wp-barrio-seo';
    return $submenu_file;
});

/* ─────────────────────────────────────────
   IMPOSTAZIONI
   ───────────────────────────────────────── */
add_action('admin_init', function () {
    register_setting('wpb_advisor_settings', 'wpb_advisor_provider', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'anthropic']);
    register_setting('wpb_advisor_settings', 'wpb_advisor_api_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wpb_advisor_settings', 'wpb_advisor_gemini_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wpb_advisor_settings', 'wpb_advisor_model', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wpb_advisor_settings', 'wpb_advisor_log_enabled', ['sanitize_callback' => 'absint', 'default' => 1]);
    // Impostazioni prompt
    register_setting('wpb_advisor_settings', 'wpb_advisor_language', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'it']);
    register_setting('wpb_advisor_settings', 'wpb_advisor_tone', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'seo']);
    register_setting('wpb_advisor_settings', 'wpb_advisor_style', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'contextual']);
    register_setting('wpb_advisor_settings', 'wpb_advisor_context', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
    register_setting('wpb_advisor_settings', 'wpb_advisor_keywords', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
});

/* ─────────────────────────────────────────
   HELPER: chiave API attiva
   ───────────────────────────────────────── */
function wpb_advisor_active_key(): string
{
    $provider = get_option('wpb_advisor_provider', 'anthropic');
    return $provider === 'gemini'
        ? get_option('wpb_advisor_gemini_key', '')
        : get_option('wpb_advisor_api_key', '');
}

/* ─────────────────────────────────────────
   HELPER: log
   ───────────────────────────────────────── */
function wpb_advisor_log(string $status, string $target, string $note): void
{
    if (!get_option('wpb_advisor_log_enabled', 1))
        return;
    $log = get_option(WPB_ADVISOR_LOG_OPTION, []);
    array_unshift($log, [
        'ts' => current_time('timestamp'),
        'status' => $status,
        'target' => $target,
        'note' => $note,
    ]);
    if (count($log) > WPB_ADVISOR_LOG_MAX)
        $log = array_slice($log, 0, WPB_ADVISOR_LOG_MAX);
    update_option(WPB_ADVISOR_LOG_OPTION, $log, false);
}

/* ─────────────────────────────────────────
   HELPER: costruisce il contenuto da analizzare
   ───────────────────────────────────────── */
function wpb_advisor_get_content(string $type, int $id): array
{
    if ($type === 'post') {
        $post = get_post($id);
        if (!$post)
            return ['error' => 'Post non trovato.'];
        return [
            'title' => get_the_title($post),
            'content' => wp_strip_all_tags($post->post_content),
            'excerpt' => wp_strip_all_tags(get_the_excerpt($post)),
            'url' => get_permalink($id),
            'type' => $post->post_type,
        ];
    }

    $term = get_term($id);
    if (!$term || is_wp_error($term))
        return ['error' => 'Termine non trovato.'];
    return [
        'title' => $term->name,
        'content' => wp_strip_all_tags($term->description),
        'excerpt' => '',
        'url' => get_term_link($term),
        'type' => $term->taxonomy,
    ];
}

/* ─────────────────────────────────────────
   HELPER: chiama l'API AI e ottiene i meta SEO
   ───────────────────────────────────────── */
function wpb_advisor_generate(array $content_data): array
{
    $provider = get_option('wpb_advisor_provider', 'anthropic');
    $api_key = wpb_advisor_active_key();
    $model = get_option('wpb_advisor_model', '');
    $s = get_option(WPB_META_OPTION, []);
    $sep = $s['separator'] ?? '—';
    $site = $s['site_title'] ?? get_bloginfo('name');

    if (empty($api_key))
        return ['error' => 'Chiave API non configurata.'];

    $title = $content_data['title'] ?? '';
    $content = mb_substr($content_data['content'] ?? '', 0, 3000);
    $excerpt = $content_data['excerpt'] ?? '';

    // Lingua: usa l'impostazione del modulo, fallback alla lingua del sito
    $lang_code = get_option('wpb_advisor_language', 'it');
    $lang_map = [
        'it' => 'italiano',
        'en' => 'English',
        'es' => 'español',
        'fr' => 'français',
        'de' => 'Deutsch',
    ];
    $lang = $lang_map[$lang_code] ?? get_bloginfo('language');

    // Tono / Stile / Contesto / Parole chiave (Impostazioni prompt)
    $tone_map = [
        'neutral' => 'neutro e oggettivo',
        'professional' => 'professionale e formale',
        'friendly' => 'amichevole e accessibile',
        'technical' => 'tecnico e preciso',
        'creative' => 'creativo e coinvolgente',
        'seo' => 'ottimizzato SEO',
    ];
    $style_map = [
        'descriptive' => 'descrittivo, elenca gli elementi visibili',
        'contextual' => 'contestuale, interpreta significato e scopo',
        'emotional' => 'evocativo, trasmette sensazioni e atmosfera',
        'journalistic' => 'giornalistico, sintetico come una didascalia',
    ];
    $tone_key = get_option('wpb_advisor_tone', 'seo');
    $style_key = get_option('wpb_advisor_style', 'contextual');
    $tone_str = $tone_map[$tone_key] ?? $tone_map['seo'];
    $style_str = $style_map[$style_key] ?? $style_map['contextual'];
    $context_str = trim((string) get_option('wpb_advisor_context', ''));
    $keywords_str = trim((string) get_option('wpb_advisor_keywords', ''));

    $extra_lines = '';
    if ($context_str !== '') {
        $extra_lines .= "Contesto del sito: {$context_str}\n";
    }
    if ($keywords_str !== '') {
        $extra_lines .= "Parole chiave da includere se pertinenti: {$keywords_str}\n";
    }

    $prompt = "Sei un esperto SEO. Analizza il contenuto e genera elementi SEO ottimizzati.\n\nSito: $site\nLingua: $lang\nRegistro linguistico: $tone_str\nStile: $style_str\n{$extra_lines}Titolo: $title\nExcerpt: $excerpt\nContenuto: $content\n\nGenera in $lang:\n1. SEO Title: max 60 caratteri, keyword primaria inclusa.\n2. SEO Description: 120-160 caratteri, keyword inclusa.\n3. Keywords: 5-8 keyword, la PRIMA e' la primaria.\n\nRispondi SOLO con JSON su UNA SOLA RIGA senza newline interni ne markdown:\n{\"title\":\"...\",\"description\":\"...\",\"keywords\":[\"kw1\",\"kw2\"]}";


    if ($provider === 'anthropic') {
        if (empty($model))
            $model = 'claude-opus-4-6';
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'max_tokens' => 2048,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                    ['role' => 'assistant', 'content' => '{'],  // prefill: forza risposta JSON
                ],
            ]),
        ]);

        if (is_wp_error($response))
            return ['error' => $response->get_error_message()];
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200)
            return ['error' => 'Errore API Anthropic (HTTP ' . $code . ').'];
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $text = '{' . ($data['content'][0]['text'] ?? '');  // il { è il prefill, va reintegrato

    } else {
        // Gemini
        if (empty($model))
            $model = 'gemini-2.0-flash';
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 2048],
            ]),
        ]);

        if (is_wp_error($response))
            return ['error' => $response->get_error_message()];
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200)
            return ['error' => 'Errore API Gemini (HTTP ' . $code . ').'];
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    // ── Estrazione JSON robusta ──────────────────────────────────────────
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
    $text = preg_replace('/\s*```$/m', '', $text);
    $text = trim($text);

    // Normalizza la risposta: rimuove tutto ciò che può rompere json_decode
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

    // 1. Sostituisce newline/tab letterali con spazio (fuori e dentro le stringhe)
    //    così il JSON rimane su una riga sola, sicuro da parsare
    $text = str_replace(["\r\n", "\r", "\n", "\t"], [' ', ' ', ' ', ' '], $text);

    // 2. Rimuove qualsiasi altro carattere di controllo rimasto
    $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);

    // 3. Normalizza spazi multipli
    $text = preg_replace('/\s{2,}/', ' ', $text);
    $text = trim($text);

    $parsed = null;

    // Tentativo 1: json_decode diretto
    $parsed = json_decode($text, true);

    // Tentativo 2: trova il primo { e la } bilanciata corrispondente
    if (!is_array($parsed)) {
        $start = strpos($text, '{');
        if ($start !== false) {
            $depth = 0;
            $in_str = false;
            $esc = false;
            $end = null;
            for ($i = $start; $i < strlen($text); $i++) {
                $c = $text[$i];
                if ($esc) {
                    $esc = false;
                    continue;
                }
                if ($c === '\\') {
                    $esc = true;
                    continue;
                }
                if ($c === '"') {
                    $in_str = !$in_str;
                    continue;
                }
                if ($in_str)
                    continue;
                if ($c === '{')
                    $depth++;
                elseif ($c === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            if ($end !== null) {
                $slice = substr($text, $start, $end - $start + 1);
                $parsed = json_decode($slice, true);
            }
        }
    }

    // Tentativo 3: normalizza le virgolette tipografiche e riprova
    if (!is_array($parsed)) {
        $clean = str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}", "\u{00AB}", "\u{00BB}"],
            ['"', '"', "'", "'", '"', '"'],
            $text
        );
        $parsed = json_decode($clean, true);
        if (!is_array($parsed)) {
            $start = strpos($clean, '{');
            $end = strrpos($clean, '}');
            if ($start !== false && $end !== false && $end > $start)
                $parsed = json_decode(substr($clean, $start, $end - $start + 1), true);
        }
    }

    if (!is_array($parsed) || empty($parsed['title'])) {
        $err_code = json_last_error();
        $err_msg = json_last_error_msg();
        return ['error' => 'Parsing JSON fallito (' . $err_msg . ' / code ' . $err_code . '): ' . mb_substr($text, 0, 300)];
    }

    return [
        'title' => sanitize_text_field($parsed['title'] ?? ''),
        'description' => sanitize_text_field($parsed['description'] ?? ''),
        'keywords' => array_map('sanitize_text_field', (array) ($parsed['keywords'] ?? [])),
    ];
}

/* ─────────────────────────────────────────
   AJAX: fetch modelli
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_advisor_fetch_models', function () {
    check_ajax_referer('wpb_advisor_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.', 403);

    $provider = sanitize_key($_POST['provider'] ?? 'anthropic');
    $force = !empty($_POST['force']);
    $cache_key = 'wpb_advisor_models_' . $provider;
    if ($force)
        delete_transient($cache_key);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        wp_send_json_success($cached);
        return;
    }

    $api_key = $provider === 'gemini'
        ? get_option('wpb_advisor_gemini_key', '')
        : get_option('wpb_advisor_api_key', '');
    if (empty($api_key)) {
        wp_send_json_success(['error' => 'Chiave API non configurata.']);
        return;
    }

    $models = [];
    if ($provider === 'anthropic') {
        $r = wp_remote_get('https://api.anthropic.com/v1/models', [
            'timeout' => 15,
            'headers' => ['x-api-key' => $api_key, 'anthropic-version' => '2023-06-01'],
        ]);
        if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
            $d = json_decode(wp_remote_retrieve_body($r), true);
            foreach ($d['data'] ?? [] as $m) {
                if (!empty($m['id']) && str_starts_with($m['id'], 'claude-'))
                    $models[] = $m['id'];
            }
        }
    } else {
        $r = wp_remote_get('https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key, ['timeout' => 15]);
        if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
            $d = json_decode(wp_remote_retrieve_body($r), true);
            foreach ($d['models'] ?? [] as $m) {
                $n = str_replace('models/', '', $m['name'] ?? '');
                if ($n && str_starts_with($n, 'gemini-'))
                    $models[] = $n;
            }
        }
    }

    set_transient($cache_key, $models, DAY_IN_SECONDS);
    wp_send_json_success($models);
});

/* ─────────────────────────────────────────
   AJAX: genera meta SEO
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_advisor_generate', function () {
    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- necessario per operazioni batch lunghe
    @set_time_limit(120);
    check_ajax_referer('wpb_advisor_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.', 403);

    $obj_type = sanitize_key($_POST['obj_type'] ?? 'post'); // 'post' | 'term'
    $obj_id = absint( wp_unslash( $_POST['obj_id'] ?? 0 ) );
    if (!$obj_id)
        wp_send_json_error('ID mancante.');

    $content_data = wpb_advisor_get_content($obj_type, $obj_id);
    if (isset($content_data['error'])) {
        wpb_advisor_log('error', $obj_type . ':' . $obj_id, $content_data['error']);
        wp_send_json_error($content_data['error']);
    }

    $result = wpb_advisor_generate($content_data);
    if (isset($result['error'])) {
        wpb_advisor_log('error', $content_data['title'], $result['error']);
        wp_send_json_error($result['error']);
    }

    $target_label = $content_data['title'];
    wpb_advisor_log('success', $target_label, 'Title: ' . $result['title']);

    wp_send_json_success(array_merge($result, [
        'content_title' => $content_data['title'],
        'content_url' => is_wp_error($content_data['url']) ? '' : $content_data['url'],
        'obj_type' => $obj_type,
        'obj_id' => $obj_id,
    ]));
});

/* ─────────────────────────────────────────
   AJAX: salva i meta SEO generati
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_advisor_save', function () {
    check_ajax_referer('wpb_advisor_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.', 403);

    $obj_type = sanitize_key($_POST['obj_type'] ?? 'post');
    $obj_id = absint( wp_unslash( $_POST['obj_id'] ?? 0 ) );
    $title = sanitize_text_field( wp_unslash( $_POST['seo_title'] ?? '' ) );
    $desc = sanitize_text_field( wp_unslash( $_POST['seo_description'] ?? '' ) );
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- contenuto JSON, passato a json_decode() subito dopo
    $kw_raw = wp_unslash($_POST['seo_keywords'] ?? '[]');
    $kws = json_decode($kw_raw, true);
    if (!is_array($kws))
        $kws = [];
    $kws = array_values(array_filter(array_map('sanitize_text_field', $kws)));

    if ($obj_type === 'post') {
        if ($title)
            update_post_meta($obj_id, '_wpb_seo_title', $title);
        if ($desc)
            update_post_meta($obj_id, '_wpb_seo_description', $desc);
        if (!empty($kws))
            update_post_meta($obj_id, '_wpb_seo_keywords', wp_json_encode($kws));
    } else {
        if ($title)
            update_term_meta($obj_id, '_wpb_seo_title', $title);
        if ($desc)
            update_term_meta($obj_id, '_wpb_seo_description', $desc);
        if (!empty($kws))
            update_term_meta($obj_id, '_wpb_seo_keywords', wp_json_encode($kws));
    }

    if (function_exists('invalidate_vercel_cache')) {
        if ($obj_type === 'post') {
            invalidate_vercel_cache($obj_id);
        } else {
            invalidate_vercel_cache('wp:global');
        }
    }

    wp_send_json_success(['message' => 'Meta SEO salvati con successo.']);
});

/* ─────────────────────────────────────────
   AJAX: svuota log
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_advisor_clear_log', function () {
    check_ajax_referer('wpb_advisor_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.', 403);
    delete_option(WPB_ADVISOR_LOG_OPTION);
    wp_send_json_success();
});

/* ─────────────────────────────────────────
   AJAX: lista contenuti (post type o tassonomia)
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_advisor_get_items', function () {
    check_ajax_referer('wpb_advisor_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.', 403);

    $source = sanitize_key($_POST['source'] ?? '');
    $search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );

    // Determina se WPML è attivo con lingue di traduzione
    $wpml_active = defined('ICL_SITEPRESS_VERSION')
        && function_exists('wpb_multilang_is_enabled')
        && wpb_multilang_is_enabled();
    $languages = $wpml_active ? wpb_multilang_languages() : [null => null];
    $lang_flags = $wpml_active && function_exists('wpb_multilang_languages_with_flags') ? wpb_multilang_languages_with_flags() : [];
    $primary = $wpml_active && function_exists('wpb_multilang_primary_language')
        ? wpb_multilang_primary_language() : null;

    $groups = []; // [ ['lang_code'=>, 'lang_label'=>, 'items'=>[...]] ]

    $pt_obj = get_post_type_object($source);

    foreach (array_keys($languages) as $lang_code) {

        $lang_label = $wpml_active
            ? ($lang_flags[$lang_code] ?? $languages[$lang_code] ?? strtoupper((string) $lang_code))
            : null;

        $items = [];

        if ($pt_obj) {
            // Post type
            if ($wpml_active) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
                $ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT element_id FROM {$wpdb->prefix}icl_translations
                     WHERE element_type = %s AND language_code = %s AND element_id IS NOT NULL",
                    'post_' . $source,
                    $lang_code
                ));
                if (empty($ids)) {
                    $groups[] = ['lang_code' => $lang_code, 'lang_label' => $lang_label, 'items' => []];
                    continue;
                }
                $args = [
                    'post_type' => $source,
                    'post_status' => 'publish',
                    'posts_per_page' => 50,
                    'orderby' => 'title',
                    'order' => 'ASC',
                    'post__in' => $ids,
                    // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- necessario per bypassare filtri multilingua/query interna
                    'suppress_filters' => true,
                ];
                if ($search)
                    $args['s'] = $search;
                $posts = get_posts($args);
            } else {
                $args = [
                    'post_type' => $source,
                    'post_status' => 'publish',
                    'posts_per_page' => 50,
                    'orderby' => 'title',
                    'order' => 'ASC',
                ];
                if ($search)
                    $args['s'] = $search;
                $posts = get_posts($args);
            }
            $items = array_map(fn($p) => [
                'id' => $p->ID,
                'title' => get_the_title($p),
                'obj_type' => 'post',
                'edit_url' => get_edit_post_link($p->ID, 'raw'),
            ], $posts);

        } else {
            // Tassonomia
            if ($wpml_active) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
                $tt_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT element_id FROM {$wpdb->prefix}icl_translations
                     WHERE element_type = %s AND language_code = %s AND element_id IS NOT NULL",
                    'tax_' . $source,
                    $lang_code
                ));
                if (empty($tt_ids)) {
                    $groups[] = ['lang_code' => $lang_code, 'lang_label' => $lang_label, 'items' => []];
                    continue;
                }
                $term_args = [
                    'taxonomy' => $source,
                    'hide_empty' => false,
                    'number' => 50,
                    'orderby' => 'name',
                    'order' => 'ASC',
                    'term_taxonomy_id' => $tt_ids
                ];
                if ($search)
                    $term_args['search'] = $search;
                $terms = get_terms($term_args);
            } else {
                $term_args = [
                    'taxonomy' => $source,
                    'hide_empty' => false,
                    'number' => 50,
                    'orderby' => 'name',
                    'order' => 'ASC'
                ];
                if ($search)
                    $term_args['search'] = $search;
                $terms = get_terms($term_args);
            }
            if (is_wp_error($terms)) {
                $groups[] = ['lang_code' => $lang_code, 'lang_label' => $lang_label, 'items' => []];
                continue;
            }
            $items = array_map(fn($t) => [
                'id' => $t->term_id,
                'title' => $t->name,
                'obj_type' => 'term',
                'edit_url' => get_edit_term_link($t->term_id, $source, '', 'raw'),
            ], $terms);
        }

        $groups[] = [
            'lang_code' => $lang_code,
            'lang_label' => $lang_label,
            'is_primary' => ($lang_code === $primary),
            'items' => $items,
        ];
    }

    wp_send_json_success(['groups' => $groups, 'wpml' => $wpml_active]);
});

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function wpb_advisor_admin_page()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'generate';
    $api_key = wpb_advisor_active_key();
    $provider = get_option('wpb_advisor_provider', 'anthropic');
    $model = get_option('wpb_advisor_model', '');
    $log = get_option(WPB_ADVISOR_LOG_OPTION, []);

    $s = get_option(WPB_META_OPTION, []);
    $sep = $s['separator'] ?? '—';
    $site_name = $s['site_title'] ?? get_bloginfo('name');
    $order = $s['title_order'] ?? 'page_site';

    $excluded_taxes = ['post_format', 'nav_menu', 'link_category'];
    $sources = [];
    foreach (get_post_types(['public' => true], 'objects') as $pt) {
        if ($pt->name === 'attachment')
            continue;
        $sources[$pt->name] = ['label' => $pt->labels->name, 'type' => 'post_type'];
    }
    foreach (array_diff(get_taxonomies(['public' => true], 'names'), $excluded_taxes) as $tax) {
        $obj = get_taxonomy($tax);
        $sources[$tax] = ['label' => $obj->labels->name ?? $tax, 'type' => 'taxonomy'];
    }
    ?>
<div id="wpb-advisor-wrap" class="wrap wpb-wrap">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
            <div class="wpb-adv-header">
                <div class="wpb-adv-header-icon"><span class="dashicons dashicons-chart-line"></span></div>
                <div>
                    <h1>WP Barrio <span class="wpb-adv-breadcrumb">/ SEO / SEO Advisor</span></h1>
                    <p class="wpb-adv-sub">Genera title, description e keywords SEO con l'AI</p>
                </div>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-seo')); ?>"
                style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;font-size:12.5px;font-weight:600;background:#f3f4f6;color:#374151 !important;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none !important;white-space:nowrap;flex-shrink:0;align-self:flex-start;">
                <span class="dashicons dashicons-arrow-left-alt2"
                    style="font-size:14px;width:14px;height:14px;margin-top:1px;"></span>
                Strumenti SEO
            </a>
        </div>
        <hr class="wpb-adv-divider">

        <div class="wpb-adv-tabs">
            <a href="?page=wp-barrio-seo-advisor&tab=generate"
                class="wpb-adv-tab <?php echo $tab === 'generate' ? 'active' : ''; ?>">Genera</a>
            <a href="?page=wp-barrio-seo-advisor&tab=log"
                class="wpb-adv-tab <?php echo $tab === 'log' ? 'active' : ''; ?>">Log</a>
            <a href="?page=wp-barrio-seo-advisor&tab=settings"
                class="wpb-adv-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>">Impostazioni</a>
        </div>

        <?php if ($tab === 'generate'): ?>

            <?php if (!$api_key): ?>
                <div class="wpb-adv-notice-warning">
                    <span class="dashicons dashicons-warning"></span>
                    <div>Chiave API non configurata. <a href="?page=wp-barrio-seo-advisor&tab=settings">Vai alle impostazioni
                            &rarr;</a></div>
                </div>
            <?php endif; ?>

            <div class="wpb-adv-layout">
                <!-- Colonna sinistra -->
                <div class="wpb-adv-panel">
                    <div class="wpb-adv-panel-header">
                        <span class="dashicons dashicons-admin-post"></span>
                        <span class="wpb-adv-panel-title">Seleziona contenuto</span>
                    </div>
                    <div class="wpb-adv-source-select">
                        <select id="wpb-adv-source">
                            <option value="">— Tipo di contenuto —</option>
                            <optgroup label="Post Type">
                                <?php foreach ($sources as $key => $src):
                                    if ($src['type'] !== 'post_type')
                                        continue; ?>
                                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($src['label']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Tassonomie">
                                <?php foreach ($sources as $key => $src):
                                    if ($src['type'] !== 'taxonomy')
                                        continue; ?>
                                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($src['label']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="wpb-adv-search" id="wpb-adv-search-wrap" style="display:none;">
                        <input type="search" id="wpb-adv-search" placeholder="Cerca…">
                    </div>
                    <div class="wpb-adv-items" id="wpb-adv-items">
                        <div class="wpb-adv-empty">Seleziona un tipo di contenuto.</div>
                    </div>
                </div>

                <!-- Colonna destra -->
                <div id="wpb-adv-result-col">
                    <div class="wpb-adv-placeholder" id="wpb-adv-placeholder">
                        <span class="dashicons dashicons-chart-line"></span>
                        <p>Seleziona un contenuto e clicca su <strong>Genera con AI</strong>.</p>
                    </div>

                    <div id="wpb-adv-result" style="display:none;">
                        <!-- SERP preview -->
                        <div class="wpb-adv-panel" style="margin-bottom:16px;">
                            <div class="wpb-adv-panel-header">
                                <span class="dashicons dashicons-search"></span>
                                <span class="wpb-adv-panel-title">Anteprima Google</span>
                            </div>
                            <div style="padding:16px;">
                                <div class="wpb-adv-serp">
                                    <div class="wpb-adv-serp-site">
                                        <div class="wpb-adv-serp-favicon" id="wpb-adv-serp-fav">
                                            <?php $fav_id = (int) ($s['favicon_id'] ?? 0);
                                            $fav_url = $fav_id ? wp_get_attachment_image_url($fav_id, 'thumbnail') : ''; ?>
                                            <?php if ($fav_url): ?><img src="<?php echo esc_url($fav_url); ?>"
                                                    style="width:100%;height:100%;object-fit:cover;"><?php else: ?>
                                                <div style="width:8px;height:8px;border-radius:50%;background:#bdc1c6;"></div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="wpb-adv-serp-site-name"><?php echo esc_html($site_name); ?></div>
                                            <div class="wpb-adv-serp-url" id="wpb-adv-serp-url"></div>
                                        </div>
                                    </div>
                                    <div class="wpb-adv-serp-title" id="wpb-adv-serp-title">—</div>
                                    <div class="wpb-adv-serp-desc" id="wpb-adv-serp-desc">—</div>
                                </div>
                            </div>
                        </div>

                        <!-- Campi editabili -->
                        <div class="wpb-adv-panel">
                            <div class="wpb-adv-panel-header">
                                <span class="dashicons dashicons-edit"></span>
                                <span class="wpb-adv-panel-title">Meta SEO generati</span>
                                <span style="margin-left:auto;font-size:12px;color:#6b7280;" id="wpb-adv-content-label"></span>
                            </div>
                            <div style="padding:16px;display:flex;flex-direction:column;gap:14px;">
                                <div class="wpb-adv-field">
                                    <span class="wpb-adv-field-label">SEO Title <span class="wpb-adv-counter"
                                            id="wpb-adv-title-ctr">0/60</span></span>
                                    <input type="text" id="wpb-adv-title" maxlength="100">
                                </div>
                                <div class="wpb-adv-field">
                                    <span class="wpb-adv-field-label">SEO Description <span class="wpb-adv-counter"
                                            id="wpb-adv-desc-ctr">0/160</span></span>
                                    <textarea id="wpb-adv-desc" rows="3"></textarea>
                                </div>
                                <div class="wpb-adv-field">
                                    <span class="wpb-adv-field-label">Keywords</span>
                                    <div class="wpb-adv-kw-wrap" id="wpb-adv-kw-wrap"></div>
                                </div>
                                <input type="hidden" id="wpb-adv-obj-type" value="">
                                <input type="hidden" id="wpb-adv-obj-id" value="">
                                <input type="hidden" id="wpb-adv-kw-json" value="[]">
                                <div class="wpb-adv-error" id="wpb-adv-save-error"></div>
                                <div class="wpb-adv-success" id="wpb-adv-save-success">&#x2713; Meta SEO salvati con successo.
                                </div>
                                <div style="display:flex;gap:8px;padding-top:4px;border-top:1px solid #f3f4f6;">
                                    <button class="wpb-adv-save-btn" id="wpb-adv-save-btn">
                                        <span class="dashicons dashicons-yes"
                                            style="font-size:16px;width:16px;height:16px;"></span>
                                        Salva meta SEO
                                    </button>
                                    <button class="wpb-adv-btn-ghost" id="wpb-adv-regen-btn">&#x21BB; Rigenera</button>
                                    <a href="#" class="wpb-adv-btn-ghost" id="wpb-adv-edit-link" target="_blank"
                                        style="margin-left:auto;">Apri in editor &rarr;</a>
                                </div>
                            </div>
                        </div>
                    </div><!-- #wpb-adv-result -->

                    <!-- Genera button -->
                    <div id="wpb-adv-generate-bar" style="display:none;margin-top:12px;">
                        <div class="wpb-adv-error" id="wpb-adv-gen-error" style="margin-bottom:10px;"></div>
                        <div class="wpb-adv-generate-row">
                            <button class="wpb-adv-btn-primary" id="wpb-adv-gen-btn" style="font-size:14px;padding:11px 24px;"
                                <?php echo !$api_key ? 'disabled' : ''; ?>>
                                &#x2728; Genera con AI
                            </button>
                            <div class="wpb-adv-spinner" id="wpb-adv-spinner"></div>
                            <span id="wpb-adv-gen-status" style="font-size:13px;color:#6b7280;"></span>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($tab === 'log'): ?>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <span style="font-size:13px;color:#6b7280;font-weight:500;"><?php echo count($log); ?> operazioni
                    registrate</span>
                <button class="wpb-adv-btn-ghost" id="wpb-adv-clear-log">🗑 Svuota log</button>
            </div>
            <?php if (empty($log)): ?>
                <div
                    style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:40px;text-align:center;color:#6b7280;font-size:13px;">
                    Nessuna operazione registrata.</div>
            <?php else: ?>
                <table class="wpb-log-table">
                    <thead>
                        <tr>
                            <th style="width:160px;">Data</th>
                            <th style="width:90px;">Stato</th>
                            <th>Contenuto</th>
                            <th>Dettaglio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log as $entry): ?>
                            <tr>
                                <td style="color:#9ca3af;font-size:12px;">
                                    <?php echo esc_html(date_i18n('d/m/Y H:i', $entry['ts'] ?? 0)); ?></td>
                                <td><span
                                        class="wpb-log-badge <?php echo esc_attr($entry['status'] ?? 'error'); ?>"><?php echo $entry['status'] === 'success' ? '&#x2713; OK' : '&#x2715; Errore'; ?></span>
                                </td>
                                <td><?php echo esc_html($entry['target'] ?? ''); ?></td>
                                <td style="color:#9ca3af;font-size:12px;"><?php echo esc_html($entry['note'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php elseif ($tab === 'settings'): ?>

            <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione ?>
            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="wpb-adv-notice-success"><span class="dashicons dashicons-yes-alt"></span>
                    <div>Impostazioni salvate.</div>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php" style="max-width:640px;">
                <?php settings_fields('wpb_advisor_settings'); ?>

                <div class="wpb-settings-card">
                    <div class="wpb-settings-card-header">Provider AI</div>
                    <div class="wpb-settings-card-body">
                        <div class="wpb-settings-row">
                            <div class="wpb-settings-label">Provider<small>Seleziona il servizio AI</small></div>
                            <div class="wpb-settings-input">
                                <select name="wpb_advisor_provider" id="wpb-adv-provider-sel">
                                    <option value="anthropic" <?php selected($provider, 'anthropic'); ?>>Anthropic Claude
                                    </option>
                                    <option value="gemini" <?php selected($provider, 'gemini'); ?>>Google Gemini</option>
                                </select>
                            </div>
                        </div>
                        <div class="wpb-settings-row" id="wpb-adv-anthropic-row"
                            style="<?php echo $provider === 'gemini' ? 'display:none;' : ''; ?>">
                            <div class="wpb-settings-label">Chiave API Anthropic<small>sk-ant-…</small></div>
                            <div class="wpb-settings-input">
                                <input type="password" name="wpb_advisor_api_key"
                                    value="<?php echo esc_attr(get_option('wpb_advisor_api_key', '')); ?>"
                                    placeholder="sk-ant-api03-…" autocomplete="off">
                            </div>
                        </div>
                        <div class="wpb-settings-row" id="wpb-adv-gemini-row"
                            style="<?php echo $provider !== 'gemini' ? 'display:none;' : ''; ?>">
                            <div class="wpb-settings-label">Chiave API Gemini<small>AIza…</small></div>
                            <div class="wpb-settings-input">
                                <input type="password" name="wpb_advisor_gemini_key"
                                    value="<?php echo esc_attr(get_option('wpb_advisor_gemini_key', '')); ?>"
                                    placeholder="AIzaSy…" autocomplete="off">
                            </div>
                        </div>
                        <div class="wpb-settings-row">
                            <div class="wpb-settings-label">Modello<small>Lascia vuoto per il default</small></div>
                            <div class="wpb-settings-input">
                                <div class="wpb-model-row">
                                    <select name="wpb_advisor_model" id="wpb-adv-model-sel">
                                        <option value="">— Default —</option>
                                        <?php if ($model): ?>
                                            <option value="<?php echo esc_attr($model); ?>" selected><?php echo esc_html($model); ?>
                                            </option><?php endif; ?>
                                    </select>
                                    <button type="button" class="wpb-adv-btn-ghost" id="wpb-adv-load-models">Carica
                                        modelli</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="wpb-settings-card">
                    <div class="wpb-settings-card-header">Lingua output</div>
                    <div class="wpb-settings-card-body">
                        <div class="wpb-settings-row">
                            <div class="wpb-settings-label">Lingua<small>Lingua dei testi generati</small></div>
                            <div class="wpb-settings-input">
                                <?php $adv_lang = get_option('wpb_advisor_language', 'it'); ?>
                                <select name="wpb_advisor_language">
                                    <option value="it" <?php selected($adv_lang, 'it'); ?>>🇮🇹 Italiano</option>
                                    <option value="en" <?php selected($adv_lang, 'en'); ?>>🇬🇧 English</option>
                                    <option value="es" <?php selected($adv_lang, 'es'); ?>>🇪🇸 Español</option>
                                    <option value="fr" <?php selected($adv_lang, 'fr'); ?>>🇫🇷 Français</option>
                                    <option value="de" <?php selected($adv_lang, 'de'); ?>>🇩🇪 Deutsch</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="wpb-settings-card">
                    <div class="wpb-settings-card-header">Impostazioni prompt</div>
                    <div class="wpb-settings-card-body">
                        <div class="wpb-settings-row">
                            <div class="wpb-settings-label">Registro linguistico<small>Tono dei testi generati</small></div>
                            <div class="wpb-settings-input">
                                <?php $adv_tone = get_option('wpb_advisor_tone', 'seo'); ?>
                                <select name="wpb_advisor_tone">
                                    <option value="neutral" <?php selected($adv_tone, 'neutral'); ?>>Neutro e oggettivo</option>
                                    <option value="professional" <?php selected($adv_tone, 'professional'); ?>>Professionale e formale</option>
                                    <option value="friendly" <?php selected($adv_tone, 'friendly'); ?>>Amichevole e accessibile</option>
                                    <option value="technical" <?php selected($adv_tone, 'technical'); ?>>Tecnico e preciso</option>
                                    <option value="creative" <?php selected($adv_tone, 'creative'); ?>>Creativo e coinvolgente</option>
                                    <option value="seo" <?php selected($adv_tone, 'seo'); ?>>Ottimizzato SEO</option>
                                </select>
                            </div>
                        </div>
                        <div class="wpb-settings-row">
                            <div class="wpb-settings-label">Stile descrittivo<small>Approccio dell'AI</small></div>
                            <div class="wpb-settings-input">
                                <?php $adv_style = get_option('wpb_advisor_style', 'contextual'); ?>
                                <select name="wpb_advisor_style">
                                    <option value="descriptive" <?php selected($adv_style, 'descriptive'); ?>>Descrittivo — elenca gli elementi</option>
                                    <option value="contextual" <?php selected($adv_style, 'contextual'); ?>>Contestuale — interpreta significato e scopo</option>
                                    <option value="emotional" <?php selected($adv_style, 'emotional'); ?>>Evocativo — trasmette sensazioni e atmosfera</option>
                                    <option value="journalistic" <?php selected($adv_style, 'journalistic'); ?>>Giornalistico — sintetico come una didascalia</option>
                                </select>
                            </div>
                        </div>
                        <div class="wpb-settings-row">
                            <div class="wpb-settings-label">Contesto del sito<small>Fornisce contesto all'AI</small></div>
                            <div class="wpb-settings-input">
                                <input type="text" name="wpb_advisor_context"
                                    value="<?php echo esc_attr(get_option('wpb_advisor_context', '')); ?>"
                                    placeholder="es. e-commerce di moda sostenibile, blog di cucina italiana…"
                                    style="width:100%;">
                            </div>
                        </div>
                        <div class="wpb-settings-row">
                            <div class="wpb-settings-label">Parole chiave SEO<small>Separate da virgola</small></div>
                            <div class="wpb-settings-input">
                                <input type="text" name="wpb_advisor_keywords"
                                    value="<?php echo esc_attr(get_option('wpb_advisor_keywords', '')); ?>"
                                    placeholder="es. scarpe artigianali, pelle italiana, handmade…"
                                    style="width:100%;">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="wpb-settings-card">
                    <div class="wpb-settings-card-header">Log</div>
                    <div class="wpb-settings-card-body">
                        <div class="wpb-settings-row">
                            <div class="wpb-settings-label">Abilita log<small>Registra operazioni</small></div>
                            <div class="wpb-settings-input">
                                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                    <input type="checkbox" name="wpb_advisor_log_enabled" value="1" <?php checked(get_option('wpb_advisor_log_enabled', 1)); ?>>
                                    <span style="font-size:13px;color:#374151;">Attivo</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="wpb-adv-btn-primary">
                    <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                    Salva impostazioni
                </button>
            </form>

        <?php endif; ?>
    </div>

    <script>
        (function () {
            var nonce = <?php echo wp_json_encode(wp_create_nonce('wpb_advisor_nonce')); ?>;
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var sep = <?php echo wp_json_encode($sep); ?>;
            var siteName = <?php echo wp_json_encode($site_name); ?>;
            var titleOrder = <?php echo wp_json_encode($order); ?>;

            var sourceSelect = document.getElementById('wpb-adv-source');
            var searchWrap = document.getElementById('wpb-adv-search-wrap');
            var searchInput = document.getElementById('wpb-adv-search');
            var itemsWrap = document.getElementById('wpb-adv-items');
            var genBar = document.getElementById('wpb-adv-generate-bar');
            var placeholder = document.getElementById('wpb-adv-placeholder');
            var resultDiv = document.getElementById('wpb-adv-result');
            var genBtn = document.getElementById('wpb-adv-gen-btn');
            var regenBtn = document.getElementById('wpb-adv-regen-btn');
            var spinner = document.getElementById('wpb-adv-spinner');
            var genStatus = document.getElementById('wpb-adv-gen-status');
            var genError = document.getElementById('wpb-adv-gen-error');

            var selectedObjType = '';
            var selectedObjId = 0;
            var selectedEditUrl = '';
            var currentSource = '';

            function loadItems(source, search) {
                itemsWrap.innerHTML = '<div class="wpb-adv-empty">Caricamento…</div>';
                var fd = new FormData();
                fd.append('action', 'wpb_advisor_get_items');
                fd.append('nonce', nonce);
                fd.append('source', source);
                if (search) fd.append('search', search);
                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) { itemsWrap.innerHTML = '<div class="wpb-adv-empty">Errore nel caricamento.</div>'; return; }
                        var data = res.data; var groups = data.groups || []; var isWpml = data.wpml || false;
                        var html = ''; var hasAny = false;
                        groups.forEach(function (group) {
                            if (!group.items || !group.items.length) return;
                            hasAny = true;
                            if (isWpml && group.lang_label) {
                                html += '<div class="wpb-adv-lang-sep"><span class="dashicons dashicons-translation"></span>' + esc(group.lang_label) + (group.is_primary ? ' <span class="wpb-adv-lang-sep-primary-badge">principale</span>' : '') + '</div>';
                            }
                            group.items.forEach(function (item) {
                                html += '<div class="wpb-adv-item" data-id="' + item.id + '" data-type="' + item.obj_type + '" data-edit="' + esc(item.edit_url || '') + '"><span class="wpb-adv-item-title">' + esc(item.title) + '</span></div>';
                            });
                        });
                        if (!hasAny) { itemsWrap.innerHTML = '<div class="wpb-adv-empty">Nessun contenuto trovato.</div>'; return; }
                        itemsWrap.innerHTML = html;
                        if (selectedObjId) { var sel = itemsWrap.querySelector('[data-id="' + selectedObjId + '"][data-type="' + selectedObjType + '"]'); if (sel) sel.classList.add('selected'); }
                    });
            }

            if (sourceSelect) {
                sourceSelect.addEventListener('change', function () {
                    currentSource = this.value; selectedObjId = 0; selectedObjType = ''; genBar.style.display = 'none';
                    if (!currentSource) { itemsWrap.innerHTML = '<div class="wpb-adv-empty">Seleziona un tipo di contenuto.</div>'; searchWrap.style.display = 'none'; return; }
                    searchWrap.style.display = ''; searchInput.value = ''; loadItems(currentSource, '');
                });
            }

            var searchTimer;
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    clearTimeout(searchTimer); var val = this.value;
                    searchTimer = setTimeout(function () { loadItems(currentSource, val); }, 350);
                });
            }

            if (itemsWrap) {
                itemsWrap.addEventListener('click', function (e) {
                    var item = e.target.closest('.wpb-adv-item'); if (!item) return;
                    itemsWrap.querySelectorAll('.wpb-adv-item').forEach(function (i) { i.classList.remove('selected'); });
                    item.classList.add('selected');
                    selectedObjId = parseInt(item.dataset.id); selectedObjType = item.dataset.type; selectedEditUrl = item.dataset.edit || '';
                    genBar.style.display = ''; genError.style.display = 'none';
                });
            }

            function doGenerate() {
                if (!selectedObjId) return;
                genBtn.disabled = true; if (regenBtn) regenBtn.disabled = true;
                spinner.style.display = 'block'; genStatus.textContent = 'Generazione in corso…'; genError.style.display = 'none';
                var fd = new FormData();
                fd.append('action', 'wpb_advisor_generate'); fd.append('nonce', nonce); fd.append('obj_type', selectedObjType); fd.append('obj_id', selectedObjId);
                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.success) { genError.textContent = res.data || 'Errore sconosciuto.'; genError.style.display = 'block'; return; }
                        renderResult(res.data);
                    })
                    .catch(function () { genError.textContent = 'Errore di rete.'; genError.style.display = 'block'; })
                    .finally(function () { genBtn.disabled = false; if (regenBtn) regenBtn.disabled = false; spinner.style.display = 'none'; genStatus.textContent = ''; });
            }
            if (genBtn) genBtn.addEventListener('click', doGenerate);
            if (regenBtn) regenBtn.addEventListener('click', doGenerate);

            function renderResult(data) {
                placeholder.style.display = 'none'; resultDiv.style.display = '';
                var titleInput = document.getElementById('wpb-adv-title'); var descInput = document.getElementById('wpb-adv-desc');
                var kwWrap = document.getElementById('wpb-adv-kw-wrap'); var kwJson = document.getElementById('wpb-adv-kw-json');
                var objTypeInp = document.getElementById('wpb-adv-obj-type'); var objIdInp = document.getElementById('wpb-adv-obj-id');
                var contentLbl = document.getElementById('wpb-adv-content-label'); var editLink = document.getElementById('wpb-adv-edit-link');
                titleInput.value = data.title || ''; descInput.value = data.description || '';
                objTypeInp.value = data.obj_type || ''; objIdInp.value = data.obj_id || '';
                if (contentLbl) contentLbl.textContent = data.content_title || '';
                if (editLink && selectedEditUrl) editLink.href = selectedEditUrl;
                var kws = data.keywords || []; kwJson.value = JSON.stringify(kws); kwWrap.innerHTML = '';
                kws.forEach(function (kw, i) {
                    var pill = document.createElement('span');
                    pill.className = 'wpb-adv-kw-pill ' + (i === 0 ? 'wpb-adv-kw-pill-primary' : 'wpb-adv-kw-pill-secondary');
                    pill.textContent = kw; kwWrap.appendChild(pill);
                });
                updateSerp(); updateCounters(); updateSerpLive();
                document.getElementById('wpb-adv-save-error').style.display = 'none';
                document.getElementById('wpb-adv-save-success').style.display = 'none';
            }

            function updateSerp() {
                var titleInput = document.getElementById('wpb-adv-title'); var descInput = document.getElementById('wpb-adv-desc');
                var serpTitle = document.getElementById('wpb-adv-serp-title'); var serpDesc = document.getElementById('wpb-adv-serp-desc'); var serpUrl = document.getElementById('wpb-adv-serp-url');
                if (!serpTitle) return;
                var t = titleInput ? titleInput.value.trim() : '';
                var full = titleOrder === 'site_page' ? siteName + ' ' + sep + ' ' + t : t + ' ' + sep + ' ' + siteName;
                serpTitle.textContent = full || '—';
                var d = descInput ? descInput.value.trim() : '';
                serpDesc.textContent = d || '—'; serpDesc.className = 'wpb-adv-serp-desc' + (d ? '' : ' empty');
                if (serpUrl) serpUrl.textContent = selectedEditUrl ? new URL(selectedEditUrl).hostname : '';
            }
            function updateSerpLive() { var ti = document.getElementById('wpb-adv-title'); var di = document.getElementById('wpb-adv-desc'); if (ti) ti.addEventListener('input', updateSerp); if (di) di.addEventListener('input', updateSerp); }
            function updateCounters() {
                function refreshCtr(input, ctr, max, w1, w2) {
                    if (!input || !ctr) return;
                    input.addEventListener('input', function () { var l = this.value.length; ctr.textContent = l + '/' + max; ctr.style.color = l > w2 ? '#dc2626' : l >= w1 ? '#059669' : '#9ca3af'; });
                    var l = input.value.length; ctr.textContent = l + '/' + max; ctr.style.color = l > w2 ? '#dc2626' : l >= w1 ? '#059669' : '#9ca3af';
                }
                refreshCtr(document.getElementById('wpb-adv-title'), document.getElementById('wpb-adv-title-ctr'), 60, 30, 60);
                refreshCtr(document.getElementById('wpb-adv-desc'), document.getElementById('wpb-adv-desc-ctr'), 160, 120, 160);
            }

            var saveBtn = document.getElementById('wpb-adv-save-btn');
            if (saveBtn) {
                saveBtn.addEventListener('click', function () {
                    var title = document.getElementById('wpb-adv-title').value.trim(), desc = document.getElementById('wpb-adv-desc').value.trim();
                    var kwJson = document.getElementById('wpb-adv-kw-json').value, objType = document.getElementById('wpb-adv-obj-type').value, objId = document.getElementById('wpb-adv-obj-id').value;
                    var errEl = document.getElementById('wpb-adv-save-error'), okEl = document.getElementById('wpb-adv-save-success');
                    this.disabled = true; errEl.style.display = 'none'; okEl.style.display = 'none';
                    var fd = new FormData(); fd.append('action', 'wpb_advisor_save'); fd.append('nonce', nonce);
                    fd.append('obj_type', objType); fd.append('obj_id', objId); fd.append('seo_title', title); fd.append('seo_description', desc); fd.append('seo_keywords', kwJson);
                    var btn = this;
                    fetch(ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (res) {
                        if (res.success) { okEl.style.display = 'block'; setTimeout(function () { okEl.style.display = 'none'; }, 4000); }
                        else { errEl.textContent = res.data || 'Errore nel salvataggio.'; errEl.style.display = 'block'; }
                    }).catch(function () { errEl.textContent = 'Errore di rete.'; errEl.style.display = 'block'; }).finally(function () { btn.disabled = false; });
                });
            }

            var clearLogBtn = document.getElementById('wpb-adv-clear-log');
            if (clearLogBtn) {
                clearLogBtn.addEventListener('click', function () {
                    if (!confirm('Svuotare il log?')) return;
                    var fd = new FormData(); fd.append('action', 'wpb_advisor_clear_log'); fd.append('nonce', nonce);
                    fetch(ajaxUrl, { method: 'POST', body: fd }).then(function () { window.location.reload(); });
                });
            }

            var provSel = document.getElementById('wpb-adv-provider-sel');
            if (provSel) {
                provSel.addEventListener('change', function () {
                    var ant = document.getElementById('wpb-adv-anthropic-row'), gem = document.getElementById('wpb-adv-gemini-row');
                    if (this.value === 'gemini') { ant.style.display = 'none'; gem.style.display = ''; } else { ant.style.display = ''; gem.style.display = 'none'; }
                });
            }

            var loadModelsBtn = document.getElementById('wpb-adv-load-models');
            if (loadModelsBtn) {
                loadModelsBtn.addEventListener('click', function () {
                    var prov = document.getElementById('wpb-adv-provider-sel').value, sel = document.getElementById('wpb-adv-model-sel');
                    this.disabled = true; this.textContent = 'Caricamento…';
                    var fd = new FormData(); fd.append('action', 'wpb_advisor_fetch_models'); fd.append('nonce', nonce); fd.append('provider', prov); fd.append('force', '1');
                    var btn = this;
                    fetch(ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (res) {
                        if (!res.success || res.data.error) { alert(res.data.error || 'Impossibile caricare i modelli.'); return; }
                        var current = sel.value; while (sel.options.length > 1) sel.remove(1);
                        (res.data || []).forEach(function (m) { var opt = document.createElement('option'); opt.value = m; opt.textContent = m; if (m === current) opt.selected = true; sel.appendChild(opt); });
                    }).finally(function () { btn.disabled = false; btn.textContent = 'Carica modelli'; });
                });
            }

            function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
        })();
    </script>
    <?php
}