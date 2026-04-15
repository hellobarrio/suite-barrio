<?php
/**
 * Plugin Name:  WP Barrio
 * Description:  WP Barrio — suite di strumenti AI per WordPress. Auto Alt genera automaticamente alt text e title delle immagini. Forms permette di creare form di contatto con builder drag & drop.
 * Version:      2.3.8
 * Author:       Hellobarrio
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain:  wp-barrio
 */

if (!defined('ABSPATH'))
   exit;

/* ─────────────────────────────────────────
   COSTANTI GLOBALI
   ───────────────────────────────────────── */
define('WP_ALT_AI_VERSION', '2.3.8');
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
require_once WP_ALT_AI_PLUGIN_DIR . 'modules/auto-alt/auto-alt.php';
require_once WP_ALT_AI_PLUGIN_DIR . 'modules/forms/forms.php';
require_once WP_ALT_AI_PLUGIN_DIR . 'modules/auto-fields/auto-fields.php';

/* ─────────────────────────────────────────
   MODULI OPZIONALI (richiedono plugin di terze parti)
   ───────────────────────────────────────── */

// GraphQL — caricato solo se WPGraphQL è attivo.
// `graphql_register_types` non esiste se WPGraphQL non è installato,
// quindi il file è sicuro da includere sempre: al suo interno tutti gli
// hook su `graphql_register_types` vengono silenziosamente ignorati.
// Il notice admin è invece sempre attivo per avvisare l'utente.
require_once WP_ALT_AI_PLUGIN_DIR . 'modules/forms/graphql.php';
