# Pubblicazione e aggiornamenti (branch `con-updater`)

Questo branch è Barrio Suite con l'updater da GitHub, per i siti dei clienti finché i plugin non sono
nella directory di WordPress.org. `senza-updater` è la versione per WordPress.org: il codice è lo stesso
tranne questi file, gli unici diversi tra i due branch (ogni fase si porta da qui all'altro con un
cherry-pick, e questi file non si toccano):

- `src/Extensions.php` e `assets/src/extensions/Extensions.js`, la pagina Estensioni: qui dal catalogo
  delle release GitHub, con gli aggiornamenti; in `senza-updater` dalla directory di WordPress.org;
- solo qui: `assets/src/extensions/Updates.js` (la card Aggiornamenti in Barrio → Impostazioni e il
  passaggio della messa online, che `Extensions.js` passa alle due pagine con `settingsSections` e
  `launchSteps`), `dev/release.js` e lo script `release` di `package.json`, questo file;
- l'header `Update URI` di Barrio Suite e delle estensioni, e la sezione "External services" del readme;
- `tests/Integration/ExtensionsTest.php`, che prova la pagina Estensioni di ognuno dei due branch;
- `languages/`, del core e di `barrio-suite-migration` (il cui file principale ha una riga in più, `Update URI`):
  le stringhe dell'updater ci sono solo qui. Dopo un cherry-pick si rigenerano in ogni branch invece di risolvere
  i conflitti.

## Pubblicazione

Ogni estensione ha l'header `Update URI: https://github.com/hellobarrio/suite-barrio`, lo stesso di Barrio
Suite (`Extensions::REPO`; `ExtensionsTest` controlla che coincidano): una cartella nuova in `extensions/` con
quell'header entra da sola nella release successiva.

1. `npm run release` prepara gli zip di Barrio Suite e delle estensioni e `catalog.json`, con nome,
   descrizione, versione e requisiti presi dagli header dei plugin. WordPress propone un aggiornamento
   solo se la versione sale: prima si alza `Version` nei plugin cambiati.
2. Si caricano come asset di una nuova release del repository GitHub, con il tag (`v0.1.0`…) sul
   branch `stable`. Ogni sito legge il catalogo dell'ultima release
   (`…/releases/latest/download/catalog.json`, tenuto in cache un'ora).
3. Nei siti, Barrio → Extensions elenca le estensioni con Installa, Attiva, Disattiva e
   Aggiorna, usando l'installatore di WordPress; servono le capability `install_plugins`,
   `update_plugins` e `activate_plugins` (con `DISALLOW_FILE_MODS` restano solo attiva e disattiva).
   Gli aggiornamenti di Barrio Suite e delle estensioni compaiono anche in Bacheca → Aggiornamenti,
   come quelli degli altri plugin, compresi gli aggiornamenti automatici.

Uno zip si può sempre caricare a mano da Plugin → Aggiungi nuovo plugin → Carica plugin (la pagina Estensioni ha il
link): è la strada anche sugli hosting dove WordPress non può scrivere i file senza credenziali FTP.

## Estensioni e aggiornamenti nel sito

- `GET /extensions` (`activate_plugins`) e `POST /extensions/{slug}` con `action` = `install` (`install_plugins`),
  `update` (`update_plugins`), `activate`, `deactivate`: le estensioni del catalogo dell'ultima release GitHub e quelle
  installate, con lo stato; `refresh` salta la copia del catalogo dell'ultima ora ("Check for updates"), `install`
  installa e attiva; lo zip è sempre l'asset `{slug}.zip` dell'ultima release, mai un indirizzo del catalogo o della
  richiesta; un'estensione attiva in tutta la rete di un multisite non si disattiva da qui (`src/Extensions.php`).
  La risposta ha anche la versione installata e pubblicata di Barrio Suite (`suite`), che si aggiorna con
  `POST /extensions/barrio-suite` (non una copia con `.git`: 409), e gli aggiornamenti automatici; `action` = `delete`
  (`delete_plugins`) elimina un'estensione disattivata come Plugin → Elimina; `PUT /extensions` con `auto_update`
  (`update_plugins`, e solo se WordPress li permette) li accende o spegne per Barrio Suite e tutte le estensioni
  installate nell'opzione di WordPress `auto_update_plugins`, quella del link in Plugin; un'estensione installata
  dalla pagina li riceve se Barrio Suite li ha;
- `GET /launch` ha anche il passaggio `updates` (aggiornamenti automatici accesi), con il filtro
  `barrio_launch_steps`, per chi ha `activate_plugins` (non l'amministratore di un sito di un multisite, che
  non li può cambiare).

- **Estensioni** (pagina `barrio-extensions`, `activate_plugins`): una card per estensione con descrizione, versione installata e disponibile, Installa / Attiva / Disattiva / Aggiorna / Elimina (solo disattivata, con conferma); nella barra la ricerca (nome e descrizione), "Carica uno zip" (la pagina di WordPress) e "Cerca aggiornamenti" (senza la cache del catalogo). Ogni estensione resta un plugin separato, visibile anche in Plugin.
- **Impostazioni** (pagina `barrio-settings`): la card Aggiornamenti (`assets/src/extensions/Updates.js`, solo per chi ha `activate_plugins`) con la versione di Barrio Suite, "Cerca aggiornamenti" (senza la cache del catalogo), "Aggiorna a x.y.z" (con l'installatore di WordPress, come le estensioni; poi la pagina si ricarica) e "Aggiorna da solo ogni notte", salvata con il Salva della pagina. L'interruttore mette Barrio Suite e tutte le estensioni negli aggiornamenti automatici di WordPress (`auto_update_plugins`, lo stesso link di Plugin), ma i nostri plugin si aggiornano solo di notte: `Extensions::nightly()`, un evento singolo di WP-Cron alla prossima mezzanotte del sito (rimesso ogni giorno, segue fuso e ora legale; parte alla prima visita dopo mezzanotte, solo sul sito principale), rilegge catalogo e versioni e usa l'aggiornatore automatico di WordPress, con il ritorno alla versione precedente se il sito dà un errore fatale; nel giro di WP-Cron di WordPress il filtro `auto_update_plugin` li salta. Una copia di sviluppo (con `.git`) non si aggiorna mai dallo zip.

## Da verificare

- **Repository GitHub** (fase 8): `hellobarrio/suite-barrio`, pubblico, che è anche quello del prototipo: il nuovo plugin sta nel branch `stable`, con una storia separata da `main` e `dev`, e le sue release hanno il tag su `stable`. Il prototipo non pubblica release né si aggiorna da GitHub, quindi "l'ultima release" è sempre del nuovo plugin; se un giorno il prototipo ne pubblicasse, servirebbe un repository a parte (`Extensions::REPO` e l'header `Update URI` di ogni plugin). Finché non c'è una release la pagina Estensioni mostra l'errore del catalogo e le sole estensioni già installate. Provato con un catalogo e degli zip locali al posto di GitHub; la prima release vera va provata su un sito di prova, aggiornamento di Barrio Suite compreso.
- **Repository separato per le estensioni** (da valutare): si può. Il catalogo e gli zip delle estensioni starebbero nelle release di un altro repository, con versioni e release indipendenti da Barrio Suite; `Extensions` accetterebbe gli zip di entrambi e le estensioni avrebbero il nuovo repository nell'header `Update URI`. Quelle già installate lo ricevono con un'ultima release dal repository attuale.
