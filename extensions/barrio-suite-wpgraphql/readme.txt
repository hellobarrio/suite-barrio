=== Barrio Suite WPGraphQL ===
Contributors: hellobarrio
Tags: graphql, headless, forms, seo, popup
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The Barrio Suite forms, SEO, popups, banners, opening hours and company details in WPGraphQL, for headless frontends.

== Description ==

Adds the Barrio Suite modules to the WPGraphQL schema, with the conventions of WPGraphQL and of plugins such as Yoast SEO and Gravity Forms: the `hb` prefix, `databaseId`, enums and dates in ISO 8601.

* forms: `hbForm`, `hbForms` and `hbSubmitForm`. Submissions go through the same pipeline as the REST API, spam checks and file checks included, and a rejected one comes back with an error for each field;
* SEO: `seo` on every public content type and taxonomy, `hbSeoSettings`, `hbSeoSchema`, `hbSitemap`, `hbRedirects`, `hbLlms` and `hbLlmsFull`, with the same data as the head, the sitemap and llms.txt of WordPress;
* popups, banners and opening hours: `hbPopup`, `hbBanner` and `hbOpeningHours`, the same popup, banner and hours as on WordPress: the days before a popup comes back, the period of popups and banners, the current and the usual week, the special days with their notes and the hours as HH:MM;
* company details: `hbCompanyInfo`, the name, contacts, legal data, offices and profiles of Barrio → Company details.

Each part only works while its Barrio Suite module is on. In GraphQL → Settings, "Frontend address" moves the links of the SEO data (canonical, JSON-LD, llms.txt) to the headless site; images stay on WordPress. Without WPGraphQL the extension does nothing.

Frontends written for the previous Suite Barrio plugin keep the shape of their queries, with new names and some new values: the porting guide is `docs/graphql-porting.md` in https://github.com/hellobarrio/suite-barrio (branch `stable`).

It needs Barrio Suite.

== Changelog ==

= 0.1.0 =
* First release.
