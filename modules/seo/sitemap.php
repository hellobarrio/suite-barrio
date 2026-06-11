<?php
/**
 * WP Barrio — SEO / Sitemap
 * Genera e gestisce la sitemap XML del sito.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

if (!defined('WPB_SITEMAP_OPTION'))
    define('WPB_SITEMAP_OPTION', 'wpb_sitemap_settings');

/* ─────────────────────────────────────────
   MENU (nascosto, raggiungibile via URL)
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!wpb_tools_module_enabled('seo_sitemap'))
        return;

    // parent wp-barrio: mantiene il menu aperto e la capability check intatta
    add_submenu_page(
        'wp-barrio',
        'Sitemap — WP Barrio',
        'Sitemap',
        'manage_options',
        'wp-barrio-sitemap',
        'wpb_sitemap_admin_page'
    );
}, 13);

add_action('admin_head', function () {
    echo '<style>#adminmenu a[href="admin.php?page=wp-barrio-sitemap"]{display:none!important}</style>';
});

add_filter('submenu_file', function ($submenu_file) {
    if (isset($_GET['page']) && $_GET['page'] === 'wp-barrio-sitemap')
        return 'wp-barrio-seo';
    return $submenu_file;
});

/* ─────────────────────────────────────────
   SALVATAGGIO
   ───────────────────────────────────────── */
