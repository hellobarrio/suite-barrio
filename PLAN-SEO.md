# Barrio Suite: piano del modulo SEO

Secondo modulo di Barrio Suite, riscritto da zero dal modulo SEO di `suite-barrio-prototipo`
(`modules/seo/`, ~7.900 righe): si conservano le funzionalità, non il codice. Valgono tutte le decisioni
di [PLAN.md](PLAN.md) (database, REST, admin, tabelle, estensioni, privacy, traduzioni, test): qui c'è
solo ciò che è nuovo. Prima del modulo serve una fase sul core: accendere e spegnere i moduli, e
portare nel core il menu e l'app admin, che oggi stanno nel modulo Forms (PLAN.md §3.5).

Si lavora su `con-updater` e ogni fase si porta su `senza-updater` con un cherry-pick: tra i due branch
resta una sola differenza, l'updater da GitHub.

## Decisioni

| Tema | Decisione |
|---|---|
| Nomi | `modules/seo/`, namespace `BarrioSuite\Seo\`, prefisso `barrio_seo_`, meta `_barrio_seo_*`, tabella `{prefix}barrio_redirects`, capability `barrio_manage_seo` e `barrio_edit_seo` |
| Moduli accesi e spenti | In Barrio → Impostazioni, card Moduli: un interruttore per modulo e un solo Salva nella barra. **Tutti i moduli sono accesi di default**, anche quelli che arriveranno con gli aggiornamenti: l'opzione `barrio_disabled_modules` salva solo quelli spenti. Su un sito con Yoast o Rank Math il modulo SEO va spento dalla card (lo dicono la card e il readme). Sui siti del prototipo lo tiene spento l'estensione di migrazione fino alla migrazione (§7, S7) |
| Plugin esterni | Come nei form, tutto ciò che dipende da un plugin esterno o serve solo con lui sta in un'estensione: anche le impostazioni. L'indirizzo del frontend headless, per esempio, è dell'estensione WPGraphQL, non del modulo SEO. Il core espone solo i propri hook |
| Modulo spento | Il modulo non registra hook, menu, rotte REST, blocchi o cron, e le sue migrazioni non girano: le tabelle si creano alla prima accensione. I dati restano, come quando si disattiva il plugin. Le estensioni del modulo restano ferme, perché `barrio_<modulo>_loaded` non parte. Con Forms spento, `[barrio_form]` non stampa niente (invece del testo dello shortcode) |
| WordPress classico e headless | Il prototipo è quasi solo headless: in WordPress stampa i meta, mentre sitemap, redirect e JSON-LD esistono solo in GraphQL. Barrio Suite va su WordPress.org, quindi il modulo fa tutto anche in WordPress: head, `wp-sitemap.xml`, redirect, JSON-LD e `llms.txt`. Ai siti headless gli stessi dati arrivano dall'estensione `barrio-suite-wpgraphql`, con i nomi del prototipo |
| Una sola risoluzione | Titolo, descrizione, robots, canonical, immagine e indicizzazione si calcolano in un solo punto (`Seo\Meta`). Lo usano head, sitemap, schema, llms.txt e GraphQL. Nel prototipo la stessa logica è copiata cinque volte e dà risultati diversi |
| Prima WordPress | Per la sitemap si usa quella del core (`wp-sitemap.xml`, filtrata; `/sitemap.xml` lo redirige già il core). Poi `wp_robots`, `pre_get_document_title` e `get_canonical_url`, il redirect dei vecchi slug del core, le pagine degli allegati spente dal core (`wp_attachment_pages_enabled`). Nome e icona del sito restano le opzioni di WordPress (`blogname`, `site_icon`): niente copie |
| Dati dell'azienda | Stanno nelle impostazioni SEO, card Organizzazione, come la "Local SEO" di Rank Math: servono allo schema. Il futuro modulo Dati aziendali (`info-azienda`, `hbCompanyInfo`) li leggerà da qui invece di tenerne una copia, come succede oggi nel prototipo |
| AI | Titoli e descrizioni generati con Anthropic o Gemini, e l'introduzione di llms.txt: estensione `barrio-suite-ai`, perché è un servizio di terzi come Mailchimp. Il core non fa chiamate esterne |
| Sitemap | Niente interruttore "in sitemap" separato: in sitemap c'è ciò che è indicizzabile, come in Yoast e Rank Math. Il prototipo aveva due fonti per la stessa scelta, e una non si poteva impostare |
| Lingue | `I18n` passa dal modulo Forms al core, perché SEO non può dipendere da un modulo che si spegne. I filtri si rinominano `barrio_languages`, `barrio_current_language`, `barrio_locale` e `barrio_language_flags`, e l'estensione WPML cambia nello stesso rilascio |
| SMTP | La card SMTP esce da Form → Impostazioni e va in Barrio → Impostazioni, con l'email di prova: vale per tutte le email del sito e deve restare configurabile anche con Forms spento |

## 1. Problemi del prototipo da non ripetere

| Problema | Nel nuovo modulo |
|---|---|
| Il titolo personalizzato non arriva mai nel `<title>`: il filtro si aggiunge dopo che il titolo è già stampato. Separatore e ordine servono solo all'anteprima | Il titolo lo costruisce un'unica funzione PHP, con fixture condivise con l'anteprima JS (come `conditions.json`) |
| Sui singoli contenuti `canonical` e `robots` escono due volte, perché quelli del core non si tolgono | Si passa dalle API del core invece di stampare accanto |
| Sugli archivi `og:url` punta al primo post; archivi e home non hanno canonical | Canonical e `og:url` per ogni tipo di pagina, paginazione compresa |
| "Predefinito" si perde al primo salvataggio: i campi nascosti scrivono sempre 1 o 0 | Un meta assente vuol dire "eredita"; si salva solo una scelta esplicita |
| La metabox compare solo sui tipi indicizzati, quindi non si può indicizzare un singolo contenuto di un tipo noindex. Salvare la scheda Contenuti mette gli allegati in noindex | Il pannello c'è su tutti i tipi pubblici, con tre stati; gli allegati non sono mai coinvolti |
| Salvare senza favicon cancella `site_icon` | Nessuna copia delle opzioni di WordPress |
| La sitemap ignora noindex, include i contenuti con password e ignora il meta sitemap dei termini; con la home ad articoli dà priorità 1.0 a ogni post; l'hub la ricostruisce tutta a ogni apertura | Sitemap del core, con le esclusioni nella query; niente `priority` (Google la ignora) |
| `llms-full.txt` pubblica il contenuto dei post protetti da password. I file si scrivono a mano in `ABSPATH` (permessi, multisite) e si rigenerano solo a mano | Generati alla richiesta e tenuti in cache, senza contenuti protetti o noindex; la cache si svuota quando un contenuto cambia |
| Redirect in un'opzione: un 410 si salva con `to='/'`, la modifica accetta duplicati, nessuno li esegue in WordPress. Il vecchio URL di un termine si legge dopo il salvataggio | Tabella con UNIQUE e CHECK; il vecchio URL si legge prima del salvataggio |
| I dati dell'azienda stanno in due opzioni, senza sincronia. Con l'opzione vuota lo schema genera warning PHP. Per trovare un termine scorre tutti i termini a ogni richiesta | Un solo posto, valori di default dallo schema, ricerca diretta |
| Chiavi API in chiaro, e il codice dei provider AI copiato quattro volte (advisor, llms, alt, cookie) | Un'estensione AI, con le chiavi cifrate da `Crypto` |
| Sei sotto-pagine nascoste con il CSS; il selettore dei media scritto quattro volte | L'app React del plugin, con i suoi componenti |
| GraphQL registra lo schema SEO anche con il modulo spento | L'estensione lo registra solo con il modulo acceso |

## 2. Dati

### 2.1 Per contenuto: meta di WordPress

Niente tabella: i dati SEO appartengono al contenuto. WPML li copia, l'esportazione di WordPress li
porta con sé e la cancellazione li elimina con il post. Si registrano con `register_post_meta` e
`register_term_meta` (`show_in_rest`, schema, `auth_callback` su `edit_post`/`edit_term` più
`barrio_edit_seo`), quindi si leggono e si scrivono anche dalla REST del core.

| Meta (post e termini) | Valore | Assente |
|---|---|---|
| `_barrio_seo_title` | stringa | titolo del contenuto, del termine o dell'archivio |
| `_barrio_seo_description` | stringa | estratto manuale o 160 caratteri del contenuto (descrizione del termine), poi la descrizione predefinita. La stessa regola vale ovunque: nel prototipo head, GraphQL e schema ne usavano tre diverse |
| `_barrio_seo_image` | ID dell'allegato | immagine in evidenza (solo post), poi immagine predefinita |
| `_barrio_seo_keywords` | lista di stringhe; la prima è la principale | nessuna. Servono al punteggio e a `keywords` nello schema; nessun `<meta name="keywords">` |
| `_barrio_seo_index` | `index` \| `noindex` | la scelta del tipo o della tassonomia |
| `_barrio_seo_follow` | `follow` \| `nofollow` | la scelta del tipo o della tassonomia |
| `_barrio_seo_collection` (solo post) | slug di un post type | pagina normale; con uno slug, `CollectionPage` con un `ItemList` di quel tipo |

La sitemap esclude i noindex con una sola `meta_query`: per un tipo indicizzabile scarta
`noindex`, per un tipo noindex tiene solo `index`.

### 2.2 Impostazioni: opzione `barrio_seo`

Ha un JSON Schema e la scrive solo la rotta `PUT /barrio/v1/seo/settings` (come `barrio_smtp`):

- `title`: `separator` (default `—`), `order` (`page_site` \| `site_page`);
- `default_image` (ID);
- `index`: `post_types` e `taxonomies` (`{slug: {index, follow}}`; una chiave assente vale indicizzato),
  `archives` (`author`, `date`). Ricerca ed embed restano noindex come già fa il core;
- `organization`: tipo (le 16 voci del prototipo), nome, logo e immagine (ID, non URL), telefono, email,
  P.IVA, indirizzo, coordinate, zone servite (`[{type, name}]`), competenze (lista), profili
  (`same_as`, una lista di URL al posto dei cinque social fissi), catalogo delle offerte (nome, valuta, voci
  come nel prototipo);
- `llms`: `post_types` e `taxonomies` (`include` \| `links` \| `exclude`);
- `auto_redirects` (default `true`);
- `texts`: `{lingua: {default_description, organization_description, llms_intro}}`. Come nei form, nessun
  testo ha una lingua implicita: senza estensione multilingua c'è solo la lingua del sito.

La lingua dello schema è quella del sito o della pagina, non un'impostazione. Il nome del sito e
l'icona si leggono e si scrivono come opzioni di WordPress con `/wp/v2/settings`.

### 2.3 Redirect: tabella (migrazione `0001`)

```
redirects   id, source VARCHAR(768) utf8mb4_bin UNIQUE, CHECK (source LIKE '/%')
              percorso senza dominio e senza / finale, query string ammessa
            target VARCHAR(2048) NULL (percorso o URL assoluto)
            code SMALLINT, CHECK (code IN (301, 302, 307, 308, 410))
            CHECK ((code = 410) = (target IS NULL))
            note VARCHAR(255) NULL, is_auto (creato dal cambio di URL), created_at, updated_at (UTC)
