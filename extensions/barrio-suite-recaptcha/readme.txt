=== Barrio Suite reCAPTCHA ===
Contributors: hellobarrio
Tags: recaptcha, spam, captcha, forms
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Invisible reCAPTCHA Enterprise check on the Barrio Suite forms, against spam bots.

== Description ==

Before each submission the form asks Google for an invisible token, and the server checks it with an assessment before saving the entry. It connects to Google with the service account of Barrio → Settings, which needs the reCAPTCHA Enterprise Agent and reCAPTCHA Enterprise Viewer roles in its Google Cloud project, and it is off until a score-based site key of that project is chosen in Barrio → Forms → Settings. If Google does not answer, the submission is refused and administrators see the error. The `barrio_recaptcha_threshold` filter changes the minimum score (0.5).

It needs Barrio Suite.

== External services ==

This extension uses Google reCAPTCHA Enterprise. On pages with a Barrio Suite form the browser loads `https://www.google.com/recaptcha/enterprise.js`, which collects data about the visit for Google; on each submission the server sends the token, the site key and the action to `https://recaptchaenterprise.googleapis.com/` (not the visitor's IP address). In Barrio → Forms → Settings the server asks the same API for the keys of the project, and Google for an access token of the service account (`https://oauth2.googleapis.com/token`). Google terms: https://policies.google.com/terms — privacy: https://policies.google.com/privacy — reCAPTCHA Enterprise terms: https://cloud.google.com/terms

== Changelog ==

= 0.1.0 =
* First release.
