# UX-Konzept: wp-dsgvo-form

## 1. Admin-UI: Formular-Builder

### 1.1 Formular-Liste (Admin-Hauptseite)

Die Einstiegsseite zeigt alle konfigurierten Formulare in einer WordPress-typischen List-Table.

```
+------------------------------------------------------------------------+
|  DSGVO Formulare                                    [+ Neues Formular] |
+------------------------------------------------------------------------+
|                                                                        |
|  Suche: [___________________] [Suchen]                                 |
|                                                                        |
|  [ ] | Name               | Felder | Empfaenger | Einsendungen | Datum |
|  ----|--------------------+--------+------------+--------------+-------|
|  [ ] | Kontaktformular    |    5   |     2      |      23      | 12.04 |
|  [ ] | Reparatur-Anfrage  |    8   |     3      |      47      | 10.04 |
|  [ ] | Datenschutz-Auskunft|   4   |     1      |       5      | 08.04 |
|                                                                        |
|  Bulk-Aktionen: [Auswahl v] [Anwenden]                                 |
|                                                                        |
|  Hover-Aktionen pro Zeile:                                             |
|    Bearbeiten | Duplizieren | Shortcode | Loeschen                     |
+------------------------------------------------------------------------+
```

**UX-Entscheidungen:**
- WordPress-native WP_List_Table fuer vertraute Bedienung
- Spalte "Einsendungen" verlinkt direkt zur Einsendungsliste
- "Shortcode" kopiert `[dsgvo_form id="X"]` in die Zwischenablage (Fallback fuer Classic Editor)
- "Duplizieren" ermoeglicht schnelle Erstellung aehnlicher Formulare

---

### 1.2 Formular bearbeiten / erstellen

Zweispalten-Layout: Links der Feld-Builder, rechts die Formular-Einstellungen.

```
+------------------------------------------------------------------------+
|  < Zurueck zur Liste          Formular bearbeiten             [Speichern] |
+------------------------------------------------------------------------+
|                                          |                              |
|  Formular-Name:                          |  EINSTELLUNGEN               |
|  [Kontaktformular________________]       |                              |
|                                          |  Empfaenger (WP-User):       |
|  FELDER                  [+ Feld]        |  +-------------------------+ |
|  +------------------------------------+  |  | max@example.de     [x]  | |
|  | :: | E-Mail *           [Txt] [x]  |  |  | info@example.de    [x]  | |
|  |    | Typ: E-Mail | Pflicht: Ja     |  |  +-------------------------+ |
|  +------------------------------------+  |  [+ Empfaenger zuweisen]     |
|  | :: | Betreff            [Txt] [x]  |  |                              |
|  |    | Typ: Text | Pflicht: Nein     |  |  SPRACHE:                    |
|  +------------------------------------+  |  (o) Automatisch (WP-Locale) |
|  | :: | --- Hinweistext ---    [x]    |  |  ( ) Feste Sprache:          |
|  |    | "Bitte beschreiben Sie..."    |  |      [Deutsch (de_DE)    v]  |
|  +------------------------------------+  |                              |
|  | :: | Nachricht *        [Txt] [x]  |  |  CAPTCHA:                    |
|  |    | Typ: Textarea | Pflicht: Ja   |  |  [x] CAPTCHA aktivieren      |
|  +------------------------------------+  |                              |
|  | :: | Datei-Upload       [Txt] [x]  |  |  SUBMIT-BUTTON:              |
|  |    | Typ: Datei | Max: 5 MB       |  |  Label: [Absenden_______]    |
|  +------------------------------------+  |                              |
|                                          |  DSGVO-EINWILLIGUNG:         |
|  [+ Feld hinzufuegen] [+ Textblock]     |  Loeschfrist: [90] Tage      |
|                                          |  Datenschutz-URL:            |
|                                          |  [/datenschutz_________]     |
|                                          |  [Einwilligungstext          |
|                                          |   bearbeiten ->]             |
|                                          |                              |
|                                          |  BESTAETIGUNGSNACHRICHT:      |
|                                          |  [Volltext bearbeiten ->]    |
+------------------------------------------------------------------------+

Legende:  ::  = Drag-Handle    [Txt] = Feld bearbeiten    [x] = Entfernen
```

**UX-Entscheidungen:**
- **Drag & Drop** via `::` Handle zum Sortieren (mit Tastatur-Fallback: Up/Down-Buttons werden bei Hover eingeblendet)
- **Inline-Bearbeitung**: Klick auf `[Txt]` oeffnet ein expandierendes Panel unter dem Feld
- **Statische Textbloecke**: Ueber `[+ Textblock]` einfuegbar, visuell abgesetzt (gestrichelte Umrandung)
- **DSGVO-Einwilligung** ist standardmaessig aktiv und Pflicht — Admin kann den Einwilligungstext anpassen, aber nicht deaktivieren
- **Einwilligungstext** wird aus Bausteinen generiert: Zweck + Loeschfrist + Widerrufshinweis + Datenschutz-Link. Admin kann auch den Freitext komplett ueberschreiben.
- **Bestaetigungsnachricht** enthaelt standardmaessig Verschluesselungs-, Loeschfrist- und Widerrufshinweis
- **Empfaenger-Verwaltung**: Direkt in der Seitenleiste, nicht auf separater Seite

**Art. 9-Warnung (besondere Datenkategorien):**

Beim Erstellen eines neuen Formulars wird ein Hinweis angezeigt:
```
+------------------------------------------------------------------+
| /i\ Datenschutz-Hinweis                                          |
|                                                                  |
| Falls dieses Formular besondere Kategorien personenbezogener     |
| Daten erfasst (z.B. Gesundheitsdaten, religioese Ueberzeugungen, |
| politische Meinungen), gelten strengere Anforderungen nach       |
| Art. 9 DSGVO. Bitte pruefen Sie dies mit Ihrem                  |
| Datenschutzbeauftragten.                                         |
|                                                                  |
| [ ] Dieses Formular erfasst besondere Datenkategorien            |
|     (aktiviert erweiterte Schutzmaassnahmen)                     |
+------------------------------------------------------------------+
```

Wenn aktiviert: Formular wird in der Liste mit einem Schild-Icon markiert, Loeschfrist-Empfehlung wird auf 30 Tage reduziert.

---

### 1.3 Feld hinzufuegen — Modal

