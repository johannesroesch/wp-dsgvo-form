# Consent-Versioning: Zwei Varianten (Code-Entwurf)

**Task:** #46 Vorarbeit (DPO-FINDING-13, LEGAL-I18N-04/05)
**Autor:** developer-3
**Status:** Entwurf -- Architekten-Entscheidung ausstehend

## Problem

Aktuell: `consent_text` (text) + `consent_version` (int) auf `dsgvo_forms` -- ein einziger Text/Version fuer alle Sprachen.

Anforderung LEGAL-I18N-04/05: Consent-Versioning MUSS pro Sprache separat erfolgen. Bei 6 Sprachen (de_DE, en_US, fr_FR, es_ES, it_IT, sv_SE) braucht jede Sprache ihren eigenen versionierten Text.

## Betroffene Dateien (bei finaler Implementierung)

| Datei | Aenderung |
|-------|-----------|
| `includes/Activator.php` | Schema-Migration |
| `includes/Models/Form.php` | Consent-Text CRUD, Versionierung |
| `includes/Models/Submission.php` | consent_locale Verknuepfung |
| `includes/Api/SubmitEndpoint.php` | Consent-Lookup per Locale |
| `includes/Block/FormBlock.php` | Fail-Closed per Locale |
| `includes/Admin/FormEditPage.php` | Admin-UI fuer Mehrsprach-Texte |

---

## Variante A: JSON-Spalten in dsgvo_forms

### Konzept

`consent_text` und `consent_version` werden von skalaren Werten zu JSON-Objekten:
```
consent_text: {"de_DE": "Ich stimme zu...", "en_US": "I agree...", ...}
consent_version: {"de_DE": 3, "en_US": 2, "fr_FR": 1}
```

### Schema-Aenderung (Activator.php)

Keine neue Tabelle. Bestehende Spalten bleiben, Typ aendert sich semantisch:
- `consent_text text` -- bleibt `text`, Inhalt wird JSON
- `consent_version` -- wird von `int unsigned` zu `text` (JSON-String)

```php
// Migration in Activator: Bestehende Daten konvertieren
private static function migrate_consent_to_json(): void {
    global $wpdb;
    $table = $wpdb->prefix . 'dsgvo_forms';

    $forms = $wpdb->get_results(
        "SELECT id, consent_text, consent_version FROM `{$table}`",
        ARRAY_A
    );

    foreach ( $forms as $form ) {
        // Pruefen ob bereits JSON (idempotent).
        if ( json_decode( $form['consent_text'] ) !== null ) {
            continue;
        }

        $site_locale = get_locale(); // z.B. de_DE

        $json_text    = wp_json_encode( [ $site_locale => $form['consent_text'] ] );
        $json_version = wp_json_encode( [ $site_locale => (int) $form['consent_version'] ] );

        $wpdb->update(
            $table,
            [
                'consent_text'    => $json_text,
                'consent_version' => $json_version,
            ],
            [ 'id' => $form['id'] ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }
}
```

### Form Model (Form.php) -- Aenderungen

```php
/**
 * Properties aendern:
 * - consent_text: string -> array (JSON-decoded)
 * - consent_version: int -> array (JSON-decoded)
 */

/** @var array<string, string> Consent-Texte pro Locale {"de_DE": "...", "en_US": "..."} */
public array $consent_texts = [];

/** @var array<string, int> Versionen pro Locale {"de_DE": 3, "en_US": 2} */
public array $consent_versions = [];

// Rueckwaertskompatibilitaet: consent_text und consent_version als Getter
// die den Default-Locale liefern (fuer bestehenden Code).
// HINWEIS: Diese Getter sind Uebergangs-Loesung -- nach Migration entfernen.

/**
 * Returns consent text for a specific locale.
 * Fail-Closed: Returns empty string if locale not found (DPO-FINDING-13).
 */
public function get_consent_text( string $locale ): string {
    return $this->consent_texts[ $locale ] ?? '';
}

/**
 * Returns consent version for a specific locale.
 */
public function get_consent_version( string $locale ): int {
    return $this->consent_versions[ $locale ] ?? 0;
}

/**
 * Sets consent text for a locale. Auto-increments version if text changed.
 * (LEGAL-TEMPLATE-06, LEGAL-I18N-05)
 */
public function set_consent_text( string $locale, string $text ): void {
    $old_text = $this->consent_texts[ $locale ] ?? '';

    $this->consent_texts[ $locale ] = $text;

    if ( $old_text !== $text && $text !== '' ) {
        $current_version = $this->consent_versions[ $locale ] ?? 0;
        $this->consent_versions[ $locale ] = $current_version + 1;
    }
}

/**
 * Returns list of locales that have a non-empty consent text.
 * @return string[]
 */
public function get_available_consent_locales(): array {
    return array_keys( array_filter( $this->consent_texts, static fn( $t ) => trim( $t ) !== '' ) );
}
```

