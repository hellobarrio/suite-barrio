# API per le estensioni

Le estensioni sono plugin separati che usano solo l'API qui sotto: il core non contiene codice per
plugin o servizi di terzi. Cinque esempi completi, in `extensions/`:

| Estensione | Cosa mostra |
|---|---|
| [`barrio-suite-mailchimp`](../extensions/barrio-suite-mailchimp/barrio-suite-mailchimp.php) | Un'integrazione con impostazioni globali (la chiave API) e per form, anche tradotte, e `process()` |
| [`barrio-suite-recaptcha`](../extensions/barrio-suite-recaptcha/barrio-suite-recaptcha.php) | Un captcha: solo impostazioni globali (la chiave del sito, da una tendina con `options`), il service account del core, `barrio_forms_render`, `beforeSubmit` nel browser e `barrio_forms_spam_check`; il filtro `barrio_recaptcha_threshold` cambia il punteggio minimo (0.5) |
| [`barrio-suite-wpml`](../extensions/barrio-suite-wpml/barrio-suite-wpml.php) | Le lingue da un plugin multilingua, con i filtri `barrio_languages` e seguenti |
| [`barrio-suite-wpgraphql`](../extensions/barrio-suite-wpgraphql/barrio-suite-wpgraphql.php) | Un'altra porta per i moduli: lo schema GraphQL `hb*` sopra `FormRepository`, `Submission::process()`, `Seo\Meta` e i dati degli altri moduli, un file per modulo, e la card Frontend headless di Barrio → Impostazioni, con le sue rotte e la revalidation (vedi [GraphQL](#graphql)) |
| [`barrio-suite-migration`](../extensions/barrio-suite-migration/barrio-suite-migration.php) | Un'importazione: legge le tabelle e le opzioni di un altro plugin e scrive con `FormRepository::create()` (con l'ID), `EntryRepository::create()` (con data e stato di lettura), `Seo\Settings::save()`, `RedirectRepository::create()`, `PopupRepository::save()` e `BannerRepository::save()` e le opzioni dei moduli, da Barrio → Migration (rotte `barrio/v1/migration`); tiene spento un modulo finché i dati non sono migrati (vedi [Migrazione dal prototipo](#migrazione-dal-prototipo)) |

I test delle estensioni sono in `tests/Integration/` (`MailchimpTest`, `RecaptchaTest`, `WpmlTest`,
`WpGraphqlTest`, `SeoGraphqlTest`, `ModulesGraphqlTest`, `MigrationTest`, `SeoMigrationTest`, `ModulesMigrationTest`): le risposte dei servizi si simulano con `pre_http_request`. I test GraphQL girano solo con
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
nelle pagine admin diventa un campo di testo. Con `enum` diventa una tendina delle sole scelte elencate, e
`labels` (`valore => etichetta`, già tradotta) dà il testo di ognuna; senza `labels` si vede il valore, e la
prima voce, vuota, vale "nessuna scelta" (come lo `status` di `barrio-suite-mailchimp`). Con `type: boolean`
diventa un interruttore, con `type: number` un campo numerico. Formati del plugin, per cui basta `format`:

| `format` | Valore | Controllo |
|---|---|---|
| `password` | stringa, solo nelle impostazioni globali (in quelle per form non sarebbe cifrata) | Campo password. Si salva cifrato (`Crypto`, chiave dai salt di WordPress), non torna mai dalla REST e vuoto vuol dire "resta quello salvato" |
| `field` | la chiave di un campo del form | Tendina dei campi |
| `field:email`, `field:consent,checkbox` | la chiave di un campo di quei tipi | Tendina dei soli campi di quei tipi |
| `field_map` | lista di coppie `[nome nel servizio, chiave del campo]` | Righe con un testo e un campo, come le scelte |

`options` (il percorso di una rotta REST, come `/barrio/v1/recaptcha/keys`) rende una stringa una tendina con le
scelte che la rotta restituisce come `[{ value, label }]`, lette all'apertura della pagina: per le scelte che vengono da
un servizio. Un errore della rotta compare sotto la tendina, e il valore salvato resta anche se non è più nell'elenco.
Un'estensione che parla con Google usa il service account del core: `BarrioSuite\Google::account()` (null senza) e
`Google::token( $scope )`, con la cache del token, come fa `barrio-suite-recaptcha`.

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
| `barrio_popups_loaded`, `barrio_banners_loaded`, `barrio_hours_loaded`, `barrio_company_loaded` | azione | — | Su `init` (priorità 5), solo con il modulo acceso: il momento per collegarsi a Popup, Banner, Orari e Dati aziendali, come `barrio_forms_loaded`. L'estensione WPGraphQL vi registra `hbPopup`, `hbBanner`, `hbOpeningHours` e `hbCompanyInfo` |
| `barrio_seo_url` | filtro | `string $url` | L'URL di una pagina in ogni punto in cui il modulo SEO lo scrive (`Seo\Meta::url()`: canonical, `og:url`, JSON-LD, llms.txt); le immagini restano su WordPress. Serve a portare gli URL su un frontend headless. La canonical dei singoli nell'head la stampa il core e non passa da qui |
| `barrio_seo_changed` | azione | `string $what`, `int $id = 0` | Sono cambiati le impostazioni SEO (`'settings'`), i redirect (`'redirects'`, anche quelli automatici) o i meta SEO di un contenuto (`'post'`) o di un termine (`'term'`) con il suo ID, una volta per richiesta: per svuotare la cache di un frontend |
| `barrio_hours_changed` | azione | — | Sono cambiati gli orari (`barrio_hours`): un salvataggio, o la sincronizzazione con Google, anche quella di ogni notte. Per svuotare la cache di un frontend |
| `barrio_company_changed` | azione | — | Sono cambiati i Dati aziendali (`barrio_company`). Per svuotare la cache di un frontend, anche quella dello schema del SEO, che ne prende nome, contatti e indirizzo |
| `barrio_forms_changed` | azione | `int $id` | È cambiato un form: salvato, chiuso, cestinato, ripristinato o eliminato. Per svuotare la cache di un frontend |
| `barrio_popups_changed` | azione | — | È cambiato un popup: creato, modificato, acceso, spento, duplicato o eliminato. Per svuotare la cache di un frontend |
| `barrio_banners_changed` | azione | — | È cambiato un banner: creato, modificato, acceso, spento, duplicato o eliminato. Per svuotare la cache di un frontend |
| `barrio_ordering_changed` | azione | `string $kind`, `string $name` | È cambiato l'ordine manuale di un tipo di contenuto (`'post'`, `'page'`) o di una tassonomia (`'term'`, `'category'`): un nuovo ordine salvato, o l'ordine manuale acceso o spento. Per svuotare la cache di un frontend |
| `barrio_ordering_items` | filtro | `list<array{id, title, status, parent}> $items`, `string $kind`, `string $name` | Le voci della modale Riordina di un elenco, che si leggono senza i filtri delle query di WordPress: un plugin multilingua tiene quelle di una lingua (lo fa `barrio-suite-wpml` con la lingua dell'admin), e solo quelle si possono salvare |

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

Il popup della pagina (`@barrio/popups`) si apre anche con un link a `#barrio-popup-ID` o un clic su un elemento con
`data-barrio-popup="ID"`, anche se chi guarda l'aveva chiuso. Un link da un'altra pagina con quell'ancora lo apre
all'arrivo. Il tema cambia l'aspetto con le custom property di `.barrio-popup` (`--barrio-popup-background`,
`--barrio-popup-color`, `--barrio-popup-width`, `--barrio-popup-radius`).

## GraphQL

`barrio-suite-wpgraphql` mette in WPGraphQL form, SEO, popup, banner, orari e dati aziendali, un file per modulo,
ognuno solo con il suo modulo acceso (`barrio_<modulo>_loaded`). Senza WPGraphQL non registra tipi né campi: resta
il frontend headless (sotto), con le rotte, la card e la revalidation di menu e moduli. Lo schema ha
nomi in inglese con il prefisso `hb` (campi della radice `hb*`, tipi `Hb*`, come `gf*` e `GfForm` di Gravity Forms;
solo il campo `seo` dei contenuti e dei termini non ce l'ha, come in Yoast e Rank Math), `language` per la lingua,
`databaseId`, booleani con `is`, enum in maiuscolo che finiscono in `Enum` per gli insiemi chiusi, date ISO 8601 in
UTC. Non null le liste, i loro elementi, `databaseId`, booleani, enum e ciò che il resolver dà sempre; i campi della
radice restano nullable, così un errore non azzera il resto della risposta; nel dubbio, nullable. Una sola versione
dello schema: un campo rinominato o tolto non resta accanto al nuovo, e nessun `deprecationReason`. La fotografia dello schema è in `tests/fixtures/wpgraphql-*.txt`, stampata da
`WpGraphqlTest::sdl()`. I frontend del prototipo si portano con il [foglio di porting](graphql-porting.md), le cui
query si provano in `WpGraphqlTest`.

- **Precedenza sul prototipo**: ogni file registra con priorità 9, prima del prototipo (10), che registra molti
  degli stessi nomi anche con i suoi moduli spenti. WPGraphQL tiene la prima registrazione e scarta le altre
  (`DUPLICATE_TYPE` e `DUPLICATE_FIELD` nel suo debug) e confronta i nomi dei tipi senza maiuscole (`HbForm` vince
  su `HBForm`): con tutti e due attivi rispondono i dati di Barrio Suite, anche per il campo `seo`. I nomi che ha solo
  il prototipo (`hbOrari`, `hbSeoSite`, `hbSchemaRaw`) restano suoi.
- **Lingua**: `language` è una lingua attiva, altrimenti la principale.
- **Frontend headless** (`frontend.php`, collegato sempre, anche con i moduli spenti): l'indirizzo del frontend e il
  token nell'opzione `barrio_frontend` (`url`, `token`), con le rotte `GET` e `PUT /barrio/v1/frontend` e
  `POST /barrio/v1/frontend/clear` (`tags`, svuota a mano la cache di alcuni tag), tutte con `manage_options`. Le usa
  la card Frontend headless di Barrio → Impostazioni (`assets/src/general/Frontend.js`, nel core), che compare solo
  con `frontend` in `barrio_admin_data`, come la pagina Migrazione con `migration`. L'indirizzo è `https://` o
  `http://` con un host, anche con un percorso (il `basePath` di Next.js), senza query; scritto senza protocollo
  prende `https://`. Il token si salva com'è, in chiaro, e la `GET` lo restituisce: è un'eccezione voluta rispetto
  agli altri segreti, perché va copiato nel frontend e permette solo di svuotarne la cache; ammette lettere, numeri e
  `. _ ~ + / = -`. La revalidation
  di Next.js è quella del prototipo, con la stessa chiamata e gli stessi tag, così i frontend non cambiano: alla fine
  della richiesta, in una chiamata ogni 100 tag (quasi sempre una sola), `GET {frontend}/api/revalidate/?tag=a,b` con
  `Authorization: Bearer {token}` se c'è il token. Senza indirizzo nessuna chiamata. Con PHP-FPM la risposta a chi
  salva parte prima della chiamata (`fastcgi_finish_request()`), e un frontend che non risponde ferma le chiamate
  della richiesta. L'ultimo errore (il frontend non risponde, o risponde con un codice da 300 in su) resta in un
  transient (`barrio_frontend_error`) finché una chiamata non riesce: lo dà la `GET` in `error` e lo mostra la card.
  I tag:
  - un contenuto di un tipo `show_in_graphql` (non le immagini), se era o è pubblico:
    `wp:uri:{percorso con _ al posto di /},wp:type:{tipo},page_{id}`, la home `wp:uri:`; anche l'URI di prima, se uno
    slug, il cestino o una bozza lo tolgono. Bozze, revisioni e salvataggi automatici niente. Con WordPress in una
    sottocartella il percorso è senza quello della home, come l'`uri` di WPGraphQL;
  - il testo alternativo di un'immagine (`_wp_attachment_image_alt`), scritto a mano o generato: i tag del contenuto
    a cui è allegata; un'immagine non allegata niente;
  - un menu salvato o eliminato `wp:menu`;
  - `barrio_seo_changed`: `wp:seo,wp:schema,wp:sitemap,wp:llms`, `wp:redirects`, o per i meta di un contenuto
    pubblico i suoi tag e `wp:sitemap`;
  - `barrio_forms_changed` `form_{id}`, `barrio_hours_changed` `wp:orari`, `barrio_company_changed` `wp:option` (che
    nel frontend copre anche lo schema), `barrio_ordering_changed` `wp:type:{tipo}`;
  - `barrio_popups_changed` `wp:popup` e `barrio_banners_changed` `wp:banner`, anche a ogni inizio e fine futuri degli
    accesi, con un evento di WP-Cron (`barrio_wpgraphql_popups_period`, `barrio_wpgraphql_banners_period`).

  Il frontend controlla il token quando ha la variabile `REVALIDATE_TOKEN` ([foglio di porting](graphql-porting.md)
  §9), così si attiva sito per sito.

### Form

`hbForm(id, language)`, `hbForms` e `hbSubmitForm`.

- **`hbForm`**: `null` se il form non c'è o è nel cestino. `fields` sono tutti i campi, `steps` gli stessi divisi
  dalle interruzioni di pagina. `title` è interno: solo per chi vede l'elenco dei form.
- **Consenso**: i frontend headless non conoscono il tipo `consent` (`hello_standard` non lo mostra), quindi arriva
  come la checkbox della privacy del prototipo: `type: checkbox`, etichetta vuota e una scelta con valore `1` ed
  etichetta il testo con il link; `"Etichetta: true"` torna come consenso dato.
- **Invio**: la stessa pipeline della REST. I valori arrivano nel formato del prototipo e diventano quelli
  della pipeline: checkbox e select multiple (`isMultiple`) come `"Etichetta: true | Etichetta: false"` (etichette
  nella lingua del form) o valori separati da virgole, date come `dd/MM/yyyy` (il `format` del campo), file come JSON
  `{"content": base64, "filename": "…"}` scritto in un file temporaneo che passa da `FileStore::check()`.
  `pageUrl` diventa la pagina della registrazione (senza query string). I valori per le estensioni stanno tra i campi
  (`{name: "_barrio_recaptcha", value: token}`).
- **Esito**: un invio rifiutato torna con `success: false`, non come errore GraphQL. `errors` ne ha uno per campo,
  con `name` la chiave del campo e `message` senza l'etichetta, oppure uno solo con `name: null` per un errore del
  form (non trovato, chiuso, limite per IP); `message` è il primo errore con la sua etichetta. Un'eccezione la
  gestisce WPGraphQL, con il messaggio solo nel suo debug.
- **Antispam**: un client GraphQL non ha il segno del JavaScript, quindi restano il limite per IP e i
  captcha. Il limite non vale per le richieste di un utente con `edit_posts`: un frontend con l'application
  password invia dal suo server, con lo stesso IP per tutti i visitatori, e il suo utente deve avere quella
  capability (da Autore in su; un Sottoscrittore resta limitato come un anonimo). La registrazione non salva
  l'utente della richiesta (è quello del frontend, non chi compila).
- **`hbForms`**: come `GET /barrio/v1/forms`, solo per chi può modificare gli articoli o gestire le
  registrazioni (nel prototipo era pubblico); senza permesso è un errore GraphQL. `entryCount` solo per chi gestisce
  form o registrazioni.

### SEO

`seo` sui contenuti e sui termini, `hbSeoSettings`, `hbSeoSchema(uri)`, `hbSitemap`, `hbRedirects`, `hbLlms` e
`hbLlmsFull`. I dati sono quelli dell'head, della sitemap e di llms.txt di WordPress: nessuna logica a parte.

- **`seo`** (`HbSeo`) su ogni post type pubblico e su ogni tassonomia del SEO esposti in GraphQL: `Seo\Meta::post()`
  e `Seo\Meta::term()`. `title` è senza il nome del sito, `robots` è `index,follow` e simili, `ogType` è `article`
  solo per gli articoli. `isInSitemap` segue la sitemap: un contenuto pubblicato, indicizzato e senza password, un
  termine indicizzato con contenuti. `keywords` solo per chi può modificare il contenuto o il termine. Di un
  contenuto non pubblico (bozza, privato, in attesa) `seo` è `null` per chi non lo può modificare, e `hbSeoSchema`
  non ne dà la pagina: WPGraphQL ne mostra solo il titolo.
- **`hbSeoSettings`**: nome del sito, separatore, ordine (`PAGE_SITE` o `SITE_PAGE`), descrizione e immagine
  predefinite, icona del sito. L'organizzazione non c'è: l'anagrafica è in `hbCompanyInfo`, il resto nel JSON-LD.
- **`hbSeoSchema(uri)`**: il JSON-LD di `Seo\Schema`, con `JSON_HEX_TAG` perché finisce in un `<script>`. L'URI si
  risolve come `nodeByUri`; con un contenuto o un termine indicizzabile ci sono anche la pagina e il breadcrumb,
  altrimenti (senza `uri`, archivi, noindex, 404) solo organizzazione e sito.
- **`hbSitemap`**: gli URL di `wp-sitemap.xml` in tutte le lingue (`Seo\Sitemaps::each()`, gli stessi che conta
  SEO → Impostazioni), anche con "Scoraggia i motori di ricerca", perché il WordPress di un sito headless spesso
  non va indicizzato. `loc` è relativo; `type` è `post`, `term` o `user` (gli archivi degli autori, se
  indicizzati); `databaseId` è 0 per la home con gli ultimi articoli. Il campo è pubblico e leggere tutti gli URL
  costa: l'elenco sta nel transient `barrio_wpgraphql_sitemap_entries` per un giorno al più, svuotato dagli stessi
  hook che svuotano llms.txt (contenuti, termini, impostazioni del SEO, nome del sito).
- **`hbRedirects`**: tutti i redirect. `from` ha la `/` finale dei permalink (le sorgenti nel database non ce
  l'hanno), perché i frontend la confrontano con il percorso della pagina; non l'hanno quelle con la query string o
  con un'estensione (`/pagina.html`). Un 410 ha `to` null. `note` solo per chi ha `barrio_manage_seo` (nel prototipo
  era pubblica).
- **`hbLlms`, `hbLlmsFull`**: il testo di `Seo\Llms` dalla sua cache, con `updatedAt` del momento in cui è stato
  generato.
- **Indirizzo del frontend**: in Barrio → Impostazioni → Frontend headless (`barrio_wpgraphql_frontend()['url']`, qui
  sopra). Se c'è, durante le richieste GraphQL il filtro `barrio_seo_url`
  porta su quell'indirizzo gli URL delle pagine (canonical, JSON-LD, llms.txt); le immagini restano su WordPress,
  e l'head e il `llms.txt` di WordPress non cambiano. La cache di llms.txt ha una copia per indirizzo.
- **Lingue**: i testi predefiniti (la descrizione, quella dell'organizzazione) sono nella lingua della richiesta, non
  in quella del contenuto, perché il core non sa la lingua di un contenuto.

### Popup, banner e orari

`hbPopup(language)`, `hbBanner(language)` e `hbOpeningHours(language)`.

- **`hbPopup`, `hbBanner`**: quello del sito (`shown()` del modulo), il più recente tra gli accesi nel loro periodo, o
  `null`; i testi sono la riga della lingua, o quella principale. `startsAt` ed `endsAt` sono il periodo (`null`
  senza inizio o fine), per ricontrollarlo nel browser con la cache. Il periodo si valuta alla richiesta: un frontend
  con la cache rivalida su `barrio_popups_changed` e `barrio_banners_changed`, che non partono quando un periodo
  inizia o finisce; per quello l'estensione programma una revalidation a ogni inizio e fine futuri. Un popup o un
  banner programmato non esce prima del suo inizio, nemmeno con i suoi testi.
- **Popup**: `content` è l'HTML senza shortcode, tranne quello del form (`[barrio_form id="3"]`, senza il paragrafo
  intorno), che il frontend separa dal testo, legge con `hbForm` e invia con `hbSubmitForm`; con il modulo Forms spento
  non c'è. `link` è il pulsante (`null` senza): `title` è il suo testo o quello standard della lingua ("Scopri di
  più"), `target` è `_blank` con "Nuova scheda". `image.node` è il `MediaItem`, `null` per un'immagine eliminata.
  `days` sono i giorni prima di riproporlo a chi l'ha chiuso (0: alla pagina dopo; mai più dopo un form inviato).
  Quando aprirlo lo decide il frontend: nel sito è 2 secondi dopo il caricamento.
- **Banner**: `text` è il testo, `link` è `null` senza link.
- **`hbOpeningHours`**: con i nomi di Google Places e di schema.org. `currentWeek` sono i prossimi 7 giorni da
  lunedì, con le fasce dei giorni speciali al posto della settimana, come la `currentOpeningHours` di Google;
  `regularWeek` è la settimana senza giorni speciali, e `specialDays` sono quelli da oggi in poi, con `startDate`,
  `endDate` (`Y-m-d`) e `note` (nella lingua, o in quella principale). Senza orari le settimane sono vuote. `slots`
  sono `09:00 - 13:00`, o `9:00 AM - 1:00 PM` in inglese; `periods` sono le stesse ore come `opens` e `closes` in
  `HH:MM`, nel fuso del sito (`generalSettings.timezone`). Una chiusura non dopo l'apertura è il giorno dopo:
  `00:00 - 00:00` è aperto tutto il giorno. I nomi dei giorni sono di `WP_Locale` nella lingua chiesta, con la
  maiuscola (servono i pacchetti di lingua). `source` è `MANUAL` o `GOOGLE`; `syncedAt` c'è solo con la fonte Google,
  `displayName` è il nome del luogo su Google. L'ultimo errore di Google resta nell'admin.

### Dati aziendali

`hbCompanyInfo`, lo stesso nome del prototipo, con i campi in inglese: i dati di `Company\Module::data()`, gli stessi
del blocco. Sono pubblici, come nel footer e nel JSON-LD.

- Una stringa vuota è `null`, come nel prototipo; `legalName` vuota vale `name`.
- `address` è la sede legale, `null` senza indirizzo; `offices` sono le sedi operative nell'ordine di Dati aziendali,
  vuota se la sede legale è l'unica, senza quelle senza indirizzo. Ogni sede ha `name`, `null` se non c'è, e `phones`
  ed `emails`, liste di `HbCompanyContact { label value }` con `label` `null` se non c'è, vuote senza contatti.
- `profiles` sono i profili nell'ordine di Dati aziendali, con `network` riconosciuta dal dominio, sottodomini compresi
  (`facebook`, `instagram`, `linkedin`, `x` anche per `twitter.com`, `youtube` anche per `youtu.be`, `telegram` per
  `t.me`, `tiktok`, `whatsapp` per `wa.me`, `pinterest`, `threads`), oppure `null`: il frontend sceglie l'icona senza
  guardare l'URL.

## Migrazione dal prototipo

`barrio-suite-migration` porta su Barrio Suite i moduli di Suite Barrio (il prototipo), un sito alla volta e un modulo alla volta.

Il prototipo può essere stato modificato su un sito. Ogni migrazione conosce ciò che il prototipo ha avuto in tutta la
storia del suo repo, anche quello che non migra, e dà un avviso per ogni altra cosa piena che trova: un'opzione con il
prefisso del modulo (`suite_barrio_orari_…`), una chiave in un'opzione, in un campo, nelle impostazioni o in una
notifica di un form, un dato di servizio in una registrazione (`_hutk`), una colonna delle tabelle `wpb_*` (anche
vuota), un meta `_suite_barrio_seo_…`. L'avviso dice il nome, mai il valore, che può essere una chiave segreta; il dato
non passa.

Si migra da **Barrio → Migration**, che c'è solo con qualcosa da migrare (un modulo del prototipo acceso, uno
trattenuto, un form non ancora passato) e solo per chi ha `manage_options`. I comandi WP-CLI non ci sono più: SSH manca
su molti hosting. Un passaggio per modulo, nell'ordine in cui farli (Forms, SEO, Dati aziendali, Popup, Banner, Orari,
Blocchi, Ordinamento, Duplicazione, Testi alternativi, Statistiche), ognuno con il suo stato (da migrare, migrato,
niente da migrare) e due pulsanti: **Dry run**, la prova a vuoto, e **Migrate**, che si apre solo dopo una prova del
passaggio nella pagina e chiede conferma. Sotto, il resoconto: conteggi, note e avvisi. Ogni passaggio si può
rilanciare. La pagina e lo stato dei passaggi dipendono dagli interruttori del prototipo (Suite Barrio → Strumenti):
il prototipo resta quindi attivo, con i suoi moduli accesi, finché l'ultimo passaggio non è migrato. La revalidation di
Next.js dei siti headless è di `barrio-suite-wpgraphql` (§ GraphQL): finita la migrazione, il prototipo e questa
estensione si eliminano.

La pagina è React nel core (`assets/src/migration`), perché le estensioni non hanno una build. I passaggi le arrivano
con `barrio_admin_data`, in `migration`: `id`, `title`, `description`, `status` (`pending`, `migrated`, `empty`),
`path`, `items` e `columns` (le colonne del resoconto con il loro nome). La pagina chiama `POST {path}` con `dry_run`:
per un passaggio con `items` (i form) uno per richiesta con `item`, poi una senza `item` che chiude; la risposta ha
`rows`, `notes`, `warnings` e lo `step` aggiornato. Un'altra importazione (Popup Maker) può dare i suoi passaggi allo
stesso modo. Una richiesta alla volta (`GET_LOCK`): una seconda, mentre la prima lavora, riceve 409. Un errore del
database arriva con il suo motivo (500), non come errore critico di WordPress. Ogni richiesta porta `set_time_limit()`
a 300 secondi dove è più basso, ma il limite dell'hosting resta: un form che lo supera si annulla da solo, perché è
una transazione, le copie dei suoi file nella cartella protetta si eliminano alla chiusura della richiesta
(`shutdown`), e si rilancia; se succede sempre, va migrato a lotti di registrazioni. Se è l'hosting a uccidere il
processo, le copie restano in `uploads/barrio-forms-private/AAAA/MM` senza una registrazione che le nomini.

### Form

- **Prima**: spegnere il modulo Forms del prototipo (Suite Barrio → Strumenti), altrimenti ciò che riceve dopo
  resta lì; sui siti WPML attivare `barrio-suite-wpml`, che dà la lingua principale; per le chiavi di Mailchimp e
  reCAPTCHA attivare le loro estensioni, perché i segreti si cifrano con il loro schema. Il passaggio si può
  rilanciare: salta i form già presenti e copia le impostazioni solo dove mancano.
- **Form**: lo stesso ID, così shortcode e frontend headless restano validi. Se l'ID è già di un altro form di
  Barrio Suite è un errore (quel form prenderebbe il posto di questo nelle pagine): va risolto prima di
  continuare. I form migrati si ricordano nell'opzione `barrio_migration_forms` e al giro dopo si saltano. Ogni form passa in una transazione con le sue registrazioni; prima del commit i conteggi del
  prototipo e di Barrio Suite si confrontano nel database e, se non tornano, si annulla il form. La versione 1 è
  l'archivio, con anche i campi che esistono solo nelle registrazioni vecchie (testo lungo o file); la 2, solo
  se diversa, è la definizione attuale.
- **Campi**: le chiavi diventano `^[a-z][a-z0-9_]*$` (il resoconto segnala quelle cambiate), e merge tag, regole e
  integrazioni le seguono. Il valore di una scelta è la sua etichetta nella lingua principale senza HTML, cioè
  quello che il prototipo salvava; le traduzioni (per posizione) diventano righe `_i18n` complete. La checkbox della privacy,
  con il link nella sua unica scelta, diventa un campo Consenso con il link (anche nei frontend GraphQL, vedi
  sopra); nelle altre scelte l'HTML si toglie e il resoconto lo segnala. Il `multiple` della select (un prototipo
  modificato sul server) passa nella config.
- **Registrazioni**: checkbox da array o da `"Etichetta: true | …"`, select multiple da `"a|b|c"` e scelte tradotte
  diventano valori, le date `dd/mm/yyyy` diventano `Y-m-d`, `_page_url` diventa `source_url` senza query string, la
  data passa in UTC (`UNIX_TIMESTAMP()` nel fuso della sessione MySQL) e sono tutte già lette. IP, user agent,
  referrer e titolo della pagina non si importano. I file passano dagli upload alla cartella protetta: copia, commit,
  poi eliminazione dell'originale; uno che non c'è più finisce in `missing_files`.
- **Notifiche**: destinatario, risposta a, oggetto, titolo e testo (o `{{all_fields}}`, con `{{source_url}}` se
  il form mostrava la pagina), con gli allegati se il form ha un campo file, come nel prototipo. Mittente e Bcc
  per notifica non esistono più: il resoconto li segnala e vale il mittente dell'SMTP.
- **Impostazioni globali**: Mailjet diventa l'SMTP `in-v3.mailjet.com:587` con le stesse chiavi (solo se l'SMTP è
  vuoto; il mittente è quello delle notifiche o l'email dell'amministratore, come nel prototipo), poi le chiavi di
  Mailchimp e reCAPTCHA, logo e colori delle email del form modificato per ultimo, la retention (anni × 365).
