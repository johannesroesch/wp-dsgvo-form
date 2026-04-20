# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Projekt-Übersicht

WordPress-Plugin für DSGVO-konforme Formulare mit verschlüsselter Datenspeicherung, konfigurierbare Admin-UI, Gutenberg-Block und eigenem CAPTCHA-Service.

## Tech Stack

- **PHP** — WordPress Plugin (Hauptlogik, REST-API, Admin-UI)
- **MySQL** — Custom Tables via `$wpdb` + WordPress DB-API
- **React/JSX** — Gutenberg Block (Editor-Integration)
- **webpack/npm** — Build-Pipeline für Gutenberg Block

## CAPTCHA-Integration

Service: `https://captcha.repaircafe-bruchsal.de`

**3-Schritt-Flow:**

1. **Widget → `/api/challenge`** — Challenge anfordern (PoW oder Textfrage)
2. **Widget → `/api/verify`** — Lösung einreichen, erhält `verification_token`
3. **Backend → `/api/validate`** — Server-to-Server Token-Validierung

**Frontend (lokales Bundling):**
```html
<!-- captcha.min.js ist lokal gebundelt unter public/js/captcha.min.js -->
<script src="{plugin_url}/public/js/captcha.min.js" integrity="{WPDSGVO_CAPTCHA_SRI}" crossorigin="anonymous"></script>
<captcha-widget form-id="dsgvo-{form_id}" server-url="https://captcha.repaircafe-bruchsal.de" lang="de" theme="auto"></captcha-widget>
```
`captcha.min.js` wird lokal gebundelt (DSGVO-Vorteil: IP-Übertragung nur bei POST /api/validate, nicht beim Script-Load). SRI-Hash wird automatisch beim Build generiert (`WPDSGVO_CAPTCHA_SRI`-Konstante). Das Widget erledigt Schritte 1+2 automatisch und befüllt ein `<input type="hidden" name="captcha_token">` mit dem `verification_token`.

**Backend-Validierung (Schritt 3 — Server-to-Server):**
```
POST https://captcha.repaircafe-bruchsal.de/api/validate
Headers: Content-Type: application/json
         Authorization: Bearer <api_key>
Body: { "verification_token": "<captcha_token from POST>" }
Response (200): { "valid": true, "form_id": "contact", "solved_at": "...", "stage_completed": 0 }
Response (422): { "valid": false, "error": "token expired" }
Response (401): { "error": "missing_api_key" }
```
API-Key wird in Plugin-Einstellungen als "Secret Key" konfiguriert (`wpdsgvo_captcha_secret`).
Immer server-seitig validieren — nie nur auf Client-seitiges Token vertrauen.

**Hinweis:** DSGVO-Konformität des CAPTCHA-Service wird durch den DPO geprüft.

## Build-Befehle

Ein vollständiger Build wird ausschließlich durch `devops-engineer` via Build-Script ausgeführt:

```sh
bin/build-release.sh --version X.Y.Z   # Produktions-Build + ZIP
bin/build-release.sh --version X.Y.Z --dry-run   # Vorschau ohne Änderungen
```

Das Script erledigt automatisch: Pre-flight Checks, Version-Bump (wp-dsgvo-form.php + readme.txt), npm install, npm run build, composer install --no-dev, ZIP-Erstellung (nur produktionsrelevante Dateien inkl. korrektem vendor/-Handling).

## Plugin-Architektur (Überblick)

Architektur-Details: `ARCHITECTURE.md` (vom Architekten erstellt)

