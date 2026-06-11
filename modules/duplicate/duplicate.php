<?php
/**
 * WP Barrio — Duplicate
 * Duplicazione di post e termini di tassonomia direttamente dalla list table.
 *
 * Architettura:
 *  - Pagina "Duplicate" (manage_options): configura quali PT e tassonomie
 *    supportano la duplicazione.
 *  - Link "Copia" nelle row actions della list table per ogni PT abilitato.
 *  - Link "Copia" nelle row actions della pagina termini per ogni tassonomia abilitata.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('WPB_DUPLICATE_VERSION', '1.0.0');
define('WPB_DUPLICATE_OPTION', 'wpb_duplicate_settings');

/* ─────────────────────────────────────────
   HELPERS
   ───────────────────────────────────────── */
function wpb_duplicate_settings(): array
{
    return get_option(WPB_DUPLICATE_OPTION, []);
}

function wpb_duplicate_enabled_post_types(): array
{
    $s = wpb_duplicate_settings();
    return $s['enabled_post_types'] ?? [];
}

function wpb_duplicate_enabled_taxonomies(): array
{
    $s = wpb_duplicate_settings();
    return $s['enabled_taxonomies'] ?? [];
}

function wpb_duplicate_get_post_types(): array
{
    $post_types = get_post_types(['public' => true], 'objects');
    unset($post_types['attachment']);
    $result = [];
    foreach ($post_types as $pt) {
        $result[] = [
            'slug' => $pt->name,
            'label' => $pt->labels->name ?? $pt->name,
        ];
    }
    return $result;
}

function wpb_duplicate_get_taxonomies(): array
{
    $taxonomies = get_taxonomies(['public' => true], 'objects');
    $result = [];
    foreach ($taxonomies as $tax) {
        $result[] = [
            'slug' => $tax->name,
            'label' => $tax->labels->name ?? $tax->name,
        ];
    }
    return $result;
}

/* ─────────────────────────────────────────
   MENU ADMIN — pagina configurazione
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    add_submenu_page(
        'wp-barrio',
        'Duplicate — WP Barrio',
        'Duplicate',
        'manage_options',
        'wp-barrio-duplicate',
        'wpb_duplicate_admin_page'
    );
}, 13);

/* ─────────────────────────────────────────
   ENQUEUE — solo nelle pagine duplicate
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'wp-barrio-duplicate') === false)
        return;

    wp_add_inline_style('wp-admin', wpb_duplicate_config_css());
    wp_add_inline_script('jquery', wpb_duplicate_config_js());
});

/* ─────────────────────────────────────────
   AJAX: toggle abilitazione post type
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_duplicate_toggle_pt', function () {
    check_ajax_referer('wpb_duplicate_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permessi insufficienti.');

    $pt = sanitize_key($_POST['post_type'] ?? '');
    $enabled = !empty($_POST['enabled']);
    if (!$pt)
        wp_send_json_error('Post type mancante.');

    $s = wpb_duplicate_settings();
    $list = $s['enabled_post_types'] ?? [];
    $list = $enabled
        ? array_unique(array_merge($list, [$pt]))
        : array_values(array_diff($list, [$pt]));

    $s['enabled_post_types'] = $list;
    update_option(WPB_DUPLICATE_OPTION, $s);
    wp_send_json_success($list);
});

/* ─────────────────────────────────────────
   AJAX: toggle abilitazione tassonomia
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_duplicate_toggle_tax', function () {
    check_ajax_referer('wpb_duplicate_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permessi insufficienti.');

    $tax = sanitize_key($_POST['taxonomy'] ?? '');
    $enabled = !empty($_POST['enabled']);
    if (!$tax)
        wp_send_json_error('Tassonomia mancante.');

    $s = wpb_duplicate_settings();
    $list = $s['enabled_taxonomies'] ?? [];
    $list = $enabled
        ? array_unique(array_merge($list, [$tax]))
        : array_values(array_diff($list, [$tax]));

    $s['enabled_taxonomies'] = $list;
    update_option(WPB_DUPLICATE_OPTION, $s);
    wp_send_json_success($list);
});

/* ─────────────────────────────────────────
   DUPLICA POST — azione GET sicura con nonce
   ───────────────────────────────────────── */
