=== Barrio Suite WPGraphQL ===
Contributors: hellobarrio
Tags: graphql, headless, forms, seo
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The Barrio Suite forms and SEO in WPGraphQL, for headless frontends.

== Description ==

Adds the Barrio Suite forms and SEO to the WPGraphQL schema, with the same names and types as the previous Suite Barrio plugin, so existing headless frontends keep working unchanged:

* forms: `hbForm`, `hbForms` and `hbSubmitForm`. Submissions go through the same pipeline as the REST API, spam checks and file checks included;
* SEO: `seo` on every public content type, `hbSeoSite`, `hbSchemaRaw`, `hbSitemap`, `hbRedirects`, `hbLlms` and `hbLlmsFull`, with the same data as the head, the sitemap and llms.txt of WordPress.

Each part only works while its Barrio Suite module is on. In GraphQL → Settings, "Frontend address" moves the links of the SEO data (canonical, JSON-LD, llms.txt) to the headless site; images stay on WordPress. Without WPGraphQL the extension does nothing.

It needs Barrio Suite.

== Changelog ==

= 0.1.0 =
* First release.
