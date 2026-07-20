<?php
/**
 * Suite Barrio — Columns
 * Gestione delle colonne delle liste in bacheca (edit.php) per ogni post type,
 * in stile Admin Columns Pro: un ingranaggio accanto al titolo della lista apre
 * una modale per attivare, riordinare e rinominare le colonne. Sorgenti:
 * info generiche del post, tassonomie del post type e campi custom ACF.
 * La configurazione è globale: ogni ruolo vede la stessa vista di colonne,
 * e ogni ruolo con accesso alla lista può modificarla.
 *
 * Fuori scope (prototipo): tabella media (upload.php usa hook manage_media_*),
 * tabelle tassonomie, inline edit dei valori, larghezza colonne, subfield di
 * repeater/group, sorting su colonne tassonomia, export/import, viste per ruolo.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   COSTANTI
   ───────────────────────────────────────── */
define('SUITE_BARRIO_COLUMNS_VERSION', '1.0.0');
define('SUITE_BARRIO_COLUMNS_OPTION', 'wpb_columns_settings');

/* ─────────────────────────────────────────
   HELPER: impostazioni e configurazione per post type
   ───────────────────────────────────────── */
function suite_barrio_columns_settings(): array
{
    $settings = get_option(SUITE_BARRIO_COLUMNS_OPTION, []);
    return is_array($settings) ? $settings : [];
}

function suite_barrio_columns_get_config(string $pt): array
{
    $settings = suite_barrio_columns_settings();
    if (isset($settings[$pt]['columns']) && is_array($settings[$pt]['columns']))
        return $settings[$pt]['columns'];
    return [];
}

/* Post type gestibili: pubblici, esclusi gli allegati (upload.php usa hook diversi) */
function suite_barrio_columns_allowed_post_types(): array
{
    $post_types = get_post_types(['public' => true], 'objects');
    unset($post_types['attachment']);
    return $post_types;
}

/* La configurazione è modificabile da chiunque abbia accesso alla lista del PT */
function suite_barrio_columns_can_manage(string $pt): bool
{
    $allowed = suite_barrio_columns_allowed_post_types();
    return isset($allowed[$pt]) && current_user_can($allowed[$pt]->cap->edit_posts);
}

/* Placeholder per celle senza valore */
function suite_barrio_columns_empty(): string
{
    return '<span class="suite-barrio-cols-empty">&mdash;</span>';
}

/* Chiave colonna nativa WP per una tassonomia (rispecchia WP_Posts_List_Table) */
function suite_barrio_columns_taxonomy_key(string $taxonomy): string
{
    if ($taxonomy === 'category')
        return 'categories';
    if ($taxonomy === 'post_tag')
        return 'tags';
    return 'taxonomy-' . $taxonomy;
}

/* core:title sempre presente, in testa se mancante: preserva primary column,
   row actions e Quick Edit. Unico punto che definisce l'entry forzata. */
function suite_barrio_columns_ensure_title(array $config): array
{
    foreach ($config as $entry) {
        if (($entry['id'] ?? '') === 'core:title')
            return $config;
    }
    array_unshift($config, [
        'id' => 'core:title',
        'type' => 'core',
        'key' => 'title',
        'label' => '',
        'default_label' => 'Titolo',
    ]);
    return $config;
}

/* ─────────────────────────────────────────
   REGISTRO SORGENTI — colonne core
   Dove esiste un renderer nativo WP si riusa la chiave nativa
   (title, date, author, comments, categories/tags/taxonomy-*):
   header, row actions e Quick Edit restano quelli di WordPress.
   Le chiavi con prefisso sbc_ sono renderizzate dal modulo.
   ───────────────────────────────────────── */
function suite_barrio_columns_available_core(string $pt): array
{
    $core = [
        'core:title' => ['type' => 'core', 'key' => 'title', 'label' => 'Titolo', 'locked' => true],
        'core:id' => ['type' => 'core', 'key' => 'sbc_id', 'label' => 'ID'],
        'core:image' => ['type' => 'core', 'key' => 'sbc_image', 'label' => 'Immagine'],
        'core:author' => ['type' => 'core', 'key' => 'author', 'label' => 'Autore'],
        'core:status' => ['type' => 'core', 'key' => 'sbc_status', 'label' => 'Stato'],
        'core:slug' => ['type' => 'core', 'key' => 'sbc_slug', 'label' => 'Slug'],
        'core:comments' => ['type' => 'core', 'key' => 'comments', 'label' => 'Commenti'],
        'core:date' => ['type' => 'core', 'key' => 'date', 'label' => 'Data'],
        'core:modified' => ['type' => 'core', 'key' => 'sbc_modified', 'label' => 'Ultima modifica'],
    ];
    if (!post_type_supports($pt, 'author'))
        unset($core['core:author']);
    if (!post_type_supports($pt, 'comments'))
        unset($core['core:comments']);
    if (!post_type_supports($pt, 'thumbnail'))
        unset($core['core:image']);
    return $core;
}

/* ─────────────────────────────────────────
   REGISTRO SORGENTI — tassonomie del post type
   Chiavi native WP: categories / tags / taxonomy-{slug}
   (il renderer di WP_Posts_List_Table le gestisce da solo).
   ───────────────────────────────────────── */
