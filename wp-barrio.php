<?php
/**
 * Plugin Name:  WP Barrio
 * Description:  WP Barrio — suite di strumenti AI per WordPress. Auto Alt genera alt text e title delle immagini. Forms crea form di contatto con builder drag & drop. Auto LLMs genera llms.txt e llms-full.txt con AI.
 * Version:      7.7.0
 * Author:       Hellobarrio
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain:  wp-barrio
 */

if (!defined('ABSPATH'))
   exit;

/* ─────────────────────────────────────────
   CSS CONDIVISO: caricato su tutte le pagine WP Barrio
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
   // if (strpos($hook, 'wp-barrio') === false)
   //    return;
   wp_enqueue_style(
      'wpb-admin',
      WP_ALT_AI_PLUGIN_URL . 'assets/css/wpb-admin.css',
      [],
      WP_ALT_AI_VERSION
   );
});

/* ─────────────────────────────────────────
   COSTANTI GLOBALI
   ───────────────────────────────────────── */
define('WP_ALT_AI_VERSION', '7.7.0');
define('WP_ALT_AI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_ALT_AI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_ALT_AI_LOG_OPTION', 'wp_alt_ai_log');
define('WP_ALT_AI_LOG_MAX', 200);
define('WP_ALT_AI_JOB_OPTION', 'wp_alt_ai_bulk_job');
define('WP_ALT_AI_CRON_HOOK', 'wp_alt_ai_process_batch');
define('WP_ALT_AI_BATCH_SIZE', 5);

/* ─────────────────────────────────────────
   MODULI CORE
   ───────────────────────────────────────── */
require_once WP_ALT_AI_PLUGIN_DIR . 'modules/dashboard/dashboard.php';
require_once WP_ALT_AI_PLUGIN_DIR . 'modules/tools/tools.php';
require_once WP_ALT_AI_PLUGIN_DIR . 'modules/seo/seo.php';
require_once WP_ALT_AI_PLUGIN_DIR . 'modules/info-azienda/info-azienda.php';
require_once WP_ALT_AI_PLUGIN_DIR . 'wizard/wizard.php';

// Carica ogni modulo solo se non e' stato disattivato dalla pagina Strumenti
if (wpb_tools_module_enabled('auto_alt'))
   require_once WP_ALT_AI_PLUGIN_DIR . 'modules/auto-alt/auto-alt.php';

if (wpb_tools_module_enabled('blocks'))
   require_once WP_ALT_AI_PLUGIN_DIR . 'modules/blocks/blocks.php';

if (wpb_tools_module_enabled('forms')) {
   require_once WP_ALT_AI_PLUGIN_DIR . 'modules/forms/forms.php';
}

if (wpb_tools_module_enabled('auto_fields'))
   require_once WP_ALT_AI_PLUGIN_DIR . 'modules/auto-fields/auto-fields.php';

if (wpb_tools_module_enabled('orari'))
   require_once WP_ALT_AI_PLUGIN_DIR . 'modules/orari/orari.php';

if (wpb_tools_module_enabled('popups'))
   require_once WP_ALT_AI_PLUGIN_DIR . 'modules/popups/popups.php';

if (wpb_tools_module_enabled('banners'))
   require_once WP_ALT_AI_PLUGIN_DIR . 'modules/banners/banners.php';


if (wpb_tools_module_enabled('cookie_privacy'))
   require_once WP_ALT_AI_PLUGIN_DIR . 'modules/cookie-privacy/cookie-privacy.php';


if (wpb_tools_module_enabled('orders'))
   require_once WP_ALT_AI_PLUGIN_DIR . 'modules/orders/orders.php';

if (wpb_tools_module_enabled('duplicate'))
   require_once WP_ALT_AI_PLUGIN_DIR . 'modules/duplicate/duplicate.php';

if (wpb_tools_module_enabled('stats'))
   require_once WP_ALT_AI_PLUGIN_DIR . 'modules/stats/stats.php';

/* ─────────────────────────────────────────
   GRAPHQL — caricato sempre.
   I resolver verificano internamente la disponibilità
   delle funzioni richieste da ciascun modulo.
   Se WPGraphQL non è installato tutti gli hook su
   `graphql_register_types` vengono ignorati silenziosamente.
   ───────────────────────────────────────── */
require_once WP_ALT_AI_PLUGIN_DIR . 'modules/graphql.php';

/* ─────────────────────────────────────────
   CACHE INVALIDATION (Vercel)
   ───────────────────────────────────────── */
require_once WP_ALT_AI_PLUGIN_DIR . 'cache.php';
