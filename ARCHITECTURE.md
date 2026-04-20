# ARCHITECTURE.md — wp-dsgvo-form

> WordPress-Plugin für DSGVO-konforme Formulare mit AES-256-GCM-verschlüsselter Speicherung

**Version:** 2.2.0
**Stand:** 2026-04-20
**Architekt:** architect
**Status:** FREIGEGEBEN — Verbindlich für alle Entwickler

---

## Inhaltsverzeichnis

1. [Plugin-Verzeichnisstruktur](#1-plugin-verzeichnisstruktur)
2. [Datenbank-Schema](#2-datenbank-schema)
3. [Verschlüsselungskonzept](#3-verschlüsselungskonzept)
4. [PHP-Modulstruktur](#4-php-modulstruktur)
5. [REST-API-Design](#5-rest-api-design)
6. [Gutenberg Block](#6-gutenberg-block)
7. [WordPress-Integration](#7-wordpress-integration)
8. [Empfänger- & Rollensystem](#8-empfänger---rollensystem)
9. [CAPTCHA-Integration](#9-captcha-integration)
10. [Spam-Schutz](#10-spam-schutz)
11. [Sicherheitskonzept](#11-sicherheitskonzept)
12. [DSGVO-Compliance](#12-dsgvo-compliance)
13. [Build-System & Tooling](#13-build-system--tooling)
14. [i18n / Mehrsprachigkeit](#14-i18n--mehrsprachigkeit)
15. [Performance-Konzept](#15-performance-konzept)
16. [Anhang: Design-Entscheidungen](#16-anhang-design-entscheidungen)

---

## Rahmenbedingungen

| Parameter | Wert |
|-----------|------|
| PHP-Version | >= 8.1 (strict_types Pflicht) |
| WordPress-Version | >= 6.4 |
| Verschlüsselung | AES-256-GCM (openssl) |
| KEK-Konstante | `DSGVO_FORM_ENCRYPTION_KEY` in wp-config.php |
| Externe PHP-Dependencies | Keine (self-contained) |
| Build-System | `@wordpress/scripts` (webpack) |
| Coding-Standards | WPCS + PSR-12, PHPStan Level 6 |
| Text-Domain | `wp-dsgvo-form` |
| DB-Prefix | `{$wpdb->prefix}dsgvo_` |
| REST-Namespace | `dsgvo-form/v1` |
| Hook-/Funktions-Prefix | `wpdsgvo_` |

---

## 1. Plugin-Verzeichnisstruktur

```
wp-dsgvo-form/
├── wp-dsgvo-form.php                  # Plugin-Hauptdatei (Bootstrap)
├── uninstall.php                      # Sauberes Deinstallieren (DB + Files + Roles)
├── composer.json                      # PSR-4 Autoloading, Dev-Dependencies
├── package.json                       # @wordpress/scripts, npm
├── webpack.config.js                  # Erweiterte webpack-Config
├── .phpcs.xml.dist                    # WPCS + PSR-12 Ruleset
├── phpstan.neon                       # PHPStan Level 6 Config
├── .eslintrc.json                     # @wordpress/eslint-plugin
├── ARCHITECTURE.md                    # Dieses Dokument
├── CHANGELOG.md                       # Keep-a-Changelog-Format
│
├── includes/                          # PHP-Kernlogik (PSR-4: WpDsgvoForm\)
│   ├── Plugin.php                     # Zentrale Plugin-Klasse (Singleton, Boot)
│   ├── Activator.php                  # DB-Tabellen, Rollen, Cron
│   ├── Deactivator.php                # Cron entfernen
│   │
│   ├── Admin/                         # Admin-Backend
│   │   ├── AdminMenu.php              # Menü-Registrierung
│   │   ├── FormListPage.php           # Formular-Übersicht (WP_List_Table)
│   │   ├── FormEditPage.php           # Formular-Builder (PHP-basiert)
│   │   ├── SubmissionListPage.php     # Einsendungs-Übersicht (Admin)
│   │   ├── SubmissionViewPage.php     # Einzelne Einsendung (entschlüsselt)
│   │   ├── RecipientListPage.php      # Empfänger-Verwaltung
│   │   ├── SettingsPage.php           # Plugin-Einstellungen
│   │   └── AdminBarNotification.php   # Unread-Badge in WP Admin Bar
│   │
│   ├── Auth/                          # Authentifizierung & Autorisierung
│   │   ├── RoleManager.php            # Rollen-Registrierung + Härtung
│   │   ├── AccessControl.php          # Capability-Checks, IDOR-Schutz
│   │   └── LoginRedirect.php          # Login-Redirect für Reader/Supervisor
│   │
│   ├── Models/                        # Datenmodelle (Repository-Pattern)
│   │   ├── Form.php                   # Formular CRUD + Caching
│   │   ├── Field.php                  # Feld CRUD + Sortierung
│   │   ├── Submission.php             # Einsendungs CRUD + Lazy Loading
│   │   ├── Recipient.php              # Empfänger-Zuordnung CRUD
│   │   └── ConsentVersion.php         # Consent-Versionierung CRUD
│   │
│   ├── Encryption/                    # Verschlüsselungsmodul
│   │   ├── EncryptionService.php      # AES-256-GCM (Daten + Dateien)
│   │   └── KeyManager.php             # KEK-Zugriff, HMAC-Key-Ableitung
│   │
│   ├── Api/                           # REST-API-Endpunkte
│   │   ├── SubmitEndpoint.php         # POST /submit (öffentlich)
│   │   ├── FormsEndpoint.php          # CRUD Formulare (Admin)
│   │   ├── FieldsEndpoint.php         # CRUD Felder (Admin)
│   │   ├── SubmissionsEndpoint.php    # Einsendungen lesen (Empfänger)
│   │   ├── SubmissionDeleteEndpoint.php # Einsendungen löschen
│   │   └── RecipientsEndpoint.php     # Empfänger-Zuordnung (Admin)
│   │
│   ├── Block/                         # Gutenberg-Block (PHP-Seite)
│   │   └── FormBlock.php              # Block-Registrierung + SSR
│   │
│   ├── Email/                         # E-Mail-Benachrichtigungen
│   │   └── NotificationService.php    # E-Mail-Versand (kein Klartext!)
│   │
│   ├── Captcha/                       # CAPTCHA-Integration
│   │   └── CaptchaVerifier.php        # Token-Validierung (fail-closed)
│   │
│   ├── Validation/                    # Eingabevalidierung
│   │   └── FieldValidator.php         # Server-seitige Validierung pro Feldtyp
│   │
│   ├── Upload/                        # Datei-Upload-Handling
│   │   └── FileHandler.php            # Upload + verschlüsselte Speicherung
│   │
│   ├── Privacy/                       # DSGVO / WP Privacy Tools
│   │   └── PrivacyHandler.php         # Exporter + Eraser Hooks
│   │
│   ├── Audit/                         # Audit-Logging
│   │   └── AuditLogger.php            # Protokollierung (view/export/delete)
│   │
│   └── Exception/                     # Exception-Hierarchie
│       ├── WpDsgvoException.php       # Basis-Exception
│       ├── EncryptionException.php
│       ├── ValidationException.php
│       ├── AuthenticationException.php
│       └── StorageException.php
│
├── src/                               # JavaScript (Gutenberg Block + Frontend)
│   ├── block/                         # Gutenberg Block
│   │   ├── index.js                   # Block-Registrierung
│   │   ├── edit.js                    # Editor-Komponente
│   │   ├── save.js                    # return null (dynamisch gerendert)
│   │   ├── inspector.js               # InspectorControls (Sidebar)
│   │   └── block.json                 # Block-Metadaten + Supports
│   │
│   ├── frontend/                      # Frontend-Formular (Vanilla JS!)
│   │   └── form-handler.js            # Submit, CAPTCHA, Validierung, Honeypot
│   │
│   └── styles/
│       ├── editor.scss
│       ├── frontend.scss
│       └── admin.scss
│
├── templates/                         # PHP-Templates
│   ├── submission-list.php            # Empfänger: Einsendungs-Liste
│   └── submission-detail.php          # Empfänger: Detail-Ansicht
│
├── languages/                         # i18n (.po/.mo)
│   ├── wp-dsgvo-form.pot              # POT-Template
│   ├── wp-dsgvo-form-de_DE.po
│   ├── wp-dsgvo-form-en_US.po
│   ├── wp-dsgvo-form-fr_FR.po
│   ├── wp-dsgvo-form-es_ES.po
│   ├── wp-dsgvo-form-it_IT.po
│   └── wp-dsgvo-form-sv_SE.po
│
└── tests/
    ├── phpunit.xml.dist
    ├── bootstrap.php
    ├── Unit/
    │   ├── Encryption/
    │   ├── Validation/
    │   ├── Models/
    │   ├── Captcha/
    │   └── Auth/
    ├── Integration/
    │   ├── Api/
    │   └── Admin/
    └── E2E/
```

---

## 2. Datenbank-Schema

7 Custom Tables mit Prefix `{$wpdb->prefix}dsgvo_`.

### 2.1 `dsgvo_forms` — Formulare

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `title` | `VARCHAR(255) NOT NULL` | Formularname |
| `slug` | `VARCHAR(255) NOT NULL` | URL-Slug (UNIQUE) |
| `description` | `TEXT` | Optionale Beschreibung |
| `success_message` | `TEXT` | Nachricht nach Einsendung |
| `email_subject` | `VARCHAR(255)` | Betreff der Benachrichtigung |
| `email_template` | `TEXT` | E-Mail-Body-Template |
| `is_active` | `TINYINT(1) DEFAULT 1` | Aktiv/Inaktiv |
| `legal_basis` | `VARCHAR(20) NOT NULL DEFAULT 'consent'` | Rechtsgrundlage: `consent` oder `contract` |
| `purpose` | `VARCHAR(500)` | Verarbeitungszweck (DSGVO) |
| `retention_days` | `INT UNSIGNED NOT NULL DEFAULT 90` | Auto-Löschung (Min: 1, Max: 3650) |
| `captcha_enabled` | `TINYINT(1) DEFAULT 1` | CAPTCHA pro Formular ein/aus |
| `has_special_categories` | `TINYINT(1) DEFAULT 0` | Art. 9 DSGVO Flag |
| `created_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP` | |
| `updated_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | |

**Indizes:** `PRIMARY (id)`, `UNIQUE (slug)`, `INDEX (is_active)`

### 2.2 `dsgvo_fields` — Formularfelder

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `form_id` | `BIGINT UNSIGNED NOT NULL` | FK → forms |
| `field_type` | `VARCHAR(50) NOT NULL` | `text`, `email`, `tel`, `textarea`, `checkbox`, `radio`, `select`, `date`, `file`, `static` |
| `label` | `VARCHAR(255) NOT NULL` | Feld-Bezeichnung |
| `name` | `VARCHAR(100) NOT NULL` | HTML name-Attribut |
| `placeholder` | `VARCHAR(255)` | Platzhalter |
| `is_required` | `TINYINT(1) DEFAULT 0` | Pflichtfeld |
| `width` | `VARCHAR(10) DEFAULT 'full'` | Layout: `full`, `half`, `third` |
| `options` | `TEXT` | JSON: Optionen (radio/select/checkbox) |
| `validation_rules` | `TEXT` | JSON: Validierungsregeln |
| `static_content` | `TEXT` | HTML für statische Textblöcke |
| `file_config` | `TEXT` | JSON: `{max_size, allowed_types}` |
| `css_class` | `VARCHAR(255)` | Zusätzliche CSS-Klassen |
| `sort_order` | `INT UNSIGNED DEFAULT 0` | Reihenfolge |
| `created_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP` | |

**Indizes:** `PRIMARY (id)`, `INDEX (form_id, sort_order)`, `FK (form_id) → forms ON DELETE CASCADE`

### 2.3 `dsgvo_submissions` — Einsendungen (verschlüsselt)

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK (nur intern) |
| `uuid` | `VARCHAR(36) NOT NULL` | Öffentlicher Identifier (IDOR-Schutz) |
| `form_id` | `BIGINT UNSIGNED NOT NULL` | FK → forms |
| `encrypted_data` | `LONGTEXT NOT NULL` | `base64(iv(12) + tag(16) + ciphertext)` |
| `email_lookup_hash` | `VARCHAR(64)` | HMAC-SHA256 der E-Mail (Art. 15/17 Suche) |
| `consent_version_id` | `BIGINT UNSIGNED` | FK → consent_versions |
| `consent_locale` | `VARCHAR(10) NOT NULL DEFAULT ''` | Sprache der Einwilligung |
| `consent_timestamp` | `DATETIME` | Zeitpunkt der Einwilligung |
| `submitted_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP` | Einsendungszeitpunkt |
| `is_read` | `TINYINT(1) DEFAULT 0` | Gelesen-Status |
| `is_restricted` | `TINYINT(1) DEFAULT 0` | Art. 18: Einschränkung der Verarbeitung |
| `expires_at` | `DATETIME` | Auto-Lösch-Zeitpunkt |

**Indizes:**
- `PRIMARY (id)`, `UNIQUE (uuid)`
- `INDEX idx_form_submitted (form_id, submitted_at DESC)` — Paginierte Liste
- `INDEX idx_form_read (form_id, is_read)` — Ungelesene filtern
- `INDEX idx_expiry_restricted (expires_at, is_restricted)` — Lösch-Cron
- `INDEX idx_email_lookup (email_lookup_hash)` — DSGVO-Suche
- `FK (form_id) → forms ON DELETE CASCADE`
- `FK (consent_version_id) → consent_versions`

**WICHTIG:** `encrypted_data` wird **nie** in Listenansichten geladen — nur per AJAX bei Einzelansicht (Lazy Loading).

### 2.4 `dsgvo_submission_files` — Hochgeladene Dateien

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `submission_id` | `BIGINT UNSIGNED NOT NULL` | FK → submissions |
| `field_id` | `BIGINT UNSIGNED NOT NULL` | FK → fields |
| `file_path` | `VARCHAR(512) NOT NULL` | Pfad zur verschlüsselten Datei |
| `original_name` | `VARCHAR(255) NOT NULL` | Originalname (Klartext, für Anzeige) |
| `mime_type` | `VARCHAR(100) NOT NULL` | MIME-Typ |
| `file_size` | `BIGINT UNSIGNED NOT NULL` | Dateigröße in Bytes |
| `created_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP` | |

**Indizes:** `PRIMARY (id)`, `INDEX (submission_id)`, `FK → submissions ON DELETE CASCADE`

**Speicherort:** `WP_CONTENT_DIR/dsgvo-form-uploads/` mit:
```apache
# .htaccess
Order Deny,Allow
Deny from all
php_flag engine off
```

### 2.5 `dsgvo_form_recipients` — Empfänger-Zuordnung

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `form_id` | `BIGINT UNSIGNED NOT NULL` | FK → forms |
| `user_id` | `BIGINT UNSIGNED NOT NULL` | FK → wp_users.ID |
| `notify_email` | `TINYINT(1) DEFAULT 1` | E-Mail-Benachrichtigung |
| `role_justification` | `TEXT` | Zweckdokumentation (Pflicht bei Supervisor) |
| `created_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP` | |

**Indizes:** `PRIMARY (id)`, `UNIQUE (form_id, user_id)`, `INDEX (user_id)`, `FK → forms ON DELETE CASCADE`

### 2.6 `dsgvo_consent_versions` — Einwilligungstext-Versionierung

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `form_id` | `BIGINT UNSIGNED NOT NULL` | FK → forms |
| `locale` | `VARCHAR(5) NOT NULL` | Sprache (z.B. `de_DE`) |
| `version` | `INT UNSIGNED NOT NULL` | Versionsnummer (auto-increment pro form+locale) |
| `consent_text` | `TEXT NOT NULL` | Wortlaut des Einwilligungstexts |
| `privacy_policy_url` | `VARCHAR(500)` | Datenschutzerklärungs-URL |
| `valid_from` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | Gültig ab |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | |

**Indizes:** `PRIMARY (id)`, `UNIQUE (form_id, locale, version)`, `FK → forms ON DELETE CASCADE`

**Consent-Felder werden NICHT verschlüsselt** — sie müssen für DSGVO-Nachweise jederzeit lesbar sein (SEC-ENC-11).

### 2.7 `dsgvo_audit_log` — Audit-Protokollierung

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `user_id` | `BIGINT UNSIGNED NOT NULL` | Handelnder User |
| `action` | `VARCHAR(30) NOT NULL` | `view`, `export`, `delete`, `bulk_delete`, `restrict`, `unrestrict` |
| `submission_uuid` | `VARCHAR(36)` | Betroffene Einsendung |
| `form_id` | `BIGINT UNSIGNED` | Betroffenes Formular |
| `details` | `TEXT` | Zusätzliche Details (JSON) |
| `created_at` | `DATETIME DEFAULT CURRENT_TIMESTAMP` | |

**Indizes:** `PRIMARY (id)`, `INDEX (user_id)`, `INDEX (form_id)`, `INDEX (created_at)`

**Regeln:** Nicht vom Admin löschbar. Aufbewahrung: 1 Jahr (monatlicher Cleanup-Cron).

---

## 3. Verschlüsselungskonzept

### 3.1 Algorithmus: AES-256-GCM

- Authentifizierte Verschlüsselung (Vertraulichkeit + Integrität)
- PHP-native: `openssl_encrypt()` / `openssl_decrypt()` mit `aes-256-gcm`
- GCM Authentication Tag verhindert Manipulation

### 3.2 Schlüsselverwaltung: Einzelner Schlüssel in wp-config.php

```
DSGVO_FORM_ENCRYPTION_KEY (in wp-config.php)
└── 256-Bit, base64-kodiert
└── Generiert bei Plugin-Aktivierung: base64_encode(random_bytes(32))
└── HMAC-Key wird abgeleitet: hash_hkdf('sha256', $key, 32, 'hmac-lookup')
```

**Entscheidung:** Kein Envelope Encryption. Begründung: In einem WordPress-Kontext bietet Envelope Encryption keinen substantiellen Sicherheitsgewinn — wenn ein Angreifer DB-Zugriff hat, erreicht er typischerweise auch das Filesystem. Die zusätzliche Komplexität erhöht die Fehleranfälligkeit. (Security-Expert-Empfehlung)

### 3.3 Verschlüsselungsformat

```
encrypted_data = base64( iv(12 bytes) || auth_tag(16 bytes) || ciphertext )
```

Eine einzige Spalte statt separater IV/Tag-Spalten — einfacheres Schema, keine Gefahr mismatched IV/Daten.

### 3.4 Verschlüsselungsablauf

```php
// Verschlüsseln:
$key = base64_decode(DSGVO_FORM_ENCRYPTION_KEY);
$iv = random_bytes(12);                    // 96 Bit für GCM
$tag = '';
$ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
return base64_encode($iv . $tag . $ciphertext);

// Entschlüsseln:
$data = base64_decode($encoded);
$iv = substr($data, 0, 12);
$tag = substr($data, 12, 16);
$ciphertext = substr($data, 28);
return openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
```

### 3.5 Was wird verschlüsselt

| Daten | Verschlüsselt | Begründung |
|-------|:---:|------------|
| Formularfeld-Werte | Ja | Personenbezogene Daten |
| Hochgeladene Dateien | Ja | Personenbezogene Daten |
| form_id, submitted_at | Nein | Nötig für Queries |
| Consent-Text/-Version | Nein | DSGVO-Nachweis (SEC-ENC-11) |
| email_lookup_hash | Nein | HMAC-Hash, kein Klartext |
| Audit-Log | Nein | Compliance-Nachweis |

### 3.6 Datei-Verschlüsselung (Streaming)

Dateien werden in **8 KB Chunks** verschlüsselt (Peak Memory < 2 MB):

```
Datei → chunk(8KB) → AES-256-GCM(chunk) → Encrypted File
```

Die Methoden `encrypt_file()` / `decrypt_file()` sind Teil des `EncryptionService` (keine separate FileEncryptor-Klasse). `FileHandler` nutzt den `EncryptionService` via Constructor Injection.

### 3.7 HMAC Lookup-Hash

Für DSGVO Art. 15/17 (Auskunft/Löschung) ohne Bulk-Entschlüsselung:

```php
$hmac_key = hash_hkdf('sha256', $encryption_key, 32, 'hmac-lookup');
$email_lookup_hash = hash_hmac('sha256', strtolower(trim($email)), $hmac_key);
```

HMAC (nicht einfacher SHA-256) — Rainbow-Table-resistent.

### 3.8 Fail-Closed

Wenn `DSGVO_FORM_ENCRYPTION_KEY` nicht in wp-config.php definiert:
- Admin-Notice: "Verschlüsselungsschlüssel fehlt — Plugin deaktiviert"
- Formular-Submission blockiert
- Entschlüsselung nicht möglich
- **Kein Fallback-Key, niemals**

---

## 4. PHP-Modulstruktur

### 4.1 Namespace & Autoloading

```
Namespace: WpDsgvoForm\
Autoloading: PSR-4 via Composer
Mapping: WpDsgvoForm\ → includes/
```

```json
{
  "autoload": {
    "psr-4": { "WpDsgvoForm\\": "includes/" }
  },
  "require-dev": {
    "phpunit/phpunit": "^10.0",
    "squizlabs/php_codesniffer": "^3.9",
    "wp-coding-standards/wpcs": "^3.1",
    "phpstan/phpstan": "^2.0",
    "phpstan/phpstan-wordpress": "^2.0"
  }
}
```

**Keine Production-Dependencies** — das Plugin ist self-contained. Nur der Composer-Autoloader wird ausgeliefert.

### 4.2 Klassen-Übersicht

| Klasse | Namespace | Verantwortlichkeit |
|--------|-----------|-------------------|
| `Plugin` | `WpDsgvoForm` | Singleton, Bootstrap, Hook-Registration |
| `Activator` | `WpDsgvoForm` | DB-Tabellen (dbDelta), Rollen, Cron, KEK-Setup |
| `Deactivator` | `WpDsgvoForm` | Cron entfernen |
| `AdminMenu` | `Admin` | Admin-Menü-Registrierung |
| `FormListPage` | `Admin` | Formular-Übersicht (WP_List_Table) |
| `FormEditPage` | `Admin` | Formular-Builder (PHP, Inline-JS für Feld-Verwaltung) |
| `SubmissionListPage` | `Admin` | Einsendungs-Übersicht |
| `SubmissionViewPage` | `Admin` | Einzelne Einsendung (entschlüsselt) |
| `RecipientListPage` | `Admin` | Empfänger-Zuordnungsverwaltung |
| `SettingsPage` | `Admin` | Plugin-Einstellungen |
| `AdminBarNotification` | `Admin` | Unread-Badge in WP Admin Bar |
| `RoleManager` | `Auth` | Rollen + 6 Härtungsmaßnahmen |
| `AccessControl` | `Auth` | Capability-Checks, Formular-Zuordnung, IDOR |
| `LoginRedirect` | `Auth` | Redirect Reader/Supervisor nach Login |
| `Form` | `Models` | Formular CRUD + Transient-Cache |
| `Field` | `Models` | Feld CRUD + sort_order |
| `Submission` | `Models` | Einsendungs CRUD + Lazy Loading |
| `Recipient` | `Models` | Empfänger-Zuordnung CRUD |
| `ConsentVersion` | `Models` | Consent-Text-Versionierung |
| `EncryptionService` | `Encryption` | AES-256-GCM (Daten + Dateien) |
| `KeyManager` | `Encryption` | KEK-Zugriff, HMAC-Key-Ableitung |
| `SubmitEndpoint` | `Api` | POST /submit (öffentlich) |
| `FormsEndpoint` | `Api` | CRUD Formulare (Admin) |
| `FieldsEndpoint` | `Api` | CRUD Felder (Admin) |
| `SubmissionsEndpoint` | `Api` | Einsendungen lesen |
| `SubmissionDeleteEndpoint` | `Api` | Einsendungen löschen + Audit |
| `RecipientsEndpoint` | `Api` | Empfänger CRUD (Admin) |
| `FormBlock` | `Block` | Gutenberg-Block + SSR + Shortcode `[dsgvo_form]` |
| `NotificationService` | `Email` | Benachrichtigung (ohne Klartext-Daten!) |
| `CaptchaVerifier` | `Captcha` | Token-Validierung (fail-closed) |
| `FieldValidator` | `Validation` | Server-seitige Validierung |
| `FileHandler` | `Upload` | Upload-Verarbeitung + verschlüsselte Speicherung |
| `PrivacyHandler` | `Privacy` | WP Privacy Tools (Exporter + Eraser) |
| `AuditLogger` | `Audit` | Protokollierung |
| `WpDsgvoException` | `Exception` | Basis-Exception |

### 4.3 Plugin-Bootstrap

```php
<?php
declare(strict_types=1);
namespace WpDsgvoForm;

class Plugin {
    private static ?Plugin $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void {
        $keyManager = new Encryption\KeyManager();
        $encryption = new Encryption\EncryptionService($keyManager);
        $captcha = new Captcha\CaptchaVerifier();
        $validator = new Validation\FieldValidator();
        $auditLogger = new Audit\AuditLogger();

        // Auth: Rollen-Härtung (immer aktiv)
        $roleManager = new Auth\RoleManager();
        $roleManager->register_hooks();
        (new Auth\LoginRedirect())->register_hooks();

        // Admin
        if (is_admin()) {
            (new Admin\AdminMenu($encryption, $auditLogger))->register();
        }

        // REST-API
        add_action('rest_api_init', function () use ($encryption, $captcha, $validator, $auditLogger) {
            (new Api\SubmitEndpoint($encryption, $captcha, $validator))->register();
            (new Api\FormsEndpoint())->register();
            (new Api\FieldsEndpoint())->register();
            (new Api\SubmissionsEndpoint($encryption, $auditLogger))->register();
            (new Api\SubmissionDeleteEndpoint($encryption, $auditLogger))->register();
            (new Api\RecipientsEndpoint())->register();
        });

        // Block
        (new Block\FormBlock())->register();

        // Privacy Tools
        (new Privacy\PrivacyHandler($encryption))->register();

        // Crons
        add_action('wpdsgvo_cleanup_expired', [Models\Submission::class, 'delete_expired']);
        add_action('wpdsgvo_cleanup_audit_log', [$auditLogger, 'cleanup_old_entries']);
    }
}
```

### 4.4 Dependency Injection

**Constructor Injection** — keine DI-Container, keine Magie:

- `EncryptionService($keyManager)`
- `SubmitEndpoint($encryptionService, $captchaVerifier, $fieldValidator)`
- `FileHandler($encryptionService)`
- `SubmissionsEndpoint($encryptionService, $auditLogger)`

Alle Dependencies sind explizit, testbar via Mock-Injection.

### 4.5 Exception-Hierarchie

```
WpDsgvoException (Basis)
├── EncryptionException    — Verschlüsselungsfehler
├── ValidationException    — Eingabevalidierung
├── AuthenticationException — Auth/Autorisierung
└── StorageException       — DB/Filesystem
```

**Error-Handling-Strategie:**
- **Presentation Layer** (Admin): `wp_die()` / `admin_notice`
- **Service Layer**: Exceptions werfen
- **Data Layer**: `WP_Error` / `false` zurückgeben

---

## 5. REST-API-Design

Basis-URL: `/wp-json/dsgvo-form/v1/`

### 5.1 Öffentliche Endpunkte

#### `POST /submit` — Formular-Einsendung

**Request:** `multipart/form-data` mit:
```
form_id:        1
fields[name]:   Max Mustermann
fields[email]:  max@example.com
captcha_token:  abc123...
_wpnonce:       xyz789...
_honeypot:      (muss leer sein)
files[upload]:  (Datei)
```

**Validierungsreihenfolge:**
1. Nonce prüfen (`wp_verify_nonce`)
2. Honeypot prüfen (muss leer sein)
3. CAPTCHA-Token verifizieren (fail-closed, 5s Timeout)
4. Formular aktiv prüfen
5. Consent-Text in aktueller Locale vorhanden? (fail-closed)
6. Pflichtfelder + Feldtyp-Validierung
7. Datei-Upload-Validierung (MIME, Größe)
8. Einwilligungs-Checkbox prüfen (Hard-Block bei legal_basis = 'consent')
9. Verschlüsseln + speichern
10. `expires_at` berechnen: `submitted_at + retention_days`
11. `email_lookup_hash` generieren (falls E-Mail-Feld vorhanden)
12. E-Mail-Benachrichtigung senden
13. Response: `201 { success: true, message: "..." }`

#### `GET /forms/{id}/config` — Formular-Konfiguration (gecacht)

Öffentlich, Transient-Cache (1h). Gibt Felder, Feldtypen, Validierungsregeln zurück. **Keine verschlüsselten Daten.**

### 5.2 Admin-Endpunkte (Capability: `dsgvo_form_manage`)

| Method | Endpunkt | Beschreibung |
|--------|----------|-------------|
| `GET` | `/admin/forms` | Alle Formulare |
| `POST` | `/admin/forms` | Formular anlegen |
| `PUT` | `/admin/forms/{id}` | Formular bearbeiten |
| `DELETE` | `/admin/forms/{id}` | Formular löschen |
| `GET` | `/admin/forms/{id}/fields` | Felder laden |
| `POST` | `/admin/forms/{id}/fields` | Feld hinzufügen |
| `PUT` | `/admin/fields/{id}` | Feld bearbeiten |
| `DELETE` | `/admin/fields/{id}` | Feld löschen |
| `POST` | `/admin/forms/{id}/fields/reorder` | Reihenfolge ändern |
| `GET` | `/admin/forms/{id}/recipients` | Empfänger laden |
| `POST` | `/admin/forms/{id}/recipients` | Empfänger zuordnen |
| `DELETE` | `/admin/recipients/{id}` | Empfänger entfernen |

### 5.3 Empfänger-Endpunkte (Capability: `dsgvo_form_view_submissions`)

| Method | Endpunkt | Beschreibung |
|--------|----------|-------------|
| `GET` | `/submissions` | Paginierte Liste (nur Metadaten!) |
| `GET` | `/submissions/{uuid}` | Einzelne Einsendung (entschlüsselt) |
| `GET` | `/submissions/{uuid}/files/{id}` | Datei-Download (entschlüsselt) |
| `DELETE` | `/submissions/{uuid}` | Einsendung + Dateien löschen |
| `POST` | `/submissions/export` | DSGVO-Export (>50: Background-Job) |

**Zugriffsprüfung bei jedem Request:**
```
if (dsgvo_form_manage) → Zugriff (Admin)
elif (dsgvo_form_view_all_submissions) → Zugriff + Audit-Log (Supervisor)
elif (dsgvo_form_view_submissions + Formular-Zuordnung) → Zugriff (Reader)
else → 403
```

---

## 6. Gutenberg Block

### 6.1 block.json

```json
{
  "apiVersion": 3,
  "name": "dsgvo-form/form",
  "title": "DSGVO Formular",
  "category": "widgets",
  "icon": "feedback",
  "description": "DSGVO-konformes Formular einfügen",
  "supports": {
    "html": false,
    "align": ["wide", "full"],
    "color": { "background": true, "text": true },
    "spacing": { "margin": true, "padding": true },
    "typography": { "fontSize": true, "fontFamily": true }
  },
  "attributes": {
    "formId": { "type": "number", "default": 0 }
  },
  "editorScript": "file:../../build/block/index.js",
  "editorStyle": "file:../../build/block/editor.css",
  "style": "file:../../build/block/frontend.css",
  "render": "file:./render.php",
  "viewScript": "file:../../build/frontend/form-handler.js"
}
```

### 6.2 Server-Side Rendering

`save.js` gibt `null` zurück. Das Rendering passiert in PHP (`FormBlock::render()`):

1. Formular laden (aus Transient-Cache)
2. Consent-Text in aktueller Locale prüfen — **Fail-Closed:** kein Formular ohne Consent-Text
3. Felder als HTML rendern (CSS Grid für width: full/half/third)
4. CAPTCHA-Script enqueuen (nur wenn `captcha_enabled`, mit `defer` + `in_footer`)
5. Honeypot-Feld einfügen (CSS `display:none`)
6. Nonce-Feld einfügen (`wp_nonce_field('dsgvo_form_submit_' . $formId)`)
7. Einwilligungs-Checkbox rendern (bei `legal_basis = 'consent'`)
8. `wp_localize_script()` für form-handler.js: API-URL, Nonce, Feld-Limits

### 6.3 Shortcode-Fallback

Für Themes oder Page-Builder ohne Gutenberg-Unterstützung:

```
[dsgvo_form id="123"]
```

Der Shortcode delegiert an `FormBlock::render()` — identischer Rendering-Pfad wie der Gutenberg-Block (SSR, Consent-Prüfung, CAPTCHA, Honeypot, Nonce). Kein separater Rendering-Code.

**Admin-Notices:** Fehlerzustände (kein Formular gewählt, Formular nicht gefunden, fehlender Encryption-Key, fehlender Consent-Text) zeigen Hinweise nur für Benutzer mit `dsgvo_form_manage`-Capability. Reguläre Besucher sehen nichts (Fail-Closed, DSGVO-konform).

### 6.4 Inspector Controls (Sidebar)

- **Formular-Auswahl:** Dropdown + "Im Admin bearbeiten" Link
- **Farben:** Hintergrund, Text, Akzent, Button, Button-Text (WordPress ColorPalette)
- **Typografie:** Schriftgröße, Label-Gewicht
- **Abstände:** Padding (BoxControl), Feld-Abstand
- **Erweitert:** Border-Radius, CSS-Klasse

### 6.5 Frontend-Script: Vanilla JS

Das Frontend-Script (`form-handler.js`) ist **Vanilla JS, kein React** (< 5 KB gzipped vs. 40+ KB mit React):

- Client-seitige Validierung (blur + submit)
- CAPTCHA-Token auslesen (`captcha_token` hidden input)
- Honeypot prüfen
- `fetch()` POST an `/dsgvo-form/v1/submit`
- Inline-Fehlermeldungen mit `aria-invalid` + `aria-describedby`
- Submit-Button deaktiviert während Übertragung
- Erfolg ersetzt Formular (kein Reset)

---

## 7. WordPress-Integration

### 7.1 Lifecycle-Hooks

**Activation (`Activator::activate()`):**
- 7 DB-Tabellen via `dbDelta()`
- Custom Roles + Capabilities registrieren
- KEK generieren und in wp-config.php schreiben (falls nicht vorhanden)
- Cron-Jobs schedulen
- Upload-Verzeichnis + .htaccess anlegen

**Deactivation (`Deactivator::deactivate()`):**
- Cron-Jobs entfernen
- Daten bleiben erhalten

**Uninstall (`uninstall.php`):**
- Alle 7 Custom Tables löschen
- Alle Options entfernen
- Upload-Verzeichnis rekursiv löschen
- Custom Roles + Capabilities entfernen
- Transient-Cache löschen

### 7.2 Admin-Menü

```
DSGVO Formulare (dashicons-feedback)
├── Alle Formulare       (FormListPage, dsgvo_form_manage)
├── Neues Formular       (FormEditPage, dsgvo_form_manage)
├── Empfänger            (RecipientListPage, dsgvo_form_manage)
└── Einstellungen        (SettingsPage, dsgvo_form_manage)
```

Reader/Supervisor sehen ein eigenes, reduziertes Menü:
```
Einsendungen (dashicons-email)
├── Übersicht            (SubmissionListPage, dsgvo_form_view_submissions)
```

### 7.3 Assets-Enqueueing

```php
// Admin: Nur auf Plugin-Seiten
add_action('admin_enqueue_scripts', function (string $hook): void {
    if (strpos($hook, 'dsgvo-form') === false) return;
    wp_enqueue_script('wpdsgvo-admin', ...);
    wp_localize_script('wpdsgvo-admin', 'wpdsgvoAdmin', [
        'apiUrl' => rest_url('dsgvo-form/v1/'),
        'nonce'  => wp_create_nonce('wp_rest'),
    ]);
});

// Frontend: Automatisch via block.json viewScript (nur auf Seiten mit Block)
```

**Performance-Budget:**
- 0 KB auf Seiten ohne Formular
- < 30 KB Frontend-Bundle (gzipped)
- < 80 KB Admin-Bundle (gzipped)

### 7.4 Cron-Jobs

| Cron | Intervall | Aktion |
|------|-----------|--------|
| `wpdsgvo_cleanup_expired` | Stündlich | Abgelaufene Submissions löschen (Batch: 200, `WHERE is_restricted = 0`) |
| `wpdsgvo_cleanup_audit_log` | Monatlich | Audit-Einträge > 1 Jahr löschen |

### 7.5 Custom Hooks (Erweiterbarkeit)

```php
do_action('wpdsgvo_before_submission', $form_id, $data);
do_action('wpdsgvo_after_submission', $form_id, $submission_id);
do_action('wpdsgvo_submission_deleted', $submission_uuid);
apply_filters('wpdsgvo_allowed_mime_types', $types, $field);
apply_filters('wpdsgvo_max_file_size', $size, $field);
apply_filters('wpdsgvo_email_subject', $subject, $form);
apply_filters('wpdsgvo_retention_days_range', ['min' => 1, 'max' => 3650]);
```

### 7.6 Admin Bar Notification (Unread-Badge)

Zeigt eine Zählerblase ("Badge") in der WordPress Admin Bar mit der Anzahl ungelesener Einsendungen.

**Klasse:** `WpDsgvoForm\Admin\AdminBarNotification`
**Datei:** `includes/Admin/AdminBarNotification.php`
**Constructor:** `__construct(AccessControl $access_control)`

#### 7.6.1 Hook-Registrierung

```php
add_action('admin_bar_menu', [$this, 'add_notification_node'], 80);
add_action('admin_head', [$this, 'render_badge_styles']);
add_action('wp_head', [$this, 'render_badge_styles']);
```

- Priorität 80: nach Standard-Nodes, vor `LoginRedirect::restrict_admin_bar()` (999)
- Styles inline (< 200 Bytes), kein separates Stylesheet

#### 7.6.2 Sichtbarkeit

Das Badge erscheint **nur** für eingeloggte User mit `dsgvo_form_view_submissions` Capability:

| Rolle | Sichtbar | Kontext |
|-------|:---:|---------|
| Administrator | Ja | Admin-Bereich + Frontend (Admin Bar) |
| Supervisor | Ja | Admin-Bereich (Admin Bar) |
| Reader | Ja | Admin-Bereich (Admin Bar) |
| Anonymer Besucher | Nein | — |

**Hinweis:** Auf der Recipient-Seite (`/dsgvo-empfaenger/`) ist die Admin Bar ausgeblendet (`RecipientPage::maybe_hide_admin_bar`). Die Notification ist dort nicht sichtbar — die Recipient-Seite zeigt den Status inline in ihrer eigenen Liste an.

#### 7.6.3 Rollenbasierter Count

```
Admin / Supervisor (dsgvo_form_view_all_submissions):
  SELECT COUNT(*) FROM dsgvo_submissions
  WHERE is_read = 0 AND is_restricted = 0

Reader (nur zugewiesene Formulare):
  Recipient::get_form_ids_for_user($user_id) → $form_ids
  Submission::count_by_form_ids($form_ids, is_read: false, include_restricted: false)
```

- **Restricted Submissions** (Art. 18 DSGVO) werden beim Count ausgeschlossen
- **Reader** nutzt die existierenden Methoden `Recipient::get_form_ids_for_user()` + `Submission::count_by_form_ids()`
- **Admin/Supervisor** nutzt einen globalen COUNT (ohne form_id-Filter)
- Existierender Index `idx_form_read (form_id, is_read)` deckt den Reader-Query optimal ab

#### 7.6.4 Caching

| Rolle | Cache-Key | TTL |
|-------|-----------|-----|
| Admin / Supervisor | `wpdsgvo_unread_count` | 2 min |
| Reader | `wpdsgvo_unread_count_{user_id}` | 2 min |

- WordPress Transient-API (`get_transient` / `set_transient`)
- **Keine aktive Cache-Invalidierung** — 2 min TTL ist kurz genug (bereits bewährtes Muster, vgl. §15.2)
- Per-User-Key für Reader nötig, da jeder Reader unterschiedliche Formulare sieht
- `user_id` im Cache-Key ist eine interne WP-ID, kein personenbezogenes Datum

#### 7.6.5 Admin Bar Node

```php
$wp_admin_bar->add_node([
    'id'    => 'wpdsgvo-unread',
    'title' => $this->build_badge_html($count),
    'href'  => admin_url('admin.php?page=dsgvo-form-submissions'),
    'meta'  => [
        'class' => 'wpdsgvo-admin-bar-notification',
        'title' => sprintf(
            _n('%d ungelesene Einsendung', '%d ungelesene Einsendungen', $count, 'wp-dsgvo-form'),
            $count
        ),
    ],
]);
```

**Verhalten:**
- **Count = 0:** Kein Node (Badge komplett unsichtbar)
- **Count > 99:** Anzeige als "99+" (UX-Limit)
- **Icon:** `dashicons-email-alt` (WordPress-Standard)
- **Link:** `admin.php?page=dsgvo-form-submissions` (Admin-Einsendungsseite)
- **Tooltip:** `title`-Attribut mit exaktem Count + Plural-Form
- **Accessibility (WCAG 2.1 AA):** `build_badge_html()` enthält zusätzlich `<span class="screen-reader-text">` mit vollständigem Klartext (z.B. "5 ungelesene Einsendungen") — `title`-Attribut allein reicht nicht für Assistive Technologies. Singular/Plural via `_n()`.

#### 7.6.6 Badge-Styling (Inline CSS)

```css
#wpadminbar .wpdsgvo-admin-bar-notification .ab-item {
    display: flex;
    align-items: center;
}
#wpadminbar .wpdsgvo-unread-count {
    background: #d63638;
    color: #fff;
    border-radius: 50%;
    font-size: 9px;
    font-weight: 600;
    min-width: 17px;
    height: 17px;
    line-height: 17px;
    text-align: center;
    display: inline-block;
    margin-left: 2px;
    padding: 0 4px;
    box-sizing: border-box;
}
```

Visuell konsistent mit WordPress-eigenen Notification-Badges (Plugin-Updates, Kommentare).

#### 7.6.7 Integration in Plugin-Bootstrap

In `Plugin::register_hooks()`:

```php
// Admin Bar Notification (outside is_admin() — admin bar also on frontend)
$notification = new Admin\AdminBarNotification($access_control);
$notification->register_hooks();
```

Registrierung **außerhalb** von `is_admin()` — die Admin Bar erscheint auch auf dem Frontend für eingeloggte Admins.

#### 7.6.8 DSGVO-Konformität

- **Keine personenbezogenen Daten** — nur ein numerischer Count
- **Kein User-Tracking** — kein zusätzliches Logging, keine neuen Cookies
- **Restricted-Ausschluss** — Art. 18 wird beachtet (is_restricted = 0)
- **Cache enthält keine PII** — nur Integer-Werte

#### 7.6.9 Performance-Budget

| Metrik | Budget |
|--------|--------|
| DB-Queries (uncached) | 1 COUNT + ggf. 1 form_ids-Query (Reader) |
| DB-Queries (cached) | 0 |
| Cache-TTL | 120 s |
| CSS-Overhead | < 200 Bytes inline |
| JS-Overhead | 0 KB (kein JavaScript) |

Kein neuer DB-Index nötig. Globaler Admin/Supervisor-Count auf `is_read + is_restricted` ist bei typischer Plugin-Nutzung (< 50k Submissions) performant genug.

---

## 8. Empfänger- & Rollensystem

### 8.1 WordPress-User-Integration

Empfänger sind WP-User mit Custom Roles. **Kein eigenes Auth-System** — WP Session-Handling wird genutzt (SEC-AUTH-07).

### 8.2 Rollen & Capabilities

| Capability | `wp_dsgvo_form_reader` | `wp_dsgvo_form_supervisor` | Administrator |
|-----------|:---:|:---:|:---:|
| `read` | x | x | x |
| `dsgvo_form_view_submissions` | x | x | x |
| `dsgvo_form_view_all_submissions` | - | **x** | x |
| `dsgvo_form_delete_submissions` | x | x | x |
| `dsgvo_form_export` | - | x | x |
| `dsgvo_form_manage` | - | - | x |

**Reader** sieht nur Einsendungen seiner zugewiesenen Formulare (via `dsgvo_form_recipients`-Tabelle).
**Supervisor** sieht alle Einsendungen aller Formulare — mit DSGVO-Pflichtmaßnahmen.

### 8.3 Supervisor DSGVO-Maßnahmen

1. **Zweckdokumentation:** Admin muss bei Supervisor-Zuweisung Zweck angeben (`role_justification`, Pflichtfeld)
2. **Audit-Log:** Jeder Supervisor-Lesezugriff wird protokolliert
3. **Warnhinweis:** UI zeigt Warnung bei Supervisor-Zuweisung
4. **Halbjährliche Review-Erinnerung:** Admin-Notice zur Überprüfung
5. **Monitoring:** Warnung ab > 3 Supervisoren

### 8.4 Rollen-Härtung (`RoleManager`)

6 Pflicht-Maßnahmen für Reader und Supervisor:

| # | Maßnahme | Hook |
|---|----------|------|
| 1 | Login-Redirect zu Einsendungs-Viewer | `login_redirect` Filter |
| 2 | Admin-Menü-Isolation (nur Plugin-Seiten) | `admin_menu` Action (Priorität 999) |
| 3 | Admin-Bar einschränken | `wp_before_admin_bar_render` |
| 4 | Direktzugriff auf WP-Admin blockieren | `current_screen` Hook |
| 5 | Verkürzte Session (2h statt 48h) | `auth_cookie_expiration` Filter |
| 6 | Cleanup bei Deinstallation | `uninstall.php` → `remove_role()` |

### 8.5 E-Mail-Benachrichtigung

```
Neue Einsendung → NotificationService → wp_mail() an alle Empfänger mit notify_email=1
```

**E-Mail enthält KEINE Formulardaten** — nur:
- "Neue Einsendung für {Formularname}"
- Link zum Login-Bereich
- Datum/Uhrzeit

Versand ausschließlich über `wp_mail()`. Header-Injection-Schutz via `sanitize_email()` + Zeilenumbruch-Entfernung.

---

## 9. CAPTCHA-Integration

### 9.1 Frontend

```html
<!-- Server-Side gerendert in FormBlock::render() -->
<script src="https://captcha.repaircafe-bruchsal.de/captcha.js" defer></script>
<captcha-widget></captcha-widget>
<input type="hidden" name="captcha_token" value="">
```

Das `<captcha-widget>` Web Component füllt automatisch das hidden input `captcha_token`. `form-handler.js` liest den Wert beim Submit aus.

CAPTCHA-Script wird nur im `render_callback` enqueued — mit `defer` + `in_footer`. DNS-Prefetch erlaubt.

### 9.2 Backend-Validierung (fail-closed)

```php
class CaptchaVerifier {
    public function verify(string $token): bool {
        $url = get_option('wpdsgvo_captcha_url', 'https://captcha.repaircafe-bruchsal.de');
        $response = wp_remote_post($url . '/api/verify', [
            'body'    => ['token' => $token],
            'timeout' => 5,  // Fail-closed bei Timeout
            'sslverify' => true,
        ]);
        if (is_wp_error($response)) return false;  // Fail-closed
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return ($body['success'] ?? false) === true;
    }
}
```

- CAPTCHA-URL konfigurierbar in Einstellungen (SEC-CAP-05)
- Pro Formular aktivierbar/deaktivierbar (SEC-CAP-07)
- Timeout: 5 Sekunden, fail-closed (SEC-CAP-04)
- HTTPS erzwungen, Zertifikat validiert (SEC-CAP-06)

---

## 10. Spam-Schutz

**Kein IP-basiertes Rate-Limiting** — nicht DSGVO-konform (auch gehashte IPs sind personenbezogene Daten, DPO-Finding).

Spam-Schutz besteht aus drei Schichten:

| Schicht | Mechanismus | Implementierung |
|---------|-------------|----------------|
| 1 | CAPTCHA | Externer Service (konfigurierbar) |
| 2 | WordPress Nonce | `wp_nonce_field('dsgvo_form_submit_' . $formId)` |
| 3 | Honeypot-Feld | CSS `display:none`; Server prüft: muss leer sein |

```html
<!-- Honeypot (im SSR-Output) -->
<div style="display:none" aria-hidden="true">
    <input type="text" name="_honeypot" tabindex="-1" autocomplete="off" value="">
</div>
```

---

## 11. Sicherheitskonzept

Vollständige Referenz: `SECURITY_REQUIREMENTS.md` (78+ Anforderungen).

### 11.1 CSRF-Schutz

- REST-API: `X-WP-Nonce` Header (automatisch bei `wp.apiFetch`)
- Frontend-Formulare: `wp_nonce_field('dsgvo_form_submit_' . $formId)` — formularspezifisch
- Admin-Aktionen: WordPress-Standard-Nonces

### 11.2 Input-Sanitization

```php
'text'     → sanitize_text_field()
'email'    → sanitize_email() + is_email()
'tel'      → preg_match('/^\+?[0-9\s\-()]{5,20}$/')  // ReDoS-sicher
'textarea' → sanitize_textarea_field()
'date'     → DateTime::createFromFormat('Y-m-d')
'select'   → in_array($value, $allowedOptions, true)
'radio'    → in_array($value, $allowedOptions, true)
'checkbox' → array_intersect($values, $allowedOptions)
'file'     → sanitize_file_name() + wp_check_filetype_and_ext() + finfo_file()
```

Maximale Feldlänge: 10.000 Zeichen (Hard-Limit).

### 11.3 Output-Escaping

| Kontext | Funktion |
|---------|----------|
| HTML-Text | `esc_html()` |
| Attribute | `esc_attr()` |
| URLs | `esc_url()` |
| JavaScript | `wp_json_encode()` |
| SQL | `$wpdb->prepare()` |

Kein `echo $variable` ohne Escaping — auch nicht im Admin. Entschlüsselte Formulardaten enthalten beliebigen User-Input!

### 11.4 Datei-Upload-Sicherheit

1. Whitelist MIME-Typen (Standard: PDF, JPG, PNG; konfigurierbar pro Feld)
2. Max. Dateigröße (Standard: 5 MB, Hard-Limit: 20 MB, kommuniziert via `wp_localize_script`)
3. Max. 5 Dateien pro Submission, 25 MB Gesamt
4. `sanitize_file_name()` + UUID-Umbenennung
5. MIME-Verifizierung: `wp_check_filetype_and_ext()` + `finfo_file()`
6. Doppel-Extensions ablehnen (z.B. `file.php.jpg`)
7. Verschlüsselte Speicherung in geschütztem Verzeichnis
8. Download nur über authentifizierten API-Endpunkt
9. `.htaccess`: `Deny from all` + `php_flag engine off`

### 11.5 SQL-Injection

Ausschließlich `$wpdb->prepare()`. Keine String-Konkatenation. ORDER BY / LIMIT mit Whitelist-Ansatz.

### 11.6 Jede PHP-Datei beginnt mit

```php
<?php
declare(strict_types=1);
defined('ABSPATH') || exit;
```

---

## 12. DSGVO-Compliance

Vollständige Referenz: `LEGAL_REQUIREMENTS.md`, `DATA_PROTECTION.md`.

### 12.1 Rechtsgrundlage (Art. 6 DSGVO)

Pro Formular konfigurierbar:
- **`consent`** (Art. 6 Abs. 1 lit. a): Einwilligungs-Checkbox Pflicht, Hard-Block bei fehlender Zustimmung
- **`contract`** (Art. 6 Abs. 1 lit. b): Keine Einwilligungs-Checkbox, Info-Textblock stattdessen

### 12.2 Einwilligungstext-Versionierung

- Separate Tabelle `dsgvo_consent_versions` (pro Formular + Locale)
- Bei Textänderung: neue Version, alte Submissions behalten Referenz
- Kein Fallback auf andere Sprachen — **Fail-Closed**
- Consent-Texte NICHT verschlüsselt (SEC-ENC-11)

### 12.3 Betroffenenrechte

| Recht | Art. | Implementierung |
|-------|------|----------------|
| Auskunft | Art. 15 | `email_lookup_hash` → Submissions finden → entschlüsseln → Export |
| Löschung | Art. 17 | Echtes DELETE (kein Soft-Delete) + Dateien physisch löschen |
| Berichtigung | Art. 16 | Admin kann Submission neu verschlüsseln |
| Einschränkung | Art. 18 | `is_restricted = 1` → aus Listen/Export ausgeblendet, kein Auto-Delete |
| Datenübertragbarkeit | Art. 20 | CSV/JSON-Export (entschlüsselt) |
| Widerspruch | Art. 21 | Manuell über Admin-Löschung |

### 12.4 WP Privacy Tools Integration (`PrivacyHandler`)

```php
add_filter('wp_privacy_personal_data_exporters', ...);  // Exporter
add_filter('wp_privacy_personal_data_erasers', ...);     // Eraser
wp_add_privacy_policy_content('DSGVO Formular', $text);  // Datenschutzseite
```

### 12.5 Auto-Löschung

- `retention_days`: 1–3650 (kein "unbegrenzt", DPO-FINDING-01)
- Stündlicher Cron: `WHERE expires_at <= NOW() AND is_restricted = 0`
- Batch-Größe: 200 (Performance-Anforderung)
- Zugehörige Dateien physisch löschen

### 12.6 Datensparsamkeit

- Keine IP-Adressen speichern (weder Klartext noch Hash)
- Keine User-Agents, Referrer, Tracking
- Nur konfigurierte Formularfelder speichern

---

## 13. Build-System & Tooling

### 13.1 @wordpress/scripts

```json
{
  "name": "wp-dsgvo-form",
  "scripts": {
    "build": "wp-scripts build",
    "start": "wp-scripts start",
    "lint:js": "wp-scripts lint-js",
    "lint:css": "wp-scripts lint-style",
    "test:js": "wp-scripts test-unit-js",
    "check-all": "npm run lint:js && npm run lint:css && npm run test:js"
  },
  "devDependencies": {
    "@wordpress/scripts": "^30.0.0",
    "@wordpress/i18n": "^5.0.0"
  }
}
```

```js
// webpack.config.js
const defaultConfig = require('@wordpress/scripts/config/webpack.config');
module.exports = {
    ...defaultConfig,
    entry: {
        'block/index': './src/block/index.js',
        'frontend/form-handler': './src/frontend/form-handler.js',
    },
};
```

### 13.2 PHP-Tooling

```json
{
  "scripts": {
    "test": "phpunit --configuration tests/phpunit.xml.dist",
    "lint": "phpcs",
    "lint:fix": "phpcbf",
    "analyze": "phpstan analyse",
    "check-all": "composer lint && composer analyze && composer test"
  }
}
```

### 13.3 Coding-Standards

- **PHP:** WPCS + PSR-12 (`.phpcs.xml.dist`), Tabs, `declare(strict_types=1)`, PHPStan Level 6
- **JS:** `@wordpress/eslint-plugin`, Functional Components, nur `@wordpress/*`-Pakete
- **CSS:** `@wordpress/stylelint-config`
- **Komplexitätsgrenzen:** 50 Zeilen/Funktion (PHP), 40 (JS), Zyklomatische Komplexität max 10

### 13.4 Test-Coverage-Ziele

| Bereich | Coverage |
|---------|----------|
| Encryption | 100% |
| Validierung | 95% |
| REST-API | 90% |
| Admin-UI | 85% |
| Gutenberg Block | 80% |
| **Gesamt** | **80%** |

### 13.5 Vulnerability Management

- `npm audit` im Build-Prozess (0 critical/high Findings)
- 72h-Patch-Policy bei Critical Vulnerabilities (SEC-VULN-01/02)

---

## 14. i18n / Mehrsprachigkeit

### 14.1 Unterstützte Sprachen

`de_DE`, `en_US`, `fr_FR`, `es_ES`, `it_IT`, `sv_SE`

### 14.2 Technische Umsetzung

- **Text-Domain:** `wp-dsgvo-form`
- **PHP:** `__()`, `_e()`, `esc_html__()` etc.
- **JS:** `@wordpress/i18n`
- **POT:** `wp i18n make-pot . languages/wp-dsgvo-form.pot`
- **Sprach-Fallback:** WP-Locale → Plugin-Default → Deutsch

### 14.3 Consent-Texte

Consent-Texte sind **pro Formular pro Locale** versioniert (Tabelle `dsgvo_consent_versions`).

- Admin gibt Consent-Texte pro Sprache ein
- Formular-Rendering prüft: Consent-Text in aktueller Locale vorhanden?
- **Fail-Closed:** Kein Formular-Rendering ohne Consent-Text (DPO-FINDING-13)
- Datenschutzerklärungs-URL ebenfalls pro Locale
- `consent_locale` wird in jeder Submission gespeichert

### 14.4 Admin-konfigurierte Labels

Formular-Labels (Feldnamen, Platzhalter, statische Textblöcke) werden **nicht automatisch übersetzt** — der Admin gibt sie in der jeweiligen Sprache ein. Kompatibel mit WPML, Polylang, TranslatePress.

---

## 15. Performance-Konzept

Vollständige Referenz: `PERFORMANCE_REQUIREMENTS.md`.

### 15.1 Performance-Budget

| Metrik | Budget |
|--------|--------|
| Frontend-Assets (Seite ohne Formular) | 0 KB |
| Frontend-Bundle (Seite mit Formular) | < 30 KB gzipped |
| Admin-Bundle | < 80 KB gzipped |
| DB-Queries pro Frontend-Request | <= 3 |
| DB-Queries pro Admin-Seite | <= 5 |
| Formular-Rendering (cached) | < 10 ms |
| Submission speichern | < 300 ms |
| Submission entschlüsseln (einzeln) | < 200 ms |

### 15.2 Caching

| Daten | Cachen | TTL |
|-------|:---:|-----|
| Formular-Config | Ja | 1h (Transient) |
| Submission-Count | Ja | 2 min |
| Unread-Count (Admin Bar) | Ja | 2 min (Transient, pro User für Reader) |
| Submissions | **Nein** | DSGVO! |
| Entschlüsselte Daten | **Nein** | DSGVO! |

Cache-Invalidierung bei Formular-Update: `delete_transient('wpdsgvo_form_' . $formId)`.

### 15.3 Lazy Loading

`encrypted_data` wird NIE in Listenansichten geladen. List-Queries:
```sql
SELECT id, uuid, form_id, submitted_at, is_read, is_restricted
FROM dsgvo_submissions
WHERE form_id = %d
ORDER BY submitted_at DESC
LIMIT %d OFFSET %d
```

Entschlüsselung erst per AJAX bei Einzelansicht.

### 15.4 Pagination

Server-seitig, Offset-basiert. 20 Submissions/Seite. Export > 50 als WP-Cron Background-Job.

---

## 16. Anhang: Design-Entscheidungen

| Entscheidung | Begründung | Expert-Input |
|-------------|-----------|-------------|
| Einzelner Key statt Envelope Encryption | Weniger Komplexität, gleicher Schutzgrad im WP-Kontext | Security-Expert |
| AES-256-GCM statt CBC | Authentifizierte Verschlüsselung, verhindert Padding-Oracle | Security-Expert |
| iv+tag+ciphertext als ein Blob | Einfacheres Schema, keine mismatched IV/Daten | Security-Expert |
| WP-User-System statt eigenem Auth | Bewährt, sicher, keine eigene Session-Verwaltung | Auftraggeber + Security-Expert (UX-Expert-Vorschlag abgelehnt) |
| `wp_dsgvo_form_reader` + `wp_dsgvo_form_supervisor` | Zwei-Rollen-Modell mit DSGVO-Safeguards | Auftraggeber + Security-Expert |
| Separate Consent-Versions-Tabelle | Saubere Versionierung, FK aus Submissions, Locale-Dimension | DPO (Legal-Expert-JSON-Vorschlag abgelehnt) |
| Kein IP-basiertes Rate-Limiting | Nicht DSGVO-konform (auch gehashte IPs) | DPO + Performance-Expert |
| CAPTCHA + Nonce + Honeypot für Spam | DSGVO-konformer Spam-Schutz ohne personenbezogene Daten | Security-Expert + DPO |
| Server-Side Rendering für Block | SEO, kein React-Hydration, CAPTCHA einfacher | Performance-Expert |
| Frontend Vanilla JS statt React | < 5 KB vs. 40+ KB, kein React-Overhead für statische Formulare | Performance-Expert |
| Custom Tables statt Post Meta | Performance, Foreign Keys, strukturierte Daten | Performance-Expert |
| PSR-4 via Composer | Standard-Autoloading, IDE-Support, Testbarkeit | Quality-Expert |
| @wordpress/scripts | Offizielles Tooling, Block-Kompatibilität | Quality-Expert |
| Keine externen PHP-Dependencies | Self-contained, keine Supply-Chain-Risiken | Quality-Expert + Security-Expert |
| E-Mail ohne Formulardaten | Verschlüsselte Daten nicht über unverschlüsselten Kanal | Security-Expert |
| Hard-Block für Einwilligung | DSGVO-Compliance bei legal_basis=consent | Auftraggeber |
| retention_days: 1–3650, kein "unbegrenzt" | DSGVO Speicherbegrenzung (Art. 5 Abs. 1 lit. e) | DPO |
| is_restricted statt is_locked | Art. 18 DSGVO Terminologie (Task #63) | DPO |
| FileEncryptor im EncryptionService | Krypto-Logik an einem Ort, bessere Auditierbarkeit | Architect-Entscheidung |
| Keine ISO 27001-Zertifizierung | Zertifiziert Organisationen, nicht Produkte — unverhältnismäßig | Security-Expert |
| Audit-Log-Tabelle | Pragmatischer ISO 27001-Control, DSGVO-Rechenschaftspflicht | Security-Expert |
| Vier-Augen-Prinzip (Code-Review) | Auftraggeber-Anforderung für alle Produktivcode-Änderungen | Auftraggeber |
| Admin-Formular-Builder in PHP statt React | Alle Admin-Seiten sind PHP; kein React-spezifisches Feature nötig; kein Build-Schritt, konsistenter Stack | Architect-Entscheidung |
| Admin Bar Badge: 2-min-Cache ohne aktive Invalidierung | Kurzer TTL reicht, erspart Hook-Komplexität; bewährtes Muster (vgl. Submission-Count-Cache) | Architect-Entscheidung |
| Admin Bar Badge: Kein Node bei Count=0 | Sauberer als "0" anzuzeigen; reduziert Admin-Bar-Clutter | UX-Expert |
| Admin Bar Badge: Count-Cap bei 99+ | Standard-UX-Pattern; verhindert Layout-Probleme bei hohen Zahlen | UX-Expert |
| Admin Bar Badge: Kein neuer DB-Index | Globaler COUNT performant genug bei < 50k Submissions; Reader-Query nutzt existierenden idx_form_read | Performance-Expert |

---

## Referenz-Dokumente

| Dokument | Beschreibung |
|----------|-------------|
| `SECURITY_REQUIREMENTS.md` | 78+ Security-Anforderungen (v1.5) |
| `LEGAL_REQUIREMENTS.md` | Rechtliche Anforderungen (v1.6) |
| `DATA_PROTECTION.md` | DPO-Findings, Privacy-by-Design |
| `QUALITY_STANDARDS.md` | Coding-Standards, Coverage, Reviews |
| `PERFORMANCE_REQUIREMENTS.md` | DB-Design, Caching, Budgets (v3.0) |
| `UX_CONCEPT.md` | Admin-UI, Formular-Builder, Mockups |
