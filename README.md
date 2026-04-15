# WP Alt AI — Plugin WordPress

Genera e gestisce alt text e title delle immagini usando Claude AI (Vision),
con una UI di revisione manuale nell'admin di WordPress.

## Installazione

1. Copia la cartella `wp-barrio/` in `wp-content/plugins/`
2. Attiva il plugin da **Plugin → Plugin installati**
3. Vai su **Media → Alt AI** e inserisci la tua chiave API Anthropic

## Funzionalità

- Pagina admin dedicata sotto "Media"
- Mostra tutte le immagini prive di alt text
- Genera alt + title con AI (Claude Vision) con un click per immagine
- Generazione in batch di tutte le immagini visibili
- Revisione e modifica manuale prima del salvataggio
- Salvataggio con un click tramite AJAX (nessun reload)
- Supporto multilingua (IT, EN, ES, FR, DE)
- Paginazione a 12 immagini per volta
- **Hook opzionale** (commentato): genera alt automaticamente su ogni nuovo upload

## Configurazione chiave API

La chiave Anthropic viene salvata in `wp_options` con `get_option('wp_alt_ai_api_key')`.
Per maggiore sicurezza puoi definirla in `wp-config.php`:

```php
define( 'WP_ALT_AI_API_KEY', 'sk-ant-api03-...' );
```

E leggere con `defined('WP_ALT_AI_API_KEY') ? WP_ALT_AI_API_KEY : get_option('wp_alt_ai_api_key')`.

## Auto-generazione su upload

Decommentare il blocco `add_action('add_attachment', ...)` in fondo al plugin
per attivare la generazione automatica su ogni nuovo upload.
Questo usa `wp_remote_post` (server-side), senza esporre la chiave al browser.

## Note di sicurezza

- La chiave API non viene mai esposta lato front-end
- Tutte le chiamate AJAX sono protette da nonce WordPress
- I permessi richiedono `manage_options` (amministratore)
