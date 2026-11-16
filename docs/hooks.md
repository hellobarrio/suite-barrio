# API per le estensioni

Le estensioni sono plugin separati che usano solo l'API qui sotto: il core non contiene codice per
plugin o servizi di terzi. Tre esempi completi, in `extensions/`:

| Estensione | Cosa mostra |
|---|---|
| [`barrio-suite-mailchimp`](../extensions/barrio-suite-mailchimp/barrio-suite-mailchimp.php) | Un'integrazione con impostazioni globali (la chiave API) e per form, anche tradotte, e `process()` |
| [`barrio-suite-recaptcha`](../extensions/barrio-suite-recaptcha/barrio-suite-recaptcha.php) | Un captcha: solo impostazioni globali, `barrio_forms_render`, `beforeSubmit` nel browser e `barrio_forms_spam_check`; il filtro `barrio_recaptcha_threshold` cambia il punteggio minimo (0.5) |
| [`barrio-suite-wpml`](../extensions/barrio-suite-wpml/barrio-suite-wpml.php) | Le lingue da un plugin multilingua, con i filtri `barrio_forms_languages` e seguenti |

I test delle estensioni sono in `tests/Integration/` (`MailchimpTest`, `RecaptchaTest`, `WpmlTest`):
le risposte dei servizi si simulano con `pre_http_request`.

## Pubblicazione e installazione

Ogni estensione è un plugin a sé, in `extensions/<slug>/<slug>.php`, con l'header
`Update URI: https://github.com/hellobarrio/suite-barrio`, lo stesso di Barrio Suite (`Extensions::REPO`;
`ExtensionsTest` controlla che coincidano). Una cartella nuova con quell'header entra da sola nella
release successiva.

1. `npm run release` prepara gli zip di Barrio Suite e delle estensioni e `catalog.json`, con nome,
   descrizione, versione e requisiti presi dagli header dei plugin. WordPress propone un aggiornamento
   solo se la versione sale: prima si alza `Version` nei plugin cambiati.
