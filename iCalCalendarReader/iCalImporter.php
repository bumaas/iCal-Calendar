<?php

declare(strict_types=1);

use Kigkonsult\Icalcreator\IcalInterface;
use Kigkonsult\Icalcreator\Pc;
use RRule\RRule;

/***********************************************************************
 * iCal importer class
 ************************************************************************/
class iCalImporter
{
    /**
     * MS-/Windows-Zeitzonennamen mit zugehörigem UTC-Offset (Standardzeit).
     * Übernommen aus der mit iCalcreator 2.41.57 entfallenen RegulateTimezoneFactory
     * ($MStimezoneToOffset); wird von regulateTimezones() genutzt, da das Symcon-PHP
     * kein ext-intl (IntlTimeZone::getIDForWindowsID) bereitstellt.
     */
    private const MS_TIMEZONE_TO_OFFSET = [
        'Afghanistan Standard Time'       => '+04:30',
        'Arab Standard Time'              => '+03:00',
        'Arabian Standard Time'           => '+04:00',
        'Arabic Standard Time'            => '+03:00',
        'Argentina Standard Time'         => '-03:00',
        'Atlantic Standard Time'          => '-04:00',
        'AUS Eastern Standard Time'       => '+10:00',
        'Azerbaijan Standard Time'        => '+04:00',
        'Bangladesh Standard Time'        => '+06:00',
        'Belarus Standard Time'           => '+03:00',
        'Cape Verde Standard Time'        => '-01:00',
        'Caucasus Standard Time'          => '+04:00',
        'Central America Standard Time'   => '-06:00',
        'Central Asia Standard Time'      => '+06:00',
        'Central Europe Standard Time'    => '+01:00',
        'Central European Standard Time'  => '+01:00',
        'Central Pacific Standard Time'   => '+11:00',
        'Central Standard Time (Mexico)'  => '-06:00',
        'China Standard Time'             => '+08:00',
        'E. Africa Standard Time'         => '+03:00',
        'E. Europe Standard Time'         => '+02:00',
        'E. South America Standard Time'  => '-03:00',
        'Eastern Standard Time'           => '-05:00',
        'Egypt Standard Time'             => '+02:00',
        'Fiji Standard Time'              => '+12:00',
        'FLE Standard Time'               => '+02:00',
        'Georgian Standard Time'          => '+04:00',
        'GMT Standard Time'               => '',
        'Greenland Standard Time'         => '-03:00',
        'Greenwich Standard Time'         => '',
        'GTB Standard Time'               => '+02:00',
        'Hawaiian Standard Time'          => '-10:00',
        'India Standard Time'             => '+05:30',
        'Israel Standard Time'            => '+02:00',
        'Jordan Standard Time'            => '+02:00',
        'Korea Standard Time'             => '+09:00',
        'Mauritius Standard Time'         => '+04:00',
        'Middle East Standard Time'       => '+02:00',
        'Montevideo Standard Time'        => '-03:00',
        'Morocco Standard Time'           => '',
        'Myanmar Standard Time'           => '+06:30',
        'Namibia Standard Time'           => '+01:00',
        'Nepal Standard Time'             => '+05:45',
        'New Zealand Standard Time'       => '+12:00',
        'Pacific SA Standard Time'        => '-03:00',
        'Pacific Standard Time'           => '-08:00',
        'Pakistan Standard Time'          => '+05:00',
        'Paraguay Standard Time'          => '-04:00',
        'Romance Standard Time'           => '+01:00',
        'Russian Standard Time'           => '+03:00',
        'SA Eastern Standard Time'        => '-03:00',
        'SA Pacific Standard Time'        => '-05:00',
        'SA Western Standard Time'        => '-04:00',
        'Samoa Standard Time'             => '+13:00',
        'SE Asia Standard Time'           => '+07:00',
        'Singapore Standard Time'         => '+08:00',
        'South Africa Standard Time'      => '+02:00',
        'Sri Lanka Standard Time'         => '+05:30',
        'Syria Standard Time'             => '+02:00',
        'Taipei Standard Time'            => '+08:00',
        'Tokyo Standard Time'             => '+09:00',
        'Tonga Standard Time'             => '+13:00',
        'Turkey Standard Time'            => '+02:00',
        'Ulaanbaatar Standard Time'       => '+08:00',
        'UTC'                             => '',
        'UTC-02'                          => '-02:00',
        'UTC-11'                          => '-11:00',
        'UTC+12'                          => '+12:00',
        'Venezuela Standard Time'         => '-04:30',
        'W. Central Africa Standard Time' => '+01:00',
        'W. Europe Standard Time'         => '+01:00',
        'West Asia Standard Time'         => '+05:00',
        'West Pacific Standard Time'      => '+10:00',
    ];

    private string $Timezone;

    private int    $DaysToCacheAhead;

