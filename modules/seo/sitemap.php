<?php
/**
 * Suite Barrio — SEO / Sitemap
 * Genera e gestisce la sitemap XML del sito.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

if (!defined('SUITE_BARRIO_SITEMAP_OPTION'))
    define('SUITE_BARRIO_SITEMAP_OPTION', 'wpb_sitemap_settings');

/* ─────────────────────────────────────────
   MENU (nascosto, raggiungibile via URL)
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!suite_barrio_tools_module_enabled('seo_sitemap'))
        return;

    // parent suite-barrio: mantiene il menu aperto e la capability check intatta
    add_submenu_page(
        'suite-barrio',
        'Sitemap — Suite Barrio',
        'Sitemap',
        'manage_options',
        'suite-barrio-sitemap',
        'suite_barrio_sitemap_admin_page'
    );
}, 13);

add_action('admin_enqueue_scripts', function ($hook) {
    // Nasconde la voce di menu "suite-barrio-sitemap" (pagina raggiungibile solo via URL diretto).
    wp_add_inline_style('suite-barrio-admin', '#adminmenu a[href="admin.php?page=suite-barrio-sitemap"]{display:none!important}');

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    if (!isset($_GET['page']) || $_GET['page'] !== 'suite-barrio-sitemap')
        return;

    wp_register_script('suite-barrio-sitemap-admin', '', [], SUITE_BARRIO_VERSION, true);
    wp_enqueue_script('suite-barrio-sitemap-admin');
    wp_add_inline_script('suite-barrio-sitemap-admin', '(function () {
    var toggles = document.querySelectorAll(\'.suite-barrio-sm-toggle\');
    var countEl = document.getElementById(\'suite-barrio-preview-count\');
    var entries = document.querySelectorAll(\'.suite-barrio-sitemap-entry\');
    toggles.forEach(function (cb) {
        cb.addEventListener(\'change\', function () {
            var type = this.dataset.type, key = this.dataset.key, on = this.checked;
            entries.forEach(function (el) {
                if (el.dataset.type === (type === \'pt\' ? \'post\' : \'term\') && el.dataset.subtype === key)
                    el.style.display = on ? \'\' : \'none\';
            });
            var visible = document.querySelectorAll(\'.suite-barrio-sitemap-entry:not([style*="display: none"])\').length;
            countEl.textContent = visible + \' URL\';
        });
    });
})();'
    );
});

add_filter('submenu_file', function ($submenu_file) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    if (isset($_GET['page']) && $_GET['page'] === 'suite-barrio-sitemap')
        return 'suite-barrio-seo';
    return $submenu_file;
});

/* ─────────────────────────────────────────
   SALVATAGGIO
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_sitemap_save', function () {
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');
    check_admin_referer('suite_barrio_sitemap_save');

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

    update_option(SUITE_BARRIO_SITEMAP_OPTION, $settings);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-sitemap&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   HELPER: calcola la priority SEO di un post
   ───────────────────────────────────────── */
function suite_barrio_sitemap_post_priority(int $post_id, string $post_type, string $lastmod): float
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
function suite_barrio_sitemap_term_priority(int $term_id, string $taxonomy): float
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
function suite_barrio_sitemap_build_entries()
{
    $settings = get_option(SUITE_BARRIO_SITEMAP_OPTION, []);
    $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);
    $entries = [];
    $seen_locs = []; // Deduplication per URL

    // Determina le lingue da iterare:
    // se WPML è attivo usa tutte le lingue attive, altrimenti solo [null]
    $wpml_active = defined('ICL_SITEPRESS_VERSION') && function_exists('suite_barrio_multilang_is_enabled') && suite_barrio_multilang_is_enabled();
    $languages = $wpml_active ? suite_barrio_multilang_languages() : [null => null];

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
                $priority = suite_barrio_sitemap_post_priority($post_id, $slug, $lastmod);

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
                    'priority' => suite_barrio_sitemap_term_priority($term->term_id, $slug),
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
function suite_barrio_sitemap_admin_page()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione (notice "salvato"), nessun nonce esistente per questa azione
    $saved = isset($_GET['saved']);
    $settings = get_option(SUITE_BARRIO_SITEMAP_OPTION, []);
    $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);

    $post_types = get_post_types(['public' => true], 'objects');
    $taxonomies = array_filter(get_taxonomies(['public' => true], 'objects'), fn($t) => !in_array($t->name, ['post_format', 'nav_menu', 'link_category'], true));
    unset($post_types['attachment']);

    $entries = suite_barrio_sitemap_build_entries();
    $total_url = count($entries);
    ?>
