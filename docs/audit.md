# Audit di Barrio Suite

Data: 16 novembre 2026. Codice esaminato: branch `fase-11-amministrazione`, commit `85150a1`.

Scopo: capire cosa togliere o semplificare, quali presupposti non reggono più e cosa migliorare in
sicurezza, prestazioni e interfaccia, prima di usare il plugin sui siti, pubblicarlo su WordPress.org e
costruirci sopra gli altri moduli. Per ogni punto c'è la prova e la modifica più piccola che conserva il
comportamento attuale.

## In breve

Il codice è sano e c'è poco codice morto da tagliare. PHPCS, PHPStan livello 8, ESLint e stylelint sono
puliti; passano 279 test PHP e 23 JS. Non sono emerse vulnerabilità critiche.

Non è però pronto per nessuno dei due obiettivi:

- **Uso reale**: ci sono 13 difetti da correggere, 4 dei quali riprodotti. Quasi tutti si chiudono con
  1–8 righe.
- **WordPress.org**: Plugin Check dà errori bloccanti su tutti e 6 i plugin, e l'installazione delle
  estensioni da GitHub non è ammessa.

Il taglio grosso è uno solo: l'updater da GitHub (circa 1.000 righe), che è anche ciò che blocca
WordPress.org.

## Metodo

- Lettura completa del codice di produzione (circa 14.000 righe tra PHP, JS e CSS), divisa per area:
  core, REST e pipeline di invio, repository e database, shell dell'admin, builder e registrazioni,
  frontend ed estensioni.
- Strumenti: PHPCS, PHPStan, ESLint, stylelint, PHPUnit e i test JS del progetto; Plugin Check 2.2.0 su
  core ed estensioni (installato sul sito wp-env e poi rimosso); `legacy-scan` di modern-web-guidance.
- Prove dal vivo sul sito wp-env (WordPress 7.1.4, MariaDB 12.3, 100.000 registrazioni di prova): Chrome
  156 senza interfaccia per i comportamenti del browser, curl per l'endpoint pubblico, conteggio e tempi
  delle query con `SAVEQUERIES`.
- "Riprodotto" vuol dire eseguito davvero; "verificato in lettura" vuol dire letto riga per riga, senza
  eseguirlo.

## 1. Da correggere prima dell'uso reale

Riprodotti (Chrome 156 e curl sul sito wp-env):

