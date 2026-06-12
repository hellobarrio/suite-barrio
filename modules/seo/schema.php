<?php
/**
 * WP Barrio — SEO / Schema
 * Genera JSON-LD strutturato per tutti i contenuti indicizzabili
 * ed esporta lo schema completo via GraphQL.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

if (!defined('WPB_SCHEMA_OPTION'))
    define('WPB_SCHEMA_OPTION', 'wpb_schema_settings');

/* ─────────────────────────────────────────
   MENU
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!wpb_tools_module_enabled('seo'))
        return;

    add_submenu_page(
        'wp-barrio',
        'Schema — WP Barrio',
        'Schema',
        'manage_options',
        'wp-barrio-schema',
        'wpb_schema_admin_page'
    );
}, 15);

add_action('admin_head', function () {
    echo '<style>#adminmenu a[href="admin.php?page=wp-barrio-schema"]{display:none!important}</style>';
});

add_filter('submenu_file', function ($submenu_file) {
    if (isset($_GET['page']) && $_GET['page'] === 'wp-barrio-schema')
        return 'wp-barrio-seo';
    return $submenu_file;
});

/* ─────────────────────────────────────────
   SALVATAGGIO IMPOSTAZIONI
   ───────────────────────────────────────── */
add_action('admin_post_wpb_schema_save', function () {
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');
    check_admin_referer('wpb_schema_save');

    $settings = [
        'business_type' => sanitize_text_field($_POST['business_type'] ?? 'LocalBusiness'),
        'business_name' => sanitize_text_field($_POST['business_name'] ?? ''),
        'description' => sanitize_textarea_field($_POST['description'] ?? ''),
        'url' => esc_url_raw($_POST['url'] ?? ''),
        'logo' => esc_url_raw($_POST['logo'] ?? ''),
        'image' => esc_url_raw($_POST['image'] ?? ''),
        'telephone' => sanitize_text_field($_POST['telephone'] ?? ''),
        'email' => sanitize_email($_POST['email'] ?? ''),
        'vat_id' => sanitize_text_field($_POST['vat_id'] ?? ''),
        'street_address' => sanitize_text_field($_POST['street_address'] ?? ''),
        'postal_code' => sanitize_text_field($_POST['postal_code'] ?? ''),
        'locality' => sanitize_text_field($_POST['locality'] ?? ''),
        'region' => sanitize_text_field($_POST['region'] ?? ''),
        'country' => sanitize_text_field($_POST['country'] ?? 'IT'),
        'latitude' => sanitize_text_field($_POST['latitude'] ?? ''),
        'longitude' => sanitize_text_field($_POST['longitude'] ?? ''),
        'facebook' => esc_url_raw($_POST['facebook'] ?? ''),
        'instagram' => esc_url_raw($_POST['instagram'] ?? ''),
        'twitter' => esc_url_raw($_POST['twitter'] ?? ''),
        'linkedin' => esc_url_raw($_POST['linkedin'] ?? ''),
        'language' => sanitize_text_field($_POST['language'] ?? 'it-IT'),
    ];

    update_option(WPB_SCHEMA_OPTION, $settings);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_redirect(admin_url('admin.php?page=wp-barrio-schema&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   HELPER: costruisce il grafo JSON-LD
   ───────────────────────────────────────── */
function wpb_schema_build(): array
{
    $s = get_option(WPB_SCHEMA_OPTION, []);
    $meta = get_option(WPB_META_OPTION, []);
    $content = get_option(WPB_META_CONTENT_OPTION, []);

    $site_url = rtrim($s['url'] ?: home_url(), '/');
    $site_name = $s['business_name'] ?: ($meta['site_title'] ?: get_bloginfo('name'));
    $base_id = $site_url . '/#business';
    $web_id = $site_url . '/#website';
    $lang = $s['language'] ?: get_bloginfo('language');

    // Converte qualsiasi URL WordPress nel corrispondente URL del frontend
    $to_frontend = function (string $wp_url) use ($site_url): string {
        if (empty($wp_url))
            return '';
        $uri = wp_make_link_relative($wp_url);
        return $site_url . $uri;
    };

    $graph = [];

    // ── 1. LocalBusiness / Organization ─────────────────────────────────
    $business = [
        '@type' => array_filter([$s['business_type'] ?: 'LocalBusiness']),
        '@id' => $base_id,
        'name' => $site_name,
    ];

    if (!empty($s['description']))
        $business['description'] = $s['description'];
    if (!empty($s['url']))
        $business['url'] = $s['url'];
    if (!empty($s['logo']))
        $business['logo'] = $s['logo'];
    if (!empty($s['image']))
        $business['image'] = $s['image'];
    if (!empty($s['telephone']))
        $business['telephone'] = $s['telephone'];
    if (!empty($s['email']))
        $business['email'] = $s['email'];
    if (!empty($s['vat_id']))
        $business['vatID'] = $s['vat_id'];

    // Indirizzo
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

    // Coordinate
    if (!empty($s['latitude']) && !empty($s['longitude'])) {
        $business['geo'] = [
            '@type' => 'GeoCoordinates',
            'latitude' => (float) $s['latitude'],
            'longitude' => (float) $s['longitude'],
        ];
    }

    // Social sameAs
    $same_as = array_values(array_filter([
        $s['facebook'] ?? '',
        $s['instagram'] ?? '',
        $s['twitter'] ?? '',
        $s['linkedin'] ?? '',
    ]));
    if (!empty($same_as))
        $business['sameAs'] = $same_as;

    $graph[] = $business;

    // ── 2. WebSite ───────────────────────────────────────────────────────
    $graph[] = [
        '@type' => 'WebSite',
        '@id' => $web_id,
        'url' => $site_url . '/',
        'name' => $site_name,
        'description' => $meta['default_desc'] ?? '',
        'inLanguage' => $lang,
        'publisher' => ['@id' => $base_id],
    ];

    // ── 3. WebPage per ogni contenuto indicizzabile ──────────────────────
    $excluded_taxes = ['post_format', 'nav_menu', 'link_category'];
    $pt_list = $content['post_types'] ?? [];
    $tax_list = $content['taxonomies'] ?? [];

    // Lingue da iterare: tutte le lingue WPML attive, oppure [null] se WPML non è attivo
    $wpml_active = defined('ICL_SITEPRESS_VERSION')
        && function_exists('wpb_multilang_is_enabled')
        && wpb_multilang_is_enabled();
    $languages = $wpml_active ? wpb_multilang_languages() : [null => null];

    // Mappa codice ISO WPML → BCP-47 per inLanguage schema.org (es. 'it' → 'it-IT')
    $to_bcp47 = function (string $code): string {
        $map = [
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
    };

    foreach (array_keys($languages) as $lang_code) {

        // Lingua corrente per i nodi di questa iterazione
        $node_lang = $wpml_active ? $to_bcp47($lang_code) : $lang;

        // ── Post / CPT ────────────────────────────────────────────────────
        foreach (get_post_types(['public' => true], 'objects') as $pt_obj) {
            $slug = $pt_obj->name;
            if ($slug === 'attachment')
                continue;

            $cfg = $pt_list[$slug] ?? ['index' => 1];
            if (empty($cfg['index']))
                continue;

            if ($wpml_active) {
                global $wpdb;
                $ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT element_id FROM {$wpdb->prefix}icl_translations
                     WHERE element_type = %s AND language_code = %s AND element_id IS NOT NULL",
                    'post_' . $slug,
                    $lang_code
                ));
                if (empty($ids))
                    continue;
                $posts = get_posts([
                    'post_type' => $slug,
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                    'post__in' => $ids,
                    'suppress_filters' => true,
                ]);
            } else {
                $posts = get_posts([
                    'post_type' => $slug,
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                ]);
            }

            foreach ($posts as $post_id) {
                $post_idx = get_post_meta($post_id, '_wpb_seo_index', true);
                if ($post_idx === '0')
                    continue;

                $permalink = get_permalink($post_id);
                $frontend_url = $to_frontend($permalink);
                $page_uri = wp_make_link_relative($permalink);
                $page_id = $site_url . $page_uri . '#webpage';

                $seo_title = get_post_meta($post_id, '_wpb_seo_title', true) ?: get_the_title($post_id);
                $seo_desc = get_post_meta($post_id, '_wpb_seo_description', true)
                    ?: wp_strip_all_tags(get_the_excerpt($post_id))
                    ?: ($meta['default_desc'] ?? '');

                $kw_raw = get_post_meta($post_id, '_wpb_seo_keywords', true);
                $kws = $kw_raw ? (array) json_decode($kw_raw, true) : [];

                $og_img = '';
                $custom_og = (int) get_post_meta($post_id, '_wpb_seo_og_image_id', true);
                if ($custom_og)
                    $og_img = wp_get_attachment_image_url($custom_og, 'full') ?: '';
                if (!$og_img && has_post_thumbnail($post_id))
                    $og_img = get_the_post_thumbnail_url($post_id, 'full') ?: '';
                if (!$og_img && !empty($meta['og_image_id']))
                    $og_img = wp_get_attachment_image_url((int) $meta['og_image_id'], 'full') ?: '';

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
                        'itemListElement' => [
                            [
                                '@type' => 'ListItem',
                                'position' => 1,
                                'name' => $seo_title,
                                'item' => $frontend_url,
                            ]
                        ],
                    ],
                ];

                if ($seo_desc)
                    $page['description'] = $seo_desc;
                if (!empty($kws))
                    $page['keywords'] = implode(', ', $kws);
                if ($og_img)
                    $page['image'] = $og_img;

                $modified = get_post_modified_time('c', true, $post_id);
                if ($modified)
                    $page['dateModified'] = $modified;
                $published = get_post_time('c', true, $post_id);
                if ($published)
                    $page['datePublished'] = $published;

                $graph[] = $page;
            }
        }

        // ── Tassonomie ────────────────────────────────────────────────────
        foreach (array_diff(get_taxonomies(['public' => true], 'names'), $excluded_taxes) as $tax) {
            $cfg = $tax_list[$tax] ?? ['index' => 1];
            if (empty($cfg['index']))
                continue;

            if ($wpml_active) {
                global $wpdb;
                $tt_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT element_id FROM {$wpdb->prefix}icl_translations
                     WHERE element_type = %s AND language_code = %s AND element_id IS NOT NULL",
                    'tax_' . $tax,
                    $lang_code
                ));
                if (empty($tt_ids))
                    continue;
                $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => true, 'term_taxonomy_id' => $tt_ids]);
            } else {
                $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => true]);
            }

            if (is_wp_error($terms))
                continue;

            foreach ($terms as $term) {
                $term_idx = get_term_meta($term->term_id, '_wpb_seo_index', true);
                if ($term_idx === '0')
                    continue;

                $link = get_term_link($term, $tax);
                if (is_wp_error($link))
                    continue;
                $frontend_url = $to_frontend($link);
                $page_uri = wp_make_link_relative($link);
                $page_id = $site_url . $page_uri . '#webpage';

                $seo_title = get_term_meta($term->term_id, '_wpb_seo_title', true) ?: $term->name;
                $seo_desc = get_term_meta($term->term_id, '_wpb_seo_description', true)
                    ?: wp_strip_all_tags($term->description)
                    ?: ($meta['default_desc'] ?? '');

                $kw_raw = get_term_meta($term->term_id, '_wpb_seo_keywords', true);
                $kws = $kw_raw ? (array) json_decode($kw_raw, true) : [];

                $og_img = '';
                $custom_og = (int) get_term_meta($term->term_id, '_wpb_seo_og_image_id', true);
                if ($custom_og)
                    $og_img = wp_get_attachment_image_url($custom_og, 'full') ?: '';
                if (!$og_img && !empty($meta['og_image_id']))
                    $og_img = wp_get_attachment_image_url((int) $meta['og_image_id'], 'full') ?: '';

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
                        'itemListElement' => [
                            [
                                '@type' => 'ListItem',
                                'position' => 1,
                                'name' => $seo_title,
                                'item' => $frontend_url,
                            ]
                        ],
                    ],
                ];

                if ($seo_desc)
                    $page['description'] = $seo_desc;
                if (!empty($kws))
                    $page['keywords'] = implode(', ', $kws);
                if ($og_img)
                    $page['image'] = $og_img;

                $graph[] = $page;
            }
        }
    }

    return [
        '@context' => 'https://schema.org',
        '@graph' => $graph,
    ];
}

