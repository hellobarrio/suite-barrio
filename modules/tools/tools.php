<?php
/**
 * Suite Barrio — Strumenti
 * Pagina di amministrazione riservata a support@hellobarrio.it
 * Permette di attivare/disattivare i singoli moduli del plugin.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   COSTANTI
   ───────────────────────────────────────── */
define('SUITE_BARRIO_TOOLS_OPTION', 'wpb_tools_modules');
define('SUITE_BARRIO_TOOLS_ALLOWED_EMAIL', 'support@hellobarrio.it');

/* ─────────────────────────────────────────
   HELPER: l'utente corrente può accedere?
   ───────────────────────────────────────── */
function suite_barrio_tools_can_access()
{
    if (!is_user_logged_in())
        return false;
    $user = wp_get_current_user();
    return strtolower($user->user_email) === SUITE_BARRIO_TOOLS_ALLOWED_EMAIL;
}

/* ─────────────────────────────────────────
   HELPER: elenco moduli con label e slug opzione
   ───────────────────────────────────────── */
function suite_barrio_tools_modules_list()
{
    return [
        'accessibility' => [
            'label' => 'Accessibilità',
            'desc'  => 'Widget di accessibilità frontend: regolazioni di testo, colore e orientamento per gli utenti del sito.',
        ],
        'auto_alt' => [
            'label' => 'Auto Alt',
            'desc' => 'Generazione automatica di alt text e title delle immagini tramite AI.',
        ],
        'blocks' => [
            'label' => 'Blocks',
            'desc' => 'Gestisci quali blocchi Gutenberg sono disponibili per gli utenti non amministratori.',
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
        'cookie_banner' => [
            'label' => 'Cookie Banner',
            'desc'  => 'Banner di consenso cookie nativo con Service Registry, scanner automatico e widget preferenze. Compatibile con siti PHP e Next.js headless.',
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
function suite_barrio_tools_module_enabled($key)
{
    $options = get_option(SUITE_BARRIO_TOOLS_OPTION, []);
    return isset($options[$key]) ? (bool) $options[$key] : true;
}

/* ─────────────────────────────────────────
   HELPER MULTILINGUA — rilevamento automatico WPML
   ───────────────────────────────────────── */
function suite_barrio_multilang_is_enabled(): bool
{
    if (!defined('ICL_SITEPRESS_VERSION'))
        return false;
    global $sitepress;
    if (!isset($sitepress) || !is_object($sitepress))
        return false;
    return count(suite_barrio_multilang_translation_languages()) > 0;
}

function suite_barrio_multilang_primary_language(): string
{
    if (!defined('ICL_SITEPRESS_VERSION'))
        return 'en';
    global $sitepress;
    if (!isset($sitepress) || !is_object($sitepress))
        return 'en';
    return (string) $sitepress->get_default_language();
}

function suite_barrio_multilang_translation_languages(): array
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

function suite_barrio_multilang_languages(): array
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

function suite_barrio_multilang_flag(string $lang_code): string
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

function suite_barrio_multilang_languages_with_flags(): array
{
    $langs = suite_barrio_multilang_languages();
    $result = [];
    foreach ($langs as $code => $label) {
        $flag = suite_barrio_multilang_flag($code);
        $result[$code] = $flag ? $flag . ' ' . $label : $label;
    }
    return $result;
}

/* ─────────────────────────────────────────
   WPML: nasconde lo switch lingua nella admin bar
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function () {
    if (!suite_barrio_multilang_is_enabled())
        return;
    $screen = get_current_screen();
    if (!$screen)
        return;
    if (strpos($screen->id, 'suite-barrio') === false)
        return;
    wp_add_inline_style('suite-barrio-admin', '#wp-admin-bar-WPML_ALS { display: none !important; }');
});

/* ─────────────────────────────────────────
   1. MENU
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (!suite_barrio_tools_can_access())
        return;
    add_submenu_page(
        'suite-barrio',
        'Strumenti — Suite Barrio',
        'Strumenti',
        'manage_options',
        'suite-barrio-tools',
        'suite_barrio_tools_admin_page'
    );
}, 20);

/* ─────────────────────────────────────────
   2. SALVATAGGIO IMPOSTAZIONI
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_tools_save', function () {
    if (!suite_barrio_tools_can_access())
        wp_die('Accesso negato.');
    check_admin_referer('suite_barrio_tools_save');
    $modules = suite_barrio_tools_modules_list();
    $options = [];
    foreach ($modules as $key => $data) {
        $options[$key] = isset($_POST['modules'][$key]) ? 1 : 0;
    }
    update_option(SUITE_BARRIO_TOOLS_OPTION, $options);
    wp_safe_redirect(admin_url('admin.php?page=suite-barrio-tools&saved=1'));
    exit;
});

/* ─────────────────────────────────────────
   3. PAGINA ADMIN
   ───────────────────────────────────────── */
function suite_barrio_tools_admin_page()
{
    if (!suite_barrio_tools_can_access()) {
        wp_die('Accesso riservato.');
    }

    $modules = suite_barrio_tools_modules_list();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $saved = isset($_GET['saved']);

    $wpml_active = defined('ICL_SITEPRESS_VERSION');
    $ml_enabled = suite_barrio_multilang_is_enabled();
    $ml_primary = suite_barrio_multilang_primary_language();
    $ml_trans = suite_barrio_multilang_translation_languages();
    $ml_languages = suite_barrio_multilang_languages_with_flags();
    ?>
    <div id="suite-barrio-tools-wrap" class="wrap suite-barrio-wrap">

        <div class="suite-barrio-tools-header">
            <div class="suite-barrio-tools-header-icon">
                <span class="dashicons dashicons-admin-tools"></span>
            </div>
            <div>
                <h1>Suite Barrio <span style="font-size:14px;font-weight:400;color:#9ca3af;">/ Strumenti</span></h1>
                <p class="suite-barrio-tools-header-sub">Gestione moduli e configurazione</p>
            </div>
        </div>

        <hr class="suite-barrio-tools-divider">

        <?php if ($saved): ?>
            <div class="suite-barrio-tools-notice suite-barrio-notice-success">
                <span class="dashicons dashicons-yes-alt"></span>
                <span>Impostazioni salvate.</span>
            </div>
        <?php endif; ?>

        <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-info-azienda')); ?>" class="suite-barrio-company-link">
            <div class="suite-barrio-company-link-icon">
                <span class="dashicons dashicons-id-alt"></span>
            </div>
            <div class="suite-barrio-company-link-text">
                <p class="suite-barrio-company-link-title">Info Azienda</p>
                <p class="suite-barrio-company-link-desc">
                    Gestisci ragione sociale, dati fiscali, contatti, indirizzo e social media dell'azienda.
                </p>
            </div>
            <span class="dashicons dashicons-arrow-right-alt2 suite-barrio-company-link-arrow"></span>
        </a>

        <?php if (function_exists('suite_barrio_cache_purge_render')) {
            suite_barrio_cache_purge_render();
        } ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('suite_barrio_tools_save'); ?>
            <input type="hidden" name="action" value="suite_barrio_tools_save">

            <p class="suite-barrio-tools-section-title">Moduli attivi</p>
            <p class="suite-barrio-tools-section-desc">
                Attiva o disattiva i singoli moduli del plugin. Un modulo disattivato non carica
                nessun hook, script o impostazione.
            </p>

            <div class="suite-barrio-modules-list">
                <?php foreach ($modules as $key => $data):
                    $enabled = suite_barrio_tools_module_enabled($key);
                    ?>
                    <div class="suite-barrio-module-row">
                        <div class="suite-barrio-module-info">
                            <div class="suite-barrio-module-label"><?php echo esc_html($data['label']); ?></div>
                            <div class="suite-barrio-module-desc"><?php echo esc_html($data['desc']); ?></div>
                        </div>
                        <label class="suite-barrio-toggle"
                            title="<?php echo $enabled ? 'Disattiva' : 'Attiva'; ?> <?php echo esc_attr($data['label']); ?>">
                            <input type="checkbox" name="modules[<?php echo esc_attr($key); ?>]" value="1" <?php checked($enabled, true); ?>>
                            <span class="suite-barrio-toggle-track"></span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="suite-barrio-save-btn">
                <span class="dashicons dashicons-yes" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span>
                Salva impostazioni
            </button>
        </form>

        <!-- Stato Multilingua -->
        <div class="suite-barrio-ml-section">
            <p class="suite-barrio-ml-section-title">
                <span class="dashicons dashicons-translation"></span>
                Multilingua
            </p>

            <?php if (!$wpml_active): ?>
                <div class="suite-barrio-ml-status suite-barrio-ml-neutral">
                    <span class="dashicons dashicons-info-outline"></span>
                    <span>WPML non rilevato. Il supporto multilingua si attiva automaticamente quando WPML è installato e
                        configurato.</span>
                </div>

            <?php elseif (!$ml_enabled): ?>
                <div class="suite-barrio-ml-status suite-barrio-ml-warning">
                    <span class="dashicons dashicons-warning"></span>
                    <span>WPML è attivo ma non ha lingue di traduzione configurate. Aggiungi almeno una lingua in
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wpml-language-switcher')); ?>">WPML → Lingue</a>
                        per abilitare il supporto multilingua.</span>
                </div>

            <?php else: ?>
                <div class="suite-barrio-ml-status suite-barrio-ml-active">
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

        <!-- Messa Online -->
        <div class="suite-barrio-ml-section">
            <p class="suite-barrio-ml-section-title">
                <span class="dashicons dashicons-migrate"></span>
                Messa Online
            </p>
            <?php
            $wiz_complete  = function_exists('suite_barrio_wizard_is_complete') && suite_barrio_wizard_is_complete();
            $wiz_badge     = function_exists('suite_barrio_wizard_badge_count') ? suite_barrio_wizard_badge_count() : 0;
            $wiz_url       = admin_url('admin.php?page=suite-barrio-wizard');
            ?>
            <div class="suite-barrio-wizard-banner">
                <div class="suite-barrio-wizard-banner-body">
                    <div class="suite-barrio-wizard-banner-icon">
                        <span class="dashicons dashicons-migrate"></span>
                    </div>
                    <div class="suite-barrio-wizard-banner-text">
                        <?php if ($wiz_complete && $wiz_badge === 0): ?>
                            <strong>Messa online completata</strong>
                            <span>Tutti i passaggi sono stati completati. Puoi riaprire il wizard in qualsiasi momento.</span>
                        <?php elseif ($wiz_complete && $wiz_badge > 0): ?>
                            <strong>Messa online — <?php echo esc_html($wiz_badge); ?> operazioni in sospeso</strong>
                            <span>Alcuni passaggi sono stati saltati o non completati. Rientra nel wizard per terminarli.</span>
                        <?php else: ?>
                            <strong>Wizard non ancora completato</strong>
                            <span>Segui la guida passo-passo per portare il sito online correttamente.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="<?php echo esc_url($wiz_url); ?>" class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-launch">
                    Controlla messa online &rarr;
                </a>
            </div>
        </div>

    </div>
    <?php
}
