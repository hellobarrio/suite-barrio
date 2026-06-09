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
        'orders'      => [
            'label' => 'Orders',
            'desc'  => 'Riordina a piacimento post type e termini di tassonomia tramite drag & drop.',
        ],
        'duplicate'   => [
            'label' => 'Duplicate',
            'desc'  => 'Duplica post e termini di tassonomia direttamente dalla lista, con un click.',
        ],
        'seo'         => [
            'label' => 'SEO',
            'desc'  => "Strumenti per l'ottimizzazione sui motori di ricerca.",
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
   HELPER MULTILINGUA — rilevamento automatico WPML

   Queste funzioni sono l'unica interfaccia usata dagli altri
   moduli per sapere se il sito è multilingua e quali lingue
   sono attive. Non esiste nessuna impostazione manuale:
   tutto viene letto direttamente da WPML.
   ───────────────────────────────────────── */

/**
 * true se WPML è attivo e ha almeno una lingua di traduzione
 * oltre alla principale.
 */
function wpb_multilang_is_enabled(): bool
{
    if (!defined('ICL_SITEPRESS_VERSION'))
        return false;

    global $sitepress;
    if (!isset($sitepress) || !is_object($sitepress))
        return false;

    return count(wpb_multilang_translation_languages()) > 0;
}

/**
 * Codice ISO della lingua principale configurata in WPML.
 * Fallback "en" se WPML non è disponibile.
 */
function wpb_multilang_primary_language(): string
{
    if (!defined('ICL_SITEPRESS_VERSION'))
        return 'en';

    global $sitepress;
    if (!isset($sitepress) || !is_object($sitepress))
        return 'en';

    return (string) $sitepress->get_default_language();
}

/**
 * Array di codici ISO delle lingue di traduzione attive in WPML,
 * esclusa la lingua principale.
 * Esempio: ['en', 'fr', 'de']
 * Restituisce [] se WPML non è disponibile o non ha traduzioni.
 */
function wpb_multilang_translation_languages(): array
{
    if (!defined('ICL_SITEPRESS_VERSION'))
        return [];

    global $sitepress;
    if (!isset($sitepress) || !is_object($sitepress))
        return [];

    $primary    = (string) $sitepress->get_default_language();
    $all_active = $sitepress->get_active_languages();

    if (!is_array($all_active))
        return [];

    $translations = [];
    foreach ($all_active as $code => $data) {
        if ((string) $code !== $primary) {
            $translations[] = (string) $code;
        }
    }

    return $translations;
}

/**
 * Array associativo code => nome nativo per tutte le lingue
 * attive in WPML (principale + traduzioni).
 * Esempio: ['it' => 'Italiano', 'en' => 'English']
 * Restituisce [] se WPML non è disponibile.
 */
function wpb_multilang_languages(): array
{
    if (!defined('ICL_SITEPRESS_VERSION'))
        return [];

    global $sitepress;
    if (!isset($sitepress) || !is_object($sitepress))
        return [];

    $all_active = $sitepress->get_active_languages();
    if (!is_array($all_active))
        return [];

    $result = [];
    foreach ($all_active as $code => $data) {
        $label = $data['native_name'] ?? $data['display_name'] ?? strtoupper((string) $code);
        $result[(string) $code] = (string) $label;
    }

    return $result;
}

/**
 * Restituisce la bandiera emoji per un codice lingua ISO 639-1.
 * Le emoji bandiera sono composte da due Regional Indicator Symbols
 * corrispondenti al codice paese ISO 3166-1 alpha-2.
 * Per le lingue senza corrispondenza 1:1 usa la bandiera più comune.
 */
function wpb_multilang_flag(string $lang_code): string
{
    // Mappa codice lingua → codice paese ISO 3166-1 alpha-2
    $map = [
        'it' => 'IT', 'en' => 'GB', 'fr' => 'FR', 'de' => 'DE',
        'es' => 'ES', 'pt' => 'PT', 'nl' => 'NL', 'pl' => 'PL',
        'ru' => 'RU', 'zh' => 'CN', 'ja' => 'JP', 'ar' => 'SA',
        'sv' => 'SE', 'da' => 'DK', 'fi' => 'FI', 'nb' => 'NO',
        'no' => 'NO', 'cs' => 'CZ', 'hu' => 'HU', 'ro' => 'RO',
        'tr' => 'TR', 'el' => 'GR', 'uk' => 'UA', 'ca' => 'ES',
        'hr' => 'HR', 'sk' => 'SK', 'sl' => 'SI', 'bg' => 'BG',
        'sr' => 'RS', 'he' => 'IL', 'ko' => 'KR', 'vi' => 'VN',
        'th' => 'TH', 'id' => 'ID', 'ms' => 'MY', 'fa' => 'IR',
        'hi' => 'IN', 'bn' => 'BD', 'lt' => 'LT', 'lv' => 'LV',
        'et' => 'EE', 'en_us' => 'US', 'en_gb' => 'GB',
        'pt_br' => 'BR', 'zh_tw' => 'TW',
    ];

    $code = strtolower(str_replace('-', '_', $lang_code));
    // Prova prima il codice completo (es. en_us), poi solo le prime 2 lettere
    $country = $map[$code] ?? $map[substr($code, 0, 2)] ?? null;
    if (!$country) return '';

    // Converti codice paese in emoji flag (Regional Indicator Symbols)
    $flag = '';
    foreach (str_split(strtoupper($country)) as $char) {
        $flag .= mb_chr(0x1F1E6 + (ord($char) - ord('A')), 'UTF-8');
    }
    return $flag;
}

/**
 * Restituisce le lingue attive con bandiera: ['it' => '🇮🇹 Italiano', ...]
 */
function wpb_multilang_languages_with_flags(): array
{
    $langs = wpb_multilang_languages();
    $result = [];
    foreach ($langs as $code => $label) {
        $flag = wpb_multilang_flag($code);
        $result[$code] = $flag ? $flag . ' ' . $label : $label;
    }
    return $result;
}

/* ─────────────────────────────────────────
   WPML: nasconde lo switch lingua nella admin bar
   su tutte le pagine del plugin WP Barrio.
   ───────────────────────────────────────── */
add_action('admin_head', function () {
    if (!wpb_multilang_is_enabled())
        return;

    $screen = get_current_screen();
    if (!$screen)
        return;

    // Tutte le pagine WP Barrio hanno l'ID che contiene "wp-barrio"
    if (strpos($screen->id, 'wp-barrio') === false)
        return;

    echo '<style>#wp-admin-bar-WPML_ALS { display: none !important; }</style>';
});

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

    // Stato WPML (solo lettura, nessuna impostazione manuale)
    $wpml_active  = defined('ICL_SITEPRESS_VERSION');
    $ml_enabled   = wpb_multilang_is_enabled();
    $ml_primary   = wpb_multilang_primary_language();
    $ml_trans     = wpb_multilang_translation_languages();
    $ml_languages = wpb_multilang_languages_with_flags();
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

        .wpb-toggle input:checked + .wpb-toggle-slider { background: #00a32a; }
        .wpb-toggle input:checked + .wpb-toggle-slider:before { transform: translateX(18px); }
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

        <!-- ── Stato Multilingua (sola lettura) ── -->
        <div style="margin-top:32px;">
            <h2 style="font-size:14px;font-weight:600;color:#1d2327;margin:0 0 8px;">
                <span class="dashicons dashicons-translation"
                      style="font-size:16px;width:16px;height:16px;vertical-align:middle;margin-right:4px;color:#2271b1;"></span>
                Multilingua
            </h2>

            <?php if (!$wpml_active): ?>
                <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 16px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:3px;font-size:13px;color:#646970;">
                    <span class="dashicons dashicons-info-outline" style="color:#a7aaad;font-size:18px;width:18px;height:18px;flex-shrink:0;margin-top:1px;"></span>
                    <span>WPML non rilevato. Il supporto multilingua negli strumenti di WP Barrio si attiva automaticamente quando WPML è installato e configurato.</span>
                </div>

            <?php elseif (!$ml_enabled): ?>
                <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 16px;background:#fcf9e8;border:1px solid #dba617;border-radius:3px;font-size:13px;color:#6e4e00;">
                    <span class="dashicons dashicons-warning" style="color:#dba617;font-size:18px;width:18px;height:18px;flex-shrink:0;margin-top:1px;"></span>
                    <span>WPML è attivo ma non ha lingue di traduzione configurate. Aggiungi almeno una lingua in
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wpml-language-switcher')); ?>">WPML → Lingue</a>
                    per abilitare il supporto multilingua negli strumenti.</span>
                </div>

            <?php else: ?>
                <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:3px;font-size:13px;color:#166534;">
                    <span class="dashicons dashicons-yes-alt" style="color:#00a32a;font-size:18px;width:18px;height:18px;flex-shrink:0;margin-top:1px;"></span>
                    <div>
                        <strong>Multilingua attivo</strong> tramite WPML <?php echo esc_html(ICL_SITEPRESS_VERSION); ?>.<br>
                        <span style="display:inline-block;margin-top:5px;color:#166534;">
                            Lingua principale: <strong><?php echo esc_html($ml_languages[$ml_primary] ?? $ml_primary); ?></strong>
                            &nbsp;&middot;&nbsp;
                            <?php
                            $trans_labels = array_map(fn($c) => $ml_languages[$c] ?? $c, $ml_trans);
                            echo 'Lingue di traduzione: <strong>' . esc_html(implode(', ', $trans_labels)) . '</strong>';
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php
}