function suite_barrio_columns_available_taxonomies(string $pt): array
{
    $out = [];
    foreach (get_object_taxonomies($pt, 'objects') as $tax) {
        if (!$tax->show_ui)
            continue;
        $out['tax:' . $tax->name] = [
            'type' => 'taxonomy',
            'key' => suite_barrio_columns_taxonomy_key($tax->name),
            'taxonomy' => $tax->name,
            'label' => $tax->labels->name,
        ];
    }
    return $out;
}

/* ─────────────────────────────────────────
   REGISTRO SORGENTI — campi ACF dei gruppi assegnati al post type
   ───────────────────────────────────────── */
function suite_barrio_columns_available_acf(string $pt): array
{
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields'))
        return [];

    $out = [];
    $layout_types = ['group', 'clone', 'tab', 'message', 'accordion'];
    foreach (acf_get_field_groups(['post_type' => $pt]) as $group) {
        $fields = acf_get_fields($group);
        if (empty($fields))
            continue;
        foreach ($fields as $field) {
            if (in_array($field['type'], $layout_types, true))
                continue;
            $out['acf:' . $field['key']] = [
                'type' => 'acf',
                'key' => 'sbc_acf_' . $field['key'],
                'field_key' => $field['key'],
                'name' => $field['name'],
                'field_type' => $field['type'],
                'label' => $field['label'] !== '' ? $field['label'] : $field['name'],
                'group' => $group['title'] ?? 'ACF',
            ];
        }
    }
    return $out;
}

/* Registro completo indicizzato per id (core:* / tax:* / acf:*) */
function suite_barrio_columns_registry(string $pt): array
{
    static $cache = [];
    if (!isset($cache[$pt])) {
        $cache[$pt] = array_merge(
            suite_barrio_columns_available_core($pt),
            suite_barrio_columns_available_taxonomies($pt),
            suite_barrio_columns_available_acf($pt)
        );
    }
    return $cache[$pt];
}

/* Tipi ACF ordinabili cliccando l'intestazione (meta semplice) e relativo
   cast per l'orderby: unica fonte di verità per sortable() e orderby() */
function suite_barrio_columns_acf_sort_casts(): array
{
    return [
        'text' => 'CHAR',
        'email' => 'CHAR',
        'url' => 'CHAR',
        'select' => 'CHAR',
        'date_picker' => 'CHAR',
        'date_time_picker' => 'CHAR',
        'number' => 'NUMERIC',
        'range' => 'NUMERIC',
        'true_false' => 'NUMERIC',
    ];
}

function suite_barrio_columns_acf_sortable_types(): array
{
    return array_keys(suite_barrio_columns_acf_sort_casts());
}

/* Entry di configurazione indicizzate per chiave colonna (cache per il render) */
function suite_barrio_columns_config_entry_by_key(string $pt, string $key): ?array
{
    static $cache = [];
    if (!isset($cache[$pt])) {
        $cache[$pt] = [];
        foreach (suite_barrio_columns_get_config($pt) as $entry) {
            if (!empty($entry['key']))
                $cache[$pt][$entry['key']] = $entry;
        }
    }
    return $cache[$pt][$key] ?? null;
}

/* ─────────────────────────────────────────
   INTEGRAZIONE LIST TABLE
   Registrata su init 99 così i CPT ACF sono già disponibili.
   Solo i post type con una configurazione salvata ricevono i filtri:
   senza configurazione la lista resta ai default di WordPress.
   ───────────────────────────────────────── */
add_action('init', 'suite_barrio_columns_register_hooks', 99);
function suite_barrio_columns_register_hooks(): void
{
    if (!is_admin())
        return;

    $registered = false;
    foreach (suite_barrio_columns_settings() as $pt => $cfg) {
        if (empty($cfg['columns']) || !is_array($cfg['columns']) || $pt === 'attachment' || !post_type_exists($pt))
            continue;

        add_filter("manage_{$pt}_posts_columns", function ($columns) use ($pt) {
            return suite_barrio_columns_build((array) $columns, $pt);
        });
        add_action("manage_{$pt}_posts_custom_column", function ($column, $post_id) use ($pt) {
            suite_barrio_columns_render_cell((string) $column, (int) $post_id, $pt);
        }, 10, 2);
        add_filter("manage_edit-{$pt}_sortable_columns", function ($sortable) use ($pt) {
            return suite_barrio_columns_sortable((array) $sortable, $pt);
        });
        $registered = true;
    }

    if ($registered) {
        add_action('pre_get_posts', 'suite_barrio_columns_orderby');
        // Una colonna in configurazione deve essere sempre visibile: la si
        // toglie dalle colonne nascoste di "Impostazioni schermata" (per le
        // chiavi native WP come `date`, che l'utente può aver nascosto in
        // passato e che altrimenti resterebbe invisibile pur essendo attiva).
        add_filter('hidden_columns', 'suite_barrio_columns_unhide_configured', 10, 2);
    }
}

