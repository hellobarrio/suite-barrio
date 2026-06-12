<?php
/**
 * WP Barrio — Info Azienda
 * Pagina di gestione delle informazioni anagrafiche dell'azienda,
 * organizzata in 3 tab: Dati azienda, Contatti, Social.
 * I dati vengono salvati in un'unica option `wpb_company_info`
 * e sono accessibili a tutti i moduli tramite la helper wpb_company_info_get().
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   COSTANTI
   ───────────────────────────────────────── */
define('WPB_COMPANY_INFO_VERSION', '1.1.0');
define('WPB_COMPANY_INFO_OPTION', 'wpb_company_info');

/* ─────────────────────────────────────────
   HELPER: definizione piatta dei campi.
   {chiave => {type => 'text|email|url|bool|textarea'}}
   Unico punto di verità per get + sanitize.
   ───────────────────────────────────────── */
function wpb_company_info_flat_keys()
{
    return [
        // Tab 1 — Dati azienda
        'ragione_sociale' => ['type' => 'text'],
        'sito_web' => ['type' => 'url'],
        'telefono' => ['type' => 'text'],
        'cellulare' => ['type' => 'text'],
        'email' => ['type' => 'email'],
        'email_privacy' => ['type' => 'email'],
        'partita_iva' => ['type' => 'text'],
        'codice_fiscale' => ['type' => 'text'],
        'rea' => ['type' => 'text'],
        'capitale_sociale' => ['type' => 'text'],
        'pec' => ['type' => 'email'],
        'codice_univoco' => ['type' => 'text'],

        // Tab 2 — Contatti / Sede legale
        'sede_via' => ['type' => 'text'],
        'sede_citta' => ['type' => 'text'],
        'sede_cap' => ['type' => 'text'],
        'sede_provincia' => ['type' => 'text'],
        'sede_google_maps' => ['type' => 'url'],

        // Tab 2 — Sede operativa (condizionale)
        'sede_operativa_diversa' => ['type' => 'bool'],
        'sede_op_via' => ['type' => 'text'],
        'sede_op_citta' => ['type' => 'text'],
        'sede_op_cap' => ['type' => 'text'],
        'sede_op_provincia' => ['type' => 'text'],
        'sede_op_google_maps' => ['type' => 'url'],

        // Tab 3 — Social
        'facebook' => ['type' => 'url'],
        'instagram' => ['type' => 'url'],
        'linkedin' => ['type' => 'url'],
        'twitter' => ['type' => 'url'],
        'youtube' => ['type' => 'url'],
        'telegram' => ['type' => 'url'],
        'tiktok' => ['type' => 'url'],
    ];
}

/* ─────────────────────────────────────────
   HELPER: legge tutti i dati salvati.
   Restituisce sempre un array con tutte le chiavi
   (vuote / false se non valorizzate).
   ───────────────────────────────────────── */
function wpb_company_info_get()
{
    $saved = get_option(WPB_COMPANY_INFO_OPTION, []);
    if (!is_array($saved))
        $saved = [];
    $data = [];
    foreach (wpb_company_info_flat_keys() as $key => $meta) {
        if ($meta['type'] === 'bool') {
            $data[$key] = !empty($saved[$key]);
        } else {
            $data[$key] = $saved[$key] ?? '';
        }
    }
    return $data;
}

/* ─────────────────────────────────────────
   HELPER: il modulo è "configurato"?
   ───────────────────────────────────────── */
function wpb_company_info_is_configured()
{
    $data = wpb_company_info_get();
    return !empty(trim($data['ragione_sociale'] ?? ''));
}

/* ─────────────────────────────────────────
   HELPER: tab disponibili
   ───────────────────────────────────────── */
function wpb_company_info_tabs()
{
    return [
        'dati' => ['label' => 'Dati azienda', 'icon' => 'dashicons-building'],
        'contatti' => ['label' => 'Contatti', 'icon' => 'dashicons-location'],
        'social' => ['label' => 'Social', 'icon' => 'dashicons-share'],
    ];
}

/* ─────────────────────────────────────────
   1. MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    add_submenu_page(
        'wp-barrio',
        'Info Azienda — WP Barrio',
        'Info Azienda',
        'manage_options',
        'wp-barrio-info-azienda',
        'wpb_company_info_admin_page'
    );
}, 11);

/* La pagina resta accessibile via URL e callback,
   ma viene nascosta dal menu laterale.
   La rimozione avviene su `admin_head`, non su `admin_menu`,
   perché il check `user_can_access_admin_page()` gira tra i due hook
   e si basa sulla presenza della voce in $submenu. */