```

Una tabella e non un'opzione: si legge a ogni richiesta del frontend, con una SELECT sulla UNIQUE, e la
tabella dell'admin la cerca, la ordina e la pagina sul server. L'enum PHP `RedirectCode` coincide con il
CHECK, e i test lo verificano. La migrazione `0002` assegna `barrio_manage_seo` agli admin e
`barrio_edit_seo` ad admin ed editor. Alla disinstallazione (se il sito l'ha scelta) `Module::uninstall()`
toglie anche i meta `_barrio_seo_*`, che il core non vede.

## 3. Architettura

```
src/
├── Admin.php            # menu Barrio e app React (da modules/forms/src/Admin.php)
├── Modules.php          # quali moduli sono accesi; GET/PUT /barrio/v1/modules
└── I18n.php             # dal modulo Forms, con i filtri barrio_languages…
assets/src/              # la shell: ui.js, query.js, Bacheca, Messa online, Impostazioni, Estensioni
modules/forms/assets/src/  # solo le pagine dei form
modules/seo/
├── migrations/0001_redirects.php, 0002_capabilities.php
├── src/
│   ├── Module.php       # boot(): meta registrati, hook, barrio_seo_loaded
│   ├── Settings.php     # barrio_seo: schema e default
│   ├── Meta.php         # la risoluzione unica: post, termine, archivio, home, ricerca, 404
│   ├── Head.php         # titolo, robots e canonical dai filtri del core; description, OG, Twitter, JSON-LD
│   ├── Schema.php       # il grafo JSON-LD
│   ├── Sitemaps.php     # filtri della sitemap del core
│   ├── Redirects.php    # esecuzione e redirect automatici
│   ├── Repository/RedirectRepository.php   # l'unico a usare $wpdb
│   ├── Llms.php
│   ├── Editor.php       # sidebar dell'editor a blocchi, metabox classica, campi dei termini
│   └── Api/RestSettings.php, RestRedirects.php, RestContents.php
└── assets/src/{seo,editor}/   # pagina SEO e pannello; title.js e score.js condivisi
tests/fixtures/seo-title.json  # lo stesso titolo in PHP e in JS
```

Ci sono due build: `barrio-admin` (shell, Forms e SEO) e `seo-editor` (il pannello, caricato nell'editor e
nei termini).

## 4. Output nel sito

- **Titolo**: `{titolo} {sep} {sito}`, o al contrario. Il titolo è il meta, se c'è, altrimenti quello del
  contenuto, del termine o dell'archivio, con la pagina dopo la prima. Funziona con i temi che dichiarano `title-tag`.
- **Meta**:
  - `description`;
  - `robots` con `wp_robots` (il core aggiunge già `max-image-preview:large`);
  - `canonical`, che sui singoli passa da `get_canonical_url` e su archivi, home e tassonomie è il nostro (la pagina 2 ha la sua);
  - Open Graph: `og:type` (`article` sui post, `website` altrove), `title`, `description`, `url` (= canonical), `site_name`, `image` con le dimensioni, `locale`;
  - Twitter `summary_large_image`.
- **JSON-LD**: un solo `@graph` con:
  - Organization o il tipo scelto (`#organization`);
  - WebSite (`#website`);
  - WebPage o CollectionPage (con un `ItemList` di al più 200 voci);
  - BreadcrumbList (Home > antenati > pagina; per i termini, i genitori).

  Niente grafo sulle pagine noindex.
