<?php
/**
 * Suite Barrio — SEO / Seo Globale
 * Gestione meta title, description, robots e og per ogni post type e tassonomia.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   COSTANTI
   ───────────────────────────────────────── */
if (!defined('SUITE_BARRIO_META_OPTION'))
    define('SUITE_BARRIO_META_OPTION', 'suite_barrio_meta_settings');
if (!defined('SUITE_BARRIO_META_CONTENT_OPTION'))
    define('SUITE_BARRIO_META_CONTENT_OPTION', 'suite_barrio_meta_content');

/* ─────────────────────────────────────────
   MENU — voce nascosta ma con parent corretto
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!suite_barrio_tools_module_enabled('seo'))
        return;

    // Parent suite-barrio: WordPress sa che appartiene a questo menu
    // (necessario perché il menu rimanga aperto sulla pagina)
    add_submenu_page(
        'suite-barrio',
        'Seo Globale — Suite Barrio',
        'Seo Globale',
        'manage_options',
        'suite-barrio-global-seo',
        'suite_barrio_meta_admin_page'
    );
}, 12);

// Evidenzia "SEO" come voce attiva quando si è in Seo Globale
add_filter('submenu_file', function ($submenu_file) {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    if (isset($_GET['page']) && $_GET['page'] === 'suite-barrio-global-seo')
        return 'suite-barrio-seo';
    return $submenu_file;
});

/* ─────────────────────────────────────────
   ENQUEUE — CSS menu nascosto + media uploader + script inline
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    // Nasconde la voce di sottomenu dal menu di navigazione (deve valere su
    // tutte le pagine admin, perché il menu laterale è visibile ovunque)
    wp_add_inline_style('suite-barrio-admin', '#adminmenu a[href="admin.php?page=suite-barrio-global-seo"]{display:none!important}');

    // Pagina impostazioni Meta Tags
    if ($hook === 'suite-barrio_page_suite-barrio-global-seo') {
        wp_enqueue_media();
        wp_register_script('suite-barrio-seo-global-settings', '', [], SUITE_BARRIO_VERSION, true);
        wp_enqueue_script('suite-barrio-seo-global-settings');
        return;
    }
    // Editor post/pagine (metabox) e form tassonomie
    if (in_array($hook, ['post.php', 'post-new.php', 'edit-tags.php', 'term.php'])) {
        wp_enqueue_media();
    }
    // Metabox SEO su post/pagine: JS inline della metabox
    if (in_array($hook, ['post.php', 'post-new.php'])) {
        wp_register_script('suite-barrio-seo-post-metabox', '', [], SUITE_BARRIO_VERSION, true);
        wp_enqueue_script('suite-barrio-seo-post-metabox');
    }
    // Form tassonomie (aggiungi/modifica termine): JS inline dei campi SEO
    if (in_array($hook, ['edit-tags.php', 'term.php'])) {
        wp_register_script('suite-barrio-seo-term-fields', '', [], SUITE_BARRIO_VERSION, true);
        wp_enqueue_script('suite-barrio-seo-term-fields');
    }
});

/* ─────────────────────────────────────────
   SALVATAGGIO — Tab Contenuti
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_meta_content_save', function () {
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');

    check_admin_referer('suite_barrio_meta_content_save');

    $post_types = get_post_types(['public' => true]);
    $taxonomies = array_diff(get_taxonomies(['public' => true]), ['post_format', 'nav_menu', 'link_category']);

    $current = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);

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

    update_option(SUITE_BARRIO_META_CONTENT_OPTION, $current);
    if (function_exists('suite_barrio_invalidate_vercel_cache')) {
        suite_barrio_invalidate_vercel_cache('wp:seo');
    }
    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-global-seo&tab=content&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   SALVATAGGIO — Tab Impostazioni
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_meta_settings_save', function () {
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');

    check_admin_referer('suite_barrio_meta_settings_save');

    // Gestione upload immagine OG
    $og_image_id = (int) get_option(SUITE_BARRIO_META_OPTION . '_og_image_id', 0);
    if (!empty($_POST['suite_barrio_meta_og_image_id'])) {
        $og_image_id = absint($_POST['suite_barrio_meta_og_image_id']);
    }

    // Gestione favicon
    $favicon_id = isset($_POST['suite_barrio_meta_favicon_id']) ? absint($_POST['suite_barrio_meta_favicon_id']) : 0;

    $settings = [
        'site_title' => sanitize_text_field(wp_unslash($_POST['suite_barrio_meta_site_title'] ?? '')),
        'separator' => sanitize_text_field(wp_unslash($_POST['suite_barrio_meta_separator'] ?? '—')),
        'title_order' => sanitize_text_field(wp_unslash($_POST['suite_barrio_meta_title_order'] ?? 'page_site')),
        'og_image_id' => $og_image_id,
        'favicon_id' => $favicon_id,
        'default_desc' => sanitize_textarea_field(wp_unslash($_POST['suite_barrio_meta_default_desc'] ?? '')),
    ];

    update_option(SUITE_BARRIO_META_OPTION, $settings);

    // Sincronizza con il titolo del sito WordPress
    if (!empty($settings['site_title']))
        update_option('blogname', $settings['site_title']);

    // Sincronizza la favicon con l'icona del sito WordPress (site_icon)
    if ($favicon_id)
        update_option('site_icon', $favicon_id);
    else
        delete_option('site_icon');

    if (function_exists('suite_barrio_invalidate_vercel_cache')) {
        suite_barrio_invalidate_vercel_cache('wp:seo');
    }

    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-global-seo&tab=settings&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   OUTPUT META TAGS nel <head> del frontend
   ───────────────────────────────────────── */
add_action('wp_head', 'suite_barrio_meta_output_tags', 1);
function suite_barrio_meta_output_tags()
{
    if (!suite_barrio_tools_module_enabled('seo'))
        return;

    $settings = get_option(SUITE_BARRIO_META_OPTION, []);
    $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);

    $sep = $settings['separator'] ?? '—';
    $site_name = $settings['site_title'] ?? get_bloginfo('name');
    $order = $settings['title_order'] ?? 'page_site';

    // ── Determina robots per il tipo corrente ────────────────────────────
    $robots = 'index,follow'; // fallback fisso

    if (is_singular()) {
        $pt = get_post_type();
        $cfg = $content['post_types'][$pt] ?? ['index' => 1, 'follow' => 1];

        // Legge override per-post ('' = non impostato, usa default post type)
        $post_index = get_post_meta(get_the_ID(), '_suite_barrio_seo_index', true);
        $post_follow = get_post_meta(get_the_ID(), '_suite_barrio_seo_follow', true);

        $idx = $post_index !== '' ? (bool) (int) $post_index : (bool) ($cfg['index'] ?? 1);
        $follow = $post_follow !== '' ? (bool) (int) $post_follow : (bool) ($cfg['follow'] ?? 1);
        $robots = ($idx ? 'index' : 'noindex') . ',' . ($follow ? 'follow' : 'nofollow');
    } elseif (is_tax() || is_category() || is_tag()) {
        $term = get_queried_object();
        $tax = $term ? $term->taxonomy : '';
        $cfg = $content['taxonomies'][$tax] ?? ['index' => 1, 'follow' => 1];

        // Legge override per-termine ('1'/'0') o usa default tassonomia ('')
        $term_index = $term ? get_term_meta($term->term_id, '_suite_barrio_seo_index', true) : '';
        $term_follow = $term ? get_term_meta($term->term_id, '_suite_barrio_seo_follow', true) : '';

        $idx = $term_index !== '' ? (bool) (int) $term_index : (bool) ($cfg['index'] ?? 1);
        $follow = $term_follow !== '' ? (bool) (int) $term_follow : (bool) ($cfg['follow'] ?? 1);
        $robots = ($idx ? 'index' : 'noindex') . ',' . ($follow ? 'follow' : 'nofollow');
    }

    // ── Title personalizzato ─────────────────────────────────────────────
    if (is_singular()) {
        $custom_title = get_post_meta(get_the_ID(), '_suite_barrio_seo_title', true);
        if (!empty($custom_title)) {
            add_filter('document_title_parts', function ($parts) use ($custom_title) {
                $parts['title'] = $custom_title;
                return $parts;
            }, 99);
        }
    } elseif (is_tax() || is_category() || is_tag()) {
        $term = get_queried_object();
        if ($term) {
            $custom_title = get_term_meta($term->term_id, '_suite_barrio_seo_title', true);
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
        $description = get_post_meta(get_the_ID(), '_suite_barrio_seo_description', true);
        if (empty($description))
            $description = mb_substr(wp_strip_all_tags(strip_shortcodes(get_post_field('post_content', get_the_ID()))), 0, 160);
    } elseif (is_tax() || is_category() || is_tag()) {
        $term = get_queried_object();
        if ($term) {
            $description = get_term_meta($term->term_id, '_suite_barrio_seo_description', true);
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
        $custom_og_id = (int) get_post_meta(get_the_ID(), '_suite_barrio_seo_og_image_id', true);
        if ($custom_og_id)
            $og_image_url = wp_get_attachment_image_url($custom_og_id, 'large');
        if (empty($og_image_url) && has_post_thumbnail())
            $og_image_url = get_the_post_thumbnail_url(null, 'large');
    } elseif (is_tax() || is_category() || is_tag()) {
        $term = get_queried_object();
        if ($term) {
            $custom_og_id = (int) get_term_meta($term->term_id, '_suite_barrio_seo_og_image_id', true);
            if ($custom_og_id)
                $og_image_url = wp_get_attachment_image_url($custom_og_id, 'large');
        }
    }
    if (empty($og_image_url) && !empty($settings['og_image_id']))
        $og_image_url = wp_get_attachment_image_url($settings['og_image_id'], 'large');

    // ── Canonical (sempre attivo) ─────────────────────────────────────────
    $canonical = is_singular() ? get_permalink() : '';

    echo "\n<!-- Suite Barrio SEO / Seo Globale -->\n";

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

    echo "<!-- / Suite Barrio SEO -->\n\n";
}

/* ─────────────────────────────────────────
   HELPER: post type e tassonomie abilitati all'index
   ───────────────────────────────────────── */
function suite_barrio_meta_indexed_post_types()
{
    $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);
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

function suite_barrio_meta_indexed_taxonomies()
{
    $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);
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
    foreach (suite_barrio_meta_indexed_post_types() as $pt) {
        add_meta_box(
            'suite_barrio_seo_meta_box',
            'SEO',
            'suite_barrio_meta_render_post_metabox',
            $pt,
            'normal',
            'high'
        );
    }
});

