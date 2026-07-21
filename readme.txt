=== WP DSGVO Form ===
Contributors: johannesroesch
Tags: gdpr, contact form, encryption, privacy
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

GDPR-compliant contact form plugin with AES-256 encrypted data storage.

== Description ==

WP DSGVO Form is a WordPress plugin for GDPR-compliant contact forms. All submissions are stored AES-256-GCM encrypted in the database.

**Main Features:**

* AES-256-GCM encryption of all form data
* Configurable form builder in the admin UI
* Gutenberg block for easy embedding
* CAPTCHA integration (self-hosted service)
* Consent texts with versioning (Art. 7 GDPR)
* Automatic data deletion after configurable retention period
* Recipient login with custom roles (Reader, Supervisor)
* File upload with server-side encryption
* WordPress Privacy Data Exporter/Eraser (Art. 15, 17 GDPR)
* Audit log for Supervisor access

**Privacy:**

* Encryption at rest (AES-256-GCM) — data is unreadable in the database
* HMAC-based blind index for data subject requests (Art. 15 GDPR)
* Automatic deletion of expired submissions (Art. 17 GDPR)
* Restriction of processing possible (Art. 18 GDPR)
* Consent texts immutably versioned (Art. 7 para. 1 GDPR)
* No external script loading — CAPTCHA widget bundled locally

**Requirements:**

* PHP 8.1 or higher
* WordPress 6.0 or higher
* OpenSSL PHP extension
* DSGVO_FORM_ENCRYPTION_KEY defined in wp-config.php

== Installation ==

1. Upload the plugin ZIP under Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Add the encryption key to `wp-config.php`:

`define( 'DSGVO_FORM_ENCRYPTION_KEY', 'your-base64-encoded-256bit-key' );`

4. Configure the desired options under DSGVO Forms > Settings.
5. Create a new form and embed it via the Gutenberg block.

== Frequently Asked Questions ==

= Where is form data stored? =

All form data is stored AES-256-GCM encrypted in custom database tables. Decryption only occurs on authorized access.

= What happens on uninstall? =

All plugin data is completely deleted: database tables, roles, capabilities, options, encrypted upload files, and cron jobs.

= Which legal bases are supported? =

The plugin supports the legal bases "Consent" (Art. 6 para. 1 lit. a GDPR) and "Contract performance" (Art. 6 para. 1 lit. b GDPR). For "Consent", consent texts are stored with versioning.

= Do I need an external CAPTCHA service? =

No. The plugin uses a self-hosted CAPTCHA service. The widget script is bundled locally — no external script loading, no IP transfer on page load.

= Do I need to exclude pages with forms from caching? =

Yes. Pages with DSGVO forms should be excluded from page caching (e.g. WP Super Cache, W3 Total Cache, LiteSpeed Cache). Cached pages may contain expired CAPTCHA tokens and WordPress nonces, causing failed form submissions.

== Changelog ==

= 1.3.0 =
* Security: npm dependencies updated to 0 vulnerabilities
* ESLint flat config for ESLint v10
* WordPress Plugin Checker compatibility fixes

= 1.1.0 =
* Internationalization: 6 languages (de_DE, en_US, fr_FR, es_ES, it_IT, sv_SE)
* FormEditor: field width configuration
* FirstLoginNotice for new recipients
* Eraser bug fixes (pagination drift, done-flag infinite loop)
* Controller placeholders in consent templates (Art. 7 para. 2+3 GDPR)
* DSFA notice, CAPTCHA settings toggle, cache exclusion

= 1.0.7 =
* WordPress Plugin Checker fixes (PHPCS, prefixing, SQL)
* WordPress Privacy Data Exporter/Eraser (Art. 15 + 17 GDPR)
* Privacy policy notice via wp_add_privacy_policy_content()
* Consent legal_basis check and locale whitelist

= 1.0.5 =
* First complete release
* GDPR-compliant forms with AES-256 encryption
* Form builder, Gutenberg block, recipient login

== Upgrade Notice ==

= 1.3.0 =
Security update: npm dependencies updated, ESLint v10 compatibility, Plugin Checker fixes.
