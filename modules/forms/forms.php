<?php
/**
 * Suite Barrio — Forms
 * Builder drag & drop per form di contatto personalizzati.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('SUITE_BARRIO_FORMS_VERSION', '1.0.14');
define('SUITE_BARRIO_FORMS_TABLE', 'wpb_forms');
define('SUITE_BARRIO_FORMS_SUB_TABLE', 'wpb_form_submissions');

/* ─────────────────────────────────────────
   INSTALL: crea le tabelle al primo avvio
   ───────────────────────────────────────── */
register_activation_hook(SUITE_BARRIO_PLUGIN_DIR . 'wp-alt-ai.php', 'suite_barrio_forms_install');

function suite_barrio_forms_install()
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // CREATE TABLE IF NOT EXISTS — non dipende da dbDelta
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$charset} viene da $wpdb->get_charset_collate(), valore fisso non da input utente
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- operazione di installazione/aggiornamento schema, eseguita una tantum in attivazione plugin; nessun object-cache group dedicato, il caching non ha senso su una CREATE TABLE
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

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- operazione di installazione/aggiornamento schema, eseguita una tantum in attivazione plugin; nessun object-cache group dedicato, il caching non ha senso su una CREATE TABLE
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
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

add_action('plugins_loaded', 'suite_barrio_forms_install');

// Fallback: crea le tabelle anche alla prima visita admin
add_action('admin_init', function () {
    if (!get_option('wpb_forms_db_version')) {
        suite_barrio_forms_install();
        update_option('wpb_forms_db_version', SUITE_BARRIO_FORMS_VERSION, false);
    }
});

/* ─────────────────────────────────────────
   HELPER DB
   ───────────────────────────────────────── */
function suite_barrio_forms_get_all()
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_results(
        "SELECT id, title, created_at,
                (SELECT COUNT(*) FROM {$wpdb->prefix}"
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella fisso (costante di plugin), non input utente
        . SUITE_BARRIO_FORMS_SUB_TABLE . " s WHERE s.form_id = f.id) AS submissions
         FROM {$wpdb->prefix}"
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella fisso (costante di plugin), non input utente
        . SUITE_BARRIO_FORMS_TABLE . " f
         ORDER BY id DESC"
    );
}

function suite_barrio_forms_get($id)
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_row($wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella fisso (costante di plugin), non input utente
        "SELECT * FROM {$wpdb->prefix}" . SUITE_BARRIO_FORMS_TABLE . " WHERE id = %d",
        intval($id)
    ));
}

function suite_barrio_forms_get_submissions($form_id, $limit = 50, $offset = 0)
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_results($wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella fisso (costante di plugin), non input utente
        "SELECT * FROM {$wpdb->prefix}" . SUITE_BARRIO_FORMS_SUB_TABLE . "
         WHERE form_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
        intval($form_id),
        intval($limit),
        intval($offset)
    ));
}

function suite_barrio_forms_count_submissions($form_id)
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return (int) $wpdb->get_var($wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella fisso (costante di plugin), non input utente
        "SELECT COUNT(*) FROM {$wpdb->prefix}" . SUITE_BARRIO_FORMS_SUB_TABLE . " WHERE form_id = %d",
        intval($form_id)
    ));
}

function suite_barrio_forms_get_submission($id)
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_row($wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella fisso (costante di plugin), non input utente
        "SELECT * FROM {$wpdb->prefix}" . SUITE_BARRIO_FORMS_SUB_TABLE . " WHERE id = %d",
        intval($id)
    ));
}


/* ─────────────────────────────────────────
   ENQUEUE: asset front-end del form (solo registrazione).
   Il caricamento effettivo (wp_enqueue_*) e l'aggancio del
   codice dinamico (wp_add_inline_*) avvengono in
   suite_barrio_forms_shortcode(), solo quando [suite_barrio_form] è realmente
   presente in pagina.
   ───────────────────────────────────────── */
add_action('wp_enqueue_scripts', function () {
    wp_register_style('suite-barrio-forms-frontend', false, [], SUITE_BARRIO_FORMS_VERSION);
    wp_register_script('suite-barrio-forms-frontend', false, [], SUITE_BARRIO_FORMS_VERSION, true);
});

/* ─────────────────────────────────────────
   SHORTCODE: [suite_barrio_form id="X"]
   ───────────────────────────────────────── */
add_shortcode('wpb_form', 'suite_barrio_forms_shortcode');

