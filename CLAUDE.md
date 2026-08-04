# CLAUDE.md — iCal-Calendar

IP-Symcon-Modulbibliothek: liest Kalender im iCal-Format (URL oder Medienobjekt) und
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

- `php tests/check_locale.php` — Übersetzungs-Vollständigkeit (Translate-Texte vs. locale.json).
- `php tests/import_regression.php` — Import-Regressionstest über die Testkalender in
  `docs/Examples/Testdaten` (nicht committet, private Daten → in der CI übersprungen).
  Festes Referenzdatum, Vergleich gegen `tests/import_regression.golden.json` (committet).
  Nach beabsichtigten Verhaltensänderungen: `php tests/import_regression.php --update`.
- CI: `.github/workflows/check.yml` (php -l, JSON-Validität, Locale-Check, Regressionstest).

## Konventionen

- Version/Build/Datum in `library.json` pflegen; Commit-Subject: `<version> build <NN>: <Beschreibung>`.
- `T:\modules` ist das produktive Symcon-modules-Verzeichnis — Änderungen wirken sofort auf
  der Produktiv-Installation.