- **Sitemap**: quella del core, con:
  - i tipi e le tassonomie indicizzabili;
  - senza noindex e contenuti protetti da password;
  - `lastmod` da `post_modified_gmt`;
  - gli autori solo con i loro archivi indicizzati.

  La riga `Sitemap:` in robots.txt la aggiunge già il core.
- **Redirect**: su `template_redirect`, priorità 0, prima di `redirect_canonical` e del redirect dei vecchi slug; solo nel frontend.
  - Il percorso si normalizza e si cerca prima con la query string, poi senza; la query della richiesta passa alla destinazione.
  - Un 410 risponde con `status_header(410)` e il template 404.
  - **Redirect automatici** (se `auto_redirects`): quando cambia l'URL di un contenuto pubblicato o di un termine, anche cambiando il genitore, si crea un 301.
    - niente loop;
    - catene appiattite (A→B→C diventa A→C);
    - un redirect che partiva dal nuovo URL si toglie.

    Servono soprattutto ai siti headless: in WordPress i post hanno già `_wp_old_slug`, ma il frontend Next.js no.
- **llms.txt e llms-full.txt**: li serve WordPress (`parse_request`, `text/plain; charset=utf-8`) dalla cache. Il contenuto è come nel prototipo:
  - intestazione e introduzione;
  - sezioni per tipo e tassonomia secondo `llms`;
  - "Navigazione" dai menu;
  - con più lingue, una sezione per lingua.

  Un file vero in `ABSPATH` ha la precedenza, perché il web server lo serve prima di WordPress: la migrazione elimina quelli del prototipo.
