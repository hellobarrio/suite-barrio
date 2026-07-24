<?php
/**
 * Suite Barrio — Wizard Messa Online
 * Guida step-by-step per portare un sito in produzione.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('SUITE_BARRIO_WIZARD_PROGRESS_OPTION', 'suite_barrio_wizard_progress');

/* ─────────────────────────────────────────
   Sanifica il cookie report script (condivisa con il modulo Cookie e
   Privacy: definita qui perché il wizard è sempre caricato, mentre
   quel modulo può essere disattivato dalla pagina Strumenti).
   ───────────────────────────────────────── */
if (!function_exists('suite_barrio_cp_sanitize_report_script')) {
    function suite_barrio_cp_sanitize_report_script(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (!current_user_can('unfiltered_html')) {
            $raw = wp_kses($raw, wp_kses_allowed_html('post'));
        }
        return $raw;
    }
}

/* ─────────────────────────────────────────
   DEFINIZIONE PASSI
   ───────────────────────────────────────── */
// Moduli che richiedono chiavi API AI generativa.
// Usato da suite_barrio_wizard_steps() e dal render dello step "Configura AI".
function suite_barrio_wizard_ai_modules(): array
{
    return ['auto_alt', 'seo', 'cookie_privacy'];
}

function suite_barrio_wizard_needs_ai(): bool
{
    if (!function_exists('suite_barrio_tools_module_enabled'))
        return false;
    foreach (suite_barrio_wizard_ai_modules() as $key) {
        if (suite_barrio_tools_module_enabled($key))
            return true;
    }
    return false;
}

function suite_barrio_wizard_steps(): array
{
    $steps = [
        1 => ['label' => 'Info Azienda',        'icon' => 'dashicons-id-alt'],
        2 => ['label' => 'Strumenti',            'icon' => 'dashicons-admin-plugins'],
        3 => ['label' => 'GTM',                  'icon' => 'dashicons-tag'],
    ];

    if (function_exists('suite_barrio_tools_module_enabled') && suite_barrio_tools_module_enabled('forms')) {
        $steps[4] = ['label' => 'Mailjet', 'icon' => 'dashicons-email'];
    }

    if (function_exists('suite_barrio_tools_module_enabled') && suite_barrio_tools_module_enabled('cookie_privacy')) {
        $steps[5] = ['label' => 'Cookie, Privacy', 'icon' => 'dashicons-shield'];
    }

    if (suite_barrio_wizard_needs_ai()) {
        $steps[6] = ['label' => 'Configura AI', 'icon' => 'dashicons-admin-network'];
    }

    if (function_exists('suite_barrio_tools_module_enabled') && suite_barrio_tools_module_enabled('seo')) {
        $steps[7] = ['label' => 'SEO', 'icon' => 'dashicons-search'];
    }

    $steps[8] = ['label' => 'Controlli Finali', 'icon' => 'dashicons-yes-alt'];

    return $steps;
}

// Restituisce i 4 sotto-step fissi di SEO con label e icona.
function suite_barrio_wizard_seo_substeps(): array
{
    return [
        1 => ['label' => 'SEO Globale', 'icon' => 'dashicons-admin-site'],
        2 => ['label' => 'Sitemap',     'icon' => 'dashicons-networking'],
        3 => ['label' => 'Schema',      'icon' => 'dashicons-tag'],
        4 => ['label' => 'Redirects',   'icon' => 'dashicons-randomize'],
    ];
}

// Restituisce il primo sotto-step SEO non ancora done/skipped.
function suite_barrio_wizard_seo_current_substep(): int
{
    $p = suite_barrio_wizard_get_progress();
    foreach (suite_barrio_wizard_seo_substeps() as $n => $_) {
        if (($p["seo_sub_{$n}"] ?? 'pending') === 'pending')
            return $n;
    }
    return 4; // tutti completati: rimani sull'ultimo
}

// Il wizard è concluso quando step_8 risulta 'done'.
function suite_barrio_wizard_is_complete(): bool
{
    return suite_barrio_wizard_step_status(8) === 'done';
}

// Alert chiave => array chiavi dismissed.
function suite_barrio_wizard_get_dismissed(): array
{
    $p = suite_barrio_wizard_get_progress();
    $d = $p['dismissed_alerts'] ?? [];
    return is_array($d) ? $d : [];
}

// Numero di step saltati (esclusi i dismissed e step_8 = Controlli Finali).
function suite_barrio_wizard_skipped_count(): int
{
    $steps     = suite_barrio_wizard_steps();
    $dismissed = suite_barrio_wizard_get_dismissed();
    $count     = 0;
    foreach ($steps as $n => $_) {
        if ($n === 8) continue;
        if (in_array("step_{$n}", $dismissed, true)) continue;
        if (suite_barrio_wizard_step_status($n) === 'skipped') $count++;
    }
    return $count;
}

// Numero di checkbox di "Controlli Finali" non spuntate (escluse le dismissed).
function suite_barrio_wizard_unchecked_finals_count(): int
{
    $all       = ['gtm_analytics', 'mailjet_recaptcha', 'email_forms', 'orari_gmaps', 'popup_banner', 'auto_alt', 'cookie_policy', 'accessibility'];
    $p         = suite_barrio_wizard_get_progress();
    $saved     = isset($p['step_8_checks']) && is_array($p['step_8_checks']) ? $p['step_8_checks'] : [];
    $dismissed = suite_barrio_wizard_get_dismissed();
    $pending   = array_diff($all, $saved);
    $pending   = array_filter($pending, fn($k) => !in_array("final_{$k}", $dismissed, true));
    return count($pending);
}

// Conteggio totale per il badge: step saltati + checkbox finali non spuntate.
function suite_barrio_wizard_badge_count(): int
{
    if (!suite_barrio_wizard_is_complete()) return 0;
    return suite_barrio_wizard_skipped_count() + suite_barrio_wizard_unchecked_finals_count();
}

/* ─────────────────────────────────────────
   AJAX — dismissal singolo alert
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_wizard_dismiss_alert', function () {
    check_ajax_referer('suite_barrio_wizard_dismiss_alert', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);

    $key = sanitize_key(wp_unslash($_POST['alert_key'] ?? ''));
    if ($key === '') wp_send_json_error('missing_key', 400);

    $progress = suite_barrio_wizard_get_progress();
    $dismissed = isset($progress['dismissed_alerts']) && is_array($progress['dismissed_alerts'])
        ? $progress['dismissed_alerts'] : [];
    if (!in_array($key, $dismissed, true)) {
        $dismissed[] = $key;
    }
    $progress['dismissed_alerts'] = $dismissed;
    update_option(SUITE_BARRIO_WIZARD_PROGRESS_OPTION, $progress);

    wp_send_json_success(['badge' => suite_barrio_wizard_badge_count()]);
});

/* ─────────────────────────────────────────
   BADGE MENU — step saltati + check finali
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    $count = suite_barrio_wizard_badge_count();
    if ($count === 0) return;
    global $menu;
    foreach ($menu as $k => $item) {
        if (isset($item[2]) && $item[2] === 'suite-barrio') {
            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- aggiorna solo la propria voce di menu (slug 'suite-barrio'), nessun'altra viene toccata
            $menu[$k][0] .= ' <span class="update-plugins count-' . $count
                . '"><span class="update-count">' . $count . '</span></span>';
            break;
        }
    }
}, 999);

/* ─────────────────────────────────────────
   HELPER: progressi salvati
   ───────────────────────────────────────── */
function suite_barrio_wizard_get_progress()
{
    $p = get_option(SUITE_BARRIO_WIZARD_PROGRESS_OPTION, []);
    return is_array($p) ? $p : [];
}

function suite_barrio_wizard_step_status(int $num): string
{
    $p = suite_barrio_wizard_get_progress();
    return $p["step_{$num}"] ?? 'pending'; // 'done' | 'skipped' | 'pending'
}

// Uno step è navigabile se tutti i precedenti (esistenti) sono done/skipped.
function suite_barrio_wizard_step_accessible(int $num): bool
{
    $steps = suite_barrio_wizard_steps();
    if (!isset($steps[$num]))
        return false;
    foreach ($steps as $n => $step) {
        if ($n >= $num)
            break;
        if (suite_barrio_wizard_step_status($n) === 'pending')
            return false;
    }
    return true;
}

// Restituisce il numero del prossimo step esistente, 0 se siamo all'ultimo.
function suite_barrio_wizard_next_step(int $current): int
{
    $keys = array_keys(suite_barrio_wizard_steps());
    $pos  = array_search($current, $keys, true);
    return ($pos !== false && isset($keys[$pos + 1])) ? $keys[$pos + 1] : 0;
}

