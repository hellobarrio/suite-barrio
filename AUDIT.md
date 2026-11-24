# Audit di Barrio Suite

Data: 20 novembre 2026. Codice esaminato: branch `con-updater`, commit `719e555`, con le modifiche non committate di quel giorno (28 file). Perimetro: tutto il repository, cioè core, 11 moduli, 5 estensioni, test, traduzioni e CI: circa 18.000 righe di PHP, 21.000 tra JavaScript e CSS e 13.600 di test.

Questo file si legge da solo. Ogni voce ha un id, l'area, la prova con file e riga, la modifica più piccola che conserva il comportamento, il rischio e l'impegno. Nessun file di codice è stato cambiato. I tre audit precedenti (`docs/audit.md`, `docs/audit-seo.md`, `docs/audit-moduli.md`) restano come storia: qui c'è solo ciò che è aperto oggi, compresi i loro punti non ancora chiusi.

## Esito delle correzioni (24 novembre 2026)

Le voci di §1–§5 sono state corrette sul branch `con-updater` e portate su `senza-updater`, tranne quelle di questa tabella. Lint e test sono verdi: 73 test JS e 566 test PHP, anche con WPGraphQL e in multisite; le voci di interfaccia sono state guardate nel browser sul sito dei test (25 pagine admin a 1400 e 360 px, le modali toccate, banner, popup e form nel sito). Le funzioni suggerite del §6 non sono state scritte: sono proposte, non difetti.

