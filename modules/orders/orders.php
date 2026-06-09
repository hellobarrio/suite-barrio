<?php
/**
 * WP Barrio — Orders
 * Riordinamento drag & drop di post type e termini di tassonomia.
 *
 * Architettura:
 *  - Pagina "Orders" (manage_options): configura quali PT e tassonomie
 *    sono riordinabili + riordino diretto delle tassonomie.
 *  - Sottovoce "Riordina" sotto ogni post type abilitato: drag & drop post.
 *  - Voce "Riordina Categorie" per utenti con edit_posts: riordino tassonomie.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('WPB_ORDERS_VERSION', '1.0.0');
define('WPB_ORDERS_OPTION',  'wpb_orders_settings');

/* ─────────────────────────────────────────
   HELPERS: lettura impostazioni
   ───────────────────────────────────────── */
function wpb_orders_settings(): array
{
    return get_option(WPB_ORDERS_OPTION, []);
}

function wpb_orders_enabled_post_types(): array
{
    $s = wpb_orders_settings();
    return $s['enabled_post_types'] ?? [];
}

function wpb_orders_enabled_taxonomies(): array
{
    $s = wpb_orders_settings();
    return $s['enabled_taxonomies'] ?? [];
}

function wpb_orders_get_post_types(): array
{
    $post_types = get_post_types(['public' => true], 'objects');
    unset($post_types['attachment']);
    $result = [];
    foreach ($post_types as $pt) {
        $result[] = [
            'slug'  => $pt->name,
            'label' => $pt->labels->name ?? $pt->name,
            'menu_icon' => $pt->menu_icon ?? 'dashicons-admin-post',
        ];
    }
    return $result;
}

function wpb_orders_get_taxonomies(): array
{
    $taxonomies = get_taxonomies(['public' => true], 'objects');
    $result = [];
    foreach ($taxonomies as $tax) {
        $result[] = [
            'slug'  => $tax->name,
            'label' => $tax->labels->name ?? $tax->name,
        ];
    }
    return $result;
}