```
+------------------------------------------+
|  Feld hinzufuegen                    [x] |
+------------------------------------------+
|                                          |
|  Feldtyp waehlen:                        |
|                                          |
|  +------+  +------+  +------+  +------+ |
|  | Aa   |  | @    |  | 123  |  | Tel  | |
|  | Text |  |E-Mail|  |Nummer|  |Telefon| |
|  +------+  +------+  +------+  +------+ |
|  +------+  +------+  +------+  +------+ |
|  | [__] |  | (o)  |  | [v]  |  | [=]  | |
|  |Textarea| Radio | |Select |  |Datei | |
|  +------+  +------+  +------+  +------+ |
|  +------+  +------+  +------+           |
|  |  []  |  | Cal  |  | URL  |           |
|  |Check |  |Datum |  | URL  |           |
|  | box  |  |      |  |      |           |
|  +------+  +------+  +------+           |
|                                          |
+------------------------------------------+
```

**UX-Entscheidungen:**
- **Icon-Grid** statt Dropdown fuer bessere Erkennbarkeit
- Nach Auswahl wird das Feld sofort in die Liste eingefuegt und das Konfigurations-Panel geoeffnet
- Jeder Feldtyp hat eigene Konfigurationsoptionen (siehe 1.4)

---

### 1.4 Feld-Konfiguration (expandiertes Panel)

```
+--------------------------------------------------------------------+
| :: | E-Mail-Adresse *                              [^] [v] [x]    |
+--------------------------------------------------------------------+
|    +--------------------------------------------------------------+|
|    | Label:       [E-Mail-Adresse______________]                  ||
|    | Platzhalter: [name@beispiel.de____________]                  ||
|    | Pflichtfeld: [x]                                             ||
|    | Breite:      (o) Voll  ( ) Halb  ( ) Drittel                 ||
|    | CSS-Klasse:  [________________________________] (optional)   ||
|    +--------------------------------------------------------------+|
+--------------------------------------------------------------------+

Fuer Radio/Select/Checkbox zusaetzlich:
+--------------------------------------------------------------------+
|    | Optionen:                                                     |
|    |   [Option 1___________] [x]                                   |
|    |   [Option 2___________] [x]                                   |
|    |   [Option 3___________] [x]                                   |
|    |   [+ Option hinzufuegen]                                      |
+--------------------------------------------------------------------+

Fuer Datei-Upload zusaetzlich:
+--------------------------------------------------------------------+
|    | Erlaubte Typen: [x] PDF [x] JPG [x] PNG [ ] DOC [ ] Andere   |
|    | Max. Groesse:   [5___] MB                                     |
+--------------------------------------------------------------------+
```

**UX-Entscheidungen:**
- **Breite-Optionen**: Voll/Halb/Drittel ermoeglichen mehrspaltige Layouts
- **Optionen bei Radio/Select**: Inline editierbar, per Drag & Drop sortierbar
- **Datei-Upload**: Vordefinierte Typen als Checkboxen, nicht Freitext (verhindert Fehleingaben)

---

### 1.5 Statischer Textblock

```
+--------------------------------------------------------------------+
| :: | ---- Hinweistext ----                              [^] [v] [x]|
+--------------------------------------------------------------------+
|    +--------------------------------------------------------------+|
|    | Inhalt (WYSIWYG):                                            ||
|    | +----------------------------------------------------------+ ||
|    | | B  I  U  | Link | Liste |                                | ||
|    | +----------------------------------------------------------+ ||
|    | | Bitte beschreiben Sie Ihr Anliegen moeglichst             | ||
|    | | detailliert. Wir antworten innerhalb von 48h.             | ||
|    | +----------------------------------------------------------+ ||
|    +--------------------------------------------------------------+|
+--------------------------------------------------------------------+
```

**UX-Entscheidungen:**
- **Mini-WYSIWYG-Editor** (wp.editor) fuer Textbloecke — reicher als reines Textfeld, aber keine vollen Gutenberg-Bloecke
- Visuell durch gestrichelte Umrandung von Formularfeldern unterscheidbar

---

## 2. Empfaenger-Login-Bereich (Frontend)

### 2.1 Login-Flow (WordPress-Login mit Redirect)

Empfaenger nutzen den Standard-WordPress-Login (`wp-login.php`). Nach erfolgreichem
Login werden Benutzer mit der Rolle `dsgvoform_reader` automatisch in den
Einsendungs-Viewer weitergeleitet — sie sehen nie das WP-Dashboard.

```
Standard WP-Login (keine eigene Login-Seite noetig):
+------------------------------------------+
|                                          |
|      +----------------------------+      |
|      |   [WordPress-Logo]         |      |
|      |                            |      |
|      |   Benutzername oder E-Mail |      |
|      |   [_____________________]  |      |
|      |                            |      |
|      |   Passwort:                |      |
|      |   [_____________________]  |      |
|      |                            |      |
|      |   [ ] Angemeldet bleiben   |      |
|      |                            |      |
|      |   [      Anmelden       ]  |      |
|      |                            |      |
|      |   Passwort vergessen?      |      |
|      +----------------------------+      |
|                                          |
+------------------------------------------+

Redirect-Logik (login_redirect Filter):
  +------------------+     +---------------------+
  | WP-Login erfolgr.|---->| Rolle pruefen       |
  +------------------+     +---------------------+
                                  |           |
                           dsgvoform_reader  andere
                                  |           |
                                  v           v
                           /dsgvo-empfaenger/  /wp-admin/

Direktzugriff ohne Login:
  /dsgvo-empfaenger/
    --> wp-login.php?redirect_to=/dsgvo-empfaenger/
      --> Login --> Redirect zurueck
```

**UX-Entscheidungen:**
- **Standard-WP-Login** wird wiederverwendet — vertraute UI, Passwort-Reset eingebaut, keine eigene Auth-Logik
- **Automatischer Redirect**: `login_redirect`-Filter leitet `dsgvoform_reader` direkt zum Viewer
- **Kein WP-Dashboard-Zugang**: Admin-Bar wird per `show_admin_bar`-Filter fuer diese Rolle ausgeblendet
- **Direktlink** `/dsgvo-empfaenger/` funktioniert auch ohne Login — WP leitet zum Login und danach zurueck
- **"Passwort vergessen"** nutzt Standard-WP-Mechanismus (kein eigener Reset-Flow noetig)
- **Logout-Link** im Viewer leitet zu `wp-login.php?action=logout` mit Redirect zur Startseite

---

### 2.1a Datenschutzhinweis beim ersten Login (Empfaenger)

Beim ersten Login sieht ein Empfaenger einen einmaligen Datenschutzhinweis,
der bestaetigt werden muss (Zeitstempel wird gespeichert als Nachweis).