    private int    $DaysToCacheBack;

    private array  $CalendarTimezones;

    private        $Logger_Dbg;

    private        $Logger_Err;

    private DateTime $ReferenceDate;

    /*
        convert the timezone RRULE to a datetime object in the given/current year
    */
    private function TZRRuleToDateTime($RRule, $Year = ''): ?DateTime
    {
        // always yearly, once a year
        if (array_key_exists('BYDAY', $RRule) && array_key_exists('0', $RRule['BYDAY'])) {
            $Occ = $RRule['BYDAY']['0'];
            if (array_key_exists('DAY', $RRule['BYDAY'])) {
                $Day = $RRule['BYDAY']['DAY'];
                if (array_key_exists('BYMONTH', $RRule)) {
                    $Month     = $RRule['BYMONTH'];
                    $DateObj   = DateTime::createFromFormat('!m', $Month);
                    $MonthName = $DateObj->format('F');
                    $DayName   = match ($Day) {
                        'MO' => 'Monday',
                        'TU' => 'Tuesday',
                        'WE' => 'Wednesday',
                        'TH' => 'Thursday',
                        'FR' => 'Friday',
                        'SA' => 'Saturday',
                        default => 'Sunday',
                    };
                    return date_timestamp_set(new DateTime(), strtotime($Occ . ' ' . $DayName . ' ' . $MonthName . ' ' . $Year . '00:00:00'));
                }
            }
        }
        return null;
    }

    /*
        apply the time offset from a timezone provided by the loaded calendar
    */
    private function ApplyCustomTimezoneOffset(DateTime $EventDateTime, string $CustomTimezoneName): DateTime
    {
        // is timezone in calendar provided timezone?
        foreach ($this->CalendarTimezones as $CalendarTimezone) {
            if ($CalendarTimezone['TZID'] === $CustomTimezoneName) {
                $DSTStartDateTime = $this->TZRRuleToDateTime($CalendarTimezone['DAYLIGHT_RRULE'], $EventDateTime->format('Y'));
                $DSTEndDateTime   = $this->TZRRuleToDateTime($CalendarTimezone['STANDARD_RRULE'], $EventDateTime->format('Y'));

                // between these dates?
                if (($EventDateTime > $DSTStartDateTime) && ($EventDateTime < $DSTEndDateTime)) {
                    $from_diff = sprintf(
                        '%s %d hours %s %d minutes',
                        $CalendarTimezone['TZOFFSETFROM'][0],
                        substr($CalendarTimezone['TZOFFSETFROM'], 1, 2),
                        $CalendarTimezone['TZOFFSETFROM'][0],
                        substr($CalendarTimezone['TZOFFSETFROM'], 3, 2)
                    );
                    $EventDateTime->add(DateInterval::createFromDateString($from_diff));
                } else {
                    $to_diff = sprintf(
                        '%s %d hours %s %d minutes',
                        $CalendarTimezone['TZOFFSETTO'][0],
                        substr($CalendarTimezone['TZOFFSETTO'], 1, 2),
                        $CalendarTimezone['TZOFFSETTO'][0],
                        substr($CalendarTimezone['TZOFFSETTO'], 3, 2)
                    );
                    $EventDateTime->add(DateInterval::createFromDateString($to_diff));
                }
                break;
            }
        }
        return $EventDateTime;
    }

    /*
        convert iCal format to PHP DateTime respecting timezone information
        every information will be transformed into the current timezone!
    */
    private function iCalDateTimeArrayToDateTime(Pc|array $dtValue, bool $WholeDay): DateTime
    {

        if (!($dtValue['value'] instanceof DateTime)) {
            throw new RuntimeException('Component is not of type DateTime');
        }

        $value = $dtValue['value'];

        $Year  = (int)$value->format('Y');
        $Month = (int)$value->format('n');
        $Day   = (int)$value->format('j');
        $Hour  = (int)$value->format('G');
        $Min   = (int)$value->format('i');
        $Sec   = (int)$value->format('s');

        // owncloud calendar
        $params = $dtValue['params'] ?? [];
        $TimezoneName = $params['TZID'] ?? $value->getTimezone()->getName();

        $DateTime = new DateTime();

        // whole-day, this is not timezone relevant!
        if ($WholeDay) {
            $DateTime->setTimezone(new DateTimeZone($this->Timezone));
            $DateTime->setDate($Year, $Month, $Day);
            $DateTime->setTime($Hour, $Min, $Sec);
        } else {
            $IsStandardTimezone = true;
            try {
                $tz = new DateTimeZone($TimezoneName);
            } catch (Exception) {
                $this->logError(sprintf('"%s" is no Standard Timezone', $TimezoneName));
                // no standard timezone, set to UTC first
                $tz                 = new DateTimeZone('UTC');
                $IsStandardTimezone = false;
            }

            $DateTime->setTimezone($tz);
            $DateTime->setDate($Year, $Month, $Day);
            $DateTime->setTime($Hour, $Min, $Sec);
            if (!$IsStandardTimezone) {
                // set UTC offset if provided in calendar data
                $DateTime = $this->ApplyCustomTimezoneOffset($DateTime, 'UTC');
            }
            // convert to the local timezone
            $DateTime->setTimezone(new DateTimeZone($this->Timezone));
        }
        return $DateTime;
    }

