<?php
/**
 * Suite Barrio — License Gate
 * Blocca l'intero plugin finché non viene inserita la chiave di licenza corretta.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   VERIFICA LICENZA
   ───────────────────────────────────────── */
function suite_barrio_license_is_active(): bool
{
    if (!defined('SUITE_BARRIO_LICENSE_KEY') || SUITE_BARRIO_LICENSE_KEY === '') {
        return false;
    }

    $stored = (string) get_option('wpb_license_key', '');
    if ($stored === '') {
        return false;
    }

    return hash_equals(SUITE_BARRIO_LICENSE_KEY, $stored);
}

/* ─────────────────────────────────────────
   MENU ADMIN — registrato solo se la licenza
   non è (ancora) attiva.
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    if (suite_barrio_license_is_active()) {
        return;
    }

    add_menu_page(
        'Suite Barrio',
        'Suite Barrio',
        'manage_options',
        'suite-barrio',
        'suite_barrio_license_render_page',
        'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjIwIiBhcmlhLWhpZGRlbj0idHJ1ZSIgZm9jdXNhYmxlPSJmYWxzZSIgcm9sZT0iaW1nIj48cGF0aCBmaWxsPSJibGFjayIgZD0iTTUsNWgyLjV2Mi41SDVaTTAsOC43NWgyLjV2Mi41SDBaTTIuNSwxMS4yNWgyLjV2Mi41SDIuNVpNNSwxMy43NWgxMHYyLjVINVpNMTcuNSw4Ljc1aDIuNXYyLjVIMTcuNVpNMTUsMTEuMjVoMi41djIuNUgxNVpNMTIuNSw1aDIuNXYyLjVIMTIuNVoiLz48L3N2Zz4=',
        80
    );
}, 9);

/* ─────────────────────────────────────────
   HANDLER ATTIVAZIONE
   ───────────────────────────────────────── */
add_action('admin_post_suite_barrio_license_activate', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Non autorizzato.', 'suite-barrio'));
    }

    check_admin_referer('suite_barrio_license_activate');

    $key = isset($_POST['wpb_license_key']) ? trim(wp_unslash($_POST['wpb_license_key'])) : '';
    $valid = defined('SUITE_BARRIO_LICENSE_KEY')
        && SUITE_BARRIO_LICENSE_KEY !== ''
        && $key !== ''
        && hash_equals(SUITE_BARRIO_LICENSE_KEY, $key);

    if ($valid) {
        update_option('wpb_license_key', $key);
        $status = 'success';
    } else {
        $status = 'error';
    }

    wp_safe_redirect(add_query_arg(
        ['page' => 'suite-barrio', 'suite_barrio_license' => $status],
        admin_url('admin.php')
    ));
    exit;
});

/* ─────────────────────────────────────────
   PAGINA DI ATTIVAZIONE
   ───────────────────────────────────────── */
function suite_barrio_license_render_page()
{
    $show_error = isset($_GET['suite_barrio_license']) && $_GET['suite_barrio_license'] === 'error';
    ?>
    <div class="wrap suite-barrio-wrap">

        <div class="suite-barrio-header">
            <div class="suite-barrio-header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="22" height="22" aria-hidden="true">
                    <path fill="#fff"
                        d="M5,5h2.5v2.5H5ZM0,8.75h2.5v2.5H0ZM2.5,11.25h2.5v2.5H2.5ZM5,13.75h10v2.5H5ZM17.5,8.75h2.5v2.5H17.5ZM15,11.25h2.5v2.5H15ZM12.5,5h2.5v2.5H12.5Z" />
                </svg>
            </div>
            <div>
                <h1>Suite Barrio</h1>
                <p class="suite-barrio-header-sub">Attiva il plugin per iniziare</p>
            </div>
        </div>

        <hr class="suite-barrio-divider">

        <?php if ($show_error): ?>
            <div class="suite-barrio-notice suite-barrio-notice-error">
                <span class="dashicons dashicons-warning"></span>
                <div>Chiave non valida. Verifica e riprova.</div>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:420px;">
            <input type="hidden" name="action" value="suite_barrio_license_activate">
            <?php wp_nonce_field('suite_barrio_license_activate'); ?>

            <label class="suite-barrio-field-label" for="wpb_license_key">Chiave di licenza</label>
            <input
                type="text"
                id="wpb_license_key"
                name="wpb_license_key"
                class="suite-barrio-input"
                style="width:100%;margin-bottom:12px;"
                autocomplete="off"
                required
            >
            <p class="suite-barrio-field-desc">Inserisci la chiave di licenza per attivare Suite Barrio.</p>

            <button type="submit" class="suite-barrio-btn suite-barrio-btn-primary" style="margin-top:12px;">Attiva</button>
        </form>

    </div>
    <?php
}
