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
| Prima WordPress | Per la sitemap si usa quella del core (`wp-sitemap.xml`, filtrata; `/sitemap.xml` lo redirige già il core). Poi `wp_robots`, `pre_get_document_title` e `get_canonical_url`, il redirect dei vecchi slug del core, le pagine degli allegati spente dal core (`wp_attachment_pages_enabled`, solo sui siti nati dalla 6.4: sugli altri sono noindex di default, con una riga in Indicizzazione). Nome e icona del sito restano le opzioni di WordPress (`blogname`, `site_icon`): niente copie |
| Dati dell'azienda | Stanno nelle impostazioni SEO, card Organizzazione, come la "Local SEO" di Rank Math: servono allo schema. Il futuro modulo Dati aziendali (`info-azienda`, `hbCompanyInfo`) li leggerà da qui invece di tenerne una copia, come succede oggi nel prototipo |
| AI | Nel core, non in un'estensione (scelta del 18 novembre): serve a tutta la suite, non solo al SEO. Gemini su Vertex AI, con il **service account di Google** in Barrio → Impostazioni, lo stesso che userà Analytics (basterà aggiungere l'ID della proprietà GA4): Vertex AI accetta i token dei service account, l'API Gemini di AI Studio solo le chiavi API (il suo discovery document non dichiara scope OAuth). Nessun modello o regione scritti nel codice: si scelgono dalle liste di Google (`publishers/google/models`, `projects.locations`), che la card AI legge da sola appena c'è il service account; la regione vuota è l'endpoint `global`. "Genera con AI" resta spento finché mancano service account e modello. Le chiamate a Google partono solo da un clic di chi lavora nell'admin, e il readme le dichiara (regola di WordPress.org) |
| Sitemap | Niente interruttore "in sitemap" separato: in sitemap c'è ciò che è indicizzabile, come in Yoast e Rank Math. Il prototipo aveva due fonti per la stessa scelta, e una non si poteva impostare. Niente scheda a sé: la sitemap non si modifica, è l'effetto dell'indicizzazione, quindi sta nella card Indicizzazione con i numeri per tipo. L'elenco degli URL è `wp-sitemap.xml`, leggibile nel browser |
| robots.txt | Quello virtuale del core, che ha già la riga `Sitemap:`; con "Scoraggia i motori di ricerca" il core usa `noindex` e non `Disallow: /`, che nasconderebbe il `noindex`. Niente editor: robots.txt blocca la scansione ma non toglie le pagine da Google (lo fa `noindex`), una riga sbagliata toglie il sito dai risultati, e un file vero nella cartella del sito ha comunque la precedenza. La card Indicizzazione avvisa se quel file c'è. Il blocco dei crawler AI, se un sito lo chiede, è un interruttore sul filtro `robots_txt` |
| Impostazioni | Un'opzione, `barrio_seo`, in autoload: è un solo record letto intero a ogni pagina, quindi arriva con le altre opzioni senza query in più. Una tabella avrebbe una riga sola, o diventerebbe una copia di `wp_options`. Come per i form: tabelle per le raccolte di record, opzioni per le impostazioni |
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
| Chiavi API in chiaro, e il codice dei provider AI copiato quattro volte (advisor, llms, alt, cookie) | Un solo client AI nel core (`src/Ai.php`), con il service account cifrato da `Crypto` (`src/Google.php`) |
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
| `_barrio_seo_keywords` | lista di stringhe; la prima è la principale | nessuna. Servono solo al punteggio: niente `keywords` nello schema né `<meta name="keywords">`, e la REST e GraphQL le danno solo a chi modifica il contenuto |
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
  `archives` (`author` indicizzato, `date` noindex di default, perché ripete gli articoli). Ricerca ed embed restano noindex come già fa il core;
- `organization`: tipo (le voci del prototipo che schema.org ha: senza `Architect` e `InteriorDesigner`), nome, logo e immagine (ID, non URL), telefono, email,
  P.IVA, indirizzo, coordinate, zone servite (`[{type, name}]`, con `type` tra `Place`, `AdministrativeArea`, `City`,
  `State` e `Country`: `Text` e `GeoShape` del prototipo non sono luoghi con un nome), competenze (lista), profili
  (`same_as`, una lista di URL al posto dei cinque social fissi), catalogo delle offerte (nome, valuta, voci
  come nel prototipo);
- `llms`: `post_types` e `taxonomies` (`include` \| `links` \| `exclude`); una chiave assente vale `include` per articoli, pagine e tassonomie, `links` per gli altri tipi (`Llms::preset()`);
- `auto_redirects` (default `true`);
- `texts`: `{lingua: {default_description, organization_description, llms_intro}}`. Come nei form, nessun
  testo ha una lingua implicita: senza estensione multilingua c'è solo la lingua del sito.

