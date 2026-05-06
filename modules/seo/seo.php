<?php
/**
 * WP Barrio — SEO
 * Pagina SEO del plugin con le card dei sotto-strumenti.
 * Struttura analoga alla dashboard principale.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   SOTTO-STRUMENTI SEO
   ───────────────────────────────────────── */
// I sotto-strumenti sono attivi solo se anche SEO è attivo
if (wpb_tools_module_enabled('seo')) {
    require_once WP_ALT_AI_PLUGIN_DIR . 'modules/seo/global-seo.php';

    if (wpb_tools_module_enabled('seo_sitemap'))
        require_once WP_ALT_AI_PLUGIN_DIR . 'modules/seo/sitemap.php';

    require_once WP_ALT_AI_PLUGIN_DIR . 'modules/seo/schema.php';
    require_once WP_ALT_AI_PLUGIN_DIR . 'modules/seo/redirects.php';
    require_once WP_ALT_AI_PLUGIN_DIR . 'modules/seo/seo-advisor.php';
    require_once WP_ALT_AI_PLUGIN_DIR . 'modules/seo/auto-llms.php';
}

/* ─────────────────────────────────────────
   MENU ADMIN: sottovoce SEO sotto WP Barrio
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!wpb_tools_module_enabled('seo'))
        return;

    add_submenu_page(
        'wp-barrio',
        'SEO — WP Barrio',
        'SEO',
        'manage_options',
        'wp-barrio-seo',
        'wpb_seo_page'
    );
}, 11); // priority 11: appare subito dopo la dashboard (9) e prima degli altri moduli (10+)

/* ─────────────────────────────────────────
   PAGINA SEO
   ───────────────────────────────────────── */
