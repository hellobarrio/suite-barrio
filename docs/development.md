# Come si lavora su Barrio Suite

Barrio Suite riscrive da zero i moduli di `suite-barrio-prototipo`: se ne conservano le funzionalità, non il codice.
Qui ci sono le regole che valgono per ogni modifica. Le altre regole sono in [database.md](database.md) (tabelle,
vincoli, migrazioni), [hooks.md](hooks.md) (API per le estensioni, GraphQL, migrazione dal prototipo) e
[release.md](release.md) (branch, pubblicazione, aggiornamenti). Ciò che resta da fare è in [TODO.md](../TODO.md). I
piani con cui sono stati costruiti i moduli (Forms, SEO, gli otto moduli del 19-20 novembre, lo schema GraphQL e Dati
aziendali) sono stati tolti a lavoro finito: restano nella storia di git
(`git log -- PLAN.md PLAN-SEO.md PLAN-MODULI.md PLAN-GRAPHQL.md AUDIT.md`).

Un gruppo di moduli nuovi parte da un suo piano (`PLAN-<nome>.md`: decisioni, dati, problemi del prototipo da non
ripetere, admin, da verificare, fasi), che si toglie quando è finito, portando qui o in `docs/` le regole che restano.

## Principi

- Leggibilità prima dell'astuzia. Ogni astrazione deve servire a qualcosa oggi. Prima si riusano WordPress, WPGraphQL e
  WPML, poi si scrive codice nostro. Una sola persona deve poter mantenere tutto.
- **Il database è il centro**: un modulo parte dai dati, e ogni fase si apre con schema, migrazioni e i test che
  dimostrano ogni vincolo, prima di REST e interfaccia ([database.md](database.md)).
- **Privacy by design e by default** (art. 25 GDPR): si raccoglie e si salva solo ciò che serve; ogni raccolta in più è
  un'opzione spenta di default. Nessun IP, user agent o referrer salvato.
- Il tracciamento dei siti passa da Google Tag Manager, anche il cookie banner (CookieScript): il plugin non inserisce
  script di tracciamento, e Statistiche legge solo i report di GA4.

## Struttura