- **Non si stampano**: meta keywords; hreflang, che lo fa WPML; `priority` e `changefreq`.

## 5. Admin

Menu Barrio: Bacheca, Form, SEO, Estensioni, Impostazioni. Un modulo spento non ha la sua voce.

- **SEO → Contenuti** (`barrio_manage_seo`): la tabella del plugin, un tipo alla volta, scelto nel titolo della barra con il `Picker` (pagine, articoli, CPT, poi tassonomie).
  - **Colonne**: titolo, titolo SEO, descrizione, indicizzazione, punteggio; la lingua con più lingue.
  - **Filtri**: indicizzazione, senza descrizione, punteggio, lingua.
  - **Menu ⋯**: Modifica SEO (una modale con lo stesso pannello dell'editor), Modifica, Visualizza.
  - **Azioni di massa**: indicizza, noindex, predefinito.

  L'elenco viene da `GET /barrio/v1/seo/contents`; le modifiche passano dalla REST del core (`meta`), una richiesta per contenuto come nell'elenco dei form. Questa tabella prende il posto del selettore di contenuti dell'advisor del prototipo.
  `// ponytail: tutti i contenuti del tipo in una richiesta, con filtri e ordinamento nel browser (localQuery); oltre ~2000 per tipo si passa al server, come per le Registrazioni`.
- **SEO → Redirect**: la tabella del plugin con ricerca, filtri (codice, automatici), ordinamento e pagine sul server.
  - **Menu ⋯**: modifica ed elimina; tra le azioni di massa, elimina.
  - **"Aggiungi redirect"** nell'intestazione: una modale con sorgente, codice, destinazione (disattivata con il 410) e nota.
- **SEO → Impostazioni**, un solo Salva:
  - **Titoli e meta**: nome e icona del sito, separatore, ordine con l'anteprima, descrizione e immagine di condivisione predefinite;
  - **Indicizzazione**: una riga per tipo e per tassonomia (Indicizza, Segui) con il robots risultante; archivi autore e data; il link a `wp-sitemap.xml`;
  - **Organizzazione**: i campi del §2.2 (logo e immagine dalla libreria dei media, profili e offerte come liste), con l'anteprima del JSON-LD;
  - **llms.txt**: per tipo e per tassonomia Includi / Solo link / Escludi, l'introduzione e i link ai due file;
  - **Redirect**: la creazione automatica al cambio di URL;
  - **Permessi**: `barrio_manage_seo` e `barrio_edit_seo` per ruolo (`Roles.php`).
- **Pannello SEO** (`barrio_edit_seo` più il permesso di modificare il contenuto). Dove compare:
  - nell'editor a blocchi è una sidebar (`PluginSidebar`, con il punteggio sull'icona) e i meta si salvano con il post;
  - nell'editor classico, e nei tipi senza editor a blocchi, è una metabox;
  - nei termini, i campi stanno nella pagina di modifica.

  Il componente è lo stesso ovunque:
  - le anteprime di Google e social;
  - titolo e descrizione con i contatori (60/160);
  - l'immagine, con quella in evidenza come segnaposto;
  - le parole chiave (la prima è la principale);
  - Indicizza e Segui a tre stati: Predefinito (con il valore ereditato), Sì, No;
  - la pagina archivio, solo nelle pagine;
  - il punteggio.
