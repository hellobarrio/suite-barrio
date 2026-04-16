<?php
/**
 * WP Barrio — Forms GraphQL
 *
 * Estende lo schema WPGraphQL con i tipi e le query per i form WP Barrio.
 * Viene caricato SOLO se WPGraphQL è attivo.
 *
 * Query disponibili:
 *   hbForm(id: Int)   → HBForm
 *   hbForms           → [HBFormListItem]
 *
 * Mutation disponibili:
 *   hbSubmitForm(input: { formId, fields: [HbFormFieldInput], pageUrl?, pageTitle?, pageReferrer? })
 *     → { success, message, submissionId, errors }
 *
 * @package WP_Barrio
 */

if (!defined('ABSPATH'))
    exit;

/* ─────────────────────────────────────────
   CORS — permette richieste GraphQL dal
   frontend Next.js su dominio esterno.

   Configura i domini consentiti tramite la
   costante WPB_GRAPHQL_ALLOWED_ORIGINS definita
   in wp-config.php, ad es.:

     define( 'WPB_GRAPHQL_ALLOWED_ORIGINS', 'https://www.miosito.it,https://staging.miosito.it' );

   Se la costante non è definita, l'header non
   viene aggiunto (ambiente non-headless).
   ───────────────────────────────────────── */
add_action('graphql_response_headers_to_send', function (array $headers): array {

    if (!defined('WPB_GRAPHQL_ALLOWED_ORIGINS'))
        return $headers;

    $allowed = array_map('trim', explode(',', WPB_GRAPHQL_ALLOWED_ORIGINS));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin && in_array($origin, $allowed, true)) {
        $headers['Access-Control-Allow-Origin'] = $origin;
        $headers['Access-Control-Allow-Methods'] = 'POST, GET, OPTIONS';
        $headers['Access-Control-Allow-Headers'] = 'Content-Type, Authorization';
        $headers['Access-Control-Allow-Credentials'] = 'true';
        $headers['Vary'] = 'Origin';
    }

    return $headers;
});

// Gestisce il preflight OPTIONS inviato dal browser prima della mutation
add_action('init', function () {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'OPTIONS')
        return;
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/graphql') === false)
        return;
    if (!defined('WPB_GRAPHQL_ALLOWED_ORIGINS'))
        return;

    $allowed = array_map('trim', explode(',', WPB_GRAPHQL_ALLOWED_ORIGINS));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if ($origin && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
        exit(0);
    }
});

/* ─────────────────────────────────────────
   CONTROLLO DISPONIBILITÀ WPGRAPHQL
   ───────────────────────────────────────── */
function wpb_graphql_is_available(): bool
{
    return function_exists('register_graphql_field')
        && function_exists('register_graphql_object_type');
}

/* ─────────────────────────────────────────
   ADMIN NOTICE — WPGraphQL mancante
   ───────────────────────────────────────── */
add_action('admin_notices', function () {
    if (wpb_graphql_is_available())
        return;

    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'wp-barrio') === false)
        return;
    ?>
    <div class="notice notice-warning">
        <p>
            <strong>WP Barrio — Forms GraphQL:</strong>
            per esporre i form tramite GraphQL è necessario installare e attivare il plugin
            <a href="<?php echo esc_url(admin_url('plugin-install.php?s=wpgraphql&tab=search&type=term')); ?>">
                WPGraphQL
            </a>.
        </p>
    </div>
    <?php
});

/* ─────────────────────────────────────────
   REGISTRAZIONE SCHEMA
   ───────────────────────────────────────── */
add_action('graphql_register_types', 'wpb_graphql_register_schema');

