<?php
/**
 * WP Barrio — Forms GraphQL
 *
 * Estende lo schema WPGraphQL con i tipi e le query per i form WP Barrio.
 * Viene caricato SOLO se WPGraphQL è attivo.
 *
 * Query disponibili:
 *   wpbarrioForm(id: Int, slug: String)  → WPBarrioForm
 *   wpbarrioForms                        → [WPBarrioFormListItem]
 *
 * @package WP_Barrio
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Controlla se WPGraphQL è attivo e disponibile.
 * Viene chiamata sia all'attivazione che a ogni caricamento del plugin.
 */
function wpb_graphql_is_available(): bool {
    return function_exists( 'register_graphql_field' )
        && function_exists( 'register_graphql_object_type' );
}

/**
 * Mostra un admin notice se WPGraphQL non è installato/attivo.
 */
add_action( 'admin_notices', function () {
    if ( wpb_graphql_is_available() ) return;

    // Mostra il notice solo nelle pagine WP Barrio
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'wp-barrio' ) === false ) return;

    ?>
    <div class="notice notice-warning">
        <p>
            <strong>WP Barrio — Forms GraphQL:</strong>
            per esporre i form tramite GraphQL è necessario installare e attivare il plugin
            <a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=wpgraphql&tab=search&type=term' ) ); ?>">
                WPGraphQL
            </a>.
        </p>
    </div>
    <?php
} );

/**
 * Registra tutti i tipi e le query GraphQL.
 * Aggancia a `graphql_register_types` che WPGraphQL esegue quando
 * costruisce lo schema — si attiva solo se WPGraphQL è presente.
 */
add_action( 'graphql_register_types', 'wpb_graphql_register_schema' );

