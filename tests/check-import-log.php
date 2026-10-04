<?php

declare(strict_types=1);

/**
 * Modultest gegen den Kernel-Stub: Importprobleme im Log (MCP-Regeln 3, 4, 16, 17).
 *
 * Probleme beim Einlesen (z. B. eine unbekannte Zeitzone) stehen einmal als Warnung im
 * Log, solange sie sich nicht ändern - nicht bei jedem Abruf. Sind sie verschwunden, steht
 * das einmal als Meldung im Log. Termintitel und andere fremde Texte erscheinen nur gekürzt.
 *
 * Aufruf: php tests/check-import-log.php
 */

require_once __DIR__ . '/harness.php';

date_default_timezone_set('Europe/Berlin');

/** Kalender mit einem Termin; mit $tzid eine Zeitzone, die es nicht gibt und die nicht definiert ist */
function kalenderMitZone(?string $tzid, string $titel = 'Termin'): string
{
    $start = date('Ymd\T100000', time() + 86400);
    $ende  = date('Ymd\T110000', time() + 86400);
    $zeit  = static fn (string $wert): string => $tzid === null ? ':' . $wert : ';TZID=' . $tzid . ':' . $wert;
    return implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//test//check-import-log//DE',
        'BEGIN:VEVENT',
        'UID:zone@test',
        'DTSTAMP:20261001T000000Z',
        'DTSTART' . $zeit($start),
        'DTEND' . $zeit($ende),
        'SUMMARY:' . $titel,
        'END:VEVENT',
        'END:VCALENDAR',
        '',
    ]);
}

function neueLogs(iCalCalendarReaderHarness $m): array
{
    $typ = [KL_ERROR => 'ERROR', KL_WARNING => 'WARNING', KL_MESSAGE => 'MESSAGE', KL_DEBUG => 'DEBUG'];
    $liste = array_map(
        static fn (array $e): string => ($typ[$e['Type']] ?? $e['Type']) . ': ' . $e['Message'],
        $m->logsSeitMarke()
    );
    $m->logsZuruecksetzen();
    foreach ($liste as $zeile) {
        echo '      | ' . $zeile . "\n";
    }
    return $liste;
}

function genauEine(array $logs, string $typ, array $enthaelt): bool
{
    if (count($logs) !== 1 || !str_starts_with($logs[0], $typ . ': ')) {
        return false;
    }
    foreach ($enthaelt as $teil) {
        if (!str_contains($logs[0], $teil)) {
            return false;
        }
    }
    return true;
}

$m  = neueInstanz();
$id = $m->instanzId();
$m->urlAntwort = antwortKalender(kalenderMitZone(null));
IPS_SetProperty($id, 'CalendarServerURL', 'https://kalender.example/test.ics');
IPS_ApplyChanges($id);
$m->UpdateCalendar();
$m->logsZuruecksetzen();

// --- 1. unbekannte Zeitzone
echo "Unbekannte Zeitzone\n";
$m->urlAntwort = antwortKalender(kalenderMitZone('Fantasie/Zone'));
$termine = json_decode((string) $m->UpdateCalendar(), true, 512, JSON_THROW_ON_ERROR);
$logs = neueLogs($m);
pruefe(count($termine) === 1, 'der Termin wird trotzdem eingelesen');
pruefe(
    genauEine($logs, 'WARNING', ['1 problem', 'Fantasie/Zone', 'check the calendar at its source']),
    'eine Warnung: Anzahl, erstes Problem, nächster Schritt'
);
pruefe(IPS_GetInstance($id)['InstanceStatus'] === IS_ACTIVE, 'Importproblem ist keine Störung des Abrufs (Status 102)');

$m->UpdateCalendar();
$m->UpdateCalendar();
pruefe(neueLogs($m) === [], 'dieselben Probleme bei jedem Abruf: keine weiteren Einträge');

// --- 2. andere Probleme
echo "\nAndere Zeitzone\n";
$m->urlAntwort = antwortKalender(kalenderMitZone('Andere/Fantasie'));
$m->UpdateCalendar();
pruefe(genauEine(neueLogs($m), 'WARNING', ['Andere/Fantasie']), 'geänderte Probleme: neue Warnung');

// --- 3. langer fremder Text wird gekürzt
echo "\nLanger Zeitzonenname\n";
$langerName = 'Zone/' . str_repeat('x', 300);
$m->urlAntwort = antwortKalender(kalenderMitZone($langerName));
$m->UpdateCalendar();
$logs = neueLogs($m);
pruefe(count($logs) === 1 && !str_contains($logs[0], str_repeat('x', 150)), 'fremder Text im Log gekürzt');

// --- 4. Probleme behoben
echo "\nKalender wieder sauber\n";
$m->urlAntwort = antwortKalender(kalenderMitZone(null));
$m->UpdateCalendar();
pruefe(genauEine(neueLogs($m), 'MESSAGE', ['without problems']), 'Behebung einmal gemeldet');
$m->UpdateCalendar();
pruefe(neueLogs($m) === [], 'sauberer Kalender: Log bleibt ruhig');

// --- 5. Selbsttest meldet Importprobleme, schreibt aber nichts ins Log
echo "\nSelbsttest\n";
$m->urlAntwort = antwortKalender(kalenderMitZone('Fantasie/Zone'));
$text = $m->RunSelfTest();
pruefe(str_contains($text, '⚠ 1 import problem(s)'), 'Selbsttest nennt das Importproblem');
pruefe(neueLogs($m) === [], 'Selbsttest schreibt nichts ins Log');

ergebnis();
