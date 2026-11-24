# Audit di Barrio Suite: ciò che resta aperto

Aggiornato il 24 novembre 2026. I quattro audit fatti finora (16, 18 e 20 novembre su tutto il plugin, 24 novembre sul commit dello schema GraphQL e di Dati aziendali) sono chiusi: le loro voci sono corrette nel codice, sui branch `con-updater` e `senza-updater`. I loro testi non servono più e sono stati tolti; quelli dei primi tre e la versione lunga di questo file restano nella storia di git (`git log -- AUDIT.md docs/audit.md docs/audit-seo.md docs/audit-moduli.md`).

Qui resta solo ciò che non è una correzione già fatta: le scelte ancora da prendere, le prove che chiedono un sito o un servizio vero e le funzioni suggerite. Le regole del progetto e le altre cose da verificare stanno nei piani ([PLAN.md](PLAN.md) §8, [PLAN-SEO.md](PLAN-SEO.md) §8, [PLAN-MODULI.md](PLAN-MODULI.md) §11, [PLAN-GRAPHQL.md](PLAN-GRAPHQL.md) §7).

## Da decidere

| Area | Com'è oggi | La scelta |
|---|---|---|
| Pacchetto | Lo zip contiene i sorgenti JavaScript (`assets/src` e `modules/*/assets/src` in `files` di `package.json`): circa 0,6 MB che WordPress non usa | Toglierli, se per la revisione di WordPress.org basta il link al repository nel readme. La regola va riletta prima |
| Testi alternativi | L'alt generato va nel meta dell'allegato: le `<img>` già scritte nei contenuti restano con `alt=""`, e la pagina lo dice | Un filtro `wp_content_img_tag` che riempie un `alt` vuoto dal meta (circa 8 righe). Non distingue un'immagine decorativa, che l'`alt` vuoto lo vuole |
| Forms, versioni | La versione di un form appena sostituita si elimina se non ha registrazioni. Con la cache di pagina, chi ha la pagina vecchia legge "ricarica la pagina" | Tenerla fino al salvataggio successivo (`version < %d` in `FormRepository`). Porta colonne vuote in Registrazioni |
| reCAPTCHA | Con un form dentro il popup, lo script di Google è in tutte le pagine; commento e readme lo dicono | Caricarlo al primo `focusin` su un form. Può abbassare i punteggi: va provato con una chiave vera |
| Popup e Banner | L'editor (stato, salvataggio, barra e schede) è scritto due volte, circa 40 righe | Un hook `usePeriodEditor()` in `period.js`, quando si rimette mano a quella logica. Non da solo |
| Builder dei form | Sotto i 1100 px il clic su un campo porta al pannello delle proprietà | Da provare a mano: sul telefono ogni tocco porta via dall'elenco dei campi |
| Admin, contrasto | Il bordo di campi e bottoni è `--barrio-border` (`#949494`, 3:1) | Da guardare a schermo: è la modifica che si vede di più |

## Da provare su un sito o un servizio vero