function wpb_graphql_register_schema(): void {

    // ── 1. WPBarrioFormOption ─────────────────────────────────────────────────
    // Rappresenta un'opzione di campo select / radio / checkbox
    register_graphql_object_type( 'WPBarrioFormOption', [
        'description' => 'Opzione di un campo select, radio o checkbox di WP Barrio Forms.',
        'fields'      => [
            'value' => [
                'type'        => 'String',
                'description' => 'Valore dell\'opzione.',
            ],
            'label' => [
                'type'        => 'String',
                'description' => 'Etichetta visibile all\'utente.',
            ],
        ],
    ] );

    // ── 2. WPBarrioShowFieldCondition ─────────────────────────────────────────
    // Singola condizione per la visibilità condizionale di un campo
    register_graphql_object_type( 'WPBarrioShowFieldCondition', [
        'description' => 'Condizione per la visibilità condizionale di un campo.',
        'fields'      => [
            'value'    => [ 'type' => 'String', 'description' => 'Valore atteso.' ],
            'operator' => [ 'type' => 'String', 'description' => 'Operatore di confronto (=, !=, >, <, >=, <=).' ],
        ],
    ] );

    // ── 3. WPBarrioShowFieldRule ──────────────────────────────────────────────
    // Regola completa di visibilità: mostra `fields` se `conditions` è verificata
    register_graphql_object_type( 'WPBarrioShowFieldRule', [
        'description' => 'Regola di visibilità condizionale: quali campi mostrare e quando.',
        'fields'      => [
            'fields'     => [
                'type'        => [ 'list_of' => 'String' ],
                'description' => 'Nomi dei campi da mostrare/nascondere.',
            ],
            'relation'   => [
                'type'        => 'String',
                'description' => 'Relazione logica tra le condizioni: AND oppure OR.',
            ],
            'conditions' => [
                'type'        => [ 'list_of' => 'WPBarrioShowFieldCondition' ],
                'description' => 'Lista di condizioni da verificare.',
            ],
        ],
    ] );

    // ── 4. WPBarrioFormField ──────────────────────────────────────────────────
    // Rappresenta un singolo campo del form, con tutte le sue proprietà
    register_graphql_object_type( 'WPBarrioFormField', [
        'description' => 'Campo di un form WP Barrio.',
        'fields'      => [
            // Identificativi
            'id'    => [ 'type' => 'String', 'description' => 'ID interno univoco del campo.' ],
            'name'  => [ 'type' => 'String', 'description' => 'Name HTML del campo (coincide con id).' ],
            'type'  => [ 'type' => 'String', 'description' => 'Tipo del campo: text, email, textarea, tel, number, date, select, radio, checkbox, range, file.' ],

            // Testi
            'label'        => [ 'type' => 'String', 'description' => 'Etichetta visibile.' ],
            'placeholder'  => [ 'type' => 'String', 'description' => 'Testo segnaposto.' ],
            'errorMessage' => [ 'type' => 'String', 'description' => 'Messaggio di errore personalizzato.' ],

            // Validazione
            'required' => [ 'type' => 'Boolean', 'description' => 'Se il campo è obbligatorio.' ],
            'pattern'  => [ 'type' => 'String',  'description' => 'Regex di validazione (HTML pattern attribute).' ],

            // Numero
            'min' => [ 'type' => 'String', 'description' => 'Valore minimo (tipo number/range).' ],
            'max' => [ 'type' => 'String', 'description' => 'Valore massimo (tipo number/range).' ],

            // Data
            'format' => [ 'type' => 'String', 'description' => 'Formato data (es. dd/MM/y) per il datepicker.' ],

            // Opzioni (select, radio, checkbox)
            'options' => [
                'type'        => [ 'list_of' => 'WPBarrioFormOption' ],
                'description' => 'Lista di opzioni per i campi select, radio e checkbox.',
            ],

            // Visibilità condizionale
            'showFields' => [
                'type'        => [ 'list_of' => 'WPBarrioShowFieldRule' ],
                'description' => 'Regole di visibilità condizionale: questo campo può mostrare/nascondere altri campi.',
            ],
        ],
    ] );

    // ── 5. WPBarrioFormSettings ───────────────────────────────────────────────
    // Impostazioni generali del form
    register_graphql_object_type( 'WPBarrioFormSettings', [
        'description' => 'Impostazioni di un form WP Barrio.',
        'fields'      => [
            'submitText'     => [ 'type' => 'String', 'description' => 'Testo del pulsante di invio.' ],
            'successMessage' => [ 'type' => 'String', 'description' => 'Messaggio mostrato dopo l\'invio.' ],
            'errorMessage'   => [ 'type' => 'String', 'description' => 'Messaggio di errore generico del form.' ],
            'afterSubmit'    => [ 'type' => 'String', 'description' => 'Comportamento dopo l\'invio: "message" o "redirect".' ],
            'redirectUrl'    => [ 'type' => 'String', 'description' => 'URL di redirect dopo l\'invio (se afterSubmit = redirect).' ],
        ],
    ] );

    // ── 6. WPBarrioForm ───────────────────────────────────────────────────────
    // Tipo principale: form completo con campi e impostazioni
    register_graphql_object_type( 'WPBarrioForm', [
        'description' => 'Form di contatto WP Barrio con tutti i suoi campi e impostazioni.',
        'fields'      => [
            'id'         => [ 'type' => [ 'non_null' => 'Int' ], 'description' => 'ID numerico del form.' ],
            'title'      => [ 'type' => 'String', 'description' => 'Titolo del form.' ],
            'slug'       => [ 'type' => 'String', 'description' => 'Slug URL-safe del form.' ],
            'createdAt'  => [ 'type' => 'String', 'description' => 'Data di creazione (ISO 8601).' ],
            'updatedAt'  => [ 'type' => 'String', 'description' => 'Data di ultima modifica (ISO 8601).' ],
            'fields'     => [
                'type'        => [ 'list_of' => 'WPBarrioFormField' ],
                'description' => 'Lista dei campi del form.',
            ],
            'settings'   => [
                'type'        => 'WPBarrioFormSettings',
                'description' => 'Impostazioni del form.',
            ],
        ],
    ] );

    // ── 7. WPBarrioFormListItem ───────────────────────────────────────────────
    // Tipo leggero per la lista dei form (senza i campi completi)
    register_graphql_object_type( 'WPBarrioFormListItem', [
        'description' => 'Voce della lista dei form WP Barrio (senza i dettagli dei campi).',
        'fields'      => [
            'id'          => [ 'type' => [ 'non_null' => 'Int' ], 'description' => 'ID del form.' ],
            'title'       => [ 'type' => 'String', 'description' => 'Titolo del form.' ],
            'slug'        => [ 'type' => 'String', 'description' => 'Slug del form.' ],
            'submissions' => [ 'type' => 'Int',    'description' => 'Numero di risposte ricevute.' ],
            'createdAt'   => [ 'type' => 'String', 'description' => 'Data di creazione.' ],
        ],
    ] );

    // ── 8. RootQuery: wpbarrioForm ────────────────────────────────────────────
    // Recupera un singolo form per ID o per slug
    register_graphql_field( 'RootQuery', 'wpbarrioForm', [
        'type'        => 'WPBarrioForm',
        'description' => 'Restituisce un form WP Barrio per ID o per slug.',
        'args'        => [
            'id'   => [ 'type' => 'Int',    'description' => 'ID numerico del form.' ],
            'slug' => [ 'type' => 'String', 'description' => 'Slug del form.' ],
        ],
        'resolve' => function ( $root, array $args ): ?array {
            global $wpdb;

            $form = null;

            if ( ! empty( $args['id'] ) ) {
                $form = wpb_forms_get( intval( $args['id'] ) );
            } elseif ( ! empty( $args['slug'] ) ) {
                $form = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wpb_forms WHERE slug = %s LIMIT 1",
                    sanitize_title( $args['slug'] )
                ) );
            }

            if ( ! $form ) return null;

            return wpb_graphql_format_form( $form );
        },
    ] );

    // ── 9. RootQuery: wpbarrioForms ───────────────────────────────────────────
    // Lista di tutti i form (dati leggeri, senza i campi completi)
    register_graphql_field( 'RootQuery', 'wpbarrioForms', [
        'type'        => [ 'list_of' => 'WPBarrioFormListItem' ],
        'description' => 'Restituisce la lista di tutti i form WP Barrio.',
        'resolve'     => function (): array {
            global $wpdb;

            $rows = $wpdb->get_results(
                "SELECT id, title, slug, created_at,
                        (SELECT COUNT(*) FROM {$wpdb->prefix}wpb_form_submissions s
                         WHERE s.form_id = f.id) AS submissions
                 FROM {$wpdb->prefix}wpb_forms f
                 ORDER BY id DESC"
            );

            if ( ! $rows ) return [];

            return array_map( fn( $r ) => [
                'id'          => (int) $r->id,
                'title'       => $r->title,
                'slug'        => $r->slug,
                'submissions' => (int) $r->submissions,
                'createdAt'   => $r->created_at,
            ], $rows );
        },
    ] );
}

