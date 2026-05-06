<?php
/**
 * WP Barrio — SEO / Sitemap
 * Genera e gestisce la sitemap XML del sito.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

if (!defined('WPB_SITEMAP_OPTION')) define('WPB_SITEMAP_OPTION', 'wpb_sitemap_settings');

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
    if (!current_user_can('manage_options')) wp_die('Accesso negato.');
    check_admin_referer('wpb_sitemap_save');

    $post_types = get_post_types(['public' => true], 'names');
    $taxonomies = array_diff(get_taxonomies(['public' => true], 'names'), ['post_format', 'nav_menu', 'link_category']);

    $settings = [];
    foreach ($post_types as $pt) {
        if ($pt === 'attachment') continue;
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
        if ($age_days <= 30)  return 0.9; // molto recente
        if ($age_days <= 180) return 0.8; // ultimi 6 mesi
        if ($age_days <= 365) return 0.7; // ultimo anno
        return 0.6;                        // più vecchio di un anno
    }

    // CPT
    if ($age_days <= 30)  return 0.8;
    if ($age_days <= 180) return 0.7;
    return 0.6;
}

/* ─────────────────────────────────────────
   HELPER: calcola la priority SEO di un termine
   ───────────────────────────────────────── */
function wpb_sitemap_term_priority(int $term_id, string $taxonomy): float
{
    $count = (int) (get_term($term_id, $taxonomy)->count ?? 0);
    if ($count >= 20) return 0.6;
    if ($count >= 10) return 0.5;
    if ($count >= 3)  return 0.4;
    return 0.3;
}

/* ─────────────────────────────────────────
   HELPER: costruisce gli URL della sitemap
   ───────────────────────────────────────── */
