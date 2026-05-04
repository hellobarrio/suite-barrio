<?php
/**
 * WP Barrio — GraphQL
 *
 * Estende lo schema WPGraphQL con tutti i tipi e le query del plugin.
 * Viene caricato sempre (indipendentemente dai moduli attivi) ma i singoli
 * resolver verificano internamente la disponibilità delle funzioni richieste.
 *
 * Query disponibili:
 *   hbForm(id: Int)   → HBForm
 *   hbForms           → [HBFormListItem]
 *   hbPopup           → HBPopup (popup attivo)
 *   hbOrari           → HBOrari (orari da Google Places)
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

    // ── 2. HBConditionalLogicRule ─────────────────────────────────────────────
    register_graphql_object_type('HBConditionalLogicRule', [
        'description' => 'Singola regola della logica condizionale (Gravity Forms-style).',
        'fields' => [
            'fieldName' => ['type' => 'String', 'description' => 'Name del campo sorgente da osservare.'],
            'operator'  => ['type' => 'String', 'description' => 'Operatore: =, !=, >, <, contains, starts_with, ends_with, empty, not_empty.'],
            'value'     => ['type' => 'String', 'description' => 'Valore atteso.'],
        ],
    ]);

    // ── 3. HBConditionalLogic ─────────────────────────────────────────────────
    register_graphql_object_type('HBConditionalLogic', [
        'description' => 'Logica condizionale di un campo (Gravity Forms-style): il campo stesso descrive quando mostrarsi o nascondersi.',
        'fields' => [
            'enabled'   => ['type' => 'Boolean', 'description' => 'Se true la logica condizionale è attiva.'],
            'action'    => ['type' => 'String',  'description' => 'Cosa fare quando le regole sono soddisfatte: "show" oppure "hide".'],
            'logicType' => ['type' => 'String',  'description' => 'Relazione tra le regole: "all" (AND) oppure "any" (OR).'],
            'rules'     => ['type' => ['list_of' => 'HBConditionalLogicRule'], 'description' => 'Lista delle regole da soddisfare.'],
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
            'options'          => ['type' => ['list_of' => 'HBFormOption'], 'description' => 'Opzioni per select, radio, checkbox.'],
            'conditionalLogic' => ['type' => 'HBConditionalLogic', 'description' => 'Logica condizionale del campo (Gravity Forms-style).'],
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

    // ── 5b. HBFormStep ──────────────────────────────────────────────────────────
    register_graphql_object_type('HBFormStep', [
        'description' => 'Uno step di un form multistep. Contiene i campi di quella schermata.',
        'fields' => [
            'fields' => ['type' => ['list_of' => 'HBFormField'], 'description' => 'Campi dello step.'],
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
            'fields' => ['type' => ['list_of' => 'HBFormField'], 'description' => 'Lista piatta di tutti i campi (esclusi page_break). Per il multistep usare steps.'],
            'steps' => ['type' => ['list_of' => 'HBFormStep'], 'description' => 'Campi raggruppati per step. Se non ci sono page_break, contiene un unico step con tutti i campi.'],
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

    // ── 9a. HBPopup ───────────────────────────────────────────────────────────
    // HBPopupImage usa il tipo nativo MediaItem di WPGraphQL per node,
    // così i fragment come MediaItemFragment funzionano senza errori.
    register_graphql_object_type('HBPopupImage', [
        'description' => 'Wrapper immagine del popup (compatibile con la connessione MediaItem di WPGraphQL).',
        'fields' => [
            'node' => ['type' => 'MediaItem', 'description' => 'Il MediaItem WordPress dell\'immagine.'],
        ],
    ]);

    register_graphql_object_type('HBPopupCta', [
        'description' => 'CTA (call to action) del popup.',
        'fields' => [
            'url'    => ['type' => 'String', 'description' => 'URL di destinazione.'],
            'title'  => ['type' => 'String', 'description' => 'Testo del pulsante.'],
            'target' => ['type' => 'String', 'description' => 'Target del link: _self o _blank.'],
        ],
    ]);

    register_graphql_object_type('HBPopup', [
        'description' => 'Popup attivo da mostrare sul frontend.',
        'fields' => [
            'id'      => ['type' => ['non_null' => 'Int'], 'description' => 'ID del popup.'],
            'title'   => ['type' => 'String', 'description' => 'Titolo del popup.'],
            'content' => ['type' => 'String', 'description' => 'Testo/HTML del popup.'],
            'link'    => ['type' => 'HBPopupCta', 'description' => 'CTA del popup (opzionale).'],
            'image'   => ['type' => 'HBPopupImage', 'description' => 'Immagine del popup (opzionale).'],
        ],
    ]);

    // ── 9a2. RootQuery: hbPopup ───────────────────────────────────────────────
    register_graphql_field('RootQuery', 'hbPopup', [
        'type'        => 'HBPopup',
        'description' => 'Restituisce il popup attivo, o null se nessuno è attivo.',
        'resolve'     => function (): ?array {
            if (!function_exists('wpb_popups_get_active'))
                return null;

            $popup = wpb_popups_get_active();
            if (!$popup)
                return null;

            // Costruisce il wrapper image solo se c'è un attachment valido.
            // Restituiamo l'ID del post: WPGraphQL risolve il MediaItem nativo
            // a partire da esso, rendendo i fragment come MediaItemFragment compatibili.
            $image    = null;
            $image_id = (int) ($popup->image_id ?? 0);
            if ($image_id > 0) {
                $post = get_post($image_id);
                if ($post && $post->post_type === 'attachment') {
                    $image = [
                        'node' => new \WPGraphQL\Model\Post($post),
                    ];
                }
            }

            return [
                'id'      => (int) $popup->id,
                'title'   => (string) $popup->title,
                'content' => (string) $popup->content,
                'link'    => !empty($popup->link) ? [
                    'url'    => (string) $popup->link,
                    'title'  => (string) ($popup->link_title ?? ''),
                    'target' => (string) ($popup->link_target ?? '_self'),
                ] : null,
                'image'   => $image,
            ];
        },
    ]);

    // ── 9b. HBOrariGiorno ─────────────────────────────────────────────────────
    register_graphql_object_type('HBOrariGiorno', [
        'description' => 'Orari di apertura per un singolo giorno della settimana.',
        'fields' => [
            'giorno' => ['type' => 'String', 'description' => 'Nome del giorno (es. Lunedì).'],
            'slots'  => ['type' => ['list_of' => 'String'], 'description' => 'Fasce orarie (es. ["09:00 - 13:00", "15:00 - 19:00"]). Array vuoto = chiuso.'],
            'chiuso' => ['type' => 'Boolean', 'description' => 'true se il locale è chiuso quel giorno.'],
        ],
    ]);

    // ── 9c. HBOrari ───────────────────────────────────────────────────────────
    register_graphql_object_type('HBOrari', [
        'description' => 'Orari di apertura del locale, sincronizzati da Google Places.',
        'fields' => [
            'ultimoAggiornamento' => ['type' => 'String', 'description' => 'Data/ora dell\'ultimo aggiornamento (formato d/m/Y H:i).'],
            'displayName'         => ['type' => 'String', 'description' => 'Nome del locale come restituito da Google Places.'],
            'settimana'           => ['type' => ['list_of' => 'HBOrariGiorno'], 'description' => 'Orari per ciascun giorno della settimana.'],
        ],
    ]);

    // ── 9d. RootQuery: hbOrari ────────────────────────────────────────────────
    register_graphql_field('RootQuery', 'hbOrari', [
        'type'        => 'HBOrari',
        'description' => 'Restituisce gli orari di apertura del locale.',
        'resolve'     => function (): array {
            $data_option = defined('WPB_ORARI_DATA_OPTION') ? WPB_ORARI_DATA_OPTION : 'wpb_orari_data';
            $data        = get_option($data_option, []);

            $settimana = [];
            foreach ($data['orari_settimanali'] ?? [] as $giorno => $slots) {
                $settimana[] = [
                    'giorno' => $giorno,
                    'slots'  => array_values($slots),
                    'chiuso' => empty($slots),
                ];
            }

            return [
                'ultimoAggiornamento' => $data['ultimo_aggiornamento'] ?? null,
                'displayName'         => $data['display_name'] ?? null,
                'settimana'           => $settimana,
            ];
        },
    ]);

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
                    if (($field['type'] ?? '') === 'page_break')
                        continue;
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

                // ── Costruisci dati submission + decodifica allegati ──────────────
                $submission_data = [];
                $attachments     = [];

                foreach ($form_fields as $field) {
                    if (($field['type'] ?? '') === 'page_break')
                        continue;

                    $name  = $field['name']  ?? '';
                    $type  = $field['type']  ?? 'text';
                    $label = $field['label'] ?? $name;
                    $val   = $input_map[$name] ?? '';

                    if ($type === 'file' && $val !== '') {
                        // Il frontend invia: {"content":"<base64>","filename":"...","type":"..."}
                        $file_data = json_decode($val, true);

                        if (
                            is_array($file_data)
                            && !empty($file_data['content'])
                            && !empty($file_data['filename'])
                        ) {
                            $decoded = base64_decode($file_data['content'], true);

                            if ($decoded !== false) {
                                // Salva in wp-uploads (organizzato per anno/mese come wp_handle_upload)
                                $upload     = wp_upload_dir();
                                $safe_name  = wp_unique_filename($upload['path'], sanitize_file_name($file_data['filename']));
                                $file_path  = $upload['path'] . DIRECTORY_SEPARATOR . $safe_name;
                                $file_url   = $upload['url']  . '/' . $safe_name;

                                file_put_contents($file_path, $decoded);

                                $attachments[] = $file_path;

                                // Stesso formato dell'handler AJAX: visibile nel back office con link
                                $submission_data['_file_' . $name] = [
                                    'label'   => $label,
                                    'value'   => $safe_name,
                                    'url'     => $file_url,
                                    'is_file' => true,
                                ];
                            }
                        }

                        continue; // non salvare il blob base64 nei dati testuali
                    }

                    $submission_data[$name] = ['label' => $label, 'value' => $val];
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

                // ── Invia notifiche email (con allegati) ──────────────────────────
                $mail_log = wpb_forms_send_notifications($form, $settings, $submission_data, $attachments);
                error_log('[WP Barrio][mutation] mail_log: ' . implode(' | ', $mail_log));

                // ── Integrazione MailUp ───────────────────────────────────────────
                if (!empty($settings['mailup_enabled'])) {
                    $mu_global      = get_option('wpb_forms_mailup', []);
                    $mu_client_id   = trim($mu_global['client_id'] ?? '');
                    $mu_secret      = trim($mu_global['client_secret'] ?? '');
                    $mu_list_id     = max(1, intval($settings['mailup_list_id'] ?? 1));
                    $mu_email_field = sanitize_key($settings['mailup_email_field'] ?? 'email');
                    $mu_name_field  = sanitize_key($settings['mailup_name_field'] ?? '');
                    $mu_optin_field = sanitize_key($settings['mailup_optin_field'] ?? '');

                    // Controlla opt-in se configurato
                    $optin_ok = true;
                    if ($mu_optin_field !== '') {
                        $optin_raw   = $submission_data[$mu_optin_field]['value'] ?? '';
                        $optin_value = is_array($optin_raw) ? implode(',', $optin_raw) : $optin_raw;
                        $optin_ok    = trim($optin_value) !== '';
                        if (!$optin_ok) {
                            error_log('[WP Barrio][MailUp][GraphQL] Opt-in non selezionato (campo: ' . $mu_optin_field . ') — iscrizione saltata.');
                        }
                    }

                    $mu_email = sanitize_email(
                        is_array($submission_data[$mu_email_field]['value'] ?? null)
                            ? implode('', $submission_data[$mu_email_field]['value'])
                            : ($submission_data[$mu_email_field]['value'] ?? '')
                    );

                    if ($optin_ok && $mu_client_id && $mu_secret && is_email($mu_email)) {
                        $mu_name = sanitize_text_field(
                            is_array($submission_data[$mu_name_field]['value'] ?? null)
                                ? implode(' ', $submission_data[$mu_name_field]['value'])
                                : ($submission_data[$mu_name_field]['value'] ?? '')
                        );

                        $mu_extra_fields = [];
                        $field_map = $settings['mailup_field_map'] ?? [];
                        if (is_array($field_map)) {
                            foreach ($field_map as $map) {
                                $form_slug = sanitize_key($map['form_field'] ?? '');
                                $mailup_id = intval($map['mailup_field_id'] ?? 0);
                                if (!$form_slug || !$mailup_id) continue;
                                $raw   = $submission_data[$form_slug]['value'] ?? '';
                                $value = sanitize_text_field(is_array($raw) ? implode(', ', $raw) : $raw);
                                $mu_extra_fields[] = ['Id' => $mailup_id, 'Value' => $value];
                            }
                        }

                        $mu_result = wpb_mailup_subscribe($mu_client_id, $mu_secret, $mu_email, $mu_name, $mu_list_id, $mu_extra_fields);
                        if (is_wp_error($mu_result)) {
                            error_log('[WP Barrio][MailUp][GraphQL] Errore iscrizione form_id=' . $form_id . ': ' . $mu_result->get_error_message());
                        }
                    } elseif ($optin_ok) {
                        error_log('[WP Barrio][MailUp][GraphQL] Salto iscrizione: credenziali mancanti o email non valida.');
                    }
                }


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

    // Filtra i page_break per la lista piatta fields
    $real_fields = array_values(array_filter($raw_fields, fn($f) => ($f['type'] ?? '') !== 'page_break'));

    // Costruisci steps: splitta per page_break
    $steps = [];
    $current_step_fields = [];
    foreach ($raw_fields as $f) {
        if (($f['type'] ?? '') === 'page_break') {
            $steps[] = ['fields' => array_map('wpb_graphql_format_field', $current_step_fields)];
            $current_step_fields = [];
        } else {
            $current_step_fields[] = $f;
        }
    }
    // Ultimo step (o unico se nessun page_break)
    $steps[] = ['fields' => array_map('wpb_graphql_format_field', $current_step_fields)];

    return [
        'id' => (int) $form->id,
        'title' => $form->title,
        'createdAt' => $form->created_at,
        'updatedAt' => $form->updated_at ?? $form->created_at,
        'fields' => array_map('wpb_graphql_format_field', $real_fields),
        'steps' => $steps,
        'settings' => wpb_graphql_format_settings($raw_settings),
    ];
}

/* ─────────────────────────────────────────
   HELPER: formatta un singolo campo
   ───────────────────────────────────────── */
