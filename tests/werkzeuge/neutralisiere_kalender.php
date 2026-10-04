<?php

declare(strict_types=1);

/**
 * Erzeugt aus den privaten Testkalendern (Mitschnitte von Anwendern, nicht
 * committet) neutralisierte Fixtures für tests/import_regression.php.
 *
 * Neutralisiert werden alle Freitexte und Adressen (SUMMARY, DESCRIPTION,
 * LOCATION, ORGANIZER, ATTENDEE, UID, URL, CN=, EMAIL= …) — formgetreu:
 * Buchstabe bleibt Buchstabe, Ziffer bleibt Ziffer, Satz- und Escape-Zeichen
 * bleiben stehen, gleiche Texte werden gleich ersetzt (UID ↔ RECURRENCE-ID
 * passt weiter zusammen). Zeiten, Wiederholungsregeln und Zeitzonen bleiben
 * unverändert.
 *
 * Danach wird jeder Kalender vorher und nachher importiert und verglichen:
 * Anzahl, Fehlerzahl und alle textfreien Felder jedes Termins (Zeiten,
 * Ganztägig, Status, Alarme) müssen übereinstimmen, sonst Exit 1.
 *
 * Aufruf:  php tests/werkzeuge/neutralisiere_kalender.php [--quelle=<ordner>]
 *          (Standard: docs/Examples/Testdaten)
 */

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('Europe/Berlin');

require_once dirname(__DIR__) . '/lib/kalender_import.php';

/** Quelldatei => Fixture-Name (ohne Anwendernamen) */
const ZUORDNUNG = [
    'Joachim 20201212.txt'                     => 'icloud-serien-2020',
    'TestDaten.txt'                            => 'google-1',
    'TestDaten_HarmonyFan_ohne_EndeDatum.txt'  => 'kopano-ohne-enddatum',
    'TestDaten_Joachim_Email.txt'              => 'google-ungueltige-email',
    'TestDaten_Joachim_Exception.txt'          => 'icloud-ausnahmen',
    'TestDaten_Joachim_Wiederholung.txt'       => 'google-wiederholung',
    'TestDaten_Proxima_Zeitzone.txt'           => 'sabredav-zeitzone',
    'TestDaten_hfichtinger.txt'                => 'google-2',
    'TestDaten_sunnyww_Zeitzone.txt'           => 'exchange-zeitzone',
    'Testdaten Jahrestag.txt'                  => 'fragment-jahrestag',
    'Testdaten Tanzen.txt'                     => 'fragment-wochentermine',
    'Testdaten2.txt'                           => 'fragment-1',
    'Testdaten_7weazel7.txt'                   => 'fragment-2',
    'Testdaten_Alexander_Münch(flamedoil).txt' => 'fragment-3',
    'Testdaten_Geburtstage.txt'                => 'nextcloud-geburtstage',
    'Testdaten_hoep.txt'                       => 'exchange-gross',
    'iCloud.txt'                               => 'icloud',
    'iCoud Sondezeichen in EXDATE.txt'         => 'sabredav-sonderzeichen-exdate',
];

/** Properties nach RFC 5545/7986 — andere Zeilenanfänge (außer X-…) gelten als Freitext */
const BEKANNTE_PROPERTIES = [
    'BEGIN', 'END', 'VERSION', 'PRODID', 'CALSCALE', 'METHOD', 'ACTION', 'ATTACH', 'ATTENDEE',
    'CATEGORIES', 'CLASS', 'COMMENT', 'COMPLETED', 'CONTACT', 'CREATED', 'DESCRIPTION', 'DTEND',
    'DTSTAMP', 'DTSTART', 'DUE', 'DURATION', 'EXDATE', 'EXRULE', 'FREEBUSY', 'GEO', 'LAST-MODIFIED',
    'LOCATION', 'ORGANIZER', 'PERCENT-COMPLETE', 'PRIORITY', 'RDATE', 'RECURRENCE-ID', 'RELATED-TO',
    'REPEAT', 'REQUEST-STATUS', 'RESOURCES', 'RRULE', 'SEQUENCE', 'STATUS', 'SUMMARY', 'TRANSP',
    'TRIGGER', 'TZID', 'TZNAME', 'TZOFFSETFROM', 'TZOFFSETTO', 'TZURL', 'UID', 'URL', 'NAME',
    'REFRESH-INTERVAL', 'SOURCE', 'COLOR', 'IMAGE', 'CONFERENCE',
];

/** Properties, deren Wert neutralisiert wird */
const TEXT_PROPERTIES = [
    'SUMMARY', 'DESCRIPTION', 'LOCATION', 'COMMENT', 'CONTACT', 'CATEGORIES', 'RESOURCES', 'GEO',
    'URL', 'UID', 'RELATED-TO', 'ORGANIZER', 'ATTENDEE', 'ATTACH', 'CONFERENCE', 'NAME', 'SOURCE', 'IMAGE',
];

