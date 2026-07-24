<?php
if (!defined('ABSPATH')) exit;

class SUITE_BARRIO_Scanner {

    const RESULTS_OPTION     = 'suite_barrio_cb_scan_results';
    const PROGRESS_TRANSIENT = 'suite_barrio_cb_scan_progress';
    const MAX_PAGES          = 15;

    /* ── Provider database (pubblico per SUITE_BARRIO_Script_Blocker) ── */
    public static function get_provider_db(): array { return self::provider_db(); }

    /* ── Provider database (rilevamento diretto nella pagina) ── */
    private static function provider_db(): array {
        return [
            'googletagmanager.com'   => ['name' => 'Google Tag Manager',   'category' => 'analytics',  'provider' => 'Google LLC',       'privacy_url' => 'https://policies.google.com/privacy',                        'cookies' => []],
            'google-analytics.com'   => ['name' => 'Google Analytics 4',   'category' => 'analytics',  'provider' => 'Google LLC',       'privacy_url' => 'https://policies.google.com/privacy',                        'cookies' => ['_ga', '_ga_*']],
            'connect.facebook.net'   => ['name' => 'Meta Pixel',           'category' => 'marketing',  'provider' => 'Meta Platforms',   'privacy_url' => 'https://www.facebook.com/privacy/policy/',                   'cookies' => ['_fbp', '_fbc']],
            'fbevents.js'            => ['name' => 'Meta Pixel',           'category' => 'marketing',  'provider' => 'Meta Platforms',   'privacy_url' => 'https://www.facebook.com/privacy/policy/',                   'cookies' => ['_fbp', '_fbc']],
            "fbq('init"              => ['name' => 'Meta Pixel',           'category' => 'marketing',  'provider' => 'Meta Platforms',   'privacy_url' => 'https://www.facebook.com/privacy/policy/',                   'cookies' => ['_fbp', '_fbc']],
            'snap.licdn.com'         => ['name' => 'LinkedIn Insight Tag', 'category' => 'marketing',  'provider' => 'LinkedIn Corp.',   'privacy_url' => 'https://www.linkedin.com/legal/privacy-policy',              'cookies' => ['li_fat_id', 'li_sugr', 'bcookie']],
            'hotjar.com'             => ['name' => 'Hotjar',               'category' => 'analytics',  'provider' => 'Hotjar Ltd.',      'privacy_url' => 'https://www.hotjar.com/legal/policies/privacy/',             'cookies' => ['_hjSessionUser_*', '_hjSession_*', '_hjAbsoluteSessionInProgress', '_hjFirstSeen']],
            'player.vimeo.com'       => ['name' => 'Vimeo embed',          'category' => 'functional', 'provider' => 'Vimeo LLC',        'privacy_url' => 'https://vimeo.com/privacy',                                  'cookies' => ['vuid', 'player']],
            'youtube.com/embed'      => ['name' => 'YouTube embed',        'category' => 'functional', 'provider' => 'Google LLC',       'privacy_url' => 'https://policies.google.com/privacy',                        'cookies' => ['YSC', 'VISITOR_INFO1_LIVE', 'GPS']],
            'youtube-nocookie.com'   => ['name' => 'YouTube (no-cookie)',  'category' => 'functional', 'provider' => 'Google LLC',       'privacy_url' => 'https://policies.google.com/privacy',                        'cookies' => ['YSC', 'VISITOR_INFO1_LIVE']],
            'maps.google.com'        => ['name' => 'Google Maps',          'category' => 'functional', 'provider' => 'Google LLC',       'privacy_url' => 'https://policies.google.com/privacy',                        'cookies' => ['NID', '1P_JAR', 'CGIC']],
            'mc.yandex.ru'           => ['name' => 'Yandex Metrica',       'category' => 'analytics',  'provider' => 'Yandex LLC',       'privacy_url' => 'https://yandex.com/legal/privacy/',                          'cookies' => ['_ym_uid', '_ym_d', '_ym_isad']],
            'static.ads-twitter.com' => ['name' => 'Twitter/X Pixel',     'category' => 'marketing',  'provider' => 'Twitter/X Corp.',  'privacy_url' => 'https://twitter.com/privacy',                                'cookies' => ['personalization_id', 'muc']],
            'cdn.heapanalytics.com'  => ['name' => 'Heap Analytics',      'category' => 'analytics',  'provider' => 'Heap Inc.',        'privacy_url' => 'https://heap.io/privacy',                                    'cookies' => ['_hp2_id.*', '_hp2_ses_props.*']],
            'js.hs-scripts.com'      => ['name' => 'HubSpot',             'category' => 'marketing',  'provider' => 'HubSpot Inc.',     'privacy_url' => 'https://legal.hubspot.com/privacy-policy',                   'cookies' => ['hubspotutk', '__hstc', '__hssc', '__hssrc']],
            'tawk.to'                => ['name' => 'Tawk.to',              'category' => 'functional', 'provider' => 'Tawk.to Inc.',     'privacy_url' => 'https://www.tawk.to/privacy-policy/',                        'cookies' => ['TawkConnectionTime', 'twk_uuid_*']],
            'crisp.chat'             => ['name' => 'Crisp Chat',           'category' => 'functional', 'provider' => 'Crisp IM SAS',     'privacy_url' => 'https://crisp.chat/en/privacy/',                             'cookies' => ['crisp-client/session/*', '__cfruid']],
        ];
    }

