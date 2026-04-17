# Coding Standards & Architektur-Guidelines — wp-dsgvo-form

> Verbindliche Standards fuer alle Entwickler, Tester und Reviewer.
> Ergaenzt `QUALITY_STANDARDS.md` (Projekt-Root) mit architekturspezifischen Details.

---

## 1. PHP Coding Standards

### 1.1 Basis-Kombination: WPCS + PSR-12

Wir verwenden eine **Kombination** beider Standards:

| Aspekt | Standard | Begruendung |
|--------|----------|-------------|
| Einrueckung | Tabs (WPCS) | WordPress-Oekosystem-Konsistenz |
| Klammerstil | WP-Style (K&R fuer Control, Allman fuer Klassen) | WPCS |
| Namenskonventionen | `snake_case` Funktionen/Variablen, `PascalCase` Klassen | WPCS |
| Type Declarations | Pflicht (PSR-12 erweitert) | Typsicherheit, PHPStan |
| Strict Types | `declare(strict_types=1);` in jeder Datei | PSR-12 |
| Namespace-Struktur | PSR-4 kompatibel | Autoloading |
| DB-Zugriffe, Escaping, Nonces | WPCS | WordPress-Security |

**Tool-Konfiguration**: `phpcs` mit Custom-Ruleset das `WordPress-Extra` als Basis nimmt und PSR-12-Ergaenzungen hinzufuegt.

```xml
<!-- .phpcs.xml.dist -->
<?xml version="1.0"?>
<ruleset name="WpDsgvoForm">
    <description>Coding Standards fuer wp-dsgvo-form</description>

    <file>./includes</file>
    <file>./src</file>
    <file>./tests</file>

    <arg name="extensions" value="php"/>
    <arg name="colors"/>
    <arg value="sp"/>

    <!-- WordPress als Basis -->
    <rule ref="WordPress-Extra"/>
    <rule ref="WordPress.WP.DeprecatedFunctions"/>

    <!-- PSR-12 Ergaenzungen -->
    <rule ref="Generic.PHP.RequireStrictTypes"/>

    <!-- Zeilenlaenge -->
    <rule ref="Generic.Files.LineLength">
        <properties>
            <property name="lineLimit" value="120"/>
            <property name="absoluteLineLimit" value="150"/>
        </properties>
    </rule>

    <!-- Komplexitaet -->
    <rule ref="Generic.Metrics.CyclomaticComplexity">
        <properties>
            <property name="complexity" value="10"/>
        </properties>
    </rule>
    <rule ref="Generic.Metrics.NestingLevel">
        <properties>
            <property name="nestingLevel" value="4"/>
        </properties>
    </rule>
</ruleset>
```

### 1.2 Allgemeine Regeln

- `declare(strict_types=1);` in jeder PHP-Datei
- Type Declarations fuer alle Parameter und Return-Types
- `defined('ABSPATH') || exit;` am Anfang jeder PHP-Datei
- Prefix `wpdsgvo_` fuer alle globalen Funktionen, Hooks, Options, Transients
- Keine `eval()`, `create_function()`, `serialize()`/`unserialize()`
- `$wpdb->prepare()` fuer ALLE Datenbank-Queries

---

## 2. Namespace-Struktur

### 2.1 Root-Namespace

```
WpDsgvoForm\
```

### 2.2 Sub-Namespaces

```
WpDsgvoForm\
├── Admin\              # Admin-UI, Menue-Seiten, Settings
│   ├── FormBuilder\    # Formular-Builder UI-Logik
│   ├── Settings\       # Plugin-Einstellungen
│   └── RecipientUI\    # Empfaenger-Login & Verwaltung
├── Block\              # Gutenberg Block Registration & Rendering
├── Encryption\         # AES-256 Verschluesselung, Key-Management
├── Form\               # Formular-Modelle, Felder, Validierung
│   ├── Field\          # Feld-Typen (Text, Email, Checkbox, etc.)
│   └── Validation\     # Validierungsregeln
├── Rest\               # REST-API-Endpoints
├── Storage\            # Datenbank-Zugriff (Repository-Pattern)
├── Captcha\            # CAPTCHA-Integration
├── Auth\               # Empfaenger-Authentifizierung
├── Exception\          # Exception-Hierarchie
└── Util\               # Hilfsfunktionen (nur wenn wirklich noetig)
```

