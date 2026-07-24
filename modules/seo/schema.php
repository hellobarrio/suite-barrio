<?php
/**
 * Suite Barrio — SEO / Schema
 * Genera JSON-LD strutturato ed esporta via GraphQL.
 *
 *  - hbSchemaRaw          → globale: LocalBusiness + WebSite
 *  - hbSchemaPageRaw(url) → pagina: globale + WebPage per quell'URL
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

if (!defined('SUITE_BARRIO_SCHEMA_OPTION'))
    define('SUITE_BARRIO_SCHEMA_OPTION', 'suite_barrio_schema_settings');

/* ─────────────────────────────────────────
   MENU
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!suite_barrio_tools_module_enabled('seo'))
        return;

    add_submenu_page(
        'suite-barrio',
        'Schema — Suite Barrio',
        'Schema',
        'manage_options',
        'suite-barrio-schema',
        'suite_barrio_schema_admin_page'
    );
}, 15);

add_action('admin_enqueue_scripts', function () {
    wp_add_inline_style(
        'suite-barrio-admin',
        '#adminmenu a[href="admin.php?page=suite-barrio-schema"]{display:none!important}'
    );
});

add_filter('submenu_file', function ($submenu_file) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    if (isset($_GET['page']) && $_GET['page'] === 'suite-barrio-schema')
        return 'suite-barrio-seo';
    return $submenu_file;
});

/* ─────────────────────────────────────────
   SALVATAGGIO IMPOSTAZIONI
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_schema_save', function () {
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');
    check_admin_referer('suite_barrio_schema_save');

    $settings = [
        'business_type' => sanitize_text_field(wp_unslash($_POST['business_type'] ?? 'LocalBusiness')),
        'business_name' => sanitize_text_field(wp_unslash($_POST['business_name'] ?? '')),
        'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
        'url' => esc_url_raw(wp_unslash($_POST['url'] ?? '')),
        'logo' => esc_url_raw(wp_unslash($_POST['logo'] ?? '')),
        'image' => esc_url_raw(wp_unslash($_POST['image'] ?? '')),
        'telephone' => sanitize_text_field(wp_unslash($_POST['telephone'] ?? '')),
        'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
        'vat_id' => sanitize_text_field(wp_unslash($_POST['vat_id'] ?? '')),
        'street_address' => sanitize_text_field(wp_unslash($_POST['street_address'] ?? '')),
        'postal_code' => sanitize_text_field(wp_unslash($_POST['postal_code'] ?? '')),
        'locality' => sanitize_text_field(wp_unslash($_POST['locality'] ?? '')),
        'region' => sanitize_text_field(wp_unslash($_POST['region'] ?? '')),
        'country' => sanitize_text_field(wp_unslash($_POST['country'] ?? 'IT')),
        'latitude' => sanitize_text_field(wp_unslash($_POST['latitude'] ?? '')),
        'longitude' => sanitize_text_field(wp_unslash($_POST['longitude'] ?? '')),
        'facebook' => esc_url_raw(wp_unslash($_POST['facebook'] ?? '')),
        'instagram' => esc_url_raw(wp_unslash($_POST['instagram'] ?? '')),
        'twitter' => esc_url_raw(wp_unslash($_POST['twitter'] ?? '')),
        'linkedin' => esc_url_raw(wp_unslash($_POST['linkedin'] ?? '')),
        'language' => sanitize_text_field(wp_unslash($_POST['language'] ?? 'it-IT')),
    ];

    update_option(SUITE_BARRIO_SCHEMA_OPTION, $settings);
    if (function_exists('suite_barrio_invalidate_vercel_cache')) {
        suite_barrio_invalidate_vercel_cache('wp:schema');
    }
    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-schema&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   HELPER: ISO → BCP-47
   ───────────────────────────────────────── */