/* ─────────────────────────────────────────
   GRAPHQL
   ───────────────────────────────────────── */
add_action('graphql_register_types', function () {
    if (!function_exists('register_graphql_object_type'))
        return;

    // ── Tipo nodo generico del grafo ─────────────────────────────────────
    register_graphql_object_type('HBSchemaNode', [
        'description' => 'Un nodo JSON-LD nel grafo Schema.org di WP Barrio',
        'fields' => [
            'type' => ['type' => ['list_of' => 'String'], 'description' => '@type (può essere multiplo)'],
            'id' => ['type' => 'String', 'description' => '@id univoco del nodo'],
            'name' => ['type' => 'String', 'description' => 'Nome o titolo'],
            'description' => ['type' => 'String', 'description' => 'Descrizione'],
            'url' => ['type' => 'String', 'description' => 'URL'],
            'image' => ['type' => 'String', 'description' => 'Immagine principale'],
            'logo' => ['type' => 'String', 'description' => 'Logo (per Business)'],
            'telephone' => ['type' => 'String', 'description' => 'Telefono'],
            'email' => ['type' => 'String', 'description' => 'Email'],
            'vatID' => ['type' => 'String', 'description' => 'Partita IVA'],
            'inLanguage' => ['type' => 'String', 'description' => 'Lingua (es. it-IT)'],
            'keywords' => ['type' => 'String', 'description' => 'Keywords SEO (CSV)'],
            'datePublished' => ['type' => 'String', 'description' => 'Data pubblicazione ISO 8601'],
            'dateModified' => ['type' => 'String', 'description' => 'Data modifica ISO 8601'],
            'sameAs' => ['type' => ['list_of' => 'String'], 'description' => 'URL social (sameAs)'],
            'isPartOfId' => ['type' => 'String', 'description' => '@id del nodo padre (isPartOf)'],
            'aboutId' => ['type' => 'String', 'description' => '@id del nodo about'],
            'publisherId' => ['type' => 'String', 'description' => '@id del publisher'],
            'rawJson' => ['type' => 'String', 'description' => 'Nodo completo serializzato come stringa JSON'],
        ],
    ]);

    // ── Query: hbSchema ──────────────────────────────────────────────────
    register_graphql_field('RootQuery', 'hbSchema', [
        'type' => ['list_of' => 'HBSchemaNode'],
        'description' => 'Restituisce il grafo JSON-LD Schema.org completo di WP Barrio (tutti i contenuti indicizzabili)',
        'resolve' => function () {
            if (!function_exists('wpb_schema_build'))
                return [];
            $schema = wpb_schema_build();
            $nodes = [];

            foreach ($schema['@graph'] ?? [] as $node) {
                // Normalizza @type sempre come array
                $type = isset($node['@type'])
                    ? (array) $node['@type']
                    : [];

                $nodes[] = [
                    'type' => $type,
                    'id' => $node['@id'] ?? null,
                    'name' => $node['name'] ?? null,
                    'description' => $node['description'] ?? null,
                    'url' => $node['url'] ?? null,
                    'image' => $node['image'] ?? null,
                    'logo' => is_string($node['logo'] ?? null) ? $node['logo'] : null,
                    'telephone' => $node['telephone'] ?? null,
                    'email' => $node['email'] ?? null,
                    'vatID' => $node['vatID'] ?? null,
                    'inLanguage' => $node['inLanguage'] ?? null,
                    'keywords' => $node['keywords'] ?? null,
                    'datePublished' => $node['datePublished'] ?? null,
                    'dateModified' => $node['dateModified'] ?? null,
                    'sameAs' => $node['sameAs'] ?? null,
                    'isPartOfId' => $node['isPartOf']['@id'] ?? null,
                    'aboutId' => $node['about']['@id'] ?? null,
                    'publisherId' => $node['publisher']['@id'] ?? null,
                    'rawJson' => wp_json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }

            return $nodes;
        },
    ]);

    // ── Query: hbSchemaRaw ───────────────────────────────────────────────
    // Restituisce l'intero JSON-LD come stringa — utile per iniettarlo direttamente
    register_graphql_field('RootQuery', 'hbSchemaRaw', [
        'type' => 'String',
        'description' => 'Restituisce l\'intero grafo Schema.org come stringa JSON-LD pronta per <script type="application/ld+json">',
        'resolve' => function () {
            if (!function_exists('wpb_schema_build'))
                return null;
            return wp_json_encode(
                wpb_schema_build(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );
        },
    ]);
});

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function wpb_schema_admin_page()
{
    $saved = isset($_GET['saved']);
    $s = get_option(WPB_SCHEMA_OPTION, []);

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
    ];
    ?>
<div id="wpb-schema-wrap" class="wrap">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
            <div class="wpb-sch-header">
                <div class="wpb-sch-header-icon"><span class="dashicons dashicons-networking"></span></div>
                <div>
                    <h1>WP Barrio <span class="wpb-sch-breadcrumb">/ SEO / Schema</span></h1>
                    <p class="wpb-sch-sub">Dati strutturati JSON-LD &mdash; esportati via GraphQL per il frontend headless
                    </p>
                </div>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-seo')); ?>"
                style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;font-size:12.5px;font-weight:600;background:#f3f4f6;color:#374151 !important;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none !important;white-space:nowrap;flex-shrink:0;align-self:flex-start;">
                <span class="dashicons dashicons-arrow-left-alt2"
                    style="font-size:14px;width:14px;height:14px;margin-top:1px;"></span>
                Strumenti SEO
            </a>
        </div>
        <hr class="wpb-sch-divider">

        <?php if ($saved): ?>
            <div class="wpb-sch-notice-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <div>Impostazioni salvate.</div>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wpb_schema_save'); ?>
            <input type="hidden" name="action" value="wpb_schema_save">

            <!-- Business -->
            <div class="wpb-schema-panel">
                <div class="wpb-schema-panel-header">
                    <span class="dashicons dashicons-building"></span>
                    <span class="wpb-schema-panel-header-title">Identità Business</span>
                </div>
                <div class="wpb-schema-section">
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">Tipo<small>Schema.org @type</small></div>
                        <div class="wpb-sf-input">
                            <select name="business_type">
                                <?php foreach ($business_types as $val => $label): ?>
                                    <option value="<?php echo esc_attr($val); ?>" <?php selected($s['business_type'] ?? 'LocalBusiness', $val); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">Nome<small>Nome del business</small></div>
                        <div class="wpb-sf-input"><input type="text" name="business_name"
                                value="<?php echo esc_attr($s['business_name'] ?? ''); ?>"
                                placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></div>
                    </div>
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">Descrizione</div>
                        <div class="wpb-sf-input"><textarea
                                name="description"><?php echo esc_textarea($s['description'] ?? ''); ?></textarea></div>
                    </div>
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">URL sito</div>
                        <div class="wpb-sf-input"><input type="url" name="url"
                                value="<?php echo esc_attr($s['url'] ?? home_url()); ?>"></div>
                    </div>
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">Logo URL</div>
                        <div class="wpb-sf-input"><input type="url" name="logo"
                                value="<?php echo esc_attr($s['logo'] ?? ''); ?>" placeholder="https://…/logo.png"></div>
                    </div>
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">Immagine principale</div>
                        <div class="wpb-sf-input"><input type="url" name="image"
                                value="<?php echo esc_attr($s['image'] ?? ''); ?>" placeholder="https://…/cover.jpg"></div>
                    </div>
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">Lingua<small>Lingua principale del sito</small></div>
                        <div class="wpb-sf-input">
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
            <div class="wpb-schema-panel">
                <div class="wpb-schema-panel-header">
                    <span class="dashicons dashicons-phone"></span>
                    <span class="wpb-schema-panel-header-title">Contatti</span>
                </div>
                <div class="wpb-schema-section">
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">Telefono</div>
                        <div class="wpb-sf-input"><input type="text" name="telephone"
                                value="<?php echo esc_attr($s['telephone'] ?? ''); ?>" placeholder="+390000000000"></div>
                    </div>
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">Email</div>
                        <div class="wpb-sf-input"><input type="email" name="email"
                                value="<?php echo esc_attr($s['email'] ?? ''); ?>"></div>
                    </div>
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">Partita IVA</div>
                        <div class="wpb-sf-input"><input type="text" name="vat_id"
                                value="<?php echo esc_attr($s['vat_id'] ?? ''); ?>" placeholder="IT00000000000"></div>
                    </div>
                </div>
            </div>

            <!-- Indirizzo -->
            <div class="wpb-schema-panel">
                <div class="wpb-schema-panel-header">
                    <span class="dashicons dashicons-location"></span>
                    <span class="wpb-schema-panel-header-title">Indirizzo &amp; Posizione</span>
                </div>
                <div class="wpb-schema-section">
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">Via / Indirizzo</div>
                        <div class="wpb-sf-input"><input type="text" name="street_address"
                                value="<?php echo esc_attr($s['street_address'] ?? ''); ?>"></div>
                    </div>
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">CAP e Città</div>
                        <div class="wpb-sf-input">
                            <div class="wpb-sf-pair">
                                <input type="text" name="postal_code"
                                    value="<?php echo esc_attr($s['postal_code'] ?? ''); ?>" placeholder="CAP"
                                    style="max-width:100px;">
                                <input type="text" name="locality" value="<?php echo esc_attr($s['locality'] ?? ''); ?>"
                                    placeholder="Città">
                            </div>
                        </div>
                    </div>
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">Provincia / Nazione</div>
                        <div class="wpb-sf-input">
                            <div class="wpb-sf-pair">
                                <input type="text" name="region" value="<?php echo esc_attr($s['region'] ?? ''); ?>"
                                    placeholder="Provincia" style="max-width:100px;">
                                <input type="text" name="country" value="<?php echo esc_attr($s['country'] ?? 'IT'); ?>"
                                    placeholder="IT" style="max-width:80px;">
                            </div>
                        </div>
                    </div>
                    <div class="wpb-sf-row">
                        <div class="wpb-sf-label">Coordinate GPS<small>latitudine, longitudine</small></div>
                        <div class="wpb-sf-input">
                            <div class="wpb-sf-pair">
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
            <div class="wpb-schema-panel">
                <div class="wpb-schema-panel-header">
                    <span class="dashicons dashicons-share"></span>
                    <span class="wpb-schema-panel-header-title">Social (sameAs)</span>
                </div>
                <div class="wpb-schema-section">
                    <?php foreach ([
                        'facebook' => ['Facebook', 'https://www.facebook.com/…'],
                        'instagram' => ['Instagram', 'https://www.instagram.com/…'],
                        'twitter' => ['Twitter/X', 'https://www.x.com/…'],
                        'linkedin' => ['LinkedIn', 'https://www.linkedin.com/…'],
                    ] as $field => [$label, $ph]): ?>
                        <div class="wpb-sf-row">
                            <div class="wpb-sf-label"><?php echo $label; ?></div>
                            <div class="wpb-sf-input"><input type="url" name="<?php echo $field; ?>"
                                    value="<?php echo esc_attr($s[$field] ?? ''); ?>"
                                    placeholder="<?php echo esc_attr($ph); ?>"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="wpb-sch-save-btn">
                <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                Salva impostazioni
            </button>
        </form>

        <!-- Anteprima -->
        <div style="margin-top:32px;">
            <h2 style="font-size:14px;font-weight:700;color:#111827;margin:0 0 10px;">Anteprima Schema JSON-LD</h2>
            <?php
            $preview = wpb_schema_build();
            $json = wp_json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $json_hl = preg_replace(
                ['/"([^"]+)"\s*:/', '/"([^"]*)"(?!\s*:)/', '/\b(\d+\.?\d*)\b/', '/\b(true|false|null)\b/'],
                ['<span class="key">"$1"</span>:', '<span class="str">"$1"</span>', '<span class="num">$1</span>', '<span class="bool">$1</span>'],
                htmlspecialchars($json)
            );
            ?>
            <div class="wpb-schema-preview"><?php echo $json_hl; ?></div>
        </div>
    </div>
    <?php
}