    /*
        basic setup

        $ReferenceDate: Bezugsdatum für das Cache-Fenster (Standard: heute);
        von der Regressions-Testsuite genutzt, um deterministische Ergebnisse zu erhalten
    */
    public function __construct(
        int $DaysToCacheBack,
        int $DaysToCacheAhead,
        callable $Logger_Dbg,
        callable $Logger_Err,
        ?DateTimeInterface $ReferenceDate = null
    ) {
        $this->Timezone         = date_default_timezone_get();
        $this->DaysToCacheAhead = $DaysToCacheAhead;
        $this->DaysToCacheBack  = $DaysToCacheBack;
        $this->Logger_Dbg       = $Logger_Dbg;
        $this->Logger_Err       = $Logger_Err;
        $this->ReferenceDate    = $ReferenceDate !== null
            ? DateTime::createFromInterface($ReferenceDate)->setTime(0, 0)
            : new DateTime('today');
    }

    /*
        Nicht-PHP-Zeitzonenbezeichner im iCal-Text durch PHP-Zeitzonen ersetzen.
        Ersetzt die mit iCalcreator 2.41.57 entfallene RegulateTimezoneFactory —
        notwendig, weil iCalcreator 2.41.x beim Parsen für unbekannte TZIDs eine
        Exception wirft (und der IntlTimeZone-Fallback ohne ext-intl nicht existiert).
        Behandelt werden:
        - mit '"' oder '\' verunstaltete TZIDs (bisherige bumaas-Patches in der Lib)
        - Windows-/MS-Namen ("W. Europe Standard Time", Tabelle MS_TIMEZONE_TO_OFFSET)
        - Exchange-Displaynamen ("(UTC+01:00) Amsterdam, ...", "(GMT +01:00) ...", "(UTC)")
        - Offsets ohne Doppelpunkt-Norm ("+02", "GMT+0200")
        - unbekannte Namen ("Customized Time Zone"), sofern der Kalender eine
          VTIMEZONE-Definition mit TZOFFSETTO mitliefert (Offset-Ableitung)
    */
    private function regulateTimezones(string $iCalData): string
    {
        // Zeilen entfalten (RFC-5545-Folding), damit TZID-Werte vollständig vorliegen;
        // der Parser akzeptiert ungefaltete Zeilen beliebiger Länge
        $unfolded = preg_replace('/\r?\n[ \t]/', '', $iCalData);
        if (!is_string($unfolded)) {
            return $iCalData;
        }

        // alle TZID-Werte einsammeln: Property-Zeilen (VTIMEZONE) und Parameter
        $rawTzids = [];
        if (preg_match_all('/^TZID(?:;[^:]*)?:(.+?)\r?$/m', $unfolded, $matches)) {
            $rawTzids = $matches[1];
        }
        if (preg_match_all('/;TZID="([^"]+)"/', $unfolded, $matches)) {
            $rawTzids = array_merge($rawTzids, $matches[1]);
        }
        if (preg_match_all('/;TZID=([^";:=\r\n]+)[;:]/', $unfolded, $matches)) {
            $rawTzids = array_merge($rawTzids, $matches[1]);
        }

        $replacements = [];
        foreach (array_unique($rawTzids) as $rawTzid) {
            $phpTz = $this->mapTzidToPhpTimezone($rawTzid, $unfolded);
            if (($phpTz !== null) && ($phpTz !== $rawTzid)) {
                $replacements[$rawTzid] = $phpTz;
            }
        }

        // nur im TZID-Kontext ersetzen (Property-Zeile bzw. Parameter), nicht im Freitext
        foreach ($replacements as $rawTzid => $phpTz) {
            $this->logDebug(__FUNCTION__, sprintf('TZID "%s" -> "%s"', $rawTzid, $phpTz));
            $quoted   = preg_quote($rawTzid, '/');
            $unfolded = preg_replace(
                ['/^(TZID(?:;[^:]*)?:)' . $quoted . '(\r?)$/m', '/;TZID="?' . $quoted . '"?(?=[;:])/'],
                ['${1}' . $phpTz . '${2}', ';TZID=' . $phpTz],
                $unfolded
            );
        }

        return $unfolded;
    }

