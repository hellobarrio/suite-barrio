# Barrio Suite: piano dello schema GraphQL

**Da fare prima della release, a plugin finito.** Fino ad allora l'estensione `barrio-suite-wpgraphql` tiene lo
schema del prototipo (`hb*`) per la massima compatibilità con i frontend esistenti, e i dati nuovi si aggiungono solo
come campi in più, senza togliere né cambiare quelli del prototipo (come in M8).

Prima della release l'estensione si rivede da capo (decisione del 20 novembre 2026): lo schema deve somigliare il più
possibile a quello che ci si aspetta da WordPress, da WPGraphQL e dai plugin famosi, così chi usa il plugin trova i
dati dove li cercherebbe. I frontend esistenti si adeguano, con un foglio di porting da dare a un'AI.

## Prima di scrivere lo schema

Studiare come fanno, e scegliere modulo per modulo, scrivendo qui il perché:

- **WPGraphQL**: l'interfaccia `Node` con l'ID globale (così `node(id:)` trova anche un popup o un form), le
  connessioni con `edges`, `nodes` e `pageInfo` per le liste, le relazioni uno a uno come `featuredImage { node }`,
  `nodeByUri`, le impostazioni come `generalSettings` e `readingSettings`, gli enum, `deprecationReason`.
- **SEO**: WPGraphQL for Yoast SEO e Rank Math SEO, che usano tutti e due un campo `seo` sui contenuti, sui termini e
  sulla radice (`title`, `metaDesc` o `description`, `canonical`, `robots`, `openGraph`, lo schema JSON-LD come
  `raw`). Un nome come il loro aiuta chi viene da quei plugin, ma si scontra se sono attivi insieme: decidere.
- **Form**: WPGraphQL for Gravity Forms (`gfForm(id:, idType:)`, i campi come interfacce con un tipo per campo,
  `submitGfForm` con `fieldValues`, gli errori per campo) e gli altri plugin di form con GraphQL.
- **Altri**: WooGraphQL e WPGraphQL for ACF, per nomi, impostazioni e permessi.
- **Transizione**: se tenere per un po' i campi `hb*` con `deprecationReason`, invece di toglierli subito.

Le domande aperte della bozza qui sotto: prefisso `barrio` o nomi come quelli dei plugin famosi; `MediaItem` diretto
o `{ node }`; `config` dei campi come JSON o un tipo per campo; liste semplici o connessioni.

## Bozza del 20 novembre 2026

Il primo giro, scritto prima di studiare i plugin famosi: un punto di partenza, non una decisione. Dice comunque
quali dati vanno esposti e quali scelte del prototipo lasciare.

### 1. Decisioni

| Tema | Decisione |
|---|---|
| Nomi | Inglese, come il codice e la REST del plugin. Tipi `Barrio*` (PascalCase), campi in camelCase, campi della radice `barrio*` (come `barrio_` negli hook, `barrio/v1` nella REST, `.barrio-` nel CSS): nessuno scontro con altri plugin, anche con `seo` di Yoast o Rank Math. Enum in maiuscolo per gli insiemi chiusi |
| Una sola regola | Ogni campo viene dalla stessa funzione del sito o della REST (`Seo\Meta`, `PopupRepository::shown()`, `Hours\Render`, `Submission::process()`…): GraphQL non ricalcola niente |
| Dati, non presentazione | Ore come `HH:MM`, date come `Y-m-d`, istanti in ISO 8601 UTC, booleani al posto di stringhe (`index`/`follow`, `newTab`). Nomi dei giorni, formati delle ore e testi già composti li fa il frontend, con `Intl`. Restano composti solo i testi che il plugin compone con una regola sua (`documentTitle`, il testo standard del pulsante) |
| Vuoti | `null` per "non c'è", mai la stringa vuota; liste vuote, mai `null` |
| Lingua | Argomento `lang` (come `?lang=` della REST): una lingua attiva, altrimenti la principale |
| Permessi | Gli stessi della REST. Ciò che è pubblico nel sito è pubblico; liste e dati interni chiedono le capability della REST, e senza sono un errore GraphQL (`UserError`) |
| Errori | Un invio rifiutato non è un errore GraphQL: torna nel payload, con un errore per campo |
| Prototipo | Nomi diversi, quindi i due schemi convivono senza la priorità 9: finché il prototipo è attivo i frontend non portati leggono ancora `hb*`. Si spegne dopo il porting |
| Moduli | Ogni parte solo con il suo modulo acceso (`barrio_<modulo>_loaded`), come oggi |

