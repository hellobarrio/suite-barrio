<?php
/**
 * WP Barrio — SEO / Seo Globale
 * Gestione meta title, description, robots e og per ogni post type e tassonomia.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   COSTANTI
   ───────────────────────────────────────── */
if (!defined('WPB_META_OPTION'))
    define('WPB_META_OPTION', 'wpb_meta_settings');
if (!defined('WPB_META_CONTENT_OPTION'))
    define('WPB_META_CONTENT_OPTION', 'wpb_meta_content');

/* ─────────────────────────────────────────
   MENU — voce nascosta ma con parent corretto
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!wpb_tools_module_enabled('seo'))
        return;

    // Parent wp-barrio: WordPress sa che appartiene a questo menu
    // (necessario perché il menu rimanga aperto sulla pagina)
    add_submenu_page(
        'wp-barrio',
        'Seo Globale — WP Barrio',
        'Seo Globale',
        'manage_options',
        'wp-barrio-global-seo',
        'wpb_meta_admin_page'
    );
}, 12);

// Nasconde la voce dal sidebar via CSS
add_action('admin_head', function () {
    echo '<style>#adminmenu a[href="admin.php?page=wp-barrio-global-seo"]{display:none!important}</style>';
});

// Evidenzia "SEO" come voce attiva quando si è in Seo Globale
add_filter('submenu_file', function ($submenu_file) {
    if (isset($_GET['page']) && $_GET['page'] === 'wp-barrio-global-seo')
        return 'wp-barrio-seo';
    return $submenu_file;
});

/* ─────────────────────────────────────────
   ENQUEUE — media uploader
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    // Pagina impostazioni Meta Tags
    if ($hook === 'wp-barrio_page_wp-barrio-global-seo') {
        wp_enqueue_media();
        return;
    }
    // Editor post/pagine (metabox) e form tassonomie
    if (in_array($hook, ['post.php', 'post-new.php', 'edit-tags.php', 'term.php'])) {
        wp_enqueue_media();
    }
});

/* ─────────────────────────────────────────
   SALVATAGGIO — Tab Contenuti
   ───────────────────────────────────────── */