/* ─────────────────────────────────────────
   MENU: pagina nascosta
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    global $suite_barrio_wizard_page_hook;
    $suite_barrio_wizard_page_hook = add_submenu_page(
        null,
        'Messa Online — Suite Barrio',
        'Wizard Messa Online',
        'manage_options',
        'suite-barrio-wizard',
        'suite_barrio_wizard_page'
    );
});

/* ─────────────────────────────────────────
   ASSET: script della pagina wizard (contenuto inline
   agganciato ad handle registrati, invece di <script> raw)
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    global $suite_barrio_wizard_page_hook;
    if (!$suite_barrio_wizard_page_hook || $hook !== $suite_barrio_wizard_page_hook) {
        return;
    }

    // Handle "vuoto" (nessun file esterno) usati come contenitore per
    // il JS inline generato da suite_barrio_wizard_page() ai singoli step.
    wp_register_script('suite-barrio-wizard-step6', '', [], SUITE_BARRIO_VERSION, true);
    wp_enqueue_script('suite-barrio-wizard-step6');

    wp_register_script('suite-barrio-wizard-step7-redirects', '', [], SUITE_BARRIO_VERSION, true);
    wp_enqueue_script('suite-barrio-wizard-step7-redirects');
});

/* ─────────────────────────────────────────
   SALVATAGGIO STEP 1 — Dati azienda
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_wizard_step1_save', function () {
    check_admin_referer('suite_barrio_wizard_step1_save');

    $fields = [
        'ragione_sociale' => 'text',
        'sito_web' => 'url',
        'email' => 'email',
        'email_privacy' => 'email',
        'pec' => 'email',
        'telefono' => 'text',
        'cellulare' => 'text',
        'partita_iva' => 'text',
        'codice_fiscale' => 'text',
        'codice_univoco' => 'text',
        'rea' => 'text',
        'capitale_sociale' => 'text',
    ];

    $clean = [];
    foreach ($fields as $key => $type) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitizzato subito sotto in base al tipo di campo (email/url/text) nello switch
        $raw = isset($_POST['company'][$key]) ? wp_unslash($_POST['company'][$key]) : '';
        switch ($type) {
            case 'email':
                $clean[$key] = sanitize_email($raw);
                break;
            case 'url':
                $clean[$key] = esc_url_raw(trim($raw));
                break;
            default:
                $clean[$key] = sanitize_text_field($raw);
        }
    }

    $existing = get_option(SUITE_BARRIO_COMPANY_INFO_OPTION, []);
    update_option(SUITE_BARRIO_COMPANY_INFO_OPTION, array_merge((array) $existing, $clean));

    if (function_exists('suite_barrio_invalidate_vercel_cache'))
        suite_barrio_invalidate_vercel_cache('wp:option');

    $progress = suite_barrio_wizard_get_progress();
    $progress['step_1'] = 'done';
    update_option(SUITE_BARRIO_WIZARD_PROGRESS_OPTION, $progress);

    $next = suite_barrio_wizard_next_step(1);
    $url = $next
        ? admin_url("admin.php?page=suite-barrio-wizard&step={$next}")
        : admin_url('admin.php?page=suite-barrio-wizard&completed=1');
    wp_safe_redirect($url);
    exit;
});

/* ─────────────────────────────────────────
   SALVATAGGIO STEP 2 — Selezione strumenti
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_wizard_step2_save', function () {
    check_admin_referer('suite_barrio_wizard_step2_save');

    $modules = suite_barrio_tools_modules_list();
    $options = [];
    foreach ($modules as $key => $data) {
        $options[$key] = isset($_POST['modules'][$key]) ? 1 : 0;
    }
    update_option(SUITE_BARRIO_TOOLS_OPTION, $options);

    $progress           = suite_barrio_wizard_get_progress();
    $progress['step_2'] = 'done';
    update_option(SUITE_BARRIO_WIZARD_PROGRESS_OPTION, $progress);

    $next = suite_barrio_wizard_next_step(2);
    $url  = $next
        ? admin_url("admin.php?page=suite-barrio-wizard&step={$next}")
        : admin_url('admin.php?page=suite-barrio-wizard&completed=1');
    wp_safe_redirect($url);
    exit;
});

/* ─────────────────────────────────────────
   SALVATAGGIO STEP 3 — Google Tag Manager
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_wizard_step3_save', function () {
    check_admin_referer('suite_barrio_wizard_step3_save');

    $gtm_id = sanitize_text_field(wp_unslash($_POST['gtm_id'] ?? ''));
    // Normalizza: assicura il prefisso GTM- se l'utente ha inserito solo il codice
    if (!empty($gtm_id) && !str_starts_with(strtoupper($gtm_id), 'GTM-')) {
        $gtm_id = 'GTM-' . strtoupper($gtm_id);
    }
    update_option('suite_barrio_gtm_container_id', $gtm_id);

    $progress           = suite_barrio_wizard_get_progress();
    $progress['step_3'] = 'done';
    update_option(SUITE_BARRIO_WIZARD_PROGRESS_OPTION, $progress);

    $next = suite_barrio_wizard_next_step(3);
    $url  = $next
        ? admin_url("admin.php?page=suite-barrio-wizard&step={$next}")
        : admin_url('admin.php?page=suite-barrio-wizard&completed=1');
    wp_safe_redirect($url);
    exit;
});

/* ─────────────────────────────────────────
   SALVATAGGIO STEP 4 — Mailjet
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_wizard_step4_save', function () {
    check_admin_referer('suite_barrio_wizard_step4_save');

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- già sanitizzato con sanitize_text_field(); il trim() intermedio non viene riconosciuto dal sniff
    $mj_api_key    = sanitize_text_field(trim(wp_unslash($_POST['mailjet_api_key']    ?? '')));
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- già sanitizzato con sanitize_text_field(); il trim() intermedio non viene riconosciuto dal sniff
    $mj_secret_key = sanitize_text_field(trim(wp_unslash($_POST['mailjet_secret_key'] ?? '')));

    $existing = get_option('suite_barrio_forms_mailjet', []);
    if (!is_array($existing)) $existing = [];
    if ($mj_api_key !== '')    $existing['api_key']    = $mj_api_key;
    if ($mj_secret_key !== '') $existing['secret_key'] = $mj_secret_key;
    update_option('suite_barrio_forms_mailjet', $existing);

    $progress           = suite_barrio_wizard_get_progress();
    $progress['step_4'] = 'done';
    update_option(SUITE_BARRIO_WIZARD_PROGRESS_OPTION, $progress);

    $next = suite_barrio_wizard_next_step(4);
    $url  = $next
        ? admin_url("admin.php?page=suite-barrio-wizard&step={$next}")
        : admin_url('admin.php?page=suite-barrio-wizard&completed=1');
    wp_safe_redirect($url);
    exit;
});

/* ─────────────────────────────────────────
   SALVATAGGIO STEP 5 — Cookie e Privacy
   Nessun dato da salvare: solo avanzamento.
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_wizard_step5_save', function () {
    check_admin_referer('suite_barrio_wizard_step5_save');

    // Salva il cookie report script nello stesso option usato dallo strumento Cookie e Privacy.
    if (isset($_POST['suite_barrio_cp_cookie_report_script'])) {
        update_option(
            'suite_barrio_cp_cookie_report_script',
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanificato da suite_barrio_cp_sanitize_report_script() (wp_kses se l'utente non ha unfiltered_html)
            suite_barrio_cp_sanitize_report_script(wp_unslash($_POST['suite_barrio_cp_cookie_report_script']))
        );
    }

    $progress           = suite_barrio_wizard_get_progress();
    $progress['step_5'] = 'done';
    update_option(SUITE_BARRIO_WIZARD_PROGRESS_OPTION, $progress);

    $next = suite_barrio_wizard_next_step(5);
    $url  = $next
        ? admin_url("admin.php?page=suite-barrio-wizard&step={$next}")
        : admin_url('admin.php?page=suite-barrio-wizard&completed=1');
    wp_safe_redirect($url);
    exit;
});

/* ─────────────────────────────────────────
   SALVATAGGIO STEP 6 — Configura AI
   Scrive provider, chiavi, modello e impostazioni prompt
   in tutti i moduli AI attivi.
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_wizard_step6_save', function () {
    check_admin_referer('suite_barrio_wizard_step6_save');

    $fn = 'suite_barrio_tools_module_enabled';

    $provider  = sanitize_key(wp_unslash($_POST['suite_barrio_wizard_ai_provider']      ?? 'anthropic'));
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- già sanitizzato con sanitize_text_field(); il trim() intermedio non viene riconosciuto dal sniff
    $key_anth  = sanitize_text_field(trim(wp_unslash($_POST['suite_barrio_wizard_ai_key_anthropic'] ?? '')));
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- già sanitizzato con sanitize_text_field(); il trim() intermedio non viene riconosciuto dal sniff
    $key_gem   = sanitize_text_field(trim(wp_unslash($_POST['suite_barrio_wizard_ai_key_gemini']    ?? '')));
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- già sanitizzato con sanitize_text_field(); il trim() intermedio non viene riconosciuto dal sniff
    $model     = sanitize_text_field(trim(wp_unslash($_POST['suite_barrio_wizard_ai_model']         ?? '')));
    $language  = sanitize_key(wp_unslash($_POST['suite_barrio_wizard_ai_language']      ?? 'it'));
    $tone      = sanitize_key(wp_unslash($_POST['suite_barrio_wizard_ai_tone']          ?? 'neutral'));
    $style     = sanitize_key(wp_unslash($_POST['suite_barrio_wizard_ai_style']         ?? 'descriptive'));
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- già sanitizzato con sanitize_text_field(); il trim() intermedio non viene riconosciuto dal sniff
    $context   = sanitize_text_field(trim(wp_unslash($_POST['suite_barrio_wizard_ai_context']       ?? '')));
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- già sanitizzato con sanitize_text_field(); il trim() intermedio non viene riconosciuto dal sniff
    $keywords  = sanitize_text_field(trim(wp_unslash($_POST['suite_barrio_wizard_ai_keywords']      ?? '')));

    $alt = function_exists($fn) && suite_barrio_tools_module_enabled('auto_alt');
    $seo = function_exists($fn) && suite_barrio_tools_module_enabled('seo');
    $cp  = function_exists($fn) && suite_barrio_tools_module_enabled('cookie_privacy');

    // ── Provider ──────────────────────────────────────────────────────
    if ($alt) update_option('suite_barrio_alt_ai_provider',    $provider);
    if ($seo) { update_option('suite_barrio_llms_provider',   $provider); update_option('suite_barrio_advisor_provider', $provider); }
    if ($cp)  update_option('suite_barrio_cp_provider',       $provider);

    // ── Chiavi API ────────────────────────────────────────────────────
    if ($key_anth !== '') {
        if ($alt) update_option('suite_barrio_alt_ai_api_key',    $key_anth);
        if ($seo) { update_option('suite_barrio_llms_api_key',   $key_anth); update_option('suite_barrio_advisor_api_key', $key_anth); }
        if ($cp)  update_option('suite_barrio_cp_anthropic_key', $key_anth);
    }
    if ($key_gem !== '') {
        if ($alt) update_option('suite_barrio_alt_ai_gemini_key',    $key_gem);
        if ($seo) { update_option('suite_barrio_llms_gemini_key',   $key_gem); update_option('suite_barrio_advisor_gemini_key', $key_gem); }
        if ($cp)  update_option('suite_barrio_cp_gemini_key',       $key_gem);
    }

    // ── Modello ───────────────────────────────────────────────────────
    if ($model !== '') {
        if ($alt) update_option('suite_barrio_alt_ai_model',    $model);
        if ($seo) { update_option('suite_barrio_llms_model',   $model); update_option('suite_barrio_advisor_model', $model); }
        if ($cp)  update_option('suite_barrio_cp_model',       $model);
    }

    // ── Lingua ────────────────────────────────────────────────────────
    if ($alt) update_option('suite_barrio_alt_ai_language',    $language);
    if ($seo) { update_option('suite_barrio_llms_language',   $language); update_option('suite_barrio_advisor_language', $language); }

    // ── Tone ──────────────────────────────────────────────────────────
    if ($alt) update_option('suite_barrio_alt_ai_tone',    $tone);
    if ($seo) { update_option('suite_barrio_llms_tone',   $tone); update_option('suite_barrio_advisor_tone', $tone); }

    // ── Style ─────────────────────────────────────────────────────────
    if ($alt) update_option('suite_barrio_alt_ai_style',    $style);
    if ($seo) update_option('suite_barrio_advisor_style',  $style);

    // ── Contesto / keywords ───────────────────────────────────────────
    if ($alt) update_option('suite_barrio_alt_ai_context',    $context);
    if ($seo) { update_option('suite_barrio_advisor_context', $context); update_option('suite_barrio_llms_focus', $context); }
    if ($alt) update_option('suite_barrio_alt_ai_keywords',   $keywords);
    if ($seo) update_option('suite_barrio_advisor_keywords', $keywords);

    $progress           = suite_barrio_wizard_get_progress();
    $progress['step_6'] = 'done';
    update_option(SUITE_BARRIO_WIZARD_PROGRESS_OPTION, $progress);

    $next = suite_barrio_wizard_next_step(6);
    $url  = $next
        ? admin_url("admin.php?page=suite-barrio-wizard&step={$next}")
        : admin_url('admin.php?page=suite-barrio-wizard&completed=1');
    wp_safe_redirect($url);
    exit;
});

/* ─────────────────────────────────────────
   STEP 7 — SEO (4 sotto-step)
   ───────────────────────────────────────── */

// Helper interno: marca un sotto-step e, se è l'ultimo, anche step_7.
function suite_barrio_wizard_seo_mark(int $substep, string $status): void
{
    $progress = suite_barrio_wizard_get_progress();
    $progress["seo_sub_{$substep}"] = $status;
    // Se tutti e 4 sono completati, chiudi anche lo step principale.
    $all_done = true;
    foreach (suite_barrio_wizard_seo_substeps() as $n => $_) {
        $st = $progress["seo_sub_{$n}"] ?? 'pending';
        if ($st === 'pending') { $all_done = false; break; }
    }
    if ($all_done)
        $progress['step_7'] = ($status === 'skipped' && ($progress['step_7'] ?? '') !== 'done') ? 'skipped' : 'done';
    update_option(SUITE_BARRIO_WIZARD_PROGRESS_OPTION, $progress);
}

// Sotto-step 1 — SEO Globale
add_action('admin_post_suite_barrio_wizard_step7_sub1_save', function () {
    check_admin_referer('suite_barrio_wizard_step7_sub1_save');
    $settings = get_option('suite_barrio_meta_settings', []);
    if (!is_array($settings)) $settings = [];
    $settings['site_title']  = sanitize_text_field(wp_unslash($_POST['suite_barrio_meta_site_title']  ?? ''));
    $settings['separator']   = sanitize_text_field(wp_unslash($_POST['suite_barrio_meta_separator']   ?? '—'));
    $settings['title_order'] = sanitize_text_field(wp_unslash($_POST['suite_barrio_meta_title_order'] ?? 'page_site'));
    $settings['default_desc'] = sanitize_textarea_field(wp_unslash($_POST['suite_barrio_meta_default_desc'] ?? ''));
    update_option('suite_barrio_meta_settings', $settings);
    if (!empty($settings['site_title']))
        update_option('blogname', $settings['site_title']);
    suite_barrio_wizard_seo_mark(1, 'done');
    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-wizard&step=7&substep=2'));
    exit;
});

// Sotto-step 2 — Sitemap
add_action('admin_post_suite_barrio_wizard_step7_sub2_save', function () {
    check_admin_referer('suite_barrio_wizard_step7_sub2_save');
    $post_types = get_post_types(['public' => true], 'names');
    $taxonomies = array_diff(get_taxonomies(['public' => true], 'names'), ['post_format', 'nav_menu', 'link_category']);
    $settings   = [];
    foreach ($post_types as $pt)
        $settings['post_types'][$pt] = isset($_POST['pt'][$pt]) ? 1 : 0;
    foreach ($taxonomies as $tax)
        $settings['taxonomies'][$tax] = isset($_POST['tax'][$tax]) ? 1 : 0;
    update_option('suite_barrio_sitemap_settings', $settings);
    suite_barrio_wizard_seo_mark(2, 'done');
    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-wizard&step=7&substep=3'));
    exit;
});

