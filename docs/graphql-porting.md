# Portare un frontend allo schema GraphQL di Barrio Suite

Per chi porta un frontend headless (Next.js, come `hello_standard`) dallo schema `hb*` di Suite Barrio, il prototipo,
a quello di `barrio-suite-wpgraphql`, senza altro contesto. Le query restano annidate come prima: cambiano alcuni nomi
e alcuni valori, e qualche campo non c'è più. I due schemi non convivono: un nome vecchio dà un errore di validazione
GraphQL, e la risposta non arriva.

## 1. Quando pubblicarlo

Sul WordPress del sito servono Barrio Suite, `barrio-suite-wpgraphql` e i moduli accesi (Barrio → Settings → Modules).
Con un modulo acceso, i nomi che ha anche il prototipo (`hbForm`, `hbSubmitForm`, `hbPopup`, `hbBanner`,
`hbCompanyInfo`, `seo`, `hbSitemap`, `hbRedirects`, `hbLlms`) rispondono con lo schema nuovo, e le query vecchie danno
errore. Se il sito ha ancora il prototipo, la migrazione (Barrio → Migration, estensione
`barrio-suite-migration`) tiene spenti il SEO e gli altri moduli di Barrio Suite finché quelli del prototipo non sono
migrati, e alla fine li accende; Forms no: è acceso da subito, quindi `hbForm` e `hbSubmitForm` cambiano appena
`barrio-suite-wpgraphql` è attiva. Il frontend portato si pubblica quindi insieme all'estensione e alla migrazione. I
nomi che ha solo il prototipo (`hbOrari`, `hbSeoSite`, `hbSchemaRaw`) rispondono finché il prototipo è attivo, con i
suoi dati: nel frontend portato non ne resta nessuno.

Ogni query si prova sul WordPress del sito, nell'IDE di GraphQL → GraphiQL IDE o con
`curl -s https://<wordpress>/graphql -H 'Content-Type: application/json' -d '{"query":"{ hbSeoSettings { siteTitle } }"}'`:
la risposta non deve avere `errors`.

## 2. Regole generali

| Prima | Dopo | Dove |
|---|---|---|
| Argomento `lang` | `language`, con lo stesso valore: una lingua attiva (`it`, `en`), altrimenti la principale | `hbForm`, `hbPopup`, `hbBanner`, `hbOpeningHours` e l'input di `hbSubmitForm` |
| `id` | `databaseId` | Form, elenco dei form, popup, banner, voci della sitemap, redirect |
| Tipi `HB*` nei fragment e nelle variabili | `Hb*` | `on HBSeoPost` → `on HbSeo`; `HbSubmitFormInput` era già così |
| `$id: Int` | `$id: Int!` | `hbForm` |
| Valori minuscoli (`show`, `redirect`) | Enum in maiuscolo (`SHOW`, `REDIRECT`) | Logica condizionale, `afterSubmit`, `titleOrder`, `source` degli orari |
| Date in quattro formati | ISO 8601 in UTC, come `2026-12-01T09:00:00+00:00`: si leggono con `new Date(valore)` | `createdAt`, `updatedAt`, `syncedAt` |
| Booleani senza `is` | Con `is`: `isRequired`, `isClosed` | Campi dei form, giorni degli orari |
| Nomi italiani | Inglesi | Orari, dati aziendali |

Nelle query qui sotto la variabile si chiama come l'argomento (`$language`): nel codice cambia anche la chiave delle
variabili (`{ language: lang }`). I tag della cache non cambiano (§9).

## 3. Form

### 3.1 Lettura (`lib/data/queries/getForm.ts`)

```graphql
query getForm($id: Int!, $language: String = "it") {
  hbForm(id: $id, language: $language) {
    databaseId
    title
    steps {
      fields {
        name
        type
        label
        placeholder
        isRequired
        isMultiple
        pattern
        errorMessage
        min
        max
        format
        options { value label }
        conditionalLogic {
          action
          logicType
          rules { fieldName operator value }
        }
      }
    }
    settings {
      submitText
      successMessage
      afterSubmit
      redirectUrl
    }
  }
}
```