function suite_barrio_meta_render_post_metabox($post)
{
    wp_nonce_field('suite_barrio_seo_post_meta_save_' . $post->ID, '_suite_barrio_seo_nonce');

    $custom_title = get_post_meta($post->ID, '_suite_barrio_seo_title', true);
    $custom_desc = get_post_meta($post->ID, '_suite_barrio_seo_description', true);
    $custom_og_id = (int) get_post_meta($post->ID, '_suite_barrio_seo_og_image_id', true);

    $raw_kw = get_post_meta($post->ID, '_suite_barrio_seo_keywords', true);
    $keywords = $raw_kw ? (array) json_decode($raw_kw, true) : [];

    // Robots: custom per post oppure default del post type
    $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);
    $pt_slug = $post->post_type;
    $pt_cfg = $content['post_types'][$pt_slug] ?? ['index' => 1, 'follow' => 1, 'sitemap' => 1];

    $custom_index = get_post_meta($post->ID, '_suite_barrio_seo_index', true);
    $custom_follow = get_post_meta($post->ID, '_suite_barrio_seo_follow', true);
    $custom_sitemap = get_post_meta($post->ID, '_suite_barrio_seo_sitemap', true);
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

    $s = get_option(SUITE_BARRIO_META_OPTION, []);
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
    <div class="suite-barrio-seo-mb">

        <!-- ── Colonna campi ── -->
        <div class="suite-barrio-seo-fields">

            <!-- Title -->
            <div class="suite-barrio-seo-field-row">
                <span class="suite-barrio-seo-field-label">Title</span>
                <input type="text" id="suite-barrio-mb-title" name="_suite_barrio_seo_title" value="<?php echo esc_attr($custom_title); ?>"
                    placeholder="<?php echo esc_attr($default_title); ?>">
                <span class="suite-barrio-seo-counter" id="suite-barrio-mb-title-ctr">
                    <span id="suite-barrio-mb-title-len"><?php echo esc_html(mb_strlen($display_title)); ?></span>/60
                </span>
            </div>

            <!-- Description -->
            <div class="suite-barrio-seo-field-row">
                <span class="suite-barrio-seo-field-label">Description</span>
                <textarea id="suite-barrio-mb-desc" name="_suite_barrio_seo_description" rows="3"
                    placeholder="<?php echo esc_attr($default_desc ?: 'Estratto del post…'); ?>"><?php echo esc_textarea($custom_desc); ?></textarea>
                <span class="suite-barrio-seo-counter" id="suite-barrio-mb-desc-ctr">
                    <span id="suite-barrio-mb-desc-len"><?php echo esc_html(mb_strlen($display_desc)); ?></span>/160
                </span>
            </div>

            <!-- OG Image -->
            <div class="suite-barrio-seo-field-row">
                <span class="suite-barrio-seo-field-label">Immagine OG</span>
                <input type="hidden" name="_suite_barrio_seo_og_image_id" id="suite-barrio-mb-og-id"
                    value="<?php echo esc_attr($custom_og_id ?: ''); ?>">
                <div class="suite-barrio-seo-og-row">
                    <div class="suite-barrio-seo-og-thumb" id="suite-barrio-mb-og-thumb" title="Clicca per cambiare">
                        <?php if ($og_thumb_url): ?>
                            <img src="<?php echo esc_url($og_thumb_url); ?>"
                                style="<?php echo !$custom_og_id ? 'opacity:.45;' : ''; ?>" alt="" id="suite-barrio-mb-og-thumb-img">
                        <?php else: ?>
                            <span class="dashicons dashicons-format-image"></span>
                        <?php endif; ?>
                    </div>
                    <div class="suite-barrio-seo-og-btns">
                        <button type="button" class="button button-small" id="suite-barrio-mb-og-select">
                            <?php echo $custom_og_id ? 'Cambia' : 'Seleziona'; ?>
                        </button>
                        <?php if ($custom_og_id): ?>
                            <button type="button" class="button button-small" id="suite-barrio-mb-og-remove">✕</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Keywords -->
            <div class="suite-barrio-seo-field-row">
                <span class="suite-barrio-seo-field-label">Keywords</span>
                <input type="hidden" name="_suite_barrio_seo_keywords" id="suite-barrio-mb-kw-hidden"
                    value="<?php echo esc_attr($raw_kw ?: '[]'); ?>">
                <div class="suite-barrio-kw-wrap" id="suite-barrio-mb-kw-wrap">
                    <?php foreach ($keywords as $i => $kw): ?>
                        <span class="suite-barrio-kw-pill <?php echo $i === 0 ? 'suite-barrio-kw-pill-primary' : 'suite-barrio-kw-pill-secondary'; ?>"
                            data-kw="<?php echo esc_attr($kw); ?>">
                            <?php echo esc_html($kw); ?>
                            <button type="button" class="suite-barrio-kw-pill-remove" aria-label="Rimuovi">×</button>
                        </span>
                    <?php endforeach; ?>
                    <input type="text" class="suite-barrio-kw-input" id="suite-barrio-mb-kw-input"
                        placeholder="<?php echo empty($keywords) ? 'Aggiungi keyword…' : ''; ?>">
                </div>
                <span class="suite-barrio-kw-hint">Premi <kbd>Enter</kbd> o <kbd>,</kbd> per aggiungere. La prima è la keyword
                    primaria.</span>
            </div>

            <!-- Pagina archivio (CollectionPage) -->
            <?php $collection_pt = get_post_meta($post->ID, '_suite_barrio_schema_collection', true); ?>
            <div class="suite-barrio-seo-field-row">
                <span class="suite-barrio-seo-field-label">Pagina archivio</span>
                <select name="_suite_barrio_schema_collection">
                    <option value="">— No (WebPage normale) —</option>
                    <?php foreach (get_post_types(['public' => true], 'objects') as $pt_obj): ?>
                        <?php if ($pt_obj->name === 'attachment')
                            continue; ?>
                        <option value="<?php echo esc_attr($pt_obj->name); ?>" <?php selected($collection_pt, $pt_obj->name); ?>>
                            <?php echo esc_html($pt_obj->labels->name ?: $pt_obj->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="suite-barrio-kw-hint">Marca questa pagina come <code>CollectionPage</code> nello schema, con
                    l'elenco (<code>ItemList</code>) dei contenuti pubblicati del tipo scelto.</span>
            </div>

            <!-- Indicizzazione -->
            <div class="suite-barrio-seo-field-row" style="margin-top: 20px;">
                <span class="suite-barrio-seo-field-label">Indicizzazione</span>
                <input type="hidden" name="_suite_barrio_seo_index_set" value="1">
                <input type="hidden" name="_suite_barrio_seo_follow_set" value="1">
                <input type="hidden" name="_suite_barrio_seo_sitemap_set" value="1">
                <div class="suite-barrio-seo-robots-row">
                    <div class="suite-barrio-seo-robots-item">
                        <label>
                            <span class="suite-barrio-tog">
                                <input type="checkbox" id="suite-barrio-mb-index" name="_suite_barrio_seo_index" value="1" <?php checked($index_val); ?>>
                                <span class="suite-barrio-tog-sl"></span>
                            </span>
                            Index
                            <?php if ($custom_index === ''): ?>
                                <span class="suite-barrio-robots-badge" id="suite-barrio-index-badge">default</span>
                            <?php endif; ?>
                        </label>
                    </div>
                    <div class="suite-barrio-seo-robots-item">
                        <label>
                            <span class="suite-barrio-tog">
                                <input type="checkbox" id="suite-barrio-mb-follow" name="_suite_barrio_seo_follow" value="1" <?php checked($follow_val); ?>>
                                <span class="suite-barrio-tog-sl"></span>
                            </span>
                            Follow
                            <?php if ($custom_follow === ''): ?>
                                <span class="suite-barrio-robots-badge" id="suite-barrio-follow-badge">default</span>
                            <?php endif; ?>
                        </label>
                    </div>
                    <div class="suite-barrio-seo-robots-item">
                        <label>
                            <span class="suite-barrio-tog">
                                <input type="checkbox" id="suite-barrio-mb-sitemap" name="_suite_barrio_seo_sitemap" value="1" <?php checked($sitemap_val); ?>>
                                <span class="suite-barrio-tog-sl"></span>
                            </span>
                            Sitemap
                            <?php if ($custom_sitemap === ''): ?>
                                <span class="suite-barrio-robots-badge" id="suite-barrio-sitemap-badge">default</span>
                            <?php endif; ?>
                        </label>
                    </div>
                </div>
            </div>

        </div><!-- .suite-barrio-seo-fields -->

        <!-- ── Colonna preview ── -->
        <div class="suite-barrio-seo-preview">

            <!-- Anteprima Google -->
            <div class="suite-barrio-seo-preview-section">
                <div class="suite-barrio-seo-preview-label">Anteprima Google</div>
                <div class="suite-barrio-serp-card">
                    <div class="suite-barrio-serp-site">
                        <div class="suite-barrio-serp-favicon">
                            <?php if ($favicon_preview): ?>
                                <img src="<?php echo esc_url($favicon_preview); ?>" alt=""
                                    style="width:100%;height:100%;object-fit:cover;">
                            <?php else: ?>
                                <div class="suite-barrio-serp-favicon-dot"></div>
                            <?php endif; ?>
                        </div>
                        <div class="suite-barrio-serp-site-info">
                            <span class="suite-barrio-serp-site-name"><?php echo esc_html($site_name); ?></span>
                            <span class="suite-barrio-serp-url"><?php echo esc_html($display_url); ?></span>
                        </div>
                    </div>
                    <div class="suite-barrio-serp-title" id="suite-barrio-serp-title"><?php echo esc_html($full_title); ?></div>
                    <div class="suite-barrio-serp-desc <?php echo empty($display_desc) ? 'placeholder' : ''; ?>" id="suite-barrio-serp-desc">
                        <?php echo esc_html($display_desc ?: 'Aggiungi una descrizione per mostrare qui il testo…'); ?>
                    </div>
                </div>
            </div>

            <!-- Anteprima Social -->
            <div class="suite-barrio-seo-preview-section">
                <div class="suite-barrio-seo-preview-label">Anteprima Social</div>
                <div class="suite-barrio-social-card">
                    <div class="suite-barrio-social-card-img" id="suite-barrio-social-img-wrap">
                        <?php if ($og_thumb_url): ?>
                            <img src="<?php echo esc_url($og_thumb_url); ?>" id="suite-barrio-social-preview-img" alt="">
                        <?php else: ?>
                            <div class="suite-barrio-social-img-placeholder" id="suite-barrio-social-img-placeholder">
                                <span class="dashicons dashicons-format-image"></span>
                                <span>Nessuna immagine</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="suite-barrio-social-card-body">
                        <div class="suite-barrio-social-card-domain">
                            <?php echo esc_html(wp_parse_url($display_url, PHP_URL_HOST) ?: get_bloginfo('url')); ?>
                        </div>
                        <div class="suite-barrio-social-card-title" id="suite-barrio-social-title"><?php echo esc_html($full_title); ?></div>
                        <div class="suite-barrio-social-card-desc <?php echo empty($display_desc) ? 'placeholder' : ''; ?>"
                            id="suite-barrio-social-desc">
                            <?php echo esc_html($display_desc ?: 'Aggiungi una descrizione…'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Punteggio SEO -->
            <div class="suite-barrio-seo-preview-section">
                <div class="suite-barrio-seo-preview-label">Punteggio SEO</div>
                <div class="suite-barrio-score-wrap" id="suite-barrio-score-wrap">
                    <div class="suite-barrio-score-gauge">
                        <svg viewBox="0 0 100 54" class="suite-barrio-score-svg">
                            <path class="suite-barrio-score-track" d="M9,50 A41,41 0 0,1 91,50" fill="none" stroke="#f0f0f1"
                                stroke-width="10" stroke-linecap="round" />
                            <path class="suite-barrio-score-fill" id="suite-barrio-score-fill" d="M9,50 A41,41 0 0,1 91,50" fill="none"
                                stroke="#c3c4c7" stroke-width="10" stroke-linecap="round" stroke-dasharray="0 129" />
                        </svg>
                        <div class="suite-barrio-score-num" id="suite-barrio-score-num">0</div>
                        <div class="suite-barrio-score-label" id="suite-barrio-score-label">—</div>
                    </div>
                    <ul class="suite-barrio-score-items" id="suite-barrio-score-items"></ul>
                </div>
            </div>

        </div><!-- .suite-barrio-seo-preview -->

    </div><!-- .suite-barrio-seo-mb -->

    <?php ob_start(); ?>
        (function () {
            var defaultTitle = <?php echo wp_json_encode($default_title); ?>;
            var defaultDesc = <?php echo wp_json_encode($default_desc); ?>;
            var siteName = <?php echo wp_json_encode($site_name); ?>;
            var sep = <?php echo wp_json_encode($sep); ?>;
            var order = <?php echo wp_json_encode($order); ?>;
            var defaultOgUrl = <?php echo wp_json_encode($og_thumb_url); ?>;
            var indexDefault = <?php echo wp_json_encode($index_default); ?>;
            var followDefault = <?php echo wp_json_encode($follow_default); ?>;

            var titleInput = document.getElementById('suite-barrio-mb-title');
            var titleLen = document.getElementById('suite-barrio-mb-title-len');
            var titleCtr = document.getElementById('suite-barrio-mb-title-ctr');
            var descInput = document.getElementById('suite-barrio-mb-desc');
            var descLen = document.getElementById('suite-barrio-mb-desc-len');
            var descCtr = document.getElementById('suite-barrio-mb-desc-ctr');
            var serpTitle = document.getElementById('suite-barrio-serp-title');
            var serpDesc = document.getElementById('suite-barrio-serp-desc');
            var socialTitle = document.getElementById('suite-barrio-social-title');
            var socialDesc = document.getElementById('suite-barrio-social-desc');
            var socialImg = document.getElementById('suite-barrio-social-img-wrap');
            var ogIdField = document.getElementById('suite-barrio-mb-og-id');
            var ogThumb = document.getElementById('suite-barrio-mb-og-thumb');
            var selectBtn = document.getElementById('suite-barrio-mb-og-select');
            var removeBtn = document.getElementById('suite-barrio-mb-og-remove');
            var indexCb = document.getElementById('suite-barrio-mb-index');
            var followCb = document.getElementById('suite-barrio-mb-follow');
            var frame;

            // ── Title ──────────────────────────────────────────────────────────
            function updateTitle() {
                var val = titleInput.value || defaultTitle;
                var len = val.length;
                titleLen.textContent = len;
                titleCtr.className = 'suite-barrio-seo-counter' + (len > 60 ? ' warn' : len >= 50 ? ' ok' : '');
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
                descCtr.className = 'suite-barrio-seo-counter' + (len > 160 ? ' warn' : len >= 120 ? ' ok' : '');
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
                    socialImg.innerHTML = '<div class="suite-barrio-social-img-placeholder"><span class="dashicons dashicons-format-image"></span><span>Nessuna immagine</span></div>';
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
                        document.dispatchEvent(new Event('suite-barrio-og-changed'));
                        if (!document.getElementById('suite-barrio-mb-og-remove')) {
                            var rb = document.createElement('button');
                            rb.type = 'button'; rb.className = 'button button-small';
                            rb.id = 'suite-barrio-mb-og-remove'; rb.textContent = '\u2715';
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
                document.dispatchEvent(new Event('suite-barrio-og-changed'));
                var rb = document.getElementById('suite-barrio-mb-og-remove');
                if (rb) rb.remove();
            }
            if (removeBtn) removeBtn.addEventListener('click', doRemove);

            // ── Robots badge (rimuove "default" al primo cambio manuale) ──────
            function removeBadge(id) {
                var b = document.getElementById(id);
                if (b) b.remove();
            }
            if (indexCb) indexCb.addEventListener('change', function () { removeBadge('suite-barrio-index-badge'); });
            if (followCb) followCb.addEventListener('change', function () { removeBadge('suite-barrio-follow-badge'); });
            var sitemapCb = document.getElementById('suite-barrio-mb-sitemap');
            if (sitemapCb) sitemapCb.addEventListener('change', function () { removeBadge('suite-barrio-sitemap-badge'); });

            // ── Keywords tag-input con drag & drop ──────────────────────────
            (function () {
                var wrap = document.getElementById('suite-barrio-mb-kw-wrap');
                var input = document.getElementById('suite-barrio-mb-kw-input');
                var hidden = document.getElementById('suite-barrio-mb-kw-hidden');
                if (!wrap || !input || !hidden) return;

                var dragSrc = null;

                function getKeywords() {
                    var kws = [];
                    wrap.querySelectorAll('.suite-barrio-kw-pill').forEach(function (p) { kws.push(p.dataset.kw); });
                    return kws;
                }

                function sync() {
                    var kws = getKeywords();
                    hidden.value = JSON.stringify(kws);
                    wrap.querySelectorAll('.suite-barrio-kw-pill').forEach(function (p, i) {
                        p.className = 'suite-barrio-kw-pill ' + (i === 0 ? 'suite-barrio-kw-pill-primary' : 'suite-barrio-kw-pill-secondary');
                    });
                    input.placeholder = kws.length === 0 ? 'Aggiungi keyword\u2026' : '';
                    document.dispatchEvent(new Event('suite-barrio-kw-changed'));
                }

                function makePill(kw) {
                    var pill = document.createElement('span');
                    pill.className = 'suite-barrio-kw-pill suite-barrio-kw-pill-secondary';
                    pill.dataset.kw = kw;
                    pill.draggable = true;
                    pill.style.cursor = 'grab';
                    pill.innerHTML = esc(kw) +
                        '<button type="button" class="suite-barrio-kw-pill-remove" aria-label="Rimuovi">\u00d7</button>';
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
                        wrap.querySelectorAll('.suite-barrio-kw-pill').forEach(function (p) {
                            p.classList.remove('suite-barrio-kw-drag-over');
                        });
                    });
                    pill.addEventListener('dragover', function (e) {
                        e.preventDefault();
                        e.dataTransfer.dropEffect = 'move';
                        if (this !== dragSrc) this.classList.add('suite-barrio-kw-drag-over');
                    });
                    pill.addEventListener('dragleave', function () {
                        this.classList.remove('suite-barrio-kw-drag-over');
                    });
                    pill.addEventListener('drop', function (e) {
                        e.stopPropagation();
                        this.classList.remove('suite-barrio-kw-drag-over');
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
                wrap.querySelectorAll('.suite-barrio-kw-pill').forEach(function (p) {
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
                        var pills = wrap.querySelectorAll('.suite-barrio-kw-pill');
                        if (pills.length > 0) { pills[pills.length - 1].remove(); sync(); }
                    }
                });
                input.addEventListener('blur', function () {
                    if (this.value.trim()) { addKeyword(this.value); this.value = ''; }
                });

                wrap.addEventListener('click', function (e) {
                    if (e.target.classList.contains('suite-barrio-kw-pill-remove')) {
                        e.target.closest('.suite-barrio-kw-pill').remove(); sync();
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
                var titleInput = document.getElementById('suite-barrio-mb-title');
                var descInput = document.getElementById('suite-barrio-mb-desc');
                var ogIdField = document.getElementById('suite-barrio-mb-og-id');
                var kwHidden = document.getElementById('suite-barrio-mb-kw-hidden');
                var scoreNum = document.getElementById('suite-barrio-score-num');
                var scoreLbl = document.getElementById('suite-barrio-score-label');
                var scoreFill = document.getElementById('suite-barrio-score-fill');
                var scoreItems = document.getElementById('suite-barrio-score-items');
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
                        html += '<li class="suite-barrio-score-item ' + cls + '">' +
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
                document.addEventListener('suite-barrio-og-changed', compute);
                document.addEventListener('suite-barrio-kw-changed', compute);
                // Aggiorna quando i toggle robots cambiano (non influisce sul score ma
                // potrebbe influire in futuro — registriamo per completezza)
                compute();

                // Esponi compute globalmente per poterla chiamare dal tag-input
                window._suiteBarrioScoreCompute = compute;
            })();
            descInput.addEventListener('input', updateDesc);
            updateTitle(); updateDesc();
        })();
    <?php
    wp_add_inline_script('suite-barrio-seo-post-metabox', ob_get_clean());
}


/* ─────────────────────────────────────────
   SALVATAGGIO metabox post
   ───────────────────────────────────────── */
add_action('save_post', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;
    if (!isset($_POST['_suite_barrio_seo_nonce']))
        return;
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_suite_barrio_seo_nonce'])), 'suite_barrio_seo_post_meta_save_' . $post_id))
        return;
    if (!current_user_can('edit_post', $post_id))
        return;

    $fields = ['_suite_barrio_seo_title', '_suite_barrio_seo_description', '_suite_barrio_seo_og_image_id'];
    foreach ($fields as $field) {
        if (!isset($_POST[$field]))
            continue;
        $val = $field === '_suite_barrio_seo_og_image_id'
            ? absint($_POST[$field])
            : sanitize_textarea_field(wp_unslash($_POST[$field]));
        if ($val === '' || $val === 0)
            delete_post_meta($post_id, $field);
        else
            update_post_meta($post_id, $field, $val);
    }

    // Keywords
    if (isset($_POST['_suite_barrio_seo_keywords'])) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON array, valori sanificati singolarmente con sanitize_text_field subito sotto
        $kws = json_decode(wp_unslash($_POST['_suite_barrio_seo_keywords']), true);
        if (is_array($kws)) {
            $kws = array_values(array_filter(array_map('sanitize_text_field', $kws)));
            if (empty($kws))
                delete_post_meta($post_id, '_suite_barrio_seo_keywords');
            else
                update_post_meta($post_id, '_suite_barrio_seo_keywords', wp_json_encode($kws));
        }
    }

    // Pagina archivio → CollectionPage
    if (isset($_POST['_suite_barrio_schema_collection'])) {
        $collection_pt = sanitize_key(wp_unslash($_POST['_suite_barrio_schema_collection']));
        if ($collection_pt && post_type_exists($collection_pt))
            update_post_meta($post_id, '_suite_barrio_schema_collection', $collection_pt);
        else
            delete_post_meta($post_id, '_suite_barrio_schema_collection');
    }

    // Robots per-post: salva solo se il form ha il campo _set (sempre presente)
    // Il valore è '1' se il checkbox è spuntato, altrimenti non è nei POST
    // Salviamo '1' o '0' — stringa vuota significa "usa default post type"
    if (isset($_POST['_suite_barrio_seo_index_set'])) {
        update_post_meta($post_id, '_suite_barrio_seo_index', isset($_POST['_suite_barrio_seo_index']) ? '1' : '0');
    }
    if (isset($_POST['_suite_barrio_seo_follow_set'])) {
        update_post_meta($post_id, '_suite_barrio_seo_follow', isset($_POST['_suite_barrio_seo_follow']) ? '1' : '0');
    }
    if (isset($_POST['_suite_barrio_seo_sitemap_set'])) {
        update_post_meta($post_id, '_suite_barrio_seo_sitemap', isset($_POST['_suite_barrio_seo_sitemap']) ? '1' : '0');
    }

    if (function_exists('suite_barrio_invalidate_vercel_cache')) {
        suite_barrio_invalidate_vercel_cache($post_id);
    }
});

/* ─────────────────────────────────────────
   WPML — copia meta di indicizzazione alla duplicazione
   Quando WPML crea una traduzione, copia _suite_barrio_seo_index,
   _suite_barrio_seo_follow e _suite_barrio_seo_sitemap dall'originale.
   Il post tradotto potrà poi modificarli indipendentemente.
   ───────────────────────────────────────── */

/**
 * Copia i tre meta di indicizzazione dall'originale al tradotto.
 * Scrive sempre '0' o '1' esplicitamente, in modo che il metabox
 * non mostri "default" ma il valore reale dell'originale.
 */
function suite_barrio_seo_copy_indexing_meta(int $original_id, int $new_id): void
{
    foreach (['_suite_barrio_seo_index', '_suite_barrio_seo_follow', '_suite_barrio_seo_sitemap'] as $key) {
        $val = get_post_meta($original_id, $key, true);
        // Se l'originale non ha valore esplicito (è su "default"), leggi il default
        // del post type e scrivilo esplicitamente nella traduzione.
        if ($val === '') {
            $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);
            $pt = get_post_type($original_id);
            $cfg = $content['post_types'][$pt] ?? [];
            if ($key === '_suite_barrio_seo_sitemap') {
                $sm_settings = get_option(defined('SUITE_BARRIO_SITEMAP_OPTION') ? SUITE_BARRIO_SITEMAP_OPTION : 'suite_barrio_sitemap_settings', []);
                $val = isset($sm_settings['post_types'][$pt]) ? '1' : '0';
            } else {
                $field = str_replace('_suite_barrio_seo_', '', $key); // 'index' o 'follow'
                $val = isset($cfg[$field]) ? (string) (int) $cfg[$field] : '1';
            }
        }
        update_post_meta($new_id, $key, $val);
    }

    // La pagina tradotta resta un archivio dello stesso post type
    $collection_pt = get_post_meta($original_id, '_suite_barrio_schema_collection', true);
    if ($collection_pt)
        update_post_meta($new_id, '_suite_barrio_schema_collection', $collection_pt);
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

    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legge l'hook di WPML, non ne definisce uno nuovo
    $original = apply_filters('wpml_object_id', $new_id, get_post_type($new_id) ?: 'post', false, $args['source_language_code']);
    if (!$original || $original === $new_id)
        return;

    // Copia solo se il post tradotto non ha ancora valori espliciti (appena creato)
    $already_set = get_post_meta($new_id, '_suite_barrio_seo_index', true);
    if ($already_set !== '')
        return;

    suite_barrio_seo_copy_indexing_meta((int) $original, $new_id);
});

// Hook di copertura: wpml_after_save_post è fired dopo ogni salvataggio di traduzione
add_action('wpml_after_save_post', function ($post_id, $post_array, $job) {
    if (empty($job) || !is_object($job))
        return;

    $original_id = (int) ($job->original_doc_id ?? 0);
    if (!$original_id || $original_id === $post_id)
        return;

    // Copia solo se il tradotto non ha ancora valori propri
    if (get_post_meta($post_id, '_suite_barrio_seo_index', true) !== '')
        return;

    suite_barrio_seo_copy_indexing_meta($original_id, (int) $post_id);
}, 10, 3);

/* ─────────────────────────────────────────
   FORM SEO per le tassonomie
   ───────────────────────────────────────── */
add_action('init', function () {
    foreach (suite_barrio_meta_indexed_taxonomies() as $tax) {
        // Form "aggiungi termine"
        add_action("{$tax}_add_form_fields", 'suite_barrio_meta_render_term_add_fields');
        // Form "modifica termine"
        add_action("{$tax}_edit_form_fields", 'suite_barrio_meta_render_term_edit_fields', 10, 2);
        // Salvataggio
        add_action("created_{$tax}", 'suite_barrio_meta_save_term_fields');
        add_action("edited_{$tax}", 'suite_barrio_meta_save_term_fields');
    }
});

/* ── Aggiungi termine ── */
function suite_barrio_meta_render_term_add_fields($taxonomy)
{
    wp_nonce_field('suite_barrio_seo_term_meta_save', '_suite_barrio_seo_term_nonce');

    // Default dal config del post type nella tab Contenuti
    $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);
    $sm_settings = get_option(defined('SUITE_BARRIO_SITEMAP_OPTION') ? SUITE_BARRIO_SITEMAP_OPTION : 'suite_barrio_sitemap_settings', []);
    $tax_cfg = $content['taxonomies'][$taxonomy] ?? ['index' => 1, 'follow' => 1];

    // Sitemap: cascade SUITE_BARRIO_SITEMAP_OPTION → SUITE_BARRIO_META_CONTENT_OPTION → 1
    $sitemap_default = isset($sm_settings['taxonomies'][$taxonomy])
        ? (bool) $sm_settings['taxonomies'][$taxonomy]
        : (bool) ($content['taxonomies'][$taxonomy]['sitemap'] ?? 1);
    ?>
    <div class="form-field">
        <label for="suite_barrio_seo_title">SEO Title
            <span style="font-size:11px;color:#a7aaad;font-weight:400;margin-left:4px;">(lascia vuoto per usare il nome del
                termine)</span>
        </label>
        <input type="text" name="_suite_barrio_seo_title" id="suite_barrio_seo_title" value="" maxlength="100">
        <p class="description">Titolo personalizzato per i meta tag SEO. Max 60 caratteri consigliati.</p>
    </div>
    <div class="form-field">
        <label for="suite_barrio_seo_description">SEO Description
            <span style="font-size:11px;color:#a7aaad;font-weight:400;margin-left:4px;">(lascia vuoto per usare la
                descrizione del termine)</span>
        </label>
        <textarea name="_suite_barrio_seo_description" id="suite_barrio_seo_description" rows="3"></textarea>
        <p class="description">Descrizione personalizzata. Max 160 caratteri consigliati.</p>
    </div>
    <div class="form-field">
        <label>OG Image
            <span style="font-size:11px;color:#a7aaad;font-weight:400;margin-left:4px;">(opzionale)</span>
        </label>
        <input type="hidden" name="_suite_barrio_seo_og_image_id" id="suite_barrio_seo_og_image_id_add" value="">
        <div style="display:flex;gap:10px;align-items:center;margin-top:4px;">
            <div id="suite-barrio-add-og-thumb"
                style="width:100px;height:53px;border:1px solid #dcdcde;border-radius:3px;background:#f6f7f7;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                <span style="font-size:11px;color:#c3c4c7;">Nessuna</span>
            </div>
            <div>
                <button type="button" class="button button-small" id="suite-barrio-add-og-select">Seleziona immagine</button>
                <p class="description" style="margin-top:4px;">1200 × 630 px consigliati.</p>
            </div>
        </div>
        <?php suite_barrio_meta_term_og_script('suite-barrio-add-og-select', null, 'suite_barrio_seo_og_image_id_add', 'suite-barrio-add-og-thumb'); ?>
    </div>
    <div class="form-field">
        <label>Indicizzazione
            <span style="font-size:11px;color:#a7aaad;font-weight:400;margin-left:4px;">(default dal config
                tassonomia)</span>
        </label>
        <input type="hidden" name="_suite_barrio_seo_index_set" value="1">
        <input type="hidden" name="_suite_barrio_seo_follow_set" value="1">
        <input type="hidden" name="_suite_barrio_seo_sitemap_set" value="1">
        <div style="display:flex;gap:16px;margin-top:4px;">
            <?php foreach ([
                '_suite_barrio_seo_index' => ['label' => 'Index', 'default' => $tax_cfg['index'] ?? 1],
                '_suite_barrio_seo_follow' => ['label' => 'Follow', 'default' => $tax_cfg['follow'] ?? 1],
                '_suite_barrio_seo_sitemap' => ['label' => 'Sitemap', 'default' => $sitemap_default],
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
        <input type="hidden" name="_suite_barrio_seo_keywords" id="suite_barrio_seo_kw_hidden_add" value="[]">
        <div class="suite-barrio-kw-wrap" id="suite-barrio-kw-wrap-add">
            <input type="text" class="suite-barrio-kw-input" id="suite-barrio-kw-input-add" placeholder="Aggiungi keyword…">
        </div>
        <p class="description">Premi <kbd>Enter</kbd> o <kbd>,</kbd> per aggiungere. La prima è la keyword primaria.</p>
        <?php suite_barrio_meta_term_kw_script('suite-barrio-kw-wrap-add', 'suite-barrio-kw-input-add', 'suite_barrio_seo_kw_hidden_add', []); ?>
    </div>
    <?php
}
function suite_barrio_meta_render_term_edit_fields($term, $taxonomy)
{
    wp_nonce_field('suite_barrio_seo_term_meta_save', '_suite_barrio_seo_term_nonce');

    $custom_title = get_term_meta($term->term_id, '_suite_barrio_seo_title', true);
    $custom_desc = get_term_meta($term->term_id, '_suite_barrio_seo_description', true);
    $custom_og_id = (int) get_term_meta($term->term_id, '_suite_barrio_seo_og_image_id', true);
    $custom_og_url = $custom_og_id ? wp_get_attachment_image_url($custom_og_id, 'thumbnail') : '';

    $default_title = $term->name;
    $default_desc = wp_strip_all_tags($term->description);

    $s = get_option(SUITE_BARRIO_META_OPTION, []);
    $sep = $s['separator'] ?? '—';
    $site_name = $s['site_title'] ?? get_bloginfo('name');
    $order = $s['title_order'] ?? 'page_site';
    ?>
    <tr class="form-field">
        <th scope="row">
            <label for="suite_barrio_seo_title_edit">SEO Title</label>
        </th>
        <td>
            <input type="text" name="_suite_barrio_seo_title" id="suite_barrio_seo_title_edit" value="<?php echo esc_attr($custom_title); ?>"
                placeholder="<?php echo esc_attr($default_title); ?>" class="regular-text" maxlength="100">
            <p class="description">
                Lascia vuoto per usare il nome del termine (<em><?php echo esc_html($default_title); ?></em>).
                Max 60 caratteri consigliati.
                <span id="suite-barrio-term-title-counter" style="color:#a7aaad;margin-left:6px;">
                    <span id="suite-barrio-term-title-len"><?php echo esc_html(mb_strlen($custom_title ?: $default_title)); ?></span>/60
                </span>
            </p>
            <div id="suite-barrio-term-title-preview"
                style="font-size:12px;color:#646970;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;padding:5px 10px;margin-top:6px;max-width:500px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <?php
                $display = $custom_title ?: $default_title;
                $pt = '<span style="color:#1d4ed8;font-weight:600;">' . esc_html($display) . '</span>';
                $s_ = ' <span style="color:#c3c4c7;">' . esc_html($sep) . '</span> ';
                $site = esc_html($site_name);
                echo wp_kses_post($order === 'site_page' ? $site . $s_ . $pt : $pt . $s_ . $site);
                ?>
            </div>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row">
            <label for="suite_barrio_seo_desc_edit">SEO Description</label>
        </th>
        <td>
            <textarea name="_suite_barrio_seo_description" id="suite_barrio_seo_desc_edit" rows="3" class="regular-text"
                placeholder="<?php echo esc_attr($default_desc ?: 'Descrizione del termine…'); ?>"><?php echo esc_textarea($custom_desc); ?></textarea>
            <p class="description">
                Lascia vuoto per usare la descrizione del termine.
                Max 160 caratteri consigliati.
                <span id="suite-barrio-term-desc-counter" style="color:#a7aaad;margin-left:6px;">
                    <span id="suite-barrio-term-desc-len"><?php echo esc_html(mb_strlen($custom_desc ?: $default_desc)); ?></span>/160
                </span>
            </p>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row"><label>OG Image</label></th>
        <td>
            <input type="hidden" name="_suite_barrio_seo_og_image_id" id="suite_barrio_seo_og_image_id_edit"
                value="<?php echo esc_attr($custom_og_id ?: ''); ?>">
            <div style="display:flex;gap:12px;align-items:flex-start;">
                <div id="suite-barrio-edit-og-thumb"
                    style="width:120px;height:63px;border:1px solid #dcdcde;border-radius:3px;background:#f6f7f7;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;">
                    <?php if ($custom_og_url): ?>
                        <img src="<?php echo esc_url($custom_og_url); ?>" style="width:100%;height:100%;object-fit:cover;"
                            alt="">
                    <?php else: ?>
                        <span style="font-size:11px;color:#c3c4c7;">Nessuna</span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;flex-direction:column;gap:5px;">
                    <button type="button" class="button" id="suite-barrio-edit-og-select">
                        <?php echo $custom_og_id ? 'Cambia immagine' : 'Seleziona immagine'; ?>
                    </button>
                    <?php if ($custom_og_id): ?>
                        <button type="button" class="button" id="suite-barrio-edit-og-remove">Rimuovi</button>
                    <?php endif; ?>
                    <p class="description">1200 × 630 px consigliati.</p>
                </div>
            </div>
            <?php suite_barrio_meta_term_og_script('suite-barrio-edit-og-select', 'suite-barrio-edit-og-remove', 'suite_barrio_seo_og_image_id_edit', 'suite-barrio-edit-og-thumb'); ?>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row"><label>Indicizzazione</label></th>
        <td>
            <?php
            $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);
            $sm_settings = get_option(defined('SUITE_BARRIO_SITEMAP_OPTION') ? SUITE_BARRIO_SITEMAP_OPTION : 'suite_barrio_sitemap_settings', []);
            $tax_cfg = $content['taxonomies'][$taxonomy] ?? ['index' => 1, 'follow' => 1];

            // Sitemap: cascade SUITE_BARRIO_SITEMAP_OPTION → SUITE_BARRIO_META_CONTENT_OPTION → 1
            $sitemap_default = isset($sm_settings['taxonomies'][$taxonomy])
                ? (bool) $sm_settings['taxonomies'][$taxonomy]
                : (bool) ($content['taxonomies'][$taxonomy]['sitemap'] ?? 1);

            // Legge override per-termine ('1'/'0') o usa default tassonomia ('')
            $custom_index = get_term_meta($term->term_id, '_suite_barrio_seo_index', true);
            $custom_follow = get_term_meta($term->term_id, '_suite_barrio_seo_follow', true);
            $custom_sitemap = get_term_meta($term->term_id, '_suite_barrio_seo_sitemap', true);

            $index_val = $custom_index !== '' ? (bool) (int) $custom_index : (bool) ($tax_cfg['index'] ?? 1);
            $follow_val = $custom_follow !== '' ? (bool) (int) $custom_follow : (bool) ($tax_cfg['follow'] ?? 1);
            $sitemap_val = $custom_sitemap !== '' ? (bool) (int) $custom_sitemap : $sitemap_default;
            ?>
            <input type="hidden" name="_suite_barrio_seo_index_set" value="1">
            <input type="hidden" name="_suite_barrio_seo_follow_set" value="1">
            <input type="hidden" name="_suite_barrio_seo_sitemap_set" value="1">
            <div style="display:flex;gap:16px;">
                <?php foreach ([
                    '_suite_barrio_seo_index' => ['label' => 'Index', 'val' => $index_val, 'custom' => $custom_index],
                    '_suite_barrio_seo_follow' => ['label' => 'Follow', 'val' => $follow_val, 'custom' => $custom_follow],
                    '_suite_barrio_seo_sitemap' => ['label' => 'Sitemap', 'val' => $sitemap_val, 'custom' => $custom_sitemap],
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
            $raw_kw_term = get_term_meta($term->term_id, '_suite_barrio_seo_keywords', true);
            $kws_term = $raw_kw_term ? (array) json_decode($raw_kw_term, true) : [];
            ?>
            <input type="hidden" name="_suite_barrio_seo_keywords" id="suite_barrio_seo_kw_hidden_edit"
                value="<?php echo esc_attr($raw_kw_term ?: '[]'); ?>">
            <div class="suite-barrio-kw-wrap" id="suite-barrio-kw-wrap-edit">
                <?php foreach ($kws_term as $i => $kw): ?>
                    <span class="suite-barrio-kw-pill <?php echo $i === 0 ? 'suite-barrio-kw-pill-primary' : 'suite-barrio-kw-pill-secondary'; ?>"
                        data-kw="<?php echo esc_attr($kw); ?>">
                        <?php echo esc_html($kw); ?>
                        <button type="button" class="suite-barrio-kw-pill-remove" aria-label="Rimuovi">×</button>
                    </span>
                <?php endforeach; ?>
                <input type="text" class="suite-barrio-kw-input" id="suite-barrio-kw-input-edit"
                    placeholder="<?php echo empty($kws_term) ? 'Aggiungi keyword…' : ''; ?>">
            </div>
            <p class="description">Premi <kbd>Enter</kbd> o <kbd>,</kbd> per aggiungere. La prima è la keyword primaria.</p>
            <?php suite_barrio_meta_term_kw_script('suite-barrio-kw-wrap-edit', 'suite-barrio-kw-input-edit', 'suite_barrio_seo_kw_hidden_edit', $kws_term); ?>
        </td>
    </tr>
    <?php ob_start(); ?>
        (function () {
            var defaultTitle = <?php echo wp_json_encode($default_title); ?>;
            var siteName = <?php echo wp_json_encode($site_name); ?>;
            var sep = <?php echo wp_json_encode($sep); ?>;
            var order = <?php echo wp_json_encode($order); ?>;

            var ti = document.getElementById('suite_barrio_seo_title_edit');
            var tl = document.getElementById('suite-barrio-term-title-len');
            var tc = document.getElementById('suite-barrio-term-title-counter');
            var tp = document.getElementById('suite-barrio-term-title-preview');
            var di = document.getElementById('suite_barrio_seo_desc_edit');
            var dl = document.getElementById('suite-barrio-term-desc-len');
            var dc = document.getElementById('suite-barrio-term-desc-counter');

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
                    var ogInput = document.getElementById('suite_barrio_seo_og_image_id_edit');
                    var hasOg = (ogInput && ogInput.value) || hasOgDefault;
                    var kwHidden = document.getElementById('suite_barrio_seo_kw_hidden_edit');
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

                    var wrap = document.getElementById('suite-barrio-term-score-wrap');
                    var snum = document.getElementById('suite-barrio-term-score-num');
                    var slbl = document.getElementById('suite-barrio-term-score-label');
                    var sfill = document.getElementById('suite-barrio-term-score-fill');
                    var sitems = document.getElementById('suite-barrio-term-score-items');
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
                        html += '<li class="suite-barrio-score-item ' + cls + '"><span>' + c.label + '</span><span style="margin-left:auto;font-size:10px;opacity:.6;">' + icon + '</span></li>';
                    });
                    sitems.innerHTML = html;
                }

                [ti, di].forEach(function (el) { if (el) el.addEventListener('input', compute); });
                document.addEventListener('suite-barrio-kw-changed', compute);
                document.addEventListener('suite-barrio-og-changed', compute);
                compute();
            })();
        })();
    <?php
    wp_add_inline_script('suite-barrio-seo-term-fields', ob_get_clean());
    ?>

    <!-- Punteggio SEO -->
    <tr class="form-field">
        <th scope="row">Punteggio SEO</th>
        <td>
            <div id="suite-barrio-term-score-wrap" style="max-width:300px;">
                <div style="display:flex;align-items:center;gap:16px;">
                    <div style="position:relative;width:90px;flex-shrink:0;">
                        <svg viewBox="0 0 100 54" style="width:100%;display:block;">
                            <path d="M9,50 A41,41 0 0,1 91,50" fill="none" stroke="#f0f0f1" stroke-width="10"
                                stroke-linecap="round" />
                            <path id="suite-barrio-term-score-fill" d="M9,50 A41,41 0 0,1 91,50" fill="none" stroke="#c3c4c7"
                                stroke-width="10" stroke-linecap="round" stroke-dasharray="0 129" />
                        </svg>
                        <div id="suite-barrio-term-score-num"
                            style="position:absolute;bottom:4px;left:50%;transform:translateX(-50%);font-size:18px;font-weight:700;color:#1d2327;line-height:1;">
                            0</div>
                    </div>
                    <div>
                        <div id="suite-barrio-term-score-label"
                            style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#646970;margin-bottom:6px;">
                            —</div>
                        <ul id="suite-barrio-term-score-items" class="suite-barrio-score-items"></ul>
                    </div>
                </div>
            </div>
        </td>
    </tr>
    <?php
}

