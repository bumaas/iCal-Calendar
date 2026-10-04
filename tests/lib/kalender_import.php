<?php

declare(strict_types=1);

/**
 * Gemeinsamer Import-Aufruf für tests/check-import-regression.php und
 * tests/werkzeuge/neutralisiere_kalender.php: lädt Libs und iCalImporter und
 * importiert einen Kalender mit festem Referenzdatum.
 */

const REFERENCE_DATE      = '2026-08-01'; // fix, damit die Ergebnisse deterministisch sind
const DAYS_TO_CACHE_BACK  = 3000;
const DAYS_TO_CACHE_AHEAD = 3000;

$kalenderRoot = dirname(__DIR__, 2);
require_once $kalenderRoot . '/libs/iCalcreator-master/autoload.php';
require_once $kalenderRoot . '/libs/php-rrule-master/src/RRuleInterface.php';
require_once $kalenderRoot . '/libs/php-rrule-master/src/RRuleTrait.php';
require_once $kalenderRoot . '/libs/php-rrule-master/src/RfcParser.php';
require_once $kalenderRoot . '/libs/php-rrule-master/src/RRule.php';
require_once $kalenderRoot . '/libs/php-rrule-master/src/RSet.php';
require_once $kalenderRoot . '/iCalCalendarReader/iCalImporter.php';

/**
 * @return array{events: ?array, errors: list<string>}
 */
function kalenderImportieren(string $data): array
{
    $errors = [];
    $events = null;
    try {
        $importer = new iCalImporter(
            DAYS_TO_CACHE_BACK,
            DAYS_TO_CACHE_AHEAD,
            static function (string $method, string $message): void {
            },
            static function (string $message) use (&$errors): void {
                $errors[] = $message;
            },
            new DateTimeImmutable(REFERENCE_DATE)
        );
        $events = $importer->ImportCalendar($data);
    } catch (Throwable $t) {
        $errors[] = 'EXCEPTION ' . get_class($t) . ': ' . $t->getMessage();
    }

    // kanonisch sortieren (bei gleicher Startzeit ist die usort-Reihenfolge instabil)
    if (is_array($events)) {
        usort($events, static function (array $a, array $b): int {
            return [$a['From'], $a['To'], $a['UID'], $a['Name']] <=> [$b['From'], $b['To'], $b['UID'], $b['Name']];
        });
    }

    return ['events' => $events, 'errors' => $errors];
}