| Prima | Dopo | Nel codice |
|---|---|---|
| `hbForm(id, lang)` | `hbForm(id, language)`, `id` obbligatorio | Variabili `{ id, language: lang }`. `null` se il form non c'è o è nel cestino |
| `id` del form | `databaseId` | `form.id` → `form.databaseId`: gli `id` HTML (`hbform${form.id}`) e `formId` dell'invio |
| `fields { id }` | Tolto: era uguale a `name` | `field.name` |
| `required` | `isRequired` | `field.required` → `field.isRequired`, nei campi e nelle regole di validazione |
| `multiple` (solo in un prototipo modificato sul server) | `isMultiple`: `true` per una select multipla, `false` per tutti gli altri campi | `field.multiple` → `field.isMultiple`, nel tipo e nella scelta del componente (`MultiSelectCombobox`) |
| `conditionalLogic.enabled` | Tolto: era sempre `true`. Un campo sempre visibile ha `conditionalLogic: null` | `!cl?.enabled` → `!cl` |
| `action`: `show`, `hide` | `SHOW`, `HIDE` | `action === "show"` → `action === "SHOW"`; un default `?? "show"` → `?? "SHOW"` |
| `logicType`: `all`, `any` | `ALL`, `ANY` | `logicType === "all"` → `logicType === "ALL"` |
| `settings.afterSubmit`: `message`, `redirect` | `MESSAGE`, `REDIRECT` | `== "redirect"` → `=== "REDIRECT"`, `== "message"` → `=== "MESSAGE"` |
| `settings.errorMessage` | Tolto: era sempre vuoto | Il messaggio d'errore del frontend (`t("sendError")`) |
| `settings.displayPageInfo` | Tolto: era sempre `true` | Si invia sempre `pageUrl`. Il campo `currentpage` che il frontend aggiungeva ai valori si toglie: nessun form ha un campo con quel nome |
| `createdAt`, `updatedAt` in `Y-m-d H:i:s` nel fuso del sito | ISO 8601 in UTC | `new Date(createdAt)` |

Restano come prima `type` (`text`, `email`, `textarea`, `tel`, `number`, `datepicker`, `select`, `radio`, `checkbox`,
`file`, `hidden` o il tipo di un'estensione), `label` con i link, `options`, `operator` (`=`, `!=`, `>`, `<`, `contains`,
`starts_with`, `ends_with`, `empty`, `not_empty`) e il consenso: una `checkbox` senza etichetta con una sola scelta, il
cui `label` è il testo con il link.

### 3.2 Invio (`app/api/submit-form/route.ts`, `lib/data/queries/submitForm.ts`)

```graphql
mutation submitForm($input: HbSubmitFormInput!) {
  hbSubmitForm(input: $input) {
    success
    message
    entryId
    errors { name message }
  }
}
```

L'input: `{ formId, fields: [{ name, value }], language, pageUrl }`.

| Prima | Dopo | Nel codice |
|---|---|---|
| Input `lang` | `language` | `...(lang && { lang })` → `...(lang && { language: lang })` |
| Input `pageTitle`, `pageReferrer` | Tolti: il server li ignorava | Non si inviano: un campo che l'input non ha è un errore GraphQL e il form non parte |
| `submissionId` | `entryId` | Nella mutation, nella risposta della route e nel tipo del risultato |
| `errors: [String]`, come `"Email: non valida"` | `errors { name message }`: uno per campo, con `name` la chiave del campo e `message` senza l'etichetta. Un errore del form intero (non trovato, chiuso, troppi invii) ha `name: null`. Vuota con successo | Un errore sotto il suo campo: `errors.forEach(e => e.name ? setError(e.name, { message: e.message }) : …)` |
| `debug` | Tolto: era sempre `null` | |

