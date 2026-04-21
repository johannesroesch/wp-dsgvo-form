# CI/CD Pipeline-Konzept — wp-dsgvo-form

> **Erstellt von:** devops-engineer  
> **Stand:** 2026-04-21  
> **Status:** KONSOLIDIERT — alle 6 Expert-Feedbacks eingearbeitet, zur Architect-Freigabe

---

## 1. Übersicht

Vollständige GitHub Actions CI/CD-Pipeline für das wp-dsgvo-form WordPress-Plugin. Deckt den gesamten Lifecycle ab: Lint → Static Analysis → Security Scanning → Tests → Build → Release.

### Pipeline-Architektur

```
┌──────────────────────────────────────────────────────────────────────┐
│                      GitHub Actions Workflows                        │
├──────────┬──────────┬──────────┬──────────┬──────────┬──────────────┤
│  CI      │  CodeQL  │  Semgrep │  Release │  Deps    │  Scheduled   │
│  (Push/  │  (Push/  │  (Push/  │  (Tag    │  Review  │  Security    │
│   PR)    │   PR)    │   PR)    │   Push)  │  (PR)    │  (Daily)     │
└──────────┴──────────┴──────────┴──────────┴──────────┴──────────────┘
```

### Trigger-Matrix

| Workflow | Push main | Pull Request | Tag v*.*.* | Schedule |
|----------|-----------|-------------|------------|----------|
| CI | ✅ | ✅ | ❌ | ❌ |
| CodeQL Security | ✅ | ✅ | ❌ | Mo 06:00 UTC |
| Semgrep SAST | ✅ | ✅ | ❌ | ❌ |
| Release | ❌ | ❌ | ✅ | ❌ |
| Dependency Review | ❌ | ✅ | ❌ | ❌ |
| Scheduled Security | ❌ | ❌ | ❌ | Täglich 03:00 UTC |

### Expert-Input-Quellen

| Expert | Input erhalten | Wichtigste Anforderungen |
|--------|---------------|--------------------------|
| Security | ✅ | gitleaks (MUSS), Semgrep (SOLL), SRI bei jedem Push, No-Key Guard |
| Quality | ✅ | WPCS+Security+PHPCompat, 80/90% Coverage, PHPCS=Fail, PHPStan ^2.0 |
| Performance | ✅ | ≤5 Min Pipeline, 500K ZIP-Budget (Warn), kein Bundle-Analyzer |
| DPO | ✅ | Kein PII (OK), SRI fail-closed (SOLL-F10), Test-Key-Präfix (SOLL-F09) |
| Legal | ✅ | Erweiterte Lizenz-Deny-Liste, Consent-Text-Regression-Check |
| UX | ✅ | Nur Stylelint Browser-Targets, kein Lighthouse/Percy |

---

## 2. CI Workflow (Haupt-Pipeline)

**Datei:** `.github/workflows/ci.yml`  
**Trigger:** Push auf `main`, Pull Requests  
**Budget:** ≤5 Minuten bei Cache-Hit (Performance-Expert)  
**Timeout:** 10 Minuten pro Job (Sicherheitsnetz)

### Job-Abhängigkeiten

```
Stufe 1 (parallel):
  lint-php ─────────┐
  lint-js ──────────┤
  lint-css ─────────┤
  phpstan ──────────┤
  secret-detection ─┤
  sri-verify ───────┤
  security-audit ───┤
  consent-check ────┘
                    │
Stufe 2 (nach Stufe 1):
                    ├──► tests-php (Matrix: 8.1–8.4, 8.5 allow_failure)
                    ├──► tests-js
                    │
Stufe 3 (nach Stufe 2):
                    └──► build ──► size-check ──► upload-artifact
```

### 2.1 Lint-Jobs (Stufe 1, parallel)

#### PHP Linting (PHPCS + WordPress Coding Standards)

```yaml
lint-php:
  runs-on: ubuntu-latest
  timeout-minutes: 10
  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php-version: '8.2'
        tools: cs2pr
    - uses: actions/cache@v4
      with:
        path: vendor
        key: composer-${{ hashFiles('composer.lock') }}
    - run: composer install --prefer-dist
    - run: vendor/bin/phpcs --report=checkstyle | cs2pr
```