/* ── Script media uploader OG per i term form ── */
function suite_barrio_meta_term_og_script($select_id, $remove_id, $input_id, $thumb_id)
{
    ob_start();
    ?>
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
                    if (!document.getElementById(<?php echo wp_json_encode($remove_id ?: 'suite-barrio-no-remove'); ?>)) {
                        var rb = document.createElement('button');
                        rb.type = 'button'; rb.className = 'suite-barrio-gs-media-btn'; rb.id = <?php echo wp_json_encode($remove_id ?: 'suite-barrio-og-rm-' . $select_id); ?>;
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
                var rb = removeBtn || document.getElementById(<?php echo wp_json_encode($remove_id ?: 'suite-barrio-no-remove'); ?>);
                if (rb) rb.remove();
            }
            if (removeBtn) removeBtn.addEventListener('click', doRemove);
        })();
    <?php
    wp_add_inline_script('suite-barrio-seo-term-fields', ob_get_clean());
}

/* ─────────────────────────────────────────
   HELPER: script keywords tag-input per i term form
   ───────────────────────────────────────── */
function suite_barrio_meta_term_kw_script($wrap_id, $input_id, $hidden_id, array $initial_kws)
{
    ob_start();
    ?>
        (function () {
            var wrap = document.getElementById(<?php echo wp_json_encode($wrap_id); ?>);
            var input = document.getElementById(<?php echo wp_json_encode($input_id); ?>);
            var hidden = document.getElementById(<?php echo wp_json_encode($hidden_id); ?>);
            if (!wrap || !input || !hidden) return;
            var dragSrc = null;
            function getKeywords() {
                var kws = [];
                wrap.querySelectorAll('.suite-barrio-kw-pill').forEach(function (p) { kws.push(p.dataset.kw); });
                return kws;
            }
            function sync() {
                var kws = getKeywords();
                hidden.value = JSON.stringify(kws);
                wrap.querySelectorAll('.suite-barrio-kw-pill').forEach(function (p, i) {
                    p.className = 'suite-barrio-kw-pill ' + (i === 0 ? 'suite-barrio-kw-pill-primary' : 'suite-barrio-kw-pill-secondary');
                });
                input.placeholder = kws.length === 0 ? 'Aggiungi keyword\u2026' : '';
                document.dispatchEvent(new Event('suite-barrio-kw-changed'));
            }
            function bindDrag(pill) {
                pill.draggable = true;
                pill.addEventListener('dragstart', function (e) {
                    dragSrc = this; e.dataTransfer.effectAllowed = 'move'; this.style.opacity = '.4';
                });
                pill.addEventListener('dragend', function () {
                    this.style.opacity = '';
                    wrap.querySelectorAll('.suite-barrio-kw-pill').forEach(function (p) { p.classList.remove('suite-barrio-kw-drag-over'); });
                });
                pill.addEventListener('dragover', function (e) {
                    e.preventDefault(); if (this !== dragSrc) this.classList.add('suite-barrio-kw-drag-over');
                });
                pill.addEventListener('dragleave', function () { this.classList.remove('suite-barrio-kw-drag-over'); });
                pill.addEventListener('drop', function (e) {
                    e.stopPropagation(); this.classList.remove('suite-barrio-kw-drag-over');
                    if (dragSrc === this) return;
                    wrap.insertBefore(dragSrc, this); sync();
                });
            }
            function makePill(kw) {
                var pill = document.createElement('span');
                pill.className = 'suite-barrio-kw-pill suite-barrio-kw-pill-secondary';
                pill.dataset.kw = kw;
                pill.innerHTML = esc(kw) + '<button type="button" class="suite-barrio-kw-pill-remove" aria-label="Rimuovi">\u00d7</button>';
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
            wrap.querySelectorAll('.suite-barrio-kw-pill').forEach(bindDrag);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault(); addKeyword(this.value); this.value = '';
                } else if (e.key === 'Backspace' && this.value === '') {
                    var pills = wrap.querySelectorAll('.suite-barrio-kw-pill');
                    if (pills.length > 0) { pills[pills.length - 1].remove(); sync(); }
                }
            });
            input.addEventListener('blur', function () {
                if (this.value.trim()) { addKeyword(this.value); this.value = ''; }
            });
            wrap.addEventListener('click', function (e) {
                if (e.target.classList.contains('suite-barrio-kw-pill-remove')) {
                    e.target.closest('.suite-barrio-kw-pill').remove(); sync();
                } else if (e.target === wrap || e.target === input) { input.focus(); }
            });
            function esc(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
            sync();
        })();
    <?php
    wp_add_inline_script('suite-barrio-seo-term-fields', ob_get_clean());
}