### 2.3 Namenskonventionen

- Ein Namespace-Segment = ein Verzeichnis
- Eine Klasse pro Datei
- Dateiname = Klassenname (PSR-4)
- Interfaces: `*Interface` Suffix (z.B. `EncryptionServiceInterface`)
- Abstract Classes: `Abstract*` Prefix (z.B. `AbstractFieldType`)

---

## 3. Autoloading

### 3.1 PSR-4 via Composer

```json
{
    "autoload": {
        "psr-4": {
            "WpDsgvoForm\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "WpDsgvoForm\\Tests\\": "tests/"
        }
    }
}
```

### 3.2 Warum Composer-Autoloading statt WP-eigenes

| Kriterium | Composer PSR-4 | WP spl_autoload |
|-----------|---------------|-----------------|
| Performance | Classmap in Prod (optimiert) | Jeder Load = Dateisuche |
| Standard | PHP-Community-Standard | WP-spezifisch |
| IDE-Support | Perfekt (PhpStorm, VS Code) | Manuell |
| Testbarkeit | Sofort nutzbar in PHPUnit | Braucht WP-Bootstrap |

**Wichtig**: Der generierte Autoloader (`vendor/autoload.php`) wird im Plugin-Hauptfile eingebunden. Das `vendor/`-Verzeichnis enthaelt NUR den Autoloader + dev-Dependencies, KEINE Runtime-Dependencies.

```php
// wp-dsgvo-form.php (Plugin-Hauptdatei)
defined( 'ABSPATH' ) || exit;

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}
```

---

## 4. JavaScript / React Standards

### 4.1 ESLint-Konfiguration

```json
{
    "extends": ["plugin:@wordpress/eslint-plugin/recommended"],
    "rules": {
        "no-console": "warn",
        "complexity": ["warn", 10],
        "max-depth": ["error", 4],
        "max-params": ["error", 5],
        "max-lines-per-function": ["warn", { "max": 40, "skipBlankLines": true, "skipComments": true }],
        "@wordpress/no-unsafe-wp-apis": "error"
    },
    "overrides": [
        {
            "files": ["**/*.test.js", "**/*.test.jsx"],
            "rules": {
                "max-lines-per-function": "off"
            }
        }
    ]
}
```

### 4.2 Gutenberg-Block-Code

- Nur `@wordpress/*`-Pakete fuer UI-Komponenten (`@wordpress/components`, `@wordpress/block-editor`)
- `block.json` als Single Source of Truth fuer Block-Definition
- `useBlockProps()` in jedem Edit und Save
- i18n: `__()`, `_x()` aus `@wordpress/i18n`
- Functional Components mit Hooks — keine Class Components
- State-Management ueber Block-Attribute und `setAttributes()`

### 4.3 Datei-Struktur JS

```
src/
└── blocks/
    └── dsgvo-form/
        ├── block.json          # Block-Registrierung
        ├── index.js            # Entry (registerBlockType)
        ├── edit.js             # Editor-Komponente
        ├── save.js             # Frontend-Rendering (oder dynamic via PHP)
        ├── style.scss          # Frontend-Styles
        ├── editor.scss         # Editor-Styles
        ├── components/         # Wiederverwendbare Sub-Komponenten
        │   ├── FieldList.js
        │   ├── CaptchaPreview.js
        │   └── StyleControls.js
        └── __tests__/          # Jest-Tests
            ├── edit.test.js
            └── save.test.js
```

---

## 5. Verzeichnisstruktur (Gesamtprojekt)