- **Integrazioni per form**: Mailchimp con le impostazioni della sua estensione. MailUp, che non ha ancora
  un'estensione, si salva come `mailup` con `email_field` e `merge_fields` come Mailchimp, `list_id` e
  `group_id` tradotti, e l'opt-in come condizione `not_empty` (il prototipo iscriveva solo chi spuntava):
  sono le impostazioni che l'estensione MailUp dovrà dichiarare.
- **Shortcode**: alla fine, quando tutti i form sono in Barrio Suite, `[suite_barrio_form` e `[wpb_form` diventano
  `[barrio_form`, in PHP: nei contenuti con un `REPLACE` SQL, nei meta dei contenuti (i page builder) e nelle opzioni
  (i widget) deserializzando, sostituendo e salvando, perché nel testo serializzato cambierebbero le lunghezze. La
  prova a vuoto conta solo contenuti, meta e opzioni da riscrivere. Gli attributi, cioè l'ID, restano. Con un form
  rimasto indietro non si riscrive niente, con un avviso: un ID non migrato mostrerebbe un altro form. Non tocca i
  file del tema: uno shortcode scritto lì (`do_shortcode()`) continua a funzionare finché l'estensione è attiva, che li
  registra quando il prototipo non li usa.
- **Dopo**: spento il modulo Forms del prototipo e svuotata la cache delle pagine, l'estensione si può eliminare.
  Il prototipo solo se il sito non usa nient'altro di suo: è anche popup, banner, cookie banner con il registro
  dei consensi, SEO, orari, dati aziendali, llms.txt, accessibilità, e i frontend come `hello_standard` leggono
  da lui `hbPopup`, `hbBanner`, `hbOrari`, `hbCompanyInfo`, `hbSeoSite`, `hbSchemaRaw`, `hbRedirects`,
  `hbSitemap`, `hbLlms`, finché non sono portati allo schema di Barrio Suite ([foglio di porting](graphql-porting.md)).
  Le tabelle `wpb_forms`, `wpb_form_submissions` e `wpb_forms_translations` e le opzioni
  `suite_barrio_forms_*` restano: contengono le registrazioni vecchie, con IP e dati personali, quindi si
  eliminano a mano dal database appena verificata la migrazione.