/* Rimuove dalle colonne nascoste quelle presenti nella configurazione del PT */
function suite_barrio_columns_unhide_configured($hidden, $screen)
{
    if (!($screen instanceof WP_Screen) || empty($screen->post_type))
        return $hidden;

    $config = suite_barrio_columns_get_config($screen->post_type);
    if (empty($config))
        return $hidden;

    $configured = [];
    foreach ($config as $entry) {
        if (!empty($entry['key']))
            $configured[$entry['key']] = true;
    }
    return array_values(array_filter((array) $hidden, fn($key) => !isset($configured[$key])));
}

/* Costruisce l'array colonne nell'ordine configurato.
   Le colonne di terze parti (aggiunte da altri plugin e non gestibili
   dal modulo) vengono accodate, non eliminate. */
function suite_barrio_columns_build(array $columns, string $pt): array
{
    $config = suite_barrio_columns_get_config($pt);
    if (empty($config))
        return $columns;

    $acf_active = function_exists('get_field');
    $pt_taxonomies = get_object_taxonomies($pt);
    $config = suite_barrio_columns_ensure_title($config);

    $out = [];
    if (isset($columns['cb']))
        $out['cb'] = $columns['cb'];

    foreach ($config as $entry) {
        $key = (string) ($entry['key'] ?? '');
        if ($key === '')
            continue;
        if (($entry['type'] ?? '') === 'acf' && !$acf_active)
            continue;
        if (($entry['type'] ?? '') === 'taxonomy') {
            $tax = (string) ($entry['taxonomy'] ?? '');
            if (!taxonomy_exists($tax) || !in_array($tax, $pt_taxonomies, true))
                continue;
        }

        $label = trim((string) ($entry['label'] ?? ''));
        if ($label !== '')
            $out[$key] = esc_html($label);
        elseif (isset($columns[$key]))
            $out[$key] = $columns[$key]; // header nativo WP (es. icona commenti)
        else
            $out[$key] = esc_html((string) ($entry['default_label'] ?? $key));
    }

    // Chiavi gestite dal modulo: la loro assenza dalla config è una scelta
    // dell'utente. Tutto il resto (colonne di altri plugin) viene accodato.
    $managed = ['cb' => true, 'title' => true, 'author' => true, 'date' => true, 'comments' => true];
    foreach ($pt_taxonomies as $tax)
        $managed[suite_barrio_columns_taxonomy_key($tax)] = true;

    foreach ($columns as $key => $label) {
        if (isset($out[$key]) || isset($managed[$key]) || strpos($key, 'sbc_') === 0)
            continue;
        $out[$key] = $label;
    }
    return $out;
}

/* Render delle celle con prefisso sbc_ (le chiavi native le renderizza WP) */
function suite_barrio_columns_render_cell(string $column, int $post_id, string $pt): void
{
    if (strpos($column, 'sbc_') !== 0)
        return;

    switch ($column) {
        case 'sbc_id':
            echo $post_id;
            return;

        case 'sbc_slug':
            echo esc_html(get_post_field('post_name', $post_id));
            return;

        case 'sbc_status':
            $status = get_post_status($post_id);
            $status_obj = $status ? get_post_status_object($status) : null;
            echo esc_html($status_obj->label ?? (string) $status);
            return;

        case 'sbc_image':
            if (has_post_thumbnail($post_id))
                echo get_the_post_thumbnail($post_id, [40, 40]);
            else
                echo suite_barrio_columns_empty();
            return;

        case 'sbc_modified':
            $modified = get_post_modified_time(get_option('date_format') . ' ' . get_option('time_format'), false, $post_id, true);
            echo $modified ? esc_html($modified) : suite_barrio_columns_empty();
            return;
    }

    if (strpos($column, 'sbc_acf_') === 0) {
        $entry = suite_barrio_columns_config_entry_by_key($pt, $column);
        if ($entry)
            suite_barrio_columns_render_acf($entry, $post_id);
        else
            echo suite_barrio_columns_empty();
    }
}

/* ─────────────────────────────────────────
   RENDER CAMPI ACF
   Valore sempre raw (get_field con $format_value = false): l'output
   formattato dipende dal return_format del campo ed è imprevedibile.
   Definizione live del campo preferita, snapshot salvato come fallback.
   ───────────────────────────────────────── */
