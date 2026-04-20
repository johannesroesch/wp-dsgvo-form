=== WP DSGVO Form ===
Contributors: johannesroesch
Tags: dsgvo, gdpr, contact form, encryption, privacy
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

DSGVO-konformes Formular-Plugin mit AES-256 verschluesselter Speicherung.

== Description ==

WP DSGVO Form ist ein WordPress-Plugin fuer datenschutzkonforme Kontaktformulare. Alle Einsendungen werden mit AES-256-GCM verschluesselt in der Datenbank gespeichert.

**Hauptfunktionen:**

* AES-256-GCM Verschluesselung aller Formulardaten
* Konfigurierbarer Formular-Builder in der Admin-UI
* Gutenberg-Block fuer einfache Einbindung
* CAPTCHA-Integration (selbstgehosteter Service)
* Einwilligungstexte mit Versionierung (Art. 7 DSGVO)
* Automatische Datenloeschung nach konfigurierbarer Aufbewahrungsfrist
* Empfaenger-Login mit eigenen Rollen (Reader, Supervisor)
* Datei-Upload mit serverseitiger Verschluesselung
* WordPress Privacy Data Exporter/Eraser (Art. 15, 17 DSGVO)
* Audit-Log fuer Supervisor-Zugriffe

**Datenschutz:**

* Verschluesselung at-rest (AES-256-GCM) — Daten sind in der Datenbank nicht lesbar
* HMAC-basierter Blind Index fuer Betroffenenanfragen (Art. 15 DSGVO)
* Automatische Loeschung abgelaufener Einsendungen (Art. 17 DSGVO)
* Einschraenkung der Verarbeitung moeglich (Art. 18 DSGVO)
* Einwilligungstexte unveraenderlich versioniert (Art. 7 Abs. 1 DSGVO)
* Kein externer Script-Load — CAPTCHA-Widget lokal gebundelt

**Voraussetzungen:**

* PHP 8.1 oder hoeher
* WordPress 6.0 oder hoeher
* OpenSSL PHP-Extension
* DSGVO_FORM_ENCRYPTION_KEY in wp-config.php definiert

== Installation ==

1. Plugin-ZIP hochladen unter Plugins > Installieren > Plugin hochladen.
2. Plugin aktivieren.
3. Verschluesselungs-Key in `wp-config.php` eintragen:

`define( 'DSGVO_FORM_ENCRYPTION_KEY', 'Ihr-Base64-encodierter-256bit-Key' );`

4. Unter DSGVO Formulare > Einstellungen die gewuenschten Optionen konfigurieren.
5. Neues Formular erstellen und per Gutenberg-Block einbinden.

== Frequently Asked Questions ==

= Wo werden die Formulardaten gespeichert? =

Alle Formulardaten werden AES-256-GCM verschluesselt in eigenen Datenbanktabellen gespeichert. Die Entschluesselung erfolgt nur bei berechtigtem Zugriff.

= Was passiert bei der Deinstallation? =

Alle Plugin-Daten werden vollstaendig geloescht: Datenbanktabellen, Rollen, Capabilities, Optionen, verschluesselte Upload-Dateien und Cron-Jobs.

= Welche Rechtsgrundlagen werden unterstuetzt? =

Das Plugin unterstuetzt die Rechtsgrundlagen "Einwilligung" (Art. 6 Abs. 1 lit. a DSGVO) und "Vertragsdurchfuehrung" (Art. 6 Abs. 1 lit. b DSGVO). Bei "Einwilligung" werden Einwilligungstexte versioniert gespeichert.

= Brauche ich einen externen CAPTCHA-Dienst? =

Nein. Das Plugin nutzt einen selbstgehosteten CAPTCHA-Service. Das Widget-Script wird lokal gebundelt — kein externer Script-Load, keine IP-Uebertragung beim Seitenaufruf.

== Changelog ==

= 1.0.7 =
* WordPress Plugin Checker Fixes (PHPCS, Prefixing, SQL)
* WordPress Privacy Data Exporter/Eraser (Art. 15 + 17 DSGVO)
* Datenschutzhinweis-Baustein via wp_add_privacy_policy_content()
* Consent legal_basis-Check und Locale-Whitelist
* NonPrefixedVariable-Fix in uninstall.php
* load_plugin_textdomain entfernt (WP 4.6+ Auto-Loading)
* PreparedSQLPlaceholders Regression behoben
* AuditLogger SQL phpcs:ignore ergaenzt
* WP_Filesystem fuer Dateioperationen

= 1.0.6 =
* CAPTCHA-Integration komplett ueberarbeitet
* captcha.min.js lokal gebundelt mit SRI-Hash

= 1.0.5 =
* Erste vollstaendige Release
* DSGVO-konforme Formulare mit AES-256-Verschluesselung
* Formular-Builder, Gutenberg-Block, Empfaenger-Login

== Upgrade Notice ==

= 1.0.7 =
WordPress Plugin Checker Kompatibilitaet, Privacy Data Exporter/Eraser, diverse Security-Fixes.