### SEO

- **Prima**: con l'estensione attiva e il SEO del prototipo acceso, il modulo SEO di Barrio Suite si spegne da solo
  (una volta, all'attivazione) e gli amministratori vedono un avviso: altrimenti i meta uscirebbero due volte. Sui
  siti WPML attivare `barrio-suite-wpml`, che dà la lingua principale dei testi. L'indirizzo del frontend va in
  Barrio → Impostazioni → Frontend headless, se è ancora vuoto: il sito web di Info Azienda, su cui il prototipo
  faceva la revalidation, oppure, senza Info Azienda, l'URL dello schema se è diverso da quello di WordPress. Sui siti
  nati da un modello possono essere tutti e due quelli del modello: conviene scriverlo prima di migrare.
- **Cosa passa**: le impostazioni (titoli, immagine e descrizione predefinite, indicizzazione per tipo e
  tassonomia, organizzazione dello schema, modalità e introduzione di llms.txt) in `barrio_seo`, solo se Barrio Suite
  non ha ancora le sue, con l'anagrafica dell'organizzazione (nome, contatti, partita IVA, indirizzo, profili) nei
  campi vuoti di `barrio_company` solo se il prototipo non ha Info Azienda, che altrimenti ha il suo passaggio; i meta dei contenuti e dei termini, tranne quelli che
  Barrio Suite ha già e gli index e follow uguali al default del tipo, che restano "predefinito"; i redirect,
  tranne doppioni, cicli e quelli già nella tabella. Il resoconto conta per ogni meta trovati, migrati,
  predefiniti e saltati: gli stessi numeri nella prova a vuoto e nella migrazione. Contesto, settore e parole
  chiave dell'advisor diventano la descrizione del sito dell'AI (Barrio → Impostazioni), se non ce n'è già una.
- **Avvisi**: i contenuti fuori dalla sitemap ma indicizzabili (in Barrio Suite in sitemap c'è ciò che è
  indicizzabile: se devono restarne fuori, noindex), le immagini dello schema che non sono nella libreria dei media,
  i prezzi che non sono numeri, i redirect scartati, la lingua dello schema diversa da quella del sito, le chiavi API
  dell'AI, che non passano perché Barrio Suite si collega a Google con un service account.
- **Dopo**: il SEO del prototipo è spento e quello di Barrio Suite acceso, i `llms.txt` e `llms-full.txt` del
  prototipo nella cartella del sito sono eliminati, e `barrio_seo_changed` (`settings` e `redirects`) fa rileggere
  tutto al frontend headless. Gli archivi degli autori sono noindex, come nel prototipo che non
  li aveva. Le opzioni e i meta `_suite_barrio_seo_*` del prototipo restano: si eliminano a mano dopo la verifica.

### Popup, Banner, Orari, Dati aziendali, Blocchi, Ordinamento, Duplicazione, Testi alternativi e Statistiche

- **Prima**: con l'estensione attiva, ogni modulo di Barrio Suite il cui modulo del prototipo è acceso (Suite
  Barrio → Strumenti; Statistiche nel prototipo è sempre acceso) si spegne da solo, una volta, con lo stato in
  `barrio_migration_<modulo>` (`held`), e gli amministratori vedono un solo avviso con il link a Barrio → Migration. Altrimenti il sito
  avrebbe due popup e due banner, due link Duplica, due sincronizzazioni degli orari, e in GraphQL i tipi `hb*` del
  prototipo al posto di quelli dell'estensione. Riacceso a mano prima della migrazione, resta acceso.
