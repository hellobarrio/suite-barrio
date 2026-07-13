<?php
/**
 * Template default per [suite_barrio_popup].
 *
 * Per personalizzare copia questo file in:
 *   wp-content/themes/<tuo-tema>/suite-barrio/popup.php
 *
 * Variabili disponibili:
 *   $wrap_id     — ID del wrapper (es. "suite-barrio-popup-1")
 *   $title_id    — ID del titolo per aria-labelledby (es. "suite-barrio-popup-1-title")
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

<div class="suite-barrio-popup"
     id="<?php echo esc_attr($wrap_id); ?>"
     role="dialog"
     aria-modal="true"
     aria-labelledby="<?php echo esc_attr($title_id); ?>"
     data-suite-barrio-popup
     data-delay="<?php echo esc_attr($delay); ?>"
     data-days="<?php echo esc_attr($days); ?>">

    <div class="suite-barrio-popup__overlay" data-suite-barrio-popup-close></div>

    <div class="suite-barrio-popup__dialog">
        <button type="button" class="suite-barrio-popup__close" data-suite-barrio-popup-close
                aria-label="<?php esc_attr_e('Chiudi', 'suite-barrio'); ?>">&times;</button>

        <?php if ('' !== $image): ?>
            <img class="suite-barrio-popup__image"
                 src="<?php echo esc_url($image); ?>"
                 alt="<?php echo esc_attr($title); ?>"
                 loading="lazy">
        <?php endif; ?>

        <div class="suite-barrio-popup__body">
            <?php if ('' !== $title): ?>
                <h2 class="suite-barrio-popup__title" id="<?php echo esc_attr($title_id); ?>">
                    <?php echo esc_html($title); ?>
                </h2>
            <?php endif; ?>

            <?php if ('' !== trim($content)): ?>
                <div class="suite-barrio-popup__content"><?php echo wp_kses_post(wpautop($content)); ?></div>
            <?php endif; ?>

            <?php if ('' !== $link): ?>
                <a class="suite-barrio-popup__cta"
                   href="<?php echo esc_url($link); ?>"
                   target="<?php echo esc_attr($link_target); ?>"<?php echo wp_kses_post($rel); ?>>
                    <?php echo esc_html('' !== $link_title ? $link_title : __('Scopri di più', 'suite-barrio')); ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>