function wpb_graphql_format_field(array $f): array
{
    // Normalizza opzioni: stringa semplice -> {value, label}
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

    // Logica condizionale — espone il nuovo formato conditionalLogic
    $conditional_logic = null;
    if (!empty($f['conditionalLogic'])) {
        $cl    = $f['conditionalLogic'];
        $rules = [];
        foreach ((array) ($cl['rules'] ?? []) as $r) {
            if (empty($r['fieldName'])) continue;
            $rules[] = [
                'fieldName' => (string) $r['fieldName'],
                'operator'  => (string) ($r['operator'] ?? '='),
                'value'     => (string) ($r['value'] ?? ''),
            ];
        }
        $conditional_logic = [
            'enabled'   => (bool) ($cl['enabled'] ?? false),
            'action'    => in_array($cl['action'] ?? '', ['show', 'hide']) ? $cl['action'] : 'show',
            'logicType' => in_array($cl['logicType'] ?? '', ['all', 'any']) ? $cl['logicType'] : 'all',
            'rules'     => $rules,
        ];
    }

    // Formato default per datepicker
    $type   = (string) ($f['type'] ?? 'text');
    $format = ($type === 'datepicker') ? (string) ($f['format'] ?? 'dd/MM/y') : '';

    // Pattern
    $pattern = (string) ($f['pattern'] ?? '');
    if ($type === 'tel') {
        $pattern = '^\\s*(?:\\+?(\\d{1,3}))?[-. (]*(\\d{3})[-. )]*(\\d{3})[-. ]*(\\d{4})(?: *x(\\d+))?\\s*$';
    } elseif ($type === 'email') {
        $pattern = '[a-z0-9\\._%+!$&*=^|~#%{}/\\-]+@([a-z0-9\\-]+\\.){1,}([a-z]{2,22})';
    }

    $result = [
        'id'               => (string) ($f['name'] ?? ''),  // alias retrocompatibilita
        'name'             => (string) ($f['name'] ?? ''),
        'type'             => $type,
        'label'            => (string) ($f['label'] ?? ''),
        'placeholder'      => (string) ($f['placeholder'] ?? ''),
        'errorMessage'     => (string) ($f['error_message'] ?? ''),
        'required'         => (bool) ($f['required'] ?? false),
        'pattern'          => $pattern,
        'format'           => $format,
        'options'          => $options,
        'conditionalLogic' => $conditional_logic,
    ];

    if ($type !== 'tel') {
        $result['min'] = isset($f['min']) && $f['min'] !== '' ? (string) $f['min'] : null;
        $result['max'] = isset($f['max']) && $f['max'] !== '' ? (string) $f['max'] : null;
    }

    return $result;
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
