<?php
/**
 * Template default per [suite_barrio_banner].
 *
 * Per personalizzare copia questo file in:
 *   wp-content/themes/<tuo-tema>/suite-barrio/banner.php
 *
 * Variabili disponibili:
 *   $uid         — ID univoco dell'elemento (es. "suite-barrio-banner-1")
 *   $title       — testo del banner
 *   $link        — URL (stringa vuota se assente)
 *   $link_target — "_self" | "_blank"
 *   $rel         — attributo rel pronto per l'output (es. ' rel="noopener noreferrer"')
 */

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variabili locali di questo file di template, iniettate da extract() in suite_barrio_get_template(), non variabili globali
$item  = '<div class="suite-barrio-banner__item">' . esc_html($title) . '</div>';
$items = str_repeat($item, 15);
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>

<div id="<?php echo esc_attr($uid); ?>" class="suite-barrio-banner">

    <?php if ('' !== $link): ?>
        <a href="<?php echo esc_url($link); ?>"
           target="<?php echo esc_attr($link_target); ?>"<?php echo wp_kses_post($rel); ?>
           id="<?php echo esc_attr($uid); ?>-inner"
           class="suite-barrio-banner__inner">
            <div id="<?php echo esc_attr($uid); ?>-t1" class="suite-barrio-banner__track"><?php echo wp_kses_post($items); ?></div>
            <div id="<?php echo esc_attr($uid); ?>-t2" class="suite-barrio-banner__track"><?php echo wp_kses_post($items); ?></div>
        </a>
    <?php else: ?>
        <div id="<?php echo esc_attr($uid); ?>-inner" class="suite-barrio-banner__inner">
            <div id="<?php echo esc_attr($uid); ?>-t1" class="suite-barrio-banner__track"><?php echo wp_kses_post($items); ?></div>
            <div id="<?php echo esc_attr($uid); ?>-t2" class="suite-barrio-banner__track"><?php echo wp_kses_post($items); ?></div>
        </div>
    <?php endif; ?>

</div>