=== Barrio Suite Migration ===
Contributors: hellobarrio
Tags: migration, import, forms, seo, popups
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Moves the data of Suite Barrio, the previous plugin, to Barrio Suite, one module at a time.

== Description ==

Run `wp barrio forms migrate --dry-run` to see the counts and warnings without saving anything, then `wp barrio forms migrate`. Forms keep their ID, old shortcodes are rewritten to `[barrio_form]`, uploaded files move to the protected folder; IP addresses, user agents and referrers are not imported. When the migration is done, delete the extension.

For the SEO, run `wp barrio seo migrate --dry-run`, then `wp barrio seo migrate`: settings, schema, content and term meta and redirects move to Barrio Suite, then the SEO of Suite Barrio turns off and the one of Barrio Suite on. Until then, while the SEO of Suite Barrio is on, the extension keeps the SEO module of Barrio Suite off, so meta tags are not printed twice.

For popups, banners, opening hours, blocks, ordering, duplicate, alt text and analytics there is one command each: `wp barrio popups migrate`, `banners`, `hours`, `blocks`, `ordering`, `duplicate`, `alt` and `analytics`, always with `--dry-run` first. Until a module of Suite Barrio is migrated, the extension keeps the same module of Barrio Suite off, so the site does not get two popups, two Duplicate links or the GraphQL types twice. At the end of each command the module of Suite Barrio turns off and the one of Barrio Suite on. The commands can be run again: what Barrio Suite already has stays as it is.

It needs Barrio Suite.

== Changelog ==

= 0.1.0 =
* First release.
