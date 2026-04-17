# ISO 27001 Bewertung — wp-dsgvo-form

**Erstellt:** 2026-04-17 | **Autor:** security-expert
**Anlass:** Auftraggeber-Anfrage zur ISO 27001-Umsetzung

---

## Zusammenfassung (Executive Summary)

**Empfehlung: ISO 27001-Zertifizierung NICHT anstreben. Stattdessen: Relevante technologische Controls aus Annex A als Best Practices übernehmen — was unsere SECURITY_REQUIREMENTS.md bereits weitgehend abdeckt.**

ISO 27001 ist ein Standard für Informationssicherheits-Managementsysteme (ISMS) in *Organisationen*. Er zertifiziert Prozesse, nicht Produkte. Ein einzelnes WordPress-Plugin kann nicht ISO 27001-zertifiziert werden. Der Aufwand für eine organisationsweite Zertifizierung wäre für dieses Projekt unverhältnismäßig.

---

## 1. Welche ISO 27001-Anforderungen wären relevant?

ISO 27001:2022 enthält 93 Controls in Annex A, gruppiert in 4 Kategorien. Davon sind für ein WordPress-Plugin nur die **technologischen Controls (A.8)** direkt relevant — und auch davon nur ein Teil:

### Relevante Controls

| Control | Beschreibung | Status in SECURITY_REQUIREMENTS.md |
|---------|-------------|-----------------------------------|
| **A.8.3** Informationszugangsbeschränkung | Capability-basierte Zugriffskontrolle | Abgedeckt (SEC-AUTH-01 bis SEC-AUTH-10) |
| **A.8.5** Sichere Authentifizierung | WP-User-System, Session-Sicherheit | Abgedeckt (SEC-AUTH-05 bis SEC-AUTH-08) |
| **A.8.7** Schutz gegen Schadsoftware | Datei-Upload-Validierung, MIME-Check | Abgedeckt (SEC-FILE-01 bis SEC-FILE-10) |
| **A.8.9** Konfigurationsmanagement | Plugin-Einstellungen, Schlüsselverwaltung | Abgedeckt (SEC-ENC-01 bis SEC-ENC-04) |
| **A.8.12** Verhinderung von Datenabfluss | Verschlüsselung, keine Daten in E-Mails | Abgedeckt (SEC-ENC-05 bis SEC-ENC-12, SEC-MAIL-03) |
| **A.8.24** Einsatz von Kryptographie | AES-256-GCM, Key-Management | Abgedeckt (SEC-ENC-01 bis SEC-ENC-12) |
| **A.8.25** Sicherer Entwicklungslebenszyklus | Code-Review-Checkliste | Abgedeckt (Checkliste in SECURITY_REQUIREMENTS.md) |
| **A.8.26** Anforderungen an Anwendungssicherheit | Input-Validierung, XSS/CSRF/SQLi-Schutz | Abgedeckt (SEC-VAL, SEC-XSS, SEC-CSRF, SEC-SQL) |
| **A.8.28** Sicheres Coding | WordPress-Coding-Standards, Escaping | Abgedeckt (SEC-XSS, SEC-SQL, SEC-GEN) |

### Nicht relevante Controls (Auswahl)

| Kategorie | Warum nicht relevant |
|-----------|---------------------|
| **A.5** Organisatorische Controls (37 Stück) | Betreffen die Organisation, nicht das Produkt. Informationssicherheitsrichtlinien, Rollen & Verantwortlichkeiten, Lieferantenbeziehungen — alles organisationsweit. |
| **A.6** Personelle Controls (8 Stück) | Screening, Schulung, Disziplinarverfahren — betreffen Mitarbeiter, nicht Software. |
| **A.7** Physische Controls (14 Stück) | Perimeterschutz, Bürosicherheit, Verkabelung — vollständig irrelevant für ein Plugin. |
| **A.8.1** Endnutzergeräte | Außerhalb des Plugin-Scopes. |
| **A.8.20** Netzwerksicherheit | WordPress-Hosting, nicht Plugin-Verantwortung. |
| **A.8.23** Webfilter | Infrastruktur, nicht Plugin. |

**Fazit:** Von 93 Controls sind ~9 für das Plugin relevant. Alle 9 werden durch unsere bestehenden SECURITY_REQUIREMENTS.md bereits abgedeckt.

---

## 2. Was würde die Umsetzung konkret bedeuten?