function wpb_seo_page()
{
    // ── Dati per ogni sotto-strumento SEO (aggiungere qui le variabili
    //    man mano che i moduli vengono creati) ──────────────────────────
    ?>
    <div class="wrap">
        <h1>
            <span
                style="width:26px;height:26px;vertical-align:middle;margin-right:6px;display:inline-block;background:url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjIwIiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSIgcm9sZT0iaW1nIj48cGF0aCBmaWxsPSJibGFjayIgZD0iTTUsNWgyLjV2Mi41SDVaTTAsOC43NWgyLjV2Mi41SDBaTTIuNSwxMS4yNWgyLjV2Mi41SDIuNVpNNSwxMy43NWgxMHYyLjVINVpNMTcuNSw4Ljc1aDIuNXYyLjVIMTcuNVpNMTUsMTEuMjVoMi41djIuNUgxNVpNMTIuNSw1aDIuNXYyLjVIMTIuNVoiLz48L3N2Zz4=') center/contain no-repeat;filter:invert(34%) sepia(72%) saturate(400%) hue-rotate(190deg);"></span>
            WP Barrio
            <span style="font-size:15px;font-weight:400;color:#646970;margin-left:4px;">/ SEO</span>
        </h1>
        <p class="description" style="font-size:14px;margin:4px 0 24px;">
            Strumenti SEO per ottimizzare la visibilità del sito —
            <strong>v<?php echo WP_ALT_AI_VERSION; ?></strong>
        </p>

        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:32px;">

            <?php
            // ── Aggiungi nuovi sotto-strumenti qui, con lo stesso pattern ─
        
            $any_seo_sub_active = false;
            ?>

            <?php if (wpb_tools_module_enabled('seo_meta')):
                $any_seo_sub_active = true; ?>
                <!-- Card Seo Globale -->
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                    <div style="background:#e6600d;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                        <span class="dashicons dashicons-search"
                            style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                        <div>
                            <div style="font-size:16px;font-weight:600;color:#fff;">Seo Globale</div>
                            <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">Title, description, robots e
                                Open Graph</div>
                        </div>
                    </div>
                    <div style="padding:16px 20px;">
                        <?php
                        $s = get_option(WPB_META_OPTION, []);
                        $sep = $s['separator'] ?? '—';
                        $og_en = !empty($s['og_enabled']);
                        $tw_en = !empty($s['twitter_enabled']);
                        $can_en = !empty($s['canonical_enabled'] ?? 1);
                        $og_img = (int) ($s['og_image_id'] ?? 0);
                        $content = get_option(WPB_META_CONTENT_OPTION, []);
                        $pt_count = count(array_filter($content['post_types'] ?? [], fn($c) => !empty($c['index'])));
                        $tax_count = count(array_filter($content['taxonomies'] ?? [], fn($c) => !empty($c['index'])));
                        ?>
                        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Separatore titolo</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <code><?php echo esc_html($sep); ?></code>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Post type indicizzabili</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php echo $pt_count ?: 0; ?>
                                </td>
                            </tr>
                            <tr style="border-top:1px solid #f0f0f1;">
                                <td style="padding:6px 0;font-size:13px;color:#646970;">Tassonomie indicizzabili</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php echo $tax_count ?: 0; ?>
                                </td>
                            </tr>
                        </table>
                        <div style="display:flex;gap:8px;">
                            <a href="?page=wp-barrio-global-seo&tab=content" class="button button-primary"
                                style="flex:1;text-align:center;">
                                Contenuti
                            </a>
                            <a href="?page=wp-barrio-global-seo&tab=settings" class="button" style="flex:1;text-align:center;">
                                Impostazioni
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('seo_sitemap')):
                $any_seo_sub_active = true;
                $sm_entries = function_exists('wpb_sitemap_build_entries') ? wpb_sitemap_build_entries() : [];
                $sm_total = count($sm_entries);
                ?>
                <!-- Card Sitemap -->
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                    <div style="background:#e6600d;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                        <span class="dashicons dashicons-media-document"
                            style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                        <div>
                            <div style="font-size:16px;font-weight:600;color:#fff;">Sitemap</div>
                            <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">Gestione della sitemap XML
                            </div>
                        </div>
                    </div>
                    <div style="padding:16px 20px;">
                        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                            <tr>
                                <td style="padding:6px 0;font-size:13px;color:#646970;">URL totali</td>
                                <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                    <?php echo $sm_total; ?></td>
                            </tr>
                        </table>
                        <div style="display:flex;gap:8px;">
                            <a href="?page=wp-barrio-sitemap" class="button button-primary" style="flex:1;text-align:center;">
                                Gestisci
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php
            // ── Card Schema ─────────────────────────────────────────────
            $any_seo_sub_active = true;
            $schema_s = get_option(WPB_SCHEMA_OPTION, []);
            $schema_ok = !empty($schema_s['business_name']) || !empty($schema_s['url']);
            ?>
            <!-- Card Schema -->
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                <div style="background:#e6600d;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                    <span class="dashicons dashicons-networking"
                        style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                    <div>
                        <div style="font-size:16px;font-weight:600;color:#fff;">Schema</div>
                        <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">JSON-LD strutturato per
                            tutti i contenuti</div>
                    </div>
                </div>
                <div style="padding:16px 20px;">
                    <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                        <tr>
                            <td style="padding:6px 0;font-size:13px;color:#646970;">Tipo business</td>
                            <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                <?php echo esc_html($schema_s['business_type'] ?? '—'); ?>
                            </td>
                        </tr>
                        <tr style="border-top:1px solid #f0f0f1;">
                            <td style="padding:6px 0;font-size:13px;color:#646970;">Configurato</td>
                            <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                <?php echo $schema_ok
                                    ? '<span style="color:#00a32a;">&#x2713; Sì</span>'
                                    : '<span style="color:#646970;">Non ancora</span>'; ?>
                            </td>
                        </tr>
                    </table>
                    <div style="display:flex;gap:8px;">
                        <a href="?page=wp-barrio-schema" class="button button-primary" style="flex:1;text-align:center;">
                            Configura
                        </a>
                    </div>
                </div>
            </div>

            <?php
            // ── Card Redirects ──────────────────────────────────────────
            $any_seo_sub_active = true;
            $rd_rows = function_exists('wpb_redirects_get_all') ? wpb_redirects_get_all() : [];
            $rd_total = count($rd_rows);
            $rd_301 = count(array_filter($rd_rows, fn($r) => ((int) ($r['code'] ?? 301)) === 301));
            ?>
            <!-- Card Redirects -->
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                <div style="background:#e6600d;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                    <span class="dashicons dashicons-randomize"
                        style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                    <div>
                        <div style="font-size:16px;font-weight:600;color:#fff;">Redirects</div>
                        <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">Gestione redirect per il
                            frontend headless</div>
                    </div>
                </div>
                <div style="padding:16px 20px;">
                    <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                        <tr>
                            <td style="padding:6px 0;font-size:13px;color:#646970;">Redirect totali</td>
                            <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                <?php echo $rd_total; ?></td>
                        </tr>
                        <tr style="border-top:1px solid #f0f0f1;">
                            <td style="padding:6px 0;font-size:13px;color:#646970;">301 Permanenti</td>
                            <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                <?php echo $rd_301; ?></td>
                        </tr>
                    </table>
                    <div style="display:flex;gap:8px;">
                        <a href="?page=wp-barrio-redirects" class="button button-primary" style="flex:1;text-align:center;">
                            Gestisci
                        </a>
                    </div>
                </div>
            </div>

            <?php
            // ── Card SEO Advisor ────────────────────────────────────────
            $any_seo_sub_active = true;
            $adv_key_ok = !empty(wpb_advisor_active_key());
            $adv_log = get_option(WPB_ADVISOR_LOG_OPTION, []);
            $adv_success = count(array_filter($adv_log, fn($e) => ($e['status'] ?? '') === 'success'));
            ?>
            <!-- Card SEO Advisor -->
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                <div style="background:#e6600d;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                    <span class="dashicons dashicons-chart-line"
                        style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                    <div>
                        <div style="font-size:16px;font-weight:600;color:#fff;">SEO Advisor</div>
                        <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">Genera meta SEO con AI</div>
                    </div>
                </div>
                <div style="padding:16px 20px;">
                    <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                        <tr>
                            <td style="padding:6px 0;font-size:13px;color:#646970;">Chiave API</td>
                            <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                <?php if ($adv_key_ok): ?>
                                    <span style="color:#00a32a;">&#x2713; Configurata</span>
                                <?php else: ?>
                                    <span style="color:#d63638;">Non configurata</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr style="border-top:1px solid #f0f0f1;">
                            <td style="padding:6px 0;font-size:13px;color:#646970;">Generazioni riuscite</td>
                            <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                <?php echo $adv_success; ?></td>
                        </tr>
                    </table>
                    <div style="display:flex;gap:8px;">
                        <a href="?page=wp-barrio-seo-advisor&tab=generate" class="button button-primary"
                            style="flex:1;text-align:center;">
                            Genera
                        </a>
                        <a href="?page=wp-barrio-seo-advisor&tab=settings" class="button" style="flex:1;text-align:center;">
                            Impostazioni
                        </a>
                    </div>
                </div>
            </div>

            <?php
            // ── Card Auto LLMs ──────────────────────────────────────────
            $any_seo_sub_active = true;
            $llms_key_ok = function_exists('wpb_llms_active_key') && !empty(wpb_llms_active_key());
            $llms_cache = get_option('wpb_llms_last_generated', []);
            $llms_last = !empty($llms_cache['time']) ? date_i18n('d/m/Y H:i', $llms_cache['time']) : '—';
            ?>
            <!-- Card Auto LLMs -->
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:0;width:360px;overflow:hidden;">
                <div style="background:#e6600d;padding:16px 20px;display:flex;align-items:center;gap:12px;">
                    <span class="dashicons dashicons-text-page"
                        style="font-size:28px;width:28px;height:28px;color:#fff;flex-shrink:0;"></span>
                    <div>
                        <div style="font-size:16px;font-weight:600;color:#fff;">Auto LLMs</div>
                        <div style="font-size:12px;color:rgba(255,255,255,.75);margin-top:2px;">Generazione llms.txt e
                            llms-full.txt</div>
                    </div>
                </div>
                <div style="padding:16px 20px;">
                    <table style="width:100%;border-collapse:collapse;margin-bottom:16px;">
                        <tr>
                            <td style="padding:6px 0;font-size:13px;color:#646970;">Chiave API</td>
                            <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                <?php if ($llms_key_ok): ?>
                                    <span style="color:#00a32a;">&#x2713; Configurata</span>
                                <?php else: ?>
                                    <span style="color:#d63638;">Non configurata</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr style="border-top:1px solid #f0f0f1;">
                            <td style="padding:6px 0;font-size:13px;color:#646970;">Ultima generazione</td>
                            <td style="padding:6px 0;font-size:13px;font-weight:600;text-align:right;">
                                <?php echo esc_html($llms_last); ?></td>
                        </tr>
                    </table>
                    <div style="display:flex;gap:8px;">
                        <a href="?page=wp-barrio-auto-llms" class="button button-primary" style="flex:1;text-align:center;">
                            Gestisci
                        </a>
                    </div>
                </div>
            </div>

            <?php if (!$any_seo_sub_active):
                ?>
                <div
                    style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:32px 28px;width:100%;max-width:520px;">
                    <div style="display:flex;align-items:flex-start;gap:16px;">
                        <span class="dashicons dashicons-search"
                            style="font-size:36px;width:36px;height:36px;color:#2271b1;flex-shrink:0;margin-top:2px;"></span>
                        <div>
                            <h2 style="font-size:15px;font-weight:600;color:#1d2327;margin:0 0 8px;">
                                Nessun sotto-strumento SEO attivo
                            </h2>
                            <p style="font-size:13px;color:#646970;margin:0;line-height:1.6;">
                                Questa sezione raccoglierà tutti gli strumenti SEO del plugin
                                (Schema Markup, Schema Markup, Redirect Manager, ecc.).<br><br>
                                I sotto-strumenti compariranno qui man mano che vengono sviluppati.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
    <?php
}