#### from_row() Aenderung

```php
// JSON-Dekodierung in from_row():
$form->consent_texts   = json_decode( (string) ( $row['consent_text'] ?? '{}' ), true ) ?: [];
$form->consent_versions = json_decode( (string) ( $row['consent_version'] ?? '{}' ), true ) ?: [];

// Rueckwaertskompatibilitaet: Falls noch kein JSON (alte Daten), wrappen
if ( ! is_array( $form->consent_texts ) ) {
    $form->consent_texts = [];
}
```

#### update_record() Aenderung

```php
private function update_record(): int {
    global $wpdb;
    $table = self::get_table_name();

    // Versionierung passiert bereits in set_consent_text() pro Locale.
    // Nur noch DB-Update noetig.
    $data = $this->to_db_array();

    $wpdb->update(
        $table,
        $data,
        [ 'id' => $this->id ],
        self::get_formats( $data ),
        [ '%d' ]
    );

    self::invalidate_cache( $this->id );
    return $this->id;
}
```

#### to_db_array() Aenderung

```php
private function to_db_array(): array {
    return [
        // ... bestehende Felder ...
        'consent_text'    => wp_json_encode( $this->consent_texts ),
        'consent_version' => wp_json_encode( $this->consent_versions ),
    ];
}
```

### SubmitEndpoint.php -- Aenderungen

```php
// In verify_consent():
// Statt $form->consent_version direkt zu verwenden:
$consent_locale  = (string) ( $params['consent_locale'] ?? '' );
$consent_text    = $form->get_consent_text( $consent_locale );
$consent_version = $form->get_consent_version( $consent_locale );

// Fail-Closed: Kein Consent-Text fuer dieses Locale = Ablehnung
if ( $consent_text === '' ) {
    return new \WP_Error(
        'consent_text_missing_for_locale',
        __( 'Fuer diese Sprache liegt kein Einwilligungstext vor.', 'wp-dsgvo-form' ),
        [ 'status' => 422 ]
    );
}

return [
    'consent_text_version' => $consent_version,
    'consent_timestamp'    => current_time( 'mysql', true ),
    'consent_locale'       => $consent_locale,
];
```

### FormBlock.php -- Fail-Closed per Locale

```php
// In render():
if ( $form->legal_basis === 'consent' ) {
    $consent_text = $form->get_consent_text( $locale );
    if ( trim( $consent_text ) === '' ) {
        return ''; // Fail-Closed: Formular nicht anzeigen (DPO-FINDING-13)
    }
}

// In render_consent_checkbox():
private function render_consent_checkbox( Form $form, string $locale ): string {
    $consent_text    = $form->get_consent_text( $locale );
    $consent_version = $form->get_consent_version( $locale );

    // ... HTML mit $consent_text und $consent_version ...
}
```

### Vorteile Variante A

1. **Kein neues Schema** -- keine neue Tabelle, keine FKs
2. **Einfache Migration** -- bestehende Daten werden gewrappt
3. **Atomares Update** -- consent_text + version in einem Row-Update
4. **Performance** -- ein einziger SELECT fuer Form + alle Consent-Texte

### Nachteile Variante A