    /* ── Pattern database per tag dentro container GTM ── */
    private static function gtm_tag_db(): array {
        return [
            // Ogni entry: 'patterns' è una lista di stringhe/regex cercate nel JS del container.
            // La prima che matcha identifica il servizio. I pattern regex hanno metacaratteri.
            'gtm:ga4' => [
                'patterns'    => ['__googtag', '__ga4', 'G-[A-Z0-9]{4,12}'],
                'name'        => 'Google Analytics 4',
                'category'    => 'analytics',
                'provider'    => 'Google LLC',
                'privacy_url' => 'https://policies.google.com/privacy',
                'cookies'     => ['_ga', '_ga_*'],
            ],
            'gtm:ga-ua' => [
                'patterns'    => ['__ua', 'UA-[0-9]+-[0-9]+'],
                'name'        => 'Google Analytics (Universal)',
                'category'    => 'analytics',
                'provider'    => 'Google LLC',
                'privacy_url' => 'https://policies.google.com/privacy',
                'cookies'     => ['_ga', '_gat', '_gid'],
            ],
            'gtm:google-ads' => [
                'patterns'    => ['__awct', '__conversionlinker', 'AW-[0-9]{7,12}'],
                'name'        => 'Google Ads',
                'category'    => 'marketing',
                'provider'    => 'Google LLC',
                'privacy_url' => 'https://policies.google.com/privacy',
                'cookies'     => ['_gcl_au', 'IDE', 'test_cookie'],
            ],
            'gtm:meta-pixel' => [
                'patterns'    => ['__fxg', '__sp', 'connect.facebook.net', 'fbq'],
                'name'        => 'Meta Pixel',
                'category'    => 'marketing',
                'provider'    => 'Meta Platforms',
                'privacy_url' => 'https://www.facebook.com/privacy/policy/',
                'cookies'     => ['_fbp', '_fbc'],
            ],
            'gtm:linkedin' => [
                'patterns'    => ['__lnkd', 'snap.licdn.com'],
                'name'        => 'LinkedIn Insight Tag',
                'category'    => 'marketing',
                'provider'    => 'LinkedIn Corp.',
                'privacy_url' => 'https://www.linkedin.com/legal/privacy-policy',
                'cookies'     => ['li_fat_id', 'li_sugr', 'bcookie'],
            ],
            'gtm:hotjar' => [
                'patterns'    => ['__hj', 'hotjar.com', 'hjid'],
                'name'        => 'Hotjar',
                'category'    => 'analytics',
                'provider'    => 'Hotjar Ltd.',
                'privacy_url' => 'https://www.hotjar.com/legal/policies/privacy/',
                'cookies'     => ['_hjSessionUser_*', '_hjSession_*', '_hjFirstSeen'],
            ],
            'gtm:twitter' => [
                'patterns'    => ['__tpxl', 'analytics.twitter.com', 'static.ads-twitter.com'],
                'name'        => 'Twitter/X Pixel',
                'category'    => 'marketing',
                'provider'    => 'Twitter/X Corp.',
                'privacy_url' => 'https://twitter.com/privacy',
                'cookies'     => ['personalization_id', 'muc'],
            ],
            'gtm:tiktok' => [
                'patterns'    => ['tiktok.com/i/'], // specifico per evitare falsi positivi su "tiktok"
                'name'        => 'TikTok Pixel',
                'category'    => 'marketing',
                'provider'    => 'TikTok (ByteDance)',
                'privacy_url' => 'https://www.tiktok.com/legal/page/global/privacy-policy',
                'cookies'     => ['_ttp', 'tt_webid_v2'],
            ],
            'gtm:pinterest' => [
                'patterns'    => ['__pintrk', 'pintrk', 'pinterest.com/v3'],
                'name'        => 'Pinterest Tag',
                'category'    => 'marketing',
                'provider'    => 'Pinterest Inc.',
                'privacy_url' => 'https://policy.pinterest.com/privacy-policy',
                'cookies'     => ['_pinterest_ct_ua', '_pin_unauth'],
            ],
            'gtm:microsoft-clarity' => [
                'patterns'    => ['clarity.ms', '"__clarity"'],
                'name'        => 'Microsoft Clarity',
                'category'    => 'analytics',
                'provider'    => 'Microsoft Corp.',
                'privacy_url' => 'https://privacy.microsoft.com/privacystatement',
                'cookies'     => ['_clsk', '_clckuid'],
            ],
            'gtm:microsoft-ads' => [
                'patterns'    => ['bat.bing.com', 'uetq'],
                'name'        => 'Microsoft Ads (UET)',
                'category'    => 'marketing',
                'provider'    => 'Microsoft Corp.',
                'privacy_url' => 'https://privacy.microsoft.com/privacystatement',
                'cookies'     => ['_uetsid', '_uetvid'],
            ],
            'gtm:hubspot' => [
                'patterns'    => ['hs-scripts.com', 'hsq'],
                'name'        => 'HubSpot',
                'category'    => 'marketing',
                'provider'    => 'HubSpot Inc.',
                'privacy_url' => 'https://legal.hubspot.com/privacy-policy',
                'cookies'     => ['hubspotutk', '__hstc', '__hssc', '__hssrc'],
            ],
        ];
    }

