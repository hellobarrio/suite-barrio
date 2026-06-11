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
    ?>
        <style>
            /* ── WP Barrio Dashboard v7 ── */
            #wpb-dash {
                font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", sans-serif;
                max-width: 1200px;
            }

            /* Header */
            .wpb-header {
                display: flex;
                align-items: center;
                gap: 14px;
                margin-bottom: 8px;
            }

            .wpb-header-icon {
                width: 40px;
                height: 40px;
                background: #111827;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .wpb-header-icon svg {
                display: block;
            }

            .wpb-header h1 {
                font-size: 22px !important;
                font-weight: 700 !important;
                color: #111827 !important;
                margin: 0 !important;
                padding: 0 !important;
                line-height: 1.2 !important;
            }

            .wpb-header-sub {
                font-size: 13px;
                color: #6b7280;
                margin: 0;
            }

            .wpb-version-badge {
                display: inline-flex;
                align-items: center;
                background: #f3f4f6;
                border: 1px solid #e5e7eb;
                border-radius: 20px;
                padding: 2px 10px;
                font-size: 11px;
                font-weight: 600;
                color: #374151;
                letter-spacing: .3px;
                margin-left: 8px;
                vertical-align: middle;
            }

            .wpb-divider {
                border: none;
                border-top: 1px solid #f3f4f6;
                margin: 16px 0 28px;
            }

            /* Grid */
            .wpb-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
                gap: 16px;
                margin-bottom: 40px;
            }

            /* Card */
            .wpb-card {
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
                transition: box-shadow .18s, transform .18s;
            }

            .wpb-card:hover {
                box-shadow: 0 4px 16px rgba(0, 0, 0, .10);
                transform: translateY(-1px);
            }

            /* Card header */
            .wpb-card-head {
                padding: 18px 22px;
                display: flex;
                align-items: center;
                gap: 14px;
            }

            .wpb-card-icon {
                width: 42px;
                height: 42px;
                background: rgba(255, 255, 255, .18);
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .wpb-card-icon .dashicons {
                font-size: 22px;
                width: 22px;
                height: 22px;
                color: #fff;
            }

            .wpb-card-title {
                font-size: 15px;
                font-weight: 700;
                color: #fff;
                line-height: 1.2;
            }

            .wpb-card-subtitle {
                font-size: 11.5px;
                color: rgba(255, 255, 255, .75);
                margin-top: 2px;
                line-height: 1.3;
            }

            /* Card body */
            .wpb-card-body {
                padding: 20px 22px;
            }

            /* Stats table */
            .wpb-stats {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 18px;
            }

            .wpb-stats tr+tr td {
                border-top: 1px solid #f3f4f6;
            }

            .wpb-stats td {
                padding: 7px 0;
                font-size: 13px;
                line-height: 1.4;
            }

            .wpb-stats td:first-child {
                color: #6b7280;
            }

            .wpb-stats td:last-child {
                text-align: right;
                font-weight: 600;
                color: #111827;
            }

            /* Status badges */
            .wpb-ok {
                color: #059669 !important;
            }

            .wpb-warn {
                color: #d97706 !important;
            }

            .wpb-err {
                color: #dc2626 !important;
            }

            .wpb-mute {
                color: #9ca3af !important;
            }

            /* Card actions */
            .wpb-card-actions {
                display: flex;
                gap: 8px;
            }

            .wpb-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex: 1;
                padding: 7px 14px;
                font-size: 12.5px;
                font-weight: 600;
                border-radius: 7px;
                text-decoration: none !important;
                cursor: pointer;
                border: none;
                transition: opacity .15s, background .15s;
                line-height: 1.4;
            }

            .wpb-btn:hover {
                opacity: .85;
            }

            .wpb-btn-primary {
                background: #111827;
                color: #fff !important;
            }

            .wpb-btn-ghost {
                background: #f3f4f6;
                color: #374151 !important;
                border: 1px solid #e5e7eb;
            }

            /* Notice */
            .wpb-notice {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                background: #fffbeb;
                border: 1px solid #fde68a;
                border-radius: 10px;
                padding: 14px 18px;
                margin-bottom: 24px;
                font-size: 13px;
                color: #92400e;
                line-height: 1.5;
            }

            .wpb-notice .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
                flex-shrink: 0;
                color: #d97706;
                margin-top: 1px;
            }

            .wpb-notice a {
                color: #b45309;
            }
        </style>

        <div id="wpb-dash" class="wrap">

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