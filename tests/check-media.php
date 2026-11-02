<?php

declare(strict_types=1);

/**
 * Modultest gegen den Kernel-Stub: Kalender aus einem Medienobjekt (Befund 1 aus dem
 * Code-Review vom 02.11.2026).
 *
 * Wird das zugewiesene Medienobjekt gelöscht oder ist es leer, darf weder ApplyChanges noch der
 * Abruf-Timer noch der Selbsttest abbrechen. Ohne URL ergibt das den Konfigurationsstatus 201
 * mit einer Warnung, mit gültiger URL wird wie beim Übernehmen die URL gelesen.
 * Seit SymconStubs 31f2e04 kennt der Stub Medienobjekte (MediaManager); vorher war dieser Weg
 * nicht testbar.
 *
 * Aufruf: php tests/check-media.php
 */

require_once __DIR__ . '/harness.php';

date_default_timezone_set('Europe/Berlin');

function neueLogs(iCalCalendarReaderHarness $m): array
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

/** Aufruf ausführen, Ausnahme statt Abbruch des Tests als Text zurückgeben */
function ohneAbbruch(callable $aufruf): string
{
    try {
        $aufruf();
        return '';
    } catch (Throwable $t) {
        return get_class($t) . ': ' . $t->getMessage();
    }
}

function status(int $id): int
{
    return IPS_GetInstance($id)['InstanceStatus'];
}

function kalenderDokument(string $name): int
{
    $mid = IPS_CreateMedia(MEDIATYPE_DOCUMENT);
    IPS_SetName($mid, $name);
    IPS_SetMediaContent($mid, base64_encode(kalender()));
    return $mid;
}

$m  = neueInstanz();
$id = $m->instanzId();
IPS_SetProperty($id, 'Notifiers', json_encode([notifier('NOTIFIER1', 'Restmüll')], JSON_THROW_ON_ERROR));
IPS_SetProperty($id, 'UpdateFrequency', 15);
$m->logsZuruecksetzen();

// --- Ausgangslage: Kalender aus einem Dokument
echo "Medienobjekt mit Kalender, keine URL\n";
$mid = kalenderDokument('Müllkalender');
IPS_SetProperty($id, 'iCalMediaID', $mid);
$abbruch = ohneAbbruch(static fn () => IPS_ApplyChanges($id));
neueLogs($m);
pruefe($abbruch === '', 'ApplyChanges läuft durch ' . $abbruch);
pruefe(status($id) === IS_ACTIVE, 'Status 102 (' . status($id) . ')');
pruefe(IPS_GetObject($id)['ObjectSummary'] === 'Müllkalender', 'Zusammenfassung zeigt den Namen des Medienobjekts');
pruefe(($m->werte()['NOTIFIER1'] ?? null) === true, 'Meldevariable aus dem Medienkalender');

// --- Medienobjekt gelöscht, Instanz läuft noch: der Abruf-Timer schlägt zu
echo "\nMedienobjekt gelöscht, Abruf-Timer läuft\n";
IPS_DeleteMedia($mid, true);
$abrufe  = $m->urlAbrufe;
$abbruch = ohneAbbruch(static fn () => $m->UpdateCalendar());
$logs    = neueLogs($m);
pruefe($abbruch === '', 'UpdateCalendar bricht nicht ab ' . $abbruch);
pruefe(status($id) === 201, 'Status 201 (' . status($id) . ')');
pruefe($m->urlAbrufe === $abrufe, 'kein Abruf einer leeren URL');
pruefe(
    count($logs) === 1 && str_starts_with($logs[0], 'WARNING: ') && str_contains($logs[0], 'Media object #' . $mid),
    'eine Warnung, die das Medienobjekt nennt'
);
pruefe(($m->werte()['NOTIFIER1'] ?? null) === true, 'Meldevariable bleibt beim letzten Stand');

// --- Übernehmen mit gelöschtem Medienobjekt
echo "\nÜbernehmen mit gelöschtem Medienobjekt, keine URL\n";
$abbruch = ohneAbbruch(static fn () => IPS_ApplyChanges($id));
$logs    = neueLogs($m);
pruefe($abbruch === '', 'ApplyChanges bricht nicht ab ' . $abbruch);
pruefe(status($id) === 201, 'Status 201 (' . status($id) . ')');
pruefe($logs === [], 'keine zweite Warnung beim selben Problem');
pruefe(!str_contains((string) IPS_GetObject($id)['ObjectSummary'], 'Müllkalender'), 'Zusammenfassung nennt das gelöschte Objekt nicht mehr');

echo "\nSelbsttest mit gelöschtem Medienobjekt\n";
$text    = '';
$abbruch = ohneAbbruch(static function () use ($m, &$text): void {
    $text = $m->RunSelfTest();
});
pruefe($abbruch === '', 'RunSelfTest bricht nicht ab ' . $abbruch);
pruefe(str_contains($text, '#' . $mid), 'Selbsttest nennt das Medienobjekt');

// --- gelöschtes Medienobjekt, aber gültige URL: wie beim Übernehmen gilt die URL
echo "\nGelöschtes Medienobjekt, gültige URL\n";
$m->urlAntwort = antwortKalender(kalender());
IPS_SetProperty($id, 'CalendarServerURL', 'https://kalender.example/test.ics');
$abrufe  = $m->urlAbrufe;
$abbruch = ohneAbbruch(static fn () => IPS_ApplyChanges($id));
neueLogs($m);
pruefe($abbruch === '', 'ApplyChanges bricht nicht ab ' . $abbruch);
pruefe(status($id) === IS_ACTIVE, 'Status 102 (' . status($id) . ')');
pruefe($m->urlAbrufe === $abrufe + 1, 'Kalender von der URL gelesen');
pruefe(IPS_GetObject($id)['ObjectSummary'] === 'https://kalender.example/test.ics', 'Zusammenfassung zeigt die URL');
$abbruch = ohneAbbruch(static fn () => $m->UpdateCalendar());
pruefe($abbruch === '' && status($id) === IS_ACTIVE, 'Abruf-Timer liest weiter die URL ' . $abbruch);

// --- leeres Dokument (noch keine Datei hinterlegt)
echo "\nLeeres Dokument, keine URL\n";
IPS_SetProperty($id, 'CalendarServerURL', '');
$leer = IPS_CreateMedia(MEDIATYPE_DOCUMENT);
IPS_SetName($leer, 'Leeres Dokument');
IPS_SetProperty($id, 'iCalMediaID', $leer);
$abbruch = ohneAbbruch(static fn () => IPS_ApplyChanges($id));
neueLogs($m);
pruefe($abbruch === '', 'ApplyChanges bricht nicht ab ' . $abbruch);
pruefe(status($id) === 201, 'Status 201 (' . status($id) . ')');
$text    = '';
$abbruch = ohneAbbruch(static function () use ($m, &$text): void {
    $text = $m->RunSelfTest();
});
pruefe($abbruch === '', 'RunSelfTest bricht bei leerem Dokument nicht ab ' . $abbruch);
pruefe(str_contains($text, '#' . $leer), 'Selbsttest nennt das leere Medienobjekt');

ergebnis();