**Schlüssel-Bereiche:**
- `wp-dsgvo-form.php` — Plugin-Einstiegspunkt, Hooks registrieren
- `includes/` — PHP-Klassen (Namespaces: `WpDsgvoForm\`)
- `includes/Admin/FormEditPage.php` — PHP-basierter Formular-Builder (Admin-UI)
- `includes/Admin/AdminBarNotification.php` — Admin Bar Notification für ungelesene Einsendungen
- `src/` — React-Quellcode für Gutenberg Block
- `public/js/captcha.min.js` — lokal gebundeltes CAPTCHA-Script (via Build generiert)
- `build/` — Kompilierte Block-Assets (nicht in Git)
- `tests/` — PHPUnit-Tests (nur Tester dürfen hier Änderungen vornehmen)

## Datenbank

Alle Custom Tables mit `$wpdb->prefix` (Standard: `wp_`):
- `wp_dsgvoform_forms` — Formular-Konfigurationen
- `wp_dsgvoform_fields` — Feld-Definitionen pro Formular
- `wp_dsgvoform_submissions` — Einsendungen (AES-256 verschlüsselt)
- `wp_dsgvoform_recipients` — Empfänger pro Formular

Schema-Details (inkl. Audit-Log-Tabelle): `ARCHITECTURE.md`

## WordPress-Rollen

Das Plugin registriert zwei Custom Roles (WP-typische Syntax: Plugin-Slug als Präfix, lowercase):

| Rolle | Slug | Zugriff |
|-------|------|---------|
| Einsendungs-Leser | `wp_dsgvo_form_reader` | Nur Einsendungen der eigenen zugewiesenen Formulare |
| Supervisor | `wp_dsgvo_form_supervisor` | Alle Einsendungen aller Formulare (mit Audit-Log) |

- Kein WP-Dashboard-Zugang für beide Rollen
- Login via Standard-WP-Login → Redirect direkt in Einsendungs-Viewer
- Supervisor-Rolle erfordert Audit-Logging jedes Zugriffs (DSGVO-Anforderung)

## Verschlüsselung

- Algorithmus: AES-256-CBC
- Kein Hardcoded Encryption-Key — Key wird beim Plugin-Aktivieren generiert und in WP Options gespeichert
- Encryption/Decryption ausschließlich in `EncryptionService`-Klasse

## Sicherheits-Regeln

- Alle DB-Abfragen: `$wpdb->prepare()` (Prepared Statements)
- Alle Ausgaben: `esc_html()`, `esc_attr()`, `wp_kses()` je nach Kontext
- Admin-Aktionen: WordPress Nonces (`wp_nonce_field`, `check_admin_referer`)
- REST-API: `permission_callback` mit Capability-Checks
- CAPTCHA: **immer** server-seitig verifizieren
- Kein direkter Dateizugriff: `defined('ABSPATH') || exit;` in jeder PHP-Datei
- `$_POST`/`$_GET`-Werte: **immer** `wp_unslash()` vor `sanitize_*()` — z.B. `sanitize_text_field(wp_unslash($_POST['field']))`
- i18n-Funktionen: Text-Domain **immer** als String-Literal — `__('Text', 'wp-dsgvo-form')` (nicht `self::TEXT_DOMAIN`)
- Exception-Messages: bei HTML-Ausgabe mit `esc_html()` escapen; in `error_log()` kein Escaping nötig

## Review-Pflichten

- **Peer-Review:** Jede Produktivcode-Änderung braucht Review durch einen anderen Entwickler. Zuweisung durch project-lead: freie Developer zuerst; nur wenn alle beschäftigt sind, wird der Ring (dev-1→2→3→4→1) angewendet.
- **Task-Abschluss:** Ein Developer schließt seinen Task ab sobald Peer-Review approved ist (und kein Security-Veto vorliegt). Er wartet **nicht** auf den Tester — der Tester testet asynchron danach.
- **Tester informieren:** Nach **jeder** Produktivcode-Änderung wird ein Tester informiert, damit er die entsprechenden Tests nachziehen kann. Koordination über project-lead. Der Tester arbeitet parallel/asynchron — er blockiert nicht den Developer.
- **Architekt & Experts informieren:** Über alle Änderungen müssen `architect` und alle Experts informiert werden. Sie können ihre Findings über `project-lead` in den Backlog einkippen.
- **Security-Veto:** Nur `security-expert` darf ein hartes Veto erteilen — entweder verbietet es die Änderung oder erzwingt einen unmittelbaren Fix. Ein Security-Veto ist ein Release-Blocker.
- **Expert-Review nach Build:** Nach jedem Build führen alle Experts + architect ein vollständiges Review des gesamten Projekts durch.
- **Datenschutz-Review:** Datenschutzrelevanter Code (Encryption, Datenlöschung, Rollenprüfungen) muss von `dpo` oder `security-expert` abgenommen werden
- **Release-Blocker:** Alle Findings von `security-expert`, `dpo` und `legal-expert` müssen vor Release umgesetzt sein

## Anforderungs-Dokumente

| Dokument | Erstellt von | Inhalt |
|----------|-------------|--------|
| `ARCHITECTURE.md` | architect | Vollständige Systemarchitektur |
| `SECURITY_REQUIREMENTS.md` | security-expert | Security & technische DSGVO-Maßnahmen |
| `QUALITY_STANDARDS.md` | quality-expert | Coding-Standards, Review-Checkliste |
| `PERFORMANCE_REQUIREMENTS.md` | performance-expert | DB-Indizes, Caching, Asset-Loading |
| `UX_CONCEPT.md` | ux-expert | Admin-UI, Formular-Builder, Viewer |
| `LEGAL_REQUIREMENTS.md` | legal-expert | Rechtsgrundlagen, Einwilligungstexte, Betroffenenrechte |
| `DATA_PROTECTION.md` | dpo | Privacy-by-Design, Verarbeitungsverzeichnis, Speicherfristen |

## Team-Struktur

| Rolle | Agent | Zuständigkeit |
|-------|-------|---------------|
| Team Lead | `team-lead` (Claude Code) | Status-Tracking, Kommunikation mit Auftraggeber und project-lead, kein Schreibrecht auf Code/Tests/Infra, **keine Analysen** — einziger Agent der Agents spawnen darf, nur mit expliziter Zustimmung des Auftraggebers |
| Project Lead | `wp-dsgvo-form-project-lead` | Koordination, Task-Verteilung, Projektsteuerung, sammelt gesamten Projektfortschritt, hält team-lead auf dem Laufenden — kein Schreibrecht auf Code/Tests/Infra, **keine Analysen** |
| Architekt | `wp-dsgvo-form-architect` | Alle Design-Entscheidungen (alleinige Gewalt) |
| Security Expert | `wp-dsgvo-form-security-expert` | Technische DSGVO, Verschlüsselung, XSS/CSRF, ISO 27001-Controls |
| Performance Expert | `wp-dsgvo-form-performance-expert` | DB-Optimierung, Caching, Lösch-Batch-Jobs |
| UX Expert | `wp-dsgvo-form-ux-expert` | Admin-UI, Formular-Builder-UX, Privacy-by-Design in UI |
| Quality Expert | `wp-dsgvo-form-quality-expert` | Coding-Standards, Code-Review, DSGVO-Checks in Reviews |
| Legal Expert | `wp-dsgvo-form-legal-expert` | Rechtsgrundlagen, Einwilligungstexte, Betroffenenrechte, Haftung |
| DPO | `wp-dsgvo-form-dpo` | DSGVO-Konformität, Privacy-by-Design, Verarbeitungsverzeichnis, CAPTCHA-Bewertung |
| Developer 1–4 | `wp-dsgvo-form-developer-1` bis `wp-dsgvo-form-developer-4` | Implementierung (nur sie dürfen Produktivcode ändern) |
| Tester 1–3 | `wp-dsgvo-form-tester-1` bis `wp-dsgvo-form-tester-3` | Tests (nur sie dürfen Tests bearbeiten); Aufteilung: tester-1 Admin-UI/Gutenberg, tester-2 Crypto/CAPTCHA, tester-3 Empfänger/Integration |
| DevOps Engineer | `wp-dsgvo-form-devops-engineer` | Infrastruktur (nur er darf composer.json, package.json etc. bearbeiten) — **nur er darf Builds erzeugen** (`npm run build`, `composer install` etc.) — **darf Commits und Tags eigenständig pushen** |
| Status Board | `wp-dsgvo-form-status-board` | Zeigt Kanban-Board aller offenen Tasks — wird von `project-lead` bei jeder Status-Änderung unverzüglich informiert |

**Namens-Konvention:** Alle Agents außer `team-lead` tragen den Prefix `wp-dsgvo-form-` (kein Generationssuffix wie `-2`).

**Schreibrechte:** Entwickler → Produktivcode · Tester → Tests · DevOps → Infrastruktur-Dateien

**Agent-Koordination:** Nur `team-lead` darf Agents spawnen — ausschließlich mit expliziter Zustimmung des Auftraggebers. `team-lead` kommuniziert mit dem Team **ausschließlich über `project-lead`** per SendMessage — auch wenn der Auftraggeber explizit bittet, dem Team etwas mitzuteilen. `project-lead` hat keine Schreibrechte auf Code, Tests oder Infrastruktur.

**Kommunikationsfluss:** Team-Mitglieder kommunizieren projektbezogene Themen ausschließlich mit `project-lead` — kein Direktkontakt zu `team-lead`. `project-lead` sammelt den gesamten Projektfortschritt, steuert das Projekt und hält `team-lead` auf dem Laufenden. Direktkontakt zum `team-lead` ist nur bei team-organisatorischen Themen erlaubt.

**Analyse-Verbot:** Weder `team-lead` noch `project-lead` führen technische Analysen durch (Code-Lesen, Root-Cause-Analyse, Schema-Prüfung etc.). Das ist ausschließlich Aufgabe der jeweiligen Team-Mitglieder (Developer, Tester, Experts, Architekt).