function suite_barrio_columns_render_acf(array $entry, int $post_id): void
{
    if (!function_exists('get_field')) {
        echo suite_barrio_columns_empty();
        return;
    }

    $field = acf_get_field((string) ($entry['field_key'] ?? ''));
    $name = is_array($field) && !empty($field['name']) ? $field['name'] : (string) ($entry['name'] ?? '');
    $type = is_array($field) && !empty($field['type']) ? $field['type'] : (string) ($entry['field_type'] ?? 'text');

    if ($name === '') {
        echo suite_barrio_columns_empty();
        return;
    }

    $value = get_field($name, $post_id, false);

    // true_false: sempre icona, anche su valore falso/assente
    if ($type === 'true_false') {
        echo $value
            ? '<span class="dashicons dashicons-yes-alt suite-barrio-cols-bool-on"></span>'
            : '<span class="dashicons dashicons-minus suite-barrio-cols-bool-off"></span>';
        return;
    }

    if ($value === null || $value === false || $value === '' || (is_array($value) && $value === [])) {
        echo suite_barrio_columns_empty();
        return;
    }

    switch ($type) {
        case 'image':
            $attachment_id = (int) (is_array($value) ? ($value['ID'] ?? $value['id'] ?? 0) : $value);
            $img = $attachment_id ? wp_get_attachment_image($attachment_id, [40, 40]) : '';
            echo $img !== '' ? $img : suite_barrio_columns_empty();
            return;

        case 'gallery':
            $ids = array_filter(array_map('intval', (array) $value));
            if (function_exists('_prime_post_caches'))
                _prime_post_caches(array_slice($ids, 0, 3), false, true);
            $thumbs = '';
            foreach (array_slice($ids, 0, 3) as $img_id)
                $thumbs .= wp_get_attachment_image($img_id, [28, 28]);
            $extra = count($ids) - 3;
            if ($extra > 0)
                $thumbs .= '<span class="suite-barrio-cols-more">+' . $extra . '</span>';
            echo $thumbs !== '' ? '<span class="suite-barrio-cols-gallery">' . $thumbs . '</span>' : suite_barrio_columns_empty();
            return;

        case 'file':
            $attachment_id = (int) (is_array($value) ? ($value['ID'] ?? $value['id'] ?? 0) : $value);
            $url = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
            if ($url)
                echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html(wp_basename($url)) . '</a>';
            else
                echo suite_barrio_columns_empty();
            return;

        case 'select':
        case 'radio':
        case 'button_group':
        case 'checkbox':
            $choices = is_array($field) && !empty($field['choices']) && is_array($field['choices']) ? $field['choices'] : [];
            $values = is_array($value) ? $value : [$value];
            $labels = [];
            foreach ($values as $v) {
                $v = is_scalar($v) ? (string) $v : '';
                if ($v === '')
                    continue;
                $labels[] = isset($choices[$v]) ? (string) $choices[$v] : $v;
            }
            echo $labels ? esc_html(implode(', ', $labels)) : suite_barrio_columns_empty();
            return;

        case 'post_object':
        case 'relationship':
        case 'page_link':
            $ids = array_filter(array_map('intval', is_array($value) ? $value : [$value]));
            if (!$ids) {
                // page_link può contenere anche URL di archivio (stringa non numerica)
                echo esc_html(is_scalar($value) ? (string) $value : '');
                return;
            }
            if (function_exists('_prime_post_caches'))
                _prime_post_caches(array_slice($ids, 0, 3), false, true);
            $links = [];
            foreach (array_slice($ids, 0, 3) as $rel_id) {
                $title = get_the_title($rel_id);
                $edit = get_edit_post_link($rel_id);
                if ($title === '')
                    continue;
                $links[] = $edit
                    ? '<a href="' . esc_url($edit) . '">' . esc_html($title) . '</a>'
                    : esc_html($title);
            }
            $extra = count($ids) - 3;
            if ($extra > 0)
                $links[] = '<span class="suite-barrio-cols-more">+' . $extra . '</span>';
            echo $links ? implode(', ', $links) : suite_barrio_columns_empty();
            return;

        case 'taxonomy':
            $names = [];
            foreach (array_filter(array_map('intval', is_array($value) ? $value : [$value])) as $term_id) {
                $term = get_term($term_id);
                if ($term instanceof WP_Term)
                    $names[] = $term->name;
            }
            echo $names ? esc_html(implode(', ', $names)) : suite_barrio_columns_empty();
            return;

        case 'user':
            $names = [];
            foreach (array_filter(array_map('intval', is_array($value) ? $value : [$value])) as $user_id) {
                $user = get_userdata($user_id);
                if ($user)
                    $names[] = $user->display_name;
            }
            echo $names ? esc_html(implode(', ', $names)) : suite_barrio_columns_empty();
            return;

        case 'date_picker':
            // Salvato raw come Ymd (es. 20260717)
            $raw = (string) $value;
            if (preg_match('/^\d{8}$/', $raw)) {
                $dt = DateTime::createFromFormat('Ymd', $raw);
                if ($dt) {
                    echo esc_html(date_i18n(get_option('date_format'), $dt->getTimestamp()));
                    return;
                }
            }
            echo esc_html($raw);
            return;

        case 'date_time_picker':
            $ts = strtotime((string) $value);
            echo $ts
                ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts))
                : esc_html((string) $value);
            return;

        case 'url':
        case 'email':
            $raw = (string) $value;
            $href = $type === 'email' ? 'mailto:' . $raw : $raw;
            echo '<a href="' . esc_url($href) . '" target="_blank" rel="noopener">' . esc_html(mb_strimwidth($raw, 0, 40, '…')) . '</a>';
            return;

        case 'link':
            $url = is_array($value) ? (string) ($value['url'] ?? '') : (string) $value;
            $title = is_array($value) && !empty($value['title']) ? (string) $value['title'] : $url;
            if ($url === '') {
                echo suite_barrio_columns_empty();
                return;
            }
            echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html(mb_strimwidth($title, 0, 40, '…')) . '</a>';
            return;

        case 'color_picker':
            $hex = (string) $value;
            echo '<span class="suite-barrio-cols-swatch" style="background:' . esc_attr($hex) . '"></span> ' . esc_html($hex);
            return;

        case 'number':
        case 'range':
            echo esc_html((string) $value);
            return;

        case 'repeater':
            $count = is_array($value) ? count($value) : (int) $value;
            echo esc_html($count . ($count === 1 ? ' riga' : ' righe'));
            return;

        case 'flexible_content':
            $count = is_array($value) ? count($value) : (int) $value;
            echo esc_html($count . ($count === 1 ? ' blocco' : ' blocchi'));
            return;

        default:
            // text, textarea, wysiwyg, oembed e tipi non gestiti esplicitamente
            $text = is_array($value) ? implode(', ', array_map('strval', array_filter($value, 'is_scalar'))) : (string) $value;
            $text = wp_strip_all_tags($text);
            echo $text !== '' ? esc_html(mb_strimwidth($text, 0, 80, '…')) : suite_barrio_columns_empty();
            return;
    }
}