`message` resta com'era: il messaggio di successo, oppure il primo errore con la sua etichetta. Un invio rifiutato
torna con `success: false`, non come errore GraphQL. I valori si inviano come prima: checkbox come
`"Etichetta: true | Etichetta: false"` o valori separati da virgole, la select multipla come la checkbox (un frontend
con la select multipla univa i valori con `|`: ora con `,`), date come `dd/MM/yyyy` o `yyyy-MM-dd`, file come
JSON `{"content": "<base64>", "filename": "cv.pdf"}`, il consenso come la checkbox con una scelta. I valori per le
estensioni stanno tra i campi (`{ name: "_barrio_recaptcha", value: token }`). L'invio con l'application password del
frontend non ha il limite per IP solo se il suo utente può scrivere contenuti (da Autore in su).

### 3.3 Elenco dei form

`hello_standard` non lo usa.

```graphql
query getForms {
  hbForms {
    databaseId
    title
    entryCount
    createdAt
  }
}
```

Prima era pubblico, con `id` e `submissions`. Ora serve un utente che può modificare gli articoli o gestire le
registrazioni (l'application password); senza è un errore GraphQL. `entryCount` è `null` per chi non gestisce form o
registrazioni.

## 4. SEO

### 4.1 Il campo `seo` (`SeoFragment` in `lib/fragments.ts`)

```graphql
query getPageSeo($uri: ID!) {
  page(id: $uri, idType: URI) {
    seo { ...SeoFragment }
  }
}

fragment SeoFragment on HbSeo {
  title
  description
  keywords
  robots
  canonical
  ogImage
  ogType
  twitterCard
  isIndexed
  isInSitemap
  ogDescription
  ogTitle
  twitterImage
  twitterTitle
}
```

Cambia solo il tipo del fragment: `on HBSeoPost` → `on HbSeo`. I campi sono gli stessi. In più, `seo` c'è anche sui
termini delle tassonomie del SEO esposte in GraphQL (categorie, tag, tassonomie personalizzate), con gli stessi campi,
come `category(id: $uri, idType: URI) { seo { ...SeoFragment } }`: `ogType` è `website`, `isInSitemap` è vero per un
termine indicizzato con contenuti. `keywords` è vuota per chi non può
modificare il contenuto o il termine. Di un contenuto non pubblicato `seo` è `null` per chi non lo può modificare.

### 4.2 Impostazioni (`lib/data/queries/getSeo.ts`)

```graphql
query getSeo {
  hbSeoSettings {
    siteTitle
    defaultDescription
    separator
    titleOrder
    favicon
    ogImage
  }
}
```

| Prima | Dopo | Nel codice |
|---|---|---|
| `hbSeoSite` | `hbSeoSettings` | `response.hbSeoSite` → `response.hbSeoSettings` |
| `defaultDesc` | `defaultDescription` | `seo.defaultDesc` → `seo.defaultDescription` (`app/[locale]/layout.tsx`) |
| `titleOrder`: `page_site`, `site_page` | `PAGE_SITE`, `SITE_PAGE` | I confronti in maiuscolo |
| `robotsDefault` | Tolto: era sempre `index,follow` | L'indicizzazione di ogni pagina è in `seo.robots` |

Nome, contatti, indirizzo e profili dell'azienda non sono qui: si leggono da `hbCompanyInfo` (§7), il resto
dell'organizzazione è nel JSON-LD di `hbSeoSchema`.

### 4.3 Schema (`lib/data/queries/getSchema.ts`)

```graphql
query getSchema($uri: String) {
  hbSeoSchema(uri: $uri)
}
```

`hbSchemaRaw` → `hbSeoSchema`, anche nella lettura: `response.hbSchemaRaw` → `response.hbSeoSchema`. Il valore è lo
stesso: il JSON-LD come stringa, da mettere in `<script type="application/ld+json">`. Senza `uri`, o con un archivio o
una pagina noindex, ci sono solo organizzazione e sito.

### 4.4 Sitemap (`lib/data/queries/getSitemap.ts`, `app/sitemap.ts`)

```graphql
query getSitemap {
  hbSitemap {
    loc
    lastmod
  }
}
```

| Prima | Dopo | Nel codice |
|---|---|---|
| `priority` | Tolto: era sempre `null` | `priority: post.priority` in `app/sitemap.ts` si toglie |
| `id`, una stringa | `databaseId`, un intero (0 per la home con gli ultimi articoli) | Se letto |

Restano `type` (`post`, `term` o `user`), `subtype` (il tipo di contenuto, la tassonomia o `author`) e `title`. `loc` è
un percorso: il frontend aggiunge il suo dominio.

### 4.5 Redirect (`lib/data/queries/getRedirects.ts`)

```graphql
query getRedirects {
  hbRedirects {
    from
    to
    code
    note
  }
}
```

La query non cambia. `id` diventa `databaseId`, un intero. `to` è `null` per un 410; `note` è `null` per chi non
gestisce il SEO (prima era pubblica).

### 4.6 llms.txt (`lib/data/queries/getLLMS.ts`, `app/api/llms/route.ts`, `app/api/llms-full/route.ts`)

```graphql
query getLLMS {
  hbLlms { content updatedAt bytes }
  hbLlmsFull { content updatedAt bytes }
}
```

La query non cambia, ma `updatedAt` sì: era un timestamp Unix in una stringa (`"1764000000"`), ora è ISO 8601 in
UTC. `new Date(updatedAt * 1000)` → `new Date(updatedAt)`, in tutte e due le route. Il tipo si chiama `HbLlms` (era
`LlmsContent`), se compare in un fragment.

## 5. Popup e banner

### 5.1 Popup (`lib/data/queries/getPopup.ts`)

```graphql
query hbPopup($language: String = "it") {
  hbPopup(language: $language) {
    databaseId
    title
    content
    link { url title target }
    image { node { ...MediaItemFragment } }
  }
}

fragment MediaItemFragment on MediaItem {
  altText
  title
  sourceUrl
  mediaDetails { width height }
}
```

`hbPopup(lang)` → `hbPopup(language)`, `id` → `databaseId`; variabili `{ language: lang }`. Il resto è uguale: `link`
è `null` senza pulsante, `image` senza immagine, e in `content` resta lo shortcode del form (`[barrio_form id="3"]`),
da leggere con `hbForm`. Facoltativi: `days`, i giorni prima di riproporlo a chi l'ha chiuso (al posto di un valore
fisso come `exp={24}` in `hb-popup-container.tsx`), e `startsAt`, `endsAt` in ISO 8601.

