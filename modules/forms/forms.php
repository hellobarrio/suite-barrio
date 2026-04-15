<?php
/**
 * WP Barrio — Forms
 * Builder drag & drop per form di contatto personalizzati.
 *
 * @package WP_Barrio
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WPB_FORMS_VERSION',   '1.0.1' );
define( 'WPB_FORMS_TABLE',     'wpb_forms' );
define( 'WPB_FORMS_SUB_TABLE', 'wpb_form_submissions' );

/* ─────────────────────────────────────────
   INSTALL: crea le tabelle al primo avvio
   ───────────────────────────────────────── */
register_activation_hook( WP_ALT_AI_PLUGIN_DIR . 'wp-alt-ai.php', 'wpb_forms_install' );

function wpb_forms_install() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // CREATE TABLE IF NOT EXISTS — non dipende da dbDelta
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}wpb_forms` (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title      VARCHAR(255)    NOT NULL DEFAULT '',
            slug       VARCHAR(255)    NOT NULL DEFAULT '',
            fields     LONGTEXT        NOT NULL,
            settings   LONGTEXT        NOT NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) {$charset};"
    );

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}wpb_form_submissions` (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id    BIGINT UNSIGNED NOT NULL,
            data       LONGTEXT        NOT NULL,
            ip         VARCHAR(45)     NOT NULL DEFAULT '',
            user_agent TEXT,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id)
        ) {$charset};"
    );
}

add_action( 'plugins_loaded', 'wpb_forms_install' );

// Fallback: crea le tabelle anche alla prima visita admin
add_action( 'admin_init', function () {
    if ( ! get_option( 'wpb_forms_db_version' ) ) {
        wpb_forms_install();
        update_option( 'wpb_forms_db_version', WPB_FORMS_VERSION, false );
    }
} );

/* ─────────────────────────────────────────
   HELPER DB
   ───────────────────────────────────────── */
function wpb_forms_get_all() {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT id, title, slug, created_at,
                (SELECT COUNT(*) FROM {$wpdb->prefix}" . WPB_FORMS_SUB_TABLE . " s WHERE s.form_id = f.id) AS submissions
         FROM {$wpdb->prefix}" . WPB_FORMS_TABLE . " f
         ORDER BY id DESC"
    );
}

function wpb_forms_get( $id ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}" . WPB_FORMS_TABLE . " WHERE id = %d",
        intval( $id )
    ) );
}

function wpb_forms_get_submissions( $form_id, $limit = 50, $offset = 0 ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}" . WPB_FORMS_SUB_TABLE . "
         WHERE form_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
        intval( $form_id ), intval( $limit ), intval( $offset )
    ) );
}

function wpb_forms_count_submissions( $form_id ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}" . WPB_FORMS_SUB_TABLE . " WHERE form_id = %d",
        intval( $form_id )
    ) );
}

function wpb_forms_generate_slug( $title, $exclude_id = 0 ) {
    global $wpdb;
    $base = sanitize_title( $title );
    $slug = $base;
    $i    = 1;
    while ( $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}" . WPB_FORMS_TABLE . " WHERE slug = %s AND id != %d",
        $slug, $exclude_id
    ) ) ) {
        $slug = $base . '-' . ( ++$i );
    }
    return $slug;
}

/* ─────────────────────────────────────────
   SHORTCODE: [wpb_form id="X"]
   ───────────────────────────────────────── */
add_shortcode( 'wpb_form', 'wpb_forms_shortcode' );