```
+------------------------------------------------------------------------+
|                                                                        |
|  +------------------------------------------------------------------+ |
|  |  Hinweis zum Umgang mit personenbezogenen Daten                   | |
|  |                                                                    | |
|  |  Sie haben Zugriff auf personenbezogene Daten, die Ihnen im       | |
|  |  Rahmen Ihrer Aufgabe anvertraut werden. Bitte behandeln Sie      | |
|  |  diese vertraulich und gemaess den Datenschutzrichtlinien         | |
|  |  Ihres Unternehmens.                                              | |
|  |                                                                    | |
|  |  Insbesondere:                                                     | |
|  |  - Geben Sie die Daten nicht an Unbefugte weiter                   | |
|  |  - Speichern Sie keine Daten auf privaten Geraeten                 | |
|  |  - Melden Sie Datenschutzvorfaelle unverzueglich                   | |
|  |                                                                    | |
|  |  [x] Ich habe den Hinweis gelesen und verstanden                   | |
|  |                                                                    | |
|  |  [           Bestaetigen und fortfahren            ]               | |
|  +------------------------------------------------------------------+ |
|                                                                        |
+------------------------------------------------------------------------+
```

**UX-Entscheidungen:**
- **Einmalig**: Wird nur beim allerersten Login angezeigt, danach nie wieder
- **Kein Blocker**: Checkbox + Button bestaetigen reicht — kein separater Vertrag
- **Zeitstempel**: `dsgvoform_privacy_acknowledged` als User-Meta mit Datum gespeichert (dokumentierter Nachweis)
- **Konfigurierbar**: Text ist in den Plugin-Einstellungen anpassbar

---

### 2.2 Einsendungs-Liste (nach Login)

```
+------------------------------------------------------------------------+
|  Hallo, Max Mustermann                                    [Abmelden]  |
+------------------------------------------------------------------------+
|                                                                        |
|  Formular: [Alle Formulare    v]   Status: [Alle v]                    |
|  Suche:    [________________________]  Zeitraum: [Letzte 30 Tage v]   |
|                                                                        |
|  Status-Filter: Alle | Neu | Gelesen | Gesperrt                       |
|                                                                        |
+------------------------------------------------------------------------+
|  *  | Datum        | Formular            | Absender       | Status    |
|  ---|--------------|---------------------|----------------|-----------|
|  o  | 17.04.2026   | Kontaktformular     | anna@mail.de   | Neu       |
|  o  | 16.04.2026   | Reparatur-Anfrage   | bob@mail.de    | Neu       |
|     | 15.04.2026   | Kontaktformular     | carl@mail.de   | Gelesen   |
|     | 12.04.2026   | Datenschutz-Auskunft| dana@mail.de   | Gelesen   |
|                                                                        |
|  Legende:  o = Ungelesen (fett)    * = Spalte sortierbar              |
|                                                                        |
|  Seite 1 von 3   [<] [1] [2] [3] [>]                                  |
+------------------------------------------------------------------------+
```

**UX-Entscheidungen:**
- **Ungelesene Einsendungen** werden fett dargestellt und haben einen farbigen Punkt
- **Filter** fuer Formular, Status und Zeitraum — kombinierbar
- **Suchfeld** durchsucht alle Felder (Absender, Betreff, etc.)
- **Paginierung** mit 20 Eintraegen pro Seite
- Sortierbar nach Datum (Standard: neueste zuerst), Formular, Status

---

### 2.3 Einsendungs-Detailansicht

```
+------------------------------------------------------------------------+
|  < Zurueck zur Liste                                                   |
+------------------------------------------------------------------------+
|                                                                        |
|  Einsendung #47                                                        |
|  Formular: Kontaktformular                                             |
|  Eingegangen: 17.04.2026, 14:32 Uhr                                   |
|  Status: Neu -> wird automatisch auf "Gelesen" gesetzt                 |
|                                                                        |
+------------------------------------------------------------------------+
|                                                                        |
|  E-Mail:        anna@beispiel.de                                       |
|  Betreff:       Anfrage zur Datenloeschung                             |
|  Nachricht:     Sehr geehrte Damen und Herren,                         |
|                 ich moechte gemaess Art. 17 DSGVO die Loeschung        |
|                 meiner personenbezogenen Daten beantragen...            |
|                                                                        |
|  Datei-Upload:  [Personalausweis.pdf]  (Download)                      |
|                                                                        |
|  DSGVO-Zustimmung: Ja, am 17.04.2026 14:32 Uhr                        |
|                                                                        |
+------------------------------------------------------------------------+
|                                                                        |
|  Notizen (intern):                                                     |
|  +----------------------------------------------------------------+   |
|  | 18.04. - Max: Loeschung durchgefuehrt, Bestaetigung gesendet   |   |
|  +----------------------------------------------------------------+   |
|  [Notiz hinzufuegen: ________________________________] [Speichern]    |
|                                                                        |
|  [  Als CSV exportieren  ]  [Sperren]  [  Einsendung loeschen  ]       |
+------------------------------------------------------------------------+
```

**UX-Entscheidungen:**
- **Automatische Lesebestaetigung**: Status wechselt beim Oeffnen auf "Gelesen"
- **Interne Notizen**: Empfaenger koennen Bearbeitungsnotizen hinterlassen
- **DSGVO-Zustimmung** wird immer angezeigt (Nachweis der Einwilligung)
- **Sperren** (Art. 18 DSGVO): Sperrt die Einsendung — gesperrte Daten koennen nur noch angezeigt, nicht mehr exportiert oder weitergeleitet werden. Button wechselt zu "Entsperren". Gesperrter Status wird in der Liste als "Gesperrt" angezeigt.
- **Loeschen**: Bestaetigung per Modal ("Daten werden unwiderruflich geloescht")
- **CSV-Export**: Einzelne Einsendung als CSV herunterladen (fuer Dokumentation). Bei gesperrten Einsendungen deaktiviert.
- **Entschluesselung**: Daten werden erst beim Anzeigen clientseitig entschluesselt — kein Klartext in der DB

---

## 3. Gutenberg Block

### 3.1 Block-Einfuegen

```
+------------------------------------------+
|  /dsgvo         (Block-Suche)            |
|  +--------------------------------------+|
|  |  [ICON]  DSGVO Formular              ||
|  |  Datenschutzkonformes Formular       ||
|  |  einfuegen                           ||
|  +--------------------------------------+|
+------------------------------------------+
```

### 3.2 Block im Editor — Formular-Auswahl