```
wp-dsgvo-form/
├── wp-dsgvo-form.php           # Plugin-Hauptdatei (Bootstrap)
├── uninstall.php               # Cleanup bei Deinstallation
├── composer.json
├── package.json
├── .phpcs.xml.dist
├── phpstan.neon
├── phpunit.xml.dist
├── .eslintrc.json
├── .prettierrc
├── webpack.config.js           # oder @wordpress/scripts default
│
├── src/                        # PHP-Klassen (PSR-4: WpDsgvoForm\)
│   ├── Admin/
│   │   ├── AdminMenu.php
│   │   ├── FormBuilder/
│   │   ├── Settings/
│   │   └── RecipientUI/
│   ├── Block/
│   │   └── DsgvoFormBlock.php  # Server-side Block-Registrierung
│   ├── Encryption/
│   │   ├── EncryptionServiceInterface.php
│   │   └── AesGcmEncryptionService.php
│   ├── Form/
│   │   ├── FormModel.php
│   │   ├── Field/
│   │   └── Validation/
│   ├── Rest/
│   │   ├── FormEndpoint.php
│   │   └── SubmissionEndpoint.php
│   ├── Storage/
│   │   ├── FormRepository.php
│   │   └── SubmissionRepository.php
│   ├── Captcha/
│   │   └── CaptchaService.php
│   ├── Auth/
│   │   └── RecipientAuth.php
│   ├── Exception/
│   │   ├── WpDsgvoException.php
│   │   ├── EncryptionException.php
│   │   ├── ValidationException.php
│   │   ├── AuthenticationException.php
│   │   └── StorageException.php
│   └── Plugin.php              # Haupt-Bootstrap-Klasse
│
├── src/blocks/                 # JS/React Gutenberg-Code
│   └── dsgvo-form/
│       ├── block.json
│       ├── index.js
│       ├── edit.js
│       ├── save.js
│       └── components/
│
├── templates/                  # PHP-Templates (Admin-Views)
│   ├── admin/
│   │   ├── form-builder.php
│   │   ├── settings.php
│   │   └── recipient-login.php
│   └── frontend/
│       └── form-display.php    # Fallback-Template
│
├── assets/                     # Statische Assets (nicht von webpack)
│   ├── css/
│   └── images/
│
├── build/                      # Webpack-Output (gitignored)
│
├── languages/                  # i18n .pot/.po/.mo Dateien
│
├── tests/
│   ├── Unit/                   # PHPUnit Unit-Tests
│   │   ├── Encryption/
│   │   ├── Form/
│   │   └── Validation/
│   ├── Integration/            # WordPress-Integration-Tests
│   │   ├── Rest/
│   │   └── Storage/
│   ├── E2E/                    # Playwright E2E-Tests
│   └── bootstrap.php
│
├── docs/                       # Dokumentation
│   ├── coding-standards.md     # Dieses Dokument
│   └── architecture.md         # Architektur-Uebersicht
│
└── vendor/                     # Composer (gitignored in Prod)
```

---

## 6. Testbarkeit — Architektur-Patterns

### 6.1 Dependency Injection (Constructor Injection)

**Pflicht**: Alle Services erhalten ihre Abhaengigkeiten ueber den Constructor.

```php
// RICHTIG
class SubmissionEndpoint {
    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly FormRepository $form_repository,
    ) {}
}

// FALSCH
class SubmissionEndpoint {
    public function handle(): void {
        $encryption = new AesGcmEncryptionService(); // Hard-coded!
    }
}
```

### 6.2 Interface-Abstraktion fuer externe Services

Interfaces fuer alles was extern ist oder sich aendern koennte:

```php
interface EncryptionServiceInterface {
    public function encrypt( string $plaintext, string $key ): string;
    public function decrypt( string $ciphertext, string $key ): string;
}

interface CaptchaServiceInterface {
    public function verify( string $token ): bool;
}
```

### 6.3 Repository-Pattern fuer Datenbankzugriffe

```php
interface FormRepositoryInterface {
    public function find( int $id ): ?FormModel;
    public function save( FormModel $form ): int;
    public function delete( int $id ): bool;
    /** @return FormModel[] */
    public function find_all(): array;
}
```