1. **Kein SQL-Indexing** auf einzelne Locales moeglich
2. **JSON-Validierung** muss in PHP passieren (kein DB-Constraint)
3. **consent_version Spaltentyp** aendert sich von `int` zu `text` (Breaking Change fuer bestehende Queries)
4. **Keine Historie** -- nur aktuelle Version pro Locale gespeichert, kein Audit-Trail der Textaenderungen
5. **MySQL < 5.7.8** hat keine native JSON-Unterstuetzung (aber WP 6.x braucht 5.7+)

---

## Variante B: Separate Tabelle dsgvo_consent_versions

### Konzept

Neue Tabelle speichert alle Consent-Text-Versionen pro Form + Locale.
`dsgvo_forms.consent_text` und `consent_version` werden deprecated/entfernt.

### Schema (Activator.php -- neue Tabelle)

```php
// 7. Consent text versions (LEGAL-I18N-04/05, DPO-FINDING-13).
dbDelta(
    "CREATE TABLE {$prefix}dsgvo_consent_versions (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        form_id bigint(20) unsigned NOT NULL,
        locale varchar(10) NOT NULL,
        consent_text text NOT NULL,
        version int unsigned NOT NULL DEFAULT 1,
        is_current tinyint(1) NOT NULL DEFAULT 1,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY form_locale_version (form_id,locale,version),
        KEY form_locale_current (form_id,locale,is_current),
        KEY form_id (form_id)
    ) {$charset_collate};"
);
```

### Migration bestehender Daten

```php
private static function migrate_consent_to_table(): void {
    global $wpdb;
    $forms_table   = $wpdb->prefix . 'dsgvo_forms';
    $consent_table = $wpdb->prefix . 'dsgvo_consent_versions';

    // Nur migrieren wenn consent_versions-Tabelle leer.
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$consent_table}`" );
    if ( $count > 0 ) {
        return;
    }

    $forms = $wpdb->get_results(
        "SELECT id, consent_text, consent_version FROM `{$forms_table}` WHERE consent_text != ''",
        ARRAY_A
    );

    $site_locale = get_locale();

    foreach ( $forms as $form ) {
        $wpdb->insert(
            $consent_table,
            [
                'form_id'      => (int) $form['id'],
                'locale'       => $site_locale,
                'consent_text' => $form['consent_text'],
                'version'      => (int) $form['consent_version'],
                'is_current'   => 1,
            ],
            [ '%d', '%s', '%s', '%d', '%d' ]
        );
    }
}
```

### Neues Model: ConsentVersion.php

```php
<?php
declare(strict_types=1);

namespace WpDsgvoForm\Models;

defined('ABSPATH') || exit;

/**
 * ConsentVersion model -- CRUD fuer dsgvo_consent_versions.
 *
 * Speichert versionierte Einwilligungstexte pro Formular und Locale.
 * Jede Textaenderung erzeugt eine neue Version; alte Versionen bleiben
 * als Nachweis erhalten (Art. 7 Abs. 1 DSGVO).
 *
 * @privacy-relevant Art. 7 DSGVO -- Nachweis der Einwilligung
 * @privacy-relevant LEGAL-I18N-04/05 -- Sprachspezifische Versionierung
 */
class ConsentVersion {

