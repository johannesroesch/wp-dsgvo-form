# Qualitäts-Standards — wp-dsgvo-form

> Verbindliche Standards für alle Entwickler, Tester und Reviewer.
> Dieses Dokument ist die Single Source of Truth für Code-Qualität im Projekt.

---

## 1. PHP Coding Standards

### Basis
- **PSR-12** (Extended Coding Style) als Grundlage
- **WordPress Coding Standards (WPCS)** wo sie von PSR-12 abweichen und WordPress-spezifisch sind (Hooks, DB-Zugriffe, Escaping)
- Tool: `phpcs` mit Ruleset `WordPress-Extra` + `PSR12`

### Regeln
| Regel | Wert |
|-------|------|
| Max. Zeilenlänge | 120 Zeichen (soft), 150 (hard) |
| Einrückung | Tabs (WordPress-Konvention) |
| Klammerstil | Allman-Style für Klassen/Funktionen, K&R für Control Structures (WP-Standard) |
| Namenskonventionen | `snake_case` für Funktionen/Variablen, `UPPER_SNAKE_CASE` für Konstanten, `PascalCase` für Klassen |
| Type Declarations | Pflicht für Parameter und Return-Types (PHP 8.1+) |
| Strict Types | `declare(strict_types=1);` in jeder PHP-Datei |

### WordPress-spezifisch
- **Escaping**: Alle Ausgaben mit `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses()` escapen
- **Sanitizing**: Alle Eingaben mit `sanitize_text_field()`, `absint()`, etc. sanitizen
- **Nonces**: Jedes Formular und jeder AJAX-Request muss Nonce-Verifizierung haben
- **Prepared Statements**: `$wpdb->prepare()` für ALLE Datenbank-Queries — keine Ausnahmen
- **Prefix**: Alle Funktionen, Klassen, Hooks mit `wpdsgvo_` prefixen um Namespace-Kollisionen zu vermeiden

### Tool-Konfiguration
```json
// composer.json (relevanter Auszug)
{
  "require-dev": {
    "squizlabs/php_codesniffer": "^3.9",
    "wp-coding-standards/wpcs": "^3.1",
    "phpstan/phpstan": "^2.0",
    "phpstan/phpstan-wordpress": "^2.0"
  }
}
```

---

## 2. JavaScript / React Standards

### Basis
- **ESLint** mit `@wordpress/eslint-plugin` (beinhaltet React- und JSX-Regeln)
- **Prettier** für Formatierung, integriert in ESLint

### Regeln
| Regel | Wert |
|-------|------|
| Einrückung | Tabs (WordPress-Konvention) |
| Semikolons | Ja |
| Anführungszeichen | Single-Quotes |
| Max. Zeilenlänge | 120 Zeichen |
| Variablen | `const` bevorzugt, `let` nur bei Mutation, `var` verboten |
| Komponenten | Functional Components mit Hooks (keine Class Components) |
| Prop-Types | PropTypes oder JSDoc für alle öffentlichen Component-Props |

### Gutenberg-spezifisch
- Nur `@wordpress/*`-Pakete für UI-Komponenten nutzen (keine externen UI-Libs)
- `useBlockProps()` in jedem Block-Edit und -Save verwenden
- Block-Attribute im `block.json` definieren, nicht inline
- i18n: Alle sichtbaren Strings mit `__()` / `_x()` aus `@wordpress/i18n`

### Tool-Konfiguration
```json
// .eslintrc.json
{
  "extends": ["plugin:@wordpress/eslint-plugin/recommended"],
  "rules": {
    "no-console": "warn",
    "complexity": ["warn", 10]
  }
}
```

---

## 3. Code-Complexity

### Limits
| Metrik | Max. Wert | Tool |
|--------|-----------|------|
| Funktionslänge (PHP) | 50 Zeilen | phpcs custom rule |
| Funktionslänge (JS) | 40 Zeilen | ESLint `max-lines-per-function` |
| Zyklomatische Komplexität | 10 | PHPStan / ESLint `complexity` |
| Verschachtelungstiefe | 4 Ebenen | phpcs / ESLint `max-depth` |
| Parameter pro Funktion | 5 | phpcs / ESLint `max-params` |
| Klassen-Methoden | max. 15 public methods | PHPStan |

### Umgang mit Überschreitungen
- Komplexität > Limit → Funktion muss aufgeteilt werden
- Überschreitungen dürfen NICHT mit `@phpcs:ignore` oder `// eslint-disable` unterdrückt werden
- Ausnahmen nur mit schriftlicher Begründung im PR und Genehmigung durch Quality Expert