    /* ── Public interface ── */

    public static function get_results(): array {
        return (array) get_option(self::RESULTS_OPTION, []);
    }

    public static function run(): array {
        $base_url = self::get_base_url();
        if (!$base_url) {
            return ['error' => 'URL sito non configurato in Info Azienda.'];
        }

        set_transient(self::PROGRESS_TRANSIENT, ['status' => 'running', 'pages' => 0, 'found' => []], 300);

        $pages          = self::discover_pages($base_url);
        $found          = [];
        $scanned        = 0;
        $gtm_containers = []; // [container_id => [paths where found]]

        foreach ($pages as $url) {
            if ($scanned >= self::MAX_PAGES) break;

            $page_result = self::scan_page($url);
            $path        = wp_parse_url($url, PHP_URL_PATH) ?: '/';

            foreach ($page_result['providers'] as $pattern => $match) {
                if (!isset($found[$pattern])) {
                    $found[$pattern]             = $match;
                    $found[$pattern]['found_on'] = [];
                }
                if (!in_array($path, $found[$pattern]['found_on'], true)) {
                    $found[$pattern]['found_on'][] = $path;
                }
            }

            // Accumula container GTM trovati su questa pagina
            foreach ($page_result['gtm_ids'] as $gtm_id) {
                $gtm_containers[$gtm_id]   = $gtm_containers[$gtm_id] ?? [];
                $gtm_containers[$gtm_id][] = $path;
            }

            $scanned++;
            set_transient(
                self::PROGRESS_TRANSIENT,
                ['status' => 'running', 'pages' => $scanned, 'found' => array_values($found)],
                300
            );
        }

        // Scansiona i container GTM trovati
        $found_names = array_column($found, 'name'); // per evitare duplicati per nome
        foreach ($gtm_containers as $container_id => $pages_with_gtm) {
            foreach (self::scan_gtm_container($container_id) as $key => $item) {
                // Se lo stesso servizio è già stato rilevato direttamente sulla pagina, salta
                if (in_array($item['name'], $found_names, true)) continue;
                $item['found_on']   = array_unique($pages_with_gtm);
                $item['via_gtm']    = $container_id;
                $found[$key]        = $item;
                $found_names[]      = $item['name'];
            }
        }

        // Auto-aggiungi al registry i servizi trovati non ancora presenti;
        // se già presenti con cookies vuoti, aggiorna solo i cookie.
        $all_services     = SUITE_BARRIO_Service_Registry::get_services();
        $registered_names = array_column($all_services, 'name');
        foreach ($found as $item) {
            $known_cookies = $item['cookies'] ?? [];
            if (in_array($item['name'], $registered_names, true)) {
                // Aggiorna i cookie se il servizio esiste ma li ha vuoti
                if (!empty($known_cookies)) {
                    foreach ($all_services as $s) {
                        if ($s['name'] === $item['name'] && empty($s['cookies'])) {
                            SUITE_BARRIO_Service_Registry::update_service($s['id'], ['cookies' => $known_cookies]);
                            break;
                        }
                    }
                }
                continue;
            }
            SUITE_BARRIO_Service_Registry::add_service([
                'name'        => $item['name'],
                'category'    => $item['category'],
                'provider'    => $item['provider'] ?? '',
                'privacy_url' => $item['privacy_url'] ?? '',
                'cookies'     => $known_cookies,
                'script'      => '',
                'active'      => true,
                'source'      => 'scanner',
            ]);
            $registered_names[] = $item['name'];
        }

        // Marca nel risultato quali provider sono nel registry
        foreach ($found as &$item) {
            $item['in_registry'] = in_array($item['name'], $registered_names, true);
        }
        unset($item);

        $results = [
            'ts'            => time(),
            'pages_scanned' => $scanned,
            'found'         => array_values($found),
        ];

        update_option(self::RESULTS_OPTION, $results, false);
        set_transient(
            self::PROGRESS_TRANSIENT,
            ['status' => 'done', 'pages' => $scanned, 'found' => $results['found']],
            120
        );

        return $results;
    }

