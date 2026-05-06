<?php
/**
 * WP Barrio — Strumenti
 * Pagina di amministrazione riservata a support@hellobarrio.it
 * Permette di attivare/disattivare i singoli moduli del plugin.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   COSTANTI
   ───────────────────────────────────────── */
define('WPB_TOOLS_OPTION',        'wpb_tools_modules');
define('WPB_TOOLS_ALLOWED_EMAIL', 'support@hellobarrio.it');

/* ─────────────────────────────────────────
   HELPER: l'utente corrente può accedere?
   ───────────────────────────────────────── */
function wpb_tools_can_access()
{
    if (!is_user_logged_in())
        return false;
    $user = wp_get_current_user();
    return strtolower($user->user_email) === WPB_TOOLS_ALLOWED_EMAIL;
}

/* ─────────────────────────────────────────
   HELPER: elenco moduli con label e slug opzione
   ───────────────────────────────────────── */
function wpb_tools_modules_list()
{
    return [
        'auto_alt'    => [
            'label' => 'Auto Alt',
            'desc'  => 'Generazione automatica di alt text e title delle immagini tramite AI.',
        ],
        'forms'       => [
            'label' => 'Forms',
            'desc'  => 'Builder drag & drop per form di contatto.',
        ],
        'auto_fields' => [
            'label' => 'Auto Fields',
            'desc'  => 'Generazione automatica dei campi custom tramite AI.',
        ],
        'orari'       => [
            'label' => 'Orari',
            'desc'  => 'Sincronizzazione automatica degli orari di apertura da Google Places API.',
        ],
        'popups'      => [
            'label' => 'Popups',
            'desc'  => 'Crea e gestisci popup per il frontend. Un solo popup attivo alla volta.',
        ],
        'banners'     => [
            'label' => 'Banners',
            'desc'  => 'Crea e gestisci banner a scorrimento (marquee) per il frontend. Un solo banner attivo alla volta.',
        ],
        'seo'         => [
            'label' => 'SEO',
            'desc'  => 'Strumenti per l\'ottimizzazione sui motori di ricerca.',
        ],
    ];
}

/* ─────────────────────────────────────────
   HELPER: il modulo è attivo?
   ───────────────────────────────────────── */
function wpb_tools_module_enabled($key)
{
    $options = get_option(WPB_TOOLS_OPTION, []);
    // Default: tutti attivi se l'opzione non esiste ancora
    return isset($options[$key]) ? (bool) $options[$key] : true;
}

/* ─────────────────────────────────────────
   1. MENU — aggiunto dopo Auto LLMs (priority 20)
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!wpb_tools_can_access())
        return;

    add_submenu_page(
        'wp-barrio',
        'Strumenti — WP Barrio',
        'Strumenti',
        'manage_options',
        'wp-barrio-tools',
        'wpb_tools_admin_page'
    );
}, 20);

/* ─────────────────────────────────────────
   2. SALVATAGGIO IMPOSTAZIONI
   ───────────────────────────────────────── */
add_action('admin_post_wpb_tools_save', function () {
    if (!wpb_tools_can_access())
        wp_die('Accesso negato.');

    check_admin_referer('wpb_tools_save');

    $modules = wpb_tools_modules_list();
    $options = [];
    foreach ($modules as $key => $data) {
        $options[$key] = isset($_POST['modules'][$key]) ? 1 : 0;
    }
    update_option(WPB_TOOLS_OPTION, $options);

    wp_redirect(admin_url('admin.php?page=wp-barrio-tools&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   3. PAGINA ADMIN
   ───────────────────────────────────────── */
function wpb_tools_admin_page()
{
    if (!wpb_tools_can_access()) {
        wp_die('Accesso riservato.');
    }

    $modules = wpb_tools_modules_list();
    $saved   = isset($_GET['saved']);
    ?>
    <style>
        #wpb-tools-wrap { max-width: 700px; }

        .wpb-tools-card {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            margin-bottom: 2px;
        }

        .wpb-tools-row {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 20px;
            border-bottom: 1px solid #f0f0f1;
        }

        .wpb-tools-row:last-child { border-bottom: none; }

        .wpb-tools-info { flex: 1; }

        .wpb-tools-label {
            font-size: 13px;
            font-weight: 600;
            color: #1d2327;
            margin-bottom: 2px;
        }

        .wpb-tools-desc {
            font-size: 12px;
            color: #646970;
        }

        /* Toggle switch */
        .wpb-toggle {
            position: relative;
            width: 40px;
            height: 22px;
            flex-shrink: 0;
        }

        .wpb-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }

        .wpb-toggle-slider {
            position: absolute;
            inset: 0;
            background: #a7aaad;
            border-radius: 22px;
            cursor: pointer;
            transition: background .2s;
        }

        .wpb-toggle-slider:before {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            left: 3px;
            top: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform .2s;
        }

        .wpb-toggle input:checked + .wpb-toggle-slider {
            background: #00a32a;
        }

        .wpb-toggle input:checked + .wpb-toggle-slider:before {
            transform: translateX(18px);
        }
    </style>

    <div id="wpb-tools-wrap" class="wrap">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-admin-tools"
                style="font-size:26px;width:26px;height:26px;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
            WP Barrio
            <span style="font-size:15px;font-weight:400;color:#646970;margin-left:6px;">/ Strumenti</span>
        </h1>
        <hr class="wp-header-end" style="margin-bottom:20px;">

        <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible" style="margin-bottom:20px;">
                <p>Impostazioni salvate.</p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wpb_tools_save'); ?>
            <input type="hidden" name="action" value="wpb_tools_save">

            <h2 style="font-size:14px;font-weight:600;color:#1d2327;margin:0 0 8px;">Moduli attivi</h2>
            <p style="font-size:13px;color:#646970;margin:0 0 16px;">
                Attiva o disattiva i singoli moduli del plugin. Un modulo disattivato non carica
                nessun hook, script o impostazione.
            </p>

            <div class="wpb-tools-card">
                <?php foreach ($modules as $key => $data):
                    $enabled = wpb_tools_module_enabled($key);
                ?>
                    <div class="wpb-tools-row">
                        <div class="wpb-tools-info">
                            <div class="wpb-tools-label"><?php echo esc_html($data['label']); ?></div>
                            <div class="wpb-tools-desc"><?php echo esc_html($data['desc']); ?></div>
                        </div>
                        <label class="wpb-toggle">
                            <input type="checkbox"
                                   name="modules[<?php echo esc_attr($key); ?>]"
                                   value="1"
                                   <?php checked($enabled, true); ?>>
                            <span class="wpb-toggle-slider"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php submit_button('Salva impostazioni', 'primary', 'submit', false, ['style' => 'margin-top:16px;']); ?>
        </form>
    </div>
    <?php
}