function suite_barrio_schema_to_bcp47(string $code): string
{
    static $map = [
    'it' => 'it-IT',
    'en' => 'en-US',
    'fr' => 'fr-FR',
    'de' => 'de-DE',
    'es' => 'es-ES',
    'pt' => 'pt-PT',
    'nl' => 'nl-NL',
    'pl' => 'pl-PL',
    'ru' => 'ru-RU',
    'zh' => 'zh-CN',
    'ja' => 'ja-JP',
    'ar' => 'ar-SA',
    'sv' => 'sv-SE',
    'da' => 'da-DK',
    'fi' => 'fi-FI',
    'nb' => 'nb-NO',
    'cs' => 'cs-CZ',
    'hu' => 'hu-HU',
    'ro' => 'ro-RO',
    'tr' => 'tr-TR',
    'el' => 'el-GR',
    ];
    return $map[$code] ?? $code;
}

/* ─────────────────────────────────────────
   HELPER: grafo globale (LocalBusiness + WebSite)
   ───────────────────────────────────────── */
function suite_barrio_schema_build_global(): array
{
    $s = get_option(SUITE_BARRIO_SCHEMA_OPTION, []);
    $meta = get_option(SUITE_BARRIO_META_OPTION, []);

    $site_url = rtrim($s['url'] ?: home_url(), '/');
    $site_name = $s['business_name'] ?: ($meta['site_title'] ?: get_bloginfo('name'));
    $base_id = $site_url . '/#business';
    $web_id = $site_url . '/#website';
    $lang = $s['language'] ?: get_bloginfo('language');

    $graph = [];

    // ── 1. LocalBusiness / Organization ──────────────────────────────────
    $business = [
        '@type' => $s['business_type'] ?: 'LocalBusiness',
        '@id' => $base_id,
        'name' => $site_name,
    ];

    if (!empty($s['description']))
        $business['description'] = $s['description'];
    if (!empty($s['url']))
        $business['url'] = $s['url'];
    if (!empty($s['logo']))
        $business['logo'] = ['@type' => 'ImageObject', 'url' => $s['logo']];
    if (!empty($s['image']))
        $business['image'] = ['@type' => 'ImageObject', 'url' => $s['image']];
    if (!empty($s['telephone']))
        $business['telephone'] = $s['telephone'];
    if (!empty($s['email']))
        $business['email'] = $s['email'];
    if (!empty($s['vat_id']))
        $business['vatID'] = $s['vat_id'];

    if (!empty($s['street_address']) || !empty($s['locality'])) {
        $business['address'] = array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $s['street_address'] ?? '',
            'postalCode' => $s['postal_code'] ?? '',
            'addressLocality' => $s['locality'] ?? '',
            'addressRegion' => $s['region'] ?? '',
            'addressCountry' => $s['country'] ?? 'IT',
        ]);
    }

    if (!empty($s['latitude']) && !empty($s['longitude'])) {
        $business['geo'] = [
            '@type' => 'GeoCoordinates',
            'latitude' => (float) $s['latitude'],
            'longitude' => (float) $s['longitude'],
        ];
    }

    $same_as = array_values(array_filter([
        $s['facebook'] ?? '',
        $s['instagram'] ?? '',
        $s['twitter'] ?? '',
        $s['linkedin'] ?? '',
    ]));
    if (!empty($same_as))
        $business['sameAs'] = $same_as;

    $graph[] = $business;

    // ── 2. WebSite ────────────────────────────────────────────────────────
    $website = [
        '@type' => 'WebSite',
        '@id' => $web_id,
        'url' => "$site_url/",
        'name' => $site_name,
        'inLanguage' => $lang,
        'publisher' => ['@id' => $base_id],
    ];
    if (!empty($meta['default_desc']))
        $website['description'] = $meta['default_desc'];
    $graph[] = $website;

    return [
        '@context' => 'https://schema.org',
        '@graph' => $graph,
    ];
}

/* ─────────────────────────────────────────
   HELPER: nodo WebPage per URL specifica
   Restituisce null se l'URL non corrisponde
   a nessun contenuto indicizzabile.
   ───────────────────────────────────────── */
