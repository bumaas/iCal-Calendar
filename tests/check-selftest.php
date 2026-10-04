<?php

declare(strict_types=1);

/**
 * Modultest gegen den Kernel-Stub: ICCR_RunSelfTest.
 *
 * Der Selbsttest ist für Skripte und KI-Assistenten gedacht. Geprüft wird, dass er
 * jede typische Störung mit Art und nächstem Schritt benennt, mit der Zeile
 * "N errors, M warnings" endet und nichts verändert (Status, Variablen, Cache, Timer).
 *
 * Aufruf: php tests/check-selftest.php
 */

require_once __DIR__ . '/harness.php';

date_default_timezone_set('Europe/Berlin');

/** Zustand, den der Selbsttest nicht verändern darf */
function zustand(iCalCalendarReaderHarness $m): array
{
    $id = $m->instanzId();
    return [
        'status' => IPS_GetInstance($id)['InstanceStatus'],
        'werte'  => $m->werte(),
        'cache'  => $m->GetCachedCalendar(),
        'timer'  => $m->timer,
        'writes' => count($m->writes),
        'stati'  => count($m->status),
    ];
}

function schlusszeile(string $text): string
{
    $zeilen = explode("\n", $text);
    return end($zeilen);
}

// --- 1. ohne Kalenderquelle
echo "Ohne Kalenderquelle\n";
$m = neueInstanz();
$text = $m->RunSelfTest();
pruefe(str_contains($text, '✗ No valid calendar URL configured'), 'fehlende URL als Fehler');
pruefe(str_contains($text, '→ Enter the iCal URL'), 'nennt den nächsten Schritt');
pruefe(schlusszeile($text) === '1 errors, 0 warnings', 'Schlusszeile 1 errors, 0 warnings');
pruefe($m->urlAbrufe === 0, 'ohne URL kein Abruf');

// --- 2. funktionierender Kalender mit Notifiern
echo "\nKalender lesbar, drei Notifier\n";
$m->urlAntwort = [IS_ACTIVE, kalender()];
$id = $m->instanzId();
IPS_SetProperty($id, 'CalendarServerURL', 'https://kalender.example/test.ics');
IPS_SetProperty($id, 'Notifiers', json_encode([
    notifier('NOTIFIER1', 'Restmüll'),
    notifier('NOTIFIER2', 'Zahnarzt'),
    notifier('NOTIFIER3', '([', true),
], JSON_THROW_ON_ERROR));
IPS_ApplyChanges($id);
$m->UpdateCalendar();
$m->TriggerNotifications();

$vorher = zustand($m);
$abrufe = $m->urlAbrufe;
$text   = $m->RunSelfTest();
echo preg_replace('/^/m', '      | ', $text) . "\n";
pruefe(zustand($m) === $vorher, 'Selbsttest verändert nichts (Status, Variablen, Cache, Timer)');
pruefe($m->urlAbrufe === $abrufe + 1, 'Selbsttest liest den Kalender genau einmal');
pruefe(str_contains($text, '✓ Import: 2 dates'), 'Import mit Terminzahl');
pruefe(str_contains($text, '✓ Cache: 2 dates, read every 15 minutes'), 'Cache und Abrufintervall');
pruefe(str_contains($text, 'NOTIFIER1 (text "Restmüll"): 1 matching date(s) in the cache window, active now: yes, variable: true'), 'NOTIFIER1 mit Treffer, aktiv');
pruefe(str_contains($text, '⚠ NOTIFIER2 (text "Zahnarzt"): 0 matching'), 'NOTIFIER2 ohne Treffer als Warnung');
pruefe(str_contains($text, '✗ NOTIFIER3 (pattern "(["): invalid regular expression'), 'ungültiger Ausdruck als Fehler');
pruefe(schlusszeile($text) === '1 errors, 1 warnings', 'Schlusszeile 1 errors, 1 warnings');

// Intervall 0 heißt: nie automatisch lesen (am nuc bei fünf Instanzen, Cache dort veraltet)
IPS_SetProperty($id, 'UpdateFrequency', 0);
IPS_ApplyChanges($id);
$text = $m->RunSelfTest();
pruefe(
    str_contains($text, 'Cache: 2 dates, not read automatically (update interval 0)'),
    'Intervall 0: „not read automatically" statt „read every 0 minutes"'
);
IPS_SetProperty($id, 'UpdateFrequency', 15);
IPS_ApplyChanges($id);

// --- 3. Server lehnt Zugang ab
echo "\nZugang abgelehnt\n";
$m->urlAntwort = [203, ''];
$vorher = zustand($m);
$text   = $m->RunSelfTest();
pruefe(str_contains($text, '✗ Calendar URL could not be read: invalid user or password (status 203)'), 'Art des Fehlers mit Statuscode');
pruefe(str_contains($text, '→ Configuration: correct user name and password.'), 'nächster Schritt: Zugangsdaten');
pruefe(zustand($m) === $vorher, 'Status bleibt trotz Fehlschlag unverändert');

$m->urlAntwort = [204, ''];
pruefe(str_contains($m->RunSelfTest(), '→ Server not reachable: try again later'), 'Verbindungsfehler: später erneut versuchen');

// --- 4. abgeschaltete Instanz
echo "\nInstanz abgeschaltet\n";
IPS_SetProperty($id, 'active', false);
IPS_ApplyChanges($id);
$abrufe = $m->urlAbrufe;
$text   = $m->RunSelfTest();
pruefe(str_contains($text, '⚠ Instance is switched off'), 'abgeschaltet als Warnung');
pruefe($m->urlAbrufe === $abrufe, 'abgeschaltet: kein Abruf');

ergebnis();
