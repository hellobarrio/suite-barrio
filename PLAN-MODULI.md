# Barrio Suite: piano dei moduli Popup, Banner, Orari, Blocchi, Ordinamento, Duplicazione e Testi alternativi

Terzo gruppo di moduli, riscritti da zero da `suite-barrio-prototipo` (`modules/popups`, `banners`, `orari`, `blocks`,
`orders`, `duplicate`, `auto-alt`, circa 7.700 righe, più le parti di `graphql.php`): si conservano le funzionalità,
non il codice. Valgono tutte le decisioni di [PLAN.md](PLAN.md) e [PLAN-SEO.md](PLAN-SEO.md) (database, REST, admin,
tabelle, moduli accesi e spenti, estensioni, privacy, traduzioni, test): qui c'è solo ciò che è nuovo. Dove il
prototipo è povero si prende da plugin noti (Popup Maker, Yoast Duplicate Post, Simple Page Ordering e Post Types
Order, PublishPress Blocks e Disable Gutenberg, Rank Math Local SEO).

Si lavora su `con-updater` e ogni fase si porta su `senza-updater` con un cherry-pick: tra i due branch resta una
sola differenza, l'updater da GitHub (`docs/release.md`).

## Decisioni

| Tema | Decisione |
|---|---|
| Database | **Come sempre, il database è il centro dello sviluppo**: ogni modulo parte dai dati, e ogni fase si apre con schema, migrazioni e i test che dimostrano ogni vincolo su MariaDB 10.5 e MySQL 8.0, prima di REST e interfaccia. Valgono tutte le regole di PLAN.md §2: InnoDB, foreign key, CHECK per gli insiemi chiusi, `utf8mb4_bin` per gli identificatori, date UTC scritte da PHP, NULL solo per "non applicabile", un enum PHP per ogni CHECK, solo i Repository usano `$wpdb`, transazioni sulle scritture su più tabelle, migrazioni numerate con il lock. Le raccolte di record (popup, banner) sono tabelle con i testi in tabelle `_i18n`. I record unici sono opzioni con JSON Schema. I dati di un contenuto di WordPress (l'ordine dei termini) sono suoi meta. `docs/database.md` documenta le tabelle nuove |
| Moduli | Sette voci nuove nella card Moduli: `popups` (Popup), `banners` (Banner), `hours` (Orari), `blocks` (Blocchi), `ordering` (Ordinamento), `duplicate` (Duplicazione), `alt` (Testi alternativi). Namespace `BarrioSuite\Popups`, `\Banners`, `\Hours`, `\Blocks`, `\Ordering`, `\Duplicate`, `\Alt` |
| Accesi di default | Come tutti i moduli, ma **un modulo acceso non cambia niente finché non lo si configura**: nessun popup o banner, nessun orario, nessun blocco disattivato, nessun tipo in ordine manuale, nessuna generazione al caricamento. L'unica cosa che si vede subito è il link "Duplica" nelle liste. Sui siti del prototipo l'estensione di migrazione tiene spenti i moduli che il prototipo ha accesi, fino alla migrazione (come per il SEO) |
| Popup e Banner separati | Due moduli, come nel prototipo, per accenderli, gestirli e dare i permessi separatamente: ognuno ha le sue tabelle, la sua pagina, la sua capability e la sua card. Ciò che hanno uguale sta nel core e si scrive una volta (§1): dove, chi e quando compaiono, il periodo, la frequenza, la lingua. Nel prototipo quella parte è copiata da un modulo all'altro, con gli stessi bug |
| Tabelle e non CPT | Popup e banner sono raccolte di record con testi per lingua, come i form: tabella più tabella `_i18n`, con la lingua scelta nella barra dell'editor e i componenti dei testi tradotti del builder (`TextIn`, `Fixed`, `fillTranslations`). Così funzionano senza WPML e il GraphQL resta con i campi del prototipo. Un CPT con l'editor a blocchi lascerebbe le traduzioni a WPML, e per il banner (una riga di testo) aprirebbe un editor a blocchi intero |
| Impostazioni | Orari, Blocchi, Ordinamento, Duplicazione e Testi alternativi hanno un solo record ciascuno: un'opzione con JSON Schema (`barrio_hours`, `barrio_blocks`, `barrio_ordering`, `barrio_duplicate`, `barrio_alt`), scritta solo dalla sua rotta REST. In autoload solo quelle lette nel sito (`barrio_hours`, `barrio_ordering`) |
| Admin | Quattro pagine nuove nel menu Barrio: **Testi alternativi**, **Popup**, **Banner** e **Orari**, perché ci lavora chi gestisce i contenuti; Testi alternativi come il SEO, con le schede Immagini e Impostazioni. Blocchi, Ordinamento e Duplicazione sono strumenti per i contenuti con poche impostazioni: invece di tre voci, una pagina **Contenuti** con una scheda per modulo acceso (la pagina sparisce con tutti e tre spenti), solo per gli amministratori. Ordinamento e Duplicazione si usano dove si lavora, nelle liste di WordPress: Riordina accanto ad Aggiungi, Duplica tra le azioni. Menu: Bacheca, Form, SEO, Testi alternativi, Popup, Banner, Orari, Contenuti, Prestazioni, Estensioni, Impostazioni |
| Capability | Nuove solo dove WordPress non ne ha una adatta: `barrio_manage_popups`, `barrio_manage_banners` e `barrio_manage_hours` (admin ed editor, dalla migrazione `0002` di ogni modulo), con la card Permessi nelle impostazioni del modulo. Anche Ordinamento, Duplicazione e Testi alternativi hanno i Permessi, per spegnerli a un ruolo: `barrio_reorder`, `barrio_duplicate` e `barrio_generate_alt` (dalla migrazione `0001`, a chi poteva già usarli), in più a quelle del core (`edit_post`, `edit_others_posts`, `edit_terms`, `upload_files`). Blocchi e le impostazioni chiedono `manage_options` |
| Google | Orari e Testi alternativi usano il **service account di Google del core** (Barrio → Impostazioni), come l'AI del SEO: nessuna chiave API nei moduli. Places API (New) accetta i token OAuth dei service account (scope `cloud-platform`, progetto con fatturazione in `X-Goog-User-Project`). Le chiamate partono da un clic in admin o dalla sincronizzazione notturna degli orari, che il readme dichiara |
| Headless | `hbPopup`, `hbBanner` e `hbOrari` restano con i nomi e le forme del prototipo nell'estensione `barrio-suite-wpgraphql`, un file per modulo. Ordinamento non ha GraphQL suo: `menuOrder` e `orderby: MENU_ORDER` sono già di WPGraphQL |
| Revalidation | Come `barrio_seo_changed`: `barrio_popups_changed`, `barrio_banners_changed`, `barrio_hours_changed` e `barrio_ordering_changed`, usati dal ponte dell'estensione di migrazione verso la revalidation del prototipo (tag `wp:popup`, `wp:banner`, `wp:orari`, `wp:type:{tipo}`) e, più avanti, dall'estensione di revalidation |

## 1. Regole di visualizzazione (core, per Popup e Banner)

Le regole sono del core (`src/Visibility.php`, lo script module `assets/frontend/visibility.js` e le card React in
`assets/src/admin/visibility.js`), come `I18n`: due moduli le usano, e nessuno dei due deve dipendere dall'altro
acceso.

- **Dove**: tutto il sito, solo in, o ovunque tranne. Le scelte possibili sono:
  - la home;
  - contenuti scelti;
  - tutti i contenuti di un tipo;
  - un termine (i contenuti che lo hanno e il suo archivio).
- **Chi**: dispositivo (tutti, desktop, telefoni) e utenti (tutti, solo i visitatori, solo chi ha fatto l'accesso).
- **Periodo**: inizio e fine facoltativi. Lo stato si calcola: Spento, Programmato, Attivo, Scaduto.
- **Frequenza**: chiuso, non torna per N giorni (0: torna a ogni pagina, come `days="0"` nel prototipo). Si ricorda
  in `localStorage` (`barrio_{modulo}_{id}`, la data di chiusura). Non è un cookie: non va al server e non serve
  consenso, perché ricorda solo una scelta di chi visita.
- **Più attivi insieme**, come in Popup Maker. In una pagina compaiono al più un popup e un banner: tra quelli che
  valgono per la pagina, il più recente. Una promozione con un periodo creata dopo vince su quella di sempre, senza
  un campo priorità.
- **Lingue**: come nei form, ogni lingua ha la sua riga di testi completa. Nell'editor un testo non tradotto è quello
  della lingua principale, e al salvataggio la riga si completa (`fillTranslations`). Nel sito e in GraphQL si legge
  la riga della lingua, o quella principale se la lingua non ne ha una: la stessa regola ovunque.
- **ID nella lingua principale**: le regole salvano gli ID dei contenuti e dei termini nella lingua principale. Nel
  sito l'ID della pagina si porta alla lingua principale con il filtro nuovo `barrio_main_language_id`, a cui
  risponde l'estensione WPML (`wpml_object_id`): una regola sulla pagina "Chi siamo" vale anche per "About us".
- **Nel sito**: il server valuta pagina, utente e periodo e stampa l'elemento. Il JavaScript controlla dispositivo
  (`matchMedia`), periodo e frequenza: con la cache di pagina l'HTML può essere vecchio, il browser no.
- **Niente query a ogni pagina**: gli elementi accesi e non scaduti di un modulo, con i loro testi, stanno in un
  transient senza scadenza (`barrio_popups_live`, `barrio_banners_live`) che ogni scrittura elimina; con zero
  elementi è un array vuoto. CSS e JS solo nelle pagine con un popup o un banner.
- **JSON Schema**: le regole sono una parte delle impostazioni JSON di ogni elemento (`Visibility::SCHEMA`),
  validate prima di ogni scrittura.

## 2. Popup (`popups`)

### 2.1 Cosa fa

- **Contenuto**: titolo, testo formattato, immagine e un pulsante (testo, link, nuova scheda).
- **Posizione**: al centro con lo sfondo scuro (modale), oppure in basso a destra senza sfondo (non modale, non
  prende il focus).
- **Aspetto**: colore di sfondo e del testo. Senza una scelta valgono le custom property del CSS, che il tema può
  sovrascrivere come per i form. Niente template da sovrascrivere nel tema.
- **Quando si apre**: dopo N secondi, dopo lo scroll di una percentuale della pagina, oppure all'uscita (il mouse che
  lascia la finestra, solo desktop). Si apre sempre anche con un link a `#barrio-popup-{id}` o un elemento con
  `data-barrio-popup="{id}"`, anche senza le altre regole (il trigger "clic" di Popup Maker).
- **Form**: basta lo shortcode `[barrio_form]` nel testo. Un form inviato dentro il popup (`barrio:success`) lo chiude
  dopo il messaggio e non lo ripropone più. Con Forms spento lo shortcode non stampa niente.
- **Dove, chi, periodo, frequenza, lingue**: §1.

### 2.2 Dati (migrazione `0001`)

```
popups        id, is_enabled, starts_at NULL, ends_at NULL (UTC), CHECK (ends_at > starts_at),
              image_id BIGINT UNSIGNED NULL, settings JSON (CHECK oggetto), created_at, updated_at
              INDEX (is_enabled, ends_at)
popups_i18n   (popup_id, lang) → popups CASCADE; lang utf8mb4_bin
              title VARCHAR(255) NOT NULL, content MEDIUMTEXT NULL (HTML filtrato con wp_kses_post),
              button_label VARCHAR(255) NULL, link_url VARCHAR(2048) NULL,
              CHECK (link_url REGEXP '^(/|https?://)'), CHECK (button_label IS NULL OR link_url IS NOT NULL)
```

- `settings` si legge e si scrive sempre intero e non si interroga in SQL, quindi è JSON (regola 4 di PLAN.md),
  validato con il suo schema. Contiene `position` (`center`|`corner`), `colors`, `trigger` (`delay`|`scroll`|`exit`
  con il valore), `new_tab` e le regole del §1.
- `image_id` non ha una foreign key verso `wp_posts` (regola 9). Un'immagine eliminata si ignora: il popup esce
  senza.
- Un pulsante con il link e senza testo usa il testo standard della lingua ("Scopri di più"); un testo senza link non
  si salva (CHECK).
- Ogni popup ha la riga della lingua principale: lo garantisce `PopupRepository`, come le righe di testo dei campi
  dei form (nessun vincolo SQL impone un figlio), e lo dimostra un test.
- La migrazione `0002` assegna `barrio_manage_popups` ad admin ed editor.

### 2.3 Problemi del prototipo da non ripetere

| Problema | Nel nuovo modulo |
|---|---|
| Un solo popup attivo, senza regole: compare solo dove si mette lo shortcode | Regole di pagina, dispositivo, utenti e periodo, e l'inserimento automatico in `wp_footer` |
| Il popup chiuso resta nella pagina con la sola opacità a 0: gli screen reader lo leggono al caricamento, e pulsante e link restano raggiungibili col Tab. Aperto, il focus non entra, non resta dentro e non torna | `<dialog>` nativo: `showModal()` rende inerte il resto della pagina, Esc chiude, il browser rimette il focus dove era. Nascosto finché non si apre |
| Gli editor (menu di primo livello) non hanno il JavaScript della pagina: salvare, attivare, eliminare e scegliere l'immagine non fanno niente | L'app React del plugin, una pagina per chi ha `barrio_manage_popups` |
| XSS salvato nell'admin: il titolo letto con `textContent` e riscritto con `innerHTML` | React, che non scrive HTML dai dati |
| Cambiando lingua nell'editor tornano i valori letti all'apertura: risalvando si perde la modifica appena salvata. Nei primi 600 ms la traduzione può finire nella lingua principale | I testi di tutte le lingue nello stato dell'editor e un solo Salva, come nel builder |
| Il limite di popup c'è solo nell'interfaccia | Niente limite: le regole decidono cosa compare |
| Shortcode e GraphQL usano regole diverse per le traduzioni (campo per campo nel sito, riga intera in GraphQL), per l'immagine (URL o ID) e per il testo (`wpautop` solo nel sito) | Una funzione sola (`Popups::live()`), usata dal sito e da GraphQL; righe di testo complete (§1); l'immagine è sempre un ID |
| `CREATE TABLE` e `SHOW COLUMNS` a ogni richiesta; CSS in linea in ogni pagina, anche senza popup; due query per pagina | Migrazioni del Migrator; transient e asset solo dove serve (§1) |
| `LONGTEXT NOT NULL DEFAULT ''`, nessuna foreign key tra popup e traduzioni, nessun CHECK | Lo schema del §2.2 |
| GraphQL risponde anche con il modulo spento | L'estensione registra lo schema solo con il modulo acceso |
| Due shortcode nella stessa pagina danno ID doppi e il secondo non si apre | Niente shortcode: un solo popup per pagina, inserito dal modulo |
| Testi in italiano scritti nel codice; nessuna pulizia alla disinstallazione | `__()` e `languages/`; le tabelle le elimina `Uninstall` |

### 2.4 Nel sito e admin

- **Nel sito**: il popup in `wp_footer`, uno script module senza build (come i form). Il testo si salva già in HTML
  (TinyMCE, senza `wpautop`). Nel sito passa da `do_shortcode()`; in GraphQL gli shortcode si tolgono
  (`strip_shortcodes`), perché un frontend headless non saprebbe mostrarli. Gli shortcode del prototipo
  (`[suite_barrio_popup]`, `[wpb_popup]`) li tiene l'estensione di migrazione come alias vuoti.
- **Popup** (`barrio_manage_popups`), schede Popup e Impostazioni (Permessi, `manage_options`):
  - la tabella del plugin con colonne titolo, stato, dove (le regole in breve), periodo e lingue (con più lingue).
    Filtri: stato, lingua. Nel menu ⋯: Modifica, Duplica, Accendi/Spegni, Elimina. Azioni di massa: accendi, spegni,
    elimina;
  - "Nuovo popup" nella barra.
- **Editor** (`?popup=ID`): nella barra il ritorno, il titolo, la lingua (con più lingue) e Salva.
  - A sinistra, in card: Contenuto (per lingua), Aspetto, Apertura, poi Dove, Chi e Periodo (le card del core).
  - A destra, sticky, l'anteprima: l'HTML del sito in un iframe con CSS e JS del frontend, come l'anteprima dei form
    (`POST /popups/preview`).
  - Il testo formattato è l'editor classico di WordPress (`wp.oldEditor`, TinyMCE ridotto come nel prototipo:
    grassetto, corsivo, link, elenchi), l'immagine dalla libreria dei media.
  - Nelle altre lingue si cambiano solo i testi; immagine, aspetto e regole restano visibili ma disattivati
    (`Fixed`).