function wpb_sitemap_build_entries()
{
    $settings = get_option(WPB_SITEMAP_OPTION, []);
    $content  = get_option(WPB_META_CONTENT_OPTION, []);
    $entries  = [];

    // Post types
    $post_types = get_post_types(['public' => true], 'objects');
    foreach ($post_types as $pt_obj) {
        $slug = $pt_obj->name;
        if ($slug === 'attachment') continue;

        // Priorità: override sitemap.php → config meta-tags → default on
        if (isset($settings['post_types'][$slug])) {
            $include = (bool) $settings['post_types'][$slug];
        } else {
            $include = (bool) ($content['post_types'][$slug]['sitemap'] ?? 1);
        }
        if (!$include) continue;

        $posts = get_posts([
            'post_type'      => $slug,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        foreach ($posts as $post_id) {
            // Rispetta override per-post di sitemap
            $post_sitemap = get_post_meta($post_id, '_wpb_seo_sitemap', true);
            if ($post_sitemap === '0') continue;

            $lastmod  = get_post_modified_time('c', true, $post_id);
            $priority = wpb_sitemap_post_priority($post_id, $slug, $lastmod);

            $entries[] = [
                'loc'      => wp_make_link_relative(get_permalink($post_id)),
                'lastmod'  => $lastmod,
                'type'     => 'post',
                'subtype'  => $slug,
                'id'       => $post_id,
                'title'    => get_the_title($post_id),
                'priority' => $priority,
            ];
        }
    }

    // Tassonomie
    $taxonomies = array_filter(get_taxonomies(['public' => true], 'objects'), fn($t) => !in_array($t->name, ['post_format', 'nav_menu', 'link_category'], true));
    foreach ($taxonomies as $tax_obj) {
        $slug = $tax_obj->name;

        if (isset($settings['taxonomies'][$slug])) {
            $include = (bool) $settings['taxonomies'][$slug];
        } else {
            $include = (bool) ($content['taxonomies'][$slug]['sitemap'] ?? 1);
        }
        if (!$include) continue;

        $terms = get_terms(['taxonomy' => $slug, 'hide_empty' => true]);
        if (is_wp_error($terms)) continue;

        foreach ($terms as $term) {
            $term_link = get_term_link($term);
            if (is_wp_error($term_link)) continue;

            $entries[] = [
                'loc'      => wp_make_link_relative($term_link),
                'lastmod'  => null,
                'type'     => 'term',
                'subtype'  => $slug,
                'id'       => $term->term_id,
                'title'    => $term->name,
                'priority' => wpb_sitemap_term_priority($term->term_id, $slug),
            ];
        }
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
    $saved    = isset($_GET['saved']);
    $settings = get_option(WPB_SITEMAP_OPTION, []);
    $content  = get_option(WPB_META_CONTENT_OPTION, []);

    $post_types = get_post_types(['public' => true], 'objects');
    $taxonomies = array_filter(get_taxonomies(['public' => true], 'objects'), fn($t) => !in_array($t->name, ['post_format', 'nav_menu', 'link_category'], true));
    unset($post_types['attachment']);

    $entries   = wpb_sitemap_build_entries();
    $total_url = count($entries);
    ?>
    <style>
        #wpb-sitemap-wrap { max-width: 940px; }

        .wpb-sitemap-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            align-items: start;
        }

        /* ── Panel ─────────────────────────── */
        .wpb-panel {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            margin-bottom: 16px;
            overflow: hidden;
        }
        .wpb-panel-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: #f6f7f7;
            border-bottom: 1px solid #f0f0f1;
        }
        .wpb-panel-header .dashicons {
            font-size: 16px; width: 16px; height: 16px;
            color: #2271b1; flex-shrink: 0;
        }
        .wpb-panel-header-title {
            font-size: 13px; font-weight: 600; color: #1d2327;
        }
        .wpb-panel-header-count {
            margin-left: auto;
            font-size: 11px; color: #646970;
            background: #f0f0f1;
            padding: 2px 8px; border-radius: 10px;
        }

        /* ── Toggle row ────────────────────── */
        .wpb-sm-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            border-bottom: 1px solid #f0f0f1;
        }
        .wpb-sm-row:last-child { border-bottom: none; }
        .wpb-sm-info { flex: 1; min-width: 0; }
        .wpb-sm-label {
            font-size: 13px; font-weight: 600; color: #1d2327;
        }
        .wpb-sm-slug {
            font-size: 11px; color: #a7aaad; font-family: monospace;
        }
        .wpb-sm-source {
            font-size: 10px; color: #a7aaad;
            background: #f6f7f7;
            padding: 1px 5px; border-radius: 2px;
            white-space: nowrap;
        }

        /* Toggle switch */
        .wpb-tog {
            position: relative; width: 36px; height: 20px; flex-shrink: 0;
            display: inline-block;
        }
        .wpb-tog input { opacity: 0; width: 0; height: 0; position: absolute; }
        .wpb-tog-sl {
            position: absolute; inset: 0;
            background: #dcdcde; border-radius: 20px;
            cursor: pointer; transition: background .15s;
        }
        .wpb-tog-sl:before {
            content: ''; position: absolute;
            width: 14px; height: 14px; left: 3px; top: 3px;
            background: #fff; border-radius: 50%;
            transition: transform .15s;
            box-shadow: 0 1px 2px rgba(0,0,0,.2);
        }
        .wpb-tog input:checked + .wpb-tog-sl { background: #00a32a; }
        .wpb-tog input:checked + .wpb-tog-sl:before { transform: translateX(16px); }

        /* ── Preview sitemap ───────────────── */
        .wpb-sitemap-preview {
            position: sticky;
            top: 32px;
        }
        .wpb-sitemap-preview .wpb-panel { margin-bottom: 0; }
        .wpb-sitemap-entries {
            max-height: 520px;
            overflow-y: auto;
        }
        .wpb-sitemap-entry {
            padding: 8px 16px;
            border-bottom: 1px solid #f0f0f1;
            font-size: 12px;
        }
        .wpb-sitemap-entry:last-child { border-bottom: none; }
        .wpb-sitemap-entry-url {
            color: #2271b1;
            text-decoration: none;
            word-break: break-all;
            display: block;
            line-height: 1.4;
        }
        .wpb-sitemap-entry-url:hover { text-decoration: underline; }
        .wpb-sitemap-entry-meta {
            display: flex;
            gap: 8px;
            margin-top: 2px;
        }
        .wpb-sitemap-entry-type {
            font-size: 10px;
            color: #646970;
            background: #f0f0f1;
            padding: 1px 5px; border-radius: 2px;
            font-weight: 600; text-transform: uppercase; letter-spacing: .3px;
        }
        .wpb-sitemap-entry-date {
            font-size: 10px; color: #a7aaad;
        }
        .wpb-sitemap-entry-priority {
            font-size: 10px; font-weight: 700;
            padding: 1px 5px; border-radius: 2px;
            margin-left: auto;
        }
        .wpb-priority-10 { background: #edfaef; color: #00a32a; }
        .wpb-priority-09 { background: #edfaef; color: #00a32a; }
        .wpb-priority-08 { background: #e8f0fb; color: #2271b1; }
        .wpb-priority-07 { background: #f0f0f1; color: #646970; }
        .wpb-priority-06 { background: #f0f0f1; color: #a7aaad; }
        .wpb-priority-05 { background: #f0f0f1; color: #a7aaad; }
        .wpb-priority-04 { background: #f0f0f1; color: #c3c4c7; }
        .wpb-priority-03 { background: #f0f0f1; color: #c3c4c7; }
        .wpb-sitemap-empty {
            padding: 24px 16px;
            text-align: center;
            color: #646970;
            font-size: 13px;
        }
        .wpb-sitemap-empty .dashicons {
            font-size: 28px; width: 28px; height: 28px;
            color: #c3c4c7; display: block; margin: 0 auto 8px;
        }
    </style>

    <div id="wpb-sitemap-wrap" class="wrap">
        <h1>
            <span class="dashicons dashicons-media-document"
                style="font-size:24px;width:24px;height:24px;vertical-align:middle;margin-right:6px;color:#e6600d;"></span>
            WP Barrio
            <span style="font-size:15px;font-weight:400;color:#646970;margin-left:4px;">/ SEO / Sitemap</span>
        </h1>
        <p class="description" style="font-size:14px;margin:4px 0 20px;">
            Configura quali contenuti includere nella sitemap XML.
            <?php if ($total_url > 0): ?>
                <strong><?php echo $total_url; ?> URL</strong> totali.
            <?php endif; ?>
        </p>

        <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible" style="margin-bottom:20px;">
                <p>Impostazioni salvate.</p>
            </div>
        <?php endif; ?>

        <div class="wpb-sitemap-layout">

            <!-- ── Colonna sinistra: configurazione ── -->
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
                                $on = isset($settings['post_types'][$slug])
                                    ? (bool)$settings['post_types'][$slug]
                                    : (bool)($content['post_types'][$slug]['sitemap'] ?? 1);
                                if ($on) $pt_on++;
                            }
                            ?>
                            <span class="wpb-panel-header-count"><?php echo $pt_on; ?> / <?php echo count($post_types); ?></span>
                        </div>
                        <?php foreach ($post_types as $pt_obj):
                            $slug    = $pt_obj->name;
                            $plural  = $pt_obj->labels->name ?? $slug;
                            $builtin = $pt_obj->_builtin ?? false;

                            // Determina valore e fonte
                            if (isset($settings['post_types'][$slug])) {
                                $checked = (bool) $settings['post_types'][$slug];
                                $source  = 'sitemap';
                            } else {
                                $checked = (bool) ($content['post_types'][$slug]['sitemap'] ?? 1);
                                $source  = 'meta-tags';
                            }
                        ?>
                            <div class="wpb-sm-row">
                                <div class="wpb-sm-info">
                                    <div class="wpb-sm-label">
                                        <?php echo esc_html($plural); ?>
                                        <?php if ($builtin): ?>
                                            <span style="font-size:10px;background:#dde7f2;color:#2271b1;padding:1px 5px;border-radius:2px;font-weight:600;margin-left:4px;">core</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="wpb-sm-slug"><?php echo esc_html($slug); ?></span>
                                </div>
                                <?php if ($source === 'meta-tags'): ?>
                                    <span class="wpb-sm-source" title="Valore ereditato da Meta Tags → Contenuti">da meta tags</span>
                                <?php endif; ?>
                                <label class="wpb-tog">
                                    <input type="checkbox"
                                           name="pt[<?php echo esc_attr($slug); ?>]"
                                           value="1"
                                           <?php checked($checked); ?>
                                           class="wpb-sm-toggle"
                                           data-type="pt" data-key="<?php echo esc_attr($slug); ?>">
                                    <span class="wpb-tog-sl"></span>
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
                                $on = isset($settings['taxonomies'][$slug])
                                    ? (bool)$settings['taxonomies'][$slug]
                                    : (bool)($content['taxonomies'][$slug]['sitemap'] ?? 1);
                                if ($on) $tax_on++;
                            }
                            ?>
                            <span class="wpb-panel-header-count"><?php echo $tax_on; ?> / <?php echo count($taxonomies); ?></span>
                        </div>
                        <?php foreach ($taxonomies as $tax_obj):
                            $slug    = $tax_obj->name;
                            $label   = $tax_obj->labels->name ?? $slug;
                            $builtin = in_array($slug, ['category', 'post_tag', 'nav_menu', 'link_category', 'post_format']);

                            if (isset($settings['taxonomies'][$slug])) {
                                $checked = (bool) $settings['taxonomies'][$slug];
                                $source  = 'sitemap';
                            } else {
                                $checked = (bool) ($content['taxonomies'][$slug]['sitemap'] ?? 1);
                                $source  = 'meta-tags';
                            }
                        ?>
                            <div class="wpb-sm-row">
                                <div class="wpb-sm-info">
                                    <div class="wpb-sm-label">
                                        <?php echo esc_html($label); ?>
                                        <?php if ($builtin): ?>
                                            <span style="font-size:10px;background:#dde7f2;color:#2271b1;padding:1px 5px;border-radius:2px;font-weight:600;margin-left:4px;">core</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="wpb-sm-slug"><?php echo esc_html($slug); ?></span>
                                </div>
                                <?php if ($source === 'meta-tags'): ?>
                                    <span class="wpb-sm-source" title="Valore ereditato da Meta Tags → Contenuti">da meta tags</span>
                                <?php endif; ?>
                                <label class="wpb-tog">
                                    <input type="checkbox"
                                           name="tax[<?php echo esc_attr($slug); ?>]"
                                           value="1"
                                           <?php checked($checked); ?>
                                           class="wpb-sm-toggle"
                                           data-type="tax" data-key="<?php echo esc_attr($slug); ?>">
                                    <span class="wpb-tog-sl"></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php submit_button('Salva impostazioni', 'primary', 'submit', false); ?>
                </form>
            </div>

            <!-- ── Colonna destra: anteprima ── -->
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
                                if (empty($url)) continue;
                            ?>
                                <div class="wpb-sitemap-entry" data-type="<?php echo esc_attr($entry['type']); ?>" data-subtype="<?php echo esc_attr($entry['subtype']); ?>">
                                    <a href="<?php echo esc_url($url); ?>" class="wpb-sitemap-entry-url" target="_blank">
                                        <?php echo esc_html(str_replace(home_url(), '', $url) ?: '/'); ?>
                                    </a>
                                    <div class="wpb-sitemap-entry-meta">
                                        <span class="wpb-sitemap-entry-type"><?php echo esc_html($entry['subtype']); ?></span>
                                        <?php if ($entry['lastmod']): ?>
                                            <span class="wpb-sitemap-entry-date"><?php echo esc_html(date_i18n('d/m/Y', strtotime($entry['lastmod']))); ?></span>
                                        <?php endif; ?>
                                        <?php
                                        $p    = $entry['priority'];
                                        $pkey = 'wpb-priority-' . str_replace('.', '', number_format($p, 1));
                                        ?>
                                        <span class="wpb-sitemap-entry-priority <?php echo esc_attr($pkey); ?>">
                                            <?php echo number_format($p, 1); ?>
                                        </span>
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
        // Aggiorna il contatore nell'anteprima al cambio dei toggle
        // (l'anteprima vera richiede un reload dopo salvataggio)
        var toggles  = document.querySelectorAll('.wpb-sm-toggle');
        var countEl  = document.getElementById('wpb-preview-count');
        var entries  = document.querySelectorAll('.wpb-sitemap-entry');

        toggles.forEach(function (cb) {
            cb.addEventListener('change', function () {
                var type = this.dataset.type;
                var key  = this.dataset.key;
                var on   = this.checked;

                entries.forEach(function (el) {
                    if (el.dataset.type === (type === 'pt' ? 'post' : 'term') &&
                        el.dataset.subtype === key) {
                        el.style.display = on ? '' : 'none';
                    }
                });

                // Aggiorna contatore
                var visible = document.querySelectorAll('.wpb-sitemap-entry:not([style*="display: none"])').length;
                countEl.textContent = visible + ' URL';
            });
        });
    })();
    </script>
    <?php
}
