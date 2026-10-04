# CLAUDE.md — iCal-Calendar

Symcon-Modulbibliothek: liest Kalender im iCal-Format (URL oder Medienobjekt) und
stellt Termine/Benachrichtigungen bereit. Ein Modul: `iCalCalendarReader` (Prefix `ICCR`,
GUID `{5127CDDC-2859-4223-A870-4D26AC83622C}`).

## Aufbau

- `iCalCalendarReader/module.php` — Symcon-Modulklasse (`IPSModuleStrict`), Konfigurationsformular
  wird dynamisch erzeugt (keine form.json). HTTP-Abruf unterstützt Basic- und Digest-Auth.
- `iCalCalendarReader/iCalImporter.php` — eigenständige Importklasse (auch ohne Symcon nutzbar,
  Konstruktor nimmt Logger-Callables und optional ein Referenzdatum für das Cache-Fenster).
- `libs/iCalcreator-master` — iCalcreator (Parser), `libs/php-rrule-master` — RRULE-Auswertung.
  Versionen und Bezugsquellen stehen im readme; **Änderungshinweise unten beachten!**

## Gebündelte Libs: lokale Patches

Die iCalcreator-Kopie enthält vier mit `bumaas` markierte Patches — vollständige
Dokumentation im Kommentarkopf von `iCalCalendarReader/module.php`:

1. `Util/CalAddressFactory.php` `assertCalAddress()`: sofortiges `return`
   (Kalender mit ungültigen ORGANIZER-/ATTENDEE-Adressen tolerieren, z. B. iCloud).
2. `Util/HttpFactory.php` `assertUrl()`: sofortiges `return` (ungültige URL-Properties tolerieren).
3. `Util/DateTimeZoneFactory.php`: `class_exists('IntlTimeZone')`-Guard —
   **das Symcon-PHP hat kein ext-intl**; ohne Guard endet der Windows-Zeitzonen-Fallback fatal.
4. `Util/DateTimeFactory.php` `isStringAndDate()`: 32-Bit-Sonderfall (strtotime scheitert
   dort außerhalb 1901–2038).

Bei einem Lib-Update müssen diese Patches neu angewendet werden. php-rrule ist ungepatcht.

Die bis iCalcreator 2.40.x genutzte `RegulateTimezoneFactory` (Umschreiben von Windows-/
Exchange-Zeitzonen vor dem Parsen) ist seit 2.41.57 aus der Lib entfallen; ihre Aufgabe
übernimmt `iCalImporter::regulateTimezones()`:
- MS-Namen ("W. Europe Standard Time") über die portierte Offset-Tabelle `MS_TIMEZONE_TO_OFFSET`
- Exchange-Displaynamen ("(UTC+01:00) Amsterdam, ...") über den Offset im Namen
- reine Offsets ("+02", "GMT+0200") auf DST-freie `Etc/GMT∓H`-Zonen (Vorzeichen invertiert!)
- TZID-Bereinigung um `"` und `\`
- unbekannte Namen: Offset aus der VTIMEZONE-Definition, letzter Fallback lokale Zeitzone

Wichtig: iCalcreator 2.41.x wirft beim Parsen für unbekannte TZIDs eine Exception (der ganze
Kalender ginge verloren) — deshalb muss regulateTimezones alle TZIDs auflösen.

## Tests

- **Modultests gegen den Kernel-Stub** (`symcon/SymconStubs`, Submodul `tests/stubs`, gepinnt
  auf `bf2950f`), gemeinsamer Aufbau in `tests/harness.php`:
  - Netz-Naht: `LoadCalendarURL()` ist überschrieben und liefert `$urlAntwort` ([Status,
    Inhalt]); ohne gesetzte Antwort wirft die Harness, statt echt ins Netz zu gehen.
  - Util Control (für `UC_FindReferences` beim Löschen von Notifier-Variablen) stellt die
    Harness als `UtilControlAttrappe` samt globaler Funktion; Referenzen setzt der Test.
  - Medienobjekte kann der Stub nicht (`IPS_GetMedia` liefert `[]`) — der Medien-Weg ist
    deshalb nicht abgedeckt.
  - `php tests/check-notifier.php` — Status, Timer, Kalenderabruf, Notifier (Text, Regex,
    ungültiger Ausdruck, Vorlauf), Entfernen samt Referenzschutz, Störung und Erholung,
    deaktivierte Instanz. Termine liegen relativ zu jetzt.
  - `php tests/check-variable-registration.php` — Idents, Typen, Positionen der Notifier-Variablen.
- `php tests/check_presentations.php` — Darstellungen gegen bekannte Presentation-GUIDs.
- `php tests/check_locale.php` — Übersetzungs-Vollständigkeit (Translate-Texte vs. locale.json).
- `php tests/check-readme.php` — Doku-Sperrklinke (README de/en gegen Modul, bekannte Lücken
  in `tests/readme-bekannt.json`).
- `php tests/check-import-regression.php` — Import-Regressionstest über 18 Testkalender in
  `tests/fixtures/kalender/*.ics.gz` (committet). Festes Referenzdatum, Vergleich von Anzahl,
  Fehlerzahl und Prüfsumme gegen `tests/check-import-regression.golden.json`; ohne Fixtures rot.
  Nach beabsichtigten Verhaltensänderungen: `php tests/check-import-regression.php --update`.
- **Fixtures sind neutralisierte Anwender-Mitschnitte.** Die Originale liegen privat in
  `docs/Examples/Testdaten` (per `.gitignore` ausgeschlossen, nie committen).
  `php tests/werkzeuge/neutralisiere_kalender.php` ersetzt alle Freitexte und Adressen
  formgetreu (Buchstabe bleibt Buchstabe, Ziffer bleibt Ziffer, gleiche Texte gleich), lässt
  Zeiten, Regeln und Zeitzonen stehen und belegt danach je Kalender, dass der Import vorher und
  nachher dieselben Termine liefert (Exit 1 sonst). Neuer Mitschnitt: Datei dort ablegen,
  neutralen Namen in `ZUORDNUNG` eintragen, Werkzeug laufen lassen, Golden mit `--update`.
  Vor dem Commit die entpackten Fixtures auf Namen, Adressen und Orte durchsehen.
- `tests/lib/kalender_import.php` — gemeinsamer Import-Aufruf für Test und Werkzeug.
- CI: `.github/workflows/check.yml` (PHP 8.4, Checkout mit Submodulen): Code-Stil mit
  php-cs-fixer gegen das Regelwerk im Submodul `.style` (`--dry-run`; die gebündelten Libs
  `libs/iCalcreator-master` und `libs/php-rrule-master` sind per `.style-exclude`
  ausgenommen), php -l, JSON-Validität, MCP-Prüfung, danach **alle `tests/*.php`** per Glob
  (ein neuer Test läuft ohne Workflow-Änderung mit).

## Konventionen

- Version/Build und die Rolle von `T:\modules` als Produktivverzeichnis: siehe globale
  `CLAUDE.md`, Abschnitte „Symcon: Build-/Versionspflege in Modul-Repos" und „Symcon:
  Referenz-Checkliste für Modul-Repos".