| # | Problema | Dove | Prova | Modifica minima |
|---|---|---|---|---|
| 1 | Con il puntatore sopra una tabella la rotella non scorre la pagina (Chrome ≥ 144, Firefox ≥ 150; Safari no) | [admin.css:857](../modules/forms/assets/src/admin/admin.css#L857), [1015](../modules/forms/assets/src/admin/admin.css#L1015), [1194](../modules/forms/assets/src/admin/admin.css#L1194) | In Registrazioni `scrollY` resta 0 sopra la tabella e arriva a 168 sopra l'intestazione | `overscroll-behavior-x: none` alle righe 857 e 1015; per il pannello (1194) va deciso |
| 2 | Multistep: Invio in un campo di testo invia il form dal primo passaggio | [form.js:163-169](../modules/forms/assets/frontend/form.js#L163-L169), [233-236](../modules/forms/assets/frontend/form.js#L233-L236) | `submit` parte anche con l'unico pulsante submit in un passaggio `hidden`; `submit()` valida solo il passaggio corrente | Nel listener, se non è l'ultimo passaggio fare come "Avanti" (~4 righe) |
| 3 | `POST /forms/{id}/entries` risponde 500 a un JSON che non è un oggetto, senza autenticazione | [RestPublic.php:86](../modules/forms/src/Api/RestPublic.php#L86) | `"x"` → 500, `1` → 500, `{}` → 400 | Cast `(array)` (1 riga) |
| 4 | Un campo con chiave `action` rompe l'invio | [form.js:182](../modules/forms/assets/frontend/form.js#L182) | `form.action` restituisce l'`<input name="action">` | `form.getAttribute( 'action' )` (1 riga) |

Il punto 1 contraddice la regola decisa il 13 novembre ("`overscroll-behavior: none` su entrambi gli
assi"): i browser ora la applicano anche all'asse che non scorre.

Verificati in lettura:

| # | Problema | Dove | Prova | Modifica minima |
|---|---|---|---|---|
| 5 | Correggere l'etichetta di un campo già salvato ne rinomina la chiave (e il valore di una scelta) | [Fields.js:424-432](../modules/forms/assets/src/builder/Fields.js#L424-L432), [model.js:46-50](../modules/forms/assets/src/builder/model.js#L46-L50) | La chiave segue l'etichetta senza sapere se il campo è salvato: `{{chiave}}` vuoto nelle email, seconda colonna nel CSV, condizioni e Mailchimp sulla chiave vecchia | Seguire l'etichetta solo fino al primo salvataggio (~8 righe) |
| 6 | Limite di 10 invii ogni 10 minuti per `REMOTE_ADDR`, per tutto il sito | [Submission.php:29-31](../modules/forms/src/Submission/Submission.php#L29-L31), [130](../modules/forms/src/Submission/Submission.php#L130) | Dietro un proxy che non ripristina l'IP, dopo 10 invii tutti ricevono 429. È già in PLAN §8, ma il filtro non c'è | `apply_filters( 'barrio_forms_rate_limit', … )` (1 riga) |
| 7 | Cache dei form senza versione né scadenza | [FormRepository.php:24](../modules/forms/src/Repository/FormRepository.php#L24), [267](../modules/forms/src/Repository/FormRepository.php#L267) | Con Redis o Memcached, dopo un aggiornamento che cambia `Model\Form` l'oggetto vecchio ha proprietà mancanti: errore fatale sulle pagine con form | Versione nel gruppo e scadenza di un giorno (2 righe) |
| 8 | Durante una migrazione, chi perde il lock parte senza il modulo | [Migrator.php:37-39](../src/Database/Migrator.php#L37-L39), [Plugin.php:44-49](../src/Plugin.php#L44-L49) | `GET_LOCK(%s, 0)` → `return null`: lo shortcode esce come testo e una cache di pagina può salvarlo | `GET_LOCK(%s, 5)` e versione riletta (~5 righe) |
| 9 | WPGraphQL: file temporanei lasciati da richieste anonime | [barrio-suite-wpgraphql.php:351-356](../extensions/barrio-suite-wpgraphql/barrio-suite-wpgraphql.php#L351-L356), [425-434](../extensions/barrio-suite-wpgraphql/barrio-suite-wpgraphql.php#L425-L434) | Con lo stesso campo file ripetuto N volte si cancella solo l'ultimo; il base64 si decodifica prima di ogni limite | `??=` e controllo di `strlen` prima di decodificare (~4 righe) |
| 10 | Ciò che si scrive mentre il salvataggio è in corso viene sovrascritto | [Builder.js:68-72](../modules/forms/assets/src/builder/Builder.js#L68-L72), [Settings.js:183-190](../modules/forms/assets/src/settings/Settings.js#L183-L190) | `load( data )` rimpiazza lo stato; i campi restano modificabili | Tenere lo stato se è cambiato (~3 righe) |
| 11 | Gli esiti compaiono fuori schermo | [admin.css:555-562](../modules/forms/assets/src/admin/admin.css#L555-L562) | L'avviso non è sticky, la barra sì: l'errore SMTP di "Send test email" e il 409 del builder finiscono in cima alla pagina | `position: sticky` sotto la barra (3 righe) |
| 12 | Allegati delle notifiche senza tetto | [Notifications.php:244-256](../modules/forms/src/Notifications.php#L244-L256) | Senza `max_size_mb` vale il limite del server; se la memoria finisce la registrazione è salvata ma il visitatore riceve 500 (stima, non misurata) | Niente allegati oltre ~10 MB totali (3 righe) |
| 13 | reCAPTCHA si spegne in silenzio se la chiave non si decifra (salt diversi dopo un clone) | [barrio-suite-recaptcha.php:62-66](../extensions/barrio-suite-recaptcha/barrio-suite-recaptcha.php#L62-L66) | `null === $settings` → il controllo lascia passare tutto | Avviso in admin (~5 righe) |

## 2. WordPress.org: cosa blocca

Risultati di Plugin Check 2.2.0 su core ed estensioni.

| Blocco | Prova | Modifica minima |
|---|---|---|
| Header `Update URI` in tutti e 6 i plugin | Errore `plugin_updater_detected`: "not allowed in plugins hosted on WordPress.org" | Toglierlo dalla build per WordPress.org |
| Installazione e aggiornamenti da GitHub | [Extensions.php:308](../src/Extensions.php#L308), [359-364](../src/Extensions.php#L359-L364); avviso `update_modification_detected` | Vedi §3, punto 1 |
| `readme.txt` assente | Errore `no_plugin_readme` ×6 | Readme con Stable tag, Tested up to e i servizi esterni (GitHub, Google reCAPTCHA, Mailchimp) |
| 5 file senza guardia di accesso diretto | `functions.php`, le 3 migrazioni, `templates/email.php` | `defined( 'ABSPATH' ) \|\| exit;` (5 righe) |
| SQL non preparato in `Db` | Errori a [Db.php:21, 33, 40, 47, 68](../src/Database/Db.php#L21) | `phpcs:ignore` motivati: le query sono preparate nei Repository |
| Text domain del core nelle estensioni | wpgraphql righe 342 e 398, migration riga 365 | Usare il dominio dell'estensione |
| "wp" nello slug | Avviso su `barrio-suite-wpml` e `-wpgraphql` | Rinominare, se vanno nella directory |

Sul punto `Db`: il wrapper nasconde allo sniff tutto l'SQL dei Repository, quindi la garanzia resta la
revisione manuale. In questa revisione colonne e direzione risultano in whitelist e gli identificatori
passano da `%i`.

Lo strumento non vede altri quattro punti:

- **Sorgenti**: la build è minificata e [package.json](../package.json) non include `assets/src`; il
  readme deve indicare il repository pubblico.
- **Lingua**: i commenti per i traduttori e i preset
  ([Admin.php:75-76](../modules/forms/src/Admin.php#L75-L76)) sono in italiano, e non c'è nessun file di
  traduzione. Sui siti italiani l'interfaccia resta in inglese finché non arrivano i language pack.
- **Versione minima**: `Requires at least: 6.6` non è mai stato provato (la CI usa solo l'ultima
  versione, in locale c'è la 7.1.4). Inoltre
  [block.json:20](../modules/forms/assets/block/block.json#L20) carica `form.css` su ogni pagina dei
  temi classici prima della 6.9. Alzare il minimo a 6.9 risolve entrambe (1 riga).
- **Dipendenza**: le estensioni non dichiarano `Requires Plugins: barrio-suite` (1 riga ciascuna, da
  provare).

## 3. Cosa togliere o semplificare

1. **Updater da GitHub fuori dal core** (circa 1.000 righe): [Extensions.php](../src/Extensions.php)
   (378), `Extensions.js` (278), `ExtensionsTest.php` (287), `release.js` (46) e metà di `General.js`.
   Per conservare tutto sui siti interni, la via più corta è spostarlo in un'estensione non pubblicata,
   come vuole la regola del progetto per il codice di terzi. Se invece anche le estensioni vanno su
   WordPress.org, si cancella del tutto.
2. **Estensione di migrazione** (856 righe) e alias degli shortcode, che girano a ogni richiesta finché
   è attiva. È già previsto: va tolta sito per sito.
3. **API senza utilizzatori**: `barrio_forms_register_field_type()`
   ([functions.php:13-16](../modules/forms/functions.php#L13-L16)) compare solo nei test. I filtri
   `barrio_forms_validate` e `barrio_forms_rest_response` non hanno ascoltatori. Una volta pubblicati
   diventano contratti: va deciso ora se tenerli.
4. **Colonne mai lette**: `form_versions.created_by` è scritta e ripulita ma nessuno la legge.
   `entries.user_id` è sempre NULL dal form (la REST pubblica non ha nonce,
   [RestPublic.php:92](../modules/forms/src/Api/RestPublic.php#L92)).
5. **`Settings.js`**: cinque stati paralleli copiati due volte
   ([40-76](../modules/forms/assets/src/settings/Settings.js#L40-L76)), da ridurre a un solo `draft`
   come in `Launch.js` (−25 righe, +6). Corregge anche l'avviso "modifiche non salvate" che scatta
   durante il caricamento.
6. **Due mappature schema → controllo**: `ConfigControl`
   ([Fields.js:571-589](../modules/forms/assets/src/builder/Fields.js#L571-L589)) e `SettingControl` in
   `Integrations.js`, da unire (−19 righe, +8).
7. **CSS**: le variabili su `.barrio-admin` obbligano a 15 valori ripetuti a mano per popover e modale;
   su `:root` spariscono (−10 righe). I cinque offset della barra (48, 62, 16, 122, 138) diventano una
   variabile.
8. **Query**: `FormRepository::versions()` fa 3 query per versione
   ([320-324](../modules/forms/src/Repository/FormRepository.php#L320-L324)); con `IN (…)` restano 3 in
   tutto.
9. **Ritocchi da una riga**: `wp_enqueue_media()` su ogni pagina Barrio
   ([Admin.php:57](../modules/forms/src/Admin.php#L57)) serve solo a Impostazioni e Messa online.
   `RestForms::schema()` è costruito tre volte per richiesta. Il CSS RTL (23 KB) è compilato e spedito
   ma mai registrato.

Da non toccare: in `admin.css` non c'è una classe inutilizzata e nessun export è morto. `fit()` e i
`Dropdown` di WordPress servono davvero, perché le alternative native non sono ancora disponibili
ovunque.

## 4. Presupposti che non reggono più

| Presupposto | Realtà |
|---|---|
| `_barrio_version` arriva solo da una pagina in cache | La sceglie il client, senza scadenza ([Submission.php:45-49](../modules/forms/src/Submission/Submission.php#L45-L49)): una validazione aggiunta dopo si aggira indicando la versione vecchia |
| "Bastano honeypot e limite per IP" | Il token JS è la costante `1` e il limite conta solo gli invii salvati; con reCAPTCHA ogni token finto costa una chiamata a Google |
| "Su Nginx il plugin lo segnala" (PLAN §5) | Non c'è codice: "nginx" compare solo in un commento di `FileStore.php` |
| "Senza JS valida il browser" | Senza JS l'invio finisce su un JSON 400; manca un `<noscript>` |
| Privacy by default | Mailchimp iscrive tutti come `subscribed`; senza campo di consenso i tag di consenso valgono per tutti ([barrio-suite-mailchimp.php:108-113](../extensions/barrio-suite-mailchimp/barrio-suite-mailchimp.php#L108-L113)) |
| La CI controlla ogni push | [ci.yml](../.github/workflows/ci.yml) parte su `main`, che su origin è il prototipo: gira solo sulle PR |
| Il lavoro è al sicuro su GitHub | Le fasi 9–11 (41 file, +5.190 righe) risultano non pushate rispetto a `origin/stable` |
| Gli aggiornamenti arrivano ai siti | Tutti i plugin sono ancora alla 0.1.0: nessun sito vede un aggiornamento finché non sale |

## 5. Interfaccia e accessibilità

- **Form pubblico**: `autocomplete` c'è solo su email e telefono
  ([FieldTypes.php:336-339](../modules/forms/src/FieldTypes.php#L336-L339)), quindi nome e indirizzo
  non rispettano WCAG 1.3.5. Dopo un errore del server il focus non va al campo sbagliato, e dopo
  l'invio si perde.
- **Bacheca**: il titolo bianco sul gradiente verde ha contrasto 2,54–3,77:1 e la descrizione
  2,10–2,95:1, contro il 4,5:1 richiesto
  ([Dashboard.js:91](../modules/forms/assets/src/dashboard/Dashboard.js#L91)). Bastano due colori più
  scuri.
- **Builder**: il drag & drop annuncia in inglese con id interni ("sortable-12"). Su un conflitto 409
  l'unica scelta è ricaricare e perdere il lavoro.
- **Registrazioni**: aprendo una registrazione l'elenco dimentica ricerca, filtri e pagina.
- **Editor a blocchi**: l'anteprima del form è cliccabile e inviabile; va avvolta in `Disabled`
  (1 riga).
- **Telefono**: a 360px nessuna delle 8 pagine esce in orizzontale (misurato).

## 6. Cosa regge

- **Sicurezza**: ogni rotta ha la sua capability e nessun ID è accettato senza controllare a chi
  appartiene. Gli upload hanno nome casuale e contenuto verificato. Header delle email e celle CSV sono
  neutralizzati, i segreti sono in sola scrittura, i valori inviati non diventano mai HTML nell'admin.
- **Punti minori aperti**: `GET /forms` mostra i conteggi a chi ha solo `edit_posts`; `hbForm` espone
  il titolo interno agli anonimi; l'iframe di anteprima non ha `sandbox`.
- **Prestazioni** (100.000 registrazioni, 700.000 valori, MariaDB 12.3):

| Operazione | Tempo | Query |
|---|---|---|
| Avvio di WordPress | — | 0 di Barrio Suite |
| Render di un form | 10 ms | 9 |
| Registrazioni, pagina 1 | 6–17 ms | 11–20 |
| Registrazioni, pagina 900 | 44 ms | 11 |
| Ricerca | 240–285 ms | 9 |
| Ordinamento per campo | 370–460 ms | 11 |

L'obiettivo di 100.000 registrazioni per sito regge. Le misure sono su MariaDB 12.3: vanno ripetute su
MySQL 8.0.

## 7. Base per i prossimi moduli

Lo spostamento di menu, pagine comuni e bundle da `modules/forms` al core può aspettare il secondo
modulo, come dice già il commento in [Admin.php](../modules/forms/src/Admin.php). Il core dipende da
Forms in un solo punto ([Plugin.php:10](../src/Plugin.php#L10)).

Va invece deciso ora ciò che diventa permanente alla prima installazione vera: le tabelle
`barrio_entries*`, le rotte `/barrio/v1/entries/{id}` e i filtri `barrio_forms_languages…` sono nomi di
suite occupati da un solo modulo.

## 8. Ordine consigliato

1. Push delle fasi 9–11 e CI sul branch giusto.
2. I 13 punti del §1 (una cinquantina di righe in tutto).
3. La scelta sull'updater (§3.1), poi i blocchi di Plugin Check.
4. Le decisioni sui nomi (§7) e sulle API senza utilizzatori.
5. Le semplificazioni restanti.

## Modern Web Guidance

`legacy-scan` ha dato un solo risultato su `modules`
([admin.css:86](../modules/forms/assets/src/admin/admin.css#L86)), che è un falso positivo: è
l'indicatore delle schede, escluso dalla regola stessa. Zero sulle estensioni; lo scan copre circa il
20% del catalogo. Le guide hanno sostenuto questi punti:

- **`prevent-page-scroll-under-modal-dialog`**: è la fonte del §1.1 ("`overscroll-behavior` applies to
  **all scroll container elements**, including non-scrollable scroll containers").
- **`accessible-error-announcement`**: gli avvisi di successo possono non essere letti ("a live region
  that is shown, rather than changed, isn't announced reliably").
- **`forms`**: dopo un errore il focus va spostato ("move focus to the first invalid field").
- **`viewport-aware-dropdowns`**: motivo per tenere i `Dropdown` di WordPress ("Anchor positioning:
  Limited availability").

## Non verificato

- I servizi veri (Google reCAPTCHA, Mailchimp), i lettori di schermo, Firefox e Safari, MySQL 8.0.
- Il testo delle linee guida di WordPress.org sugli aggiornamenti esterni e sui sorgenti è citato a
  memoria; gli errori di Plugin Check sono invece quelli dello strumento.
- Il punto 12 del §1 è una stima sulla memoria, non una misura.

Rischio principale: finché il punto 5 del §1 non è corretto, ritoccare un'etichetta su un form in
produzione può svuotare le email senza alcun avviso.