- **Popup e banner**: ogni riga di `wpb_popups` e `wpb_banners` diventa un record con `PopupRepository::save()` o
  `BannerRepository::save()`, nella sua transazione, con un ID nuovo; la mappa degli ID è in
  `barrio_migration_popups_ids` e `barrio_migration_banners_ids`, e al giro dopo quelli migrati si saltano. Quello
  attivo resta acceso (il popup con 7 giorni, il valore del prototipo), gli altri spenti; nessun periodo. Le traduzioni
  diventano righe complete: un campo vuoto prende quello della lingua principale. Il testo del popup passa da
  `wpautop()`, che il prototipo applicava solo nel sito; un'immagine scelta come URL diventa il suo allegato. Un link
  che non è un indirizzo o un percorso (`#ancora`, `mailto:`) si toglie, con il pulsante; un popup senza titolo prende
  "Popup N" (Barrio Suite lo mostra ed è il nome del `<dialog>`); un banner senza testo non passa. Il resoconto elenca
  i contenuti con gli shortcode del prototipo e i loro attributi (`delay`, `days`): l'estensione li registra vuoti,
  perché popup e banner sono in tutte le pagine.
- **Orari**: il Place ID passa e la fonte diventa Google (manuale senza Place ID); la settimana è l'ultima che il
  prototipo aveva preso da Google, i giorni dai nomi italiani, con `synced_at` dall'ultimo aggiornamento. Barrio Suite
  la sostituisce alla sincronizzazione delle 3 con la settimana normale e i giorni speciali. La chiave API non passa;
  il cron del prototipo (`suite_barrio_cron_aggiorna_orari`) si toglie.
