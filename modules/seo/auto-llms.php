<?php
/**
 * WP Barrio — Auto LLMs
 * Genera automaticamente llms.txt e llms-full.txt tramite AI (Gemini o Anthropic Claude).
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   COSTANTI
   ───────────────────────────────────────── */
if (!defined('WPB_LLMS_LOG_OPTION'))
    define('WPB_LLMS_LOG_OPTION', 'wpb_llms_log');
if (!defined('WPB_LLMS_LOG_MAX'))
    define('WPB_LLMS_LOG_MAX', 100);
if (!defined('WPB_LLMS_CACHE_OPTION'))
    define('WPB_LLMS_CACHE_OPTION', 'wpb_llms_last_generated');

/* ─────────────────────────────────────────
   HELPER: scrivi una riga nel log
   ───────────────────────────────────────── */
function wpb_llms_log($status, $type = '', $note = '')
{
    if (!get_option('wpb_llms_log_enabled', 1))
        return;

    $log = get_option(WPB_LLMS_LOG_OPTION, []);
    array_unshift($log, [
        'ts' => current_time('timestamp'),
        'status' => $status,   // 'success' | 'error'
        'type' => $type,     // 'short' | 'full' | 'both'
        'note' => $note,
    ]);
    if (count($log) > WPB_LLMS_LOG_MAX) {
        $log = array_slice($log, 0, WPB_LLMS_LOG_MAX);
    }
    update_option(WPB_LLMS_LOG_OPTION, $log, false);
}

/* ─────────────────────────────────────────
   HELPER: chiave API del provider attivo
   ───────────────────────────────────────── */
function wpb_llms_active_key()
{
    $provider = get_option('wpb_llms_provider', 'anthropic');
    return $provider === 'gemini'
        ? get_option('wpb_llms_gemini_key', '')
        : get_option('wpb_llms_api_key', '');
}

/* ─────────────────────────────────────────
   HELPER: recupera modelli dal provider (con cache 24h)
   ───────────────────────────────────────── */
function wpb_llms_fetch_models($provider)
{
    $cache_key = 'wpb_llms_models_' . $provider;
    $cached = get_transient($cache_key);
    if ($cached !== false)
        return $cached;

    $models = [];

    if ($provider === 'anthropic') {
        $api_key = get_option('wpb_llms_api_key', '');
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
        foreach ($data['data'] ?? [] as $m) {
            $id = $m['id'] ?? '';
            if (strpos($id, 'claude-') === 0 && !preg_match('/claude-(instant|2\.|3-haiku-2|3-sonnet-2|3-opus-2)/i', $id)) {
                $models[$id] = ['name' => $m['display_name'] ?? $id, 'desc' => ''];
            }
        }
    } elseif ($provider === 'gemini') {
        $api_key = get_option('wpb_llms_gemini_key', '');
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
            $methods = $m['supportedGenerationMethods'] ?? [];
            if (!in_array('generateContent', $methods))
                continue;
            $skip = false;
            foreach ($exclude as $ex) {
                if (strpos($id, $ex) !== false) {
                    $skip = true;
                    break;
                }
            }
            if (!preg_match('/^gemini-(2|3)/', $id))
                $skip = true;
            if (preg_match('/exp|computer-use|pro-exp/', $id))
                $skip = true;
            if ($skip)
                continue;
            $models[$id] = ['name' => $m['displayName'] ?? $id, 'desc' => ''];
        }
    }

    if (empty($models))
        return null;

    uksort($models, fn($a, $b) => strcmp($a, $b));
    set_transient($cache_key, $models, DAY_IN_SECONDS);
    return $models;
}

/* ─────────────────────────────────────────
   HELPER: restituisce tutti i post type e tassonomie configurabili
   ───────────────────────────────────────── */
function wpb_llms_get_configurable_post_types()
{
    $built_in = ['post', 'page'];
    $all = get_post_types(['public' => true], 'objects');
    $result = [];
    foreach ($all as $pt) {
        $result[$pt->name] = [
            'label' => $pt->labels->name ?? $pt->name,
            'built_in' => in_array($pt->name, $built_in),
        ];
    }
    return $result;
}

function wpb_llms_get_configurable_taxonomies()
{
    $all = get_taxonomies(['public' => true], 'objects');
    $result = [];
    foreach ($all as $tax) {
        $result[$tax->name] = [
            'label' => $tax->labels->name ?? $tax->name,
            'built_in' => in_array($tax->name, ['category', 'post_tag']),
        ];
    }
    return $result;
}

/* ─────────────────────────────────────────
   HELPER: costruisce il Markdown completo direttamente (no AI per la struttura)
   $include_full  → true = llms-full.txt (tutto), false = llms.txt (primi 10)
   $pt_modes      → ['post_type_name' => 'include'|'include_links'|'exclude']
   $tax_modes     → ['taxonomy_name'  => 'include'|'include_links'|'exclude']
   ───────────────────────────────────────── */
function wpb_llms_build_markdown($include_full = false, $pt_modes = [], $tax_modes = [])
{
    $site_name = get_bloginfo('name');
    $site_url = get_bloginfo('url');
    $lang = get_bloginfo('language');
    $limit = $include_full ? 500 : 10;

    // Determina le lingue da includere
    $wpml_active = defined('ICL_SITEPRESS_VERSION')
        && function_exists('wpb_multilang_is_enabled')
        && wpb_multilang_is_enabled();
    $languages = $wpml_active ? wpb_multilang_languages() : [null => null];
    $primary = $wpml_active && function_exists('wpb_multilang_primary_language')
        ? wpb_multilang_primary_language()
        : null;

    $md = '';

    foreach (array_keys($languages) as $lang_code) {

        // Forza la lingua corrente per tutte le query WPML
        if ($wpml_active) {
            do_action('wpml_switch_language', $lang_code);
            // Filtro aggiuntivo: assicura che WP_Query riceva il lang corretto
            add_filter('wpml_current_language', function () use ($lang_code) {
                return $lang_code;
            }, 999);
        }

        // Intestazione di sezione per lingua (solo se multilingua attivo)
        $lang_flag = $wpml_active && function_exists('wpb_multilang_flag') ? wpb_multilang_flag((string) $lang_code) : '';
        $lang_label = $wpml_active ? ($languages[$lang_code] ?? strtoupper((string) $lang_code)) : null;
        $lang_display = $lang_label ? trim($lang_flag . ' ' . $lang_label) : null;
        if ($lang_display) {
            $is_primary = ($lang_code === $primary);
            $md .= "\n---\n\n# Lingua: {$lang_display}" . ($is_primary ? ' (principale)' : '') . "\n";
        }

        // ── Post type (inclusi CPT) ───────────────────────────────────────────
        $all_pt = get_post_types(['public' => true], 'objects');

        foreach ($all_pt as $pt) {
            $slug = $pt->name;
            if ($slug === 'attachment')
                continue;
            $label = $pt->labels->name ?? $slug;
            $mode = $pt_modes[$slug] ?? 'include';
            if ($mode === 'exclude')
                continue;

            // Ottiene i post tradotti in questa lingua tramite WPML
            if ($wpml_active) {
                global $sitepress;
                // Recupera gli ID dei post in questa lingua via tabella icl_translations
                global $wpdb;
                $post_ids_in_lang = $wpdb->get_col($wpdb->prepare(
                    "SELECT element_id FROM {$wpdb->prefix}icl_translations
                     WHERE element_type = %s AND language_code = %s AND element_id IS NOT NULL",
                    'post_' . $slug,
                    $lang_code
                ));

                if (empty($post_ids_in_lang))
                    continue;

                $posts = get_posts([
                    'post_type' => $slug,
                    'post_status' => 'publish',
                    'posts_per_page' => $limit,
                    'post__in' => $post_ids_in_lang,
                    'orderby' => ($slug === 'page') ? 'menu_order' : 'date',
                    'order' => ($slug === 'page') ? 'ASC' : 'DESC',
                    'suppress_filters' => true,
                ]);
            } else {
                $posts = get_posts([
                    'post_type' => $slug,
                    'post_status' => 'publish',
                    'posts_per_page' => $limit,
                    'orderby' => ($slug === 'page') ? 'menu_order' : 'date',
                    'order' => ($slug === 'page') ? 'ASC' : 'DESC',
                    'suppress_filters' => false,
                ]);
            }

            if (empty($posts))
                continue;

            $md .= "\n## {$label}\n\n";

            foreach ($posts as $post) {
                $permalink = get_permalink($post->ID);

                if ($mode === 'include_links') {
                    $md .= "- [{$post->post_title}]({$permalink})\n";
                    continue;
                }

                $md .= "### [{$post->post_title}]({$permalink})\n\n";

                if ($include_full) {
                    $text = wp_strip_all_tags(apply_filters('the_content', $post->post_content));
                    $text = preg_replace('/\s+/', ' ', trim($text));
                    if (!empty($text)) {
                        $max_chars = ($slug === 'page') ? 3000 : 4000;
                        $md .= mb_substr($text, 0, $max_chars) . "\n\n";
                    }
                } else {
                    $excerpt = get_the_excerpt($post->ID);
                    if (empty($excerpt)) {
                        $text = wp_strip_all_tags(apply_filters('the_content', $post->post_content));
                        $excerpt = mb_substr(preg_replace('/\s+/', ' ', trim($text)), 0, 300);
                    }
                    if (!empty($excerpt)) {
                        $md .= $excerpt . "\n\n";
                    }
                }

                if ($slug === 'post') {
                    $md .= "**Data:** " . get_the_date('Y-m-d', $post->ID) . "  \n";
                }

                $post_taxes = get_object_taxonomies($slug, 'objects');
                foreach ($post_taxes as $tax_slug => $tax_obj) {
                    $tax_mode = $tax_modes[$tax_slug] ?? 'include';
                    if ($tax_mode === 'exclude')
                        continue;
                    $terms = get_the_terms($post->ID, $tax_slug);
                    if (!empty($terms) && !is_wp_error($terms)) {
                        $tax_label = $tax_obj->labels->name ?? $tax_slug;
                        if ($tax_mode === 'include_links') {
                            $links = [];
                            foreach ($terms as $term) {
                                $turl = get_term_link($term);
                                $links[] = is_wp_error($turl) ? $term->name : "[{$term->name}]({$turl})";
                            }
                            $md .= "**{$tax_label}:** " . implode(', ', $links) . "  \n";
                        } else {
                            $md .= "**{$tax_label}:** " . implode(', ', wp_list_pluck($terms, 'name')) . "  \n";
                        }
                    }
                }

                $md .= "\n";
            }
        }

        // ── Tassonomie standalone ────────────────────────────────────────────
        $all_tax = get_taxonomies(['public' => true], 'objects');

        foreach ($all_tax as $tax_slug => $tax_obj) {
            $mode = $tax_modes[$tax_slug] ?? 'include';
            if ($mode === 'exclude')
                continue;

            // Per WPML: recupera solo i term_taxonomy_id in questa lingua
            if ($wpml_active) {
                global $wpdb;
                $term_ids_in_lang = $wpdb->get_col($wpdb->prepare(
                    "SELECT element_id FROM {$wpdb->prefix}icl_translations
                     WHERE element_type = %s AND language_code = %s AND element_id IS NOT NULL",
                    'tax_' . $tax_slug,
                    $lang_code
                ));

                if (empty($term_ids_in_lang))
                    continue;

                $terms = get_terms([
                    'taxonomy' => $tax_slug,
                    'hide_empty' => true,
                    'number' => $include_full ? 0 : $limit,
                    'term_taxonomy_id' => $term_ids_in_lang,
                ]);
            } else {
                $terms = get_terms([
                    'taxonomy' => $tax_slug,
                    'hide_empty' => true,
                    'number' => $include_full ? 0 : $limit,
                ]);
            }

            if (empty($terms) || is_wp_error($terms))
                continue;

            $label = $tax_obj->labels->name ?? $tax_slug;
            $md .= "\n## {$label}\n\n";

            foreach ($terms as $term) {
                $term_url = get_term_link($term);
                $url_ok = !is_wp_error($term_url);

                if ($mode === 'include_links') {
                    $md .= $url_ok ? "- [{$term->name}]({$term_url})\n" : "- {$term->name}\n";
                } else {
                    $title = $url_ok ? "[{$term->name}]({$term_url})" : $term->name;
                    $md .= "- {$title}";
                    if (!empty($term->description)) {
                        $md .= " — " . wp_strip_all_tags($term->description);
                    }
                    $md .= "\n";
                }
            }

            $md .= "\n";
        }

        // ── Menu (solo per la lingua principale per evitare duplicati) ───────
        if (!$wpml_active || $lang_code === $primary) {
            $menus = wp_get_nav_menus();
            if (!empty($menus)) {
                $md .= "\n## Navigazione\n\n";
                foreach ($menus as $menu) {
                    $items = wp_get_nav_menu_items($menu->term_id);
                    if (empty($items))
                        continue;
                    $md .= "**{$menu->name}:** ";
                    $links = [];
                    foreach ($items as $item) {
                        if (!empty($item->url) && !empty($item->title)) {
                            $links[] = "[{$item->title}]({$item->url})";
                        }
                    }
                    $md .= implode(' · ', $links) . "\n";
                }
                $md .= "\n";
            }
        }
    }

    // Ripristina lingua WPML e rimuove il filtro temporaneo
    if ($wpml_active) {
        remove_all_filters('wpml_current_language', 999);
        do_action('wpml_switch_language', null);
    }

    return [
        'site_name' => $site_name,
        'site_url' => $site_url,
        'lang' => $lang,
        'body' => $md,
        'raw_info' => "Nome: {$site_name}\nURL: {$site_url}\nDescrizione WP: " . get_bloginfo('description') . "\n",
    ];
}

