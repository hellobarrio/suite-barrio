<?php
if (!defined('ABSPATH'))
    exit;

define('WPB_CP_LOG_OPTION', 'wpb_cp_log');
define('WPB_CP_LOG_MAX', 100);

add_action('admin_menu', function () {
    add_submenu_page('wp-barrio', 'Cookie e Privacy', 'Cookie e Privacy', 'manage_options', 'wp-barrio-cookie-privacy', 'wpb_cp_admin_page');
}, 13);

add_action('admin_init', function () {
    register_setting('wpb_cp_content_settings', 'wpb_cp_privacy_page_id', ['sanitize_callback' => 'absint']);
    register_setting('wpb_cp_content_settings', 'wpb_cp_cookie_page_id', ['sanitize_callback' => 'absint']);
    // wpb_cp_cookie_report_script NON è registrata come setting:
    // il salvataggio avviene manualmente qui sotto, in base64, per bypassare
    // ogni filtro kses/WAF che stripperebbe <script>/<iframe>.
    register_setting('wpb_cp_settings', 'wpb_cp_provider', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'gemini']);
    register_setting('wpb_cp_settings', 'wpb_cp_anthropic_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wpb_cp_settings', 'wpb_cp_gemini_key', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wpb_cp_settings', 'wpb_cp_model', ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('wpb_cp_settings', 'wpb_cp_log_enabled', ['sanitize_callback' => 'absint', 'default' => 1]);
});

/* ─────────────────────────────────────────
   Salvataggio manuale del cookie report script.
   admin_init gira PRIMA che options.php processi la form,
   quindi leggiamo $_POST qui e salviamo direttamente.
   options.php non toccherà il campo perché non è registrato.
   ───────────────────────────────────────── */
add_action('admin_init', function () {
    if (
        !empty($_POST['option_page'])
        && $_POST['option_page'] === 'wpb_cp_content_settings'
        && current_user_can('manage_options')
    ) {
        // Il nonce viene verificato da options.php subito dopo, ma
        // noi verifichiamo preventivamente per non salvare su richieste invalide.
        if (wp_verify_nonce($_POST['_wpnonce'] ?? '', 'wpb_cp_content_settings-options')) {
            $raw = isset($_POST['wpb_cp_cookie_report_script'])
                ? trim(wp_unslash($_POST['wpb_cp_cookie_report_script']))
                : '';
            update_option(
                'wpb_cp_cookie_report_script',
                $raw !== '' ? base64_encode($raw) : '',
                false // no autoload
            );
            // Aggiorna subito la pagina cookie policy con il nuovo script
            wpb_cp_sync_report_script_to_page();
        }
    }
}, 1); // Priorità 1: prima di options.php

/* ─────────────────────────────────────────
   HELPER: legge il cookie report script dal DB.
   Il valore è salvato in base64 per evitare che venga
   strippato da options.php / kses / WAF.
   Gestisce anche l'eventuale valore legacy (non base64).
   ───────────────────────────────────────── */
function wpb_cp_get_cookie_report_script(): string
{
    $raw = (string) get_option('wpb_cp_cookie_report_script', '');
    if ($raw === '') {
        return '';
    }
    // Prova a decodificare. In strict mode restituisce false se non è base64 valido
    // (gestisce migrazione da valore legacy non codificato).
    $decoded = base64_decode($raw, true);
    if ($decoded !== false && base64_encode($decoded) === $raw) {
        return $decoded;
    }
    // Fallback: valore legacy non codificato
    return $raw;
}

/* ─────────────────────────────────────────
   HELPER: trova il blocco Gutenberg contenente il cookie report script
   nella pagina Cookie Policy e lo sostituisce con il valore attuale del campo.
   Se il blocco non esiste lo aggiunge in coda. Se lo script è vuoto lo rimuove.
   ───────────────────────────────────────── */
function wpb_cp_sync_report_script_to_page(): void
{
    $cookie_id = absint(get_option('wpb_cp_cookie_page_id', 0));
    if (!$cookie_id) return;

    $page = get_post($cookie_id);
    if (!$page || $page->post_type !== 'page') return;

    $script  = wpb_cp_get_cookie_report_script();
    $content = $page->post_content;

    // Pattern: heading "Cookie utilizzati su questo sito" + blocco wp:html
    $heading = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Cookie utilizzati su questo sito</h2>\n<!-- /wp:heading -->";
    $pattern = '#<!-- wp:heading -->\s*\n\s*<h2[^>]*>\s*Cookie utilizzati su questo sito\s*</h2>\s*\n\s*<!-- /wp:heading -->\s*\n+\s*<!-- wp:html -->\n.*?\n<!-- /wp:html -->#s';

    if ($script !== '') {
        $block = $heading . "\n\n<!-- wp:html -->\n" . $script . "\n<!-- /wp:html -->";
        if (preg_match($pattern, $content)) {
            $new_content = preg_replace($pattern, $block, $content);
        } else {
            $new_content = rtrim($content) . "\n\n" . $block;
        }
    } else {
        // Script vuoto: rimuovi il blocco se esiste
        $new_content = rtrim((string) preg_replace($pattern, '', $content));
    }

    if ($new_content === null || $new_content === $content) return;

    kses_remove_filters();
    wp_update_post(['ID' => $cookie_id, 'post_content' => $new_content]);
    kses_init_filters();

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache($cookie_id);
    }
}

/* ─────────────────────────────────────────
   HELPER: dominio del sito.
   Deriva l'host dal campo "Sito web" di WP Barrio → Info Azienda.
   Fallback: dominio del sito WordPress (home_url).
   ───────────────────────────────────────── */