function wpb_forms_shortcode( $atts ) {
    $atts = shortcode_atts( [ 'id' => 0 ], $atts );
    $form = wpb_forms_get( intval( $atts['id'] ) );
    if ( ! $form ) return '<p>Form non trovato.</p>';

    $fields       = json_decode( $form->fields,   true ) ?: [];
    $settings     = json_decode( $form->settings, true ) ?: [];
    $form_id_css  = 'wpb-form-' . $form->id;
    $after_submit = $settings['after_submit']    ?? 'message';
    $redirect_url = esc_url( $settings['redirect_url'] ?? '' );

    ob_start();
    ?>
    <div class="wpb-form-wrap" id="<?php echo esc_attr( $form_id_css ); ?>">
        <form class="wpb-contact-form" data-form-id="<?php echo intval( $form->id ); ?>" novalidate>
            <?php wp_nonce_field( 'wpb_form_submit_' . $form->id, 'wpb_nonce' ); ?>
            <input type="hidden" name="action"  value="wpb_forms_submit">
            <input type="hidden" name="form_id" value="<?php echo intval( $form->id ); ?>">

            <?php foreach ( $fields as $field ) :
                $fid         = esc_attr( $field['id'] ?? '' );
                $label       = esc_html( $field['label'] ?? '' );
                $required    = ! empty( $field['required'] );
                $type        = $field['type'] ?? 'text';
                $placeholder = esc_attr( $field['placeholder'] ?? '' );
                $req_attr    = $required ? 'required' : '';
                $req_star    = $required ? ' <span class="wpb-req">*</span>' : '';
            ?>
                <div class="wpb-field-wrap wpb-field-type-<?php echo esc_attr( $type ); ?>">
                    <label for="wpb-<?php echo $fid; ?>"><?php echo $label . $req_star; ?></label>

                    <?php if ( $type === 'textarea' ) : ?>
                        <textarea id="wpb-<?php echo $fid; ?>" name="<?php echo $fid; ?>"
                                  placeholder="<?php echo $placeholder; ?>" rows="5" <?php echo $req_attr; ?>></textarea>

                    <?php elseif ( $type === 'select' ) :
                        $options = $field['options'] ?? [];
                    ?>
                        <select id="wpb-<?php echo $fid; ?>" name="<?php echo $fid; ?>" <?php echo $req_attr; ?>>
                            <option value=""><?php echo esc_html( $field['placeholder'] ?? 'Seleziona…' ); ?></option>
                            <?php foreach ( $options as $opt ) : ?>
                                <option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif ( $type === 'radio' || $type === 'checkbox' ) :
                        $options = $field['options'] ?? [];
                    ?>
                        <div class="wpb-choice-group">
                        <?php foreach ( $options as $opt ) : ?>
                            <label class="wpb-choice-label">
                                <input type="<?php echo esc_attr( $type ); ?>"
                                       name="<?php echo $fid . ( $type === 'checkbox' ? '[]' : '' ); ?>"
                                       value="<?php echo esc_attr( $opt ); ?>">
                                <?php echo esc_html( $opt ); ?>
                            </label>
                        <?php endforeach; ?>
                        </div>

                    <?php else : ?>
                        <?php
                        $extra_attrs = '';
                        if ( $type === 'number' ) {
                            if ( isset( $field['min'] ) && $field['min'] !== '' ) $extra_attrs .= ' min="' . esc_attr( $field['min'] ) . '"';
                            if ( isset( $field['max'] ) && $field['max'] !== '' ) $extra_attrs .= ' max="' . esc_attr( $field['max'] ) . '"';
                        }
                        ?>
                        <input type="<?php echo esc_attr( $type ); ?>"
                               id="wpb-<?php echo $fid; ?>"
                               name="<?php echo $fid; ?>"
                               <?php echo $type !== 'date' ? 'placeholder="' . $placeholder . '"' : ''; ?>
                               <?php echo $extra_attrs; ?>
                               <?php echo $req_attr; ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="wpb-field-wrap wpb-field-submit">
                <button type="submit" class="wpb-submit-btn">
                    <?php echo esc_html( $settings['submit_text'] ?? 'Invia' ); ?>
                </button>
            </div>
            <div class="wpb-form-msg" style="display:none;"></div>
        </form>
    </div>

    <style>
    .wpb-form-wrap { max-width: 600px; }
    .wpb-form-wrap .wpb-field-wrap { margin-bottom: 18px; }
    .wpb-form-wrap label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
    .wpb-req { color: #d63638; }
    .wpb-form-wrap input[type=text],
    .wpb-form-wrap input[type=email],
    .wpb-form-wrap input[type=tel],
    .wpb-form-wrap input[type=number],
    .wpb-form-wrap select,
    .wpb-form-wrap textarea {
        width: 100%; padding: 10px 14px; font-size: 14px;
        border: 1px solid #c3c4c7; border-radius: 4px;
        background: #fff; box-sizing: border-box; transition: border-color .15s;
    }
    .wpb-form-wrap input:focus,
    .wpb-form-wrap select:focus,
    .wpb-form-wrap textarea:focus {
        outline: none; border-color: #2271b1; box-shadow: 0 0 0 2px rgba(34,113,177,.15);
    }
    .wpb-choice-group { display: flex; flex-direction: column; gap: 8px; }
    .wpb-choice-label { display: flex; align-items: center; gap: 8px; font-weight: 400; cursor: pointer; }
    .wpb-submit-btn {
        padding: 11px 28px; font-size: 15px; font-weight: 600;
        background: #2271b1; color: #fff; border: none; border-radius: 4px;
        cursor: pointer; transition: background .15s;
    }
    .wpb-submit-btn:hover { background: #135e96; }
    .wpb-submit-btn:disabled { background: #a7aaad; cursor: not-allowed; }
    .wpb-form-msg { padding: 12px 16px; border-radius: 4px; font-size: 14px; margin-top: 12px; }
    .wpb-form-msg.success { background: #edfaef; border: 1px solid #00a32a; color: #00a32a; }
    .wpb-form-msg.error   { background: #fcf0f1; border: 1px solid #d63638; color: #d63638; }
    </style>

    <script>
    (function(){
        var wrap = document.getElementById('<?php echo esc_js( $form_id_css ); ?>');
        if (!wrap) return;
        var form         = wrap.querySelector('.wpb-contact-form');
        var msgEl        = wrap.querySelector('.wpb-form-msg');
        var btn          = wrap.querySelector('.wpb-submit-btn');
        var afterSubmit  = '<?php echo esc_js( $after_submit ); ?>';
        var redirectUrl  = '<?php echo esc_js( $redirect_url ); ?>';
        var successMsg   = '<?php echo esc_js( $settings['success_message'] ?? 'Grazie! Il tuo messaggio è stato inviato.' ); ?>';
        var submitLabel  = btn.textContent;

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            btn.disabled    = true;
            btn.textContent = 'Invio in corso…';
            msgEl.className     = 'wpb-form-msg';
            msgEl.style.display = 'none';

            fetch('<?php echo admin_url( 'admin-ajax.php' ); ?>', {
                method: 'POST',
                body:   new FormData(form)
            })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (res.success) {
                    if (afterSubmit === 'redirect' && redirectUrl) {
                        window.location.href = redirectUrl;
                    } else {
                        msgEl.textContent   = res.data.message || successMsg;
                        msgEl.className     = 'wpb-form-msg success';
                        msgEl.style.display = 'block';
                        form.reset();
                    }
                } else {
                    msgEl.textContent   = res.data || 'Si è verificato un errore. Riprova.';
                    msgEl.className     = 'wpb-form-msg error';
                    msgEl.style.display = 'block';
                }
            })
            .catch(function() {
                msgEl.textContent   = 'Errore di rete. Riprova.';
                msgEl.className     = 'wpb-form-msg error';
                msgEl.style.display = 'block';
            })
            .finally(function() {
                btn.disabled    = false;
                btn.textContent = submitLabel;
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

/* ─────────────────────────────────────────
   AJAX: submit front-end
   ───────────────────────────────────────── */
add_action( 'wp_ajax_wpb_forms_submit',        'wpb_forms_handle_submit' );
add_action( 'wp_ajax_nopriv_wpb_forms_submit', 'wpb_forms_handle_submit' );

function wpb_forms_handle_submit() {
    global $wpdb;

    $form_id = intval( $_POST['form_id'] ?? 0 );
    if ( ! $form_id ) wp_send_json_error( 'Form non valido.' );

    check_ajax_referer( 'wpb_form_submit_' . $form_id, 'wpb_nonce' );

    $form = wpb_forms_get( $form_id );
    if ( ! $form ) wp_send_json_error( 'Form non trovato.' );

    $fields   = json_decode( $form->fields,   true ) ?: [];
    $settings = json_decode( $form->settings, true ) ?: [];

    foreach ( $fields as $field ) {
        if ( empty( $field['required'] ) ) continue;
        $fid = $field['id'] ?? '';
        $val = $_POST[ $fid ] ?? '';
        if ( is_array( $val ) ) $val = implode( ',', $val );
        if ( '' === trim( $val ) ) {
            $err = ! empty( $field['error_message'] )
                ? $field['error_message']
                : 'Il campo "' . esc_html( $field['label'] ) . '" è obbligatorio.';
            wp_send_json_error( $err );
        }
    }

    $submission_data = [];
    foreach ( $fields as $field ) {
        $fid = $field['id'] ?? '';
        $val = $_POST[ $fid ] ?? '';
        $val = is_array( $val ) ? array_map( 'sanitize_text_field', $val ) : sanitize_textarea_field( $val );
        $submission_data[ $fid ] = [ 'label' => $field['label'] ?? $fid, 'value' => $val ];
    }

    $wpdb->insert(
        $wpdb->prefix . WPB_FORMS_SUB_TABLE,
        [
            'form_id'    => $form_id,
            'data'       => wp_json_encode( $submission_data ),
            'ip'         => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
            'user_agent' => sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
        ],
        [ '%d', '%s', '%s', '%s' ]
    );

    if ( ! empty( $settings['notify_email'] ) ) {
        $to      = sanitize_email( $settings['notify_email'] );
        $subject = sprintf( '[%s] Nuova risposta: %s', get_bloginfo( 'name' ), $form->title );
        $body    = "Nuova risposta ricevuta per il form: {$form->title}\n\n";
        foreach ( $submission_data as $d ) {
            $v     = is_array( $d['value'] ) ? implode( ', ', $d['value'] ) : $d['value'];
            $body .= "{$d['label']}: {$v}\n";
        }
        $body .= "\n---\nData: " . current_time( 'Y-m-d H:i' );
        wp_mail( $to, $subject, $body );
    }

    wp_send_json_success( [ 'message' => $settings['success_message'] ?? 'Grazie! Il tuo messaggio è stato inviato.' ] );
}

/* ─────────────────────────────────────────
   AJAX ADMIN: salva form
   ───────────────────────────────────────── */
add_action( 'wp_ajax_wpb_forms_save', function () {
    check_ajax_referer( 'wpb_forms_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permesso negato.' );

    global $wpdb;

    $id       = intval( $_POST['form_id'] ?? 0 );
    $title    = sanitize_text_field( $_POST['title'] ?? '' );
    $fields   = wp_unslash( $_POST['fields']   ?? '[]' );
    $settings = wp_unslash( $_POST['settings'] ?? '{}' );

    if ( empty( $title ) ) wp_send_json_error( 'Il titolo è obbligatorio.' );
    if ( json_decode( $fields )   === null ) $fields   = '[]';
    if ( json_decode( $settings ) === null ) $settings = '{}';

    $table = $wpdb->prefix . WPB_FORMS_TABLE;

    if ( $id ) {
        $ok = $wpdb->update(
            $table,
            [ 'title' => $title, 'fields' => $fields, 'settings' => $settings ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s' ],
            [ '%d' ]
        );
        if ( $ok === false ) wp_send_json_error( 'Errore DB: ' . $wpdb->last_error );
        wp_send_json_success( [ 'id' => $id, 'message' => 'Form salvato.' ] );
    } else {
        $slug = wpb_forms_generate_slug( $title );
        $ok   = $wpdb->insert(
            $table,
            [ 'title' => $title, 'slug' => $slug, 'fields' => $fields, 'settings' => $settings ],
            [ '%s', '%s', '%s', '%s' ]
        );
        if ( $ok === false ) wp_send_json_error( 'Errore DB: ' . $wpdb->last_error );
        wp_send_json_success( [ 'id' => $wpdb->insert_id, 'message' => 'Form creato.' ] );
    }
} );

/* ─────────────────────────────────────────
   AJAX ADMIN: elimina form
   ───────────────────────────────────────── */
add_action( 'wp_ajax_wpb_forms_delete', function () {
    check_ajax_referer( 'wpb_forms_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permesso negato.' );
    global $wpdb;
    $id = intval( $_POST['form_id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( 'ID non valido.' );
    $wpdb->delete( $wpdb->prefix . WPB_FORMS_TABLE,     [ 'id'      => $id ], [ '%d' ] );
    $wpdb->delete( $wpdb->prefix . WPB_FORMS_SUB_TABLE, [ 'form_id' => $id ], [ '%d' ] );
    wp_send_json_success( 'Form eliminato.' );
} );

/* ─────────────────────────────────────────
   AJAX ADMIN: elimina submission
   ───────────────────────────────────────── */
add_action( 'wp_ajax_wpb_forms_delete_submission', function () {
    check_ajax_referer( 'wpb_forms_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permesso negato.' );
    global $wpdb;
    $id = intval( $_POST['submission_id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( 'ID non valido.' );
    $wpdb->delete( $wpdb->prefix . WPB_FORMS_SUB_TABLE, [ 'id' => $id ], [ '%d' ] );
    wp_send_json_success( 'Risposta eliminata.' );
} );

/* ─────────────────────────────────────────
   VOCE DI MENU ADMIN
   ───────────────────────────────────────── */
add_action( 'admin_menu', function () {
    add_submenu_page(
        'wp-barrio',
        'Forms — WP Barrio',
        'Forms',
        'manage_options',
        'wp-barrio-forms',
        'wpb_forms_admin_page'
    );
}, 11 );

/* ─────────────────────────────────────────
   PAGINA ADMIN FORMS
   ───────────────────────────────────────── */
function wpb_forms_admin_page() {

    // Nonce sempre disponibile prima di qualsiasi output
    $nonce = wp_create_nonce( 'wpb_forms_nonce' );

    $forms   = wpb_forms_get_all();
    $tab     = isset( $_GET['tab'] )     ? sanitize_key( $_GET['tab'] ) : 'list';
    $edit_id = isset( $_GET['edit_id'] ) ? intval( $_GET['edit_id'] )   : 0;
    $view_id = isset( $_GET['view_id'] ) ? intval( $_GET['view_id'] )   : 0;

    $edit_form       = $edit_id ? wpb_forms_get( $edit_id ) : null;
    $view_form       = $view_id ? wpb_forms_get( $view_id ) : null;
    $builder_form_id = ( $tab === 'builder' ) ? $edit_id : 0;

    // Dati builder (usati solo nel tab builder, ma dichiarati sempre per il JS)
    $saved_fields   = ( $edit_form ) ? $edit_form->fields   : '[]';
    $saved_settings = ( $edit_form ) ? $edit_form->settings : '{}';

    ?>
    <style>
    #wpb-forms-wrap { max-width: 1200px; }

    .wpb-forms-table-wrap { background:#fff; border:1px solid #c3c4c7; border-radius:3px; overflow:hidden; }
    .wpb-forms-table { width:100%; border-collapse:collapse; font-size:13px; }
    .wpb-forms-table th {
        background:#f0f0f1; padding:9px 14px; text-align:left;
        font-size:11px; font-weight:600; text-transform:uppercase;
        letter-spacing:.4px; color:#50575e; border-bottom:1px solid #c3c4c7;
    }
    .wpb-forms-table td { padding:10px 14px; border-bottom:1px solid #f0f0f1; vertical-align:middle; }
    .wpb-forms-table tr:last-child td { border-bottom:none; }
    .wpb-forms-table tr:hover td { background:#fafafa; }
    .wpb-forms-empty { text-align:center; padding:48px 20px; color:#646970; }
    .wpb-forms-empty .dashicons { font-size:48px; width:48px; height:48px; color:#c3c4c7; }

    .wpb-sub-count { display:inline-block; padding:1px 9px; border-radius:12px; font-size:11px; font-weight:600; background:#f0f0f1; color:#1d2327; }
    .wpb-sub-count.has-subs { background:#edfaef; color:#00a32a; }

    /* ── BUILDER ── */
    .wpb-builder-layout {
        display:grid; grid-template-columns:240px 1fr 280px; gap:0;
        border:1px solid #c3c4c7; border-radius:3px; overflow:hidden;
        background:#fff; min-height:580px;
    }
    .wpb-panel-fields { background:#f6f7f7; border-right:1px solid #c3c4c7; overflow-y:auto; }
    .wpb-panel-fields h3 {
        font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;
        color:#646970; padding:14px 16px 10px; border-bottom:1px solid #e0e0e0; margin:0;
    }
    .wpb-field-type-btn {
        display:flex; align-items:center; gap:10px; width:100%; padding:10px 16px;
        background:none; border:none; border-bottom:1px solid #e8e8e8; cursor:pointer;
        font-size:13px; font-family:inherit; color:#1d2327; text-align:left; transition:background .1s;
    }
    .wpb-field-type-btn:hover { background:#fff; }
    .wpb-ft-icon {
        width:28px; height:28px; border-radius:4px; background:#e0e0e0;
        display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0;
    }
    .wpb-field-type-btn:hover .wpb-ft-icon { background:#d0d5dd; }

    .wpb-panel-canvas { padding:20px; background:#fafafa; overflow-y:auto; border-right:1px solid #c3c4c7; }
    .wpb-canvas-title { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; color:#646970; margin:0 0 14px; }
    .wpb-canvas-drop-zone {
        min-height:380px; border:2px dashed #c3c4c7; border-radius:4px;
        padding:10px; transition:border-color .15s, background .15s;
    }
    .wpb-canvas-drop-zone.drag-over { border-color:#2271b1; background:rgba(34,113,177,.04); }
    .wpb-canvas-empty {
        display:flex; flex-direction:column; align-items:center;
        justify-content:center; height:260px; color:#a7aaad; pointer-events:none;
    }
    .wpb-canvas-empty .dashicons { font-size:40px; width:40px; height:40px; }
    .wpb-canvas-empty p { font-size:13px; margin:8px 0 0; }

    .wpb-field-card {
        background:#fff; border:1px solid #d0d5dd; border-radius:3px;
        margin-bottom:8px; cursor:grab; transition:box-shadow .1s, border-color .1s;
    }
    .wpb-field-card:active { cursor:grabbing; }
    .wpb-field-card.selected { border-color:#2271b1; box-shadow:0 0 0 2px rgba(34,113,177,.2); }
    .wpb-field-card.dragging { opacity:.4; }
    .wpb-field-card-header { display:flex; align-items:center; gap:8px; padding:9px 12px; border-bottom:1px solid #f0f0f1; }
    .wpb-drag-handle { color:#a7aaad; cursor:grab; font-size:16px; line-height:1; flex-shrink:0; }
    .wpb-field-card-label { font-size:13px; font-weight:600; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .wpb-field-card-type  { font-size:10px; color:#646970; background:#f0f0f1; padding:1px 7px; border-radius:3px; }
    .wpb-btn-remove-field {
        background:none; border:none; cursor:pointer; color:#d63638;
        font-size:18px; padding:0 2px; line-height:1; flex-shrink:0; opacity:.5; transition:opacity .1s;
    }
    .wpb-btn-remove-field:hover { opacity:1; }
    .wpb-field-card-preview { padding:8px 12px 10px; }
    .wpb-field-card-preview input,
    .wpb-field-card-preview select,
    .wpb-field-card-preview textarea {
        width:100%; padding:5px 8px; border:1px solid #d0d5dd; border-radius:3px;
        font-size:12px; color:#a7aaad; background:#fafafa; pointer-events:none; box-sizing:border-box;
    }
    .wpb-field-card-preview textarea { height:52px; resize:none; }

    .wpb-panel-props { overflow-y:auto; }
    .wpb-panel-props h3 {
        font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;
        color:#646970; padding:14px 16px 10px; border-bottom:1px solid #e0e0e0; margin:0; background:#f6f7f7;
    }
    .wpb-props-inner { padding:14px 16px; }
    .wpb-prop-group { margin-bottom:14px; }
    .wpb-prop-group label { display:block; font-size:11px; font-weight:600; color:#50575e; margin-bottom:5px; }
    .wpb-prop-group input[type=text],
    .wpb-prop-group input[type=number],
    .wpb-prop-group textarea,
    .wpb-prop-group select {
        width:100%; padding:6px 9px; border:1px solid #c3c4c7; border-radius:3px;
        font-size:12px; font-family:inherit; background:#fff; box-sizing:border-box;
    }
    .wpb-prop-group input:focus,
    .wpb-prop-group textarea:focus,
    .wpb-prop-group select:focus { outline:none; border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; }
    .wpb-prop-check { display:flex; align-items:center; gap:8px; font-size:12px; color:#1d2327; cursor:pointer; font-weight:400; }
    .wpb-no-field-selected { text-align:center; padding:40px 16px; color:#a7aaad; font-size:13px; }
    .wpb-no-field-selected .dashicons { font-size:36px; width:36px; height:36px; }

    .wpb-builder-actions { display:flex; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
    .wpb-builder-title-input {
        flex:1; min-width:200px; padding:7px 12px; font-size:15px; font-weight:600;
        border:1px solid #c3c4c7; border-radius:3px; font-family:inherit;
    }
    .wpb-builder-title-input:focus { outline:none; border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; }

    /* Opzioni lista */
    .wpb-options-list { display:flex; flex-direction:column; gap:5px; margin-top:6px; }
    .wpb-option-row { display:flex; gap:6px; align-items:center; }
    .wpb-option-row input[type=text] { flex:1; padding:4px 7px; font-size:12px; border:1px solid #c3c4c7; border-radius:3px; }
    .wpb-btn-rm-opt { background:none; border:none; color:#d63638; cursor:pointer; font-size:16px; line-height:1; padding:0; }
    .wpb-btn-add-opt {
        font-size:11px; background:none; border:1px dashed #c3c4c7; border-radius:3px;
        padding:4px 10px; cursor:pointer; color:#646970; margin-top:4px; width:100%;
    }
    .wpb-btn-add-opt:hover { border-color:#2271b1; color:#2271b1; }

    .wpb-builder-tabs { display:flex; border-bottom:1px solid #c3c4c7; margin-bottom:16px; }
    .wpb-btab {
        padding:8px 16px; font-size:13px; font-family:inherit; background:none;
        border:none; border-bottom:2px solid transparent; cursor:pointer; color:#646970; margin-bottom:-1px;
    }
    .wpb-btab.active { color:#2271b1; border-bottom-color:#2271b1; font-weight:600; }

    /* Toggle dopo-invio */
    .wpb-after-submit-toggle { display:flex; gap:0; margin-bottom:10px; border:1px solid #c3c4c7; border-radius:3px; overflow:hidden; }
    .wpb-ast-btn {
        flex:1; padding:7px 12px; font-size:12px; font-weight:600; font-family:inherit;
        border:none; border-right:1px solid #c3c4c7; cursor:pointer; background:#f6f7f7; color:#646970;
        transition:all .1s;
    }
    .wpb-ast-btn:last-child { border-right:none; }
    .wpb-ast-btn.active { background:#2271b1; color:#fff; }

    /* Submissions */
    .wpb-sub-table-wrap { background:#fff; border:1px solid #c3c4c7; border-radius:3px; overflow:hidden; overflow-x:auto; }
    .wpb-sub-table { width:100%; border-collapse:collapse; font-size:13px; min-width:500px; }
    .wpb-sub-table th {
        background:#f0f0f1; padding:8px 12px; text-align:left; font-weight:600;
        font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:#50575e; border-bottom:1px solid #c3c4c7;
    }
    .wpb-sub-table td { padding:9px 12px; border-bottom:1px solid #f0f0f1; vertical-align:top; font-size:12px; }
    .wpb-sub-table tr:last-child td { border-bottom:none; }
    .wpb-sub-table tr:hover td { background:#fafafa; }

    /* Toast */
    #wpb-forms-toast {
        position:fixed; bottom:24px; right:24px;
        background:#1d2327; color:#fff; padding:10px 18px; border-radius:3px;
        font-size:13px; font-weight:500; z-index:9999;
        opacity:0; transform:translateY(6px); transition:all .2s; pointer-events:none;
        border-left:4px solid #00a32a;
    }
    #wpb-forms-toast.show  { opacity:1; transform:translateY(0); }
    #wpb-forms-toast.error { border-left-color:#d63638; }

    /* ── Modale conferma elimina campo ── */
    #wpb-confirm-modal {
        display:none; position:fixed; inset:0; z-index:100000;
        background:rgba(0,0,0,.5); align-items:center; justify-content:center;
    }
    #wpb-confirm-modal.open { display:flex; }
    .wpb-modal-box {
        background:#fff; border-radius:4px; padding:28px 28px 22px;
        max-width:380px; width:90%; box-shadow:0 8px 32px rgba(0,0,0,.22);
        text-align:center;
    }
    .wpb-modal-box .wpb-modal-icon {
        font-size:32px; width:48px; height:48px; background:#fcf0f1;
        border-radius:50%; display:inline-flex; align-items:center;
        justify-content:center; margin-bottom:14px;
    }
    .wpb-modal-box h3 { font-size:15px; margin:0 0 8px; color:#1d2327; }
    .wpb-modal-box p  { font-size:13px; color:#646970; margin:0 0 20px; line-height:1.5; }
    .wpb-modal-actions { display:flex; gap:8px; justify-content:center; }
    .wpb-modal-btn-cancel {
        padding:7px 20px; font-size:13px; font-family:inherit; font-weight:600;
        background:#fff; border:1px solid #c3c4c7; border-radius:3px; cursor:pointer;
        color:#1d2327; transition:border-color .1s;
    }
    .wpb-modal-btn-cancel:hover { border-color:#1d2327; }
    .wpb-modal-btn-confirm {
        padding:7px 20px; font-size:13px; font-family:inherit; font-weight:600;
        background:#d63638; border:1px solid #d63638; border-radius:3px; cursor:pointer;
        color:#fff; transition:background .1s;
    }
    .wpb-modal-btn-confirm:hover { background:#b32d2e; border-color:#b32d2e; }
    </style>

    <div id="wpb-forms-wrap" class="wrap">
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-feedback" style="font-size:22px;width:22px;height:22px;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
            WP Barrio <span style="font-size:15px;font-weight:400;color:#646970;margin-left:4px;">/ Forms</span>
        </h1>

        <?php if ( $tab === 'list' ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-barrio-forms&tab=builder' ) ); ?>"
               class="page-title-action">+ Nuovo form</a>
        <?php endif; ?>
        <hr class="wp-header-end" style="margin-bottom:20px;">

        <?php /* ══════ LISTA ══════ */ if ( $tab === 'list' ) : ?>

        <div class="wpb-forms-table-wrap">
            <?php if ( empty( $forms ) ) : ?>
                <div class="wpb-forms-empty">
                    <span class="dashicons dashicons-feedback"></span>
                    <p style="font-size:14px;margin:10px 0 6px;font-weight:600;">Nessun form ancora</p>
                    <p style="font-size:13px;color:#a7aaad;margin:0 0 16px;">Crea il tuo primo form di contatto in pochi click.</p>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-barrio-forms&tab=builder' ) ); ?>"
                       class="button button-primary">+ Crea il primo form</a>
                </div>
            <?php else : ?>
                <table class="wpb-forms-table">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <th>Nome form</th>
                            <th>Risposte</th>
                            <th>Creato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $forms as $f ) :
                        $edit_url = admin_url( 'admin.php?page=wp-barrio-forms&tab=builder&edit_id=' . $f->id );
                        $subs_url = admin_url( 'admin.php?page=wp-barrio-forms&tab=submissions&view_id=' . $f->id );
                    ?>
                        <tr data-form-id="<?php echo intval( $f->id ); ?>">
                            <td style="color:#a7aaad;font-size:11px;"><?php echo intval( $f->id ); ?></td>
                            <td>
                                <strong>
                                    <a href="<?php echo esc_url( $edit_url ); ?>" style="text-decoration:none;">
                                        <?php echo esc_html( $f->title ); ?>
                                    </a>
                                </strong>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( $subs_url ); ?>" style="text-decoration:none;">
                                    <span class="wpb-sub-count <?php echo $f->submissions > 0 ? 'has-subs' : ''; ?>">
                                        <?php echo intval( $f->submissions ); ?> risposte
                                    </span>
                                </a>
                            </td>
                            <td style="font-size:12px;color:#646970;"><?php echo date_i18n( 'd/m/Y', strtotime( $f->created_at ) ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">Modifica</a>
                                <button class="button button-small wpb-btn-delete-form"
                                        style="color:#d63638;border-color:#d63638;margin-left:4px;"
                                        data-id="<?php echo intval( $f->id ); ?>"
                                        data-title="<?php echo esc_attr( $f->title ); ?>">Elimina</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php /* ══════ BUILDER ══════ */ elseif ( $tab === 'builder' ) : ?>

        <div class="wpb-builder-actions">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-barrio-forms' ) ); ?>"
               class="button">← Tutti i form</a>
            <input type="text" id="wpb-form-title" class="wpb-builder-title-input"
                   value="<?php echo esc_attr( $edit_form ? $edit_form->title : '' ); ?>"
                   placeholder="Nome del form…">
            <button class="button button-primary" id="wpb-btn-save-form">Salva form</button>
        </div>

        <div class="wpb-builder-tabs">
            <button class="wpb-btab active" data-tab="fields">Campi</button>
            <button class="wpb-btab"        data-tab="settings">Impostazioni</button>
        </div>

        <!-- TAB CAMPI -->
        <div id="wpb-tab-fields">
            <div class="wpb-builder-layout">

                <!-- Sx: tipi campo -->
                <div class="wpb-panel-fields">
                    <h3>Aggiungi campo</h3>
                    <?php
                    $field_types = [
                        'text'     => [ 'icon' => '𝐓', 'label' => 'Testo'          ],
                        'email'    => [ 'icon' => '✉',  'label' => 'Email'           ],
                        'textarea' => [ 'icon' => '≡',  'label' => 'Area di testo'   ],
                        'tel'      => [ 'icon' => '☎',  'label' => 'Telefono'        ],
                        'number'   => [ 'icon' => '#',  'label' => 'Numero'          ],
                        'date'     => [ 'icon' => '📅', 'label' => 'Data'            ],
                        'select'   => [ 'icon' => '▼',  'label' => 'Menù a tendina'  ],
                        'radio'    => [ 'icon' => '◉',  'label' => 'Scelta singola'  ],
                        'checkbox' => [ 'icon' => '☑',  'label' => 'Scelte multiple' ],
                    ];
                    foreach ( $field_types as $type => $data ) : ?>
                        <button class="wpb-field-type-btn" data-type="<?php echo esc_attr( $type ); ?>">
                            <span class="wpb-ft-icon"><?php echo $data['icon']; ?></span>
                            <?php echo esc_html( $data['label'] ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- Centro: canvas -->
                <div class="wpb-panel-canvas">
                    <p class="wpb-canvas-title">Struttura del form — trascina per riordinare</p>
                    <div id="wpb-drop-zone" class="wpb-canvas-drop-zone">
                        <div class="wpb-canvas-empty" id="wpb-canvas-empty">
                            <span class="dashicons dashicons-migrate"></span>
                            <p>Clicca un tipo di campo<br>per aggiungerlo al form</p>
                        </div>
                    </div>
                </div>

                <!-- Dx: proprietà -->
                <div class="wpb-panel-props">
                    <h3>Proprietà campo</h3>
                    <div id="wpb-props-inner" class="wpb-props-inner">
                        <div class="wpb-no-field-selected">
                            <span class="dashicons dashicons-edit-large"></span>
                            <p>Seleziona un campo<br>per modificarne le proprietà</p>
                        </div>
                    </div>
                </div>

            </div>
        </div><!-- #wpb-tab-fields -->

        <!-- TAB IMPOSTAZIONI -->
        <div id="wpb-tab-settings" style="display:none;">
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:24px;max-width:540px;">
                <table class="form-table" style="max-width:100%;"><tbody>

                    <tr>
                        <th style="width:200px;"><label>Testo pulsante invia</label></th>
                        <td><input type="text" id="wpb-setting-submit-text" class="regular-text" placeholder="Invia"></td>
                    </tr>

                    <tr>
                        <th style="vertical-align:top;padding-top:14px;"><label>Dopo l'invio</label></th>
                        <td>
                            <div class="wpb-after-submit-toggle">
                                <button type="button" class="wpb-ast-btn active" data-value="message">
                                    Mostra messaggio
                                </button>
                                <button type="button" class="wpb-ast-btn" data-value="redirect">
                                    Reindirizza a una pagina
                                </button>
                            </div>
                            <input type="hidden" id="wpb-setting-after-submit" value="message">

                            <div id="wpb-ast-panel-message">
                                <textarea id="wpb-setting-success-msg" rows="3" class="large-text"
                                          placeholder="Grazie! Il tuo messaggio è stato inviato."></textarea>
                                <p class="description">Testo mostrato al visitatore dopo l'invio.</p>
                            </div>

                            <div id="wpb-ast-panel-redirect" style="display:none;">
                                <input type="url" id="wpb-setting-redirect-url" class="large-text"
                                       placeholder="https://example.com/grazie">
                                <p class="description">URL della pagina di ringraziamento a cui rimandare l'utente.</p>
                            </div>
                        </td>
                    </tr>

                </tbody></table>
            </div>
        </div><!-- #wpb-tab-settings -->

        <?php /* ══════ SUBMISSIONS ══════ */ elseif ( $tab === 'submissions' && $view_form ) :
            $subs        = wpb_forms_get_submissions( $view_form->id, 50, 0 );
            $total_subs  = wpb_forms_count_submissions( $view_form->id );
            $form_fields = json_decode( $view_form->fields, true ) ?: [];
        ?>

        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-barrio-forms' ) ); ?>"
           class="button" style="margin-bottom:16px;">← Tutti i form</a>

        <h2 style="font-size:16px;margin:0 0 4px;">
            Risposte: <strong><?php echo esc_html( $view_form->title ); ?></strong>
        </h2>
        <p style="margin:0 0 16px;color:#646970;font-size:13px;">
            <?php echo $total_subs; ?> rispost<?php echo $total_subs === 1 ? 'a' : 'e'; ?> ricevut<?php echo $total_subs === 1 ? 'a' : 'e'; ?>
        </p>

        <?php if ( empty( $subs ) ) : ?>
            <div style="text-align:center;padding:48px;background:#fff;border:1px solid #c3c4c7;border-radius:3px;color:#646970;">
                <span class="dashicons dashicons-email-alt" style="font-size:40px;width:40px;height:40px;color:#c3c4c7;"></span>
                <p style="margin:12px 0 0;font-size:13px;">Nessuna risposta ricevuta ancora.</p>
            </div>
        <?php else : ?>
            <div class="wpb-sub-table-wrap">
                <table class="wpb-sub-table">
                    <thead>
                        <tr>
                            <th style="width:40px">#</th>
                            <?php foreach ( $form_fields as $ff ) : ?>
                                <th><?php echo esc_html( $ff['label'] ?? $ff['id'] ); ?></th>
                            <?php endforeach; ?>
                            <th>IP</th>
                            <th>Data</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $subs as $sub ) :
                        $sub_data = json_decode( $sub->data, true ) ?: [];
                    ?>
                        <tr>
                            <td style="color:#a7aaad;font-size:11px;"><?php echo intval( $sub->id ); ?></td>
                            <?php foreach ( $form_fields as $ff ) :
                                $fid = $ff['id'] ?? '';
                                $val = $sub_data[ $fid ]['value'] ?? '—';
                                if ( is_array( $val ) ) $val = implode( ', ', $val );
                            ?>
                                <td><?php echo esc_html( $val ); ?></td>
                            <?php endforeach; ?>
                            <td style="color:#a7aaad;font-size:11px;"><?php echo esc_html( $sub->ip ); ?></td>
                            <td style="font-size:11px;color:#646970;white-space:nowrap;"><?php echo date_i18n( 'd/m/Y H:i', strtotime( $sub->created_at ) ); ?></td>
                            <td>
                                <button class="button button-small wpb-btn-del-sub"
                                        style="color:#d63638;border-color:#d63638;"
                                        data-id="<?php echo intval( $sub->id ); ?>">🗑</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php endif; // fine switch tab ?>

    </div><!-- #wpb-forms-wrap -->
    <div id="wpb-forms-toast"></div>

    <!-- Modale conferma eliminazione campo -->
    <div id="wpb-confirm-modal" role="dialog" aria-modal="true">
        <div class="wpb-modal-box">
            <div class="wpb-modal-icon">🗑</div>
            <h3>Eliminare questo campo?</h3>
            <p id="wpb-modal-field-name">L'operazione non può essere annullata.</p>
            <div class="wpb-modal-actions">
                <button class="wpb-modal-btn-cancel" id="wpb-modal-cancel">Annulla</button>
                <button class="wpb-modal-btn-confirm" id="wpb-modal-confirm">Elimina</button>
            </div>
        </div>
    </div>

    <script>
    (function(){
        const NONCE   = '<?php echo esc_js( $nonce ); ?>';
        const AJAX    = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        const EDIT_ID = <?php echo intval( $builder_form_id ); ?>;

        function toast(msg, type = 'success') {
            const t = document.getElementById('wpb-forms-toast');
            if (!t) return;
            t.textContent = msg;
            t.className = 'show' + (type === 'error' ? ' error' : '');
            clearTimeout(t._timer);
            t._timer = setTimeout(() => t.className = '', 3500);
        }

        /* ── Elimina form ── */
        document.querySelectorAll('.wpb-btn-delete-form').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Eliminare il form "' + btn.dataset.title + '" e tutte le sue risposte?')) return;
                btn.disabled = true;
                const res  = await fetch(AJAX, { method:'POST', body: new URLSearchParams({ action:'wpb_forms_delete', nonce:NONCE, form_id:btn.dataset.id }) });
                const data = await res.json();
                if (data.success) {
                    const row = btn.closest('tr');
                    row.style.transition = 'opacity .3s'; row.style.opacity = '0';
                    setTimeout(() => row.remove(), 300);
                    toast('Form eliminato.');
                } else { toast(data.data || 'Errore.', 'error'); btn.disabled = false; }
            });
        });

        /* ── Elimina submission ── */
        document.querySelectorAll('.wpb-btn-del-sub').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Eliminare questa risposta?')) return;
                btn.disabled = true;
                const res  = await fetch(AJAX, { method:'POST', body: new URLSearchParams({ action:'wpb_forms_delete_submission', nonce:NONCE, submission_id:btn.dataset.id }) });
                const data = await res.json();
                if (data.success) {
                    const row = btn.closest('tr');
                    row.style.transition = 'opacity .3s'; row.style.opacity = '0';
                    setTimeout(() => row.remove(), 300);
                    toast('Risposta eliminata.');
                } else { toast(data.data || 'Errore.', 'error'); btn.disabled = false; }
            });
        });

        /* ════════════════════════════
           BUILDER
           ════════════════════════════ */
        if (!document.getElementById('wpb-drop-zone')) return;

        let fields   = <?php echo wp_json_encode( json_decode( $saved_fields   ) ?: [] ); ?>;
        let settings = <?php echo wp_json_encode( json_decode( $saved_settings ) ?: new stdClass() ); ?>;
        if (!Array.isArray(fields))                   fields   = [];
        if (typeof settings !== 'object' || !settings) settings = {};

        let selectedFieldId = null;
        let dragSrcIdx      = null;

        const dropZone   = document.getElementById('wpb-drop-zone');
        const emptyState = document.getElementById('wpb-canvas-empty');
        const propsInner = document.getElementById('wpb-props-inner');

        /* ── Impostazioni UI ── */
        function initSettings() {
            const s  = settings;
            const el = id => document.getElementById(id);
            if (el('wpb-setting-submit-text'))  el('wpb-setting-submit-text').value  = s.submit_text     || '';
            if (el('wpb-setting-success-msg'))  el('wpb-setting-success-msg').value  = s.success_message || '';
            if (el('wpb-setting-redirect-url')) el('wpb-setting-redirect-url').value = s.redirect_url    || '';
            const after = s.after_submit || 'message';
            setAfterPanel(after);
        }

        function setAfterPanel(val) {
            const msgP = document.getElementById('wpb-ast-panel-message');
            const redP = document.getElementById('wpb-ast-panel-redirect');
            const hid  = document.getElementById('wpb-setting-after-submit');
            if (msgP) msgP.style.display = val === 'message'  ? '' : 'none';
            if (redP) redP.style.display = val === 'redirect' ? '' : 'none';
            if (hid)  hid.value = val;
            document.querySelectorAll('.wpb-ast-btn').forEach(b => b.classList.toggle('active', b.dataset.value === val));
        }

        document.querySelectorAll('.wpb-ast-btn').forEach(b => b.addEventListener('click', () => setAfterPanel(b.dataset.value)));
        initSettings();

        function readSettings() {
            const el = id => document.getElementById(id);
            return {
                submit_text:     el('wpb-setting-submit-text')  ? el('wpb-setting-submit-text').value.trim()  : (settings.submit_text     || 'Invia'),
                success_message: el('wpb-setting-success-msg')  ? el('wpb-setting-success-msg').value.trim()  : (settings.success_message || ''),
                after_submit:    el('wpb-setting-after-submit') ? el('wpb-setting-after-submit').value        : (settings.after_submit    || 'message'),
                redirect_url:    el('wpb-setting-redirect-url') ? el('wpb-setting-redirect-url').value.trim() : (settings.redirect_url    || ''),
            };
        }

        /* ── Helpers ── */
        function genId() { return 'field_' + Math.random().toString(36).slice(2, 9); }
        function esc(s)  { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

        const TYPE_LABELS = {
            text:'Testo', email:'Email', textarea:'Area di testo',
            tel:'Telefono', number:'Numero', date:'Data',
            select:'Seleziona', radio:'Scelta', checkbox:'Scelta multipla'
        };

        /* ── Builder tabs ── */
        document.querySelectorAll('.wpb-btab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.wpb-btab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('wpb-tab-fields').style.display   = tab.dataset.tab === 'fields'   ? '' : 'none';
                document.getElementById('wpb-tab-settings').style.display = tab.dataset.tab === 'settings' ? '' : 'none';
            });
        });

        /* ── Aggiungi campo ── */
        function addField(type) {
            const f = {
                id: genId(), type,
                label:         TYPE_LABELS[type] || type,
                placeholder:   '',
                required:      false,
                error_message: '',
                options:       ['select','radio','checkbox'].includes(type) ? ['Opzione 1','Opzione 2'] : [],
            };
            if (type === 'number') { f.min = ''; f.max = ''; }
            fields.push(f);
            renderCanvas();
            selectField(fields[fields.length - 1].id);
        }

        /* ── Canvas ── */
        function renderCanvas() {
            dropZone.querySelectorAll('.wpb-field-card').forEach(c => c.remove());
            emptyState.style.display = fields.length === 0 ? 'flex' : 'none';
            fields.forEach((f, i) => dropZone.appendChild(buildCard(f, i)));
        }

        function buildCard(f, idx) {
            const card = document.createElement('div');
            card.className   = 'wpb-field-card' + (f.id === selectedFieldId ? ' selected' : '');
            card.dataset.id  = f.id;
            card.dataset.idx = idx;
            card.draggable   = true;

            let preview = f.type === 'textarea'
                ? `<textarea placeholder="${esc(f.placeholder||f.label)}" disabled></textarea>`
                : f.type === 'select'
                    ? `<select disabled><option>${esc(f.placeholder||f.label)}</option></select>`
                    : f.type === 'date'
                        ? `<input type="date" disabled>`
                        : `<input type="${esc(f.type)}" placeholder="${esc(f.placeholder||f.label)}" disabled>`;

            card.innerHTML = `
                <div class="wpb-field-card-header">
                    <span class="wpb-drag-handle">⠿</span>
                    <span class="wpb-field-card-label">${esc(f.label)}${f.required ? ' <span style="color:#d63638;font-size:10px;">*</span>' : ''}</span>
                    <span class="wpb-field-card-type">${esc(f.type)}</span>
                    <button class="wpb-btn-remove-field" data-id="${esc(f.id)}" title="Rimuovi">×</button>
                </div>
                <div class="wpb-field-card-preview">${preview}</div>`;

            card.addEventListener('click', e => { if (!e.target.classList.contains('wpb-btn-remove-field')) selectField(f.id); });
            card.querySelector('.wpb-btn-remove-field').addEventListener('click', e => { e.stopPropagation(); removeField(f.id); });

            card.addEventListener('dragstart', e => { dragSrcIdx = idx; card.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
            card.addEventListener('dragend',   () => { card.classList.remove('dragging'); dropZone.querySelectorAll('.wpb-field-card').forEach(c => { c.style.borderTop = c.style.borderBottom = ''; }); });
            card.addEventListener('dragover',  e => {
                e.preventDefault();
                const mid = card.getBoundingClientRect().top + card.getBoundingClientRect().height / 2;
                dropZone.querySelectorAll('.wpb-field-card').forEach(c => { c.style.borderTop = c.style.borderBottom = ''; });
                card.style[e.clientY < mid ? 'borderTop' : 'borderBottom'] = '2px solid #2271b1';
            });
            card.addEventListener('dragleave', () => { card.style.borderTop = card.style.borderBottom = ''; });
            card.addEventListener('drop', e => {
                e.preventDefault();
                card.style.borderTop = card.style.borderBottom = '';
                const tIdx = parseInt(card.dataset.idx);
                if (dragSrcIdx === null || dragSrcIdx === tIdx) return;
                const mid    = card.getBoundingClientRect().top + card.getBoundingClientRect().height / 2;
                const moved  = fields.splice(dragSrcIdx, 1)[0];
                let at       = tIdx > dragSrcIdx ? tIdx - 1 : tIdx;
                if (e.clientY >= mid) at++;
                fields.splice(at, 0, moved);
                dragSrcIdx = null;
                renderCanvas();
            });
            return card;
        }

        dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
        dropZone.addEventListener('dragleave', e => { if (!dropZone.contains(e.relatedTarget)) dropZone.classList.remove('drag-over'); });
        dropZone.addEventListener('drop',      () => dropZone.classList.remove('drag-over'));

        function selectField(id) { selectedFieldId = id; renderCanvas(); renderProps(); }

        /* ── Modale conferma ── */
        let _pendingRemoveId = null;
        const modal        = document.getElementById('wpb-confirm-modal');
        const modalName    = document.getElementById('wpb-modal-field-name');
        const modalConfirm = document.getElementById('wpb-modal-confirm');
        const modalCancel  = document.getElementById('wpb-modal-cancel');

        function askRemoveField(id) {
            const f = fields.find(f => f.id === id);
            if (!f) return;
            _pendingRemoveId = id;
            if (modalName) modalName.textContent = 'Stai per eliminare il campo "' + f.label + '". L\'operazione non può essere annullata.';
            modal.classList.add('open');
            setTimeout(() => modalConfirm && modalConfirm.focus(), 50);
        }
        function closeModal() { modal.classList.remove('open'); _pendingRemoveId = null; }

        if (modalConfirm) modalConfirm.addEventListener('click', () => {
            if (_pendingRemoveId) _doRemoveField(_pendingRemoveId);
            closeModal();
        });
        if (modalCancel) modalCancel.addEventListener('click', closeModal);
        modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
        });

        function _doRemoveField(id) {
            fields = fields.filter(f => f.id !== id);
            if (selectedFieldId === id) { selectedFieldId = null; }
            renderCanvas(); renderProps();
        }
        // alias pubblico — usato dai pulsanti × nelle card
        function removeField(id) { askRemoveField(id); }

        /* ── Backspace sul campo selezionato ── */
        document.addEventListener('keydown', e => {
            if (e.key !== 'Backspace' && e.key !== 'Delete') return;
            // Ignora se il focus è su un input/textarea/select
            const tag = document.activeElement?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            if (!selectedFieldId) return;
            askRemoveField(selectedFieldId);
        });
        function updateField(id, key, value) {
            const f = fields.find(f => f.id === id);
            if (!f) return;
            f[key] = value;
            const old = dropZone.querySelector('[data-id="' + id + '"]');
            if (old) { const n = buildCard(f, fields.indexOf(f)); old.replaceWith(n); n.classList.add('selected'); }
        }

        /* ── Proprietà ── */
        function renderProps() {
            if (!selectedFieldId) {
                propsInner.innerHTML = `<div class="wpb-no-field-selected"><span class="dashicons dashicons-edit-large"></span><p>Seleziona un campo<br>per modificarne le proprietà</p></div>`;
                return;
            }
            const f = fields.find(f => f.id === selectedFieldId);
            if (!f) return;
            const hasOpts     = ['select','radio','checkbox'].includes(f.type);
            const isNumber    = f.type === 'number';
            const isDate      = f.type === 'date';
            const showPlaceholder = !hasOpts && !isDate;

            propsInner.innerHTML = `
                <div class="wpb-prop-group">
                    <label>Etichetta</label>
                    <input type="text" id="prop-label" value="${esc(f.label)}">
                </div>
                ${showPlaceholder ? `<div class="wpb-prop-group"><label>Testo segnaposto</label><input type="text" id="prop-placeholder" value="${esc(f.placeholder||'')}"></div>` : ''}
                ${isNumber ? `
                <div class="wpb-prop-group">
                    <label>Valore minimo</label>
                    <input type="number" id="prop-min" value="${esc(f.min??'')}">
                </div>
                <div class="wpb-prop-group">
                    <label>Valore massimo</label>
                    <input type="number" id="prop-max" value="${esc(f.max??'')}">
                </div>` : ''}
                ${hasOpts ? `<div class="wpb-prop-group">
                    <label>Opzioni</label>
                    <div class="wpb-options-list" id="wpb-opts-list">
                    ${(f.options||[]).map((o,i) => `<div class="wpb-option-row"><input type="text" class="wpb-opt-input" data-idx="${i}" value="${esc(o)}" placeholder="Opzione ${i+1}"><button class="wpb-btn-rm-opt" data-idx="${i}">×</button></div>`).join('')}
                    </div>
                    <button class="wpb-btn-add-opt" id="wpb-btn-add-opt">+ Aggiungi opzione</button>
                </div>` : ''}
                <div class="wpb-prop-group">
                    <label class="wpb-prop-check"><input type="checkbox" id="prop-required" ${f.required?'checked':''}> Campo obbligatorio</label>
                </div>
                <div class="wpb-prop-group">
                    <label>Messaggio di errore</label>
                    <input type="text" id="prop-error-message" value="${esc(f.error_message||'')}" placeholder="Questo campo è obbligatorio">
                    <p style="font-size:11px;color:#a7aaad;margin:4px 0 0;">Mostrato se il campo non è compilato correttamente.</p>
                </div>
                <div style="margin-top:20px;padding-top:14px;border-top:1px solid #f0f0f1;font-size:11px;color:#a7aaad;">
                    Tipo: <strong>${esc(f.type)}</strong> &nbsp;·&nbsp; ID: <code style="font-size:10px;">${esc(f.id)}</code>
                </div>`;

            const bi = (elId, key) => { const el = document.getElementById(elId); if (el) el.addEventListener('input', () => updateField(f.id, key, el.value)); };
            bi('prop-label', 'label');
            bi('prop-placeholder', 'placeholder');
            bi('prop-min', 'min');
            bi('prop-max', 'max');
            bi('prop-error-message', 'error_message');
            const req = document.getElementById('prop-required');
            if (req) req.addEventListener('change', () => updateField(f.id, 'required', req.checked));

            if (hasOpts) {
                document.querySelectorAll('.wpb-opt-input').forEach(inp => {
                    inp.addEventListener('input', () => {
                        const opts = [...(f.options||[])]; opts[parseInt(inp.dataset.idx)] = inp.value;
                        updateField(f.id, 'options', opts);
                    });
                });
                document.querySelectorAll('.wpb-btn-rm-opt').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const opts = [...(f.options||[])]; opts.splice(parseInt(btn.dataset.idx), 1);
                        updateField(f.id, 'options', opts); renderProps();
                    });
                });
                const addBtn = document.getElementById('wpb-btn-add-opt');
                if (addBtn) addBtn.addEventListener('click', () => { updateField(f.id, 'options', [...(f.options||[]), 'Nuova opzione']); renderProps(); });
            }
        }

        /* ── Bottoni tipo campo ── */
        document.querySelectorAll('.wpb-field-type-btn').forEach(btn => {
            btn.addEventListener('click', () => addField(btn.dataset.type));
        });

        /* ── Salva form ── */
        const saveBtn = document.getElementById('wpb-btn-save-form');
        if (saveBtn) {
            saveBtn.addEventListener('click', async () => {
                const title = document.getElementById('wpb-form-title').value.trim();
                if (!title) { toast('Inserisci un nome per il form.', 'error'); return; }

                saveBtn.disabled    = true;
                saveBtn.textContent = 'Salvataggio…';

                const body = new URLSearchParams({
                    action:   'wpb_forms_save',
                    nonce:    NONCE,
                    form_id:  String(EDIT_ID),
                    title,
                    fields:   JSON.stringify(fields),
                    settings: JSON.stringify(readSettings()),
                });

                try {
                    const res  = await fetch(AJAX, { method:'POST', body });
                    const data = await res.json();
                    if (data.success) {
                        toast('✓ ' + (data.data.message || 'Salvato!'));
                        if (!EDIT_ID && data.data.id) {
                            setTimeout(() => {
                                location.href = '<?php echo esc_js( admin_url( "admin.php?page=wp-barrio-forms&tab=builder&edit_id=" ) ); ?>' + data.data.id;
                            }, 800);
                        }
                    } else {
                        toast(data.data || 'Errore durante il salvataggio.', 'error');
                    }
                } catch(e) {
                    toast('Errore di rete.', 'error');
                } finally {
                    saveBtn.disabled    = false;
                    saveBtn.textContent = 'Salva form';
                }
            });
        }

        renderCanvas();
        renderProps();
    })();
    </script>
    <?php
}
