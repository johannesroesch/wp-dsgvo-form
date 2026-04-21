# WP DSGVO Form

DSGVO-konformes Formular-Plugin für WordPress mit AES-256 verschlüsselter Speicherung.

![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple)
![License](https://img.shields.io/badge/License-GPLv2-green)
![Version](https://img.shields.io/badge/Version-1.1.0-orange)

## Überblick

WP DSGVO Form ist ein WordPress-Plugin für datenschutzkonforme Kontaktformulare. Alle Einsendungen werden mit AES-256-GCM verschlüsselt in der Datenbank gespeichert. Das Plugin wurde mit Fokus auf die Anforderungen der DSGVO (Datenschutz-Grundverordnung) entwickelt.

### Hauptfunktionen

- **AES-256-GCM Verschlüsselung** aller Formulardaten (at-rest)
- **Konfigurierbarer Formular-Builder** in der Admin-UI mit Feldbreiten-Steuerung
- **Gutenberg-Block** für einfache Einbindung in Beiträge und Seiten
- **CAPTCHA-Integration** mit selbstgehostetem Service (kein externer Script-Load)
- **Einwilligungstexte** mit sprachspezifischer Versionierung (Art. 7 DSGVO)
- **Automatische Datenlöschung** nach konfigurierbarer Aufbewahrungsfrist
- **Empfänger-Login** mit eigenen Rollen (Reader, Supervisor)
- **Datei-Upload** mit serverseitiger Verschlüsselung
- **WordPress Privacy Data Exporter/Eraser** (Art. 15, 17 DSGVO)
- **Audit-Log** für Supervisor-Zugriffe
- **Internationalisierung** — 6 Sprachen (de_DE, en_US, fr_FR, es_ES, it_IT, sv_SE)

## Architektur

```
wp-dsgvo-form/
├── wp-dsgvo-form.php          # Plugin-Einstiegspunkt, Hooks
├── includes/                   # PHP-Klassen (Namespace: WpDsgvoForm\)
│   ├── Admin/                  # Admin-Seiten (FormEditPage, Settings, etc.)
│   ├── Models/                 # Datenmodelle (Form, Submission, ConsentVersion)
│   ├── Security/               # EncryptionService, KekRotation, CaptchaVerifier
│   └── Privacy/                # PrivacyHandler, DataExporter, DataEraser
├── src/                        # React-Quellcode (Gutenberg Block)
├── build/                      # Kompilierte Block-Assets
├── public/js/                  # captcha.min.js (lokal gebündelt)
├── languages/                  # .pot/.po/.mo Übersetzungsdateien
├── templates/                  # E-Mail- und Consent-Templates
└── tests/                      # PHPUnit-Tests
```

### Datenbank

Alle Custom Tables verwenden `$wpdb->prefix`:

| Tabelle | Inhalt |
|---------|--------|
| `wp_dsgvoform_forms` | Formular-Konfigurationen |
| `wp_dsgvoform_fields` | Feld-Definitionen pro Formular |
| `wp_dsgvoform_submissions` | Einsendungen (AES-256 verschlüsselt) |
| `wp_dsgvoform_recipients` | Empfänger pro Formular |
| `wp_dsgvoform_consent_versions` | Einwilligungstexte (versioniert, pro Sprache) |
| `wp_dsgvoform_audit_log` | Audit-Log (Supervisor-Zugriffe) |

### Rollen

| Rolle | Slug | Zugriff |
|-------|------|---------|
| Einsendungs-Leser | `wp_dsgvo_form_reader` | Nur eigene zugewiesene Formulare |
| Supervisor | `wp_dsgvo_form_supervisor` | Alle Formulare (mit Audit-Logging) |

## Datenschutz-Features

| DSGVO-Artikel | Umsetzung |
|---------------|-----------|
| Art. 5 Abs. 1 lit. c (Datenminimierung) | Kein externer Script-Load, CAPTCHA lokal gebündelt |
| Art. 6 Abs. 1 lit. a (Einwilligung) | Hard-Block — Formular nur mit angekreuzter Checkbox absendbar |
| Art. 6 Abs. 1 lit. b (Vertrag) | Unterstützt als alternative Rechtsgrundlage |
| Art. 7 Abs. 1 (Nachweispflicht) | Einwilligungstexte unveränderlich versioniert |
| Art. 12 Abs. 1 (Verständliche Sprache) | Consent-Texte pro Sprache konfigurierbar, Fail-Closed bei fehlender Übersetzung |
| Art. 15 (Auskunftsrecht) | WordPress Privacy Data Exporter |
| Art. 17 (Recht auf Löschung) | WordPress Privacy Data Eraser + automatische Löschung |
| Art. 18 (Einschränkung) | Einsendungen können als eingeschränkt markiert werden |
| Art. 25 (Privacy by Design) | Verschlüsselung at-rest, HMAC-basierter Blind Index |
| Art. 30 (Verarbeitungsverzeichnis) | Audit-Log aller Datenzugriffe |

## Voraussetzungen

- PHP 8.1 oder höher
- WordPress 6.0 oder höher
- OpenSSL PHP-Extension
- `DSGVO_FORM_ENCRYPTION_KEY` in `wp-config.php` definiert

## Installation

1. Plugin-ZIP hochladen unter **Plugins > Installieren > Plugin hochladen**.
2. Plugin aktivieren.
3. Verschlüsselungs-Key in `wp-config.php` eintragen:

   ```php
   define( 'DSGVO_FORM_ENCRYPTION_KEY', 'Ihr-Base64-encodierter-256bit-Key' );
   ```

4. Unter **DSGVO Formulare > Einstellungen** die gewünschten Optionen konfigurieren.
5. Neues Formular erstellen und per Gutenberg-Block einbinden.

> **Wichtig:** Seiten mit DSGVO-Formularen sollten vom Seiten-Cache ausgeschlossen werden (z.B. WP Super Cache, W3 Total Cache, LiteSpeed Cache). Gecachte Seiten können abgelaufene CAPTCHA-Tokens und WordPress-Nonces enthalten.

## FAQ

### Wo werden die Formulardaten gespeichert?

Alle Formulardaten werden AES-256-GCM verschlüsselt in eigenen Datenbanktabellen gespeichert. Die Entschlüsselung erfolgt nur bei berechtigtem Zugriff.

### Was passiert bei der Deinstallation?

Alle Plugin-Daten werden vollständig gelöscht: Datenbanktabellen, Rollen, Capabilities, Optionen, verschlüsselte Upload-Dateien und Cron-Jobs.

### Welche Rechtsgrundlagen werden unterstützt?

Das Plugin unterstützt die Rechtsgrundlagen "Einwilligung" (Art. 6 Abs. 1 lit. a DSGVO) und "Vertragsdurchführung" (Art. 6 Abs. 1 lit. b DSGVO). Bei "Einwilligung" werden Einwilligungstexte versioniert gespeichert.

### Brauche ich einen externen CAPTCHA-Dienst?

Nein. Das Plugin nutzt einen selbstgehosteten CAPTCHA-Service. Das Widget-Script wird lokal gebündelt — kein externer Script-Load, keine IP-Übertragung beim Seitenaufruf.

## Changelog

### 1.1.0

- Internationalisierung: 6 Sprachen (de_DE, en_US, fr_FR, es_ES, it_IT, sv_SE)
- FormEditor: Feldbreiten-Konfiguration
- FirstLoginNotice für neue Empfänger
- Eraser-Bugs behoben (Pagination-Drift, done-Flag Endlosschleife)
- Controller-Platzhalter in Einwilligungstemplates (Art. 7 Abs. 2+3 DSGVO)
- DSFA-Hinweis, CAPTCHA-Settings-Toggle, Cache-Exclusion

### 1.0.7

- WordPress Plugin Checker Fixes (PHPCS, Prefixing, SQL)
- WordPress Privacy Data Exporter/Eraser (Art. 15 + 17 DSGVO)
- Datenschutzhinweis-Baustein via `wp_add_privacy_policy_content()`
- Consent legal_basis-Check und Locale-Whitelist
- WP_Filesystem für Dateioperationen

### 1.0.6

- CAPTCHA-Integration komplett überarbeitet
- captcha.min.js lokal gebündelt mit SRI-Hash

### 1.0.5

- Erste vollständige Release
- DSGVO-konforme Formulare mit AES-256-Verschlüsselung
- Formular-Builder, Gutenberg-Block, Empfänger-Login

## Haftungsausschluss

Dieses Plugin stellt technische Maßnahmen zur Unterstützung der DSGVO-Konformität bereit (Verschlüsselung, Einwilligungsverwaltung, automatische Löschung, Audit-Logging). Es ersetzt jedoch keine Rechtsberatung.

Der Betreiber der Website ist für die korrekte Konfiguration des Plugins, die rechtliche Prüfung der Einwilligungstexte, die Einhaltung der Aufbewahrungsfristen sowie die Erstellung eines vollständigen Verarbeitungsverzeichnisses selbst verantwortlich. Bei Fragen zur datenschutzrechtlichen Konformität wenden Sie sich bitte an Ihren Datenschutzbeauftragten oder einen spezialisierten Rechtsanwalt.

## Lizenz

GPLv2 or later — siehe [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
