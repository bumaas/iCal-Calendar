<?php

declare(strict_types=1);

/**
 * Modultest gegen den Kernel-Stub: Instanzstatus, Kalenderabruf und Notifier.
 *
 * Spielt den Weg durch, den ein Anwender geht: Instanz anlegen, URL und Notifier
 * eintragen, Kalender laden, Benachrichtigungen auslösen, Notifier wieder entfernen.
 * Der Kalender kommt über die Netz-Naht der Harness, die Termine liegen relativ zu
 * jetzt (CheckPresence vergleicht mit time()).
 *
 * Aufruf: php tests/check-notifier.php
 */

require_once __DIR__ . '/harness.php';

date_default_timezone_set('Europe/Berlin');

neueUtilControl();

function variablenIdents(int $instanz): array
{
    $idents = [];
    foreach (IPS_GetChildrenIDs($instanz) as $vid) {
        $obj = IPS_GetObject($vid);
        if ($obj['ObjectType'] === 2) {
            $idents[$obj['ObjectIdent']] = $vid;
        }
    }
    ksort($idents);
    return $idents;
}

// --- 1. Neue Instanz ohne URL und Medienobjekt
echo "Neue Instanz ohne Kalenderquelle\n";
$m  = neueInstanz();
$id = $m->instanzId();
pruefe(IPS_GetInstance($id)['InstanceStatus'] === 201, 'Status 201 (ungültige URL)');
pruefe(($m->timer['TriggerCalendarNotifications'] ?? null) === 0, 'Benachrichtigungs-Timer aus');
pruefe($m->urlAbrufe === 0, 'ohne URL kein Abruf');
pruefe(variablenIdents($id) === [], 'keine Variablen ohne Notifier');

// --- 2. URL und Notifier eintragen
echo "\nURL und vier Notifier\n";
$m->urlAntwort = [IS_ACTIVE, kalender()];
IPS_SetProperty($id, 'CalendarServerURL', 'https://kalender.example/test.ics');
IPS_SetProperty($id, 'Notifiers', json_encode([
    notifier('NOTIFIER1', 'Restmüll'),
    notifier('NOTIFIER2', '^Arzt', true),
    notifier('NOTIFIER3', '([', true),       // ungültiger regulärer Ausdruck
    notifier('NOTIFIER4', 'Arzt', false, 25 * 60), // Vorlauf 25 h: der Termin morgen zählt schon
], JSON_THROW_ON_ERROR));
$m->logsZuruecksetzen();
IPS_ApplyChanges($id);

pruefe(IPS_GetInstance($id)['InstanceStatus'] === IS_ACTIVE, 'Status 102 nach erfolgreichem Abruf');
pruefe(array_keys(variablenIdents($id)) === ['NOTIFIER1', 'NOTIFIER2', 'NOTIFIER3', 'NOTIFIER4'], 'je Notifier eine Variable');
foreach (variablenIdents($id) as $ident => $vid) {
    pruefe(IPS_GetVariable($vid)['VariableType'] === VARIABLETYPE_BOOLEAN, "$ident ist boolesch");
}
pruefe(($m->timer['UpdateCalendar'] ?? null) === 15 * 60 * 1000, 'Abruf-Timer 15 min (Standard)');
pruefe(($m->timer['TriggerCalendarNotifications'] ?? null) === 60 * 1000, 'Benachrichtigungs-Timer 1 min');
$warnungen = array_filter($m->logsSeitMarke(), static fn (array $e): bool => $e['Type'] === KL_WARNING);
pruefe(
    count($warnungen) === 1 && str_contains(reset($warnungen)['Message'], 'NOTIFIER3'),
    'ungültiger Ausdruck wird einmal als Warnung gemeldet'
);

try {
    $grund = $m->GetNotifierPresenceReason('NOTIFIER1');
} catch (Throwable $t) {
    $grund = 'Ausnahme: ' . $t->getMessage();
}
pruefe($grund === '[]', "GetNotifierPresenceReason vor der ersten Auswertung: leer statt PHP-Warnung ($grund)");