---

## 4. Dokumentation

### PHPDoc
- **Pflicht** für: Klassen, Interfaces, öffentliche Methoden, Hooks (actions/filters)
- **Optional** für: Private Methoden (wenn Logik nicht offensichtlich)
- Format:
```php
/**
 * Verschlüsselt Formulardaten mit AES-256-GCM.
 *
 * @since 1.0.0
 *
 * @param string $plaintext Die zu verschlüsselnden Daten.
 * @param string $key       Der Verschlüsselungsschlüssel.
 * @return string           Base64-kodierter Ciphertext mit IV und Tag.
 *
 * @throws \RuntimeException Wenn OpenSSL nicht verfügbar ist.
 */
```

### JSDoc
- Pflicht für exportierte Funktionen und React-Komponenten
- Props-Dokumentation per JSDoc oder PropTypes

### Inline-Kommentare
- Nur wo das **Warum** nicht offensichtlich ist — kein Nacherzählen von Code
- Security-relevante Entscheidungen IMMER kommentieren (z.B. warum ein bestimmter Cipher gewählt wurde)
- TODO/FIXME nur mit Jira-Ticket-Nummer: `// TODO(#42): Migration auf neues API`

### Changelog
- `CHANGELOG.md` im Keep-a-Changelog-Format
- Jeder PR muss einen Changelog-Eintrag enthalten (außer reine Refactorings)

---

## 5. Test-Coverage

### Mindestanforderungen
| Bereich | Coverage | Typ |
|---------|----------|-----|
| Encryption/Decryption | 100% | Unit |
| Formular-Validierung | 95% | Unit |
| REST-API-Endpoints | 90% | Integration |
| Admin-UI CRUD | 85% | Integration |
| Gutenberg Block | 80% | Unit + E2E |
| Gesamt-Projekt | 80% | Combined |

### Test-Frameworks
- **PHP**: PHPUnit + WordPress Test Suite (`wp-phpunit`)
- **JavaScript**: Jest + `@testing-library/react`
- **E2E**: Playwright (oder wp-e2e falls passender)

### Test-Regeln
- Jeder PR muss Tests für neuen/geänderten Code mitbringen
- Security-relevanter Code (Encryption, Auth, Nonces) braucht IMMER Tests
- Tests müssen deterministisch sein — keine Abhängigkeit von externem State
- Test-Daten dürfen keine echten personenbezogenen Daten enthalten
- Mocking von `$wpdb` und WordPress-Funktionen über `WP_Mock` oder `Brain\Monkey`

---

## 6. Error-Handling

### Strategie
```
┌─────────────────────────────────────────┐
│ Ebene 1: WordPress-Hooks (Presentation) │  → wp_die() / admin_notice
│ Ebene 2: Service-Layer                  │  → Exceptions werfen
│ Ebene 3: Data-Layer                     │  → WP_Error / false zurückgeben
└─────────────────────────────────────────┘
```

### Regeln
- **Keine stummen Fehler**: Kein leerer `catch`-Block ohne Logging
- **Exceptions** für unerwartete Zustände (z.B. Encryption-Fehler)
- **WP_Error** für erwartete Fehler im Data-Layer (z.B. Validierung)
- **HTTP-Status-Codes** korrekt in REST-Responses (400 für Validation, 403 für Auth, 500 für Server-Fehler)
- **Benutzerfreundliche Meldungen** in der UI — keine Stack-Traces, keine internen Details

### Exception-Hierarchie
```
WpDsgvoException (Basis)
├── EncryptionException
├── ValidationException
├── AuthenticationException
└── StorageException
```

---

## 7. Logging

### Regeln
- Logging über `error_log()` oder einen leichtgewichtigen PSR-3-kompatiblen Logger
- **NIEMALS loggen**: Passwörter, Encryption-Keys, personenbezogene Daten, Formular-Inhalte
- **Immer loggen**: Encryption-Fehler, Authentication-Fehler, unerwartete Exceptions
- Log-Level verwenden:
  - `ERROR` — Fehler die Funktionalität beeinträchtigen
  - `WARNING` — Unerwartetes Verhalten, das abgefangen wurde
  - `DEBUG` — Nur in Entwicklung aktiv (gesteuert durch `WP_DEBUG`)

### Sensitive-Daten-Filter
```php
// Beispiel: Niemals Klartext-Daten loggen
error_log( sprintf(
    '[wp-dsgvo-form] Encryption failed for submission #%d: %s',
    $submission_id,
    $exception->getMessage()
    // NICHT: $plaintext_data
) );
```

---

## 8. Security-Coding-Standards

