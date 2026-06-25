<?php
/**
 * WP Barrio — Blocks
 * Gestisce quali blocchi Gutenberg sono disponibili per gli utenti non amministratori.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('WPB_BLOCKS_OPTION', 'wpb_blocks_settings');

/* ─────────────────────────────────────────
   HELPERS
   ───────────────────────────────────────── */
function wpb_blocks_get_settings(): array
{
    $defaults = [
        'enabled' => true,
        'allowed' => ['core/paragraph', 'core/list', 'core/heading', 'core/separator', 'core/embed'],
    ];
    $s = get_option(WPB_BLOCKS_OPTION, false);
    if ($s === false || !is_array($s)) return $defaults;
    return array_merge($defaults, $s);
}

function wpb_blocks_is_enabled(): bool
{
    return !empty(wpb_blocks_get_settings()['enabled']);
}

function wpb_blocks_get_allowed(): array
{
    $s = wpb_blocks_get_settings();
    return isset($s['allowed']) && is_array($s['allowed']) ? $s['allowed'] : [];
}

/* ─────────────────────────────────────────
   FILTRO EDITOR — limita blocchi per non-admin
   ───────────────────────────────────────── */
add_filter('allowed_block_types_all', function ($allowed, $context) {
    if (!wpb_blocks_is_enabled())
        return $allowed;
    if (current_user_can('manage_options'))
        return $allowed;
    $list = wpb_blocks_get_allowed();
    return !empty($list) ? $list : $allowed;
}, 10, 2);

/* ─────────────────────────────────────────
   RESTRIZIONE H1 — editor JS + safety server-side
   ───────────────────────────────────────── */

// Server-side: converte <h1> → <h2> nel render per gli utenti non-admin
add_filter('render_block', function (string $html, array $block): string {
    if (!wpb_blocks_is_enabled()) return $html;
    if (current_user_can('manage_options')) return $html;
    if (($block['blockName'] ?? '') !== 'core/heading') return $html;
    if ((int)($block['attrs']['level'] ?? 2) !== 1) return $html;
    return preg_replace('/<(\/?)h1(\b[^>]*)>/i', '<$1h2$2>', $html);
}, 10, 2);

// Editor JS: nasconde H1 dalla toolbar heading e corregge blocchi H1 già salvati
add_action('enqueue_block_editor_assets', function () {
    if (!wpb_blocks_is_enabled()) return;
    if (current_user_can('manage_options')) return;
    wp_enqueue_script(
        'wpb-blocks-no-h1',
        WP_ALT_AI_PLUGIN_URL . 'modules/blocks/editor.js',
        ['wp-hooks', 'wp-compose', 'wp-element'],
        WP_ALT_AI_VERSION
    );
});

/* ─────────────────────────────────────────
   MENU
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    add_submenu_page(
        'wp-barrio',
        'Blocks — WP Barrio',
        'Blocks',
        'manage_options',
        'wp-barrio-blocks',
        'wpb_blocks_admin_page'
    );
});

/* ─────────────────────────────────────────
   SALVATAGGIO
   ───────────────────────────────────────── */
