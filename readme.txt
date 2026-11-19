=== Barrio Suite ===
Contributors: hellobarrio
Tags: seo, contact form, form builder, schema, multilingual
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Forms and site tools by Hellobarrio: a form builder with versioned fields, entries, email notifications and privacy by default.

== Description ==

Barrio Suite starts with a Forms module:

* A drag and drop builder with text, email, phone, number, date, choices, file upload, consent, hidden fields and page breaks.
* Conditional logic, checked in the browser and again on the server.
* Every saved form is a new version: each entry keeps the labels and the consent text the visitor read.
* Entries with search, filters, bulk actions, notes and CSV export.
* Email notifications with merge tags, conditions, attachments and your own SMTP server.
* Privacy by default: no IP address, user agent or referrer is stored, uploaded files go to a protected folder, entries can be deleted automatically after a number of days, and the WordPress personal data tools find them by email address.
* A block and the `[barrio_form id="…"]` shortcode, both cache friendly (no nonce in the page).
* Translations of every text when an extension provides the languages (for example WPML).

And an SEO module: titles and meta descriptions, Open Graph, JSON-LD (organization, website, page and breadcrumbs), the WordPress sitemap with only indexable content, redirects (also created automatically when the URL of a content changes) and llms.txt. An SEO panel in the block editor, the classic editor and the term pages shows the Google and social previews and a score with the checks to improve. If the site already uses another SEO plugin, such as Yoast SEO or Rank Math, turn the SEO module off.

And a Performance module, with what WordPress already provides and no external services: the sizes of new JPEG images in WebP or AVIF (the uploaded file is kept as it is), speculative loading of the next page (prefetch or prerender, with the eagerness you choose) and a lighter page head, without the emoji script and styles, the generator tag, the RSD link and the shortlink.

And a Blocks module, for what the people who write find in the block editor, chosen role by role in Barrio → Contents: the blocks they can insert, and restrictions such as no heading 1, only the colors and font sizes of the theme, no code editor, no locking of blocks, and no Author, Revisions and Parent panels. Administrators always have everything, and the contents and the site do not change. It also chooses the editor of each content type (block, classic or none, keeping the content in the REST API) and can turn off the patterns from WordPress.org and the Openverse images. Nothing changes until you choose.

And an Ordering module: a manual order for the content types and taxonomies you choose, chosen in Barrio → Contents. Each of them gets a Reorder page in its menu, such as Pages → Reorder Pages, to drag the items (with the keyboard too) with the hierarchy of pages and categories. The order applies wherever the site lists those items without choosing another order: archives, admin lists, the REST API, the Query Loop block and WPGraphQL. Saving it does not change the modification date of the contents, and turning it off deletes nothing. Its Permissions choose which roles can reorder.

And a Duplication module: a Duplicate link in the lists of contents and terms, in the bulk actions and in the block and classic editors. The copy is a draft of whoever duplicates it, with the content, the custom fields (ACF included) and the terms of the original, and only those who can edit the original can duplicate it. Choose the content types, the taxonomies and the roles that can duplicate in Barrio → Contents.

Every module can be turned off in Barrio → Settings: it disappears from the menu and from the site, and its data is kept.

Integrations with other plugins and services are separate extensions, so the core contains no code for third parties. Barrio → Extensions installs them with one click.

= Source code =

The admin pages are built from the sources in `assets/src` and `modules/*/assets/src` with `@wordpress/scripts` (`npm install && npm run build`). The full repository is at https://github.com/hellobarrio/suite-barrio (branch `stable`).

== Installation ==

1. Upload the plugin and activate it. It needs MySQL 8.0.16+ or MariaDB 10.5+ with InnoDB, and refuses to activate with a clear message otherwise.
2. Open Barrio → Dashboard and follow the site launch steps (SMTP, email template, privacy…).
3. Create a form in Barrio → Forms and add the Barrio Form block to a page.

== Frequently Asked Questions ==

= Are my entries deleted when I delete the plugin? =

No, unless you choose so in Barrio → Settings before deleting it.

= Does it work without JavaScript? =

The form is shown, but it is sent only with JavaScript enabled: part of the spam protection runs in the browser.

== External services ==

Emails are sent through the SMTP server you configure, if any.

The AI features use Gemini on Vertex AI, a service of Google Cloud, with the service account you add in Barrio → Settings, and only from the admin: with the service account saved, Barrio → Settings reads the regions and the models of Google; "Generate with AI" sends the title, the address and up to 4,000 characters of the text of a page (never the text of a password-protected post), the site name and the description of the site from the settings, or, for llms.txt, the current llms.txt of the site. Each request first gets an access token from Google with the key of the service account. Google Cloud terms: https://cloud.google.com/terms — Google privacy policy: https://policies.google.com/privacy

== Changelog ==

= 0.1.0 =
* First release.