> Ergänzend zu den Anforderungen des Security Experts.

- **Input Validation**: Whitelist-Ansatz — nur erlaubte Werte akzeptieren
- **Output Escaping**: Context-aware (HTML, Attribut, URL, JS)
- **CSRF**: WordPress-Nonces für alle State-ändernden Operationen
- **Capabilities**: `current_user_can()` vor jeder privilegierten Aktion prüfen
- **Direct File Access**: `defined('ABSPATH') || exit;` am Anfang jeder PHP-Datei
- **No `eval()`**: Kein `eval()`, `create_function()`, `preg_replace()` mit `e`-Modifier
- **No `serialize()`/`unserialize()`**: `json_encode()`/`json_decode()` oder `wp_json_encode()` verwenden
- **Dependencies**: Keine externen PHP-Dependencies via Composer in Production (alles self-contained)

---

## 9. Accessibility (WCAG 2.1 AA)

### Admin-UI
- Alle Formular-Felder mit zugehörigem `<label>`
- Fehlermeldungen mit `aria-describedby` verknüpft
- Farbkontrast-Ratio mindestens 4.5:1 (Text) bzw. 3:1 (große Schrift)
- Tastatur-Navigation für alle interaktiven Elemente
- Focus-Management bei dynamischen UI-Änderungen (Modale, Tabs)
- WordPress Admin-UI-Komponenten bevorzugen (bereits WCAG-konform)

### Öffentliche Formulare (Gutenberg Block)
- Semantisches HTML (`<form>`, `<fieldset>`, `<legend>`)
- `aria-required="true"` für Pflichtfelder
- `aria-invalid="true"` + Fehlerbeschreibung bei Validierungsfehlern
- Kein CAPTCHA ohne barrierefreie Alternative (Audio-CAPTCHA oder Honeypot-Fallback)
- Formulare müssen ohne JavaScript grundlegende Funktion behalten (Progressive Enhancement)

---

## 10. Deprecation-Handling

- Keine veralteten WordPress-Funktionen verwenden
- Minimum-Kompatibilität: **WordPress 6.4+**, **PHP 8.1+**
- Vor jedem Release: `phpcs` mit `WordPress.WP.DeprecatedFunctions` Rule ausführen
- Bei eigenen Deprecations: `_deprecated_function()` verwenden, mindestens 2 Minor-Versionen vorhalten

---

## 11. DSGVO/Compliance-Coding-Standards

> Erarbeitet mit legal-expert und dpo. Referenz: `LEGAL_REQUIREMENTS.md`, `DATA_PROTECTION.md`

### Annotations

Zwei getrennte PHPDoc-Tags für gezielte Audits:

- **`@privacy-relevant`** — DSGVO-Bezug: Einwilligung, Betroffenenrechte, Datenlöschung, Consent-Versionierung
- **`@security-critical`** — Crypto/Auth: Verschlüsselung, Key-Management, CSRF, Capability-Checks

Überschneidungen sind erlaubt. Format:
```php
/**
 * @privacy-relevant Art. 17 DSGVO — Recht auf Löschung
 * @security-critical Kaskadierte Löschung inkl. verschlüsselter Dateien
 */
```

Gezielte Suche:
```bash
grep -r "@privacy-relevant" src/    # Datenschutz-Audit
grep -r "@security-critical" src/   # Security-Review
```

### QUALITY-DSGVO-01: Keine hardcoded Einwilligungstexte

Einwilligungstexte und Datenschutzhinweise **dürfen nicht** als String-Literale in Templates oder Controllern stehen. Sie **müssen** aus der Datenbank geladen oder über dedizierte Template-Klassen bereitgestellt werden.

- Default-Texte in zentraler Klasse (z.B. `ConsentTemplates::getDefault()`)
- Admin-konfigurierbar pro Formular
- Versioniert (Spalte `consent_version`)

### QUALITY-DSGVO-02: Löschfunktionen vollständig dokumentieren

Löschfunktionen für personenbezogene Daten **müssen** dokumentieren:
- Was genau gelöscht wird (DB-Datensätze, Dateien, Caches, Transients)
- Was **nicht** gelöscht wird und warum (z.B. Audit-Log-Einträge)
- Ob die Löschung kaskadiert
- Den DSGVO-Bezug (Artikel-Referenz)

### QUALITY-DSGVO-03: `@privacy-relevant` Annotation

Funktionen, die personenbezogene Daten verarbeiten, **müssen** mit `@privacy-relevant` im PHPDoc markiert werden, inkl. DSGVO-Artikel-Referenz.

