<?php
/**
 * Suite Barrio — Orders
 * Riordinamento drag & drop di post type e termini di tassonomia.
 *
 * Architettura:
 *  - Pagina "Orders" (manage_options): configura quali PT e tassonomie
 *    sono riordinabili + riordino diretto delle tassonomie.
 *  - Sottovoce "Riordina" sotto ogni post type abilitato: drag & drop post.
 *  - Voce "Riordina Categorie" per utenti con edit_posts: riordino tassonomie.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('SUITE_BARRIO_ORDERS_VERSION', '1.0.0');
define('SUITE_BARRIO_ORDERS_OPTION', 'suite_barrio_orders_settings');

/* ─────────────────────────────────────────
   HELPERS: lettura impostazioni
   ───────────────────────────────────────── */
function suite_barrio_orders_settings(): array
{
    return get_option(SUITE_BARRIO_ORDERS_OPTION, []);
}

function suite_barrio_orders_enabled_post_types(): array
{
    $s = suite_barrio_orders_settings();
    return $s['enabled_post_types'] ?? [];
}

function suite_barrio_orders_enabled_taxonomies(): array
{
    $s = suite_barrio_orders_settings();
    return $s['enabled_taxonomies'] ?? [];
}

function suite_barrio_orders_get_post_types(): array
{
    $post_types = get_post_types(['public' => true], 'objects');
    unset($post_types['attachment']);
    $result = [];
    foreach ($post_types as $pt) {
        $result[] = [
            'slug' => $pt->name,
            'label' => $pt->labels->name ?? $pt->name,
            'menu_icon' => $pt->menu_icon ?? 'dashicons-admin-post',
        ];
    }
    return $result;
}

function suite_barrio_orders_get_taxonomies(): array
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
   MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {

    // 1. Pagina Orders nel menu Suite Barrio (solo admin)
    add_submenu_page(
        'suite-barrio',
        'Orders — Suite Barrio',
        'Orders',
        'manage_options',
        'suite-barrio-orders',
        'suite_barrio_orders_admin_page'
    );

    // 2. "Riordina" sotto ogni post type abilitato
    $enabled_pts = suite_barrio_orders_enabled_post_types();
    foreach ($enabled_pts as $pt_slug) {
        $pt_obj = get_post_type_object($pt_slug);
        if (!$pt_obj)
            continue;

        // Il menu padre del post type (es. "edit.php?post_type=prodotti")
        $parent_slug = ($pt_slug === 'post')
            ? 'edit.php'
            : 'edit.php?post_type=' . $pt_slug;

        add_submenu_page(
            $parent_slug,
            'Riordina — ' . $pt_obj->labels->name,
            'Riordina',
            'edit_posts',
            'suite-barrio-reorder-' . $pt_slug,
            function () use ($pt_slug) {
                suite_barrio_orders_reorder_posts_page($pt_slug);
            }
        );
    }

    // 3. "Riordina Categorie" — solo per utenti NON admin
    $enabled_taxs = suite_barrio_orders_enabled_taxonomies();
    if (!empty($enabled_taxs) && !current_user_can('manage_options')) {
        add_menu_page(
            'Riordina Categorie — Suite Barrio',
            'Riordina Categorie',
            'edit_posts',
            'suite-barrio-reorder-terms',
            'suite_barrio_orders_reorder_terms_page',
            'dashicons-sort',
            80
        );
    }

}, 16);

