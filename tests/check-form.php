<?php

declare(strict_types=1);

/**
 * Modultest gegen den Kernel-Stub: Konfigurationsformular (Blindtest-Befund C, 04.10.2026).
 *
 * Ein ungültiger regulärer Ausdruck in einer Meldevariable stand bisher nur im Log und im
 * Selbsttest; das Formular zeigte nichts, die Meldevariable schaltete einfach nie. Jetzt
 * markiert das Formular die Zeile und nennt den Fehler in einem Label unter der Liste -
 * sichtbar für Anwender und für eine KI, die das Formular über IPS_GetConfigurationForm liest.
 * Ein eigener Fehlerstatus ist bewusst nicht vorgesehen: Er würde das Lesen des Kalenders und
 * alle übrigen Meldevariablen mit lahmlegen.
 *
 * Aufruf: php tests/check-form.php
 */

require_once __DIR__ . '/harness.php';

date_default_timezone_set('Europe/Berlin');

/** Formularelement mit diesem Namen, rekursiv gesucht */
function element(array $elemente, string $name): ?array
{
    foreach ($elemente as $e) {
        if (($e['name'] ?? '') === $name) {
            return $e;
        }
        if (isset($e['items']) && ($treffer = element($e['items'], $name)) !== null) {
            return $treffer;
        }
    }
    return null;
}

function formular(iCalCalendarReaderHarness $m): array
{
    return json_decode($m->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
}

neueUtilControl();

$m  = neueInstanz();
$id = $m->instanzId();
$m->urlAntwort = antwortKalender(kalender());
IPS_SetProperty($id, 'CalendarServerURL', 'https://kalender.example/test.ics');

// --- 1. ein ungültiger Ausdruck
echo "Ein ungültiger regulärer Ausdruck\n";
IPS_SetProperty($id, 'Notifiers', json_encode([
    notifier('NOTIFIER1', 'Restmüll'),
    notifier('NOTIFIER2', 'Bio(tonne', true),
    notifier('NOTIFIER3', '^Arzt', true),
], JSON_THROW_ON_ERROR));
IPS_ApplyChanges($id);
$form  = formular($m);
$liste = element($form['elements'], 'Notifiers');
$zeilen = [];
foreach ($liste['values'] ?? [] as $zeile) {
    $zeilen[$zeile['Ident']] = $zeile;
}
pruefe(isset($zeilen['NOTIFIER2']['rowColor']), 'Zeile mit ungültigem Ausdruck ist farbig markiert');
pruefe(!isset($zeilen['NOTIFIER1']['rowColor']) && !isset($zeilen['NOTIFIER3']['rowColor']), 'gültige Zeilen sind nicht markiert');

$label = element($form['elements'], 'InvalidPatternHint');
echo '      | ' . ($label['caption'] ?? '(kein Label)') . "\n";
pruefe($label !== null && ($label['type'] ?? '') === 'Label' && ($label['visible'] ?? true) === true, 'sichtbares Label unter der Liste');
pruefe(
    $label !== null && str_contains($label['caption'], 'NOTIFIER2') && str_contains($label['caption'], 'Bio(tonne') && str_contains($label['caption'], 'never'),
    'Label nennt Meldevariable, Ausdruck und Folge'
);
pruefe(!str_contains($label['caption'] ?? '', 'NOTIFIER3'), 'gültiger Ausdruck wird nicht genannt');
pruefe(IPS_GetInstance($id)['InstanceStatus'] === IS_ACTIVE, 'Instanz bleibt aktiv (kein eigener Fehlerstatus)');
pruefe($m->werte()['NOTIFIER1'] === true, 'übrige Meldevariablen schalten weiter');

// --- 2. alles gültig
echo "\nAlle Ausdrücke gültig\n";
IPS_SetProperty($id, 'Notifiers', json_encode([notifier('NOTIFIER1', 'Restmüll'), notifier('NOTIFIER2', 'Bio(tonne)?', true)], JSON_THROW_ON_ERROR));
IPS_ApplyChanges($id);
$form  = formular($m);
$label = element($form['elements'], 'InvalidPatternHint');
pruefe($label === null || ($label['visible'] ?? true) === false, 'kein sichtbares Label');
$farbig = array_filter(element($form['elements'], 'Notifiers')['values'] ?? [], static fn (array $z): bool => isset($z['rowColor']));
pruefe($farbig === [], 'keine Zeile markiert');

ergebnis();
