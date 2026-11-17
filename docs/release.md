# Pubblicazione (branch `senza-updater`)

Questo branch è la versione di Barrio Suite per WordPress.org. `con-updater` aggiunge l'updater da GitHub,
per i siti dei clienti finché i plugin non sono nella directory: il codice è lo stesso tranne questi file,
gli unici diversi tra i due branch (ogni fase si porta da `con-updater` a qui con un cherry-pick, e questi
file non si toccano):

- `src/Extensions.php` e `assets/src/extensions/Extensions.js`, la pagina Estensioni: qui dalla directory
  di WordPress.org, e `launchSteps` e `settingsSections` sono vuoti; in `con-updater` dal catalogo delle
  release GitHub, con gli aggiornamenti nella messa online e nelle Impostazioni;
- solo in `con-updater`: `assets/src/extensions/Updates.js`, `dev/release.js` e lo script `release` di
  `package.json`;
- l'header `Update URI` di Barrio Suite e delle estensioni (solo in `con-updater`), la sezione "External
  services" del readme e questo file;
- `languages/`: le stringhe dell'updater ci sono solo in `con-updater`. Dopo un cherry-pick si rigenerano
  in ogni branch invece di risolvere i conflitti.

## Pubblicazione

`npm run zip` prepara lo zip di Barrio Suite (con `readme.txt`, traduzioni e sorgenti JS) e
`npm run zip:extensions` quelli delle estensioni. Tutte, tranne la migrazione, si pubblicano nella directory
di WordPress.org con l'account `hellobarrio`. La migrazione resta uno zip da caricare da Plugin → Carica plugin.

## Estensioni nel sito

- `GET /extensions` (`activate_plugins`): le estensioni della directory di WordPress.org e quelle installate,
  con versione, stato, `plugin` per `/wp/v2/plugins` e `update_url`; più `error`, `can_install`, `can_delete`,
  `upload_url`.
- **Estensioni** (pagina `barrio-extensions`, `activate_plugins`, `src/Extensions.php`): una card per
  estensione, dalla directory di WordPress.org (`plugins_api()` con autore `hellobarrio`, in cache 12 ore) più
  quelle installate a mano, con Installa (e attiva), Attiva, Disattiva, Elimina (solo disattivata, con
  conferma) dalla REST di WordPress (`/wp/v2/plugins`) e Aggiorna verso la schermata di WordPress quando c'è
  una versione nuova; nella barra la ricerca e "Carica uno zip". Senza la directory (rete assente) restano
  quelle installate, con l'errore. Gli aggiornamenti sono quelli di WordPress per i plugin della directory.