La lingua dello schema è quella del sito o della pagina, non un'impostazione. Il nome del sito e
l'icona si leggono e si scrivono come opzioni di WordPress con `/wp/v2/settings`.

### 2.3 Redirect: tabella (migrazione `0001`)

```
redirects   id, source VARCHAR(768) utf8mb4_bin UNIQUE, CHECK (source LIKE '/%' e senza / finale)
              percorso senza dominio e senza / finale, query string ammessa
            target VARCHAR(2048) NULL (percorso o URL assoluto), CHECK (target REGEXP '^(/|https?://)')
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
├── I18n.php             # dal modulo Forms, con i filtri barrio_languages…
├── Google.php           # service account di Google: GET/PUT/DELETE /barrio/v1/google, token con un JWT firmato
└── Ai.php               # Gemini su Vertex AI: GET/PUT /barrio/v1/ai, GET /ai/models, generate()
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
│   ├── Editor.php       # sidebar dell'editor a blocchi, metabox classica, campi dei termini e loro salvataggio
│   └── Api/RestSettings.php, RestRedirects.php, RestContents.php, RestGenerate.php (POST /seo/generate e /seo/generate/llms)
└── assets/src/{seo,editor}/   # pagina SEO e pannello (Panel.js); title.js e score.js condivisi
tests/fixtures/seo-title.json  # lo stesso titolo in PHP e in JS
```

