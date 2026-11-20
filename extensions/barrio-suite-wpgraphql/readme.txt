=== Barrio Suite WPGraphQL ===
Contributors: hellobarrio
Tags: graphql, headless, forms, seo, popup
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The Barrio Suite forms, SEO, popups, banners and opening hours in WPGraphQL, for headless frontends.

== Description ==

Adds the Barrio Suite forms, SEO, popups, banners and opening hours to the WPGraphQL schema, with the same names and types as the previous Suite Barrio plugin, so existing headless frontends keep working unchanged:

* forms: `hbForm`, `hbForms` and `hbSubmitForm`. Submissions go through the same pipeline as the REST API, spam checks and file checks included;
* SEO: `seo` on every public content type, `hbSeoSite`, `hbSchemaRaw`, `hbSitemap`, `hbRedirects`, `hbLlms` and `hbLlmsFull`, with the same data as the head, the sitemap and llms.txt of WordPress;
* popups, banners and opening hours: `hbPopup`, `hbBanner` and `hbOrari`, the same popup, banner and hours as on WordPress, with the data the previous plugin did not have: the days before a popup comes back, the period of popups and banners, the usual week, the special days with their notes and the hours as HH:MM.

Each part only works while its Barrio Suite module is on. In GraphQL → Settings, "Frontend address" moves the links of the SEO data (canonical, JSON-LD, llms.txt) to the headless site; images stay on WordPress. Without WPGraphQL the extension does nothing.

It needs Barrio Suite.

== Changelog ==

= 0.1.0 =
* First release.