```
+------------------------------------------------------------------------+
|  +------------------------------------------------------------------+ |
|  |                                                                    | |
|  |   DSGVO Formular                                                   | |
|  |                                                                    | |
|  |   Formular waehlen:                                                | |
|  |   +--------------------------------------------+                  | |
|  |   |  Kontaktformular                        v  |                  | |
|  |   +--------------------------------------------+                  | |
|  |                                                                    | |
|  |   oder [Neues Formular erstellen ->]                               | |
|  |                                                                    | |
|  +------------------------------------------------------------------+ |
+------------------------------------------------------------------------+
```

### 3.3 Block im Editor — Vorschau (nach Formular-Auswahl)

```
+------------------------------------------------------------------------+
|  +------------------------------------------------------------------+ |
|  |  Kontaktformular                          [Formular aendern v]   | |
|  |  +---------------------------------------------------------+    | |
|  |  |                                                         |    | |
|  |  |  E-Mail-Adresse *                                       |    | |
|  |  |  [name@beispiel.de_______________________________]      |    | |
|  |  |                                                         |    | |
|  |  |  Betreff                                                |    | |
|  |  |  [_________________________________________________]    |    | |
|  |  |                                                         |    | |
|  |  |  Bitte beschreiben Sie Ihr Anliegen moeglichst          |    | |
|  |  |  detailliert. Wir antworten innerhalb von 48h.          |    | |
|  |  |                                                         |    | |
|  |  |  Nachricht *                                            |    | |
|  |  |  [_________________________________________________]    |    | |
|  |  |  [_________________________________________________]    |    | |
|  |  |  [_________________________________________________]    |    | |
|  |  |                                                         |    | |
|  |  |  [x] Ich stimme der Verarbeitung meiner Daten          |    | |
|  |  |      gemaess der Datenschutzerklaerung zu.              |    | |
|  |  |                                                         |    | |
|  |  |  [CAPTCHA-Platzhalter]                                  |    | |
|  |  |                                                         |    | |
|  |  |  [          Absenden           ]                        |    | |
|  |  |                                                         |    | |
|  |  +---------------------------------------------------------+    | |
|  +------------------------------------------------------------------+ |
+------------------------------------------------------------------------+
```

### 3.4 Block-Sidebar (Inspector Controls)

```
+----------------------------------+
|  DSGVO FORMULAR                  |
+----------------------------------+
|                                  |
|  Formular:                       |
|  [Kontaktformular          v]    |
|  [Im Admin bearbeiten ->]        |
|                                  |
+----------------------------------+
|  FARBEN                          |
|                                  |
|  Hintergrund:  [#FFFFFF] [O]     |
|  Text:         [#333333] [O]     |
|  Akzent:       [#0073AA] [O]     |
|  Button:       [#0073AA] [O]     |
|  Button-Text:  [#FFFFFF] [O]     |
|                                  |
+----------------------------------+
|  TYPOGRAFIE                      |
|                                  |
|  Schriftgroesse:  [16px    v]    |
|  Label-Gewicht:   [Fett   v]    |
|                                  |
+----------------------------------+
|  ABSTAENDE                       |
|                                  |
|  Padding:                        |
|       [20]                       |
|  [20] +--+ [20]                  |
|       [20]                       |
|                                  |
|  Feld-Abstand:   [16px    v]    |
|                                  |
+----------------------------------+
|  ERWEITERT                       |
|                                  |
|  Border-Radius:  [4px     v]    |
|  CSS-Klasse:     [__________]   |
|                                  |
+----------------------------------+
```

**UX-Entscheidungen:**
- **Live-Vorschau** im Editor — das Formular wird gerendert (nicht nur Platzhalter)
- **Styling nutzt WordPress-native Controls** (ColorPalette, FontSizePicker, BoxControl)
- **"Im Admin bearbeiten"** oeffnet die Formular-Konfiguration in neuem Tab
- **Farb-Auswahl** bietet Theme-Farben + Custom-Picker
- **Padding-Control** nutzt WordPress BoxControl mit verketteter/einzelner Eingabe
- **Keine Formular-Bearbeitung im Block** — der Block referenziert ein konfiguriertes Formular. Single Source of Truth.

---

## 4. Oeffentliches Formular (Frontend-Rendering)

### 4.1 Formular-Anzeige

```
+----------------------------------------------------+
|                                                    |
|  E-Mail-Adresse *                                  |
|  +----------------------------------------------+  |
|  | name@beispiel.de                              |  |
|  +----------------------------------------------+  |
|                                                    |
|  Betreff                                           |
|  +----------------------------------------------+  |
|  |                                                |  |
|  +----------------------------------------------+  |
|                                                    |
|  Bitte beschreiben Sie Ihr Anliegen moeglichst     |
|  detailliert. Wir antworten innerhalb von 48h.     |
|                                                    |
|  Nachricht *                                       |
|  +----------------------------------------------+  |
|  |                                                |  |
|  |                                                |  |
|  |                                                |  |
|  +----------------------------------------------+  |
|                                                    |
|  Datei hochladen                                   |
|  +----------------------------------------------+  |
|  | [Datei auswaehlen]  Keine Datei gewaehlt      |  |
|  | Erlaubt: PDF, JPG, PNG (max. 5 MB)            |  |
|  | Hochgeladene Dateien werden verschluesselt     |  |
|  | gespeichert und nach 90 Tagen geloescht.       |  |
|  +----------------------------------------------+  |
|                                                    |
|  +----------------------------------------------+  |
|  | Hinweis zur Datenverarbeitung:                |  |
|  | Ihre Angaben werden zum Zweck der Bearbeitung |  |
|  | Ihrer Anfrage verarbeitet und verschluesselt  |  |
|  | gespeichert. Die Daten werden nach 90 Tagen   |  |
|  | automatisch geloescht. Weitere Informationen  |  |
|  | finden Sie in unserer                         |  |
|  | [Datenschutzerklaerung].                      |  |
|  +----------------------------------------------+  |
|                                                    |
|  [ ] Ich willige in die Verarbeitung meiner        |
|      Angaben ein und habe den Datenschutzhinweis   |
|      gelesen. Ich kann diese Einwilligung          |
|      jederzeit widerrufen. *                       |
|                                                    |
|  +----------------------------------------------+  |
|  | CAPTCHA: Bitte bestaetige, dass du kein Bot   |  |
|  | bist.                                          |  |
|  |  [Aufgabe anzeigen]                            |  |
|  +----------------------------------------------+  |
|                                                    |
|  [            Absenden              ]              |
|                                                    |
+----------------------------------------------------+
```

### 4.2 Validierung & Feedback