add_action('admin_action_wpb_duplicate_post', function () {
    $post_id = absint($_GET['post'] ?? 0);
    if (!$post_id)
        wp_die('ID post mancante.');

    check_admin_referer('wpb_duplicate_post_' . $post_id);

    if (!current_user_can('edit_posts'))
        wp_die('Permessi insufficienti.');

    $original = get_post($post_id);
    if (!$original)
        wp_die('Post non trovato.');

    // Verifica che il post type sia abilitato
    if (!in_array($original->post_type, wpb_duplicate_enabled_post_types(), true))
        wp_die('Duplicazione non abilitata per questo post type.');

    // Crea il duplicato come bozza
    $new_id = wp_insert_post([
        'post_title' => $original->post_title . ' — Copia',
        'post_content' => $original->post_content,
        'post_excerpt' => $original->post_excerpt,
        'post_status' => 'draft',
        'post_type' => $original->post_type,
        'post_author' => get_current_user_id(),
        'post_parent' => $original->post_parent,
        'menu_order' => $original->menu_order,
        'comment_status' => $original->comment_status,
        'ping_status' => $original->ping_status,
    ]);

    if (is_wp_error($new_id))
        wp_die($new_id->get_error_message());

    // Copia post meta
    // Meta interni WP da escludere sempre
    $skip_meta = ['_edit_lock', '_edit_last', '_wp_old_slug'];

    if (function_exists('get_fields')) {
        // ── ACF attivo: usa le API native per gestire correttamente
        //    repeater, flexible content, relazioni e file ──────────
        $acf_fields = get_fields($post_id);
        if (!empty($acf_fields)) {
            foreach ($acf_fields as $field_key => $field_value) {
                update_field($field_key, $field_value, $new_id);
            }
        }

        // Copia i meta NON-ACF (quelli ACF hanno già il prefisso _ gestito da update_field)
        $acf_keys = [];
        if (!empty($acf_fields)) {
            foreach (array_keys($acf_fields) as $k) {
                $acf_keys[] = $k;
                $acf_keys[] = '_' . $k; // chiave di riferimento campo ACF
            }
        }

        $meta_list = get_post_meta($post_id);
        foreach ($meta_list as $meta_key => $meta_values) {
            if (in_array($meta_key, $skip_meta, true))
                continue;
            if (in_array($meta_key, $acf_keys, true))
                continue;
            foreach ($meta_values as $meta_value) {
                add_post_meta($new_id, $meta_key, maybe_unserialize($meta_value));
            }
        }
    } else {
        // ── ACF non attivo: copia raw di tutti i post meta ────────
        $meta_list = get_post_meta($post_id);
        foreach ($meta_list as $meta_key => $meta_values) {
            if (in_array($meta_key, $skip_meta, true))
                continue;
            foreach ($meta_values as $meta_value) {
                add_post_meta($new_id, $meta_key, maybe_unserialize($meta_value));
            }
        }
    }

    // Copia tassonomie e termini associati
    $taxonomies = get_object_taxonomies($original->post_type);
    foreach ($taxonomies as $taxonomy) {
        $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (!is_wp_error($terms) && !empty($terms)) {
            wp_set_object_terms($new_id, $terms, $taxonomy);
        }
    }

    // Copia immagine in evidenza
    $thumbnail_id = get_post_thumbnail_id($post_id);
    if ($thumbnail_id) {
        set_post_thumbnail($new_id, $thumbnail_id);
    }

    // Redirect alla modifica del nuovo post
    $pt_obj = get_post_type_object($original->post_type);
    $list_url = ($original->post_type === 'post')
        ? admin_url('edit.php')
        : admin_url('edit.php?post_type=' . $original->post_type);

    wp_redirect(add_query_arg('wpb_duplicated', '1', $list_url));
    exit;
});