add_action('admin_head', function () {
    remove_submenu_page('wp-barrio', 'wp-barrio-info-azienda');
}, 1);

/* ─────────────────────────────────────────
   2. SALVATAGGIO IMPOSTAZIONI
   ───────────────────────────────────────── */
add_action('admin_post_wpb_company_info_save', function () {
    if (!current_user_can('manage_options'))
        wp_die('Accesso negato.');
    check_admin_referer('wpb_company_info_save');

    $clean = [];
    foreach (wpb_company_info_flat_keys() as $key => $meta) {
        $raw = isset($_POST['company'][$key]) ? wp_unslash($_POST['company'][$key]) : '';
        switch ($meta['type']) {
            case 'bool':
                $clean[$key] = !empty($_POST['company'][$key]) ? 1 : 0;
                break;
            case 'email':
                $clean[$key] = sanitize_email($raw);
                break;
            case 'url':
                $clean[$key] = esc_url_raw(trim($raw));
                break;
            case 'textarea':
                $clean[$key] = sanitize_textarea_field($raw);
                break;
            default:
                $clean[$key] = sanitize_text_field($raw);
        }
    }

    update_option(WPB_COMPANY_INFO_OPTION, $clean);

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:option');
    }

    // Mantieni la tab attiva nel redirect
    $tabs = array_keys(wpb_company_info_tabs());
    $active = isset($_POST['active_tab']) && in_array($_POST['active_tab'], $tabs, true)
        ? $_POST['active_tab']
        : 'dati';

    wp_safe_redirect(admin_url('admin.php?page=wp-barrio-info-azienda&saved=1&tab=' . $active));
    exit;
});

/* ─────────────────────────────────────────
   3. PAGINA ADMIN
   ───────────────────────────────────────── */
function wpb_company_info_admin_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('Accesso riservato.');
    }

    $tabs = wpb_company_info_tabs();
    $data = wpb_company_info_get();
    $saved = isset($_GET['saved']);
    $active_tab = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? sanitize_key($_GET['tab']) : 'dati';
    ?>