function suite_barrio_forms_shortcode($atts)
{
    $atts = shortcode_atts(['id' => 0], $atts);
    $form = suite_barrio_forms_get(intval($atts['id']));
    if (!$form)
        return '<p>Form non trovato.</p>';

    $fields = json_decode($form->fields, true) ?: [];
    $settings = json_decode($form->settings, true) ?: [];
    $form_id_css = 'suite-barrio-form-' . $form->id;
    $after_submit = $settings['after_submit'] ?? 'message';
    $redirect_url = esc_url($settings['redirect_url'] ?? '');
    $display_page_info = !empty($settings['display_page_info']);

    ob_start();
    ?>
    <div class="suite-barrio-form-wrap" id="<?php echo esc_attr($form_id_css); ?>">
        <form class="suite-barrio-contact-form" data-form-id="<?php echo intval($form->id); ?>" novalidate>
            <?php wp_nonce_field('suite_barrio_form_submit_' . $form->id, 'suite_barrio_nonce'); ?>
            <input type="hidden" name="action" value="suite_barrio_forms_submit">
            <input type="hidden" name="form_id" value="<?php echo intval($form->id); ?>">
            <?php if ($display_page_info): ?>
                <input type="hidden" name="_page_url" id="<?php echo esc_attr($form_id_css); ?>-page-url">
                <input type="hidden" name="_page_title" id="<?php echo esc_attr($form_id_css); ?>-page-title">
            <?php endif; ?>

            <?php
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $fid/$label/$placeholder/$req_attr sono già passati per esc_attr()/esc_html() all'assegnazione qui sotto; un secondo escaping raddoppierebbe l'encoding delle entità e altererebbe l'output per caratteri speciali. $req_star è markup HTML intenzionale (tag <span>) e non va escapato.
            foreach ($fields as $field):
                $type = $field['type'] ?? 'text';
                if ($type === 'page_break')
                    continue; // separatore multistep, non un campo HTML
                $fid = esc_attr($field['name'] ?? '');
                $label = esc_html($field['label'] ?? '');
                $required = !empty($field['required']);
                $placeholder = esc_attr($field['placeholder'] ?? '');
                $req_attr = $required ? 'required' : '';
                $req_star = $required ? ' <span class="suite-barrio-req">*</span>' : '';
                ?>
                <div class="suite-barrio-field-wrap suite-barrio-field-type-<?php echo esc_attr($type); ?>"
                    data-field-name="<?php echo $fid; ?>">
                    <label for="suite-barrio-<?php echo $fid; ?>"><?php echo $label . $req_star; ?></label>

                    <?php if ($type === 'textarea'): ?>
                        <textarea id="suite-barrio-<?php echo $fid; ?>" name="<?php echo $fid; ?>"
                            placeholder="<?php echo $placeholder; ?>" rows="5" <?php echo $req_attr; ?>></textarea>

                    <?php elseif ($type === 'select'):
                        $options = $field['options'] ?? [];
                        ?>
                        <select id="suite-barrio-<?php echo $fid; ?>" name="<?php echo $fid; ?>" <?php echo $req_attr; ?>>
                            <option value="" disabled selected hidden><?php echo esc_html($field['placeholder'] ?? 'Seleziona…'); ?>
                            </option>
                            <?php foreach ($options as $opt): ?>
                                <option value="<?php echo esc_attr($opt); ?>"><?php echo esc_html($opt); ?></option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif ($type === 'radio' || $type === 'checkbox'):
                        $options = $field['options'] ?? [];
                        ?>
                        <div class="suite-barrio-choice-group">
                            <?php foreach ($options as $opt): ?>
                                <label class="suite-barrio-choice-label">
                                    <input type="<?php echo esc_attr($type); ?>"
                                        name="<?php echo $fid . ($type === 'checkbox' ? '[]' : ''); ?>"
                                        value="<?php echo esc_attr($opt); ?>">
                                    <?php echo esc_html($opt); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($type === 'datepicker'): ?>
                        <input type="date" id="suite-barrio-<?php echo $fid; ?>" name="<?php echo $fid; ?>"
                            data-format="<?php echo esc_attr($field['format'] ?? 'dd/MM/y'); ?>" <?php echo $req_attr; ?>>

                    <?php elseif ($type === 'file'): ?>
                        <input type="file" id="suite-barrio-<?php echo $fid; ?>" name="<?php echo $fid; ?>" <?php echo $req_attr; ?>>

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
                        <input type="<?php echo esc_attr($type); ?>" id="suite-barrio-<?php echo $fid; ?>"
                            name="<?php echo $fid; ?>" placeholder="<?php echo $placeholder; ?>" <?php echo $extra_attrs; ?>             <?php echo $req_attr; ?>>
                    <?php endif; ?>
                </div>
                <?php
            endforeach;
            // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>

            <div class="suite-barrio-field-wrap suite-barrio-field-submit">
                <button type="submit" class="suite-barrio-submit-btn">
                    <?php echo esc_html($settings['submit_text'] ?? 'Invia'); ?>
                </button>
            </div>
            <div class="suite-barrio-form-msg" style="display:none;"></div>
        </form>
    </div>

    <?php ob_start(); ?>
    .suite-barrio-form-wrap {
    max-width: 600px;
    }

    .suite-barrio-form-wrap .suite-barrio-field-wrap {
    margin-bottom: 18px;
    }

    .suite-barrio-form-wrap label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 6px;
    }

    .suite-barrio-req {
    color: #d63638;
    }

    .suite-barrio-form-wrap input[type=text],
    .suite-barrio-form-wrap input[type=email],
    .suite-barrio-form-wrap input[type=tel],
    .suite-barrio-form-wrap input[type=number],
    .suite-barrio-form-wrap select,
    .suite-barrio-form-wrap textarea {
    width: 100%;
    padding: 10px 14px;
    font-size: 14px;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    background: #fff;
    box-sizing: border-box;
    transition: border-color .15s;
    }

    .suite-barrio-form-wrap input:focus,
    .suite-barrio-form-wrap select:focus,
    .suite-barrio-form-wrap textarea:focus {
    outline: none;
    border-color: #2271b1;
    box-shadow: 0 0 0 2px rgba(34, 113, 177, .15);
    }

    .suite-barrio-choice-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
    }

    .suite-barrio-choice-label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 400;
    cursor: pointer;
    }

    .suite-barrio-submit-btn {
    padding: 11px 28px;
    font-size: 15px;
    font-weight: 600;
    background: #2271b1;
    color: #fff;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background .15s;
    }

    .suite-barrio-submit-btn:hover {
    background: #135e96;
    }

    .suite-barrio-submit-btn:disabled {
    background: #a7aaad;
    cursor: not-allowed;
    }

    .suite-barrio-form-msg {
    padding: 12px 16px;
    border-radius: 4px;
    font-size: 14px;
    margin-top: 12px;
    }

    .suite-barrio-form-msg.success {
    background: #edfaef;
    border: 1px solid #00a32a;
    color: #00a32a;
    }

    .suite-barrio-form-msg.error {
    background: #fcf0f1;
    border: 1px solid #d63638;
    color: #d63638;
    }
    <?php
    $suite_barrio_form_css = ob_get_clean();
    wp_enqueue_style('suite-barrio-forms-frontend');
    wp_add_inline_style('suite-barrio-forms-frontend', $suite_barrio_form_css);
    wp_enqueue_script('suite-barrio-forms-frontend');
    ob_start();
    ?>
    (function () {
    var wrap = document.getElementById('<?php echo esc_js($form_id_css); ?>');
    if (!wrap) return;
    var form = wrap.querySelector('.suite-barrio-contact-form');
    var msgEl = wrap.querySelector('.suite-barrio-form-msg');
    var btn = wrap.querySelector('.suite-barrio-submit-btn');
    var afterSubmit = '<?php echo esc_js($after_submit); ?>';
    var redirectUrl = '<?php echo esc_js($redirect_url); ?>';
    var successMsg = '<?php echo esc_js($settings['success_message'] ?? 'Grazie! Il tuo messaggio è stato inviato.'); ?>';
    var submitLabel = btn.textContent;

    form.addEventListener('submit', function (e) {
    e.preventDefault();
    btn.disabled = true;
    btn.textContent = 'Invio in corso…';
    msgEl.className = 'suite-barrio-form-msg';
    msgEl.style.display = 'none';

    <?php if ($display_page_info): ?>
        document.getElementById('<?php echo esc_js($form_id_css); ?>-page-url').value = window.location.href;
        document.getElementById('<?php echo esc_js($form_id_css); ?>-page-title').value = document.title;
    <?php endif; ?>

    fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
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
    msgEl.className = 'suite-barrio-form-msg success';
    msgEl.style.display = 'block';
    form.reset();
    }
    } else {
    msgEl.textContent = res.data || 'Si è verificato un errore. Riprova.';
    msgEl.className = 'suite-barrio-form-msg error';
    msgEl.style.display = 'block';
    }
    })
    .catch(function () {
    msgEl.textContent = 'Errore di rete. Riprova.';
    msgEl.className = 'suite-barrio-form-msg error';
    msgEl.style.display = 'block';
    })
    .finally(function () {
    btn.disabled = false;
    btn.textContent = submitLabel;
    });
    });
    })();
    <?php
    $suite_barrio_form_js = ob_get_clean();
    wp_add_inline_script('suite-barrio-forms-frontend', $suite_barrio_form_js, 'after');
    ?>

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
        ob_start();
        ?>
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
        case '<': return v.some(function (x) { return parseFloat(x) < parseFloat(rv); }); case 'contains' : return
            v.some(function (x) { return x.indexOf(rv) !==-1; }); case 'starts_with' : return v.some(function (x) { return
            x.indexOf(rv)===0; }); case 'ends_with' : return v.some(function (x) { return x.slice(-rv.length)===rv; });
            case 'empty' : return v.every(function (x) { return x==='' ; }); case 'not_empty' : return v.some(function (x) {
            return x !=='' ; }); default: return false; } } /* ── Valuta e applica la logica di UN campo ── */ function
            evaluateField(fieldName) { var cond=FIELD_COND[fieldName]; if (!cond) return; var
            el=wrap.querySelector('[data-field-name="' + fieldName + '" ]'); if (!el) return; var rules=cond.rules || []; var
            logicType=cond.logicType; // 'all' | 'any' var action=cond.action; // 'show' | 'hide' var
            conditionsMet=rules.length> 0 && (
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
            <?php
            $suite_barrio_form_cond_js = ob_get_clean();
            wp_add_inline_script('suite-barrio-forms-frontend', $suite_barrio_form_cond_js, 'after');
    endif; ?>
        <?php
        return ob_get_clean();
}

/* ─────────────────────────────────────────
   HELPER: sostituisce {{name}} con i valori
   della submission nei campi della notifica
   ───────────────────────────────────────── */
function suite_barrio_interpolate_field_vars(string $text, array $submission_data): string
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
function suite_barrio_build_email_html(
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
function suite_barrio_forms_send_notifications(object $form, array $settings, array $submission_data, array $attachments = []): array
{
    $log = [];

    $notifications = isset($settings['notifications']) && is_array($settings['notifications'])
        ? $settings['notifications'] : [];

    $log[] = 'form_id=' . $form->id . ' notifications_count=' . count($notifications)
        . ' settings_keys=' . implode(',', array_keys($settings));

    if (empty($notifications)) {
        $log[] = 'SKIP: nessuna notifica configurata per questo form.';
        return $log;
    }

    $default_subject = sprintf('[%s] Nuova registrazione: %s', get_bloginfo('name'), $form->title);

    foreach ($notifications as $notif) {

        // ── Destinatario ─────────────────────────────────────────────────────
        $to_email_raw = suite_barrio_interpolate_field_vars(trim($notif['to_email'] ?? ''), $submission_data);
        $to_email = sanitize_email($to_email_raw);
        if (!$to_email)
            continue;

        $to_name = suite_barrio_interpolate_field_vars(sanitize_text_field($notif['to_name'] ?? ''), $submission_data);
        $to = $to_name ? "{$to_name} <{$to_email}>" : $to_email;

        // ── Oggetto ──────────────────────────────────────────────────────────
        $subject = !empty($notif['subject'])
            ? sanitize_text_field($notif['subject'])
            : $default_subject;
        $subject = suite_barrio_interpolate_field_vars($subject, $submission_data);

        // ── Titolo email ─────────────────────────────────────────────────────
        $email_title = !empty($notif['email_title'])
            ? sanitize_text_field($notif['email_title'])
            : $form->title;
        $email_title = suite_barrio_interpolate_field_vars($email_title, $submission_data);

        // ── Corpo ────────────────────────────────────────────────────────────
        $message_mode = $notif['message_mode'] ?? 'auto'; // 'auto' | 'custom'
        if ($message_mode === 'custom') {
            $raw_msg = suite_barrio_interpolate_field_vars(sanitize_textarea_field($notif['message'] ?? ''), $submission_data);
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
            $content_html .= "<p style=\"margin:16px 0 0;font-size:12px;color:#a7aaad;\">Inviato il " . current_time('d/m/Y \a\l\l\e H:i') . "</p>";

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

        $html = suite_barrio_build_email_html(
            $content_html,
            $email_title,
            $settings['email_bg_color'] ?? '#f4f4f4',
            $settings['email_header_color'] ?? '#2271b1',
            $settings['email_logo_url'] ?? '',
            $settings['domain_email'] ?? get_option('admin_email')
        );

        // ── Headers ──────────────────────────────────────────────────────────
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $from_email = sanitize_email(suite_barrio_interpolate_field_vars(trim($notif['from_email'] ?? ''), $submission_data));
        $from_name = suite_barrio_interpolate_field_vars(sanitize_text_field($notif['from_name'] ?? ''), $submission_data);
        if ($from_email) {
            $headers[] = $from_name ? "From: {$from_name} <{$from_email}>" : "From: {$from_email}";
        }

        $replyto_email = sanitize_email(suite_barrio_interpolate_field_vars(trim($notif['replyto_email'] ?? ''), $submission_data));
        $replyto_name = suite_barrio_interpolate_field_vars(sanitize_text_field($notif['replyto_name'] ?? ''), $submission_data);
        if ($replyto_email) {
            $headers[] = $replyto_name ? "Reply-To: {$replyto_name} <{$replyto_email}>" : "Reply-To: {$replyto_email}";
        }

        $cc_email = sanitize_email(suite_barrio_interpolate_field_vars(trim($notif['cc_email'] ?? ''), $submission_data));
        $cc_name = suite_barrio_interpolate_field_vars(sanitize_text_field($notif['cc_name'] ?? ''), $submission_data);
        if ($cc_email) {
            $headers[] = $cc_name ? "Bcc: {$cc_name} <{$cc_email}>" : "Bcc: {$cc_email}";
        }

        // ── Sceglie il metodo di invio: Mailjet API o wp_mail ────────────────
        $global_mj = get_option('wpb_forms_mailjet', []);
        $mj_api_key = trim($global_mj['api_key'] ?? '');
        $mj_secret_key = trim($global_mj['secret_key'] ?? '');

        if ($mj_api_key && $mj_secret_key) {
            $sent = suite_barrio_mailjet_send($mj_api_key, $mj_secret_key, $to, $subject, $html, $headers, $attachments);
            $method = 'Mailjet';
        } else {
            $sent = wp_mail($to, $subject, $html, $headers, $attachments);
            $method = 'wp_mail';
        }

        $result = $method . ' to="' . $to . '" subject="' . $subject . '" → ' . ($sent ? 'INVIATA' : 'FALLITA');
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
function suite_barrio_mailjet_send(
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
        return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $body_decoded = json_decode(wp_remote_retrieve_body($response), true);
    $status = $body_decoded['Messages'][0]['Status'] ?? 'unknown';

    if ($code !== 200) {
        return false;
    }

    return strtolower($status) === 'success';
}

/* ─────────────────────────────────────────
   AJAX: submit front-end (shortcode)
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_forms_submit', 'suite_barrio_forms_handle_submit');
add_action('wp_ajax_nopriv_suite_barrio_forms_submit', 'suite_barrio_forms_handle_submit');

function suite_barrio_forms_handle_submit()
{
    global $wpdb;

    $form_id = intval($_POST['form_id'] ?? 0);
    if (!$form_id)
        wp_send_json_error('Form non valido.');

    check_ajax_referer('suite_barrio_form_submit_' . $form_id, 'suite_barrio_nonce');

    $form = suite_barrio_forms_get($form_id);
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
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES è un array strutturato gestito da wp_handle_upload(), non sanitizzabile con sanitize_text_field
            $uploaded = $_FILES[$fid] ?? null;
            $missing = !$uploaded || empty($uploaded['name']) || $uploaded['error'] === UPLOAD_ERR_NO_FILE;
        } else {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- usato solo per il controllo di campo obbligatorio (vuoto/non vuoto), non salvato né stampato; la sanitizzazione reale avviene al momento del salvataggio più sotto
            $val = wp_unslash($_POST[$fid] ?? '');
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
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES è un array strutturato gestito da wp_handle_upload(), non sanitizzabile con sanitize_text_field
            $uploaded = $_FILES[$fid] ?? null;
            if (!$uploaded || empty($uploaded['name']) || $uploaded['error'] !== UPLOAD_ERR_OK)
                continue;

            // Validazione MIME e dimensione base
            $allowed_types = apply_filters('suite_barrio_forms_allowed_file_types', [
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
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- valore sanitizzato nella riga immediatamente successiva (array_map('sanitize_text_field', ...) o sanitize_textarea_field()) prima di qualsiasi uso; wp_unslash() qui rimuove solo gli slash aggiunti da WP e non introduce un utilizzo non sanitizzato del dato
            $val = wp_unslash($_POST[$fid] ?? '');
            $val = is_array($val)
                ? array_map('sanitize_text_field', $val)
                : sanitize_textarea_field($val);
            $submission_data[$fid] = ['label' => $label, 'value' => $val];
        }
    }

    if (!empty($settings['display_page_info'])) {
        $submission_data['_page_url'] = [
            'label' => 'URL pagina',
            'value' => sanitize_url(wp_unslash($_POST['_page_url'] ?? '')),
        ];
        $submission_data['_page_title'] = [
            'label' => 'Titolo pagina',
            'value' => sanitize_text_field(wp_unslash($_POST['_page_title'] ?? '')),
        ];
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->insert(
        $wpdb->prefix . SUITE_BARRIO_FORMS_SUB_TABLE,
        [
            'form_id' => $form_id,
            'data' => wp_json_encode($submission_data),
            'ip' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
            'user_agent' => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ],
        ['%d', '%s', '%s', '%s']
    );

    // Invia le notifiche email con gli allegati
    suite_barrio_forms_send_notifications($form, $settings, $submission_data, $attachments);

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

            suite_barrio_mailup_subscribe($mu_email, $mu_list_id, $mu_group_id, $mu_mapped_fields);
        }
    }

    wp_send_json_success(['message' => $settings['success_message'] ?? 'Grazie! Il tuo messaggio è stato inviato.']);
}

/* ─────────────────────────────────────────
   AJAX ADMIN: salva impostazioni globali (Mailjet)
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_forms_save_global_settings', function () {
    check_ajax_referer('suite_barrio_forms_nonce', 'nonce');
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
function suite_barrio_mailup_subscribe(
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

    $curl_response = wp_remote_get($urlNewsletter, [
        'timeout' => 15,
        'redirection' => 10,
        'headers' => [
            'Cookie' => 'BIGipServercu_front_80_pool=!SZdQ+v1gd8oQ8VNyTiB5XWD/TCWaDTD7AeDqgydX491rNiByeurG+QWHNKLmufuzRVmHW51J5iseiX0=',
        ],
    ]);

    // Errore di trasporto
    if (is_wp_error($curl_response)) {
        $err = 'MailUp xmlSubscribe: errore HTTP — ' . $curl_response->get_error_message();
        return new WP_Error('mailup_curl', $err);
    }

    $http_code = wp_remote_retrieve_response_code($curl_response);
    $response = wp_remote_retrieve_body($curl_response);

    // Errore HTTP
    if ($http_code < 200 || $http_code >= 300) {
        $err = "MailUp xmlSubscribe: risposta HTTP {$http_code} — " . wp_strip_all_tags($response);
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
            return new WP_Error('mailup_subscribe', $err);
        }
    }

    return true;
}

/* ─────────────────────────────────────────
   AJAX ADMIN: salva form
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_forms_save', function () {
    check_ajax_referer('suite_barrio_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;

    $id = intval($_POST['form_id'] ?? 0);
    $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON grezzo del form, decodificato con json_decode più sotto; sanitize_text_field corromperebbe il JSON
    $fields = wp_unslash($_POST['fields'] ?? '[]');
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON grezzo delle impostazioni, decodificato con json_decode più sotto; sanitize_text_field corromperebbe il JSON
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

    $table = $wpdb->prefix . SUITE_BARRIO_FORMS_TABLE;

    if ($id) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $ok = $wpdb->update(
            $table,
            ['title' => $title, 'fields' => $fields, 'settings' => $settings],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        if ($ok === false)
            wp_send_json_error('Errore DB: ' . $wpdb->last_error);
        suite_barrio_forms_revalidate_cache($id);
        if (function_exists('invalidate_vercel_cache')) {
            invalidate_vercel_cache('form_' . $id);
        }
        wp_send_json_success(['id' => $id, 'message' => 'Form salvato.']);
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
        $ok = $wpdb->insert(
            $table,
            ['title' => $title, 'fields' => $fields, 'settings' => $settings],
            ['%s', '%s', '%s']
        );
        if ($ok === false)
            wp_send_json_error('Errore DB: ' . $wpdb->last_error);
        $new_id = $wpdb->insert_id;
        suite_barrio_forms_revalidate_cache($new_id);
        if (function_exists('invalidate_vercel_cache')) {
            invalidate_vercel_cache('form_' . $id);
        }
        wp_send_json_success(['id' => $new_id, 'message' => 'Form creato.']);
    }
});

/* ─────────────────────────────────────────
   AJAX ADMIN: elimina form
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_forms_delete', function () {
    check_ajax_referer('suite_barrio_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');
    global $wpdb;
    $id = intval($_POST['form_id'] ?? 0);
    if (!$id)
        wp_send_json_error('ID non valido.');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->delete($wpdb->prefix . SUITE_BARRIO_FORMS_TABLE, ['id' => $id], ['%d']);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->delete($wpdb->prefix . SUITE_BARRIO_FORMS_SUB_TABLE, ['form_id' => $id], ['%d']);
    if (function_exists('invalidate_vercel_cache')) {
        invalidate_vercel_cache('form_' . $id);
    }
    wp_send_json_success('Form eliminato.');
});

/* ─────────────────────────────────────────
   AJAX ADMIN: carica submissions + struttura campi (tab Registrazioni)
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_forms_get_submissions', function () {
    check_ajax_referer('suite_barrio_forms_nonce', 'nonce');
    if (!current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    $form_id = intval($_POST['form_id'] ?? 0);
    if (!$form_id)
        wp_send_json_error('ID form non valido.');

    $form = suite_barrio_forms_get($form_id);
    if (!$form)
        wp_send_json_error('Form non trovato.');

    // Campi definiti nel form (struttura) — escludi page_break
    $form_fields = array_values(array_filter(
        json_decode($form->fields, true) ?: [],
        fn($f) => ($f['type'] ?? '') !== 'page_break'
    ));

    // Submissions (tutte, ordinate dalla più recente)
    $subs_raw = suite_barrio_forms_get_submissions($form_id, 200, 0);
    $total = suite_barrio_forms_count_submissions($form_id);

    $is_admin = current_user_can('manage_options');
    $subs = array_map(function ($s) use ($is_admin) {
        $entry = [
            'id' => (int) $s->id,
            'data' => json_decode($s->data, true) ?: [],
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
add_action('wp_ajax_suite_barrio_forms_delete_all_submissions', function () {
    check_ajax_referer('suite_barrio_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $form_id = intval($_POST['form_id'] ?? 0);
    if (!$form_id)
        wp_send_json_error('ID form non valido.');

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->delete($wpdb->prefix . SUITE_BARRIO_FORMS_SUB_TABLE, ['form_id' => $form_id], ['%d']);
    wp_send_json_success('Registrazioni eliminate.');
});
add_action('wp_ajax_suite_barrio_forms_delete_submission', function () {
    check_ajax_referer('suite_barrio_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');
    global $wpdb;
    $id = intval($_POST['submission_id'] ?? 0);
    if (!$id)
        wp_send_json_error('ID non valido.');
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->delete($wpdb->prefix . SUITE_BARRIO_FORMS_SUB_TABLE, ['id' => $id], ['%d']);
    wp_send_json_success('Risposta eliminata.');
});

/* ─────────────────────────────────────────
   AJAX ADMIN: rinvia notifica per una singola submission
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_forms_resend_notification', function () {
    check_ajax_referer('suite_barrio_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $submission_id = intval($_POST['submission_id'] ?? 0);
    $notif_index = intval($_POST['notification_index'] ?? -1);
    $override_email = sanitize_email(wp_unslash($_POST['override_email'] ?? ''));

    if (!$submission_id)
        wp_send_json_error('ID registrazione non valido.');

    $sub = suite_barrio_forms_get_submission($submission_id);
    if (!$sub)
        wp_send_json_error('Registrazione non trovata.');

    $form = suite_barrio_forms_get($sub->form_id);
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

    $log = suite_barrio_forms_send_notifications($form, $temp_settings, $submission_data);

    wp_send_json_success([
        'message' => 'Notifica reinviata con successo.',
        'log' => $log,
    ]);
});

/* ─────────────────────────────────────────
   AJAX ADMIN: anteprima template notifica
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_forms_preview_notification', function () {
    check_ajax_referer('suite_barrio_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $form_id = intval($_POST['form_id'] ?? 0);
    if (!$form_id)
        wp_send_json_error('ID form non valido.');

    $form = suite_barrio_forms_get($form_id);
    if (!$form)
        wp_send_json_error('Form non trovato.');

    $settings = json_decode($form->settings, true) ?: [];
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON grezzo del template di notifica, già passato per wp_unslash() e poi json_decode(); sanitize_text_field corromperebbe il JSON
    $notif_data = json_decode(wp_unslash($_POST['notif_data'] ?? '{}'), true) ?: [];

    $message_mode = $notif_data['message_mode'] ?? 'auto';
    $email_title = !empty($notif_data['email_title'])
        ? sanitize_text_field($notif_data['email_title'])
        : esc_html($form->title);

    if ($message_mode === 'custom') {
        $raw_msg = sanitize_textarea_field($notif_data['message'] ?? '');
        $content_html = $raw_msg !== ''
            ? nl2br(esc_html($raw_msg))
            : '<em style="color:#a7aaad;">Corpo del messaggio vuoto.</em>';
    } else {
        // Usa i campi passati dal JS (stato live dell'editor), con fallback a quelli salvati nel DB
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON grezzo dei campi form dallo stato live dell'editor, già passato per wp_unslash() e poi json_decode(); sanitize_text_field corromperebbe il JSON
        $live_fields = json_decode(wp_unslash($_POST['fields_data'] ?? '[]'), true);
        $form_fields = is_array($live_fields) && !empty($live_fields)
            ? $live_fields
            : (isset($settings['fields']) && is_array($settings['fields']) ? $settings['fields'] : []);

        $rows = '';
        foreach ($form_fields as $field) {
            if (($field['type'] ?? '') === 'page_break')
                continue;
            $label = esc_html($field['label'] ?? $field['name'] ?? '—');
            $name = esc_html($field['name'] ?? '');
            $rows .= "<tr>
                <td style=\"padding:8px 12px;font-size:13px;font-weight:600;color:#50575e;background:#f6f7f7;border-bottom:1px solid #e8e8e8;width:35%;\">{$label}</td>
                <td style=\"padding:8px 12px;font-size:13px;color:#a7aaad;border-bottom:1px solid #e8e8e8;\"><em style=\"font-style:italic;\">{{{$name}}}</em></td>
            </tr>";
        }
        if (!$rows) {
            $rows = "<tr><td colspan=\"2\" style=\"padding:8px 12px;font-size:13px;color:#a7aaad;\">Nessun campo configurato.</td></tr>";
        }
        $content_html = "<table style=\"width:100%;border-collapse:collapse;border:1px solid #e8e8e8;border-radius:4px;overflow:hidden;\">{$rows}</table>";
    }

    $html = suite_barrio_build_email_html(
        $content_html,
        $email_title,
        $settings['email_bg_color'] ?? '#f4f4f4',
        $settings['email_header_color'] ?? '#2271b1',
        $settings['email_logo_url'] ?? '',
        $settings['domain_email'] ?? get_option('admin_email')
    );

    wp_send_json_success(['html' => $html]);
});

/* ─────────────────────────────────────────
   VOCE DI MENU ADMIN
   ───────────────────────────────────────── */
add_action('admin_menu', function () {
    add_submenu_page(
        'suite-barrio',
        'Forms — Suite Barrio',
        'Forms',
        'manage_options',
        'suite-barrio-forms',
        'suite_barrio_forms_admin_page'
    );
}, 11);

/* ─────────────────────────────────────────
   ENQUEUE: wp.media + asset builder nella pagina Forms.
   La registrazione qui è "virtuale" (src=false): il CSS/JS
   effettivo viene agganciato con wp_add_inline_style/script()
   dentro suite_barrio_forms_admin_page(), dove sono note le variabili
   dinamiche (nonce, dati form, ecc.).
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'suite-barrio_page_suite-barrio-forms')
        return;
    wp_enqueue_media();
    wp_register_style('suite-barrio-forms-admin', false, [], SUITE_BARRIO_FORMS_VERSION);
    wp_register_script('suite-barrio-forms-admin', false, [], SUITE_BARRIO_FORMS_VERSION, true);
    wp_enqueue_style('suite-barrio-forms-admin');
    wp_enqueue_script('suite-barrio-forms-admin');
});

/* ─────────────────────────────────────────
   PAGINA ADMIN FORMS
   ───────────────────────────────────────── */
function suite_barrio_forms_admin_page()
{

    // Nonce sempre disponibile prima di qualsiasi output
    $nonce = wp_create_nonce('suite_barrio_forms_nonce');

    $forms = suite_barrio_forms_get_all();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'list';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $edit_id = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $view_id = isset($_GET['view_id']) ? intval($_GET['view_id']) : 0;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $sub_id = isset($_GET['sub_id']) ? intval($_GET['sub_id']) : 0;

    $edit_form = $edit_id ? suite_barrio_forms_get($edit_id) : null;
    $view_form = $view_id ? suite_barrio_forms_get($view_id) : null;
    $sub_detail = ($sub_id && $view_id) ? suite_barrio_forms_get_submission($sub_id) : null;
    $builder_form_id = ($tab === 'builder') ? $edit_id : 0;

    // Dati builder (usati solo nel tab builder, ma dichiarati sempre per il JS)
    $saved_fields = ($edit_form) ? $edit_form->fields : '[]';
    $saved_settings = ($edit_form) ? $edit_form->settings : '{}';

    ?>
        <?php ob_start(); ?>
        .suite-barrio-sub-count {
        display: inline-block;
        padding: 1px 9px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        background: #f0f0f1;
        color: #1d2327;
        }

        .suite-barrio-sub-count.has-subs {
        background: #edfaef;
        color: #00a32a;
        }

        /* ── BUILDER ── */
        .suite-barrio-builder-layout {
        display: grid;
        grid-template-columns: 240px 1fr 280px;
        gap: 0;
        }

        .suite-barrio-panel-fields {
        background: #f6f7f7;
        border-right: 1px solid #c3c4c7;
        overflow-y: auto;
        height: 100%;
        }

        .suite-barrio-panel-fields h3 {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #646970;
        padding: 14px 16px 10px;
        border-bottom: 1px solid #e0e0e0;
        margin: 0;
        }

        .suite-barrio-field-type-btn {
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
        color: #1d2327;
        text-align: left;
        transition: background .1s;
        }

        .suite-barrio-field-type-btn:hover {
        background: #fff;
        }

        .suite-barrio-ft-icon {
        width: 28px;
        height: 28px;
        border-radius: 4px;
        background: #e0e0e0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        flex-shrink: 0;
        }

        .suite-barrio-field-type-btn:hover .suite-barrio-ft-icon {
        background: #d0d5dd;
        }

        .suite-barrio-panel-canvas {
        padding: 20px;
        background: #fafafa;
        overflow-y: auto;
        border-right: 1px solid #c3c4c7;
        height: 100%;
        box-sizing: border-box;
        }

        .suite-barrio-canvas-title {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #646970;
        margin: 0 0 14px;
        }

        .suite-barrio-canvas-drop-zone {
        min-height: 380px;
        border: 2px dashed #c3c4c7;
        border-radius: 4px;
        padding: 10px;
        transition: border-color .15s, background .15s;
        }

        .suite-barrio-canvas-drop-zone.drag-over {
        border-color: #2271b1;
        background: rgba(34, 113, 177, .04);
        }

        .suite-barrio-canvas-empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 260px;
        color: #a7aaad;
        pointer-events: none;
        }

        .suite-barrio-canvas-empty .dashicons {
        font-size: 40px;
        width: 40px;
        height: 40px;
        }

        .suite-barrio-canvas-empty p {
        font-size: 13px;
        margin: 8px 0 0;
        }

        .suite-barrio-field-card {
        background: #fff;
        border: 1px solid #d0d5dd;
        border-radius: 3px;
        margin-bottom: 8px;
        cursor: grab;
        transition: box-shadow .1s, border-color .1s;
        }

        .suite-barrio-field-card:active {
        cursor: grabbing;
        }

        .suite-barrio-field-card.selected {
        border-color: #2271b1;
        box-shadow: 0 0 0 2px rgba(34, 113, 177, .2);
        }

        .suite-barrio-field-card.dragging {
        opacity: .4;
        }

        .suite-barrio-field-card-header {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 9px 12px;
        border-bottom: 1px solid #f0f0f1;
        }

        .suite-barrio-drag-handle {
        color: #a7aaad;
        cursor: grab;
        font-size: 16px;
        line-height: 1;
        flex-shrink: 0;
        }

        .suite-barrio-field-card-label {
        font-size: 13px;
        font-weight: 600;
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        }

        .suite-barrio-field-card-type {
        font-size: 10px;
        color: #646970;
        background: #f0f0f1;
        padding: 1px 7px;
        border-radius: 3px;
        }

        .suite-barrio-btn-remove-field {
        background: none;
        border: none;
        cursor: pointer;
        color: #d63638;
        font-size: 18px;
        padding: 0 2px;
        line-height: 1;
        flex-shrink: 0;
        opacity: .5;
        transition: opacity .1s;
        }

        .suite-barrio-btn-remove-field:hover {
        opacity: 1;
        }

        .suite-barrio-field-card-preview {
        padding: 8px 12px 10px;
        }

        .suite-barrio-field-card-preview input,
        .suite-barrio-field-card-preview textarea {
        width: 100%;
        padding: 5px 8px;
        border: 1px solid #d0d5dd;
        border-radius: 3px;
        font-size: 12px;
        color: #a7aaad;
        background: #fafafa;
        pointer-events: none;
        box-sizing: border-box;
        }

        .suite-barrio-field-card-preview textarea {
        height: 52px;
        resize: none;
        }

        /* Preview select */
        .suite-barrio-preview-select {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 5px 8px;
        border: 1px solid #d0d5dd;
        border-radius: 3px;
        background: #fafafa;
        font-size: 12px;
        color: #a7aaad;
        }

        /* Preview radio / checkbox */
        .suite-barrio-preview-choices {
        display: flex;
        flex-direction: column;
        gap: 5px;
        }

        .suite-barrio-preview-choice {
        display: flex;
        align-items: center;
        gap: 7px;
        font-size: 12px;
        color: #646970;
        }

        .suite-barrio-preview-radio,
        .suite-barrio-preview-checkbox {
        width: 13px;
        height: 13px;
        border: 1.5px solid #c3c4c7;
        flex-shrink: 0;
        background: #fff;
        }

        .suite-barrio-preview-radio {
        border-radius: 50%;
        }

        .suite-barrio-preview-checkbox {
        border-radius: 2px;
        }

        /* Preview datepicker */
        .suite-barrio-preview-date {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 5px 8px;
        border: 1px solid #d0d5dd;
        border-radius: 3px;
        background: #fafafa;
        font-size: 12px;
        color: #a7aaad;
        }

        /* Preview file */
        .suite-barrio-preview-file {
        display: flex;
        align-items: center;
        gap: 7px;
        padding: 5px 8px;
        border: 1px solid #d0d5dd;
        border-radius: 3px;
        background: #fafafa;
        font-size: 12px;
        color: #a7aaad;
        }

        .suite-barrio-panel-props {
        overflow-y: auto;
        height: 100%;
        }

        .suite-barrio-panel-props h3 {
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #646970;
        padding: 14px 16px 10px;
        border-bottom: 1px solid #e0e0e0;
        margin: 0;
        background: #f6f7f7;
        }

        .suite-barrio-props-inner {
        padding: 14px 16px;
        }

        .suite-barrio-prop-group {
        margin-bottom: 14px;
        }

        .suite-barrio-prop-group label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        color: #50575e;
        margin-bottom: 5px;
        }

        .suite-barrio-prop-group input[type=text],
        .suite-barrio-prop-group input[type=number],
        .suite-barrio-prop-group textarea,
        .suite-barrio-prop-group select {
        width: 100%;
        padding: 6px 9px;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        font-size: 12px;
        font-family: inherit;
        background: #fff;
        box-sizing: border-box;
        }

        .suite-barrio-prop-group input:focus,
        .suite-barrio-prop-group textarea:focus,
        .suite-barrio-prop-group select:focus {
        outline: none;
        border-color: #2271b1;
        box-shadow: 0 0 0 1px #2271b1;
        }

        .suite-barrio-prop-check {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 12px;
        color: #1d2327;
        cursor: pointer;
        font-weight: 400;
        }

        .suite-barrio-no-field-selected {
        text-align: center;
        padding: 40px 16px;
        color: #a7aaad;
        font-size: 13px;
        }

        .suite-barrio-no-field-selected .dashicons {
        font-size: 36px;
        width: 36px;
        height: 36px;
        }

        .suite-barrio-builder-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 16px;
        flex-wrap: wrap;
        }

        .suite-barrio-builder-title-input {
        flex: 1;
        min-width: 200px;
        padding: 7px 12px;
        font-size: 15px;
        font-weight: 600;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        font-family: inherit;
        }

        .suite-barrio-builder-title-input:focus {
        outline: none;
        border-color: #2271b1;
        box-shadow: 0 0 0 1px #2271b1;
        }

        /* Opzioni lista */
        .suite-barrio-options-list {
        display: flex;
        flex-direction: column;
        gap: 5px;
        margin-top: 6px;
        }

        .suite-barrio-option-row {
        display: flex;
        gap: 6px;
        align-items: center;
        }

        .suite-barrio-option-row input[type=text] {
        flex: 1;
        padding: 4px 7px;
        font-size: 12px;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        }

        .suite-barrio-btn-rm-opt {
        background: none;
        border: none;
        color: #d63638;
        cursor: pointer;
        font-size: 16px;
        line-height: 1;
        padding: 0;
        }

        .suite-barrio-btn-add-opt {
        font-size: 11px;
        background: none;
        border: 1px dashed #c3c4c7;
        border-radius: 3px;
        padding: 4px 10px;
        cursor: pointer;
        color: #646970;
        margin-top: 4px;
        width: 100%;
        }

        .suite-barrio-btn-add-opt:hover {
        border-color: #2271b1;
        color: #2271b1;
        }

        /* Preset opzioni (Nazioni, Province, ecc.) */
        .suite-barrio-preset-select {
        width: 100%;
        padding: 5px 7px;
        font-size: 11px;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        background: #fff;
        color: #50575e;
        margin-top: 6px;
        cursor: pointer;
        font-family: inherit;
        }

        .suite-barrio-preset-select:hover {
        border-color: #2271b1;
        color: #2271b1;
        }

        .suite-barrio-preset-select:focus {
        outline: none;
        border-color: #2271b1;
        box-shadow: 0 0 0 1px #2271b1;
        }

        .suite-barrio-preset-hint {
        font-size: 10px;
        color: #a7aaad;
        margin: 4px 0 0;
        line-height: 1.4;
        }

        /* ── Logica condizionale (Gravity Forms-style) ── */
        .suite-barrio-cond-section {
        margin-top: 16px;
        padding-top: 14px;
        border-top: 1px solid #f0f0f1;
        }

        .suite-barrio-cond-section-title {
        font-size: 11px;
        font-weight: 600;
        color: #50575e;
        text-transform: uppercase;
        letter-spacing: .4px;
        margin: 0 0 10px;
        }

        .suite-barrio-cl-enable-row {
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        margin-bottom: 10px;
        font-size: 12px;
        font-weight: 600;
        color: #1d2327;
        }

        .suite-barrio-cl-enable-row input[type=checkbox] {
        cursor: pointer;
        margin: 0;
        }

        .suite-barrio-cond-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-bottom: 8px;
        }

        /* Riga singola regola: [campo] [op] [valore] [×] */
        .suite-barrio-cond-row {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
        }

        .suite-barrio-cond-row select,
        .suite-barrio-cond-row input[type=text] {
        padding: 4px 6px;
        font-size: 11px;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
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

        .suite-barrio-btn-rm-cond {
        background: none;
        border: none;
        cursor: pointer;
        color: #d63638;
        font-size: 15px;
        line-height: 1;
        padding: 0 2px;
        flex-shrink: 0;
        opacity: .6;
        }

        .suite-barrio-btn-rm-cond:hover {
        opacity: 1;
        }

        .suite-barrio-btn-add-cond {
        font-size: 11px;
        background: none;
        border: 1px dashed #c3c4c7;
        border-radius: 3px;
        padding: 4px 10px;
        cursor: pointer;
        color: #646970;
        width: 100%;
        font-family: inherit;
        }

        .suite-barrio-btn-add-cond:hover {
        border-color: #2271b1;
        color: #2271b1;
        }

        .suite-barrio-builder-tabs {
        display: flex;
        border-bottom: 1px solid #c3c4c7;
        margin-bottom: 16px;
        }

        .suite-barrio-btab {
        padding: 8px 16px;
        font-size: 13px;
        font-family: inherit;
        background: none;
        border: none;
        border-bottom: 2px solid transparent;
        cursor: pointer;
        color: #646970;
        margin-bottom: -1px;
        }

        .suite-barrio-btab.active {
        color: #2271b1;
        border-bottom-color: #2271b1;
        font-weight: 600;
        }

        /* ── Registrazioni ── */
        .suite-barrio-reg-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
        gap: 12px;
        }

        .suite-barrio-reg-count {
        font-size: 13px;
        color: #646970;
        }

        .suite-barrio-reg-empty {
        text-align: center;
        padding: 48px 20px;
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        color: #646970;
        font-size: 13px;
        }

        .suite-barrio-reg-table-wrap {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        overflow: hidden;
        overflow-x: auto;
        width: 100%;
        }

        .suite-barrio-reg-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        min-width: 500px;
        }

        .suite-barrio-reg-table thead tr {
        background: #f0f0f1;
        }

        .suite-barrio-reg-table th {
        padding: 9px 12px;
        text-align: left;
        font-weight: 600;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .4px;
        color: #50575e;
        border-bottom: 1px solid #c3c4c7;
        white-space: nowrap;
        }

        .suite-barrio-reg-table td {
        padding: 9px 12px;
        border-bottom: 1px solid #f0f0f1;
        vertical-align: top;
        font-size: 12px;
        max-width: 220px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        }

        .suite-barrio-reg-table td.suite-barrio-reg-cell-empty {
        color: #c3c4c7;
        font-style: italic;
        }

        .suite-barrio-reg-table tr:last-child td {
        border-bottom: none;
        }

        .suite-barrio-reg-table tr:hover td {
        background: #fafafa;
        }

        .suite-barrio-reg-table tbody tr {
        cursor: pointer;
        }

        .suite-barrio-reg-table td:first-child {
        color: #a7aaad;
        font-size: 11px;
        width: 40px;
        }

        .suite-barrio-reg-table td:last-child {
        width: 60px;
        text-align: center;
        }

        .suite-barrio-reg-date {
        font-size: 11px;
        color: #646970;
        white-space: nowrap;
        }

        .suite-barrio-check-on {
        padding: 1px 0;
        color: #00a32a;
        font-weight: 600;
        }

        .suite-barrio-check-off {
        padding: 1px 0;
        color: #a7aaad;
        }

        .suite-barrio-check-summary {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 600;
        background: #f0f0f1;
        color: #646970;
        }

        /* Toggle dopo-invio */
        .suite-barrio-after-submit-toggle {
        display: flex;
        gap: 0;
        margin-bottom: 10px;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        overflow: hidden;
        }

        .suite-barrio-ast-btn {
        flex: 1;
        padding: 7px 12px;
        font-size: 12px;
        font-weight: 600;
        font-family: inherit;
        border: none;
        border-right: 1px solid #c3c4c7;
        cursor: pointer;
        background: #f6f7f7;
        color: #646970;
        transition: all .1s;
        }

        .suite-barrio-ast-btn:last-child {
        border-right: none;
        }

        .suite-barrio-ast-btn.active {
        background: #2271b1;
        color: #fff;
        }

        /* Submissions */
        .suite-barrio-sub-table-wrap {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        overflow: hidden;
        overflow-x: auto;
        }

        .suite-barrio-sub-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        min-width: 500px;
        }

        .suite-barrio-sub-table th {
        background: #f0f0f1;
        padding: 8px 12px;
        text-align: left;
        font-weight: 600;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .4px;
        color: #50575e;
        border-bottom: 1px solid #c3c4c7;
        }

        .suite-barrio-sub-table td {
        padding: 9px 12px;
        border-bottom: 1px solid #f0f0f1;
        vertical-align: top;
        font-size: 12px;
        }

        .suite-barrio-sub-table tr:last-child td {
        border-bottom: none;
        }

        .suite-barrio-sub-table tr:hover td {
        background: #fafafa;
        }

        /* Dettaglio submission */
        .suite-barrio-sub-detail-wrap {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        max-width: 760px;
        width: 60%;
        }

        .suite-barrio-sub-detail-header {
        padding: 16px 20px;
        border-bottom: 1px solid #f0f0f1;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        }

        .suite-barrio-sub-detail-header h3 {
        margin: 0;
        font-size: 14px;
        color: #1d2327;
        }

        .suite-barrio-sub-detail-meta {
        padding: 12px 20px;
        background: #f6f7f7;
        border-bottom: 1px solid #f0f0f1;
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        }

        .suite-barrio-sub-detail-meta span {
        font-size: 12px;
        color: #646970;
        }

        .suite-barrio-sub-detail-meta strong {
        color: #1d2327;
        }

        .suite-barrio-sub-detail-fields {
        padding: 0;
        }

        .suite-barrio-sub-detail-row {
        display: grid;
        grid-template-columns: 200px 1fr;
        padding: 12px 20px;
        border-bottom: 1px solid #f0f0f1;
        gap: 16px;
        }

        .suite-barrio-sub-detail-row:last-child {
        border-bottom: none;
        }

        .suite-barrio-sub-detail-label {
        font-size: 12px;
        font-weight: 600;
        color: #50575e;
        text-transform: uppercase;
        letter-spacing: .3px;
        padding-top: 1px;
        }

        .suite-barrio-sub-detail-value {
        font-size: 13px;
        color: #1d2327;
        word-break: break-word;
        white-space: pre-wrap;
        }

        .suite-barrio-sub-detail-value.suite-barrio-empty {
        color: #a7aaad;
        font-style: italic;
        }

        .suite-barrio-sub-detail-sep {
        padding: 10px 20px;
        background: #f0f0f1;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .5px;
        color: #50575e;
        text-transform: uppercase;
        border-bottom: 1px solid #e0e0e0;
        }

        /* ── Rinvia notifica ── */
        .suite-barrio-resend-wrap {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        max-width: 760px;
        margin-top: 14px;
        overflow: hidden;
        width: calc(40% - 30px);
        height: 100%;
        }

        .suite-barrio-resend-header {
        padding: 14px 20px;
        border-bottom: 1px solid #f0f0f1;
        background: #f6f7f7;
        }

        .suite-barrio-resend-header h4 {
        margin: 0;
        font-size: 13px;
        color: #1d2327;
        display: flex;
        align-items: center;
        gap: 8px;
        }

        .suite-barrio-resend-body {
        padding: 20px;
        }

        .suite-barrio-resend-field {
        margin-bottom: 14px;
        }

        .suite-barrio-resend-field:last-child {
        margin-bottom: 0;
        }

        .suite-barrio-resend-field label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: #50575e;
        margin-bottom: 5px;
        }

        .suite-barrio-resend-field select,
        .suite-barrio-resend-field input[type=email] {
        width: 100%;
        max-width: 400px;
        padding: 7px 10px;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        font-size: 13px;
        font-family: inherit;
        background: #fff;
        box-sizing: border-box;
        }

        .suite-barrio-resend-field select:focus,
        .suite-barrio-resend-field input:focus {
        outline: none;
        border-color: #2271b1;
        box-shadow: 0 0 0 1px #2271b1;
        }

        .suite-barrio-resend-field .description {
        font-size: 11px;
        color: #a7aaad;
        margin: 4px 0 0;
        }

        .suite-barrio-resend-actions {
        margin-top: 16px;
        display: flex;
        align-items: center;
        gap: 12px;
        }

        .suite-barrio-resend-actions .suite-barrio-resend-result {
        font-size: 13px;
        }

        /* ── Notifiche ── */
        .suite-barrio-notif-card {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 4px;
        margin-bottom: 10px;
        overflow: hidden;
        transition: box-shadow .15s;
        }

        .suite-barrio-notif-card:hover {
        box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
        }

        .suite-barrio-notif-card.is-open {
        border-color: #2271b1;
        }

        .suite-barrio-notif-header {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        cursor: pointer;
        user-select: none;
        background: #f6f7f7;
        }

        .suite-barrio-notif-card.is-open .suite-barrio-notif-header {
        background: #f0f6fc;
        border-bottom: 1px solid #c8d8f0;
        }

        .suite-barrio-notif-header-icon {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: #2271b1;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
        flex-shrink: 0;
        }

        .suite-barrio-notif-header-info {
        flex: 1;
        min-width: 0;
        }

        .suite-barrio-notif-header-title {
        font-size: 13px;
        font-weight: 600;
        color: #1d2327;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        }

        .suite-barrio-notif-header-sub {
        font-size: 11px;
        color: #646970;
        margin-top: 2px;
        }

        .suite-barrio-notif-header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
        }

        .suite-barrio-notif-chevron {
        font-size: 11px;
        color: #646970;
        transition: transform .2s;
        display: block;
        }

        .suite-barrio-notif-card.is-open .suite-barrio-notif-chevron {
        transform: rotate(180deg);
        }

        .suite-barrio-notif-del-btn {
        background: none;
        border: none;
        cursor: pointer;
        color: #d63638;
        font-size: 16px;
        padding: 0 2px;
        line-height: 1;
        opacity: .5;
        transition: opacity .1s;
        }

        .suite-barrio-notif-del-btn:hover {
        opacity: 1;
        }

        .suite-barrio-notif-preview-btn {
        background: none;
        border: none;
        cursor: pointer;
        color: #2271b1;
        font-size: 14px;
        padding: 0 2px;
        line-height: 1;
        opacity: .6;
        transition: opacity .1s;
        }

        .suite-barrio-notif-preview-btn:hover {
        opacity: 1;
        }

        .suite-barrio-notif-preview-btn:disabled {
        cursor: default;
        opacity: .3;
        }

        .suite-barrio-notif-body {
        display: none;
        padding: 20px;
        border-top: none;
        }

        .suite-barrio-notif-card.is-open .suite-barrio-notif-body {
        display: block;
        }

        .suite-barrio-notif-section {
        margin-bottom: 20px;
        }

        .suite-barrio-notif-section:last-child {
        margin-bottom: 0;
        }

        .suite-barrio-notif-section-label {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: #a7aaad;
        margin: 0 0 10px;
        padding-bottom: 7px;
        border-bottom: 1px solid #f0f0f1;
        }

        .suite-barrio-notif-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        }

        .suite-barrio-notif-grid.single {
        grid-template-columns: 1fr;
        }

        .suite-barrio-notif-field {
        display: flex;
        flex-direction: column;
        gap: 5px;
        }

        .suite-barrio-notif-field>label {
        font-size: 11px;
        font-weight: 600;
        color: #50575e;
        }

        .suite-barrio-notif-field .opt {
        font-weight: 400;
        color: #a7aaad;
        margin-left: 4px;
        }

        .suite-barrio-notif-field input,
        .suite-barrio-notif-field textarea {
        padding: 7px 9px;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        font-size: 12px;
        font-family: inherit;
        width: 100%;
        box-sizing: border-box;
        background: #fff;
        transition: border-color .1s, box-shadow .1s;
        }

        .suite-barrio-notif-field input:focus,
        .suite-barrio-notif-field textarea:focus {
        outline: none;
        border-color: #2271b1;
        box-shadow: 0 0 0 1px #2271b1;
        }

        .suite-barrio-notif-field textarea {
        resize: vertical;
        min-height: 80px;
        }

        .suite-barrio-notif-field .hint {
        font-size: 11px;
        color: #a7aaad;
        margin: 0;
        }

        /* Variabili campo */
        .suite-barrio-field-vars {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        margin-top: 5px;
        }

        .suite-barrio-field-var-pill {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        padding: 2px 7px;
        background: #f0f6fc;
        border: 1px solid #c8d8f0;
        border-radius: 12px;
        font-size: 10px;
        color: #2271b1;
        font-weight: 600;
        cursor: pointer;
        transition: background .1s, border-color .1s;
        font-family: monospace;
        white-space: nowrap;
        }

        .suite-barrio-field-var-pill:hover {
        background: #dbeafe;
        border-color: #2271b1;
        }

        .suite-barrio-notif-empty-state {
        text-align: center;
        padding: 40px 20px;
        background: #fff;
        border: 2px dashed #e0e0e0;
        border-radius: 4px;
        color: #a7aaad;
        }

        .suite-barrio-notif-empty-state .dashicons {
        font-size: 36px;
        width: 36px;
        height: 36px;
        color: #d0d5dd;
        display: block;
        margin: 0 auto 8px;
        }

        .suite-barrio-notif-empty-state p {
        margin: 0;
        font-size: 13px;
        }

        .suite-barrio-notif-add-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 11px;
        background: #fff;
        border: 1px dashed #2271b1;
        border-radius: 4px;
        color: #2271b1;
        font-size: 13px;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        margin-top: 10px;
        transition: background .1s;
        }

        .suite-barrio-notif-add-btn:hover {
        background: #f0f6fc;
        }

        /* ── Modale conferma elimina campo ── */
        #suite-barrio-confirm-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 100000;
        background: rgba(0, 0, 0, .5);
        align-items: center;
        justify-content: center;
        }

        #suite-barrio-confirm-modal.open {
        display: flex;
        }

        .suite-barrio-modal-box {
        background: #fff;
        border-radius: 4px;
        padding: 28px 28px 22px;
        max-width: 380px;
        width: 90%;
        box-shadow: 0 8px 32px rgba(0, 0, 0, .22);
        text-align: center;
        }

        .suite-barrio-modal-box .suite-barrio-modal-icon {
        font-size: 32px;
        width: 48px;
        height: 48px;
        background: #fcf0f1;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 14px;
        }

        .suite-barrio-modal-box h3 {
        font-size: 15px;
        margin: 0 0 8px;
        color: #1d2327;
        }

        .suite-barrio-modal-box p {
        font-size: 13px;
        color: #646970;
        margin: 0 0 20px;
        line-height: 1.5;
        }

        .suite-barrio-modal-actions {
        display: flex;
        gap: 8px;
        justify-content: center;
        }


        #suite-barrio-tab-notifiche {
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

        #suite-barrio-tab-settings>div {
        max-width: none !important;
        }

        #suite-barrio-reg-content {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        }
        <?php
        $suite_barrio_admin_css = ob_get_clean();
        wp_add_inline_style('suite-barrio-forms-admin', $suite_barrio_admin_css);
        ?>

        <div id="suite-barrio-forms-wrap" class="wrap suite-barrio-wrap">

            <div class="suite-barrio-header-row">
                <div class="suite-barrio-header">
                    <div class="suite-barrio-header-icon" style="background:#0f172a;">
                        <span class="dashicons dashicons-feedback"
                            style="color:#fff;font-size:20px;width:20px;height:20px;"></span>
                    </div>
                    <div>
                        <h1>Suite Barrio <span class="suite-barrio-header-breadcrumb">/ Forms</span></h1>
                        <p class="suite-barrio-header-sub">Gestione form di contatto</p>
                    </div>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio')); ?>"
                    class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-ghost">
                    <span class="dashicons dashicons-arrow-left-alt2" style="font-size:14px;width:14px;height:14px;"></span>
                    Dashboard
                </a>
            </div>
            <hr class="suite-barrio-divider">
            <?php
            $mj_global = get_option('wpb_forms_mailjet', []);
            $mj_missing = empty(trim($mj_global['api_key'] ?? '')) || empty(trim($mj_global['secret_key'] ?? ''));
            if ($mj_missing): ?>
                <div class="suite-barrio-notice suite-barrio-notice-warning">
                    <span class="dashicons dashicons-warning"></span>
                    <span>Le credenziali Mailjet non sono configurate.
                        <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-forms&tab=settings')); ?>"
                            style="color:#b45309;font-weight:600;">Vai alle impostazioni &rarr;</a></span>
                </div>
            <?php endif; ?>

            <?php if ($tab === 'list' || $tab === 'settings'): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;gap:16px;">
                    <div class="suite-barrio-tabs" style="margin-bottom:0;border-bottom:none;">
                        <a href="?page=suite-barrio-forms"
                            class="suite-barrio-tab <?php echo $tab === 'list' ? 'active' : ''; ?>">Form</a>
                        <a href="?page=suite-barrio-forms&tab=settings"
                            class="suite-barrio-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>">Impostazioni</a>
                    </div>
                    <?php if ($tab === 'list'): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-forms&tab=builder')); ?>"
                            class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-primary">+ Nuovo form</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* ══════ IMPOSTAZIONI GLOBALI ══════ */ if ($tab === 'settings'):
                $mj_opts = get_option('wpb_forms_mailjet', []);
                ?>
                <div style="max-width:600px;">
                    <div class="suite-barrio-section-box" style="margin-bottom:20px;">
                        <div class="suite-barrio-section-head">
                            <svg width="18" height="18" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"
                                style="flex-shrink:0;">
                                <rect width="32" height="32" rx="6" fill="#FF4B32" />
                                <path d="M7 10h18v2H7zm0 5h18v2H7zm0 5h12v2H7z" fill="#fff" />
                            </svg>
                            Mailjet
                        </div>
                        <div class="suite-barrio-section-body">
                            <p style="margin:0 0 16px;font-size:12px;color:#a7aaad;">Se impostati, le email di tutti i form
                                vengono
                                inviate tramite le API Mailjet anziché tramite <code>wp_mail</code>. Lascia vuoti per usare il
                                sistema
                                di invio standard di WordPress.</p>
                            <table class="form-table" style="max-width:100%;">
                                <tbody>
                                    <tr>
                                        <th style="width:220px;"><label for="suite-barrio-global-mj-api-key">API Key
                                                (pubblica)</label></th>
                                        <td>
                                            <input type="text" id="suite-barrio-global-mj-api-key" class="large-text"
                                                value="<?php echo esc_attr($mj_opts['api_key'] ?? ''); ?>"
                                                placeholder="es. a1b2c3d4e5f6..." autocomplete="off" spellcheck="false">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="suite-barrio-global-mj-secret-key">Secret Key (privata)</label></th>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:8px;">
                                                <input type="password" id="suite-barrio-global-mj-secret-key" class="large-text"
                                                    value="<?php echo esc_attr($mj_opts['secret_key'] ?? ''); ?>"
                                                    placeholder="es. 9z8y7x6w..." autocomplete="new-password"
                                                    spellcheck="false">
                                                <button type="button"
                                                    style="background:none;border:1px solid #c3c4c7;border-radius:3px;padding:5px 9px;cursor:pointer;color:#646970;font-size:12px;white-space:nowrap;"
                                                    onclick="(function(){var i=document.getElementById('suite-barrio-global-mj-secret-key');i.type=i.type==='password'?'text':'password';})();">Mostra</button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p style="margin:16px 0 0;">
                                <button type="button" class="suite-barrio-btn suite-barrio-btn-primary"
                                    id="suite-barrio-btn-save-global">
                                    Salva impostazioni
                                </button>
                                <span id="suite-barrio-global-saved-msg"
                                    style="display:none;margin-left:10px;color:#059669;font-size:13px;">✓
                                    Salvato</span>
                            </p>
                        </div><!-- .suite-barrio-section-body -->
                    </div><!-- .suite-barrio-section-box Mailjet -->

                    <!-- MailUp card -->
                    <div class="suite-barrio-section-box" style="margin-bottom:20px;">
                        <div class="suite-barrio-section-head">
                            <svg width="18" height="18" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"
                                style="flex-shrink:0;">
                                <rect width="32" height="32" rx="6" fill="#00529C" />
                                <path d="M7 8h18v3H7zm0 6.5h18v3H7zm0 6.5h11v3H7z" fill="#fff" />
                                <circle cx="24" cy="22" r="4" fill="#F7A600" />
                            </svg>
                            MailUp
                        </div>
                        <div class="suite-barrio-section-body">
                            <p style="margin:0 0 16px;font-size:12px;color:#a7aaad;">Integrazione MailUp tramite xmlSubscribe.
                                Nessuna
                                credenziale richiesta.</p>
                            <table class="form-table" style="max-width:100%;">
                                <tbody>
                                    <tr>
                                        <th><label for="">IP Corrente</label></th>
                                        <td>
                                            <p>
                                                <?php
                                                $ip = file_get_contents('https://api4.ipify.org'); // forza IPv4
                                                echo esc_html($ip);
                                                ?>
                                                <br>
                                                <span style="margin:0 0 16px;font-size:12px;color:#a7aaad;">Inserisci questo
                                                    indirizzo
                                                    IP su MailUp per limitare le richieste a questo sito.</span>
                                            </p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p style="margin:16px 0 0;">
                                <button type="button" class="suite-barrio-btn suite-barrio-btn-primary"
                                    id="suite-barrio-btn-save-global-mu">
                                    Salva impostazioni MailUp
                                </button>
                                <span id="suite-barrio-global-saved-msg-mu"
                                    style="display:none;margin-left:10px;color:#059669;font-size:13px;">✓
                                    Salvato</span>
                            </p>
                        </div><!-- .suite-barrio-section-body -->
                    </div><!-- .suite-barrio-section-box MailUp -->
                </div>
                <?php ob_start(); ?>
                (function () {
                var btn = document.getElementById('suite-barrio-btn-save-global');
                var msg = document.getElementById('suite-barrio-global-saved-msg');
                btn.addEventListener('click', function () {
                btn.disabled = true;
                btn.textContent = 'Salvataggio…';
                var fd = new URLSearchParams({
                action: 'suite_barrio_forms_save_global_settings',
                nonce: '<?php echo esc_js($nonce); ?>',
                mailjet_api_key: document.getElementById('suite-barrio-global-mj-api-key').value.trim(),
                mailjet_secret_key: document.getElementById('suite-barrio-global-mj-secret-key').value.trim()
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
                var btnMu = document.getElementById('suite-barrio-btn-save-global-mu');
                var msgMu = document.getElementById('suite-barrio-global-saved-msg-mu');
                btnMu.addEventListener('click', function () {
                btnMu.disabled = true;
                btnMu.textContent = 'Salvataggio…';
                var fd = new URLSearchParams({
                action: 'suite_barrio_forms_save_global_settings',
                nonce: '<?php echo esc_js($nonce); ?>',
                mailjet_api_key: document.getElementById('suite-barrio-global-mj-api-key').value.trim(),
                mailjet_secret_key: document.getElementById('suite-barrio-global-mj-secret-key').value.trim()
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
                <?php
                $suite_barrio_settings_tab_js = ob_get_clean();
                wp_add_inline_script('suite-barrio-forms-admin', $suite_barrio_settings_tab_js, 'after');
                ?>

            <?php /* ══════ LISTA ══════ */ elseif ($tab === 'list'): ?>

                <div class="suite-barrio-table-wrap">
                    <?php if (empty($forms)): ?>
                        <div class="suite-barrio-empty">
                            <span class="dashicons dashicons-feedback"></span>
                            <h3>Nessun form ancora</h3>
                            <p>Crea il tuo primo form di contatto in pochi click.</p>
                            <br>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-forms&tab=builder')); ?>"
                                class="suite-barrio-btn suite-barrio-btn-primary" style="margin-top:8px;">+ Crea il primo form</a>
                        </div>
                    <?php else: ?>
                        <table class="suite-barrio-table">
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
                                    $edit_url = admin_url('admin.php?page=suite-barrio-forms&tab=builder&edit_id=' . $f->id);
                                    $subs_url = admin_url('admin.php?page=suite-barrio-forms&tab=builder&edit_id=' . $f->id . '#suite-barrio-registrazioni');
                                    ?>
                                    <tr data-form-id="<?php echo intval($f->id); ?>">
                                        <td style="color:#a7aaad;font-size:11px;"><?php echo intval($f->id); ?></td>
                                        <td>
                                            <strong>
                                                <a href="<?php echo esc_url($edit_url); ?>" style="text-decoration:none;">
                                                    <?php echo esc_html($f->title); ?>
                                                </a>
                                            </strong>
                                        </td>
                                        <td>
                                            <a href="<?php echo esc_url($subs_url); ?>" style="text-decoration:none;">
                                                <span
                                                    class="suite-barrio-sub-count <?php echo $f->submissions > 0 ? 'has-subs' : ''; ?>">
                                                    <?php echo intval($f->submissions); ?> registrazioni
                                                </span>
                                            </a>
                                        </td>
                                        <td style="font-size:12px;color:#6b7280;">
                                            <?php echo esc_html(date_i18n('d/m/Y', strtotime($f->created_at))); ?>
                                        </td>
                                        <td>
                                            <a href="<?php echo esc_url($edit_url); ?>"
                                                class="suite-barrio-btn suite-barrio-btn-ghost suite-barrio-btn-sm">Modifica</a>
                                            <button
                                                class="suite-barrio-btn suite-barrio-btn-danger suite-barrio-btn-sm suite-barrio-btn-delete-form"
                                                data-id="<?php echo intval($f->id); ?>"
                                                data-title="<?php echo esc_attr($f->title); ?>">Elimina</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

            <?php /* ══════ BUILDER ══════ */ elseif ($tab === 'builder'): ?>

                <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-forms')); ?>"
                    class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-ghost">←
                    Tutti i form</a>
                <div class="suite-barrio-builder-actions suite-barrio-cp-field-input" style="margin-top: 20px;">
                    <input type="text" id="suite-barrio-form-title" class="suite-barrio-input suite-barrio-builder-title-input"
                        style="max-width: none;" value="<?php echo esc_attr($edit_form ? $edit_form->title : ''); ?>"
                        placeholder="Nome del form…">
                    <button class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-primary"
                        id="suite-barrio-btn-save-form">Salva form</button>
                </div>

                <div class="suite-barrio-builder-tabs">
                    <button class="suite-barrio-btab active" data-tab="fields">Campi</button>
                    <button class="suite-barrio-btab" data-tab="settings">Impostazioni</button>
                    <button class="suite-barrio-btab" data-tab="notifiche">Notifiche</button>
                    <?php if ($edit_id): ?>
                        <button class="suite-barrio-btab" data-tab="registrazioni">Registrazioni</button>
                    <?php endif; ?>
                </div>

                <!-- TAB CAMPI -->
                <div id="suite-barrio-tab-fields">
                    <div class="suite-barrio-builder-layout suite-barrio-section-box">

                        <!-- Sx: tipi campo -->
                        <div class="suite-barrio-panel-fields">
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
                                <button class="suite-barrio-field-type-btn" data-type="<?php echo esc_attr($type); ?>">
                                    <span class="suite-barrio-ft-icon"><?php echo esc_html($data['icon']); ?></span>
                                    <?php echo esc_html($data['label']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <!-- Centro: canvas -->
                        <div class="suite-barrio-panel-canvas">
                            <p class="suite-barrio-canvas-title">Struttura del form — trascina per riordinare</p>
                            <div id="suite-barrio-drop-zone" class="suite-barrio-canvas-drop-zone">
                                <div class="suite-barrio-canvas-empty" id="suite-barrio-canvas-empty">
                                    <span class="dashicons dashicons-migrate"></span>
                                    <p>Clicca un tipo di campo<br>per aggiungerlo al form</p>
                                </div>
                            </div>
                        </div>

                        <!-- Dx: proprietà -->
                        <div class="suite-barrio-panel-props">
                            <h3>Proprietà campo</h3>
                            <div id="suite-barrio-props-inner" class="suite-barrio-props-inner">
                                <div class="suite-barrio-no-field-selected">
                                    <span class="dashicons dashicons-edit-large"></span>
                                    <p>Seleziona un campo<br>per modificarne le proprietà</p>
                                </div>
                            </div>
                        </div>

                    </div>
                </div><!-- #suite-barrio-tab-fields -->

                <!-- TAB IMPOSTAZIONI -->
                <div id="suite-barrio-tab-settings" style="display:none;">

                    <!-- Impostazioni generali -->
                    <div class="suite-barrio-section-box" style="max-width:540px;">
                        <div class="suite-barrio-section-head">Impostazioni generali</div>
                        <div class="suite-barrio-section-body">

                            <div style="margin-bottom:20px;">
                                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;"
                                    for="suite-barrio-setting-submit-text">Testo pulsante invio</label>
                                <input type="text" id="suite-barrio-setting-submit-text" class="regular-text"
                                    placeholder="Invia">
                            </div>

                            <div style="margin-bottom:20px;">
                                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;"
                                    for="suite-barrio-setting-display-page-info">Informazioni pagina</label>
                                <label
                                    style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer;font-size:13px;">
                                    <input type="checkbox" id="suite-barrio-setting-display-page-info" value="1">
                                    Registra URL e titolo della pagina al momento dell'invio
                                </label>
                            </div>

                            <div>
                                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Conferma di
                                    invio</label>
                                <div class="suite-barrio-after-submit-toggle" style="margin-bottom:12px;">
                                    <button type="button" class="suite-barrio-ast-btn active" data-value="message">Mostra
                                        messaggio</button>
                                    <button type="button" class="suite-barrio-ast-btn" data-value="redirect">Reindirizza a una
                                        pagina</button>
                                </div>
                                <input type="hidden" id="suite-barrio-setting-after-submit" value="message">
                                <div id="suite-barrio-ast-panel-message">
                                    <textarea id="suite-barrio-setting-success-msg" rows="3" class="large-text"
                                        placeholder="Grazie! Il tuo messaggio è stato inviato."></textarea>
                                    <p class="description" style="margin-top:4px;">Testo mostrato al visitatore dopo l'invio.
                                    </p>
                                </div>
                                <div id="suite-barrio-ast-panel-redirect" style="display:none;">
                                    <input type="url" id="suite-barrio-setting-redirect-url" class="large-text"
                                        placeholder="https://example.com/grazie">
                                    <p class="description" style="margin-top:4px;">URL della pagina di ringraziamento a cui
                                        rimandare
                                        l'utente.</p>
                                </div>
                            </div>
                        </div><!-- .suite-barrio-section-body -->
                    </div><!-- .suite-barrio-section-box impostazioni generali -->

                    <!-- MailUp per-form card -->
                    <div class="suite-barrio-section-box" style="max-width:540px;margin-top:20px;">
                        <div class="suite-barrio-section-head">
                            <svg width="18" height="18" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"
                                style="flex-shrink:0;">
                                <rect width="32" height="32" rx="6" fill="#00529C" />
                                <path d="M7 8h18v3H7zm0 6.5h18v3H7zm0 6.5h11v3H7z" fill="#fff" />
                                <circle cx="24" cy="22" r="4" fill="#F7A600" />
                            </svg>
                            Integrazione MailUp
                        </div>
                        <div class="suite-barrio-section-body">
                            <p style="margin:0 0 20px;font-size:12px;color:#a7aaad;">Iscrivi automaticamente i contatti a una
                                lista
                                MailUp al submit di questo form. Le credenziali API vanno configurate nelle <a
                                    href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-forms&tab=settings')); ?>">Impostazioni
                                    globali</a>.</p>

                            <!-- Toggle abilitazione -->
                            <div style="margin-bottom:20px;">
                                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                                    <input type="checkbox" id="suite-barrio-setting-mailup-enabled" value="1"
                                        style="width:16px;height:16px;">
                                    <span style="font-size:13px;font-weight:600;">Abilita iscrizione MailUp</span>
                                </label>
                            </div>

                            <!-- Campi extra — visibili solo se abilitato -->
                            <div id="suite-barrio-mailup-fields-wrap"
                                style="display:none;border-top:1px solid #f0f0f0;padding-top:20px;">

                                <!-- ID Lista + ID Gruppo: stessa riga -->
                                <div style="display:flex;gap:24px;margin-bottom:20px;flex-wrap:wrap;">
                                    <div style="flex:1;min-width:120px;">
                                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"
                                            for="suite-barrio-setting-mailup-list-id">ID Lista MailUp</label>
                                        <input type="number" id="suite-barrio-setting-mailup-list-id" style="width:100px;"
                                            class="small-text" value="1" min="1" placeholder="1">
                                        <p class="description" style="margin-top:4px;">Parametro <code>List</code> dell'URL
                                            xmlSubscribe.</p>
                                    </div>
                                    <div style="flex:1;min-width:120px;">
                                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"
                                            for="suite-barrio-setting-mailup-group-id">ID Gruppo MailUp</label>
                                        <input type="number" id="suite-barrio-setting-mailup-group-id" style="width:100px;"
                                            class="small-text" value="1" min="1" placeholder="1">
                                        <p class="description" style="margin-top:4px;">Parametro <code>Group</code> dell'URL
                                            xmlSubscribe.</p>
                                    </div>
                                </div>

                                <!-- Campo email -->
                                <div style="margin-bottom:20px;">
                                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"
                                        for="suite-barrio-setting-mailup-email-field">Campo email del form</label>
                                    <input type="text" id="suite-barrio-setting-mailup-email-field" class="regular-text"
                                        placeholder="email">
                                    <p class="description" style="margin-top:4px;">Lo <strong>slug</strong> del campo email di
                                        questo
                                        form. Viene usato come indirizzo del contatto iscritto. Default: <code>email</code>.</p>
                                </div>

                                <!-- Campo opt-in newsletter -->
                                <div
                                    style="margin-bottom:24px;padding:16px;background:#f6f7f7;border-radius:3px;border:1px solid #e2e4e7;">
                                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"
                                        for="suite-barrio-setting-mailup-optin-field">
                                        Campo opt-in newsletter
                                        <span style="font-weight:400;color:#a7aaad;"> (opzionale)</span>
                                    </label>
                                    <p class="description" style="margin-bottom:10px;margin-top:0;">Se specificato, l'iscrizione
                                        a
                                        MailUp avviene <strong>solo se questo campo checkbox è spuntato</strong> al momento
                                        dell'invio.
                                        Lascia vuoto per iscrivere sempre.</p>
                                    <select id="suite-barrio-setting-mailup-optin-field"
                                        style="width:100%;max-width:340px;padding:5px 8px;border:1px solid #c3c4c7;border-radius:3px;font-size:13px;">
                                        <option value="">— Iscrivi sempre —</option>
                                    </select>
                                    <p class="description" style="margin-top:6px;">Seleziona il campo <strong>checkbox</strong>
                                        del
                                        form
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
                                    <div id="suite-barrio-mailup-field-map-rows"></div>
                                    <button type="button" id="suite-barrio-mailup-add-map-row"
                                        style="margin-top:8px;background:none;border:1px dashed #c3c4c7;border-radius:3px;padding:6px 14px;cursor:pointer;font-size:12px;color:#646970;width:100%;text-align:center;">
                                        + Aggiungi mappatura campo
                                    </button>
                                    <p class="description" style="margin-top:8px;">
                                        Esempi di parametri MailUp: <code>Nome</code>, <code>Cognome</code>,
                                        <code>Telefono</code>,
                                        <code>Azienda</code>.
                                        Controlla i nomi esatti in <em>MailUp → Impostazioni → Profilo iscritto → Campi
                                            personalizzati</em>.
                                    </p>
                                </div>

                            </div><!-- #suite-barrio-mailup-fields-wrap -->
                        </div><!-- .suite-barrio-section-body -->
                    </div><!-- .suite-barrio-section-box MailUp per-form -->

                </div><!-- #suite-barrio-tab-settings -->

                <!-- TAB NOTIFICHE -->
                <div id="suite-barrio-tab-notifiche" style="display:none;">

                    <!-- Impostazioni globali email -->
                    <div class="email-style-settings suite-barrio-section-box" style="max-width:600px;margin-bottom:20px;">
                        <div class="suite-barrio-section-head">Stile email</div>
                        <div class="suite-barrio-section-body" style="padding:16px 22px;">
                            <table class="form-table" style="max-width:100%;">
                                <tbody>
                                    <tr>
                                        <th style="width:220px;"><label for="suite-barrio-setting-email-bg-color">Colore di
                                                sfondo</label>
                                        </th>
                                        <td style="display:flex;align-items:center;gap:10px;">
                                            <input type="color" id="suite-barrio-setting-email-bg-color" value="#f4f4f4"
                                                style="width:44px;height:34px;padding:2px;border:1px solid #c3c4c7;border-radius:3px;cursor:pointer;">
                                            <input type="text" id="suite-barrio-setting-email-bg-color-hex" class="small-text"
                                                placeholder="#f4f4f4" maxlength="7" style="width:90px;">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="suite-barrio-setting-email-header-color">Colore header</label></th>
                                        <td style="display:flex;align-items:center;gap:10px;">
                                            <input type="color" id="suite-barrio-setting-email-header-color" value="#2271b1"
                                                style="width:44px;height:34px;padding:2px;border:1px solid #c3c4c7;border-radius:3px;cursor:pointer;">
                                            <input type="text" id="suite-barrio-setting-email-header-color-hex"
                                                class="small-text" placeholder="#2271b1" maxlength="7" style="width:90px;">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="suite-barrio-setting-email-logo-url">URL logo</label></th>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:8px;">
                                                <input type="url" id="suite-barrio-setting-email-logo-url" class="large-text"
                                                    placeholder="https://esempio.com/logo.png">
                                                <button type="button"
                                                    class="suite-barrio-btn suite-barrio-btn-ghost suite-barrio-btn-sm"
                                                    id="suite-barrio-btn-media-logo">Scegli immagine</button>
                                            </div>
                                            <div id="suite-barrio-logo-preview" style="margin-top:8px;display:none;">
                                                <img src="" alt="Anteprima logo"
                                                    style="max-width:200px;max-height:60px;border:1px solid #c3c4c7;border-radius:3px;padding:4px;background:#fff;">
                                                <button type="button" id="suite-barrio-btn-remove-logo"
                                                    style="background:none;border:none;color:#d63638;cursor:pointer;font-size:16px;vertical-align:top;padding:0 4px;"
                                                    title="Rimuovi">&times;</button>
                                            </div>
                                            <p class="description">Lascia vuoto per non mostrare il logo nell'email.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="suite-barrio-setting-domain-email">Email dominio</label></th>
                                        <td>
                                            <input type="email" id="suite-barrio-setting-domain-email" class="large-text"
                                                placeholder="info@esempio.com">
                                            <p class="description">Indirizzo email generico del sito, mostrato nel footer legale
                                                delle
                                                email.</p>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div><!-- .suite-barrio-section-body -->
                    </div><!-- .suite-barrio-section-box stile email -->

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
                        <div id="suite-barrio-notifications-list"></div>
                        <button type="button" class="suite-barrio-notif-add-btn" id="suite-barrio-btn-add-notification">
                            <span style="font-size:18px;line-height:1;">+</span> Aggiungi notifica
                        </button>
                    </div>

                </div><!-- #suite-barrio-tab-notifiche -->

                <?php if ($edit_id): ?>
                    <!-- TAB REGISTRAZIONI -->
                    <div id="suite-barrio-tab-registrazioni" style="display:none;">

                        <div class="suite-barrio-reg-toolbar">
                            <span class="suite-barrio-reg-count" id="suite-barrio-reg-count"></span>
                            <button
                                class="suite-barrio-btn-reg-delete-all suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-danger"
                                id="suite-barrio-btn-reg-delete-all" style="display:none;">
                                Elimina tutte
                            </button>
                        </div>

                        <div id="suite-barrio-reg-content">
                            <div class="suite-barrio-reg-empty" id="suite-barrio-reg-loading">
                                <span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
                                Caricamento registrazioni…
                            </div>
                        </div>

                    </div><!-- #suite-barrio-tab-registrazioni -->
                <?php endif; ?>

            <?php endif; // fine switch tab ?>

        </div><!-- #suite-barrio-forms-wrap -->
        <div id="suite-barrio-toast"></div>

        <!-- Modale conferma eliminazione campo -->
        <div id="suite-barrio-confirm-modal" role="dialog" aria-modal="true">
            <div class="suite-barrio-modal-box">
                <div class="suite-barrio-modal-icon">🗑</div>
                <h3>Eliminare questo campo?</h3>
                <p id="suite-barrio-modal-field-name">L'operazione non può essere annullata.</p>
                <div class="suite-barrio-modal-actions">
                    <button class="suite-barrio-modal-btn-cancel suite-barrio-btn suite-barrio-btn-ghost"
                        id="suite-barrio-modal-cancel">Annulla</button>
                    <button class="suite-barrio-modal-btn-confirm suite-barrio-btn suite-barrio-btn-danger"
                        id="suite-barrio-modal-confirm">Elimina</button>
                </div>
            </div>
        </div>

        <?php ob_start(); ?>
        (function () {
        const NONCE = '<?php echo esc_js($nonce); ?>';
        const AJAX = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        const EDIT_ID = <?php echo intval($builder_form_id); ?>;

        function toast(msg, type = 'success') {
        const t = document.getElementById('suite-barrio-toast');
        if (!t) return;
        t.textContent = msg;
        t.className = 'show' + (type === 'error' ? ' error' : '');
        clearTimeout(t._timer);
        t._timer = setTimeout(() => t.className = '', 3500);
        }

        /* ── Elimina form ── */
        document.querySelectorAll('.suite-barrio-btn-delete-form').forEach(btn => {
        btn.addEventListener('click', async () => {
        if (!confirm('Eliminare il form "' + btn.dataset.title + '" e tutte le sue risposte?')) return;
        btn.disabled = true;
        const res = await fetch(AJAX, { method: 'POST', body: new URLSearchParams({ action: 'suite_barrio_forms_delete',
        nonce: NONCE, form_id: btn.dataset.id }) });
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
        document.querySelectorAll('.suite-barrio-btn-del-sub').forEach(btn => {
        btn.addEventListener('click', async () => {
        if (!confirm('Eliminare questa risposta?')) return;
        btn.disabled = true;
        const res = await fetch(AJAX, { method: 'POST', body: new URLSearchParams({ action:
        'suite_barrio_forms_delete_submission', nonce: NONCE, submission_id: btn.dataset.id }) });
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
        if (!document.getElementById('suite-barrio-drop-zone')) return;

        let fields = <?php echo wp_json_encode(json_decode($saved_fields) ?: []); ?>;
        let settings = <?php echo wp_json_encode(json_decode($saved_settings) ?: new stdClass()); ?>;
        if (!Array.isArray(fields)) fields = [];
        if (typeof settings !== 'object' || !settings) settings = {};

        // I campi salvati non hanno id interno — lo generiamo in memoria dal name
        fields = fields.map(f => ({ ...f, id: f.id || ('_' + (f.name || Math.random().toString(36).slice(2))) }));

        let selectedFieldId = null;
        let dragSrcIdx = null;
        let notificationsData = [];

        const dropZone = document.getElementById('suite-barrio-drop-zone');
        const emptyState = document.getElementById('suite-barrio-canvas-empty');
        const propsInner = document.getElementById('suite-barrio-props-inner');

        /* ── Impostazioni UI ── */
        function initSettings() {
        const s = settings;
        const el = id => document.getElementById(id);
        if (el('suite-barrio-setting-submit-text')) el('suite-barrio-setting-submit-text').value = s.submit_text || '';
        if (el('suite-barrio-setting-success-msg')) el('suite-barrio-setting-success-msg').value = s.success_message || '';
        if (el('suite-barrio-setting-redirect-url')) el('suite-barrio-setting-redirect-url').value = s.redirect_url || '';
        const dpi = el('suite-barrio-setting-display-page-info');
        if (dpi) dpi.checked = !!(s.display_page_info);
        // Stile email
        const bgColor = s.email_bg_color || '#f4f4f4';
        const hdColor = s.email_header_color || '#2271b1';
        if (el('suite-barrio-setting-email-bg-color')) el('suite-barrio-setting-email-bg-color').value = bgColor;
        if (el('suite-barrio-setting-email-bg-color-hex')) el('suite-barrio-setting-email-bg-color-hex').value = bgColor;
        if (el('suite-barrio-setting-email-header-color')) el('suite-barrio-setting-email-header-color').value = hdColor;
        if (el('suite-barrio-setting-email-header-color-hex')) el('suite-barrio-setting-email-header-color-hex').value =
        hdColor;
        if (el('suite-barrio-setting-email-logo-url')) {
        el('suite-barrio-setting-email-logo-url').value = s.email_logo_url || '';
        el('suite-barrio-setting-email-logo-url').dispatchEvent(new Event('input'));
        }
        if (el('suite-barrio-setting-domain-email')) el('suite-barrio-setting-domain-email').value = s.domain_email || '';
        // Notifiche
        notificationsData = Array.isArray(s.notifications) ? s.notifications.map(n => ({ ...n })) : [];
        renderNotifications();
        const after = s.after_submit || 'message';
        setAfterPanel(after);
        // MailUp
        const muEnabled = el('suite-barrio-setting-mailup-enabled');
        if (muEnabled) {
        muEnabled.checked = !!(s.mailup_enabled);
        toggleMailupFields(muEnabled.checked);
        }
        if (el('suite-barrio-setting-mailup-list-id')) el('suite-barrio-setting-mailup-list-id').value = s.mailup_list_id ||
        '1';
        if (el('suite-barrio-setting-mailup-group-id')) el('suite-barrio-setting-mailup-group-id').value = s.mailup_group_id
        || '1';
        if (el('suite-barrio-setting-mailup-email-field')) el('suite-barrio-setting-mailup-email-field').value =
        s.mailup_email_field || '';
        // Opt-in select: popola con i campi checkbox presenti nel form
        const optinSel = el('suite-barrio-setting-mailup-optin-field');
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
        mailupFieldMap = Array.isArray(s.mailup_field_map) ? s.mailup_field_map.map(r => ({ form_field: r.form_field || '',
        mailup_field_name: r.mailup_field_name || '' })) : [];
        renderMailupMapRows();
        }

        function setAfterPanel(val) {
        const msgP = document.getElementById('suite-barrio-ast-panel-message');
        const redP = document.getElementById('suite-barrio-ast-panel-redirect');
        const hid = document.getElementById('suite-barrio-setting-after-submit');
        if (msgP) msgP.style.display = val === 'message' ? '' : 'none';
        if (redP) redP.style.display = val === 'redirect' ? '' : 'none';
        if (hid) hid.value = val;
        // Aggiorna i bottoni del toggle "Conferma di invio"
        document.querySelectorAll('.suite-barrio-ast-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.value === val);
        });
        }

        document.querySelectorAll('.suite-barrio-ast-btn').forEach(b => b.addEventListener('click', () =>
        setAfterPanel(b.dataset.value)));

        function toggleMailupFields(show) {
        const wrap = document.getElementById('suite-barrio-mailup-fields-wrap');
        if (wrap) wrap.style.display = show ? '' : 'none';
        }
        const muCb = document.getElementById('suite-barrio-setting-mailup-enabled');
        if (muCb) muCb.addEventListener('change', () => toggleMailupFields(muCb.checked));

        // ── Field mapping rows ──────────────────────────────────────────
        let mailupFieldMap = []; // [{form_field, mailup_field_name}]

        function renderMailupMapRows() {
        const container = document.getElementById('suite-barrio-mailup-field-map-rows');
        if (!container) return;
        container.innerHTML = '';
        mailupFieldMap.forEach((row, idx) => {
        const div = document.createElement('div');
        div.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:8px;';
        div.innerHTML = `
        <input type="text" placeholder="slug campo form (es. telefono)" value="${esc(row.form_field || '')}"
            data-idx="${idx}" data-key="form_field"
            style="flex:1;padding:5px 8px;border:1px solid #c3c4c7;border-radius:3px;font-size:13px;">
        <span style="color:#a7aaad;font-size:18px;line-height:1;flex-shrink:0;">→</span>
        <input type="text" placeholder="es. Nome" value="${esc(row.mailup_field_name || '')}" data-idx="${idx}"
            data-key="mailup_field_name"
            style="flex:1;padding:5px 8px;border:1px solid #c3c4c7;border-radius:3px;font-size:13px;">
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

        const addMapBtn = document.getElementById('suite-barrio-mailup-add-map-row');
        if (addMapBtn) addMapBtn.addEventListener('click', () => {
        mailupFieldMap.push({ form_field: '', mailup_field_name: '' });
        renderMailupMapRows();
        });

        initSettings();

        function readSettings() {
        const el = id => document.getElementById(id);
        const dpi = el('suite-barrio-setting-display-page-info');
        return {
        submit_text: el('suite-barrio-setting-submit-text') ? el('suite-barrio-setting-submit-text').value.trim() :
        (settings.submit_text || 'Invia'),
        success_message: el('suite-barrio-setting-success-msg') ? el('suite-barrio-setting-success-msg').value.trim() :
        (settings.success_message || ''),
        after_submit: el('suite-barrio-setting-after-submit') ? el('suite-barrio-setting-after-submit').value :
        (settings.after_submit || 'message'),
        redirect_url: el('suite-barrio-setting-redirect-url') ? el('suite-barrio-setting-redirect-url').value.trim() :
        (settings.redirect_url || ''),
        email_bg_color: el('suite-barrio-setting-email-bg-color-hex') ?
        el('suite-barrio-setting-email-bg-color-hex').value.trim() : (settings.email_bg_color || '#f4f4f4'),
        email_header_color: el('suite-barrio-setting-email-header-color-hex') ?
        el('suite-barrio-setting-email-header-color-hex').value.trim() : (settings.email_header_color || '#2271b1'),
        email_logo_url: el('suite-barrio-setting-email-logo-url') ? el('suite-barrio-setting-email-logo-url').value.trim() :
        (settings.email_logo_url || ''),
        domain_email: el('suite-barrio-setting-domain-email') ? el('suite-barrio-setting-domain-email').value.trim() :
        (settings.domain_email || ''),
        notifications: notificationsData
        .filter(n => n.to_email && n.to_email.trim())
        .map(({ _open, ...rest }) => rest),
        display_page_info: dpi ? dpi.checked : !!(settings.display_page_info),
        // MailUp
        mailup_enabled: el('suite-barrio-setting-mailup-enabled') ? el('suite-barrio-setting-mailup-enabled').checked :
        !!(settings.mailup_enabled),
        mailup_list_id: el('suite-barrio-setting-mailup-list-id') ?
        parseInt(el('suite-barrio-setting-mailup-list-id').value, 10) || 1 : (settings.mailup_list_id || 1),
        mailup_group_id: el('suite-barrio-setting-mailup-group-id') ?
        parseInt(el('suite-barrio-setting-mailup-group-id').value, 10) || 1 : (settings.mailup_group_id || 1),
        mailup_email_field: el('suite-barrio-setting-mailup-email-field') ?
        el('suite-barrio-setting-mailup-email-field').value.trim() : (settings.mailup_email_field || 'email'),
        mailup_optin_field: el('suite-barrio-setting-mailup-optin-field') ?
        el('suite-barrio-setting-mailup-optin-field').value : (settings.mailup_optin_field || ''),
        mailup_field_map: mailupFieldMap.filter(r => r.form_field && r.mailup_field_name),
        };
        }

        /* ── Notifiche ── */
        function renderNotifications() {
        const list = document.getElementById('suite-barrio-notifications-list');
        if (!list) return;

        if (!notificationsData.length) {
        list.innerHTML = `
        <div class="suite-barrio-notif-empty-state">
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
        ? '<div class="suite-barrio-field-vars" style="margin-top:5px;">' + emailFields.map(f =>
            '<button type="button" class="suite-barrio-field-var-pill suite-barrio-email-field-pill"' +
                                                ' data-value="' + ob + esc(f.name) + cb + '"' +
                                                '
                title="Usa il campo &quot;' + esc(f.label || f.name) + '&quot; come destinatario">' +
                '⟵ ' + esc(f.label || f.name) +
                '</button>'
            ).join('') + '</div>'
        : '';

        // Pill variabili (per campi testo)
        const pills = realFields.length
        ? '<div class="suite-barrio-field-vars">' + realFields.map(f =>
            '<button type="button" class="suite-barrio-field-var-pill"' +
                                                ' data-var="' + ob + esc(f.name) + cb + '"' +
                                                '
                title="Inserisci il valore di &quot;' + esc(f.label || f.name) + '&quot;">' +
                ob + esc(f.name) + cb +
                '</button>'
            ).join('') + '</div>'
        : '<p style="font-size:11px;color:#a7aaad;margin:4px 0 0;">Nessun campo disponibile — aggiungi campi al form.</p>';

        // Modalità corpo messaggio
        const msgMode = n.message_mode || 'auto';
        const isCustom = msgMode === 'custom';

        return `
        <div class="suite-barrio-notif-card${isOpen ? ' is-open' : ''}" data-idx="${i}">

            <div class="suite-barrio-notif-header" data-idx="${i}">
                <div class="suite-barrio-notif-header-icon">✉</div>
                <div class="suite-barrio-notif-header-info">
                    <div class="suite-barrio-notif-header-title">${esc(headerTitle)}</div>
                    <div class="suite-barrio-notif-header-sub">Da: ${fromLabel}</div>
                </div>
                <div class="suite-barrio-notif-header-actions">
                    <span class="suite-barrio-notif-chevron">▼</span>
                    <button type="button" class="suite-barrio-notif-preview-btn" data-idx="${i}"
                        title="Anteprima email">👁</button>
                    <button type="button" class="suite-barrio-notif-del-btn" data-idx="${i}"
                        title="Elimina notifica">×</button>
                </div>
            </div>

            <div class="suite-barrio-notif-body">

                <!-- NOME NOTIFICA -->
                <div class="suite-barrio-notif-section">
                    <div class="suite-barrio-notif-field">
                        <label>Nome notifica <span class="opt">opzionale</span></label>
                        <input type="text" data-field="notification_name" value="${esc(n.notification_name || '')}"
                            placeholder="es. Notifica admin, Conferma utente…">
                        <p class="hint">Etichetta interna per riconoscere questa notifica.</p>
                    </div>
                </div>

                <!-- DESTINATARIO -->
                <div class="suite-barrio-notif-section">
                    <p class="suite-barrio-notif-section-label">📨 Destinatario</p>
                    <div class="suite-barrio-notif-grid">
                        <div class="suite-barrio-notif-field">
                            <label>Email destinatario</label>
                            <input type="text" data-field="to_email" value="${esc(n.to_email || '')}"
                                placeholder="admin@esempio.com">
                            ${emailPills}
                            <p class="hint">Puoi inserire un'email fissa o usare un campo del form.</p>
                        </div>
                        <div class="suite-barrio-notif-field">
                            <label>Nome destinatario <span class="opt">opzionale</span></label>
                            <input type="text" data-field="to_name" data-supports-vars="1" value="${esc(n.to_name || '')}"
                                placeholder="Admin">
                            ${pills}
                        </div>
                    </div>
                </div>

                <!-- MITTENTE -->
                <div class="suite-barrio-notif-section">
                    <p class="suite-barrio-notif-section-label">👤 Mittente</p>
                    <div class="suite-barrio-notif-grid">
                        <div class="suite-barrio-notif-field">
                            <label>Email mittente</label>
                            <input type="email" data-field="from_email" value="${esc(n.from_email || '')}"
                                placeholder="noreply@esempio.com">
                        </div>
                        <div class="suite-barrio-notif-field">
                            <label>Nome mittente <span class="opt">opzionale</span></label>
                            <input type="text" data-field="from_name" data-supports-vars="1"
                                value="${esc(n.from_name || '')}" placeholder="Nome Sito">
                            ${pills}
                        </div>
                    </div>
                </div>

                <!-- CONTENUTO EMAIL -->
                <div class="suite-barrio-notif-section">
                    <p class="suite-barrio-notif-section-label">📝 Contenuto email</p>
                    <div class="suite-barrio-notif-grid single" style="margin-bottom:12px;">
                        <div class="suite-barrio-notif-field">
                            <label>Oggetto email</label>
                            <input type="text" data-field="subject" data-supports-vars="1" value="${esc(n.subject || '')}"
                                placeholder="Nuovo messaggio dal form">
                            ${pills}
                        </div>
                    </div>
                    <div class="suite-barrio-notif-grid single" style="margin-bottom:12px;">
                        <div class="suite-barrio-notif-field">
                            <label>Titolo intestazione email <span class="opt">opzionale</span></label>
                            <input type="text" data-field="email_title" data-supports-vars="1"
                                value="${esc(n.email_title || '')}" placeholder="Hai ricevuto un nuovo messaggio">
                            ${pills}
                        </div>
                    </div>
                    <div class="suite-barrio-notif-grid single">
                        <div class="suite-barrio-notif-field">
                            <label>Corpo del messaggio</label>
                            <div
                                style="display:flex;gap:0;margin-bottom:8px;border:1px solid #c3c4c7;border-radius:3px;overflow:hidden;">
                                <button type="button" class="suite-barrio-msg-mode-btn${!isCustom ? ' active' : ''}"
                                    data-mode="auto"
                                    style="flex:1;padding:5px 10px;font-size:11px;font-weight:600;font-family:inherit;border:none;cursor:pointer;background:${!isCustom ? '#2271b1' : '#f6f7f7'};color:${!isCustom ? '#fff' : '#646970'};">
                                    Auto (tutti i campi)
                                </button>
                                <button type="button" class="suite-barrio-msg-mode-btn${isCustom ? ' active' : ''}"
                                    data-mode="custom"
                                    style="flex:1;padding:5px 10px;font-size:11px;font-weight:600;font-family:inherit;border:none;border-left:1px solid #c3c4c7;cursor:pointer;background:${isCustom ? '#2271b1' : '#f6f7f7'};color:${isCustom ? '#fff' : '#646970'};">
                                    Personalizzato
                                </button>
                            </div>
                            <input type="hidden" data-field="message_mode" value="${esc(msgMode)}">
                            <div class="suite-barrio-msg-custom-area" style="display:${isCustom ? '' : 'none'};">
                                <textarea data-field="message" data-supports-vars="1"
                                    placeholder="Scrivi il corpo dell'email. Lascia vuoto per inviare senza corpo.">${esc(n.message || '')}</textarea>
                                ${pills}
                                <p class="hint">Puoi inserire i riferimenti ai campi del form usando i pill sopra.</p>
                            </div>
                            <div class="suite-barrio-msg-auto-area" style="display:${!isCustom ? '' : 'none'};">
                                <p class="hint" style="margin:0;">Verrà generata automaticamente una tabella con tutti i
                                    campi compilati.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- OPZIONALI -->
                <div class="suite-barrio-notif-section">
                    <p class="suite-barrio-notif-section-label">⚙️ Opzionali</p>
                    <div class="suite-barrio-notif-grid">
                        <div class="suite-barrio-notif-field">
                            <label>Reply-To email <span class="opt">opzionale</span></label>
                            <input type="email" data-field="replyto_email" value="${esc(n.replyto_email || '')}"
                                placeholder="risposta@esempio.com">
                        </div>
                        <div class="suite-barrio-notif-field">
                            <label>Reply-To nome <span class="opt">opzionale</span></label>
                            <input type="text" data-field="replyto_name" data-supports-vars="1"
                                value="${esc(n.replyto_name || '')}" placeholder="">
                            ${pills}
                        </div>
                        <div class="suite-barrio-notif-field">
                            <label>CCN email <span class="opt">opzionale</span></label>
                            <input type="email" data-field="cc_email" value="${esc(n.cc_email || '')}"
                                placeholder="ccn@esempio.com">
                        </div>
                        <div class="suite-barrio-notif-field">
                            <label>CCN nome <span class="opt">opzionale</span></label>
                            <input type="text" data-field="cc_name" data-supports-vars="1" value="${esc(n.cc_name || '')}"
                                placeholder="">
                            ${pills}
                        </div>
                    </div>
                </div>

            </div>
        </div>`;
        }).join('');

        // Bind pill variabili → inserisci {{name}} nel campo al cursore
        list.querySelectorAll('.suite-barrio-field-var-pill').forEach(pill => {
        pill.addEventListener('click', () => {
        const varText = pill.dataset.var;
        const section = pill.closest('.suite-barrio-notif-field');
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

        const idx = parseInt(target.closest('.suite-barrio-notif-card').dataset.idx, 10);
        notificationsData[idx][target.dataset.field] = target.value;
        });
        });

        // Pill campi email → imposta direttamente to_email
        list.querySelectorAll('.suite-barrio-email-field-pill').forEach(pill => {
        pill.addEventListener('click', () => {
        const card = pill.closest('.suite-barrio-notif-card');
        const input = card.querySelector('input[data-field="to_email"]');
        const idx = parseInt(card.dataset.idx, 10);
        if (!input) return;
        input.value = pill.dataset.value;
        notificationsData[idx].to_email = pill.dataset.value;
        // aggiorna header
        const titleEl = card.querySelector('.suite-barrio-notif-header-title');
        if (titleEl) titleEl.textContent = pill.dataset.value;
        });
        });

        // Toggle modalità corpo messaggio
        list.querySelectorAll('.suite-barrio-msg-mode-btn').forEach(btn => {
        btn.addEventListener('click', () => {
        const card = btn.closest('.suite-barrio-notif-card');
        const idx = parseInt(card.dataset.idx, 10);
        const mode = btn.dataset.mode;
        notificationsData[idx].message_mode = mode;
        // Aggiorna visuale senza re-render completo
        card.querySelectorAll('.suite-barrio-msg-mode-btn').forEach(b => {
        const isActive = b.dataset.mode === mode;
        b.style.background = isActive ? '#2271b1' : '#f6f7f7';
        b.style.color = isActive ? '#fff' : '#646970';
        });
        const customArea = card.querySelector('.suite-barrio-msg-custom-area');
        const autoArea = card.querySelector('.suite-barrio-msg-auto-area');
        const modeInput = card.querySelector('input[data-field="message_mode"]');
        if (customArea) customArea.style.display = mode === 'custom' ? '' : 'none';
        if (autoArea) autoArea.style.display = mode === 'auto' ? '' : 'none';
        if (modeInput) modeInput.value = mode;
        });
        });

        // Toggle accordion
        list.querySelectorAll('.suite-barrio-notif-header').forEach(header => {
        header.addEventListener('click', e => {
        if (e.target.closest('.suite-barrio-notif-del-btn')) return;
        const idx = parseInt(header.dataset.idx, 10);
        notificationsData[idx]._open = (notificationsData[idx]._open === false);
        const card = header.closest('.suite-barrio-notif-card');
        card.classList.toggle('is-open', notificationsData[idx]._open !== false);
        card.querySelector('.suite-barrio-notif-body').style.display =
        notificationsData[idx]._open !== false ? '' : 'none';
        });
        });

        // Live update campi
        list.querySelectorAll('input[data-field], textarea[data-field]').forEach(inp => {
        inp.addEventListener('input', () => {
        const idx = parseInt(inp.closest('.suite-barrio-notif-card').dataset.idx, 10);
        notificationsData[idx][inp.dataset.field] = inp.value;
        // Aggiorna header senza re-render completo
        const card = inp.closest('.suite-barrio-notif-card');
        if (inp.dataset.field === 'to_email' || inp.dataset.field === 'notification_name') {
        const t = card.querySelector('.suite-barrio-notif-header-title');
        const n = notificationsData[idx];
        if (t) t.textContent = n.notification_name || n.to_email || `Notifica ${idx + 1}`;
        }
        if (inp.dataset.field === 'from_name' || inp.dataset.field === 'from_email') {
        const s = card.querySelector('.suite-barrio-notif-header-sub');
        const n = notificationsData[idx];
        if (s) s.textContent = 'Da: ' + (n.from_name || n.from_email || 'Mittente non impostato');
        }
        });
        });

        // Elimina
        list.querySelectorAll('.suite-barrio-notif-del-btn').forEach(btn => {
        btn.addEventListener('click', e => {
        e.stopPropagation();
        notificationsData.splice(parseInt(btn.dataset.idx, 10), 1);
        renderNotifications();
        });
        });

        // Anteprima notifica
        list.querySelectorAll('.suite-barrio-notif-preview-btn').forEach(btn => {
        btn.addEventListener('click', async e => {
        e.stopPropagation();
        const idx = parseInt(btn.dataset.idx, 10);
        const notif = notificationsData[idx];
        btn.disabled = true;
        btn.textContent = '…';
        try {
        const res = await fetch(AJAX, {
        method: 'POST',
        body: new URLSearchParams({
        action: 'suite_barrio_forms_preview_notification',
        nonce: NONCE,
        form_id: String(EDIT_ID),
        notif_data: JSON.stringify(notif),
        fields_data: JSON.stringify(fields),
        }),
        });
        const json = await res.json();
        if (json.success && json.data?.html) {
        const win = window.open('', '_blank');
        win.document.open();
        win.document.write(json.data.html);
        win.document.close();
        } else {
        alert(json.data || 'Errore durante la generazione dell\'anteprima.');
        }
        } catch (err) {
        alert('Errore di rete durante la generazione dell\'anteprima.');
        } finally {
        btn.disabled = false;
        btn.textContent = '👁';
        }
        });
        });
        }

        document.getElementById('suite-barrio-btn-add-notification')?.addEventListener('click', () => {
        notificationsData.push({
        notification_name: '', from_email: '', from_name: '', to_email: '', to_name: '',
        cc_email: '', cc_name: '', replyto_email: '', replyto_name: '',
        subject: '', email_title: '', message: '', message_mode: 'auto', _open: true
        });
        renderNotifications();
        // Focus automatico sull'email destinatario della nuova card
        const cards = document.querySelectorAll('.suite-barrio-notif-card');
        if (cards.length) cards[cards.length - 1].querySelector('input[data-field="to_email"]')?.focus();
        });

        // Sincronizza color picker ↔ input hex
        ['suite-barrio-setting-email-bg-color', 'suite-barrio-setting-email-header-color'].forEach(id => {
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
        const btn = document.getElementById('suite-barrio-btn-media-logo');
        const input = document.getElementById('suite-barrio-setting-email-logo-url');
        const preview = document.getElementById('suite-barrio-logo-preview');
        const removeBtn = document.getElementById('suite-barrio-btn-remove-logo');
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
        function esc(s) { return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;' ).replace( />/g,
        '&gt;').replace(/"/g, '&quot;'); }

        // Formatta valore checkbox/radio: "Label: true | Label: false" → HTML con ✓/✗
        function formatCheckVal(val) {
        if (typeof val !== 'string' || !/:\s*(true|false)(\s*\||$)/.test(val)) return null;
        return val.split('|').map(p => p.trim()).filter(Boolean).map(part => {
        const m = part.match(/^(.+?):\s*(true|false)$/);
        if (!m) return '';
        const label = m[1].trim();
        const checked = m[2] === 'true';
        const icon = checked ? '✔' : '✘';
        const cls = checked ? 'suite-barrio-check-on' : 'suite-barrio-check-off';
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
        return `<span class="suite-barrio-check-summary">${on}/${total}</span>`;
        }

        // Slugify: converte una label in un name HTML-safe senza spazi né caratteri speciali
        // Es. "Nome e Cognome" → "nome_e_cognome" | "Età (anni)" → "eta_anni"
        function slugify(label) {
        return String(label || '')
        .toLowerCase()
        .normalize('NFD') // separa i diacritici (à → a + `)
        .replace(/[\u0300-\u036f]/g, '') // rimuove i diacritici
        .replace(/[^a-z0-9]+/g, '_') // tutto ciò che non è alfanumerico → _
        .replace(/^_+|_+$/g, '') // trimma underscore iniziali/finali
        || 'campo';
        }

        const TYPE_LABELS = {
        text: 'Testo', email: 'Email', textarea: 'Area di testo',
        tel: 'Telefono', number: 'Numero', datepicker: 'Data',
        select: 'Seleziona', radio: 'Scelta', checkbox: 'Scelta multipla',
        file: 'File', page_break: 'Interruzione pagina'
        };

        /* ── Builder tabs ── */
        let regLoaded = false;

        document.querySelectorAll('.suite-barrio-btab').forEach(tab => {
        tab.addEventListener('click', () => {
        document.querySelectorAll('.suite-barrio-btab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('suite-barrio-tab-fields').style.display = tab.dataset.tab === 'fields' ? '' : 'none';
        document.getElementById('suite-barrio-tab-settings').style.display = tab.dataset.tab === 'settings' ? '' : 'none';
        document.getElementById('suite-barrio-tab-notifiche').style.display = tab.dataset.tab === 'notifiche' ? '' : 'none';
        const regPanel = document.getElementById('suite-barrio-tab-registrazioni');
        if (regPanel) {
        regPanel.style.display = tab.dataset.tab === 'registrazioni' ? '' : 'none';
        if (tab.dataset.tab === 'registrazioni' && !regLoaded) {
        loadRegistrazioni();
        }
        }
        });
        });

        // Auto-apre il tab Registrazioni se richiesto via hash
        if (window.location.hash === '#suite-barrio-registrazioni') {
        history.replaceState(null, '', window.location.pathname + window.location.search);
        const regBtn = document.querySelector('.suite-barrio-btab[data-tab="registrazioni"]');
        if (regBtn) regBtn.click();
        }

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
        dropZone.querySelectorAll('.suite-barrio-field-card').forEach(c => c.remove());
        emptyState.style.display = fields.length === 0 ? 'flex' : 'none';
        fields.forEach((f, i) => dropZone.appendChild(buildCard(f, i)));
        refreshMailupOptinSelect();
        }

        // Aggiorna il <select> opt-in MailUp con i campi checkbox presenti nel canvas
            function refreshMailupOptinSelect() {
            const sel = document.getElementById('suite-barrio-setting-mailup-optin-field');
            if (!sel) return;
            const current = sel.value;
            while (sel.options.length > 1) sel.remove(1);
            fields.filter(f => f.type === 'checkbox').forEach(f => {
            const opt = document.createElement('option');
            opt.value = f.name || '';
            opt.textContent = (f.label || f.name || f.id) + ' [slug: ' + (f.name || '') + ']';
            sel.appendChild(opt);
            });
            // Ripristina il valore precedente se esiste ancora
            if ([...sel.options].some(o => o.value === current)) sel.value = current;
            }

            function buildCard(f, idx) {
            const card = document.createElement('div');
            card.className = 'suite-barrio-field-card' + (f.id === selectedFieldId ? ' selected' : '');
            card.dataset.id = f.id;
            card.dataset.idx = idx;
            card.draggable = true;

            let preview = '';
            if (f.type === 'textarea') {
            preview = `<textarea placeholder="${esc(f.placeholder || f.label)}" disabled></textarea>`;
            } else if (f.type === 'select') {
            preview = `<div class="suite-barrio-preview-select"><span>${esc(f.placeholder || 'Seleziona…')}</span><svg
                    width="12" height="12" viewBox="0 0 12 12" fill="none">
                    <path d="M2 4l4 4 4-4" stroke="#a7aaad" stroke-width="1.5" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg></div>`;
            } else if (f.type === 'radio') {
            const opts = (f.options || []).slice(0, 3);
            preview = `<div class="suite-barrio-preview-choices">${opts.map(o => `<label
                    class="suite-barrio-preview-choice"><span
                        class="suite-barrio-preview-radio"></span>${esc(o)}</label>`).join('')}</div>`;
            } else if (f.type === 'checkbox') {
            const opts = (f.options || []).slice(0, 3);
            preview = `<div class="suite-barrio-preview-choices">${opts.map(o => `<label
                    class="suite-barrio-preview-choice"><span
                        class="suite-barrio-preview-checkbox"></span>${esc(o)}</label>`).join('')}</div>`;
            } else if (f.type === 'datepicker') {
            preview = `<div class="suite-barrio-preview-date"><span>${esc(f.placeholder || 'gg/mm/aaaa')}</span><svg
                    width="14" height="14" viewBox="0 0 14 14" fill="none">
                    <rect x="1" y="2" width="12" height="11" rx="1.5" stroke="#a7aaad" stroke-width="1.2" />
                    <path d="M1 5h12M4 1v2M10 1v2" stroke="#a7aaad" stroke-width="1.2" stroke-linecap="round" />
                </svg></div>`;
            } else if (f.type === 'file') {
            preview = `<div class="suite-barrio-preview-file"><svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                    <path d="M3 1h6l3 3v9H3V1z" stroke="#a7aaad" stroke-width="1.2" stroke-linejoin="round" />
                    <path d="M8 1v3h3" stroke="#a7aaad" stroke-width="1.2" stroke-linejoin="round" />
                </svg><span>Scegli file…</span></div>`;
            } else if (f.type === 'page_break') {
            preview = `<div class="suite-barrio-preview-page-break">
                <hr style="border:none;border-top:2px dashed #2271b1;margin:0;"><span
                    style="display:block;text-align:center;font-size:11px;color:#2271b1;margin-top:4px;font-weight:600;">↓
                    Pagina successiva ↓</span>
            </div>`;
            } else {
            preview = `<input type="${esc(f.type)}" placeholder="${esc(f.placeholder || f.label)}" disabled>`;
            }

            card.innerHTML = `
            <div class="suite-barrio-field-card-header">
                <span class="suite-barrio-drag-handle">⠿</span>
                <span class="suite-barrio-field-card-label">${esc(f.label)}${f.required ? ' <span
                        style="color:#d63638;font-size:10px;">*</span>' : ''}</span>
                <span class="suite-barrio-field-card-type">${esc(f.type)}</span>
                <button class="suite-barrio-btn-remove-field" data-id="${esc(f.id)}" title="Rimuovi">×</button>
            </div>
            <div class="suite-barrio-field-card-preview">${preview}</div>`;

            card.addEventListener('click', e => { if (!e.target.classList.contains('suite-barrio-btn-remove-field'))
            selectField(f.id); });
            card.querySelector('.suite-barrio-btn-remove-field').addEventListener('click', e => { e.stopPropagation();
            removeField(f.id); });

            card.addEventListener('dragstart', e => { dragSrcIdx = idx; card.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move'; });
            card.addEventListener('dragend', () => { card.classList.remove('dragging');
            dropZone.querySelectorAll('.suite-barrio-field-card').forEach(c => { c.style.borderTop = c.style.borderBottom =
            ''; }); });
            card.addEventListener('dragover', e => {
            e.preventDefault();
            const mid = card.getBoundingClientRect().top + card.getBoundingClientRect().height / 2;
            dropZone.querySelectorAll('.suite-barrio-field-card').forEach(c => { c.style.borderTop = c.style.borderBottom =
            ''; });
            card.style[e.clientY < mid ? 'borderTop' : 'borderBottom' ]='2px solid #2271b1' ; });
                card.addEventListener('dragleave', ()=> { card.style.borderTop = card.style.borderBottom = ''; });
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
                dropZone.addEventListener('dragleave', e => { if (!dropZone.contains(e.relatedTarget))
                dropZone.classList.remove('drag-over'); });
                dropZone.addEventListener('drop', () => dropZone.classList.remove('drag-over'));

                function selectField(id) { selectedFieldId = id; renderCanvas(); renderProps(); }

                /* ── Modale conferma ── */
                let _pendingRemoveId = null;
                const modal = document.getElementById('suite-barrio-confirm-modal');
                const modalName = document.getElementById('suite-barrio-modal-field-name');
                const modalConfirm = document.getElementById('suite-barrio-modal-confirm');
                const modalCancel = document.getElementById('suite-barrio-modal-cancel');

                function askRemoveField(id) {
                const f = fields.find(f => f.id === id);
                if (!f) return;
                _pendingRemoveId = id;
                if (modalName) modalName.textContent = 'Stai per eliminare il campo "' + f.label + '". L\'operazione non può
                essere annullata.';
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

                /* ──────────────────────────────────────────────────────────────────
                PRESET OPZIONI per campi select / radio / checkbox
                Set predefiniti caricabili con un click nel pannello proprietà.
                Aggiungere qui nuovi preset → diventano automaticamente disponibili.
                ────────────────────────────────────────────────────────────────── */
                const SUITE_BARRIO_OPTION_PRESETS = {
                'countries_it': {
                label: '🌍 Nazioni del mondo (italiano)',
                options: [
                'Afghanistan', 'Albania', 'Algeria', 'Andorra', 'Angola', 'Antigua e Barbuda',
                'Arabia Saudita', 'Argentina', 'Armenia', 'Australia', 'Austria', 'Azerbaigian',
                'Bahamas', 'Bahrein', 'Bangladesh', 'Barbados', 'Belgio', 'Belize', 'Benin',
                'Bhutan', 'Bielorussia', 'Bolivia', 'Bosnia ed Erzegovina', 'Botswana', 'Brasile',
                'Brunei', 'Bulgaria', 'Burkina Faso', 'Burundi', 'Cambogia', 'Camerun', 'Canada',
                'Capo Verde', 'Ciad', 'Cile', 'Cina', 'Cipro', 'Città del Vaticano', 'Colombia',
                'Comore', 'Corea del Nord', 'Corea del Sud', "Costa d'Avorio", 'Costa Rica',
                'Croazia', 'Cuba', 'Danimarca', 'Dominica', 'Ecuador', 'Egitto', 'El Salvador',
                'Emirati Arabi Uniti', 'Eritrea', 'Estonia', 'Eswatini', 'Etiopia', 'Figi',
                'Filippine', 'Finlandia', 'Francia', 'Gabon', 'Gambia', 'Georgia', 'Germania',
                'Ghana', 'Giamaica', 'Giappone', 'Gibuti', 'Giordania', 'Grecia', 'Grenada',
                'Guatemala', 'Guinea', 'Guinea Equatoriale', 'Guinea-Bissau', 'Guyana', 'Haiti',
                'Honduras', 'India', 'Indonesia', 'Iran', 'Iraq', 'Irlanda', 'Islanda',
                'Isole Marshall', 'Isole Salomone', 'Israele', 'Italia', 'Kazakistan', 'Kenya',
                'Kirghizistan', 'Kiribati', 'Kosovo', 'Kuwait', 'Laos', 'Lesotho', 'Lettonia',
                'Libano', 'Liberia', 'Libia', 'Liechtenstein', 'Lituania', 'Lussemburgo',
                'Macedonia del Nord', 'Madagascar', 'Malawi', 'Malaysia', 'Maldive', 'Mali',
                'Malta', 'Marocco', 'Mauritania', 'Mauritius', 'Messico', 'Micronesia',
                'Moldavia', 'Monaco', 'Mongolia', 'Montenegro', 'Mozambico', 'Myanmar (Birmania)',
                'Namibia', 'Nauru', 'Nepal', 'Nicaragua', 'Niger', 'Nigeria', 'Norvegia',
                'Nuova Zelanda', 'Oman', 'Paesi Bassi', 'Pakistan', 'Palau', 'Palestina',
                'Panama', 'Papua Nuova Guinea', 'Paraguay', 'Perù', 'Polonia', 'Portogallo',
                'Qatar', 'Regno Unito', 'Repubblica Centrafricana', 'Repubblica Ceca',
                'Repubblica del Congo', 'Repubblica Democratica del Congo',
                'Repubblica Dominicana', 'Romania', 'Ruanda', 'Russia', 'Saint Kitts e Nevis',
                'Saint Lucia', 'Saint Vincent e Grenadine', 'Samoa', 'San Marino',
                'São Tomé e Príncipe', 'Senegal', 'Serbia', 'Seychelles', 'Sierra Leone',
                'Singapore', 'Siria', 'Slovacchia', 'Slovenia', 'Somalia', 'Spagna', 'Sri Lanka',
                "Stati Uniti d'America", 'Sud Africa', 'Sud Sudan', 'Sudan', 'Suriname',
                'Svezia', 'Svizzera', 'Tagikistan', 'Taiwan', 'Tanzania', 'Thailandia',
                'Timor Est', 'Togo', 'Tonga', 'Trinidad e Tobago', 'Tunisia', 'Turchia',
                'Turkmenistan', 'Tuvalu', 'Ucraina', 'Uganda', 'Ungheria', 'Uruguay',
                'Uzbekistan', 'Vanuatu', 'Venezuela', 'Vietnam', 'Yemen', 'Zambia', 'Zimbabwe'
                ]
                },
                'regions_it': {
                label: '🇮🇹 Regioni italiane',
                options: [
                'Abruzzo', 'Basilicata', 'Calabria', 'Campania', 'Emilia-Romagna',
                'Friuli-Venezia Giulia', 'Lazio', 'Liguria', 'Lombardia', 'Marche', 'Molise',
                'Piemonte', 'Puglia', 'Sardegna', 'Sicilia', 'Toscana', 'Trentino-Alto Adige',
                'Umbria', "Valle d'Aosta", 'Veneto'
                ]
                },
                'provinces_it': {
                label: '🇮🇹 Province italiane (nomi)',
                options: [
                'Agrigento', 'Alessandria', 'Ancona', 'Aosta', 'Arezzo', 'Ascoli Piceno', 'Asti',
                'Avellino', 'Bari', 'Barletta-Andria-Trani', 'Belluno', 'Benevento', 'Bergamo',
                'Biella', 'Bologna', 'Bolzano', 'Brescia', 'Brindisi', 'Cagliari',
                'Caltanissetta', 'Campobasso', 'Caserta', 'Catania', 'Catanzaro', 'Chieti',
                'Como', 'Cosenza', 'Cremona', 'Crotone', 'Cuneo', 'Enna', 'Fermo', 'Ferrara',
                'Firenze', 'Foggia', 'Forlì-Cesena', 'Frosinone', 'Genova', 'Gorizia',
                'Grosseto', 'Imperia', 'Isernia', "L'Aquila", 'La Spezia', 'Latina', 'Lecce',
                'Lecco', 'Livorno', 'Lodi', 'Lucca', 'Macerata', 'Mantova', 'Massa-Carrara',
                'Matera', 'Messina', 'Milano', 'Modena', 'Monza e della Brianza', 'Napoli',
                'Novara', 'Nuoro', 'Oristano', 'Padova', 'Palermo', 'Parma', 'Pavia', 'Perugia',
                'Pesaro e Urbino', 'Pescara', 'Piacenza', 'Pisa', 'Pistoia', 'Pordenone',
                'Potenza', 'Prato', 'Ragusa', 'Ravenna', 'Reggio Calabria', 'Reggio Emilia',
                'Rieti', 'Rimini', 'Roma', 'Rovigo', 'Salerno', 'Sassari', 'Savona', 'Siena',
                'Siracusa', 'Sondrio', 'Sud Sardegna', 'Taranto', 'Teramo', 'Terni', 'Torino',
                'Trapani', 'Trento', 'Treviso', 'Trieste', 'Udine', 'Varese', 'Venezia',
                'Verbano-Cusio-Ossola', 'Vercelli', 'Verona', 'Vibo Valentia', 'Vicenza',
                'Viterbo'
                ]
                },
                'provinces_it_abbr': {
                label: '🇮🇹 Province italiane (sigle)',
                options: [
                'AG', 'AL', 'AN', 'AO', 'AR', 'AP', 'AT', 'AV', 'BA', 'BT', 'BL', 'BN', 'BG',
                'BI', 'BO', 'BZ', 'BS', 'BR', 'CA', 'CL', 'CB', 'CE', 'CT', 'CZ', 'CH', 'CO',
                'CS', 'CR', 'KR', 'CN', 'EN', 'FM', 'FE', 'FI', 'FG', 'FC', 'FR', 'GE', 'GO',
                'GR', 'IM', 'IS', 'AQ', 'SP', 'LT', 'LE', 'LC', 'LI', 'LO', 'LU', 'MC', 'MN',
                'MS', 'MT', 'ME', 'MI', 'MO', 'MB', 'NA', 'NO', 'NU', 'OR', 'PD', 'PA', 'PR',
                'PV', 'PG', 'PU', 'PE', 'PC', 'PI', 'PT', 'PN', 'PZ', 'PO', 'RG', 'RA', 'RC',
                'RE', 'RI', 'RN', 'RM', 'RO', 'SA', 'SS', 'SV', 'SI', 'SR', 'SO', 'SU', 'TA',
                'TE', 'TR', 'TO', 'TP', 'TN', 'TV', 'TS', 'UD', 'VA', 'VE', 'VB', 'VC', 'VR',
                'VV', 'VI', 'VT'
                ]
                },
                'us_states': {
                label: '🇺🇸 Stati USA',
                options: [
                'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Carolina del Nord',
                'Carolina del Sud', 'Colorado', 'Connecticut', 'Dakota del Nord',
                'Dakota del Sud', 'Delaware', 'Distretto di Columbia', 'Florida', 'Georgia',
                'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky',
                'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan', 'Minnesota',
                'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire',
                'New Jersey', 'New Mexico', 'New York', 'Ohio', 'Oklahoma', 'Oregon',
                'Pennsylvania', 'Rhode Island', 'Tennessee', 'Texas', 'Utah', 'Vermont',
                'Virginia', 'Virginia Occidentale', 'Washington', 'Wisconsin', 'Wyoming'
                ]
                },
                'continents': {
                label: '🌐 Continenti',
                options: [
                'Africa', 'America del Nord', 'America del Sud', 'Antartide', 'Asia', 'Europa',
                'Oceania'
                ]
                },
                'days_it': {
                label: '📅 Giorni della settimana',
                options: [
                'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato', 'Domenica'
                ]
                },
                'months_it': {
                label: "📅 Mesi dell'anno",
                options: [
                'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio',
                'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'
                ]
                },
                'languages_it': {
                label: '🗣 Lingue principali',
                options: [
                'Arabo', 'Bengalese', 'Cinese (mandarino)', 'Coreano', 'Francese', 'Giapponese',
                'Hindi', 'Inglese', 'Italiano', 'Portoghese', 'Russo', 'Spagnolo', 'Tedesco',
                'Turco', 'Urdu', 'Vietnamita'
                ]
                },
                'education_it': {
                label: '🎓 Titolo di studio',
                options: [
                'Licenza elementare', 'Licenza media', 'Diploma di scuola superiore',
                'Laurea triennale', 'Laurea magistrale / specialistica', 'Master di I livello',
                'Master di II livello', 'Dottorato di ricerca'
                ]
                },
                'marital_status_it': {
                label: '💍 Stato civile',
                options: [
                'Celibe / Nubile', 'Coniugato/a', 'Separato/a', 'Divorziato/a', 'Vedovo/a',
                'Convivente'
                ]
                },
                'gender': {
                label: '👤 Genere',
                options: [
                'Uomo', 'Donna', 'Altro', 'Preferisco non specificare'
                ]
                }
                };

                /**
                * Applica un preset al campo selezionato.
                * Se ci sono opzioni custom esistenti (non solo i default "Opzione N"),
                * chiede conferma prima di sovrascriverle.
                */
                function applyOptionPreset(field, presetKey) {
                const preset = SUITE_BARRIO_OPTION_PRESETS[presetKey];
                if (!preset) return;
                const current = Array.isArray(field.options) ? field.options : [];
                // "Default" = lista vuota oppure soltanto valori del tipo "Opzione N" / "Nuova opzione" / vuoti
                const isDefault = current.length === 0 ||
                current.every((o, i) => o === 'Opzione ' + (i + 1) || o === 'Nuova opzione' || o === '');
                if (!isDefault) {
                const ok = confirm(
                'Il campo contiene già ' + current.length + ' opzioni personalizzate.\n\n' +
                'Vuoi sostituirle con il preset "' + preset.label + '" (' + preset.options.length + ' voci)?\n\n' +
                'OK = Sostituisci · Annulla = Mantieni le attuali'
                );
                if (!ok) return;
                }
                updateField(field.id, 'options', [...preset.options]);
                renderProps();
                toast('Preset caricato: ' + preset.options.length + ' opzioni.');
                }

                /* ── Proprietà ── */
                function renderProps() {
                if (!selectedFieldId) {
                propsInner.innerHTML = `<div class="suite-barrio-no-field-selected"><span
                        class="dashicons dashicons-edit-large"></span>
                    <p>Seleziona un campo<br>per modificarne le proprietà</p>
                </div>`;
                return;
                }
                const f = fields.find(f => f.id === selectedFieldId);
                if (!f) return;

                if (f.type === 'page_break') {
                propsInner.innerHTML = `<div style="padding:12px 0;text-align:center;color:#646970;">
                    <span class="dashicons dashicons-minus"
                        style="font-size:28px;width:28px;height:28px;color:#2271b1;"></span>
                    <p style="margin:8px 0 0;font-size:13px;">Interruzione pagina</p>
                    <p style="margin:4px 0 0;font-size:11px;color:#a7aaad;">Separa i campi in step diversi nel form
                        multistep.</p>
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
                <div class="suite-barrio-prop-group">
                    <label>Etichetta</label>
                    <input type="text" id="prop-label" value="${esc(f.label)}">
                </div>
                <div class="suite-barrio-prop-group">
                    <label>Name <span style="font-size:10px;color:#a7aaad;font-weight:400;">(attributo HTML, auto-generato
                            dalla label)</span></label>
                    <input type="text" id="prop-name" value="${esc(f.name || '')}"
                        style="font-family:Consolas,monospace;font-size:12px;">
                </div>
                ${showPlaceholder ? `<div class="suite-barrio-prop-group"><label>Testo segnaposto</label><input type="text"
                        id="prop-placeholder" value="${esc(f.placeholder || '')}"></div>` : ''}
                ${isNumber ? `
                <div class="suite-barrio-prop-group">
                    <label>Valore minimo</label>
                    <input type="number" id="prop-min" value="${esc(f.min ?? '')}">
                </div>
                <div class="suite-barrio-prop-group">
                    <label>Valore massimo</label>
                    <input type="number" id="prop-max" value="${esc(f.max ?? '')}">
                </div>` : ''}
                ${isDatepicker ? `
                <div class="suite-barrio-prop-group">
                    <label>Formato data</label>
                    <input type="text" id="prop-format" value="${esc(f.format || 'dd/MM/y')}" placeholder="dd/MM/y">
                    <p style="font-size:11px;color:#a7aaad;margin:4px 0 0;">Es: dd/MM/y · MM/dd/yyyy · yyyy-MM-dd</p>
                </div>` : ''}
                ${hasOpts ? `<div class="suite-barrio-prop-group">
                    <label>Opzioni</label>
                    <div class="suite-barrio-options-list" id="suite-barrio-opts-list">
                        ${(f.options || []).map((o, i) => `<div class="suite-barrio-option-row"><input type="text"
                                class="suite-barrio-opt-input" data-idx="${i}" value="${esc(o)}"
                                placeholder="Opzione ${i + 1}"><button class="suite-barrio-btn-rm-opt"
                                data-idx="${i}">×</button></div>`).join('')}
                    </div>
                    <button class="suite-barrio-btn-add-opt" id="suite-barrio-btn-add-opt">+ Aggiungi opzione</button>
                    <select class="suite-barrio-preset-select" id="suite-barrio-preset-select">
                        <option value="" disabled selected hidden>📋 Carica un preset di opzioni…</option>
                        ${Object.entries(SUITE_BARRIO_OPTION_PRESETS).map(([k, v]) => `<option value="${esc(k)}">
                            ${esc(v.label)} (${v.options.length})</option>`).join('')}
                    </select>
                    <p class="suite-barrio-preset-hint">Seleziona un set predefinito (Nazioni, Province, ecc.) per popolare
                        automaticamente le opzioni.</p>
                </div>` : ''}
                <div class="suite-barrio-prop-group">
                    <label class="suite-barrio-prop-check"><input type="checkbox" id="prop-required" ${f.required
                            ? 'checked' : '' }> Campo obbligatorio</label>
                </div>
                <div class="suite-barrio-prop-group">
                    <label>Messaggio di errore</label>
                    <input type="text" id="prop-error-message" value="${esc(f.error_message || '')}"
                        placeholder="Questo campo è obbligatorio">
                    <p style="font-size:11px;color:#a7aaad;margin:4px 0 0;">Mostrato se il campo non è compilato
                        correttamente.</p>
                </div>
                <div style="margin-top:20px;padding-top:14px;border-top:1px solid #f0f0f1;font-size:11px;color:#a7aaad;">
                    Tipo: <strong>${esc(f.type)}</strong>
                </div>
                <div class="suite-barrio-cond-section">
                    <div class="suite-barrio-cond-section-title">
                        <span>Logica condizionale</span>
                    </div>
                    ${renderCondLogicUI(f)}
                </div>`;

                const bi = (elId, key) => { const el = document.getElementById(elId); if (el) el.addEventListener('input',
                () => updateField(f.id, key, el.value)); };

                // Binding label: aggiorna label E ricalcola name automaticamente
                const labelEl = document.getElementById('prop-label');
                const nameEl = document.getElementById('prop-name');
                if (labelEl) {
                labelEl.addEventListener('input', () => {
                const newLabel = labelEl.value;
                updateField(f.id, 'label', newLabel);
                const newName = slugify(newLabel);
                updateField(f.id, 'name', newName);
                if (nameEl) nameEl.value = newName;
                });
                }
                // Binding name: modificabile manualmente
                bi('prop-name', 'name');

                bi('prop-placeholder', 'placeholder');
                bi('prop-min', 'min');
                bi('prop-max', 'max');
                bi('prop-format', 'format');
                bi('prop-error-message', 'error_message');
                const req = document.getElementById('prop-required');
                if (req) req.addEventListener('change', () => updateField(f.id, 'required', req.checked));

                if (hasOpts) {
                document.querySelectorAll('.suite-barrio-opt-input').forEach(inp => {
                inp.addEventListener('input', () => {
                const opts = [...(f.options || [])]; opts[parseInt(inp.dataset.idx)] = inp.value;
                updateField(f.id, 'options', opts);
                });
                });
                document.querySelectorAll('.suite-barrio-btn-rm-opt').forEach(btn => {
                btn.addEventListener('click', () => {
                const opts = [...(f.options || [])]; opts.splice(parseInt(btn.dataset.idx), 1);
                updateField(f.id, 'options', opts); renderProps();
                });
                });
                const addBtn = document.getElementById('suite-barrio-btn-add-opt');
                if (addBtn) addBtn.addEventListener('click', () => { updateField(f.id, 'options', [...(f.options || []),
                'Nuova opzione']); renderProps(); });

                // Preset opzioni: applica un set predefinito
                const presetSel = document.getElementById('suite-barrio-preset-select');
                if (presetSel) {
                presetSel.addEventListener('change', () => {
                const key = presetSel.value;
                if (!key) return;
                applyOptionPreset(f, key);
                // Riporto il select al placeholder (anche in caso l'utente abbia annullato)
                presetSel.value = '';
                });
                }
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
                * - Abilita/Disabilita (toggle)
                * - Azione: Mostra | Nascondi
                * - Logica: Tutte (AND) | Almeno una (OR)
                * - Lista di regole: [campo] [operatore] [valore]
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
                <div class="suite-barrio-cl-wrap">
                    <label class="suite-barrio-cl-enable-row"
                        style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:10px;">
                        <input type="checkbox" id="cl-enabled" ${enabled ? 'checked' : '' } style="margin:0;">
                        <span style="font-size:12px;font-weight:600;color:#1d2327;">Abilita logica condizionale</span>
                    </label>
                    <div id="cl-body" style="display:${enabled ? 'block' : 'none'};">
                        <div
                            style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:12px;color:#1d2327;margin-bottom:10px;">
                            <select id="cl-action"
                                style="padding:4px 8px;font-size:12px;border:1px solid #c3c4c7;border-radius:3px;background:#fff;font-family:inherit;">
                                <option value="show" ${action==='show' ? 'selected' : '' }>Mostra</option>
                                <option value="hide" ${action==='hide' ? 'selected' : '' }>Nascondi</option>
                            </select>
                            <span>questo campo se</span>
                            <select id="cl-logic-type"
                                style="padding:4px 8px;font-size:12px;border:1px solid #c3c4c7;border-radius:3px;background:#fff;font-family:inherit;">
                                <option value="all" ${logicType==='all' ? 'selected' : '' }>tutte</option>
                                <option value="any" ${logicType==='any' ? 'selected' : '' }>almeno una</option>
                            </select>
                            <span>delle seguenti condizioni sono soddisfatte:</span>
                        </div>
                        <div class="suite-barrio-cond-list" id="suite-barrio-cond-list">
                            ${rulesHtml || '<p style="font-size:11px;color:#a7aaad;margin:4px 0 8px;">Nessuna regola.
                                Aggiungine una.</p>'}
                        </div>
                        <button type="button" class="suite-barrio-btn-add-cond" id="suite-barrio-btn-add-cond">+ Aggiungi
                            regola</button>
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
                `<option value="${esc(x.name)}" ${rule.fieldName===x.name ? 'selected' : '' }>${esc(x.label || x.name)}
                </option>`
                ).join('');

                // Operatori disponibili a seconda del tipo di campo sorgente
                const srcHasOptions = selectedField && ['select', 'radio', 'checkbox'].includes(selectedField.type) &&
                (selectedField.options || []).length;
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
                { v: '<', l: 'è minore di' }, { v: 'empty' , l: 'è vuoto' }, { v: 'not_empty' , l: 'non è vuoto' }, ]; }
                    else { availableOps=[ { v: '=' , l: 'è uguale a' }, { v: '!=' , l: 'è diverso da' }, { v: 'contains' ,
                    l: 'contiene' }, { v: 'starts_with' , l: 'inizia per' }, { v: 'ends_with' , l: 'finisce per' }, {
                    v: 'empty' , l: 'è vuoto' }, { v: 'not_empty' , l: 'non è vuoto' }, ]; } const
                    opOpts=availableOps.map(o=>
                    `<option value="${o.v}" ${(rule.operator || '=' )===o.v ? 'selected' : '' }>${o.l}</option>`
                    ).join('');

                    const needsValue = !['empty', 'not_empty'].includes(rule.operator || '=');
                    let valueEl = '';
                    if (needsValue) {
                    if (srcHasOptions) {
                    const valOpts = (selectedField.options || []).map(opt =>
                    `<option value="${esc(opt)}" ${(rule.value || '' )===opt ? 'selected' : '' }>${esc(opt)}</option>`
                    ).join('');
                    valueEl = `<select class="cl-rule-val" data-ri="${ri}"
                        style="flex:1;min-width:80px;padding:4px 6px;font-size:11px;border:1px solid #c3c4c7;border-radius:3px;background:#fff;font-family:inherit;">
                        <option value="" disabled ${!rule.value ? 'selected' : '' } hidden>Seleziona…</option>
                        ${valOpts}
                    </select>`;
                    } else {
                    valueEl = `<input type="text" class="cl-rule-val" data-ri="${ri}" value="${esc(rule.value || '')}"
                        placeholder="Valore…"
                        style="flex:1;min-width:80px;padding:4px 7px;font-size:11px;border:1px solid #c3c4c7;border-radius:3px;font-family:inherit;">`;
                    }
                    }

                    return `<div class="suite-barrio-cond-row" data-ri="${ri}"
                        style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px;">
                        <select class="cl-rule-field" data-ri="${ri}"
                            style="flex:1;min-width:110px;padding:4px 6px;font-size:11px;border:1px solid #c3c4c7;border-radius:3px;background:#fff;font-family:inherit;">
                            <option value="" disabled ${!rule.fieldName ? 'selected' : '' } hidden>Campo…</option>
                            ${fieldOpts || '<option disabled>Nessun campo disponibile</option>'}
                        </select>
                        <select class="cl-rule-op" data-ri="${ri}"
                            style="flex:0 0 auto;padding:4px 6px;font-size:11px;border:1px solid #c3c4c7;border-radius:3px;background:#fff;font-family:inherit;">
                            ${opOpts}
                        </select>
                        ${valueEl}
                        <button type="button" class="suite-barrio-btn-rm-cond" data-ri="${ri}" title="Rimuovi regola"
                            style="padding:2px 6px;font-size:14px;background:none;border:none;cursor:pointer;color:#d63638;flex-shrink:0;">×</button>
                    </div>`;
                    }

                    /* ── Logica condizionale: bind eventi ── */
                    function bindCondEvents(f) {
                    const wrap = document.getElementById('suite-barrio-cond-list');
                    const body = document.getElementById('cl-body');
                    const enabledCb = document.getElementById('cl-enabled');
                    if (!wrap || !enabledCb) return;

                    /* Legge l'intera struttura conditionalLogic dal DOM */
                    function readCondLogic() {
                    const enabled = enabledCb.checked;
                    const actionSel = document.getElementById('cl-action');
                    const ltSel = document.getElementById('cl-logic-type');
                    const rules = [];

                    wrap.querySelectorAll('.suite-barrio-cond-row').forEach(row => {
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
                    wrap.querySelectorAll('.suite-barrio-btn-rm-cond').forEach(btn => {
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
                    const addBtn = document.getElementById('suite-barrio-btn-add-cond');
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
                    document.querySelectorAll('.suite-barrio-field-type-btn').forEach(btn => {
                    btn.addEventListener('click', () => addField(btn.dataset.type));
                    });

                    /* ── Salva form ── */
                    const saveBtn = document.getElementById('suite-barrio-btn-save-form');
                    if (saveBtn) {
                    saveBtn.addEventListener('click', async () => {
                    const title = document.getElementById('suite-barrio-form-title').value.trim();
                    if (!title) { toast('Inserisci un nome per il form.', 'error'); return; }

                    saveBtn.disabled = true;
                    saveBtn.textContent = 'Salvataggio…';

                    const body = new URLSearchParams({
                    action: 'suite_barrio_forms_save',
                    nonce: NONCE,
                    form_id: String(EDIT_ID),
                    title,
                    // Rimuovi l'id interno prima di salvare: non serve nel DB
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
                    location.href =
                    '<?php echo esc_js(admin_url("admin.php?page=suite-barrio-forms&tab=builder&edit_id=")); ?>' +
                    data.data.id;
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

                    /* ── Registrazioni: caricamento AJAX ── */
                    function showDetail(sub, formFields) {
                    const countEl = document.getElementById('suite-barrio-reg-count');
                    const delAllEl = document.getElementById('suite-barrio-btn-reg-delete-all');
                    if (delAllEl) delAllEl.style.display = 'none';
                    if (countEl) countEl.textContent = '';

                    const SKIP = ['_page_url', '_page_title'];

                    let fieldsHtml = '<div class="suite-barrio-sub-detail-sep">Campi del form</div>';
                    formFields.filter(f => !SKIP.includes(f.name || '') && f.type !== 'page_break' && f.type !==
                    'file').forEach(f => {
                    const key = f.name || '';
                    const cell = sub.data[key];
                    let val = cell ? cell.value : null;
                    if (Array.isArray(val)) val = val.join(', ');
                    const empty = val === null || val === undefined || val === '';
                    const checkHtml = !empty ? formatCheckVal(val) : null;
                    const valHtml = checkHtml || (empty ? 'Non compilato' : esc(String(val)));

                    fieldsHtml += `<div class="suite-barrio-sub-detail-row">
                        <div class="suite-barrio-sub-detail-label">${esc(f.label || f.name)}</div>
                        <div class="suite-barrio-sub-detail-value${empty ? ' suite-barrio-empty' : ''}">${valHtml}</div>
                    </div>`;
                    });

                    // Sezione allegati (file caricati con prefisso _file_)
                    const fileEntries = Object.entries(sub.data || {}).filter(([k, v]) => k.startsWith('_file_') && v &&
                    v.is_file);
                    if (fileEntries.length) {
                    fieldsHtml += '<div class="suite-barrio-sub-detail-sep">📎 Allegati</div>';
                    fileEntries.forEach(([k, d]) => {
                    const fname = esc(d.value || '');
                    const furl = d.url ? esc(d.url) : '';
                    const flabel = esc(d.label || fname);
                    const fileLink = furl
                    ? `<a href="${furl}" target="_blank" rel="noopener" style="font-weight:500;">${fname}</a>`
                    : `<span style="color:#a7aaad;">${fname} <em>(URL non disponibile)</em></span>`;
                    fieldsHtml += `<div class="suite-barrio-sub-detail-row">
                        <div class="suite-barrio-sub-detail-label">${flabel}</div>
                        <div class="suite-barrio-sub-detail-value">${fileLink}</div>
                    </div>`;
                    });
                    }

                    // Sezione informazioni pagina (se presenti nella submission)
                    const pageUrl = sub.data['_page_url'] ? (sub.data['_page_url'].value ?? sub.data['_page_url']) : null;
                    const pageTitle = sub.data['_page_title'] ? (sub.data['_page_title'].value ?? sub.data['_page_title']) :
                    null;
                    if (pageUrl || pageTitle) {
                    fieldsHtml += '<div class="suite-barrio-sub-detail-sep">Informazioni pagina</div>';
                    if (pageUrl) {
                    const urlEsc = esc(String(pageUrl));
                    const urlLink = pageUrl ? `<a href="${urlEsc}" target="_blank" rel="noopener">${urlEsc}</a>` : '';
                    fieldsHtml += `<div class="suite-barrio-sub-detail-row">
                        <div class="suite-barrio-sub-detail-label">URL pagina</div>
                        <div class="suite-barrio-sub-detail-value" style="word-break:break-all;">${urlLink}</div>
                    </div>`;
                    }
                    if (pageTitle) {
                    fieldsHtml += `<div class="suite-barrio-sub-detail-row">
                        <div class="suite-barrio-sub-detail-label">Titolo pagina</div>
                        <div class="suite-barrio-sub-detail-value">${esc(String(pageTitle))}</div>
                    </div>`;
                    }
                    }

                    const metaHtml = [
                    `<span>\uD83D\uDCC5 Data: <strong>${esc(sub.created_at_full || sub.created_at)}</strong></span>`,
                    sub.ip ? `<span>\uD83C\uDF10 IP: <strong>${esc(sub.ip)}</strong></span>` : '',
                    sub.user_agent ? `<span>\uD83D\uDDA5 Browser: <span
                            style="font-weight:400;font-size:11px;">${esc(sub.user_agent)}</span></span>` : '',
                    ].filter(Boolean).join('');

                    // Costruisci sezione rinvia notifica (solo se ci sono notifiche configurate)
                    let resendHtml = '';
                    if (notificationsData.length) {
                    let optionsHtml = '<option value="" disabled selected hidden>Seleziona un template…</option>';
                    notificationsData.forEach((n, i) => {
                    const label = (n.notification_name || n.to_email || 'Notifica ' + (i + 1))
                    + (n.subject ? ' — ' + esc(n.subject) : '');
                    optionsHtml += `<option value="${i}">${esc(label)}</option>`;
                    });
                    resendHtml = `
                    <div class="suite-barrio-resend-wrap">
                        <div class="suite-barrio-resend-header">
                            <h4><span class="dashicons dashicons-email-alt"
                                    style="font-size:18px;width:18px;height:18px;color:#2271b1;"></span> Reinvia notifica
                            </h4>
                        </div>
                        <div class="suite-barrio-resend-body">
                            <div class="suite-barrio-resend-field">
                                <label for="suite-barrio-resend-template">Template di notifica <span
                                        style="color:#d63638;">*</span></label>
                                <select id="suite-barrio-resend-template" required>${optionsHtml}</select>
                            </div>
                            <div class="suite-barrio-resend-field">
                                <label for="suite-barrio-resend-email">Email destinatario (override)</label>
                                <input type="email" id="suite-barrio-resend-email"
                                    placeholder="Lascia vuoto per usare il destinatario configurato">
                                <p class="description">Se compilato, la notifica verrà inviata a questo indirizzo invece che
                                    al destinatario del template.</p>
                            </div>
                            <div class="suite-barrio-resend-actions">
                                <button class="suite-barrio-btn suite-barrio-btn-primary" id="suite-barrio-btn-resend">Invia
                                    notifica</button>
                                <span id="suite-barrio-resend-result" class="suite-barrio-resend-result"></span>
                            </div>
                        </div>
                    </div>`;
                    }

                    const content = document.getElementById('suite-barrio-reg-content');
                    content.innerHTML = `
                    <div class="suite-barrio-sub-detail-wrap" style="margin-top:14px;">
                        <div class="suite-barrio-sub-detail-header">
                            <h3>Registrazione #${sub.id}</h3>
                            <button
                                class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-danger suite-barrio-btn-sm suite-barrio-btn-reg-del-detail"
                                data-id="${sub.id}">Elimina</button>
                        </div>
                        <div class="suite-barrio-sub-detail-meta">${metaHtml}</div>
                        <div class="suite-barrio-sub-detail-fields">${fieldsHtml}</div>
                    </div>
                    ${resendHtml}
                    <button class="suite-barrio-btn suite-barrio-btn-ghost" id="suite-barrio-reg-back"
                        style="margin-top:20px;">\u2190 Torna alla lista</button>`;

                    document.getElementById('suite-barrio-reg-back').addEventListener('click', () => {
                    regLoaded = false;
                    loadRegistrazioni();
                    });

                    content.querySelectorAll('.suite-barrio-btn-reg-del-detail').forEach(btn => {
                    btn.addEventListener('click', async () => {
                    if (!confirm('Eliminare questa registrazione?')) return;
                    btn.disabled = true;
                    const res = await fetch(AJAX, { method: 'POST', body: new URLSearchParams({ action:
                    'suite_barrio_forms_delete_submission', nonce: NONCE, submission_id: btn.dataset.id }) });
                    const resp = await res.json();
                    if (resp.success) { regLoaded = false; loadRegistrazioni(); }
                    else { toast(resp.data || 'Errore.', 'error'); btn.disabled = false; }
                    });
                    });

                    // Bind rinvia notifica
                    const resendBtn = document.getElementById('suite-barrio-btn-resend');
                    if (resendBtn) {
                    resendBtn.addEventListener('click', async () => {
                    const templateSelect = document.getElementById('suite-barrio-resend-template');
                    const overrideEmail = document.getElementById('suite-barrio-resend-email');
                    const resultEl = document.getElementById('suite-barrio-resend-result');

                    if (!templateSelect.value && templateSelect.value !== '0') {
                    resultEl.textContent = 'Seleziona un template di notifica.';
                    resultEl.style.color = '#d63638';
                    return;
                    }

                    resendBtn.disabled = true;
                    resendBtn.textContent = 'Invio in corso…';
                    resultEl.textContent = '';

                    try {
                    const params = new URLSearchParams({
                    action: 'suite_barrio_forms_resend_notification',
                    nonce: NONCE,
                    submission_id: sub.id,
                    notification_index: templateSelect.value,
                    });
                    if (overrideEmail.value.trim()) {
                    params.set('override_email', overrideEmail.value.trim());
                    }
                    const res = await fetch(AJAX, { method: 'POST', body: params });
                    const resp = await res.json();
                    if (resp.success) {
                    resultEl.textContent = '✓ ' + (resp.data.message || 'Notifica inviata.');
                    resultEl.style.color = '#00a32a';
                    toast('Notifica reinviata con successo.');
                    } else {
                    resultEl.textContent = resp.data || 'Errore nell\'invio.';
                    resultEl.style.color = '#d63638';
                    toast(resp.data || 'Errore nell\'invio.', 'error');
                    }
                    } catch (e) {
                    resultEl.textContent = 'Errore di rete.';
                    resultEl.style.color = '#d63638';
                    } finally {
                    resendBtn.disabled = false;
                    resendBtn.textContent = 'Invia notifica';
                    }
                    });
                    }
                    }

                    function loadRegistrazioni() {
                    if (!EDIT_ID) return;
                    regLoaded = true;

                    const content = document.getElementById('suite-barrio-reg-content');
                    const countEl = document.getElementById('suite-barrio-reg-count');
                    const delAllEl = document.getElementById('suite-barrio-btn-reg-delete-all');

                    fetch(AJAX, {
                    method: 'POST',
                    body: new URLSearchParams({ action: 'suite_barrio_forms_get_submissions', nonce: NONCE, form_id: EDIT_ID
                    })
                    })
                    .then(r => r.json())
                    .then(data => {
                    if (!data.success) {
                    content.innerHTML = `<div class="suite-barrio-reg-empty">Errore nel caricamento delle registrazioni.
                    </div>`;
                    return;
                    }
                    const { subs, fields: formFields, total } = data.data;

                    if (countEl) countEl.textContent = total + ' registrazion' + (total === 1 ? 'e' : 'i');

                    if (!subs || !subs.length) {
                    content.innerHTML = `<div class="suite-barrio-reg-empty">
                        <span class="dashicons dashicons-email-alt"
                            style="font-size:36px;width:36px;height:36px;color:#c3c4c7;display:block;margin:0 auto 10px;"></span>
                        Nessuna registrazione ancora.
                    </div>`;
                    return;
                    }

                    if (delAllEl) delAllEl.style.display = '';

                    // Intestazioni: solo le prime 2 colonne + data
                    const listFields = formFields.slice(0, 2);
                    let thead = '<tr>
                        <th>#</th>';
                        listFields.forEach(f => { thead += `<th>${esc(f.label || f.name)}</th>`; });
                        thead += '<th>Data</th>
                        <th></th>
                    </tr>';

                    // Righe: compatte, cliccabili per dettaglio
                    let tbody = '';
                    subs.forEach((sub, idx) => {
                    const d = sub.data || {};
                    tbody += `<tr data-sub-id="${sub.id}" data-idx="${idx}" class="suite-barrio-btn-reg-detail">
                        <td>${sub.id}</td>`;
                        listFields.forEach(f => {
                        const key = f.name || '';
                        const cell = d[key];
                        let val = cell ? cell.value : null;
                        if (Array.isArray(val)) val = val.join(', ');
                        const empty = val === null || val === undefined || val === '';
                        const summary = !empty ? formatCheckSummary(val) : null;
                        tbody += empty
                        ? `<td class="suite-barrio-reg-cell-empty">—</td>`
                        : `<td title="${esc(String(val))}">${summary || esc(String(val))}</td>`;
                        });
                        tbody += `<td><span class="suite-barrio-reg-date">${esc(sub.created_at)}</span></td>`;
                        tbody += `<td style="white-space:nowrap;"><button
                                class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-danger suite-barrio-btn-sm suite-barrio-btn-reg-del"
                                data-id="${sub.id}" onclick="event.stopPropagation()">Elimina</button></td>`;
                        tbody += '
                    </tr>';
                    });

                    content.innerHTML = `
                    <div class="suite-barrio-reg-table-wrap">
                        <table class="suite-barrio-reg-table">
                            <thead>${thead}</thead>
                            <tbody>${tbody}</tbody>
                        </table>
                    </div>`;

                    // Bind dettaglio: riga intera cliccabile
                    content.querySelectorAll('tr.suite-barrio-btn-reg-detail').forEach(row => {
                    row.addEventListener('click', () => {
                    const idx = parseInt(row.dataset.idx);
                    showDetail(subs[idx], formFields);
                    });
                    });

                    // Bind elimina singola
                    content.querySelectorAll('.suite-barrio-btn-reg-del').forEach(btn => {
                    btn.addEventListener('click', async () => {
                    if (!confirm('Eliminare questa registrazione?')) return;
                    btn.disabled = true;
                    const res = await fetch(AJAX, { method: 'POST', body: new URLSearchParams({ action:
                    'suite_barrio_forms_delete_submission', nonce: NONCE, submission_id: btn.dataset.id }) });
                    const resp = await res.json();
                    if (resp.success) {
                    const row = btn.closest('tr');
                    row.style.transition = 'opacity .25s';
                    row.style.opacity = '0';
                    setTimeout(() => {
                    row.remove();
                    const remaining = content.querySelectorAll('.suite-barrio-btn-reg-del').length;
                    if (countEl) countEl.textContent = remaining + ' registrazion' + (remaining === 1 ? 'e' : 'i');
                    if (!remaining) {
                    if (delAllEl) delAllEl.style.display = 'none';
                    content.innerHTML = `<div class="suite-barrio-reg-empty">Nessuna registrazione ancora.</div>`;
                    }
                    }, 250);
                    } else {
                    toast(resp.data || 'Errore.', 'error');
                    btn.disabled = false;
                    }
                    });
                    });
                    })
                    .catch(() => {
                    content.innerHTML = `<div class="suite-barrio-reg-empty">Errore di rete.</div>`;
                    });
                    }

                    // Elimina tutte le registrazioni
                    const delAllBtn = document.getElementById('suite-barrio-btn-reg-delete-all');
                    if (delAllBtn) {
                    delAllBtn.addEventListener('click', async () => {
                    if (!confirm('Eliminare tutte le registrazioni?\nL\'operazione non può essere annullata.')) return;
                    delAllBtn.disabled = true;
                    const res = await fetch(AJAX, { method: 'POST', body: new URLSearchParams({ action:
                    'suite_barrio_forms_delete_all_submissions', nonce: NONCE, form_id: EDIT_ID }) });
                    const data = await res.json();
                    if (data.success) {
                    regLoaded = false;
                    loadRegistrazioni();
                    delAllBtn.style.display = 'none';
                    toast('Tutte le registrazioni eliminate.');
                    } else {
                    toast(data.data || 'Errore.', 'error');
                    delAllBtn.disabled = false;
                    }
                    });
                    }

                    renderCanvas();
                    renderProps();
                    })();
                    <?php
                    $suite_barrio_builder_js = ob_get_clean();
                    wp_add_inline_script('suite-barrio-forms-admin', $suite_barrio_builder_js, 'after');
                    ?>
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
    if (current_user_can('manage_options'))
        return; // gli admin vedono già tutto dalla pagina Forms

    add_menu_page(
        'Registrazioni Form — Suite Barrio',
        'Registrazioni',
        'edit_others_posts',
        'suite-barrio-submissions',
        'suite_barrio_forms_submissions_page',
        'dashicons-list-view',
        81
    );
}, 12);

/* ─────────────────────────────────────────
   ENQUEUE: asset della pagina Registrazioni (solo registrazione).
   Il CSS/JS effettivo viene agganciato con wp_add_inline_style/script()
   dentro suite_barrio_forms_submissions_page().
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_suite-barrio-submissions')
        return;
    wp_register_style('suite-barrio-forms-submissions', false, [], SUITE_BARRIO_FORMS_VERSION);
    wp_register_script('suite-barrio-forms-submissions', false, [], SUITE_BARRIO_FORMS_VERSION, true);
    wp_enqueue_style('suite-barrio-forms-submissions');
    wp_enqueue_script('suite-barrio-forms-submissions');
});

/* ─────────────────────────────────────────
   PAGINA READ-ONLY: lista form → submissions → dettaglio
   ───────────────────────────────────────── */
function suite_barrio_forms_submissions_page()
{
    if (!current_user_can('edit_others_posts')) {
        wp_die('Non hai i permessi per accedere a questa pagina.');
    }

    $nonce = wp_create_nonce('suite_barrio_forms_nonce');
    $forms = suite_barrio_forms_get_all();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $view_id = isset($_GET['view_id']) ? intval($_GET['view_id']) : 0;
    $view_form = $view_id ? suite_barrio_forms_get($view_id) : null;
    ?>
                    <?php ob_start(); ?>
                    .suite-barrio-sub-count {
                    display: inline-block;
                    padding: 2px 10px;
                    border-radius: 10px;
                    font-size: 12px;
                    font-weight: 600;
                    background: #f0f0f1;
                    color: #646970
                    }

                    .suite-barrio-sub-count.has-subs {
                    background: #e7f5e8;
                    color: #00a32a
                    }

                    /* Registrazioni inline */
                    .suite-barrio-subs-reg-table-wrap {
                    overflow-x: auto;
                    margin-top: 10px
                    }

                    .suite-barrio-subs-reg-table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 13px;
                    background: #fff;
                    border: 1px solid #c3c4c7;
                    border-radius: 3px
                    }

                    .suite-barrio-subs-reg-table th {
                    background: #f0f0f1;
                    padding: 8px 12px;
                    text-align: left;
                    font-size: 11px;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: .3px;
                    color: #50575e;
                    border-bottom: 1px solid #c3c4c7;
                    white-space: nowrap
                    }

                    .suite-barrio-subs-reg-table td {
                    padding: 8px 12px;
                    border-bottom: 1px solid #f0f0f1;
                    max-width: 220px;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap
                    }

                    .suite-barrio-subs-reg-table tr:hover td {
                    background: #f6f7f7
                    }

                    .suite-barrio-subs-reg-table tbody tr {
                    cursor: pointer
                    }

                    .suite-barrio-subs-reg-cell-empty {
                    color: #a7aaad;
                    font-style: italic
                    }

                    .suite-barrio-subs-reg-date {
                    font-size: 12px;
                    color: #646970
                    }

                    /* Dettaglio */
                    .suite-barrio-subs-detail-wrap {
                    background: #fff;
                    border: 1px solid #c3c4c7;
                    border-radius: 4px;
                    overflow: hidden
                    }

                    .suite-barrio-subs-detail-header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 16px 20px;
                    border-bottom: 1px solid #f0f0f1;
                    background: #fafafa
                    }

                    .suite-barrio-subs-detail-header h3 {
                    margin: 0;
                    font-size: 15px
                    }

                    .suite-barrio-subs-detail-meta {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 16px;
                    padding: 14px 20px;
                    font-size: 12px;
                    color: #646970;
                    border-bottom: 1px solid #f0f0f1;
                    background: #f9f9f9
                    }

                    .suite-barrio-subs-detail-fields {
                    padding: 0
                    }

                    .suite-barrio-subs-detail-row {
                    display: flex;
                    border-bottom: 1px solid #f0f0f1;
                    font-size: 13px
                    }

                    .suite-barrio-subs-detail-row:last-child {
                    border-bottom: none
                    }

                    .suite-barrio-subs-detail-label {
                    width: 200px;
                    min-width: 160px;
                    padding: 12px 20px;
                    background: #fafafa;
                    font-weight: 600;
                    color: #50575e;
                    border-right: 1px solid #f0f0f1
                    }

                    .suite-barrio-subs-detail-value {
                    flex: 1;
                    padding: 12px 20px;
                    word-break: break-word
                    }

                    .suite-barrio-subs-detail-empty {
                    color: #a7aaad;
                    font-style: italic
                    }

                    /* Toolbar */
                    .suite-barrio-subs-toolbar {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    margin-bottom: 14px
                    }

                    .suite-barrio-subs-reg-count {
                    font-size: 13px;
                    color: #646970
                    }

                    .suite-barrio-subs-spinner {
                    display: inline-block;
                    width: 18px;
                    height: 18px;
                    border: 2px solid #c3c4c7;
                    border-top-color: #2271b1;
                    border-radius: 50%;
                    animation: suite-barrio-subs-spin .6s linear infinite
                    }

                    @keyframes suite-barrio-subs-spin {
                    to {
                    transform: rotate(360deg)
                    }
                    }

                    .suite-barrio-check-on {
                    padding: 1px 0;
                    color: #00a32a;
                    font-weight: 600
                    }

                    .suite-barrio-check-off {
                    padding: 1px 0;
                    color: #a7aaad
                    }

                    .suite-barrio-check-summary {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 10px;
                    font-size: 11px;
                    font-weight: 600;
                    background: #f0f0f1;
                    color: #646970
                    }
                    <?php
                    $suite_barrio_submissions_css = ob_get_clean();
                    wp_add_inline_style('suite-barrio-forms-submissions', $suite_barrio_submissions_css);
                    ?>

                    <div class="wrap suite-barrio-wrap" id="suite-barrio-subs-wrap">
                        <div class="suite-barrio-header">
                            <div class="suite-barrio-header-icon"><span class="dashicons dashicons-list-view"></span></div>
                            <div>
                                <h1>Registrazioni Form</h1>
                                <p class="suite-barrio-header-sub">Visualizza le registrazioni dei form</p>
                            </div>
                        </div>
                        <hr class="suite-barrio-divider">

                        <?php if ($view_form): ?>
                            <!-- ═══ REGISTRAZIONI DI UN FORM ═══ -->
                            <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-submissions')); ?>"
                                    class="suite-barrio-btn suite-barrio-btn-ghost">← Tutti i form</a>
                                <span style="font-weight:600;font-size:14px;"><?php echo esc_html($view_form->title); ?></span>
                            </div>
                            <div class="suite-barrio-subs-toolbar">
                                <span class="suite-barrio-subs-reg-count" id="suite-barrio-subs-count"></span>
                            </div>
                            <div id="suite-barrio-subs-content"><span class="suite-barrio-subs-spinner"></span> Caricamento…
                            </div>

                            <?php ob_start(); ?>
                            (function () {
                            const AJAX = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                            const NONCE = '<?php echo esc_js($nonce); ?>';
                            const FORM_ID = <?php echo intval($view_id); ?>;

                            let subsData = [], formFields = [];

                            function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

                            function formatCheckValEd(val) {
                            if (typeof val !== 'string' || !/:\s*(true|false)(\s*\||$)/.test(val)) return null;
                            return val.split('|').map(p => p.trim()).filter(Boolean).map(part => {
                            const m = part.match(/^(.+?):\s*(true|false)$/);
                            if (!m) return '';
                            const label = m[1].trim();
                            const checked = m[2] === 'true';
                            const icon = checked ? '\u2714' : '\u2718';
                            const cls = checked ? 'suite-barrio-check-on' : 'suite-barrio-check-off';
                            return '<div class="' + cls + '">' + icon + ' ' + esc(label) + '</div>';
                            }).join('');
                            }

                            function formatCheckSummaryEd(val) {
                            if (typeof val !== 'string' || !/:\s*(true|false)(\s*\||$)/.test(val)) return null;
                            const parts = val.split('|').map(p => p.trim()).filter(Boolean);
                            let total = 0, on = 0;
                            parts.forEach(part => {
                            const m = part.match(/^(.+?):\s*(true|false)$/);
                            if (m) { total++; if (m[2] === 'true') on++; }
                            });
                            return '<span class="suite-barrio-check-summary">' + on + '/' + total + '</span>';
                            }

                            function loadSubmissions() {
                            fetch(AJAX, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({ action: 'suite_barrio_forms_get_submissions', nonce: NONCE, form_id:
                            FORM_ID })
                            }).then(r => r.json()).then(res => {
                            if (!res.success) { document.getElementById('suite-barrio-subs-content').innerHTML = '<p>' +
                                esc(res.data || 'Errore') + '</p>'; return; }
                            subsData = res.data.subs || [];
                            formFields = (res.data.fields || []).filter(f => f.type !== 'submit');
                            document.getElementById('suite-barrio-subs-count').textContent = res.data.total + ' registrazion' +
                            (res.data.total === 1 ? 'e' : 'i');
                            renderList();
                            }).catch(() => { document.getElementById('suite-barrio-subs-content').innerHTML = '<p>Errore di
                                rete.</p>'; });
                            }

                            function renderList() {
                            const content = document.getElementById('suite-barrio-subs-content');
                            if (!subsData.length) { content.innerHTML = '<p style="color:#646970;">Nessuna registrazione.</p>';
                            return; }

                            const listFields = formFields.slice(0, 2);
                            let thead = '<tr>
                                <th>#</th>';
                                listFields.forEach(f => { thead += '<th>' + esc(f.label || f.name || '—') + '</th>'; });
                                thead += '<th>Data</th>
                            </tr>';

                            let tbody = '';
                            subsData.forEach((sub, idx) => {
                            const d = sub.data || {};
                            tbody += '<tr data-idx="' + idx + '">
                                <td>' + sub.id + '</td>';
                                listFields.forEach(f => {
                                const key = f.name || '';
                                const cell = d[key];
                                let val = cell ? cell.value : null;
                                if (Array.isArray(val)) val = val.join(', ');
                                const empty = val === null || val === undefined || val === '';
                                const summary = !empty ? formatCheckSummaryEd(val) : null;
                                tbody += empty ? '<td class="suite-barrio-subs-reg-cell-empty">—</td>' : '<td
                                    title="' + esc(String(val)) + '">' + (summary || esc(String(val))) + '</td>';
                                });
                                tbody += '<td><span class="suite-barrio-subs-reg-date">' + esc(sub.created_at) + '</span></td>';
                                tbody += '
                            </tr>';
                            });

                            content.innerHTML = '<div class="suite-barrio-subs-reg-table-wrap">
                                <table class="suite-barrio-subs-reg-table">
                                    <thead>' + thead + '</thead>
                                    <tbody>' + tbody + '</tbody>
                                </table>
                            </div>';

                            content.querySelectorAll('.suite-barrio-subs-reg-table tbody tr').forEach(row => {
                            row.addEventListener('click', () => { showDetail(parseInt(row.dataset.idx, 10)); });
                            });
                            }

                            function showDetail(idx) {
                            const sub = subsData[idx]; if (!sub) return;

                            let fieldsHtml = '';
                            formFields.filter(f => f.type !== 'file').forEach(f => {
                            const key = f.name || '';
                            const cell = (sub.data || {})[key];
                            let val = cell ? cell.value : null;
                            if (Array.isArray(val)) val = val.join(', ');
                            const empty = val === null || val === undefined || val === '';
                            const checkHtml = !empty ? formatCheckValEd(val) : null;
                            const valHtml = checkHtml || (empty ? '—' : esc(String(val)));

                            fieldsHtml += '<div class="suite-barrio-subs-detail-row">
                                <div class="suite-barrio-subs-detail-label">' + esc(f.label || f.name || '—') + '</div>
                                <div
                                    class="suite-barrio-subs-detail-value' + (empty ? ' suite-barrio-subs-detail-empty' : '') + '">
                                    ' + valHtml + '</div>
                            </div>';
                            });

                            // Sezione allegati (file caricati con prefisso _file_)
                            const fileEntriesEd = Object.entries(sub.data || {}).filter(([k, v]) => k.startsWith('_file_') && v
                            && v.is_file);
                            if (fileEntriesEd.length) {
                            fieldsHtml += '<div class="suite-barrio-subs-detail-sep"
                                style="padding:10px 20px;background:#f0f0f1;font-size:11px;font-weight:700;letter-spacing:.5px;color:#50575e;text-transform:uppercase;border-bottom:1px solid #e0e0e0;">
                                📎 Allegati</div>';
                            fileEntriesEd.forEach(([k, d]) => {
                            const fname = esc(d.value || '');
                            const furl = d.url ? esc(d.url) : '';
                            const flabel = esc(d.label || fname);
                            const fileLink = furl
                            ? '<a href="' + furl + '" target="_blank" rel="noopener" style="font-weight:500;">' + fname + '</a>'
                            : '<span style="color:#a7aaad;">' + fname + ' <em>(URL non disponibile)</em></span>';
                            fieldsHtml += '<div class="suite-barrio-subs-detail-row">
                                <div class="suite-barrio-subs-detail-label">' + flabel + '</div>
                                <div class="suite-barrio-subs-detail-value">' + fileLink + '</div>
                            </div>';
                            });
                            }

                            const metaHtml = [
                            '<span>\uD83D\uDCC5 Data: <strong>' + esc(sub.created_at_full || sub.created_at) +
                                    '</strong></span>',
                            ].filter(Boolean).join('');

                            const content = document.getElementById('suite-barrio-subs-content');
                            content.innerHTML = '<button class="suite-barrio-btn suite-barrio-btn-ghost"
                                id="suite-barrio-subs-back">\u2190 Torna alla lista</button>'
                            + '<div class="suite-barrio-subs-detail-wrap" style="margin-top:14px;">'
                                + '<div class="suite-barrio-subs-detail-header">
                                    <h3>Registrazione #' + sub.id + '</h3>
                                </div>'
                                + '<div class="suite-barrio-subs-detail-meta">' + metaHtml + '</div>'
                                + '<div class="suite-barrio-subs-detail-fields">' + fieldsHtml + '</div>'
                                + '</div>';

                            document.getElementById('suite-barrio-subs-back').addEventListener('click', () => { renderList();
                            });
                            }

                            loadSubmissions();
                            })();
                            <?php
                            $suite_barrio_submissions_js = ob_get_clean();
                            wp_add_inline_script('suite-barrio-forms-submissions', $suite_barrio_submissions_js, 'after');
                            ?>

                        <?php else: ?>
                            <!-- ═══ LISTA FORM ═══ -->
                            <div class="suite-barrio-table-wrap">
                                <?php if (empty($forms)): ?>
                                    <div style="padding:40px 20px;text-align:center;">
                                        <span class="dashicons dashicons-list-view"
                                            style="font-size:40px;width:40px;height:40px;color:#c3c4c7;"></span>
                                        <p style="font-size:14px;margin:10px 0 0;color:#646970;">Nessun form disponibile.</p>
                                    </div>
                                <?php else: ?>
                                    <table class="suite-barrio-table">
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
                                                $subs_url = admin_url('admin.php?page=suite-barrio-submissions&view_id=' . $f->id);
                                                ?>
                                                <tr>
                                                    <td style="color:#a7aaad;font-size:11px;"><?php echo intval($f->id); ?></td>
                                                    <td><strong><?php echo esc_html($f->title); ?></strong></td>
                                                    <td>
                                                        <span
                                                            class="suite-barrio-sub-count <?php echo $f->submissions > 0 ? 'has-subs' : ''; ?>">
                                                            <?php echo intval($f->submissions); ?> registrazioni
                                                        </span>
                                                    </td>
                                                    <td style="font-size:12px;color:#646970;">
                                                        <?php echo esc_html(date_i18n('d/m/Y', strtotime($f->created_at))); ?>
                                                    </td>
                                                    <td>
                                                        <a href="<?php echo esc_url($subs_url); ?>"
                                                            class="suite-barrio-btn suite-barrio-btn-ghost suite-barrio-btn-sm">Vedi
                                                            registrazioni</a>
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
