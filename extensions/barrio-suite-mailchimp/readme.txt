=== Barrio Suite Mailchimp ===
Contributors: hellobarrio
Tags: mailchimp, newsletter, forms
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds who fills in a Barrio Suite form to a Mailchimp audience.

== Description ==

Choose, form by form, the audience (one per language), the email field, the merge fields, the tags and an optional marketing consent field. By default Mailchimp emails new contacts a link to confirm (double opt-in); the consent tags are added only when the consent field is checked. The result of every subscription is in the entry history.

The API key goes in Barrio → Forms → Settings and is stored encrypted.

It needs Barrio Suite.

== External services ==

This extension sends data to Mailchimp (Intuit Mailchimp), and only for the forms where you turn it on: after each entry, the email address, the merge fields and the tags you chose go to `https://<dc>.api.mailchimp.com/3.0/`, authenticated with your API key. Mailchimp terms: https://mailchimp.com/legal/terms/ — privacy: https://www.intuit.com/privacy/statement/

== Changelog ==

= 0.1.0 =
* First release.
