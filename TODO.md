# Barrio Suite: cosa resta aperto

Aggiornato il 25 novembre 2026. Tutte le fasi dei piani (Forms, SEO, gli otto moduli, lo schema GraphQL e Dati
aziendali, le aggiunte dal primo sito) sono nel codice, e gli audit sono chiusi. Qui resta solo ciò che chiede un sito
o un servizio vero, una scelta, o un lavoro nuovo. Le regole del progetto sono in
[docs/development.md](docs/development.md).

## Da decidere

| Area | Com'è oggi | La scelta |
|---|---|---|
| Pacchetto | Lo zip contiene i sorgenti JavaScript (`assets/src` e `modules/*/assets/src`): circa 0,6 MB che WordPress non usa | Toglierli se per WordPress.org basta il link al repository nel readme. Dopo la revisione in corso |
| reCAPTCHA | Con un form dentro il popup, lo script di Google è in tutte le pagine | Caricarlo al primo `focusin` su un form. Può abbassare i punteggi: si prova con una chiave vera |
| Estensioni su WordPress.org | Plugin Check segnala gli slug `barrio-suite-wpml` e `barrio-suite-wpgraphql` (il termine "wp") e `$barrio_module` nell'estensione WPGraphQL | Prima di mandarle, dopo l'approvazione del core |
| Token di revalidation | È salvato in chiaro e la card lo mostra, perché va copiato nel frontend ([hooks.md](docs/hooks.md#graphql)) | Cifrarlo come gli altri segreti, in sola scrittura: cambiano rotta, card e test |
| Chiamata al frontend | `wp_remote_get()` verso l'indirizzo scritto da un amministratore: raggiunge anche host privati (conta su un multisite) | `wp_safe_remote_get()`, che però blocca `localhost:3000` in sviluppo senza i filtri `http_allowed_safe_ports` e `http_request_host_is_external` |
| Card della Bacheca | L'azione principale di Contenuti e Prestazioni è "Settings", quella di Testi alternativi e SEO ha il nome della scheda, non della pagina | Rinominarle con il titolo del modulo, o precisare la regola Bottoni di [development.md](docs/development.md) |
| Moduli del prototipo non portati | Accessibilità, generatore di campi ACF (`auto-fields`), colonne, generatore di Privacy e Cookie Policy. Il cookie banner no: si fa con Google Tag Manager e CookieScript | Quali portare. Accessibilità usa rotte in `barrio/v1` come Barrio Suite: andrebbero scelte rotte diverse |

## Da provare su un sito o un servizio vero

Mai provati dagli audit: i servizi veri, WPML, WooCommerce e ACF attivi, un frontend headless reale, Nginx, Firefox,
Safari e gli screen reader veri.

- **Google**, con un service account vero:
  - Vertex AI: API attiva e ruolo Vertex AI User, l'endpoint `global`, la lista dei modelli, i tempi dei modelli che
    ragionano (timeout di 60 secondi, a volte oltre il `max_execution_time` di PHP), quali Gemini accettano immagini,
    i tempi per 500 immagini in massa;
  - Places: un Place ID vero, con l'API abilitata nel progetto, e lo schema degli orari al Rich Results Test;
  - GA4: il ruolo Visualizzatore deve bastare per la Data API e per `accountSummaries`; i token di una pagina
    (`returnPropertyQuota`; quota di 200.000 al giorno e 40.000 l'ora per proprietà); con fusi diversi tra proprietà
    e sito il limite "ieri" di Personalizzato può spostarsi di un giorno; con Google Signals le righe nascoste;
    `url_to_postid()` con i percorsi di WPML e del frontend headless;
  - reCAPTCHA Enterprise: una chiave del dominio e i punteggi. Se Google non risponde l'invio non passa (503).
- **Mailchimp**, con un'audience di prova: iscrizione con il double opt-in, tag di consenso, e i merge field di tipo
  data, che potrebbero rifiutare le date nel formato del sito.
- **Updater da GitHub** (`con-updater`): la prima release vera su un sito di prova, anche il giro notturno.
- **Migrazione su un sito reale**: con WPML le lingue delle registrazioni e le etichette delle checkbox;
  con un frontend headless le chiavi dei campi rinominate (un nome scritto a mano nel codice va aggiornato); gli
  shortcode nei file del tema, che la migrazione non tocca; `<head>` e GraphQL di cinque pagine uguali prima e dopo,
  tranne le correzioni previste. Di un prototipo modificato sul server sono ipotesi le chiavi dei contatti per sede
  (`sede_telefoni`, `sede_email`, `sede_op_telefoni`, `sede_op_email`), `multiple` nei campi e i valori della select
  multipla uniti da `|`: se sono diverse, gli avvisi della prova a vuoto le nominano. Se il frontend chiama GraphQL
  dal browser, il prototipo gli dava gli header CORS (`SUITE_BARRIO_GRAPHQL_ALLOWED_ORIGINS`), che Barrio Suite non
  ha. Le tabelle `wpb_*` del prototipo hanno le registrazioni vecchie con IP e user agent: si eliminano a mano, a
  verifica fatta. Un form interrotto dal tempo massimo (si prova con `set_time_limit( 1 )`) non deve lasciare copie
  dei file nella cartella protetta, e al rilancio deve passare.
- **Frontend headless**: su PHP-FPM chi salva un contenuto non deve aspettare la risposta del frontend
  (`fastcgi_finish_request()`), e gli altri plugin collegati a `shutdown` devono continuare a funzionare; con un
  token sbagliato la card deve mostrare l'errore 401 finché una chiamata non riesce.
- **WPML vero**: Ordinamento (la modale elenca le voci di tutte le lingue; decidere se copiare l'ordine alle
  traduzioni); l'alt per lingua con la traduzione dei media; `seo` e `hbSeoSchema` usano i testi predefiniti nella
  lingua della richiesta, non del contenuto. Per gli ultimi due serve un filtro della lingua di un contenuto
  nell'estensione WPML.
- **Hosting dei clienti**: versione di MySQL o MariaDB e InnoDB all'attivazione; dietro un proxy che non riscrive
  `REMOTE_ADDR` (Cloudflare senza `mod_remoteip`) il limite di invii per IP vale per tutto il sito (filtro
  `barrio_forms_rate_limit`); su Nginx la cartella protetta dei file senza `.htaccess`; `llms-full.txt` deve stare in
  `max_allowed_packet`, altrimenti si rigenera a ogni richiesta.
- **Temi dei clienti**: un tema senza `title-tag` stampa il suo `<title>`; nei temi classici senza `wp_body_open` il
  banner arriva da `wp_footer` e non deve coprire l'header; con la cache di pagina un popup o un banner nuovo si vede
  quando la cache scade.
- **Blocchi**: prima di usare il modulo su un sito, contare i blocchi registrati solo in JavaScript
  (`wp.blocks.getBlockTypes()` meno `GET /wp/v2/block-types`): con un solo blocco spento un ruolo li perde tutti.
  Sul prototipo, prima di migrare: con la lista di default un elenco non dovrebbe poter avere voci, e un paragrafo
  colorato da un amministratore dovrebbe essere "contenuto inatteso" per un editor.
- **Ordinamento**: nel Query Loop, negli ordini diversi da quello manuale resta la scelta del blocco; WPGraphQL chiede
  `name` di default per i termini; la lista gerarchica di `edit-tags.php` resta giusta.
- **Browser e accessibilità**: il `<dialog>` del popup su Safari e con VoiceOver; Firefox; il builder dei form dal
  telefono (sotto i 1100 px ogni tocco su un campo porta al pannello delle proprietà); a 360px, con una chiave Google
  collegata, "Sostituisci chiave" e "Rimuovi chiave" uno sotto l'altro dentro la card.
- **Altro**: PageSpeed Insights su un sito pubblico con Prestazioni acceso; il CSV delle registrazioni con l'Excel dei
  clienti; `legalName` e `taxID` al validatore di schema.org e al Rich Results Test; le tre proprietà sperimentali di
  `FormTokenField` nel blocco Dati aziendali, a ogni versione di WordPress.

## Da scrivere

- Le estensioni MailUp (la migrazione salva già le impostazioni per form) e Turnstile, con gli hook di reCAPTCHA.
- Il porting di `hello_standard` allo schema nuovo, in una sessione a parte e con il solo
  [foglio di porting](docs/graphql-porting.md): è anche la prova del foglio.

## Funzionalità suggerite

Proposte, non difetti: funzioni piccole dei plugin che Barrio Suite sostituisce. L'ultima colonna dice dove si scrivono
e quanto costano.

| Id | Funzione | Da | Perché serve | Dove e quanto |
|---|---|---|---|---|
| F-1 | URL canonico per contenuto | Yoast SEO, Rank Math | Per i contenuti doppi o ripubblicati; senza, la migrazione da Yoast perde quei valori | Un meta, `Meta`, il filtro `get_canonical_url` e un campo nel pannello: circa 25 righe. Da decidere se un contenuto con il canonico altrove esce dalla sitemap |
| F-2 | Importare ed esportare i redirect in CSV | Redirection | Alla messa online di un sito rifatto la mappa dei vecchi indirizzi arriva come foglio di calcolo | Esportazione circa 15 righe, importazione circa 40, con la validazione che c'è |
| F-4 | "Non inviare email" per i siti di prova | WP Mail SMTP | Un sito copiato in staging con l'SMTP del cliente scrive ai clienti veri | Un `Toggle` nella card SMTP e un filtro `pre_wp_mail`: circa 12 righe |
| F-5 | "Passaggio 2 di 3" nei form a più passaggi | Gravity Forms, WPForms Pro | Chi compila sa quanto manca, e gli screen reader sentono il cambio di passaggio | Un `<p aria-live="polite">` in `Render::html()`, una stringa e una riga in `form.js`: circa 10 righe |
| F-6 | Precompilare un campo visibile da un parametro dell'URL | Gravity Forms, WPForms | Link da newsletter o pagine prodotto che aprono il form già compilato | `param`, oggi solo del tipo `hidden`, nello schema di `text`, `email`, `tel` e `number`: circa 8 righe |
| F-7 | Dati `Article` per gli articoli | Yoast SEO | Data e autore degli articoli per Google | 2 righe in `Head.php` e circa 12 in `Schema::graph()` |
| F-8 | I file nell'esportazione dei dati personali | WordPress | Oggi l'esportazione ha solo il nome del file, che si scarica dal dettaglio della registrazione | Nello ZIP con l'azione `wp_privacy_personal_data_export_file_created`, se un cliente lo chiede |
| GF-1 | Tipografia, colore, allineamento e classe del blocco Dati aziendali | WordPress (block supports) | La riga legale del footer è quasi sempre piccola e centrata: oggi serve un Gruppo intorno | `supports` in `block.json` e `get_block_wrapper_attributes()` solo per il blocco: circa 10 righe. Il test "blocco e shortcode danno lo stesso HTML" va riscritto |
| GF-2 | Fascia di prezzo (`priceRange`) nello schema dell'attività | Yoast Local SEO, Rank Math Local SEO | Chi migra da quei plugin la perde | Un campo in SEO → Organizzazione e una riga in `Schema::organization()`: circa 12 righe |
| GF-3 | `breadcrumbs { text url }` nel campo `seo` di GraphQL | WPGraphQL Yoast SEO Addon | Un frontend headless mostra il percorso senza ricostruire la gerarchia | Rendere riusabile l'elenco di `Schema::breadcrumb()`: circa 20 righe. Solo se un frontend lo usa |
| GF-4 | Reindirizzare le pagine pubbliche di WordPress al frontend | FaustWP ("Enable public route redirects") | Su un sito headless il dominio di WordPress mostra lo stesso contenuto del frontend | Un interruttore nella card Frontend headless e un `template_redirect` nell'estensione, con `barrio_frontend.url`: circa 15 righe |
| GF-5 | "Genera" accanto al token di revalidation | FaustWP (la Secret Key si genera e si rigenera) | Evita token deboli scritti a mano | `crypto.getRandomValues` in `Frontend.js`: circa 8 righe |
| GF-6 | Numero massimo di scelte per checkbox e select multipla | WPForms ("Choice Limit") | "Scegli al massimo 3" è una richiesta comune nei form di iscrizione | Un intero `max_choices` nello schema, un controllo in `FieldTypes::validate()` e in `form.js`: circa 15 righe e una stringa |
| GF-7 | `contactPoint` nello schema dell'organizzazione, dai contatti per sede | Rank Math (Local SEO, "Phone Numbers" con il tipo) | Oggi i contatti per sede non arrivano al JSON-LD: `Schema::organization()` usa solo il telefono dell'azienda | Circa 8 righe in `Schema::organization()` e un test; da provare al Rich Results Test |

Valutate e non proposte: i merge tag nel messaggio di conferma (i frontend headless li mostrerebbero grezzi), le
sorgenti del traffico nelle Statistiche, precedente e successivo nell'ordine manuale, "una volta per visita" per i
popup, `fullHead` in GraphQL, i block bindings per i dati aziendali.