/* ─────────────────────────────────────────
   SORTABLE — intestazioni cliccabili
   Core: ID / slug / ultima modifica (orderby nativi WP_Query).
   ACF: solo meta semplice, gestita in pre_get_posts.
   ───────────────────────────────────────── */
function suite_barrio_columns_sortable(array $sortable, string $pt): array
{
    foreach (suite_barrio_columns_get_config($pt) as $entry) {
        $key = (string) ($entry['key'] ?? '');
        if ($key === '')
            continue;

        switch ($entry['id'] ?? '') {
            case 'core:id':
                $sortable[$key] = 'ID';
                continue 2;
            case 'core:slug':
                $sortable[$key] = 'name';
                continue 2;
            case 'core:modified':
                $sortable[$key] = 'modified';
                continue 2;
        }

        if (
            ($entry['type'] ?? '') === 'acf'
            && function_exists('get_field')
            && in_array($entry['field_type'] ?? '', suite_barrio_columns_acf_sortable_types(), true)
        ) {
            $sortable[$key] = $key; // risolto in suite_barrio_columns_orderby()
        }
    }
    return $sortable;
}

function suite_barrio_columns_orderby(WP_Query $query): void
{
    if (!is_admin() || !$query->is_main_query())
        return;

    $orderby = $query->get('orderby');
    if (!is_string($orderby) || strpos($orderby, 'sbc_acf_') !== 0)
        return;

    $pt = $query->get('post_type');
    if (is_array($pt))
        $pt = (string) reset($pt);
    if (!$pt)
        $pt = 'post';

    $entry = suite_barrio_columns_config_entry_by_key((string) $pt, $orderby);
    if (!$entry || ($entry['type'] ?? '') !== 'acf')
        return;

    $casts = suite_barrio_columns_acf_sort_casts();
    $field_type = (string) ($entry['field_type'] ?? '');
    if (!isset($casts[$field_type]))
        return;

    $field = function_exists('acf_get_field') ? acf_get_field((string) ($entry['field_key'] ?? '')) : false;
    $meta_name = is_array($field) && !empty($field['name']) ? $field['name'] : (string) ($entry['name'] ?? '');
    if ($meta_name === '')
        return;

    // OR EXISTS/NOT EXISTS: i post senza meta restano in lista
    $exists = ['key' => $meta_name, 'compare' => 'EXISTS'];
    if ($casts[$field_type] === 'NUMERIC')
        $exists['type'] = 'NUMERIC';

    $sort_clause = [
        'relation' => 'OR',
        'sbc_exists' => $exists,
        ['key' => $meta_name, 'compare' => 'NOT EXISTS'],
    ];

    // Non sovrascrivere eventuali meta_query di filtri già attivi sulla lista
    $existing = $query->get('meta_query');
    if (is_array($existing) && $existing !== [])
        $sort_clause = ['relation' => 'AND', $existing, $sort_clause];

    $query->set('meta_query', $sort_clause);
    $query->set('orderby', 'sbc_exists');
}

/* ─────────────────────────────────────────
   DEFAULT — approssimazione delle colonne standard WP
   usata come prefill della modale quando non c'è configurazione
   ───────────────────────────────────────── */
function suite_barrio_columns_default_config(string $pt): array
{
    $config = [['id' => 'core:title']];
    if (post_type_supports($pt, 'author'))
        $config[] = ['id' => 'core:author'];
    foreach (get_object_taxonomies($pt, 'objects') as $tax) {
        if ($tax->show_ui && $tax->show_admin_column)
            $config[] = ['id' => 'tax:' . $tax->name];
    }
    if (post_type_supports($pt, 'comments'))
        $config[] = ['id' => 'core:comments'];
    $config[] = ['id' => 'core:date'];
    return $config;
}

/* ─────────────────────────────────────────
   AJAX
   ───────────────────────────────────────── */
function suite_barrio_columns_validate_request(): string
{
    $pt = sanitize_key($_POST['post_type'] ?? '');
    if ($pt === '' || !suite_barrio_columns_can_manage($pt))
        wp_send_json_error('Permesso negato.');
    return $pt;
}