/* ─────────────────────────────────────────
   SALVATAGGIO term meta
   ───────────────────────────────────────── */
function suite_barrio_meta_save_term_fields($term_id)
{
    if (!isset($_POST['_suite_barrio_seo_term_nonce']))
        return;
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_suite_barrio_seo_term_nonce'])), 'suite_barrio_seo_term_meta_save'))
        return;
    if (!current_user_can('manage_categories'))
        return;

    $fields = ['_suite_barrio_seo_title', '_suite_barrio_seo_description', '_suite_barrio_seo_og_image_id'];
    foreach ($fields as $field) {
        if (!isset($_POST[$field]))
            continue;
        $val = $field === '_suite_barrio_seo_og_image_id'
            ? absint($_POST[$field])
            : sanitize_textarea_field(wp_unslash($_POST[$field]));
        if ($val === '' || $val === 0)
            delete_term_meta($term_id, $field);
        else
            update_term_meta($term_id, $field, $val);
    }

    // Index / Follow / Sitemap per-termine
    foreach (['_suite_barrio_seo_index', '_suite_barrio_seo_follow', '_suite_barrio_seo_sitemap'] as $field) {
        $set_key = $field . '_set';
        if (!isset($_POST[$set_key]))
            continue;
        update_term_meta($term_id, $field, isset($_POST[$field]) ? '1' : '0');
    }

    // Keywords
    if (isset($_POST['_suite_barrio_seo_keywords'])) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON array, valori sanificati singolarmente con sanitize_text_field subito sotto
        $kws = json_decode(wp_unslash($_POST['_suite_barrio_seo_keywords']), true);
        if (is_array($kws)) {
            $kws = array_values(array_filter(array_map('sanitize_text_field', $kws)));
            if (empty($kws))
                delete_term_meta($term_id, '_suite_barrio_seo_keywords');
            else
                update_term_meta($term_id, '_suite_barrio_seo_keywords', wp_json_encode($kws));
        }
    }
}