add_action('admin_post_wpb_meta_content_save', function () {
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');

    check_admin_referer('wpb_meta_content_save');

    $post_types = get_post_types(['public' => true]);
    $taxonomies = array_diff(get_taxonomies(['public' => true]), ['post_format', 'nav_menu', 'link_category']);

    $current = get_option(WPB_META_CONTENT_OPTION, []);

    foreach ($post_types as $pt) {
        $current['post_types'][$pt] = [
            'index' => isset($_POST['pt'][$pt]['index']) ? 1 : 0,
            'follow' => isset($_POST['pt'][$pt]['follow']) ? 1 : 0,
        ];
    }

    foreach ($taxonomies as $tax) {
        $current['taxonomies'][$tax] = [
            'index' => isset($_POST['tax'][$tax]['index']) ? 1 : 0,
            'follow' => isset($_POST['tax'][$tax]['follow']) ? 1 : 0,
        ];
    }

    update_option(WPB_META_CONTENT_OPTION, $current);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_redirect(admin_url('admin.php?page=wp-barrio-global-seo&tab=content&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   SALVATAGGIO — Tab Impostazioni
   ───────────────────────────────────────── */
add_action('admin_post_wpb_meta_settings_save', function () {
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');

    check_admin_referer('wpb_meta_settings_save');

    // Gestione upload immagine OG
    $og_image_id = (int) get_option(WPB_META_OPTION . '_og_image_id', 0);
    if (!empty($_POST['wpb_meta_og_image_id'])) {
        $og_image_id = absint($_POST['wpb_meta_og_image_id']);
    }

    // Gestione favicon
    $favicon_id = isset($_POST['wpb_meta_favicon_id']) ? absint($_POST['wpb_meta_favicon_id']) : 0;

    $settings = [
        'site_title' => sanitize_text_field($_POST['wpb_meta_site_title'] ?? ''),
        'separator' => sanitize_text_field($_POST['wpb_meta_separator'] ?? '—'),
        'title_order' => sanitize_text_field($_POST['wpb_meta_title_order'] ?? 'page_site'),
        'og_image_id' => $og_image_id,
        'favicon_id' => $favicon_id,
        'default_desc' => sanitize_textarea_field($_POST['wpb_meta_default_desc'] ?? ''),
    ];

    update_option(WPB_META_OPTION, $settings);

    // Sincronizza con il titolo del sito WordPress
    if (!empty($settings['site_title']))
        update_option('blogname', $settings['site_title']);

    // Sincronizza la favicon con l'icona del sito WordPress (site_icon)
    if ($favicon_id)
        update_option('site_icon', $favicon_id);
    else
        delete_option('site_icon');

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }

    wp_redirect(admin_url('admin.php?page=wp-barrio-global-seo&tab=settings&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   OUTPUT META TAGS nel <head> del frontend
   ───────────────────────────────────────── */
add_action('wp_head', 'wpb_meta_output_tags', 1);
function wpb_meta_output_tags()
{
    if (!wpb_tools_module_enabled('seo'))
        return;

    $settings = get_option(WPB_META_OPTION, []);
    $content = get_option(WPB_META_CONTENT_OPTION, []);

    $sep = $settings['separator'] ?? '—';
    $site_name = $settings['site_title'] ?? get_bloginfo('name');
    $order = $settings['title_order'] ?? 'page_site';

    // ── Determina robots per il tipo corrente ────────────────────────────
    $robots = 'index,follow'; // fallback fisso

    if (is_singular()) {
        $pt = get_post_type();
        $cfg = $content['post_types'][$pt] ?? ['index' => 1, 'follow' => 1];

        // Legge override per-post ('' = non impostato, usa default post type)
        $post_index = get_post_meta(get_the_ID(), '_wpb_seo_index', true);
        $post_follow = get_post_meta(get_the_ID(), '_wpb_seo_follow', true);

        $idx = $post_index !== '' ? (bool) (int) $post_index : (bool) ($cfg['index'] ?? 1);
        $follow = $post_follow !== '' ? (bool) (int) $post_follow : (bool) ($cfg['follow'] ?? 1);
        $robots = ($idx ? 'index' : 'noindex') . ',' . ($follow ? 'follow' : 'nofollow');
    } elseif (is_tax() || is_category() || is_tag()) {
        $term = get_queried_object();
        $tax = $term ? $term->taxonomy : '';
        $cfg = $content['taxonomies'][$tax] ?? ['index' => 1, 'follow' => 1];

        // Legge override per-termine ('1'/'0') o usa default tassonomia ('')
        $term_index = $term ? get_term_meta($term->term_id, '_wpb_seo_index', true) : '';
        $term_follow = $term ? get_term_meta($term->term_id, '_wpb_seo_follow', true) : '';

        $idx = $term_index !== '' ? (bool) (int) $term_index : (bool) ($cfg['index'] ?? 1);
        $follow = $term_follow !== '' ? (bool) (int) $term_follow : (bool) ($cfg['follow'] ?? 1);
        $robots = ($idx ? 'index' : 'noindex') . ',' . ($follow ? 'follow' : 'nofollow');
    }

    // ── Title personalizzato ─────────────────────────────────────────────
    if (is_singular()) {
        $custom_title = get_post_meta(get_the_ID(), '_wpb_seo_title', true);
        if (!empty($custom_title)) {
            add_filter('document_title_parts', function ($parts) use ($custom_title) {
                $parts['title'] = $custom_title;
                return $parts;
            }, 99);
        }
    } elseif (is_tax() || is_category() || is_tag()) {
        $term = get_queried_object();
        if ($term) {
            $custom_title = get_term_meta($term->term_id, '_wpb_seo_title', true);
            if (!empty($custom_title)) {
                add_filter('document_title_parts', function ($parts) use ($custom_title) {
                    $parts['title'] = $custom_title;
                    return $parts;
                }, 99);
            }
        }
    }

    $page_title = wp_get_document_title();

    // ── Description ──────────────────────────────────────────────────────
    $description = '';
    if (is_singular()) {
        $description = get_post_meta(get_the_ID(), '_wpb_seo_description', true);
        if (empty($description))
            $description = mb_substr(wp_strip_all_tags(strip_shortcodes(get_post_field('post_content', get_the_ID()))), 0, 160);
    } elseif (is_tax() || is_category() || is_tag()) {
        $term = get_queried_object();
        if ($term) {
            $description = get_term_meta($term->term_id, '_wpb_seo_description', true);
            if (empty($description))
                $description = term_description($term->term_id);
        }
    }
    if (empty($description))
        $description = $settings['default_desc'] ?? '';
    $description = wp_strip_all_tags($description);

    // ── OG Image ─────────────────────────────────────────────────────────
    $og_image_url = '';
    if (is_singular()) {
        $custom_og_id = (int) get_post_meta(get_the_ID(), '_wpb_seo_og_image_id', true);
        if ($custom_og_id)
            $og_image_url = wp_get_attachment_image_url($custom_og_id, 'large');
        if (empty($og_image_url) && has_post_thumbnail())
            $og_image_url = get_the_post_thumbnail_url(null, 'large');
    } elseif (is_tax() || is_category() || is_tag()) {
        $term = get_queried_object();
        if ($term) {
            $custom_og_id = (int) get_term_meta($term->term_id, '_wpb_seo_og_image_id', true);
            if ($custom_og_id)
                $og_image_url = wp_get_attachment_image_url($custom_og_id, 'large');
        }
    }
    if (empty($og_image_url) && !empty($settings['og_image_id']))
        $og_image_url = wp_get_attachment_image_url($settings['og_image_id'], 'large');

    // ── Canonical (sempre attivo) ─────────────────────────────────────────
    $canonical = is_singular() ? get_permalink() : '';

    echo "\n<!-- WP Barrio SEO / Seo Globale -->\n";

    // Robots
    echo '<meta name="robots" content="' . esc_attr($robots) . '">' . "\n";

    // Description
    if (!empty($description))
        echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";

    // Canonical
    if (!empty($canonical))
        echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";

    // Open Graph (sempre attivo, og:type fisso)
    $og_type = is_singular() ? 'article' : 'website';
    echo '<meta property="og:type"        content="' . esc_attr($og_type) . '">' . "\n";
    echo '<meta property="og:title"       content="' . esc_attr($page_title) . '">' . "\n";
    echo '<meta property="og:url"         content="' . esc_url(get_permalink() ?: home_url()) . '">' . "\n";
    echo '<meta property="og:site_name"   content="' . esc_attr($site_name) . '">' . "\n";
    if (!empty($description))
        echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
    if (!empty($og_image_url))
        echo '<meta property="og:image"       content="' . esc_url($og_image_url) . '">' . "\n";

    // Twitter Card (sempre attivo, summary_large_image fisso)
    echo '<meta name="twitter:card"        content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title"       content="' . esc_attr($page_title) . '">' . "\n";
    if (!empty($description))
        echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n";
    if (!empty($og_image_url))
        echo '<meta name="twitter:image"       content="' . esc_url($og_image_url) . '">' . "\n";

    echo "<!-- / WP Barrio SEO -->\n\n";
}

/* ─────────────────────────────────────────
   HELPER: post type e tassonomie abilitati all'index
   ───────────────────────────────────────── */
function wpb_meta_indexed_post_types()
{
    $content = get_option(WPB_META_CONTENT_OPTION, []);
    $result = [];
    foreach (get_post_types(['public' => true], 'names') as $pt) {
        if ($pt === 'attachment')
            continue;
        $cfg = $content['post_types'][$pt] ?? ['index' => 1];
        if (!empty($cfg['index']))
            $result[] = $pt;
    }
    return $result;
}

function wpb_meta_indexed_taxonomies()
{
    $content = get_option(WPB_META_CONTENT_OPTION, []);
    $result = [];
    foreach (array_diff(get_taxonomies(['public' => true], 'names'), ['post_format', 'nav_menu', 'link_category']) as $tax) {
        $cfg = $content['taxonomies'][$tax] ?? ['index' => 1];
        if (!empty($cfg['index']))
            $result[] = $tax;
    }
    return $result;
}

/* ─────────────────────────────────────────
   METABOX — POST / PAGE / CPT
   ───────────────────────────────────────── */
add_action('add_meta_boxes', function () {
    foreach (wpb_meta_indexed_post_types() as $pt) {
        add_meta_box(
            'wpb_seo_meta_box',
            'SEO',
            'wpb_meta_render_post_metabox',
            $pt,
            'normal',
            'high'
        );
    }
});

function wpb_meta_render_post_metabox($post)
{
    wp_nonce_field('wpb_seo_post_meta_save_' . $post->ID, '_wpb_seo_nonce');

    $custom_title = get_post_meta($post->ID, '_wpb_seo_title', true);
    $custom_desc = get_post_meta($post->ID, '_wpb_seo_description', true);
    $custom_og_id = (int) get_post_meta($post->ID, '_wpb_seo_og_image_id', true);

    $raw_kw = get_post_meta($post->ID, '_wpb_seo_keywords', true);
    $keywords = $raw_kw ? (array) json_decode($raw_kw, true) : [];

    // Robots: custom per post oppure default del post type
    $content = get_option(WPB_META_CONTENT_OPTION, []);
    $pt_slug = $post->post_type;
    $pt_cfg = $content['post_types'][$pt_slug] ?? ['index' => 1, 'follow' => 1, 'sitemap' => 1];

    $custom_index = get_post_meta($post->ID, '_wpb_seo_index', true);
    $custom_follow = get_post_meta($post->ID, '_wpb_seo_follow', true);
    $custom_sitemap = get_post_meta($post->ID, '_wpb_seo_sitemap', true);
    // '' = non impostato (usa default post type), '1' = sì, '0' = no
    $index_val = $custom_index !== '' ? (bool) (int) $custom_index : (bool) $pt_cfg['index'];
    $follow_val = $custom_follow !== '' ? (bool) (int) $custom_follow : (bool) $pt_cfg['follow'];
    $sitemap_val = $custom_sitemap !== '' ? (bool) (int) $custom_sitemap : (bool) ($pt_cfg['sitemap'] ?? 1);
    $index_default = (bool) $pt_cfg['index'];
    $follow_default = (bool) $pt_cfg['follow'];
    $sitemap_default = (bool) ($pt_cfg['sitemap'] ?? 1);

    $default_title = get_the_title($post->ID);
    $default_desc = mb_substr(wp_strip_all_tags(strip_shortcodes(get_post_field('post_content', $post->ID))), 0, 160);
    $default_og = get_the_post_thumbnail_url($post->ID, 'medium') ?: '';
    $custom_og_url = $custom_og_id ? wp_get_attachment_image_url($custom_og_id, 'medium') : '';

    $s = get_option(WPB_META_OPTION, []);
    $sep = $s['separator'] ?? '—';
    $site_name = $s['site_title'] ?? get_bloginfo('name');
    $order = $s['title_order'] ?? 'page_site';

    $display_title = $custom_title ?: $default_title;
    $full_title = $order === 'site_page'
        ? $site_name . ' ' . $sep . ' ' . $display_title
        : $display_title . ' ' . $sep . ' ' . $site_name;

    $display_desc = $custom_desc ?: $default_desc;
    $display_url = get_permalink($post->ID) ?: get_home_url() . '/' . $post->post_name;
    $og_thumb_url = $custom_og_url ?: ($default_og ?: '');
    $favicon_preview = !empty($s['favicon_id'])
        ? wp_get_attachment_image_url((int) $s['favicon_id'], 'thumbnail')
        : '';
    ?>
    <div class="wpb-seo-mb">

        <!-- ── Colonna campi ── -->
        <div class="wpb-seo-fields">

            <!-- Title -->
            <div class="wpb-seo-field-row">
                <span class="wpb-seo-field-label">Title</span>
                <input type="text" id="wpb-mb-title" name="_wpb_seo_title" value="<?php echo esc_attr($custom_title); ?>"
                    placeholder="<?php echo esc_attr($default_title); ?>">
                <span class="wpb-seo-counter" id="wpb-mb-title-ctr">
                    <span id="wpb-mb-title-len"><?php echo mb_strlen($display_title); ?></span>/60
                </span>
            </div>

            <!-- Description -->
            <div class="wpb-seo-field-row">
                <span class="wpb-seo-field-label">Description</span>
                <textarea id="wpb-mb-desc" name="_wpb_seo_description" rows="3"
                    placeholder="<?php echo esc_attr($default_desc ?: 'Estratto del post…'); ?>"><?php echo esc_textarea($custom_desc); ?></textarea>
                <span class="wpb-seo-counter" id="wpb-mb-desc-ctr">
                    <span id="wpb-mb-desc-len"><?php echo mb_strlen($display_desc); ?></span>/160
                </span>
            </div>

            <!-- OG Image -->
            <div class="wpb-seo-field-row">
                <span class="wpb-seo-field-label">Immagine OG</span>
                <input type="hidden" name="_wpb_seo_og_image_id" id="wpb-mb-og-id"
                    value="<?php echo esc_attr($custom_og_id ?: ''); ?>">
                <div class="wpb-seo-og-row">
                    <div class="wpb-seo-og-thumb" id="wpb-mb-og-thumb" title="Clicca per cambiare">
                        <?php if ($og_thumb_url): ?>
                            <img src="<?php echo esc_url($og_thumb_url); ?>"
                                style="<?php echo !$custom_og_id ? 'opacity:.45;' : ''; ?>" alt="" id="wpb-mb-og-thumb-img">
                        <?php else: ?>
                            <span class="dashicons dashicons-format-image"></span>
                        <?php endif; ?>
                    </div>
                    <div class="wpb-seo-og-btns">
                        <button type="button" class="button button-small" id="wpb-mb-og-select">
                            <?php echo $custom_og_id ? 'Cambia' : 'Seleziona'; ?>
                        </button>
                        <?php if ($custom_og_id): ?>
                            <button type="button" class="button button-small" id="wpb-mb-og-remove">✕</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Keywords -->
            <div class="wpb-seo-field-row">
                <span class="wpb-seo-field-label">Keywords</span>
                <input type="hidden" name="_wpb_seo_keywords" id="wpb-mb-kw-hidden"
                    value="<?php echo esc_attr($raw_kw ?: '[]'); ?>">
                <div class="wpb-kw-wrap" id="wpb-mb-kw-wrap">
                    <?php foreach ($keywords as $i => $kw): ?>
                        <span class="wpb-kw-pill <?php echo $i === 0 ? 'wpb-kw-pill-primary' : 'wpb-kw-pill-secondary'; ?>"
                            data-kw="<?php echo esc_attr($kw); ?>">
                            <?php echo esc_html($kw); ?>
                            <button type="button" class="wpb-kw-pill-remove" aria-label="Rimuovi">×</button>
                        </span>
                    <?php endforeach; ?>
                    <input type="text" class="wpb-kw-input" id="wpb-mb-kw-input"
                        placeholder="<?php echo empty($keywords) ? 'Aggiungi keyword…' : ''; ?>">
                </div>
                <span class="wpb-kw-hint">Premi <kbd>Enter</kbd> o <kbd>,</kbd> per aggiungere. La prima è la keyword
                    primaria.</span>
            </div>

            <!-- Indicizzazione -->
            <div class="wpb-seo-field-row" style="margin-top: 20px;">
                <span class="wpb-seo-field-label">Indicizzazione</span>
                <input type="hidden" name="_wpb_seo_index_set" value="1">
                <input type="hidden" name="_wpb_seo_follow_set" value="1">
                <input type="hidden" name="_wpb_seo_sitemap_set" value="1">
                <div class="wpb-seo-robots-row">
                    <div class="wpb-seo-robots-item">
                        <label>
                            <span class="wpb-tog">
                                <input type="checkbox" id="wpb-mb-index" name="_wpb_seo_index" value="1" <?php checked($index_val); ?>>
                                <span class="wpb-tog-sl"></span>
                            </span>
                            Index
                            <?php if ($custom_index === ''): ?>
                                <span class="wpb-robots-badge" id="wpb-index-badge">default</span>
                            <?php endif; ?>
                        </label>
                    </div>
                    <div class="wpb-seo-robots-item">
                        <label>
                            <span class="wpb-tog">
                                <input type="checkbox" id="wpb-mb-follow" name="_wpb_seo_follow" value="1" <?php checked($follow_val); ?>>
                                <span class="wpb-tog-sl"></span>
                            </span>
                            Follow
                            <?php if ($custom_follow === ''): ?>
                                <span class="wpb-robots-badge" id="wpb-follow-badge">default</span>
                            <?php endif; ?>
                        </label>
                    </div>
                    <div class="wpb-seo-robots-item">
                        <label>
                            <span class="wpb-tog">
                                <input type="checkbox" id="wpb-mb-sitemap" name="_wpb_seo_sitemap" value="1" <?php checked($sitemap_val); ?>>
                                <span class="wpb-tog-sl"></span>
                            </span>
                            Sitemap
                            <?php if ($custom_sitemap === ''): ?>
                                <span class="wpb-robots-badge" id="wpb-sitemap-badge">default</span>
                            <?php endif; ?>
                        </label>
                    </div>
                </div>
            </div>

        </div><!-- .wpb-seo-fields -->

        <!-- ── Colonna preview ── -->
        <div class="wpb-seo-preview">

            <!-- Anteprima Google -->
            <div class="wpb-seo-preview-section">
                <div class="wpb-seo-preview-label">Anteprima Google</div>
                <div class="wpb-serp-card">
                    <div class="wpb-serp-site">
                        <div class="wpb-serp-favicon">
                            <?php if ($favicon_preview): ?>
                                <img src="<?php echo esc_url($favicon_preview); ?>" alt=""
                                    style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <div class="wpb-serp-favicon-dot"></div>
                            <?php endif; ?>
                        </div>
                        <div class="wpb-serp-site-info">
                            <span class="wpb-serp-site-name"><?php echo esc_html($site_name); ?></span>
                            <span class="wpb-serp-url"><?php echo esc_html($display_url); ?></span>
                        </div>
                    </div>
                    <div class="wpb-serp-title" id="wpb-serp-title"><?php echo esc_html($full_title); ?></div>
                    <div class="wpb-serp-desc <?php echo empty($display_desc) ? 'placeholder' : ''; ?>" id="wpb-serp-desc">
                        <?php echo esc_html($display_desc ?: 'Aggiungi una descrizione per mostrare qui il testo…'); ?>
                    </div>
                </div>
            </div>

            <!-- Anteprima Social -->
            <div class="wpb-seo-preview-section">
                <div class="wpb-seo-preview-label">Anteprima Social</div>
                <div class="wpb-social-card">
                    <div class="wpb-social-card-img" id="wpb-social-img-wrap">
                        <?php if ($og_thumb_url): ?>
                            <img src="<?php echo esc_url($og_thumb_url); ?>" id="wpb-social-preview-img" alt="">
                        <?php else: ?>
                            <div class="wpb-social-img-placeholder" id="wpb-social-img-placeholder">
                                <span class="dashicons dashicons-format-image"></span>
                                <span>Nessuna immagine</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="wpb-social-card-body">
                        <div class="wpb-social-card-domain">
                            <?php echo esc_html(wp_parse_url($display_url, PHP_URL_HOST) ?: get_bloginfo('url')); ?>
                        </div>
                        <div class="wpb-social-card-title" id="wpb-social-title"><?php echo esc_html($full_title); ?></div>
                        <div class="wpb-social-card-desc <?php echo empty($display_desc) ? 'placeholder' : ''; ?>"
                            id="wpb-social-desc">
                            <?php echo esc_html($display_desc ?: 'Aggiungi una descrizione…'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Punteggio SEO -->
            <div class="wpb-seo-preview-section">
                <div class="wpb-seo-preview-label">Punteggio SEO</div>
                <div class="wpb-score-wrap" id="wpb-score-wrap">
                    <div class="wpb-score-gauge">
                        <svg viewBox="0 0 100 54" class="wpb-score-svg">
                            <path class="wpb-score-track" d="M9,50 A41,41 0 0,1 91,50" fill="none" stroke="#f0f0f1"
                                stroke-width="10" stroke-linecap="round" />
                            <path class="wpb-score-fill" id="wpb-score-fill" d="M9,50 A41,41 0 0,1 91,50" fill="none"
                                stroke="#c3c4c7" stroke-width="10" stroke-linecap="round" stroke-dasharray="0 129" />
                        </svg>
                        <div class="wpb-score-num" id="wpb-score-num">0</div>
                        <div class="wpb-score-label" id="wpb-score-label">—</div>
                    </div>
                    <ul class="wpb-score-items" id="wpb-score-items"></ul>
                </div>
            </div>

        </div><!-- .wpb-seo-preview -->

    </div><!-- .wpb-seo-mb -->

    <script>
        (function () {
            var defaultTitle = <?php echo wp_json_encode($default_title); ?>;
            var defaultDesc = <?php echo wp_json_encode($default_desc); ?>;
            var siteName = <?php echo wp_json_encode($site_name); ?>;
            var sep = <?php echo wp_json_encode($sep); ?>;
            var order = <?php echo wp_json_encode($order); ?>;
            var defaultOgUrl = <?php echo wp_json_encode($og_thumb_url); ?>;
            var indexDefault = <?php echo wp_json_encode($index_default); ?>;
            var followDefault = <?php echo wp_json_encode($follow_default); ?>;

            var titleInput = document.getElementById('wpb-mb-title');
            var titleLen = document.getElementById('wpb-mb-title-len');
            var titleCtr = document.getElementById('wpb-mb-title-ctr');
            var descInput = document.getElementById('wpb-mb-desc');
            var descLen = document.getElementById('wpb-mb-desc-len');
            var descCtr = document.getElementById('wpb-mb-desc-ctr');
            var serpTitle = document.getElementById('wpb-serp-title');
            var serpDesc = document.getElementById('wpb-serp-desc');
            var socialTitle = document.getElementById('wpb-social-title');
            var socialDesc = document.getElementById('wpb-social-desc');
            var socialImg = document.getElementById('wpb-social-img-wrap');
            var ogIdField = document.getElementById('wpb-mb-og-id');
            var ogThumb = document.getElementById('wpb-mb-og-thumb');
            var selectBtn = document.getElementById('wpb-mb-og-select');
            var removeBtn = document.getElementById('wpb-mb-og-remove');
            var indexCb = document.getElementById('wpb-mb-index');
            var followCb = document.getElementById('wpb-mb-follow');
            var frame;

            // ── Title ──────────────────────────────────────────────────────────
            function updateTitle() {
                var val = titleInput.value || defaultTitle;
                var len = val.length;
                titleLen.textContent = len;
                titleCtr.className = 'wpb-seo-counter' + (len > 60 ? ' warn' : len >= 50 ? ' ok' : '');
                var full = order === 'site_page'
                    ? siteName + ' ' + sep + ' ' + val
                    : val + ' ' + sep + ' ' + siteName;
                serpTitle.textContent = full;
                socialTitle.textContent = full;
            }

            // ── Description ────────────────────────────────────────────────────
            function updateDesc() {
                var val = descInput.value;
                var display = val || defaultDesc;
                var len = val.length;
                descLen.textContent = len;
                descCtr.className = 'wpb-seo-counter' + (len > 160 ? ' warn' : len >= 120 ? ' ok' : '');
                // SERP
                if (display) {
                    serpDesc.textContent = display;
                    serpDesc.classList.remove('placeholder');
                } else {
                    serpDesc.textContent = 'Aggiungi una descrizione per mostrare qui il testo\u2026';
                    serpDesc.classList.add('placeholder');
                }
                // Social
                if (display) {
                    socialDesc.textContent = display;
                    socialDesc.classList.remove('placeholder');
                } else {
                    socialDesc.textContent = 'Aggiungi una descrizione\u2026';
                    socialDesc.classList.add('placeholder');
                }
            }

            // ── OG Image ───────────────────────────────────────────────────────
            function renderOgImage(src, custom) {
                var opacity = custom ? '' : 'opacity:.45;';
                // thumb
                ogThumb.innerHTML = '<img src="' + src + '" style="width:100%;height:100%;object-fit:cover;' + opacity + '" alt="">';
                // social preview
                socialImg.innerHTML = '<img src="' + src + '" style="width:100%;height:100%;object-fit:cover;" alt="">';
            }
            function clearOgImage() {
                if (defaultOgUrl) {
                    renderOgImage(defaultOgUrl, false);
                } else {
                    ogThumb.innerHTML = '<span class="dashicons dashicons-format-image" style="font-size:16px;width:16px;height:16px;color:#dcdcde;"></span>';
                    socialImg.innerHTML = '<div class="wpb-social-img-placeholder"><span class="dashicons dashicons-format-image"></span><span>Nessuna immagine</span></div>';
                }
            }

            if (selectBtn) {
                selectBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (frame) { frame.open(); return; }
                    frame = wp.media({ title: 'Seleziona immagine OG', button: { text: 'Usa questa immagine' }, multiple: false, library: { type: 'image' } });
                    frame.on('select', function () {
                        var att = frame.state().get('selection').first().toJSON();
                        var src = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
                        ogIdField.value = att.id;
                        renderOgImage(src, true);
                        selectBtn.textContent = 'Cambia';
                        document.dispatchEvent(new Event('wpb-og-changed'));
                        if (!document.getElementById('wpb-mb-og-remove')) {
                            var rb = document.createElement('button');
                            rb.type = 'button'; rb.className = 'button button-small';
                            rb.id = 'wpb-mb-og-remove'; rb.textContent = '\u2715';
                            selectBtn.after(rb);
                            rb.addEventListener('click', doRemove);
                        }
                    });
                    frame.open();
                });
                ogThumb.addEventListener('click', function () { selectBtn.click(); });
            }

            function doRemove() {
                ogIdField.value = '';
                clearOgImage();
                selectBtn.textContent = 'Seleziona';
                document.dispatchEvent(new Event('wpb-og-changed'));
                var rb = document.getElementById('wpb-mb-og-remove');
                if (rb) rb.remove();
            }
            if (removeBtn) removeBtn.addEventListener('click', doRemove);

            // ── Robots badge (rimuove "default" al primo cambio manuale) ──────
            function removeBadge(id) {
                var b = document.getElementById(id);
                if (b) b.remove();
            }
            if (indexCb) indexCb.addEventListener('change', function () { removeBadge('wpb-index-badge'); });
            if (followCb) followCb.addEventListener('change', function () { removeBadge('wpb-follow-badge'); });
            var sitemapCb = document.getElementById('wpb-mb-sitemap');
            if (sitemapCb) sitemapCb.addEventListener('change', function () { removeBadge('wpb-sitemap-badge'); });

            // ── Keywords tag-input con drag & drop ──────────────────────────
            (function () {
                var wrap = document.getElementById('wpb-mb-kw-wrap');
                var input = document.getElementById('wpb-mb-kw-input');
                var hidden = document.getElementById('wpb-mb-kw-hidden');
                if (!wrap || !input || !hidden) return;

                var dragSrc = null;

                function getKeywords() {
                    var kws = [];
                    wrap.querySelectorAll('.wpb-kw-pill').forEach(function (p) { kws.push(p.dataset.kw); });
                    return kws;
                }

                function sync() {
                    var kws = getKeywords();
                    hidden.value = JSON.stringify(kws);
                    wrap.querySelectorAll('.wpb-kw-pill').forEach(function (p, i) {
                        p.className = 'wpb-kw-pill ' + (i === 0 ? 'wpb-kw-pill-primary' : 'wpb-kw-pill-secondary');
                    });
                    input.placeholder = kws.length === 0 ? 'Aggiungi keyword\u2026' : '';
                    document.dispatchEvent(new Event('wpb-kw-changed'));
                }

                function makePill(kw) {
                    var pill = document.createElement('span');
                    pill.className = 'wpb-kw-pill wpb-kw-pill-secondary';
                    pill.dataset.kw = kw;
                    pill.draggable = true;
                    pill.style.cursor = 'grab';
                    pill.innerHTML = esc(kw) +
                        '<button type="button" class="wpb-kw-pill-remove" aria-label="Rimuovi">\u00d7</button>';
                    bindDrag(pill);
                    return pill;
                }

                function bindDrag(pill) {
                    pill.addEventListener('dragstart', function (e) {
                        dragSrc = this;
                        e.dataTransfer.effectAllowed = 'move';
                        this.style.opacity = '.4';
                    });
                    pill.addEventListener('dragend', function () {
                        this.style.opacity = '';
                        wrap.querySelectorAll('.wpb-kw-pill').forEach(function (p) {
                            p.classList.remove('wpb-kw-drag-over');
                        });
                    });
                    pill.addEventListener('dragover', function (e) {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                        if (this !== dragSrc) this.classList.add('wpb-kw-drag-over');
                    });
                    pill.addEventListener('dragleave', function () {
                        this.classList.remove('wpb-kw-drag-over');
                    });
                    pill.addEventListener('drop', function (e) {
                        e.stopPropagation();
                        this.classList.remove('wpb-kw-drag-over');
                        if (dragSrc === this) return;
                        // Inserisce dragSrc prima di this
                        wrap.insertBefore(dragSrc, this);
                        sync();
                    });
                }

                function addKeyword(raw) {
                    var kw = raw.trim().replace(/,+$/, '').trim();
                    if (!kw) return;
                    var existing = getKeywords().map(function (k) { return k.toLowerCase(); });
                    if (existing.indexOf(kw.toLowerCase()) !== -1) return;
                    var pill = makePill(kw);
                    wrap.insertBefore(pill, input);
                    sync();
                }

                // Rende draggabili le pill già presenti nel DOM
                wrap.querySelectorAll('.wpb-kw-pill').forEach(function (p) {
                    p.draggable = true;
                    p.style.cursor = 'grab';
                    bindDrag(p);
                });

                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ',') {
                        e.preventDefault();
                        addKeyword(this.value);
                        this.value = '';
                    } else if (e.key === 'Backspace' && this.value === '') {
                        var pills = wrap.querySelectorAll('.wpb-kw-pill');
                        if (pills.length > 0) { pills[pills.length - 1].remove(); sync(); }
                    }
                });
                input.addEventListener('blur', function () {
                    if (this.value.trim()) { addKeyword(this.value); this.value = ''; }
                });

                wrap.addEventListener('click', function (e) {
                    if (e.target.classList.contains('wpb-kw-pill-remove')) {
                        e.target.closest('.wpb-kw-pill').remove(); sync();
                    } else if (e.target === wrap || e.target === input) {
                        input.focus();
                    }
                });

                function esc(s) {
                    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                }
            })();

            // ── SEO Score ─────────────────────────────────────────────────────
            (function () {
                var titleInput = document.getElementById('wpb-mb-title');
                var descInput = document.getElementById('wpb-mb-desc');
                var ogIdField = document.getElementById('wpb-mb-og-id');
                var kwHidden = document.getElementById('wpb-mb-kw-hidden');
                var scoreNum = document.getElementById('wpb-score-num');
                var scoreLbl = document.getElementById('wpb-score-label');
                var scoreFill = document.getElementById('wpb-score-fill');
                var scoreItems = document.getElementById('wpb-score-items');
                if (!scoreNum) return;

                var defaultTitle = <?php echo wp_json_encode($default_title); ?>;
                var defaultDesc = <?php echo wp_json_encode($default_desc); ?>;
                var hasOgDefault = <?php echo wp_json_encode(!empty($og_thumb_url)); ?>;
                // Arco totale (semicerchio): ~129 unità SVG
                var ARC = 128.8;

                function compute() {
                    var title = (titleInput ? titleInput.value.trim() : '') || defaultTitle;
                    var desc = (descInput ? descInput.value.trim() : '') || defaultDesc;
                    var hasCustomTitle = titleInput && titleInput.value.trim() !== '';
                    var hasCustomDesc = descInput && descInput.value.trim() !== '';
                    var hasOg = (ogIdField && ogIdField.value) || hasOgDefault;
                    var kws = [];
                    if (kwHidden && kwHidden.value) {
                        try { kws = JSON.parse(kwHidden.value) || []; } catch (e) { }
                    }
                    var primaryKw = kws.length > 0 ? kws[0].toLowerCase() : '';

                    var checks = [
                        {
                            id: 'custom-title',
                            label: 'Title personalizzato',
                            pts: 15,
                            pass: hasCustomTitle,
                            warn: false,
                        },
                        {
                            id: 'title-len',
                            label: 'Title 30–60 caratteri (' + title.length + ')',
                            pts: 10,
                            pass: title.length >= 30 && title.length <= 60,
                            warn: title.length > 0 && (title.length < 30 || title.length > 60),
                        },
                        {
                            id: 'custom-desc',
                            label: 'Description personalizzata',
                            pts: 15,
                            pass: hasCustomDesc,
                            warn: false,
                        },
                        {
                            id: 'desc-len',
                            label: 'Description 120–160 caratteri (' + desc.length + ')',
                            pts: 10,
                            pass: desc.length >= 120 && desc.length <= 160,
                            warn: desc.length > 0 && (desc.length < 120 || desc.length > 160),
                        },
                        {
                            id: 'og-image',
                            label: 'Immagine OG presente',
                            pts: 15,
                            pass: !!hasOg,
                            warn: false,
                        },
                        {
                            id: 'keywords',
                            label: 'Almeno una keyword',
                            pts: 15,
                            pass: kws.length > 0,
                            warn: false,
                        },
                        {
                            id: 'kw-in-title',
                            label: 'Keyword primaria nel title',
                            pts: 10,
                            pass: primaryKw && title.toLowerCase().indexOf(primaryKw) !== -1,
                            warn: false,
                            skip: kws.length === 0,
                        },
                        {
                            id: 'kw-in-desc',
                            label: 'Keyword primaria nella description',
                            pts: 10,
                            pass: primaryKw && desc.toLowerCase().indexOf(primaryKw) !== -1,
                            warn: false,
                            skip: kws.length === 0,
                        },
                    ];

                    var score = 0;
                    var html = '';
                    checks.forEach(function (c) {
                        if (c.skip) return;
                        var cls, icon;
                        if (c.pass) {
                            score += c.pts;
                            cls = 'ok'; icon = '+' + c.pts + 'pt';
                        } else if (c.warn) {
                            score += Math.round(c.pts * 0.5);
                            cls = 'warn'; icon = '+' + Math.round(c.pts * 0.5) + 'pt';
                        } else {
                            cls = 'fail'; icon = '0pt';
                        }
                        html += '<li class="wpb-score-item ' + cls + '">' +
                            '<span>' + c.label + '</span>' +
                            '<span style="margin-left:auto;font-size:10px;opacity:.6;">' + icon + '</span>' +
                            '</li>';
                    });

                    score = Math.min(100, Math.max(0, score));
                    scoreNum.textContent = score;

                    // Colore e label in stile semaforo
                    var color, label;
                    if (score >= 80) { color = '#00a32a'; label = 'Ottimo'; }
                    else if (score >= 50) { color = '#dba617'; label = 'Migliorabile'; }
                    else { color = '#d63638'; label = 'Scarso'; }

                    scoreLbl.textContent = label;
                    scoreLbl.style.color = color;
                    scoreNum.style.color = color;

                    // Arco SVG
                    var filled = (score / 100) * ARC;
                    scoreFill.setAttribute('stroke', color);
                    scoreFill.setAttribute('stroke-dasharray', filled + ' ' + (ARC - filled + 0.01));

                    scoreItems.innerHTML = html;
                }

                // Aggiorna al cambio di ogni campo
                [titleInput, descInput].forEach(function (el) {
                    if (el) el.addEventListener('input', compute);
                });
                // Aggiorna quando cambia OG image o keywords (eventi custom)
                document.addEventListener('wpb-og-changed', compute);
                document.addEventListener('wpb-kw-changed', compute);
                // Aggiorna quando i toggle robots cambiano (non influisce sul score ma
                // potrebbe influire in futuro — registriamo per completezza)
                compute();

                // Esponi compute globalmente per poterla chiamare dal tag-input
                window._wpbScoreCompute = compute;
            })();
            descInput.addEventListener('input', updateDesc);
            updateTitle(); updateDesc();
        })();
    </script>
    <?php
}


/* ─────────────────────────────────────────
   SALVATAGGIO metabox post
   ───────────────────────────────────────── */
add_action('save_post', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;
    if (!isset($_POST['_wpb_seo_nonce']))
        return;
    if (!wp_verify_nonce($_POST['_wpb_seo_nonce'], 'wpb_seo_post_meta_save_' . $post_id))
        return;
    if (!current_user_can('edit_post', $post_id))
        return;

    $fields = ['_wpb_seo_title', '_wpb_seo_description', '_wpb_seo_og_image_id'];
    foreach ($fields as $field) {
        if (!isset($_POST[$field]))
            continue;
        $val = $field === '_wpb_seo_og_image_id'
            ? absint($_POST[$field])
            : sanitize_textarea_field($_POST[$field]);
        if ($val === '' || $val === 0)
            delete_post_meta($post_id, $field);
        else
            update_post_meta($post_id, $field, $val);
    }

    // Keywords
    if (isset($_POST['_wpb_seo_keywords'])) {
        $kws = json_decode(wp_unslash($_POST['_wpb_seo_keywords']), true);
        if (is_array($kws)) {
            $kws = array_values(array_filter(array_map('sanitize_text_field', $kws)));
            if (empty($kws))
                delete_post_meta($post_id, '_wpb_seo_keywords');
            else
                update_post_meta($post_id, '_wpb_seo_keywords', wp_json_encode($kws));
        }
    }

    // Robots per-post: salva solo se il form ha il campo _set (sempre presente)
    // Il valore è '1' se il checkbox è spuntato, altrimenti non è nei POST
    // Salviamo '1' o '0' — stringa vuota significa "usa default post type"
    if (isset($_POST['_wpb_seo_index_set'])) {
        update_post_meta($post_id, '_wpb_seo_index', isset($_POST['_wpb_seo_index']) ? '1' : '0');
    }
    if (isset($_POST['_wpb_seo_follow_set'])) {
        update_post_meta($post_id, '_wpb_seo_follow', isset($_POST['_wpb_seo_follow']) ? '1' : '0');
    }
    if (isset($_POST['_wpb_seo_sitemap_set'])) {
        update_post_meta($post_id, '_wpb_seo_sitemap', isset($_POST['_wpb_seo_sitemap']) ? '1' : '0');
    }

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache($post_id);
    }
});

/* ─────────────────────────────────────────
   WPML — copia meta di indicizzazione alla duplicazione
   Quando WPML crea una traduzione, copia _wpb_seo_index,
   _wpb_seo_follow e _wpb_seo_sitemap dall'originale.
   Il post tradotto potrà poi modificarli indipendentemente.
   ───────────────────────────────────────── */

/**
 * Copia i tre meta di indicizzazione dall'originale al tradotto.
 * Scrive sempre '0' o '1' esplicitamente, in modo che il metabox
 * non mostri "default" ma il valore reale dell'originale.
 */
function wpb_seo_copy_indexing_meta(int $original_id, int $new_id): void
{
    foreach (['_wpb_seo_index', '_wpb_seo_follow', '_wpb_seo_sitemap'] as $key) {
        $val = get_post_meta($original_id, $key, true);
        // Se l'originale non ha valore esplicito (è su "default"), leggi il default
        // del post type e scrivilo esplicitamente nella traduzione.
        if ($val === '') {
            $content = get_option(WPB_META_CONTENT_OPTION, []);
            $pt = get_post_type($original_id);
            $cfg = $content['post_types'][$pt] ?? [];
            if ($key === '_wpb_seo_sitemap') {
                $sm_settings = get_option(defined('WPB_SITEMAP_OPTION') ? WPB_SITEMAP_OPTION : 'wpb_sitemap_settings', []);
                $val = isset($sm_settings['post_types'][$pt]) ? '1' : '0';
            } else {
                $field = str_replace('_wpb_seo_', '', $key); // 'index' o 'follow'
                $val = isset($cfg[$field]) ? (string) (int) $cfg[$field] : '1';
            }
        }
        update_post_meta($new_id, $key, $val);
    }
}

// Hook principale: wpml_set_element_language_details è chiamato ogni volta
// che WPML associa un post a una lingua — incluso alla creazione di una traduzione.
add_action('wpml_set_element_language_details', function ($args) {
    // $args: [ element_id, element_type, trid, language_code, source_language_code ]
    if (empty($args['element_id']) || empty($args['source_language_code']))
        return;

    $new_id = (int) $args['element_id'];

    // Trova il post originale (stessa trid, lingua sorgente)
    if (empty($args['trid']))
        return;

    $original = apply_filters('wpml_object_id', $new_id, get_post_type($new_id) ?: 'post', false, $args['source_language_code']);
    if (!$original || $original === $new_id)
        return;

    // Copia solo se il post tradotto non ha ancora valori espliciti (appena creato)
    $already_set = get_post_meta($new_id, '_wpb_seo_index', true);
    if ($already_set !== '')
        return;

    wpb_seo_copy_indexing_meta((int) $original, $new_id);
});

// Hook di copertura: wpml_after_save_post è fired dopo ogni salvataggio di traduzione
add_action('wpml_after_save_post', function ($post_id, $post_array, $job) {
    if (empty($job) || !is_object($job))
        return;

    $original_id = (int) ($job->original_doc_id ?? 0);
    if (!$original_id || $original_id === $post_id)
        return;

    // Copia solo se il tradotto non ha ancora valori propri
    if (get_post_meta($post_id, '_wpb_seo_index', true) !== '')
        return;

    wpb_seo_copy_indexing_meta($original_id, (int) $post_id);
}, 10, 3);

/* ─────────────────────────────────────────
   FORM SEO per le tassonomie
   ───────────────────────────────────────── */
add_action('init', function () {
    foreach (wpb_meta_indexed_taxonomies() as $tax) {
        // Form "aggiungi termine"
        add_action("{$tax}_add_form_fields", 'wpb_meta_render_term_add_fields');
        // Form "modifica termine"
        add_action("{$tax}_edit_form_fields", 'wpb_meta_render_term_edit_fields', 10, 2);
        // Salvataggio
        add_action("created_{$tax}", 'wpb_meta_save_term_fields');
        add_action("edited_{$tax}", 'wpb_meta_save_term_fields');
    }
});

/* ── Aggiungi termine ── */
function wpb_meta_render_term_add_fields($taxonomy)
{
    wp_nonce_field('wpb_seo_term_meta_save', '_wpb_seo_term_nonce');

    // Default dal config del post type nella tab Contenuti
    $content = get_option(WPB_META_CONTENT_OPTION, []);
    $sm_settings = get_option(defined('WPB_SITEMAP_OPTION') ? WPB_SITEMAP_OPTION : 'wpb_sitemap_settings', []);
    $tax_cfg = $content['taxonomies'][$taxonomy] ?? ['index' => 1, 'follow' => 1];

    // Sitemap: cascade WPB_SITEMAP_OPTION → WPB_META_CONTENT_OPTION → 1
    $sitemap_default = isset($sm_settings['taxonomies'][$taxonomy])
        ? (bool) $sm_settings['taxonomies'][$taxonomy]
        : (bool) ($content['taxonomies'][$taxonomy]['sitemap'] ?? 1);
    ?>
    <div class="form-field">
        <label for="wpb_seo_title">SEO Title
            <span style="font-size:11px;color:#a7aaad;font-weight:400;margin-left:4px;">(lascia vuoto per usare il nome del
                termine)</span>
        </label>
        <input type="text" name="_wpb_seo_title" id="wpb_seo_title" value="" maxlength="100">
        <p class="description">Titolo personalizzato per i meta tag SEO. Max 60 caratteri consigliati.</p>
    </div>
    <div class="form-field">
        <label for="wpb_seo_description">SEO Description
            <span style="font-size:11px;color:#a7aaad;font-weight:400;margin-left:4px;">(lascia vuoto per usare la
                descrizione del termine)</span>
        </label>
        <textarea name="_wpb_seo_description" id="wpb_seo_description" rows="3"></textarea>
        <p class="description">Descrizione personalizzata. Max 160 caratteri consigliati.</p>
    </div>
    <div class="form-field">
        <label>OG Image
            <span style="font-size:11px;color:#a7aaad;font-weight:400;margin-left:4px;">(opzionale)</span>
        </label>
        <input type="hidden" name="_wpb_seo_og_image_id" id="wpb_seo_og_image_id_add" value="">
        <div style="display:flex;gap:10px;align-items:center;margin-top:4px;">
            <div id="wpb-add-og-thumb"
                style="width:100px;height:53px;border:1px solid #dcdcde;border-radius:3px;background:#f6f7f7;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                <span style="font-size:11px;color:#c3c4c7;">Nessuna</span>
            </div>
            <div>
                <button type="button" class="button button-small" id="wpb-add-og-select">Seleziona immagine</button>
                <p class="description" style="margin-top:4px;">1200 × 630 px consigliati.</p>
            </div>
        </div>
        <?php wpb_meta_term_og_script('wpb-add-og-select', null, 'wpb_seo_og_image_id_add', 'wpb-add-og-thumb'); ?>
    </div>
    <div class="form-field">
        <label>Indicizzazione
            <span style="font-size:11px;color:#a7aaad;font-weight:400;margin-left:4px;">(default dal config
                tassonomia)</span>
        </label>
        <input type="hidden" name="_wpb_seo_index_set" value="1">
        <input type="hidden" name="_wpb_seo_follow_set" value="1">
        <input type="hidden" name="_wpb_seo_sitemap_set" value="1">
        <div style="display:flex;gap:16px;margin-top:4px;">
            <?php foreach ([
                '_wpb_seo_index' => ['label' => 'Index', 'default' => $tax_cfg['index'] ?? 1],
                '_wpb_seo_follow' => ['label' => 'Follow', 'default' => $tax_cfg['follow'] ?? 1],
                '_wpb_seo_sitemap' => ['label' => 'Sitemap', 'default' => $sitemap_default],
            ] as $name => $cfg): ?>
                <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer;">
                    <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked((bool) $cfg['default']); ?>>
                    <?php echo esc_html($cfg['label']); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="description">Sovrascrive il default della tassonomia per questo termine.</p>
    </div>
    <div class="form-field">
        <label>Keywords
            <span style="font-size:11px;color:#a7aaad;font-weight:400;margin-left:4px;">(prima = primaria)</span>
        </label>
        <input type="hidden" name="_wpb_seo_keywords" id="wpb_seo_kw_hidden_add" value="[]">
        <div class="wpb-kw-wrap" id="wpb-kw-wrap-add">
            <input type="text" class="wpb-kw-input" id="wpb-kw-input-add" placeholder="Aggiungi keyword…">
        </div>
        <p class="description">Premi <kbd>Enter</kbd> o <kbd>,</kbd> per aggiungere. La prima è la keyword primaria.</p>
        <?php wpb_meta_term_kw_script('wpb-kw-wrap-add', 'wpb-kw-input-add', 'wpb_seo_kw_hidden_add', []); ?>
    </div>
    <?php
}
function wpb_meta_render_term_edit_fields($term, $taxonomy)
{
    wp_nonce_field('wpb_seo_term_meta_save', '_wpb_seo_term_nonce');

    $custom_title = get_term_meta($term->term_id, '_wpb_seo_title', true);
    $custom_desc = get_term_meta($term->term_id, '_wpb_seo_description', true);
    $custom_og_id = (int) get_term_meta($term->term_id, '_wpb_seo_og_image_id', true);
    $custom_og_url = $custom_og_id ? wp_get_attachment_image_url($custom_og_id, 'thumbnail') : '';

    $default_title = $term->name;
    $default_desc = strip_tags($term->description);

    $s = get_option(WPB_META_OPTION, []);
    $sep = $s['separator'] ?? '—';
    $site_name = $s['site_title'] ?? get_bloginfo('name');
    $order = $s['title_order'] ?? 'page_site';
    ?>
    <tr class="form-field">
        <th scope="row">
            <label for="wpb_seo_title_edit">SEO Title</label>
        </th>
        <td>
            <input type="text" name="_wpb_seo_title" id="wpb_seo_title_edit" value="<?php echo esc_attr($custom_title); ?>"
                placeholder="<?php echo esc_attr($default_title); ?>" class="regular-text" maxlength="100">
            <p class="description">
                Lascia vuoto per usare il nome del termine (<em><?php echo esc_html($default_title); ?></em>).
                Max 60 caratteri consigliati.
                <span id="wpb-term-title-counter" style="color:#a7aaad;margin-left:6px;">
                    <span id="wpb-term-title-len"><?php echo mb_strlen($custom_title ?: $default_title); ?></span>/60
                </span>
            </p>
            <div id="wpb-term-title-preview"
                style="font-size:12px;color:#646970;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;padding:5px 10px;margin-top:6px;max-width:500px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?php
                $display = $custom_title ?: $default_title;
                $pt = '<span style="color:#1d4ed8;font-weight:600;">' . esc_html($display) . '</span>';
                $s_ = ' <span style="color:#c3c4c7;">' . esc_html($sep) . '</span> ';
                $site = esc_html($site_name);
                echo $order === 'site_page' ? $site . $s_ . $pt : $pt . $s_ . $site;
                ?>
            </div>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row">
            <label for="wpb_seo_desc_edit">SEO Description</label>
        </th>
        <td>
            <textarea name="_wpb_seo_description" id="wpb_seo_desc_edit" rows="3" class="regular-text"
                placeholder="<?php echo esc_attr($default_desc ?: 'Descrizione del termine…'); ?>"><?php echo esc_textarea($custom_desc); ?></textarea>
            <p class="description">
                Lascia vuoto per usare la descrizione del termine.
                Max 160 caratteri consigliati.
                <span id="wpb-term-desc-counter" style="color:#a7aaad;margin-left:6px;">
                    <span id="wpb-term-desc-len"><?php echo mb_strlen($custom_desc ?: $default_desc); ?></span>/160
                </span>
            </p>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row"><label>OG Image</label></th>
        <td>
            <input type="hidden" name="_wpb_seo_og_image_id" id="wpb_seo_og_image_id_edit"
                value="<?php echo esc_attr($custom_og_id ?: ''); ?>">
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div id="wpb-edit-og-thumb"
                    style="width:120px;height:63px;border:1px solid #dcdcde;border-radius:3px;background:#f6f7f7;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
                    <?php if ($custom_og_url): ?>
                        <img src="<?php echo esc_url($custom_og_url); ?>" style="width:100%;height:100%;object-fit:cover;"
                            alt="">
                    <?php else: ?>
                        <span style="font-size:11px;color:#c3c4c7;">Nessuna</span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;flex-direction:column;gap:5px;">
                    <button type="button" class="button" id="wpb-edit-og-select">
                        <?php echo $custom_og_id ? 'Cambia immagine' : 'Seleziona immagine'; ?>
                    </button>
                    <?php if ($custom_og_id): ?>
                        <button type="button" class="button" id="wpb-edit-og-remove">Rimuovi</button>
                    <?php endif; ?>
                    <p class="description">1200 × 630 px consigliati.</p>
                </div>
            </div>
            <?php wpb_meta_term_og_script('wpb-edit-og-select', 'wpb-edit-og-remove', 'wpb_seo_og_image_id_edit', 'wpb-edit-og-thumb'); ?>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row"><label>Indicizzazione</label></th>
        <td>
            <?php
            $content = get_option(WPB_META_CONTENT_OPTION, []);
            $sm_settings = get_option(defined('WPB_SITEMAP_OPTION') ? WPB_SITEMAP_OPTION : 'wpb_sitemap_settings', []);
            $tax_cfg = $content['taxonomies'][$taxonomy] ?? ['index' => 1, 'follow' => 1];

            // Sitemap: cascade WPB_SITEMAP_OPTION → WPB_META_CONTENT_OPTION → 1
            $sitemap_default = isset($sm_settings['taxonomies'][$taxonomy])
                ? (bool) $sm_settings['taxonomies'][$taxonomy]
                : (bool) ($content['taxonomies'][$taxonomy]['sitemap'] ?? 1);

            // Legge override per-termine ('1'/'0') o usa default tassonomia ('')
            $custom_index = get_term_meta($term->term_id, '_wpb_seo_index', true);
            $custom_follow = get_term_meta($term->term_id, '_wpb_seo_follow', true);
            $custom_sitemap = get_term_meta($term->term_id, '_wpb_seo_sitemap', true);

            $index_val = $custom_index !== '' ? (bool) (int) $custom_index : (bool) ($tax_cfg['index'] ?? 1);
            $follow_val = $custom_follow !== '' ? (bool) (int) $custom_follow : (bool) ($tax_cfg['follow'] ?? 1);
            $sitemap_val = $custom_sitemap !== '' ? (bool) (int) $custom_sitemap : $sitemap_default;
            ?>
            <input type="hidden" name="_wpb_seo_index_set" value="1">
            <input type="hidden" name="_wpb_seo_follow_set" value="1">
            <input type="hidden" name="_wpb_seo_sitemap_set" value="1">
            <div style="display:flex;gap:16px;">
                <?php foreach ([
                    '_wpb_seo_index' => ['label' => 'Index', 'val' => $index_val, 'custom' => $custom_index],
                    '_wpb_seo_follow' => ['label' => 'Follow', 'val' => $follow_val, 'custom' => $custom_follow],
                    '_wpb_seo_sitemap' => ['label' => 'Sitemap', 'val' => $sitemap_val, 'custom' => $custom_sitemap],
                ] as $name => $cfg): ?>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                        <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($cfg['val']); ?>>
                        <?php echo esc_html($cfg['label']); ?>
                        <?php if ($cfg['custom'] === ''): ?>
                            <span
                                style="font-size:10px;color:#a7aaad;background:#f0f0f1;padding:1px 5px;border-radius:2px;">default</span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="description">Sovrascrive il default della tassonomia per questo termine.</p>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row"><label>Keywords</label></th>
        <td>
            <?php
            $raw_kw_term = get_term_meta($term->term_id, '_wpb_seo_keywords', true);
            $kws_term = $raw_kw_term ? (array) json_decode($raw_kw_term, true) : [];
            ?>
            <input type="hidden" name="_wpb_seo_keywords" id="wpb_seo_kw_hidden_edit"
                value="<?php echo esc_attr($raw_kw_term ?: '[]'); ?>">
            <div class="wpb-kw-wrap" id="wpb-kw-wrap-edit">
                <?php foreach ($kws_term as $i => $kw): ?>
                    <span class="wpb-kw-pill <?php echo $i === 0 ? 'wpb-kw-pill-primary' : 'wpb-kw-pill-secondary'; ?>"
                        data-kw="<?php echo esc_attr($kw); ?>">
                        <?php echo esc_html($kw); ?>
                        <button type="button" class="wpb-kw-pill-remove" aria-label="Rimuovi">×</button>
                    </span>
                <?php endforeach; ?>
                <input type="text" class="wpb-kw-input" id="wpb-kw-input-edit"
                    placeholder="<?php echo empty($kws_term) ? 'Aggiungi keyword…' : ''; ?>">
            </div>
            <p class="description">Premi <kbd>Enter</kbd> o <kbd>,</kbd> per aggiungere. La prima è la keyword primaria.</p>
            <?php wpb_meta_term_kw_script('wpb-kw-wrap-edit', 'wpb-kw-input-edit', 'wpb_seo_kw_hidden_edit', $kws_term); ?>
        </td>
    </tr>
    <script>
        (function () {
            var defaultTitle = <?php echo wp_json_encode($default_title); ?>;
            var siteName = <?php echo wp_json_encode($site_name); ?>;
            var sep = <?php echo wp_json_encode($sep); ?>;
            var order = <?php echo wp_json_encode($order); ?>;

            var ti = document.getElementById('wpb_seo_title_edit');
            var tl = document.getElementById('wpb-term-title-len');
            var tc = document.getElementById('wpb-term-title-counter');
            var tp = document.getElementById('wpb-term-title-preview');
            var di = document.getElementById('wpb_seo_desc_edit');
            var dl = document.getElementById('wpb-term-desc-len');
            var dc = document.getElementById('wpb-term-desc-counter');

            function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

            function updateTitlePreview() {
                var val = ti.value || defaultTitle;
                var len = val.length;
                tl.textContent = len;
                tc.style.color = len > 60 ? '#d63638' : len >= 50 ? '#00a32a' : '#a7aaad';
                var pt = '<span style="color:#1d4ed8;font-weight:600;">' + esc(val) + '</span>';
                var s_ = ' <span style="color:#c3c4c7;">' + esc(sep) + '</span> ';
                var site = esc(siteName);
                tp.innerHTML = order === 'site_page' ? site + s_ + pt : pt + s_ + site;
            }
            function updateDescCounter() {
                var len = di.value.length;
                dl.textContent = len;
                dc.style.color = len > 160 ? '#d63638' : len >= 120 ? '#00a32a' : '#a7aaad';
            }
            if (ti) { ti.addEventListener('input', updateTitlePreview); updateTitlePreview(); }
            if (di) { di.addEventListener('input', updateDescCounter); updateDescCounter(); }

            // ── SEO Score ───────────────────────────────────────────────────
            (function () {
                var defaultTitle = <?php echo wp_json_encode($default_title); ?>;
                var defaultDesc = <?php echo wp_json_encode($default_desc); ?>;
                var hasOgDefault = <?php echo wp_json_encode(!empty($custom_og_url)); ?>;
                var ARC = 128.8;

                function compute() {
                    var title = (ti && ti.value.trim()) || defaultTitle;
                    var desc = (di && di.value.trim()) || defaultDesc;
                    var hasCustomTitle = ti && ti.value.trim() !== '';
                    var hasCustomDesc = di && di.value.trim() !== '';
                    var ogInput = document.getElementById('wpb_seo_og_image_id_edit');
                    var hasOg = (ogInput && ogInput.value) || hasOgDefault;
                    var kwHidden = document.getElementById('wpb_seo_kw_hidden_edit');
                    var kws = [];
                    if (kwHidden && kwHidden.value) {
                        try { kws = JSON.parse(kwHidden.value) || []; } catch (e) { }
                    }
                    var primaryKw = kws.length > 0 ? kws[0].toLowerCase() : '';

                    var checks = [
                        { label: 'Title personalizzato', pts: 15, pass: hasCustomTitle, warn: false },
                        { label: 'Title 30–60 car. (' + title.length + ')', pts: 10, pass: title.length >= 30 && title.length <= 60, warn: title.length > 0 && (title.length < 30 || title.length > 60) },
                        { label: 'Description personalizzata', pts: 15, pass: hasCustomDesc, warn: false },
                        { label: 'Description 120–160 car. (' + desc.length + ')', pts: 10, pass: desc.length >= 120 && desc.length <= 160, warn: desc.length > 0 && (desc.length < 120 || desc.length > 160) },
                        { label: 'Immagine OG presente', pts: 15, pass: !!hasOg, warn: false },
                        { label: 'Almeno una keyword', pts: 15, pass: kws.length > 0, warn: false },
                        { label: 'Keyword primaria nel title', pts: 10, pass: primaryKw && title.toLowerCase().indexOf(primaryKw) !== -1, warn: false, skip: kws.length === 0 },
                        { label: 'Keyword primaria nella description', pts: 10, pass: primaryKw && desc.toLowerCase().indexOf(primaryKw) !== -1, warn: false, skip: kws.length === 0 },
                    ];

                    var score = 0;
                    checks.forEach(function (c) {
                        if (c.skip) return;
                        if (c.pass) score += c.pts;
                        else if (c.warn) score += Math.round(c.pts * 0.5);
                    });
                    score = Math.min(100, Math.max(0, score));

                    var wrap = document.getElementById('wpb-term-score-wrap');
                    var snum = document.getElementById('wpb-term-score-num');
                    var slbl = document.getElementById('wpb-term-score-label');
                    var sfill = document.getElementById('wpb-term-score-fill');
                    var sitems = document.getElementById('wpb-term-score-items');
                    if (!snum) return;

                    var color, label;
                    if (score >= 80) { color = '#00a32a'; label = 'Ottimo'; }
                    else if (score >= 50) { color = '#dba617'; label = 'Migliorabile'; }
                    else { color = '#d63638'; label = 'Scarso'; }

                    snum.textContent = score; snum.style.color = color;
                    slbl.textContent = label; slbl.style.color = color;
                    var filled = (score / 100) * ARC;
                    sfill.setAttribute('stroke', color);
                    sfill.setAttribute('stroke-dasharray', filled + ' ' + (ARC - filled + 0.01));

                    var html = '';
                    checks.forEach(function (c) {
                        if (c.skip) return;
                        var cls = c.pass ? 'ok' : (c.warn ? 'warn' : 'fail');
                        var icon = c.pass ? '+' + c.pts + 'pt' : (c.warn ? '+' + Math.round(c.pts * .5) + 'pt' : '0pt');
                        html += '<li class="wpb-score-item ' + cls + '"><span>' + c.label + '</span><span style="margin-left:auto;font-size:10px;opacity:.6;">' + icon + '</span></li>';
                    });
                    sitems.innerHTML = html;
                }

                [ti, di].forEach(function (el) { if (el) el.addEventListener('input', compute); });
                document.addEventListener('wpb-kw-changed', compute);
                document.addEventListener('wpb-og-changed', compute);
                compute();
            })();
        })();
    </script>

    <!-- Punteggio SEO -->
    <tr class="form-field">
        <th scope="row">Punteggio SEO</th>
        <td>
            <div id="wpb-term-score-wrap" style="max-width:300px;">
                <div style="display:flex;align-items:center;gap:16px;">
                    <div style="position:relative;width:90px;flex-shrink:0;">
                        <svg viewBox="0 0 100 54" style="width:100%;display:block;">
                            <path d="M9,50 A41,41 0 0,1 91,50" fill="none" stroke="#f0f0f1" stroke-width="10"
                                stroke-linecap="round" />
                            <path id="wpb-term-score-fill" d="M9,50 A41,41 0 0,1 91,50" fill="none" stroke="#c3c4c7"
                                stroke-width="10" stroke-linecap="round" stroke-dasharray="0 129" />
                        </svg>
                        <div id="wpb-term-score-num"
                            style="position:absolute;bottom:4px;left:50%;transform:translateX(-50%);font-size:18px;font-weight:700;color:#1d2327;line-height:1;">
                            0</div>
                    </div>
                    <div>
                        <div id="wpb-term-score-label"
                            style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#646970;margin-bottom:6px;">
                            —</div>
                        <ul id="wpb-term-score-items" class="wpb-score-items"></ul>
                    </div>
                </div>
            </div>
        </td>
    </tr>
    <?php
}

/* ── Script media uploader OG per i term form ── */
function wpb_meta_term_og_script($select_id, $remove_id, $input_id, $thumb_id)
{
    ?>
    <script>
        (function () {
            var selectBtn = document.getElementById(<?php echo wp_json_encode($select_id); ?>);
            var removeBtn = document.getElementById(<?php echo wp_json_encode($remove_id); ?>);
            var idInput = document.getElementById(<?php echo wp_json_encode($input_id); ?>);
            var thumbWrap = document.getElementById(<?php echo wp_json_encode($thumb_id); ?>);
            var frame;
            if (!selectBtn) return;

            selectBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: 'Seleziona immagine OG', button: { text: 'Usa questa immagine' }, multiple: false, library: { type: 'image' } });
                frame.on('select', function () {
                    var att = frame.state().get('selection').first().toJSON();
                    var src = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                    idInput.value = att.id;
                    thumbWrap.innerHTML = '<img src="' + src + '" style="width:100%;height:100%;object-fit:cover;" alt="">';
                    selectBtn.textContent = 'Cambia immagine';
                    if (!document.getElementById(<?php echo wp_json_encode($remove_id ?: 'wpb-no-remove'); ?>)) {
                        var rb = document.createElement('button');
                        rb.type = 'button'; rb.className = 'wpb-gs-media-btn'; rb.id = <?php echo wp_json_encode($remove_id ?: 'wpb-og-rm-' . $select_id); ?>;
                        rb.textContent = 'Rimuovi';
                        selectBtn.after(rb);
                        rb.addEventListener('click', doRemove);
                    }
                });
                frame.open();
            });

            function doRemove() {
                idInput.value = '';
                thumbWrap.innerHTML = '<span style="font-size:11px;color:#c3c4c7;">Nessuna</span>';
                selectBtn.textContent = 'Seleziona immagine';
                var rb = removeBtn || document.getElementById(<?php echo wp_json_encode($remove_id ?: 'wpb-no-remove'); ?>);
                if (rb) rb.remove();
            }
            if (removeBtn) removeBtn.addEventListener('click', doRemove);
        })();
    </script>
    <?php
}

