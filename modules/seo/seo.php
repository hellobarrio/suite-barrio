<?php
/**
 * Suite Barrio — SEO
 * Pagina SEO del plugin con le card dei sotto-strumenti.
 * Struttura analoga alla dashboard principale.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   SOTTO-STRUMENTI SEO
   ───────────────────────────────────────── */
// I sotto-strumenti sono attivi solo se anche SEO è attivo
if (suite_barrio_tools_module_enabled('seo')) {
    require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/seo/global-seo.php';

    if (suite_barrio_tools_module_enabled('seo_sitemap'))
        require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/seo/sitemap.php';

    require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/seo/schema.php';
    require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/seo/redirects.php';
    require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/seo/seo-advisor.php';
    require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/seo/auto-llms.php';
}

/* ─────────────────────────────────────────
   MENU ADMIN: sottovoce SEO sotto Suite Barrio
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!suite_barrio_tools_module_enabled('seo'))
        return;

    add_submenu_page(
        'suite-barrio',
        'SEO — Suite Barrio',
        'SEO',
        'manage_options',
        'suite-barrio-seo',
        'suite_barrio_seo_page'
    );
}, 18);

/* ─────────────────────────────────────────
   PAGINA SEO
   ───────────────────────────────────────── */
function suite_barrio_seo_page()
{
    $schema_s = get_option(SUITE_BARRIO_SCHEMA_OPTION, []);
    $schema_ok = !empty($schema_s['business_name']) || !empty($schema_s['url']);
    $rd_rows = function_exists('suite_barrio_redirects_get_all') ? suite_barrio_redirects_get_all() : [];
    $rd_total = count($rd_rows);
    $rd_301 = count(array_filter($rd_rows, fn($r) => ((int) ($r['code'] ?? 301)) === 301));
    $adv_key_ok = !empty(suite_barrio_advisor_active_key());
    $adv_log = get_option(SUITE_BARRIO_ADVISOR_LOG_OPTION, []);
    $adv_success = count(array_filter($adv_log, fn($e) => ($e['status'] ?? '') === 'success'));
    $llms_key_ok = function_exists('suite_barrio_llms_active_key') && !empty(suite_barrio_llms_active_key());
    $llms_cache = get_option('wpb_llms_last_generated', []);
    $llms_last = !empty($llms_cache['time']) ? date_i18n('d/m/Y H:i', $llms_cache['time']) : '—';
    $s = get_option(SUITE_BARRIO_META_OPTION, []);
    $content = get_option(SUITE_BARRIO_META_CONTENT_OPTION, []);
    $pt_count = count(array_filter($content['post_types'] ?? [], fn($c) => !empty($c['index'])));
    $tax_count = count(array_filter($content['taxonomies'] ?? [], fn($c) => !empty($c['index'])));
    ?>
<div id="suite-barrio-seo-hub" class="wrap suite-barrio-wrap">

        <div class="suite-barrio-seo-header">
            <div class="suite-barrio-seo-header-icon"><span class="dashicons dashicons-search"></span></div>
            <div>
                <h1>Suite Barrio <span class="suite-barrio-seo-breadcrumb">/ SEO</span></h1>
                <p class="suite-barrio-seo-sub">Strumenti per l'ottimizzazione sui motori di ricerca &mdash;
                    v<?php echo esc_html(SUITE_BARRIO_VERSION); ?></p>
            </div>
        </div>
        <hr class="suite-barrio-seo-divider">

        <div class="suite-barrio-seo-grid">

            <?php if (suite_barrio_tools_module_enabled('seo_meta')): ?>
                <!-- SEO Globale -->
                <div class="suite-barrio-seo-card">
                    <div class="suite-barrio-seo-card-head">
                        <div class="suite-barrio-seo-card-icon"><span class="dashicons dashicons-search"></span></div>
                        <div>
                            <div class="suite-barrio-seo-card-title">SEO Globale</div>
                            <div class="suite-barrio-seo-card-desc">Title, description, robots e Open Graph</div>
                        </div>
                    </div>
                    <div class="suite-barrio-seo-card-body">
                        <table class="suite-barrio-seo-stats">
                            <tr>
                                <td>Separatore titolo</td>
                                <td><code><?php echo esc_html($s['separator'] ?? '—'); ?></code></td>
                            </tr>
                            <tr>
                                <td>Post type indicizzabili</td>
                                <td><?php echo esc_html($pt_count); ?></td>
                            </tr>
                            <tr>
                                <td>Tassonomie indicizzabili</td>
                                <td><?php echo esc_html($tax_count); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="suite-barrio-seo-card-footer">
                        <a href="?page=suite-barrio-global-seo&tab=content" class="suite-barrio-seo-btn suite-barrio-seo-btn-primary">Contenuti</a>
                        <a href="?page=suite-barrio-global-seo&tab=settings" class="suite-barrio-seo-btn suite-barrio-seo-btn-ghost">Impostazioni</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (suite_barrio_tools_module_enabled('seo_sitemap')):
                $sm_entries = function_exists('suite_barrio_sitemap_build_entries') ? suite_barrio_sitemap_build_entries() : [];
                ?>
                <!-- Sitemap -->
                <div class="suite-barrio-seo-card">
                    <div class="suite-barrio-seo-card-head">
                        <div class="suite-barrio-seo-card-icon"><span class="dashicons dashicons-media-document"></span></div>
                        <div>
                            <div class="suite-barrio-seo-card-title">Sitemap</div>
                            <div class="suite-barrio-seo-card-desc">Gestione della sitemap XML</div>
                        </div>
                    </div>
                    <div class="suite-barrio-seo-card-body">
                        <table class="suite-barrio-seo-stats">
                            <tr>
                                <td>URL totali</td>
                                <td><?php echo count($sm_entries); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="suite-barrio-seo-card-footer">
                        <a href="?page=suite-barrio-sitemap" class="suite-barrio-seo-btn suite-barrio-seo-btn-primary">Gestisci</a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Schema -->
            <div class="suite-barrio-seo-card">
                <div class="suite-barrio-seo-card-head">
                    <div class="suite-barrio-seo-card-icon"><span class="dashicons dashicons-networking"></span></div>
                    <div>
                        <div class="suite-barrio-seo-card-title">Schema</div>
                        <div class="suite-barrio-seo-card-desc">JSON-LD strutturato per tutti i contenuti</div>
                    </div>
                </div>
                <div class="suite-barrio-seo-card-body">
                    <table class="suite-barrio-seo-stats">
                        <tr>
                            <td>Tipo business</td>
                            <td><?php echo esc_html($schema_s['business_type'] ?? '—'); ?></td>
                        </tr>
                        <tr>
                            <td>Configurato</td>
                            <td><?php echo $schema_ok ? '<span class="suite-barrio-ok">&#x2713; Sì</span>' : '<span class="suite-barrio-mute">Non ancora</span>'; ?>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="suite-barrio-seo-card-footer">
                    <a href="?page=suite-barrio-schema" class="suite-barrio-seo-btn suite-barrio-seo-btn-primary">Configura</a>
                </div>
            </div>

            <!-- Redirects -->
            <div class="suite-barrio-seo-card">
                <div class="suite-barrio-seo-card-head">
                    <div class="suite-barrio-seo-card-icon"><span class="dashicons dashicons-randomize"></span></div>
                    <div>
                        <div class="suite-barrio-seo-card-title">Redirects</div>
                        <div class="suite-barrio-seo-card-desc">Gestione redirect per il frontend headless</div>
                    </div>
                </div>
                <div class="suite-barrio-seo-card-body">
                    <table class="suite-barrio-seo-stats">
                        <tr>
                            <td>Redirect totali</td>
                            <td><?php echo esc_html($rd_total); ?></td>
                        </tr>
                        <tr>
                            <td>301 Permanenti</td>
                            <td><?php echo esc_html($rd_301); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="suite-barrio-seo-card-footer">
                    <a href="?page=suite-barrio-redirects" class="suite-barrio-seo-btn suite-barrio-seo-btn-primary">Gestisci</a>
                </div>
            </div>

            <!-- SEO Advisor -->
            <div class="suite-barrio-seo-card">
                <div class="suite-barrio-seo-card-head">
                    <div class="suite-barrio-seo-card-icon"><span class="dashicons dashicons-chart-line"></span></div>
                    <div>
                        <div class="suite-barrio-seo-card-title">SEO Advisor</div>
                        <div class="suite-barrio-seo-card-desc">Genera meta SEO con AI</div>
                    </div>
                </div>
                <div class="suite-barrio-seo-card-body">
                    <table class="suite-barrio-seo-stats">
                        <tr>
                            <td>Chiave API</td>
                            <td><?php echo $adv_key_ok ? '<span class="suite-barrio-ok">&#x2713; Configurata</span>' : '<span class="suite-barrio-err">Non configurata</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Generazioni riuscite</td>
                            <td><?php echo esc_html($adv_success); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="suite-barrio-seo-card-footer">
                    <a href="?page=suite-barrio-seo-advisor&tab=generate" class="suite-barrio-seo-btn suite-barrio-seo-btn-primary">Genera</a>
                    <a href="?page=suite-barrio-seo-advisor&tab=settings"
                        class="suite-barrio-seo-btn suite-barrio-seo-btn-ghost">Impostazioni</a>
                </div>
            </div>

            <!-- Auto LLMs -->
            <div class="suite-barrio-seo-card">
                <div class="suite-barrio-seo-card-head">
                    <div class="suite-barrio-seo-card-icon"><span class="dashicons dashicons-text-page"></span></div>
                    <div>
                        <div class="suite-barrio-seo-card-title">Auto LLMs</div>
                        <div class="suite-barrio-seo-card-desc">Generazione llms.txt e llms-full.txt</div>
                    </div>
                </div>
                <div class="suite-barrio-seo-card-body">
                    <table class="suite-barrio-seo-stats">
                        <tr>
                            <td>Chiave API</td>
                            <td><?php echo $llms_key_ok ? '<span class="suite-barrio-ok">&#x2713; Configurata</span>' : '<span class="suite-barrio-err">Non configurata</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td>Ultima generazione</td>
                            <td><?php echo esc_html($llms_last); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="suite-barrio-seo-card-footer">
                    <a href="?page=suite-barrio-auto-llms" class="suite-barrio-seo-btn suite-barrio-seo-btn-primary">Gestisci</a>
                </div>
            </div>

        </div>
    </div>
    <?php
}