```
Feldvalidierung (Inline, unter dem Feld):
+----------------------------------------------+
| name@                                         |
+----------------------------------------------+
  ! Bitte geben Sie eine gueltige E-Mail ein.

Erfolgs-Meldung (nach Absenden):
+----------------------------------------------------+
|                                                    |
|  +----------------------------------------------+  |
|  |  [Haekchen]                                   |  |
|  |  Vielen Dank fuer Ihre Einsendung!            |  |
|  |  Ihre Daten wurden verschluesselt             |  |
|  |  gespeichert und werden nach 90 Tagen         |  |
|  |  automatisch geloescht.                       |  |
|  |                                               |  |
|  |  Sie koennen Ihre Einwilligung jederzeit      |  |
|  |  widerrufen. Wenden Sie sich dazu an          |  |
|  |  [Kontakt-E-Mail]. Weitere Informationen      |  |
|  |  finden Sie in unserer                        |  |
|  |  [Datenschutzerklaerung].                     |  |
|  +----------------------------------------------+  |
|                                                    |
+----------------------------------------------------+

Fehler-Meldung (bei Serverfehler):
+----------------------------------------------------+
|                                                    |
|  +----------------------------------------------+  |
|  |  [Warnung]                                    |  |
|  |  Leider konnte Ihre Nachricht nicht           |  |
|  |  gesendet werden. Bitte versuchen Sie es      |  |
|  |  spaeter erneut.                              |  |
|  +----------------------------------------------+  |
|                                                    |
+----------------------------------------------------+
```

**UX-Entscheidungen:**
- **Inline-Validierung**: Fehler erscheinen unter dem jeweiligen Feld, nicht als Sammelmeldung oben
- **Validierung bei Blur + Submit**: Felder werden beim Verlassen validiert, nochmals beim Absenden
- **ARIA-Attribute** fuer Barrierefreiheit (`aria-invalid`, `aria-describedby`)
- **Submit-Button deaktiviert** waehrend der Uebertragung, zeigt Lade-Spinner
- **Erfolg ersetzt das Formular** (kein Reset — verhindert versehentliches Doppelsenden)
- **CAPTCHA** wird als letztes Element vor dem Button platziert

---

### 4.3 Rechtliche Texte — Konfiguration und Standardwerte

Alle rechtlichen Texte sind vom Admin anpassbar, haben aber rechtskonforme Standardwerte.

**Einwilligungstext (DSGVO-Checkbox):**

Standard-Template (Platzhalter werden aus Formular-Config befuellt):
```
Ich willige ein, dass meine Angaben zum Zweck der {zweck} verarbeitet und
verschluesselt gespeichert werden. Die Daten werden nach {loeschfrist} Tagen
automatisch geloescht. Ich kann diese Einwilligung jederzeit widerrufen.
Weitere Informationen: {datenschutz_link}
```

Konfigurierbare Variablen:
| Variable | Quelle | Standard |
|----------|--------|----------|
| `{zweck}` | Formular-Einstellung "Zweck" | "Bearbeitung meiner Anfrage" |
| `{loeschfrist}` | Plugin-Einstellung "Loeschfrist" | "90" |
| `{datenschutz_link}` | WP Datenschutzseite oder manuelle URL | WP Privacy Page |

Rechtliche Pflichtbestandteile (Warnung wenn fehlend):
- Zweck der Verarbeitung
- Speicherdauer
- Widerrufshinweis
- Link zur Datenschutzerklaerung

**Bestaetigungsnachricht:**

Standard-Template:
```
Vielen Dank fuer Ihre Einsendung. Ihre Daten wurden verschluesselt gespeichert
und werden nach {loeschfrist} Tagen automatisch geloescht.

Sie koennen Ihre Einwilligung jederzeit widerrufen. Wenden Sie sich dazu an
{kontakt_email}. Weitere Informationen finden Sie in unserer
{datenschutz_link}.
```

**Datei-Upload-Hinweis:**

Wird automatisch unter dem Upload-Feld generiert:
```
Hochgeladene Dateien werden verschluesselt auf dem Server gespeichert und
zusammen mit Ihrer Einsendung nach {loeschfrist} Tagen automatisch geloescht.
Zugelassene Dateiformate: {erlaubte_typen}. Maximale Dateigroesse: {max_mb} MB.
```

**Admin-UI fuer Textbearbeitung:**

Der "Volltext bearbeiten"-Link in der Formular-Sidebar oeffnet ein Modal:
```
+--------------------------------------------------+
|  Einwilligungstext bearbeiten                [x] |
+--------------------------------------------------+
|                                                  |
|  Verfuegbare Platzhalter:                        |
|  {zweck} {loeschfrist} {datenschutz_link}        |
|                                                  |
|  +----------------------------------------------+|
|  | Ich willige ein, dass meine Angaben zum      ||
|  | Zweck der {zweck} verarbeitet und             ||
|  | verschluesselt gespeichert werden. Die Daten  ||
|  | werden nach {loeschfrist} Tagen automatisch   ||
|  | geloescht. Ich kann diese Einwilligung        ||
|  | jederzeit widerrufen. Weitere Informationen:  ||
|  | {datenschutz_link}                            ||
|  +----------------------------------------------+|
|                                                  |
|  +----------------------------------------------+|
|  | [X] FEHLER: Im Text fehlt ein Verweis auf    ||
|  |     die Speicherdauer. Der Einwilligungstext  ||
|  |     muss Zweck, Speicherdauer, Widerrufs-     ||
|  |     recht und Datenschutz-Link enthalten       ||
|  |     (DSGVO Art. 7, Art. 13).                  ||
|  +----------------------------------------------+|
|  (Roter Fehlerhinweis — Speichern blockiert)     |
|                                                  |
|  [Auf Standard zuruecksetzen]    [Speichern]     |
|                                  (deaktiviert)   |
+--------------------------------------------------+
```

**Validierungslogik fuer Einwilligungstext:**

Die Pruefung erfolgt beim Speichern — als **Hard-Block (roter Fehler)**.
Speichern ist nicht moeglich, solange Pflichtbestandteile fehlen.

| Pflichtbestandteil | Pruefung auf Platzhalter/Keyword | Fehlermeldung wenn fehlend |
|-------------------|----------------------------------|----------------------------|
| Zweck | `{zweck}` oder "Zweck" | "Im Text fehlt ein Verweis auf den Verarbeitungszweck" |
| Speicherdauer | `{loeschfrist}` oder "Tage"/"geloescht" | "Im Text fehlt ein Verweis auf die Speicherdauer" |
| Widerrufsrecht | "widerrufen" oder "Widerruf" | "Im Text fehlt ein Hinweis auf das Widerrufsrecht" |
| Datenschutz-Link | `{datenschutz_link}` oder "Datenschutz" | "Im Text fehlt ein Link zur Datenschutzerklaerung" |