### Szenario A: Vollständige ISO 27001-Zertifizierung

| Aspekt | Aufwand |
|--------|---------|
| ISMS-Dokumentation | 3-6 Monate, umfasst Risikobewertung, Statement of Applicability, Policies für alle 93 Controls |
| Externe Auditierung | 10.000-30.000 € (abhängig vom Auditor und Scope) |
| Jährliche Überwachungsaudits | 5.000-15.000 € pro Jahr |
| Dedizierte Rolle | Information Security Officer nötig |
| Scope-Problem | Ein Plugin kann nicht zertifiziert werden — nur die Organisation, die es entwickelt |

**Bewertung: Komplett unverhältnismäßig.** Die Zertifizierungskosten übersteigen vermutlich den gesamten Projektwert.

### Szenario B: ISO 27001-"orientierte" Entwicklung (ohne Zertifizierung)

| Aspekt | Aufwand |
|--------|---------|
| Mapping unserer Requirements auf Annex A | 1-2 Tage (einmalig) |
| Dokumentation der Abdeckung | 1 Tag |
| Lückenanalyse | Bereits durchgeführt (diese Bewertung) |
| Zusätzliche technische Maßnahmen | Keine — unsere Requirements decken die relevanten Controls bereits ab |

**Bewertung: Minimaler Aufwand, aber auch minimaler Zusatznutzen.** Wir würden im Wesentlichen nur dokumentieren, dass wir tun, was wir ohnehin tun.

---

## 3. Mehrwert über DSGVO-Konformität hinaus?

### Was DSGVO + unsere Security Requirements bereits abdecken

| Schutzziel | DSGVO | Unsere Sec-Reqs | ISO 27001 zusätzlich |
|-----------|-------|-----------------|---------------------|
| Vertraulichkeit | Art. 32 (Verschlüsselung) | AES-256-GCM | Nichts Neues |
| Integrität | Art. 32 | GCM Auth-Tag, Input-Validierung | Nichts Neues |
| Verfügbarkeit | Art. 32 | Fail-closed, Error-Handling | Backup-Strategie (aber: Hosting-Verantwortung) |
| Zugriffskontrolle | Art. 25 (Privacy by Design) | Capabilities, IDOR-Schutz | Nichts Neues |
| Kryptographie | Art. 32 | AES-256-GCM, Key-Management | Key-Rotation (aber: würde Datenverlust verursachen) |
| Logging/Monitoring | — | Nicht explizit | **Potenzieller Zusatz:** Audit-Log für Admin-Aktionen |
| Secure SDLC | — | Code-Review-Checkliste | **Potenzieller Zusatz:** Formales Threat Modeling |

### Echte Lücken, die ISO 27001 aufdeckt

Es gibt genau **zwei Bereiche**, die ISO 27001 anregt und die in unseren bisherigen Requirements fehlen:

1. **Audit-Logging (A.8.15):** Protokollierung von Admin-Aktionen (wer hat wann welche Submission gelesen/gelöscht/exportiert). Dies wäre ein sinnvoller Zusatz für Nachweiszwecke.

2. **Vulnerability Management (A.8.8):** Formaler Prozess für Umgang mit bekannten Schwachstellen in Dependencies. Für das Plugin relevant: regelmäßiger `npm audit` für Gutenberg-Block-Dependencies.

Beide Punkte empfehle ich unabhängig von ISO 27001 als Ergänzung.

---

## 4. Empfehlung

### Klare Empfehlung: NICHT umsetzen (weder voll noch teilweise "ISO 27001")

**Begründung:**

1. **ISO 27001 zertifiziert Organisationen, nicht Produkte.** Ein WordPress-Plugin kann nicht ISO 27001-zertifiziert werden. Die Aussage "ISO 27001-konformes Plugin" wäre fachlich falsch und könnte als irreführend angesehen werden.

2. **Kein Marktdruck.** Kein WordPress-Plugin im Markt wirbt mit ISO 27001. Die Zielgruppe (WordPress-Admins) erwartet DSGVO-Konformität und sichere Coding-Practices, nicht ISMS-Zertifizierungen.

3. **Unsere bestehenden Requirements sind stärker.** Die SECURITY_REQUIREMENTS.md mit 78 konkreten, technischen Anforderungen ist für die Produktsicherheit wertvoller als ein generisches ISO 27001-Mapping. Wir haben WordPress-spezifische Best Practices, die ISO 27001 gar nicht kennt (`$wpdb->prepare()`, `esc_html()`, Nonces, etc.).

