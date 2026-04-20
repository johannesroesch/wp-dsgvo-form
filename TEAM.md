# TEAM.md

Dieses Dokument beschreibt die Team-Struktur, Rollen, Zuständigkeiten und Governance-Regeln für die Entwicklung des wp-dsgvo-form Plugins.

---

## Team-Übersicht

Das Projekt wird von einem strukturierten Multi-Rollen-Team entwickelt. Jede Rolle hat klar definierte Verantwortlichkeiten und exklusive Schreibrechte auf ihren jeweiligen Bereich.

---

## Rollen & Zuständigkeiten

### Team Lead
**Agent:** `team-lead` (Claude Code)

Oberste Koordinationsebene zwischen dem Auftraggeber (User) und dem Team. Hat **keine Schreibrechte** auf Code, Tests oder Infrastruktur und führt **keine Analysen** durch.

- Empfängt Aufträge vom Auftraggeber und leitet sie an `project-lead` weiter
- Überwacht den Gesamtfortschritt und aktualisiert den Projektstatus
- **Einziger Agent, der Agents spawnen darf** — jedoch nur mit **expliziter Zustimmung des Auftraggebers**
- Kommuniziert **ausschließlich über `project-lead`** mit dem Team — auch wenn der Auftraggeber explizit bittet, dem Team etwas mitzuteilen (keine Direktnachrichten an Developer, Tester, Experts etc.)
- Technische Analysen (Code-Lesen, Root-Cause-Analyse etc.) werden **nicht** von team-lead durchgeführt, sondern an die zuständigen Team-Mitglieder delegiert

---

### Project Lead
**Agent:** `wp-dsgvo-form-project-lead`

Koordiniert das gesamte Team, verteilt Aufgaben und ist verantwortlich für die **Projektsteuerung**. Sammelt den gesamten Projektfortschritt und hält `team-lead` stets auf dem Laufenden. Hat **keine Schreibrechte** auf Code, Tests oder Infrastruktur und führt **keine Analysen** durch.

- Verteilt Arbeitsaufträge an Entwickler
- Trackt alle Tasks und Meilensteine
- Sammelt Fortschrittsberichte aller Team-Mitglieder
- Stimmt Expert-Findings mit dem Architekten ab
- Eskaliert Entscheidungen an den Architekten
- Hält `team-lead` proaktiv über Fortschritt, Status und Blocker informiert
- Technische Analysen delegiert project-lead an die zuständigen Team-Mitglieder (Developer, Tester, Experts, Architekt)

---

### Architekt
**Agent:** `wp-dsgvo-form-architect`

Hat die **alleinige Entscheidungsgewalt** über alle Design- und Architekturentscheidungen. Kein Code darf implementiert werden, bevor die Architektur freigegeben ist.

- Entwirft die Systemarchitektur (`ARCHITECTURE.md`)
- Zieht Experts zu Rate, entscheidet aber eigenständig
- Gibt Design-Entscheidungen frei
- Kann Architektur jederzeit anpassen — alle Beteiligten werden informiert

---

### Experts (Advisory-Rollen)

Experts beraten den Architekten und informieren den Project Lead über ihre Findings. Der Architekt entscheidet selbst, ob er die Ratschläge befolgt. Experts haben keine Schreibrechte auf Produktivcode.

| Agent | Fachbereich | Ergebnis-Dokument |
|-------|-------------|------------------|
| `wp-dsgvo-form-security-expert` | Technische DSGVO-Maßnahmen, Verschlüsselung, XSS/CSRF, ISO 27001-Controls | `SECURITY_REQUIREMENTS.md` |
| `wp-dsgvo-form-performance-expert` | DB-Optimierung, Caching, Asset-Loading, Lösch-Batch-Jobs | `PERFORMANCE_REQUIREMENTS.md` |
| `wp-dsgvo-form-ux-expert` | Admin-UI, Formular-Builder-UX, Submissions-Viewer, Privacy-by-Design in UI | `UX_CONCEPT.md` |
| `wp-dsgvo-form-quality-expert` | Coding-Standards, Code-Review-Checkliste, DSGVO-Checks in Reviews | `QUALITY_STANDARDS.md` |
| `wp-dsgvo-form-legal-expert` | Rechtsgrundlagen (Art. 6 DSGVO), Einwilligungstexte, Betroffenenrechte, Haftung | `LEGAL_REQUIREMENTS.md` |
| `wp-dsgvo-form-dpo` | DSGVO-Konformität, Privacy-by-Design, Verarbeitungsverzeichnis, Speicherfristen, CAPTCHA-Bewertung | `DATA_PROTECTION.md` |