- **REST** (`barrio_manage_popups`): `GET`/`POST /popups`, `GET`/`PUT`/`DELETE /popups/{id}`,
  `POST /popups/{id}/duplicate`, `POST /popups/bulk`, `POST /popups/preview`. Nessuna rotta pubblica: il sito
  stampa dal server, i frontend headless leggono GraphQL.
- **Bacheca**: card con i popup attivi ora e programmati, Gestisci e Nuovo.

### 2.5 Da Popup Maker

- **Presi**: modale e angolo; apertura dopo un tempo, allo scroll, all'uscita e al clic su un link; frequenza dopo la
  chiusura; chiusura dopo l'invio di un form; regole di pagina, dispositivo e utente; programmazione; Esc e sfondo
  che chiudono; anteprima.
- **Lasciati**:
  - statistiche di aperture e conversioni: ogni apertura è un dato in più da conservare (privacy by default);
  - test A/B;
  - temi ed editor visuale: bastano colori e custom property;
  - regole su referrer, parametri dell'URL e carrello WooCommerce: estensioni, se un sito le chiede;
  - più popup nella stessa pagina e popup dentro popup;
  - animazioni a scelta: una sola, spenta con `prefers-reduced-motion`.

## 3. Banner (`banners`)

### 3.1 Cosa fa