/* ─────────────────────────────────────────
   HELPER: raccoglie il contenuto del sito (wrapper legacy — usato solo per compatibilità)
   ───────────────────────────────────────── */
function wpb_llms_collect_site_content($include_full = false, $pt_modes = [], $tax_modes = [])
{
    $built = wpb_llms_build_markdown($include_full, $pt_modes, $tax_modes);
    return $built['raw_info'] . $built['body'];
}

/* ─────────────────────────────────────────
   HELPER: chiama l'AI per generare SOLO la descrizione introduttiva
   Ritorna ['short_intro' => '...', 'full_intro' => '...'] oppure ['error' => '...']
   ───────────────────────────────────────── */
function wpb_llms_generate_intro($site_info, $generate_type)
{
    $provider = get_option('wpb_llms_provider', 'anthropic');
    $model = get_option('wpb_llms_model', 'claude-sonnet-4-6') ?: 'claude-sonnet-4-6';
    $lang = get_option('wpb_llms_language', 'it');
    $tone = get_option('wpb_llms_tone', 'professional');
    $focus = get_option('wpb_llms_focus', '');

    $lang_map = ['it' => 'italiano', 'en' => 'inglese', 'es' => 'spagnolo', 'fr' => 'francese', 'de' => 'tedesco'];
    $lang_name = $lang_map[$lang] ?? 'italiano';
    $tone_map = [
        'professional' => 'professionale e chiaro',
        'technical' => 'tecnico e preciso',
        'friendly' => 'amichevole e accessibile',
        'concise' => 'conciso e diretto',
    ];
    $tone_str = $tone_map[$tone] ?? 'professionale e chiaro';

    if ($generate_type === 'short') {
        $prompt = "Sei un esperto di GEO (Generative Engine Optimization) per siti web. ";
        $prompt .= "Scrivi la sezione introduttiva di un file llms.txt per il sito descritto sotto. ";
        $prompt .= "L'introduzione deve essere ottimizzata per essere compresa e citata dai modelli AI (ChatGPT, Gemini, Claude, Perplexity). ";
        $prompt .= "Includi: 1-2 frasi che definiscono chiaramente cos'è il sito e a chi si rivolge; ";
        $prompt .= "le funzionalità o i contenuti principali offerti; ";
        $prompt .= "perché è rilevante e autorevole nel suo settore. ";
        $prompt .= "Tono: {$tone_str}. Lingua: {$lang_name}. ";
        if (!empty($focus))
            $prompt .= "Settore/focus: {$focus}. ";
        $prompt .= "Lunghezza: 3-5 righe di testo scorrevole in Markdown (no intestazioni, no elenchi). ";
        $prompt .= "Rispondi SOLO con il testo dell'introduzione, nient'altro.\n\n";
        $prompt .= "SITO:\n{$site_info}";
    } else {
        $prompt = "Sei un esperto di GEO (Generative Engine Optimization) per siti web. ";
        $prompt .= "Scrivi la sezione introduttiva estesa di un file llms-full.txt per il sito descritto sotto. ";
        $prompt .= "L'introduzione deve essere ottimizzata per essere compresa e citata dai modelli AI (ChatGPT, Gemini, Claude, Perplexity). ";
        $prompt .= "Includi: descrizione approfondita del sito e della sua missione; ";
        $prompt .= "pubblico di riferimento e bisogni che soddisfa; ";
        $prompt .= "panoramica delle sezioni e funzionalità principali; ";
        $prompt .= "elementi di autorevolezza (storia, expertise, unicità dell'offerta); ";
        $prompt .= "informazioni utili per un AI che deve citare o raccomandare il sito. ";
        $prompt .= "Tono: {$tone_str}. Lingua: {$lang_name}. ";
        if (!empty($focus))
            $prompt .= "Settore/focus: {$focus}. ";
        $prompt .= "Lunghezza: 6-10 righe di testo scorrevole in Markdown (puoi usare grassetti per enfatizzare concetti chiave). ";
        $prompt .= "Rispondi SOLO con il testo dell'introduzione, nient'altro.\n\n";
        $prompt .= "SITO:\n{$site_info}";
    }

    $raw = '';

    if ($provider === 'gemini') {
        $api_key = get_option('wpb_llms_gemini_key', '');
        if (empty($api_key))
            return ['error' => 'Chiave API Gemini non configurata'];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . $api_key;
        $response = wp_remote_post($url, [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['maxOutputTokens' => 1024, 'temperature' => 0.5],
            ]),
        ]);
        if (is_wp_error($response))
            return ['error' => 'Connessione fallita: ' . $response->get_error_message()];
        $http = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        if ($http !== 200) {
            $d = json_decode($body_raw, true);
            return ['error' => 'Gemini HTTP ' . $http . ': ' . ($d['error']['message'] ?? $body_raw)];
        }
        $body = json_decode($body_raw, true);
        $raw = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($raw === '') {
            $finish = $body['candidates'][0]['finishReason'] ?? 'UNKNOWN';
            return ['error' => 'Gemini risposta vuota (finishReason: ' . $finish . ')'];
        }

    } else {
        $api_key = get_option('wpb_llms_api_key', '');
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
                'max_tokens' => 1024,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]),
        ]);
        if (is_wp_error($response))
            return ['error' => 'Connessione fallita: ' . $response->get_error_message()];
        $http = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        if ($http !== 200) {
            $d = json_decode($body_raw, true);
            return ['error' => 'Anthropic HTTP ' . $http . ': ' . ($d['error']['message'] ?? $body_raw)];
        }
        $body = json_decode($body_raw, true);
        $raw = $body['content'][0]['text'] ?? '';
        if ($raw === '')
            return ['error' => 'Anthropic risposta vuota. Body: ' . substr($body_raw, 0, 300)];
    }

    return ['intro' => trim($raw)];
}

/* ─────────────────────────────────────────
   HELPER: chiama il provider AI (wrapper — ora usato solo dalla vecchia firma)
   ───────────────────────────────────────── */
function wpb_llms_call_api($site_content, $generate_type, $pt_modes = [], $tax_modes = [])
{
    // Questo wrapper non viene più usato nel flusso principale
    // (il generate handler usa wpb_llms_build_markdown + wpb_llms_generate_intro)
    return ['error' => 'Metodo deprecato — usa wpb_llms_generate_intro.'];
}

/* ─────────────────────────────────────────
   HELPER: scrive il file nella root di WordPress
   ───────────────────────────────────────── */
function wpb_llms_write_file($filename, $content)
{
    $path = ABSPATH . $filename;
    $result = file_put_contents($path, $content);
    if ($result === false) {
        return ['error' => 'Impossibile scrivere ' . $filename . '. Verifica i permessi sulla root del sito.'];
    }
    return ['bytes' => $result];
}

/* ─────────────────────────────────────────
   HELPER: legge un file dalla root
   ───────────────────────────────────────── */
function wpb_llms_read_file($filename)
{
    $path = ABSPATH . $filename;
    if (!file_exists($path))
        return null;
    return file_get_contents($path);
}

