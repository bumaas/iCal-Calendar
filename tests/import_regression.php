<?php

declare(strict_types=1);

/**
 * Regressionstest für den Kalender-Import (iCalImporter + gebündelte Libs).
 *
 * Importiert alle Testkalender aus docs/Examples/Testdaten (nicht committet,
 * enthält private Daten — in der CI wird der Test daher übersprungen) mit einem
 * festen Referenzdatum und vergleicht Event-Anzahl und Prüfsumme der kanonisch
 * sortierten Ergebnisse gegen die committete Golden-Datei.
 *
 * Aufruf:  php tests/import_regression.php            Prüfen (Exit-Code 1 bei Abweichung)
 *          php tests/import_regression.php --update   Golden-Datei neu erzeugen
 */

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Europe/Berlin');

const REFERENCE_DATE     = '2026-08-01'; // fix, damit die Ergebnisse deterministisch sind
const DAYS_TO_CACHE_BACK = 3000;
const DAYS_TO_CACHE_AHEAD = 3000;

$root       = dirname(__DIR__);
$testDir    = $root . '/docs/Examples/Testdaten';
$goldenFile = __DIR__ . '/import_regression.golden.json';
$update     = in_array('--update', $argv, true);

if (!is_dir($testDir)) {
    echo "Testdaten-Verzeichnis nicht vorhanden (docs/Examples/Testdaten ist nicht committet) - Test übersprungen.\n";
    exit(0);
}

require_once $root . '/libs/iCalcreator-master/autoload.php';
$rruleDir = $root . '/libs/php-rrule-master/src/';
require_once $rruleDir . 'RRuleInterface.php';
require_once $rruleDir . 'RRuleTrait.php';
require_once $rruleDir . 'RfcParser.php';
require_once $rruleDir . 'RRule.php';
require_once $rruleDir . 'RSet.php';
require_once $root . '/iCalCalendarReader/iCalImporter.php';

$files = glob($testDir . '/*.txt');
sort($files);
if ($files === []) {
    echo "keine Testdateien gefunden - Test übersprungen.\n";
    exit(0);
}

$results = [];
foreach ($files as $file) {
    $name = basename($file);
    $raw  = file_get_contents($file);

    // Normalisierung: einige Testdateien enthalten literale <CR><LF>-Marker bzw.
    // sind als PHP-Snippet ($curl_result = '...';) gespeichert
    $data = str_replace(['<CR><LF>', '<CR>', '<LF>'], ["\r\n", "\r\n", "\r\n"], $raw);
    if (preg_match("/curl_result\s*=\s*'(.*)';/s", $data, $m)) {
        $data = $m[1];
    }
    if (strpos($data, 'BEGIN:VCALENDAR') === false) {
        $data = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//test//EN\r\n" . $data . "\r\nEND:VCALENDAR\r\n";
    }

    $errors = [];
    $events = null;
    try {
        $importer = new iCalImporter(
            DAYS_TO_CACHE_BACK,
            DAYS_TO_CACHE_AHEAD,
            static function (string $method, string $message): void {
            },
            static function (string $message) use (&$errors): void {
                $errors[] = $message;
            },
            new DateTimeImmutable(REFERENCE_DATE)
        );
        $events = $importer->ImportCalendar($data);
    } catch (Throwable $t) {
        $errors[] = 'EXCEPTION ' . get_class($t) . ': ' . $t->getMessage();
    }

    // kanonisch sortieren (bei gleicher Startzeit ist die usort-Reihenfolge instabil)
    if (is_array($events)) {
        usort($events, static function (array $a, array $b): int {
            return [$a['From'], $a['To'], $a['UID'], $a['Name']] <=> [$b['From'], $b['To'], $b['UID'], $b['Name']];
        });
    }

    $results[$name] = [
        'count'  => is_array($events) ? count($events) : null,
        'errors' => count($errors),
        'sha256' => is_array($events)
            ? hash('sha256', json_encode($events, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE))
            : null,
    ];
}

if ($update) {
    file_put_contents(
        $goldenFile,
        json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
    echo 'Golden-Datei aktualisiert: ' . count($results) . " Kalender.\n";
    exit(0);
}

if (!is_file($goldenFile)) {
    fwrite(STDERR, "Golden-Datei fehlt - mit --update erzeugen.\n");
    exit(1);
}

$golden = json_decode(file_get_contents($goldenFile), true, 512, JSON_THROW_ON_ERROR);
$fail   = false;
foreach ($results as $name => $result) {
    $expected = $golden[$name] ?? null;
    if ($expected === null) {
        echo "NEU (nicht in Golden-Datei): $name\n";
        $fail = true;
        continue;
    }
    if ($expected !== $result) {
        echo "ABWEICHUNG: $name\n";
        echo '  erwartet: ' . json_encode($expected) . "\n";
        echo '  erhalten: ' . json_encode($result) . "\n";
        $fail = true;
    }
}
foreach (array_keys($golden) as $name) {
    if (!isset($results[$name])) {
        echo "FEHLT (in Golden-Datei, aber nicht mehr vorhanden): $name\n";
        $fail = true;
    }
}

if ($fail) {
    fwrite(STDERR, "\nFEHLER: Importergebnisse weichen von der Golden-Datei ab.\n");
    fwrite(STDERR, "Falls die Abweichung beabsichtigt ist: php tests/import_regression.php --update\n");
    exit(1);
}
echo 'OK: ' . count($results) . " Kalender unverändert.\n";