- **Contenuto**: una riga di testo, tutta cliccabile se ha un link (nuova scheda a scelta).
- **Posizione**: in cima alla pagina (`wp_body_open`) o fisso in fondo (`wp_footer`).
- **Scorrevole o fermo**: scorrevole è la scritta che scorre del prototipo e di `hello_standard`; fermo è la classica
  barra degli avvisi.
- **Aspetto**: colore di sfondo e del testo, con le custom property del CSS come per i popup. L'altezza è nella
  custom property `--barrio-banner-height`, per i temi con l'header fisso.
- **Chiusura**: con la X, se si sceglie; poi vale la frequenza del §1.
- **Dove, chi, periodo, frequenza, lingue**: §1.

### 3.2 Dati (migrazione `0001`)

```
banners       id, is_enabled, starts_at NULL, ends_at NULL (UTC), CHECK (ends_at > starts_at),
              settings JSON (CHECK oggetto), created_at, updated_at
              INDEX (is_enabled, ends_at)
banners_i18n  (banner_id, lang) → banners CASCADE; lang utf8mb4_bin
              text VARCHAR(255) NOT NULL, link_url VARCHAR(2048) NULL, CHECK (link_url REGEXP '^(/|https?://)')
```

- `settings` contiene `position` (`top`|`bottom`), `scroll`, `closable`, `colors`, `new_tab` e le regole del §1.
- La riga della lingua principale la garantisce `BannerRepository`, come per i popup.
- La migrazione `0002` assegna `barrio_manage_banners` ad admin ed editor.

### 3.3 Problemi del prototipo da non ripetere

| Problema | Nel nuovo modulo |
|---|---|
| Un solo banner attivo, senza regole né periodo; compare solo dove il tema stampa lo shortcode | Regole del §1 e l'inserimento automatico |
| La scritta si ripete 30 volte per gli screen reader, e il nome del link è il testo ripetuto 30 volte | Il testo si legge una volta: le copie sono `aria-hidden` |
| Scorre senza modo di fermarla (WCAG 2.2.2) e ignora `prefers-reduced-motion`; si ferma solo col mouse | Si ferma anche col focus e col tocco; resta ferma con `prefers-reduced-motion`; si può scegliere fermo |
| Salta di 20px a ogni giro (`GAP` copiato dal frontend senza lo spazio nel CSS); le copie non hanno spazio tra loro; la larghezza si misura una volta, prima dei font | Una Web Animation sulla larghezza misurata dopo il caricamento dei font (`document.fonts.ready`) e al ridimensionamento, con lo spazio nel CSS |
| XSS salvato nell'admin (`innerHTML` del titolo); il limite di banner solo nell'interfaccia; cambio di lingua che riporta i valori vecchi | Come per i popup (§2.3) |
| Due tabelle create a ogni richiesta, senza foreign key; da 2 a 4 query per pagina; CSS e JS in ogni pagina | Lo schema del §3.2; transient e asset solo dove serve (§1) |
| Un link tradotto vuoto toglie il link in GraphQL e lo tiene nel sito | Righe di testo complete, una regola sola (§1) |
| Due shortcode nella stessa pagina: ID doppi e solo il primo scorre | Niente shortcode: un solo banner per pagina |

### 3.4 Admin

- **Banner** (`barrio_manage_banners`), schede Banner e Impostazioni (Permessi):
  - la tabella del plugin con colonne testo, stato, dove, periodo e lingue. Stessi filtri, menu ⋯ e azioni di massa
    dei popup;
  - "Nuovo banner" nella barra.
- **Editor** (`?banner=ID`): una pagina corta, non una modale, perché ha le card delle regole. A sinistra Contenuto
  (per lingua) e Aspetto, poi Dove, Chi e Periodo; a destra l'anteprima del banner, scorrevole o fermo, con i suoi
  colori.
- **REST** (`barrio_manage_banners`): `GET`/`POST /banners`, `GET`/`PUT`/`DELETE /banners/{id}`,
  `POST /banners/{id}/duplicate`, `POST /banners/bulk`.
- **Bacheca**: card con i banner attivi ora e programmati.
- Gli shortcode del prototipo (`[suite_barrio_banner]`, `[wpb_banner]`) li tiene l'estensione di migrazione come alias
  vuoti.

**Da WPFront Notification Bar e Simple Banner**:
- presi: in cima o in fondo, colori, chiusura ricordata, periodo, pagine;
- lasciati: conto alla rovescia, pulsante separato dal testo, banner fisso in cima sopra l'header (dipende dal
  tema: c'è la custom property dell'altezza).

## 4. Orari (`hours`)

### 4.1 Cosa fa

- **Settimana**: per ogni giorno nessuna fascia (chiuso) o più fasce. Una chiusura prima dell'apertura vale il giorno
  dopo, per chi chiude dopo mezzanotte. "Copia su tutti i giorni".
- **Giorni speciali**: una data o un intervallo, chiuso o con fasce sue, e una nota per lingua ("Ferie", "Natale").
  Quelli passati si tolgono da soli.