    public int $id          = 0;
    public int $form_id     = 0;
    public string $locale   = '';
    public string $consent_text = '';
    public int $version     = 1;
    public bool $is_current = true;
    public string $created_at = '';

    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'dsgvo_consent_versions';
    }

    /**
     * Returns the current consent text for a form + locale.
     * Fail-Closed: Returns null if no text exists (DPO-FINDING-13).
     */
    public static function find_current( int $form_id, string $locale ): ?self {
        global $wpdb;
        $table = self::get_table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE form_id = %d AND locale = %s AND is_current = %d",
                $form_id,
                $locale,
                1
            ),
            ARRAY_A
        );

        return $row !== null ? self::from_row( $row ) : null;
    }

    /**
     * Returns a specific historical consent version (fuer Nachweis Art. 7).
     */
    public static function find_version( int $form_id, string $locale, int $version ): ?self {
        global $wpdb;
        $table = self::get_table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE form_id = %d AND locale = %s AND version = %d",
                $form_id,
                $locale,
                $version
            ),
            ARRAY_A
        );

        return $row !== null ? self::from_row( $row ) : null;
    }

    /**
     * Returns all consent versions for a form (all locales, chronological).
     * Used in admin UI for consent history view.
     *
     * @return self[]
     */
    public static function find_all_by_form( int $form_id ): array {
        global $wpdb;
        $table = self::get_table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE form_id = %d ORDER BY locale ASC, version DESC",
                $form_id
            ),
            ARRAY_A
        );

        return array_map( [ self::class, 'from_row' ], $rows ?: [] );
    }

    /**
     * Returns all locales that have a current consent text for a form.
     * @return string[]
     */
    public static function get_available_locales( int $form_id ): array {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT locale FROM `{$table}` WHERE form_id = %d AND is_current = %d",
                $form_id,
                1
            )
        ) ?: [];
    }

    /**
     * Creates a new consent version for a form + locale.
     *
     * Auto-increments version number. Marks previous version as not current.
     * Idempotent: If text hasn't changed, returns existing version.
     *
     * @privacy-relevant LEGAL-TEMPLATE-06 -- Version auto-incremented on change
     * @privacy-relevant LEGAL-I18N-05 -- Pro-Sprache-Versionierung
     */
    public static function create_or_update( int $form_id, string $locale, string $text ): self {
        global $wpdb;
        $table = self::get_table_name();

        // Pruefen ob sich Text geaendert hat.
        $current = self::find_current( $form_id, $locale );

        if ( $current !== null && $current->consent_text === $text ) {
            return $current; // Keine Aenderung -- keine neue Version
        }

        $new_version = $current !== null ? $current->version + 1 : 1;

        // Alte Version als nicht-aktuell markieren.
        if ( $current !== null ) {
            $wpdb->update(
                $table,
                [ 'is_current' => 0 ],
                [
                    'form_id' => $form_id,
                    'locale'  => $locale,
                    'is_current' => 1,
                ],
                [ '%d' ],
                [ '%d', '%s', '%d' ]
            );
        }

        // Neue Version einfuegen.
        $wpdb->insert(
            $table,
            [
                'form_id'      => $form_id,
                'locale'       => $locale,
                'consent_text' => $text,
                'version'      => $new_version,
                'is_current'   => 1,
            ],
            [ '%d', '%s', '%s', '%d', '%d' ]
        );

        if ( $wpdb->insert_id === 0 ) {
            throw new \RuntimeException( 'Failed to insert consent version: ' . $wpdb->last_error );
        }

        $new = new self();
        $new->id           = (int) $wpdb->insert_id;
        $new->form_id      = $form_id;
        $new->locale       = $locale;
        $new->consent_text = $text;
        $new->version      = $new_version;
        $new->is_current   = true;

        return $new;
    }

    /**
     * Deletes ALL consent versions for a form (called on form deletion).
     * FK CASCADE from dsgvo_forms handles this if FK is set.
     */
    public static function delete_by_form( int $form_id ): bool {
        global $wpdb;
        $table  = self::get_table_name();
        $result = $wpdb->delete( $table, [ 'form_id' => $form_id ], [ '%d' ] );
        return $result !== false;
    }

    private static function from_row( array $row ): self {
        $cv               = new self();
        $cv->id           = (int) ( $row['id'] ?? 0 );
        $cv->form_id      = (int) ( $row['form_id'] ?? 0 );
        $cv->locale       = (string) ( $row['locale'] ?? '' );
        $cv->consent_text = (string) ( $row['consent_text'] ?? '' );
        $cv->version      = (int) ( $row['version'] ?? 1 );
        $cv->is_current   = (bool) ( $row['is_current'] ?? true );
        $cv->created_at   = (string) ( $row['created_at'] ?? '' );
        return $cv;
    }
}
```

### Form Model Aenderungen (Variante B)

```php
// consent_text und consent_version Spalten aus dsgvo_forms entfernen
// oder deprecated lassen fuer Rueckwaertskompatibilitaet.

