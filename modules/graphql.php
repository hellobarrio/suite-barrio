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
 *   hbBanner          → HBBanner (banner marquee attivo)
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
            'operator' => ['type' => 'String', 'description' => 'Operatore: =, !=, >, <, contains, starts_with, ends_with, empty, not_empty.'],
            'value' => ['type' => 'String', 'description' => 'Valore atteso.'],
        ],
    ]);

    // ── 3. HBConditionalLogic ─────────────────────────────────────────────────
    register_graphql_object_type('HBConditionalLogic', [
        'description' => 'Logica condizionale di un campo (Gravity Forms-style): il campo stesso descrive quando mostrarsi o nascondersi.',
        'fields' => [
            'enabled' => ['type' => 'Boolean', 'description' => 'Se true la logica condizionale è attiva.'],
            'action' => ['type' => 'String', 'description' => 'Cosa fare quando le regole sono soddisfatte: "show" oppure "hide".'],
            'logicType' => ['type' => 'String', 'description' => 'Relazione tra le regole: "all" (AND) oppure "any" (OR).'],
            'rules' => ['type' => ['list_of' => 'HBConditionalLogicRule'], 'description' => 'Lista delle regole da soddisfare.'],
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
            'url' => ['type' => 'String', 'description' => 'URL di destinazione.'],
            'title' => ['type' => 'String', 'description' => 'Testo del pulsante.'],
            'target' => ['type' => 'String', 'description' => 'Target del link: _self o _blank.'],
        ],
    ]);

    register_graphql_object_type('HBPopup', [
        'description' => 'Popup attivo da mostrare sul frontend.',
        'fields' => [
            'id' => ['type' => ['non_null' => 'Int'], 'description' => 'ID del popup.'],
            'title' => ['type' => 'String', 'description' => 'Titolo del popup.'],
            'content' => ['type' => 'String', 'description' => 'Testo/HTML del popup.'],
            'link' => ['type' => 'HBPopupCta', 'description' => 'CTA del popup (opzionale).'],
            'image' => ['type' => 'HBPopupImage', 'description' => 'Immagine del popup (opzionale).'],
        ],
    ]);

    // ── 9a2. RootQuery: hbPopup ───────────────────────────────────────────────
    register_graphql_field('RootQuery', 'hbPopup', [
        'type' => 'HBPopup',
        'description' => 'Restituisce il popup attivo, o null se nessuno è attivo.',
        'resolve' => function (): ?array {
            if (!function_exists('wpb_popups_get_active'))
                return null;

            $popup = wpb_popups_get_active();
            if (!$popup)
                return null;

            // Costruisce il wrapper image solo se c'è un attachment valido.
            // Restituiamo l'ID del post: WPGraphQL risolve il MediaItem nativo
            // a partire da esso, rendendo i fragment come MediaItemFragment compatibili.
            $image = null;
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
                'id' => (int) $popup->id,
                'title' => (string) $popup->title,
                'content' => (string) $popup->content,
                'link' => !empty($popup->link) ? [
                    'url' => (string) $popup->link,
                    'title' => (string) ($popup->link_title ?? ''),
                    'target' => (string) ($popup->link_target ?? '_self'),
                ] : null,
                'image' => $image,
            ];
        },
    ]);

    // ── 9b. HBOrariGiorno ─────────────────────────────────────────────────────

    // ── 9a3. HBBannerCta ──────────────────────────────────────────────────────
    register_graphql_object_type('HBBannerCta', [
        'description' => 'Link del banner (opzionale).',
        'fields' => [
            'url' => ['type' => 'String', 'description' => 'URL del link.'],
            'target' => ['type' => 'String', 'description' => 'Target del link (_self o _blank).'],
        ],
    ]);

    // ── 9a4. HBBanner ─────────────────────────────────────────────────────────
    register_graphql_object_type('HBBanner', [
        'description' => 'Banner a scorrimento (marquee) attivo sul sito.',
        'fields' => [
            'id' => ['type' => 'Int', 'description' => 'ID univoco del banner.'],
            'title' => ['type' => 'String', 'description' => 'Testo del banner.'],
            'link' => ['type' => 'HBBannerCta', 'description' => 'Link del banner (opzionale).'],
        ],
    ]);

    // ── 9a5. RootQuery: hbBanner ──────────────────────────────────────────────
    register_graphql_field('RootQuery', 'hbBanner', [
        'type' => 'HBBanner',
        'description' => 'Restituisce il banner attivo, o null se nessuno è attivo.',
        'resolve' => function (): ?array {
            if (!function_exists('wpb_banners_get_active'))
                return null;

            $banner = wpb_banners_get_active();
            if (!$banner)
                return null;

            return [
                'id' => (int) $banner->id,
                'title' => (string) $banner->title,
                'link' => !empty($banner->link) ? [
                    'url' => (string) $banner->link,
                    'target' => (string) ($banner->link_target ?? '_self'),
                ] : null,
            ];
        },
    ]);

    register_graphql_object_type('HBOrariGiorno', [
        'description' => 'Orari di apertura per un singolo giorno della settimana.',
        'fields' => [
            'giorno' => ['type' => 'String', 'description' => 'Nome del giorno (es. Lunedì).'],
            'slots' => ['type' => ['list_of' => 'String'], 'description' => 'Fasce orarie (es. ["09:00 - 13:00", "15:00 - 19:00"]). Array vuoto = chiuso.'],
            'chiuso' => ['type' => 'Boolean', 'description' => 'true se il locale è chiuso quel giorno.'],
        ],
    ]);

    // ── 9c. HBOrari ───────────────────────────────────────────────────────────
    register_graphql_object_type('HBOrari', [
        'description' => 'Orari di apertura del locale, sincronizzati da Google Places.',
        'fields' => [
            'ultimoAggiornamento' => ['type' => 'String', 'description' => 'Data/ora dell\'ultimo aggiornamento (formato d/m/Y H:i).'],
            'displayName' => ['type' => 'String', 'description' => 'Nome del locale come restituito da Google Places.'],
            'settimana' => ['type' => ['list_of' => 'HBOrariGiorno'], 'description' => 'Orari per ciascun giorno della settimana.'],
        ],
    ]);

    // ── 9d. RootQuery: hbOrari ────────────────────────────────────────────────
    register_graphql_field('RootQuery', 'hbOrari', [
        'type' => 'HBOrari',
        'description' => 'Restituisce gli orari di apertura del locale.',
        'resolve' => function (): array {
            $data_option = defined('WPB_ORARI_DATA_OPTION') ? WPB_ORARI_DATA_OPTION : 'wpb_orari_data';
            $data = get_option($data_option, []);

            $settimana = [];
            foreach ($data['orari_settimanali'] ?? [] as $giorno => $slots) {
                $settimana[] = [
                    'giorno' => $giorno,
                    'slots' => array_values($slots),
                    'chiuso' => empty($slots),
                ];
            }

            return [
                'ultimoAggiornamento' => $data['ultimo_aggiornamento'] ?? null,
                'displayName' => $data['display_name'] ?? null,
                'settimana' => $settimana,
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
                $attachments = [];

                foreach ($form_fields as $field) {
                    if (($field['type'] ?? '') === 'page_break')
                        continue;

                    $name = $field['name'] ?? '';
                    $type = $field['type'] ?? 'text';
                    $label = $field['label'] ?? $name;
                    $val = $input_map[$name] ?? '';

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
                                $upload = wp_upload_dir();
                                $safe_name = wp_unique_filename($upload['path'], sanitize_file_name($file_data['filename']));
                                $file_path = $upload['path'] . DIRECTORY_SEPARATOR . $safe_name;
                                $file_url = $upload['url'] . '/' . $safe_name;

                                file_put_contents($file_path, $decoded);

                                $attachments[] = $file_path;

                                // Stesso formato dell'handler AJAX: visibile nel back office con link
                                $submission_data['_file_' . $name] = [
                                    'label' => $label,
                                    'value' => $safe_name,
                                    'url' => $file_url,
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
                    $mu_global = get_option('wpb_forms_mailup', []);
                    $mu_client_id = trim($mu_global['client_id'] ?? '');
                    $mu_secret = trim($mu_global['client_secret'] ?? '');
                    $mu_list_id = max(1, intval($settings['mailup_list_id'] ?? 1));
                    $mu_email_field = sanitize_key($settings['mailup_email_field'] ?? 'email');
                    $mu_name_field = sanitize_key($settings['mailup_name_field'] ?? '');
                    $mu_optin_field = sanitize_key($settings['mailup_optin_field'] ?? '');

                    // Controlla opt-in se configurato
                    $optin_ok = true;
                    if ($mu_optin_field !== '') {
                        $optin_raw = $submission_data[$mu_optin_field]['value'] ?? '';
                        $optin_value = is_array($optin_raw) ? implode(',', $optin_raw) : $optin_raw;
                        $optin_ok = trim($optin_value) !== '';
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
                                if (!$form_slug || !$mailup_id)
                                    continue;
                                $raw = $submission_data[$form_slug]['value'] ?? '';
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

    // ── Tipo: dati SEO di un singolo post ────────────────────────────────
    register_graphql_object_type('HBSeoPost', [
        'description' => 'Dati SEO di un post calcolati da WP Barrio Meta Tags',
        'fields' => [
            'title'         => ['type' => 'String',  'description' => 'Meta title completo (custom o generato dal titolo del post)'],
            'description'   => ['type' => 'String',  'description' => 'Meta description (custom, excerpt o default globale)'],
            'robots'        => ['type' => 'String',  'description' => 'Valore del tag robots (es. index,follow)'],
            'canonical'     => ['type' => 'String',  'description' => 'URL canonical del post'],
            'ogTitle'       => ['type' => 'String',  'description' => 'og:title'],
            'ogDescription' => ['type' => 'String',  'description' => 'og:description'],
            'ogImage'       => ['type' => 'String',  'description' => 'URL assoluto dell\'immagine og:image'],
            'ogType'        => ['type' => 'String',  'description' => 'og:type (article per i post singoli)'],
            'twitterCard'   => ['type' => 'String',  'description' => 'twitter:card'],
            'twitterTitle'  => ['type' => 'String',  'description' => 'twitter:title'],
            'twitterImage'  => ['type' => 'String',  'description' => 'URL assoluto dell\'immagine twitter:image'],
            'isIndexed'     => ['type' => 'Boolean', 'description' => 'Il post è indicizzabile (override per-post o default post type)'],
            'isInSitemap'   => ['type' => 'Boolean', 'description' => 'Il post è incluso nella sitemap (override per-post o default post type)'],
            'keywords'      => ['type' => ['list_of' => 'String'], 'description' => 'Keywords SEO: il primo elemento è la keyword primaria'],
        ],
    ]);

    // ── Campo seo su ogni post type indicizzato ──────────────────────────
    // Ricava il graphql_single_name di ogni post type pubblico e registra
    // il campo seo direttamente sul tipo WPGraphQL corrispondente.
    // Es: Post → post { seo { ... } }
    //     Page → page { seo { ... } }
    //     CPT  → mioTipo { seo { ... } }
    $content = get_option(WPB_META_CONTENT_OPTION, []);

    foreach (get_post_types(['public' => true, 'show_in_graphql' => true], 'objects') as $pt_obj) {
        $pt_slug = $pt_obj->name;
        if ($pt_slug === 'attachment') continue;

        // Rispetta la configurazione della tab Contenuti:
        // se il post type non è indicizzato il campo esiste comunque
        // ma isIndexed sarà false — utile per sapere lato client.

        $graphql_type = $pt_obj->graphql_single_name ?? null;
        if (empty($graphql_type)) continue;

        // Capitalizza la prima lettera per corrispondere al tipo WPGraphQL
        $graphql_type = ucfirst($graphql_type);

        register_graphql_field($graphql_type, 'seo', [
            'type'        => 'HBSeoPost',
            'description' => 'Dati SEO calcolati da WP Barrio Meta Tags',
            'resolve'     => function ($post_obj) use ($pt_slug) {
                // $post_obj è il model WPGraphQL — recupera il WP_Post
                $post_id = $post_obj->databaseId ?? ($post_obj->ID ?? null);
                if (!$post_id) return null;

                $post = get_post((int) $post_id);
                if (!$post || $post->post_status !== 'publish') return null;

                $s       = get_option(WPB_META_OPTION, []);
                $content = get_option(WPB_META_CONTENT_OPTION, []);

                $sep       = $s['separator']   ?? '—';
                $site_name = $s['site_title']  ?? get_bloginfo('name');
                $order     = $s['title_order'] ?? 'page_site';

                // Title
                $custom_title = get_post_meta($post_id, '_wpb_seo_title', true);
                $base_title   = $custom_title ?: get_the_title($post);
                $full_title   = $order === 'site_page'
                    ? $site_name . ' ' . $sep . ' ' . $base_title
                    : $base_title . ' ' . $sep . ' ' . $site_name;

                // Description
                $description = get_post_meta($post_id, '_wpb_seo_description', true);
                if (empty($description)) $description = get_the_excerpt($post);
                if (empty($description)) $description = $s['default_desc'] ?? '';
                $description = wp_strip_all_tags($description);

                // OG Image
                $og_image_url = '';
                $custom_og_id = (int) get_post_meta($post_id, '_wpb_seo_og_image_id', true);
                if ($custom_og_id)
                    $og_image_url = wp_get_attachment_image_url($custom_og_id, 'full');
                if (empty($og_image_url) && has_post_thumbnail($post_id))
                    $og_image_url = get_the_post_thumbnail_url($post_id, 'full');
                if (empty($og_image_url) && !empty($s['og_image_id']))
                    $og_image_url = wp_get_attachment_image_url((int) $s['og_image_id'], 'full');

                // Robots (override per-post + fallback post type config)
                $cfg         = $content['post_types'][$pt_slug] ?? ['index' => 1, 'follow' => 1];
                $post_index   = get_post_meta($post_id, '_wpb_seo_index',   true);
                $post_follow  = get_post_meta($post_id, '_wpb_seo_follow',  true);
                $post_sitemap = get_post_meta($post_id, '_wpb_seo_sitemap', true);
                $idx     = $post_index   !== '' ? (bool)(int)$post_index   : (bool)($cfg['index']   ?? 1);
                $follow  = $post_follow  !== '' ? (bool)(int)$post_follow  : (bool)($cfg['follow']  ?? 1);
                $sitemap = $post_sitemap !== '' ? (bool)(int)$post_sitemap : (bool)($cfg['sitemap'] ?? 1);
                $robots = ($idx ? 'index' : 'noindex') . ',' . ($follow ? 'follow' : 'nofollow');

                return [
                    'title'         => $full_title,
                    'description'   => $description,
                    'robots'        => $robots,
                    'canonical'     => get_permalink($post_id) ?: '',
                    'ogTitle'       => $full_title,
                    'ogDescription' => $description,
                    'ogImage'       => $og_image_url ?: null,
                    'ogType'        => 'article',
                    'twitterCard'   => $s['twitter_card'] ?? 'summary_large_image',
                    'twitterTitle'  => $full_title,
                    'twitterImage'  => $og_image_url ?: null,
                    'isIndexed'     => $idx,
                    'isInSitemap'   => $sitemap,
                    'keywords'      => (function() use ($post_id) {
                        $raw = get_post_meta($post_id, '_wpb_seo_keywords', true);
                        if (!$raw) return [];
                        $kws = json_decode($raw, true);
                        return is_array($kws) ? $kws : [];
                    })(),
                ];
            },
        ]);
    }

    // ── Tipo: impostazioni SEO globali del sito ──────────────────────────
    register_graphql_object_type('HBSeoSite', [
        'description' => 'Impostazioni SEO globali del sito configurate in WP Barrio',
        'fields' => [
            'siteTitle' => ['type' => 'String', 'description' => 'Titolo del sito'],
            'separator' => ['type' => 'String', 'description' => 'Separatore usato nel title tag (es. —)'],
            'titleOrder' => ['type' => 'String', 'description' => 'Ordine del title: page_site o site_page'],
            'defaultDesc' => ['type' => 'String', 'description' => 'Meta description di default'],
            'robotsDefault' => ['type' => 'String', 'description' => 'Valore robots globale di default'],
            'ogImage' => ['type' => 'String', 'description' => 'URL dell\'immagine OG di default'],
            'favicon' => ['type' => 'String', 'description' => 'URL assoluto della favicon del sito'],
        ],
    ]);

    // ── Query: hbSeoSite ─────────────────────────────────────────────────
    register_graphql_field('RootQuery', 'hbSeoSite', [
        'type' => 'HBSeoSite',
        'description' => 'Restituisce le impostazioni SEO globali del sito configurate in WP Barrio',
        'resolve' => function () {
            $s = get_option(WPB_META_OPTION, []);

            $og_image_url = '';
            if (!empty($s['og_image_id']))
                $og_image_url = wp_get_attachment_image_url((int) $s['og_image_id'], 'full');

            $favicon_url = '';
            if (!empty($s['favicon_id']))
                $favicon_url = wp_get_attachment_image_url((int) $s['favicon_id'], 'full');

            return [
                'siteTitle' => $s['site_title'] ?? get_bloginfo('name'),
                'separator' => $s['separator'] ?? '—',
                'titleOrder' => $s['title_order'] ?? 'page_site',
                'defaultDesc' => $s['default_desc'] ?? '',
                'robotsDefault' => $s['robots_default'] ?? 'index,follow',
                'ogImage' => $og_image_url ?: null,
                'favicon' => $favicon_url ?: null,
            ];
        },
    ]);

    // ── Tipo: singolo redirect ────────────────────────────────────────────
    register_graphql_object_type('HBRedirect', [
        'description' => 'Un redirect configurato in WP Barrio SEO',
        'fields'      => [
            'id'   => ['type' => ['non_null' => 'String'], 'description' => 'UUID univoco del redirect'],
            'from' => ['type' => ['non_null' => 'String'], 'description' => 'URI relativo di origine (es. /vecchio-path/)'],
            'to'   => ['type' => 'String',                 'description' => 'URI relativo di destinazione (null se codice 410)'],
            'code' => ['type' => ['non_null' => 'Int'],    'description' => 'Codice HTTP: 301, 302, 307, 308 o 410'],
            'note' => ['type' => 'String',                 'description' => 'Nota descrittiva opzionale'],
        ],
    ]);

    // ── Query: hbRedirects ───────────────────────────────────────────────
    register_graphql_field('RootQuery', 'hbRedirects', [
        'type'        => ['list_of' => 'HBRedirect'],
        'description' => 'Restituisce tutti i redirect configurati in WP Barrio SEO come URI relativi',
        'resolve'     => function () {
            if (!function_exists('wpb_redirects_get_all'))
                return [];

            return array_map(function ($row) {
                return [
                    'id'   => $row['id']   ?? '',
                    'from' => $row['from'] ?? '',
                    'to'   => ($row['code'] ?? 301) === 410 ? null : ($row['to'] ?? ''),
                    'code' => (int) ($row['code'] ?? 301),
                    'note' => $row['note'] ?? null,
                ];
            }, wpb_redirects_get_all());
        },
    ]);

    // ── Tipo: singola entry della sitemap ────────────────────────────────
    register_graphql_object_type('HBSitemapEntry', [
        'description' => 'Una singola URL nella sitemap di WP Barrio',
        'fields'      => [
            'loc'      => ['type' => ['non_null' => 'String'], 'description' => 'URL assoluto'],
            'lastmod'  => ['type' => 'String',                 'description' => 'Data ultima modifica in formato ISO 8601 (solo per i post)'],
            'type'     => ['type' => ['non_null' => 'String'], 'description' => '"post" o "term"'],
            'subtype'  => ['type' => ['non_null' => 'String'], 'description' => 'Post type o slug tassonomia'],
            'id'       => ['type' => ['non_null' => 'Int'],    'description' => 'ID WordPress del post o del termine'],
            'title'    => ['type' => 'String',                 'description' => 'Titolo del post o nome del termine'],
            'priority' => ['type' => 'Float',                  'description' => 'Priority SEO (0.0–1.0): homepage=1.0, pagine=0.8, post recenti=0.9, tassonomie=0.3–0.6'],
        ],
    ]);

    // ── Query: hbSitemap ─────────────────────────────────────────────────
    register_graphql_field('RootQuery', 'hbSitemap', [
        'type'        => ['list_of' => 'HBSitemapEntry'],
        'description' => 'Restituisce tutte le URL incluse nella sitemap di WP Barrio',
        'resolve'     => function () {
            if (!function_exists('wpb_sitemap_build_entries'))
                return [];

            $entries = wpb_sitemap_build_entries();
            $result  = [];

            foreach ($entries as $entry) {
                $loc = $entry['loc'];
                if (is_wp_error($loc) || empty($loc)) continue;
                $result[] = [
                    'loc'      => wp_make_link_relative($loc),
                    'lastmod'  => $entry['lastmod'] ?: null,
                    'type'     => $entry['type'],
                    'subtype'  => $entry['subtype'],
                    'id'       => (int) $entry['id'],
                    'title'    => $entry['title'] ?? null,
                    'priority' => (float) ($entry['priority'] ?? 0.5),
                ];
            }

            return $result;
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
        $cl = $f['conditionalLogic'];
        $rules = [];
        foreach ((array) ($cl['rules'] ?? []) as $r) {
            if (empty($r['fieldName']))
                continue;
            $rules[] = [
                'fieldName' => (string) $r['fieldName'],
                'operator' => (string) ($r['operator'] ?? '='),
                'value' => (string) ($r['value'] ?? ''),
            ];
        }
        $conditional_logic = [
            'enabled' => (bool) ($cl['enabled'] ?? false),
            'action' => in_array($cl['action'] ?? '', ['show', 'hide']) ? $cl['action'] : 'show',
            'logicType' => in_array($cl['logicType'] ?? '', ['all', 'any']) ? $cl['logicType'] : 'all',
            'rules' => $rules,
        ];
    }

    // Formato default per datepicker
    $type = (string) ($f['type'] ?? 'text');
    $format = ($type === 'datepicker') ? (string) ($f['format'] ?? 'dd/MM/y') : '';

    // Pattern
    $pattern = (string) ($f['pattern'] ?? '');
    if ($type === 'tel') {
        $pattern = '^\\s*(?:\\+?(\\d{1,3}))?[-. (]*(\\d{3})[-. )]*(\\d{3})[-. ]*(\\d{4})(?: *x(\\d+))?\\s*$';
    } elseif ($type === 'email') {
        $pattern = '[a-z0-9\\._%+!$&*=^|~#%{}/\\-]+@([a-z0-9\\-]+\\.){1,}([a-z]{2,22})';
    }

    $result = [
        'id' => (string) ($f['name'] ?? ''),  // alias retrocompatibilita
        'name' => (string) ($f['name'] ?? ''),
        'type' => $type,
        'label' => (string) ($f['label'] ?? ''),
        'placeholder' => (string) ($f['placeholder'] ?? ''),
        'errorMessage' => (string) ($f['error_message'] ?? ''),
        'required' => (bool) ($f['required'] ?? false),
        'pattern' => $pattern,
        'format' => $format,
        'options' => $options,
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