### 2. Form

```graphql
barrioForm(id: Int!, lang: String): BarrioForm               # null se non c'è o è nel cestino
barrioForms: [BarrioFormSummary!]!                           # edit_posts o barrio_manage_entries, come GET /forms
barrioSubmitForm(input: BarrioSubmitFormInput!): BarrioSubmitFormPayload!

type BarrioForm { id: Int!  status: BarrioFormStatus!  lang: String!  versionId: Int!  submitLabel: String!
                  fields: [BarrioFormField!]! }
enum BarrioFormStatus { ACTIVE CLOSED }
type BarrioFormField { key: String!  type: String!  required: Boolean!  label: String  placeholder: String
                       errorMessage: String  options: [BarrioFormOption!]  config: String  conditions: BarrioFormConditions }
type BarrioFormOption { value: String!  label: String! }
type BarrioFormConditions { action: String!  logic: String!  rules: [BarrioFormRule!]! }
type BarrioFormRule { field: String!  operator: String!  value: String }
type BarrioFormSummary { id: Int!  title: String!  status: BarrioFormStatus!  entries: Int  createdAt: String! }

input BarrioSubmitFormInput { formId: Int!  versionId: Int  lang: String  pageUrl: String
                              values: [BarrioFormValueInput!]!  files: [BarrioFormFileInput!] }
input BarrioFormValueInput { key: String!  value: String  values: [String!] }
input BarrioFormFileInput { key: String!  name: String!  content: String! }   # base64
type BarrioSubmitFormPayload { success: Boolean!  message: String  redirect: String  errors: [BarrioFormError!]! }
type BarrioFormError { key: String  message: String! }                         # key null: errore del form
```

- **Come `GET /barrio/v1/forms/{id}`**: gli stessi campi, compreso `page_break` tra i campi (il frontend divide le
  pagine, come fa il form del plugin) e il consenso come tipo `consent`. `type` è una stringa e `config` è JSON, perché
  le estensioni aggiungono tipi di campo con la loro configurazione (`pattern`, `min`, `max`, `extensions`,
  `max_size_mb`…). `label` è HTML filtrato (il link alla privacy).
- **Invio come `POST /forms/{id}/entries`**: le checkbox con `values`, il consenso con `value: "1"`, le date `Y-m-d`, i
  file in base64 in `files` (passano da `FileStore::check()` come un upload). `versionId` è quello letto con il form
  (`_barrio_version`), `pageUrl` diventa la pagina della registrazione. I valori delle estensioni stanno tra i `values`
  con la loro chiave (`_barrio_recaptcha`). L'esito è quello della REST: `message`, o `redirect` se il form manda a una
  pagina.
