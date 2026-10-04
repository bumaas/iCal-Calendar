<?php

declare(strict_types=1);

/**
 * Modultest gegen den Kernel-Stub: Übernehmen der Einstellungen (Befunde A und B aus dem
 * Blindtest vom 04.10.2026, Ergebnis unter Eigenes/nuc/checks).
 *
 * A: Eine Notifier-Liste, die gültiges JSON, aber keine Liste ist (z. B. doppelt kodiert, wie
 *    sie ein naheliegender MCP-Aufruf von IPS_SetProperty erzeugt), darf ApplyChanges nicht
 *    abbrechen lassen, sondern ergibt einen Fehlerstatus mit Log-Warnung und Handlung.
 * B: Nach dem Übernehmen ist der Kalender gelesen und die Meldevariablen stimmen sofort - nicht
 *    erst nach Ablauf des Abrufintervalls (bis zu Stunden, bei Intervall 0 nie).
 * Dazu: Scheitert der Abruf beim Übernehmen, läuft der Abruf-Timer trotzdem, damit der in der
 *    Log-Warnung angekündigte erneute Versuch auch stattfindet.
 *
 * Aufruf: php tests/check-apply.php
 */

require_once __DIR__ . '/harness.php';

date_default_timezone_set('Europe/Berlin');

function neueWarnungen(iCalCalendarReaderHarness $m): array
{
    $liste = [];
    foreach ($m->logsSeitMarke() as $e) {
        $liste[] = ($e['Type'] === KL_WARNING ? 'WARNING' : ($e['Type'] === KL_MESSAGE ? 'MESSAGE' : (string) $e['Type'])) . ': ' . $e['Message'];
    }
    $m->logsZuruecksetzen();
    foreach ($liste as $zeile) {
        echo '      | ' . $zeile . "\n";
    }
    return $liste;
}

/** Übernehmen, Ausnahme statt Abbruch des Tests zurückgeben */
function uebernehmen(int $id): string
{
    try {
        IPS_ApplyChanges($id);
        return '';
    } catch (Throwable $t) {
        return get_class($t) . ': ' . $t->getMessage();
    }
}

$m  = neueInstanz();
$id = $m->instanzId();
$m->logsZuruecksetzen();

// --- B. Einrichten: der Kalender ist nach dem Übernehmen gelesen
echo "Einrichten mit URL und Notifier\n";
$m->urlAntwort = antwortKalender(kalender());
IPS_SetProperty($id, 'CalendarServerURL', 'https://kalender.example/test.ics');
IPS_SetProperty($id, 'UpdateFrequency', 360);
IPS_SetProperty($id, 'Notifiers', json_encode([notifier('NOTIFIER1', 'Restmüll')], JSON_THROW_ON_ERROR));
$abrufe = $m->urlAbrufe;
$abbruch = uebernehmen($id);
pruefe($abbruch === '', 'ApplyChanges läuft durch ' . $abbruch);
pruefe(IPS_GetInstance($id)['InstanceStatus'] === IS_ACTIVE, 'Status 102');
pruefe($m->urlAbrufe === $abrufe + 1, 'genau ein Abruf beim Übernehmen (' . ($m->urlAbrufe - $abrufe) . ')');
pruefe(count(json_decode($m->GetCachedCalendar(), true, 512, JSON_THROW_ON_ERROR)) === 2, 'Kalender nach dem Übernehmen gelesen (ohne UpdateCalendar)');
pruefe(($m->werte()['NOTIFIER1'] ?? null) === true, 'Meldevariable stimmt sofort, nicht erst nach 6 Stunden');

// Quellenwechsel: neue Termine gelten sofort
echo "\nQuellenwechsel\n";
$m->urlAntwort = antwortKalender(str_replace('Müllabfuhr Restmüll', 'Gelbe Tonne', kalender()));
IPS_SetProperty($id, 'CalendarServerURL', 'https://anderer-kalender.example/neu.ics');
uebernehmen($id);
pruefe(($m->werte()['NOTIFIER1'] ?? null) === false, 'nach Quellenwechsel schaltet die Meldevariable nach dem neuen Kalender');

// Intervall 0: gelesen wird trotzdem beim Übernehmen
echo "\nAbrufintervall 0\n";
$m->urlAntwort = antwortKalender(kalender());
IPS_SetProperty($id, 'UpdateFrequency', 0);
uebernehmen($id);
pruefe(($m->werte()['NOTIFIER1'] ?? null) === true, 'Intervall 0: Kalender beim Übernehmen gelesen');
IPS_SetProperty($id, 'UpdateFrequency', 15);
uebernehmen($id);
neueWarnungen($m);

// Abruf scheitert beim Übernehmen: der Timer muss den erneuten Versuch trotzdem machen
echo "\nAbruf scheitert beim Übernehmen\n";
$m->urlAntwort = antwortCurlFehler(7, 'Failed to connect to kalender.example port 443');
$m->timer = [];
uebernehmen($id);
neueWarnungen($m);
pruefe(IPS_GetInstance($id)['InstanceStatus'] === 204, 'Status 204');
pruefe(($m->timer['UpdateCalendar'] ?? null) === 15 * 60 * 1000, 'Abruf-Timer läuft (15 min) - der angekündigte erneute Versuch findet statt');

// --- A. Notifier-Liste ist gültiges JSON, aber keine Liste
echo "\nDoppelt kodierte Notifier-Liste\n";
$m->urlAntwort = antwortKalender(kalender());
IPS_SetProperty($id, 'Notifiers', json_encode(json_encode([notifier('NOTIFIER1', 'Restmüll')], JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR));
$abbruch = uebernehmen($id);
$logs   = neueWarnungen($m);
pruefe($abbruch === '', 'ApplyChanges bricht nicht ab ' . $abbruch);
pruefe(IPS_GetInstance($id)['InstanceStatus'] === 208, 'eigener Fehlerstatus 208');
pruefe(
    count($logs) === 1 && str_starts_with($logs[0], 'WARNING: ') && str_contains($logs[0], 'not a JSON array') && str_contains($logs[0], 'json_encode'),
    'eine Warnung: was falsch ist und wie es richtig geht'
);
pruefe(in_array('NOTIFIER1', array_keys($m->werte()), true), 'vorhandene Meldevariable bleibt erhalten');
try {
    $text = $m->RunSelfTest();
} catch (Throwable $t) {
    $text = 'Ausnahme: ' . $t->getMessage();
}
pruefe(str_contains($text, '✗ Notifier list'), 'Selbsttest meldet den Fehler');
try {
    $m->TriggerNotifications();
    $abbruch = '';
} catch (Throwable $t) {
    $abbruch = $t->getMessage();
}
pruefe($abbruch === '', 'TriggerNotifications mit kaputter Liste ohne Abbruch ' . $abbruch);

echo "\nNotifier-Liste korrigiert, Einträge ohne optionale Felder\n";
IPS_SetProperty($id, 'Notifiers', json_encode([['Ident' => 'NOTIFIER1', 'Find' => 'Restmüll']], JSON_THROW_ON_ERROR));
$abbruch = uebernehmen($id);
$logs   = neueWarnungen($m);
pruefe($abbruch === '' && IPS_GetInstance($id)['InstanceStatus'] === IS_ACTIVE, 'Status 102 ' . $abbruch);
pruefe(count($logs) === 1 && str_contains($logs[0], 'MESSAGE:'), 'Behebung einmal gemeldet');
pruefe(($m->werte()['NOTIFIER1'] ?? null) === true, 'Eintrag ohne RegExpression/Prenotify/Postnotify funktioniert mit Standardwerten');

ergebnis();