/* Colonne disponibili + configurazione attiva per la modale */
add_action('wp_ajax_suite_barrio_columns_get', function () {
    check_ajax_referer('suite_barrio_columns_nonce', 'nonce');
    $pt = suite_barrio_columns_validate_request();

    $registry = suite_barrio_columns_registry($pt);
    $config = suite_barrio_columns_get_config($pt);
    $has_config = !empty($config);
    if (!$has_config)
        $config = suite_barrio_columns_default_config($pt);

    $active = [];
    foreach ($config as $entry) {
        $id = (string) ($entry['id'] ?? '');
        if (!isset($registry[$id]))
            continue; // sorgente non più disponibile (campo ACF eliminato, tassonomia rimossa…)
        $active[] = [
            'id' => $id,
            'label' => (string) ($entry['label'] ?? ''),
            'default_label' => $registry[$id]['label'],
            'locked' => !empty($registry[$id]['locked']),
        ];
    }

    $groups = ['Info post' => [], 'Tassonomie' => []];
    foreach ($registry as $id => $def) {
        $item = ['id' => $id, 'label' => $def['label']];
        if ($def['type'] === 'core') {
            $groups['Info post'][] = $item;
        } elseif ($def['type'] === 'taxonomy') {
            $groups['Tassonomie'][] = $item;
        } else {
            $item['field_type'] = $def['field_type'];
            $groups['ACF — ' . $def['group']][] = $item;
        }
    }
    $available = [];
    foreach ($groups as $group_label => $items) {
        if (!empty($items))
            $available[] = ['group' => $group_label, 'items' => $items];
    }

    wp_send_json_success([
        'post_type' => $pt,
        'has_config' => $has_config,
        'active' => $active,
        'available' => $available,
    ]);
});

/* Salva la configurazione: il client invia solo id + label ordinati,
   tutti i dati canonici vengono ripresi dal registro lato server */
add_action('wp_ajax_suite_barrio_columns_save', function () {
    check_ajax_referer('suite_barrio_columns_nonce', 'nonce');
    $pt = suite_barrio_columns_validate_request();

    $raw_json = $_POST['columns'] ?? '';
    if (!is_string($raw_json))
        wp_send_json_error('Payload non valido.');
    $raw = json_decode(wp_unslash($raw_json), true);
    if (!is_array($raw) || $raw === [])
        wp_send_json_error('Nessuna colonna ricevuta.');
    if (count($raw) > 30)
        wp_send_json_error('Massimo 30 colonne per lista.');

    $columns = suite_barrio_columns_sanitize_payload($raw, $pt);
    if (empty($columns))
        wp_send_json_error('Nessuna colonna valida.');

    $settings = suite_barrio_columns_settings();
    $settings[$pt] = ['columns' => $columns];
    update_option(SUITE_BARRIO_COLUMNS_OPTION, $settings, false);

    wp_send_json_success(['message' => 'Colonne salvate.']);
});

/* Ripristina i default WordPress per il post type */
add_action('wp_ajax_suite_barrio_columns_reset', function () {
    check_ajax_referer('suite_barrio_columns_nonce', 'nonce');
    $pt = suite_barrio_columns_validate_request();

    $settings = suite_barrio_columns_settings();
    unset($settings[$pt]);
    update_option(SUITE_BARRIO_COLUMNS_OPTION, $settings, false);

    wp_send_json_success(['message' => 'Configurazione ripristinata.']);
});

function suite_barrio_columns_sanitize_payload(array $raw, string $pt): array
{
    $registry = suite_barrio_columns_registry($pt);
    $columns = [];
    $seen = [];

    foreach ($raw as $item) {
        if (!is_array($item))
            continue;
        $id = (string) ($item['id'] ?? '');
        if (!isset($registry[$id]) || isset($seen[$id]))
            continue; // id sconosciuti o duplicati: scartati
        $seen[$id] = true;

        $def = $registry[$id];
        $entry = [
            'id' => $id,
            'type' => $def['type'],
            'key' => $def['key'],
            'label' => mb_substr(sanitize_text_field((string) ($item['label'] ?? '')), 0, 60),
            'default_label' => $def['label'],
        ];
        if ($def['type'] === 'taxonomy')
            $entry['taxonomy'] = $def['taxonomy'];
        if ($def['type'] === 'acf') {
            $entry['field_key'] = $def['field_key'];
            $entry['name'] = $def['name'];
            $entry['field_type'] = $def['field_type'];
        }
        $columns[] = $entry;
    }

    return suite_barrio_columns_ensure_title($columns);
}

/* ─────────────────────────────────────────
   ENQUEUE — JS ingranaggio + modale, solo su edit.php
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'edit.php')
        return;
    $pt = (string) ($GLOBALS['typenow'] ?? '');
    if ($pt === '' || !suite_barrio_columns_can_manage($pt))
        return;

    wp_enqueue_script('jquery-ui-sortable');

    wp_register_script('suite-barrio-columns-admin', '', ['jquery-ui-sortable'], SUITE_BARRIO_COLUMNS_VERSION, true);
    wp_enqueue_script('suite-barrio-columns-admin');

    $js = str_replace(
        ['SUITE_BARRIO_COLS_NONCE', 'SUITE_BARRIO_COLS_AJAX_URL', 'SUITE_BARRIO_COLS_POST_TYPE'],
        [
            wp_json_encode(wp_create_nonce('suite_barrio_columns_nonce')),
            wp_json_encode(admin_url('admin-ajax.php')),
            wp_json_encode($pt),
        ],
        suite_barrio_columns_js()
    );
    wp_add_inline_script('suite-barrio-columns-admin', $js);
});

/* ─────────────────────────────────────────
   MODALE — skeleton stampato nel footer di edit.php,
   contenuto popolato via AJAX al primo click sull'ingranaggio.
   Hook 'admin_footer' (non 'admin_footer-edit.php'): quest'ultimo
   viene eseguito DOPO admin_print_footer_scripts, quando l'inline
   script è già girato e non troverebbe la modale nel DOM.
   ───────────────────────────────────────── */