- **Dati aziendali**: da `suite_barrio_company_info` in `barrio_company`, poi, solo dove Info Azienda non ha niente,
  nome, contatti, partita IVA, indirizzo (e il paese, se l'indirizzo è lo stesso) e profili dello schema del SEO del
  prototipo. Prima Info Azienda, perché era ciò che il sito mostrava, mentre lo schema su molti siti era ancora quello
  del modello (quello di hello_standard). Ogni dato riempie solo un campo vuoto; dove i due valori sono diversi
  resta quello già scritto, con un avviso, così si può rilanciare. Contatti per sede che non si capiscono danno un
  avviso invece di sparire. La ragione sociale va in `legal_name` ed è anche il nome, se manca; la sede
  operativa passa solo con "sede operativa diversa" acceso, sulla sede operativa con lo stesso indirizzo o come prima
  di `offices` se non ce ne sono (altrimenti un avviso); i link alle mappe e i contatti per sede (`sede_telefoni`,
  `sede_email`, `sede_op_telefoni`, `sede_op_email` di un prototipo modificato sul server) solo sulla stessa sede, i sette social
  diventano i profili. `sito_web`, che il prototipo usava come dominio del frontend per la revalidation, diventa
  l'indirizzo del frontend (Barrio → Impostazioni), se non c'è già. Da quel momento `hbCompanyInfo` risponde con i nomi
  nuovi: il frontend portato si pubblica subito dopo.
- **Blocchi**: per ogni ruolo senza `manage_options` i blocchi disattivati sono quelli registrati meno quelli ammessi,
  senza i blocchi figli (che seguono il loro blocco: un elenco ha di nuovo le sue voci) e senza quelli di Barrio
  Suite, che il prototipo non conosceva. Con la restrizione accesa (anche senza l'opzione: era il default del
  prototipo, con 5 blocchi) anche H1 e colori e tipografia; i pannelli sempre, come faceva il prototipo. I tipi senza
  "editor Gutenberg" diventano "nessun editor".
- **Ordinamento**: i tipi e le tassonomie abilitati; il meta `suite_barrio_term_order` diventa `_barrio_order` con una
  sola istruzione, tranne dove c'è già.
- **Duplicazione**: il prototipo salvava i tipi abilitati, Barrio Suite quelli spenti: tutti gli altri si spengono.
- **Testi alternativi**: `suite_barrio_alt_ai_auto_upload` diventa `on_upload`; provider, chiavi, modello, lingua,
  tono, stile, contesto e parole chiave restano, con un avviso.
- **Statistiche**: `property_id` diventa `properties/{id}`, `replace_dashboard` passa com'è. La migrazione chiede a
  Google se il service account vede la proprietà (anche nella prova a vuoto) e, se no, dice quale email aggiungere come
  Visualizzatore. Client OAuth e token non passano: `suite_barrio_stats_tokens` (un refresh token in chiaro) si
  elimina, e il resoconto ricorda di eliminare il client in Google Cloud.
- **Impostazioni già in Barrio Suite**: un'opzione che c'è già (`barrio_hours`, `barrio_blocks`, …) resta com'è.
- **Dopo**: il modulo del prototipo è spento (`suite_barrio_tools_modules`) e quello di Barrio Suite acceso; i Dati
  aziendali fanno partire `barrio_company_changed`, popup e banner il loro `_changed`. Le tabelle `wpb_popups*` e
  `wpb_banners*` e le opzioni del prototipo restano: si eliminano a mano dopo la verifica.

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