/* ─────────────────────────────────────────
   WPML — copia meta di indicizzazione alla duplicazione dei termini
   ───────────────────────────────────────── */

function suite_barrio_seo_copy_indexing_term_meta(int $original_term_id, int $new_term_id, string $taxonomy = ''): void
{
    $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);
    $tax = $taxonomy ?: get_term($original_term_id)->taxonomy ?? '';
    $cfg = $content['taxonomies'][$tax] ?? [];
    $sm_settings = get_option(defined('SUITE_BARRIO_SITEMAP_OPTION') ? SUITE_BARRIO_SITEMAP_OPTION : 'suite_barrio_sitemap_settings', []);

    foreach (['_suite_barrio_seo_index', '_suite_barrio_seo_follow', '_suite_barrio_seo_sitemap'] as $key) {
        $val = get_term_meta($original_term_id, $key, true);
        if ($val === '') {
            if ($key === '_suite_barrio_seo_sitemap') {
                $val = isset($sm_settings['taxonomies'][$tax]) ? '1' : '0';
            } else {
                $field = str_replace('_suite_barrio_seo_', '', $key);
                $val = isset($cfg[$field]) ? (string) (int) $cfg[$field] : '1';
            }
        }
        update_term_meta($new_term_id, $key, $val);
    }
}

