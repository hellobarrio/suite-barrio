=== Suite Barrio ===
Contributors: hellobarrio
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 9.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered toolkit for WordPress: automatic image alt text, drag-and-drop forms, SEO tools, cookie consent, popups and banners.

== Description ==

Suite Barrio is a suite of independent tools for WordPress developed by HelloBarrio, built to speed up the development workflow of headless WordPress + Next.js websites. It includes AI-generated image alt text, a drag-and-drop contact form builder with GraphQL support, an ACF field group generator, SEO utilities, a cookie consent banner, popups, banners, and more.

---

# Suite Barrio

A suite of WordPress tools developed by **HelloBarrio**. This plugin bundles three independent modules designed to streamline the development workflow for headless WordPress + Next.js websites.

- **Auto Alt** — Automatically generates image alt text and titles using AI.
- **Forms** — A drag-and-drop contact form builder with full GraphQL support.
- **Auto Fields** — Automatically creates ACF field groups and GraphQL queries directly from a page's HTML structure.

---

## Requirements

| Requirement | Minimum Version |
| ----------- | --------------- |
| WordPress   | 6.0             |
| PHP         | 8.0             |

### Optional Dependencies

| Plugin                                                                | Required By                  |
| --------------------------------------------------------------------- | ---------------------------- |
| [WPGraphQL](https://www.wpgraphql.com/)                               | Forms (GraphQL exposition)   |
| [Advanced Custom Fields (ACF)](https://www.advancedcustomfields.com/) | Auto Fields                  |

---

## Installation

1. Copy the `suite-barrio` folder into your WordPress installation's `wp-content/plugins/` directory.
2. Activate the plugin via the **Plugins** page in the WordPress admin dashboard.
3. Access the **Suite Barrio** item in the sidebar menu to configure the modules.

---

## External services

Some modules connect to third-party services to provide their functionality. Every connection below is optional: it only happens when the corresponding module is enabled and, where noted, only after you enter your own API key/account for that service.

### AI providers (Auto Alt, Cookie & Privacy Policy generator, SEO Advisor, Auto LLMs)

These four modules can generate text with an AI model of your choice. You must supply your own API key for the provider you pick; nothing is sent until you trigger a generation action from the admin screen.

- **Anthropic Claude API** — used to generate image alt/title text (Auto Alt), draft Privacy/Cookie Policy content and detect embedded third-party services (Cookie & Privacy Policy), SEO title/description/keyword suggestions (SEO Advisor), and `llms.txt` / `llms-full.txt` content (Auto LLMs). Data sent: the image being described (Auto Alt only), and/or the site/page text, keywords and settings you provide as prompt context.
  Terms: https://www.anthropic.com/legal/commercial-terms — Privacy: https://www.anthropic.com/legal/privacy
- **Google Gemini API** — alternative provider for the same four modules above, same data sent.
  Terms: https://ai.google.dev/gemini-api/terms — Privacy: https://policies.google.com/privacy

### Google Analytics (Stats module)

Connects to your own Google Analytics 4 property (via OAuth you authorize) to fetch traffic reports shown in the WordPress dashboard. Sends the OAuth token, your GA4 property ID and the selected date range; no data is sent until you connect your Google account from the Stats settings screen.
Terms: https://policies.google.com/terms — Privacy: https://policies.google.com/privacy

### Google Places API (Orari / Opening Hours module)

Fetches your business's opening hours from your Google Business Profile, using the Place ID and API key you provide.
Terms: https://policies.google.com/terms — Privacy: https://policies.google.com/privacy

### Google Tag Manager (Cookie Banner scanner and setup wizard)

To help you categorize cookies for the consent banner, the plugin fetches the public `gtm.js` file of the GTM container ID you configure, to detect which tracking tags are already set up inside it. No site or visitor data is sent — this is a read-only fetch of your own container's public script.
Terms: https://policies.google.com/terms — Privacy: https://policies.google.com/privacy

### ipify (Forms → MailUp settings)

Looks up your own server's public IP address, so you can allow-list it in your MailUp account. No site data is sent; the response only contains your server's IP.
https://www.ipify.org

### Mailjet API (Forms module, optional)

If you configure Mailjet as the delivery method for a form, submitted entries (the field values, plus sender/recipient email addresses) are sent to Mailjet to deliver the notification email. Only active if you enter your own Mailjet API/secret key.
Terms: https://www.mailjet.com/legal/terms-of-use/ — Privacy: https://www.mailjet.com/legal/privacy-policy/

### MailUp (Forms module, optional)

If you enable the MailUp newsletter integration on a form, the submitted field values you map are sent to your MailUp list to subscribe the visitor. Only active if you configure this integration.
Terms & Privacy: https://www.optimizely.com/legal/

### Your own headless frontend (cache invalidation)

If you run this site headless with a separate frontend (e.g. Next.js on Vercel), the plugin can call an on-demand cache-revalidation endpoint on *your own* frontend domain after content changes in WordPress, so the frontend cache stays in sync. This is not a HelloBarrio-operated service — it calls whatever frontend URL you configure.

### Auto Fields — "Analyze URL"

When you enter a URL in the Auto Fields tool, the plugin fetches that page's HTML to detect its structure and draft matching ACF fields. The URL is whatever you type in (e.g. a mockup or reference site); no data besides the HTTP request itself is sent.

---

## Modules

### 1. Auto Alt

Automatically generates **alt text** and **titles** for images uploaded to the Media Library by leveraging AI vision models.

#### Supported Providers

| Provider             | Models                             |
| -------------------- | ---------------------------------- |
| **Google Gemini**    | Gemini models with vision support  |
| **Anthropic Claude** | Claude models with vision support  |

#### Features

- **Auto-generation on upload**: When enabled, every newly uploaded image automatically receives an AI-generated alt text and title.
- **Batch processing**: Automatically processes existing images in the library that have missing alt or title texts in the background (runs in batches of 5 via WP Cron).
- **Manual review**: An admin interface allows you to view and modify the AI-generated alt/title texts before saving.
- **Prompt customization**: Fine-tune the tone (neutral, professional, friendly, technical, creative, SEO), style (descriptive, contextual, evocative, journalistic), site context, and optional keywords.
- **Multilingual**: Supports English, Italian, Spanish, French, and German.
- **Activity log**: Keeps a record of the last 200 operations (successes, errors, manual edits).

#### Configuration

Navigate to **Suite Barrio → Auto Alt → Settings**:

1. Select your AI provider (Gemini or Anthropic).
2. Enter the API key for the chosen provider.
3. Select the model you want to use.
4. Configure the tone, style, and language.
5. (Optional) Activate auto-generation on upload.

---

### 2. Forms

A drag-and-drop builder to create custom **contact forms**, save submissions, and optionally expose everything via **WPGraphQL** for headless frontends.

#### Supported Field Types

`text`, `email`, `textarea`, `tel`, `number`, `datepicker`, `select`, `radio`, `checkbox`, `file`

#### Features

- **Visual builder**: Create and edit forms seamlessly with drag-and-drop mechanics inside the admin panel.
- **Shortcode**: Insert any form into your WP pages using `[suite_barrio_form id="X"]` (the legacy `[wpb_form id="X"]` shortcode still works for content created with earlier versions).
- **Submissions**: Form entries are saved securely in the database along with the user's IP, user agent, and timestamp. These can be reviewed from the form's admin page.
- **Conditional logic**: Show or hide fields dynamically based on other field values using operators (`=`, `!=`, `contains`, `empty`, `notempty`) and logical relations (`AND`/`OR`).
- **Post-submission behavior**: Display a custom success message or redirect users to a specific URL.
- **Page insights**: Optional feature to track the URL, page title, and referrer of the page where the form was submitted.

#### GraphQL (Requires WPGraphQL)

When WPGraphQL is active, the module automatically registers:

**Queries**

- `hbForm(id: Int)` → Returns a complete form structure (fields, settings, validations)
- `hbForms` → Returns a list of all available forms

**Mutations**

- `hbSubmitForm(input: { formId, fields, pageUrl?, pageTitle?, pageReferrer? })` → Handles form submissions

#### CORS for Headless Frontends

To allow GraphQL mutations from an external domain (e.g., Next.js), define the following in your `wp-config.php`:

```php
define('SUITE_BARRIO_GRAPHQL_ALLOWED_ORIGINS', 'https://www.mysite.com,https://staging.mysite.com');
```

---

### 3. Auto Fields

Analyzes the HTML structure of a web page (via URL) and automatically generates an **ACF field group draft** alongside its corresponding, ready-to-use **GraphQL query**.

#### How it works

1. Enter the URL of a page (e.g., a static mockup or a reference website).
2. The HTML parser analyzes the DOM structure, extracting sections (`<section>`) and the content within them.
3. A live preview of the detected fields, organized by section, is presented.
4. Confirm the group name and location rules (post type, page, template).
5. The module creates the ACF field group programmatically via `acf_import_field_group()` and generates the matching GraphQL query.

#### Elements Detected by the Parser

| HTML Element                     | Generated ACF Field Type |
| -------------------------------- | ------------------------ |
| `<h1>`–`<h6>`                    | Text                     |
| `<p>` (short)                    | Text                     |
| `<p>` (long, >200 characters)    | Textarea                 |
| `<p>` with inline formatting     | WYSIWYG                  |
| `<img>`, `<picture>`, `<figure>` | Image                    |
| `<figcaption>`                   | Text                     |
| `<blockquote>`                   | Textarea                 |
| `<video>`                        | URL / File               |
| `<ul>`, `<ol>` with >3 items     | Repeater                 |
| `<a>` (link)                     | Link / URL               |

#### Requirements

- **ACF** must be installed and active to handle field group generation.

---

## Plugin Structure

```
suite-barrio/
├── suite-barrio.php                 # Entry point, global constants, module loader
├── modules/
│   ├── dashboard/
│   │   └── dashboard.php            # Main dashboard featuring module overview cards
│   ├── auto-alt/
│   │   └── auto-alt.php             # Auto Alt logic (AI APIs, batching, logging)
│   ├── auto-fields/
│   │   └── auto-fields.php          # HTML parser and ACF + GraphQL generation
│   └── forms/
│       ├── forms.php                # Form builder, shortcodes, and submissions
│       └── graphql.php              # WPGraphQL schema integration for forms
└── README.md
```

---

## Author

Developed by **[HelloBarrio](https://www.hellobarrio.com/)**.

---

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