add_action('admin_footer', 'suite_barrio_columns_modal_html');
function suite_barrio_columns_modal_html(): void
{
    if (($GLOBALS['hook_suffix'] ?? '') !== 'edit.php')
        return;
    $pt = (string) ($GLOBALS['typenow'] ?? '');
    if ($pt === '' || !suite_barrio_columns_can_manage($pt))
        return;
    $allowed = suite_barrio_columns_allowed_post_types();
    $pt_label = $allowed[$pt]->labels->name;
    ?>
    <div class="suite-barrio-cols-modal-overlay" id="suite-barrio-cols-modal">
        <div class="suite-barrio-cols-modal">
            <button type="button" class="suite-barrio-cols-modal-close" id="suite-barrio-cols-modal-close">&#x2715;</button>
            <h2>Colonne — <?php echo esc_html($pt_label); ?></h2>
            <div class="suite-barrio-cols-error" id="suite-barrio-cols-error"></div>
            <div class="suite-barrio-cols-body">
                <div class="suite-barrio-cols-panel suite-barrio-cols-panel-available">
                    <div class="suite-barrio-cols-panel-title">Colonne disponibili</div>
                    <input type="search" id="suite-barrio-cols-search" placeholder="Cerca colonna…">
                    <div class="suite-barrio-cols-available" id="suite-barrio-cols-available">
                        <div class="suite-barrio-cols-loading">Caricamento…</div>
                    </div>
                </div>
                <div class="suite-barrio-cols-panel suite-barrio-cols-panel-active">
                    <div class="suite-barrio-cols-panel-title">Colonne attive <span class="suite-barrio-cols-hint">trascina per riordinare</span></div>
                    <ul id="suite-barrio-cols-active"></ul>
                </div>
            </div>
            <div class="suite-barrio-cols-footer">
                <button type="button" class="suite-barrio-btn suite-barrio-btn-ghost" id="suite-barrio-cols-reset-btn" style="display:none;">Ripristina default WordPress</button>
                <span class="suite-barrio-cols-footer-spacer"></span>
                <button type="button" class="suite-barrio-btn suite-barrio-btn-ghost" id="suite-barrio-cols-cancel-btn">Annulla</button>
                <button type="button" class="suite-barrio-btn suite-barrio-btn-primary" id="suite-barrio-cols-save-btn">
                    <span class="dashicons dashicons-yes"></span>
                    Salva colonne
                </button>
            </div>
        </div>
    </div>
    <?php
}

/* ─────────────────────────────────────────
   JS — ingranaggio, modale, drag & drop e salvataggio.
   Token SUITE_BARRIO_COLS_* sostituiti in fase di enqueue.
   ───────────────────────────────────────── */