    /*
        einen einzelnen TZID-Wert auf eine PHP-Zeitzone abbilden;
        null = keine Ersetzung nötig/möglich
    */
    private function mapTzidToPhpTimezone(string $rawTzid, string $unfoldedIcal): ?string
    {
        // '\'-Escapes (z. B. "\," ) und umschließende '"' entfernen
        $clean = trim(str_replace('\\', '', $rawTzid), '" ');

        // reiner Offset ("+02", "+02:00", "GMT+0200"): fester Offset OHNE Sommerzeit.
        // Muss vor der PHP-Zeitzonen-Prüfung stehen, denn "+02:00" wäre zwar gültig,
        // würde von iCalcreator aber wieder auf eine DST-behaftete Zone abgebildet;
        // Etc/GMT-Zonen (Vorzeichen invertiert!) bleiben dagegen unangetastet
        if (preg_match('/^(?:UTC|GMT)?([+-])(\d{1,2}):?(\d{2})?$/', $clean, $matches)) {
            $sign    = $matches[1];
            $hours   = (int)$matches[2];
            $minutes = (int)($matches[3] ?? 0);
            if (($minutes === 0) && ($hours <= 14)) {
                return 'Etc/GMT' . ($sign === '+' ? '-' : '+') . $hours;
            }
            return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
        }

        // bereits eine gültige PHP-Zeitzone?
        try {
            $tzName = (new DateTimeZone($clean))->getName();
            if (strcasecmp($tzName, $clean) === 0) {
                return ($clean === $rawTzid) ? null : $clean;
            }
        } catch (Exception) {
            // keine gültige PHP-Zeitzone, weiter mit den Mappings
        }

        // Windows-/MS-Name laut Tabelle
        if (isset(self::MS_TIMEZONE_TO_OFFSET[$clean])) {
            return $this->offsetToPhpTimezone(self::MS_TIMEZONE_TO_OFFSET[$clean]);
        }

        // Exchange-Displayname "(UTC+01:00) ...", "(GMT +01:00) ..." — solche Namen
        // bezeichnen DST-behaftete Zonen, daher Abbildung auf eine benannte Zeitzone
        if (preg_match('/^\((?:UTC|GMT)\s?([+-]\d{2}:\d{2})\)/', $clean, $matches)) {
            return $this->offsetToPhpTimezone($matches[1]);
        }
        if (preg_match('/^\((?:UTC|GMT)\)/', $clean)) {
            return 'UTC';
        }

        // unbekannter Name: Offset aus der VTIMEZONE-Definition des Kalenders ableiten
        $pattern = '/BEGIN:VTIMEZONE.*?TZID(?:;[^:]*)?:' . preg_quote($rawTzid, '/')
                   . '.*?BEGIN:STANDARD.*?TZOFFSETTO:([+-]\d{4}).*?END:VTIMEZONE/s';
        if (preg_match($pattern, $unfoldedIcal, $matches)) {
            $offset = substr($matches[1], 0, 3) . ':' . substr($matches[1], 3);
            return $this->offsetToPhpTimezone($offset);
        }

        // letzte Stufe: als lokale Zeitzone interpretieren, damit der Kalender nicht
        // komplett verloren geht (iCalcreator 2.41.x bricht bei unbekannter TZID ab)
        $this->logError(
            sprintf('TZID "%s" konnte keiner PHP-Zeitzone zugeordnet werden und wird als "%s" interpretiert', $rawTzid, $this->Timezone)
        );
        return $this->Timezone;
    }

    /*
        UTC-Offset (Standardzeit, "+HH:MM") auf eine PHP-Zeitzone abbilden
    */
    private function offsetToPhpTimezone(string $offset): ?string
    {
        if ($offset === '' || $offset === '+00:00' || $offset === '-00:00') {
            return 'UTC';
        }
        // bisheriges Verhalten beibehalten: das frühere explizite Mapping des Moduls
        // ("(UTC+01:00) Amsterdam, Berlin, ..." -> Europe/Amsterdam)
        if ($offset === '+01:00') {
            return 'Europe/Amsterdam';
        }
        $seconds = ((int)substr($offset, 0, 3)) * 3600
                   + (int)($offset[0] . substr($offset, 4, 2)) * 60;
        $tzName  = timezone_name_from_abbr('', $seconds, 0);
        if ($tzName !== false) {
            return $tzName;
        }
        // keine benannte Zone gefunden: fester Offset ist ebenfalls eine gültige PHP-Zeitzone
        return $offset;
    }