function wpb_graphql_register_schema(): void
{

    // ── 1. HBFormOption ──────────────────────────────────────────────────────
    register_graphql_object_type('HBFormOption', [
        'description' => 'Opzione di un campo select, radio o checkbox.',
        'fields' => [
            'value' => ['type' => 'String', 'description' => 'Valore dell\'opzione.'],
            'label' => ['type' => 'String', 'description' => 'Etichetta visibile all\'utente.'],
        ],
    ]);

    // ── 2. HBShowFieldCondition ───────────────────────────────────────────────
    register_graphql_object_type('HBShowFieldCondition', [
        'description' => 'Condizione per la visibilità condizionale di un campo.',
        'fields' => [
            'value' => ['type' => 'String', 'description' => 'Valore atteso.'],
            'operator' => ['type' => 'String', 'description' => 'Operatore di confronto (=, !=, >, <, >=, <=).'],
        ],
    ]);

    // ── 3. HBShowFieldRule ────────────────────────────────────────────────────
    register_graphql_object_type('HBShowFieldRule', [
        'description' => 'Regola di visibilità condizionale.',
        'fields' => [
            'targets' => ['type' => ['list_of' => 'String'], 'description' => 'Names dei campi da mostrare.'],
            'fields' => ['type' => ['list_of' => 'String'], 'description' => 'Alias di targets (retrocompatibilità).'],
            'operator' => ['type' => 'String', 'description' => 'Operatore: =, !=, contains, empty, notempty.'],
            'value' => ['type' => 'String', 'description' => 'Valore atteso.'],
            'relation' => ['type' => 'String', 'description' => 'Relazione logica: AND oppure OR.'],
            'conditions' => ['type' => ['list_of' => 'HBShowFieldCondition'], 'description' => 'Condizioni (formato esteso).'],
        ],
    ]);

    // ── 4. HBFormField ────────────────────────────────────────────────────────
    register_graphql_object_type('HBFormField', [
        'description' => 'Singolo campo di un form HB.',
        'fields' => [
            'id' => ['type' => 'String', 'description' => 'ID interno univoco del campo.'],
            'name' => ['type' => 'String', 'description' => 'Attributo name HTML (coincide con id).'],
            'type' => ['type' => 'String', 'description' => 'Tipo: text, email, textarea, tel, number, datepicker, select, radio, checkbox, file.'],
            'label' => ['type' => 'String', 'description' => 'Etichetta visibile.'],
            'placeholder' => ['type' => 'String', 'description' => 'Testo segnaposto.'],
            'errorMessage' => ['type' => 'String', 'description' => 'Messaggio di errore personalizzato.'],
            'required' => ['type' => 'Boolean', 'description' => 'Campo obbligatorio.'],
            'pattern' => ['type' => 'String', 'description' => 'Regex di validazione (HTML pattern).'],
            'min' => ['type' => 'String', 'description' => 'Valore minimo (number).'],
            'max' => ['type' => 'String', 'description' => 'Valore massimo (number).'],
            'format' => ['type' => 'String', 'description' => 'Formato data per il datepicker (es. dd/MM/y).'],
            'options' => ['type' => ['list_of' => 'HBFormOption'], 'description' => 'Opzioni per select, radio, checkbox.'],
            'showFields' => ['type' => ['list_of' => 'HBShowFieldRule'], 'description' => 'Regole di visibilità condizionale.'],
        ],
    ]);

    // ── 5. HBFormSettings ─────────────────────────────────────────────────────
    register_graphql_object_type('HBFormSettings', [
        'description' => 'Impostazioni generali di un form HB.',
        'fields' => [
            'submitText' => ['type' => 'String', 'description' => 'Testo del pulsante di invio.'],
            'successMessage' => ['type' => 'String', 'description' => 'Messaggio dopo l\'invio.'],
            'errorMessage' => ['type' => 'String', 'description' => 'Messaggio di errore generico.'],
            'afterSubmit' => ['type' => 'String', 'description' => 'Comportamento dopo l\'invio: "message" o "redirect".'],
            'redirectUrl' => ['type' => 'String', 'description' => 'URL di redirect (se afterSubmit = redirect).'],
            'displayPageInfo' => ['type' => 'Boolean', 'description' => 'Se true, al submit vengono registrati URL, titolo e referrer della pagina corrente.'],
        ],
    ]);

    // ── 6. HBForm ─────────────────────────────────────────────────────────────
    register_graphql_object_type('HBForm', [
        'description' => 'Form completo con campi e impostazioni.',
        'fields' => [
            'id' => ['type' => ['non_null' => 'Int'], 'description' => 'ID numerico del form.'],
            'title' => ['type' => 'String', 'description' => 'Titolo del form.'],
            'createdAt' => ['type' => 'String', 'description' => 'Data di creazione.'],
            'updatedAt' => ['type' => 'String', 'description' => 'Data di ultima modifica.'],
            'fields' => ['type' => ['list_of' => 'HBFormField'], 'description' => 'Campi del form.'],
            'settings' => ['type' => 'HBFormSettings', 'description' => 'Impostazioni del form.'],
        ],
    ]);

    // ── 7. HBFormListItem ─────────────────────────────────────────────────────
    register_graphql_object_type('HBFormListItem', [
        'description' => 'Voce leggera della lista form (senza i dettagli dei campi).',
        'fields' => [
            'id' => ['type' => ['non_null' => 'Int'], 'description' => 'ID del form.'],
            'title' => ['type' => 'String', 'description' => 'Titolo del form.'],
            'submissions' => ['type' => 'Int', 'description' => 'Numero di risposte ricevute.'],
            'createdAt' => ['type' => 'String', 'description' => 'Data di creazione.'],
        ],
    ]);

    // ── 8. HbFormFieldInput (mutation input) ─────────────────────────────────
    // Nome in HbFormFieldInput (non HBFormFieldInput) per rispettare la
    // convenzione di naming di WPGraphQL che converte camelCase → PascalCase
    register_graphql_input_type('HbFormFieldInput', [
        'description' => 'Valore di un singolo campo del form inviato dal frontend.',
        'fields' => [
            'name' => [
                'type' => ['non_null' => 'String'],
                'description' => 'Name del campo (corrisponde all\'attributo name dell\'input HTML).',
            ],
            'value' => [
                'type' => 'String',
                'description' => 'Valore compilato. Per checkbox/radio multipli, concatena i valori con virgola.',
            ],
        ],
    ]);

    // Nota: HbSubmitFormPayload viene generato automaticamente da WPGraphQL
    // a partire da outputFields — non serve registrarlo manualmente.

    // ── 10. RootQuery: hbForm ──────────────────────────────────────────────────
    register_graphql_field('RootQuery', 'hbForm', [
        'type' => 'HBForm',
        'description' => 'Restituisce un form HB per ID.',
        'args' => [
            'id' => ['type' => 'Int', 'description' => 'ID numerico del form.'],
        ],
        'resolve' => function ($root, array $args): ?array {
            if (empty($args['id']))
                return null;
            $form = wpb_forms_get(intval($args['id']));
            if (!$form)
                return null;
            return wpb_graphql_format_form($form);
        },
    ]);

    // ── 11. RootQuery: hbForms ─────────────────────────────────────────────────
    register_graphql_field('RootQuery', 'hbForms', [
        'type' => ['list_of' => 'HBFormListItem'],
        'description' => 'Restituisce la lista di tutti i form HB.',
        'resolve' => function (): array {
            global $wpdb;

            $rows = $wpdb->get_results(
                "SELECT id, title, created_at,
                        (SELECT COUNT(*) FROM {$wpdb->prefix}wpb_form_submissions s
                         WHERE s.form_id = f.id) AS submissions
                 FROM {$wpdb->prefix}wpb_forms f
                 ORDER BY id DESC"
            );

            if (!$rows)
                return [];

            return array_map(fn($r) => [
                'id' => (int) $r->id,
                'title' => $r->title,
                'submissions' => (int) $r->submissions,
                'createdAt' => $r->created_at,
            ], $rows);
        },
    ]);

    // ── 12. Mutation: hbSubmitForm ─────────────────────────────────────────────
    register_graphql_mutation('hbSubmitForm', [
        'description' => 'Invia la compilazione di un form HB dal frontend.',
        'inputFields' => [
            'formId' => [
                'type' => ['non_null' => 'Int'],
                'description' => 'ID del form da compilare.',
            ],
            'fields' => [
                'type' => ['non_null' => ['list_of' => 'HbFormFieldInput']],
                'description' => 'Lista dei campi compilati.',
            ],
            // Informazioni pagina (opzionali — usate se displayPageInfo è attivo)
            'pageUrl' => ['type' => 'String', 'description' => 'URL della pagina da cui è stato inviato il form.'],
            'pageTitle' => ['type' => 'String', 'description' => 'Titolo della pagina corrente.'],
            'pageReferrer' => ['type' => 'String', 'description' => 'Referrer della pagina corrente.'],
        ],
        'outputFields' => [
            'success' => ['type' => ['non_null' => 'Boolean'], 'description' => 'true se l\'invio è riuscito.'],
            'message' => ['type' => 'String', 'description' => 'Messaggio di successo o di errore.'],
            'submissionId' => ['type' => 'Int', 'description' => 'ID della registrazione salvata.'],
            'errors' => ['type' => ['list_of' => 'String'], 'description' => 'Errori di validazione.'],
            'debug' => ['type' => ['list_of' => 'String'], 'description' => 'Log diagnostici (solo sviluppo).'],
        ],
        'mutateAndGetPayload' => function (array $input): array {
            global $wpdb;

            try {

                $form_id = intval($input['formId'] ?? 0);
                if (!$form_id) {
                    return ['success' => false, 'message' => 'ID form non valido.', 'submissionId' => null, 'errors' => ['ID form non valido.']];
                }

                $form = wpb_forms_get($form_id);
                if (!$form) {
                    return ['success' => false, 'message' => 'Form non trovato.', 'submissionId' => null, 'errors' => ['Form non trovato.']];
                }

                $form_fields = json_decode($form->fields, true) ?: [];
                $settings = json_decode($form->settings, true) ?: [];

                // Costruisci mappa name → value dall'input GraphQL
                // Nota: sanitize_text_field (non sanitize_key) per preservare il case
                $input_map = [];
                foreach ($input['fields'] ?? [] as $field_input) {
                    $name = sanitize_text_field($field_input['name'] ?? '');
                    if ($name !== '') {
                        $input_map[$name] = sanitize_textarea_field($field_input['value'] ?? '');
                    }
                }

                // ── Validazione required ──────────────────────────────────────────
                $errors = [];
                foreach ($form_fields as $field) {
                    if (empty($field['required']))
                        continue;
                    $name = $field['name'] ?? '';
                    $val = trim($input_map[$name] ?? '');
                    if ($val === '') {
                        $errors[] = !empty($field['error_message'])
                            ? $field['error_message']
                            : 'Il campo "' . ($field['label'] ?? $name) . '" è obbligatorio.';
                    }
                }

                if (!empty($errors)) {
                    return [
                        'success' => false,
                        'message' => $errors[0],
                        'submissionId' => null,
                        'errors' => $errors,
                    ];
                }

                // ── Costruisci dati submission ────────────────────────────────────
                $submission_data = [];
                foreach ($form_fields as $field) {
                    $name = $field['name'] ?? '';
                    $val = $input_map[$name] ?? '';
                    $submission_data[$name] = [
                        'label' => $field['label'] ?? $name,
                        'value' => $val,
                    ];
                }

                // Aggiungi informazioni pagina se attivo
                if (!empty($settings['display_page_info'])) {
                    $submission_data['_page_url'] = [
                        'label' => 'URL pagina',
                        'value' => sanitize_url($input['pageUrl'] ?? ''),
                    ];
                    $submission_data['_page_title'] = [
                        'label' => 'Titolo pagina',
                        'value' => sanitize_text_field($input['pageTitle'] ?? ''),
                    ];
                    $submission_data['_page_referrer'] = [
                        'label' => 'Referrer',
                        'value' => sanitize_url($input['pageReferrer'] ?? ''),
                    ];
                }

                // ── Salva nel DB ──────────────────────────────────────────────────
                $ok = $wpdb->insert(
                    $wpdb->prefix . WPB_FORMS_SUB_TABLE,
                    [
                        'form_id' => $form_id,
                        'data' => wp_json_encode($submission_data),
                        'ip' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
                        'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    ],
                    ['%d', '%s', '%s', '%s']
                );

                if ($ok === false) {
                    return [
                        'success' => false,
                        'message' => 'Errore nel salvataggio.',
                        'submissionId' => null,
                        'errors' => ['Errore DB: ' . $wpdb->last_error],
                    ];
                }

                $submission_id = (int) $wpdb->insert_id;

                // ── Invia notifiche email ─────────────────────────────────────────
                // Usa la funzione condivisa con l'handler AJAX (shortcode front-end).
                // Viene eseguita qui, lato server WordPress, indipendentemente dal
                // frontend Next.js che ha inviato i dati tramite mutation GraphQL.
                $mail_log = wpb_forms_send_notifications($form, $settings, $submission_data);
                error_log('[WP Barrio][mutation] mail_log: ' . implode(' | ', $mail_log));

                return [
                    'success' => true,
                    'message' => $settings['success_message'] ?? 'Grazie! Il tuo messaggio è stato inviato.',
                    'submissionId' => $submission_id,
                    'errors' => [],
                    'debug' => $mail_log,
                ];

            } catch (\Throwable $e) {
                // Esponi l'errore reale invece di far crashare WPGraphQL con "Internal server error"
                return [
                    'success' => false,
                    'message' => 'Errore interno: ' . $e->getMessage(),
                    'submissionId' => null,
                    'errors' => [$e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()],
                ];
            }
        },
    ]);
}

/* ─────────────────────────────────────────
   HELPER: formatta un form per GraphQL
   ───────────────────────────────────────── */
function wpb_graphql_format_form(object $form): array
{
    $raw_fields = json_decode($form->fields, true) ?: [];
    $raw_settings = json_decode($form->settings, true) ?: [];

    return [
        'id' => (int) $form->id,
        'title' => $form->title,
        'createdAt' => $form->created_at,
        'updatedAt' => $form->updated_at ?? $form->created_at,
        'fields' => array_map('wpb_graphql_format_field', $raw_fields),
        'settings' => wpb_graphql_format_settings($raw_settings),
    ];
}

/* ─────────────────────────────────────────
   HELPER: formatta un singolo campo
   ───────────────────────────────────────── */
function wpb_graphql_format_field(array $f): array
{
    // Normalizza opzioni: stringa semplice → {value, label}
    $options = [];
    foreach ($f['options'] ?? [] as $opt) {
        if (is_array($opt) && isset($opt['value'])) {
            $options[] = [
                'value' => (string) $opt['value'],
                'label' => (string) ($opt['label'] ?? $opt['value']),
            ];
        } else {
            $options[] = ['value' => (string) $opt, 'label' => (string) $opt];
        }
    }

    // Normalizza showFields — supporta sia il vecchio formato {fields,conditions} che il nuovo {targets,operator,value}
    $show_fields = [];
    foreach ($f['showFields'] ?? [] as $rule) {
        // Nuovo formato semplificato
        if (isset($rule['targets'])) {
            $show_fields[] = [
                'targets' => (array) ($rule['targets'] ?? []),
                'fields' => (array) ($rule['targets'] ?? []), // alias
                'operator' => (string) ($rule['operator'] ?? '='),
                'value' => (string) ($rule['value'] ?? ''),
                'relation' => '',
                'conditions' => [],
            ];
        } else {
            // Vecchio formato esteso {fields, relation, conditions}
            $conditions = [];
            foreach ($rule['conditions'] ?? [] as $cond) {
                $conditions[] = [
                    'value' => (string) ($cond['value'] ?? ''),
                    'operator' => (string) ($cond['operator'] ?? '='),
                ];
            }
            $targets = (array) ($rule['fields'] ?? []);
            $show_fields[] = [
                'targets' => $targets,
                'fields' => $targets,
                'operator' => '',
                'value' => '',
                'relation' => (string) ($rule['relation'] ?? 'AND'),
                'conditions' => $conditions,
            ];
        }
    }

    // Formato default per datepicker
    $format = '';
    if (($f['type'] ?? '') === 'datepicker') {
        $format = (string) ($f['format'] ?? 'dd/MM/y');
    }

    return [
        'id' => (string) ($f['name'] ?? ''),  // alias per retrocompatibilità
        'name' => (string) ($f['name'] ?? ''),
        'type' => (string) ($f['type'] ?? 'text'),
        'label' => (string) ($f['label'] ?? ''),
        'placeholder' => (string) ($f['placeholder'] ?? ''),
        'errorMessage' => (string) ($f['error_message'] ?? ''),
        'required' => (bool) ($f['required'] ?? false),
        'pattern' => (string) ($f['pattern'] ?? ''),
        'min' => isset($f['min']) && $f['min'] !== '' ? (string) $f['min'] : null,
        'max' => isset($f['max']) && $f['max'] !== '' ? (string) $f['max'] : null,
        'format' => $format,
        'options' => $options,
        'showFields' => $show_fields,
    ];
}

/* ─────────────────────────────────────────
   HELPER: formatta le impostazioni del form
   ───────────────────────────────────────── */
function wpb_graphql_format_settings(array $s): array
{
    return [
        'submitText' => (string) ($s['submit_text'] ?? 'Invia'),
        'successMessage' => (string) ($s['success_message'] ?? ''),
        'errorMessage' => (string) ($s['error_message'] ?? ''),
        'afterSubmit' => (string) ($s['after_submit'] ?? 'message'),
        'redirectUrl' => (string) ($s['redirect_url'] ?? ''),
        'displayPageInfo' => (bool) ($s['display_page_info'] ?? false),
    ];
}