| Id | Stato | Cosa resta |
|---|---|---|
| X-2 | Fatto | Commit sui due branch e push su `origin` (`stable` e `senza-updater`); l'esito della CI su GitHub va guardato lì |
| CORE-12 | Non toccato | I sorgenti restano nello zip. Il repository è pubblico, quindi per WordPress.org il link nel readme dovrebbe bastare: è una scelta |
| CONT-12 | Non toccato | Non è una modifica: i blocchi registrati solo in JavaScript vanno contati sui siti dei clienti prima di usare il modulo |
| PBH-11 | Non toccato | L'audit stesso lo rimanda a quando si rimette mano all'editor di popup e banner |
| CONT-4 | Solo il testo | Il filtro `wp_content_img_tag` che riempie un `alt` vuoto resta da decidere (non distingue un'immagine decorativa) |
| FORMS-12 | Solo (a) | Il visitatore con la pagina vecchia legge "ricarica la pagina"; tenere la versione sostituita, (b), resta da decidere |
| EXT-5 | Testi e uscita anticipata | Il caricamento dello script al primo `focusin` va provato con una chiave vera, perché può abbassare i punteggi |
| CORE-1, CORE-2 | Fatto, da provare | "Aggiorna" usa `bulk_upgrade()` (copia della versione di prima, il plugin resta attivo) e il giro notturno è `wp_maybe_auto_update()`: la prima release vera va provata su un sito di prova |
| PBH-4 | Fatto | La forma di `currentOpeningHours` è stata guardata su Places (20 luoghi aperti 24 ore: un solo periodo di 7 giorni, troncato); un giorno speciale dentro il periodo lungo è provato solo dal test |
| CONT-11 | Fatto, da provare | Il filtro `barrio_ordering_items` e l'estensione WPML sono provati con i filtri di WPML simulati, non con WPML |
| EXT-3 | Fatto, da controllare | L'utente dell'application password di ogni frontend headless deve avere `edit_posts`, altrimenti il limite per IP vale per tutto il sito |
| UI-4 | Fatto, da guardare | Il bordo di campi e bottoni è `--barrio-border` (`#949494`): è la modifica che si vede di più |
| LIVE-4 | Fatto, da provare a mano | Sotto i 1100 px il clic su un campo porta al pannello delle proprietà |
| TEST-1, TEST-2, TEST-3 | Fatto | Provati in locale (WordPress 6.9, PHP 8.4, multisite); su GitHub girano dal primo push |

Differenze dalla "Modifica minima" scritta qui sotto: UI-6 annuncia il conteggio con `speak()` a scrittura ferma, non con `role="status"`; PBH-3 crea il bottone di pausa in `banner.js`, così esiste solo mentre la scritta scorre; SEO-8 taglia il titolo a 255 caratteri invece di rifiutarlo; EXT-4 ha solo il transient; UI-2 tiene il colore del plugin, con un'eccezione a stylelint per `--wpds-color-stroke-focus`.

## In breve

Il codice è sano. Lint e test sono verdi (ESLint, stylelint, PHPCS, PHPStan livello 8; 64 test JS e 544 test PHP, con e senza WPGraphQL), non ci sono vulnerabilità gravi e quasi niente codice morto: nessuna classe CSS inutilizzata, nessun metodo senza utilizzatori. Quasi tutti i punti dei tre audit precedenti sono chiusi.

Le voci sono 110: 72 quick win, 12 da correggere con più calma, 12 semplificazioni e 14 miglioramenti. Le prime quattro, nell'ordine:

1. **X-2**: 13 commit di `con-updater`, 37 di `senza-updater` e 28 file modificati esistono solo su questo computer.
2. **TEST-1**: al primo push la CI diventa rossa, perché il job dei test non costruisce `build/` (riprodotto).
3. **SEO-1**: con il modulo SEO acceso, un indirizzo con byte non UTF-8 come `/caf%E9` risponde 500 invece di 404, a chiunque lo chieda (riprodotto). Tre righe.
4. **FADM-1**: nel builder dei form, scrivere il valore di una scelta può sovrascrivere l'etichetta di un'altra, senza avviso (riprodotto). Quattro righe.

**Cosa togliere o semplificare.** Non c'è un taglio grosso: il codice è già asciutto e le dipendenze sono solo quattro, tutte di sviluppo. Restano duplicati piccoli, che insieme valgono circa 210 righe nette: il bottone Salva scritto nove volte (X-1, −70), la scheda Settings scritta cinque volte (UI-13, −43), le tre voci di Contenuti (CONT-15, −32), le dichiarazioni ripetute di `admin.css` (UI-12, −30), il controllo dell'inizio che non può scattare (PBH-8, −12) e alcuni ritocchi (FADM-8, CORE-10, EXT-10, PBH-10). Popup e Banner, che l'audit di stamattina trovava scritti due volte, sono stati messi in comune per il grosso: resta l'editor (PBH-11), che conviene unire solo quando ci si rimette mano.

**Sicurezza.** Niente di grave. Sei punti piccoli: titoli di contenuti privati mostrati agli autori (CONT-6), il `seo` di GraphQL sulle bozze altrui (EXT-2, da provare), `hbSubmitForm` senza limite per chi ha fatto login (EXT-3), l'attivazione di un'estensione con la sola `install_plugins` (CORE-7), i segreti che non si possono togliere (CORE-8), gli argomenti senza lunghezza massima di "Genera con AI" (SEO-8).

**Prestazioni.** Sulle pagine del sito non è emerso niente di nuovo. Restano i due carichi già noti sui siti grandi (SEO-6, EXT-4) e alcune richieste inutili nell'admin (UI-14, CONT-5, FADM-11, EXT-9).

**Interfaccia e accessibilità.** I componenti comuni sono in buono stato: nel browser nessuna pagina esce in orizzontale e axe trova una sola violazione del plugin su 34 pagine. Le voci che pesano di più: il focus che finisce sotto la barra o fuori dalla modale dell'anteprima (LIVE-1, LIVE-2), dati scritti che si perdono senza avviso (UI-7, PBH-2), Salva acceso senza modifiche (CONT-2, CONT-10, UI-1), messaggi d'errore tecnici in inglese (FADM-2, PBH-5, FORMS-10), interruttori con lo stesso nome per gli screen reader (UI-3), il contrasto dei bordi dei campi (UI-4), il banner che non si può fermare (PBH-3).

**Presupposti.** Una decina di frasi dei piani, della documentazione o dei commenti non corrisponde più a ciò che il codice fa (§5). Sono quasi tutte da correggere nel testo, non nel codice.

## Come usare questo file

Per chi riprende il lavoro in una nuova chat, senza questa conversazione:

- **Ordine**: §1 dall'alto (gravità alta, poi media, poi bassa), poi §2. Le voci di §3 e §4 si possono fare quando si tocca quell'area. Ogni voce è indipendente dalle altre, salvo dove dice "vedi".
- **Prima di toccare una voce**: riaprire le righe citate, perché i numeri di riga valgono per l'albero del 20 novembre 2026. Le voci marcate "non verificato" o "da provare" vanno prima riprodotte.
- **Regole del progetto da rispettare** (da PLAN.md, PLAN-SEO.md, PLAN-MODULI.md, PLAN-GRAPHQL.md):
  - si lavora su `con-updater` e ogni correzione si porta su `senza-updater` con un cherry-pick; tra i due branch cambia solo l'updater (`docs/release.md`);
  - la versione resta `0.1.0` in tutti i plugin;
  - i servizi e i plugin di terzi stanno nelle estensioni, non nel core (fanno eccezione il service account di Google e l'AI);
  - lo schema GraphQL tiene i nomi `hb*` del prototipo e riceve solo campi in più, fino alla release;
  - il database cambia solo con migrazioni numerate, e solo i Repository usano `$wpdb`;
  - nell'admin ogni tabella usa `Table`, ogni modale `Dialog`, ogni pagina ha una `Bar` con Salva attivo solo con modifiche;
  - le stringhe sono in inglese con `__()` e la traduzione italiana si rigenera quando cambiano (PLAN.md §8, con `wp i18n update-po`);
  - i commenti nel codice sono in italiano e solo dove spiegano un perché.
- **Controlli dopo ogni modifica**: `npm run lint:js`, `npm run lint:css`, `npm run test:js`, `npm run lint:php`, `npm run test:php`, `npm run test:php:graphql`. Per le voci di interfaccia, guardare la pagina nel browser sul sito dei test (porta 8889), modali comprese.

## Indice per priorità

| # | Id | Voce | Area | Gravità | Rischio | Impegno |
|---|---|---|---|---|---|---|
| | | **1. Quick win** | | | | |
| 1 | X-2 | Il lavoro degli ultimi giorni esiste solo su questo computer | repository | alta | nessuno | S |
| 2 | TEST-1 | Nella CI il job `test` non costruisce `build/`: un test di Ordinamento fallisce su tutte le combinazioni | CI | alta | basso | S |
| 3 | SEO-1 | Un URL con byte non UTF-8 fa rispondere 500 invece di 404 | SEO, redirect | alta | basso | S |
| 4 | FADM-1 | Nel builder, scrivere il valore di una scelta sovrascrive l'etichetta di un'altra | Forms, builder (React) | alta | basso | S |
| 5 | CONT-6 | La pagina Testi alternativi mostra a un autore il titolo di contenuti che non può leggere | Testi alternativi, REST | media | basso | S |
| 6 | FORMS-1 | Un invio che supera `post_max_size` viene respinto come spam | Forms, invio pubblico | media | basso | S |
| 7 | SEO-2 | Una pagina nuova pubblicata su un indirizzo che ha già un redirect automatico resta irraggiungibile | SEO, redirect automatici | media | basso | S |
| 8 | PBH-2 | Scegliere la fonte Google sostituisce gli orari scritti a mano, senza avviso | Orari | media | basso | S |
| 9 | UI-7 | Messa online: cambiando passaggio senza salvare, il primo "Continua" cancella i campi scritti | admin, Messa online | media | basso | S |
| 10 | SEO-5 | SEO → Contenuti: cambiare tipo durante un salvataggio o "Genera con AI" fa cadere la pagina | SEO, tabella Contenuti | media | basso | S |
| 11 | EXT-1 | Statistiche, Orari e Ordinamento trattenuti spenti dalla migrazione non si riaccendono se il prototipo non ha dati | estensione di migrazione | media | basso | S |
| 12 | TEST-2 | Un test di ExtensionsTest passa solo se Barrio Suite sta nella cartella dei plugin: nella CI non ci sta | CI | media | basso | S |
| 13 | CONT-1 | "Genera tutti" si ferma per sempre su un'immagine che Google blocca, e non dice quale | Testi alternativi, generazione di massa | media | basso | S |
| 14 | PBH-1 | Chi clicca il pulsante del popup lo ritrova nella pagina di arrivo | Popup, script del sito | media | basso | S |
| 15 | CONT-2 | Riordina: su un elenco gerarchico Salva è acceso e Annulla chiede conferma senza aver toccato niente | Ordinamento, modale Riordina | media | basso | S |
| 16 | UI-1 | Dopo il salvataggio dei Moduli il browser chiede "modifiche non salvate" | admin, Barrio → Impostazioni | media | basso | S |
| 17 | UI-8 | Il menu "Barrio" compare a chiunque abbia `read` e porta a una Bacheca vuota | core, menu admin | media | basso | S |
| 18 | LIVE-1 | Tornando indietro con Shift+Tab il controllo con il focus finisce sotto la barra sticky | admin, tutte le pagine con la barra | media | basso | S |
| 19 | LIVE-2 | Anteprima del form: con Tab il focus esce dalla modale, e Esc non la chiude | Forms, builder | media | basso | S |
| 20 | CONT-3 | "Niente H1": incollando un testo con un H1, o scrivendo `/h1` e Invio, l'H1 si crea lo stesso | Blocchi, script dell'editor | media | basso | S |
| 21 | CONT-4 | L'alt generato va solo nel meta dell'allegato, ma la pagina dice "Every image has its alt text" | Testi alternativi | media | basso | S |
| 22 | UI-3 | Nei Permessi e in Indicizzazione gli interruttori di righe diverse hanno lo stesso nome | admin, `Group` e card SEO | media | basso | S |
| 23 | UI-2 | `--wp-admin-theme-color` su `:root` non ha effetto con lo schema di default di WordPress 7.1, e con "Fresh" ricolora schermate non di Barrio | admin, CSS | media | basso | S |
| 24 | FORMS-2 | Il limite per IP non è "10 ogni 10 minuti": ogni tentativo fa ripartire i 10 minuti | Forms, antispam | bassa | basso | S |
| 25 | FORMS-3 | Un campo nascosto preso dall'URL, oltre 255 caratteri, blocca l'invio senza mostrare nulla | Forms, validazione | bassa | basso | S |
| 26 | FORMS-4 | Un campo con chiave `dataset` rompe il form | Forms, script del sito | bassa | basso | S |
| 27 | FORMS-5 | Con la pagina di ringraziamento il form torna inviabile mentre il browser naviga | Forms, script del sito | bassa | basso | S |
| 28 | FORMS-6 | Multistep: tutti i passaggi sono visibili finché non parte il JavaScript | Forms, rendering | bassa | basso | S |
| 29 | FORMS-8 | Una scelta il cui valore ha spazi ai bordi o doppi non si può mai inviare | Forms, validazione | bassa | basso | S |
| 30 | FORMS-9 | PHP e JS normalizzano i valori in due modi, senza fixture comuni | Forms, logica condizionale | bassa | basso | S |
| 31 | FORMS-13 | Durante l'invio del form non c'è alcun messaggio | Forms, script del sito | bassa | basso | S |
| 32 | FADM-3 | Tendine che mostrano "—" mentre resta salvato un valore non più in elenco | Forms, builder (condizioni e integrazioni) | bassa | basso | S |
| 33 | FADM-6 | Due frasi con un numero non usano `_n` | Forms (elenco dei form) e Messa online | bassa | basso | S |
| 34 | FADM-10 | Cambiare scheda nel builder dimentica il campo scelto | Forms, builder | bassa | basso | S |
| 35 | FADM-11 | Registrazioni: 250 ms di attesa prima di ogni richiesta, non solo durante la ricerca | Forms, registrazioni | bassa | basso | S |
| 36 | FADM-12 | Un preset sostituisce tutte le scelte senza conferma | Forms, builder | bassa | basso | S |
| 37 | SEO-3 | La query string della richiesta finisce dopo il `#` della destinazione | SEO, redirect | bassa | basso | S |
| 38 | SEO-4 | Il controllo dei cicli non vede la destinazione "sorgente + query string" | SEO, redirect | bassa | basso | S |
| 39 | SEO-7 | llms.txt elenca le voci di tutti i menu, anche non assegnati e verso pagine non indicizzate | SEO, llms.txt | bassa | basso | S |
| 40 | SEO-8 | "Genera con AI": `title` e `url` senza lunghezza massima | SEO, REST | bassa | basso | S |
| 41 | SEO-10 | "N controlli da migliorare" scorre la pagina ma non sposta il focus | SEO, pannello dell'editor | bassa | basso | S |
| 42 | SEO-12 | Due tipi di organizzazione non esistono in schema.org | SEO, schema dell'organizzazione | bassa | basso | S |
| 43 | PBH-5 | Un link o un'ora non validi tornano all'utente come espressione regolare | Popup, Banner, Orari | bassa | basso | S |
| 44 | PBH-6 | "Attivo" anche per il popup o il banner che il sito non mostra | Popup e Banner, elenco e Bacheca | bassa | basso | S |
| 45 | PBH-7 | Selezionare del testo nel popup e rilasciare il mouse fuori lo chiude | Popup, script del sito | bassa | basso | S |
| 46 | PBH-12 | In un tema senza `wp_body_open` il banner non compare, e niente lo dice | Banner | bassa | basso | S |
| 47 | PBH-13 | Se la prima anteprima fallisce, il popup resta con la rotella e gli Orari con una card vuota | Popup e Orari, admin | bassa | basso | S |
| 48 | CONT-5 | Google viene chiamato anche quando non c'è niente da scrivere | Testi alternativi | bassa | basso | S |
| 49 | CONT-7 | Ogni maniglia di trascinamento si chiama solo "Move" | admin, `Sortable` (Riordina, builder dei form) | bassa | basso | S |
| 50 | CONT-8 | Pagine più viste: il link va all'host registrato da GA, che non si vede mai | Statistiche | bassa | basso | S |
| 51 | CONT-9 | Blocchi: cambiando un editor si perdono le scelte dei tipi che in quel momento non esistono | Blocchi, pagina Contenuti | bassa | basso | S |
| 52 | CONT-10 | Ordinamento e Duplicazione: spegnere e riaccendere un interruttore lascia Salva acceso | pagina Contenuti | bassa | basso | S |
| 53 | UI-6 | `Table`: il numero di risultati cambia senza annuncio | admin, `Table` | bassa | basso | S |
| 54 | UI-9 | Estensioni: il testo rimanda a "Forms → Settings" anche con il modulo Form spento | admin, pagina Estensioni | bassa | basso | S |
| 55 | UI-10 | Google/AI: se Google non risponde, "Loading from Google…" resta per sempre | admin, card Google | bassa | basso | S |
| 56 | UI-11 | Gli avvisi `info` non hanno uno stile | admin, CSS | bassa | basso | S |
| 57 | UI-14 | `wp_enqueue_media()` parte su ogni `tab=settings` | core, admin | bassa | basso | S |
| 58 | LIVE-5 | Chiusa l'anteprima del form, il focus riparte dall'inizio della pagina | Forms, builder | bassa | basso | S |
| 59 | LIVE-7 | L'anteprima JSON-LD dell'organizzazione non si scorre da tastiera | SEO, Organizzazione | bassa | basso | S |
| 60 | LIVE-9 | Bacheca sul telefono: quattro descrizioni dei moduli sono tagliate | admin, Bacheca | bassa | basso | S |
| 61 | LIVE-10 | Messa online: "fatto" e "saltato" dei passaggi sono solo un segno | admin, Messa online | bassa | basso | S |
| 62 | LIVE-11 | Permessi sul telefono: i due interruttori di ogni ruolo stanno uno sopra l'altro a 8 px | admin, CSS | bassa | basso | S |
| 63 | CORE-4 | Con il modulo Orari spento `[barrio_hours]` resta come testo nelle pagine | core, shortcode | bassa | basso | S |
| 64 | CORE-5 | Una migrazione che fallisce si riesegue a ogni richiesta, in fila dietro il lock | core, `Migrator` | bassa | basso | S |
| 65 | CORE-6 | `barrio_disabled_modules` nasce solo all'attivazione | core, `Modules` | bassa | basso | S |
| 66 | CORE-7 | `install` attiva l'estensione controllando solo `install_plugins` | core, `Extensions` | bassa | basso | S |
| 67 | EXT-5 | reCAPTCHA: lo script di Google finisce in tutte le pagine quando il popup contiene un form | estensione reCAPTCHA | bassa | basso | S |
| 68 | EXT-6 | reCAPTCHA: l'avviso "manca il service account" compare anche per impostazioni rimaste dal vecchio disegno | estensione reCAPTCHA | bassa | basso | S |
| 69 | EXT-7 | reCAPTCHA: due stringhe non sono nel `.pot` né nella traduzione italiana | estensione reCAPTCHA | bassa | basso | S |
| 70 | EXT-8 | La migrazione lascia i form con Mailchimp a "iscrivi senza conferma" e non lo dice | estensione di migrazione | bassa | basso | S |
| 71 | EXT-9 | Estensione di migrazione: opzioni di stato lette dal database a ogni richiesta | estensione di migrazione | bassa | basso | S |
| 72 | TEST-5 | Un test senza asserzioni non fa fallire PHPUnit | test, configurazione | bassa | basso | S |
| | | **2. Da correggere prima dell'uso reale** | | | | |
| 73 | CORE-1 | "Aggiorna" di Barrio sovrascrive il plugin senza la copia temporanea di WordPress | core, updater da GitHub (solo `con-updater`) | media | medio | M |
| 74 | CORE-2 | L'aggiornamento notturno salta `WP_Automatic_Updater::run()`: niente email, esito ignorato | core, updater da GitHub (solo `con-updater`) | media | medio | M |
| 75 | PBH-4 | Fonte Google: un giorno speciale dentro un periodo di più giorni diventa "Chiuso" | Orari, sincronizzazione con Google Places | media | medio | M |
| 76 | PBH-3 | Il banner con un link non si può fermare in modo stabile | Banner, script del sito | media | medio | M |
| 77 | SEO-6 | SEO → Contenuti manda il testo intero di ogni contenuto; in errore la scheda resta senza il selettore del tipo | SEO, REST e tabella Contenuti | media | basso | S |
| 78 | EXT-2 | GraphQL: `seo` e `hbSchemaRaw` leggono `$post->ID`, che su un nodo "restricted" è null | estensione WPGraphQL, SEO | bassa | basso | S |
| 79 | EXT-3 | `hbSubmitForm`: nessun limite di invii per qualsiasi utente collegato | estensione WPGraphQL, form | bassa | medio | S |
| 80 | EXT-4 | `hbSitemap` è pubblico, senza cache, e rilegge tutti gli URL a ogni richiesta | estensione WPGraphQL, SEO | bassa | basso | S |
| 81 | FORMS-7 | Un'estensione di file che WordPress non ammette si salva senza avviso e sul sito sparisce | Forms, campo file | bassa | medio | S |
| 82 | FORMS-12 | La versione appena sostituita si elimina se non ha registrazioni | Forms, versioni e cache di pagina | bassa | basso | S |
| 83 | CONT-11 | Con WPML la modale Riordina elenca insieme le voci di tutte le lingue | Ordinamento | bassa | medio | M |
| 84 | CONT-12 | Con un solo blocco spento, il ruolo perde tutti i blocchi registrati solo in JavaScript | Blocchi | bassa | medio | S |
| | | **3. Togliere o semplificare** | | | | |
| 85 | X-1 | Il bottone Salva è scritto nove volte uguale | admin React, tutte le pagine | media | basso | S |
| 86 | UI-13 | `index.js`: la scheda "Settings" di un modulo è scritta cinque volte uguale | admin, registro delle pagine | media | basso | S |
| 87 | CONT-15 | `Contents.js` scrive tre volte la stessa voce | admin, pagina Contenuti | bassa | basso | S |
| 88 | UI-12 | `admin.css`: dichiarazioni ripetute e colori senza variabile | admin, CSS | bassa | basso | M |
| 89 | PBH-8 | Il controllo dell'inizio nel browser non può mai scattare | Popup e Banner, script del sito | bassa | basso | S |
| 90 | FADM-8 | Piccoli duplicati nell'admin dei form | Forms, admin React | bassa | basso | S |
| 91 | CORE-10 | `Admin::contents_tab()` ha un parametro che riceve sempre lo stesso valore | core, menu admin | bassa | basso | S |
| 92 | EXT-10 | Estensione di migrazione: tre regole scritte due volte | estensione di migrazione | bassa | basso | S |
| 93 | PBH-10 | `created_at` e `updated_at` di popup e banner si scrivono e non si leggono mai | Popup e Banner, Repository | bassa | basso | S |
| 94 | FADM-9 | I testi per lingua di tutta la suite stanno nel builder dei form | admin React, core e moduli | bassa | basso | S |
| 95 | PBH-11 | Popup e Banner: cosa resta doppio dopo `PeriodRepository` e `PeriodList` | Popup e Banner | bassa | medio | M |
| 96 | CORE-12 | Lo zip contiene i sorgenti JavaScript accanto alla build | pacchetto | bassa | basso | S |
| | | **4. Miglioramenti** | | | | |
| 97 | UI-4 | Contrasto: bordo dei campi e interruttore spento a 1,74:1 | admin, CSS | media | medio | S |
| 98 | TEST-3 | La CI prova solo PHP 8.2, l'ultimo WordPress e il sito singolo | CI | media | medio | M |
| 99 | FADM-2 | Il builder non controlla niente prima di salvare: gli errori sono i messaggi tecnici della REST | Forms, builder | media | basso | M |
| 100 | LIVE-4 | Builder sotto i 1100 px: scelto un campo, le sue proprietà restano fuori schermo | Forms, builder | media | medio | S |
| 101 | FORMS-10 | Errori di salvataggio del form in inglese fisso, con `&#039;` visibile | Forms, REST | bassa | basso | M |
| 102 | FADM-4 | Nel builder togliere un campo o una scelta fa perdere il focus | Forms, builder | bassa | basso | M |
| 103 | FADM-5 | L'iframe dell'anteprima ha un `sandbox` che non isola | Forms, builder | bassa | medio | S |
| 104 | FADM-7 | "Modifiche non salvate" scatta anche senza modifiche, nelle traduzioni | Forms, builder | bassa | basso | S |
| 105 | FADM-13 | Logica pura del builder senza test | Forms, test JS | bassa | basso | M |
| 106 | CONT-14 | Il tetto di 5 MB verso Google e gli errori che la massa salta non hanno un test | Testi alternativi, test | bassa | basso | S |
| 107 | UI-5 | `admin.css` senza `forced-colors` e `prefers-reduced-motion` | admin, CSS | bassa | basso | S |
| 108 | UI-15 | 10 `!important` e copie di valori interni dei componenti di WordPress | admin, CSS | bassa | basso | S |
| 109 | CORE-8 | Un segreto salvato non si può più togliere: si può solo sostituire | core, Google e SMTP | bassa | basso | M |
| 110 | TEST-9 | Un quarto del tempo dei test è l'attesa di un lock | test | bassa | basso | S |

## 1. Quick win

Impegno S (meno di 30 minuti l'uno) e rischio basso. Prima i difetti, poi i ritocchi.

### Gravità alta

#### X-2 · Il lavoro degli ultimi giorni esiste solo su questo computer

- **Area**: repository · rischio di perdita
- **Prova**: `git rev-list --count origin/stable..con-updater` → 13; `git rev-list --count origin/senza-updater..senza-updater` → 37; `git status` → 28 file modificati e non committati su `con-updater` (reCAPTCHA sul service account, Statistiche in tre parti, piani). I tre audit precedenti lo segnalano ogni volta (`docs/audit.md` §4, `audit-seo.md` e `audit-moduli.md` "In breve").
- **Effetto**: un guasto del disco, o un altro zip caricato sopra la cartella montata (è già successo due volte il 17 novembre), cancella le fasi M1–M10 e le correzioni degli audit.
- **Modifica minima**: commit delle modifiche in corso e push dei due branch. Prima del push va corretta la CI (TEST-1), altrimenti il primo giro è rosso.
- **Rischio**: nessuno · **Impegno**: S · **Verifica**: comandi `git` eseguiti il 20 novembre 2026.

#### TEST-1 · Nella CI il job `test` non costruisce `build/`: un test di Ordinamento fallisce su tutte le combinazioni

- **Area**: CI · test
- **Prova**: i passi del job `test` sono checkout, PHP, `composer install`, download di WordPress e `vendor/bin/phpunit` ([ci.yml:70-87](.github/workflows/ci.yml#L70-L87)); `npm run build` c'è solo nel job `js`. `build/` non è nel repository (`git ls-files build` → 0 file). [ordering/Module.php:83](modules/ordering/src/Module.php#L83) esce senza caricare lo script se manca `build/barrio-reorder.asset.php`, e [OrderingTest.php:406-415](tests/Integration/OrderingTest.php#L406-L415) confronta allora con una stringa vuota.
- **Effetto**: al primo push di `con-updater` le quattro combinazioni di `test` diventano rosse e restano rosse: una regressione vera non si distingue più. In locale il test passa perché `build/` esiste.
- **Modifica minima**: nel job `test`, prima di PHPUnit, gli stessi passi del job `js`: `actions/setup-node@v4`, `npm ci`, `npm run build` (+6).
- **Rischio**: basso, tocca solo la CI · **Impegno**: S
- **Verifica**: riprodotto nel container dei test su una copia del repository senza `build/`: `OrderingTest::test_reorder_is_in_the_list_for_who_can_reorder_it` fallisce con `Failed asserting that '' contains ""list":"post\/page","title":"Reorder Pages""` (9 test, 1 fallito). L'esito su GitHub non è verificato.

#### SEO-1 · Un URL con byte non UTF-8 fa rispondere 500 invece di 404

- **Area**: SEO, redirect · difetto raggiungibile da chiunque
- **Prova**: a ogni richiesta del sito, senza try/catch: `$redirect = self::match( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) );` ([Redirects.php:27](modules/seo/src/Redirects.php#L27)). Il percorso si decodifica byte per byte (`rawurldecode`, [riga 67](modules/seo/src/Redirects.php#L67)): `/caf%E9` diventa una stringa non UTF-8, che entra in `SELECT * FROM %i WHERE source = %s` ([RedirectRepository.php:26](modules/seo/src/Repository/RedirectRepository.php#L26)). `wpdb` rifiuta la query ("…contains invalid data", `wp-includes/class-wpdb.php:2243-2258`) e `Db::checked()` lancia `RuntimeException` ([Db.php:92-93](src/Database/Db.php#L92-L93)).
- **Effetto**: `GET /caf%E9` o `/%ff` (un vecchio link in Latin-1, uno scanner) → pagina "errore critico" con stato 500 e una riga nel log, invece del 404. Search Console li segna come errori del server. Con il modulo SEO spento la stessa richiesta dà 404.
- **Modifica minima**: in `Redirects::match()`, dopo aver calcolato `$source`: `if ( ! wp_is_valid_utf8( $source ) ) { return null; }` (la funzione esiste dalla 6.9, `wp-includes/utf8.php:39`), +3, più un caso nel test dei redirect. In alternativa un try/catch di `RuntimeException` in `run()` (+4), che copre anche una tabella mancante.
- **Rischio**: basso. Un percorso non UTF-8 non può essere la sorgente di un redirect salvato.
- **Impegno**: S
- **Verifica**: riprodotto sul sito di prova (porta 8889) con `curl`: `/caf%E9` → 500 "critical error", `/%ff` → 500, `/caf%C3%A9` → 404, `/pagina-che-non-esiste` → 404.

#### FADM-1 · Nel builder, scrivere il valore di una scelta sovrascrive l'etichetta di un'altra

- **Area**: Forms, builder (React) · difetto
- **Prova**: [Fields.js:609-613](modules/forms/assets/src/builder/Fields.js#L609-L613) rilegge le righe dal campo a ogni tasto (`const rows = choiceRows( field, main );`) e le riscrive con `withChoices`. [model.js:38-40](modules/forms/assets/src/builder/model.js#L38-L40) salva le etichette per valore (`Object.fromEntries( rows.map( ( row ) => [ row.value, row.label ] ) )`): due righe con lo stesso valore hanno una sola etichetta, l'ultima, e [model.js:26-30](modules/forms/assets/src/builder/model.js#L26-L30) la rilegge per entrambe. Il valore uguale capita mentre si scrive ([Fields.js:708-710](modules/forms/assets/src/builder/Fields.js#L708-L710)).
- **Effetto**: scelte `1` "Pessimo", `2` "Scarso" e una terza "Ottimo" a cui si scrive il valore `10`: al primo tasto (`1`) la prima scelta prende l'etichetta "Ottimo" e la tiene. Lo stesso con "No" e "No, grazie". Nessun avviso: il form si salva con l'etichetta sbagliata, che il sito e le registrazioni mostrano.
- **Modifica minima**: in `Choices` tenere le righe in uno stato locale e derivarne il campo: `const [ rows, setRows ] = useState( () => choiceRows( field, main ) );` e `set = ( next ) => { setRows( next ); onChange( withChoices( field, main, next ) ); }` (+4 / −2). `FieldPanel` ha già una `key` per campo. Aggiungere il caso a `tests/js/builder.test.js` (+8).
- **Rischio**: basso. Cambia solo da dove `Choices` legge le righe.
- **Impegno**: S
- **Verifica**: riprodotto con `node` sulle funzioni vere di `model.js`: dopo i tasti `1` e `0` il risultato è `[{"value":"1","label":"Ottimo"},{"value":"2","label":"Scarso"},{"value":"10","label":"Ottimo"}]`. Non provato nel browser.

### Gravità media

#### CONT-6 · La pagina Testi alternativi mostra a un autore il titolo di contenuti che non può leggere

- **Area**: Testi alternativi, REST · sicurezza
- **Prova**: [alt/Module.php:236](modules/alt/src/Module.php#L236): `'parent' => $post->post_parent ? get_the_title( $post->post_parent ) : ''` per ogni immagine, senza guardare chi chiede. La rotta chiede solo `upload_files` e `barrio_generate_alt` ([Module.php:191-193](modules/alt/src/Module.php#L191-L193)), che la migrazione dà a ogni ruolo con `upload_files`, autori compresi ([0001_capabilities.php:6-10](modules/alt/migrations/0001_capabilities.php#L6-L10)). Il core nella stessa colonna controlla `edit_post`/`read_post` e altrimenti scrive "(Private post)" (`wp-admin/includes/class-wp-media-list-table.php:621-627`, WordPress 7.1.4).
- **Effetto**: un autore legge nella colonna "Attached to" il titolo di una pagina privata o di un tipo che il suo ruolo non vede.
- **Modifica minima**: `current_user_can( 'read_post', $post->post_parent ) ? get_the_title( … ) : ''` (+1 / −1) e un caso in `AltTest` (+5).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga, nel plugin e nel core.

#### FORMS-1 · Un invio che supera `post_max_size` viene respinto come spam

- **Area**: Forms, invio pubblico · UX
- **Prova**: `$input = (array) ( $request->get_json_params() ?: $request->get_body_params() );` ([RestPublic.php:87](modules/forms/src/Api/RestPublic.php#L87)): se PHP scarta il corpo, `$input` è `[]`. Allora `'1' !== self::scalar( $input['_barrio_js'] ?? null )` è vero e la risposta è `barrio_spam`, "Your submission could not be accepted." ([Submission.php:145-146](modules/forms/src/Submission/Submission.php#L145-L146)), contata nel limite per IP ([54-57](modules/forms/src/Submission/Submission.php#L54-L57)). Il limite controllato è solo per singolo file (`min( $limit, wp_max_upload_size() )`, [FileStore.php:90](modules/forms/src/Submission/FileStore.php#L90)); `grep -rnE "post_max_size|CONTENT_LENGTH" modules/forms/src` non trova niente.
- **Effetto**: form con due campi file e server con `upload_max_filesize = post_max_size = 8M`: due file da 5 MB passano il controllo per file, il corpo è di 10 MB, PHP svuota `$_POST` e `$_FILES`. Il visitatore legge il messaggio dell'antispam e ogni tentativo consuma uno dei suoi 10 invii.
- **Modifica minima**: in `RestPublic::submit()`: se `$input` e i file sono vuoti e `$_SERVER['CONTENT_LENGTH']` supera `wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) )`, un `WP_Error` 413 con un messaggio sui file troppo grandi, senza passare dall'antispam (+5).
- **Rischio**: basso; il ramo scatta solo quando PHP ha già scartato il corpo · **Impegno**: S · **Verifica**: letto riga per riga; non riprodotto (il comportamento di PHP oltre `post_max_size` è citato a memoria).

#### SEO-2 · Una pagina nuova pubblicata su un indirizzo che ha già un redirect automatico resta irraggiungibile

- **Area**: SEO, redirect automatici · difetto
- **Prova**: `moved()` lavora solo sugli ID presenti prima e dopo (`array_intersect_key( $before, $after )`, [Redirects.php:124-125](modules/seo/src/Redirects.php#L124-L125)), ed è l'unico punto che toglie il redirect che parte dal nuovo URL ([130-133](modules/seo/src/Redirects.php#L130-L133)). Per una bozza `post_urls()` dà `[]` ([riga 150](modules/seo/src/Redirects.php#L150)): alla pubblicazione `$before` è vuoto e non succede niente. Il redirect gira su `template_redirect` a priorità 0 ([seo/Module.php:45](modules/seo/src/Module.php#L45)).
- **Effetto**: "Servizi" (`/servizi/`) rinominata `servizi-2024` crea `/servizi → /servizi-2024/`. Più tardi si pubblica una nuova pagina "Servizi" con lo slug `servizi`: `/servizi/` risponde 301 verso la vecchia. Un editor non può rimediare (`barrio_manage_seo` è degli amministratori).
- **Modifica minima**: in `after_post()`, per gli URL in `$after` che non erano in `$before`, eliminare il redirect `is_auto` che parte da lì e lanciare `barrio_seo_changed` (+7 / −1), più un caso nel test.
- **Rischio**: basso; tocca solo i redirect automatici, quelli a mano restano · **Impegno**: S · **Verifica**: letto riga per riga; non eseguito.

#### PBH-2 · Scegliere la fonte Google sostituisce gli orari scritti a mano, senza avviso

- **Area**: Orari · UX, perdita di dati
- **Prova**: al salvataggio con la fonte appena passata a Google parte `self::sync()` ([hours/Module.php:313-315](modules/hours/src/Module.php#L313-L315)), che scrive sopra settimana e giorni speciali: `$settings = array_replace( $settings, $google );` ([Module.php:363](modules/hours/src/Module.php#L363)). L'opzione ha un solo `week` e un solo `exceptions`. La card Fonte spiega solo il verso opposto e il `Select` non chiede conferma ([Hours.js:457-473](modules/hours/assets/src/Hours.js#L457-L473)).
- **Effetto**: chi prova "Google" su un sito con settimana, dieci giorni speciali e note in due lingue li perde al Salva; tornare a "manuale" riparte dai dati di Google.
- **Modifica minima**: un `help` sotto il `Select` quando si passa da manuale a Google: "The hours and the special days written by hand are replaced by those of Google." (+4, più la traduzione). Niente PHP.
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### UI-7 · Messa online: cambiando passaggio senza salvare, il primo "Continua" cancella i campi scritti

- **Area**: admin, Messa online · UX, perdita di ciò che si è scritto
- **Prova**: ogni passaggio dell'elenco è un bottone con `onClick={ () => go( id ) }` ([Launch.js:375-381](assets/src/launch/Launch.js#L375-L381)); `go()` cambia solo `current`, senza guardare `dirty` ([243-247](assets/src/launch/Launch.js#L243-L247)); `dirty` riguarda solo il passaggio aperto ([233-237](assets/src/launch/Launch.js#L233-L237)); `advance()` chiama `.then( load )` sulla promessa, e `load()` riscrive le bozze di tutti i passaggi dai valori salvati ([200-212](assets/src/launch/Launch.js#L200-L212)).
- **Effetto**: nel passaggio SMTP si scrivono host, utente e password, si clicca "SEO" nell'elenco e si preme "Continue": la bozza SMTP torna ai valori salvati senza avviso.
- **Modifica minima**: nel bottone dell'elenco, con `dirty`, chiedere conferma prima di `go( id )` e riportare la bozza del passaggio al valore salvato (+7 / −1, una stringa).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga; non riprodotto nel browser.

#### SEO-5 · SEO → Contenuti: cambiare tipo durante un salvataggio o "Genera con AI" fa cadere la pagina

- **Area**: SEO, tabella Contenuti · difetto UI
- **Prova**: dopo ogni risposta `setData( ( now ) => ( { ...now, rows: now.rows.map( … ) } ) )` ([Contents.js:211-219](modules/seo/assets/src/seo/Contents.js#L211-L219)); il Picker del tipo, sempre attivo, fa `setData( null )` ([446-455](modules/seo/assets/src/seo/Contents.js#L446-L455)). Nessun error boundary (`grep -rn "ErrorBoundary\|componentDidCatch" assets/src modules` non trova niente).
- **Effetto**: 20 pagine selezionate, "Generate with AI"; mentre lavora si sceglie "Articoli": alla risposta successiva l'updater legge `null.rows`, React smonta l'app (pagina vuota) e il ciclo continua a chiamare Google.
- **Modifica minima**: `( now ) => now && { … }` e fermare il ciclo di `fill()` se il tipo è cambiato (un `useRef`), +5 / −1.
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga; non riprodotto nel browser.

#### EXT-1 · Statistiche, Orari e Ordinamento trattenuti spenti dalla migrazione non si riaccendono se il prototipo non ha dati

- **Area**: estensione di migrazione · UX
- **Prova**: all'attivazione l'estensione spegne il modulo ogni volta che quello del prototipo è acceso, senza guardare se ha dati ([barrio-suite-migration.php:72-75](extensions/barrio-suite-migration/barrio-suite-migration.php#L72-L75)). Il comando di tre moduli esce con `barrio_migration_no_prototype` se il prototipo non ha mai salvato niente ([hours.php:25-27](extensions/barrio-suite-migration/hours.php#L25-L27), [ordering.php:15-17](extensions/barrio-suite-migration/ordering.php#L15-L17), [analytics.php:14-16](extensions/barrio-suite-migration/analytics.php#L14-L16)), e con l'errore lo scambio non avviene (`if ( $error || $dry_run ) { return $error ?? $report; }`, [riga 195-197](extensions/barrio-suite-migration/barrio-suite-migration.php#L195-L197)). Blocchi, Duplicazione e Testi alternativi gestiscono già il caso ([blocks.php:10](extensions/barrio-suite-migration/blocks.php#L10)).
- **Effetto**: su un sito con il prototipo attivo e le Statistiche mai configurate, il modulo di Barrio Suite si spegne, `wp barrio analytics migrate` risponde "There are no analytics settings…" e l'avviso in admin resta per sempre. L'unica uscita è riaccendere il modulo a mano, e niente lo dice.
- **Modifica minima**: in `barrio_migration_module_run()`, se l'errore è `barrio_migration_no_prototype` e il modulo del prototipo è acceso, scrivere una nota "niente da migrare" e proseguire con lo scambio (+5). Il test `ModulesMigrationTest.php:334-340` va aggiornato.
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga (l'agente ha letto anche il prototipo); non eseguito.

#### TEST-2 · Un test di ExtensionsTest passa solo se Barrio Suite sta nella cartella dei plugin: nella CI non ci sta

- **Area**: CI · test
- **Prova**: `$this->assertTrue( $this->auto_update( true )->get_data()['auto_update'] );` ([ExtensionsTest.php:158](tests/Integration/ExtensionsTest.php#L158)). `Extensions` accende gli aggiornamenti automatici solo per i plugin trovati da `installed()`, che scorre `get_plugins()`, cioè `WP_PLUGIN_DIR`. In wp-env il repository è montato in `wp-content/plugins/barrio-suite`; nella CI il checkout resta nella cartella di lavoro e WordPress è in `/tmp/wordpress`, con il plugin caricato da `tests/bootstrap.php` con un `require`.
- **Effetto**: `test_the_settings_page_shows_the_version_and_switches_automatic_updates` fallisce nella CI ed è verde in locale.
- **Modifica minima**: in `ci.yml`, dopo il download di WordPress, copiare il checkout in `$WP_CORE_DIR/wp-content/plugins/barrio-suite` e lanciare PHPUnit da lì (`working-directory`), +3.
- **Rischio**: basso, solo la CI · **Impegno**: S · **Verifica**: letto riga per riga; non riprodotto (nel container dei test la cartella dei plugin contiene già il repository).

#### CONT-1 · "Genera tutti" si ferma per sempre su un'immagine che Google blocca, e non dice quale

- **Area**: Testi alternativi, generazione di massa · UX
- **Prova**: gli errori che fanno saltare un'immagine sono quattro ([Alt.js:59-64](modules/alt/assets/src/Alt.js#L59-L64)); gli altri fermano tutto: `if ( ! SKIP.includes( error?.code ) ) { throw error; }` ([Alt.js:380-384](modules/alt/assets/src/Alt.js#L380-L384)). Quando Google non dà testo l'errore è `barrio_ai_empty`, e il commento dice il caso: "Bloccata dai filtri di sicurezza o troncata" ([Ai.php:198-203](src/Ai.php#L198-L203)). È un errore di quella sola immagine e si ripete a ogni tentativo; l'avviso non riporta ID né nome ([Alt.js:408-409](modules/alt/assets/src/Alt.js#L408-L409)).
- **Effetto**: una foto bloccata dal filtro di Gemini ferma la massa; al tentativo dopo è la prima della lista e la ferma subito. È il §1.1 di `docs/audit-moduli.md`, corretto per file mancante, peso e formato ma non per questa causa.
- **Modifica minima**: `'barrio_ai_empty'` in `SKIP`, e l'ID dell'immagine nel messaggio del `catch` esterno (+4 / −1, più la traduzione). Per un modello che non risponde mai: fermarsi dopo 3 salti di fila (+3).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga; il blocco di Gemini non è provato con Google vero.

#### PBH-1 · Chi clicca il pulsante del popup lo ritrova nella pagina di arrivo

- **Area**: Popup, script del sito · UX
- **Prova**: la chiusura si ricorda solo sull'evento `close` ([popup.js:70-75](modules/popups/assets/frontend/popup.js#L70-L75): `if ( ! sent && rules.days ) { remember( rules.key, Date.now() ); }`). Il pulsante è un link semplice ([popups/Module.php:88-93](modules/popups/src/Module.php#L88-L93)) e nessun listener lo guarda: il clic naviga senza `close`, e nella pagina dopo `setTimeout( show, DELAY )` ([popup.js:83](modules/popups/assets/frontend/popup.js#L83)) lo riapre.
- **Effetto**: popup "Black Friday" con il pulsante verso `/black-friday/`: dopo il clic il popup copre la pagina appena chiesta, e così a ogni pagina finché non si chiude con la X.
- **Modifica minima**: ricordare la chiusura anche al clic su un link del popup: `dialog.addEventListener( 'click', ( event ) => { if ( event.target.closest( 'a[href]' ) && rules.days ) { remember( rules.key, Date.now() ); } } );` (+5).
- **Rischio**: basso; con `days = 0` resta com'è · **Impegno**: S · **Verifica**: letto riga per riga; non provato nel browser.

#### CONT-2 · Riordina: su un elenco gerarchico Salva è acceso e Annulla chiede conferma senza aver toccato niente

- **Area**: Ordinamento, modale Riordina · difetto UI
- **Prova**: [reorder.js:85-87](modules/ordering/assets/src/reorder.js#L85-L87) confronta `flatten( tree ).join()` (genitori prima dei figli, [tree.js:11-15](modules/ordering/assets/src/tree.js#L11-L15)) con l'ordine piatto della REST, che è `ORDER BY menu_order, post_date DESC` ([OrderRepository.php:28](modules/ordering/src/OrderRepository.php#L28)). Con le pagine a `menu_order` 0 una figlia creata dopo la madre esce prima di lei: i due ordini sono diversi già all'apertura.
- **Effetto**: Pagine mai riordinate, con una sottopagina: Salva è acceso, Annulla ed Esc chiedono "Discard the changes?", il browser avvisa. Contro la regola "Salva attivo solo con modifiche".
- **Modifica minima**: confrontare con l'albero al caricamento: `flatten( tree ).join() !== flatten( toTree( saved ) ).join()` (+1 / −1), più un caso in `tests/js/ordering.test.js` (+4).
- **Rischio**: basso · **Impegno**: S · **Verifica**: riprodotto con `node` su `tree.js`: `saved 12,11,10`, `flatten 11,10,12`, `dirty true`. Modale non aperta nel browser.

#### UI-1 · Dopo il salvataggio dei Moduli il browser chiede "modifiche non salvate"

- **Area**: admin, Barrio → Impostazioni · UX
- **Prova**: `useUnsavedWarning( changed.length > 0 );` ([General.js:198](assets/src/general/General.js#L198)). Dopo il salvataggio riuscito di una sezione con `reload`: `window.location.reload(); return;` ([General.js:211-214](assets/src/general/General.js#L211-L214)), prima che `data` e `draft` si aggiornino: `changed.length > 0` è ancora vero e `beforeunload` è ancora registrato.
- **Effetto**: si accende o spegne un modulo e si preme Salva: il salvataggio riesce e subito il browser chiede se ricaricare. Con "Annulla" la pagina resta con il menu vecchio e Salva attivo.
- **Modifica minima**: `useUnsavedWarning( changed.length > 0 && ! busy );` (1 riga).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga; non riprodotto nel browser.

#### UI-8 · Il menu "Barrio" compare a chiunque abbia `read` e porta a una Bacheca vuota

- **Area**: core, menu admin · UX
- **Prova**: `add_menu_page( 'Barrio Suite', 'Barrio', 'read', … )` e la Bacheca con `'read'` ([Admin.php:27-28](src/Admin.php#L27-L28)). Ogni card della Bacheca torna `null` senza la capability del suo modulo, e non c'è un testo per il caso vuoto ([Dashboard.js:33-50](assets/src/dashboard/Dashboard.js#L33-L50)). Alla pagina arrivano comunque il bundle e `window.barrioAdmin` con la versione e i tipi di contenuto.
- **Effetto**: su un sito con utenti registrati un Sottoscrittore o un Collaboratore vede "Barrio" nel menu e una pagina con solo il titolo; nel sorgente legge la versione del plugin e i nomi dei tipi di contenuto.
- **Modifica minima**: in `Admin::menu_end()`, se sotto "barrio" c'è solo la Bacheca, togliere la voce: `if ( count( $GLOBALS['submenu'][ self::PAGE ] ?? [] ) < 2 ) { remove_menu_page( self::PAGE ); }` (+4).
- **Rischio**: basso; da guardare nel browser con quattro ruoli (Sottoscrittore, Collaboratore, Autore, Editor): gli ultimi due devono tenere il menu · **Impegno**: S · **Verifica**: letto riga per riga da due agenti; non provato con un Sottoscrittore.

#### LIVE-1 · Tornando indietro con Shift+Tab il controllo con il focus finisce sotto la barra sticky

- **Area**: admin, tutte le pagine con la barra · accessibilità
- **Prova**: la barra è `position: sticky; z-index: 20; top: var(--barrio-top);` ([admin.css:1078-1082](assets/src/admin/admin.css#L1078-L1082)) e niente dice al browser di lasciare quello spazio quando porta in vista l'elemento con il focus. L'unica regola del genere è `.barrio-scroll-target { scroll-margin: … }` ([admin.css:1756-1758](assets/src/admin/admin.css#L1756-L1758)), usata solo da tre componenti del builder.
- **Effetto**: in Barrio → Impostazioni, Shift+Tab da "Banners" a "Popups": l'interruttore è coperto dalla barra. Lo stesso in SEO → Impostazioni, Orari, Contenuti → Ordinamento, Form → Impostazioni, a 1400 e a 360 px. È il criterio WCAG 2.2 2.4.11. In avanti con Tab non succede.
- **Modifica minima**: `html:has(.barrio-bar) { scroll-padding-top: var(--barrio-below-bar); }` in `admin.css` (+4).
- **Rischio**: basso; cambia solo dove si ferma lo scorrimento automatico · **Impegno**: S
- **Verifica**: riprodotto nel browser (Chrome, sito di prova) su sei pagine a due larghezze; nello screenshot dell'agente si vede solo il bordo dell'interruttore sotto la barra. La correzione è stata provata iniettando la regola: i controlli coperti scendono a 0.

#### LIVE-2 · Anteprima del form: con Tab il focus esce dalla modale, e Esc non la chiude

- **Area**: Forms, builder · accessibilità
- **Prova**: il `Dialog` dell'anteprima non ha `footer` ([Builder.js:308-317](modules/forms/assets/src/builder/Builder.js#L308-L317)) e il contenuto è un `<iframe>` ([338-344](modules/forms/assets/src/builder/Builder.js#L338-L344)): i tasti premuti dentro l'iframe non arrivano alla modale, e dopo l'iframe non c'è niente su cui il focus possa fermarsi.
- **Effetto**: Tab 1 è "Close", dal 2 al 7 i campi del form, l'8 è `body`, il 9 "Skip to main content" della pagina sotto. La modale resta aperta e Esc non la chiude più. Vale anche per l'anteprima delle email.
- **Modifica minima**: un `footer` con un bottone "Close", come le altre modali, e in `onLoad` un ascolto di Esc nel documento dell'iframe che chiama `setPreview( null )` (+14 / −1).
- **Rischio**: basso · **Impegno**: S
- **Verifica**: riprodotto nel browser a 1400 e 360 px; le due correzioni provate modificando il DOM della pagina aperta.

#### CONT-3 · "Niente H1": incollando un testo con un H1, o scrivendo `/h1` e Invio, l'H1 si crea lo stesso

- **Area**: Blocchi, script dell'editor · presupposto
- **Prova**: [no-h1.js:22-30](modules/blocks/assets/editor/no-h1.js#L22-L30) toglie solo la scorciatoia `# ` (`filter( ( { prefix } ) => '#' !== prefix )`). Il blocco Titolo del core ha altre due trasformazioni che creano un H1: `type: "raw"` con `selector: "h1,h2,h3,h4,h5,h6"` (l'incolla) e `type: "enter"` con ``regExp: new RegExp(`^/(h|H)${level}$`)`` (`wp-includes/js/dist/block-library.js:33254-33304`, WordPress 7.1.4).
- **Effetto**: un editor con "No heading 1" incolla da Word un testo con un "Titolo 1": nel contenuto c'è un secondo H1, e il selettore del livello non lo mostra.
- **Modifica minima**: in `no-h1.js` togliere anche la trasformazione `enter` di `/h1` e portare a 2 il livello 1 di `raw` (+9 / −3); aggiornare PLAN-MODULI §5.1.
- **Rischio**: basso, tocca solo `core/heading` per chi ha la restrizione · **Impegno**: S · **Verifica**: letto nel plugin e nel core; non provato nell'editor.

#### CONT-4 · L'alt generato va solo nel meta dell'allegato, ma la pagina dice "Every image has its alt text"

- **Area**: Testi alternativi · UX, presupposto
- **Prova**: l'unica scrittura è `update_post_meta( $id, self::META, … )` ([alt/Module.php:390-392](modules/alt/src/Module.php#L390-L392)); `grep -rn "post_content\|render_block\|the_content\|wp_content_img_tag" modules/alt` non trova niente. Nel core l'alt del blocco Immagine sta nel markup del contenuto. La pagina però conclude `'Every image has its alt text.'` ([Alt.js:428-431](modules/alt/assets/src/Alt.js#L428-L431)).
- **Effetto**: su un sito con 2.000 immagini già negli articoli, dopo "Generate all missing" ogni `<img>` inserita prima ha ancora `alt=""`. Cambiano solo le immagini stampate dal meta (immagine in evidenza, `wp_get_attachment_image()`) e quelle inserite da ora in poi.
- **Modifica minima**: dirlo nella card Generazione e nel readme (2 stringhe più la traduzione). Da decidere a parte: un filtro `wp_content_img_tag` che riempie un `alt` vuoto dal meta (~8 righe), che però non distingue un `alt=""` voluto (immagine decorativa).
- **Rischio**: basso per il testo, medio per il filtro · **Impegno**: S (testo), M (filtro) · **Verifica**: letto riga per riga.

#### UI-3 · Nei Permessi e in Indicizzazione gli interruttori di righe diverse hanno lo stesso nome

- **Area**: admin, `Group` e card SEO · accessibilità
- **Prova**: `Group` è un `<div>` con uno `<span>` per l'etichetta, senza `role` né `aria-labelledby` ([ui.js:250-258](assets/src/admin/ui.js#L250-L258)). In `Permissions`, con più capability, ogni ruolo è un `Group` con un `Toggle` per capability, etichettato solo con il nome della capability ([ui.js:498-509](assets/src/admin/ui.js#L498-L509)). Stesso difetto in SEO → Indicizzazione: ogni riga ha due `Toggle` con etichette fisse, "Show in search results" e "Follow the links" ([seo/Settings.js:96-107](modules/seo/assets/src/seo/Settings.js#L96-L107)).
- **Effetto**: uno screen reader legge "Create and edit forms, interruttore" una volta per ruolo, senza dire quale: è la card che dà i permessi, e quella accanto decide cosa Google indicizza. Misurato nel browser sul sito di prova: 5 interruttori "Create and edit forms" e 5 "See and manage entries" in Form → Impostazioni; 6 "Show in search results" e 6 "Follow the links" in SEO → Impostazioni; in Orari 9 "Opens", 9 "Closes" e 9 "Remove" senza il giorno ([Hours.js:162](modules/hours/assets/src/Hours.js#L162)); in Estensioni 5 "Deactivate".
- **Modifica minima**: in `Group`, `const id = useId();`, `role="group" aria-labelledby={ id }` sul `<div>` e `id` sullo `<span>` (+4 / −1); in `Settings.js:96`, `role="group" aria-label={ label }` sul `.barrio-checks` (1 riga); in `Hours.js:162`, `role="group" aria-label={ name }` (+2). Per i bottoni ripetuti ("Remove field", "Deactivate", "Actions") il nome dell'elemento con `sprintf`, come fanno già le tabelle (+10 / −5, quattro stringhe nuove; per le maniglie vedi CONT-7).
- **Rischio**: basso, solo attributi · **Impegno**: S per `Group`, M con i bottoni · **Verifica**: letto riga per riga e misurato nel browser (nomi accessibili doppi); non provato con uno screen reader.

#### UI-2 · `--wp-admin-theme-color` su `:root` non ha effetto con lo schema di default di WordPress 7.1, e con "Fresh" ricolora schermate non di Barrio

- **Area**: admin, CSS · presupposto
- **Prova**: [admin.css:3](assets/src/admin/admin.css#L3) dice "Variabili su :root (il foglio c'è solo nelle pagine di Barrio)" e le righe [33-36](assets/src/admin/admin.css#L33-L36) impostano `--wp-admin-theme-color: #1d2327;`. Ma il foglio si carica anche fuori da Barrio: `Admin::style()` è chiamato da [ordering/Module.php:96](modules/ordering/src/Module.php#L96) (lista di un tipo) e da [analytics/Module.php:325](modules/analytics/src/Module.php#L325) (Bacheca di WordPress). E il core dichiara la stessa variabile su `body`, più vicino di `:root`: `body.admin-color-modern { --wp-admin-theme-color: #3858e9;` (`wp-includes/css/dist/base-styles/admin-schemes.css:111-112`); "modern" è il default di WordPress 7.1 (`wp-includes/user.php:2517`), ed è lo schema dell'utente `admin` del sito di prova.
- **Effetto**: con lo schema di default, caselle, radio e fuoco dei componenti di WordPress nelle pagine Barrio restano blu accanto ai bottoni scuri: la regola non fa niente. Nel browser il valore calcolato è `#1d2327` su `<html>` e `#3858e9` su `<body>` e dentro `.barrio-admin`; l'anello del focus delle schede del builder è `rgb(56, 88, 233)`, quello dei controlli del plugin `#2271b1`: tre colori per lo stesso stato. Con "Fresh" la regola funziona, ma anche nella lista dei tipi con Ordinamento e nella Bacheca di WordPress.
- **Modifica minima**: decidere quale colore si vuole. Se va bene quello di WordPress: togliere le tre righe e il commento (−4). Se si vuole quello del plugin: dichiarare su `.barrio-admin, .barrio-menu, .barrio-picker, .barrio-filters` le variabili che WordPress 7.1 legge davvero, a partire da `--wpds-color-stroke-focus` (+6 / −3). Spostare solo `--wp-admin-theme-color` su `.barrio-admin` non basta: provato nel browser, l'anello delle schede resta blu. In ogni caso correggere il commento di riga 3.
- **Rischio**: basso, solo colori · **Impegno**: S · **Verifica**: letto riga per riga, core 7.1.4 compreso, e riprodotto nel browser sul sito di prova; non verificato su WordPress 6.9. Effetto collaterale della correzione del §3.7 di `docs/audit.md`.

### Gravità bassa

#### FORMS-2 · Il limite per IP non è "10 ogni 10 minuti": ogni tentativo fa ripartire i 10 minuti

- **Area**: Forms, antispam · presupposto
- **Prova**: `set_transient( self::rate_key( $ip ), self::submissions( $ip ) + 1, self::RATE_WINDOW );` ([Submission.php:161](modules/forms/src/Submission/Submission.php#L161)): ogni conteggio riscrive la scadenza. Conta anche il 429 ([54-57](modules/forms/src/Submission/Submission.php#L54-L57)), quindi chi riprova allunga il proprio blocco. Lettura e scrittura sono separate, non atomiche.
- **Effetto**: un IP condiviso (ufficio, tablet a una fiera, frontend headless) che invia ogni pochi minuti arriva alla decima registrazione e resta bloccato finché non passano 10 minuti senza alcun tentativo. Una raffica in parallelo invece passa tutta.
- **Modifica minima**: salvare nel transient anche la fine della finestra e riusarla come scadenza (+3 / −2). Per la raffica basta un commento `ponytail:` (un contatore atomico chiede un object cache).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### FORMS-3 · Un campo nascosto preso dall'URL, oltre 255 caratteri, blocca l'invio senza mostrare nulla

- **Area**: Forms, validazione · UX
- **Prova**: con `param` il valore del campo `hidden` è quello del client, intero ([FieldTypes.php:196](modules/forms/src/FieldTypes.php#L196)), e passa dal controllo "at most %d characters" ([368-372](modules/forms/src/FieldTypes.php#L368-L372)); `form.js` copia il parametro senza tagliarlo ([form.js:40](modules/forms/assets/frontend/form.js#L40)) e l'errore finisce in un campo con `display: none`.
- **Effetto**: chi arriva con un `utm_content` o un click id di 300 caratteri riceve "Please correct the highlighted fields." senza alcun campo evidenziato.
- **Modifica minima**: tagliare invece di rifiutare: `mb_substr( self::line( $raw ), 0, 255 )` (1 riga).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### FORMS-4 · Un campo con chiave `dataset` rompe il form

- **Area**: Forms, script del sito · qualità (stessa classe di `action`, già corretta)
- **Prova**: `JSON.parse( form.dataset.messages )` ([form.js:29](modules/forms/assets/frontend/form.js#L29)) e `'barrioPreview' in form.dataset` ([riga 178](modules/forms/assets/frontend/form.js#L178)); la chiave del campo diventa il `name` del controllo, e in un `<form>` i controlli con nome coprono le proprietà dell'elemento. Per `action` il rimedio c'è già ([form.js:189-190](modules/forms/assets/frontend/form.js#L189-L190)). Lo schema non ha chiavi riservate.
- **Effetto**: un campo con etichetta "Dataset" → `form.dataset` è l'input, `JSON.parse( undefined )` lancia e `init()` si ferma: niente condizioni, niente passaggi, invio nativo sul JSON della REST.
- **Modifica minima**: `form.getAttribute( 'data-messages' )` e `form.hasAttribute( 'data-barrio-preview' )` (2 righe).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga; non riprodotto nel browser.

#### FORMS-5 · Con la pagina di ringraziamento il form torna inviabile mentre il browser naviga

- **Area**: Forms, script del sito · UX
- **Prova**: `window.location.assign( body.redirect ); return;` passa dal `finally` che fa `form.removeAttribute( 'aria-busy' )` ([form.js:205-221](modules/forms/assets/frontend/form.js#L205-L221)), l'unica guardia contro il doppio invio.
- **Effetto**: con una pagina di destinazione lenta, un secondo clic su Invia crea una registrazione e una notifica doppie.
- **Modifica minima**: non togliere `aria-busy` quando si lascia la pagina, e toglierlo su `pageshow` con `event.persisted` (+5 / −1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### FORMS-6 · Multistep: tutti i passaggi sono visibili finché non parte il JavaScript

- **Area**: Forms, rendering · UX
- **Prova**: nessun passaggio esce con `hidden` ([Render.php:93](modules/forms/src/Frontend/Render.php#L93)); li nasconde lo script module, differito.
- **Effetto**: a ogni caricamento il form compare per intero e poi si riduce al primo passaggio (salto del layout).
- **Modifica minima**: `hidden` sui passaggi dopo il primo in `Render.php` (1 riga; `RenderTest` da aggiornare se confronta l'HTML). Senza JavaScript il form non si invia comunque, e lo dice il `<noscript>`.
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### FORMS-8 · Una scelta il cui valore ha spazi ai bordi o doppi non si può mai inviare

- **Area**: Forms, validazione · qualità
- **Prova**: il valore inviato si normalizza con `self::line()` ([FieldTypes.php:199](modules/forms/src/FieldTypes.php#L199), [418-421](modules/forms/src/FieldTypes.php#L418-L421)) e poi si confronta in modo esatto con il valore salvato, non normalizzato: `in_array( $value, (array) $config['choices'], true )` ([riga 399](modules/forms/src/FieldTypes.php#L399)).
- **Effetto**: un valore incollato come `"Corso  avanzato "` si salva così, e chi sceglie quella voce riceve "Please choose one of the available options."
- **Modifica minima**: in `FieldTypes::check()` rifiutare al salvataggio una scelta diversa da `self::line()` di sé stessa (+4).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### FORMS-9 · PHP e JS normalizzano i valori in due modi, senza fixture comuni

- **Area**: Forms, logica condizionale · duplicato
- **Prova**: JS: `control.value.trim().replace( /\s+/g, ' ' )` ([form.js:62-64](modules/forms/assets/frontend/form.js#L62-L64)), che comprende lo spazio non separabile. PHP: `preg_replace( '/\s+/', ' ', … )` senza `u` e `trim()` ([FieldTypes.php:420](modules/forms/src/FieldTypes.php#L420), [429](modules/forms/src/FieldTypes.php#L429)): solo spazi ASCII. `tests/fixtures/conditions.json` prova valori già normalizzati.
- **Effetto**: regola "mostra Dettagli se Città = San Remo" e un valore incollato con uno spazio non separabile: il browser mostra Dettagli, il server lo considera nascosto e ne scarta la risposta, senza errore.
- **Modifica minima**: `/\s+/u` e un rifilo Unicode in `text()` (2 righe).
- **Rischio**: basso · **Impegno**: S · **Verifica**: lato JS riprodotto con `node`; lato PHP letto (il comportamento di `\s` senza `u` è citato a memoria).

#### FORMS-13 · Durante l'invio del form non c'è alcun messaggio

- **Area**: Forms, script del sito · accessibilità
- **Prova**: `form.setAttribute( 'aria-busy', 'true' ); status.textContent = '';` ([form.js:182-183](modules/forms/assets/frontend/form.js#L182-L183)); l'unico segnale è il pulsante al 60% di opacità.
- **Effetto**: con un file da 20 MB da telefono, per decine di secondi niente dice che l'invio è partito; uno screen reader non sente nulla.
- **Modifica minima**: `status.textContent = messages.sending;` e la stringa `sending` tra i messaggi di `Render.php` (2 righe, più la traduzione). L'elemento ha già `role="status"`.
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### FADM-3 · Tendine che mostrano "—" mentre resta salvato un valore non più in elenco

- **Area**: Forms, builder (condizioni e integrazioni) · UX
- **Prova**: [Conditions.js:179](modules/forms/assets/src/builder/Conditions.js#L179) cambia il campo di una regola senza toccare il valore (`onChange( { field } )`), ma il valore è una `Select` con le sole scelte del campo nuovo ([Conditions.js:157-169](modules/forms/assets/src/builder/Conditions.js#L157-L169)). [Integrations.js:37-42](modules/forms/assets/src/builder/Integrations.js#L37-L42) (`fieldOptions`) elenca solo i campi che esistono. `Select` è un `<select>` con `value={ value ?? '' }` ([ui.js:306-308](assets/src/admin/ui.js#L306-L308)): con un valore assente il browser mostra la prima opzione, "—".
- **Effetto**: regola "Tipo = azienda", si cambia il campo in "Provincia": la tendina mostra "—" ma `rule.value` resta `azienda`. Integrazione Mailchimp con il campo email eliminato: la scheda mostra "—", l'impostazione salvata resta la chiave vecchia.
- **Modifica minima**: `onChange( { field, value: '' } )` (1 riga); in `fieldOptions` aggiungere il valore salvato che non è tra i campi, con l'etichetta "(missing field)" già usata in [Conditions.js:137-147](modules/forms/assets/src/builder/Conditions.js#L137-L147) (+5).
- **Rischio**: basso. Al cambio di campo si perde anche un valore di testo che poteva servire ancora.
- **Impegno**: S
- **Verifica**: letto riga per riga; non provato nel browser.

#### FADM-6 · Due frasi con un numero non usano `_n`

- **Area**: Forms (elenco dei form) e Messa online · i18n
- **Prova**: [Forms.js:82-90](modules/forms/assets/src/forms/Forms.js#L82-L90) usa `__( '"%1$s" has %2$d entries, so it cannot be deleted…' )` con `form.entries`, che può essere 1 (il ramo si apre con `form.entries > 0`, riga 80). [Launch.js:164-168](assets/src/launch/Launch.js#L164-L168) usa `__()` con il testo `…: %d steps to go.` e `left.length`, che parte da 1.
- **Effetto**: "has 1 entries" e "1 steps to go" (in italiano "ha 1 registrazioni", "mancano 1 passaggi").
- **Modifica minima**: `_n()` nelle due chiamate (+6 / −2), poi rigenerare le traduzioni.
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### FADM-10 · Cambiare scheda nel builder dimentica il campo scelto

- **Area**: Forms, builder · UX
- **Prova**: `TabPanel` rende solo la scheda aperta ([Builder.js:271-305](modules/forms/assets/src/builder/Builder.js#L271-L305)), quindi `FieldsTab` si smonta a ogni cambio e riparte dal primo campo: `useState( fields.length ? 0 : null )` ([Fields.js:141](modules/forms/assets/src/builder/Fields.js#L141)).
- **Effetto**: si modifica il dodicesimo campo, si apre Notifiche per leggere un merge tag, si torna a Campi: è selezionato il primo.
- **Modifica minima**: tenere `selected` in `Builder` e passarlo a `FieldsTab` (+4 / −1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### FADM-11 · Registrazioni: 250 ms di attesa prima di ogni richiesta, non solo durante la ricerca

- **Area**: Forms, registrazioni · prestazioni
- **Prova**: [Entries.js:104-126](modules/forms/assets/src/entries/Entries.js#L104-L126): il commento dice "Mentre si scrive nella ricerca parte una sola richiesta", ma `setTimeout( …, 250 )` avvolge ogni richiesta dell'effetto, che dipende da `[ formId, query, reload ]`: anche primo caricamento, cambio pagina, ordinamento, filtri.
- **Effetto**: ogni clic nella tabella aspetta 250 ms prima di chiamare il server.
- **Modifica minima**: il ritardo solo quando cambia `query.search` (un `useRef` con l'ultima ricerca, `setTimeout( …, changed ? 250 : 0 )`, +4).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### FADM-12 · Un preset sostituisce tutte le scelte senza conferma

- **Area**: Forms, builder · UX
- **Prova**: [Fields.js:619-630](modules/forms/assets/src/builder/Fields.js#L619-L630): ogni voce del menu "Presets" chiama subito `set( presetRows( preset ) )`. Che le scelte vengono sostituite lo dice solo `label`, che con `text` non è sul bottone. Le altre azioni che non si annullano chiedono conferma ([Forms.js:97](modules/forms/assets/src/forms/Forms.js#L97)).
- **Effetto**: un clic su "Countries (197)" cancella le scelte scritte a mano; il builder non ha annulla.
- **Modifica minima**: `window.confirm` prima di `set` quando ci sono scelte scritte (+5).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### SEO-3 · La query string della richiesta finisce dopo il `#` della destinazione

- **Area**: SEO, redirect · qualità
- **Prova**: `$redirect['target'] .= ( str_contains( $redirect['target'], '?' ) ? '&' : '?' ) . $query;` ([Redirects.php:58](modules/seo/src/Redirects.php#L58)): la query si accoda in fondo, anche dopo un frammento, che `TARGET` ammette.
- **Effetto**: redirect `/contatti-old → /contatti/#form` e una visita a `/contatti-old?fbclid=abc`: `Location: /contatti/#form?fbclid=abc`. L'ancora non funziona e la query non arriva al server.
- **Modifica minima**: mettere la query prima del frammento (+2 / −1), più un'asserzione.
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### SEO-4 · Il controllo dei cicli non vede la destinazione "sorgente + query string"

- **Area**: SEO, redirect · qualità
- **Prova**: `loops()` confronta solo il percorso intero (`if ( $at === $source )`, [Redirects.php:84-86](modules/seo/src/Redirects.php#L84-L86)), mentre a runtime una richiesta con query ricade sulla regola senza query ([55-58](modules/seo/src/Redirects.php#L55-L58)).
- **Effetto**: `source: /promo`, `target: /promo?utm_source=sito` si salva; nel sito è un ciclo ("troppi reindirizzamenti").
- **Modifica minima**: `if ( $at === $source || explode( '?', $at, 2 )[0] === $source )` (1 riga), più un caso nel test.
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga, seguendo a mano la catena.

#### SEO-7 · llms.txt elenca le voci di tutti i menu, anche non assegnati e verso pagine non indicizzate

- **Area**: SEO, llms.txt · presupposto
- **Prova**: `foreach ( wp_get_nav_menus() as $menu )` e per ogni voce `self::link( $item->title, $item->url )`, senza `Meta::indexable()` ([Llms.php:141-149](modules/seo/src/Llms.php#L141-L149)). L'intestazione della classe dice "Solo contenuti indicizzabili" ([Llms.php:12-13](modules/seo/src/Llms.php#L12-L13)).
- **Effetto**: i menu rimasti da un tema precedente, o una pagina noindex in un menu, escono in llms.txt sotto "Navigation".
- **Modifica minima**: solo i menu assegnati a una posizione: `array_unique( array_filter( get_nav_menu_locations() ) )` (+2 / −1).
- **Rischio**: basso; con un tema a blocchi senza posizioni la sezione sparisce · **Impegno**: S · **Verifica**: letto riga per riga.

#### SEO-8 · "Genera con AI": `title` e `url` senza lunghezza massima

- **Area**: SEO, REST · sicurezza (costo)
- **Prova**: gli argomenti sono `'type' => 'string', 'default' => ''`, senza `maxLength` ([RestGenerate.php:21-24](modules/seo/src/Api/RestGenerate.php#L21-L24)); solo `text` si taglia a 4.000 caratteri ([riga 58](modules/seo/src/Api/RestGenerate.php#L58)). Titolo e URL entrano nel prompt come arrivano ([riga 64](modules/seo/src/Api/RestGenerate.php#L64)). La rotta chiede `barrio_edit_seo`.
- **Effetto**: chi ha quella capability può mandare a Google, sul conto del sito, megabyte per richiesta.
- **Modifica minima**: `'maxLength' => 2048` su `url`, `255` su `title` e `lang` (+3). Il limite per utente resta una scelta (già in `docs/audit-seo.md` §6).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### SEO-10 · "N controlli da migliorare" scorre la pagina ma non sposta il focus

- **Area**: SEO, pannello dell'editor · accessibilità
- **Prova**: `setChecksOpen( true ); checks.current.scrollIntoView( { block: 'start' } );` ([Panel.js:400-403](modules/seo/assets/src/editor/Panel.js#L400-L403)).
- **Modifica minima**: `checks.current.querySelector( 'button' )?.focus();` al posto di `scrollIntoView` (+1 / −1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### SEO-12 · Due tipi di organizzazione non esistono in schema.org

- **Area**: SEO, schema dell'organizzazione · presupposto
- **Prova**: l'elenco "Le 16 voci del prototipo" contiene `'InteriorDesigner', 'Architect'` ([seo/Settings.php:18](modules/seo/src/Settings.php#L18)), e il valore esce così com'è: `'@type' => $o['type']` ([Schema.php:115](modules/seo/src/Schema.php#L115)). `https://schema.org/Architect` e `https://schema.org/InteriorDesigner` rispondono 404; gli altri 14 tipi esistono (`ProfessionalService` è segnato come deprecato nella sua pagina).
- **Effetto**: uno studio di architettura che sceglie "Architect" pubblica un `#organization` con un `@type` sconosciuto: Google non lo tratta come attività locale e il Rich Results Test lo segnala.
- **Modifica minima**: togliere le due voci dall'elenco (−2) e, nell'estensione di migrazione, portare a `LocalBusiness` i siti del prototipo che le avevano scelte (+2).
- **Rischio**: basso; il plugin non è ancora rilasciato · **Impegno**: S · **Verifica**: le due pagine di schema.org richieste il 20 novembre 2026 (404); codice letto.

#### PBH-5 · Un link o un'ora non validi tornano all'utente come espressione regolare

- **Area**: Popup, Banner, Orari · UX (stessa famiglia di FADM-2)
- **Prova**: l'errore dello schema passa così com'è: `new WP_Error( 'rest_invalid_param', $valid->get_error_message(), … )` ([PopupRepository.php:57-60](modules/popups/src/PopupRepository.php#L57-L60), lo stesso in `BannerRepository.php:42-45`). Il link ha `'pattern' => '^(/|https?://)'` ([PopupRepository.php:154](modules/popups/src/PopupRepository.php#L154)), l'ora `'^([01][0-9]|2[0-3]):[0-5][0-9]$'` ([hours/Module.php:203](modules/hours/src/Module.php#L203)). La modale del link chiede solo che il campo non sia vuoto ([period.js:245-247](assets/src/admin/period.js#L245-L247)).
- **Effetto**: con `mailto:…`, `tel:…` o `#contatti` nel link l'avviso dice "popup[i18n][it][link_url] does not match pattern ^(/|https?://)." (messaggio ricostruito dal sorgente di WordPress).
- **Modifica minima**: due messaggi nostri prima della validazione dello schema: per il link una volta sola in `PeriodRepository` (+7), per le ore un `validate_callback` di `week` (+5), più 2 stringhe.
- **Rischio**: basso, cambia solo il testo dell'errore · **Impegno**: S · **Verifica**: letto riga per riga.

#### PBH-6 · "Attivo" anche per il popup o il banner che il sito non mostra

- **Area**: Popup e Banner, elenco e Bacheca · UX
- **Prova**: il sito mostra solo il primo attivo per `id` decrescente ([PeriodRepository.php:60-67](src/Database/PeriodRepository.php#L60-L67)); l'elenco mostra lo stato del singolo record e la Bacheca li conta tutti come "Active now".
- **Effetto**: due banner accesi: tutti e due "Attivo" in verde, "Attivi ora: 2", il sito ne mostra uno. "Il più recente" è l'ultimo creato, non l'ultimo modificato, e niente lo dice.
- **Modifica minima**: in `PeriodList` gli attivi dopo il primo prendono un badge grigio "Active, behind a newer one" (+6 / −1, 1 stringa); nella Bacheca "Active now" conta al più 1.
- **Rischio**: basso, solo admin · **Impegno**: S · **Verifica**: letto riga per riga.

#### PBH-7 · Selezionare del testo nel popup e rilasciare il mouse fuori lo chiude

- **Area**: Popup, script del sito · UX
- **Prova**: lo sfondo chiude guardando solo il bersaglio del clic: `if ( event.target === dialog ) { dialog.close(); }` ([popup.js:65-69](modules/popups/assets/frontend/popup.js#L65-L69)). Premendo dentro il contenuto e rilasciando sullo sfondo il `click` arriva al `<dialog>` (regola di UI Events, citata a memoria).
- **Effetto**: in un popup con un form, selezionare il testo di un campo trascinando oltre il bordo chiude il popup, che con i giorni impostati non torna.
- **Modifica minima**: chiudere solo se anche la pressione era sullo sfondo (un `pointerdown` che ricorda il bersaglio, +4 / −1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: non riprodotto nel browser.

#### PBH-12 · In un tema senza `wp_body_open` il banner non compare, e niente lo dice

- **Area**: Banner · presupposto
- **Prova**: l'unico punto in cui si stampa è `add_action( 'wp_body_open', [ self::class, 'render' ] );` ([banners/Module.php:30](modules/banners/src/Module.php#L30)); `grep -n "wp_footer" modules/banners/src/Module.php` non trova un ripiego.
- **Effetto**: su un tema classico che non chiama `wp_body_open()` il banner è "Attivo" nell'admin, la pagina carica CSS e JS, e nel sito non c'è.
- **Modifica minima**: ripiego su `wp_footer` con un flag statico e `data-late`; in `banner.js`, con `data-late`, `document.body.prepend( banner )` (+5 PHP, +3 JS).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### PBH-13 · Se la prima anteprima fallisce, il popup resta con la rotella e gli Orari con una card vuota

- **Area**: Popup e Orari, admin · UX
- **Prova**: `.catch( () => {} )` dopo la richiesta dell'anteprima ([Popups.js:269-271](modules/popups/assets/src/Popups.js#L269-L271), [Hours.js:567-568](modules/hours/assets/src/Hours.js#L567-L568)); finché `html` è `null` la card mostra `<Loading />`.
- **Effetto**: uno shortcode che va in errore o la rete che cade: rotella senza fine, nessun messaggio.
- **Modifica minima**: uno stato `failed` nel `catch` e un `<Notice>` al posto della rotella (+5 per file).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### CONT-5 · Google viene chiamato anche quando non c'è niente da scrivere

- **Area**: Testi alternativi · costo delle chiamate
- **Prova**: `cron()` chiama `self::generate( $id )` e solo dopo `self::save()`, che scrive "solo dove manca" ([alt/Module.php:280-285](modules/alt/src/Module.php#L280-L285), [389-392](modules/alt/src/Module.php#L389-L392)); lo stesso in `generate_route()` con `save` ([258-266](modules/alt/src/Module.php#L258-L266)). L'evento si programma senza guardare l'alt ([Module.php:273](modules/alt/src/Module.php#L273)). La massa della tabella filtra solo `row.can && row.supported` ([Alt.js:591-598](modules/alt/assets/src/Alt.js#L591-L598)), anche se la voce si chiama "empty fields only".
- **Effetto**: con "Generate after the upload" acceso, ogni immagine che ha già l'alt costa una chiamata a Gemini buttata; 20 righe con l'alt selezionate = 20 chiamate e "20 of 20 images done" senza scrivere niente.
- **Modifica minima**: una funzione privata che dice se c'è qualcosa da scrivere, usata prima di `generate()` in `cron()` e in `generate_route()` con `save` (+7), e un test che conta le richieste (+6).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### CONT-7 · Ogni maniglia di trascinamento si chiama solo "Move"

- **Area**: admin, `Sortable` (Riordina, builder dei form) · accessibilità
- **Prova**: `aria-label={ __( 'Move', 'barrio-suite' ) }` fisso ([sortable.js:18-24](assets/src/admin/sortable.js#L18-L24)); in Riordina c'è una maniglia per voce e il titolo è in uno `<span>` a parte.
- **Effetto**: con lo screen reader, Tab in una modale di 40 pagine dice "Sposta, pulsante" quaranta volte.
- **Modifica minima**: `Sortable` conosce già il nome (`label( item )`): passarlo alla maniglia, `'aria-label': sprintf( __( 'Move %s', 'barrio-suite' ), label( item ) )` con il commento per i traduttori (+6 / −1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga; non provato con uno screen reader.

#### CONT-8 · Pagine più viste: il link va all'host registrato da GA, che non si vede mai

- **Area**: Statistiche · UX
- **Prova**: il titolo si cerca per percorso sul sito qualunque sia l'host ([analytics/Module.php:515-516](modules/analytics/src/Module.php#L515-L516)), il link usa l'host di GA ([riga 523](modules/analytics/src/Module.php#L523)), e il suggerimento mostra solo il percorso: `title={ row.path }` ([Analytics.js:566-570](modules/analytics/assets/src/Analytics.js#L566-L570)).
- **Effetto**: se la proprietà riceve visite da un altro host (staging, copia del sito) compaiono due righe "Chi siamo" identiche, una delle quali porta fuori dal sito.
- **Modifica minima**: `title={ row.url }` (+1 / −1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### CONT-9 · Blocchi: cambiando un editor si perdono le scelte dei tipi che in quel momento non esistono

- **Area**: Blocchi, pagina Contenuti · qualità
- **Prova**: `setEditor()` ricostruisce `editors` dai soli tipi che il server elenca ([Blocks.js:267-278](modules/blocks/assets/src/Blocks.js#L267-L278)); [185-197](modules/blocks/assets/src/Blocks.js#L185-L197) fa lo stesso per i ruoli. Ordinamento e Duplicazione dichiarano la regola opposta ("Un tipo che non c'è più resta in ordine manuale, se torna").
- **Effetto**: plugin che registra il tipo `evento` disattivato per manutenzione; si cambia l'editor delle Pagine e si salva: la scelta di `evento` sparisce dall'opzione.
- **Modifica minima**: aggiungere in coda le voci del draft che non sono tra quelle disponibili (+6).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### CONT-10 · Ordinamento e Duplicazione: spegnere e riaccendere un interruttore lascia Salva acceso

- **Area**: pagina Contenuti · UI
- **Prova**: riaccendere aggiunge il nome in fondo (`[ ...draft[ group ], name ]`, [Ordering.js:74-82](modules/ordering/assets/src/Ordering.js#L74-L82)) e `General.js` confronta con `JSON.stringify` ([General.js:140](assets/src/general/General.js#L140)), che guarda l'ordine. In Duplicazione ogni clic scrive una scelta esplicita ([Duplicate.js:83-88](modules/duplicate/assets/src/Duplicate.js#L83-L88)).
- **Effetto**: Salva resta acceso e il browser chiede conferma uscendo, anche se non è cambiato niente.
- **Modifica minima**: `.sort()` sull'elenco in Ordinamento (+3); in Duplicazione togliere la chiave quando il valore torna quello salvato o di default (+4 / −1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: l'ordine riprodotto con `node`; Duplicazione letta.

#### UI-6 · `Table`: il numero di risultati cambia senza annuncio

- **Area**: admin, `Table` · accessibilità
- **Prova**: il conteggio è uno `<span>` senza `role` né `aria-live` ([ui.js:1406-1414](assets/src/admin/ui.js#L1406-L1414)); `<div className="barrio-table__scroll">` e `<table>` non hanno un nome ([ui.js:1323-1324](assets/src/admin/ui.js#L1323-L1324)).
- **Effetto**: chi usa uno screen reader scrive nella ricerca e non sa se ha trovato 0 o 57 righe (WCAG 4.1.3).
- **Modifica minima**: `role="status"` sullo `<span>` (1 riga). Facoltativo: `aria-label` sulla tabella e `role="region"` con `tabIndex={ 0 }` sul contenitore (+4).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga e riprodotto nel browser: scrivendo "news" in "Search forms" il conteggio diventa "1–1 of 1" e nessuna regione live cambia.

#### UI-9 · Estensioni: il testo rimanda a "Forms → Settings" anche con il modulo Form spento

- **Area**: admin, pagina Estensioni · presupposto
- **Prova**: il paragrafo è sempre mostrato, con `href={ pageUrl( { tab: 'settings' } ) }` ([Extensions.js:168-177](assets/src/extensions/Extensions.js#L168-L177)); `pageUrl` arriva da `modules/forms/assets/src/ui.js` ([Extensions.js:14](assets/src/extensions/Extensions.js#L14)).
- **Effetto**: con Form spento il link porta a "Sorry, you are not allowed to access this page."; il testo descrive tutte le estensioni come integrazioni dei form, e WPML e WPGraphQL non lo sono.
- **Modifica minima**: dividere la stringa e mostrare la seconda frase solo se `window.barrioAdmin.forms` esiste (+4 / −1, una stringa da ritradurre).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### UI-10 · Google/AI: se Google non risponde, "Loading from Google…" resta per sempre

- **Area**: admin, card Google · UX
- **Prova**: `const loading = connected && ! choices ? __( 'Loading from Google…' ) : '';` ([Google.js:144-147](assets/src/general/Google.js#L144-L147)); il `catch` mostra l'errore ma non tocca `choices`, che resta `null` ([126-128](assets/src/general/Google.js#L126-L128)).
- **Modifica minima**: nel `catch` anche `current && setChoices( {} )` (+2 / −1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### UI-11 · Gli avvisi `info` non hanno uno stile

- **Area**: admin, CSS · UI
- **Prova**: `status: 'info'` compare 8 volte nei sorgenti JS; [admin.css:813-829](assets/src/admin/admin.css#L813-L829) definisce solo `.is-success`, `.is-error` e `.is-warning` (`grep -c is-info assets/src/admin/admin.css` → 0).
- **Effetto**: un avviso info ha il bordo del colore del testo e nessuno sfondo; se è sticky il contenuto gli scorre dietro.
- **Modifica minima**: `.barrio-notice.is-info { border-color: var(--barrio-line); background: #fff; }` (+5).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga; non guardato a schermo.

#### UI-14 · `wp_enqueue_media()` parte su ogni `tab=settings`

- **Area**: core, admin · prestazioni
- **Prova**: con la condizione `if ( 'settings' === sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ) || isset( $_GET['launch'] ) )` parte `wp_enqueue_media()` ([Admin.php:108-113](src/Admin.php#L108-L113)). La libreria serve a Form → Impostazioni (logo) e alla messa online; SEO e Popup la caricano già sul loro hook.
- **Effetto**: le schede Settings di Statistiche, Testi alternativi, Banner e Orari caricano script e template della libreria dei media per niente.
- **Modifica minima**: spostare la chiamata sull'hook `load-…` della pagina Form e lasciare in `Admin.php` solo `isset( $_GET['launch'] )` (+3 / −1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga. Seguito del §3.9 di `docs/audit.md`.

#### LIVE-5 · Chiusa l'anteprima del form, il focus riparte dall'inizio della pagina

- **Area**: Forms, builder · accessibilità
- **Prova**: il bottone Preview ha `disabled={ !! busy }` ([Builder.js:189-192](modules/forms/assets/src/builder/Builder.js#L189-L192)) e `busy` parte al clic: il bottone con il focus diventa `disabled`, il browser sposta il focus su `<body>`, e alla chiusura la modale lo rimette lì. Stesso schema per l'anteprima di una notifica ([riga 579](modules/forms/assets/src/builder/Builder.js#L579)).
- **Effetto**: chiusa l'anteprima si riparte da "Skip to main content". Le altre nove modali provate rimettono il focus su chi le ha aperte.
- **Modifica minima**: `disabled={ 'save' === busy }` per i due bottoni (+2 / −2).
- **Rischio**: basso · **Impegno**: S · **Verifica**: riprodotto nel browser, tre misure.

#### LIVE-7 · L'anteprima JSON-LD dell'organizzazione non si scorre da tastiera

- **Area**: SEO, Organizzazione · accessibilità
- **Prova**: `<pre className="barrio-code">{ preview }</pre>` ([Organization.js:155](modules/seo/assets/src/seo/Organization.js#L155)) con `max-height: 320px` e `overflow-y: auto` ([admin.css:2244-2249](assets/src/admin/admin.css#L2244-L2249)): una zona che scorre senza niente che prenda il focus.
- **Effetto**: sul sito di prova il JSON è alto 2.318 px in una finestra di 344: da tastiera si leggono solo le prime righe. È l'unica violazione di axe dovuta al plugin nelle 34 pagine admin misurate (`scrollable-region-focusable`).
- **Modifica minima**: `tabIndex="0" role="region" aria-label={ __( 'Preview', 'barrio-suite' ) }` sul `<pre>` (1 riga).
- **Rischio**: basso · **Impegno**: S · **Verifica**: riprodotto nel browser con axe.

#### LIVE-9 · Bacheca sul telefono: quattro descrizioni dei moduli sono tagliate

- **Area**: admin, Bacheca · UX (tocca una scelta del 19 novembre)
- **Prova**: `.barrio-module__head p { … white-space: nowrap; text-overflow: ellipsis; }` ([admin.css:620-627](assets/src/admin/admin.css#L620-L627)) e il testo intero solo nel `title` ([ui.js:568](assets/src/admin/ui.js#L568)).
- **Effetto**: a 360 px sono tagliate le descrizioni di Statistiche, Testi alternativi, Contenuti e Prestazioni; su un telefono non c'è il passaggio del mouse, quindi quel testo non si può leggere. La riga unica era stata chiesta per tenere allineate le card affiancate, ma a 360 px le card sono una sotto l'altra.
- **Modifica minima**: `@media (max-width: 782px) { .barrio-module__head p { white-space: normal; } }` (+6).
- **Rischio**: basso · **Impegno**: S · **Verifica**: riprodotto nel browser a 360×740.

#### LIVE-10 · Messa online: "fatto" e "saltato" dei passaggi sono solo un segno

- **Area**: admin, Messa online · accessibilità
- **Prova**: `mark = <Icon name="yes" />;` (icona `aria-hidden`) per un passaggio fatto e `mark = '–';` per uno saltato ([Launch.js:362-367](assets/src/launch/Launch.js#L362-L367)).
- **Effetto**: i nomi dei bottoni letti dal browser sono "1 SMTP", "2 Forms", "SEO", "4 Time zone"…: un passaggio fatto si riconosce solo perché gli manca il numero.
- **Modifica minima**: accanto al segno un `<span className="screen-reader-text">` con "Configured" o "Skipped", stringhe che esistono già (+8 / −2).
- **Rischio**: basso · **Impegno**: S · **Verifica**: riprodotto nel browser per "fatto"; "saltato" letto nel codice.

#### LIVE-11 · Permessi sul telefono: i due interruttori di ogni ruolo stanno uno sopra l'altro a 8 px

- **Area**: admin, CSS · UI (regola fissa degli interruttori impilati)
- **Prova**: `.barrio-checks { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px 24px; }` ([admin.css:537-542](assets/src/admin/admin.css#L537-L542)): quando vanno a capo restano a 8 px, mentre la regola degli interruttori impilati (16 px e una linea) esclude apposta `.barrio-checks`.
- **Effetto**: a 360 px in Form → Impostazioni → Permessi e in SEO → Impostazioni ogni coppia è impilata a 8 px: con il dito è facile toccare l'interruttore sbagliato, e sono permessi.
- **Modifica minima**: `gap: 16px 24px;` (1 riga; su una riga sola non cambia niente).
- **Rischio**: basso · **Impegno**: S · **Verifica**: riprodotto nel browser a 360×740.

#### CORE-4 · Con il modulo Orari spento `[barrio_hours]` resta come testo nelle pagine

- **Area**: core, shortcode · presupposto
- **Prova**: solo il form è coperto: `if ( ! Modules::is_enabled( 'forms' ) ) { add_shortcode( 'barrio_form', '__return_empty_string' ); }` ([Plugin.php:74-77](src/Plugin.php#L74-L77)). `[barrio_hours]` si registra solo se il modulo parte. E un modulo acceso ma con lo schema non aggiornato non fa `boot()` ([Plugin.php:51-53](src/Plugin.php#L51-L53)), quindi nemmeno la riga 75 scatta.
- **Effetto**: spegnendo Orari le pagine mostrano ai visitatori il testo `[barrio_hours]`; con una migrazione fallita anche `[barrio_form id="3"]`, e una cache di pagina lo può salvare.
- **Modifica minima**: su `init` con priorità 11, per i due tag: `if ( ! shortcode_exists( $tag ) ) { add_shortcode( $tag, '__return_empty_string' ); }` al posto delle righe 74-77 (+9 / −3).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### CORE-5 · Una migrazione che fallisce si riesegue a ogni richiesta, in fila dietro il lock

- **Area**: core, `Migrator` · prestazioni in caso di errore
- **Prova**: `$error = $migrator->run();` a ogni `boot()` ([Plugin.php:49](src/Plugin.php#L49)); `SELECT GET_LOCK(%s, 5)` ([Migrator.php:44](src/Database/Migrator.php#L44)); se la migrazione lancia, `return new WP_Error( 'barrio_migration_failed', … )` e niente ricorda il fallimento ([57-61](src/Database/Migrator.php#L57-L61)).
- **Effetto**: se una migrazione fallisce sempre (utente del database senza `ALTER`), ogni richiesta del sito prende il lock e la riesegue; le richieste contemporanee aspettano fino a 5 secondi l'una.
- **Modifica minima**: nel `catch` un transient di un minuto, e all'inizio di `run()` restituire lo stesso errore senza prendere il lock se c'è (+6 / −1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga; non riprodotto.

#### CORE-6 · `barrio_disabled_modules` nasce solo all'attivazione

- **Area**: core, `Modules` · prestazioni (multisite)
- **Prova**: `add_option( Modules::OPTION, [], '', true );` sta in `activate()` ([Plugin.php:18-19](src/Plugin.php#L18-L19)); `is_enabled()` la legge a ogni richiesta.
- **Effetto**: con il plugin attivato per tutta la rete di un multisite, gli altri siti non hanno l'opzione e WordPress la cerca nel database a ogni richiesta. Già in `docs/audit-seo.md` §1.5 e `audit-moduli.md` §8.
- **Modifica minima**: spostare la riga in `boot()`: `if ( false === get_option( Modules::OPTION ) ) { add_option( … ); }` (+3 / −2).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga; non misurato su un multisite.

#### CORE-7 · `install` attiva l'estensione controllando solo `install_plugins`

- **Area**: core, `Extensions` · sicurezza
- **Prova**: il permesso dell'azione è `'install' => 'install_plugins'` ([Extensions.php:174](src/Extensions.php#L174)), ma dopo l'installazione il plugin viene anche attivato (`?? activate_plugin( … )`, [riga 257](src/Extensions.php#L257)). WordPress per la stessa operazione chiede anche `activate_plugins` (`class-wp-rest-plugins-controller.php:242-250`).
- **Effetto**: solo con ruoli personalizzati: chi ha `install_plugins` senza `activate_plugins` attiva un'estensione.
- **Modifica minima**: attivare solo se `current_user_can( 'activate_plugins' )` (+1 / −1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### EXT-5 · reCAPTCHA: lo script di Google finisce in tutte le pagine quando il popup contiene un form

- **Area**: estensione reCAPTCHA · presupposto, dichiarazione dei servizi esterni
- **Prova**: il commento dice "Nelle sole pagine con un form" ([barrio-suite-recaptcha.php:153](extensions/barrio-suite-recaptcha/barrio-suite-recaptcha.php#L153)) e il readme "On pages with a Barrio Suite form". Ma il popup acceso si prepara in ogni pagina e il suo testo passa da `do_shortcode()`, che lancia `barrio_forms_render`, a cui è agganciato `barrio_recaptcha_enqueue()`.
- **Effetto**: con un popup che contiene un form, ogni pagina del sito carica `enterprise.js`, mostra il badge e manda a Google i dati della visita, anche a chi il popup non lo vedrà. Il readme, che per WordPress.org dichiara i servizi esterni, dice altro.
- **Modifica minima**: correggere commento e readme, e uscire subito se lo script è già in coda (`wp_script_is( 'barrio-recaptcha' )`, +3). Caricare lo script al primo `focusin` su un form (+12 / −3) toglierebbe il carico, ma può abbassare i punteggi: da provare con una chiave vera.
- **Rischio**: basso per i testi, medio per il caricamento ritardato · **Impegno**: S · **Verifica**: letto riga per riga.

#### EXT-6 · reCAPTCHA: l'avviso "manca il service account" compare anche per impostazioni rimaste dal vecchio disegno

- **Area**: estensione reCAPTCHA · presupposto
- **Prova**: `if ( array_filter( barrio_forms_integration_settings( 'recaptcha' ) ) && null === barrio_recaptcha_site_key() )` ([barrio-suite-recaptcha.php:221](extensions/barrio-suite-recaptcha/barrio-suite-recaptcha.php#L221)); `save_settings()` non toglie mai le chiavi vecchie (`$values + (array) ( $saved[ $slug ] ?? [] )`, [Integrations.php:183](modules/forms/src/Integrations.php#L183)), e la versione in `HEAD` salvava `project_id` e `api_key`.
- **Effetto**: sui siti di prova configurati con lo zip precedente, chi sceglie "—" come chiave del sito vede l'avviso in ogni pagina dell'admin, e non può toglierlo.
- **Modifica minima**: guardare solo la chiave del sito (+1 / −1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### EXT-7 · reCAPTCHA: due stringhe non sono nel `.pot` né nella traduzione italiana

- **Area**: estensione reCAPTCHA · i18n (modifiche non committate)
- **Prova**: `grep -rn "cannot read the reCAPTCHA keys" extensions/barrio-suite-recaptcha/` trova solo il PHP ([barrio-suite-recaptcha.php:94](extensions/barrio-suite-recaptcha/barrio-suite-recaptcha.php#L94)); anche la descrizione di "Site key" (riga 44) è cambiata dopo l'ultima rigenerazione. Le altre traduzioni, del core e delle estensioni, sono complete: 1.066 voci nel core, 0 non tradotte, 0 fuzzy.
- **Effetto**: in un admin in italiano il messaggio che spiega quale ruolo manca al service account esce in inglese.
- **Modifica minima**: rigenerare `.pot`, `.po` e `.mo` dell'estensione e tradurre le due stringhe. Facoltativo: un test Node (~25 righe) che fallisce se una stringa dei sorgenti manca dal `.pot`.
- **Rischio**: basso · **Impegno**: S · **Verifica**: grep indicato.

#### EXT-8 · La migrazione lascia i form con Mailchimp a "iscrivi senza conferma" e non lo dice

- **Area**: estensione di migrazione · privacy by default
- **Prova**: `'status' => 'subscribed',` con il commento "Come nel prototipo" ([migration/forms.php:469-470](extensions/barrio-suite-migration/forms.php#L469-L470)); in quel ramo non c'è nessun avviso nel report. L'estensione Mailchimp ha come default la conferma via email ([barrio-suite-mailchimp.php:132](extensions/barrio-suite-mailchimp/barrio-suite-mailchimp.php#L132)). Tenere i form migrati come nel prototipo è una scelta del piano (PLAN.md §8).
- **Effetto**: dopo `wp barrio forms migrate` ogni form con Mailchimp iscrive senza conferma, e chi migra non lo viene a sapere.
- **Modifica minima**: un avviso per form nel report del comando (+1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### EXT-9 · Estensione di migrazione: opzioni di stato lette dal database a ogni richiesta

- **Area**: estensione di migrazione · prestazioni
- **Prova**: `update_option( 'barrio_migration_seo', 'held', false );` senza autoload ([migration/seo.php:33-35](extensions/barrio-suite-migration/seo.php#L33-L35)); l'avviso legge le otto opzioni prima di controllare chi guarda ([barrio-suite-migration.php:109-110](extensions/barrio-suite-migration/barrio-suite-migration.php#L109-L110)).
- **Effetto**: una query in più in ogni pagina del sito prima della migrazione del SEO, e fino a nove in ogni pagina dell'admin (numero non misurato).
- **Modifica minima**: togliere `, false` e controllare `current_user_can( 'manage_options' )` per primo (+3 / −1, 2 righe cambiate).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### TEST-5 · Un test senza asserzioni non fa fallire PHPUnit

- **Area**: test, configurazione
- **Prova**: [phpunit.xml.dist](phpunit.xml.dist) ha `beStrictAboutOutputDuringTests="true"` ma non `failOnRisky`: in PHPUnit 9.6 un test "risky" non cambia il codice di uscita. Nella cache dei risultati ci sono due esiti "risky": è già successo.
- **Modifica minima**: `failOnRisky="true"` (+1). Oggi nessun test ha zero asserzioni.
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto; il default è nel sorgente di PHPUnit in `vendor/`.

## 2. Da correggere prima dell'uso reale

Difetti e punti di sicurezza che chiedono più di mezz'ora, una prova dal vivo o una decisione.

### Gravità media

#### CORE-1 · "Aggiorna" di Barrio sovrascrive il plugin senza la copia temporanea di WordPress

- **Area**: core, updater da GitHub (solo `con-updater`) · affidabilità
- **Prova**: l'azione `update` chiama `self::install( self::DOWNLOAD . "{$slug}.zip", true );` ([Extensions.php:264-265](src/Extensions.php#L264-L265)), cioè `( new Plugin_Upgrader( $skin ) )->install( $package, [ 'overwrite_package' => $overwrite ] );` ([riga 377](src/Extensions.php#L377)). In WordPress `install()` svuota la destinazione senza `temp_backup`, che invece `upgrade()` passa (`wp-admin/includes/class-plugin-upgrader.php:139-144` e `228-238`); il ripristino scatta solo con `temp_backup` (`class-wp-upgrader.php:954-967`).
- **Effetto**: se la copia dei file nuovi si ferma a metà (spazio finito, file non scrivibile), la cartella del plugin resta vuota o parziale e niente la ripristina: form, SEO e redirect spariscono dal sito. Lo stesso aggiornamento da Bacheca → Aggiornamenti passa da `upgrade()` ed è protetto.
- **Modifica minima**: per `update` usare `( new Plugin_Upgrader( $skin ) )->upgrade( $local['file'] )`, dopo aver rinfrescato il transient `update_plugins` come fa già `nightly()` (+8 / −1).
- **Rischio**: medio. Cambia il percorso dell'aggiornamento manuale; il test va adattato e la prima release vera è ancora da provare.
- **Impegno**: M · **Verifica**: letto riga per riga, plugin e core 7.1.4; non eseguito.

#### CORE-2 · L'aggiornamento notturno salta `WP_Automatic_Updater::run()`: niente email, esito ignorato

- **Area**: core, updater da GitHub (solo `con-updater`) · architettura
- **Prova**: `$updater = new WP_Automatic_Updater();` e nel ciclo `$updater->update( 'plugin', $item );`, senza guardare il risultato ([Extensions.php:98-104](src/Extensions.php#L98-L104)). In WordPress l'email dopo gli aggiornamenti automatici, il lock e la rimozione dei controlli ripetuti stanno in `run()` (`wp-admin/includes/class-wp-automatic-updater.php:651-773`).
- **Effetto**: un aggiornamento notturno fallito, o annullato dopo un errore fatale, non lascia traccia: il sito resta alla versione vecchia e riprova ogni notte. Per ogni plugin aggiornato partono in più i controlli di versione di WordPress.
- **Modifica minima**: lasciare il giro a WordPress: in `nightly()`, dopo aver svuotato catalogo e transient, `self::$night = true; wp_maybe_auto_update(); self::$night = false;` (+5 / −12). Da accettare: a mezzanotte WordPress applica anche gli altri aggiornamenti automatici già accesi.
- **Rischio**: medio. Il test va riscritto; da provare con una release vera.
- **Impegno**: M · **Verifica**: letto riga per riga, plugin e core; non eseguito.

#### PBH-4 · Fonte Google: un giorno speciale dentro un periodo di più giorni diventa "Chiuso"

- **Area**: Orari, sincronizzazione con Google Places · difetto (non verificato su Places)
- **Prova**: per ogni data di `specialDays` si prendono solo i periodi che iniziano quel giorno: `if ( self::date( (array) ( $open['date'] ?? [] ) ) !== $date ) { continue; }` ([hours/Module.php:417-421](modules/hours/src/Module.php#L417-L421)); senza periodi `hours` resta `[]` e il giorno esce come chiuso. Un periodo iniziato il giorno prima e che finisce il giorno dopo copre quella data ma non ha `open.date` uguale. Il test prova solo un periodo che si apre e si chiude nello stesso giorno ([HoursTest.php:201-209](tests/Integration/HoursTest.php#L201-L209)).
- **Effetto**: un'attività aperta di continuo per più giorni (hotel, distributore) con una festività nei prossimi 7 giorni: il sito scrive "Chiuso" nei giorni speciali, nello stato e nello schema dell'organizzazione.
- **Modifica minima**: in `google_exceptions()` un periodo vale per la data se `open.date <= data <= close.date`, con inizio `00:00` quando è iniziato prima (+6 / −3), più un caso nel test con un periodo di tre giorni.
- **Rischio**: medio. Tocca dati che vanno nel sito e nello schema.
- **Impegno**: M
- **Verifica**: non verificato. La logica è letta riga per riga, ma la forma di `currentOpeningHours.periods` per un luogo aperto più giorni di fila non è stata controllata con un Place ID vero: va fatto prima di correggere.

#### PBH-3 · Il banner con un link non si può fermare in modo stabile

- **Area**: Banner, script del sito · accessibilità
- **Prova**: mouse e focus fermano la scritta solo finché restano sul banner (`pointerleave` e `focusout` la fanno ripartire, [banner.js:83-86](modules/banners/assets/frontend/banner.js#L83-L86)). L'unico fermo che dura è il clic, e solo senza link: `if ( ! banner.querySelector( 'a' ) ) { banner.addEventListener( 'click', … ) }` ([banner.js:87-92](modules/banners/assets/frontend/banner.js#L87-L92)). Senza link il corpo è un `<div>` con `tabindex` ([banners/Module.php:82](modules/banners/src/Module.php#L82)) senza gestione dei tasti.
- **Effetto**: con un link, su un telefono la scritta si ferma solo tenendo il dito sopra, e il tocco apre il link; da tastiera riparte appena si passa oltre. WCAG 2.2.2 chiede un modo per mettere in pausa (testo del criterio citato a memoria). La decisione "si ferma anche col focus" resta: manca il fermo che dura.
- **Modifica minima**: un `<button class="barrio-banner__pause" aria-pressed="false">` dopo il corpo (+1 riga PHP e una stringa); in `banner.js` il bottone fa ciò che oggi fa il clic senza link, e quel ramo si toglie (+6 / −6); in `banner.css` il bottone a destra, almeno 24×24 px, nascosto con `prefers-reduced-motion` (+11).
- **Rischio**: medio. È un elemento nuovo nel banner: da guardare su telefono e nell'anteprima dell'admin, che usa lo stesso script.
- **Impegno**: M
- **Verifica**: letto riga per riga; non provato su un telefono. Seguito del §1.7 di `docs/audit-moduli.md`.

#### SEO-6 · SEO → Contenuti manda il testo intero di ogni contenuto; in errore la scheda resta senza il selettore del tipo

- **Area**: SEO, REST e tabella Contenuti · prestazioni
- **Prova**: `'posts_per_page' => -1` su cinque stati ([RestContents.php:81-89](modules/seo/src/Api/RestContents.php#L81-L89)), e per ogni riga `Editor::post_page( $post )`, che contiene `'content' => $post->post_content` ([Editor.php:120](modules/seo/src/Editor.php#L120)). In errore la pagina mostra solo l'avviso (`return notice ? <Notice … /> : <Loading />;`, [Contents.js:198-200](modules/seo/assets/src/seo/Contents.js#L198-L200)), senza il Picker del tipo, e il tipo è già nell'URL ([160-167](modules/seo/assets/src/seo/Contents.js#L160-L167)).
- **Effetto**: misure di `docs/audit-seo.md` §5, non rifatte: 2.000 articoli da 9 KB = 18,6 MB di JSON. Oltre `memory_limit` la REST risponde 500; se il tipo che fallisce sono le pagine (il default), la scheda non si apre più.
- **Modifica minima**: (b) mostrare il Picker anche in errore, o spostare `replaceState` nel `then` (2 righe): toglie il vicolo cieco. (a) 200 righe a richiesta, unite nel browser (~10 righe).
- **Rischio**: basso per (b), medio per (a) · **Impegno**: S per (b), M per (a) · **Verifica**: letto riga per riga. Il payload è già in `docs/audit-seo.md` §4-§5; lo stato d'errore è nuovo.

### Gravità bassa

#### EXT-2 · GraphQL: `seo` e `hbSchemaRaw` leggono `$post->ID`, che su un nodo "restricted" è null

- **Area**: estensione WPGraphQL, SEO · sicurezza (da provare)
- **Prova**: `barrio_wpgraphql_seo( get_post( $post->ID ) )` ([wpgraphql/seo.php:76](extensions/barrio-suite-wpgraphql/seo.php#L76)) e `get_post( $node->ID )` ([riga 285](extensions/barrio-suite-wpgraphql/seo.php#L285)). In WPGraphQL 2.28.0 un nodo ristretto (bozza di un altro autore, contenuto con password) non ha `ID`, e `get_post( null )` restituisce il post globale, che WPGraphQL imposta sul contenuto del nodo: `seo` risponde comunque. `Meta` protegge solo i contenuti con password.
- **Effetto**: un Autore che chiede `post(id: N) { seo { description } }` sulla bozza di un altro riceve i primi 160 caratteri del testo, che WPGraphQL gli nasconde in ogni altro campo.
- **Modifica minima**: usare `databaseId` nei due punti e in `barrio_wpgraphql_seo()` rispondere null per un contenuto non pubblicato che chi chiede non può modificare (3 righe cambiate), più un caso da Autore nel test.
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto dall'agente nel sorgente di WPGraphQL installato in wp-env; non verificato a runtime: serve una richiesta GraphQL da Autore prima di correggere.

#### EXT-3 · `hbSubmitForm`: nessun limite di invii per qualsiasi utente collegato

- **Area**: estensione WPGraphQL, form · sicurezza
- **Prova**: `$ip = is_user_logged_in() ? null : …` ([wpgraphql/forms.php:362](extensions/barrio-suite-wpgraphql/forms.php#L362)); senza IP `Submission::spam()` salta il limite ([Submission.php:149](modules/forms/src/Submission/Submission.php#L149)), e `_barrio_js` è forzato a `'1'` (riga 357).
- **Effetto**: la decisione pensa al frontend con l'application password, ma il codice esenta chiunque abbia fatto login, anche un Sottoscrittore; con reCAPTCHA ogni token finto è una chiamata a Google.
- **Modifica minima**: `current_user_can( 'edit_posts' )` al posto di `is_user_logged_in()` (1 riga), e dirlo in `docs/hooks.md`.
- **Rischio**: medio. L'utente dell'application password dei frontend esistenti deve avere quella capability: va controllato sito per sito.
- **Impegno**: S · **Verifica**: letto riga per riga.

#### EXT-4 · `hbSitemap` è pubblico, senza cache, e rilegge tutti gli URL a ogni richiesta

- **Area**: estensione WPGraphQL, SEO · prestazioni
- **Prova**: il campo è registrato senza permessi ([wpgraphql/seo.php:151-159](extensions/barrio-suite-wpgraphql/seo.php#L151-L159)) e chiama `Sitemaps::each()` a ogni risoluzione; con gli alias GraphQL si può chiedere più volte nella stessa richiesta.
- **Effetto**: le misure di `docs/audit-seo.md` §5 (0,4 secondi e 87 MB con 5.000 articoli, non rifatte) valgono per ogni richiesta anonima.
- **Modifica minima**: un transient svuotato con gli hook di llms.txt (~10 righe); per i soli alias un `static $entries` (+3).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga. Già in `docs/audit-seo.md` §5.

#### FORMS-7 · Un'estensione di file che WordPress non ammette si salva senza avviso e sul sito sparisce

- **Area**: Forms, campo file · UX
- **Prova**: lo schema controlla solo la forma (`'pattern' => '^[a-z0-9]+$'`, [FieldTypes.php:136](modules/forms/src/FieldTypes.php#L136)); `FileStore::extensions()` scarta in silenzio quelle fuori da `get_allowed_mime_types()` ([FileStore.php:83-84](modules/forms/src/Submission/FileStore.php#L83-L84)).
- **Effetto**: chi aggiunge `dwg` accanto a `pdf` salva senza errori, ma il sito dice "Accepted formats: PDF." e rifiuta il .dwg. Con la sola `dwg` nessun file si può caricare.
- **Modifica minima**: in `FieldTypes::check()`, per il tipo `file`, un errore con il nome dell'estensione non ammessa (+4).
- **Rischio**: medio. Un form già salvato o migrato con un'estensione non ammessa non si salva più finché non la si toglie.
- **Impegno**: S · **Verifica**: letto riga per riga.

#### FORMS-12 · La versione appena sostituita si elimina se non ha registrazioni

- **Area**: Forms, versioni e cache di pagina · presupposto
- **Prova**: `DELETE IGNORE FROM %i WHERE form_id = %d AND version <= %d` ([FormRepository.php:95](modules/forms/src/Repository/FormRepository.php#L95)) elimina anche la versione appena sostituita, se nessuna registrazione la usa; allora vale la corrente (`FormRepository::version( $shown, $form->id ) ?? $version`, [Submission.php:49-51](modules/forms/src/Submission/Submission.php#L49-L51)). PLAN.md riga 271: la versione letta dal visitatore "è quella che la registrazione deve provare".
- **Effetto**: con la cache di pagina e una versione senza registrazioni, dopo aver aggiunto un campo obbligatorio chi ha la pagina vecchia riceve "Please correct the highlighted fields." senza campi evidenziati; se sono cambiati solo i testi, la prova conservata mostra un consenso che il visitatore non ha letto.
- **Modifica minima**: (a) in `failure()` di `form.js`, se arrivano errori per campi che la pagina non ha, chiedere di ricaricare (+4 e una stringa). (b) `version < %d` tiene la versione sostituita fino al salvataggio dopo (1 carattere): da decidere, perché porta colonne vuote in Registrazioni.
- **Rischio**: basso per (a), medio per (b) · **Impegno**: S · **Verifica**: letto riga per riga; non riprodotto con una cache.

#### CONT-11 · Con WPML la modale Riordina elenca insieme le voci di tutte le lingue

- **Area**: Ordinamento · UX (non verificato con WPML)
- **Prova**: [OrderRepository.php:25-38](modules/ordering/src/OrderRepository.php#L25-L38) e [45-62](modules/ordering/src/OrderRepository.php#L45-L62) leggono con SQL diretto su `wp_posts` e `wp_terms`, senza i filtri delle query su cui WPML aggiunge la lingua. Nessuna estensione tocca l'ordinamento tranne la migrazione.
- **Effetto**: sito in due lingue con 40 pagine l'una: la modale ne mostra 80 mescolate, senza dire la lingua. L'ordine di ogni lingua resta giusto.
- **Modifica minima**: dopo la prova con WPML, un `apply_filters` sull'elenco di `Module::items()` e ~10 righe nell'estensione WPML per tenere la lingua corrente.
- **Rischio**: medio, senza WPML installato non si prova · **Impegno**: M · **Verifica**: codice letto; già in `docs/audit-moduli.md` §4.

#### CONT-12 · Con un solo blocco spento, il ruolo perde tutti i blocchi registrati solo in JavaScript

- **Area**: Blocchi · presupposto ancora da contare
- **Prova**: con `$allowed === true` l'elenco ammesso diventa il registro PHP meno gli spenti ([blocks/Module.php:169-176](modules/blocks/src/Module.php#L169-L176)); un blocco registrato solo con `registerBlockType()` non è nel registro. Lo dice il commento sopra il metodo, e PLAN-MODULI §11 chiede di contarli sui siti dei clienti: non è stato fatto.
- **Effetto**: su un sito con un tema che registra i blocchi solo in JS, spegnere un blocco per gli editor fa sparire dall'inseritore anche tutti quelli del tema.
- **Modifica minima**: prima di usare il modulo su un sito, contare i blocchi solo JS (nell'editor, `wp.blocks.getBlockTypes()` meno `GET /wp/v2/block-types`). Solo se ce ne sono: spegnere i blocchi in JavaScript con `supports.inserter: false` (~15 righe, e `allowed()` si toglie).
- **Rischio**: medio per l'alternativa · **Impegno**: S (conteggio), M (alternativa) · **Verifica**: letto riga per riga; già in `docs/audit-moduli.md` §4.

## 3. Togliere o semplificare

### Gravità media

#### X-1 · Il bottone Salva è scritto nove volte uguale

- **Area**: admin React, tutte le pagine · duplicato
- **Prova**: lo stesso blocco di 13 righe (un `<button>` con `className="barrio-button is-primary"`, `disabled` e `onClick`, poi `<Lucide name="save" />` e il testo che passa da "Save" a "Saving…" con `busy`) in [General.js:255-267](assets/src/general/General.js#L255-L267), [Popups.js:409-421](modules/popups/assets/src/Popups.js#L409-L421), [Banners.js:274-286](modules/banners/assets/src/Banners.js#L274-L286), [forms/Settings.js:170-182](modules/forms/assets/src/settings/Settings.js#L170-L182), [Builder.js:200-212](modules/forms/assets/src/builder/Builder.js#L200-L212), [SettingsPage.js:215-227](modules/seo/assets/src/seo/SettingsPage.js#L215-L227), [seo/Contents.js:109-121](modules/seo/assets/src/seo/Contents.js#L109-L121), [Redirects.js:99-112](modules/seo/assets/src/seo/Redirects.js#L99-L112) e [reorder.js:142-154](modules/ordering/assets/src/reorder.js#L142-L154). Cambiano solo `disabled`, `onClick` e, in un caso, `type="submit" form={ form }`. In `ui.js` non c'è un componente per questo. Anche la conferma "Discard the changes?" è scritta quattro volte (`Alt.js:126`, `reorder.js:95`, `Contents.js:79`, `Redirects.js:51`).
- **Modifica minima**: `SaveButton( { busy, disabled, ...props } )` in `assets/src/admin/ui.js` (+14) e una riga per pagina: circa −95 / +25.
- **Rischio**: basso, stesso markup · **Impegno**: S · **Verifica**: mio controllo meccanico dei blocchi ripetuti (`mech.py dup`) e lettura dei nove punti.

#### UI-13 · `index.js`: la scheda "Settings" di un modulo è scritta cinque volte uguale

- **Area**: admin, registro delle pagine · semplificare
- **Prova**: cinque blocchi identici che cambiano solo per l'array delle sezioni, alle righe [77-86](assets/src/admin/index.js#L77-L86), 134-143, 159-168, 184-193 e 218-227: `window.barrioAdmin.canManageOptions && [`, poi `'settings'`, il titolo "Settings" e una funzione che rende `<General>` con il titolo e le sezioni.
- **Modifica minima**: `const settingsTab = ( ...sections ) => window.barrioAdmin.canManageOptions && [ … ]`, chiamata una volta per pagina (+12 / −55). `sections` nasce una volta alla valutazione del modulo, quindi resta stabile come chiede `General`.
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

### Gravità bassa

#### CONT-15 · `Contents.js` scrive tre volte la stessa voce

- **Area**: admin, pagina Contenuti · semplificare
- **Prova**: [Contents.js:38-87](assets/src/contents/Contents.js#L38-L87): tre costanti `*_SECTIONS` e tre blocchi di 14 righe che cambiano solo per il nome (`blocks`, `ordering`, `duplicate`).
- **Modifica minima**: una funzione `tool( data, id, module, section, stats )` che restituisce `{ tab, stats }` e tre righe che la chiamano (+12 / −44).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### UI-12 · `admin.css`: dichiarazioni ripetute e colori senza variabile

- **Area**: admin, CSS · semplificare
- **Prova**: `.barrio-filters__clear` ridichiara ciò che `.barrio-button` dà già ([admin.css:2013-2024](assets/src/admin/admin.css#L2013-L2024); il bottone ha già quella classe, [ui.js:1128](assets/src/admin/ui.js#L1128)); i titoli di sezione hanno le stesse 6 dichiarazioni in [887-894](assets/src/admin/admin.css#L887-L894) e [1052-1062](assets/src/admin/admin.css#L1052-L1062); `overflow-x: hidden; overflow-y: auto; overscroll-behavior: none` quattro volte (1014, 1945, 1984, 2248); colori letterali ripetuti: `#c3c4c7` 9 volte, `#edfaef`, `#1a7f37` e `#fcf0f1` 4 volte l'uno.
- **Modifica minima**: selettori comuni per i primi punti (circa −40 / +10, senza toccare il JS) e tre variabili su `:root` per i colori (+3, 17 righe cambiate). Va insieme a UI-4.
- **Rischio**: basso, stessa cascata · **Impegno**: M · **Verifica**: righe 2013-2024 rilette; il resto è il conteggio dell'agente.

#### PBH-8 · Il controllo dell'inizio nel browser non può mai scattare

- **Area**: Popup e Banner, script del sito · rimuovere
- **Prova**: il server stampa solo ciò che è già attivo (`if ( 'active' === self::status( $item ) )`, [PeriodRepository.php:62](src/Database/PeriodRepository.php#L62); `Period::status()` dà `scheduled` finché `starts_at > now`, [Period.php:31](src/Period.php#L31)). Quindi l'inizio che arriva al browser è sempre passato, eppure viaggia e si controlla: `'start' => …` ([popups/Module.php:83](modules/popups/src/Module.php#L83)), `( ! start || now >= start * 1000 )` ([popup.js:22](modules/popups/assets/frontend/popup.js#L22)), `data-start` ([banners/Module.php:85-91](modules/banners/src/Module.php#L85-L91)) e `now < Number( banner.dataset.start ?? 0 )` ([banner.js:105](modules/banners/assets/frontend/banner.js#L105)). Con la cache di pagina può essere vecchia la fine, non l'inizio. In più `STATUS` e `periodText` in [period.js:35](assets/src/admin/period.js#L35) e [46](assets/src/admin/period.js#L46) sono esportati ma nessuno li importa.
- **Effetto**: codice e test per uno stato che non esiste; scatta solo con l'orologio del visitatore indietro, e allora nasconde ciò che andava mostrato.
- **Modifica minima**: togliere `start` dalle regole del popup, `data-start` dal banner, le due condizioni nel JS e adeguare i tre test; togliere `export` dai due nomi (+2 / −14). In GraphQL `startsAt` resta.
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### FADM-8 · Piccoli duplicati nell'admin dei form

- **Area**: Forms, admin React · duplicato
- **Prova**: la stessa `( error ) => setNotice( { status: 'error', message: errorMessage( error ) } )` scritta tre volte in [Settings.js:55-57](modules/forms/assets/src/settings/Settings.js#L55-L57), 79-81 e 144-146 (gli altri file la chiamano `fail`); [Entry.js:110-114](modules/forms/assets/src/entries/Entry.js#L110-L114) riscrive la chiamata di `bulk` definita a [98-103](modules/forms/assets/src/entries/Entry.js#L98-L103); `is_read` si scrive a [Entry.js:108](modules/forms/assets/src/entries/Entry.js#L108) e non si legge più; `orNull` in [Builder.js:39](modules/forms/assets/src/builder/Builder.js#L39) è la stessa espressione di [Texts.js:37](modules/forms/assets/src/builder/Texts.js#L37); [Forms.js:345](modules/forms/assets/src/forms/Forms.js#L345) riscrive lo shortcode di [ui.js:22](modules/forms/assets/src/ui.js#L22).
- **Modifica minima**: `fail` una volta in `Settings.js` (−6); `bulk( 'read', … )` e `setEntry( data )` in `Entry.js` (−5); una funzione `shortcode( id )` (+1 / −1).
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto riga per riga.

#### CORE-10 · `Admin::contents_tab()` ha un parametro che riceve sempre lo stesso valore

- **Area**: core, menu admin · semplificare
- **Prova**: `public static function contents_tab( string $capability )` con un commento di cinque righe sul meccanismo "una capability per scheda" ([Admin.php:38-47](src/Admin.php#L38-L47)); i tre chiamanti passano tutti `'manage_options'` (`grep -rn contents_tab src modules`: blocks 103, duplicate 67, ordering 58). L'unico altro valore è in un test.
- **Modifica minima**: togliere il parametro e mettere `'manage_options'` dentro (+2 / −8 tra `Admin.php`, i tre moduli e il test).
- **Rischio**: basso · **Impegno**: S · **Verifica**: grep indicato.

#### EXT-10 · Estensione di migrazione: tre regole scritte due volte

- **Area**: estensione di migrazione · duplicato
- **Prova**: `$exists` in [migration/forms.php:116](extensions/barrio-suite-migration/forms.php#L116) è `barrio_migration_table_exists()` di `barrio-suite-migration.php:218-221`; `barrio_migration_seo_prototype()` ([seo.php:59-61](extensions/barrio-suite-migration/seo.php#L59-L61)) è `barrio_migration_prototype( 'seo' )`; la normalizzazione del codice lingua di `barrio-suite-migration.php:234-235` è `barrio_migration_lang()` di `forms.php:847-850`.
- **Modifica minima**: usare le tre funzioni che ci sono già (+3 / −9).
- **Rischio**: basso · **Impegno**: S · **Verifica**: le prime due rilette; la terza è dell'agente.

#### PBH-10 · `created_at` e `updated_at` di popup e banner si scrivono e non si leggono mai

- **Area**: Popup e Banner, Repository · rimuovere
- **Prova**: `grep -rn "created_at\|updated_at"` su `modules/popups`, `modules/banners`, `PeriodRepository.php`, `period.js` e i due file GraphQL trova solo le colonne, le scritture e la lettura in `hydrate()` ([PopupRepository.php:214-215](modules/popups/src/PopupRepository.php#L214-L215), [BannerRepository.php:156-157](modules/banners/src/BannerRepository.php#L156-L157)). La REST non le manda, l'elenco non ha una colonna, "il più recente" è `ORDER BY id DESC`.
- **Modifica minima**: togliere le due chiavi da `hydrate()` e dai tipi (−4), lasciando le colonne. Oppure usarle: una colonna "Modified" nell'elenco (+6), che aiuta anche PBH-6.
- **Rischio**: basso, PHPStan segnala un uso dimenticato · **Impegno**: S · **Verifica**: grep indicato.

#### FADM-9 · I testi per lingua di tutta la suite stanno nel builder dei form

- **Area**: admin React, core e moduli · architettura
- **Prova**: [period.js:8-12](assets/src/admin/period.js#L8-L12), [Popups.js:32-36](modules/popups/assets/src/Popups.js#L32-L36) e [Banners.js:31-32](modules/banners/assets/src/Banners.js#L31-L32) importano `TextIn` da `modules/forms/assets/src/builder/Texts.js` e `textIn`/`setTranslation` da `…/builder/model.js`. `withTranslation` ha il caso speciale `options` delle scelte dei form ([model.js:83-104](modules/forms/assets/src/builder/model.js#L83-L104)).
- **Effetto**: il core dipende da un modulo; chi cambia `withTranslation` per i form cambia senza saperlo popup, banner e periodi, che `tests/js/builder.test.js` non copre. Nessun effetto a runtime (un solo bundle).
- **Modifica minima**: spostare `Texts.js` e le tre funzioni in `assets/src/admin/` e aggiornare gli import (circa 8 righe cambiate).
- **Rischio**: basso · **Impegno**: S · **Verifica**: import trovati con `grep -rn "from '.*forms/assets" modules assets/src`.

#### PBH-11 · Popup e Banner: cosa resta doppio dopo `PeriodRepository` e `PeriodList`

- **Area**: Popup e Banner · duplicato (misura, nessuna modifica urgente)
- **Prova**: il §3.1 di `docs/audit-moduli.md` è stato applicato in gran parte. Righe uguali dopo aver uniformato i nomi, oggi e nell'audit di stamattina: `Popups.js`/`Banners.js` 341 (erano 616), i due Repository 134 (erano 192), i due `Module.php` 221 (erano 220). Ciò che resta di logica vera scritta due volte è l'editor: stato e "modificato" ([Popups.js:310-337](modules/popups/assets/src/Popups.js#L310-L337) ↔ [Banners.js:186-213](modules/banners/assets/src/Banners.js#L186-L213)), salvataggio ([357-385](modules/popups/assets/src/Popups.js#L357-L385) ↔ [223-251](modules/banners/assets/src/Banners.js#L223-L251)), barra e schede ([388-431](modules/popups/assets/src/Popups.js#L388-L431) ↔ [253-296](modules/banners/assets/src/Banners.js#L253-L296)). Il mio controllo meccanico (blocchi di 8 righe uguali) trova 47 righe tra i due JS e 42 tra i due `Module.php`.
- **Modifica minima**: un hook `usePeriodEditor( name, id, blank )` in `period.js` (+67 / −106, circa −40 righe). Conviene quando si rimette mano a quella logica, non da solo. Le rotte dei due `Module` aspettano un terzo modulo, come già deciso.
- **Rischio**: medio, tocca il salvataggio di due pagine · **Impegno**: M · **Verifica**: misura dell'agente con uno script (LCS); la mia con `mech.py dup`.

#### CORE-12 · Lo zip contiene i sorgenti JavaScript accanto alla build

- **Area**: pacchetto · rimuovere (da confermare)
- **Prova**: `"assets/src"` e `"modules/*/assets/src"` in `files` di [package.json](package.json), accanto a `"build"`; nessun file PHP li legge. Erano stati aggiunti dopo `docs/audit.md` §2, insieme al link al repository nel readme.
- **Effetto**: ogni sito scarica e tiene circa 0,6 MB di file che WordPress non usa.
- **Modifica minima**: togliere le due righe e la frase del readme che rimanda alle cartelle dello zip, lasciando il link (+1 / −3).
- **Rischio**: basso, ma va confermato che per la revisione di WordPress.org basti il link al repository (regola citata a memoria). Se si preferisce lo zip ricostruibile, resta com'è.
- **Impegno**: S · **Verifica**: letto; dimensioni misurate dall'agente con `du`.

## 4. Miglioramenti

Interfaccia, accessibilità, prestazioni, traduzioni, test e architettura: niente di rotto, ma vale la pena.

### Gravità media

#### UI-4 · Contrasto: bordo dei campi e interruttore spento a 1,74:1

- **Area**: admin, CSS · accessibilità
- **Prova**: `border: 1px solid #c3c4c7;` sui campi bianchi su card bianca ([admin.css:367](assets/src/admin/admin.css#L367)) e `background: #c3c4c7;` per l'interruttore spento, senza bordo ([admin.css:486-488](assets/src/admin/admin.css#L486-L488)): `#c3c4c7` su `#ffffff` è 1,74:1, mentre per il bordo che identifica un controllo servono 3:1 (WCAG 1.4.11). Il core usa `#949494` (3,03:1). Il rosso `#d63638` ([admin.css:19](assets/src/admin/admin.css#L19)) su `#fcf0f1` al passaggio del mouse ([235-237](assets/src/admin/admin.css#L235-L237)) è 4,25:1, sotto i 4,5:1 del testo.
- **Effetto**: chi vede poco distingue a fatica dove sono i campi e se un interruttore spento c'è.
- **Modifica minima**: una variabile `--barrio-border` al posto dei 9 `#c3c4c7` letterali, con `#949494` almeno per campi e interruttore; per il rosso al passaggio `color: #b32d2e` (5,67:1). +3 righe, 9 cambiate.
- **Rischio**: medio. Il bordo di tutti i campi diventa più scuro: va guardato a schermo.
- **Impegno**: S
- **Verifica**: rapporti ricalcolati con `node` (formula WCAG): 1,74 · 3,03 · 4,25 · 5,67.

#### TEST-3 · La CI prova solo PHP 8.2, l'ultimo WordPress e il sito singolo

- **Area**: CI · test
- **Prova**: `php-version: '8.2'` e `https://wordpress.org/latest.tar.gz` in [ci.yml](.github/workflows/ci.yml); la matrice ha solo `db` e `graphql` ([righe 39-43](.github/workflows/ci.yml#L39-L43)). I requisiti dichiarati sono WordPress 6.9 e PHP 8.2. `grep -rn -i "multisite" tests .github phpunit.xml.dist` trova solo un messaggio, mentre [Uninstall.php:57-73](src/Uninstall.php#L57-L73) ha un ramo multisite che fa `switch_to_blog()` e poi `DROP TABLE`.
- **Effetto**: "WordPress 6.9+" non è mai stato eseguito (oggi nessuna funzione `@since 7.x` è usata: l'agente l'ha cercato tra 347 nomi, ma niente lo tiene così). Il codice più distruttivo del core, la cancellazione dei dati sito per sito, non ha mai girato in un test.
- **Modifica minima**: tre voci `include:` nella matrice (WordPress 6.9, PHP 8.4, `WP_MULTISITE: 1`), +12 / −2, e un test multisite in `UninstallTest` (~15 righe).
- **Rischio**: medio. `wp-phpunit ^7.1` con il core 6.9 può non partire: quel job può avere bisogno della sua versione.
- **Impegno**: M · **Verifica**: letto riga per riga. Il minimo mai provato è già in `docs/audit.md` §2.

#### FADM-2 · Il builder non controlla niente prima di salvare: gli errori sono i messaggi tecnici della REST

- **Area**: Forms, builder · UX
- **Prova**: per un form nuovo Salva è sempre attivo: `disabled={ !! busy || ( form.id && ! dirty ) }` ([Builder.js:203](modules/forms/assets/src/builder/Builder.js#L203)). L'errore mostrato è `Object.values( error.data.params ).join( ' ' )` ([ui.js:24-27](assets/src/admin/ui.js#L24-L27)), cioè il testo di WordPress con il percorso del parametro. Lo schema chiede almeno un carattere per il titolo ([RestForms.php:275](modules/forms/src/Api/RestForms.php#L275)), `^(/|https?://)` per la pagina dopo l'invio (riga 280) e `^[a-z][a-z0-9_]*$` per la chiave (riga 307). Il campo Chiave accetta qualsiasi testo ([Fields.js:558](modules/forms/assets/src/builder/Fields.js#L558)). La chiave doppia risponde in inglese non traducibile: `"Field key '{$field->key}' is used twice."` ([FormRepository.php:444](modules/forms/src/Repository/FormRepository.php#L444)).
- **Effetto**: form senza nome → "title must be at least 1 character long."; chiave "Nome Azienda" → "fields[2][key] does not match pattern ^[a-z][a-z0-9_]*$.". Chi usa il builder non sa quale campo correggere. I messaggi d'esempio sono ricavati dal codice, non da una richiesta vera.
- **Modifica minima**: (1) `disabled={ !! busy || ! dirty }` (1 riga, torna la regola "Salva attivo solo con modifiche"); (2) in `save` un avviso leggibile se il nome è vuoto (+6); (3) nel campo Chiave un `help` di errore se la chiave non rispetta il pattern o è già usata, `FieldPanel` riceve già `keys` (+6).
- **Rischio**: basso. Solo controlli in più prima della richiesta.
- **Impegno**: M
- **Verifica**: letto riga per riga.

#### LIVE-4 · Builder sotto i 1100 px: scelto un campo, le sue proprietà restano fuori schermo

- **Area**: Forms, builder · UX
- **Prova**: sotto i 1100 px `.barrio-fields { grid-template-columns: minmax(0, 1fr); }` ([admin.css:1468-1472](assets/src/admin/admin.css#L1468-L1472)) mette il pannello delle proprietà sotto l'elenco; `onSelect={ () => setSelected( i ) }` ([Fields.js:238](modules/forms/assets/src/builder/Fields.js#L238)) cambia solo il contenuto del pannello, e l'unico scorrimento è per i campi appena aggiunti ([Fields.js:285](modules/forms/assets/src/builder/Fields.js#L285)).
- **Effetto**: a 360 px e a 1000 px (tablet, finestra a mezzo schermo) il tocco su un campo sembra non fare niente: il pannello cambia ma è sotto il fondo dello schermo e la pagina non scorre.
- **Modifica minima**: quando un campo viene scelto a mano, portare in vista il pannello con `useScrollIntoView` e la classe `barrio-scroll-target` (+9 / −2).
- **Rischio**: medio. È una scelta d'uso: sul telefono ogni tocco su un campo porta via dall'elenco. Da provare a mano prima di tenerla.
- **Impegno**: S · **Verifica**: riprodotto nel browser a 360×740 e 1000×800; la correzione non è stata provata.

### Gravità bassa

#### FORMS-10 · Errori di salvataggio del form in inglese fisso, con `&#039;` visibile

- **Area**: Forms, REST · i18n (stessa famiglia di FADM-2)
- **Prova**: `return 'config[pattern] is not a valid regular expression.';` ([FieldTypes.php:179](modules/forms/src/FieldTypes.php#L179)); `throw new InvalidArgumentException( esc_html( "Field key '{$field->key}' is used twice." ) );` ([FormRepository.php:444](modules/forms/src/Repository/FormRepository.php#L444)); il messaggio va nel JSON così com'è ([RestForms.php:369](modules/forms/src/Api/RestForms.php#L369)) e il builder lo mostra come testo. `esc_html()` trasforma gli apici in `&#039;`.
- **Effetto**: su un sito in italiano compare `Field &#039;cap&#039;: config[pattern] is not a valid regular expression.`
- **Modifica minima**: `wp_specialchars_decode( $e->getMessage(), ENT_QUOTES )` in `RestForms.php:369` (1 riga) e `__()` sulle tre stringhe raggiungibili dall'interfaccia (+6 / −3), poi rigenerare l'italiano.
- **Rischio**: basso · **Impegno**: M (per le traduzioni sui due branch) · **Verifica**: letto riga per riga.

#### FADM-4 · Nel builder togliere un campo o una scelta fa perdere il focus

- **Area**: Forms, builder · accessibilità
- **Prova**: `grep -rn "focus(" modules/forms/assets/src` non trova niente. [Fields.js:746-752](modules/forms/assets/src/builder/Fields.js#L746-L752): quando cambia il numero di righe tutti gli id ripartono (`const fresh = Array.from( { length }, newId );`), e [sortable.js:132](assets/src/admin/sortable.js#L132) li usa come `key`: a ogni aggiunta o rimozione React rimonta tutte le card, compreso il bottone appena premuto.
- **Effetto**: da tastiera, dopo "Remove field" o "Remove choice" il focus torna al `body`; con uno screen reader niente dice che il campo è stato tolto o aggiunto.
- **Modifica minima**: dopo la rimozione spostare il focus sul contenitore dell'elenco (`ref` + `tabIndex={ -1 }`) e annunciare con `speak()`; `autoFocus` sul primo campo delle proprietà di un campo appena aggiunto (+12).
- **Rischio**: basso · **Impegno**: M · **Verifica**: letto riga per riga; non provato con uno screen reader.

#### FADM-5 · L'iframe dell'anteprima ha un `sandbox` che non isola

- **Area**: Forms, builder · sicurezza (difesa in più)
- **Prova**: [Builder.js:338-344](modules/forms/assets/src/builder/Builder.js#L338-L344): `sandbox="allow-scripts allow-same-origin allow-forms"` su un iframe `srcDoc`, usato per il form e per l'email. Con questi due permessi insieme uno script nel contenuto ha l'origine della pagina admin (nota della specifica HTML, citata a memoria). Oggi il contenuto è filtrato (`wp_kses_post` in [Notifications.php:163](modules/forms/src/Notifications.php#L163)).
- **Effetto**: nessuno finché ogni testo resta filtrato; è la seconda difesa che manca.
- **Modifica minima**: per l'anteprima dell'email, che non ha script, `sandbox="allow-same-origin"` (1 riga). Da provare: `fitContent` usa un `ResizeObserver` dell'iframe.
- **Rischio**: medio, dipende dal browser · **Impegno**: S · **Verifica**: letto riga per riga. Già in `docs/audit.md` §6.

#### FADM-7 · "Modifiche non salvate" scatta anche senza modifiche, nelle traduzioni

- **Area**: Forms, builder · qualità
- **Prova**: `const dirty = JSON.stringify( form ) !== JSON.stringify( saved );` ([Builder.js:92](modules/forms/assets/src/builder/Builder.js#L92)) conta anche l'ordine delle chiavi; `withTranslation` toglie un testo vuoto e, riscritto, lo rimette in fondo ([model.js:83-108](modules/forms/assets/src/builder/model.js#L83-L108)). [Builder.js:378-385](modules/forms/assets/src/builder/Builder.js#L378-L385) scrive `success_url: null` in ogni lingua, anche in quelle che non avevano la chiave.
- **Effetto**: svuotare e riscrivere uguale un'etichetta tradotta, o scegliere "Open another page" e tornare a "Show a message", lascia Salva attivo e fa comparire l'avviso del browser.
- **Modifica minima**: `'success_url' in text ? { ...text, success_url: null } : text` (1 riga); per l'ordine, un `JSON.stringify` con le chiavi ordinate (+4). Si può anche accettare: il salvataggio in più non fa danni.
- **Rischio**: basso · **Impegno**: S · **Verifica**: l'ordine delle chiavi riprodotto con `node`; il resto letto.

#### FADM-13 · Logica pura del builder senza test

- **Area**: Forms, test JS
- **Prova**: `tests/js/builder.test.js` importa solo `model.js`. Senza test: `presetRows` ([Fields.js:99-125](modules/forms/assets/src/builder/Fields.js#L99-L125)), `plain`/`ruleFields` ([Fields.js:60-63](modules/forms/assets/src/builder/Fields.js#L60-L63), [129-137](modules/forms/assets/src/builder/Fields.js#L129-L137)), `params`/`storedView` ([Entries.js:27-63](modules/forms/assets/src/entries/Entries.js#L27-L63)), perché stanno in file che importano `@wordpress/*`.
- **Effetto**: FADM-1 e FADM-7 sono errori di questa logica che i test non vedono.
- **Modifica minima**: spostare in `model.js` le funzioni senza React e aggiungere un test ciascuna (circa +25 di test).
- **Rischio**: basso · **Impegno**: M · **Verifica**: letto riga per riga.

#### CONT-14 · Il tetto di 5 MB verso Google e gli errori che la massa salta non hanno un test

- **Area**: Testi alternativi, test
- **Prova**: il limite è in [alt/Module.php:497-507](modules/alt/src/Module.php#L497-L507) (`$size <= self::MAX_BYTES`, poi `barrio_alt_size` e `barrio_alt_file`); `grep -rn "alt_size\|MAX_BYTES\|alt_file" tests` non trova niente. I codici sono anche un contratto con il JS ([Alt.js:59-64](modules/alt/assets/src/Alt.js#L59-L64)).
- **Effetto**: rinominare un codice o spostare il controllo del peso non fa fallire nessun test.
- **Modifica minima**: un test in `AltTest.php`: allegato senza file → `barrio_alt_file`; file oltre `MAX_BYTES` senza `large` → `barrio_alt_size` e nessuna richiesta a Google (+15).
- **Rischio**: basso · **Impegno**: S · **Verifica**: grep indicato.

#### UI-5 · `admin.css` senza `forced-colors` e `prefers-reduced-motion`

- **Area**: admin, CSS · accessibilità
- **Prova**: `grep -c 'prefers-reduced-motion\|forced-colors' assets/src/admin/admin.css` → 0. Il fuoco dei campi è `outline: none; box-shadow: 0 0 0 1px var(--barrio-ink);` ([admin.css:390-394](assets/src/admin/admin.css#L390-L394)): con i colori forzati `box-shadow` non si disegna. L'interruttore è fatto solo di `background`. `scrollIntoView( { behavior: 'smooth' … } )` senza condizione ([ui.js:107-110](assets/src/admin/ui.js#L107-L110)).
- **Effetto**: con il Contrasto elevato di Windows non si vede quale campo ha il fuoco né lo stato degli interruttori.
- **Modifica minima**: `outline: 2px solid transparent` al posto di `outline: none` (2 righe); un blocco `@media (forced-colors: active)` per l'interruttore (+11); `behavior` `'auto'` con `prefers-reduced-motion` (1 riga).
- **Rischio**: basso, fuori dai colori forzati non cambia nulla · **Impegno**: S · **Verifica**: letto riga per riga; non provato con i colori forzati.

#### UI-15 · 10 `!important` e copie di valori interni dei componenti di WordPress

- **Area**: admin, CSS · da riguardare a ogni versione maggiore di WordPress
- **Prova**: `grep -c '!important' assets/src/admin/admin.css` → 11 righe (10 dichiarazioni e un commento), su classi interne come `.components-base-control__label` e `.components-button.is-secondary`; altre regole cambiano `.components-modal__content` e `.components-tab-panel__tabs`. Le classi esistono ancora nella 7.1.4, ma il core si sta spostando sui token `--wpds-*` (vedi UI-2).
- **Modifica minima**: nessuna riga ora. Un commento sopra ciascuno dei cinque blocchi con la versione da cui vengono i valori (+5), e guardarli a schermo a ogni versione maggiore.
- **Rischio**: basso · **Impegno**: S · **Verifica**: letto e confrontato con il core 7.1.4, non con la 6.9.

#### CORE-8 · Un segreto salvato non si può più togliere: si può solo sostituire

- **Area**: core, Google e SMTP · sicurezza
- **Prova**: la rotta `/google` ha solo `GET` e `PUT`, con `service_account` `'required' => true` ([Google.php:44-48](src/Google.php#L44-L48)); per l'SMTP una password vuota lascia quella salvata ([Smtp.php:182](src/Mail/Smtp.php#L182)). In `src/` l'unico `delete_option` è quello della disinstallazione.
- **Effetto**: la chiave privata del service account e la password SMTP restano in `wp_options` finché il plugin non viene eliminato con "elimina i dati"; chi passa il sito a un altro fornitore non ha un modo dall'interfaccia.
- **Modifica minima**: `DELETE /barrio/v1/google` con "Remove key" accanto a "Replace key", e lo stesso per la password SMTP (+12 PHP e +10 JS l'uno).
- **Rischio**: basso · **Impegno**: M · **Verifica**: letto riga per riga.

#### TEST-9 · Un quarto del tempo dei test è l'attesa di un lock

- **Area**: test
- **Prova**: `SELECT GET_LOCK(%s, 5)` ([Migrator.php:44](src/Database/Migrator.php#L44)); `MigratorTest::test_does_nothing_while_another_connection_holds_the_lock` aspetta tutti i 5 secondi, su circa 20 dell'intera suite (misurati in questo audit: 20,4 s).
- **Modifica minima**: un parametro `$wait = 5` nel costruttore che il test porta a 0 (3 righe). Si può anche lasciare: sarebbe un parametro che solo il test imposta.
- **Rischio**: basso · **Impegno**: S · **Verifica**: tempo letto dall'agente nella cache di PHPUnit.

## 5. Presupposti che non reggono più

Frasi dei PLAN, commenti o regole che il codice di oggi contraddice. Dove serve una modifica al codice c'è il rimando alla voce.

| Id | Presupposto | Realtà (prova) | Cosa fare |
|---|---|---|---|
| PBH-9 | Il transient di popup e banner accesi si rilegge quando cambia la versione ([PeriodRepository.php:38](src/Database/PeriodRepository.php#L38)) | `BARRIO_SUITE_VERSION` è `0.1.0` e per decisione non sale: la correzione del §1.9 di `docs/audit-moduli.md` oggi non scatta mai | Niente codice. Finché la versione è ferma, ogni cambio di forma delle righe porta una migrazione con `delete_transient( 'barrio_popups_live' )`; scriverlo in `docs/database.md` (1 riga) |
| PBH-14 | "Nel sito nessuna query" per i popup ([popups/Module.php:16](modules/popups/src/Module.php#L16)) | Vale per scegliere il popup, non per stamparlo: l'immagine passa da `wp_get_attachment_image()` e il form nel testo da `do_shortcode()`. Il test conta solo `live()` e `shown()` | Correggere la frase |
| PBH-14 | PLAN-MODULI riga 101: "una funzione sola (`Popups::live()`)"; riga 185: misura "dopo il caricamento dei font (`document.fonts.ready`)"; `0001_popups.php:14`: `settings` con "apertura" | La funzione è `PeriodRepository::shown()`; `banner.js` rimisura con `ResizeObserver`; l'apertura è stata tolta dallo schema | Correggere le tre frasi |
| CONT-13 | PLAN-MODULI righe 622-623 e 637: un transient per periodo con la risposta intera, "due chiamate batch" | Un transient per parte (`md5( [ $property, $period['ranges'], $part ] )`, [analytics/Module.php:257](modules/analytics/src/Module.php#L257)) e tre parti, una chiamata a Google ciascuna | Correggere le due frasi: chi stima la quota conta 2 chiamate invece di 3 |
| FORMS-11 | "Su Nginx … (da segnalare nelle Impostazioni)" ([FileStore.php:98](modules/forms/src/Submission/FileStore.php#L98)) | `grep -rniE "nginx" src modules extensions assets` trova solo questa riga: l'avviso non esiste. Già in `docs/audit.md` §4 | Togliere la frase dal commento, o scrivere l'avviso |
| SEO-9 | [score.js:2](modules/seo/assets/src/score.js#L2): "Lo prova tests/js/score.test.js"; [seo/Admin.php:10](modules/seo/src/Admin.php#L10): "le schede Contenuti, Redirect e Impostazioni" | Il file non esiste: i test del punteggio sono in `tests/js/seo.test.js`; le schede sono quattro | Correggere le due frasi |
| SEO-9 | PLAN-SEO righe 203 e 418-419, [RestContents.php:49-50](modules/seo/src/Api/RestContents.php#L49-L50): "oltre ~2000 per tipo", "va misurata" | Il limite è nei byte (SEO-6); le due misure sono già in `docs/audit-seo.md` §4-§5 | Correggere le frasi |
| TEST-10 | PLAN.md riga 455: la rigenerazione delle traduzioni mappa `barrio-admin.js`, `seo-sidebar.js`, `seo-form.js` e "lo script del blocco" | Gli script con stringhe sono sette: anche `barrio-reorder.js`, `barrio-analytics.js` e un secondo blocco (Orari). Oggi i 7 JSON sono allineati | Elencare i sette script, o "tutti gli entry di `npm run build` e ogni `assets/block/index.js`" |
| CORE-13 | [docs/release.md:4-6](docs/release.md#L4-L6): "questi file, gli unici diversi tra i due branch" | `git diff --stat con-updater senza-updater` mostra anche `tests/Integration/ExtensionsTest.php` e le traduzioni dell'estensione di migrazione | Aggiungere le due voci all'elenco |
| EXT-11 | `docs/hooks.md` righe 80-82: "Interruttori, numeri e tendine si aggiungeranno quando un'estensione ne avrà bisogno" | Mailchimp dichiara già `enum` con `labels` ([barrio-suite-mailchimp.php:53-62](extensions/barrio-suite-mailchimp/barrio-suite-mailchimp.php#L53-L62)), che il builder trasforma in una tendina | Correggere la frase e documentare `enum` e `labels` |

## 6. Funzionalità suggerite

Sette funzioni piccole prese dai plugin che Barrio Suite vuole sostituire. Per ognuna: il plugin che la ha (controllato sulla sua documentazione il 20 novembre 2026), la prova che qui manca e quanto costa sopra ciò che c'è già. Nessuna è tra quelle che i PLAN hanno escluso.

| Id | Funzione | Da | Perché serve | Prova che manca | Dove e quanto |
|---|---|---|---|---|---|
| F-1 | URL canonico per contenuto | Yoast SEO e Rank Math (campo "Canonical URL" nella scheda avanzata) | È il campo che chi viene da quei plugin usa per i contenuti doppi o ripubblicati; senza, la migrazione da Yoast perde quei valori | `grep -rn "_barrio_seo_canonical" src modules extensions` → niente; `Module::META` ([seo/Module.php:23](modules/seo/src/Module.php#L23)) non lo contiene | Un meta in più, `Meta`, il filtro `get_canonical_url` e un campo nel pannello: ~25 righe. Da decidere: se un contenuto con il canonico altrove esce dalla sitemap |
| F-2 | Importare ed esportare i redirect in CSV | Redirection (Import/Export: CSV, .htaccess, Nginx, JSON) | Alla messa online di un sito rifatto la mappa vecchi → nuovi indirizzi arriva come foglio di calcolo; oggi si inseriscono uno a uno | `grep -niE "import\|export\|csv"` su `RestRedirects.php`, `RedirectRepository.php` e `Redirects.js` → niente | Esportazione ~15 righe (`RedirectRepository::all()` c'è, il CSV si scrive già in [RestEntries.php:347](modules/forms/src/Api/RestEntries.php#L347)); importazione ~40, passando ogni riga dalla validazione che c'è. È la più grande delle sette |
| F-3 | Avviso in admin quando l'ultima email del sito non è partita | WP Mail SMTP (avviso "Email delivery error" in bacheca) | L'SMTP vale per tutte le email del sito: dopo un cambio dei salt o una copia del sito la password diventa illeggibile e niente lo dice fuori da Barrio → Impostazioni | `wp_mail_failed` compare solo dentro `Smtp::send()` ([Smtp.php:246-250](src/Mail/Smtp.php#L246-L250)) | Ultimo errore in un transient e un `admin_notices` per gli amministratori, come fa già l'estensione reCAPTCHA: ~20 righe in `Smtp.php` |
| F-4 | "Non inviare email" per i siti di prova | WP Mail SMTP (interruttore "Do Not Send" e costante `WPMS_DO_NOT_SEND`) | Un sito copiato in staging con l'SMTP del cliente scrive ai clienti veri (notifiche dei form, reset della password) | `grep -rniE "pre_wp_mail\|do_not_send" src modules extensions` → niente | Un `Toggle` nella card SMTP e un filtro `pre_wp_mail`: ~12 righe PHP |
| F-5 | Indicatore del passaggio nei form a più passaggi ("Passaggio 2 di 3") | Gravity Forms (Progress Bar o Steps nelle impostazioni del Page Break), WPForms Pro (indicatore del Page Break) | Chi compila sa quanto manca; lo stesso testo annuncia il cambio di passaggio agli screen reader, che oggi non c'è | `grep -rniE "progress\|step.*of" modules/forms/assets/frontend modules/forms/src/Frontend` → solo `cursor: progress` | Un `<p aria-live="polite">` in `Render::html()`, una stringa nei messaggi e una riga in `go()` ([form.js:134](modules/forms/assets/frontend/form.js#L134)): ~10 righe |
| F-6 | Precompilare un campo visibile da un parametro dell'URL | Gravity Forms (popolamento dinamico dei campi), WPForms ("Enable Prefill By URL") | Link da newsletter o pagine prodotto che aprono il form con email o prodotto già scritti | `param` esiste solo per il tipo `hidden` ([FieldTypes.php:154](modules/forms/src/FieldTypes.php#L154), [303-304](modules/forms/src/FieldTypes.php#L303-L304)) | La stessa proprietà nello schema di `text`, `email`, `tel` e `number` e `data-param` nel rendering: ~8 righe. `form.js` compila già ogni `input[data-param]` e il server valida il valore come gli altri |
| F-7 | Dati `Article` per gli articoli | Yoast SEO (`article:published_time`, `article:modified_time` e un nodo `Article` con `datePublished` e `dateModified`) | È ciò che Google legge per data e autore degli articoli; oggi c'è solo `og:type` `article` ([Head.php:52](modules/seo/src/Head.php#L52)) e un `WebPage` con le date ([Schema.php:56-57](modules/seo/src/Schema.php#L56-L57)) | `grep -rn "'Article'\|article:published_time" src modules extensions` → niente | +2 righe in `Head.php` e ~12 in `Schema::graph()`, con le date già calcolate e gli `@id` esistenti |

Valutate e non proposte, perché non abbastanza utili o da decidere prima: i merge tag nel messaggio di conferma (Gravity Forms, WPForms: +2 righe, ma `hbForm.successMessage` mostrerebbe i tag grezzi ai frontend headless); le sorgenti del traffico nelle Statistiche (MonsterInsights); precedente e successivo nell'ordine manuale (Post Types Order: serve solo ai temi classici); "una volta per visita" per i popup (Popup Maker: cambia il significato di 0 giorni).

## 7. Decisioni rispettate, e quelle messe in dubbio

Le decisioni di PLAN.md, PLAN-SEO.md, PLAN-MODULI.md, PLAN-GRAPHQL.md e della memoria del progetto sono state prese come vincoli: estensioni separate per i servizi di terzi, i due branch con l'updater che resta fino all'approvazione di WordPress.org, versione ferma a 0.1.0, moduli accesi di default, database al centro, Popup e Banner separati, schema GraphQL `hb*` fino alla release, nessun tag di tracciamento, le regole fisse dell'interfaccia. Nessuna voce di questo file chiede di cambiarle. Tre punti le toccano, e lo dicono:

- **EXT-3**: l'esenzione dal limite per IP "per le richieste con un utente collegato" (`docs/hooks.md`) è più larga del caso per cui è nata, il frontend con l'application password.
- **CORE-12**: i sorgenti JavaScript nello zip erano la risposta al §2 di `docs/audit.md`; con il link al repository nel readme potrebbero non servire più. Da confermare con le regole di WordPress.org.
- **PBH-9**: la versione ferma a 0.1.0 rende inerte una correzione che contava sul cambio di versione. La decisione resta; serve la regola scritta accanto.

Una quarta voce tocca una scelta d'interfaccia: **LIVE-9** propone di lasciar andare a capo le descrizioni delle card della Bacheca sotto i 782 px, dove la riga unica chiesta il 19 novembre taglia quattro testi.

I verdetti "Da non toccare" dei tre audit precedenti restano validi. In particolare: i 22 `glob()` di `Plugin::boot()` (0,11 ms su un disco vero, misura di `docs/audit-moduli.md` §3; un memo di 4 righe in `Migrator::files()` li dimezzerebbe, ma non serve), lo schema "un'opzione, il suo JSON Schema, GET e PUT" ripetuto nei moduli, `fit()` e i `Dropdown` di WordPress (le alternative native non sono ancora disponibili ovunque: ricontrollato sulle guide di `.claude/skills/modern-web-guidance`), `Render::status()` in PHP e in `hours.js`.

## 8. Controllato e a posto

Da non rifare in un prossimo giro, salvo modifiche a quei file.

- **Strumenti** (eseguiti il 20 novembre 2026 sull'albero di lavoro): ESLint, stylelint, PHPCS e PHPStan livello 8 senza errori; 64 test JS; PHPUnit 544 test e 2.455 asserzioni (29 saltati senza WPGraphQL e Mailpit), 2.654 asserzioni con WPGraphQL (1 saltato). Nessun fallimento.
- **Codice morto**: nessuna classe `.barrio-*` dei 6 fogli di stile è senza riferimenti; nessun metodo PHP senza utilizzatori (le cinque `barrio_wpgraphql_*_boot()` che lo script segnala sono chiamate con il nome composto, `( "barrio_wpgraphql_{$barrio_module}_boot" )()`, in `barrio-suite-wpgraphql.php`). Sei `export` JS non hanno importatori e possono perdere la parola `export`: `periodText` (`period.js:46`, vedi PBH-8), `altData` (`Alt.js:39`), `Numbers` e `Chart` (`Analytics.js:262` e `317`), `passes` (`conditions.js:42`) e `toValue` (`model.js:4`, usato solo dal test).
- **I due branch**: `git diff con-updater senza-updater --stat -- . ':!languages'` mostra solo l'updater, la pagina Estensioni, `release.js`, `docs/release.md`, gli header `Update URI`, `package.json`, il readme e `ExtensionsTest.php` (vedi CORE-13).
- **Punti dei tre audit precedenti**: quasi tutti chiusi nel codice. Restano aperti quelli ripresi qui come SEO-6, EXT-4, CONT-11, CONT-12, CORE-6, FADM-5, FORMS-11, TEST-3.
- **Rotte REST**: tutte le 61 `register_rest_route` hanno un `permission_callback` e un test con il controllo 401/403 (conteggio fatto con uno script dall'agente dei test). L'unica pubblica in scrittura è `POST /forms/{id}/entries`, per decisione.
- **SQL**: ogni query dei Repository passa da `$wpdb->prepare()` o da pezzi già preparati; ordinamenti da whitelist; scritture su più tabelle in transazione.
- **Output nel sito**: `<title>`, meta, Open Graph, canonical e HTML di popup, banner e orari con `esc_*` su ogni valore; JSON-LD con `JSON_HEX_TAG`; una sola risoluzione dei meta in `Seo\Meta`.
- **Form pubblico**: honeypot, token JS, limite per IP con HMAC, nessun IP o user agent salvato; i merge tag non aggiungono destinatari; file con estensioni del campo ∩ tipi di WordPress, nome casuale, download solo con capability, eliminati con la registrazione; CSV a blocchi con le celle neutralizzate.
- **Segreti**: password SMTP e service account cifrati con `sodium_crypto_secretbox`, mai restituiti dalla REST né scritti nei log; la chiave Mailchimp non entra mai nei messaggi d'errore e il data center è validato (nessun SSRF).
- **Updater**: l'indirizzo dello zip è sempre `REPO/releases/latest/download/{slug}.zip` con lo slug validato, mai un indirizzo del catalogo; ogni azione ha la sua capability (salvo CORE-7); si agisce solo sui plugin con il nostro `Update URI`.
- **Disinstallazione**: `Uninstall.php` toglie tutto ciò che `src/`, `modules/` ed `extensions/` creano (opzioni `barrio_`, tabelle, capability, transient senza scadenza, meta, cartella degli upload, eventi di WP-Cron).
- **Traduzioni**: core 1.066 voci, 0 non tradotte, 0 fuzzy, 0 segnaposto diversi; 7 JSON per 7 script, nessuno orfano; 0 chiamate gettext con il dominio sbagliato; nessuna frase costruita per concatenazione. Unica eccezione EXT-7.
- **Accessibilità già a posto**: intestazioni ordinabili con `aria-sort` e bottone, selezione con `indeterminate`, menu con `role="menu"` e frecce, `Toggle` con `role="switch"`, avvisi annunciati con `speak()`, trascinamento da tastiera con annunci, `<dialog>` nativo per il popup, form del sito con etichette, `aria-invalid`, `aria-describedby` e focus sul primo errore.
- **Moduli**: l'ordine manuale non tocca ricerca, feed e query con un altro `orderby`; Duplica controlla nonce, `edit_post` e `create_posts`; i Testi alternativi non sovrascrivono mai un alt esistente; le Statistiche hanno capability e cache di un'ora su ogni rotta; i filtri del modulo Prestazioni esistono tutti nel core 7.1.4.
- **Nel browser** (Chrome, sito di prova, 1400×900 e 360×740, 34 pagine e schede admin): nessuna pagina esce in orizzontale, nemmeno a 320 px; con axe l'unica violazione dovuta al plugin è LIVE-7; tutti i bottoni, i campi e i select sono alti 36 px; una sola barra sticky per pagina e nessuna nella Bacheca; menu ⋯, pannello Filtri e nove modali su dieci fanno entrare il focus, si chiudono con Esc e lo rimettono su chi li ha aperti; le modali tengono intestazione e bottoni visibili con 20 px tra i campi; l'indicatore del focus si vede a ogni fermata del Tab; ordinare una colonna, cambiare scheda e riordinare un campo funzionano da tastiera.
- **Nel sito, senza login**: il popup è un `<dialog>` modale con il focus sulla X, il Tab resta dentro ed Esc chiude; il banner si ferma con il focus e con `prefers-reduced-motion`; il form ha etichette e `fieldset` con `legend`; gli orari sono tabelle con `caption`.
- **Estensioni**: senza il core o con il modulo spento nessuna dà errori; reCAPTCHA è chiuso quando Google non risponde e aperto, con avviso, senza chiave; la migrazione si può rilanciare (form per ID, transazione per form).

## 9. Non verificato

- I servizi veri: Google (Vertex AI, Places, GA4, reCAPTCHA), Mailchimp, GitHub Releases. PBH-4 dipende dalla forma di una risposta di Places che nessuno ha guardato.
- WPML, WooCommerce e ACF attivi; un frontend headless reale; EXT-2 con una richiesta GraphQL da Autore.
- WordPress 6.9 (il core letto è il 7.1.4 di wp-env), PHP oltre la 8.2, multisite, MySQL 8.0 (i test di questo giro sono su MariaDB), Nginx.
- Firefox, Safari, gli screen reader veri e il Contrasto elevato di Windows: le voci di accessibilità sono lette nel codice o misurate in Chrome (albero di accessibilità e axe).
- Nel browser non è stato provato niente che scrive o chiama un servizio (Salva, Elimina, Genera, Sincronizza, l'invio del form nel sito), né il pannello SEO nell'editor a blocchi, l'admin in italiano, i ruoli diversi dall'amministratore e un sito con più lingue.
- L'esito della CI su GitHub (TEST-1 è riprodotto in locale, TEST-2 solo letto).
- Il branch `senza-updater`: confrontato con `git diff`, non letto né provato.
- Plugin Check e `legacy-scan` di modern-web-guidance non sono stati rilanciati.
- Citati a memoria, senza rileggere la fonte: il comportamento di PHP oltre `post_max_size` (FORMS-1), di `\s` senza il flag `u` (FORMS-9), dei controlli con nome dentro un `<form>` (FORMS-4), il testo di WCAG 2.2.2 (PBH-3) e la regola di WordPress.org sui sorgenti (CORE-12).

## 10. Metodo

- Lettura completa del codice di produzione, divisa in nove aree lette in parallelo da altrettanti agenti: core e strumenti, Forms PHP e sito, Forms admin, SEO e Prestazioni, Popup/Banner/Orari, Blocchi/Ordinamento/Duplicazione/Testi alternativi/Statistiche, shell dell'admin, estensioni, test e traduzioni. Una decima passata ha guardato le pagine in un browser vero (Chrome con Playwright e axe-core, in sola lettura: gli script fermavano ogni richiesta che non fosse GET, tranne quattro anteprime che non scrivono). Aprire Statistiche e Impostazioni ha fatto leggere al server i dati da Google, come fanno quelle pagine da sole.
- Ogni segnalazione degli agenti è stata ricontrollata da chi ha scritto questo file aprendo le righe citate; dove bastava un comando, è stata riprodotta (FADM-1, CONT-2, SEO-1, TEST-1, UI-4, SEO-12). Le righe "Verifica" dicono quale delle due.
- Controlli meccanici su tutto l'albero: simboli senza utilizzatori, classi CSS senza riferimenti, blocchi di almeno 8 righe uguali tra due file.
- Niente è stato modificato nel repository, a parte questo file. Sul sito di prova (porta 8889) sono state fatte solo letture, quattro richieste `GET` a indirizzi inesistenti e i test del progetto.
- "Riprodotto" vuol dire eseguito davvero; "letto riga per riga" vuol dire letto, senza eseguirlo. Impegno: S meno di 30 minuti, M meno di 2 ore, L oltre.