    /*
        main import method
    */
    public function ImportCalendar(string $iCalData): array
    {
        // see Internet Calendaring and Scheduling Core Object Specification https://tools.ietf.org/html/rfc5545

        $iCalCalendarArray       = [];
        $this->CalendarTimezones = [];

        // Nicht-PHP-Zeitzonen (Windows-IDs, Exchange-Displaynamen wie "(UTC+01:00) Amsterdam, ...")
        // vor dem Parsen durch PHP-Zeitzonen ersetzen; ersetzt die mit iCalcreator 2.41.57
        // entfallene RegulateTimezoneFactory
        $stringCalendarToParse = $this->regulateTimezones($iCalData);

        try {
            $vCalendar = new Kigkonsult\Icalcreator\Vcalendar();
            $vCalendar->parse($stringCalendarToParse);

        } catch (Exception $e) {
            $this->logError('parse: ' . $e->getMessage());
            return [];
        }

        $this->collectCalendarTimezones($vCalendar);

        $CacheDateTimeFrom  = (clone $this->ReferenceDate)->sub(new DateInterval('P' . $this->DaysToCacheBack . 'D')); //P='Period', D='Days'
        $CacheDateTimeUntil = (clone $this->ReferenceDate)->add(new DateInterval('P' . ($this->DaysToCacheAhead + 1) . 'D'));
        $this->logDebug(
            __FUNCTION__,
            sprintf(
                'cached time: (DaysToCacheBack: %s, DaysToCache: %s, %s - %s)',
                $this->DaysToCacheBack,
                $this->DaysToCacheAhead,
                $CacheDateTimeFrom->format('Y-m-d H:i:s'),
                $CacheDateTimeUntil->format('Y-m-d H:i:s')
            )
        );

        [$vEvents, $vEvents_with_RRULE, $vEvents_with_Recurrence_id] = $this->classifyVevents($vCalendar, $CacheDateTimeUntil);

        $eventArray = [];

        foreach ($vEvents as $vEvent) {
            $eventArray[] = $this->processSingleEvent($vEvent);
        }

        foreach ($vEvents_with_RRULE as $vEvent) {
            array_push(
                $eventArray,
                ...$this->processRecurringEvent($vEvent, $vEvents_with_Recurrence_id, $CacheDateTimeFrom, $CacheDateTimeUntil)
            );
        }

        foreach ($eventArray as $event) {
            if ($this->isEventOutsideCachedTime($event, $CacheDateTimeFrom, $CacheDateTimeUntil)) {
                $this->logDebug(
                    __FUNCTION__,
                    sprintf(
                        'Event \'%s\' (%s - %s) is outside the cached time, is ignored',
                        $event['Name'],
                        $event['FromS'],
                        $event['ToS']
                    )
                );
            } else {
                // insert event
                $iCalCalendarArray[] = $event;
            }
        }

        // sort by start date/time to make the check on changes work
        usort(
            $iCalCalendarArray, static function ($a, $b) {
            return $a['From'] - $b['From'];
        }
        );
        return $iCalCalendarArray;
    }

    /*
        die im Kalender mitgelieferten VTIMEZONE-Definitionen einsammeln
        (Basis für den Fallback ApplyCustomTimezoneOffset bei unbekannten Zeitzonen)
    */
    private function collectCalendarTimezones(Kigkonsult\Icalcreator\Vcalendar $vCalendar): void
    {
        while ($vTimezone = $vCalendar->getComponent(IcalInterface::VTIMEZONE)) {
            if (!($vTimezone instanceof Kigkonsult\Icalcreator\Vtimezone)) {
                throw new RuntimeException('Component is not of type Vtimezone');
            }

            // seit iCalcreator 2.41.x liefert getComponent() Subkomponenten nur noch
            // der Reihe nach; STANDARD/DAYLIGHT daher gezielt über getComponents() holen
            $Standard = $vTimezone->getComponents(IcalInterface::STANDARD)[0] ?? false;

            if ($Standard === false) {
                $this->logError(sprintf('Uncomplete vtimezone: %s', $vTimezone->getTzid()));
                continue;
            }

            if (!($Standard instanceof Kigkonsult\Icalcreator\Standard)) {
                throw new RuntimeException('Component is not of type Standard');
            }

            $ProvidedTZ         = [];
            $ProvidedTZ['TZID'] = $vTimezone->getTzid();

            $Daylight = $vTimezone->getComponents(IcalInterface::DAYLIGHT)[0] ?? false;
            if ($Daylight) {
                if (!($Daylight instanceof Kigkonsult\Icalcreator\Daylight)) {
                    throw new RuntimeException('Component is not of type Daylight');
                }
                if ($Daylight->getRrule()) {
                    $ProvidedTZ['DAYLIGHT_RRULE'] = $Daylight->getRrule();
                }
            }

            if ($Standard->getRrule()) {
                $ProvidedTZ['STANDARD_RRULE'] = $Standard->getRrule();
            }
            $ProvidedTZ['TZOFFSETTO']   = $Standard->getTzoffsetto();
            $ProvidedTZ['TZOFFSETFROM'] = $Standard->getTzoffsetfrom();

            $this->logDebug(__FUNCTION__, 'ProvidedTZ: ' . print_r($ProvidedTZ, true));
            $this->CalendarTimezones[] = $ProvidedTZ;
        }
    }

