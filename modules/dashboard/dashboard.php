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

            <?php if (wpb_tools_module_enabled('auto_llms')): ?>
                <!-- Card Auto LLMs -->
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                    <div style="background:#0e7490;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                        <span class="dashicons dashicons-text-page"
                            style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                        <div>
                            <div style="font-size:16px;font-weight:600;color:#fff;">Auto LLMs</div>
                            <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">Genera llms.txt e
                                llms-full.txt per la GEO</div>
                        </div>
                    </div>
                    <div style="padding:16px 20px;">
                        <?php
                        $llms_short_exists = file_exists(ABSPATH . 'llms.txt');
                        $llms_full_exists = file_exists(ABSPATH . 'llms-full.txt');
                        $llms_provider = get_option('wpb_llms_provider', 'anthropic');
                        $llms_provider_name = $llms_provider === 'gemini' ? 'Google Gemini' : 'Anthropic Claude';
                        $llms_last = get_option('wpb_llms_last_generated', null);
                        ?>
                        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#646970;">llms.txt</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php if ($llms_short_exists): ?>
                                        <span style="color:#00a32a;">&#x2713; Presente
                                            (<?php echo size_format(filesize(ABSPATH . 'llms.txt')); ?>)</span>
                                    <?php else: ?>
                                        <span style="color:#646970;">Non generato</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">llms-full.txt</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php if ($llms_full_exists): ?>
                                        <span style="color:#00a32a;">&#x2713; Presente
                                            (<?php echo size_format(filesize(ABSPATH . 'llms-full.txt')); ?>)</span>
                                    <?php else: ?>
                                        <span style="color:#646970;">Non generato</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Provider attivo</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php echo esc_html($llms_provider_name); ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Ultima generazione</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php echo $llms_last ? date_i18n('d/m/Y H:i', $llms_last['ts']) : '—'; ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">GraphQL</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php if ($graphql_active): ?>
                                        <span style="color:#00a32a;">&#x2713; hbLlms · hbLlmsFull</span>
                                    <?php else: ?>
                                        <span style="color:#646970;">WPGraphQL non attivo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <div style="display:flex;gap:8px;">
                            <a href="?page=wp-barrio-auto-llms&tab=generate" class="button button-primary"
                                style="flex:1;text-align:center;">
                                Genera
                            </a>
                            <a href="?page=wp-barrio-auto-llms&tab=settings" class="button" style="flex:1;text-align:center;">
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

        </div>
    </div>
    <?php
}