### 5.2 Banner (`lib/data/queries/getBanner.ts`)

```graphql
query hbBanner($language: String = "it") {
  hbBanner(language: $language) {
    text
    link { url target }
  }
}
```

| Prima | Dopo | Nel codice |
|---|---|---|
| `hbBanner(lang)` | `hbBanner(language)` | Variabili `{ language: lang }` |
| `title` | `text`: è il testo del banner | `banner.title` → `banner.text` (`hb-banner-container.tsx`, due volte) |
| `id` | `databaseId` | Se letto |

## 6. Orari (`lib/data/queries/getOrari.ts`)

```graphql
query getOpeningHours($language: String) {
  hbOpeningHours(language: $language) {
    syncedAt
    displayName
    currentWeek {
      day
      slots
      isClosed
    }
  }
}
```

| Prima | Dopo | Nel codice |
|---|---|---|
| `hbOrari(lang)` | `hbOpeningHours(language)` | `response.hbOrari` → `response.hbOpeningHours` |
| `ultimoAggiornamento`, già scritto come `d/m/Y H:i` nel fuso del sito | `syncedAt`, ISO 8601 in UTC; `null` con gli orari scritti a mano | Si formatta nel frontend: `new Intl.DateTimeFormat(locale, { dateStyle: "short", timeStyle: "short", timeZone }).format(new Date(syncedAt))`, con il fuso del sito (`generalSettings { timezone }`) |
| `settimana` | `currentWeek`: i prossimi 7 giorni da lunedì, con i giorni speciali | |
| `giorno` | `day`: il nome del giorno nella lingua chiesta | |
| `chiuso` | `isClosed` | |
| `settimanaNormale` | `regularWeek` | |
| `giorniSpeciali { dal al nota }` | `specialDays { startDate endDate note }` | Date `Y-m-d` come prima |
| `fasce { apertura chiusura }` | `periods { opens closes }` | `HH:MM` come prima |
| `fonte`: `manual`, `google` | `source`: `MANUAL`, `GOOGLE` | I confronti in maiuscolo |

