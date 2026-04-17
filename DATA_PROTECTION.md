# Datenschutz-Dokumentation — wp-dsgvo-form

**Status:** VERBINDLICH — Alle Findings MUESSEN vor dem Release umgesetzt sein.
**Erstellt:** 2026-04-17 | **Autor:** dpo (Datenschutzbeauftragter)
**Abhaengigkeiten:** SECURITY_REQUIREMENTS.md, ARCHITECTURE.md
**Review:** v1.6 — Update DPO-FINDING-10 (CAPTCHA lokal gebundelt, v1.0.6)

---

## Inhaltsverzeichnis

1. [Datenschutz-by-Design & by-Default](#1-datenschutz-by-design--by-default)
2. [Datenkategorien und Verarbeitungszwecke](#2-datenkategorien-und-verarbeitungszwecke)
3. [Muster-Verarbeitungsverzeichnis (Art. 30 DSGVO)](#3-muster-verarbeitungsverzeichnis-art-30-dsgvo)
4. [Rechtsgrundlagen der Verarbeitung](#4-rechtsgrundlagen-der-verarbeitung)
5. [Datenschutzrechtliche Bewertung der Supervisor-Rolle](#5-datenschutzrechtliche-bewertung-der-supervisor-rolle)
6. [Speicherdauer und Loeschkonzept](#6-speicherdauer-und-loeschkonzept)
7. [Betroffenenrechte — Technische Umsetzung](#7-betroffenenrechte--technische-umsetzung)
8. [CAPTCHA-Datenschutzbewertung](#8-captcha-datenschutzbewertung)
9. [Datenpanne und Breach-Notification](#9-datenpanne-und-breach-notification)
10. [Datenschutz-Folgenabschaetzung (DSFA)](#10-datenschutz-folgenabschaetzung-dsfa)
11. [Technische und organisatorische Massnahmen (TOMs)](#11-technische-und-organisatorische-massnahmen-toms)
12. [Anforderungen an Plugin-Nutzer](#12-anforderungen-an-plugin-nutzer)
13. [Offene Punkte und Empfehlungen](#13-offene-punkte-und-empfehlungen)

---

## 1. Datenschutz-by-Design & by-Default

### 1.1 Datenschutzgrundsaetze in der Architektur (Art. 25 DSGVO)

Das Plugin MUSS die folgenden Datenschutzprinzipien architektonisch verankern:

| # | Prinzip | DSGVO-Artikel | Architektonische Umsetzung | Status |
|---|---------|---------------|---------------------------|--------|
| PbD-01 | **Datensparsamkeit** | Art. 5 Abs. 1 lit. c | Nur vom Admin konfigurierte Felder werden gespeichert. Keine versteckten Metadaten (User-Agent, Referrer, Browser-Fingerprint). IP-Adresse wird NICHT gespeichert (nur temporaer fuer Rate-Limiting). | Spezifiziert (SEC-DSGVO-01/02) |
| PbD-02 | **Zweckbindung** | Art. 5 Abs. 1 lit. b | Jedes Formular hat einen definierten Zweck. Empfaenger sehen nur ihnen zugewiesene Formulare. Supervisor-Zugriff erfordert dokumentierten Zweck. | Spezifiziert (SEC-AUTH-DSGVO-01) |
| PbD-03 | **Speicherbegrenzung** | Art. 5 Abs. 1 lit. e | Konfigurierbare Aufbewahrungsfristen pro Formular (Default: 90 Tage). Automatische Loeschung via Cron-Job. | Spezifiziert (SEC-DSGVO-08) |
| PbD-04 | **Integritaet & Vertraulichkeit** | Art. 5 Abs. 1 lit. f | AES-256-GCM Verschluesselung aller Formulardaten. Envelope Encryption: KEK (Key Encryption Key) in wp-config.php, pro Formular ein eigener DEK (Data Encryption Key), pro Datei ein eigener File-DEK. Zufaelliger IV pro Operation. Authentifizierte Verschluesselung mit GCM Auth-Tag. Kompromittierung eines DEK betrifft nur ein Formular. | Spezifiziert (SEC-ENC-01 bis 12, ARCHITECTURE.md §3.2) |
| PbD-05 | **Transparenz** | Art. 5 Abs. 1 lit. a | Einwilligungstext mit Link zur Datenschutzerklaerung. Integration in WordPress Privacy Policy Page. Muster-Verarbeitungsverzeichnis. | Spezifiziert (SEC-DSGVO-04/05/12) |
| PbD-06 | **Rechenschaftspflicht** | Art. 5 Abs. 2 | Lueckenlose Audit-Protokollierung aller Zugriffe auf Einsendungen. Audit-Log nicht loeschbar (1 Jahr Aufbewahrung). | Spezifiziert (SEC-AUDIT-01 bis 03) |

### 1.2 Privacy-by-Default-Anforderungen

| # | Anforderung | Beschreibung |
|---|-------------|--------------|
| PbD-DEF-01 | **Verschluesselung aktiv** | Verschluesselung ist IMMER aktiv — keine Option zum Deaktivieren. |
| PbD-DEF-02 | **CAPTCHA aktiv** | CAPTCHA ist standardmaessig fuer jedes neue Formular aktiviert. Deaktivierung nur bewusst durch Admin. |
| PbD-DEF-03 | **Loeschfrist gesetzt** | Neue Formulare haben standardmaessig eine Aufbewahrungsfrist von 90 Tagen. Admin kann aendern, aber nicht auf "unbegrenzt" setzen. |
| PbD-DEF-04 | **Einwilligung Hard-Block** | Jedes Formular enthaelt automatisch ein Pflicht-Checkbox-Feld fuer die Datenschutzeinwilligung. Dieses Feld kann nicht entfernt werden. **Auftraggeber-Entscheidung:** Formular-Submission ohne aktive Einwilligung wird serverseitig abgelehnt (Hard-Block). Keine Daten werden gespeichert, keine E-Mails gesendet. (Siehe auch LEGAL-CONSENT-06) |
| PbD-DEF-05 | **Keine E-Mail-Inhalte** | Benachrichtigungs-E-Mails enthalten standardmaessig KEINE Formulardaten — nur einen Hinweis auf neue Einsendungen. |
| PbD-DEF-06 | **Session-Timeout** | Empfaenger/Supervisor-Sessions laufen nach 2 Stunden ab (nicht 14 Tage wie WP-Standard). |

> **[DPO-FINDING-01] KRITISCH — Unbegrenzte Speicherung verhindern:**
> Die Architektur erlaubt `retention_days = 0` als "keine automatische Loeschung". Dies widerspricht Art. 5 Abs. 1 lit. e DSGVO (Speicherbegrenzung). **Empfehlung:** `retention_days` MUSS mindestens 1 Tag betragen. Maximum: 3650 Tage (10 Jahre) fuer gesetzliche Aufbewahrungspflichten. `0` darf NICHT "unbegrenzt" bedeuten.
> **Status (v1.5):** TEILWEISE GELOEST — `Submission::validate()` erzwingt retention_days >= 1. ABER: SettingsPage zeigt "0 = unbegrenzt" als Hinweistext an (siehe DPO-FINDING-18), was den Admin verwirren kann.

> **[DPO-FINDING-18] NIEDRIG — Settings-UX "0 = unbegrenzt" widersprüchlich:**
> **Entdeckt:** v1.0.3 DPO-Vollreview (SettingsPage.php).
> Die Einstellungsseite zeigt den Hinweistext "0 = unbegrenzt" beim Feld `retention_days`, obwohl `Submission::validate()` im Code einen Mindestwert von 1 Tag erzwingt. Admins koennten versuchen, 0 einzugeben und dann eine unverstaendliche Fehlermeldung erhalten.
> **Empfehlung:** Hinweistext in SettingsPage aendern auf: "Mindestwert: 1 Tag. Maximum: 3650 Tage (10 Jahre)." Die Option "unbegrenzte Speicherung" darf NICHT angeboten werden (Art. 5 Abs. 1 lit. e).

---

## 2. Datenkategorien und Verarbeitungszwecke

### 2.1 Vom Plugin verarbeitete personenbezogene Daten

| Datenkategorie | Herkunft | Speicherort | Verschluesselt | Loeschbar | Rechtsgrundlage |
|----------------|----------|-------------|---------------|-----------|-----------------|
| **Formularfeld-Daten** (Name, E-Mail, Telefon, Freitext etc.) | Formular-Einsender | `dsgvo_submissions.encrypted_data` | Ja (AES-256-GCM) | Ja (manuell + automatisch) | Art. 6 Abs. 1 lit. a (Einwilligung) |
| **Hochgeladene Dateien** | Formular-Einsender | Dateisystem + `dsgvo_submission_files` | Ja (AES-256-GCM) | Ja (mit Submission) | Art. 6 Abs. 1 lit. a (Einwilligung) |
| **Einwilligungsdaten** (Zeitstempel, Textversion) | Formular-Einsender | `dsgvo_submissions.encrypted_data` | Ja | Ja (mit Submission) | Art. 6 Abs. 1 lit. c (rechtl. Verpflichtung) |
| **IP-Adresse** (temporaer) | Formular-Einsender | Nur im Arbeitsspeicher (Request-Scope) | N/A | Automatisch (nach Request) | Art. 6 Abs. 1 lit. f (berechtigtes Interesse) |
| **CAPTCHA-Token** | Externer CAPTCHA-Service | Nur im Arbeitsspeicher (Verifikation) | N/A | Automatisch (nach Verifikation) | Art. 6 Abs. 1 lit. f (berechtigtes Interesse) |
| **Empfaenger-E-Mail** | Admin-Konfiguration | `dsgvo_form_recipients.user_id` (Referenz auf wp_users) | Nein | Ja (Empfaenger entfernen) | Art. 6 Abs. 1 lit. f (berechtigtes Interesse) |
| **Audit-Log** (user_id, action, IP, timestamp) | Admin/Supervisor-Zugriffe | `dsgvo_form_audit_log` | Nein | Automatisch nach 1 Jahr (IP nach 90 Tagen auf NULL gesetzt) | Art. 6 Abs. 1 lit. f (berechtigtes Interesse) |
| **CAPTCHA-Token** | Externer CAPTCHA-Service | Nur im Arbeitsspeicher (Verifikation) | N/A | Automatisch (nach Verifikation) | Art. 6 Abs. 1 lit. f (berechtigtes Interesse) |

> **Hinweis (Klarstellung security-expert v1.4):** Es gibt KEIN IP-basiertes Rate-Limiting mit gespeicherten IP-Hashes. IP-Adressen werden ausschliesslich im Request-Scope verarbeitet und NICHT in der Datenbank oder in Transients gespeichert (SEC-DSGVO-02, SEC-CAP-11). CAPTCHA ist der primaere Spam-Schutz.

### 2.2 Besondere Kategorien personenbezogener Daten (Art. 9 DSGVO)

> **[DPO-FINDING-02] WARNUNG — Besondere Datenkategorien moeglich:**
> Da das Plugin frei konfigurierbare Formularfelder erlaubt, koennen Plugin-Nutzer Formulare erstellen, die besondere Kategorien personenbezogener Daten (Art. 9 DSGVO) erfassen: Gesundheitsdaten, religioese Ueberzeugungen, politische Meinungen etc.
>
> **Anforderung:** Das Plugin MUSS:
> 1. In der Admin-UI bei der Formularerstellung einen Hinweis anzeigen, dass besondere Datenkategorien eine ausdrueckliche Einwilligung (Art. 9 Abs. 2 lit. a) erfordern.
> 2. Eine Option bieten, ein Formular als "enthaltend besondere Datenkategorien" zu markieren — dies loest strengere Schutzmassnahmen aus (kuerzere Aufbewahrungsfrist, weniger Zugriffsberechtigte).
> 3. In der Datenschutzerklaerungsvorlage einen optionalen Absatz fuer Art. 9-Daten bereitstellen.

### 2.3 Datenfluss-Diagramm

```
Formular-Einsender                Plugin (WordPress-Server)              Externer Service
       |                                    |                                    |
       |-- 1. Formular ausfuellen --------->|                                    |
       |-- 2. CAPTCHA loesen -------------->|-- 3. Token verifizieren ---------->|
       |                                    |<-- 4. Verifikation (valid/invalid) |
       |                                    |                                    |
       |                                    |-- 5. Einwilligung pruefen (HARD-BLOCK)|
       |                                    |-- 6. Felder validieren             |
       |                                    |-- 7. Daten verschluesseln (AES-256)|
       |                                    |-- 8. In DB speichern               |
       |                                    |-- 9. Empfaenger benachrichtigen    |
       |<-- 10. Erfolgs-/Fehlermeldung -----|                                    |
       |                                    |                                    |
       |                                    |   Empfaenger/Supervisor            |
       |                                    |<-- 10. Login                       |
       |                                    |-- 11. Daten entschluesseln         |
       |                                    |-- 12. Anzeige (escaped)            |
       |                                    |-- 13. Zugriff loggen (Audit)       |
```

---

## 3. Muster-Verarbeitungsverzeichnis (Art. 30 DSGVO)

Das Plugin stellt Plugin-Nutzern (als Verantwortliche gem. Art. 4 Nr. 7 DSGVO) folgendes Muster-Verarbeitungsverzeichnis bereit. Dieses wird ueber `wp_add_privacy_policy_content()` in die WordPress-Datenschutzseite integriert.

### 3.1 Verarbeitungstaetigkeit: Kontaktformular-Einsendungen

| Feld | Inhalt |
|------|--------|
| **Bezeichnung der Verarbeitung** | Entgegennahme und Bearbeitung von Formular-Einsendungen ueber DSGVO-konforme Web-Formulare |
| **Verantwortlicher** | [Name und Kontaktdaten des Website-Betreibers — vom Plugin-Nutzer auszufuellen] |
| **Datenschutzbeauftragter** | [Falls vorhanden — vom Plugin-Nutzer auszufuellen] |
| **Zweck der Verarbeitung** | Entgegennahme, Speicherung und Bearbeitung von Anfragen/Einsendungen ueber Kontakt-, Anmelde- oder sonstige Webformulare |
| **Kategorien betroffener Personen** | Website-Besucher, die ein Formular ausfuellen (Kunden, Interessenten, Bewerber etc.) |
| **Kategorien personenbezogener Daten** | Je nach Formular-Konfiguration: Name, E-Mail-Adresse, Telefonnummer, Freitext-Nachrichten, hochgeladene Dateien, ggf. weitere vom Admin konfigurierte Felder |
| **Empfaenger der Daten** | - Zugewiesene Formular-Empfaenger (nur ihre Formulare) |
| | - Supervisoren (alle Formulare, mit dokumentiertem Zweck) |
| | - Administratoren des WordPress-Systems |
| | - CAPTCHA-Service: captcha.repaircafe-bruchsal.de (nur Token-Verifikation) |
| **Uebermittlung in Drittlaender** | Nein (CAPTCHA-Service muss in EU/EWR gehostet sein — siehe Abschnitt 8) |
| **Loeschfristen** | Konfigurierbar pro Formular (Default: 90 Tage). Automatische Loeschung nach Ablauf. Audit-Log: 1 Jahr. |
| **Technische und organisatorische Massnahmen** | AES-256-GCM Verschluesselung, Zugriffskontrolle via WordPress-Rollen, Audit-Logging, CAPTCHA-Schutz, Session-Timeout (2h) |
| **Rechtsgrundlage** | Art. 6 Abs. 1 lit. a DSGVO (Einwilligung des Betroffenen) |

### 3.2 Verarbeitungstaetigkeit: Audit-Logging

| Feld | Inhalt |
|------|--------|
| **Bezeichnung der Verarbeitung** | Protokollierung administrativer Zugriffe auf Formular-Einsendungen |
| **Zweck der Verarbeitung** | Nachvollziehbarkeit von Datenzugriffen, Missbrauchserkennung, Rechenschaftspflicht (Art. 5 Abs. 2 DSGVO) |
| **Kategorien betroffener Personen** | Administratoren, Supervisoren und Empfaenger des Plugins |
| **Kategorien personenbezogener Daten** | WordPress-User-ID, IP-Adresse, Zeitstempel, durchgefuehrte Aktion |
| **Loeschfristen** | Automatische Loeschung nach 1 Jahr |
| **Rechtsgrundlage** | Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an der Nachvollziehbarkeit) |

> **[DPO-FINDING-03] ENTSCHIEDEN — Audit-Log IP-Adressen (SEC-AUDIT-04/05):**
> Security-expert hat SEC-AUDIT-04 ergaenzt: IP-Adressen im Audit-Log werden nach **90 Tagen auf NULL gesetzt**. Der restliche Log-Eintrag (user_id, action, timestamp) bleibt bis zur 1-Jahres-Frist. 
> **DPO-Entscheidung:** 90-Tage-Aufbewahrung der IP ist angemessen. Die IP dient als Zusatznachweis bei kompromittierten Admin-Accounts. Die user_id allein waere in diesem Fall nicht aussagekraeftig genug. Rechtsgrundlage: Art. 6 Abs. 1 lit. f (berechtigtes Interesse an Nachvollziehbarkeit bei Sicherheitsvorfaellen).
> **Status (v1.5):** IMPLEMENTIERT — AuditLogger::cleanup_ip_addresses() setzt ip_address nach 90 Tagen auf NULL, cleanup_old_entries() loescht nach 365 Tagen. Kein admin-seitiger Loeschpfad fuer Audit-Eintraege.

> **[DPO-FINDING-17] MITTEL — Audit-Log Deduplizierung (Datensparsamkeit):**
> **Entdeckt:** v1.0.3 DPO-Vollreview (AuditLogger.php).
> Jeder Aufruf einer Einsendungs-Detailseite erzeugt einen neuen Audit-Eintrag. Beim schnellen Navigieren (z.B. Reload, Vor/Zurueck) entstehen viele identische Eintraege (gleicher User, gleiche Submission, gleiche Aktion, wenige Sekunden Abstand). Dies ist fuer die Nachvollziehbarkeit irrelevant und widerspricht der Datensparsamkeit (Art. 5 Abs. 1 lit. c).
> **Empfehlung:** Deduplizierung einfuehren — kein neuer Eintrag, wenn innerhalb der letzten 60 Sekunden bereits ein identischer Eintrag existiert (gleicher user_id + submission_id + action). Implementierung: `INSERT ... WHERE NOT EXISTS (SELECT 1 FROM audit_log WHERE ... AND created_at > NOW() - INTERVAL 60 SECOND)`.

---

## 4. Rechtsgrundlagen der Verarbeitung

### 4.1 Einwilligung (Art. 6 Abs. 1 lit. a DSGVO)

Die primaere Rechtsgrundlage fuer Formular-Einsendungen ist die **Einwilligung** des Betroffenen.

**Anforderungen an die Einwilligung (Art. 7 DSGVO):**

| # | Anforderung | Umsetzung im Plugin |
|---|-------------|---------------------|
| CONSENT-01 | **Freiwilligkeit** | Kein Zwang zur Formularnutzung. Keine vorausgefuellten Checkboxen. |
| CONSENT-02 | **Bestimmtheit** | Einwilligungstext muss den konkreten Zweck benennen (Admin-konfigurierbar). |
| CONSENT-03 | **Informiertheit** | Link zur Datenschutzerklaerung im Einwilligungstext. Pflicht! |
| CONSENT-04 | **Unmissverstaendlichkeit** | Klare, einfache Sprache im Einwilligungstext. Admin-konfigurierbar. |
| CONSENT-05 | **Nachweisbarkeit** | Zeitstempel + Version des Einwilligungstexts werden mit der Einsendung gespeichert. |
| CONSENT-06 | **Widerrufbarkeit** | Hinweis auf Widerrufrecht im Einwilligungstext. Technisch: Loeschung der Einsendung. |
| CONSENT-07 | **Hard-Block (Auftraggeber-Entscheidung)** | Formular-Submission ohne aktive Einwilligung wird serverseitig abgelehnt. KEINE Daten werden gespeichert, KEINE E-Mails gesendet, KEINE Dateien angenommen. Fehlermeldung an den Einsender. (LEGAL-CONSENT-06) |

### 4.1a Mehrsprachigkeit und Einwilligung (i18n)

| # | Anforderung | Beschreibung |
|---|-------------|--------------|
| CONSENT-I18N-01 | **Sprachkongruenz** | Einwilligungstext MUSS in derselben Sprache angezeigt werden wie das Formular. Art. 7 Abs. 2 DSGVO: "verstaendliche und leicht zugaengliche Form in einer klaren und einfachen Sprache". |
| CONSENT-I18N-02 | **Fail-Closed bei fehlender Uebersetzung** | Fehlt der Einwilligungstext in der aktuellen Formular-Sprache, darf das Formular NICHT angezeigt werden. Kein Fallback auf eine andere Sprache fuer den Consent-Text (PbD-Prinzip). |
| CONSENT-I18N-03 | **Sprachspezifische Versionierung** | Jede Sprachversion des Einwilligungstexts ist ein eigenstaendiger Rechtstext mit eigener Version-ID. Schema: `consent_version_id` + `consent_locale`. Aenderung einer Sprachversion erzeugt neue Version nur fuer diese Sprache. |
| CONSENT-I18N-04 | **Locale in Submission speichern** | Bei jeder Einsendung MUSS gespeichert werden: welche Sprachversion (Locale + Version-ID) der Einwilligungstext hatte, den der Nutzer akzeptiert hat. |
| CONSENT-I18N-05 | **Datenschutzerklaerung-Link sprachspezifisch** | Der Link zur Datenschutzerklaerung im Einwilligungstext muss auf die sprachlich passende Version verweisen. |

> **[DPO-FINDING-13] KRITISCH — Einwilligungstext-Uebersetzung:**
> Bei 6 unterstuetzten Sprachen (de_DE, en_US, fr_FR, es_ES, it_IT, sv_SE) MUSS sichergestellt sein, dass fuer jede Sprache ein rechtsgueltiger Einwilligungstext vorliegt. Fehlt eine Uebersetzung, darf das Formular in dieser Sprache NICHT angezeigt werden (Fail-Closed). Die Submission muss `consent_locale` und `consent_version_id` speichern, damit die Nachweisbarkeit (Art. 7 Abs. 1) sprachuebergreifend gewaehrleistet ist.

> **[DPO-FINDING-04] KRITISCH — Einwilligungstext-Versionierung (erweitert v1.4):**
> Bei Aenderung des Einwilligungstexts durch den Admin MUSS die vorherige Version archiviert werden. Bestehende Einsendungen beziehen sich auf die zum Zeitpunkt der Einsendung gueltige Version. **Anforderung:** Versionierte Speicherung des Einwilligungstexts (Version-ID + Locale + Text + Gueltig-ab-Datum). Durch i18n erweitert: Pro Sprache eigene Versionshistorie.
> **Status (v1.5):** IMPLEMENTIERT — ConsentVersion-Model (includes/Models/ConsentVersion.php) mit immutablen Versionen pro form_id + locale. Neue Version bei jeder Aenderung. ConsentManagementPage in Admin-UI.

> **[DPO-FINDING-14] HOCH — Consent-Version Race Condition (Art. 7 Abs. 1):**
> **Entdeckt:** v1.0.3 DPO-Vollreview.
> `form-handler.js` (Zeile 26) listet `dsgvo_consent_version` in der Konstante `SYSTEM_FIELDS`, wodurch es aus `collectFields()` und `buildPayload()` ausgeschlossen wird. Das Hidden-Input-Feld, das `FormBlock.php` (Zeile 421) rendert (`<input type="hidden" name="dsgvo_consent_version" value="...">`), wird daher NICHT an den Server gesendet.
> Der Server bestimmt `consent_version_id` eigenstaendig zum Submit-Zeitpunkt via `ConsentVersion::get_current_version($form_id, $locale)` in `SubmitEndpoint::verify_consent()`.
>
> **Problem:** Zwischen Formular-Rendering und Formular-Absendung kann ein Admin den Einwilligungstext aendern. In diesem Fall wuerde der Server die **neue** Version speichern, obwohl der Einsender die **alte** Version im Browser gesehen hat. Dies bricht die Nachweiskette nach Art. 7 Abs. 1 DSGVO (Nachweis, welchen Text der Betroffene akzeptiert hat).
>
> **Fix:** `form-handler.js` MUSS `dsgvo_consent_version` aus `SYSTEM_FIELDS` entfernen und das Hidden-Input-Feld regulaer in den Payload aufnehmen. `SubmitEndpoint` MUSS die vom Client gesendete `consent_version_id` verwenden und gegen die DB validieren (existiert, gehoert zum richtigen Formular + Locale). Wenn die Version nicht mehr existiert: 409 Conflict → Benutzer muss Formular neu laden.
>
> **Release-Blocker:** JA — Art. 7 Abs. 1 DSGVO.

> **[DPO-FINDING-15] HOCH — Consent-Locale Client-Seitig (Art. 7 Abs. 1):**
> **Entdeckt:** v1.0.3 DPO-Vollreview.
> `consent_locale` wird aus `form.dataset.locale` (client-seitig) gelesen und im Payload an den Server gesendet. Theoretisch kann ein Angreifer den Locale-Wert manipulieren.
>
> **Mitigierung:** Wenn DPO-FINDING-14 behoben ist (Client sendet consent_version_id), ist die consent_version_id der autoritaive Anker — die Locale ist in der ConsentVersion-Tabelle eindeutig mit der Version verknuepft. Der Server SOLL die Locale aus der ConsentVersion ableiten, nicht dem Client-Wert vertrauen.
>
> **Fix:** Bei der Behebung von DPO-FINDING-14 sicherstellen, dass `SubmitEndpoint` die `consent_locale` aus der validierten ConsentVersion-Zeile extrahiert, nicht aus dem Client-Payload.
>
> **Release-Blocker:** JA — in Kombination mit DPO-FINDING-14 (ein Fix behebt beide).

### 4.2 Berechtigtes Interesse (Art. 6 Abs. 1 lit. f DSGVO)

Fuer bestimmte Verarbeitungen wird das berechtigte Interesse herangezogen:

| Verarbeitung | Berechtigtes Interesse | Interessenabwaegung |
|-------------|----------------------|---------------------|
| CAPTCHA-basiertes Spam-Filtering | Schutz vor Spam/Missbrauch | Minimal invasiv (nur Token-Verifikation). Keine IP-Speicherung. Interesse ueberwiegt. |
| Audit-Logging | Nachvollziehbarkeit, Missbrauchserkennung | Nur Admin-Aktionen betroffen, nicht Endnutzer. Interesse ueberwiegt. |
| CAPTCHA-Validierung | Schutz vor Spam/Bot-Einsendungen | Minimal invasiv (nur Token-Verifikation). Interesse ueberwiegt. |
| Supervisor-Zugriff | Datenschutzkontrolle, Revision | Nur mit dokumentiertem Zweck. Strenges Audit-Logging als Ausgleich. |

---

## 5. Datenschutzrechtliche Bewertung der Supervisor-Rolle

### 5.1 Problemstellung

Die `wp_dsgvo_form_supervisor`-Rolle ermoeglicht Zugriff auf ALLE Einsendungen ALLER Formulare. Dies steht in Spannung mit:
- **Zweckbindung (Art. 5 Abs. 1 lit. b):** Daten eines Formulars wurden fuer den Zweck dieses Formulars erhoben — nicht fuer eine uebergreifende Einsicht.
- **Datensparsamkeit (Art. 5 Abs. 1 lit. c):** Nur so viele Daten zugaenglich machen wie noetig.
- **Need-to-Know-Prinzip:** Zugriff nur auf das, was fuer die Aufgabenerfuellung erforderlich ist.

### 5.2 Bewertung: Bedingt zulaessig

Die Supervisor-Rolle ist **datenschutzrechtlich zulaessig**, wenn ALLE folgenden Bedingungen erfuellt sind:

1. **Dokumentierter Zweck:** Der Admin MUSS bei Zuweisung der Rolle einen Zweck angeben (Pflichtfeld).
2. **Zulaessige Zwecke:**
   - Datenschutzbeauftragter (Kontrolle der Datenverarbeitung)
   - Geschaeftsfuehrung mit Weisungsbefugnis
   - IT-Administration (Support/Fehleranalyse)
   - Revision/Audit
3. **Unzulaessig:** Pauscaler Zugriff "weil es bequemer ist" oder "fuer den Fall der Faelle".
4. **Technische Flankierung:** Lueckenloses Audit-Logging aller Zugriffe (SEC-AUDIT-01 bis 03).
5. **Organisatorische Flankierung:** Halbjaehrliche Review-Erinnerung (SEC-AUTH-DSGVO-01 Nr. 4).

### 5.3 Anforderungen (ueber SECURITY_REQUIREMENTS.md hinaus)

| # | Anforderung | DSGVO-Bezug |
|---|-------------|-------------|
| DPO-SUP-01 | Supervisor-Zuweisungen MUESSEN in einer separaten Log-Tabelle oder im Audit-Log dokumentiert werden (Wer hat wann wen zum Supervisor ernannt, mit welchem Zweck). | Art. 5 Abs. 2 (Rechenschaftspflicht) |
| DPO-SUP-02 | Bei Aenderung des Zwecks: Neue Dokumentation erforderlich, alte bleibt archiviert. | Art. 5 Abs. 1 lit. b (Zweckbindung) |
| DPO-SUP-03 | Das Plugin MUSS eine Admin-Seite bereitstellen, die alle aktuellen Supervisoren mit ihrem dokumentierten Zweck und dem Datum der letzten Ueberpruefung auflistet. | Art. 25 (Privacy by Design) |
| DPO-SUP-04 | Export von Supervisor-Zugriffsprotokollen MUSS moeglich sein (fuer Auditoren/Datenschutzbehoerden). | Art. 5 Abs. 2 (Rechenschaftspflicht) |

> **[DPO-FINDING-16] MITTEL — role_justification nur fuer Supervisoren (Art. 5 Abs. 2):**
> **Entdeckt:** v1.0.3 DPO-Vollreview (RecipientListPage.php, Zeile 437).
> Das Feld `role_justification` ist nur bei der Supervisor-Rolle ein Pflichtfeld. Fuer die Reader-Rolle ist es optional. Aus Sicht der Rechenschaftspflicht (Art. 5 Abs. 2) SOLLTE fuer JEDE Empfaenger-Zuweisung dokumentiert werden, warum diese Person Zugriff auf die Formulardaten benoetigt — auch wenn der Zugriff auf bestimmte Formulare beschraenkt ist.
> **Empfehlung:** `role_justification` auch fuer Reader als Pflichtfeld setzen. Alternativ: Mindestens in der Admin-Dokumentation darauf hinweisen, dass Plugin-Nutzer die Zuweisungsgruende auch fuer Reader dokumentieren sollten.

---

## 6. Speicherdauer und Loeschkonzept

### 6.1 Loeschfristen

| Datentyp | Default-Loeschfrist | Konfigurierbar | Mechanismus |
|----------|---------------------|----------------|-------------|
| Formular-Einsendungen | 90 Tage | Ja (pro Formular, 1-3650 Tage) | Cron-Job `dsgvo_form_cleanup` (stuendlich) |
| Hochgeladene Dateien | Mit Einsendung | Nein (an Einsendung gekoppelt) | Cron-Job (physische + DB-Loeschung) |
| Audit-Log (gesamt) | 1 Jahr | Nein (fest) | Cron-Job |
| Audit-Log (IP-Adressen) | 90 Tage | Nein (fest) | Cron-Job setzt `ip_address = NULL` (SEC-AUDIT-04) |
| CAPTCHA-Token | Sofort nach Verifikation | Nein | Im Arbeitsspeicher |

### 6.2 Anforderungen an das Loeschkonzept

| # | Anforderung | Beschreibung |
|---|-------------|--------------|
| DEL-01 | **Echte Loeschung** | Kein Soft-Delete. `DELETE FROM` + physische Dateiloeschung (`unlink()`). Ueberschreiben des Speicherbereichs ist bei DB-Loeschung nicht moeglich — daher Verschluesselung als primaerer Schutz. |
| DEL-02 | **Kaskaden-Loeschung** | Bei Loeschung einer Einsendung: Alle zugehoerigen Dateien (DB-Eintraege + Dateisystem) mit loeschen. |
| DEL-03 | **Formular-Loeschung** | Bei Loeschung eines Formulars: ALLE Einsendungen + Dateien + Empfaenger-Zuordnungen + DEK loeschen. Achtung: DEK-Loeschung macht alle Daten unwiederbringlich unlesbar (Crypto-Erasure). |
| DEL-04 | **Plugin-Deinstallation** | Bei Deinstallation (uninstall.php): ALLE Tabellen, Optionen, Rollen, Capabilities, Upload-Verzeichnisse loeschen. Admin-Warnung vorher anzeigen. |
| DEL-05 | **Cron-Job-Zuverlaessigkeit** | Cron-Job MUSS Fehlerbehandlung haben. Bei Fehlschlag: Admin-Benachrichtigung. Erneuter Versuch beim naechsten Lauf. |
| DEL-06 | **Keine unbegrenzte Speicherung** | retention_days darf nicht 0 oder leer sein. Minimum: 1 Tag. |

> **[DPO-FINDING-05] EMPFEHLUNG — Crypto-Erasure als Loeschstrategie (Aktualisiert v1.3):**
> Durch Envelope Encryption (KEK→DEK) ist Crypto-Erasure nun auf zwei Ebenen moeglich:
> 1. **Formular-Ebene:** Loeschung des Formular-DEK macht alle Einsendungen dieses Formulars unwiederbringlich unlesbar. DEL-03 nutzt dies bereits.
> 2. **Plugin-Ebene:** Loeschung des KEK in wp-config.php macht ALLE DEKs und damit alle Daten unlesbar (Deinstallations-Strategie).
> **Empfehlung:** Beide Ebenen als Loeschstrategie dokumentieren. Formular-Loeschung via DEK-Vernichtung ist der primaere Mechanismus. KEK-Loeschung als zusaetzliche Absicherung bei Deinstallation.

---

## 7. Betroffenenrechte — Technische Umsetzung

### 7.1 Recht auf Auskunft (Art. 15 DSGVO)

| # | Anforderung | Technische Umsetzung |
|---|-------------|---------------------|
| ART15-01 | Betroffene muessen erfahren koennen, ob und welche Daten ueber sie gespeichert sind. | Admin/Supervisor kann nach E-Mail-Adresse in Einsendungen suchen und dem Betroffenen die Daten mitteilen. |
| ART15-02 | Export in lesbarem Format. | Export als CSV oder JSON (entschluesselt). |
| ART15-03 | Integration in WordPress Privacy Export Tool. | Plugin registriert einen Exporter ueber `wp_register_personal_data_exporter()`. Sucht alle Einsendungen, die eine E-Mail-Adresse enthalten, die der angefragten Person zugeordnet werden kann. |

> **[DPO-FINDING-06] KRITISCH — Suche nach Betroffenen:**
> Da Formulardaten verschluesselt gespeichert werden, ist eine direkte Datenbanksuche nach E-Mail-Adressen oder Namen NICHT moeglich. Fuer Art. 15-Anfragen muessen ALLE Einsendungen eines Formulars entschluesselt und durchsucht werden.
>
> **Anforderungen:**
> 1. **Such-Index:** Ein verschluesselter Suchindex (z.B. Blind Index mit HMAC-SHA256 der E-Mail-Adresse) MUSS implementiert werden, um Auskunftsanfragen effizient bearbeiten zu koennen.
> 2. **Alternativ:** Zeitbasierte Eingrenzung durch den Betroffenen ("Ich habe am Datum X ein Formular Y ausgefuellt") reduziert den Suchaufwand.
> 3. **Performance:** Bei vielen Einsendungen (>1000 pro Formular) kann eine Vollentschluesselung zur Suche problematisch werden — Performance-Expert einbeziehen.
>
> **Status (v1.5):** IMPLEMENTIERT — KeyManager::calculate_lookup_hash() erzeugt HMAC-SHA256 ueber normalisierte E-Mail (lowercase+trim). Separater HMAC-Key via derive_hmac_key() (SEC-ENC-14, kein Key-Reuse). Feld `email_lookup_hash` in dsgvo_submissions fuer O(1)-Suche nach Betroffenen.

### 7.2 Recht auf Loeschung (Art. 17 DSGVO)

| # | Anforderung | Technische Umsetzung |
|---|-------------|---------------------|
| ART17-01 | Einzelne Einsendung loeschen | Admin-UI: "Loeschen"-Button pro Einsendung. Echte Loeschung (kein Soft-Delete). |
| ART17-02 | Bulk-Loeschung | Admin-UI: Alle Einsendungen eines Formulars loeschen. |
| ART17-03 | Automatische Loeschung | Cron-Job loescht nach Ablauf der Aufbewahrungsfrist. |
| ART17-04 | WordPress Privacy Eraser | Plugin registriert einen Eraser ueber `wp_register_personal_data_eraser()`. |
| ART17-05 | Kaskadierende Loeschung | Einsendung + Dateien + Audit-Referenzen (anonymisiert, nicht geloescht). |

### 7.3 Recht auf Datenportabilitaet (Art. 20 DSGVO)

| # | Anforderung | Technische Umsetzung |
|---|-------------|---------------------|
| ART20-01 | Strukturiertes, gaengiges, maschinenlesbares Format | Export als JSON (strukturiert) oder CSV (tabellarisch). |
| ART20-02 | Direktes Uebermittlungsrecht | Nicht technisch durch das Plugin unterstuetzbar (Plugin-Nutzer muss organisatorisch sicherstellen). |

> **[DPO-FINDING-07] — Export-Format:**
> JSON ist das bevorzugte Format fuer Datenportabilitaet (Art. 20), da es strukturiert und maschinenlesbar ist. CSV ist ergaenzend fuer die menschliche Lesbarkeit sinnvoll. Beide Formate MUESSEN angeboten werden.

### 7.4 Recht auf Berichtigung (Art. 16 DSGVO)

> **[DPO-FINDING-08] WARNUNG — Berichtigungsrecht:**
> Das Plugin sieht aktuell KEINE Moeglichkeit vor, Einsendungsdaten nachtraeglich zu aendern (nur Lesen und Loeschen). Art. 16 DSGVO erfordert aber ein Recht auf Berichtigung.
>
> **Empfehlung:** Mindestens einen Admin-Workflow implementieren:
> 1. Admin kann auf Anfrage eine Einsendung oeffnen und einzelne Felder berichtigen.
> 2. Berichtigung wird im Audit-Log dokumentiert (Wer, Wann, Was geaendert).
> 3. **Alternativ (minimaler Aufwand):** Admin loescht fehlerhafte Einsendung und bittet den Betroffenen um erneute Einsendung.

### 7.5 Recht auf Einschraenkung der Verarbeitung (Art. 18 DSGVO)

> **[DPO-FINDING-09] WARNUNG — Einschraenkungsrecht (Art. 18 DSGVO):**
> Art. 18 DSGVO erfordert die Moeglichkeit, die Verarbeitung einzuschraenken (z.B. bei Streit ueber die Richtigkeit der Daten).
>
> **Empfehlung:** Ein `is_restricted`-Flag (TINYINT(1)) pro Einsendung einfuehren. Benennung orientiert sich an Art. 18 DSGVO ("restriction of processing"). Eingeschraenkte Einsendungen:
> - Werden nicht geloescht (auch nicht durch automatische Loeschung)
> - Koennen nur vom Admin eingesehen werden (nicht von Empfaengern)
> - Sind klar als "gesperrt" gekennzeichnet
> - Koennen wieder freigegeben werden
>
> **Status (v1.5):** IMPLEMENTIERT —
> - `is_restricted` Flag in dsgvo_submissions Tabelle
> - DPO-BEDINGUNG-1: SubmissionDetailView entschluesselt eingeschraenkte Submissions NICHT fuer Reader
> - DPO-BEDINGUNG-2: Nur Supervisor/Admin kann Einschraenkung aufheben (SubmissionViewPage)
> - SubmissionDeleteEndpoint: 409 Conflict bei Versuch, eingeschraenkte Submission zu loeschen
> - Audit-Logging bei restrict/unrestrict Aktionen

---

## 8. CAPTCHA-Datenschutzbewertung

### 8.1 Datenfluss-Analyse

| Schritt | Daten | Empfaenger | Datenschutz-Relevanz |
|---------|-------|------------|---------------------|
| 1. CAPTCHA-Widget laden | Script wird lokal aus `public/js/captcha.min.js` geladen (v1.0.6). **Kein externer HTTP-Request** beim Seitenaufruf. SRI-geschuetzt. | WordPress-Server (lokal) | **KEINE** — kein Datentransfer an Dritte beim Laden |
| 2. CAPTCHA loesen | IP-Adresse des Endnutzers bei aktiver Interaktion mit dem Widget. Interaktionsdaten (Klicks, Timing). | captcha.repaircafe-bruchsal.de | **MITTEL** — IP-Uebertragung nur bei bewusster Nutzerinteraktion |
| 3. Token generieren | CAPTCHA-Token | Browser (hidden input) | **NIEDRIG** — kein Personenbezug |
| 4. Token verifizieren (Server-zu-Server) | NUR `verification_token` im Body. Bearer-Auth via `Authorization`-Header. KEINE Endnutzer-IP, kein User-Agent (SEC-CAP-08). | captcha.repaircafe-bruchsal.de | **NIEDRIG** — kein Endnutzer-Personenbezug im Verify-Request |

### 8.2 Bewertung

> **[DPO-FINDING-10] GELOEST — CAPTCHA-Service Datenschutz (v1.0.6):**
> 
> **Geloest in v1.0.6** durch drei Massnahmen:
>
> 1. **Lokales Script-Bundling (Task #223):** `captcha.min.js` (16.6 KB) wird lokal aus `public/js/captcha.min.js` geladen. Kein externer Script-Request beim Seitenaufruf — die IP des Endnutzers wird beim Laden der Seite NICHT an den CAPTCHA-Server uebertragen. SRI-Integritaet wird via Build-Script automatisch generiert (WPDSGVO_CAPTCHA_SRI).
>
> 2. **IP-Uebertragung nur bei aktiver Interaktion:** Die IP des Endnutzers wird erst bei der aktiven CAPTCHA-Loesung (Schritt 2-3) an den CAPTCHA-Server uebertragen. Dies ist datenschutzrechtlich akzeptabel, da der Nutzer durch die aktive Interaktion mit dem Widget eine Handlung vornimmt. HINWEIS: Plugin-Betreiber muessen den CAPTCHA-Service in ihrer Datenschutzerklaerung nennen.
>
> 3. **Bearer-Token-Authentifizierung (Task #230):** Server-zu-Server-Verifikation (`POST /api/validate`) uebertraegt ausschliesslich `verification_token` im Request-Body. Keine Endnutzer-IP, kein User-Agent (SEC-CAP-08). Authentifizierung via `Authorization: Bearer <api_key>`. HTTPS erzwungen. 5-Sekunden-Timeout mit Fail-Closed.
>
> **Konfigurierbarkeit (Task #221):** CAPTCHA-Server-URL ist ueber Plugin-Einstellungen konfigurierbar. Bei externer URL wird das Script vom externen Server geladen (Fallback-Verhalten). **SOLL:** Hinweistext in SettingsPage ergaenzen, dass bei externer URL die IP des Endnutzers an den externen Server uebertragen wird.
>
> **Verbleibende Anforderungen an Plugin-Betreiber:**
> - Plugin-Betreiber MUSS CAPTCHA-Service in Datenschutzerklaerung nennen (SEC-CAP-09).
> - Plugin-Betreiber MUSS AVV mit CAPTCHA-Anbieter abschliessen, wenn externer Service genutzt wird (LEGAL-PRIVACY-02/03).
> - Bei Nutzung eines externen CAPTCHA-Servers: Hosting-Standort pruefen (EU/EWR empfohlen).

### 8.3 Vergleich mit gaengigen CAPTCHA-Diensten

| Kriterium | Google reCAPTCHA | hCaptcha | Eigener Service (repaircafe) |
|-----------|-----------------|----------|------------------------------|
| Datenuebertragung USA | Ja (problematisch) | Ja (aber Privacy-freundlicher) | Nein — Script lokal gebundelt, IP nur bei aktiver Interaktion |
| Tracking/Profiling | Ja (Google-Oekosystem) | Minimal | Kein Tracking durch lokales Bundling |
| AVV verfuegbar | Ja (Google DPA) | Ja | Betreiber-Pflicht bei externem Server |
| DSGVO-Konformitaet | Umstritten (mehrere Bussgelder) | Besser, aber nicht unproblematisch | Optimal: lokales Script, kein Tracking, EU-Server empfohlen |

---

## 9. Datenpanne und Breach-Notification

### 9.1 Szenarien einer Datenpanne

| Szenario | Risiko-Einstufung | Meldepflicht (Art. 33/34) |
|----------|-------------------|--------------------------|
| **DB-Leak ohne Key-Kompromittierung** | NIEDRIG — Daten sind AES-256-GCM verschluesselt, ohne Key unlesbar | Keine Meldepflicht (kein Risiko fuer Betroffene, sofern Verschluesselung nachweislich intakt) |
| **DB-Leak MIT Key-Kompromittierung** | HOCH — Alle Daten potenziell lesbar | Meldepflicht: Aufsichtsbehoerde (72h) + Betroffene (unverzueglich) |
| **Unbefugter Admin-Zugriff** | MITTEL — Zugriff auf entschluesselte Daten, aber Audit-Trail vorhanden | Meldepflicht: Aufsichtsbehoerde (72h). Betroffene nur bei hohem Risiko. |
| **CAPTCHA-Service kompromittiert** | NIEDRIG — Nur Tokens betroffen, keine Formulardaten | Keine Meldepflicht (kein Personenbezug der Tokens). Aber: Spam-Schutz deaktiviert. |
| **Backup-Leak** | MITTEL-HOCH — Abhaengig davon, ob Backups verschluesselt sind | Meldepflicht abhaengig von Backup-Verschluesselung |
| **wp-config.php exponiert (Key sichtbar)** | KRITISCH — Encryption Key kompromittiert | Sofortige Meldepflicht + Key-Rotation (manueller Incident-Response-Prozess) |

### 9.2 Technische Anforderungen fuer Breach-Response

| # | Anforderung | Beschreibung |
|---|-------------|--------------|
| BREACH-01 | **Erkennung** | Audit-Log ermoeglicht Erkennung ungewoehnlicher Zugriffsmuster (z.B. Supervisor liest ungewoehnlich viele Einsendungen). Admin-Benachrichtigung bei Anomalien. |
| BREACH-02 | **Key-Rotation (Incident-Response)** | Bei KEK-Kompromittierung: Neuen KEK generieren, alle DEKs mit altem KEK entschluesseln und mit neuem KEK re-encrypten, wp-config.php aktualisieren, alten KEK sicher loeschen. **Vorteil Envelope Encryption:** Nur DEKs werden re-encrypted, Submission-Daten bleiben unberuehrt (schneller, weniger fehleranfaellig). |
| BREACH-03 | **Notfall-Loeschung** | Moeglichkeit, ALLE Einsendungen eines Formulars oder ALLER Formulare sofort zu loeschen (Panik-Button fuer Admins). |
| BREACH-04 | **Benachrichtigungs-Template** | Plugin stellt ein Muster-Template fuer die Benachrichtigung der Aufsichtsbehoerde (Art. 33) und der Betroffenen (Art. 34) bereit. |
| BREACH-05 | **Verschluesselungsnachweis** | Bei DB-Leak: Plugin muss nachweisen koennen, dass die Daten zum Zeitpunkt des Leaks verschluesselt waren (Audit-Log + Konfigurationsnachweis). |

> **[DPO-FINDING-11] EMPFEHLUNG — Key-Rotation als Incident-Response-Prozess (Aktualisiert v1.3):**
> Durch Envelope Encryption ist KEK-Rotation nun moeglich, OHNE alle Daten neu zu verschluesseln. Nur die DEKs muessen mit dem neuen KEK re-encrypted werden:
> **Anforderung:** Ein dokumentierter Incident-Response-Prozess fuer Key-Kompromittierung MUSS in der Plugin-Dokumentation beschrieben sein:
> 1. Neuen KEK generieren (`random_bytes(32)`)
> 2. WP-CLI oder Admin-Tool: Alle DEKs mit altem KEK entschluesseln + mit neuem KEK re-encrypten (Submission-Daten bleiben unberuehrt!)
> 3. `DSGVO_FORM_ENCRYPTION_KEY` in wp-config.php aktualisieren
> 4. Alten KEK sicher loeschen
> 5. Vorfall im Audit-Log dokumentieren

---

## 10. Datenschutz-Folgenabschaetzung (DSFA)

### 10.1 DSFA-Pflicht-Pruefung (Art. 35 DSGVO)

Eine DSFA ist erforderlich, wenn die Verarbeitung "voraussichtlich ein hohes Risiko fuer die Rechte und Freiheiten natuerlicher Personen" birgt.

| Kriterium (WP 248 Rev.01 der Art.-29-Gruppe) | Zutreffend? | Begruendung |
|----------------------------------------------|-------------|-------------|
| Bewertung/Scoring | Nein | Keine Profilbildung |
| Automatisierte Entscheidungsfindung | Nein | Keine automatisierten Entscheidungen |
| Systematische Ueberwachung | Nein | Kein Monitoring |
| Sensible Daten (Art. 9) | **Moeglich** | Abhaengig von Formular-Konfiguration |
| Daten in grossem Umfang | **Moeglich** | Abhaengig von Nutzungsintensitaet |
| Zusammenfuehren von Datensaetzen | Nein | Keine Datenaggregation |
| Daten schutzbeduerftiger Personen | **Moeglich** | Z.B. Kinder, Patienten (je nach Formular) |
| Innovative Technologie | Nein | Standard-Verschluesselung |
| Daten, die die Rechtsausuebung verhindern | Nein | |

### 10.2 Bewertung

> **[DPO-FINDING-12] — DSFA-Empfehlung:**
> Das Plugin selbst loest keine DSFA-Pflicht aus. ABER: Abhaengig von der konkreten Nutzung (z.B. Gesundheitsformulare, Formulare fuer Kinder) KANN beim Plugin-Nutzer eine DSFA-Pflicht entstehen.
>
> **Anforderung:**
> 1. Das Plugin MUSS in der Dokumentation darauf hinweisen, dass der Plugin-Nutzer als Verantwortlicher pruefen muss, ob eine DSFA erforderlich ist.
> 2. Das Plugin SOLL ein Muster-DSFA-Template bereitstellen (optional, als Hilfestellung).
> 3. Bei Markierung eines Formulars als "besondere Datenkategorien" (DPO-FINDING-02): Automatischer Hinweis "Eine Datenschutz-Folgenabschaetzung koennte erforderlich sein".

---

## 11. Technische und organisatorische Massnahmen (TOMs)

### 11.1 Technische Massnahmen

| # | Massnahme | Umsetzung | DSGVO-Bezug |
|---|-----------|-----------|-------------|
| TOM-T01 | **Verschluesselung** | AES-256-GCM, Envelope Encryption, verschluesselte Dateien | Art. 32 Abs. 1 lit. a |
| TOM-T02 | **Datenminimierung** | Keine IP-Speicherung (SEC-DSGVO-02), keine Metadaten, CAPTCHA als Spam-Schutz | Art. 32 Abs. 1 lit. a |
| TOM-T03 | **Zugriffskontrolle** | WordPress-Rollen (Reader/Supervisor/Admin), Capability-Checks | Art. 32 Abs. 1 lit. b |
| TOM-T04 | **Integritaetsschutz** | GCM Authentication Tag, CSRF-Nonces, Input-Validierung | Art. 32 Abs. 1 lit. b |
| TOM-T05 | **Verfuegbarkeit** | WordPress-Standard (Backups durch Hosting, nicht Plugin-Scope) | Art. 32 Abs. 1 lit. c |
| TOM-T06 | **Belastbarkeit** | CAPTCHA-Schutz, Honeypot-Feld, WordPress-Nonce | Art. 32 Abs. 1 lit. b |
| TOM-T07 | **Wiederherstellbarkeit** | Verschluesselte Backups (Hosting-Verantwortung), DB-Migrationen | Art. 32 Abs. 1 lit. c |
| TOM-T08 | **Regemaessige Pruefung** | Audit-Logging, halbjaehrliche Supervisor-Review | Art. 32 Abs. 1 lit. d |
| TOM-T09 | **Automatische Loeschung** | Cron-Job fuer abgelaufene Einsendungen | Art. 5 Abs. 1 lit. e |
| TOM-T10 | **Session-Management** | 2-Stunden-Timeout fuer Plugin-Rollen | Art. 32 Abs. 1 lit. b |

### 11.2 Organisatorische Massnahmen (Verantwortung des Plugin-Nutzers)

| # | Massnahme | Beschreibung |
|---|-----------|--------------|
| TOM-O01 | **Rollenkonzept** | Plugin-Nutzer muss dokumentieren, wer welche Rolle hat und warum. |
| TOM-O02 | **Schulung** | Empfaenger und Supervisoren muessen im Umgang mit personenbezogenen Daten geschult werden. |
| TOM-O03 | **Regelmaessige Pruefung** | Halbjaehrliche Ueberpruefung der Rollenverteilung (Plugin unterstuetzt mit Erinnerungen). |
| TOM-O04 | **Backup-Verschluesselung** | Plugin-Nutzer muss sicherstellen, dass DB-Backups verschluesselt sind (nicht Plugin-Scope). |
| TOM-O05 | **CAPTCHA-AVV** | Falls CAPTCHA-Service Drittanbieter: AVV abschliessen. |

---

## 12. Anforderungen an Plugin-Nutzer

Das Plugin unterstuetzt DSGVO-Konformitaet, aber die Verantwortung liegt beim Plugin-Nutzer als Verantwortlicher (Art. 4 Nr. 7 DSGVO). Folgendes MUSS der Plugin-Nutzer sicherstellen:

| # | Pflicht | Plugin-Unterstuetzung |
|---|---------|----------------------|
| NUTZER-01 | Datenschutzerklaerung auf der Website | Plugin stellt Muster-Text bereit (wp_add_privacy_policy_content) |
| NUTZER-02 | Verarbeitungsverzeichnis fuehren | Plugin stellt Muster-Eintrag bereit (Abschnitt 3) |
| NUTZER-03 | AVV mit CAPTCHA-Anbieter | Plugin weist in Einstellungen darauf hin |
| NUTZER-04 | DSFA pruefen (bei sensiblen Daten) | Plugin weist bei Art.-9-Markierung darauf hin |
| NUTZER-05 | Betroffenenrechte bearbeiten | Plugin stellt Export-/Loesch-Funktionen bereit |
| NUTZER-06 | Rollenverteilung dokumentieren | Plugin stellt Supervisor-Uebersicht bereit |
| NUTZER-07 | Aufbewahrungsfristen festlegen | Plugin erzwingt Konfiguration bei Formularerstellung |
| NUTZER-08 | Backups verschluesseln | Nicht im Plugin-Scope — Hosting-Verantwortung |
| NUTZER-09 | Datenpanne melden (72h) | Plugin stellt Muster-Templates bereit (BREACH-04) |
| NUTZER-10 | wp-config.php schuetzen | Nicht im Plugin-Scope — Server-Konfiguration |

---

## 13. Offene Punkte und Empfehlungen

### 13.1 Zusammenfassung aller DPO-Findings

| Finding | Schwere | Abschnitt | Beschreibung | Status |
|---------|---------|-----------|--------------|--------|
| DPO-FINDING-01 | KRITISCH | 1.2 | retention_days = 0 darf nicht "unbegrenzt" bedeuten | Teilweise geloest (Code OK, UX-Text falsch → DPO-FINDING-18) |
| DPO-FINDING-02 | WARNUNG | 2.2 | Besondere Datenkategorien (Art. 9) moeglich — Hinweis + Markierung noetig | Offen |
| DPO-FINDING-03 | ENTSCHIEDEN | 3.2 | Audit-Log IP-Adressen: 90 Tage behalten, dann NULL (SEC-AUDIT-04) | Geloest (v1.0.3 verifiziert) |
| DPO-FINDING-04 | KRITISCH | 4.1 | Einwilligungstext-Versionierung — pro Sprache eigene Version-ID | Geloest (v1.0.3 — ConsentVersion-Model implementiert) |
| DPO-FINDING-05 | EMPFEHLUNG | 6.2 | Crypto-Erasure: Per-Form (DEK loeschen) und Per-Plugin (KEK loeschen) dokumentieren | Aktualisiert (v1.3) |
| DPO-FINDING-06 | KRITISCH | 7.1 | Suche nach Betroffenen bei verschluesselten Daten — Blind Index | Geloest (v1.0.3 — HMAC-SHA256 Lookup Hash in KeyManager) |
| DPO-FINDING-07 | EMPFEHLUNG | 7.3 | JSON als primaeres Export-Format fuer Datenportabilitaet | Offen |
| DPO-FINDING-08 | WARNUNG | 7.4 | Berichtigungsrecht (Art. 16) nicht implementierbar | Offen |
| DPO-FINDING-09 | WARNUNG | 7.5 | Einschraenkungsrecht (Art. 18) — is_restricted Flag | Geloest (v1.0.3 — DPO-BEDINGUNG-1 und -2 implementiert) |
| DPO-FINDING-10 | GELOEST | 8.2 | CAPTCHA lokal gebundelt (v1.0.6), IP nur bei aktiver Interaktion, Bearer-Auth | Geloest (v1.0.6 — lokales Bundling + Bearer-Auth) |
| DPO-FINDING-11 | EMPFEHLUNG | 9.2 | Key-Rotation: KEK-Rotation via DEK-Re-Encryption (Incident-Response-Prozess) | Aktualisiert (v1.3) |
| DPO-FINDING-12 | EMPFEHLUNG | 10.2 | DSFA-Hinweis fuer Plugin-Nutzer | Offen |
| DPO-FINDING-13 | KRITISCH | 4.1a | Einwilligungstext-Uebersetzung: Fail-Closed bei fehlender Sprache, consent_locale | Geloest (v1.0.3 — FormBlock Fail-Closed + consent_locale implementiert) |
| **DPO-FINDING-14** | **HOCH** | **4.1** | **Consent-Version Race Condition: form-handler.js sendet consent_version_id nicht** | **RELEASE-BLOCKER** |
| **DPO-FINDING-15** | **HOCH** | **4.1a** | **Consent-Locale client-seitig: Server soll Locale aus ConsentVersion ableiten** | **RELEASE-BLOCKER (mit DPO-FINDING-14 behebbar)** |
| DPO-FINDING-16 | MITTEL | 5.3 | role_justification nur fuer Supervisoren — sollte auch fuer Reader gelten | Offen |
| DPO-FINDING-17 | MITTEL | 3.2 | Audit-Log Deduplizierung: identische Eintraege innerhalb 60 Sek. vermeiden | Offen |
| DPO-FINDING-18 | NIEDRIG | 1.2 | Settings-UX "0 = unbegrenzt" widersprueht Code-Validierung (min 1 Tag) | Offen |

### 13.2 Priorisierung

**RELEASE-BLOCKER (HOCH — MUESSEN vor Release behoben sein):**
1. **DPO-FINDING-14** — Consent-Version Race Condition (form-handler.js + SubmitEndpoint)
2. **DPO-FINDING-15** — Consent-Locale aus ConsentVersion ableiten (in einem Fix mit DPO-FINDING-14)

**Vor Release SOLLEN umgesetzt sein (WARNUNG):**
3. DPO-FINDING-02 — Art. 9-Hinweis
4. DPO-FINDING-08 — Berichtigungsrecht (mindestens Workaround)

**Empfohlen (MITTEL/NIEDRIG/EMPFEHLUNG):**
6. DPO-FINDING-16 — role_justification auch fuer Reader
7. DPO-FINDING-17 — Audit-Log Deduplizierung
8. DPO-FINDING-18 — Settings-UX "0 = unbegrenzt" korrigieren
9. DPO-FINDING-05 — Crypto-Erasure-Dokumentation
10. DPO-FINDING-07 — Export-Formate
11. DPO-FINDING-11 — KEK-Rotation-Incident-Response-Dokumentation
12. DPO-FINDING-12 — DSFA-Muster

### 13.3 Geloeste Punkte

- **DPO-FINDING-01 (teilweise):** Submission::validate() erzwingt retention_days >= 1. Settings-UX-Text noch widerspruelich (→ DPO-FINDING-18).
- **DPO-FINDING-03:** Audit-Log IP-Adressen werden 90 Tage aufbewahrt, dann auf NULL gesetzt (SEC-AUDIT-04). v1.0.3 verifiziert: AuditLogger::cleanup_ip_addresses() + cleanup_old_entries() korrekt implementiert.
- **DPO-FINDING-04:** ConsentVersion-Model mit immutablen Versionen pro form_id + locale implementiert. Admin-UI (ConsentManagementPage) zum Verwalten vorhanden.
- **DPO-FINDING-05:** Aktualisiert (v1.3): Per-Form Crypto-Erasure durch DEK-Loeschung moeglich (Envelope Encryption). KEK-Loeschung als Deinstallations-Absicherung.
- **DPO-FINDING-06:** HMAC-SHA256 Lookup Hash (email_lookup_hash) in KeyManager::calculate_lookup_hash() implementiert. Separater HMAC-Key via derive_hmac_key() (kein Key-Reuse).
- **DPO-FINDING-09:** is_restricted Flag implementiert. DPO-BEDINGUNG-1: Eingeschraenkte Submissions werden fuer Reader NICHT entschluesselt (SubmissionDetailView). DPO-BEDINGUNG-2: Nur Supervisor/Admin kann Einschraenkung aufheben (SubmissionViewPage).
- **DPO-FINDING-10 (geloest v1.0.6):** CAPTCHA-Script lokal gebundelt (`public/js/captcha.min.js`, 16.6 KB). Kein externer Script-Request beim Seitenaufruf. IP nur bei aktiver CAPTCHA-Interaktion uebertragen. Server-zu-Server-Verifikation mit Bearer-Auth, nur `verification_token` im Body (SEC-CAP-08). SRI automatisch generiert.
- **DPO-FINDING-11:** Aktualisiert (v1.3): KEK-Rotation erfordert nur DEK-Re-Encryption, nicht Full-Re-Encryption aller Submissions.
- **DPO-FINDING-13:** FormBlock::render() erzwingt Fail-Closed bei fehlender ConsentVersion fuer aktuelle Locale. consent_locale + consent_version_id werden in Submission gespeichert. ABER: Race-Condition entdeckt (→ DPO-FINDING-14).

---

## Versions-Historie

| Version | Datum | Aenderung |
|---------|-------|----------|
| 1.0 | 2026-04-17 | Initiale Datenschutz-Dokumentation erstellt (DPO) |
| 1.1 | 2026-04-17 | Aktualisiert nach Feedback security-expert (v1.4) und legal-expert: Kein Envelope Encryption (einzelner Key), kein IP-Hashing, CAPTCHA nur Token bei Verify, Audit-Log IP 90 Tage → NULL, Key-Rotation als Incident-Response |
| 1.2 | 2026-04-17 | Auftraggeber-Entscheidung: Consent Hard-Block (CONSENT-07, PbD-DEF-04). SEC-CAP-12 (Client-seitiger CAPTCHA-Hinweis) und SEC-ENC-15 (Re-Encryption Incident-Response) von security-expert uebernommen. Abstimmung mit legal-expert (LEGAL-CONSENT-06, Template-Praezisierung) abgeschlossen. |
| 1.3 | 2026-04-17 | Architektur-Abgleich: Aktualisiert auf Envelope Encryption (KEK→DEK) gemaess ARCHITECTURE.md §3.2 und implementiertem Code (KeyManager.php, EncryptionService.php). DPO-FINDING-05 (Crypto-Erasure per Form moeglich), DPO-FINDING-11 (KEK-Rotation statt Full-Re-Encryption), BREACH-02, TOM-T02 (IP-Hash entfernt), TOM-T06 (Rate-Limiting → Honeypot) aktualisiert. DPO-Code-Review durchgefuehrt. |
| 1.4 | 2026-04-17 | i18n/Mehrsprachigkeit: CONSENT-I18N-01 bis 05 (Sprachkongruenz, Fail-Closed, sprachspezifische Versionierung, Locale in Submission, sprachspezifischer Datenschutzerklaerung-Link). DPO-FINDING-13 (KRITISCH) und DPO-FINDING-04 erweitert. Konstantenname korrigiert auf DSGVO_FORM_ENCRYPTION_KEY. |
| 1.5 | 2026-04-17 | **Vollstaendiges DPO-Review nach v1.0.3 Build.** Alle 35+ PHP-Dateien und Frontend-JS analysiert. 5 neue Findings: DPO-FINDING-14 (HOCH, Consent Race Condition), DPO-FINDING-15 (HOCH, Locale Client-seitig), DPO-FINDING-16 (MITTEL, role_justification), DPO-FINDING-17 (MITTEL, Audit Dedup), DPO-FINDING-18 (NIEDRIG, Settings UX). Status-Updates: DPO-FINDING-01/03/04/06/09/13 als geloest/teilweise geloest markiert. Neue Release-Blocker: DPO-FINDING-14 + 15 (Art. 7 Abs. 1). |
| 1.6 | 2026-04-17 | **DPO-FINDING-10 vollstaendig geloest (v1.0.6).** CAPTCHA-Script lokal gebundelt (public/js/captcha.min.js, 16.6 KB), kein externer HTTP-Request beim Seitenaufruf. IP nur bei aktiver CAPTCHA-Interaktion uebertragen. Bearer-Token-Authentifizierung bei Server-zu-Server-Verifikation. Datenfluss-Tabelle §8.1 aktualisiert. Vergleichstabelle §8.3 aktualisiert. |