/** X-Properties, deren Wert unverändert bleibt (technisch, nicht personenbezogen) */
const X_BEHALTEN = [
    'X-MICROSOFT-CDO-', 'X-MOZ-', 'X-LIC-', 'X-WR-TIMEZONE', 'X-APPLE-CALENDAR-COLOR',
    'X-APPLE-CREATOR-', 'X-NEXTCLOUD-BC-', 'X-MICROSOFT-DISALLOW-COUNTER',
    'X-MICROSOFT-DONOTFORWARDMEETING', 'X-MICROSOFT-REQUESTEDATTENDANCEMODE',
    'X-MICROSOFT-ISRESPONSEREQUESTED', 'X-PUBLISHED-TTL', 'X-MS-OLK-CONFTYPE',
    'X-MS-OLK-AUTOFILLLOCATION',
];

/** Parameter, deren Wert in jeder Property neutralisiert wird */
const TEXT_PARAMETER = [
    'CN', 'EMAIL', 'SENT-BY', 'DIR', 'DELEGATED-TO', 'DELEGATED-FROM', 'MEMBER', 'ALTREP',
    'X-TITLE', 'X-ADDRESS', 'X-APPLE-MAPKIT-HANDLE', 'X-APPLE-ABUID', 'X-APPLE-REFERENCEFRAME',
];

$root   = dirname(__DIR__, 2);
$quelle = $root . '/docs/Examples/Testdaten';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--quelle=')) {
        $quelle = substr($arg, 9);
    }
}
$ziel = dirname(__DIR__) . '/fixtures/kalender';

$dateien = glob($quelle . '/*.txt');
if ($dateien === false || $dateien === []) {
    fwrite(STDERR, "Keine Testkalender in $quelle gefunden.\n");
    exit(1);
}
sort($dateien);
if (!is_dir($ziel) && !mkdir($ziel, 0777, true) && !is_dir($ziel)) {
    fwrite(STDERR, "Zielordner $ziel lässt sich nicht anlegen.\n");
    exit(1);
}

$fehler = 0;
foreach ($dateien as $datei) {
    $name = basename($datei);
    if (!isset(ZUORDNUNG[$name])) {
        echo "FEHLT IN ZUORDNUNG: $name — neutralen Fixture-Namen in ZUORDNUNG eintragen\n";
        $fehler++;
        continue;
    }
    $original     = normalisiere((string)file_get_contents($datei));
    $neutral      = neutralisiere($original);
    $fixture      = $ziel . '/' . ZUORDNUNG[$name] . '.ics.gz';
    file_put_contents($fixture, gzencode($neutral, 9));

    $vorher  = kalenderImportieren($original);
    $nachher = kalenderImportieren((string)gzdecode((string)file_get_contents($fixture)));
    $abweichung = vergleiche($vorher, $nachher);
    printf(
        "%-6s %-32s %5s Termine, %d Fehler  %s\n",
        $abweichung === '' ? 'GLEICH' : 'ANDERS',
        ZUORDNUNG[$name],
        is_array($vorher['events']) ? (string)count($vorher['events']) : '-',
        count($vorher['errors']),
        $abweichung
    );
    if ($abweichung !== '') {
        $fehler++;
    }
}
echo "\n" . count($dateien) . " Kalender, $fehler Abweichungen\n";
exit($fehler === 0 ? 0 : 1);

/**
 * Bringt einen Mitschnitt in reines iCal: literale <CR><LF>-Marker, als
 * PHP-Snippet ($curl_result = '...';) gespeicherte Daten, fehlender Rahmen.
 */
function normalisiere(string $raw): string
{
    $data = str_replace(['<CR><LF>', '<CR>', '<LF>'], ["\r\n", "\r\n", "\r\n"], $raw);
    if (preg_match("/curl_result\s*=\s*'(.*)';/s", $data, $m)) {
        $data = $m[1];
    }
    if (!str_contains($data, 'BEGIN:VCALENDAR')) {
        $data = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//test//EN\r\n" . $data . "\r\nEND:VCALENDAR\r\n";
    }
    return $data;
}

function neutralisiere(string $data): string
{
    $data   = (string)preg_replace("/\r?\n[ \t]/", '', $data);
    $zeilen = preg_split("/\r?\n/", $data);
    $aus    = [];
    foreach ($zeilen as $zeile) {
        $aus[] = neutralisiereZeile($zeile);
    }
    return implode("\r\n", $aus);
}