/* ─────────────────────────────────────────
   HELPER: formatta un form per GraphQL
   ───────────────────────────────────────── */
function wpb_graphql_format_form( object $form ): array {
    $raw_fields   = json_decode( $form->fields,   true ) ?: [];
    $raw_settings = json_decode( $form->settings, true ) ?: [];

    return [
        'id'        => (int) $form->id,
        'title'     => $form->title,
        'slug'      => $form->slug,
        'createdAt' => $form->created_at,
        'updatedAt' => $form->updated_at ?? $form->created_at,
        'fields'    => array_map( 'wpb_graphql_format_field', $raw_fields ),
        'settings'  => wpb_graphql_format_settings( $raw_settings ),
    ];
}

/* ─────────────────────────────────────────
   HELPER: formatta un singolo campo
   ───────────────────────────────────────── */
function wpb_graphql_format_field( array $f ): array {
    // Le opzioni sono salvate internamente come array di stringhe ["Opzione 1", ...]
    // oppure come array di oggetti [{value, label}] — normalizziamo sempre in {value, label}
    $options = [];
    foreach ( $f['options'] ?? [] as $opt ) {
        if ( is_array( $opt ) && isset( $opt['value'] ) ) {
            $options[] = [
                'value' => (string) $opt['value'],
                'label' => (string) ( $opt['label'] ?? $opt['value'] ),
            ];
        } else {
            // Stringa semplice → value e label coincidono
            $options[] = [
                'value' => (string) $opt,
                'label' => (string) $opt,
            ];
        }
    }

    // showFields: visibilità condizionale
    $show_fields = [];
    foreach ( $f['showFields'] ?? [] as $rule ) {
        $conditions = [];
        foreach ( $rule['conditions'] ?? [] as $cond ) {
            $conditions[] = [
                'value'    => (string) ( $cond['value']    ?? '' ),
                'operator' => (string) ( $cond['operator'] ?? '=' ),
            ];
        }
        $show_fields[] = [
            'fields'     => (array) ( $rule['fields'] ?? [] ),
            'relation'   => (string) ( $rule['relation'] ?? 'AND' ),
            'conditions' => $conditions,
        ];
    }

    return [
        // Identificativi — `name` è l'attributo HTML, coincide con `id` internamente
        'id'           => (string) ( $f['id'] ?? '' ),
        'name'         => (string) ( $f['id'] ?? '' ),
        'type'         => (string) ( $f['type'] ?? 'text' ),

        // Testi
        'label'        => (string) ( $f['label']         ?? '' ),
        'placeholder'  => (string) ( $f['placeholder']   ?? '' ),
        'errorMessage' => (string) ( $f['error_message'] ?? '' ),

        // Validazione
        'required'     => (bool)   ( $f['required'] ?? false ),
        'pattern'      => (string) ( $f['pattern']  ?? '' ),

        // Numero / Range
        'min'          => isset( $f['min'] ) && $f['min'] !== '' ? (string) $f['min'] : null,
        'max'          => isset( $f['max'] ) && $f['max'] !== '' ? (string) $f['max'] : null,

        // Data
        'format'       => (string) ( $f['format'] ?? '' ),

        // Opzioni
        'options'      => $options,

        // Visibilità condizionale
        'showFields'   => $show_fields,
    ];
}

/* ─────────────────────────────────────────
   HELPER: formatta le impostazioni del form
   ───────────────────────────────────────── */
function wpb_graphql_format_settings( array $s ): array {
    return [
        'submitText'     => (string) ( $s['submit_text']     ?? 'Invia' ),
        'successMessage' => (string) ( $s['success_message'] ?? '' ),
        'errorMessage'   => (string) ( $s['error_message']   ?? '' ),
        'afterSubmit'    => (string) ( $s['after_submit']    ?? 'message' ),
        'redirectUrl'    => (string) ( $s['redirect_url']    ?? '' ),
    ];
}
