<?php
/**
 * Cache invalidation per Vercel.
 *
 * Invalida la cache Vercel ad ogni modifica di post, menu o opzioni ACF.
 * Il dominio viene letto dal "Sito web" configurato in WP Barrio → Info Azienda.
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
        if (function_exists('wpb_company_info_get')) {
            $company = wpb_company_info_get();
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
            error_log('invalidate_vercel_cache: dominio "Sito web" non configurato in Info Azienda.');
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
            if (is_wp_error($response)) {
                error_log('Vercel revalidate error: ' . $response->get_error_message());
            } else {
                error_log('Vercel revalidate response: ' . wp_remote_retrieve_body($response));
            }
            return;
        }

        // Invia la richiesta GET utilizzando cURL
        $ch = curl_init($full_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer YOUR_TOKEN',
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Segui i reindirizzamenti

        $response = curl_exec($ch);

        // Controlla eventuali errori
        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            error_log('cURL Error: ' . $error_msg);
        } else {
            // Logga il risultato per il debug
            error_log('cURL Response: ' . $response);
        }
        curl_close($ch);
    }

    add_action('acf/save_post', 'invalidate_vercel_cache');
    add_action('wp_update_nav_menu', 'invalidate_vercel_cache');
    add_action('before_delete_post', 'invalidate_vercel_cache');  // eliminazione definitiva
    add_action('wp_trash_post', 'invalidate_vercel_cache');        // spostamento nel cestino
    add_action('untrashed_post', 'invalidate_vercel_cache');
}

/* ─────────────────────────────────────────
   INVALIDAZIONE PER ALLEGATO (immagini)
   Quando cambia l'alt text (o un campo) di un'immagine, risale a TUTTI i post che
   la usano e ne invalida la cache. Sempre attivo, indipendente dai moduli, così
   copre qualsiasi origine: pannello Media, schermata allegato, editor a
   blocchi/REST, WP-CLI e i moduli AI (es. Auto-Alt, disattivabile).
   ───────────────────────────────────────── */
if (!function_exists('invalidate_vercel_cache_for_attachment')) {

    function invalidate_vercel_cache_for_attachment($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if (!$attachment_id || !function_exists('invalidate_vercel_cache'))
            return;

        // Evita lookup/invalidazioni ripetute per lo stesso allegato nella stessa
        // richiesta: più hook possono scattare insieme (edit_attachment + updated_post_meta).
        static $done = [];
        if (isset($done[$attachment_id]))
            return;
        $done[$attachment_id] = true;

        global $wpdb;
        $ids = [];

        // 1. Immagine in evidenza
        $ids = array_merge($ids, (array) $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
            $attachment_id
        )));

        // 2. Inserita nel contenuto: WordPress marca gli <img> con la classe wp-image-<id>
        $ids = array_merge($ids, (array) $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s",
            '%wp-image-' . $attachment_id . '%'
        )));

        // 3. Campi ACF / altri postmeta che referenziano l'ID
        //    - image singola: meta_value = "123"
        //    - gallery/repeater serializzati: contengono la stringa "123"
        $ids = array_merge($ids, (array) $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value = %d OR meta_value LIKE %s",
            $attachment_id,
            '%"' . $attachment_id . '"%'
        )));

        // 4. post_parent diretto
        $parent = wp_get_post_parent_id($attachment_id);
        if ($parent)
            $ids[] = $parent;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        // Nessun utilizzo trovato: invalida il tag globale
        if (empty($ids)) {
            invalidate_vercel_cache('wp:global');
            return;
        }

        // invalidate_vercel_cache ignora già revisioni/autosave/ID non validi
        foreach ($ids as $post_id)
            invalidate_vercel_cache($post_id);
    }

    // 'edit_attachment' copre i salvataggi dal pannello Media e dalla schermata
    // allegato (passano da wp_update_post); gli hook su _wp_attachment_image_alt
    // coprono le modifiche al solo alt text (che non passano da wp_update_post).
    add_action('edit_attachment', 'invalidate_vercel_cache_for_attachment');

    $wpb_invalidate_on_alt_meta = function ($meta_id, $post_id, $meta_key) {
        if ($meta_key === '_wp_attachment_image_alt')
            invalidate_vercel_cache_for_attachment((int) $post_id);
    };
    add_action('added_post_meta', $wpb_invalidate_on_alt_meta, 10, 3);
    add_action('updated_post_meta', $wpb_invalidate_on_alt_meta, 10, 3);
}