function wpb_cp_get_domain(): string
{
    $url = '';
    if (function_exists('wpb_company_info_get')) {
        $info = wpb_company_info_get();
        $url = trim((string) ($info['sito_web'] ?? ''));
    }

    if ($url !== '') {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            // L'utente potrebbe aver scritto "example.com" senza schema
            $host = preg_replace('#^https?://#i', '', $url);
            $host = preg_replace('#[/?#].*$#', '', (string) $host);
        }
        if ($host) {
            return (string) $host;
        }
    }

    return (string) parse_url(home_url(), PHP_URL_HOST);
}

/* ─────────────────────────────────────────
   HELPER: dati aziendali rilevanti per le policy.
   Restituisce un array normalizzato con tutti i campi
   utili (ragione sociale, sede legale, contatti fiscali).
   Tutti i valori sono stringhe (vuote se assenti).
   ───────────────────────────────────────── */
function wpb_cp_company_data(): array
{
    $defaults = [
        'ragione_sociale' => '',
        'partita_iva' => '',
        'codice_fiscale' => '',
        'rea' => '',
        'capitale_sociale' => '',
        'codice_univoco' => '',
        'pec' => '',
        'email' => '',
        'email_privacy' => '',
        'telefono' => '',
        'cellulare' => '',
        'sito_web' => '',
        'sede_via' => '',
        'sede_citta' => '',
        'sede_cap' => '',
        'sede_provincia' => '',
    ];

    if (!function_exists('wpb_company_info_get')) {
        return $defaults;
    }

    $info = wpb_company_info_get();
    $out = $defaults;
    foreach ($defaults as $key => $_) {
        $out[$key] = trim((string) ($info[$key] ?? ''));
    }
    return $out;
}

/* ─────────────────────────────────────────
   HELPER: blocco HTML "Titolare del trattamento".
   Generato server-side a partire dai dati di Info Azienda,
   viene preposto al contenuto della Privacy Policy.
   I campi vuoti vengono omessi automaticamente.
   ───────────────────────────────────────── */
function wpb_cp_company_block_html(array $c): string
{
    if ($c['ragione_sociale'] === '') {
        return '';
    }

    // Riga indirizzo: solo i pezzi presenti
    $address_parts = [];
    if ($c['sede_via'] !== '')
        $address_parts[] = $c['sede_via'];
    $city_line = trim(($c['sede_cap'] !== '' ? $c['sede_cap'] . ' ' : '')
        . $c['sede_citta']
        . ($c['sede_provincia'] !== '' ? ' (' . $c['sede_provincia'] . ')' : ''));
    if ($city_line !== '')
        $address_parts[] = $city_line;
    $address_line = implode(' — ', $address_parts);

    // Riga dati fiscali: P.IVA, CF, REA
    $fiscal_parts = [];
    if ($c['partita_iva'] !== '')
        $fiscal_parts[] = 'P. IVA ' . esc_html($c['partita_iva']);
    if ($c['codice_fiscale'] !== '' && $c['codice_fiscale'] !== $c['partita_iva'])
        $fiscal_parts[] = 'C.F. ' . esc_html($c['codice_fiscale']);
    if ($c['rea'] !== '')
        $fiscal_parts[] = 'REA ' . esc_html($c['rea']);

    // Costruzione blocco
    $html = '<h2>Titolare del trattamento</h2>';

    $intro = 'Il Titolare del trattamento dei dati personali è <strong>'
        . esc_html($c['ragione_sociale']) . '</strong>';
    if ($address_line !== '') {
        $intro .= ', con sede legale in ' . esc_html($address_line);
    }
    if (!empty($fiscal_parts)) {
        $intro .= ' — ' . implode(', ', $fiscal_parts);
    }
    $intro .= '.';
    $html .= '<p>' . $intro . '</p>';

    // Blocco contatti
    $contacts = [];
    if ($c['email'] !== '') {
        $contacts[] = 'Email: <a href="mailto:' . esc_attr($c['email']) . '">' . esc_html($c['email']) . '</a>';
    }
    if ($c['email_privacy'] !== '' && $c['email_privacy'] !== $c['email']) {
        $contacts[] = 'Email privacy: <a href="mailto:' . esc_attr($c['email_privacy']) . '">' . esc_html($c['email_privacy']) . '</a>';
    }
    if ($c['pec'] !== '') {
        $contacts[] = 'PEC: <a href="mailto:' . esc_attr($c['pec']) . '">' . esc_html($c['pec']) . '</a>';
    }
    if ($c['telefono'] !== '') {
        $contacts[] = 'Telefono: ' . esc_html($c['telefono']);
    }
    if (!empty($contacts)) {
        $html .= '<p><strong>Contatti:</strong><br>' . implode('<br>', $contacts) . '</p>';
    }

    return $html;
}

/* ─────────────────────────────────────────
   HELPER: testo dei dati aziendali da iniettare nel prompt.
   Formato leggibile dall'AI con tutti i campi disponibili.
   ───────────────────────────────────────── */
function wpb_cp_company_data_for_prompt(array $c): string
{
    $lines = [];
    $map = [
        'ragione_sociale' => 'Ragione sociale',
        'sito_web' => 'Sito web',
        'sede_via' => 'Indirizzo sede legale',
        'sede_citta' => 'Città',
        'sede_cap' => 'CAP',
        'sede_provincia' => 'Provincia',
        'partita_iva' => 'Partita IVA',
        'codice_fiscale' => 'Codice Fiscale',
        'rea' => 'REA',
        'capitale_sociale' => 'Capitale sociale',
        'codice_univoco' => 'Codice univoco (SDI)',
        'email' => 'Email',
        'email_privacy' => 'Email privacy',
        'pec' => 'PEC',
        'telefono' => 'Telefono',
        'cellulare' => 'Cellulare',
    ];
    foreach ($map as $key => $label) {
        if ($c[$key] !== '') {
            $lines[] = '- ' . $label . ': ' . $c[$key];
        }
    }
    return implode("\n", $lines);
}

