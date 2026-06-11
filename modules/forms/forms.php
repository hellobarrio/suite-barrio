<?php
/**
 * WP Barrio — Forms
 * Builder drag & drop per form di contatto personalizzati.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

if (!defined('WPB_FORMS_VERSION'))
    define('WPB_FORMS_VERSION', '1.1.0');
if (!defined('WPB_FORMS_TABLE'))
    define('WPB_FORMS_TABLE', 'wpb_forms');
if (!defined('WPB_FORMS_SUB_TABLE'))
    define('WPB_FORMS_SUB_TABLE', 'wpb_form_submissions');
if (!defined('WPB_FORMS_TRANS_TABLE'))
    define('WPB_FORMS_TRANS_TABLE', 'wpb_forms_translations');

/* ─────────────────────────────────────────
   INSTALL: crea le tabelle al primo avvio
   ───────────────────────────────────────── */
register_activation_hook(WP_ALT_AI_PLUGIN_DIR . 'wp-alt-ai.php', 'wpb_forms_install');

function wpb_forms_install()
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}wpb_forms` (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title      VARCHAR(255)    NOT NULL DEFAULT '',
            fields     LONGTEXT        NOT NULL,
            settings   LONGTEXT        NOT NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset};"
    );

    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}wpb_form_submissions` (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id    BIGINT UNSIGNED NOT NULL,
            data       LONGTEXT        NOT NULL,
            lang       VARCHAR(10)     NOT NULL DEFAULT '',
            ip         VARCHAR(45)     NOT NULL DEFAULT '',
            user_agent TEXT,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id)
        ) {$charset};"
    );

    // Tabella traduzioni form (v1.1.0)
    $wpdb->query(
        "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}wpb_forms_translations` (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id       BIGINT UNSIGNED NOT NULL,
            lang          VARCHAR(10)     NOT NULL DEFAULT '',
            fields_i18n   LONGTEXT        NOT NULL,
            settings_i18n LONGTEXT        NOT NULL,
            updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY form_lang (form_id, lang)
        ) {$charset};"
    );
}

