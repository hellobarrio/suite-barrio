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
<style>
.wpb-popup {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    opacity: 0;
    pointer-events: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    transition: opacity .3s ease-in-out;
}
.wpb-popup.is-open {
    opacity: 1;
    pointer-events: all;
}
.wpb-popup__overlay {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, .35);
    cursor: pointer;
}
.wpb-popup__dialog {
    position: relative;
    display: flex;
    flex-wrap: wrap;
    z-index: 1;
    width: 100%;
    max-width: 760px;
    max-height: 90vh;
    transform: translateY(30px);
    overflow: auto;
    background: white;
    color: black;
    box-shadow: 0 30px 60px rgba(0, 0, 0, .35);
    transition: transform .3s ease-in-out;
}
.wpb-popup.is-open .wpb-popup__dialog {
    transform: translateY(0);
}
.wpb-popup__close {
    position: absolute;
    top: 10px;
    right: 12px;
    z-index: 2;
    width: 40px;
    height: 40px;
    border: 0;
    background: white;
    border-radius: 50px;
    font-size: 30px;
    line-height: 1;
    color: black;
    cursor: pointer;
    transition: opacity .3s ease;
}
.wpb-popup__close:hover {
    opacity: .6;
}
.wpb-popup__image {
    display: block;
    width: 40%;
    height: auto;
    aspect-ratio: 1;
    object-fit: cover;
}
.wpb-popup__body {
    padding: 40px;
}
.wpb-popup__title {
    margin: 0 0 16px;
    font-weight: 400;
    font-size: 2rem;
    line-height: 1.2;
    color: black;
}
.wpb-popup__content {
    margin: 0 0 24px;
    font-size: 1rem;
    line-height: 1.4;
    color: black;
}
.wpb-popup__content :last-child {
    margin-bottom: 0;
}
.wpb-popup__cta {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 20px;
    background: black;
    border: 1px solid black;
    color: white;
    font-size: 0.875rem;
    font-weight: 500;
    text-transform: uppercase;
    text-decoration: none;
    transition: all .5s ease;
}
.wpb-popup__cta:hover {
    color: black;
    background: white;
}
@media (max-width: 639px) {
    .wpb-popup__body {
        padding: 28px 24px;
    }
    .wpb-popup__title {
        font-size: 1.5rem;
        line-height: 1.3;
    }
    .wpb-popup__image {
        width: 100%;
        height: auto;
        aspect-ratio: 4/3;
    }
}
</style>

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

<script>
(function () {
    var el = document.getElementById('<?php echo esc_js($wrap_id); ?>');
    if (!el || el.dataset.wpbPopupInit) return;
    el.dataset.wpbPopupInit = '1';

    var cookieName = 'wpb_popup_closed_<?php echo esc_js((string) $id); ?>';
    var days  = parseInt(el.dataset.days, 10) || 0;
    var delay = parseInt(el.dataset.delay, 10) || 0;

    function hasClosedCookie() {
        return document.cookie.split('; ').some(function (c) {
            return c.indexOf(cookieName + '=') === 0;
        });
    }
    function setClosedCookie() {
        if (days <= 0) return;
        var d = new Date();
        d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
        var secure = location.protocol === 'https:' ? '; secure' : '';
        document.cookie = cookieName + '=1; expires=' + d.toUTCString() + '; path=/; samesite=lax' + secure;
    }

    if (days > 0 && hasClosedCookie()) return;

    function open() {
        el.classList.add('is-open');
        document.documentElement.style.overflow = 'hidden';
    }
    function close() {
        el.classList.remove('is-open');
        document.documentElement.style.overflow = '';
        setClosedCookie();
    }

    el.querySelectorAll('[data-wpb-popup-close]').forEach(function (b) {
        b.addEventListener('click', close);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && el.classList.contains('is-open')) close();
    });

    if (delay > 0) { setTimeout(open, delay); } else { open(); }
})();
</script>