In Tests kann das Repository durch ein In-Memory-Repository ersetzt werden.

### 6.4 Service-Container (leichtgewichtig)

**Kein vollstaendiger DI-Container** (keine externe Dependency). Stattdessen eine einfache Factory/Registry in der Plugin-Klasse:

```php
final class Plugin {
    private static ?self $instance = null;
    private ?EncryptionServiceInterface $encryption = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function encryption(): EncryptionServiceInterface {
        return $this->encryption ??= new AesGcmEncryptionService();
    }

    // Fuer Tests: Service ueberschreiben
    public function set_encryption( EncryptionServiceInterface $service ): void {
        $this->encryption = $service;
    }
}
```

### 6.5 Kein Singleton-Pattern fuer normale Klassen

- **Nur** die Plugin-Hauptklasse darf Singleton sein (WordPress-Konvention)
- Alle anderen Klassen: normale Instanzen, via DI verbunden
- Grund: Singletons sind schwer testbar und erzeugen versteckte Abhaengigkeiten

### 6.6 WordPress-Hooks testbar registrieren

```php
// Hooks in einer dedizierten Methode registrieren (nicht im Constructor!)
class AdminMenu {
    public function register_hooks(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_pages' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }
}
```

---

## 7. WordPress-Best-Practices

### 7.1 Hook-Registrierung

- Alle Hooks werden in der `Plugin::boot()` Methode oder in dedizierter `register_hooks()` pro Klasse registriert
- Keine Hooks in Constructors
- Hook-Prioritaeten dokumentieren wenn nicht default (10)
- Custom Hooks mit `wpdsgvo_` Prefix:
  - Actions: `do_action( 'wpdsgvo_before_form_submit', $form_id )`
  - Filters: `apply_filters( 'wpdsgvo_field_types', $types )`

### 7.2 Options & Transients

- Prefix: `wpdsgvo_`
- Options nur ueber eine Settings-Klasse lesen/schreiben (kein direktes `get_option()` verstreut im Code)
- Transients fuer Cache-Daten mit sinnvoller Ablaufzeit
- `uninstall.php` muss ALLE Options, Transients und DB-Tabellen aufraumen

### 7.3 Internationalisierung (i18n)

- Text-Domain: `wp-dsgvo-form`
- PHP: `__( 'Text', 'wp-dsgvo-form' )`, `esc_html__()`, `_e()`, `_n()`
- JS: `__( 'Text', 'wp-dsgvo-form' )` aus `@wordpress/i18n`
- `.pot`-Datei in `languages/` generieren via `wp i18n make-pot`

### 7.4 Datenbank-Custom-Tables

- Prefix: `{$wpdb->prefix}wpdsgvo_`
- Schema-Erstellung in Aktivierungs-Hook mit `dbDelta()`
- Versionierung der DB-Schemas ueber Plugin-Version in Options

### 7.5 REST-API

- Namespace: `wpdsgvo/v1`
- Permission-Callbacks fuer jeden Endpoint
- Schema-Validierung fuer Request-Parameter
- `WP_REST_Response` fuer alle Antworten

---

## 8. Build-System

### 8.1 `@wordpress/scripts` (empfohlen)

Statt manueller webpack-Konfiguration: `@wordpress/scripts` nutzen. Es ist der offizielle WordPress-Weg und bietet:
- Vorkonfiguriertes webpack mit React/JSX-Support
- Automatische Dependency-Extraktion fuer `@wordpress/*`-Pakete
- SCSS-Support
- Source Maps in Development
- Minification in Production

```json
{
    "scripts": {
        "build": "wp-scripts build",
        "start": "wp-scripts start",
        "lint": "wp-scripts lint-js",
        "lint:fix": "wp-scripts lint-js --fix",
        "lint:css": "wp-scripts lint-style",
        "test": "wp-scripts test-unit-js",
        "test:e2e": "wp-scripts test-playwright",
        "check-all": "npm run lint && npm run lint:css && npm test"
    },
    "devDependencies": {
        "@wordpress/scripts": "^30.0",
        "@wordpress/eslint-plugin": "^21.0"
    }
}
```

