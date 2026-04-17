# Performance-Anforderungen — wp-dsgvo-form

> Erstellt: 2026-04-17 | Autor: Performance Expert
> Version: 3.0 — Abgeglichen mit DPO-Anforderungen (DATA_PROTECTION.md v1.1): Speicherfristen, Lösch-Cron-Jobs, Art. 18 Sperrung, Audit-Log

---

## Inhaltsverzeichnis

1. [Datenbank-Design & Indizes](#1-datenbank-design--indizes)
2. [Verschlüsselte Daten: Sortierung, Filterung, Paginierung](#2-verschlüsselte-daten-sortierung-filterung-paginierung)
3. [Lazy Loading verschlüsselter Daten](#3-lazy-loading-verschlüsselter-daten)
4. [Asset-Loading (JS/CSS)](#4-asset-loading-jscss)
5. [CAPTCHA-Script-Loading](#5-captcha-script-loading)
6. [Caching-Strategie](#6-caching-strategie)
7. [AES-256 Encryption Performance](#7-aes-256-encryption-performance)
8. [Pagination](#8-pagination)
9. [Admin-UI Performance](#9-admin-ui-performance)
10. [Datei-Upload Performance](#10-datei-upload-performance)
11. [REST-API Performance](#11-rest-api-performance)
12. [Skalierbarkeit](#12-skalierbarkeit)
13. [DSGVO-Lösch-Cron-Jobs & Retention](#13-dsgvo-lösch-cron-jobs--retention)
14. [Gutenberg-Block React-Performance](#14-gutenberg-block-react-performance)
15. [Performance-Budget (Zusammenfassung)](#15-performance-budget-zusammenfassung)
16. [Monitoring-Empfehlung](#16-monitoring-empfehlung)

---

## 1. Datenbank-Design & Indizes

### Custom Tables (empfohlen statt Post-Meta)

Das Plugin sollte eigene Tabellen nutzen, da WordPress `wp_postmeta` bei vielen Submissions zum Bottleneck wird. Post-Meta speichert alles als Key-Value-Paare, was zu massivem Row-Overhead und schlechter Index-Nutzung führt.

**Tabellen-Schema:**

```sql
-- Formulare
CREATE TABLE {prefix}dsgvo_forms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    config LONGTEXT NOT NULL,          -- JSON: Feld-Konfiguration
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    retention_days INT UNSIGNED NOT NULL DEFAULT 90,  -- Speicherfrist (1–3650 Tage, DPO-FINDING-01)
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Formularfelder (separate Tabelle für flexible Konfiguration)
CREATE TABLE {prefix}dsgvo_form_fields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    field_type VARCHAR(50) NOT NULL,    -- text, textarea, email, file, checkbox, select, ...
    field_label VARCHAR(255) NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    field_options JSON DEFAULT NULL,    -- Validierung, Placeholder, Optionen
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_form_id (form_id),
    INDEX idx_form_sort (form_id, sort_order),
    FOREIGN KEY (form_id) REFERENCES {prefix}dsgvo_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Eingereichte Formulardaten
CREATE TABLE {prefix}dsgvo_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    encrypted_data LONGTEXT NOT NULL,  -- AES-256 verschlüsselt (Formularfelder)
    iv VARCHAR(64) NOT NULL,           -- Initialisierungsvektor
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,      -- Berechnet: submitted_at + retention_days (DPO-FINDING-01)
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    is_restricted TINYINT(1) NOT NULL DEFAULT 0,  -- Art. 18 DSGVO: Einschränkung der Verarbeitung
    has_attachments TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_form_id (form_id),
    INDEX idx_submitted_at (submitted_at),
    INDEX idx_form_submitted (form_id, submitted_at DESC),
    INDEX idx_form_read (form_id, is_read),
    INDEX idx_expires_at (expires_at),                          -- Lösch-Cron: WHERE expires_at < NOW()
    INDEX idx_expiry_restricted (expires_at, is_restricted),              -- Lösch-Cron: WHERE expires_at < NOW() AND is_restricted = 0
    FOREIGN KEY (form_id) REFERENCES {prefix}dsgvo_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Datei-Anhänge (getrennt von Submission-Daten)
CREATE TABLE {prefix}dsgvo_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,
    file_name_encrypted VARCHAR(512) NOT NULL,  -- Verschlüsselter Originalname
    file_path VARCHAR(512) NOT NULL,            -- Pfad im geschützten Verzeichnis
    file_size BIGINT UNSIGNED NOT NULL,         -- Bytes (für Quota-Checks)
    mime_type VARCHAR(100) NOT NULL,
    iv VARCHAR(64) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_submission_id (submission_id),
    FOREIGN KEY (submission_id) REFERENCES {prefix}dsgvo_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Empfänger-Zuordnung
CREATE TABLE {prefix}dsgvo_recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    INDEX idx_form_user (form_id, user_id),
    UNIQUE KEY uq_form_user (form_id, user_id),
    FOREIGN KEY (form_id) REFERENCES {prefix}dsgvo_forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Begründung der Indizes

| Index | Zweck | Erwartete Abfrage |
|-------|-------|-------------------|
| `idx_form_id` (submissions) | Submissions eines Formulars laden | `WHERE form_id = ?` |
| `idx_submitted_at` | Globale Sortierung nach Datum | `ORDER BY submitted_at DESC` |
| `idx_form_submitted` | **Covering Index** für paginierte Formular-Submissions | `WHERE form_id = ? ORDER BY submitted_at DESC LIMIT ?, ?` |
| `idx_form_read` | Ungelesene filtern | `WHERE form_id = ? AND is_read = 0` |
| `idx_expires_at` | Lösch-Cron: abgelaufene Submissions finden | `WHERE expires_at < NOW()` |
| `idx_expiry_restricted` | **Lösch-Cron (primär)**: abgelaufene, nicht gesperrte Submissions | `WHERE expires_at < NOW() AND is_restricted = 0` |
| `idx_form_user` (recipients) | Empfänger-Lookup & Berechtigungsprüfung | `WHERE form_id = ? AND user_id = ?` |
| `uq_form_user` | Keine doppelten Empfänger-Zuordnungen | Unique Constraint |
| `idx_form_sort` (fields) | Felder in korrekter Reihenfolge laden | `WHERE form_id = ? ORDER BY sort_order` |
| `idx_submission_id` (attachments) | Anhänge einer Submission laden | `WHERE submission_id = ?` |

### Performance-Kennzahl
- **Ziel**: Submission-Liste eines Formulars in < 50 ms bei 10.000 Einträgen laden (paginiert)

---

## 2. Verschlüsselte Daten: Sortierung, Filterung, Paginierung

### Das Problem

Da `encrypted_data` AES-256-verschlüsselt ist, können wir **nicht** nach Feldinhalten suchen, filtern oder sortieren. Das ist ein fundamentaler Trade-off der Ende-zu-Ende-Verschlüsselung.

### Strategie: Klartext-Metadaten für Sortierung/Filterung

Alle filterbaren/sortierbaren Informationen werden als **unverschlüsselte Metadaten** in eigenen Spalten gespeichert:

```
Sortierbar/Filterbar (Klartext):     Nicht sortierbar (verschlüsselt):
├── submitted_at                      └── encrypted_data (alle Formularfelder)
├── expires_at
├── is_read
├── is_restricted
├── form_id
├── has_attachments
└── id (Reihenfolge)
```

### Umsetzung

**Sortierung** erfolgt ausschließlich über Klartext-Spalten:
- Nach Datum (`submitted_at DESC` — Standard)
- Nach Gelesen-Status (`is_read ASC, submitted_at DESC`)
- Nach ID (`id DESC` — Einsendereihenfolge)

**Filterung** über Klartext-Spalten:
- Ungelesene: `WHERE is_read = 0`
- Gesperrte (Art. 18): `WHERE is_restricted = 1`
- Bald ablaufend: `WHERE expires_at < NOW() + INTERVAL 7 DAY`
- Mit Anhängen: `WHERE has_attachments = 1`
- Zeitraum: `WHERE submitted_at BETWEEN ? AND ?`

**Volltextsuche in verschlüsselten Daten: Nicht möglich.**
→ Dem Nutzer in der UI klar kommunizieren: "Suche ist nicht verfügbar, da Daten verschlüsselt gespeichert werden."

### Alternative: Suchindex mit gehashten Werten (NICHT empfohlen)

Theoretisch könnte man durchsuchbare Hash-Indizes anlegen (Blind Index Pattern). Das ist für diesen Anwendungsfall **zu komplex** und **sicherheitskritisch**:
- Erhöht die Angriffsfläche (Frequency Analysis)
- Hoher Implementierungsaufwand
- Für ein WordPress-Plugin unverhältnismäßig

**Empfehlung**: Klare Kommunikation an Nutzer, dass verschlüsselte Daten nicht durchsuchbar sind. Sortierung nach Metadaten ist ausreichend.

---

## 3. Lazy Loading verschlüsselter Daten

### Prinzip
Verschlüsselte Inhalte (`encrypted_data`) werden **nur geladen, wenn sie tatsächlich angezeigt werden**.

### Umsetzung

```
Submissions-Liste:  Nur id, form_id, submitted_at, expires_at, is_read, is_restricted, has_attachments laden
Detailansicht:      encrypted_data + iv per AJAX nachladen → serverseitig entschlüsseln
```

**Regeln:**
- `SELECT`-Statements auf Listenansichten dürfen `encrypted_data` NICHT enthalten
- Entschlüsselung erfolgt per AJAX-Request beim Öffnen einer einzelnen Submission
- Maximal **eine** Submission gleichzeitig entschlüsseln (kein Batch-Decrypt in der Liste)
- Entschlüsselte Daten werden **nicht** im Browser-LocalStorage oder SessionStorage gespeichert

### Performance-Kennzahl
- **Ziel**: Listenansicht ohne Entschlüsselung in < 100 ms
- **Ziel**: Einzelne Submission entschlüsseln in < 200 ms

---

## 4. Asset-Loading (JS/CSS)

### Gutenberg Block Assets

**Kritisch: Kein siteweites Enqueuing!**

```php
// RICHTIG: Nur laden wenn Block auf der Seite ist
function enqueue_block_assets() {
    if (!has_block('wp-dsgvo-form/form')) {
        return;
    }
    wp_enqueue_style('wp-dsgvo-form-frontend', ...);
    wp_enqueue_script('wp-dsgvo-form-frontend', ...);
}
add_action('wp_enqueue_scripts', 'enqueue_block_assets');
```

**Block-Assets via `block.json` (bevorzugt):**
```json
{
    "editorScript": "file:./build/index.js",
    "editorStyle": "file:./build/index.css",
    "viewScript": "file:./build/view.js",
    "viewStyle": "file:./build/style-index.css"
}
```

WordPress registriert Block-Assets automatisch über `block.json` und lädt `viewScript`/`viewStyle` nur auf Seiten, die den Block enthalten.

### Admin-Assets

```php
function enqueue_admin_assets($hook) {
    // Nur auf Plugin-eigenen Admin-Seiten
    if (strpos($hook, 'dsgvo-form') === false) {
        return;
    }
    wp_enqueue_style('wp-dsgvo-form-admin', ...);
    wp_enqueue_script('wp-dsgvo-form-admin', ...);
}
add_action('admin_enqueue_scripts', 'enqueue_admin_assets');
```

### Minification & Build

- **Produktion**: Alle JS/CSS minifiziert ausliefern (`.min.js`, `.min.css`)
- **Build-Tool**: `@wordpress/scripts` (Standard WordPress Build-Toolchain)
- **Tree-Shaking**: WordPress-Dependencies (`@wordpress/components`, `@wordpress/element`) als Externals deklarieren — nicht ins Bundle packen
- **Source Maps**: Nur im Development-Build, nicht in Produktion

### Performance-Kennzahlen
- **Ziel**: 0 KB zusätzliche Assets auf Seiten ohne Formular
- **Ziel**: Frontend-Bundle < 30 KB (gzipped)
- **Ziel**: Admin-Bundle < 80 KB (gzipped)

---

## 5. CAPTCHA-Script-Loading

### Bedingtes Laden

Das CAPTCHA-Script von `captcha.repaircafe-bruchsal.de` darf **nur** geladen werden, wenn ein Formular auf der aktuellen Seite gerendert wird.

```php
// Im render_callback des Blocks:
function render_form_block($attributes, $content) {
    wp_enqueue_script(
        'dsgvo-captcha',
        'https://captcha.repaircafe-bruchsal.de/api.js',
        [],
        null,
        ['strategy' => 'defer', 'in_footer' => true]
    );
    return $content;
}
```

**Regeln:**
- `defer`-Attribut verwenden (nicht render-blocking)
- Script in Footer laden (`in_footer => true`)
- Kein Preload/Prefetch des CAPTCHA-Scripts im `<head>`
- DNS-Prefetch für die CAPTCHA-Domain ist erlaubt:

```php
function add_captcha_dns_prefetch($hints, $relation_type) {
    if ($relation_type === 'dns-prefetch') {
        $hints[] = 'https://captcha.repaircafe-bruchsal.de';
    }
    return $hints;
}
add_filter('wp_resource_hints', 'add_captcha_dns_prefetch', 10, 2);
```

### Performance-Kennzahl
- **Ziel**: CAPTCHA-Script auf 0 Seiten ohne Formular geladen

---

## 6. Caching-Strategie

### WordPress Transients für Formular-Konfigurationen

Formular-Konfigurationen ändern sich selten → ideal für Caching.

```php
function get_form_config(int $form_id): ?array {
    $cache_key = 'dsgvo_form_config_' . $form_id;

    $config = get_transient($cache_key);
    if ($config !== false) {
        return $config;
    }

    $config = $this->load_form_config_from_db($form_id);
    if ($config !== null) {
        set_transient($cache_key, $config, HOUR_IN_SECONDS);
    }

    return $config;
}
```

### Cache-Invalidierung

```php
function on_form_updated(int $form_id): void {
    delete_transient('dsgvo_form_config_' . $form_id);
}
```

### Object Cache Kompatibilität

WordPress Transients nutzen automatisch Object Cache (Redis, Memcached), wenn vorhanden. Das Plugin muss:

- **Keine eigene** Cache-Implementierung bauen
- Transients API konsistent verwenden (nicht `wp_cache_*` direkt)
- Cache-Keys mit Plugin-Prefix versehen (`dsgvo_`) um Kollisionen zu vermeiden
- `wp_using_ext_object_cache()` prüfen, falls cache-spezifische Logik nötig ist

### Was cachen, was nicht

| Daten | Cachen? | TTL | Begründung |
|-------|---------|-----|------------|
| Formular-Konfiguration (+ Felder) | Ja | 1 Stunde | Ändert sich selten, häufig gelesen |
| Formular-Liste (Admin) | Ja | 5 Minuten | Übersicht, nicht zeitkritisch |
| Submission-Count pro Form | Ja | 2 Minuten | Für Dashboard-Badges |
| Empfänger-Zuordnung pro Form | Ja | 30 Minuten | Berechtigungsprüfung, selten geändert |
| Submissions selbst | **Nein** | — | Verschlüsselt, personenbezogen |
| Entschlüsselte Daten | **Nein** | — | DSGVO! Dürfen nie gecacht werden |
| CAPTCHA-Tokens | **Nein** | — | Einmalig gültig |

### Performance-Kennzahl
- **Ziel**: Formular-Rendering mit Cache < 5 ms DB-Overhead
- **Ziel**: Cache-Hit-Rate > 95% für Formular-Configs im Normalbetrieb

---

## 7. AES-256 Encryption Performance

### Impact-Analyse

AES-256-CBC über PHP OpenSSL ist hardwarebeschleunigt auf modernen CPUs (AES-NI).

**Erwartete Performance:**
- Verschlüsselung eines typischen Formulars (1–5 KB Payload): < 1 ms
- Entschlüsselung: < 1 ms
- **Kein Bottleneck** bei einzelnen Operationen

### Risiko: Batch-Operationen

| Submissions | Geschätzte Decrypt-Zeit | Empfehlung |
|-------------|------------------------|------------|
| 1–50 | < 50 ms | Direkt entschlüsseln |
| 50–500 | 50–500 ms | Pagination erzwingen |
| 500+ | > 500 ms | Background-Job |

### Empfehlungen

1. **Kein Bulk-Decrypt in einem Request** — Pagination erzwingen (max. 20 pro Seite)
2. **Export-Funktion**: Als WP-Cron Background-Job mit Fortschrittsanzeige
3. **Schlüssel-Handling**: Entschlüsselungsschlüssel in der Session halten (nach Empfänger-Login), nicht bei jedem Request neu ableiten
4. **Datei-Verschlüsselung**: Streaming-Verschlüsselung für große Dateien (siehe Abschnitt 10)

### Performance-Kennzahl
- **Ziel**: Einzelne Submission verschlüsseln/entschlüsseln in < 5 ms
- **Ziel**: Paginierte Liste (20 Einträge) entschlüsseln in < 100 ms

---

## 8. Pagination

### Submissions-Liste

**Serverseitig paginiert** — kein clientseitiges Filtern großer Datenmengen.

```php
function get_submissions(int $form_id, int $page = 1, int $per_page = 20): array {
    global $wpdb;
    $offset = ($page - 1) * $per_page;
    $table = $wpdb->prefix . 'dsgvo_submissions';

    // Nur Metadaten laden (KEIN encrypted_data!)
    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT id, form_id, submitted_at, expires_at, is_read, is_restricted, has_attachments
         FROM {$table}
         WHERE form_id = %d
         ORDER BY submitted_at DESC
         LIMIT %d OFFSET %d",
        $form_id, $per_page, $offset
    ));

    $total = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE form_id = %d",
        $form_id
    ));

    return [
        'items'       => $items,
        'total'       => (int) $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => (int) ceil($total / $per_page),
    ];
}
```

### Seitengrößen

| Kontext | Standard | Maximum | Begründung |
|---------|----------|---------|------------|
| Submissions-Liste | 20 | 50 | Entschlüsselungszeit begrenzen |
| Formular-Liste (Admin) | 20 | 100 | Leichtgewichtig, keine Verschlüsselung |
| Export | 100/Batch | — | Background-Job, nicht UI-gebunden |

### Performance-Kennzahl
- **Ziel**: Paginierte Abfrage bei 50.000 Submissions in < 100 ms
- **Ziel**: COUNT-Query bei 50.000 Submissions in < 50 ms

---

## 9. Admin-UI Performance

### Formular-Liste

- **Server-Side Rendering** der Tabelle mit `WP_List_Table`
- AJAX-Pagination (kein Full-Page-Reload beim Blättern)
- Sortierung serverseitig (nicht clientseitig)

### Formular-Editor

- React-basierter Editor (Gutenberg-Sidebar oder eigene Admin-Page)
- **Autosave** mit Debounce (max. 1 Request pro 5 Sekunden)
- Feld-Konfiguration als JSON — ein einziger `UPDATE` statt vieler kleiner Writes

### Submissions-Ansicht

- AJAX-basiertes Laden der Detailansicht (kein Seitenwechsel)
- Entschlüsselung erst bei Klick auf einzelne Submission
- Batch-Operationen als einzelne Queries:

```php
// "Alle als gelesen markieren" — ein Query statt N
function mark_all_read(int $form_id): int {
    global $wpdb;
    return $wpdb->update(
        $wpdb->prefix . 'dsgvo_submissions',
        ['is_read' => 1],
        ['form_id' => $form_id, 'is_read' => 0]
    );
}

// "Ausgewählte löschen" — ein Query statt N (gesperrte überspringen!)
function delete_submissions(array $ids): int {
    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    return $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}dsgvo_submissions WHERE id IN ($placeholders) AND is_restricted = 0",
        ...$ids
    ));
}
```

### Performance-Kennzahlen
- **Ziel**: Admin-Seitenaufbau < 300 ms (inkl. DB-Queries)
- **Ziel**: Max. 5 DB-Queries pro Admin-Seitenaufruf
- **Ziel**: AJAX-Responses < 200 ms

---

## 10. Datei-Upload Performance

### Speicherstrategie: Filesystem (nicht DB)

**Empfehlung: Dateien im Filesystem speichern, nicht in der Datenbank.**

| Aspekt | DB (LONGBLOB) | Filesystem |
|--------|---------------|------------|
| Performance bei großen Dateien | Schlecht (DB-Overhead, Speicher) | Gut (direkter Dateizugriff) |
| Backup-Größe | Riesig | DB bleibt klein |
| Streaming | Nicht möglich | Nativ unterstützt |
| WordPress-Kompatibilität | Unüblich | Standard (`wp-content/uploads/`) |

### Geschützter Upload-Ordner

```
wp-content/uploads/dsgvo-form/
├── .htaccess          ← "Deny from all"
├── index.php          ← Leere Datei (Directory-Listing verhindern)
├── {form_id}/
│   ├── {submission_id}/
│   │   ├── {random_hash}.enc    ← Verschlüsselte Datei
│   │   └── {random_hash}.enc    ← Verschlüsselte Datei
```

**Zugriffsschutz:**
```apache
# .htaccess im Upload-Ordner
Order Deny,Allow
Deny from all
```

Dateien werden **nur** über einen PHP-Endpoint ausgeliefert, der Berechtigung prüft und entschlüsselt.

### Verschlüsselung großer Dateien: Streaming

Dateien dürfen NICHT komplett in den Speicher geladen werden. Stattdessen **Streaming-Verschlüsselung** in Chunks:

```php
function encrypt_file_stream(string $source, string $dest, string $key): string {
    $iv = openssl_random_pseudo_bytes(16);
    $in = fopen($source, 'rb');
    $out = fopen($dest, 'wb');

    // IV am Anfang der Datei schreiben
    fwrite($out, $iv);

    while (!feof($in)) {
        $chunk = fread($in, 8192); // 8 KB Chunks
        $encrypted = openssl_encrypt($chunk, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        // Neuen IV für nächsten Chunk (CBC Chaining)
        $iv = substr($encrypted, -16);
        fwrite($out, pack('N', strlen($encrypted)) . $encrypted);
    }

    fclose($in);
    fclose($out);
    return bin2hex($iv);
}
```

### Upload-Limits

| Parameter | Wert | Begründung |
|-----------|------|------------|
| Max. Dateigröße pro Datei | 10 MB | WordPress-Standard respektieren (`upload_max_filesize`) |
| Max. Dateien pro Submission | 5 | Speicher-Overhead begrenzen |
| Max. Gesamtgröße pro Submission | 25 MB | Server-Timeout vermeiden |
| Erlaubte MIME-Types | PDF, JPEG, PNG, DOCX | Angriffsfläche minimieren |

### Chunk-Upload für große Dateien

Für Dateien > 2 MB: **Client-seitiger Chunk-Upload**

```javascript
async function uploadInChunks(file, formId, chunkSize = 1024 * 1024) {
    const totalChunks = Math.ceil(file.size / chunkSize);
    const uploadId = crypto.randomUUID();

    for (let i = 0; i < totalChunks; i++) {
        const chunk = file.slice(i * chunkSize, (i + 1) * chunkSize);
        const formData = new FormData();
        formData.append('chunk', chunk);
        formData.append('uploadId', uploadId);
        formData.append('chunkIndex', i);
        formData.append('totalChunks', totalChunks);

        await fetch(`/wp-json/dsgvo-form/v1/upload-chunk/${formId}`, {
            method: 'POST',
            body: formData,
            headers: { 'X-WP-Nonce': wpApiSettings.nonce }
        });
    }
    return uploadId;
}
```

**Server-seitig**: Chunks in temporärem Verzeichnis sammeln, nach Abschluss zusammensetzen und verschlüsseln.

### Aufräumen

- **Temporäre Chunks**: Nach 1 Stunde via WP-Cron löschen
- **Verwaiste Dateien**: Täglicher Cron-Job prüft, ob Submissions noch existieren

### Performance-Kennzahlen
- **Ziel**: Upload von 5 MB Datei < 3 Sekunden (exkl. Netzwerk)
- **Ziel**: Verschlüsselung 10 MB Datei < 500 ms (Streaming)
- **Ziel**: Peak Memory bei Datei-Verschlüsselung < 2 MB (unabhängig von Dateigröße)

---

## 11. REST-API Performance

### Endpunkt-Design

```
POST   /wp-json/dsgvo-form/v1/submit/{form_id}        → Submission erstellen
POST   /wp-json/dsgvo-form/v1/upload-chunk/{form_id}   → Datei-Chunk hochladen
GET    /wp-json/dsgvo-form/v1/forms/{id}/config         → Formular-Config (cached)
```

### Rate-Limiting / Spam-Schutz

> **DPO-Entscheidung (SEC-CAP-11 + SEC-DSGVO-02):** Kein IP-basiertes Rate-Limiting — auch nicht
> als Hash in kurzlebigen Transients. IP-Adressen sind personenbezogene Daten, auch gehasht.
> **CAPTCHA ist der alleinige Spam-Schutz.**

Zusätzlich zum CAPTCHA werden folgende **IP-freie** Maßnahmen empfohlen:

```php
// 1. WordPress-Nonce validieren (CSRF-Schutz + Replay-Schutz)
// 2. CAPTCHA-Token serverseitig verifizieren (primärer Spam-Schutz)
// 3. Honeypot-Feld: verstecktes Feld, das Bots ausfüllen → Submission ablehnen
function validate_submission(array $data): bool {
    // Nonce prüfen
    if (!wp_verify_nonce($data['_wpnonce'] ?? '', 'dsgvo_form_submit')) {
        return false;
    }

    // Honeypot-Feld prüfen (muss leer sein)
    if (!empty($data['website_url'])) { // verstecktes Feld
        return false;
    }

    // CAPTCHA verifizieren
    if (!$this->captcha_service->verify($data['captcha_token'] ?? '')) {
        return false;
    }

    return true;
}
```

**Performance-Vorteil:** Kein Transient-Overhead pro Submission (Lese- + Schreibzugriff entfällt).
```

### Request-Optimierung

- **Nonce-Validierung** vor jeder teuren Operation (frühzeitiger Abbruch)
- **Input-Validierung** vor Verschlüsselung (kein Encrypt von ungültigen Daten)
- **Prepared Statements** für alle DB-Queries (`$wpdb->prepare()`)

### Performance-Kennzahl
- **Ziel**: Submission-Endpunkt Response-Time < 300 ms (inkl. Verschlüsselung + DB-Write)
- **Ziel**: Chunk-Upload Response < 200 ms pro Chunk

---

## 12. Skalierbarkeit

### Szenario-Analyse

| Szenario | Formulare | Felder/Form | Submissions | Empfohlene Maßnahme |
|----------|-----------|-------------|-------------|---------------------|
| Klein (Blog) | 1–5 | 3–10 | < 1.000 | Standard-Setup, keine besonderen Maßnahmen |
| Mittel (Verein) | 5–20 | 5–15 | 1.000–10.000 | Indizes, Pagination, Caching |
| Groß (Organisation) | 20–100 | 10–30 | 10.000–100.000 | Background-Jobs, Export-Optimierung |

### DB-Partitionierung: NICHT empfohlen

Für ein WordPress-Plugin ist DB-Partitionierung **unverhältnismäßig**:
- WordPress nutzt `$wpdb` — keine Partition-Awareness
- Shared Hosting unterstützt es meist nicht
- Die Indizes + Pagination-Strategie reicht für 100.000+ Submissions

### Stattdessen: Retention-basierte automatische Löschung

> **Abgeglichen mit DPO-Anforderungen (DATA_PROTECTION.md v1.1)**

Die automatische Löschung basiert auf `expires_at` (berechnet bei Submission: `submitted_at + retention_days`).
Details zum Lösch-Cron-Job siehe [Abschnitt 13](#13-dsgvo-lösch-cron-jobs--retention).

```php
// expires_at bei Submission berechnen
function create_submission(int $form_id, string $encrypted_data, string $iv): int {
    global $wpdb;
    $form = $this->get_form($form_id);
    $retention_days = $form->retention_days; // Default: 90, Min: 1, Max: 3650

    return $wpdb->insert($wpdb->prefix . 'dsgvo_submissions', [
        'form_id'         => $form_id,
        'encrypted_data'  => $encrypted_data,
        'iv'              => $iv,
        'submitted_at'    => current_time('mysql'),
        'expires_at'      => date('Y-m-d H:i:s', strtotime("+{$retention_days} days")),
        'is_read'         => 0,
        'is_restricted'       => 0,
        'has_attachments'  => 0,
    ]);
}
```

### Skalierbare Query-Patterns

**Vermeiden:**
```sql
-- SCHLECHT: Full Table Scan
SELECT COUNT(*) FROM wp_dsgvo_submissions;

-- SCHLECHT: Alle Formulare mit Submission-Count (N+1)
SELECT f.*, (SELECT COUNT(*) FROM wp_dsgvo_submissions WHERE form_id = f.id) as count
FROM wp_dsgvo_forms f;
```

**Bevorzugen:**
```sql
-- GUT: Count nur für ein Formular (Index-Nutzung)
SELECT COUNT(*) FROM wp_dsgvo_submissions WHERE form_id = ?;

-- GUT: Batch-Count mit GROUP BY
SELECT form_id, COUNT(*) as submission_count
FROM wp_dsgvo_submissions
GROUP BY form_id;
```

### Viele Felder pro Formular

Formularfelder werden als **JSON in `config`** gespeichert ODER in der separaten `dsgvo_form_fields`-Tabelle. Performance-Vergleich:

| Ansatz | Lesen | Schreiben | Flexibilität |
|--------|-------|-----------|--------------|
| JSON in `config` | 1 Query, 1 Parse | 1 UPDATE | Sehr hoch |
| Separate Tabelle | 1 JOIN oder 2 Queries | N INSERTs | Struktur-Validierung möglich |

**Empfehlung**: Separate `dsgvo_form_fields`-Tabelle verwenden. Die Anzahl Felder pro Formular bleibt typischerweise unter 30 — das ist kein Performance-Problem. Der Vorteil: Einzelne Felder können sortiert, validiert und referenziert werden.

Die Felder pro Formular werden zusammen mit der Formular-Config gecacht (Abschnitt 6), daher ist die extra Query kein Problem.

---

## 13. DSGVO-Lösch-Cron-Jobs & Retention

> **Abgeglichen mit DPO (DATA_PROTECTION.md v1.1)**

### 13.1 Retention-Konzept

| Parameter | Wert | Quelle |
|-----------|------|--------|
| Default-Speicherfrist | 90 Tage | DPO-FINDING-01 |
| Minimum | 1 Tag | DPO-FINDING-01 (kein `0` für unbegrenzt!) |
| Maximum | 3650 Tage (10 Jahre) | Gesetzl. Aufbewahrungspflichten |
| Konfigurierbar pro | Formular (`retention_days`) | Admin-Einstellung |
| Granularität | Komplette Submission + Dateien | Kein Einzel-Feld-Löschen (atomar verschlüsselt) |

### 13.2 Lösch-Cron: Abgelaufene Submissions

**Intervall:** Stündlich (`wp_schedule_event` mit `hourly`)
**Batch-Größe:** 100–500 Rows pro Durchlauf (konfigurierbar)

```php
function cron_delete_expired_submissions(int $batch_size = 200): array {
    global $wpdb;
    $table = $wpdb->prefix . 'dsgvo_submissions';

    // 1. Abgelaufene, NICHT gesperrte Submissions finden (Art. 18 beachten!)
    $expired_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$table}
         WHERE expires_at < NOW() AND is_restricted = 0
         ORDER BY expires_at ASC
         LIMIT %d",
        $batch_size
    ));

    if (empty($expired_ids)) {
        return ['deleted' => 0, 'skipped_locked' => 0];
    }

    // 2. Datei-Anhänge vom Filesystem löschen (VOR DB-Delete!)
    $this->delete_attachment_files($expired_ids);

    // 3. DB-Rows löschen (CASCADE löscht auch dsgvo_attachments Rows)
    $placeholders = implode(',', array_fill(0, count($expired_ids), '%d'));
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE id IN ($placeholders)",
        ...$expired_ids
    ));

    // 4. Audit-Log schreiben (SEC-AUDIT-01)
    foreach ($expired_ids as $id) {
        $this->audit_log('auto_delete', [
            'submission_id' => $id,
            'reason'        => 'retention_expired',
        ]);
    }

    // 5. Gesperrte zählen (für Admin-Dashboard Info)
    $locked_count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$table} WHERE expires_at < NOW() AND is_restricted = 1"
    );

    return ['deleted' => $deleted, 'skipped_locked' => $locked_count];
}
```

**Performance-Überlegungen:**
- `ORDER BY expires_at ASC LIMIT N` nutzt den `idx_expiry_restricted` Index → kein Full Table Scan
- Batch-Löschung verhindert lange Table-Locks auf InnoDB
- Audit-Log Writes sind leichtgewichtig (INSERT-only)
- Bei sehr vielen abgelaufenen Rows: Cron läuft mehrfach hintereinander bis alles aufgeräumt

### 13.3 Formular-Löschung (Cascade)

Wenn ein Admin ein Formular löscht, werden ALLE Submissions gelöscht. Das kann ein großer Batch sein.

```php
function delete_form(int $form_id): bool {
    global $wpdb;

    // 1. Alle Submission-IDs für Datei-Cleanup sammeln
    $submission_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}dsgvo_submissions WHERE form_id = %d",
        $form_id
    ));

    // 2. Dateien in Batches löschen (Filesystem)
    foreach (array_chunk($submission_ids, 100) as $batch) {
        $this->delete_attachment_files($batch);
    }

    // 3. Formular löschen — CASCADE löscht submissions + attachments + recipients
    $wpdb->delete($wpdb->prefix . 'dsgvo_forms', ['id' => $form_id]);

    // 4. Cache invalidieren
    delete_transient('dsgvo_form_config_' . $form_id);

    return true;
}
```

**Performance-Risiko:** Bei 10.000+ Submissions kann der CASCADE-Delete mehrere Sekunden dauern.
**Mitigation:** Als Background-Job ausführen, wenn Count > 500. Admin sieht "Formular wird gelöscht..."-Status.

### 13.4 Audit-Log Cleanup

> **DPO-Anforderung:** Audit-Log Einträge 1 Jahr aufbewahren, dann löschen.
> IP-Adressen im Audit-Log nach 90 Tagen nullen.

**Zwei separate Cron-Jobs:**

```php
// Monatlich: IP-Adressen im Audit-Log nullen (nach 90 Tagen)
function cron_audit_log_ip_cleanup(): int {
    global $wpdb;
    return $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}dsgvo_audit_log
         SET ip_address = NULL
         WHERE ip_address IS NOT NULL AND timestamp < %s",
        date('Y-m-d H:i:s', strtotime('-90 days'))
    ));
}

// Jährlich: Alte Audit-Log Einträge löschen (nach 1 Jahr)
function cron_audit_log_cleanup(): int {
    global $wpdb;
    return $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}dsgvo_audit_log
         WHERE timestamp < %s",
        date('Y-m-d H:i:s', strtotime('-1 year'))
    ));
}
```

### 13.5 Cron-Job Übersicht

| Cron-Job | Intervall | Batch-Größe | Index genutzt | Erwartete Laufzeit |
|----------|-----------|-------------|---------------|--------------------|
| Abgelaufene Submissions löschen | Stündlich | 100–500 | `idx_expiry_restricted` | < 2s pro Batch |
| Temporäre Upload-Chunks aufräumen | Stündlich | — | Filesystem-Scan | < 1s |
| Verwaiste Dateien aufräumen | Täglich | — | `idx_submission_id` | < 5s |
| Audit-Log IP-Cleanup | Monatlich | All matching | Timestamp-Index | < 1s |
| Audit-Log Cleanup | Jährlich | All matching | Timestamp-Index | < 2s |

### 13.6 Performance-Kennzahlen (Löschung)

- **Ziel**: Stündlicher Lösch-Cron < 5 Sekunden (200 Rows Batch)
- **Ziel**: Keine spürbare DB-Belastung während Lösch-Cron (< 100ms Lock-Zeit pro Batch)
- **Ziel**: Formular-Löschung mit 1.000 Submissions < 10 Sekunden
- **Ziel**: Audit-Log Cleanup < 2 Sekunden

---

## 14. Gutenberg-Block React-Performance

### Re-Rendering minimieren

Der Block-Editor (React) sollte unnötige Re-Renders vermeiden:

**1. `useMemo` für berechnete Werte:**
```jsx
const FormPreview = ({ fields, formTitle }) => {
    // Felder nur neu berechnen wenn sich fields tatsächlich ändert
    const sortedFields = useMemo(
        () => [...fields].sort((a, b) => a.sort_order - b.sort_order),
        [fields]
    );

    return <div>{/* ... */}</div>;
};
```

**2. `useCallback` für Event-Handler:**
```jsx
const FieldEditor = ({ field, onUpdate }) => {
    const handleChange = useCallback((value) => {
        onUpdate(field.id, value);
    }, [field.id, onUpdate]);

    return <TextControl onChange={handleChange} />;
};
```

**3. Individuelle Feld-Komponenten als `React.memo`:**
```jsx
const FieldItem = React.memo(({ field, onUpdate, onDelete }) => {
    return (
        <div className="dsgvo-field-item">
            <TextControl value={field.label} onChange={/* ... */} />
        </div>
    );
});
```

### State-Management im Block

```
Block State (attributes):
├── formId          ← Nur die Form-ID speichern
└── (keine Felder!) ← Felder NICHT in Block-Attributes

Server-Side Rendering:
└── render_callback lädt Form-Config → HTML generieren
```

**Warum**: Block-Attributes werden in `post_content` serialisiert. Formular-Konfiguration gehört in die DB-Tabelle, nicht in den Post-Content. Der Block speichert nur die `formId`.

### Gutenberg-spezifische Patterns

- **`useSelect` mit Selector-Stabilität**: Selektoren memorizen, nicht bei jedem Render neue Referenzen
- **`useEntityProp` vermeiden** für Custom Tables — eigene REST-API + `apiFetch` nutzen
- **Inspector Controls**: Lazy Loading der Formular-Konfiguration in der Sidebar (nicht bei Block-Mount)

### Frontend-Formular (viewScript)

Das Frontend-Formular sollte **Vanilla JS** oder ein minimales Framework verwenden — kein React im Frontend:

```javascript
// viewScript: Leichtgewichtig, kein Framework
document.querySelectorAll('.wp-dsgvo-form').forEach(form => {
    form.addEventListener('submit', handleSubmit);
});
```

**Begründung**: React im Frontend würde das Bundle um 40+ KB aufblähen. Formulare sind statisches HTML mit Event-Handlern — dafür braucht es kein Framework.

### Performance-Kennzahlen
- **Ziel**: Block-Editor Re-Render bei Feldänderung < 16 ms (60fps)
- **Ziel**: Frontend viewScript < 5 KB (gzipped)
- **Ziel**: Block-Mount im Editor < 200 ms

---

## 15. Performance-Budget (Zusammenfassung)

| Metrik | Budget |
|--------|--------|
| **Frontend-Assets** | |
| Seite ohne Formular | 0 KB zusätzlich |
| Frontend-Bundle (viewScript + viewStyle) | < 30 KB gzipped |
| Frontend viewScript allein | < 5 KB gzipped |
| Admin-Bundle | < 80 KB gzipped |
| **Datenbank** | |
| DB-Queries pro Frontend-Request | ≤ 3 |
| DB-Queries pro Admin-Seite | ≤ 5 |
| Paginierte Liste (50.000 Rows) | < 100 ms |
| COUNT-Query (50.000 Rows) | < 50 ms |
| **Verschlüsselung** | |
| Einzelne Submission encrypt/decrypt | < 5 ms |
| 20 Submissions entschlüsseln | < 100 ms |
| 10 MB Datei verschlüsseln (Streaming) | < 500 ms |
| Peak Memory Datei-Verschlüsselung | < 2 MB |
| **Response-Zeiten** | |
| Formular-Rendering (cached) | < 10 ms |
| Submission speichern (inkl. Encrypt) | < 300 ms |
| Submission entschlüsseln (einzeln) | < 200 ms |
| Paginierte Liste laden (20 Items) | < 100 ms |
| Admin-Seitenaufbau | < 300 ms |
| AJAX-Responses | < 200 ms |
| **Gutenberg** | |
| Block-Editor Re-Render | < 16 ms |
| Block-Mount | < 200 ms |
| **DSGVO-Lösch-Cron** | |
| Stündlicher Lösch-Batch (200 Rows) | < 5 s |
| DB-Lock pro Lösch-Batch | < 100 ms |
| Formular-Löschung (1.000 Submissions) | < 10 s |
| Audit-Log Cleanup | < 2 s |

---

## 16. Monitoring-Empfehlung

### Entwicklung

**Query Monitor** (WordPress Plugin) integrieren:
- Anzahl DB-Queries pro Seite überwachen
- Langsame Queries identifizieren
- Enqueued Scripts/Styles pro Seite prüfen
- Hook-Ausführungszeiten messen

### Produktion

- WordPress `SAVEQUERIES` Flag für Debugging bei Bedarf
- PHP `memory_get_peak_usage()` in Submission-Handler loggen
- `microtime(true)` für Encryption-Timing in Development

### Automatisierte Checks

Die Entwickler sollten folgende Checks in ihre Test-Suite aufnehmen:

```php
// Test: Keine Assets auf Seiten ohne Block
$this->assertEmpty(wp_scripts()->queue, 'Keine Plugin-Scripts auf Seiten ohne Block');

// Test: Encrypted_data nicht in Listenabfragen
$this->assertStringNotContainsString(
    'encrypted_data',
    $last_query,
    'Listenabfrage darf encrypted_data nicht enthalten'
);

// Test: Pagination erzwungen
$this->assertLessThanOrEqual(50, $result['per_page'], 'Max 50 Items pro Seite');
```
