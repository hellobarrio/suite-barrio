<?php
/**
 * WP Barrio — Accessibilità
 * Widget di accessibilità frontend: posizione e colori configurabili dall'admin.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('WPB_ACCESSIBILITY_VERSION', '1.0.0');
define('WPB_ACCESSIBILITY_OPTION', 'wpb_accessibility_settings');

/* ─────────────────────────────────────────
   HELPERS
   ───────────────────────────────────────── */
function wpb_accessibility_settings(): array
{
    $defaults = [
        'position'            => 'left',
        'accent_color'        => '#ea9c17',
        'navy_color'          => '#415568',
        'declaration_enabled' => false,
        'declaration_content' => '',
    ];
    $s = get_option(WPB_ACCESSIBILITY_OPTION, []);
    return array_merge($defaults, is_array($s) ? $s : []);
}

function wpb_accessibility_darken_hex(string $hex, float $factor = 0.82): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3)
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    $r = (int) round(hexdec(substr($hex, 0, 2)) * $factor);
    $g = (int) round(hexdec(substr($hex, 2, 2)) * $factor);
    $b = (int) round(hexdec(substr($hex, 4, 2)) * $factor);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

function wpb_accessibility_hex_rgba(string $hex, float $alpha): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3)
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    return sprintf(
        'rgba(%d,%d,%d,%.2f)',
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
        $alpha
    );
}

/* ─────────────────────────────────────────
   MENU
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    add_submenu_page(
        'wp-barrio',
        'Accessibilità — WP Barrio',
        'Accessibilità',
        'manage_options',
        'wp-barrio-accessibility',
        'wpb_accessibility_admin_page'
    );
});

/* ─────────────────────────────────────────
   SALVATAGGIO
   ───────────────────────────────────────── */
