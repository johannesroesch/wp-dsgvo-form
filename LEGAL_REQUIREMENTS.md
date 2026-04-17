# Legal Requirements — wp-dsgvo-form

**Status:** ENTWURF — Muss von echtem Anwalt gegengelesen werden.  
**Erstellt:** 2026-04-17 | **Autor:** legal-expert  
**Abgestimmt mit:** dpo (Datenschutzbeauftragter)  
**Hinweis:** Dieses Dokument liefert juristische Best Practices und Einschaetzungen, stellt aber keine formale Rechtsberatung dar.

---

## Inhaltsverzeichnis

1. [Rechtsgrundlagen fuer Datenverarbeitung](#1-rechtsgrundlagen-fuer-datenverarbeitung)
2. [Einwilligungstexte (Muster-Vorlagen)](#2-einwilligungstexte-muster-vorlagen)
3. [Datenschutzhinweis-Bausteine](#3-datenschutzhinweis-bausteine)
4. [DSGVO-Bewertung der Supervisor-Rolle](#4-dsgvo-bewertung-der-supervisor-rolle)
5. [Betroffenenrechte — Technische Umsetzung](#5-betroffenenrechte--technische-umsetzung)
6. [Haftungsfragen](#6-haftungsfragen)
7. [Technische Anforderungen an das Plugin](#7-technische-anforderungen-an-das-plugin)
8. [Checkliste fuer Plugin-Betreiber](#8-checkliste-fuer-plugin-betreiber)

---

## 1. Rechtsgrundlagen fuer Datenverarbeitung

### 1.1 Uebersicht der Rechtsgrundlagen (Art. 6 Abs. 1 DSGVO)

Fuer Formular-Einsendungen kommen primaer drei Rechtsgrundlagen in Betracht:

| Rechtsgrundlage | Artikel | Typische Anwendungsfaelle | Empfehlung |
|-----------------|---------|--------------------------|------------|
| **Einwilligung** | Art. 6 Abs. 1 lit. a | Kontaktformulare, Newsletter-Anmeldung, Feedback-Formulare, allgemeine Anfragen | **Standard-Empfehlung fuer das Plugin** |
| **Vertragsdurchfuehrung** | Art. 6 Abs. 1 lit. b | Bestellformulare, Auftragsanfragen, Bewerbungsformulare, Buchungsanfragen | Optional konfigurierbar |
| **Berechtigtes Interesse** | Art. 6 Abs. 1 lit. f | IT-Sicherheit (kurzlebiges Rate-Limiting via Transients), CAPTCHA-Validierung | Nur fuer technische Hilfsprozesse |

### 1.2 Detaillierte Bewertung

#### a) Einwilligung (Art. 6 Abs. 1 lit. a DSGVO)

**Wann:** Wenn keine vorvertragliche Beziehung besteht und der Betroffene freiwillig Daten uebermittelt (z.B. Kontaktformular, Feedback, allgemeine Anfragen).

**Anforderungen an die Einwilligung (Art. 7 DSGVO):**
- **[LEGAL-CONSENT-01]** Einwilligung muss **freiwillig** sein — kein Kopplungsverbot-Verstoss (kein "Stimmen Sie zu, um unsere Seite nutzen zu koennen")
- **[LEGAL-CONSENT-02]** Einwilligung muss **informiert** sein — der Betroffene muss wissen, welche Daten zu welchem Zweck verarbeitet werden
- **[LEGAL-CONSENT-03]** Einwilligung muss **unmissverstaendlich** sein — aktives Opt-in (Checkbox), keine vorausgefuellten Haekchen
- **[LEGAL-CONSENT-04]** Einwilligung muss **nachweisbar** sein — Zeitstempel + Version des Einwilligungstexts mit der Einsendung speichern
- **[LEGAL-CONSENT-05]** Einwilligung muss **widerrufbar** sein — Hinweis auf Widerrufsrecht muss im Einwilligungstext stehen

**Technische Konsequenzen:**
- **[LEGAL-CONSENT-06] Hard-Block (Auftraggeber-Entscheidung):** Einsendungen MUESSEN serverseitig abgelehnt werden, wenn `consent_given` nicht `true` ist. Dies ist ein Hard-Block — keine Soft-Warnung, keine Fallback-Verarbeitung. Der Server antwortet mit einem Fehler (HTTP 422) und speichert keine Daten. Client-seitige Validierung (JavaScript) ist ergaenzend, aber NICHT ausreichend — die serverseitige Pruefung ist massgeblich.
- **[LEGAL-CONSENT-07] Validierungsreihenfolge:** Die Einwilligungspruefung MUSS VOR der CAPTCHA-Verifikation erfolgen. Begründung: Ohne vorliegende Einwilligung fehlt die Rechtsgrundlage fuer die IP-Uebertragung an den CAPTCHA-Dienst (Art. 6 Abs. 1 lit. f setzt voraus, dass der Nutzer das Formular absenden will). Verbindliche Reihenfolge im Submission-Flow: (1) Einwilligung pruefen → (2) CAPTCHA verifizieren → (3) Felder validieren → (4) Verschluesseln → (5) Speichern.
- Einwilligungs-Checkbox MUSS ein Pflichtfeld sein (nicht vorausgefuellt, serverseitig erzwungen)
- Einwilligungstext MUSS versioniert gespeichert werden
- Bei Textaenderung: Neue Einsendungen beziehen sich auf neue Version, alte bleiben unveraendert
- Zeitstempel der Einwilligung wird mit den Einsendungsdaten gespeichert (aber NICHT verschluesselt, da Compliance-Nachweis)

#### b) Vertragsdurchfuehrung (Art. 6 Abs. 1 lit. b DSGVO)

**Wann:** Wenn die Formular-Einsendung zur Anbahnung oder Erfuellung eines Vertrags dient (z.B. Angebotsanfrage, Buchung, Bewerbung).

**Vorteil:** Keine separate Einwilligung noetig — aber trotzdem Informationspflicht (Art. 13 DSGVO).

**[LEGAL-CONTRACT-01]** Das Plugin SOLLTE dem Admin die Moeglichkeit geben, pro Formular die Rechtsgrundlage auszuwaehlen:
- "Einwilligung (Art. 6 Abs. 1 lit. a)" — Standard
- "Vertragsdurchfuehrung (Art. 6 Abs. 1 lit. b)" — Optional

Bei Vertragsdurchfuehrung entfaellt die Einwilligungs-Checkbox, aber ein Hinweis auf Art. 13 DSGVO (Informationspflicht) MUSS trotzdem angezeigt werden.

#### c) Berechtigtes Interesse (Art. 6 Abs. 1 lit. f DSGVO)

**Wann:** Nur fuer technische Hilfsdaten, die nicht direkt vom Benutzer eingegeben werden.

**[LEGAL-LEGINT-01]** Berechtigtes Interesse darf im Plugin NUR fuer folgende Zwecke herangezogen werden:
- Kurzlebiges Rate-Limiting via WordPress Transients (IP wird nur temporaer im Arbeitsspeicher gehalten, NICHT in der DB gespeichert)
- Zeitstempel der Einsendung (fuer Aufbewahrungsfristen)
- CAPTCHA-Validierung (IP wird im HTTP-Request an den CAPTCHA-Dienst uebertragen, aber nicht vom Plugin gespeichert)

**[LEGAL-LEGINT-02]** Berechtigtes Interesse darf NICHT als Standard-Rechtsgrundlage fuer die eigentlichen Formulardaten dienen. Diese brauchen entweder Einwilligung oder Vertragsgrundlage.

### 1.3 Empfehlung fuer das Plugin-Design

**[LEGAL-RG-01]** Das Plugin bietet pro Formular ein Dropdown-Feld "Rechtsgrundlage" mit folgenden Optionen:

| Option | Auswirkung auf Formular |
|--------|------------------------|
| Einwilligung (Standard) | Pflicht-Einwilligungs-Checkbox wird angezeigt, Einwilligungstext konfigurierbar |
| Vertragsdurchfuehrung | Keine Einwilligungs-Checkbox, stattdessen Datenschutzhinweis-Textblock |

---

## 2. Einwilligungstexte (Muster-Vorlagen)

### 2.1 Anforderungen an den Einwilligungstext

Nach Art. 7 und Art. 13 DSGVO muss der Einwilligungstext folgende Informationen enthalten:

- Identitaet des Verantwortlichen (wird vom Betreiber konfiguriert)
- Zweck der Datenverarbeitung
- Welche Daten verarbeitet werden
- Empfaenger/Kategorien von Empfaengern
- Speicherdauer bzw. Kriterien fuer die Festlegung der Dauer
- Hinweis auf Widerrufsrecht
- Link zur vollstaendigen Datenschutzerklaerung

### 2.2 Muster-Einwilligungstext: Kontaktformular

**[LEGAL-TEMPLATE-01]** Standard-Vorlage (vom Admin anpassbar):

```
Ich willige ein, dass meine im Formular eingegebenen Daten ({field_list}) zum Zweck 
der {purpose} verarbeitet und gespeichert werden. Die Daten werden verschluesselt 
gespeichert und nach {retention_days} Tagen automatisch geloescht.

Empfaenger meiner Daten: {recipient_description}

Ich kann diese Einwilligung jederzeit mit Wirkung fuer die Zukunft widerrufen. 
Naeheres zum Umgang mit meinen Daten finde ich in der Datenschutzerklaerung 
unter {privacy_policy_url}.
```

**Platzhalter:**
| Platzhalter | Beschreibung | Quelle |
|-------------|-------------|--------|
| `{field_list}` | Automatisch generierte Liste der Formularfelder | Aus Formular-Konfiguration |
| `{purpose}` | Zweck der Verarbeitung (Admin-konfigurierbar) | Freitext in Formular-Einstellungen |
| `{retention_days}` | Aufbewahrungsfrist in Tagen | Aus Formular-Konfiguration |
| `{recipient_description}` | **Konkrete** Angabe, wer Zugriff hat (Art. 13 Abs. 1 lit. e): Namen, Rollen oder Funktionen der Empfaenger — nicht nur "zugeordnete Empfaenger". Beispiel: "Personalabteilung (Max Mustermann), Geschaeftsfuehrung (Erika Musterfrau)" | Automatisch aus Empfaenger-Konfiguration (Name + Rolle/Funktion) |
| `{privacy_policy_url}` | Link zur Datenschutzerklaerung | Aus WordPress-Einstellungen (Datenschutzseite) |

### 2.3 Muster-Einwilligungstext: Formular mit Datei-Upload

**[LEGAL-TEMPLATE-02]** Erweiterung fuer Datei-Uploads:

```
Ich willige ein, dass meine im Formular eingegebenen Daten sowie die von mir 
hochgeladenen Dateien ({file_field_list}) zum Zweck der {purpose} verarbeitet 
und verschluesselt gespeichert werden. Die Daten und Dateien werden nach 
{retention_days} Tagen automatisch geloescht.

Zulaessige Dateiformate: {allowed_file_types}. Maximale Dateigroesse: {max_file_size}.

Empfaenger meiner Daten: {recipient_description}

Ich kann diese Einwilligung jederzeit mit Wirkung fuer die Zukunft widerrufen.
Naeheres zum Umgang mit meinen Daten finde ich in der Datenschutzerklaerung 
unter {privacy_policy_url}.
```

### 2.4 Informationstext bei Rechtsgrundlage "Vertragsdurchfuehrung"

**[LEGAL-TEMPLATE-03]** Wenn Rechtsgrundlage = Vertragsdurchfuehrung:

```
Ihre Angaben werden zum Zweck der {purpose} im Rahmen der Vertragsanbahnung 
bzw. Vertragsdurchfuehrung verarbeitet (Art. 6 Abs. 1 lit. b DSGVO). 
Die Daten werden verschluesselt gespeichert und nach {retention_days} Tagen 
automatisch geloescht.

Weitere Informationen zur Verarbeitung Ihrer Daten finden Sie in unserer 
Datenschutzerklaerung unter {privacy_policy_url}.
```

### 2.5 Technische Anforderungen an Einwilligungstexte

- **[LEGAL-TEMPLATE-04]** Alle Vorlagen MUESSEN vom Admin vollstaendig anpassbar sein (kein erzwungener Wortlaut)
- **[LEGAL-TEMPLATE-05]** Die Vorlagen dienen als Ausgangspunkt — das Plugin zeigt beim Erstellen eines neuen Formulars die passende Vorlage als Default an
- **[LEGAL-TEMPLATE-06]** Einwilligungstexte werden versioniert. Bei Aenderung: neue Version, alte Einsendungen behalten die zum Zeitpunkt der Einsendung gueltige Version
- **[LEGAL-TEMPLATE-07]** Das Plugin speichert mit jeder Einsendung: Einwilligungstext-Version, Einwilligungszeitpunkt, Einwilligungstext im Wortlaut (als Teil der Einsendungsdaten, aber NICHT verschluesselt — muss fuer Compliance-Nachweis lesbar bleiben)

### 2.6 Mehrsprachigkeit (i18n) von Einwilligungstexten

#### Rechtliche Grundlage

**Art. 12 Abs. 1 DSGVO** verlangt, dass Informationen zur Datenverarbeitung "in praeziser, transparenter, verstaendlicher und leicht zugaenglicher Form in einer **klaren und einfachen Sprache**" bereitgestellt werden. **Art. 7 Abs. 2 DSGVO** fordert fuer Einwilligungserklaerungen ebenfalls "klare und einfache Sprache".

**Konsequenz:** Eine Einwilligung in einer Sprache, die der Betroffene nicht versteht, ist **unwirksam**. Wenn das Plugin eine Website in 6 Sprachen bedient, MUESSEN die Einwilligungstexte in der jeweiligen Sprache des Betroffenen vorliegen.

#### Anforderungen

**[LEGAL-I18N-01]** Das Plugin MUSS Einwilligungstexte pro Sprache konfigurierbar machen. Jedes Formular hat pro unterstuetzter Sprache (de_DE, en_US, fr_FR, es_ES, it_IT, sv_SE) einen eigenen Einwilligungstext. Die angezeigte Sprache richtet sich nach der aktuellen WordPress-Locale (`get_locale()`).

**[LEGAL-I18N-02]** Das Plugin MUSS Muster-Vorlagen (LEGAL-TEMPLATE-01 bis 03) in allen 6 Sprachen mitliefern. Diese dienen als Ausgangspunkt — der Admin MUSS sie anpassen koennen. Die Muster-Uebersetzungen werden als `.po/.mo`-Dateien oder direkt in der Formular-Konfiguration bereitgestellt.

**[LEGAL-I18N-03]** Ein Fallback auf Englisch oder Deutsch ist **rechtlich NICHT ausreichend**, wenn die Website in weiteren Sprachen angeboten wird. Ausnahme: Wenn ein Formular nur auf deutschsprachigen Seiten eingebettet ist, genuegt ein deutscher Einwilligungstext — die Sprachpflicht richtet sich nach dem Zielpublikum des konkreten Formulars, nicht nach der Plugin-Konfiguration insgesamt.

**[LEGAL-I18N-04]** Wenn fuer eine aktive Sprache kein Einwilligungstext konfiguriert ist, MUSS das Plugin:
- Im Admin-Bereich eine **Warnung** anzeigen ("Einwilligungstext fuer Sprache X fehlt")
- Im Frontend das Formular **NICHT anzeigen** (Hard-Block) — ein Formular ohne gueltigen Einwilligungstext in der Nutzersprache darf keine Einsendungen annehmen

#### Versionierung pro Sprache

**[LEGAL-I18N-05]** Einwilligungstexte MUESSEN pro Sprache separat versioniert werden. Begruendung: Eine Textaenderung in der deutschen Fassung betrifft nicht die franzoesische — die Versionen entwickeln sich unabhaengig. Schema-Konsequenz:

| Feld | Beschreibung |
|------|-------------|
| `consent_version` | Wird pro Formular **und** pro Sprache gefuehrt |
| `consent_text` | Pro Sprache gespeichert (z.B. als JSON-Objekt `{"de_DE": "...", "en_US": "..."}` oder separate Zeilen) |

Bei jeder Einsendung wird gespeichert: (a) Sprache der Einwilligung, (b) Versionsnummer in dieser Sprache, (c) Wortlaut in dieser Sprache.

**[LEGAL-I18N-06]** Auch der Datenschutzhinweis-Baustein (LEGAL-PRIVACY-01, via `wp_add_privacy_policy_content()`) SOLLTE in allen 6 Sprachen bereitgestellt werden. WordPress unterstuetzt mehrsprachige Privacy-Policy-Suggestions nativ ueber die Locale.

**[LEGAL-I18N-07]** Der Datenschutzerklaerung-Link im Einwilligungstext (`{privacy_policy_url}`) MUSS auf die sprachlich passende Version der Datenschutzerklaerung verweisen. Bei mehrsprachigen WordPress-Installationen (z.B. via WPML, Polylang) wird die Locale-spezifische URL automatisch aufgeloest.

#### Widerrufbarkeit bei mehrsprachigen Einwilligungen (Art. 7 Abs. 3 DSGVO)

**[LEGAL-I18N-08]** Ein Widerruf der Einwilligung bezieht sich auf die **konkrete Einsendung**, nicht auf eine Sprachversion. Begruendung:
- Der Betroffene widerruft seine Einwilligung zu einer bestimmten Datenverarbeitung (= seiner Einsendung), nicht zu einem Textdokument
- Ein Widerruf in Sprache A gilt selbstverstaendlich auch dann, wenn die urspruengliche Einwilligung in Sprache B erteilt wurde
- Die Sprache des Widerrufs ist irrelevant — entscheidend ist die Identifizierung der Einsendung (ueber lookup_hash oder Einsendungs-ID)

**Konsequenz:** Der Widerrufs-Mechanismus (LEGAL-RIGHTS-07) ist sprachunabhaengig und braucht keine sprachspezifische Logik. Die Sprache der Einwilligung wird lediglich fuer den Compliance-Nachweis gespeichert (`consent_locale`).

#### Gemeinsame vs. separate Version-IDs

Eine **gemeinsame Version-ID ueber alle Sprachen hinweg ist NICHT zulaessig**. Begruendung:
- Jede Sprachversion ist ein eigenstaendiger Rechtstext (nicht nur eine Uebersetzung)
- Eine Aenderung am deutschen Text (z.B. Praezisierung der Empfaengerangabe) erfordert keine Neu-Version des franzoesischen Texts
- Gemeinsame IDs wuerden faelschlich suggerieren, dass sich alle Sprachversionen gleichzeitig geaendert haben — das waere fuer den Compliance-Nachweis irrefuehrend
- DPO-Anforderung CONSENT-I18N-03 bestaetigt: "eigenstaendiger Rechtstext mit eigener Version-ID"

#### Zusammenfassung: Verantwortlichkeit

| Aufgabe | Verantwortlich | Plugin-Unterstuetzung |
|---------|---------------|----------------------|
| Muster-Vorlagen in 6 Sprachen | Plugin-Entwickler | MUSS mitliefern (LEGAL-I18N-02) |
| Anpassung/Pruefung der Texte | Plugin-Betreiber | Plugin MUSS Bearbeitungs-UI pro Sprache bereitstellen |
| Juristische Korrektheit der Uebersetzungen | Plugin-Betreiber | Plugin liefert nur Ausgangspunkt, keine Rechtsberatung |
| Sicherstellung, dass alle aktiven Sprachen abgedeckt sind | Plugin (technisch) | Hard-Block bei fehlender Uebersetzung (LEGAL-I18N-04) |

---

## 3. Datenschutzhinweis-Bausteine

### 3.1 Pflichtinformationen nach Art. 13 DSGVO

Ein Formular-Betreiber, der dieses Plugin nutzt, MUSS seine Datenschutzerklaerung um folgende Informationen ergaenzen. Das Plugin SOLLTE diese Bausteine als konfigurierbare Vorlagen bereitstellen und ueber `wp_add_privacy_policy_content()` in die WordPress-Datenschutzseite einbinden.

### 3.2 Muster-Datenschutzhinweis

**[LEGAL-PRIVACY-01]** Folgender Text wird ueber `wp_add_privacy_policy_content()` automatisch vorgeschlagen:

```
KONTAKTFORMULARE / DSGVO-FORMULARE

Auf unserer Website setzen wir das Plugin "WP DSGVO Form" ein, um Ihnen 
Kontakt- und sonstige Formulare zur Verfuegung zu stellen.

Verantwortlicher:
[Wird automatisch aus WordPress-Einstellungen uebernommen]

Welche Daten werden verarbeitet?
Wir verarbeiten die von Ihnen in das jeweilige Formular eingegebenen Daten. 
Welche Daten konkret erhoben werden, ergibt sich aus den Feldern des jeweiligen 
Formulars.

Rechtsgrundlage:
- Bei Kontaktformularen: Ihre Einwilligung (Art. 6 Abs. 1 lit. a DSGVO). 
  Sie koennen diese Einwilligung jederzeit mit Wirkung fuer die Zukunft 
  widerrufen.
- Bei vertragsrelevanten Formularen (z.B. Angebotsanfragen): 
  Vertragsdurchfuehrung bzw. -anbahnung (Art. 6 Abs. 1 lit. b DSGVO).

Verschluesselung:
Ihre Formulardaten werden mit AES-256-Verschluesselung gespeichert. 
Die Entschluesselung erfolgt nur bei berechtigtem Zugriff durch autorisiertes 
Personal.

Empfaenger:
Zugriff auf Ihre Daten haben ausschliesslich die dem jeweiligen Formular 
zugeordneten Empfaenger: [Wird automatisch aus Empfaenger-Konfiguration 
generiert, z.B. "Personalabteilung (Max Mustermann)"]. 
[Falls zutreffend: Der Hosting-Anbieter hat als 
Auftragsverarbeiter ggf. technischen Zugang zu den verschluesselten Daten.]

Speicherdauer:
[Wird pro Formular konfiguriert, z.B.:]
Ihre Daten werden nach [X] Tagen automatisch geloescht, sofern keine 
gesetzlichen Aufbewahrungspflichten entgegenstehen.

CAPTCHA-Dienst:
Zum Schutz vor Spam wird beim Absenden des Formulars ein CAPTCHA-Widget 
von captcha.repaircafe-bruchsal.de geladen. Dabei wird Ihre IP-Adresse 
an den CAPTCHA-Dienstleister uebertragen. Die Verarbeitung erfolgt auf 
Grundlage unseres berechtigten Interesses an der Abwehr von Spam und 
Missbrauch (Art. 6 Abs. 1 lit. f DSGVO). Der CAPTCHA-Dienst speichert 
Ihre IP-Adresse nicht dauerhaft. [Ggf.: Naehere Informationen finden Sie 
in der Datenschutzerklaerung des CAPTCHA-Anbieters unter {captcha_privacy_url}.]

Ihre Rechte:
Sie haben das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16), 
Loeschung (Art. 17), Einschraenkung der Verarbeitung (Art. 18), 
Datenuebertragbarkeit (Art. 20) und Widerspruch (Art. 21). Bei Einwilligung 
haben Sie ein Widerrufsrecht (Art. 7 Abs. 3). Sie haben das Recht, 
Beschwerde bei einer Aufsichtsbehoerde einzulegen (Art. 77).
```

### 3.3 Hinweis zum CAPTCHA-Drittdienst

**[LEGAL-PRIVACY-02]** Der CAPTCHA-Dienst (captcha.repaircafe-bruchsal.de) ist ein Drittdienst. Der Plugin-Betreiber muss pruefen:
- Ist der CAPTCHA-Anbieter Auftragsverarbeiter? Falls ja: Auftragsverarbeitungsvertrag (AVV) nach Art. 28 DSGVO erforderlich
- Serverstandort: EU/EWR? Falls Drittland: Angemessenheitsbeschluss oder Standardvertragsklauseln noetig
- Welche Daten werden an den CAPTCHA-Dienst uebermittelt? (IP-Adresse, Browser-Fingerprint?)

**[LEGAL-PRIVACY-03]** Das Plugin SOLLTE in den Einstellungen einen Hinweis anzeigen: "Bitte pruefen Sie, ob fuer den CAPTCHA-Dienst ein Auftragsverarbeitungsvertrag erforderlich ist."

---

## 4. DSGVO-Bewertung der Supervisor-Rolle

### 4.1 Ausgangslage

Die Rolle `wp_dsgvo_form_supervisor` ermoeglicht einem Benutzer, **alle Einsendungen aller Formulare** einzusehen — im Gegensatz zur Reader-Rolle, die nur auf zugewiesene Formulare Zugriff hat.

### 4.2 Rechtliche Einordnung

**Bewertung: Bedingt zulaessig — aber NUR unter strikten organisatorischen Massnahmen.**

#### Zulaessige Zwecke (abschliessend):

| # | Zweck | Rechtsgrundlage | Beispiel |
|---|-------|----------------|---------|
| 1 | Datenschutzbeauftragter | Art. 6 Abs. 1 lit. c i.V.m. Art. 39 DSGVO | DSB muss Verarbeitungen ueberwachen koennen |
| 2 | Geschaeftsfuehrung/Inhaber | Art. 6 Abs. 1 lit. f (berechtigtes Interesse) | Weisungsbefugnis ueber alle Formulare |
| 3 | IT-Administration | Art. 6 Abs. 1 lit. f | Support, Fehleranalyse, Systemwartung |
| 4 | Interne Revision/Audit | Art. 6 Abs. 1 lit. c oder f | Compliance-Pruefung |

#### NICHT zulaessige Nutzung:

- Pauschal "damit jemand alles sehen kann" ohne dokumentierten Zweck
- Abteilungsleiter, die nur ihre eigenen Formulare betreuen (dafuer: Reader-Rolle mit Zuordnung)
- Praktikanten, Werkstudenten oder andere nicht-privilegierte Personen

### 4.3 Technisch-organisatorische Massnahmen (TOM)

Die folgenden Massnahmen sind **Pflicht**, damit die Supervisor-Rolle DSGVO-konform eingesetzt werden kann. Diese Anforderungen sind bereits in SECURITY_REQUIREMENTS.md als SEC-AUTH-DSGVO-01 bis 03 verankert:

| # | Massnahme | DSGVO-Bezug | Umsetzung im Plugin |
|---|----------|-------------|-------------------|
| 1 | **Zweckdokumentation** | Art. 5 Abs. 1 lit. b (Zweckbindung) | Pflicht-Freitext bei Rollenzuweisung: "Warum braucht dieser User Supervisor-Zugriff?" |
| 2 | **Audit-Protokollierung** | Art. 5 Abs. 2 (Rechenschaftspflicht) | Jeder Lesezugriff des Supervisors wird in Audit-Tabelle protokolliert |
| 3 | **Admin-Warnung** | Art. 25 (Privacy by Design) | Warnhinweis bei Zuweisung der Rolle |
| 4 | **Regelmaessige Ueberpruefung** | Art. 5 Abs. 1 lit. e (analog) | Halbjaehrliche Erinnerung zur Rollenueberpruefung per WP-Cron + Admin-Notice |
| 5 | **Minimale Anzahl** | Art. 5 Abs. 1 lit. c (analog) | Warnung ab >3 Supervisoren, Empfehlung: Max. 2-3 |

### 4.4 Datenschutz-Folgenabschaetzung (DSFA)

**[LEGAL-SUPERVISOR-01]** Die Supervisor-Rolle kann eine DSFA nach Art. 35 DSGVO ausloesen, wenn:
- Formulare besondere Kategorien personenbezogener Daten erfassen (Art. 9 DSGVO: Gesundheit, Religion, etc.)
- Grossflaechige Datenverarbeitung vorliegt (viele Formulare mit vielen Einsendungen)
- Systematische Ueberwachung stattfindet

**Empfehlung:** Das Plugin SOLLTE in der Admin-Oberflaeche einen Hinweis anzeigen, wenn >5 Formulare oder >1000 Einsendungen existieren: "Bei umfangreicher Datenverarbeitung empfehlen wir die Durchfuehrung einer Datenschutz-Folgenabschaetzung (Art. 35 DSGVO)."

### 4.5 Zusammenfassung: Supervisor-Rolle

Die Supervisor-Rolle ist **nicht per se DSGVO-widrig**, aber sie erfordert:
1. Dokumentierten, legitimen Zweck fuer jeden Supervisor
2. Lueckenlose Audit-Protokollierung
3. Regelmaessige Ueberpruefung der Notwendigkeit
4. Minimierung der Anzahl

Ohne diese Massnahmen waere der Zugriff auf alle Einsendungen ein Verstoss gegen den Grundsatz der Datenminimierung (Art. 5 Abs. 1 lit. c DSGVO).

---

## 5. Betroffenenrechte — Technische Umsetzung

### 5.1 Uebersicht der Betroffenenrechte

| Recht | Artikel | Technische Umsetzung | Frist |
|-------|---------|---------------------|-------|
| Auskunft | Art. 15 | Export der eigenen Daten | 1 Monat |
| Berichtigung | Art. 16 | Manuelle Korrektur durch Admin | Ohne ungebuehrliche Verzoegerung |
| Loeschung | Art. 17 | Einzelloeschung + Auto-Loeschung | Ohne ungebuehrliche Verzoegerung |
| Einschraenkung | Art. 18 | Sperr-Flag auf Einsendung | Ohne ungebuehrliche Verzoegerung |
| Datenuebertragbarkeit | Art. 20 | Export als JSON/CSV | 1 Monat |
| Widerspruch | Art. 21 | Loeschung der Daten | Ohne ungebuehrliche Verzoegerung |
| Widerruf | Art. 7 Abs. 3 | Loeschung der Daten | Ohne ungebuehrliche Verzoegerung |

### 5.2 Detaillierte technische Anforderungen

#### a) Recht auf Auskunft (Art. 15 DSGVO)

**[LEGAL-RIGHTS-01]** Das Plugin MUSS die WordPress Privacy Data Exporter-Hooks implementieren:

```php
add_filter('wp_privacy_personal_data_exporters', function($exporters) {
    $exporters['dsgvo-form'] = [
        'exporter_friendly_name' => 'DSGVO Formular-Einsendungen',
        'callback' => [PrivacyHandler::class, 'exportPersonalData'],
    ];
    return $exporters;
});
```

**Funktionsweise:** WordPress stellt unter Werkzeuge > Personenbezogene Daten exportieren eine Suche nach E-Mail-Adresse bereit. Das Plugin durchsucht alle entschluesselten Einsendungen nach E-Mail-Feldern und exportiert die zugehoerigen Daten.

**Problem:** Da Daten verschluesselt sind, muss das Plugin ALLE Einsendungen entschluesseln und nach der E-Mail-Adresse suchen. Dies ist bei vielen Einsendungen performance-kritisch.

**Loesung:** 
**[LEGAL-RIGHTS-02]** Zu jeder Einsendung einen `lookup_hash` speichern: HMAC-SHA256 der normalisierten E-Mail-Adresse (lowercase, trimmed), berechnet mit einem vom Encryption-Key abgeleiteten HMAC-Key. Dieses Feld ist NICHT verschluesselt und dient ausschliesslich der Zuordnung fuer Betroffenenrechte. Ein einfacher SHA-256-Hash waere per Dictionary-Attack reversibel und darf NICHT verwendet werden.

#### b) Recht auf Loeschung (Art. 17 DSGVO)

**[LEGAL-RIGHTS-03]** Das Plugin MUSS folgende Loeschmechanismen bereitstellen:

1. **Einzelloeschung:** Admin/Empfaenger kann einzelne Einsendungen unwiderruflich loeschen (kein Soft-Delete, echtes DELETE + Dateien vom Filesystem loeschen)
2. **Bulk-Loeschung:** Alle Einsendungen eines bestimmten Betroffenen loeschen (ueber E-Mail-Suche)
3. **Auto-Loeschung:** Konfigurierbare Aufbewahrungsfrist pro Formular (Cron-Job)
4. **WordPress Privacy Eraser:** Integration in WordPress' eingebautes Loeschwerkzeug

```php
add_filter('wp_privacy_personal_data_erasers', function($erasers) {
    $erasers['dsgvo-form'] = [
        'eraser_friendly_name' => 'DSGVO Formular-Einsendungen',
        'callback' => [PrivacyHandler::class, 'erasePersonalData'],
    ];
    return $erasers;
});
```

**[LEGAL-RIGHTS-04]** Bei Loeschung MUESSEN folgende Daten entfernt werden:
- Verschluesselte Einsendungsdaten
- Zugehoerige Dateien (physisch vom Filesystem)
- Einwilligungsdaten (es sei denn, gesetzliche Aufbewahrungspflicht besteht)
- Lookup-Hash
- Audit-Log-Eintraege zu dieser Einsendung (nach 1 Jahr oder bei Loeschanfrage)

#### c) Recht auf Datenuebertragbarkeit (Art. 20 DSGVO)

**[LEGAL-RIGHTS-05]** Das Plugin MUSS einen Export in maschinenlesbarem Format anbieten:
- Format: JSON (strukturiert) und/oder CSV
- Inhalt: Alle Einsendungsdaten eines Betroffenen, entschluesselt
- Zugang: Ueber WordPress Privacy Tools oder Admin-Export-Funktion

#### d) Recht auf Einschraenkung der Verarbeitung (Art. 18 DSGVO)

**[LEGAL-RIGHTS-06]** Das Plugin SOLLTE eine Moeglichkeit bieten, Einsendungen zu "sperren":
- Gesperrte Einsendungen bleiben gespeichert, werden aber nicht mehr an Empfaenger angezeigt
- Gesperrte Einsendungen werden von der Auto-Loeschung ausgenommen (bis Klaerung)
- Admin kann die Sperre aufheben oder die Einsendung endgueltig loeschen

**Technisch:** Ein `is_restricted` Flag in der Submissions-Tabelle.

#### e) Widerruf der Einwilligung (Art. 7 Abs. 3 DSGVO)

**[LEGAL-RIGHTS-07]** Bei Widerruf der Einwilligung:
- Daten MUESSEN geloescht werden (sofern keine andere Rechtsgrundlage greift)
- Der Widerruf darf nicht schwieriger sein als die Einwilligung
- Empfehlung: Kontaktinformation fuer Widerruf in der Bestaetigung und im Einwilligungstext angeben

### 5.3 Zusammenspiel mit WordPress Privacy Tools

**[LEGAL-RIGHTS-08]** Das Plugin MUSS sich in folgende WordPress-native Mechanismen integrieren:

| WordPress-Feature | Plugin-Integration |
|-------------------|-------------------|
| `wp_add_privacy_policy_content()` | Datenschutzhinweis-Bausteine automatisch vorschlagen |
| `wp_privacy_personal_data_exporters` | Formular-Einsendungen eines Betroffenen exportieren |
| `wp_privacy_personal_data_erasers` | Formular-Einsendungen eines Betroffenen loeschen |

---

## 6. Haftungsfragen

### 6.1 Haftungsverteilung

| Partei | Haftet fuer | DSGVO-Artikel |
|--------|------------|---------------|
| **Plugin-Entwickler** | Technische Maengel: fehlerhafte Verschluesselung, SQL-Injection, XSS-Luecken, defekte Loeschfunktion | Art. 82 Abs. 4 (Haftung des Auftragsverarbeiters, falls zutreffend) |
| **Plugin-Betreiber** (Website-Inhaber) | Korrekte Konfiguration, Einholung der Einwilligung, Datenschutzerklaerung, Betroffenenrechte bearbeiten | Art. 82 Abs. 2 (Haftung des Verantwortlichen) |
| **Hosting-Anbieter** | Serversicherheit, Backups, physischer Zugang | Art. 28 (Auftragsverarbeiter) |

### 6.2 Haftungsrisiken fuer den Plugin-Entwickler

**[LEGAL-LIABILITY-01]** Das Plugin-Team muss folgende Haftungsrisiken minimieren:

1. **Verschluesselungsfehler:** Wenn die AES-256-Verschluesselung fehlerhaft implementiert ist und Daten im Klartext gespeichert werden, haftet der Entwickler mit.
   - **Massnahme:** Umfangreiche Unit-Tests fuer EncryptionService, Code-Review durch security-expert

2. **Fehlende Loeschfunktion:** Wenn die Auto-Loeschung nicht funktioniert und Daten ueber die konfigurierte Frist hinaus gespeichert bleiben.
   - **Massnahme:** Integrationstests fuer Cron-Job, Monitoring

3. **Sicherheitsluecken:** SQL-Injection, XSS, CSRF, die zu Datenabfluss fuehren.
   - **Massnahme:** Code-Review-Checkliste (SECURITY_REQUIREMENTS.md), regelmaessige Audits

4. **Irregulaere Einwilligungsmechanik:** Wenn das Plugin die Einwilligung technisch falsch erfasst (z.B. vorausgefuellte Checkbox, fehlende Versionierung).
   - **Massnahme:** Default-Checkbox ist NICHT angehakt, Versionierung implementieren

### 6.3 Haftungsbegrenzung fuer den Plugin-Betreiber

**[LEGAL-LIABILITY-02]** Das Plugin SOLLTE den Betreiber auf folgende Pflichten hinweisen (z.B. in einem "Setup-Assistenten" oder in der Admin-Oberflaeche):

1. Datenschutzerklaerung anpassen (Bausteine aus Abschnitt 3)
2. Aufbewahrungsfristen festlegen (nicht bei 0 = "nie loeschen" belassen)
3. Empfaenger sorgfaeltig auswaehlen (Datenminimierung)
4. Einwilligungstext anpassen und pruefen lassen
5. Regelmaessig pruefen, ob die Verarbeitung noch erforderlich ist
6. Ggf. Auftragsverarbeitungsvertrag mit Hosting-Anbieter abschliessen
7. Ggf. Datenschutz-Folgenabschaetzung durchfuehren

### 6.4 Haftungsausschluss im Plugin

**[LEGAL-LIABILITY-03]** Das Plugin MUSS einen klaren Haftungsausschluss enthalten:

```
HAFTUNGSAUSSCHLUSS

Dieses Plugin stellt technische Werkzeuge zur DSGVO-konformen Verarbeitung 
von Formulardaten bereit. Der Plugin-Entwickler uebernimmt keine Haftung fuer:

- Die rechtliche Korrektheit der vom Betreiber konfigurierten Einwilligungstexte
- Die Vollstaendigkeit der Datenschutzerklaerung des Betreibers
- Die Einhaltung von Aufbewahrungsfristen durch den Betreiber
- Datenverarbeitungen, die ueber den vorgesehenen Einsatzzweck hinausgehen

Der Betreiber ist als Verantwortlicher im Sinne der DSGVO verpflichtet, 
die Rechtmaessigkeit der Datenverarbeitung eigenverantwortlich sicherzustellen. 
Wir empfehlen die Konsultation eines spezialisierten Rechtsanwalts.
```

---

## 7. Technische Anforderungen an das Plugin

### 7.1 Zusammenfassung der rechtlich gebotenen Features

Aus den vorangegangenen Abschnitten ergeben sich folgende technische Anforderungen:

| # | Anforderung | Abschnitt | Prioritaet |
|---|-------------|----------|-----------|
| 1 | Rechtsgrundlage pro Formular waehlbar (Einwilligung/Vertrag) | 1.3 | MUSS |
| 2 | Einwilligungs-Checkbox (Pflichtfeld, nicht vorausgefuellt) | 1.2a | MUSS |
| 2a | **Hard-Block: Serverseitige Ablehnung ohne consent_given=true** | 1.2a | MUSS |
| 2b | **Validierungsreihenfolge: Consent vor CAPTCHA** | 1.2a | MUSS |
| 3 | Einwilligungstext konfigurierbar + versioniert | 2.2-2.5 | MUSS |
| 4 | Muster-Vorlagen fuer Einwilligungstexte | 2.2-2.4 | SOLLTE |
| 5 | Datenschutzhinweis via `wp_add_privacy_policy_content()` | 3.2 | MUSS |
| 6 | WordPress Privacy Exporter (Art. 15) | 5.2a | MUSS |
| 7 | WordPress Privacy Eraser (Art. 17) | 5.2b | MUSS |
| 8 | Lookup-Hash fuer E-Mail-Suche | 5.2a | MUSS |
| 9 | Einzelloeschung + Bulk-Loeschung + Auto-Loeschung | 5.2b | MUSS |
| 10 | Export als JSON/CSV (Art. 20) | 5.2c | MUSS |
| 11 | Sperr-Flag fuer Einschraenkung (Art. 18) | 5.2d | SOLLTE |
| 12 | Zweckdokumentation bei Supervisor-Zuweisung | 4.3 | MUSS |
| 13 | Audit-Log fuer Supervisor-Zugriffe | 4.3 | MUSS |
| 14 | DSFA-Hinweis ab bestimmter Datenmenge | 4.4 | SOLLTE |
| 15 | Haftungsausschluss in Plugin-Beschreibung | 6.4 | MUSS |
| 16 | Setup-Hinweise fuer Betreiberpflichten | 6.3 | SOLLTE |
| 17 | Hinweis auf AVV-Pflicht fuer CAPTCHA-Dienst | 3.3 | SOLLTE |
| 18 | **Einwilligungstexte pro Sprache konfigurierbar** | 2.6 | MUSS |
| 19 | **Muster-Vorlagen in allen 6 Sprachen mitliefern** | 2.6 | MUSS |
| 20 | **Hard-Block bei fehlender Uebersetzung fuer aktive Sprache** | 2.6 | MUSS |
| 21 | **Separate Versionierung pro Sprache** | 2.6 | MUSS |
| 22 | Datenschutzhinweis-Baustein in allen 6 Sprachen | 2.6 | SOLLTE |

### 7.2 Aenderungen am Datenbank-Schema

Basierend auf den rechtlichen Anforderungen werden folgende Schema-Ergaenzungen empfohlen:

#### Ergaenzung `{prefix}dsgvo_forms`:

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `legal_basis` | `ENUM('consent','contract') DEFAULT 'consent'` | Rechtsgrundlage fuer dieses Formular |
| `purpose` | `VARCHAR(500)` | Zweck der Datenverarbeitung (fuer Einwilligungstext) |
| `consent_text` | `TEXT` | Aktueller Einwilligungstext — bei Mehrsprachigkeit als JSON-Objekt `{"de_DE": "...", "en_US": "...", ...}` |
| `consent_version` | `INT UNSIGNED DEFAULT 1` | Versionsnummer des Einwilligungstexts — bei Mehrsprachigkeit als JSON-Objekt `{"de_DE": 3, "en_US": 2, ...}` |

#### Ergaenzung `{prefix}dsgvo_submissions`:

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `consent_text_version` | `INT UNSIGNED` | Version des Einwilligungstexts zum Zeitpunkt der Einsendung (in der jeweiligen Sprache) |
| `consent_timestamp` | `DATETIME` | Zeitpunkt der Einwilligung |
| `consent_locale` | `VARCHAR(10)` | Sprache der Einwilligung (z.B. 'de_DE', 'en_US') — fuer Compliance-Nachweis |
| `lookup_hash` | `VARCHAR(64)` | HMAC-SHA256 der E-Mail-Adresse (fuer Betroffenenrechte) |
| `is_restricted` | `TINYINT(1) DEFAULT 0` | Einschraenkung der Verarbeitung (Art. 18) |

#### Ergaenzung `{prefix}dsgvo_form_recipients` (Supervisor):

| Spalte | Typ | Beschreibung |
|--------|-----|-------------|
| `role_justification` | `TEXT` | Zweckdokumentation bei Supervisor-Zuweisung |

---

## 8. Checkliste fuer Plugin-Betreiber

Das Plugin SOLLTE nach Installation eine Checkliste anzeigen, die der Betreiber durcharbeiten muss:

- [ ] Datenschutzerklaerung um Formular-Abschnitt ergaenzt
- [ ] Einwilligungstexte pro Formular angepasst und von Rechtsanwalt geprueft
- [ ] Aufbewahrungsfristen fuer jedes Formular festgelegt
- [ ] Empfaenger sorgfaeltig ausgewaehlt (Datenminimierung beachtet)
- [ ] Verschluesselungsschluessel (DSGVO_FORM_ENCRYPTION_KEY) sicher in wp-config.php hinterlegt
- [ ] Auftragsverarbeitungsvertrag mit Hosting-Anbieter geprueft
- [ ] Ggf. Auftragsverarbeitungsvertrag mit CAPTCHA-Anbieter geprueft
- [ ] Verarbeitungsverzeichnis aktualisiert
- [ ] Ggf. Datenschutz-Folgenabschaetzung durchgefuehrt
- [ ] Regelmaessige Pruefung der Supervisor-Zuweisungen eingeplant

---

## Versions-Historie

| Version | Datum | Aenderung |
|---------|-------|----------|
| 1.0 | 2026-04-17 | Initiale Legal Requirements erstellt |
| 1.1 | 2026-04-17 | Korrekturen nach Security-Expert-Review: IP-Hash-Referenzen entfernt (kein IP-Hash in DB), LEGAL-RIGHTS-02 auf ausschliesslich HMAC-SHA256 korrigiert, Schluesselname auf DSGVO_FORM_ENCRYPTION_KEY korrigiert |
| 1.2 | 2026-04-17 | Auftraggeber-Entscheidung: Hard-Block bei fehlender Einwilligung (LEGAL-CONSENT-06). Serverseitige Ablehnung (HTTP 422) statt Soft-Warnung. Security-Expert-Abstimmung: v1.5.1 konsistent |
| 1.3 | 2026-04-17 | DPO-Feedback eingearbeitet: {recipient_description} praezisiert (konkrete Namen/Rollen statt generischer Beschreibung, Art. 13 Abs. 1 lit. e). Datenschutzhinweis-Muster (3.2) entsprechend angepasst |
| 1.4 | 2026-04-17 | CAPTCHA-Datenschutzhinweis (LEGAL-PRIVACY-01) praezisiert: IP-Uebertragung beim Widget-Laden explizit erwaehnt (SEC-CAP-12), Rechtsgrundlage Art. 6 Abs. 1 lit. f ergaenzt, Platzhalter {captcha_privacy_url} hinzugefuegt |
| 1.5 | 2026-04-17 | DPO-Abstimmung: Validierungsreihenfolge (LEGAL-CONSENT-07) — Consent-Pruefung VOR CAPTCHA-Verify, da ohne Einwilligung keine Rechtsgrundlage fuer IP-Uebertragung an CAPTCHA-Dienst |
| 1.6 | 2026-04-17 | Mehrsprachigkeit (i18n): Neuer Abschnitt 2.6 mit LEGAL-I18N-01 bis 06. Einwilligungstexte pro Sprache pflichtmaessig, separate Versionierung, Hard-Block bei fehlender Uebersetzung. Schema-Ergaenzung: consent_locale in Submissions, JSON-Format fuer consent_text/consent_version in Forms |
| 1.7 | 2026-04-17 | DPO-Abstimmung i18n: LEGAL-I18N-07 (sprachspezifischer DSE-Link), LEGAL-I18N-08 (Widerruf sprachunabhaengig). Klarstellung: Gemeinsame Version-ID ueber Sprachen nicht zulaessig. Konsistenz mit CONSENT-I18N-01 bis 05 (DATA_PROTECTION.md v1.4) bestaetigt |
| 1.8 | 2026-04-17 | Korrektur: Feldname bleibt `is_restricted` (nicht `is_locked`). Umbenennung aus v1.8-Entwurf rueckgaengig gemacht — `is_restricted` ist der verbindliche Name (Art. 18 DSGVO, SEC-DSGVO-13) |