**Tool:** [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer) mit WPCS  
**Regelset (Quality-Expert MUSS):**
- `WordPress-Extra` (inkl. WordPress-Core)
- `WordPress-Security` (XSS, SQL-Injection Patterns)
- `PHPCompatibility` + `PHPCompatibilityWP` (PHP 8.1+ Kompatibilität)

**Verhalten:** Errors = Pipeline-Fail, Warnings = sichtbar aber kein Fail (Quality-Expert MUSS)

**Custom Rules (Quality-Expert §3):**

| Metrik | Grenzwert |
|--------|----------|
| Funktionslänge | max. 50 Zeilen |
| Cyclomatic Complexity | max. 10 |
| Nesting Depth | max. 4 |
| Parameter Count | max. 5 |

#### JavaScript Linting

```yaml
lint-js:
  runs-on: ubuntu-latest
  timeout-minutes: 10
  steps:
    - uses: actions/checkout@v4
    - uses: actions/setup-node@v4
      with:
        node-version: '20'
        cache: 'npm'
    - run: npm ci
    - run: npm run lint:js
```

**Tool:** ESLint mit `@wordpress/eslint-plugin` (Quality-Expert MUSS)

#### CSS Linting (mit Browser-Targets)

```yaml
lint-css:
  runs-on: ubuntu-latest
  timeout-minutes: 10
  steps:
    - uses: actions/checkout@v4
    - uses: actions/setup-node@v4
      with:
        node-version: '20'
        cache: 'npm'
    - run: npm ci
    - run: npm run lint:css
```

**Tool:** Stylelint via `@wordpress/scripts lint-style`  
**Erweiterung (UX-Expert):** `stylelint-no-unsupported-browser-features` mit Targets: `> 1%, last 2 versions, not dead`

### 2.2 Static Analysis (Stufe 1, parallel)

#### PHPStan (Level 6 mit phpstan-wordpress)

```yaml
phpstan:
  runs-on: ubuntu-latest
  timeout-minutes: 10
  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php-version: '8.2'
    - uses: actions/cache@v4
      with:
        path: vendor
        key: composer-${{ hashFiles('composer.lock') }}
    - run: composer install --prefer-dist
    - run: composer analyze
```

