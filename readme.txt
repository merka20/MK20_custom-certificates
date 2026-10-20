=== MK20 Custom Certificates ===
Contributors: merka20
Tags: certificates, learndash, buddypress, pdf, fpdf
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 1.4.3
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generates custom two-sided PDF certificates via FPDF when completing LearnDash courses.

== Description ==

This plugin automatically generates a two-sided PDF certificate when a student completes a LearnDash course.
It integrates with BuddyBoss/BuddyPress to display certificates on user profiles.
Full configuration of coordinates, colors and font sizes from the admin settings panel.
Certificates can also be uploaded automatically to an external API via POST multipart.

== Installation ==

1. Upload the `mk20-custom-certificates` folder to `/wp-content/plugins/`
2. Activate the plugin from the Plugins menu
3. Go to Settings → MK20 Certificates to configure

== Changelog ==

= 1.4.3 =
* Course titles are decoded before rendering the PDF, so HTML entities no longer appear
* The verification page always uses the public course title, without internal prefixes

= 1.4.2 =
* Added a verification page logo setting, independent from the theme logo and the site icon

= 1.4.1 =
* Fixed certificate generation failing when the QR code image was fetched as a temporary file without extension
* QR code errors no longer prevent the certificate from being issued

= 1.4.0 =
* Batched external certificate sync with WP-Cron, progress spinner and backoff
* Certificate pagination on BuddyBoss profiles (20 per page)
* Automatic start/end dates on native certificates from LearnDash activity
* Reduced HTTP timeouts and retries only on authentication errors
* Fixed infinite sync polling and deferred item handling

= 1.3.0 =
* Automatic upload of generated certificates to an external API via POST multipart
* Public certificate verification with indexed lookup table and rate limiting
* Hardened public download and verification endpoints

= 1.2.0 =
* External certificate import and sync from an external API
* Rejected certificate management and audit logging

= 1.1.0 =
* Improved coordinate and color management
* BuddyBoss xprofile fields support
* PDF preview from admin panel

= 1.0.0 =
* Initial release