/* ─────────────────────────────────────────
   DUPLICA TERMINE — azione GET sicura con nonce
   ───────────────────────────────────────── */
add_action('admin_action_wpb_duplicate_term', function () {
    $term_id = absint($_GET['term_id'] ?? 0);
    $taxonomy = sanitize_key($_GET['taxonomy'] ?? '');
    if (!$term_id || !$taxonomy)
        wp_die('Parametri mancanti.');

    check_admin_referer('wpb_duplicate_term_' . $term_id);

    if (!current_user_can('manage_categories'))
        wp_die('Permessi insufficienti.');

    if (!in_array($taxonomy, wpb_duplicate_enabled_taxonomies(), true))
        wp_die('Duplicazione non abilitata per questa tassonomia.');

    $original = get_term($term_id, $taxonomy);
    if (!$original || is_wp_error($original))
        wp_die('Termine non trovato.');

    // Crea il duplicato
    $result = wp_insert_term(
        $original->name . ' — Copia',
        $taxonomy,
        [
            'description' => $original->description,
            'parent' => $original->parent,
            'slug' => $original->slug . '-copia',
        ]
    );

    if (is_wp_error($result))
        wp_die($result->get_error_message());

    $new_term_id = $result['term_id'];

    // Copia term meta
    if (function_exists('get_fields')) {
        // ── ACF attivo: usa le API native ────────────────────────
        $acf_fields = get_fields('term_' . $term_id);
        if (!empty($acf_fields)) {
            foreach ($acf_fields as $field_key => $field_value) {
                update_field($field_key, $field_value, 'term_' . $new_term_id);
            }
        }

        // Copia i meta NON-ACF
        $acf_keys = [];
        if (!empty($acf_fields)) {
            foreach (array_keys($acf_fields) as $k) {
                $acf_keys[] = $k;
                $acf_keys[] = '_' . $k;
            }
        }

        $meta_list = get_term_meta($term_id);
        foreach ($meta_list as $meta_key => $meta_values) {
            if (in_array($meta_key, $acf_keys, true))
                continue;
            // Salta l'ordine personalizzato WPB Orders: il termine è nuovo
            if ($meta_key === 'wpb_term_order')
                continue;
            foreach ($meta_values as $meta_value) {
                add_term_meta($new_term_id, $meta_key, maybe_unserialize($meta_value));
            }
        }
    } else {
        // ── ACF non attivo: copia raw ─────────────────────────────
        $meta_list = get_term_meta($term_id);
        foreach ($meta_list as $meta_key => $meta_values) {
            if ($meta_key === 'wpb_term_order')
                continue;
            foreach ($meta_values as $meta_value) {
                add_term_meta($new_term_id, $meta_key, maybe_unserialize($meta_value));
            }
        }
    }

    // Redirect alla lista termini con conferma
    $redirect = admin_url('edit-tags.php?taxonomy=' . $taxonomy . '&wpb_duplicated=1');
    wp_redirect($redirect);
    exit;
});

/* ─────────────────────────────────────────
   NOTICE "Duplicato con successo"
   ───────────────────────────────────────── */
add_action('admin_notices', function () {
    if (!isset($_GET['wpb_duplicated']))
        return;
    echo '<div class="notice notice-success is-dismissible"><p>✓ Elemento duplicato con successo come bozza.</p></div>';
});

/* ─────────────────────────────────────────
   HELPER: inserisce "Copia" dopo "Modifica rapida"
   nella lista delle row actions.

   Struttura chiavi WordPress nelle row actions:
     'edit'                → Modifica
     'inline hide-if-no-js'→ Modifica rapida  ← vogliamo inserire QUI
     'trash'               → Cestino
     'view'                → Visualizza

   Per i PT gerarchici (page, custom hierarchical) la chiave
   "Modifica rapida" non esiste: in quel caso inseriamo dopo 'edit'.
   ───────────────────────────────────────── */