    /*
        alle VEVENTs des Kalenders einsammeln und klassifizieren;
        liefert [Einzeltermine, Serientermine (RRULE), geänderte Serienelemente (RECURRENCE-ID)]
    */
    private function classifyVevents(Kigkonsult\Icalcreator\Vcalendar $vCalendar, DateTime $CacheDateTimeUntil): array
    {
        $vEvents                    = [];
        $vEvents_with_RRULE         = [];
        $vEvents_with_Recurrence_id = [];

        while (($vEvent = $vCalendar->getComponent(IcalInterface::VEVENT)) !== false) {
            if (!($vEvent instanceof Kigkonsult\Icalcreator\Vevent)) {
                throw new RuntimeException('Component is not of type vevent');
            }

            $propDtstart = $vEvent->getDtstart(true); // incl. params

            if ($propDtstart === false) {
                $this->logError(
                    sprintf(
                        'Event \'%s\': DTSTART can\'t be processed, ignoring',
                        $vEvent->getSummary()
                    )
                );
                continue;
            }

            $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart, $this->isAllDayEvent($vEvent));

            if ($dtStartingTime->getTimestamp() > $CacheDateTimeUntil->getTimestamp()) {
                // event is too far in the future, ignore
                $this->logDebug(
                    __FUNCTION__,
                    sprintf(
                        'Event \'%s\' (%s) is too far in the future, ignoring',
                        $vEvent->getSummary(),
                        $dtStartingTime->format('Y-m-d H:i:s')
                    )
                );

                continue;
            }

            if ($vEvent->getRrule()) {
                $vEvents_with_RRULE[] = $vEvent;
            } elseif ($vEvent->getRecurrenceid()) {
                $vEvents_with_Recurrence_id[] = $vEvent;
            } else {
                $vEvents[] = $vEvent;
            }
        }

        $this->logDebug(
            __FUNCTION__,
            sprintf(
                'vEvents: %s, vEvents_with_RRULE: %s, vEvents_with_Recurrence_id: %s',
                count($vEvents),
                count($vEvents_with_RRULE),
                count($vEvents_with_Recurrence_id)
            )
        );