4. **Aufwand-Nutzen-Verhältnis:** Selbst das "orientierte" Szenario B produziert hauptsächlich Dokumentation ohne technischen Mehrwert.

### Stattdessen empfehle ich

1. **SECURITY_REQUIREMENTS.md als verbindlichen Standard** beibehalten (bereits vereinbart).
2. **Zwei sinnvolle Ergänzungen** aus der ISO 27001-Analyse übernehmen:
   - **[SEC-AUDIT-01]** Audit-Log für Admin-Aktionen (Submissions lesen/löschen/exportieren)
   - **[SEC-AUDIT-02]** Regelmäßiger `npm audit` als Teil des Build-Prozesses
3. **"DSGVO-konform"** als Positionierung verwenden — das ist für die Zielgruppe das relevante Qualitätsmerkmal.
4. **OWASP WordPress Security Implementation Guideline** als zusätzliche Referenz nutzen — das ist der branchenrelevante Standard für WordPress-Plugin-Sicherheit.

---

## Anhang A: Mapping-Tabelle (für die Dokumentation)

Falls der Auftraggeber eine formale Zuordnung wünscht, hier das Mapping unserer Requirements auf ISO 27001:2022 Annex A:

| ISO 27001 Control | Unsere Anforderungen |
|-------------------|---------------------|
| A.8.3 Information Access Restriction | SEC-AUTH-01 bis SEC-AUTH-16 |
| A.8.5 Secure Authentication | SEC-AUTH-06 bis SEC-AUTH-12 |
| A.8.7 Protection Against Malware | SEC-FILE-01 bis SEC-FILE-10 |
| A.8.8 Management of Technical Vulnerabilities | SEC-VULN-01, SEC-VULN-02 |
| A.8.9 Configuration Management | SEC-ENC-01 bis SEC-ENC-04 |
| A.8.12 Data Leakage Prevention | SEC-ENC-10 bis SEC-ENC-12, SEC-MAIL-03 |
| A.8.15 Logging | SEC-AUDIT-01 bis SEC-AUDIT-03 |
| A.8.24 Use of Cryptography | SEC-ENC-01 bis SEC-ENC-12 |
| A.8.25 Secure Development Life Cycle | Code-Review-Checkliste |
| A.8.26 Application Security Requirements | SEC-VAL, SEC-XSS, SEC-CSRF, SEC-SQL |
| A.8.28 Secure Coding | SEC-XSS, SEC-SQL, SEC-GEN |

---

## Anhang B: Pragmatische ISO 27001-Controls für wp-dsgvo-form

**Stand:** 2026-04-17 | **Anlass:** Auftraggeber-Entscheidung — keine formale Zertifizierung, aber sinnvolle Controls übernehmen.

Folgende 11 Controls aus ISO 27001:2022 Annex A werden pragmatisch umgesetzt, weil sie konkreten Sicherheitswert für das Plugin bieten:

### Kategorie 1: Zugriffskontrolle (UMSETZEN — hoher Wert)

| # | ISO Control | Was wir umsetzen | Unsere Anforderungen | Aufwand |
|---|-------------|-----------------|---------------------|---------|
| 1 | **A.8.3** Information Access Restriction | Custom Role `dsgvoform_reader` mit minimalen Capabilities, Formular-basierte Zuordnung, IDOR-Schutz | SEC-AUTH-01 bis SEC-AUTH-16 | Mittel |
| 2 | **A.8.5** Secure Authentication | WP-Login, verkürzte Session für Reader-Rolle (2h), Login-Redirect | SEC-AUTH-06, SEC-AUTH-11, SEC-AUTH-12 | Gering |

**Begründung:** Empfänger greifen auf personenbezogene Daten zu — saubere Zugriffskontrolle ist Kern-Feature und DSGVO-Pflicht.

### Kategorie 2: Kryptographie & Datenschutz (UMSETZEN — Kern-Feature)

