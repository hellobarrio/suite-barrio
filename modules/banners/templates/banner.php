<?php
/**
 * Template default per [wpb_banner].
 *
 * Per personalizzare copia questo file in:
 *   wp-content/themes/<tuo-tema>/wp-barrio/banner.php
 *
 * Variabili disponibili:
 *   $uid         — ID univoco dell'elemento (es. "wpb-banner-1")
 *   $title       — testo del banner
 *   $link        — URL (stringa vuota se assente)
 *   $link_target — "_self" | "_blank"
 *   $rel         — attributo rel pronto per l'output (es. ' rel="noopener noreferrer"')
 */

if (!defined('ABSPATH')) exit;

$item  = '<div class="wpb-banner__item">' . esc_html($title) . '</div>';
$items = str_repeat($item, 15);
?>

<div id="<?php echo esc_attr($uid); ?>" class="wpb-banner">

    <?php if ('' !== $link): ?>
        <a href="<?php echo esc_url($link); ?>"
           target="<?php echo esc_attr($link_target); ?>"<?php echo $rel; ?>
           id="<?php echo esc_attr($uid); ?>-inner"
           class="wpb-banner__inner">
            <div id="<?php echo esc_attr($uid); ?>-t1" class="wpb-banner__track"><?php echo $items; ?></div>
            <div id="<?php echo esc_attr($uid); ?>-t2" class="wpb-banner__track"><?php echo $items; ?></div>
        </a>
    <?php else: ?>
        <div id="<?php echo esc_attr($uid); ?>-inner" class="wpb-banner__inner">
            <div id="<?php echo esc_attr($uid); ?>-t1" class="wpb-banner__track"><?php echo $items; ?></div>
            <div id="<?php echo esc_attr($uid); ?>-t2" class="wpb-banner__track"><?php echo $items; ?></div>
        </div>
    <?php endif; ?>

</div>