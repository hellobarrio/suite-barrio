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

add_action('admin_enqueue_scripts', function ($hook) {
    wp_add_inline_style(
        'suite-barrio-admin',
        '#adminmenu a[href="admin.php?page=suite-barrio-schema"]{display:none!important}'
    );

    // Media uploader per le immagini del catalogo offerte
    if ($hook === 'suite-barrio_page_suite-barrio-schema')
        wp_enqueue_media();
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
        'behance' => esc_url_raw(wp_unslash($_POST['behance'] ?? '')),
        'knows_about' => sanitize_textarea_field(wp_unslash($_POST['knows_about'] ?? '')),
        'area_served' => sanitize_textarea_field(wp_unslash($_POST['area_served'] ?? '')),
        'language' => sanitize_text_field(wp_unslash($_POST['language'] ?? 'it-IT')),
        'offer_catalog_name' => sanitize_text_field(wp_unslash($_POST['offer_catalog_name'] ?? '')),
        'offer_currency' => sanitize_text_field(wp_unslash($_POST['offer_currency'] ?? 'EUR')),
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ogni campo è sanificato in suite_barrio_schema_sanitize_offers()
        'offers' => suite_barrio_schema_sanitize_offers((array) ($_POST['offers'] ?? [])),
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
   HELPER: textarea → array (una voce per riga)
   ───────────────────────────────────────── */
function suite_barrio_schema_lines(string $raw): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw))));
}

/* ─────────────────────────────────────────
   HELPER: @type ammessi per areaServed
   ───────────────────────────────────────── */
function suite_barrio_schema_place_types(): array
{
    return ['Place', 'Text', 'GeoShape', 'AdministrativeArea'];
}

/* ─────────────────────────────────────────
   HELPER: catalogo offerte — valori ammessi
   ───────────────────────────────────────── */
function suite_barrio_schema_offer_item_types(): array
{
    return ['Service', 'Product'];
}

function suite_barrio_schema_offer_availability(): array
{
    return [
        '' => '— non specificata —',
        'InStock' => 'InStock — disponibile',
        'OutOfStock' => 'OutOfStock — esaurito',
        'PreOrder' => 'PreOrder — preordine',
        'LimitedAvailability' => 'LimitedAvailability — disponibilità limitata',
        'Discontinued' => 'Discontinued — non più offerto',
    ];
}

function suite_barrio_schema_offer_currencies(): array
{
    return ['EUR', 'USD', 'GBP', 'CHF'];
}

/* ─────────────────────────────────────────
   HELPER: sanitizzazione righe catalogo offerte
   ───────────────────────────────────────── */
function suite_barrio_schema_sanitize_offers(array $rows): array
{
    $offers = [];
    foreach ($rows as $row) {
        if (!is_array($row))
            continue;

        $name = sanitize_text_field(wp_unslash($row['name'] ?? ''));
        if ($name === '')
            continue;

        $type = sanitize_text_field(wp_unslash($row['type'] ?? ''));
        $availability = sanitize_text_field(wp_unslash($row['availability'] ?? ''));

        $offers[] = [
            'type' => in_array($type, suite_barrio_schema_offer_item_types(), true) ? $type : 'Service',
            'name' => $name,
            'description' => sanitize_textarea_field(wp_unslash($row['description'] ?? '')),
            'category' => sanitize_text_field(wp_unslash($row['category'] ?? '')),
            'url' => esc_url_raw(wp_unslash($row['url'] ?? '')),
            'image' => esc_url_raw(wp_unslash($row['image'] ?? '')),
            'price' => sanitize_text_field(wp_unslash($row['price'] ?? '')),
            'availability' => isset(suite_barrio_schema_offer_availability()[$availability]) ? $availability : '',
        ];
    }
    return $offers;
}

/* ─────────────────────────────────────────
   HELPER: nodo OfferCatalog (hasOfferCatalog)
   https://schema.org/hasOfferCatalog
   ───────────────────────────────────────── */
