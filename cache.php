<?php
/**
 * Cache invalidation per Vercel.
 *
 * Invalida la cache Vercel ad ogni modifica di post, menu o opzioni ACF.
 * Il dominio viene letto dal "Sito web" configurato in Suite Barrio → Info Azienda.
 */

if (!defined('ABSPATH'))
    exit;

if (!function_exists('invalidate_vercel_cache')) {

    function invalidate_vercel_cache($post_id = 0)
    {
        // Previeni chiamate multiple per lo stesso post nella stessa richiesta
        static $processed = [];
        $cache_key = $post_id . '_' . current_action();

        if (isset($processed[$cache_key])) {
            return;
        }
        $processed[$cache_key] = true;

        // 1. Controlli di sicurezza e bypass
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            return;
        if (get_post_status($post_id) === 'auto-draft')
            return;
        if (wp_is_post_revision($post_id))
            return;

        // 2. Recupera il dominio dal modulo Info Azienda
        $site_domain = '';
        if (function_exists('suite_barrio_company_info_get')) {
            $company = suite_barrio_company_info_get();
            $sito_web = isset($company['sito_web']) ? trim((string) $company['sito_web']) : '';

            if ($sito_web !== '') {
                // Estrai solo l'host (es. "www.esempio.it") da un URL tipo "https://www.esempio.it/path"
                $parsed = wp_parse_url($sito_web);
                if (!empty($parsed['host'])) {
                    $site_domain = $parsed['host'];
                } else {
                    // Fallback: l'utente potrebbe aver inserito solo il dominio senza schema
                    $site_domain = preg_replace('#^/+|/+$#', '', $sito_web);
                }
            }
        }

        // Senza dominio configurato non possiamo invalidare nulla
        if ($site_domain === '') {
            return;
        }

        // 3. Determina i tag da invalidare
        $tags = "";

        if ($post_id) {

            // Controlla se si tratta di una option page
            if (current_action() === 'wp_update_nav_menu') {
                // Se l'azione è wp_update_nav_menu, è sicuramente un menu
                $tags = 'wp:global';
            } else if (is_string($post_id) && !is_numeric($post_id)) {
                // Tag arbitrario passato direttamente (es. "form_123")
                $tags = $post_id == "wp:global" ? "wp:global,home" : $post_id;
            } else {
                // Recupera l'oggetto post
                $post = get_post($post_id);

                // Se non esiste come post, potrebbe essere un menu
                if (!$post && is_nav_menu($post_id)) {
                    // Tag globale per menu
                    $tags = 'wp:global';
                } else if ($post) {
                    // Controlla se è la homepage
                    $is_homepage = (int) get_option('page_on_front') === (int) $post_id;

                    if ($is_homepage) {
                        // Tag specifico per homepage
                        $tags = 'home';
                    } else {
                        // Tag 1: L'URI specifico (es. wp:uri:chi-siamo)
                        // Usiamo wp_make_link_relative per avere solo il path
                        $full_url = get_permalink($post_id);
                        $uri = wp_make_link_relative($full_url);

                        // Rimuovi gli slash iniziali e finali, poi sostituisci tutti gli slash con underscore
                        $uri = trim($uri, '/');
                        $uri = str_replace('/', '_', $uri);

                        $tags = 'wp:uri:' . $uri . ',wp:type:' . $post->post_type;
                    }
                } else {
                    return; // Non è né un post né un menu valido
                }
            }
        }

        // Aggiungi i tag come parametri di query
        $query_params = http_build_query([
            'tag' => $tags,
        ]);

        // Configura l'URL dell'endpoint API
        $url = 'https://' . $site_domain . '/api/revalidate/';

        $full_url = $url . '?' . $query_params;

        // Se cURL non è disponibile, fai fallback su wp_remote_get
        if (!function_exists('curl_init')) {
            $response = wp_remote_get($full_url, [
                'headers' => [
                    'Authorization' => 'Bearer YOUR_TOKEN',
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 5,
            ]);
            return;
        }

        // Invia la richiesta GET (segue i reindirizzamenti come CURLOPT_FOLLOWLOCATION,
        // il body della risposta è sempre disponibile come con CURLOPT_RETURNTRANSFER)
        $response = wp_remote_get($full_url, [
            'headers' => [
                'Authorization' => 'Bearer YOUR_TOKEN',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    add_action('acf/save_post', 'invalidate_vercel_cache');
    add_action('wp_update_nav_menu', 'invalidate_vercel_cache');
    add_action('before_delete_post', 'invalidate_vercel_cache');  // eliminazione definitiva
    add_action('wp_trash_post', 'invalidate_vercel_cache');        // spostamento nel cestino
    add_action('untrashed_post', 'invalidate_vercel_cache');
}

/* ─────────────────────────────────────────
   PURGE MANUALE — endpoint AJAX riservato agli amministratori
   ───────────────────────────────────────── */
if (!function_exists('suite_barrio_cache_purge_ajax')) {
    function suite_barrio_cache_purge_ajax()
    {
        check_ajax_referer('suite_barrio_cache_purge', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permesso negato.'], 403);
        }

        $tag = isset($_POST['tag']) ? sanitize_text_field(wp_unslash($_POST['tag'])) : '';
        if ($tag === '') {
            wp_send_json_error(['message' => 'Specifica un tag.']);
        }

        if (!function_exists('invalidate_vercel_cache')) {
            wp_send_json_error(['message' => 'Funzione di invalidazione non disponibile.']);
        }

        invalidate_vercel_cache($tag);
        wp_send_json_success(['message' => 'Richiesta di invalidazione inviata per il tag: ' . $tag]);
    }
    add_action('wp_ajax_suite_barrio_cache_purge', 'suite_barrio_cache_purge_ajax');
}

/* ─────────────────────────────────────────
   PURGE MANUALE — asset JS del box (solo admin)
   Registrato/enqueued via admin_enqueue_scripts e iniettato con
   wp_add_inline_script(), mai echoed inline in HTML.
   ───────────────────────────────────────── */
if (!function_exists('suite_barrio_cache_purge_enqueue_assets')) {
    function suite_barrio_cache_purge_enqueue_assets($hook)
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_register_script('suite-barrio-cache-purge', '', [], SUITE_BARRIO_VERSION, true);
        wp_enqueue_script('suite-barrio-cache-purge');

        $js = '(function () {
    document.querySelectorAll(\'.suite-barrio-cache-purge\').forEach(function (box) {
        var btn = box.querySelector(\'.suite-barrio-cache-purge-btn\');
        var input = box.querySelector(\'.suite-barrio-cache-purge-input\');
        var status = box.querySelector(\'.suite-barrio-cache-purge-status\');
        if (!btn || btn.dataset.bound === \'1\') return;
        btn.dataset.bound = \'1\';

        function setStatus(msg, cls) {
            status.textContent = msg;
            status.className = \'suite-barrio-cache-purge-status\' + (cls ? \' \' + cls : \'\');
        }

        function send() {
            var tag = (input.value || \'\').trim();
            if (!tag) {
                setStatus(\'Inserisci un tag.\', \'err\');
                input.focus();
                return;
            }
            btn.disabled = true;
            setStatus(\'Invalidazione in corso…\', \'pending\');
            var body = new URLSearchParams({
                action: \'suite_barrio_cache_purge\',
                nonce: box.dataset.nonce,
                tag: tag
            });
            fetch(box.dataset.ajax, { method: \'POST\', body: body, credentials: \'same-origin\' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    btn.disabled = false;
                    if (j && j.success) {
                        setStatus((j.data && j.data.message) || \'Cache invalidata.\', \'ok\');
                        input.value = \'\';
                    } else {
                        setStatus((j && j.data && j.data.message) || "Errore durante l\'invalidazione.", \'err\');
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    setStatus(\'Errore di rete.\', \'err\');
                });
        }

        btn.addEventListener(\'click\', send);
        input.addEventListener(\'keydown\', function (e) {
            if (e.key === \'Enter\') {
                e.preventDefault();
                send();
            }
        });
    });
})();';

        wp_add_inline_script('suite-barrio-cache-purge', $js);
    }
    add_action('admin_enqueue_scripts', 'suite_barrio_cache_purge_enqueue_assets');
}

/* ─────────────────────────────────────────
   PURGE MANUALE — render del box (solo admin)
   Chiamato da dashboard.php e tools.php sotto il link "Info Azienda".
   ───────────────────────────────────────── */
if (!function_exists('suite_barrio_cache_purge_render')) {
    function suite_barrio_cache_purge_render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $nonce = wp_create_nonce('suite_barrio_cache_purge');
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <div class="suite-barrio-cache-purge" data-ajax="<?php echo esc_url($ajax_url); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
            <div class="suite-barrio-cache-purge-icon">
                <span class="dashicons dashicons-trash"></span>
            </div>
            <div class="suite-barrio-cache-purge-text">
                <p class="suite-barrio-cache-purge-title">Svuota cache Vercel</p>
                <p class="suite-barrio-cache-purge-desc">
                    Invalida manualmente la cache passando un tag specifico
                    (es. <code>wp:global</code>, <code>home</code>, <code>form_3</code>, <code>wp:option</code>).
                </p>
                <div class="suite-barrio-cache-purge-form">
                    <input type="text" class="suite-barrio-cache-purge-input" placeholder="Inserisci un tag…" required>
                    <button type="button" class="suite-barrio-cache-purge-btn">
                        <span class="dashicons dashicons-update"></span>
                        <span class="suite-barrio-cache-purge-btn-label">Svuota cache</span>
                    </button>
                </div>
                <p class="suite-barrio-cache-purge-status" aria-live="polite"></p>
            </div>
        </div>
        <?php
    }
}
