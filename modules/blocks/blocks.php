<?php
/**
 * Suite Barrio — Blocks
 * Gestisce quali blocchi Gutenberg sono disponibili per gli utenti non amministratori.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('SUITE_BARRIO_BLOCKS_OPTION', 'suite_barrio_blocks_settings');

/* ─────────────────────────────────────────
   HELPERS
   ───────────────────────────────────────── */
function suite_barrio_blocks_get_settings(): array
{
    $defaults = [
        'enabled' => true,
        'allowed' => ['core/paragraph', 'core/list', 'core/heading', 'core/separator', 'core/embed'],
        // gutenberg_post_types è assente di default: null = mai configurato = tutti abilitati
    ];
    $s = get_option(SUITE_BARRIO_BLOCKS_OPTION, false);
    if ($s === false || !is_array($s)) return $defaults;
    return array_merge($defaults, $s);
}

function suite_barrio_blocks_is_enabled(): bool
{
    return !empty(suite_barrio_blocks_get_settings()['enabled']);
}

function suite_barrio_blocks_get_allowed(): array
{
    $s = suite_barrio_blocks_get_settings();
    return isset($s['allowed']) && is_array($s['allowed']) ? $s['allowed'] : [];
}

// Restituisce null se non ancora configurato (= tutti abilitati), array altrimenti.
function suite_barrio_blocks_gutenberg_post_types(): ?array
{
    $s = suite_barrio_blocks_get_settings();
    if (!array_key_exists('gutenberg_post_types', $s)) return null;
    return is_array($s['gutenberg_post_types']) ? $s['gutenberg_post_types'] : [];
}

/* ─────────────────────────────────────────
   RIMOZIONE EDITOR PER POST TYPE non selezionati
   ───────────────────────────────────────── */
add_action('init', function () {
    $enabled = suite_barrio_blocks_gutenberg_post_types();
    if ($enabled === null) return; // mai configurato: nessuna restrizione
    foreach (get_post_types(['public' => true]) as $pt) {
        if ($pt === 'attachment') continue;
        if (!in_array($pt, $enabled, true)) {
            remove_post_type_support($pt, 'editor');
        }
    }
}, 20);

/* ─────────────────────────────────────────
   FILTRO EDITOR — limita blocchi per non-admin
   ───────────────────────────────────────── */
add_filter('allowed_block_types_all', function ($allowed, $context) {
    if (!suite_barrio_blocks_is_enabled())
        return $allowed;
    if (current_user_can('manage_options'))
        return $allowed;
    $list = suite_barrio_blocks_get_allowed();
    return !empty($list) ? $list : $allowed;
}, 10, 2);

/* ─────────────────────────────────────────
   RESTRIZIONE H1 — editor JS + safety server-side
   ───────────────────────────────────────── */

// Server-side: converte <h1> → <h2> nel render per gli utenti non-admin
add_filter('render_block', function (string $html, array $block): string {
    if (!suite_barrio_blocks_is_enabled()) return $html;
    if (current_user_can('manage_options')) return $html;
    if (($block['blockName'] ?? '') !== 'core/heading') return $html;
    if ((int)($block['attrs']['level'] ?? 2) !== 1) return $html;
    return preg_replace('/<(\/?)h1(\b[^>]*)>/i', '<$1h2$2>', $html);
}, 10, 2);

// Editor JS: nasconde H1, rimuove pannelli sidebar per non-admin
add_action('enqueue_block_editor_assets', function () {
    if (!suite_barrio_blocks_is_enabled()) return;
    if (current_user_can('manage_options')) return;
    wp_enqueue_script(
        'suite-barrio-blocks-no-h1',
        SUITE_BARRIO_PLUGIN_URL . 'modules/blocks/editor.js',
        ['wp-hooks', 'wp-compose', 'wp-element', 'wp-data', 'wp-dom-ready'],
        SUITE_BARRIO_VERSION,
        false
    );
});

/* ─────────────────────────────────────────
   NASCONDI REVISIONI / AUTORE / ATTRIBUTI PAGINA per non-admin
   ───────────────────────────────────────── */
add_action('admin_init', function () {
    if (current_user_can('manage_options')) return;

    // Classic Editor: rimuovi le meta box (priority 99 = dopo tutti i plugin)
    add_action('add_meta_boxes', function () {
        foreach (get_post_types() as $pt) {
            remove_meta_box('revisionsdiv',  $pt, 'normal'); // Revisioni
            remove_meta_box('authordiv',     $pt, 'normal'); // Autore
            remove_meta_box('pageparentdiv', $pt, 'side');   // Attributi della pagina
        }
    }, 99);

    // Block Editor: rimuovere il support fa sparire i pannelli Author e Page Attributes
    // (il pannello Revisioni viene rimosso lato JS in editor.js)
    foreach (get_post_types(['public' => true]) as $pt) {
        remove_post_type_support($pt, 'author');
        remove_post_type_support($pt, 'page-attributes');
    }
});