add_action('plugins_loaded', function () {
    wpb_forms_install();
    $installed = get_option('wpb_forms_db_version', '0');
    if (version_compare($installed, '1.1.0', '<')) {
        wpb_forms_install();
        update_option('wpb_forms_db_version', WPB_FORMS_VERSION, false);
    }
    // Migrazione v1.1: aggiunge colonna lang a wpb_form_submissions se manca
    global $wpdb;
    $sub_table = $wpdb->prefix . WPB_FORMS_SUB_TABLE;
    $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$sub_table}`", 0);
    if ($cols && !in_array('lang', $cols, true)) {
        $wpdb->query("ALTER TABLE `{$sub_table}` ADD COLUMN `lang` VARCHAR(10) NOT NULL DEFAULT '' AFTER `data`");
    }
});

// Fallback: crea le tabelle anche alla prima visita admin
add_action('admin_init', function () {
    if (!get_option('wpb_forms_db_version')) {
        wpb_forms_install();
        update_option('wpb_forms_db_version', WPB_FORMS_VERSION, false);
    }
});

/* ─────────────────────────────────────────
   HELPER DB
   ───────────────────────────────────────── */
function wpb_forms_get_all()
{
    global $wpdb;
    return $wpdb->get_results(
        "SELECT id, title, created_at,
                (SELECT COUNT(*) FROM {$wpdb->prefix}" . WPB_FORMS_SUB_TABLE . " s WHERE s.form_id = f.id) AS submissions
         FROM {$wpdb->prefix}" . WPB_FORMS_TABLE . " f
         ORDER BY id DESC"
    );
}

function wpb_forms_get($id)
{
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}" . WPB_FORMS_TABLE . " WHERE id = %d",
        intval($id)
    ));
}

function wpb_forms_get_submissions($form_id, $limit = 50, $offset = 0)
{
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}" . WPB_FORMS_SUB_TABLE . "
         WHERE form_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
        intval($form_id),
        intval($limit),
        intval($offset)
    ));
}

function wpb_forms_count_submissions($form_id)
{
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}" . WPB_FORMS_SUB_TABLE . " WHERE form_id = %d",
        intval($form_id)
    ));
}

function wpb_forms_get_submission($id)
{
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}" . WPB_FORMS_SUB_TABLE . " WHERE id = %d",
        intval($id)
    ));
}


/* ─────────────────────────────────────────
   HELPER DB — TRADUZIONI FORM
   ───────────────────────────────────────── */

/**
 * Restituisce la traduzione di un form per una lingua.
 */
function wpb_forms_get_translation(int $form_id, string $lang): ?object
{
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}wpb_forms_translations
         WHERE form_id = %d AND lang = %s",
        $form_id,
        $lang
    )) ?: null;
}

/**
 * Upsert traduzione form.
 */
function wpb_forms_save_translation(int $form_id, string $lang, array $fields_i18n, array $settings_i18n): bool
{
    global $wpdb;
    $table = $wpdb->prefix . WPB_FORMS_TRANS_TABLE;
    $existing = wpb_forms_get_translation($form_id, $lang);

    $row = [
        'form_id' => $form_id,
        'lang' => $lang,
        'fields_i18n' => wp_json_encode($fields_i18n, JSON_UNESCAPED_UNICODE),
        'settings_i18n' => wp_json_encode($settings_i18n, JSON_UNESCAPED_UNICODE),
    ];
    $fmt = ['%d', '%s', '%s', '%s'];

    if ($existing) {
        $ok = $wpdb->update($table, $row, ['id' => (int) $existing->id], $fmt, ['%d']);
    } else {
        $ok = $wpdb->insert($table, $row, $fmt);
    }
    return $ok !== false;
}

/**
 * Elimina tutte le traduzioni di un form.
 */
function wpb_forms_delete_translations(int $form_id): void
{
    global $wpdb;
    $wpdb->delete($wpdb->prefix . WPB_FORMS_TRANS_TABLE, ['form_id' => $form_id], ['%d']);
}

/**
 * Applica una traduzione a un form: sovrascrive solo i campi traducibili.
 * Restituisce fields e settings mergiati (non modifica il DB).
 */
function wpb_forms_apply_translation(array $fields, array $settings, object $trans): array
{
    $fi18n = json_decode($trans->fields_i18n, true) ?: [];
    $si18n = json_decode($trans->settings_i18n, true) ?: [];

    // Merge fields: per ogni campo, sovrascrive label, placeholder, options, error_message
    foreach ($fields as $i => &$field) {
        if (!isset($fi18n[$i]))
            continue;
        $t = $fi18n[$i];
        foreach (['label', 'placeholder', 'error_message'] as $k) {
            if (isset($t[$k]) && $t[$k] !== '')
                $field[$k] = $t[$k];
        }
        if (!empty($t['options']) && is_array($t['options'])) {
            $field['options'] = $t['options'];
        }
    }
    unset($field);

    // Merge settings: sovrascrive solo i campi traducibili
    $translatable_settings = ['submit_label', 'redirect_url', 'success_message', 'mailup_list_id', 'mailup_group_id'];
    foreach ($translatable_settings as $k) {
        if (isset($si18n[$k]) && $si18n[$k] !== '')
            $settings[$k] = $si18n[$k];
    }
    // Notifiche: merge array by index
    if (!empty($si18n['notifications']) && is_array($si18n['notifications'])) {
        $orig_notifs = $settings['notifications'] ?? [];
        foreach ($si18n['notifications'] as $ni => $nt) {
            if (!isset($orig_notifs[$ni]))
                continue;
            $translatable_notif = ['subject', 'body', 'reply_to', 'from_name', 'to'];
            foreach ($translatable_notif as $nk) {
                if (isset($nt[$nk]) && $nt[$nk] !== '')
                    $orig_notifs[$ni][$nk] = $nt[$nk];
            }
        }
        $settings['notifications'] = $orig_notifs;
    }

    return [$fields, $settings];
}

/**
 * Estrae da fields e settings solo i campi traducibili,
 * per pre-popolare l'editor di traduzione.
 */
function wpb_forms_extract_translatable(array $fields, array $settings): array
{
    $fi18n = [];
    foreach ($fields as $field) {
        $entry = [];
        foreach (['label', 'placeholder', 'error_message'] as $k) {
            $entry[$k] = $field[$k] ?? '';
        }
        $entry['options'] = $field['options'] ?? [];
        $fi18n[] = $entry;
    }

    $si18n = [];
    foreach (['submit_label', 'redirect_url', 'success_message', 'mailup_list_id', 'mailup_group_id'] as $k) {
        $si18n[$k] = $settings[$k] ?? '';
    }
    $si18n['notifications'] = [];
    foreach ($settings['notifications'] ?? [] as $notif) {
        $nt = [];
        foreach (['subject', 'body', 'reply_to', 'from_name', 'to'] as $nk) {
            $nt[$nk] = $notif[$nk] ?? '';
        }
        $si18n['notifications'][] = $nt;
    }

    return [$fi18n, $si18n];
}

/* ─────────────────────────────────────────
   SHORTCODE: [wpb_form id="X"]
   ───────────────────────────────────────── */
add_shortcode('wpb_form', 'wpb_forms_shortcode');

function wpb_forms_shortcode($atts)
{
    $atts = shortcode_atts(['id' => 0], $atts);
    $form = wpb_forms_get(intval($atts['id']));
    if (!$form)
        return '<p>Form non trovato.</p>';

    $fields = json_decode($form->fields, true) ?: [];
    $settings = json_decode($form->settings, true) ?: [];
    $form_id_css = 'wpb-form-' . $form->id;
    $after_submit = $settings['after_submit'] ?? 'message';
    $redirect_url = esc_url($settings['redirect_url'] ?? '');
    $display_page_info = !empty($settings['display_page_info']);

    ob_start();
    ?>
    <div class="wpb-form-wrap" id="<?php echo esc_attr($form_id_css); ?>">
        <form class="wpb-contact-form" data-form-id="<?php echo intval($form->id); ?>" novalidate>
            <?php wp_nonce_field('wpb_form_submit_' . $form->id, 'wpb_nonce'); ?>
            <input type="hidden" name="action" value="wpb_forms_submit">
            <input type="hidden" name="form_id" value="<?php echo intval($form->id); ?>">
            <input type="hidden" name="_lang" value="<?php
            $wpb_form_lang = '';
            if (defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE) {
                $wpb_form_lang = ICL_LANGUAGE_CODE;
            } elseif (function_exists('apply_filters')) {
                $wpb_form_lang = apply_filters('wpml_current_language', '');
            }
            echo esc_attr($wpb_form_lang);
            ?>">
            <?php if ($display_page_info): ?>
                <input type="hidden" name="_page_url" id="<?php echo esc_attr($form_id_css); ?>-page-url">
                <input type="hidden" name="_page_title" id="<?php echo esc_attr($form_id_css); ?>-page-title">
            <?php endif; ?>

            <?php foreach ($fields as $field):
                $type = $field['type'] ?? 'text';
                if ($type === 'page_break')
                    continue; // separatore multistep, non un campo HTML
                $fid = esc_attr($field['name'] ?? '');
                $label = esc_html($field['label'] ?? '');
                $required = !empty($field['required']);
                $placeholder = esc_attr($field['placeholder'] ?? '');
                $req_attr = $required ? 'required' : '';
                $req_star = $required ? ' <span class="wpb-req">*</span>' : '';
                ?>
                <div class="wpb-field-wrap wpb-field-type-<?php echo esc_attr($type); ?>" data-field-name="<?php echo $fid; ?>">
                    <label for="wpb-<?php echo $fid; ?>"><?php echo $label . $req_star; ?></label>

                    <?php if ($type === 'textarea'): ?>
                        <textarea id="wpb-<?php echo $fid; ?>" name="<?php echo $fid; ?>" placeholder="<?php echo $placeholder; ?>"
                            rows="5" <?php echo $req_attr; ?>></textarea>

                    <?php elseif ($type === 'select'):
                        $options = $field['options'] ?? [];
                        ?>
                        <select id="wpb-<?php echo $fid; ?>" name="<?php echo $fid; ?>" <?php echo $req_attr; ?>>
                            <option value="" disabled selected hidden><?php echo esc_html($field['placeholder'] ?? 'Seleziona…'); ?>
                            </option>
                            <?php foreach ($options as $opt): ?>
                                <option value="<?php echo esc_attr($opt); ?>"><?php echo esc_html($opt); ?></option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif ($type === 'radio' || $type === 'checkbox'):
                        $options = $field['options'] ?? [];
                        ?>
                        <div class="wpb-choice-group">
                            <?php foreach ($options as $opt): ?>
                                <label class="wpb-choice-label">
                                    <input type="<?php echo esc_attr($type); ?>"
                                        name="<?php echo $fid . ($type === 'checkbox' ? '[]' : ''); ?>"
                                        value="<?php echo esc_attr($opt); ?>">
                                    <?php echo esc_html($opt); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($type === 'datepicker'): ?>
                        <input type="date" id="wpb-<?php echo $fid; ?>" name="<?php echo $fid; ?>"
                            data-format="<?php echo esc_attr($field['format'] ?? 'dd/MM/y'); ?>" <?php echo $req_attr; ?>>

                    <?php elseif ($type === 'file'): ?>
                        <input type="file" id="wpb-<?php echo $fid; ?>" name="<?php echo $fid; ?>" <?php echo $req_attr; ?>>

                    <?php else: ?>
                        <?php
                        $extra_attrs = '';
                        if ($type === 'number') {
                            if (isset($field['min']) && $field['min'] !== '')
                                $extra_attrs .= ' min="' . esc_attr($field['min']) . '"';
                            if (isset($field['max']) && $field['max'] !== '')
                                $extra_attrs .= ' max="' . esc_attr($field['max']) . '"';
                        }
                        ?>
                        <input type="<?php echo esc_attr($type); ?>" id="wpb-<?php echo $fid; ?>" name="<?php echo $fid; ?>"
                            placeholder="<?php echo $placeholder; ?>" <?php echo $extra_attrs; ?>             <?php echo $req_attr; ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <div class="wpb-field-wrap wpb-field-submit">
                <button type="submit" class="wpb-submit-btn">
                    <?php echo esc_html($settings['submit_text'] ?? 'Invia'); ?>
                </button>
            </div>
            <div class="wpb-form-msg" style="display:none;"></div>
        </form>
    </div>

    <style>
        .wpb-form-wrap {
            max-width: 600px;
        }

        .wpb-form-wrap .wpb-field-wrap {
            margin-bottom: 18px;
        }

        .wpb-form-wrap label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .wpb-req {
            color: #dc2626;
        }

        .wpb-form-wrap input[type=text],
        .wpb-form-wrap input[type=email],
        .wpb-form-wrap input[type=tel],
        .wpb-form-wrap input[type=number],
        .wpb-form-wrap select,
        .wpb-form-wrap textarea {
            width: 100%;
            padding: 10px 14px;
            font-size: 14px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
            box-sizing: border-box;
            transition: border-color .15s;
        }

        .wpb-form-wrap input:focus,
        .wpb-form-wrap select:focus,
        .wpb-form-wrap textarea:focus {
            outline: none;
            border-color: #111827;
            box-shadow: 0 0 0 2px rgba(34, 113, 177, .15);
        }

        .wpb-choice-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .wpb-choice-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 400;
            cursor: pointer;
        }

        .wpb-submit-btn {
            padding: 11px 28px;
            font-size: 15px;
            font-weight: 600;
            background: #111827;
            color: #fff;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: background .15s;
        }

        .wpb-submit-btn:hover {
            background: #135e96;
        }

        .wpb-submit-btn:disabled {
            background: #a7aaad;
            cursor: not-allowed;
        }

        .wpb-form-msg {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 14px;
            margin-top: 12px;
        }

        .wpb-form-msg.success {
            background: #dcfce7;
            border: 1px solid #059669;
            color: #059669;
        }

        .wpb-form-msg.error {
            background: #fee2e2;
            border: 1px solid #d63638;
            color: #dc2626;
        }
    </style>

    <script>
        (function () {
            var wrap = document.getElementById('<?php echo esc_js($form_id_css); ?>');
            if (!wrap) return;
            var form = wrap.querySelector('.wpb-contact-form');
            var msgEl = wrap.querySelector('.wpb-form-msg');
            var btn = wrap.querySelector('.wpb-submit-btn');
            var afterSubmit = '<?php echo esc_js($after_submit); ?>';
            var redirectUrl = '<?php echo esc_js($redirect_url); ?>';
            var successMsg = '<?php echo esc_js($settings['success_message'] ?? 'Grazie! Il tuo messaggio è stato inviato.'); ?>';
            var submitLabel = btn.textContent;

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                btn.disabled = true;
                btn.textContent = 'Invio in corso…';
                msgEl.className = 'wpb-form-msg';
                msgEl.style.display = 'none';

                <?php if ($display_page_info): ?>
                    document.getElementById('<?php echo esc_js($form_id_css); ?>-page-url').value = window.location.href;
                    document.getElementById('<?php echo esc_js($form_id_css); ?>-page-title').value = document.title;
                <?php endif; ?>

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: new FormData(form)
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.success) {
                            if (afterSubmit === 'redirect' && redirectUrl) {
                                window.location.href = redirectUrl;
                            } else {
                                msgEl.textContent = res.data.message || successMsg;
                                msgEl.className = 'wpb-form-msg success';
                                msgEl.style.display = 'block';
                                form.reset();
                            }
                        } else {
                            msgEl.textContent = res.data || 'Si è verificato un errore. Riprova.';
                            msgEl.className = 'wpb-form-msg error';
                            msgEl.style.display = 'block';
                        }
                    })
                    .catch(function () {
                        msgEl.textContent = 'Errore di rete. Riprova.';
                        msgEl.className = 'wpb-form-msg error';
                        msgEl.style.display = 'block';
                    })
                    .finally(function () {
                        btn.disabled = false;
                        btn.textContent = submitLabel;
                    });
            });
        })();
    </script>

    <?php
    /*
     * Logica condizionale front-end — modello Gravity Forms
     * Ogni campo porta la propria logica: "mostra/nascondi questo campo se…"
     *
     * Struttura dati per campo (nuovo formato):
     *   conditionalLogic: {
     *     enabled    : bool,
     *     action     : 'show' | 'hide',    // cosa fare quando le regole sono soddisfatte
     *     logicType  : 'all' | 'any',      // AND / OR tra le singole regole
     *     rules      : [{ fieldName, operator, value }]
     *   }
     *
     * Retrocompatibilità: il vecchio formato showFields viene ignorato qui;
     * la migrazione avviene lato builder alla prima apertura del form.
     */
    $field_cond_map = []; // fieldName → conditionalLogic
    $watch_fields = []; // elenco dei fieldName che altri campi osservano

    foreach ($fields as $field) {
        $fname = $field['name'] ?? '';
        if (!$fname || ($field['type'] ?? '') === 'page_break')
            continue;

        $cl = $field['conditionalLogic'] ?? null;
        if (!$cl || empty($cl['enabled']))
            continue;

        $rules = $cl['rules'] ?? [];
        if (empty($rules))
            continue;

        $field_cond_map[$fname] = [
            'action' => ($cl['action'] ?? 'show') === 'hide' ? 'hide' : 'show',
            'logicType' => ($cl['logicType'] ?? 'all') === 'any' ? 'any' : 'all',
            'rules' => array_values(array_filter(
                array_map(function ($r) {
                    if (empty($r['fieldName']))
                        return null;
                    return [
                        'fieldName' => $r['fieldName'],
                        'operator' => $r['operator'] ?? '=',
                        'value' => $r['value'] ?? '',
                    ];
                }, $rules)
            )),
        ];

        // Raccogli i nomi dei campi osservati per attaccare i listener
        foreach ($rules as $r) {
            if (!empty($r['fieldName'])) {
                $watch_fields[$r['fieldName']] = true;
            }
        }
    }

    if (!empty($field_cond_map)):
        ?>
        <script>
            (function () {
                var wrap = document.getElementById('<?php echo esc_js($form_id_css); ?>');
                if (!wrap) return;

                /**
                 * FIELD_COND: mappa fieldName → { action, logicType, rules[] }
                 * Ogni campo descrive da sé quando deve essere mostrato/nascosto.
                 */
                var FIELD_COND = <?php echo wp_json_encode($field_cond_map); ?>;

                /* ── Lettura valore di un campo ── */
                function getFieldValue(name) {
                    var inputs = wrap.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]');
                    if (!inputs.length) return '';
                    var type = inputs[0].type;
                    if (type === 'checkbox') {
                        return Array.from(inputs).filter(function (i) { return i.checked; }).map(function (i) { return String(i.value); });
                    }
                    if (type === 'radio') {
                        var chk = Array.from(inputs).find(function (i) { return i.checked; });
                        return chk ? String(chk.value) : '';
                    }
                    return String(inputs[0].value || '');
                }

                /* ── Test di una singola regola ── */
                function testRule(rule) {
                    var val = getFieldValue(rule.fieldName);
                    var v = Array.isArray(val) ? val : [val];
                    var rv = String(rule.value || '');
                    switch (rule.operator) {
                        case '=': return v.some(function (x) { return x === rv; });
                        case '!=': return v.every(function (x) { return x !== rv; });
                        case '>': return v.some(function (x) { return parseFloat(x) > parseFloat(rv); });
                        case '<': return v.some(function (x) { return parseFloat(x) < parseFloat(rv); });
                        case 'contains': return v.some(function (x) { return x.indexOf(rv) !== -1; });
                        case 'starts_with': return v.some(function (x) { return x.indexOf(rv) === 0; });
                        case 'ends_with': return v.some(function (x) { return x.slice(-rv.length) === rv; });
                        case 'empty': return v.every(function (x) { return x === ''; });
                        case 'not_empty': return v.some(function (x) { return x !== ''; });
                        default: return false;
                    }
                }

                /* ── Valuta e applica la logica di UN campo ── */
                function evaluateField(fieldName) {
                    var cond = FIELD_COND[fieldName];
                    if (!cond) return;

                    var el = wrap.querySelector('[data-field-name="' + fieldName + '"]');
                    if (!el) return;

                    var rules = cond.rules || [];
                    var logicType = cond.logicType; // 'all' | 'any'
                    var action = cond.action;    // 'show' | 'hide'

                    var conditionsMet = rules.length > 0 && (
                        logicType === 'all'
                            ? rules.every(testRule)
                            : rules.some(testRule)
                    );

                    /*
                     * Se action==='show': mostra il campo SOLO quando le condizioni sono soddisfatte.
                     * Se action==='hide': nascondi il campo SOLO quando le condizioni sono soddisfatte.
                     */
                    var visible = action === 'show' ? conditionsMet : !conditionsMet;

                    el.style.display = visible ? '' : 'none';
                    el.dataset.condHidden = visible ? '0' : '1';
                    el.querySelectorAll('input,select,textarea').forEach(function (inp) {
                        inp.disabled = !visible;
                    });
                }

                /* ── Stato iniziale di TUTTI i campi con logica ── */
                Object.keys(FIELD_COND).forEach(evaluateField);

                /* ── Mappa inversa: quale campo osserva quali altri ── */
                // Quando un campo-sorgente cambia, rivalutiamo tutti i campi che lo osservano.
                var watchMap = {}; // sourceName → [targetFieldNames]
                Object.keys(FIELD_COND).forEach(function (targetName) {
                    (FIELD_COND[targetName].rules || []).forEach(function (rule) {
                        var src = rule.fieldName;
                        if (!watchMap[src]) watchMap[src] = [];
                        if (watchMap[src].indexOf(targetName) === -1) {
                            watchMap[src].push(targetName);
                        }
                    });
                });

                /* ── Listener sui campi sorgente ── */
                Object.keys(watchMap).forEach(function (srcName) {
                    var inputs = wrap.querySelectorAll('[name="' + srcName + '"], [name="' + srcName + '[]"]');
                    inputs.forEach(function (inp) {
                        function onChange() {
                            watchMap[srcName].forEach(evaluateField);
                        }
                        inp.addEventListener('change', onChange);
                        inp.addEventListener('input', onChange);
                    });
                });
            })();
        </script>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/* ─────────────────────────────────────────
   HELPER: sostituisce {{name}} con i valori
   della submission nei campi della notifica
   ───────────────────────────────────────── */
function wpb_interpolate_field_vars(string $text, array $submission_data): string
{
    // Sostituisce {{name}} con il valore del campo corrispondente
    return preg_replace_callback(
        '/\{\{([^}]+)\}\}/',
        function ($matches) use ($submission_data) {
            $key = trim($matches[1]);
            if (isset($submission_data[$key])) {
                $val = $submission_data[$key]['value'] ?? '';
                return is_array($val) ? implode(', ', $val) : (string) $val;
            }
            return $matches[0]; // lascia invariato se non trovato
        },
        $text
    );
}

/* ─────────────────────────────────────────
   HELPER: compila il template HTML email
   ───────────────────────────────────────── */
function wpb_build_email_html(
    string $content,
    string $title,
    string $bg_color = '#f4f4f4',
    string $header_color = '#2271b1',
    string $logo_url = '',
    string $domain_email = ''
): string {
    // Logo — se assente, nasconde il blocco con display:none
    $logo_block = $logo_url
        ? '<img class="t21" style="display:block;border:0;height:auto;width:100%;Margin:0;max-width:100%;" width="200" height="60" src="' . esc_url($logo_url) . '" alt="Logo" />'
        : '<span style="display:block;height:4px;"></span>';

    $domain_safe = esc_html($domain_email);
    $domain_href = esc_url('mailto:' . $domain_email);

    $tpl = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="it">
<head>
<meta name="x-apple-disable-message-reformatting" />
<meta http-equiv="X-UA-Compatible" />
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width" />
<style type="text/css">
table{border-collapse:separate;table-layout:fixed;mso-table-lspace:0pt;mso-table-rspace:0pt}
table td{border-collapse:collapse}
*{line-height:inherit;text-size-adjust:100%;-ms-text-size-adjust:100%;-moz-text-size-adjust:100%;-webkit-text-size-adjust:100%;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
a{text-decoration:none}
h1,h2,h3,p,a{line-height:1;overflow-wrap:normal;white-space:normal;word-break:break-word}
img{margin:0;padding:0;-ms-interpolation-mode:bicubic}
</style>
</head>
<body style="min-width:100%;margin:0;padding:0;background-color:[bgColor];">
<div style="background-color:[bgColor]">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center">
    <tr><td style="font-size:0;line-height:0" valign="top" align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" align="center">

        <!-- SPACER -->
        <tr><td><div style="font-size:1px;line-height:60px;">&nbsp;</div></td></tr>

        <!-- HEADER -->
        <tr><td>
          <table role="presentation" cellpadding="0" cellspacing="0" align="center">
            <tr>
              <td style="background-color:[headerColor];overflow:hidden;width:600px;padding:40px 0;border-radius:14px 14px 0 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                  <tr><td>
                    <table role="presentation" cellpadding="0" cellspacing="0" align="center">
                      <tr><td style="width:200px;">[logoBlock]</td></tr>
                    </table>
                  </td></tr>
                </table>
              </td>
            </tr>
          </table>
        </td></tr>

        <!-- BODY -->
        <tr><td>
          <table role="presentation" cellpadding="0" cellspacing="0" align="center">
            <tr>
              <td style="background-color:#ffffff;overflow:hidden;width:540px;padding:40px 30px;border-radius:0 0 14px 14px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                  <tr><td>
                    <p style="font-family:BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:1.4;font-weight:400;font-size:24px;color:#111111;margin:0 0 20px;">[titoloEmail]</p>
                  </td></tr>
                  <tr><td>
                    <div style="font-family:BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;font-size:14px;line-height:1.6;color:#111111;">[content]</div>
                  </td></tr>
                </table>
              </td>
            </tr>
          </table>
        </td></tr>

        <!-- FOOTER -->
        <tr><td>
          <table role="presentation" cellpadding="0" cellspacing="0" align="center">
            <tr>
              <td style="width:540px;padding:20px 30px;">
                <p style="font-family:BlinkMacSystemFont,Segoe UI,Helvetica Neue,Arial,sans-serif;line-height:1.6;font-size:12px;color:#878787;margin:0;">
                  La presente email è stata generata automaticamente da un indirizzo di posta elettronica di solo invio; si chiede pertanto di non rispondere al messaggio.
                  Le informazioni e gli eventuali allegati contenuti nella presente comunicazione sono diretti esclusivamente al destinatario e devono ritenersi riservati.
                  Se avete ricevuto questo messaggio per errore, vi preghiamo di cancellarlo e di informarci inviando un messaggio a:
                  <a href="[domainHref]" style="color:#878787;font-weight:700;">[domainEmail]</a>
                </p>
              </td>
            </tr>
          </table>
        </td></tr>

        <!-- SPACER -->
        <tr><td><div style="font-size:1px;line-height:60px;">&nbsp;</div></td></tr>

      </table>
    </td></tr>
  </table>
</div>
</body>
</html>';

    return str_replace(
        ['[bgColor]', '[headerColor]', '[logoBlock]', '[titoloEmail]', '[content]', '[domainEmail]', '[domainHref]'],
        [$bg_color, $header_color, $logo_block, esc_html($title), $content, $domain_safe, $domain_href],
        $tpl
    );
}

/* ─────────────────────────────────────────
   HELPER CONDIVISO: invia le notifiche email
   Usato sia dall'handler AJAX (shortcode front-end)
   sia dalla mutation GraphQL (Next.js headless).
   ───────────────────────────────────────── */

/**
 * Invia tutte le notifiche email configurate per un form.
 *
 * @param object $form           Riga del form da DB (con campo ->title).
 * @param array  $settings       Impostazioni decodificate del form.
 * @param array  $submission_data Dati già sanitizzati nel formato
 *                               [ name => ['label' => ..., 'value' => ...] ].
 */
function wpb_forms_send_notifications(object $form, array $settings, array $submission_data, array $attachments = []): array
{
    $log = [];

    $notifications = isset($settings['notifications']) && is_array($settings['notifications'])
        ? $settings['notifications'] : [];

    $log[] = 'form_id=' . $form->id . ' notifications_count=' . count($notifications)
        . ' settings_keys=' . implode(',', array_keys($settings));
    error_log('[WP Barrio][send_notifications] ' . end($log));

    if (empty($notifications)) {
        $log[] = 'SKIP: nessuna notifica configurata per questo form.';
        error_log('[WP Barrio][send_notifications] ' . end($log));
        return $log;
    }

    // Logga eventuali errori di wp_mail senza far crashare il flusso
    static $mail_failed_hooked = false;
    if (!$mail_failed_hooked) {
        add_action('wp_mail_failed', function ($error) {
            error_log('[WP Barrio] wp_mail_failed: ' . $error->get_error_message());
        });
        $mail_failed_hooked = true;
    }

    $default_subject = sprintf('[%s] Nuova registrazione: %s', get_bloginfo('name'), $form->title);

    foreach ($notifications as $notif) {

        // ── Destinatario ─────────────────────────────────────────────────────
        $to_email_raw = wpb_interpolate_field_vars(trim($notif['to_email'] ?? ''), $submission_data);
        $to_email = sanitize_email($to_email_raw);
        error_log('[WP Barrio] notifica to_email_raw="' . $to_email_raw . '" sanitized="' . $to_email . '"');
        if (!$to_email)
            continue;

        $to_name = wpb_interpolate_field_vars(sanitize_text_field($notif['to_name'] ?? ''), $submission_data);
        $to = $to_name ? "{$to_name} <{$to_email}>" : $to_email;

        // ── Oggetto ──────────────────────────────────────────────────────────
        $subject = !empty($notif['subject'])
            ? sanitize_text_field($notif['subject'])
            : $default_subject;
        $subject = wpb_interpolate_field_vars($subject, $submission_data);

        // ── Titolo email ─────────────────────────────────────────────────────
        $email_title = !empty($notif['email_title'])
            ? sanitize_text_field($notif['email_title'])
            : $form->title;
        $email_title = wpb_interpolate_field_vars($email_title, $submission_data);

        // ── Corpo ────────────────────────────────────────────────────────────
        $message_mode = $notif['message_mode'] ?? 'auto'; // 'auto' | 'custom'
        if ($message_mode === 'custom') {
            $raw_msg = wpb_interpolate_field_vars(sanitize_textarea_field($notif['message'] ?? ''), $submission_data);
            $content_html = $raw_msg !== '' ? nl2br(esc_html($raw_msg)) : '';
        } else {
            $rows = '';
            foreach ($submission_data as $key => $d) {
                if (substr($key, 0, 1) === '_')
                    continue; // campi interni (_page_url, _file_*, ecc.)
                $label = esc_html($d['label']);
                $raw_val = $d['value'];
                // Formato checkbox/radio: "Label: true | Label: false"
                if (is_string($raw_val) && preg_match('/:\s*(true|false)\s*(\||$)/', $raw_val)) {
                    $parts = array_map('trim', explode('|', $raw_val));
                    $val = '';
                    foreach ($parts as $part) {
                        if (preg_match('/^(.+?):\s*(true|false)$/', $part, $m)) {
                            $opt_label = esc_html(trim($m[1]));
                            $checked = $m[2] === 'true';
                            $icon = $checked ? '&#x2714;' : '&#x2718;';
                            $color = $checked ? '#00a32a' : '#a7aaad';
                            $weight = $checked ? '600' : '400';
                            $val .= "<div style=\"padding:2px 0;color:{$color};font-weight:{$weight};\">{$icon} {$opt_label}</div>";
                        }
                    }
                } else {
                    $val = is_array($raw_val) ? esc_html(implode(', ', $raw_val)) : esc_html($raw_val);
                }
                $rows .= "<tr>
                    <td style=\"padding:8px 12px;font-size:13px;font-weight:600;color:#50575e;background:#f6f7f7;border-bottom:1px solid #e8e8e8;width:35%;\">{$label}</td>
                    <td style=\"padding:8px 12px;font-size:13px;color:#111111;border-bottom:1px solid #e8e8e8;\">{$val}</td>
                </tr>";
            }
            $content_html = "<table style=\"width:100%;border-collapse:collapse;border:1px solid #e8e8e8;border-radius:4px;overflow:hidden;\">{$rows}</table>";
            // Riga lingua (se presente)
            if (!empty($submission_data['_lang']['value'])) {
                $lang_val = esc_html($submission_data['_lang']['value']);
                $content_html .= "<table style=\"width:100%;border-collapse:collapse;border:1px solid #dde;border-top:none;\">
                    <tr>
                        <td style=\"padding:7px 12px;font-size:12px;font-weight:600;color:#50575e;background:#f0f6fc;width:35%;\">&#127760; Lingua</td>
                        <td style=\"padding:7px 12px;font-size:12px;color:#111;background:#f0f6fc;\">{$lang_val}</td>
                    </tr></table>";
            }
            $content_html .= "<p style=\"margin:16px 0 0;font-size:12px;color:#a7aaad;\">Inviato il " . current_time('d/m/Y \\a\\l\\l\\e H:i') . "</p>";

            // ── Allegati: elenca i file caricati (non mostrati nella tabella) ─
            $file_entries = array_filter($submission_data, fn($d) => !empty($d['is_file']));
            if (!empty($file_entries)) {
                $att_rows = '';
                foreach ($file_entries as $d) {
                    $fname = esc_html($d['value']);
                    $furl = esc_url($d['url'] ?? '');
                    $att_rows .= "<tr>
                        <td style=\"padding:8px 12px;font-size:13px;font-weight:600;color:#50575e;background:#f6f7f7;border-bottom:1px solid #e8e8e8;width:35%;\">" . esc_html($d['label']) . "</td>
                        <td style=\"padding:8px 12px;font-size:13px;color:#2271b1;border-bottom:1px solid #e8e8e8;\">
                            📎 " . ($furl ? "<a href=\"{$furl}\" style=\"color:#2271b1;text-decoration:none;\">{$fname}</a>" : $fname) . "
                        </td>
                    </tr>";
                }
                $content_html .= "<p style=\"margin:20px 0 6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#a7aaad;\">Allegati</p>"
                    . "<table style=\"width:100%;border-collapse:collapse;border:1px solid #e8e8e8;border-radius:4px;overflow:hidden;\">{$att_rows}</table>";
            }

            // Informazioni pagina (se attivo e presenti nella submission)
            if (!empty($settings['display_page_info'])) {
                $page_url = $submission_data['_page_url']['value'] ?? '';
                $page_title = $submission_data['_page_title']['value'] ?? '';
                $page_ref = $submission_data['_page_referrer']['value'] ?? '';

                if ($page_url || $page_title || $page_ref) {
                    $page_rows = '';
                    if ($page_title) {
                        $page_rows .= "<tr>
                            <td style=\"padding:8px 12px;font-size:12px;font-weight:600;color:#50575e;background:#f6f7f7;border-bottom:1px solid #e8e8e8;width:35%;\">Pagina</td>
                            <td style=\"padding:8px 12px;font-size:12px;color:#111111;border-bottom:1px solid #e8e8e8;\">" . esc_html($page_title) . "</td>
                        </tr>";
                    }
                    if ($page_url) {
                        $url_safe = esc_url($page_url);
                        $page_rows .= "<tr>
                            <td style=\"padding:8px 12px;font-size:12px;font-weight:600;color:#50575e;background:#f6f7f7;border-bottom:1px solid #e8e8e8;width:35%;\">URL</td>
                            <td style=\"padding:8px 12px;font-size:12px;color:#2271b1;border-bottom:1px solid #e8e8e8;\"><a href=\"{$url_safe}\" style=\"color:#2271b1;text-decoration:none;\">{$url_safe}</a></td>
                        </tr>";
                    }
                    if ($page_ref) {
                        $ref_safe = esc_url($page_ref);
                        $page_rows .= "<tr>
                            <td style=\"padding:8px 12px;font-size:12px;font-weight:600;color:#50575e;background:#f6f7f7;width:35%;\">Referrer</td>
                            <td style=\"padding:8px 12px;font-size:12px;color:#2271b1;\"><a href=\"{$ref_safe}\" style=\"color:#2271b1;text-decoration:none;\">{$ref_safe}</a></td>
                        </tr>";
                    }
                    $content_html .= "<p style=\"margin:20px 0 6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#a7aaad;\">Origine</p>"
                        . "<table style=\"width:100%;border-collapse:collapse;border:1px solid #e8e8e8;border-radius:4px;overflow:hidden;\">{$page_rows}</table>";
                }
            }
        }

        $html = wpb_build_email_html(
            $content_html,
            $email_title,
            $settings['email_bg_color'] ?? '#f4f4f4',
            $settings['email_header_color'] ?? '#2271b1',
            $settings['email_logo_url'] ?? '',
            $settings['domain_email'] ?? get_option('admin_email')
        );

        // ── Headers ──────────────────────────────────────────────────────────
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $from_email = sanitize_email(wpb_interpolate_field_vars(trim($notif['from_email'] ?? ''), $submission_data));
        $from_name = wpb_interpolate_field_vars(sanitize_text_field($notif['from_name'] ?? ''), $submission_data);
        if ($from_email) {
            $headers[] = $from_name ? "From: {$from_name} <{$from_email}>" : "From: {$from_email}";
        }

        $replyto_email = sanitize_email(wpb_interpolate_field_vars(trim($notif['replyto_email'] ?? ''), $submission_data));
        $replyto_name = wpb_interpolate_field_vars(sanitize_text_field($notif['replyto_name'] ?? ''), $submission_data);
        if ($replyto_email) {
            $headers[] = $replyto_name ? "Reply-To: {$replyto_name} <{$replyto_email}>" : "Reply-To: {$replyto_email}";
        }

        $cc_email = sanitize_email(wpb_interpolate_field_vars(trim($notif['cc_email'] ?? ''), $submission_data));
        $cc_name = wpb_interpolate_field_vars(sanitize_text_field($notif['cc_name'] ?? ''), $submission_data);
        if ($cc_email) {
            $headers[] = $cc_name ? "Bcc: {$cc_name} <{$cc_email}>" : "Bcc: {$cc_email}";
        }

        // ── Sceglie il metodo di invio: Mailjet API o wp_mail ────────────────
        $global_mj = get_option('wpb_forms_mailjet', []);
        $mj_api_key = trim($global_mj['api_key'] ?? '');
        $mj_secret_key = trim($global_mj['secret_key'] ?? '');

        if ($mj_api_key && $mj_secret_key) {
            $sent = wpb_mailjet_send($mj_api_key, $mj_secret_key, $to, $subject, $html, $headers, $attachments);
            $method = 'Mailjet';
        } else {
            $sent = wp_mail($to, $subject, $html, $headers, $attachments);
            $method = 'wp_mail';
        }

        $result = $method . ' to="' . $to . '" subject="' . $subject . '" → ' . ($sent ? 'INVIATA' : 'FALLITA');
        error_log('[WP Barrio] ' . $result);
        $log[] = $result;
    }

    return $log;
}

/* ─────────────────────────────────────────
   HELPER: invio tramite Mailjet Send API v3.1
   ───────────────────────────────────────── */
/**
 * @param string $api_key    Chiave pubblica Mailjet.
 * @param string $secret_key Chiave privata Mailjet.
 * @param string $to         Destinatario ("Nome <email>" o solo email).
 * @param string $subject    Oggetto email.
 * @param string $html       Corpo HTML.
 * @param array  $headers    Header extra già formattati (From:, Reply-To:, Bcc:).
 * @return bool              true se Mailjet ha accettato il messaggio.
 */
function wpb_mailjet_send(
    string $api_key,
    string $secret_key,
    string $to,
    string $subject,
    string $html,
    array $headers = [],
    array $attachments = []
): bool {

    // ── Parsing destinatario "Nome <email>" ───────────────────────────────
    $to_email = $to;
    $to_name = '';
    if (preg_match('/^(.+)\s*<([^>]+)>$/', $to, $m)) {
        $to_name = trim($m[1]);
        $to_email = trim($m[2]);
    }

    // ── Parsing headers extra ─────────────────────────────────────────────
    $from_email = '';
    $from_name = '';
    $replyto = [];
    $bcc = [];

    foreach ($headers as $header) {
        if (stripos($header, 'From:') === 0) {
            $val = trim(substr($header, 5));
            if (preg_match('/^(.+)\s*<([^>]+)>$/', $val, $m)) {
                $from_name = trim($m[1]);
                $from_email = trim($m[2]);
            } else {
                $from_email = $val;
            }
        } elseif (stripos($header, 'Reply-To:') === 0) {
            $val = trim(substr($header, 9));
            if (preg_match('/^(.+)\s*<([^>]+)>$/', $val, $m)) {
                $replyto[] = ['Email' => trim($m[2]), 'Name' => trim($m[1])];
            } else {
                $replyto[] = ['Email' => $val];
            }
        } elseif (stripos($header, 'Bcc:') === 0) {
            $val = trim(substr($header, 4));
            if (preg_match('/^(.+)\s*<([^>]+)>$/', $val, $m)) {
                $bcc[] = ['Email' => trim($m[2]), 'Name' => trim($m[1])];
            } else {
                $bcc[] = ['Email' => $val];
            }
        }
    }

    // Fallback mittente: usa admin email se non configurato
    if (!$from_email) {
        $from_email = get_option('admin_email');
        $from_name = get_bloginfo('name');
    }

    // ── Build payload ─────────────────────────────────────────────────────
    $message = [
        'From' => array_filter(['Email' => $from_email, 'Name' => $from_name]),
        'To' => array_filter([['Email' => $to_email, 'Name' => $to_name]]),
        'Subject' => $subject,
        'HTMLPart' => $html,
    ];

    if (!empty($replyto))
        $message['ReplyTo'] = $replyto[0];
    if (!empty($bcc))
        $message['Bcc'] = $bcc;

    // ── Allegati file ─────────────────────────────────────────────────────
    if (!empty($attachments)) {
        $mj_attachments = [];
        foreach ($attachments as $path) {
            if (!is_readable($path))
                continue;
            $mj_attachments[] = [
                'ContentType' => mime_content_type($path) ?: 'application/octet-stream',
                'Filename' => basename($path),
                'Base64Content' => base64_encode(file_get_contents($path)),
            ];
        }
        if (!empty($mj_attachments))
            $message['Attachments'] = $mj_attachments;
    }

    $body = wp_json_encode(['Messages' => [$message]]);

    // ── Richiesta HTTP con WP HTTP API ────────────────────────────────────
    $response = wp_remote_post(
        'https://api.mailjet.com/v3.1/send',
        [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($api_key . ':' . $secret_key),
            ],
            'body' => $body,
        ]
    );

    if (is_wp_error($response)) {
        error_log('[WP Barrio][Mailjet] WP_Error: ' . $response->get_error_message());
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body_decoded = json_decode(wp_remote_retrieve_body($response), true);
    $status = $body_decoded['Messages'][0]['Status'] ?? 'unknown';

    error_log('[WP Barrio][Mailjet] HTTP ' . $code . ' status=' . $status);

    if ($code !== 200) {
        error_log('[WP Barrio][Mailjet] Response: ' . wp_remote_retrieve_body($response));
        return false;
    }

    return strtolower($status) === 'success';
}

/* ─────────────────────────────────────────
   AJAX: submit front-end (shortcode)
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_forms_submit', 'wpb_forms_handle_submit');
add_action('wp_ajax_nopriv_wpb_forms_submit', 'wpb_forms_handle_submit');

function wpb_forms_handle_submit()
{
    global $wpdb;

    $form_id = intval($_POST['form_id'] ?? 0);
    if (!$form_id)
        wp_send_json_error('Form non valido.');

    check_ajax_referer('wpb_form_submit_' . $form_id, 'wpb_nonce');

    $form = wpb_forms_get($form_id);
    if (!$form)
        wp_send_json_error('Form non trovato.');

    $fields = json_decode($form->fields, true) ?: [];
    $settings = json_decode($form->settings, true) ?: [];

    // ── Validazione required ──────────────────────────────────────────────
    foreach ($fields as $field) {
        if (($field['type'] ?? '') === 'page_break')
            continue;
        if (empty($field['required']))
            continue;

        $fid = $field['name'] ?? '';
        $type = $field['type'] ?? 'text';

        if ($type === 'file') {
            $uploaded = $_FILES[$fid] ?? null;
            $missing = !$uploaded || empty($uploaded['name']) || $uploaded['error'] === UPLOAD_ERR_NO_FILE;
        } else {
            $val = $_POST[$fid] ?? '';
            $missing = '' === trim(is_array($val) ? implode(',', $val) : $val);
        }

        if ($missing) {
            $err = !empty($field['error_message'])
                ? $field['error_message']
                : 'Il campo "' . esc_html($field['label']) . '" è obbligatorio.';
            wp_send_json_error($err);
        }
    }

    // ── Raccolta dati e file ──────────────────────────────────────────────
    $submission_data = []; // solo campi non-file: va in DB e nella tabella email
    $attachments = []; // percorsi temporanei dei file: vanno in allegato

    foreach ($fields as $field) {
        if (($field['type'] ?? '') === 'page_break')
            continue;

        $fid = $field['name'] ?? '';
        $type = $field['type'] ?? 'text';
        $label = $field['label'] ?? $fid;

        if ($type === 'file') {
            // Gestisci upload: salva in wp-content/uploads con wp_handle_upload
            $uploaded = $_FILES[$fid] ?? null;
            if (!$uploaded || empty($uploaded['name']) || $uploaded['error'] !== UPLOAD_ERR_OK)
                continue;

            // Validazione MIME e dimensione base
            $allowed_types = apply_filters('wpb_forms_allowed_file_types', [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain',
                'text/csv',
                'application/zip',
            ]);

            require_once ABSPATH . 'wp-admin/includes/file.php';
            $overrides = ['test_form' => false, 'mimes' => null];
            $moved = wp_handle_upload($uploaded, $overrides);

            if (isset($moved['error'])) {
                error_log('[WP Barrio][file] upload error for ' . $fid . ': ' . $moved['error']);
                continue;
            }

            $file_path = $moved['file'];
            $file_name = basename($file_path);

            // Tieni traccia del file in submission_data con prefisso _file_
            // in modo che sia visibile nel pannello admin ma non nella tabella email
            $submission_data['_file_' . $fid] = [
                'label' => $label,
                'value' => $file_name,
                'url' => $moved['url'],
                'is_file' => true,
            ];

            $attachments[] = $file_path;

        } else {
            $val = $_POST[$fid] ?? '';
            $val = is_array($val)
                ? array_map('sanitize_text_field', $val)
                : sanitize_textarea_field($val);
            $submission_data[$fid] = ['label' => $label, 'value' => $val];
        }
    }

    if (!empty($settings['display_page_info'])) {
        $submission_data['_page_url'] = [
            'label' => 'URL pagina',
            'value' => sanitize_url($_POST['_page_url'] ?? ''),
        ];
        $submission_data['_page_title'] = [
            'label' => 'Titolo pagina',
            'value' => sanitize_text_field($_POST['_page_title'] ?? ''),
        ];
    }

    // Aggiunge la lingua di compilazione a submission_data
    // — disponibile nell'email come {{_lang}} e inclusa nel riepilogo
    $submission_lang = sanitize_key($_POST['_lang'] ?? '');
    if (!$submission_lang && defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE) {
        $submission_lang = ICL_LANGUAGE_CODE;
    }
    if (!$submission_lang) {
        $submission_lang = sanitize_key(apply_filters('wpml_current_language', ''));
    }
    if ($submission_lang) {
        $lang_labels = function_exists('wpb_multilang_languages') ? wpb_multilang_languages() : [];
        $submission_data['_lang'] = [
            'label' => 'Lingua',
            'value' => $lang_labels[$submission_lang] ?? strtoupper($submission_lang),
        ];
    }

    $wpdb->insert(
        $wpdb->prefix . WPB_FORMS_SUB_TABLE,
        [
            'form_id' => $form_id,
            'data' => wp_json_encode($submission_data),
            'lang' => $submission_lang,
            'ip' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ],
        ['%d', '%s', '%s', '%s', '%s']
    );

    // Invia le notifiche email con gli allegati
    wpb_forms_send_notifications($form, $settings, $submission_data, $attachments);

    // ── Integrazione MailUp ───────────────────────────────────────────────
    // Esegui solo se l'integrazione è abilitata per questo form

    if (!empty($settings['mailup_enabled'])) {
        $mu_list_id = max(1, intval($settings['mailup_list_id'] ?: 1));
        $mu_group_id = max(1, intval($settings['mailup_group_id'] ?: 1));
        $mu_email_field = sanitize_key($settings['mailup_email_field'] ?: 'email');
        $mu_optin_field = sanitize_key($settings['mailup_optin_field'] ?: '');

        // Se è configurato un campo opt-in, controlla che sia stato checkato
        $optin_ok = true;
        if ($mu_optin_field !== '') {
            $optin_raw = $submission_data[$mu_optin_field]['value'] ?: '';
            $optin_value = is_array($optin_raw) ? implode(',', $optin_raw) : $optin_raw;
            // Il campo checkbox invia il valore dell'opzione selezionata; consideriamo
            // l'utente iscritto se il valore non è vuoto (= checkbox spuntata)
            $optin_ok = trim($optin_value) !== '';
            if (!$optin_ok) {
                error_log('[WP Barrio][MailUp] Opt-in non selezionato (campo: ' . $mu_optin_field . ') — iscrizione saltata.');
            }
        }

        $mu_email = sanitize_email(
            is_array($submission_data[$mu_email_field]['value'] ?: null)
            ? implode('', $submission_data[$mu_email_field]['value'])
            : ($submission_data[$mu_email_field]['value'] ?: '')
        );

        if ($optin_ok && is_email($mu_email)) {
            // Costruisci query-string dei campi mappati da passare a xmlSubscribe
            // Ogni riga del field_map è: {form_field: slug, mailup_field_name: string}
            $mu_mapped_fields = [];
            $field_map = $settings['mailup_field_map'] ?: [];
            if (is_array($field_map)) {
                foreach ($field_map as $map) {
                    $form_slug = sanitize_key($map['form_field'] ?: '');
                    $mailup_fname = sanitize_text_field($map['mailup_field_name'] ?: '');
                    if (!$form_slug || !$mailup_fname)
                        continue;
                    $raw = $submission_data[$form_slug]['value'] ?: '';
                    $value = sanitize_text_field(
                        is_array($raw) ? implode(', ', $raw) : $raw
                    );
                    $mu_mapped_fields[$mailup_fname] = $value;
                }
            }

            $result = wpb_mailup_subscribe($mu_email, $mu_list_id, $mu_group_id, $mu_mapped_fields);
            if (is_wp_error($result)) {
                error_log('[WP Barrio][MailUp] Errore iscrizione per form_id=' . $form_id . ': ' . $result->get_error_message());
            }
        } else {
            error_log('[WP Barrio][MailUp] Salto iscrizione: email non valida (' . $mu_email . ')');
        }
    }

    wp_send_json_success(['message' => $settings['success_message'] ?? 'Grazie! Il tuo messaggio è stato inviato.']);
}

/* ─────────────────────────────────────────
   AJAX ADMIN: salva impostazioni globali (Mailjet)
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_forms_save_global_settings', function () {
    check_ajax_referer('wpb_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $api_key = sanitize_text_field(wp_unslash($_POST['mailjet_api_key'] ?? ''));
    $secret_key = sanitize_text_field(wp_unslash($_POST['mailjet_secret_key'] ?? ''));

    update_option('wpb_forms_mailjet', [
        'api_key' => $api_key,
        'secret_key' => $secret_key,
    ], false);

    // MailUp: nessuna credenziale necessaria — la chiamata è pubblica
    update_option('wpb_forms_mailup', [], false);

    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_send_json_success('Impostazioni salvate.');
});

/* ─────────────────────────────────────────
   HELPER: iscrizione tramite MailUp xmlSubscribe
   ───────────────────────────────────────── */
/**
 * Iscrive un contatto a una lista/gruppo MailUp tramite l'endpoint xmlSubscribe.
 *
 * @param string $email          Email del contatto da iscrivere.
 * @param int    $list_id        ID della lista MailUp (parametro List, default 1).
 * @param int    $group_id       ID del gruppo MailUp (parametro Group, default 1).
 * @param array  $mapped_fields  Campi aggiuntivi come ['NomeCampoMailUp' => 'valore'].
 * @return bool|WP_Error         true se iscritto con successo, WP_Error in caso di errore.
 */
function wpb_mailup_subscribe(
    string $email,
    int $list_id = 1,
    int $group_id = 1,
    array $mapped_fields = []
) {
    $params = [
        'List' => $list_id,
        'Group' => $group_id,
        'Confirm' => 'false',
        'Email' => $email,
        'csvFldNames' => '',
        'csvFldValues' => ''
    ];

    // Aggiunge i campi mappati come parametri query aggiuntivi
    foreach ($mapped_fields as $field_name => $field_value) {
        $params['csvFldNames'] .= $field_name . ";";
        $params['csvFldValues'] .= $field_value . ";";
    }

    $params['csvFldNames'] = rtrim($params['csvFldNames'], ";");
    $params['csvFldValues'] = rtrim($params['csvFldValues'], ";");

    $urlNewsletter = 'https://a9c1g2.mailupclient.com/frontend/xmlSubscribe.aspx?' . http_build_query($params);
    error_log($urlNewsletter);

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $urlNewsletter,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => [
            'Cookie: BIGipServercu_front_80_pool=!SZdQ+v1gd8oQ8VNyTiB5XWD/TCWaDTD7AeDqgydX491rNiByeurG+QWHNKLmufuzRVmHW51J5iseiX0=',
        ],
    ]);

    $response = curl_exec($curl);
    $curl_errno = curl_errno($curl);
    $curl_error = curl_error($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    // Errore di trasporto cURL
    if ($curl_errno !== 0 || $response === false) {
        $err = "MailUp xmlSubscribe: errore cURL #{$curl_errno} — {$curl_error}";
        error_log('[WP Barrio][MailUp] ' . $err);
        return new WP_Error('mailup_curl', $err);
    }

    // Errore HTTP
    if ($http_code < 200 || $http_code >= 300) {
        $err = "MailUp xmlSubscribe: risposta HTTP {$http_code} — " . wp_strip_all_tags($response);
        error_log('[WP Barrio][MailUp] ' . $err);
        return new WP_Error('mailup_http', $err);
    }

    // La risposta XML di MailUp contiene un codice di esito; valori negativi = errore
    // Esempio di risposta OK:  <xmlSubscribeResponse><Code>0</Code>...</xmlSubscribeResponse>
    if (preg_match('/<Code>([-\d]+)<\/Code>/i', $response, $matches)) {
        $code = intval($matches[1]);
        if ($code < 0) {
            // Estrai eventuale messaggio di errore
            $detail = '';
            if (preg_match('/<Message>([^<]*)<\/Message>/i', $response, $m)) {
                $detail = $m[1];
            }
            $err = "MailUp xmlSubscribe: esito errore (Code={$code})" . ($detail ? " — {$detail}" : '');
            error_log('[WP Barrio][MailUp] ' . $err);
            return new WP_Error('mailup_subscribe', $err);
        }
    }

    error_log("[WP Barrio][MailUp] Iscrizione OK — email={$email} list={$list_id} group={$group_id} http={$http_code} response=" . wp_strip_all_tags($response));
    return true;
}

/* ─────────────────────────────────────────
   HELPER: rivalidazione cache Next.js
   ───────────────────────────────────────── */
function wpb_forms_revalidate_cache(int $form_id): void
{
    $tag = 'form_' . $form_id;
    $url = 'https://hb-st-25.vercel.app/api/revalidate/?' . http_build_query(['tag' => $tag]);

    $response = wp_remote_get($url, ['timeout' => 10, 'blocking' => false]);

    if (is_wp_error($response)) {
        error_log('[WP Barrio][Revalidate] Errore per tag "' . $tag . '": ' . $response->get_error_message());
    } else {
        error_log('[WP Barrio][Revalidate] Richiesta inviata per tag "' . $tag . '"');
    }
}

/* ─────────────────────────────────────────
   AJAX ADMIN: salva form
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_forms_save', function () {
    check_ajax_referer('wpb_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;

    $id = intval($_POST['form_id'] ?? 0);
    $title = sanitize_text_field($_POST['title'] ?? '');
    $fields = wp_unslash($_POST['fields'] ?? '[]');
    $settings = wp_unslash($_POST['settings'] ?? '{}');

    if (empty($title))
        wp_send_json_error('Il titolo è obbligatorio.');
    if (json_decode($fields) === null)
        $fields = '[]';
    if (json_decode($settings) === null)
        $settings = '{}';

    // Normalizza campi
    $fields_arr = json_decode($fields, true);
    if (is_array($fields_arr)) {
        foreach ($fields_arr as &$field) {
            // page_break: pulisci proprietà inutili
            if (($field['type'] ?? '') === 'page_break') {
                $field = ['type' => 'page_break'];
                continue;
            }

            // Campi tel: pattern fisso, niente min/max
            if (($field['type'] ?? '') === 'tel') {
                $field['pattern'] = '^(\+0?1\s)?\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}$';
                unset($field['min'], $field['max']);
            }

            if (($field['type'] ?? '') === 'email') {
                $field['pattern'] = '[a-z0-9\\._%+!$&*=^|~#%{}/\\-]+@([a-z0-9\\-]+\\.){1,}([a-z]{2,22})';
            }

            // ── Normalizza logica condizionale ──────────────────────────────────
            // Nuovo formato: conditionalLogic { enabled, action, logicType, rules[] }
            // Vecchio formato (showFields): migrato a conditionalLogic al salvataggio.

            // 1) Migra vecchio showFields → conditionalLogic se ancora presente
            if (!empty($field['showFields']) && !isset($field['conditionalLogic'])) {
                // Raccoglie tutti i target da tutti i gruppi showFields e li converte
                $migrated_rules = [];
                foreach ($field['showFields'] as $old_rule) {
                    // Normalizza vecchio {targets,operator,value}
                    if (isset($old_rule['targets']) && !isset($old_rule['conditions'])) {
                        $old_rule['fields'] = (array) $old_rule['targets'];
                        $old_rule['conditions'] = [['operator' => $old_rule['operator'] ?? '=', 'value' => $old_rule['value'] ?? '']];
                        unset($old_rule['targets'], $old_rule['operator'], $old_rule['value']);
                    }
                    // Nel vecchio modello la logica stava nel campo trigger;
                    // la migrazione inverte: creiamo regole "quando [trigger] è X" sul campo target.
                    // Poiché non possiamo accedere ai campi target qui, conserviamo le regole
                    // come commento: il builder le risolverà alla prima apertura.
                    foreach ((array) ($old_rule['fields'] ?? $old_rule['targets'] ?? []) as $target_name) {
                        $conditions = $old_rule['conditions'] ?? [['operator' => '=', 'value' => '']];
                        foreach ($conditions as $cond) {
                            $migrated_rules[] = [
                                'fieldName' => $field['name'] ?? '',
                                'operator' => $cond['operator'] ?? '=',
                                'value' => $cond['value'] ?? '',
                            ];
                        }
                    }
                }
                // Rimuovi showFields: sostituito da conditionalLogic
                unset($field['showFields']);
            }

            // 2) Sanitizza conditionalLogic se presente
            if (isset($field['conditionalLogic'])) {
                $cl = $field['conditionalLogic'];
                $field['conditionalLogic'] = [
                    'enabled' => !empty($cl['enabled']),
                    'action' => in_array($cl['action'] ?? '', ['show', 'hide']) ? $cl['action'] : 'show',
                    'logicType' => in_array($cl['logicType'] ?? '', ['all', 'any']) ? $cl['logicType'] : 'all',
                    'rules' => array_values(array_filter(
                        array_map(function ($r) {
                            if (empty($r['fieldName']))
                                return null;
                            $allowed_ops = ['=', '!=', '>', '<', 'contains', 'starts_with', 'ends_with', 'empty', 'not_empty'];
                            return [
                                'fieldName' => sanitize_key($r['fieldName']),
                                'operator' => in_array($r['operator'] ?? '', $allowed_ops) ? $r['operator'] : '=',
                                'value' => sanitize_text_field($r['value'] ?? ''),
                            ];
                        }, (array) ($cl['rules'] ?? []))
                    )),
                ];
            }
        }
        unset($field);
        $fields = wp_json_encode($fields_arr);
    }

    $table = $wpdb->prefix . WPB_FORMS_TABLE;

    if ($id) {
        $ok = $wpdb->update(
            $table,
            ['title' => $title, 'fields' => $fields, 'settings' => $settings],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        if ($ok === false)
            wp_send_json_error('Errore DB: ' . $wpdb->last_error);
        wpb_forms_revalidate_cache($id);
        if (function_exists('invalidate_vercel_cache')) {
            invalidate_vercel_cache('wp:global');
        }
        wp_send_json_success(['id' => $id, 'message' => 'Form salvato.']);
    } else {
        $ok = $wpdb->insert(
            $table,
            ['title' => $title, 'fields' => $fields, 'settings' => $settings],
            ['%s', '%s', '%s']
        );
        if ($ok === false)
            wp_send_json_error('Errore DB: ' . $wpdb->last_error);
        $new_id = $wpdb->insert_id;
        wpb_forms_revalidate_cache($new_id);
        if (function_exists('invalidate_vercel_cache')) {
            invalidate_vercel_cache('wp:global');
        }
        wp_send_json_success(['id' => $new_id, 'message' => 'Form creato.']);
    }
});

/* ─────────────────────────────────────────
   AJAX ADMIN: elimina form
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_forms_delete', function () {
    check_ajax_referer('wpb_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');
    global $wpdb;
    $id = intval($_POST['form_id'] ?? 0);
    if (!$id)
        wp_send_json_error('ID non valido.');
    wpb_forms_delete_translations($id);
    $wpdb->delete($wpdb->prefix . WPB_FORMS_TABLE, ['id' => $id], ['%d']);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('wp:global');
    }
    wp_send_json_success('Form eliminato.');
});

/* ─────────────────────────────────────────
   AJAX ADMIN: init traduzione form (copia valori originali)
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_forms_init_translation', function () {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wpb_forms_nonce'))
        wp_send_json_error('Nonce non valido.');
    if (!current_user_can('manage_options') && !current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $form_id = intval($_POST['form_id'] ?? 0);
    $lang = sanitize_key($_POST['lang'] ?? '');
    if (!$form_id || !$lang)
        wp_send_json_error('Parametri mancanti: form_id=' . $form_id . ' lang=' . $lang);

    // Verifica lingua solo se WPML è disponibile e operativo
    if (defined('ICL_SITEPRESS_VERSION')) {
        global $sitepress;
        if (isset($sitepress) && is_object($sitepress)) {
            $allowed = wpb_multilang_translation_languages();
            if (!empty($allowed) && !in_array($lang, $allowed, true))
                wp_send_json_error('Lingua non consentita: ' . $lang);
        }
    }

    if (wpb_forms_get_translation($form_id, $lang))
        wp_send_json_success(['already_exists' => true]);

    $form = wpb_forms_get($form_id);
    if (!$form)
        wp_send_json_error('Form non trovato: id=' . $form_id);

    $fields = json_decode($form->fields, true) ?: [];
    $settings = json_decode($form->settings, true) ?: [];
    [$fi18n, $si18n] = wpb_forms_extract_translatable($fields, $settings);

    $ok = wpb_forms_save_translation($form_id, $lang, $fi18n, $si18n);
    if (!$ok) {
        global $wpdb;
        wp_send_json_error('Errore DB: ' . $wpdb->last_error);
    }

    wp_send_json_success(['already_exists' => false]);
});

/* ─────────────────────────────────────────
   AJAX ADMIN: salva traduzione form
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_forms_save_translation', function () {
    check_ajax_referer('wpb_forms_nonce', 'nonce');
    if (!current_user_can('manage_options') && !current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $form_id = intval($_POST['form_id'] ?? 0);
    $lang = sanitize_key($_POST['lang'] ?? '');
    $fields_i18n_raw = wp_unslash($_POST['fields_i18n'] ?? '[]');
    $settings_i18n_raw = wp_unslash($_POST['settings_i18n'] ?? '{}');

    if (!$form_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    $allowed = wpb_multilang_translation_languages();
    if (!in_array($lang, $allowed, true))
        wp_send_json_error('Lingua non consentita.');

    $fi18n = json_decode($fields_i18n_raw, true);
    $si18n = json_decode($settings_i18n_raw, true);
    if (!is_array($fi18n))
        $fi18n = [];
    if (!is_array($si18n))
        $si18n = [];

    // Sanitize fields_i18n
    $fi18n_clean = [];
    foreach ($fi18n as $entry) {
        $fi18n_clean[] = [
            'label' => sanitize_text_field($entry['label'] ?? ''),
            'placeholder' => sanitize_text_field($entry['placeholder'] ?? ''),
            'error_message' => sanitize_text_field($entry['error_message'] ?? ''),
            'options' => array_map('sanitize_text_field', (array) ($entry['options'] ?? [])),
        ];
    }

    // Sanitize settings_i18n
    $si18n_clean = [
        'submit_label' => sanitize_text_field($si18n['submit_label'] ?? ''),
        'redirect_url' => sanitize_url($si18n['redirect_url'] ?? ''),
        'success_message' => wp_kses_post($si18n['success_message'] ?? ''),
        'mailup_list_id' => sanitize_text_field($si18n['mailup_list_id'] ?? ''),
        'mailup_group_id' => sanitize_text_field($si18n['mailup_group_id'] ?? ''),
        'notifications' => [],
    ];
    foreach ((array) ($si18n['notifications'] ?? []) as $notif) {
        $si18n_clean['notifications'][] = [
            'subject' => sanitize_text_field($notif['subject'] ?? ''),
            'body' => wp_kses_post($notif['body'] ?? ''),
            'reply_to' => sanitize_email($notif['reply_to'] ?? ''),
            'from_name' => sanitize_text_field($notif['from_name'] ?? ''),
            'to' => sanitize_text_field($notif['to'] ?? ''),
        ];
    }

    $ok = wpb_forms_save_translation($form_id, $lang, $fi18n_clean, $si18n_clean);
    if (!$ok)
        wp_send_json_error('Errore DB.');

    if (function_exists('invalidate_vercel_cache'))
        invalidate_vercel_cache('wp:global');
    wp_send_json_success(['message' => 'Traduzione salvata.']);
});

/* ─────────────────────────────────────────
   AJAX ADMIN: carica traduzione form
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_forms_get_translation', function () {
    check_ajax_referer('wpb_forms_nonce', 'nonce');
    if (!current_user_can('manage_options') && !current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $form_id = intval($_POST['form_id'] ?? 0);
    $lang = sanitize_key($_POST['lang'] ?? '');
    if (!$form_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    $row = wpb_forms_get_translation($form_id, $lang);
    if (!$row)
        wp_send_json_error('Traduzione non trovata.');

    wp_send_json_success([
        'fields_i18n' => json_decode($row->fields_i18n, true) ?: [],
        'settings_i18n' => json_decode($row->settings_i18n, true) ?: [],
    ]);
});

/* ─────────────────────────────────────────
   AJAX ADMIN: carica submissions + struttura campi (tab Registrazioni)
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_forms_get_submissions', function () {
    check_ajax_referer('wpb_forms_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $form_id = intval($_POST['form_id'] ?? 0);
    if (!$form_id)
        wp_send_json_error('ID form non valido.');

    $form = wpb_forms_get($form_id);
    if (!$form)
        wp_send_json_error('Form non trovato.');

    // Campi definiti nel form (struttura) — escludi page_break
    $form_fields = array_values(array_filter(
        json_decode($form->fields, true) ?: [],
        fn($f) => ($f['type'] ?? '') !== 'page_break'
    ));

    // Submissions (tutte, ordinate dalla più recente)
    $subs_raw = wpb_forms_get_submissions($form_id, 200, 0);
    $total = wpb_forms_count_submissions($form_id);

    $is_admin = current_user_can('manage_options');
    $subs = array_map(function ($s) use ($is_admin) {
        $entry = [
            'id' => (int) $s->id,
            'data' => json_decode($s->data, true) ?: [],
            'lang' => $s->lang ?? '',
            'created_at' => date_i18n('d/m/Y H:i', strtotime($s->created_at)),
            'created_at_full' => date_i18n('d/m/Y \a\l\l\e H:i:s', strtotime($s->created_at)),
        ];
        if ($is_admin) {
            $entry['ip'] = $s->ip;
            $entry['user_agent'] = $s->user_agent ?? '';
        }
        return $entry;
    }, $subs_raw);

    wp_send_json_success([
        'total' => $total,
        'fields' => $form_fields,
        'subs' => $subs,
    ]);
});

/* ─────────────────────────────────────────
   AJAX ADMIN: elimina tutte le submissions di un form
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_forms_delete_all_submissions', function () {
    check_ajax_referer('wpb_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $form_id = intval($_POST['form_id'] ?? 0);
    if (!$form_id)
        wp_send_json_error('ID form non valido.');

    $wpdb->delete($wpdb->prefix . WPB_FORMS_SUB_TABLE, ['form_id' => $form_id], ['%d']);
    wp_send_json_success('Registrazioni eliminate.');
});
add_action('wp_ajax_wpb_forms_delete_submission', function () {
    check_ajax_referer('wpb_forms_nonce', 'nonce');
    if (!current_user_can('manage_options') && !current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');
    global $wpdb;
    $id = intval($_POST['submission_id'] ?? 0);
    if (!$id)
        wp_send_json_error('ID non valido.');
    $wpdb->delete($wpdb->prefix . WPB_FORMS_SUB_TABLE, ['id' => $id], ['%d']);
    wp_send_json_success('Risposta eliminata.');
});

/* ─────────────────────────────────────────
   AJAX ADMIN: elimina più submissions (bulk delete)
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_forms_delete_submissions', function () {
    check_ajax_referer('wpb_forms_nonce', 'nonce');
    if (!current_user_can('manage_options') && !current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $form_id = intval($_POST['form_id'] ?? 0);
    $raw = wp_unslash($_POST['ids'] ?? '[]');
    $ids = array_filter(array_map('intval', json_decode($raw, true) ?: []), fn($id) => $id > 0);

    if (empty($ids))
        wp_send_json_error('Nessun ID valido.');

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $wpdb->query($wpdb->prepare(
        "DELETE FROM `{$wpdb->prefix}wpb_form_submissions`
         WHERE id IN ($placeholders)" . ($form_id ? ' AND form_id = %d' : ''),
        ...($form_id ? [...$ids, $form_id] : $ids)
    ));

    wp_send_json_success(['deleted' => count($ids)]);
});

/* ─────────────────────────────────────────
   AJAX ADMIN: rinvia notifica per una singola submission
   ───────────────────────────────────────── */
add_action('wp_ajax_wpb_forms_resend_notification', function () {
    check_ajax_referer('wpb_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $submission_id = intval($_POST['submission_id'] ?? 0);
    $notif_index = intval($_POST['notification_index'] ?? -1);
    $override_email = sanitize_email($_POST['override_email'] ?? '');

    if (!$submission_id)
        wp_send_json_error('ID registrazione non valido.');

    $sub = wpb_forms_get_submission($submission_id);
    if (!$sub)
        wp_send_json_error('Registrazione non trovata.');

    $form = wpb_forms_get($sub->form_id);
    if (!$form)
        wp_send_json_error('Form non trovato.');

    $settings = json_decode($form->settings, true) ?: [];
    $notifications = $settings['notifications'] ?? [];

    if ($notif_index < 0 || $notif_index >= count($notifications))
        wp_send_json_error('Template di notifica non valido.');

    $submission_data = json_decode($sub->data, true) ?: [];

    $notif = $notifications[$notif_index];
    if ($override_email) {
        $notif['to_email'] = $override_email;
        $notif['to_name'] = '';
    }

    $temp_settings = $settings;
    $temp_settings['notifications'] = [$notif];

    $log = wpb_forms_send_notifications($form, $temp_settings, $submission_data);

    wp_send_json_success([
        'message' => 'Notifica reinviata con successo.',
        'log' => $log,
    ]);
});

/* ─────────────────────────────────────────
   VOCE DI MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    add_submenu_page(
        'wp-barrio',
        'Forms — WP Barrio',
        'Forms',
        'manage_options',
        'wp-barrio-forms',
        'wpb_forms_admin_page'
    );
}, 14);

/* ─────────────────────────────────────────
   ENQUEUE: wp.media nella pagina Forms
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'wp-barrio_page_wp-barrio-forms')
        return;
    wp_enqueue_media();
});

/* ─────────────────────────────────────────
   PAGINA ADMIN FORMS
   ───────────────────────────────────────── */
function wpb_forms_admin_page()
{

    // Nonce sempre disponibile prima di qualsiasi output
    $nonce = wp_create_nonce('wpb_forms_nonce');

    $forms = wpb_forms_get_all();
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'list';
    $edit_id = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0;
    $view_id = isset($_GET['view_id']) ? intval($_GET['view_id']) : 0;
    $sub_id = isset($_GET['sub_id']) ? intval($_GET['sub_id']) : 0;

    $edit_form = $edit_id ? wpb_forms_get($edit_id) : null;
    $view_form = $view_id ? wpb_forms_get($view_id) : null;
    $sub_detail = ($sub_id && $view_id) ? wpb_forms_get_submission($sub_id) : null;
    $builder_form_id = ($tab === 'builder') ? $edit_id : 0;

    // Dati builder (usati solo nel tab builder, ma dichiarati sempre per il JS)
    $saved_fields = ($edit_form) ? $edit_form->fields : '[]';
    $saved_settings = ($edit_form) ? $edit_form->settings : '{}';

    // Dati multilingua
    $ml_enabled = wpb_multilang_is_enabled();
    $ml_primary = wpb_multilang_primary_language();
    $ml_trans = wpb_multilang_translation_languages();
    $ml_languages = wpb_multilang_languages_with_flags();
    $ml_active = $ml_enabled && !empty($ml_trans);

    ?>
    <style>
        #wpb-forms-wrap {
            max-width: 1200px;
            font-family: -apple-system, BlinkMacSystemFont, "Inter", "Segoe UI", sans-serif;
        }

        .wpb-forms-table-wrap {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .05);
        }

        .wpb-forms-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .wpb-forms-table th {
            background: #f9fafb;
            padding: 10px 14px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
        }

        .wpb-forms-table td {
            padding: 11px 14px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
            color: #374151;
        }

        .wpb-forms-table tr:last-child td {
            border-bottom: none;
        }

        .wpb-forms-table tr:hover td {
            background: #f9fafb;
        }

        /* ── Pill lingue nella lista form ── */
        .wpb-form-langs-row td {
            background: #fafafa !important;
            padding: 6px 14px 8px !important;
            border-top: none !important;
        }

        .wpb-form-langs {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
        }

        .wpb-form-langs-label {
            font-size: 11px;
            color: #9ca3af;
            margin-right: 3px;
        }

        .wpb-lang-pill {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 8px;
            border: 1px solid;
        }

        .wpb-lang-pill.primary {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
        }

        .wpb-lang-pill.translated {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #166534;
            cursor: pointer;
            text-decoration: none;
        }

        .wpb-lang-pill.missing {
            background: #fff;
            border-color: #e5e7eb;
            color: #6b7280;
            cursor: pointer;
        }

        .wpb-lang-pill.missing:hover {
            background: #f0f6fc;
            border-color: #72aee6;
            color: #111827;
        }

        .wpb-lang-pill.missing .wpb-lang-add {
            font-size: 13px;
            font-weight: 400;
            line-height: 1;
        }

        .wpb-lang-pill.loading {
            opacity: .6;
            cursor: wait;
        }

        text-align: center;
        padding: 48px 20px;
        color: #6b7280;
        }

        .wpb-forms-empty .dashicons {
            font-size: 48px;
            width: 48px;
            height: 48px;
            color: #e5e7eb;
        }

        .wpb-sub-count {
            display: inline-block;
            padding: 1px 9px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            background: #f3f4f6;
            color: #111827;
        }

        .wpb-sub-count.has-subs {
            background: #dcfce7;
            color: #059669;
        }

        /* ── BUILDER ── */
        .wpb-builder-layout {
            display: grid;
            grid-template-columns: 240px 1fr 280px;
            gap: 0;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
        }

        .wpb-panel-fields {
            background: #f9fafb;
            border-right: 1px solid #e5e7eb;
            overflow-y: auto;
            height: 100%;
        }

        .wpb-panel-fields h3 {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #6b7280;
            padding: 14px 16px 10px;
            border-bottom: 1px solid #e0e0e0;
            margin: 0;
        }

        .wpb-field-type-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 10px 16px;
            background: none;
            border: none;
            border-bottom: 1px solid #e8e8e8;
            cursor: pointer;
            font-size: 13px;
            font-family: inherit;
            color: #111827;
            text-align: left;
            transition: background .1s;
        }

        .wpb-field-type-btn:hover {
            background: #fff;
        }

        .wpb-ft-icon {
            width: 28px;
            height: 28px;
            border-radius: 10px;
            background: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .wpb-field-type-btn:hover .wpb-ft-icon {
            background: #d0d5dd;
        }

        .wpb-panel-canvas {
            padding: 20px;
            background: #fafafa;
            overflow-y: auto;
            border-right: 1px solid #e5e7eb;
            height: 100%;
            box-sizing: border-box;
        }

        .wpb-canvas-title {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #6b7280;
            margin: 0 0 14px;
        }

        .wpb-canvas-drop-zone {
            min-height: 380px;
            border: 2px dashed #e5e7eb;
            border-radius: 10px;
            padding: 10px;
            transition: border-color .15s, background .15s;
        }

        .wpb-canvas-drop-zone.drag-over {
            border-color: #111827;
            background: rgba(17, 24, 39, .03);
        }

        .wpb-canvas-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 260px;
            color: #9ca3af;
            pointer-events: none;
        }

        .wpb-canvas-empty .dashicons {
            font-size: 40px;
            width: 40px;
            height: 40px;
        }

        .wpb-canvas-empty p {
            font-size: 13px;
            margin: 8px 0 0;
        }

        .wpb-field-card {
            background: #fff;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            margin-bottom: 8px;
            cursor: grab;
            transition: box-shadow .1s, border-color .1s;
        }

        .wpb-field-card:active {
            cursor: grabbing;
        }

        .wpb-field-card.selected {
            border-color: #111827;
            box-shadow: 0 0 0 2px rgba(34, 113, 177, .2);
        }

        .wpb-field-card.dragging {
            opacity: .4;
        }

        .wpb-field-card-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-bottom: 1px solid #f3f4f6;
        }

        .wpb-drag-handle {
            color: #9ca3af;
            cursor: grab;
            font-size: 16px;
            line-height: 1;
            flex-shrink: 0;
        }

        .wpb-field-card-label {
            font-size: 13px;
            font-weight: 600;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .wpb-field-card-type {
            font-size: 10px;
            color: #6b7280;
            background: #f3f4f6;
            padding: 1px 7px;
            border-radius: 8px;
        }

        .wpb-btn-remove-field {
            background: none;
            border: none;
            cursor: pointer;
            color: #dc2626;
            font-size: 18px;
            padding: 0 2px;
            line-height: 1;
            flex-shrink: 0;
            opacity: .5;
            transition: opacity .1s;
        }

        .wpb-btn-remove-field:hover {
            opacity: 1;
        }

        .wpb-field-card-preview {
            padding: 8px 12px 10px;
        }

        .wpb-field-card-preview input,
        .wpb-field-card-preview textarea {
            width: 100%;
            padding: 5px 8px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            font-size: 12px;
            color: #9ca3af;
            background: #fafafa;
            pointer-events: none;
            box-sizing: border-box;
        }

        .wpb-field-card-preview textarea {
            height: 52px;
            resize: none;
        }

        /* Preview select */
        .wpb-preview-select {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 5px 8px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            background: #fafafa;
            font-size: 12px;
            color: #9ca3af;
        }

        /* Preview radio / checkbox */
        .wpb-preview-choices {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .wpb-preview-choice {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: 12px;
            color: #6b7280;
        }

        .wpb-preview-radio,
        .wpb-preview-checkbox {
            width: 13px;
            height: 13px;
            border: 1.5px solid #e5e7eb;
            flex-shrink: 0;
            background: #fff;
        }

        .wpb-preview-radio {
            border-radius: 50%;
        }

        .wpb-preview-checkbox {
            border-radius: 2px;
        }

        /* Preview datepicker */
        .wpb-preview-date {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 5px 8px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            background: #fafafa;
            font-size: 12px;
            color: #9ca3af;
        }

        /* Preview file */
        .wpb-preview-file {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 5px 8px;
            border: 1px solid #d0d5dd;
            border-radius: 8px;
            background: #fafafa;
            font-size: 12px;
            color: #9ca3af;
        }

        .wpb-panel-props {
            overflow-y: auto;
            height: 100%;
        }

        .wpb-panel-props h3 {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #6b7280;
            padding: 14px 16px 10px;
            border-bottom: 1px solid #e0e0e0;
            margin: 0;
            background: #f9fafb;
        }

        .wpb-props-inner {
            padding: 14px 16px;
        }

        .wpb-prop-group {
            margin-bottom: 14px;
        }

        .wpb-prop-group label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
        }

        .wpb-prop-group input[type=text],
        .wpb-prop-group input[type=number],
        .wpb-prop-group textarea,
        .wpb-prop-group select {
            width: 100%;
            padding: 6px 9px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 12px;
            font-family: inherit;
            background: #fff;
            box-sizing: border-box;
        }

        .wpb-prop-group input:focus,
        .wpb-prop-group textarea:focus,
        .wpb-prop-group select:focus {
            outline: none;
            border-color: #111827;
        }

        .wpb-prop-check {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #111827;
            cursor: pointer;
            font-weight: 400;
        }

        .wpb-no-field-selected {
            text-align: center;
            padding: 40px 16px;
            color: #9ca3af;
            font-size: 13px;
        }

        .wpb-no-field-selected .dashicons {
            font-size: 36px;
            width: 36px;
            height: 36px;
        }

        .wpb-builder-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .wpb-builder-title-input {
            flex: 1;
            min-width: 200px;
            padding: 7px 12px;
            font-size: 15px;
            font-weight: 600;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-family: inherit;
        }

        .wpb-builder-title-input:focus {
            outline: none;
            border-color: #111827;
        }

        /* Opzioni lista */
        .wpb-options-list {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-top: 6px;
        }

        .wpb-option-row {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .wpb-option-row input[type=text] {
            flex: 1;
            padding: 4px 7px;
            font-size: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
        }

        .wpb-btn-rm-opt {
            background: none;
            border: none;
            color: #dc2626;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            padding: 0;
        }

        .wpb-btn-add-opt {
            font-size: 11px;
            background: none;
            border: 1px dashed #e5e7eb;
            border-radius: 8px;
            padding: 4px 10px;
            cursor: pointer;
            color: #6b7280;
            margin-top: 4px;
            width: 100%;
        }

        .wpb-btn-add-opt:hover {
            border-color: #111827;
            color: #111827;
        }

        /* ── Logica condizionale (Gravity Forms-style) ── */
        .wpb-cond-section {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid #f3f4f6;
        }

        .wpb-cond-section-title {
            font-size: 11px;
            font-weight: 600;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin: 0 0 10px;
        }

        .wpb-cl-enable-row {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            margin-bottom: 10px;
            font-size: 12px;
            font-weight: 600;
            color: #111827;
        }

        .wpb-cl-enable-row input[type=checkbox] {
            cursor: pointer;
            margin: 0;
        }

        .wpb-cond-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 8px;
        }

        /* Riga singola regola: [campo] [op] [valore] [×] */
        .wpb-cond-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .wpb-cond-row select,
        .wpb-cond-row input[type=text] {
            padding: 4px 6px;
            font-size: 11px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #fff;
            font-family: inherit;
        }

        .cl-rule-field {
            flex: 1;
            min-width: 110px;
        }

        .cl-rule-op {
            flex: 0 0 auto;
        }

        .cl-rule-val {
            flex: 1;
            min-width: 80px;
        }

        .wpb-btn-rm-cond {
            background: none;
            border: none;
            cursor: pointer;
            color: #dc2626;
            font-size: 15px;
            line-height: 1;
            padding: 0 2px;
            flex-shrink: 0;
            opacity: .6;
        }

        .wpb-btn-rm-cond:hover {
            opacity: 1;
        }

        .wpb-btn-add-cond {
            font-size: 11px;
            background: none;
            border: 1px dashed #e5e7eb;
            border-radius: 8px;
            padding: 4px 10px;
            cursor: pointer;
            color: #6b7280;
            width: 100%;
            font-family: inherit;
        }

        .wpb-btn-add-cond:hover {
            border-color: #111827;
            color: #111827;
        }

        /* ══ Translation mode ══════════════════════════════════════════ */

        /* Canvas: no drag handle, no delete button */
        .wpb-translation-mode .wpb-drag-handle {
            display: none !important;
        }

        .wpb-translation-mode .wpb-btn-remove-field {
            display: none !important;
        }

        .wpb-translation-mode .wpb-field-card {
            cursor: pointer !important;
        }

        /* Props panel: blocca tutto tranne label, placeholder, opzioni, errore */
        .wpb-translation-mode #prop-name {
            pointer-events: none !important;
            opacity: .35 !important;
            user-select: none;
        }

        .wpb-translation-mode #prop-required {
            pointer-events: none !important;
            opacity: .35 !important;
        }

        .wpb-translation-mode #prop-min {
            pointer-events: none !important;
            opacity: .35 !important;
        }

        .wpb-translation-mode #prop-max {
            pointer-events: none !important;
            opacity: .35 !important;
        }

        .wpb-translation-mode #prop-format {
            pointer-events: none !important;
            opacity: .35 !important;
        }

        .wpb-translation-mode .wpb-cond-section {
            pointer-events: none !important;
            opacity: .35 !important;
            user-select: none;
        }

        /* Blocca anche i label wrapper dei campi non traducibili */
        .wpb-translation-mode .wpb-prop-check {
            pointer-events: none !important;
            opacity: .35 !important;
        }

        /* Impostazioni — blocca tutto tranne: submit-text, success-msg, redirect-url, mailup-list-id */
        .wpb-translation-mode .wpb-after-submit-toggle {
            pointer-events: none !important;
            opacity: .35 !important;
        }

        .wpb-translation-mode #wpb-setting-display-page-info {
            pointer-events: none !important;
            opacity: .35 !important;
        }

        .wpb-translation-mode #wpb-setting-mailup-enabled {
            pointer-events: none !important;
            opacity: .35 !important;
        }

        .wpb-translation-mode #wpb-setting-mailup-email-field {
            pointer-events: none !important;
            opacity: .35 !important;
        }

        .wpb-translation-mode #wpb-setting-mailup-optin-field {
            pointer-events: none !important;
            opacity: .35 !important;
        }

        .wpb-translation-mode #wpb-mailup-add-map-row {
            pointer-events: none !important;
            opacity: .35 !important;
        }

        .wpb-translation-mode #wpb-mailup-field-map-rows {
            pointer-events: none !important;
            opacity: .35 !important;
        }

        /* Blocca il toggle abilitazione MailUp (il suo label wrapper) */
        .wpb-translation-mode #wpb-setting-mailup-enabled~span {
            pointer-events: none !important;
        }

        .wpb-builder-tabs {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 16px;
        }

        .wpb-btab {
            padding: 8px 16px;
            font-size: 13px;
            font-family: inherit;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            color: #6b7280;
            margin-bottom: -1px;
        }

        .wpb-btab.active {
            color: #111827;
            border-bottom-color: #111827;
            font-weight: 600;
        }

        /* ── Registrazioni ── */
        .wpb-reg-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            gap: 12px;
        }

        .wpb-reg-count {
            font-size: 13px;
            color: #6b7280;
        }

        .wpb-reg-empty {
            text-align: center;
            padding: 48px 20px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            color: #6b7280;
            font-size: 13px;
        }

        .wpb-reg-table-wrap {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
            overflow-x: auto;
            width: 100%;
        }

        .wpb-reg-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 500px;
        }

        .wpb-reg-table thead tr {
            background: #f3f4f6;
        }

        .wpb-reg-table th {
            padding: 9px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }

        .wpb-reg-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
            font-size: 12px;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .wpb-reg-table td.wpb-reg-cell-empty {
            color: #e5e7eb;
            font-style: italic;
        }

        .wpb-reg-table tr:last-child td {
            border-bottom: none;
        }

        .wpb-reg-table tr:hover td {
            background: #fafafa;
        }

        .wpb-reg-table tbody tr {
            cursor: pointer;
        }

        .wpb-reg-table td:first-child {
            color: #9ca3af;
            font-size: 11px;
            width: 40px;
        }

        .wpb-reg-table td:last-child {
            width: 60px;
            text-align: center;
        }

        .wpb-reg-date {
            font-size: 11px;
            color: #6b7280;
            white-space: nowrap;
        }

        .wpb-check-on {
            padding: 1px 0;
            color: #059669;
            font-weight: 600;
        }

        .wpb-check-off {
            padding: 1px 0;
            color: #9ca3af;
        }

        .wpb-check-summary {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            background: #f3f4f6;
            color: #6b7280;
        }

        /* Toggle dopo-invio */
        .wpb-after-submit-toggle {
            display: flex;
            gap: 0;
            margin-bottom: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }

        .wpb-ast-btn {
            flex: 1;
            padding: 7px 12px;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit;
            border: none;
            border-right: 1px solid #e5e7eb;
            cursor: pointer;
            background: #f9fafb;
            color: #6b7280;
            transition: all .1s;
        }

        .wpb-ast-btn:last-child {
            border-right: none;
        }

        .wpb-ast-btn.active {
            background: #111827;
            color: #fff;
        }

        /* Submissions */
        .wpb-sub-table-wrap {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
            overflow-x: auto;
        }

        .wpb-sub-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 500px;
        }

        .wpb-sub-table th {
            background: #f3f4f6;
            padding: 8px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
        }

        .wpb-sub-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
            font-size: 12px;
        }

        .wpb-sub-table tr:last-child td {
            border-bottom: none;
        }

        .wpb-sub-table tr:hover td {
            background: #fafafa;
        }

        /* Dettaglio submission */
        .wpb-sub-detail-wrap {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            max-width: 760px;
            width: 60%;
        }

        .wpb-sub-detail-header {
            padding: 16px 20px;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .wpb-sub-detail-header h3 {
            margin: 0;
            font-size: 14px;
            color: #111827;
        }

        .wpb-sub-detail-meta {
            padding: 12px 20px;
            background: #f9fafb;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .wpb-sub-detail-meta span {
            font-size: 12px;
            color: #6b7280;
        }

        .wpb-sub-detail-meta strong {
            color: #111827;
        }

        .wpb-sub-detail-fields {
            padding: 0;
        }

        .wpb-sub-detail-row {
            display: grid;
            grid-template-columns: 200px 1fr;
            padding: 12px 20px;
            border-bottom: 1px solid #f3f4f6;
            gap: 16px;
        }

        .wpb-sub-detail-row:last-child {
            border-bottom: none;
        }

        .wpb-sub-detail-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: .3px;
            padding-top: 1px;
        }

        .wpb-sub-detail-value {
            font-size: 13px;
            color: #111827;
            word-break: break-word;
            white-space: pre-wrap;
        }

        .wpb-sub-detail-value.wpb-empty {
            color: #9ca3af;
            font-style: italic;
        }

        .wpb-sub-detail-sep {
            padding: 10px 20px;
            background: #f3f4f6;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .5px;
            color: #374151;
            text-transform: uppercase;
            border-bottom: 1px solid #e0e0e0;
        }

        /* ── Rinvia notifica ── */
        .wpb-resend-wrap {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            max-width: 760px;
            margin-top: 14px;
            overflow: hidden;
            width: calc(40% - 30px);
            height: 100%;
        }

        .wpb-resend-header {
            padding: 14px 20px;
            border-bottom: 1px solid #f3f4f6;
            background: #f9fafb;
        }

        .wpb-resend-header h4 {
            margin: 0;
            font-size: 13px;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .wpb-resend-body {
            padding: 20px;
        }

        .wpb-resend-field {
            margin-bottom: 14px;
        }

        .wpb-resend-field:last-child {
            margin-bottom: 0;
        }

        .wpb-resend-field label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
        }

        .wpb-resend-field select,
        .wpb-resend-field input[type=email] {
            width: 100%;
            max-width: 400px;
            padding: 7px 10px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            background: #fff;
            box-sizing: border-box;
        }

        .wpb-resend-field select:focus,
        .wpb-resend-field input:focus {
            outline: none;
            border-color: #111827;
        }

        .wpb-resend-field .description {
            font-size: 11px;
            color: #9ca3af;
            margin: 4px 0 0;
        }

        .wpb-resend-actions {
            margin-top: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .wpb-resend-actions .wpb-resend-result {
            font-size: 13px;
        }

        /* ── Notifiche ── */
        .wpb-notif-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            margin-bottom: 10px;
            overflow: hidden;
            transition: box-shadow .15s;
        }

        .wpb-notif-card:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
        }

        .wpb-notif-card.is-open {
            border-color: #111827;
        }

        .wpb-notif-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            cursor: pointer;
            user-select: none;
            background: #f9fafb;
        }

        .wpb-notif-card.is-open .wpb-notif-header {
            background: #f9fafb;
            border-bottom: 1px solid #f3f4f6;
        }

        .wpb-notif-header-icon {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #111827;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }

        .wpb-notif-header-info {
            flex: 1;
            min-width: 0;
        }

        .wpb-notif-header-title {
            font-size: 13px;
            font-weight: 600;
            color: #111827;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .wpb-notif-header-sub {
            font-size: 11px;
            color: #6b7280;
            margin-top: 2px;
        }

        .wpb-notif-header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .wpb-notif-chevron {
            font-size: 11px;
            color: #6b7280;
            transition: transform .2s;
            display: block;
        }

        .wpb-notif-card.is-open .wpb-notif-chevron {
            transform: rotate(180deg);
        }

        .wpb-notif-del-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #dc2626;
            font-size: 16px;
            padding: 0 2px;
            line-height: 1;
            opacity: .5;
            transition: opacity .1s;
        }

        .wpb-notif-del-btn:hover {
            opacity: 1;
        }

        .wpb-notif-body {
            display: none;
            padding: 20px;
            border-top: none;
        }

        .wpb-notif-card.is-open .wpb-notif-body {
            display: block;
        }

        .wpb-notif-section {
            margin-bottom: 20px;
        }

        .wpb-notif-section:last-child {
            margin-bottom: 0;
        }

        .wpb-notif-section-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: #9ca3af;
            margin: 0 0 10px;
            padding-bottom: 7px;
            border-bottom: 1px solid #f3f4f6;
        }

        .wpb-notif-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .wpb-notif-grid.single {
            grid-template-columns: 1fr;
        }

        .wpb-notif-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .wpb-notif-field>label {
            font-size: 11px;
            font-weight: 600;
            color: #374151;
        }

        .wpb-notif-field .opt {
            font-weight: 400;
            color: #9ca3af;
            margin-left: 4px;
        }

        .wpb-notif-field input,
        .wpb-notif-field textarea {
            padding: 7px 9px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 12px;
            font-family: inherit;
            width: 100%;
            box-sizing: border-box;
            background: #fff;
            transition: border-color .1s, box-shadow .1s;
        }

        .wpb-notif-field input:focus,
        .wpb-notif-field textarea:focus {
            outline: none;
            border-color: #111827;
        }

        .wpb-notif-field textarea {
            resize: vertical;
            min-height: 80px;
        }

        .wpb-notif-field .hint {
            font-size: 11px;
            color: #9ca3af;
            margin: 0;
        }

        /* Variabili campo */
        .wpb-field-vars {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 5px;
        }

        .wpb-field-var-pill {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 7px;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            font-size: 10px;
            color: #374151;
            font-weight: 600;
            cursor: pointer;
            transition: background .1s, border-color .1s;
            font-family: monospace;
            white-space: nowrap;
        }

        .wpb-field-var-pill:hover {
            background: #f9fafb;
            border-color: #111827;
        }

        .wpb-notif-empty-state {
            text-align: center;
            padding: 40px 20px;
            background: #fff;
            border: 2px dashed #e0e0e0;
            border-radius: 10px;
            color: #9ca3af;
        }

        .wpb-notif-empty-state .dashicons {
            font-size: 36px;
            width: 36px;
            height: 36px;
            color: #d0d5dd;
            display: block;
            margin: 0 auto 8px;
        }

        .wpb-notif-empty-state p {
            margin: 0;
            font-size: 13px;
        }

        .wpb-notif-add-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 11px;
            background: #fff;
            border: 1px dashed #d1d5db;
            border-radius: 10px;
            color: #6b7280;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            margin-top: 10px;
            transition: background .1s;
        }

        .wpb-notif-add-btn:hover {
            background: #f9fafb;
            border-color: #111827;
            color: #111827;
        }

        /* Toast */
        #wpb-forms-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #1d2327;
            color: #fff;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            z-index: 9999;
            opacity: 0;
            transform: translateY(6px);
            transition: all .2s;
            pointer-events: none;
            border-left: 4px solid #059669;
        }

        #wpb-forms-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        #wpb-forms-toast.error {
            border-left-color: #dc2626;
        }

        /* ── Modale conferma elimina campo ── */
        #wpb-confirm-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 100000;
            background: rgba(0, 0, 0, .5);
            align-items: center;
            justify-content: center;
        }

        #wpb-confirm-modal.open {
            display: flex;
        }

        .wpb-modal-box {
            background: #fff;
            border-radius: 10px;
            padding: 28px 28px 22px;
            max-width: 380px;
            width: 90%;
            box-shadow: 0 8px 32px rgba(0, 0, 0, .22);
            text-align: center;
        }

        .wpb-modal-box .wpb-modal-icon {
            font-size: 32px;
            width: 48px;
            height: 48px;
            background: #fee2e2;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
        }

        .wpb-modal-box h3 {
            font-size: 15px;
            margin: 0 0 8px;
            color: #111827;
        }

        .wpb-modal-box p {
            font-size: 13px;
            color: #6b7280;
            margin: 0 0 20px;
            line-height: 1.5;
        }

        .wpb-modal-actions {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        .wpb-modal-btn-cancel {
            padding: 7px 20px;
            font-size: 13px;
            font-family: inherit;
            font-weight: 600;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            cursor: pointer;
            color: #111827;
            transition: border-color .1s;
        }

        .wpb-modal-btn-cancel:hover {
            border-color: #111827;
        }

        .wpb-modal-btn-confirm {
            padding: 7px 20px;
            font-size: 13px;
            font-family: inherit;
            font-weight: 600;
            background: #d63638;
            border: 1px solid #d63638;
            border-radius: 8px;
            cursor: pointer;
            color: #fff;
            transition: background .1s;
        }

        .wpb-modal-btn-confirm:hover {
            background: #b32d2e;
            border-color: #b32d2e;
        }

        #wpb-tab-notifiche {
            display: flex;
            column-gap: 30px;
            flex-direction: row-reverse;
        }

        .email-style-settings {
            height: 100%;
        }

        .email-notifications-list {
            width: 100%;
        }

        #wpb-tab-settings>div {
            max-width: none !important;
        }

        #wpb-reg-content {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
        }


        /* Small button variants */
        .wpb-frm-btn-sm-ghost {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 12px;
            font-size: 12.5px;
            font-weight: 600;
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #e5e7eb;
            border-radius: 7px;
            cursor: pointer;
            text-decoration: none !important;
            font-family: inherit;
            transition: background .15s;
            line-height: 1.4;
        }

        .wpb-frm-btn-sm-ghost:hover {
            background: #e5e7eb;
            color: #111827;
        }

        .wpb-frm-btn-sm-danger {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 12px;
            font-size: 12.5px;
            font-weight: 600;
            background: #fff;
            color: #dc2626;
            border: 1px solid #fca5a5;
            border-radius: 7px;
            cursor: pointer;
            text-decoration: none !important;
            font-family: inherit;
            transition: background .15s;
            line-height: 1.4;
        }

        .wpb-frm-btn-sm-danger:hover {
            background: #fee2e2;
        }

        /* ── WP Barrio Forms v7 — button styles ── */
        .wpb-frm-btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 600;
            background: #111827;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            transition: opacity .15s;
            text-decoration: none;
        }

        .wpb-frm-btn-primary:hover {
            opacity: .85;
            color: #fff;
        }

        .wpb-frm-btn-primary:disabled {
            opacity: .45;
            cursor: not-allowed;
        }
    </style>

    <div id="wpb-forms-wrap" class="wrap<?php echo ($is_translation_mode ?? false) ? ' wpb-translation-mode' : ''; ?>">
        <?php
        $mj_global = get_option('wpb_forms_mailjet', []);
        $mj_missing = empty(trim($mj_global['api_key'] ?? '')) || empty(trim($mj_global['secret_key'] ?? ''));
        if ($mj_missing): ?>
            <div
                style="background:#fffbeb;border:1px solid #fde68a;border-left:4px solid #d97706;border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:flex-start;gap:12px;font-size:13px;color:#92400e;">
                <span class="dashicons dashicons-warning" style="color:#dba617;font-size:18px;width:18px;height:18px;"></span>
                <span>Le credenziali Mailjet non sono configurate.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-forms&tab=settings')); ?>"
                        style="color:#6e4e00;font-weight:600;">Vai alle impostazioni &rarr;</a></span>
            </div>
        <?php endif; ?>
        <style>
            .wpb-frm-header {
                display: flex;
                align-items: center;
                gap: 14px;
                margin-bottom: 8px;
            }

            .wpb-frm-header-icon {
                width: 40px;
                height: 40px;
                background: #111827;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .wpb-frm-header-icon .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
                color: #fff;
            }

            .wpb-frm-header h1 {
                font-size: 22px !important;
                font-weight: 700 !important;
                color: #111827 !important;
                margin: 0 !important;
                padding: 0 !important;
                line-height: 1.2 !important;
            }

            .wpb-frm-breadcrumb {
                font-size: 14px;
                font-weight: 400;
                color: #9ca3af;
                margin-left: 4px;
            }

            .wpb-frm-sub {
                font-size: 13px;
                color: #6b7280;
                margin: 2px 0 0;
            }

            .wpb-frm-divider {
                border: none;
                border-top: 1px solid #f3f4f6;
                margin: 14px 0 20px;
            }
        </style>
        <div class="wpb-frm-header">
            <div class="wpb-frm-header-icon"><span class="dashicons dashicons-feedback"></span></div>
            <div>
                <h1>WP Barrio <span class="wpb-frm-breadcrumb">/ Forms</span></h1>
                <p class="wpb-frm-sub">Builder drag &amp; drop per form di contatto</p>
            </div>
        </div>
        <hr class="wpb-frm-divider">

        <?php if ($tab === 'list' || $tab === 'settings'): ?>
            <?php if ($tab === 'list'): ?>
                <div style="margin-bottom:16px;"><a
                        href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-forms&tab=builder')); ?>"
                        class="wpb-btn wpb-btn-primary wpb-btn-sm">+ Nuovo form</a></div>
            <?php endif; ?>
            <div class="wpb-tabs">
                <a href="?page=wp-barrio-forms" class="wpb-tab <?php echo $tab === 'list' ? 'active' : ''; ?>"><span
                        class="dashicons dashicons-feedback"></span> Form</a>
                <a href="?page=wp-barrio-forms&tab=settings"
                    class="wpb-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>"><span
                        class="dashicons dashicons-admin-settings"></span> Impostazioni</a>
            </div>
        <?php endif; ?>

        <?php /* ══════ IMPOSTAZIONI GLOBALI ══════ */ if ($tab === 'settings'):
            $mj_opts = get_option('wpb_forms_mailjet', []);
            ?>
            <div style="max-width:600px;">
                <div
                    style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.05);">
                    <h3
                        style="margin:0 0 4px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#50575e;display:flex;align-items:center;gap:8px;">
                        <svg width="18" height="18" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"
                            style="flex-shrink:0;">
                            <rect width="32" height="32" rx="6" fill="#FF4B32" />
                            <path d="M7 10h18v2H7zm0 5h18v2H7zm0 5h12v2H7z" fill="#fff" />
                        </svg>
                        Mailjet
                    </h3>
                    <p style="margin:0 0 16px;font-size:12px;color:#a7aaad;">Se impostati, le email di tutti i form vengono
                        inviate tramite le API Mailjet anziché tramite <code>wp_mail</code>. Lascia vuoti per usare il sistema
                        di invio standard di WordPress.</p>
                    <table class="form-table" style="max-width:100%;">
                        <tbody>
                            <tr>
                                <th style="width:220px;"><label for="wpb-global-mj-api-key">API Key (pubblica)</label></th>
                                <td>
                                    <input type="text" id="wpb-global-mj-api-key" class="large-text"
                                        value="<?php echo esc_attr($mj_opts['api_key'] ?? ''); ?>"
                                        placeholder="es. a1b2c3d4e5f6..." autocomplete="off" spellcheck="false">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wpb-global-mj-secret-key">Secret Key (privata)</label></th>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <input type="password" id="wpb-global-mj-secret-key" class="large-text"
                                            value="<?php echo esc_attr($mj_opts['secret_key'] ?? ''); ?>"
                                            placeholder="es. 9z8y7x6w..." autocomplete="new-password" spellcheck="false">
                                        <button type="button"
                                            style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:7px;padding:6px 12px;cursor:pointer;color:#374151;font-size:12px;white-space:nowrap;font-family:inherit;"
                                            onclick="(function(){var i=document.getElementById('wpb-global-mj-secret-key');i.type=i.type==='password'?'text':'password';})();">Mostra</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p style="margin:16px 0 0;">
                        <button type="button" class="wpb-frm-btn-primary" id="wpb-btn-save-global">
                            Salva impostazioni
                        </button>
                        <span id="wpb-global-saved-msg" style="display:none;margin-left:10px;color:#059669;font-size:13px;">✓
                            Salvato</span>
                    </p>
                </div>

                <!-- MailUp card -->
                <div
                    style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,.05);">
                    <h3
                        style="margin:0 0 4px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#50575e;display:flex;align-items:center;gap:8px;">
                        <svg width="18" height="18" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"
                            style="flex-shrink:0;">
                            <rect width="32" height="32" rx="6" fill="#00529C" />
                            <path d="M7 8h18v3H7zm0 6.5h18v3H7zm0 6.5h11v3H7z" fill="#fff" />
                            <circle cx="24" cy="22" r="4" fill="#F7A600" />
                        </svg>
                        MailUp
                    </h3>
                    <p style="margin:0 0 16px;font-size:12px;color:#a7aaad;">Integrazione MailUp tramite xmlSubscribe. Nessuna
                        credenziale richiesta.</p>
                    <table class="form-table" style="max-width:100%;">
                        <tbody>
                            <tr>
                                <th><label for="">IP Corrente</label></th>
                                <td>
                                    <p>
                                        <?php
                                        $ip = file_get_contents('https://api4.ipify.org'); // forza IPv4
                                        echo $ip;
                                        ?>
                                        <br>
                                        <span style="margin:0 0 16px;font-size:12px;color:#a7aaad;">Inserisci questo indirizzo
                                            IP su MailUp per limitare le richieste a questo sito.</span>
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p style="margin:16px 0 0;">
                        <button type="button" class="wpb-frm-btn-primary" id="wpb-btn-save-global-mu">
                            Salva impostazioni MailUp
                        </button>
                        <span id="wpb-global-saved-msg-mu" style="display:none;margin-left:10px;color:#059669;font-size:13px;">✓
                            Salvato</span>
                    </p>
                </div>
            </div>
            <script>
                (function () {
                    var btn = document.getElementById('wpb-btn-save-global');
                    var msg = document.getElementById('wpb-global-saved-msg');
                    btn.addEventListener('click', function () {
                        btn.disabled = true;
                        btn.textContent = 'Salvataggio…';
                        var fd = new URLSearchParams({
                            action: 'wpb_forms_save_global_settings',
                            nonce: '<?php echo esc_js($nonce); ?>',
                            mailjet_api_key: document.getElementById('wpb-global-mj-api-key').value.trim(),
                            mailjet_secret_key: document.getElementById('wpb-global-mj-secret-key').value.trim()
                        });
                        fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                            method: 'POST',
                            body: fd
                        }).then(function (r) { return r.json(); }).then(function (res) {
                            if (res.success) {
                                msg.style.display = 'inline';
                                setTimeout(function () { msg.style.display = 'none'; }, 3000);
                            } else {
                                alert(res.data || 'Errore.');
                            }
                        }).catch(function () { alert('Errore di rete.'); }).finally(function () {
                            btn.disabled = false;
                            btn.textContent = 'Salva impostazioni';
                        });
                    });

                    // MailUp save button
                    var btnMu = document.getElementById('wpb-btn-save-global-mu');
                    var msgMu = document.getElementById('wpb-global-saved-msg-mu');
                    btnMu.addEventListener('click', function () {
                        btnMu.disabled = true;
                        btnMu.textContent = 'Salvataggio…';
                        var fd = new URLSearchParams({
                            action: 'wpb_forms_save_global_settings',
                            nonce: '<?php echo esc_js($nonce); ?>',
                            mailjet_api_key: document.getElementById('wpb-global-mj-api-key').value.trim(),
                            mailjet_secret_key: document.getElementById('wpb-global-mj-secret-key').value.trim()
                        });
                        fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                            method: 'POST',
                            body: fd
                        }).then(function (r) { return r.json(); }).then(function (res) {
                            if (res.success) {
                                msgMu.style.display = 'inline';
                                setTimeout(function () { msgMu.style.display = 'none'; }, 3000);
                            } else {
                                alert(res.data || 'Errore.');
                            }
                        }).catch(function () { alert('Errore di rete.'); }).finally(function () {
                            btnMu.disabled = false;
                            btnMu.textContent = 'Salva impostazioni MailUp';
                        });
                    });
                })();
            </script>

        <?php /* ══════ LISTA ══════ */ elseif ($tab === 'list'): ?>

            <div class="wpb-forms-table-wrap">
                <?php if (empty($forms)): ?>
                    <div class="wpb-forms-empty">
                        <span class="dashicons dashicons-feedback"></span>
                        <p style="font-size:14px;margin:10px 0 6px;font-weight:600;">Nessun form ancora</p>
                        <p style="font-size:13px;color:#a7aaad;margin:0 0 16px;">Crea il tuo primo form di contatto in pochi click.
                        </p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-forms&tab=builder')); ?>"
                            class="wpb-frm-btn-primary">+ Crea il primo form</a>
                    </div>
                <?php else: ?>
                    <table class="wpb-forms-table">
                        <thead>
                            <tr>
                                <th style="width:40px">#</th>
                                <th>Nome form</th>
                                <th>Registrazioni</th>
                                <th>Creato</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($forms as $f):
                                $edit_url = admin_url('admin.php?page=wp-barrio-forms&tab=builder&edit_id=' . $f->id);
                                $subs_url = admin_url('admin.php?page=wp-barrio-submissions&view_id=' . $f->id);
                                ?>
                                <tr data-form-id="<?php echo intval($f->id); ?>">
                                    <td style="color:#a7aaad;font-size:11px;"><?php echo intval($f->id); ?></td>
                                    <td>
                                        <strong>
                                            <a href="<?php echo esc_url($edit_url); ?>"
                                                style="text-decoration:none;color:#111827;font-weight:600;">
                                                <?php echo esc_html($f->title); ?>
                                            </a>
                                        </strong>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url($subs_url); ?>" style="text-decoration:none;">
                                            <span class="wpb-sub-count <?php echo $f->submissions > 0 ? 'has-subs' : ''; ?>">
                                                <?php echo intval($f->submissions); ?> registrazioni
                                            </span>
                                        </a>
                                    </td>
                                    <td style="font-size:12px;color:#646970;">
                                        <?php echo date_i18n('d/m/Y', strtotime($f->created_at)); ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url($edit_url); ?>" class="wpb-frm-btn-sm-ghost">Modifica</a>
                                        <button class="wpb-frm-btn-sm-danger wpb-btn-delete-form"
                                            data-id="<?php echo intval($f->id); ?>"
                                            data-title="<?php echo esc_attr($f->title); ?>">Elimina</button>
                                    </td>
                                </tr>
                                <?php if ($ml_active): ?>
                                    <tr class="wpb-form-langs-row">
                                        <td colspan="5">
                                            <div class="wpb-form-langs">
                                                <span class="wpb-form-langs-label">Lingue:</span>
                                                <span class="wpb-lang-pill primary">
                                                    <span class="dashicons dashicons-yes"
                                                        style="font-size:11px;width:11px;height:11px;"></span>
                                                    <?php echo esc_html($ml_languages[$ml_primary] ?? strtoupper($ml_primary)); ?>
                                                </span>
                                                <?php foreach ($ml_trans as $lc):
                                                    $ll = $ml_languages[$lc] ?? strtoupper($lc);
                                                    $has_t = (bool) wpb_forms_get_translation((int) $f->id, $lc);
                                                    $edit_trl = admin_url('admin.php?page=wp-barrio-forms&tab=builder&edit_id=' . $f->id . '&lang=' . $lc);
                                                    ?>
                                                    <?php if ($has_t): ?>
                                                        <a href="<?php echo esc_url($edit_trl); ?>" class="wpb-lang-pill translated"
                                                            title="Modifica traduzione in <?php echo esc_attr($ll); ?>">
                                                            <span class="dashicons dashicons-yes"
                                                                style="font-size:11px;width:11px;height:11px;"></span>
                                                            <?php echo esc_html($ll); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <button type="button" class="wpb-lang-pill missing wpb-form-lang-init-btn"
                                                            data-form-id="<?php echo intval($f->id); ?>" data-lang="<?php echo esc_attr($lc); ?>"
                                                            data-lang-label="<?php echo esc_attr($ll); ?>"
                                                            title="Inizializza traduzione in <?php echo esc_attr($ll); ?>">
                                                            <span class="wpb-lang-add">+</span>
                                                            <?php echo esc_html($ll); ?>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if ($ml_active): ?>
                <script>
                    (function () {
                        const NONCE = '<?php echo esc_js($nonce); ?>';
                        const AJAX = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                        const URL_EDIT_BASE = '<?php echo esc_js(admin_url('admin.php?page=wp-barrio-forms&tab=builder&edit_id=')); ?>';

                        document.querySelectorAll('.wpb-form-lang-init-btn').forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                var formId = this.dataset.formId;
                                var lang = this.dataset.lang;
                                var langLabel = this.dataset.langLabel;
                                var pill = this;

                                console.log('[WPB] init click', { formId: formId, lang: lang, nonce: NONCE, ajax: AJAX });

                                pill.classList.add('loading');
                                pill.disabled = true;

                                var params = new URLSearchParams({
                                    action: 'wpb_forms_init_translation',
                                    nonce: NONCE,
                                    form_id: formId,
                                    lang: lang,
                                });

                                fetch(AJAX, { method: 'POST', body: params })
                                    .then(function (r) {
                                        console.log('[WPB] HTTP status:', r.status);
                                        return r.text();
                                    })
                                    .then(function (text) {
                                        console.log('[WPB] raw response:', text);
                                        var res;
                                        try { res = JSON.parse(text); } catch (e) {
                                            alert('Risposta non JSON (status potrebbe essere 400): ' + text.substring(0, 300));
                                            pill.classList.remove('loading'); pill.disabled = false; return;
                                        }
                                        if (res.success) {
                                            var link = document.createElement('a');
                                            link.href = URL_EDIT_BASE + formId + '&lang=' + lang;
                                            link.className = 'wpb-lang-pill translated';
                                            link.title = 'Modifica traduzione in ' + langLabel;
                                            link.innerHTML = '<span class="dashicons dashicons-yes" style="font-size:11px;width:11px;height:11px;"></span> ' + langLabel;
                                            pill.replaceWith(link);
                                        } else {
                                            alert(res.data || 'Errore sconosciuto');
                                            pill.classList.remove('loading'); pill.disabled = false;
                                        }
                                    })
                                    .catch(function (err) {
                                        console.error('[WPB] fetch error:', err);
                                        alert('Fetch error: ' + err.message);
                                        pill.classList.remove('loading'); pill.disabled = false;
                                    });
                            });
                        });
                    })();
                </script>
            <?php endif; ?>

        <?php /* ══════ BUILDER ══════ */ elseif ($tab === 'builder'): ?>

            <?php
            // Lingua selezionata nell'editor (da querystring, se valida e traduzione esistente)
            $qs_lang = isset($_GET['lang']) ? sanitize_key($_GET['lang']) : '';
            $editor_lang = $ml_primary; // default: lingua principale
            $is_translation_mode = false;

            if ($ml_active && $edit_id && $qs_lang && $qs_lang !== $ml_primary) {
                if (
                    in_array($qs_lang, $ml_trans, true) &&
                    wpb_forms_get_translation($edit_id, $qs_lang)
                ) {
                    $editor_lang = $qs_lang;
                    $is_translation_mode = true;
                }
            }
            ?>

            <div class="wpb-builder-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-forms')); ?>" class="wpb-frm-btn-sm-ghost">←
                    Tutti i form</a>
                <input type="text" id="wpb-form-title" class="wpb-builder-title-input"
                    value="<?php echo esc_attr($edit_form ? $edit_form->title : ''); ?>" placeholder="Nome del form…" <?php echo $is_translation_mode ? 'disabled style="opacity:.5;"' : ''; ?>>
                <button class="wpb-frm-btn-primary" id="wpb-btn-save-form">
                    <?php echo $is_translation_mode ? 'Salva traduzione' : 'Salva form'; ?>
                </button>
            </div>

            <?php if ($ml_active && $edit_id): ?>
                <?php
                // Costruisce la lista lingue disponibili (principale + traduzioni inizializzate)
                $editor_langs = [$ml_primary => ($ml_languages[$ml_primary] ?? $ml_primary) . ' (principale)'];
                foreach ($ml_trans as $lc) {
                    if (wpb_forms_get_translation($edit_id, $lc)) {
                        $editor_langs[$lc] = $ml_languages[$lc] ?? strtoupper($lc);
                    }
                }
                ?>
                <div
                    style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:10px 14px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:9px;">
                    <span class="dashicons dashicons-translation"
                        style="font-size:16px;width:16px;height:16px;color:#6b7280;flex-shrink:0;"></span>
                    <label for="wpb-form-lang-select" style="font-size:13px;font-weight:600;color:#1d2327;margin:0;">Lingua:</label>
                    <select id="wpb-form-lang-select" style="min-width:180px;font-size:13px;">
                        <?php foreach ($editor_langs as $lc => $label): ?>
                            <option value="<?php echo esc_attr($lc); ?>"
                                data-is-primary="<?php echo $lc === $ml_primary ? '1' : '0'; ?>" <?php selected($editor_lang, $lc); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($is_translation_mode): ?>
                        <span
                            style="font-size:12px;color:#1e40af;background:#eff6ff;padding:4px 10px;border-radius:20px;border:1px solid #bfdbfe;">
                            Stai modificando la traduzione in
                            <strong><?php echo esc_html($ml_languages[$editor_lang] ?? $editor_lang); ?></strong>.
                            Struttura, logica e impostazioni ereditate dall'originale.
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="wpb-builder-tabs">
                <button class="wpb-btab active" data-tab="fields">Campi</button>
                <button class="wpb-btab" data-tab="settings">Impostazioni</button>
                <button class="wpb-btab" data-tab="notifiche">Notifiche</button>
                <?php if ($edit_id): ?>

                <?php endif; ?>
            </div>

            <!-- TAB CAMPI -->
            <div id="wpb-tab-fields">
                <div class="wpb-builder-layout">

                    <!-- Sx: tipi campo / disclaimer traduzione -->
                    <?php if ($is_translation_mode): ?>
                        <div class="wpb-panel-fields"
                            style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:18px 16px;display:flex;flex-direction:column;gap:0;">
                            <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:14px;">
                                <span class="dashicons dashicons-translation"
                                    style="font-size:20px;width:20px;height:20px;color:#6b7280;flex-shrink:0;margin-top:2px;"></span>
                                <strong style="font-size:13px;color:#1d2327;">Modalità traduzione</strong>
                            </div>
                            <p style="font-size:12px;color:#0a4b78;margin:0 0 10px;line-height:1.6;">
                                Stai modificando la versione in
                                <strong><?php echo esc_html($ml_languages[$editor_lang] ?? $editor_lang); ?></strong>.
                            </p>
                            <p style="font-size:12px;color:#0a4b78;margin:0 0 6px;font-weight:600;">Puoi modificare:</p>
                            <ul style="font-size:12px;color:#0a4b78;margin:0 0 12px;padding-left:16px;line-height:1.8;">
                                <li>Etichetta (label)</li>
                                <li>Testo segnaposto (placeholder)</li>
                                <li>Opzioni di select, radio, checkbox</li>
                                <li>Messaggio di errore</li>
                            </ul>
                            <p style="font-size:12px;color:#646970;margin:0 0 6px;font-weight:600;">Non puoi modificare:</p>
                            <ul style="font-size:12px;color:#646970;margin:0;padding-left:16px;line-height:1.8;">
                                <li>Struttura e ordine dei campi</li>
                                <li>Tipo e nome (slug)</li>
                                <li>Obbligatorietà e logica condizionale</li>
                                <li>Validazione (min/max/formato)</li>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="wpb-panel-fields">
                            <h3>Aggiungi campo</h3>
                            <?php
                            $field_types = [
                                'text' => ['icon' => '𝐓', 'label' => 'Testo'],
                                'email' => ['icon' => '✉', 'label' => 'Email'],
                                'textarea' => ['icon' => '≡', 'label' => 'Area di testo'],
                                'tel' => ['icon' => '☎', 'label' => 'Telefono'],
                                'number' => ['icon' => '#', 'label' => 'Numero'],
                                'datepicker' => ['icon' => '📅', 'label' => 'Data'],
                                'select' => ['icon' => '▼', 'label' => 'Menù a tendina'],
                                'radio' => ['icon' => '◉', 'label' => 'Scelta singola'],
                                'checkbox' => ['icon' => '☑', 'label' => 'Scelte multiple'],
                                'file' => ['icon' => '📎', 'label' => 'File'],
                                'page_break' => ['icon' => '⏎', 'label' => 'Interruzione pagina'],
                            ];
                            foreach ($field_types as $type => $data): ?>
                                <button class="wpb-field-type-btn" data-type="<?php echo esc_attr($type); ?>">
                                    <span class="wpb-ft-icon"><?php echo $data['icon']; ?></span>
                                    <?php echo esc_html($data['label']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

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

                <!-- Impostazioni generali -->
                <div
                    style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;max-width:540px;box-shadow:0 1px 3px rgba(0,0,0,.05);">
                    <h3
                        style="margin:0 0 16px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#50575e;">
                        Impostazioni generali</h3>

                    <div style="margin-bottom:20px;">
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;"
                            for="wpb-setting-submit-text">Testo pulsante invio</label>
                        <input type="text" id="wpb-setting-submit-text" class="regular-text" placeholder="Invia">
                    </div>

                    <div style="margin-bottom:20px;">
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;"
                            for="wpb-setting-display-page-info">Informazioni pagina</label>
                        <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer;font-size:13px;">
                            <input type="checkbox" id="wpb-setting-display-page-info" value="1">
                            Registra URL e titolo della pagina al momento dell'invio
                        </label>
                    </div>

                    <div>
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Conferma di invio</label>
                        <div class="wpb-after-submit-toggle" style="margin-bottom:12px;">
                            <button type="button" class="wpb-ast-btn active" data-value="message">Mostra messaggio</button>
                            <button type="button" class="wpb-ast-btn" data-value="redirect">Reindirizza a una pagina</button>
                        </div>
                        <input type="hidden" id="wpb-setting-after-submit" value="message">
                        <div id="wpb-ast-panel-message">
                            <textarea id="wpb-setting-success-msg" rows="3" class="large-text"
                                placeholder="Grazie! Il tuo messaggio è stato inviato."></textarea>
                            <p class="description" style="margin-top:4px;">Testo mostrato al visitatore dopo l'invio.</p>
                        </div>
                        <div id="wpb-ast-panel-redirect" style="display:none;">
                            <input type="url" id="wpb-setting-redirect-url" class="large-text"
                                placeholder="https://example.com/grazie">
                            <p class="description" style="margin-top:4px;">URL della pagina di ringraziamento a cui rimandare
                                l'utente.</p>
                        </div>
                    </div>
                </div>

                <!-- MailUp per-form card -->
                <div
                    style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;max-width:540px;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-top:20px;">
                    <h3
                        style="margin:0 0 4px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#50575e;display:flex;align-items:center;gap:8px;">
                        <svg width="18" height="18" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"
                            style="flex-shrink:0;">
                            <rect width="32" height="32" rx="6" fill="#00529C" />
                            <path d="M7 8h18v3H7zm0 6.5h18v3H7zm0 6.5h11v3H7z" fill="#fff" />
                            <circle cx="24" cy="22" r="4" fill="#F7A600" />
                        </svg>
                        Integrazione MailUp
                    </h3>
                    <p style="margin:0 0 20px;font-size:12px;color:#a7aaad;">Iscrivi automaticamente i contatti a una lista
                        MailUp al submit di questo form. Le credenziali API vanno configurate nelle <a
                            href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-forms&tab=settings')); ?>">Impostazioni
                            globali</a>.</p>

                    <!-- Toggle abilitazione -->
                    <div style="margin-bottom:20px;">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                            <input type="checkbox" id="wpb-setting-mailup-enabled" value="1" style="width:16px;height:16px;">
                            <span style="font-size:13px;font-weight:600;">Abilita iscrizione MailUp</span>
                        </label>
                    </div>

                    <!-- Campi extra — visibili solo se abilitato -->
                    <div id="wpb-mailup-fields-wrap" style="display:none;border-top:1px solid #f0f0f0;padding-top:20px;">

                        <!-- ID Lista + ID Gruppo: stessa riga -->
                        <div style="display:flex;gap:24px;margin-bottom:20px;flex-wrap:wrap;">
                            <div style="flex:1;min-width:120px;">
                                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"
                                    for="wpb-setting-mailup-list-id">ID Lista MailUp</label>
                                <input type="number" id="wpb-setting-mailup-list-id" style="width:100px;" class="small-text"
                                    value="1" min="1" placeholder="1">
                                <p class="description" style="margin-top:4px;">Parametro <code>List</code> dell'URL
                                    xmlSubscribe.</p>
                            </div>
                            <div style="flex:1;min-width:120px;">
                                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"
                                    for="wpb-setting-mailup-group-id">ID Gruppo MailUp</label>
                                <input type="number" id="wpb-setting-mailup-group-id" style="width:100px;" class="small-text"
                                    value="1" min="1" placeholder="1">
                                <p class="description" style="margin-top:4px;">Parametro <code>Group</code> dell'URL
                                    xmlSubscribe.</p>
                            </div>
                        </div>

                        <!-- Campo email -->
                        <div style="margin-bottom:20px;">
                            <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"
                                for="wpb-setting-mailup-email-field">Campo email del form</label>
                            <input type="text" id="wpb-setting-mailup-email-field" class="regular-text" placeholder="email">
                            <p class="description" style="margin-top:4px;">Lo <strong>slug</strong> del campo email di questo
                                form. Viene usato come indirizzo del contatto iscritto. Default: <code>email</code>.</p>
                        </div>

                        <!-- Campo opt-in newsletter -->
                        <div
                            style="margin-bottom:24px;padding:16px;background:#f9fafb;border-radius:9px;border:1px solid #e5e7eb;">
                            <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"
                                for="wpb-setting-mailup-optin-field">
                                Campo opt-in newsletter
                                <span style="font-weight:400;color:#a7aaad;"> (opzionale)</span>
                            </label>
                            <p class="description" style="margin-bottom:10px;margin-top:0;">Se specificato, l'iscrizione a
                                MailUp avviene <strong>solo se questo campo checkbox è spuntato</strong> al momento dell'invio.
                                Lascia vuoto per iscrivere sempre.</p>
                            <select id="wpb-setting-mailup-optin-field"
                                style="width:100%;max-width:340px;padding:5px 8px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;">
                                <option value="">— Iscrivi sempre —</option>
                            </select>
                            <p class="description" style="margin-top:6px;">Seleziona il campo <strong>checkbox</strong> del form
                                che funge da consenso newsletter (es. "Iscriviti alla newsletter").</p>
                        </div>

                        <!-- Mapping campi form → parametri xmlSubscribe -->
                        <div>
                            <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;">
                                Mappa campi del form su parametri MailUp
                                <span style="font-weight:400;color:#a7aaad;">(opzionale)</span>
                            </label>
                            <p class="description" style="margin-bottom:12px;">
                                Collega i campi di questo form ai parametri accettati dall'endpoint xmlSubscribe
                                (es. <code>Nome</code>, <code>Cognome</code>, <code>Telefono</code>).
                                A sinistra lo <strong>slug del campo del form</strong>, a destra il
                                <strong>nome del parametro MailUp</strong> esatto (case-sensitive).
                            </p>
                            <!-- Header colonne -->
                            <div style="display:flex;gap:8px;margin-bottom:6px;padding:0 32px 0 0;">
                                <span
                                    style="flex:1;font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase;letter-spacing:.4px;">Slug
                                    campo form</span>
                                <span
                                    style="flex:1;font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase;letter-spacing:.4px;">Parametro
                                    MailUp</span>
                                <span style="width:28px;"></span>
                            </div>
                            <div id="wpb-mailup-field-map-rows"></div>
                            <button type="button" id="wpb-mailup-add-map-row"
                                style="margin-top:8px;background:none;border:1px dashed #d1d5db;border-radius:8px;padding:6px 14px;cursor:pointer;font-size:12px;color:#6b7280;width:100%;text-align:center;font-family:inherit;">
                                + Aggiungi mappatura campo
                            </button>
                            <p class="description" style="margin-top:8px;">
                                Esempi di parametri MailUp: <code>Nome</code>, <code>Cognome</code>, <code>Telefono</code>,
                                <code>Azienda</code>.
                                Controlla i nomi esatti in <em>MailUp → Impostazioni → Profilo iscritto → Campi
                                    personalizzati</em>.
                            </p>
                        </div>

                    </div><!-- #wpb-mailup-fields-wrap -->
                </div>

            </div><!-- #wpb-tab-settings -->

            <!-- TAB NOTIFICHE -->
            <div id="wpb-tab-notifiche" style="display:none;">

                <!-- Impostazioni globali email -->
                <div class="email-style-settings"
                    style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;max-width:600px;margin-bottom:20px;">
                    <h3
                        style="margin:0 0 16px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#50575e;">
                        Stile email</h3>
                    <table class="form-table" style="max-width:100%;">
                        <tbody>
                            <tr>
                                <th style="width:220px;"><label for="wpb-setting-email-bg-color">Colore di sfondo</label></th>
                                <td style="display:flex;align-items:center;gap:10px;">
                                    <input type="color" id="wpb-setting-email-bg-color" value="#f4f4f4"
                                        style="width:44px;height:34px;padding:2px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                    <input type="text" id="wpb-setting-email-bg-color-hex" class="small-text"
                                        placeholder="#f4f4f4" maxlength="7" style="width:90px;">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wpb-setting-email-header-color">Colore header</label></th>
                                <td style="display:flex;align-items:center;gap:10px;">
                                    <input type="color" id="wpb-setting-email-header-color" value="#2271b1"
                                        style="width:44px;height:34px;padding:2px;border:1px solid #e5e7eb;border-radius:8px;cursor:pointer;">
                                    <input type="text" id="wpb-setting-email-header-color-hex" class="small-text"
                                        placeholder="#2271b1" maxlength="7" style="width:90px;">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wpb-setting-email-logo-url">URL logo</label></th>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <input type="url" id="wpb-setting-email-logo-url" class="large-text"
                                            placeholder="https://esempio.com/logo.png">
                                        <button type="button" class="wpb-frm-btn-sm-ghost" id="wpb-btn-media-logo"
                                            style="white-space:nowrap;">Scegli immagine</button>
                                    </div>
                                    <div id="wpb-logo-preview" style="margin-top:8px;display:none;">
                                        <img src="" alt="Anteprima logo"
                                            style="max-width:200px;max-height:60px;border:1px solid #e5e7eb;border-radius:8px;padding:4px;background:#fff;">
                                        <button type="button" id="wpb-btn-remove-logo"
                                            style="background:none;border:none;color:#d63638;cursor:pointer;font-size:16px;vertical-align:top;padding:0 4px;"
                                            title="Rimuovi">&times;</button>
                                    </div>
                                    <p class="description">Lascia vuoto per non mostrare il logo nell'email.</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wpb-setting-domain-email">Email dominio</label></th>
                                <td>
                                    <input type="email" id="wpb-setting-domain-email" class="large-text"
                                        placeholder="info@esempio.com">
                                    <p class="description">Indirizzo email generico del sito, mostrato nel footer legale delle
                                        email.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Lista notifiche email -->
                <div class="email-notifications-list" style="max-width:600px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
                        <div>
                            <h3
                                style="margin:0 0 2px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#50575e;">
                                Notifiche email</h3>
                            <p style="margin:0;font-size:12px;color:#a7aaad;">Configurazione destinatari per ogni invio del
                                form.</p>
                        </div>
                    </div>
                    <div id="wpb-notifications-list"></div>
                    <button type="button" class="wpb-notif-add-btn" id="wpb-btn-add-notification">
                        <span style="font-size:18px;line-height:1;">+</span> Aggiungi notifica
                    </button>
                </div>

            </div><!-- #wpb-tab-notifiche -->

            <?php if ($edit_id): ?>
                <!-- TAB REGISTRAZIONI rimosso: accessibile da wp-barrio-submissions -->
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

    <?php
    // Variabili usate nel blocco <script> — definite qui con default
    // per evitare errori quando il tab non è 'builder'
    if (!isset($editor_lang))
        $editor_lang = $ml_primary;
    if (!isset($is_translation_mode))
        $is_translation_mode = false;
    ?>
    <script>
        (function () {
            const NONCE = '<?php echo esc_js($nonce); ?>';
            const AJAX = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            const EDIT_ID = <?php echo intval($builder_form_id); ?>;

            // Dati multilingua
            const ML_ACTIVE = <?php echo wp_json_encode($ml_active); ?>;
            const ML_PRIMARY = <?php echo wp_json_encode($ml_primary); ?>;
            const ML_LANGUAGES = <?php echo wp_json_encode($ml_languages); ?>;
            const URL_EDIT_BASE = <?php echo wp_json_encode(admin_url('admin.php?page=wp-barrio-forms&tab=builder&edit_id=')); ?>;
            let currentEditorLang = <?php echo wp_json_encode($editor_lang ?? $ml_primary); ?>;
            const IS_TRANSLATION = <?php echo wp_json_encode($is_translation_mode ?? false); ?>;

            /* ── Select lingua nel builder: naviga alla versione tradotta ── */
            const langSelect = document.getElementById('wpb-form-lang-select');
            if (langSelect && ML_ACTIVE && EDIT_ID) {
                langSelect.addEventListener('change', function () {
                    const lang = this.value;
                    const isPrimary = this.options[this.selectedIndex].dataset.isPrimary === '1';
                    if (isPrimary) {
                        window.location.href = URL_EDIT_BASE + EDIT_ID;
                    } else {
                        window.location.href = URL_EDIT_BASE + EDIT_ID + '&lang=' + lang;
                    }
                });
            }

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
                    const res = await fetch(AJAX, { method: 'POST', body: new URLSearchParams({ action: 'wpb_forms_delete', nonce: NONCE, form_id: btn.dataset.id }) });
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
                    const res = await fetch(AJAX, { method: 'POST', body: new URLSearchParams({ action: 'wpb_forms_delete_submission', nonce: NONCE, submission_id: btn.dataset.id }) });
                    const data = await res.json();
                    if (data.success) {
                        if (btn.dataset.redirect) {
                            // siamo nella pagina dettaglio: torna alla lista
                            window.location.href = btn.dataset.redirect;
                        } else {
                            const row = btn.closest('tr');
                            row.style.transition = 'opacity .3s'; row.style.opacity = '0';
                            setTimeout(() => row.remove(), 300);
                            toast('Risposta eliminata.');
                        }
                    } else { toast(data.data || 'Errore.', 'error'); btn.disabled = false; }
                });
            });

            /* ════════════════════════════
               BUILDER
               ════════════════════════════ */
            if (!document.getElementById('wpb-drop-zone')) return;

            let fields = <?php echo wp_json_encode(json_decode($saved_fields) ?: []); ?>;
            let settings = <?php echo wp_json_encode(json_decode($saved_settings) ?: new stdClass()); ?>;
            if (!Array.isArray(fields)) fields = [];
            if (typeof settings !== 'object' || !settings) settings = {};

            // Se siamo in translation mode, merge con i dati traducibili della traduzione
            <?php if ($is_translation_mode && $edit_id && $editor_lang !== $ml_primary):
                $trans_row = wpb_forms_get_translation($edit_id, $editor_lang);
                if ($trans_row):
                    $fi18n_data = json_decode($trans_row->fields_i18n, true) ?: [];
                    $si18n_data = json_decode($trans_row->settings_i18n, true) ?: [];
                    ?>
                        (function () {
                            const fi18n = <?php echo wp_json_encode($fi18n_data); ?>;
                            const si18n = <?php echo wp_json_encode($si18n_data); ?>;
                            // Merge fields: sovrascrive solo label, placeholder, options, error_message
                            fields = fields.map((f, i) => {
                                if (!fi18n[i]) return f;
                                const t = fi18n[i];
                                const merged = { ...f };
                                if (t.label !== undefined && t.label !== '') merged.label = t.label;
                                if (t.placeholder !== undefined && t.placeholder !== '') merged.placeholder = t.placeholder;
                                if (t.error_message !== undefined && t.error_message !== '') merged.error_message = t.error_message;
                                if (t.options && Array.isArray(t.options) && t.options.length) merged.options = t.options;
                                return merged;
                            });
                            // Merge settings: sovrascrive solo i campi traducibili
                            ['submit_label', 'redirect_url', 'success_message', 'mailup_list_id', 'mailup_group_id'].forEach(k => {
                                if (si18n[k] !== undefined && si18n[k] !== '') settings[k] = si18n[k];
                            });
                            if (si18n.notifications && Array.isArray(si18n.notifications)) {
                                const origNotifs = settings.notifications || [];
                                settings.notifications = origNotifs.map((n, ni) => {
                                    const nt = si18n.notifications[ni];
                                    if (!nt) return n;
                                    const merged = { ...n };
                                    ['subject', 'body', 'reply_to', 'from_name', 'to'].forEach(k => {
                                        if (nt[k] !== undefined && nt[k] !== '') merged[k] = nt[k];
                                    });
                                    return merged;
                                });
                            }
                        })();
                <?php endif; endif; ?>

            // I campi salvati non hanno id interno — lo generiamo in memoria dal name
            fields = fields.map(f => ({ ...f, id: f.id || ('_' + (f.name || Math.random().toString(36).slice(2))) }));

            let selectedFieldId = null;
            let dragSrcIdx = null;
            let notificationsData = [];

            const dropZone = document.getElementById('wpb-drop-zone');
            const emptyState = document.getElementById('wpb-canvas-empty');
            const propsInner = document.getElementById('wpb-props-inner');

            /* ── Impostazioni UI ── */
            function initSettings() {
                const s = settings;
                const el = id => document.getElementById(id);
                if (el('wpb-setting-submit-text')) el('wpb-setting-submit-text').value = s.submit_text || '';
                if (el('wpb-setting-success-msg')) el('wpb-setting-success-msg').value = s.success_message || '';
                if (el('wpb-setting-redirect-url')) el('wpb-setting-redirect-url').value = s.redirect_url || '';
                const dpi = el('wpb-setting-display-page-info');
                if (dpi) dpi.checked = !!(s.display_page_info);
                // Stile email
                const bgColor = s.email_bg_color || '#f4f4f4';
                const hdColor = s.email_header_color || '#2271b1';
                if (el('wpb-setting-email-bg-color')) el('wpb-setting-email-bg-color').value = bgColor;
                if (el('wpb-setting-email-bg-color-hex')) el('wpb-setting-email-bg-color-hex').value = bgColor;
                if (el('wpb-setting-email-header-color')) el('wpb-setting-email-header-color').value = hdColor;
                if (el('wpb-setting-email-header-color-hex')) el('wpb-setting-email-header-color-hex').value = hdColor;
                if (el('wpb-setting-email-logo-url')) {
                    el('wpb-setting-email-logo-url').value = s.email_logo_url || '';
                    el('wpb-setting-email-logo-url').dispatchEvent(new Event('input'));
                }
                if (el('wpb-setting-domain-email')) el('wpb-setting-domain-email').value = s.domain_email || '';
                // Notifiche
                notificationsData = Array.isArray(s.notifications) ? s.notifications.map(n => ({ ...n })) : [];
                renderNotifications();
                const after = s.after_submit || 'message';
                setAfterPanel(after);
                // MailUp
                const muEnabled = el('wpb-setting-mailup-enabled');
                if (muEnabled) {
                    muEnabled.checked = !!(s.mailup_enabled);
                    toggleMailupFields(muEnabled.checked);
                }
                if (el('wpb-setting-mailup-list-id')) el('wpb-setting-mailup-list-id').value = s.mailup_list_id || '1';
                if (el('wpb-setting-mailup-group-id')) el('wpb-setting-mailup-group-id').value = s.mailup_group_id || '1';
                if (el('wpb-setting-mailup-email-field')) el('wpb-setting-mailup-email-field').value = s.mailup_email_field || '';
                // Opt-in select: popola con i campi checkbox presenti nel form
                const optinSel = el('wpb-setting-mailup-optin-field');
                if (optinSel) {
                    // Rimuovi opzioni precedenti tranne la prima (— Iscrivi sempre —)
                    while (optinSel.options.length > 1) optinSel.remove(1);
                    fields.filter(f => f.type === 'checkbox').forEach(f => {
                        const opt = document.createElement('option');
                        opt.value = f.name || '';
                        opt.textContent = (f.label || f.name || f.id) + ' [' + (f.name || '') + ']';
                        optinSel.appendChild(opt);
                    });
                    optinSel.value = s.mailup_optin_field || '';
                }
                // Field mapping
                mailupFieldMap = Array.isArray(s.mailup_field_map) ? s.mailup_field_map.map(r => ({ form_field: r.form_field || '', mailup_field_name: r.mailup_field_name || '' })) : [];
                renderMailupMapRows();
            }

            function setAfterPanel(val) {
                const msgP = document.getElementById('wpb-ast-panel-message');
                const redP = document.getElementById('wpb-ast-panel-redirect');
                const hid = document.getElementById('wpb-setting-after-submit');
                if (msgP) msgP.style.display = val === 'message' ? '' : 'none';
                if (redP) redP.style.display = val === 'redirect' ? '' : 'none';
                if (hid) hid.value = val;
                // Aggiorna i bottoni del toggle "Conferma di invio"
                document.querySelectorAll('.wpb-ast-btn').forEach(b => {
                    b.classList.toggle('active', b.dataset.value === val);
                });
            }

            document.querySelectorAll('.wpb-ast-btn').forEach(b => b.addEventListener('click', () => setAfterPanel(b.dataset.value)));

            function toggleMailupFields(show) {
                const wrap = document.getElementById('wpb-mailup-fields-wrap');
                if (wrap) wrap.style.display = show ? '' : 'none';
            }
            const muCb = document.getElementById('wpb-setting-mailup-enabled');
            if (muCb) muCb.addEventListener('change', () => toggleMailupFields(muCb.checked));

            // ── Field mapping rows ──────────────────────────────────────────
            let mailupFieldMap = []; // [{form_field, mailup_field_name}]

            function renderMailupMapRows() {
                const container = document.getElementById('wpb-mailup-field-map-rows');
                if (!container) return;
                container.innerHTML = '';
                mailupFieldMap.forEach((row, idx) => {
                    const div = document.createElement('div');
                    div.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:8px;';
                    div.innerHTML = `
                        <input type="text" placeholder="slug campo form (es. telefono)"
                            value="${esc(row.form_field || '')}"
                            data-idx="${idx}" data-key="form_field"
                            style="flex:1;padding:5px 8px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;">
                        <span style="color:#a7aaad;font-size:18px;line-height:1;flex-shrink:0;">→</span>
                        <input type="text" placeholder="es. Nome"
                            value="${esc(row.mailup_field_name || '')}"
                            data-idx="${idx}" data-key="mailup_field_name"
                            style="flex:1;padding:5px 8px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;">
                        <button type="button" data-idx="${idx}"
                            style="background:none;border:none;color:#d63638;cursor:pointer;font-size:18px;line-height:1;padding:0 4px;flex-shrink:0;"
                            title="Rimuovi">×</button>`;
                    // Input change handlers
                    div.querySelectorAll('input[data-key]').forEach(inp => {
                        inp.addEventListener('input', e => {
                            const i = parseInt(e.target.dataset.idx, 10);
                            mailupFieldMap[i][e.target.dataset.key] = e.target.value;
                        });
                    });
                    // Remove button
                    div.querySelector('button[data-idx]').addEventListener('click', e => {
                        const i = parseInt(e.currentTarget.dataset.idx, 10);
                        mailupFieldMap.splice(i, 1);
                        renderMailupMapRows();
                    });
                    container.appendChild(div);
                });
            }

            const addMapBtn = document.getElementById('wpb-mailup-add-map-row');
            if (addMapBtn) addMapBtn.addEventListener('click', () => {
                mailupFieldMap.push({ form_field: '', mailup_field_name: '' });
                renderMailupMapRows();
            });

            initSettings();

            function readSettings() {
                const el = id => document.getElementById(id);
                const dpi = el('wpb-setting-display-page-info');
                return {
                    submit_text: el('wpb-setting-submit-text') ? el('wpb-setting-submit-text').value.trim() : (settings.submit_text || 'Invia'),
                    success_message: el('wpb-setting-success-msg') ? el('wpb-setting-success-msg').value.trim() : (settings.success_message || ''),
                    after_submit: el('wpb-setting-after-submit') ? el('wpb-setting-after-submit').value : (settings.after_submit || 'message'),
                    redirect_url: el('wpb-setting-redirect-url') ? el('wpb-setting-redirect-url').value.trim() : (settings.redirect_url || ''),
                    email_bg_color: el('wpb-setting-email-bg-color-hex') ? el('wpb-setting-email-bg-color-hex').value.trim() : (settings.email_bg_color || '#f4f4f4'),
                    email_header_color: el('wpb-setting-email-header-color-hex') ? el('wpb-setting-email-header-color-hex').value.trim() : (settings.email_header_color || '#2271b1'),
                    email_logo_url: el('wpb-setting-email-logo-url') ? el('wpb-setting-email-logo-url').value.trim() : (settings.email_logo_url || ''),
                    domain_email: el('wpb-setting-domain-email') ? el('wpb-setting-domain-email').value.trim() : (settings.domain_email || ''),
                    notifications: notificationsData
                        .filter(n => n.to_email && n.to_email.trim())
                        .map(({ _open, ...rest }) => rest),
                    display_page_info: dpi ? dpi.checked : !!(settings.display_page_info),
                    // MailUp
                    mailup_enabled: el('wpb-setting-mailup-enabled') ? el('wpb-setting-mailup-enabled').checked : !!(settings.mailup_enabled),
                    mailup_list_id: el('wpb-setting-mailup-list-id') ? parseInt(el('wpb-setting-mailup-list-id').value, 10) || 1 : (settings.mailup_list_id || 1),
                    mailup_group_id: el('wpb-setting-mailup-group-id') ? parseInt(el('wpb-setting-mailup-group-id').value, 10) || 1 : (settings.mailup_group_id || 1),
                    mailup_email_field: el('wpb-setting-mailup-email-field') ? el('wpb-setting-mailup-email-field').value.trim() : (settings.mailup_email_field || 'email'),
                    mailup_optin_field: el('wpb-setting-mailup-optin-field') ? el('wpb-setting-mailup-optin-field').value : (settings.mailup_optin_field || ''),
                    mailup_field_map: mailupFieldMap.filter(r => r.form_field && r.mailup_field_name),
                };
            }

            /* ── Notifiche ── */
            function renderNotifications() {
                const list = document.getElementById('wpb-notifications-list');
                if (!list) return;

                if (!notificationsData.length) {
                    list.innerHTML = `
                                <div class="wpb-notif-empty-state">
                                    <span class="dashicons dashicons-email-alt"></span>
                                    <p>Nessuna notifica configurata.<br>
                                       <span style="font-size:11px;">Aggiungi una notifica per ricevere un'email ad ogni invio.</span>
                                    </p>
                                </div>`;
                    return;
                }

                list.innerHTML = notificationsData.map((n, i) => {
                    const isOpen = n._open !== false;
                    const headerTitle = n.notification_name || n.to_email || `Notifica ${i + 1}`;
                    const fromLabel = n.from_name ? esc(n.from_name) : (n.from_email ? esc(n.from_email) : 'Mittente non impostato');

                    // Campi di tipo email del form (per destinatario) — escludi page_break
                    const ob = '{{';
                    const cb = '}}';
                    const realFields = fields.filter(f => f.type !== 'page_break');
                    const emailFields = realFields.filter(f => f.type === 'email');
                    const emailPills = emailFields.length
                        ? '<div class="wpb-field-vars" style="margin-top:5px;">' + emailFields.map(f =>
                            '<button type="button" class="wpb-field-var-pill wpb-email-field-pill"' +
                            ' data-value="' + ob + esc(f.name) + cb + '"' +
                            ' title="Usa il campo &quot;' + esc(f.label || f.name) + '&quot; come destinatario">' +
                            '⟵ ' + esc(f.label || f.name) +
                            '</button>'
                        ).join('') + '</div>'
                        : '';

                    // Pill variabili (per campi testo)
                    const pills = realFields.length
                        ? '<div class="wpb-field-vars">' + realFields.map(f =>
                            '<button type="button" class="wpb-field-var-pill"' +
                            ' data-var="' + ob + esc(f.name) + cb + '"' +
                            ' title="Inserisci il valore di &quot;' + esc(f.label || f.name) + '&quot;">' +
                            ob + esc(f.name) + cb +
                            '</button>'
                        ).join('') + '</div>'
                        : '<p style="font-size:11px;color:#a7aaad;margin:4px 0 0;">Nessun campo disponibile — aggiungi campi al form.</p>';

                    // Modalità corpo messaggio
                    const msgMode = n.message_mode || 'auto';
                    const isCustom = msgMode === 'custom';

                    return `
                            <div class="wpb-notif-card${isOpen ? ' is-open' : ''}" data-idx="${i}">

                                <div class="wpb-notif-header" data-idx="${i}">
                                    <div class="wpb-notif-header-icon">✉</div>
                                    <div class="wpb-notif-header-info">
                                        <div class="wpb-notif-header-title">${esc(headerTitle)}</div>
                                        <div class="wpb-notif-header-sub">Da: ${fromLabel}</div>
                                    </div>
                                    <div class="wpb-notif-header-actions">
                                        <span class="wpb-notif-chevron">▼</span>
                                        <button type="button" class="wpb-notif-del-btn" data-idx="${i}" title="Elimina notifica">×</button>
                                    </div>
                                </div>

                                <div class="wpb-notif-body">

                                    <!-- NOME NOTIFICA -->
                                    <div class="wpb-notif-section">
                                        <div class="wpb-notif-field">
                                            <label>Nome notifica <span class="opt">opzionale</span></label>
                                            <input type="text" data-field="notification_name"
                                                   value="${esc(n.notification_name || '')}"
                                                   placeholder="es. Notifica admin, Conferma utente…">
                                            <p class="hint">Etichetta interna per riconoscere questa notifica.</p>
                                        </div>
                                    </div>

                                    <!-- DESTINATARIO -->
                                    <div class="wpb-notif-section">
                                        <p class="wpb-notif-section-label">📨 Destinatario</p>
                                        <div class="wpb-notif-grid">
                                            <div class="wpb-notif-field">
                                                <label>Email destinatario</label>
                                                <input type="text" data-field="to_email"
                                                       value="${esc(n.to_email || '')}"
                                                       placeholder="admin@esempio.com">
                                                ${emailPills}
                                                <p class="hint">Puoi inserire un'email fissa o usare un campo del form.</p>
                                            </div>
                                            <div class="wpb-notif-field">
                                                <label>Nome destinatario <span class="opt">opzionale</span></label>
                                                <input type="text" data-field="to_name" data-supports-vars="1"
                                                       value="${esc(n.to_name || '')}"
                                                       placeholder="Admin">
                                                ${pills}
                                            </div>
                                        </div>
                                    </div>

                                    <!-- MITTENTE -->
                                    <div class="wpb-notif-section">
                                        <p class="wpb-notif-section-label">👤 Mittente</p>
                                        <div class="wpb-notif-grid">
                                            <div class="wpb-notif-field">
                                                <label>Email mittente</label>
                                                <input type="email" data-field="from_email"
                                                       value="${esc(n.from_email || '')}"
                                                       placeholder="noreply@esempio.com">
                                            </div>
                                            <div class="wpb-notif-field">
                                                <label>Nome mittente <span class="opt">opzionale</span></label>
                                                <input type="text" data-field="from_name" data-supports-vars="1"
                                                       value="${esc(n.from_name || '')}"
                                                       placeholder="Nome Sito">
                                                ${pills}
                                            </div>
                                        </div>
                                    </div>

                                    <!-- CONTENUTO EMAIL -->
                                    <div class="wpb-notif-section">
                                        <p class="wpb-notif-section-label">📝 Contenuto email</p>
                                        <div class="wpb-notif-grid single" style="margin-bottom:12px;">
                                            <div class="wpb-notif-field">
                                                <label>Oggetto email</label>
                                                <input type="text" data-field="subject" data-supports-vars="1"
                                                       value="${esc(n.subject || '')}"
                                                       placeholder="Nuovo messaggio dal form">
                                                ${pills}
                                            </div>
                                        </div>
                                        <div class="wpb-notif-grid single" style="margin-bottom:12px;">
                                            <div class="wpb-notif-field">
                                                <label>Titolo intestazione email <span class="opt">opzionale</span></label>
                                                <input type="text" data-field="email_title" data-supports-vars="1"
                                                       value="${esc(n.email_title || '')}"
                                                       placeholder="Hai ricevuto un nuovo messaggio">
                                                ${pills}
                                            </div>
                                        </div>
                                        <div class="wpb-notif-grid single">
                                            <div class="wpb-notif-field">
                                                <label>Corpo del messaggio</label>
                                                <div style="display:flex;gap:0;margin-bottom:8px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                                                    <button type="button" class="wpb-msg-mode-btn${!isCustom ? ' active' : ''}"
                                                            data-mode="auto"
                                                            style="flex:1;padding:5px 10px;font-size:11px;font-weight:600;font-family:inherit;border:none;cursor:pointer;background:${!isCustom ? '#111827' : '#f6f7f7'};color:${!isCustom ? '#fff' : '#646970'};">
                                                        Auto (tutti i campi)
                                                    </button>
                                                    <button type="button" class="wpb-msg-mode-btn${isCustom ? ' active' : ''}"
                                                            data-mode="custom"
                                                            style="flex:1;padding:5px 10px;font-size:11px;font-weight:600;font-family:inherit;border:none;border-left:1px solid #e5e7eb;cursor:pointer;background:${isCustom ? '#111827' : '#f6f7f7'};color:${isCustom ? '#fff' : '#646970'};">
                                                        Personalizzato
                                                    </button>
                                                </div>
                                                <input type="hidden" data-field="message_mode" value="${esc(msgMode)}">
                                                <div class="wpb-msg-custom-area" style="display:${isCustom ? '' : 'none'};">
                                                    <textarea data-field="message" data-supports-vars="1"
                                                              placeholder="Scrivi il corpo dell'email. Lascia vuoto per inviare senza corpo.">${esc(n.message || '')}</textarea>
                                                    ${pills}
                                                    <p class="hint">Puoi inserire i riferimenti ai campi del form usando i pill sopra.</p>
                                                </div>
                                                <div class="wpb-msg-auto-area" style="display:${!isCustom ? '' : 'none'};">
                                                    <p class="hint" style="margin:0;">Verrà generata automaticamente una tabella con tutti i campi compilati.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- OPZIONALI -->
                                    <div class="wpb-notif-section">
                                        <p class="wpb-notif-section-label">⚙️ Opzionali</p>
                                        <div class="wpb-notif-grid">
                                            <div class="wpb-notif-field">
                                                <label>Reply-To email <span class="opt">opzionale</span></label>
                                                <input type="email" data-field="replyto_email"
                                                       value="${esc(n.replyto_email || '')}"
                                                       placeholder="risposta@esempio.com">
                                            </div>
                                            <div class="wpb-notif-field">
                                                <label>Reply-To nome <span class="opt">opzionale</span></label>
                                                <input type="text" data-field="replyto_name" data-supports-vars="1"
                                                       value="${esc(n.replyto_name || '')}"
                                                       placeholder="">
                                                ${pills}
                                            </div>
                                            <div class="wpb-notif-field">
                                                <label>CCN email <span class="opt">opzionale</span></label>
                                                <input type="email" data-field="cc_email"
                                                       value="${esc(n.cc_email || '')}"
                                                       placeholder="ccn@esempio.com">
                                            </div>
                                            <div class="wpb-notif-field">
                                                <label>CCN nome <span class="opt">opzionale</span></label>
                                                <input type="text" data-field="cc_name" data-supports-vars="1"
                                                       value="${esc(n.cc_name || '')}"
                                                       placeholder="">
                                                ${pills}
                                            </div>
                                        </div>
                                    </div>

                                </div>
                            </div>`;
                }).join('');

                // Bind pill variabili → inserisci {{name}} nel campo al cursore
                list.querySelectorAll('.wpb-field-var-pill').forEach(pill => {
                    pill.addEventListener('click', () => {
                        const varText = pill.dataset.var;
                        const section = pill.closest('.wpb-notif-field');
                        const target = section
                            ? section.querySelector('input[data-supports-vars], textarea[data-supports-vars]')
                            : null;
                        if (!target) return;

                        const start = target.selectionStart ?? target.value.length;
                        const end = target.selectionEnd ?? target.value.length;
                        target.value = target.value.slice(0, start) + varText + target.value.slice(end);
                        const newPos = start + varText.length;
                        target.setSelectionRange(newPos, newPos);
                        target.focus();

                        const idx = parseInt(target.closest('.wpb-notif-card').dataset.idx, 10);
                        notificationsData[idx][target.dataset.field] = target.value;
                    });
                });

                // Pill campi email → imposta direttamente to_email
                list.querySelectorAll('.wpb-email-field-pill').forEach(pill => {
                    pill.addEventListener('click', () => {
                        const card = pill.closest('.wpb-notif-card');
                        const input = card.querySelector('input[data-field="to_email"]');
                        const idx = parseInt(card.dataset.idx, 10);
                        if (!input) return;
                        input.value = pill.dataset.value;
                        notificationsData[idx].to_email = pill.dataset.value;
                        // aggiorna header
                        const titleEl = card.querySelector('.wpb-notif-header-title');
                        if (titleEl) titleEl.textContent = pill.dataset.value;
                    });
                });

                // Toggle modalità corpo messaggio
                list.querySelectorAll('.wpb-msg-mode-btn').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const card = btn.closest('.wpb-notif-card');
                        const idx = parseInt(card.dataset.idx, 10);
                        const mode = btn.dataset.mode;
                        notificationsData[idx].message_mode = mode;
                        // Aggiorna visuale senza re-render completo
                        card.querySelectorAll('.wpb-msg-mode-btn').forEach(b => {
                            const isActive = b.dataset.mode === mode;
                            b.style.background = isActive ? '#111827' : '#f6f7f7';
                            b.style.color = isActive ? '#fff' : '#646970';
                        });
                        const customArea = card.querySelector('.wpb-msg-custom-area');
                        const autoArea = card.querySelector('.wpb-msg-auto-area');
                        const modeInput = card.querySelector('input[data-field="message_mode"]');
                        if (customArea) customArea.style.display = mode === 'custom' ? '' : 'none';
                        if (autoArea) autoArea.style.display = mode === 'auto' ? '' : 'none';
                        if (modeInput) modeInput.value = mode;
                    });
                });

                // Toggle accordion
                list.querySelectorAll('.wpb-notif-header').forEach(header => {
                    header.addEventListener('click', e => {
                        if (e.target.closest('.wpb-notif-del-btn')) return;
                        const idx = parseInt(header.dataset.idx, 10);
                        notificationsData[idx]._open = (notificationsData[idx]._open === false);
                        const card = header.closest('.wpb-notif-card');
                        card.classList.toggle('is-open', notificationsData[idx]._open !== false);
                        card.querySelector('.wpb-notif-body').style.display =
                            notificationsData[idx]._open !== false ? '' : 'none';
                    });
                });

                // Live update campi
                list.querySelectorAll('input[data-field], textarea[data-field]').forEach(inp => {
                    inp.addEventListener('input', () => {
                        const idx = parseInt(inp.closest('.wpb-notif-card').dataset.idx, 10);
                        notificationsData[idx][inp.dataset.field] = inp.value;
                        // Aggiorna header senza re-render completo
                        const card = inp.closest('.wpb-notif-card');
                        if (inp.dataset.field === 'to_email' || inp.dataset.field === 'notification_name') {
                            const t = card.querySelector('.wpb-notif-header-title');
                            const n = notificationsData[idx];
                            if (t) t.textContent = n.notification_name || n.to_email || `Notifica ${idx + 1}`;
                        }
                        if (inp.dataset.field === 'from_name' || inp.dataset.field === 'from_email') {
                            const s = card.querySelector('.wpb-notif-header-sub');
                            const n = notificationsData[idx];
                            if (s) s.textContent = 'Da: ' + (n.from_name || n.from_email || 'Mittente non impostato');
                        }
                    });
                });

                // Elimina
                list.querySelectorAll('.wpb-notif-del-btn').forEach(btn => {
                    btn.addEventListener('click', e => {
                        e.stopPropagation();
                        notificationsData.splice(parseInt(btn.dataset.idx, 10), 1);
                        renderNotifications();
                    });
                });
            }

            document.getElementById('wpb-btn-add-notification')?.addEventListener('click', () => {
                notificationsData.push({
                    notification_name: '', from_email: '', from_name: '', to_email: '', to_name: '',
                    cc_email: '', cc_name: '', replyto_email: '', replyto_name: '',
                    subject: '', email_title: '', message: '', message_mode: 'auto', _open: true
                });
                renderNotifications();
                // Focus automatico sull'email destinatario della nuova card
                const cards = document.querySelectorAll('.wpb-notif-card');
                if (cards.length) cards[cards.length - 1].querySelector('input[data-field="to_email"]')?.focus();
            });

            // Sincronizza color picker ↔ input hex
            ['wpb-setting-email-bg-color', 'wpb-setting-email-header-color'].forEach(id => {
                const picker = document.getElementById(id);
                const hex = document.getElementById(id + '-hex');
                if (!picker || !hex) return;
                picker.addEventListener('input', () => { hex.value = picker.value; });
                hex.addEventListener('input', () => {
                    if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) picker.value = hex.value;
                });
            });

            // Media picker per logo email
            (function () {
                const btn = document.getElementById('wpb-btn-media-logo');
                const input = document.getElementById('wpb-setting-email-logo-url');
                const preview = document.getElementById('wpb-logo-preview');
                const removeBtn = document.getElementById('wpb-btn-remove-logo');
                if (!btn || !input) return;

                function updatePreview() {
                    const url = input.value.trim();
                    if (url && preview) {
                        preview.querySelector('img').src = url;
                        preview.style.display = '';
                    } else if (preview) {
                        preview.style.display = 'none';
                    }
                }
                input.addEventListener('input', updatePreview);

                btn.addEventListener('click', () => {
                    const frame = wp.media({
                        title: 'Seleziona logo',
                        library: { type: ['image/png', 'image/jpeg'] },
                        multiple: false
                    });
                    frame.on('select', () => {
                        const attachment = frame.state().get('selection').first().toJSON();
                        input.value = attachment.url;
                        updatePreview();
                    });
                    frame.open();
                });

                if (removeBtn) {
                    removeBtn.addEventListener('click', () => {
                        input.value = '';
                        updatePreview();
                    });
                }
            })();

            /* ── Helpers ── */
            function genId() { return 'field_' + Math.random().toString(36).slice(2, 9); }
            function esc(s) { return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

            // Formatta valore checkbox/radio: "Label: true | Label: false" → HTML con ✓/✗
            function formatCheckVal(val) {
                if (typeof val !== 'string' || !/:\s*(true|false)(\s*\||$)/.test(val)) return null;
                return val.split('|').map(p => p.trim()).filter(Boolean).map(part => {
                    const m = part.match(/^(.+?):\s*(true|false)$/);
                    if (!m) return '';
                    const label = m[1].trim();
                    const checked = m[2] === 'true';
                    const icon = checked ? '✔' : '✘';
                    const cls = checked ? 'wpb-check-on' : 'wpb-check-off';
                    return `<div class="${cls}">${icon} ${esc(label)}</div>`;
                }).join('');
            }

            // Riassunto compatto per lista: "2/3" (quanti true su totali)
            function formatCheckSummary(val) {
                if (typeof val !== 'string' || !/:\s*(true|false)(\s*\||$)/.test(val)) return null;
                const parts = val.split('|').map(p => p.trim()).filter(Boolean);
                let total = 0, on = 0;
                parts.forEach(part => {
                    const m = part.match(/^(.+?):\s*(true|false)$/);
                    if (m) { total++; if (m[2] === 'true') on++; }
                });
                return `<span class="wpb-check-summary">${on}/${total}</span>`;
            }

            // Slugify: converte una label in un name HTML-safe senza spazi né caratteri speciali
            // Es. "Nome e Cognome" → "nome_e_cognome"  |  "Età (anni)" → "eta_anni"
            function slugify(label) {
                return String(label || '')
                    .toLowerCase()
                    .normalize('NFD')                        // separa i diacritici (à → a + `)
                .replace(/[\u0300-\u036f]/g, '')         // rimuove i diacritici
                .replace(/[^a-z0-9]+/g, '_')             // tutto ciò che non è alfanumerico → _
                .replace(/^_+|_+$/g, '')                 // trimma underscore iniziali/finali
                || 'campo';
        }

        const TYPE_LABELS = {
            text: 'Testo', email: 'Email', textarea: 'Area di testo',
            tel: 'Telefono', number: 'Numero', datepicker: 'Data',
            select: 'Seleziona', radio: 'Scelta', checkbox: 'Scelta multipla',
            file: 'File', page_break: 'Interruzione pagina'
        };

        /* ── Builder tabs ── */
        document.querySelectorAll('.wpb-btab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.wpb-btab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('wpb-tab-fields').style.display = tab.dataset.tab === 'fields' ? '' : 'none';
                document.getElementById('wpb-tab-settings').style.display = tab.dataset.tab === 'settings' ? '' : 'none';
                document.getElementById('wpb-tab-notifiche').style.display = tab.dataset.tab === 'notifiche' ? '' : 'none';
            });
        });

        /* ── Aggiungi campo ── */
        function addField(type) {
            const label = TYPE_LABELS[type] || type;
            const f = {
                id: genId(),
                name: slugify(label),
                type,
                label,
                placeholder: '',
                required: false,
                error_message: '',
                options: ['select', 'radio', 'checkbox'].includes(type) ? ['Opzione 1', 'Opzione 2'] : [],
            };
            if (type === 'number') { f.min = ''; f.max = ''; }
            if (type === 'datepicker') { f.format = 'dd/MM/y'; }
            if (type === 'page_break') { f.label = 'Interruzione pagina'; f.name = ''; f.required = false; }
            fields.push(f);
            renderCanvas();
            selectField(fields[fields.length - 1].id);
        }

        /* ── Canvas ── */
        function renderCanvas() {
            dropZone.querySelectorAll('.wpb-field-card').forEach(c => c.remove());
            emptyState.style.display = fields.length === 0 ? 'flex' : 'none';
            fields.forEach((f, i) => dropZone.appendChild(buildCard(f, i)));
            refreshMailupOptinSelect();
        }

        // Aggiorna il <select> opt-in MailUp con i campi checkbox presenti nel canvas
        function refreshMailupOptinSelect() {
            const sel = document.getElementById('wpb-setting-mailup-optin-field');
            if (!sel) return;
            const current = sel.value;
            while (sel.options.length > 1) sel.remove(1);
            fields.filter(f => f.type === 'checkbox').forEach(f => {
                const opt = document.createElement('option');
                opt.value = f.name || '';
                opt.textContent = (f.label || f.name || f.id) + '  [slug: ' + (f.name || '') + ']';
                sel.appendChild(opt);
            });
            // Ripristina il valore precedente se esiste ancora
            if ([...sel.options].some(o => o.value === current)) sel.value = current;
        }

        function buildCard(f, idx) {
            const card = document.createElement('div');
            card.className = 'wpb-field-card' + (f.id === selectedFieldId ? ' selected' : '');
            card.dataset.id = f.id;
            card.dataset.idx = idx;
            card.draggable = true;

            let preview = '';
            if (f.type === 'textarea') {
                preview = `<textarea placeholder="${esc(f.placeholder || f.label)}" disabled></textarea>`;
            } else if (f.type === 'select') {
                preview = `<div class="wpb-preview-select"><span>${esc(f.placeholder || 'Seleziona…')}</span><svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2 4l4 4 4-4" stroke="#a7aaad" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></div>`;
            } else if (f.type === 'radio') {
                const opts = (f.options || []).slice(0, 3);
                preview = `<div class="wpb-preview-choices">${opts.map(o => `<label class="wpb-preview-choice"><span class="wpb-preview-radio"></span>${esc(o)}</label>`).join('')}</div>`;
            } else if (f.type === 'checkbox') {
                const opts = (f.options || []).slice(0, 3);
                preview = `<div class="wpb-preview-choices">${opts.map(o => `<label class="wpb-preview-choice"><span class="wpb-preview-checkbox"></span>${esc(o)}</label>`).join('')}</div>`;
            } else if (f.type === 'datepicker') {
                preview = `<div class="wpb-preview-date"><span>${esc(f.placeholder || 'gg/mm/aaaa')}</span><svg width="14" height="14" viewBox="0 0 14 14" fill="none"><rect x="1" y="2" width="12" height="11" rx="1.5" stroke="#a7aaad" stroke-width="1.2"/><path d="M1 5h12M4 1v2M10 1v2" stroke="#a7aaad" stroke-width="1.2" stroke-linecap="round"/></svg></div>`;
            } else if (f.type === 'file') {
                preview = `<div class="wpb-preview-file"><svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M3 1h6l3 3v9H3V1z" stroke="#a7aaad" stroke-width="1.2" stroke-linejoin="round"/><path d="M8 1v3h3" stroke="#a7aaad" stroke-width="1.2" stroke-linejoin="round"/></svg><span>Scegli file…</span></div>`;
            } else if (f.type === 'page_break') {
                preview = `<div class="wpb-preview-page-break"><hr style="border:none;border-top:2px dashed #d1d5db;margin:0;"><span style="display:block;text-align:center;font-size:11px;color:#6b7280;margin-top:4px;font-weight:600;">↓ Pagina successiva ↓</span></div>`;
            } else {
                preview = `<input type="${esc(f.type)}" placeholder="${esc(f.placeholder || f.label)}" disabled>`;
            }

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
            card.addEventListener('dragend', () => { card.classList.remove('dragging'); dropZone.querySelectorAll('.wpb-field-card').forEach(c => { c.style.borderTop = c.style.borderBottom = ''; }); });
            card.addEventListener('dragover', e => {
                e.preventDefault();
                const mid = card.getBoundingClientRect().top + card.getBoundingClientRect().height / 2;
                dropZone.querySelectorAll('.wpb-field-card').forEach(c => { c.style.borderTop = c.style.borderBottom = ''; });
                card.style[e.clientY < mid ? 'borderTop' : 'borderBottom'] = '2px solid #111827';
            });
            card.addEventListener('dragleave', () => { card.style.borderTop = card.style.borderBottom = ''; });
            card.addEventListener('drop', e => {
                e.preventDefault();
                card.style.borderTop = card.style.borderBottom = '';
                const tIdx = parseInt(card.dataset.idx);
                if (dragSrcIdx === null || dragSrcIdx === tIdx) return;
                const mid = card.getBoundingClientRect().top + card.getBoundingClientRect().height / 2;
                const moved = fields.splice(dragSrcIdx, 1)[0];
                let at = tIdx > dragSrcIdx ? tIdx - 1 : tIdx;
                if (e.clientY >= mid) at++;
                fields.splice(at, 0, moved);
                dragSrcIdx = null;
                renderCanvas();
            });
            return card;
        }

        dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
        dropZone.addEventListener('dragleave', e => { if (!dropZone.contains(e.relatedTarget)) dropZone.classList.remove('drag-over'); });
        dropZone.addEventListener('drop', () => dropZone.classList.remove('drag-over'));

        function selectField(id) { selectedFieldId = id; renderCanvas(); renderProps(); }

        /* ── Modale conferma ── */
        let _pendingRemoveId = null;
        const modal = document.getElementById('wpb-confirm-modal');
        const modalName = document.getElementById('wpb-modal-field-name');
        const modalConfirm = document.getElementById('wpb-modal-confirm');
        const modalCancel = document.getElementById('wpb-modal-cancel');

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

            if (f.type === 'page_break') {
                propsInner.innerHTML = `<div style="padding:12px 0;text-align:center;color:#646970;">
                                                                                                                                                                                                                                                                                                    <span class="dashicons dashicons-minus" style="font-size:28px;width:28px;height:28px;color:#6b7280;"></span>
                                                                                                                                                                                                                                                                                                    <p style="margin:8px 0 0;font-size:13px;">Interruzione pagina</p>
                                                                                                                                                                                                                                                                                                    <p style="margin:4px 0 0;font-size:11px;color:#a7aaad;">Separa i campi in step diversi nel form multistep.</p>
                                                                                                                                                                                                                                                                                                </div>`;
                return;
            }

            const hasOpts = ['select', 'radio', 'checkbox'].includes(f.type);
            const isNumber = f.type === 'number';
            const isDatepicker = f.type === 'datepicker';
            const isFile = f.type === 'file';
            const isSelect = f.type === 'select';
            const showPlaceholder = (!hasOpts || isSelect) && !isDatepicker && !isFile;

            propsInner.innerHTML = `
                                                                                                                                                                                                            <div class="wpb-prop-group">
                                                                                                                                                                                                                <label>Etichetta</label>
                                                                                                                                                                                                                <input type="text" id="prop-label" value="${esc(f.label)}">
                                                                                                                                                                                                            </div>
                                                                                                                                                                                                            <div class="wpb-prop-group">
                                                                                                                                                                                                                <label>Name <span style="font-size:10px;color:#a7aaad;font-weight:400;">(attributo HTML, auto-generato dalla label)</span></label>
                                                                                                                                                                                                                <input type="text" id="prop-name" value="${esc(f.name || '')}" style="font-family:Consolas,monospace;font-size:12px;">
                                                                                                                                                                                                            </div>
                                                                                                                                                                                                            ${showPlaceholder ? `<div class="wpb-prop-group"><label>Testo segnaposto</label><input type="text" id="prop-placeholder" value="${esc(f.placeholder || '')}"></div>` : ''}
                                                                                                                                                                                                            ${isNumber ? `
                <div class="wpb-prop-group">
                    <label>Valore minimo</label>
                    <input type="number" id="prop-min" value="${esc(f.min ?? '')}">
                </div>
                <div class="wpb-prop-group">
                    <label>Valore massimo</label>
                    <input type="number" id="prop-max" value="${esc(f.max ?? '')}">
                </div>` : ''}
                                                                                                                                                                                                            ${isDatepicker ? `
                <div class="wpb-prop-group">
                    <label>Formato data</label>
                    <input type="text" id="prop-format" value="${esc(f.format || 'dd/MM/y')}" placeholder="dd/MM/y">
                    <p style="font-size:11px;color:#a7aaad;margin:4px 0 0;">Es: dd/MM/y · MM/dd/yyyy · yyyy-MM-dd</p>
                </div>` : ''}
                                                                                                                                                                                                            ${hasOpts ? `<div class="wpb-prop-group">
                    <label>Opzioni</label>
                    <div class="wpb-options-list" id="wpb-opts-list">
                    ${(f.options || []).map((o, i) => `<div class="wpb-option-row"><input type="text" class="wpb-opt-input" data-idx="${i}" value="${esc(o)}" placeholder="Opzione ${i + 1}"><button class="wpb-btn-rm-opt" data-idx="${i}">×</button></div>`).join('')}
                    </div>
                    <button class="wpb-btn-add-opt" id="wpb-btn-add-opt">+ Aggiungi opzione</button>
                </div>` : ''}
                                                                                                                                                                                                            <div class="wpb-prop-group">
                                                                                                                                                                                                                <label class="wpb-prop-check"><input type="checkbox" id="prop-required" ${f.required ? 'checked' : ''}> Campo obbligatorio</label>
                                                                                                                                                                                                            </div>
                                                                                                                                                                                                            <div class="wpb-prop-group">
                                                                                                                                                                                                                <label>Messaggio di errore</label>
                                                                                                                                                                                                                <input type="text" id="prop-error-message" value="${esc(f.error_message || '')}" placeholder="Questo campo è obbligatorio">
                                                                                                                                                                                                                <p style="font-size:11px;color:#a7aaad;margin:4px 0 0;">Mostrato se il campo non è compilato correttamente.</p>
                                                                                                                                                                                                            </div>
                                                                                                                                                                                                            <div style="margin-top:20px;padding-top:14px;border-top:1px solid #f0f0f1;font-size:11px;color:#a7aaad;">
                                                                                                                                                                                                                Tipo: <strong>${esc(f.type)}</strong>
                                                                                                                                                                                                            </div>
                                                                                                                                                                                                            <div class="wpb-cond-section">
                                                                                                                                                                                                                <div class="wpb-cond-section-title">
                                                                                                                                                                                                                    <span>Logica condizionale</span>
                                                                                                                                                                                                                </div>
                                                                                                                                                                                                                ${renderCondLogicUI(f)}
                                                                                                                                                                                                            </div>`;

            const bi = (elId, key) => { const el = document.getElementById(elId); if (el) el.addEventListener('input', () => updateField(f.id, key, el.value)); };

            // Binding label: aggiorna label E ricalcola name automaticamente (non in translation mode)
            const labelEl = document.getElementById('prop-label');
            const nameEl = document.getElementById('prop-name');
            if (labelEl) {
                labelEl.addEventListener('input', () => {
                    const newLabel = labelEl.value;
                    updateField(f.id, 'label', newLabel);
                    if (!IS_TRANSLATION) {
                        const newName = slugify(newLabel);
                        updateField(f.id, 'name', newName);
                        if (nameEl) nameEl.value = newName;
                    }
                });
            }
            // Binding name: modificabile manualmente (non in translation mode)
            if (!IS_TRANSLATION) bi('prop-name', 'name');

            bi('prop-placeholder', 'placeholder');
            bi('prop-min', 'min');
            bi('prop-max', 'max');
            bi('prop-format', 'format');
            bi('prop-error-message', 'error_message');
            const req = document.getElementById('prop-required');
            if (req) req.addEventListener('change', () => updateField(f.id, 'required', req.checked));

            if (hasOpts) {
                document.querySelectorAll('.wpb-opt-input').forEach(inp => {
                    inp.addEventListener('input', () => {
                        const opts = [...(f.options || [])]; opts[parseInt(inp.dataset.idx)] = inp.value;
                        updateField(f.id, 'options', opts);
                    });
                });
                document.querySelectorAll('.wpb-btn-rm-opt').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const opts = [...(f.options || [])]; opts.splice(parseInt(btn.dataset.idx), 1);
                        updateField(f.id, 'options', opts); renderProps();
                    });
                });
                const addBtn = document.getElementById('wpb-btn-add-opt');
                if (addBtn) addBtn.addEventListener('click', () => { updateField(f.id, 'options', [...(f.options || []), 'Nuova opzione']); renderProps(); });
            }

            // Bind logica condizionale
            bindCondEvents(f);
        }

        /* ══════════════════════════════════════════════════════════════════
           LOGICA CONDIZIONALE — modello Gravity Forms
           Ogni campo porta la propria regola: "mostrami/nascondimi se…"
           ══════════════════════════════════════════════════════════════════ */

        /**
         * Costruisce l'HTML completo del pannello logica condizionale per un campo.
         * Gravity Forms-style:
         *   - Abilita/Disabilita (toggle)
         *   - Azione: Mostra | Nascondi
         *   - Logica: Tutte (AND) | Almeno una (OR)
         *   - Lista di regole: [campo] [operatore] [valore]
         */
        function renderCondLogicUI(f) {
            const cl = f.conditionalLogic || { enabled: false, action: 'show', logicType: 'all', rules: [] };
            const enabled = !!cl.enabled;
            const action = cl.action || 'show';
            const logicType = cl.logicType || 'all';
            const rules = cl.rules || [];

            const otherFields = fields.filter(x => x.id !== f.id && x.type !== 'page_break');

            const rulesHtml = rules.length
                ? rules.map((r, ri) => renderCondRuleRow(r, ri, otherFields, f)).join('')
                : '';

            return `
                                                                                                                                                                    <div class="wpb-cl-wrap">
                                                                                                                                                                        <label class="wpb-cl-enable-row" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:10px;">
                                                                                                                                                                            <input type="checkbox" id="cl-enabled" ${enabled ? 'checked' : ''} style="margin:0;">
                                                                                                                                                                            <span style="font-size:12px;font-weight:600;color:#1d2327;">Abilita logica condizionale</span>
                                                                                                                                                                        </label>
                                                                                                                                                                        <div id="cl-body" style="display:${enabled ? 'block' : 'none'};">
                                                                                                                                                                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:12px;color:#1d2327;margin-bottom:10px;">
                                                                                                                                                                                <select id="cl-action" style="padding:4px 8px;font-size:12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-family:inherit;">
                                                                                                                                                                                    <option value="show" ${action === 'show' ? 'selected' : ''}>Mostra</option>
                                                                                                                                                                                    <option value="hide" ${action === 'hide' ? 'selected' : ''}>Nascondi</option>
                                                                                                                                                                                </select>
                                                                                                                                                                                <span>questo campo se</span>
                                                                                                                                                                                <select id="cl-logic-type" style="padding:4px 8px;font-size:12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-family:inherit;">
                                                                                                                                                                                    <option value="all" ${logicType === 'all' ? 'selected' : ''}>tutte</option>
                                                                                                                                                                                    <option value="any" ${logicType === 'any' ? 'selected' : ''}>almeno una</option>
                                                                                                                                                                                </select>
                                                                                                                                                                                <span>delle seguenti condizioni sono soddisfatte:</span>
                                                                                                                                                                            </div>
                                                                                                                                                                            <div class="wpb-cond-list" id="wpb-cond-list">
                                                                                                                                                                                ${rulesHtml || '<p style="font-size:11px;color:#a7aaad;margin:4px 0 8px;">Nessuna regola. Aggiungine una.</p>'}
                                                                                                                                                                            </div>
                                                                                                                                                                            <button type="button" class="wpb-btn-add-cond" id="wpb-btn-add-cond">+ Aggiungi regola</button>
                                                                                                                                                                        </div>
                                                                                                                                                                    </div>`;
        }

        /**
         * Render di una singola riga-regola.
         * Struttura: [Campo ▾] [Operatore ▾] [Valore] [×]
         */
        function renderCondRuleRow(rule, ri, otherFields, f) {
            const selectedField = otherFields.find(x => x.name === rule.fieldName) || null;

            const fieldOpts = otherFields.map(x =>
                `<option value="${esc(x.name)}" ${rule.fieldName === x.name ? 'selected' : ''}>${esc(x.label || x.name)}</option>`
            ).join('');

            // Operatori disponibili a seconda del tipo di campo sorgente
            const srcHasOptions = selectedField && ['select', 'radio', 'checkbox'].includes(selectedField.type) && (selectedField.options || []).length;
            const srcIsNumber = selectedField && selectedField.type === 'number';

            let availableOps;
            if (srcHasOptions) {
                availableOps = [
                    { v: '=', l: 'è uguale a' },
                    { v: '!=', l: 'è diverso da' },
                ];
            } else if (srcIsNumber) {
                availableOps = [
                    { v: '=', l: 'è uguale a' },
                    { v: '!=', l: 'è diverso da' },
                    { v: '>', l: 'è maggiore di' },
                    { v: '<', l: 'è minore di' },
                    { v: 'empty', l: 'è vuoto' },
                    { v: 'not_empty', l: 'non è vuoto' },
                ];
            } else {
                availableOps = [
                    { v: '=', l: 'è uguale a' },
                    { v: '!=', l: 'è diverso da' },
                    { v: 'contains', l: 'contiene' },
                    { v: 'starts_with', l: 'inizia per' },
                    { v: 'ends_with', l: 'finisce per' },
                    { v: 'empty', l: 'è vuoto' },
                    { v: 'not_empty', l: 'non è vuoto' },
                ];
            }

            const opOpts = availableOps.map(o =>
                `<option value="${o.v}" ${(rule.operator || '=') === o.v ? 'selected' : ''}>${o.l}</option>`
            ).join('');

            const needsValue = !['empty', 'not_empty'].includes(rule.operator || '=');
            let valueEl = '';
            if (needsValue) {
                if (srcHasOptions) {
                    const valOpts = (selectedField.options || []).map(opt =>
                        `<option value="${esc(opt)}" ${(rule.value || '') === opt ? 'selected' : ''}>${esc(opt)}</option>`
                    ).join('');
                    valueEl = `<select class="cl-rule-val" data-ri="${ri}" style="flex:1;min-width:80px;padding:4px 6px;font-size:11px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-family:inherit;">
                                                                                                                                                                                       <option value="" disabled ${!rule.value ? 'selected' : ''} hidden>Seleziona…</option>
                                                                                                                                                                                       ${valOpts}
                                                                                                                                                                                   </select>`;
                } else {
                    valueEl = `<input type="text" class="cl-rule-val" data-ri="${ri}"
                                                                                                                                                                                          value="${esc(rule.value || '')}" placeholder="Valore…"
                                                                                                                                                                                          style="flex:1;min-width:80px;padding:4px 7px;font-size:11px;border:1px solid #e5e7eb;border-radius:8px;font-family:inherit;">`;
                }
            }

            return `<div class="wpb-cond-row" data-ri="${ri}" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                                                                                                                                                                            <select class="cl-rule-field" data-ri="${ri}" style="flex:1;min-width:110px;padding:4px 6px;font-size:11px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-family:inherit;">
                                                                                                                                                                                <option value="" disabled ${!rule.fieldName ? 'selected' : ''} hidden>Campo…</option>
                                                                                                                                                                                ${fieldOpts || '<option disabled>Nessun campo disponibile</option>'}
                                                                                                                                                                            </select>
                                                                                                                                                                            <select class="cl-rule-op" data-ri="${ri}" style="flex:0 0 auto;padding:4px 6px;font-size:11px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;font-family:inherit;">
                                                                                                                                                                                ${opOpts}
                                                                                                                                                                            </select>
                                                                                                                                                                            ${valueEl}
                                                                                                                                                                            <button type="button" class="wpb-btn-rm-cond" data-ri="${ri}" title="Rimuovi regola" style="padding:2px 6px;font-size:14px;background:none;border:none;cursor:pointer;color:#d63638;flex-shrink:0;">×</button>
                                                                                                                                                                        </div>`;
        }

        /* ── Logica condizionale: bind eventi ── */
        function bindCondEvents(f) {
            const wrap = document.getElementById('wpb-cond-list');
            const body = document.getElementById('cl-body');
            const enabledCb = document.getElementById('cl-enabled');
            if (!wrap || !enabledCb) return;

            /* Legge l'intera struttura conditionalLogic dal DOM */
            function readCondLogic() {
                const enabled = enabledCb.checked;
                const actionSel = document.getElementById('cl-action');
                const ltSel = document.getElementById('cl-logic-type');
                const rules = [];

                wrap.querySelectorAll('.wpb-cond-row').forEach(row => {
                    const fieldSel = row.querySelector('.cl-rule-field');
                    const opSel = row.querySelector('.cl-rule-op');
                    const valEl = row.querySelector('.cl-rule-val');
                    if (!fieldSel || !fieldSel.value) return;
                    rules.push({
                        fieldName: fieldSel.value,
                        operator: opSel ? opSel.value : '=',
                        value: valEl ? valEl.value : '',
                    });
                });

                return {
                    enabled: enabled,
                    action: actionSel ? actionSel.value : 'show',
                    logicType: ltSel ? ltSel.value : 'all',
                    rules: rules,
                };
            }

            function saveCL() {
                updateField(f.id, 'conditionalLogic', readCondLogic());
            }

            /* Toggle abilita/disabilita */
            enabledCb.addEventListener('change', () => {
                if (body) body.style.display = enabledCb.checked ? 'block' : 'none';
                saveCL();
            });

            /* Action (mostra/nascondi) e logicType (all/any) */
            ['cl-action', 'cl-logic-type'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('change', saveCL);
            });

            /* Rimuovi regola */
            wrap.querySelectorAll('.wpb-btn-rm-cond').forEach(btn => {
                btn.addEventListener('click', () => {
                    const cl = Object.assign({}, f.conditionalLogic || {});
                    const ri = parseInt(btn.dataset.ri);
                    cl.rules = (cl.rules || []).filter((_, i) => i !== ri);
                    updateField(f.id, 'conditionalLogic', cl);
                    renderProps();
                });
            });

            /* Campo sorgente cambia → re-render per aggiornare operatori/valori */
            wrap.querySelectorAll('.cl-rule-field').forEach(sel => {
                sel.addEventListener('change', () => {
                    updateField(f.id, 'conditionalLogic', readCondLogic());
                    renderProps();
                });
            });

            /* Operatore cambia → re-render per mostrare/nascondere il campo valore */
            wrap.querySelectorAll('.cl-rule-op').forEach(sel => {
                sel.addEventListener('change', () => {
                    updateField(f.id, 'conditionalLogic', readCondLogic());
                    renderProps();
                });
            });

            /* Valore cambia */
            wrap.querySelectorAll('.cl-rule-val').forEach(el => {
                el.addEventListener('change', saveCL);
                el.addEventListener('input', saveCL);
            });

            /* Aggiungi regola */
            const addBtn = document.getElementById('wpb-btn-add-cond');
            if (addBtn) {
                addBtn.addEventListener('click', () => {
                    const cl = Object.assign({}, f.conditionalLogic || { enabled: true, action: 'show', logicType: 'all' });
                    cl.rules = [...(cl.rules || []), { fieldName: '', operator: '=', value: '' }];
                    updateField(f.id, 'conditionalLogic', cl);
                    renderProps();
                });
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

                // ── Modalità traduzione ──────────────────────────────────
                if (IS_TRANSLATION && ML_ACTIVE && EDIT_ID) {
                    saveBtn.disabled = true;
                    saveBtn.textContent = 'Salvataggio…';

                    // Raccoglie solo i campi traducibili dai campi attuali
                    const fieldsI18n = fields.map(f => ({
                        label: f.label || '',
                        placeholder: f.placeholder || '',
                        error_message: f.error_message || '',
                        options: Array.isArray(f.options) ? f.options : [],
                    }));

                    const s = readSettings();
                    const settingsI18n = {
                        submit_label: s.submit_label || '',
                        redirect_url: s.redirect_url || '',
                        success_message: s.success_message || '',
                        mailup_list_id: s.mailup_list_id || '',
                        mailup_group_id: s.mailup_group_id || '',
                        notifications: (s.notifications || []).map(n => ({
                            subject: n.subject || '',
                            body: n.body || '',
                            reply_to: n.reply_to || '',
                            from_name: n.from_name || '',
                            to: n.to || '',
                        })),
                    };

                    const body = new URLSearchParams({
                        action: 'wpb_forms_save_translation',
                        nonce: NONCE,
                        form_id: String(EDIT_ID),
                        lang: currentEditorLang,
                        fields_i18n: JSON.stringify(fieldsI18n),
                        settings_i18n: JSON.stringify(settingsI18n),
                    });

                    try {
                        const res = await fetch(AJAX, { method: 'POST', body });
                        const data = await res.json();
                        if (data.success) {
                            toast('✓ Traduzione salvata!');
                        } else {
                            toast(data.data || 'Errore durante il salvataggio.', 'error');
                        }
                    } catch (e) {
                        toast('Errore di rete.', 'error');
                    } finally {
                        saveBtn.disabled = false;
                        saveBtn.textContent = 'Salva traduzione';
                    }
                    return;
                }

                // ── Modalità normale (form principale) ──────────────────
                const title = document.getElementById('wpb-form-title').value.trim();
                if (!title) { toast('Inserisci un nome per il form.', 'error'); return; }

                saveBtn.disabled = true;
                saveBtn.textContent = 'Salvataggio…';

                const body = new URLSearchParams({
                    action: 'wpb_forms_save',
                    nonce: NONCE,
                    form_id: String(EDIT_ID),
                    title,
                    fields: JSON.stringify(fields.map(({ id: _id, ...rest }) => rest)),
                    settings: JSON.stringify(readSettings()),
                });

                try {
                    const res = await fetch(AJAX, { method: 'POST', body });
                    const data = await res.json();
                    if (data.success) {
                        toast('✓ ' + (data.data.message || 'Salvato!'));
                        if (!EDIT_ID && data.data.id) {
                            setTimeout(() => {
                                location.href = '<?php echo esc_js(admin_url("admin.php?page=wp-barrio-forms&tab=builder&edit_id=")); ?>' + data.data.id;
                            }, 800);
                        }
                    } else {
                        toast(data.data || 'Errore durante il salvataggio.', 'error');
                    }
                } catch (e) {
                    toast('Errore di rete.', 'error');
                } finally {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Salva form';
                }
            });
        }


        renderCanvas();
        renderProps();

        // ── Translation mode: blocca interazioni non consentite ──
        if (IS_TRANSLATION) {

            // Funzione che rimuove draggable da tutte le card presenti nel canvas
            function lockCardDrag() {
                document.querySelectorAll('#wpb-drop-zone .wpb-field-card').forEach(function (c) {
                    c.draggable = false;
                    c.removeAttribute('draggable');
                });
            }

            // 1. Disabilita drag: sovrascrivi renderCanvas E applica subito dopo il primo render
            var _origRenderCanvas = renderCanvas;
            renderCanvas = function () {
                _origRenderCanvas.apply(this, arguments);
                lockCardDrag();
            };
            // Applica anche sulle card già presenti al caricamento
            lockCardDrag();
            // Observer sul drop-zone per bloccare qualsiasi card aggiunta dinamicamente
            new MutationObserver(lockCardDrag)
                .observe(document.getElementById('wpb-drop-zone') || document.body,
                    { childList: true });

            // 2. MutationObserver su wpb-props-inner: quando il pannello proprietà
            //    viene riscritto (click su card), disabilita i campi non traducibili
            var _propsInner = document.getElementById('wpb-props-inner');
            if (_propsInner) {
                new MutationObserver(function () {
                    // Disabilita via JS (oltre al CSS) per sicurezza
                    ['prop-name', 'prop-min', 'prop-max', 'prop-format'].forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el) { el.disabled = true; el.style.pointerEvents = 'none'; }
                    });
                    var req = document.getElementById('prop-required');
                    if (req) { req.disabled = true; }
                    // Disabilita tutti gli input/select/button nella sezione logica condizionale
                    var cond = _propsInner.querySelector('.wpb-cond-section');
                    if (cond) cond.querySelectorAll('input,select,button,textarea').forEach(function (el) { el.disabled = true; });
                }).observe(_propsInner, { childList: true });
            }

            // 3. Disabilita pulsanti impostazioni non traducibili (via JS oltre al CSS)
            document.querySelectorAll('.wpb-ast-btn').forEach(function (btn) { btn.disabled = true; });
            ['wpb-setting-display-page-info',
                'wpb-setting-mailup-enabled',
                'wpb-setting-mailup-email-field',
                'wpb-setting-mailup-optin-field',
                'wpb-mailup-add-map-row'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) el.disabled = true;
                });
        }

    })();