add_action('admin_post_wpb_blocks_save', function () {
    check_admin_referer('wpb_blocks_save');

    $enabled = isset($_POST['wpb_blocks_enabled']) ? true : false;

    $allowed = [];
    if (isset($_POST['allowed_blocks']) && is_array($_POST['allowed_blocks'])) {
        foreach ($_POST['allowed_blocks'] as $raw) {
            $name = sanitize_text_field(wp_unslash($raw));
            if (preg_match('/^[a-z0-9\-]+\/[a-z0-9\-]+$/', $name)) {
                $allowed[] = $name;
            }
        }
    }

    update_option(WPB_BLOCKS_OPTION, [
        'enabled' => $enabled,
        'allowed' => $allowed,
    ]);

    wp_safe_redirect(admin_url('admin.php?page=wp-barrio-blocks&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function wpb_blocks_admin_page()
{
    $settings = wpb_blocks_get_settings();
    $enabled  = !empty($settings['enabled']);
    $allowed  = isset($settings['allowed']) && is_array($settings['allowed']) ? $settings['allowed'] : [];
    $saved    = isset($_GET['saved']);

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
    <div class="wrap wpb-wrap" id="wpb-blocks-wrap">

        <div class="wpb-header-row">
            <div class="wpb-header">
                <div class="wpb-header-icon" style="background:#0f172a;">
                    <span class="dashicons dashicons-layout" style="color:#fff;font-size:20px;width:20px;height:20px;"></span>
                </div>
                <div>
                    <h1>Blocks</h1>
                    <p class="wpb-header-sub">Gestisci quali blocchi Gutenberg sono disponibili per gli utenti non amministratori</p>
                </div>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio')); ?>"
                class="wpb-btn wpb-btn-small wpb-btn-ghost">
                <span class="dashicons dashicons-arrow-left-alt2" style="font-size:14px;width:14px;height:14px;"></span>
                Dashboard
            </a>
        </div>

        <hr class="wpb-divider">

        <?php if ($saved): ?>
            <div class="wpb-notice wpb-notice-success" style="margin-bottom:20px;">
                <span class="dashicons dashicons-yes-alt"></span>
                <span>Impostazioni salvate.</span>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wpb-blocks-form">
            <?php wp_nonce_field('wpb_blocks_save'); ?>
            <input type="hidden" name="action" value="wpb_blocks_save">

            <!-- Attiva filtro -->
            <div class="wpb-section-box" style="margin-bottom:20px;">
                <div class="wpb-section-head">
                    <span class="dashicons dashicons-shield"></span> Stato del filtro
                </div>
                <div class="wpb-section-body">
                    <label class="wpb-module-row" style="cursor:pointer;">
                        <div class="wpb-module-info">
                            <div class="wpb-module-label">Abilita restrizione blocchi</div>
                            <div class="wpb-module-desc">
                                Quando attivo, gli utenti non amministratori vedranno nell'editor solo i blocchi selezionati qui sotto.
                                Gli amministratori hanno sempre accesso a tutti i blocchi.
                            </div>
                        </div>
                        <label class="wpb-toggle">
                            <input type="checkbox" name="wpb_blocks_enabled" id="wpb_blocks_enabled"
                                value="1" <?php checked($enabled); ?>>
                            <span class="wpb-toggle-track"></span>
                        </label>
                    </label>
                </div>
            </div>

            <!-- Lista blocchi -->
            <div id="wpb-blocks-list" <?php echo !$enabled ? 'style="opacity:.4;pointer-events:none;"' : ''; ?>>

                <!-- Barra ricerca e azioni globali -->
                <div class="wpb-blocks-toolbar">
                    <div class="wpb-blocks-search-wrap">
                        <span class="dashicons dashicons-search wpb-blocks-search-icon"></span>
                        <input type="text" id="wpb-blocks-search" placeholder="Cerca blocco…" autocomplete="off">
                    </div>
                    <div class="wpb-blocks-global-actions">
                        <button type="button" id="wpb-blocks-select-all" class="wpb-btn wpb-btn-ghost wpb-btn-small">
                            Seleziona tutti
                        </button>
                        <button type="button" id="wpb-blocks-deselect-all" class="wpb-btn wpb-btn-ghost wpb-btn-small">
                            Deseleziona tutti
                        </button>
                        <span class="wpb-blocks-count" id="wpb-blocks-count">
                            <?php echo count($allowed); ?> / <?php echo $total_blocks; ?> selezionati
                        </span>
                    </div>
                </div>

                <?php foreach ($groups as $ns => $blocks):
                    $ns_label    = $ns_labels[$ns] ?? ucfirst(str_replace(['-', '_'], ' ', $ns));
                    $group_total = count($blocks);
                    $group_sel   = count(array_intersect(array_keys($blocks), $allowed));
                ?>
                <div class="wpb-blocks-group" data-ns="<?php echo esc_attr($ns); ?>">
                    <div class="wpb-blocks-group-head">
                        <button type="button" class="wpb-blocks-group-toggle">
                            <span class="dashicons dashicons-arrow-down-alt2 wpb-blocks-group-chevron"></span>
                        </button>
                        <strong class="wpb-blocks-group-label"><?php echo esc_html($ns_label); ?></strong>
                        <code class="wpb-blocks-group-ns"><?php echo esc_html($ns); ?></code>
                        <span class="wpb-blocks-group-count">
                            <span class="wpb-blocks-group-sel"><?php echo $group_sel; ?></span> / <?php echo $group_total; ?>
                        </span>
                        <div class="wpb-blocks-group-actions">
                            <button type="button" class="wpb-blocks-group-all wpb-btn wpb-btn-ghost wpb-btn-small">tutti</button>
                            <button type="button" class="wpb-blocks-group-none wpb-btn wpb-btn-ghost wpb-btn-small">nessuno</button>
                        </div>
                    </div>
                    <div class="wpb-blocks-group-body">
                        <?php foreach ($blocks as $block_name => $block):
                            $is_checked = in_array($block_name, $allowed, true);
                            $label      = !empty($block->title) ? $block->title : $block_name;
                            $category   = !empty($block->category) ? $block->category : '';
                            $cid        = 'wpb_block_' . str_replace(['/', '-'], '_', $block_name);
                        ?>
                            <label class="wpb-blocks-item<?php echo $is_checked ? ' is-checked' : ''; ?>"
                                for="<?php echo esc_attr($cid); ?>"
                                data-name="<?php echo esc_attr($block_name); ?>"
                                data-label="<?php echo esc_attr(strtolower($label)); ?>">
                                <span class="wpb-blocks-item-label">
                                    <strong><?php echo esc_html($label); ?></strong>
                                    <code><?php echo esc_html($block_name); ?></code>
                                </span>
                                <span class="wpb-wizard-toggle-wrap">
                                    <input type="checkbox"
                                        id="<?php echo esc_attr($cid); ?>"
                                        name="allowed_blocks[]"
                                        value="<?php echo esc_attr($block_name); ?>"
                                        <?php checked($is_checked); ?>>
                                    <span class="wpb-wizard-toggle-track"></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

            </div><!-- #wpb-blocks-list -->

            <div style="margin-top:24px;">
                <button type="submit" class="wpb-save-btn">
                    <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span>
                    Salva impostazioni
                </button>
            </div>

        </form>

    </div><!-- .wpb-wrap -->

    <script>
    (function () {
        var enableToggle = document.getElementById('wpb_blocks_enabled');
        var listWrap     = document.getElementById('wpb-blocks-list');
        var searchInput  = document.getElementById('wpb-blocks-search');
        var countEl      = document.getElementById('wpb-blocks-count');

        // Abilita/disabilita lista al cambio del toggle principale
        enableToggle.addEventListener('change', function () {
            listWrap.style.opacity       = this.checked ? '1'    : '.4';
            listWrap.style.pointerEvents = this.checked ? 'auto' : 'none';
        });

        // Aggiorna contatore selezionati
        function updateCount() {
            var total   = document.querySelectorAll('.wpb-blocks-item input[type=checkbox]').length;
            var checked = document.querySelectorAll('.wpb-blocks-item input[type=checkbox]:checked').length;
            if (countEl) countEl.textContent = checked + ' / ' + total + ' selezionati';
        }

        // Aggiorna contatore per gruppo
        function updateGroupCount(group) {
            var total   = group.querySelectorAll('.wpb-blocks-item input').length;
            var checked = group.querySelectorAll('.wpb-blocks-item input:checked').length;
            var selEl   = group.querySelector('.wpb-blocks-group-sel');
            if (selEl) selEl.textContent = checked;
        }

        // Stile riga al cambio checkbox
        document.querySelectorAll('.wpb-blocks-item input[type=checkbox]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var item  = cb.closest('.wpb-blocks-item');
                var group = cb.closest('.wpb-blocks-group');
                if (item)  item.classList.toggle('is-checked', cb.checked);
                if (group) updateGroupCount(group);
                updateCount();
            });
        });

        // Collapse/expand gruppo
        document.querySelectorAll('.wpb-blocks-group-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var group  = btn.closest('.wpb-blocks-group');
                var body   = group.querySelector('.wpb-blocks-group-body');
                var chev   = group.querySelector('.wpb-blocks-group-chevron');
                var hidden = body.style.display === 'none';
                body.style.display = hidden ? '' : 'none';
                chev.classList.toggle('wpb-blocks-group-chevron-up', hidden);
            });
        });

        // Seleziona/deseleziona tutto nel gruppo
        document.querySelectorAll('.wpb-blocks-group-all').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var group = btn.closest('.wpb-blocks-group');
                group.querySelectorAll('.wpb-blocks-item input').forEach(function (cb) {
                    cb.checked = true;
                    cb.closest('.wpb-blocks-item').classList.add('is-checked');
                });
                updateGroupCount(group);
                updateCount();
            });
        });
        document.querySelectorAll('.wpb-blocks-group-none').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var group = btn.closest('.wpb-blocks-group');
                group.querySelectorAll('.wpb-blocks-item input').forEach(function (cb) {
                    cb.checked = false;
                    cb.closest('.wpb-blocks-item').classList.remove('is-checked');
                });
                updateGroupCount(group);
                updateCount();
            });
        });

        // Seleziona/deseleziona tutti globale
        document.getElementById('wpb-blocks-select-all').addEventListener('click', function () {
            document.querySelectorAll('.wpb-blocks-item:not(.wpb-blocks-item-hidden) input').forEach(function (cb) {
                cb.checked = true;
                cb.closest('.wpb-blocks-item').classList.add('is-checked');
            });
            document.querySelectorAll('.wpb-blocks-group').forEach(updateGroupCount);
            updateCount();
        });
        document.getElementById('wpb-blocks-deselect-all').addEventListener('click', function () {
            document.querySelectorAll('.wpb-blocks-item:not(.wpb-blocks-item-hidden) input').forEach(function (cb) {
                cb.checked = false;
                cb.closest('.wpb-blocks-item').classList.remove('is-checked');
            });
            document.querySelectorAll('.wpb-blocks-group').forEach(updateGroupCount);
            updateCount();
        });

        // Ricerca
        searchInput.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            document.querySelectorAll('.wpb-blocks-group').forEach(function (group) {
                var visible = 0;
                group.querySelectorAll('.wpb-blocks-item').forEach(function (item) {
                    var match = !q
                        || item.dataset.label.includes(q)
                        || item.dataset.name.includes(q);
                    item.classList.toggle('wpb-blocks-item-hidden', !match);
                    if (match) visible++;
                });
                group.classList.toggle('wpb-blocks-group-hidden', visible === 0);
            });
        });

        updateCount();
    })();
    </script>
    <?php
}