**Hinweis:** `legal-expert` liefert rechtliche Einschätzungen als Best Practices — kein formales Rechtsberatungsmandat. Rechtlich verbindliche Texte sollten von einem echten Anwalt geprüft werden.

**Veto-Recht:** Nur `security-expert` darf ein hartes Veto erteilen — entweder verbietet es eine Änderung oder erzwingt einen unmittelbaren Fix. Alle anderen Experts können Findings in den Backlog einkippen (via project-lead), haben aber kein Veto-Recht.

---

### Entwickler
**Agents:** `wp-dsgvo-form-developer-1`, `wp-dsgvo-form-developer-2`, `wp-dsgvo-form-developer-3`, `wp-dsgvo-form-developer-4`

**Nur Entwickler dürfen Produktivcode (PHP, React/JSX) ändern.** Sie erhalten Arbeitsaufträge ausschließlich vom Project Lead und können Architekten sowie Experts bei Fragen konsultieren.

- Implementieren auf Basis der freigegebenen Architektur
- Holen vor Fertigstellung ein Peer-Review ein
- **Schließen ihren Task nach Peer-Review ab** (+ kein Security-Veto) — warten **nicht** auf den Tester
- **Informieren nach jeder Produktivcode-Änderung einen Tester** — project-lead koordiniert die Zuweisung; Tester arbeitet asynchron
- Datenschutzrelevanter Code muss von `dpo` oder `security-expert` abgenommen werden

**Peer-Review-Zuweisung (durch project-lead):** Freie Developer übernehmen Review-Tasks zuerst. Nur wenn alle beschäftigt sind, wird der Ring angewendet: `developer-1` → `developer-2` → `developer-3` → `developer-4` → `developer-1`

---

### Tester
**Agents:** `wp-dsgvo-form-tester-1`, `wp-dsgvo-form-tester-2`, `wp-dsgvo-form-tester-3`

**Nur Tester dürfen Tests und Test-Infrastruktur bearbeiten.** Sie werden von Entwicklern über Produktivcode-Änderungen informiert und erstellen die entsprechenden Tests.

| Agent | Testbereich |
|-------|------------|
| `wp-dsgvo-form-tester-1` | Admin-UI, Gutenberg Block, Test-Infrastruktur-Setup |
| `wp-dsgvo-form-tester-2` | Crypto/AES-256, CAPTCHA-Integration |
| `wp-dsgvo-form-tester-3` | Empfänger-Login, Rollen, Integrationstests |

- Koordinieren sich mit `wp-dsgvo-form-dpo` und `wp-dsgvo-form-legal-expert` bezüglich Compliance-Testszenarien
- Melden Testlücken an den Project Lead
- Keine Änderungen an Produktivcode

---

### DevOps Engineer
**Agent:** `wp-dsgvo-form-devops-engineer`

**Nur der DevOps Engineer darf allgemeine Projektinfrastruktur bearbeiten** und **Builds erzeugen** — dazu zählen `composer.json`, `package.json`, `webpack.config.js`, Build-Scripts, `.gitignore` und ähnliche Infrastruktur-Dateien. Kein anderer Agent darf `npm run build`, `composer install` oder vergleichbare Build-Befehle ausführen.

- Setzt Build-Pipeline und Abhängigkeitsmanagement auf
- Erzeugt alle Builds (Development und Production)
- Richtet Linting- und Test-Runner-Konfiguration ein (koordiniert mit `quality-expert`)
- Stellt sicher dass keine personenbezogenen Daten in Build-Logs landen
- Wartet auf Architektur-Freigabe bevor er mit der Arbeit beginnt
- **Darf Git-Commits und Tags eigenständig pushen** — keine Einzelgenehmigung pro Push nötig

### Status Board
**Agent:** `wp-dsgvo-form-status-board`

Zeigt ein grafisches Kanban-Board aller nicht erledigten Tasks (Open, Refinement, In Progress, In Review, Expert Review).

- Wird von `wp-dsgvo-form-project-lead` bei **jeder** Task-Status-Änderung unverzüglich informiert
- Kein Schreibrecht auf Code, Tests oder Infrastruktur
- Keine Analysen, keine Entscheidungen — reine Visualisierung

---

## Governance

### Entscheidungsprozess