### 8.2 webpack-Konfiguration (nur falls Anpassung noetig)

```js
// webpack.config.js (erweitert @wordpress/scripts Default)
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
    ...defaultConfig,
    entry: {
        'dsgvo-form': './src/blocks/dsgvo-form/index.js',
        'admin': './src/blocks/admin/index.js',
    },
};
```

### 8.3 Asset-Enqueuing

```php
// Generierte .asset.php Datei nutzen (von @wordpress/scripts erzeugt)
$asset_file = include plugin_dir_path( __FILE__ ) . 'build/dsgvo-form.asset.php';

wp_enqueue_script(
    'wpdsgvo-form-block',
    plugins_url( 'build/dsgvo-form.js', __FILE__ ),
    $asset_file['dependencies'],
    $asset_file['version'],
    true
);
```

---

## 9. Error-Handling-Strategie

### 3-Ebenen-Modell

```
Ebene 1: Presentation (WordPress Hooks)  ->  wp_die() / admin_notice / REST-Response
Ebene 2: Service-Layer                   ->  Exceptions werfen
Ebene 3: Data-Layer (Repository)         ->  WP_Error / false
```

### Exception-Hierarchie

```php
namespace WpDsgvoForm\Exception;

class WpDsgvoException extends \RuntimeException {}
class EncryptionException extends WpDsgvoException {}
class ValidationException extends WpDsgvoException {}
class AuthenticationException extends WpDsgvoException {}
class StorageException extends WpDsgvoException {}
```

### REST-API-Fehlerbehandlung

```php
// In REST-Endpoint: Exceptions fangen und als WP_REST_Response zurueckgeben
try {
    $result = $this->service->process( $request );
    return new \WP_REST_Response( $result, 200 );
} catch ( ValidationException $e ) {
    return new \WP_Error( 'validation_error', $e->getMessage(), [ 'status' => 400 ] );
} catch ( AuthenticationException $e ) {
    return new \WP_Error( 'auth_error', $e->getMessage(), [ 'status' => 403 ] );
} catch ( WpDsgvoException $e ) {
    error_log( '[wp-dsgvo-form] ' . $e->getMessage() );
    return new \WP_Error( 'internal_error', __( 'Ein Fehler ist aufgetreten.', 'wp-dsgvo-form' ), [ 'status' => 500 ] );
}
```

---

## 10. Test-Standards & Coverage

### Mindestanforderungen

| Bereich | Coverage | Typ |
|---------|----------|-----|
| Encryption/Decryption | 100% | Unit |
| Formular-Validierung | 95% | Unit |
| REST-API-Endpoints | 90% | Integration |
| Admin-UI CRUD | 85% | Integration |
| Gutenberg Block | 80% | Unit + E2E |
| Gesamt-Projekt | 80% | Combined |

### Test-Namenskonventionen

```php
// PHP: test_{methodName}_{scenario}_{expectedResult}
public function test_encrypt_with_valid_key_returns_ciphertext(): void {}
public function test_encrypt_with_empty_key_throws_exception(): void {}

// Alternativ mit @test Annotation und beschreibendem Namen:
/** @test */
public function it_encrypts_data_with_aes_256_gcm(): void {}
```

```js
// JS: describe('ComponentName') > it('should ...')
describe( 'FieldList', () => {
    it( 'should render all configured fields', () => {} );
    it( 'should call onChange when field is modified', () => {} );
} );
```

### Test-Kategorien

| Kategorie | Tool | Ausfuehrung | Scope |
|-----------|------|-------------|-------|
| Unit | PHPUnit / Jest | Schnell (<1s pro Test) | Einzelne Klasse/Funktion |
| Integration | PHPUnit + WP Test Suite | Mittel | Service + DB/WP-API |
| E2E | Playwright | Langsam | Vollstaendiger User-Flow |

### Test-Verzeichnisstruktur