</script>
<?php
}


/* ═══════════════════════════════════════════════════════════════
   PAGINA READ-ONLY REGISTRAZIONI (Editor)
   ═══════════════════════════════════════════════════════════════ */

/* ─────────────────────────────────────────
   MENU: voce "Registrazioni" per editor
   (visibile solo a chi NON è admin, gli admin usano la pagina Forms completa)
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    // Per gli editor: voce nel menu principale
    if (!current_user_can('manage_options') && current_user_can('edit_others_posts')) {
        add_menu_page(
            'Registrazioni Form — WP Barrio',
            'Registrazioni',
            'edit_others_posts',
            'wp-barrio-submissions',
            'wpb_forms_submissions_page',
            'dashicons-list-view',
            81
        );
        return;
    }

    // Per gli admin: pagina nascosta sotto wp-barrio (raggiungibile via link diretto)
    if (current_user_can('manage_options')) {
        add_submenu_page(
            'wp-barrio',
            'Registrazioni Form — WP Barrio',
            'Registrazioni',
            'manage_options',
            'wp-barrio-submissions',
            'wpb_forms_submissions_page'
        );
    }
}, 14);

// Nasconde la voce dal menu laterale per gli admin (è accessibile solo via link)
add_action('admin_head', function () {
    if (current_user_can("manage_options")) {
        echo '<style>#adminmenu a[href="admin.php?page=wp-barrio-submissions"]{display:none!important}</style>';
    }
});

/* ─────────────────────────────────────────
   PAGINA READ-ONLY: lista form → submissions → dettaglio
   ───────────────────────────────────────── */