| # | ISO Control | Was wir umsetzen | Unsere Anforderungen | Aufwand |
|---|-------------|-----------------|---------------------|---------|
| 3 | **A.8.24** Use of Cryptography | AES-256-GCM für alle Submissions + Datei-Uploads, Key in wp-config.php | SEC-ENC-01 bis SEC-ENC-12 | Mittel |
| 4 | **A.8.12** Data Leakage Prevention | Keine Klartext-Daten in E-Mails, verschlüsseltes Upload-Verzeichnis, kein Browser-Caching | SEC-ENC-09, SEC-MAIL-03, SEC-FILE-06/07 | Gering |

**Begründung:** Verschlüsselung ist das zentrale Differenzierungsmerkmal des Plugins und DSGVO Art. 32 Pflicht.

### Kategorie 3: Sichere Entwicklung (UMSETZEN — verhindert Schwachstellen)

| # | ISO Control | Was wir umsetzen | Unsere Anforderungen | Aufwand |
|---|-------------|-----------------|---------------------|---------|
| 5 | **A.8.25** Secure Development Life Cycle | Code-Review-Checkliste (10 Punkte), Security-Review vor Release | Checkliste in SECURITY_REQUIREMENTS.md | Gering |
| 6 | **A.8.26** Application Security Requirements | Input-Validierung, XSS/CSRF/SQLi-Schutz, CAPTCHA-Verifikation | SEC-VAL, SEC-XSS, SEC-CSRF, SEC-SQL, SEC-CAP | Hoch (aber ohnehin nötig) |
| 7 | **A.8.28** Secure Coding | WordPress-APIs nutzen (esc_html, $wpdb->prepare, wp_nonce, etc.) | SEC-XSS, SEC-SQL, SEC-GEN | Im Code-Standard integriert |

**Begründung:** OWASP Top 10 sind die häufigsten Angriffstypen auf Webanwendungen. Kein Plugin darf ohne diese Maßnahmen live gehen.

### Kategorie 4: Logging & Incident (UMSETZEN — Nachweispflicht)

| # | ISO Control | Was wir umsetzen | Unsere Anforderungen | Aufwand |
|---|-------------|-----------------|---------------------|---------|
| 8 | **A.8.15** Logging | Audit-Log für Submission-Zugriffe (view/export/delete), eigene DB-Tabelle, 1 Jahr Aufbewahrung | SEC-AUDIT-01 bis SEC-AUDIT-03 | Mittel |
| 9 | **A.5.24** Incident Management Planning *(vereinfacht)* | Dokumentierter Prozess: Bei Security-Incident → Admin-Benachrichtigung, Key-Rotation-Anleitung, Disclosure-Kontakt in README | Neu — empfohlen | Gering |

**Begründung:** DSGVO Art. 33 verlangt Meldepflicht bei Datenpannen. Audit-Log ist Voraussetzung für die Erkennung, Incident-Prozess für die Reaktion.

### Kategorie 5: Vulnerability & Config Management (UMSETZEN — Hygiene)

| # | ISO Control | Was wir umsetzen | Unsere Anforderungen | Aufwand |
|---|-------------|-----------------|---------------------|---------|
| 10 | **A.8.8** Management of Technical Vulnerabilities | `npm audit` im Build, 72h-Patch-Policy, Security-Kontakt in Plugin-Header | SEC-VULN-01, SEC-VULN-02 | Gering |
| 11 | **A.8.9** Configuration Management | Encryption-Key in wp-config.php, Plugin-Settings mit Defaults, Fail-closed bei fehlender Konfiguration | SEC-ENC-01 bis SEC-ENC-04 | Gering |

**Begründung:** Veraltete Dependencies sind die #1 Angriffsfläche bei WordPress-Plugins. Sichere Defaults verhindern Fehlkonfigurationen.

### Bewusst NICHT übernommene Controls

| ISO Control | Warum nicht |
|-------------|------------|
| A.5.1-A.5.37 (Organisatorische) | Betreffen die Organisation, nicht das Plugin-Produkt |
| A.6.1-A.6.8 (Personelle) | HR-Prozesse, irrelevant für Software |
| A.7.1-A.7.14 (Physische) | Infrastruktur/Hosting, nicht Plugin-Scope |
| A.8.1 Endnutzergeräte | Außerhalb Plugin-Kontrolle |
| A.8.20-A.8.22 Netzwerk | Hosting-Verantwortung, nicht Plugin |
| A.8.16 Monitoring | Würde permanentes Server-Monitoring erfordern — unverhältnismäßig für ein Plugin, dafür gibt es spezialisierte Security-Plugins |
| A.8.23 Webfilter | Infrastruktur-Level |