// wpml_copy_term_metadata è il filtro corretto per la sincronizzazione dei termini
foreach (['_suite_barrio_seo_index', '_suite_barrio_seo_follow', '_suite_barrio_seo_sitemap'] as $suite_barrio_dup_term_key) {
    add_filter('wpml_copy_term_metadata', function ($value, $meta_key, $original_term_id) use ($suite_barrio_dup_term_key) {
        if ($meta_key !== $suite_barrio_dup_term_key)
            return $value;
        $original_val = get_term_meta((int) $original_term_id, $meta_key, true);
        // Se l'originale non ha valore esplicito, risolvi il default della tassonomia
        if ($original_val === '') {
            $term = get_term((int) $original_term_id);
            $tax = $term->taxonomy ?? '';
            $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);
            $cfg = $content['taxonomies'][$tax] ?? [];
            $sm_settings = get_option(defined('SUITE_BARRIO_SITEMAP_OPTION') ? SUITE_BARRIO_SITEMAP_OPTION : 'suite_barrio_sitemap_settings', []);
            if ($meta_key === '_suite_barrio_seo_sitemap') {
                $original_val = isset($sm_settings['taxonomies'][$tax]) ? '1' : '0';
            } else {
                $field = str_replace('_suite_barrio_seo_', '', $meta_key);
                $original_val = isset($cfg[$field]) ? (string) (int) $cfg[$field] : '1';
            }
        }
        return $original_val;
    }, 10, 3);
}

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function suite_barrio_meta_admin_page()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione (tab attivo), nessun nonce esistente per questa azione
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'content';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione (notice "salvato"), nessun nonce esistente per questa azione
    $saved = isset($_GET['saved']);
    $s = get_option(SUITE_BARRIO_META_OPTION, []);
    $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);

    // Post types e tassonomie disponibili
    $post_types = get_post_types(['public' => true], 'objects');
    $taxonomies = array_filter(get_taxonomies(['public' => true], 'objects'), fn($t) => !in_array($t->name, ['post_format', 'nav_menu', 'link_category'], true));
    unset($post_types['attachment']);
    ?>
    <div id="suite-barrio-meta-wrap" class="wrap suite-barrio-wrap">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
            <div class="suite-barrio-gs-header">
                <div class="suite-barrio-gs-header-icon"><span class="dashicons dashicons-search"></span></div>
                <div>
                    <h1>Suite Barrio <span class="suite-barrio-gs-breadcrumb">/ SEO / SEO Globale</span></h1>
                    <p class="suite-barrio-gs-sub">Meta title, description, robots e Open Graph per ogni contenuto</p>
                </div>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-seo')); ?>"
                style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;font-size:12.5px;font-weight:600;background:#f3f4f6;color:#374151 !important;border:1px solid #e5e7eb;border-radius:8px;text-decoration:none !important;white-space:nowrap;flex-shrink:0;align-self:flex-start;">
                <span class="dashicons dashicons-arrow-left-alt2"
                    style="font-size:14px;width:14px;height:14px;margin-top:1px;"></span>
                Strumenti SEO
            </a>
        </div>
        <hr class="suite-barrio-gs-divider">

        <div class="suite-barrio-gs-tabs">
            <a href="?page=suite-barrio-global-seo&tab=content"
                class="suite-barrio-gs-tab <?php echo $tab === 'content' ? 'active' : ''; ?>">Contenuti</a>
            <a href="?page=suite-barrio-global-seo&tab=settings"
                class="suite-barrio-gs-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>">Impostazioni</a>
        </div>

        <?php if ($saved): ?>
            <div class="suite-barrio-gs-notice-success"><span class="dashicons dashicons-yes-alt"></span>
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
                <?php wp_nonce_field('suite_barrio_meta_content_save'); ?>
                <input type="hidden" name="action" value="suite_barrio_meta_content_save">

                <!-- Post Types -->
                <div class="suite-barrio-panel">
                    <div class="suite-barrio-panel-header">
                        <span class="dashicons dashicons-admin-post"></span>
                        <span class="suite-barrio-panel-header-title">Post Type</span>
                        <span class="suite-barrio-panel-header-desc">
                            Configura indicizzazione e follow per ogni tipo di contenuto
                        </span>
                    </div>
                    <table class="suite-barrio-content-table">
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
                                        <span class="suite-barrio-pt-label">
                                            <?php echo esc_html($plural); ?>
                                            <?php if ($builtin): ?>
                                                <span class="suite-barrio-pt-badge builtin">core</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="suite-barrio-pt-slug"><?php echo esc_html($slug); ?></span>
                                    </td>
                                    <td class="center">
                                        <label class="suite-barrio-toggle">
                                            <input type="checkbox" name="pt[<?php echo esc_attr($slug); ?>][index]" value="1" <?php checked($idx); ?> class="suite-barrio-robots-cb"
                                                data-row="pt-<?php echo esc_attr($slug); ?>">
                                            <span class="suite-barrio-toggle-track"></span>
                                        </label>
                                    </td>
                                    <td class="center">
                                        <label class="suite-barrio-toggle">
                                            <input type="checkbox" name="pt[<?php echo esc_attr($slug); ?>][follow]" value="1" <?php checked($follow); ?> class="suite-barrio-robots-cb"
                                                data-row="pt-<?php echo esc_attr($slug); ?>">
                                            <span class="suite-barrio-toggle-track"></span>
                                        </label>
                                    </td>
                                    <td>
                                        <code class="suite-barrio-robots-preview" id="robots-pt-<?php echo esc_attr($slug); ?>">
                                                                            <?php echo esc_html($robots); ?>
                                                                        </code>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Tassonomie -->
                <div class="suite-barrio-panel">
                    <div class="suite-barrio-panel-header">
                        <span class="dashicons dashicons-tag"></span>
                        <span class="suite-barrio-panel-header-title">Tassonomie</span>
                        <span class="suite-barrio-panel-header-desc">
                            Configura indicizzazione e follow per ogni tassonomia
                        </span>
                    </div>
                    <?php if (empty($taxonomies)): ?>
                        <p style="padding:20px;color:#646970;font-size:13px;">Nessuna tassonomia pubblica trovata.</p>
                    <?php else: ?>
                        <table class="suite-barrio-content-table">
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
                                            <span class="suite-barrio-pt-label">
                                                <?php echo esc_html($label); ?>
                                                <?php if ($builtin): ?>
                                                    <span class="suite-barrio-pt-badge builtin">core</span>
                                                <?php endif; ?>
                                            </span>
                                            <span class="suite-barrio-pt-slug"><?php echo esc_html($slug); ?></span>
                                        </td>
                                        <td class="center">
                                            <label class="suite-barrio-toggle">
                                                <input type="checkbox" name="tax[<?php echo esc_attr($slug); ?>][index]" value="1" <?php checked($idx); ?> class="suite-barrio-robots-cb"
                                                    data-row="tax-<?php echo esc_attr($slug); ?>">
                                                <span class="suite-barrio-toggle-track"></span>
                                            </label>
                                        </td>
                                        <td class="center">
                                            <label class="suite-barrio-toggle">
                                                <input type="checkbox" name="tax[<?php echo esc_attr($slug); ?>][follow]" value="1"
                                                    <?php checked($follow); ?> class="suite-barrio-robots-cb"
                                                    data-row="tax-<?php echo esc_attr($slug); ?>">
                                                <span class="suite-barrio-toggle-track"></span>
                                            </label>
                                        </td>
                                        <td>
                                            <code class="suite-barrio-robots-preview" id="robots-tax-<?php echo esc_attr($slug); ?>">
                                                                                        <?php echo esc_html($robots); ?>
                                                                                    </code>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <button type="submit" class="suite-barrio-gs-save-btn"><span class="dashicons dashicons-yes"
                        style="font-size:16px;width:16px;height:16px;"></span> Salva impostazioni contenuti</button>
            </form>

            <?php
            $suite_barrio_seo_global_js = '(function () {
    document.querySelectorAll(\'.suite-barrio-robots-cb\').forEach(function (cb) {
        cb.addEventListener(\'change\', function () {
            var row = this.dataset.row;
            var cells = document.querySelectorAll(\'[data-row="\' + row + \'"]\');
            var idx = false, follow = false;
            cells.forEach(function (c) {
                var name = c.name || \'\';
                if (name.indexOf(\'[index]\') !== -1) idx = c.checked;
                if (name.indexOf(\'[follow]\') !== -1) follow = c.checked;
            });
            var preview = document.getElementById(\'robots-\' + row);
            if (preview) {
                preview.textContent = (idx ? \'index\' : \'noindex\') + \', \' + (follow ? \'follow\' : \'nofollow\');
            }
        });
    });
})();';
            wp_add_inline_script('suite-barrio-seo-global-settings', $suite_barrio_seo_global_js);

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
                <?php wp_nonce_field('suite_barrio_meta_settings_save'); ?>
                <input type="hidden" name="action" value="suite_barrio_meta_settings_save">
                <input type="hidden" name="suite_barrio_meta_og_image_id" id="suite-barrio-og-image-id-field"
                    value="<?php echo esc_attr($og_image_id); ?>">
                <input type="hidden" name="suite_barrio_meta_favicon_id" id="suite-barrio-favicon-id-field"
                    value="<?php echo esc_attr($favicon_id); ?>">

                <!-- ── Identità del sito ──────────────────────────────────── -->
                <div class="suite-barrio-panel">
                    <div class="suite-barrio-panel-header">
                        <span class="dashicons dashicons-admin-home"></span>
                        <span class="suite-barrio-panel-header-title">Identità del sito</span>
                    </div>
                    <div class="suite-barrio-settings-section">

                        <!-- Titolo del sito -->
                        <div class="suite-barrio-field-row">
                            <div class="suite-barrio-field-label">
                                Titolo del sito
                                <span class="suite-barrio-field-desc">Usato come suffisso/prefisso nel &lt;title&gt;</span>
                            </div>
                            <div class="suite-barrio-field-input">
                                <input type="text" name="suite_barrio_meta_site_title" id="suite-barrio-site-title"
                                    value="<?php echo esc_attr($site_title); ?>" class="regular-text"
                                    placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                            </div>
                        </div>

                        <!-- Separatore -->
                        <div class="suite-barrio-field-row">
                            <div class="suite-barrio-field-label">
                                Separatore
                                <span class="suite-barrio-field-desc">Carattere tra titolo pagina e nome sito</span>
                            </div>
                            <div class="suite-barrio-field-input">
                                <input type="text" name="suite_barrio_meta_separator" id="suite-barrio-separator"
                                    value="<?php echo esc_attr($separator); ?>" style="width:60px;" maxlength="5">
                                <div class="suite-barrio-title-preview">
                                    <span id="suite-barrio-prev-page">Titolo pagina</span>
                                    <span class="sep" id="suite-barrio-prev-sep"><?php echo esc_html($separator); ?></span>
                                    <span id="suite-barrio-prev-site"><?php echo esc_html($site_title ?: get_bloginfo('name')); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Ordine titolo -->
                        <div class="suite-barrio-field-row">
                            <div class="suite-barrio-field-label">
                                Ordine titolo
                                <span class="suite-barrio-field-desc">Come viene costruito il &lt;title&gt;</span>
                            </div>
                            <div class="suite-barrio-field-input">
                                <div class="suite-barrio-order-options">
                                    <label class="suite-barrio-order-option">
                                        <input type="radio" name="suite_barrio_meta_title_order" value="page_site" <?php checked($title_order, 'page_site'); ?>>
                                        <div>
                                            <div>Pagina prima</div>
                                            <div class="suite-barrio-order-preview">Titolo pagina — Nome sito</div>
                                        </div>
                                    </label>
                                    <label class="suite-barrio-order-option">
                                        <input type="radio" name="suite_barrio_meta_title_order" value="site_page" <?php checked($title_order, 'site_page'); ?>>
                                        <div>
                                            <div>Sito prima</div>
                                            <div class="suite-barrio-order-preview">Nome sito — Titolo pagina</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Favicon -->
                        <div class="suite-barrio-field-row">
                            <div class="suite-barrio-field-label">
                                Favicon
                                <span class="suite-barrio-field-desc">Icona del sito (browser, bookmark, SERP)</span>
                            </div>
                            <div class="suite-barrio-field-input">
                                <div class="suite-barrio-og-image-wrap">
                                    <div class="suite-barrio-og-image-preview" id="suite-barrio-favicon-preview"
                                        style="width:48px;height:48px;border-radius:6px;">
                                        <?php if ($favicon_url): ?>
                                            <img src="<?php echo esc_url($favicon_url); ?>" id="suite-barrio-favicon-preview-img" alt="">
                                        <?php else: ?>
                                            <div class="suite-barrio-og-placeholder" id="suite-barrio-favicon-placeholder">
                                                <span class="dashicons dashicons-admin-site"
                                                    style="font-size:22px;width:22px;height:22px;color:#dcdcde;display:block;margin:0 auto;"></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="suite-barrio-og-image-actions">
                                        <button type="button" class="suite-barrio-gs-media-btn" id="suite-barrio-favicon-select">
                                            <?php echo $favicon_url ? 'Cambia favicon' : 'Seleziona favicon'; ?>
                                        </button>
                                        <?php if ($favicon_url): ?>
                                            <button type="button" class="suite-barrio-gs-media-btn" id="suite-barrio-favicon-remove">Rimuovi</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="suite-barrio-field-hint">Consigliato: 512×512 px, formato PNG o SVG.</div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ── Default meta ───────────────────────────────────────── -->
                <div class="suite-barrio-panel">
                    <div class="suite-barrio-panel-header">
                        <span class="dashicons dashicons-editor-code"></span>
                        <span class="suite-barrio-panel-header-title">Meta tag di default</span>
                    </div>
                    <div class="suite-barrio-settings-section">

                        <!-- Descrizione di default -->
                        <div class="suite-barrio-field-row">
                            <div class="suite-barrio-field-label">
                                Descrizione di default
                                <span class="suite-barrio-field-desc">Usata quando la pagina non ha excerpt</span>
                            </div>
                            <div class="suite-barrio-field-input">
                                <textarea name="suite_barrio_meta_default_desc"
                                    rows="3"><?php echo esc_textarea($default_desc); ?></textarea>
                                <div class="suite-barrio-field-hint">Idealmente tra 120 e 160 caratteri.</div>
                            </div>
                        </div>

                        <!-- Immagine OG di default -->
                        <div class="suite-barrio-field-row">
                            <div class="suite-barrio-field-label">
                                Immagine di condivisione
                                <span class="suite-barrio-field-desc">og:image e twitter:image di default</span>
                            </div>
                            <div class="suite-barrio-field-input">
                                <div class="suite-barrio-og-image-wrap">
                                    <div class="suite-barrio-og-image-preview" id="suite-barrio-og-preview">
                                        <?php if ($og_image_url): ?>
                                            <img src="<?php echo esc_url($og_image_url); ?>" id="suite-barrio-og-preview-img" alt="">
                                        <?php else: ?>
                                            <div class="suite-barrio-og-placeholder" id="suite-barrio-og-placeholder">
                                                <span class="dashicons dashicons-format-image"
                                                    style="font-size:22px;width:22px;height:22px;color:#dcdcde;margin-bottom:4px;display:block;"></span>
                                                Nessuna immagine
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="suite-barrio-og-image-actions">
                                        <button type="button" class="suite-barrio-gs-media-btn" id="suite-barrio-og-select">
                                            <?php echo $og_image_url ? 'Cambia immagine' : 'Seleziona immagine'; ?>
                                        </button>
                                        <?php if ($og_image_url): ?>
                                            <button type="button" class="suite-barrio-gs-media-btn" id="suite-barrio-og-remove">Rimuovi</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="suite-barrio-field-hint">Dimensione consigliata: 1200 × 630 px.</div>
                            </div>
                        </div>

                    </div>
                </div>

                <button type="submit" class="suite-barrio-gs-save-btn"><span class="dashicons dashicons-yes"
                        style="font-size:16px;width:16px;height:16px;"></span> Salva impostazioni</button>
            </form>

            <?php ob_start(); ?>
                (function () {
                    // ── Preview titolo live ──────────────────────────────────────
                    var titleInput = document.getElementById('suite-barrio-site-title');
                    var sepInput = document.getElementById('suite-barrio-separator');
                    var prevSep = document.getElementById('suite-barrio-prev-sep');
                    var prevSite = document.getElementById('suite-barrio-prev-site');

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
                    var selectBtn = document.getElementById('suite-barrio-og-select');
                    var removeBtn = document.getElementById('suite-barrio-og-remove');
                    var idField = document.getElementById('suite-barrio-og-image-id-field');
                    var previewWrap = document.getElementById('suite-barrio-og-preview');
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
                                    rb.className = 'suite-barrio-gs-media-btn';
                                    rb.id = 'suite-barrio-og-remove';
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
                        previewWrap.innerHTML = '<div class="suite-barrio-og-placeholder"><span class="dashicons dashicons-format-image" style="font-size:22px;width:22px;height:22px;color:#dcdcde;margin-bottom:4px;display:block;"></span>Nessuna immagine</div>';
                        selectBtn.textContent = 'Seleziona immagine';
                        var rb = document.getElementById('suite-barrio-og-remove');
                        if (rb) rb.remove();
                    }

                    if (removeBtn) { removeBtn.addEventListener('click', doRemove); }
                })();

                // ── Favicon uploader ─────────────────────────────────────────────
                (function () {
                    var selectBtn = document.getElementById('suite-barrio-favicon-select');
                    var removeBtn = document.getElementById('suite-barrio-favicon-remove');
                    var idField = document.getElementById('suite-barrio-favicon-id-field');
                    var previewWrap = document.getElementById('suite-barrio-favicon-preview');
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
                            if (!document.getElementById('suite-barrio-favicon-remove')) {
                                var rb = document.createElement('button');
                                rb.type = 'button'; rb.className = 'suite-barrio-gs-media-btn'; rb.id = 'suite-barrio-favicon-remove';
                                rb.textContent = 'Rimuovi';
                                selectBtn.after(rb);
                                rb.addEventListener('click', doRemoveFavicon);
                            }
                        });
                        frame.open();
                    });

                    function doRemoveFavicon() {
                        idField.value = 0;
                        previewWrap.innerHTML = '<div class="suite-barrio-og-placeholder"><span class="dashicons dashicons-admin-site" style="font-size:22px;width:22px;height:22px;color:#dcdcde;display:block;margin:0 auto;"></span></div>';
                        selectBtn.textContent = 'Seleziona favicon';
                        var rb = document.getElementById('suite-barrio-favicon-remove');
                        if (rb) rb.remove();
                    }

                    if (removeBtn) { removeBtn.addEventListener('click', doRemoveFavicon); }
                })();
            <?php
            wp_add_inline_script('suite-barrio-seo-global-settings', ob_get_clean());
            ?>

        <?php endif; // tab ?>

    </div><!-- #suite-barrio-meta-wrap -->
    <?php
}