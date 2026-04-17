# Security Requirements — wp-dsgvo-form

**Status:** VERBINDLICH — Alle Anforderungen MÜSSEN vor dem Release umgesetzt sein.
**Version:** 1.6 | **Erstellt:** 2026-04-17 | **Autor:** security-expert
**Review-Status:** Pending architect approval

---

## Inhaltsverzeichnis

1. [DSGVO-Compliance](#1-dsgvo-compliance)
2. [Verschlüsselung (AES-256-GCM mit Envelope Encryption)](#2-verschlüsselung-aes-256-gcm-mit-envelope-encryption)
3. [Input-Validierung & Sanitization](#3-input-validierung--sanitization)
4. [CSRF-Schutz](#4-csrf-schutz)
5. [XSS-Prävention](#5-xss-prävention)
6. [CAPTCHA-Validierung](#6-captcha-validierung)
7. [Authentifizierung & Autorisierung](#7-authentifizierung--autorisierung)
8. [SQL-Injection-Schutz](#8-sql-injection-schutz)
9. [Datei-Upload-Sicherheit](#9-datei-upload-sicherheit)
10. [E-Mail-Sicherheit](#10-e-mail-sicherheit)
11. [Allgemeine Plugin-Sicherheit](#11-allgemeine-plugin-sicherheit)

---

## 1. DSGVO-Compliance

### 1.1 Datensparsamkeit (Art. 5 Abs. 1 lit. c DSGVO)
- **[SEC-DSGVO-01]** Nur Felder speichern, die im Formular konfiguriert sind. Keine zusätzlichen Metadaten (IP-Adresse, User-Agent, etc.) erfassen, es sei denn vom Admin explizit aktiviert.
- **[SEC-DSGVO-02]** IP-Adresse nur für CAPTCHA-Validierung verwenden, danach sofort verwerfen — NICHT in der DB speichern.
- **[SEC-DSGVO-03]** Timestamps der Einsendung speichern (nötig für Löschfristen), aber keine Tracking-Daten.

### 1.2 Einwilligung (Art. 6, Art. 7 DSGVO)
- **[SEC-DSGVO-04]** Jedes Formular mit Rechtsgrundlage "Einwilligung" (SEC-DSGVO-14, lit. a) MUSS ein Pflicht-Checkbox-Feld für die Datenschutzeinwilligung enthalten. Formular-Submission ohne aktive Einwilligung (`consent_given != true`) serverseitig mit HTTP 422 ablehnen. **Hard-Block:** Keine Daten werden gespeichert, verarbeitet oder zwischengespeichert, wenn die Einwilligung fehlt — weder in DB, noch in Logs, noch in Transients. Client-seitige Validierung ist ergänzend (UX), serverseitig ist maßgeblich. (Korrespondiert mit LEGAL-CONSENT-06.)
- **[SEC-DSGVO-05]** Der Einwilligungstext MUSS vom Admin pro Formular konfigurierbar sein und einen Link zur Datenschutzerklärung enthalten.
- **[SEC-DSGVO-06]** Die Einwilligung MUSS zusammen mit der Einsendung gespeichert werden:
  - `consent_timestamp` (DATETIME) — Zeitpunkt der Einwilligung
  - `consent_text_version` (INT) — Versionsnummer des Einwilligungstexts
  - Der **Wortlaut** des Einwilligungstexts wird in der Formular-Konfiguration versioniert gespeichert (`consent_text` + `consent_version` in der Forms-Tabelle). Nur die Versionsnummer reicht nicht, da der Text später geändert werden kann.
  - Diese Compliance-Daten werden NICHT verschlüsselt gespeichert — sie müssen für Nachweiszwecke jederzeit lesbar sein (konsistent mit SEC-ENC-11).

### 1.3 Recht auf Löschung (Art. 17 DSGVO)
- **[SEC-DSGVO-07]** Admin-Oberfläche MUSS eine Funktion zum unwiderruflichen Löschen einzelner Einsendungen bieten (echtes DELETE, kein Soft-Delete).
- **[SEC-DSGVO-08]** Automatische Löschung nach konfigurierbarer Aufbewahrungsfrist (Default: 90 Tage). Cron-Job zum regelmäßigen Aufräumen.
- **[SEC-DSGVO-09]** Bulk-Löschung aller Einsendungen eines Formulars MUSS möglich sein.

### 1.4 Recht auf Datenauskunft und -übertragbarkeit (Art. 15, Art. 20 DSGVO)
- **[SEC-DSGVO-10]** Export einzelner oder aller Einsendungen als CSV/JSON (entschlüsselt) für berechtigte Admins.
- **[SEC-DSGVO-11]** Integration in WordPress' eingebaute Datenschutz-Export- und -Lösch-Tools (`wp_add_privacy_policy_content`, Exporter/Eraser-Hooks).

### 1.5 Einschränkung der Verarbeitung (Art. 18 DSGVO)
- **[SEC-DSGVO-13]** Die Submissions-Tabelle MUSS ein `is_restricted`-Flag (BOOLEAN, Default: FALSE) enthalten. Wird die Verarbeitung einer Einsendung gemäß Art. 18 DSGVO eingeschränkt, wird dieses Flag auf TRUE gesetzt.
  - Eingeschränkte Einsendungen werden weiterhin gespeichert (verschlüsselt), aber:
    - NICHT in der Standard-Ansicht angezeigt (nur über separaten Filter "Eingeschränkte Einsendungen")
    - NICHT in CSV/JSON-Exporte einbezogen (es sei denn, explizit ausgewählt)
    - Lese-Zugriff wird im Audit-Log mit `action = 'view_restricted'` protokolliert
  - Nur Benutzer mit `dsgvo_form_manage` können das Flag setzen/entfernen
  - Beim Setzen/Entfernen: Audit-Log-Eintrag mit `action = 'restrict'` / `'unrestrict'`
  - **Auto-Löschung:** Eingeschränkte Einsendungen (`is_restricted = TRUE`) werden von der automatischen Löschung (SEC-DSGVO-08) ausgenommen. Art. 18 DSGVO verlangt, dass eingeschränkte Daten aufbewahrt, aber nicht verarbeitet werden.

### 1.6 Rechtsgrundlage pro Formular
- **[SEC-DSGVO-14]** Die Rechtsgrundlage MUSS pro Formular konfigurierbar sein. Unterstützte Rechtsgrundlagen:
  - **Art. 6 Abs. 1 lit. a (Einwilligung):** Standard-Fall. Erfordert Checkbox-Pflichtfeld (SEC-DSGVO-04).
  - **Art. 6 Abs. 1 lit. b (Vertragserfüllung):** Kein Einwilligungs-Checkbox nötig, aber Hinweistext auf Vertragserfüllung als Rechtsgrundlage.
  - Die gewählte Rechtsgrundlage wird in der Formular-Konfiguration gespeichert und beeinflusst:
    - Ob das Einwilligungs-Checkbox-Feld Pflicht ist (lit. a) oder nicht (lit. b)
    - Den Text in der Datenschutzerklärung (automatisch generiert via `wp_add_privacy_policy_content()`)
    - Die Darstellung im Verarbeitungsverzeichnis
  - **Hinweis:** Bei Art. 6 Abs. 1 lit. b entfällt das Widerrufsrecht der Einwilligung, aber Recht auf Löschung (Art. 17) besteht weiterhin nach Wegfall des Zwecks.

### 1.7 Verarbeitungsverzeichnis
- **[SEC-DSGVO-12]** Das Plugin MUSS einen vorausgefüllten Text für das Verarbeitungsverzeichnis bereitstellen, der in die WordPress-Datenschutzseite eingebunden wird.

---

## 2. Verschlüsselung (AES-256-GCM mit Envelope Encryption)

### 2.1 Schlüsselhierarchie (Envelope Encryption)

Das Plugin verwendet **Envelope Encryption** mit einer dreistufigen Schlüsselhierarchie:

| Ebene | Schlüssel | Lebensdauer | Speicherort |
|-------|-----------|-------------|-------------|
| **KEK** (Key Encryption Key) | 1 pro Installation | Dauerhaft | `wp-config.php` als `DSGVO_FORM_ENCRYPTION_KEY` |
| **Form-DEK** (Data Encryption Key) | 1 pro Formular | Formular-Lebensdauer | `dsgvo_forms.encrypted_dek` + `dek_iv` (verschlüsselt mit KEK) |
| **File-DEK** | 1 pro Datei-Upload | Datei-Lebensdauer | `dsgvo_submission_files.encrypted_key` (verschlüsselt mit Form-DEK) |

**Verschlüsselungskette:**
- Submissions: `KEK → Form-DEK → JSON-Blob der Formularfelder`
- Datei-Uploads: `KEK → Form-DEK → File-DEK → Datei-Inhalt`
- HMAC-Key: Abgeleitet vom KEK via HMAC-SHA256 (SEC-ENC-14)

**Sicherheitsvorteile:**
- Kompromittierung eines Form-DEK betrifft nur Daten eines Formulars
- Key-Rotation erfordert nur Re-Wrapping der DEKs, nicht Re-Encryption aller Daten
- Per-File-DEK isoliert Datei-Uploads zusätzlich

### 2.2 KEK-Verwaltung (Master Key)
- **[SEC-ENC-01]** KEK DARF NICHT im Code hardcoded sein.
- **[SEC-ENC-02]** KEK wird bei Plugin-Aktivierung automatisch generiert (`base64_encode(random_bytes(32))`) und als Konstante `DSGVO_FORM_ENCRYPTION_KEY` in `wp-config.php` gespeichert. Falls bereits vorhanden, nicht überschreiben. Der Konstantenname ist verbindlich — keine Abweichungen (z.B. NICHT `DSGVO_FORM_MASTER_KEY`).
- **[SEC-ENC-03]** KEK MUSS genau 256 Bit (32 Bytes raw) Entropie haben. Base64-kodiert in `wp-config.php` (44 Zeichen). Generierung über `random_bytes(32)`.
- **[SEC-ENC-04]** Fail-closed: Falls `DSGVO_FORM_ENCRYPTION_KEY` nicht definiert oder ungültig ist, MUSS die gesamte Plugin-Funktionalität DEAKTIVIERT werden. Admin-Warnung anzeigen. Niemals auf einen Default-Key zurückfallen.

### 2.3 DEK-Verwaltung (Data Encryption Keys)
- **[SEC-ENC-16]** Bei Formular-Erstellung MUSS ein neuer Form-DEK generiert werden: `random_bytes(32)`.
- **[SEC-ENC-17]** Der Form-DEK wird mit dem KEK verschlüsselt (AES-256-GCM) und als `encrypted_dek` + `dek_iv` in der Forms-Tabelle gespeichert. Format: `base64(ciphertext + auth_tag)` für `encrypted_dek`, `base64(iv)` für `dek_iv`.
- **[SEC-ENC-18]** Der Form-DEK wird bei jedem Zugriff aus der DB gelesen und mit dem KEK entschlüsselt. Er wird NICHT gecacht — kein Klartext-DEK im RAM über die Request-Dauer hinaus.
- **[SEC-ENC-19]** File-DEK: Jeder Datei-Upload erhält einen eigenen DEK (`random_bytes(32)`), der mit dem Form-DEK (nicht dem KEK) verschlüsselt wird. Format: `base64(iv + auth_tag + ciphertext)` als einzelner Blob in `dsgvo_submission_files.encrypted_key`.

### 2.4 Key-Rotation / Re-Encryption
- **[SEC-ENC-15]** Key-Kompromittierung / Re-Encryption: Da kein automatisches Key-Rotation-Feature implementiert wird (Overengineering für ein WP-Plugin), MUSS die Plugin-Dokumentation einen manuellen Re-Encryption-Prozess beschreiben.
  
  **Vorteil durch Envelope Encryption:** Bei KEK-Kompromittierung müssen nur die Form-DEKs neu verschlüsselt werden (Re-Wrapping), nicht die eigentlichen Submission-Daten. Das reduziert die Downtime erheblich.
  
  **Prozess bei KEK-Kompromittierung:**
  1. Neuen KEK generieren: `php -r "echo base64_encode(random_bytes(32));"`
  2. WP-CLI- oder Admin-Skript bereitstellen, das:
     a. Alle Form-DEKs mit dem alten KEK entschlüsselt
     b. Alle Form-DEKs mit dem neuem KEK neu verschlüsselt (Re-Wrapping)
     c. **Die Submissions und Dateien selbst bleiben unverändert** (sie sind mit ihrem DEK verschlüsselt, nicht direkt mit dem KEK)
  3. Alten KEK in `wp-config.php` durch neuen ersetzen
  4. `email_lookup_hash` aller Submissions neu berechnen (da der HMAC-Key vom KEK abgeleitet wird, SEC-ENC-14)
  
  **Prozess bei Form-DEK-Kompromittierung** (einzelnes Formular betroffen):
  1. Neuen Form-DEK generieren
  2. Alle Submissions des betroffenen Formulars mit altem DEK entschlüsseln und mit neuem DEK neu verschlüsseln
  3. Alle File-DEKs des Formulars mit neuem Form-DEK re-wrappen
  4. Neuen Form-DEK mit KEK verschlüsseln und in DB speichern
  
  - **Hinweis:** Beide Prozesse erfordern Downtime und sind Incident-Response-Verfahren, keine regulären Features. Der Admin-Bereich MUSS einen Hinweis enthalten, dass bei Verdacht auf Key-Kompromittierung sofort der Sicherheitsbeauftragte kontaktiert werden soll.

### 2.5 Verschlüsselungs-Implementierung
- **[SEC-ENC-05]** Algorithmus: AES-256-GCM (authentifizierte Verschlüsselung) auf allen Ebenen (KEK→DEK, DEK→Data, DEK→File-DEK→File). Kein CBC ohne HMAC.
- **[SEC-ENC-06]** Jede Verschlüsselungsoperation MUSS einen eigenen, zufälligen IV erhalten: `random_bytes(12)` für GCM (96 Bit). Kein IV-Reuse zwischen Operationen.
- **[SEC-ENC-07]** Storage-Formate (drei Packing-Varianten, je nach verfügbaren DB-Spalten):
  
  | Kontext | Format | DB-Spalten |
  |---------|--------|------------|
  | Submissions (Formularfelder) | Separate base64: ciphertext, iv, auth_tag | 3 Spalten in `dsgvo_submissions` |
  | Form-DEK (verschlüsselt mit KEK) | `base64(ciphertext + tag)` + `base64(iv)` | 2 Spalten in `dsgvo_forms` |
  | File-DEK (verschlüsselt mit Form-DEK) | `base64(iv + tag + ciphertext)` | 1 Spalte in `dsgvo_submission_files` |
  
  Alle drei Formate sind kryptographisch äquivalent (AES-256-GCM mit 12-Byte-IV und 16-Byte-Tag). Die unterschiedliche Packung ergibt sich aus der Anzahl verfügbarer DB-Spalten pro Entität.
- **[SEC-ENC-08]** PHP-Implementierung via `openssl_encrypt()` / `openssl_decrypt()` mit `aes-256-gcm`. Tag-Länge: 16 Bytes.
- **[SEC-ENC-09]** Entschlüsselung NUR serverseitig bei expliziter Anzeige/Export. Niemals Klartext-Daten im Browser-Cache oder in REST-Responses cachen.

### 2.6 Was wird verschlüsselt
- **[SEC-ENC-10]** ALLE Formularfeld-Werte (Name, E-Mail, Telefon, Freitext, etc.) werden als ein verschlüsselter JSON-Blob pro Einsendung gespeichert. Verschlüsselung erfolgt mit dem **Form-DEK** des jeweiligen Formulars (nicht direkt mit dem KEK).
- **[SEC-ENC-11]** Metadaten (Formular-ID, Zeitstempel, Einwilligungs-Info) werden NICHT verschlüsselt (nötig für Queries und Compliance-Nachweise).
- **[SEC-ENC-12]** Hochgeladene Dateien werden mit einem **per-File-DEK** verschlüsselt. Der File-DEK wird mit dem Form-DEK des zugehörigen Formulars verschlüsselt und in `dsgvo_submission_files.encrypted_key` gespeichert. Dadurch ist jede Datei kryptographisch isoliert — Kompromittierung eines File-DEK betrifft nur eine Datei.

### 2.7 Lookup-Hash für Datenschutzanfragen
- **[SEC-ENC-13]** Lookup-Hash: Für jede Submission wird ein HMAC-SHA256-Hash der E-Mail-Adresse als `email_lookup_hash` (unverschlüsselt) in der Submissions-Tabelle gespeichert. Dieser ermöglicht die Suche nach Einsendungen einer bestimmten Person für Auskunfts-, Lösch- und Portabilitätsanfragen (Art. 15, 17, 20 DSGVO), ohne alle Einsendungen entschlüsseln zu müssen.
  - E-Mail-Adresse vor Hashing normalisieren: `strtolower(trim($email))`
  - NUR HMAC-SHA256, KEIN reines SHA-256 (zu leicht reversibel bei E-Mail-Adressen via Dictionary-Angriff)
  - Lookup-Hash wird nur bei Submission-Erstellung gesetzt und danach nicht mehr aktualisiert
  - Der Hash erlaubt keine Wiederherstellung der E-Mail-Adresse, nur Vergleich
- **[SEC-ENC-14]** Key Derivation für HMAC-Schlüssel: Der HMAC-Schlüssel MUSS vom KEK abgeleitet werden — NICHT den KEK direkt als HMAC-Key verwenden. Ableitung über HMAC-SHA256 mit festem Context-String:
  ```php
  // In KeyManager::derive_hmac_key():
  // HMAC-Key vom KEK ableiten (NICHT den KEK direkt verwenden!)
  $hmac_key = hash_hmac('sha256', 'dsgvo_form_lookup_hash_key', $kek, true);
  
  // In KeyManager::calculate_lookup_hash():
  $email_normalized = strtolower(trim($email));
  $lookup_hash = hash_hmac('sha256', $email_normalized, $hmac_key);
  
  // In DB speichern (unverschlüsselt, als HEX-String)
  $wpdb->update($table, ['email_lookup_hash' => $lookup_hash], ['id' => $submission_id]);
  ```
  **Begründung:** Separate Schlüssel für unterschiedliche kryptographische Operationen (Encryption vs. MAC) ist Best Practice und verhindert Key-Reuse-Schwachstellen.

---

## 3. Input-Validierung & Sanitization

### 3.1 Allgemeine Regeln
- **[SEC-VAL-01]** ALLE Eingaben serverseitig validieren. Client-seitige Validierung ist nur UX-Hilfe, niemals Security-Maßnahme.
- **[SEC-VAL-02]** Whitelist-Ansatz: Nur erwartete Felder und Datentypen akzeptieren. Unbekannte Felder verwerfen.
- **[SEC-VAL-03]** Maximale Feldlängen definieren und durchsetzen (konfigurierbar pro Feld, Hard-Limit bei 10.000 Zeichen für Text/Textarea).

### 3.2 Feldtyp-spezifische Validierung
- **[SEC-VAL-04]** **E-Mail:** `is_email()` + `sanitize_email()` — keine zusätzlichen Zeichen erlauben.
- **[SEC-VAL-05]** **Telefon:** Regex-Validierung für internationale Formate: `/^\+?[0-9\s\-\(\)]{5,20}$/`.
- **[SEC-VAL-06]** **Text/Textarea:** `sanitize_text_field()` bzw. `sanitize_textarea_field()` von WordPress verwenden.
- **[SEC-VAL-07]** **Checkbox/Radio/Select:** Werte gegen die vom Admin definierten Optionen prüfen (Whitelist). Beliebige Werte ablehnen.
- **[SEC-VAL-08]** **Datum:** Gegen das konfigurierte Datumsformat validieren (z.B. `Y-m-d`). `DateTime::createFromFormat()` verwenden.
- **[SEC-VAL-09]** **Datei-Upload:** Siehe Abschnitt 9.

### 3.3 Admin-Konfiguration
- **[SEC-VAL-10]** Admin-Inputs (Formularnamen, Feldnamen, E-Mail-Adressen) ebenfalls sanitizen. Formularfeld-Label: `sanitize_text_field()`, E-Mails: `sanitize_email()`.
- **[SEC-VAL-11]** JSON-Konfigurationen (Formular-Definitionen) MÜSSEN Schema-validiert werden bevor sie gespeichert werden.

### 3.4 Validierungsreihenfolge bei Formular-Submissions
- **[SEC-VAL-12]** Die serverseitige Verarbeitung einer Formular-Submission MUSS in folgender Reihenfolge ablaufen:
  1. **Nonce prüfen** (CSRF-Schutz, SEC-CSRF-01/02) — bei Fehlschlag: HTTP 403, sofort abbrechen
  2. **Einwilligung prüfen** (SEC-DSGVO-04) — bei Rechtsgrundlage "Einwilligung" und fehlendem Consent: HTTP 422, sofort abbrechen, **keine weiteren Aktionen**
  3. **CAPTCHA verifizieren** (SEC-CAP-01/02) — externer Request an CAPTCHA-Service; bei Fehlschlag: HTTP 422, Daten verwerfen
  4. **Felder validieren** (SEC-VAL-01 bis 09) — Whitelist, Typ-Prüfung, Längenbeschränkung
  5. **Verschlüsseln** (SEC-ENC-05 bis 10) — AES-256-GCM
  6. **Speichern** — DB-Insert mit `$wpdb->prepare()`
  7. **Benachrichtigung** (SEC-MAIL-01 bis 05) — E-Mail an Empfänger
  
  **Begründung der Reihenfolge:** Die Einwilligungsprüfung (Schritt 2) MUSS vor der CAPTCHA-Verifizierung (Schritt 3) erfolgen, damit bei fehlender Einwilligung kein externer Service kontaktiert wird — andernfalls würde die IP des WordPress-Servers ohne Rechtsgrundlage an den CAPTCHA-Service übertragen. (Korrespondiert mit DPO CONSENT-07.)

---

## 4. CSRF-Schutz

- **[SEC-CSRF-01]** JEDES Frontend-Formular MUSS ein WordPress-Nonce enthalten: `wp_nonce_field('dsgvo_form_submit_' . $form_id)`.
- **[SEC-CSRF-02]** Serverseitige Nonce-Prüfung bei jeder Formular-Submission: `wp_verify_nonce()`. Bei Fehlschlag: Anfrage sofort ablehnen (HTTP 403).
- **[SEC-CSRF-03]** ALLE Admin-AJAX-Actions MÜSSEN Nonces prüfen: `check_ajax_referer('dsgvo_form_admin_' . $action)`.
- **[SEC-CSRF-04]** REST-API-Endpunkte (falls verwendet): WordPress REST-Nonce via `X-WP-Nonce`-Header oder Cookie-basierte Authentifizierung.
- **[SEC-CSRF-05]** Nonces MÜSSEN formularspezifisch sein (nicht ein globales Nonce für alle Formulare).

---

## 5. XSS-Prävention

### 5.1 Output-Escaping
- **[SEC-XSS-01]** JEDER dynamische Output im HTML MUSS escaped werden. Keine Ausnahmen.
  - HTML-Kontext: `esc_html()`
  - Attribute: `esc_attr()`
  - URLs: `esc_url()`
  - JavaScript: `esc_js()` oder `wp_json_encode()`
  - Textarea-Inhalt: `esc_textarea()`
- **[SEC-XSS-02]** Entschlüsselte Formular-Daten in der Admin-Ansicht MÜSSEN escaped werden (Daten können beliebigen User-Input enthalten).
- **[SEC-XSS-03]** Kein `echo $variable` ohne Escaping — NIEMALS. Auch nicht in Admin-Templates.

### 5.2 Content Security Policy
- **[SEC-XSS-04]** Inline-Skripte vermeiden. Falls nötig, Nonce-basierte CSP nutzen (`wp_add_inline_script()` mit Nonce).
- **[SEC-XSS-05]** `wp_localize_script()` oder `wp_add_inline_script()` statt Inline-`<script>`-Tags für die Übergabe von PHP-Daten an JavaScript.

### 5.3 Gutenberg-Block
- **[SEC-XSS-06]** Im React/JSX-Kontext: Kein `dangerouslySetInnerHTML`. Alle dynamischen Werte durch React's Auto-Escaping schützen.
- **[SEC-XSS-07]** Block-Attribute MÜSSEN typisiert und validiert sein (in `block.json`).

---

## 6. CAPTCHA-Validierung

- **[SEC-CAP-01]** CAPTCHA-Validierung MUSS serverseitig erfolgen: PHP-Backend → POST `https://captcha.repaircafe-bruchsal.de/api/verify`.
- **[SEC-CAP-02]** NIEMALS nur client-seitig validieren. Das CAPTCHA-Token MUSS mit der Formular-Submission an den Server gesendet und dort verifiziert werden.
- **[SEC-CAP-03]** Bei fehlgeschlagener CAPTCHA-Validierung: Formular-Submission komplett ablehnen, alle Daten verwerfen.
- **[SEC-CAP-04]** Timeout für CAPTCHA-Verifikations-Request: Max 5 Sekunden. Bei Timeout: Submission ablehnen (fail-closed, nicht fail-open).
- **[SEC-CAP-05]** CAPTCHA-Service-URL MUSS in den Admin-Einstellungen konfigurierbar sein (nicht hardcoded), um Umgebungswechsel zu ermöglichen.
- **[SEC-CAP-06]** HTTPS für den CAPTCHA-Service erzwingen. Zertifikat validieren (kein `verify: false`).
- **[SEC-CAP-07]** CAPTCHA pro Formular aktivierbar/deaktivierbar (manche internen Formulare brauchen es ggf. nicht).
- **[SEC-CAP-08]** CAPTCHA-Datenfluss — An den CAPTCHA-Service werden ausschließlich folgende Daten übertragen:
  - Das CAPTCHA-Response-Token (vom Frontend generiert)
  - Kein User-Agent, keine IP-Adresse des Endnutzers, keine weiteren Metadaten.
  - Implementierung via `wp_remote_post()`:
    ```php
    $response = wp_remote_post($captcha_verify_url, [
        'body'    => ['token' => $captcha_token],
        'timeout' => 5,
    ]);
    ```
  - Hinweis: Die IP-Adresse des WordPress-Servers wird durch die HTTPS-Verbindung technisch bedingt übertragen, aber es werden KEINE Endnutzer-Daten weitergegeben.
- **[SEC-CAP-09]** Der CAPTCHA-Service MUSS in der Datenschutzerklärung als Unterauftragsverarbeiter genannt werden, da eine Verbindung zu einem externen Server aufgebaut wird.
- **[SEC-CAP-12]** Client-seitiger CAPTCHA-Datenfluss: Das CAPTCHA-Widget wird per `<script>` vom CAPTCHA-Server geladen. Dabei überträgt der **Browser des Endnutzers** seine IP-Adresse an den CAPTCHA-Server (technisch unvermeidbar bei HTTP-Verbindungen). Dies ist KEIN Datenfluss vom Plugin, sondern vom Browser. Konsequenzen:
  - Datenschutzerklärung MUSS darauf hinweisen, dass beim Laden des CAPTCHA-Widgets eine Verbindung zum externen Server aufgebaut wird und dabei die IP-Adresse übertragen wird
  - Falls CAPTCHA per Formular deaktivierbar ist (SEC-CAP-07), wird bei deaktiviertem CAPTCHA KEIN Widget geladen → keine externe Verbindung
  - Die DSGVO-Konformität des CAPTCHA-Service selbst wird durch den DPO geprüft (siehe DATA_PROTECTION.md)

### 6.2 Rate-Limiting (Spam-Schutz)
- **[SEC-CAP-10]** Rate-Limiting für Formular-Submissions: Max 5 Submissions pro Formular pro Zeitfenster (10 Minuten). Implementierung über WP Transients mit anonymisiertem Schlüssel.
- **[SEC-CAP-11]** KEIN IP-basiertes Rate-Limiting mit gespeicherten IP-Adressen oder IP-Hashes. Begründung: SHA-256-Hashes von IPv4-Adressen sind trivial reversibel (~4 Mrd. mögliche Werte). Stattdessen: CAPTCHA ist der primäre Spam-Schutz. Falls zusätzliches Rate-Limiting nötig, über WordPress-Nonce-basierte Mechanismen oder serverseitiges Throttling ohne IP-Persistierung (z.B. `wp_throttle_comment`-Muster mit Transients, die nur im RAM existieren und nach Ablauf automatisch verschwinden).

---

## 7. Authentifizierung & Autorisierung

### 7.1 Zwei-Rollen-Modell

#### 7.1.1 Rollenübersicht

| Rolle | Slug (WP-intern) | Capabilities | Sichtbare Submissions |
|-------|-------------------|-------------|----------------------|
| Administrator | `administrator` | `dsgvo_form_manage` + alle Plugin-Caps | Alle (via `dsgvo_form_manage`) |
| Supervisor | `wp_dsgvo_form_supervisor` | `read`, `dsgvo_form_view_submissions`, `dsgvo_form_view_all_submissions` | Alle Formulare |
| Empfänger | `wp_dsgvo_form_reader` | `read`, `dsgvo_form_view_submissions` | Nur zugewiesene Formulare |

- **[SEC-AUTH-01]** Rollen bei Plugin-Aktivierung registrieren:
  ```php
  add_role('wp_dsgvo_form_reader', 'DSGVO-Formular Empfänger', [
      'read'                          => true,
      'dsgvo_form_view_submissions'   => true,
  ]);
  add_role('wp_dsgvo_form_supervisor', 'DSGVO-Formular Supervisor', [
      'read'                              => true,
      'dsgvo_form_view_submissions'       => true,
      'dsgvo_form_view_all_submissions'   => true,
  ]);
  ```
- **[SEC-AUTH-02]** Beide Rollen DÜRFEN KEINE der folgenden Standard-Capabilities besitzen: `edit_posts`, `delete_posts`, `upload_files`, `edit_pages`, `publish_posts`, `manage_options`, `edit_users`, `install_plugins`, `edit_themes`. **Nur `read` + Plugin-eigene Capabilities.**
- **[SEC-AUTH-03]** Admin-Aktionen (Formulare erstellen/bearbeiten/löschen) erfordern `dsgvo_form_manage`. Diese Capability wird nur der `administrator`-Rolle zugewiesen.
- **[SEC-AUTH-04]** Submissions lesen als Reader erfordert `dsgvo_form_view_submissions` UND Zuordnung zum jeweiligen Formular. Submissions lesen als Supervisor erfordert `dsgvo_form_view_all_submissions` (ohne Formular-Zuordnung).
- **[SEC-AUTH-05]** Jede Admin-Seite und jeder AJAX-Endpunkt MUSS `current_user_can()` prüfen BEVOR Daten geladen werden.

#### 7.1.2 DSGVO-Bewertung der Supervisor-Rolle

**Rechtliche Einordnung:** Eine Rolle, die alle Einsendungen aller Formulare sehen kann, ist unter DSGVO **bedingt zulässig** — aber NUR unter strikten Voraussetzungen.

**Rechtsgrundlage:** Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse) oder Art. 6 Abs. 1 lit. c (rechtliche Verpflichtung, z.B. Datenschutzbeauftragter). Der Zugriff muss einem **dokumentierten, legitimen Zweck** dienen.

**Zulässige Zwecke für die Supervisor-Rolle:**
- Datenschutzbeauftragter, der Einsendungen auf Compliance prüfen muss
- Geschäftsführung mit Weisungsbefugnis über alle Formulare
- IT-Administration für Support/Fehleranalyse
- Revision/Audit-Zwecke

**NICHT zulässig:** Pauschal "damit jemand alles sehen kann" ohne dokumentierten Zweck.

**[SEC-AUTH-DSGVO-01]** Die Supervisor-Rolle MUSS an folgende technische und organisatorische Maßnahmen geknüpft sein:

| # | Maßnahme | Anforderung | DSGVO-Bezug |
|---|----------|-------------|-------------|
| 1 | **Zweckdokumentation** | Admin MUSS bei Zuweisung der Supervisor-Rolle einen Zweck angeben (Freitextfeld, Pflicht). Zweck wird in `{prefix}_dsgvo_form_recipients` gespeichert. | Art. 5 Abs. 1 lit. b (Zweckbindung) |
| 2 | **Lückenlose Audit-Protokollierung** | JEDER Lesezugriff des Supervisors auf Submissions wird in der Audit-Tabelle protokolliert (SEC-AUDIT-01 bis 03). | Art. 5 Abs. 2 (Rechenschaftspflicht) |
| 3 | **Admin-Warnung bei Zuweisung** | Beim Zuweisen der Supervisor-Rolle zeigt das Plugin eine Warnung an: "Diese Rolle gewährt Zugriff auf ALLE Einsendungen aller Formulare. Bitte stellen Sie sicher, dass ein dokumentierter Zweck vorliegt (z.B. Datenschutzbeauftragter, Revision)." | Art. 25 (Privacy by Design) |
| 4 | **Regelmäßige Überprüfung** | Admin erhält halbjährlich eine Erinnerung, die Supervisor-Zuweisungen zu überprüfen (WP-Cron + Admin-Notice). | Art. 5 Abs. 1 lit. e (Speicherbegrenzung, analog für Zugriffsrechte) |
| 5 | **Minimale Anzahl** | Plugin zeigt in den Einstellungen die Anzahl aktiver Supervisoren an. Empfehlung: Max. 2-3 pro Installation. Kein Hard-Limit, aber Warnung ab >3. | Art. 5 Abs. 1 lit. c (Datenminimierung, analog) |

**[SEC-AUTH-DSGVO-02]** Privacy-Impact-Hinweis: Das Plugin MUSS in der Admin-Oberfläche dokumentieren, dass die Supervisor-Rolle aus DSGVO-Sicht nur für berechtigte Personen mit dokumentiertem Zweck genutzt werden darf. Dieser Text wird auch in den `wp_add_privacy_policy_content()`-Eintrag aufgenommen.

**[SEC-AUTH-DSGVO-03]** Zugriffsprüfung für Supervisor:
  ```php
  function dsgvo_form_user_can_access_form(int $form_id): bool {
      // Admin: Vollzugriff
      if (current_user_can('dsgvo_form_manage')) return true;
      
      // Supervisor: Alle Formulare, aber Audit-Log-Eintrag
      if (current_user_can('dsgvo_form_view_all_submissions')) {
          dsgvo_form_log_access($form_id, 'supervisor_view');
          return true;
      }
      
      // Reader: Nur zugewiesene Formulare
      if (!current_user_can('dsgvo_form_view_submissions')) return false;
      
      global $wpdb;
      return (bool) $wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM {$wpdb->prefix}dsgvo_form_recipients 
           WHERE form_id = %d AND user_id = %d",
          $form_id, get_current_user_id()
      ));
  }
  ```

### 7.2 Capability-Isolation & Backend-Zugang
- **[SEC-AUTH-06]** Login-Redirect: `wp_dsgvo_form_reader` und `wp_dsgvo_form_supervisor` werden nach Login auf die Plugin-eigene Submissions-Seite umgeleitet — NICHT auf das WordPress-Dashboard. Implementierung über `login_redirect`-Filter.
  ```php
  add_filter('login_redirect', function($redirect_to, $request, $user) {
      $plugin_roles = ['wp_dsgvo_form_reader', 'wp_dsgvo_form_supervisor'];
      if (array_intersect($plugin_roles, $user->roles ?? [])) {
          return admin_url('admin.php?page=dsgvo-form-submissions');
      }
      return $redirect_to;
  }, 10, 3);
  ```
- **[SEC-AUTH-07]** Admin-Menü-Isolation: Beide Plugin-Rollen sehen NUR den Menüpunkt "Einsendungen". Alle anderen Admin-Menüpunkte (Posts, Pages, Media, Tools, Settings, etc.) MÜSSEN per `remove_menu_page()` entfernt werden.
  ```php
  add_action('admin_menu', function() {
      if (current_user_can('dsgvo_form_view_submissions') && !current_user_can('dsgvo_form_manage')) {
          remove_menu_page('index.php');           // Dashboard
          remove_menu_page('edit.php');             // Posts
          remove_menu_page('upload.php');           // Media
          remove_menu_page('edit.php?post_type=page'); // Pages
          remove_menu_page('edit-comments.php');    // Comments
          remove_menu_page('tools.php');            // Tools
          remove_menu_page('options-general.php');  // Settings
      }
  }, 999);
  ```
- **[SEC-AUTH-08]** Admin-Bar einschränken: Für beide Plugin-Rollen nur "Einsendungen" und "Abmelden" anzeigen. WordPress-Logo, Site-Name-Link, Dashboard-Link entfernen.
- **[SEC-AUTH-09]** Direktzugriff auf Admin-Seiten blockieren: Falls eine Plugin-Rolle versucht, eine nicht-autorisierte Admin-Seite direkt aufzurufen (z.B. `/wp-admin/edit.php`), MUSS auf die Submissions-Seite umgeleitet werden. Implementierung über `current_screen`-Hook.

### 7.3 Empfänger-Zugriff
- **[SEC-AUTH-10]** Empfänger-Formular-Zuordnung in einer separaten DB-Tabelle (`{prefix}_dsgvo_form_recipients`). Supervisor benötigt KEINEN Eintrag in dieser Tabelle (Zugriff über Capability).
- **[SEC-AUTH-11]** Session-Handling über WordPress' eigenes System (keine eigenen Sessions implementieren).
- **[SEC-AUTH-12]** Logout nach Inaktivität: Auth-Cookie-Expiration auf 2 Stunden für beide Plugin-Rollen setzen (über `auth_cookie_expiration`-Filter).
  ```php
  add_filter('auth_cookie_expiration', function($expiration, $user_id, $remember) {
      $user = get_userdata($user_id);
      $plugin_roles = ['wp_dsgvo_form_reader', 'wp_dsgvo_form_supervisor'];
      if (array_intersect($plugin_roles, $user->roles ?? [])) {
          return $remember ? DAY_IN_SECONDS : 2 * HOUR_IN_SECONDS;
      }
      return $expiration;
  }, 10, 3);
  ```

### 7.4 Direkte Objekt-Referenzen (IDOR)
- **[SEC-AUTH-13]** Submissions-IDs DÜRFEN NICHT vorhersagbar sein. Zusätzlich zur Auto-Increment-ID einen UUID (`wp_generate_uuid4()`) als öffentlichen Identifier verwenden.
- **[SEC-AUTH-14]** Bei jedem Zugriff auf eine Submission: Prüfen, ob der aktuelle Benutzer berechtigt ist, DIESE Submission zu sehen. Nicht nur prüfen, ob er generell Submissions sehen darf. Gilt auch für Supervisoren (die haben Zugriff, aber jeder Zugriff wird geloggt).

### 7.5 Rollenbereinigung bei Plugin-Deinstallation
- **[SEC-AUTH-15]** Bei Plugin-Deinstallation: `remove_role('wp_dsgvo_form_reader')` und `remove_role('wp_dsgvo_form_supervisor')` aufrufen. Benutzer behalten ihren Account, verlieren aber die Rolle und damit alle Plugin-Capabilities.
- **[SEC-AUTH-16]** Bei Plugin-Deinstallation: Custom Capabilities (`dsgvo_form_manage`, `dsgvo_form_view_submissions`, `dsgvo_form_view_all_submissions`, `dsgvo_form_export`, `dsgvo_form_delete_submissions`) von ALLEN Rollen entfernen.

---

## 8. SQL-Injection-Schutz

- **[SEC-SQL-01]** ALLE Datenbank-Abfragen MÜSSEN über `$wpdb->prepare()` laufen. Keine Ausnahmen.
- **[SEC-SQL-02]** Tabellennamen dynamisch mit `$wpdb->prefix` zusammensetzen — aber niemals User-Input in Tabellennamen verwenden.
- **[SEC-SQL-03]** `$wpdb->insert()`, `$wpdb->update()`, `$wpdb->delete()` mit expliziten Format-Parametern verwenden (`%s`, `%d`).
- **[SEC-SQL-04]** KEINE String-Konkatenation für SQL-Queries: `"SELECT * FROM table WHERE id = " . $id` ist VERBOTEN.
- **[SEC-SQL-05]** ORDER BY und LIMIT mit Whitelist-Ansatz: Erlaubte Spalten-/Richtungs-Werte fest definieren, User-Input dagegen prüfen.
- **[SEC-SQL-06]** Custom-Tabellen bei Plugin-Aktivierung mit `dbDelta()` erstellen. Schema klar definieren mit passenden Spaltentypen und Indices.

---

## 9. Datei-Upload-Sicherheit

- **[SEC-FILE-01]** Upload MUSS über WordPress' `wp_handle_upload()` laufen — NICHT manuell über `move_uploaded_file()`.
- **[SEC-FILE-02]** Erlaubte MIME-Types auf Whitelist beschränken (konfigurierbar pro Formularfeld, Default: `pdf, jpg, jpeg, png`).
- **[SEC-FILE-03]** Maximale Dateigröße pro Feld konfigurierbar (Default: 5 MB, Hard-Limit: 20 MB).
- **[SEC-FILE-04]** Dateinamen sanitizen: `sanitize_file_name()` verwenden. Doppelte Extensions ablehnen (z.B. `file.php.jpg`).
- **[SEC-FILE-05]** MIME-Type serverseitig prüfen (nicht nur die Dateiendung). `wp_check_filetype_and_ext()` verwenden. Zusätzlich `finfo_file()` für echte MIME-Type-Erkennung.
- **[SEC-FILE-06]** Uploads in einem geschützten Verzeichnis speichern: `wp_upload_dir()['basedir'] . '/dsgvo-form-files/'` mit `.htaccess`-Schutz (`Deny from all`) und einer `index.php`-Datei. **Nginx-Hinweis:** Da `.htaccess` nur auf Apache wirkt, MUSS die Plugin-Dokumentation für nginx-Server eine entsprechende Location-Regel empfehlen: `location ~* /uploads/dsgvo-form-files/ { deny all; }`.
- **[SEC-FILE-07]** Dateien MÜSSEN vor dem Speichern mit AES-256 verschlüsselt werden (wie Formular-Daten). Entschlüsselter Download nur über einen authentifizierten PHP-Endpunkt.
- **[SEC-FILE-08]** Kein direkter Datei-Download-Link. Stattdessen: `admin-ajax.php?action=dsgvo_form_download&file_id=X&nonce=Y` mit Berechtigungsprüfung.
- **[SEC-FILE-09]** Dateien bei Löschung der Einsendung ebenfalls löschen (physisch, nicht nur DB-Referenz entfernen).
- **[SEC-FILE-10]** Upload-Verzeichnis: Execution von PHP-Dateien per `.htaccess` blockieren: `php_flag engine off`.

---

## 10. E-Mail-Sicherheit

- **[SEC-MAIL-01]** E-Mail-Versand AUSSCHLIESSLICH über `wp_mail()` — niemals `mail()` direkt.
- **[SEC-MAIL-02]** E-Mail-Header-Injection verhindern: Empfänger-Adressen und Betreff über `sanitize_email()` / `sanitize_text_field()` bereinigen. Zeilenumbrüche (`\r`, `\n`) in Headerfeldern entfernen.
- **[SEC-MAIL-03]** E-Mail-Inhalt (Benachrichtigung an Empfänger) DARF KEINE unverschlüsselten Formular-Daten enthalten. Nur ein Hinweis "Neue Einsendung eingegangen" mit Link zum Login-Bereich.
- **[SEC-MAIL-04]** Empfänger-E-Mail-Adressen werden vom Admin konfiguriert und MÜSSEN gegen das WordPress-Benutzersystem validiert werden.
- **[SEC-MAIL-05]** Rate-Limiting: Maximal eine Benachrichtigungs-E-Mail pro Einsendung. Keine E-Mail-Schleifen durch fehlerhafte Konfiguration ermöglichen.

---

## 11. Allgemeine Plugin-Sicherheit

### 11.1 WordPress-Konformität
- **[SEC-GEN-01]** Direkte Dateizugriffe blockieren: `defined('ABSPATH') || exit;` am Anfang JEDER PHP-Datei.
- **[SEC-GEN-02]** Alle Hooks/Actions/Filters mit einzigartigem Prefix versehen: `dsgvo_form_`.
- **[SEC-GEN-03]** Keine Debug-Informationen im Produktivbetrieb ausgeben. `WP_DEBUG` respektieren.
- **[SEC-GEN-04]** Error-Handling: Keine Stack-Traces oder DB-Fehler an Frontend-Benutzer ausgeben. Generische Fehlermeldungen verwenden, Details ins Error-Log.

### 11.2 Dependency-Sicherheit
- **[SEC-GEN-05]** Keine externen PHP-Libraries einbinden, die nicht zwingend nötig sind. WordPress' eingebaute Funktionen bevorzugen.
- **[SEC-GEN-06]** Falls npm-Dependencies für den Gutenberg-Block: Regelmäßig `npm audit` laufen lassen. Keine bekannten Vulnerabilities.

### 11.3 Deinstallation
- **[SEC-GEN-07]** Bei Plugin-Deinstallation (`uninstall.php`): ALLE Plugin-Daten entfernen (Custom-Tabellen, Options, Upload-Verzeichnis, Capabilities). Klare Warnung im Admin-Bereich vor der Deinstallation.
- **[SEC-GEN-08]** Deaktivierung ≠ Deinstallation: Bei Deaktivierung Daten BEHALTEN. Nur bei Deinstallation löschen.

### 11.4 Plugin-Update-Sicherheit
- **[SEC-GEN-09]** Datenbank-Migrationen bei Updates über Versionsprüfung und `dbDelta()` sicher durchführen.
- **[SEC-GEN-10]** Verschlüsselungsschlüssel NIEMALS bei Updates überschreiben oder rotieren (Datenverlust!).

### 11.5 Audit-Logging
- **[SEC-AUDIT-01]** Admin-Aktionen auf Submissions MÜSSEN protokolliert werden: Wer hat wann welche Submission gelesen, exportiert oder gelöscht. Logging in eigener DB-Tabelle (`{prefix}_dsgvo_form_audit_log`).
- **[SEC-AUDIT-02]** Audit-Log-Einträge: `user_id`, `action` (view|export|delete), `submission_id`, `form_id`, `timestamp`, `ip_address` (des Admins, nicht des Einsenders).
- **[SEC-AUDIT-03]** Audit-Log darf NICHT vom Admin löschbar sein (Manipulationsschutz). Aufbewahrungsfrist: 1 Jahr, danach automatisch bereinigen.
- **[SEC-AUDIT-04]** Datenschutz im Audit-Log: Die `ip_address` des Admins im Audit-Log ist ein personenbezogenes Datum. Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an Nachvollziehbarkeit von Zugriffen auf besondere Datenkategorien). Die IP-Adresse wird nach 90 Tagen aus dem Audit-Log entfernt (der restliche Log-Eintrag bleibt bis zur 1-Jahres-Frist). Implementierung: Cron-Job setzt `ip_address = NULL` für Einträge älter als 90 Tage.
- **[SEC-AUDIT-05]** Alternative: Falls der DPO/Auftraggeber die IP-Speicherung im Audit-Log ablehnt, kann stattdessen nur `user_id` + `timestamp` gespeichert werden. Die `user_id` identifiziert den Admin hinreichend, die IP dient nur als zusätzlicher Nachweis bei kompromittierten Accounts.

### 11.6 Vulnerability Management
- **[SEC-VULN-01]** `npm audit` MUSS als Teil des Build-Prozesses laufen. Build schlägt bei bekannten high/critical Vulnerabilities fehl.
- **[SEC-VULN-02]** Dokumentierter Prozess für Security-Updates: Bei bekannter Schwachstelle in einer Dependency innerhalb von 72h patchen oder Workaround bereitstellen.

---

## Checkliste für Code-Reviews

Jeder Pull Request MUSS gegen folgende Punkte geprüft werden:

| # | Check | Bestanden |
|---|-------|-----------|
| 1 | Kein `echo` ohne `esc_html()` / `esc_attr()` / `esc_url()` | [ ] |
| 2 | Kein SQL ohne `$wpdb->prepare()` | [ ] |
| 3 | Nonce-Prüfung in jedem Formular-Handler | [ ] |
| 4 | `current_user_can()` vor jeder privilegierten Aktion | [ ] |
| 5 | Kein hardcodierter Schlüssel / Passwort / Secret | [ ] |
| 6 | Input-Validierung vorhanden und server-seitig | [ ] |
| 7 | Datei-Uploads nur über WP-APIs + MIME-Check | [ ] |
| 8 | E-Mail-Versand nur über `wp_mail()` | [ ] |
| 9 | `defined('ABSPATH') || exit;` in jeder PHP-Datei | [ ] |
| 10 | Keine sensiblen Daten in E-Mails | [ ] |

---

## Versions-Historie

| Version | Datum | Änderung |
|---------|-------|----------|
| 1.0 | 2026-04-17 | Initiale Security Requirements erstellt |
| 1.1 | 2026-04-17 | Audit-Logging (SEC-AUDIT-01 bis 03) und Vulnerability Management (SEC-VULN-01/02) ergänzt (aus ISO 27001-Analyse) |
| 1.2 | 2026-04-17 | Auth-Sektion überarbeitet: Custom Role `dsgvoform_reader` mit Capability-Isolation, Login-Redirect, Admin-Menü-Isolation, Session-Timeout, Rollenbereinigung (SEC-AUTH-01 bis 16) |
| 1.3 | 2026-04-17 | Zwei-Rollen-Modell: `wp_dsgvo_form_reader` + `wp_dsgvo_form_supervisor` mit DSGVO-Bewertung (SEC-AUTH-DSGVO-01 bis 03). Rollennamen auf WP-Syntax korrigiert. Neue Capability `dsgvo_form_view_all_submissions`. |
| 1.4 | 2026-04-17 | CAPTCHA-Datenfluss spezifiziert (SEC-CAP-08/09), Rate-Limiting ohne IP-Hashing (SEC-CAP-10/11), Audit-Log IP-Datenschutz (SEC-AUDIT-04/05). Abstimmung mit DPO. |
| 1.5 | 2026-04-17 | Lookup-Hash für Datenschutzanfragen (SEC-ENC-13/14), Einschränkung der Verarbeitung Art. 18 (SEC-DSGVO-13), Rechtsgrundlage pro Formular (SEC-DSGVO-14). Abstimmung mit legal-expert. |
| 1.5.1 | 2026-04-17 | SEC-DSGVO-06 präzisiert: Einwilligungstext-Wortlaut versioniert aufbewahren. SEC-DSGVO-13: Auto-Löschung-Ausnahme für eingeschränkte Submissions. Abstimmung mit legal-expert. |
| 1.5.2 | 2026-04-17 | Client-seitiger CAPTCHA-Datenfluss (SEC-CAP-12), Re-Encryption-Dokumentation (SEC-ENC-15). Abstimmung mit DPO. |
| 1.5.3 | 2026-04-17 | SEC-DSGVO-04 präzisiert: Hard-Block bei fehlender Einwilligung (HTTP 422, keine Datenspeicherung). Korrespondiert mit LEGAL-CONSENT-06. Nur bei Rechtsgrundlage "Einwilligung" (SEC-DSGVO-14). |
| 1.5.4 | 2026-04-17 | Validierungsreihenfolge (SEC-VAL-12): Consent vor CAPTCHA prüfen, damit bei fehlender Einwilligung kein externer Service kontaktiert wird. Abstimmung mit DPO (CONSENT-07). |
| 1.6 | 2026-04-17 | **Envelope Encryption:** Sektion 2 überarbeitet — dreistufige Schlüsselhierarchie (KEK → Form-DEK → File-DEK) dokumentiert. Neue Requirements SEC-ENC-16 bis 19 (DEK-Lifecycle). SEC-ENC-02/03/05/07/10/12/15 an Envelope-Architektur angepasst. Packing-Formate (3 Varianten) spezifiziert. Re-Encryption-Prozess vereinfacht (nur Re-Wrapping der DEKs nötig). |