- **Lasciati dal prototipo**: `steps`, la checkbox al posto del consenso, `"Etichetta: true | …"`, `dd/MM/yyyy`, i
  file come JSON in una stringa, `settings` con campi sempre uguali (`displayPageInfo`, `errorMessage`), `debug`,
  `pageTitle`, `pageReferrer`, `submissionId` (l'ID di una registrazione non va a chi compila), il titolo interno del
  form nella query pubblica.

### 3. SEO

```graphql
barrioSeo: BarrioSeo                      # su ogni tipo di contenuto e tassonomia pubblici in GraphQL
barrioSeo(uri: String!): BarrioSeo        # radice: l'URI come lo risolve nodeByUri; null se non è un contenuto o un termine
barrioSeoSettings: BarrioSeoSettings!
barrioSitemap: [BarrioSitemapEntry!]!
barrioRedirects: [BarrioRedirect!]!
barrioLlms(full: Boolean = false): BarrioLlms!

type BarrioSeo { title: String!  documentTitle: String!  description: String  canonical: String!
                 robots: BarrioRobots!  image: MediaItem  openGraphType: String!  inSitemap: Boolean!
                 keywords: [String!]  schema: String! }
type BarrioRobots { index: Boolean!  follow: Boolean! }
type BarrioSeoSettings { siteName: String!  separator: String!  titleOrder: BarrioTitleOrder!
                         defaultDescription: String  defaultImage: MediaItem  siteIcon: MediaItem
                         organization: BarrioOrganization!  schema: String! }
enum BarrioTitleOrder { PAGE_SITE SITE_PAGE }
type BarrioSitemapEntry { path: String!  lastModified: String  type: BarrioSitemapType!  subtype: String!
                          id: Int!  title: String }
enum BarrioSitemapType { POST TERM USER }
type BarrioRedirect { id: Int!  source: String!  target: String  code: Int!  note: String }
type BarrioLlms { content: String!  generatedAt: String!  bytes: Int! }
```

- **`BarrioSeo`** è `Seo\Meta`, come l'head: `title` senza il nome del sito, `documentTitle` il `<title>` completo
  (separatore e ordine del sito), `keywords` solo a chi modifica il contenuto, `schema` il JSON-LD della pagina
  (organizzazione e sito, più pagina e breadcrumb se è indicizzabile). Anche sui termini, che il prototipo non aveva.
- **`BarrioSeoSettings`**: le impostazioni che servono al frontend, compresa la card **Organizzazione** (tipo, nome,
  logo, immagine, telefono, email, partita IVA, indirizzo, coordinate, zone servite, competenze, profili, offerte) e
  lo `schema` del sito per le pagine che non sono contenuti (archivi, ricerca, 404).
- **Redirect**: `source` com'è salvata, senza `/` finale; il frontend confronta i percorsi senza la `/` finale, come
  fa il modulo. `note` solo con `barrio_manage_seo`.
- Gli URL delle pagine restano sull'**indirizzo del frontend** (GraphQL → Settings), le immagini su WordPress.
- **Lasciati dal prototipo**: `robots` come stringa, `og*` e `twitter*` uguali a titolo, descrizione e immagine,
  `twitterCard` e `robotsDefault` sempre uguali, `priority` sempre null, `loc`, `from` con la `/` aggiunta,
  `updatedAt` come stringa di un timestamp, `LlmsContent` e i due campi `hbLlms`/`hbLlmsFull`, `hbSchemaRaw`.

### 4. Popup, banner e orari

```graphql
barrioPopup(lang: String): BarrioPopup           # quello del sito ora, o null
barrioPopups(lang: String): [BarrioPopup!]!      # accesi e non scaduti, anche i programmati, nell'ordine in cui vincono
barrioBanner(lang: String): BarrioBanner
barrioBanners(lang: String): [BarrioBanner!]!
barrioOpeningHours(lang: String): BarrioOpeningHours   # null senza orari, come il blocco

type BarrioPopup { id: Int!  title: String!  content: String  image: MediaItem  button: BarrioPopupButton
                   days: Int!  startsAt: String  endsAt: String }
type BarrioPopupButton { label: String!  url: String!  newTab: Boolean! }
type BarrioBanner { id: Int!  text: String!  url: String  newTab: Boolean!  startsAt: String  endsAt: String }

type BarrioOpeningHours { source: BarrioHoursSource!  timezone: String!  week: [BarrioOpeningDay!]!
                          specialDays: [BarrioSpecialDay!]!  google: BarrioGooglePlace }
enum BarrioHoursSource { MANUAL GOOGLE }
type BarrioOpeningDay { day: Int!  hours: [BarrioOpeningSlot!]! }            # day ISO: 1 lunedì … 7 domenica; [] chiuso
type BarrioSpecialDay { from: String!  to: String!  note: String  hours: [BarrioOpeningSlot!]! }
type BarrioOpeningSlot { opens: String!  closes: String! }                  # HH:MM nel fuso del sito
type BarrioGooglePlace { name: String  syncedAt: String }                   # null con la fonte manuale
```

- **Più popup e banner**: `barrioPopup` e `barrioBanner` sono quello che il sito mostra adesso. Le liste servono ai
  frontend con la cache: scelgono nel browser il primo nel suo periodo, così uno programmato compare alla sua data
  senza aspettare una revalidation.
- **Popup**: `content` è HTML senza shortcode, tranne quello del form, che il frontend separa e legge con
  `barrioForm`; senza il modulo Forms non c'è. `button` è null senza link; `label` è il testo scritto o quello
  standard della lingua. Quando aprirlo lo decide il frontend (nel sito, 2 secondi dopo il caricamento).
- **Orari**: i dati di `GET /barrio/v1/hours`. Una chiusura non dopo l'apertura è il giorno dopo; `00:00`–`00:00` è
  aperto tutto il giorno. I giorni speciali sono quelli da oggi in poi. `google` c'è solo con la fonte Google: il nome
  del luogo e l'ultima sincronizzazione (oggi `displayName` e `ultimoAggiornamento` sono null con gli orari scritti a
  mano, ed è per questo). `lang` vale solo per le note: nomi dei giorni e ore li scrive il frontend.