/* ─────────────────────────────────────────
   MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {

    // 1. Pagina Orders nel menu WP Barrio (solo admin)
    add_submenu_page(
        'wp-barrio',
        'Orders — WP Barrio',
        'Orders',
        'manage_options',
        'wp-barrio-orders',
        'wpb_orders_admin_page'
    );

    // 2. "Riordina" sotto ogni post type abilitato
    $enabled_pts = wpb_orders_enabled_post_types();
    foreach ($enabled_pts as $pt_slug) {
        $pt_obj = get_post_type_object($pt_slug);
        if (!$pt_obj) continue;

        // Il menu padre del post type (es. "edit.php?post_type=prodotti")
        $parent_slug = ($pt_slug === 'post')
            ? 'edit.php'
            : 'edit.php?post_type=' . $pt_slug;

        add_submenu_page(
            $parent_slug,
            'Riordina — ' . $pt_obj->labels->name,
            'Riordina',
            'edit_posts',
            'wpb-reorder-' . $pt_slug,
            function () use ($pt_slug) {
                wpb_orders_reorder_posts_page($pt_slug);
            }
        );
    }

    // 3. "Riordina Categorie" — solo per utenti NON admin
    $enabled_taxs = wpb_orders_enabled_taxonomies();
    if (!empty($enabled_taxs) && !current_user_can('manage_options')) {
        add_menu_page(
            'Riordina Categorie — WP Barrio',
            'Riordina Categorie',
            'edit_posts',
            'wpb-reorder-terms',
            'wpb_orders_reorder_terms_page',
            'dashicons-sort',
            21
        );
    }

}, 13);

/* ─────────────────────────────────────────
   ENQUEUE SCRIPTS
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    $is_orders_page    = strpos($hook, 'wp-barrio-orders') !== false;
    $is_reorder_posts  = strpos($hook, 'wpb-reorder-') !== false;
    $is_reorder_terms  = strpos($hook, 'wpb-reorder-terms') !== false;

    if (!$is_orders_page && !$is_reorder_posts && !$is_reorder_terms)
        return;

    wp_enqueue_script('jquery-ui-sortable');
    wp_add_inline_style('wp-admin', wpb_orders_inline_css());

    if ($is_reorder_posts || $is_reorder_terms) {
        wp_add_inline_script('jquery-ui-sortable', wpb_orders_sortable_js(), 'after');
    }
    if ($is_orders_page) {
        wp_add_inline_script('jquery-ui-sortable', wpb_orders_config_js(), 'after');
    }
});

/* ─────────────────────────────────────────
   AJAX: toggle abilitazione post type
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_orders_toggle_pt', function () {
    check_ajax_referer('wpb_orders_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permessi insufficienti.');

    $pt      = sanitize_key($_POST['post_type'] ?? '');
    $enabled = (bool) ($_POST['enabled'] ?? false);

    if (!$pt) wp_send_json_error('Post type mancante.');

    $s = wpb_orders_settings();
    $list = $s['enabled_post_types'] ?? [];

    if ($enabled) {
        $list = array_unique(array_merge($list, [$pt]));
    } else {
        $list = array_values(array_diff($list, [$pt]));
        // Azzera anche i menu_order esistenti
        global $wpdb;
        $wpdb->update($wpdb->posts, ['menu_order' => 0], ['post_type' => $pt], ['%d'], ['%s']);
    }

    $s['enabled_post_types'] = $list;
    update_option(WPB_ORDERS_OPTION, $s);
    wp_send_json_success($list);
});

/* ─────────────────────────────────────────
   AJAX: toggle abilitazione tassonomia
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_orders_toggle_tax', function () {
    check_ajax_referer('wpb_orders_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permessi insufficienti.');

    $tax     = sanitize_key($_POST['taxonomy'] ?? '');
    $enabled = (bool) ($_POST['enabled'] ?? false);

    if (!$tax) wp_send_json_error('Tassonomia mancante.');

    $s = wpb_orders_settings();
    $list = $s['enabled_taxonomies'] ?? [];

    if ($enabled) {
        $list = array_unique(array_merge($list, [$tax]));
    } else {
        $list = array_values(array_diff($list, [$tax]));
        // Rimuovi termmeta esistente
        $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $t) delete_term_meta($t->term_id, 'wpb_term_order');
        }
    }

    $s['enabled_taxonomies'] = $list;
    update_option(WPB_ORDERS_OPTION, $s);
    wp_send_json_success($list);
});

/* ─────────────────────────────────────────
   AJAX: salva ordine post
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_orders_save_posts', function () {
    check_ajax_referer('wpb_orders_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('Permessi insufficienti.');

    $items     = isset($_POST['items']) ? (array) $_POST['items'] : [];
    $post_type = sanitize_key($_POST['post_type'] ?? '');

    if (!in_array($post_type, wpb_orders_enabled_post_types(), true))
        wp_send_json_error('Post type non abilitato.');

    foreach ($items as $order => $post_id) {
        $post_id = absint($post_id);
        if (!$post_id) continue;
        wp_update_post(['ID' => $post_id, 'menu_order' => (int) $order]);
    }
    wp_send_json_success('Ordine salvato.');
});

/* ─────────────────────────────────────────
   AJAX: salva ordine termini
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_orders_save_terms', function () {
    check_ajax_referer('wpb_orders_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('Permessi insufficienti.');

    $items    = isset($_POST['items']) ? (array) $_POST['items'] : [];
    $taxonomy = sanitize_key($_POST['taxonomy'] ?? '');

    if (!in_array($taxonomy, wpb_orders_enabled_taxonomies(), true))
        wp_send_json_error('Tassonomia non abilitata.');

    foreach ($items as $order => $term_id) {
        $term_id = absint($term_id);
        if (!$term_id) continue;
        update_term_meta($term_id, 'wpb_term_order', (int) $order);
    }
    wp_send_json_success('Ordine salvato.');
});

/* ─────────────────────────────────────────
   AJAX: carica post di un post type
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_orders_get_posts', function () {
    check_ajax_referer('wpb_orders_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('Permessi insufficienti.');

    $post_type = sanitize_key($_POST['post_type'] ?? '');
    if (!$post_type) wp_send_json_error('Post type mancante.');

    $posts = get_posts([
        'post_type'      => $post_type,
        'post_status'    => ['publish', 'draft', 'private'],
        'posts_per_page' => -1,
        'orderby'        => ['menu_order' => 'ASC', 'title' => 'ASC'],
        'order'          => 'ASC',
    ]);

    wp_send_json_success(array_map(fn($p) => [
        'id'     => $p->ID,
        'title'  => $p->post_title ?: '(senza titolo)',
        'status' => $p->post_status,
        'order'  => $p->menu_order,
    ], $posts));
});

/* ─────────────────────────────────────────
   AJAX: carica termini di una tassonomia
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_orders_get_terms', function () {
    check_ajax_referer('wpb_orders_nonce', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('Permessi insufficienti.');

    $taxonomy = sanitize_key($_POST['taxonomy'] ?? '');
    if (!$taxonomy) wp_send_json_error('Tassonomia mancante.');

    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
    if (is_wp_error($terms)) wp_send_json_error($terms->get_error_message());

    $with_order    = [];
    $without_order = [];

    foreach ($terms as $t) {
        $order = get_term_meta($t->term_id, 'wpb_term_order', true);
        $item  = ['id' => $t->term_id, 'name' => $t->name, 'count' => $t->count, 'order' => (int)$order];
        if ($order !== '') $with_order[]    = $item;
        else               $without_order[] = $item;
    }

    usort($with_order,    fn($a, $b) => $a['order'] <=> $b['order']);
    usort($without_order, fn($a, $b) => strcmp($a['name'], $b['name']));

    wp_send_json_success(array_merge($with_order, $without_order));
});

/* ─────────────────────────────────────────
   FRONT-END: applica ordine post type
   ───────────────────────────────────────── */
