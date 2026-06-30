<?php
/**
 * WP Barrio — Dashboard
 * Pagina principale del plugin con le card dei moduli.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   HELPER: tutti i moduli sono disattivi?
   ───────────────────────────────────────── */
function wpb_all_modules_disabled()
{
    foreach (array_keys(wpb_tools_modules_list()) as $key) {
        if (wpb_tools_module_enabled($key))
            return false;
    }
    return true;
}

/* ─────────────────────────────────────────
   MENU ADMIN: voce principale + rimozione duplicato
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (wpb_all_modules_disabled() && !wpb_tools_can_access()) {
        return;
    }

    add_menu_page(
        'WP Barrio',
        'WP Barrio',
        'manage_options',
        'wp-barrio',
        'wp_barrio_dashboard_page',
        'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjIwIiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSIgcm9sZT0iaW1nIj48cGF0aCBmaWxsPSJibGFjayIgZD0iTTUsNWgyLjV2Mi41SDVaTTAsOC43NWgyLjV2Mi41SDBaTTIuNSwxMS4yNWgyLjV2Mi41SDIuNVpNNSwxMy43NWgxMHYyLjVINVpNMTcuNSw4Ljc1aDIuNXYyLjVIMTcuNVpNMTUsMTEuMjVoMi41djIuNUgxNVpNMTIuNSw1aDIuNXYyLjVIMTIuNVoiLz48L3N2Zz4=',
        80
    );
    remove_submenu_page('wp-barrio', 'wp-barrio');
}, 9);

/* ─────────────────────────────────────────
   DASHBOARD PAGE
   ───────────────────────────────────────── */