function suite_barrio_columns_js(): string
{
    return <<<'JS'
(function () {
    var nonce = SUITE_BARRIO_COLS_NONCE;
    var ajaxUrl = SUITE_BARRIO_COLS_AJAX_URL;
    var postType = SUITE_BARRIO_COLS_POST_TYPE;

    var modal = document.getElementById('suite-barrio-cols-modal');
    if (!modal) return;
    var errorBox = document.getElementById('suite-barrio-cols-error');
    var availBox = document.getElementById('suite-barrio-cols-available');
    var activeList = document.getElementById('suite-barrio-cols-active');
    var searchInput = document.getElementById('suite-barrio-cols-search');
    var saveBtn = document.getElementById('suite-barrio-cols-save-btn');
    var resetBtn = document.getElementById('suite-barrio-cols-reset-btn');
    var loaded = false;

    /* ── Ingranaggio accanto al titolo della lista ── */
    var heading = document.querySelector('.wrap .wp-heading-inline');
    var actions = document.querySelectorAll('.wrap .page-title-action');
    var anchor = actions.length ? actions[actions.length - 1] : heading;
    if (!anchor) return;
    var gear = document.createElement('button');
    gear.type = 'button';
    gear.id = 'suite-barrio-cols-gear';
    gear.className = 'page-title-action suite-barrio-cols-gear-btn';
    gear.innerHTML = '<span class="dashicons dashicons-admin-generic"></span> Colonne';
    anchor.insertAdjacentElement('afterend', gear);

    /* ── Apertura/chiusura modale ── */
    gear.addEventListener('click', function () {
        errorBox.style.display = 'none';
        modal.classList.add('open');
        if (!loaded) load();
    });
    function closeModal() { modal.classList.remove('open'); }
    document.getElementById('suite-barrio-cols-modal-close').addEventListener('click', closeModal);
    document.getElementById('suite-barrio-cols-cancel-btn').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

    /* ── Caricamento colonne disponibili + configurazione attiva ── */
    function load() {
        fetch(ajaxUrl, {
            method: 'POST',
            body: new URLSearchParams({ action: 'suite_barrio_columns_get', nonce: nonce, post_type: postType })
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { showError(res.data || 'Errore sconosciuto.'); return; }
                loaded = true;
                resetBtn.style.display = res.data.has_config ? '' : 'none';
                renderAvailable(res.data.available);
                activeList.innerHTML = '';
                res.data.active.forEach(addActive);
                refreshAvailable();
                jQuery(activeList).sortable({
                    handle: '.suite-barrio-cols-item-handle',
                    placeholder: 'suite-barrio-cols-placeholder',
                    axis: 'y'
                });
            })
            .catch(function () { showError('Errore di rete.'); });
    }

    function renderAvailable(groups) {
        var html = '';
        groups.forEach(function (group) {
            html += '<div class="suite-barrio-cols-group"><div class="suite-barrio-cols-group-title">' + esc(group.group) + '</div>';
            group.items.forEach(function (item) {
                html += '<div class="suite-barrio-cols-avail-item" data-id="' + esc(item.id) + '" data-label="' + esc(item.label) + '">' +
                    '<span class="suite-barrio-cols-avail-label">' + esc(item.label) + '</span>' +
                    (item.field_type ? '<span class="suite-barrio-cols-badge">' + esc(item.field_type) + '</span>' : '') +
                    '<button type="button" class="suite-barrio-cols-add-btn" title="Aggiungi">+</button>' +
                    '</div>';
            });
            html += '</div>';
        });
        availBox.innerHTML = html || '<div class="suite-barrio-cols-loading">Nessuna colonna disponibile.</div>';
    }

    function addActive(item) {
        var li = document.createElement('li');
        li.className = 'suite-barrio-cols-item' + (item.locked ? ' suite-barrio-cols-item-locked' : '');
        li.dataset.id = item.id;
        li.innerHTML =
            '<span class="suite-barrio-cols-item-handle dashicons dashicons-menu"></span>' +
            '<span class="suite-barrio-cols-item-label">' + esc(item.default_label) + '</span>' +
            '<input type="text" class="suite-barrio-cols-item-rename" placeholder="' + esc(item.default_label) + '" value="' + esc(item.label || '') + '">' +
            (item.locked ? '' : '<button type="button" class="suite-barrio-cols-item-remove" title="Rimuovi">&#x2715;</button>');
        activeList.appendChild(li);
    }

    function refreshAvailable() {
        var ids = {};
        activeList.querySelectorAll('li').forEach(function (li) { ids[li.dataset.id] = true; });
        availBox.querySelectorAll('.suite-barrio-cols-avail-item').forEach(function (el) {
            el.classList.toggle('suite-barrio-cols-avail-item-active', !!ids[el.dataset.id]);
        });
    }

    /* ── Aggiungi / rimuovi colonne ── */
    availBox.addEventListener('click', function (e) {
        var btn = e.target.closest('.suite-barrio-cols-add-btn');
        if (!btn) return;
        var item = btn.closest('.suite-barrio-cols-avail-item');
        if (item.classList.contains('suite-barrio-cols-avail-item-active')) return;
        addActive({ id: item.dataset.id, label: '', default_label: item.dataset.label, locked: false });
        refreshAvailable();
    });
    activeList.addEventListener('click', function (e) {
        var btn = e.target.closest('.suite-barrio-cols-item-remove');
        if (!btn) return;
        btn.closest('li').remove();
        refreshAvailable();
    });

    /* ── Filtro di ricerca sulle disponibili ── */
    searchInput.addEventListener('input', function () {
        var q = this.value.toLowerCase();
        availBox.querySelectorAll('.suite-barrio-cols-avail-item').forEach(function (el) {
            el.style.display = !q || el.dataset.label.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
        });
    });

    /* ── Salvataggio ── */
    saveBtn.addEventListener('click', function () {
        var state = [];
        activeList.querySelectorAll('li').forEach(function (li) {
            state.push({ id: li.dataset.id, label: li.querySelector('.suite-barrio-cols-item-rename').value.trim() });
        });
        if (!state.length) { showError('Aggiungi almeno una colonna.'); return; }

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="suite-barrio-spinner"></span> Salvataggio…';

        fetch(ajaxUrl, {
            method: 'POST',
            body: new URLSearchParams({ action: 'suite_barrio_columns_save', nonce: nonce, post_type: postType, columns: JSON.stringify(state) })
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) { window.location.reload(); }
                else { showError(res.data || 'Errore sconosciuto.'); restoreSaveBtn(); }
            })
            .catch(function () { showError('Errore di rete.'); restoreSaveBtn(); });
    });

    function restoreSaveBtn() {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<span class="dashicons dashicons-yes"></span> Salva colonne';
    }

    /* ── Ripristino default WordPress ── */
    resetBtn.addEventListener('click', function () {
        if (!confirm('Ripristinare le colonne di default di WordPress per questa lista?')) return;
        fetch(ajaxUrl, {
            method: 'POST',
            body: new URLSearchParams({ action: 'suite_barrio_columns_reset', nonce: nonce, post_type: postType })
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) { window.location.reload(); }
                else { showError(res.data || 'Errore sconosciuto.'); }
            })
            .catch(function () { showError('Errore di rete.'); });
    });

    function showError(msg) { errorBox.textContent = msg; errorBox.style.display = 'block'; }
    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
})();
JS;
}