/* ─────────────────────────────────────────
   ENQUEUE SCRIPTS
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    $is_orders_page = strpos($hook, 'suite-barrio-orders') !== false;
    $is_reorder_posts = strpos($hook, 'suite-barrio-reorder-') !== false;
    $is_reorder_terms = strpos($hook, 'suite-barrio-reorder-terms') !== false;

    if (!$is_orders_page && !$is_reorder_posts && !$is_reorder_terms)
        return;

    wp_enqueue_script('jquery-ui-sortable');

    if ($is_reorder_posts || $is_reorder_terms) {
        wp_add_inline_script('jquery-ui-sortable', suite_barrio_orders_sortable_js(), 'after');
    }
    if ($is_orders_page) {
        wp_add_inline_script('jquery-ui-sortable', suite_barrio_orders_config_js(), 'after');
    }
});

/* ─────────────────────────────────────────
   AJAX: toggle abilitazione post type
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_orders_toggle_pt', function () {
    check_ajax_referer('suite_barrio_orders_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permessi insufficienti.');

    $pt = sanitize_key($_POST['post_type'] ?? '');
    $enabled = isset($_POST['enabled']) ? (bool) sanitize_text_field(wp_unslash($_POST['enabled'])) : false;

    if (!$pt)
        wp_send_json_error('Post type mancante.');

    $s = suite_barrio_orders_settings();
    $list = $s['enabled_post_types'] ?? [];

    if ($enabled) {
        $list = array_unique(array_merge($list, [$pt]));
    } else {
        $list = array_values(array_diff($list, [$pt]));
        // Azzera anche i menu_order esistenti
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query interna del plugin su tabella core WP, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $wpdb->update($wpdb->posts, ['menu_order' => 0], ['post_type' => $pt], ['%d'], ['%s']);
    }

    $s['enabled_post_types'] = $list;
    update_option(SUITE_BARRIO_ORDERS_OPTION, $s);
    wp_send_json_success($list);
});

/* ─────────────────────────────────────────
   AJAX: toggle abilitazione tassonomia
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_orders_toggle_tax', function () {
    check_ajax_referer('suite_barrio_orders_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permessi insufficienti.');

    $tax = sanitize_key($_POST['taxonomy'] ?? '');
    $enabled = isset($_POST['enabled']) ? (bool) sanitize_text_field(wp_unslash($_POST['enabled'])) : false;

    if (!$tax)
        wp_send_json_error('Tassonomia mancante.');

    $s = suite_barrio_orders_settings();
    $list = $s['enabled_taxonomies'] ?? [];

    if ($enabled) {
        $list = array_unique(array_merge($list, [$tax]));
    } else {
        $list = array_values(array_diff($list, [$tax]));
        // Rimuovi termmeta esistente
        $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
        if (!is_wp_error($terms)) {
            foreach ($terms as $t)
                delete_term_meta($t->term_id, 'suite_barrio_term_order');
        }
    }

    $s['enabled_taxonomies'] = $list;
    update_option(SUITE_BARRIO_ORDERS_OPTION, $s);
    wp_send_json_success($list);
});

/* ─────────────────────────────────────────
   AJAX: salva ordine post
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_orders_save_posts', function () {
    check_ajax_referer('suite_barrio_orders_nonce', 'nonce');
    if (!current_user_can('edit_posts'))
        wp_send_json_error('Permessi insufficienti.');

    $items = isset($_POST['items']) ? array_map('absint', wp_unslash((array) $_POST['items'])) : [];
    $post_type = sanitize_key($_POST['post_type'] ?? '');

    if (!in_array($post_type, suite_barrio_orders_enabled_post_types(), true))
        wp_send_json_error('Post type non abilitato.');

    foreach ($items as $order => $post_id) {
        $post_id = absint($post_id);
        if (!$post_id)
            continue;
        wp_update_post(['ID' => $post_id, 'menu_order' => (int) $order]);
    }
    if (function_exists('suite_barrio_invalidate_vercel_cache')) {
        suite_barrio_invalidate_vercel_cache('wp:type:' . $post_type);
    }
    wp_send_json_success('Ordine salvato.');
});

/* ─────────────────────────────────────────
   AJAX: salva ordine termini
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_orders_save_terms', function () {
    check_ajax_referer('suite_barrio_orders_nonce', 'nonce');
    if (!current_user_can('edit_posts'))
        wp_send_json_error('Permessi insufficienti.');

    $items = isset($_POST['items']) ? array_map('absint', wp_unslash((array) $_POST['items'])) : [];
    $taxonomy = sanitize_key($_POST['taxonomy'] ?? '');

    if (!in_array($taxonomy, suite_barrio_orders_enabled_taxonomies(), true))
        wp_send_json_error('Tassonomia non abilitata.');

    foreach ($items as $order => $term_id) {
        $term_id = absint($term_id);
        if (!$term_id)
            continue;
        update_term_meta($term_id, 'suite_barrio_term_order', (int) $order);
    }
    if (function_exists('suite_barrio_invalidate_vercel_cache')) {
        suite_barrio_invalidate_vercel_cache('wp:type:' . $taxonomy);
    }
    wp_send_json_success('Ordine salvato.');
});

/* ─────────────────────────────────────────
   AJAX: carica post di un post type
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_orders_get_posts', function () {
    check_ajax_referer('suite_barrio_orders_nonce', 'nonce');
    if (!current_user_can('edit_posts'))
        wp_send_json_error('Permessi insufficienti.');

    $post_type = sanitize_key($_POST['post_type'] ?? '');
    if (!$post_type)
        wp_send_json_error('Post type mancante.');

    $posts = get_posts([
        'post_type' => $post_type,
        'post_status' => ['publish', 'draft', 'private'],
        'posts_per_page' => -1,
        'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC'],
        'order' => 'ASC',
    ]);

    wp_send_json_success(array_map(fn($p) => [
        'id' => $p->ID,
        'title' => $p->post_title ?: '(senza titolo)',
        'status' => $p->post_status,
        'order' => $p->menu_order,
    ], $posts));
});

/* ─────────────────────────────────────────
   AJAX: carica termini di una tassonomia
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_orders_get_terms', function () {
    check_ajax_referer('suite_barrio_orders_nonce', 'nonce');
    if (!current_user_can('edit_posts'))
        wp_send_json_error('Permessi insufficienti.');

    $taxonomy = sanitize_key($_POST['taxonomy'] ?? '');
    if (!$taxonomy)
        wp_send_json_error('Tassonomia mancante.');

    $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
    if (is_wp_error($terms))
        wp_send_json_error($terms->get_error_message());

    $with_order = [];
    $without_order = [];

    foreach ($terms as $t) {
        $order = get_term_meta($t->term_id, 'suite_barrio_term_order', true);
        $item = ['id' => $t->term_id, 'name' => $t->name, 'count' => $t->count, 'order' => (int) $order];
        if ($order !== '')
            $with_order[] = $item;
        else
            $without_order[] = $item;
    }

    usort($with_order, fn($a, $b) => $a['order'] <=> $b['order']);
    usort($without_order, fn($a, $b) => strcmp($a['name'], $b['name']));

    wp_send_json_success(array_merge($with_order, $without_order));
});

/* ─────────────────────────────────────────
   FRONT-END: applica ordine post type
   ───────────────────────────────────────── */