`slots` (`09:00 - 13:00`, o `9:00 AM - 1:00 PM` in inglese) e `displayName` restano. Senza orari le settimane sono
vuote.

## 7. Dati aziendali (`lib/data/queries/getInfoAzienda.ts`)

```graphql
query getCompanyInfo {
  hbCompanyInfo {
    name
    legalName
    phone
    mobile
    email
    privacyEmail
    certifiedEmail
    vatId
    taxCode
    registrationNumber
    shareCapital
    invoiceCode
    address {
      name street postalCode locality region country mapsUrl
      phones { label value }
      emails { label value }
    }
    offices {
      name street postalCode locality region country mapsUrl
      phones { label value }
      emails { label value }
    }
    profiles { network url }
  }
}
```

| Prima | Dopo |
|---|---|
| `ragioneSociale` | `legalName`; vuota, vale `name` |
| — | `name`, il nome con cui l'azienda è conosciuta |
| `telefono`, `cellulare` | `phone`, `mobile` |
| `email`, `emailPrivacy`, `pec` | `email`, `privacyEmail`, `certifiedEmail` |
| `partitaIva`, `codiceFiscale` | `vatId`, `taxCode` |
| `rea`, `capitaleSociale`, `codiceUnivoco` | `registrationNumber`, `shareCapital`, `invoiceCode` |
| `sedeLegale { via citta cap provincia googleMaps }` | `address { street locality postalCode region mapsUrl }`, più `country` (ISO 3166-1, come `IT`) |
| `sedeOperativa { … }` | `offices { … }`, una lista: le sedi operative, la prima è quella del prototipo |
| — | `name` in ogni sede, il nome facoltativo ("Magazzino di Milano") |
| `telefoni { label value }`, `email { label value }` in una sede (solo prototipi modificati sul server) | `phones { label value }`, `emails { label value }` |
| `social { facebook instagram linkedin twitter youtube telegram tiktok }` | `profiles { network url }` |
| `sitoWeb` | Tolto: l'indirizzo del sito lo conosce il frontend (`process.env.SITE_DOMAIN`), quello di WordPress è `generalSettings { url }` |

- Un dato vuoto è `null`, come prima. `address` è `null` senza indirizzo; `offices` è vuota quando la sede legale è
  l'unica: `infoAzienda.sedeOperativa.via` → `company.offices[0]?.street`, oppure un `map` su tutte le sedi.
- `phones` ed `emails` di una sede non sono mai `null`: vuote senza contatti. `label` è `null` se non c'è.
- `profiles` è una lista al posto dei sette social fissi, nell'ordine scelto in WordPress. `network` è la rete
  riconosciuta dall'indirizzo (`facebook`, `instagram`, `linkedin`, `x`, `youtube`, `telegram`, `tiktok`, `whatsapp`,
  `pinterest`, `threads`), oppure `null` per un altro sito: `social.facebook` →
  `profiles.find((p) => p.network === "facebook")?.url`, e `social.twitter` → `network === "x"`.

## 8. Tipi TypeScript (`lib/types/general.ts`)

- `HB_Form_Type`: `id` → `databaseId`; `settings` senza `errorMessage` né `displayPageInfo`, con
  `afterSubmit: "MESSAGE" | "REDIRECT"`.