/* ─────────────────────────────────────────
   AJAX: recupera modelli live
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_llms_fetch_models', function () {
    check_ajax_referer('wpb_llms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_success(['error' => 'Permesso negato.']);

    $provider = sanitize_key($_POST['provider'] ?? 'anthropic');
    $force = !empty($_POST['force']);
    if ($force)
        delete_transient('wpb_llms_models_' . $provider);

    $models = wpb_llms_fetch_models($provider);
    if ($models === null) {
        wp_send_json_success(['error' => 'Impossibile recuperare i modelli. Verifica la chiave API.']);
    }
    wp_send_json_success($models);
});

/* ─────────────────────────────────────────
   AJAX: genera i file llms
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_llms_generate', function () {
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');

    check_ajax_referer('wpb_llms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_success(['error' => 'Permesso negato.']);

    $type = sanitize_key($_POST['type'] ?? 'both');

    $api_key = wpb_llms_active_key();
    if (empty($api_key)) {
        $provider = get_option('wpb_llms_provider', 'anthropic');
        $msg = 'Chiave API non configurata per il provider: ' . $provider;
        wpb_llms_log('error', $type, $msg);
        wp_send_json_success(['error' => $msg]);
    }

    $allowed = ['include', 'include_links', 'exclude'];
    $pt_modes = [];
    $tax_modes = [];
    $raw_pt = !empty($_POST['pt_modes']) ? (array) $_POST['pt_modes'] : get_option('wpb_llms_pt_modes', []);
    $raw_tax = !empty($_POST['tax_modes']) ? (array) $_POST['tax_modes'] : get_option('wpb_llms_tax_modes', []);
    foreach ($raw_pt as $slug => $mode) {
        $slug = sanitize_key($slug);
        if ($slug && in_array($mode, $allowed))
            $pt_modes[$slug] = $mode;
    }
    foreach ($raw_tax as $slug => $mode) {
        $slug = sanitize_key($slug);
        if ($slug && in_array($mode, $allowed))
            $tax_modes[$slug] = $mode;
    }

    $results = [];

    // Genera llms.txt
    if (in_array($type, ['short', 'both'])) {
        $built = wpb_llms_build_markdown(false, $pt_modes, $tax_modes);
        $intro = wpb_llms_generate_intro($built['raw_info'], 'short');

        if (isset($intro['error'])) {
            wpb_llms_log('error', 'short', $intro['error']);
            wp_send_json_success(['error' => $intro['error'], 'error_type' => 'short']);
        }

        $final = "# {$built['site_name']}\n\n> {$built['site_url']}\n\n";
        $final .= $intro['intro'] . "\n" . $built['body'];

        $write = wpb_llms_write_file('llms.txt', $final);
        if (isset($write['error'])) {
            wpb_llms_log('error', 'short', $write['error']);
            wp_send_json_success(['error' => $write['error'], 'error_type' => 'short']);
        }
        $results['short'] = ['label' => 'llms.txt', 'bytes' => $write['bytes']];
    }

    // Genera llms-full.txt
    if (in_array($type, ['full', 'both'])) {
        $built_full = wpb_llms_build_markdown(true, $pt_modes, $tax_modes);
        $intro_full = wpb_llms_generate_intro($built_full['raw_info'], 'full');

        if (isset($intro_full['error'])) {
            wpb_llms_log('error', 'full', $intro_full['error']);
            wp_send_json_success(['error' => $intro_full['error'], 'error_type' => 'full']);
        }

        $final_full = "# {$built_full['site_name']}\n\n> {$built_full['site_url']}\n\n";
        $final_full .= $intro_full['intro'] . "\n" . $built_full['body'];

        $write_full = wpb_llms_write_file('llms-full.txt', $final_full);
        if (isset($write_full['error'])) {
            wpb_llms_log('error', 'full', $write_full['error']);
            wp_send_json_success(['error' => $write_full['error'], 'error_type' => 'full']);
        }
        $results['full'] = ['label' => 'llms-full.txt', 'bytes' => $write_full['bytes']];
    }

    update_option(WPB_LLMS_CACHE_OPTION, ['ts' => current_time('timestamp'), 'type' => $type], false);
    $provider = get_option('wpb_llms_provider', 'anthropic');
    $model = get_option('wpb_llms_model', '');
    wpb_llms_log('success', $type, 'Generato con ' . $provider . ($model ? ' / ' . $model : ''));

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_send_json_success($results);
});

/* ─────────────────────────────────────────
   AJAX: salva le impostazioni di inclusione
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_llms_save_inclusion', function () {
    check_ajax_referer('wpb_llms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_success(['error' => 'Permesso negato.']);

    $allowed = ['include', 'include_links', 'exclude'];
    $pt_modes = [];
    $tax_modes = [];

    foreach ((array) ($_POST['pt_modes'] ?? []) as $slug => $mode) {
        $slug = sanitize_key($slug);
        $mode = in_array($mode, $allowed) ? $mode : 'include';
        if ($slug)
            $pt_modes[$slug] = $mode;
    }
    foreach ((array) ($_POST['tax_modes'] ?? []) as $slug => $mode) {
        $slug = sanitize_key($slug);
        $mode = in_array($mode, $allowed) ? $mode : 'include';
        if ($slug)
            $tax_modes[$slug] = $mode;
    }

    update_option('wpb_llms_pt_modes', $pt_modes, false);
    update_option('wpb_llms_tax_modes', $tax_modes, false);

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }

    wp_send_json_success();
});

/* ─────────────────────────────────────────
   AJAX: svuota log
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_llms_clear_log', function () {
    check_ajax_referer('wpb_llms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_success(['error' => 'Permesso negato.']);

    delete_option(WPB_LLMS_LOG_OPTION);
    wp_send_json_success();
});

/* ─────────────────────────────────────────
   SOPPRIME settings_errors SULLA PAGINA AUTO LLMS
   WordPress chiama settings_errors() automaticamente in cima
   a ogni pagina admin: questo evita che messaggi di errore API
   finiscano visualizzati come notice globali.
   ───────────────────────────────────────── */
add_action('load-wp-barrio_page_wp-barrio-auto-llms', function () {
    add_filter('pre_option_settings_errors', '__return_empty_array');
});

/* ─────────────────────────────────────────
   GRAPHQL: espone hbLlms e hbLlmsFull
   Richiede WPGraphQL. Se non è attivo i hook
   graphql_register_types vengono ignorati.
   ───────────────────────────────────────── */
add_action('graphql_register_types', function () {

    // Tipo LlmsContent
    register_graphql_object_type('LlmsContent', [
        'description' => 'Contenuto LLMs generato da WP Barrio Auto LLMs',
        'fields' => [
            'content' => [
                'type' => 'String',
                'description' => 'Testo Markdown del documento LLMs',
            ],
            'updatedAt' => [
                'type' => 'String',
                'description' => 'Data e ora dell\'ultima generazione (timestamp Unix come stringa)',
            ],
            'bytes' => [
                'type' => 'Int',
                'description' => 'Dimensione in byte del contenuto',
            ],
        ],
    ]);

    // Query hbLlms → versione sintetica (legge llms.txt)
    register_graphql_field('RootQuery', 'hbLlms', [
        'type' => 'LlmsContent',
        'description' => 'Restituisce il contenuto di llms.txt',
        'resolve' => function () {
            $content = wpb_llms_read_file('llms.txt');
            if ($content === null)
                return null;
            $path = ABSPATH . 'llms.txt';
            return [
                'content' => $content,
                'updatedAt' => (string) filemtime($path),
                'bytes' => (int) filesize($path),
            ];
        },
    ]);

    // Query hbLlmsFull → versione completa (legge llms-full.txt)
    register_graphql_field('RootQuery', 'hbLlmsFull', [
        'type' => 'LlmsContent',
        'description' => 'Restituisce il contenuto di llms-full.txt',
        'resolve' => function () {
            $content = wpb_llms_read_file('llms-full.txt');
            if ($content === null)
                return null;
            $path = ABSPATH . 'llms-full.txt';
            return [
                'content' => $content,
                'updatedAt' => (string) filemtime($path),
                'bytes' => (int) filesize($path),
            ];
        },
    ]);
});

/* ─────────────────────────────────────────
   1. MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!wpb_tools_module_enabled('seo'))
        return;

    // Figlio di wp-barrio (mantiene capability check) ma nascosto via CSS
    add_submenu_page(
        'wp-barrio',
        'Auto LLMs — WP Barrio',
        'Auto LLMs',
        'manage_options',
        'wp-barrio-auto-llms',
        'wpb_llms_admin_page'
    );
}, 15);

add_action('admin_head', function () {
    echo '<style>#adminmenu a[href="admin.php?page=wp-barrio-auto-llms"]{display:none!important}</style>';
});

add_filter('submenu_file', function ($submenu_file) {
    if (isset($_GET['page']) && $_GET['page'] === 'wp-barrio-auto-llms')
        return 'wp-barrio-seo';
    return $submenu_file;
});

/* ─────────────────────────────────────────
   2. IMPOSTAZIONI
   ───────────────────────────────────────── */