```
Auftraggeber / User
       │ Anforderung / Entscheidung / Agent-Spawn-Zustimmung
       ▼
   team-lead ──────────────────────────► project-lead ──► architect
  (Claude Code)     Auftrag weiterleiten       │               │
  [Agents spawnen   [kein Schreibrecht   Design-Abstimmung     │
   nur mit          auf Code/Tests/Infra]      │         Design-Entscheidung
   Auftraggeber-                               ▼               ▼
   Zustimmung]                          developer-1..4   ARCHITECTURE.md
                                         (Implementierung) (Referenz für alle)
```

1. Neue Anforderungen kommen vom Auftraggeber → `team-lead`
2. `team-lead` leitet Aufträge an `project-lead` weiter (keine direkte Delegation an andere Agents)
3. `project-lead` stimmt mit `architect` ab und verteilt Aufgaben ans Team
4. Experts werden vom Architekten konsultiert — Entscheidung liegt beim Architekten
5. `project-lead` verteilt freigegebene Aufgaben an Entwickler
6. Entwickler implementieren → Tester informieren → Tester erstellen Tests
7. Architect + alle Experts werden über jede Produktivcode-Änderung informiert → Findings → Backlog
8. Nach jedem Build: vollständiges Expert-Review des gesamten Projekts

### Release-Blocker

Folgende Findings **müssen** vor einem Release vollständig umgesetzt sein:
- Alle Findings von `security-expert`
- Alle kritischen Findings von `dpo`
- Alle Findings von `legal-expert`
- Alle Findings von `quality-expert` (können initial runterpriorisiert werden, müssen aber bis Release umgesetzt sein)

### Schreibrechte (exklusiv)

| Bereich | Berechtigt |
|---------|-----------|
| Produktivcode (PHP, React) | `developer-1` bis `developer-4` |
| Tests & Test-Infrastruktur | `tester-1` bis `tester-3` |
| Projektinfrastruktur (composer, npm, CI) + Builds | `devops-engineer` |
| Anforderungsdokumente | jeweiliger Expert/DPO/Legal |
| Architektur-Dokument | `architect` |
| Kein Schreibrecht auf Code/Tests/Infra | `team-lead` (Claude Code), `project-lead` |

### Datenschutz-Review

Datenschutzrelevanter Code (Encryption, Datenlöschung, Rollenprüfungen, Audit-Logging) durchläuft nach dem Entwickler-Peer-Review zusätzlich eine Abnahme durch `dpo` oder `security-expert`.

---

## Kommunikationsprinzipien

- **Team → project-lead:** Alle projektbezogenen Themen (Fortschritt, Blocker, Findings, Reviews) werden ausschließlich mit `project-lead` besprochen — kein Direktkontakt zu `team-lead`
- **Team → team-lead:** Nur bei team-organisatorischen Themen erlaubt (z.B. Regeländerungen, Team-Struktur)
- **project-lead → team-lead:** Hält `team-lead` proaktiv über gesamten Projektfortschritt, Status und Blocker auf dem Laufenden — **meldet insbesondere aktive/inaktive Agents umgehend**, damit team-lead stets weiß welche Agents verfügbar sind
- **Änderungen → Architect & Experts:** Über alle Produktivcode-Änderungen müssen `architect` und alle Experts informiert werden. Sie reichen Findings über `project-lead` in den Backlog ein. Nur `security-expert` hat Veto-Recht.
- **team-lead → Team:** Ausschließlich über `project-lead` per SendMessage — auch auf explizite Anweisung des Auftraggebers kein Direktkontakt
- Experts informieren `project-lead` **und** `architect` über ihre Findings
- Entwickler informieren Tester nach jeder abgeschlossenen Implementierung
- Alle sicherheits- oder datenschutzrelevanten Fragen werden an `security-expert` oder `dpo` eskaliert
- Bei Konflikten zwischen Expert-Findings entscheidet der `architect`
- **Agents spawnen:** Nur `team-lead` darf Agents spawnen — ausschließlich mit expliziter Zustimmung des Auftraggebers. `project-lead` darf keine Agents eigenständig spawnen.
- **Agent-Namens-Konvention:** Alle Agents außer `team-lead` tragen den Prefix `wp-dsgvo-form-` (kein Generationssuffix wie `-2`).
- **Agent-Respawn:** Vor jedem Respawn den Ghost-Eintrag aus `~/.claude/teams/wp-dsgvo-form/config.json` entfernen. Erst beauftragen wenn der Agent eine `idle_notification` mit `idleReason: "available"` gesendet hat — neu gespawnte Agents können gequeuete alte Shutdown-Nachrichten empfangen und sofort terminieren.
