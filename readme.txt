=== MK20 Custom Certificates ===
Contributors: merka20
Tags: certificates, learndash, buddypress, pdf, fpdf
Requires at least: 5.0
Tested up to: 7.0
Stable tag: 1.3.0
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