add_action('pre_get_posts', function ($query) {
    if (is_admin() || !$query->is_main_query())
        return;

    $enabled = suite_barrio_orders_enabled_post_types();
    if (empty($enabled))
        return;

    $queried_pt = (array) $query->get('post_type');
    if (empty($queried_pt))
        $queried_pt = ['post'];

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
    if (is_admin())
        return $clauses;

    $enabled = suite_barrio_orders_enabled_taxonomies();
    if (empty($enabled))
        return $clauses;

    $has = false;
    foreach ($taxonomies as $tax) {
        if (in_array($tax, $enabled, true)) {
            $has = true;
            break;
        }
    }
    if (!$has)
        return $clauses;

    global $wpdb;
    $clauses['join'] .= " LEFT JOIN {$wpdb->termmeta} suite_barrio_tm ON (t.term_id = suite_barrio_tm.term_id AND suite_barrio_tm.meta_key = 'suite_barrio_term_order')";
    $clauses['orderby'] = 'ORDER BY CAST(COALESCE(suite_barrio_tm.meta_value, 9999) AS UNSIGNED) ASC, t.name ASC';
    return $clauses;
}, 10, 3);

function suite_barrio_orders_config_js(): string
{
    $nonce = wp_create_nonce('suite_barrio_orders_nonce');
    $ajax_url = esc_url(admin_url('admin-ajax.php'));

    return 'jQuery(function($){
    var ajaxUrl = \'' . $ajax_url . '\';
    var nonce   = \'' . $nonce . '\';

    // Tab switching
    $(document).on(\'click\', \'.suite-barrio-orders-tab\', function(){
        var tab = $(this).data(\'tab\');
        $(\'.suite-barrio-orders-tab\').removeClass(\'active\');
        $(this).addClass(\'active\');
        $(\'.suite-barrio-orders-panel\').removeClass(\'active\');
        $(\'#suite-barrio-orders-panel-\' + tab).addClass(\'active\');
    });

    // Toggle post type
    $(document).on(\'change\', \'.suite-barrio-pt-toggle\', function(){
        var input   = $(this);
        var row     = input.closest(\'.suite-barrio-orders-row\');
        var label   = row.find(\'.suite-barrio-toggle\');
        var pt      = input.data(\'pt\');
        var enabled = input.is(\':checked\') ? 1 : 0;

        label.addClass(\'suite-barrio-toggle-saving\');
        $.post(ajaxUrl, { action:\'suite_barrio_orders_toggle_pt\', nonce:nonce, post_type:pt, enabled:enabled },
        function(res){
            label.removeClass(\'suite-barrio-toggle-saving\');
            if (!res.success) { input.prop(\'checked\', !enabled); alert(\'Errore nel salvataggio.\'); }
            else {
                // mostra/nascondi nota "Riordina apparirà nel menu"
                if (enabled) row.find(\'.suite-barrio-pt-hint\').fadeIn(150);
                else         row.find(\'.suite-barrio-pt-hint\').fadeOut(150);
            }
        });
    });

    // Toggle tassonomia — e carica/scarica il blocco di riordino inline
    $(document).on(\'change\', \'.suite-barrio-tax-toggle\', function(){
        var input   = $(this);
        var row     = input.closest(\'.suite-barrio-orders-row\');
        var label   = row.find(\'.suite-barrio-toggle\');
        var tax     = input.data(\'tax\');
        var enabled = input.is(\':checked\') ? 1 : 0;

        label.addClass(\'suite-barrio-toggle-saving\');
        $.post(ajaxUrl, { action:\'suite_barrio_orders_toggle_tax\', nonce:nonce, taxonomy:tax, enabled:enabled },
        function(res){
            label.removeClass(\'suite-barrio-toggle-saving\');
            if (!res.success) { input.prop(\'checked\', !enabled); alert(\'Errore nel salvataggio.\'); return; }

            var reorderBlock = row.find(\'.suite-barrio-orders-tax-reorder\');
            if (enabled) {
                reorderBlock.slideDown(200);
                loadTermsInline(tax, reorderBlock.find(\'.suite-barrio-tax-inline-list\'));
            } else {
                reorderBlock.slideUp(200);
            }
        });
    });

    // Carica termini nel blocco inline
    function loadTermsInline(taxonomy, wrap) {
        wrap.html(\'<div class="suite-barrio-orders-loading">Caricamento...</div>\');
        $.post(ajaxUrl, { action:\'suite_barrio_orders_get_terms\', nonce:nonce, taxonomy:taxonomy },
        function(res){
            if (!res.success) { wrap.html(\'<div class="suite-barrio-orders-empty">Errore.</div>\'); return; }
            var items = res.data;
            if (!items.length) { wrap.html(\'<div class="suite-barrio-orders-empty">Nessun termine.</div>\'); return; }

            var html = \'<ul class="suite-barrio-orders-list suite-barrio-tax-sortable" data-tax="\' + taxonomy + \'">\';
            $.each(items, function(i, item){
                html += \'<li class="suite-barrio-orders-item" data-id="\' + item.id + \'">\';
                html += \'<span class="suite-barrio-orders-handle dashicons dashicons-menu"></span>\';
                html += \'<span class="suite-barrio-orders-item-title">\' + $(\'<div>\').text(item.name).html() + \'</span>\';
                html += \'<span class="suite-barrio-orders-item-meta">\' + item.count + \' elem.</span>\';
                html += \'</li>\';
            });
            html += \'</ul>\';
            html += \'<div class="suite-barrio-orders-actions">\';
            html += \'<button class="button button-primary suite-barrio-save-terms-inline">Salva ordine</button>\';
            html += \'<span class="suite-barrio-orders-saved-badge suite-barrio-terms-inline-saved"><span class="dashicons dashicons-yes" style="color:#00a32a;font-size:16px;width:16px;height:16px;"></span> Ordine salvato</span>\';
            html += \'</div>\';
            wrap.html(html);

            wrap.find(\'.suite-barrio-tax-sortable\').sortable({
                handle:      \'.suite-barrio-orders-handle\',
                placeholder: \'suite-barrio-orders-item suite-barrio-orders-placeholder\',
                axis:        \'y\',
                tolerance:   \'pointer\'
            });
        });
    }

    // Salva termini inline (pagina config tassonomie)
    $(document).on(\'click\', \'.suite-barrio-save-terms-inline\', function(){
        var btn      = $(this);
        var actionsDiv = btn.closest(\'.suite-barrio-orders-actions\');
        var sortable = btn.closest(\'.suite-barrio-orders-tax-reorder\').find(\'.suite-barrio-tax-sortable\');
        var taxonomy = sortable.data(\'tax\');
        var items    = [];
        sortable.find(\'.suite-barrio-orders-item\').each(function(i){ items.push($(this).data(\'id\')); });
        if (!items.length) return;

        btn.prop(\'disabled\', true).text(\'Salvataggio...\');
        $.post(ajaxUrl, { action:\'suite_barrio_orders_save_terms\', nonce:nonce, taxonomy:taxonomy, items:items },
        function(res){
            btn.prop(\'disabled\', false).text(\'Salva ordine\');
            if (res.success) {
                var badge = actionsDiv.find(\'.suite-barrio-terms-inline-saved\');
                badge.addClass(\'show\');
                setTimeout(function(){ badge.removeClass(\'show\'); }, 2500);
            }
        });
    });

    // Inizializza i blocchi tassonomia già abilitati all\'apertura
    $(\'.suite-barrio-tax-toggle:checked\').each(function(){
        var tax  = $(this).data(\'tax\');
        var wrap = $(this).closest(\'.suite-barrio-orders-row\').find(\'.suite-barrio-orders-tax-reorder\');
        wrap.show();
        loadTermsInline(tax, wrap.find(\'.suite-barrio-tax-inline-list\'));
    });
});';
}

/* ─────────────────────────────────────────
   JS INLINE — pagine di riordino (post e termini)
   ───────────────────────────────────────── */
function suite_barrio_orders_sortable_js(): string
{
    $nonce = wp_create_nonce('suite_barrio_orders_nonce');
    $ajax_url = esc_url(admin_url('admin-ajax.php'));

    return 'jQuery(function($){
    var ajaxUrl = \'' . $ajax_url . '\';
    var nonce   = \'' . $nonce . '\';

    // ── Carica post ────────────────────────────────────────
    function loadPosts(postType) {
        var wrap = $(\'#suite-barrio-reorder-list\');
        wrap.html(\'<div class="suite-barrio-orders-loading">Caricamento...</div>\');

        $.post(ajaxUrl, { action:\'suite_barrio_orders_get_posts\', nonce:nonce, post_type:postType },
        function(res){
            if (!res.success) { wrap.html(\'<div class="suite-barrio-orders-empty">Errore nel caricamento.</div>\'); return; }
            var items = res.data;
            if (!items.length) { wrap.html(\'<div class="suite-barrio-orders-empty">Nessun post trovato.</div>\'); return; }

            var html = \'<ul class="suite-barrio-orders-list" id="suite-barrio-sortable-posts">\';
            $.each(items, function(i, item){
                var statusLabel = item.status === \'publish\' ? \'Pubblicato\' : (item.status === \'draft\' ? \'Bozza\' : item.status);
                html += \'<li class="suite-barrio-orders-item" data-id="\' + item.id + \'">\';
                html += \'<span class="suite-barrio-orders-handle dashicons dashicons-menu"></span>\';
                html += \'<span class="suite-barrio-orders-item-title">\' + $(\'<div>\').text(item.title).html() + \'</span>\';
                html += \'<span class="suite-barrio-orders-item-meta"><span class="suite-barrio-orders-status \' + item.status + \'">\' + statusLabel + \'</span></span>\';
                html += \'</li>\';
            });
            html += \'</ul>\';
            wrap.html(html);

            $(\'#suite-barrio-sortable-posts\').sortable({
                handle:      \'.suite-barrio-orders-handle\',
                placeholder: \'suite-barrio-orders-item suite-barrio-orders-placeholder\',
                axis:        \'y\',
                tolerance:   \'pointer\'
            });
        });
    }

    // ── Carica termini ─────────────────────────────────────
    function loadTerms(taxonomy) {
        var wrap = $(\'#suite-barrio-reorder-list\');
        wrap.html(\'<div class="suite-barrio-orders-loading">Caricamento...</div>\');

        $.post(ajaxUrl, { action:\'suite_barrio_orders_get_terms\', nonce:nonce, taxonomy:taxonomy },
        function(res){
            if (!res.success) { wrap.html(\'<div class="suite-barrio-orders-empty">Errore nel caricamento.</div>\'); return; }
            var items = res.data;
            if (!items.length) { wrap.html(\'<div class="suite-barrio-orders-empty">Nessun termine trovato.</div>\'); return; }

            var html = \'<ul class="suite-barrio-orders-list" id="suite-barrio-sortable-terms">\';
            $.each(items, function(i, item){
                html += \'<li class="suite-barrio-orders-item" data-id="\' + item.id + \'">\';
                html += \'<span class="suite-barrio-orders-handle dashicons dashicons-menu"></span>\';
                html += \'<span class="suite-barrio-orders-item-title">\' + $(\'<div>\').text(item.name).html() + \'</span>\';
                html += \'<span class="suite-barrio-orders-item-meta">\' + item.count + \' elementi</span>\';
                html += \'</li>\';
            });
            html += \'</ul>\';
            wrap.html(html);

            $(\'#suite-barrio-sortable-terms\').sortable({
                handle:      \'.suite-barrio-orders-handle\',
                placeholder: \'suite-barrio-orders-item suite-barrio-orders-placeholder\',
                axis:        \'y\',
                tolerance:   \'pointer\'
            });
        });
    }

    // ── Caricamento iniziale ───────────────────────────────
    var initPt  = $(\'#suite-barrio-reorder-post-type\').val();
    var initTax = $(\'#suite-barrio-reorder-taxonomy\').val();
    if (initPt)  loadPosts(initPt);
    if (initTax) loadTerms(initTax);

    // ── Cambio select tassonomia ───────────────────────────
    $(document).on(\'change\', \'#suite-barrio-reorder-taxonomy\', function(){
        var val = $(this).val();
        if (val) loadTerms(val);
        else $(\'#suite-barrio-reorder-list\').html(\'\');
    });

    // ── Salva ordine post ──────────────────────────────────
    $(document).on(\'click\', \'#suite-barrio-save-posts\', function(){
        var btn      = $(this);
        var postType = $(\'#suite-barrio-reorder-post-type\').val();
        var items    = [];
        $(\'#suite-barrio-sortable-posts .suite-barrio-orders-item\').each(function(i){ items.push($(this).data(\'id\')); });
        if (!items.length || !postType) return;

        btn.prop(\'disabled\', true).text(\'Salvataggio...\');
        $.post(ajaxUrl, { action:\'suite_barrio_orders_save_posts\', nonce:nonce, post_type:postType, items:items },
        function(res){
            btn.prop(\'disabled\', false).text(\'Salva ordine\');
            if (res.success) {
                var badge = $(\'#suite-barrio-posts-saved\');
                badge.addClass(\'show\');
                setTimeout(function(){ badge.removeClass(\'show\'); }, 2500);
            }
        });
    });

    // ── Salva ordine termini ───────────────────────────────
    $(document).on(\'click\', \'#suite-barrio-save-terms\', function(){
        var btn      = $(this);
        var taxonomy = $(\'#suite-barrio-reorder-taxonomy\').val();
        var items    = [];
        $(\'#suite-barrio-sortable-terms .suite-barrio-orders-item\').each(function(i){ items.push($(this).data(\'id\')); });
        if (!items.length || !taxonomy) return;

        btn.prop(\'disabled\', true).text(\'Salvataggio...\');
        $.post(ajaxUrl, { action:\'suite_barrio_orders_save_terms\', nonce:nonce, taxonomy:taxonomy, items:items },
        function(res){
            btn.prop(\'disabled\', false).text(\'Salva ordine\');
            if (res.success) {
                var badge = $(\'#suite-barrio-terms-saved\');
                badge.addClass(\'show\');
                setTimeout(function(){ badge.removeClass(\'show\'); }, 2500);
            }
        });
    });
});';
}

/* ─────────────────────────────────────────
   PAGINA ADMIN — Configurazione
   ───────────────────────────────────────── */
function suite_barrio_orders_admin_page()
{
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');

    $post_types = suite_barrio_orders_get_post_types();
    $taxonomies = suite_barrio_orders_get_taxonomies();
    $enabled_pts = suite_barrio_orders_enabled_post_types();
    $enabled_taxs = suite_barrio_orders_enabled_taxonomies();
    ?>
    <div id="suite-barrio-orders-wrap" class="wrap suite-barrio-wrap">

        <!-- Header -->
        <div class="suite-barrio-ord-header">
            <div class="suite-barrio-ord-header-icon"><span class="dashicons dashicons-sort"></span></div>
            <div>
                <h1>Suite Barrio <span class="suite-barrio-ord-breadcrumb">/ Orders</span></h1>
                <p class="suite-barrio-ord-sub">Attiva il riordinamento drag &amp; drop per post type e tassonomie</p>
            </div>
        </div>
        <hr class="suite-barrio-ord-divider">

        <!-- Tabs -->
        <div class="suite-barrio-orders-tabs">
            <div class="suite-barrio-orders-tab active" data-tab="post-types">
                <span class="dashicons dashicons-admin-post"></span>
                Post Type
                <?php if (!empty($enabled_pts)): ?>
                    <span class="suite-barrio-orders-tab-badge"><?php echo count($enabled_pts); ?></span>
                <?php endif; ?>
            </div>
            <div class="suite-barrio-orders-tab" data-tab="taxonomies">
                <span class="dashicons dashicons-tag"></span>
                Tassonomie
                <?php if (!empty($enabled_taxs)): ?>
                    <span class="suite-barrio-orders-tab-badge"><?php echo count($enabled_taxs); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Panel: Post Type -->
        <div id="suite-barrio-orders-panel-post-types" class="suite-barrio-orders-panel active">
            <div class="suite-barrio-orders-notice">
                <span class="dashicons dashicons-info"></span>
                <div>Attiva il riordinamento per un post type: apparirà la voce <strong>Riordina</strong> nel menu del post
                    type, dove trascinare i post nell'ordine desiderato. L'ordine viene applicato automaticamente nel
                    front-end.</div>
            </div>
            <div class="suite-barrio-orders-card">
                <?php foreach ($post_types as $pt):
                    $is_enabled = in_array($pt['slug'], $enabled_pts, true);
                    ?>
                    <div class="suite-barrio-orders-row">
                        <div class="suite-barrio-orders-row-info">
                            <div class="suite-barrio-orders-row-label"><?php echo esc_html($pt['label']); ?></div>
                            <div class="suite-barrio-orders-row-slug"><?php echo esc_html($pt['slug']); ?></div>
                            <div class="suite-barrio-pt-hint" <?php echo $is_enabled ? '' : 'style="display:none;"'; ?>>
                                &#x2713; «Riordina» visibile nel menu <?php echo esc_html($pt['label']); ?>
                            </div>
                        </div>
                        <label class="suite-barrio-toggle">
                            <input type="checkbox" class="suite-barrio-pt-toggle" data-pt="<?php echo esc_attr($pt['slug']); ?>" <?php checked($is_enabled); ?>>
                            <span class="suite-barrio-toggle-track"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Panel: Tassonomie -->
        <div id="suite-barrio-orders-panel-taxonomies" class="suite-barrio-orders-panel">
            <div class="suite-barrio-orders-notice">
                <span class="dashicons dashicons-info"></span>
                <div>Attiva il riordinamento per una tassonomia: potrai ordinare i termini direttamente qui. Gli utenti con
                    permesso di modifica troveranno la voce <strong>Riordina Categorie</strong> nel menu principale.</div>
            </div>
            <div class="suite-barrio-orders-card">
                <?php foreach ($taxonomies as $tax):
                    if ($tax['slug'] === 'post_format')
                        continue;
                    $is_enabled = in_array($tax['slug'], $enabled_taxs, true);
                    ?>
                    <div class="suite-barrio-orders-row" style="flex-wrap:wrap; align-items:flex-start;">
                        <div class="suite-barrio-orders-row-info">
                            <div class="suite-barrio-orders-row-label"><?php echo esc_html($tax['label']); ?></div>
                            <div class="suite-barrio-orders-row-slug"><?php echo esc_html($tax['slug']); ?></div>
                        </div>
                        <label class="suite-barrio-toggle" style="margin-top:2px;">
                            <input type="checkbox" class="suite-barrio-tax-toggle" data-tax="<?php echo esc_attr($tax['slug']); ?>" <?php checked($is_enabled); ?>>
                            <span class="suite-barrio-toggle-track"></span>
                        </label>
                        <!-- Blocco riordino inline -->
                        <div class="suite-barrio-orders-tax-reorder" <?php echo $is_enabled ? '' : 'style="display:none;"'; ?>>
                            <div class="suite-barrio-orders-tax-reorder-title">Ordine termini — <?php echo esc_html($tax['label']); ?>
                            </div>
                            <div class="suite-barrio-tax-inline-list">
                                <div class="suite-barrio-orders-loading">Caricamento…</div>
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
function suite_barrio_orders_reorder_posts_page(string $post_type)
{
    if (!current_user_can('edit_posts'))
        wp_die('Accesso negato.');

    $pt_obj = get_post_type_object($post_type);
    $label = $pt_obj ? $pt_obj->labels->name : $post_type;
    ?>
    <div class="suite-barrio-reorder-wrap wrap">

        <!-- Header -->
        <div class="suite-barrio-ord-header">
            <div class="suite-barrio-ord-header-icon"><span class="dashicons dashicons-sort"></span></div>
            <div>
                <h1>Riordina &mdash; <?php echo esc_html($label); ?></h1>
                <p class="suite-barrio-ord-sub">Trascina i post nell'ordine desiderato e clicca Salva ordine</p>
            </div>
        </div>
        <hr class="suite-barrio-ord-divider">

        <input type="hidden" id="suite-barrio-reorder-post-type" value="<?php echo esc_attr($post_type); ?>">

        <div class="suite-barrio-reorder-list-wrap">
            <div id="suite-barrio-reorder-list">
                <div class="suite-barrio-orders-loading">Caricamento…</div>
            </div>
        </div>

        <div class="suite-barrio-orders-actions">
            <button id="suite-barrio-save-posts" class="suite-barrio-orders-btn">
                <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                Salva ordine
            </button>
            <span id="suite-barrio-posts-saved" class="suite-barrio-orders-saved-badge">
                <span class="dashicons dashicons-yes-alt"
                    style="font-size:18px;width:18px;height:18px;color:#059669;"></span>
                Ordine salvato
            </span>
        </div>
    </div>
    <?php
}

/* ─────────────────────────────────────────
   PAGINA "Riordina Categorie" — per tutti
   ───────────────────────────────────────── */
function suite_barrio_orders_reorder_terms_page()
{
    if (!current_user_can('edit_posts'))
        wp_die('Accesso negato.');

    $enabled_taxs = suite_barrio_orders_enabled_taxonomies();
    $taxonomies = array_filter(suite_barrio_orders_get_taxonomies(), fn($t) => in_array($t['slug'], $enabled_taxs, true));
    $taxonomies = array_values($taxonomies);
    $first_tax = $taxonomies[0]['slug'] ?? '';
    ?>
    <div class="suite-barrio-reorder-wrap wrap">

        <!-- Header -->
        <div class="suite-barrio-ord-header">
            <div class="suite-barrio-ord-header-icon"><span class="dashicons dashicons-sort"></span></div>
            <div>
                <h1>Riordina Categorie</h1>
                <p class="suite-barrio-ord-sub">Seleziona una tassonomia, trascina i termini e clicca Salva ordine</p>
            </div>
        </div>
        <hr class="suite-barrio-ord-divider">

        <?php if (empty($taxonomies)): ?>
            <div class="suite-barrio-orders-notice" style="background:#fffbeb;border-color:#fde68a;color:#92400e;">
                <span class="dashicons dashicons-warning" style="color:#d97706;"></span>
                <div>Nessuna tassonomia è stata abilitata al riordinamento. Chiedi all'amministratore di attivarle in <strong>WP
                        Barrio → Orders</strong>.</div>
            </div>
        <?php else: ?>
            <div class="suite-barrio-reorder-selector">
                <select id="suite-barrio-reorder-taxonomy">
                    <?php foreach ($taxonomies as $tax): ?>
                        <option value="<?php echo esc_attr($tax['slug']); ?>" <?php selected($tax['slug'], $first_tax); ?>>
                            <?php echo esc_html($tax['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="suite-barrio-reorder-list-wrap">
                <div id="suite-barrio-reorder-list">
                    <div class="suite-barrio-orders-loading">Caricamento…</div>
                </div>
            </div>

            <div class="suite-barrio-orders-actions">
                <button id="suite-barrio-save-terms" class="suite-barrio-orders-btn">
                    <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;"></span>
                    Salva ordine
                </button>
                <span id="suite-barrio-terms-saved" class="suite-barrio-orders-saved-badge">
                    <span class="dashicons dashicons-yes-alt"
                        style="font-size:18px;width:18px;height:18px;color:#059669;"></span>
                    Ordine salvato
                </span>
            </div>
        <?php endif; ?>

    </div>
    <?php
}