/* ─────────────────────────────────────────
   HELPER: genera UNA policy (privacy o cookie) e la salva.
   Usata da entrambi gli endpoint AJAX in parallelo.
   ───────────────────────────────────────── */
function wpb_cp_generate_and_save_policy(string $policy_type, int $page_id, array $company, string $domain): array
{
    $page = $page_id ? get_post($page_id) : null;
    if (!$page || $page->post_type !== 'page') {
        return ['success' => false, 'message' => 'Pagina di destinazione non valida.'];
    }

    // Cookie report (script fornito dal provider di cookie management)
    $cookie_report = $policy_type === 'cookie'
        ? wpb_cp_get_cookie_report_script()
        : '';

    $result = wpb_cp_call_ai(wpb_cp_policy_prompt(
        $policy_type,
        $domain,
        $page,
        $company,
        $cookie_report !== ''
    ));
    if (!empty($result['error'])) {
        return ['success' => false, 'message' => $result['error']];
    }

    $content = wpb_cp_clean_generated_html($result['text'] ?? '');
    if (!$content) {
        return ['success' => false, 'message' => 'Contenuto generato vuoto o non valido.'];
    }

    // Per la privacy, preponiamo il blocco "Titolare del trattamento"
    // costruito server-side dai dati di Info Azienda.
    if ($policy_type === 'privacy') {
        $block = wpb_cp_company_block_html($company);
        if ($block !== '') {
            $content = wp_kses_post($block) . "\n\n" . $content;
        }
    }

    // Per la cookie, appendiamo il report del provider come blocco
    // "HTML personalizzato" di Gutenberg (<!-- wp:html -->).
    // Questo garantisce che l'editor non lo manometta in fase di display.
    $has_raw_html = false;
    if ($policy_type === 'cookie' && $cookie_report !== '') {
        $content .= "\n\n"
            . "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Cookie utilizzati su questo sito</h2>\n<!-- /wp:heading -->\n\n"
            . "<!-- wp:html -->\n" . $cookie_report . "\n<!-- /wp:html -->";
        $has_raw_html = true;
    }

    // Belt-and-suspenders: rimuoviamo i filtri kses durante il salvataggio
    // per evitare che <script>/<iframe> dentro <!-- wp:html --> vengano strippati
    // dal filtro content_save_pre → wp_filter_post_kses.
    if ($has_raw_html) {
        kses_remove_filters();
    }

    $update = wp_update_post(['ID' => $page_id, 'post_content' => $content], true);

    if ($has_raw_html) {
        kses_init_filters();
    }

    if (is_wp_error($update)) {
        return ['success' => false, 'message' => 'Errore salvataggio: ' . $update->get_error_message()];
    }

    return [
        'success' => true,
        'page_id' => $page_id,
        'page_title' => get_the_title($page_id),
    ];
}

/* ─────────────────────────────────────────
   AJAX: validazione comune + dispatch
   ───────────────────────────────────────── */
function wpb_cp_ajax_generate(string $policy_type): void
{
    check_ajax_referer('wpb_cp_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permesso negato.'], 403);
    }

    // Tentativo di estendere il time limit (best-effort, alcuni host lo proibiscono).
    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }

    $domain = wpb_cp_get_domain();
    if ($domain === '') {
        wp_send_json_error([
            'message' => 'Dominio mancante. Compila il "Sito web" in <a href="'
                . esc_url(admin_url('admin.php?page=wp-barrio-info-azienda&tab=dati'))
                . '">Info Azienda</a>.',
        ]);
    }

    $company = wpb_cp_company_data();
    if ($company['ragione_sociale'] === '') {
        wp_send_json_error([
            'message' => 'Compila almeno la <strong>Ragione sociale</strong> in <a href="'
                . esc_url(admin_url('admin.php?page=wp-barrio-info-azienda&tab=dati'))
                . '">Info Azienda</a> prima di generare le policy.',
        ]);
    }

    $privacy_id = absint(get_option('wpb_cp_privacy_page_id', 0));
    $cookie_id = absint(get_option('wpb_cp_cookie_page_id', 0));
    if (!$privacy_id || !$cookie_id) {
        wp_send_json_error(['message' => 'Seleziona entrambe le pagine (Privacy e Cookie) e salva prima di generare.']);
    }
    if ($privacy_id === $cookie_id) {
        wp_send_json_error(['message' => 'Privacy Policy e Cookie Policy devono essere due pagine distinte.']);
    }

    $page_id = $policy_type === 'privacy' ? $privacy_id : $cookie_id;
    $result = wpb_cp_generate_and_save_policy($policy_type, $page_id, $company, $domain);

    if (!$result['success']) {
        wpb_cp_log('error', $domain, ucfirst($policy_type) . ' Policy: ' . $result['message']);
        wp_send_json_error(['message' => $result['message']]);
    }

    wpb_cp_log('success', $domain, ucfirst($policy_type) . ' Policy aggiornata: ' . $result['page_title']);

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache($privacy_id);
        invalidate_vercel_cache($cookie_id);
    }

    wp_send_json_success([
        'page_id' => $result['page_id'],
        'page_title' => $result['page_title'],
    ]);
}

add_action('wp_ajax_wpb_cp_generate_privacy', function () {
    wpb_cp_ajax_generate('privacy');
});

add_action('wp_ajax_wpb_cp_generate_cookie', function () {
    wpb_cp_ajax_generate('cookie');
});

