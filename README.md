# WP Barrio

Suite di strumenti per WordPress sviluppata da **HelloBarrio**. Il plugin raccoglie tre moduli indipendenti pensati per velocizzare il workflow di sviluppo di siti headless WordPress + Next.js.

- **Auto Alt** — Genera automaticamente alt text e title delle immagini con AI
- **Forms** — Builder drag & drop per form di contatto, con supporto GraphQL
- **Auto Fields** — Crea gruppi di campi ACF e query GraphQL partendo dall'HTML di una pagina

---

## Requisiti

| Requisito | Versione minima |
| --------- | --------------- |
| WordPress | 6.0             |
| PHP       | 8.0             |

### Dipendenze opzionali

| Plugin                                                                | Richiesto da                |
| --------------------------------------------------------------------- | --------------------------- |
| [WPGraphQL](https://www.wpgraphql.com/)                               | Forms (esposizione GraphQL) |
| [Advanced Custom Fields (ACF)](https://www.advancedcustomfields.com/) | Auto Fields                 |

---

## Installazione

1. Copia la cartella `wp-barrio` nella directory `wp-content/plugins/` del tuo WordPress.
2. Attiva il plugin dalla pagina **Plugin** dell'admin.
3. Accedi alla voce **WP Barrio** nel menu laterale per configurare i moduli.

---

## Moduli

### 1. Auto Alt

Genera automaticamente **alt text** e **title** delle immagini caricate nella Libreria Media, sfruttando modelli AI vision.

#### Provider supportati

| Provider             | Modelli                            |
| -------------------- | ---------------------------------- |
| **Google Gemini**    | Modelli Gemini con supporto vision |
| **Anthropic Claude** | Modelli Claude con supporto vision |

#### Funzionalità

- **Auto-generazione all'upload**: quando attivata, ogni nuova immagine caricata riceve automaticamente alt e title generati dall'AI.
- **Elaborazione in batch**: processa in background le immagini già presenti nella libreria che hanno alt o title mancanti, a lotti di 5 alla volta tramite WP Cron.
- **Revisione manuale**: interfaccia admin per visualizzare e modificare gli alt/title generati prima della pubblicazione.
- **Personalizzazione del prompt**: tono (neutro, professionale, amichevole, tecnico, creativo, SEO), stile (descrittivo, contestuale, evocativo, giornalistico), contesto del sito e parole chiave opzionali.
- **Multilingua**: supporto per italiano, inglese, spagnolo, francese e tedesco.
- **Log attività**: registro delle ultime 200 operazioni (successi, errori, modifiche manuali).

#### Configurazione

Dalla pagina **WP Barrio → Auto Alt → Impostazioni**:

1. Seleziona il provider AI (Gemini o Anthropic).
2. Inserisci la chiave API del provider scelto.
3. Scegli il modello da utilizzare.
4. Configura tono, stile e lingua.
5. (Opzionale) Attiva l'auto-generazione all'upload.

---

### 2. Forms

Builder drag & drop per creare **form di contatto** personalizzati, salvare le submission e opzionalmente esporre tutto via **WPGraphQL** per il frontend headless.

#### Tipi di campo supportati

`text`, `email`, `textarea`, `tel`, `number`, `datepicker`, `select`, `radio`, `checkbox`, `file`

#### Funzionalità

- **Builder visuale**: crea e modifica form con drag & drop direttamente dal pannello admin.
- **Shortcode**: inserisci qualsiasi form nelle pagine WP con `[wpb_form id="X"]`.
- **Submission**: le risposte vengono salvate nel database con IP, user agent e timestamp. Consultabili dalla pagina admin del form.
- **Logica condizionale**: mostra/nascondi campi in base al valore di altri campi, con operatori (`=`, `!=`, `contains`, `empty`, `notempty`) e relazioni (`AND`/`OR`).
- **Comportamento post-invio**: messaggio di successo personalizzato oppure redirect a URL.
- **Info pagina**: opzione per registrare URL, titolo e referrer della pagina in cui il form è stato compilato.

#### GraphQL (richiede WPGraphQL)

Quando WPGraphQL è attivo, il modulo registra automaticamente:

**Query**

- `hbForm(id: Int)` → restituisce un form completo (campi, impostazioni, validazioni)
- `hbForms` → lista di tutti i form

**Mutation**

- `hbSubmitForm(input: { formId, fields, pageUrl?, pageTitle?, pageReferrer? })` → invia una submission

#### CORS per frontend headless

Per permettere mutation GraphQL da un dominio esterno (es. Next.js), definisci in `wp-config.php`:

```php
define('WPB_GRAPHQL_ALLOWED_ORIGINS', 'https://www.miosito.it,https://staging.miosito.it');
```

---

### 3. Auto Fields

Analizza la struttura HTML di una pagina web (tramite URL) e genera automaticamente una **bozza di gruppo campi ACF** con la relativa **query GraphQL** pronta all'uso.

#### Come funziona

1. Inserisci l'URL di una pagina (es. un mockup statico o un sito di riferimento).
2. Il parser HTML analizza la struttura DOM ed estrae le sezioni (`<section>`) e i contenuti al loro interno.
3. Viene mostrata un'anteprima dei campi rilevati, organizzati per sezione.
4. Conferma il nome del gruppo e la location rule (post type, pagina, template).
5. Il modulo crea il gruppo di campi ACF tramite `acf_import_field_group()` e genera la query GraphQL corrispondente.

#### Elementi rilevati dal parser

| Elemento HTML                    | Tipo campo ACF generato |
| -------------------------------- | ----------------------- |
| `<h1>`–`<h6>`                    | Text                    |
| `<p>` (breve)                    | Text                    |
| `<p>` (lungo, >200 caratteri)    | Textarea                |
| `<p>` con formattazione inline   | WYSIWYG                 |
| `<img>`, `<picture>`, `<figure>` | Image                   |
| `<figcaption>`                   | Text                    |
| `<blockquote>`                   | Textarea                |
| `<video>`                        | URL / File              |
| `<ul>`, `<ol>` con >3 voci       | Repeater                |
| `<a>` (link)                     | Link / URL              |

#### Requisiti

- **ACF** deve essere installato e attivo per la generazione dei gruppi di campi.

---

## Struttura del plugin

```
wp-barrio/
├── wp-barrio.php                    # Entry point, costanti globali, caricamento moduli
├── modules/
│   ├── dashboard/
│   │   └── dashboard.php            # Dashboard principale con card dei moduli
│   ├── auto-alt/
│   │   └── auto-alt.php             # Logica Auto Alt (API AI, batch, log)
│   ├── auto-fields/
│   │   └── auto-fields.php          # Parser HTML e generazione ACF + GraphQL
│   └── forms/
│       ├── forms.php                # Builder form, shortcode, submission
│       └── graphql.php              # Schema WPGraphQL per i form
└── README.md
```

---

## Autore

Sviluppato da **[HelloBarrio](https://www.hellobarrio.com/)**.

---

## Licenza

Uso interno — tutti i diritti riservati.
