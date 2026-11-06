<?php
/**
 * Plugin Name:  Suite Barrio
 * Description:  Suite Barrio — suite di strumenti AI per WordPress. Auto Alt genera alt text e title delle immagini. Forms crea form di contatto con builder drag & drop. Auto LLMs genera llms.txt e llms-full.txt con AI.
 * Version:      9.1.4
 * Author:       Hellobarrio
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain:  suite-barrio
 * License:      GPLv2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH'))
   exit;

/* ─────────────────────────────────────────
   CSS CONDIVISO: caricato su tutte le pagine Suite Barrio
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
   // if (strpos($hook, 'suite-barrio') === false)
   //    return;
   wp_enqueue_style(
      'suite-barrio-admin',
      SUITE_BARRIO_PLUGIN_URL . 'assets/css/suite-barrio-admin.css',
      [],
      SUITE_BARRIO_VERSION
   );
});

/* ─────────────────────────────────────────
   COSTANTI GLOBALI
   ───────────────────────────────────────── */
define('SUITE_BARRIO_VERSION', '9.1.4');
define('SUITE_BARRIO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SUITE_BARRIO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SUITE_BARRIO_LOG_OPTION', 'suite_barrio_alt_ai_log');
define('SUITE_BARRIO_LOG_MAX', 200);
define('SUITE_BARRIO_JOB_OPTION', 'suite_barrio_alt_ai_bulk_job');
define('SUITE_BARRIO_CRON_HOOK', 'suite_barrio_alt_ai_process_batch');
define('SUITE_BARRIO_BATCH_SIZE', 5);

/* ─────────────────────────────────────────
   MIGRAZIONE — rinomina una tantum di opzioni/meta dal vecchio
   prefisso "wpb_"/"wp_alt_ai_" al nuovo "suite_barrio_". Deve girare
   PRIMA di ogni modulo, incluso "tools" da cui dipende quali moduli
   caricare.
   ───────────────────────────────────────── */
require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/migrations.php';

/* ─────────────────────────────────────────
   MODULI CORE
   ───────────────────────────────────────── */
require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/dashboard/dashboard.php';
require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/tools/tools.php';
require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/seo/seo.php';
require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/info-azienda/info-azienda.php';
// Sempre attivo: gestisce anche i siti legacy con post type "registrazioni"
require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/retention.php';
require_once SUITE_BARRIO_PLUGIN_DIR . 'wizard/wizard.php';

// Carica ogni modulo solo se non e' stato disattivato dalla pagina Strumenti
if (suite_barrio_tools_module_enabled('accessibility'))
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/accessibility/accessibility.php';

if (suite_barrio_tools_module_enabled('auto_alt'))
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/auto-alt/auto-alt.php';

if (suite_barrio_tools_module_enabled('blocks'))
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/blocks/blocks.php';

if (suite_barrio_tools_module_enabled('forms')) {
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/forms/forms.php';
}

if (suite_barrio_tools_module_enabled('auto_fields'))
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/auto-fields/auto-fields.php';

if (suite_barrio_tools_module_enabled('orari'))
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/orari/orari.php';

if (suite_barrio_tools_module_enabled('popups'))
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/popups/popups.php';

if (suite_barrio_tools_module_enabled('banners'))
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/banners/banners.php';

if (suite_barrio_tools_module_enabled('columns'))
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/columns/columns.php';


if (suite_barrio_tools_module_enabled('cookie_privacy'))
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/cookie-privacy/cookie-privacy.php';

if (suite_barrio_tools_module_enabled('cookie_banner'))
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/cookie-banner/cookie-banner.php';

if (suite_barrio_tools_module_enabled('orders'))
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/orders/orders.php';

if (suite_barrio_tools_module_enabled('duplicate'))
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/duplicate/duplicate.php';

if (suite_barrio_tools_module_enabled('stats'))
   require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/stats/stats.php';

/* ─────────────────────────────────────────
   GRAPHQL — caricato sempre.
   I resolver verificano internamente la disponibilità
   delle funzioni richieste da ciascun modulo.
   Se WPGraphQL non è installato tutti gli hook su
   `graphql_register_types` vengono ignorati silenziosamente.
   ───────────────────────────────────────── */
require_once SUITE_BARRIO_PLUGIN_DIR . 'modules/graphql.php';

/* ─────────────────────────────────────────
   CACHE INVALIDATION (Vercel)
   ───────────────────────────────────────── */
require_once SUITE_BARRIO_PLUGIN_DIR . 'cache.php';