/* ─────────────────────────────────────────
   HELPER: script keywords tag-input per i term form
   ───────────────────────────────────────── */
function wpb_meta_term_kw_script($wrap_id, $input_id, $hidden_id, array $initial_kws)
{
    ?>
    <script>
        (function () {
            var wrap = document.getElementById(<?php echo wp_json_encode($wrap_id); ?>);
            var input = document.getElementById(<?php echo wp_json_encode($input_id); ?>);
            var hidden = document.getElementById(<?php echo wp_json_encode($hidden_id); ?>);
            if (!wrap || !input || !hidden) return;
            var dragSrc = null;
            function getKeywords() {
                var kws = [];
                wrap.querySelectorAll('.wpb-kw-pill').forEach(function (p) { kws.push(p.dataset.kw); });
                return kws;
            }
            function sync() {
                var kws = getKeywords();
                hidden.value = JSON.stringify(kws);
                wrap.querySelectorAll('.wpb-kw-pill').forEach(function (p, i) {
                    p.className = 'wpb-kw-pill ' + (i === 0 ? 'wpb-kw-pill-primary' : 'wpb-kw-pill-secondary');
                });
                input.placeholder = kws.length === 0 ? 'Aggiungi keyword\u2026' : '';
                document.dispatchEvent(new Event('wpb-kw-changed'));
            }
            function bindDrag(pill) {
                pill.draggable = true;
                pill.addEventListener('dragstart', function (e) {
                    dragSrc = this; e.dataTransfer.effectAllowed = 'move'; this.style.opacity = '.4';
                });
                pill.addEventListener('dragend', function () {
                    this.style.opacity = '';
                    wrap.querySelectorAll('.wpb-kw-pill').forEach(function (p) { p.classList.remove('wpb-kw-drag-over'); });
                });
                pill.addEventListener('dragover', function (e) {
                    e.preventDefault(); if (this !== dragSrc) this.classList.add('wpb-kw-drag-over');
                });
                pill.addEventListener('dragleave', function () { this.classList.remove('wpb-kw-drag-over'); });
                pill.addEventListener('drop', function (e) {
                    e.stopPropagation(); this.classList.remove('wpb-kw-drag-over');
                    if (dragSrc === this) return;
                    wrap.insertBefore(dragSrc, this); sync();
                });
            }
            function makePill(kw) {
                var pill = document.createElement('span');
                pill.className = 'wpb-kw-pill wpb-kw-pill-secondary';
                pill.dataset.kw = kw;
                pill.innerHTML = esc(kw) + '<button type="button" class="wpb-kw-pill-remove" aria-label="Rimuovi">\u00d7</button>';
                bindDrag(pill);
                return pill;
            }
            function addKeyword(raw) {
                var kw = raw.trim().replace(/,+$/, '').trim();
                if (!kw) return;
                var existing = getKeywords().map(function (k) { return k.toLowerCase(); });
                if (existing.indexOf(kw.toLowerCase()) !== -1) return;
                wrap.insertBefore(makePill(kw), input); sync();
            }
            wrap.querySelectorAll('.wpb-kw-pill').forEach(bindDrag);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault(); addKeyword(this.value); this.value = '';
                } else if (e.key === 'Backspace' && this.value === '') {
                    var pills = wrap.querySelectorAll('.wpb-kw-pill');
                    if (pills.length > 0) { pills[pills.length - 1].remove(); sync(); }
                }
            });
            input.addEventListener('blur', function () {
                if (this.value.trim()) { addKeyword(this.value); this.value = ''; }
            });
            wrap.addEventListener('click', function (e) {
                if (e.target.classList.contains('wpb-kw-pill-remove')) {
                    e.target.closest('.wpb-kw-pill').remove(); sync();
                } else if (e.target === wrap || e.target === input) { input.focus(); }
            });
            function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
            sync();
        })();
    </script>
    <?php
}