// Neue Methoden in Form:

/**
 * Returns consent text for this form + locale via ConsentVersion model.
 * Fail-Closed: Returns empty string if no version exists (DPO-FINDING-13).
 */
public function get_consent_text( string $locale ): string {
    $cv = ConsentVersion::find_current( $this->id, $locale );
    return $cv !== null ? $cv->consent_text : '';
}

/**
 * Returns consent version number for this form + locale.
 */
public function get_consent_version( string $locale ): int {
    $cv = ConsentVersion::find_current( $this->id, $locale );
    return $cv !== null ? $cv->version : 0;
}

/**
 * Sets/updates consent text for a locale. Auto-versions.
 */
public function set_consent_text( string $locale, string $text ): ConsentVersion {
    return ConsentVersion::create_or_update( $this->id, $locale, $text );
}

/**
 * Returns locales with available consent text.
 * @return string[]
 */
public function get_available_consent_locales(): array {
    return ConsentVersion::get_available_locales( $this->id );
}
```

### SubmitEndpoint + FormBlock -- Identisch zu Variante A

Die Aufrufer-Seite (SubmitEndpoint, FormBlock) nutzt die gleichen Methoden:
- `$form->get_consent_text( $locale )`
- `$form->get_consent_version( $locale )`

Der Unterschied liegt nur in der Implementierung (JSON vs. DB-Tabelle).

### Vorteile Variante B

1. **Vollstaendige Historie** -- Alle alten Consent-Texte bleiben gespeichert (Art. 7 Nachweis)
2. **SQL-Indexing** -- Effiziente Queries pro Locale moeglich (UNIQUE KEY)
3. **Keine Typ-Aenderung** -- consent_version bleibt `int`, neuer Kontext in eigener Tabelle
4. **Saubere Normalisierung** -- Standard-Datenbankdesign, keine JSON-Hacks
5. **Audit-Trail** -- `created_at` pro Version zeigt wann Text geaendert wurde
6. **Nachweis Art. 7 Abs. 1** -- Exakter Wortlaut jeder Consent-Version abrufbar
7. **Einfaches Loeschen** -- FK CASCADE bei Form-Loeschung

### Nachteile Variante B

1. **Neue Tabelle** -- Schema-Komplexitaet steigt
2. **N+1 Problem** -- Form-Laden braucht zusaetzlichen Query fuer Consent
3. **Migration** -- Bestehende Daten muessen ueberfuehrt werden
4. **Admin-UI** -- Consent-Editor muss 6 Tabs/Felder rendern

---

## Vergleich

| Kriterium | Variante A (JSON) | Variante B (Tabelle) |
|-----------|-------------------|---------------------|
| Schema-Komplexitaet | Niedrig | Mittel |
| Nachweis Art. 7 (Historie) | Keine Historie | Vollstaendige Historie |
| Performance (Lesen) | 1 Query | 2 Queries (cachebar) |
| Performance (Schreiben) | 1 Update | 1 Update + 1 Insert |
| SQL-Indexing pro Locale | Nein | Ja (UNIQUE KEY) |
| Migration bestehender Daten | Einfach (JSON wrap) | Mittel (Insert in neue Tabelle) |
| Aufrufer-Code (SubmitEndpoint etc.) | Identisch | Identisch |
| DSGVO Art. 7 Nachweis | Schwach (nur aktuelle Version) | Stark (alle Versionen) |
| MySQL-Kompatibilitaet | WP 6.x Minimum genuegt | Standard relational |

## Empfehlung (developer-3 an Architekt)

**Variante B** ist DSGVO-konformer: Art. 7 Abs. 1 verlangt den Nachweis, WELCHEN Text der Nutzer akzeptiert hat. Variante A speichert nur die aktuelle Version -- bei Textaenderung ist der alte Wortlaut weg. Variante B haelt alle Versionen vor und ermoeglicht exakten Nachweis.

Das N+1-Problem laesst sich durch Caching (WP Transients, wie bereits bei Form::find()) loesen.

**Architekten-Entscheidung ausstehend.**