Jede fehlende Komponente erzeugt eine eigene Fehlerzeile im roten Hinweisblock.
Der Speichern-Button bleibt deaktiviert bis alle Pflichtbestandteile vorhanden sind.
Der "Auf Standard zuruecksetzen"-Button stellt den rechtlich vollstaendigen Default-Text sofort wieder her und aktiviert den Speichern-Button.

**Einwilligungstext-Versionierung (DPO-FINDING-04):**

Wenn der Admin den Einwilligungstext aendert, wird eine neue Version angelegt:
```
+--------------------------------------------------+
|  Einwilligungstext bearbeiten                [x] |
+--------------------------------------------------+
|  ...                                             |
|                                                  |
|  Aktuelle Version: v3 (geaendert 15.04.2026)    |
|  [Aeltere Versionen anzeigen v]                  |
|    v2 — 01.03.2026                               |
|    v1 — 10.01.2026 (Erstversion)                 |
|                                                  |
|  ! Bei Aenderung wird eine neue Version          |
|    erstellt. Bestehende Einsendungen behalten     |
|    den Verweis auf die Version, der zum           |
|    Zeitpunkt der Einwilligung gueltig war.        |
+--------------------------------------------------+
```

Jede Einsendung speichert die Version des Einwilligungstexts, die zum Zeitpunkt der Einwilligung galt. In der Einsendungs-Detailansicht wird angezeigt: "Einwilligungstext: Version 2 vom 01.03.2026 [anzeigen]".

**Einwilligungs-Checkbox im Frontend (Pflicht):**

Die DSGVO-Einwilligungs-Checkbox ist im Formular immer aktiv und kann vom Admin nicht deaktiviert werden.
Bei fehlendem Consent wird das Formular nicht abgesendet:
```
Fehlermeldung unter der Checkbox:
  [ ] Ich willige ein, dass meine Angaben...
  ! Sie muessen der Datenverarbeitung zustimmen, um das Formular absenden zu koennen.
```

Der Submit-Button ist klickbar, aber die Validierung verhindert das Absenden und setzt den Fokus auf die Checkbox.

---

## 5. Navigationsstruktur (Admin)

```
WordPress Admin Sidebar:
+----------------------------+
| ...                        |
| DSGVO Formulare            |
|   > Alle Formulare         |
|   > Neues Formular         |
|   > Empfaenger             |
|   > Einstellungen          |
| ...                        |
+----------------------------+
```

### 5.1 Empfaenger-Verwaltung (Admin)

Empfaenger sind WordPress-Benutzer mit der Rolle `dsgvoform_reader`.
Die Verwaltung erfolgt ueber eine eigene Admin-Seite (nicht ueber die
Standard-Benutzerverwaltung), die nur relevante Felder zeigt.

```
+------------------------------------------------------------------------+
|  Empfaenger                                         [+ Neuer Empfaenger]|
+------------------------------------------------------------------------+
|                                                                        |
|  [ ] | Name               | E-Mail            | Formulare   | Status  |
|  ----|--------------------+-------------------+-------------+---------|
|  [ ] | Max Mustermann     | max@example.de    | 2 Formulare | Aktiv   |
|  [ ] | Lisa Schmidt       | lisa@example.de   | 1 Formular  | Aktiv   |
|  [ ] | Tom Mueller        | tom@example.de    | 3 Formulare | Inaktiv |
|                                                                        |
|  Hover-Aktionen: Bearbeiten | Deaktivieren | Loeschen                  |
+------------------------------------------------------------------------+

Neuer Empfaenger (Modal):
+------------------------------------------+
|  Empfaenger hinzufuegen              [x] |
+------------------------------------------+
|                                          |
|  Name:     [_________________________]   |
|  E-Mail:   [_________________________]   |
|                                          |
|  Passwort:                               |
|  (o) Automatisch generieren & per        |
|      E-Mail senden                       |
|  ( ) Manuell festlegen:                  |
|      [_________________________]         |
|                                          |
|  Formulare zuweisen:                     |
|  [x] Kontaktformular                    |
|  [x] Reparatur-Anfrage                  |
|  [ ] Datenschutz-Auskunft               |
|                                          |
|  [Abbrechen]          [Hinzufuegen]      |
+------------------------------------------+

Hinweis: Erstellt einen WP-User mit Rolle "dsgvoform_reader".
Falls die E-Mail bereits einem WP-User gehoert, wird die Rolle
hinzugefuegt (kein Duplikat).
```

**UX-Entscheidungen:**
- Empfaenger sind **WordPress-Benutzer mit Custom Role `dsgvoform_reader`**
- **Eigene Admin-Seite** statt Standard-WP-Benutzerverwaltung — zeigt nur relevante Felder, kein WP-Rollen-Overhead sichtbar
- **Passwort**: Standard "Automatisch generieren" (WP sendet Welcome-E-Mail mit Passwort-Link), alternativ manuell
- **Bestehende WP-User**: Wenn die E-Mail bereits existiert, wird nur die Rolle `dsgvoform_reader` hinzugefuegt — Hinweis wird angezeigt: "Benutzer existiert bereits, Rolle wurde ergaenzt"
- **Formular-Zuweisung** per Checkbox — gespeichert als User-Meta
- **Deaktivieren**: Entfernt die Rolle temporaer (User bleibt bestehen), Reaktivieren fuegt sie wieder hinzu
- **Loeschen**: Entfernt nur die Rolle und die Formular-Zuweisungen, NICHT den WP-User selbst (Sicherheit). Hinweis: "Die Rolle wird entfernt. Der WordPress-Benutzer bleibt bestehen."

---

### 5.1a Empfaenger-Uebersicht fuer Supervisoren (DPO-SUP-03)

Admin-Seite mit Compliance-Uebersicht aller Empfaenger:

```
+------------------------------------------------------------------------+
|  Empfaenger-Compliance-Uebersicht                                      |
+------------------------------------------------------------------------+
|                                                                        |
|  Name            | Rolle           | Zugriff seit | Datenschutz  | Letzte|
|                  |                 |              | akzeptiert   | Pruef.|
|  ----------------|-----------------|--------------|--------------|-------|
|  Max Mustermann  | dsgvoform_reader| 10.01.2026   | 10.01.2026   | --    |
|  Lisa Schmidt    | dsgvoform_reader| 01.03.2026   | 01.03.2026   | --    |
|  Admin           | dsgvoform_admin | 01.01.2026   | —            | --    |
|                                                                        |
|  [Uebersicht als CSV exportieren]                                      |
+------------------------------------------------------------------------+
```