/* ─────────────────────────────────────────
   SALVATAGGIO term meta
   ───────────────────────────────────────── */
function wpb_meta_save_term_fields($term_id)
{
    if (!isset($_POST['_wpb_seo_term_nonce']))
        return;
    if (!wp_verify_nonce($_POST['_wpb_seo_term_nonce'], 'wpb_seo_term_meta_save'))
        return;
    if (!current_user_can('manage_categories'))
        return;

    $fields = ['_wpb_seo_title', '_wpb_seo_description', '_wpb_seo_og_image_id'];
    foreach ($fields as $field) {
        if (!isset($_POST[$field]))
            continue;
        $val = $field === '_wpb_seo_og_image_id'
            ? absint($_POST[$field])
            : sanitize_textarea_field($_POST[$field]);
        if ($val === '' || $val === 0)
            delete_term_meta($term_id, $field);
        else
            update_term_meta($term_id, $field, $val);
    }

    // Index / Follow / Sitemap per-termine
    foreach (['_wpb_seo_index', '_wpb_seo_follow', '_wpb_seo_sitemap'] as $field) {
        $set_key = $field . '_set';
        if (!isset($_POST[$set_key]))
            continue;
        update_term_meta($term_id, $field, isset($_POST[$field]) ? '1' : '0');
    }

    // Keywords
    if (isset($_POST['_wpb_seo_keywords'])) {
        $kws = json_decode(wp_unslash($_POST['_wpb_seo_keywords']), true);
        if (is_array($kws)) {
            $kws = array_values(array_filter(array_map('sanitize_text_field', $kws)));
            if (empty($kws))
                delete_term_meta($term_id, '_wpb_seo_keywords');
            else
                update_term_meta($term_id, '_wpb_seo_keywords', wp_json_encode($kws));
        }
    }
}