<div id="suite-barrio-sitemap-wrap" class="wrap suite-barrio-wrap">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
            <div class="suite-barrio-sm-header">
                <div class="suite-barrio-sm-header-icon"><span class="dashicons dashicons-media-document"></span></div>
                <div>
                    <h1>Suite Barrio <span class="suite-barrio-sm-breadcrumb">/ SEO / Sitemap</span></h1>
                    <p class="suite-barrio-sm-sub">Configura quali contenuti includere nella sitemap XML<?php if ($total_url > 0): ?>
                            &mdash; <strong><?php echo esc_html($total_url); ?> URL</strong> totali<?php endif; ?></p>
                </div>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-seo')); ?>"
                style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;font-size:12.5px;font-weight:600;background:#f3f4f6;color:#374151 !important;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none !important;white-space:nowrap;flex-shrink:0;align-self:flex-start;">
                <span class="dashicons dashicons-arrow-left-alt2"
                    style="font-size:14px;width:14px;height:14px;margin-top:1px;"></span>
                Strumenti SEO
            </a>
        </div>
        <hr class="suite-barrio-sm-divider">

        <?php if ($saved): ?>
            <div class="suite-barrio-sm-notice-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <div>Impostazioni salvate.</div>
            </div>
        <?php endif; ?>

        <div class="suite-barrio-sitemap-layout">

            <!-- Colonna sinistra: config -->
            <div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="suite-barrio-sitemap-form">
                    <?php wp_nonce_field('suite_barrio_sitemap_save'); ?>
                    <input type="hidden" name="action" value="suite_barrio_sitemap_save">

                    <!-- Post Types -->
                    <div class="suite-barrio-panel">
                        <div class="suite-barrio-panel-header">
                            <span class="dashicons dashicons-admin-post"></span>
                            <span class="suite-barrio-panel-header-title">Post Type</span>
                            <?php
                            $pt_on = 0;
                            foreach ($post_types as $pt_obj) {
                                $slug = $pt_obj->name;
                                $on = isset($settings['post_types'][$slug]) ? (bool) $settings['post_types'][$slug] : (bool) ($content['post_types'][$slug]['sitemap'] ?? 1);
                                if ($on)
                                    $pt_on++;
                            }
                            ?>
                            <span class="suite-barrio-panel-header-count"><?php echo esc_html($pt_on); ?> /
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
                            <div class="suite-barrio-sm-row">
                                <div class="suite-barrio-sm-info">
                                    <div class="suite-barrio-sm-label">
                                        <?php echo esc_html($plural); ?>
                                        <?php if ($builtin): ?><span class="suite-barrio-sm-core-badge">core</span><?php endif; ?>
                                    </div>
                                    <span class="suite-barrio-sm-slug"><?php echo esc_html($slug); ?></span>
                                </div>
                                <?php if ($source === 'meta-tags'): ?><span class="suite-barrio-sm-source"
                                        title="Valore ereditato da Meta Tags → Contenuti">da meta tags</span><?php endif; ?>
                                <label class="suite-barrio-toggle">
                                    <input type="checkbox" name="pt[<?php echo esc_attr($slug); ?>]" value="1" <?php checked($checked); ?> class="suite-barrio-sm-toggle" data-type="pt"
                                        data-key="<?php echo esc_attr($slug); ?>">
                                    <span class="suite-barrio-toggle-track"></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Tassonomie -->
                    <div class="suite-barrio-panel">
                        <div class="suite-barrio-panel-header">
                            <span class="dashicons dashicons-tag"></span>
                            <span class="suite-barrio-panel-header-title">Tassonomie</span>
                            <?php
                            $tax_on = 0;
                            foreach ($taxonomies as $tax_obj) {
                                $slug = $tax_obj->name;
                                $on = isset($settings['taxonomies'][$slug]) ? (bool) $settings['taxonomies'][$slug] : (bool) ($content['taxonomies'][$slug]['sitemap'] ?? 1);
                                if ($on)
                                    $tax_on++;
                            }
                            ?>
                            <span class="suite-barrio-panel-header-count"><?php echo esc_html($tax_on); ?> /
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
                            <div class="suite-barrio-sm-row">
                                <div class="suite-barrio-sm-info">
                                    <div class="suite-barrio-sm-label">
                                        <?php echo esc_html($label); ?>
                                        <?php if ($builtin): ?><span class="suite-barrio-sm-core-badge">core</span><?php endif; ?>
                                    </div>
                                    <span class="suite-barrio-sm-slug"><?php echo esc_html($slug); ?></span>
                                </div>
                                <?php if ($source === 'meta-tags'): ?><span class="suite-barrio-sm-source"
                                        title="Valore ereditato da Meta Tags → Contenuti">da meta tags</span><?php endif; ?>
                                <label class="suite-barrio-toggle">
                                    <input type="checkbox" name="tax[<?php echo esc_attr($slug); ?>]" value="1" <?php checked($checked); ?> class="suite-barrio-sm-toggle" data-type="tax"
                                        data-key="<?php echo esc_attr($slug); ?>">
                                    <span class="suite-barrio-toggle-track"></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="submit" class="suite-barrio-sm-save-btn">
                        <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                        Salva impostazioni
                    </button>
                </form>
            </div>

            <!-- Colonna destra: anteprima -->
            <div class="suite-barrio-sitemap-preview">
                <div class="suite-barrio-panel">
                    <div class="suite-barrio-panel-header">
                        <span class="dashicons dashicons-list-view"></span>
                        <span class="suite-barrio-panel-header-title">Anteprima sitemap</span>
                        <span class="suite-barrio-panel-header-count" id="suite-barrio-preview-count"><?php echo esc_html($total_url); ?> URL</span>
                    </div>
                    <div class="suite-barrio-sitemap-entries" id="suite-barrio-sitemap-entries">
                        <?php if (empty($entries)): ?>
                            <div class="suite-barrio-sitemap-empty">
                                <span class="dashicons dashicons-media-document"></span>
                                Nessun contenuto incluso nella sitemap.
                            </div>
                        <?php else: ?>
                            <?php foreach ($entries as $entry):
                                $url = is_wp_error($entry['loc']) ? '' : $entry['loc'];
                                if (empty($url))
                                    continue;
                                $p = $entry['priority'];
                                $pkey = 'suite-barrio-priority-' . str_replace('.', '', number_format($p, 1));
                                ?>
                                <div class="suite-barrio-sitemap-entry" data-type="<?php echo esc_attr($entry['type']); ?>"
                                    data-subtype="<?php echo esc_attr($entry['subtype']); ?>">
                                    <a href="<?php echo esc_url($url); ?>" class="suite-barrio-sitemap-entry-url" target="_blank">
                                        <?php echo esc_html(str_replace(home_url(), '', $url) ?: '/'); ?>
                                    </a>
                                    <div class="suite-barrio-sitemap-entry-meta">
                                        <span class="suite-barrio-sitemap-entry-type"><?php echo esc_html($entry['subtype']); ?></span>
                                        <?php if ($entry['lastmod']): ?><span
                                                class="suite-barrio-sitemap-entry-date"><?php echo esc_html(date_i18n('d/m/Y', strtotime($entry['lastmod']))); ?></span><?php endif; ?>
                                        <span
                                            class="suite-barrio-sitemap-entry-priority <?php echo esc_attr($pkey); ?>"><?php echo number_format($p, 1); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <?php
}