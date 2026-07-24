<?php
/**
 * Suite Barrio — Migrazione una tantum del prefisso opzioni/meta.
 *
 * Fino alla 9.0.0 il plugin salvava opzioni/meta con i prefissi "wpb_"
 * e "wp_alt_ai_" (troppo corti / riservati a WordPress). Da qui in poi
 * tutto usa il prefisso "suite_barrio_". Questa migrazione rinomina le
 * righe già esistenti sui siti attivi così le impostazioni non vengono
 * perse in fase di aggiornamento. Gira una sola volta per sito.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('SUITE_BARRIO_PREFIX_MIGRATION_VERSION', '1');

function suite_barrio_migrate_legacy_prefix(): void
{
    if (get_option('suite_barrio_prefix_migration_version') === SUITE_BARRIO_PREFIX_MIGRATION_VERSION) {
        return;
    }

    global $wpdb;

    // ── wp_options: "wpb_*" e "wp_alt_ai*" → "suite_barrio_*" ──
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- migrazione one-time, i nomi delle opzioni non sono input utente
    $legacy_options = $wpdb->get_col(
        "SELECT option_name FROM {$wpdb->options}
         WHERE option_name LIKE 'wpb\\_%' OR option_name LIKE 'wp\\_alt\\_ai%'"
    );

    foreach ($legacy_options as $old_name) {
        $new_name = suite_barrio_migrate_new_option_name($old_name);
        if ($new_name === $old_name) {
            continue;
        }
        // Non sovrascrivere se esiste già un valore con il nuovo nome
        // (es. il plugin aggiornato ha già scritto un default).
        if (get_option($new_name, null) !== null) {
            continue;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- rename one-time della riga, preserva valore e autoload senza serializzare/deserializzare
        $wpdb->update($wpdb->options, ['option_name' => $new_name], ['option_name' => $old_name]);
    }
    wp_cache_delete('alloptions', 'options');

    // ── wp_postmeta / wp_termmeta: "_wpb_seo_*" → "_suite_barrio_seo_*" ──
    $seo_meta_keys = [
        '_wpb_seo_title',
        '_wpb_seo_description',
        '_wpb_seo_keywords',
        '_wpb_seo_index',
        '_wpb_seo_follow',
        '_wpb_seo_sitemap',
        '_wpb_seo_og_image_id',
    ];
    foreach ($seo_meta_keys as $old_key) {
        $new_key = '_suite_barrio_seo_' . substr($old_key, strlen('_wpb_seo_'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- rename one-time delle chiavi meta esistenti
        $wpdb->update($wpdb->postmeta, ['meta_key' => $new_key], ['meta_key' => $old_key]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- rename one-time delle chiavi meta esistenti
        $wpdb->update($wpdb->termmeta, ['meta_key' => $new_key], ['meta_key' => $old_key]);
    }

    // ── wp_termmeta: "wpb_term_order" → "suite_barrio_term_order" ──
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- rename one-time della chiave meta esistente
    $wpdb->update($wpdb->termmeta, ['meta_key' => 'suite_barrio_term_order'], ['meta_key' => 'wpb_term_order']);

    update_option('suite_barrio_prefix_migration_version', SUITE_BARRIO_PREFIX_MIGRATION_VERSION);
}

/**
 * Applica alla stringa la stessa trasformazione di prefisso usata nel
 * codice: "wp_alt_ai_"/"wp_alt_ai" → "suite_barrio_alt_ai[_]", poi
 * "wpb_" → "suite_barrio_".
 */
function suite_barrio_migrate_new_option_name(string $old_name): string
{
    if (strpos($old_name, 'wp_alt_ai_') === 0) {
        return 'suite_barrio_alt_ai_' . substr($old_name, strlen('wp_alt_ai_'));
    }
    if (strpos($old_name, 'wp_alt_ai') === 0) {
        return 'suite_barrio_alt_ai' . substr($old_name, strlen('wp_alt_ai'));
    }
    if (strpos($old_name, 'wpb_') === 0) {
        return 'suite_barrio_' . substr($old_name, strlen('wpb_'));
    }
    return $old_name;
}

suite_barrio_migrate_legacy_prefix();