function wpb_duplicate_inject_link(array $actions, string $url, string $label): array
{
    $link = '<a href="' . esc_url($url) . '" aria-label="' . esc_attr($label) . '">Copia</a>';

    $new_actions = [];
    $inserted = false;

    foreach ($actions as $key => $action) {
        $new_actions[$key] = $action;

        // Inserisce subito dopo "Modifica rapida" (priorità massima)
        if ($key === 'inline hide-if-no-js' && !$inserted) {
            $new_actions['wpb_duplicate'] = $link;
            $inserted = true;
        }
    }

    // Fallback: dopo 'edit' se "Modifica rapida" non era presente
    if (!$inserted) {
        $with_edit = [];
        foreach ($new_actions as $key => $action) {
            $with_edit[$key] = $action;
            if ($key === 'edit') {
                $with_edit['wpb_duplicate'] = $link;
                $inserted = true;
            }
        }
        $new_actions = $with_edit;
    }

    // Fallback finale: in testa (meglio visibile di coda)
    if (!$inserted) {
        $new_actions = array_merge(['wpb_duplicate' => $link], $new_actions);
    }

    return $new_actions;
}

/* ─────────────────────────────────────────
   ROW ACTIONS — post type
   Agganciato su tutti i filtri necessari:
     - post_row_actions       → post standard e PT non-gerarchici
     - page_row_actions       → pages e PT gerarchici
     - {post_type}_row_actions → PT custom (inclusi ACF CPT)
   I filtri vengono registrati a 'init' così i PT custom
   sono già stati registrati da ACF o da altri plugin.
   ───────────────────────────────────────── */
function wpb_duplicate_post_row_action(array $actions, \WP_Post $post): array
{
    if (!in_array($post->post_type, wpb_duplicate_enabled_post_types(), true))
        return $actions;

    if (!current_user_can('edit_posts'))
        return $actions;

    $url = wp_nonce_url(
        admin_url('admin.php?action=wpb_duplicate_post&post=' . $post->ID),
        'wpb_duplicate_post_' . $post->ID
    );

    return wpb_duplicate_inject_link($actions, $url, 'Copia "' . $post->post_title . '"');
}

// Hook nativi WP (coprono post, page e PT non registrati esplicitamente)
add_filter('post_row_actions', 'wpb_duplicate_post_row_action', 10, 2);
add_filter('page_row_actions', 'wpb_duplicate_post_row_action', 10, 2);

// Hook dinamici per ogni PT abilitato — registrati dopo init così ACF ha
// già caricato i suoi CPT. Evita doppioni su 'post' e 'page'.
add_action('init', function () {
    foreach (wpb_duplicate_enabled_post_types() as $pt) {
        if (in_array($pt, ['post', 'page'], true))
            continue;
        add_filter($pt . '_row_actions', 'wpb_duplicate_post_row_action', 10, 2);
    }
}, 99);

/* ─────────────────────────────────────────
   ROW ACTIONS — termini di tassonomia
   ───────────────────────────────────────── */
add_filter('tag_row_actions', function (array $actions, \WP_Term $term): array {
    if (!in_array($term->taxonomy, wpb_duplicate_enabled_taxonomies(), true))
        return $actions;

    if (!current_user_can('manage_categories'))
        return $actions;

    $url = wp_nonce_url(
        admin_url('admin.php?action=wpb_duplicate_term&term_id=' . $term->term_id . '&taxonomy=' . $term->taxonomy),
        'wpb_duplicate_term_' . $term->term_id
    );

    return wpb_duplicate_inject_link($actions, $url, 'Copia "' . $term->name . '"');
}, 10, 2);

/* ─────────────────────────────────────────
   CSS — pagina configurazione
   ───────────────────────────────────────── */
/* ─────────────────────────────────────────
   CSS — pagina configurazione
   ───────────────────────────────────────── */
