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
}, 18);

/* ─────────────────────────────────────────
   PAGINA SEO
   ───────────────────────────────────────── */
function wpb_seo_page()
{
    $schema_s = get_option(WPB_SCHEMA_OPTION, []);
    $schema_ok = !empty($schema_s['business_name']) || !empty($schema_s['url']);
    $rd_rows = function_exists('wpb_redirects_get_all') ? wpb_redirects_get_all() : [];
    $rd_total = count($rd_rows);
    $rd_301 = count(array_filter($rd_rows, fn($r) => ((int) ($r['code'] ?? 301)) === 301));
    $adv_key_ok = !empty(wpb_advisor_active_key());
    $adv_log = get_option(WPB_ADVISOR_LOG_OPTION, []);
    $adv_success = count(array_filter($adv_log, fn($e) => ($e['status'] ?? '') === 'success'));
    $llms_key_ok = function_exists('wpb_llms_active_key') && !empty(wpb_llms_active_key());
    $llms_cache = get_option('wpb_llms_last_generated', []);
    $llms_last = !empty($llms_cache['time']) ? date_i18n('d/m/Y H:i', $llms_cache['time']) : '—';
    $s = get_option(WPB_META_OPTION, []);
    $content = get_option(WPB_META_CONTENT_OPTION, []);
    $pt_count = count(array_filter($content['post_types'] ?? [], fn($c) => !empty($c['index'])));
    $tax_count = count(array_filter($content['taxonomies'] ?? [], fn($c) => !empty($c['index'])));
    ?>
    <style>
        #wpb-seo-hub {
            max-width: 1060px;
            font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", sans-serif;
        }

        /* Header */
        .wpb-seo-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 8px;
        }

        .wpb-seo-header-icon {
            width: 40px;
            height: 40px;
            background: #111827;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .wpb-seo-header-icon .dashicons {
            font-size: 20px;
            width: 20px;
            height: 20px;
            color: #fff;
        }

        .wpb-seo-header h1 {
            font-size: 22px !important;
            font-weight: 700 !important;
            color: #111827 !important;
            margin: 0 !important;
            padding: 0 !important;
            line-height: 1.2 !important;
        }

        .wpb-seo-breadcrumb {
            font-size: 14px;
            font-weight: 400;
            color: #9ca3af;
            margin-left: 4px;
        }

        .wpb-seo-sub {
            font-size: 13px;
            color: #6b7280;
            margin: 2px 0 0;
        }

        .wpb-seo-divider {
            border: none;
            border-top: 1px solid #f3f4f6;
            margin: 14px 0 24px;
        }

        /* Grid */
        .wpb-seo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }

        /* Card */
        .wpb-seo-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .05);
            display: flex;
            flex-direction: column;
        }

        .wpb-seo-card-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            border-bottom: 1px solid #f3f4f6;
            background: #f9fafb;
        }

        .wpb-seo-card-icon {
            width: 36px;
            height: 36px;
            background: #111827;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .wpb-seo-card-icon .dashicons {
            font-size: 18px;
            width: 18px;
            height: 18px;
            color: #fff;
        }

        .wpb-seo-card-title {
            font-size: 14px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 2px;
        }

        .wpb-seo-card-desc {
            font-size: 12px;
            color: #6b7280;
        }

        .wpb-seo-card-body {
            padding: 16px 20px;
            flex: 1;
        }

        .wpb-seo-card-footer {
            padding: 14px 20px;
            border-top: 1px solid #f3f4f6;
            display: flex;
            gap: 8px;
        }

        /* Stats table inside card */
        .wpb-seo-stats {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .wpb-seo-stats td {
            padding: 5px 0;
            vertical-align: middle;
        }

        .wpb-seo-stats td:first-child {
            color: #6b7280;
        }

        .wpb-seo-stats td:last-child {
            text-align: right;
            font-weight: 600;
            color: #111827;
        }

        .wpb-seo-stats tr+tr td {
            border-top: 1px solid #f3f4f6;
        }

        .wpb-ok {
            color: #059669 !important;
        }

        .wpb-err {
            color: #dc2626 !important;
        }

        .wpb-mute {
            color: #9ca3af !important;
        }

        /* Buttons inside card */
        .wpb-seo-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 16px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px;
            text-decoration: none !important;
            cursor: pointer;
            border: none;
            transition: opacity .15s;
            font-family: inherit;
            flex: 1;
            text-align: center;
        }

        .wpb-seo-btn:hover {
            opacity: .85;
        }

        .wpb-seo-btn-primary {
            background: #111827;
            color: #fff !important;
        }

        .wpb-seo-btn-ghost {
            background: #f3f4f6;
            color: #374151 !important;
            border: 1px solid #e5e7eb;
        }
    </style>

    <div id="wpb-seo-hub" class="wrap">

        <div class="wpb-seo-header">
            <div class="wpb-seo-header-icon"><span class="dashicons dashicons-search"></span></div>
            <div>
                <h1>WP Barrio <span class="wpb-seo-breadcrumb">/ SEO</span></h1>
                <p class="wpb-seo-sub">Strumenti per l'ottimizzazione sui motori di ricerca &mdash;
                    v<?php echo WP_ALT_AI_VERSION; ?></p>
            </div>
        </div>
        <hr class="wpb-seo-divider">

        <div class="wpb-seo-grid">

            <?php if (wpb_tools_module_enabled('seo_meta')): ?>
                <!-- SEO Globale -->
                <div class="wpb-seo-card">
                    <div class="wpb-seo-card-head">
                        <div class="wpb-seo-card-icon"><span class="dashicons dashicons-search"></span></div>
                        <div>
                            <div class="wpb-seo-card-title">SEO Globale</div>
                            <div class="wpb-seo-card-desc">Title, description, robots e Open Graph</div>
                        </div>
                    </div>
                    <div class="wpb-seo-card-body">
                        <table class="wpb-seo-stats">
                            <tr>
                                <td>Separatore titolo</td>
                                <td><code><?php echo esc_html($s['separator'] ?? '—'); ?></code></td>
                            </tr>
                            <tr>
                                <td>Post type indicizzabili</td>
                                <td><?php echo $pt_count; ?></td>
                            </tr>
                            <tr>
                                <td>Tassonomie indicizzabili</td>
                                <td><?php echo $tax_count; ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="wpb-seo-card-footer">
                        <a href="?page=wp-barrio-global-seo&tab=content" class="wpb-seo-btn wpb-seo-btn-primary">Contenuti</a>
                        <a href="?page=wp-barrio-global-seo&tab=settings" class="wpb-seo-btn wpb-seo-btn-ghost">Impostazioni</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (wpb_tools_module_enabled('seo_sitemap')):
                $sm_entries = function_exists('wpb_sitemap_build_entries') ? wpb_sitemap_build_entries() : [];
                ?>
                <!-- Sitemap -->
                <div class="wpb-seo-card">
                    <div class="wpb-seo-card-head">
                        <div class="wpb-seo-card-icon"><span class="dashicons dashicons-media-document"></span></div>
                        <div>
                            <div class="wpb-seo-card-title">Sitemap</div>
                            <div class="wpb-seo-card-desc">Gestione della sitemap XML</div>
                        </div>
                    </div>
                    <div class="wpb-seo-card-body">
                        <table class="wpb-seo-stats">
                            <tr>
                                <td>URL totali</td>
                                <td><?php echo count($sm_entries); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="wpb-seo-card-footer">
                        <a href="?page=wp-barrio-sitemap" class="wpb-seo-btn wpb-seo-btn-primary">Gestisci</a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Schema -->
            <div class="wpb-seo-card">
                <div class="wpb-seo-card-head">
                    <div class="wpb-seo-card-icon"><span class="dashicons dashicons-networking"></span></div>
                    <div>
                        <div class="wpb-seo-card-title">Schema</div>
                        <div class="wpb-seo-card-desc">JSON-LD strutturato per tutti i contenuti</div>
                    </div>
                </div>
                <div class="wpb-seo-card-body">
                    <table class="wpb-seo-stats">
                        <tr>
                            <td>Tipo business</td>
                            <td><?php echo esc_html($schema_s['business_type'] ?? '—'); ?></td>
                        </tr>
                        <tr>
                            <td>Configurato</td>
                            <td><?php echo $schema_ok ? '<span class="wpb-ok">&#x2713; Sì</span>' : '<span class="wpb-mute">Non ancora</span>'; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="wpb-seo-card-footer">
                    <a href="?page=wp-barrio-schema" class="wpb-seo-btn wpb-seo-btn-primary">Configura</a>
                </div>
            </div>

            <!-- Redirects -->
            <div class="wpb-seo-card">
                <div class="wpb-seo-card-head">
                    <div class="wpb-seo-card-icon"><span class="dashicons dashicons-randomize"></span></div>
                    <div>
                        <div class="wpb-seo-card-title">Redirects</div>
                        <div class="wpb-seo-card-desc">Gestione redirect per il frontend headless</div>
                    </div>
                </div>
                <div class="wpb-seo-card-body">
                    <table class="wpb-seo-stats">
                        <tr>
                            <td>Redirect totali</td>
                            <td><?php echo $rd_total; ?></td>
                        </tr>
                        <tr>
                            <td>301 Permanenti</td>
                            <td><?php echo $rd_301; ?></td>
                        </tr>
                    </table>
                </div>
                <div class="wpb-seo-card-footer">
                    <a href="?page=wp-barrio-redirects" class="wpb-seo-btn wpb-seo-btn-primary">Gestisci</a>
                </div>
            </div>

            <!-- SEO Advisor -->
            <div class="wpb-seo-card">
                <div class="wpb-seo-card-head">
                    <div class="wpb-seo-card-icon"><span class="dashicons dashicons-chart-line"></span></div>
                    <div>
                        <div class="wpb-seo-card-title">SEO Advisor</div>
                        <div class="wpb-seo-card-desc">Genera meta SEO con AI</div>
                    </div>
                </div>
                <div class="wpb-seo-card-body">
                    <table class="wpb-seo-stats">
                        <tr>
                            <td>Chiave API</td>
                            <td><?php echo $adv_key_ok ? '<span class="wpb-ok">&#x2713; Configurata</span>' : '<span class="wpb-err">Non configurata</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Generazioni riuscite</td>
                            <td><?php echo $adv_success; ?></td>
                        </tr>
                    </table>
                </div>
                <div class="wpb-seo-card-footer">
                    <a href="?page=wp-barrio-seo-advisor&tab=generate" class="wpb-seo-btn wpb-seo-btn-primary">Genera</a>
                    <a href="?page=wp-barrio-seo-advisor&tab=settings"
                        class="wpb-seo-btn wpb-seo-btn-ghost">Impostazioni</a>
                </div>
            </div>

            <!-- Auto LLMs -->
            <div class="wpb-seo-card">
                <div class="wpb-seo-card-head">
                    <div class="wpb-seo-card-icon"><span class="dashicons dashicons-text-page"></span></div>
                    <div>
                        <div class="wpb-seo-card-title">Auto LLMs</div>
                        <div class="wpb-seo-card-desc">Generazione llms.txt e llms-full.txt</div>
                    </div>
                </div>
                <div class="wpb-seo-card-body">
                    <table class="wpb-seo-stats">
                        <tr>
                            <td>Chiave API</td>
                            <td><?php echo $llms_key_ok ? '<span class="wpb-ok">&#x2713; Configurata</span>' : '<span class="wpb-err">Non configurata</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Ultima generazione</td>
                            <td><?php echo esc_html($llms_last); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="wpb-seo-card-footer">
                    <a href="?page=wp-barrio-auto-llms" class="wpb-seo-btn wpb-seo-btn-primary">Gestisci</a>
                </div>
            </div>

        </div>
    </div>
    <?php
}