add_action('admin_post_wpb_accessibility_save', function () {
    check_admin_referer('wpb_accessibility_save');
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');

    $position            = sanitize_key(wp_unslash($_POST['position'] ?? 'left'));
    $accent_color        = sanitize_hex_color(wp_unslash($_POST['accent_color'] ?? '#ea9c17')) ?: '#ea9c17';
    $navy_color          = sanitize_hex_color(wp_unslash($_POST['navy_color']   ?? '#415568')) ?: '#415568';
    $declaration_enabled = !empty($_POST['declaration_enabled']);
    $declaration_content = wp_kses_post(wp_unslash($_POST['declaration_content'] ?? ''));

    update_option(WPB_ACCESSIBILITY_OPTION, [
        'position'            => in_array($position, ['left', 'right'], true) ? $position : 'left',
        'accent_color'        => $accent_color,
        'navy_color'          => $navy_color,
        'declaration_enabled' => $declaration_enabled,
        'declaration_content' => $declaration_content,
    ]);

    wp_safe_redirect(admin_url('admin.php?page=wp-barrio-accessibility&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   REST API — script embed per Next.js / headless
   Endpoint: GET /wp-json/wp-barrio/v1/accessibility
   Restituisce application/javascript con CSS + JS
   già configurati (colori, posizione) pronti all'uso.
   ───────────────────────────────────────── */
add_action('rest_api_init', function () {
    register_rest_route('wp-barrio/v1', '/accessibility', [
        'methods'             => 'GET',
        'callback'            => 'wpb_accessibility_embed_handler',
        'permission_callback' => '__return_true',
    ]);
});

function wpb_accessibility_embed_handler(): void
{
    $s = wpb_accessibility_settings();

    $navy_deep   = wpb_accessibility_darken_hex($s['navy_color']);
    $accent_rgba = wpb_accessibility_hex_rgba($s['accent_color'], 0.28);

    $css_path = WP_ALT_AI_PLUGIN_DIR . 'modules/accessibility/assets/accessibility-widget.css';
    $js_path  = WP_ALT_AI_PLUGIN_DIR . 'modules/accessibility/assets/accessibility-widget.js';

    $css = (string) file_get_contents($css_path);
    $js  = (string) file_get_contents($js_path);

    // Prepend CSS variable + position overrides
    $overrides = "#a11y-widget {\n"
        . "  --a11y-accent:      {$s['accent_color']};\n"
        . "  --a11y-accent-glow: {$accent_rgba};\n"
        . "  --a11y-navy:        {$s['navy_color']};\n"
        . "  --a11y-navy-deep:   {$navy_deep};\n"
        . "}\n";

    if ($s['position'] === 'right') {
        $overrides .= "#a11y-widget { left: auto; right: 1.75rem; }\n"
            . "#a11y-widget .a11y-widget__panel { left: auto; right: 0; transform-origin: bottom right; }\n"
            . "@media (max-width: 575px) { #a11y-widget { left: auto; right: 1rem; } }\n";
    }

    $full_css = $css . $overrides;

    // Embed: inietta lo <style> nel <head> poi esegue il JS del widget
    $css_json    = json_encode($full_css, JSON_UNESCAPED_UNICODE);
    $config_json = json_encode([
        'declaration' => [
            'enabled' => !empty($s['declaration_enabled']),
            'content' => $s['declaration_content'] ?? '',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $embed    = <<<JS
(function(){if(document.getElementById('wpb-a11y-style'))return;var s=document.createElement('style');s.id='wpb-a11y-style';s.textContent={$css_json};(document.head||document.documentElement).appendChild(s);})();
window.__wpbA11yConfig={$config_json};
{$js}
JS;

    // Cache basata sull'hash delle impostazioni correnti
    $etag = '"' . md5($embed) . '"';
    $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH'])
        ? trim(wp_unslash($_SERVER['HTTP_IF_NONE_MATCH']))
        : '';

    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');

    if ($if_none_match === $etag) {
        status_header(304);
        exit;
    }

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $embed;
    exit;
}

/* ─────────────────────────────────────────
   FRONTEND — enqueueing + override CSS
   ───────────────────────────────────────── */
add_action('wp_enqueue_scripts', function () {
    $s = wpb_accessibility_settings();

    wp_enqueue_style(
        'wpb-accessibility-widget',
        WP_ALT_AI_PLUGIN_URL . 'modules/accessibility/assets/accessibility-widget.css',
        [],
        WPB_ACCESSIBILITY_VERSION
    );

    wp_enqueue_script(
        'wpb-accessibility-widget',
        WP_ALT_AI_PLUGIN_URL . 'modules/accessibility/assets/accessibility-widget.js',
        [],
        WPB_ACCESSIBILITY_VERSION,
        true
    );

    $navy_deep   = wpb_accessibility_darken_hex($s['navy_color']);
    $accent_rgba = wpb_accessibility_hex_rgba($s['accent_color'], 0.28);

    $inline = "#a11y-widget {\n"
        . "  --a11y-accent:      {$s['accent_color']};\n"
        . "  --a11y-accent-glow: {$accent_rgba};\n"
        . "  --a11y-navy:        {$s['navy_color']};\n"
        . "  --a11y-navy-deep:   {$navy_deep};\n"
        . "}\n";

    if ($s['position'] === 'right') {
        $inline .= "#a11y-widget { left: auto; right: 1.75rem; }\n"
            . "#a11y-widget .a11y-widget__panel { left: auto; right: 0; transform-origin: bottom right; }\n"
            . "@media (max-width: 575px) { #a11y-widget { left: auto; right: 1rem; } }\n";
    }

    wp_add_inline_style('wpb-accessibility-widget', $inline);

    $config_json = (string) json_encode([
        'declaration' => [
            'enabled' => !empty($s['declaration_enabled']),
            'content' => $s['declaration_content'] ?? '',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    wp_add_inline_script(
        'wpb-accessibility-widget',
        'window.__wpbA11yConfig=' . $config_json . ';',
        'before'
    );
});

/* ─────────────────────────────────────────
   PAGINA ADMIN
   ───────────────────────────────────────── */
function wpb_accessibility_admin_page()
{
    $s     = wpb_accessibility_settings();
    $saved = isset($_GET['saved']);
    ?>
    <div class="wrap wpb-wrap">

        <div class="wpb-header-row">
            <div class="wpb-header">
                <div class="wpb-header-icon">
                    <span class="dashicons dashicons-universal-access"
                        style="color:#fff;font-size:20px;width:20px;height:20px;"></span>
                </div>
                <div>
                    <h1>WP Barrio <span style="font-size:14px;font-weight:400;color:#9ca3af;">/ Accessibilità</span></h1>
                    <p class="wpb-header-sub">Widget di accessibilità per il frontend del sito</p>
                </div>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio')); ?>"
                class="wpb-btn wpb-btn-small wpb-btn-ghost">
                <span class="dashicons dashicons-arrow-left-alt2"
                    style="font-size:14px;width:14px;height:14px;"></span>
                Dashboard
            </a>
        </div>

        <hr class="wpb-divider">

        <?php if ($saved): ?>
            <div class="wpb-tools-notice wpb-notice-success" style="margin-bottom:16px;">
                <span class="dashicons dashicons-yes-alt"></span>
                <span>Impostazioni salvate.</span>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wpb_accessibility_save'); ?>
            <input type="hidden" name="action" value="wpb_accessibility_save">

            <div class="wpb-section-box" style="max-width:640px;">
                <div class="wpb-section-head">
                    <span class="dashicons dashicons-universal-access"></span> Impostazioni widget
                </div>
                <div class="wpb-section-body">

                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th scope="row" style="padding:10px 20px 10px 0;width:170px;">
                                <span style="font-weight:600;font-size:13px;color:#374151;">Posizione</span>
                            </th>
                            <td style="padding:10px 0;">
                                <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;margin-right:24px;">
                                    <input type="radio" name="position" value="left"
                                        <?php checked($s['position'], 'left'); ?>>
                                    In basso a sinistra
                                </label>
                                <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
                                    <input type="radio" name="position" value="right"
                                        <?php checked($s['position'], 'right'); ?>>
                                    In basso a destra
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding:10px 20px 10px 0;">
                                <label for="wpb-a11y-accent"
                                    style="font-weight:600;font-size:13px;color:#374151;">
                                    Colore principale
                                </label>
                            </th>
                            <td style="padding:10px 0;">
                                <div style="display:flex;align-items:center;gap:12px;">
                                    <input type="color" id="wpb-a11y-accent" name="accent_color"
                                        value="<?php echo esc_attr($s['accent_color']); ?>"
                                        style="width:48px;height:36px;padding:2px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;">
                                    <div>
                                        <div style="font-size:13px;color:#374151;">
                                            <?php echo esc_html($s['accent_color']); ?>
                                        </div>
                                        <div style="font-size:12px;color:#6b7280;margin-top:2px;">
                                            Bottoni attivi, accenti e bordo superiore del pannello
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding:10px 20px 10px 0;">
                                <label for="wpb-a11y-navy"
                                    style="font-weight:600;font-size:13px;color:#374151;">
                                    Colore secondario
                                </label>
                            </th>
                            <td style="padding:10px 0;">
                                <div style="display:flex;align-items:center;gap:12px;">
                                    <input type="color" id="wpb-a11y-navy" name="navy_color"
                                        value="<?php echo esc_attr($s['navy_color']); ?>"
                                        style="width:48px;height:36px;padding:2px;border:1px solid #d1d5db;border-radius:6px;cursor:pointer;">
                                    <div>
                                        <div style="font-size:13px;color:#374151;">
                                            <?php echo esc_html($s['navy_color']); ?>
                                        </div>
                                        <div style="font-size:12px;color:#6b7280;margin-top:2px;">
                                            Header pannello e pulsante trigger
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>

                </div>
            </div>

            <!-- Dichiarazione di Accessibilità -->
            <div class="wpb-section-box" style="max-width:640px;margin-top:24px;">
                <div class="wpb-section-head">
                    <span class="dashicons dashicons-text-page"></span> Dichiarazione di Accessibilità
                </div>
                <div class="wpb-section-body">
                    <p style="margin:0 0 14px;font-size:13px;color:#374151;">
                        Se attivata, un pulsante <strong>"Dichiarazione"</strong> comparirà nell'header
                        del widget. Cliccandolo l'utente vedrà il testo inserito qui sotto in una
                        schermata dedicata all'interno del pannello.
                    </p>
                    <table style="border-collapse:collapse;width:100%;">
                        <tr>
                            <th scope="row" style="padding:10px 20px 10px 0;width:180px;vertical-align:top;">
                                <label for="wpb-a11y-decl-enabled"
                                    style="font-weight:600;font-size:13px;color:#374151;">
                                    Attiva dichiarazione
                                </label>
                            </th>
                            <td style="padding:10px 0;">
                                <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
                                    <input type="checkbox" id="wpb-a11y-decl-enabled"
                                        name="declaration_enabled" value="1"
                                        <?php checked(!empty($s['declaration_enabled'])); ?>
                                        style="width:16px;height:16px;">
                                    <span style="font-size:13px;color:#374151;">Mostra il pulsante Dichiarazione nel widget</span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding:10px 20px 10px 0;vertical-align:top;">
                                <span style="font-weight:600;font-size:13px;color:#374151;">
                                    Testo della dichiarazione
                                </span>
                            </th>
                            <td style="padding:10px 0;">
                                <?php
                                wp_editor($s['declaration_content'], 'wpb_a11y_declaration', [
                                    'textarea_name' => 'declaration_content',
                                    'media_buttons' => false,
                                    'textarea_rows' => 12,
                                    'tinymce'       => [
                                        'toolbar1'      => 'formatselect | bold italic | bullist numlist | link | undo redo',
                                        'block_formats' => 'Paragrafo=p;Titolo 2=h2;Titolo 3=h3;Titolo 4=h4',
                                    ],
                                ]);
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div style="margin-top:16px;">
                <button type="submit" class="wpb-save-btn">
                    <span class="dashicons dashicons-yes"
                        style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span>
                    Salva impostazioni
                </button>
            </div>
        </form>

    </div>

        <?php
        $embed_url  = rest_url('wp-barrio/v1/accessibility');
        $parsed_url = wp_parse_url(home_url());
        $wp_origin  = $parsed_url['scheme'] . '://' . $parsed_url['host']
            . (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '');

        $step1_snippet = "import Script from 'next/script'\n\n"
            . "// Nel tuo layout.tsx o _app.tsx:\n"
            . "<Script\n"
            . '  src="' . esc_url($embed_url) . '"' . "\n"
            . "  strategy=\"afterInteractive\"\n"
            . "/>";

        $step2_snippet = "// next.config.js — aggiungi il dominio WordPress alla direttiva script-src\n"
            . "/** @type {import('next').NextConfig} */\n"
            . "const nextConfig = {\n"
            . "  async headers() {\n"
            . "    return [\n"
            . "      {\n"
            . "        source: '/(.*)',\n"
            . "        headers: [\n"
            . "          {\n"
            . "            key: 'Content-Security-Policy',\n"
            . "            // Aggiungi " . $wp_origin . " alla tua script-src esistente\n"
            . "            value: \"script-src 'self' 'unsafe-inline' 'unsafe-eval' "
            .               $wp_origin . " ...;\",\n"
            . "          },\n"
            . "        ],\n"
            . "      },\n"
            . "    ]\n"
            . "  },\n"
            . "}\n\n"
            . "module.exports = nextConfig";
        ?>
        <div class="wpb-section-box" style="max-width:680px;margin-top:24px;">
            <div class="wpb-section-head">
                <span class="dashicons dashicons-editor-code"></span> Integrazione Next.js
            </div>
            <div class="wpb-section-body">

                <!-- Step 1 -->
                <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:8px;">
                    <span style="flex-shrink:0;width:22px;height:22px;border-radius:50%;background:#0d9488;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;margin-top:2px;">1</span>
                    <div style="flex:1; width: calc(100% - 32px)">
                        <p style="margin:0 0 6px;font-size:13px;font-weight:600;color:#111827;">Carica il widget</p>
                        <p style="margin:0 0 10px;font-size:13px;color:#374151;">
                            Aggiungi questo tag nel tuo <code>layout.tsx</code> o <code>_app.tsx</code>.
                            Lo script include CSS, JS e impostazioni già configurate e si aggiorna automaticamente
                            quando salvi le impostazioni qui sopra.
                        </p>
                        <div style="position:relative;">
                            <pre id="wpb-a11y-snippet" style="background:#1e293b;color:#e2e8f0;padding:14px 16px;border-radius:8px;font-size:12px;line-height:1.6;overflow-x:auto;margin:0;white-space:pre;"><?php echo esc_html($step1_snippet); ?></pre>
                            <button type="button"
                                style="position:absolute;top:8px;right:8px;padding:4px 10px;border-radius:5px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#e2e8f0;font-size:11px;cursor:pointer;transition:background .15s;"
                                onclick="(function(b){var t=b.closest('div').querySelector('pre').textContent;navigator.clipboard.writeText(t).then(function(){b.textContent='Copiato!';setTimeout(function(){b.textContent='Copia';},1800);});})(this)">
                                Copia
                            </button>
                        </div>
                        <p style="margin:8px 0 0;font-size:12px;color:#6b7280;">
                            Endpoint:
                            <a href="<?php echo esc_url($embed_url); ?>" target="_blank" rel="noopener"
                                style="font-family:monospace;"><?php echo esc_html($embed_url); ?></a>
                        </p>
                    </div>
                </div>

                <hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0;">

                <!-- Step 2 -->
                <div style="display:flex;align-items:flex-start;gap:10px;">
                    <span style="flex-shrink:0;width:22px;height:22px;border-radius:50%;background:#0d9488;color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;margin-top:2px;">2</span>
                    <div style="flex:1; width: calc(100% - 32px)">
                        <p style="margin:0 0 6px;font-size:13px;font-weight:600;color:#111827;">Autorizza il dominio nel CSP</p>
                        <p style="margin:0 0 10px;font-size:13px;color:#374151;">
                            Se il tuo sito Next.js usa una <strong>Content Security Policy</strong>, aggiungi
                            il dominio WordPress (<code><?php echo esc_html($wp_origin); ?></code>) alla direttiva
                            <code>script-src</code>, altrimenti il browser bloccherà lo script.
                        </p>
                        <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:6px;padding:10px 12px;margin-bottom:10px;font-size:12px;color:#92400e;">
                            <strong>Errore tipico senza questa configurazione:</strong>
                            <code style="display:block;margin-top:4px;word-break:break-all;font-size:11px;color:#78350f;">
                                Refused to load the script '<?php echo esc_html($embed_url); ?>' because it violates the Content-Security-Policy directive "script-src ..."
                            </code>
                        </div>
                        <div style="position:relative;">
                            <pre id="wpb-a11y-snippet-csp" style="background:#1e293b;color:#e2e8f0;padding:14px 16px;border-radius:8px;font-size:12px;line-height:1.6;overflow-x:auto;margin:0;white-space:pre;"><?php echo esc_html($step2_snippet); ?></pre>
                            <button type="button"
                                style="position:absolute;top:8px;right:8px;padding:4px 10px;border-radius:5px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#e2e8f0;font-size:11px;cursor:pointer;transition:background .15s;"
                                onclick="(function(b){var t=b.closest('div').querySelector('pre').textContent;navigator.clipboard.writeText(t).then(function(){b.textContent='Copiato!';setTimeout(function(){b.textContent='Copia';},1800);});})(this)">
                                Copia
                            </button>
                        </div>
                        <p style="margin:10px 0 0;font-size:12px;color:#6b7280;">
                            Se gestisci il CSP in <code>middleware.ts</code>, aggiungi
                            <code><?php echo esc_html($wp_origin); ?></code> alla stessa direttiva <code>script-src</code>.
                        </p>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <script>
    (function () {
        var accentInput = document.getElementById('wpb-a11y-accent');
        var navyInput   = document.getElementById('wpb-a11y-navy');

        function bindColorLabel(input) {
            if (!input) return;
            var label = input.closest('td').querySelector('div > div:first-child');
            if (!label) return;
            input.addEventListener('input', function () {
                label.textContent = input.value;
            });
        }

        bindColorLabel(accentInput);
        bindColorLabel(navyInput);
    })();
    </script>
    <?php
}
