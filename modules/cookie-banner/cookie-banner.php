<?php
if (!defined('ABSPATH')) exit;

define('SUITE_BARRIO_CB_DIR', plugin_dir_path(__FILE__));
define('SUITE_BARRIO_CB_URL', plugin_dir_url(__FILE__));

require_once SUITE_BARRIO_CB_DIR . 'class-service-registry.php';
require_once SUITE_BARRIO_CB_DIR . 'class-scanner.php';
require_once SUITE_BARRIO_CB_DIR . 'class-script-blocker.php';
require_once SUITE_BARRIO_CB_DIR . 'class-cron.php';
require_once SUITE_BARRIO_CB_DIR . 'class-consent-log.php';
require_once SUITE_BARRIO_CB_DIR . 'rest-api.php';
require_once SUITE_BARRIO_CB_DIR . 'frontend.php';
require_once SUITE_BARRIO_CB_DIR . 'admin-ui.php';

SUITE_BARRIO_Script_Blocker::init();

/* ── Funzione standalone chiamata da cookie-privacy.php tramite function_exists ── */
function suite_barrio_cb_get_services_for_prompt(): string {
    return SUITE_BARRIO_Service_Registry::get_services_for_prompt();
}

/* ── Config condivisa tra frontend.php e rest-api.php ── */
function suite_barrio_cb_build_frontend_config(): array {
    $banner  = get_option('suite_barrio_cb_banner_config', []);
    $cats    = SUITE_BARRIO_Service_Registry::get_categories();

    return [
        'categories'     => array_values(array_map(fn($c) => [
            'id'           => $c['id'],
            'name'         => $c['name'],
            'description'  => $c['description'],
            'always_active' => $c['always_active'],
        ], $cats)),
        'banner'         => [
            'position'    => $banner['position']    ?? 'bottom',
            'color_bg'    => $banner['color_bg']    ?? '#0D1B2E',
            'color_text'  => $banner['color_text']  ?? '#FFFFFF',
            'color_accent' => $banner['color_accent'] ?? '#1A56DB',
            'text_title'  => $banner['text_title']  ?? 'Questo sito usa cookie',
            'text_body'   => $banner['text_body']   ?? 'Utilizziamo cookie per migliorare la tua esperienza. Puoi scegliere quali accettare.',
            'text_accept' => $banner['text_accept'] ?? 'Accetta tutti',
            'text_reject' => $banner['text_reject'] ?? 'Rifiuta tutti',
            'text_manage' => $banner['text_manage'] ?? 'Gestisci preferenze',
            'show_widget' => $banner['show_widget'] ?? true,
            'widget_pos'  => $banner['widget_pos']  ?? 'bottom-right',
        ],
        'preferencesUrl' => rest_url('suite-barrio/v1/cookie-banner/preferences-html'),
        // Provato dal frontend nella richiesta di consent-log: dimostra che il
        // chiamante ha prima caricato questa config dal sito, invece di essere
        // uno script/bot esterno che invia POST alla cieca sull'endpoint pubblico.
        'consentNonce'   => wp_create_nonce('suite_barrio_cb_consent_log'),
    ];
}

register_deactivation_hook(
    SUITE_BARRIO_PLUGIN_DIR . 'suite-barrio.php',
    function () {
        if (class_exists('SUITE_BARRIO_Cron')) SUITE_BARRIO_Cron::deactivate();
    }
);
