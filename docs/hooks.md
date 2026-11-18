# API per le estensioni

Le estensioni sono plugin separati che usano solo l'API qui sotto: il core non contiene codice per
plugin o servizi di terzi. Cinque esempi completi, in `extensions/`:

| Estensione | Cosa mostra |
|---|---|
| [`barrio-suite-mailchimp`](../extensions/barrio-suite-mailchimp/barrio-suite-mailchimp.php) | Un'integrazione con impostazioni globali (la chiave API) e per form, anche tradotte, e `process()` |
| [`barrio-suite-recaptcha`](../extensions/barrio-suite-recaptcha/barrio-suite-recaptcha.php) | Un captcha: solo impostazioni globali, `barrio_forms_render`, `beforeSubmit` nel browser e `barrio_forms_spam_check`; il filtro `barrio_recaptcha_threshold` cambia il punteggio minimo (0.5) |
| [`barrio-suite-wpml`](../extensions/barrio-suite-wpml/barrio-suite-wpml.php) | Le lingue da un plugin multilingua, con i filtri `barrio_languages` e seguenti |
| [`barrio-suite-wpgraphql`](../extensions/barrio-suite-wpgraphql/barrio-suite-wpgraphql.php) | Un'altra porta per l'invio e per il SEO: lo schema GraphQL del prototipo sopra `FormRepository`, `Submission::process()` e `Seo\Meta`, e un'impostazione nella pagina di un altro plugin (vedi [GraphQL](#graphql)) |
| [`barrio-suite-migration`](../extensions/barrio-suite-migration/barrio-suite-migration.php) | Un'importazione: legge le tabelle di un altro plugin e scrive con `FormRepository::create()` (con l'ID) ed `EntryRepository::create()` (con data e stato di lettura), in un comando WP-CLI (vedi [Migrazione dal prototipo](#migrazione-dal-prototipo)) |

I test delle estensioni sono in `tests/Integration/` (`MailchimpTest`, `RecaptchaTest`, `WpmlTest`,
`WpGraphqlTest`, `SeoGraphqlTest`, `MigrationTest`): le risposte dei servizi si simulano con `pre_http_request`. I test GraphQL girano solo con
WPGraphQL, come nella riga WPGraphQL della CI: in locale si installa una volta nell'ambiente dei test
(`npx wp-env run tests-cli wp plugin install wp-graphql`) e si lancia `npm run test:php:graphql`.

## Pubblicazione e installazione

Ogni estensione è un plugin a sé, in `extensions/<slug>/<slug>.php`, con `Requires Plugins: barrio-suite`:
WordPress non la attiva senza Barrio Suite. Il suo text domain è lo slug, anche per le stringhe uguali a quelle
del core. Come si pubblicano e da dove le installa Barrio → Extensions dipende dal branch: lo dice
[`docs/release.md`](release.md).

## Quando registrarsi

```php
add_action( 'barrio_forms_loaded', static function (): void {
	barrio_forms_register_integration( 'mailup', [ /* … */ ] );
} );
```

`barrio_forms_loaded` parte su `init` (priorità 5), solo se Barrio Suite è attivo, il modulo Forms è
acceso (Barrio → Impostazioni → Moduli) e il suo database è aggiornato. Le funzioni `barrio_forms_*` esistono solo da lì in poi, quindi un'estensione senza
Barrio Suite non fa niente e non dà errori. Su `init` le stringhe tradotte (`__()`) delle etichette
funzionano: prima WordPress segnala le traduzioni caricate troppo presto.

Hook e filtri che servono solo con Barrio Suite (per esempio `barrio_forms_spam_check`) si aggiungono
dentro `barrio_forms_loaded`, come fa `barrio-suite-recaptcha`.

Un'estensione che lavora con più moduli ha un file per modulo (`forms.php`, `seo.php`), ognuno con la sua
funzione `barrio_<estensione>_<modulo>_boot()` che aggiunge gli hook del modulo. Il file principale ha solo
l'intestazione, i `require` e un aggancio a `barrio_<modulo>_loaded` per modulo, con lo stesso controllo sul
plugin di terzi: così un modulo spento non registra niente e i moduli non si mescolano. Esempio:
`barrio-suite-wpgraphql`. Il plugin di terzi si controlla lì e non quando si carica il file, perché WordPress
carica i plugin in ordine alfabetico e il suo può arrivare dopo.

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

## Hook

| Hook | Tipo | Argomenti | |
|---|---|---|---|
| `barrio_forms_loaded` | azione | — | Su `init`: il momento per registrarsi |
| `barrio_forms_render` | azione | `Form` | Un form sta per comparire nella pagina (blocco o shortcode): per gli script di un captcha |
| `barrio_forms_spam_check` | filtro | `?WP_Error`, `array $input`, `Form` | Dopo honeypot e limite per IP. Restituire un `WP_Error` (con `status`) rifiuta l'invio; se arriva già un errore, va restituito così com'è |
| `barrio_forms_rate_limit` | filtro | `int $limit`, `string $ip` | Invii per IP ogni 10 minuti (10), compresi quelli respinti dall'antispam; 0 toglie il limite, per esempio dietro un proxy che non ripristina l'IP del visitatore |
| `barrio_forms_entry_data` | filtro | `Entry`, `Form` | La registrazione prima del salvataggio (senza `id`) |
| `barrio_forms_after_submission` | azione | `Entry`, `Form` | Dopo il salvataggio: notifiche (10) e integrazioni (20). Un'eccezione finisce nelle note |
| `barrio_forms_notification` | filtro | `array $email`, `Notification`, `Entry` | `to`, `subject`, `heading`, `body` (HTML), `reply_to`, `attachments` prima dell'invio |
| `barrio_forms_presets` | filtro | `array $presets` | Scelte pronte del builder: `'id' => [ 'label' => …, 'choices' => [ [ 'valore', 'Etichetta' ], … ] ]`; con `'intl' => 'region'` (o `language`, `weekday`, `month`) le etichette vuote le scrive il builder con `Intl`, nella lingua principale del form |
| `barrio_languages` | filtro | `list<string>` | Codici delle lingue attive, la principale per prima. Valgono per tutti i moduli (`src/I18n.php`) |
| `barrio_current_language` | filtro | `?string` | La lingua della pagina |
| `barrio_locale` | filtro | `string $locale`, `string $lang` | Il locale di WordPress di una lingua |
| `barrio_switch_language` | azione | `?string $lang` | Per il codice che segue, i contenuti della lingua (query, permalink, nome del sito); `null` torna alla lingua di prima. La chiama `I18n::in()`, che cambia anche il locale: llms.txt, la tabella SEO → Contenuti e i conteggi della sitemap la usano una lingua alla volta |
| `barrio_language_flags` | filtro | `array $flags` | URL della bandiera per codice, per l'admin |
| `barrio_launch_steps` | filtro | `array<string, bool> $steps` | Messa online (`src/Launch.php`): per passaggio, se è configurato secondo le impostazioni vere. Un passaggio per modulo (Forms: `forms`) più quelli del sito (`smtp`, `timezone`, `search`); un passaggio nuovo compare nella guida solo se `assets/src/launch/Launch.js` sa come configurarlo |
| `barrio_admin_data` | filtro | `array $data` | Per i moduli: i dati delle pagine Barrio, in `window.barrioAdmin` (`src/Admin.php`). Forms aggiunge `forms`; senza, il JavaScript sa che il modulo è spento |
| `barrio_seo_loaded` | azione | — | Su `init` (priorità 5), solo con il modulo SEO acceso: il momento per collegarsi al SEO, come `barrio_forms_loaded`. L'estensione WPML vi aggiunge a robots.txt le sitemap delle altre lingue |
| `barrio_seo_url` | filtro | `string $url` | L'URL di una pagina in ogni punto in cui il modulo SEO lo scrive (`Seo\Meta::url()`: canonical, `og:url`, JSON-LD, llms.txt); le immagini restano su WordPress. Serve a portare gli URL su un frontend headless. La canonical dei singoli nell'head la stampa il core e non passa da qui |
| `barrio_seo_changed` | azione | `string $what`, `int $id = 0` | Sono cambiati le impostazioni SEO (`'settings'`), i redirect (`'redirects'`, anche quelli automatici) o i meta SEO di un contenuto (`'post'`) o di un termine (`'term'`) con il suo ID, una volta per richiesta: per svuotare la cache di un frontend |

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

## GraphQL

`barrio-suite-wpgraphql` espone i form con lo schema del prototipo, `hbForm(id, lang)`, `hbForms` e
`hbSubmitForm`, con gli stessi nomi e tipi (`tests/fixtures/wpgraphql-schema.txt`, stampato dal prototipo):
i frontend Next.js del prototipo funzionano senza modifiche. Senza WPGraphQL non registra niente.

- **Precedenza sul prototipo**: registra lo schema con priorità 9, prima del prototipo (10), che registra gli
  stessi nomi anche con il suo modulo Forms disattivato. WPGraphQL tiene la prima registrazione e scarta le
  altre (`DUPLICATE_TYPE` nel suo debug), quindi con tutti e due attivi rispondono i form di Barrio Suite.
- **Consenso**: i frontend del prototipo non conoscono il tipo `consent` (`hello_standard` non lo mostra), quindi
  arriva come la loro checkbox della privacy: `type: checkbox`, etichetta vuota e una scelta con valore `1` ed
  etichetta il testo con il link; `"Etichetta: true"` torna come consenso dato.
- **Invio**: la stessa pipeline della REST. I valori arrivano nel formato del prototipo e diventano quelli
  della pipeline: checkbox come `"Etichetta: true | Etichetta: false"` (etichette nella lingua del form) o
  valori separati da virgole, date come `dd/MM/yyyy` (il `format` del campo), file come JSON
  `{"content": base64, "filename": "…"}` scritto in un file temporaneo che passa da `FileStore::check()`.
  `pageUrl` diventa la pagina della registrazione (senza query string); `pageTitle` e `pageReferrer` non si
  salvano. I valori per le estensioni stanno tra i campi (`{name: "_barrio_recaptcha", value: token}`).
- **Esito**: un invio rifiutato torna con `success: false`, `message` e `errors` (`"Etichetta: errore"`, uno
  per campo), come nel prototipo; un'eccezione la gestisce WPGraphQL, con il messaggio solo nel suo debug
  (`debug` resta nello schema, sempre `null`).
- **Antispam**: un client GraphQL non ha il segno del JavaScript, quindi restano il limite per IP e i
  captcha. Il limite vale solo per le richieste anonime: un frontend con l'application password invia dal
  suo server, con lo stesso IP per tutti i visitatori. La registrazione non salva l'utente della richiesta
  (è quello del frontend, non chi compila).
- **`hbForms`**: come `GET /barrio/v1/forms`, solo per chi può modificare gli articoli o gestire le
  registrazioni (nel prototipo era pubblico); senza permesso è un errore GraphQL.

### SEO

Con il modulo SEO acceso (`barrio_seo_loaded`) l'estensione registra lo schema SEO del prototipo, con gli stessi
nomi e tipi (`tests/fixtures/wpgraphql-seo-schema.txt`), anch'esso con priorità 9: con il prototipo attivo
rispondono i dati di Barrio Suite, anche per il campo `seo` sui suoi stessi tipi (WPGraphQL tiene il primo campo
registrato e scarta l'altro, `DUPLICATE_FIELD` nel suo debug). Con il modulo spento non registra niente e, se è
attivo, risponde il prototipo. I dati sono quelli dell'head, della sitemap e di llms.txt di WordPress: nessuna
logica a parte.

- **`seo`** (`HBSeoPost`) su ogni post type pubblico in GraphQL: `Seo\Meta::post()`. `title` è senza il nome del
  sito, `robots` è `index,follow` e simili, `ogType` è `article` solo per gli articoli (nel prototipo sempre),
  `isInSitemap` vale per i contenuti pubblicati, indicizzati e senza password.
- **`hbSeoSite`**: nome del sito, separatore, ordine, descrizione e immagine predefinite, icona del sito.
  `robotsDefault` è sempre `index,follow`: l'indicizzazione è per tipo e per contenuto.
- **`hbSchemaRaw(uri)`**: il JSON-LD di `Seo\Schema`, con `JSON_HEX_TAG` perché finisce in un `<script>`. L'URI si
  risolve come `nodeByUri`; con un contenuto o un termine indicizzabile ci sono anche la pagina e il breadcrumb,
  altrimenti (senza `uri`, archivi, noindex, 404) solo organizzazione e sito.
- **`hbSitemap`**: gli URL di `wp-sitemap.xml` in tutte le lingue (`Seo\Sitemaps::each()`, gli stessi che conta
  SEO → Impostazioni), anche con "Scoraggia i motori di ricerca", perché il WordPress di un sito headless spesso
  non va indicizzato. `loc` è relativo; `type` è `post`, `term` o `user` (gli archivi degli autori, se
  indicizzati); `priority` è sempre `null`.
- **`hbRedirects`**: tutti i redirect. `from` ha la `/` finale dei permalink (le sorgenti nel database non ce
  l'hanno), perché i frontend del prototipo la confrontano con il percorso della pagina; non l'hanno quelle con
  la query string o con un'estensione (`/pagina.html`). Un 410 ha `to` null. `note` solo per chi ha
  `barrio_manage_seo` (nel prototipo era pubblica).
- **`hbLlms`, `hbLlmsFull`**: il testo di `Seo\Llms` dalla sua cache, con `updatedAt` (timestamp Unix) del momento
  in cui è stato generato.
- **Indirizzo del frontend**: un campo nelle impostazioni generali di WPGraphQL (GraphQL → Settings,
  `get_graphql_setting( 'barrio_frontend_url' )`). Se c'è, durante le richieste GraphQL il filtro `barrio_seo_url`
  porta su quell'indirizzo gli URL delle pagine (canonical, JSON-LD, llms.txt); le immagini restano su WordPress,
  e l'head e il `llms.txt` di WordPress non cambiano. La cache di llms.txt ha una copia per indirizzo.
- **Lingue**: i testi predefiniti (la descrizione, quella dell'organizzazione) sono nella lingua della richiesta, non
  in quella del contenuto, perché il core non sa la lingua di un contenuto.

## Migrazione dal prototipo

`barrio-suite-migration` porta su Barrio Suite il modulo Forms di Suite Barrio (il prototipo), un sito alla volta:

```sh
wp barrio forms migrate --dry-run   # tutta l'importazione e poi il rollback: conteggi e avvisi, niente salvato
wp barrio forms migrate
```

- **Prima**: spegnere il modulo Forms del prototipo (Suite Barrio → Strumenti), altrimenti ciò che riceve dopo
  resta lì; sui siti WPML attivare `barrio-suite-wpml`, che dà la lingua principale; per le chiavi di Mailchimp e
  reCAPTCHA attivare le loro estensioni, perché i segreti si cifrano con il loro schema. Il comando si può
  rilanciare: salta i form già presenti e copia le impostazioni solo dove mancano.
- **Form**: lo stesso ID, così shortcode e frontend headless restano validi. Se l'ID è già di un altro form di
  Barrio Suite è un errore (quel form prenderebbe il posto di questo nelle pagine): va risolto prima di
  continuare. I form migrati si ricordano nell'opzione `barrio_migration_forms` e al giro dopo si saltano. Ogni form passa in una transazione con le sue registrazioni; prima del commit i conteggi del
  prototipo e di Barrio Suite si confrontano nel database e, se non tornano, si annulla il form. La versione 1 è
  l'archivio, con anche i campi che esistono solo nelle registrazioni vecchie (testo lungo o file); la 2, solo
  se diversa, è la definizione attuale.
- **Campi**: le chiavi diventano `^[a-z][a-z0-9_]*$` (il comando segnala quelle cambiate), e merge tag, regole e
  integrazioni le seguono. Il valore di una scelta è la sua etichetta nella lingua principale senza HTML, cioè
  quello che il prototipo salvava; le traduzioni (per posizione) diventano righe `_i18n` complete. La checkbox della privacy,
  con il link nella sua unica scelta, diventa un campo Consenso con il link (anche nei frontend GraphQL, vedi
  sopra); nelle altre scelte l'HTML si toglie e il comando lo segnala.
- **Registrazioni**: checkbox da array o da `"Etichetta: true | …"` e scelte tradotte diventano valori, le date
  `dd/mm/yyyy` diventano `Y-m-d`, `_page_url` diventa `source_url` senza query string, la data passa in UTC
  (`UNIX_TIMESTAMP()` nel fuso della sessione MySQL) e sono tutte già lette. IP, user agent, referrer e titolo
  della pagina non si importano. I file passano dagli upload alla cartella protetta: copia, commit, poi
  eliminazione dell'originale; uno che non c'è più finisce in `missing_files`.
- **Notifiche**: destinatario, risposta a, oggetto, titolo e testo (o `{{all_fields}}`, con `{{source_url}}` se
  il form mostrava la pagina), con gli allegati se il form ha un campo file, come nel prototipo. Mittente e Bcc
  per notifica non esistono più: il comando li segnala e vale il mittente dell'SMTP.
- **Impostazioni globali**: Mailjet diventa l'SMTP `in-v3.mailjet.com:587` con le stesse chiavi (solo se l'SMTP è
  vuoto; il mittente è quello delle notifiche o l'email dell'amministratore, come nel prototipo), poi le chiavi di
  Mailchimp e reCAPTCHA, logo e colori delle email del form modificato per ultimo, la retention (anni × 365).
- **Integrazioni per form**: Mailchimp con le impostazioni della sua estensione. MailUp, che non ha ancora
  un'estensione, si salva come `mailup` con `email_field` e `merge_fields` come Mailchimp, `list_id` e
  `group_id` tradotti, e l'opt-in come condizione `not_empty` (il prototipo iscriveva solo chi spuntava):
  sono le impostazioni che l'estensione MailUp dovrà dichiarare.
- **Shortcode**: quando tutti i form sono in Barrio Suite, il comando riscrive `[suite_barrio_form` e `[wpb_form`
  in `[barrio_form` con `wp search-replace`, nelle tabelle di WordPress: contenuti, dati dei page builder e dei
  widget, anche serializzati (con `--dry-run` mostra solo quante sostituzioni farebbe). Gli attributi, cioè l'ID,
  restano. Non tocca i file del tema: uno shortcode scritto lì (`do_shortcode()`) continua a funzionare finché
  l'estensione è attiva, che li registra quando il prototipo non li usa.
- **Dopo**: spento il modulo Forms del prototipo e svuotata la cache delle pagine, l'estensione si può eliminare.
  Il prototipo solo se il sito non usa nient'altro di suo: è anche popup, banner, cookie banner con il registro
  dei consensi, SEO, orari, dati aziendali, llms.txt, accessibilità, e i frontend come `hello_standard` leggono
  da lui `hbPopup`, `hbBanner`, `hbOrari`, `hbCompanyInfo`, `hbSeoSite`, `hbSchemaRaw`, `hbRedirects`,
  `hbSitemap`, `hbLlms`. Le tabelle `wpb_forms`, `wpb_form_submissions` e `wpb_forms_translations` e le opzioni
  `suite_barrio_forms_*` restano: contengono le registrazioni vecchie, con IP e dati personali, quindi si
  eliminano a mano appena verificata la migrazione (`wp db query "DROP TABLE …"`).

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