| Tema | Regola |
|---|---|
| Nomi | `barrio-suite`, namespace `BarrioSuite\`, prefisso `barrio_`, tabelle `{prefix}barrio_*`. Un modulo in `modules/<slug>/` con il namespace `BarrioSuite\<Modulo>\`. Solo GraphQL usa `hb*` e `Hb*` ([hooks.md](hooks.md#graphql)) |
| Requisiti | PHP 8.2+, WordPress 6.9+, MySQL 8.0.16+ o MariaDB 10.5+, InnoDB, controllati all'attivazione |
| Versione | Resta 0.1.0 finché non si decide una release |
| Moduli | Accesi di default, anche quelli che arrivano con un aggiornamento (`barrio_disabled_modules` salva solo gli spenti). Un modulo acceso non cambia niente finché non lo si configura. Spento non registra hook, menu, rotte REST, blocchi o cron, le sue migrazioni non girano e le sue estensioni restano ferme (`barrio_<modulo>_loaded` non parte); i dati restano |
| Dati | Le raccolte di record sono tabelle, con i testi per lingua in una tabella `_i18n`. Un record unico è un'opzione con JSON Schema, scritta solo dalla sua rotta REST, in autoload solo se il sito la legge, e creata vuota da una migrazione (un'opzione che non esiste costa una query a ogni richiesta). I dati di un contenuto di WordPress sono suoi meta |
| Plugin di terzi | Nessuno è obbligatorio, e il core non chiama le loro funzioni né legge i loro filtri: ognuno ha un'estensione in `extensions/` (WPML, WPGraphQL, Mailchimp, HubSpot, reCAPTCHA, migrazione dal prototipo), anche per le impostazioni che servono solo con lui. Senza il plugin di terzi l'estensione non fa niente e non dà errori. Le estensioni usano le API di [hooks.md](hooks.md); un hook nuovo si aggiunge solo quando un'estensione lo usa |
| Google | Un solo service account, in Barrio → Impostazioni, per AI (Gemini su Vertex AI), Places, GA4 e reCAPTCHA: nessuna chiave API nei moduli, nessun modello o regione scritti nel codice. Le chiamate partono da un clic in admin, dall'apertura delle Statistiche o dalla sincronizzazione notturna degli orari, e il readme le dichiara (regola di WordPress.org) |
| Email | `wp_mail()` con il PHPMailer di WordPress; l'SMTP di Barrio → Impostazioni vale per tutte le email del sito, anche con i moduli spenti |
| Lingue | Le danno i filtri `barrio_languages`, `barrio_current_language`, `barrio_locale` e `barrio_language_flags`, a cui risponde `barrio-suite-wpml`; senza, il sito ha una lingua sola. I testi si traducono nell'editor del modulo (lingua scelta nella barra, `TextIn`): in un'altra lingua un testo vuoto vale quello della lingua principale |
| Prototipo | Il core non contiene codice di compatibilità con il prototipo: la migrazione è l'estensione `barrio-suite-migration`, da eliminare a migrazione finita. Sui siti del prototipo trattiene spenti i moduli che il prototipo ha accesi, fino alla migrazione: per questo lì il core si aggiorna insieme all'estensione, reinstallandola se era già stata tolta |

## Admin

Un'app React (`@wordpress/scripts`, build `barrio-admin`) per tutte le pagine del menu Barrio, con i componenti di
`assets/src/admin/ui.js` in HTML nativo e il CSS del plugin (`admin.css`); da `@wordpress/components` solo modale,
popover e schede. Stringhe dell'interfaccia in inglese con `__()`, con la traduzione italiana inclusa; i commenti dei
traduttori in inglese, quelli del codice in italiano e solo dove spiegano qualcosa che il codice non dice.

| Tema | Regola |
|---|---|
| Tabelle | Tutte con lo stesso componente `Table`: nella barra la ricerca, un solo pulsante Filtri e, con righe selezionate, il menu "N selezionate" delle azioni di massa; poi l'azione principale. Il pannello Filtri ha una sezione per filtro (lo stato sempre, se le righe ne hanno uno, con i conteggi; la lingua; con la ricerca oltre 5 scelte) e le colonne da mostrare; sul pulsante il numero dei filtri attivi, in fondo "Clear filters". Le altre scelte singole usano lo stesso `Picker`. Selezione multipla con la prima colonna, pagine da 20 righe, ordinamento a tre stati (crescente → decrescente → default) con una colonna sempre ordinata. Le azioni di riga stanno tutte nel menu "⋯", senza descrizioni. Menu e scelte oltre 5 voci hanno la ricerca |
| Pagine con salvataggio | Una barra sticky in alto (`Bar`), staccata di 16px da quella di WordPress: titolo a sinistra (con il ritorno, se c'è), azioni a destra con la principale per ultima. Un solo Salva, attivo solo con modifiche, e un avviso prima di lasciare la pagina con modifiche (`useUnsavedWarning`). La Bacheca non ha azioni sue. Negli elenchi la barra ha la ricerca e "Nuovo". La barra resta su una riga (`fit()`): prima si accorcia ciò che può, poi i pulsanti perdono il testo, poi la ricerca diventa un pulsante, e solo alla fine il titolo va sopra. La colonna destra, quando c'è, è sticky. Sui telefoni niente esce dalla pagina: le tabelle scorrono in orizzontale |
| Modali | Sempre `Dialog` di `ui.js`, mai `Modal`: intestazione in alto con la linea sotto, bottoni in un piede sempre visibile, contenuto che scorre, campi con gli stili delle pagine. Si controllano nel browser |
| Bottoni | **Tipi**: principale (scuro, uno per zona: l'ultimo della barra, la conferma di un dialogo, la prima azione di una card della Bacheca); secondario (bianco con bordo); pericolo (bordo e testo rossi, icona cestino); solo icona (36×36, con `aria-label`; togliere una riga da un elenco è sempre `RemoveButton`, mai la X); i selettori restano con la freccia giù. **Icone**: ogni bottone con testo ha la sua icona da 16px a sinistra, anche Annulla (`CancelButton`) e Applica (`ApplyButton`). **Testi**: "Add" nelle intestazioni di card e sezioni (`AddButton`, il nome completo come `aria-label`), il testo intero nella barra ("New form"). **Larghezze**: i bottoni accanto larghi quanto il più largo (`Buttons`, il piede dei dialoghi); nelle intestazioni almeno 128px; sotto un campo larghi quanto il campo, e due se lo dividono (`barrio-row is-fill`); nelle card della Bacheca colonne uguali, con l'azione principale che ha il nome della pagina |
| Spazi e controlli | Misure a multipli di 4: 20px tra le card e dentro, 16px tra i campi; lo spazio dentro un elemento non supera quello che lo separa dagli altri; un angolo dentro un altro ha il raggio di quello esterno meno la distanza dal bordo. Acceso/spento è un interruttore (`Toggle`, testo a sinistra; più interruttori di fila separati da una linea); le caselle servono per scegliere da un elenco. Ciò che si allunga si chiude: card richiudibili, impostazioni rare in `Details` |
| Link | Il link di una CTA si scrive con `LinkField` (`period.js`), come il campo Link di ACF: testo, indirizzo e nuova scheda in una modale |
| Permessi | Ogni pagina di un modulo ha la sua capability `barrio_*` e la card Permessi nella scheda Impostazioni del modulo, anche dove basterebbe `manage_options`. Gli amministratori possono sempre tutto. Le capability le assegna una migrazione, non l'attivazione, così arrivano anche ai siti di un multisite |
| Bacheca e messa online | Ogni modulo acceso ha la sua card nella Bacheca (`ModuleCard`) e, se va configurato prima di pubblicare il sito, il suo passaggio della messa online (`barrio_launch_steps`) |

## Branch

Si lavora su `con-updater`, e ogni commit si porta su `senza-updater` con un cherry-pick. Tra i due branch cambiano solo
l'updater da GitHub (i file elencati in [release.md](release.md)) e, dalla revisione di WordPress.org, le traduzioni
incluse: `senza-updater` non ha `languages/`, `Domain Path` e `load_plugin_textdomain()` nel core e nelle quattro
estensioni che vanno su WordPress.org; `barrio-suite-migration` le tiene in tutti e due. Dopo ogni cherry-pick
`git diff con-updater senza-updater --stat -- . ':!languages'` deve mostrare solo quelle differenze. Un commit già
portato non si modifica senza portare anche la modifica.

## Prima di chiudere una modifica

- PHPUnit su MariaDB 10.5 e MySQL 8.0, con e senza WPGraphQL (`npm run test:php`, `npm run test:php:graphql`): ogni FK,
  CHECK e UNIQUE dimostrati, il JSON Schema di ogni opzione e colonna JSON, ogni enum uguale al suo CHECK.
- `npm run lint:php` (PHPCS `WordPress-Extra`, PHPStan livello 8), `npm run lint:js`, `npm run lint:css`,
  `npm run test:js` (le stesse fixture delle condizioni in PHP e in JS).
- `npm run build` e un controllo nel browser delle pagine toccate, anche a 360px.
- Traduzioni rigenerate (qui sotto), lo stesso commit sui due branch, un plugin installabile (`npm run zip`). Prima di
  caricarlo su WordPress.org, Plugin Check sullo zip scompattato, non sul repository.

## Traduzioni

Il core e le estensioni includono l'italiano in `languages/` (`.pot`, `.po`, `.mo` e, per il JavaScript, i JSON legati
a ogni script con stringhe). Le altre lingue arrivano dai language pack di WordPress.org. Dopo una modifica alle
stringhe, su `con-updater`, in `wp-env run tests-cli --env-cwd=wp-content/plugins/barrio-suite`:

1. `npm run build` prima: le stringhe del JavaScript si leggono dalla build.
2. `wp i18n make-pot . languages/barrio-suite.pot`, escludendo `assets/src`, `modules/*/assets/src`, `extensions`,
   `tests`, `dev`, `docs`, `vendor` e `node_modules`.
3. `wp i18n update-po` sul `.po`, non `msgmerge`, che riformatta tutti i riferimenti.
4. Le traduzioni delle stringhe nuove, controllate con `msgfmt --check` e `msgattrib --untranslated`.
5. `wp i18n make-mo` e `wp i18n make-json --no-purge --use-map=<mappa>`. WP-CLI 2.12 sbaglia il nome del JSON di
   `build/barrio-admin.js` (lo legge come `.min.js` e ne fa `barrio-a.js`): la mappa porta `build/barrio-admin.js` su
   `build/barrio-admin.min.js` e ogni altro script (le altre build, gli `assets/block/index.js` dei blocchi) su sé
   stesso, perché con la mappa WP-CLI scrive solo i file mappati.

Il parser JavaScript di WP-CLI (Peast) non legge `[ ...new Set( … ) ]` quando la minificazione lo mette dentro
un'espressione con la virgola: la build allora non dà nessuna stringa, senza errori. Se il `.pot` perde le stringhe del
JavaScript, è questo. `switch_to_locale()` cambia lingua solo se WordPress ha installato la sua traduzione.