/* ─────────────────────────────────────────
   MENU
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    add_submenu_page(
        'suite-barrio',
        'Blocks — Suite Barrio',
        'Blocks',
        'manage_options',
        'suite-barrio-blocks',
        'suite_barrio_blocks_admin_page'
    );
});

/* ─────────────────────────────────────────
   ENQUEUE — solo nella pagina blocks
   ───────────────────────────────────────── */
function suite_barrio_blocks_admin_js(): string
{
    return '(function () {
    var enableToggle = document.getElementById(\'suite_barrio_blocks_enabled\');
    var listWrap     = document.getElementById(\'suite-barrio-blocks-list\');
    var searchInput  = document.getElementById(\'suite-barrio-blocks-search\');
    var countEl      = document.getElementById(\'suite-barrio-blocks-count\');

    // Abilita/disabilita lista blocchi al cambio del toggle principale
    enableToggle.addEventListener(\'change\', function () {
        listWrap.style.opacity       = this.checked ? \'1\'    : \'.4\';
        listWrap.style.pointerEvents = this.checked ? \'auto\' : \'none\';
    });

    // Aggiorna contatore selezionati
    function updateCount() {
        var total   = document.querySelectorAll(\'.suite-barrio-blocks-item input[type=checkbox]\').length;
        var checked = document.querySelectorAll(\'.suite-barrio-blocks-item input[type=checkbox]:checked\').length;
        if (countEl) countEl.textContent = checked + \' / \' + total + \' selezionati\';
    }

    // Aggiorna contatore per gruppo
    function updateGroupCount(group) {
        var total   = group.querySelectorAll(\'.suite-barrio-blocks-item input\').length;
        var checked = group.querySelectorAll(\'.suite-barrio-blocks-item input:checked\').length;
        var selEl   = group.querySelector(\'.suite-barrio-blocks-group-sel\');
        if (selEl) selEl.textContent = checked;
    }

    // Stile riga al cambio checkbox
    document.querySelectorAll(\'.suite-barrio-blocks-item input[type=checkbox]\').forEach(function (cb) {
        cb.addEventListener(\'change\', function () {
            var item  = cb.closest(\'.suite-barrio-blocks-item\');
            var group = cb.closest(\'.suite-barrio-blocks-group\');
            if (item)  item.classList.toggle(\'is-checked\', cb.checked);
            if (group) updateGroupCount(group);
            updateCount();
        });
    });

    // Collapse/expand gruppo
    document.querySelectorAll(\'.suite-barrio-blocks-group-toggle\').forEach(function (btn) {
        btn.addEventListener(\'click\', function () {
            var group  = btn.closest(\'.suite-barrio-blocks-group\');
            var body   = group.querySelector(\'.suite-barrio-blocks-group-body\');
            var chev   = group.querySelector(\'.suite-barrio-blocks-group-chevron\');
            var hidden = body.style.display === \'none\';
            body.style.display = hidden ? \'\' : \'none\';
            chev.classList.toggle(\'suite-barrio-blocks-group-chevron-up\', hidden);
        });
    });

    // Seleziona/deseleziona tutto nel gruppo
    document.querySelectorAll(\'.suite-barrio-blocks-group-all\').forEach(function (btn) {
        btn.addEventListener(\'click\', function () {
            var group = btn.closest(\'.suite-barrio-blocks-group\');
            group.querySelectorAll(\'.suite-barrio-blocks-item input\').forEach(function (cb) {
                cb.checked = true;
                cb.closest(\'.suite-barrio-blocks-item\').classList.add(\'is-checked\');
            });
            updateGroupCount(group);
            updateCount();
        });
    });
    document.querySelectorAll(\'.suite-barrio-blocks-group-none\').forEach(function (btn) {
        btn.addEventListener(\'click\', function () {
            var group = btn.closest(\'.suite-barrio-blocks-group\');
            group.querySelectorAll(\'.suite-barrio-blocks-item input\').forEach(function (cb) {
                cb.checked = false;
                cb.closest(\'.suite-barrio-blocks-item\').classList.remove(\'is-checked\');
            });
            updateGroupCount(group);
            updateCount();
        });
    });

    // Seleziona/deseleziona tutti globale
    document.getElementById(\'suite-barrio-blocks-select-all\').addEventListener(\'click\', function () {
        document.querySelectorAll(\'.suite-barrio-blocks-item:not(.suite-barrio-blocks-item-hidden) input\').forEach(function (cb) {
            cb.checked = true;
            cb.closest(\'.suite-barrio-blocks-item\').classList.add(\'is-checked\');
        });
        document.querySelectorAll(\'.suite-barrio-blocks-group\').forEach(updateGroupCount);
        updateCount();
    });
    document.getElementById(\'suite-barrio-blocks-deselect-all\').addEventListener(\'click\', function () {
        document.querySelectorAll(\'.suite-barrio-blocks-item:not(.suite-barrio-blocks-item-hidden) input\').forEach(function (cb) {
            cb.checked = false;
            cb.closest(\'.suite-barrio-blocks-item\').classList.remove(\'is-checked\');
        });
        document.querySelectorAll(\'.suite-barrio-blocks-group\').forEach(updateGroupCount);
        updateCount();
    });

    // Ricerca
    searchInput.addEventListener(\'input\', function () {
        var q = this.value.trim().toLowerCase();
        document.querySelectorAll(\'.suite-barrio-blocks-group\').forEach(function (group) {
            var visible = 0;
            group.querySelectorAll(\'.suite-barrio-blocks-item\').forEach(function (item) {
                var match = !q
                    || item.dataset.label.includes(q)
                    || item.dataset.name.includes(q);
                item.classList.toggle(\'suite-barrio-blocks-item-hidden\', !match);
                if (match) visible++;
            });
            group.classList.toggle(\'suite-barrio-blocks-group-hidden\', visible === 0);
        });
    });

    updateCount();
})();';
}

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'suite-barrio-blocks') === false)
        return;

    wp_register_script('suite-barrio-blocks-admin', '', [], SUITE_BARRIO_VERSION, true);
    wp_enqueue_script('suite-barrio-blocks-admin');
    wp_add_inline_script('suite-barrio-blocks-admin', suite_barrio_blocks_admin_js());
});

/* ─────────────────────────────────────────
   SALVATAGGIO
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_blocks_save', function () {
    check_admin_referer('suite_barrio_blocks_save');

    $enabled = isset($_POST['suite_barrio_blocks_enabled']) ? true : false;

    $allowed = [];
    if (isset($_POST['allowed_blocks']) && is_array($_POST['allowed_blocks'])) {
        $raw_allowed_blocks = array_map('sanitize_text_field', wp_unslash($_POST['allowed_blocks']));
        foreach ($raw_allowed_blocks as $name) {
            if (preg_match('/^[a-z0-9\-]+\/[a-z0-9\-]+$/', $name)) {
                $allowed[] = $name;
            }
        }
    }

    $gutenberg_post_types = [];
    if (isset($_POST['gutenberg_post_types']) && is_array($_POST['gutenberg_post_types'])) {
        $raw_gutenberg_post_types = array_map('sanitize_key', wp_unslash($_POST['gutenberg_post_types']));
        foreach ($raw_gutenberg_post_types as $pt) {
            if ($pt) $gutenberg_post_types[] = $pt;
        }
    }

    update_option(SUITE_BARRIO_BLOCKS_OPTION, [
        'enabled'              => $enabled,
        'allowed'              => $allowed,
        'gutenberg_post_types' => $gutenberg_post_types,
    ]);

    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-blocks&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function suite_barrio_blocks_admin_page()
{
    $settings             = suite_barrio_blocks_get_settings();
    $enabled              = !empty($settings['enabled']);
    $allowed              = isset($settings['allowed']) && is_array($settings['allowed']) ? $settings['allowed'] : [];
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $saved                = isset($_GET['saved']);

    // null = mai configurato = tutti attivi; array = quelli selezionati
    $gutenberg_post_types = suite_barrio_blocks_gutenberg_post_types();

    // Tutti i post type pubblici tranne attachment
    $all_post_types_for_gutenberg = array_filter(
        get_post_types(['public' => true], 'objects'),
        function ($pt) { return $pt->name !== 'attachment'; }
    );

    // Tutti i blocchi registrati, raggruppati per namespace.
    $registry   = WP_Block_Type_Registry::get_instance();
    $all_blocks = $registry->get_all_registered();

    $groups = [];
    foreach ($all_blocks as $name => $block) {
        $ns = strstr($name, '/', true) ?: 'other';
        $groups[$ns][$name] = $block;
    }
    ksort($groups);
    foreach ($groups as $ns => &$blocks) {
        ksort($blocks);
    }
    unset($blocks);

    $ns_labels = [
        'core'           => 'WordPress Core',
        'core-embed'     => 'Core — Embed',
        'acf'            => 'Advanced Custom Fields',
        'gravityforms'   => 'Gravity Forms',
        'woocommerce'    => 'WooCommerce',
        'woo'            => 'WooCommerce',
        'jetpack'        => 'Jetpack',
        'yoast'          => 'Yoast SEO',
        'rank-math'      => 'Rank Math',
        'contact-form-7' => 'Contact Form 7',
    ];

    $total_blocks = count($all_blocks);
    ?>
    <div class="wrap suite-barrio-wrap" id="suite-barrio-blocks-wrap">

        <div class="suite-barrio-header-row">
            <div class="suite-barrio-header">
                <div class="suite-barrio-header-icon" style="background:#0f172a;">
                    <span class="dashicons dashicons-layout" style="color:#fff;font-size:20px;width:20px;height:20px;"></span>
                </div>
                <div>
                    <h1>Suite Barrio <span style="font-size:14px;font-weight:400;color:#9ca3af;">/ Blocks</span></h1>
                    <p class="suite-barrio-header-sub">Gestisci quali blocchi Gutenberg sono disponibili per gli utenti non amministratori</p>
                </div>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio')); ?>"
                class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-ghost">
                <span class="dashicons dashicons-arrow-left-alt2" style="font-size:14px;width:14px;height:14px;"></span>
                Dashboard
            </a>
        </div>

        <hr class="suite-barrio-divider">

        <?php if ($saved): ?>
            <div class="suite-barrio-notice suite-barrio-notice-success" style="margin-bottom:20px;">
                <span class="dashicons dashicons-yes-alt"></span>
                <span>Impostazioni salvate.</span>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="suite-barrio-blocks-form">
            <?php wp_nonce_field('suite_barrio_blocks_save'); ?>
            <input type="hidden" name="action" value="suite_barrio_blocks_save">

            <!-- Editor Gutenberg per post type -->
            <div class="suite-barrio-section-box" style="margin-bottom:20px;">
                <div class="suite-barrio-section-head">
                    <span class="dashicons dashicons-edit-page"></span> Editor Gutenberg per tipo di contenuto
                </div>
                <div class="suite-barrio-section-body">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;">
                    <?php foreach ($all_post_types_for_gutenberg as $pt):
                        $pt_id      = 'suite_barrio_gpt_' . $pt->name;
                        $pt_checked = ($gutenberg_post_types === null) || in_array($pt->name, $gutenberg_post_types, true);
                        $pt_label   = $pt->labels->singular_name ?: $pt->name;
                    ?>
                        <label class="suite-barrio-blocks-item<?php echo $pt_checked ? ' is-checked' : ''; ?>"
                               for="<?php echo esc_attr($pt_id); ?>"
                               style="padding:10px 14px;">
                            <span class="suite-barrio-blocks-item-label">
                                <strong><?php echo esc_html($pt_label); ?></strong>
                                <code><?php echo esc_html($pt->name); ?></code>
                            </span>
                            <span class="suite-barrio-wizard-toggle-wrap">
                                <input type="checkbox"
                                       id="<?php echo esc_attr($pt_id); ?>"
                                       name="gutenberg_post_types[]"
                                       value="<?php echo esc_attr($pt->name); ?>"
                                       <?php checked($pt_checked); ?>>
                                <span class="suite-barrio-wizard-toggle-track"></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Attiva filtro -->
            <div class="suite-barrio-section-box" style="margin-bottom:20px;">
                <div class="suite-barrio-section-head">
                    <span class="dashicons dashicons-shield"></span> Stato del filtro
                </div>
                <div class="suite-barrio-section-body">
                    <label class="suite-barrio-module-row" style="cursor:pointer;">
                        <div class="suite-barrio-module-info">
                            <div class="suite-barrio-module-label">Abilita restrizione blocchi</div>
                            <div class="suite-barrio-module-desc">
                                Quando attivo, gli utenti non amministratori vedranno nell'editor solo i blocchi selezionati qui sotto.
                                Gli amministratori hanno sempre accesso a tutti i blocchi.
                            </div>
                        </div>
                        <label class="suite-barrio-toggle">
                            <input type="checkbox" name="suite_barrio_blocks_enabled" id="suite_barrio_blocks_enabled"
                                value="1" <?php checked($enabled); ?>>
                            <span class="suite-barrio-toggle-track"></span>
                        </label>
                    </label>
                </div>
            </div>

            <!-- Lista blocchi -->
            <div id="suite-barrio-blocks-list" <?php echo !$enabled ? 'style="opacity:.4;pointer-events:none;"' : ''; ?>>

                <!-- Barra ricerca e azioni globali -->
                <div class="suite-barrio-blocks-toolbar">
                    <div class="suite-barrio-blocks-search-wrap">
                        <span class="dashicons dashicons-search suite-barrio-blocks-search-icon"></span>
                        <input type="text" id="suite-barrio-blocks-search" placeholder="Cerca blocco…" autocomplete="off">
                    </div>
                    <div class="suite-barrio-blocks-global-actions">
                        <button type="button" id="suite-barrio-blocks-select-all" class="suite-barrio-btn suite-barrio-btn-ghost suite-barrio-btn-small">
                            Seleziona tutti
                        </button>
                        <button type="button" id="suite-barrio-blocks-deselect-all" class="suite-barrio-btn suite-barrio-btn-ghost suite-barrio-btn-small">
                            Deseleziona tutti
                        </button>
                        <span class="suite-barrio-blocks-count" id="suite-barrio-blocks-count">
                            <?php echo count($allowed); ?> / <?php echo esc_html($total_blocks); ?> selezionati
                        </span>
                    </div>
                </div>

                <?php foreach ($groups as $ns => $blocks):
                    $ns_label    = $ns_labels[$ns] ?? ucfirst(str_replace(['-', '_'], ' ', $ns));
                    $group_total = count($blocks);
                    $group_sel   = count(array_intersect(array_keys($blocks), $allowed));
                ?>
                <div class="suite-barrio-blocks-group" data-ns="<?php echo esc_attr($ns); ?>">
                    <div class="suite-barrio-blocks-group-head">
                        <button type="button" class="suite-barrio-blocks-group-toggle">
                            <span class="dashicons dashicons-arrow-down-alt2 suite-barrio-blocks-group-chevron"></span>
                        </button>
                        <strong class="suite-barrio-blocks-group-label"><?php echo esc_html($ns_label); ?></strong>
                        <code class="suite-barrio-blocks-group-ns"><?php echo esc_html($ns); ?></code>
                        <span class="suite-barrio-blocks-group-count">
                            <span class="suite-barrio-blocks-group-sel"><?php echo esc_html($group_sel); ?></span> / <?php echo esc_html($group_total); ?>
                        </span>
                        <div class="suite-barrio-blocks-group-actions">
                            <button type="button" class="suite-barrio-blocks-group-all suite-barrio-btn suite-barrio-btn-ghost suite-barrio-btn-small">tutti</button>
                            <button type="button" class="suite-barrio-blocks-group-none suite-barrio-btn suite-barrio-btn-ghost suite-barrio-btn-small">nessuno</button>
                        </div>
                    </div>
                    <div class="suite-barrio-blocks-group-body">
                        <?php foreach ($blocks as $block_name => $block):
                            $is_checked = in_array($block_name, $allowed, true);
                            $label      = !empty($block->title) ? $block->title : $block_name;
                            $category   = !empty($block->category) ? $block->category : '';
                            $cid        = 'suite_barrio_block_' . str_replace(['/', '-'], '_', $block_name);
                        ?>
                            <label class="suite-barrio-blocks-item<?php echo $is_checked ? ' is-checked' : ''; ?>"
                                for="<?php echo esc_attr($cid); ?>"
                                data-name="<?php echo esc_attr($block_name); ?>"
                                data-label="<?php echo esc_attr(strtolower($label)); ?>">
                                <span class="suite-barrio-blocks-item-label">
                                    <strong><?php echo esc_html($label); ?></strong>
                                    <code><?php echo esc_html($block_name); ?></code>
                                </span>
                                <span class="suite-barrio-wizard-toggle-wrap">
                                    <input type="checkbox"
                                        id="<?php echo esc_attr($cid); ?>"
                                        name="allowed_blocks[]"
                                        value="<?php echo esc_attr($block_name); ?>"
                                        <?php checked($is_checked); ?>>
                                    <span class="suite-barrio-wizard-toggle-track"></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

            </div><!-- #suite-barrio-blocks-list -->

            <div style="margin-top:24px;">
                <button type="submit" class="suite-barrio-save-btn">
                    <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span>
                    Salva impostazioni
                </button>
            </div>

        </form>

    </div><!-- .suite-barrio-wrap -->

    <?php
}