2. Si caricano come asset di una nuova release del repository GitHub, con il tag (`v0.1.0`…) sul
   branch `stable`. Ogni sito legge il catalogo dell'ultima release
   (`…/releases/latest/download/catalog.json`, tenuto in cache un'ora).
3. Nei siti, Barrio → Extensions elenca le estensioni con Installa, Attiva, Disattiva e
   Aggiorna, usando l'installatore di WordPress; servono le capability `install_plugins`,
   `update_plugins` e `activate_plugins` (con `DISALLOW_FILE_MODS` restano solo attiva e disattiva).
   Gli aggiornamenti di Barrio Suite e delle estensioni compaiono anche in Bacheca → Aggiornamenti,
   come quelli degli altri plugin, compresi gli aggiornamenti automatici.

Uno zip si può sempre caricare a mano da Plugin → Aggiungi nuovo plugin → Carica plugin (la pagina Estensioni ha il
link): è la strada anche sugli hosting dove WordPress non può scrivere i file senza credenziali FTP.

## Quando registrarsi

```php
add_action( 'barrio_forms_loaded', static function (): void {
	barrio_forms_register_integration( 'mailup', [ /* … */ ] );
} );
```

`barrio_forms_loaded` parte su `init` (priorità 5), solo se Barrio Suite è attivo e il suo database è
aggiornato. Le funzioni `barrio_forms_*` esistono solo da lì in poi, quindi un'estensione senza
Barrio Suite non fa niente e non dà errori. Su `init` le stringhe tradotte (`__()`) delle etichette
funzionano: prima WordPress segnala le traduzioni caricate troppo presto.

Hook e filtri che servono solo con Barrio Suite (per esempio `barrio_forms_spam_check`) si aggiungono
dentro `barrio_forms_loaded`, come fa `barrio-suite-recaptcha`.

## Integrazioni

Il concetto di *feed* di Gravity Forms: un servizio che riceve ogni registrazione, con impostazioni
globali (scheda Impostazioni) e per form (scheda Integrazioni del builder). Le impostazioni si
dichiarano come JSON Schema: le pagine admin ne ricavano i controlli e il server valida con lo stesso
schema prima di salvare.

```php
barrio_forms_register_integration( 'mailchimp', [
	'label'           => 'Mailchimp',
	'description'     => __( 'Adds who fills in the form to a Mailchimp audience.', 'barrio-suite-mailchimp' ),
	'global_settings' => [
		'api_key' => [ 'format' => 'password', 'title' => __( 'API key', 'barrio-suite-mailchimp' ) ],
	],
	'form_settings'   => [
		'list_id'      => [ 'type' => 'string', 'translatable' => true, 'title' => __( 'Audience ID', 'barrio-suite-mailchimp' ) ],
		'email_field'  => [ 'format' => 'field:email', 'title' => __( 'Email field', 'barrio-suite-mailchimp' ) ],
		'merge_fields' => [ 'format' => 'field_map', 'title' => __( 'Merge fields', 'barrio-suite-mailchimp' ) ],
	],
	'process'         => 'barrio_mailchimp_subscribe',
] );
```

| Chiave | |
|---|---|
| slug (primo argomento) | `^[a-z][a-z0-9_-]*$`, al massimo 64 caratteri. Identifica le impostazioni salvate e la fonte delle note |
| `label`, `description` | Il nome e una frase, già tradotti: titolo e descrizione delle card |
| `global_settings` | Proprietà JSON Schema delle impostazioni globali. Facoltative: senza, nessuna card nelle Impostazioni |
| `form_settings` | Proprietà JSON Schema delle impostazioni per form. Con queste l'integrazione si aggiunge ai form e serve `process` |
| `process` | `callable( Entry $entry, Form $form, array $settings, array $global ): true\|WP_Error` |

Ogni proprietà è una stringa (`type: string`), con `title` e `description` per l'etichetta e l'aiuto:
nelle pagine admin diventa un campo di testo. Interruttori, numeri e tendine si aggiungeranno quando
un'estensione ne avrà bisogno. Formati del plugin, per cui basta `format`:

| `format` | Valore | Controllo |
|---|---|---|
| `password` | stringa, solo nelle impostazioni globali (in quelle per form non sarebbe cifrata) | Campo password. Si salva cifrato (`Crypto`, chiave dai salt di WordPress), non torna mai dalla REST e vuoto vuol dire "resta quello salvato" |
| `field` | la chiave di un campo del form | Tendina dei campi |
| `field:email`, `field:consent,checkbox` | la chiave di un campo di quei tipi | Tendina dei soli campi di quei tipi |
| `field_map` | lista di coppie `[nome nel servizio, chiave del campo]` | Righe con un testo e un campo, come le scelte |

`translatable: true` (solo per le stringhe) rende un'impostazione traducibile: nel builder si cambia
scegliendo la lingua nella barra, come i testi dei campi. Vuota in una lingua vale quella della lingua
principale.

### Dopo l'invio

`process()` parte dopo le notifiche (`barrio_forms_after_submission`, priorità 20), solo se
l'integrazione è attiva nel form e la sua logica condizionale (la stessa dei campi e delle notifiche)
è vera. Riceve:

- `$entry`, la registrazione salvata (con `id`), e `$form`;
- `$settings`, le impostazioni del form, quelle traducibili nella lingua della registrazione;
- `$global`, le impostazioni globali con i segreti in chiaro (un segreto che non si legge più è `''`).

Gira nella lingua di chi ha compilato (`switch_to_locale()`), come le email. I valori si leggono con
`MergeTags::of( $entry, $form )`: `->values` sono i valori grezzi per chiave del campo
(`list<string>`, per i file i nomi), `->fields` quelli compilati come li legge una persona
(`['label' => …, 'value' => …]`, per le scelte l'etichetta).

`true` scrive "Sent to Mailchimp." nello storico della registrazione; un `WP_Error`, o un'eccezione,
"Could not send to Mailchimp: …" con il messaggio. Un errore non ferma l'invio né le altre integrazioni.
Le note sono nella lingua del sito. Le chiamate HTTP devono avere un timeout breve (10 secondi): l'invio
le aspetta.

### Dove finiscono le impostazioni

- Globali: nell'opzione `barrio_forms_integrations` (`[slug => [nome => valore]]`), scritta solo da
  `PUT /barrio/v1/settings` (`manage_options`). Un'estensione le legge con
  `barrio_forms_integration_settings( 'slug' )`.
- Per form: nelle tabelle `form_integrations` e `form_integrations_i18n` (migrazione `0003`,
  [`docs/database.md`](database.md)), nel formato del builder (`integrations` di `GET /forms/{id}?context=edit`).
  Come le notifiche non sono versionate: si sostituiscono a ogni salvataggio.
- Con l'estensione disattivata le impostazioni restano: il builder le mostra in una card che lo dice,
  e tornano attive con l'estensione.

## Tipi di campo

```php
barrio_forms_register_field_type( 'tax_code', [
	'label'     => __( 'Tax code', 'my-extension' ),
	'schema'    => [ 'type' => 'object', 'properties' => [], 'additionalProperties' => false ],
	'render'    => fn ( Field $field, array $text, string $id ): string => /* etichetta e controllo */,
	'normalize' => fn ( Field $field, mixed $raw ): array => [ strtoupper( trim( (string) $raw ) ) ],
	'validate'  => fn ( Field $field, string $value ): ?string => /* messaggio di errore o null */,
] );
```

Solo `label` è obbligatoria. Lo slug è `^[a-z][a-z0-9_]*$`, al massimo 32 caratteri, e non può essere un
tipo core. `schema` è il JSON Schema della config del campo (default: nessuna proprietà): il builder ne
ricava il pannello (testi e numeri) e il server lo valida a ogni salvataggio. Senza `render` il campo è
un testo su una riga; `render` riceve i testi nella lingua della pagina e l'`id` da usare nell'etichetta
(`for`) e per l'errore (`aria-describedby="{$id}-error"`); il controllo deve avere `name` uguale alla
chiave del campo. Senza `normalize` il valore è una riga di testo; `validate` riceve ogni valore non
vuoto (oltre 255 caratteri il valore è già rifiutato). Un form che usa un tipo di un'estensione
disattivata mostra un campo di testo e non si salva finché il campo non si toglie.

## Hook

| Hook | Tipo | Argomenti | |
|---|---|---|---|
| `barrio_forms_loaded` | azione | — | Su `init`: il momento per registrarsi |
| `barrio_forms_render` | azione | `Form` | Un form sta per comparire nella pagina (blocco o shortcode): per gli script di un captcha |
| `barrio_forms_spam_check` | filtro | `?WP_Error`, `array $input`, `Form` | Dopo honeypot e limite per IP. Restituire un `WP_Error` (con `status`) rifiuta l'invio; se arriva già un errore, va restituito così com'è |
| `barrio_forms_validate` | filtro | `array $errors`, `array $values`, `Form` | Errori per chiave del campo, dopo quelli dei tipi; `$values` sono i valori accettati (`list<string>` per chiave) |
| `barrio_forms_entry_data` | filtro | `Entry`, `Form` | La registrazione prima del salvataggio (senza `id`) |
| `barrio_forms_after_submission` | azione | `Entry`, `Form` | Dopo il salvataggio: notifiche (10) e integrazioni (20). Un'eccezione finisce nelle note |
| `barrio_forms_notification` | filtro | `array $email`, `Notification`, `Entry` | `to`, `subject`, `heading`, `body` (HTML), `reply_to`, `attachments` prima dell'invio |
| `barrio_forms_rest_response` | filtro | `array $response`, `Entry`, `Form` | La risposta dell'invio: `message` e `redirect` |
| `barrio_forms_presets` | filtro | `array $presets` | Scelte pronte del builder: `'id' => [ 'label' => …, 'choices' => [ [ 'valore', 'Etichetta' ], … ] ]` |
| `barrio_forms_languages` | filtro | `list<string>` | Codici delle lingue attive, la principale per prima |
| `barrio_forms_current_language` | filtro | `?string` | La lingua della pagina |
| `barrio_forms_locale` | filtro | `string $locale`, `string $lang` | Il locale di WordPress di una lingua |
| `barrio_forms_language_flags` | filtro | `array $flags` | URL della bandiera per codice, per l'admin |

## Nel browser

Il form è lo script module `@barrio/forms`. Uno script di un'estensione che ne ha bisogno è a sua volta
un module che lo importa, così parte dopo (`import '@barrio/forms';`, con `@barrio/forms` tra le
dipendenze di `wp_enqueue_script_module()`):

- `window.barrioForms.beforeSubmit( fn )`: `fn( form, data )` può aggiungere valori a `data`
  (`FormData`) e restituire una Promise; se la Promise è rifiutata (o `fn` lancia un errore), il form
  mostra il suo messaggio d'errore e non invia. Meglio una funzione `async`: un errore lanciato dentro
  un callback (come fa Google con una chiave non valida) altrimenti lascerebbe il form in attesa.
- Eventi `barrio:success` e `barrio:error` sul form, con la risposta in `detail` (per esempio per GTM).

I valori che un'estensione aggiunge all'invio iniziano con `_` (`_barrio_recaptcha`): le chiavi dei campi
non possono, quindi non si scontrano con un campo. Un frontend headless deve inviarli da sé.

## Database

Un'estensione con tabelle proprie usa il Migrator del core, con le stesse regole (`docs/database.md`):
file `NNNN_nome.php` che restituiscono `static function ( wpdb $wpdb ): void`, idempotenti, e la versione
raggiunta nell'opzione `barrio_{nome}_db_version`.

```php
add_action( 'barrio_forms_loaded', static function (): void {
	( new BarrioSuite\Database\Migrator( 'mailup', __DIR__ . '/migrations' ) )->run();
} );
```

Per le impostazioni di un'integrazione non serve: le salva il core.
