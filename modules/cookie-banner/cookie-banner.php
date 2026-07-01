<?php
if (!defined('ABSPATH')) exit;

define('WPB_CB_DIR', plugin_dir_path(__FILE__));
define('WPB_CB_URL', plugin_dir_url(__FILE__));

require_once WPB_CB_DIR . 'class-service-registry.php';
require_once WPB_CB_DIR . 'class-scanner.php';
require_once WPB_CB_DIR . 'class-script-blocker.php';
require_once WPB_CB_DIR . 'class-cron.php';
require_once WPB_CB_DIR . 'rest-api.php';
require_once WPB_CB_DIR . 'frontend.php';
require_once WPB_CB_DIR . 'admin-ui.php';

WPB_Script_Blocker::init();

/* ── Funzione standalone chiamata da cookie-privacy.php tramite function_exists ── */
function wpb_cb_get_services_for_prompt(): string {
    return WPB_Service_Registry::get_services_for_prompt();
}

/* ── Config condivisa tra frontend.php e rest-api.php ── */
function wpb_cb_build_frontend_config(): array {
    $banner  = get_option('wpb_cb_banner_config', []);
    $cats    = WPB_Service_Registry::get_categories();

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
        'preferencesUrl' => rest_url('wpb/v1/cookie-banner/preferences-html'),
    ];
}

register_deactivation_hook(
    WP_ALT_AI_PLUGIN_DIR . 'wp-barrio.php',
    function () {
        if (class_exists('WPB_Cron')) WPB_Cron::deactivate();
    }
);