**UX-Entscheidungen:**
- Zeigt alle Benutzer mit DSGVO-Formular-Rollen und deren dokumentierten Zweck
- **Datenschutz akzeptiert**: Datum des Erstlogin-Datenschutzhinweises (User-Meta)
- **CSV-Export**: Fuer Compliance-Dokumentation / Audits
- Erreichbar ueber Empfaenger-Seite → Tab "Compliance" oder als Unterseite

---

### 5.2 Einstellungen

```
+------------------------------------------------------------------------+
|  DSGVO Formular - Einstellungen                                        |
+------------------------------------------------------------------------+
|                                                                        |
|  ALLGEMEIN                                                             |
|  --------                                                              |
|  Einsendungs-Viewer-Seite:  [/dsgvo-empfaenger___________] (Slug)     |
|  (Seite, auf die dsgvoform_reader nach WP-Login weitergeleitet wird)  |
|                                                                        |
|  CAPTCHA                                                               |
|  ------                                                                |
|  CAPTCHA-Server:  [https://captcha.repaircafe-bruchsal.de]            |
|  CAPTCHA-Modus:   (o) Immer  ( ) Nur bei Verdacht  ( ) Aus           |
|                                                                        |
|  VERSCHLUESSELUNG                                                      |
|  ----------------                                                      |
|  Verschluesselungs-Schluessel:  [****************************]         |
|  [Neuen Schluessel generieren]                                         |
|  ! Warnung: Bei Schlussel-Aenderung koennen alte Einsendungen          |
|    nicht mehr entschluesselt werden.                                   |
|                                                                        |
|  E-MAIL-BENACHRICHTIGUNGEN                                             |
|  --------------------------                                            |
|  [x] Empfaenger bei neuer Einsendung benachrichtigen                   |
|  Betreff-Vorlage: [Neue Einsendung: {formular_name}___]              |
|                                                                        |
|  DATENHALTUNG                                                          |
|  -----------                                                           |
|  Einsendungen automatisch loeschen nach: [90___] Tagen                |
|  (Minimum: 1 Tag, Maximum: 3650 Tage / 10 Jahre)                     |
|  ! Unbegrenzte Speicherung ist nicht DSGVO-konform.                    |
|                                                                        |
|  [Einstellungen speichern]                                             |
+------------------------------------------------------------------------+
```

**UX-Entscheidungen:**
- **Automatische Datenloeschung** als DSGVO-Feature: Einstellbar oder deaktivierbar
- **Verschluesselungsschluessel** wird bei Plugin-Aktivierung generiert, mit klarer Warnung bei Aenderung
- **CAPTCHA-URL** ist vorkonfiguriert, aber aenderbar

---

## 6. Responsive Design

### 6.1 Oeffentliches Formular (Mobile)

```
+------------------------+
| E-Mail-Adresse *       |
| [____________________] |
|                        |
| Betreff                |
| [____________________] |
|                        |
| Nachricht *            |
| [____________________] |
| [____________________] |
|                        |
| [x] Datenschutz...    |
|                        |
| [CAPTCHA]              |
|                        |
| [    Absenden    ]     |
+------------------------+
```

- Alle Felder werden auf volle Breite gesetzt (Halb/Drittel-Layout wird zu Full-Width)
- Touch-Targets mindestens 44x44px
- Button geht auf volle Breite

### 6.2 Empfaenger-Bereich (Mobile)

```
+------------------------+
| Hallo, Max  [Abmelden] |
+------------------------+
| Filter: [Alle Form. v] |
+------------------------+
| o 17.04. Kontaktform.  |
|   anna@mail.de    Neu  |
|------------------------|
| o 16.04. Rep.-Anfrage  |
|   bob@mail.de     Neu  |
|------------------------|
|   15.04. Kontaktform.  |
|   carl@mail.de Gelesen |
+------------------------+
```

- Tabelle wird zu Card-Layout auf Mobile
- Wichtigste Info (Datum, Formular, Status) sofort sichtbar

---

## 7. Barrierefreiheit (A11y)

- **WCAG 2.1 AA** als Mindeststandard
- Alle Formularfelder haben verknuepfte `<label>`-Elemente
- Fehlermeldungen per `aria-describedby` mit Feldern verknuepft
- Fokus-Management: Nach Submit springt der Fokus zur Erfolgs-/Fehlermeldung
- Drag & Drop hat Tastatur-Alternative (Up/Down-Buttons im Admin)
- Farbkontrast mindestens 4.5:1 fuer Text, 3:1 fuer UI-Elemente
- Skip-Links im Empfaenger-Bereich
- Statusaenderungen per `aria-live` Regionen kommuniziert

---

## 8. Zusammenfassung der UX-Prinzipien

1. **WordPress-nativ**: WP_List_Table, Admin-Styles, Gutenberg-Controls — keine Fremdkoerper
2. **DSGVO als Feature, nicht als Huerde**: Verschluesselung und Datenloeschung sind Standard, nicht optional
3. **Progressive Disclosure**: Einfache Grundansicht, Details bei Bedarf aufklappbar
4. **Single Source of Truth**: Formulare werden im Admin konfiguriert, der Block referenziert sie nur
5. **Accessible by Default**: ARIA, Tastatur-Bedienung, Kontrast von Anfang an mitgedacht
6. **Mobile First**: Oeffentliche Formulare muessen auf jedem Geraet funktionieren
7. **Mehrsprachig**: 6 Sprachen unterstuetzt, WP-Locale-basiert mit Formular-Override

---

## 9. Internationalisierung (i18n)

Unterstuetzte Sprachen: **de_DE, en_US, fr_FR, es_ES, it_IT, sv_SE**

### 9.1 Strategie: WP-Locale + Formular-Override

```
Sprach-Ermittlung (Prioritaet):
  1. Formular-Einstellung "Sprache" (falls gesetzt)
  2. WP-Locale der aktuellen Seite (WPML/Polylang/TranslatePress)
  3. WP-Standardsprache (Settings > General > Site Language)
```

**UX-Entscheidungen:**
- **Automatisch via WP-Locale** ist der Standard — kein Aufwand fuer den Admin
- **Formular-Override** nur fuer Spezialfaelle (z.B. ein franzoesisches Formular auf einer deutschen Seite)
- Kompatibel mit gaengigen Multilingual-Plugins (WPML, Polylang, TranslatePress)

### 9.2 Frontend-Formular: Sprachsteuerung

Alle vom Plugin generierten UI-Texte werden ueber WordPress `.po/.mo`-Dateien uebersetzt:

