<?php
if (!defined('ABSPATH')) exit;

/**
 * Blocca lato server gli script di terze parti non consentiti.
 *
 * Intercetta l'output HTML prima che venga inviato al browser, converte
 * i <script> di tracciamento non consentiti in type="text/plain"
 * data-wpb-category="..." e li rimette in gioco quando il consent engine
 * riceve il consenso (activate() nel JS già gestisce questo pattern).
 *
 * GTM è escluso dal blocco perché è il veicolo del consent engine stesso.
 */
class WPB_Script_Blocker {

    // Script che non vanno mai bloccati (veicolo del consent engine)
    private const GTM_BYPASS = [
        'googletagmanager.com/gtm.js',
        'googletagmanager.com/gtag/js',
    ];

    public static function init(): void {
        // Solo frontend, non admin, non AJAX, non REST
        if (is_admin()) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;
        if (defined('REST_REQUEST') && REST_REQUEST) return;
        add_action('template_redirect', [self::class, 'start_buffer'], 0);
    }

    public static function start_buffer(): void {
        ob_start([self::class, 'filter_output']);
    }

    /**
     * Callback del buffer: riceve l'intero HTML della pagina,
     * blocca gli script non consentiti e lo restituisce modificato.
     */
    public static function filter_output(string $html): string {
        if (empty($html)) return $html;

        $consent = self::read_consent();

        // Se tutto è già consentito non modifichiamo nulla
        if (self::all_granted($consent)) return $html;

        $db = WPB_Scanner::get_provider_db();

        return preg_replace_callback(
            '#<script(\s[^>]*)?>(.*?)</script>#si',
            static function (array $m) use ($consent, $db): string {
                $attrs   = $m[1] ?? '';
                $content = $m[2];

                // Già bloccato da un passaggio precedente
                if (stripos($attrs, 'text/plain') !== false) return $m[0];

                // Estrai l'eventuale src
                $src = '';
                if (preg_match('#\bsrc=["\']([^"\']+)["\']#i', $attrs, $sm)) {
                    $src = $sm[1];
                }

                // GTM non va mai bloccato
                foreach (self::GTM_BYPASS as $bypass) {
                    if ($src && str_contains($src, $bypass)) return $m[0];
                }

                // Cerca il primo provider che matcha src o contenuto inline
                $category = null;
                foreach ($db as $pattern => $info) {
                    $in_src     = $src     && str_contains($src,     $pattern);
                    $in_content = !$src    && str_contains($content, $pattern);
                    if ($in_src || $in_content) {
                        $category = $info['category'];
                        break;
                    }
                }

                // Nessun provider riconosciuto o categoria necessaria → non toccare
                if (!$category || $category === 'necessary') return $m[0];

                // Consenso già dato per questa categoria → non toccare
                if (!empty($consent[$category])) return $m[0];

                // Blocca: rimuovi type esistente e imposta text/plain
                $safe_attrs = preg_replace('#\s+type=["\'][^"\']*["\']#i', '', $attrs);
                return '<script' . $safe_attrs
                    . ' type="text/plain" data-wpb-category="' . esc_attr($category) . '">'
                    . $content
                    . '</script>';
            },
            $html
        );
    }

    /**
     * Legge il cookie wpb_consent (server-side) e restituisce le preferenze.
     * Ritorna array vuoto se il cookie non esiste (nuovo visitatore = tutto negato).
     */
    public static function read_consent(): array {
        $raw = $_COOKIE['wpb_consent'] ?? '';
        if ($raw === '') return [];
        $c = json_decode(urldecode($raw), true);
        return is_array($c) ? $c : [];
    }

    private static function all_granted(array $consent): bool {
        return !empty($consent['functional'])
            && !empty($consent['analytics'])
            && !empty($consent['marketing']);
    }
}
