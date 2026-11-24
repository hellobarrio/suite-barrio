# Audit di Barrio Suite: i moduli di PLAN-MODULI

Data: 20 novembre 2026. Codice esaminato: branch `con-updater`, commit `7c903d4`.

Perimetro: gli otto moduli di [PLAN-MODULI.md](../PLAN-MODULI.md) (Popup, Banner, Orari, Blocchi, Ordinamento,
Duplicazione, Testi alternativi, Statistiche), ciò che il core ha in comune per loro (`src/Period.php`,
`src/Roles.php`, `assets/src/admin/period.js`, `sortable.js`, la pagina Contenuti) e le loro parti nelle
estensioni WPGraphQL e di migrazione: circa 12.000 righe. Il core, Forms, SEO e Prestazioni sono in
[audit.md](audit.md) e [audit-seo.md](audit-seo.md) e qui non si ripetono.

Lo scopo è lo stesso: cosa togliere o semplificare, quali presupposti non reggono più e cosa migliorare in
sicurezza, prestazioni e interfaccia, prima di usare il plugin sui siti, pubblicarlo su WordPress.org e
costruirci sopra gli altri moduli. Per ogni punto c'è la prova e la modifica più piccola che conserva il
comportamento attuale.

## In breve

Il codice è sano. PHPCS, PHPStan livello 8, ESLint e stylelint sono puliti; passano 64 test JS e 538 test
PHP su 541 (i 3 che falliscono sono di `ExtensionsTest`, fuori perimetro: sul sito di prova è installata
l'estensione WPGraphQL e il test la trova nell'elenco). Plugin Check non segnala niente nei file degli otto
moduli. Non sono emerse vulnerabilità e non c'è codice morto.

Restano quattro cose:

- **Uso reale**: 9 difetti, 8 dei quali riprodotti, ognuno da 1–8 righe. I due che pesano di più: "Genera
  tutti" dei Testi alternativi si ferma per sempre alla prima immagine con il file mancante, e con gli
  articoli in ordine manuale il feed RSS non è più in ordine di data.
- **WordPress.org**: niente di bloccante in questo perimetro. Vanno però decisi tre comportamenti di
  default, perché dopo la prima installazione cambiarli cambia i siti: Duplica su ogni tipo di contenuto con
  interfaccia, l'ordine manuale che vale anche dove si chiedono "gli ultimi", i popup programmati leggibili
  in GraphQL.