function wp_barrio_dashboard_page()
{
    global $wpdb;
    $graphql_active = function_exists('register_graphql_field');

    if (wpb_tools_module_enabled('auto_alt')) {
        $total_images = array_sum((array) wp_count_attachments());
        $missing_count = wp_alt_ai_count_incomplete();
        $done_count = max(0, $total_images - $missing_count);
        $provider = get_option('wp_alt_ai_provider', 'anthropic');
        $provider_name = $provider === 'gemini' ? 'Google Gemini' : 'Anthropic Claude';
        $auto_upload = get_option('wp_alt_ai_auto_upload', 0);
    }

    if (wpb_tools_module_enabled('forms')) {
        $forms_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpb_forms");
        $subs_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpb_form_submissions");
    }

    if (wpb_tools_module_enabled('popups')) {
        $popups_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpb_popups");
        $popup_active = wpb_popups_get_active();
    }

    if (wpb_tools_module_enabled('banners')) {
        $banners_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpb_banners");
        $banner_active = function_exists('wpb_banners_get_active') ? wpb_banners_get_active() : null;
    }

    if (wpb_tools_module_enabled('orders')) {
        $orders_settings = get_option(WPB_ORDERS_OPTION, []);
        $orders_pt_count = count($orders_settings['enabled_post_types'] ?? []);
        $orders_tax_count = count($orders_settings['enabled_taxonomies'] ?? []);
    }

    if (wpb_tools_module_enabled('blocks')) {
        $blocks_s = function_exists('wpb_blocks_get_settings') ? wpb_blocks_get_settings() : [];
        $blocks_enabled = !empty($blocks_s['enabled']);
        $blocks_count = isset($blocks_s['allowed']) && is_array($blocks_s['allowed']) ? count($blocks_s['allowed']) : 0;
    }

    if (wpb_tools_module_enabled('duplicate')) {
        $dup_settings = get_option(WPB_DUPLICATE_OPTION, []);
        $dup_pt_count = count($dup_settings['enabled_post_types'] ?? []);
        $dup_tax_count = count($dup_settings['enabled_taxonomies'] ?? []);
    }

    if (wpb_tools_module_enabled('stats')) {
        $stats_s = wpb_stats_settings();
        $stats_configured = wpb_stats_is_configured();
        $stats_connected = wpb_stats_is_connected();
        $stats_replace = !empty($stats_s['replace_dashboard']);
    }

    if (wpb_tools_module_enabled('orari')) {
        $orari_data = get_option(WPB_ORARI_DATA_OPTION, []);
        $orari_settings = get_option(WPB_ORARI_OPTION, []);
        $orari_next_cron = wp_next_scheduled(WPB_ORARI_CRON_HOOK);
        $orari_configured = !empty(trim($orari_settings['google_api_key'] ?? ''))
            && !empty(trim($orari_settings['place_id'] ?? ''));
    }

    if (wpb_tools_module_enabled('accessibility'))
        $a11y_s = function_exists('wpb_accessibility_settings') ? wpb_accessibility_settings() : [];

    if (wpb_tools_module_enabled('cookie_privacy')) {
        $cp_domain = function_exists('wpb_cp_get_domain')
            ? wpb_cp_get_domain()
            : (string) parse_url(home_url(), PHP_URL_HOST);
        $cp_privacy_page_id = (int) get_option('wpb_cp_privacy_page_id', 0);
        $cp_cookie_page_id = (int) get_option('wpb_cp_cookie_page_id', 0);
        $cp_provider = get_option('wpb_cp_provider', 'gemini');
        $cp_provider_name = $cp_provider === 'anthropic' ? 'Anthropic Claude' : 'Google Gemini';
        $cp_privacy_page = $cp_privacy_page_id ? get_post($cp_privacy_page_id) : null;
        $cp_cookie_page = $cp_cookie_page_id ? get_post($cp_cookie_page_id) : null;
        $cp_log = get_option('wpb_cp_log', []);
        $cp_configured = !empty($cp_domain)
            && $cp_privacy_page instanceof WP_Post
            && $cp_cookie_page instanceof WP_Post
            && $cp_privacy_page_id !== $cp_cookie_page_id;
    }
    ?>
    <div id="wpb-dash" class="wrap wpb-wrap">

        <div class="wpb-header">
            <div class="wpb-header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="22" height="22" aria-hidden="true">
                    <path fill="#fff"
                        d="M5,5h2.5v2.5H5ZM0,8.75h2.5v2.5H0ZM2.5,11.25h2.5v2.5H2.5ZM5,13.75h10v2.5H5ZM17.5,8.75h2.5v2.5H17.5ZM15,11.25h2.5v2.5H15ZM12.5,5h2.5v2.5H12.5Z" />
                </svg>
            </div>
            <div>
                <h1>WP Barrio <span class="wpb-version-badge">v<?php echo WP_ALT_AI_VERSION; ?></span></h1>
                <p class="wpb-header-sub">Suite di strumenti AI per WordPress</p>
            </div>
        </div>

        <hr class="wpb-divider">

        <?php if (!function_exists('wpb_wizard_is_complete') || !wpb_wizard_is_complete()): ?>
            <div class="wpb-wizard-banner">
                <div class="wpb-wizard-banner-body">
                    <div class="wpb-wizard-banner-icon">
                        <span class="dashicons dashicons-migrate"></span>
                    </div>
                    <div class="wpb-wizard-banner-text">
                        <strong>Messa online del sito</strong>
                        <span>Segui la guida passo-passo per configurare e pubblicare il sito.</span>
                    </div>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-wizard')); ?>"
                    class="wpb-btn wpb-btn-small wpb-btn-launch">
                    Inizia la messa online &rarr;
                </a>
            </div>
        <?php endif; ?>

        <?php
        // Alert passaggi saltati / checkbox non completate (solo se wizard terminato)
        if (
            function_exists('wpb_wizard_is_complete') && wpb_wizard_is_complete() &&
            function_exists('wpb_wizard_badge_count') && wpb_wizard_badge_count() > 0
        ):
            $wiz_progress = function_exists('wpb_wizard_get_progress') ? wpb_wizard_get_progress() : [];
            $saved_chks = isset($wiz_progress['step_8_checks']) && is_array($wiz_progress['step_8_checks'])
                ? $wiz_progress['step_8_checks'] : [];
            $dismissed = function_exists('wpb_wizard_get_dismissed') ? wpb_wizard_get_dismissed() : [];

            $final_items = [
                'gtm_analytics' => ['label' => 'Inserire analytics in Google Tag Manager', 'icon' => 'dashicons-chart-area'],
                'mailjet_recaptcha' => ['label' => 'Inserire Recaptcha', 'icon' => 'dashicons-email-alt'],
                'email_forms' => ['label' => 'Graficare email inviate dai form', 'icon' => 'dashicons-email'],
                'orari_gmaps' => ['label' => 'Inserire orari tramite Google Place API', 'icon' => 'dashicons-location-alt'],
                'popup_banner' => ['label' => 'Graficare Popup e Banner', 'icon' => 'dashicons-megaphone'],
                'auto_alt' => ['label' => 'Generare alt e title delle immagini', 'icon' => 'dashicons-format-image'],
                'cookie_policy' => ['label' => 'Genera Cookie e Privacy policy con AI', 'icon' => 'dashicons-shield'],
                'accessibility' => ['label' => 'Attiva accessibilità', 'icon' => 'dashicons-universal-access'],
            ];
            ?>
            <div class="wpb-wizard-todo" id="wpb-wizard-todo">
                <div class="wpb-wizard-todo-head">
                    <span class="dashicons dashicons-warning"></span>
                    <strong>Messa online — operazioni da completare</strong>
                </div>
                <ul class="wpb-wizard-todo-list">

                    <?php
                    // Step saltati
                    if (function_exists('wpb_wizard_steps')) {
                        foreach (wpb_wizard_steps() as $n => $step) {
                            if ($n === 8)
                                continue;
                            if (($wiz_progress["step_{$n}"] ?? 'pending') !== 'skipped')
                                continue;
                            if (in_array("step_{$n}", $dismissed, true))
                                continue;
                            $url = admin_url('admin.php?page=wp-barrio-wizard&step=' . $n);
                            ?>
                            <li class="wpb-wizard-todo-item" data-alert-key="step_<?php echo $n; ?>">
                                <span class="dashicons <?php echo esc_attr($step['icon']); ?>"></span>
                                <span class="wpb-wizard-todo-label">
                                    Passaggio saltato: <strong><?php echo esc_html($step['label']); ?></strong>
                                </span>
                                <a href="<?php echo esc_url($url); ?>" class="wpb-wizard-todo-link">
                                    Configura <span class="dashicons dashicons-arrow-right-alt2"></span>
                                </a>
                                <button type="button" class="wpb-wizard-todo-dismiss" title="Elimina notifica">
                                    <span class="dashicons dashicons-no-alt"></span>
                                </button>
                            </li>
                            <?php
                        }
                    }

                    // Checkbox non spuntate in "Controlli Finali"
                    $wiz_url_7 = admin_url('admin.php?page=wp-barrio-wizard&step=8');
                    foreach ($final_items as $key => $item) {
                        if (in_array($key, $saved_chks, true))
                            continue;
                        if (in_array("final_{$key}", $dismissed, true))
                            continue;
                        ?>
                        <li class="wpb-wizard-todo-item" data-alert-key="final_<?php echo esc_attr($key); ?>">
                            <span class="dashicons <?php echo esc_attr($item['icon']); ?>"></span>
                            <span class="wpb-wizard-todo-label">
                                <?php echo esc_html($item['label']); ?>
                            </span>
                            <a href="<?php echo esc_url($wiz_url_7); ?>" class="wpb-wizard-todo-link">
                                Verifica <span class="dashicons dashicons-arrow-right-alt2"></span>
                            </a>
                            <button type="button" class="wpb-wizard-todo-dismiss" title="Elimina notifica">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>
                        </li>
                        <?php
                    }
                    ?>

                </ul>
            </div>

            <script>
                (function () {
                    var nonce = <?php echo wp_json_encode(wp_create_nonce('wpb_wizard_dismiss_alert')); ?>;
                    var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                    var todo = document.getElementById('wpb-wizard-todo');

                    function updateMenuBadge(count) {
                        var badge = document.querySelector('#menu-posts-wp-barrio .update-plugins, #toplevel_page_wp-barrio .update-plugins');
                        if (!badge) return;
                        if (count <= 0) {
                            badge.remove();
                        } else {
                            badge.className = 'update-plugins count-' + count;
                            var inner = badge.querySelector('.update-count');
                            if (inner) inner.textContent = count;
                        }
                    }

                    document.querySelectorAll('.wpb-wizard-todo-dismiss').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var li = btn.closest('.wpb-wizard-todo-item');
                            var key = li ? li.dataset.alertKey : '';
                            if (!key) return;

                            btn.disabled = true;

                            var fd = new FormData();
                            fd.append('action', 'wpb_wizard_dismiss_alert');
                            fd.append('nonce', nonce);
                            fd.append('alert_key', key);

                            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                                .then(function (r) { return r.json(); })
                                .then(function (data) {
                                    if (!data.success) { btn.disabled = false; return; }
                                    li.style.transition = 'opacity .2s';
                                    li.style.opacity = '0';
                                    setTimeout(function () {
                                        li.remove();
                                        updateMenuBadge(data.data.badge);
                                        if (todo && todo.querySelectorAll('.wpb-wizard-todo-item').length === 0) {
                                            todo.style.transition = 'opacity .2s';
                                            todo.style.opacity = '0';
                                            setTimeout(function () { todo.remove(); }, 200);
                                        }
                                    }, 200);
                                })
                                .catch(function () { btn.disabled = false; });
                        });
                    });
                })();
            </script>

        <?php endif; ?>

        <?php if (wpb_tools_can_access() && wpb_all_modules_disabled()): ?>
            <div class="wpb-notice">
                <span class="dashicons dashicons-warning"></span>
                <div>
                    <strong>Tutti i moduli sono disattivati.</strong>
                    WP Barrio non è visibile agli altri utenti amministratori.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-tools')); ?>">Attiva almeno un modulo
                        &rarr;</a>
                </div>
            </div>
        <?php endif; ?>

        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-info-azienda')); ?>" class="wpb-company-link">
            <div class="wpb-company-link-icon">
                <span class="dashicons dashicons-id-alt"></span>
            </div>
            <div class="wpb-company-link-text">
                <p class="wpb-company-link-title">Info Azienda</p>
                <p class="wpb-company-link-desc">
                    Gestisci ragione sociale, dati fiscali, contatti, indirizzo e social media dell'azienda.
                </p>
            </div>
            <span class="dashicons dashicons-arrow-right-alt2 wpb-company-link-arrow"></span>
        </a>

        <?php if (function_exists('wpb_cache_purge_render')) {
            wpb_cache_purge_render();
        } ?>

        <div class="wpb-grid">

            <?php if (wpb_tools_module_enabled('auto_alt')): ?>
                <div class="wpb-card">
                    <div class="wpb-card-head" style="background:linear-gradient(135deg,#1d4ed8,#2563eb);">
                        <div class="wpb-card-icon"><span class="dashicons dashicons-format-image"></span></div>
                        <div>
                            <div class="wpb-card-title">Auto Alt</div>
                            <div class="wpb-card-subtitle">Generazione automatica alt text &amp; title</div>
                        </div>
                    </div>
                    <div class="wpb-card-body">
                        <table class="wpb-stats">
                            <tr>
                                <td>Immagini totali</td>
                                <td><?php echo number_format($total_images); ?></td>
                            </tr>
                            <tr>
                                <td>Senza alt text</td>
                                <td class="wpb-err"><?php echo number_format($missing_count); ?></td>
                            </tr>
                            <tr>
                                <td>Completate</td>
                                <td class="wpb-ok"><?php echo number_format($done_count); ?></td>
                            </tr>
                            <tr>
                                <td>Provider attivo</td>
                                <td><?php echo esc_html($provider_name); ?></td>
                            </tr>
                            <tr>
                                <td>Auto-generazione</td>
                                <td><?php if ($auto_upload): ?>
                                        <span class="wpb-ok">&#x2713; Attiva</span>
                                    <?php else: ?>
                                        <span class="wpb-mute">Disattiva</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="wpb-card-actions">
                            <a href="?page=wp-barrio-auto-alt&tab=review" class="wpb-btn wpb-btn-primary">Revisione
                                immagini</a>
                            <a href="?page=wp-barrio-auto-alt&tab=settings" class="wpb-btn wpb-btn-ghost">Impostazioni</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('auto_fields')): ?>
                <div class="wpb-card">
                    <div class="wpb-card-head" style="background:linear-gradient(135deg,#7c3aed,#8b5cf6);">
                        <div class="wpb-card-icon"><span class="dashicons dashicons-layout"></span></div>
                        <div>
                            <div class="wpb-card-title">Auto Fields</div>
                            <div class="wpb-card-subtitle">Genera campi ACF da pagina web</div>
                        </div>
                    </div>
                    <div class="wpb-card-body">
                        <?php $acf_available = function_exists('acf_import_field_group'); ?>
                        <table class="wpb-stats">
                            <tr>
                                <td>Funzionalità</td>
                                <td class="wpb-mute">Analisi HTML → ACF</td>
                            </tr>
                            <tr>
                                <td>ACF</td>
                                <td><?php if ($acf_available): ?>
                                        <span class="wpb-ok">&#x2713; Attivo</span>
                                    <?php else: ?>
                                        <span class="wpb-err">&#x2715; Non installato</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Output</td>
                                <td class="wpb-mute">Campi ACF + GraphQL</td>
                            </tr>
                        </table>
                        <div class="wpb-card-actions">
                            <a href="?page=wp-barrio-auto-fields" class="wpb-btn wpb-btn-primary">Genera campi</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('blocks')): ?>
                <div class="wpb-card">
                    <div class="wpb-card-head" style="background:linear-gradient(135deg,#0f172a,#1e293b);">
                        <div class="wpb-card-icon"><span class="dashicons dashicons-layout"></span></div>
                        <div>
                            <div class="wpb-card-title">Blocks</div>
                            <div class="wpb-card-subtitle">Visibilità blocchi Gutenberg per ruolo</div>
                        </div>
                    </div>
                    <div class="wpb-card-body">
                        <table class="wpb-stats">
                            <tr>
                                <td>Filtro</td>
                                <td><?php echo $blocks_enabled
                                    ? '<span class="wpb-ok">&#x2713; Attivo</span>'
                                    : '<span class="wpb-mute">Disattivato</span>'; ?></td>
                            </tr>
                            <tr>
                                <td>Blocchi permessi</td>
                                <td><?php echo $blocks_enabled && $blocks_count > 0
                                    ? '<span class="wpb-ok">&#x2713; ' . $blocks_count . '</span>'
                                    : '<span class="wpb-mute">Tutti</span>'; ?></td>
                            </tr>
                        </table>
                        <div class="wpb-card-actions">
                            <a href="?page=wp-barrio-blocks" class="wpb-btn wpb-btn-primary">Gestisci blocchi</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('banners')): ?>
                <div class="wpb-card">
                    <div class="wpb-card-head" style="background:linear-gradient(135deg,#0369a1,#0891b2);">
                        <div class="wpb-card-icon"><span class="dashicons dashicons-flag"></span></div>
                        <div>
                            <div class="wpb-card-title">Banners</div>
                            <div class="wpb-card-subtitle">Banner marquee a scorrimento</div>
                        </div>
                    </div>
                    <div class="wpb-card-body">
                        <table class="wpb-stats">
                            <tr>
                                <td>Banner totali</td>
                                <td><?php echo number_format($banners_count); ?></td>
                            </tr>
                            <tr>
                                <td>Banner attivo</td>
                                <td><?php if ($banner_active): ?>
                                        <span class="wpb-ok">&#x2713; <?php echo esc_html($banner_active->title); ?></span>
                                    <?php else: ?>
                                        <span class="wpb-mute">Nessuno</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>GraphQL</td>
                                <td><?php if ($graphql_active): ?>
                                        <span class="wpb-ok">&#x2713; hbBanner</span>
                                    <?php else: ?>
                                        <span class="wpb-mute">WPGraphQL non attivo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="wpb-card-actions">
                            <a href="?page=wp-barrio-banners" class="wpb-btn wpb-btn-primary">Gestisci banner</a>
                            <a href="?page=wp-barrio-banners&edit_id=new" class="wpb-btn wpb-btn-ghost">+ Nuovo</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('cookie_privacy')): ?>
                <div class="wpb-card">
                    <div class="wpb-card-head" style="background:linear-gradient(135deg,#0f766e,#14b8a6);">
                        <div class="wpb-card-icon"><span class="dashicons dashicons-shield"></span></div>
                        <div>
                            <div class="wpb-card-title">Cookie e Privacy</div>
                            <div class="wpb-card-subtitle">Generazione documenti GDPR</div>
                        </div>
                    </div>
                    <div class="wpb-card-body">
                        <table class="wpb-stats">
                            <tr>
                                <td>Configurazione</td>
                                <td><?php if ($cp_configured): ?>
                                        <span class="wpb-ok">&#x2713; Pronta</span>
                                    <?php else: ?>
                                        <span class="wpb-warn">Da completare</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Dominio</td>
                                <td><?php echo $cp_domain ? esc_html($cp_domain) : '<span class="wpb-mute">Non impostato</span>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Privacy Policy</td>
                                <td>
                                    <?php if ($cp_privacy_page instanceof WP_Post) { ?>
                                        <a href="<?php echo get_the_permalink($cp_privacy_page); ?>">
                                            <?php echo esc_html(get_the_title($cp_privacy_page)); ?>
                                        </a>
                                    <?php } else {
                                        echo '<span class="wpb-mute">Non selezionata</span>';
                                    } ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Cookie Policy</td>
                                <td>
                                    <?php if ($cp_cookie_page instanceof WP_Post) { ?>
                                        <a href="<?php echo get_the_permalink($cp_cookie_page); ?>">
                                            <?php echo esc_html(get_the_title($cp_cookie_page)); ?>
                                        </a>
                                    <?php } else {
                                        echo '<span class="wpb-mute">Non selezionata</span>';
                                    } ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Provider attivo</td>
                                <td><?php echo esc_html($cp_provider_name); ?></td>
                            </tr>
                        </table>
                        <div class="wpb-card-actions">
                            <a href="?page=wp-barrio-cookie-privacy&tab=generate" class="wpb-btn wpb-btn-primary">Genera</a>
                            <a href="?page=wp-barrio-cookie-privacy&tab=settings" class="wpb-btn wpb-btn-ghost">Impostazioni</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('duplicate')): ?>
                <div class="wpb-card">
                    <div class="wpb-card-head" style="background:linear-gradient(135deg,#0f766e,#0d9488);">
                        <div class="wpb-card-icon"><span class="dashicons dashicons-admin-page"></span></div>
                        <div>
                            <div class="wpb-card-title">Duplicate</div>
                            <div class="wpb-card-subtitle">Duplicazione rapida di post e termini</div>
                        </div>
                    </div>
                    <div class="wpb-card-body">
                        <table class="wpb-stats">
                            <tr>
                                <td>Post type abilitati</td>
                                <td><?php echo $dup_pt_count > 0
                                    ? '<span class="wpb-ok">&#x2713; ' . $dup_pt_count . '</span>'
                                    : '<span class="wpb-mute">Nessuno</span>'; ?></td>
                            </tr>
                            <tr>
                                <td>Tassonomie abilitate</td>
                                <td><?php echo $dup_tax_count > 0
                                    ? '<span class="wpb-ok">&#x2713; ' . $dup_tax_count . '</span>'
                                    : '<span class="wpb-mute">Nessuna</span>'; ?></td>
                            </tr>
                        </table>
                        <div class="wpb-card-actions">
                            <a href="?page=wp-barrio-duplicate" class="wpb-btn wpb-btn-primary">Configura duplicazione</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('forms')): ?>
                <div class="wpb-card">
                    <div class="wpb-card-head" style="background:linear-gradient(135deg,#059669,#10b981);">
                        <div class="wpb-card-icon"><span class="dashicons dashicons-feedback"></span></div>
                        <div>
                            <div class="wpb-card-title">Forms</div>
                            <div class="wpb-card-subtitle">Builder drag &amp; drop per form di contatto</div>
                        </div>
                    </div>
                    <div class="wpb-card-body">
                        <table class="wpb-stats">
                            <tr>
                                <td>Form attivi</td>
                                <td><?php echo number_format($forms_count); ?></td>
                            </tr>
                            <tr>
                                <td>Registrazioni ricevute</td>
                                <td><?php echo number_format($subs_count); ?></td>
                            </tr>
                            <tr>
                                <td>Dopo l'invio</td>
                                <td class="wpb-mute">Messaggio o redirect</td>
                            </tr>
                            <tr>
                                <td>GraphQL</td>
                                <td><?php if ($graphql_active): ?>
                                        <span class="wpb-ok">&#x2713; Attivo</span>
                                    <?php else: ?>
                                        <a href="<?php echo esc_url(admin_url('plugin-install.php?s=wpgraphql&tab=search&type=term')); ?>"
                                            class="wpb-err" style="text-decoration:none;font-size:11px;">&#x2715; Non
                                            installato</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="wpb-card-actions">
                            <a href="?page=wp-barrio-forms" class="wpb-btn wpb-btn-primary">Gestisci form</a>
                            <a href="?page=wp-barrio-forms&tab=settings" class="wpb-btn wpb-btn-ghost">Impostazioni</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('orari')): ?>
                <div class="wpb-card">
                    <div class="wpb-card-head" style="background:linear-gradient(135deg,#9a3412,#c2410c);">
                        <div class="wpb-card-icon"><span class="dashicons dashicons-clock"></span></div>
                        <div>
                            <div class="wpb-card-title">Orari</div>
                            <div class="wpb-card-subtitle">Sincronizzazione orari da Google Places</div>
                        </div>
                    </div>
                    <div class="wpb-card-body">
                        <table class="wpb-stats">
                            <tr>
                                <td>Credenziali</td>
                                <td><?php if ($orari_configured): ?>
                                        <span class="wpb-ok">&#x2713; Configurate</span>
                                    <?php else: ?>
                                        <span class="wpb-err">&#x2715; Non configurate</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Ultimo aggiornamento</td>
                                <td><?php echo !empty($orari_data['ultimo_aggiornamento'])
                                    ? esc_html($orari_data['ultimo_aggiornamento'])
                                    : '<span class="wpb-mute">Mai</span>'; ?></td>
                            </tr>
                            <tr>
                                <td>Prossimo cron</td>
                                <td><?php echo $orari_next_cron
                                    ? esc_html(date_i18n('d/m H:i', $orari_next_cron + get_option('gmt_offset') * 3600))
                                    : '<span class="wpb-mute">—</span>'; ?></td>
                            </tr>
                            <tr>
                                <td>GraphQL</td>
                                <td><?php if ($graphql_active): ?>
                                        <span class="wpb-ok">&#x2713; hbOrari</span>
                                    <?php else: ?>
                                        <span class="wpb-mute">WPGraphQL non attivo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="wpb-card-actions">
                            <a href="?page=wp-barrio-orari" class="wpb-btn wpb-btn-primary">Gestisci orari</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('orders')): ?>
                <div class="wpb-card">
                    <div class="wpb-card-head" style="background:linear-gradient(135deg,#5b21b6,#7c3aed);">
                        <div class="wpb-card-icon"><span class="dashicons dashicons-sort"></span></div>
                        <div>
                            <div class="wpb-card-title">Orders</div>
                            <div class="wpb-card-subtitle">Riordino drag &amp; drop di post e tassonomie</div>
                        </div>
                    </div>
                    <div class="wpb-card-body">
                        <table class="wpb-stats">
                            <tr>
                                <td>Post type ordinati</td>
                                <td><?php echo $orders_pt_count > 0
                                    ? '<span class="wpb-ok">&#x2713; ' . $orders_pt_count . '</span>'
                                    : '<span class="wpb-mute">Nessuno</span>'; ?></td>
                            </tr>
                            <tr>
                                <td>Tassonomie ordinate</td>
                                <td><?php echo $orders_tax_count > 0
                                    ? '<span class="wpb-ok">&#x2713; ' . $orders_tax_count . '</span>'
                                    : '<span class="wpb-mute">Nessuna</span>'; ?></td>
                            </tr>
                        </table>
                        <div class="wpb-card-actions">
                            <a href="?page=wp-barrio-orders" class="wpb-btn wpb-btn-primary">Gestisci ordini</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('popups')): ?>
                <div class="wpb-card">
                    <div class="wpb-card-head" style="background:linear-gradient(135deg,#6d28d9,#7c3aed);">
                        <div class="wpb-card-icon"><span class="dashicons dashicons-megaphone"></span></div>
                        <div>
                            <div class="wpb-card-title">Popups</div>
                            <div class="wpb-card-subtitle">Gestione popup frontend</div>
                        </div>
                    </div>
                    <div class="wpb-card-body">
                        <table class="wpb-stats">
                            <tr>
                                <td>Popup totali</td>
                                <td><?php echo number_format($popups_count); ?></td>
                            </tr>
                            <tr>
                                <td>Popup attivo</td>
                                <td><?php if ($popup_active): ?>
                                        <span class="wpb-ok">&#x2713; <?php echo esc_html($popup_active->title); ?></span>
                                    <?php else: ?>
                                        <span class="wpb-mute">Nessuno</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>GraphQL</td>
                                <td><?php if ($graphql_active): ?>
                                        <span class="wpb-ok">&#x2713; hbPopup</span>
                                    <?php else: ?>
                                        <span class="wpb-mute">WPGraphQL non attivo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="wpb-card-actions">
                            <a href="?page=wp-barrio-popups" class="wpb-btn wpb-btn-primary">Gestisci popup</a>
                            <a href="?page=wp-barrio-popups&edit_id=new" class="wpb-btn wpb-btn-ghost">+ Nuovo</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('seo')): ?>
                <div class="wpb-card">
                    <div class="wpb-card-head" style="background:linear-gradient(135deg,#c2410c,#ea580c);">
                        <div class="wpb-card-icon"><span class="dashicons dashicons-search"></span></div>
                        <div>
                            <div class="wpb-card-title">SEO</div>
                            <div class="wpb-card-subtitle">Strumenti per l'ottimizzazione sui motori di ricerca</div>
                        </div>
                    </div>
                    <div class="wpb-card-body">
                        <table class="wpb-stats">
                            <tr>
                                <td>Sotto-strumenti</td>
                                <td><?php
                                $sitemap_on = wpb_tools_module_enabled('seo_sitemap');
                                $count = $sitemap_on ? 6 : 5;
                                echo '<span class="wpb-ok">&#x2713; ' . $count . ' attivi</span>';
                                ?></td>
                            </tr>
                            <tr>
                                <td>GraphQL</td>
                                <td><?php if ($graphql_active): ?>
                                        <span class="wpb-ok">&#x2713; Attivo</span>
                                    <?php else: ?>
                                        <span class="wpb-mute">WPGraphQL non attivo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="wpb-card-actions">
                            <a href="?page=wp-barrio-seo" class="wpb-btn wpb-btn-primary">Vai a SEO</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('accessibility')): ?>
                <div class="wpb-card">
                    <div class="wpb-card-head" style="background:linear-gradient(135deg,#0d9488,#0f766e);">
                        <div class="wpb-card-icon"><span class="dashicons dashicons-universal-access"></span></div>
                        <div>
                            <div class="wpb-card-title">Accessibilità</div>
                            <div class="wpb-card-subtitle">Widget di accessibilità frontend</div>
                        </div>
                    </div>
                    <div class="wpb-card-body">
                        <table class="wpb-stats">
                            <tr>
                                <td>Posizione</td>
                                <td><?php echo isset($a11y_s['position']) && $a11y_s['position'] === 'right'
                                    ? '<span class="wpb-mute">In basso a destra</span>'
                                    : '<span class="wpb-mute">In basso a sinistra</span>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Colore principale</td>
                                <td>
                                    <span style="display:inline-flex;align-items:center;gap:6px;">
                                        <span
                                            style="display:inline-block;width:12px;height:12px;border-radius:3px;background:<?php echo esc_attr($a11y_s['accent_color'] ?? '#ea9c17'); ?>;border:1px solid rgba(0,0,0,.1);"></span>
                                        <span
                                            class="wpb-mute"><?php echo esc_html($a11y_s['accent_color'] ?? '#ea9c17'); ?></span>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>Colore secondario</td>
                                <td>
                                    <span style="display:inline-flex;align-items:center;gap:6px;">
                                        <span
                                            style="display:inline-block;width:12px;height:12px;border-radius:3px;background:<?php echo esc_attr($a11y_s['navy_color'] ?? '#415568'); ?>;border:1px solid rgba(0,0,0,.1);"></span>
                                        <span
                                            class="wpb-mute"><?php echo esc_html($a11y_s['navy_color'] ?? '#415568'); ?></span>
                                    </span>
                                </td>
                            </tr>
                        </table>
                        <div class="wpb-card-actions">
                            <a href="?page=wp-barrio-accessibility" class="wpb-btn wpb-btn-primary">Impostazioni</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('stats')): ?>
                <div class="wpb-card">
                    <div class="wpb-card-head" style="background:linear-gradient(135deg,#1a56db,#1967d2);">
                        <div class="wpb-card-icon"><span class="dashicons dashicons-chart-area"></span></div>
                        <div>
                            <div class="wpb-card-title">Statistiche</div>
                            <div class="wpb-card-subtitle">Google Analytics 4</div>
                        </div>
                    </div>
                    <div class="wpb-card-body">
                        <table class="wpb-stats">
                            <tr>
                                <td>Report GA4</td>
                                <td><?php if ($stats_connected): ?>
                                        <span class="wpb-ok">&#x2713; Collegato</span>
                                    <?php elseif ($stats_configured): ?>
                                        <span class="wpb-warn">&#x2713; Configurato, non connesso</span>
                                    <?php else: ?>
                                        <span class="wpb-mute">Non configurato</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Bacheca</td>
                                <td><?php if ($stats_replace && $stats_connected): ?>
                                        <span class="wpb-ok">&#x2713; Sostituita</span>
                                    <?php else: ?>
                                        <span class="wpb-mute">Predefinita</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="wpb-card-actions">
                            <a href="?page=wp-barrio-stats" class="wpb-btn wpb-btn-primary">Configura</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
        <?php
}
