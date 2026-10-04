<?php

declare(strict_types=1);

/**
 * Modultest gegen den Kernel-Stub: Störungen und ihre Behebung im Log (MCP-Regeln 3, 4, 16).
 *
 * Jeder Wechsel in einen Fehlerstatus steht genau einmal als Warnung im Log - mit Art
 * des Fehlers und nächstem Schritt, ohne Geheimnisse aus der URL. Wiederholt sich die
 * Störung bei jedem Abruf, bleibt das Log ruhig. Die Behebung wird einmal gemeldet.
 * Der Selbsttest schreibt nichts ins Log.
 *
 * Aufruf: php tests/check-status-log.php
 */

require_once __DIR__ . '/harness.php';

date_default_timezone_set('Europe/Berlin');

const URL_MIT_TOKEN = 'https://kalender.example/published/2/geheim-token-4711/calendar.ics';

/** Log seit der Marke als kurze Liste "TYP: Text" */
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

// --- 1. neue Instanz ohne Kalenderquelle
echo "Neue Instanz ohne Kalenderquelle\n";
$m  = neueInstanz();
$id = $m->instanzId();
$logs = $m->logsSeitMarke();
$m->logsZuruecksetzen();
$logs = array_map(static fn (array $e): string => ($e['Type'] === KL_WARNING ? 'WARNING' : (string) $e['Type']) . ': ' . $e['Message'], $logs);
foreach ($logs as $zeile) {
    echo '      | ' . $zeile . "\n";
}
pruefe(genauEine($logs, 'WARNING', ['No calendar URL', 'media object']), 'Status 201 als Warnung im Log, nennt URL und Medienobjekt');

IPS_ApplyChanges($id);
pruefe(neueLogs($m) === [], 'erneutes Übernehmen ohne Änderung: keine zweite Warnung');

// --- 2. ungültige URL
echo "\nUngültige URL\n";
IPS_SetProperty($id, 'CalendarServerURL', 'kalender ohne schema');
IPS_ApplyChanges($id);
$logs = neueLogs($m);
pruefe(IPS_GetInstance($id)['InstanceStatus'] === 201, 'Status 201');
pruefe(genauEine($logs, 'WARNING', ['not a valid URL', 'correct the URL']), 'ungültige URL: Art und nächster Schritt');

// --- 3. Kalender lesbar: Behebung wird gemeldet
echo "\nKalender lesbar\n";
$m->urlAntwort = antwortKalender(kalender());
IPS_SetProperty($id, 'CalendarServerURL', URL_MIT_TOKEN);
IPS_ApplyChanges($id);
$logs = neueLogs($m);
pruefe(IPS_GetInstance($id)['InstanceStatus'] === IS_ACTIVE, 'Status 102');
pruefe(genauEine($logs, 'MESSAGE', ['can be read again']), 'Behebung einmal als Meldung');
$m->UpdateCalendar();
pruefe(neueLogs($m) === [], 'regulärer Abruf ohne Störung: Log bleibt ruhig');

// --- 4. Server nicht erreichbar, mehrfach
echo "\nServer nicht erreichbar\n";
$m->urlAntwort = antwortCurlFehler(6, 'Could not resolve host: kalender.example');
$m->UpdateCalendar();
$logs = neueLogs($m);
pruefe(IPS_GetInstance($id)['InstanceStatus'] === 204, 'Status 204');
pruefe(
    genauEine($logs, 'WARNING', ['https://kalender.example/', 'not reachable', 'Could not resolve host', 'retried every 15 minutes']),
    'Verbindungsfehler: Server, Ursache und „wird erneut versucht"'
);
pruefe(!str_contains(implode("\n", $logs), 'geheim-token'), 'Pfad der URL (Token) steht nicht im Log');
$m->UpdateCalendar();
$m->UpdateCalendar();
pruefe(neueLogs($m) === [], 'dieselbe Störung bei jedem Abruf: keine weiteren Einträge');

// --- 5. andere Störung: Zugang abgelehnt
echo "\nZugang abgelehnt\n";
$m->urlAntwort = antwortInhalt(SABRE_NICHT_ANGEMELDET);
$m->UpdateCalendar();
$logs = neueLogs($m);
pruefe(
    genauEine($logs, 'WARNING', ['rejected user name or password', 'NotAuthenticated', 'correct user name and password', 'paused until the changes are applied']),
    'Wechsel zu einer anderen Störung: neue Warnung mit nächstem Schritt'
);

// --- 6. Selbsttest bei Störung schreibt nichts ins Log
echo "\nSelbsttest bei Störung\n";
$m->RunSelfTest();
pruefe(neueLogs($m) === [], 'Selbsttest schreibt nichts ins Log');

// --- 7. wieder erreichbar
echo "\nWieder erreichbar\n";
// Bei abgelehnter Anmeldung liest der Abruf-Timer nicht weiter (kein Dauerversuch mit falschen
// Zugangsdaten) - erst das Übernehmen der korrigierten Einstellungen liest wieder.
$m->urlAntwort = antwortKalender(kalender());
$abrufe = $m->urlAbrufe;
pruefe($m->UpdateCalendar() === null && $m->urlAbrufe === $abrufe, 'Status 203: Abruf-Timer liest nicht weiter');
IPS_ApplyChanges($id);
$logs = neueLogs($m);
pruefe(genauEine($logs, 'MESSAGE', ['can be read again', 'problem was: invalid user or password']), 'nach dem Übernehmen: Behebung einmal gemeldet, mit der behobenen Störung');

// --- 8. Abschalten ist keine Störung
echo "\nAbschalten\n";
IPS_SetProperty($id, 'active', false);
IPS_ApplyChanges($id);
pruefe(neueLogs($m) === [], 'abgeschaltete Instanz: kein Eintrag');
IPS_SetProperty($id, 'active', true);
IPS_ApplyChanges($id);
pruefe(neueLogs($m) === [], 'wieder eingeschaltet ohne vorherige Störung: kein Eintrag');

ergebnis();