```
tests/
├── Unit/                       # Spiegelt src/ Struktur
│   ├── Encryption/
│   │   └── AesGcmEncryptionServiceTest.php
│   ├── Form/
│   │   ├── FormModelTest.php
│   │   └── Validation/
│   └── Captcha/
├── Integration/
│   ├── Rest/
│   │   ├── FormEndpointTest.php
│   │   └── SubmissionEndpointTest.php
│   └── Storage/
│       └── FormRepositoryTest.php
├── E2E/
│   ├── form-submission.spec.ts
│   ├── admin-form-builder.spec.ts
│   └── recipient-login.spec.ts
└── bootstrap.php
```

---

## 11. DSGVO/Compliance-Coding-Standards

> Erarbeitet mit legal-expert und dpo. Referenz: `LEGAL_REQUIREMENTS.md`, `DATA_PROTECTION.md`

### 11.1 PHPDoc-Annotations fuer Audits

Zwei getrennte Tags:

| Tag | Zweck | Beispiel |
|-----|-------|---------|
| `@privacy-relevant` | DSGVO-Bezug | `@privacy-relevant Art. 17 DSGVO — Recht auf Loeschung` |
| `@security-critical` | Crypto/Auth | `@security-critical Kaskadierte Loeschung verschluesselter Dateien` |

Ueberschneidungen erlaubt. Gezielte Suche:
```bash
grep -r "@privacy-relevant" src/    # Datenschutz-Audit
grep -r "@security-critical" src/   # Security-Review
```

### 11.2 QUALITY-DSGVO-Regeln

**QUALITY-DSGVO-01**: Einwilligungstexte NICHT hardcoded. Aus DB laden, Admin-konfigurierbar, versioniert.

**QUALITY-DSGVO-02**: Loeschfunktionen dokumentieren: was geloescht wird, was nicht, ob kaskadiert, DSGVO-Artikel.

**QUALITY-DSGVO-03**: `@privacy-relevant` Annotation Pflicht bei Funktionen die personenbezogene Daten verarbeiten.

**QUALITY-DSGVO-04**: Audit-faehige Datenfluss-Doku (Input/Verarbeitung/Output/Loeschung) fuer alle datenverarbeitenden Klassen.

### 11.3 Rechtsgrundlagen-Kommentare

Pflicht wo Rechtsgrundlage die Code-Logik beeinflusst oder bewusst Daten NICHT gespeichert werden.
Nicht noetig bei Standard-CRUD, Rendering, Block-Logik.

Vollstaendige Details: `QUALITY_STANDARDS.md` Abschnitt 11.

---

## 12. Review-Prozess

### Definition of Done

Ein Feature/Bugfix ist "done" wenn:

1. Code implementiert und funktional
2. phpcs + PHPStan + ESLint ohne Errors
3. Tests geschrieben und gruen
4. Coverage-Anforderungen erfuellt
5. PHPDoc/JSDoc fuer oeffentliche APIs
6. CHANGELOG.md aktualisiert
7. Von mindestens 1 Reviewer approved
8. CI-Pipeline gruen

### Code-Review-Checkliste

Siehe `QUALITY_STANDARDS.md` Abschnitt 12 fuer die vollstaendige Checkliste (inkl. DSGVO/Datenschutz-Block).

---

## 13. Composer-Scripts

```json
{
    "scripts": {
        "phpcs": "phpcs",
        "phpcbf": "phpcbf",
        "phpstan": "phpstan analyse",
        "test": "phpunit",
        "test:unit": "phpunit --testsuite=unit",
        "test:integration": "phpunit --testsuite=integration",
        "check-all": [
            "@phpcs",
            "@phpstan",
            "@test"
        ]
    }
}
```

---

*Dokument erstellt: 2026-04-17 | Quality Expert | Version 1.1*
*Update 1.1: DSGVO/Compliance-Abschnitt 11 hinzugefuegt*
*Ergaenzt QUALITY_STANDARDS.md mit architekturspezifischen Details*
