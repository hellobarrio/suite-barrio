=== Barrio Suite Migration ===
Contributors: hellobarrio
Tags: migration, import, forms
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Moves forms, entries, files and settings from Suite Barrio, the previous plugin.

== Description ==

Run `wp barrio forms migrate --dry-run` to see the counts and warnings without saving anything, then `wp barrio forms migrate`. Forms keep their ID, old shortcodes are rewritten to `[barrio_form]`, uploaded files move to the protected folder; IP addresses, user agents and referrers are not imported. When the migration is done, delete the extension.

It needs Barrio Suite.

== Changelog ==

= 0.1.0 =
* First release.
