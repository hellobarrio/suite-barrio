# Barrio Suite: piano dello schema GraphQL e del modulo Dati aziendali

Decisione del 24 novembre 2026, che sostituisce quella del 20 novembre (tenere lo schema `hb*` del prototipo fino alla
release e poi rifare l'estensione da capo). Due lavori, in un piano solo perché il secondo serve al primo:

1. L'estensione `barrio-suite-wpgraphql` passa **adesso, in una volta sola**, dallo schema del prototipo a uno schema
   in inglese, con le convenzioni di WPGraphQL e dei plugin noti (Yoast SEO, Rank Math, Gravity Forms, ACF, Polylang).
   Tiene il prefisso `hb` e **non cambia la forma delle query**: stessi campi annidati allo stesso modo, alcuni nomi
   e valori nuovi.
2. Un modulo nuovo, **Dati aziendali** (`company`, §5), riscritto da `suite-barrio-prototipo/modules/info-azienda`:
   senza, a `hbCompanyInfo` del prototipo non risponderebbe nessuno.

Non si tengono due versioni: un campo rinominato o tolto non resta accanto al nuovo, e nessun `deprecationReason`. I
frontend esistenti (come `../hello_standard`) si portano con un foglio di porting (§9), scritto per un'AI.

La bozza del 20 novembre proponeva un rifacimento più profondo (`MediaItem` diretto, `config` JSON, `page_break` tra i
campi, ore senza testi composti): è scartata, perché cambiava il modo di scrivere le query. Resta nella storia di git
(`git show f9393c8:PLAN-GRAPHQL.md`).

Valgono le decisioni di [PLAN.md](PLAN.md), [PLAN-SEO.md](PLAN-SEO.md) e [PLAN-MODULI.md](PLAN-MODULI.md). Si lavora su
`con-updater` e ogni fase si porta su `senza-updater` con un cherry-pick.

## 1. Decisioni

| Tema | Decisione |
|---|---|
| Forma | Le query restano annidate come oggi. Cambiano i nomi (§3), alcuni valori (date, enum), i campi morti spariscono. Niente connessioni, niente ID globali |
| Prefisso | Resta `hb`, scelto il 24 novembre: campi della radice `hb*` e tipi `Hb*`, un prefisso corto del plugin come `gf*` e `GfForm` di Gravity Forms, `rankMath*` e `acf*`. Vale solo per GraphQL: hook, REST, opzioni, blocchi e shortcode restano `barrio`. Oggi i tipi hanno tre stili (`HB*`, `Hb*`, `LlmsContent` senza prefisso): diventano tutti `Hb*`, come `HbSubmitFormInput` e `HbSubmitFormPayload`, che WPGraphQL genera dal nome della mutation. Nei tipi che restano cambia solo il prefisso (`HBForm` → `HbForm`): nelle query si vede nei fragment e nei tipi delle variabili |
| `seo` | Unica eccezione al prefisso: il campo sui contenuti resta `seo`, come in Yoast e in Rank Math. Si aggiunge sui termini |
| Mutation | Resta `hbSubmitForm`. Con il verbo prima, come `submitGfForm`, il prefisso finirebbe in mezzo (`submitHbForm`) |
| Lingua dei nomi | Inglese ovunque. Per gli orari i nomi di Google Places (`regularOpeningHours`, `specialDays`, `periods`, `displayName`) e di schema.org (`opens`, `closes`) |
| Argomento della lingua | `language` al posto di `lang`: come il `where: { language }` di Polylang e WPML, che `hello_standard` usa già nelle altre query. Stesso significato: una lingua attiva, altrimenti la principale |
| ID | L'intero del database si chiama `databaseId`, come in WPGraphQL. Oggi `id` è `Int!`, `Int` o `String!` a seconda del tipo. Gli argomenti restano `id: Int!` |
| Booleani | Con `is`: `isRequired`, `isClosed`, come `isIndexed` e `isInSitemap` che ci sono già |
| Date | Gli istanti in ISO 8601 UTC ovunque, come `Period::iso()` (`2026-12-01T09:00:00+00:00`). Oggi ci sono quattro formati. I giorni restano `Y-m-d`, le ore `HH:MM` |
| Enum | Per gli insiemi chiusi (§4), in maiuscolo e con il nome che finisce in `Enum`, come WPGraphQL. Restano stringhe gli insiemi aperti: il `type` dei campi (le estensioni ne aggiungono), il `type` della sitemap (un provider di un altro plugin dà il suo nome), `target` (`_blank` è il valore dell'HTML, come in ACF), `operator` |
| Non null | Dentro i tipi: liste, elementi delle liste, `databaseId`, booleani, enum e i campi che il resolver dà sempre. I campi della radice restano nullable: un errore in uno non azzera il resto della risposta. Nel dubbio, nullable |
| Campi morti | Si tolgono i campi del prototipo che hanno sempre lo stesso valore (§3, "tolti") |
| Dati | Ogni campo viene dalla stessa funzione di oggi (`Seo\Meta`, `PopupRepository::shown()`, `Hours\Render`, `Submission::process()`…): cambiano nomi e formati, non i dati |
| Prototipo | Molti nomi sono ancora quelli del prototipo, che li registra anche con i suoi moduli spenti: la priorità 9 resta in ogni file, come oggi, e c'è anche in `company.php`. WPGraphQL tiene la prima registrazione e scarta le altre, e confronta i nomi dei tipi senza maiuscole: `HbForm` vince su `HBForm` del prototipo |
| Dati aziendali | Un modulo nuovo, `company` (§5), acceso di default come gli altri e senza effetti finché non lo si compila. In GraphQL `hbCompanyInfo` resta con il suo nome e risponde con i dati del modulo (§3.5) |
| Un solo posto per ogni dato | Nome, telefono, email, partita IVA, indirizzo e profili si scrivono in Dati aziendali, con i dati legali; lo schema del SEO li legge da lì, senza copie (nel prototipo erano due opzioni senza sincronia). In SEO → Organizzazione resta ciò che serve solo ai motori di ricerca: tipo, descrizione, logo, immagine, coordinate, aree servite, competenze e offerte. Decisione del 24 novembre, dopo G4: prima l'anagrafica stava nell'Organizzazione e Dati aziendali la leggeva |
| Dati aziendali spento | I dati restano salvati e lo schema del SEO li legge lo stesso, ma si modificano solo con il modulo acceso: SEO → Organizzazione lo dice |
| Versione | Resta 0.1.0 |

## 2. Cosa resta com'è

È già la forma degli standard, e non si tocca:

- `seo { title description canonical robots … }` sui contenuti: lo stesso campo di Yoast e Rank Math.
- `image { node { … } }` nel popup: la relazione uno a uno di WPGraphQL (`featuredImage { node }`).
- `link { url title target }`: il campo Link di ACF.
- `conditionalLogic { action logicType rules { fieldName operator value } }`: quasi uguale a Gravity Forms.
- La mutation con `input` e payload, e l'invio rifiutato che torna nel payload e non come errore GraphQL.
- Le liste semplici (`steps`, `fields`, `options`, redirect, sitemap, settimane): insiemi completi, senza paginazione.
  Anche Yoast espone i redirect così.
- `loc` e `lastmod` nella sitemap: i nomi del protocollo. `from`, `to` e `code` nei redirect.
- `fields` e `steps` insieme, il consenso come checkbox con una scelta, i valori inviati come oggi (checkbox come
  `"Etichetta: true | Etichetta: false"` o separati da virgole, date `dd/MM/yyyy` o `yyyy-MM-dd`, file come JSON con
  `content` in base64 e `filename`).
- `slots` già scritti (24 ore, 12 per l'inglese) accanto alle ore da calcolare.
- I permessi: titolo del form, lista dei form, `keywords` e `note` solo a chi li vede oggi.

## 3. Schema nuovo

La nullabilità qui sotto è l'obiettivo; si cambia solo scrivendo qui il perché.

### 3.1 Form

```graphql
# RootQuery
hbForm(id: Int!, language: String): HbForm      # null se non c'è o è nel cestino
hbForms: [HbFormListItem!]                       # edit_posts o barrio_manage_entries; senza, errore GraphQL
# RootMutation
hbSubmitForm(input: HbSubmitFormInput!): HbSubmitFormPayload

type HbForm {
  databaseId: Int!
  title: String                  # interno: solo a chi vede la lista dei form
  createdAt: String!             # ISO 8601 UTC
  updatedAt: String!
  fields: [HbFormField!]!
  steps: [HbFormStep!]!
  settings: HbFormSettings!
}
type HbFormStep { fields: [HbFormField!]! }
type HbFormField {
  name: String!
  type: String!
  label: String
  placeholder: String
  errorMessage: String
  isRequired: Boolean!
  pattern: String
  min: String
  max: String
  format: String
  options: [HbFormOption!]
  conditionalLogic: HbConditionalLogic
}
type HbFormOption { value: String!  label: String! }
type HbConditionalLogic {
  action: HbConditionalLogicActionEnum!       # SHOW | HIDE
  logicType: HbConditionalLogicTypeEnum!      # ALL | ANY
  rules: [HbConditionalLogicRule!]!
}
type HbConditionalLogicRule { fieldName: String!  operator: String!  value: String }
type HbFormSettings {
  submitText: String
  successMessage: String
  afterSubmit: HbFormAfterSubmitEnum!             # MESSAGE | REDIRECT
  redirectUrl: String
}
type HbFormListItem { databaseId: Int!  title: String  entryCount: Int  createdAt: String! }

input HbSubmitFormInput {
  clientMutationId: String
  formId: Int!
  fields: [HbFormFieldInput!]!
  language: String
  pageUrl: String
}
input HbFormFieldInput { name: String!  value: String }
type HbSubmitFormPayload {
  clientMutationId: String
  success: Boolean!
  message: String
  entryId: Int
  errors: [HbFormError!]!
}
type HbFormError { name: String  message: String! }
```

| Prototipo | Nuovo |
|---|---|
| `hbForm(id, lang)` | `hbForm(id, language)`, con `id` obbligatorio (`$id: Int!` nelle query) |
| `HBForm.id`, `HBFormListItem.id` | `databaseId` |
| `HBFormField.required` | `isRequired` |
| `createdAt`, `updatedAt` in `Y-m-d H:i:s` locale | ISO 8601 UTC |
| `action`, `logicType`, `afterSubmit` minuscoli | Enum: `SHOW`/`HIDE`, `ALL`/`ANY`, `MESSAGE`/`REDIRECT` |
| `HBFormListItem.submissions` | `entryCount` |
| input `lang` | `language` |
| payload `submissionId` | `entryId` |
| payload `errors: [String]` (`"Etichetta: errore"`) | `errors { name message }` |

- **Tolti**: `HBFormField.id` (uguale a `name`), `conditionalLogic.enabled` (sempre `true`), `settings.errorMessage`
  (sempre vuoto), `settings.displayPageInfo` (sempre `true`: `pageUrl` si invia sempre), gli input `pageTitle` e
  `pageReferrer` (ignorati), `debug` nel payload (sempre `null`).
- **Errori**: uno per campo, con `name` la chiave del campo e `message` il messaggio senza l'etichetta. Un errore del
  form (non trovato, chiuso, limite per IP) ha `name: null`. Con successo la lista è vuota. `message` del payload resta
  com'è oggi: il messaggio di successo, oppure il primo errore con la sua etichetta.
- **Restano**: `hbForms`, `hbSubmitForm` e i nomi dei tipi, con il prefisso `Hb` (`HBForm` → `HbForm`);
  `HbFormFieldInput` resta com'è.

### 3.2 SEO

```graphql
# su ogni tipo di contenuto e, nuovo, su ogni tassonomia del SEO esposti in GraphQL
seo: HbSeo

type HbSeo {                 # era HBSeoPost: gli stessi campi
  title: String
  description: String
  robots: String
  canonical: String
  ogTitle: String
  ogDescription: String
  ogImage: String
  ogType: String
  twitterCard: String
  twitterTitle: String
  twitterImage: String
  isIndexed: Boolean!
  isInSitemap: Boolean!
  keywords: [String!]!
}

# RootQuery
hbSeoSettings: HbSeoSettings
hbSeoSchema(uri: String): String
hbSitemap: [HbSitemapEntry!]
hbRedirects: [HbRedirect!]
hbLlms: HbLlms
hbLlmsFull: HbLlms

type HbSeoSettings {
  siteTitle: String
  separator: String
  titleOrder: HbSeoTitleOrderEnum!                # PAGE_SITE | SITE_PAGE
  defaultDescription: String
  ogImage: String
  favicon: String
}
type HbSitemapEntry { loc: String!  lastmod: String  type: String!  subtype: String!  databaseId: Int!  title: String }
type HbRedirect { databaseId: Int!  from: String!  to: String  code: Int!  note: String }
type HbLlms { content: String!  updatedAt: String!  bytes: Int! }      # updatedAt ISO 8601 UTC
```

| Prototipo | Nuovo |
|---|---|
| `seo` (`HBSeoPost`) | `seo` (`HbSeo`): i fragment `on HBSeoPost` diventano `on HbSeo` |
| `hbSeoSite` | `hbSeoSettings`, come `generalSettings` di WPGraphQL e `rankMathSettings` |
| `defaultDesc` | `defaultDescription` |
| `titleOrder` minuscolo | Enum `PAGE_SITE`/`SITE_PAGE` |
| `hbSchemaRaw(uri)` | `hbSeoSchema(uri)` |
| `HBSitemapEntry.id`, `HBRedirect.id` (stringa) | `databaseId: Int!` |
| `LlmsContent.updatedAt` (timestamp Unix in una stringa) | ISO 8601 UTC |

- **Tolti**: `HBSeoSite.robotsDefault` (sempre `index,follow`), `HBSitemapEntry.priority` (sempre `null`).
- **`seo` sui termini**: nuovo, su ogni tassonomia di `Seo\Module::taxonomies()` esposta in GraphQL, da
  `Seo\Meta::term()`. `ogType` è `website`; `keywords` solo a chi può modificare il termine (`edit_term`).
- **Restano**: `hbSitemap`, `hbRedirects`, `hbLlms` e `hbLlmsFull`. Tipi: `HBSeoPost` → `HbSeo` (ora è anche sui
  termini), `HBSeoSite` → `HbSeoSettings`, `LlmsContent` → `HbLlms`.
- **Organizzazione**: non esce da `hbSeoSettings`. Nome, contatti, indirizzo e profili si leggono da
  `hbCompanyInfo` (§3.5), il resto è nel JSON-LD di `hbSeoSchema`.

### 3.3 Popup e banner

```graphql
hbPopup(language: String): HbPopup
hbBanner(language: String): HbBanner

type HbPopup {
  databaseId: Int!
  title: String
  content: String
  link: HbPopupCta          # null senza pulsante
  image: HbPopupImage        # null senza immagine
  days: Int!
  startsAt: String
  endsAt: String
}
type HbPopupCta { url: String!  title: String!  target: String! }
type HbPopupImage { node: MediaItem }
type HbBanner {
  databaseId: Int!
  text: String
  link: HbBannerCta         # null senza link
  startsAt: String
  endsAt: String
}
type HbBannerCta { url: String!  target: String! }
```

| Prototipo | Nuovo |
|---|---|
| `hbPopup(lang)`, `hbBanner(lang)` | `hbPopup(language)`, `hbBanner(language)` |
| `id` | `databaseId` |
| `HBBanner.title` | `text`: è il testo del banner, non un titolo |

I nomi dei tipi restano, con il prefisso `Hb` (`HBPopup` → `HbPopup`). Nel testo del popup resta lo shortcode del form (`[barrio_form id="3"]`).

### 3.4 Orari

```graphql
hbOpeningHours(language: String): HbOpeningHours

type HbOpeningHours {
  source: HbOpeningHoursSourceEnum!               # MANUAL | GOOGLE
  displayName: String            # il nome del luogo su Google
  syncedAt: String               # ISO 8601 UTC; null con gli orari scritti a mano
  currentWeek: [HbOpeningHoursDay!]!              # i prossimi 7 giorni da lunedì, con i giorni speciali
  regularWeek: [HbOpeningHoursDay!]!              # la settimana normale
  specialDays: [HbOpeningHoursSpecialDay!]!       # da oggi in poi
}
type HbOpeningHoursDay {
  day: String!                   # il nome del giorno nella lingua richiesta
  slots: [String!]!
  periods: [HbOpeningHoursPeriod!]!
  isClosed: Boolean!
}
type HbOpeningHoursSpecialDay {
  startDate: String!             # Y-m-d
  endDate: String!
  note: String
  slots: [String!]!
  periods: [HbOpeningHoursPeriod!]!
  isClosed: Boolean!
}
type HbOpeningHoursPeriod { opens: String!  closes: String! }           # HH:MM nel fuso del sito
```

| Prototipo | Nuovo |
|---|---|
| `hbOrari(lang)` | `hbOpeningHours(language)` |
| `settimana`, `settimanaNormale`, `giorniSpeciali` | `currentWeek`, `regularWeek`, `specialDays` |
| `giorno`, `chiuso` | `day`, `isClosed` |
| `fasce { apertura chiusura }` | `periods { opens closes }` |
| `dal`, `al`, `nota` | `startDate`, `endDate`, `note` |
| `fonte` minuscolo | `source`, enum `MANUAL`/`GOOGLE` |
| `ultimoAggiornamento` in `d/m/Y H:i` | `syncedAt`, ISO 8601 UTC |

`slots` e `displayName` restano. Tipi: `HBOrari` → `HbOpeningHours`, `HBOrariGiorno` → `HbOpeningHoursDay`,
`HBOrariGiornoSpeciale` → `HbOpeningHoursSpecialDay`, `HBOrariFascia` → `HbOpeningHoursPeriod`.

### 3.5 Dati aziendali

Il prototipo ha `hbCompanyInfo` (`hello_standard`: `getInfoAzienda`), che l'estensione non ha mai registrato. Arriva
con il modulo del §5, in un file suo (`company.php`), con lo stesso nome e la stessa forma: i dati in piano, le due
sedi annidate. I campi passano all'inglese.

```graphql
hbCompanyInfo: HbCompanyInfo

type HbCompanyInfo {
  name: String
  legalName: String                        # la ragione sociale; vuota, il nome
  phone: String
  mobile: String
  email: String
  privacyEmail: String
  certifiedEmail: String                   # PEC
  vatId: String
  taxCode: String                          # codice fiscale
  registrationNumber: String               # REA
  shareCapital: String
  invoiceCode: String                      # SDI
  address: HbCompanyAddress                # la sede legale; null se vuota
  operatingAddress: HbCompanyAddress       # null se è la stessa
  profiles: [HbCompanyProfile!]!
}
type HbCompanyAddress {
  street: String
  postalCode: String
  locality: String
  region: String
  country: String
  mapsUrl: String
}
type HbCompanyProfile { network: String  url: String! }
```

| Prototipo | Nuovo |
|---|---|
| `ragioneSociale` | `legalName`; in più `name`, il nome con cui l'azienda è conosciuta |
| `telefono`, `cellulare` | `phone`, `mobile` |
| `email`, `emailPrivacy`, `pec` | `email`, `privacyEmail`, `certifiedEmail` |
| `partitaIva`, `codiceFiscale` | `vatId`, `taxCode` |
| `rea`, `capitaleSociale`, `codiceUnivoco` | `registrationNumber`, `shareCapital`, `invoiceCode` |
| `sedeLegale { via citta cap provincia googleMaps }` | `address { street locality postalCode region mapsUrl }`, più `country` |
| `sedeOperativa { … }` | `operatingAddress { … }` |
| `social { facebook instagram linkedin twitter youtube telegram tiktok }` | `profiles { network url }` |
| `sitoWeb` | Tolto: `generalSettings.url`, o l'indirizzo che il frontend conosce già |

- Tutto viene da `Company\Module::data()` (§5.2), la stessa funzione del blocco. Una stringa vuota è `null`, come nel
  prototipo. I dati sono pubblici: sono quelli del footer e del JSON-LD.
- **`profiles`**: i profili dell'azienda, una lista al posto dei sette social fissi. `network` è la
  rete riconosciuta dal dominio (`facebook`, `instagram`, `linkedin`, `x`, `youtube`, `telegram`, `tiktok`,
  `whatsapp`, `pinterest`, `threads`), oppure `null`: il frontend sceglie l'icona senza guardare l'URL.

## 4. Enum

| Enum | Valori | Valore interno |
|---|---|---|
| `HbConditionalLogicActionEnum` | `SHOW`, `HIDE` | `show`, `hide` |
| `HbConditionalLogicTypeEnum` | `ALL`, `ANY` | `all`, `any` |
| `HbFormAfterSubmitEnum` | `MESSAGE`, `REDIRECT` | `message`, `redirect` |
| `HbSeoTitleOrderEnum` | `PAGE_SITE`, `SITE_PAGE` | `page_site`, `site_page` |
| `HbOpeningHoursSourceEnum` | `MANUAL`, `GOOGLE` | `manual`, `google` |

Con `register_graphql_enum_type()` e `'values' => [ 'SHOW' => [ 'value' => 'show' ] ]` i resolver continuano a dare il
valore interno, e WPGraphQL risponde con il nome.

## 5. Dati aziendali (`company`)

Namespace `BarrioSuite\Company`, cartella `modules/company`, una voce in `Modules::ALL` e nell'autoloader di
`barrio-suite.php`. In inglese "Company details".

### 5.1 Cosa fa

- **L'anagrafica dell'azienda**, in un posto solo: nome, ragione sociale, partita IVA, codice fiscale, numero REA,
  capitale sociale, codice SDI; telefono, cellulare, email, PEC, email per la privacy; la sede legale con il link alla
  mappa, una sede operativa se è diversa, i profili social. Nome, contatti, partita IVA, indirizzo e profili stavano in
  SEO → Organizzazione: la migrazione `0001` li sposta qui. La ragione sociale vuota vale il nome.
- **Nel sito**: il blocco `barrio/company` (dinamico) e lo shortcode `[barrio_company]`, con lo stesso render, come
  per gli orari.
  - Si scelgono i dati e il loro ordine (`fields`), se in una riga separati da " · " o in un elenco (`layout`), e se
    con le etichette (`labels`): `[barrio_company fields="legal_name,vat_id,registration_number" layout="line"]`.
  - I dati: `name`, `legal_name`, `vat_id`, `tax_code`, `registration_number`, `share_capital`, `certified_email`,
    `invoice_code`, `phone`, `mobile`, `email`, `privacy_email`, `address`, `operating_address`. Senza `fields`, la
    riga legale: ragione sociale, sede, partita IVA, codice fiscale, REA, capitale sociale.
  - Le email sono link `mailto:`, i telefoni `tel:`, una sede è una riga, con il link alla mappa se c'è.
  - Un dato vuoto si salta; senza dati il blocco non mostra niente. Le etichette seguono la lingua della pagina.
  - Nessun CSS e nessuno script: il testo prende lo stile del tema.
- **Schema**: con il modulo SEO acceso, il nodo Organization prende da qui nome, ragione sociale (`legalName`),
  contatti, partita IVA, codice fiscale (`taxID`), indirizzo e profili (`sameAs`), anche con questo modulo spento.
- **Headless**: `hbCompanyInfo` (§3.5).

### 5.2 Dati

**Opzione `barrio_company`** (autoload: la leggono il blocco e lo schema), con il suo JSON Schema, scritta solo dalla
sua rotta REST e dalla migrazione dal prototipo. Nasce dalla migrazione `0001` con i campi tolti da
`barrio_seo.organization` (`same_as` diventa `profiles`); dall'Organizzazione si tolgono solo se l'opzione nasce lì.

- `name`, `legal_name`, `tax_code`, `registration_number`, `share_capital`, `invoice_code`: righe di testo;
- `vat_id`, `phone`, `mobile`: al più 64 caratteri;
- `email`, `certified_email`, `privacy_email`: un'email o vuoto;
- `address` (la sede legale) e `operating_address`: `{street, postal_code, locality, region, country, maps_url}`, con
  il paese in ISO 3166-1 e la mappa `http` o `https`, o vuota. La sede operativa tutta vuota è la sede legale;
- `profiles`: gli URL dei profili, `http` o `https`.

Nessun testo per lingua: sono dati legali. È un'opzione e non una tabella: è un solo record, letto intero.

### 5.3 Problemi del prototipo da non ripetere

| Problema | Nel nuovo modulo |
|---|---|
| I dati dell'azienda stanno in due opzioni (Info Azienda e schema), senza sincronia | Un solo posto: `barrio_company`, da cui legge anche lo schema |
| Nessun output in WordPress: i dati esistono solo in GraphQL | Blocco, shortcode e schema |
| Sette social fissi | Una lista di profili, con la rete riconosciuta dal dominio |
| `sito_web` scritto a mano, usato anche come dominio del frontend per la revalidation | Non c'è: l'indirizzo del frontend è in GraphQL → Settings |
| L'interruttore "sede operativa diversa" si salva a parte: spento, i campi restano pieni | Nessun interruttore salvato: senza sede operativa i campi sono vuoti |
| Chiavi e campi GraphQL in italiano (`sede_op_via`, `partitaIva`) | Inglese, gli stessi nomi in opzione, REST e GraphQL |

### 5.4 Admin

- **Dati aziendali**, una pagina nel menu Barrio con la capability `barrio_manage_company` (migrazione `0002`), che
  all'inizio hanno i soli amministratori: sono dati legali, non un contenuto del sito. Una colonna, un solo Salva:
  - card Azienda: nome, ragione sociale, partita IVA, codice fiscale, numero REA, capitale sociale, codice SDI;
  - card Contatti: telefono, cellulare, email, PEC, email per la privacy;
  - card Sede legale: indirizzo e link alla mappa;
  - card Sede operativa: l'interruttore "Sede operativa diversa dalla sede legale", che apre indirizzo e link alla
    mappa; spento, al salvataggio quei campi si svuotano;
  - card Profili, come era in SEO → Organizzazione; i profili vuoti non si salvano.
- **Impostazioni**, una scheda agli amministratori, come negli altri moduli: la card Come mostrarli (il blocco, lo
  shortcode con i nomi dei dati, lo schema SEO) e i Permessi, per dare la capability agli altri ruoli (24 novembre,
  richieste dopo G4).
- **SEO → Organizzazione**: senza i campi spostati; un avviso in cima porta a Dati aziendali o, con il modulo spento,
  alle Impostazioni. La card Indirizzo diventa Coordinate (latitudine e longitudine).
- **REST**: `GET`/`PUT /company` (`barrio_manage_company`). `GET` dà agli amministratori anche i ruoli; solo loro li
  cambiano con `PUT`.
- **Bacheca**: una card larga sotto la messa online, a chi ha `barrio_manage_company`, con una riga di dati
  (ragione sociale, partita IVA, sede legale ed email, scritti come nel blocco ma senza link), "—" dove mancano, e
  Modifica e Impostazioni (24 novembre, richiesta dopo G6; prima: nessuna card, perché sono dati che si scrivono una
  volta).

**Lasciati**: più di due sedi, i testi per lingua, le icone dei social (c'è il blocco Icone social di WordPress), i
block bindings per mettere un dato dentro un paragrafo (lo shortcode basta).

### 5.5 Estensioni e hook

| Estensione | Cosa cambia |
|---|---|
| `barrio-suite-wpgraphql` | `company.php`, solo con il modulo acceso e con priorità 9: `hbCompanyInfo` (§3.5) |
| `barrio-suite-migration` | `company.php`, con il comando `wp barrio company migrate [--dry-run]`, da `suite_barrio_company_info`.<br>• I dati legali, il cellulare, l'email per la privacy, i link alle mappe e la sede operativa (solo con "sede operativa diversa" acceso) vanno in `barrio_company`.<br>• Nome, telefono, email, partita IVA, indirizzo e social riempiono solo i campi vuoti di `barrio_company`: la migrazione del SEO ci ha già messo quelli dello schema del prototipo (o di Info Azienda, se lo schema era vuoto), sempre solo nei campi vuoti. Dove tutti e due hanno un valore, e diverso, resta quello già scritto, con un avviso.<br>• `ragione_sociale` va sempre in `legal_name`.<br>• Si può rilanciare: se `barrio_company` ha già dati, restano.<br>• `sito_web` diventa l'indirizzo del frontend (GraphQL → Settings), se non c'è già.<br>• Finché il prototipo è attivo il modulo resta spento, come gli altri: Info Azienda non si spegne, e un `hbCompanyInfo` di Barrio Suite con i nomi nuovi romperebbe le query del frontend non ancora portato.<br>Nel ponte di revalidation, `barrio_company_changed` manda il tag `wp:option`, che in `hello_standard` copre anche lo schema (`getSchema`) |
| `barrio-suite-wpml` | Niente: nessun testo per lingua |

Hook nuovi: `barrio_company_loaded` (su `init`, priorità 5), per l'estensione WPGraphQL, e `barrio_company_changed`,
per il ponte di revalidation.

## 6. Fuori da questo piano

Scartati perché cambiano il modo di scrivere le query o di inviare i dati, per poco guadagno:

- le connessioni (`nodes`, `edges`, `pageInfo`) e l'interfaccia `Node` con l'ID globale e `idType`;
- un tipo GraphQL per ogni tipo di campo, come in Gravity Forms;
- `MediaItem` al posto degli URL in `seo` e nelle impostazioni;
- le checkbox come `values: [String!]`, i file come input tipizzato o in multipart, il consenso come tipo suo;
- la stringa vuota come `null` nei campi che c'erano già (`placeholder`, `pattern`, `successMessage`…);
- `operator` come enum, un solo `hbLlms(full:)`, `source`/`target` nei redirect.

## 7. Da verificare

- **Cache della sitemap**: il transient `barrio_wpgraphql_sitemap` ha le righe con la chiave `id`. Cambiare nome al
  transient, o tenere le chiavi interne e leggere `databaseId` da `id`: mai un `Int!` nullo da una cache vecchia.
- **`isInSitemap` dei termini**: la stessa regola di `Seo\Sitemaps` per i termini (indicizzato, e con contenuti se il
  core nasconde i termini vuoti).
- **Non null**: per ogni `!` del §3, che il resolver non dia mai `null` (`title` del popup senza testo, `label` di una
  scelta, il nome del giorno). Dove può, il campo torna nullable e si scrive qui.
- **Prototipo acceso**: per ogni campo con lo stesso nome rispondono i dati di Barrio Suite (priorità 9). I campi che
  ha ancora solo il prototipo (`hbOrari`, `hbSeoSite`, `hbSchemaRaw`) restano i suoi, con i suoi tipi, e non devono
  rompere lo schema: provarlo con `WP_DEBUG` e con il debug di WPGraphQL.
- **Estensioni**: che `barrio-suite-wpml` e `barrio-suite-migration` non usino i nomi vecchi.
- **Revalidation dello schema**: `hbSeoSchema` cambia anche quando si salvano i Dati aziendali
  (`barrio_company_changed`). Controllare che il tag del ponte copra anche lo schema nel frontend.
- **Reti dei profili**: `x.com` e `twitter.com`, `youtu.be`, `t.me`, `wa.me`, il `www.` e i sottodomini
  (`it.linkedin.com`, `m.facebook.com`).
- **`legalName` e `taxID`**: lo schema con il validatore di schema.org e con il Rich Results Test.
- **Siti del prototipo**: quanto differiscono Info Azienda e i dati dello schema, per capire quanti avvisi dà la
  migrazione.

## 8. Test

- **Schema GraphQL**: le tre fixture del prototipo (`tests/fixtures/wpgraphql-schema.txt`, `wpgraphql-seo-schema.txt`,
  `wpgraphql-modules-schema.txt`) diventano la fotografia dello schema dell'estensione, stampata con
  `WpGraphqlTest::sdl()`: ogni cambiamento si vede nel diff. I test `test_the_schema_is_the_prototype_one` diventano il
  confronto con la fotografia; `test_the_schema_keeps_every_field_of_the_prototype` si toglie.
- **Esistenti**: `WpGraphqlTest`, `SeoGraphqlTest` e `ModulesGraphqlTest` con i nomi nuovi; i nomi dei test non
  parlano più del prototipo.
- **Nuovi in GraphQL**: un errore per campo con `name`, e uno del form con `name: null`; gli enum in maiuscolo; le date
  in ISO 8601 UTC (form, llms, orari); `seo` su un termine, con `keywords` solo a chi lo modifica; nessun nome
  italiano e nessun campo tolto nello schema.
- **Dati aziendali**:
  - la migrazione `0001` sposta l'anagrafica dall'Organizzazione, e rilanciata non cambia niente; l'opzione è in
    autoload e una pagina del sito non fa una query in più (`ModulesTest`);
  - il JSON Schema rifiuta un'email, un URL e un paese non validi;
  - la ragione sociale vuota vale il nome, una sede senza indirizzo è `null`;
  - blocco e shortcode danno lo stesso HTML, con i dati scelti nell'ordine scelto, i link giusti e ogni valore
    protetto; senza dati, niente;
  - lo schema del SEO prende i dati da qui anche con il modulo spento, `legalName` solo se è scritta;
  - `hbCompanyInfo` pieno e vuoto (`address` e `operatingAddress` `null`), con le reti dei profili;
  - la migrazione: prova a vuoto e migrazione con gli stessi conteggi e avvisi, e il secondo lancio non cambia niente.
- **Come sempre**: con il modulo spento nessun campo, nessuna rotta e nessun blocco; senza WPGraphQL nessun errore
  (`npm run test:php`); con WPGraphQL `npm run test:php:graphql`.

## 9. Foglio di porting

`docs/graphql-porting.md`, scritto per un'AI che porta un frontend del prototipo al nuovo schema, senza altro contesto:

- per ogni query `hb*` la query nuova completa, da copiare;
- le tabelle "Prototipo → Nuovo" del §3, i campi tolti e cosa fare dove il frontend li leggeva;
- i valori che cambiano, con il codice da cercare: confronti con `"redirect"`, `"message"`, `"show"`, `"all"`;
  `updatedAt * 1000` di llms; `ultimoAggiornamento` già formattato; `errors` come stringhe; `$id: Int`;
  `social.facebook` che diventa una ricerca in `profiles`;
- una lista di controllo finale: nessun campo rinominato o tolto nelle query (`hbOrari`, `hbSeoSite`, `hbSchemaRaw`,
  `lang:`, `id` dove ora c'è `databaseId`), nessun tipo `HB*` nei fragment e nelle variabili (`on HBSeoPost`), i tipi
  TypeScript, i tag della cache.

Ogni query del foglio si prova contro wp-env. In `hello_standard` il porting tocca: `lib/data/queries/` (`getForm`,
`getPopup`, `getBanner`, `getOrari`, `getSeo`, `getSchema`, `getSitemap`, `getRedirects`, `getLLMS`, `getInfoAzienda`),
`SeoFragment` in `lib/fragments.ts`, `app/api/submit-form/route.ts`, `app/api/llms/route.ts`,
`app/api/llms-full/route.ts`, `components/standard/hb-form/` (`hb-form.tsx`, `field-handler.tsx`) e i tipi in
`lib/types/general.ts`. Portarlo è un lavoro a parte, in un'altra sessione, usando solo il foglio: è la sua prova.

## 10. Fasi

| # | Fase | Pronta quando |
|---|---|---|
| G1 | **Form** (`forms.php`) | Un form multipagina con consenso, checkbox, data, file e logica condizionale si legge e si invia con i nomi nuovi; un invio rifiutato dà un errore per campo con `name`; `hbForms` senza permesso è un errore |
| G2 | **SEO** (`seo.php`) | `seo` su contenuti e termini uguale all'head; impostazioni, schema, sitemap, redirect e llms con i nomi nuovi e le date ISO; con il prototipo acceso `seo` risponde con i dati di Barrio Suite |
| G3 | **Popup, banner e orari** (`popups.php`, `banners.php`, `hours.php`) | Popup e banner con `databaseId` e `text`; orari in inglese con settimana, settimana normale, giorni speciali e `syncedAt`; nessun nome italiano nello schema |
| G4 | **Dati aziendali**, il modulo (§5): opzione e schema, REST, pagina, blocco e shortcode, schema SEO, l'anagrafica spostata da SEO → Organizzazione | Lo schema JSON rifiuta email e URL non validi; la pagina salva l'anagrafica, che lo schema SEO legge da qui; SEO → Organizzazione senza i campi spostati, con l'avviso; blocco e shortcode danno lo stesso HTML, e niente senza dati; il JSON-LD ha `legalName` e `taxID`; con il modulo spento non resta niente; controllo nel browser della pagina e del blocco |
| G5 | **Dati aziendali in GraphQL e migrazione** (`company.php` nelle due estensioni) | `hbCompanyInfo` dà ogni dato che dava il prototipo, tranne `sitoWeb`; su wp-env con il prototipo e i suoi dati, prova a vuoto e migrazione con gli stessi conteggi, e si può rilanciare; un salvataggio fa partire la revalidation |
| G6 | **Foglio di porting e documentazione** | Ogni query del foglio risponde su wp-env; ogni query `hb*` di `hello_standard` ha la sua sostituta; aggiornati `docs/hooks.md` (§ GraphQL e hook nuovi), `docs/database.md`, l'intestazione e il `readme.txt` del plugin e dell'estensione con le traduzioni, i commenti dei file ("lo schema del prototipo"), [PLAN-MODULI.md](PLAN-MODULI.md) (riga Headless delle decisioni, riga dell'estensione, fasi M8 e M10) e la riga su GraphQL di `docs/audit-moduli.md` |

G1, G2 e G3 non dipendono l'una dall'altra; G5 viene dopo G4, G6 per ultima.

Ogni fase si chiude come le altre: test verdi su MariaDB 10.5 e MySQL 8.0 con e senza WPGraphQL, PHPStan, PHPCS,
ESLint, un plugin installabile, traduzioni, lo stesso commit su `con-updater` e `senza-updater`.

**Un solo commit per tutto il piano.** I commit delle fasi servono solo mentre si lavora: alla fine di G6 quelli del
piano, dal primo (`docs: PLAN-GRAPHQL.md`) all'ultimo, si uniscono in uno solo, su `con-updater` e su `senza-updater`.
Fino ad allora niente push, così l'unione non riscrive commit già pubblicati.