    /* ── Internal helpers ── */

    /**
     * Resolve the site base URL.
     * Requires suite_barrio_company_info_get()['sito_web'] to be configured.
     * Returns empty string if not configured, signaling run() to return an error.
     */
    private static function get_base_url(): string {
        if (function_exists('suite_barrio_company_info_get')) {
            $info = suite_barrio_company_info_get();
            $url  = trim((string) ($info['sito_web'] ?? ''));
            if ($url !== '') {
                if (!preg_match('#^https?://#i', $url)) {
                    $url = 'https://' . $url;
                }
                return rtrim($url, '/');
            }
        }
        return '';
    }

    /**
     * Discover up to MAX_PAGES URLs to scan.
     *
     * Strategy:
     * 1. Try /sitemap.xml — parse all <loc> entries that start with base_url.
     * 2. Fallback: extract internal links from homepage HTML.
     * Always includes the homepage itself as the first entry.
     */
    private static function discover_pages(string $base_url): array {
        $pages = [$base_url];

        // Attempt sitemap.xml
        $sitemap_response = wp_remote_get(
            $base_url . '/sitemap.xml',
            ['timeout' => 10, 'user-agent' => 'SuiteBarrio-Scanner/1.0 (+https://hellobarrio.it)']
        );

        if (
            !is_wp_error($sitemap_response) &&
            wp_remote_retrieve_response_code($sitemap_response) === 200
        ) {
            $body = wp_remote_retrieve_body($sitemap_response);
            preg_match_all('#<loc>\s*(.*?)\s*</loc>#s', $body, $matches);
            foreach ($matches[1] as $loc) {
                $loc = trim($loc);
                if (str_starts_with($loc, $base_url)) {
                    $pages[] = $loc;
                }
            }
            return array_unique(array_slice($pages, 0, self::MAX_PAGES));
        }

        // Fallback: extract internal links from homepage HTML
        $home_response = wp_remote_get(
            $base_url,
            ['timeout' => 15, 'user-agent' => 'SuiteBarrio-Scanner/1.0 (+https://hellobarrio.it)']
        );

        if (
            !is_wp_error($home_response) &&
            wp_remote_retrieve_response_code($home_response) === 200
        ) {
            $pattern = '#href=["\'](' . preg_quote($base_url, '#') . '[^"\'#?]*)["\']#';
            preg_match_all($pattern, wp_remote_retrieve_body($home_response), $links);
            foreach ($links[1] as $link) {
                $clean = rtrim($link, '/');
                // Skip asset files — only follow HTML pages
                if (
                    $clean &&
                    !preg_match('#\.(css|js|jpg|jpeg|png|gif|svg|pdf|xml|ico|woff2?)$#i', $clean)
                ) {
                    $pages[] = $clean;
                }
            }
        }

        return array_unique(array_slice($pages, 0, self::MAX_PAGES));
    }

