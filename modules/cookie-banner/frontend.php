<?php
if (!defined('ABSPATH')) exit;

/* ── Carica consent engine nel <head> con priorità massima ── */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script(
        'suite-barrio-consent-engine',
        SUITE_BARRIO_CB_URL . 'assets/consent-engine.js',
        [],
        SUITE_BARRIO_VERSION,
        false // in <head>, non in footer
    );
    wp_add_inline_script(
        'suite-barrio-consent-engine',
        'window.SuiteBarrioConfig = ' . wp_json_encode(suite_barrio_cb_build_frontend_config()) . ';',
        'before'
    );
}, 1); // Priorità 1: prima di qualsiasi altro enqueue

/* ── Blocca script non-necessari registrati nel Service Registry ── */
add_filter('script_loader_tag', function (string $tag, string $handle): string {
    static $blocked = null;
    if ($blocked === null) {
        $blocked = [];
        foreach (SUITE_BARRIO_Service_Registry::get_active_services() as $s) {
            if ($s['category'] !== 'necessary' && wp_scripts()->query($s['id'])) {
                $blocked[$s['id']] = $s['category'];
            }
        }
    }

    // Pass through: handle not in blocked list or is the consent engine itself
    if ($handle === 'suite-barrio-consent-engine' || !isset($blocked[$handle])) {
        return $tag;
    }

    $category = esc_attr($blocked[$handle]);

    // Rimuove type="text/javascript" esplicito se presente (verrà rimpiazzato)
    $tag = preg_replace('#(<script\b[^>]*)\btype=["\']text/javascript["\']#i', '$1', $tag);

    // Aggiunge type="text/plain" e data-suite-barrio-category per bloccare l'esecuzione
    $tag = str_replace('<script ', '<script type="text/plain" data-suite-barrio-category="' . $category . '" ', $tag);

    // Rimuove defer/async: script bloccati non vanno processati dal parser
    $tag = preg_replace('#\s+(?:defer|async)(?:=["\'][^"\']*["\'])?(?=[\s>])#i', '', $tag);

    return $tag;
}, 10, 2);