function suite_barrio_schema_find_webpage(string $requested_url): ?array
{
    $s = get_option(SUITE_BARRIO_SCHEMA_OPTION, []);
    $meta = get_option(SUITE_BARRIO_META_OPTION, []);
    $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);

    $site_url = rtrim($s['url'] ?: home_url(), '/');
    $site_name = $s['business_name'] ?: ($meta['site_title'] ?: get_bloginfo('name'));
    $base_id = $site_url . '/#business';
    $web_id = $site_url . '/#website';
    $lang = $s['language'] ?: get_bloginfo('language');

    $pt_list = $content['post_types'] ?? [];
    $tax_list = $content['taxonomies'] ?? [];

    $wpml_active = defined('ICL_SITEPRESS_VERSION')
        && function_exists('suite_barrio_multilang_is_enabled')
        && suite_barrio_multilang_is_enabled();

    $to_frontend = function (string $wp_url) use ($site_url): string {
        if (empty($wp_url))
            return '';
        return $site_url . wp_make_link_relative($wp_url);
    };

    $req_norm = rtrim($requested_url, '/');

    // ── Prova come post / CPT ─────────────────────────────────────────────
    $post_id = url_to_postid($requested_url);
    if ($post_id && get_post_status($post_id) === 'publish') {

        $post_type = get_post_type($post_id);
        $cfg = $pt_list[$post_type] ?? ['index' => 1];
        if (empty($cfg['index']))
            return null;

        $post_idx = get_post_meta($post_id, '_suite_barrio_seo_index', true);
        if ($post_idx === '0')
            return null;

        $permalink = get_permalink($post_id);
        $frontend_url = $to_frontend($permalink);
        $page_uri = wp_make_link_relative($permalink);
        $page_id = $site_url . $page_uri . '#webpage';

        $seo_title = get_post_meta($post_id, '_suite_barrio_seo_title', true) ?: get_the_title($post_id);
        $seo_desc = get_post_meta($post_id, '_suite_barrio_seo_description', true)
            ?: wp_strip_all_tags(get_the_excerpt($post_id))
            ?: ($meta['default_desc'] ?? '');

        $kw_raw = get_post_meta($post_id, '_suite_barrio_seo_keywords', true);
        $kws = $kw_raw ? (array) json_decode($kw_raw, true) : [];

        $og_img = '';
        $custom_og = (int) get_post_meta($post_id, '_suite_barrio_seo_og_image_id', true);
        if ($custom_og)
            $og_img = wp_get_attachment_image_url($custom_og, 'full') ?: '';
        if (!$og_img && has_post_thumbnail($post_id))
            $og_img = get_the_post_thumbnail_url($post_id, 'full') ?: '';
        if (!$og_img && !empty($meta['og_image_id']))
            $og_img = wp_get_attachment_image_url((int) $meta['og_image_id'], 'full') ?: '';

        $node_lang = $lang;
        if ($wpml_active) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legge l'hook di WPML, non ne definisce uno nuovo
            $lang_details = apply_filters('wpml_element_language_details', null, [
                'element_id' => $post_id,
                'element_type' => $post_type,
            ]);
            if ($lang_details && isset($lang_details->language_code))
                $node_lang = suite_barrio_schema_to_bcp47($lang_details->language_code);
        }

        $is_home = rtrim($frontend_url, '/') === rtrim($site_url, '/');
        $breadcrumb_items = $is_home
            ? [['@type' => 'ListItem', 'position' => 1, 'name' => $site_name, 'item' => $frontend_url]]
            : [
                ['@type' => 'ListItem', 'position' => 1, 'name' => $site_name, 'item' => "$site_url/"],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $seo_title, 'item' => $frontend_url],
            ];

        $page = [
            '@type' => 'WebPage',
            '@id' => $page_id,
            'url' => $frontend_url,
            'name' => $seo_title,
            'inLanguage' => $node_lang,
            'isPartOf' => ['@id' => $web_id],
            'about' => ['@id' => $base_id],
            'breadcrumb' => [
                '@type' => 'BreadcrumbList',
                '@id' => "{$page_id}#breadcrumb",
                'itemListElement' => $breadcrumb_items,
            ],
        ];

        if ($seo_desc)
            $page['description'] = $seo_desc;
        if (!empty($kws))
            $page['keywords'] = implode(', ', $kws);
        if ($og_img)
            $page['image'] = ['@type' => 'ImageObject', 'url' => $og_img];

        $modified = get_post_modified_time('c', true, $post_id);
        $published = get_post_time('c', true, $post_id);
        if ($modified)
            $page['dateModified'] = $modified;
        if ($published)
            $page['datePublished'] = $published;

        return $page;
    }

    // ── Prova come termine di tassonomia ──────────────────────────────────
    $excluded_taxes = ['post_format', 'nav_menu', 'link_category'];

    foreach (array_diff(get_taxonomies(['public' => true], 'names'), $excluded_taxes) as $tax) {
        $cfg = $tax_list[$tax] ?? ['index' => 1];
        if (empty($cfg['index']))
            continue;

        $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => true]);
        if (is_wp_error($terms))
            continue;

        foreach ($terms as $term) {
            $link = get_term_link($term, $tax);
            if (is_wp_error($link))
                continue;

            $frontend_url = $to_frontend($link);
            if (rtrim($frontend_url, '/') !== $req_norm)
                continue;

            $term_idx = get_term_meta($term->term_id, '_suite_barrio_seo_index', true);
            if ($term_idx === '0')
                return null;

            $page_uri = wp_make_link_relative($link);
            $page_id = $site_url . $page_uri . '#webpage';

            $seo_title = get_term_meta($term->term_id, '_suite_barrio_seo_title', true) ?: $term->name;
            $seo_desc = get_term_meta($term->term_id, '_suite_barrio_seo_description', true)
                ?: wp_strip_all_tags($term->description)
                ?: ($meta['default_desc'] ?? '');

            $kw_raw = get_term_meta($term->term_id, '_suite_barrio_seo_keywords', true);
            $kws = $kw_raw ? (array) json_decode($kw_raw, true) : [];

            $og_img = '';
            $custom_og = (int) get_term_meta($term->term_id, '_suite_barrio_seo_og_image_id', true);
            if ($custom_og)
                $og_img = wp_get_attachment_image_url($custom_og, 'full') ?: '';
            if (!$og_img && !empty($meta['og_image_id']))
                $og_img = wp_get_attachment_image_url((int) $meta['og_image_id'], 'full') ?: '';

            $node_lang = $lang;
            if ($wpml_active) {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legge l'hook di WPML, non ne definisce uno nuovo
            $lang_details = apply_filters('wpml_element_language_details', null, [
                    'element_id' => $term->term_taxonomy_id,
                    'element_type' => 'tax_' . $tax,
                ]);
                if ($lang_details && isset($lang_details->language_code))
                    $node_lang = suite_barrio_schema_to_bcp47($lang_details->language_code);
            }

            $page = [
                '@type' => 'WebPage',
                '@id' => $page_id,
                'url' => $frontend_url,
                'name' => $seo_title,
                'inLanguage' => $node_lang,
                'isPartOf' => ['@id' => $web_id],
                'about' => ['@id' => $base_id],
                'breadcrumb' => [
                    '@type' => 'BreadcrumbList',
                    '@id' => "{$page_id}#breadcrumb",
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => $site_name, 'item' => "$site_url/"],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => $seo_title, 'item' => $frontend_url],
                    ],
                ],
            ];

            if ($seo_desc)
                $page['description'] = $seo_desc;
            if (!empty($kws))
                $page['keywords'] = implode(', ', $kws);
            if ($og_img)
                $page['image'] = ['@type' => 'ImageObject', 'url' => $og_img];

            return $page;
        }
    }

    return null;
}