function wpb_forms_submissions_page()
{
    if (!current_user_can('edit_others_posts') && !current_user_can('manage_options')) {
        wp_die('Non hai i permessi per accedere a questa pagina.');
    }

    $nonce = wp_create_nonce('wpb_forms_nonce');
    $forms = wpb_forms_get_all();
    $view_id = isset($_GET['view_id']) ? intval($_GET['view_id']) : 0;
    $view_form = $view_id ? wpb_forms_get($view_id) : null;

    $ml_active = function_exists('wpb_multilang_is_enabled') && wpb_multilang_is_enabled();
    $ml_languages = $ml_active && function_exists('wpb_multilang_languages_with_flags') ? wpb_multilang_languages_with_flags() : [];
    ?>
<style>
    #wpb-subs-wrap {
        max-width: 1200px
    }

    .wpb-subs-toolbar {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 14px;
        flex-wrap: wrap
    }

    .wpb-subs-reg-count {
        font-size: 13px;
        color: #6b7280
    }

    .wpb-subs-spinner {
        display: inline-block;
        width: 18px;
        height: 18px;
        border: 2px solid #e5e7eb;
        border-top-color: #111827;
        border-radius: 50%;
        animation: wpb-spin .6s linear infinite
    }

    @keyframes wpb-spin {
        to {
            transform: rotate(360deg)
        }
    }

    .wpb-subs-reg-table-wrap {
        overflow-x: auto
    }

    .wpb-subs-reg-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px
    }

    .wpb-subs-reg-table th {
        background: #f3f4f6;
        padding: 8px 12px;
        text-align: left;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .3px;
        color: #374151;
        border-bottom: 1px solid #e5e7eb;
        white-space: nowrap
    }

    .wpb-subs-reg-table td {
        padding: 8px 12px;
        border-bottom: 1px solid #f3f4f6;
        max-width: 220px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap
    }

    .wpb-subs-reg-table tr:last-child td {
        border-bottom: none
    }

    .wpb-subs-reg-table tr:hover td {
        background: #f9fafb
    }

    .wpb-subs-reg-table tbody tr {
        cursor: pointer
    }

    .wpb-subs-reg-cell-empty {
        color: #9ca3af;
        font-style: italic
    }

    .wpb-subs-reg-date {
        font-size: 12px;
        color: #6b7280
    }

    .wpb-check-on {
        color: #059669;
        font-weight: 600
    }

    .wpb-check-off {
        color: #9ca3af
    }

    .wpb-check-summary {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 600;
        background: #f3f4f6;
        color: #6b7280
    }

    /* Colonne picker */
    .wpb-col-picker {
        position: relative
    }

    .wpb-col-picker-panel {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        z-index: 99;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        box-shadow: 0 3px 10px rgba(0, 0, 0, .1);
        padding: 10px 14px;
        min-width: 200px
    }

    .wpb-col-picker-panel.open {
        display: block
    }

    .wpb-col-picker-panel label {
        display: flex;
        align-items: center;
        gap: 7px;
        padding: 4px 0;
        font-size: 13px;
        cursor: pointer;
        white-space: nowrap
    }

    /* Bulk */
    .wpb-subs-reg-table th:first-child,
    .wpb-subs-reg-table td:first-child {
        width: 32px;
        padding-right: 4px
    }

    .wpb-bulk-bar {
        display: none;
        align-items: center;
        gap: 10px;
        padding: 8px 12px;
        background: #f0f6fc;
        border: 1px solid #c6d9ef;
        border-radius: 8px;
        margin-bottom: 10px;
        font-size: 13px
    }

    .wpb-bulk-bar.visible {
        display: flex
    }

    /* Dettaglio */
    .wpb-subs-detail-wrap {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        overflow: hidden
    }

    .wpb-subs-detail-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 20px;
        border-bottom: 1px solid #f3f4f6;
        background: #fafafa
    }

    .wpb-subs-detail-header h3 {
        margin: 0;
        font-size: 15px
    }

    .wpb-subs-detail-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        padding: 14px 20px;
        font-size: 12px;
        color: #6b7280;
        border-bottom: 1px solid #f3f4f6;
        background: #f9f9f9
    }

    .wpb-subs-detail-fields {
        padding: 0
    }

    .wpb-subs-detail-row {
        display: flex;
        border-bottom: 1px solid #f3f4f6;
        font-size: 13px
    }

    .wpb-subs-detail-row:last-child {
        border-bottom: none
    }

    .wpb-subs-detail-label {
        width: 200px;
        min-width: 160px;
        padding: 12px 20px;
        background: #fafafa;
        font-weight: 600;
        color: #374151;
        border-right: 1px solid #f3f4f6
    }

    .wpb-subs-detail-value {
        flex: 1;
        padding: 12px 20px;
        word-break: break-word
    }

    .wpb-subs-detail-empty {
        color: #9ca3af;
        font-style: italic
    }

    .wpb-sub-count {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 600;
        background: #f3f4f6;
        color: #6b7280
    }

    .wpb-sub-count.has-subs {
        background: #e7f5e8;
        color: #059669
    }

    .wpb-subs-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px
    }

    .wpb-subs-table th {
        background: #f3f4f6;
        padding: 9px 14px;
        text-align: left;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .4px;
        color: #374151;
        border-bottom: 1px solid #e5e7eb
    }

    .wpb-subs-table td {
        padding: 10px 14px;
        border-bottom: 1px solid #f3f4f6;
        vertical-align: middle
    }

    .wpb-subs-table tr:last-child td {
        border-bottom: none
    }

    .wpb-subs-table tr:hover td {
        background: #fafafa
    }

    .wpb-subs-table-wrap {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        overflow: hidden
    }
