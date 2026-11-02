<?php

declare(strict_types=1);

/**
 * Modultest gegen den Kernel-Stub: Speichergrenze für den Import (Store-Vorab-Review vom
 * 02.11.2026, Hinweis zu ini_set im Konstruktor).
 *
 * Das Parsen eines 1-MB-Kalenders braucht rund 85 MB, unabhängig vom Cache-Fenster (gemessen
 * mit icloud-ausnahmen und kopano-ohne-enddatum). Das liegt über den 64 MB einer SymBox, deshalb
 * hebt das Modul die Grenze an. Es tut das aber nur direkt vor dem Import (Abruf und Selbsttest)
 * und senkt eine höhere oder unbegrenzte Grenze nie ab. Das bloße Laden der Instanz, etwa für
 * GetCachedCalendar oder TriggerNotifications, lässt die Grenze in Ruhe.
 *
 * Aufruf: php tests/check-memory.php
 */

require_once __DIR__ . '/harness.php';

date_default_timezone_set('Europe/Berlin');

const KALENDER_URL = 'https://kalender.example/test.ics';

function grenze(): string
{
    return (string) ini_get('memory_limit');
}

// --- 1. Laden der Instanz
echo "Laden der Instanz\n";
ini_set('memory_limit', '128M');
$m  = neueInstanz();
$id = $m->instanzId();
pruefe(grenze() === '128M', 'Konstruktor lässt die Grenze unverändert (' . grenze() . ')');
$m->GetCachedCalendar();
$m->TriggerNotifications();
pruefe(grenze() === '128M', 'GetCachedCalendar und TriggerNotifications heben nicht an (' . grenze() . ')');

// --- 2. Abruf mit Import
echo "\nAbruf mit Import\n";
IPS_SetProperty($id, 'Notifiers', json_encode([notifier('NOTIFIER1', 'Restmüll')], JSON_THROW_ON_ERROR));
IPS_SetProperty($id, 'CalendarServerURL', KALENDER_URL);
$m->urlAntwort = antwortKalender(kalender());
IPS_ApplyChanges($id);
pruefe(IPS_GetInstance($id)['InstanceStatus'] === IS_ACTIVE, 'Kalender gelesen');
pruefe(grenze() === '256M', 'vor dem Import auf 256M angehoben (' . grenze() . ')');

// --- 3. höhere Grenze bleibt
echo "\nHöhere oder unbegrenzte Grenze\n";
foreach (['512M', '1G', '-1'] as $vorher) {
    ini_set('memory_limit', $vorher);
    $m = neueInstanz();
    IPS_SetProperty($m->instanzId(), 'CalendarServerURL', KALENDER_URL);
    $m->urlAntwort = antwortKalender(kalender());
    IPS_ApplyChanges($m->instanzId());
    $m->UpdateCalendar();
    pruefe(grenze() === $vorher, 'Grenze ' . $vorher . ' wird nicht abgesenkt (' . grenze() . ')');
}

// --- 4. Selbsttest importiert ebenfalls
echo "\nSelbsttest\n";
$m = neueInstanz();
IPS_SetProperty($m->instanzId(), 'CalendarServerURL', KALENDER_URL);
$m->urlAntwort = antwortKalender(kalender());
IPS_ApplyChanges($m->instanzId()); // Properties gelten im Stub erst danach
ini_set('memory_limit', '128M');
$text = $m->RunSelfTest();
pruefe(str_contains($text, '0 errors'), 'Selbsttest ohne Fehler');
pruefe(grenze() === '256M', 'Selbsttest hebt vor dem Import an (' . grenze() . ')');

ergebnis();
