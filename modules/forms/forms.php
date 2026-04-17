<?php
/**
 * WP Barrio — Forms
 * Builder drag & drop per form di contatto personalizzati.
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('WPB_FORMS_VERSION', '1.0.10');
define('WPB_FORMS_TABLE', 'wpb_forms');
define('WPB_FORMS_SUB_TABLE', 'wpb_form_submissions');

/* ─────────────────────────────────────────
   INSTALL: crea le tabelle al primo avvio
   ───────────────────────────────────────── */
register_activation_hook(WP_ALT_AI_PLUGIN_DIR . 'wp-alt-ai.php', 'wpb_forms_install');

function wpb_forms_install()
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // CREATE TABLE IF NOT EXISTS — non dipende da dbDelta
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
            ip         VARCHAR(45)     NOT NULL DEFAULT '',
            user_agent TEXT,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id)
        ) {$charset};"
    );
}

add_action('plugins_loaded', 'wpb_forms_install');

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
            color: #d63638;
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
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            background: #fff;
            box-sizing: border-box;
            transition: border-color .15s;
        }

        .wpb-form-wrap input:focus,
        .wpb-form-wrap select:focus,
        .wpb-form-wrap textarea:focus {
            outline: none;
            border-color: #2271b1;
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
            background: #2271b1;
            color: #fff;
            border: none;
            border-radius: 4px;
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
            border-radius: 4px;
            font-size: 14px;
            margin-top: 12px;
        }

        .wpb-form-msg.success {
            background: #edfaef;
            border: 1px solid #00a32a;
            color: #00a32a;
        }

        .wpb-form-msg.error {
            background: #fcf0f1;
            border: 1px solid #d63638;
            color: #d63638;
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
    // Logica condizionale front-end — costruisce la mappa trigger→regole
    $cond_rules = [];
    foreach ($fields as $field) {
        foreach ($field['showFields'] ?? [] as $rule) {
            if (empty($rule['targets']))
                continue;
            $trigger_name = $field['name'] ?? '';
            if (!$trigger_name)
                continue;
            if (!isset($cond_rules[$trigger_name]))
                $cond_rules[$trigger_name] = [];
            $cond_rules[$trigger_name][] = [
                'targets' => (array) $rule['targets'],
                'operator' => $rule['operator'] ?? '=',
                'value' => $rule['value'] ?? '',
            ];
        }
    }

    // Calcola quali campi partono nascosti (sono target di almeno una regola)
    $initially_hidden = [];
    foreach ($cond_rules as $rules) {
        foreach ($rules as $rule) {
            foreach ($rule['targets'] as $t) {
                $initially_hidden[$t] = true;
            }
        }
    }

    if (!empty($cond_rules)):
        ?>
        <script>
            (function () {
                var wrap = document.getElementById('<?php echo esc_js($form_id_css); ?>');
                if (!wrap) return;

                // Mappa trigger → regole
                var RULES = <?php echo wp_json_encode($cond_rules); ?>;

                // Nascondi i campi che partono come target di una condizione
                var hiddenByDefault = <?php echo wp_json_encode(array_keys($initially_hidden)); ?>;
                hiddenByDefault.forEach(function (name) {
                    var el = wrap.querySelector('[data-field-name="' + name + '"]');
                    if (el) { el.style.display = 'none'; el.dataset.condHidden = '1'; }
                });

                // Helpers
                function getFieldValue(name) {
                    var inputs = wrap.querySelectorAll('[name="' + name + '"], [name="' + name + '[]"]');
                    if (!inputs.length) return '';
                    var type = inputs[0].type;
                    if (type === 'checkbox') {
                        return Array.from(inputs).filter(i => i.checked).map(i => String(i.value));
                    }
                    if (type === 'radio') {
                        var checked = Array.from(inputs).find(i => i.checked);
                        return checked ? String(checked.value) : '';
                    }
                    return String(inputs[0].value || '');
                }

                function testRule(rule, val) {
                    var v = Array.isArray(val) ? val : [val];
                    var rv = String(rule.value || '');
                    switch (rule.operator) {
                        case '=': return v.some(function (x) { return x === rv; });
                        case '!=': return v.every(function (x) { return x !== rv; });
                        case 'contains': return v.some(function (x) { return x.indexOf(rv) !== -1; });
                        case 'empty': return v.every(function (x) { return x === ''; });
                        case 'notempty': return v.some(function (x) { return x !== ''; });
                        default: return false;
                    }
                }

                function applyRules(triggerName) {
                    var rules = RULES[triggerName];
                    if (!rules) return;
                    var val = getFieldValue(triggerName);
                    rules.forEach(function (rule) {
                        var show = testRule(rule, val);
                        rule.targets.forEach(function (targetName) {
                            var el = wrap.querySelector('[data-field-name="' + targetName + '"]');
                            if (!el) return;
                            el.style.display = show ? '' : 'none';
                            el.dataset.condHidden = show ? '0' : '1';
                            // Se nascosto, disabilita i campi interni per non includerli nel submit
                            el.querySelectorAll('input,select,textarea').forEach(function (inp) {
                                inp.disabled = !show;
                            });
                        });
                    });
                }

                // Attacca listener a tutti i trigger
                Object.keys(RULES).forEach(function (triggerName) {
                    var inputs = wrap.querySelectorAll('[name="' + triggerName + '"], [name="' + triggerName + '[]"]');
                    inputs.forEach(function (inp) {
                        inp.addEventListener('change', function () { applyRules(triggerName); });
                        inp.addEventListener('input', function () { applyRules(triggerName); });
                    });
                    // Valuta subito lo stato iniziale
                    applyRules(triggerName);
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
function wpb_forms_send_notifications(object $form, array $settings, array $submission_data): array
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
                    continue;
                $label = esc_html($d['label']);
                $val = is_array($d['value']) ? esc_html(implode(', ', $d['value'])) : esc_html($d['value']);
                $rows .= "<tr>
                    <td style=\"padding:8px 12px;font-size:13px;font-weight:600;color:#50575e;background:#f6f7f7;border-bottom:1px solid #e8e8e8;width:35%;\">{$label}</td>
                    <td style=\"padding:8px 12px;font-size:13px;color:#111111;border-bottom:1px solid #e8e8e8;\">{$val}</td>
                </tr>";
            }
            $content_html = "<table style=\"width:100%;border-collapse:collapse;border:1px solid #e8e8e8;border-radius:4px;overflow:hidden;\">{$rows}</table>";
            $content_html .= "<p style=\"margin:16px 0 0;font-size:12px;color:#a7aaad;\">Inviato il " . current_time('d/m/Y \a\l\l\e H:i') . "</p>";

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
            $sent = wpb_mailjet_send($mj_api_key, $mj_secret_key, $to, $subject, $html, $headers);
            $method = 'Mailjet';
        } else {
            $sent = wp_mail($to, $subject, $html, $headers);
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
    array $headers = []
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

    foreach ($fields as $field) {
        if (($field['type'] ?? '') === 'page_break')
            continue;
        if (empty($field['required']))
            continue;
        $fid = $field['name'] ?? '';
        $val = $_POST[$fid] ?? '';
        if (is_array($val))
            $val = implode(',', $val);
        if ('' === trim($val)) {
            $err = !empty($field['error_message'])
                ? $field['error_message']
                : 'Il campo "' . esc_html($field['label']) . '" è obbligatorio.';
            wp_send_json_error($err);
        }
    }

    $submission_data = [];
    foreach ($fields as $field) {
        if (($field['type'] ?? '') === 'page_break')
            continue;
        $fid = $field['name'] ?? '';
        $val = $_POST[$fid] ?? '';
        $val = is_array($val) ? array_map('sanitize_text_field', $val) : sanitize_textarea_field($val);
        $submission_data[$fid] = ['label' => $field['label'] ?? $fid, 'value' => $val];
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

    $wpdb->insert(
        $wpdb->prefix . WPB_FORMS_SUB_TABLE,
        [
            'form_id' => $form_id,
            'data' => wp_json_encode($submission_data),
            'ip' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ],
        ['%d', '%s', '%s', '%s']
    );

    // Invia le notifiche email tramite la funzione condivisa
    // (riutilizzata anche dalla mutation GraphQL per il frontend Next.js)
    wpb_forms_send_notifications($form, $settings, $submission_data);

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

    wp_send_json_success('Impostazioni salvate.');
});

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

            // Normalizza showFields: converte il vecchio formato {fields} nel nuovo {targets}
            if (!isset($field['showFields']))
                continue;
            foreach ($field['showFields'] as &$rule) {
                // Se ha la chiave vecchia "fields" ma non "targets", migra
                if (isset($rule['fields']) && !isset($rule['targets'])) {
                    $rule['targets'] = $rule['fields'];
                }
                unset($rule['fields']); // rimuovi l'alias dal DB
            }
            unset($rule);
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
    $wpdb->delete($wpdb->prefix . WPB_FORMS_TABLE, ['id' => $id], ['%d']);
    $wpdb->delete($wpdb->prefix . WPB_FORMS_SUB_TABLE, ['form_id' => $id], ['%d']);
    wp_send_json_success('Form eliminato.');
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
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');
    global $wpdb;
    $id = intval($_POST['submission_id'] ?? 0);
    if (!$id)
        wp_send_json_error('ID non valido.');
    $wpdb->delete($wpdb->prefix . WPB_FORMS_SUB_TABLE, ['id' => $id], ['%d']);
    wp_send_json_success('Risposta eliminata.');
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
}, 11);

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

    ?>
    <style>
        #wpb-forms-wrap {
            max-width: 1200px;
        }

        .wpb-forms-table-wrap {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            overflow: hidden;
        }

        .wpb-forms-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .wpb-forms-table th {
            background: #f0f0f1;
            padding: 9px 14px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: #50575e;
            border-bottom: 1px solid #c3c4c7;
        }

        .wpb-forms-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f0f0f1;
            vertical-align: middle;
        }

        .wpb-forms-table tr:last-child td {
            border-bottom: none;
        }

        .wpb-forms-table tr:hover td {
            background: #fafafa;
        }

        .wpb-forms-empty {
            text-align: center;
            padding: 48px 20px;
            color: #646970;
        }

        .wpb-forms-empty .dashicons {
            font-size: 48px;
            width: 48px;
            height: 48px;
            color: #c3c4c7;
        }

        .wpb-sub-count {
            display: inline-block;
            padding: 1px 9px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            background: #f0f0f1;
            color: #1d2327;
        }

        .wpb-sub-count.has-subs {
            background: #edfaef;
            color: #00a32a;
        }

        /* ── BUILDER ── */
        .wpb-builder-layout {
            display: grid;
            grid-template-columns: 240px 1fr 280px;
            gap: 0;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            overflow: hidden;
            background: #fff;
            height: 600px;
        }

        .wpb-panel-fields {
            background: #f6f7f7;
            border-right: 1px solid #c3c4c7;
            overflow-y: auto;
            height: 100%;
        }

        .wpb-panel-fields h3 {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #646970;
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
            color: #1d2327;
            text-align: left;
            transition: background .1s;
        }

        .wpb-field-type-btn:hover {
            background: #fff;
        }

        .wpb-ft-icon {
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

        .wpb-field-type-btn:hover .wpb-ft-icon {
            background: #d0d5dd;
        }

        .wpb-panel-canvas {
            padding: 20px;
            background: #fafafa;
            overflow-y: auto;
            border-right: 1px solid #c3c4c7;
            height: 100%;
            box-sizing: border-box;
        }

        .wpb-canvas-title {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #646970;
            margin: 0 0 14px;
        }

        .wpb-canvas-drop-zone {
            min-height: 380px;
            border: 2px dashed #c3c4c7;
            border-radius: 4px;
            padding: 10px;
            transition: border-color .15s, background .15s;
        }

        .wpb-canvas-drop-zone.drag-over {
            border-color: #2271b1;
            background: rgba(34, 113, 177, .04);
        }

        .wpb-canvas-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 260px;
            color: #a7aaad;
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
            border-radius: 3px;
            margin-bottom: 8px;
            cursor: grab;
            transition: box-shadow .1s, border-color .1s;
        }

        .wpb-field-card:active {
            cursor: grabbing;
        }

        .wpb-field-card.selected {
            border-color: #2271b1;
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
            border-bottom: 1px solid #f0f0f1;
        }

        .wpb-drag-handle {
            color: #a7aaad;
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
            color: #646970;
            background: #f0f0f1;
            padding: 1px 7px;
            border-radius: 3px;
        }

        .wpb-btn-remove-field {
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
            border-radius: 3px;
            font-size: 12px;
            color: #a7aaad;
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
            border-radius: 3px;
            background: #fafafa;
            font-size: 12px;
            color: #a7aaad;
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
            color: #646970;
        }

        .wpb-preview-radio,
        .wpb-preview-checkbox {
            width: 13px;
            height: 13px;
            border: 1.5px solid #c3c4c7;
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
            border-radius: 3px;
            background: #fafafa;
            font-size: 12px;
            color: #a7aaad;
        }

        /* Preview file */
        .wpb-preview-file {
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

        .wpb-panel-props {
            overflow-y: auto;
            height: 100%;
        }

        .wpb-panel-props h3 {
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
            color: #50575e;
            margin-bottom: 5px;
        }

        .wpb-prop-group input[type=text],
        .wpb-prop-group input[type=number],
        .wpb-prop-group textarea,
        .wpb-prop-group select {
            width: 100%;
            padding: 6px 9px;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            font-size: 12px;
            font-family: inherit;
            background: #fff;
            box-sizing: border-box;
        }

        .wpb-prop-group input:focus,
        .wpb-prop-group textarea:focus,
        .wpb-prop-group select:focus {
            outline: none;
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
        }

        .wpb-prop-check {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #1d2327;
            cursor: pointer;
            font-weight: 400;
        }

        .wpb-no-field-selected {
            text-align: center;
            padding: 40px 16px;
            color: #a7aaad;
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
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            font-family: inherit;
        }

        .wpb-builder-title-input:focus {
            outline: none;
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
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
            border: 1px solid #c3c4c7;
            border-radius: 3px;
        }

        .wpb-btn-rm-opt {
            background: none;
            border: none;
            color: #d63638;
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            padding: 0;
        }

        .wpb-btn-add-opt {
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

        .wpb-btn-add-opt:hover {
            border-color: #2271b1;
            color: #2271b1;
        }

        /* ── Logica condizionale ── */
        .wpb-cond-section {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid #f0f0f1;
        }

        .wpb-cond-section-title {
            font-size: 11px;
            font-weight: 600;
            color: #50575e;
            text-transform: uppercase;
            letter-spacing: .4px;
            margin: 0 0 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .wpb-cond-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 6px;
        }

        .wpb-cond-row {
            background: #f6f7f7;
            border: 1px solid #e0e0e0;
            border-radius: 3px;
            padding: 8px 10px;
            display: flex;
            flex-direction: column;
            gap: 5px;
            position: relative;
        }

        .wpb-cond-row-header {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: #646970;
        }

        .wpb-cond-row-header select {
            flex: 1;
            padding: 3px 6px;
            font-size: 11px;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            background: #fff;
            font-family: inherit;
        }

        .wpb-cond-then {
            font-size: 11px;
            color: #646970;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .wpb-cond-then select {
            flex: 1;
            padding: 3px 6px;
            font-size: 11px;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            background: #fff;
            font-family: inherit;
        }

        .wpb-btn-rm-cond {
            background: none;
            border: none;
            cursor: pointer;
            color: #d63638;
            font-size: 15px;
            line-height: 1;
            padding: 0;
            flex-shrink: 0;
            opacity: .6;
        }

        .wpb-btn-rm-cond:hover {
            opacity: 1;
        }

        .wpb-btn-add-cond {
            font-size: 11px;
            background: none;
            border: 1px dashed #c3c4c7;
            border-radius: 3px;
            padding: 4px 10px;
            cursor: pointer;
            color: #646970;
            width: 100%;
        }

        .wpb-btn-add-cond:hover {
            border-color: #2271b1;
            color: #2271b1;
        }

        .wpb-builder-tabs {
            display: flex;
            border-bottom: 1px solid #c3c4c7;
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
            color: #646970;
            margin-bottom: -1px;
        }

        .wpb-btab.active {
            color: #2271b1;
            border-bottom-color: #2271b1;
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
            color: #646970;
        }

        .wpb-reg-empty {
            text-align: center;
            padding: 48px 20px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            color: #646970;
            font-size: 13px;
        }

        .wpb-reg-table-wrap {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            overflow: hidden;
            overflow-x: auto;
        }

        .wpb-reg-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 500px;
        }

        .wpb-reg-table thead tr {
            background: #f0f0f1;
        }

        .wpb-reg-table th {
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

        .wpb-reg-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #f0f0f1;
            vertical-align: top;
            font-size: 12px;
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .wpb-reg-table td.wpb-reg-cell-empty {
            color: #c3c4c7;
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
            color: #a7aaad;
            font-size: 11px;
            width: 40px;
        }

        .wpb-reg-table td:last-child {
            width: 60px;
            text-align: center;
        }

        .wpb-reg-date {
            font-size: 11px;
            color: #646970;
            white-space: nowrap;
        }

        /* Toggle dopo-invio */
        .wpb-after-submit-toggle {
            display: flex;
            gap: 0;
            margin-bottom: 10px;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            overflow: hidden;
        }

        .wpb-ast-btn {
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

        .wpb-ast-btn:last-child {
            border-right: none;
        }

        .wpb-ast-btn.active {
            background: #2271b1;
            color: #fff;
        }

        /* Submissions */
        .wpb-sub-table-wrap {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
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

        .wpb-sub-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #f0f0f1;
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
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            max-width: 760px;
        }

        .wpb-sub-detail-header {
            padding: 16px 20px;
            border-bottom: 1px solid #f0f0f1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .wpb-sub-detail-header h3 {
            margin: 0;
            font-size: 14px;
            color: #1d2327;
        }

        .wpb-sub-detail-meta {
            padding: 12px 20px;
            background: #f6f7f7;
            border-bottom: 1px solid #f0f0f1;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .wpb-sub-detail-meta span {
            font-size: 12px;
            color: #646970;
        }

        .wpb-sub-detail-meta strong {
            color: #1d2327;
        }

        .wpb-sub-detail-fields {
            padding: 0;
        }

        .wpb-sub-detail-row {
            display: grid;
            grid-template-columns: 200px 1fr;
            padding: 12px 20px;
            border-bottom: 1px solid #f0f0f1;
            gap: 16px;
        }

        .wpb-sub-detail-row:last-child {
            border-bottom: none;
        }

        .wpb-sub-detail-label {
            font-size: 12px;
            font-weight: 600;
            color: #50575e;
            text-transform: uppercase;
            letter-spacing: .3px;
            padding-top: 1px;
        }

        .wpb-sub-detail-value {
            font-size: 13px;
            color: #1d2327;
            word-break: break-word;
            white-space: pre-wrap;
        }

        .wpb-sub-detail-value.wpb-empty {
            color: #a7aaad;
            font-style: italic;
        }

        .wpb-sub-detail-sep {
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
        .wpb-resend-wrap {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            max-width: 760px;
            margin-top: 14px;
            overflow: hidden;
        }

        .wpb-resend-header {
            padding: 14px 20px;
            border-bottom: 1px solid #f0f0f1;
            background: #f6f7f7;
        }

        .wpb-resend-header h4 {
            margin: 0;
            font-size: 13px;
            color: #1d2327;
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
            color: #50575e;
            margin-bottom: 5px;
        }

        .wpb-resend-field select,
        .wpb-resend-field input[type=email] {
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

        .wpb-resend-field select:focus,
        .wpb-resend-field input:focus {
            outline: none;
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
        }

        .wpb-resend-field .description {
            font-size: 11px;
            color: #a7aaad;
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
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            margin-bottom: 10px;
            overflow: hidden;
            transition: box-shadow .15s;
        }

        .wpb-notif-card:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
        }

        .wpb-notif-card.is-open {
            border-color: #2271b1;
        }

        .wpb-notif-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            cursor: pointer;
            user-select: none;
            background: #f6f7f7;
        }

        .wpb-notif-card.is-open .wpb-notif-header {
            background: #f0f6fc;
            border-bottom: 1px solid #c8d8f0;
        }

        .wpb-notif-header-icon {
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

        .wpb-notif-header-info {
            flex: 1;
            min-width: 0;
        }

        .wpb-notif-header-title {
            font-size: 13px;
            font-weight: 600;
            color: #1d2327;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .wpb-notif-header-sub {
            font-size: 11px;
            color: #646970;
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
            color: #646970;
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
            color: #d63638;
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
            color: #a7aaad;
            margin: 0 0 10px;
            padding-bottom: 7px;
            border-bottom: 1px solid #f0f0f1;
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
            color: #50575e;
        }

        .wpb-notif-field .opt {
            font-weight: 400;
            color: #a7aaad;
            margin-left: 4px;
        }

        .wpb-notif-field input,
        .wpb-notif-field textarea {
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

        .wpb-notif-field input:focus,
        .wpb-notif-field textarea:focus {
            outline: none;
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
        }

        .wpb-notif-field textarea {
            resize: vertical;
            min-height: 80px;
        }

        .wpb-notif-field .hint {
            font-size: 11px;
            color: #a7aaad;
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

        .wpb-field-var-pill:hover {
            background: #dbeafe;
            border-color: #2271b1;
        }

        .wpb-notif-empty-state {
            text-align: center;
            padding: 40px 20px;
            background: #fff;
            border: 2px dashed #e0e0e0;
            border-radius: 4px;
            color: #a7aaad;
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

        .wpb-notif-add-btn:hover {
            background: #f0f6fc;
        }

        /* Toast */
        #wpb-forms-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #1d2327;
            color: #fff;
            padding: 10px 18px;
            border-radius: 3px;
            font-size: 13px;
            font-weight: 500;
            z-index: 9999;
            opacity: 0;
            transform: translateY(6px);
            transition: all .2s;
            pointer-events: none;
            border-left: 4px solid #00a32a;
        }

        #wpb-forms-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        #wpb-forms-toast.error {
            border-left-color: #d63638;
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
            border-radius: 4px;
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
            background: #fcf0f1;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
        }

        .wpb-modal-box h3 {
            font-size: 15px;
            margin: 0 0 8px;
            color: #1d2327;
        }

        .wpb-modal-box p {
            font-size: 13px;
            color: #646970;
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
            border: 1px solid #c3c4c7;
            border-radius: 3px;
            cursor: pointer;
            color: #1d2327;
            transition: border-color .1s;
        }

        .wpb-modal-btn-cancel:hover {
            border-color: #1d2327;
        }

        .wpb-modal-btn-confirm {
            padding: 7px 20px;
            font-size: 13px;
            font-family: inherit;
            font-weight: 600;
            background: #d63638;
            border: 1px solid #d63638;
            border-radius: 3px;
            cursor: pointer;
            color: #fff;
            transition: background .1s;
        }

        .wpb-modal-btn-confirm:hover {
            background: #b32d2e;
            border-color: #b32d2e;
        }
    </style>

    <div id="wpb-forms-wrap" class="wrap">
        <?php
        $mj_global = get_option('wpb_forms_mailjet', []);
        $mj_missing = empty(trim($mj_global['api_key'] ?? '')) || empty(trim($mj_global['secret_key'] ?? ''));
        if ($mj_missing): ?>
            <div
                style="background:#fcf9e8;border:1px solid #dba617;border-left:4px solid #dba617;border-radius:3px;padding:30px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:16px;color:#6e4e00;">
                <span class="dashicons dashicons-warning" style="color:#dba617;font-size:18px;width:18px;height:18px;"></span>
                <span>Le credenziali Mailjet non sono configurate.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-forms&tab=settings')); ?>"
                        style="color:#6e4e00;font-weight:600;">Vai alle impostazioni &rarr;</a></span>
            </div>
        <?php endif; ?>
        <h1 class="wp-heading-inline">
            <span class="dashicons dashicons-feedback"
                style="font-size:22px;width:22px;height:22px;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
            WP Barrio <span style="font-size:15px;font-weight:400;color:#646970;margin-left:4px;">/ Forms</span>
        </h1>

        <?php if ($tab === 'list' || $tab === 'settings'): ?>
            <?php if ($tab === 'list'): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-forms&tab=builder')); ?>"
                    class="page-title-action">+ Nuovo form</a>
            <?php endif; ?>
            <hr class="wp-header-end" style="margin-bottom:20px;">

            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <a href="?page=wp-barrio-forms" class="nav-tab <?php echo $tab === 'list' ? 'nav-tab-active' : ''; ?>">Form</a>
                <a href="?page=wp-barrio-forms&tab=settings"
                    class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">Impostazioni</a>
            </nav>
        <?php else: ?>
            <hr class="wp-header-end" style="margin-bottom:20px;">
        <?php endif; ?>

        <?php /* ══════ IMPOSTAZIONI GLOBALI ══════ */ if ($tab === 'settings'):
            $mj_opts = get_option('wpb_forms_mailjet', []);
            ?>
            <div style="max-width:600px;">
                <div style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:24px;margin-bottom:20px;">
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
                                            style="background:none;border:1px solid #c3c4c7;border-radius:3px;padding:5px 9px;cursor:pointer;color:#646970;font-size:12px;white-space:nowrap;"
                                            onclick="(function(){var i=document.getElementById('wpb-global-mj-secret-key');i.type=i.type==='password'?'text':'password';})();">Mostra</button>
                                    </div>
                                    <p class="description">Trovate su <a href="https://app.mailjet.com/account/api_keys"
                                            target="_blank">app.mailjet.com → Account → API Keys</a>.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p style="margin:16px 0 0;">
                        <button type="button" class="button button-primary" id="wpb-btn-save-global">
                            Salva impostazioni
                        </button>
                        <span id="wpb-global-saved-msg" style="display:none;margin-left:10px;color:#00a32a;font-size:13px;">✓
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
                            class="button button-primary">+ Crea il primo form</a>
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
                                $subs_url = admin_url('admin.php?page=wp-barrio-forms&tab=builder&edit_id=' . $f->id . '#wpb-registrazioni');
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
                                            <span class="wpb-sub-count <?php echo $f->submissions > 0 ? 'has-subs' : ''; ?>">
                                                <?php echo intval($f->submissions); ?> registrazioni
                                            </span>
                                        </a>
                                    </td>
                                    <td style="font-size:12px;color:#646970;">
                                        <?php echo date_i18n('d/m/Y', strtotime($f->created_at)); ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">Modifica</a>
                                        <button class="button button-small wpb-btn-delete-form"
                                            style="color:#d63638;border-color:#d63638;margin-left:4px;"
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

            <div class="wpb-builder-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-forms')); ?>" class="button">← Tutti i
                    form</a>
                <input type="text" id="wpb-form-title" class="wpb-builder-title-input"
                    value="<?php echo esc_attr($edit_form ? $edit_form->title : ''); ?>" placeholder="Nome del form…">
                <button class="button button-primary" id="wpb-btn-save-form">Salva form</button>
            </div>

            <div class="wpb-builder-tabs">
                <button class="wpb-btab active" data-tab="fields">Campi</button>
                <button class="wpb-btab" data-tab="settings">Impostazioni</button>
                <button class="wpb-btab" data-tab="notifiche">Notifiche</button>
                <?php if ($edit_id): ?>
                    <button class="wpb-btab" data-tab="registrazioni">Registrazioni</button>
                <?php endif; ?>
            </div>

            <!-- TAB CAMPI -->
            <div id="wpb-tab-fields">
                <div class="wpb-builder-layout">

                    <!-- Sx: tipi campo -->
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
                    <table class="form-table" style="max-width:100%;">
                        <tbody>

                            <tr>
                                <th style="width:200px;"><label>Testo pulsante invia</label></th>
                                <td><input type="text" id="wpb-setting-submit-text" class="regular-text" placeholder="Invia">
                                </td>
                            </tr>

                            <tr>
                                <th><label for="wpb-setting-display-page-info">Informazioni pagina</label></th>
                                <td>
                                    <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer;">
                                        <input type="checkbox" id="wpb-setting-display-page-info" value="1">
                                        Registra URL e titolo della pagina al momento dell'invio
                                    </label>
                                </td>
                            </tr>

                            <tr>
                                <th style="vertical-align:top;padding-top:14px;"><label>Conferma di invio</label></th>
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

                        </tbody>
                    </table>
                </div>
            </div><!-- #wpb-tab-settings -->

            <!-- TAB NOTIFICHE -->
            <div id="wpb-tab-notifiche" style="display:none;">

                <!-- Impostazioni globali email -->
                <div
                    style="background:#fff;border:1px solid #c3c4c7;border-radius:3px;padding:24px;max-width:600px;margin-bottom:20px;">
                    <h3
                        style="margin:0 0 16px;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:#50575e;">
                        Stile email</h3>
                    <table class="form-table" style="max-width:100%;">
                        <tbody>
                            <tr>
                                <th style="width:220px;"><label for="wpb-setting-email-bg-color">Colore di sfondo</label></th>
                                <td style="display:flex;align-items:center;gap:10px;">
                                    <input type="color" id="wpb-setting-email-bg-color" value="#f4f4f4"
                                        style="width:44px;height:34px;padding:2px;border:1px solid #c3c4c7;border-radius:3px;cursor:pointer;">
                                    <input type="text" id="wpb-setting-email-bg-color-hex" class="small-text"
                                        placeholder="#f4f4f4" maxlength="7" style="width:90px;">
                                </td>
                            </tr>
                            <tr>
                                <th><label for="wpb-setting-email-header-color">Colore header</label></th>
                                <td style="display:flex;align-items:center;gap:10px;">
                                    <input type="color" id="wpb-setting-email-header-color" value="#2271b1"
                                        style="width:44px;height:34px;padding:2px;border:1px solid #c3c4c7;border-radius:3px;cursor:pointer;">
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
                                        <button type="button" class="button" id="wpb-btn-media-logo"
                                            style="white-space:nowrap;">Scegli immagine</button>
                                    </div>
                                    <div id="wpb-logo-preview" style="margin-top:8px;display:none;">
                                        <img src="" alt="Anteprima logo"
                                            style="max-width:200px;max-height:60px;border:1px solid #c3c4c7;border-radius:3px;padding:4px;background:#fff;">
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
                <div style="max-width:600px;">
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
                <!-- TAB REGISTRAZIONI -->
                <div id="wpb-tab-registrazioni" style="display:none;">

                    <div class="wpb-reg-toolbar">
                        <span class="wpb-reg-count" id="wpb-reg-count"></span>
                        <button class="wpb-btn-reg-delete-all button" id="wpb-btn-reg-delete-all"
                            style="color:#d63638;border-color:#d63638;display:none;">
                            Elimina tutte
                        </button>
                    </div>

                    <div id="wpb-reg-content">
                        <div class="wpb-reg-empty" id="wpb-reg-loading">
                            <span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
                            Caricamento registrazioni…
                        </div>
                    </div>

                </div><!-- #wpb-tab-registrazioni -->
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
        (function () {
            const NONCE = '<?php echo esc_js($nonce); ?>';
            const AJAX = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            const EDIT_ID = <?php echo intval($builder_form_id); ?>;

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
                                                <div style="display:flex;gap:0;margin-bottom:8px;border:1px solid #c3c4c7;border-radius:3px;overflow:hidden;">
                                                    <button type="button" class="wpb-msg-mode-btn${!isCustom ? ' active' : ''}"
                                                            data-mode="auto"
                                                            style="flex:1;padding:5px 10px;font-size:11px;font-weight:600;font-family:inherit;border:none;cursor:pointer;background:${!isCustom ? '#2271b1' : '#f6f7f7'};color:${!isCustom ? '#fff' : '#646970'};">
                                                        Auto (tutti i campi)
                                                    </button>
                                                    <button type="button" class="wpb-msg-mode-btn${isCustom ? ' active' : ''}"
                                                            data-mode="custom"
                                                            style="flex:1;padding:5px 10px;font-size:11px;font-weight:600;font-family:inherit;border:none;border-left:1px solid #c3c4c7;cursor:pointer;background:${isCustom ? '#2271b1' : '#f6f7f7'};color:${isCustom ? '#fff' : '#646970'};">
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
                            b.style.background = isActive ? '#2271b1' : '#f6f7f7';
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
        let regLoaded = false;

        document.querySelectorAll('.wpb-btab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.wpb-btab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById('wpb-tab-fields').style.display = tab.dataset.tab === 'fields' ? '' : 'none';
                document.getElementById('wpb-tab-settings').style.display = tab.dataset.tab === 'settings' ? '' : 'none';
                document.getElementById('wpb-tab-notifiche').style.display = tab.dataset.tab === 'notifiche' ? '' : 'none';
                const regPanel = document.getElementById('wpb-tab-registrazioni');
                if (regPanel) {
                    regPanel.style.display = tab.dataset.tab === 'registrazioni' ? '' : 'none';
                    if (tab.dataset.tab === 'registrazioni' && !regLoaded) {
                        loadRegistrazioni();
                    }
                }
            });
        });

        // Auto-apre il tab Registrazioni se richiesto via hash
        if (window.location.hash === '#wpb-registrazioni') {
            history.replaceState(null, '', window.location.pathname + window.location.search);
            const regBtn = document.querySelector('.wpb-btab[data-tab="registrazioni"]');
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
            dropZone.querySelectorAll('.wpb-field-card').forEach(c => c.remove());
            emptyState.style.display = fields.length === 0 ? 'flex' : 'none';
            fields.forEach((f, i) => dropZone.appendChild(buildCard(f, i)));
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
                preview = `<div class="wpb-preview-page-break"><hr style="border:none;border-top:2px dashed #2271b1;margin:0;"><span style="display:block;text-align:center;font-size:11px;color:#2271b1;margin-top:4px;font-weight:600;">↓ Pagina successiva ↓</span></div>`;
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
                card.style[e.clientY < mid ? 'borderTop' : 'borderBottom'] = '2px solid #2271b1';
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
                            <span class="dashicons dashicons-minus" style="font-size:28px;width:28px;height:28px;color:#2271b1;"></span>
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
                                                                                                                                                            <p style="font-size:11px;color:#a7aaad;margin:0 0 8px;line-height:1.5;">
                                                                                                                                                                Questo campo mostra altri campi quando vengono soddisfatte le condizioni seguenti.
                                                                                                                                                            </p>
                                                                                                                                                            <div class="wpb-cond-list" id="wpb-cond-list">
                                                                                                                                                                ${(f.showFields || []).map((rule, ri) => renderCondRow(rule, ri, f)).join('')}
                                                                                                                                                            </div>
                                                                                                                                                            <button class="wpb-btn-add-cond" id="wpb-btn-add-cond">+ Aggiungi regola</button>
                                                                                                                                                        </div>`;

            const bi = (elId, key) => { const el = document.getElementById(elId); if (el) el.addEventListener('input', () => updateField(f.id, key, el.value)); };

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

        /* ── Logica condizionale: render riga regola ── */
        function renderCondRow(rule, ri, f) {
            const otherFields = fields.filter(x => x.id !== f.id && x.type !== 'page_break');
            // Supporta sia il nuovo formato (targets) che il vecchio (fields)
            const selected = rule.targets || rule.fields || [];
            const targetOpts = otherFields.map(x =>
                `<option value="${esc(x.name)}" ${selected.includes(x.name) ? 'selected' : ''}>${esc(x.label || x.name)}</option>`
            ).join('');

            const ops = [
                { v: '=', l: 'è uguale a' },
                { v: '!=', l: 'è diverso da' },
                { v: 'contains', l: 'contiene' },
                { v: 'empty', l: 'è vuoto' },
                { v: 'notempty', l: 'non è vuoto' },
            ];
            const opOpts = ops.map(o =>
                `<option value="${o.v}" ${(rule.operator || '=') === o.v ? 'selected' : ''}>${o.l}</option>`
            ).join('');

            const needsValue = !['empty', 'notempty'].includes(rule.operator || '=');

            return `<div class="wpb-cond-row" data-ri="${ri}">
                                                                                                                                                        <div class="wpb-cond-row-header">
                                                                                                                                                            <span style="flex-shrink:0;font-size:11px;color:#646970;">Mostra</span>
                                                                                                                                                            <select class="wpb-cond-target" data-ri="${ri}" multiple size="3"
                                                                                                                                                                    style="flex:1;padding:3px 4px;font-size:11px;border:1px solid #c3c4c7;border-radius:3px;background:#fff;font-family:inherit;height:auto;">
                                                                                                                                                                ${targetOpts || '<option disabled>Nessun altro campo</option>'}
                                                                                                                                                            </select>
                                                                                                                                                            <button class="wpb-btn-rm-cond" data-ri="${ri}" title="Rimuovi">×</button>
                                                                                                                                                        </div>
                                                                                                                                                        <div class="wpb-cond-then" style="font-size:11px;color:#646970;display:flex;align-items:center;gap:6px;margin-top:4px;">
                                                                                                                                                            <span style="flex-shrink:0;">quando</span>
                                                                                                                                                            <select class="wpb-cond-op" data-ri="${ri}"
                                                                                                                                                                    style="flex:1;padding:3px 6px;font-size:11px;border:1px solid #c3c4c7;border-radius:3px;background:#fff;font-family:inherit;">
                                                                                                                                                                ${opOpts}
                                                                                                                                                            </select>
                                                                                                                                                        </div>
                                                                                                                                                        ${needsValue ? `<div style="font-size:11px;display:flex;align-items:center;gap:6px;margin-top:4px;">
                    <span style="flex-shrink:0;color:#646970;width:52px;">valore</span>
                    <input type="text" class="wpb-cond-val" data-ri="${ri}"
                           value="${esc(rule.value || '')}" placeholder="Valore atteso…"
                           style="flex:1;padding:3px 7px;font-size:11px;border:1px solid #c3c4c7;border-radius:3px;font-family:inherit;">
                </div>` : ''}
                                                                                                                                                    </div>`;
        }

        /* ── Logica condizionale: bind eventi ── */
        function bindCondEvents(f) {
            const list = document.getElementById('wpb-cond-list');
            if (!list) return;

            function readAllRules() {
                const rules = [];
                list.querySelectorAll('.wpb-cond-row').forEach(row => {
                    const targetSel = row.querySelector('.wpb-cond-target');
                    const opSel = row.querySelector('.wpb-cond-op');
                    const valInp = row.querySelector('.wpb-cond-val');
                    if (!targetSel || !opSel) return;
                    const targets = Array.from(targetSel.selectedOptions).map(o => o.value).filter(Boolean);
                    if (!targets.length) return;
                    rules.push({
                        targets: targets,
                        operator: opSel.value,
                        value: valInp ? valInp.value : '',
                    });
                });
                return rules;
            }

            function saveRules() { updateField(f.id, 'showFields', readAllRules()); }

            list.querySelectorAll('.wpb-btn-rm-cond').forEach(btn => {
                btn.addEventListener('click', () => {
                    const ri = parseInt(btn.dataset.ri);
                    const rules = (f.showFields || []).filter((_, i) => i !== ri);
                    updateField(f.id, 'showFields', rules);
                    renderProps();
                });
            });

            list.querySelectorAll('.wpb-cond-op').forEach(sel => {
                sel.addEventListener('change', () => {
                    updateField(f.id, 'showFields', readAllRules());
                    renderProps();
                });
            });

            list.querySelectorAll('.wpb-cond-target, .wpb-cond-val').forEach(el => {
                el.addEventListener('change', saveRules);
                el.addEventListener('input', saveRules);
            });

            const addBtn = document.getElementById('wpb-btn-add-cond');
            if (addBtn) {
                addBtn.addEventListener('click', () => {
                    const rules = [...(f.showFields || []), { targets: [], operator: '=', value: '' }];
                    updateField(f.id, 'showFields', rules);
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
                const title = document.getElementById('wpb-form-title').value.trim();
                if (!title) { toast('Inserisci un nome per il form.', 'error'); return; }

                saveBtn.disabled = true;
                saveBtn.textContent = 'Salvataggio…';

                const body = new URLSearchParams({
                    action: 'wpb_forms_save',
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

        /* ── Registrazioni: caricamento AJAX ── */
        function showDetail(sub, formFields) {
            const countEl = document.getElementById('wpb-reg-count');
            const delAllEl = document.getElementById('wpb-btn-reg-delete-all');
            if (delAllEl) delAllEl.style.display = 'none';
            if (countEl) countEl.textContent = '';

            const SKIP = ['_page_url', '_page_title'];

            let fieldsHtml = '<div class="wpb-sub-detail-sep">Campi del form</div>';
            formFields.filter(f => !SKIP.includes(f.name || '') && f.type !== 'page_break').forEach(f => {
                const key = f.name || '';
                const cell = sub.data[key];
                let val = cell ? cell.value : null;
                if (Array.isArray(val)) val = val.join(', ');
                const empty = val === null || val === undefined || val === '';
                fieldsHtml += `<div class="wpb-sub-detail-row">
                                                                                                                                                    <div class="wpb-sub-detail-label">${esc(f.label || f.name)}</div>
                                                                                                                                                    <div class="wpb-sub-detail-value${empty ? ' wpb-empty' : ''}">${empty ? 'Non compilato' : esc(String(val))}</div>
                                                                                                                                                </div>`;
            });

            // Sezione informazioni pagina (se presenti nella submission)
            const pageUrl = sub.data['_page_url'] ? (sub.data['_page_url'].value ?? sub.data['_page_url']) : null;
            const pageTitle = sub.data['_page_title'] ? (sub.data['_page_title'].value ?? sub.data['_page_title']) : null;
            if (pageUrl || pageTitle) {
                fieldsHtml += '<div class="wpb-sub-detail-sep">Informazioni pagina</div>';
                if (pageUrl) {
                    const urlEsc = esc(String(pageUrl));
                    const urlLink = pageUrl ? `<a href="${urlEsc}" target="_blank" rel="noopener">${urlEsc}</a>` : '';
                    fieldsHtml += `<div class="wpb-sub-detail-row">
                                                                                                                                <div class="wpb-sub-detail-label">URL pagina</div>
                                                                                                                                <div class="wpb-sub-detail-value" style="word-break:break-all;">${urlLink}</div>
                                                                                                                            </div>`;
                }
                if (pageTitle) {
                    fieldsHtml += `<div class="wpb-sub-detail-row">
                                                                                                                                <div class="wpb-sub-detail-label">Titolo pagina</div>
                                                                                                                                <div class="wpb-sub-detail-value">${esc(String(pageTitle))}</div>
                                                                                                                            </div>`;
                }
            }

            const metaHtml = [
                `<span>\uD83D\uDCC5 Data: <strong>${esc(sub.created_at_full || sub.created_at)}</strong></span>`,
                sub.ip ? `<span>\uD83C\uDF10 IP: <strong>${esc(sub.ip)}</strong></span>` : '',
                sub.user_agent ? `<span>\uD83D\uDDA5 Browser: <span style="font-weight:400;font-size:11px;">${esc(sub.user_agent)}</span></span>` : '',
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
                                                                                <div class="wpb-resend-wrap">
                                                                                    <div class="wpb-resend-header">
                                                                                        <h4><span class="dashicons dashicons-email-alt" style="font-size:18px;width:18px;height:18px;color:#2271b1;"></span> Rinvia notifica</h4>
                                                                                    </div>
                                                                                    <div class="wpb-resend-body">
                                                                                        <div class="wpb-resend-field">
                                                                                            <label for="wpb-resend-template">Template di notifica <span style="color:#d63638;">*</span></label>
                                                                                            <select id="wpb-resend-template" required>${optionsHtml}</select>
                                                                                        </div>
                                                                                        <div class="wpb-resend-field">
                                                                                            <label for="wpb-resend-email">Email destinatario (override)</label>
                                                                                            <input type="email" id="wpb-resend-email" placeholder="Lascia vuoto per usare il destinatario configurato">
                                                                                            <p class="description">Se compilato, la notifica verrà inviata a questo indirizzo invece che al destinatario del template.</p>
                                                                                        </div>
                                                                                        <div class="wpb-resend-actions">
                                                                                            <button class="button button-primary" id="wpb-btn-resend">Invia notifica</button>
                                                                                            <span id="wpb-resend-result" class="wpb-resend-result"></span>
                                                                                        </div>
                                                                                    </div>
                                                                                </div>`;
            }

            const content = document.getElementById('wpb-reg-content');
            content.innerHTML = `
                                                                                                                                                <button class="button" id="wpb-reg-back">\u2190 Torna alla lista</button>
                                                                                                                                                <div class="wpb-sub-detail-wrap" style="margin-top:14px;">
                                                                                                                                                    <div class="wpb-sub-detail-header">
                                                                                                                                                        <h3>Registrazione #${sub.id}</h3>
                                                                                                                                                        <button class="button button-small wpb-btn-reg-del-detail"
                                                                                                                                                                style="color:#d63638;border-color:#d63638;"
                                                                                                                                                                data-id="${sub.id}">Elimina</button>
                                                                                                                                                    </div>
                                                                                                                                                    <div class="wpb-sub-detail-meta">${metaHtml}</div>
                                                                                                                                                    <div class="wpb-sub-detail-fields">${fieldsHtml}</div>
                                                                                                                                                </div>
                                                                                                                                                ${resendHtml}`;

            document.getElementById('wpb-reg-back').addEventListener('click', () => {
                regLoaded = false;
                loadRegistrazioni();
            });

            content.querySelectorAll('.wpb-btn-reg-del-detail').forEach(btn => {
                btn.addEventListener('click', async () => {
                    if (!confirm('Eliminare questa registrazione?')) return;
                    btn.disabled = true;
                    const res = await fetch(AJAX, { method: 'POST', body: new URLSearchParams({ action: 'wpb_forms_delete_submission', nonce: NONCE, submission_id: btn.dataset.id }) });
                    const resp = await res.json();
                    if (resp.success) { regLoaded = false; loadRegistrazioni(); }
                    else { toast(resp.data || 'Errore.', 'error'); btn.disabled = false; }
                });
            });

            // Bind rinvia notifica
            const resendBtn = document.getElementById('wpb-btn-resend');
            if (resendBtn) {
                resendBtn.addEventListener('click', async () => {
                    const templateSelect = document.getElementById('wpb-resend-template');
                    const overrideEmail = document.getElementById('wpb-resend-email');
                    const resultEl = document.getElementById('wpb-resend-result');

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
                            action: 'wpb_forms_resend_notification',
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

            const content = document.getElementById('wpb-reg-content');
            const countEl = document.getElementById('wpb-reg-count');
            const delAllEl = document.getElementById('wpb-btn-reg-delete-all');

            fetch(AJAX, {
                method: 'POST',
                body: new URLSearchParams({ action: 'wpb_forms_get_submissions', nonce: NONCE, form_id: EDIT_ID })
            })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        content.innerHTML = `<div class="wpb-reg-empty">Errore nel caricamento delle registrazioni.</div>`;
                        return;
                    }
                    const { subs, fields: formFields, total } = data.data;

                    if (countEl) countEl.textContent = total + ' registrazion' + (total === 1 ? 'e' : 'i');

                    if (!subs || !subs.length) {
                        content.innerHTML = `<div class="wpb-reg-empty">
                                                                                                                                                                <span class="dashicons dashicons-email-alt" style="font-size:36px;width:36px;height:36px;color:#c3c4c7;display:block;margin:0 auto 10px;"></span>
                                                                                                                                                                Nessuna registrazione ancora.
                                                                                                                                                            </div>`;
                        return;
                    }

                    if (delAllEl) delAllEl.style.display = '';

                    // Intestazioni: solo le prime 2 colonne + data
                    const listFields = formFields.slice(0, 2);
                    let thead = '<tr><th>#</th>';
                    listFields.forEach(f => { thead += `<th>${esc(f.label || f.name)}</th>`; });
                    thead += '<th>Data</th><th></th></tr>';

                    // Righe: compatte, cliccabili per dettaglio
                    let tbody = '';
                    subs.forEach((sub, idx) => {
                        const d = sub.data || {};
                        tbody += `<tr data-sub-id="${sub.id}" data-idx="${idx}" class="wpb-btn-reg-detail"><td>${sub.id}</td>`;
                        listFields.forEach(f => {
                            const key = f.name || '';
                            const cell = d[key];
                            let val = cell ? cell.value : null;
                            if (Array.isArray(val)) val = val.join(', ');
                            const empty = val === null || val === undefined || val === '';
                            tbody += empty
                                ? `<td class="wpb-reg-cell-empty">—</td>`
                                : `<td title="${esc(String(val))}">${esc(String(val))}</td>`;
                        });
                        tbody += `<td><span class="wpb-reg-date">${esc(sub.created_at)}</span></td>`;
                        tbody += `<td style="white-space:nowrap;"><button class="button button-small wpb-btn-reg-del" style="color:#d63638;border-color:#d63638;" data-id="${sub.id}" onclick="event.stopPropagation()">Elimina</button></td>`;
                        tbody += '</tr>';
                    });

                    content.innerHTML = `
                                                                                                                                                            <div class="wpb-reg-table-wrap">
                                                                                                                                                                <table class="wpb-reg-table">
                                                                                                                                                                    <thead>${thead}</thead>
                                                                                                                                                                    <tbody>${tbody}</tbody>
                                                                                                                                                                </table>
                                                                                                                                                            </div>`;

                    // Bind dettaglio: riga intera cliccabile
                    content.querySelectorAll('tr.wpb-btn-reg-detail').forEach(row => {
                        row.addEventListener('click', () => {
                            const idx = parseInt(row.dataset.idx);
                            showDetail(subs[idx], formFields);
                        });
                    });

                    // Bind elimina singola
                    content.querySelectorAll('.wpb-btn-reg-del').forEach(btn => {
                        btn.addEventListener('click', async () => {
                            if (!confirm('Eliminare questa registrazione?')) return;
                            btn.disabled = true;
                            const res = await fetch(AJAX, { method: 'POST', body: new URLSearchParams({ action: 'wpb_forms_delete_submission', nonce: NONCE, submission_id: btn.dataset.id }) });
                            const resp = await res.json();
                            if (resp.success) {
                                const row = btn.closest('tr');
                                row.style.transition = 'opacity .25s';
                                row.style.opacity = '0';
                                setTimeout(() => {
                                    row.remove();
                                    const remaining = content.querySelectorAll('.wpb-btn-reg-del').length;
                                    if (countEl) countEl.textContent = remaining + ' registrazion' + (remaining === 1 ? 'e' : 'i');
                                    if (!remaining) {
                                        if (delAllEl) delAllEl.style.display = 'none';
                                        content.innerHTML = `<div class="wpb-reg-empty">Nessuna registrazione ancora.</div>`;
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
                    content.innerHTML = `<div class="wpb-reg-empty">Errore di rete.</div>`;
                });
        }

        // Elimina tutte le registrazioni
        const delAllBtn = document.getElementById('wpb-btn-reg-delete-all');
        if (delAllBtn) {
            delAllBtn.addEventListener('click', async () => {
                if (!confirm('Eliminare tutte le registrazioni?\nL\'operazione non può essere annullata.')) return;
                delAllBtn.disabled = true;
                const res = await fetch(AJAX, { method: 'POST', body: new URLSearchParams({ action: 'wpb_forms_delete_all_submissions', nonce: NONCE, form_id: EDIT_ID }) });
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
    if (current_user_can('manage_options'))
        return; // gli admin vedono già tutto dalla pagina Forms

    add_menu_page(
        'Registrazioni Form — WP Barrio',
        'Registrazioni',
        'edit_others_posts',
        'wp-barrio-submissions',
        'wpb_forms_submissions_page',
        'dashicons-list-view',
        81
    );
}, 12);

/* ─────────────────────────────────────────
   PAGINA READ-ONLY: lista form → submissions → dettaglio
   ───────────────────────────────────────── */
function wpb_forms_submissions_page()
{
    if (!current_user_can('edit_others_posts')) {
        wp_die('Non hai i permessi per accedere a questa pagina.');
    }

    $nonce = wp_create_nonce('wpb_forms_nonce');
    $forms = wpb_forms_get_all();
    $view_id = isset($_GET['view_id']) ? intval($_GET['view_id']) : 0;
    $view_form = $view_id ? wpb_forms_get($view_id) : null;
    ?>
<style>
    #wpb-subs-wrap {
        max-width: 1200px
    }

    .wpb-subs-table-wrap {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        overflow: hidden
    }

    .wpb-subs-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px
    }

    .wpb-subs-table th {
        background: #f0f0f1;
        padding: 9px 14px;
        text-align: left;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .4px;
        color: #50575e;
        border-bottom: 1px solid #c3c4c7
    }

    .wpb-subs-table td {
        padding: 10px 14px;
        border-bottom: 1px solid #f0f0f1;
        vertical-align: middle
    }

    .wpb-subs-table tr:last-child td {
        border-bottom: none
    }

    .wpb-subs-table tr:hover td {
        background: #fafafa
    }

    .wpb-sub-count {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 600;
        background: #f0f0f1;
        color: #646970
    }

    .wpb-sub-count.has-subs {
        background: #e7f5e8;
        color: #00a32a
    }

    /* Registrazioni inline */
    .wpb-subs-reg-table-wrap {
        overflow-x: auto;
        margin-top: 10px
    }

    .wpb-subs-reg-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 3px
    }

    .wpb-subs-reg-table th {
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

    .wpb-subs-reg-table td {
        padding: 8px 12px;
        border-bottom: 1px solid #f0f0f1;
        max-width: 220px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap
    }

    .wpb-subs-reg-table tr:hover td {
        background: #f6f7f7
    }

    .wpb-subs-reg-table tbody tr {
        cursor: pointer
    }

    .wpb-subs-reg-cell-empty {
        color: #a7aaad;
        font-style: italic
    }

    .wpb-subs-reg-date {
        font-size: 12px;
        color: #646970
    }

    /* Dettaglio */
    .wpb-subs-detail-wrap {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 4px;
        overflow: hidden
    }

    .wpb-subs-detail-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 20px;
        border-bottom: 1px solid #f0f0f1;
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
        color: #646970;
        border-bottom: 1px solid #f0f0f1;
        background: #f9f9f9
    }

    .wpb-subs-detail-fields {
        padding: 0
    }

    .wpb-subs-detail-row {
        display: flex;
        border-bottom: 1px solid #f0f0f1;
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
        color: #50575e;
        border-right: 1px solid #f0f0f1
    }

    .wpb-subs-detail-value {
        flex: 1;
        padding: 12px 20px;
        word-break: break-word
    }

    .wpb-subs-detail-empty {
        color: #a7aaad;
        font-style: italic
    }

    /* Toolbar */
    .wpb-subs-toolbar {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 14px
    }

    .wpb-subs-reg-count {
        font-size: 13px;
        color: #646970
    }

    .wpb-subs-spinner {
        display: inline-block;
        width: 18px;
        height: 18px;
        border: 2px solid #c3c4c7;
        border-top-color: #2271b1;
        border-radius: 50%;
        animation: wpb-subs-spin .6s linear infinite
    }

    @keyframes wpb-subs-spin {
        to {
            transform: rotate(360deg)
        }
    }
</style>

<div class="wrap" id="wpb-subs-wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-list-view"
            style="font-size:22px;width:22px;height:22px;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
        Registrazioni Form
    </h1>
    <hr class="wp-header-end" style="margin-bottom:20px;">

    <?php if ($view_form): ?>
    <!-- ═══ REGISTRAZIONI DI UN FORM ═══ -->
    <p style="margin-bottom:14px;">
        <a href="<?php echo esc_url(admin_url('admin.php?page=wp-barrio-submissions')); ?>" class="button">← Tutti i
            form</a>
        <span style="margin-left:10px;font-weight:600;font-size:14px;">
            <?php echo esc_html($view_form->title); ?>
        </span>
    </p>
    <div class="wpb-subs-toolbar">
        <span class="wpb-subs-reg-count" id="wpb-subs-count"></span>
    </div>
    <div id="wpb-subs-content"><span class="wpb-subs-spinner"></span> Caricamento…</div>

    <script>
        (function () {
            const AJAX = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
            const NONCE = '<?php echo esc_js($nonce); ?>';
            const FORM_ID = <?php echo intval($view_id); ?>;

            let subsData = [], formFields = [];

            function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

            function loadSubmissions() {
                fetch(AJAX, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'wpb_forms_get_submissions', nonce: NONCE, form_id: FORM_ID })
                }).then(r => r.json()).then(res => {
                    if (!res.success) { document.getElementById('wpb-subs-content').innerHTML = '<p>' + esc(res.data || 'Errore') + '</p>'; return; }
                    subsData = res.data.subs || [];
                    formFields = (res.data.fields || []).filter(f => f.type !== 'submit');
                    document.getElementById('wpb-subs-count').textContent = res.data.total + ' registrazion' + (res.data.total === 1 ? 'e' : 'i');
                    renderList();
                }).catch(() => { document.getElementById('wpb-subs-content').innerHTML = '<p>Errore di rete.</p>'; });
            }

            function renderList() {
                const content = document.getElementById('wpb-subs-content');
                if (!subsData.length) { content.innerHTML = '<p style="color:#646970;">Nessuna registrazione.</p>'; return; }

                const listFields = formFields.slice(0, 2);
                let thead = '<tr><th>#</th>';
                listFields.forEach(f => { thead += '<th>' + esc(f.label || f.name || '—') + '</th>'; });
                thead += '<th>Data</th></tr>';

                let tbody = '';
                subsData.forEach((sub, idx) => {
                    const d = sub.data || {};
                    tbody += '<tr data-idx="' + idx + '"><td>' + sub.id + '</td>';
                    listFields.forEach(f => {
                        const key = f.name || '';
                        const cell = d[key];
                        let val = cell ? cell.value : null;
                        if (Array.isArray(val)) val = val.join(', ');
                        const empty = val === null || val === undefined || val === '';
                        tbody += empty ? '<td class="wpb-subs-reg-cell-empty">—</td>' : '<td title="' + esc(String(val)) + '">' + esc(String(val)) + '</td>';
                    });
                    tbody += '<td><span class="wpb-subs-reg-date">' + esc(sub.created_at) + '</span></td>';
                    tbody += '</tr>';
                });

                content.innerHTML = '<div class="wpb-subs-reg-table-wrap"><table class="wpb-subs-reg-table"><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table></div>';

                content.querySelectorAll('.wpb-subs-reg-table tbody tr').forEach(row => {
                    row.addEventListener('click', () => { showDetail(parseInt(row.dataset.idx, 10)); });
                });
            }

            function showDetail(idx) {
                const sub = subsData[idx]; if (!sub) return;

                let fieldsHtml = '';
                formFields.forEach(f => {
                    const key = f.name || '';
                    const cell = (sub.data || {})[key];
                    let val = cell ? cell.value : null;
                    if (Array.isArray(val)) val = val.join(', ');
                    const empty = val === null || val === undefined || val === '';
                    fieldsHtml += '<div class="wpb-subs-detail-row"><div class="wpb-subs-detail-label">' + esc(f.label || f.name || '—') + '</div><div class="wpb-subs-detail-value' + (empty ? ' wpb-subs-detail-empty' : '') + '">' + (empty ? '—' : esc(String(val))) + '</div></div>';
                });

                const metaHtml = [
                    '<span>\uD83D\uDCC5 Data: <strong>' + esc(sub.created_at_full || sub.created_at) + '</strong></span>',
                ].filter(Boolean).join('');

                const content = document.getElementById('wpb-subs-content');
                content.innerHTML = '<button class="button" id="wpb-subs-back">\u2190 Torna alla lista</button>'
                    + '<div class="wpb-subs-detail-wrap" style="margin-top:14px;">'
                    + '<div class="wpb-subs-detail-header"><h3>Registrazione #' + sub.id + '</h3></div>'
                    + '<div class="wpb-subs-detail-meta">' + metaHtml + '</div>'
                    + '<div class="wpb-subs-detail-fields">' + fieldsHtml + '</div>'
                    + '</div>';

                document.getElementById('wpb-subs-back').addEventListener('click', () => { renderList(); });
            }

            loadSubmissions();
        })();
    </script>

    <?php else: ?>
    <!-- ═══ LISTA FORM ═══ -->
    <div class="wpb-subs-table-wrap">
        <?php if (empty($forms)): ?>
        <div style="padding:40px 20px;text-align:center;">
            <span class="dashicons dashicons-list-view"
                style="font-size:40px;width:40px;height:40px;color:#c3c4c7;"></span>
            <p style="font-size:14px;margin:10px 0 0;color:#646970;">Nessun form disponibile.</p>
        </div>
        <?php else: ?>
        <table class="wpb-subs-table">
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
                        <a href="<?php echo esc_url($subs_url); ?>" class="button button-small">Vedi registrazioni</a>
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