function wpb_duplicate_config_css(): string
{
    return '
    /* ── WP Barrio Duplicate v7 ── */
    #wpb-duplicate-wrap {
        max-width: 760px;
        font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", sans-serif;
    }

    /* Header */
    .wpb-dup-header { display:flex; align-items:center; gap:14px; margin-bottom:8px; }
    .wpb-dup-header-icon {
        width:40px; height:40px; background:#111827; border-radius:10px;
        display:flex; align-items:center; justify-content:center; flex-shrink:0;
    }
    .wpb-dup-header-icon .dashicons { font-size:20px; width:20px; height:20px; color:#fff; }
    .wpb-dup-header h1 {
        font-size:22px !important; font-weight:700 !important;
        color:#111827 !important; margin:0 !important; padding:0 !important; line-height:1.2 !important;
    }
    .wpb-dup-header-breadcrumb { font-size:14px; font-weight:400; color:#9ca3af; margin-left:4px; }
    .wpb-dup-header-sub { font-size:13px; color:#6b7280; margin:2px 0 0; }
    .wpb-dup-divider { border:none; border-top:1px solid #f3f4f6; margin:14px 0 24px; }

    /* Tabs */
    .wpb-dup-tabs { display:flex; gap:2px; border-bottom:2px solid #e5e7eb; margin-bottom:24px; }
    .wpb-dup-tab {
        display:inline-flex; align-items:center; gap:6px;
        padding:9px 18px; font-size:13px; font-weight:600; color:#6b7280;
        cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px;
        border-radius:6px 6px 0 0; transition:color .15s; user-select:none;
    }
    .wpb-dup-tab:hover { color:#111827; background:#f9fafb; }
    .wpb-dup-tab.active { color:#111827; border-bottom-color:#111827; }
    .wpb-dup-tab .dashicons { font-size:15px; width:15px; height:15px; }
    .wpb-dup-tab-badge {
        display:inline-flex; align-items:center; justify-content:center;
        background:#111827; color:#fff;
        border-radius:20px; padding:1px 8px;
        font-size:10px; font-weight:700; min-width:18px;
    }
    .wpb-dup-panel { display:none; }
    .wpb-dup-panel.active { display:block; }

    /* Info notice */
    .wpb-dup-notice {
        display:flex; align-items:flex-start; gap:12px;
        background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px;
        padding:13px 16px; margin-bottom:16px;
        font-size:13px; color:#1e40af; line-height:1.5;
    }
    .wpb-dup-notice .dashicons { font-size:17px; width:17px; height:17px; flex-shrink:0; margin-top:1px; color:#3b82f6; }

    /* List card */
    .wpb-dup-card {
        background:#fff; border:1px solid #e5e7eb; border-radius:12px;
        overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.05);
    }
    .wpb-dup-row {
        display:flex; align-items:center; gap:16px;
        padding:13px 20px; border-bottom:1px solid #f3f4f6;
        transition:background .12s;
    }
    .wpb-dup-row:last-child { border-bottom:none; }
    .wpb-dup-row:hover { background:#f9fafb; }
    .wpb-dup-row-info { flex:1; min-width:0; }
    .wpb-dup-row-label { font-size:13.5px; font-weight:600; color:#111827; }
    .wpb-dup-row-slug  { font-size:11px; color:#9ca3af; margin-top:2px; font-family:ui-monospace,Consolas,monospace; }
    .wpb-dup-row-hint  { font-size:12px; color:#059669; margin-top:4px; font-weight:500; }

    /* Toggle switch — identico a tools.php */
    .wpb-toggle { position:relative; width:44px; height:24px; flex-shrink:0; }
    .wpb-toggle input { opacity:0; width:0; height:0; position:absolute; }
    .wpb-toggle-track {
        position:absolute; inset:0; background:#d1d5db;
        border-radius:24px; cursor:pointer; transition:background .2s;
    }
    .wpb-toggle-track::before {
        content:""; position:absolute; width:18px; height:18px;
        left:3px; top:3px; background:#fff; border-radius:50%; transition:transform .2s;
        box-shadow:0 1px 3px rgba(0,0,0,.2);
    }
    .wpb-toggle input:checked + .wpb-toggle-track { background:#111827; }
    .wpb-toggle input:checked + .wpb-toggle-track::before { transform:translateX(20px); }
    .wpb-toggle-saving .wpb-toggle-track { opacity:.5; pointer-events:none; }
    ';
}

/* ─────────────────────────────────────────
   JS — pagina configurazione
   ───────────────────────────────────────── */
function wpb_duplicate_config_js(): string
{
    $nonce = wp_create_nonce('wpb_duplicate_nonce');
    $ajax_url = esc_url(admin_url('admin-ajax.php'));

    return <<<JS
jQuery(function($){
    var ajaxUrl = '{$ajax_url}';
    var nonce   = '{$nonce}';

    // Tab switching
    $(document).on('click', '.wpb-dup-tab', function(){
        var tab = $(this).data('tab');
        $('.wpb-dup-tab').removeClass('active');
        $(this).addClass('active');
        $('.wpb-dup-panel').removeClass('active');
        $('#wpb-dup-panel-' + tab).addClass('active');
    });

    // Toggle post type
    $(document).on('change', '.wpb-dup-pt-toggle', function(){
        var input   = $(this);
        var wrap    = input.closest('.wpb-toggle');
        var row     = input.closest('.wpb-dup-row');
        var pt      = input.data('pt');
        var enabled = input.is(':checked') ? 1 : 0;

        wrap.addClass('wpb-toggle-saving');
        $.post(ajaxUrl, { action:'wpb_duplicate_toggle_pt', nonce:nonce, post_type:pt, enabled:enabled },
        function(res){
            wrap.removeClass('wpb-toggle-saving');
            if (!res.success) {
                input.prop('checked', !enabled);
                alert('Errore nel salvataggio.');
            } else {
                if (enabled) row.find('.wpb-dup-row-hint').fadeIn(150);
                else         row.find('.wpb-dup-row-hint').fadeOut(150);
            }
        });
    });

    // Toggle tassonomia
    $(document).on('change', '.wpb-dup-tax-toggle', function(){
        var input   = $(this);
        var wrap    = input.closest('.wpb-toggle');
        var row     = input.closest('.wpb-dup-row');
        var tax     = input.data('tax');
        var enabled = input.is(':checked') ? 1 : 0;

        wrap.addClass('wpb-toggle-saving');
        $.post(ajaxUrl, { action:'wpb_duplicate_toggle_tax', nonce:nonce, taxonomy:tax, enabled:enabled },
        function(res){
            wrap.removeClass('wpb-toggle-saving');
            if (!res.success) {
                input.prop('checked', !enabled);
                alert('Errore nel salvataggio.');
            } else {
                if (enabled) row.find('.wpb-dup-row-hint').fadeIn(150);
                else         row.find('.wpb-dup-row-hint').fadeOut(150);
            }
        });
    });
});
JS;
}

/* ─────────────────────────────────────────
   PAGINA ADMIN — Configurazione
   ───────────────────────────────────────── */
function wpb_duplicate_admin_page()
{
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');

    $post_types = wpb_duplicate_get_post_types();
    $taxonomies = wpb_duplicate_get_taxonomies();
    $enabled_pts = wpb_duplicate_enabled_post_types();
    $enabled_taxs = wpb_duplicate_enabled_taxonomies();
    ?>
    <div id="wpb-duplicate-wrap" class="wrap">

        <!-- Header -->
        <div class="wpb-dup-header">
            <div class="wpb-dup-header-icon"><span class="dashicons dashicons-admin-page"></span></div>
            <div>
                <h1>WP Barrio <span class="wpb-dup-header-breadcrumb">/ Duplicate</span></h1>
                <p class="wpb-dup-header-sub">Attiva la duplicazione rapida per post type e tassonomie</p>
            </div>
        </div>
        <hr class="wpb-dup-divider">

        <!-- Tabs -->
        <div class="wpb-dup-tabs">
            <div class="wpb-dup-tab active" data-tab="post-types">
                <span class="dashicons dashicons-admin-post"></span>
                Post Type
                <?php if (!empty($enabled_pts)): ?>
                    <span class="wpb-dup-tab-badge"><?php echo count($enabled_pts); ?></span>
                <?php endif; ?>
            </div>
            <div class="wpb-dup-tab" data-tab="taxonomies">
                <span class="dashicons dashicons-tag"></span>
                Tassonomie
                <?php if (!empty($enabled_taxs)): ?>
                    <span class="wpb-dup-tab-badge"><?php echo count($enabled_taxs); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Panel: Post Type -->
        <div id="wpb-dup-panel-post-types" class="wpb-dup-panel active">
            <div class="wpb-dup-notice">
                <span class="dashicons dashicons-info"></span>
                <div>Attiva la duplicazione per un post type: apparirà il link <strong>Copia</strong> nella riga di ogni
                    post, accanto a «Modifica rapida». Il duplicato viene creato come <strong>bozza</strong> con titolo
                    «<em>Titolo originale — Copia</em>», inclusi meta, tassonomie e immagine in evidenza.</div>
            </div>
            <div class="wpb-dup-card">
                <?php foreach ($post_types as $pt):
                    $is_enabled = in_array($pt['slug'], $enabled_pts, true);
                    ?>
                    <div class="wpb-dup-row">
                        <div class="wpb-dup-row-info">
                            <div class="wpb-dup-row-label"><?php echo esc_html($pt['label']); ?></div>
                            <div class="wpb-dup-row-slug"><?php echo esc_html($pt['slug']); ?></div>
                            <div class="wpb-dup-row-hint" <?php echo $is_enabled ? '' : 'style="display:none;"'; ?>>
                                &#x2713; «Copia» visibile nella lista <?php echo esc_html($pt['label']); ?>
                            </div>
                        </div>
                        <label class="wpb-toggle">
                            <input type="checkbox" class="wpb-dup-pt-toggle" data-pt="<?php echo esc_attr($pt['slug']); ?>"
                                <?php checked($is_enabled); ?>>
                            <span class="wpb-toggle-track"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Panel: Tassonomie -->
        <div id="wpb-dup-panel-taxonomies" class="wpb-dup-panel">
            <div class="wpb-dup-notice">
                <span class="dashicons dashicons-info"></span>
                <div>Attiva la duplicazione per una tassonomia: apparirà il link <strong>Copia</strong> nella riga di ogni
                    termine. Il duplicato viene creato con nome «<em>Nome originale — Copia</em>», inclusi i term meta.
                </div>
            </div>
            <div class="wpb-dup-card">
                <?php foreach ($taxonomies as $tax):
                    if ($tax['slug'] === 'post_format')
                        continue;
                    $is_enabled = in_array($tax['slug'], $enabled_taxs, true);
                    ?>
                    <div class="wpb-dup-row">
                        <div class="wpb-dup-row-info">
                            <div class="wpb-dup-row-label"><?php echo esc_html($tax['label']); ?></div>
                            <div class="wpb-dup-row-slug"><?php echo esc_html($tax['slug']); ?></div>
                            <div class="wpb-dup-row-hint" <?php echo $is_enabled ? '' : 'style="display:none;"'; ?>>
                                &#x2713; «Copia» visibile nella lista <?php echo esc_html($tax['label']); ?>
                            </div>
                        </div>
                        <label class="wpb-toggle">
                            <input type="checkbox" class="wpb-dup-tax-toggle" data-tax="<?php echo esc_attr($tax['slug']); ?>"
                                <?php checked($is_enabled); ?>>
                            <span class="wpb-toggle-track"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
    <?php
}