// Sotto-step 3 — Schema
add_action('admin_post_suite_barrio_wizard_step7_sub3_save', function () {
    check_admin_referer('suite_barrio_wizard_step7_sub3_save');
    $s = [
        'business_type'  => sanitize_text_field(wp_unslash($_POST['business_type']  ?? 'LocalBusiness')),
        'business_name'  => sanitize_text_field(wp_unslash($_POST['business_name']  ?? '')),
        'description'    => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
        'url'            => esc_url_raw(wp_unslash($_POST['url']       ?? '')),
        'logo'           => esc_url_raw(wp_unslash($_POST['logo']      ?? '')),
        'image'          => esc_url_raw(wp_unslash($_POST['image']     ?? '')),
        'telephone'      => sanitize_text_field(wp_unslash($_POST['telephone']      ?? '')),
        'email'          => sanitize_email(wp_unslash($_POST['email']               ?? '')),
        'vat_id'         => sanitize_text_field(wp_unslash($_POST['vat_id']         ?? '')),
        'street_address' => sanitize_text_field(wp_unslash($_POST['street_address'] ?? '')),
        'postal_code'    => sanitize_text_field(wp_unslash($_POST['postal_code']    ?? '')),
        'locality'       => sanitize_text_field(wp_unslash($_POST['locality']       ?? '')),
        'region'         => sanitize_text_field(wp_unslash($_POST['region']         ?? '')),
        'country'        => sanitize_text_field(wp_unslash($_POST['country']        ?? 'IT')),
        'latitude'       => sanitize_text_field(wp_unslash($_POST['latitude']       ?? '')),
        'longitude'      => sanitize_text_field(wp_unslash($_POST['longitude']      ?? '')),
        'facebook'       => esc_url_raw(wp_unslash($_POST['facebook']  ?? '')),
        'instagram'      => esc_url_raw(wp_unslash($_POST['instagram'] ?? '')),
        'twitter'        => esc_url_raw(wp_unslash($_POST['twitter']   ?? '')),
        'linkedin'       => esc_url_raw(wp_unslash($_POST['linkedin']  ?? '')),
        'language'       => sanitize_text_field(wp_unslash($_POST['language']       ?? 'it-IT')),
    ];
    // Mantieni i campi esistenti non presenti nel form del wizard.
    $existing = get_option('suite_barrio_schema_settings', []);
    if (is_array($existing))
        $s = array_merge($existing, $s);
    update_option('suite_barrio_schema_settings', $s);
    suite_barrio_wizard_seo_mark(3, 'done');
    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-wizard&step=7&substep=4'));
    exit;
});

// Sotto-step 4 — Redirects (aggiunge una o più voci)
add_action('admin_post_suite_barrio_wizard_step7_sub4_save', function () {
    check_admin_referer('suite_barrio_wizard_step7_sub4_save');

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array grezzo di righe; ogni campo (from/to/code/note) viene unslashed e sanitizzato singolarmente nel foreach sottostante
    $rows      = isset($_POST['redirects']) && is_array($_POST['redirects']) ? $_POST['redirects'] : [];
    $valid_codes = [301, 302, 307, 308, 410];
    $saved     = get_option('suite_barrio_redirects', []);
    if (!is_array($saved)) $saved = [];

    foreach ($rows as $row) {
        $from = sanitize_text_field(trim(wp_unslash($row['from'] ?? '')));
        $to   = esc_url_raw(trim(wp_unslash($row['to']   ?? '')));
        if ($from === '' || $to === '') continue;
        $code = (int) ($row['code'] ?? 301);
        $saved[] = [
            'id'   => uniqid('sbrio_', false),
            'from' => '/' . ltrim($from, '/'),
            'to'   => $to,
            'code' => in_array($code, $valid_codes, true) ? $code : 301,
            'note' => sanitize_text_field(wp_unslash($row['note'] ?? '')),
        ];
    }
    update_option('suite_barrio_redirects', $saved);

    suite_barrio_wizard_seo_mark(4, 'done');
    $next = suite_barrio_wizard_next_step(7);
    wp_safe_redirect($next
        ? admin_url("admin.php?page=suite-barrio-wizard&step={$next}")
        : admin_url('admin.php?page=suite-barrio-wizard&completed=1'));
    exit;
});

// Skip di un singolo sotto-step SEO
add_action('admin_post_suite_barrio_wizard_seo_substep_skip', function () {
    check_admin_referer('suite_barrio_wizard_seo_substep_skip');
    $sub = max(1, min(4, absint(wp_unslash($_POST['substep'] ?? 1))));
    suite_barrio_wizard_seo_mark($sub, 'skipped');
    $next_sub = $sub + 1;
    if ($next_sub <= 4) {
        wp_safe_redirect(admin_url("admin.php?page=suite-barrio-wizard&step=7&substep={$next_sub}"));
    } else {
        $next_step = suite_barrio_wizard_next_step(7);
        wp_safe_redirect($next_step
            ? admin_url("admin.php?page=suite-barrio-wizard&step={$next_step}")
            : admin_url('admin.php?page=suite-barrio-wizard&completed=1'));
    }
    exit;
});

/* ─────────────────────────────────────────
   SALVATAGGIO STEP 8 — Controlli Finali
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_wizard_step8_save', function () {
    check_admin_referer('suite_barrio_wizard_step8_save');

    $allowed = ['gtm_analytics', 'mailjet_recaptcha', 'email_forms', 'orari_gmaps', 'popup_banner', 'auto_alt', 'cookie_policy', 'accessibility'];
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_unslash() sull'array e sanitize_key() su ogni chiave estratta, poi filtrate contro $allowed subito sotto
    $submitted = isset($_POST['checks']) && is_array($_POST['checks']) ? array_map('sanitize_key', array_keys(wp_unslash($_POST['checks']))) : [];
    $checks = array_filter($submitted, fn($k) => in_array($k, $allowed, true));

    $progress                  = suite_barrio_wizard_get_progress();
    $progress['step_8']        = 'done';
    $progress['step_8_checks'] = array_values($checks);
    update_option(SUITE_BARRIO_WIZARD_PROGRESS_OPTION, $progress);

    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-wizard&completed=1'));
    exit;
});

/* ─────────────────────────────────────────
   SALTA PASSAGGIO
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_wizard_skip', function () {
    check_admin_referer('suite_barrio_wizard_skip');
    $step = max(1, absint(wp_unslash($_POST['step'] ?? 1)));

    $progress = suite_barrio_wizard_get_progress();
    $progress["step_{$step}"] = 'skipped';
    update_option(SUITE_BARRIO_WIZARD_PROGRESS_OPTION, $progress);

    $next = suite_barrio_wizard_next_step($step);
    $url = $next
        ? admin_url("admin.php?page=suite-barrio-wizard&step={$next}")
        : admin_url('admin.php?page=suite-barrio-wizard&completed=1');
    wp_safe_redirect($url);
    exit;
});


/* ─────────────────────────────────────────
   PAGINA WIZARD
   ───────────────────────────────────────── */