- **Fonte**: manuale, oppure Google con il Place ID. Con Google settimana e giorni speciali arrivano ogni notte
  (03:00 del fuso del sito) e con "Aggiorna ora", e nell'admin si vedono senza modificarli. Per modificarli si passa
  a manuale, che parte dagli ultimi dati.
  - La settimana viene da `regularOpeningHours`.
  - I giorni speciali vengono da `currentOpeningHours.specialDays` (i prossimi 7 giorni).
- **Nel sito**: il blocco `barrio/hours` (dinamico) e lo shortcode `[barrio_hours]`, con lo stesso render. Tre forme:
  - la settimana: una tabella, con i giorni uguali raggruppati se si sceglie ("Lun–Ven");
  - oggi;
  - lo stato ("Aperto · chiude alle 19:00").

  I nomi dei giorni e il formato dell'ora seguono la lingua della pagina (`WP_Locale`, `time_format`). "Aperto ora"
  si calcola nel browser con il fuso del sito (`Intl`), quindi resta giusto con la cache di pagina.
- **Schema**: con il modulo SEO acceso, il nodo Organization ha `openingHoursSpecification`, anche per i giorni
  speciali (`validFrom`, `validThrough`). È il Local SEO di Rank Math: `Seo\Schema` lo chiede a `Hours`, se il modulo
  è acceso.

### 4.2 Dati

**Opzione `barrio_hours`** (autoload: la legge il blocco), con il suo JSON Schema:
- `source` (`manual`|`google`), `place_id`;
- `week`: `{1..7: [{open, close}]}`, giorni ISO (1 = lunedì) e ore `HH:MM`;
- `exceptions`: `[{from, to, hours, note: {lingua: testo}}]`, date `Y-m-d`, `to` non prima di `from`;
- `google`: `synced_at` (timestamp UTC), nome del luogo, ultimo errore.

È un'opzione e non una tabella: è un solo record, letto intero da ogni blocco, e i giorni speciali sono poche voci
che si potano da sole. Lo schema fa i controlli che in una tabella farebbero i CHECK (ore valide, giorni 1–7, date).

### 4.3 Problemi del prototipo da non ripetere

| Problema | Nel nuovo modulo |
|---|---|
| Solo Google: senza chiave e Place ID non c'è modo di scrivere gli orari | Manuale di default, Google a scelta |
| Chiave API in chiaro nell'opzione e nell'HTML della pagina; Place ID nell'URL senza codifica | Il service account cifrato del core; il Place ID validato e codificato |
| Nessun output in WordPress: gli orari esistono solo in GraphQL | Blocco, shortcode e schema |
| `currentOpeningHours` usato come settimana: nella settimana di Natale gli orari "normali" diventano quelli delle feste, senza dire quali giorni | Settimana da `regularOpeningHours`, eccezioni con la loro data. `hbOrari` le applica ai prossimi 7 giorni, come faceva |
| Un locale aperto 24 ore (fascia senza `close`) dà un warning PHP e una fascia sbagliata | La fascia senza chiusura vale tutto il giorno (da verificare, §10) |
| Il cron gira alle 01:00 UTC e non del sito, si programma a ogni richiesta anche senza credenziali e non si toglie mai; se Google sbaglia, nessuno lo sa | Programmato solo con la fonte Google, tolto spegnendo il modulo (`deactivate()`); l'errore resta nella pagina e nella card della Bacheca |
| Giorni salvati con i nomi italiani come chiavi; in GraphQL una tabella di traduzione per 12 lingue scritta nel codice; l'ultimo aggiornamento salvato come testo già formattato | Giorni ISO e ore `HH:MM`; i nomi dalla lingua della richiesta; il timestamp UTC, formattato solo in uscita |

### 4.4 Admin

- **Orari** (`barrio_manage_hours`), un solo Salva:
  - card Fonte (`manage_options`): manuale o Google, Place ID, Aggiorna ora, ultimo aggiornamento ed errore;
  - card Settimana: una riga per giorno, l'interruttore Aperto, le fasce;
  - card Giorni speciali: "Aggiungi" nell'intestazione, una modale per data, fasce e nota;
  - colonna destra: come appare nel sito (lo stato e la settimana) e come inserirlo (blocco o shortcode);
  - la lingua nella barra serve solo alle note;
  - scheda Impostazioni con i Permessi.
- **REST**: `GET`/`PUT /hours` (`barrio_manage_hours`; fonte e Place ID solo con `manage_options`) e
  `POST /hours/sync`.
- **Bacheca**: card con gli orari di oggi, il prossimo giorno speciale e l'errore di Google, se c'è.

**Da Rank Math Local SEO**:
- presi: orari nello schema, formato dell'ora della lingua, blocco e shortcode;
- lasciati: più sedi (un'estensione se un'agenzia ne ha bisogno) e un fuso diverso da quello del sito.

**Lasciato**: Google Business Profile API, che darebbe anche gli orari futuri ma vuole il consenso OAuth del
proprietario della scheda, non un service account.

## 5. Blocchi (`blocks`)

### 5.1 Cosa fa

- **Per ruolo** (gli amministratori hanno sempre tutto):
  - **blocchi disattivati**, per categoria, con la ricerca e "tutti/nessuno". I blocchi che esistono solo dentro un
    altro (`parent` in `block.json`: voce di elenco, colonna, pulsante) non si elencano e seguono il loro blocco;
  - **restrizioni dell'editor**:
    - niente H1 nei titoli (l'H1 è il titolo della pagina);
    - niente colori e tipografia personalizzati, restano quelli del tema;
    - niente editor del codice;
    - niente blocco e sblocco dei blocchi;
    - pannelli nascosti: revisioni, autore, attributi della pagina.
- **Per tipo di contenuto**: editor a blocchi, editor classico o nessun editor. Di default, quello che sceglie
  WordPress.
- **Per tutto il sito**: pattern dalla directory di WordPress.org e immagini di Openverse nell'editor (accesi come
  nel core). Sono richieste a servizi esterni dal browser di chi scrive.

Ognuna di queste cose è un filtro del core, tranne poche righe di JavaScript scritte a mano, senza build, come il
blocco dei form:
- `allowed_block_types_all`;
- `use_block_editor_for_post_type`;
- `block_editor_settings_all` (`codeEditingEnabled`, `canLockBlocks`, `enableOpenverseMediaCategory`);
- `should_load_remote_block_patterns`;
- i colori e la tipografia con `wp_theme_json_data_theme`, solo nell'admin;
- i pannelli con `rest_prepare_post_type`: in WordPress 7.1 Autore, Revisioni e Genitore (attributi della pagina)
  compaiono solo se il tipo li supporta, e `removeEditorPanel` non toglie i primi due. Per chi ha la restrizione la
  REST dei tipi non li elenca; il supporto resta, e con lui le revisioni;
- in JavaScript: il default di `levelOptions` nei blocchi che lo hanno senza un valore loro (il titolo, non il titolo
  del sito) e la scorciatoia `# `, che creerebbe un H1.

### 5.2 Dati