function suite_barrio_schema_build_offer_catalog(array $s): ?array
{
    $rows = $s['offers'] ?? [];
    if (!is_array($rows) || empty($rows))
        return null;

    $currency = ($s['offer_currency'] ?? '') ?: 'EUR';
    $items = [];

    foreach ($rows as $row) {
        $name = trim($row['name'] ?? '');
        if ($name === '')
            continue;

        $item = array_filter([
            '@type' => in_array($row['type'] ?? '', suite_barrio_schema_offer_item_types(), true) ? $row['type'] : 'Service',
            'name' => $name,
            'description' => $row['description'] ?? '',
            'category' => $row['category'] ?? '',
            'url' => $row['url'] ?? '',
            'image' => $row['image'] ?? '',
        ]);

        $offer = ['@type' => 'Offer', 'itemOffered' => $item];
        if (!empty($row['url']))
            $offer['url'] = $row['url'];

        // ponytail: solo prezzo fisso; per fasce usare priceSpecification se serviranno min/max
        $price = str_replace(',', '.', trim($row['price'] ?? ''));
        if (is_numeric($price)) {
            $offer['price'] = $price;
            $offer['priceCurrency'] = $currency;
        }

        if (!empty($row['availability']))
            $offer['availability'] = 'https://schema.org/' . $row['availability'];

        $items[] = $offer;
    }

    if (empty($items))
        return null;

    return [
        '@type' => 'OfferCatalog',
        'name' => ($s['offer_catalog_name'] ?? '') ?: 'Servizi',
        'itemListElement' => $items,
    ];
}

/* ─────────────────────────────────────────
   HELPER: ItemList dei contenuti di un post type
   (per le pagine marcate come archivio/CollectionPage)
   ───────────────────────────────────────── */
