<?php
/**
 * Suite Barrio — Auto LLMs
 * Genera automaticamente llms.txt e llms-full.txt tramite AI (Gemini o Anthropic Claude).
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   COSTANTI
   ───────────────────────────────────────── */
if (!defined('SUITE_BARRIO_LLMS_LOG_OPTION'))
    define('SUITE_BARRIO_LLMS_LOG_OPTION', 'suite_barrio_llms_log');
if (!defined('SUITE_BARRIO_LLMS_LOG_MAX'))
    define('SUITE_BARRIO_LLMS_LOG_MAX', 100);
if (!defined('SUITE_BARRIO_LLMS_CACHE_OPTION'))
    define('SUITE_BARRIO_LLMS_CACHE_OPTION', 'suite_barrio_llms_last_generated');

/* ─────────────────────────────────────────
   HELPER: scrivi una riga nel log
   ───────────────────────────────────────── */
function suite_barrio_llms_log($status, $type = '', $note = '')
{
    if (!get_option('suite_barrio_llms_log_enabled', 1))
        return;

    $log = get_option(SUITE_BARRIO_LLMS_LOG_OPTION, []);
    array_unshift($log, [
        'ts' => current_time('timestamp'),
        'status' => $status,   // 'success' | 'error'
        'type' => $type,     // 'short' | 'full' | 'both'
        'note' => $note,
    ]);
    if (count($log) > SUITE_BARRIO_LLMS_LOG_MAX) {
        $log = array_slice($log, 0, SUITE_BARRIO_LLMS_LOG_MAX);
    }
    update_option(SUITE_BARRIO_LLMS_LOG_OPTION, $log, false);
}

/* ─────────────────────────────────────────
   HELPER: chiave API del provider attivo
   ───────────────────────────────────────── */
function suite_barrio_llms_active_key()
{
    $provider = get_option('suite_barrio_llms_provider', 'anthropic');
    return $provider === 'gemini'
        ? get_option('suite_barrio_llms_gemini_key', '')
        : get_option('suite_barrio_llms_api_key', '');
}

/* ─────────────────────────────────────────
   HELPER: recupera modelli dal provider (con cache 24h)
   ───────────────────────────────────────── */
