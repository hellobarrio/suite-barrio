<?php
/**
 * Suite Barrio — Dashboard
 * Pagina principale del plugin con le card dei moduli.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   HELPER: tutti i moduli sono disattivi?
   ───────────────────────────────────────── */
function suite_barrio_all_modules_disabled()
{
    foreach (array_keys(suite_barrio_tools_modules_list()) as $key) {
        if (suite_barrio_tools_module_enabled($key))
            return false;
    }
    return true;
}

/* ─────────────────────────────────────────
   MENU ADMIN: voce principale + rimozione duplicato
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (suite_barrio_all_modules_disabled() && !suite_barrio_tools_can_access()) {
        return;
    }

    add_menu_page(
        'Suite Barrio',
        'Suite Barrio',
        'manage_options',
        'suite-barrio',
        'suite_barrio_dashboard_page',
        'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjIwIiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSIgcm9sZT0iaW1nIj48cGF0aCBmaWxsPSJibGFjayIgZD0iTTUsNWgyLjV2Mi41SDVaTTAsOC43NWgyLjV2Mi41SDBaTTIuNSwxMS4yNWgyLjV2Mi41SDIuNVpNNSwxMy43NWgxMHYyLjVINVpNMTcuNSw4Ljc1aDIuNXYyLjVIMTcuNVpNMTUsMTEuMjVoMi41djIuNUgxNVpNMTIuNSw1aDIuNXYyLjVIMTIuNVoiLz48L3N2Zz4=',
        80
    );
    remove_submenu_page('suite-barrio', 'suite-barrio');
}, 9);

/* ─────────────────────────────────────────
   ASSET: script per il widget "todo" del wizard
   (mostrato solo nella pagina principale della dashboard)
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_suite-barrio')
        return;

    if (
        !(function_exists('suite_barrio_wizard_is_complete') && suite_barrio_wizard_is_complete() &&
            function_exists('suite_barrio_wizard_badge_count') && suite_barrio_wizard_badge_count() > 0)
    ) {
        return;
    }

    wp_register_script('suite-barrio-dashboard-wizard-todo', '', [], SUITE_BARRIO_VERSION, true);
    wp_enqueue_script('suite-barrio-dashboard-wizard-todo');

    $inline_js = sprintf(
        '(function () {
    var nonce = %s;
    var ajaxUrl = %s;
    var todo = document.getElementById(\'suite-barrio-wizard-todo\');

    function updateMenuBadge(count) {
        var badge = document.querySelector(\'#menu-posts-suite-barrio .update-plugins, #toplevel_page_suite-barrio .update-plugins\');
        if (!badge) return;
        if (count <= 0) {
            badge.remove();
        } else {
            badge.className = \'update-plugins count-\' + count;
            var inner = badge.querySelector(\'.update-count\');
            if (inner) inner.textContent = count;
        }
    }

    document.querySelectorAll(\'.suite-barrio-wizard-todo-dismiss\').forEach(function (btn) {
        btn.addEventListener(\'click\', function () {
            var li = btn.closest(\'.suite-barrio-wizard-todo-item\');
            var key = li ? li.dataset.alertKey : \'\';
            if (!key) return;

            btn.disabled = true;

            var fd = new FormData();
            fd.append(\'action\', \'suite_barrio_wizard_dismiss_alert\');
            fd.append(\'nonce\', nonce);
            fd.append(\'alert_key\', key);

            fetch(ajaxUrl, { method: \'POST\', body: fd, credentials: \'same-origin\' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) { btn.disabled = false; return; }
                    li.style.transition = \'opacity .2s\';
                    li.style.opacity = \'0\';
                    setTimeout(function () {
                        li.remove();
                        updateMenuBadge(data.data.badge);
                        if (todo && todo.querySelectorAll(\'.suite-barrio-wizard-todo-item\').length === 0) {
                            todo.style.transition = \'opacity .2s\';
                            todo.style.opacity = \'0\';
                            setTimeout(function () { todo.remove(); }, 200);
                        }
                    }, 200);
                })
                .catch(function () { btn.disabled = false; });
        });
    });
})();',
        wp_json_encode(wp_create_nonce('suite_barrio_wizard_dismiss_alert')),
        wp_json_encode(admin_url('admin-ajax.php'))
    );

    wp_add_inline_script('suite-barrio-dashboard-wizard-todo', $inline_js);
});

/* ─────────────────────────────────────────
   DASHBOARD PAGE
   ───────────────────────────────────────── */