add_action('admin_post_wpb_sitemap_save', function () {
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');
    check_admin_referer('wpb_sitemap_save');

    $post_types = get_post_types(['public' => true], 'names');
    $taxonomies = array_diff(get_taxonomies(['public' => true], 'names'), ['post_format', 'nav_menu', 'link_category']);

    $settings = [];
    foreach ($post_types as $pt) {
        if ($pt === 'attachment')
            continue;
        $settings['post_types'][$pt] = isset($_POST['pt'][$pt]) ? 1 : 0;
    }
    foreach ($taxonomies as $tax) {
        $settings['taxonomies'][$tax] = isset($_POST['tax'][$tax]) ? 1 : 0;
    }

    update_option(WPB_SITEMAP_OPTION, $settings);
    wp_redirect(admin_url('admin.php?page=wp-barrio-sitemap&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   HELPER: calcola la priority SEO di un post
   ───────────────────────────────────────── */
function wpb_sitemap_post_priority(int $post_id, string $post_type, string $lastmod): float
{
    // Homepage
    if (get_option('page_on_front') && (int) get_option('page_on_front') === $post_id)
        return 1.0;
    if (get_option('show_on_front') === 'posts' && $post_type === 'post')
        return 1.0; // blog index è la home

    // Pagine statiche: struttura del sito, alta importanza
    if ($post_type === 'page') {
        // Pagina padre vs pagina figlia
        $parent = wp_get_post_parent_id($post_id);
        return $parent ? 0.7 : 0.8;
    }

    // Post e CPT: priority in base all'età
    $age_days = (time() - strtotime($lastmod)) / DAY_IN_SECONDS;

    if ($post_type === 'post') {
        if ($age_days <= 30)
            return 0.9; // molto recente
        if ($age_days <= 180)
            return 0.8; // ultimi 6 mesi
        if ($age_days <= 365)
            return 0.7; // ultimo anno
        return 0.6;                        // più vecchio di un anno
    }

    // CPT
    if ($age_days <= 30)
        return 0.8;
    if ($age_days <= 180)
        return 0.7;
    return 0.6;
}

/* ─────────────────────────────────────────
   HELPER: calcola la priority SEO di un termine
   ───────────────────────────────────────── */
function wpb_sitemap_term_priority(int $term_id, string $taxonomy): float
{
    $count = (int) (get_term($term_id, $taxonomy)->count ?? 0);
    if ($count >= 20)
        return 0.6;
    if ($count >= 10)
        return 0.5;
    if ($count >= 3)
        return 0.4;
    return 0.3;
}

/* ─────────────────────────────────────────
   HELPER: costruisce gli URL della sitemap
   ───────────────────────────────────────── */
function wpb_sitemap_build_entries()
{
    $settings = get_option(WPB_SITEMAP_OPTION, []);
    $content = get_option(WPB_META_CONTENT_OPTION, []);
    $entries = [];
    $seen_locs = []; // Deduplication per URL

    // Determina le lingue da iterare:
    // se WPML è attivo usa tutte le lingue attive, altrimenti solo [null]
    $wpml_active = defined('ICL_SITEPRESS_VERSION') && function_exists('wpb_multilang_is_enabled') && wpb_multilang_is_enabled();
    $languages = $wpml_active ? wpb_multilang_languages() : [null => null];

    foreach (array_keys($languages) as $lang_code) {

        // Switcha contesto lingua WPML (no-op se WPML non è attivo)
        if ($wpml_active) {
            do_action('wpml_switch_language', $lang_code);
        }

        // ── Post types ────────────────────────────────────────────────────
        $post_types = get_post_types(['public' => true], 'objects');
        foreach ($post_types as $pt_obj) {
            $slug = $pt_obj->name;
            if ($slug === 'attachment')
                continue;

            if (isset($settings['post_types'][$slug])) {
                $include = (bool) $settings['post_types'][$slug];
            } else {
                $include = (bool) ($content['post_types'][$slug]['sitemap'] ?? 1);
            }
            if (!$include)
                continue;

            $posts = get_posts([
                'post_type' => $slug,
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'suppress_filters' => false, // lascia che WPML filtri per lingua
            ]);

            foreach ($posts as $post_id) {
                $post_sitemap = get_post_meta($post_id, '_wpb_seo_sitemap', true);
                if ($post_sitemap === '0')
                    continue;

                $loc = wp_make_link_relative(get_permalink($post_id));
                if (isset($seen_locs[$loc]))
                    continue;
                $seen_locs[$loc] = true;

                $lastmod = get_post_modified_time('c', true, $post_id);
                $priority = wpb_sitemap_post_priority($post_id, $slug, $lastmod);

                $entries[] = [
                    'loc' => $loc,
                    'lastmod' => $lastmod,
                    'type' => 'post',
                    'subtype' => $slug,
                    'id' => $post_id,
                    'title' => get_the_title($post_id),
                    'priority' => $priority,
                    'lang' => $lang_code,
                ];
            }
        }

        // ── Tassonomie ────────────────────────────────────────────────────
        $taxonomies = array_filter(
            get_taxonomies(['public' => true], 'objects'),
            fn($t) => !in_array($t->name, ['post_format', 'nav_menu', 'link_category'], true)
        );

        foreach ($taxonomies as $tax_obj) {
            $slug = $tax_obj->name;

            if (isset($settings['taxonomies'][$slug])) {
                $include = (bool) $settings['taxonomies'][$slug];
            } else {
                $include = (bool) ($content['taxonomies'][$slug]['sitemap'] ?? 1);
            }
            if (!$include)
                continue;

            $terms = get_terms(['taxonomy' => $slug, 'hide_empty' => true]);
            if (is_wp_error($terms))
                continue;

            foreach ($terms as $term) {
                $term_link = get_term_link($term);
                if (is_wp_error($term_link))
                    continue;

                $loc = wp_make_link_relative($term_link);
                if (isset($seen_locs[$loc]))
                    continue;
                $seen_locs[$loc] = true;

                $entries[] = [
                    'loc' => $loc,
                    'lastmod' => null,
                    'type' => 'term',
                    'subtype' => $slug,
                    'id' => $term->term_id,
                    'title' => $term->name,
                    'priority' => wpb_sitemap_term_priority($term->term_id, $slug),
                    'lang' => $lang_code,
                ];
            }
        }
    }

    // Ripristina la lingua corrente WPML
    if ($wpml_active) {
        do_action('wpml_switch_language', null);
    }

    // Ordina per priority decrescente, poi per tipo e titolo
    usort($entries, function ($a, $b) {
        if ($b['priority'] !== $a['priority'])
            return $b['priority'] <=> $a['priority'];
        return strcmp($a['subtype'] . $a['title'], $b['subtype'] . $b['title']);
    });

    return $entries;
}

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function wpb_sitemap_admin_page()
{
    $saved = isset($_GET['saved']);
    $settings = get_option(WPB_SITEMAP_OPTION, []);
    $content = get_option(WPB_META_CONTENT_OPTION, []);

    $post_types = get_post_types(['public' => true], 'objects');
    $taxonomies = array_filter(get_taxonomies(['public' => true], 'objects'), fn($t) => !in_array($t->name, ['post_format', 'nav_menu', 'link_category'], true));
    unset($post_types['attachment']);

    $entries = wpb_sitemap_build_entries();
    $total_url = count($entries);
    ?>
    <style>
        #wpb-sitemap-wrap {
            max-width: 940px;
            font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", sans-serif;
        }

        .wpb-sm-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 8px;
        }

        .wpb-sm-header-icon {
            width: 40px;
            height: 40px;
            background: #111827;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .wpb-sm-header-icon .dashicons {
            font-size: 20px;
            width: 20px;
            height: 20px;
            color: #fff;
        }

        .wpb-sm-header h1 {
            font-size: 22px !important;
            font-weight: 700 !important;
            color: #111827 !important;
            margin: 0 !important;
            padding: 0 !important;
            line-height: 1.2 !important;
        }

        .wpb-sm-breadcrumb {
            font-size: 14px;
            font-weight: 400;
            color: #9ca3af;
            margin-left: 4px;
        }

        .wpb-sm-sub {
            font-size: 13px;
            color: #6b7280;
            margin: 2px 0 0;
        }

        .wpb-sm-divider {
            border: none;
            border-top: 1px solid #f3f4f6;
            margin: 14px 0 24px;
        }

        .wpb-sm-notice-success {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            padding: 13px 16px;
            font-size: 13px;
            color: #166534;
            margin-bottom: 20px;
        }

        .wpb-sm-notice-success .dashicons {
            font-size: 17px;
            width: 17px;
            height: 17px;
            flex-shrink: 0;
            margin-top: 1px;
            color: #059669;
        }

        .wpb-sitemap-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            align-items: start;
        }

        .wpb-panel {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            margin-bottom: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .05);
        }

        .wpb-panel-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 16px;
            background: #f9fafb;
            border-bottom: 1px solid #f3f4f6;
        }

        .wpb-panel-header .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
            color: #6b7280;
            flex-shrink: 0;
        }

        .wpb-panel-header-title {
            font-size: 13px;
            font-weight: 700;
            color: #111827;
        }

        .wpb-panel-header-count {
            margin-left: auto;
            font-size: 11px;
            color: #6b7280;
            background: #f3f4f6;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 600;
        }

        .wpb-sm-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            border-bottom: 1px solid #f3f4f6;
        }

        .wpb-sm-row:last-child {
            border-bottom: none;
        }

        .wpb-sm-info {
            flex: 1;
            min-width: 0;
        }

        .wpb-sm-label {
            font-size: 13px;
            font-weight: 600;
            color: #111827;
        }

        .wpb-sm-slug {
            font-size: 11px;
            color: #9ca3af;
            font-family: ui-monospace, Consolas, monospace;
        }

        .wpb-sm-source {
            font-size: 10px;
            color: #9ca3af;
            background: #f3f4f6;
            padding: 2px 7px;
            border-radius: 20px;
            white-space: nowrap;
            font-weight: 600;
        }

        .wpb-sm-core-badge {
            font-size: 10px;
            background: #dbeafe;
            color: #1e40af;
            padding: 1px 6px;
            border-radius: 20px;
            font-weight: 600;
            margin-left: 4px;
            vertical-align: middle;
        }

        /* Toggle — identico al design system */
        .wpb-toggle {
            position: relative;
            width: 44px;
            height: 24px;
            flex-shrink: 0;
            display: inline-block;
        }

        .wpb-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }

        .wpb-toggle-track {
            position: absolute;
            inset: 0;
            background: #d1d5db;
            border-radius: 24px;
            cursor: pointer;
            transition: background .2s;
        }

        .wpb-toggle-track::before {
            content: "";
            position: absolute;
            width: 18px;
            height: 18px;
            left: 3px;
            top: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform .2s;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .2);
        }

        .wpb-toggle input:checked+.wpb-toggle-track {
            background: #111827;
        }

        .wpb-toggle input:checked+.wpb-toggle-track::before {
            transform: translateX(20px);
        }

        /* Preview */
        .wpb-sitemap-preview {
            position: sticky;
            top: 32px;
        }

        .wpb-sitemap-preview .wpb-panel {
            margin-bottom: 0;
        }

        .wpb-sitemap-entries {
            max-height: 520px;
            overflow-y: auto;
        }

        .wpb-sitemap-entry {
            padding: 8px 16px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 12px;
        }

        .wpb-sitemap-entry:last-child {
            border-bottom: none;
        }

        .wpb-sitemap-entry-url {
            color: #111827;
            text-decoration: none;
            word-break: break-all;
            display: block;
            line-height: 1.4;
            font-family: ui-monospace, Consolas, monospace;
            font-size: 11px;
        }

        .wpb-sitemap-entry-url:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        .wpb-sitemap-entry-meta {
            display: flex;
            gap: 8px;
            margin-top: 3px;
        }

        .wpb-sitemap-entry-type {
            font-size: 10px;
            color: #6b7280;
            background: #f3f4f6;
            padding: 1px 6px;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .wpb-sitemap-entry-date {
            font-size: 10px;
            color: #9ca3af;
        }

        .wpb-sitemap-entry-priority {
            font-size: 10px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 20px;
            margin-left: auto;
        }

        .wpb-priority-10,
        .wpb-priority-09 {
            background: #dcfce7;
            color: #059669;
        }

        .wpb-priority-08 {
            background: #dbeafe;
            color: #1e40af;
        }

        .wpb-priority-07,
        .wpb-priority-06,
        .wpb-priority-05 {
            background: #f3f4f6;
            color: #6b7280;
        }

        .wpb-priority-04,
        .wpb-priority-03 {
            background: #f3f4f6;
            color: #9ca3af;
        }

        .wpb-sitemap-empty {
            padding: 24px 16px;
            text-align: center;
            color: #6b7280;
            font-size: 13px;
        }

        .wpb-sitemap-empty .dashicons {
            font-size: 28px;
            width: 28px;
            height: 28px;
            color: #d1d5db;
            display: block;
            margin: 0 auto 8px;
        }

        .wpb-sm-save-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 20px;
            background: #111827;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            transition: opacity .15s;
            margin-top: 4px;
        }

        .wpb-sm-save-btn:hover {
            opacity: .85;
        }

        .wpb-sm-save-btn .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
        }
    </style>

    <div id="wpb-sitemap-wrap" class="wrap">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
            <div class="wpb-sm-header">
                <div class="wpb-sm-header-icon"><span class="dashicons dashicons-media-document"></span></div>
                <div>
                    <h1>WP Barrio <span class="wpb-sm-breadcrumb">/ SEO / Sitemap</span></h1>
                    <p class="wpb-sm-sub">Configura quali contenuti includere nella sitemap XML<?php if ($total_url > 0): ?>
                            &mdash; <strong><?php echo $total_url; ?> URL</strong> totali<?php endif; ?></p>
                </div>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-seo')); ?>"
                style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;font-size:12.5px;font-weight:600;background:#f3f4f6;color:#374151 !important;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none !important;white-space:nowrap;flex-shrink:0;align-self:flex-start;">
                <span class="dashicons dashicons-arrow-left-alt2"
                    style="font-size:14px;width:14px;height:14px;margin-top:1px;"></span>
                Strumenti SEO
            </a>
        </div>
        <hr class="wpb-sm-divider">

        <?php if ($saved): ?>
            <div class="wpb-sm-notice-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <div>Impostazioni salvate.</div>
            </div>
        <?php endif; ?>

        <div class="wpb-sitemap-layout">

            <!-- Colonna sinistra: config -->
            <div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wpb-sitemap-form">
                    <?php wp_nonce_field('wpb_sitemap_save'); ?>
                    <input type="hidden" name="action" value="wpb_sitemap_save">

                    <!-- Post Types -->
                    <div class="wpb-panel">
                        <div class="wpb-panel-header">
                            <span class="dashicons dashicons-admin-post"></span>
                            <span class="wpb-panel-header-title">Post Type</span>
                            <?php
                            $pt_on = 0;
                            foreach ($post_types as $pt_obj) {
                                $slug = $pt_obj->name;
                                $on = isset($settings['post_types'][$slug]) ? (bool) $settings['post_types'][$slug] : (bool) ($content['post_types'][$slug]['sitemap'] ?? 1);
                                if ($on)
                                    $pt_on++;
                            }
                            ?>
                            <span class="wpb-panel-header-count"><?php echo $pt_on; ?> /
                                <?php echo count($post_types); ?></span>
                        </div>
                        <?php foreach ($post_types as $pt_obj):
                            $slug = $pt_obj->name;
                            $plural = $pt_obj->labels->name ?? $slug;
                            $builtin = $pt_obj->_builtin ?? false;
                            if (isset($settings['post_types'][$slug])) {
                                $checked = (bool) $settings['post_types'][$slug];
                                $source = 'sitemap';
                            } else {
                                $checked = (bool) ($content['post_types'][$slug]['sitemap'] ?? 1);
                                $source = 'meta-tags';
                            }
                            ?>
                            <div class="wpb-sm-row">
                                <div class="wpb-sm-info">
                                    <div class="wpb-sm-label">
                                        <?php echo esc_html($plural); ?>
                                        <?php if ($builtin): ?><span class="wpb-sm-core-badge">core</span><?php endif; ?>
                                    </div>
                                    <span class="wpb-sm-slug"><?php echo esc_html($slug); ?></span>
                                </div>
                                <?php if ($source === 'meta-tags'): ?><span class="wpb-sm-source"
                                        title="Valore ereditato da Meta Tags → Contenuti">da meta tags</span><?php endif; ?>
                                <label class="wpb-toggle">
                                    <input type="checkbox" name="pt[<?php echo esc_attr($slug); ?>]" value="1" <?php checked($checked); ?> class="wpb-sm-toggle" data-type="pt"
                                        data-key="<?php echo esc_attr($slug); ?>">
                                    <span class="wpb-toggle-track"></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Tassonomie -->
                    <div class="wpb-panel">
                        <div class="wpb-panel-header">
                            <span class="dashicons dashicons-tag"></span>
                            <span class="wpb-panel-header-title">Tassonomie</span>
                            <?php
                            $tax_on = 0;
                            foreach ($taxonomies as $tax_obj) {
                                $slug = $tax_obj->name;
                                $on = isset($settings['taxonomies'][$slug]) ? (bool) $settings['taxonomies'][$slug] : (bool) ($content['taxonomies'][$slug]['sitemap'] ?? 1);
                                if ($on)
                                    $tax_on++;
                            }
                            ?>
                            <span class="wpb-panel-header-count"><?php echo $tax_on; ?> /
                                <?php echo count($taxonomies); ?></span>
                        </div>
                        <?php foreach ($taxonomies as $tax_obj):
                            $slug = $tax_obj->name;
                            $label = $tax_obj->labels->name ?? $slug;
                            $builtin = in_array($slug, ['category', 'post_tag', 'nav_menu', 'link_category', 'post_format']);
                            if (isset($settings['taxonomies'][$slug])) {
                                $checked = (bool) $settings['taxonomies'][$slug];
                                $source = 'sitemap';
                            } else {
                                $checked = (bool) ($content['taxonomies'][$slug]['sitemap'] ?? 1);
                                $source = 'meta-tags';
                            }
                            ?>
                            <div class="wpb-sm-row">
                                <div class="wpb-sm-info">
                                    <div class="wpb-sm-label">
                                        <?php echo esc_html($label); ?>
                                        <?php if ($builtin): ?><span class="wpb-sm-core-badge">core</span><?php endif; ?>
                                    </div>
                                    <span class="wpb-sm-slug"><?php echo esc_html($slug); ?></span>
                                </div>
                                <?php if ($source === 'meta-tags'): ?><span class="wpb-sm-source"
                                        title="Valore ereditato da Meta Tags → Contenuti">da meta tags</span><?php endif; ?>
                                <label class="wpb-toggle">
                                    <input type="checkbox" name="tax[<?php echo esc_attr($slug); ?>]" value="1" <?php checked($checked); ?> class="wpb-sm-toggle" data-type="tax"
                                        data-key="<?php echo esc_attr($slug); ?>">
                                    <span class="wpb-toggle-track"></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="wpb-sm-save-btn">
                        <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                        Salva impostazioni
                    </button>
                </form>
            </div>

            <!-- Colonna destra: anteprima -->
            <div class="wpb-sitemap-preview">
                <div class="wpb-panel">
                    <div class="wpb-panel-header">
                        <span class="dashicons dashicons-list-view"></span>
                        <span class="wpb-panel-header-title">Anteprima sitemap</span>
                        <span class="wpb-panel-header-count" id="wpb-preview-count"><?php echo $total_url; ?> URL</span>
                    </div>
                    <div class="wpb-sitemap-entries" id="wpb-sitemap-entries">
                        <?php if (empty($entries)): ?>
                            <div class="wpb-sitemap-empty">
                                <span class="dashicons dashicons-media-document"></span>
                                Nessun contenuto incluso nella sitemap.
                            </div>
                        <?php else: ?>
                            <?php foreach ($entries as $entry):
                                $url = is_wp_error($entry['loc']) ? '' : $entry['loc'];
                                if (empty($url))
                                    continue;
                                $p = $entry['priority'];
                                $pkey = 'wpb-priority-' . str_replace('.', '', number_format($p, 1));
                                ?>
                                <div class="wpb-sitemap-entry" data-type="<?php echo esc_attr($entry['type']); ?>"
                                    data-subtype="<?php echo esc_attr($entry['subtype']); ?>">
                                    <a href="<?php echo esc_url($url); ?>" class="wpb-sitemap-entry-url" target="_blank">
                                        <?php echo esc_html(str_replace(home_url(), '', $url) ?: '/'); ?>
                                    </a>
                                    <div class="wpb-sitemap-entry-meta">
                                        <span class="wpb-sitemap-entry-type"><?php echo esc_html($entry['subtype']); ?></span>
                                        <?php if ($entry['lastmod']): ?><span
                                                class="wpb-sitemap-entry-date"><?php echo esc_html(date_i18n('d/m/Y', strtotime($entry['lastmod']))); ?></span><?php endif; ?>
                                        <span
                                            class="wpb-sitemap-entry-priority <?php echo esc_attr($pkey); ?>"><?php echo number_format($p, 1); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        (function () {
            var toggles = document.querySelectorAll('.wpb-sm-toggle');
            var countEl = document.getElementById('wpb-preview-count');
            var entries = document.querySelectorAll('.wpb-sitemap-entry');
            toggles.forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var type = this.dataset.type, key = this.dataset.key, on = this.checked;
                    entries.forEach(function (el) {
                        if (el.dataset.type === (type === 'pt' ? 'post' : 'term') && el.dataset.subtype === key)
                            el.style.display = on ? '' : 'none';
                    });
                    var visible = document.querySelectorAll('.wpb-sitemap-entry:not([style*="display: none"])').length;
                    countEl.textContent = visible + ' URL';
                });
            });
        })();
    </script>
    <?php
}