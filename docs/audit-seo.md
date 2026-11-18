# Audit di Barrio Suite: SEO e Prestazioni

Data: 18 novembre 2026. Codice esaminato: branch `con-updater`, commit `3b10aeb`.

Perimetro: i moduli SEO e Prestazioni, l'AI e il service account di Google nel core (`src/Ai.php`,
`src/Google.php`) e le parti SEO delle estensioni WPGraphQL, WPML e di migrazione, circa 8.600 righe. Il
resto del core e il modulo Forms sono in [audit.md](audit.md) e qui non si ripetono.

Lo scopo è lo stesso: cosa togliere o semplificare, quali presupposti non reggono più e cosa migliorare in
sicurezza, prestazioni e interfaccia, prima di usare il plugin sui siti, pubblicarlo su WordPress.org e
costruirci sopra gli altri moduli. Per ogni punto c'è la prova e la modifica più piccola che conserva il
comportamento attuale.

## In breve

Il codice è sano. PHPCS, PHPStan livello 8, ESLint e stylelint sono puliti; passano 395 test PHP e 38 JS.
Non sono emerse vulnerabilità e non c'è codice morto. Sulle pagine del sito i due moduli costano 5 query e
circa 1 ms.

Prima dei due obiettivi restano però tre cose:

- **Uso reale**: 8 difetti, 7 dei quali riprodotti, ognuno da 1–10 righe. I due che pesano di più: una
  regola 410 viene scavalcata da un redirect del core, e sui siti nati prima di WordPress 6.4 le pagine
  degli allegati restano indicizzabili senza un modo per spegnerle.
- **WordPress.org**: Plugin Check dà un solo errore nuovo in questo perimetro. Vanno però decisi tre
  comportamenti di default, perché dopo la prima installazione cambiarli cambia i siti: llms-full.txt
  pubblica il testo di ogni tipo di contenuto pubblico, llms.txt esce anche sui siti nascosti ai motori di
  ricerca, le parole chiave di ogni pagina sono pubbliche.
- **Lavoro non al sicuro**: le fasi S2–S9 (11 commit, 100 file, +16.521 righe) esistono solo su questo
  computer.

Da togliere c'è poco. La semplificazione che vale è una: l'elenco dei tipi di contenuto pubblici è scritto
in 12 punti, con due definizioni diverse.

## Metodo

- Lettura completa del codice del perimetro, PHP, JavaScript e CSS.
- Strumenti: PHPCS, PHPStan, ESLint, stylelint, PHPUnit (395 test, anche con WPGraphQL caricato) e i test
  JS del progetto; Plugin Check 2.2.0 sul plugin principale.
- Prove dal vivo sul sito dei test di wp-env (porta 8889: WordPress 7.1.4, PHP 8.2.35, MariaDB 12.3.3,
  Twenty Twenty-Five):
  - curl per redirect, head delle pagine, llms.txt e robots.txt;
  - conteggio e tempi delle query con `SAVEQUERIES`, con i moduli accesi e spenti (mediana di 15 richieste
    per pagina);
  - misure a scala con 500, 2.000 e 5.000 articoli da 9 KB, creati in una transazione poi annullata;
  - Chrome 158 senza interfaccia per l'editor a blocchi e per le pagine admin a 1400 e 360 px.
- Il sito è tornato com'era: due regole 410, due bozze e un mu-plugin di misura creati per le prove sono
  stati eliminati, Plugin Check è stato disattivato.
- "Riprodotto" vuol dire eseguito davvero; "verificato in lettura" vuol dire letto riga per riga, senza
  eseguirlo. Le modifiche proposte non sono state applicate: l'unica provata è quella del punto 4.

## 1. Da correggere prima dell'uso reale

Riprodotti:

| # | Problema | Dove | Prova | Modifica minima |
|---|---|---|---|---|
| 1 | Una regola 410 non vale se il core trova un contenuto con lo slug che inizia allo stesso modo: risponde 301 verso quel contenuto | [Redirects.php:28-34](../modules/seo/src/Redirects.php#L28-L34) | Con la regola 410 su `/hello`, `curl -I /hello` dà `301`, `X-Redirect-By: WordPress`, `Location: …/2026/11/11/hello-world/`; `/chiuso`, senza contenuti simili, dà `410`. Dopo `set_404()` parte `redirect_canonical`, che indovina il permalink. Il test del 410 ([SeoOutputTest.php:303-331](../tests/Integration/SeoOutputTest.php#L303-L331)) non lo esegue | Nel ramo del 410, `remove_action( 'template_redirect', 'redirect_canonical' )` e lo stesso per `wp_old_slug_redirect` (2 righe) |
| 2 | Sui siti creati prima di WordPress 6.4 le pagine degli allegati sono indicizzabili, e le impostazioni non hanno una riga per spegnerle | [Meta.php:127-133](../modules/seo/src/Meta.php#L127-L133), [Admin.php:31-35](../modules/seo/src/Admin.php#L31-L35) | Con `wp_attachment_pages_enabled` a 1 (il valore che il core lascia ai siti aggiornati, qui simulato con un filtro), la pagina di un allegato ha `index = true` e robots `max-image-preview:large`; i tipi nelle impostazioni sono `page, post, category, post_tag, post_format` | In `Meta::robots()` gli allegati valgono noindex se non c'è una scelta (2 righe). In alternativa, sito per sito, l'opzione del core a 0 |
| 3 | La REST accetta redirect che si rincorrono | [RestRedirects.php:173-179](../modules/seo/src/Api/RestRedirects.php#L173-L179) | `/audit-a → /audit-b` e `/audit-b → /audit-a` rispondono entrambi 201; `Redirects::match()` alterna i due all'infinito: nel browser è "troppi reindirizzamenti". Si rifiuta solo la destinazione uguale alla sorgente | Seguire la catena dalla destinazione e rifiutare se torna alla sorgente. Il controllo esiste già nell'estensione di migrazione ([seo.php:351-368](../extensions/barrio-suite-migration/seo.php#L351-L368)): spostato in `Redirects`, serve a tutti e due (~10 righe, −17 nell'estensione) |
| 4 | La cache di llms.txt si svuota con 2 query a ogni `clean_post_cache` e `clean_term_cache`, anche quando è già vuota | [Llms.php:54-57](../modules/seo/src/Llms.php#L54-L57), [Module.php:71-73](../modules/seo/src/Module.php#L71-L73) | Salvare un articolo: 4 query su 25 (dall'editor a blocchi 4 su 40). Autosalvataggio: 2 su 10. Un commento: 2 su 7. Importare 100 articoli: 600 su 3.499 | Eliminare il transient solo se c'è: `if ( false !== get_transient( $key ) )` (3 righe). Provato: 300 svuotamenti passano da 600 query a 2 |
| 5 | Due query in più a ogni richiesta, del sito e dell'admin, per due opzioni che non esistono | [Modules.php:29](../src/Modules.php#L29), [Migrator.php:28-30](../src/Database/Migrator.php#L28-L30) | `SELECT … 'barrio_disabled_modules'` e `SELECT … 'barrio_performance_db_version'` in ogni pagina misurata. La prima opzione nasce solo spegnendo un modulo, la seconda mai: Prestazioni non ha migrazioni. L'audit del 16 novembre misurava 0 query all'avvio | `add_option( 'barrio_disabled_modules', [] )` in `Plugin::activate()` (1 riga); `is_current()` vero senza leggere l'opzione se non ci sono file di migrazione (1 riga) |
| 6 | Un URL con un `%` non valido fa cadere il pannello SEO e tutta la tabella Contenuti | [score.js:81-83](../modules/seo/assets/src/score.js#L81-L83) | `score()` con `https://example.it/100%-sconto/` o con un segnaposto rimasto (`/%postname%/`) lancia `URIError: URI malformed`. Nella tabella il punteggio di tutte le righe si calcola in un solo `useMemo` | `try`/`catch` attorno a `decodeURIComponent`, come fa già `breadcrumb()` in [Panel.js:206-215](../modules/seo/assets/src/editor/Panel.js#L206-L215) (3 righe) |
| 7 | Cambiando l'URL di una pagina, i redirect scritti a mano verso il vecchio URL non seguono, se la destinazione è senza `/` finale o è un URL assoluto | [RedirectRepository.php:106-109](../modules/seo/src/Repository/RedirectRepository.php#L106-L109) | Dopo il cambio di slug: `/promo-3 → /audit-pagina/` diventa `/audit-pagina-nuova/`, ma `/promo-1 → /audit-pagina` e `/promo-2 → http://…/audit-pagina/` restano: due salti invece di uno | In `retarget()` confrontare anche le due varianti: `target IN (%s, %s, %s)` (2 righe) |

Verificato in lettura:

| # | Problema | Dove | Prova | Modifica minima |
|---|---|---|---|---|
| 8 | Due amministratori che lavorano su Organizzazione e Impostazioni si sovrascrivono a vicenda | [SettingsPage.js:171-181](../modules/seo/assets/src/seo/SettingsPage.js#L171-L181) | Le due schede usano la stessa pagina e il Salva manda tutte le sezioni lette all'apertura (`...seo`), anche quelle non toccate. Il server sa già salvare una sezione alla volta ([Settings.php:91-93](../modules/seo/src/Settings.php#L91-L93)) | Mandare solo le sezioni diverse da quelle salvate (3 righe) |

## 2. WordPress.org

Plugin Check 2.2.0 è stato lanciato sulla cartella del repository. Nel perimetro di questo audit dà un solo
errore; gli altri sono quelli già in [audit.md](audit.md) §2 (header `Update URI`, updater) o riguardano
file che `package.json` non mette nel pacchetto.

| Punto | Prova | Modifica minima |
|---|---|---|
| `suppress_filters` a `true` | Errore `WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters` a [RestContents.php:144](../modules/seo/src/Api/RestContents.php#L144): serve a contare i contenuti di tutte le lingue | `phpcs:ignore` con il motivo (1 riga), oppure contare una lingua alla volta come fa `list()` |
| llms-full.txt pubblica il testo di ogni tipo pubblico | Riprodotto: un tipo appena registrato con `public => true` finisce in llms-full.txt con il suo `post_content`. Una chiave assente vale "Includi" ([Llms.php:85](../modules/seo/src/Llms.php#L85)) e il testo non passa da `the_content`, quindi non vede i plugin che proteggono i contenuti (corsi, aree riservate, template dei page builder) | "Solo link" come valore di una chiave assente per i tipi diversi da `post` e `page`: 1 riga in PHP, 2 in [Settings.js:160-166](../modules/seo/assets/src/seo/Settings.js#L160-L166) e [430](../modules/seo/assets/src/seo/Settings.js#L430) |
| llms.txt esce anche sui siti nascosti ai motori di ricerca | Verificato in lettura: [Llms.php:23-30](../modules/seo/src/Llms.php#L23-L30) non guarda `blog_public`. Un sito di staging pubblica tutti i suoi testi agli assistenti AI | Non servire i due file con `blog_public` a 0 (1 riga) |
| Le parole chiave di ogni pagina sono pubbliche | Riprodotto: un anonimo legge `_barrio_seo_keywords` da `GET /wp/v2/posts/{id}`; escono anche nel JSON-LD ([Schema.php:51](../modules/seo/src/Schema.php#L51)) e in GraphQL. Yoast e Rank Math non le stampano nelle pagine; Google non elenca `keywords` tra le proprietà di `WebPage` che usa | Da decidere. Per tenerle private: togliere la riga 51 e rendere il meta leggibile solo a chi modifica (−1 riga, +3) |
| Tag del readme | `Tags: forms, contact form, form builder, gdpr, multilingual`: chi cerca un plugin SEO non lo trova | Scegliere i 5 tag pensando ai tre moduli |

I servizi esterni dell'AI sono già dichiarati nel readme, con ciò che si invia a Google.

## 3. Cosa togliere o semplificare

1. **Un solo elenco dei tipi pubblici.** Nel modulo "i tipi pubblici tranne gli allegati" è scritto 7 volte
   e "le tassonomie pubbliche" 5, in `Module`, `RestContents`, `Editor`, `Llms` e `Admin`; un'altra volta
   nell'estensione WPGraphQL. Tutti usano `public => true`, mentre `Schema` e `Redirects` decidono con
   `is_post_type_viewable()` ([Schema.php:202](../modules/seo/src/Schema.php#L202),
   [Redirects.php:122](../modules/seo/src/Redirects.php#L122)): un tipo può avere il pannello ma non i
   redirect automatici. Due funzioni in `Module` (~8 righe) al posto di 12 elenchi; sono anche il punto in
   cui togliere `post_format` (§7) e decidere gli allegati (§1.2).
2. **Un solo controllo dei cicli di redirect**, in `Redirects`, usato dalla REST e dalla migrazione (§1.3).
3. **Testo semplice in PHP e in JS.** `Meta::text()` ([Meta.php:166-168](../modules/seo/src/Meta.php#L166-L168))
   e `text()` ([title.js:39-54](../modules/seo/assets/src/title.js#L39-L54)) fanno la stessa cosa e danno
   risultati diversi. Riprodotto con `Prezzi [da confermare] &euro; 10 &ndash; 20`: PHP dà
   `Prezzi [da confermare] € 10 – 20`, JS dà `Prezzi &euro; 10 &ndash; 20`. Il JS toglie ogni parentesi
   quadra e conosce 6 entità; il PHP toglie solo gli shortcode registrati e le conosce tutte. Anteprima,
   contatori e punteggio possono quindi non coincidere con la pagina. Le fixture condivise coprono solo il
   titolo (`seo-title.json`): bastano 4–5 casi per `text()` e `excerpt()` nello stesso file, senza codice
   nuovo.
4. **Il gruppo di un controllo sta nel posto sbagliato.** [Panel.js:340-343](../modules/seo/assets/src/editor/Panel.js#L340-L343)
   divide i controlli con `slice( 0, 9 )`: aggiungendone uno in `score.js` il gruppo "Contenuto" slitta in
   silenzio. Un campo `group` in ogni controllo (+1 riga per controllo, −2).

Da non toccare:

- Nessun metodo pubblico del perimetro è senza utilizzatori (controllato con uno script su `src`, `modules`
  ed `extensions`).
- `Meta::current()` è chiamato tre volte per pagina e legge le impostazioni 10 volte, ma costa 0,18 ms a
  chiamata: una cache non serve.
- I tre bottoni "Genera con AI" e le due anteprime di Google si somigliano, ma hanno due utilizzi ciascuno
  e stati diversi: un componente comune sarebbe più codice.
- Il modulo Prestazioni (196 righe PHP, 193 JS) è già il minimo: ogni funzione è un filtro o un
  `remove_action` del core.
- Il flag "nessun redirect" ipotizzato in PLAN-SEO §8 non serve (§5).

## 4. Presupposti che non reggono più

| Presupposto | Realtà |
|---|---|
| "Le pagine degli allegati le spegne il core" (PLAN-SEO, Decisioni) | Solo sui siti creati dalla 6.4 in poi: su quelli aggiornati l'opzione resta a 1 (§1.2) |
| "Un 410 risponde con `status_header( 410 )`" (PLAN-SEO §4) | Non se il core indovina un altro contenuto (§1.1) |
| "Avvio di WordPress: 0 query di Barrio Suite" (audit.md §6) | Dalle fasi S0 e S9 sono 2 (§1.5) |
| "Tutti i contenuti del tipo in una richiesta, fino a ~2.000" ([RestContents.php:48-49](../modules/seo/src/Api/RestContents.php#L48-L49)) | Il limite è nei byte, non nel numero: 2.000 articoli da 9 KB sono 18,6 MB di JSON e 61 MB di memoria in più. Con i contenuti dei page builder si arriva lì con poche centinaia di pagine (§5) |
| "Il timeout di 60 secondi può superare `max_execution_time`" (PLAN-SEO §8) | Su Linux il tempo passato ad aspettare la rete non conta nel limite di PHP. I limiti veri sono quelli di PHP-FPM e del proxy davanti al sito, spesso 60 secondi |
| "I tipi pubblici sono contenuti da pubblicare" | Vale sui siti dell'agenzia. Su un sito qualsiasi sono pubblici anche i tipi di altri plugin (§2) |
| "Le parole chiave servono allo schema" (PLAN-SEO §2.1) | Nessun motore le usa, e si vedono (§2) |
| "Il costo dei redirect va misurato" (PLAN-SEO §8) | Misurato: una SELECT da 0,2 ms, due con una query string |
| "llms-full.txt: misurare quanto ci mette" (PLAN-SEO §8) | Misurato: 0,12–0,39 secondi a cache vuota, 2 MB. Il transient deve però stare in `max_allowed_packet` (16 MB sul sito di prova): con più tipi lunghi e più lingue non ci sta, e allora si rigenera a ogni richiesta |
| Il lavoro è su GitHub | `con-updater` segue `origin/stable`, fermo alla fase S1: 11 commit non pushati |

## 5. Prestazioni

Pagine del sito, con tutti i moduli accesi contro SEO e Prestazioni spenti. I tempi totali (75–88 ms) non
cambiano oltre il rumore della misura.

| Pagina | Query totali | Query di Barrio Suite | Tempo delle sue query |
|---|---|---|---|
| Home con gli ultimi articoli | da 24 a 29 | 5 | 1,07 ms |
| Articolo | da 29 a 34 | 5 | 1,10 ms |
| Pagina | da 20 a 25 | 5 | 1,01 ms |
| Pagina con una query string | da 20 a 26 | 6 | 1,18 ms |
| Categoria | da 28 a 34 | 6 | 1,33 ms |
| 404 | da 18 a 21 | 3 | 0,57 ms |

Delle 5 query, 2 sono le opzioni mancanti (§1.5), 1 è la ricerca del redirect e 2 leggono l'immagine di
condivisione predefinita. Costruire l'head di un articolo costa 2,8 ms.

A scala, a cache degli oggetti vuota:

| Operazione | 500 articoli | 2.000 | 5.000 |
|---|---|---|---|
| SEO → Contenuti (`GET /seo/contents`) | 135 ms, 4,6 MB di JSON, +17 MB | 510 ms, 18,6 MB, +61 MB | non provato |
| Card della Bacheca (`GET /seo/stats`) | 14 ms | 32 ms | 75 ms |
| Conteggi della sitemap e `hbSitemap` (`Sitemaps::each()`) | 41–45 ms, +15 MB | 145–155 ms, +59 MB | 410–455 ms, +87 MB |
| Una pagina di `wp-sitemap.xml` da 2.000 URL, con il filtro del modulo | — | 141 ms (solo core: 111) | 139 ms (solo core: 109) |
| llms.txt a cache vuota | 11 ms | 23 ms | 32 ms |
| llms-full.txt a cache vuota | 124 ms, 1,9 MB | 193 ms | 387 ms |

Nell'editor a blocchi la sidebar SEO ricalcola il punteggio a ogni modifica
([sidebar.js:17-30](../modules/seo/assets/src/editor/sidebar.js#L17-L30)). Tempo di script per tasto, in
Chrome 158: su un articolo di 21 KB 7,7 ms con la sidebar e 5,8 senza; su uno di 107 KB 12,0 e 7,5. Resta
lontano dai 50 ms in cui la scrittura rallenta, quindi non va toccato; va rimisurato se i controlli
aumentano.

Due limiti da tenere presenti, non da correggere ora:

- **SEO → Contenuti** manda il contenuto intero di ogni riga, perché il punteggio si calcola nel browser.
  Quando arriva un sito con più di qualche centinaio di pagine lunghe: chiedere 200 righe alla volta e
  unirle nel browser (~10 righe), senza toccare filtri e ordinamento.
- **`hbSitemap`** è pubblico, senza cache, e legge tutti gli URL in una richiesta
  ([seo.php:310-364](../extensions/barrio-suite-wpgraphql/seo.php#L310-L364)): a 5.000 articoli sono
  0,4 secondi e 87 MB, vicino al limite di 128 MB di molti hosting. Oltre quella soglia: un transient
  svuotato con gli stessi hook di llms.txt (~10 righe).

Una pagina archivio (`_barrio_seo_collection`) aggiunge 37 ms, 9 query e 29 KB di JSON-LD a ogni
visualizzazione ([Schema.php:201-217](../modules/seo/src/Schema.php#L201-L217)). Con una cache di pagina non
si vede.

## 6. Sicurezza

Cosa regge:

- Le sette rotte provate (`/seo/settings`, `/seo/redirects`, `/seo/stats`, `/seo/contents/…`, `/ai`,
  `/google`, `/performance`) rispondono 401 a un anonimo; ogni rotta ha la sua capability.
- I meta SEO si scrivono solo con `barrio_edit_seo` più il permesso sul contenuto; la metabox classica ha un
  nonce per contenuto e passa dalla validazione della REST del core.
- Nell'head tutto è passato da `esc_attr`, il titolo da `esc_html`, il JSON-LD da `JSON_HEX_TAG`.
- La chiave del service account è cifrata, in sola scrittura, e il JWT firmato va solo all'endpoint dei
  token di Google scritto nel codice. Regione e modello sono validati prima di finire in un URL.
- La tabella dei redirect ha UNIQUE e CHECK, e ogni query è preparata.

Punti minori:

- Una destinazione `//altro-sito.example/x` passa come percorso (riprodotto: 201) e porta fuori dal sito.
  La sceglie chi gestisce il SEO, quindi non è un rischio; `^(/(?!/)|https?://)` la tratta come le altre
  (1 riga in [RestRedirects.php:173](../modules/seo/src/Api/RestRedirects.php#L173)).
- `window.barrioSeo` porta il contenuto del post nella pagina senza `JSON_HEX_TAG`
  ([Editor.php:199](../modules/seo/src/Editor.php#L199)), a differenza del JSON-LD. Un commento HTML non
  chiuso seguito da `<script` può rompere la pagina di modifica. Stessa flag, 1 riga.
- "Genera con AI" non ha un limite per utente: chi ha `barrio_edit_seo` può generare a volontà sul conto
  Google del sito.

## 7. Interfaccia

Misurato in Chrome 158 su Contenuti, Redirect, Organizzazione, Impostazioni, Prestazioni e Bacheca: a
360 px nessuna pagina esce in orizzontale, nessun errore in console, nessun campo senza etichetta e nessun
bottone senza nome.

Da migliorare:

- **Formati degli articoli tra le tassonomie.** `post_format` compare in Indicizzazione, in Assistenti AI
  e nel selettore di Contenuti (riprodotto). È una riga in più che nessuno deve decidere: si toglie con
  l'elenco unico (§3.1).
- **Nessuna riga per gli allegati** nella card Indicizzazione, dove servono (§1.2).
- **La lista dei modelli AI contiene anche modelli che non scrivono testo** (Imagen, Veo, embedding): lo
  dice già PLAN-SEO §8, e l'errore arriva solo generando. Tenere i nomi che iniziano con `gemini`
  ([Ai.php:118-135](../src/Ai.php#L118-L135), 1 riga) contraddice "nessun nome nel codice", ma evita la
  scelta sbagliata.
- **La modale di un redirect si chiude senza chiedere**, quella del SEO di un contenuto chiede conferma
  ([Redirects.js:86](../modules/seo/assets/src/seo/Redirects.js#L86) contro
  [Contents.js:75-83](../modules/seo/assets/src/seo/Contents.js#L75-L83)). Stesso controllo, 5 righe.
- **Con i permalink semplici llms.txt non esiste**, ma la card mostra i due link (verificato in lettura:
  `$wp->request` resta vuoto). Nascondere i link o dirlo.
- **Un meta non valido dalla metabox classica si perde in silenzio**: il risultato di `update_value()` non
  si guarda ([Editor.php:48](../modules/seo/src/Editor.php#L48), [68](../modules/seo/src/Editor.php#L68)).
  Il pannello manda solo valori validi, quindi oggi non succede.

## 8. Base per i prossimi moduli

- **Default che diventano permanenti.** In `barrio_seo` una chiave assente vale "indicizzato" e, per
  llms.txt, "Includi". Dopo la prima installazione vera, cambiare il significato di "assente" cambia i siti
  senza che nessuno salvi niente: le scelte del §2 vanno fatte prima.
- **Moduli senza database.** Prestazioni è il primo; il prossimo costerà un'altra query a richiesta finché
  `Migrator` legge una versione che non esiste (§1.5).
- **Elenco dei tipi** (§3.1): il prossimo modulo che lavora sui contenuti lo userà.
- **Svuotare una cache quando cambiano i contenuti.** Oggi lo fa solo llms.txt; se arriva la cache di
  `hbSitemap` conviene che usi la stessa funzione, corretta come al §1.4.
- Lo schema "un'opzione, il suo JSON Schema, GET e PUT" è ripetuto in Prestazioni, AI, Google, SMTP e
  impostazioni SEO. Ogni copia è di 30–40 righe e ha le sue eccezioni (segreti, sezioni): un'astrazione
  comune non le accorcerebbe.

## 9. Ordine consigliato

1. Push delle fasi S2–S9.
2. I punti 1–6 del §1: una trentina di righe.
3. Le tre scelte di default del §2, prima di installare il plugin su un sito vero.
4. L'elenco unico dei tipi e il controllo dei cicli (§3.1 e §3.2), che chiudono anche §1.3 e due punti del
   §7.
5. I punti 7 e 8 del §1, l'errore di Plugin Check, i ritocchi dell'interfaccia.
6. Le richieste a pagine di Contenuti e la cache di `hbSitemap`, quando arriva un sito grande.

## Non verificato

- `legacy-scan` di modern-web-guidance: il comando è stato bloccato dai permessi della sessione.
- Un service account vero di Google, WPML e un frontend headless reale: l'AI e GraphQL sono provati solo
  dai test del progetto.
- MySQL 8.0 (le misure sono su MariaDB 12.3), Nginx (in particolare se inoltra `/llms.txt` a WordPress),
  Firefox, Safari e i lettori di schermo.
- Rich Results Test e PageSpeed Insights su un sito pubblico.
- L'estensione di migrazione è stata letta, non eseguita oltre i suoi test.
- I tempi vengono da Docker su un Mac: valgono come confronto, non come valori assoluti.
- Citati a memoria, senza rileggere la fonte: il manuale di PHP su `max_execution_time`, la documentazione
  di Google sulle proprietà di `WebPage` e il comportamento di Yoast e Rank Math con le parole chiave.

Rischio principale: finché il punto 1 del §1 non è corretto, una regola 410 può mandare i visitatori e
Google su un'altra pagina invece di dire che il contenuto non c'è più, senza alcun avviso.