</style>

<div class="wrap wpb-page" id="wpb-subs-wrap">
    <div class="wpb-frm-header">
        <div class="wpb-frm-header-icon"><span class="dashicons dashicons-list-view"></span></div>
        <div>
            <h1>Registrazioni Form</h1>
            <p class="wpb-frm-sub">Elenco completo delle sottomissioni ricevute</p>
        </div>
    </div>
    <hr class="wpb-frm-divider">

    <?php if ($view_form): ?>
    <!-- ═══ REGISTRAZIONI DI UN FORM ═══ -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-forms')); ?>" class="wpb-frm-btn-sm-ghost">←
            Tutti i form</a>
        <strong style="font-size:14px;"><?php echo esc_html($view_form->title); ?></strong>
    </div>

    <!-- Toolbar: count + filtro lingua + colonne + bulk bar -->
    <div class="wpb-subs-toolbar">
        <span class="wpb-subs-reg-count" id="wpb-subs-count"></span>

        <?php if ($ml_active && !empty($ml_languages)): ?>
        <select id="wpb-lang-filter" style="font-size:13px;">
            <option value="">— Tutte le lingue —</option>
            <?php foreach ($ml_languages as $lc => $ll): ?>
            <option value="<?php echo esc_attr($lc); ?>"><?php echo esc_html($ll); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <div class="wpb-col-picker">
            <button type="button" class="wpb-frm-btn-sm-ghost" id="wpb-col-picker-btn">
                <span class="dashicons dashicons-visibility"
                    style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
                Colonne
            </button>
            <div class="wpb-col-picker-panel" id="wpb-col-picker-panel">
                <p style="font-size:11px;font-weight:700;text-transform:uppercase;color:#646970;margin:0 0 6px;">Colonne
                    visibili</p>
                <div id="wpb-col-options"><!-- popolato via JS --></div>
            </div>
        </div>

        <button type="button" class="wpb-frm-btn-sm-ghost" id="wpb-select-all-btn" style="display:none;">
            Seleziona tutto
        </button>
    </div>

    <div class="wpb-bulk-bar" id="wpb-bulk-bar">
        <span id="wpb-bulk-count"></span>
        <button type="button" class="wpb-frm-btn-sm-danger" id="wpb-bulk-delete-btn">
            <span class="dashicons dashicons-trash"
                style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span>
            Elimina selezionati
        </button>
        <button type="button" class="wpb-frm-btn-sm-ghost" id="wpb-bulk-cancel-btn">Annulla</button>
    </div>

    <div id="wpb-subs-content"><span class="wpb-subs-spinner"></span> Caricamento…</div>

    <script>
        (function () {
            const AJAX = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            const NONCE = '<?php echo esc_js($nonce); ?>';
            const FORM_ID = <?php echo intval($view_id); ?>;
            const ML_LANGUAGES = <?php echo wp_json_encode($ml_languages); ?>;
            const ML_ACTIVE = <?php echo wp_json_encode($ml_active); ?>;

            let subsData = [], formFields = [], filteredData = [];
            let visibleCols = [];   // nomi campi visibili (oltre a #, lingua, data)
            let selectedIds = new Set();

            function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

            function formatCheckSummary(val) {
                if (typeof val !== 'string' || !/:\s*(true|false)(\s*\||$)/.test(val)) return null;
                const parts = val.split('|').map(p => p.trim()).filter(Boolean);
                let total = 0, on = 0;
                parts.forEach(p => { const m = p.match(/^(.+?):\s*(true|false)$/); if (m) { total++; if (m[2] === 'true') on++; } });
                return '<span class="wpb-check-summary">' + on + '/' + total + '</span>';
            }
            function formatCheckVal(val) {
                if (typeof val !== 'string' || !/:\s*(true|false)(\s*\||$)/.test(val)) return null;
                return val.split('|').map(p => p.trim()).filter(Boolean).map(part => {
                    const m = part.match(/^(.+?):\s*(true|false)$/);
                    if (!m) return '';
                    const cls = m[2] === 'true' ? 'wpb-check-on' : 'wpb-check-off';
                    const icon = m[2] === 'true' ? '✔' : '✘';
                    return '<div class="' + cls + '">' + icon + ' ' + esc(m[1].trim()) + '</div>';
                }).join('');
            }

            // ── Carica submissions ──────────────────────────────────────────
            function loadSubmissions() {
                fetch(AJAX, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'wpb_forms_get_submissions', nonce: NONCE, form_id: FORM_ID })
                })
                    .then(r => r.json())
                    .then(res => {
                        if (!res.success) { document.getElementById('wpb-subs-content').innerHTML = '<p>' + esc(res.data || 'Errore') + '</p>'; return; }
                        subsData = res.data.subs || [];
                        formFields = (res.data.fields || []).filter(f => f.type !== 'submit' && f.type !== 'page_break');
                        // Colonne default: primi 2 campi
                        visibleCols = formFields.slice(0, 2).map(f => f.name);
                        buildColPicker();
                        applyFilter();
                    })
                    .catch(() => { document.getElementById('wpb-subs-content').innerHTML = '<p>Errore di rete.</p>'; });
            }

            // ── Col picker ──────────────────────────────────────────────────
            function buildColPicker() {
                const wrap = document.getElementById('wpb-col-options');
                if (!wrap) return;
                wrap.innerHTML = '';
                formFields.forEach(f => {
                    const lbl = document.createElement('label');
                    const cb = document.createElement('input');
                    cb.type = 'checkbox';
                    cb.value = f.name;
                    cb.checked = visibleCols.includes(f.name);
                    cb.addEventListener('change', function () {
                        if (this.checked) {
                            if (!visibleCols.includes(this.value)) visibleCols.push(this.value);
                        } else {
                            visibleCols = visibleCols.filter(v => v !== this.value);
                        }
                        renderList();
                    });
                    lbl.appendChild(cb);
                    lbl.appendChild(document.createTextNode(' ' + (f.label || f.name)));
                    wrap.appendChild(lbl);
                });
            }

            document.getElementById('wpb-col-picker-btn').addEventListener('click', function (e) {
                e.stopPropagation();
                document.getElementById('wpb-col-picker-panel').classList.toggle('open');
            });
            document.addEventListener('click', function () {
                document.getElementById('wpb-col-picker-panel').classList.remove('open');
            });
            document.getElementById('wpb-col-picker-panel').addEventListener('click', function (e) { e.stopPropagation(); });

            // ── Filtro lingua ───────────────────────────────────────────────
            const langFilter = document.getElementById('wpb-lang-filter');
            if (langFilter) {
                langFilter.addEventListener('change', applyFilter);
            }

            function applyFilter() {
                const lang = langFilter ? langFilter.value : '';
                filteredData = lang ? subsData.filter(s => s.lang === lang) : subsData.slice();
                selectedIds.clear();
                updateBulkBar();
                renderList();
            }

            // ── Render lista ────────────────────────────────────────────────
            function renderList() {
                const content = document.getElementById('wpb-subs-content');
                const countEl = document.getElementById('wpb-subs-count');
                if (countEl) countEl.textContent = filteredData.length + ' registrazion' + (filteredData.length === 1 ? 'e' : 'i');

                const selectAllBtn = document.getElementById('wpb-select-all-btn');
                if (selectAllBtn) selectAllBtn.style.display = filteredData.length ? '' : 'none';

                if (!filteredData.length) {
                    content.innerHTML = '<p style="color:#646970;">Nessuna registrazione.</p>';
                    return;
                }

                // Header
                let thead = '<tr><th><input type="checkbox" id="wpb-check-all"></th><th>#</th>';
                visibleCols.forEach(name => {
                    const f = formFields.find(f => f.name === name);
                    thead += '<th>' + esc(f ? (f.label || f.name) : name) + '</th>';
                });
                if (ML_ACTIVE) thead += '<th>Lingua</th>';
                thead += '<th>Data</th><th></th></tr>';

                // Rows
                let tbody = '';
                filteredData.forEach((sub) => {
                    const checked = selectedIds.has(sub.id);
                    tbody += '<tr data-id="' + sub.id + '">';
                    tbody += '<td onclick="event.stopPropagation()"><input type="checkbox" class="wpb-sub-check" value="' + sub.id + '"' + (checked ? ' checked' : '') + '></td>';
                    tbody += '<td>' + sub.id + '</td>';

                    const d = sub.data || {};
                    visibleCols.forEach(name => {
                        const cell = d[name];
                        let val = cell ? cell.value : null;
                        if (Array.isArray(val)) val = val.join(', ');
                        const empty = val === null || val === undefined || val === '';
                        const summary = !empty ? formatCheckSummary(String(val)) : null;
                        tbody += empty
                            ? '<td class="wpb-subs-reg-cell-empty">—</td>'
                            : '<td title="' + esc(String(val)) + '">' + (summary || esc(String(val))) + '</td>';
                    });

                    if (ML_ACTIVE) {
                        const lc = sub.lang || '';
                        const ll = lc ? (ML_LANGUAGES[lc] || lc.toUpperCase()) : '—';
                        tbody += '<td><span style="font-size:11px;background:#f0f0f1;padding:1px 6px;border-radius:2px;font-weight:600;">' + esc(ll) + '</span></td>';
                    }

                    tbody += '<td><span class="wpb-subs-reg-date">' + esc(sub.created_at) + '</span></td>';
                    tbody += '<td onclick="event.stopPropagation()" style="width:32px;padding:4px 8px;">'
                        + '<button type="button" class="wpb-frm-btn-sm-danger wpb-del-single" data-id="' + sub.id + '" title="Elimina">'
                        + '<span class="dashicons dashicons-trash" style="font-size:12px;width:12px;height:12px;"></span>'
                        + '</button></td>';
                    tbody += '</tr>';
                });

                content.innerHTML = '<div class="wpb-subs-reg-table-wrap"><table class="wpb-subs-reg-table"><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table></div>';

                // Check-all
                const checkAll = document.getElementById('wpb-check-all');
                if (checkAll) {
                    checkAll.addEventListener('change', function () {
                        document.querySelectorAll('.wpb-sub-check').forEach(cb => {
                            cb.checked = this.checked;
                            const id = parseInt(cb.value);
                            if (this.checked) selectedIds.add(id); else selectedIds.delete(id);
                        });
                        updateBulkBar();
                    });
                    checkAll.checked = filteredData.length > 0 && filteredData.every(s => selectedIds.has(s.id));
                }

                // Singole checkbox
                document.querySelectorAll('.wpb-sub-check').forEach(cb => {
                    cb.addEventListener('change', function () {
                        const id = parseInt(this.value);
                        if (this.checked) selectedIds.add(id); else selectedIds.delete(id);
                        updateBulkBar();
                        const allCb = document.getElementById('wpb-check-all');
                        if (allCb) allCb.checked = filteredData.every(s => selectedIds.has(s.id));
                    });
                });

                // Click riga → dettaglio
                content.querySelectorAll('.wpb-subs-reg-table tbody tr').forEach(row => {
                    row.addEventListener('click', () => {
                        const id = parseInt(row.dataset.id);
                        const idx = filteredData.findIndex(s => s.id === id);
                        if (idx !== -1) showDetail(idx);
                    });
                });

                // Delete singolo
                content.querySelectorAll('.wpb-del-single').forEach(btn => {
                    btn.addEventListener('click', function () {
                        const id = parseInt(this.dataset.id);
                        if (!confirm('Eliminare questa registrazione?')) return;
                        deleteSubmissions([id], () => {
                            subsData = subsData.filter(s => s.id !== id);
                            applyFilter();
                        });
                    });
                });
            }

            // ── Bulk delete ─────────────────────────────────────────────────
            function updateBulkBar() {
                const bar = document.getElementById('wpb-bulk-bar');
                const cnt = document.getElementById('wpb-bulk-count');
                if (!bar) return;
                if (selectedIds.size > 0) {
                    bar.classList.add('visible');
                    cnt.textContent = selectedIds.size + ' selezionat' + (selectedIds.size === 1 ? 'a' : 'e');
                } else {
                    bar.classList.remove('visible');
                }
            }

            document.getElementById('wpb-bulk-delete-btn').addEventListener('click', function () {
                if (!selectedIds.size) return;
                if (!confirm('Eliminare ' + selectedIds.size + ' registrazioni?')) return;
                deleteSubmissions(Array.from(selectedIds), () => {
                    const ids = Array.from(selectedIds);
                    subsData = subsData.filter(s => !ids.includes(s.id));
                    selectedIds.clear();
                    updateBulkBar();
                    applyFilter();
                });
            });

            document.getElementById('wpb-bulk-cancel-btn').addEventListener('click', function () {
                selectedIds.clear();
                updateBulkBar();
                document.querySelectorAll('.wpb-sub-check').forEach(cb => cb.checked = false);
                const allCb = document.getElementById('wpb-check-all');
                if (allCb) allCb.checked = false;
            });

            // Seleziona tutto (anche filtrati)
            const selAllBtn = document.getElementById('wpb-select-all-btn');
            if (selAllBtn) {
                selAllBtn.addEventListener('click', function () {
                    filteredData.forEach(s => selectedIds.add(s.id));
                    document.querySelectorAll('.wpb-sub-check').forEach(cb => cb.checked = true);
                    const allCb = document.getElementById('wpb-check-all');
                    if (allCb) allCb.checked = true;
                    updateBulkBar();
                });
            }

            function deleteSubmissions(ids, onSuccess) {
                fetch(AJAX, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'wpb_forms_delete_submissions',
                        nonce: NONCE,
                        form_id: FORM_ID,
                        ids: JSON.stringify(ids),
                    })
                })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) onSuccess();
                        else alert(res.data || 'Errore durante l\'eliminazione.');
                    })
                    .catch(() => alert('Errore di rete.'));
            }

            // ── Dettaglio registrazione ─────────────────────────────────────
            function showDetail(idx) {
                const sub = filteredData[idx]; if (!sub) return;
                const content = document.getElementById('wpb-subs-content');

                let fieldsHtml = '';
                formFields.filter(f => f.type !== 'file').forEach(f => {
                    const key = f.name || '';
                    const cell = (sub.data || {})[key];
                    let val = cell ? cell.value : null;
                    if (Array.isArray(val)) val = val.join(', ');
                    const empty = val === null || val === undefined || val === '';
                    const checkHtml = !empty ? formatCheckVal(String(val)) : null;
                    const valHtml = checkHtml || (empty ? '—' : esc(String(val)));
                    fieldsHtml += '<div class="wpb-subs-detail-row"><div class="wpb-subs-detail-label">' + esc(f.label || f.name || '—') + '</div><div class="wpb-subs-detail-value' + (empty ? ' wpb-subs-detail-empty' : '') + '">' + valHtml + '</div></div>';
                });

                // Allegati
                const fileEntries = Object.entries(sub.data || {}).filter(([k, v]) => k.startsWith('_file_') && v && v.is_file);
                if (fileEntries.length) {
                    fieldsHtml += '<div style="padding:10px 20px;background:#f0f0f1;font-size:11px;font-weight:700;letter-spacing:.5px;color:#50575e;text-transform:uppercase;border-bottom:1px solid #e0e0e0;">📎 Allegati</div>';
                    fileEntries.forEach(([k, d]) => {
                        const fname = esc(d.value || '');
                        const furl = d.url ? '<a href="' + esc(d.url) + '" target="_blank" style="font-size:12px;margin-left:8px;">Scarica</a>' : '';
                        fieldsHtml += '<div class="wpb-subs-detail-row"><div class="wpb-subs-detail-label">📎</div><div class="wpb-subs-detail-value">' + fname + furl + '</div></div>';
                    });
                }

                const langCode = sub.lang || '';
                const langLabel = langCode ? (ML_LANGUAGES[langCode] || langCode.toUpperCase()) : null;

                const metaItems = [
                    '📅 Data: <strong>' + esc(sub.created_at_full || sub.created_at) + '</strong>',
                ];
                if (langLabel) metaItems.push('🌐 Lingua: <strong>' + esc(langLabel) + '</strong>');
                if (sub.ip) metaItems.push('🌍 IP: <strong>' + esc(sub.ip) + '</strong>');

                content.innerHTML =
                    '<div class="wpb-subs-detail-wrap">' +
                    '<div class="wpb-subs-detail-header">' +
                    '<h3>Registrazione #' + sub.id + '</h3>' +
                    '<div style="display:flex;gap:8px;">' +
                    '<button type="button" class="wpb-frm-btn-sm-ghost" id="wpb-detail-back">← Lista</button>' +
                    '<button type="button" class="wpb-frm-btn-sm-danger" id="wpb-detail-del">🗑 Elimina</button>' +
                    '</div></div>' +
                    '<div class="wpb-subs-detail-meta">' + metaItems.map(m => '<span>' + m + '</span>').join('') + '</div>' +
                    '<div class="wpb-subs-detail-fields">' + fieldsHtml + '</div>' +
                    '</div>';

                document.getElementById('wpb-detail-back').addEventListener('click', renderList);
                document.getElementById('wpb-detail-del').addEventListener('click', function () {
                    if (!confirm('Eliminare la registrazione #' + sub.id + '?')) return;
                    deleteSubmissions([sub.id], () => {
                        subsData = subsData.filter(s => s.id !== sub.id);
                        applyFilter();
                    });
                });
            }

            loadSubmissions();
        })();
    </script>

    <?php else: ?>
    <!-- ═══ ELENCO FORM ═══ -->
    <div class="wpb-subs-table-wrap">
        <?php if (empty($forms)): ?>
        <p style="padding:24px;color:#646970;text-align:center;">Nessun form trovato.</p>
        <?php else: ?>
        <table class="wpb-subs-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nome form</th>
                    <th>Registrazioni</th>
                    <th>Creato</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $f):
                    $subs_url = admin_url('admin.php?page=wp-barrio-submissions&view_id=' . $f->id);
                    ?>
                <tr>
                    <td style="color:#a7aaad;font-size:11px;"><?php echo intval($f->id); ?></td>
                    <td><strong><?php echo esc_html($f->title); ?></strong></td>
                    <td>
                        <span class="wpb-sub-count <?php echo $f->submissions > 0 ? 'has-subs' : ''; ?>">
                            <?php echo intval($f->submissions); ?> registrazioni
                        </span>
                    </td>
                    <td style="font-size:12px;color:#646970;">
                        <?php echo date_i18n('d/m/Y', strtotime($f->created_at)); ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url($subs_url); ?>" class="wpb-frm-btn-sm-ghost">Vedi registrazioni</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php
}