- `inputField`: `required` → `isRequired`, `multiple` → `isMultiple`; `conditionalLogic` è
  `{ action: "SHOW" | "HIDE"; logicType: "ALL" | "ANY"; rules: { fieldName: string; operator: string; value: string | null }[] } | null`.
- Il risultato dell'invio (`submitForm.ts`): `submissionId` → `entryId`,
  `errors: { name: string | null; message: string }[]`; senza `pageTitle` tra le opzioni.

## 9. Cache

I tag non cambiano: `barrio-suite-wpgraphql` chiama `GET {frontend}/api/revalidate/?tag=a,b` come il prototipo, con
gli stessi tag (`wp:uri:…`, `wp:type:…`, `page_{id}`, `wp:menu`, `form_{id}`, `wp:popup`, `wp:banner`, `wp:orari`,
`wp:option`, `wp:seo`…), all'indirizzo del frontend di Barrio → Impostazioni → Frontend headless (la migrazione lo
prende dal sito web di Info Azienda). Il salvataggio dei Dati aziendali manda `wp:option`, che deve stare sia in `getInfoAzienda` sia in
`getSchema`, perché anche il JSON-LD prende da lì nome, contatti e indirizzo.

Il prototipo mandava `Authorization: Bearer YOUR_TOKEN` e la route non lo controllava: chiunque poteva svuotare la
cache. Barrio Suite manda il token della stessa card (Token di revalidation); la route lo controlla quando il
frontend ha `REVALIDATE_TOKEN`, all'inizio di `GET` in `app/api/revalidate/route.ts`:

```ts
const token = process.env.REVALIDATE_TOKEN;
if (token && request.headers.get("authorization") !== `Bearer ${token}`) {
  return Response.json({ message: "Invalid token" }, { status: 401 });
}
```

Lo stesso valore va in WordPress e in `REVALIDATE_TOKEN` (`.env.example` senza valore, le variabili del progetto su
Vercel). Svuota cache, nella stessa card (Svuota la cache), prova la chiamata: con un token diverso risponde 401.
Finita la migrazione, il prototipo e `barrio-suite-migration` si eliminano: la revalidation resta.

## 10. Lista di controllo finale

Ogni riga trovata qui, in `app`, `components` e `lib`, è un punto da portare o da ricontrollare:

```sh
grep -rnE 'hbOrari|hbSeoSite|hbSchemaRaw|lang: \$|\$id: Int[^!]|on HB[A-Z]|\$[A-Za-z]+: \[?HB[A-Z]|LlmsContent|submissionId|displayPageInfo|currentpage|pageTitle|pageReferrer|defaultDesc\b|ultimoAggiornamento|settimana|giorniSpeciali|fasce|sedeLegale|sedeOperativa|ragioneSociale|partitaIva|sitoWeb|social\.|\.priority|cl\?\.enabled|\* 1000|==+ ?"(show|hide|all|any|message|redirect|manual|google|page_site|site_page)"|\?\? ?"(show|hide|all|any)"' app components lib
```

Poi, a mano:

- [ ] Ogni query del frontend risponde senza `errors` sul WordPress del sito (§1).
- [ ] `databaseId` dove il codice leggeva `id`: form, popup, banner, sitemap, redirect.
- [ ] I confronti con gli enum in maiuscolo: `"SHOW"`, `"HIDE"`, `"ALL"`, `"ANY"`, `"MESSAGE"`, `"REDIRECT"`,
      `"PAGE_SITE"`, `"SITE_PAGE"`, `"MANUAL"`, `"GOOGLE"`.
- [ ] Le date lette come ISO 8601: `createdAt`, `updatedAt`, `syncedAt`.
- [ ] Un invio con un campo obbligatorio vuoto mostra l'errore sotto quel campo; uno riuscito fa ciò che dice
      `afterSubmit`.
- [ ] I tipi TypeScript del §8, e la build del frontend (`npm run build`) senza errori.
- [ ] I tag della cache e il controllo del token del §9.