function wpb_cp_log(string $status, string $target, string $note): void
{
    if (!get_option('wpb_cp_log_enabled', 1))
        return;
    $log = get_option(WPB_CP_LOG_OPTION, []);
    array_unshift($log, [
        'ts' => current_time('timestamp'),
        'status' => $status,
        'target' => $target,
        'note' => $note,
    ]);
    if (count($log) > WPB_CP_LOG_MAX)
        $log = array_slice($log, 0, WPB_CP_LOG_MAX);
    update_option(WPB_CP_LOG_OPTION, $log, false);
}

function wpb_cp_active_key(): string
{
    $provider = get_option('wpb_cp_provider', 'gemini');
    return $provider === 'anthropic'
        ? get_option('wpb_cp_anthropic_key', '')
        : get_option('wpb_cp_gemini_key', '');
}

function wpb_cp_call_ai(string $prompt): array
{
    $provider = get_option('wpb_cp_provider', 'gemini');
    $key = wpb_cp_active_key();
    $model = get_option('wpb_cp_model', '');
    if (!$key)
        return ['error' => 'Chiave API non configurata.'];

    if ($provider === 'anthropic') {
        if (empty($model))
            $model = 'claude-opus-4-6';
        $res = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'max_tokens' => 6000,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]),
        ]);
        if (is_wp_error($res))
            return ['error' => $res->get_error_message()];
        if (wp_remote_retrieve_response_code($res) !== 200)
            return ['error' => 'Errore API Anthropic (HTTP ' . wp_remote_retrieve_response_code($res) . ').'];
        $body = json_decode(wp_remote_retrieve_body($res), true);
        return ['text' => $body['content'][0]['text'] ?? ''];
    }

    if (empty($model))
        $model = 'gemini-2.0-flash';
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $key;
    $res = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 6000],
        ]),
        'timeout' => 120,
    ]);
    if (is_wp_error($res))
        return ['error' => $res->get_error_message()];
    if (wp_remote_retrieve_response_code($res) !== 200)
        return ['error' => 'Errore API Gemini (HTTP ' . wp_remote_retrieve_response_code($res) . ').'];
    $body = json_decode(wp_remote_retrieve_body($res), true);
    return ['text' => $body['candidates'][0]['content']['parts'][0]['text'] ?? ''];
}

function wpb_cp_policy_prompt(string $policy_type, string $domain, WP_Post $page, array $company = [], bool $cookie_report_present = false): string
{
    $site_name = get_bloginfo('name');
    $lang = get_bloginfo('language');
    $title = $policy_type === 'privacy' ? 'Privacy Policy' : 'Cookie Policy';
    $focus = $policy_type === 'privacy'
        ? 'trattamento dei dati personali, basi giuridiche, finalita, conservazione, diritti degli interessati e trasferimenti'
        : 'uso dei cookie, categorie di cookie, finalita, gestione consenso, cookie tecnici, analitici, marketing e modalita di modifica preferenze';

    $company_block = !empty($company) ? wpb_cp_company_data_for_prompt($company) : '';
    $company_section = $company_block !== ''
        ? "DATI AZIENDALI VERIFICATI (unica fonte di verità per qualsiasi riferimento al titolare/azienda):\n$company_block\n"
        : '';

    $services_block   = function_exists('wpb_cb_get_services_for_prompt')
        ? wpb_cb_get_services_for_prompt()
        : '';
    $services_section = $services_block !== ''
        ? "SERVIZI E COOKIE RILEVATI (usa questi dati nella sezione cookie della policy, non inventarne altri):\n$services_block\n"
        : '';

    // Istruzione specifica per la Privacy: NON deve includere la sezione
    // "Titolare del trattamento" / "Contatti del titolare" perché viene
    // generata server-side e preposta al contenuto.
    $privacy_specific = $policy_type === 'privacy'
        ? "IMPORTANTE: NON includere la sezione \"Titolare del trattamento\" né i contatti del titolare: "
        . "questa sezione viene aggiunta automaticamente in testa al documento e duplicarla creerebbe un doppione. "
        . "Inizia direttamente con le sezioni che spiegano FINALITÀ, BASI GIURIDICHE, TIPOLOGIE DI DATI, CONSERVAZIONE, "
        . "DIRITTI DEGLI INTERESSATI, EVENTUALI TRASFERIMENTI EXTRA-UE.\n"
        : '';

    // Istruzione specifica per la Cookie quando il report del provider è presente:
    // l'elenco dettagliato dei cookie viene iniettato server-side, quindi l'AI deve
    // generare solo il testo descrittivo/legale e NON inventare nomi di cookie specifici.
    $cookie_specific = ($policy_type === 'cookie' && $cookie_report_present)
        ? "IMPORTANTE: in coda al documento verrà inserito automaticamente l'elenco aggiornato dei cookie specifici del sito, "
        . "fornito dal servizio di cookie management (Iubenda, Cookiebot, CookieYes o simili). "
        . "Di conseguenza NON generare tabelle né elenchi puntati con nomi specifici di cookie (\"_ga\", \"PHPSESSID\", \"wordpress_logged_in_*\", ecc.): "
        . "duplicherebbero quanto già presente. Concentrati su definizioni, categorie generali (tecnici/analitici/marketing/profilazione), "
        . "basi giuridiche, durata, come revocare il consenso e come gestire le preferenze dal browser.\n"
        : '';

    return <<<PROMPT
Sei un consulente GDPR per siti web. Genera una $title completa e distinta.

Sito: $site_name
Dominio: $domain
Lingua sito: $lang
Pagina di destinazione: {$page->post_title}

$company_section
$services_section
Regole inderogabili sui dati:
- Quando devi citare l'azienda, il titolare, l'indirizzo, una P.IVA, un'email, un telefono o qualsiasi dato anagrafico, usa ESCLUSIVAMENTE i valori della sezione "DATI AZIENDALI VERIFICATI" qui sopra.
- NON inventare email, numeri di telefono, indirizzi, P.IVA o ragioni sociali.
- Se un dato non è presente in "DATI AZIENDALI VERIFICATI", ometti quella menzione: non scrivere placeholder tipo "[inserire qui]" né "esempio@esempio.it".

$privacy_specific$cookie_specific
Scrivi SOLO il contenuto della $title, senza includere l'altra policy.
Focus obbligatorio: $focus.

Formato:
- restituisci HTML pulito adatto al campo post_content di WordPress
- usa h2, h3, p, ul, li
- non usare markdown
- non racchiudere la risposta in backtick o blocchi codice
- non aggiungere introduzioni sul fatto che sei un'AI
PROMPT;
}

