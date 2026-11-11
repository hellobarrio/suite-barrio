<?php
/**
 * Suite Barrio — Forms
 * Builder drag & drop per form di contatto personalizzati.
 *
 * @package Suite_Barrio
 */

if (!defined('ABSPATH'))
    exit;

define('SUITE_BARRIO_FORMS_VERSION', '1.1.0');
define('SUITE_BARRIO_FORMS_TABLE', 'wpb_forms');
define('SUITE_BARRIO_FORMS_SUB_TABLE', 'wpb_form_submissions');
define('SUITE_BARRIO_FORMS_TRANS_TABLE', 'wpb_forms_translations');

/* ─────────────────────────────────────────
   INSTALL: crea le tabelle al primo avvio
   ───────────────────────────────────────── */
register_activation_hook(SUITE_BARRIO_PLUGIN_DIR . 'suite-barrio.php', 'suite_barrio_forms_install');

function suite_barrio_forms_install()
{
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    // CREATE TABLE IF NOT EXISTS — non dipende da dbDelta
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$charset} viene da $wpdb->get_charset_collate(), valore fisso non da input utente
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- operazione di installazione/aggiornamento schema, eseguita una tantum in attivazione plugin; nessun object-cache group dedicato, il caching non ha senso su una CREATE TABLE
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

    // Migrazione v1.1: aggiunge la colonna lang alle installazioni preesistenti.
    $sub_table = $wpdb->prefix . SUITE_BARRIO_FORMS_SUB_TABLE;
    $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$sub_table}`", 0);
    if ($cols && !in_array('lang', $cols, true)) {
        $wpdb->query("ALTER TABLE `{$sub_table}` ADD COLUMN `lang` VARCHAR(10) NOT NULL DEFAULT '' AFTER `data`");
    }
    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

add_action('plugins_loaded', 'suite_barrio_forms_install');

// Fallback: crea le tabelle anche alla prima visita admin
add_action('admin_init', function () {
    $installed = get_option('suite_barrio_forms_db_version', '0');
    if (version_compare($installed, SUITE_BARRIO_FORMS_VERSION, '<')) {
        suite_barrio_forms_install();
        update_option('suite_barrio_forms_db_version', SUITE_BARRIO_FORMS_VERSION, false);
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
   HELPER: log diagnostico.
   Scrive solo con WP_DEBUG attivo: in produzione è un no-op, così le
   integrazioni esterne (Mailjet, MailUp, Mailchimp, reCAPTCHA) restano
   diagnosticabili senza sporcare i log di un sito live.
   ───────────────────────────────────────── */
function suite_barrio_forms_log(string $message): void
{
    if (!defined('WP_DEBUG') || !WP_DEBUG)
        return;
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- log diagnostico volontario, attivo solo con WP_DEBUG
    error_log('[Suite Barrio][Forms] ' . $message);
}

/* ─────────────────────────────────────────
   HELPER DB — TRADUZIONI FORM
   ───────────────────────────────────────── */

/**
 * Chiavi traducibili — unica fonte di verità (usata da apply/extract, dal
 * sanitize AJAX e dal JS dell'editor, così non possono divergere).
 * I nomi devono combaciare con quelli letti da suite_barrio_forms_send_notifications():
 * message / email_title / to_name / replyto_name.
 */
function suite_barrio_forms_translatable_settings(): array
{
    return [
        'submit_text',
        'redirect_url',
        'success_message',
        'mailup_list_id',
        'mailup_group_id',
        'mailchimp_list_id',
        // I tag sono traducibili come l'Audience ID: servono a segmentare gli
        // iscritti per lingua (es. "Newsletter IT" / "Newsletter EN") e poi a
        // inviare campagne nella lingua giusta.
        'mailchimp_tags',
        'mailchimp_consent_tags',
        // Testi fissi delle email di notifica (vuoti = predefiniti della lingua)
        ...array_keys(suite_barrio_forms_email_text_settings()),
    ];
}

/**
 * Testi fissi delle email modificabili dalla scheda Notifiche, per lingua.
 * impostazione => [chiave in suite_barrio_forms_email_strings(), etichetta, aiuto]
 */
function suite_barrio_forms_email_text_settings(): array
{
    return [
        'email_sent_label' => ['sent_at', 'Data di invio', 'Usa {data} e {ora} per data e ora di invio.'],
        'email_source_label' => ['source', 'Titolo sezione origine', 'Sezione con pagina e URL da cui è stato inviato il form.'],
        'email_page_label' => ['page', 'Etichetta pagina', ''],
        'email_attachments_label' => ['attachments', 'Titolo sezione allegati', ''],
        'email_footer' => ['footer', 'Testo footer', "Testo legale in fondo alle email, seguito dall'email dominio."],
    ];
}

function suite_barrio_forms_translatable_notif(): array
{
    return ['subject', 'email_title', 'message', 'from_name', 'to_name', 'replyto_name'];
}

/**
 * Restituisce la traduzione di un form per una lingua, o null se non esiste.
 */
function suite_barrio_forms_get_translation(int $form_id, string $lang): ?object
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    return $wpdb->get_row($wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome tabella fisso (costante di plugin), non input utente
        "SELECT * FROM {$wpdb->prefix}" . SUITE_BARRIO_FORMS_TRANS_TABLE . "
         WHERE form_id = %d AND lang = %s",
        $form_id,
        $lang
    )) ?: null;
}

/**
 * Upsert traduzione form.
 */
function suite_barrio_forms_save_translation(int $form_id, string $lang, array $fields_i18n, array $settings_i18n): bool
{
    global $wpdb;
    $table = $wpdb->prefix . SUITE_BARRIO_FORMS_TRANS_TABLE;
    $existing = suite_barrio_forms_get_translation($form_id, $lang);

    $row = [
        'form_id' => $form_id,
        'lang' => $lang,
        'fields_i18n' => wp_json_encode($fields_i18n, JSON_UNESCAPED_UNICODE),
        'settings_i18n' => wp_json_encode($settings_i18n, JSON_UNESCAPED_UNICODE),
    ];
    $fmt = ['%d', '%s', '%s', '%s'];

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato
    if ($existing) {
        $ok = $wpdb->update($table, $row, ['id' => (int) $existing->id], $fmt, ['%d']);
    } else {
        $ok = $wpdb->insert($table, $row, $fmt);
    }
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    return $ok !== false;
}

/**
 * Elimina tutte le traduzioni di un form.
 */
function suite_barrio_forms_delete_translations(int $form_id): void
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato
    $wpdb->delete($wpdb->prefix . SUITE_BARRIO_FORMS_TRANS_TABLE, ['form_id' => $form_id], ['%d']);
}

/**
 * Applica una traduzione a un form: sovrascrive solo i campi traducibili.
 * Restituisce [fields, settings] mergiati (non tocca il DB).
 */
function suite_barrio_forms_apply_translation(array $fields, array $settings, object $trans): array
{
    $fi18n = json_decode($trans->fields_i18n, true) ?: [];
    $si18n = json_decode($trans->settings_i18n, true) ?: [];

    // Merge fields: per ogni campo sovrascrive label, placeholder, error_message, options
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

    // Merge settings: solo i campi traducibili
    foreach (suite_barrio_forms_translatable_settings() as $k) {
        if (isset($si18n[$k]) && $si18n[$k] !== '')
            $settings[$k] = $si18n[$k];
    }
    // Notifiche: merge per indice
    if (!empty($si18n['notifications']) && is_array($si18n['notifications'])) {
        $orig_notifs = $settings['notifications'] ?? [];
        foreach ($si18n['notifications'] as $ni => $nt) {
            if (!isset($orig_notifs[$ni]))
                continue;
            foreach (suite_barrio_forms_translatable_notif() as $nk) {
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
function suite_barrio_forms_extract_translatable(array $fields, array $settings): array
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
    foreach (suite_barrio_forms_translatable_settings() as $k) {
        $si18n[$k] = $settings[$k] ?? '';
    }
    $si18n['notifications'] = [];
    foreach ($settings['notifications'] ?? [] as $notif) {
        $nt = [];
        foreach (suite_barrio_forms_translatable_notif() as $nk) {
            $nt[$nk] = $notif[$nk] ?? '';
        }
        $si18n['notifications'][] = $nt;
    }

    return [$fi18n, $si18n];
}

/**
 * Lingua corrente del sito secondo WPML, se attivo. Stringa vuota se monolingua.
 */
function suite_barrio_forms_current_lang(): string
{
    if (defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE) {
        return ICL_LANGUAGE_CODE;
    }
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legge l'hook di WPML, non ne definisce uno nuovo
    return (string) apply_filters('wpml_current_language', '');
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
add_shortcode('suite_barrio_form', 'suite_barrio_forms_shortcode');
add_shortcode('wpb_form', 'suite_barrio_forms_shortcode'); // alias retrocompatibile: contenuti già pubblicati con [wpb_form]

function suite_barrio_forms_shortcode($atts)
{
    $atts = shortcode_atts(['id' => 0], $atts);
    $form = suite_barrio_forms_get(intval($atts['id']));
    if (!$form)
        return '<p>Form non trovato.</p>';

    $fields = json_decode($form->fields, true) ?: [];
    $settings = json_decode($form->settings, true) ?: [];

    // Se esiste, usa il form tradotto nella lingua corrente; altrimenti resta in lingua originale.
    $suite_barrio_form_lang = suite_barrio_forms_current_lang();
    if ($suite_barrio_form_lang) {
        $trans = suite_barrio_forms_get_translation((int) $form->id, $suite_barrio_form_lang);
        if ($trans) {
            [$fields, $settings] = suite_barrio_forms_apply_translation($fields, $settings, $trans);
        }
    }

    $form_id_css = 'suite-barrio-form-' . $form->id;

    // reCAPTCHA Enterprise: attivo solo se le credenziali globali sono complete.
    $rc_site_key = suite_barrio_recaptcha_site_key();
    if ($rc_site_key) {
        wp_enqueue_script(
            'suite-barrio-recaptcha-enterprise',
            'https://www.google.com/recaptcha/enterprise.js?render=' . rawurlencode($rc_site_key),
            [],
            null,
            false
        );
    }

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
            <input type="hidden" name="_lang" value="<?php echo esc_attr($suite_barrio_form_lang); ?>">
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
                    data-field-name="<?php echo $fid; ?>" <?php echo $required ? ' data-required="1"' : ''; ?>         <?php if (!empty($field['error_message'])): ?> data-error-msg="<?php echo esc_attr($field['error_message']); ?>" <?php endif; ?>>
                    <?php // radio/checkbox hanno una <label> per opzione: qui la label del gruppo sarebbe un for="" orfano.
                    if ($type !== 'radio' && $type !== 'checkbox' && '' !== $label): ?>
                        <label for="suite-barrio-<?php echo $fid; ?>"><?php echo $label . $req_star; ?></label>
                    <?php endif; ?>

                    <?php if ($type === 'textarea'): ?>
                        <textarea id="suite-barrio-<?php echo $fid; ?>" name="<?php echo $fid; ?>"
                            placeholder="<?php echo $placeholder; ?>" rows="5" <?php echo $req_attr; ?>></textarea>

                    <?php elseif ($type === 'select'):
                        $options = $field['options'] ?? [];
                        ?>
                        <select id="suite-barrio-<?php echo $fid; ?>" name="<?php echo $fid; ?>" <?php echo $req_attr; ?>>
                            <option value="" disabled selected hidden><?php echo esc_html($field['placeholder'] ?? 'Seleziona…'); ?>
                            </option>
                            <?php foreach ($options as $opt):
                                // In un <option> il markup non può renderizzare: testo puro.
                                $opt_text = wp_strip_all_tags($opt);
                                ?>
                                <option value="<?php echo esc_attr($opt_text); ?>"><?php echo esc_html($opt_text); ?></option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif ($type === 'radio' || $type === 'checkbox'):
                        $options = $field['options'] ?? [];
                        ?>
                        <div class="suite-barrio-choice-group">
                            <?php foreach ($options as $oi => $opt):
                                $opt_id = 'suite-barrio-' . $fid . '-' . intval($oi);
                                // Il valore inviato è sempre testo puro: è quello che finisce
                                // nelle registrazioni, nelle email e nei confronti della logica
                                // condizionale, dove il markup dell'etichetta sarebbe rumore.
                                $opt_value = wp_strip_all_tags($opt);
                                ?>
                                <div class="suite-barrio-choice-label">
                                    <input type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr($opt_id); ?>"
                                        name="<?php echo $fid . ($type === 'checkbox' ? '[]' : ''); ?>"
                                        value="<?php echo esc_attr($opt_value); ?>">
                                    <?php // wp_kses_post: l'admin può mettere un link nell'etichetta (es. privacy policy),
                                        // ma script e attributi pericolosi restano fuori. ?>
                                    <label for="<?php echo esc_attr($opt_id); ?>"><?php echo wp_kses_post($opt); ?></label>
                                </div>
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

    /* ── Stato di errore per-campo (hook stilabili) ──
       .suite-barrio-field-error      → sul wrapper del campo non valido
       [aria-invalid="true"]          → sul controllo (input/select/textarea)
       .suite-barrio-field-error-msg  → messaggio iniettato sotto al campo
       Sono solo i default: sovrascrivibili dal tema. */
    .suite-barrio-form-wrap .suite-barrio-field-error input,
    .suite-barrio-form-wrap .suite-barrio-field-error select,
    .suite-barrio-form-wrap .suite-barrio-field-error textarea {
    border-color: #d63638;
    }

    .suite-barrio-form-wrap .suite-barrio-field-error input:focus,
    .suite-barrio-form-wrap .suite-barrio-field-error select:focus,
    .suite-barrio-form-wrap .suite-barrio-field-error textarea:focus {
    border-color: #d63638;
    box-shadow: 0 0 0 2px rgba(214, 54, 56, .15);
    }

    .suite-barrio-field-error-msg {
    color: #d63638;
    font-size: 13px;
    line-height: 1.3;
    margin-top: 6px;
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
    var requiredMsg = '<?php echo esc_js(__('Questo campo è obbligatorio.', 'suite-barrio')); ?>';
    var emailMsg = '<?php echo esc_js(__('Inserisci un indirizzo email valido.', 'suite-barrio')); ?>';
    var submitLabel = btn.textContent;
    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    /* ── Validazione per-campo (marca il markup, così è stilabile) ──────── */

    // Tutti i wrapper di campo (escluso il blocco submit).
    function fieldWraps() {
    return Array.prototype.slice.call(
    form.querySelectorAll('.suite-barrio-field-wrap[data-field-name]')
    );
    }

    // Un campo è "attivo" se non nascosto dalla logica condizionale.
    function isActive(fw) {
    return fw.dataset.condHidden !== '1';
    }

    // Controlli compilabili del campo (esclude quelli disabilitati).
    function controlsOf(fw) {
    return Array.prototype.slice
    .call(fw.querySelectorAll('input, select, textarea'))
    .filter(function (c) { return !c.disabled; });
    }

    function setFieldError(fw, message) {
    fw.classList.add('suite-barrio-field-error');
    controlsOf(fw).forEach(function (c) { c.setAttribute('aria-invalid', 'true'); });
    var el = fw.querySelector('.suite-barrio-field-error-msg');
    if (!el) {
    el = document.createElement('div');
    el.className = 'suite-barrio-field-error-msg';
    el.setAttribute('aria-live', 'polite');
    fw.appendChild(el);
    }
    el.textContent = message || '';
    }

    function clearFieldError(fw) {
    fw.classList.remove('suite-barrio-field-error');
    fw.querySelectorAll('[aria-invalid]').forEach(function (c) {
    c.removeAttribute('aria-invalid');
    });
    var el = fw.querySelector('.suite-barrio-field-error-msg');
    if (el && el.parentNode) el.parentNode.removeChild(el);
    }

    function clearAllErrors() { fieldWraps().forEach(clearFieldError); }

    // Valida un campo → ritorna il messaggio d'errore, oppure '' se valido.
    function validateField(fw) {
    var controls = controlsOf(fw);
    if (!controls.length) return '';

    var first = controls[0];
    var type = first.type; // text, email, tel, number, radio, checkbox, file, date, textarea, select-one…
    var required = fw.dataset.required === '1';
    var reqMsg = fw.dataset.errorMsg || requiredMsg;

    var hasValue;
    if (type === 'radio' || type === 'checkbox') {
    hasValue = controls.some(function (c) { return c.checked; });
    } else if (type === 'file') {
    hasValue = controls.some(function (c) { return c.files && c.files.length; });
    } else {
    hasValue = controls.some(function (c) { return String(c.value || '').trim() !== ''; });
    }

    if (required && !hasValue) return reqMsg;

    // Formato email: controllato anche se non obbligatorio, quando compilato.
    if (type === 'email' && hasValue && !EMAIL_RE.test(String(first.value).trim())) {
    return emailMsg;
    }

    return '';
    }

    // Aggiornamento live: quando un campo già segnalato torna valido, lo ripulisce.
    function onFieldInput(e) {
    var t = e.target;
    var fw = t.closest ? t.closest('.suite-barrio-field-wrap[data-field-name]') : null;
    if (!fw || !fw.classList.contains('suite-barrio-field-error')) return;
    var err = validateField(fw);
    if (err) setFieldError(fw, err);
    else clearFieldError(fw);
    }
    form.addEventListener('input', onFieldInput, true);
    form.addEventListener('change', onFieldInput, true);

    form.addEventListener('submit', function (e) {
    e.preventDefault();
    clearAllErrors();
    msgEl.className = 'suite-barrio-form-msg';
    msgEl.style.display = 'none';

    // Validazione client-side: marca ogni campo non valido.
    var firstInvalid = null;
    fieldWraps().forEach(function (fw) {
    if (!isActive(fw)) return;
    var err = validateField(fw);
    if (err) {
    setFieldError(fw, err);
    if (!firstInvalid) firstInvalid = fw;
    }
    });

    if (firstInvalid) {
    var ctrl = firstInvalid.querySelector('input, select, textarea');
    if (ctrl) ctrl.focus();
    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return; // niente invio finché ci sono errori
    }

    btn.disabled = true;
    btn.textContent = 'Invio in corso…';

    <?php if ($display_page_info): ?>
        document.getElementById('<?php echo esc_js($form_id_css); ?>-page-url').value = window.location.href;
        document.getElementById('<?php echo esc_js($form_id_css); ?>-page-title').value = document.title;
    <?php endif; ?>

    // reCAPTCHA Enterprise v3: token invisibile, allegato al POST.
    var tokenPromise = <?php if ($rc_site_key): ?>new Promise(function (resolve) {
        if (typeof grecaptcha === 'undefined' || !grecaptcha.enterprise) return resolve('');
        grecaptcha.enterprise.ready(function () {
        grecaptcha.enterprise
        .execute('<?php echo esc_js($rc_site_key); ?>', { action: 'suite_barrio_form_<?php echo intval($form->id); ?>' })
        .then(resolve, function () { resolve(''); });
        });
        })<?php else: ?>Promise.resolve('')<?php endif; ?>;

    tokenPromise
    .then(function (token) {
    var fd = new FormData(form);
    if (token) fd.append('recaptcha_token', token);
    return fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
    method: 'POST',
    body: fd
    });
    })
    .then(function (r) { return r.json(); })
    .then(function (res) {
    if (res.success) {
    if (afterSubmit === 'redirect' && redirectUrl) {
    window.location.href = redirectUrl;
    } else {
    msgEl.textContent = (res.data && res.data.message) || successMsg;
    msgEl.className = 'suite-barrio-form-msg success';
    msgEl.style.display = 'block';
    form.reset();
    }
    } else {
    // res.data può essere una stringa oppure { message, field }.
    var data = res.data;
    var serverMsg = (data && typeof data === 'object') ? data.message : data;
    var serverField = (data && typeof data === 'object') ? data.field : '';

    if (serverField) {
    var fw = form.querySelector('.suite-barrio-field-wrap[data-field-name="' + serverField + '"]');
    if (fw) {
    setFieldError(fw, serverMsg || (fw.dataset.errorMsg || requiredMsg));
    var c = fw.querySelector('input, select, textarea');
    if (c) c.focus();
    fw.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    }

    msgEl.textContent = serverMsg || 'Si è verificato un errore. Riprova.';
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
        var action = cond.action;       // 'show' | 'hide'
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
/**
 * Testi fissi delle email di notifica nella lingua dell'invio.
 * Predefiniti: italiano per la lingua principale (o sito monolingua), inglese per le altre
 * (altre lingue: filtro suite_barrio_forms_email_strings). I testi scritti nel form
 * ($settings, già tradotti per lingua) hanno la precedenza.
 */
function suite_barrio_forms_email_strings(string $lang = '', array $settings = []): array
{
    $strings = ($lang === '' || $lang === 'it')
        ? [
            'lang' => 'it',
            'sent_at' => 'Inviato il {data} alle {ora}',
            'attachments' => 'Allegati',
            'source' => 'Origine',
            'page' => 'Pagina',
            'default_subject' => '[%1$s] Nuova registrazione: %2$s',
            'footer' => 'La presente email è stata generata automaticamente da un indirizzo di posta elettronica di solo invio; si chiede pertanto di non rispondere al messaggio. Le informazioni e gli eventuali allegati contenuti nella presente comunicazione sono diretti esclusivamente al destinatario e devono ritenersi riservati. Se avete ricevuto questo messaggio per errore, vi preghiamo di cancellarlo e di informarci inviando un messaggio a:',
        ]
        : [
            'lang' => $lang,
            'sent_at' => 'Sent on {data} at {ora}',
            'attachments' => 'Attachments',
            'source' => 'Source',
            'page' => 'Page',
            'default_subject' => '[%1$s] New submission: %2$s',
            'footer' => 'This email was generated automatically from a send-only address; please do not reply to this message. The information and any attachments contained in this message are intended solely for the recipient and must be treated as confidential. If you have received this message in error, please delete it and let us know by writing to:',
        ];

    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook con il prefisso del plugin
    $strings = apply_filters('suite_barrio_forms_email_strings', $strings, $lang);

    foreach (suite_barrio_forms_email_text_settings() as $setting => [$key]) {
        $custom = trim((string) ($settings[$setting] ?? ''));
        if ($custom !== '')
            $strings[$key] = $custom;
    }
    return $strings;
}

function suite_barrio_build_email_html(
    string $content,
    string $title,
    string $bg_color = '#f4f4f4',
    string $header_color = '#2271b1',
    string $logo_url = '',
    string $domain_email = '',
    string $lang = '',
    string $footer = ''
): string {
    $strings = suite_barrio_forms_email_strings($lang);
    // Testo del form (impostazione email_footer, tradotta per lingua) o predefinito della lingua
    $footer_html = nl2br(esc_html(trim($footer) !== '' ? trim($footer) : $strings['footer']));

    // Logo — se assente, nasconde il blocco con display:none
    $logo_block = $logo_url
        ? '<img class="t21" style="display:block;border:0;height:auto;width:100%;Margin:0;max-width:100%;" width="200" height="60" src="' . esc_url($logo_url) . '" alt="Logo" />'
        : '<span style="display:block;height:4px;"></span>';

    $domain_safe = esc_html($domain_email);
    $domain_href = esc_url('mailto:' . $domain_email);

    $tpl = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="[lang]">
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
                  [footerText]
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
        ['[lang]', '[footerText]', '[bgColor]', '[headerColor]', '[logoBlock]', '[titoloEmail]', '[content]', '[domainEmail]', '[domainHref]'],
        [esc_attr($strings['lang']), $footer_html, $bg_color, $header_color, $logo_block, esc_html($title), $content, $domain_safe, $domain_href],
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
 * @param string $lang           Lingua dell'invio: testi fissi dell'email (vedi suite_barrio_forms_email_strings).
 */
function suite_barrio_forms_send_notifications(object $form, array $settings, array $submission_data, array $attachments = [], string $lang = ''): array
{
    $log = [];
    $strings = suite_barrio_forms_email_strings($lang, $settings);

    $notifications = isset($settings['notifications']) && is_array($settings['notifications'])
        ? $settings['notifications'] : [];

    $log[] = 'form_id=' . $form->id . ' notifications_count=' . count($notifications)
        . ' settings_keys=' . implode(',', array_keys($settings));

    if (empty($notifications)) {
        $log[] = 'SKIP: nessuna notifica configurata per questo form.';
        return $log;
    }

    $default_subject = sprintf($strings['default_subject'], get_bloginfo('name'), $form->title);

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
            $content_html .= "<p style=\"margin:16px 0 0;font-size:12px;color:#a7aaad;\">" . esc_html(str_replace(['{data}', '{ora}'], [current_time('d/m/Y'), current_time('H:i')], $strings['sent_at'])) . "</p>";

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
                $content_html .= "<p style=\"margin:20px 0 6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#a7aaad;\">" . esc_html($strings['attachments']) . "</p>"
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
                            <td style=\"padding:8px 12px;font-size:12px;font-weight:600;color:#50575e;background:#f6f7f7;border-bottom:1px solid #e8e8e8;width:35%;\">" . esc_html($strings['page']) . "</td>
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
                    $content_html .= "<p style=\"margin:20px 0 6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#a7aaad;\">" . esc_html($strings['source']) . "</p>"
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
            $settings['domain_email'] ?? get_option('admin_email'),
            $lang,
            $strings['footer']
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
        $global_mj = get_option('suite_barrio_forms_mailjet', []);
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
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- allegato locale codificato in base64 come richiesto dall'API Mailjet, non offuscamento; $path e' un file locale, non un URL
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
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- codifica richiesta dallo schema HTTP Basic Auth, non offuscamento
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
   HELPER: reCAPTCHA Enterprise (v3)
   ───────────────────────────────────────── */

/**
 * Site key da usare nel front-end, solo se la configurazione è completa
 * (project id + site key + api key). Stringa vuota = reCAPTCHA disattivato.
 */
function suite_barrio_recaptcha_site_key(): string
{
    $opts = get_option('suite_barrio_forms_recaptcha', []);
    if (!is_array($opts))
        return '';
    $project = trim($opts['project_id'] ?? '');
    $site = trim($opts['site_key'] ?? '');
    $api = trim($opts['api_key'] ?? '');
    return ($project && $site && $api) ? $site : '';
}

/**
 * Verifica un token reCAPTCHA Enterprise creando un assessment.
 *
 * @param string $token           Token generato dal front-end.
 * @param string $expected_action Action attesa (deve combaciare con quella del front-end).
 * @return true|WP_Error true se il token è valido e sopra soglia.
 *                       Se reCAPTCHA non è configurato ritorna true (nessuna verifica).
 */
function suite_barrio_recaptcha_verify(string $token, string $expected_action)
{
    $opts = get_option('suite_barrio_forms_recaptcha', []);
    $site_key = suite_barrio_recaptcha_site_key();
    if (!$site_key)
        return true; // non configurato

    $spam_msg = __('Verifica antispam non superata. Riprova.', 'suite-barrio');

    if ('' === $token)
        return new WP_Error('recaptcha_missing', $spam_msg);

    $res = wp_remote_post(
        'https://recaptchaenterprise.googleapis.com/v1/projects/'
        . rawurlencode(trim($opts['project_id'])) . '/assessments?key='
        . rawurlencode(trim($opts['api_key'])),
        [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'event' => [
                    'token' => $token,
                    'siteKey' => $site_key,
                    'expectedAction' => $expected_action,
                    'userIpAddress' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
                ],
            ]),
        ]
    );

    // Google irraggiungibile: non blocchiamo l'invio, logghiamo soltanto.
    // ponytail: fail-open sul transport error, per non perdere lead durante un disservizio.
    if (is_wp_error($res)) {
        suite_barrio_forms_log('[recaptcha] richiesta fallita: ' . $res->get_error_message());
        return true;
    }

    if (200 !== wp_remote_retrieve_response_code($res)) {
        suite_barrio_forms_log('[recaptcha] API error: ' . wp_remote_retrieve_body($res));
        return true; // credenziali/quota errate: l'errore è nostro, non blocchiamo l'utente
    }

    $body = json_decode(wp_remote_retrieve_body($res), true) ?: [];
    $props = $body['tokenProperties'] ?? [];

    if (empty($props['valid'])) {
        suite_barrio_forms_log('[recaptcha] token non valido: ' . ($props['invalidReason'] ?? 'sconosciuto'));
        return new WP_Error('recaptcha_invalid', $spam_msg);
    }
    if (($props['action'] ?? '') !== $expected_action) {
        suite_barrio_forms_log('[recaptcha] action mismatch: ' . ($props['action'] ?? ''));
        return new WP_Error('recaptcha_action', $spam_msg);
    }

    $score = (float) ($body['riskAnalysis']['score'] ?? 0);
    $threshold = (float) apply_filters('suite_barrio_forms_recaptcha_threshold', 0.5);
    if ($score < $threshold) {
        suite_barrio_forms_log('[recaptcha] score ' . $score . ' sotto soglia ' . $threshold);
        return new WP_Error('recaptcha_score', $spam_msg);
    }

    return true;
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

    // ── reCAPTCHA Enterprise (no-op se le credenziali globali non ci sono) ─
    $rc = suite_barrio_recaptcha_verify(
        sanitize_text_field(wp_unslash($_POST['recaptcha_token'] ?? '')),
        'suite_barrio_form_' . $form_id
    );
    if (is_wp_error($rc))
        wp_send_json_error($rc->get_error_message());

    $form = suite_barrio_forms_get($form_id);
    if (!$form)
        wp_send_json_error('Form non trovato.');

    $fields = json_decode($form->fields, true) ?: [];
    $settings = json_decode($form->settings, true) ?: [];

    // ── Lingua di compilazione + traduzione ───────────────────────────────
    // Va applicata QUI, prima di validazione e notifiche: altrimenti messaggi
    // d'errore, success_message ed email restano nella lingua principale.
    $submission_lang = sanitize_key(wp_unslash($_POST['_lang'] ?? ''));
    if (!$submission_lang) {
        $submission_lang = sanitize_key(suite_barrio_forms_current_lang());
    }
    if ($submission_lang) {
        $trans = suite_barrio_forms_get_translation($form_id, $submission_lang);
        if ($trans) {
            [$fields, $settings] = suite_barrio_forms_apply_translation($fields, $settings, $trans);
        }
    }

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
            // Ritorna anche il name del campo, così il front-end può marcarlo come errato.
            wp_send_json_error(['message' => $err, 'field' => $fid]);
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
                suite_barrio_forms_log('[file] upload fallito per ' . $fid . ': ' . $moved['error']);
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

    // Aggiunge la lingua di compilazione a submission_data: disponibile
    // nell'email come {{_lang}} e inclusa nel riepilogo.
    if ($submission_lang) {
        $lang_labels = function_exists('suite_barrio_multilang_languages') ? suite_barrio_multilang_languages() : [];
        $submission_data['_lang'] = [
            'label' => 'Lingua',
            'value' => $lang_labels[$submission_lang] ?? strtoupper($submission_lang),
        ];
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->insert(
        $wpdb->prefix . SUITE_BARRIO_FORMS_SUB_TABLE,
        [
            'form_id' => $form_id,
            'data' => wp_json_encode($submission_data),
            'lang' => $submission_lang,
            'ip' => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
            'user_agent' => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ],
        ['%d', '%s', '%s', '%s', '%s']
    );

    // Invia le notifiche email con gli allegati
    suite_barrio_forms_send_notifications($form, $settings, $submission_data, $attachments, $submission_lang);

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
                suite_barrio_forms_log('[MailUp] Opt-in non selezionato (campo: ' . $mu_optin_field . ') — iscrizione saltata.');
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

            $result = suite_barrio_mailup_subscribe($mu_email, $mu_list_id, $mu_group_id, $mu_mapped_fields);
            if (is_wp_error($result)) {
                suite_barrio_forms_log('[MailUp] Errore iscrizione per form_id=' . $form_id . ': ' . $result->get_error_message());
            }
        } elseif ($optin_ok) {
            suite_barrio_forms_log('[MailUp] Salto iscrizione: email non valida.');
        }
    }

    // ── Integrazione Mailchimp ────────────────────────────────────────────
    // Esegui solo se l'integrazione è abilitata per questo form
    if (!empty($settings['mailchimp_enabled'])) {
        $mc_list_id = trim((string) ($settings['mailchimp_list_id'] ?: ''));
        $mc_email_field = sanitize_key($settings['mailchimp_email_field'] ?: 'email');
        $mc_optin_field = sanitize_key($settings['mailchimp_optin_field'] ?: '');

        // Il campo opt-in NON decide se iscrivere — il contatto viene registrato comunque —
        // ma solo se applicare il tag della newsletter commerciale.
        // Nessun campo configurato = consenso implicito (form di sola iscrizione newsletter).
        $commercial_consent = true;
        if ($mc_optin_field !== '') {
            $optin_raw = $submission_data[$mc_optin_field]['value'] ?? '';
            $optin_value = is_array($optin_raw) ? implode(',', $optin_raw) : $optin_raw;
            $commercial_consent = trim((string) $optin_value) !== '';
        }

        $mc_email = sanitize_email(
            is_array($submission_data[$mc_email_field]['value'] ?: null)
            ? implode('', $submission_data[$mc_email_field]['value'])
            : ($submission_data[$mc_email_field]['value'] ?: '')
        );

        if (is_email($mc_email)) {
            // Mappatura campi form → merge tag Mailchimp
            // Ogni riga del field_map è: {form_field: slug, mailchimp_field_name: 'FNAME'}
            $mc_merge_fields = [];
            $field_map = $settings['mailchimp_field_map'] ?: [];
            if (is_array($field_map)) {
                foreach ($field_map as $map) {
                    $form_slug = sanitize_key($map['form_field'] ?: '');
                    $mc_tag = sanitize_text_field($map['mailchimp_field_name'] ?: '');
                    if (!$form_slug || !$mc_tag)
                        continue;
                    $raw = $submission_data[$form_slug]['value'] ?: '';
                    $mc_merge_fields[$mc_tag] = sanitize_text_field(is_array($raw) ? implode(', ', $raw) : $raw);
                }
            }

            // Tag configurati per questo form: quelli "sempre" vanno su ogni
            // iscritto, quelli di consenso solo se la checkbox è spuntata.
            $mc_tags = suite_barrio_forms_parse_tags($settings['mailchimp_tags'] ?? '');
            $mc_consent_tags = suite_barrio_forms_parse_tags($settings['mailchimp_consent_tags'] ?? '');
            if ($commercial_consent) {
                $mc_tags = array_merge($mc_tags, $mc_consent_tags);
            } elseif ($mc_consent_tags) {
                suite_barrio_forms_log('[Mailchimp] Consenso commerciale assente (campo: ' . $mc_optin_field . ') — iscritto senza i tag: ' . implode(', ', $mc_consent_tags));
            }
            // Il filtro resta l'ultima parola, per casi che l'interfaccia non copre.
            $mc_tags = apply_filters('suite_barrio_mailchimp_tags', array_values(array_unique($mc_tags)), $form_id, $submission_data, $commercial_consent);

            $result = suite_barrio_mailchimp_subscribe($mc_email, $mc_list_id, $mc_merge_fields, (array) $mc_tags);
            if (is_wp_error($result)) {
                suite_barrio_forms_log('[Mailchimp] Errore iscrizione per form_id=' . $form_id . ': ' . $result->get_error_message());
            }
        } else {
            suite_barrio_forms_log('[Mailchimp] Salto iscrizione: email non valida.');
        }
    }

    wp_send_json_success(['message' => $settings['success_message'] ?? 'Grazie! Il tuo messaggio è stato inviato.']);
}

/* ─────────────────────────────────────────
   AJAX ADMIN: salva impostazioni globali
   (Mailjet / MailUp / Mailchimp / reCAPTCHA)
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_forms_save_global_settings', function () {
    check_ajax_referer('suite_barrio_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    // Aggiorna solo i provider effettivamente presenti nel POST, così i diversi
    // pulsanti "Salva" non si azzerano a vicenda le credenziali degli altri.
    if (isset($_POST['mailjet_api_key']) || isset($_POST['mailjet_secret_key'])) {
        update_option('suite_barrio_forms_mailjet', [
            'api_key' => sanitize_text_field(wp_unslash($_POST['mailjet_api_key'] ?? '')),
            'secret_key' => sanitize_text_field(wp_unslash($_POST['mailjet_secret_key'] ?? '')),
        ], false);
    }

    // MailUp: nessuna credenziale necessaria — la chiamata è pubblica
    update_option('suite_barrio_forms_mailup', [], false);

    // Mailchimp: API key globale (il data-center si ricava dal suffisso della key)
    if (isset($_POST['mailchimp_api_key'])) {
        update_option('suite_barrio_forms_mailchimp', [
            'api_key' => sanitize_text_field(wp_unslash($_POST['mailchimp_api_key'])),
        ], false);
    }

    // reCAPTCHA Enterprise: credenziali uniche per tutti i form
    if (isset($_POST['recaptcha_site_key']) || isset($_POST['recaptcha_project_id'])) {
        update_option('suite_barrio_forms_recaptcha', [
            'project_id' => sanitize_text_field(wp_unslash($_POST['recaptcha_project_id'] ?? '')),
            'site_key' => sanitize_text_field(wp_unslash($_POST['recaptcha_site_key'] ?? '')),
            'api_key' => sanitize_text_field(wp_unslash($_POST['recaptcha_api_key'] ?? '')),
        ], false);
    }

    if (function_exists('suite_barrio_invalidate_vercel_cache')) {
        suite_barrio_invalidate_vercel_cache('wp:global');
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
        suite_barrio_forms_log('[MailUp] ' . $err);
        return new WP_Error('mailup_curl', $err);
    }

    $http_code = wp_remote_retrieve_response_code($curl_response);
    $response = wp_remote_retrieve_body($curl_response);

    // Errore HTTP
    if ($http_code < 200 || $http_code >= 300) {
        $err = "MailUp xmlSubscribe: risposta HTTP {$http_code} — " . wp_strip_all_tags($response);
        suite_barrio_forms_log('[MailUp] ' . $err);
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
            suite_barrio_forms_log('[MailUp] ' . $err);
            return new WP_Error('mailup_subscribe', $err);
        }
    }

    suite_barrio_forms_log("[MailUp] Iscrizione OK — list={$list_id} group={$group_id} http={$http_code}");
    return true;
}

/* ─────────────────────────────────────────
   HELPER: lista di tag da stringa separata da virgole
   ───────────────────────────────────────── */
/**
 * Converte "Sito web, Newsletter" in ['Sito web', 'Newsletter'].
 * Scarta i valori vuoti e i duplicati, così una virgola di troppo o un tag
 * ripetuto non generano chiamate inutili a Mailchimp.
 *
 * @param string $raw Valore così come digitato nell'interfaccia.
 * @return string[]
 */
function suite_barrio_forms_parse_tags($raw): array
{
    if (!is_string($raw) || '' === trim($raw))
        return [];

    $tags = array_map('sanitize_text_field', explode(',', $raw));
    $tags = array_filter(array_map('trim', $tags), static fn($t) => '' !== $t);

    return array_values(array_unique($tags));
}

/* ─────────────────────────────────────────
   HELPER: iscrizione tramite Mailchimp Marketing API v3
   ───────────────────────────────────────── */
/**
 * Iscrive (upsert) un contatto a un'Audience Mailchimp.
 * La API key è globale (opzione suite_barrio_forms_mailchimp); il data-center (dc)
 * viene ricavato dal suffisso della key (es. "…-us21" → "us21").
 *
 * @param string $email        Email del contatto.
 * @param string $list_id      ID Audience Mailchimp.
 * @param array  $merge_fields Merge tag come ['FNAME' => 'Mario'].
 * @param array  $tags         Tag da applicare al contatto.
 * @return true|WP_Error true se ok, WP_Error in caso di errore.
 */
function suite_barrio_mailchimp_subscribe(string $email, string $list_id, array $merge_fields = [], array $tags = [])
{
    $opts = get_option('suite_barrio_forms_mailchimp', []);
    $api_key = is_array($opts) ? trim($opts['api_key'] ?? '') : '';

    if ('' === $api_key)
        return new WP_Error('mailchimp_no_key', 'Mailchimp: API key non configurata (Impostazioni globali).');
    if ('' === $list_id)
        return new WP_Error('mailchimp_no_list', 'Mailchimp: Audience ID mancante.');

    // dc = parte dopo l'ultimo trattino della API key
    $dash = strrchr($api_key, '-');
    $dc = $dash ? substr($dash, 1) : '';
    if ('' === $dc)
        return new WP_Error('mailchimp_no_dc', 'Mailchimp: data-center non ricavabile dalla API key (formato atteso: xxxx-us21).');

    $auth = 'Basic ' . base64_encode('anystring:' . $api_key);
    // upsert per subscriber hash = md5(lowercase(email))
    $subscriber_hash = md5(strtolower($email));
    $base = "https://{$dc}.api.mailchimp.com/3.0/lists/" . rawurlencode($list_id) . "/members/{$subscriber_hash}";

    $body = [
        // iscrizione diretta solo per i NUOVI contatti; sugli esistenti non forziamo lo
        // status (evita errori 400 di "compliance" su chi si è già disiscritto)
        'email_address' => $email,
        'status_if_new' => 'subscribed',
    ];
    if (!empty($merge_fields)) {
        $body['merge_fields'] = $merge_fields;
    }

    $response = wp_remote_request($base, [
        'method' => 'PUT',
        'timeout' => 15,
        'headers' => ['Authorization' => $auth, 'Content-Type' => 'application/json'],
        'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        $err = 'Mailchimp: errore di rete — ' . $response->get_error_message();
        suite_barrio_forms_log('[Mailchimp] ' . $err);
        return new WP_Error('mailchimp_http', $err);
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        $json = json_decode(wp_remote_retrieve_body($response), true);
        $detail = is_array($json) ? ($json['detail'] ?? ($json['title'] ?? '')) : '';
        $err = "Mailchimp: risposta HTTP {$code}" . ($detail ? " — {$detail}" : '');
        suite_barrio_forms_log('[Mailchimp] ' . $err);
        return new WP_Error('mailchimp_subscribe', $err);
    }

    // Applica i tag tramite l'endpoint dedicato: idempotente e valido sia per
    // contatti nuovi che esistenti. Non blocca l'esito dell'iscrizione.
    $tag_objs = [];
    foreach ($tags as $t) {
        $t = trim((string) $t);
        if ('' !== $t) {
            $tag_objs[] = ['name' => $t, 'status' => 'active'];
        }
    }
    if (!empty($tag_objs)) {
        $tag_resp = wp_remote_post($base . '/tags', [
            'timeout' => 15,
            'headers' => ['Authorization' => $auth, 'Content-Type' => 'application/json'],
            'body' => wp_json_encode(['tags' => $tag_objs]),
        ]);
        if (is_wp_error($tag_resp)) {
            suite_barrio_forms_log('[Mailchimp] Tag non applicati (rete): ' . $tag_resp->get_error_message());
        } else {
            $tcode = wp_remote_retrieve_response_code($tag_resp);
            if ($tcode < 200 || $tcode >= 300) {
                suite_barrio_forms_log('[Mailchimp] Tag non applicati — HTTP ' . $tcode . ' — ' . wp_strip_all_tags(wp_remote_retrieve_body($tag_resp)));
            }
        }
    }

    suite_barrio_forms_log("[Mailchimp] Iscrizione OK — list={$list_id} http={$code} tags=" . implode(',', $tags));
    return true;
}

/* ─────────────────────────────────────────
   HELPER: sanifica ricorsivamente ogni stringa di una struttura
   fields/settings decodificata dal JSON del form builder (nomi,
   etichette, placeholder, opzioni...), lasciando intatti bool/numeri
   e la struttura degli array.
   ───────────────────────────────────────── */
function suite_barrio_forms_sanitize_recursive($value, string $key = '')
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            // Le chiavi numeriche sono elementi di lista: ereditano la chiave del
            // contenitore, così 'options' => [0 => '…'] resta riconoscibile come
            // opzione anche scendendo di un livello.
            $out[$k] = suite_barrio_forms_sanitize_recursive($v, is_int($k) ? $key : (string) $k);
        }
        return $out;
    }
    if (is_string($value)) {
        // Le etichette di radio/checkbox vengono stampate come HTML dallo
        // shortcode (wp_kses_post), quindi qui il markup consentito va
        // conservato: un link alla privacy policy nell'etichetta di una
        // checkbox di consenso è un caso d'uso normale. Stesso allowlist usato
        // dal salvataggio traduzioni, così i due percorsi non divergono.
        if ('options' === $key) {
            return wp_kses_post($value);
        }
        // textarea_field invece di text_field: preserva eventuali interruzioni
        // di riga intenzionali (es. un valore predefinito multi-riga) mentre
        // rimuove comunque tag HTML e caratteri di controllo non validi.
        return sanitize_textarea_field($value);
    }
    return $value;
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
        $fields = wp_json_encode(suite_barrio_forms_sanitize_recursive($fields_arr));
    }

    $settings_arr = json_decode($settings, true);
    if (is_array($settings_arr)) {
        $settings = wp_json_encode(suite_barrio_forms_sanitize_recursive($settings_arr));
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
        if (function_exists('suite_barrio_invalidate_vercel_cache')) {
            suite_barrio_invalidate_vercel_cache('form_' . $id);
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
        if (function_exists('suite_barrio_invalidate_vercel_cache')) {
            suite_barrio_invalidate_vercel_cache('form_' . $new_id);
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
    suite_barrio_forms_delete_translations($id);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->delete($wpdb->prefix . SUITE_BARRIO_FORMS_TABLE, ['id' => $id], ['%d']);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato; introdurre caching qui rischierebbe dati stantii senza beneficio reale
    $wpdb->delete($wpdb->prefix . SUITE_BARRIO_FORMS_SUB_TABLE, ['form_id' => $id], ['%d']);
    if (function_exists('suite_barrio_invalidate_vercel_cache')) {
        suite_barrio_invalidate_vercel_cache('form_' . $id);
    }
    wp_send_json_success('Form eliminato.');
});

/* ─────────────────────────────────────────
   AJAX ADMIN: init traduzione form (copia i valori originali)
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_forms_init_translation', function () {
    check_ajax_referer('suite_barrio_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $form_id = intval($_POST['form_id'] ?? 0);
    $lang = sanitize_key(wp_unslash($_POST['lang'] ?? ''));
    if (!$form_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    $allowed = function_exists('suite_barrio_multilang_translation_languages')
        ? suite_barrio_multilang_translation_languages()
        : [];
    if (!empty($allowed) && !in_array($lang, $allowed, true))
        wp_send_json_error('Lingua non consentita: ' . $lang);

    if (suite_barrio_forms_get_translation($form_id, $lang))
        wp_send_json_success(['already_exists' => true]);

    $form = suite_barrio_forms_get($form_id);
    if (!$form)
        wp_send_json_error('Form non trovato.');

    $fields = json_decode($form->fields, true) ?: [];
    $settings = json_decode($form->settings, true) ?: [];
    [$fi18n, $si18n] = suite_barrio_forms_extract_translatable($fields, $settings);

    if (!suite_barrio_forms_save_translation($form_id, $lang, $fi18n, $si18n)) {
        global $wpdb;
        wp_send_json_error('Errore DB: ' . $wpdb->last_error);
    }

    wp_send_json_success(['already_exists' => false]);
});

/* ─────────────────────────────────────────
   AJAX ADMIN: salva traduzione form
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_forms_save_translation', function () {
    check_ajax_referer('suite_barrio_forms_nonce', 'nonce');
    if (!current_user_can('manage_options'))
        wp_send_json_error('Permesso negato.');

    $form_id = intval($_POST['form_id'] ?? 0);
    $lang = sanitize_key(wp_unslash($_POST['lang'] ?? ''));
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON grezzo, decodificato con json_decode e sanificato campo per campo più sotto; sanitize_text_field corromperebbe il JSON
    $fields_i18n_raw = wp_unslash($_POST['fields_i18n'] ?? '[]');
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON grezzo, decodificato con json_decode e sanificato campo per campo più sotto; sanitize_text_field corromperebbe il JSON
    $settings_i18n_raw = wp_unslash($_POST['settings_i18n'] ?? '{}');

    if (!$form_id || !$lang)
        wp_send_json_error('Parametri mancanti.');

    $allowed = function_exists('suite_barrio_multilang_translation_languages')
        ? suite_barrio_multilang_translation_languages()
        : [];
    if (!empty($allowed) && !in_array($lang, $allowed, true))
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
            // Le option di radio/checkbox sono renderizzate come HTML (es. link privacy)
            'options' => array_map('wp_kses_post', (array) ($entry['options'] ?? [])),
        ];
    }

    // Sanitize settings_i18n
    $si18n_clean = [
        'submit_text' => sanitize_text_field($si18n['submit_text'] ?? ''),
        'redirect_url' => sanitize_url($si18n['redirect_url'] ?? ''),
        'success_message' => wp_kses_post($si18n['success_message'] ?? ''),
        'mailup_list_id' => sanitize_text_field($si18n['mailup_list_id'] ?? ''),
        'mailup_group_id' => sanitize_text_field($si18n['mailup_group_id'] ?? ''),
        'mailchimp_list_id' => sanitize_text_field($si18n['mailchimp_list_id'] ?? ''),
        'mailchimp_tags' => sanitize_text_field($si18n['mailchimp_tags'] ?? ''),
        'mailchimp_consent_tags' => sanitize_text_field($si18n['mailchimp_consent_tags'] ?? ''),
        'notifications' => [],
    ];
    foreach (array_keys(suite_barrio_forms_email_text_settings()) as $k) {
        $si18n_clean[$k] = 'email_footer' === $k
            ? sanitize_textarea_field($si18n[$k] ?? '')
            : sanitize_text_field($si18n[$k] ?? '');
    }
    foreach ((array) ($si18n['notifications'] ?? []) as $notif) {
        $nt = [];
        foreach (suite_barrio_forms_translatable_notif() as $nk) {
            // "message" è multi-riga (come nell'editor), il resto singola riga
            $nt[$nk] = 'message' === $nk
                ? sanitize_textarea_field($notif[$nk] ?? '')
                : sanitize_text_field($notif[$nk] ?? '');
        }
        $si18n_clean['notifications'][] = $nt;
    }

    if (!suite_barrio_forms_save_translation($form_id, $lang, $fi18n_clean, $si18n_clean))
        wp_send_json_error('Errore DB.');

    if (function_exists('suite_barrio_invalidate_vercel_cache')) {
        suite_barrio_invalidate_vercel_cache('form_' . $form_id);
    }
    wp_send_json_success(['message' => 'Traduzione salvata.']);
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
    if (!current_user_can('manage_options') && !current_user_can('edit_others_posts'))
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
   AJAX ADMIN: elimina più submissions (bulk delete)
   ───────────────────────────────────────── */
add_action('wp_ajax_suite_barrio_forms_delete_submissions', function () {
    check_ajax_referer('suite_barrio_forms_nonce', 'nonce');
    if (!current_user_can('manage_options') && !current_user_can('edit_others_posts'))
        wp_send_json_error('Permesso negato.');

    global $wpdb;
    $form_id = intval($_POST['form_id'] ?? 0);
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array JSON di ID, decodificato e forzato a interi nella riga successiva
    $raw = wp_unslash($_POST['ids'] ?? '[]');
    $ids = array_values(array_filter(array_map('intval', (array) (json_decode($raw, true) ?: [])), fn($id) => $id > 0));

    if (empty($ids))
        wp_send_json_error('Nessun ID valido.');

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $table = $wpdb->prefix . SUITE_BARRIO_FORMS_SUB_TABLE;
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- query su tabella custom del plugin, nessun object-cache group dedicato
    $wpdb->query($wpdb->prepare(
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table è una costante di plugin e $placeholders è generato solo da segnaposto %d, non da input utente
        "DELETE FROM `{$table}` WHERE id IN ({$placeholders})" . ($form_id ? ' AND form_id = %d' : ''),
        ...($form_id ? [...$ids, $form_id] : $ids)
    ));
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    wp_send_json_success(['deleted' => count($ids)]);
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

    // Stessa lingua dell'invio originale: notifica tradotta e testi fissi nella lingua della registrazione
    $sub_lang = sanitize_key($sub->lang ?? '');
    if ($sub_lang) {
        $trans = suite_barrio_forms_get_translation((int) $form->id, $sub_lang);
        if ($trans) {
            [, $settings] = suite_barrio_forms_apply_translation(json_decode($form->fields, true) ?: [], $settings, $trans);
        }
    }

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

    $log = suite_barrio_forms_send_notifications($form, $temp_settings, $submission_data, [], $sub_lang);

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
        $settings['domain_email'] ?? get_option('admin_email'),
        sanitize_key(wp_unslash($_POST['lang'] ?? '')),
        sanitize_textarea_field(wp_unslash($_POST['email_footer'] ?? ''))
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

    // ── Contesto multilingua (WPML, via modulo Tools) ─────────────────────
    $ml_active = function_exists('suite_barrio_multilang_is_enabled') && suite_barrio_multilang_is_enabled();
    $ml_primary = $ml_active ? suite_barrio_multilang_primary_language() : '';
    $ml_trans = $ml_active ? suite_barrio_multilang_translation_languages() : [];
    $ml_languages = $ml_active ? suite_barrio_multilang_languages() : [];

    // Lingua selezionata nell'editor: dalla querystring, solo se valida e la
    // traduzione esiste già (l'inizializzazione avviene dalla lista form).
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $qs_lang = isset($_GET['lang']) ? sanitize_key($_GET['lang']) : '';
    $editor_lang = $ml_primary;
    $is_translation_mode = false;
    if ($ml_active && $edit_id && $qs_lang && $qs_lang !== $ml_primary) {
        if (in_array($qs_lang, $ml_trans, true) && suite_barrio_forms_get_translation($edit_id, $qs_lang)) {
            $editor_lang = $qs_lang;
            $is_translation_mode = true;
        }
    }

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

        /* ── Pill lingue nella lista form ── */
        .suite-barrio-form-langs-row td {
        background: #fafafa !important;
        padding: 6px 14px 8px !important;
        border-top: none !important;
        }

        .suite-barrio-form-langs {
        display: flex;
        align-items: center;
        gap: 5px;
        flex-wrap: wrap;
        }

        .suite-barrio-form-langs-label {
        font-size: 11px;
        color: #a7aaad;
        margin-right: 3px;
        }

        .suite-barrio-lang-pill {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        font-size: 11px;
        font-weight: 600;
        padding: 2px 7px;
        border-radius: 3px;
        border: 1px solid;
        }

        .suite-barrio-lang-pill.primary,
        .suite-barrio-lang-pill.translated {
        background: #f0fdf4;
        border-color: #bbf7d0;
        color: #166534;
        }

        .suite-barrio-lang-pill.translated {
        cursor: pointer;
        text-decoration: none;
        }

        .suite-barrio-lang-pill.missing {
        background: #fff;
        border-color: #c3c4c7;
        color: #646970;
        cursor: pointer;
        }

        .suite-barrio-lang-pill.missing:hover {
        background: #f0f6fc;
        border-color: #72aee6;
        color: #2271b1;
        }

        .suite-barrio-lang-pill.missing .suite-barrio-lang-add {
        font-size: 13px;
        font-weight: 400;
        line-height: 1;
        }

        .suite-barrio-lang-pill.loading {
        opacity: .6;
        cursor: wait;
        }

        /* ── Modalità traduzione: si modificano solo i testi ──
           Struttura, slug, obbligatorietà, validazione, logica condizionale e
           configurazione delle integrazioni si ereditano dalla lingua principale.
           Il blocco è anche applicato via JS (vedi IS_TRANSLATION nel builder):
           qui è solo il feedback visivo. */
        .suite-barrio-translation-mode .suite-barrio-drag-handle,
        .suite-barrio-translation-mode .suite-barrio-btn-remove-field {
        display: none !important;
        }

        .suite-barrio-translation-mode .suite-barrio-field-card {
        cursor: pointer !important;
        }

        .suite-barrio-translation-mode #prop-name,
        .suite-barrio-translation-mode #prop-required,
        .suite-barrio-translation-mode #prop-min,
        .suite-barrio-translation-mode #prop-max,
        .suite-barrio-translation-mode #prop-format,
        .suite-barrio-translation-mode .suite-barrio-cond-section,
        .suite-barrio-translation-mode .suite-barrio-prop-check,
        .suite-barrio-translation-mode .suite-barrio-msg-mode-btn,
        .suite-barrio-translation-mode .suite-barrio-after-submit-toggle,
        .suite-barrio-translation-mode .suite-barrio-ast-btn,
        .suite-barrio-translation-mode #suite-barrio-setting-display-page-info,
        .suite-barrio-translation-mode #suite-barrio-setting-mailup-enabled,
        .suite-barrio-translation-mode #suite-barrio-setting-mailup-email-field,
        .suite-barrio-translation-mode #suite-barrio-setting-mailup-optin-field,
        .suite-barrio-translation-mode #suite-barrio-mailup-add-map-row,
        .suite-barrio-translation-mode #suite-barrio-mailup-field-map-rows,
        .suite-barrio-translation-mode #suite-barrio-setting-mailchimp-enabled,
        .suite-barrio-translation-mode #suite-barrio-setting-mailchimp-email-field,
        .suite-barrio-translation-mode #suite-barrio-setting-mailchimp-optin-field,
        .suite-barrio-translation-mode #suite-barrio-mailchimp-add-map-row,
        .suite-barrio-translation-mode #suite-barrio-mailchimp-field-map-rows,
        .suite-barrio-translation-mode .suite-barrio-notif-card [data-field]:disabled,
        .suite-barrio-translation-mode .email-style-settings :disabled {
        pointer-events: none !important;
        opacity: .35 !important;
        user-select: none;
        }

        /* Notifiche: si traducono solo i testi, non si aggiungono né si eliminano
           (le traduzioni sono salvate per posizione) */
        .suite-barrio-translation-mode #suite-barrio-btn-add-notification,
        .suite-barrio-translation-mode .suite-barrio-notif-del-btn,
        .suite-barrio-translation-mode .suite-barrio-email-field-pill {
        display: none !important;
        }

        /* Blocca anche i label wrapper dei toggle integrazione */
        .suite-barrio-translation-mode #suite-barrio-setting-mailup-enabled~span,
        .suite-barrio-translation-mode #suite-barrio-setting-mailchimp-enabled~span {
        pointer-events: none !important;
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


        /* Una sezione sotto l'altra: Stile email, Testi email, Notifiche */
        #suite-barrio-tab-notifiche {
        display: flex;
        flex-direction: column;
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

        <div id="suite-barrio-forms-wrap"
            class="wrap suite-barrio-wrap<?php echo $is_translation_mode ? ' suite-barrio-translation-mode' : ''; ?>">

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
            $mj_global = get_option('suite_barrio_forms_mailjet', []);
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
                $mj_opts = get_option('suite_barrio_forms_mailjet', []);
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
                                                $ip_response = wp_remote_get('https://api4.ipify.org', ['timeout' => 5]); // forza IPv4
                                                $ip = !is_wp_error($ip_response) ? wp_remote_retrieve_body($ip_response) : '';
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

                    <!-- Mailchimp card -->
                    <?php $mc_opts = get_option('suite_barrio_forms_mailchimp', []);
                    $mc_opts = is_array($mc_opts) ? $mc_opts : []; ?>
                    <div class="suite-barrio-section-box" style="margin-bottom:20px;">
                        <div class="suite-barrio-section-head">
                            <svg width="18" height="18" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"
                                style="flex-shrink:0;">
                                <rect width="32" height="32" rx="6" fill="#FFE01B" />
                                <circle cx="13" cy="14.5" r="2.2" fill="#241C15" />
                                <circle cx="19" cy="14.5" r="2.2" fill="#241C15" />
                                <path d="M11 20c1.2 1.8 8.8 1.8 10 0" stroke="#241C15" stroke-width="1.6"
                                    stroke-linecap="round" fill="none" />
                            </svg>
                            Mailchimp
                        </div>
                        <div class="suite-barrio-section-body">
                            <p style="margin:0 0 16px;font-size:12px;color:#a7aaad;">API key per iscrivere i contatti alle
                                Audience Mailchimp. Il data-center viene ricavato automaticamente dal suffisso della key
                                (es. <code>…-us21</code>).</p>
                            <table class="form-table" style="max-width:100%;">
                                <tbody>
                                    <tr>
                                        <th style="width:220px;"><label for="suite-barrio-global-mc-api-key">API key</label>
                                        </th>
                                        <td>
                                            <div style="display:flex;gap:6px;align-items:center;">
                                                <input type="password" id="suite-barrio-global-mc-api-key" class="large-text"
                                                    value="<?php echo esc_attr($mc_opts['api_key'] ?? ''); ?>"
                                                    placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us21"
                                                    autocomplete="new-password" spellcheck="false">
                                                <button type="button" class="suite-barrio-btn suite-barrio-btn-ghost"
                                                    onclick="(function(){var i=document.getElementById('suite-barrio-global-mc-api-key');i.type=i.type==='password'?'text':'password';})();">Mostra</button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p style="margin:16px 0 0;">
                                <button type="button" class="suite-barrio-btn suite-barrio-btn-primary"
                                    id="suite-barrio-btn-save-global-mc">
                                    Salva impostazioni Mailchimp
                                </button>
                                <span id="suite-barrio-global-saved-msg-mc"
                                    style="display:none;margin-left:10px;color:#059669;font-size:13px;">✓
                                    Salvato</span>
                            </p>
                        </div><!-- .suite-barrio-section-body -->
                    </div><!-- .suite-barrio-section-box Mailchimp -->

                    <!-- reCAPTCHA Enterprise card -->
                    <?php $rc_opts = get_option('suite_barrio_forms_recaptcha', []);
                    $rc_opts = is_array($rc_opts) ? $rc_opts : []; ?>
                    <div class="suite-barrio-section-box" style="margin-bottom:20px;">
                        <div class="suite-barrio-section-head">
                            <svg width="18" height="18" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"
                                style="flex-shrink:0;">
                                <rect width="32" height="32" rx="6" fill="#1A73E8" />
                                <path d="M16 7l7 3v6c0 4.2-2.9 7.6-7 9-4.1-1.4-7-4.8-7-9v-6l7-3z" fill="#fff" />
                                <path d="M13 16.2l2.2 2.2 4-4.4" stroke="#1A73E8" stroke-width="1.8" stroke-linecap="round"
                                    stroke-linejoin="round" fill="none" />
                            </svg>
                            reCAPTCHA Enterprise (v3)
                        </div>
                        <div class="suite-barrio-section-body">
                            <p style="margin:0 0 16px;font-size:12px;color:#a7aaad;">Credenziali uniche per
                                <strong>tutti</strong> i form. Compila tutti e tre i campi per attivare la protezione;
                                lasciane uno vuoto per disattivarla. Soglia di punteggio: <code>0.5</code> (modificabile
                                con il filtro <code>suite_barrio_forms_recaptcha_threshold</code>).</p>
                            <table class="form-table" style="max-width:100%;">
                                <tbody>
                                    <tr>
                                        <th style="width:220px;"><label
                                                for="suite-barrio-global-rc-project">Project ID</label></th>
                                        <td>
                                            <input type="text" id="suite-barrio-global-rc-project" class="large-text"
                                                value="<?php echo esc_attr($rc_opts['project_id'] ?? ''); ?>"
                                                placeholder="es. my-project-123456" autocomplete="off" spellcheck="false">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="suite-barrio-global-rc-site">Site Key (pubblica)</label></th>
                                        <td>
                                            <input type="text" id="suite-barrio-global-rc-site" class="large-text"
                                                value="<?php echo esc_attr($rc_opts['site_key'] ?? ''); ?>"
                                                placeholder="6Lc…" autocomplete="off" spellcheck="false">
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="suite-barrio-global-rc-api">API Key (privata)</label></th>
                                        <td>
                                            <div style="display:flex;gap:6px;align-items:center;">
                                                <input type="password" id="suite-barrio-global-rc-api" class="large-text"
                                                    value="<?php echo esc_attr($rc_opts['api_key'] ?? ''); ?>"
                                                    placeholder="AIza…" autocomplete="new-password" spellcheck="false">
                                                <button type="button" class="suite-barrio-btn suite-barrio-btn-ghost"
                                                    onclick="(function(){var i=document.getElementById('suite-barrio-global-rc-api');i.type=i.type==='password'?'text':'password';})();">Mostra</button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <p style="margin:16px 0 0;">
                                <button type="button" class="suite-barrio-btn suite-barrio-btn-primary"
                                    id="suite-barrio-btn-save-global-rc">
                                    Salva impostazioni reCAPTCHA
                                </button>
                                <span id="suite-barrio-global-saved-msg-rc"
                                    style="display:none;margin-left:10px;color:#059669;font-size:13px;">✓
                                    Salvato</span>
                            </p>
                        </div><!-- .suite-barrio-section-body -->
                    </div><!-- .suite-barrio-section-box reCAPTCHA -->
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

                // Mailchimp save button
                var btnMc = document.getElementById('suite-barrio-btn-save-global-mc');
                var msgMc = document.getElementById('suite-barrio-global-saved-msg-mc');
                if (btnMc) btnMc.addEventListener('click', function () {
                btnMc.disabled = true;
                btnMc.textContent = 'Salvataggio…';
                var fd = new URLSearchParams({
                action: 'suite_barrio_forms_save_global_settings',
                nonce: '<?php echo esc_js($nonce); ?>',
                mailchimp_api_key: document.getElementById('suite-barrio-global-mc-api-key').value.trim()
                });
                fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                method: 'POST',
                body: fd
                }).then(function (r) { return r.json(); }).then(function (res) {
                if (res.success) {
                msgMc.style.display = 'inline';
                setTimeout(function () { msgMc.style.display = 'none'; }, 3000);
                } else {
                alert(res.data || 'Errore.');
                }
                }).catch(function () { alert('Errore di rete.'); }).finally(function () {
                btnMc.disabled = false;
                btnMc.textContent = 'Salva impostazioni Mailchimp';
                });
                });

                // reCAPTCHA save button
                var btnRc = document.getElementById('suite-barrio-btn-save-global-rc');
                var msgRc = document.getElementById('suite-barrio-global-saved-msg-rc');
                if (btnRc) btnRc.addEventListener('click', function () {
                btnRc.disabled = true;
                btnRc.textContent = 'Salvataggio…';
                var fd = new URLSearchParams({
                action: 'suite_barrio_forms_save_global_settings',
                nonce: '<?php echo esc_js($nonce); ?>',
                recaptcha_project_id: document.getElementById('suite-barrio-global-rc-project').value.trim(),
                recaptcha_site_key: document.getElementById('suite-barrio-global-rc-site').value.trim(),
                recaptcha_api_key: document.getElementById('suite-barrio-global-rc-api').value.trim()
                });
                fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                method: 'POST',
                body: fd
                }).then(function (r) { return r.json(); }).then(function (res) {
                if (res.success) {
                msgRc.style.display = 'inline';
                setTimeout(function () { msgRc.style.display = 'none'; }, 3000);
                } else {
                alert(res.data || 'Errore.');
                }
                }).catch(function () { alert('Errore di rete.'); }).finally(function () {
                btnRc.disabled = false;
                btnRc.textContent = 'Salva impostazioni reCAPTCHA';
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
                                    <?php if ($ml_active): ?>
                                        <tr class="suite-barrio-form-langs-row">
                                            <td colspan="5">
                                                <div class="suite-barrio-form-langs">
                                                    <span class="suite-barrio-form-langs-label">Lingue:</span>
                                                    <span class="suite-barrio-lang-pill primary">
                                                        <span class="dashicons dashicons-yes"
                                                            style="font-size:11px;width:11px;height:11px;"></span>
                                                        <?php echo esc_html($ml_languages[$ml_primary] ?? strtoupper($ml_primary)); ?>
                                                    </span>
                                                    <?php foreach ($ml_trans as $lc):
                                                        $ll = $ml_languages[$lc] ?? strtoupper($lc);
                                                        $has_t = (bool) suite_barrio_forms_get_translation((int) $f->id, $lc);
                                                        $edit_trl = admin_url('admin.php?page=suite-barrio-forms&tab=builder&edit_id=' . $f->id . '&lang=' . $lc);
                                                        ?>
                                                        <?php if ($has_t): ?>
                                                            <a href="<?php echo esc_url($edit_trl); ?>"
                                                                class="suite-barrio-lang-pill translated"
                                                                title="Modifica traduzione in <?php echo esc_attr($ll); ?>">
                                                                <span class="dashicons dashicons-yes"
                                                                    style="font-size:11px;width:11px;height:11px;"></span>
                                                                <?php echo esc_html($ll); ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <button type="button"
                                                                class="suite-barrio-lang-pill missing suite-barrio-form-lang-init-btn"
                                                                data-form-id="<?php echo intval($f->id); ?>"
                                                                data-lang="<?php echo esc_attr($lc); ?>"
                                                                data-lang-label="<?php echo esc_attr($ll); ?>"
                                                                title="Inizializza traduzione in <?php echo esc_attr($ll); ?>">
                                                                <span class="suite-barrio-lang-add">+</span>
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

                <?php if ($ml_active):
                    ob_start(); ?>
                    (function () {
                    const NONCE = '<?php echo esc_js($nonce); ?>';
                    const AJAX = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
                    const URL_EDIT_BASE = '<?php echo esc_js(admin_url('admin.php?page=suite-barrio-forms&tab=builder&edit_id=')); ?>';

                    // Pill "+ Lingua": crea la traduzione copiando i testi originali,
                    // poi la pill diventa un link all'editor di quella lingua.
                    document.querySelectorAll('.suite-barrio-form-lang-init-btn').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                    var formId = this.dataset.formId;
                    var lang = this.dataset.lang;
                    var langLabel = this.dataset.langLabel;
                    var pill = this;

                    pill.classList.add('loading');
                    pill.disabled = true;

                    fetch(AJAX, {
                    method: 'POST',
                    body: new URLSearchParams({
                    action: 'suite_barrio_forms_init_translation',
                    nonce: NONCE,
                    form_id: formId,
                    lang: lang
                    })
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                    if (res.success) {
                    var link = document.createElement('a');
                    link.href = URL_EDIT_BASE + formId + '&lang=' + lang;
                    link.className = 'suite-barrio-lang-pill translated';
                    link.title = 'Modifica traduzione in ' + langLabel;
                    link.innerHTML = '<span class="dashicons dashicons-yes" style="font-size:11px;width:11px;height:11px;"></span> ' + langLabel;
                    pill.replaceWith(link);
                    } else {
                    alert(res.data || 'Errore durante l\'inizializzazione della traduzione.');
                    pill.classList.remove('loading');
                    pill.disabled = false;
                    }
                    })
                    .catch(function () {
                    alert('Errore di rete.');
                    pill.classList.remove('loading');
                    pill.disabled = false;
                    });
                    });
                    });
                    })();
                    <?php
                    $suite_barrio_lang_init_js = ob_get_clean();
                    wp_add_inline_script('suite-barrio-forms-admin', $suite_barrio_lang_init_js, 'after');
                endif; ?>

            <?php /* ══════ BUILDER ══════ */ elseif ($tab === 'builder'): ?>

                <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-forms')); ?>"
                    class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-ghost">←
                    Tutti i form</a>
                <div class="suite-barrio-builder-actions suite-barrio-cp-field-input" style="margin-top: 20px;">
                    <input type="text" id="suite-barrio-form-title" class="suite-barrio-input suite-barrio-builder-title-input"
                        style="max-width: none;<?php echo $is_translation_mode ? 'opacity:.5;' : ''; ?>"
                        value="<?php echo esc_attr($edit_form ? $edit_form->title : ''); ?>" placeholder="Nome del form…"
                        <?php disabled($is_translation_mode, true); ?>>
                    <button class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-primary"
                        id="suite-barrio-btn-save-form"><?php echo $is_translation_mode ? 'Salva traduzione' : 'Salva form'; ?></button>
                </div>

                <?php if ($ml_active && $edit_id):
                    // Lingue selezionabili: la principale + quelle già inizializzate.
                    $editor_langs = [$ml_primary => ($ml_languages[$ml_primary] ?? $ml_primary) . ' (principale)'];
                    foreach ($ml_trans as $lc) {
                        if (suite_barrio_forms_get_translation($edit_id, $lc)) {
                            $editor_langs[$lc] = $ml_languages[$lc] ?? strtoupper($lc);
                        }
                    }
                    ?>
                    <div class="suite-barrio-section-box"
                        style="display:flex;align-items:center;gap:10px;margin-top:14px;padding:10px 14px;">
                        <span class="dashicons dashicons-translation"
                            style="font-size:16px;width:16px;height:16px;color:#2271b1;flex-shrink:0;"></span>
                        <label for="suite-barrio-form-lang-select"
                            style="font-size:13px;font-weight:600;color:#1d2327;margin:0;">Lingua:</label>
                        <select id="suite-barrio-form-lang-select" style="min-width:180px;font-size:13px;">
                            <?php foreach ($editor_langs as $lc => $label): ?>
                                <option value="<?php echo esc_attr($lc); ?>"
                                    data-is-primary="<?php echo $lc === $ml_primary ? '1' : '0'; ?>" <?php selected($editor_lang, $lc); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($is_translation_mode): ?>
                            <span
                                style="font-size:12px;color:#0a4b78;background:#f0f6fc;padding:4px 10px;border-radius:3px;border:1px solid #c6d9ef;">
                                Stai modificando la traduzione in
                                <strong><?php echo esc_html($ml_languages[$editor_lang] ?? $editor_lang); ?></strong>.
                                Struttura, logica e impostazioni sono ereditate dall'originale.
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

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

                        <!-- Sx: tipi campo / disclaimer traduzione -->
                        <?php if ($is_translation_mode): ?>
                            <div class="suite-barrio-panel-fields"
                                style="background:#f0f6fc;border:1px solid #c6d9ef;border-radius:3px;padding:20px 16px;display:flex;flex-direction:column;gap:0;">
                                <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:14px;">
                                    <span class="dashicons dashicons-translation"
                                        style="font-size:20px;width:20px;height:20px;color:#2271b1;flex-shrink:0;margin-top:2px;"></span>
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
                                <p style="font-size:12px;color:#646970;margin:0 0 6px;font-weight:600;">Non puoi modificare:
                                </p>
                                <ul style="font-size:12px;color:#646970;margin:0;padding-left:16px;line-height:1.8;">
                                    <li>Struttura e ordine dei campi</li>
                                    <li>Tipo e nome (slug)</li>
                                    <li>Obbligatorietà e logica condizionale</li>
                                    <li>Validazione (min/max/formato)</li>
                                </ul>
                            </div>
                        <?php else: ?>
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
                        <?php endif; ?>

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

                    <!-- Mailchimp per-form card -->
                    <div class="suite-barrio-section-box" style="max-width:600px;margin-top:20px;">
                        <div class="suite-barrio-section-head">
                            <svg width="18" height="18" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg"
                                style="flex-shrink:0;">
                                <rect width="32" height="32" rx="6" fill="#FFE01B" />
                                <circle cx="13" cy="14.5" r="2.2" fill="#241C15" />
                                <circle cx="19" cy="14.5" r="2.2" fill="#241C15" />
                                <path d="M11 20c1.2 1.8 8.8 1.8 10 0" stroke="#241C15" stroke-width="1.6"
                                    stroke-linecap="round" fill="none" />
                            </svg>
                            Integrazione Mailchimp
                        </div>
                        <div class="suite-barrio-section-body">
                            <p style="margin:0 0 20px;font-size:12px;color:#a7aaad;">Iscrivi automaticamente i contatti a
                                un'Audience Mailchimp al submit di questo form. La API key va configurata nelle <a
                                    href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-forms&tab=settings')); ?>">Impostazioni
                                    globali</a>.</p>

                            <!-- Toggle abilitazione -->
                            <div style="margin-bottom:20px;">
                                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                                    <input type="checkbox" id="suite-barrio-setting-mailchimp-enabled" value="1"
                                        style="width:16px;height:16px;">
                                    <span style="font-size:13px;font-weight:600;">Abilita iscrizione Mailchimp</span>
                                </label>
                            </div>

                            <!-- Campi extra — visibili solo se abilitato -->
                            <div id="suite-barrio-mailchimp-fields-wrap"
                                style="display:none;border-top:1px solid #f0f0f0;padding-top:20px;">

                                <!-- Audience ID -->
                                <div style="margin-bottom:20px;">
                                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"
                                        for="suite-barrio-setting-mailchimp-list-id">Audience ID Mailchimp</label>
                                    <input type="text" id="suite-barrio-setting-mailchimp-list-id" class="regular-text"
                                        placeholder="es. a1b2c3d4e5">
                                    <p class="description" style="margin-top:4px;">ID dell'Audience (lista). Lo trovi in
                                        <em>Mailchimp → Audience → Settings → Audience name and defaults</em>.</p>
                                </div>

                                <!-- Campo email -->
                                <div style="margin-bottom:20px;">
                                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"
                                        for="suite-barrio-setting-mailchimp-email-field">Campo email del form</label>
                                    <input type="text" id="suite-barrio-setting-mailchimp-email-field" class="regular-text"
                                        placeholder="email">
                                    <p class="description" style="margin-top:4px;">Lo <strong>slug</strong> del campo email
                                        di questo form. Default: <code>email</code>.</p>
                                </div>

                                <!-- Tag applicati al contatto -->
                                <div style="margin-bottom:24px;">
                                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;">
                                        Tag applicati al contatto
                                        <span style="font-weight:400;color:#a7aaad;">(opzionale)</span>
                                    </label>
                                    <p class="description" style="margin-bottom:12px;margin-top:0;">
                                        Etichette con cui segmentare gli iscritti dentro l'Audience. Scrivili
                                        <strong>separati da virgola</strong>; gli spazi ai bordi vengono ignorati.
                                        I tag che non esistono ancora vengono creati da Mailchimp al primo utilizzo,
                                        e riapplicarli a un contatto che li ha già non fa danni.
                                    </p>
                                    <?php if ($ml_active): ?>
                                        <p class="description"
                                            style="margin-bottom:12px;margin-top:0;padding:10px 12px;background:#f0f6fc;border:1px solid #c6d9ef;border-radius:3px;color:#0a4b78;">
                                            <?php if ($is_translation_mode): ?>
                                                Stai impostando i tag per
                                                <strong><?php echo esc_html($ml_languages[$editor_lang] ?? $editor_lang); ?></strong>:
                                                usali per separare gli iscritti di questa lingua (es.
                                                <code>Newsletter EN</code>) e poter inviare campagne nella lingua giusta.
                                                <strong>Lascia vuoto</strong> per ereditare i tag della lingua principale.
                                            <?php else: ?>
                                                Questi sono i tag della <strong>lingua principale</strong>. Ogni lingua può
                                                averne di propri: apri il form nella lingua che ti interessa dalle pill in
                                                <a
                                                    href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-forms')); ?>">elenco
                                                    form</a> e compila i tag lì. Le lingue che li lasciano vuoti usano
                                                questi.
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>

                                    <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;"
                                        for="suite-barrio-setting-mailchimp-tags">Tag sempre applicati</label>
                                    <input type="text" id="suite-barrio-setting-mailchimp-tags" class="regular-text"
                                        style="width:100%;" placeholder="es. Sito web, Richieste contatto">
                                    <p class="description" style="margin-top:4px;margin-bottom:16px;">Assegnati a
                                        <strong>ogni</strong> contatto iscritto da questo form, indipendentemente dal
                                        consenso commerciale. Servono a sapere da dove arriva l'iscritto.</p>

                                    <label style="display:block;font-weight:600;font-size:12px;margin-bottom:4px;"
                                        for="suite-barrio-setting-mailchimp-consent-tags">Tag con consenso commerciale</label>
                                    <input type="text" id="suite-barrio-setting-mailchimp-consent-tags" class="regular-text"
                                        style="width:100%;" placeholder="es. Newsletter">
                                    <p class="description" style="margin-top:4px;">Aggiunti <strong>solo</strong> quando il
                                        campo di consenso qui sotto risulta spuntato. Se non configuri un campo di consenso,
                                        vengono applicati sempre. Lascia vuoto se questo form non raccoglie un consenso
                                        commerciale.</p>
                                </div>

                                <!-- Campo consenso commerciale -->
                                <div
                                    style="margin-bottom:24px;padding:16px;background:#f6f7f7;border-radius:3px;border:1px solid #e2e4e7;">
                                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;"
                                        for="suite-barrio-setting-mailchimp-optin-field">
                                        Campo consenso commerciale
                                        <span style="font-weight:400;color:#a7aaad;"> (opzionale)</span>
                                    </label>
                                    <p class="description" style="margin-bottom:10px;margin-top:0;">Il contatto viene
                                        <strong>sempre</strong> iscritto, spuntato o no: questo campo decide soltanto se
                                        applicare i <strong>tag con consenso commerciale</strong> impostati sopra. I
                                        <strong>tag sempre applicati</strong> non dipendono da questa scelta.</p>
                                    <select id="suite-barrio-setting-mailchimp-optin-field"
                                        style="width:100%;max-width:340px;padding:5px 8px;border:1px solid #c3c4c7;border-radius:3px;font-size:13px;">
                                        <option value="">— Nessuno (consenso implicito) —</option>
                                    </select>
                                    <p class="description" style="margin-top:6px;">Seleziona la <strong>checkbox</strong> di
                                        consenso commerciale del form. Lascia "Nessuno" sui form di sola iscrizione
                                        newsletter, dove il consenso è implicito nell'invio.</p>
                                </div>

                                <!-- Mapping campi form → merge tag Mailchimp -->
                                <div>
                                    <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;">
                                        Mappa campi del form su merge tag Mailchimp
                                        <span style="font-weight:400;color:#a7aaad;">(opzionale)</span>
                                    </label>
                                    <p class="description" style="margin-bottom:12px;">
                                        Collega i campi di questo form ai <strong>merge tag</strong> dell'Audience
                                        (es. <code>FNAME</code>, <code>LNAME</code>, <code>PHONE</code>).
                                        A sinistra lo <strong>slug del campo del form</strong>, a destra il
                                        <strong>merge tag Mailchimp</strong> esatto (maiuscolo).
                                    </p>
                                    <!-- Header colonne -->
                                    <div style="display:flex;gap:8px;margin-bottom:6px;padding:0 32px 0 0;">
                                        <span
                                            style="flex:1;font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase;letter-spacing:.4px;">Slug
                                            campo form</span>
                                        <span
                                            style="flex:1;font-size:11px;font-weight:600;color:#50575e;text-transform:uppercase;letter-spacing:.4px;">Merge
                                            tag Mailchimp</span>
                                        <span style="width:28px;"></span>
                                    </div>
                                    <div id="suite-barrio-mailchimp-field-map-rows"></div>
                                    <button type="button" id="suite-barrio-mailchimp-add-map-row"
                                        style="margin-top:8px;background:none;border:1px dashed #c3c4c7;border-radius:3px;padding:6px 14px;cursor:pointer;font-size:12px;color:#646970;width:100%;text-align:center;">
                                        + Aggiungi mappatura campo
                                    </button>
                                    <p class="description" style="margin-top:8px;">
                                        Esempi di merge tag: <code>FNAME</code>, <code>LNAME</code>, <code>PHONE</code>,
                                        <code>ADDRESS</code>.
                                        Controlla i nomi esatti in <em>Mailchimp → Audience → Settings → Merge fields</em>.
                                    </p>
                                </div>

                            </div><!-- #suite-barrio-mailchimp-fields-wrap -->
                        </div><!-- .suite-barrio-section-body -->
                    </div><!-- .suite-barrio-section-box Mailchimp per-form -->

                </div><!-- #suite-barrio-tab-settings -->

                <!-- TAB NOTIFICHE -->
                <div id="suite-barrio-tab-notifiche" style="display:none;">

                    <!-- Impostazioni globali email -->
                    <div class="email-style-settings suite-barrio-section-box" style="margin-bottom:20px;">
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

                    <!-- Testi fissi delle email: modificabili e traducibili per lingua -->
                    <div class="suite-barrio-section-box" style="margin-bottom:20px;">
                        <div class="suite-barrio-section-head">Testi email</div>
                        <div class="suite-barrio-section-body" style="padding:16px 22px;">
                            <p class="description" style="margin-top:0;">
                                Vuoto = testo predefinito (mostrato in grigio).<?php if ($ml_active): ?> Ogni lingua ha i suoi
                                testi: per tradurli scegli la lingua dal selettore del form.<?php endif; ?>
                            </p>
                            <table class="form-table" style="max-width:100%;">
                                <tbody>
                                    <?php
                                    $suite_barrio_email_defaults = suite_barrio_forms_email_strings($is_translation_mode ? $editor_lang : '');
                                    foreach (suite_barrio_forms_email_text_settings() as $suite_barrio_setting => [$suite_barrio_key, $suite_barrio_label, $suite_barrio_help]):
                                        $suite_barrio_input_id = 'suite-barrio-setting-' . $suite_barrio_setting; ?>
                                        <tr>
                                            <th style="width:220px;"><label for="<?php echo esc_attr($suite_barrio_input_id); ?>"><?php echo esc_html($suite_barrio_label); ?></label></th>
                                            <td>
                                                <?php if ('email_footer' === $suite_barrio_setting): ?>
                                                    <textarea id="<?php echo esc_attr($suite_barrio_input_id); ?>" class="large-text" rows="4"
                                                        placeholder="<?php echo esc_attr($suite_barrio_email_defaults[$suite_barrio_key]); ?>"></textarea>
                                                <?php else: ?>
                                                    <input type="text" id="<?php echo esc_attr($suite_barrio_input_id); ?>" class="large-text"
                                                        placeholder="<?php echo esc_attr($suite_barrio_email_defaults[$suite_barrio_key]); ?>">
                                                <?php endif; ?>
                                                <?php if ($suite_barrio_help): ?>
                                                    <p class="description"><?php echo esc_html($suite_barrio_help); ?></p>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div><!-- .suite-barrio-section-body -->
                    </div><!-- .suite-barrio-section-box testi email -->

                    <!-- Lista notifiche email -->
                    <div class="email-notifications-list">
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

        // ── Dati multilingua ────────────────────────────────────────────
        const ML_ACTIVE = <?php echo wp_json_encode($ml_active); ?>;
        // Etichette lingua per le registrazioni (come nella pagina Registrazioni degli editor)
        const ML_LANGUAGES = <?php echo wp_json_encode($ml_active && function_exists('suite_barrio_multilang_languages_with_flags') ? suite_barrio_multilang_languages_with_flags() : []); ?>;
        const ML_PRIMARY = <?php echo wp_json_encode($ml_primary); ?>;
        const IS_TRANSLATION = <?php echo wp_json_encode($is_translation_mode); ?>;
        const URL_EDIT_BASE = <?php echo wp_json_encode(admin_url('admin.php?page=suite-barrio-forms&tab=builder&edit_id=')); ?>;
        const CURRENT_EDITOR_LANG = <?php echo wp_json_encode($editor_lang); ?>;
        // Chiavi traducibili: arrivano da PHP così editor e salvataggio non possono divergere
        const I18N_SETTING_KEYS = <?php echo wp_json_encode(suite_barrio_forms_translatable_settings()); ?>;
        const I18N_NOTIF_KEYS = <?php echo wp_json_encode(suite_barrio_forms_translatable_notif()); ?>;
        // Testi fissi delle email (input id "suite-barrio-setting-<chiave>")
        const EMAIL_TEXT_KEYS = <?php echo wp_json_encode(array_keys(suite_barrio_forms_email_text_settings())); ?>;

        /* ── Select lingua nel builder: naviga alla versione tradotta ── */
        const langSelect = document.getElementById('suite-barrio-form-lang-select');
        if (langSelect && ML_ACTIVE && EDIT_ID) {
        langSelect.addEventListener('change', function () {
        const isPrimary = this.options[this.selectedIndex].dataset.isPrimary === '1';
        window.location.href = isPrimary
        ? URL_EDIT_BASE + EDIT_ID
        : URL_EDIT_BASE + EDIT_ID + '&lang=' + this.value;
        });
        }

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

        // In modalità traduzione: sovrascrive i soli testi con quelli tradotti.
        <?php if ($is_translation_mode):
            $trans_row = suite_barrio_forms_get_translation($edit_id, $editor_lang);
            if ($trans_row): ?>
                (function () {
                const fi18n = <?php echo wp_json_encode(json_decode($trans_row->fields_i18n, true) ?: []); ?>;
                const si18n = <?php echo wp_json_encode(json_decode($trans_row->settings_i18n, true) ?: []); ?>;
                fields = fields.map((f, i) => {
                const t = fi18n[i];
                if (!t) return f;
                const merged = { ...f };
                if (t.label) merged.label = t.label;
                if (t.placeholder) merged.placeholder = t.placeholder;
                if (t.error_message) merged.error_message = t.error_message;
                if (Array.isArray(t.options) && t.options.length) merged.options = t.options;
                return merged;
                });
                I18N_SETTING_KEYS.forEach(k => {
                if (si18n[k]) settings[k] = si18n[k];
                });
                if (Array.isArray(si18n.notifications)) {
                settings.notifications = (settings.notifications || []).map((n, ni) => {
                const nt = si18n.notifications[ni];
                if (!nt) return n;
                const merged = { ...n };
                I18N_NOTIF_KEYS.forEach(k => { if (nt[k]) merged[k] = nt[k]; });
                return merged;
                });
                }
                })();
            <?php endif;
        endif; ?>

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
        EMAIL_TEXT_KEYS.forEach(k => { if (el('suite-barrio-setting-' + k)) el('suite-barrio-setting-' + k).value = s[k] || ''; });
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

        // Mailchimp
        const mcEnabled = el('suite-barrio-setting-mailchimp-enabled');
        if (mcEnabled) {
        mcEnabled.checked = !!(s.mailchimp_enabled);
        toggleMailchimpFields(mcEnabled.checked);
        }
        if (el('suite-barrio-setting-mailchimp-list-id')) el('suite-barrio-setting-mailchimp-list-id').value =
        s.mailchimp_list_id || '';
        if (el('suite-barrio-setting-mailchimp-email-field')) el('suite-barrio-setting-mailchimp-email-field').value =
        s.mailchimp_email_field || '';
        if (el('suite-barrio-setting-mailchimp-tags')) el('suite-barrio-setting-mailchimp-tags').value =
        s.mailchimp_tags || '';
        if (el('suite-barrio-setting-mailchimp-consent-tags')) el('suite-barrio-setting-mailchimp-consent-tags').value =
        s.mailchimp_consent_tags || '';
        // Consenso commerciale: popola con i campi checkbox presenti nel form
        const mcOptinSel = el('suite-barrio-setting-mailchimp-optin-field');
        if (mcOptinSel) {
        while (mcOptinSel.options.length > 1) mcOptinSel.remove(1);
        fields.filter(f => f.type === 'checkbox').forEach(f => {
        const opt = document.createElement('option');
        opt.value = f.name || '';
        opt.textContent = (f.label || f.name || f.id) + ' [' + (f.name || '') + ']';
        mcOptinSel.appendChild(opt);
        });
        mcOptinSel.value = s.mailchimp_optin_field || '';
        }
        mailchimpFieldMap = Array.isArray(s.mailchimp_field_map) ? s.mailchimp_field_map.map(r => ({ form_field:
        r.form_field || '', mailchimp_field_name: r.mailchimp_field_name || '' })) : [];
        renderMailchimpMapRows();
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

        // ── Mailchimp: toggle + field mapping ───────────────────────────
        function toggleMailchimpFields(show) {
        const wrap = document.getElementById('suite-barrio-mailchimp-fields-wrap');
        if (wrap) wrap.style.display = show ? '' : 'none';
        }
        const mcCb = document.getElementById('suite-barrio-setting-mailchimp-enabled');
        if (mcCb) mcCb.addEventListener('change', () => toggleMailchimpFields(mcCb.checked));

        let mailchimpFieldMap = []; // [{form_field, mailchimp_field_name}]

        function renderMailchimpMapRows() {
        const container = document.getElementById('suite-barrio-mailchimp-field-map-rows');
        if (!container) return;
        container.innerHTML = '';
        mailchimpFieldMap.forEach((row, idx) => {
        const div = document.createElement('div');
        div.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:8px;';
        div.innerHTML = `
        <input type="text" placeholder="slug campo form (es. nome)" value="${esc(row.form_field || '')}"
            data-idx="${idx}" data-key="form_field"
            style="flex:1;padding:5px 8px;border:1px solid #c3c4c7;border-radius:3px;font-size:13px;">
        <span style="color:#a7aaad;font-size:18px;line-height:1;flex-shrink:0;">→</span>
        <input type="text" placeholder="es. FNAME" value="${esc(row.mailchimp_field_name || '')}" data-idx="${idx}"
            data-key="mailchimp_field_name"
            style="flex:1;padding:5px 8px;border:1px solid #c3c4c7;border-radius:3px;font-size:13px;">
        <button type="button" data-idx="${idx}"
            style="background:none;border:none;color:#d63638;cursor:pointer;font-size:18px;line-height:1;padding:0 4px;flex-shrink:0;"
            title="Rimuovi">×</button>`;
        div.querySelectorAll('input[data-key]').forEach(inp => {
        inp.addEventListener('input', e => {
        const i = parseInt(e.target.dataset.idx, 10);
        mailchimpFieldMap[i][e.target.dataset.key] = e.target.value;
        });
        });
        div.querySelector('button[data-idx]').addEventListener('click', e => {
        const i = parseInt(e.currentTarget.dataset.idx, 10);
        mailchimpFieldMap.splice(i, 1);
        renderMailchimpMapRows();
        });
        container.appendChild(div);
        });
        }

        const addMapBtnMc = document.getElementById('suite-barrio-mailchimp-add-map-row');
        if (addMapBtnMc) addMapBtnMc.addEventListener('click', () => {
        mailchimpFieldMap.push({ form_field: '', mailchimp_field_name: '' });
        renderMailchimpMapRows();
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
        ...Object.fromEntries(EMAIL_TEXT_KEYS.map(k => [k,
        el('suite-barrio-setting-' + k) ? el('suite-barrio-setting-' + k).value.trim() : (settings[k] || '')])),
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
        // Mailchimp
        mailchimp_enabled: el('suite-barrio-setting-mailchimp-enabled') ?
        el('suite-barrio-setting-mailchimp-enabled').checked : !!(settings.mailchimp_enabled),
        mailchimp_list_id: el('suite-barrio-setting-mailchimp-list-id') ?
        el('suite-barrio-setting-mailchimp-list-id').value.trim() : (settings.mailchimp_list_id || ''),
        mailchimp_email_field: el('suite-barrio-setting-mailchimp-email-field') ?
        el('suite-barrio-setting-mailchimp-email-field').value.trim() : (settings.mailchimp_email_field || 'email'),
        mailchimp_optin_field: el('suite-barrio-setting-mailchimp-optin-field') ?
        el('suite-barrio-setting-mailchimp-optin-field').value : (settings.mailchimp_optin_field || ''),
        mailchimp_tags: el('suite-barrio-setting-mailchimp-tags') ?
        el('suite-barrio-setting-mailchimp-tags').value.trim() : (settings.mailchimp_tags || ''),
        mailchimp_consent_tags: el('suite-barrio-setting-mailchimp-consent-tags') ?
        el('suite-barrio-setting-mailchimp-consent-tags').value.trim() : (settings.mailchimp_consent_tags || ''),
        mailchimp_field_map: mailchimpFieldMap.filter(r => r.form_field && r.mailchimp_field_name),
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
                                                ' title="Usa il campo &quot;' + esc(f.label || f.name) + '&quot; come destinatario">' +
                '⟵ ' + esc(f.label || f.name) +
                '</button>'
            ).join('') + '</div>'
        : '';

        // Pill variabili (per campi testo)
        const pills = realFields.length
        ? '<div class="suite-barrio-field-vars">' + realFields.map(f =>
            '<button type="button" class="suite-barrio-field-var-pill"' +
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

        // Modalità traduzione: si modificano solo i testi traducibili (I18N_NOTIF_KEYS).
        // Nome notifica, email e struttura si ereditano dalla lingua principale:
        // modificarli qui non avrebbe effetto, il salvataggio della traduzione li scarta.
        if (IS_TRANSLATION) {
        list.querySelectorAll('input[data-field], textarea[data-field]').forEach(inp => {
        if (inp.type !== 'hidden' && !I18N_NOTIF_KEYS.includes(inp.dataset.field)) inp.disabled = true;
        });
        list.querySelectorAll('.suite-barrio-notif-del-btn, .suite-barrio-email-field-pill').forEach(b => { b.disabled = true; });
        }

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
        // Testi fissi dell'anteprima nella lingua che si sta modificando
        lang: IS_TRANSLATION ? CURRENT_EDITOR_LANG : '',
        email_footer: document.getElementById('suite-barrio-setting-email-footer')?.value || '',
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

        // Le opzioni di radio/checkbox possono contenere markup (es. link privacy).
        // Nelle anteprime compatte mostriamo il solo testo: i tag escapati si
        // leggerebbero come "&lt;a href…&gt;" e sembrerebbero un errore.
        function escOptText(s) {
        const d = document.createElement('div');
        d.innerHTML = String(s || '');
        return esc(d.textContent || '');
        }

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
            // Ricostruisce un select "campo opt-in" dalla lista di checkbox del form.
            // Usato sia da MailUp che da Mailchimp: senza il refresh il select resta
            // fermo ai campi esistenti al caricamento della pagina.
            function refreshOptinSelect(selectId) {
            const sel = document.getElementById(selectId);
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

            function refreshMailupOptinSelect() {
            refreshOptinSelect('suite-barrio-setting-mailup-optin-field');
            refreshOptinSelect('suite-barrio-setting-mailchimp-optin-field');
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
                        class="suite-barrio-preview-radio"></span>${escOptText(o)}</label>`).join('')}</div>`;
            } else if (f.type === 'checkbox') {
            const opts = (f.options || []).slice(0, 3);
            preview = `<div class="suite-barrio-preview-choices">${opts.map(o => `<label
                    class="suite-barrio-preview-choice"><span
                        class="suite-barrio-preview-checkbox"></span>${escOptText(o)}</label>`).join('')}</div>`;
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
                <span class="suite-barrio-field-card-label">${esc(f.label)}${f.required ? ' <span style="color:#d63638;font-size:10px;">*</span>' : ''}</span>
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
                // In traduzione lo slug NON va ricalcolato: è la chiave con cui
                // la traduzione si aggancia al campo originale.
                if (!IS_TRANSLATION) {
                const newName = slugify(newLabel);
                updateField(f.id, 'name', newName);
                if (nameEl) nameEl.value = newName;
                }
                });
                }
                // Binding name: modificabile manualmente (non in traduzione)
                if (!IS_TRANSLATION) bi('prop-name', 'name');

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
                            ${rulesHtml || '<p style="font-size:11px;color:#a7aaad;margin:4px 0 8px;">Nessuna regola. Aggiungine una.</p>'}
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

                    // ── Modalità traduzione: salva solo i testi tradotti ──
                    if (IS_TRANSLATION && ML_ACTIVE && EDIT_ID) {
                    saveBtn.disabled = true;
                    saveBtn.textContent = 'Salvataggio…';

                    const fieldsI18n = fields.map(f => ({
                    label: f.label || '',
                    placeholder: f.placeholder || '',
                    error_message: f.error_message || '',
                    options: Array.isArray(f.options) ? f.options : [],
                    }));

                    const st = readSettings();
                    const settingsI18n = { notifications: [] };
                    I18N_SETTING_KEYS.forEach(k => { settingsI18n[k] = st[k] || ''; });
                    settingsI18n.notifications = (st.notifications || []).map(n => {
                    const nt = {};
                    I18N_NOTIF_KEYS.forEach(k => { nt[k] = n[k] || ''; });
                    return nt;
                    });

                    try {
                    const res = await fetch(AJAX, {
                    method: 'POST',
                    body: new URLSearchParams({
                    action: 'suite_barrio_forms_save_translation',
                    nonce: NONCE,
                    form_id: String(EDIT_ID),
                    lang: CURRENT_EDITOR_LANG,
                    fields_i18n: JSON.stringify(fieldsI18n),
                    settings_i18n: JSON.stringify(settingsI18n),
                    })
                    });
                    const data = await res.json();
                    if (data.success) toast('✓ Traduzione salvata!');
                    else toast(data.data || 'Errore durante il salvataggio.', 'error');
                    } catch (e) {
                    toast('Errore di rete.', 'error');
                    } finally {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Salva traduzione';
                    }
                    return;
                    }

                    // ── Modalità normale (form in lingua principale) ──
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
                    ML_ACTIVE && sub.lang ? `<span>Lingua: <strong>${esc(ML_LANGUAGES[sub.lang] || sub.lang.toUpperCase())}</strong></span>` : '',
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
                    let thead = '<tr><th>#</th>';
                        listFields.forEach(f => { thead += `<th>${esc(f.label || f.name)}</th>`; });
                        if (ML_ACTIVE) thead += '<th>Lingua</th>';
                        thead += '<th>Data</th><th></th></tr>';

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
                        if (ML_ACTIVE) {
                        // Registrazioni precedenti al multilingua: lingua non salvata
                        const ll = sub.lang ? (ML_LANGUAGES[sub.lang] || sub.lang.toUpperCase()) : '—';
                        tbody += `<td><span style="font-size:11px;background:#f0f0f1;padding:1px 6px;border-radius:2px;font-weight:600;">${esc(ll)}</span></td>`;
                        }
                        tbody += `<td><span class="suite-barrio-reg-date">${esc(sub.created_at)}</span></td>`;
                        tbody += `<td style="white-space:nowrap;"><button
                                class="suite-barrio-btn suite-barrio-btn-small suite-barrio-btn-danger suite-barrio-btn-sm suite-barrio-btn-reg-del"
                                data-id="${sub.id}" onclick="event.stopPropagation()">Elimina</button></td>`;
                        tbody += '</tr>';
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

                    /* ── Modalità traduzione: blocca ciò che non è testo ──
                       Il CSS dà il feedback visivo, qui si disabilita davvero:
                       il solo pointer-events non impedisce tastiera e autofill. */
                    if (IS_TRANSLATION) {

                    // Le card non si riordinano né si eliminano.
                    function lockCardDrag() {
                    document.querySelectorAll('#suite-barrio-drop-zone .suite-barrio-field-card').forEach(function (c) {
                    c.draggable = false;
                    c.removeAttribute('draggable');
                    });
                    }
                    const _origRenderCanvas = renderCanvas;
                    renderCanvas = function () {
                    _origRenderCanvas.apply(this, arguments);
                    lockCardDrag();
                    };
                    lockCardDrag();

                    // Il pannello proprietà viene riscritto a ogni click su una card:
                    // riapplichiamo i blocchi a ogni rigenerazione.
                    const _propsInner = document.getElementById('suite-barrio-props-inner');
                    if (_propsInner) {
                    const lockProps = function () {
                    ['prop-name', 'prop-min', 'prop-max', 'prop-format', 'prop-required'].forEach(function (id) {
                    const el = document.getElementById(id);
                    if (el) el.disabled = true;
                    });
                    const cond = _propsInner.querySelector('.suite-barrio-cond-section');
                    if (cond) cond.querySelectorAll('input,select,button,textarea').forEach(function (el) { el.disabled = true; });
                    };
                    lockProps();
                    new MutationObserver(lockProps).observe(_propsInner, { childList: true });
                    }

                    // Impostazioni non traducibili: la configurazione si eredita.
                    document.querySelectorAll('.suite-barrio-ast-btn, .suite-barrio-msg-mode-btn').forEach(function (b) { b.disabled = true; });
                    ['suite-barrio-setting-display-page-info',
                    'suite-barrio-setting-mailup-enabled',
                    'suite-barrio-setting-mailup-email-field',
                    'suite-barrio-setting-mailup-optin-field',
                    'suite-barrio-mailup-add-map-row',
                    'suite-barrio-setting-mailchimp-enabled',
                    'suite-barrio-setting-mailchimp-email-field',
                    'suite-barrio-setting-mailchimp-optin-field',
                    'suite-barrio-mailchimp-add-map-row',
                    'suite-barrio-btn-add-notification',
                    // Stile email: si eredita, la traduzione salva solo il testo footer
                    'suite-barrio-setting-email-bg-color',
                    'suite-barrio-setting-email-bg-color-hex',
                    'suite-barrio-setting-email-header-color',
                    'suite-barrio-setting-email-header-color-hex',
                    'suite-barrio-setting-email-logo-url',
                    'suite-barrio-btn-media-logo',
                    'suite-barrio-btn-remove-logo',
                    'suite-barrio-setting-domain-email'].forEach(function (id) {
                    const el = document.getElementById(id);
                    if (el) el.disabled = true;
                    });
                    }
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
    // Editor: voce di primo livello nel menu di WordPress.
    if (!current_user_can('manage_options') && current_user_can('edit_others_posts')) {
        add_menu_page(
            'Registrazioni Form — Suite Barrio',
            'Registrazioni',
            'edit_others_posts',
            'suite-barrio-submissions',
            'suite_barrio_forms_submissions_page',
            'dashicons-list-view',
            81
        );
        return;
    }

    // Admin: pagina registrata ma nascosta dal menu (vedono già tutto dalla
    // pagina Forms); resta raggiungibile via link diretto dalla lista form.
    if (current_user_can('manage_options')) {
        add_submenu_page(
            'suite-barrio',
            'Registrazioni Form — Suite Barrio',
            'Registrazioni',
            'manage_options',
            'suite-barrio-submissions',
            'suite_barrio_forms_submissions_page'
        );
    }
}, 12);

/* ─────────────────────────────────────────
   ENQUEUE: asset della pagina Registrazioni (solo registrazione).
   Il CSS/JS effettivo viene agganciato con wp_add_inline_style/script()
   dentro suite_barrio_forms_submissions_page().
   ───────────────────────────────────────── */
add_action('admin_enqueue_scripts', function ($hook) {
    // L'hook cambia in base a come è registrata la voce: primo livello per gli
    // editor, sottomenu di Suite Barrio per gli admin.
    if (!in_array($hook, ['toplevel_page_suite-barrio-submissions', 'suite-barrio_page_suite-barrio-submissions'], true))
        return;
    wp_register_style('suite-barrio-forms-submissions', false, [], SUITE_BARRIO_FORMS_VERSION);
    wp_register_script('suite-barrio-forms-submissions', false, [], SUITE_BARRIO_FORMS_VERSION, true);
});

// Nasconde la voce "Registrazioni" dal menu degli admin: la pagina esiste (serve
// il link diretto dalla lista form) ma non deve comparire due volte nel menu.
add_action('admin_enqueue_scripts', function () {
    if (current_user_can('manage_options')) {
        wp_add_inline_style('suite-barrio-admin', '#adminmenu a[href="admin.php?page=suite-barrio-submissions"]{display:none!important}');
    }
});

/* ─────────────────────────────────────────
   PAGINA READ-ONLY: lista form → submissions → dettaglio
   ───────────────────────────────────────── */
function suite_barrio_forms_submissions_page()
{
    if (!current_user_can('edit_others_posts') && !current_user_can('manage_options')) {
        wp_die('Non hai i permessi per accedere a questa pagina.');
    }

    $nonce = wp_create_nonce('suite_barrio_forms_nonce');
    $forms = suite_barrio_forms_get_all();
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura di sola visualizzazione/navigazione, nessun nonce esistente per questa azione
    $view_id = isset($_GET['view_id']) ? intval($_GET['view_id']) : 0;
    $view_form = $view_id ? suite_barrio_forms_get($view_id) : null;

    $ml_active = function_exists('suite_barrio_multilang_is_enabled') && suite_barrio_multilang_is_enabled();
    $ml_languages = $ml_active && function_exists('suite_barrio_multilang_languages_with_flags') ? suite_barrio_multilang_languages_with_flags() : [];
    ?>
<?php ob_start(); ?>
    #suite-barrio-subs-wrap {
        max-width: 1200px
    }

    .suite-barrio-subs-toolbar {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 14px;
        flex-wrap: wrap
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
        animation: suite-barrio-spin .6s linear infinite
    }

    @keyframes suite-barrio-spin {
        to {
            transform: rotate(360deg)
        }
    }

    .suite-barrio-subs-reg-table-wrap {
        overflow-x: auto
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

    .suite-barrio-subs-reg-table tr:last-child td {
        border-bottom: none
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

    .suite-barrio-check-on {
        color: #00a32a;
        font-weight: 600
    }

    .suite-barrio-check-off {
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

    /* Colonne picker */
    .suite-barrio-col-picker {
        position: relative
    }

    .suite-barrio-col-picker-panel {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        z-index: 99;
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        box-shadow: 0 3px 10px rgba(0, 0, 0, .1);
        padding: 10px 14px;
        min-width: 200px
    }

    .suite-barrio-col-picker-panel.open {
        display: block
    }

    .suite-barrio-col-picker-panel label {
        display: flex;
        align-items: center;
        gap: 7px;
        padding: 4px 0;
        font-size: 13px;
        cursor: pointer;
        white-space: nowrap
    }

    /* Bulk */
    .suite-barrio-subs-reg-table th:first-child,
    .suite-barrio-subs-reg-table td:first-child {
        width: 32px;
        padding-right: 4px
    }

    .suite-barrio-bulk-bar {
        display: none;
        align-items: center;
        gap: 10px;
        padding: 8px 12px;
        background: #f0f6fc;
        border: 1px solid #c6d9ef;
        border-radius: 3px;
        margin-bottom: 10px;
        font-size: 13px
    }

    .suite-barrio-bulk-bar.visible {
        display: flex
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

    .suite-barrio-subs-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px
    }

    .suite-barrio-subs-table th {
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

    .suite-barrio-subs-table td {
        padding: 10px 14px;
        border-bottom: 1px solid #f0f0f1;
        vertical-align: middle
    }

    .suite-barrio-subs-table tr:last-child td {
        border-bottom: none
    }

    .suite-barrio-subs-table tr:hover td {
        background: #fafafa
    }

    .suite-barrio-subs-table-wrap {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 3px;
        overflow: hidden
    }
<?php
    $suite_barrio_subs_css = ob_get_clean();
    wp_enqueue_style('suite-barrio-forms-submissions');
    wp_add_inline_style('suite-barrio-forms-submissions', $suite_barrio_subs_css);
    wp_enqueue_script('suite-barrio-forms-submissions');
    ?>

<div class="wrap" id="suite-barrio-subs-wrap">
    <h1 class="wp-heading-inline">
        <span class="dashicons dashicons-list-view"
            style="font-size:22px;width:22px;height:22px;vertical-align:middle;margin-right:6px;color:#2271b1;"></span>
        Registrazioni Form
    </h1>
    <hr class="wp-header-end" style="margin-bottom:20px;">

    <?php if ($view_form): ?>
    <!-- ═══ REGISTRAZIONI DI UN FORM ═══ -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
        <a href="<?php echo esc_url(admin_url('admin.php?page=suite-barrio-forms')); ?>" class="button">← Tutti i form</a>
        <strong style="font-size:14px;"><?php echo esc_html($view_form->title); ?></strong>
    </div>

    <!-- Toolbar: count + filtro lingua + colonne + bulk bar -->
    <div class="suite-barrio-subs-toolbar">
        <span class="suite-barrio-subs-reg-count" id="suite-barrio-subs-count"></span>

        <?php if ($ml_active && !empty($ml_languages)): ?>
        <select id="suite-barrio-lang-filter" style="font-size:13px;">
            <option value="">— Tutte le lingue —</option>
            <?php foreach ($ml_languages as $lc => $ll): ?>
            <option value="<?php echo esc_attr($lc); ?>"><?php echo esc_html($ll); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <div class="suite-barrio-col-picker">
            <button type="button" class="button" id="suite-barrio-col-picker-btn">
                <span class="dashicons dashicons-visibility"
                    style="font-size:14px;width:14px;height:14px;vertical-align:middle;margin-right:4px;"></span>
                Colonne
            </button>
            <div class="suite-barrio-col-picker-panel" id="suite-barrio-col-picker-panel">
                <p style="font-size:11px;font-weight:700;text-transform:uppercase;color:#646970;margin:0 0 6px;">Colonne
                    visibili</p>
                <div id="suite-barrio-col-options"><!-- popolato via JS --></div>
            </div>
        </div>

        <button type="button" class="button button-small" id="suite-barrio-select-all-btn" style="display:none;">
            Seleziona tutto
        </button>
    </div>

    <div class="suite-barrio-bulk-bar" id="suite-barrio-bulk-bar">
        <span id="suite-barrio-bulk-count"></span>
        <button type="button" class="button" style="color:#d63638;border-color:#d63638;" id="suite-barrio-bulk-delete-btn">
            <span class="dashicons dashicons-trash"
                style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span>
            Elimina selezionati
        </button>
        <button type="button" class="button" id="suite-barrio-bulk-cancel-btn">Annulla</button>
    </div>

    <div id="suite-barrio-subs-content"><span class="suite-barrio-subs-spinner"></span> Caricamento…</div>

    <?php ob_start(); ?>
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
                return '<span class="suite-barrio-check-summary">' + on + '/' + total + '</span>';
            }
            function formatCheckVal(val) {
                if (typeof val !== 'string' || !/:\s*(true|false)(\s*\||$)/.test(val)) return null;
                return val.split('|').map(p => p.trim()).filter(Boolean).map(part => {
                    const m = part.match(/^(.+?):\s*(true|false)$/);
                    if (!m) return '';
                    const cls = m[2] === 'true' ? 'suite-barrio-check-on' : 'suite-barrio-check-off';
                    const icon = m[2] === 'true' ? '✔' : '✘';
                    return '<div class="' + cls + '">' + icon + ' ' + esc(m[1].trim()) + '</div>';
                }).join('');
            }

            // ── Carica submissions ──────────────────────────────────────────
            function loadSubmissions() {
                fetch(AJAX, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action: 'suite_barrio_forms_get_submissions', nonce: NONCE, form_id: FORM_ID })
                })
                    .then(r => r.json())
                    .then(res => {
                        if (!res.success) { document.getElementById('suite-barrio-subs-content').innerHTML = '<p>' + esc(res.data || 'Errore') + '</p>'; return; }
                        subsData = res.data.subs || [];
                        formFields = (res.data.fields || []).filter(f => f.type !== 'submit' && f.type !== 'page_break');
                        // Colonne default: primi 2 campi
                        visibleCols = formFields.slice(0, 2).map(f => f.name);
                        buildColPicker();
                        applyFilter();
                    })
                    .catch(() => { document.getElementById('suite-barrio-subs-content').innerHTML = '<p>Errore di rete.</p>'; });
            }

            // ── Col picker ──────────────────────────────────────────────────
            function buildColPicker() {
                const wrap = document.getElementById('suite-barrio-col-options');
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

            document.getElementById('suite-barrio-col-picker-btn').addEventListener('click', function (e) {
                e.stopPropagation();
                document.getElementById('suite-barrio-col-picker-panel').classList.toggle('open');
            });
            document.addEventListener('click', function () {
                document.getElementById('suite-barrio-col-picker-panel').classList.remove('open');
            });
            document.getElementById('suite-barrio-col-picker-panel').addEventListener('click', function (e) { e.stopPropagation(); });

            // ── Filtro lingua ───────────────────────────────────────────────
            const langFilter = document.getElementById('suite-barrio-lang-filter');
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
                const content = document.getElementById('suite-barrio-subs-content');
                const countEl = document.getElementById('suite-barrio-subs-count');
                if (countEl) countEl.textContent = filteredData.length + ' registrazion' + (filteredData.length === 1 ? 'e' : 'i');

                const selectAllBtn = document.getElementById('suite-barrio-select-all-btn');
                if (selectAllBtn) selectAllBtn.style.display = filteredData.length ? '' : 'none';

                if (!filteredData.length) {
                    content.innerHTML = '<p style="color:#646970;">Nessuna registrazione.</p>';
                    return;
                }

                // Header
                let thead = '<tr><th><input type="checkbox" id="suite-barrio-check-all"></th><th>#</th>';
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
                    tbody += '<td onclick="event.stopPropagation()"><input type="checkbox" class="suite-barrio-sub-check" value="' + sub.id + '"' + (checked ? ' checked' : '') + '></td>';
                    tbody += '<td>' + sub.id + '</td>';

                    const d = sub.data || {};
                    visibleCols.forEach(name => {
                        const cell = d[name];
                        let val = cell ? cell.value : null;
                        if (Array.isArray(val)) val = val.join(', ');
                        const empty = val === null || val === undefined || val === '';
                        const summary = !empty ? formatCheckSummary(String(val)) : null;
                        tbody += empty
                            ? '<td class="suite-barrio-subs-reg-cell-empty">—</td>'
                            : '<td title="' + esc(String(val)) + '">' + (summary || esc(String(val))) + '</td>';
                    });

                    if (ML_ACTIVE) {
                        const lc = sub.lang || '';
                        const ll = lc ? (ML_LANGUAGES[lc] || lc.toUpperCase()) : '—';
                        tbody += '<td><span style="font-size:11px;background:#f0f0f1;padding:1px 6px;border-radius:2px;font-weight:600;">' + esc(ll) + '</span></td>';
                    }

                    tbody += '<td><span class="suite-barrio-subs-reg-date">' + esc(sub.created_at) + '</span></td>';
                    tbody += '<td onclick="event.stopPropagation()" style="width:32px;padding:4px 8px;">'
                        + '<button type="button" class="button button-small suite-barrio-del-single" data-id="' + sub.id + '" title="Elimina" style="color:#d63638;border-color:#d63638;padding:2px 6px;">'
                        + '<span class="dashicons dashicons-trash" style="font-size:12px;width:12px;height:12px;"></span>'
                        + '</button></td>';
                    tbody += '</tr>';
                });

                content.innerHTML = '<div class="suite-barrio-subs-reg-table-wrap"><table class="suite-barrio-subs-reg-table"><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table></div>';

                // Check-all
                const checkAll = document.getElementById('suite-barrio-check-all');
                if (checkAll) {
                    checkAll.addEventListener('change', function () {
                        document.querySelectorAll('.suite-barrio-sub-check').forEach(cb => {
                            cb.checked = this.checked;
                            const id = parseInt(cb.value);
                            if (this.checked) selectedIds.add(id); else selectedIds.delete(id);
                        });
                        updateBulkBar();
                    });
                    checkAll.checked = filteredData.length > 0 && filteredData.every(s => selectedIds.has(s.id));
                }

                // Singole checkbox
                document.querySelectorAll('.suite-barrio-sub-check').forEach(cb => {
                    cb.addEventListener('change', function () {
                        const id = parseInt(this.value);
                        if (this.checked) selectedIds.add(id); else selectedIds.delete(id);
                        updateBulkBar();
                        const allCb = document.getElementById('suite-barrio-check-all');
                        if (allCb) allCb.checked = filteredData.every(s => selectedIds.has(s.id));
                    });
                });

                // Click riga → dettaglio
                content.querySelectorAll('.suite-barrio-subs-reg-table tbody tr').forEach(row => {
                    row.addEventListener('click', () => {
                        const id = parseInt(row.dataset.id);
                        const idx = filteredData.findIndex(s => s.id === id);
                        if (idx !== -1) showDetail(idx);
                    });
                });

                // Delete singolo
                content.querySelectorAll('.suite-barrio-del-single').forEach(btn => {
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
                const bar = document.getElementById('suite-barrio-bulk-bar');
                const cnt = document.getElementById('suite-barrio-bulk-count');
                if (!bar) return;
                if (selectedIds.size > 0) {
                    bar.classList.add('visible');
                    cnt.textContent = selectedIds.size + ' selezionat' + (selectedIds.size === 1 ? 'a' : 'e');
                } else {
                    bar.classList.remove('visible');
                }
            }

            document.getElementById('suite-barrio-bulk-delete-btn').addEventListener('click', function () {
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

            document.getElementById('suite-barrio-bulk-cancel-btn').addEventListener('click', function () {
                selectedIds.clear();
                updateBulkBar();
                document.querySelectorAll('.suite-barrio-sub-check').forEach(cb => cb.checked = false);
                const allCb = document.getElementById('suite-barrio-check-all');
                if (allCb) allCb.checked = false;
            });

            // Seleziona tutto (anche filtrati)
            const selAllBtn = document.getElementById('suite-barrio-select-all-btn');
            if (selAllBtn) {
                selAllBtn.addEventListener('click', function () {
                    filteredData.forEach(s => selectedIds.add(s.id));
                    document.querySelectorAll('.suite-barrio-sub-check').forEach(cb => cb.checked = true);
                    const allCb = document.getElementById('suite-barrio-check-all');
                    if (allCb) allCb.checked = true;
                    updateBulkBar();
                });
            }

            function deleteSubmissions(ids, onSuccess) {
                fetch(AJAX, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'suite_barrio_forms_delete_submissions',
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
                const content = document.getElementById('suite-barrio-subs-content');

                let fieldsHtml = '';
                formFields.filter(f => f.type !== 'file').forEach(f => {
                    const key = f.name || '';
                    const cell = (sub.data || {})[key];
                    let val = cell ? cell.value : null;
                    if (Array.isArray(val)) val = val.join(', ');
                    const empty = val === null || val === undefined || val === '';
                    const checkHtml = !empty ? formatCheckVal(String(val)) : null;
                    const valHtml = checkHtml || (empty ? '—' : esc(String(val)));
                    fieldsHtml += '<div class="suite-barrio-subs-detail-row"><div class="suite-barrio-subs-detail-label">' + esc(f.label || f.name || '—') + '</div><div class="suite-barrio-subs-detail-value' + (empty ? ' suite-barrio-subs-detail-empty' : '') + '">' + valHtml + '</div></div>';
                });

                // Allegati
                const fileEntries = Object.entries(sub.data || {}).filter(([k, v]) => k.startsWith('_file_') && v && v.is_file);
                if (fileEntries.length) {
                    fieldsHtml += '<div style="padding:10px 20px;background:#f0f0f1;font-size:11px;font-weight:700;letter-spacing:.5px;color:#50575e;text-transform:uppercase;border-bottom:1px solid #e0e0e0;">📎 Allegati</div>';
                    fileEntries.forEach(([k, d]) => {
                        const fname = esc(d.value || '');
                        const furl = d.url ? '<a href="' + esc(d.url) + '" target="_blank" style="font-size:12px;margin-left:8px;">Scarica</a>' : '';
                        fieldsHtml += '<div class="suite-barrio-subs-detail-row"><div class="suite-barrio-subs-detail-label">📎</div><div class="suite-barrio-subs-detail-value">' + fname + furl + '</div></div>';
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
                    '<div class="suite-barrio-subs-detail-wrap">' +
                    '<div class="suite-barrio-subs-detail-header">' +
                    '<h3>Registrazione #' + sub.id + '</h3>' +
                    '<div style="display:flex;gap:8px;">' +
                    '<button type="button" class="button" id="suite-barrio-detail-back">← Lista</button>' +
                    '<button type="button" class="button" style="color:#d63638;border-color:#d63638;" id="suite-barrio-detail-del">🗑 Elimina</button>' +
                    '</div></div>' +
                    '<div class="suite-barrio-subs-detail-meta">' + metaItems.map(m => '<span>' + m + '</span>').join('') + '</div>' +
                    '<div class="suite-barrio-subs-detail-fields">' + fieldsHtml + '</div>' +
                    '</div>';

                document.getElementById('suite-barrio-detail-back').addEventListener('click', renderList);
                document.getElementById('suite-barrio-detail-del').addEventListener('click', function () {
                    if (!confirm('Eliminare la registrazione #' + sub.id + '?')) return;
                    deleteSubmissions([sub.id], () => {
                        subsData = subsData.filter(s => s.id !== sub.id);
                        applyFilter();
                    });
                });
            }

            loadSubmissions();
        })();
    <?php
    $suite_barrio_subs_js = ob_get_clean();
    wp_add_inline_script('suite-barrio-forms-submissions', $suite_barrio_subs_js, 'after');
    ?>

    <?php else: ?>
    <!-- ═══ ELENCO FORM ═══ -->
    <div class="suite-barrio-subs-table-wrap">
        <?php if (empty($forms)): ?>
        <p style="padding:24px;color:#646970;text-align:center;">Nessun form trovato.</p>
        <?php else: ?>
        <table class="suite-barrio-subs-table">
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
                    $subs_url = admin_url('admin.php?page=suite-barrio-submissions&view_id=' . $f->id);
                    ?>
                <tr>
                    <td style="color:#a7aaad;font-size:11px;"><?php echo intval($f->id); ?></td>
                    <td><strong><?php echo esc_html($f->title); ?></strong></td>
                    <td>
                        <span class="suite-barrio-sub-count <?php echo $f->submissions > 0 ? 'has-subs' : ''; ?>">
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
