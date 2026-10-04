<?php

declare(strict_types=1);

/**
 * Regressionstest für den Kalender-Import (iCalImporter + gebündelte Libs).
 *
 * Importiert alle Testkalender aus tests/fixtures/kalender (neutralisierte
 * Anwender-Mitschnitte, erzeugt mit tests/werkzeuge/neutralisiere_kalender.php)
 * mit einem festen Referenzdatum und vergleicht Event-Anzahl, Fehlerzahl und
 * Prüfsumme der kanonisch sortierten Ergebnisse gegen die Golden-Datei.
 *
 * Aufruf:  php tests/import_regression.php            Prüfen (Exit-Code 1 bei Abweichung)
 *          php tests/import_regression.php --update   Golden-Datei neu erzeugen
 */

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Europe/Berlin');

require_once __DIR__ . '/lib/kalender_import.php';

$fixtureDir = __DIR__ . '/fixtures/kalender';
$goldenFile = __DIR__ . '/import_regression.golden.json';
$update     = in_array('--update', $argv, true);

$files = glob($fixtureDir . '/*.ics.gz') ?: [];
sort($files);
if ($files === []) {
    echo "FEHLER: keine Testkalender in tests/fixtures/kalender gefunden.\n";
    echo "0 Prüfungen, 1 Fehler\n";
    exit(1);
}

$results = [];
foreach ($files as $file) {
    $data = gzdecode((string)file_get_contents($file));
    if ($data === false) {
        $results[basename($file, '.ics.gz')] = ['count' => null, 'errors' => 1, 'sha256' => null];
        continue;
    }
    ['events' => $events, 'errors' => $errors] = kalenderImportieren($data);

    $results[basename($file, '.ics.gz')] = [
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
    echo "FEHLER: Golden-Datei fehlt - mit --update erzeugen.\n";
    echo "0 Prüfungen, 1 Fehler\n";
    exit(1);
}

$golden   = json_decode((string)file_get_contents($goldenFile), true, 512, JSON_THROW_ON_ERROR);
$checks   = 0;
$failures = 0;
foreach ($results as $name => $result) {
    $checks++;
    $expected = $golden[$name] ?? null;
    if ($expected === null) {
        echo "NEU (nicht in Golden-Datei): $name\n";
        $failures++;
        continue;
    }
    if ($expected !== $result) {
        echo "ABWEICHUNG: $name\n";
        echo '  erwartet: ' . json_encode($expected) . "\n";
        echo '  erhalten: ' . json_encode($result) . "\n";
        $failures++;
        continue;
    }
    echo "OK $name ({$result['count']} Termine)\n";
}
foreach (array_keys($golden) as $name) {
    if (!isset($results[$name])) {
        $checks++;
        echo "FEHLT (in Golden-Datei, aber kein Fixture): $name\n";
        $failures++;
    }
}

if ($failures > 0) {
    echo "\nFalls die Abweichung beabsichtigt ist: php tests/import_regression.php --update\n";
}
echo "$checks Prüfungen, $failures Fehler\n";
exit($failures === 0 ? 0 : 1);