- **Updater da GitHub** (solo `con-updater`): "Aggiorna" usa `bulk_upgrade()` e il giro notturno `wp_maybe_auto_update()`. La prima release vera va provata su un sito di prova.
- **Ordinamento con WPML**: il filtro `barrio_ordering_items` e l'estensione WPML sono provati con i filtri di WPML simulati, non con WPML.
- **Blocchi**: prima di usare il modulo su un sito, contare i blocchi registrati solo in JavaScript (nell'editor, `wp.blocks.getBlockTypes()` meno `GET /wp/v2/block-types`). Con un solo blocco spento il ruolo li perde tutti.
- **`hbSubmitForm` e limite per IP**: l'utente dell'application password di ogni frontend headless deve avere `edit_posts`, altrimenti il limite di invii vale per tutto il sito.
- **Blocco Dati aziendali**: usa tre proprietà sperimentali di `FormTokenField` (`__experimentalExpandOnFocus`, `__experimentalAutoSelectFirstMatch`, `__experimentalValidateInput`), che una versione di WordPress può cambiare.
- **Mai provati dagli audit**: i servizi veri (Vertex AI, Places, GA4, reCAPTCHA, Mailchimp, GitHub Releases), WPML, WooCommerce e ACF attivi, un frontend headless reale, Nginx, Firefox, Safari e gli screen reader veri.
- **Porting di `hello_standard`**: è un lavoro a parte, con il solo foglio [docs/graphql-porting.md](docs/graphql-porting.md).

## Funzionalità suggerite

Proposte, non difetti: funzioni piccole dei plugin che Barrio Suite vuole sostituire, che qui mancano. Nessuna è tra quelle che i piani escludono. L'ultima colonna dice dove si scrivono e quanto costano sopra ciò che c'è già.

| Id | Funzione | Da | Perché serve | Dove e quanto |
|---|---|---|---|---|
| F-1 | URL canonico per contenuto | Yoast SEO, Rank Math | Per i contenuti doppi o ripubblicati; senza, la migrazione da Yoast perde quei valori | Un meta, `Meta`, il filtro `get_canonical_url` e un campo nel pannello: circa 25 righe. Da decidere se un contenuto con il canonico altrove esce dalla sitemap |
| F-2 | Importare ed esportare i redirect in CSV | Redirection | Alla messa online di un sito rifatto la mappa dei vecchi indirizzi arriva come foglio di calcolo | Esportazione circa 15 righe, importazione circa 40, con la validazione che c'è. È la più grande |
| F-3 | Avviso in admin quando l'ultima email non è partita | WP Mail SMTP | Dopo un cambio dei salt o una copia del sito la password SMTP diventa illeggibile, e niente lo dice | Ultimo errore in un transient e un `admin_notices`, come l'estensione reCAPTCHA: circa 20 righe in `Smtp.php` |
| F-4 | "Non inviare email" per i siti di prova | WP Mail SMTP | Un sito copiato in staging con l'SMTP del cliente scrive ai clienti veri | Un `Toggle` nella card SMTP e un filtro `pre_wp_mail`: circa 12 righe |
| F-5 | "Passaggio 2 di 3" nei form a più passaggi | Gravity Forms, WPForms Pro | Chi compila sa quanto manca, e gli screen reader sentono il cambio di passaggio | Un `<p aria-live="polite">` in `Render::html()`, una stringa e una riga in `form.js`: circa 10 righe |
| F-6 | Precompilare un campo visibile da un parametro dell'URL | Gravity Forms, WPForms | Link da newsletter o pagine prodotto che aprono il form già compilato | `param`, oggi solo del tipo `hidden`, nello schema di `text`, `email`, `tel` e `number`, e `data-param` nel rendering: circa 8 righe |
| F-7 | Dati `Article` per gli articoli | Yoast SEO | È ciò che Google legge per data e autore degli articoli | 2 righe in `Head.php` e circa 12 in `Schema::graph()` |
| GF-1 | Tipografia, colore, allineamento e classe del blocco Dati aziendali | WordPress (block supports) | La riga legale del footer è quasi sempre piccola e centrata: oggi serve un Gruppo intorno | `supports` in `block.json` (oggi `customClassName` è spento apposta) e `get_block_wrapper_attributes()` solo per il blocco: circa 10 righe. Il test "blocco e shortcode danno lo stesso HTML" va riscritto |
| GF-2 | Fascia di prezzo (`priceRange`) nello schema dell'attività | Yoast Local SEO, Rank Math Local SEO | Chi migra da quei plugin la perde | Un campo in SEO → Organizzazione, una chiave nello schema e una riga in `Schema::organization()`: circa 12 righe |
| GF-3 | `breadcrumbs { text url }` nel campo `seo` di GraphQL | WPGraphQL Yoast SEO Addon | Un frontend headless mostra il percorso senza ricostruire la gerarchia | Rendere riusabile l'elenco di `Schema::breadcrumb()` e aggiungere il campo a `HbSeo`: circa 20 righe. Solo se un frontend lo usa |

Valutate e non proposte: i merge tag nel messaggio di conferma (i frontend headless li mostrerebbero grezzi), le sorgenti del traffico nelle Statistiche, precedente e successivo nell'ordine manuale, "una volta per visita" per i popup, `fullHead` in GraphQL, più di due sedi e i block bindings per i dati aziendali (PLAN-GRAPHQL.md §5.4 li esclude).