### QUALITY-DSGVO-04: Audit-fähige Datenfluss-Dokumentation

Jede Klasse, die personenbezogene Daten verarbeitet, enthält einen PHPDoc-Block mit:
- Welche Daten verarbeitet werden
- Woher die Daten kommen (Input)
- Wohin die Daten gehen (Output/Speicherung)

Beispiel:
```php
/**
 * Speichert verschlüsselte Formular-Einsendungen.
 *
 * @privacy-relevant Art. 6 Abs. 1 lit. a DSGVO — Einwilligung
 *
 * Datenfluss:
 * - Input: Formular-Daten (POST-Request, validiert durch FormValidator)
 * - Verarbeitung: AES-256-GCM Verschlüsselung via EncryptionService
 * - Output: Verschlüsselte Daten in {prefix}wpdsgvo_submissions
 * - Löschung: Via SubmissionRepository::delete() (Art. 17 DSGVO)
 */
```

### Rechtsgrundlagen-Kommentare

**Pflicht** an Stellen, wo die Rechtsgrundlage die Code-Logik beeinflusst:
```php
// Rechtsgrundlage: Art. 6 Abs. 1 lit. a DSGVO — Einwilligung
// Consent-Checkbox muss vor Speicherung geprüft werden
```

**Pflicht** an Stellen, wo bewusst **keine** Daten gespeichert werden:
```php
// IP-Adresse wird bewusst NICHT gespeichert — DSGVO Art. 5 Abs. 1 lit. c (Datensparsamkeit)
```

**Nicht nötig** bei: Standard-CRUD, Formular-Rendering, Gutenberg-Block-Logik.

---

## 12. Code-Review-Checkliste

Jeder PR muss vor dem Merge diese Checkliste erfüllen:

### Funktional
- [ ] Feature/Bugfix arbeitet wie beschrieben
- [ ] Edge Cases behandelt (leere Eingaben, Sonderzeichen, Unicode)
- [ ] Fehlerbehandlung vorhanden und getestet

### Security
- [ ] Input-Sanitization für alle User-Eingaben
- [ ] Output-Escaping für alle Ausgaben
- [ ] Nonce-Verifizierung bei State-ändernden Requests
- [ ] Capability-Checks bei privilegierten Aktionen
- [ ] Keine sensitiven Daten in Logs/Fehlermeldungen
- [ ] Encryption korrekt implementiert (kein ECB, korrekte IV-Generierung)

### Code-Qualität
- [ ] phpcs/ESLint ohne Errors
- [ ] PHPStan Level 6+ ohne Errors
- [ ] Keine `@phpcs:ignore` oder `eslint-disable` ohne Begründung
- [ ] Funktionslänge und Komplexität innerhalb der Limits
- [ ] Keine Code-Duplikation (DRY)

### Tests
- [ ] Neue/geänderte Funktionalität hat Tests
- [ ] Alle Tests grün
- [ ] Coverage-Anforderungen erfüllt (siehe Abschnitt 5)

### Dokumentation
- [ ] PHPDoc/JSDoc für neue öffentliche APIs
- [ ] CHANGELOG.md aktualisiert
- [ ] README.md bei Bedarf aktualisiert

### Accessibility
- [ ] Neue UI-Elemente tastatur-bedienbar
- [ ] Labels und ARIA-Attribute korrekt
- [ ] Farbkontrast geprüft

### DSGVO/Datenschutz

#### Datenminimierung (Art. 5 Abs. 1 lit. c)
- [ ] Keine zusätzlichen Metadaten erfassen, die nicht vom Admin konfiguriert wurden (kein User-Agent, Referrer, Browser-Fingerprint)
- [ ] IP-Adresse wird NICHT in DB oder Transients gespeichert (SEC-DSGVO-02, SEC-CAP-11)
- [ ] Keine `$_SERVER['REMOTE_ADDR']` in DB-Insert-Statements (außer Audit-Log für Admin-IPs)
- [ ] REST-API-Responses enthalten nur angeforderte Felder — keine zusätzlichen personenbezogenen Daten

#### Verschlüsselung & Speicherung
- [ ] Verschlüsselung VOR DB-Insert (`EncryptionService::encrypt()` → `$wpdb->insert()`)
- [ ] Entschlüsselung erst bei Anzeige/Export, nie vorsorglich (SEC-ENC-09)
- [ ] Kein Klartext in DB, Temp-Dateien, Session-Variablen oder Transients
- [ ] `random_bytes(12)` für jeden IV — kein wiederverwendeter IV