function suite_barrio_wizard_page()
{
    $steps = suite_barrio_wizard_steps();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $completed = isset($_GET['completed']);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $current = max(1, absint(wp_unslash($_GET['step'] ?? 1)));
    if (!isset($steps[$current]))
        $current = 1;

    $data = function_exists('suite_barrio_company_info_get') ? suite_barrio_company_info_get() : [];

    $step1_fields = [
        'ragione_sociale' => ['label' => 'Ragione sociale', 'type' => 'text', 'full' => true],
        'sito_web' => ['label' => 'Sito web', 'type' => 'url', 'placeholder' => 'https://'],
        'email' => ['label' => 'Email', 'type' => 'email'],
        'email_privacy' => ['label' => 'Email privacy', 'type' => 'email', 'placeholder' => 'privacy@...'],
        'pec' => ['label' => 'PEC', 'type' => 'email'],
        'telefono' => ['label' => 'Telefono', 'type' => 'text', 'placeholder' => 'es. +39 081 123 4567'],
        'cellulare' => ['label' => 'Cellulare', 'type' => 'text'],
        'partita_iva' => ['label' => 'Partita IVA', 'type' => 'text'],
        'codice_fiscale' => ['label' => 'Codice Fiscale', 'type' => 'text'],
        'codice_univoco' => ['label' => 'Codice univoco (SDI)', 'type' => 'text', 'placeholder' => 'Fatturazione elettronica'],
        'rea' => ['label' => 'N. REA', 'type' => 'text', 'placeholder' => 'Iscrizione Camera di Commercio'],
        'capitale_sociale' => ['label' => 'Capitale sociale', 'type' => 'text', 'placeholder' => 'es. 10.000 € i.v.'],
    ];
    ?>
    <div id="suite-barrio-wizard" class="wrap suite-barrio-wrap">

        <!-- Header -->
        <div class="suite-barrio-header-row">
            <div class="suite-barrio-header">
                <div class="suite-barrio-header-icon">
                    <span class="dashicons dashicons-migrate"
                        style="color:#fff;font-size:20px;width:20px;height:20px;"></span>
                </div>
                <div>
                    <h1>Messa Online</h1>
                    <p class="suite-barrio-header-sub">Guida passo-passo per portare il sito in produzione</p>
                </div>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio')); ?>"
                class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-ghost">
                <span class="dashicons dashicons-arrow-left-alt2" style="font-size:14px;width:14px;height:14px;"></span>
                Dashboard
            </a>
        </div>

        <hr class="suite-barrio-divider">

        <!-- Navigazione step -->
        <div class="suite-barrio-wizard-steps">
            <?php foreach ($steps as $num => $step):
                $status = suite_barrio_wizard_step_status($num);
                $accessible = suite_barrio_wizard_step_accessible($num);
                $is_active = ($num === $current) && !$completed;
                $cls = 'suite-barrio-wizard-step';
                if ($is_active)
                    $cls .= ' is-active';
                elseif ($status === 'done')
                    $cls .= ' is-done';
                elseif ($status === 'skipped')
                    $cls .= ' is-skipped';
                $step_url = esc_url(admin_url("admin.php?page=suite-barrio-wizard&step={$num}"));
                ?>
                <div class="<?php echo esc_attr($cls); ?>">
                    <?php if ($accessible && !$is_active): ?>
                        <a href="<?php echo esc_url($step_url); ?>" class="suite-barrio-wizard-step-inner">
                        <?php else: ?>
                            <span class="suite-barrio-wizard-step-inner">
                            <?php endif; ?>
                            <div class="suite-barrio-wizard-step-num">
                                <?php if ($status === 'done'): ?>
                                    <span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;"></span>
                                <?php elseif ($status === 'skipped'): ?>
                                    <span style="font-size:11px;line-height:1;">—</span>
                                <?php else: ?>
                                    <?php echo esc_html($num); ?>
                                <?php endif; ?>
                            </div>
                            <div class="suite-barrio-wizard-step-label"><?php echo esc_html($step['label']); ?></div>
                            <?php if ($accessible && !$is_active): ?>
                        </a>
                    <?php else: ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($completed):
            $perf_url = trim($data['sito_web'] ?? '');
        ?>

            <!-- Schermata completamento -->
            <div class="suite-barrio-wizard-complete">
                <div class="suite-barrio-wizard-complete-icon">
                    <span class="dashicons dashicons-yes-alt"></span>
                </div>
                <h2>Wizard completato!</h2>
                <p>Hai completato tutti i passaggi della messa online.</p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio')); ?>" class="suite-barrio-btn suite-barrio-btn-primary">
                    Torna alla dashboard
                </a>
                <div style="margin-top:14px;">
                    <?php if (!empty($perf_url)): ?>
                        <a href="<?php echo esc_url('https://pagespeed.web.dev/report?url=' . rawurlencode($perf_url)); ?>"
                           target="_blank" rel="noopener" class="suite-barrio-btn suite-barrio-btn-ghost">
                            <span class="dashicons dashicons-performance" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span>
                            Verifica prestazioni
                        </a>
                    <?php else: ?>
                        <button class="suite-barrio-btn suite-barrio-btn-ghost" disabled
                            title="Inserisci l'URL del sito nello Step 1 per usare questa funzione">
                            <span class="dashicons dashicons-performance" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span>
                            Verifica prestazioni
                        </button>
                        <p style="font-size:12px;color:#9ca3af;margin:6px 0 0;">Aggiungi l'URL del sito nello Step 1 per abilitare questa funzione.</p>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($current === 1): ?>

            <!-- Card step 1 -->
            <div class="suite-barrio-wizard-card">

                <div class="suite-barrio-wizard-card-head">
                    <div class="suite-barrio-wizard-card-head-icon">
                        <span class="dashicons dashicons-id-alt"></span>
                    </div>
                    <div>
                        <div class="suite-barrio-wizard-card-title">Dati azienda</div>
                        <div class="suite-barrio-wizard-card-subtitle">Ragione sociale, recapiti e dati fiscali</div>
                    </div>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="suite-barrio-wizard-form">
                    <?php wp_nonce_field('suite_barrio_wizard_step1_save'); ?>
                    <input type="hidden" name="action" value="suite_barrio_wizard_step1_save">

                    <div class="suite-barrio-wizard-fields">
                        <?php foreach ($step1_fields as $key => $field):
                            $value = $data[$key] ?? '';
                            $placeholder = $field['placeholder'] ?? '';
                            $is_full = !empty($field['full']);
                            ?>
                            <div class="suite-barrio-wizard-field<?php echo $is_full ? ' suite-barrio-wizard-field-full' : ''; ?>">
                                <label for="suite-barrio-wiz-<?php echo esc_attr($key); ?>">
                                    <?php echo esc_html($field['label']); ?>
                                </label>
                                <input type="<?php echo esc_attr($field['type']); ?>" id="suite-barrio-wiz-<?php echo esc_attr($key); ?>"
                                    name="company[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>"
                                    placeholder="<?php echo esc_attr($placeholder); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="suite-barrio-wizard-actions">
                        <button type="submit" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-wizard-submit">
                            Continua
                            <span class="dashicons dashicons-arrow-right-alt2"
                                style="font-size:15px;width:15px;height:15px;"></span>
                        </button>
                    </div>
                </form>

                <!-- Skip: form separato, fuori dal form principale -->
                <div class="suite-barrio-wizard-card-footer">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('suite_barrio_wizard_skip'); ?>
                        <input type="hidden" name="action" value="suite_barrio_wizard_skip">
                        <input type="hidden" name="step" value="1">
                        <button type="submit" class="suite-barrio-wizard-skip-btn">Salta passaggio</button>
                    </form>
                </div>

            </div>

        <?php elseif ($current === 2):

            $module_meta = [
                'accessibility'  => ['icon' => 'dashicons-universal-access', 'color' => '#0d9488'],
                'auto_alt'       => ['icon' => 'dashicons-format-image', 'color' => '#1d4ed8'],
                'auto_fields'    => ['icon' => 'dashicons-layout',       'color' => '#7c3aed'],
                'banners'        => ['icon' => 'dashicons-flag',         'color' => '#0369a1'],
                'cookie_privacy' => ['icon' => 'dashicons-shield',       'color' => '#0f766e'],
                'duplicate'      => ['icon' => 'dashicons-admin-page',   'color' => '#0f766e'],
                'forms'          => ['icon' => 'dashicons-feedback',     'color' => '#059669'],
                'orari'          => ['icon' => 'dashicons-clock',        'color' => '#c2410c'],
                'orders'         => ['icon' => 'dashicons-sort',         'color' => '#5b21b6'],
                'popups'         => ['icon' => 'dashicons-megaphone',    'color' => '#6d28d9'],
                'seo'            => ['icon' => 'dashicons-search',       'color' => '#ea580c'],
            ];
        ?>

            <!-- Card step 2 -->
            <div class="suite-barrio-wizard-card">

                <div class="suite-barrio-wizard-card-head">
                    <div class="suite-barrio-wizard-card-head-icon">
                        <span class="dashicons dashicons-admin-plugins"></span>
                    </div>
                    <div>
                        <div class="suite-barrio-wizard-card-title">Strumenti attivi</div>
                        <div class="suite-barrio-wizard-card-subtitle">Seleziona i moduli di Suite Barrio da utilizzare su questo sito</div>
                    </div>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="suite-barrio-wizard-form">
                    <?php wp_nonce_field('suite_barrio_wizard_step2_save'); ?>
                    <input type="hidden" name="action" value="suite_barrio_wizard_step2_save">

                    <div class="suite-barrio-wizard-modules">
                        <?php foreach (suite_barrio_tools_modules_list() as $key => $mod):
                            $enabled = suite_barrio_tools_module_enabled($key);
                            $meta    = $module_meta[$key] ?? ['icon' => 'dashicons-admin-generic', 'color' => '#374151'];
                        ?>
                            <label class="suite-barrio-wizard-module" for="suite-barrio-mod-<?php echo esc_attr($key); ?>">
                                <div class="suite-barrio-wizard-module-left">
                                    <div class="suite-barrio-wizard-module-icon" style="background:<?php echo esc_attr($meta['color']); ?>">
                                        <span class="dashicons <?php echo esc_attr($meta['icon']); ?>"></span>
                                    </div>
                                    <div class="suite-barrio-wizard-module-body">
                                        <div class="suite-barrio-wizard-module-label"><?php echo esc_html($mod['label']); ?></div>
                                        <div class="suite-barrio-wizard-module-desc"><?php echo esc_html($mod['desc']); ?></div>
                                    </div>
                                </div>
                                <div class="suite-barrio-toggle">
                                    <input type="checkbox" id="suite-barrio-mod-<?php echo esc_attr($key); ?>"
                                        name="modules[<?php echo esc_attr($key); ?>]" value="1"
                                        <?php checked($enabled, true); ?>>
                                    <span class="suite-barrio-toggle-track"></span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="suite-barrio-wizard-actions">
                        <button type="submit" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-wizard-submit">
                            Continua
                            <span class="dashicons dashicons-arrow-right-alt2" style="font-size:15px;width:15px;height:15px;"></span>
                        </button>
                    </div>
                </form>

                <div class="suite-barrio-wizard-card-footer">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('suite_barrio_wizard_skip'); ?>
                        <input type="hidden" name="action" value="suite_barrio_wizard_skip">
                        <input type="hidden" name="step" value="2">
                        <button type="submit" class="suite-barrio-wizard-skip-btn">Salta passaggio</button>
                    </form>
                </div>

            </div>

        <?php elseif ($current === 3):

            $site_url     = trim($data['sito_web'] ?? '');
            $saved_gtm_id = get_option('suite_barrio_gtm_container_id', '');
            $gtm_detected = '';
            $check_status = 'unchecked'; // 'found' | 'not_found' | 'error' | 'no_url'

            if (empty($site_url)) {
                $check_status = 'no_url';
            } else {
                $response = wp_remote_get($site_url, [
                    'timeout'   => 12,
                    'sslverify' => false,
                    'headers'   => ['Accept' => 'text/html'],
                ]);
                if (is_wp_error($response)) {
                    $check_status = 'error';
                } else {
                    $body = wp_remote_retrieve_body($response);
                    if (preg_match('/(GTM-[A-Z0-9]+)/i', $body, $m)) {
                        $check_status = 'found';
                        $gtm_detected = strtoupper($m[1]);
                    } else {
                        $check_status = 'not_found';
                    }
                }
            }

            // Pre-popola: priorità → già salvato, poi rilevato
            $gtm_prefill = $saved_gtm_id ?: $gtm_detected;
        ?>

            <!-- Card step 3 -->
            <div class="suite-barrio-wizard-card">

                <div class="suite-barrio-wizard-card-head">
                    <div class="suite-barrio-wizard-card-head-icon" style="background:#e37400;">
                        <span class="dashicons dashicons-tag"></span>
                    </div>
                    <div>
                        <div class="suite-barrio-wizard-card-title">Google Tag Manager</div>
                        <div class="suite-barrio-wizard-card-subtitle">Verifica e configura il tag GTM sul sito</div>
                    </div>
                </div>

                <div class="suite-barrio-wizard-form">

                    <!-- Risultato controllo -->
                    <?php if ($check_status === 'no_url'): ?>
                        <div class="suite-barrio-wizard-gtm-check suite-barrio-wizard-gtm-warn">
                            <span class="dashicons dashicons-info-outline"></span>
                            <div>
                                <strong>URL sito non impostato</strong>
                                <span>Inserisci l'URL del sito nello Step 1 per eseguire il controllo automatico.</span>
                            </div>
                        </div>

                    <?php elseif ($check_status === 'error'): ?>
                        <div class="suite-barrio-wizard-gtm-check suite-barrio-wizard-gtm-warn">
                            <span class="dashicons dashicons-warning"></span>
                            <div>
                                <strong>Sito non raggiungibile</strong>
                                <span>Impossibile connettersi a <code><?php echo esc_html($site_url); ?></code>. Verifica che il sito sia online.</span>
                            </div>
                        </div>

                    <?php elseif ($check_status === 'found'): ?>
                        <div class="suite-barrio-wizard-gtm-check suite-barrio-wizard-gtm-ok">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <div>
                                <strong>Tag GTM rilevato</strong>
                                <span>Trovato <code><?php echo esc_html($gtm_detected); ?></code> su <code><?php echo esc_html($site_url); ?></code></span>
                            </div>
                        </div>

                    <?php else: ?>
                        <div class="suite-barrio-wizard-gtm-check suite-barrio-wizard-gtm-error">
                            <span class="dashicons dashicons-dismiss"></span>
                            <div>
                                <strong>Tag GTM non trovato</strong>
                                <span>Nessun container GTM rilevato su <code><?php echo esc_html($site_url); ?></code>. Verifica che il tag sia installato correttamente.</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('suite_barrio_wizard_step3_save'); ?>
                        <input type="hidden" name="action" value="suite_barrio_wizard_step3_save">

                        <div class="suite-barrio-wizard-fields">
                            <div class="suite-barrio-wizard-field suite-barrio-wizard-field-full">
                                <label for="suite-barrio-wiz-gtm-id">Container ID</label>
                                <input type="text" id="suite-barrio-wiz-gtm-id" name="gtm_id"
                                    value="<?php echo esc_attr($gtm_prefill); ?>"
                                    placeholder="GTM-XXXXXXX">
                            </div>
                        </div>

                        <div class="suite-barrio-wizard-actions">
                            <button type="submit" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-wizard-submit"
                                <?php echo $check_status !== 'found' ? 'disabled' : ''; ?>>
                                Continua
                                <span class="dashicons dashicons-arrow-right-alt2" style="font-size:15px;width:15px;height:15px;"></span>
                            </button>
                            <?php if ($check_status !== 'found'): ?>
                                <span class="suite-barrio-wizard-submit-note">
                                    GTM non rilevato — usa "Salta passaggio" per procedere senza.
                                </span>
                            <?php endif; ?>
                        </div>
                    </form>

                </div>

                <div class="suite-barrio-wizard-card-footer">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('suite_barrio_wizard_skip'); ?>
                        <input type="hidden" name="action" value="suite_barrio_wizard_skip">
                        <input type="hidden" name="step" value="3">
                        <button type="submit" class="suite-barrio-wizard-skip-btn">Salta passaggio</button>
                    </form>
                </div>

            </div>

        <?php elseif ($current === 4):

            $mj = get_option('suite_barrio_forms_mailjet', []);
            if (!is_array($mj)) $mj = [];
            $mj_api_key    = $mj['api_key']    ?? '';
            $mj_secret_key = $mj['secret_key'] ?? '';
        ?>

            <!-- Card step 4 — Mailjet -->
            <div class="suite-barrio-wizard-card">

                <div class="suite-barrio-wizard-card-head">
                    <div class="suite-barrio-wizard-card-head-icon" style="background:#f7622c;">
                        <span class="dashicons dashicons-email" style="color:#fff;"></span>
                    </div>
                    <div>
                        <div class="suite-barrio-wizard-card-title">Mailjet</div>
                        <div class="suite-barrio-wizard-card-subtitle">Credenziali per l'invio email tramite lo strumento Forms</div>
                    </div>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="suite-barrio-wizard-form">
                    <?php wp_nonce_field('suite_barrio_wizard_step4_save'); ?>
                    <input type="hidden" name="action" value="suite_barrio_wizard_step4_save">

                    <div class="suite-barrio-wizard-fields">

                        <div class="suite-barrio-wizard-field suite-barrio-wizard-field-full">
                            <label for="suite-barrio-wiz-mj-api-key">API Key (pubblica)</label>
                            <input type="text" id="suite-barrio-wiz-mj-api-key" name="mailjet_api_key"
                                value="<?php echo esc_attr($mj_api_key); ?>"
                                placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                autocomplete="off">
                        </div>

                        <div class="suite-barrio-wizard-field suite-barrio-wizard-field-full">
                            <label for="suite-barrio-wiz-mj-secret-key">Secret Key (privata)</label>
                            <input type="password" id="suite-barrio-wiz-mj-secret-key" name="mailjet_secret_key"
                                value="<?php echo esc_attr($mj_secret_key); ?>"
                                placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                autocomplete="off">
                        </div>

                    </div>

                    <div class="suite-barrio-wizard-actions">
                        <button type="submit" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-wizard-submit">
                            Continua
                            <span class="dashicons dashicons-arrow-right-alt2" style="font-size:15px;width:15px;height:15px;"></span>
                        </button>
                    </div>
                </form>

                <div class="suite-barrio-wizard-card-footer">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('suite_barrio_wizard_skip'); ?>
                        <input type="hidden" name="action" value="suite_barrio_wizard_skip">
                        <input type="hidden" name="step" value="4">
                        <button type="submit" class="suite-barrio-wizard-skip-btn">Salta passaggio</button>
                    </form>
                </div>

            </div>

        <?php elseif ($current === 5):

            // ── Fetch + rilevamento banner cookie ──────────────────────────
            $site_url     = trim($data['sito_web'] ?? '');
            $check_status = 'unchecked'; // 'found' | 'not_found' | 'error' | 'no_url'
            $platform     = '';

            // Firme → nome piattaforma (ordine: più specifici prima)
            // Ogni valore è un array di stringhe cercate con stripos nel body HTML.
            // Includono: URL script, classi CSS, id, variabili JS, nomi cookie —
            // così il rilevamento funziona anche quando il banner è caricato via GTM.
            $signatures = [
                'CookieYes'           => ['cookieyes', 'cky-consent', 'cky-banner', 'cky-overlay'],
                'Cookiebot'           => ['cookiebot', 'CookieDeclaration', 'CookieConsent.renew', 'Cookiebot.onConsentReady'],
                'OneTrust'            => ['onetrust', 'optanon', 'ot-sdk-btn', 'OptanonConsent'],
                'iubenda'             => ['iubenda', '_iub_cs_activate', 'iubConsentContainer'],
                'Axeptio'             => ['axeptio', 'axeptio_sdk', 'window.axeptioSettings'],
                'Osano'               => ['osano-cm', 'Osano.cm', 'osano-cm-dom-info-dialog'],
                'TrustArc'            => ['trustarc', 'consent.trustarc', 'truste.eu.bindMap'],
                'Cookie Script'       => [
                    'cookie-script.com',      // URL CDN nel tag <script>
                    'cookiescript',           // classe/id/variabile
                    'cs-cookiescript',        // classe CSS
                    'cs-cookieConsent',       // classe contenitore
                    'CookieScriptConsent',    // nome cookie
                    'cookiescript_accepted',  // cookie impostato da CS
                    'cookiescript_injected',  // id iniettato
                ],
                'Complianz'           => ['complianz', 'cmplz-', 'cmplz_banner', 'cmplz-cookie-banner'],
                'Tarteaucitron'       => ['tarteaucitron'],
                'Cookie Notice (WP)'  => ['cookie-notice-container', 'cookie_notice_accepted', 'cn-notice'],
                'GDPR Cookie Consent' => ['gdpr-cookie-consent', 'wt-cli-cookie', 'wt_cli_accepted'],
                'WP Cookie Consent'   => ['wpcc-container', 'wp-cookie-consent'],
                'Suite Barrio'           => ['suite-barrio-cookie', 'suite-barrio-cp-banner'],
                'Generico'            => ['cookieconsent', 'cookie-consent-banner', 'cookie-banner', 'cookie-popup', 'cookie-law-info', 'js-cookie-consent'],
            ];

            // Helper: cerca le firme in una stringa, restituisce il nome piattaforma o ''.
            $scan_body = function (string $body) use ($signatures): string {
                foreach ($signatures as $name => $patterns) {
                    foreach ($patterns as $pattern) {
                        if (stripos($body, $pattern) !== false) {
                            return $name;
                        }
                    }
                }
                return '';
            };

            if (empty($site_url)) {
                $check_status = 'no_url';
            } else {
                // ── Passo 1: HTML statico della homepage ────────────────────
                $response = wp_remote_get($site_url, [
                    'timeout'     => 15,
                    'sslverify'   => false,
                    'redirection' => 5,
                    'headers'     => [
                        'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'it-IT,it;q=0.9,en;q=0.8',
                        'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                    ],
                ]);

                if (is_wp_error($response)) {
                    $check_status = 'error';
                } else {
                    $found = $scan_body(wp_remote_retrieve_body($response));
                    if ($found !== '') {
                        $check_status = 'found';
                        $platform     = $found;
                    }
                }

                // ── Passo 2: container GTM (tag caricati via GTM non appaiono
                //    nell'HTML statico — ma sono scritti in chiaro nel file JS
                //    del container, accessibile pubblicamente). ────────────────
                if ($check_status !== 'found' && $check_status !== 'error') {
                    $gtm_id = strtoupper(trim(get_option('suite_barrio_gtm_container_id', '')));
                    if (!empty($gtm_id)) {
                        $gtm_url      = 'https://www.googletagmanager.com/gtm.js?id=' . rawurlencode($gtm_id);
                        $gtm_response = wp_remote_get($gtm_url, [
                            'timeout'   => 15,
                            'sslverify' => false,
                        ]);
                        if (!is_wp_error($gtm_response)) {
                            $found = $scan_body(wp_remote_retrieve_body($gtm_response));
                            if ($found !== '') {
                                $check_status = 'found';
                                $platform     = $found . ' (via GTM)';
                            }
                        }
                    }
                }

                if ($check_status !== 'found' && $check_status !== 'error') {
                    $check_status = 'not_found';
                }
            }
        ?>

            <!-- Card step 4 -->
            <div class="suite-barrio-wizard-card">

                <div class="suite-barrio-wizard-card-head">
                    <div class="suite-barrio-wizard-card-head-icon" style="background:#0f766e;">
                        <span class="dashicons dashicons-shield"></span>
                    </div>
                    <div>
                        <div class="suite-barrio-wizard-card-title">Cookie e Privacy</div>
                        <div class="suite-barrio-wizard-card-subtitle">Verifica la presenza di un banner cookie sul sito</div>
                    </div>
                </div>

                <div class="suite-barrio-wizard-form">

                    <?php if ($check_status === 'no_url'): ?>
                        <div class="suite-barrio-wizard-gtm-check suite-barrio-wizard-gtm-warn">
                            <span class="dashicons dashicons-info-outline"></span>
                            <div>
                                <strong>URL sito non impostato</strong>
                                <span>Inserisci l'URL del sito nello Step 1 per eseguire il controllo automatico.</span>
                            </div>
                        </div>

                    <?php elseif ($check_status === 'error'): ?>
                        <div class="suite-barrio-wizard-gtm-check suite-barrio-wizard-gtm-warn">
                            <span class="dashicons dashicons-warning"></span>
                            <div>
                                <strong>Sito non raggiungibile</strong>
                                <span>Impossibile connettersi a <code><?php echo esc_html($site_url); ?></code>. Verifica che il sito sia online.</span>
                            </div>
                        </div>

                    <?php elseif ($check_status === 'found'): ?>
                        <div class="suite-barrio-wizard-gtm-check suite-barrio-wizard-gtm-ok">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <div>
                                <strong>Banner cookie rilevato<?php echo $platform !== 'Generico' ? ' — ' . esc_html($platform) : ''; ?></strong>
                                <span>Il sito <code><?php echo esc_html($site_url); ?></code> ha già un sistema di gestione del consenso.</span>
                            </div>
                        </div>

                    <?php else: ?>
                        <div class="suite-barrio-wizard-gtm-check suite-barrio-wizard-gtm-error">
                            <span class="dashicons dashicons-dismiss"></span>
                            <div>
                                <strong>Nessun banner cookie rilevato</strong>
                                <span>Il sito <code><?php echo esc_html($site_url); ?></code> non sembra avere un banner cookie attivo.</span>
                            </div>
                        </div>

                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('suite_barrio_wizard_step5_save'); ?>
                        <input type="hidden" name="action" value="suite_barrio_wizard_step5_save">

                        <?php if ($check_status === 'found'): ?>
                            <div class="suite-barrio-wizard-field suite-barrio-wizard-field-full" style="margin-top:20px;">
                                <label for="suite_barrio_cp_cookie_report_script_wiz" class="suite-barrio-wizard-label">
                                    Tabella riepilogo cookie
                                    <span class="suite-barrio-wizard-label-hint">Opzionale — incolla qui il codice HTML/script della tabella cookie fornito da <?php echo esc_html(str_replace(' (via GTM)', '', $platform)); ?>. Verrà aggiunto in coda alla Cookie Policy.</span>
                                </label>
                                <textarea
                                    id="suite_barrio_cp_cookie_report_script_wiz"
                                    name="suite_barrio_cp_cookie_report_script"
                                    rows="6"
                                    placeholder="<script id=&quot;CookieDeclaration&quot; ...></script>"
                                    class="suite-barrio-wizard-textarea"
                                ><?php echo esc_textarea(get_option('suite_barrio_cp_cookie_report_script', '')); ?></textarea>
                            </div>
                        <?php endif; ?>

                        <div class="suite-barrio-wizard-actions">
                            <button type="submit" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-wizard-submit"
                                <?php echo $check_status !== 'found' ? 'disabled' : ''; ?>>
                                Continua
                                <span class="dashicons dashicons-arrow-right-alt2" style="font-size:15px;width:15px;height:15px;"></span>
                            </button>
                        </div>
                        <?php if ($check_status === 'not_found'): ?>
                            <p class="suite-barrio-wizard-submit-note">
                                Nessun banner cookie rilevato sul sito. Usa "Salta passaggio" se vuoi procedere senza configurare un banner.
                            </p>
                        <?php endif; ?>
                    </form>

                </div>

                <div class="suite-barrio-wizard-card-footer">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('suite_barrio_wizard_skip'); ?>
                        <input type="hidden" name="action" value="suite_barrio_wizard_skip">
                        <input type="hidden" name="step" value="5">
                        <button type="submit" class="suite-barrio-wizard-skip-btn">Salta passaggio</button>
                    </form>
                </div>

            </div>

        <?php elseif ($current === 6):

            $fn         = 'suite_barrio_tools_module_enabled';
            $active_alt = function_exists($fn) && suite_barrio_tools_module_enabled('auto_alt');
            $active_seo = function_exists($fn) && suite_barrio_tools_module_enabled('seo');
            $active_cp  = function_exists($fn) && suite_barrio_tools_module_enabled('cookie_privacy');

            $active_labels = array_filter([
                $active_alt ? 'Auto Alt'         : '',
                $active_seo ? 'SEO'              : '',
                $active_cp  ? 'Cookie e Privacy' : '',
            ]);

            // Legge i valori correnti dal primo modulo attivo che li ha.
            $ref        = $active_alt ? 'suite_barrio_alt_ai' : ($active_seo ? 'suite_barrio_llms' : 'suite_barrio_advisor');
            $provider   = (string) get_option($ref . '_provider', 'anthropic');
            $key_anth   = (string) get_option($active_alt ? 'suite_barrio_alt_ai_api_key'    : ($active_seo ? 'suite_barrio_llms_api_key'   : 'suite_barrio_advisor_api_key'),    '');
            $key_gem    = (string) get_option($active_alt ? 'suite_barrio_alt_ai_gemini_key' : ($active_seo ? 'suite_barrio_llms_gemini_key' : 'suite_barrio_advisor_gemini_key'), '');
            $model      = (string) get_option($ref . '_model',    '');
            $language   = (string) get_option($ref . '_language', 'it');
            $tone       = (string) get_option($ref . '_tone',     'neutral');
            $style      = (string) get_option($active_alt ? 'suite_barrio_alt_ai_style' : 'suite_barrio_advisor_style', 'descriptive');
            $context    = (string) get_option($active_alt ? 'suite_barrio_alt_ai_context'  : ($active_seo ? 'suite_barrio_llms_focus' : 'suite_barrio_advisor_context'), '');
            $keywords   = (string) get_option($active_alt ? 'suite_barrio_alt_ai_keywords' : 'suite_barrio_advisor_keywords', '');

            $providers_cfg = [
                'anthropic' => [
                    'label'    => 'Anthropic — Claude',
                    'desc'     => 'Claude Opus, Sonnet, Haiku — eccellente per descrizioni contestuali',
                    'key_ph'   => 'sk-ant-api03-…',
                    'models'   => [
                        'claude-opus-4-6'             => ['name' => 'Claude Opus 4.6',   'desc' => 'Massima qualità, descrizioni dettagliate'],
                        'claude-sonnet-4-6'           => ['name' => 'Claude Sonnet 4.6', 'desc' => 'Equilibrio ottimale qualità/velocità'],
                        'claude-haiku-4-5-20251001'   => ['name' => 'Claude Haiku 4.5',  'desc' => 'Veloce ed economico, grandi volumi'],
                    ],
                    'default'  => 'claude-opus-4-6',
                ],
                'gemini' => [
                    'label'    => 'Google — Gemini',
                    'desc'     => 'Gemini 2.5 / 2.0 Flash & Pro — ottimo rapporto qualità/costo',
                    'key_ph'   => 'AIzaSy…',
                    'models'   => [
                        'gemini-2.5-pro'        => ['name' => 'Gemini 2.5 Pro',        'desc' => 'Massima qualità, ragionamento avanzato'],
                        'gemini-2.5-flash'      => ['name' => 'Gemini 2.5 Flash',      'desc' => 'Ottimo equilibrio qualità/velocità'],
                        'gemini-2.0-flash'      => ['name' => 'Gemini 2.0 Flash',      'desc' => 'Veloce ed economico, uso generale'],
                        'gemini-2.0-flash-lite' => ['name' => 'Gemini 2.0 Flash-Lite', 'desc' => 'Ultraveloce, alta frequenza'],
                    ],
                    'default'  => 'gemini-2.5-flash',
                ],
            ];
        ?>

            <!-- Card step 5 -->
            <div class="suite-barrio-wizard-card">

                <div class="suite-barrio-wizard-card-head">
                    <div class="suite-barrio-wizard-card-head-icon" style="background:#7c3aed;">
                        <span class="dashicons dashicons-admin-network" style="color:#fff;"></span>
                    </div>
                    <div>
                        <h2 style="margin:0 0 4px;font-size:18px;">Configura AI</h2>
                        <p style="margin:0;color:#6b7280;font-size:14px;">
                            Impostazioni propagate a tutti i moduli AI attivi:
                            <strong><?php echo esc_html(implode(', ', $active_labels)); ?></strong>.
                        </p>
                    </div>
                </div>

                <div class="suite-barrio-wizard-form">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('suite_barrio_wizard_step6_save'); ?>
                    <input type="hidden" name="action" value="suite_barrio_wizard_step6_save">

                    <!-- ── Provider & modello ──────────────────────────── -->
                    <div class="suite-barrio-section-box" style="margin-bottom:16px;">
                        <div class="suite-barrio-section-head">
                            <span class="dashicons dashicons-superhero-alt"></span> Provider e modello AI
                        </div>
                        <div class="suite-barrio-section-body">
                        <?php foreach ($providers_cfg as $pval => $pcfg):
                            $is_sel      = ($provider === $pval);
                            $active_mdl  = ($is_sel && isset($pcfg['models'][$model])) ? $model : $pcfg['default'];
                            $key_val     = ($pval === 'anthropic') ? $key_anth : $key_gem;
                        ?>
                            <label class="suite-barrio-provider-card <?php echo $is_sel ? 'selected' : ''; ?>"
                                data-provider="<?php echo esc_attr($pval); ?>">
                                <input type="radio" name="suite_barrio_wizard_ai_provider"
                                    value="<?php echo esc_attr($pval); ?>"
                                    <?php checked($provider, $pval); ?>>
                                <div>
                                    <div class="suite-barrio-pc-name"><?php echo esc_html($pcfg['label']); ?></div>
                                    <div class="suite-barrio-pc-desc"><?php echo esc_html($pcfg['desc']); ?></div>
                                </div>
                            </label>

                            <div class="suite-barrio-provider-keys <?php echo $is_sel ? 'visible' : ''; ?>"
                                id="suite-barrio-wiz-keys-<?php echo esc_attr($pval); ?>">
                                <div class="suite-barrio-key-group">
                                    <label>Chiave API <?php echo esc_html($pval === 'anthropic' ? 'Anthropic' : 'Google AI Studio'); ?></label>
                                    <input type="password"
                                        name="suite_barrio_wizard_ai_key_<?php echo esc_attr($pval === 'anthropic' ? 'anthropic' : 'gemini'); ?>"
                                        value="<?php echo esc_attr($key_val); ?>"
                                        placeholder="<?php echo esc_attr($pcfg['key_ph']); ?>"
                                        autocomplete="off">
                                </div>
                                <div class="suite-barrio-key-group">
                                    <label>Modello</label>
                                    <div class="suite-barrio-model-group">
                                    <?php foreach ($pcfg['models'] as $mval => $mdata):
                                        $m_checked = ($active_mdl === $mval); ?>
                                        <label class="suite-barrio-model-label <?php echo $m_checked ? 'selected' : ''; ?>">
                                            <input type="radio" name="suite_barrio_wizard_ai_model"
                                                value="<?php echo esc_attr($mval); ?>"
                                                <?php checked($active_mdl, $mval); ?>
                                                <?php echo !$is_sel ? 'disabled' : ''; ?>>
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

                    <!-- ── Lingua ──────────────────────────────────────── -->
                    <div class="suite-barrio-section-box" style="margin-bottom:16px;">
                        <div class="suite-barrio-section-head">
                            <span class="dashicons dashicons-translation"></span> Lingua output
                        </div>
                        <div class="suite-barrio-section-body">
                            <label class="suite-barrio-field-label">Lingua dei testi generati</label>
                            <select name="suite_barrio_wizard_ai_language" class="suite-barrio-select">
                                <option value="it" <?php selected($language, 'it'); ?>>🇮🇹 Italiano</option>
                                <option value="en" <?php selected($language, 'en'); ?>>🇬🇧 English</option>
                                <option value="es" <?php selected($language, 'es'); ?>>🇪🇸 Español</option>
                                <option value="fr" <?php selected($language, 'fr'); ?>>🇫🇷 Français</option>
                                <option value="de" <?php selected($language, 'de'); ?>>🇩🇪 Deutsch</option>
                            </select>
                        </div>
                    </div>

                    <!-- ── Impostazioni prompt ─────────────────────────── -->
                    <div class="suite-barrio-section-box" style="margin-bottom:20px;">
                        <div class="suite-barrio-section-head">
                            <span class="dashicons dashicons-edit-page"></span> Impostazioni prompt
                        </div>
                        <div class="suite-barrio-section-body">

                            <div style="margin-bottom:16px;">
                                <label class="suite-barrio-field-label" for="suite_barrio_wiz_tone">Registro linguistico</label>
                                <select name="suite_barrio_wizard_ai_tone" id="suite_barrio_wiz_tone" class="suite-barrio-select">
                                    <option value="neutral"      <?php selected($tone, 'neutral'); ?>>Neutro e oggettivo</option>
                                    <option value="professional" <?php selected($tone, 'professional'); ?>>Professionale e formale</option>
                                    <option value="friendly"     <?php selected($tone, 'friendly'); ?>>Amichevole e accessibile</option>
                                    <option value="technical"    <?php selected($tone, 'technical'); ?>>Tecnico e preciso</option>
                                    <option value="creative"     <?php selected($tone, 'creative'); ?>>Creativo e coinvolgente</option>
                                    <option value="seo"          <?php selected($tone, 'seo'); ?>>Ottimizzato SEO</option>
                                </select>
                                <p class="suite-barrio-field-desc">Tono dei testi generati dall'AI.</p>
                            </div>

                            <div style="margin-bottom:16px;">
                                <label class="suite-barrio-field-label" for="suite_barrio_wiz_style">Stile descrittivo</label>
                                <select name="suite_barrio_wizard_ai_style" id="suite_barrio_wiz_style" class="suite-barrio-select">
                                    <option value="descriptive"  <?php selected($style, 'descriptive'); ?>>Descrittivo — elenca gli elementi visivi</option>
                                    <option value="contextual"   <?php selected($style, 'contextual'); ?>>Contestuale — interpreta significato e scopo</option>
                                    <option value="emotional"    <?php selected($style, 'emotional'); ?>>Evocativo — trasmette sensazioni e atmosfera</option>
                                    <option value="journalistic" <?php selected($style, 'journalistic'); ?>>Giornalistico — sintetico come una didascalia</option>
                                </select>
                                <p class="suite-barrio-field-desc">Approccio con cui l'AI descrive i contenuti.</p>
                            </div>

                            <div style="margin-bottom:16px;">
                                <label class="suite-barrio-field-label" for="suite_barrio_wiz_context">Contesto del sito</label>
                                <input type="text" name="suite_barrio_wizard_ai_context" id="suite_barrio_wiz_context"
                                    class="suite-barrio-input" style="width:100%;max-width:460px;"
                                    value="<?php echo esc_attr($context); ?>"
                                    placeholder="es. e-commerce di moda sostenibile, blog di cucina italiana…">
                                <p class="suite-barrio-field-desc">Fornisce contesto all'AI per generare testi più pertinenti al tuo settore.</p>
                            </div>

                            <div>
                                <label class="suite-barrio-field-label" for="suite_barrio_wiz_keywords">Parole chiave SEO</label>
                                <input type="text" name="suite_barrio_wizard_ai_keywords" id="suite_barrio_wiz_keywords"
                                    class="suite-barrio-input" style="width:100%;max-width:460px;"
                                    value="<?php echo esc_attr($keywords); ?>"
                                    placeholder="es. scarpe artigianali, pelle italiana, handmade…">
                                <p class="suite-barrio-field-desc">Parole chiave da includere nei testi se pertinenti. Separate da virgola.</p>
                            </div>

                        </div>
                    </div>

                    <div class="suite-barrio-wizard-actions">
                        <button type="submit" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-wizard-submit">
                            Continua
                            <span class="dashicons dashicons-arrow-right-alt2" style="font-size:15px;width:15px;height:15px;"></span>
                        </button>
                    </div>
                </form>
                </div>

                <div class="suite-barrio-wizard-card-footer">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('suite_barrio_wizard_skip'); ?>
                        <input type="hidden" name="action" value="suite_barrio_wizard_skip">
                        <input type="hidden" name="step" value="6">
                        <button type="submit" class="suite-barrio-wizard-skip-btn">Salta passaggio</button>
                    </form>
                </div>

            </div>

        <?php
        wp_add_inline_script('suite-barrio-wizard-step6', '        (function () {
            var providerName = \'suite_barrio_wizard_ai_provider\';
            var modelName    = \'suite_barrio_wizard_ai_model\';

            function updateModelHighlight() {
                document.querySelectorAll(\'.suite-barrio-model-label\').forEach(function (lbl) {
                    var r = lbl.querySelector(\'input[type=radio]\');
                    lbl.classList.toggle(\'selected\', !!(r && r.checked && !r.disabled));
                });
            }

            document.querySelectorAll(\'input[name="\' + providerName + \'"]\').forEach(function (radio) {
                radio.addEventListener(\'change\', function () {
                    var sel = this.value;
                    // Aggiorna bordo card provider
                    document.querySelectorAll(\'.suite-barrio-provider-card\').forEach(function (c) {
                        c.classList.toggle(\'selected\', c.dataset.provider === sel);
                    });
                    // Mostra/nascondi sezione chiavi+modelli
                    document.querySelectorAll(\'[id^="suite-barrio-wiz-keys-"]\').forEach(function (panel) {
                        var prov  = panel.id.replace(\'suite-barrio-wiz-keys-\', \'\');
                        var active = (prov === sel);
                        panel.classList.toggle(\'visible\', active);
                        panel.querySelectorAll(\'input[type=radio][name="\' + modelName + \'"]\').forEach(function (r) {
                            r.disabled = !active;
                        });
                    });
                    updateModelHighlight();
                });
            });

            updateModelHighlight();
        })();'
        );
        ?>

        <?php elseif ($current === 7):

            // Determina substep corrente: URL param se valido, altrimenti primo incompleto.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
            $url_sub     = absint(wp_unslash($_GET['substep'] ?? 0));
            $cur_sub     = suite_barrio_wizard_seo_current_substep();
            if ($url_sub >= 1 && $url_sub <= 4) $cur_sub = $url_sub;

            $seo_steps   = suite_barrio_wizard_seo_substeps();
            $seo_progress = suite_barrio_wizard_get_progress();

            // Legge info azienda per pre-compilare SEO Globale e Schema.
            $company = function_exists('suite_barrio_company_info_get') ? suite_barrio_company_info_get() : [];

            // Legge impostazioni esistenti dei sotto-strumenti.
            $meta_s   = get_option('suite_barrio_meta_settings',   []);  if (!is_array($meta_s))   $meta_s   = [];
            $sitemap_s = get_option('suite_barrio_sitemap_settings', []); if (!is_array($sitemap_s)) $sitemap_s = [];
            $schema_s  = get_option('suite_barrio_schema_settings',  []); if (!is_array($schema_s))  $schema_s  = [];
        ?>

            <!-- Card step 6 — SEO -->
            <div class="suite-barrio-wizard-card">

                <div class="suite-barrio-wizard-card-head">
                    <div class="suite-barrio-wizard-card-head-icon" style="background:#0369a1;">
                        <span class="dashicons dashicons-search" style="color:#fff;"></span>
                    </div>
                    <div>
                        <h2 style="margin:0 0 4px;font-size:18px;">SEO</h2>
                        <p style="margin:0;color:#6b7280;font-size:14px;">Configura i dati base per il posizionamento del sito.</p>
                    </div>
                </div>

                <!-- Navigazione sotto-step -->
                <div class="suite-barrio-wizard-substeps">
                    <?php foreach ($seo_steps as $n => $sdata):
                        $st = $seo_progress["seo_sub_{$n}"] ?? 'pending';
                        $cls = 'suite-barrio-wizard-substep';
                        if ($n === $cur_sub) $cls .= ' is-active';
                        elseif ($st === 'done')    $cls .= ' is-done';
                        elseif ($st === 'skipped') $cls .= ' is-skipped';

                        // Accessibile se tutti i sotto-step precedenti sono done/skipped
                        $sub_accessible = true;
                        foreach ($seo_steps as $prev_n => $_) {
                            if ($prev_n >= $n) break;
                            if (($seo_progress["seo_sub_{$prev_n}"] ?? 'pending') === 'pending') {
                                $sub_accessible = false;
                                break;
                            }
                        }
                        $sub_url = esc_url(admin_url("admin.php?page=suite-barrio-wizard&step=7&substep={$n}"));
                        $is_link = $sub_accessible && ($n !== $cur_sub);
                    ?>
                        <div class="<?php echo esc_attr($cls); ?>">
                            <?php if ($is_link): ?>
                                <a href="<?php echo esc_url($sub_url); ?>" class="suite-barrio-wizard-substep-inner">
                            <?php else: ?>
                                <span class="suite-barrio-wizard-substep-inner">
                            <?php endif; ?>
                                <span class="suite-barrio-wizard-substep-num"><?php echo esc_html($n); ?></span>
                                <span class="suite-barrio-wizard-substep-label"><?php echo esc_html($sdata['label']); ?></span>
                            <?php if ($is_link): ?>
                                </a>
                            <?php else: ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="suite-barrio-wizard-form">

                    <!-- ═══════ SUB-STEP 1: SEO Globale ═══════ -->
                    <?php if ($cur_sub === 1): ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('suite_barrio_wizard_step7_sub1_save'); ?>
                        <input type="hidden" name="action" value="suite_barrio_wizard_step7_sub1_save">
                        <div class="suite-barrio-wizard-fields">

                            <div class="suite-barrio-wizard-field suite-barrio-wizard-field-full">
                                <label>Titolo del sito
                                    <span>Usato nel tag &lt;title&gt; come nome sito</span>
                                </label>
                                <input type="text" name="suite_barrio_meta_site_title"
                                    value="<?php echo esc_attr($meta_s['site_title'] ?? $company['ragione_sociale'] ?? get_bloginfo('name')); ?>"
                                    placeholder="Nome del sito">
                            </div>

                            <div class="suite-barrio-wizard-field">
                                <label>Separatore nel titolo</label>
                                <select name="suite_barrio_meta_separator" class="suite-barrio-select">
                                    <?php foreach (['—', '|', '·', '›', '»', '-'] as $sep): ?>
                                        <option value="<?php echo esc_attr($sep); ?>" <?php selected($meta_s['separator'] ?? '—', $sep); ?>><?php echo esc_html($sep); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="suite-barrio-wizard-field">
                                <label>Ordine nel tag title</label>
                                <select name="suite_barrio_meta_title_order" class="suite-barrio-select">
                                    <option value="page_site" <?php selected($meta_s['title_order'] ?? 'page_site', 'page_site'); ?>>Pagina — Sito</option>
                                    <option value="site_page" <?php selected($meta_s['title_order'] ?? 'page_site', 'site_page'); ?>>Sito — Pagina</option>
                                </select>
                            </div>

                            <div class="suite-barrio-wizard-field suite-barrio-wizard-field-full">
                                <label>Meta description predefinita
                                    <span>Usata quando la pagina non ha una description personalizzata</span>
                                </label>
                                <textarea name="suite_barrio_meta_default_desc" rows="3"
                                    class="suite-barrio-wizard-textarea"
                                    placeholder="Breve descrizione del sito (max 160 caratteri)…"><?php echo esc_textarea($meta_s['default_desc'] ?? ''); ?></textarea>
                            </div>

                        </div>
                        <div class="suite-barrio-wizard-actions">
                            <button type="submit" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-wizard-submit">
                                Continua <span class="dashicons dashicons-arrow-right-alt2" style="font-size:15px;width:15px;height:15px;"></span>
                            </button>
                        </div>
                    </form>

                    <!-- ═══════ SUB-STEP 2: Sitemap ═══════ -->
                    <?php elseif ($cur_sub === 2):
                        $pt_objects  = get_post_types(['public' => true], 'objects');
                        unset($pt_objects['attachment']);
                        $tax_objects = array_filter(
                            get_taxonomies(['public' => true], 'objects'),
                            fn($t) => !in_array($t->name, ['post_format', 'nav_menu', 'link_category'], true)
                        );
                    ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('suite_barrio_wizard_step7_sub2_save'); ?>
                        <input type="hidden" name="action" value="suite_barrio_wizard_step7_sub2_save">

                        <?php if (!function_exists('suite_barrio_tools_module_enabled') || !suite_barrio_tools_module_enabled('seo_sitemap')): ?>
                            <div class="suite-barrio-wizard-gtm-check suite-barrio-wizard-gtm-warn" style="margin-bottom:16px;">
                                <span class="dashicons dashicons-info-outline"></span>
                                <div>
                                    <strong>Modulo Sitemap non attivo</strong>
                                    <span>Abilita il modulo "Sitemap" nello step Strumenti per generare la sitemap XML.</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="suite-barrio-wizard-fields">
                            <?php if (!empty($pt_objects)): ?>
                            <div class="suite-barrio-wizard-field suite-barrio-wizard-field-full">
                                <label>Tipi di contenuto in sitemap</label>
                                <div class="suite-barrio-wizard-toggle-list">
                                <?php foreach ($pt_objects as $pt_obj):
                                    $included = $sitemap_s['post_types'][$pt_obj->name] ?? 1;
                                    $cid      = 'wiz_pt_' . esc_attr($pt_obj->name);
                                ?>
                                    <label class="suite-barrio-wizard-toggle-row" for="<?php echo esc_attr($cid); ?>">
                                        <span class="suite-barrio-wizard-toggle-info">
                                            <strong><?php echo esc_html($pt_obj->label); ?></strong>
                                            <code><?php echo esc_html($pt_obj->name); ?></code>
                                        </span>
                                        <span class="suite-barrio-wizard-toggle-wrap">
                                            <input type="checkbox" id="<?php echo esc_attr($cid); ?>" name="pt[<?php echo esc_attr($pt_obj->name); ?>]" value="1" <?php checked((bool)$included); ?>>
                                            <span class="suite-barrio-wizard-toggle-track"></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($tax_objects)): ?>
                            <div class="suite-barrio-wizard-field suite-barrio-wizard-field-full">
                                <label>Tassonomie in sitemap</label>
                                <div class="suite-barrio-wizard-toggle-list">
                                <?php foreach ($tax_objects as $tax_obj):
                                    $included = $sitemap_s['taxonomies'][$tax_obj->name] ?? 1;
                                    $cid      = 'wiz_tax_' . esc_attr($tax_obj->name);
                                ?>
                                    <label class="suite-barrio-wizard-toggle-row" for="<?php echo esc_attr($cid); ?>">
                                        <span class="suite-barrio-wizard-toggle-info">
                                            <strong><?php echo esc_html($tax_obj->label); ?></strong>
                                            <code><?php echo esc_html($tax_obj->name); ?></code>
                                        </span>
                                        <span class="suite-barrio-wizard-toggle-wrap">
                                            <input type="checkbox" id="<?php echo esc_attr($cid); ?>" name="tax[<?php echo esc_attr($tax_obj->name); ?>]" value="1" <?php checked((bool)$included); ?>>
                                            <span class="suite-barrio-wizard-toggle-track"></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="suite-barrio-wizard-actions">
                            <button type="submit" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-wizard-submit">
                                Continua <span class="dashicons dashicons-arrow-right-alt2" style="font-size:15px;width:15px;height:15px;"></span>
                            </button>
                        </div>
                    </form>

                    <!-- ═══════ SUB-STEP 3: Schema ═══════ -->
                    <?php elseif ($cur_sub === 3):
                        // Pre-fill da schema esistente, poi da info azienda.
                        $sc = fn(string $k, string $fallback = '') =>
                            !empty($schema_s[$k]) ? $schema_s[$k] : ($company[$fallback] ?? '');
                        $business_types = [
                            'LocalBusiness' => 'LocalBusiness', 'Organization' => 'Organization',
                            'Restaurant' => 'Restaurant', 'Hotel' => 'Hotel', 'Store' => 'Store',
                            'MedicalBusiness' => 'MedicalBusiness', 'LegalService' => 'LegalService',
                            'FinancialService' => 'FinancialService', 'InteriorDesigner' => 'InteriorDesigner',
                            'Architect' => 'Architect', 'Attorney' => 'Attorney',
                            'Dentist' => 'Dentist', 'Physician' => 'Physician',
                            'RealEstateAgent' => 'RealEstateAgent',
                        ];
                    ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('suite_barrio_wizard_step7_sub3_save'); ?>
                        <input type="hidden" name="action" value="suite_barrio_wizard_step7_sub3_save">
                        <div class="suite-barrio-wizard-fields">

                            <div class="suite-barrio-wizard-field">
                                <label>Tipo di attività</label>
                                <select name="business_type" class="suite-barrio-select">
                                    <?php foreach ($business_types as $val => $lbl): ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php selected($schema_s['business_type'] ?? 'LocalBusiness', $val); ?>><?php echo esc_html($lbl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="suite-barrio-wizard-field">
                                <label>Lingua</label>
                                <select name="language" class="suite-barrio-select">
                                    <option value="it-IT" <?php selected($schema_s['language'] ?? 'it-IT', 'it-IT'); ?>>🇮🇹 it-IT</option>
                                    <option value="en-US" <?php selected($schema_s['language'] ?? 'it-IT', 'en-US'); ?>>🇬🇧 en-US</option>
                                    <option value="es-ES" <?php selected($schema_s['language'] ?? 'it-IT', 'es-ES'); ?>>🇪🇸 es-ES</option>
                                    <option value="fr-FR" <?php selected($schema_s['language'] ?? 'it-IT', 'fr-FR'); ?>>🇫🇷 fr-FR</option>
                                    <option value="de-DE" <?php selected($schema_s['language'] ?? 'it-IT', 'de-DE'); ?>>🇩🇪 de-DE</option>
                                </select>
                            </div>

                            <div class="suite-barrio-wizard-field suite-barrio-wizard-field-full">
                                <label>Nome attività</label>
                                <input type="text" name="business_name"
                                    value="<?php echo esc_attr($sc('business_name', 'ragione_sociale')); ?>"
                                    placeholder="Ragione sociale o nome attività">
                            </div>

                            <div class="suite-barrio-wizard-field suite-barrio-wizard-field-full">
                                <label>Descrizione <span>Breve descrizione dell'attività per i dati strutturati</span></label>
                                <textarea name="description" rows="3"
                                    class="suite-barrio-wizard-textarea"
                                    placeholder="Descrizione breve dell'attività…"><?php echo esc_textarea($schema_s['description'] ?? ''); ?></textarea>
                            </div>

                            <div class="suite-barrio-wizard-field">
                                <label>URL sito web</label>
                                <input type="url" name="url"
                                    value="<?php echo esc_attr($sc('url', 'sito_web')); ?>"
                                    placeholder="https://esempio.com">
                            </div>

                            <div class="suite-barrio-wizard-field">
                                <label>Telefono</label>
                                <input type="text" name="telephone"
                                    value="<?php echo esc_attr($sc('telephone', 'telefono')); ?>"
                                    placeholder="+39 02 0000000">
                            </div>

                            <div class="suite-barrio-wizard-field">
                                <label>Email</label>
                                <input type="email" name="email"
                                    value="<?php echo esc_attr($sc('email', 'email')); ?>"
                                    placeholder="info@esempio.com">
                            </div>

                            <div class="suite-barrio-wizard-field">
                                <label>Partita IVA</label>
                                <input type="text" name="vat_id"
                                    value="<?php echo esc_attr($sc('vat_id', 'partita_iva')); ?>"
                                    placeholder="IT00000000000">
                            </div>

                            <div class="suite-barrio-wizard-field suite-barrio-wizard-field-full">
                                <label>Indirizzo</label>
                                <input type="text" name="street_address"
                                    value="<?php echo esc_attr($sc('street_address', 'sede_via')); ?>"
                                    placeholder="Via Roma 1">
                            </div>

                            <div class="suite-barrio-wizard-field">
                                <label>CAP</label>
                                <input type="text" name="postal_code"
                                    value="<?php echo esc_attr($sc('postal_code', 'sede_cap')); ?>"
                                    placeholder="00100">
                            </div>

                            <div class="suite-barrio-wizard-field">
                                <label>Città</label>
                                <input type="text" name="locality"
                                    value="<?php echo esc_attr($sc('locality', 'sede_citta')); ?>"
                                    placeholder="Roma">
                            </div>

                            <div class="suite-barrio-wizard-field">
                                <label>Provincia</label>
                                <input type="text" name="region"
                                    value="<?php echo esc_attr($sc('region', 'sede_provincia')); ?>"
                                    placeholder="RM">
                            </div>

                            <div class="suite-barrio-wizard-field">
                                <label>Paese</label>
                                <select name="country" class="suite-barrio-select">
                                    <option value="IT" <?php selected($schema_s['country'] ?? 'IT', 'IT'); ?>>🇮🇹 Italia</option>
                                    <option value="US" <?php selected($schema_s['country'] ?? 'IT', 'US'); ?>>🇺🇸 USA</option>
                                    <option value="GB" <?php selected($schema_s['country'] ?? 'IT', 'GB'); ?>>🇬🇧 UK</option>
                                    <option value="DE" <?php selected($schema_s['country'] ?? 'IT', 'DE'); ?>>🇩🇪 Germania</option>
                                    <option value="FR" <?php selected($schema_s['country'] ?? 'IT', 'FR'); ?>>🇫🇷 Francia</option>
                                    <option value="ES" <?php selected($schema_s['country'] ?? 'IT', 'ES'); ?>>🇪🇸 Spagna</option>
                                    <option value="CH" <?php selected($schema_s['country'] ?? 'IT', 'CH'); ?>>🇨🇭 Svizzera</option>
                                </select>
                            </div>

                            <div class="suite-barrio-wizard-field suite-barrio-wizard-field-full">
                                <label>Social</label>
                                <div style="display:flex;flex-direction:column;gap:8px;">
                                    <?php foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'twitter' => 'Twitter / X', 'linkedin' => 'LinkedIn'] as $skey => $slabel): ?>
                                        <input type="url" name="<?php echo esc_attr($skey); ?>"
                                            value="<?php echo esc_attr($sc($skey, $skey)); ?>"
                                            placeholder="<?php echo esc_attr($slabel); ?> URL">
                                    <?php endforeach; ?>
                                </div>
                            </div>

                        </div>
                        <div class="suite-barrio-wizard-actions">
                            <button type="submit" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-wizard-submit">
                                Continua <span class="dashicons dashicons-arrow-right-alt2" style="font-size:15px;width:15px;height:15px;"></span>
                            </button>
                        </div>
                    </form>

                    <!-- ═══════ SUB-STEP 4: Redirects ═══════ -->
                    <?php elseif ($cur_sub === 4):
                        $existing_redirects = get_option('suite_barrio_redirects', []);
                        if (!is_array($existing_redirects)) $existing_redirects = [];
                    ?>

                    <?php if (!empty($existing_redirects)): ?>
                        <div class="suite-barrio-wizard-gtm-check suite-barrio-wizard-gtm-warn" style="margin-bottom:16px;">
                            <span class="dashicons dashicons-info-outline"></span>
                            <div>
                                <strong><?php echo count($existing_redirects); ?> redirect già configurati</strong>
                                <span>I redirect aggiunti qui si sommeranno a quelli esistenti nello strumento SEO → Redirects.</span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="suite-barrio-redirects-form">
                        <?php wp_nonce_field('suite_barrio_wizard_step7_sub4_save'); ?>
                        <input type="hidden" name="action" value="suite_barrio_wizard_step7_sub4_save">

                        <div class="suite-barrio-redirects-builder" id="suite-barrio-redirects-builder">

                            <!-- Riga template (nascosta, clonata via JS) -->
                            <template id="suite-barrio-redirect-row-tpl">
                                <div class="suite-barrio-redirect-row">
                                    <div class="suite-barrio-redirect-row-fields">
                                        <div class="suite-barrio-redirect-cell suite-barrio-redirect-cell-from suite-barrio-wizard-field">
                                            <label>Da</label>
                                            <input type="text" name="redirects[__i__][from]" placeholder="/vecchia-pagina" autocomplete="off">
                                        </div>
                                        <div class="suite-barrio-redirect-cell suite-barrio-redirect-cell-to suite-barrio-wizard-field">
                                            <label>A</label>
                                            <input type="text" name="redirects[__i__][to]" placeholder="/nuova-pagina" autocomplete="off">
                                        </div>
                                        <div class="suite-barrio-redirect-cell suite-barrio-redirect-cell-code suite-barrio-wizard-field">
                                            <label>Tipo</label>
                                            <select name="redirects[__i__][code]" class="suite-barrio-select">
                                                <option value="301">301 — Permanente</option>
                                                <option value="302">302 — Temporaneo</option>
                                                <option value="307">307 — Temporaneo (metodo)</option>
                                                <option value="410">410 — Rimosso</option>
                                            </select>
                                        </div>
                                        <div class="suite-barrio-redirect-cell suite-barrio-redirect-cell-note suite-barrio-wizard-field">
                                            <label>Nota <span>opzionale</span></label>
                                            <input type="text" name="redirects[__i__][note]" placeholder="es. vecchio slug prodotto…" autocomplete="off">
                                        </div>
                                    </div>
                                    <button type="button" class="suite-barrio-redirect-remove" title="Rimuovi">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </div>
                            </template>

                            <!-- Prima riga vuota -->
                            <div class="suite-barrio-redirect-row">
                                <div class="suite-barrio-redirect-row-fields">
                                    <div class="suite-barrio-redirect-cell suite-barrio-redirect-cell-from suite-barrio-wizard-field">
                                        <label>Da</label>
                                        <input type="text" name="redirects[0][from]" placeholder="/vecchia-pagina" autocomplete="off">
                                    </div>
                                    <div class="suite-barrio-redirect-cell suite-barrio-redirect-cell-to suite-barrio-wizard-field">
                                        <label>A</label>
                                        <input type="text" name="redirects[0][to]" placeholder="/nuova-pagina" autocomplete="off">
                                    </div>
                                    <div class="suite-barrio-redirect-cell suite-barrio-redirect-cell-code suite-barrio-wizard-field">
                                        <label>Tipo</label>
                                        <select name="redirects[0][code]" class="suite-barrio-select">
                                            <option value="301">301 — Permanente</option>
                                            <option value="302">302 — Temporaneo</option>
                                            <option value="307">307 — Temporaneo (metodo)</option>
                                            <option value="410">410 — Rimosso</option>
                                        </select>
                                    </div>
                                    <div class="suite-barrio-redirect-cell suite-barrio-redirect-cell-note suite-barrio-wizard-field">
                                        <label>Nota <span>opzionale</span></label>
                                        <input type="text" name="redirects[0][note]" placeholder="es. vecchio slug prodotto…" autocomplete="off">
                                    </div>
                                </div>
                                <button type="button" class="suite-barrio-redirect-remove" title="Rimuovi">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </div>

                        </div><!-- .suite-barrio-redirects-builder -->

                        <button type="button" id="suite-barrio-redirect-add" class="suite-barrio-btn suite-barrio-btn-ghost suite-barrio-btn-small" style="margin-top:10px;">
                            <span class="dashicons dashicons-plus-alt2" style="font-size:14px;width:14px;height:14px;margin-top:1px;"></span>
                            Aggiungi redirect
                        </button>

                        <div class="suite-barrio-wizard-actions" style="margin-top:24px;">
                            <button type="submit" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-wizard-submit">
                                Continua <span class="dashicons dashicons-arrow-right-alt2" style="font-size:15px;width:15px;height:15px;"></span>
                            </button>
                        </div>
                    </form>

                    <?php
                    wp_add_inline_script('suite-barrio-wizard-step7-redirects', '                    (function () {
                        var builder  = document.getElementById(\'suite-barrio-redirects-builder\');
                        var addBtn   = document.getElementById(\'suite-barrio-redirect-add\');
                        var tpl      = document.getElementById(\'suite-barrio-redirect-row-tpl\');
                        var rowIndex = 1;

                        function attachRemove(row) {
                            var btn = row.querySelector(\'.suite-barrio-redirect-remove\');
                            if (!btn) return;
                            btn.addEventListener(\'click\', function () {
                                if (builder.querySelectorAll(\'.suite-barrio-redirect-row\').length > 1) {
                                    row.remove();
                                } else {
                                    row.querySelectorAll(\'input\').forEach(function (i) { i.value = \'\'; });
                                }
                            });
                        }

                        // Attach su prima riga
                        builder.querySelectorAll(\'.suite-barrio-redirect-row\').forEach(attachRemove);

                        addBtn.addEventListener(\'click\', function () {
                            var clone = tpl.content.cloneNode(true);
                            var row   = clone.querySelector(\'.suite-barrio-redirect-row\');
                            // Sostituisce l\'indice placeholder
                            row.innerHTML = row.innerHTML.replace(/__i__/g, rowIndex++);
                            attachRemove(row);
                            builder.appendChild(row);
                            // Focus sul primo input della nuova riga
                            var first = builder.querySelector(\'.suite-barrio-redirect-row:last-child input\');
                            if (first) first.focus();
                        });
                    })();'
                    );
                    ?>

                    <?php endif; ?>

                </div><!-- .suite-barrio-wizard-form -->

                <!-- Footer con skip del singolo sotto-step -->
                <div class="suite-barrio-wizard-card-footer">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('suite_barrio_wizard_seo_substep_skip'); ?>
                        <input type="hidden" name="action" value="suite_barrio_wizard_seo_substep_skip">
                        <input type="hidden" name="substep" value="<?php echo esc_attr($cur_sub); ?>">
                        <button type="submit" class="suite-barrio-wizard-skip-btn">Salta passaggio</button>
                    </form>
                </div>

            </div><!-- .suite-barrio-wizard-card -->

        <?php elseif ($current === 8):

            $checklist = [
                'gtm_analytics' => [
                    'label' => 'Inserire analytics in Google Tag Manager',
                    'icon'  => 'dashicons-chart-area',
                ],
                'search_console' => [
                    'label' => 'Inserire url sitemap in Search Console',
                    'icon'  => 'dashicons-admin-site-alt3',
                ],
                'mailjet_recaptcha' => [
                    'label' => 'Inserire Recaptcha',
                    'icon'  => 'dashicons-email-alt',
                ],
                'email_forms' => [
                    'label' => 'Graficare email inviate dai form',
                    'icon'  => 'dashicons-email',
                ],
                'orari_gmaps' => [
                    'label' => 'Inserire orari tramite Google Place API',
                    'icon'  => 'dashicons-location-alt',
                ],
                'popup_banner' => [
                    'label' => 'Graficare Popup e Banner',
                    'icon'  => 'dashicons-megaphone',
                ],
                'auto_alt' => [
                    'label' => 'Generare alt e title delle immagini',
                    'icon'  => 'dashicons-format-image',
                ],
                'cookie_policy' => [
                    'label' => 'Genera Cookie e Privacy policy con AI',
                    'icon'  => 'dashicons-shield',
                ],
                'report_ai' => [
                    'label' => 'Configura sito per report AI',
                    'icon'  => 'dashicons-media-document',
                ],
                'accessibility' => [
                    'label' => 'Attiva accessibilità',
                    'icon'  => 'dashicons-universal-access',
                ],
            ];

            $p7         = suite_barrio_wizard_get_progress();
            $saved_chks = $p7['step_8_checks'] ?? [];
            if (!is_array($saved_chks)) $saved_chks = [];
        ?>

            <!-- Card step 7 — Controlli Finali -->
            <div class="suite-barrio-wizard-card">

                <div class="suite-barrio-wizard-card-head">
                    <div class="suite-barrio-wizard-card-head-icon" style="background:#111827;">
                        <span class="dashicons dashicons-yes-alt" style="color:#fff;"></span>
                    </div>
                    <div>
                        <div class="suite-barrio-wizard-card-title">Controlli Finali</div>
                        <div class="suite-barrio-wizard-card-subtitle">Verifica di aver completato tutte le operazioni prima di concludere la messa online</div>
                    </div>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="suite-barrio-wizard-form">
                    <?php wp_nonce_field('suite_barrio_wizard_step8_save'); ?>
                    <input type="hidden" name="action" value="suite_barrio_wizard_step8_save">

                    <div class="suite-barrio-wizard-checklist">
                        <?php foreach ($checklist as $key => $item):
                            $is_checked = in_array($key, $saved_chks, true);
                        ?>
                            <label class="suite-barrio-wizard-check-item" for="suite_barrio_chk_<?php echo esc_attr($key); ?>">
                                <input type="checkbox" id="suite_barrio_chk_<?php echo esc_attr($key); ?>"
                                    name="checks[<?php echo esc_attr($key); ?>]" value="1"
                                    <?php checked($is_checked); ?>>
                                <span class="suite-barrio-wizard-check-icon">
                                    <span class="dashicons <?php echo esc_attr($item['icon']); ?>"></span>
                                </span>
                                <span class="suite-barrio-wizard-check-label"><?php echo esc_html($item['label']); ?></span>
                                <span class="suite-barrio-wizard-check-done dashicons dashicons-yes"></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="suite-barrio-wizard-actions">
                        <button type="submit" class="suite-barrio-btn suite-barrio-btn-primary suite-barrio-wizard-submit">
                            Termina la messa online
                            <span class="dashicons dashicons-yes-alt" style="font-size:15px;width:15px;height:15px;"></span>
                        </button>
                    </div>
                </form>

            </div>

        <?php endif; ?>

    </div>
    <?php
}