/* ─────────────────────────────────────────
   WPML — copia meta di indicizzazione alla duplicazione dei termini
   ───────────────────────────────────────── */

function wpb_seo_copy_indexing_term_meta(int $original_term_id, int $new_term_id, string $taxonomy = ''): void
{
    $content = get_option(WPB_META_CONTENT_OPTION, []);
    $tax = $taxonomy ?: get_term($original_term_id)->taxonomy ?? '';
    $cfg = $content['taxonomies'][$tax] ?? [];
    $sm_settings = get_option(defined('WPB_SITEMAP_OPTION') ? WPB_SITEMAP_OPTION : 'wpb_sitemap_settings', []);

    foreach (['_wpb_seo_index', '_wpb_seo_follow', '_wpb_seo_sitemap'] as $key) {
        $val = get_term_meta($original_term_id, $key, true);
        if ($val === '') {
            if ($key === '_wpb_seo_sitemap') {
                $val = isset($sm_settings['taxonomies'][$tax]) ? '1' : '0';
            } else {
                $field = str_replace('_wpb_seo_', '', $key);
                $val = isset($cfg[$field]) ? (string) (int) $cfg[$field] : '1';
            }
        }
        update_term_meta($new_term_id, $key, $val);
    }
}

// wpml_copy_term_metadata è il filtro corretto per la sincronizzazione dei termini
foreach (['_wpb_seo_index', '_wpb_seo_follow', '_wpb_seo_sitemap'] as $_wpb_dup_term_key) {
    add_filter('wpml_copy_term_metadata', function ($value, $meta_key, $original_term_id) use ($_wpb_dup_term_key) {
        if ($meta_key !== $_wpb_dup_term_key)
            return $value;
        $original_val = get_term_meta((int) $original_term_id, $meta_key, true);
        // Se l'originale non ha valore esplicito, risolvi il default della tassonomia
        if ($original_val === '') {
            $term = get_term((int) $original_term_id);
            $tax = $term->taxonomy ?? '';
            $content = get_option(WPB_META_CONTENT_OPTION, []);
            $cfg = $content['taxonomies'][$tax] ?? [];
            $sm_settings = get_option(defined('WPB_SITEMAP_OPTION') ? WPB_SITEMAP_OPTION : 'wpb_sitemap_settings', []);
            if ($meta_key === '_wpb_seo_sitemap') {
                $original_val = isset($sm_settings['taxonomies'][$tax]) ? '1' : '0';
            } else {
                $field = str_replace('_wpb_seo_', '', $meta_key);
                $original_val = isset($cfg[$field]) ? (string) (int) $cfg[$field] : '1';
            }
        }
        return $original_val;
    }, 10, 3);
}

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function wpb_meta_admin_page()
{
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'content';
    $saved = isset($_GET['saved']);
    $s = get_option(WPB_META_OPTION, []);
    $content = get_option(WPB_META_CONTENT_OPTION, []);

    // Post types e tassonomie disponibili
    $post_types = get_post_types(['public' => true], 'objects');
    $taxonomies = array_filter(get_taxonomies(['public' => true], 'objects'), fn($t) => !in_array($t->name, ['post_format', 'nav_menu', 'link_category'], true));
    unset($post_types['attachment']);
    ?>
    <div id="wpb-meta-wrap" class="wrap wpb-wrap">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
            <div class="wpb-gs-header">
                <div class="wpb-gs-header-icon"><span class="dashicons dashicons-search"></span></div>
                <div>
                    <h1>WP Barrio <span class="wpb-gs-breadcrumb">/ SEO / SEO Globale</span></h1>
                    <p class="wpb-gs-sub">Meta title, description, robots e Open Graph per ogni contenuto</p>
                </div>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-seo')); ?>"
                style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;font-size:12.5px;font-weight:600;background:#f3f4f6;color:#374151 !important;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none !important;white-space:nowrap;flex-shrink:0;align-self:flex-start;">
                <span class="dashicons dashicons-arrow-left-alt2"
                    style="font-size:14px;width:14px;height:14px;margin-top:1px;"></span>
                Strumenti SEO
            </a>
        </div>
        <hr class="wpb-gs-divider">

        <div class="wpb-gs-tabs">
            <a href="?page=wp-barrio-global-seo&tab=content"
                class="wpb-gs-tab <?php echo $tab === 'content' ? 'active' : ''; ?>">Contenuti</a>
            <a href="?page=wp-barrio-global-seo&tab=settings"
                class="wpb-gs-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>">Impostazioni</a>
        </div>

        <?php if ($saved): ?>
            <div class="wpb-gs-notice-success"><span class="dashicons dashicons-yes-alt"></span>
                <div>Impostazioni salvate.</div>
            </div>
        <?php endif; ?>

        <?php

        /* ══════════════════════════════════════════════════════════════════
           TAB: CONTENUTI
           ══════════════════════════════════════════════════════════════════ */
        if ($tab === 'content'):
            ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wpb_meta_content_save'); ?>
                <input type="hidden" name="action" value="wpb_meta_content_save">

                <!-- Post Types -->
                <div class="wpb-panel">
                    <div class="wpb-panel-header">
                        <span class="dashicons dashicons-admin-post"></span>
                        <span class="wpb-panel-header-title">Post Type</span>
                        <span class="wpb-panel-header-desc">
                            Configura indicizzazione e follow per ogni tipo di contenuto
                        </span>
                    </div>
                    <table class="wpb-content-table">
                        <thead>
                            <tr>
                                <th style="width:35%;">Tipo</th>
                                <th class="center" style="width:65px;">
                                    <span class="dashicons dashicons-search" style="font-size:13px;vertical-align:middle;"
                                        title="Indicizzabile dai motori di ricerca"></span>
                                    Index
                                </th>
                                <th class="center" style="width:65px;">
                                    <span class="dashicons dashicons-randomize" style="font-size:13px;vertical-align:middle;"
                                        title="I bot possono seguire i link"></span>
                                    Follow
                                </th>
                                <th style="width:40%;color:#646970;">Robots generato</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($post_types as $pt_obj):
                                $slug = $pt_obj->name;
                                $label = $pt_obj->labels->singular_name ?? $slug;
                                $plural = $pt_obj->labels->name ?? $slug;
                                $builtin = $pt_obj->_builtin ?? false;
                                $cfg = $content['post_types'][$slug] ?? ['index' => 1, 'follow' => 1, 'sitemap' => 1];
                                $idx = (bool) ($cfg['index'] ?? 1);
                                $follow = (bool) ($cfg['follow'] ?? 1);
                                $sitemap = (bool) ($cfg['sitemap'] ?? 1);
                                $robots = ($idx ? 'index' : 'noindex') . ', ' . ($follow ? 'follow' : 'nofollow');
                                ?>
                                <tr>
                                    <td>
                                        <span class="wpb-pt-label">
                                            <?php echo esc_html($plural); ?>
                                            <?php if ($builtin): ?>
                                                <span class="wpb-pt-badge builtin">core</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="wpb-pt-slug"><?php echo esc_html($slug); ?></span>
                                    </td>
                                    <td class="center">
                                        <label class="wpb-toggle">
                                            <input type="checkbox" name="pt[<?php echo esc_attr($slug); ?>][index]" value="1" <?php checked($idx); ?> class="wpb-robots-cb"
                                                data-row="pt-<?php echo esc_attr($slug); ?>">
                                            <span class="wpb-toggle-track"></span>
                                        </label>
                                    </td>
                                    <td class="center">
                                        <label class="wpb-toggle">
                                            <input type="checkbox" name="pt[<?php echo esc_attr($slug); ?>][follow]" value="1" <?php checked($follow); ?> class="wpb-robots-cb"
                                                data-row="pt-<?php echo esc_attr($slug); ?>">
                                            <span class="wpb-toggle-track"></span>
                                        </label>
                                    </td>
                                    <td>
                                        <code class="wpb-robots-preview" id="robots-pt-<?php echo esc_attr($slug); ?>">
                                                                            <?php echo esc_html($robots); ?>
                                                                        </code>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Tassonomie -->
                <div class="wpb-panel">
                    <div class="wpb-panel-header">
                        <span class="dashicons dashicons-tag"></span>
                        <span class="wpb-panel-header-title">Tassonomie</span>
                        <span class="wpb-panel-header-desc">
                            Configura indicizzazione e follow per ogni tassonomia
                        </span>
                    </div>
                    <?php if (empty($taxonomies)): ?>
                        <p style="padding:20px;color:#646970;font-size:13px;">Nessuna tassonomia pubblica trovata.</p>
                    <?php else: ?>
                        <table class="wpb-content-table">
                            <thead>
                                <tr>
                                    <th style="width:35%;">Tassonomia</th>
                                    <th class="center" style="width:65px;">Index</th>
                                    <th class="center" style="width:65px;">Follow</th>
                                    <th style="width:40%;color:#646970;">Robots generato</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($taxonomies as $tax_obj):
                                    $slug = $tax_obj->name;
                                    $label = $tax_obj->labels->name ?? $slug;
                                    $builtin = in_array($slug, ['category', 'post_tag', 'nav_menu', 'link_category', 'post_format']);
                                    $cfg = $content['taxonomies'][$slug] ?? ['index' => 1, 'follow' => 1, 'sitemap' => 1];
                                    $idx = (bool) ($cfg['index'] ?? 1);
                                    $follow = (bool) ($cfg['follow'] ?? 1);
                                    $sitemap = (bool) ($cfg['sitemap'] ?? 1);
                                    $robots = ($idx ? 'index' : 'noindex') . ', ' . ($follow ? 'follow' : 'nofollow');
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="wpb-pt-label">
                                                <?php echo esc_html($label); ?>
                                                <?php if ($builtin): ?>
                                                    <span class="wpb-pt-badge builtin">core</span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="wpb-pt-slug"><?php echo esc_html($slug); ?></span>
                                        </td>
                                        <td class="center">
                                            <label class="wpb-toggle">
                                                <input type="checkbox" name="tax[<?php echo esc_attr($slug); ?>][index]" value="1" <?php checked($idx); ?> class="wpb-robots-cb"
                                                    data-row="tax-<?php echo esc_attr($slug); ?>">
                                                <span class="wpb-toggle-track"></span>
                                            </label>
                                        </td>
                                        <td class="center">
                                            <label class="wpb-toggle">
                                                <input type="checkbox" name="tax[<?php echo esc_attr($slug); ?>][follow]" value="1"
                                                    <?php checked($follow); ?> class="wpb-robots-cb"
                                                    data-row="tax-<?php echo esc_attr($slug); ?>">
                                                <span class="wpb-toggle-track"></span>
                                            </label>
                                        </td>
                                        <td>
                                            <code class="wpb-robots-preview" id="robots-tax-<?php echo esc_attr($slug); ?>">
                                                                                        <?php echo esc_html($robots); ?>
                                                                                    </code>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <button type="submit" class="wpb-gs-save-btn"><span class="dashicons dashicons-yes"
                        style="font-size:16px;width:16px;height:16px;"></span> Salva impostazioni contenuti</button>
            </form>

            <script>
                (function () {
                    document.querySelectorAll('.wpb-robots-cb').forEach(function (cb) {
                        cb.addEventListener('change', function () {
                            var row = this.dataset.row;
                            var cells = document.querySelectorAll('[data-row="' + row + '"]');
                            var idx = false, follow = false;
                            cells.forEach(function (c) {
                                var name = c.name || '';
                                if (name.indexOf('[index]') !== -1) idx = c.checked;
                                if (name.indexOf('[follow]') !== -1) follow = c.checked;
                            });
                            var preview = document.getElementById('robots-' + row);
                            if (preview) {
                                preview.textContent = (idx ? 'index' : 'noindex') + ', ' + (follow ? 'follow' : 'nofollow');
                            }
                        });
                    });
                })();
            </script>

            <?php

            /* ══════════════════════════════════════════════════════════════════
               TAB: IMPOSTAZIONI
               ══════════════════════════════════════════════════════════════════ */
        elseif ($tab === 'settings'):

            $site_title = $s['site_title'] ?? get_bloginfo('name');
            $separator = $s['separator'] ?? '—';
            $title_order = $s['title_order'] ?? 'page_site';
            $og_image_id = (int) ($s['og_image_id'] ?? 0);
            $og_image_url = $og_image_id ? wp_get_attachment_image_url($og_image_id, 'thumbnail') : '';
            $favicon_id = (int) ($s['favicon_id'] ?? 0);
            $favicon_url = $favicon_id ? wp_get_attachment_image_url($favicon_id, 'thumbnail') : '';
            $default_desc = $s['default_desc'] ?? '';
            ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wpb_meta_settings_save'); ?>
                <input type="hidden" name="action" value="wpb_meta_settings_save">
                <input type="hidden" name="wpb_meta_og_image_id" id="wpb-og-image-id-field"
                    value="<?php echo esc_attr($og_image_id); ?>">
                <input type="hidden" name="wpb_meta_favicon_id" id="wpb-favicon-id-field"
                    value="<?php echo esc_attr($favicon_id); ?>">

                <!-- ── Identità del sito ──────────────────────────────────── -->
                <div class="wpb-panel">
                    <div class="wpb-panel-header">
                        <span class="dashicons dashicons-admin-home"></span>
                        <span class="wpb-panel-header-title">Identità del sito</span>
                    </div>
                    <div class="wpb-settings-section">

                        <!-- Titolo del sito -->
                        <div class="wpb-field-row">
                            <div class="wpb-field-label">
                                Titolo del sito
                                <span class="wpb-field-desc">Usato come suffisso/prefisso nel &lt;title&gt;</span>
                            </div>
                            <div class="wpb-field-input">
                                <input type="text" name="wpb_meta_site_title" id="wpb-site-title"
                                    value="<?php echo esc_attr($site_title); ?>" class="regular-text"
                                    placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                            </div>
                        </div>

                        <!-- Separatore -->
                        <div class="wpb-field-row">
                            <div class="wpb-field-label">
                                Separatore
                                <span class="wpb-field-desc">Carattere tra titolo pagina e nome sito</span>
                            </div>
                            <div class="wpb-field-input">
                                <input type="text" name="wpb_meta_separator" id="wpb-separator"
                                    value="<?php echo esc_attr($separator); ?>" style="width:60px;" maxlength="5">
                                <div class="wpb-title-preview">
                                    <span id="wpb-prev-page">Titolo pagina</span>
                                    <span class="sep" id="wpb-prev-sep"><?php echo esc_html($separator); ?></span>
                                    <span id="wpb-prev-site"><?php echo esc_html($site_title ?: get_bloginfo('name')); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Ordine titolo -->
                        <div class="wpb-field-row">
                            <div class="wpb-field-label">
                                Ordine titolo
                                <span class="wpb-field-desc">Come viene costruito il &lt;title&gt;</span>
                            </div>
                            <div class="wpb-field-input">
                                <div class="wpb-order-options">
                                    <label class="wpb-order-option">
                                        <input type="radio" name="wpb_meta_title_order" value="page_site" <?php checked($title_order, 'page_site'); ?>>
                                        <div>
                                            <div>Pagina prima</div>
                                            <div class="wpb-order-preview">Titolo pagina — Nome sito</div>
                                        </div>
                                    </label>
                                    <label class="wpb-order-option">
                                        <input type="radio" name="wpb_meta_title_order" value="site_page" <?php checked($title_order, 'site_page'); ?>>
                                        <div>
                                            <div>Sito prima</div>
                                            <div class="wpb-order-preview">Nome sito — Titolo pagina</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Favicon -->
                        <div class="wpb-field-row">
                            <div class="wpb-field-label">
                                Favicon
                                <span class="wpb-field-desc">Icona del sito (browser, bookmark, SERP)</span>
                            </div>
                            <div class="wpb-field-input">
                                <div class="wpb-og-image-wrap">
                                    <div class="wpb-og-image-preview" id="wpb-favicon-preview"
                                        style="width:48px;height:48px;border-radius:6px;">
                                        <?php if ($favicon_url): ?>
                                            <img src="<?php echo esc_url($favicon_url); ?>" id="wpb-favicon-preview-img" alt="">
                                        <?php else: ?>
                                            <div class="wpb-og-placeholder" id="wpb-favicon-placeholder">
                                                <span class="dashicons dashicons-admin-site"
                                                    style="font-size:22px;width:22px;height:22px;color:#dcdcde;display:block;margin:0 auto;"></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="wpb-og-image-actions">
                                        <button type="button" class="wpb-gs-media-btn" id="wpb-favicon-select">
                                            <?php echo $favicon_url ? 'Cambia favicon' : 'Seleziona favicon'; ?>
                                        </button>
                                        <?php if ($favicon_url): ?>
                                            <button type="button" class="wpb-gs-media-btn" id="wpb-favicon-remove">Rimuovi</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="wpb-field-hint">Consigliato: 512×512 px, formato PNG o SVG.</div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ── Default meta ───────────────────────────────────────── -->
                <div class="wpb-panel">
                    <div class="wpb-panel-header">
                        <span class="dashicons dashicons-editor-code"></span>
                        <span class="wpb-panel-header-title">Meta tag di default</span>
                    </div>
                    <div class="wpb-settings-section">

                        <!-- Descrizione di default -->
                        <div class="wpb-field-row">
                            <div class="wpb-field-label">
                                Descrizione di default
                                <span class="wpb-field-desc">Usata quando la pagina non ha excerpt</span>
                            </div>
                            <div class="wpb-field-input">
                                <textarea name="wpb_meta_default_desc"
                                    rows="3"><?php echo esc_textarea($default_desc); ?></textarea>
                                <div class="wpb-field-hint">Idealmente tra 120 e 160 caratteri.</div>
                            </div>
                        </div>

                        <!-- Immagine OG di default -->
                        <div class="wpb-field-row">
                            <div class="wpb-field-label">
                                Immagine di condivisione
                                <span class="wpb-field-desc">og:image e twitter:image di default</span>
                            </div>
                            <div class="wpb-field-input">
                                <div class="wpb-og-image-wrap">
                                    <div class="wpb-og-image-preview" id="wpb-og-preview">
                                        <?php if ($og_image_url): ?>
                                            <img src="<?php echo esc_url($og_image_url); ?>" id="wpb-og-preview-img" alt="">
                                        <?php else: ?>
                                            <div class="wpb-og-placeholder" id="wpb-og-placeholder">
                                                <span class="dashicons dashicons-format-image"
                                                    style="font-size:22px;width:22px;height:22px;color:#dcdcde;margin-bottom:4px;display:block;"></span>
                                                Nessuna immagine
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="wpb-og-image-actions">
                                        <button type="button" class="wpb-gs-media-btn" id="wpb-og-select">
                                            <?php echo $og_image_url ? 'Cambia immagine' : 'Seleziona immagine'; ?>
                                        </button>
                                        <?php if ($og_image_url): ?>
                                            <button type="button" class="wpb-gs-media-btn" id="wpb-og-remove">Rimuovi</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="wpb-field-hint">Dimensione consigliata: 1200 × 630 px.</div>
                            </div>
                        </div>

                    </div>
                </div>

                <button type="submit" class="wpb-gs-save-btn"><span class="dashicons dashicons-yes"
                        style="font-size:16px;width:16px;height:16px;"></span> Salva impostazioni</button>
            </form>

            <script>
                (function () {
                    // ── Preview titolo live ──────────────────────────────────────
                    var titleInput = document.getElementById('wpb-site-title');
                    var sepInput = document.getElementById('wpb-separator');
                    var prevSep = document.getElementById('wpb-prev-sep');
                    var prevSite = document.getElementById('wpb-prev-site');

                    if (titleInput) {
                        titleInput.addEventListener('input', function () {
                            prevSite.textContent = this.value || '<?php echo esc_js(get_bloginfo('name')); ?>';
                        });
                    }
                    if (sepInput) {
                        sepInput.addEventListener('input', function () {
                            prevSep.textContent = this.value || '—';
                        });
                    }

                    // ── Media uploader OG image ──────────────────────────────────
                    var selectBtn = document.getElementById('wpb-og-select');
                    var removeBtn = document.getElementById('wpb-og-remove');
                    var idField = document.getElementById('wpb-og-image-id-field');
                    var previewWrap = document.getElementById('wpb-og-preview');
                    var frame;

                    if (selectBtn) {
                        selectBtn.addEventListener('click', function (e) {
                            e.preventDefault();
                            if (frame) { frame.open(); return; }
                            frame = wp.media({
                                title: 'Seleziona immagine di condivisione',
                                button: { text: 'Usa questa immagine' },
                                multiple: false,
                                library: { type: 'image' }
                            });
                            frame.on('select', function () {
                                var att = frame.state().get('selection').first().toJSON();
                                idField.value = att.id;
                                var src = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                                previewWrap.innerHTML = '<img src="' + src + '" alt="" style="width:100%;height:100%;object-fit:cover;">';
                                selectBtn.textContent = 'Cambia immagine';
                                if (!removeBtn) {
                                    var rb = document.createElement('button');
                                    rb.type = 'button';
                                    rb.className = 'wpb-gs-media-btn';
                                    rb.id = 'wpb-og-remove';
                                    rb.textContent = 'Rimuovi';
                                    selectBtn.after(rb);
                                    rb.addEventListener('click', doRemove);
                                }
                            });
                            frame.open();
                        });
                    }

                    function doRemove() {
                        idField.value = 0;
                        previewWrap.innerHTML = '<div class="wpb-og-placeholder"><span class="dashicons dashicons-format-image" style="font-size:22px;width:22px;height:22px;color:#dcdcde;margin-bottom:4px;display:block;"></span>Nessuna immagine</div>';
                        selectBtn.textContent = 'Seleziona immagine';
                        var rb = document.getElementById('wpb-og-remove');
                        if (rb) rb.remove();
                    }

                    if (removeBtn) { removeBtn.addEventListener('click', doRemove); }
                })();

                // ── Favicon uploader ─────────────────────────────────────────────
                (function () {
                    var selectBtn = document.getElementById('wpb-favicon-select');
                    var removeBtn = document.getElementById('wpb-favicon-remove');
                    var idField = document.getElementById('wpb-favicon-id-field');
                    var previewWrap = document.getElementById('wpb-favicon-preview');
                    var frame;

                    if (!selectBtn) return;

                    selectBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        if (frame) { frame.open(); return; }
                        frame = wp.media({
                            title: 'Seleziona favicon',
                            button: { text: 'Usa questa immagine' },
                            multiple: false,
                            library: { type: 'image' }
                        });
                        frame.on('select', function () {
                            var att = frame.state().get('selection').first().toJSON();
                            var src = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
                            idField.value = att.id;
                            previewWrap.innerHTML = '<img src="' + src + '" alt="" style="width:100%;height:100%;object-fit:cover;">';
                            selectBtn.textContent = 'Cambia favicon';
                            if (!document.getElementById('wpb-favicon-remove')) {
                                var rb = document.createElement('button');
                                rb.type = 'button'; rb.className = 'wpb-gs-media-btn'; rb.id = 'wpb-favicon-remove';
                                rb.textContent = 'Rimuovi';
                                selectBtn.after(rb);
                                rb.addEventListener('click', doRemoveFavicon);
                            }
                        });
                        frame.open();
                    });

                    function doRemoveFavicon() {
                        idField.value = 0;
                        previewWrap.innerHTML = '<div class="wpb-og-placeholder"><span class="dashicons dashicons-admin-site" style="font-size:22px;width:22px;height:22px;color:#dcdcde;display:block;margin:0 auto;"></span></div>';
                        selectBtn.textContent = 'Seleziona favicon';
                        var rb = document.getElementById('wpb-favicon-remove');
                        if (rb) rb.remove();
                    }

                    if (removeBtn) { removeBtn.addEventListener('click', doRemoveFavicon); }
                })();
            </script>

        <?php endif; // tab ?>

    </div><!-- #wpb-meta-wrap -->
    <?php
}