#### Speicherfristen & Löschung
- [ ] Neue DB-Tabellen mit personenbezogenen Daten haben ein Löschkonzept (Code-Kommentar oder ARCHITECTURE.md)
- [ ] `expires_at` wird bei Submission-Insert gesetzt (basierend auf `retention_days`)
- [ ] DELETE-Operationen sind echte DELETEs (kein Soft-Delete mit `is_deleted`-Flag)
- [ ] Kaskaden-Löschung: Dateien werden mit Submission gelöscht (physisch + DB)
- [ ] Löschfunktionen vollständig dokumentiert — was/was nicht/warum (QUALITY-DSGVO-02)

#### Datentransfer & REST-API
- [ ] Keine entschlüsselten Formulardaten in REST-Responses mit `Cache-Control: public`
- [ ] REST-Responses für Submissions enthalten `Cache-Control: no-store, no-cache`
- [ ] Kein Logging von entschlüsselten Formulardaten (weder WP-Debug-Log noch error_log)
- [ ] E-Mails enthalten KEINE Formulardaten — nur "Neue Einsendung" + Login-Link (SEC-MAIL-03)

#### Consent-Tracking
- [ ] Consent-Validation serverseitig implementiert — nicht nur client-seitig (LEGAL-CONSENT-06, HTTP 422)
- [ ] Zeitstempel der Einwilligung wird gespeichert
- [ ] Einwilligungstext-Version-ID wird gespeichert
- [ ] Bei Änderung des Einwilligungstexts: Neue Version, alte bleibt archiviert
- [ ] Einwilligungsdaten (Text + Zeitstempel) sind NICHT verschlüsselt (Compliance-Nachweis)
- [ ] Einwilligungstexte nicht hardcoded (QUALITY-DSGVO-01)

#### Audit-Trail
- [ ] Zugriff auf Submissions (View/Export/Delete) erzeugt Audit-Log-Eintrag
- [ ] Audit-Log hat KEIN DELETE-Endpoint / keine Admin-Lösch-Funktion
- [ ] Audit-Log-Einträge enthalten: user_id, action, submission_id, form_id, timestamp, ip_address

#### Dokumentation & Annotations
- [ ] `@privacy-relevant` Annotation bei Funktionen die personenbezogene Daten verarbeiten (QUALITY-DSGVO-03)
- [ ] Datenfluss dokumentiert — Input/Verarbeitung/Output/Löschung (QUALITY-DSGVO-04)
- [ ] Rechtsgrundlage kommentiert wo sie Code-Logik beeinflusst

### WordPress-Kompatibilität
- [ ] Keine veralteten Funktionen
- [ ] Translations korrekt (`__()`, `_e()`, `esc_html__()`)
- [ ] Prefix `wpdsgvo_` für alle globalen Identifier

---

## 13. Tooling-Übersicht

| Tool | Zweck | Konfiguration |
|------|-------|---------------|
| phpcs | PHP Coding Standards | `.phpcs.xml.dist` |
| PHPStan | Statische Analyse PHP (Level 6) | `phpstan.neon` |
| ESLint | JS/React Linting | `.eslintrc.json` |
| Prettier | Code-Formatierung JS | `.prettierrc` |
| PHPUnit | PHP Unit/Integration Tests | `phpunit.xml.dist` |
| Jest | JS Unit Tests | `jest.config.js` |
| Playwright | E2E Tests | `playwright.config.ts` |
| Composer Scripts | Lint/Test-Shortcuts | `composer.json` |

### CI-Pipeline-Anforderungen
- Alle oben genannten Tools müssen in der CI laufen
- PR darf nicht gemerged werden wenn ein Tool einen Error meldet
- Coverage-Reports müssen generiert und geprüft werden

---

## Anhang: Quick Reference

```bash
# PHP Linting
composer phpcs          # Coding Standards prüfen
composer phpcbf         # Auto-Fix wo möglich
composer phpstan        # Statische Analyse

# JS Linting
npm run lint            # ESLint
npm run lint:fix        # Auto-Fix

# Tests
composer test           # PHP Tests
npm test                # JS Tests
npm run test:e2e        # E2E Tests

# Alle Checks (CI-equivalent)
composer check-all      # phpcs + phpstan + test
npm run check-all       # lint + test + test:e2e
```

---

*Dokument erstellt: 2026-04-17 | Quality Expert | Version 1.2*
*Update 1.1: DSGVO/Compliance-Abschnitt hinzugefügt (legal-expert)*
*Update 1.2: Erweiterte Datenschutz-Checkliste mit dpo-Input (Datenminimierung, Speicherfristen, Audit-Trail, Consent-Tracking)*
*Nächste Review: vor Release 1.0*