// --- 3. Kalender laden und Benachrichtigungen auslösen
echo "\nKalender laden, Benachrichtigungen auslösen\n";
$json = $m->UpdateCalendar();
$termine = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
pruefe(count($termine) === 2, 'UpdateCalendar liefert beide Termine');
pruefe($m->GetCachedCalendar() === $json, 'GetCachedCalendar liefert den gepufferten Kalender');

$m->TriggerNotifications();
$werte = $m->werte();
pruefe($werte['NOTIFIER1'] === true, 'NOTIFIER1: laufender Termin „Restmüll" erkannt');
pruefe($werte['NOTIFIER2'] === false, 'NOTIFIER2: Termin morgen ohne Vorlauf nicht aktiv');
pruefe($werte['NOTIFIER3'] === false, 'NOTIFIER3: ungültiger Ausdruck schaltet nicht');
pruefe($werte['NOTIFIER4'] === true, 'NOTIFIER4: Termin morgen über Vorlauf aktiv');

$grund = json_decode($m->GetNotifierPresenceReason('NOTIFIER1'), true, 512, JSON_THROW_ON_ERROR);
pruefe(($grund['Name'] ?? '') === 'Müllabfuhr Restmüll', 'GetNotifierPresenceReason nennt den auslösenden Termin');
pruefe(
    json_decode($m->GetNotifierPresenceReason('NOTIFIER2'), true, 512, JSON_THROW_ON_ERROR) === [],
    'GetNotifierPresenceReason für inaktiven Notifier ist leer'
);

$meldung = '';
try {
    $m->GetNotifierPresenceReason('NOTIFIER9');
} catch (Throwable $t) {
    $meldung = $t->getMessage();
}
pruefe(
    str_contains($meldung, 'NOTIFIER9') && str_contains($meldung, 'NOTIFIER1, NOTIFIER2, NOTIFIER3, NOTIFIER4'),
    'GetNotifierPresenceReason mit unbekanntem Ident: Fehler nennt die gültigen Idents'
);

// --- 4. Notifier entfernen: unbenutzte Variable wird gelöscht, referenzierte bleibt
echo "\nNotifier entfernen\n";
$ids = variablenIdents($id);
UtilControlAttrappe::$referenzen[$ids['NOTIFIER4']] = [12345];
IPS_SetProperty($id, 'Notifiers', json_encode([notifier('NOTIFIER1', 'Restmüll')], JSON_THROW_ON_ERROR));
IPS_ApplyChanges($id);
$rest = array_keys(variablenIdents($id));
pruefe(!in_array('NOTIFIER2', $rest, true) && !in_array('NOTIFIER3', $rest, true), 'unbenutzte Notifier-Variablen gelöscht');
pruefe(in_array('NOTIFIER4', $rest, true), 'referenzierte Notifier-Variable bleibt erhalten');
pruefe(in_array('NOTIFIER1', $rest, true), 'verbleibender Notifier behält seine Variable');

// --- 5. Abruf scheitert
echo "\nAbruf scheitert\n";
$m->urlAntwort = [204, ''];
pruefe($m->UpdateCalendar() === null, 'UpdateCalendar liefert null bei Verbindungsfehler');
pruefe(IPS_GetInstance($id)['InstanceStatus'] === 204, 'Status 204 (Verbindungsfehler)');
pruefe($m->GetCachedCalendar() === '[]', 'GetCachedCalendar liefert bei Störung eine leere Liste');

$m->urlAntwort = [IS_ACTIVE, kalender()];
pruefe($m->UpdateCalendar() !== null && IPS_GetInstance($id)['InstanceStatus'] === IS_ACTIVE, 'nach Störung erholt sich die Instanz beim nächsten Abruf');

// --- 6. Instanz deaktiviert
echo "\nInstanz deaktiviert\n";
$abrufe = $m->urlAbrufe;
IPS_SetProperty($id, 'active', false);
IPS_ApplyChanges($id);
pruefe(IPS_GetInstance($id)['InstanceStatus'] === IS_INACTIVE, 'Status 104 (inaktiv)');
pruefe(($m->timer['TriggerCalendarNotifications'] ?? null) === 0, 'Benachrichtigungs-Timer aus');
pruefe($m->UpdateCalendar() === null && $m->urlAbrufe === $abrufe, 'inaktive Instanz ruft nicht ab');

ergebnis();