- **Punteggio**: i controlli del prototipo, più la parola chiave principale nello slug:
  - titolo e descrizione presenti e della lunghezza giusta;
  - immagine presente;
  - parole chiave presenti;
  - la principale nel titolo e nella descrizione.

  Si mostra come elenco di controlli superati o no, come in Rank Math. Si calcola solo in JS (`score.js`), non si salva, e lo usano il pannello e la tabella; i test girano con `node --test`.
- **Bacheca**: card SEO con contenuti indicizzati, contenuti senza descrizione e redirect, più i link a Contenuti e Impostazioni. La Bacheca si apre con `read` e ogni card controlla la sua capability.
- **Messa online**: il passaggio `seo` è fatto con la descrizione e l'immagine predefinite e con nome e logo dell'organizzazione.

## 6. Estensioni e hook

| Estensione | Nel modulo SEO |
|---|---|
| `barrio-suite-wpgraphql` | Solo con il modulo acceso, e con i dati di `Seo\Meta`:<br>• `seo` (`HBSeoPost`) sui post type pubblici;<br>• `hbSeoSite`, `hbSchemaRaw(uri)`, `hbSitemap`, `hbRedirects`, `hbLlms` e `hbLlmsFull`.<br>Nomi e forme sono quelli del prototipo (`loc` relativo, `title` senza il nome del sito). Registra con priorità 9, come per i form.<br>L'**indirizzo del frontend** è un'impostazione dell'estensione, in GraphQL → Settings con l'API delle impostazioni di WPGraphQL (prima lo faceva, senza dirlo, l'URL dello schema del prototipo). Nelle risposte GraphQL l'estensione porta su quell'indirizzo gli URL delle pagine (canonical, schema, llms) con il filtro `barrio_seo_url`; le immagini restano su WordPress |
| `barrio-suite-wpml` | I filtri delle lingue del core. Un `wpml-config.xml` per i meta: index, follow e collection si copiano, titolo, descrizione e parole chiave si traducono; sostituisce il codice di copia del prototipo. Sitemap con tutte le lingue, se serve (§8) |
| `barrio-suite-migration` | `wp barrio seo migrate [--dry-run]` (fase S7). Tiene spento il modulo SEO di Barrio Suite finché il SEO del prototipo è acceso e non migrato. Finché il prototipo resta attivo, fa anche da ponte verso la sua revalidation di Next.js |
| `barrio-suite-ai` (nuova) | Anthropic o Gemini, chiave cifrata, modello scelto dalla lista dell'API. Aggiunge "Genera con AI" al pannello SEO, alla modale e alle azioni di massa di Contenuti, e all'introduzione di llms.txt. Il servizio esterno va dichiarato nel readme (regola di WordPress.org) |

Hook nuovi, solo quelli che un'estensione di questo piano usa (come dopo l'audit):
- `barrio_seo_loaded` (su `init`, priorità 5);
- `barrio_seo_changed` (`'settings'`, `'redirects'` o l'ID di un post o di un termine): serve al ponte di
  revalidation e, più avanti, a un'estensione di revalidation per Next.js;
- `barrio_seo_url` (l'URL di una pagina, in ogni punto in cui il modulo lo scrive): serve all'estensione WPGraphQL;
- in JS, `barrio.seo.generators` con `@wordpress/hooks`: un generatore registrato fa comparire "Genera".

Da sviluppare come estensioni se un sito li chiede: importazione da Yoast e Rank Math, IndexNow, schema
WooCommerce, monitor dei 404.

### Da Rank Math

- **Presi**:
  - robots per tipo e per archivio;
  - sidebar nell'editor con anteprima e controlli;
  - redirect 301/302/307/308/410, anche automatici al cambio di URL;
  - schema Organization/LocalBusiness;
  - llms.txt;
  - modifica dei contenuti in tabella e in massa;
  - capability per ruolo.
- **Lasciati**:
  - monitor dei 404: nei siti headless i 404 non arrivano a WordPress, e ogni registro è un dato in più da conservare;
  - redirect con regex;
  - Analytics e Search Console;
  - breadcrumb come blocco, anche se lo schema BreadcrumbList c'è;
  - editor di robots.txt;
  - codici di verifica, che Search Console fa con il DNS;
  - contatore dei link e SEO delle immagini.

## 7. Fasi

Ogni fase si chiude con test verdi (PHPUnit su MariaDB 10.5 e MySQL 8.0, PHPStan, PHPCS, ESLint, `node --test`),
un plugin installabile e lo stesso commit su `con-updater` e `senza-updater`.

| # | Fase | Pronta quando |
|---|---|---|
| S0 | **Suite a più moduli**:<br>• `Modules.php` e card Moduli; il boot e le migrazioni solo per i moduli accesi; `deactivate()` del modulo quando si spegne;<br>• menu e shell React nel core, con la Bacheca su `read`;<br>• `I18n` nel core con i filtri rinominati, e l'estensione WPML aggiornata;<br>• SMTP ed email di prova in Barrio → Impostazioni, con il loro passaggio della messa online;<br>• l'interfaccia dell'updater in file che esistono solo su `con-updater`, così i cherry-pick successivi non vanno in conflitto | Con Forms spento:<br>• niente voce di menu, niente rotte `/forms`, shortcode vuoto;<br>• Mailchimp ferma;<br>• dati intatti.<br>Riacceso, tutto torna. I test di Forms passano dopo lo spostamento, e le traduzioni JS sono rigenerate per la nuova build |
| S1 | **Dati**: meta registrati, opzione `barrio_seo` e `GET`/`PUT /seo/settings`, migrazioni `0001` e `0002`, `RedirectRepository` | Ogni CHECK e la UNIQUE dimostrati dai test; l'enum coincide con il CHECK; un meta con un valore non valido viene rifiutato dalla REST del core |
| S2 | **Output nel sito**: `Meta`, `Head`, `Schema`, `Sitemaps`, redirect (anche automatici), llms.txt | Per home (pagina e articoli), pagina, post, CPT, categoria, tag, autore, data, ricerca, 404 e archivio paginato, il test analizza l'head con `DOMDocument`:<br>• un solo `<title>`, `description`, `robots` e `canonical`;<br>• OG corretti.<br>Inoltre:<br>• la sitemap non ha noindex né password;<br>• 301, 410 e catene appiattite;<br>• llms-full.txt non contiene mai contenuti protetti;<br>• JSON-LD valido al Rich Results Test su un sito di prova |
| S3 | **Pannello SEO**: sidebar, metabox, termini, anteprime, punteggio, `seo-title.json` | Un editor modifica la SEO di pagina, articolo e categoria, anche con l'editor classico; senza `barrio_edit_seo` non vede il pannello e la REST rifiuta i meta; fixture verdi in PHP e JS |
| S4 | **Pagine SEO**: Contenuti, Redirect, Impostazioni, card della Bacheca, passaggio della messa online | Un sito si configura da zero senza WP-CLI e la messa online arriva al verde; controllo a 360px |
| S5 | **Traduzioni**: testi per lingua scelti nella barra delle Impostazioni, `wpml-config.xml`, sitemap e llms.txt per lingua | Sullo shop Docker con WPML (it, en-us, de):<br>• head di ogni lingua con i default tradotti;<br>• la traduzione eredita index e follow;<br>• sitemap con tutte le lingue.<br>Senza WPML tutto funziona in una lingua |
| S6 | **GraphQL** nell'estensione `barrio-suite-wpgraphql` | Lo snapshot dello schema SEO coincide con quello del prototipo. `hello_standard` funziona senza modifiche contro wp-env (`getSeo`, `SeoFragment`, `getSchema`, `getSitemap`, `getRedirects`, `getLLMS`). Senza il modulo acceso non registra niente |
| S7 | **Migrazione dal prototipo** (dettagli sotto) | Un sito headless reale migrato:<br>• conteggi uguali prima e dopo (prova a vuoto, poi migrazione);<br>• `<head>` e GraphQL di cinque pagine uguali prima e dopo, tranne i bug corretti |
| S8 | **Estensione AI** (`barrio-suite-ai`) | Titolo, descrizione e parole chiave generati per un contenuto e in massa, senza modificare il core; errori e chiave mancante mostrati nel pannello |

**Migrazione (S7)**: legge le opzioni e i meta del prototipo e scrive con le API del modulo.
- **Prima della migrazione**: finché il SEO del prototipo è acceso (`suite_barrio_tools_modules['seo']`, assente = acceso), l'estensione mette `seo` tra i moduli spenti di Barrio Suite, una volta sola, e mostra un avviso agli amministratori. Altrimenti, aggiornando un sito del prototipo, i meta uscirebbero due volte e in GraphQL vincerebbero le impostazioni SEO di Barrio Suite, ancora vuote.
- **Opzioni**: `suite_barrio_meta_settings`, `suite_barrio_meta_content`, `suite_barrio_schema_settings` (se è vuota, i dati vengono da `suite_barrio_company_info`) e le modalità llms diventano `barrio_seo`. Il separatore e l'ordine passano tali e quali. Se l'URL dello schema è diverso da `home_url()`, diventa l'indirizzo del frontend dell'estensione WPGraphQL.
- **Meta di post e termini**: `_suite_barrio_seo_*` (e i vecchi `_wpb_seo_*`) diventano `_barrio_seo_*`.
  - Un index o follow esplicito uguale al default del tipo diventa "predefinito", perché il prototipo li scriveva a ogni salvataggio.
  - Un `_sitemap = 0` su un contenuto indicizzabile non ha più un equivalente: la prova a vuoto elenca questi contenuti.
  - Le parole chiave passano da JSON a lista.
- **Redirect**: l'opzione diventa la tabella; il 410 prende `target` NULL; duplicati e loop si segnalano.
- **AI**: le chiavi e le impostazioni dell'advisor passano a `barrio-suite-ai`, se è attiva.
- **Alla fine**:
  - il modulo SEO del prototipo si spegne (`suite_barrio_tools_modules['seo'] = 0`) e quello di Barrio Suite si accende;
  - si eliminano i `llms.txt` e `llms-full.txt` in `ABSPATH`.
- **Ponte di revalidation**: su `barrio_seo_changed` chiama `suite_barrio_invalidate_vercel_cache()` del prototipo, se esiste, con i suoi tag (`wp:seo`, `wp:sitemap`, `wp:schema`, `wp:redirects`, `wp:llms`).

## 8. Da verificare durante lo sviluppo

- **Sitemap del core con WPML**: bisogna verificare se include tutte le lingue o solo quella corrente (i filtri di WPML sulle query). Da questo dipende il codice dell'estensione WPML.
- **Campo `seo` in GraphQL**: il `graphql.php` del prototipo lo registra sempre, anche con il suo modulo SEO spento. Per i tipi vince la prima registrazione (fase 9 dei form); per un campo sullo stesso tipo va provato su wp-env con il prototipo attivo.
- **Revalidation dei siti headless**: il ponte funziona finché il prototipo è attivo. Sui siti headless il prototipo si toglie solo dopo che Barrio Suite avrà un'estensione di revalidation, che userà `barrio_seo_changed` e l'indirizzo del frontend.
- **Ordine degli aggiornamenti sui siti del prototipo**: il core con il modulo SEO e l'estensione di migrazione aggiornata vanno installati insieme. Sui siti dove la migrazione è già stata tolta dopo i form, va reinstallata prima di aggiornare il core.
- **Impostazioni di WPGraphQL**: verificare che `register_graphql_settings_field` regga il campo dell'indirizzo del frontend; altrimenti, una costante in `wp-config.php`.
- **`site_icon` in `/wp/v2/settings`**: verificare che la REST del core lo esponga; altrimenti lo scrive la rotta delle impostazioni SEO.
- **Temi senza `title-tag`**: stampano il loro `<title>`, e il modulo non può cambiarlo. Da controllare sui temi classici dei clienti.
- **Costo dei redirect**: una SELECT sulla UNIQUE a ogni richiesta del frontend. Va misurata; se serve, un flag "nessun redirect" in un'opzione autoload la evita.
- **llms-full.txt**: misurare quanto ci mette a generarsi, a cache vuota, sul sito più grande (500 contenuti fino a 4000 caratteri).
- **Altri plugin SEO**: con Yoast o Rank Math attivi, e il modulo acceso di default, i meta escono due volte finché non si spegne il modulo. La card Moduli e il readme lo dicono; il core non contiene codice per riconoscerli. Se servisse un avviso automatico, sarebbe un'estensione.
- **Conflitti nei cherry-pick** (S0, fatto): i file diversi tra i due branch sono elencati in `docs/release.md` (pagina Estensioni, `Updates.js` e `dev/release.js` solo su `con-updater`, header `Update URI`, "External services" del readme) e le fasi successive non li toccano. Il resto, compresi `PLAN.md`, `docs/hooks.md`, la messa online e le Impostazioni, è uguale: le parti dell'updater arrivano da `Extensions.js` (`launchSteps`, `settingsSections`). `languages/` resta diverso per le stringhe dell'updater: dopo un cherry-pick si rigenera in ogni branch.
