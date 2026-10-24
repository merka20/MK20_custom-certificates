=== MK20 Custom Certificates ===
Contributors: merka20
Tags: certificates, learndash, buddypress, pdf, fpdf
Requires at least: 5.0
Tested up to: 7.1
Stable tag: 1.4.11
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

= 1.4.11 =
* Read API for integrations: `mk20_get_user_certificates()` returns the certificates of a user (native and external)
* New `mk20_user_can_download_certificate` filter to delegate certificate downloads to other plugins (group leaders panel). Default behaviour unchanged: owner and administrators only

= 1.4.10 =
* Ghostscript diagnostics: when it is not detected, the settings page shows the exit code and stderr of each binary tested (127 = not found or not in PHP's PATH)

= 1.4.9 =
* New "Check again" button next to the Ghostscript status: clears the 12-hour cache and re-detects it without saving the settings

= 1.4.8 =
* The certificate link and the course-page notice are now part of the plugin (previously a separate must-use plugin)
* After updating, delete the `lares-certificado-curso-mk20.php` mu-plugin: keeping both would show the notice twice

= 1.4.7 =
* Per-course certificate switch: all courses generate certificates by default; check "No generar certificado" to disable a specific course
* Fixes the inverted behaviour introduced in 1.4.6 and cleans its temporary post meta

= 1.4.5 =
* DNI/NIE is now read from the dni_nie, nif_nie, dni, nif or nie user meta when the configured BuddyBoss field is empty

= 1.4.4 =
* Native certificate start date now uses the student's first lesson/topic/quiz activity instead of the course or enrollment row
* Optional compression of external Lares PDFs with Ghostscript (150/300/72 dpi) before saving; the original is kept if Ghostscript is unavailable
* Certificate attachments no longer generate unused PDF previews and thumbnails, reducing storage usage

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
