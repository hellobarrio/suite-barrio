<?php
/**
 * Template default per [wpb_popup].
 *
 * Per personalizzare copia questo file in:
 *   wp-content/themes/<tuo-tema>/wp-barrio/popup.php
 *
 * Variabili disponibili:
 *   $wrap_id     — ID del wrapper (es. "wpb-popup-1")
 *   $title_id    — ID del titolo per aria-labelledby (es. "wpb-popup-1-title")
 *   $id          — ID numerico del popup
 *   $delay       — ritardo apertura in ms
 *   $days        — durata cookie di chiusura in giorni (0 = nessun cookie)
 *   $title       — titolo
 *   $content     — corpo HTML
 *   $link        — URL CTA (stringa vuota se assente)
 *   $link_title  — testo del pulsante CTA
 *   $link_target — "_self" | "_blank"
 *   $rel         — attributo rel pronto per l'output
 *   $image       — URL immagine (stringa vuota se assente)
 */

if (!defined('ABSPATH')) exit;
?>

<div class="wpb-popup"
     id="<?php echo esc_attr($wrap_id); ?>"
     role="dialog"
     aria-modal="true"
     aria-labelledby="<?php echo esc_attr($title_id); ?>"
     data-wpb-popup
     data-delay="<?php echo esc_attr($delay); ?>"
     data-days="<?php echo esc_attr($days); ?>">

    <div class="wpb-popup__overlay" data-wpb-popup-close></div>

    <div class="wpb-popup__dialog">
        <button type="button" class="wpb-popup__close" data-wpb-popup-close
                aria-label="<?php esc_attr_e('Chiudi', 'wp-barrio'); ?>">&times;</button>

        <?php if ('' !== $image): ?>
            <img class="wpb-popup__image"
                 src="<?php echo esc_url($image); ?>"
                 alt="<?php echo esc_attr($title); ?>"
                 loading="lazy">
        <?php endif; ?>

        <div class="wpb-popup__body">
            <?php if ('' !== $title): ?>
                <h2 class="wpb-popup__title" id="<?php echo esc_attr($title_id); ?>">
                    <?php echo esc_html($title); ?>
                </h2>
            <?php endif; ?>

            <?php if ('' !== trim($content)): ?>
                <div class="wpb-popup__content"><?php echo wp_kses_post(wpautop($content)); ?></div>
            <?php endif; ?>

            <?php if ('' !== $link): ?>
                <a class="wpb-popup__cta"
                   href="<?php echo esc_url($link); ?>"
                   target="<?php echo esc_attr($link_target); ?>"<?php echo $rel; ?>>
                    <?php echo esc_html('' !== $link_title ? $link_title : __('Scopri di più', 'wp-barrio')); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