function suite_barrio_dashboard_page()
{
    global $wpdb;
    $graphql_active = function_exists('register_graphql_field');

    if (suite_barrio_tools_module_enabled('auto_alt')) {
        $total_images = array_sum((array) wp_count_attachments());
        $missing_count = suite_barrio_count_incomplete();
        $done_count = max(0, $total_images - $missing_count);
        $provider = get_option('suite_barrio_alt_ai_provider', 'anthropic');
        $provider_name = $provider === 'gemini' ? 'Google Gemini' : 'Anthropic Claude';
        $auto_upload = get_option('suite_barrio_alt_ai_auto_upload', 0);
    }

    if (suite_barrio_tools_module_enabled('forms')) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $forms_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpb_forms");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $subs_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpb_form_submissions");
    }

    if (suite_barrio_tools_module_enabled('popups')) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $popups_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpb_popups");
        $popup_active = suite_barrio_popups_get_active();
    }

    if (suite_barrio_tools_module_enabled('banners')) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $banners_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpb_banners");
        $banner_active = function_exists('suite_barrio_banners_get_active') ? suite_barrio_banners_get_active() : null;
    }

    if (suite_barrio_tools_module_enabled('orders')) {
        $orders_settings = get_option(SUITE_BARRIO_ORDERS_OPTION, []);
        $orders_pt_count = count($orders_settings['enabled_post_types'] ?? []);
        $orders_tax_count = count($orders_settings['enabled_taxonomies'] ?? []);
    }

    if (suite_barrio_tools_module_enabled('blocks')) {
        $blocks_s = function_exists('suite_barrio_blocks_get_settings') ? suite_barrio_blocks_get_settings() : [];
        $blocks_enabled = !empty($blocks_s['enabled']);
        $blocks_count = isset($blocks_s['allowed']) && is_array($blocks_s['allowed']) ? count($blocks_s['allowed']) : 0;
    }

    if (suite_barrio_tools_module_enabled('duplicate')) {
        $dup_settings = get_option(SUITE_BARRIO_DUPLICATE_OPTION, []);
        $dup_pt_count = count($dup_settings['enabled_post_types'] ?? []);
        $dup_tax_count = count($dup_settings['enabled_taxonomies'] ?? []);
    }

    if (suite_barrio_tools_module_enabled('stats')) {
        $stats_s = suite_barrio_stats_settings();
        $stats_configured = suite_barrio_stats_is_configured();
        $stats_connected = suite_barrio_stats_is_connected();
        $stats_replace = !empty($stats_s['replace_dashboard']);
    }

    if (suite_barrio_tools_module_enabled('orari')) {
        $orari_data = get_option(SUITE_BARRIO_ORARI_DATA_OPTION, []);
        $orari_settings = get_option(SUITE_BARRIO_ORARI_OPTION, []);
        $orari_next_cron = wp_next_scheduled(SUITE_BARRIO_ORARI_CRON_HOOK);
        $orari_configured = !empty(trim($orari_settings['google_api_key'] ?? ''))
            && !empty(trim($orari_settings['place_id'] ?? ''));
    }

    if (suite_barrio_tools_module_enabled('accessibility'))
        $a11y_s = function_exists('suite_barrio_accessibility_settings') ? suite_barrio_accessibility_settings() : [];

    if (suite_barrio_tools_module_enabled('cookie_privacy')) {
        $cp_domain = function_exists('suite_barrio_cp_get_domain')
            ? suite_barrio_cp_get_domain()
            : (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $cp_privacy_page_id = (int) get_option('suite_barrio_cp_privacy_page_id', 0);
        $cp_cookie_page_id = (int) get_option('suite_barrio_cp_cookie_page_id', 0);
        $cp_provider = get_option('suite_barrio_cp_provider', 'gemini');
        $cp_provider_name = $cp_provider === 'anthropic' ? 'Anthropic Claude' : 'Google Gemini';
        $cp_privacy_page = $cp_privacy_page_id ? get_post($cp_privacy_page_id) : null;
        $cp_cookie_page = $cp_cookie_page_id ? get_post($cp_cookie_page_id) : null;
        $cp_log = get_option('suite_barrio_cp_log', []);
        $cp_configured = !empty($cp_domain)
            && $cp_privacy_page instanceof WP_Post
            && $cp_cookie_page instanceof WP_Post
            && $cp_privacy_page_id !== $cp_cookie_page_id;
    }
    ?>
    <div id="suite-barrio-dash" class="wrap suite-barrio-wrap">

        <div class="suite-barrio-header">
            <div class="suite-barrio-header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="22" height="22" aria-hidden="true">
                    <path fill="#fff"
                        d="M5,5h2.5v2.5H5ZM0,8.75h2.5v2.5H0ZM2.5,11.25h2.5v2.5H2.5ZM5,13.75h10v2.5H5ZM17.5,8.75h2.5v2.5H17.5ZM15,11.25h2.5v2.5H15ZM12.5,5h2.5v2.5H12.5Z" />
                </svg>
            </div>
            <div>
                <h1>Suite Barrio <span class="suite-barrio-version-badge">v<?php echo esc_html(SUITE_BARRIO_VERSION); ?></span></h1>
                <p class="suite-barrio-header-sub">Suite di strumenti AI per WordPress</p>
            </div>
        </div>

        <hr class="suite-barrio-divider">

        <?php if (!function_exists('suite_barrio_wizard_is_complete') || !suite_barrio_wizard_is_complete()): ?>
            <div class="suite-barrio-wizard-banner">
                <div class="suite-barrio-wizard-banner-body">
                    <div class="suite-barrio-wizard-banner-icon">
                        <span class="dashicons dashicons-migrate"></span>
                    </div>
                    <div class="suite-barrio-wizard-banner-text">
                        <strong>Messa online del sito</strong>
                        <span>Segui la guida passo-passo per configurare e pubblicare il sito.</span>
                    </div>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-wizard')); ?>"
                    class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-launch">
                    Inizia la messa online &rarr;
                </a>
            </div>
        <?php endif; ?>

        <?php
        // Alert passaggi saltati / checkbox non completate (solo se wizard terminato)
        if (
            function_exists('suite_barrio_wizard_is_complete') && suite_barrio_wizard_is_complete() &&
            function_exists('suite_barrio_wizard_badge_count') && suite_barrio_wizard_badge_count() > 0
        ):
            $wiz_progress = function_exists('suite_barrio_wizard_get_progress') ? suite_barrio_wizard_get_progress() : [];
            $saved_chks = isset($wiz_progress['step_8_checks']) && is_array($wiz_progress['step_8_checks'])
                ? $wiz_progress['step_8_checks'] : [];
            $dismissed = function_exists('suite_barrio_wizard_get_dismissed') ? suite_barrio_wizard_get_dismissed() : [];

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
            <div class="suite-barrio-wizard-todo" id="suite-barrio-wizard-todo">
                <div class="suite-barrio-wizard-todo-head">
                    <span class="dashicons dashicons-warning"></span>
                    <strong>Messa online — operazioni da completare</strong>
                </div>
                <ul class="suite-barrio-wizard-todo-list">

                    <?php
                    // Step saltati
                    if (function_exists('suite_barrio_wizard_steps')) {
                        foreach (suite_barrio_wizard_steps() as $n => $step) {
                            if ($n === 8)
                                continue;
                            if (($wiz_progress["step_{$n}"] ?? 'pending') !== 'skipped')
                                continue;
                            if (in_array("step_{$n}", $dismissed, true))
                                continue;
                            $url = admin_url('admin.php?page=suite-barrio-wizard&step=' . $n);
                            ?>
                            <li class="suite-barrio-wizard-todo-item" data-alert-key="step_<?php echo esc_attr($n); ?>">
                                <span class="dashicons <?php echo esc_attr($step['icon']); ?>"></span>
                                <span class="suite-barrio-wizard-todo-label">
                                    Passaggio saltato: <strong><?php echo esc_html($step['label']); ?></strong>
                                </span>
                                <a href="<?php echo esc_url($url); ?>" class="suite-barrio-wizard-todo-link">
                                    Configura <span class="dashicons dashicons-arrow-right-alt2"></span>
                                </a>
                                <button type="button" class="suite-barrio-wizard-todo-dismiss" title="Elimina notifica">
                                    <span class="dashicons dashicons-no-alt"></span>
                                </button>
                            </li>
                            <?php
                        }
                    }

                    // Checkbox non spuntate in "Controlli Finali"
                    $wiz_url_7 = admin_url('admin.php?page=suite-barrio-wizard&step=8');
                    foreach ($final_items as $key => $item) {
                        if (in_array($key, $saved_chks, true))
                            continue;
                        if (in_array("final_{$key}", $dismissed, true))
                            continue;
                        ?>
                        <li class="suite-barrio-wizard-todo-item" data-alert-key="final_<?php echo esc_attr($key); ?>">
                            <span class="dashicons <?php echo esc_attr($item['icon']); ?>"></span>
                            <span class="suite-barrio-wizard-todo-label">
                                <?php echo esc_html($item['label']); ?>
                            </span>
                            <a href="<?php echo esc_url($wiz_url_7); ?>" class="suite-barrio-wizard-todo-link">
                                Verifica <span class="dashicons dashicons-arrow-right-alt2"></span>
                            </a>
                            <button type="button" class="suite-barrio-wizard-todo-dismiss" title="Elimina notifica">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>
                        </li>
                        <?php
                    }
                    ?>

                </ul>
            </div>

        <?php endif; ?>

        <?php if (suite_barrio_tools_can_access() && suite_barrio_all_modules_disabled()): ?>
            <div class="suite-barrio-notice">
                <span class="dashicons dashicons-warning"></span>
                <div>
                    <strong>Tutti i moduli sono disattivati.</strong>
                    Suite Barrio non è visibile agli altri utenti amministratori.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-tools')); ?>">Attiva almeno un modulo
                        &rarr;</a>
                </div>
            </div>
        <?php endif; ?>

        <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-info-azienda')); ?>" class="suite-barrio-company-link">
            <div class="suite-barrio-company-link-icon">
                <span class="dashicons dashicons-id-alt"></span>
            </div>
            <div class="suite-barrio-company-link-text">
                <p class="suite-barrio-company-link-title">Info Azienda</p>
                <p class="suite-barrio-company-link-desc">
                    Gestisci ragione sociale, dati fiscali, contatti, indirizzo e social media dell'azienda.
                </p>
            </div>
            <span class="dashicons dashicons-arrow-right-alt2 suite-barrio-company-link-arrow"></span>
        </a>

        <?php if (function_exists('suite_barrio_cache_purge_render')) {
            suite_barrio_cache_purge_render();
        } ?>

        <div class="suite-barrio-grid">

            <?php if (suite_barrio_tools_module_enabled('auto_alt')): ?>
                <div class="suite-barrio-card">
                    <div class="suite-barrio-card-head" style="background:linear-gradient(135deg,#1d4ed8,#2563eb);">
                        <div class="suite-barrio-card-icon"><span class="dashicons dashicons-format-image"></span></div>
                        <div>
                            <div class="suite-barrio-card-title">Auto Alt</div>
                            <div class="suite-barrio-card-subtitle">Generazione automatica alt text &amp; title</div>
                        </div>
                    </div>
                    <div class="suite-barrio-card-body">
                        <table class="suite-barrio-stats">
                            <tr>
                                <td>Immagini totali</td>
                                <td><?php echo number_format($total_images); ?></td>
                            </tr>
                            <tr>
                                <td>Senza alt text</td>
                                <td class="suite-barrio-err"><?php echo number_format($missing_count); ?></td>
                            </tr>
                            <tr>
                                <td>Completate</td>
                                <td class="suite-barrio-ok"><?php echo number_format($done_count); ?></td>
                            </tr>
                            <tr>
                                <td>Provider attivo</td>
                                <td><?php echo esc_html($provider_name); ?></td>
                            </tr>
                            <tr>
                                <td>Auto-generazione</td>
                                <td><?php if ($auto_upload): ?>
                                        <span class="suite-barrio-ok">&#x2713; Attiva</span>
                                    <?php else: ?>
                                        <span class="suite-barrio-mute">Disattiva</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="suite-barrio-card-actions">
                            <a href="?page=suite-barrio-auto-alt&tab=review" class="suite-barrio-btn suite-barrio-btn-primary">Revisione
                                immagini</a>
                            <a href="?page=suite-barrio-auto-alt&tab=settings" class="suite-barrio-btn suite-barrio-btn-ghost">Impostazioni</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (suite_barrio_tools_module_enabled('auto_fields')): ?>
                <div class="suite-barrio-card">
                    <div class="suite-barrio-card-head" style="background:linear-gradient(135deg,#7c3aed,#8b5cf6);">
                        <div class="suite-barrio-card-icon"><span class="dashicons dashicons-layout"></span></div>
                        <div>
                            <div class="suite-barrio-card-title">Auto Fields</div>
                            <div class="suite-barrio-card-subtitle">Genera campi ACF da pagina web</div>
                        </div>
                    </div>
                    <div class="suite-barrio-card-body">
                        <?php $acf_available = function_exists('acf_import_field_group'); ?>
                        <table class="suite-barrio-stats">
                            <tr>
                                <td>Funzionalità</td>
                                <td class="suite-barrio-mute">Analisi HTML → ACF</td>
                            </tr>
                            <tr>
                                <td>ACF</td>
                                <td><?php if ($acf_available): ?>
                                        <span class="suite-barrio-ok">&#x2713; Attivo</span>
                                    <?php else: ?>
                                        <span class="suite-barrio-err">&#x2715; Non installato</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Output</td>
                                <td class="suite-barrio-mute">Campi ACF + GraphQL</td>
                            </tr>
                        </table>
                        <div class="suite-barrio-card-actions">
                            <a href="?page=suite-barrio-auto-fields" class="suite-barrio-btn suite-barrio-btn-primary">Genera campi</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (suite_barrio_tools_module_enabled('blocks')): ?>
                <div class="suite-barrio-card">
                    <div class="suite-barrio-card-head" style="background:linear-gradient(135deg,#0f172a,#1e293b);">
                        <div class="suite-barrio-card-icon"><span class="dashicons dashicons-layout"></span></div>
                        <div>
                            <div class="suite-barrio-card-title">Blocks</div>
                            <div class="suite-barrio-card-subtitle">Visibilità blocchi Gutenberg per ruolo</div>
                        </div>
                    </div>
                    <div class="suite-barrio-card-body">
                        <table class="suite-barrio-stats">
                            <tr>
                                <td>Filtro</td>
                                <td><?php echo $blocks_enabled
                                    ? '<span class="suite-barrio-ok">&#x2713; Attivo</span>'
                                    : '<span class="suite-barrio-mute">Disattivato</span>'; ?></td>
                            </tr>
                            <tr>
                                <td>Blocchi permessi</td>
                                <td><?php echo $blocks_enabled && $blocks_count > 0
                                    ? '<span class="suite-barrio-ok">&#x2713; ' . esc_html($blocks_count) . '</span>'
                                    : '<span class="suite-barrio-mute">Tutti</span>'; ?></td>
                            </tr>
                        </table>
                        <div class="suite-barrio-card-actions">
                            <a href="?page=suite-barrio-blocks" class="suite-barrio-btn suite-barrio-btn-primary">Gestisci blocchi</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (suite_barrio_tools_module_enabled('banners')): ?>
                <div class="suite-barrio-card">
                    <div class="suite-barrio-card-head" style="background:linear-gradient(135deg,#0369a1,#0891b2);">
                        <div class="suite-barrio-card-icon"><span class="dashicons dashicons-flag"></span></div>
                        <div>
                            <div class="suite-barrio-card-title">Banners</div>
                            <div class="suite-barrio-card-subtitle">Banner marquee a scorrimento</div>
                        </div>
                    </div>
                    <div class="suite-barrio-card-body">
                        <table class="suite-barrio-stats">
                            <tr>
                                <td>Banner totali</td>
                                <td><?php echo number_format($banners_count); ?></td>
                            </tr>
                            <tr>
                                <td>Banner attivo</td>
                                <td><?php if ($banner_active): ?>
                                        <span class="suite-barrio-ok">&#x2713; <?php echo esc_html($banner_active->title); ?></span>
                                    <?php else: ?>
                                        <span class="suite-barrio-mute">Nessuno</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>GraphQL</td>
                                <td><?php if ($graphql_active): ?>
                                        <span class="suite-barrio-ok">&#x2713; hbBanner</span>
                                    <?php else: ?>
                                        <span class="suite-barrio-mute">WPGraphQL non attivo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="suite-barrio-card-actions">
                            <a href="?page=suite-barrio-banners" class="suite-barrio-btn suite-barrio-btn-primary">Gestisci banner</a>
                            <a href="?page=suite-barrio-banners&edit_id=new" class="suite-barrio-btn suite-barrio-btn-ghost">+ Nuovo</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (suite_barrio_tools_module_enabled('cookie_privacy')): ?>
                <div class="suite-barrio-card">
                    <div class="suite-barrio-card-head" style="background:linear-gradient(135deg,#0f766e,#14b8a6);">
                        <div class="suite-barrio-card-icon"><span class="dashicons dashicons-shield"></span></div>
                        <div>
                            <div class="suite-barrio-card-title">Cookie e Privacy</div>
                            <div class="suite-barrio-card-subtitle">Generazione documenti GDPR</div>
                        </div>
                    </div>
                    <div class="suite-barrio-card-body">
                        <table class="suite-barrio-stats">
                            <tr>
                                <td>Configurazione</td>
                                <td><?php if ($cp_configured): ?>
                                        <span class="suite-barrio-ok">&#x2713; Pronta</span>
                                    <?php else: ?>
                                        <span class="suite-barrio-warn">Da completare</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Dominio</td>
                                <td><?php echo $cp_domain ? esc_html($cp_domain) : '<span class="suite-barrio-mute">Non impostato</span>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Privacy Policy</td>
                                <td>
                                    <?php if ($cp_privacy_page instanceof WP_Post) { ?>
                                        <a href="<?php echo esc_url(get_the_permalink($cp_privacy_page)); ?>">
                                            <?php echo esc_html(get_the_title($cp_privacy_page)); ?>
                                        </a>
                                    <?php } else {
                                        echo '<span class="suite-barrio-mute">Non selezionata</span>';
                                    } ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Cookie Policy</td>
                                <td>
                                    <?php if ($cp_cookie_page instanceof WP_Post) { ?>
                                        <a href="<?php echo esc_url(get_the_permalink($cp_cookie_page)); ?>">
                                            <?php echo esc_html(get_the_title($cp_cookie_page)); ?>
                                        </a>
                                    <?php } else {
                                        echo '<span class="suite-barrio-mute">Non selezionata</span>';
                                    } ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Provider attivo</td>
                                <td><?php echo esc_html($cp_provider_name); ?></td>
                            </tr>
                        </table>
                        <div class="suite-barrio-card-actions">
                            <a href="?page=suite-barrio-cookie-privacy&tab=generate" class="suite-barrio-btn suite-barrio-btn-primary">Genera</a>
                            <a href="?page=suite-barrio-cookie-privacy&tab=settings" class="suite-barrio-btn suite-barrio-btn-ghost">Impostazioni</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (suite_barrio_tools_module_enabled('duplicate')): ?>
                <div class="suite-barrio-card">
                    <div class="suite-barrio-card-head" style="background:linear-gradient(135deg,#0f766e,#0d9488);">
                        <div class="suite-barrio-card-icon"><span class="dashicons dashicons-admin-page"></span></div>
                        <div>
                            <div class="suite-barrio-card-title">Duplicate</div>
                            <div class="suite-barrio-card-subtitle">Duplicazione rapida di post e termini</div>
                        </div>
                    </div>
                    <div class="suite-barrio-card-body">
                        <table class="suite-barrio-stats">
                            <tr>
                                <td>Post type abilitati</td>
                                <td><?php echo $dup_pt_count > 0
                                    ? '<span class="suite-barrio-ok">&#x2713; ' . esc_html($dup_pt_count) . '</span>'
                                    : '<span class="suite-barrio-mute">Nessuno</span>'; ?></td>
                            </tr>
                            <tr>
                                <td>Tassonomie abilitate</td>
                                <td><?php echo $dup_tax_count > 0
                                    ? '<span class="suite-barrio-ok">&#x2713; ' . esc_html($dup_tax_count) . '</span>'
                                    : '<span class="suite-barrio-mute">Nessuna</span>'; ?></td>
                            </tr>
                        </table>
                        <div class="suite-barrio-card-actions">
                            <a href="?page=suite-barrio-duplicate" class="suite-barrio-btn suite-barrio-btn-primary">Configura duplicazione</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (suite_barrio_tools_module_enabled('forms')): ?>
                <div class="suite-barrio-card">
                    <div class="suite-barrio-card-head" style="background:linear-gradient(135deg,#059669,#10b981);">
                        <div class="suite-barrio-card-icon"><span class="dashicons dashicons-feedback"></span></div>
                        <div>
                            <div class="suite-barrio-card-title">Forms</div>
                            <div class="suite-barrio-card-subtitle">Builder drag &amp; drop per form di contatto</div>
                        </div>
                    </div>
                    <div class="suite-barrio-card-body">
                        <table class="suite-barrio-stats">
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
                                <td class="suite-barrio-mute">Messaggio o redirect</td>
                            </tr>
                            <tr>
                                <td>GraphQL</td>
                                <td><?php if ($graphql_active): ?>
                                        <span class="suite-barrio-ok">&#x2713; Attivo</span>
                                    <?php else: ?>
                                        <a href="<?php echo esc_url(admin_url('plugin-install.php?s=wpgraphql&tab=search&type=term')); ?>"
                                            class="suite-barrio-err" style="text-decoration:none;font-size:11px;">&#x2715; Non
                                            installato</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="suite-barrio-card-actions">
                            <a href="?page=suite-barrio-forms" class="suite-barrio-btn suite-barrio-btn-primary">Gestisci form</a>
                            <a href="?page=suite-barrio-forms&tab=settings" class="suite-barrio-btn suite-barrio-btn-ghost">Impostazioni</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (suite_barrio_tools_module_enabled('orari')): ?>
                <div class="suite-barrio-card">
                    <div class="suite-barrio-card-head" style="background:linear-gradient(135deg,#9a3412,#c2410c);">
                        <div class="suite-barrio-card-icon"><span class="dashicons dashicons-clock"></span></div>
                        <div>
                            <div class="suite-barrio-card-title">Orari</div>
                            <div class="suite-barrio-card-subtitle">Sincronizzazione orari da Google Places</div>
                        </div>
                    </div>
                    <div class="suite-barrio-card-body">
                        <table class="suite-barrio-stats">
                            <tr>
                                <td>Credenziali</td>
                                <td><?php if ($orari_configured): ?>
                                        <span class="suite-barrio-ok">&#x2713; Configurate</span>
                                    <?php else: ?>
                                        <span class="suite-barrio-err">&#x2715; Non configurate</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Ultimo aggiornamento</td>
                                <td><?php echo !empty($orari_data['ultimo_aggiornamento'])
                                    ? esc_html($orari_data['ultimo_aggiornamento'])
                                    : '<span class="suite-barrio-mute">Mai</span>'; ?></td>
                            </tr>
                            <tr>
                                <td>Prossimo cron</td>
                                <td><?php echo $orari_next_cron
                                    ? esc_html(date_i18n('d/m H:i', $orari_next_cron + get_option('gmt_offset') * 3600))
                                    : '<span class="suite-barrio-mute">—</span>'; ?></td>
                            </tr>
                            <tr>
                                <td>GraphQL</td>
                                <td><?php if ($graphql_active): ?>
                                        <span class="suite-barrio-ok">&#x2713; hbOrari</span>
                                    <?php else: ?>
                                        <span class="suite-barrio-mute">WPGraphQL non attivo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="suite-barrio-card-actions">
                            <a href="?page=suite-barrio-orari" class="suite-barrio-btn suite-barrio-btn-primary">Gestisci orari</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (suite_barrio_tools_module_enabled('orders')): ?>
                <div class="suite-barrio-card">
                    <div class="suite-barrio-card-head" style="background:linear-gradient(135deg,#5b21b6,#7c3aed);">
                        <div class="suite-barrio-card-icon"><span class="dashicons dashicons-sort"></span></div>
                        <div>
                            <div class="suite-barrio-card-title">Orders</div>
                            <div class="suite-barrio-card-subtitle">Riordino drag &amp; drop di post e tassonomie</div>
                        </div>
                    </div>
                    <div class="suite-barrio-card-body">
                        <table class="suite-barrio-stats">
                            <tr>
                                <td>Post type ordinati</td>
                                <td><?php echo $orders_pt_count > 0
                                    ? '<span class="suite-barrio-ok">&#x2713; ' . esc_html($orders_pt_count) . '</span>'
                                    : '<span class="suite-barrio-mute">Nessuno</span>'; ?></td>
                            </tr>
                            <tr>
                                <td>Tassonomie ordinate</td>
                                <td><?php echo $orders_tax_count > 0
                                    ? '<span class="suite-barrio-ok">&#x2713; ' . esc_html($orders_tax_count) . '</span>'
                                    : '<span class="suite-barrio-mute">Nessuna</span>'; ?></td>
                            </tr>
                        </table>
                        <div class="suite-barrio-card-actions">
                            <a href="?page=suite-barrio-orders" class="suite-barrio-btn suite-barrio-btn-primary">Gestisci ordini</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (suite_barrio_tools_module_enabled('popups')): ?>
                <div class="suite-barrio-card">
                    <div class="suite-barrio-card-head" style="background:linear-gradient(135deg,#6d28d9,#7c3aed);">
                        <div class="suite-barrio-card-icon"><span class="dashicons dashicons-megaphone"></span></div>
                        <div>
                            <div class="suite-barrio-card-title">Popups</div>
                            <div class="suite-barrio-card-subtitle">Gestione popup frontend</div>
                        </div>
                    </div>
                    <div class="suite-barrio-card-body">
                        <table class="suite-barrio-stats">
                            <tr>
                                <td>Popup totali</td>
                                <td><?php echo number_format($popups_count); ?></td>
                            </tr>
                            <tr>
                                <td>Popup attivo</td>
                                <td><?php if ($popup_active): ?>
                                        <span class="suite-barrio-ok">&#x2713; <?php echo esc_html($popup_active->title); ?></span>
                                    <?php else: ?>
                                        <span class="suite-barrio-mute">Nessuno</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>GraphQL</td>
                                <td><?php if ($graphql_active): ?>
                                        <span class="suite-barrio-ok">&#x2713; hbPopup</span>
                                    <?php else: ?>
                                        <span class="suite-barrio-mute">WPGraphQL non attivo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="suite-barrio-card-actions">
                            <a href="?page=suite-barrio-popups" class="suite-barrio-btn suite-barrio-btn-primary">Gestisci popup</a>
                            <a href="?page=suite-barrio-popups&edit_id=new" class="suite-barrio-btn suite-barrio-btn-ghost">+ Nuovo</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (suite_barrio_tools_module_enabled('seo')): ?>
                <div class="suite-barrio-card">
                    <div class="suite-barrio-card-head" style="background:linear-gradient(135deg,#c2410c,#ea580c);">
                        <div class="suite-barrio-card-icon"><span class="dashicons dashicons-search"></span></div>
                        <div>
                            <div class="suite-barrio-card-title">SEO</div>
                            <div class="suite-barrio-card-subtitle">Strumenti per l'ottimizzazione sui motori di ricerca</div>
                        </div>
                    </div>
                    <div class="suite-barrio-card-body">
                        <table class="suite-barrio-stats">
                            <tr>
                                <td>Sotto-strumenti</td>
                                <td><?php
                                $sitemap_on = suite_barrio_tools_module_enabled('seo_sitemap');
                                $count = $sitemap_on ? 6 : 5;
                                echo '<span class="suite-barrio-ok">&#x2713; ' . esc_html($count) . ' attivi</span>';
                                ?></td>
                            </tr>
                            <tr>
                                <td>GraphQL</td>
                                <td><?php if ($graphql_active): ?>
                                        <span class="suite-barrio-ok">&#x2713; Attivo</span>
                                    <?php else: ?>
                                        <span class="suite-barrio-mute">WPGraphQL non attivo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="suite-barrio-card-actions">
                            <a href="?page=suite-barrio-seo" class="suite-barrio-btn suite-barrio-btn-primary">Vai a SEO</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (suite_barrio_tools_module_enabled('accessibility')): ?>
                <div class="suite-barrio-card">
                    <div class="suite-barrio-card-head" style="background:linear-gradient(135deg,#0d9488,#0f766e);">
                        <div class="suite-barrio-card-icon"><span class="dashicons dashicons-universal-access"></span></div>
                        <div>
                            <div class="suite-barrio-card-title">Accessibilità</div>
                            <div class="suite-barrio-card-subtitle">Widget di accessibilità frontend</div>
                        </div>
                    </div>
                    <div class="suite-barrio-card-body">
                        <table class="suite-barrio-stats">
                            <tr>
                                <td>Posizione</td>
                                <td><?php echo isset($a11y_s['position']) && $a11y_s['position'] === 'right'
                                    ? '<span class="suite-barrio-mute">In basso a destra</span>'
                                    : '<span class="suite-barrio-mute">In basso a sinistra</span>'; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Colore principale</td>
                                <td>
                                    <span style="display:inline-flex;align-items:center;gap:6px;">
                                        <span
                                            style="display:inline-block;width:12px;height:12px;border-radius:3px;background:<?php echo esc_attr($a11y_s['accent_color'] ?? '#ea9c17'); ?>;border:1px solid rgba(0,0,0,.1);"></span>
                                        <span
                                            class="suite-barrio-mute"><?php echo esc_html($a11y_s['accent_color'] ?? '#ea9c17'); ?></span>
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
                                            class="suite-barrio-mute"><?php echo esc_html($a11y_s['navy_color'] ?? '#415568'); ?></span>
                                    </span>
                                </td>
                            </tr>
                        </table>
                        <div class="suite-barrio-card-actions">
                            <a href="?page=suite-barrio-accessibility" class="suite-barrio-btn suite-barrio-btn-primary">Impostazioni</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (suite_barrio_tools_module_enabled('stats')): ?>
                <div class="suite-barrio-card">
                    <div class="suite-barrio-card-head" style="background:linear-gradient(135deg,#1a56db,#1967d2);">
                        <div class="suite-barrio-card-icon"><span class="dashicons dashicons-chart-area"></span></div>
                        <div>
                            <div class="suite-barrio-card-title">Statistiche</div>
                            <div class="suite-barrio-card-subtitle">Google Analytics 4</div>
                        </div>
                    </div>
                    <div class="suite-barrio-card-body">
                        <table class="suite-barrio-stats">
                            <tr>
                                <td>Report GA4</td>
                                <td><?php if ($stats_connected): ?>
                                        <span class="suite-barrio-ok">&#x2713; Collegato</span>
                                    <?php elseif ($stats_configured): ?>
                                        <span class="suite-barrio-warn">&#x2713; Configurato, non connesso</span>
                                    <?php else: ?>
                                        <span class="suite-barrio-mute">Non configurato</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Bacheca</td>
                                <td><?php if ($stats_replace && $stats_connected): ?>
                                        <span class="suite-barrio-ok">&#x2713; Sostituita</span>
                                    <?php else: ?>
                                        <span class="suite-barrio-mute">Predefinita</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div class="suite-barrio-card-actions">
                            <a href="?page=suite-barrio-stats" class="suite-barrio-btn suite-barrio-btn-primary">Configura</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
        <?php
}