add_action('admin_init', function () {
    register_setting('wpb_llms_settings', 'wpb_llms_provider', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wpb_llms_settings', 'wpb_llms_api_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wpb_llms_settings', 'wpb_llms_gemini_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wpb_llms_settings', 'wpb_llms_model', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wpb_llms_settings', 'wpb_llms_language', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wpb_llms_settings', 'wpb_llms_tone', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'professional']);
    register_setting('wpb_llms_settings', 'wpb_llms_focus', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
    register_setting('wpb_llms_settings', 'wpb_llms_log_enabled', ['sanitize_callback' => 'absint', 'default' => 1]);
});

/* ─────────────────────────────────────────
   3. PAGINA ADMIN
   ───────────────────────────────────────── */
function wpb_llms_admin_page()
{
    $api_key = wpb_llms_active_key();
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'generate';
    $last_gen = get_option(WPB_LLMS_CACHE_OPTION, null);
    $log = get_option(WPB_LLMS_LOG_OPTION, []);

    $short_exists = file_exists(ABSPATH . 'llms.txt');
    $full_exists = file_exists(ABSPATH . 'llms-full.txt');
    $short_size = $short_exists ? size_format(filesize(ABSPATH . 'llms.txt')) : '—';
    $full_size = $full_exists ? size_format(filesize(ABSPATH . 'llms-full.txt')) : '—';
    ?>
    <style>
        /* WP Barrio — Auto LLMs */
        #wpb-llms-wrap {
            max-width: 1100px;
        }

        .wpb-stats-bar {
            display: flex;
            gap: 0;
            margin: 0 0 20px;
            border: 1px solid #e5e7eb;
                border-radius: 10px;
                overflow: hidden;
                background: #fff;
            }

            .wpb-stat {
                flex: 1;
                padding: 12px 20px;
                border-right: 1px solid #e5e7eb;
                text-align: center;
            }

            .wpb-stat:last-child {
                border-right: none;
            }

            .wpb-stat .wpb-stat-num {
                font-size: 28px;
                font-weight: 600;
                line-height: 1;
                color: #111827;
            }

            .wpb-stat .wpb-stat-num.green {
                color: #059669;
            }

            .wpb-stat .wpb-stat-num.red {
                color: #dc2626;
            }

            .wpb-stat .wpb-stat-num.blue {
                color: #374151;
            }

            .wpb-stat .wpb-stat-lbl {
                font-size: 11px;
                color: #6b7280;
                margin-top: 3px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .wpb-nav-tab-wrapper {
                border-bottom: 1px solid #e5e7eb;
                margin-bottom: 20px;
            }

            .wpb-nav-tab-wrapper .nav-tab {
                margin-bottom: -1px;
            }

            /* Generate panel */
            .wpb-gen-panel {
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                overflow: hidden;
                margin-bottom: 20px;
            }

            .wpb-gen-header {
                padding: 14px 20px;
                border-bottom: 1px solid #f3f4f6;
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .wpb-gen-icon {
                font-size: 22px;
                flex-shrink: 0;
            }

            .wpb-gen-title {
                font-size: 14px;
                font-weight: 600;
                color: #111827;
                margin-bottom: 2px;
            }

            .wpb-gen-desc {
                font-size: 12px;
                color: #6b7280;
                line-height: 1.4;
            }

            .wpb-gen-body {
                padding: 16px 20px;
            }

            .wpb-file-meta {
                display: flex;
                gap: 16px;
                flex-wrap: wrap;
                margin-bottom: 14px;
            }

            .wpb-file-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 4px 10px;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 500;
            }

            .wpb-file-badge.exists {
                background: #dcfce7;
                color: #059669;
                border: 1px solid #b8e6c1;
            }

            .wpb-file-badge.missing {
                background: #f3f4f6;
                color: #6b7280;
                border: 1px solid #e5e7eb;
            }

            .wpb-gen-actions {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }

            .wpb-btn-gen-llms {
                padding: 7px 18px;
                font-size: 13px;
                font-weight: 600;
                font-family: inherit;
                background: #111827;
                color: #fff;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                gap: 7px;
                transition: background .1s;
            }

            .wpb-btn-gen-llms:hover:not(:disabled) {
                background: #135e96;
                border-color: #135e96;
            }

            .wpb-btn-gen-llms:disabled {
                background: #a7aaad;
                border-color: #9ca3af;
                cursor: not-allowed;
            }

            .wpb-btn-gen-llms.secondary {
                background: #fff;
                color: #111827;
                border-color: #8c8f94;
            }

            .wpb-btn-gen-llms.secondary:hover:not(:disabled) {
                background: #f9fafb;
                border-color: #111827;
            }

            /* Provider/model cards (stesso stile di auto-alt) */
            .wpb-gen-progress {
                display: none;
                margin-top: 14px;
                background: #f3f4f6;
                border-radius: 8px;
                overflow: hidden;
                border: 1px solid #e5e7eb;
            }

            .wpb-gen-progress.active {
                display: block;
            }

            .wpb-progress-bar-inner {
                height: 4px;
                background: #111827;
                width: 0%;
                transition: width .4s;
            }

            .wpb-progress-label {
                font-size: 11px;
                color: #6b7280;
                padding: 6px 10px 8px;
                line-height: 1.5;
            }

            /* Result box */
            .wpb-result-box {
                display: none;
                margin-top: 16px;
                background: #f0fff4;
                border: 1px solid #b8e6c1;
                border-radius: 8px;
                padding: 12px 16px;
            }

            .wpb-result-box.visible {
                display: block;
            }

            .wpb-result-title {
                font-size: 12px;
                font-weight: 700;
                color: #059669;
                margin-bottom: 6px;
                text-transform: uppercase;
                letter-spacing: .4px;
            }

            .wpb-result-files {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
            }

            .wpb-result-file {
                font-size: 12px;
                color: #111827;
                background: #fff;
                border: 1px solid #b8e6c1;
                border-radius: 8px;
                padding: 4px 10px;
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }

            /* Provider/model cards (stesso stile di auto-alt) */
            .wpb-provider-block {
                margin-bottom: 10px;
            }

            .wpb-provider-card {
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                padding: 12px 16px;
                cursor: pointer;
                display: flex;
                align-items: flex-start;
                gap: 12px;
                background: #fff;
                transition: border-color .1s, background .1s;
            }

            .wpb-provider-card.selected {
                border-color: #111827;
                background: #f9fafb;
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
                color: #111827;
            }

            .wpb-pc-desc {
                font-size: 12px;
                color: #6b7280;
            }

            .wpb-provider-keys {
                display: none;
                padding: 12px 16px 8px;
                border: 1px solid #e5e7eb;
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
                color: #374151;
                margin-bottom: 4px;
            }

            .wpb-key-group input[type=password] {
                width: 100%;
                max-width: 400px;
                padding: 5px 8px;
                border: 1px solid #8c8f94;
                border-radius: 8px;
                font-size: 13px;
                font-family: Consolas, monospace;
                background: #fff;
                box-shadow: inset 0 1px 2px rgba(0, 0, 0, .07);
            }

            .wpb-key-group input[type=password]:focus {
                outline: none;
                border-color: #111827;
            }

            .wpb-key-hint {
                font-size: 12px;
                color: #6b7280;
                margin-top: 4px;
            }

            .wpb-key-hint a {
                color: #374151;
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
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                cursor: pointer;
                background: #fff;
                transition: border-color .1s, background .1s;
            }

            .wpb-model-label.selected {
                border-color: #111827;
                background: #f9fafb;
            }

            .wpb-model-label input[type=radio] {
                margin-top: 2px;
                flex-shrink: 0;
            }

            .wpb-model-name {
                font-size: 12px;
                font-weight: 600;
                color: #111827;
                margin-bottom: 1px;
            }

            .wpb-model-desc {
                font-size: 11px;
                color: #6b7280;
            }

            .wpb-model-id {
                font-size: 10px;
                color: #9ca3af;
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
                color: #6b7280;
                flex: 1;
            }

            .wpb-model-status.ok {
                color: #059669;
            }

            .wpb-model-status.err {
                color: #dc2626;
            }

            /* Toggle */
            .wpb-toggle-row {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                padding: 12px 16px;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                background: #fff;
            }

            .wpb-toggle {
                position: relative;
                width: 44px;
                height: 24px;
                flex-shrink: 0;
                margin-top: 1px;
            }

            .wpb-toggle input {
                opacity: 0;
                width: 0;
                height: 0;
                position: absolute;
            }

            .wpb-toggle-track {
                position: absolute;
                inset: 0;
                background: #d1d5db;
                border-radius: 24px;
                cursor: pointer;
                transition: background .2s;
            }

            .wpb-toggle-track::before {
                content: "";
                position: absolute;
                width: 18px;
                height: 18px;
                left: 3px;
                top: 3px;
                background: #fff;
                border-radius: 50%;
                transition: transform .2s;
                box-shadow: 0 1px 3px rgba(0, 0, 0, .2);
            }

            .wpb-toggle input:checked+.wpb-toggle-track {
                background: #111827;
            }

            .wpb-toggle input:checked+.wpb-toggle-track::before {
                transform: translateX(20px);
            }

            .wpb-toggle-info {
                flex: 1;
            }

            .wpb-toggle-title {
                font-size: 13px;
                font-weight: 600;
                color: #111827;
                margin-bottom: 3px;
            }

            .wpb-toggle-desc {
                font-size: 12px;
                color: #6b7280;
                line-height: 1.5;
            }

            /* Log (riusa stesso stile di auto-alt) */
            .wpb-log-stats {
                display: flex;
                gap: 12px;
                margin-bottom: 16px;
            }

            .wpb-log-stat {
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                padding: 12px 20px;
                flex: 1;
                text-align: center;
            }

            .wpb-log-stat .n {
                font-size: 26px;
                font-weight: 600;
                line-height: 1;
                color: #111827;
            }

            .wpb-log-stat .n.green {
                color: #059669;
            }

            .wpb-log-stat .n.red {
                color: #dc2626;
            }

            .wpb-log-stat .l {
                font-size: 11px;
                color: #6b7280;
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
                color: #6b7280;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
            }

            .wpb-log-filter.active {
                background: #1d2327;
                color: #fff;
                border-color: #111827;
            }

            .wpb-log-filter:hover:not(.active) {
                border-color: #111827;
                color: #111827;
            }

            .wpb-btn-clear {
                padding: 4px 12px;
                font-size: 12px;
                font-family: inherit;
                cursor: pointer;
                background: #fff;
                color: #dc2626;
                border: 1px solid #d63638;
                border-radius: 8px;
            }

            .wpb-btn-clear:hover {
                background: #fee2e2;
            }

            .wpb-log-table-wrap {
                border: 1px solid #e5e7eb;
                background: #fff;
                border-radius: 8px;
                overflow: hidden;
                overflow-x: auto;
            }

            .wpb-log-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 13px;
                min-width: 500px;
            }

            .wpb-log-table th {
                background: #f3f4f6;
                padding: 8px 12px;
                text-align: left;
                font-weight: 600;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: .4px;
                color: #374151;
                border-bottom: 1px solid #e5e7eb;
            }

            .wpb-log-table td {
                padding: 8px 12px;
                border-bottom: 1px solid #f3f4f6;
                vertical-align: middle;
            }

            .wpb-log-table tr:last-child td {
                border-bottom: none;
            }

            .wpb-log-table tr:hover td {
                background: #f9fafb;
            }

            tr[data-status="error"] td {
                background: #fee2e2 !important;
            }

            tr[data-status="error"]:hover td {
                background: #fce8e9 !important;
            }

            .wpb-log-status {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 2px 8px;
                border-radius: 8px;
                font-size: 11px;
                font-weight: 600;
                white-space: nowrap;
            }

            .wpb-log-status.success {
                background: #dcfce7;
                color: #059669;
            }

            .wpb-log-status.error {
                background: #fee2e2;
                color: #dc2626;
            }

            .wpb-log-type-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 8px;
                font-size: 10px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: .4px;
                background: #f3f4f6;
                color: #374151;
            }

            .wpb-log-note {
                font-size: 11px;
                color: #6b7280;
            }

            .wpb-log-note-error {
                color: #dc2626;
                font-weight: 500;
            }

            .wpb-log-time {
                font-size: 11px;
                color: #9ca3af;
                white-space: nowrap;
            }

            .wpb-log-empty {
                text-align: center;
                padding: 40px 20px;
                color: #6b7280;
                font-size: 13px;
            }

            /* Spinner */
            @keyframes wpb-spin {
                to {
                    transform: rotate(360deg);
                }
            }

            .wpb-spinner {
                width: 13px;
                height: 13px;
                border: 2px solid rgba(255, 255, 255, .3);
                border-top-color: #fff;
                border-radius: 50%;
                animation: wpb-spin .7s linear infinite;
                display: inline-block;
                flex-shrink: 0;
            }

            /* Inclusion controls panel */
            .wpb-inclusion-panel {
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 10px;
                overflow: hidden;
                margin-bottom: 20px;
            }

            .wpb-inclusion-header {
                padding: 12px 20px;
                background: #f9fafb;
                border-bottom: 1px solid #e5e7eb;
                display: flex;
                align-items: center;
                justify-content: space-between;
                cursor: pointer;
                user-select: none;
            }

            .wpb-inclusion-header-left {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .wpb-inclusion-title {
                font-size: 13px;
                font-weight: 600;
                color: #111827;
            }

            .wpb-inclusion-desc {
                font-size: 12px;
                color: #6b7280;
                margin-top: 1px;
            }

            .wpb-inclusion-toggle-icon {
                font-size: 12px;
                color: #6b7280;
                transition: transform .2s;
            }

            .wpb-inclusion-panel.open .wpb-inclusion-toggle-icon {
                transform: rotate(180deg);
            }

            .wpb-inclusion-body {
                display: none;
                padding: 16px 20px;
            }

            .wpb-inclusion-panel.open .wpb-inclusion-body {
                display: block;
            }

            .wpb-inclusion-section {
                margin-bottom: 20px;
            }

            .wpb-inclusion-section:last-child {
                margin-bottom: 0;
            }

            .wpb-inclusion-section-title {
                font-size: 11px;
                font-weight: 700;
                color: #374151;
                text-transform: uppercase;
                letter-spacing: .5px;
                margin-bottom: 8px;
            }

            .wpb-inclusion-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
                gap: 8px;
            }

            .wpb-inclusion-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                padding: 8px 12px;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                background: #fafafa;
            }

            .wpb-inclusion-item-label {
                font-size: 12px;
                font-weight: 600;
                color: #111827;
                flex: 1;
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .wpb-inclusion-item-slug {
                font-size: 10px;
                color: #9ca3af;
                font-family: Consolas, monospace;
                display: block;
                margin-top: 1px;
            }

            .wpb-inclusion-select {
                font-size: 11px;
                font-family: inherit;
                padding: 3px 6px;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                background: #fff;
                color: #111827;
                cursor: pointer;
                flex-shrink: 0;
            }

            .wpb-inclusion-select:focus {
                outline: none;
                border-color: #111827;
            }

            .wpb-inclusion-select[data-mode="exclude"] {
                background: #fee2e2;
                border-color: #f5c2c4;
                color: #dc2626;
            }

            .wpb-inclusion-select[data-mode="include_links"] {
                background: #f0f6fc;
                border-color: #b8d4f5;
                color: #1d4ed8;
            }

            .wpb-inclusion-select[data-mode="include"] {
                background: #dcfce7;
                border-color: #b8e6c1;
                color: #007017;
            }

            /* Toast */
            #wpb-llms-toast {
                position: fixed;
                bottom: 24px;
                right: 24px;
                background: #1d2327;
                color: #fff;
                padding: 10px 18px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 500;
                z-index: 9999;
                opacity: 0;
                transform: translateY(6px);
                transition: all .2s;
                pointer-events: none;
                border-left: 4px solid #059669;
            }

            #wpb-llms-toast.show {
                opacity: 1;
                transform: translateY(0);
            }

            #wpb-llms-toast.error {
                border-left-color: #dc2626;
            }
        </style>

        <style>
            .wpb-llms-hdr {
                display: flex;
                align-items: center;
                gap: 14px;
                margin-bottom: 8px;
            }

            .wpb-llms-hdr-icon {
                width: 40px;
                height: 40px;
                background: #111827;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .wpb-llms-hdr-icon .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
                color: #fff;
            }

            .wpb-llms-hdr h1 {
                font-size: 22px !important;
                font-weight: 700 !important;
                color: #111827 !important;
                margin: 0 !important;
                padding: 0 !important;
                line-height: 1.2 !important;
            }

            .wpb-llms-bc {
                font-size: 14px;
                font-weight: 400;
                color: #9ca3af;
                margin-left: 4px;
            }

            .wpb-llms-sub {
                font-size: 13px;
                color: #6b7280;
                margin: 2px 0 0;
            }

            .wpb-llms-dvd {
                border: none;
                border-top: 1px solid #f3f4f6;
                margin: 14px 0 20px;
            }

            .wpb-llms-tabs {
                display: flex;
                gap: 2px;
                border-bottom: 2px solid #e5e7eb;
                margin-bottom: 24px;
            }

            .wpb-llms-tab {
                display: inline-flex;
                align-items: center;
                padding: 9px 18px;
                font-size: 13px;
                font-weight: 600;
                color: #6b7280;
                text-decoration: none !important;
                border-bottom: 2px solid transparent;
                margin-bottom: -2px;
                border-radius: 6px 6px 0 0;
                transition: color .15s;
            }

            .wpb-llms-tab:hover {
                color: #111827;
                background: #f9fafb;
            }

            .wpb-llms-tab.active {
                color: #111827;
                border-bottom-color: #111827;
            }

            .wpb-llms-save-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 9px 20px;
                background: #111827;
                color: #fff;
                font-size: 13px;
                font-weight: 600;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                font-family: inherit;
                transition: opacity .15s;
            }

            .wpb-llms-save-btn:hover {
                opacity: .85;
            }
        </style>
        <div id="wpb-llms-wrap" class="wrap">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                <div class="wpb-llms-hdr">
                    <div class="wpb-llms-hdr-icon"><span class="dashicons dashicons-text-page"></span></div>
                    <div>
                        <h1>WP Barrio <span class="wpb-llms-bc">/ Auto LLMs</span></h1>
                        <p class="wpb-llms-sub">Genera automaticamente llms.txt e llms-full.txt con AI</p>
                    </div>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-seo')); ?>"
                    style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;font-size:12.5px;font-weight:600;background:#f3f4f6;color:#374151 !important;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none !important;white-space:nowrap;flex-shrink:0;align-self:flex-start;">
                    <span class="dashicons dashicons-arrow-left-alt2"
                        style="font-size:14px;width:14px;height:14px;margin-top:1px;"></span>
                    Strumenti SEO
                </a>
            </div>
            <hr class="wpb-llms-dvd">

            <!-- Stats -->
            <div class="wpb-stats-bar">
                <div class="wpb-stats-bar-item">
                    <div class="wpb-stats-bar-num <?php echo $short_exists ? 'wpb-ok' : 'wpb-err'; ?>">
                        <?php echo $short_exists ? '✓' : '✗'; ?>
                    </div>
                    <div class="wpb-stats-bar-lbl">llms.txt</div>
                </div>
                <div class="wpb-stats-bar-item">
                    <div class="wpb-stats-bar-num <?php echo $full_exists ? 'wpb-ok' : 'wpb-err'; ?>">
                        <?php echo $full_exists ? '✓' : '✗'; ?>
                    </div>
                    <div class="wpb-stats-bar-lbl">llms-full.txt</div>
                </div>
                <div class="wpb-stats-bar-item">
                    <div class="wpb-stats-bar-num" style="font-size:16px;padding-top:6px;color:#374151;">
                        <?php echo $short_size; ?>
                    </div>
                    <div class="wpb-stats-bar-lbl">Dim. llms.txt</div>
                </div>
                <div class="wpb-stats-bar-item">
                    <div class="wpb-stats-bar-num" style="font-size:16px;padding-top:6px;color:#374151;">
                        <?php echo $full_size; ?>
                    </div>
                    <div class="wpb-stats-bar-lbl">Dim. llms-full.txt</div>
                </div>
                <div class="wpb-stats-bar-item">
                    <div class="wpb-stats-bar-num" style="font-size:13px;padding-top:8px;">
                        <?php echo $last_gen ? date_i18n('d/m/Y H:i', $last_gen['ts']) : '—'; ?>
                    </div>
                    <div class="wpb-stats-bar-lbl">Ultima generazione</div>
                </div>
            </div>

            <div class="wpb-llms-tabs">
                <a href="?page=wp-barrio-auto-llms&tab=generate"
                    class="wpb-llms-tab <?php echo $tab === 'generate' ? 'active' : ''; ?>">Genera</a>
                <a href="?page=wp-barrio-auto-llms&tab=log"
                    class="wpb-llms-tab <?php echo $tab === 'log' ? 'active' : ''; ?>">Log</a>
                <a href="?page=wp-barrio-auto-llms&tab=settings"
                    class="wpb-llms-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>">Impostazioni</a>
            </div>

            <?php

            /* ══════════════════════════════════════
               TAB: GENERA
               ══════════════════════════════════════ */
            if ($tab === 'generate'):

                if (empty($api_key)): ?>
                            <div class="notice notice-warning inline" style="margin-bottom:16px;">
                                ⚠️ Chiave API non configurata. <a href="?page=wp-barrio-auto-llms&tab=settings">Vai alle impostazioni →</a>
                            </div>
                    <?php endif;

                // Raccoglie CPT e tassonomie per il pannello
                $all_post_types = wpb_llms_get_configurable_post_types();
                $all_taxonomies = wpb_llms_get_configurable_taxonomies();
                $saved_pt_modes = get_option('wpb_llms_pt_modes', []);
                $saved_tax_modes = get_option('wpb_llms_tax_modes', []);
                ?>

                    <!-- Pannello inclusione CPT e tassonomie -->
                    <div class="wpb-inclusion-panel" id="wpb-inclusion-panel">
                        <div class="wpb-inclusion-header" id="wpb-inclusion-toggle">
                            <div class="wpb-inclusion-header-left">
                                <span style="font-size:18px;">🗂</span>
                                <div>
                                    <div class="wpb-inclusion-title">Contenuti da includere</div>
                                    <div class="wpb-inclusion-desc">Scegli quali post type e tassonomie includere nei file generati
                                    </div>
                                </div>
                            </div>
                            <span class="wpb-inclusion-toggle-icon">▼</span>
                        </div>
                        <div class="wpb-inclusion-body">

                            <?php if (!empty($all_post_types)): ?>
                                    <div class="wpb-inclusion-section">
                                        <div class="wpb-inclusion-section-title">Post Type</div>
                                        <div class="wpb-inclusion-grid">
                                            <?php foreach ($all_post_types as $slug => $info):
                                                $saved_mode = $saved_pt_modes[$slug] ?? 'include';
                                                ?>
                                                    <div class="wpb-inclusion-item">
                                                        <div>
                                                            <div class="wpb-inclusion-item-label"><?php echo esc_html($info['label']); ?></div>
                                                            <span class="wpb-inclusion-item-slug"><?php echo esc_html($slug); ?></span>
                                                        </div>
                                                        <select class="wpb-inclusion-select" data-type="pt" data-slug="<?php echo esc_attr($slug); ?>"
                                                            data-mode="<?php echo esc_attr($saved_mode); ?>">
                                                            <option value="include" <?php selected($saved_mode, 'include'); ?>>✓ Includi</option>
                                                            <option value="include_links" <?php selected($saved_mode, 'include_links'); ?>>↗ Solo link
                                                            </option>
                                                            <option value="exclude" <?php selected($saved_mode, 'exclude'); ?>>✗ Escludi</option>
                                                        </select>
                                                    </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                            <?php endif; ?>

                            <?php if (!empty($all_taxonomies)): ?>
                                    <div class="wpb-inclusion-section">
                                        <div class="wpb-inclusion-section-title">Tassonomie</div>
                                        <div class="wpb-inclusion-grid">
                                            <?php foreach ($all_taxonomies as $slug => $info):
                                                $saved_mode = $saved_tax_modes[$slug] ?? 'include';
                                                ?>
                                                    <div class="wpb-inclusion-item">
                                                        <div>
                                                            <div class="wpb-inclusion-item-label"><?php echo esc_html($info['label']); ?></div>
                                                            <span class="wpb-inclusion-item-slug"><?php echo esc_html($slug); ?></span>
                                                        </div>
                                                        <select class="wpb-inclusion-select" data-type="tax" data-slug="<?php echo esc_attr($slug); ?>"
                                                            data-mode="<?php echo esc_attr($saved_mode); ?>">
                                                            <option value="include" <?php selected($saved_mode, 'include'); ?>>✓ Includi</option>
                                                            <option value="include_links" <?php selected($saved_mode, 'include_links'); ?>>↗ Solo link
                                                            </option>
                                                            <option value="exclude" <?php selected($saved_mode, 'exclude'); ?>>✗ Escludi</option>
                                                        </select>
                                                    </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                            <?php endif; ?>

                            <div
                                style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid #f0f0f1;">
                                <p style="font-size:11px;color:#646970;margin:0;">
                                    <strong>✓ Includi</strong> — titolo, URL e contenuto &nbsp;·&nbsp;
                                    <strong>↗ Solo link</strong> — solo titolo e URL &nbsp;·&nbsp;
                                    <strong>✗ Escludi</strong> — non incluso nei record generati &nbsp;·&nbsp;
                                    <strong>Sintetico</strong> max 10 voci · <strong>Completo</strong> tutte
                                </p>
                                <button class="wpb-btn-gen-llms secondary" id="btn-save-inclusion"
                                    style="padding:6px 16px;font-size:12px;flex-shrink:0;">
                                    💾 Salva selezione
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- LLMs sintetico -->
                    <div class="wpb-gen-panel">
                        <div class="wpb-gen-header">
                            <div class="wpb-gen-icon">📄</div>
                            <div>
                                <div class="wpb-gen-title">LLMs sintetico — <code>hbLlms</code></div>
                                <div class="wpb-gen-desc">
                                    Versione sintetica: introduzione GEO-ottimizzata + max 10 voci per sezione.
                                    Esposto via GraphQL con la query <code>hbLlms { content updatedAt bytes }</code>.
                                </div>
                            </div>
                        </div>
                        <div class="wpb-gen-body">
                            <div class="wpb-file-meta">
                                <span class="wpb-file-badge <?php echo $short_exists ? 'exists' : 'missing'; ?>">
                                    <?php echo $short_exists ? '✓ File presente' : '○ Non ancora generato'; ?>
                                </span>
                                <?php if ($short_exists): ?>
                                        <span style="font-size:12px;color:#646970;align-self:center;">
                                            <?php echo $short_size; ?> —
                                            <?php echo date_i18n('d/m/Y H:i', filemtime(ABSPATH . 'llms.txt')); ?>
                                        </span>
                                <?php endif; ?>
                            </div>
                            <div class="wpb-gen-actions">
                                <button class="wpb-btn-gen-llms" id="btn-gen-short" <?php echo empty($api_key) ? 'disabled' : ''; ?>>
                                    ✦ Genera LLMs sintetico
                                </button>
                                <?php if ($short_exists): ?>
                                        <a href="<?php echo esc_url(get_bloginfo('url') . '/llms.txt'); ?>" target="_blank"
                                            class="wpb-btn-gen-llms secondary wpb-btn-preview-llms" style="text-decoration:none;">
                                            👁 Anteprima
                                        </a>
                                <?php endif; ?>
                            </div>
                            <div class="wpb-gen-progress" id="progress-short">
                                <div class="wpb-progress-bar-inner" id="pbar-short"></div>
                                <div class="wpb-progress-label" id="plabel-short">Raccolta contenuto sito…</div>
                            </div>
                            <div class="wpb-result-box" id="result-short">
                                <div class="wpb-result-title">✓ Generato con successo</div>
                                <div class="wpb-result-files" id="result-short-files"></div>
                            </div>
                        </div>
                    </div>

                    <!-- LLMs completo -->
                    <div class="wpb-gen-panel">
                        <div class="wpb-gen-header">
                            <div class="wpb-gen-icon">📃</div>
                            <div>
                                <div class="wpb-gen-title">LLMs completo — <code>hbLlmsFull</code></div>
                                <div class="wpb-gen-desc">
                                    Versione estesa: introduzione GEO-ottimizzata + tutte le voci con contenuto completo.
                                    Esposto via GraphQL con la query <code>hbLlmsFull { content updatedAt bytes }</code>.
                                </div>
                            </div>
                        </div>
                        <div class="wpb-gen-body">
                            <div class="wpb-file-meta">
                                <span class="wpb-file-badge <?php echo $full_exists ? 'exists' : 'missing'; ?>">
                                    <?php echo $full_exists ? '✓ File presente' : '○ Non ancora generato'; ?>
                                </span>
                                <?php if ($full_exists): ?>
                                        <span style="font-size:12px;color:#646970;align-self:center;">
                                            <?php echo $full_size; ?> —
                                            <?php echo date_i18n('d/m/Y H:i', filemtime(ABSPATH . 'llms-full.txt')); ?>
                                        </span>
                                <?php endif; ?>
                            </div>
                            <div class="wpb-gen-actions">
                                <button class="wpb-btn-gen-llms" id="btn-gen-full" <?php echo empty($api_key) ? 'disabled' : ''; ?>>
                                    ✦ Genera LLMs completo
                                </button>
                                <?php if ($full_exists): ?>
                                        <a href="<?php echo esc_url(get_bloginfo('url') . '/llms-full.txt'); ?>" target="_blank"
                                            class="wpb-btn-gen-llms secondary wpb-btn-preview-llms" style="text-decoration:none;">
                                            👁 Anteprima
                                        </a>
                                <?php endif; ?>
                            </div>
                            <div class="wpb-gen-progress" id="progress-full">
                                <div class="wpb-progress-bar-inner" id="pbar-full"></div>
                                <div class="wpb-progress-label" id="plabel-full">Raccolta contenuto completo del sito…</div>
                            </div>
                            <div class="wpb-result-box" id="result-full">
                                <div class="wpb-result-title">✓ Generato con successo</div>
                                <div class="wpb-result-files" id="result-full-files"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Genera entrambi -->
                    <div style="display:flex;gap:8px;align-items:center;margin-top:4px;">
                        <button class="wpb-btn-gen-llms" id="btn-gen-both" <?php echo empty($api_key) ? 'disabled' : ''; ?>>
                            ✦ Genera entrambi i record
                        </button>
                        <span style="font-size:12px;color:#646970;">Genera sintetico e completo in sequenza.</span>
                    </div>

                    <?php

                /* ══════════════════════════════════════
                   TAB: LOG
                   ══════════════════════════════════════ */
            elseif ($tab === 'log'):

                if (!get_option('wpb_llms_log_enabled', 1)): ?>
                            <div class="notice notice-warning inline">
                                <p>
                                    <strong>Registrazione log disabilitata.</strong>
                                    Per riattivare vai alle <a href="?page=wp-barrio-auto-llms&tab=settings">Impostazioni</a>.
                                </p>
                            </div>
                    <?php else:
                    $log_success = count(array_filter($log, fn($e) => $e['status'] === 'success'));
                    $log_errors = count(array_filter($log, fn($e) => $e['status'] === 'error'));
                    ?>
                            <div class="wpb-log-stats">
                                <div class="wpb-log-stat">
                                    <div class="n"><?php echo count($log); ?></div>
                                    <div class="l">Totale operazioni</div>
                                </div>
                                <div class="wpb-log-stat">
                                    <div class="n green"><?php echo $log_success; ?></div>
                                    <div class="l">Completate</div>
                                </div>
                                <div class="wpb-log-stat">
                                    <div class="n red"><?php echo $log_errors; ?></div>
                                    <div class="l">Errori</div>
                                </div>
                            </div>

                            <div class="wpb-log-toolbar">
                                <div class="wpb-log-filters">
                                    <button class="wpb-log-filter active" data-filter="all">Tutti</button>
                                    <button class="wpb-log-filter" data-filter="success">Successi</button>
                                    <button class="wpb-log-filter" data-filter="error">Errori</button>
                                </div>
                                <button class="wpb-btn-clear" id="btn-clear-log">🗑 Svuota log</button>
                            </div>

                            <?php if (empty($log)): ?>
                                    <div class="wpb-log-empty">Nessuna operazione registrata ancora.</div>
                            <?php else: ?>
                                    <div class="wpb-log-table-wrap">
                                        <table class="wpb-log-table" id="llms-log-table">
                                            <thead>
                                                <tr>
                                                    <th>Stato</th>
                                                    <th>Tipo</th>
                                                    <th>Nota</th>
                                                    <th>Data / ora</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($log as $entry):
                                                    $is_err = ($entry['status'] === 'error');
                                                    $label = $is_err ? '✗ Errore' : '✓ Successo';
                                                    $type_labels = ['short' => 'Sintetico', 'full' => 'Completo', 'both' => 'Entrambi'];
                                                    ?>
                                                        <tr data-status="<?php echo esc_attr($entry['status']); ?>">
                                                            <td>
                                                                <span class="wpb-log-status <?php echo esc_attr($entry['status']); ?>">
                                                                    <?php echo $label; ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <span class="wpb-log-type-badge">
                                                                    <?php echo esc_html($type_labels[$entry['type']] ?? $entry['type']); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <div class="wpb-log-note<?php echo $is_err ? ' wpb-log-note-error' : ''; ?>">
                                                                    <?php echo esc_html($entry['note'] ?: '—'); ?>
                                                                </div>
                                                            </td>
                                                            <td><span class="wpb-log-time"><?php echo date_i18n('d/m/Y H:i', $entry['ts']); ?></span></td>
                                                        </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                            <?php endif; ?>
                    <?php endif; ?>

                    <?php

                /* ══════════════════════════════════════
                   TAB: IMPOSTAZIONI
                   ══════════════════════════════════════ */
            elseif ($tab === 'settings'):
                $provider = get_option('wpb_llms_provider', 'anthropic');
                $model = get_option('wpb_llms_model', 'claude-sonnet-4-6');
                $key_anth = get_option('wpb_llms_api_key', '');
                $key_gem = get_option('wpb_llms_gemini_key', '');
                $lang = get_option('wpb_llms_language', 'it');
                $tone = get_option('wpb_llms_tone', 'professional');
                $focus = get_option('wpb_llms_focus', '');
                $log_en = get_option('wpb_llms_log_enabled', 1);

                $providers = [
                    'anthropic' => [
                        'logo' => '🟣',
                        'name' => 'Anthropic — Claude',
                        'desc' => 'Claude Opus, Sonnet, Haiku — eccellente per testi strutturati e documentazione',
                        'key_field' => 'wpb_llms_api_key',
                        'key_val' => $key_anth,
                        'key_ph' => 'sk-ant-api03-...',
                        'key_url' => 'https://console.anthropic.com/',
                        'key_label' => 'Chiave API Anthropic',
                        'models' => [
                            'claude-opus-4-6' => ['name' => 'Claude Opus 4.6', 'desc' => 'Massima qualità, testi più ricchi e dettagliati'],
                            'claude-sonnet-4-6' => ['name' => 'Claude Sonnet 4.6', 'desc' => 'Equilibrio ottimale qualità/velocità'],
                            'claude-haiku-4-5-20251001' => ['name' => 'Claude Haiku 4.5', 'desc' => 'Veloce ed economico'],
                        ],
                        'default_model' => 'claude-sonnet-4-6',
                    ],
                    'gemini' => [
                        'logo' => '🔵',
                        'name' => 'Google — Gemini',
                        'desc' => 'Gemini 2.x Flash & Pro — ottimo rapporto qualità/costo',
                        'key_field' => 'wpb_llms_gemini_key',
                        'key_val' => $key_gem,
                        'key_ph' => 'AIza...',
                        'key_url' => 'https://aistudio.google.com/app/apikey',
                        'key_label' => 'Chiave API Google AI Studio',
                        'models' => [
                            'gemini-2.5-pro' => ['name' => 'Gemini 2.5 Pro', 'desc' => 'Massima qualità, ragionamento avanzato'],
                            'gemini-2.5-flash' => ['name' => 'Gemini 2.5 Flash', 'desc' => 'Ottimo equilibrio qualità/velocità'],
                            'gemini-2.0-flash' => ['name' => 'Gemini 2.0 Flash', 'desc' => 'Veloce ed economico'],
                            'gemini-2.0-flash-lite' => ['name' => 'Gemini 2.0 Flash-Lite', 'desc' => 'Ultraveloce, alta frequenza'],
                        ],
                        'default_model' => 'gemini-2.5-flash',
                    ],
                ];
                ?>

                    <form method="post" action="options.php">
                        <?php settings_fields('wpb_llms_settings'); ?>

                        <table class="form-table" role="presentation">
                            <tbody>

                                <!-- Provider + Modello -->
                                <tr>
                                    <th scope="row" style="width:200px;">Provider e modello AI</th>
                                    <td>
                                        <div id="wpb-llms-provider-list">
                                            <?php foreach ($providers as $pval => $pdata):
                                                $is_sel = ($provider === $pval);
                                                $p_models = $pdata['models'];
                                                $active_model = ($is_sel && array_key_exists($model, $p_models)) ? $model : $pdata['default_model'];
                                                ?>
                                                    <div class="wpb-provider-block">
                                                        <label class="wpb-provider-card <?php echo $is_sel ? 'selected' : ''; ?>"
                                                            data-provider="<?php echo esc_attr($pval); ?>">
                                                            <input type="radio" name="wpb_llms_provider" value="<?php echo esc_attr($pval); ?>"
                                                                <?php checked($provider, $pval); ?>>
                                                            <span class="wpb-pc-logo"><?php echo $pdata['logo']; ?></span>
                                                            <div>
                                                                <div class="wpb-pc-name"><?php echo esc_html($pdata['name']); ?></div>
                                                                <div class="wpb-pc-desc"><?php echo esc_html($pdata['desc']); ?></div>
                                                            </div>
                                                        </label>
                                                        <div class="wpb-provider-keys <?php echo $is_sel ? 'visible' : ''; ?>"
                                                            id="wpb-llms-keys-<?php echo esc_attr($pval); ?>">
                                                            <div class="wpb-key-group">
                                                                <label><?php echo esc_html($pdata['key_label']); ?></label>
                                                                <input type="password" name="<?php echo esc_attr($pdata['key_field']); ?>"
                                                                    value="<?php echo esc_attr($pdata['key_val']); ?>"
                                                                    placeholder="<?php echo esc_attr($pdata['key_ph']); ?>" autocomplete="off">
                                                                <div class="wpb-key-hint">Ottieni la chiave su
                                                                    <a href="<?php echo esc_url($pdata['key_url']); ?>" target="_blank">
                                                                        <?php echo esc_html($pdata['key_url']); ?>
                                                                    </a>
                                                                </div>
                                                            </div>
                                                            <div class="wpb-key-group">
                                                                <label>Modello</label>
                                                                <div class="wpb-model-group">
                                                                    <?php foreach ($p_models as $mval => $mdata):
                                                                        $m_checked = ($active_model === $mval);
                                                                        ?>
                                                                            <label class="wpb-model-label <?php echo $m_checked ? 'selected' : ''; ?>">
                                                                                <input type="radio" name="wpb_llms_model"
                                                                                    value="<?php echo esc_attr($mval); ?>" <?php checked($active_model, $mval); ?>                                 <?php echo !$is_sel ? 'disabled' : ''; ?>>
                                                                                <div>
                                                                                    <div class="wpb-model-name"><?php echo esc_html($mdata['name']); ?>
                                                                                    </div>
                                                                                    <div class="wpb-model-desc"><?php echo esc_html($mdata['desc']); ?>
                                                                                    </div>
                                                                                    <div class="wpb-model-id"><?php echo esc_html($mval); ?></div>
                                                                                </div>
                                                                            </label>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Lingua -->
                                <tr>
                                    <th scope="row"><label for="wpb_llms_language">Lingua dell'output</label></th>
                                    <td>
                                        <select name="wpb_llms_language" id="wpb_llms_language">
                                            <option value="it" <?php selected($lang, 'it'); ?>>🇮🇹 Italiano</option>
                                            <option value="en" <?php selected($lang, 'en'); ?>>🇬🇧 English</option>
                                            <option value="es" <?php selected($lang, 'es'); ?>>🇪🇸 Español</option>
                                            <option value="fr" <?php selected($lang, 'fr'); ?>>🇫🇷 Français</option>
                                            <option value="de" <?php selected($lang, 'de'); ?>>🇩🇪 Deutsch</option>
                                        </select>
                                        <p class="description">Lingua in cui verranno generati i testi nei file.</p>
                                    </td>
                                </tr>

                                <!-- Tono -->
                                <tr>
                                    <th scope="row"><label for="wpb_llms_tone">Registro linguistico</label></th>
                                    <td>
                                        <select name="wpb_llms_tone" id="wpb_llms_tone">
                                            <option value="professional" <?php selected($tone, 'professional'); ?>>Professionale e
                                                chiaro</option>
                                            <option value="technical" <?php selected($tone, 'technical'); ?>>Tecnico e preciso</option>
                                            <option value="friendly" <?php selected($tone, 'friendly'); ?>>Amichevole e accessibile
                                            </option>
                                            <option value="concise" <?php selected($tone, 'concise'); ?>>Conciso e diretto</option>
                                        </select>
                                        <p class="description">Tono da usare nella generazione dei contenuti.</p>
                                    </td>
                                </tr>

                                <!-- Focus tematico -->
                                <tr>
                                    <th scope="row"><label for="wpb_llms_focus">Focus tematico</label></th>
                                    <td>
                                        <input type="text" name="wpb_llms_focus" id="wpb_llms_focus"
                                            value="<?php echo esc_attr($focus); ?>" class="regular-text"
                                            placeholder="es. agenzia di comunicazione, e-commerce di moda, blog tech…">
                                        <p class="description">Descrive in breve il tema principale del sito. Aiuta l'AI a
                                            contestualizzare meglio il contenuto.</p>
                                    </td>
                                </tr>

                                <!-- Log -->
                                <tr>
                                    <th scope="row">Registrazione log</th>
                                    <td>
                                        <div class="wpb-toggle-row">
                                            <label class="wpb-toggle">
                                                <input type="checkbox" name="wpb_llms_log_enabled" value="1" <?php checked($log_en, 1); ?>>
                                                <span class="wpb-toggle-track"></span>
                                            </label>
                                            <div class="wpb-toggle-info">
                                                <div class="wpb-toggle-title">
                                                    <?php echo $log_en ? '✓ Attivo — ogni generazione viene registrata nel Log' : 'Disattivo — le operazioni non vengono registrate'; ?>
                                                </div>
                                                <div class="wpb-toggle-desc">
                                                    Se attivo, ogni generazione AI viene salvata nella scheda Log con stato e dettagli.
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>

                            </tbody>
                        </table>

                        <button type="submit" class="wpb-llms-save-btn"><span class="dashicons dashicons-yes"
                                style="font-size:16px;width:16px;height:16px;"></span> Salva impostazioni</button>
                    </form>

                    <script>
                        (function () {
                            var ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
                            var nonce = '<?php echo wp_create_nonce("wpb_llms_nonce"); ?>';
                            var currentModel = '<?php echo esc_js(get_option("wpb_llms_model", "")); ?>';
                            var modelDefaults = { anthropic: 'claude-sonnet-4-6', gemini: 'gemini-2.5-flash' };

                            function updateModelHighlight() {
                                document.querySelectorAll('.wpb-model-label').forEach(function (lbl) {
                                    var r = lbl.querySelector('input[type=radio]');
                                    lbl.classList.toggle('selected', !!(r && r.checked && !r.disabled));
                                });
                            }

                            function renderModels(panel, models, selectedId) {
                                var group = panel.querySelector('.wpb-model-group');
                                if (!group) return;
                                var prov = panel.id.replace('wpb-llms-keys-', '');
                                var chosen = selectedId || modelDefaults[prov] || Object.keys(models)[0];
                                group.innerHTML = '';
                                Object.keys(models).forEach(function (id) {
                                    var m = models[id];
                                    var chk = (id === chosen);
                                    var lbl = document.createElement('label');
                                    lbl.className = 'wpb-model-label' + (chk ? ' selected' : '');
                                    lbl.innerHTML =
                                        '<input type="radio" name="wpb_llms_model" value="' + id + '"' + (chk ? ' checked' : '') + ' style="margin-top:2px;flex-shrink:0;">' +
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

                            function loadModels(provider, panel, force) {
                                var statusEl = panel.querySelector('.wpb-model-status');
                                if (statusEl) statusEl.textContent = 'Caricamento modelli…';
                                var body = new URLSearchParams({ action: 'wpb_llms_fetch_models', nonce: nonce, provider: provider, force: force ? '1' : '' });
                                fetch(ajaxUrl, { method: 'POST', body: body })
                                    .then(function (r) { return r.json(); })
                                    .then(function (data) {
                                        if (statusEl) statusEl.textContent = '';
                                        if (data.success && Object.keys(data.data).length > 0) {
                                            var sel = (currentModel && data.data[currentModel]) ? currentModel : null;
                                            renderModels(panel, data.data, sel);
                                            if (statusEl) { statusEl.textContent = '✓ ' + Object.keys(data.data).length + ' modelli'; statusEl.style.color = '#16a34a'; }
                                        } else {
                                            if (statusEl) { statusEl.textContent = data.data || 'Errore caricamento modelli'; statusEl.style.color = '#dc2626'; }
                                        }
                                    }).catch(function () {
                                        if (statusEl) { statusEl.textContent = 'Errore di rete'; statusEl.style.color = '#dc2626'; }
                                    });
                            }

                            document.querySelectorAll('.wpb-provider-keys').forEach(function (panel) {
                                var group = panel.querySelector('.wpb-model-group');
                                if (!group) return;
                                var bar = document.createElement('div');
                                bar.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:8px;';
                                bar.innerHTML =
                                    '<span class="wpb-model-status" style="font-size:11px;color:#888;flex:1;"></span>' +
                                    '<button type="button" class="button button-small btn-refresh-models" style="font-size:11px;padding:3px 10px;cursor:pointer;">↻ Aggiorna</button>';
                                group.parentNode.insertBefore(bar, group);
                                bar.querySelector('.btn-refresh-models').addEventListener('click', function () {
                                    currentModel = '';
                                    loadModels(panel.id.replace('wpb-llms-keys-', ''), panel, true);
                                });
                            });

                            document.querySelectorAll('input[name="wpb_llms_provider"]').forEach(function (radio) {
                                radio.addEventListener('change', function () {
                                    var sel = this.value;
                                    document.querySelectorAll('.wpb-provider-card').forEach(function (c) {
                                        c.classList.toggle('selected', c.dataset.provider === sel);
                                    });
                                    document.querySelectorAll('.wpb-provider-keys').forEach(function (panel) {
                                        var prov = panel.id.replace('wpb-llms-keys-', '');
                                        var active = (prov === sel);
                                        panel.classList.toggle('visible', active);
                                        panel.querySelectorAll('input[type=radio][name="wpb_llms_model"]').forEach(function (r) {
                                            r.disabled = !active;
                                        });
                                        if (active) { currentModel = ''; loadModels(prov, panel, false); }
                                    });
                                });
                            });

                            document.querySelectorAll('.wpb-provider-keys.visible').forEach(function (panel) {
                                loadModels(panel.id.replace('wpb-llms-keys-', ''), panel, false);
                            });

                            updateModelHighlight();
                        })();
                    </script>

            <?php endif; ?>
        </div><!-- #wpb-llms-wrap -->

        <div id="wpb-llms-toast"></div>

        <script>
            (function () {
                var ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
                var nonce = '<?php echo wp_create_nonce("wpb_llms_nonce"); ?>';

                function toast(msg, type) {
                    var t = document.getElementById('wpb-llms-toast');
                    t.textContent = msg;
                    t.className = 'show' + (type === 'error' ? ' error' : '');
                    setTimeout(function () { t.className = ''; }, 3500);
                }

                /* ── Progress helper ── */
                function startProgress(id, label) {
                    var wrap = document.getElementById('progress-' + id);
                    var bar = document.getElementById('pbar-' + id);
                    var lbl = document.getElementById('plabel-' + id);
                    if (!wrap) return;
                    wrap.classList.add('active');
                    bar.style.width = '15%';
                    lbl.textContent = label || 'Elaborazione…';
                    return { wrap, bar, lbl };
                }

                function advanceProgress(p, pct, label) {
                    if (!p) return;
                    p.bar.style.width = pct + '%';
                    if (label) p.lbl.textContent = label;
                }

                function doneProgress(p, label) {
                    if (!p) return;
                    p.bar.style.width = '100%';
                    p.bar.style.background = '#059669';
                    p.lbl.textContent = label || '✓ Completato';
                }

                function errorProgress(p, msg) {
                    if (!p) return;
                    p.bar.style.width = '100%';
                    p.bar.style.background = '#d63638';
                    p.lbl.innerHTML =
                        '<span style="display:inline-flex;align-items:flex-start;gap:8px;">' +
                        '<span style="color:#d63638;font-weight:700;flex-shrink:0;">✗</span>' +
                        '<span style="color:#d63638;word-break:break-word;">' + (msg || 'Generazione fallita') + '</span>' +
                        '</span>';
                }

                function showResult(id, records) {
                    var box = document.getElementById('result-' + id);
                    var filesEl = document.getElementById('result-' + id + '-files');
                    if (!box || !filesEl) return;
                    filesEl.innerHTML = '';
                    records.forEach(function (r) {
                        var span = document.createElement('span');
                        span.className = 'wpb-result-file';
                        span.innerHTML = '✓ ' + r.label + ' <span style="color:#646970">(' + formatBytes(r.bytes) + ')</span>';
                        filesEl.appendChild(span);
                    });
                    box.classList.add('visible');
                }

                function formatBytes(b) {
                    if (b < 1024) return b + ' B';
                    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
                    return (b / 1048576).toFixed(1) + ' MB';
                }

                async function generate(type) {
                    var ids = type === 'both' ? ['short', 'full'] : [type];
                    var btns = { short: document.getElementById('btn-gen-short'), full: document.getElementById('btn-gen-full'), both: document.getElementById('btn-gen-both') };

                    Object.values(btns).forEach(function (b) { if (b) b.disabled = true; });

                    var progs = {};
                    if (type === 'both' || type === 'short') progs.short = startProgress('short', 'Raccolta contenuto sito…');
                    if (type === 'both' || type === 'full') progs.full = startProgress('full', 'Raccolta contenuto completo…');

                    // Avanza la barra durante l'attesa (può durare 30-90s)
                    var tick = 0;
                    var timer = setInterval(function () {
                        tick++;
                        var labels = ['Raccolta contenuto sito…', 'Analisi struttura pagine…', 'Invio al modello AI…', 'Generazione in corso…', 'Ottimizzazione output…', 'Scrittura file…'];
                        var pct = Math.min(85, 15 + tick * 12);
                        ids.forEach(function (id) { advanceProgress(progs[id], pct, labels[Math.min(tick, labels.length - 1)]); });
                    }, 8000);

                    try {
                        var ptModes = {};
                        var taxModes = {};
                        document.querySelectorAll('.wpb-inclusion-select').forEach(function (sel) {
                            if (sel.dataset.type === 'pt') ptModes[sel.dataset.slug] = sel.value;
                            if (sel.dataset.type === 'tax') taxModes[sel.dataset.slug] = sel.value;
                        });

                        var params = new URLSearchParams({ action: 'wpb_llms_generate', nonce: nonce, type: type });
                        Object.keys(ptModes).forEach(function (k) { params.append('pt_modes[' + k + ']', ptModes[k]); });
                        Object.keys(taxModes).forEach(function (k) { params.append('tax_modes[' + k + ']', taxModes[k]); });

                        var res = await fetch(ajaxUrl, { method: 'POST', body: params });
                        var data = await res.json();
                        clearInterval(timer);

                        if (data.data && data.data.error) {
                            var errType = data.data.error_type;
                            var targets = errType ? [errType] : ids;
                            targets.forEach(function (id) { errorProgress(progs[id], data.data.error); });
                            Object.values(btns).forEach(function (b) { if (b) b.disabled = false; });
                            return;
                        }

                        if (data.data.short) doneProgress(progs.short, '✓ llms.txt generato');
                        if (data.data.full) doneProgress(progs.full, '✓ llms-full.txt generato');

                        toast('✓ File generati con successo');
                        setTimeout(function () { location.reload(); }, 1500);

                    } catch (e) {
                        clearInterval(timer);
                        ids.forEach(function (id) { errorProgress(progs[id], 'Errore di rete: ' + e.message); });
                        Object.values(btns).forEach(function (b) { if (b) b.disabled = false; });
                    }
                }

                var btnShort = document.getElementById('btn-gen-short');
                var btnFull = document.getElementById('btn-gen-full');
                var btnBoth = document.getElementById('btn-gen-both');
                if (btnShort) btnShort.addEventListener('click', function () { generate('short'); });
                if (btnFull) btnFull.addEventListener('click', function () { generate('full'); });
                if (btnBoth) btnBoth.addEventListener('click', function () { generate('both'); });

                /* ── Svuota log ── */
                var clearLogBtn = document.getElementById('btn-clear-log');
                if (clearLogBtn) {
                    clearLogBtn.addEventListener('click', function () {
                        if (!confirm('Svuotare tutto il log? L\'operazione è irreversibile.')) return;
                        fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'wpb_llms_clear_log', nonce: nonce }) })
                            .then(function () { location.reload(); });
                    });
                }

                /* ── Toggle pannello inclusione ── */
                var inclusionToggle = document.getElementById('wpb-inclusion-toggle');
                var inclusionPanel = document.getElementById('wpb-inclusion-panel');
                if (inclusionToggle && inclusionPanel) {
                    inclusionToggle.addEventListener('click', function () {
                        inclusionPanel.classList.toggle('open');
                    });
                }

                /* ── Colore select inclusione al cambio ── */
                document.querySelectorAll('.wpb-inclusion-select').forEach(function (sel) {
                    sel.addEventListener('change', function () {
                        sel.dataset.mode = sel.value;
                    });
                });

                /* ── Salva selezione inclusione ── */
                var saveIncBtn = document.getElementById('btn-save-inclusion');
                if (saveIncBtn) {
                    saveIncBtn.addEventListener('click', function (e) {
                        e.stopPropagation(); // non aprire/chiudere il pannello
                        saveIncBtn.disabled = true;
                        saveIncBtn.textContent = '⏳ Salvataggio…';

                        var params = new URLSearchParams({ action: 'wpb_llms_save_inclusion', nonce: nonce });
                        document.querySelectorAll('.wpb-inclusion-select').forEach(function (sel) {
                            if (sel.dataset.type === 'pt') params.append('pt_modes[' + sel.dataset.slug + ']', sel.value);
                            if (sel.dataset.type === 'tax') params.append('tax_modes[' + sel.dataset.slug + ']', sel.value);
                        });

                        fetch(ajaxUrl, { method: 'POST', body: params })
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                if (data.data && data.data.error) {
                                    saveIncBtn.textContent = '✗ Errore';
                                    setTimeout(function () { saveIncBtn.disabled = false; saveIncBtn.textContent = '💾 Salva selezione'; }, 2500);
                                } else {
                                    saveIncBtn.textContent = '✓ Salvato';
                                    saveIncBtn.style.background = '#edfaef';
                                    saveIncBtn.style.borderColor = '#b8e6c1';
                                    saveIncBtn.style.color = '#007017';
                                    setTimeout(function () {
                                        saveIncBtn.disabled = false;
                                        saveIncBtn.textContent = '💾 Salva selezione';
                                        saveIncBtn.style.background = '';
                                        saveIncBtn.style.borderColor = '';
                                        saveIncBtn.style.color = '';
                                    }, 2500);
                                }
                            })
                            .catch(function () {
                                saveIncBtn.disabled = false;
                                saveIncBtn.textContent = '💾 Salva selezione';
                            });
                    });
                }

                /* ── Filtro log ── */
                document.querySelectorAll('.wpb-log-filter').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        document.querySelectorAll('.wpb-log-filter').forEach(function (b) { b.classList.remove('active'); });
                        btn.classList.add('active');
                        var filter = btn.dataset.filter;
                        document.querySelectorAll('#llms-log-table tbody tr').forEach(function (row) {
                            row.style.display = (filter === 'all' || row.dataset.status === filter) ? '' : 'none';
                        });
                    });
                });
            })();
        </script>
        <?php
}