function suite_barrio_llms_fetch_models($provider)
{
    $cache_key = 'suite_barrio_llms_models_' . $provider;
    $cached = get_transient($cache_key);
    if ($cached !== false)
        return $cached;

    $models = [];

    if ($provider === 'anthropic') {
        $api_key = get_option('suite_barrio_llms_api_key', '');
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
        $api_key = get_option('suite_barrio_llms_gemini_key', '');
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
function suite_barrio_llms_get_configurable_post_types()
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

function suite_barrio_llms_get_configurable_taxonomies()
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
function suite_barrio_llms_build_markdown($include_full = false, $pt_modes = [], $tax_modes = [])
{
    $site_name = get_bloginfo('name');
    $site_url = get_bloginfo('url');
    $lang = get_bloginfo('language');
    $limit = $include_full ? 500 : 10;

    // Determina le lingue da includere
    $wpml_active = defined('ICL_SITEPRESS_VERSION')
        && function_exists('suite_barrio_multilang_is_enabled')
        && suite_barrio_multilang_is_enabled();
    $languages = $wpml_active ? suite_barrio_multilang_languages() : [null => null];
    $primary = $wpml_active && function_exists('suite_barrio_multilang_primary_language')
        ? suite_barrio_multilang_primary_language()
        : null;

    $md = '';

    foreach (array_keys($languages) as $lang_code) {

        // Forza la lingua corrente per tutte le query WPML
        if ($wpml_active) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoca l'hook di WPML, non ne definisce uno nuovo
            do_action('wpml_switch_language', $lang_code);
            // Filtro aggiuntivo: assicura che WP_Query riceva il lang corretto
            add_filter('wpml_current_language', function () use ($lang_code) {
                return $lang_code;
            }, 999);
        }

        // Intestazione di sezione per lingua (solo se multilingua attivo)
        $lang_flag = $wpml_active && function_exists('suite_barrio_multilang_flag') ? suite_barrio_multilang_flag((string) $lang_code) : '';
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
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
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
                    // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- necessario per bypassare filtri multilingua/query interna
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
                    $text = wp_strip_all_tags(apply_filters('the_content', $post->post_content) /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook core di WordPress, non definito da noi */);
                    $text = preg_replace('/\s+/', ' ', trim($text));
                    if (!empty($text)) {
                        $max_chars = ($slug === 'page') ? 3000 : 4000;
                        $md .= mb_substr($text, 0, $max_chars) . "\n\n";
                    }
                } else {
                    $excerpt = get_the_excerpt($post->ID);
                    if (empty($excerpt)) {
                        $text = wp_strip_all_tags(apply_filters('the_content', $post->post_content) /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook core di WordPress, non definito da noi */);
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
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
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
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoca l'hook di WPML, non ne definisce uno nuovo
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
function suite_barrio_llms_collect_site_content($include_full = false, $pt_modes = [], $tax_modes = [])
{
    $built = suite_barrio_llms_build_markdown($include_full, $pt_modes, $tax_modes);
    return $built['raw_info'] . $built['body'];
}

/* ─────────────────────────────────────────
   HELPER: chiama l'AI per generare SOLO la descrizione introduttiva
   Ritorna ['short_intro' => '...', 'full_intro' => '...'] oppure ['error' => '...']
   ───────────────────────────────────────── */
function suite_barrio_llms_generate_intro($site_info, $generate_type)
{
    $provider = get_option('suite_barrio_llms_provider', 'anthropic');
    $model = get_option('suite_barrio_llms_model', 'claude-sonnet-4-6') ?: 'claude-sonnet-4-6';
    $lang = get_option('suite_barrio_llms_language', 'it');
    $tone = get_option('suite_barrio_llms_tone', 'professional');
    $focus = get_option('suite_barrio_llms_focus', '');

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
        $api_key = get_option('suite_barrio_llms_gemini_key', '');
        if (empty($api_key))
            return ['error' => 'Chiave API Gemini non configurata'];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . $api_key;
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
        $api_key = get_option('suite_barrio_llms_api_key', '');
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
function suite_barrio_llms_call_api($site_content, $generate_type, $pt_modes = [], $tax_modes = [])
{
    // Questo wrapper non viene più usato nel flusso principale
    // (il generate handler usa suite_barrio_llms_build_markdown + suite_barrio_llms_generate_intro)
    return ['error' => 'Metodo deprecato — usa suite_barrio_llms_generate_intro.'];
}

/* ─────────────────────────────────────────
   HELPER: scrive il file nella root di WordPress
   ───────────────────────────────────────── */
function suite_barrio_llms_write_file($filename, $content)
{
    $path = ABSPATH . $filename;
    // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- il file deve risiedere nella webroot per essere raggiungibile pubblicamente; WP_Filesystem richiederebbe credenziali FTP non disponibili in questo contesto (salvataggio da AJAX admin)
    $result = file_put_contents($path, $content);
    if ($result === false) {
        return ['error' => 'Impossibile scrivere ' . $filename . '. Verifica i permessi sulla root del sito.'];
    }
    return ['bytes' => $result];
}

/* ─────────────────────────────────────────
   HELPER: legge un file dalla root
   ───────────────────────────────────────── */
function suite_barrio_llms_read_file($filename)
{
    $path = ABSPATH . $filename;
    if (!file_exists($path))
        return null;
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lettura locale del file nella webroot appena scritto da suite_barrio_llms_write_file(), non un URL remoto
    return file_get_contents($path);
}

/* ─────────────────────────────────────────
   AJAX: recupera modelli live
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_llms_fetch_models', function () {
    check_ajax_referer('suite_barrio_llms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_success(['error' => 'Permesso negato.']);

    $provider = sanitize_key($_POST['provider'] ?? 'anthropic');
    $force = !empty($_POST['force']);
    if ($force)
        delete_transient('suite_barrio_llms_models_' . $provider);

    $models = suite_barrio_llms_fetch_models($provider);
    if ($models === null) {
        wp_send_json_success(['error' => 'Impossibile recuperare i modelli. Verifica la chiave API.']);
    }
    wp_send_json_success($models);
});

/* ─────────────────────────────────────────
   AJAX: genera i file llms
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_llms_generate', function () {
    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- alcuni host disabilitano set_time_limit(); @ evita il warning, necessario per operazioni batch lunghe
    if (function_exists('set_time_limit')) @set_time_limit(300);

    check_ajax_referer('suite_barrio_llms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_success(['error' => 'Permesso negato.']);

    $type = sanitize_key($_POST['type'] ?? 'both');

    $api_key = suite_barrio_llms_active_key();
    if (empty($api_key)) {
        $provider = get_option('suite_barrio_llms_provider', 'anthropic');
        $msg = 'Chiave API non configurata per il provider: ' . $provider;
        suite_barrio_llms_log('error', $type, $msg);
        wp_send_json_success(['error' => $msg]);
    }

    $allowed = ['include', 'include_links', 'exclude'];
    $pt_modes = [];
    $tax_modes = [];
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array validato elemento per elemento nel foreach successivo (sanitize_key + whitelist $allowed)
    $raw_pt = !empty($_POST['pt_modes']) ? (array) wp_unslash($_POST['pt_modes']) : get_option('suite_barrio_llms_pt_modes', []);
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array validato elemento per elemento nel foreach successivo (sanitize_key + whitelist $allowed)
    $raw_tax = !empty($_POST['tax_modes']) ? (array) wp_unslash($_POST['tax_modes']) : get_option('suite_barrio_llms_tax_modes', []);
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
        $built = suite_barrio_llms_build_markdown(false, $pt_modes, $tax_modes);
        $intro = suite_barrio_llms_generate_intro($built['raw_info'], 'short');

        if (isset($intro['error'])) {
            suite_barrio_llms_log('error', 'short', $intro['error']);
            wp_send_json_success(['error' => $intro['error'], 'error_type' => 'short']);
        }

        $final = "# {$built['site_name']}\n\n> {$built['site_url']}\n\n";
        $final .= $intro['intro'] . "\n" . $built['body'];

        $write = suite_barrio_llms_write_file('llms.txt', $final);
        if (isset($write['error'])) {
            suite_barrio_llms_log('error', 'short', $write['error']);
            wp_send_json_success(['error' => $write['error'], 'error_type' => 'short']);
        }
        $results['short'] = ['label' => 'llms.txt', 'bytes' => $write['bytes']];
    }

    // Genera llms-full.txt
    if (in_array($type, ['full', 'both'])) {
        $built_full = suite_barrio_llms_build_markdown(true, $pt_modes, $tax_modes);
        $intro_full = suite_barrio_llms_generate_intro($built_full['raw_info'], 'full');

        if (isset($intro_full['error'])) {
            suite_barrio_llms_log('error', 'full', $intro_full['error']);
            wp_send_json_success(['error' => $intro_full['error'], 'error_type' => 'full']);
        }

        $final_full = "# {$built_full['site_name']}\n\n> {$built_full['site_url']}\n\n";
        $final_full .= $intro_full['intro'] . "\n" . $built_full['body'];

        $write_full = suite_barrio_llms_write_file('llms-full.txt', $final_full);
        if (isset($write_full['error'])) {
            suite_barrio_llms_log('error', 'full', $write_full['error']);
            wp_send_json_success(['error' => $write_full['error'], 'error_type' => 'full']);
        }
        $results['full'] = ['label' => 'llms-full.txt', 'bytes' => $write_full['bytes']];
    }

    update_option(SUITE_BARRIO_LLMS_CACHE_OPTION, ['ts' => current_time('timestamp'), 'type' => $type], false);
    $provider = get_option('suite_barrio_llms_provider', 'anthropic');
    $model = get_option('suite_barrio_llms_model', '');
    suite_barrio_llms_log('success', $type, 'Generato con ' . $provider . ($model ? ' / ' . $model : ''));

    if (function_exists('suite_barrio_invalidate_vercel_cache')) {
        suite_barrio_invalidate_vercel_cache('wp:llms');
    }
    wp_send_json_success($results);
});

/* ─────────────────────────────────────────
   AJAX: salva le impostazioni di inclusione
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_llms_save_inclusion', function () {
    check_ajax_referer('suite_barrio_llms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_success(['error' => 'Permesso negato.']);

    $allowed = ['include', 'include_links', 'exclude'];
    $pt_modes = [];
    $tax_modes = [];

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array validato elemento per elemento nel foreach (sanitize_key + whitelist $allowed)
    foreach ((array) wp_unslash($_POST['pt_modes'] ?? []) as $slug => $mode) {
        $slug = sanitize_key($slug);
        $mode = in_array($mode, $allowed) ? $mode : 'include';
        if ($slug)
            $pt_modes[$slug] = $mode;
    }
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array validato elemento per elemento nel foreach (sanitize_key + whitelist $allowed)
    foreach ((array) wp_unslash($_POST['tax_modes'] ?? []) as $slug => $mode) {
        $slug = sanitize_key($slug);
        $mode = in_array($mode, $allowed) ? $mode : 'include';
        if ($slug)
            $tax_modes[$slug] = $mode;
    }

    update_option('suite_barrio_llms_pt_modes', $pt_modes, false);
    update_option('suite_barrio_llms_tax_modes', $tax_modes, false);

    if (function_exists('suite_barrio_invalidate_vercel_cache')) {
        suite_barrio_invalidate_vercel_cache('wp:llms');
    }

    wp_send_json_success();
});

/* ─────────────────────────────────────────
   AJAX: svuota log
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_llms_clear_log', function () {
    check_ajax_referer('suite_barrio_llms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_success(['error' => 'Permesso negato.']);

    delete_option(SUITE_BARRIO_LLMS_LOG_OPTION);
    wp_send_json_success();
});

/* ─────────────────────────────────────────
   SOPPRIME settings_errors SULLA PAGINA AUTO LLMS
   WordPress chiama settings_errors() automaticamente in cima
   a ogni pagina admin: questo evita che messaggi di errore API
   finiscano visualizzati come notice globali.
   ───────────────────────────────────────── */
add_action('load-suite-barrio_page_suite-barrio-auto-llms', function () {
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
        'description' => 'Contenuto LLMs generato da Suite Barrio Auto LLMs',
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
            $content = suite_barrio_llms_read_file('llms.txt');
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
            $content = suite_barrio_llms_read_file('llms-full.txt');
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
    if (!suite_barrio_tools_module_enabled('seo'))
        return;

    // Figlio di suite-barrio (mantiene capability check) ma nascosto via CSS
    add_submenu_page(
        'suite-barrio',
        'Auto LLMs — Suite Barrio',
        'Auto LLMs',
        'manage_options',
        'suite-barrio-auto-llms',
        'suite_barrio_llms_admin_page'
    );
}, 15);

add_action('admin_enqueue_scripts', function ($hook) {
    // CSS: nasconde la voce di sottomenu (deve valere su tutte le pagine admin,
    // perché il menu laterale è visibile ovunque)
    wp_add_inline_style(
        'suite-barrio-admin',
        '#adminmenu a[href="admin.php?page=suite-barrio-auto-llms"]{display:none!important}'
    );

    // JS: handle vuoto per il codice inline della pagina Auto LLMs, solo su questa pagina
    if ($hook !== 'suite-barrio_page_suite-barrio-auto-llms')
        return;

    wp_register_script('suite-barrio-llms-inline', '', [], SUITE_BARRIO_VERSION, true);
    wp_enqueue_script('suite-barrio-llms-inline');
}, 20);

add_filter('submenu_file', function ($submenu_file) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    if (isset($_GET['page']) && $_GET['page'] === 'suite-barrio-auto-llms')
        return 'suite-barrio-seo';
    return $submenu_file;
});

/* ─────────────────────────────────────────
   2. IMPOSTAZIONI
   ───────────────────────────────────────── */
add_action('admin_init', function () {
    register_setting('suite_barrio_llms_settings', 'suite_barrio_llms_provider', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('suite_barrio_llms_settings', 'suite_barrio_llms_api_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('suite_barrio_llms_settings', 'suite_barrio_llms_gemini_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('suite_barrio_llms_settings', 'suite_barrio_llms_model', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('suite_barrio_llms_settings', 'suite_barrio_llms_language', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('suite_barrio_llms_settings', 'suite_barrio_llms_tone', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'professional']);
    register_setting('suite_barrio_llms_settings', 'suite_barrio_llms_focus', ['sanitize_callback' => 'sanitize_text_field', 'default' => '']);
    register_setting('suite_barrio_llms_settings', 'suite_barrio_llms_log_enabled', ['sanitize_callback' => 'absint', 'default' => 1]);
});

/* ─────────────────────────────────────────
   3. PAGINA ADMIN
   ───────────────────────────────────────── */
function suite_barrio_llms_admin_page()
{
    $api_key = suite_barrio_llms_active_key();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'generate';
    $last_gen = get_option(SUITE_BARRIO_LLMS_CACHE_OPTION, null);
    $log = get_option(SUITE_BARRIO_LLMS_LOG_OPTION, []);

    $short_exists = file_exists(ABSPATH . 'llms.txt');
    $full_exists = file_exists(ABSPATH . 'llms-full.txt');
    $short_size = $short_exists ? size_format(filesize(ABSPATH . 'llms.txt')) : '—';
    $full_size = $full_exists ? size_format(filesize(ABSPATH . 'llms-full.txt')) : '—';
    ?>
<div id="suite-barrio-llms-wrap" class="wrap suite-barrio-wrap">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                <div class="suite-barrio-llms-hdr">
                    <div class="suite-barrio-llms-hdr-icon"><span class="dashicons dashicons-text-page"></span></div>
                    <div>
                        <h1>Suite Barrio <span class="suite-barrio-llms-bc">/ Auto LLMs</span></h1>
                        <p class="suite-barrio-llms-sub">Genera automaticamente llms.txt e llms-full.txt con AI</p>
                    </div>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-seo')); ?>"
                    style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;font-size:12.5px;font-weight:600;background:#f3f4f6;color:#374151 !important;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none !important;white-space:nowrap;flex-shrink:0;align-self:flex-start;">
                    <span class="dashicons dashicons-arrow-left-alt2"
                        style="font-size:14px;width:14px;height:14px;margin-top:1px;"></span>
                    Strumenti SEO
                </a>
            </div>
            <hr class="suite-barrio-llms-dvd">

            <!-- Stats -->
            <div class="suite-barrio-stats-bar">
                <div class="suite-barrio-stats-bar-item">
                    <div class="suite-barrio-stats-bar-num <?php echo $short_exists ? 'suite-barrio-ok' : 'suite-barrio-err'; ?>">
                        <?php echo $short_exists ? '✓' : '✗'; ?>
                    </div>
                    <div class="suite-barrio-stats-bar-lbl">llms.txt</div>
                </div>
                <div class="suite-barrio-stats-bar-item">
                    <div class="suite-barrio-stats-bar-num <?php echo $full_exists ? 'suite-barrio-ok' : 'suite-barrio-err'; ?>">
                        <?php echo $full_exists ? '✓' : '✗'; ?>
                    </div>
                    <div class="suite-barrio-stats-bar-lbl">llms-full.txt</div>
                </div>
                <div class="suite-barrio-stats-bar-item">
                    <div class="suite-barrio-stats-bar-num">
                        <?php echo esc_html($short_size); ?>
                    </div>
                    <div class="suite-barrio-stats-bar-lbl">Dim. llms.txt</div>
                </div>
                <div class="suite-barrio-stats-bar-item">
                    <div class="suite-barrio-stats-bar-num">
                        <?php echo esc_html($full_size); ?>
                    </div>
                    <div class="suite-barrio-stats-bar-lbl">Dim. llms-full.txt</div>
                </div>
                <div class="suite-barrio-stats-bar-item">
                    <div class="suite-barrio-stats-bar-num" style="font-size:13px;">
                        <?php echo $last_gen ? esc_html(date_i18n('d/m/Y H:i', $last_gen['ts'])) : '—'; ?>
                    </div>
                    <div class="suite-barrio-stats-bar-lbl">Ultima generazione</div>
                </div>
            </div>

            <div class="suite-barrio-llms-tabs">
                <a href="?page=suite-barrio-auto-llms&tab=generate"
                    class="suite-barrio-llms-tab <?php echo $tab === 'generate' ? 'active' : ''; ?>">Genera</a>
                <a href="?page=suite-barrio-auto-llms&tab=log"
                    class="suite-barrio-llms-tab <?php echo $tab === 'log' ? 'active' : ''; ?>">Log</a>
                <a href="?page=suite-barrio-auto-llms&tab=settings"
                    class="suite-barrio-llms-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>">Impostazioni</a>
            </div>

            <?php

            /* ══════════════════════════════════════
               TAB: GENERA
               ══════════════════════════════════════ */
            if ($tab === 'generate'):

                if (empty($api_key)): ?>
                            <div class="notice notice-warning inline" style="margin-bottom:16px;">
                                ⚠️ Chiave API non configurata. <a href="?page=suite-barrio-auto-llms&tab=settings">Vai alle impostazioni →</a>
                            </div>
                    <?php endif;

                // Raccoglie CPT e tassonomie per il pannello
                $all_post_types = suite_barrio_llms_get_configurable_post_types();
                $all_taxonomies = suite_barrio_llms_get_configurable_taxonomies();
                $saved_pt_modes = get_option('suite_barrio_llms_pt_modes', []);
                $saved_tax_modes = get_option('suite_barrio_llms_tax_modes', []);
                ?>

                    <!-- Pannello inclusione CPT e tassonomie -->
                    <div class="suite-barrio-inclusion-panel" id="suite-barrio-inclusion-panel">
                        <div class="suite-barrio-inclusion-header" id="suite-barrio-inclusion-toggle">
                            <div class="suite-barrio-inclusion-header-left">
                                <span style="font-size:18px;">🗂</span>
                                <div>
                                    <div class="suite-barrio-inclusion-title">Contenuti da includere</div>
                                    <div class="suite-barrio-inclusion-desc">Scegli quali post type e tassonomie includere nei file generati
                                    </div>
                                </div>
                            </div>
                            <span class="suite-barrio-inclusion-toggle-icon">▼</span>
                        </div>
                        <div class="suite-barrio-inclusion-body">

                            <?php if (!empty($all_post_types)): ?>
                                    <div class="suite-barrio-inclusion-section">
                                        <div class="suite-barrio-inclusion-section-title">Post Type</div>
                                        <div class="suite-barrio-inclusion-grid">
                                            <?php foreach ($all_post_types as $slug => $info):
                                                $saved_mode = $saved_pt_modes[$slug] ?? 'include';
                                                ?>
                                                    <div class="suite-barrio-inclusion-item">
                                                        <div>
                                                            <div class="suite-barrio-inclusion-item-label"><?php echo esc_html($info['label']); ?></div>
                                                            <span class="suite-barrio-inclusion-item-slug"><?php echo esc_html($slug); ?></span>
                                                        </div>
                                                        <select class="suite-barrio-inclusion-select" data-type="pt" data-slug="<?php echo esc_attr($slug); ?>"
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
                                    <div class="suite-barrio-inclusion-section">
                                        <div class="suite-barrio-inclusion-section-title">Tassonomie</div>
                                        <div class="suite-barrio-inclusion-grid">
                                            <?php foreach ($all_taxonomies as $slug => $info):
                                                $saved_mode = $saved_tax_modes[$slug] ?? 'include';
                                                ?>
                                                    <div class="suite-barrio-inclusion-item">
                                                        <div>
                                                            <div class="suite-barrio-inclusion-item-label"><?php echo esc_html($info['label']); ?></div>
                                                            <span class="suite-barrio-inclusion-item-slug"><?php echo esc_html($slug); ?></span>
                                                        </div>
                                                        <select class="suite-barrio-inclusion-select" data-type="tax" data-slug="<?php echo esc_attr($slug); ?>"
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
                                <button class="suite-barrio-btn-gen-llms secondary" id="btn-save-inclusion"
                                    style="padding:6px 16px;font-size:12px;flex-shrink:0;">
                                    💾 Salva selezione
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- LLMs sintetico -->
                    <div class="suite-barrio-gen-panel">
                        <div class="suite-barrio-gen-header">
                            <div class="suite-barrio-gen-icon">📄</div>
                            <div>
                                <div class="suite-barrio-gen-title">LLMs sintetico — <code>hbLlms</code></div>
                                <div class="suite-barrio-gen-desc">
                                    Versione sintetica: introduzione GEO-ottimizzata + max 10 voci per sezione.
                                    Esposto via GraphQL con la query <code>hbLlms { content updatedAt bytes }</code>.
                                </div>
                            </div>
                        </div>
                        <div class="suite-barrio-gen-body">
                            <div class="suite-barrio-file-meta">
                                <span class="suite-barrio-file-badge <?php echo $short_exists ? 'exists' : 'missing'; ?>">
                                    <?php echo $short_exists ? '✓ File presente' : '○ Non ancora generato'; ?>
                                </span>
                                <?php if ($short_exists): ?>
                                        <span style="font-size:12px;color:#646970;align-self:center;">
                                            <?php echo esc_html($short_size); ?> —
                                            <?php echo esc_html(date_i18n('d/m/Y H:i', filemtime(ABSPATH . 'llms.txt'))); ?>
                                        </span>
                                <?php endif; ?>
                            </div>
                            <div class="suite-barrio-gen-actions">
                                <button class="suite-barrio-btn-gen-llms" id="btn-gen-short" <?php echo empty($api_key) ? 'disabled' : ''; ?>>
                                    ✦ Genera LLMs sintetico
                                </button>
                                <?php if ($short_exists): ?>
                                        <a href="<?php echo esc_url(get_bloginfo('url') . '/llms.txt'); ?>" target="_blank"
                                            class="suite-barrio-btn-gen-llms secondary suite-barrio-btn-preview-llms" style="text-decoration:none;">
                                            👁 Anteprima
                                        </a>
                                <?php endif; ?>
                            </div>
                            <div class="suite-barrio-gen-progress" id="progress-short">
                                <div class="suite-barrio-progress-bar-inner" id="pbar-short"></div>
                                <div class="suite-barrio-progress-label" id="plabel-short">Raccolta contenuto sito…</div>
                            </div>
                            <div class="suite-barrio-result-box" id="result-short">
                                <div class="suite-barrio-result-title">✓ Generato con successo</div>
                                <div class="suite-barrio-result-files" id="result-short-files"></div>
                            </div>
                        </div>
                    </div>

                    <!-- LLMs completo -->
                    <div class="suite-barrio-gen-panel">
                        <div class="suite-barrio-gen-header">
                            <div class="suite-barrio-gen-icon">📃</div>
                            <div>
                                <div class="suite-barrio-gen-title">LLMs completo — <code>hbLlmsFull</code></div>
                                <div class="suite-barrio-gen-desc">
                                    Versione estesa: introduzione GEO-ottimizzata + tutte le voci con contenuto completo.
                                    Esposto via GraphQL con la query <code>hbLlmsFull { content updatedAt bytes }</code>.
                                </div>
                            </div>
                        </div>
                        <div class="suite-barrio-gen-body">
                            <div class="suite-barrio-file-meta">
                                <span class="suite-barrio-file-badge <?php echo $full_exists ? 'exists' : 'missing'; ?>">
                                    <?php echo $full_exists ? '✓ File presente' : '○ Non ancora generato'; ?>
                                </span>
                                <?php if ($full_exists): ?>
                                        <span style="font-size:12px;color:#646970;align-self:center;">
                                            <?php echo esc_html($full_size); ?> —
                                            <?php echo esc_html(date_i18n('d/m/Y H:i', filemtime(ABSPATH . 'llms-full.txt'))); ?>
                                        </span>
                                <?php endif; ?>
                            </div>
                            <div class="suite-barrio-gen-actions">
                                <button class="suite-barrio-btn-gen-llms" id="btn-gen-full" <?php echo empty($api_key) ? 'disabled' : ''; ?>>
                                    ✦ Genera LLMs completo
                                </button>
                                <?php if ($full_exists): ?>
                                        <a href="<?php echo esc_url(get_bloginfo('url') . '/llms-full.txt'); ?>" target="_blank"
                                            class="suite-barrio-btn-gen-llms secondary suite-barrio-btn-preview-llms" style="text-decoration:none;">
                                            👁 Anteprima
                                        </a>
                                <?php endif; ?>
                            </div>
                            <div class="suite-barrio-gen-progress" id="progress-full">
                                <div class="suite-barrio-progress-bar-inner" id="pbar-full"></div>
                                <div class="suite-barrio-progress-label" id="plabel-full">Raccolta contenuto completo del sito…</div>
                            </div>
                            <div class="suite-barrio-result-box" id="result-full">
                                <div class="suite-barrio-result-title">✓ Generato con successo</div>
                                <div class="suite-barrio-result-files" id="result-full-files"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Genera entrambi -->
                    <div style="display:flex;gap:8px;align-items:center;margin-top:4px;">
                        <button class="suite-barrio-btn-gen-llms" id="btn-gen-both" <?php echo empty($api_key) ? 'disabled' : ''; ?>>
                            ✦ Genera entrambi i record
                        </button>
                        <span style="font-size:12px;color:#646970;">Genera sintetico e completo in sequenza.</span>
                    </div>

                    <?php

                /* ══════════════════════════════════════
                   TAB: LOG
                   ══════════════════════════════════════ */
            elseif ($tab === 'log'):

                if (!get_option('suite_barrio_llms_log_enabled', 1)): ?>
                            <div class="notice notice-warning inline">
                                <p>
                                    <strong>Registrazione log disabilitata.</strong>
                                    Per riattivare vai alle <a href="?page=suite-barrio-auto-llms&tab=settings">Impostazioni</a>.
                                </p>
                            </div>
                    <?php else:
                    $log_success = count(array_filter($log, fn($e) => $e['status'] === 'success'));
                    $log_errors = count(array_filter($log, fn($e) => $e['status'] === 'error'));
                    ?>
                            <div class="suite-barrio-log-stats">
                                <div class="suite-barrio-log-stat">
                                    <div class="n"><?php echo count($log); ?></div>
                                    <div class="l">Totale operazioni</div>
                                </div>
                                <div class="suite-barrio-log-stat">
                                    <div class="n green"><?php echo esc_html($log_success); ?></div>
                                    <div class="l">Completate</div>
                                </div>
                                <div class="suite-barrio-log-stat">
                                    <div class="n red"><?php echo esc_html($log_errors); ?></div>
                                    <div class="l">Errori</div>
                                </div>
                            </div>

                            <div class="suite-barrio-log-toolbar">
                                <div class="suite-barrio-log-filters">
                                    <button class="suite-barrio-log-filter active" data-filter="all">Tutti</button>
                                    <button class="suite-barrio-log-filter" data-filter="success">Successi</button>
                                    <button class="suite-barrio-log-filter" data-filter="error">Errori</button>
                                </div>
                                <button class="suite-barrio-btn-clear" id="btn-clear-log">🗑 Svuota log</button>
                            </div>

                            <?php if (empty($log)): ?>
                                    <div class="suite-barrio-log-empty">Nessuna operazione registrata ancora.</div>
                            <?php else: ?>
                                    <div class="suite-barrio-log-table-wrap">
                                        <table class="suite-barrio-log-table" id="llms-log-table">
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
                                                                <span class="suite-barrio-log-status <?php echo esc_attr($entry['status']); ?>">
                                                                    <?php echo esc_html($label); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <span class="suite-barrio-log-type-badge">
                                                                    <?php echo esc_html($type_labels[$entry['type']] ?? $entry['type']); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <div class="suite-barrio-log-note<?php echo $is_err ? ' suite-barrio-log-note-error' : ''; ?>">
                                                                    <?php echo esc_html($entry['note'] ?: '—'); ?>
                                                                </div>
                                                            </td>
                                                            <td><span class="suite-barrio-log-time"><?php echo esc_html(date_i18n('d/m/Y H:i', $entry['ts'])); ?></span></td>
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
                $provider = get_option('suite_barrio_llms_provider', 'anthropic');
                $model = get_option('suite_barrio_llms_model', 'claude-sonnet-4-6');
                $key_anth = get_option('suite_barrio_llms_api_key', '');
                $key_gem = get_option('suite_barrio_llms_gemini_key', '');
                $lang = get_option('suite_barrio_llms_language', 'it');
                $tone = get_option('suite_barrio_llms_tone', 'professional');
                $focus = get_option('suite_barrio_llms_focus', '');
                $log_en = get_option('suite_barrio_llms_log_enabled', 1);

                $providers = [
                    'anthropic' => [
                        'logo' => '🟣',
                        'name' => 'Anthropic — Claude',
                        'desc' => 'Claude Opus, Sonnet, Haiku — eccellente per testi strutturati e documentazione',
                        'key_field' => 'suite_barrio_llms_api_key',
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
                        'key_field' => 'suite_barrio_llms_gemini_key',
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
                        <?php settings_fields('suite_barrio_llms_settings'); ?>

                        <table class="form-table" role="presentation">
                            <tbody>

                                <!-- Provider + Modello -->
                                <tr>
                                    <th scope="row" style="width:200px;">Provider e modello AI</th>
                                    <td>
                                        <div id="suite-barrio-llms-provider-list">
                                            <?php foreach ($providers as $pval => $pdata):
                                                $is_sel = ($provider === $pval);
                                                $p_models = $pdata['models'];
                                                $active_model = ($is_sel && array_key_exists($model, $p_models)) ? $model : $pdata['default_model'];
                                                ?>
                                                    <div class="suite-barrio-provider-block">
                                                        <label class="suite-barrio-provider-card <?php echo $is_sel ? 'selected' : ''; ?>"
                                                            data-provider="<?php echo esc_attr($pval); ?>">
                                                            <input type="radio" name="suite_barrio_llms_provider" value="<?php echo esc_attr($pval); ?>"
                                                                <?php checked($provider, $pval); ?>>
                                                            <span class="suite-barrio-pc-logo"><?php echo esc_html($pdata['logo']); ?></span>
                                                            <div>
                                                                <div class="suite-barrio-pc-name"><?php echo esc_html($pdata['name']); ?></div>
                                                                <div class="suite-barrio-pc-desc"><?php echo esc_html($pdata['desc']); ?></div>
                                                            </div>
                                                        </label>
                                                        <div class="suite-barrio-provider-keys <?php echo $is_sel ? 'visible' : ''; ?>"
                                                            id="suite-barrio-llms-keys-<?php echo esc_attr($pval); ?>">
                                                            <div class="suite-barrio-key-group">
                                                                <label><?php echo esc_html($pdata['key_label']); ?></label>
                                                                <input type="password" name="<?php echo esc_attr($pdata['key_field']); ?>"
                                                                    value="<?php echo esc_attr($pdata['key_val']); ?>"
                                                                    placeholder="<?php echo esc_attr($pdata['key_ph']); ?>" autocomplete="off">
                                                                <div class="suite-barrio-key-hint">Ottieni la chiave su
                                                                    <a href="<?php echo esc_url($pdata['key_url']); ?>" target="_blank">
                                                                        <?php echo esc_html($pdata['key_url']); ?>
                                                                    </a>
                                                                </div>
                                                            </div>
                                                            <div class="suite-barrio-key-group">
                                                                <label>Modello</label>
                                                                <div class="suite-barrio-model-group">
                                                                    <?php foreach ($p_models as $mval => $mdata):
                                                                        $m_checked = ($active_model === $mval);
                                                                        ?>
                                                                            <label class="suite-barrio-model-label <?php echo $m_checked ? 'selected' : ''; ?>">
                                                                                <input type="radio" name="suite_barrio_llms_model"
                                                                                    value="<?php echo esc_attr($mval); ?>" <?php checked($active_model, $mval); ?>                                 <?php echo !$is_sel ? 'disabled' : ''; ?>>
                                                                                <div>
                                                                                    <div class="suite-barrio-model-name"><?php echo esc_html($mdata['name']); ?>
                                                                                    </div>
                                                                                    <div class="suite-barrio-model-desc"><?php echo esc_html($mdata['desc']); ?>
                                                                                    </div>
                                                                                    <div class="suite-barrio-model-id"><?php echo esc_html($mval); ?></div>
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
                                    <th scope="row"><label for="suite_barrio_llms_language">Lingua dell'output</label></th>
                                    <td>
                                        <select name="suite_barrio_llms_language" id="suite_barrio_llms_language">
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
                                    <th scope="row"><label for="suite_barrio_llms_tone">Registro linguistico</label></th>
                                    <td>
                                        <select name="suite_barrio_llms_tone" id="suite_barrio_llms_tone">
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
                                    <th scope="row"><label for="suite_barrio_llms_focus">Focus tematico</label></th>
                                    <td>
                                        <input type="text" name="suite_barrio_llms_focus" id="suite_barrio_llms_focus"
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
                                        <div class="suite-barrio-toggle-row">
                                            <label class="suite-barrio-toggle">
                                                <input type="checkbox" name="suite_barrio_llms_log_enabled" value="1" <?php checked($log_en, 1); ?>>
                                                <span class="suite-barrio-toggle-track"></span>
                                            </label>
                                            <div class="suite-barrio-toggle-info">
                                                <div class="suite-barrio-toggle-title">
                                                    <?php echo $log_en ? '✓ Attivo — ogni generazione viene registrata nel Log' : 'Disattivo — le operazioni non vengono registrate'; ?>
                                                </div>
                                                <div class="suite-barrio-toggle-desc">
                                                    Se attivo, ogni generazione AI viene salvata nella scheda Log con stato e dettagli.
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>

                            </tbody>
                        </table>

                        <button type="submit" class="suite-barrio-llms-save-btn"><span class="dashicons dashicons-yes"
                                style="font-size:16px;width:16px;height:16px;"></span> Salva impostazioni</button>
                    </form>

                    <?php ob_start(); ?>
                        (function () {
                            var ajaxUrl = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
                            var nonce = '<?php echo esc_js(wp_create_nonce("suite_barrio_llms_nonce")); ?>';
                            var currentModel = '<?php echo esc_js(get_option("suite_barrio_llms_model", "")); ?>';
                            var modelDefaults = { anthropic: 'claude-sonnet-4-6', gemini: 'gemini-2.5-flash' };

                            function updateModelHighlight() {
                                document.querySelectorAll('.suite-barrio-model-label').forEach(function (lbl) {
                                    var r = lbl.querySelector('input[type=radio]');
                                    lbl.classList.toggle('selected', !!(r && r.checked && !r.disabled));
                                });
                            }

                            function renderModels(panel, models, selectedId) {
                                var group = panel.querySelector('.suite-barrio-model-group');
                                if (!group) return;
                                var prov = panel.id.replace('suite-barrio-llms-keys-', '');
                                var chosen = selectedId || modelDefaults[prov] || Object.keys(models)[0];
                                group.innerHTML = '';
                                Object.keys(models).forEach(function (id) {
                                    var m = models[id];
                                    var chk = (id === chosen);
                                    var lbl = document.createElement('label');
                                    lbl.className = 'suite-barrio-model-label' + (chk ? ' selected' : '');
                                    lbl.innerHTML =
                                        '<input type="radio" name="suite_barrio_llms_model" value="' + id + '"' + (chk ? ' checked' : '') + ' style="margin-top:2px;flex-shrink:0;">' +
                                        '<div>' +
                                        '<div class="suite-barrio-model-name">' + (m.name || id) + '</div>' +
                                        (m.desc ? '<div class="suite-barrio-model-desc">' + m.desc + '</div>' : '') +
                                        '<div class="suite-barrio-model-id">' + id + '</div>' +
                                        '</div>';
                                    lbl.querySelector('input').addEventListener('change', updateModelHighlight);
                                    group.appendChild(lbl);
                                });
                                updateModelHighlight();
                            }

                            function loadModels(provider, panel, force) {
                                var statusEl = panel.querySelector('.suite-barrio-model-status');
                                if (statusEl) statusEl.textContent = 'Caricamento modelli…';
                                var body = new URLSearchParams({ action: 'suite_barrio_llms_fetch_models', nonce: nonce, provider: provider, force: force ? '1' : '' });
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

                            document.querySelectorAll('.suite-barrio-provider-keys').forEach(function (panel) {
                                var group = panel.querySelector('.suite-barrio-model-group');
                                if (!group) return;
                                var bar = document.createElement('div');
                                bar.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:8px;';
                                bar.innerHTML =
                                    '<span class="suite-barrio-model-status" style="font-size:11px;color:#888;flex:1;"></span>' +
                                    '<button type="button" class="button button-small btn-refresh-models" style="font-size:11px;padding:3px 10px;cursor:pointer;">↻ Aggiorna</button>';
                                group.parentNode.insertBefore(bar, group);
                                bar.querySelector('.btn-refresh-models').addEventListener('click', function () {
                                    currentModel = '';
                                    loadModels(panel.id.replace('suite-barrio-llms-keys-', ''), panel, true);
                                });
                            });

                            document.querySelectorAll('input[name="suite_barrio_llms_provider"]').forEach(function (radio) {
                                radio.addEventListener('change', function () {
                                    var sel = this.value;
                                    document.querySelectorAll('.suite-barrio-provider-card').forEach(function (c) {
                                        c.classList.toggle('selected', c.dataset.provider === sel);
                                    });
                                    document.querySelectorAll('.suite-barrio-provider-keys').forEach(function (panel) {
                                        var prov = panel.id.replace('suite-barrio-llms-keys-', '');
                                        var active = (prov === sel);
                                        panel.classList.toggle('visible', active);
                                        panel.querySelectorAll('input[type=radio][name="suite_barrio_llms_model"]').forEach(function (r) {
                                            r.disabled = !active;
                                        });
                                        if (active) { currentModel = ''; loadModels(prov, panel, false); }
                                    });
                                });
                            });

                            document.querySelectorAll('.suite-barrio-provider-keys.visible').forEach(function (panel) {
                                loadModels(panel.id.replace('suite-barrio-llms-keys-', ''), panel, false);
                            });

                            updateModelHighlight();
                        })();
                    <?php
                    wp_add_inline_script('suite-barrio-llms-inline', ob_get_clean());
                    ?>

            <?php endif; ?>
        </div><!-- #suite-barrio-llms-wrap -->

        <div id="suite-barrio-llms-toast"></div>

        <?php ob_start(); ?>
            (function () {
                var ajaxUrl = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
                var nonce = '<?php echo esc_js(wp_create_nonce("suite_barrio_llms_nonce")); ?>';

                function toast(msg, type) {
                    var t = document.getElementById('suite-barrio-llms-toast');
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
                        span.className = 'suite-barrio-result-file';
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
                        document.querySelectorAll('.suite-barrio-inclusion-select').forEach(function (sel) {
                            if (sel.dataset.type === 'pt') ptModes[sel.dataset.slug] = sel.value;
                            if (sel.dataset.type === 'tax') taxModes[sel.dataset.slug] = sel.value;
                        });

                        var params = new URLSearchParams({ action: 'suite_barrio_llms_generate', nonce: nonce, type: type });
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
                        fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'suite_barrio_llms_clear_log', nonce: nonce }) })
                            .then(function () { location.reload(); });
                    });
                }

                /* ── Toggle pannello inclusione ── */
                var inclusionToggle = document.getElementById('suite-barrio-inclusion-toggle');
                var inclusionPanel = document.getElementById('suite-barrio-inclusion-panel');
                if (inclusionToggle && inclusionPanel) {
                    inclusionToggle.addEventListener('click', function () {
                        inclusionPanel.classList.toggle('open');
                    });
                }

                /* ── Colore select inclusione al cambio ── */
                document.querySelectorAll('.suite-barrio-inclusion-select').forEach(function (sel) {
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

                        var params = new URLSearchParams({ action: 'suite_barrio_llms_save_inclusion', nonce: nonce });
                        document.querySelectorAll('.suite-barrio-inclusion-select').forEach(function (sel) {
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
                document.querySelectorAll('.suite-barrio-log-filter').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        document.querySelectorAll('.suite-barrio-log-filter').forEach(function (b) { b.classList.remove('active'); });
                        btn.classList.add('active');
                        var filter = btn.dataset.filter;
                        document.querySelectorAll('#llms-log-table tbody tr').forEach(function (row) {
                            row.style.display = (filter === 'all' || row.dataset.status === filter) ? '' : 'none';
                        });
                    });
                });
            })();
        <?php
        wp_add_inline_script('suite-barrio-llms-inline', ob_get_clean());
        ?>
        <?php
}