/* ─────────────────────────────────────────
   HELPER: schema completo per una pagina
   (globale + WebPage per quell'URL)
   ───────────────────────────────────────── */
function suite_barrio_schema_build_page(string $url): array
{
    $schema = suite_barrio_schema_build_global();
    $webpage = suite_barrio_schema_find_webpage($url);
    if ($webpage)
        $schema['@graph'][] = $webpage;
    return $schema;
}

/* ─────────────────────────────────────────
   COMPAT: alias backward-compatible
   ───────────────────────────────────────── */
function suite_barrio_schema_build(): array
{
    return suite_barrio_schema_build_global();
}

/* ─────────────────────────────────────────
   GRAPHQL
   ───────────────────────────────────────── */
add_action('graphql_register_types', function () {
    if (!function_exists('register_graphql_field'))
        return;

    // ── Query: hbSchemaRaw(uri) — JSON-LD come stringa ────────────────────
    register_graphql_field('RootQuery', 'hbSchemaRaw', [
        'type' => 'String',
        'description' => 'Schema JSON-LD come stringa pronta per <script type="application/ld+json">: globale + WebPage se uri fornito',
        'args' => [
            'uri' => ['type' => 'String', 'description' => 'URI della pagina corrente, es. /chi-siamo/'],
        ],
        'resolve' => function ($root, array $args) {
            $uri = trim($args['uri'] ?? '');
            $schema = $uri !== ''
                ? suite_barrio_schema_build_page(home_url('/' . ltrim($uri, '/')))
                : suite_barrio_schema_build_global();
            return wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        },
    ]);
});

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function suite_barrio_schema_admin_page()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione (notice "salvato"), nessun nonce esistente per questa azione
    $saved = isset($_GET['saved']);
    $s = get_option(SUITE_BARRIO_SCHEMA_OPTION, []);

    $business_types = [
        'LocalBusiness' => 'LocalBusiness',
        'Organization' => 'Organization',
        'Restaurant' => 'Restaurant',
        'Hotel' => 'Hotel',
        'Store' => 'Store',
        'MedicalBusiness' => 'MedicalBusiness',
        'LegalService' => 'LegalService',
        'FinancialService' => 'FinancialService',
        'InteriorDesigner' => 'InteriorDesigner',
        'Architect' => 'Architect',
        'Attorney' => 'Attorney',
        'Dentist' => 'Dentist',
        'Physician' => 'Physician',
        'RealEstateAgent' => 'RealEstateAgent',
        'LodgingBusiness' => 'LodgingBusiness'
    ];
    ?>
    <div id="suite-barrio-schema-wrap" class="wrap suite-barrio-wrap">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
            <div class="suite-barrio-sch-header">
                <div class="suite-barrio-sch-header-icon"><span class="dashicons dashicons-networking"></span></div>
                <div>
                    <h1>Suite Barrio <span class="suite-barrio-sch-breadcrumb">/ SEO / Schema</span></h1>
                    <p class="suite-barrio-sch-sub">Dati strutturati JSON-LD &mdash; esportati via GraphQL per il frontend
                        headless
                    </p>
                </div>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-seo')); ?>"
                style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;font-size:12.5px;font-weight:600;background:#f3f4f6;color:#374151 !important;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none !important;white-space:nowrap;flex-shrink:0;align-self:flex-start;">
                <span class="dashicons dashicons-arrow-left-alt2"
                    style="font-size:14px;width:14px;height:14px;margin-top:1px;"></span>
                Strumenti SEO
            </a>
        </div>
        <hr class="suite-barrio-sch-divider">

        <?php if ($saved): ?>
            <div class="suite-barrio-sch-notice-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <div>Impostazioni salvate.</div>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('suite_barrio_schema_save'); ?>
            <input type="hidden" name="action" value="suite_barrio_schema_save">

            <!-- Business -->
            <div class="suite-barrio-schema-panel">
                <div class="suite-barrio-schema-panel-header">
                    <span class="dashicons dashicons-building"></span>
                    <span class="suite-barrio-schema-panel-header-title">Identità Business</span>
                </div>
                <div class="suite-barrio-schema-section">
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Tipo<small>Schema.org @type</small></div>
                        <div class="suite-barrio-sf-input">
                            <select name="business_type">
                                <?php foreach ($business_types as $val => $label): ?>
                                    <option value="<?php echo esc_attr($val); ?>" <?php selected($s['business_type'] ?? 'LocalBusiness', $val); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Nome<small>Nome del business</small></div>
                        <div class="suite-barrio-sf-input"><input type="text" name="business_name"
                                value="<?php echo esc_attr($s['business_name'] ?? ''); ?>"
                                placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></div>
                    </div>
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Descrizione</div>
                        <div class="suite-barrio-sf-input"><textarea
                                name="description"><?php echo esc_textarea($s['description'] ?? ''); ?></textarea></div>
                    </div>
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">URL sito</div>
                        <div class="suite-barrio-sf-input"><input type="url" name="url"
                                value="<?php echo esc_attr($s['url'] ?? home_url()); ?>"></div>
                    </div>
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Logo URL</div>
                        <div class="suite-barrio-sf-input"><input type="url" name="logo"
                                value="<?php echo esc_attr($s['logo'] ?? ''); ?>" placeholder="https://…/logo.png"></div>
                    </div>
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Immagine principale</div>
                        <div class="suite-barrio-sf-input"><input type="url" name="image"
                                value="<?php echo esc_attr($s['image'] ?? ''); ?>" placeholder="https://…/cover.jpg"></div>
                    </div>
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Lingua<small>Lingua principale del sito</small></div>
                        <div class="suite-barrio-sf-input">
                            <?php
                            $current_lang = $s['language'] ?? 'it-IT';
                            $lang_options = [
                                'it-IT' => '🇮🇹 Italiano',
                                'en-US' => '🇺🇸 English (US)',
                                'en-GB' => '🇬🇧 English (UK)',
                                'fr-FR' => '🇫🇷 Français',
                                'de-DE' => '🇩🇪 Deutsch',
                                'es-ES' => '🇪🇸 Español',
                                'pt-PT' => '🇵🇹 Português (PT)',
                                'pt-BR' => '🇧🇷 Português (BR)',
                                'nl-NL' => '🇳🇱 Nederlands',
                                'pl-PL' => '🇵🇱 Polski',
                                'ru-RU' => '🇷🇺 Русский',
                                'zh-CN' => '🇨🇳 中文 (简体)',
                                'zh-TW' => '🇹🇼 中文 (繁體)',
                                'ja-JP' => '🇯🇵 日本語',
                                'ar-SA' => '🇸🇦 العربية',
                                'sv-SE' => '🇸🇪 Svenska',
                                'da-DK' => '🇩🇰 Dansk',
                                'fi-FI' => '🇫🇮 Suomi',
                                'nb-NO' => '🇳🇴 Norsk',
                                'cs-CZ' => '🇨🇿 Čeština',
                                'hu-HU' => '🇭🇺 Magyar',
                                'ro-RO' => '🇷🇴 Română',
                                'tr-TR' => '🇹🇷 Türkçe',
                                'el-GR' => '🇬🇷 Ελληνικά',
                                'uk-UA' => '🇺🇦 Українська',
                                'ca-ES' => '🏴󠁥󠁳󠁣󠁴󠁿 Català',
                            ];
                            ?>
                            <select name="language" style="min-width:200px;">
                                <?php foreach ($lang_options as $code => $label): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($current_lang, $code); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($current_lang && !isset($lang_options[$current_lang])): ?>
                                    <option value="<?php echo esc_attr($current_lang); ?>" selected>
                                        <?php echo esc_html($current_lang); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contatti -->
            <div class="suite-barrio-schema-panel">
                <div class="suite-barrio-schema-panel-header">
                    <span class="dashicons dashicons-phone"></span>
                    <span class="suite-barrio-schema-panel-header-title">Contatti</span>
                </div>
                <div class="suite-barrio-schema-section">
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Telefono</div>
                        <div class="suite-barrio-sf-input"><input type="text" name="telephone"
                                value="<?php echo esc_attr($s['telephone'] ?? ''); ?>" placeholder="+390000000000"></div>
                    </div>
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Email</div>
                        <div class="suite-barrio-sf-input"><input type="email" name="email"
                                value="<?php echo esc_attr($s['email'] ?? ''); ?>"></div>
                    </div>
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Partita IVA</div>
                        <div class="suite-barrio-sf-input"><input type="text" name="vat_id"
                                value="<?php echo esc_attr($s['vat_id'] ?? ''); ?>" placeholder="IT00000000000"></div>
                    </div>
                </div>
            </div>

            <!-- Indirizzo -->
            <div class="suite-barrio-schema-panel">
                <div class="suite-barrio-schema-panel-header">
                    <span class="dashicons dashicons-location"></span>
                    <span class="suite-barrio-schema-panel-header-title">Indirizzo &amp; Posizione</span>
                </div>
                <div class="suite-barrio-schema-section">
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Via / Indirizzo</div>
                        <div class="suite-barrio-sf-input"><input type="text" name="street_address"
                                value="<?php echo esc_attr($s['street_address'] ?? ''); ?>"></div>
                    </div>
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">CAP e Città</div>
                        <div class="suite-barrio-sf-input">
                            <div class="suite-barrio-sf-pair">
                                <input type="text" name="postal_code"
                                    value="<?php echo esc_attr($s['postal_code'] ?? ''); ?>" placeholder="CAP"
                                    style="max-width:100px;">
                                <input type="text" name="locality" value="<?php echo esc_attr($s['locality'] ?? ''); ?>"
                                    placeholder="Città">
                            </div>
                        </div>
                    </div>
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Provincia / Nazione</div>
                        <div class="suite-barrio-sf-input">
                            <div class="suite-barrio-sf-pair">
                                <input type="text" name="region" value="<?php echo esc_attr($s['region'] ?? ''); ?>"
                                    placeholder="Provincia" style="max-width:100px;">
                                <input type="text" name="country" value="<?php echo esc_attr($s['country'] ?? 'IT'); ?>"
                                    placeholder="IT" style="max-width:80px;">
                            </div>
                        </div>
                    </div>
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Coordinate GPS<small>latitudine, longitudine</small></div>
                        <div class="suite-barrio-sf-input">
                            <div class="suite-barrio-sf-pair">
                                <input type="text" name="latitude" value="<?php echo esc_attr($s['latitude'] ?? ''); ?>"
                                    placeholder="44.1234">
                                <input type="text" name="longitude" value="<?php echo esc_attr($s['longitude'] ?? ''); ?>"
                                    placeholder="7.1234">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Social -->
            <div class="suite-barrio-schema-panel">
                <div class="suite-barrio-schema-panel-header">
                    <span class="dashicons dashicons-share"></span>
                    <span class="suite-barrio-schema-panel-header-title">Social (sameAs)</span>
                </div>
                <div class="suite-barrio-schema-section">
                    <?php foreach ([
                        'facebook' => ['Facebook', 'https://www.facebook.com/…'],
                        'instagram' => ['Instagram', 'https://www.instagram.com/…'],
                        'twitter' => ['Twitter/X', 'https://x.com/…'],
                        'linkedin' => ['LinkedIn', 'https://www.linkedin.com/…'],
                    ] as $field => [$label, $ph]): ?>
                        <div class="suite-barrio-sf-row">
                            <div class="suite-barrio-sf-label"><?php echo esc_html($label); ?></div>
                            <div class="suite-barrio-sf-input"><input type="url" name="<?php echo esc_attr($field); ?>"
                                    value="<?php echo esc_attr($s[$field] ?? ''); ?>"
                                    placeholder="<?php echo esc_attr($ph); ?>"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="suite-barrio-sch-save-btn">
                <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                Salva impostazioni
            </button>
        </form>

        <!-- Anteprima globale -->
        <div style="margin-top:32px;">
            <h2 style="font-size:14px;font-weight:700;color:#111827;margin:0 0 10px;">Anteprima Schema globale
                (LocalBusiness + WebSite)</h2>
            <?php
            $preview = suite_barrio_schema_build_global();
            $json = wp_json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $json_hl = preg_replace(
                ['/"([^"]+)"\s*:/', '/"([^"]*)"(?!\s*:)/', '/\b(\d+\.?\d*)\b/', '/\b(true|false|null)\b/'],
                ['<span class="key">"$1"</span>:', '<span class="str">"$1"</span>', '<span class="num">$1</span>', '<span class="bool">$1</span>'],
                htmlspecialchars($json)
            );
            ?>
            <div class="suite-barrio-schema-preview"><?php echo wp_kses_post($json_hl); ?></div>
        </div>
    </div>
    <?php
}
