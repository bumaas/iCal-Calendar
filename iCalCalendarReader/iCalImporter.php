<?php

use RRule\RRule;


/***********************************************************************
 * iCal importer class
 ************************************************************************/
class iCalImporter
{

    private $Timezone;

    private $DaysToCacheAhead, $DaysToCacheBack;

    private $CalendarTimezones;

    private $Logger_Dbg;

    private $Logger_Err;


    /*
        convert the timezone RRULE to a datetime object in the given/current year
    */
    private function TZRRuleToDateTime($RRule, $Year = '')
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
                    switch ($Day) // RFC5545
                    {
                        case 'MO':
                            $DayName = 'Monday';
                            break;
                        case 'TU':
                            $DayName = 'Tuesday';
                            break;
                        case 'WE':
                            $DayName = 'Wednesday';
                            break;
                        case 'TH':
                            $DayName = 'Thursday';
                            break;
                        case 'FR':
                            $DayName = 'Friday';
                            break;
                        case 'SA':
                            $DayName = 'Saturday';
                            break;
                        case 'SU':
                        default:
                            $DayName = 'Sunday';
                            break;
                    }
                    return date_timestamp_set(new DateTime, strtotime($Occ . ' ' . $DayName . ' ' . $MonthName . ' ' . $Year . '00:00:00'));
                }
            }
        }
        return null;
    }

    /*
        apply the time offset from a timezone provided by the loaded calendar
    */
    private function ApplyCustomTimezoneOffset(DateTime $EventDateTime, DateTime $CustomTimezoneName): DateTime
    {
        // is timezone in calendar provided timezone?
        foreach ($this->CalendarTimezones as $CalendarTimezone) {
            if ($CalendarTimezone['TZID'] === $CustomTimezoneName) {
                $DSTStartDateTime = $this->TZRRuleToDateTime($CalendarTimezone['DAYLIGHT_RRULE'], $EventDateTime->format('Y'));
                $DSTEndDateTime   = $this->TZRRuleToDateTime($CalendarTimezone['STANDARD_RRULE'], $EventDateTime->format('Y'));

                // between these dates?
                if (($EventDateTime > $DSTStartDateTime) && ($EventDateTime < $DSTEndDateTime)) {
                    $EventDateTime->add(DateInterval::createFromDateString(strtotime($CalendarTimezone['TZOFFSETFROM'])));
                } else {
                    $EventDateTime->add(DateInterval::createFromDateString(strtotime($CalendarTimezone['TZOFFSETTO'])));
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
    private function iCalDateTimeArrayToDateTime(array $dtValue): DateTime
    {

        $value = $dtValue['value'];
        if (isset($dtValue['params'])) {
            $params = $dtValue['params'];
        }
        // whole-day, this is not timezone relevant!
        /** @noinspection PhpUndefinedVariableInspection */
        $WholeDay = (isset($params['VALUE']) && ($params['VALUE'] === 'DATE'));

        $Year  = (int) $value['year'];
        $Month = (int) $value['month'];
        $Day   = (int) $value['day'];

        if (isset($value['hour'])) {
            $Hour = (int) $value['hour'];
        } else {
            $Hour = 0;
        }
        if (isset($value['min'])) {
            $Min = (int) $value['min'];
        } else {
            $Min = 0;
        }
        if (isset($value['sec'])) {
            $Sec = (int) $value['sec'];
        } else {
            $Sec = 0;
        }
        // owncloud calendar
        if (isset($params['TZID'])) {
            /** @noinspection PhpUndefinedVariableInspection */
            $Timezone = $params['TZID'];
        } // google calendar
        elseif (isset($value['tz'])) {
            $Timezone = 'UTC';
        } else {
            $Timezone = $this->Timezone;
        }

        $DateTime = new DateTime();

        if ($WholeDay) {
            $DateTime->setTimezone(timezone_open($this->Timezone));
            $DateTime->setDate($Year, $Month, $Day);
            $DateTime->setTime($Hour, $Min, $Sec);
        } else {
            $IsStandardTimezone = true;
            $SetTZResult        = @$DateTime->setTimezone(timezone_open($Timezone));
            if (!$SetTZResult) {
                trigger_error('No Standard Timezone');
                // no standard timezone, set to UTC first
                $DateTime->setTimezone(timezone_open('UTC'));
                $IsStandardTimezone = false;
            }
            $DateTime->setDate($Year, $Month, $Day);
            $DateTime->setTime($Hour, $Min, $Sec);
            if (!$IsStandardTimezone) {
                // set UTC offset if provided in calendar data
                $DateTime = $this->ApplyCustomTimezoneOffset($DateTime, $Timezone);
            }
            // convert to local timezone
            $DateTime->setTimezone(timezone_open($this->Timezone));
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

        try {
            $vCalendar = new Kigkonsult\Icalcreator\Vcalendar();
            $vCalendar->parse($iCalData);
        }
        catch(Exception $e) {
            call_user_func($this->Logger_Err, $e->getMessage());
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
                    $this->Logger_Err, sprintf(
                                         'Uncomplete vtimezone: %s, STANDARD: %s', $vTimezone->getTzid(), json_encode($Standard)
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

        $CacheDateTimeFrom  = (new DateTime('today'))->sub(new DateInterval('P' . $this->DaysToCacheBack . 'D'));
        $CacheDateTimeUntil = (new DateTime('today'))->add(new DateInterval('P' . ($this->DaysToCacheAhead + 1) . 'D'));
        call_user_func(
            $this->Logger_Dbg, __FUNCTION__, sprintf(
                                 'cached time: (DaysToCacheBack: %s, DaysToCache: %s, %s - %s)', $this->DaysToCacheBack, $this->DaysToCacheAhead,
                                 $CacheDateTimeFrom->format('Y-m-d H:i:s'), $CacheDateTimeUntil->format('Y-m-d H:i:s')
                             )
        );

        while ($vEvent = $vCalendar->getComponent('vevent')) {

            if (!($vEvent instanceof Kigkonsult\Icalcreator\Vevent)) {
                throw new RuntimeException('Component is not of type vevent');
            }

            $propDtstart = $vEvent->getDtstart(true); // incl. params

            if ($propDtstart === false) {
                call_user_func(
                    $this->Logger_Err, sprintf(
                    'Event \'%s\': DTSTART can\'t be processed, ignoring', $vEvent->getSummary()
                ) //todo
                );
                continue;
            }
            $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart);

            if ($dtStartingTime->getTimestamp() > $CacheDateTimeUntil->getTimestamp()) {
                // event is too far in the future, ignore
                call_user_func(
                    $this->Logger_Dbg, __FUNCTION__, sprintf(
                                         'Event \'%s\' (%s) is too far in the future, ignoring', $vEvent->getSummary(),
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


        $eventArray = [];

        foreach ($vEvents as $vEvent) {

            if (!($vEvent instanceof Kigkonsult\Icalcreator\Vevent)) {
                throw new RuntimeException('Component is not of type vevent');
            }

            $propDtstart = $vEvent->getDtstart(true); // incl. params
            $propDtend   = $vEvent->getDtend(true);   // incl. params
            if ($propDtend === false) {
                $propDtend = $propDtstart;
            }

            call_user_func(
                $this->Logger_Dbg, __FUNCTION__, sprintf('dtStartingTime %s, dtEndingTime%s', json_encode($propDtstart), json_encode($propDtend))
            );

            $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart);
            $dtEndingTime   = $this->iCalDateTimeArrayToDateTime($propDtend);

            $tsStartingTime = date_timestamp_get($dtStartingTime);
            $tsEndingTime   = date_timestamp_get($dtEndingTime);

            $eventArray[] = $this->GetEventAttributes($vEvent, $tsStartingTime, $tsEndingTime);

        }

        foreach ($vEvents_with_RRULE as $vEvent) {

            if (!($vEvent instanceof Kigkonsult\Icalcreator\Vevent)) {
                throw new RuntimeException('Component is not of type vevent');
            }

            $propDtstart = $vEvent->getDtstart(true); // incl. params
            $propDtend   = $vEvent->getDtend(true);   // incl. params


            call_user_func(
                $this->Logger_Dbg, __FUNCTION__, sprintf('dtStartingTime %s, dtEndingTime%s', json_encode($propDtstart), json_encode($propDtend))
            );
            $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart);

            call_user_func($this->Logger_Dbg, __FUNCTION__, sprintf('CalRRule \'%s\': %s', $vEvent->getSummary(), $vEvent->createRrule()));

            $CalRRule = $vEvent->getRrule();
            if ($CalRRule) {
                if (array_key_exists('UNTIL', $CalRRule)) {
                    $UntilDateTime = $this->iCalDateTimeArrayToDateTime(['value' => $CalRRule['UNTIL']]);
                    // replace iCal date array with datetime object
                    $CalRRule['UNTIL'] = $UntilDateTime;
                }
                // replace/set iCal date array with datetime object
                $CalRRule['DTSTART'] = $dtStartingTime;

                // the array underneath "BYDAY" needs to be exactly one level deep. If not, lift it up
                if (array_key_exists('BYDAY', $CalRRule)) {
                    foreach ($CalRRule['BYDAY'] as &$day) {
                        if (is_array($day) && array_key_exists('DAY', $day)) {
                            $day = $day['DAY'];
                        }
                    }
                    unset ($day);
                }

                try {
                    $RRule = new RRule($CalRRule);
                }
                catch(Exception $e) {
                    call_user_func(
                        $this->Logger_Err,
                        sprintf('Error \'%s\' in CalRRule \'%s\': %s', $e->getMessage(), $vEvent->getSummary(), print_r($CalRRule, true))
                    );
                    continue;
                }
            }

            //get the EXDATES
            $dtExDates = [];
            if ($exDates = $vEvent->getExdate(null, true)) {
                foreach ($exDates['value'] as $exDateValue) {
                    $dtExDates[] = $this->iCalDateTimeArrayToDateTime(['value' => $exDateValue, 'params' => $exDates['params']]);
                }
            }


            if (!isset($RRule)){
                continue;
            }

            //get the occurrences
            foreach ($RRule->getOccurrencesBetween($CacheDateTimeFrom, $CacheDateTimeUntil) as $dtOccurrence) {
                if (!($dtOccurrence instanceof DateTime)) {
                    throw new RuntimeException('Component is not of type DateTime');
                }

                //check if occurrence was deleted
                if (in_array($dtOccurrence, $dtExDates, false)) { //compare the content, not the instance
                    continue;
                }

                //check if occurrence was changed
                $changedEvent = $this->getChangedEvent($vEvents_with_Recurrence_id, (string) $vEvent->getUid(), $dtOccurrence);

                if ($changedEvent) {
                    $propDtstart    = $changedEvent->getDtstart(true); // incl. params
                    $propDtend      = $changedEvent->getDtend(true);   // incl. params
                    $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart);
                    $dtEndingTime   = $this->iCalDateTimeArrayToDateTime($propDtend);

                    $eventArray[] = $this->GetEventAttributes($changedEvent, $dtStartingTime->getTimestamp(), $dtEndingTime->getTimestamp());
                }
            }
        }

        foreach ($eventArray as $ThisEvent) {
            if (($ThisEvent['To'] < $CacheDateTimeFrom->getTimestamp()) || ($ThisEvent['From'] > $CacheDateTimeUntil->getTimestamp())) {
                // event not in the cached time, ignore
                call_user_func(
                    $this->Logger_Dbg, __FUNCTION__, sprintf(
                                         'Event \'%s\' (%s - %s) is outside the cached time, ignoring', $ThisEvent['Name'], $ThisEvent['FromS'],
                                         $ThisEvent['ToS']
                                     )
                );
            } else {
                // insert event(s)
                $iCalCalendarArray[] = $ThisEvent;
            }
        }

        // sort by start date/time to make the check on changes work
        usort(
            $iCalCalendarArray, static function($a, $b) {
            return $a['From'] - $b['From'];
        }
        );
        return $iCalCalendarArray;
    }

    private function getChangedEvent(array $vEvents_with_Recurrence_id, string $uid, DateTime $dtOccurrence): ?Kigkonsult\Icalcreator\Vevent
    {
        foreach ($vEvents_with_Recurrence_id as $vEvent) {
            if (!($vEvent instanceof Kigkonsult\Icalcreator\Vevent)) {
                throw new RuntimeException('Component is not of type vevent');
            }

            if ($vEvent->getUid() === $uid) {
                $dtFound = $this->iCalDateTimeArrayToDateTime($vEvent->getRecurrenceid(true));
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
        $Event['UID']  = (string) $vEvent->getUid();
        $Event['Name'] = $vEvent->getSummary();

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

        $Event['From']  = $tsFrom;
        $Event['To']    = $tsTo;
        $Event['FromS'] = date('Y-m-d H:i:s', $tsFrom);
        $Event['ToS']   = date('Y-m-d H:i:s', $tsTo);
        $propDtstart    = $vEvent->getDtstart(true); // incl. params
        if ($propDtstart) {
            $Event['allDay'] = (isset($propDtstart['params']['VALUE']) && ($propDtstart['params']['VALUE'] === 'DATE'));
        }


        call_user_func(
            $this->Logger_Dbg, __FUNCTION__, sprintf('Event: %s', json_encode($Event))
        );

        return $Event;
    }
}