/* ─────────────────────────────────────────
   PURGE MANUALE — endpoint AJAX riservato agli amministratori
   ───────────────────────────────────────── */
if (!function_exists('wpb_cache_purge_ajax')) {
    function wpb_cache_purge_ajax()
    {
        check_ajax_referer('wpb_cache_purge', 'nonce');

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
    add_action('wp_ajax_wpb_cache_purge', 'wpb_cache_purge_ajax');
}

/* ─────────────────────────────────────────
   PURGE MANUALE — render del box (solo admin)
   Chiamato da dashboard.php e tools.php sotto il link "Info Azienda".
   ───────────────────────────────────────── */
if (!function_exists('wpb_cache_purge_render')) {
    function wpb_cache_purge_render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $nonce = wp_create_nonce('wpb_cache_purge');
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <div class="wpb-cache-purge" data-ajax="<?php echo esc_url($ajax_url); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
            <div class="wpb-cache-purge-icon">
                <span class="dashicons dashicons-trash"></span>
            </div>
            <div class="wpb-cache-purge-text">
                <p class="wpb-cache-purge-title">Svuota cache Vercel</p>
                <p class="wpb-cache-purge-desc">
                    Invalida manualmente la cache passando un tag specifico
                    (es. <code>wp:global</code>, <code>home</code>, <code>form_3</code>, <code>wp:option</code>).
                </p>
                <div class="wpb-cache-purge-form">
                    <input type="text" class="wpb-cache-purge-input" placeholder="Inserisci un tag…" required>
                    <button type="button" class="wpb-cache-purge-btn">
                        <span class="dashicons dashicons-update"></span>
                        <span class="wpb-cache-purge-btn-label">Svuota cache</span>
                    </button>
                </div>
                <p class="wpb-cache-purge-status" aria-live="polite"></p>
            </div>
        </div>
        <script>
            (function () {
                document.querySelectorAll('.wpb-cache-purge').forEach(function (box) {
                    var btn = box.querySelector('.wpb-cache-purge-btn');
                    var input = box.querySelector('.wpb-cache-purge-input');
                    var status = box.querySelector('.wpb-cache-purge-status');
                    if (!btn || btn.dataset.bound === '1') return;
                    btn.dataset.bound = '1';

                    function setStatus(msg, cls) {
                        status.textContent = msg;
                        status.className = 'wpb-cache-purge-status' + (cls ? ' ' + cls : '');
                    }

                    function send() {
                        var tag = (input.value || '').trim();
                        if (!tag) {
                            setStatus('Inserisci un tag.', 'err');
                            input.focus();
                            return;
                        }
                        btn.disabled = true;
                        setStatus('Invalidazione in corso…', 'pending');
                        var body = new URLSearchParams({
                            action: 'wpb_cache_purge',
                            nonce: box.dataset.nonce,
                            tag: tag
                        });
                        fetch(box.dataset.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (j) {
                                btn.disabled = false;
                                if (j && j.success) {
                                    setStatus((j.data && j.data.message) || 'Cache invalidata.', 'ok');
                                    input.value = '';
                                } else {
                                    setStatus((j && j.data && j.data.message) || "Errore durante l'invalidazione.", 'err');
                                }
                            })
                            .catch(function () {
                                btn.disabled = false;
                                setStatus('Errore di rete.', 'err');
                            });
                    }

                    btn.addEventListener('click', send);
                    input.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            send();
                        }
                    });
                });
            })();
        </script>
        <?php
    }
}