function wpb_cp_clean_generated_html(string $text): string
{
    $text = trim($text);
    $text = preg_replace('/^```(?:html)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    return wp_kses_post(trim($text));
}

add_action('wp_ajax_wpb_cp_clear_log', function () {
    check_ajax_referer('wpb_cp_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.', 403);
    delete_option(WPB_CP_LOG_OPTION);
    wp_send_json_success();
});

add_action('wp_ajax_wpb_cp_fetch_models', function () {
    check_ajax_referer('wpb_cp_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.', 403);

    $provider = sanitize_key($_POST['provider'] ?? 'gemini');
    $force = !empty($_POST['force']);
    $cache_key = 'wpb_cp_models_' . $provider;
    if ($force)
        delete_transient($cache_key);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        wp_send_json_success($cached);
        return;
    }

    $api_key = $provider === 'gemini'
        ? get_option('wpb_cp_gemini_key', '')
        : get_option('wpb_cp_anthropic_key', '');
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

function wpb_cp_admin_page()
{
    $tab = sanitize_key($_GET['tab'] ?? 'generate');
    $nonce = wp_create_nonce('wpb_cp_nonce');
    $provider = get_option('wpb_cp_provider', 'gemini');
    $model = get_option('wpb_cp_model', '');
    $log = get_option(WPB_CP_LOG_OPTION, []);
    $pages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC', 'post_status' => ['publish', 'draft', 'private']]);
    $notice = [];
    $saved_domain = wpb_cp_get_domain();
    $selected_privacy_page_id = absint(get_option('wpb_cp_privacy_page_id', 0));
    $selected_cookie_page_id = absint(get_option('wpb_cp_cookie_page_id', 0));
    $cookie_report_script = wpb_cp_get_cookie_report_script();
    ?>
<div id="wpb-cp-wrap" class="wrap wpb-wrap">
        <div class="wpb-cp-header">
            <div class="wpb-cp-header-icon"><span class="dashicons dashicons-shield"></span></div>
            <div>
                <h1>WP Barrio <span class="wpb-cp-breadcrumb">/ Cookie e Privacy</span></h1>
                <p class="wpb-cp-sub">Genera documenti privacy e cookie policy con AI per il sito corrente</p>
            </div>
        </div>
        <hr class="wpb-cp-divider">

        <?php
        $registered_tabs = apply_filters('wpb_cp_tabs', [
            'generate' => ['label' => 'Genera',       'icon' => 'media-text'],
            'log'      => ['label' => 'Log',          'icon' => 'list-view'],
            'settings' => ['label' => 'Impostazioni', 'icon' => 'admin-generic'],
        ]);
        ?>
        <div class="wpb-cp-tabs">
            <?php foreach ($registered_tabs as $tab_key => $tab_def): ?>
                <a class="wpb-cp-tab <?php echo $tab === $tab_key ? 'active' : ''; ?>"
                   href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-cookie-privacy&tab=' . urlencode($tab_key))); ?>">
                    <span class="dashicons dashicons-<?php echo esc_attr($tab_def['icon']); ?>"></span>
                    <?php echo esc_html($tab_def['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($tab === 'settings'): ?>
            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="wpb-cp-notice-success"><span class="dashicons dashicons-yes-alt"></span>
                    <div>Impostazioni salvate.</div>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php" style="max-width:640px;">
                <?php settings_fields('wpb_cp_settings'); ?>

                <div class="wpb-settings-card">
                    <div class="wpb-settings-card-header">Provider AI</div>
                    <div class="wpb-settings-card-body">
                        <div class="wpb-settings-row">
                            <div class="wpb-settings-label">Provider<small>Seleziona il servizio AI</small></div>
                            <div class="wpb-settings-input">
                                <select name="wpb_cp_provider" id="wpb-cp-provider-sel">
                                    <option value="anthropic" <?php selected($provider, 'anthropic'); ?>>Anthropic Claude
                                    </option>
                                    <option value="gemini" <?php selected($provider, 'gemini'); ?>>Google Gemini</option>
                                </select>
                            </div>
                        </div>
                        <div class="wpb-settings-row" id="wpb-cp-anthropic-row"
                            style="<?php echo $provider === 'gemini' ? 'display:none;' : ''; ?>">
                            <div class="wpb-settings-label">Chiave API Anthropic<small>sk-ant-...</small></div>
                            <div class="wpb-settings-input">
                                <input type="password" name="wpb_cp_anthropic_key"
                                    value="<?php echo esc_attr(get_option('wpb_cp_anthropic_key', '')); ?>"
                                    placeholder="sk-ant-api03-..." autocomplete="off">
                            </div>
                        </div>
                        <div class="wpb-settings-row" id="wpb-cp-gemini-row"
                            style="<?php echo $provider !== 'gemini' ? 'display:none;' : ''; ?>">
                            <div class="wpb-settings-label">Chiave API Gemini<small>AIza...</small></div>
                            <div class="wpb-settings-input">
                                <input type="password" name="wpb_cp_gemini_key"
                                    value="<?php echo esc_attr(get_option('wpb_cp_gemini_key', '')); ?>" placeholder="AIzaSy..."
                                    autocomplete="off">
                            </div>
                        </div>
                        <div class="wpb-settings-row">
                            <div class="wpb-settings-label">Modello<small>Lascia vuoto per il default</small></div>
                            <div class="wpb-settings-input">
                                <div class="wpb-model-row">
                                    <select name="wpb_cp_model" id="wpb-cp-model-sel">
                                        <option value="">— Default —</option>
                                        <?php if ($model): ?>
                                            <option value="<?php echo esc_attr($model); ?>" selected><?php echo esc_html($model); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                    <button type="button" class="wpb-cp-btn wpb-cp-btn-ghost" id="wpb-cp-load-models">Carica
                                        modelli</button>
                                </div>
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
                                <input type="hidden" name="wpb_cp_log_enabled" value="0">
                                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                    <input type="checkbox" name="wpb_cp_log_enabled" value="1" <?php checked(get_option('wpb_cp_log_enabled', 1)); ?>>
                                    <span style="font-size:13px;color:#374151;">Attivo</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="wpb-cp-btn wpb-cp-btn-primary">
                    <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                    Salva impostazioni
                </button>
            </form>

        <?php elseif ($tab === 'log'): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <span style="font-size:13px;color:#6b7280;font-weight:500;"><?php echo count($log); ?> operazioni
                    registrate</span>
                <button class="wpb-cp-btn wpb-cp-btn-ghost" id="wpb-cp-clear-log">Svuota log</button>
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
                                    <?php echo esc_html(date_i18n('d/m/Y H:i', $entry['ts'] ?? 0)); ?>
                                </td>
                                <td><span
                                        class="wpb-log-badge <?php echo esc_attr($entry['status'] ?? 'error'); ?>"><?php echo ($entry['status'] ?? '') === 'success' ? '&#x2713; OK' : '&#x2715; Errore'; ?></span>
                                </td>
                                <td><?php echo esc_html($entry['target'] ?? ''); ?></td>
                                <td style="color:#9ca3af;font-size:12px;"><?php echo esc_html($entry['note'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php elseif ($tab === 'generate'): ?>
            <?php if (!empty($notice)): ?>
                <div class="wpb-cp-notice-<?php echo esc_attr($notice['type']); ?>">
                    <span
                        class="dashicons <?php echo $notice['type'] === 'success' ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                    <div><?php echo wp_kses_post($notice['message']); ?></div>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="wpb-cp-notice-success"><span class="dashicons dashicons-yes-alt"></span>
                    <div>Configurazione salvata. Ora puoi generare i contenuti.</div>
                </div>
            <?php endif; ?>
            <div class="wpb-cp-notice">
                <span class="dashicons dashicons-info"></span>
                <div>Salva dominio e pagine prima di generare. La Privacy Policy e la Cookie Policy verranno generate
                    separatamente e salvate direttamente nel contenuto delle pagine scelte.</div>
            </div>
            <div class="wpb-cp-panel">
                <div class="wpb-cp-panel-header">
                    <span class="dashicons dashicons-media-text"></span>
                    <span class="wpb-cp-panel-title">Configurazione contenuti</span>
                    <span class="wpb-cp-panel-desc">Privacy Policy e Cookie Policy</span>
                </div>
                <div class="wpb-cp-panel-body">
                    <form method="post" action="options.php">
                        <?php settings_fields('wpb_cp_content_settings'); ?>
                        <div class="wpb-cp-field-row">
                            <label class="wpb-cp-field-label" for="wpb_cp_domain">Dominio<span>Sincronizzato automaticamente dal
                                    campo "Sito web" di
                                    <a
                                        href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-info-azienda&tab=dati')); ?>">Info
                                        Azienda</a>.</span></label>
                            <div class="wpb-cp-field-input">
                                <input id="wpb_cp_domain" type="text" placeholder="example.com"
                                    value="<?php echo esc_attr($saved_domain); ?>" readonly
                                    style="background:#f9fafb;color:#6b7280;cursor:not-allowed;">
                                <?php if ($saved_domain === ''): ?>
                                    <p style="margin:6px 0 0;font-size:12px;color:#b45309;">
                                        Nessun sito web impostato.
                                        <a
                                            href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-info-azienda&tab=dati')); ?>">Compila
                                            il campo "Sito web" in Info Azienda</a>.
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="wpb-cp-field-row">
                            <label class="wpb-cp-field-label" for="wpb_cp_privacy_page">Privacy Policy<span>Pagina in cui
                                    salvare solo la Privacy Policy.</span></label>
                            <div class="wpb-cp-field-input">
                                <select id="wpb_cp_privacy_page" name="wpb_cp_privacy_page_id" required>
                                    <option value="">— Seleziona pagina —</option>
                                    <?php foreach ($pages as $page): ?>
                                        <option value="<?php echo esc_attr($page->ID); ?>" <?php selected($selected_privacy_page_id, $page->ID); ?>>
                                            <?php echo esc_html($page->post_title ?: '(senza titolo)'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="wpb-cp-field-row">
                            <label class="wpb-cp-field-label" for="wpb_cp_cookie_page">Cookie Policy<span>Pagina in cui salvare
                                    solo la Cookie Policy.</span></label>
                            <div class="wpb-cp-field-input">
                                <select id="wpb_cp_cookie_page" name="wpb_cp_cookie_page_id" required>
                                    <option value="">— Seleziona pagina —</option>
                                    <?php foreach ($pages as $page): ?>
                                        <option value="<?php echo esc_attr($page->ID); ?>" <?php selected($selected_cookie_page_id, $page->ID); ?>>
                                            <?php echo esc_html($page->post_title ?: '(senza titolo)'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="wpb-cp-field-row">
                            <label class="wpb-cp-field-label" for="wpb_cp_cookie_report_script">Cookie report script<span>Codice
                                    fornito dal tuo servizio
                                    di cookie management (Iubenda, Cookiebot, CookieYes…). Verrà inserito automaticamente in
                                    coda alla Cookie Policy. Lascia vuoto per non includere alcun report.</span></label>
                            <div class="wpb-cp-field-input">
                                <textarea id="wpb_cp_cookie_report_script" name="wpb_cp_cookie_report_script" rows="6"
                                    placeholder="&lt;script type=&quot;text/javascript&quot; src=&quot;https://...&quot;&gt;&lt;/script&gt;"
                                    style="width:100%;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;line-height:1.5;padding:10px 12px;border:1px solid #d1d5db;border-radius:7px;resize:vertical;"><?php echo esc_textarea($cookie_report_script); ?></textarea>
                                <button type="button" class="button" id="wpb-cp-use-endpoint"
                                    style="margin-top:6px;">
                                    &#x21BA; Usa endpoint WP Barrio (sostituisce il contenuto)
                                </button>
                                <script>
                                document.getElementById('wpb-cp-use-endpoint').addEventListener('click', function(){
                                    if (!confirm('Sovrascrivere il Cookie report script con l\'endpoint WP Barrio?')) return;
                                    document.getElementById('wpb_cp_cookie_report_script').value =
                                        '<script>\nfetch(\'' + <?php echo wp_json_encode(rest_url('wpb/v1/cookie-banner/preferences-html')); ?> + '\')\n'
                                        + '  .then(function(r){ return r.json(); })\n'
                                        + '  .then(function(res){ document.getElementById(\'wpb-cookie-preferences\').innerHTML = res.html || \'\'; });\n'
                                        + '<\/script>\n<div id="wpb-cookie-preferences"></div>';
                                });
                                </script>
                            </div>
                        </div>
                        <div class="wpb-cp-actions">
                            <button type="submit" class="wpb-cp-btn wpb-cp-btn-primary"><span
                                    class="dashicons dashicons-yes"></span> Salva</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="wpb-cp-panel">
                <div class="wpb-cp-panel-header">
                    <span class="dashicons dashicons-hammer"></span>
                    <span class="wpb-cp-panel-title">Generazione</span>
                    <span class="wpb-cp-panel-desc">Genera le policy singolarmente o insieme</span>
                </div>
                <div class="wpb-cp-panel-body">

                    <!-- Container per progresso e risultato (popolato da JS) -->
                    <div id="wpb-cp-gen-progress" class="wpb-cp-gen-progress" hidden>
                        <div class="wpb-cp-gen-row" data-policy="privacy">
                            <span class="wpb-cp-gen-spinner" aria-hidden="true"></span>
                            <span class="wpb-cp-gen-label">Privacy Policy</span>
                            <span class="wpb-cp-gen-status">In coda…</span>
                        </div>
                        <div class="wpb-cp-gen-row" data-policy="cookie">
                            <span class="wpb-cp-gen-spinner" aria-hidden="true"></span>
                            <span class="wpb-cp-gen-label">Cookie Policy</span>
                            <span class="wpb-cp-gen-status">In coda…</span>
                        </div>
                    </div>

                    <div id="wpb-cp-gen-result" class="wpb-cp-gen-result"></div>

                    <div class="wpb-cp-actions wpb-cp-gen-actions" style="padding-left:0;margin-top:0;">
                        <button type="button" class="wpb-cp-btn wpb-cp-btn-primary wpb-cp-gen-btn" data-generate="both">
                            <span class="dashicons dashicons-hammer"></span> Genera entrambe
                        </button>
                        <button type="button" class="wpb-cp-btn wpb-cp-btn-ghost wpb-cp-gen-btn" data-generate="privacy">
                            <span class="dashicons dashicons-shield"></span> Solo Privacy
                        </button>
                        <button type="button" class="wpb-cp-btn wpb-cp-btn-ghost wpb-cp-gen-btn" data-generate="cookie">
                            <span class="dashicons dashicons-admin-site"></span> Solo Cookie
                        </button>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php do_action('wpb_cp_render_tab_' . sanitize_key($tab)); ?>
        <?php endif; ?>
        <script>
            (function () {
                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var nonce = <?php echo wp_json_encode($nonce); ?>;

                var clearLogBtn = document.getElementById('wpb-cp-clear-log');
                if (clearLogBtn) {
                    clearLogBtn.addEventListener('click', function () {
                        if (!confirm('Svuotare il log?')) return;
                        var fd = new FormData();
                        fd.append('action', 'wpb_cp_clear_log');
                        fd.append('nonce', nonce);
                        fetch(ajaxUrl, { method: 'POST', body: fd }).then(function () { window.location.reload(); });
                    });
                }

                var provSel = document.getElementById('wpb-cp-provider-sel');
                if (provSel) {
                    provSel.addEventListener('change', function () {
                        var ant = document.getElementById('wpb-cp-anthropic-row');
                        var gem = document.getElementById('wpb-cp-gemini-row');
                        if (this.value === 'gemini') {
                            ant.style.display = 'none';
                            gem.style.display = '';
                        } else {
                            ant.style.display = '';
                            gem.style.display = 'none';
                        }
                    });
                }

                var loadModelsBtn = document.getElementById('wpb-cp-load-models');
                if (loadModelsBtn) {
                    loadModelsBtn.addEventListener('click', function () {
                        var prov = document.getElementById('wpb-cp-provider-sel').value;
                        var sel = document.getElementById('wpb-cp-model-sel');
                        this.disabled = true;
                        this.textContent = 'Caricamento...';
                        var fd = new FormData();
                        fd.append('action', 'wpb_cp_fetch_models');
                        fd.append('nonce', nonce);
                        fd.append('provider', prov);
                        fd.append('force', '1');
                        var btn = this;
                        fetch(ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (res) {
                            if (!res.success || res.data.error) {
                                alert(res.data.error || 'Impossibile caricare i modelli.');
                                return;
                            }
                            var current = sel.value;
                            while (sel.options.length > 1) sel.remove(1);
                            (res.data || []).forEach(function (m) {
                                var opt = document.createElement('option');
                                opt.value = m;
                                opt.textContent = m;
                                if (m === current) opt.selected = true;
                                sel.appendChild(opt);
                            });
                        }).finally(function () {
                            btn.disabled = false;
                            btn.textContent = 'Carica modelli';
                        });
                    });
                }

                /* ── Generazione asincrona Privacy / Cookie / Entrambe ── */
                var genBtns = document.querySelectorAll('.wpb-cp-gen-btn');
                var progressBox = document.getElementById('wpb-cp-gen-progress');
                var resultBox = document.getElementById('wpb-cp-gen-result');

                if (genBtns.length && progressBox && resultBox) {
                    var allRows = progressBox.querySelectorAll('.wpb-cp-gen-row');

                    var setRowState = function (policy, state, statusText) {
                        var row = progressBox.querySelector('.wpb-cp-gen-row[data-policy="' + policy + '"]');
                        if (!row) return;
                        row.classList.remove('is-running', 'is-success', 'is-error', 'is-hidden');
                        if (state) row.classList.add('is-' + state);
                        row.querySelector('.wpb-cp-gen-status').textContent = statusText;
                    };

                    var showRows = function (policies) {
                        allRows.forEach(function (row) {
                            var pol = row.getAttribute('data-policy');
                            if (policies.indexOf(pol) === -1) {
                                row.classList.add('is-hidden');
                            } else {
                                row.classList.remove('is-hidden');
                            }
                        });
                    };

                    var generateOne = function (policy) {
                        var label = policy === 'privacy' ? 'Privacy Policy' : 'Cookie Policy';
                        setRowState(policy, 'running', 'Generazione in corso…');

                        var fd = new FormData();
                        fd.append('action', 'wpb_cp_generate_' + policy);
                        fd.append('nonce', nonce);

                        return fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (res) {
                                if (res && res.success) {
                                    setRowState(policy, 'success', 'Aggiornata: ' + (res.data.page_title || ''));
                                    return { policy: policy, label: label, ok: true, data: res.data };
                                }
                                var msg = (res && res.data && res.data.message) ? res.data.message : 'Errore sconosciuto';
                                var plain = (new DOMParser()).parseFromString(msg, 'text/html').body.textContent || '';
                                setRowState(policy, 'error', plain);
                                return { policy: policy, label: label, ok: false, error: msg };
                            })
                            .catch(function (err) {
                                setRowState(policy, 'error', 'Errore di rete');
                                return { policy: policy, label: label, ok: false, error: 'Errore di rete' };
                            });
                    };

                    var renderResult = function (results) {
                        var okCount = results.filter(function (r) { return r.ok; }).length;
                        var klass, icon, html;

                        if (okCount === results.length && results.length > 1) {
                            klass = 'is-success'; icon = 'yes-alt';
                            html = '<strong>Privacy Policy e Cookie Policy</strong> generate e salvate.';
                        } else if (okCount === results.length && results.length === 1) {
                            klass = 'is-success'; icon = 'yes-alt';
                            html = '<strong>' + results[0].label + '</strong> generata e salvata.';
                        } else if (okCount === 0) {
                            klass = 'is-error'; icon = 'warning';
                            html = '<strong>Generazione fallita.</strong><br>'
                                + results.map(function (r) { return r.label + ': ' + r.error; }).join('<br>');
                        } else {
                            klass = 'is-mixed'; icon = 'warning';
                            html = '<strong>Generazione parziale.</strong><br>'
                                + results.map(function (r) {
                                    return r.ok ? r.label + ': aggiornata.' : r.label + ': ' + r.error;
                                }).join('<br>');
                        }

                        resultBox.innerHTML =
                            '<div class="wpb-cp-gen-result-box ' + klass + '">'
                            + '<span class="dashicons dashicons-' + icon + '"></span>'
                            + '<div>' + html + '</div></div>';
                    };

                    var setBtnsDisabled = function (disabled) {
                        genBtns.forEach(function (b) { b.disabled = disabled; });
                    };

                    genBtns.forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var target = btn.getAttribute('data-generate');
                            var policies = target === 'both' ? ['privacy', 'cookie']
                                : target === 'privacy' ? ['privacy']
                                    : ['cookie'];

                            setBtnsDisabled(true);
                            resultBox.innerHTML = '';
                            progressBox.hidden = false;
                            showRows(policies);
                            policies.forEach(function (p) { setRowState(p, 'running', 'Generazione in corso…'); });

                            var jobs = policies.map(function (p) { return generateOne(p); });

                            Promise.all(jobs)
                                .then(function (results) { renderResult(results); })
                                .finally(function () { setBtnsDisabled(false); });
                        });
                    });
                }
            })();
        </script>
    </div>
<?php }