<div id="wpb-company-wrap" class="wrap wpb-wrap">

        <div class="wpb-company-header">
            <div class="wpb-company-header-icon">
                <span class="dashicons dashicons-id-alt"></span>
            </div>
            <div>
                <h1>WP Barrio <span style="font-size:14px;font-weight:400;color:#9ca3af;">/ Info Azienda</span></h1>
                <p class="wpb-company-header-sub">Dati anagrafici, contatti e social dell'azienda</p>
            </div>
        </div>

        <hr class="wpb-company-divider">

        <?php if ($saved): ?>
            <div class="wpb-company-notice">
                <span class="dashicons dashicons-yes-alt"></span>
                <span>Informazioni aziendali salvate correttamente.</span>
            </div>
        <?php endif; ?>

        <!-- Tab nav -->
        <div class="wpb-tabs-nav" role="tablist">
            <?php foreach ($tabs as $key => $tab): ?>
                <button type="button" class="wpb-tab-button <?php echo $active_tab === $key ? 'is-active' : ''; ?>"
                    data-tab="<?php echo esc_attr($key); ?>" role="tab"
                    aria-selected="<?php echo $active_tab === $key ? 'true' : 'false'; ?>">
                    <span class="dashicons <?php echo esc_attr($tab['icon']); ?>"></span>
                    <?php echo esc_html($tab['label']); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wpb_company_info_save'); ?>
            <input type="hidden" name="action" value="wpb_company_info_save">
            <input type="hidden" name="active_tab" value="<?php echo esc_attr($active_tab); ?>" id="wpb-active-tab-input">

            <!-- ── TAB 1: Dati azienda ── -->
            <div class="wpb-tab-panel <?php echo $active_tab === 'dati' ? 'is-active' : ''; ?>" data-tab="dati"
                role="tabpanel">
                <div class="wpb-company-group">
                    <div class="wpb-company-group-head">
                        <div class="wpb-company-group-head-title">
                            <span class="dashicons dashicons-building"></span>
                            <p class="wpb-company-group-title">Dati azienda</p>
                        </div>
                    </div>
                    <div class="wpb-company-group-body">
                        <?php
                        $tab1_fields = [
                            'ragione_sociale' => ['label' => 'Ragione sociale', 'type' => 'text', 'full' => true],
                            'sito_web' => ['label' => 'Sito web', 'type' => 'url', 'placeholder' => 'https://...'],
                            'email' => ['label' => 'Email', 'type' => 'email'],
                            'email_privacy' => ['label' => 'Email privacy', 'type' => 'email', 'placeholder' => 'privacy@...'],
                            'pec' => ['label' => 'PEC', 'type' => 'email'],
                            'telefono' => ['label' => 'Telefono', 'type' => 'text', 'placeholder' => 'es. +39 081 123 4567'],
                            'cellulare' => ['label' => 'Cellulare', 'type' => 'text'],
                            'partita_iva' => ['label' => 'Partita IVA', 'type' => 'text'],
                            'codice_fiscale' => ['label' => 'Codice Fiscale', 'type' => 'text'],
                            'codice_univoco' => ['label' => 'Codice univoco (SDI)', 'type' => 'text', 'placeholder' => 'Fatturazione elettronica'],
                            'rea' => ['label' => 'N. REA', 'type' => 'text', 'placeholder' => 'Iscrizione Camera di Commercio'],
                            'capitale_sociale' => ['label' => 'Capitale sociale', 'type' => 'text', 'placeholder' => 'es. 10.000 € i.v.'],
                        ];
                        wpb_company_info_render_fields($tab1_fields, $data);
                        ?>
                    </div>
                </div>
            </div>

            <!-- ── TAB 2: Contatti (sedi) ── -->
            <div class="wpb-tab-panel <?php echo $active_tab === 'contatti' ? 'is-active' : ''; ?>" data-tab="contatti"
                role="tabpanel">
                <div class="wpb-company-group">
                    <div class="wpb-company-group-head">
                        <div class="wpb-company-group-head-title">
                            <span class="dashicons dashicons-location"></span>
                            <p class="wpb-company-group-title">Sede legale</p>
                        </div>
                    </div>
                    <div class="wpb-company-group-body">
                        <?php
                        $sede_legale_fields = [
                            'sede_via' => ['label' => 'Indirizzo', 'type' => 'text', 'full' => true, 'placeholder' => 'Via, numero civico'],
                            'sede_citta' => ['label' => 'Città', 'type' => 'text'],
                            'sede_cap' => ['label' => 'CAP', 'type' => 'text'],
                            'sede_provincia' => ['label' => 'Provincia', 'type' => 'text', 'placeholder' => 'es. NA'],
                            'sede_google_maps' => ['label' => 'Google Maps', 'type' => 'url', 'full' => true, 'placeholder' => 'https://maps.google.com/...'],
                        ];
                        wpb_company_info_render_fields($sede_legale_fields, $data);
                        ?>
                    </div>
                </div>

                <div class="wpb-company-toggle-row">
                    <div class="wpb-company-toggle-info">
                        <p class="wpb-company-toggle-label">Sede operativa diversa?</p>
                        <p class="wpb-company-toggle-desc">Attiva se la sede operativa è diversa da quella legale.</p>
                    </div>
                    <label class="wpb-toggle">
                        <input type="checkbox" name="company[sede_operativa_diversa]" value="1" id="wpb-sede-op-toggle"
                            <?php checked($data['sede_operativa_diversa'], true); ?>>
                        <span class="wpb-toggle-track"></span>
                    </label>
                </div>

                <div class="wpb-company-group" id="wpb-sede-op-block"
                    style="<?php echo $data['sede_operativa_diversa'] ? '' : 'display:none;'; ?>">
                    <div class="wpb-company-group-head">
                        <div class="wpb-company-group-head-title">
                            <span class="dashicons dashicons-store"></span>
                            <p class="wpb-company-group-title">Sede operativa</p>
                        </div>
                    </div>
                    <div class="wpb-company-group-body">
                        <?php
                        $sede_op_fields = [
                            'sede_op_via' => ['label' => 'Indirizzo', 'type' => 'text', 'full' => true, 'placeholder' => 'Via, numero civico'],
                            'sede_op_citta' => ['label' => 'Città', 'type' => 'text'],
                            'sede_op_cap' => ['label' => 'CAP', 'type' => 'text'],
                            'sede_op_provincia' => ['label' => 'Provincia', 'type' => 'text', 'placeholder' => 'es. NA'],
                            'sede_op_google_maps' => ['label' => 'Google Maps', 'type' => 'url', 'full' => true, 'placeholder' => 'https://maps.google.com/...'],
                        ];
                        wpb_company_info_render_fields($sede_op_fields, $data);
                        ?>
                    </div>
                </div>
            </div>

            <!-- ── TAB 3: Social ── -->
            <div class="wpb-tab-panel <?php echo $active_tab === 'social' ? 'is-active' : ''; ?>" data-tab="social"
                role="tabpanel">
                <div class="wpb-company-group">
                    <div class="wpb-company-group-head">
                        <div class="wpb-company-group-head-title">
                            <span class="dashicons dashicons-share"></span>
                            <p class="wpb-company-group-title">Social media</p>
                        </div>
                    </div>
                    <div class="wpb-company-group-body">
                        <?php
                        $social_fields = [
                            'facebook' => ['label' => 'Facebook', 'type' => 'url', 'placeholder' => 'https://facebook.com/...'],
                            'instagram' => ['label' => 'Instagram', 'type' => 'url', 'placeholder' => 'https://instagram.com/...'],
                            'linkedin' => ['label' => 'LinkedIn', 'type' => 'url', 'placeholder' => 'https://linkedin.com/...'],
                            'twitter' => ['label' => 'X (Twitter)', 'type' => 'url', 'placeholder' => 'https://x.com/...'],
                            'youtube' => ['label' => 'YouTube', 'type' => 'url', 'placeholder' => 'https://youtube.com/...'],
                            'telegram' => ['label' => 'Telegram', 'type' => 'url', 'placeholder' => 'https://t.me/...'],
                            'tiktok' => ['label' => 'TikTok', 'type' => 'url', 'placeholder' => 'https://tiktok.com/@...'],
                        ];
                        wpb_company_info_render_fields($social_fields, $data);
                        ?>
                    </div>
                </div>
            </div>

            <div class="wpb-company-actions">
                <button type="submit" class="wpb-company-save-btn">
                    <span class="dashicons dashicons-yes"
                        style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span>
                    Salva informazioni
                </button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio')); ?>" class="wpb-company-back-link">
                    ← Torna alla dashboard
                </a>
            </div>
        </form>

    </div>

    <script>
        (function () {
            // Tab switcher
            var buttons = document.querySelectorAll('.wpb-tab-button');
            var panels = document.querySelectorAll('.wpb-tab-panel');
            var activeInput = document.getElementById('wpb-active-tab-input');

            buttons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var target = btn.getAttribute('data-tab');
                    buttons.forEach(function (b) {
                        var on = b.getAttribute('data-tab') === target;
                        b.classList.toggle('is-active', on);
                        b.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    panels.forEach(function (p) {
                        p.classList.toggle('is-active', p.getAttribute('data-tab') === target);
                    });
                    if (activeInput) activeInput.value = target;
                    // Aggiorna l'URL senza ricaricare, così un eventuale refresh mantiene il tab
                    if (window.history && window.history.replaceState) {
                        var url = new URL(window.location.href);
                        url.searchParams.set('tab', target);
                        url.searchParams.delete('saved');
                        window.history.replaceState({}, '', url.toString());
                    }
                });
            });

            // Toggle sede operativa diversa
            var sedeOpToggle = document.getElementById('wpb-sede-op-toggle');
            var sedeOpBlock = document.getElementById('wpb-sede-op-block');
            if (sedeOpToggle && sedeOpBlock) {
                var refresh = function () {
                    sedeOpBlock.style.display = sedeOpToggle.checked ? '' : 'none';
                };
                sedeOpToggle.addEventListener('change', refresh);
                refresh();
            }
        })();
    </script>
    <?php
}

