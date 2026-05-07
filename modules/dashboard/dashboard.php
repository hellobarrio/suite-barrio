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
   - Se tutti i moduli sono disattivi E l'utente NON è
     support@hellobarrio.it, WP Barrio non appare nel menu.
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    // Nascondi completamente il menu agli utenti normali
    // quando tutti i moduli sono disattivi
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
    // Rimuove la prima sottovoce duplicata che WP genera automaticamente
    remove_submenu_page('wp-barrio', 'wp-barrio');
}, 9); // priorità 9: prima degli altri moduli (10)

/* ─────────────────────────────────────────
   DASHBOARD PAGE
   ───────────────────────────────────────── */
function wp_barrio_dashboard_page()
{
    global $wpdb;
    $graphql_active = function_exists('register_graphql_field');

    // Dati Auto Alt (solo se modulo attivo)
    if (wpb_tools_module_enabled('auto_alt')) {
        $total_images = array_sum((array) wp_count_attachments());
        $missing_count = wp_alt_ai_count_incomplete();
        $done_count = max(0, $total_images - $missing_count);
        $provider = get_option('wp_alt_ai_provider', 'anthropic');
        $provider_name = $provider === 'gemini' ? 'Google Gemini' : 'Anthropic Claude';
        $auto_upload = get_option('wp_alt_ai_auto_upload', 0);
    }

    // Dati Forms (solo se modulo attivo)
    if (wpb_tools_module_enabled('forms')) {
        $forms_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpb_forms");
        $subs_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpb_form_submissions");
    }

    // Dati Popups (solo se modulo attivo)
    if (wpb_tools_module_enabled('popups')) {
        $popups_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpb_popups");
        $popup_active = wpb_popups_get_active();
    }
    // Dati Banners (solo se modulo attivo)
    if (wpb_tools_module_enabled('banners')) {
        $banners_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpb_banners");
        $banner_active = function_exists('wpb_banners_get_active') ? wpb_banners_get_active() : null;
    }
    if (wpb_tools_module_enabled('orari')) {
        $orari_data = get_option(WPB_ORARI_DATA_OPTION, []);
        $orari_settings = get_option(WPB_ORARI_OPTION, []);
        $orari_next_cron = wp_next_scheduled(WPB_ORARI_CRON_HOOK);
        $orari_configured = !empty(trim($orari_settings['google_api_key'] ?? ''))
            && !empty(trim($orari_settings['place_id'] ?? ''));
    }
    ?>
    <div class="wrap">
        <h1>
            <span
                style="width:26px;height:26px;vertical-align:middle;margin-right:6px;display:inline-block;background:url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjIwIiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSIgcm9sZT0iaW1nIj48cGF0aCBmaWxsPSJibGFjayIgZD0iTTUsNWgyLjV2Mi41SDVaTTAsOC43NWgyLjV2Mi41SDBaTTIuNSwxMS4yNWgyLjV2Mi41SDIuNVpNNSwxMy43NWgxMHYyLjVINVpNMTcuNSw4Ljc1aDIuNXYyLjVIMTcuNVpNMTUsMTEuMjVoMi41djIuNUgxNVpNMTIuNSw1aDIuNXYyLjVIMTIuNVoiLz48L3N2Zz4=') center/contain no-repeat;filter:invert(34%) sepia(72%) saturate(400%) hue-rotate(190deg);"></span>
            WP Barrio
        </h1>
        <p class="description" style="font-size:14px;margin:4px 0 24px;">Suite di strumenti AI per WordPress —
            <strong>v<?php echo WP_ALT_AI_VERSION; ?></strong>
        </p>

        <?php if (wpb_tools_can_access() && wpb_all_modules_disabled()): ?>
            <div class="notice notice-warning"
                style="margin:0 0 24px;padding:12px 16px;display:flex;align-items:center;gap:12px;">
                <span class="dashicons dashicons-warning"
                    style="font-size:22px;width:22px;height:22px;color:#dba617;flex-shrink:0;"></span>
                <div>
                    <strong>Tutti i moduli sono disattivati.</strong>
                    WP Barrio non è visibile agli altri utenti amministratori.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-tools')); ?>">Attiva almeno un modulo
                        &rarr;</a>
                </div>
            </div>
        <?php endif; ?>


        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:32px;">

            <?php if (wpb_tools_module_enabled('auto_alt')): ?>
                <!-- Card Auto Alt -->
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                    <div style="background:#2271b1;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                        <span class="dashicons dashicons-format-image"
                            style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                        <div>
                            <div style="font-size:16px;font-weight:600;color:#fff;">Auto Alt</div>
                            <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">Generazione automatica alt
                                text &amp; title</div>
                        </div>
                    </div>
                    <div style="padding:16px 20px;">
                        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Immagini totali</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php echo number_format($total_images); ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#d63638;">Senza alt text</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;color:#d63638;text-align:right;">
                                    <?php echo number_format($missing_count); ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#00a32a;">Completate</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;color:#00a32a;text-align:right;">
                                    <?php echo number_format($done_count); ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Provider attivo</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php echo esc_html($provider_name); ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Auto-generazione</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php if ($auto_upload): ?>
                                        <span style="color:#00a32a;">&#x2713; Attiva</span>
                                    <?php else: ?>
                                        <span style="color:#646970;">Disattiva</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div style="display:flex;gap:8px;">
                            <a href="?page=wp-barrio-auto-alt&tab=review" class="button button-primary"
                                style="flex:1;text-align:center;">
                                Revisione immagini
                            </a>
                            <a href="?page=wp-barrio-auto-alt&tab=settings" class="button" style="flex:1;text-align:center;">
                                Impostazioni
                            </a>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('forms')): ?>
                <!-- Card Forms -->
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                    <div style="background:#00a32a;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                        <span class="dashicons dashicons-feedback"
                            style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                        <div>
                            <div style="font-size:16px;font-weight:600;color:#fff;">Forms</div>
                            <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">Builder drag &amp; drop per
                                form di contatto</div>
                        </div>
                    </div>
                    <div style="padding:16px 20px;">
                        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Form attivi</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php echo number_format($forms_count); ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Registrazioni totali ricevute</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php echo number_format($subs_count); ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Dopo l'invio</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;color:#646970;">
                                    Messaggio o redirect</td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">GraphQL</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php if ($graphql_active): ?>
                                        <span style="color:#00a32a;">&#x2713; Attivo</span>
                                    <?php else: ?>
                                        <a href="<?php echo esc_url(admin_url('plugin-install.php?s=wpgraphql&tab=search&type=term')); ?>"
                                            style="color:#d63638;text-decoration:none;font-size:11px;">
                                            ✕ WPGraphQL non installato
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div style="display:flex;gap:8px;">
                            <a href="?page=wp-barrio-forms" class="button button-primary" style="flex:1;text-align:center;">
                                Gestisci form
                            </a>
                            <a href="?page=wp-barrio-forms&tab=settings" class="button" style="flex:1;text-align:center;">
                                Impostazioni
                            </a>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
            <?php if (wpb_tools_module_enabled('auto_fields')): ?>
                <!-- Card Auto Fields -->
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                    <div style="background:#8c5dce;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                        <span class="dashicons dashicons-layout"
                            style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                        <div>
                            <div style="font-size:16px;font-weight:600;color:#fff;">Auto Fields</div>
                            <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">Genera campi ACF da pagina
                                web</div>
                        </div>
                    </div>
                    <div style="padding:16px 20px;">
                        <?php $acf_available = function_exists('acf_import_field_group'); ?>
                        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Funzionalità</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">Analisi HTML → ACF
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">ACF</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php if ($acf_available): ?>
                                        <span style="color:#00a32a;">&#x2713; Attivo</span>
                                    <?php else: ?>
                                        <span style="color:#d63638;">✕ Non installato</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Output</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">Campi ACF + GraphQL
                                </td>
                            </tr>
                        </table>
                        <div style="display:flex;gap:8px;">
                            <a href="?page=wp-barrio-auto-fields" class="button button-primary"
                                style="flex:1;text-align:center;">
                                Genera campi
                            </a>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('popups')): ?>
                <!-- Card Popups -->
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                    <div style="background:#7c3aed;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                        <span class="dashicons dashicons-megaphone"
                            style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                        <div>
                            <div style="font-size:16px;font-weight:600;color:#fff;">Popups</div>
                            <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">Gestione popup frontend
                            </div>
                        </div>
                    </div>
                    <div style="padding:16px 20px;">
                        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Popup totali</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php echo number_format($popups_count); ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Popup attivo</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php if ($popup_active): ?>
                                        <span style="color:#00a32a;">&#x2713; <?php echo esc_html($popup_active->title); ?></span>
                                    <?php else: ?>
                                        <span style="color:#a7aaad;">Nessuno</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">GraphQL</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php if ($graphql_active): ?>
                                        <span style="color:#00a32a;">&#x2713; hbPopup</span>
                                    <?php else: ?>
                                        <span style="color:#646970;">WPGraphQL non attivo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div style="display:flex;gap:8px;">
                            <a href="?page=wp-barrio-popups" class="button button-primary" style="flex:1;text-align:center;">
                                Gestisci popup
                            </a>
                            <a href="?page=wp-barrio-popups&edit_id=new" class="button" style="flex:1;text-align:center;">
                                + Nuovo
                            </a>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('banners')): ?>
                <!-- Card Banners -->
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                    <div style="background:#0891b2;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                        <span class="dashicons dashicons-flag"
                            style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                        <div>
                            <div style="font-size:16px;font-weight:600;color:#fff;">Banners</div>
                            <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">Banner marquee a scorrimento
                            </div>
                        </div>
                    </div>
                    <div style="padding:16px 20px;">
                        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Banner totali</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php echo number_format($banners_count); ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Banner attivo</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php if ($banner_active): ?>
                                        <span style="color:#00a32a;">&#x2713; <?php echo esc_html($banner_active->title); ?></span>
                                    <?php else: ?>
                                        <span style="color:#a7aaad;">Nessuno</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">GraphQL</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php if ($graphql_active): ?>
                                        <span style="color:#00a32a;">&#x2713; hbBanner</span>
                                    <?php else: ?>
                                        <span style="color:#646970;">WPGraphQL non attivo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div style="display:flex;gap:8px;">
                            <a href="?page=wp-barrio-banners" class="button button-primary" style="flex:1;text-align:center;">
                                Gestisci banner
                            </a>
                            <a href="?page=wp-barrio-banners&edit_id=new" class="button" style="flex:1;text-align:center;">
                                + Nuovo
                            </a>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('seo')): ?>
            <!-- Card SEO -->
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                <div style="background:#e6600d;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                    <span class="dashicons dashicons-search"
                        style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                    <div>
                        <div style="font-size:16px;font-weight:600;color:#fff;">SEO</div>
                        <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">Strumenti per l'ottimizzazione sui motori di ricerca</div>
                    </div>
                </div>
                <div style="padding:16px 20px;">
                    <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                        <tr>
                            <td style="padding:6px 0;font-size:13px;color:#646970;">Sotto-strumenti</td>
                            <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                <?php
                        $seo_sub_keys = ['global_seo', 'sitemap', 'schema', 'redirects', 'seo_advisor', 'auto-llms'];
                                $seo_active_count = 0;
                                foreach ($seo_sub_keys as $k) {
                                    if (wpb_tools_module_enabled($k)) $seo_active_count++;
                                }
                                echo $seo_active_count > 0
                                    ? '<span style="color:#00a32a;">&#x2713; ' . $seo_active_count . ' attiv' . ($seo_active_count === 1 ? 'o' : 'i') . '</span>'
                                    : '<span style="color:#646970;">In sviluppo</span>';
                                ?>
                            </td>
                        </tr>
                        <tr style="border-top:1px solid #f0f0f1;">
                            <td style="padding:6px 0;font-size:13px;color:#646970;">GraphQL</td>
                            <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                <?php if ($graphql_active): ?>
                                    <span style="color:#00a32a;">&#x2713; Attivo</span>
                                <?php else: ?>
                                    <span style="color:#646970;">WPGraphQL non attivo</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <div style="display:flex;gap:8px;">
                        <a href="?page=wp-barrio-seo" class="button button-primary" style="flex:1;text-align:center;">
                            Vai a SEO
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; // seo ?>

            <?php if (wpb_tools_module_enabled('orari')): ?>
                <!-- Card Orari -->
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                    <div style="background:#c2410c;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                        <span class="dashicons dashicons-clock"
                            style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                        <div>
                            <div style="font-size:16px;font-weight:600;color:#fff;">Orari</div>
                            <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">Sincronizzazione orari da
                                Google Places</div>
                        </div>
                    </div>
                    <div style="padding:16px 20px;">
                        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Credenziali</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php if ($orari_configured): ?>
                                        <span style="color:#00a32a;">&#x2713; Configurate</span>
                                    <?php else: ?>
                                        <span style="color:#d63638;">✕ Non configurate</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Ultimo aggiornamento</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php echo !empty($orari_data['ultimo_aggiornamento'])
                                        ? esc_html($orari_data['ultimo_aggiornamento'])
                                        : '<span style="color:#646970;">Mai</span>'; ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Prossimo cron</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php echo $orari_next_cron
                                        ? esc_html(date_i18n('d/m H:i', $orari_next_cron + get_option('gmt_offset') * 3600))
                                        : '<span style="color:#646970;">—</span>'; ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">GraphQL</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php if ($graphql_active): ?>
                                        <span style="color:#00a32a;">&#x2713; hbOrari</span>
                                    <?php else: ?>
                                        <span style="color:#646970;">WPGraphQL non attivo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div style="display:flex;gap:8px;">
                            <a href="?page=wp-barrio-orari" class="button button-primary" style="flex:1;text-align:center;">
                                Gestisci orari
                            </a>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
    <?php
}
