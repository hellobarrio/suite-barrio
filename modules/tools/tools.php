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
define('WPB_TOOLS_OPTION', 'wpb_tools_modules');
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
        'auto_alt' => [
            'label' => 'Auto Alt',
            'desc' => 'Generazione automatica di alt text e title delle immagini tramite AI.',
        ],
        'auto_fields' => [
            'label' => 'Auto Fields',
            'desc' => 'Generazione automatica dei campi custom tramite AI.',
        ],
        'banners' => [
            'label' => 'Banners',
            'desc' => 'Crea e gestisci banner a scorrimento (marquee) per il frontend. Un solo banner attivo alla volta.',
        ],
        'cookie_privacy' => [
            'label' => 'Cookie e Privacy',
            'desc' => 'Generazione automatica di Privacy Policy e Cookie Policy tramite Gemini.',
        ],
        'duplicate' => [
            'label' => 'Duplicate',
            'desc' => 'Duplica post e termini di tassonomia direttamente dalla lista, con un click.',
        ],
        'forms' => [
            'label' => 'Forms',
            'desc' => 'Builder drag & drop per form di contatto.',
        ],
        'orari' => [
            'label' => 'Orari',
            'desc' => 'Sincronizzazione automatica degli orari di apertura da Google Places API.',
        ],
        'orders' => [
            'label' => 'Orders',
            'desc' => 'Riordina a piacimento post type e termini di tassonomia tramite drag & drop.',
        ],
        'popups' => [
            'label' => 'Popups',
            'desc' => 'Crea e gestisci popup per il frontend. Un solo popup attivo alla volta.',
        ],
        'seo' => [
            'label' => 'SEO',
            'desc' => "Strumenti per l'ottimizzazione sui motori di ricerca.",
        ],
    ];
}

/* ─────────────────────────────────────────
   HELPER: il modulo è attivo?
   ───────────────────────────────────────── */
function wpb_tools_module_enabled($key)
{
    $options = get_option(WPB_TOOLS_OPTION, []);
    return isset($options[$key]) ? (bool) $options[$key] : true;
}

/* ─────────────────────────────────────────
   HELPER MULTILINGUA — rilevamento automatico WPML
   ───────────────────────────────────────── */
function wpb_multilang_is_enabled(): bool
{
    if (!defined('ICL_SITEPRESS_VERSION'))
        return false;
    global $sitepress;
    if (!isset($sitepress) || !is_object($sitepress))
        return false;
    return count(wpb_multilang_translation_languages()) > 0;
}

function wpb_multilang_primary_language(): string
{
    if (!defined('ICL_SITEPRESS_VERSION'))
        return 'en';
    global $sitepress;
    if (!isset($sitepress) || !is_object($sitepress))
        return 'en';
    return (string) $sitepress->get_default_language();
}