**Tool:** [PHPStan](https://phpstan.org/) Level 6 mit `phpstan-wordpress` Extension  
**Hinweis (Quality-Expert MUSS):** PHPStan auf ^2.0 upgraden, `szepeviktor/phpstan-wordpress` hinzufügen  
**Baseline:** `phpstan-baseline.neon` für bestehende Findings

### 2.3 Security-Checks (Stufe 1, parallel)

#### Secret Detection (SEC-MUSS)

```yaml
secret-detection:
  runs-on: ubuntu-latest
  timeout-minutes: 10
  steps:
    - uses: actions/checkout@v4
      with:
        fetch-depth: 0
    - uses: gitleaks/gitleaks-action@v2
      env:
        GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

**Tool:** [gitleaks](https://github.com/gitleaks/gitleaks)  
**Konfiguration:** `.gitleaks.toml` mit Allowlist für `tests/` (Test-Keys als False Positives)  
**Required Status Check:** Ja (Security-Expert SEC-MUSS)

#### SRI-Verifikation (SEC-MUSS, DPO-SOLL-F10)

```yaml
sri-verify:
  runs-on: ubuntu-latest
  timeout-minutes: 10
  steps:
    - uses: actions/checkout@v4
    - name: Verify SRI hash integrity
      run: |
        EXPECTED=$(grep "WPDSGVO_CAPTCHA_SRI" wp-dsgvo-form.php | grep -oP "sha384-[A-Za-z0-9+/=]+")
        ACTUAL="sha384-$(openssl dgst -sha384 -binary public/js/captcha.min.js | openssl base64 -A)"
        if [ "$EXPECTED" != "$ACTUAL" ]; then
          echo "::error::SRI hash mismatch! Expected: $EXPECTED, Got: $ACTUAL"
          exit 1  # fail-closed (DPO-SOLL-F10)
        fi
        echo "SRI hash verified: $ACTUAL"
```

**Frequenz:** Bei jedem Push UND täglich (Security-Expert: Integritäts-Check, <1s Laufzeit)  
**Verhalten:** fail-closed bei Mismatch (DPO-SOLL-F10)

#### Dependency Audit im CI (SEC-MUSS)

```yaml
security-audit:
  runs-on: ubuntu-latest
  timeout-minutes: 10
  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php-version: '8.2'
    - uses: actions/setup-node@v4
      with:
        node-version: '20'
        cache: 'npm'
    - run: composer install --prefer-dist
    - run: composer audit
    - run: npm ci
    - run: npm audit --audit-level=moderate
```

**Begründung (Security-Expert):** Auch bei jedem Push/PR — nicht nur täglich. Fenster zwischen PR-Merge und nächstem Audit wäre zu groß.

#### No-Production-Key Guard (SEC-MUSS)

```yaml
no-key-guard:
  runs-on: ubuntu-latest
  timeout-minutes: 5
  steps:
    - name: Verify no production keys in CI
      run: |
        if [ -n "$DSGVO_FORM_ENCRYPTION_KEY" ]; then
          echo "::error::Production encryption key detected in CI environment!"
          exit 1
        fi
```

#### Consent-Text Regression Check (Legal-Expert SOLLTE)

```yaml
consent-check:
  runs-on: ubuntu-latest
  timeout-minutes: 10
  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php-version: '8.2'
    - name: Verify consent templates
      run: |
        # Prüft: Alle SUPPORTED_LOCALES haben ein Template
        # Prüft: Pflicht-Platzhalter {controller_name} und {controller_email} vorhanden
        # Prüft: SUPPORTED_LOCALES Keys == Template Keys (Sync)
        php -r "
          require_once 'vendor/autoload.php';
          \$locales = \WpDsgvoForm\Models\ConsentVersion::SUPPORTED_LOCALES;
          \$templates = \WpDsgvoForm\Models\ConsentTemplateHelper::TEMPLATES;
          \$errors = [];
          foreach (array_keys(\$locales) as \$key) {
            if (!isset(\$templates[\$key])) {
              \$errors[] = \"Missing template for locale: \$key\";
              continue;
            }
            if (strpos(\$templates[\$key], '{controller_name}') === false) {
              \$errors[] = \"Missing {controller_name} in \$key template\";
            }
            if (strpos(\$templates[\$key], '{controller_email}') === false) {
              \$errors[] = \"Missing {controller_email} in \$key template\";
            }
          }
          foreach (array_keys(\$templates) as \$key) {
            if (!isset(\$locales[\$key])) {
              \$errors[] = \"Template without matching locale: \$key\";
            }
          }
          if (\$errors) {
            foreach (\$errors as \$e) echo \"::error::\$e\n\";
            exit(1);
          }
          echo 'All consent templates valid (' . count(\$templates) . ' locales)';
        "
```

### 2.4 Tests (Stufe 2)

#### PHP Tests (PHPUnit, Matrix-Strategie)

```yaml
tests-php:
  needs: [lint-php, phpstan, secret-detection, sri-verify, security-audit]
  runs-on: ubuntu-latest
  timeout-minutes: 10
  strategy:
    fail-fast: false
    matrix:
      php-version: ['8.1', '8.2', '8.3', '8.4']
      include:
        - php-version: '8.5'
          experimental: true
  continue-on-error: ${{ matrix.experimental || false }}
  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php-version: ${{ matrix.php-version }}
        coverage: xdebug
    - uses: actions/cache@v4
      with:
        path: vendor
        key: composer-${{ hashFiles('composer.lock') }}
    - run: composer install --prefer-dist
    - run: composer test -- --coverage-clover=coverage.xml
    - uses: codecov/codecov-action@v4
      if: matrix.php-version == '8.2'
      with:
        files: coverage.xml
        flags: php
        token: ${{ secrets.CODECOV_TOKEN }}
```

**Test-Framework:** PHPUnit 10+ (aktuell 936 Tests, 2422 Assertions)  
**PHP-Matrix (Quality-Expert MUSS):** 8.1, 8.2, 8.3, 8.4 (Blocker) + 8.5 (allow_failure)  
**Coverage:** Upload nur von PHP 8.2 (eine Version reicht für Codecov)  
**Budget (Performance-Expert):** PHPUnit ≤2 Minuten

#### JavaScript Tests (Jest)

```yaml
tests-js:
  needs: [lint-js, lint-css]
  runs-on: ubuntu-latest
  timeout-minutes: 10
  steps:
    - uses: actions/checkout@v4
    - uses: actions/setup-node@v4
      with:
        node-version: '20'
        cache: 'npm'
    - run: npm ci
    - run: npm run test:js -- --coverage
    - uses: codecov/codecov-action@v4
      with:
        files: coverage/lcov.info
        flags: javascript
        token: ${{ secrets.CODECOV_TOKEN }}
```

### 2.5 Build-Verifikation (Stufe 3)

```yaml
build:
  needs: [tests-php, tests-js]
  runs-on: ubuntu-latest
  timeout-minutes: 10
  steps:
    - uses: actions/checkout@v4
    - uses: shivammathur/setup-php@v2
      with:
        php-version: '8.2'
    - uses: actions/setup-node@v4
      with:
        node-version: '20'
        cache: 'npm'
    - run: npm ci && npm run build
    - run: composer install --no-dev --optimize-autoloader
    - name: Verify build artifacts
      run: |
        test -d build/ || exit 1
        test -f build/index.js || exit 1
        echo "Build artifacts verified"
    - name: Size budget check (Performance-Expert)
      run: |
        # Kein Hard-Fail, nur Warnung bei >500K
        ZIP_SIZE=$(du -sb build/ | cut -f1)
        echo "Build size: ${ZIP_SIZE} bytes"
        if [ "$ZIP_SIZE" -gt 512000 ]; then
          echo "::warning::Build artifacts exceed 500K budget (${ZIP_SIZE} bytes)"
        fi
    - uses: actions/upload-artifact@v4
      with:
        name: build-artifacts
        path: build/
        retention-days: 90
```

**Artefakt-Aufbewahrung:** 90 Tage (Legal-Expert SOLLTE)

---

## 3. Security Workflows

### 3.1 CodeQL Analysis (GitHub-nativ)

**Datei:** `.github/workflows/codeql.yml`

```yaml
name: CodeQL Security Analysis
on:
  push:
    branches: [main]
  pull_request:
    branches: [main]
  schedule:
    - cron: '0 6 * * 1'  # Montags 06:00 UTC

jobs:
  analyze-js:
    name: CodeQL (JavaScript)
    runs-on: ubuntu-latest
    timeout-minutes: 10
    permissions:
      security-events: write
    steps:
      - uses: actions/checkout@v4
      - uses: github/codeql-action/init@v3
        with:
          languages: javascript
          queries: security-extended
      - uses: github/codeql-action/analyze@v3

  analyze-php:
    name: CodeQL (PHP — informativ)
    runs-on: ubuntu-latest
    timeout-minutes: 10
    permissions:
      security-events: write
    # Nicht als Required Check — experimenteller PHP-Support (Security-Expert)
    continue-on-error: true
    steps:
      - uses: actions/checkout@v4
      - uses: github/codeql-action/init@v3
        with:
          languages: php
          queries: security-extended
      - uses: github/codeql-action/analyze@v3
```

**Security-Expert Entscheidung:**
- **JS: Required** (production-ready, starke OWASP-Abdeckung)
- **PHP: Informativ** (experimentell, nicht blocking, aber Findings wertvoll)

### 3.2 Semgrep SAST (SEC-SOLL)

**Datei:** `.github/workflows/semgrep.yml`

```yaml
name: Semgrep PHP SAST
on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  semgrep:
    runs-on: ubuntu-latest
    timeout-minutes: 10
    container:
      image: semgrep/semgrep
    steps:
      - uses: actions/checkout@v4
      - run: semgrep scan --config p/wordpress --config p/php-security-audit --error
```

**Tool:** [Semgrep](https://semgrep.dev/) mit WordPress- und PHP-Security-Rulesets  
**Begründung (Security-Expert):** Erstklassiger PHP-Support, erkennt fehlende `esc_html()`, unsichere `$wpdb`-Queries ohne `prepare()`  
**Kosten:** Kostenlos (Open Source)

### 3.3 Secret Detection (gitleaks)

**Datei:** In CI-Workflow integriert (siehe §2.3)  
**Konfiguration:** `.gitleaks.toml`

```toml
title = "wp-dsgvo-form gitleaks config"

[allowlist]
  paths = ["tests/"]
  description = "Test fixtures with mock encryption keys (DPO-SOLL-F09)"
```

### 3.4 Dependency Review (PR-only)

**Datei:** `.github/workflows/dependency-review.yml`

```yaml
name: Dependency Review
on: pull_request

jobs:
  dependency-review:
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - uses: actions/checkout@v4
      - uses: actions/dependency-review-action@v4
        with:
          fail-on-severity: moderate
          deny-licenses: >-
            AGPL-3.0-only,
            AGPL-3.0-or-later,
            AGPL-1.0-only,
            SSPL-1.0,
            EUPL-1.1,
            EUPL-1.2,
            OSL-3.0,
            RPL-1.1,
            RPL-1.5
          allow-licenses: >-
            MIT,
            BSD-2-Clause,
            BSD-3-Clause,
            Apache-2.0,
            ISC,
            GPL-2.0-only,
            GPL-2.0-or-later,
            GPL-3.0-only,
            GPL-3.0-or-later,
            LGPL-2.1-only,
            LGPL-2.1-or-later,
            MPL-2.0,
            CC0-1.0,
            Unlicense
          comment-summary-in-pr: always
```

**Lizenz-Policy (Legal-Expert):**
- GPL-3.0 **nicht** pauschal blocken (WordPress-Plugin ist GPL-2.0-or-later, PHP-Dependencies unter GPL-3.0 sind kompatibel)
- AGPL, SSPL, OSL, RPL, EUPL blockiert (Copyleft/Network-Clause-Risiken)

### 3.5 Scheduled Security Audit

**Datei:** `.github/workflows/security-audit.yml`

```yaml
name: Daily Security Audit
on:
  schedule:
    - cron: '0 3 * * *'

jobs:
  composer-audit:
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - run: composer install --prefer-dist
      - run: composer audit

  npm-audit:
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm ci
      - run: npm audit --audit-level=moderate

  sri-verify:
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - uses: actions/checkout@v4
      - name: Verify SRI hash integrity
        run: |
          EXPECTED=$(grep "WPDSGVO_CAPTCHA_SRI" wp-dsgvo-form.php | grep -oP "sha384-[A-Za-z0-9+/=]+")
          ACTUAL="sha384-$(openssl dgst -sha384 -binary public/js/captcha.min.js | openssl base64 -A)"
          if [ "$EXPECTED" != "$ACTUAL" ]; then
            echo "::error::SRI hash mismatch!"
            exit 1
          fi
          echo "SRI hash verified: $ACTUAL"
```

---

## 4. Release Workflow

**Datei:** `.github/workflows/release.yml`  
**Trigger:** Tag-Push (`v*.*.*`)

```yaml
name: Release
on:
  push:
    tags: ['v*.*.*']

jobs:
  release:
    runs-on: ubuntu-latest
    timeout-minutes: 15
    permissions:
      contents: write
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Extract version from tag
        id: version
        run: echo "version=${GITHUB_REF_NAME#v}" >> $GITHUB_OUTPUT

      - name: Build release
        run: bin/build-release.sh --version ${{ steps.version.outputs.version }} --skip-install

      - name: Verify ZIP
        run: |
          test -f wp-dsgvo-form.zip || exit 1
          echo "ZIP size: $(du -h wp-dsgvo-form.zip | cut -f1)"
          echo "Files: $(unzip -l wp-dsgvo-form.zip | tail -1)"

      - name: Generate SHA256 checksum (SEC-SOLL)
        run: |
          sha256sum wp-dsgvo-form.zip > wp-dsgvo-form.zip.sha256
          echo "Checksum: $(cat wp-dsgvo-form.zip.sha256)"

      - name: Generate changelog
        run: |
          PREV_TAG=$(git tag --sort=-version:refname | head -2 | tail -1)
          echo "## Changes since ${PREV_TAG}" > CHANGELOG.md
          git log ${PREV_TAG}..HEAD --pretty=format:"- %s" >> CHANGELOG.md

      - name: Create GitHub Release
        uses: softprops/action-gh-release@v2
        with:
          files: |
            wp-dsgvo-form.zip
            wp-dsgvo-form.zip.sha256
          body_path: CHANGELOG.md
          draft: false
          prerelease: ${{ contains(github.ref_name, 'beta') || contains(github.ref_name, 'rc') }}
          generate_release_notes: true
```

**Ergänzungen:**
- SHA256-Checksumme als Release-Asset (Security-Expert SEC-SOLL)
- Release-Artefakte unbegrenzt aufbewahrt (Legal-Expert — Nachweis welcher Code wann produktiv war)
- Sigstore/cosign zurückgestellt auf v2.x (Security-Expert SEC-KANN)

---

## 5. Zusätzliche Workflows

### 5.1 WordPress Plugin Checker (PR-only, informativ)

```yaml
wp-plugin-check:
  runs-on: ubuntu-latest
  timeout-minutes: 10
  # Nicht als Required Check (Security-Expert Empfehlung)
  continue-on-error: true
  steps:
    - uses: actions/checkout@v4
    - uses: WordPress/plugin-check-action@v1
      with:
        build-dir: '.'
        wp-version: 'latest'
        categories: |
          plugin_repo
          security
```

### 5.2 Stale PR Cleanup

```yaml
name: Stale
on:
  schedule:
    - cron: '0 0 * * 0'

jobs:
  stale:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/stale@v9
        with:
          days-before-stale: 30
          days-before-close: 7
          stale-pr-message: 'Dieser PR ist seit 30 Tagen inaktiv.'
```

---

## 6. Tool-Übersicht (konsolidiert)

| Tool | Zweck | Kosten | Status | Quelle |
|------|-------|--------|--------|--------|
| **GitHub Actions** | CI/CD-Runner | Kostenlos (2000 Min/Monat) | MUSS | DevOps |
| **PHP_CodeSniffer + WPCS** | PHP Linting + Security | Kostenlos | MUSS | Quality |
| **PHPCompatibility** | PHP-Versions-Kompatibilität | Kostenlos | MUSS | Quality |
| **PHPStan ^2.0** | Static Analysis PHP Level 6 | Kostenlos | MUSS | Quality |
| **phpstan-wordpress** | WordPress-spezifische Typen | Kostenlos | MUSS | Quality |
| **ESLint** | JS Linting | Kostenlos | MUSS | Quality |
| **@wordpress/eslint-plugin** | WordPress JS Standards | Kostenlos | MUSS | Quality |
| **Stylelint** | CSS Linting + Browser-Targets | Kostenlos | MUSS | UX |
| **PHPUnit** | PHP Tests (936 Tests) | Kostenlos | MUSS | Quality |
| **Jest** | JS Tests | Kostenlos | MUSS | Quality |
| **Codecov** | Coverage 80%/90% | Kostenlos (OSS) | MUSS | Quality |
| **gitleaks** | Secret Detection | Kostenlos | MUSS | Security |
| **GitHub CodeQL** | SAST (JS required, PHP informativ) | Kostenlos | MUSS/SOLL | Security |
| **Semgrep** | PHP SAST (WordPress Rules) | Kostenlos | SOLL | Security |
| **Dependency Review** | CVE + Lizenz-Prüfung | Kostenlos | MUSS | Security+Legal |
| **composer/npm audit** | Vulnerability Scan | Kostenlos | MUSS | Security |
| **WP Plugin Check** | WordPress.org Standards | Kostenlos | KANN | DevOps |
| **softprops/gh-release** | GitHub Release + SHA256 | Kostenlos | MUSS | DevOps+Security |
| **actions/stale** | PR Cleanup | Kostenlos | KANN | DevOps |

**Nicht integriert (mit Begründung):**
- ~~SonarCloud~~ — PHPCS + PHPStan decken Code-Smells ab (Quality-Expert: Phase 1 nicht nötig)
- ~~Snyk~~ — composer/npm audit + Dependency Review reichen (Security-Expert)
- ~~FOSSA~~ — Dependency Review reicht aktuell (Legal-Expert)
- ~~Lighthouse/axe-core~~ — Braucht volle WP-Instanz, Overkill (UX-Expert)
- ~~Percy/Chromatic~~ — Falsch-Positive bei WP-Updates, CSS zu schlank (UX-Expert)
- ~~webpack-bundle-analyzer~~ — Nur 1 kleiner Gutenberg-Block, ZIP-Size reicht (Performance-Expert)
- ~~Sigstore/cosign~~ — Zurückgestellt auf v2.x (Security-Expert)

---

## 7. Neue Konfigurationsdateien

### 7.1 `phpcs.xml` (Quality-Expert MUSS)

```xml
<?xml version="1.0"?>
<ruleset name="WP DSGVO Form">
    <description>Coding standards for wp-dsgvo-form</description>

    <file>./includes</file>
    <file>./wp-dsgvo-form.php</file>
    <file>./uninstall.php</file>

    <exclude-pattern>vendor/*</exclude-pattern>
    <exclude-pattern>node_modules/*</exclude-pattern>
    <exclude-pattern>build/*</exclude-pattern>
    <exclude-pattern>tests/*</exclude-pattern>

    <arg name="extensions" value="php"/>
    <arg name="colors"/>
    <arg value="sp"/>

    <!-- WordPress Standards (Quality-Expert MUSS) -->
    <rule ref="WordPress-Extra"/>
    <rule ref="WordPress-Security"/>

    <!-- PHP Compatibility (Quality-Expert MUSS) -->
    <rule ref="PHPCompatibilityWP"/>
    <config name="testVersion" value="8.1-"/>
    <config name="minimum_wp_version" value="6.0"/>

    <!-- Text Domain -->
    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array">
                <element value="wp-dsgvo-form"/>
            </property>
        </properties>
    </rule>

    <!-- Custom Complexity Rules (Quality-Expert §3) -->
    <rule ref="Generic.Metrics.CyclomaticComplexity">
        <properties>
            <property name="complexity" value="10"/>
            <property name="absoluteComplexity" value="15"/>
        </properties>
    </rule>
    <rule ref="Generic.Metrics.NestingLevel">
        <properties>
            <property name="nestingLevel" value="4"/>
            <property name="absoluteNestingLevel" value="6"/>
        </properties>
    </rule>
</ruleset>
```

### 7.2 `phpstan.neon` (Quality-Expert MUSS)

```neon
includes:
    - vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
    level: 6
    paths:
        - includes/
    excludePaths:
        - vendor/
    checkMissingIterableValueType: false
    reportUnmatchedIgnoredErrors: false
```

**Dependency (composer.json):**
```json
"require-dev": {
    "phpstan/phpstan": "^2.0",
    "szepeviktor/phpstan-wordpress": "^2.0",
    "phpcompatibility/phpcompatibility-wp": "^2.0"
}
```

### 7.3 `codecov.yml` (Quality-Expert MUSS)

```yaml
coverage:
  status:
    project:
      default:
        target: 80%
        threshold: 2%
    patch:
      default:
        target: 90%
comment:
  layout: "header, diff, flags, components"
  require_changes: true
```

### 7.4 `.gitleaks.toml` (Security-Expert SEC-MUSS)

```toml
title = "wp-dsgvo-form gitleaks config"

[allowlist]
  paths = ["tests/"]
  description = "Test fixtures with mock encryption keys (DPO-SOLL-F09)"
```

### 7.5 `.browserslistrc` (UX-Expert)

```
> 1%
last 2 versions
not dead
```

---

## 8. Secrets & Repository-Einstellungen

### Benötigte GitHub Secrets

| Secret | Zweck | Quelle |
|--------|-------|--------|
| `CODECOV_TOKEN` | Coverage Upload | [codecov.io](https://codecov.io/) |
| `GITHUB_TOKEN` | gitleaks, Dependency Review | Automatisch (GitHub) |

**SEC-MUSS:** Kein `DSGVO_FORM_ENCRYPTION_KEY` als GitHub Secret anlegen. Es gibt keinen Grund, den Produktions-KEK in CI zu haben.

### Branch Protection Rules

**Required Status Checks (SEC-MUSS):**

| Check | Begründung | Quelle |
|-------|------------|--------|
| `lint-php` | WPCS-Compliance + Security-Regeln | Quality + Security |
| `lint-js` | JS-Standards | Quality |
| `tests-php (8.2)` | Mindestens eine PHP-Version clean | Security |
| `build` | Build-Integrität | DevOps |
| `CodeQL (javascript)` | OWASP-Frontend-Abdeckung | Security |
| `secret-detection` | Kein Key-Leak darf gemerged werden | Security |
| `dependency-review` | Keine verwundbaren neuen Deps | Security |

**Empfohlene Status Checks (SEC-SOLL):**

| Check | Begründung |
|-------|------------|
| `semgrep` | WordPress-spezifische PHP SAST |
| `phpstan` | Typsicherheit fängt Injection-Vektoren |
| `sri-verify` | CAPTCHA-Script-Integrität |
| `security-audit` | Keine verwundbaren Deps in PR |

**Branch Settings:**
```
main:
  ✅ Require PR reviews (1 reviewer)
  ✅ Require status checks (7 required, 4 recommended)
  ✅ Require up-to-date branches
  ✅ No force push
  ✅ No deletion
```

---

## 9. DSGVO-spezifische Pipeline-Aspekte

| Aspekt | Maßnahme | Pipeline-Stufe | Expert |
|--------|----------|----------------|--------|
| Kein PII in CI-Logs | Tests nutzen Mocks, keine echte DB | Alle Jobs | DPO ✅ |
| SRI-Integrität | fail-closed bei Hash-Mismatch | CI + Daily | DPO-SOLL-F10 |
| Test-Key-Präfix | Test-Keys in `tests/` kenntlich | gitleaks Allowlist | DPO-SOLL-F09 |
| No-Production-Key | Guard verhindert echte Keys in CI | CI | SEC-MUSS |
| Encryption-Integrität | PHPUnit-Tests prüfen AES-256-CBC | Tests | Security |
| Dependency-Sicherheit | Audit bei jedem Push + täglich | CI + Daily | SEC-MUSS |
| Secret-Detection | gitleaks scannt Git-History | CI | SEC-MUSS |
| Code-Injection-Schutz | CodeQL + Semgrep | CI + Weekly | Security |
| Lizenz-Compliance | Erweiterte Deny-Liste (AGPL, SSPL, OSL, RPL, EUPL) | PR | Legal |
| Consent-Text-Regression | Locale-Sync + Platzhalter-Check | CI | Legal |
| Artefakt-Aufbewahrung | 90d CI, unbegrenzt Releases | All | Legal |

---

## 10. Geschätztes GitHub Actions Budget

| Workflow | Trigger | Laufzeit (ca.) | Monatliche Nutzung |
|----------|---------|----------------|---------------------|
| CI (5 PHP + JS + Security) | ~20 Pushes/PRs | ~10 Min | ~200 Min |
| CodeQL (JS + PHP) | ~20 Pushes + 4 Scheduled | ~6 Min | ~144 Min |
| Semgrep | ~20 Pushes/PRs | ~2 Min | ~40 Min |
| Dependency Review | ~10 PRs | ~1 Min | ~10 Min |
| Security Audit (täglich) | 30 täglich | ~2 Min | ~60 Min |
| Release | ~2 Tags | ~5 Min | ~10 Min |
| WP Plugin Check | ~10 PRs | ~3 Min | ~30 Min |
| **Gesamt** | | | **~494 Min** (von 2000 frei) |

---

## 11. Implementierungs-Reihenfolge

| Phase | Dateien | Aufwand | Dependencies |
|-------|---------|---------|-------------|
| **Phase 1:** Infrastruktur | `phpcs.xml`, `phpstan.neon`, `.gitleaks.toml`, `.browserslistrc`, `codecov.yml` | ~1h | composer.json Updates (PHPStan ^2.0, PHPCompat) |
| **Phase 2:** CI Workflow | `.github/workflows/ci.yml` | ~2h | Phase 1 |
| **Phase 3:** Security Workflows | `codeql.yml`, `semgrep.yml`, `dependency-review.yml`, `security-audit.yml` | ~1.5h | - |
| **Phase 4:** Release Workflow | `.github/workflows/release.yml` | ~1h | - |
| **Phase 5:** Extras | WP Plugin Check, Stale | ~30min | - |
| **Phase 6:** Branch Protection | GitHub Repository Settings | ~30min | Phase 2+3 |

**Gesamtaufwand:** ~6.5h

---

## 12. Entscheidungen für Architect

| Entscheidung | Optionen | Empfehlung |
|-------------|----------|-----------|
| WP Plugin Check als Required? | Required / Informativ | Informativ (Security-Expert) |
| SonarCloud in Phase 2? | Ja / Nein | Nein (Quality: PHPCS+PHPStan reichen) |
| Sigstore für v2.x? | Ja / Nein | Ja, evaluieren wenn öffentliche Distribution |
| Branch Protection sofort oder nach Stabilisierung? | Sofort / Nach 2 Wochen | Nach 2 Wochen Testphase |

---

*Dieses Konzept wurde mit Input aller 6 Experts konsolidiert und ist bereit zur Architect-Freigabe.*