add_action('pre_get_posts', function ($query) {
    if (is_admin() || !$query->is_main_query()) return;

    $enabled = wpb_orders_enabled_post_types();
    if (empty($enabled)) return;

    $queried_pt = (array) $query->get('post_type');
    if (empty($queried_pt)) $queried_pt = ['post'];

    foreach ($queried_pt as $pt) {
        if (in_array($pt, $enabled, true)) {
            $query->set('orderby', 'menu_order');
            $query->set('order', 'ASC');
            break;
        }
    }
});

/* ─────────────────────────────────────────
   FRONT-END: applica ordine termini
   ───────────────────────────────────────── */
add_filter('terms_clauses', function ($clauses, $taxonomies, $args) {
    // Non applicare nell'area admin (romperebbe la gestione di categorie e tag)
    if (is_admin()) return $clauses;

    $enabled = wpb_orders_enabled_taxonomies();
    if (empty($enabled)) return $clauses;

    $has = false;
    foreach ($taxonomies as $tax) {
        if (in_array($tax, $enabled, true)) { $has = true; break; }
    }
    if (!$has) return $clauses;

    global $wpdb;
    $clauses['join']   .= " LEFT JOIN {$wpdb->termmeta} wpb_tm ON (t.term_id = wpb_tm.term_id AND wpb_tm.meta_key = 'wpb_term_order')";
    $clauses['orderby'] = 'ORDER BY CAST(COALESCE(wpb_tm.meta_value, 9999) AS UNSIGNED) ASC, t.name ASC';
    return $clauses;
}, 10, 3);

/* ─────────────────────────────────────────
   CSS INLINE
   ───────────────────────────────────────── */