function wpb_multilang_translation_languages(): array
{
    if (!defined('ICL_SITEPRESS_VERSION'))
        return [];
    global $sitepress;
    if (!isset($sitepress) || !is_object($sitepress))
        return [];
    $primary = (string) $sitepress->get_default_language();
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

function wpb_multilang_flag(string $lang_code): string
{
    $map = [
        'it' => 'IT',
        'en' => 'GB',
        'fr' => 'FR',
        'de' => 'DE',
        'es' => 'ES',
        'pt' => 'PT',
        'nl' => 'NL',
        'pl' => 'PL',
        'ru' => 'RU',
        'zh' => 'CN',
        'ja' => 'JP',
        'ar' => 'SA',
        'sv' => 'SE',
        'da' => 'DK',
        'fi' => 'FI',
        'nb' => 'NO',
        'no' => 'NO',
        'cs' => 'CZ',
        'hu' => 'HU',
        'ro' => 'RO',
        'tr' => 'TR',
        'el' => 'GR',
        'uk' => 'UA',
        'ca' => 'ES',
        'hr' => 'HR',
        'sk' => 'SK',
        'sl' => 'SI',
        'bg' => 'BG',
        'sr' => 'RS',
        'he' => 'IL',
        'ko' => 'KR',
        'vi' => 'VN',
        'th' => 'TH',
        'id' => 'ID',
        'ms' => 'MY',
        'fa' => 'IR',
        'hi' => 'IN',
        'bn' => 'BD',
        'lt' => 'LT',
        'lv' => 'LV',
        'et' => 'EE',
        'en_us' => 'US',
        'en_gb' => 'GB',
        'pt_br' => 'BR',
        'zh_tw' => 'TW',
    ];
    $code = strtolower(str_replace('-', '_', $lang_code));
    $country = $map[$code] ?? $map[substr($code, 0, 2)] ?? null;
    if (!$country)
        return '';
    $flag = '';
    foreach (str_split(strtoupper($country)) as $char) {
        $flag .= mb_chr(0x1F1E6 + (ord($char) - ord('A')), 'UTF-8');
    }
    return $flag;
}

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
   ───────────────────────────────────────── */
add_action('admin_head', function () {
    if (!wpb_multilang_is_enabled())
        return;
    $screen = get_current_screen();
    if (!$screen)
        return;
    if (strpos($screen->id, 'wp-barrio') === false)
        return;
    echo '<style>#wp-admin-bar-WPML_ALS { display: none !important; }</style>';
});

/* ─────────────────────────────────────────
   1. MENU
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
    $saved = isset($_GET['saved']);

    $wpml_active = defined('ICL_SITEPRESS_VERSION');
    $ml_enabled = wpb_multilang_is_enabled();
    $ml_primary = wpb_multilang_primary_language();
    $ml_trans = wpb_multilang_translation_languages();
    $ml_languages = wpb_multilang_languages_with_flags();
    ?>
    <div id="wpb-tools-wrap" class="wrap">

        <div class="wpb-tools-header">
            <div class="wpb-tools-header-icon">
                <span class="dashicons dashicons-admin-tools"></span>
            </div>
            <div>
                <h1>WP Barrio <span style="font-size:14px;font-weight:400;color:#9ca3af;">/ Strumenti</span></h1>
                <p class="wpb-tools-header-sub">Gestione moduli e configurazione</p>
            </div>
        </div>

        <hr class="wpb-tools-divider">

        <?php if ($saved): ?>
            <div class="wpb-tools-notice wpb-notice-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <span>Impostazioni salvate.</span>
            </div>
        <?php endif; ?>

        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-info-azienda')); ?>" class="wpb-company-link">
            <div class="wpb-company-link-icon">
                <span class="dashicons dashicons-id-alt"></span>
            </div>
            <div class="wpb-company-link-text">
                <p class="wpb-company-link-title">Info Azienda</p>
                <p class="wpb-company-link-desc">
                    Gestisci ragione sociale, dati fiscali, contatti, indirizzo e social media dell'azienda.
                </p>
            </div>
            <span class="dashicons dashicons-arrow-right-alt2 wpb-company-link-arrow"></span>
        </a>

        <?php if (function_exists('wpb_cache_purge_render')) {
            wpb_cache_purge_render();
        } ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wpb_tools_save'); ?>
            <input type="hidden" name="action" value="wpb_tools_save">

            <p class="wpb-tools-section-title">Moduli attivi</p>
            <p class="wpb-tools-section-desc">
                Attiva o disattiva i singoli moduli del plugin. Un modulo disattivato non carica
                nessun hook, script o impostazione.
            </p>

            <div class="wpb-modules-list">
                <?php foreach ($modules as $key => $data):
                    $enabled = wpb_tools_module_enabled($key);
                    ?>
                    <div class="wpb-module-row">
                        <div class="wpb-module-info">
                            <div class="wpb-module-label"><?php echo esc_html($data['label']); ?></div>
                            <div class="wpb-module-desc"><?php echo esc_html($data['desc']); ?></div>
                        </div>
                        <label class="wpb-toggle"
                            title="<?php echo $enabled ? 'Disattiva' : 'Attiva'; ?> <?php echo esc_attr($data['label']); ?>">
                            <input type="checkbox" name="modules[<?php echo esc_attr($key); ?>]" value="1" <?php checked($enabled, true); ?>>
                            <span class="wpb-toggle-track"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="wpb-save-btn">
                <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span>
                Salva impostazioni
            </button>
        </form>

        <!-- Stato Multilingua -->
        <div class="wpb-ml-section">
            <p class="wpb-ml-section-title">
                <span class="dashicons dashicons-translation"></span>
                Multilingua
            </p>

            <?php if (!$wpml_active): ?>
                <div class="wpb-ml-status wpb-ml-neutral">
                    <span class="dashicons dashicons-info-outline"></span>
                    <span>WPML non rilevato. Il supporto multilingua si attiva automaticamente quando WPML è installato e
                        configurato.</span>
                </div>

            <?php elseif (!$ml_enabled): ?>
                <div class="wpb-ml-status wpb-ml-warning">
                    <span class="dashicons dashicons-warning"></span>
                    <span>WPML è attivo ma non ha lingue di traduzione configurate. Aggiungi almeno una lingua in
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wpml-language-switcher')); ?>">WPML → Lingue</a>
                        per abilitare il supporto multilingua.</span>
                </div>

            <?php else: ?>
                <div class="wpb-ml-status wpb-ml-active">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <div>
                        <strong>Multilingua attivo</strong> tramite WPML <?php echo esc_html(ICL_SITEPRESS_VERSION); ?>.<br>
                        <span style="display:inline-block;margin-top:4px;">
                            Lingua principale:
                            <strong><?php echo esc_html($ml_languages[$ml_primary] ?? $ml_primary); ?></strong>
                            &nbsp;&middot;&nbsp;
                            <?php
                            $trans_labels = array_map(fn($c) => $ml_languages[$c] ?? $c, $ml_trans);
                            echo 'Traduzioni: <strong>' . esc_html(implode(', ', $trans_labels)) . '</strong>';
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
    <?php
}