        return [$vEvents, $vEvents_with_RRULE, $vEvents_with_Recurrence_id];
    }

    /*
        DTSTART/DTEND/DURATION eines Events ermitteln
        $durationSpecform = true: getDuration liefert das bereits berechnete Enddatum
    */
    private function getEventTimes(Kigkonsult\Icalcreator\Vevent $vEvent, bool $durationSpecform, string $logTag): array
    {
        $dtStartingTime = $this->getDateTime($vEvent->getDtstart(true));
        if ($vEvent->getDtend(true) === false) {
            $dtEndingTime = false;
        } else {
            $dtEndingTime = $this->getDateTime($vEvent->getDtend(true));
        }
        $dtDuration = $vEvent->getDuration(false, $durationSpecform);

        $this->logDebug(
            __FUNCTION__,
            sprintf(
                '%s dtStartingTime: %s, dtEndingTime: %s, dtDuration: %s',
                $logTag,
                json_encode($dtStartingTime, JSON_THROW_ON_ERROR),
                json_encode($dtEndingTime, JSON_THROW_ON_ERROR),
                json_encode($dtDuration, JSON_THROW_ON_ERROR)
            )
        );

        return [$dtStartingTime, $dtEndingTime, $dtDuration];
    }

    /*
        einen Einzeltermin in einen Event-Eintrag umsetzen
    */
    private function processSingleEvent(Kigkonsult\Icalcreator\Vevent $vEvent): array
    {
        [$dtStartingTime, $dtEndingTime, $dtDuration] = $this->getEventTimes($vEvent, true, '#Event#');

        $tsStartingTime = $dtStartingTime->getTimestamp();

        if ($dtDuration !== false) {
            $tsEndingTime = $dtDuration->getTimestamp();
        } elseif ($dtEndingTime === false) {
            $tsEndingTime = $tsStartingTime;
        } else {
            $tsEndingTime = $dtEndingTime->getTimestamp();
        }

        return $this->GetEventAttributes($vEvent, $tsStartingTime, $tsEndingTime);
    }

    /*
        einen Serientermin (RRULE) in seine Vorkommen innerhalb des Cache-Fensters auflösen;
        EXDATEs werden ausgelassen, per RECURRENCE-ID geänderte Vorkommen ersetzt
    */
    private function processRecurringEvent(
        Kigkonsult\Icalcreator\Vevent $vEvent,
        array $vEvents_with_Recurrence_id,
        DateTime $CacheDateTimeFrom,
        DateTime $CacheDateTimeUntil
    ): array {
        [$dtStartingTime, $dtEndingTime, $dtDuration] = $this->getEventTimes($vEvent, false, '#Event_RRULE#');

        $RRule = $this->buildRRule($vEvent, $dtStartingTime);
        if ($RRule === null) {
            return [];
        }

        $dtExDates = $this->getExDates($vEvent);
        $this->logDebug(__FUNCTION__, sprintf('dtExDates: %s', json_encode($dtExDates, JSON_THROW_ON_ERROR)));

        $events       = [];
        $dtOccurences = $RRule->getOccurrencesBetween($CacheDateTimeFrom, $CacheDateTimeUntil);
        $this->logDebug(__FUNCTION__, sprintf('dtOccurrences: %s', json_encode($dtOccurences, JSON_THROW_ON_ERROR)));

        foreach ($dtOccurences as $dtOccurrence) {
            if (!($dtOccurrence instanceof DateTime)) {
                throw new RuntimeException('Component is not of type DateTime');
            }

            //check if the occurrence was deleted
            $this->logDebug(__FUNCTION__, sprintf('dtOccurrence: %s', json_encode($dtOccurrence, JSON_THROW_ON_ERROR)));
            if (in_array($dtOccurrence, $dtExDates, false)) { //compare the content, not the instance
                $this->logDebug(__FUNCTION__, 'excluded');
                continue;
            }

            //check if the occurrence was changed
            $changedEvent = $this->getChangedEvent($vEvents_with_Recurrence_id, (string)$vEvent->getUid(), $dtOccurrence);
            if ($changedEvent) {
                // Hinweis: Start-/Endzeit des geänderten Vorkommens gelten ab hier auch für die
                // Dauerberechnung der nachfolgenden Vorkommen (bisheriges Verhalten beibehalten)
                $dtStartingTime = $changedEvent->getDtstart();
                $dtEndingTime   = $changedEvent->getDtend();
                $events[]       = $this->GetEventAttributes(
                    $changedEvent,
                    $dtStartingTime->getTimestamp(),
                    $dtEndingTime->getTimestamp()
                );
            } else {
                if ($dtDuration !== false) {
                    $tsTo = ((clone $dtOccurrence)->add($dtDuration))->getTimestamp();
                } else {
                    $tsTo = $dtOccurrence->getTimestamp() + ($dtEndingTime->getTimestamp() - $dtStartingTime->getTimestamp());
                }
                $events[] = $this->GetEventAttributes($vEvent, $dtOccurrence->getTimestamp(), $tsTo);
            }
        }

        return $events;
    }

    /*
        die RRULE eines Events in ein RRule-Objekt umsetzen; null bei fehlender/ungültiger Regel
    */
    private function buildRRule(Kigkonsult\Icalcreator\Vevent $vEvent, DateTime $dtStartingTime): ?RRule
    {
        $CalRRule = $vEvent->getRrule();
        if (!$CalRRule) {
            $this->logDebug(__FUNCTION__, '$RRule not set!');
            return null;
        }

        if (array_key_exists('UNTIL', $CalRRule)) {
            // replace iCal date array with datetime object
            $CalRRule['UNTIL'] = $this->iCalDateTimeArrayToDateTime(['value' => $CalRRule['UNTIL']], false);
        }

        // replace/set iCal date array with datetime object
        $CalRRule['DTSTART'] = $dtStartingTime;

        // the "BYDAY" element needs to be string. If not, lift it up
        if (array_key_exists('BYDAY', $CalRRule)) {
            foreach ($CalRRule['BYDAY'] as &$day) {
                if (is_array($day) && array_key_exists('DAY', $day)) {
                    $day = implode('', $day);
                }
            }
            unset($day);

            $CalRRule['BYDAY'] = implode(',', $CalRRule['BYDAY']);
        }

        $this->logDebug(
            __FUNCTION__,
            sprintf(
                'CalRRule \'%s\': %s',
                $vEvent->getSummary(),
                json_encode($CalRRule, JSON_THROW_ON_ERROR)
            )
        );

        try {
            return new RRule($CalRRule);
        } catch (Exception $e) {
            $this->logError(
                sprintf('Error \'%s\' in CalRRule \'%s\': %s', $e->getMessage(), $vEvent->getSummary(), print_r($CalRRule, true))
            );
            return null;
        }
    }

    /*
        die EXDATEs eines Events als DateTime-Liste ermitteln
    */
    private function getExDates(Kigkonsult\Icalcreator\Vevent $vEvent): array
    {
        $dtExDates = [];
        while (false !== ($exDates = $vEvent->getExdate(null, true))) {
            foreach ($exDates['value'] as $exDateValue) {
                $dtExDates[] = $this->iCalDateTimeArrayToDateTime(
                    ['value' => $exDateValue, 'params' => $exDates['params']],
                    $this->isAllDayEvent($vEvent)
                );
            }
        }
        return $dtExDates;
    }

    private function isEventOutsideCachedTime(array $event, DateTime $cacheDateTimeFrom, DateTime $cacheDateTimeUntil): bool
    {
        $isBeforeCacheTime   = $event['To'] < $cacheDateTimeFrom->getTimestamp();
        $isAfterCacheTime    = $event['From'] > $cacheDateTimeUntil->getTimestamp();
        $isSameTimeAndAllDay = $event['allDay']
                               && ($event['To'] === $cacheDateTimeFrom->getTimestamp() || $event['From'] === $cacheDateTimeUntil->getTimestamp());

        return $isBeforeCacheTime || $isAfterCacheTime || $isSameTimeAndAllDay;
    }

    private function getDateTime(Pc|array $dateTimeWithParams): DateTime
    {
        $params = $dateTimeWithParams['params'];
        if ((isset($params['VALUE']) && $params['VALUE'] === 'DATE') || (isset($params['ISLOCALTIME']) && ($params['ISLOCALTIME']))) {
            return new DateTime($dateTimeWithParams['value']->format('Y-m-d H:i:s'));
        }

        return $dateTimeWithParams['value'];
    }

    private function getChangedEvent(array $vEvents_with_Recurrence_id, string $uid, DateTime $dtOccurrence): ?Kigkonsult\Icalcreator\Vevent
    {
        foreach ($vEvents_with_Recurrence_id as $vEvent) {
            if (!($vEvent instanceof Kigkonsult\Icalcreator\Vevent)) {
                throw new RuntimeException('Component is not of type vevent');
            }

            if ($vEvent->getUid() === $uid) {
                $dtFound = $this->iCalDateTimeArrayToDateTime($vEvent->getRecurrenceid(true), $this->isAllDayEvent($vEvent));
                if ($dtOccurrence == $dtFound) {
                    $this->logDebug(__FUNCTION__, sprintf('ChangedEvent found: %s', $dtOccurrence->getTimestamp()));
                    return $vEvent;
                }
            }
        }
        return null;
    }

    private function GetEventAttributes(Kigkonsult\Icalcreator\Vevent $vEvent, int $tsFrom, int $tsTo): array
    {
        $Event                 = [];
        $Event['UID']          = (string)$vEvent->getUid();
        $Event['Name']         = $vEvent->getSummary()     ?: '';
        $Event['Status']       = $vEvent->getStatus()      ?: '';
        $Event['Location']     = $vEvent->getLocation()    ?: '';
        $Event['Description']  = $vEvent->getDescription() ?: '';
        $Event['Categories'] = $vEvent->getCategories() ?: '';
        $Event['From']       = $tsFrom;
        $Event['To']         = $tsTo;
        $Event['FromS']      = date(DATE_ATOM, $tsFrom);
        $Event['ToS']        = date(DATE_ATOM, $tsTo);
        $Event['allDay']     = $this->isAllDayEvent($vEvent);
        $Event['Alarms']     = [];

        while ($vAlarm = $vEvent->getComponent(IcalInterface::VALARM)) {
            if (!($vAlarm instanceof Kigkonsult\Icalcreator\Valarm)) {
                throw new RuntimeException(sprintf('UID: %s, Component is not of type valarm', $Event['UID']));
            }
            $trigger = $vAlarm->getTrigger();

            $Event['Alarms'][] = match (true) {
                $trigger instanceof DateInterval =>
                    (new DateTimeImmutable())->setTimestamp($tsFrom)->add($trigger)->getTimestamp() - $tsFrom,

                $trigger instanceof DateTimeInterface =>
                    $trigger->getTimestamp() - $tsFrom,

                $trigger === false => 0,

                default => throw new RuntimeException(sprintf(
                                                          'UID: %s, Unknown trigger type: %s',
                                                          $Event['UID'],
                                                          get_debug_type($trigger)
                                                      )),
            };
        }
        $this->logDebug(
            __FUNCTION__,
            sprintf('Event: %s', json_encode($Event, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR))
        );

        return $Event;
    }

    private function isAllDayEvent(Kigkonsult\Icalcreator\Vevent $vEvent): bool
    {
        $propDtstart = $vEvent->getDtstart(true); // incl. params
        $propDtend   = $vEvent->getDtend(true); // incl. params

        if ($propDtstart) {
            if (isset($propDtstart['params']['VALUE']) && ($propDtstart['params']['VALUE'] === 'DATE')) {
                return true;
            }
            if ($propDtend && ($propDtstart['value']->format('H:i:s') === '00:00:00') && ($propDtend['value']->format('H:i:s') === '00:00:00')) {
                return true;
            }
        }
        return false;
    }

    private function logDebug(string $method, string $message): void
    {
        call_user_func($this->Logger_Dbg, $method, $message);
    }

    private function logError(string $message): void
    {
        call_user_func($this->Logger_Err, $message);
    }

}
