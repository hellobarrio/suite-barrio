=== Barrio Suite ===
Contributors: hellobarrio
Tags: forms, contact form, form builder, gdpr, multilingual
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

Integrations with other plugins and services are separate extensions, so the core contains no code for third parties.

= Source code =

The admin pages are built from the sources in `modules/forms/assets/src` with `@wordpress/scripts` (`npm install && npm run build`). The full repository is at https://github.com/hellobarrio/suite-barrio (branch `stable`).

== Installation ==

1. Upload the plugin and activate it. It needs MySQL 8.0.16+ or MariaDB 10.5+ with InnoDB, and refuses to activate with a clear message otherwise.
2. Open Barrio → Dashboard and follow the site launch steps (SMTP, email template, privacy).
3. Create a form in Barrio → Forms and add the Barrio Form block to a page.

== Frequently Asked Questions ==

= Are my entries deleted when I delete the plugin? =

No, unless you choose so in Barrio → Settings before deleting it.

= Does it work without JavaScript? =

The form is shown, but it is sent only with JavaScript enabled: part of the spam protection runs in the browser.

== External services ==

This plugin installs and updates itself and its extensions from GitHub. When WordPress checks for plugin updates, when an administrator opens Barrio → Extensions or Barrio → Settings, and at night if automatic updates are on, the site downloads `catalog.json` from the latest release of https://github.com/hellobarrio/suite-barrio (at most once an hour); installing or updating downloads the zip files of that release. No data about the site or its users is sent, apart from what any HTTP request carries (the site's IP address and the WordPress user agent). GitHub terms: https://docs.github.com/site-policy/github-terms/github-terms-of-service — privacy: https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement

Emails are sent through the SMTP server you configure, if any.

== Changelog ==

= 0.1.0 =
* First release.