function neutralisiereZeile(string $zeile): string
{
    // nur bekannte Property-Namen und X-Properties gelten als Property; alles andere
    // (etwa eine falsch umbrochene Adresszeile "21339 ORT:…") wird als Freitext neutralisiert
    if (!preg_match('/^([A-Za-z0-9-]+)([;:].*)$/s', $zeile, $m)
        || (!in_array(strtoupper($m[1]), BEKANNTE_PROPERTIES, true) && !preg_match('/^X-[A-Z0-9-]+$/i', $m[1]))) {
        return $zeile === '' ? '' : pseudo($zeile);
    }
    $property = strtoupper($m[1]);
    $rest     = $m[2];

    // Parameter zerlegen (Anführungszeichen beachten), dann Wert
    $parameter = [];
    $pos       = 0;
    $laenge    = strlen($rest);
    while ($pos < $laenge && $rest[$pos] === ';') {
        $start  = ++$pos;
        $quoted = false;
        while ($pos < $laenge && ($quoted || ($rest[$pos] !== ';' && $rest[$pos] !== ':'))) {
            if ($rest[$pos] === '"') {
                $quoted = !$quoted;
            }
            $pos++;
        }
        $parameter[] = substr($rest, $start, $pos - $start);
    }
    $wert = substr($rest, $pos); // beginnt mit ':' oder ist leer

    foreach ($parameter as $i => $param) {
        [$pName, $pWert] = array_pad(explode('=', $param, 2), 2, null);
        if ($pWert !== null && in_array(strtoupper($pName), TEXT_PARAMETER, true)) {
            $parameter[$i] = $pName . '=' . pseudoMitSchema($pWert);
        }
    }

    if ($wert !== '' && istTextProperty($property)) {
        $wert = ':' . pseudoMitSchema(substr($wert, 1));
    }

    return $m[1] . ($parameter === [] ? '' : ';' . implode(';', $parameter)) . $wert;
}

function istTextProperty(string $property): bool
{
    if (in_array($property, TEXT_PROPERTIES, true)) {
        return true;
    }
    if (!str_starts_with($property, 'X-')) {
        return false;
    }
    foreach (X_BEHALTEN as $praefix) {
        if (str_starts_with($property, $praefix)) {
            return false;
        }
    }
    return true;
}

/** Wie pseudo(), lässt aber ein URI-Schema (mailto:, https:, geo: …) und Anführungszeichen stehen */
function pseudoMitSchema(string $text): string
{
    if (preg_match('/^("?)(mailto:|https?:|geo:|tel:|urn:|cid:)?(.*?)("?)$/is', $text, $m)) {
        return $m[1] . $m[2] . pseudo($m[3]) . $m[4];
    }
    return pseudo($text);
}

/**
 * Formgetreue, deterministische Ersetzung: gleiche Eingabe ergibt gleiche Ausgabe.
 * Escape-Folgen (\n, \, …) und alle Zeichen außer Buchstaben/Ziffern bleiben stehen.
 */
function pseudo(string $text): string
{
    $strom = '';
    $n     = 0;
    $aus   = '';
    $len   = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $c = $text[$i];
        if ($c === '\\' && $i + 1 < $len) {
            $aus .= $c . $text[++$i];
            continue;
        }
        $o = ord($c);
        if ($o >= 0xC0) { // Beginn eines Mehrbyte-Zeichens: als ein Nicht-ASCII-Buchstabe ersetzen
            while ($i + 1 < $len && (ord($text[$i + 1]) & 0xC0) === 0x80) {
                $i++;
            }
            $aus .= 'ö';
            continue;
        }
        if ($o >= 0x80) {
            $aus .= 'ö';
            continue;
        }
        $istKlein  = $c >= 'a' && $c <= 'z';
        $istGross  = $c >= 'A' && $c <= 'Z';
        $istZiffer = $c >= '0' && $c <= '9';
        if (!$istKlein && !$istGross && !$istZiffer) {
            $aus .= $c;
            continue;
        }
        if ($n >= strlen($strom)) {
            $strom .= md5($text . '#' . strlen($strom), true);
        }
        $zufall = ord($strom[$n++]);
        $aus .= match (true) {
            $istKlein => chr(ord('a') + $zufall % 26),
            $istGross => chr(ord('A') + $zufall % 26),
            default   => chr(ord('0') + $zufall % 10),
        };
    }
    return $aus;
}

/** Vergleicht zwei Importe ohne die neutralisierten Textfelder; leerer String = gleich */
function vergleiche(array $a, array $b): string
{
    if (count($a['errors']) !== count($b['errors'])) {
        return sprintf('Fehlerzahl %d statt %d', count($b['errors']), count($a['errors']));
    }
    if (!is_array($a['events']) || !is_array($b['events'])) {
        return $a['events'] === $b['events'] ? '' : 'Import nur auf einer Seite gescheitert';
    }
    if (count($a['events']) !== count($b['events'])) {
        return sprintf('%d statt %d Termine', count($b['events']), count($a['events']));
    }
    $kern = static function (array $events): array {
        $liste = array_map(static fn (array $e): string => json_encode([
            $e['From'], $e['To'], $e['FromS'], $e['ToS'], $e['allDay'], $e['Status'], $e['Alarms'],
        ], JSON_THROW_ON_ERROR), $events);
        sort($liste);
        return $liste;
    };
    $ka = $kern($a['events']);
    $kb = $kern($b['events']);
    if ($ka !== $kb) {
        $diff = array_diff($ka, $kb);
        return 'Terminfelder abweichend, z. B. ' . (reset($diff) ?: '(Reihenfolge/Mehrfachvorkommen)');
    }
    return '';
}
