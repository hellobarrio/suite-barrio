=== Barrio Suite HubSpot ===
Contributors: hellobarrio
Tags: hubspot, crm, forms
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends who fills in a Barrio Suite form to a HubSpot form, with the privacy consent and the subscription.

== Description ==

Choose, form by form, the HubSpot form (one per language), the contact properties and their fields, the privacy consent field and, if you want, a subscription type with its consent field. HubSpot creates the contact or updates it, records the consent with the text of the field, and links the entry to the visitor's tracked visits through the `hubspotutk` cookie, when the visitor has it. A headless frontend sends that cookie as the `_barrio_hutk` value. The result of every submission is in the entry history.

The HubSpot account ID goes in Barrio → Forms → Settings. No API key is needed.

It needs Barrio Suite.

== External services ==

This extension sends data to HubSpot, and only for the forms where you turn it on: after each entry, the fields you chose, the consent texts, the page address and the `hubspotutk` cookie go to `https://api.hsforms.com/submissions/v3/integration/submit/`. HubSpot terms: https://legal.hubspot.com/terms-of-service — privacy: https://legal.hubspot.com/privacy-policy

== Changelog ==

= 0.1.0 =
* First release.