Ci sono tre build: `barrio-admin` (shell, Forms e SEO), `seo-sidebar` (il pannello nell'editor a blocchi) e
`seo-form` (lo stesso pannello nella metabox classica e nei termini, senza caricare l'editor a blocchi).

## 4. Output nel sito

- **Titolo**: `{titolo} {sep} {sito}`, o al contrario. Il titolo è il meta, se c'è, altrimenti quello del
  contenuto, del termine o dell'archivio, con la pagina dopo la prima. Funziona con i temi che dichiarano `title-tag`.
- **Meta**:
  - `description`;
  - `robots` con `wp_robots` (il core aggiunge già `max-image-preview:large`);
  - `canonical`, che sui singoli passa da `get_canonical_url` e su archivi, home e tassonomie è il nostro (la pagina 2 ha la sua);
  - Open Graph: `og:type` (`article` sui post, `website` altrove), `title`, `description`, `url` (= canonical), `site_name`, `image` con le dimensioni, `locale`;
  - Twitter: solo `twitter:card` `summary_large_image`, perché X legge titolo, descrizione e immagine da Open Graph.

  Ricerca e 404 hanno solo il titolo e il robots del core: niente descrizione, canonical, Open Graph o JSON-LD.
- **JSON-LD**: un solo `@graph` con:
  - Organization o il tipo scelto (`#organization`);
  - WebSite (`#website`);
  - WebPage, o CollectionPage per gli archivi e per le pagine con `_barrio_seo_collection` (con un `ItemList` di al più 200 voci indicizzabili);
  - BreadcrumbList (Home > antenati > pagina; per i termini, i genitori), con i titoli dei contenuti e non quelli SEO. La home non ne ha.

  Niente grafo sulle pagine noindex.
- **Sitemap**: quella del core, con:
  - i tipi e le tassonomie indicizzabili;
  - senza noindex e contenuti protetti da password;
  - `lastmod` da `post_modified_gmt`;
  - gli autori solo con i loro archivi indicizzati.

  La riga `Sitemap:` in robots.txt la aggiunge già il core.
- **Redirect**: su `template_redirect`, priorità 0, prima di `redirect_canonical` e del redirect dei vecchi slug; solo nel frontend.
  - Il percorso si normalizza e si cerca prima con la query string, poi senza; la query della richiesta passa alla destinazione.
  - Un 410 risponde con `status_header(410)` e il template 404, senza `redirect_canonical` e il redirect dei vecchi slug, che indovinerebbero un altro contenuto.
  - **Redirect automatici** (se `auto_redirects`): quando cambia l'URL di un contenuto pubblicato o di un termine, anche cambiando il genitore, si crea un 301.
    - niente loop;
    - catene appiattite (A→B→C diventa A→C);
    - un redirect che partiva dal nuovo URL si toglie;
    - cambiano anche gli URL dei discendenti (pagine figlie, termini figli);
    - la destinazione è l'URL relativo vero, con la `/` finale dei permalink, così WordPress non aggiunge un secondo redirect.

    `// ponytail:` i post con il termine nel permalink (`%category%`) non seguono il cambio del termine.

    Servono soprattutto ai siti headless: in WordPress i post hanno già `_wp_old_slug`, ma il frontend Next.js no.
- **llms.txt e llms-full.txt**: li serve WordPress (`parse_request`, `text/plain; charset=utf-8`) dalla cache. Il contenuto è come nel prototipo:
  - intestazione e introduzione;
  - sezioni per tipo e tassonomia secondo `llms`;
  - "Navigazione" dai menu;
  - con più lingue, una sezione per lingua.

  In llms-full.txt il testo è il `post_content` senza HTML né shortcode (non `the_content`, che renderebbe anche i form), fino a 4000 caratteri; in llms.txt la descrizione di `Seo\Meta`. La cache (transient, al più un giorno) si svuota con `clean_post_cache`, `clean_term_cache`, le impostazioni SEO e il nome del sito.

  Un file vero in `ABSPATH` ha la precedenza, perché il web server lo serve prima di WordPress: la migrazione elimina quelli del prototipo.
- **Non si stampano**: meta keywords; hreflang, che lo fa WPML; `priority` e `changefreq`.

## 5. Admin

Menu Barrio: Bacheca, Form, SEO, Estensioni, Impostazioni. Un modulo spento non ha la sua voce. La pagina SEO ha le
schede Contenuti, Redirect, Organizzazione e Impostazioni.

- **SEO → Contenuti** (`barrio_manage_seo`): la tabella del plugin, un tipo alla volta, scelto nel titolo della barra con il `Picker` (pagine, articoli, CPT, poi tassonomie).
  - **Colonne**: titolo, titolo SEO, descrizione, indicizzazione, punteggio; la lingua con più lingue.
  - **Filtri**: indicizzazione, senza descrizione, punteggio, lingua.
  - **Menu ⋯**: Modifica SEO (una modale con lo stesso pannello dell'editor), Modifica, Visualizza.
  - **Azioni di massa**: indicizza, noindex, predefinito.

  L'elenco viene da `GET /barrio/v1/seo/contents`; le modifiche passano dalla REST del core (`meta`), una richiesta per contenuto come nell'elenco dei form. Questa tabella prende il posto del selettore di contenuti dell'advisor del prototipo.
  A pagine di 200 righe (`?page=`), che il browser chiede una dopo l'altra e unisce: ogni riga porta il testo intero per il punteggio, e il limite è nei byte, non nel numero (2.000 articoli da 9 KB erano 18,6 MB di JSON e 61 MB di memoria in una risposta sola, `docs/audit-seo.md` §5). `// ponytail: filtri e ordinamento nel browser (localQuery); sul server, come per le Registrazioni, se un tipo arriva a decine di megabyte`.
- **SEO → Redirect**: la tabella del plugin con ricerca, filtri (codice, automatici), ordinamento e pagine sul server.
  - **Menu ⋯**: modifica ed elimina; tra le azioni di massa, elimina.
  - **"Aggiungi redirect"** nell'intestazione: una modale con sorgente, codice, destinazione (disattivata con il 410) e nota.
- **SEO → Organizzazione** (`manage_options`), un solo Salva: i campi del §2.2 in card per argomento (azienda,
  indirizzo, zone servite, competenze, profili, offerte), con l'anteprima del JSON-LD nella colonna destra, aggiornata
  dal server mentre si scrive (`POST /seo/settings/schema`).
- **SEO → Impostazioni** (`manage_options`), un solo Salva:
  - **Titoli e meta**: nome e icona del sito, separatore, ordine con l'anteprima, descrizione e immagine di condivisione predefinite;
  - **Indicizzazione**: una riga per tipo e per tassonomia (Indicizza, Segui) con il robots risultante e quanti indirizzi mette nella sitemap; archivi autore e data. I numeri sono contati dai provider della sitemap del core con i filtri del modulo (`GET /seo/settings/sitemap`), quindi sono quelli che legge Google; una riga che si spegne dice subito che esce dalla sitemap. In fondo il totale, i link a `wp-sitemap.xml` e robots.txt e, con il sito che chiede di non essere indicizzato, che WordPress non pubblica la sitemap. Un avviso se nella cartella del sito c'è un file robots.txt;
  - **Assistenti AI** (llms.txt e llms-full.txt): per tipo e per tassonomia Includi / Solo link / Escludi, l'introduzione e i link ai due file;
  - **Redirect**: la creazione automatica al cambio di URL;
  - **Permessi**: `barrio_manage_seo` e `barrio_edit_seo` per ruolo (`Roles.php`).
- **Pannello SEO** (`barrio_edit_seo` più il permesso di modificare il contenuto). Dove compare:
  - nell'editor a blocchi è una sidebar (`PluginSidebar`, con il punteggio sull'icona) e i meta si salvano con il post;
  - nell'editor classico, e nei tipi senza editor a blocchi, è una metabox;
  - nei termini, i campi stanno nella pagina di modifica.

  Nella metabox e nei termini il pannello scrive i meta in un campo nascosto del form (con un nonce per contenuto),
  e si salvano con `WP_REST_Post_Meta_Fields` e `WP_REST_Term_Meta_Fields`: la validazione e i permessi della REST.
  Titolo, riassunto, contenuto, nome e descrizione aggiornano il pannello mentre si scrive.
  `// ponytail:` il contenuto dell'editor visuale classico (TinyMCE) arriva solo salvando o passando al testo.

  Il componente è lo stesso ovunque:
  - le anteprime di Google e social;
  - titolo e descrizione con i contatori (60/160; il titolo si conta con il nome del sito, come lo mostra Google);
  - l'immagine, con quella in evidenza come segnaposto;
  - le parole chiave (la prima è la principale);
  - Indicizza e Segui a tre stati: Predefinito (con il valore ereditato), Sì, No;
  - la pagina archivio, solo nelle pagine;
  - il punteggio.
- **Punteggio**: scritto da zero, ispirato ai test di Rank Math (non quelli del prototipo). Controlli superati o no,
  ognuno con un peso; il punteggio è la percentuale del peso superato. Più di 80 è buono, più di 50 da migliorare,
  come in Rank Math. Sempre, anche nei termini:
  - parola chiave principale impostata;
  - la principale nel titolo SEO, e nella sua prima metà;
  - titolo completo di al più 60 caratteri;
  - la principale nella descrizione; descrizione di 120–160 caratteri;
  - la principale nell'URL; percorso dell'URL di al più 75 caratteri;
  - un'immagine di condivisione sua (non quella predefinita del sito).

  Solo dove c'è un contenuto:
  - almeno 600 parole;
  - la principale nel contenuto e nel primo 10%, con densità tra 0,5% e 2,5%;
  - la principale in un sottotitolo (H2–H6) e nell'alt di un'immagine;
  - link interni ed esterni;
  - paragrafi di al più 120 parole;
  - immagini o video.

  Le parole chiave si confrontano come parole intere, senza accenti e maiuscole. Lasciati di Rank Math: parola
  chiave già usata (servirebbe una query), sentiment, power word e numero nel titolo (dipendono dalla lingua),
  indice dei contenuti. Si calcola solo in JS (`score.js`), non si salva, e lo usano il pannello e la tabella; i
  test girano con `node --test`.

  In cima al pannello un semicerchio, come in Rank Math, mostra il punteggio, il livello e quanti controlli
  mancano; il link porta ai controlli. È un `<meter>` disegnato con `conic-gradient` e una maschera, e l'arco si
  anima con una custom property registrata (`@property`), senza animazione con `prefers-reduced-motion`.
  Sull'icona della sidebar c'è il numero, nel colore del livello.
- **Bacheca**: card SEO con contenuti indicizzati, contenuti senza descrizione e redirect, più i link a Contenuti e Impostazioni. La Bacheca si apre con `read` e ogni card controlla la sua capability.
- **Messa online**: il passaggio `seo` è fatto con la descrizione e l'immagine predefinite e con nome e logo dell'organizzazione.

## 6. Estensioni e hook

| Estensione | Nel modulo SEO |
|---|---|
| `barrio-suite-wpgraphql` | Solo con il modulo acceso, e con i dati di `Seo\Meta`:<br>• `seo` (`HBSeoPost`) sui post type pubblici;<br>• `hbSeoSite`, `hbSchemaRaw(uri)`, `hbSitemap`, `hbRedirects`, `hbLlms` e `hbLlmsFull`.<br>Nomi e forme sono quelli del prototipo (`loc` relativo, `title` senza il nome del sito). Registra con priorità 9, come per i form.<br>L'**indirizzo del frontend** è un'impostazione dell'estensione, in GraphQL → Settings con l'API delle impostazioni di WPGraphQL (prima lo faceva, senza dirlo, l'URL dello schema del prototipo). Nelle risposte GraphQL l'estensione porta su quell'indirizzo gli URL delle pagine (canonical, schema, llms) con il filtro `barrio_seo_url`; le immagini restano su WordPress |
| `barrio-suite-wpml` | I filtri delle lingue del core e `barrio_switch_language` (`wpml_switch_language`). Un `wpml-config.xml` per i meta: index, follow e collection si copiano, titolo, descrizione e parole chiave si traducono, l'immagine si copia una volta; sostituisce il codice di copia del prototipo. Le sitemap delle altre lingue in robots.txt (§8) |
| `barrio-suite-migration` | `wp barrio seo migrate [--dry-run]` (fase S7, in `seo.php`). Tiene spento il modulo SEO di Barrio Suite finché il SEO del prototipo è acceso e non migrato. Finché il prototipo resta attivo, fa anche da ponte verso la sua revalidation di Next.js |

Hook nuovi, solo quelli che un'estensione di questo piano usa (come dopo l'audit):
- `barrio_seo_loaded` (su `init`, priorità 5);
- `barrio_seo_changed` (`'settings'`, `'redirects'`, o `'post'` e `'term'` con l'ID quando ne cambiano i meta SEO,
  una volta per richiesta): serve al ponte di revalidation e, più avanti, a un'estensione di revalidation per Next.js;
- `barrio_seo_url` (l'URL di una pagina, in ogni punto in cui il modulo lo scrive): serve all'estensione WPGraphQL.

L'AI è nel core (Decisioni), quindi "Genera con AI" non ha hook: il pannello SEO (anche nella modale di Contenuti) lo
mostra sopra il titolo SEO e riempie titolo, descrizione e parole chiave; le azioni di massa di Contenuti scrivono
solo i campi vuoti, un contenuto alla volta, e si fermano al primo errore; sotto l'introduzione di llms.txt scrive
l'introduzione nella lingua della barra. Senza service account e modello i bottoni sono spenti, con il motivo. Il
testo dei post protetti da password non va mai a Google.

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
  - editor di robots.txt (vedi Decisioni);
  - codici di verifica, che Search Console fa con il DNS;
  - contatore dei link e SEO delle immagini.

### Da Jetpack (fase S9)

Jetpack Boost e gli strumenti SEO di Jetpack. Quelli SEO ci sono già: titoli, descrizioni, Open Graph e sitemap.
I codici di verifica sono già tra i lasciati. Delle prestazioni conviene prendere solo ciò che il core sa già fare
e che non chiama servizi esterni. Va in un modulo a sé, `modules/performance/` (card Moduli), non nel SEO: serve
anche ai siti che usano Yoast o Rank Math e tengono spento il modulo SEO. Il modulo è acceso come gli altri, ma al
suo interno sono accese solo le funzioni che non cambiano file né comportamento.

- **Presi**:
  - **immagini WebP o AVIF** (fatto): le dimensioni create dai JPEG (e dagli HEIC, che il core porta in JPEG)
    escono nel formato scelto, tra quelli che l'editor di immagini del server sa scrivere (`wp_image_editor_supports()`).
    Si usa il filtro del core `image_editor_output_format`, e l'originale resta com'è (`original_image`). I PNG no:
    sono di solito loghi e grafiche, dove la compressione lossy si vede. Vale per i nuovi caricamenti; per quelli già
    fatti c'è `wp media regenerate`. Spenta di default, perché cambia i file. È l'alternativa all'Image CDN di
    Jetpack, senza servizi di terzi;
  - **caricamento speculativo** (fatto): il core (dalla 6.8) fa già il prefetch `conservative` dei link per i visitatori.
    Si sceglie tra prefetch e prerender e tra `conservative`, `moderate` ed `eager`
    (`wp_speculation_rules_configuration`). Gli URL che cambiano stato li esclude già il core, quindi niente codice:
    chi ha fatto l'accesso (e quindi il logout) non ha regole, `wp-admin` e `wp-*.php` sono sempre esclusi e, con i
    permalink (senza, il core spegne tutto), ogni URL con una query (`add-to-cart`). Il prerender esegue il JavaScript
    della pagina: provato con i form (§8). Di default resta la scelta del core;
  - **head più leggero** (fatto): un interruttore per ciascuno tra script e stili delle emoji, meta generator, link RSD e
    shortlink (accesi), e Dashicons nel sito per chi non vede la barra di amministrazione (spento). Ognuno è un
    `remove_action` o un `wp_dequeue_style`.
- **Già nel core, quindi niente codice**: lazy loading di immagini e iframe, `fetchpriority="high"` sull'immagine
  LCP, `width` e `height` delle immagini del contenuto, `decoding="async"`, e nei temi a blocchi il CSS caricato
  solo per i blocchi usati.
- **Lasciati**:
  - Critical CSS: serve il servizio cloud di Jetpack o un browser headless sul server;
  - rinvio di tutti gli script: rompe form e slider, tanto che Jetpack stesso chiede di escluderli. Gli script del
    plugin sono già module o `defer`;
  - Image CDN: un servizio di terzi, quindi semmai un'estensione;
  - cache delle pagine: è dell'hosting o di un plugin di cache, con il drop-in `advanced-cache.php`;
  - concatenazione di JS e CSS: con HTTP/2 guadagna poco e rende la cache meno efficace;
  - Image Guide: misura le immagini nel browser di chi visita il sito;
  - sitemap di Google News: serve solo ai siti di notizie, quindi un'estensione se un sito la chiede.

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
| S8 | **AI nel core**: service account di Google e Gemini su Vertex AI, in Barrio → Impostazioni; "Genera con AI" nel SEO; la descrizione del sito dall'advisor del prototipo | Titolo, descrizione e parole chiave generati per un contenuto e in massa; bottoni spenti finché mancano service account e modello; errori mostrati dove si è cliccato (`AiTest`, con Google simulato e la firma del JWT controllata). Resta la prova con un service account vero |
| S9 | **Prestazioni** (modulo `performance`, §6 "Da Jetpack"): immagini WebP o AVIF, caricamento speculativo, head più leggero; la pagina Barrio → Prestazioni, come gli altri moduli, e la card nella Bacheca | Su un sito di prova:<br>• una JPEG caricata ha le dimensioni in WebP (o AVIF) e l'originale intatto;<br>• le regole di speculazione hanno la modalità scelta ed escludono admin e logout;<br>• l'head non ha emoji, generator, RSD e shortlink;<br>• PageSpeed Insights su mobile non peggiora in nessuna metrica;<br>• i form si inviano anche con il prerender acceso.<br>Con il modulo spento tutto torna come nel core |

**Migrazione (S7, fatta)**: legge le opzioni e i meta del prototipo e scrive nell'opzione, nei meta e nella tabella del
modulo. La prova a vuoto calcola tutto e non scrive niente; il comando si può rilanciare e lascia com'è ciò che Barrio
Suite ha già (l'opzione `barrio_seo`, un meta, la sorgente di un redirect).
- **Prima della migrazione**: finché il SEO del prototipo è acceso (`suite_barrio_tools_module_enabled( 'seo' )`, cioè il prototipo è attivo e `suite_barrio_tools_modules['seo']` non è 0), l'estensione mette `seo` tra i moduli spenti di Barrio Suite su `plugins_loaded` (priorità 9, prima del boot), una volta sola (`barrio_migration_seo` = `held`), e mostra un avviso agli amministratori. Altrimenti, aggiornando un sito del prototipo, i meta uscirebbero due volte e in GraphQL vincerebbero le impostazioni SEO di Barrio Suite, ancora vuote. Chi riaccende il modulo prima della migrazione lo sceglie. Le tabelle del modulo spento le crea il comando (`Modules::migrator()`).
- **Opzioni**: `suite_barrio_meta_settings`, `suite_barrio_meta_content`, `suite_barrio_schema_settings` (se è vuota, i dati vengono da `suite_barrio_company_info`) e le modalità llms (`include_links` → `links`) diventano `barrio_seo`, validata sezione per sezione con il suo schema. Il separatore e l'ordine passano tali e quali. Logo, immagine e immagini delle offerte da URL diventano ID con `attachment_url_to_postid()` (se l'URL non è nella libreria dei media, un avviso). Le zone servite `Tipo: Nome` riconoscono anche City, State e Country; un prezzo che non è un numero resta vuoto, con un avviso. Gli archivi degli autori diventano noindex (vedi §8). L'introduzione di llms.txt si legge dal `llms.txt` del prototipo. La lingua dello schema era un'impostazione: se è diversa da quella del sito, un avviso. Se l'URL dello schema è diverso da `home_url()`, diventa l'indirizzo del frontend dell'estensione WPGraphQL (`graphql_general_settings['barrio_frontend_url']`), se non c'è già.
- **Meta di post e termini**: `_suite_barrio_seo_*` (e i vecchi `_wpb_seo_*`, quando manca il nuovo) e `_suite_barrio_schema_collection` diventano `_barrio_seo_*`.
  - Un index o follow esplicito uguale al default del tipo diventa "predefinito", perché il prototipo li scriveva a ogni salvataggio.
  - Un `_sitemap = 0` su un contenuto indicizzabile non ha più un equivalente: il comando elenca questi contenuti.
  - Le parole chiave passano da JSON a lista; un'immagine che non è più un allegato e una collezione di un tipo che non esiste non passano.
  - Per ogni meta il comando conta trovati, migrati, predefiniti e saltati: `found = migrated + default + skipped`.
- **Redirect**: l'opzione diventa la tabella con la sorgente normalizzata (`Redirects::path()`); il 410 prende `target` NULL; la nota "Aggiunto automaticamente al cambio slug" diventa `is_auto`. Non passano, con un avviso: sorgenti, codici e destinazioni non validi, i doppioni (`/a/` e `/a`: resta il primo) e i redirect in un ciclo (anche `/a` → `/a/`), che nel prototipo finivano in "troppi reindirizzamenti".
- **AI** (S8): contesto, settore e parole chiave dell'advisor diventano la descrizione del sito dell'AI, se non ce n'è già una. Le chiavi API del prototipo non passano, con un avviso: Barrio Suite usa un service account.
- **Alla fine**:
  - il modulo SEO del prototipo si spegne (`suite_barrio_tools_modules['seo'] = 0`) e quello di Barrio Suite si accende (`Modules::set()`; `barrio_migration_seo` = `migrated`);
  - si eliminano i `llms.txt` e `llms-full.txt` in `ABSPATH`, se il prototipo li ha generati;
  - il ponte fa rileggere tutto al frontend.
- **Ponte di revalidation**: su `barrio_seo_changed` chiama `suite_barrio_invalidate_vercel_cache()` del prototipo, se esiste, con i suoi tag: l'ID e `wp:sitemap` per un contenuto, `wp:redirects` per i redirect, `wp:seo,wp:schema,wp:sitemap,wp:llms` per impostazioni e termini.

## 8. Da verificare durante lo sviluppo

- **Sitemap del core con WPML** (S5, fatto): WPML filtra le query sulla lingua della richiesta, quindi ogni lingua ha la sua
  sitemap, con i suoi URL (`/en/wp-sitemap.xml`), e quella del core ha solo la principale. WPML non accetta `all` nel
  frontend, e una sitemap indice non può contenerne altre: l'estensione WPML aggiunge a robots.txt una riga `Sitemap:`
  per ogni altra lingua (`wpml_permalink`). I conteggi di SEO → Impostazioni sommano le sitemap delle lingue.
- **Lingua dei contenuti** (S5, fatto): `I18n::in()` cambia anche la lingua dei contenuti, con l'azione
  `barrio_switch_language`. llms.txt (un blocco per lingua), la tabella dei contenuti e i conteggi della sitemap girano
  una lingua alla volta; le loro `get_posts()` hanno `suppress_filters => false`, senza il quale WPML non filtra. La
  card della Bacheca conta tutte le lingue con `suppress_filters => true`. Con WPML la riga `>` di llms.txt resta
  l'indirizzo del sito: WPML converte `home_url` con la lingua della richiesta, non con quella attiva.
- **Campo `seo` in GraphQL** (S6, fatto): il `graphql.php` del prototipo lo registra sempre, anche con il suo modulo SEO spento. Anche per un campo sullo stesso tipo WPGraphQL tiene la prima registrazione (`DUPLICATE_FIELD` nel suo debug), quindi basta la priorità 9. Provato su wp-env con il prototipo attivo: le query di `hello_standard` (`getSeo`, `SeoFragment`, `getSchema`, `getSitemap`, `getRedirects`, `getLLMS`) rispondono con i dati di Barrio Suite; con il modulo SEO spento risponde il prototipo.
- **Revalidation dei siti headless**: il ponte funziona finché il prototipo è attivo. Sui siti headless il prototipo si toglie solo dopo che Barrio Suite avrà un'estensione di revalidation, che userà `barrio_seo_changed` e l'indirizzo del frontend.
- **Ordine degli aggiornamenti sui siti del prototipo**: il core con il modulo SEO e l'estensione di migrazione aggiornata vanno installati insieme. Sui siti dove la migrazione è già stata tolta dopo i form, va reinstallata prima di aggiornare il core.
- **Impostazioni di WPGraphQL** (S6, fatto): `register_graphql_settings_field` regge il campo dell'indirizzo del
  frontend, nelle impostazioni generali (GraphQL → Settings): salvato dal suo form, con la `/` finale tolta.
- **Lingua dei contenuti in GraphQL**: `seo` e `hbSchemaRaw` usano i testi predefiniti (descrizione,
  descrizione dell'organizzazione) e `inLanguage` della lingua della richiesta, non del contenuto, perché il core
  non sa la lingua di un contenuto. Conta solo per i contenuti senza descrizione, riassunto e testo; se un sito
  tradotto ne ha bisogno, un filtro della lingua di un contenuto nell'estensione WPML.
- **Autori in `hbSitemap`** (S7, fatto): come `wp-sitemap.xml`, ha gli archivi degli autori se sono indicizzati (lo sono
  di default), che il prototipo non aveva e che i frontend come `hello_standard` non mostrano. La migrazione li
  mette noindex; su un sito headless nuovo si spengono in SEO → Impostazioni → Indicizzazione.
- **Migrazione provata** (S7): sul sito dei test di wp-env (8889) con il prototipo attivo, WPGraphQL e dati SEO del
  prototipo (impostazioni, schema con offerte e zone servite, cinque contenuti, una categoria, tre redirect). Attivata
  l'estensione, il modulo SEO di Barrio Suite si spegne. Prova a vuoto, migrazione e database danno gli stessi
  conteggi. `<head>` e GraphQL (`nodeByUri { seo }`, `hbSchemaRaw`, `hbSeoSite`, `hbSitemap`, `hbRedirects`) di home,
  due pagine, due articoli e una categoria cambiano solo per le correzioni previste: titolo con il separatore, un solo
  canonical, home con canonical e `og:url` suoi, `og:type` website sulle pagine, canonical GraphQL sull'indirizzo del
  frontend, `#organization`, breadcrumb come nodo a sé con i titoli dei contenuti, CollectionPage sulla categoria,
  sitemap senza i tag noindex e senza `priority`, note dei redirect solo per chi gestisce il SEO. Resta da fare su un
  sito headless reale, con il suo frontend.
- **`site_icon` in `/wp/v2/settings`** (S4, fatto): la REST del core lo espone, come `blogname` (`title`). SEO →
  Impostazioni salva nome e icona del sito con `/wp/v2/settings`, con lo stesso Salva delle sezioni di `barrio_seo`.
- **Lingua in SEO → Contenuti** (S5, fatto): la tabella chiede i contenuti una lingua alla volta, la principale per
  prima, e ognuno prende la prima in cui compare: anche un tipo che WPML non traduce, o l'originale mostrato dove manca
  la traduzione ("display as translated" di WooCommerce Multilingual), resta della lingua principale e non si ripete.
  Ogni lingua ha il suo contesto per il pannello e il punteggio (descrizione predefinita, nome del sito).
- **Temi senza `title-tag`**: stampano il loro `<title>`, e il modulo non può cambiarlo. Da controllare sui temi classici dei clienti.
- **Costo dei redirect** (misurato, `docs/audit-seo.md` §4-§5): una SELECT sulla UNIQUE da 0,2 ms a ogni richiesta del frontend, due con una query string. Il flag "nessun redirect" in un'opzione autoload non serve.
- **llms-full.txt** (misurato, `docs/audit-seo.md` §4-§5): 0,12–0,39 secondi a cache vuota, 2 MB. Il transient deve stare in `max_allowed_packet`: con più tipi lunghi e più lingue può non starci, e allora si rigenera a ogni richiesta.
- **Altri plugin SEO**: con Yoast o Rank Math attivi, e il modulo acceso di default, i meta escono due volte finché non si spegne il modulo. La card Moduli e il readme lo dicono; il core non contiene codice per riconoscerli. Se servisse un avviso automatico, sarebbe un'estensione.
- **Meta dall'editor a blocchi** (S3, fatto): quando cambia un meta qualsiasi (anche le note a piè di pagina)
  l'editor rimanda tutti i meta del contenuto, con null o vuoti quelli assenti. Per il core null vuol dire
  cancellare: chiede `barrio_edit_seo` (un autore non salverebbe più) e rifiuta un meta assente il cui valore vuoto
  non rispetta lo schema (500 su `index`, `follow`, `image`, `collection`). Il filtro
  `rest_request_before_callbacks` toglie i meta SEO assenti che restano assenti (`Module::changes()`, che usa anche
  la metabox). Senza `custom-fields` la REST del core non scrive i meta: il modulo lo aggiunge ai tipi pubblici.
- **AI con un service account vero** (S8): provato solo con Google simulato. Da provare: API Vertex AI attiva, ruolo
  Vertex AI User, l'endpoint `global` per `generateContent`, la lista `v1beta1/publishers/google/models` (scope
  `cloud-platform` dal discovery document) e i tempi dei modelli che ragionano (timeout di 60 secondi, a volte oltre il
  `max_execution_time` di PHP). La lista non dice quali modelli scrivono testo: si tengono solo i Gemini, senza
  gli embedding. Non tutti i modelli sono in tutte
  le regioni: la lista dei modelli è unica, quindi l'errore arriva generando.
- **Prestazioni provate** (S9): sul sito dei test di wp-env (8889, WordPress 7.1, Twenty Twenty-Five). Una JPEG
  caricata ha il full e le dimensioni in WebP e l'originale identico (stesso md5); il full passa da 203 a 70 KB, le
  dimensioni piccole restano alla pari (±4%, con le qualità di default del core). Le regole di speculazione hanno
  modalità e anticipo scelti e le esclusioni del core. In Chrome 158 il form di `/contatti/` si invia da una pagina
  prerenderizzata davvero (`activationStart` > 0, `barrio:success`): il suo JavaScript non chiede niente al server
  prima dell'invio. Lighthouse mobile (il motore di PageSpeed Insights, mediana di tre corse) con i default contro il
  modulo spento: nessuna metrica peggiora (FCP 763 contro 774 ms, LCP 1513 contro 1520 ms, TBT 0, CLS 0), due
  richieste e 7 KB in meno. Resta PageSpeed Insights su un sito pubblico. Per togliere gli stili delle emoji si stacca
  `print_emoji_styles` da `wp_print_styles`, come chiede il core: staccando `wp_enqueue_emoji_styles` resterebbe la
  funzione deprecata, che li stampa lo stesso.
- **Conflitti nei cherry-pick** (S0, fatto): i file diversi tra i due branch sono elencati in `docs/release.md` (pagina Estensioni, `Updates.js` e `dev/release.js` solo su `con-updater`, header `Update URI`, "External services" del readme) e le fasi successive non li toccano. Il resto, compresi `PLAN.md`, `docs/hooks.md`, la messa online e le Impostazioni, è uguale: le parti dell'updater arrivano da `Extensions.js` (`launchSteps`, `settingsSections`). `languages/` resta diverso per le stringhe dell'updater: dopo un cherry-pick si rigenera in ogni branch.
