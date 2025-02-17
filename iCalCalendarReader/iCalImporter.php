<?php

declare(strict_types=1);

use Kigkonsult\Icalcreator\Util\RegulateTimezoneFactory;
use RRule\RRule;

/***********************************************************************
 * iCal importer class
 ************************************************************************/
class iCalImporter
{
    private string $Timezone;

    private int    $DaysToCacheAhead;

    private int    $DaysToCacheBack;

    private array  $CalendarTimezones;

    private        $Logger_Dbg;

    private        $Logger_Err;

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
    private function iCalDateTimeArrayToDateTime(array $dtValue, bool $WholeDay): DateTime
    {
        //call_user_func($this->Logger_Dbg, __FUNCTION__, sprintf('dtValue: %s, WholeDay: %s', print_r($dtValue, true), (int) $WholeDay));

        if (!($dtValue['value'] instanceof DateTime)) {
            throw new RuntimeException('Component is not of type DateTime');
        }

        $value = $dtValue['value'];
        if (isset($dtValue['params'])) {
            $params = $dtValue['params'];
        }

        $Year  = (int)$value->format('Y');
        $Month = (int)$value->format('n');
        $Day   = (int)$value->format('j');
        $Hour  = (int)$value->format('G');
        $Min   = (int)$value->format('i');
        $Sec   = (int)$value->format('s');

        // owncloud calendar
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
                call_user_func($this->Logger_Err, sprintf('"%s" is no Standard Timezone', $TimezoneName));
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
            // convert to local timezone
            $DateTime->setTimezone(new DateTimeZone($this->Timezone));
        }
        return $DateTime;
    }

    /*
        basic setup
    */
    public function __construct(int $DaysToCacheBack, int $DaysToCacheAhead, callable $Logger_Dbg, callable $Logger_Err)
    {
        $this->Timezone         = date_default_timezone_get();
        $this->DaysToCacheAhead = $DaysToCacheAhead;
        $this->DaysToCacheBack  = $DaysToCacheBack;
        $this->Logger_Dbg       = $Logger_Dbg;
        $this->Logger_Err       = $Logger_Err;
    }

    /*
        main import method
    */
    public function ImportCalendar(string $iCalData): array
    {
        // see Internet Calendaring and Scheduling Core Object Specification https://tools.ietf.org/html/rfc5545

        $iCalCalendarArray       = [];
        $this->CalendarTimezones = [];

        $rTZFactory = RegulateTimezoneFactory::factory($iCalData);
        //var_dump($rTZFactory);
        $rTZFactory = $rTZFactory->addOtherTzPhpRelation('(UTC+01:00) Amsterdam, Berlin, Bern, Rom, Stockholm, Wien', 'Europe/Amsterdam', true);

        $stringCalendarToParse = $rTZFactory->processCalendar()->getOutputiCal();

        try {
            $vCalendar = new Kigkonsult\Icalcreator\Vcalendar();
            $vCalendar->parse($stringCalendarToParse);
            //$vCalendar->parse($iCalData);
        } catch (Exception $e) {
            call_user_func($this->Logger_Err, 'parse: ' . $e->getMessage());
            return [];
        }

        // get calendar supplied timezones
        while ($vTimezone = $vCalendar->getComponent('vtimezone')) {
            if (!($vTimezone instanceof Kigkonsult\Icalcreator\Vtimezone)) {
                throw new RuntimeException('Component is not of type Vtimezone');
            }

            $Standard = $vTimezone->getComponent('STANDARD');

            if ($Standard === false) {
                call_user_func(
                    $this->Logger_Err,
                    sprintf(
                        'Uncomplete vtimezone: %s, STANDARD: %s',
                        $vTimezone->getTzid(),
                        json_encode($Standard, JSON_THROW_ON_ERROR)
                    )
                );
                continue;
            }

            if (!($Standard instanceof Kigkonsult\Icalcreator\Standard)) {
                throw new RuntimeException('Component is not of type Standard');
            }

            $ProvidedTZ         = [];
            $ProvidedTZ['TZID'] = $vTimezone->getTzid();

            $Daylight = $vTimezone->getComponent('DAYLIGHT');
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
            $ProvidedTZ['TZOFFSETTO']   = $Standard->getTzoffsetto(); //todo
            $ProvidedTZ['TZOFFSETFROM'] = $Standard->getTzoffsetfrom(); //todo

            call_user_func($this->Logger_Dbg, __FUNCTION__, 'ProvidedTZ: ' . print_r($ProvidedTZ, true));
            $this->CalendarTimezones[] = $ProvidedTZ;
        }

        //get different kind of events
        $vEvents                    = [];
        $vEvents_with_RRULE         = [];
        $vEvents_with_Recurrence_id = [];

        $CacheDateTimeFrom  = (new DateTime('today'))->sub(new DateInterval('P' . $this->DaysToCacheBack . 'D')); //P='Period', D='Days'
        $CacheDateTimeUntil = (new DateTime('today'))->add(new DateInterval('P' . ($this->DaysToCacheAhead + 1) . 'D'));
        call_user_func(
            $this->Logger_Dbg,
            __FUNCTION__,
            sprintf(
                'cached time: (DaysToCacheBack: %s, DaysToCache: %s, %s - %s)',
                $this->DaysToCacheBack,
                $this->DaysToCacheAhead,
                $CacheDateTimeFrom->format('Y-m-d H:i:s'),
                $CacheDateTimeUntil->format('Y-m-d H:i:s')
            )
        );

        while (($vEvent = $vCalendar->getComponent('vevent')) !== false) {
            if (!($vEvent instanceof Kigkonsult\Icalcreator\Vevent)) {
                throw new RuntimeException('Component is not of type vevent');
            }

            $propDtstart = $vEvent->getDtstart(true); // incl. params

            if ($propDtstart === false) {
                call_user_func(
                    $this->Logger_Err,
                    sprintf(
                        'Event \'%s\': DTSTART can\'t be processed, ignoring',
                        $vEvent->getSummary()
                    ) //todo
                );
                continue;
            }

            $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart, $this->isAllDayEvent($vEvent));

            if ($dtStartingTime->getTimestamp() > $CacheDateTimeUntil->getTimestamp()) {
                // event is too far in the future, ignore
                call_user_func(
                    $this->Logger_Dbg,
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

        call_user_func(
            $this->Logger_Dbg,
            __FUNCTION__,
            sprintf(
                'vEvents: %s, vEvents_with_RRULE: %s, vEvents_with_Recurrence_id: %s',
                count($vEvents),
                count($vEvents_with_RRULE),
                count($vEvents_with_Recurrence_id)
            )
        );

        $eventArray = [];

        foreach ($vEvents as $vEvent) {
            if (!($vEvent instanceof Kigkonsult\Icalcreator\Vevent)) {
                throw new RuntimeException('Component is not of type vevent');
            }

            $dtStartingTime = $this->getDateTime($vEvent->getDtstart(true));
            if ($vEvent->getDtend(true) === false) {
                $dtEndingTime = false;
            } else {
                $dtEndingTime = $this->getDateTime($vEvent->getDtend(true));
            }
            $dtDuration = $vEvent->getDuration(false, true); //specform: the end date is already calculated


            call_user_func(
                $this->Logger_Dbg,
                __FUNCTION__,
                sprintf(
                    '#Event# dtStartingTime: %s, dtEndingTime: %s, dtDuration: %s',
                    json_encode($dtStartingTime, JSON_THROW_ON_ERROR),
                    json_encode($dtEndingTime, JSON_THROW_ON_ERROR),
                    json_encode($dtDuration, JSON_THROW_ON_ERROR)
                )
            );

            $tsStartingTime = $dtStartingTime->getTimestamp();

            if ($dtDuration !== false) {
                $tsEndingTime = $dtDuration->getTimestamp();
            } elseif ($dtEndingTime === false) {
                $tsEndingTime = $tsStartingTime;
            } else {
                $tsEndingTime = $dtEndingTime->getTimestamp();
            }

            $eventArray[] = $this->GetEventAttributes($vEvent, $tsStartingTime, $tsEndingTime);
        }

        foreach ($vEvents_with_RRULE as $vEvent) {
            if (!($vEvent instanceof Kigkonsult\Icalcreator\Vevent)) {
                throw new RuntimeException('Component is not of type vevent');
            }

            $dtStartingTime = $this->getDateTime($vEvent->getDtstart(true));
            if ($vEvent->getDtend(true) === false) {
                $dtEndingTime = false;
            } else {
                $dtEndingTime = $this->getDateTime($vEvent->getDtend(true));
            }
            $dtDuration = $vEvent->getDuration(false, false);

            call_user_func(
                $this->Logger_Dbg,
                __FUNCTION__,
                sprintf(
                    '#Event_RRULE# dtStartingTime: %s, dtEndingTime: %s, dtDuration: %s',
                    json_encode($dtStartingTime, JSON_THROW_ON_ERROR),
                    json_encode($dtEndingTime, JSON_THROW_ON_ERROR),
                    json_encode($dtDuration, JSON_THROW_ON_ERROR)
                )
            );


            $CalRRule = $vEvent->getRrule();
            if ($CalRRule) {
                if (array_key_exists('UNTIL', $CalRRule)) {
                    $UntilDateTime = $this->iCalDateTimeArrayToDateTime(['value' => $CalRRule['UNTIL']], false);
                    // replace iCal date array with datetime object
                    $CalRRule['UNTIL'] = $UntilDateTime;
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

                call_user_func(
                    $this->Logger_Dbg,
                    __FUNCTION__,
                    sprintf(
                        'CalRRule \'%s\': %s',
                        $vEvent->getSummary(),
                        json_encode($CalRRule, JSON_THROW_ON_ERROR)
                    )
                );

                try {
                    $RRule = new RRule($CalRRule);
                } catch (Exception $e) {
                    call_user_func(
                        $this->Logger_Err,
                        sprintf('Error \'%s\' in CalRRule \'%s\': %s', $e->getMessage(), $vEvent->getSummary(), print_r($CalRRule, true))
                    );
                    continue;
                }
            } else {
                call_user_func($this->Logger_Dbg, __FUNCTION__, '$RRule not set!');
            }

            if (!isset($RRule)) {
                call_user_func($this->Logger_Dbg, __FUNCTION__, '$RRule not set!');
                continue;
            }

            //get the EXDATES
            $dtExDates = [];
            while (false !== ($exDates = $vEvent->getExdate(null, true))) {
                foreach ($exDates['value'] as $exDateValue) {
                    $dtExDates[] =
                        $this->iCalDateTimeArrayToDateTime(['value' => $exDateValue, 'params' => $exDates['params']], $this->isAllDayEvent($vEvent));
                }
            }
            call_user_func($this->Logger_Dbg, __FUNCTION__, sprintf('dtExDates: %s', json_encode($dtExDates, JSON_THROW_ON_ERROR)));

            //get the occurrences
            $dtOccurences = $RRule->getOccurrencesBetween($CacheDateTimeFrom, $CacheDateTimeUntil);
            call_user_func($this->Logger_Dbg, __FUNCTION__, sprintf('dtOccurrences: %s', json_encode($dtOccurences, JSON_THROW_ON_ERROR)));
            foreach ($dtOccurences as $dtOccurrence) {
                if (!($dtOccurrence instanceof DateTime)) {
                    throw new RuntimeException('Component is not of type DateTime');
                }

                //check if occurrence was deleted
                call_user_func($this->Logger_Dbg, __FUNCTION__, sprintf('dtOccurrence: %s', json_encode($dtOccurrence, JSON_THROW_ON_ERROR)));
                if (in_array($dtOccurrence, $dtExDates, false)) { //compare the content, not the instance
                    call_user_func($this->Logger_Dbg, __FUNCTION__, 'excluded');
                    continue;
                }

                //check if occurrence was changed
                $changedEvent = $this->getChangedEvent($vEvents_with_Recurrence_id, (string)$vEvent->getUid(), $dtOccurrence);
                if ($changedEvent) {
                    $dtStartingTime = $changedEvent->getDtstart();
                    $dtEndingTime   = $changedEvent->getDtend();
                    $eventArray[]   = $this->GetEventAttributes(
                        $changedEvent,
                        ($changedEvent->getDtstart())->getTimestamp(),
                        ($changedEvent->getDtend())->getTimestamp()
                    );
                } else {
                    if ($dtDuration !== false) {
                        $tsTo = ((clone $dtOccurrence)->add($dtDuration))->getTimestamp();
                    } else {
                        $tsTo = $dtOccurrence->getTimestamp() + ($dtEndingTime->getTimestamp() - $dtStartingTime->getTimestamp());
                    }
                    $eventArray[] = $this->GetEventAttributes($vEvent, $dtOccurrence->getTimestamp(), $tsTo);
                }
            }
        }

        foreach ($eventArray as $event) {
            if ($this->isEventOutsideCachedTime($event, $CacheDateTimeFrom, $CacheDateTimeUntil)) {
                call_user_func(
                    $this->Logger_Dbg,
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

    private function isEventOutsideCachedTime(array $event, DateTime $cacheDateTimeFrom, DateTime $cacheDateTimeUntil): bool
    {
        $isBeforeCacheTime   = $event['To'] < $cacheDateTimeFrom->getTimestamp();
        $isAfterCacheTime    = $event['From'] > $cacheDateTimeUntil->getTimestamp();
        $isSameTimeAndAllDay = $event['allDay']
                               && ($event['To'] === $cacheDateTimeFrom->getTimestamp() || $event['From'] === $cacheDateTimeUntil->getTimestamp());

        return $isBeforeCacheTime || $isAfterCacheTime || $isSameTimeAndAllDay;
    }

    private function getDateTime(array $dateTimeWithParams): DateTime
    {
        //var_dump($dateTimeWithParams);
        $params = $dateTimeWithParams['params'];
        if ((isset($params['VALUE']) && $params['VALUE'] === 'DATE') || (isset($params['ISLOCALTIME']) && ($params['ISLOCALTIME']))) {
            //var_dump($dateTimeWithParams['value']->format('Y-m-d H:i:s'));
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
                    call_user_func($this->Logger_Dbg, __FUNCTION__, sprintf('ChangedEvent found: %s', $dtOccurrence->getTimestamp()));
                    return $vEvent;
                }
            }
        }
        return null;
    }

    private function GetEventAttributes(Kigkonsult\Icalcreator\Vevent $vEvent, int $tsFrom, int $tsTo): array
    {
        $Event         = [];
        $Event['UID']  = (string)$vEvent->getUid();
        $Event['Name'] = $vEvent->getSummary();
        $status        = $vEvent->getStatus();
        if ($status) {
            $Event['Status'] = $vEvent->getStatus();
        } else {
            $Event['Status'] = '';
        }

        $location = $vEvent->getLocation();
        if ($location) {
            $Event['Location'] = $location;
        } else {
            $Event['Location'] = '';
        }

        $description = $vEvent->getDescription();
        if ($description) {
            $Event['Description'] = $description;
        } else {
            $Event['Description'] = '';
        }
        $Event['Categories'] = $vEvent->getCategories();
        $Event['From']       = $tsFrom;
        $Event['To']         = $tsTo;
        $Event['FromS']      = date(DATE_ATOM, $tsFrom);
        $Event['ToS']        = date(DATE_ATOM, $tsTo);
        $Event['allDay']     = $this->isAllDayEvent($vEvent);
        $Event['Alarms']     = [];

        while ($vAlarm = $vEvent->getComponent('valarm')) {
            //$vAlarm = $vEvent->getComponent('valarm');
            if (!($vAlarm instanceof Kigkonsult\Icalcreator\Valarm)) {
                throw new RuntimeException(sprintf('UID: %s, Component is not of type valarm', $Event['UID']));
            }
            $trigger = $vAlarm->getTrigger();
            if ($trigger !== false) {
                if ($trigger instanceof DateInterval) {
                    $reference         = new DateTimeImmutable('@' . $tsFrom);
                    $totalSeconds      = $reference->add($trigger)->getTimestamp() - $tsFrom;
                    $Event['Alarms'][] = $totalSeconds;
                } elseif ($trigger instanceof DateTime) {
                    $Event['Alarms'][] = $trigger->getTimestamp() - $tsFrom;
                } else {
                    var_dump($trigger);
                    throw new RuntimeException(sprintf('UID: %s, Unknown trigger type', $Event['UID']));
                }
            } else {
                $Event['Alarms'][] = 0;
            }
        }
        call_user_func(
            $this->Logger_Dbg,
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
            if ($propDtend && ($propDtend['value']->format('H:i:s') === '00:00:00') && ($propDtend['value']->format('H:i:s') === '00:00:00')) {
                return true;
            }
        }
        return false;
    }
}