| Element | Uebersetzungsmethode |
|---------|---------------------|
| Feld-Labels, Platzhalter | Admin-Eingabe (nicht uebersetzt — Admin pflegt pro Sprache) |
| Validierungsmeldungen | `.po/.mo` Textdomain `wp-dsgvo-form` |
| Submit-Button-Label | Admin-Eingabe pro Formular |
| DSGVO-Einwilligungstext | Admin-Eingabe pro Sprache (siehe 9.4) |
| Bestaetigungsnachricht | Admin-Eingabe pro Sprache (siehe 9.4) |
| CAPTCHA-Texte | Externer CAPTCHA-Server (eigene Sprache) |
| Fehlermeldungen (Server) | `.po/.mo` Textdomain |

**Formular-Labels und statische Textbloecke** werden NICHT automatisch uebersetzt.
Der Admin erstellt entweder:
- **Ein Formular pro Sprache** (empfohlen bei WPML/Polylang — jede Sprachversion hat eigene Felder)
- **Ein Formular mit sprachneutralen Labels** (z.B. "E-Mail" funktioniert in allen Sprachen)

### 9.3 Admin-UI: Spracheinstellungen im Formular-Builder

Die Admin-UI folgt der WP-Admin-Sprache (keine eigene Sprachumschaltung noetig).
Im Formular-Builder wird ein neues Feld in der Sidebar ergaenzt:

```
|  EINSTELLUNGEN               |
|                              |
|  Sprache:                    |
|  (o) Automatisch (WP-Locale) |
|  ( ) Feste Sprache:          |
|      [Deutsch (de_DE)    v]  |
|                              |
```

**UX-Entscheidungen:**
- **"Automatisch"** ist der Standard — Formular passt sich der Seitensprache an
- **"Feste Sprache"** fuer Spezialfaelle — ueberschreibt WP-Locale fuer dieses Formular
- Kein Sprachwechsel-Feature im Admin-Builder — Admin-UI folgt `Settings > General > Site Language`
- Bei Multilingual-Plugin (WPML/Polylang): Admin erstellt Formular-Uebersetzungen ueber das jeweilige Plugin

### 9.4 Einwilligungstexte: Mehrsprachige Verwaltung

Der Einwilligungstext und die Bestaetigungsnachricht muessen pro Sprache gepflegt werden.

```
+--------------------------------------------------+
|  Einwilligungstext bearbeiten                [x] |
+--------------------------------------------------+
|                                                  |
|  Sprache: [Deutsch (de_DE)  v]                   |
|                                                  |
|  Datenschutzerklaerung-URL (diese Sprache):      |
|  [/datenschutz_________________]                 |
|                                                  |
|  +----------------------------------------------+|
|  | Ich willige ein, dass meine Angaben zum      ||
|  | Zweck der {zweck} verarbeitet und             ||
|  | verschluesselt gespeichert werden...           ||
|  +----------------------------------------------+|
|                                                  |
|  Uebersetzungs-Status:                           |
|  [OK] Deutsch (de_DE)                            |
|  [OK] English (en_US)                            |
|  [!!] Francais (fr_FR) — FORMULAR BLOCKIERT      |
|  [!!] Espanol (es_ES) — FORMULAR BLOCKIERT       |
|  [!!] Italiano (it_IT) — FORMULAR BLOCKIERT      |
|  [!!] Svenska (sv_SE) — FORMULAR BLOCKIERT        |
|                                                  |
|  !! ACHTUNG: Formular wird in Sprachen ohne       |
|     Einwilligungstext NICHT angezeigt             |
|     (Fail-Closed, DPO-Anforderung).              |
|                                                  |
|  [Auf Standard zuruecksetzen]    [Speichern]     |
+--------------------------------------------------+
```

**UX-Entscheidungen:**
- **Sprachwechsel per Dropdown** im Texteditor-Modal — zeigt den Text in der gewaehlten Sprache
- **Uebersetzungs-Status** zeigt auf einen Blick welche Sprachen fehlen
- **Fail-Closed (DPO-Anforderung CONSENT-I18N-01)**: Formular wird im Frontend NICHT angezeigt, wenn kein Einwilligungstext in der Formular-Sprache existiert. Kein Fallback auf andere Sprache. Stattdessen wird eine neutrale Fehlermeldung gerendert: "Dieses Formular ist derzeit nicht verfuegbar."
- **Datenschutzerklaerung-Link pro Sprache**: Der Datenschutz-URL im Formular-Builder kann pro Sprache konfiguriert werden (z.B. `/datenschutz` fuer DE, `/privacy-policy` fuer EN). Formular zeigt immer den Link passend zur Formular-Sprache.
- **Einwilligungs-Checkbox** zeigt immer den Text in der aktuellen Formular-Sprache (kein Sprachmix)
- **Versionierung gilt pro Sprache**: Aenderung in DE erstellt neue DE-Version, andere Sprachen bleiben
- Standard-Templates werden fuer alle 6 Sprachen mitgeliefert (im Plugin enthalten)
- Gleiche Logik fuer Bestaetigungsnachricht und Datei-Upload-Hinweis
- **Admin-Warnung**: Wenn fuer aktivierte Sprachen kein Einwilligungstext existiert, zeigt der Formular-Builder eine rote Warnung: "Formular wird in folgenden Sprachen nicht angezeigt: [Sprachen-Liste]"

### 9.5 Empfaenger-Bereich: Sprache

Der Einsendungs-Viewer folgt der WP-Profil-Sprache des eingeloggten Empfaengers:

| Element | Sprache |
|---------|---------|
| UI-Texte (Navigation, Filter, Buttons) | WP-Profil-Sprache des Empfaengers |
| Formular-Feldnamen in Einsendungen | Original-Sprache des Formulars (nicht uebersetzt) |
| Einsendungs-Inhalte | Original-Eingabe des Absenders |
| Datenschutzhinweis beim Erstlogin | WP-Profil-Sprache des Empfaengers |

### 9.6 Plugin-Strings: Textdomain

Alle hartkodierte Plugin-Strings nutzen die Textdomain `wp-dsgvo-form`:

```php
// Beispiel
__( 'Absenden', 'wp-dsgvo-form' );
_e( 'Pflichtfeld', 'wp-dsgvo-form' );
```

`.po/.mo`-Dateien liegen unter `languages/`:
```
languages/
  wp-dsgvo-form-de_DE.po
  wp-dsgvo-form-de_DE.mo
  wp-dsgvo-form-en_US.po
  wp-dsgvo-form-en_US.mo
  wp-dsgvo-form-fr_FR.po
  ...
```

Standard-Sprache des Plugins: **Deutsch (de_DE)** — englische Strings als Fallback in den PHP-Dateien.