**Opzione `barrio_blocks`** (non in autoload: si legge solo nell'admin), con il suo JSON Schema:
- `roles`: `{ruolo: {disabled: [nomi], h1, styles, code, lock, panels}}`. Si salvano i blocchi disattivati, non
  quelli ammessi: un blocco nuovo (un plugin, un aggiornamento di WordPress) resta disponibile finché non lo si
  spegne;
- `editors`: `{tipo: blocks|classic|none}`, una chiave assente vale la scelta di WordPress;
- `remote_patterns`, `openverse`.

### 5.3 Problemi del prototipo da non ripetere

| Problema | Nel nuovo modulo |
|---|---|
| L'H1 diventa H2 **nel sito** secondo chi guarda (`current_user_can` del visitatore): l'amministratore vede H1, tutti gli altri H2, anche nei titoli che l'amministratore ha voluto H1 | Nel sito non si tocca niente: la restrizione toglie l'H1 dall'editor, e i titoli già salvati restano come sono |
| Aprendo un contenuto con un H1, l'editor lo cambia in H2 da solo e lo salva al primo aggiornamento | Nessuna modifica ai contenuti all'apertura |
| Colori, tipografia e Avanzate tolti con `supports` nel JavaScript: un blocco colorato o con una classe da un amministratore può risultare "contenuto inatteso" per un editor (verificato in lettura, §10) | Colori e tipografia si spengono con le impostazioni di `theme.json`, che cambiano i controlli e non i blocchi. Avanzate (ancora e classe) non si nasconde: togliere il supporto alle classi rende non validi i blocchi che ne hanno una |
| Restrizione accesa di default con 5 blocchi ammessi, `core/list` senza `core/list-item` (§10) | Niente spento di default; si salvano i blocchi disattivati, e i blocchi figli seguono il padre |
| "Editor Gutenberg" spento toglie il supporto `editor` al tipo: sparisce anche `content` dalla REST e da WPGraphQL | Il supporto si toglie solo nelle schermate di modifica (`load-post.php`, `load-post-new.php`) |
| Restrizioni uguali per tutti i non amministratori; pannelli nascosti sempre, senza scelta | Ogni restrizione si sceglie ruolo per ruolo |

### 5.4 Admin

- **Contenuti → Blocchi** (`manage_options`), un solo Salva:
  - card Blocchi e restrizioni, per il ruolo scelto nella barra in alto (`Picker`, `Tools` della sezione). Il primo
    ruolo è Amministratore, come la lingua principale: la sua card è tutta accesa e fissa, e solo con lui si cambiano
    le due card sotto, uguali per tutti i ruoli. Con gli altri ruoli quelle card sono grigie (`Fixed`) e un avviso
    dice perché, come nelle lingue diverse dalla principale;
  - card Editor per tipo di contenuto;
  - card Contenuti esterni.

  I blocchi, con titolo e categoria, li dà `GET /blocks` dal registro del server: `/wp/v2/block-types` è molto più
  pesante, e le icone dei blocchi del core sono solo nel JavaScript, quindi l'elenco non ne ha.
- **REST**: `GET`/`PUT /blocks` (`manage_options`).

**Da PublishPress Blocks e Disable Gutenberg**:
- presi: blocchi per ruolo, editor classico per tipo, niente editor di codice per i clienti;
- lasciati:
  - blocchi diversi per tipo di contenuto (il contesto di `allowed_block_types_all` lo permetterebbe: si aggiunge
    se un sito lo chiede);
  - visibilità dei blocchi nel sito per dispositivo o data: sarebbe un modulo a sé;
  - impostazioni per singolo utente: le dà già il core in Preferenze.

## 6. Ordinamento (`ordering`)

### 6.1 Cosa fa

- **Per tipo di contenuto e tassonomia** (quelli con interfaccia, allegati esclusi), l'interruttore "Ordine manuale"
  in Contenuti → Ordinamento (`manage_options`), con due card come Duplicazione.
- **Riordina** è un pulsante accanto ad Aggiungi nella lista di ogni elenco in ordine manuale (Pagine, Categorie),
  come Duplica sta tra le azioni della lista, e lo vede solo chi può riordinare. Apre una modale (lo script
  `build/barrio-reorder.js`, con gli stili delle pagine Barrio):
  - l'elenco intero trascinabile, anche da tastiera (`@dnd-kit/sortable`, come i campi del builder). Nei tipi e
    nelle tassonomie gerarchici è un albero e si sposta tra fratelli; il genitore si cambia dal contenuto;
  - Annulla e Salva in fondo; salvato, la lista si ricarica nel nuovo ordine.
- **Dove vale l'ordine**: in ogni query di un tipo in ordine manuale che non chiede un ordine, o chiede quello
  predefinito (data, decrescente): sito, admin, REST e WPGraphQL. L'ordine è `menu_order` crescente, poi la data.
  - Un ordine esplicito diverso (titolo, più vecchi, casuale, una colonna cliccata nell'admin) resta.
  - Nella ricerca vale la pertinenza.
  - Un contenuto nuovo ha `menu_order` 0, quindi va in cima finché non lo si sposta.
- **Termini**: `terms_clauses` usa il loro ordine con un LEFT JOIN quando l'ordine è `name` (il predefinito) su una
  tassonomia ordinata. I termini senza ordine vanno in fondo, per nome.

### 6.2 Dati

- **Contenuti**: `menu_order` di `wp_posts`, il campo del core, che usano già temi, menu e WPGraphQL.
- **Termini**: il meta `_barrio_order` (intero), come fa WooCommerce, perché WordPress non ha un ordine dei termini.
  È un dato del termine: segue il termine se lo si elimina, e la disinstallazione lo toglie con `Module::uninstall()`
  (il core non vede i meta).
- **Scritture**: `OrderRepository`, l'unico del modulo a usare `$wpdb`. Le posizioni sono quelle dell'elenco intero,
  con i genitori prima dei figli: anche una query senza gerarchia esce nell'ordine dell'albero.
  - Per i contenuti, un solo `UPDATE … SET menu_order = FIELD(ID, …)` per tutto l'elenco, senza toccare
    `post_modified`, poi `clean_post_cache()`. Un'istruzione sola è atomica anche dove `wp_posts` non è InnoDB
    (regola 9 di PLAN.md), quindi niente transazione.
  - Per i termini, `DELETE` e `INSERT` dei meta in una transazione: `update_term_meta()` termine per termine
    lascerebbe un ordine a metà se una scrittura fallisce.
- **Opzione `barrio_ordering`** (autoload): `post_types` e `taxonomies` in ordine manuale.
- **Migrazione `0001`**: `barrio_reorder` ai ruoli con `edit_others_posts` o `manage_categories`.

### 6.3 Problemi del prototipo da non ripetere

| Problema | Nel nuovo modulo |
|---|---|
| Salvare l'ordine usa `wp_update_post` su ogni contenuto: cambia la data di modifica di tutti (`lastmod` della sitemap, "Ultima modifica") e fa partire tutti gli hook di salvataggio | §6.2 |
| Basta `edit_posts`: un collaboratore riordina i contenuti degli altri, e l'elenco mostra bozze e privati di qualsiasi tipo, anche non abilitato | `edit_others_posts` del tipo e `edit_post` su ogni ID; solo contenuti di quel tipo e di quel genitore; per i termini `edit_terms` della tassonomia |
| Spegnere un tipo azzera `menu_order` di tutti i suoi contenuti (anche l'ordine delle pagine messo da WordPress) e cancella l'ordine dei termini | Spegnere toglie solo l'ordinamento dalle query; gli ordini restano |
| L'ordine vale solo per la query principale, anche nella ricerca e sopra un ordine scelto; quello dei termini sopra ogni `orderby` | §6.1 |
| Gerarchia appiattita; jQuery UI senza tastiera; un menu "Riordina Categorie" solo per i non amministratori | Albero tra fratelli, `@dnd-kit` accessibile, Riordina nella lista di ogni elenco per chi può riordinarlo |

### 6.4 Admin

- **REST**:
  - `GET /ordering/{post|term}/{nome}` (l'albero con ID, titolo, stato, genitore);
  - `PUT` dello stesso percorso con gli ID nell'ordine;
  - `GET`/`PUT /ordering/settings`, con i ruoli (`src/Roles.php`).
- **Contenuti → Ordinamento**: le card dei tipi e delle tassonomie e la card Permessi.
- `barrio_ordering_changed` (tipo o tassonomia) dopo ogni salvataggio.
- `// ponytail: tutto l'elenco in una richiesta e in una modale; oltre ~2000 voci per tipo servono pagine o il trascinamento nella lista nativa`.

**Da Simple Page Ordering, Post Types Order e Intuitive Custom Post Order**:
- presi: ordine dei termini, gerarchia, ordine applicato alle query senza ordine esplicito (admin compreso);
- lasciati:
  - trascinamento nella tabella nativa, che è paginata: l'elenco intero è più semplice;
  - la colonna `term_order` aggiunta a `wp_terms`: si usa il term meta, senza toccare le tabelle del core;
  - l'ordine dei contenuti dentro una singola categoria: un'estensione, se serve.

## 7. Duplicazione (`duplicate`)

### 7.1 Cosa fa

- **Duplica** compare:
  - tra le azioni di riga delle liste di contenuti e termini;
  - tra le azioni di massa delle liste di contenuti;
  - nell'editor, nel pannello dello stato: un pulsante nell'editor a blocchi (`PluginPostStatusInfo`), un link
    nell'editor classico.

  Una copia singola apre l'editor della copia; le copie di massa tornano alla lista con un avviso.
- **La copia** è una bozza dell'utente corrente, datata ora, con il titolo seguito da " (copia)" (traducibile). Si
  copiano:
  - contenuto, riassunto, genitore, ordine, commenti e ping, password;
  - tutti i meta, compresi template, immagine in evidenza, SEO e i campi ACF, copiati così come sono;
  - tutti i termini.

  Non si copiano `_edit_lock`, `_edit_last`, `_wp_old_slug`, `_wp_old_date`, `_wp_trash_meta_*` e
  `_wp_desired_post_slug`. Lo slug lo calcola WordPress.
- **I termini**: nome con " (copia)", poi " (copia 2)" se esiste già; descrizione, genitore, meta tranne
  `_barrio_order`. La copia va in fondo all'ordine.
- **Dove compare**: tutti i tipi e le tassonomie con interfaccia, allegati esclusi; si tolgono in Contenuti →
  Duplicazione (`manage_options`).

### 7.2 Dati

- Nessuna tabella: la copia passa da `wp_insert_post`, `add_post_meta`, `wp_set_object_terms` e `wp_insert_term`.
  Niente transazione, perché le tabelle del core possono non essere InnoDB (regola 9 di PLAN.md): se un passo
  fallisce, la copia a metà si elimina (`wp_delete_post`, `wp_delete_term`).
- **Opzione `barrio_duplicate`**: `post_types` e `taxonomies` spenti (una chiave assente vale acceso).
- **Migrazione `0001`**: `barrio_duplicate` ai ruoli con `edit_posts`.

### 7.3 Problemi del prototipo da non ripetere

| Problema | Nel nuovo modulo |
|---|---|
| Basta `edit_posts`: un collaboratore duplica il contenuto privato o protetto da password di un altro e lo legge nella sua bozza | `edit_post` sull'originale e `create_posts` del tipo; per i termini `edit_terms` |
| Contenuto e meta passano da `wp_insert_post` e `add_post_meta`, che tolgono le barre rovesciate: gli attributi dei blocchi (`<`, `"`) e i meta JSON si rompono | `wp_slash()` su contenuto e meta, come Yoast Duplicate Post |
| Con ACF i sotto-campi dei ripetitori si scrivono due volte (`update_field` più la copia dei meta, verificato in lettura) | Copia diretta dei meta, che per ACF è già completa: nessun codice per ACF (PLAN.md: il plugin non usa ACF) |
| La seconda copia di un termine fallisce (stesso slug `-copia`) | Nome numerato, slug unico da WordPress |
| Spento di default per ogni tipo; "— Copia" in italiano nel codice; il commento dice che apre l'editor ma torna alla lista | §7.1 |

### 7.4 Admin

- L'azione è un link con nonce a `admin-post.php` (`barrio_duplicate`), più `handle_bulk_actions-edit-{tipo}` per la
  massa.
- **Contenuti → Duplicazione**: le card dei tipi e delle tassonomie e la card Permessi; `GET`/`PUT /duplicate`
  (`manage_options`), con i ruoli (`src/Roles.php`).

**Da Yoast Duplicate Post**:
- presi: copia da riga, di massa e dall'editor; meta con `wp_slash`; titolo con un suffisso;
- lasciati:
  - Riscrivi e ripubblica: modifica di una copia che sostituisce l'originale alla pubblicazione, con unione dei meta
    e conflitti. Si aggiunge se un cliente lo chiede;
  - la scelta di cosa copiare campo per campo;
  - il link nella barra di amministrazione.

## 8. Testi alternativi (`alt`)

### 8.1 Cosa fa

- **Genera con l'AI del core** (Gemini su Vertex AI, il service account e il modello di Barrio → Impostazioni) il
  testo alternativo, e a scelta anche il titolo dell'immagine (spento). Didascalia e descrizione restano come sono.
  Senza service account e modello i pulsanti sono spenti, con il motivo.
- **Cosa va a Google**: la dimensione `large` dell'immagine (al più 1024px), o il file caricato se è più piccolo, mai
  l'originale grande. Gemini legge JPEG, PNG e WebP: AVIF (le dimensioni del modulo Prestazioni), GIF e HEIC si
  convertono in JPEG con l'editor di immagini del server, in un file temporaneo. Va anche un contesto: il nome del
  file e il titolo del contenuto a cui è allegata.

  La risposta è JSON con il suo schema (`responseSchema`, come "Genera con AI" del SEO), nella lingua principale del
  sito: alt di al più 125 caratteri, senza "immagine di". SVG e i formati che il server non apre si saltano: nella
  tabella Genera è spento, nella massa non partono.
- **Tre modi**:
  - **al caricamento** (spento di default): un evento singolo di WP-Cron dopo che WordPress ha creato le dimensioni.
    L'upload non aspetta Google. Scrive solo se l'alt è vuoto;
  - **"Genera con AI"** nei dettagli dell'allegato (finestra dei media e schermata di modifica): riempie il campo
    senza ricaricare la pagina. Si salva come ogni modifica dell'alt, quindi si vede prima;
  - **Barrio → Testi alternativi → Immagini**: la tabella del plugin con le immagini (miniatura, file, alt, contenuto
    a cui è allegata). Il filtro di partenza è "senza testo alternativo"; nel menu ⋯ Genera e Modifica aprono la
    stessa modale (Genera la apre generando), che salva con `/wp/v2/media`. Le azioni di massa e "Genera tutti" (gli
    ID da `GET /alt/images?alt=missing&fields=ids`) lavorano nel browser, un'immagine alla volta, con l'avanzamento
    e Interrompi, e si fermano al primo errore come le azioni di massa del SEO.
- **Barrio → Testi alternativi → Impostazioni**, per gli amministratori: la card Generazione e i Permessi, con il
  Salva della barra. I Permessi scelgono chi genera (la pagina e il pulsante nei dettagli); la generazione al
  caricamento vale per le immagini di chiunque.
- **Il pulsante nei dettagli**: nella finestra dei media è un campo dei plugin (`attachment_fields_to_edit`); nella
  schermata di modifica, dove quei campi stanno in una tabella stretta, si stampa dopo i campi di WordPress
  (`edit_form_after_title`), largo come Didascalia e Descrizione.
- **Nel core**: `Ai::generate()` accetta delle parti in più (`inlineData` con `mimeType` e base64) oltre al testo.
  Il resto (token, modello, istruzioni del sito, risposta JSON) c'è già.

### 8.2 Dati

- L'alt è il meta del core `_wp_attachment_image_alt`, il titolo è `post_title` dell'allegato: nessun dato nuovo.
- L'elenco "senza testo alternativo" è una `meta_query` (assente o vuoto), sull'indice `meta_key` del core.
- **Opzione `barrio_alt`**: `on_upload` (spento), `title` (spento).
- **Migrazione `0001`**: `barrio_generate_alt` ai ruoli con `upload_files`.
- **Il titolo "manca"** quando è ancora quello che WordPress dà caricando l'immagine, il nome del file (anche senza il
  `-2` di un nome già usato): solo quello si sostituisce al caricamento e nella massa.

### 8.3 Problemi del prototipo da non ripetere

| Problema | Nel nuovo modulo |
|---|---|
| Chi ha `upload_files` (gli autori) rigenera e sovrascrive alt e titolo di qualsiasi allegato | `edit_post` sull'allegato, e i Permessi per ruolo |
| Chiavi API di Anthropic e Gemini in chiaro, in autoload, nell'HTML della pagina e copiate dalla procedura guidata in altre 4–6 opzioni; il codice dei provider copiato in quattro moduli | Il service account cifrato del core e un solo client AI |
| Al caricamento la chiamata è sincrona (fino a 80 s nella richiesta di upload) e manda l'originale a piena risoluzione | WP-Cron dopo la creazione delle dimensioni; la dimensione `large` |
| Ogni percorso tranne uno sovrascrive alt e titolo anche quando ci sono; un titolo vuoto cancella quello esistente; le modifiche nella griglia di revisione non si salvano mai | Al caricamento e nella massa si scrive solo dove manca; dal pulsante si vede prima di salvare; un testo vuoto non si scrive |
| Lavoro in background fragile: ID persi se il processo muore, "esegui subito" che non fa niente, nessun errore rientra; l'opzione con tutti gli ID letta a ogni pagina dell'admin | Massa nel browser, un'immagine alla volta, sullo stato vero delle immagini: si riprende da dove si era |
| Modello predefinito sbagliato per Gemini; SVG, AVIF e HEIC mandati come JPEG; due regex che non compilano; "Totale immagini" che conta tutti i tipi e il cestino; `location.reload()` che ricarica l'editor | §8.1 |
| Immagini mandate a terzi senza dirlo; registro di ogni operazione in un'opzione, non atomico | Il readme lo dichiara (External services), e parte solo da un clic o dall'interruttore. Nessun registro: l'esito si vede nella tabella |

### 8.4 Admin

- **REST**:
  - `GET /alt/images` (`upload_files` e `barrio_generate_alt`; pagine, ricerca e filtro sul server, come le
    Registrazioni; con `fields=ids` tutte quelle trovate che chi chiede può generare);
  - `POST /alt/{id}` (le stesse e `edit_post`; genera e, con `save`, scrive);
  - `GET`/`PUT /alt/settings` (`manage_options`; con i ruoli, come Duplicazione).
- **Bacheca**: una card sua, come il SEO, con le immagini e quelle senza testo alternativo (i conteggi di
  `GET /alt/images`), i link a Immagini e Impostazioni.

**Da Alt Text AI e simili**:
- presi: generazione al caricamento, in massa e per immagine; contesto dal contenuto;
- lasciati:
  - parole chiave SEO nell'alt: l'alt serve a chi non vede l'immagine;
  - testo dal nome del file senza AI (`IMG_1234` non descrive niente);
  - crediti e servizi a pagamento di terzi.

## 9. Estensioni e hook

| Estensione | Cosa cambia |
|---|---|
| `barrio-suite-wpgraphql` | `popups.php`, `banners.php` e `hours.php`, ognuno solo con il suo modulo acceso e con priorità 9, come per form e SEO.<br>• `hbPopup(lang): HBPopup` (`id`, `title`, `content`, `link: HBPopupCta {url, title, target}`, `image: HBPopupImage {node: MediaItem}`).<br>• `hbBanner(lang): HBBanner` (`id`, `title` = il testo, `link: HBBannerCta {url, target}`).<br>• `hbOrari(lang): HBOrari` (`ultimoAggiornamento` d/m/Y H:i, `displayName`, `settimana [HBOrariGiorno {giorno, slots, chiuso}]` da lunedì, `slots` in 12 ore per l'inglese).<br>Popup e banner sono quelli di `live()` del modulo con le regole "tutto il sito" (i frontend non mandano la pagina) |
| `barrio-suite-wpml` | Il filtro `barrio_main_language_id` (`wpml_object_id`) per le regole di popup e banner. Nulla in `wpml-config.xml`: i testi di popup e banner e le note degli orari sono nel plugin |
| `barrio-suite-migration` | Un file per modulo, con il suo comando `wp barrio <modulo> migrate [--dry-run]`, e il ponte di revalidation (dettagli in §11, M9) |

Hook nuovi, solo quelli che un'estensione usa:
- `barrio_popups_loaded`, `barrio_banners_loaded`, `barrio_hours_loaded` (su `init`, priorità 5): per l'estensione
  WPGraphQL;
- `barrio_popups_changed`, `barrio_banners_changed`, `barrio_hours_changed`, `barrio_ordering_changed` (il tipo o la
  tassonomia): per il ponte di revalidation;
- `barrio_main_language_id` (filtro: l'ID di un contenuto o di un termine nella lingua principale): per l'estensione
  WPML.

Blocchi, Duplicazione e Testi alternativi non hanno hook: nessuna estensione ne ha bisogno oggi.

## 10. Da verificare durante lo sviluppo

- **Prototipo, blocchi**: con la lista di default (`core/list` ammesso, `core/list-item` no) un elenco non dovrebbe
  poter avere voci; e un paragrafo colorato da un amministratore dovrebbe risultare "contenuto inatteso" per un
  editor. Da provare sul prototipo prima della migrazione: se è vero, i siti del prototipo lo hanno già.
- **Colori e tipografia per ruolo** (verificato in M3): `wp_theme_json_data_theme` filtrato secondo l'utente
  nell'admin. Il risultato resta in `WP_Theme_JSON_Resolver` e nel gruppo di cache `theme_json`, che il core dichiara
  non persistente: nessuna scelta passa da un utente all'altro, anche con Redis. Nell'editor dei post i controlli
  seguono le impostazioni del PHP (provato con un editor e un paragrafo colorato da un amministratore).
- **Blocchi registrati solo in JavaScript**: non compaiono in `/wp/v2/block-types`, quindi non si possono
  disattivare. Con una restrizione attiva `allowed_block_types_all` dà una lista e li nasconderebbe: vanno contati
  sui siti dei clienti.
- **Ordine e blocco Query Loop**: il blocco manda sempre `orderby` (data, decrescente). Con la regola del §6.1
  l'ordine manuale vale anche lì; provare che negli altri ordini resti la scelta del blocco.
- **Ordine dei termini in WPGraphQL e in admin**: verificare che WPGraphQL chieda `name` di default (allora l'ordine
  vale, come serve a `hello_standard`) e che la lista gerarchica di `edit-tags.php` resti corretta. Con WPML ogni
  lingua ha i suoi termini, quindi si riordina una lingua alla volta: decidere se copiare l'ordine alle traduzioni.
- **Google Places**: token del service account con `X-Goog-User-Project`; come Places (New) rappresenta un luogo
  aperto 24 ore; `specialDays` con le fasce di quei giorni; il costo per una richiesta al giorno.
- **Nomi dei giorni in `hbOrari`**: con `switch_to_locale()` servono i pacchetti di lingua installati. Una lingua
  chiesta dal frontend senza pacchetto avrebbe i giorni in inglese, mentre il prototipo aveva 12 lingue scritte a
  mano.
- **`<dialog>`**: ritorno del focus alla chiusura e scroll della pagina bloccato in Safari; il popup in angolo
  (`show()`, non modale) non deve rubare il focus.
- **TinyMCE in React** (`wp.oldEditor.initialize`): cambio di lingua senza perdere il testo, distruzione allo smontaggio.
- **Banner e temi classici**: serve `wp_body_open`, che i temi chiamano dal 2019; controllare quelli dei clienti. Sui
  siti classici del prototipo con lo shortcode del banner in un punto preciso dell'header il banner si sposta in
  cima.
- **Cache di pagina**: un popup o un banner creato o spento si vede solo quando la cache della pagina scade. Il
  JavaScript rispetta il periodo, ma non può far comparire un elemento che l'HTML non ha.
- **Gemini con le immagini**: quali modelli dell'elenco accettano immagini (il filtro attuale tiene ogni `gemini-*`);
  tempi per 500 immagini in massa.
- **Lingua dell'alt con WPML**: con la traduzione dei media ogni lingua ha il suo allegato, che dovrebbe avere l'alt
  nella sua lingua; serve il filtro della lingua di un contenuto già previsto in PLAN-SEO.md §8.

## 11. Fasi

Ogni fase si apre dai dati e si chiude con:
- test verdi: PHPUnit su MariaDB 10.5 e MySQL 8.0 (ogni FK, CHECK e UNIQUE dimostrati, il JSON Schema di ogni
  opzione e colonna JSON), PHPStan, PHPCS, ESLint, `node --test`;
- un plugin installabile;
- lo stesso commit su `con-updater` e `senza-updater`;
- le traduzioni rigenerate (PLAN.md §8).

Dai moduli più piccoli ai più grandi: i primi costruiscono la pagina Contenuti, il Banner costruisce le regole del core
che il Popup riusa.

| # | Fase | Pronta quando |
|---|---|---|
| M1 | **Duplicazione** e la pagina **Contenuti** con le schede dei moduli accesi | Copia di pagina, articolo, CPT e categoria con:<br>• blocchi con attributi JSON intatti (confronto del `post_content`);<br>• meta serializzati e JSON intatti, ACF compreso;<br>• la seconda copia di un termine riuscita.<br>Un collaboratore non duplica il contenuto privato di un altro |
| M2 | **Ordinamento**: `OrderRepository`, impostazioni, albero trascinabile, query di contenuti e termini | La data di modifica non cambia riordinando; una scrittura fallita non lascia un ordine a metà. L'ordine vale:<br>• nell'archivio, nell'admin, in REST e nel Query Loop;<br>• non nella ricerca né con un ordine esplicito.<br>Spegnere non cancella niente. Un autore non riordina |
| M3 | **Blocchi**: per ruolo, per tipo, contenuti esterni | Un editor:<br>• non trova i blocchi disattivati, ma un elenco ha ancora le sue voci;<br>• non sceglie H1;<br>• apre un contenuto colorato da un amministratore senza "contenuto inatteso".<br>Con "nessun editor" `content` resta in REST. Senza scelte l'editor è quello di WordPress |
| M4 | **Testi alternativi** e `Ai::generate()` con le immagini | Alt generato al caricamento (dopo l'upload), dal pulsante e in massa, con Google simulato (`pre_http_request`): l'immagine mandata è la `large`; un alt presente non si tocca; un autore non genera su un allegato altrui |
| M5 | **Orari**: opzione e schema, manuale, Google, blocco e shortcode, schema SEO, card | Una settimana con una chiusura dopo mezzanotte e un giorno speciale si vede giusta nel blocco e nello stato "aperto ora" con la cache di pagina; lo schema passa il Rich Results Test; la sincronizzazione Google simulata scrive settimana ed eccezioni, e un errore arriva nella Bacheca. Lo schema JSON rifiuta ore, giorni e date non validi |
| M6 | **Banner** e le **regole del core** (`Visibility`): migrazioni, `BannerRepository`, editor, sito, card | Ogni vincolo delle tabelle dimostrato; la riga della lingua principale sempre presente. Un banner in due lingue compare solo dove e quando deve, anche con la cache di pagina; scorre e si ferma col focus, resta fermo con `prefers-reduced-motion`, lo screen reader legge il testo una volta; chiuso, non torna per N giorni. Con il modulo spento nessuna query e nessun asset |
| M7 | **Popup**: migrazioni, `PopupRepository`, editor con anteprima, sito, card | Ogni vincolo delle tabelle dimostrato. Il popup compare solo dove e quando deve, si apre da tastiera e da un link, si chiude con Esc e rimette il focus, non ritorna per N giorni; un form inviato nel popup lo chiude. Controllo con lo screen reader (VoiceOver) e a 360px |
| M8 | **GraphQL** in `barrio-suite-wpgraphql` | Lo snapshot dello schema di `hbPopup`, `hbBanner` e `hbOrari` coincide con il prototipo; `hello_standard` (`getPopup`, `getBanner`, `getOrari`) funziona senza modifiche contro wp-env |
| M9 | **Migrazione dal prototipo** in `barrio-suite-migration` | Su wp-env con il prototipo e i suoi dati: prova a vuoto e migrazione con gli stessi conteggi; popup, banner, orari e GraphQL uguali prima e dopo, tranne le correzioni previste |

**Migrazione (M9)**: come per il SEO, il comando si può rilanciare e lascia com'è ciò che Barrio Suite ha già.

- **Popup**: `wpb_popups` e `wpb_popups_translations` diventano righe di `popups` e `popups_i18n`, in una
  transazione per popup.
  - Quello attivo resta acceso, con "tutto il sito", apertura dopo 2 secondi e 7 giorni (i valori di default del
    prototipo); gli altri restano spenti.
  - `image_url` senza `image_id` diventa un ID con `attachment_url_to_postid()`; il testo passa da `wpautop`.
  - Un campo tradotto vuoto prende quello della lingua principale, come le righe complete del §1.
- **Banner**: `wpb_banners` e `wpb_banners_translations` diventano `banners` e `banners_i18n`. Il titolo diventa il
  testo; quello attivo resta acceso, scorrevole e in cima.
- **Shortcode**: il comando elenca i contenuti con gli shortcode del prototipo e i loro attributi, per rifare le
  regole a mano dove serve.
- **Orari**: il Place ID passa e la fonte diventa Google; la settimana parte dagli ultimi dati salvati, con
  `synced_at`. La chiave API non passa, con un avviso se manca il service account.
- **Blocchi**: con la restrizione accesa, per ogni ruolo tranne gli amministratori:
  - i blocchi disattivati sono quelli registrati meno quelli ammessi (figli esclusi);
  - H1, colori e tipografia e i pannelli diventano restrizioni accese, come si comportava il prototipo.

  I tipi senza "editor Gutenberg" diventano "nessun editor".
- **Ordinamento**: i tipi e le tassonomie abilitati; il meta `suite_barrio_term_order` diventa `_barrio_order`.
  `menu_order` è già del core.
- **Duplicazione**: i tipi e le tassonomie non abilitati nel prototipo diventano spenti, così il sito resta com'era.
- **Testi alternativi**: `auto_upload` diventa `on_upload`. Chiavi, modello, tono, stile e registro non passano, con
  un avviso: Barrio Suite usa il service account e le istruzioni del sito dell'AI.
- **Prima della migrazione**: ogni modulo di Barrio Suite resta spento finché quello del prototipo è acceso, come il
  SEO (`barrio_migration_<modulo>` = `held`).
- **Alla fine**: il modulo del prototipo si spegne e quello di Barrio Suite si accende. Le tabelle `wpb_popups*` e
  `wpb_banners*` del prototipo si eliminano a mano dopo la verifica, come quelle dei form.
- **Ponte di revalidation**: `barrio_popups_changed` (`wp:popup`), `barrio_banners_changed` (`wp:banner`),
  `barrio_hours_changed` (`wp:orari`), `barrio_ordering_changed` (`wp:type:{tipo}`).