function wpb_orders_inline_css(): string { return '
    #wpb-orders-wrap { max-width: 860px; }

    /* ── Tabs ── */
    .wpb-orders-tabs { display:flex; gap:2px; border-bottom:1px solid #c3c4c7; margin-bottom:0; }
    .wpb-orders-tab {
        padding:8px 18px; font-size:13px; font-weight:500; color:#646970;
        cursor:pointer; border:1px solid transparent; border-bottom:none;
        border-radius:3px 3px 0 0; background:#f6f7f7;
        transition:background .15s,color .15s; user-select:none;
    }
    .wpb-orders-tab:hover { background:#fff; color:#1d2327; }
    .wpb-orders-tab.active {
        background:#fff; color:#1d2327; border-color:#c3c4c7;
        margin-bottom:-1px; padding-bottom:9px;
    }
    .wpb-orders-panel { display:none; padding-top:24px; }
    .wpb-orders-panel.active { display:block; }

    /* ── Toggle card list ── */
    .wpb-orders-card { background:#fff; border:1px solid #c3c4c7; border-radius:3px; margin-bottom:2px; }
    .wpb-orders-row {
        display:flex; align-items:center; gap:16px;
        padding:14px 18px; border-bottom:1px solid #f0f0f1;
    }
    .wpb-orders-row:last-child { border-bottom:none; }
    .wpb-orders-row-info { flex:1; }
    .wpb-orders-row-label { font-size:13px; font-weight:600; color:#1d2327; }
    .wpb-orders-row-slug  { font-size:11px; color:#a7aaad; margin-top:1px; font-family:monospace; }

    /* ── Toggle switch ── */
    .wpb-toggle { position:relative; width:40px; height:22px; flex-shrink:0; }
    .wpb-toggle input { opacity:0; width:0; height:0; position:absolute; }
    .wpb-toggle-slider {
        position:absolute; inset:0; background:#a7aaad;
        border-radius:22px; cursor:pointer; transition:background .2s;
    }
    .wpb-toggle-slider:before {
        content:""; position:absolute; width:16px; height:16px;
        left:3px; top:3px; background:#fff; border-radius:50%; transition:transform .2s;
    }
    .wpb-toggle input:checked + .wpb-toggle-slider { background:#2271b1; }
    .wpb-toggle input:checked + .wpb-toggle-slider:before { transform:translateX(18px); }
    .wpb-toggle-saving .wpb-toggle-slider { opacity:.5; pointer-events:none; }

    /* ── Reorder page ── */
    .wpb-reorder-wrap { max-width:720px; }
    .wpb-reorder-selector { display:flex; align-items:center; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
    .wpb-reorder-list-wrap { background:#fff; border:1px solid #c3c4c7; border-radius:3px; min-height:60px; }
    .wpb-orders-list { list-style:none; margin:0; padding:0; }
    .wpb-orders-item {
        display:flex; align-items:center; gap:10px;
        padding:10px 14px; border-bottom:1px solid #f0f0f1;
        cursor:grab; transition:background .1s; font-size:13px; color:#1d2327;
    }
    .wpb-orders-item:last-child { border-bottom:none; }
    .wpb-orders-item:hover { background:#f6f7f7; }
    .wpb-orders-item.ui-sortable-helper {
        background:#f0f6fc; border:1px solid #72aee6;
        border-radius:3px; box-shadow:0 2px 8px rgba(0,0,0,.12); cursor:grabbing;
    }
    .wpb-orders-item.ui-sortable-placeholder {
        background:#e8f0fe; border-bottom:2px dashed #72aee6; visibility:visible !important;
    }
    .wpb-orders-handle { color:#a7aaad; cursor:grab; flex-shrink:0; font-size:18px; line-height:1; }
    .wpb-orders-item-title { flex:1; font-weight:500; }
    .wpb-orders-item-meta  { font-size:11px; color:#a7aaad; white-space:nowrap; }
    .wpb-orders-status { display:inline-block; padding:1px 6px; border-radius:10px; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
    .wpb-orders-status.publish { background:#d1fae5; color:#065f46; }
    .wpb-orders-status.draft   { background:#fef3c7; color:#92400e; }
    .wpb-orders-status.private { background:#ede9fe; color:#4c1d95; }

    .wpb-orders-actions { display:flex; gap:8px; margin-top:16px; align-items:center; flex-wrap:wrap; }
    .wpb-orders-saved-badge { display:none; align-items:center; gap:5px; font-size:12px; color:#00a32a; font-weight:500; }
    .wpb-orders-saved-badge.show { display:flex; }
    .wpb-orders-empty   { padding:32px 20px; text-align:center; color:#646970; font-size:13px; }
    .wpb-orders-loading { padding:32px 20px; text-align:center; color:#646970; font-size:13px; }
    .wpb-orders-info-bar {
        display:flex; align-items:center; gap:8px; padding:10px 14px;
        background:#f0f6fc; border:1px solid #c5d9f0; border-radius:3px;
        font-size:12px; color:#1d2327; margin-bottom:16px;
    }

    /* ── Tassonomie: blocco riordino inline nella pagina Orders ── */
    .wpb-orders-tax-reorder {
        margin-top:12px; padding:14px 16px;
        background:#f6f7f7; border:1px solid #dcdcde; border-radius:3px;
    }
    .wpb-orders-tax-reorder-title {
        font-size:12px; font-weight:600; color:#646970;
        text-transform:uppercase; letter-spacing:.5px; margin-bottom:10px;
    }
'; }

/* ─────────────────────────────────────────
   JS INLINE — pagina di configurazione
   ───────────────────────────────────────── */
function wpb_orders_config_js(): string
{
    $nonce    = wp_create_nonce('wpb_orders_nonce');
    $ajax_url = esc_url(admin_url('admin-ajax.php'));

    return <<<JS
jQuery(function($){
    var ajaxUrl = '{$ajax_url}';
    var nonce   = '{$nonce}';

    // Tab switching
    $(document).on('click', '.wpb-orders-tab', function(){
        var tab = $(this).data('tab');
        $('.wpb-orders-tab').removeClass('active');
        $(this).addClass('active');
        $('.wpb-orders-panel').removeClass('active');
        $('#wpb-orders-panel-' + tab).addClass('active');
    });

    // Toggle post type
    $(document).on('change', '.wpb-pt-toggle', function(){
        var input   = $(this);
        var row     = input.closest('.wpb-orders-row');
        var label   = row.find('.wpb-toggle');
        var pt      = input.data('pt');
        var enabled = input.is(':checked') ? 1 : 0;

        label.addClass('wpb-toggle-saving');
        $.post(ajaxUrl, { action:'wpb_orders_toggle_pt', nonce:nonce, post_type:pt, enabled:enabled },
        function(res){
            label.removeClass('wpb-toggle-saving');
            if (!res.success) { input.prop('checked', !enabled); alert('Errore nel salvataggio.'); }
            else {
                // mostra/nascondi nota "Riordina apparirà nel menu"
                if (enabled) row.find('.wpb-pt-hint').fadeIn(150);
                else         row.find('.wpb-pt-hint').fadeOut(150);
            }
        });
    });

    // Toggle tassonomia — e carica/scarica il blocco di riordino inline
    $(document).on('change', '.wpb-tax-toggle', function(){
        var input   = $(this);
        var row     = input.closest('.wpb-orders-row');
        var label   = row.find('.wpb-toggle');
        var tax     = input.data('tax');
        var enabled = input.is(':checked') ? 1 : 0;

        label.addClass('wpb-toggle-saving');
        $.post(ajaxUrl, { action:'wpb_orders_toggle_tax', nonce:nonce, taxonomy:tax, enabled:enabled },
        function(res){
            label.removeClass('wpb-toggle-saving');
            if (!res.success) { input.prop('checked', !enabled); alert('Errore nel salvataggio.'); return; }

            var reorderBlock = row.find('.wpb-orders-tax-reorder');
            if (enabled) {
                reorderBlock.slideDown(200);
                loadTermsInline(tax, reorderBlock.find('.wpb-tax-inline-list'));
            } else {
                reorderBlock.slideUp(200);
            }
        });
    });

    // Carica termini nel blocco inline
    function loadTermsInline(taxonomy, wrap) {
        wrap.html('<div class="wpb-orders-loading">Caricamento...</div>');
        $.post(ajaxUrl, { action:'wpb_orders_get_terms', nonce:nonce, taxonomy:taxonomy },
        function(res){
            if (!res.success) { wrap.html('<div class="wpb-orders-empty">Errore.</div>'); return; }
            var items = res.data;
            if (!items.length) { wrap.html('<div class="wpb-orders-empty">Nessun termine.</div>'); return; }

            var html = '<ul class="wpb-orders-list wpb-tax-sortable" data-tax="' + taxonomy + '">';
            $.each(items, function(i, item){
                html += '<li class="wpb-orders-item" data-id="' + item.id + '">';
                html += '<span class="wpb-orders-handle dashicons dashicons-menu"></span>';
                html += '<span class="wpb-orders-item-title">' + $('<div>').text(item.name).html() + '</span>';
                html += '<span class="wpb-orders-item-meta">' + item.count + ' elem.</span>';
                html += '</li>';
            });
            html += '</ul>';
            html += '<div class="wpb-orders-actions">';
            html += '<button class="button button-primary wpb-save-terms-inline">Salva ordine</button>';
            html += '<span class="wpb-orders-saved-badge wpb-terms-inline-saved"><span class="dashicons dashicons-yes" style="color:#00a32a;font-size:16px;width:16px;height:16px;"></span> Ordine salvato</span>';
            html += '</div>';
            wrap.html(html);

            wrap.find('.wpb-tax-sortable').sortable({
                handle:      '.wpb-orders-handle',
                placeholder: 'wpb-orders-item wpb-orders-placeholder',
                axis:        'y',
                tolerance:   'pointer'
            });
        });
    }

    // Salva termini inline (pagina config tassonomie)
    $(document).on('click', '.wpb-save-terms-inline', function(){
        var btn      = $(this);
        var actionsDiv = btn.closest('.wpb-orders-actions');
        var sortable = btn.closest('.wpb-orders-tax-reorder').find('.wpb-tax-sortable');
        var taxonomy = sortable.data('tax');
        var items    = [];
        sortable.find('.wpb-orders-item').each(function(i){ items.push($(this).data('id')); });
        if (!items.length) return;

        btn.prop('disabled', true).text('Salvataggio...');
        $.post(ajaxUrl, { action:'wpb_orders_save_terms', nonce:nonce, taxonomy:taxonomy, items:items },
        function(res){
            btn.prop('disabled', false).text('Salva ordine');
            if (res.success) {
                var badge = actionsDiv.find('.wpb-terms-inline-saved');
                badge.addClass('show');
                setTimeout(function(){ badge.removeClass('show'); }, 2500);
            }
        });
    });

    // Inizializza i blocchi tassonomia già abilitati all'apertura
    $('.wpb-tax-toggle:checked').each(function(){
        var tax  = $(this).data('tax');
        var wrap = $(this).closest('.wpb-orders-row').find('.wpb-orders-tax-reorder');
        wrap.show();
        loadTermsInline(tax, wrap.find('.wpb-tax-inline-list'));
    });
});
JS;
}

/* ─────────────────────────────────────────
   JS INLINE — pagine di riordino (post e termini)
   ───────────────────────────────────────── */
function wpb_orders_sortable_js(): string
{
    $nonce    = wp_create_nonce('wpb_orders_nonce');
    $ajax_url = esc_url(admin_url('admin-ajax.php'));

    return <<<JS
jQuery(function($){
    var ajaxUrl = '{$ajax_url}';
    var nonce   = '{$nonce}';

    // ── Carica post ────────────────────────────────────────
    function loadPosts(postType) {
        var wrap = $('#wpb-reorder-list');
        wrap.html('<div class="wpb-orders-loading">Caricamento...</div>');

        $.post(ajaxUrl, { action:'wpb_orders_get_posts', nonce:nonce, post_type:postType },
        function(res){
            if (!res.success) { wrap.html('<div class="wpb-orders-empty">Errore nel caricamento.</div>'); return; }
            var items = res.data;
            if (!items.length) { wrap.html('<div class="wpb-orders-empty">Nessun post trovato.</div>'); return; }

            var html = '<ul class="wpb-orders-list" id="wpb-sortable-posts">';
            $.each(items, function(i, item){
                var statusLabel = item.status === 'publish' ? 'Pubblicato' : (item.status === 'draft' ? 'Bozza' : item.status);
                html += '<li class="wpb-orders-item" data-id="' + item.id + '">';
                html += '<span class="wpb-orders-handle dashicons dashicons-menu"></span>';
                html += '<span class="wpb-orders-item-title">' + $('<div>').text(item.title).html() + '</span>';
                html += '<span class="wpb-orders-item-meta"><span class="wpb-orders-status ' + item.status + '">' + statusLabel + '</span></span>';
                html += '</li>';
            });
            html += '</ul>';
            wrap.html(html);

            $('#wpb-sortable-posts').sortable({
                handle:      '.wpb-orders-handle',
                placeholder: 'wpb-orders-item wpb-orders-placeholder',
                axis:        'y',
                tolerance:   'pointer'
            });
        });
    }

    // ── Carica termini ─────────────────────────────────────
    function loadTerms(taxonomy) {
        var wrap = $('#wpb-reorder-list');
        wrap.html('<div class="wpb-orders-loading">Caricamento...</div>');

        $.post(ajaxUrl, { action:'wpb_orders_get_terms', nonce:nonce, taxonomy:taxonomy },
        function(res){
            if (!res.success) { wrap.html('<div class="wpb-orders-empty">Errore nel caricamento.</div>'); return; }
            var items = res.data;
            if (!items.length) { wrap.html('<div class="wpb-orders-empty">Nessun termine trovato.</div>'); return; }

            var html = '<ul class="wpb-orders-list" id="wpb-sortable-terms">';
            $.each(items, function(i, item){
                html += '<li class="wpb-orders-item" data-id="' + item.id + '">';
                html += '<span class="wpb-orders-handle dashicons dashicons-menu"></span>';
                html += '<span class="wpb-orders-item-title">' + $('<div>').text(item.name).html() + '</span>';
                html += '<span class="wpb-orders-item-meta">' + item.count + ' elementi</span>';
                html += '</li>';
            });
            html += '</ul>';
            wrap.html(html);

            $('#wpb-sortable-terms').sortable({
                handle:      '.wpb-orders-handle',
                placeholder: 'wpb-orders-item wpb-orders-placeholder',
                axis:        'y',
                tolerance:   'pointer'
            });
        });
    }

    // ── Caricamento iniziale ───────────────────────────────
    var initPt  = $('#wpb-reorder-post-type').val();
    var initTax = $('#wpb-reorder-taxonomy').val();
    if (initPt)  loadPosts(initPt);
    if (initTax) loadTerms(initTax);

    // ── Cambio select tassonomia ───────────────────────────
    $(document).on('change', '#wpb-reorder-taxonomy', function(){
        var val = $(this).val();
        if (val) loadTerms(val);
        else $('#wpb-reorder-list').html('');
    });

    // ── Salva ordine post ──────────────────────────────────
    $(document).on('click', '#wpb-save-posts', function(){
        var btn      = $(this);
        var postType = $('#wpb-reorder-post-type').val();
        var items    = [];
        $('#wpb-sortable-posts .wpb-orders-item').each(function(i){ items.push($(this).data('id')); });
        if (!items.length || !postType) return;

        btn.prop('disabled', true).text('Salvataggio...');
        $.post(ajaxUrl, { action:'wpb_orders_save_posts', nonce:nonce, post_type:postType, items:items },
        function(res){
            btn.prop('disabled', false).text('Salva ordine');
            if (res.success) {
                var badge = $('#wpb-posts-saved');
                badge.addClass('show');
                setTimeout(function(){ badge.removeClass('show'); }, 2500);
            }
        });
    });

    // ── Salva ordine termini ───────────────────────────────
    $(document).on('click', '#wpb-save-terms', function(){
        var btn      = $(this);
        var taxonomy = $('#wpb-reorder-taxonomy').val();
        var items    = [];
        $('#wpb-sortable-terms .wpb-orders-item').each(function(i){ items.push($(this).data('id')); });
        if (!items.length || !taxonomy) return;

        btn.prop('disabled', true).text('Salvataggio...');
        $.post(ajaxUrl, { action:'wpb_orders_save_terms', nonce:nonce, taxonomy:taxonomy, items:items },
        function(res){
            btn.prop('disabled', false).text('Salva ordine');
            if (res.success) {
                var badge = $('#wpb-terms-saved');
                badge.addClass('show');
                setTimeout(function(){ badge.removeClass('show'); }, 2500);
            }
        });
    });
});
JS;
}

/* ─────────────────────────────────────────
   PAGINA ADMIN — Configurazione
   ───────────────────────────────────────── */
function wpb_orders_admin_page()
{
    if (!current_user_can('manage_options')) wp_die('Accesso negato.');

    $post_types   = wpb_orders_get_post_types();
    $taxonomies   = wpb_orders_get_taxonomies();
    $enabled_pts  = wpb_orders_enabled_post_types();
    $enabled_taxs = wpb_orders_enabled_taxonomies();
    ?>
    <div id="wpb-orders-wrap" class="wrap">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-sort"
                style="font-size:26px;width:26px;height:26px;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
            WP Barrio
            <span style="font-size:15px;font-weight:400;color:#646970;margin-left:6px;">/ Orders</span>
        </h1>
        <p class="description" style="font-size:13px;color:#646970;margin:6px 0 24px;">
            Scegli quali post type e tassonomie supportano il riordinamento personalizzato.
        </p>
        <hr class="wp-header-end" style="margin-bottom:24px;">

        <!-- Tabs -->
        <div class="wpb-orders-tabs">
            <div class="wpb-orders-tab active" data-tab="post-types">
                <span class="dashicons dashicons-admin-post"
                    style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
                Post Type
                <?php if (!empty($enabled_pts)): ?>
                    <span style="display:inline-block;margin-left:6px;padding:0 6px;background:#2271b1;color:#fff;border-radius:8px;font-size:10px;font-weight:700;vertical-align:middle;"><?php echo count($enabled_pts); ?></span>
                <?php endif; ?>
            </div>
            <div class="wpb-orders-tab" data-tab="taxonomies">
                <span class="dashicons dashicons-tag"
                    style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
                Tassonomie
                <?php if (!empty($enabled_taxs)): ?>
                    <span style="display:inline-block;margin-left:6px;padding:0 6px;background:#2271b1;color:#fff;border-radius:8px;font-size:10px;font-weight:700;vertical-align:middle;"><?php echo count($enabled_taxs); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Panel: Post Type -->
        <div id="wpb-orders-panel-post-types" class="wpb-orders-panel active">
            <div class="wpb-orders-info-bar">
                <span class="dashicons dashicons-info" style="color:#2271b1;font-size:16px;width:16px;height:16px;flex-shrink:0;"></span>
                <span>Attiva il riordinamento per un post type: apparirà la voce <strong>Riordina</strong> nel menu del post type, dove trascinare i post nell'ordine desiderato.</span>
            </div>

            <div class="wpb-orders-card">
                <?php foreach ($post_types as $pt):
                    $is_enabled = in_array($pt['slug'], $enabled_pts, true);
                ?>
                    <div class="wpb-orders-row">
                        <div class="wpb-orders-row-info">
                            <div class="wpb-orders-row-label"><?php echo esc_html($pt['label']); ?></div>
                            <div class="wpb-orders-row-slug"><?php echo esc_html($pt['slug']); ?></div>
                            <div class="wpb-pt-hint" style="font-size:11px;color:#2271b1;margin-top:3px;<?php echo $is_enabled ? '' : 'display:none;'; ?>">
                                ↳ "Riordina" è visibile nel menu <?php echo esc_html($pt['label']); ?>
                            </div>
                        </div>
                        <label class="wpb-toggle">
                            <input type="checkbox"
                                   class="wpb-pt-toggle"
                                   data-pt="<?php echo esc_attr($pt['slug']); ?>"
                                   <?php checked($is_enabled); ?>>
                            <span class="wpb-toggle-slider"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Panel: Tassonomie -->
        <div id="wpb-orders-panel-taxonomies" class="wpb-orders-panel">
            <div class="wpb-orders-info-bar">
                <span class="dashicons dashicons-info" style="color:#2271b1;font-size:16px;width:16px;height:16px;flex-shrink:0;"></span>
                <span>Attiva il riordinamento per una tassonomia: potrai ordinare i termini direttamente qui. Gli utenti con permesso di modifica troveranno la voce <strong>Riordina Categorie</strong> nel menu principale.</span>
            </div>

            <div class="wpb-orders-card">
                <?php foreach ($taxonomies as $tax):
                    $is_enabled = in_array($tax['slug'], $enabled_taxs, true);

                    if($tax['slug'] == 'post_format') continue;
                ?>
                    <div class="wpb-orders-row" style="flex-wrap:wrap; align-items:flex-start;">
                        <div class="wpb-orders-row-info" style="flex:1; min-width:0;">
                            <div class="wpb-orders-row-label"><?php echo esc_html($tax['label']); ?></div>
                            <div class="wpb-orders-row-slug"><?php echo esc_html($tax['slug']); ?></div>
                        </div>
                        <label class="wpb-toggle" style="margin-top:2px;">
                            <input type="checkbox"
                                   class="wpb-tax-toggle"
                                   data-tax="<?php echo esc_attr($tax['slug']); ?>"
                                   <?php checked($is_enabled); ?>>
                            <span class="wpb-toggle-slider"></span>
                        </label>

                        <!-- Blocco riordino inline, visibile solo se abilitata -->
                        <div class="wpb-orders-tax-reorder" style="width:100%; margin-top:10px; <?php echo $is_enabled ? '' : 'display:none;'; ?>">
                            <div class="wpb-orders-tax-reorder-title">Ordine termini — <?php echo esc_html($tax['label']); ?></div>
                            <div class="wpb-tax-inline-list">
                                <div class="wpb-orders-loading">Caricamento...</div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
}

/* ─────────────────────────────────────────
   PAGINA "Riordina" — sotto un post type
   ───────────────────────────────────────── */
function wpb_orders_reorder_posts_page(string $post_type)
{
    if (!current_user_can('edit_posts')) wp_die('Accesso negato.');

    $pt_obj = get_post_type_object($post_type);
    $label  = $pt_obj ? $pt_obj->labels->name : $post_type;
    ?>
    <div class="wpb-reorder-wrap wrap">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-sort"
                style="font-size:24px;width:24px;height:24px;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
            Riordina — <?php echo esc_html($label); ?>
        </h1>
        <p class="description" style="font-size:13px;color:#646970;margin:6px 0 20px;">
            Trascina i post nell'ordine desiderato e clicca <strong>Salva ordine</strong>.
            L'ordine verrà applicato automaticamente nel front-end.
        </p>
        <hr class="wp-header-end" style="margin-bottom:20px;">

        <input type="hidden" id="wpb-reorder-post-type" value="<?php echo esc_attr($post_type); ?>">

        <div class="wpb-reorder-list-wrap">
            <div id="wpb-reorder-list">
                <div class="wpb-orders-loading">Caricamento...</div>
            </div>
        </div>

        <div class="wpb-orders-actions">
            <button id="wpb-save-posts" class="button button-primary">Salva ordine</button>
            <span id="wpb-posts-saved" class="wpb-orders-saved-badge">
                <span class="dashicons dashicons-yes" style="color:#00a32a;font-size:16px;width:16px;height:16px;"></span>
                Ordine salvato
            </span>
        </div>
    </div>
    <?php
}

/* ─────────────────────────────────────────
   PAGINA "Riordina Categorie" — per tutti
   ───────────────────────────────────────── */
function wpb_orders_reorder_terms_page()
{
    if (!current_user_can('edit_posts')) wp_die('Accesso negato.');

    $enabled_taxs = wpb_orders_enabled_taxonomies();
    $taxonomies   = array_filter(wpb_orders_get_taxonomies(), fn($t) => in_array($t['slug'], $enabled_taxs, true));
    $taxonomies   = array_values($taxonomies);

    $first_tax = $taxonomies[0]['slug'] ?? '';
    ?>
    <div class="wpb-reorder-wrap wrap">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-sort"
                style="font-size:24px;width:24px;height:24px;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
            Riordina Categorie
        </h1>
        <p class="description" style="font-size:13px;color:#646970;margin:6px 0 20px;">
            Seleziona una tassonomia, trascina i termini nell'ordine desiderato e clicca <strong>Salva ordine</strong>.
        </p>
        <hr class="wp-header-end" style="margin-bottom:20px;">

        <?php if (empty($taxonomies)): ?>
            <div class="notice notice-warning inline">
                <p>Nessuna tassonomia è stata abilitata al riordinamento. Chiedi all'amministratore di attivarle in <strong>WP Barrio → Orders</strong>.</p>
            </div>
        <?php else: ?>

            <div class="wpb-reorder-selector">
                <select id="wpb-reorder-taxonomy" class="regular-text">
                    <?php foreach ($taxonomies as $tax): ?>
                        <option value="<?php echo esc_attr($tax['slug']); ?>"
                            <?php selected($tax['slug'], $first_tax); ?>>
                            <?php echo esc_html($tax['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wpb-reorder-list-wrap">
                <div id="wpb-reorder-list">
                    <div class="wpb-orders-loading">Caricamento...</div>
                </div>
            </div>

            <div class="wpb-orders-actions">
                <button id="wpb-save-terms" class="button button-primary">Salva ordine</button>
                <span id="wpb-terms-saved" class="wpb-orders-saved-badge">
                    <span class="dashicons dashicons-yes" style="color:#00a32a;font-size:16px;width:16px;height:16px;"></span>
                    Ordine salvato
                </span>
            </div>

        <?php endif; ?>
    </div>
    <?php
}
