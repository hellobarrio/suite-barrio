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
<style>
.wpb-banner {
    position: relative;
    overflow: hidden;
    width: 100%;
}
.wpb-banner__inner {
    display: flex;
    align-items: center;
    column-gap: 1rem;
    width: 100%;
    padding: 0.5lh 0;
    text-decoration: none;
    color: inherit;
}
.wpb-banner__track {
    display: flex;
    align-items: center;
    column-gap: 1rem;
    flex-shrink: 0;
    will-change: transform;
}
.wpb-banner__item {
    white-space: nowrap;
    text-transform: uppercase;
}
</style>

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
<script>
(function () {
    var t1    = document.getElementById(<?php echo wp_json_encode($uid . '-t1'); ?>);
    var t2    = document.getElementById(<?php echo wp_json_encode($uid . '-t2'); ?>);
    var inner = document.getElementById(<?php echo wp_json_encode($uid . '-inner'); ?>);
    if (!t1 || !t2 || !inner) return;

    var x = 0, targetV = 80, currentV = 80, lastTs = null, trackW = 0;

    function lerp(a, b, t) { return a + (b - a) * t; }

    inner.addEventListener('mouseenter', function () { targetV = 0; });
    inner.addEventListener('mouseleave', function () { targetV = 80; });

    function tick(ts) {
        if (lastTs === null) { lastTs = ts; requestAnimationFrame(tick); return; }
        var delta = ts - lastTs;
        lastTs = ts;

        if (trackW === 0) trackW = t1.getBoundingClientRect().width;

        currentV = lerp(currentV, targetV, 0.05);
        x -= (currentV * delta) / 1000;

        if (trackW > 0 && x <= -(trackW + 20)) x = 0;

        var tx = 'translateX(' + x + 'px)';
        t1.style.transform = tx;
        t2.style.transform = tx;

        requestAnimationFrame(tick);
    }

    requestAnimationFrame(tick);
})();
</script>