function suite_barrio_schema_item_list(string $post_type, callable $to_frontend): ?array
{
    // ponytail: cap a 200 elementi, paginare l'ItemList se un archivio cresce oltre
    $posts = get_posts([
        'post_type' => $post_type,
        'post_status' => 'publish',
        'numberposts' => 200,
        'orderby' => ['menu_order' => 'ASC', 'date' => 'DESC'],
        'suppress_filters' => false,
    ]);
    if (empty($posts))
        return null;

    $items = [];
    foreach ($posts as $p) {
        if (get_post_meta($p->ID, '_suite_barrio_seo_index', true) === '0')
            continue;
        $items[] = [
            '@type' => 'ListItem',
            'position' => count($items) + 1,
            'name' => get_post_meta($p->ID, '_suite_barrio_seo_title', true) ?: get_the_title($p),
            'url' => $to_frontend(get_permalink($p)),
        ];
    }
    if (empty($items))
        return null;

    return [
        '@type' => 'ItemList',
        'numberOfItems' => count($items),
        'itemListOrder' => 'https://schema.org/ItemListOrderAscending',
        'itemListElement' => $items,
    ];
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

    $knows_about = suite_barrio_schema_lines($s['knows_about'] ?? '');
    if (!empty($knows_about))
        $business['knowsAbout'] = $knows_about;

    $area_served = suite_barrio_schema_lines($s['area_served'] ?? '');
    if (!empty($area_served)) {
        $business['areaServed'] = array_map(function ($line) {
            // formato "Tipo: Nome", es. "City: Torino"; senza prefisso valido → Place
            [$type, $name] = array_pad(array_map('trim', explode(':', $line, 2)), 2, '');
            if ($name === '' || !in_array($type, suite_barrio_schema_place_types(), true))
                return ['@type' => 'Place', 'name' => $line];
            return ['@type' => $type, 'name' => $name];
        }, $area_served);
    }

    $same_as = array_values(array_filter([
        $s['facebook'] ?? '',
        $s['instagram'] ?? '',
        $s['twitter'] ?? '',
        $s['linkedin'] ?? '',
        $s['behance'] ?? '',
    ]));
    if (!empty($same_as))
        $business['sameAs'] = $same_as;

    $catalog = suite_barrio_schema_build_offer_catalog($s);
    if ($catalog)
        $business['hasOfferCatalog'] = $catalog;

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

        // ── Pagina archivio: CollectionPage + ItemList ────────────────────
        $collection_pt = get_post_meta($post_id, '_suite_barrio_schema_collection', true);
        if ($collection_pt && post_type_exists($collection_pt)) {
            $item_list = suite_barrio_schema_item_list($collection_pt, $to_frontend);
            if ($item_list) {
                $page['@type'] = 'CollectionPage';
                $page['mainEntity'] = $item_list;
            }
        }

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
   ADMIN: riga del catalogo offerte
   $i = indice numerico, oppure __i__ per il template
   ───────────────────────────────────────── */
function suite_barrio_schema_offer_row(string $i, array $row = []): void
{
    $n = 'offers[' . $i . ']';
    ?>
    <div class="suite-barrio-offer-row">
        <div class="suite-barrio-offer-grid">
            <label>Tipo
                <select name="<?php echo esc_attr($n); ?>[type]">
                    <?php foreach (suite_barrio_schema_offer_item_types() as $t): ?>
                        <option value="<?php echo esc_attr($t); ?>" <?php selected($row['type'] ?? 'Service', $t); ?>>
                            <?php echo esc_html($t); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="suite-barrio-offer-wide">Nome <small>obbligatorio</small>
                <input type="text" name="<?php echo esc_attr($n); ?>[name]"
                    value="<?php echo esc_attr($row['name'] ?? ''); ?>" placeholder="Progettazione siti web">
            </label>
            <label>Categoria
                <input type="text" name="<?php echo esc_attr($n); ?>[category]"
                    value="<?php echo esc_attr($row['category'] ?? ''); ?>" placeholder="Web">
            </label>
            <label>Prezzo <small>vuoto = omesso</small>
                <input type="number" name="<?php echo esc_attr($n); ?>[price]" step="0.01" min="0"
                    value="<?php echo esc_attr($row['price'] ?? ''); ?>" placeholder="1500">
            </label>
            <label>Disponibilità
                <select name="<?php echo esc_attr($n); ?>[availability]">
                    <?php foreach (suite_barrio_schema_offer_availability() as $val => $label): ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php selected($row['availability'] ?? '', $val); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="suite-barrio-offer-wide">URL
                <input type="url" name="<?php echo esc_attr($n); ?>[url]"
                    value="<?php echo esc_attr($row['url'] ?? ''); ?>" placeholder="https://…/servizi/web/">
            </label>
            <?php $img = $row['image'] ?? ''; ?>
            <div class="suite-barrio-offer-wide suite-barrio-offer-media">
                <span class="suite-barrio-offer-media-label">Immagine</span>
                <div class="suite-barrio-offer-media-box">
                    <span class="suite-barrio-offer-thumb">
                        <?php if ($img): ?>
                            <img src="<?php echo esc_url($img); ?>" alt="">
                        <?php else: ?>
                            <span class="dashicons dashicons-format-image"></span>
                        <?php endif; ?>
                    </span>
                    <input type="hidden" name="<?php echo esc_attr($n); ?>[image]"
                        value="<?php echo esc_attr($img); ?>">
                    <button type="button" class="button button-small suite-barrio-offer-img-pick">
                        <?php echo $img ? 'Cambia' : 'Seleziona'; ?>
                    </button>
                    <button type="button" class="button button-small suite-barrio-offer-img-clear"
                        <?php echo $img ? '' : 'hidden'; ?>>&times;</button>
                </div>
            </div>
            <label class="suite-barrio-offer-full">Descrizione
                <textarea name="<?php echo esc_attr($n); ?>[description]"
                    rows="2"><?php echo esc_textarea($row['description'] ?? ''); ?></textarea>
            </label>
        </div>
        <button type="button" class="suite-barrio-offer-del" title="Rimuovi offerta">
            <span class="dashicons dashicons-trash"></span>
        </button>
    </div>
    <?php
}

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
        'LodgingBusiness' => 'LodgingBusiness',
        'ProfessionalService' => 'ProfessionalService',
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
                        <div class="suite-barrio-sf-label">Competenze<small>knowsAbout &mdash; una per riga</small></div>
                        <div class="suite-barrio-sf-input"><textarea name="knows_about" rows="4"
                                placeholder="Web design&#10;Branding&#10;E-commerce"><?php echo esc_textarea($s['knows_about'] ?? ''); ?></textarea>
                        </div>
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
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Aree servite<small>areaServed &mdash; una per riga, formato
                                &laquo;Tipo: Nome&raquo;<br>Tipi: <?php echo esc_html(implode(', ', suite_barrio_schema_place_types())); ?><br>Senza
                                prefisso valido &rarr; Place</small></div>
                        <div class="suite-barrio-sf-input"><textarea name="area_served" rows="4"
                                placeholder="City: Torino&#10;AdministrativeArea: Piemonte&#10;Country: Italia"><?php echo esc_textarea($s['area_served'] ?? ''); ?></textarea>
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
                        'behance' => ['Behance', 'https://www.behance.net/…'],
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

            <!-- Catalogo offerte -->
            <?php $offers = is_array($s['offers'] ?? null) ? $s['offers'] : []; ?>
            <div class="suite-barrio-schema-panel">
                <div class="suite-barrio-schema-panel-header">
                    <span class="dashicons dashicons-cart"></span>
                    <span class="suite-barrio-schema-panel-header-title">Catalogo offerte (hasOfferCatalog)</span>
                </div>
                <div class="suite-barrio-schema-section">
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Nome catalogo<small>Es. Servizi, Prodotti</small></div>
                        <div class="suite-barrio-sf-input"><input type="text" name="offer_catalog_name"
                                value="<?php echo esc_attr($s['offer_catalog_name'] ?? ''); ?>" placeholder="Servizi">
                        </div>
                    </div>
                    <div class="suite-barrio-sf-row">
                        <div class="suite-barrio-sf-label">Valuta<small>priceCurrency delle offerte</small></div>
                        <div class="suite-barrio-sf-input">
                            <select name="offer_currency" style="max-width:120px;">
                                <?php foreach (suite_barrio_schema_offer_currencies() as $cur): ?>
                                    <option value="<?php echo esc_attr($cur); ?>" <?php selected(($s['offer_currency'] ?? '') ?: 'EUR', $cur); ?>><?php echo esc_html($cur); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="suite-barrio-offers">
                        <?php foreach (array_values($offers) as $i => $row): ?>
                            <?php suite_barrio_schema_offer_row((string) $i, (array) $row); ?>
                        <?php endforeach; ?>
                    </div>

                    <template id="suite-barrio-offer-tpl"><?php suite_barrio_schema_offer_row('__i__'); ?></template>

                    <button type="button" class="suite-barrio-offer-add" id="suite-barrio-offer-add">+ Aggiungi
                        offerta</button>
                </div>
            </div>

            <button type="submit" class="suite-barrio-sch-save-btn">
                <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                Salva impostazioni
            </button>
        </form>

        <script>
            (function () {
                var wrap = document.getElementById('suite-barrio-offers');
                var tpl = document.getElementById('suite-barrio-offer-tpl');
                var next = <?php echo (int) count($offers); ?>;

                document.getElementById('suite-barrio-offer-add').addEventListener('click', function () {
                    wrap.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, next++));
                });

                var frame = null;

                function paint(box, url) {
                    box.querySelector('input[type=hidden]').value = url;
                    box.querySelector('.suite-barrio-offer-thumb').innerHTML = url
                        ? '<img src="' + url + '" alt="">'
                        : '<span class="dashicons dashicons-format-image"></span>';
                    box.querySelector('.suite-barrio-offer-img-pick').textContent = url ? 'Cambia' : 'Seleziona';
                    box.querySelector('.suite-barrio-offer-img-clear').hidden = !url;
                }

                wrap.addEventListener('click', function (e) {
                    var del = e.target.closest('.suite-barrio-offer-del');
                    if (del) {
                        del.closest('.suite-barrio-offer-row').remove();
                        return;
                    }

                    var clear = e.target.closest('.suite-barrio-offer-img-clear');
                    if (clear) {
                        paint(clear.closest('.suite-barrio-offer-media-box'), '');
                        return;
                    }

                    var pick = e.target.closest('.suite-barrio-offer-img-pick');
                    if (!pick) return;

                    var box = pick.closest('.suite-barrio-offer-media-box');
                    if (!frame) {
                        frame = wp.media({
                            title: 'Seleziona immagine servizio',
                            button: { text: 'Usa questa immagine' },
                            multiple: false,
                            library: { type: 'image' }
                        });
                    }
                    frame.off('select');
                    frame.on('select', function () {
                        var att = frame.state().get('selection').first().toJSON();
                        paint(box, att.url);
                    });
                    frame.open();
                });
            })();
        </script>

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