- **Lasciati dal prototipo**: `image { node }` (ora `MediaItem` diretto), `link.title` e `target`, `title` del banner
  per il testo, `settimana` con i giorni speciali mescolati, `slots` già scritti in 24 o 12 ore, `giorno`, `chiuso`,
  `ultimoAggiornamento` in `d/m/Y H:i`.

### 5. Cosa non si espone

I dati dell'admin (registrazioni, notifiche, integrazioni, permessi, l'errore di Google, le impostazioni degli altri
moduli) e quelli che il sito calcola sull'ora (lo stato "aperto adesso"): con la cache sarebbero vecchi, e il
frontend li calcola dai dati. Ordinamento e Testi alternativi non hanno campi loro: `menuOrder`, l'ordine dei termini
e `altText` sono già di WPGraphQL. I dati aziendali del prototipo (`hbCompanyInfo`: PEC, REA, sedi…) aspettano il
modulo Dati aziendali; quelli dello schema sono in `barrioSeoSettings.organization`.

### 6. Foglio di porting

`docs/graphql-porting.md`, scritto per un'AI che porta un frontend del prototipo (come `hello_standard`) al nuovo
schema: per ogni query `hb*` la query nuova completa, le trasformazioni dei dati (checkbox, consenso, date, file,
errori, ore e giorni con `Intl`, redirect senza `/`), i comportamenti nuovi (popup programmati, pulsante standard,
pagine dei form da `page_break`), un esempio TypeScript per i punti meno ovvi e una lista di controllo finale. Le query
del foglio si provano contro wp-env.

### 7. Test

- Lo schema dell'estensione in una fixture (`tests/fixtures/wpgraphql-schema.txt`, stampata con `sdl()`): ogni
  cambiamento si vede nel diff. Le fixture del prototipo si tolgono.
- Per ogni modulo i dati attesi, i permessi, le lingue, i vuoti; l'invio di un form con checkbox, consenso, data e
  file, e un invio rifiutato; con il modulo spento nessun campo; senza WPGraphQL nessun errore.

### 8. Fasi

| # | Fase | Pronta quando |
|---|---|---|
| G1 | **Form** (`barrioForm`, `barrioForms`, `barrioSubmitForm`) | Un form multipagina con consenso, checkbox, data e file si legge e si invia come con la REST; un invio rifiutato dà un errore per campo; `barrioForms` senza permesso è un errore |
| G2 | **SEO** | `barrioSeo` uguale all'head per contenuti e termini, anche dalla radice con l'URI; impostazioni con l'organizzazione; sitemap, redirect e llms come in WordPress |
| G3 | **Popup, banner e orari**, con le liste | Le liste nell'ordine in cui vincono, con i programmati; orari con settimana, giorni speciali e Google |
| G4 | **Foglio di porting** e documentazione (`docs/hooks.md`, readme, piani) | Ogni query del foglio risponde su wp-env; ogni query `hb*` di `hello_standard` ha la sua sostituta |

Ogni fase si chiude come le altre: test verdi su MariaDB 10.5 e MySQL 8.0 con e senza WPGraphQL, PHPStan, PHPCS,
traduzioni, lo stesso commit su `con-updater` e `senza-updater`.