- **Semplificare**: Popup e Banner sono lo stesso modulo scritto due volte. Su sei coppie di file, 1.386
  righe sono identiche dopo aver uniformato i nomi (dal 77% all'89% del file più corto di ogni coppia).
- **Lavoro non al sicuro**: le fasi M1–M10 esistono solo su questo computer: 12 commit non pushati su
  `con-updater`, 36 su `senza-updater`.

## Metodo

- Lettura completa del PHP del perimetro e degli script del sito e dell'editor; lettura delle pagine admin
  in React, per intero dove c'è logica (salvataggi, anteprime, azioni di massa).
- Strumenti: PHPCS, PHPStan, ESLint, stylelint, PHPUnit (541 test, anche con WPGraphQL caricato), i test JS;
  Plugin Check 2.2.0 sul plugin principale; uno script che cerca i metodi pubblici senza utilizzatori e uno
  che conta le righe uguali tra due file.
- Prove dal vivo sul sito dei test di wp-env (porta 8889: WordPress 7.1.5, PHP 8.2.35, MariaDB 12.3.3,
  Twenty Twenty-Five, senza cache degli oggetti):
  - conteggio e tempi delle query con `SAVEQUERIES`, pagina per pagina;
  - misure a scala con 2.000 immagini e 2.000 pagine create con una sola `INSERT`;
  - i permessi di 21 rotte REST da anonimo, autore, editor e amministratore;
  - Chrome 158 senza interfaccia: popup e banner nel sito a 1280 e 360 px, 15 pagine admin a 1400 e 360 px,
    le date delle Statistiche, la modale Riordina con 1.011 pagine.
- Durante le prove nel browser le richieste verso `googleapis.com` erano bloccate: Places, GA4 e Vertex AI
  sono provati solo dai test del progetto.
- Il sito è tornato com'era: il database è stato ripristinato dal backup fatto all'inizio, il mu-plugin di
  misura è stato eliminato, Plugin Check è stato disattivato.
- "Riprodotto" vuol dire eseguito davvero; "verificato in lettura" vuol dire letto riga per riga, senza
  eseguirlo. Le modifiche proposte non sono state applicate.

## 1. Da correggere prima dell'uso reale

Riprodotti:

| # | Problema | Dove | Prova | Modifica minima |
|---|---|---|---|---|
| 1 | "Genera tutti" si ferma alla prima immagine con il file mancante, e a ogni nuovo tentativo riparte da quella | [Alt.js:332-379](../modules/alt/assets/src/Alt.js#L332-L379), [Module.php:494-500](../modules/alt/src/Module.php#L494-L500) | `POST /alt/5170` risponde `404 barrio_alt_file` a ogni giro; `fields=ids` dà gli ID dal più recente, quindi il primo è sempre lo stesso, e `run()` esce al primo errore. Basta un allegato senza file (un sito copiato senza tutti gli upload) per non generare più niente in massa | In `run()` saltare gli errori della singola immagine (`barrio_alt_file`, `barrio_alt_format`, `barrio_alt_not_image`) contandoli nell'avviso finale, e fermarsi solo sugli altri: quota, configurazione, rete (~6 righe) |
| 2 | Con gli articoli in ordine manuale il feed RSS esce in quell'ordine, non dal più recente | [Module.php:227-245](../modules/ordering/src/Module.php#L227-L245) | Con "Hello world!" (11 novembre) spostato sopra "Catering" (18 novembre), `/feed/` li elenca in quest'ordine. Chi legge il feed prende i primi elementi come i più nuovi: lettori RSS, newsletter automatiche | `\|\| $query->is_feed()` nell'uscita iniziale di `posts()` (1 riga). Lo stesso presupposto tocca "Ultimi articoli", che non si può distinguere: §4 |
| 3 | Su un'installazione nuova, 2 query in più a ogni richiesta del sito e della REST | [Module.php:345-350](../modules/hours/src/Module.php#L345-L350), [Module.php:227-229](../modules/ordering/src/Module.php#L227-L229) | Senza le opzioni salvate, ogni pagina misurata ha `SELECT … 'barrio_hours'` (da `init`) e `SELECT … 'barrio_ordering'` (da `pre_get_posts`): un'opzione che non esiste non resta in cache tra una richiesta e l'altra. È il difetto del §1.5 di audit-seo.md, per la terza volta. Nell'admin fa lo stesso `barrio_duplicate`, letta su `admin_init` (questo solo in lettura) | Una migrazione `0002` in `hours` e in `ordering` che crea l'opzione vuota in autoload (`add_option( …, [], '', true )`, 3 righe ciascuna). Nell'estensione di migrazione "ha già i suoi dati" deve diventare "l'opzione non è vuota" ([hours.php:35](../extensions/barrio-suite-migration/hours.php#L35), [ordering.php:19](../extensions/barrio-suite-migration/ordering.php#L19), 1 riga ciascuna) |
| 4 | "Genera tutti" fa una query per immagine prima di iniziare | [Module.php:217-221](../modules/alt/src/Module.php#L217-L221) | Con 2.003 immagini senza alt, `GET /alt/images?alt=missing&fields=ids` fa 2.005 query in 442 ms: `get_post_mime_type()` legge un allegato alla volta | `_prime_post_caches( $query->posts, false, false )` prima del filtro (1 riga): restano 3 query |
| 5 | Nelle Statistiche, scrivere una data di Personalizzato fa partire una richiesta a ogni tasto, anche con anni come 0002 | [Analytics.js:181-209](../modules/analytics/assets/src/Analytics.js#L181-L209), [696-712](../modules/analytics/assets/src/Analytics.js#L696-L712), [Module.php:273-278](../modules/analytics/src/Module.php#L273-L278) | Scrivendo una data da tastiera nel campo "Da" partono 7 `GET /analytics/report`, tre con `from=0002-…`, `0020-…`, `0202-…`. Il server li accetta: `period( 'custom', '0002-01-10', … )` dà 739.565 giorni e un periodo di confronto che inizia nell'anno -2023. Ogni richiesta sono due chiamate a Google fuori cache, e l'errore di Google lampeggia nella pagina | Un ritardo di 400 ms come nelle anteprime di Popup e Orari, `min` sul campo "Da" e lo stesso limite in `period()` (~8 righe) |
| 6 | La copia di un contenuto ha due valori per i meta che un altro plugin scrive al salvataggio, e si legge quello sbagliato | [Module.php:297-304](../modules/duplicate/src/Module.php#L297-L304) | Con un hook di prova su `save_post_page` che aggiunge il meta `audit_layout` = `predefinito`: l'originale ha `["largo"]`, la copia `["predefinito","largo"]`, e `get_post_meta( $copia, 'audit_layout', true )` dà `predefinito`. `wp_insert_post()` fa partire gli hook prima che i meta dell'originale arrivino | `delete_post_meta( $copy, $key )` prima di copiare ogni chiave (1 riga; lo stesso con `delete_term_meta` per i termini) |
| 7 | Un banner senza link non si può fermare da tastiera | [Module.php:82-84](../modules/banners/src/Module.php#L82-L84), [banner.js:83-92](../modules/banners/assets/frontend/banner.js#L83-L92) | In Chrome il banner senza link ha 0 elementi che prendono il focus: la scritta si ferma con il mouse, il tocco o un clic, mai con la tastiera. PLAN §3.3 dice "si ferma anche col focus", che vale solo con un link. È il criterio WCAG 2.2.2 | `tabindex="0"` sul corpo del banner senza link: `focusin` lo ferma già (1 riga in PHP, 1 nell'anteprima, 1 selettore in [banner.css:35](../modules/banners/assets/frontend/banner.css#L35)) |
| 8 | Un anonimo legge in GraphQL i popup e i banner programmati, con i testi, prima che inizino | [popups.php:95-112](../extensions/barrio-suite-wpgraphql/popups.php#L95-L112), [banners.php:73-90](../extensions/barrio-suite-wpgraphql/banners.php#L73-L90) | Con un popup "Black Friday" programmato per il 27 novembre, `{ hbPopups { title content startsAt } }` senza login lo restituisce con il testo. `hello_standard` usa solo `hbPopup`, `hbBanner` e `hbOrari`: `hbPopups` e `hbBanners` non hanno utilizzatori | Togliere i due campi (−36 righe, più la fixture dello schema). Il problema per cui erano nati, il frontend in cache, lo chiude già la revalidation programmata a ogni inizio e fine (PLAN §11) |

Verificato in lettura:

| # | Problema | Dove | Prova | Modifica minima |
|---|---|---|---|---|
| 9 | Il transient dei popup e dei banner accesi non ha versione né scadenza | [PopupRepository.php:37-47](../modules/popups/src/PopupRepository.php#L37-L47), [BannerRepository.php:36-46](../modules/banners/src/BannerRepository.php#L36-L46) | Tiene le righe già trasformate, in autoload, finché qualcuno non salva. Dopo un aggiornamento che cambia la forma di un popup il sito continua a leggere la forma vecchia: è già successo togliendo `trigger` (il popup del sito di prova lo ha ancora nel database). Oggi non dà errori, perché `html()` legge solo chiavi che c'erano già; alla prossima chiave nuova è un avviso PHP in ogni pagina. È il §1.7 di audit.md, sui form | La versione del plugin dentro il valore, e si rilegge se è diversa (3 righe per repository, o una volta sola con il §3.1) |

## 2. WordPress.org

Plugin Check 2.2.0 è stato lanciato sulla cartella del repository: nei file degli otto moduli non dà errori
né avvisi. Le 66 segnalazioni totali sono quelle già note (header `Update URI`, updater, `suppress_filters`
del SEO), riguardano i file di sviluppo della cartella (zip, markdown, file nascosti) o nascono dal
controllare le estensioni dentro il plugin principale (40 sono il loro text domain).

I servizi esterni dei moduli sono dichiarati nel readme, con ciò che parte e quando: l'immagine `large` per i
Testi alternativi, Places per gli Orari, Data API e Admin API per le Statistiche. "Sostituisci la Bacheca
di WordPress" è spento di default e vale solo per chi legge le statistiche.

Da decidere prima della prima installazione vera:

| Punto | Prova | Modifica minima |
|---|---|---|
| Duplica compare su ogni tipo di contenuto con interfaccia, anche quelli degli altri plugin | [Module.php:82-93](../modules/duplicate/src/Module.php#L82-L93) usa `Admin::content_types()`: sul sito di prova sono `post`, `page`, `wp_block` e `wp_navigation`. La copia è contenuto, meta e termini: per un tipo che tiene dati altrove (figli, tabelle sue) è una copia a metà. Verificato in lettura: non provato con WooCommerce o ACF | Acceso di default solo per i tipi che hanno una pagina nel sito (`is_post_type_viewable()`), gli altri a scelta (stima: 1 riga in `enabled()` e 2 nella scheda) |
| L'ordine manuale vale anche dove un tema o un blocco chiede "gli ultimi" | Riprodotto: con gli articoli in ordine manuale, `wp_get_recent_posts()`, il blocco "Ultimi articoli" e `GET /wp/v2/posts` danno l'ordine manuale. È la regola di PLAN §6.1 (la data decrescente vale "nessun ordine"), che serve al Query Loop e alla REST | Tenere la regola e dirlo nella card dei tipi di contenuto ([Ordering.js:25-28](../modules/ordering/assets/src/Ordering.js#L25-L28)): "anche negli elenchi degli ultimi contenuti" |
| I popup e i banner programmati sono pubblici in GraphQL | §1.8 | §1.8 |

## 3. Cosa togliere o semplificare

1. **Popup e Banner sono lo stesso modulo scritto due volte.** Righe non vuote, e quante sono identiche
   dopo aver sostituito "popup" e "banner" con lo stesso nome:

   | Coppia di file | Righe | Uguali |
   |---|---|---|
   | `Popups.js` e `Banners.js` | 906 e 693 | 616 (88% del più corto) |
   | `Module.php` | 347 e 256 | 220 (85%) |
   | `PopupRepository.php` e `BannerRepository.php` | 270 e 214 | 192 (89%) |
   | `PopupsTest.php` e `BannersTest.php` | 339 e 222 | 172 (77%) |
   | `popups.php` e `banners.php` in WPGraphQL | 137 e 104 | 93 (89%) |
   | `popups.php` e `banners.php` nella migrazione | 133 e 116 | 93 (80%) |

   Ogni regola sulle tabelle, ogni correzione al periodo o al transient (§1.9) oggi va scritta due volte, e
   un terzo modulo con un elenco e un periodo la scriverebbe una terza. PLAN ha già messo in comune il
   periodo (`src/Period.php`, `period.js`): manca il resto. La modifica più piccola che toglie il grosso,
   senza unire i due moduli:
   - in `assets/src/admin/period.js` l'elenco (tabella, filtri, menu ⋯, azioni di massa: 316 righe in
     ognuno dei due file), a cui ogni modulo passa la rotta, il parametro dell'URL, i testi e la prima
     colonna. Stima: −230 righe;
   - un trait per i due Repository con `get`, `all`, `live`, `shown`, `status`, `duplicate`,
     `set_enabled`, `delete` e `changed`; a ogni Repository restano tabella, schema e colonne. Stima: −100
     righe.

   Le rotte dei due `Module` (220 righe uguali) possono aspettare il terzo modulo. I test restano due:
   dimostrano i vincoli di ognuna delle due tabelle, e che dopo la modifica niente è cambiato. GraphQL e
   migrazione non si toccano: il primo è stato rifatto con lo schema di PLAN-GRAPHQL.md (G3, 24 novembre), la
   seconda si toglie sito per sito.
2. **`hbPopups` e `hbBanners`**: nessun utilizzatore e un dato che non dovrebbe uscire (§1.8).
3. **Sette `uninstall()` con lo stesso ciclo** che toglie la capability a ogni ruolo
   ([Module.php:42-45](../modules/popups/src/Module.php#L42-L45) e gli altri sei). `Roles::remove( $caps )`
   in `src/Roles.php`, che ha già `get()` e `save()`: +5 righe, −14.
4. **Un testo fermo a M6.** La card dei Banner dice "Announcements at the top or bottom of pages"
   ([Banners.js:47-50](../modules/banners/assets/src/Banners.js#L47-L50)), in italiano "Avvisi in cima o in
   fondo alle pagine": la posizione in fondo è stata tolta. 1 riga, più la traduzione.

Da non toccare:

- Nessun metodo pubblico del perimetro è senza utilizzatori (controllato con uno script su `src`, `modules`
  ed `extensions`).
- `Duplicate::admin_init()` richiama `Admin::content_types()` una volta per tipo, a ogni pagina
  dell'admin: costa 0,05 ms sul sito di prova e 1,55 ms con 34 tipi e 43 tassonomie. Una cache non serve.
- A ogni richiesta `Plugin::boot()` fa 22 `glob()`, due per modulo, per sapere se ci sono migrazioni:
  0,11 ms su un disco vero (5 µs l'uno). Su wp-env sono 7 ms, perché la cartella è montata dal Mac: i tempi
  locali vanno letti sapendolo.
- `Render::status()` in PHP e `status()` in `hours.js` fanno lo stesso calcolo, ma servono tutti e due (il
  testo senza JavaScript e quello con la cache di pagina) e sono provati sulle stesse fixture.
- `Ordering.js` e `Duplicate.js` sono uguali al 71%, ma sono 90 righe l'uno: un componente comune sarebbe
  più codice.
- La card dei Testi alternativi nella Bacheca chiede una pagina di 20 righe per mostrare tre numeri
  ([Alt.js:255-266](../modules/alt/assets/src/Alt.js#L255-L266)): con 2.000 immagini sono 13 query e
  43 ms.
- Lo schema "un'opzione, il suo JSON Schema, GET e PUT, i ruoli" è ripetuto in sei moduli: come già detto
  in audit-seo.md §8, ogni copia ha le sue eccezioni e un'astrazione non le accorcerebbe.

## 4. Presupposti che non reggono più

| Presupposto | Realtà |
|---|---|
| "Un modulo acceso non cambia niente finché non lo si configura" (PLAN, Decisioni) | Finché non si salva niente costa 2 query a richiesta (§1.3) |
| "Nel sito nessuna query" per i popup (PLAN §1) | Vero per popup e banner: a regime 0 query, il transient è in autoload. Ma il form dentro un popup si legge a ogni pagina del sito: 9 query e 2,6 ms invece di 0,5 (misurato sul popup del sito di prova) |
| "Un ordine per data decrescente vale nessun ordine" (PLAN §6.1) | Non per il feed (§1.2) né per chi chiede gli ultimi contenuti (§2) |
| Le azioni di massa "si fermano al primo errore come quelle del SEO" (PLAN §8.1) | Giusto per la quota o la configurazione. Per l'errore di una sola immagine blocca tutte le altre (§1.1) |
| Il banner "si ferma anche col focus" (PLAN §3.3) | Solo se ha un link (§1.7) |
| Personalizzato ha "le date controllate nello schema della rotta" (PLAN §9.3) | Controlla l'ordine e il limite di ieri, non quanto indietro si va (§1.5) |
| `hbPopups` e `hbBanners` servono ai frontend in cache (PLAN §10) | Nessun frontend li usa, e la revalidation programmata risolve lo stesso problema (§1.8) |
| "Oltre ~2000 voci per tipo servono pagine" ([Module.php:188](../modules/ordering/src/Module.php#L188)) | Il server regge: 2.000 pagine si leggono in 29 ms e si salvano con 7 query. Il limite è il trascinamento: con 1.011 pagine la modale si apre in 0,3 s ma ogni passo del mouse costa 67 ms (15 al secondo) e ogni tasto circa 150 ms. Fluido fino a qualche centinaio |
| "Con WPML si riordina una lingua alla volta" (PLAN §11) | `OrderRepository` legge con SQL diretto ([25-38](../modules/ordering/src/OrderRepository.php#L25-L38), [45-62](../modules/ordering/src/OrderRepository.php#L45-L62)), senza i filtri di WPML: la modale elenca insieme i contenuti e i termini di tutte le lingue. L'ordine di ogni lingua resta giusto, ma l'elenco confonde. Verificato in lettura, senza WPML |
| A Google va "mai l'originale grande" (PLAN §8.1) | Se la dimensione `large` non esiste (dimensioni spente da un tema, generazione fallita su un file enorme) parte il file caricato, qualunque sia il peso ([Module.php:468-473](../modules/alt/src/Module.php#L468-L473)). Verificato in lettura. Un tetto in byte, 2 righe |
| I blocchi registrati solo in JavaScript "vanno contati sui siti dei clienti" (PLAN §11) | Ancora da fare: con un solo blocco spento per un ruolo, quel ruolo non li trova più |
| Il lavoro è su GitHub | `con-updater` è 12 commit avanti a `origin/stable`, `senza-updater` 36 avanti a `origin/senza-updater` |

## 5. Prestazioni

Query dei moduli del perimetro nelle pagine del sito (home, pagina, categoria, REST), senza cache degli
oggetti:

| Stato | Query | Note |
|---|---|---|
| A regime, con popup e banner | 0 | I due transient sono in autoload |
| Prima richiesta dopo un salvataggio | 8 | Rilegge gli accesi e riscrive i due transient (misurato con un popup e nessun banner) |
| Popup con un form nel testo | 9 | Sono del form, in ogni pagina (§4) |
| Installazione nuova, niente salvato | 2 | §1.3 |

Nel browser, popup e banner pesano 4,9 KB compressi in tutto (`popup.css` 1,0, `popup.js` 1,6,
`banner.css` 0,7, `banner.js` 1,6) e si caricano solo quando ce n'è uno. Lo spostamento di layout misurato
è 0 a 1280 e a 360 px: il banner è già nell'HTML, il popup è un `<dialog>` chiuso.

A scala:

| Operazione | Misura |
|---|---|
| Testi alternativi, una pagina della tabella con 2.003 immagini | 10–13 query, 17–43 ms |
| "Genera tutti" con 2.003 immagini, prima di iniziare | 2.005 query, 442 ms (§1.4) |
| Ordinamento, leggere 2.000 pagine (`GET /ordering/post/page`) | 5 query, 29 ms, 114 KB di JSON |
| Ordinamento, salvare 2.000 pagine | 7 query, 206 ms; `post_modified` non cambia |
| Modale Riordina con 1.011 pagine | 336 ms per aprirla, 11.149 nodi, 67 ms a passo di trascinamento |

Le pagine Barrio caricano un solo script per tutti i moduli, 287 KB (76,6 KB compressi). Ogni modulo nuovo
lo allunga per tutte le pagine: oggi non è un problema, e si può dividere per pagina quando lo diventa.

## 6. Sicurezza

Cosa regge:

- Le 21 rotte provate rispondono 401 a un anonimo. Un autore apre solo l'elenco delle immagini, perché può
  caricare file; le impostazioni di ogni modulo le apre solo un amministratore; un editor non apre
  `/blocks`, le impostazioni né la sincronizzazione con Google.
- Riordinare chiede la capability del modulo, `edit_others_posts` del tipo ed `edit_post` su ogni ID;
  duplicare chiede un nonce per ID, `edit_post` sull'originale e `create_posts` del tipo.
- Ogni elenco di ID che finisce in SQL è fatto di interi; nomi di tabella e colonna passano da `%i`, il
  verso dell'ordinamento da un `enum`. Place ID e proprietà GA4 sono validati con un pattern prima di
  entrare in un URL.
- Nel sito ogni testo passa da `esc_html`, `esc_attr` o `esc_url`; il testo del popup è filtrato con
  `wp_kses_post` alla scrittura. Nell'admin c'è un solo `dangerouslySetInnerHTML` (l'anteprima degli
  Orari), con HTML scritto dal server già codificato.
- I moduli non tengono credenziali: usano il service account cifrato del core.

Punti minori:

- I popup e i banner programmati in GraphQL (§1.8).
- L'iframe dell'anteprima dei popup ha `sandbox="allow-scripts allow-same-origin"`
  ([Popups.js:571-576](../modules/popups/assets/src/Popups.js#L571-L576)), che insieme non isolano niente.
  Il contenuto è di chi lo sta scrivendo ed è già filtrato, quindi non è un rischio; va saputo se un giorno
  l'anteprima mostra contenuti di altri.
- La generazione dei testi alternativi non ha un limite per utente: chi ha `barrio_generate_alt` può
  generare a volontà sul conto Google del sito, come "Genera con AI" del SEO.
- `window.barrioAdmin` porta le impostazioni di Blocchi, Ordinamento e Duplicazione a chiunque apra una
  pagina Barrio ([Module.php:112-114](../modules/blocks/src/Module.php#L112-L114)). Sono elenchi di blocchi
  e di tipi: niente di riservato.

## 7. Interfaccia

Misurato in Chrome 158 su 15 pagine admin (Bacheca, Statistiche, Testi alternativi, Popup, Banner, Orari,
Contenuti, con le loro schede e i due editor): a 1400 e a 360 px nessuna esce in orizzontale, nessun campo è
senza etichetta e nessun bottone senza nome. In console ci sono solo gli errori delle richieste a Google
bloccate dalla prova e, nell'editor dei popup, un avviso di Chrome sull'evento `unload`, che viene da
`tinymce.min.js` di WordPress e non dal plugin. Nel sito il popup si apre come modale, prende il focus, ha
il titolo come nome, si chiude con Esc, rimette il focus dov'era e dopo la chiusura non torna.

Da migliorare:

- **Il banner senza link non si ferma da tastiera** (§1.7).
- **Le date di Personalizzato** fanno partire richieste mentre si scrive (§1.5).
- **"Genera tutti" non dice quale immagine ha dato errore** e non va avanti (§1.1): con la correzione,
  l'avviso finale può dire quante ne ha saltate.
- **Riordina con molte voci**: oltre qualche centinaio il trascinamento è a scatti (§4). Basta dirlo nel
  testo della modale finché non servono le pagine.
- **La card dei Banner** parla ancora del fondo della pagina (§3.4).
- **Il popup si apre dopo 2 secondi e sposta il focus** anche a chi sta già navigando con la tastiera: è
  il comportamento di ogni popup automatico, e Esc lo chiude. Da provare con VoiceOver, come dice già
  PLAN §11.

## 8. Base per i prossimi moduli

- **Un'opzione letta nel sito deve esistere dalla migrazione.** È la terza volta che un'opzione mancante
  costa una query a richiesta (`barrio_disabled_modules`, `barrio_performance_db_version`, ora
  `barrio_hours` e `barrio_ordering`). Un test solo le avrebbe trovate tutte: su un database appena
  migrato, una pagina del sito non fa nessuna `SELECT … FROM wp_options WHERE option_name = 'barrio_…'`
  (~15 righe in `ModulesTest`). Sul sito di prova manca ancora `barrio_disabled_modules`, perché il plugin
  era attivo prima della correzione: il test coprirebbe anche questo.
- **Una raccolta con periodo e testi per lingua** va scritta una volta (§3.1) prima del terzo modulo che
  ne ha bisogno. È anche il posto giusto per la versione del transient (§1.9).
- **Default che diventano permanenti.** `barrio_duplicate` salva i tipi spenti: una chiave assente vale
  "acceso", anche per i tipi che arrivano dopo con altri plugin. La scelta del §2 va fatta prima.
- **`Admin::content_types()`** è l'elenco unico che audit-seo.md §3.1 chiedeva per il SEO: Blocchi,
  Ordinamento e Duplicazione lo usano già. Il prossimo modulo che lavora sui contenuti parte da lì.
- **`ExtensionsTest` dipende dai plugin installati sul sito di prova**: 3 test falliscono con
  `barrio-suite-wpgraphql` nella cartella dei plugin. Finché è così, "test verdi" non si può leggere dal
  solo esito.

## 9. Ordine consigliato

1. Push di M1–M10 sui due branch.
2. I punti 1–7 del §1: una trentina di righe.
3. Le tre decisioni del §2, con il punto 8 del §1, prima di installare il plugin su un sito vero.
4. Il test delle opzioni mancanti (§8), che chiude per sempre il punto 3.
5. L'elenco e il Repository comuni di Popup e Banner (§3.1), con dentro il punto 9 del §1; poi
   `Roles::remove()` e il testo della card.
6. Le pagine nella modale Riordina e lo script diviso per pagina, quando un sito li chiede.

## Non verificato

- I servizi veri di Google (Places, GA4, Vertex AI con le immagini): bloccati durante le prove, coperti
  solo dai test con Google simulato.
- WPML, WooCommerce e ACF: i punti che li riguardano (§2, §4) sono letti nel codice, non provati.
- Blocchi: letto e coperto dai test del progetto, non provato a mano nell'editor. Lo stesso per i due
  script dell'editor (`duplicate.js`, `alt.js`).
- Il branch `senza-updater`: è stato esaminato solo `con-updater`.
- MySQL 8.0 (le misure sono su MariaDB 12.3), Firefox, Safari e i lettori di schermo.
- `legacy-scan` di modern-web-guidance non è stato lanciato.
- I tempi vengono da Docker su un Mac: valgono come confronto, non come valori assoluti.
- Citati a memoria, senza rileggere la fonte: il testo del criterio WCAG 2.2.2, il fatto che Yoast
  Duplicate Post elimini i meta della copia prima di riscriverli, la prima data che GA4 accetta (agosto
  2015).

Rischio principale: finché il punto 1 del §1 non è corretto, su un sito con un solo allegato senza file
"Genera tutti" non genera niente, e l'unico segnale è un errore che parla di un'immagine sola.
