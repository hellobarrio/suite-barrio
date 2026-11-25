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

Migrate from Barrio → Migration, which administrators see while there is something to migrate. One step per module, in the right order: forms, SEO, company details, popups, banners, opening hours, blocks, ordering, duplication, alt text and analytics. Each step has a dry run, which shows the counts and the warnings without saving anything, then Migrate, which opens after the dry run. Every step can be run again: what Barrio Suite already has stays as it is. The page reads the module switches of Suite Barrio, so Suite Barrio stays active, with its modules on, until the last step is migrated.

Forms keep their ID, one form per request; old shortcodes in contents, meta and widgets are rewritten to `[barrio_form]` once every form is migrated; uploaded files move to the protected folder; IP addresses, user agents and referrers are not imported.

Until a module of Suite Barrio is migrated, the extension keeps the same module of Barrio Suite off, so the site does not get meta tags, popups, Duplicate links or the GraphQL types twice. At the end of each step the module of Suite Barrio turns off and the one of Barrio Suite on. Company details come from Info Azienda first, then from the SEO schema where Info Azienda has nothing: they only fill the empty fields. When the migration is done, delete the extension.

It needs Barrio Suite.

== Changelog ==

= 0.1.0 =
* First release.