/* ─────────────────────────────────────────
   HELPER DI RENDERING: stampa un blocco di campi
   {key => {label, type, placeholder?, full?}}
   ───────────────────────────────────────── */
function wpb_company_info_render_fields(array $fields, array $data)
{
    foreach ($fields as $key => $field) {
        $value = $data[$key] ?? '';
        $placeholder = $field['placeholder'] ?? '';
        $is_full = !empty($field['full']) || ($field['type'] ?? '') === 'textarea';
        ?>
        <div class="wpb-company-field<?php echo $is_full ? ' wpb-company-field-full' : ''; ?>">
            <label for="wpb-company-<?php echo esc_attr($key); ?>">
                <?php echo esc_html($field['label']); ?>
            </label>
            <?php if (($field['type'] ?? 'text') === 'textarea'): ?>
                <textarea id="wpb-company-<?php echo esc_attr($key); ?>" name="company[<?php echo esc_attr($key); ?>]"
                    placeholder="<?php echo esc_attr($placeholder); ?>" rows="3"><?php echo esc_textarea($value); ?></textarea>
            <?php else: ?>
                <input type="<?php echo esc_attr($field['type'] ?? 'text'); ?>" id="wpb-company-<?php echo esc_attr($key); ?>"
                    name="company[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>"
                    placeholder="<?php echo esc_attr($placeholder); ?>">
            <?php endif; ?>
        </div>
        <?php
    }
}