    /**
     * Fetch a single page and return matched providers + GTM container IDs found.
     *
     * @return array{providers: array, gtm_ids: string[]}
     */
    private static function scan_page(string $url): array {
        $response = wp_remote_get(
            $url,
            ['timeout' => 15, 'user-agent' => 'SuiteBarrio-Scanner/1.0 (+https://hellobarrio.it)']
        );

        if (
            is_wp_error($response) ||
            wp_remote_retrieve_response_code($response) !== 200
        ) {
            return ['providers' => [], 'gtm_ids' => []];
        }

        $html = wp_remote_retrieve_body($response);

        return [
            'providers' => self::match_providers($html),
            'gtm_ids'   => self::extract_gtm_ids($html),
        ];
    }

    /**
     * Extract all GTM container IDs (GTM-XXXXXXX) from a page's HTML.
     *
     * @return string[]
     */
    private static function extract_gtm_ids(string $html): array {
        preg_match_all('/GTM-[A-Z0-9]+/', $html, $matches);
        return array_unique($matches[0]);
    }

    /**
     * Fetch the GTM container JS and search for known tag patterns.
     *
     * @return array keyed by gtm:* key, each value is service info.
     */
    private static function scan_gtm_container(string $container_id): array {
        $response = wp_remote_get(
            'https://www.googletagmanager.com/gtm.js?id=' . rawurlencode($container_id),
            ['timeout' => 15, 'user-agent' => 'SuiteBarrio-Scanner/1.0 (+https://hellobarrio.it)']
        );

        if (
            is_wp_error($response) ||
            wp_remote_retrieve_response_code($response) !== 200
        ) {
            return [];
        }

        $body  = wp_remote_retrieve_body($response);
        $found = [];

        foreach (self::gtm_tag_db() as $key => $def) {
            foreach ($def['patterns'] as $pattern) {
                // Pattern con metacaratteri regex → preg_match, altrimenti str_contains
                $is_regex = (bool) preg_match('/[.+*?^$\[\](){}|\\\\]/', $pattern);
                $matched  = $is_regex
                    ? (bool) preg_match('/' . $pattern . '/i', $body)
                    : str_contains($body, $pattern);

                if ($matched) {
                    $found[$key] = array_merge(
                        ['pattern' => $key, 'in_registry' => false, 'found_on' => []],
                        $def
                    );
                    unset($found[$key]['patterns']); // non serve nell'output
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Parse HTML for <script src>, <iframe src>, and <img src> attributes,
     * then match each src against the provider database.
     * Also scans the content of inline <script> blocks so plugins that
     * dynamically load third-party scripts (e.g. Meta Pixel via fbevents.js
     * loaded inside an IIFE) are detected even without a static src attribute.
     *
     * Returns an array keyed by the matched provider pattern.
     */
    private static function match_providers(string $html): array {
        $db          = self::provider_db();
        $found       = [];
        $found_names = []; // deduplicazione per nome (più pattern → stesso servizio)

        $add = function (string $pattern, array $info) use (&$found, &$found_names): void {
            if (isset($found[$pattern]) || in_array($info['name'], $found_names, true)) return;
            $found[$pattern]   = array_merge(['pattern' => $pattern, 'in_registry' => false], $info);
            $found_names[]     = $info['name'];
        };

        // 1. Attributi src di <script>, <iframe>, <img>
        preg_match_all(
            '#<(?:script|iframe|img)[^>]+src=["\']([^"\']+)["\']#i',
            $html,
            $matches
        );
        foreach ($matches[1] as $src) {
            foreach ($db as $pattern => $info) {
                if (str_contains($src, $pattern)) $add($pattern, $info);
            }
        }

        // 2. Contenuto degli script inline (es. Meta Pixel caricato via IIFE)
        preg_match_all('#<script(?:[^>]*)>(.*?)</script>#si', $html, $inline);
        $inline_body = implode(' ', $inline[1]);
        foreach ($db as $pattern => $info) {
            if (str_contains($inline_body, $pattern)) $add($pattern, $info);
        }

        return $found;
    }
}
