<?php

declare(strict_types=1);

use Kigkonsult\Icalcreator\Util\RegulateTimezoneFactory;
use RRule\RRule;

/***********************************************************************
 * iCal importer class
 ************************************************************************/
class iCalImporter
{
    private $Timezone;

    private $DaysToCacheAhead;

    private $DaysToCacheBack;

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

        if (!($dtValue['value'] instanceof DateTime)) {
            throw new RuntimeException('Component is not of type DateTime');
        }

        $value = $dtValue['value'];
        if (isset($dtValue['params'])) {
            $params = $dtValue['params'];
        }
        // whole-day, this is not timezone relevant!
        /** @noinspection PhpUndefinedVariableInspection */
        $WholeDay = (isset($params['VALUE']) && ($params['VALUE'] === 'DATE'));

        $Year  = (int) $value->format('Y');
        $Month = (int) $value->format('n');
        $Day   = (int) $value->format('j');
        $Hour  = (int) $value->format('G');
        $Min   = (int) $value->format('i');
        $Sec   = (int) $value->format('s');

        // owncloud calendar
        if (isset($params['TZID'])) {
            /** @noinspection PhpUndefinedVariableInspection */
            $TimezoneName = $params['TZID'];
        } // google calendar
        else {
            $TimezoneName = $value->getTimezone()->getName();
        }

        $DateTime = new DateTime();

        if ($WholeDay) {
            $DateTime->setTimezone(new DateTimeZone($this->Timezone));
            $DateTime->setDate($Year, $Month, $Day);
            $DateTime->setTime($Hour, $Min, $Sec);
        } else {
            $IsStandardTimezone = true;
            try{
                $tz = new DateTimeZone($TimezoneName);
            } catch (Exception $e){
                call_user_func($this->Logger_Err, sprintf('"%s" is no Standard Timezone', $TimezoneName));
                // no standard timezone, set to UTC first
                $tz = new DateTimeZone('UTC');
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
        $rTZFactory = $rTZFactory->addOtherTzPhpRelation('"(UTC+01:00) Amsterdam, Berlin, Bern, Rom, Stockholm, Wien"', 'Europe/Amsterdam');

        $stringCalendarToParse   = $rTZFactory
            ->processCalendar()
            ->getOutputiCal();

        try {
            $vCalendar = new Kigkonsult\Icalcreator\Vcalendar();
            $vCalendar->parse($stringCalendarToParse);
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

        $CacheDateTimeFrom  = (new DateTime('today', new DateTimeZone('UTC')))->sub(new DateInterval('P' . $this->DaysToCacheBack . 'D')); //P='Period', D='Days'
        $CacheDateTimeUntil = (new DateTime('today', new DateTimeZone('UTC')))->add(new DateInterval('P' . ($this->DaysToCacheAhead + 1) . 'D'));
        call_user_func(
            $this->Logger_Dbg, __FUNCTION__, sprintf(
                                 'cached time: (DaysToCacheBack: %s, DaysToCache: %s, %s - %s)', $this->DaysToCacheBack, $this->DaysToCacheAhead,
                                 $CacheDateTimeFrom->format('Y-m-d H:i:s'), $CacheDateTimeUntil->format('Y-m-d H:i:s')
                             )
        );

        while (($vEvent = $vCalendar->getComponent('vevent')) !== false) {

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

        call_user_func(
            $this->Logger_Dbg, __FUNCTION__, sprintf(
                                 'vEvents_with_RRULE: %s, vEvents_with_Recurrence_id: %s, $vEvents: %s', count($vEvents_with_RRULE),
                                 count($vEvents_with_Recurrence_id), count($vEvents)
                             )
        );

        $eventArray = [];

        foreach ($vEvents as $vEvent) {

            if (!($vEvent instanceof Kigkonsult\Icalcreator\Vevent)) {
                throw new RuntimeException('Component is not of type vevent');
            }

            $dtStartingTime = $this->getDateTime($vEvent->getDtstart(true));
            if ($vEvent->getDtend(true) === false){
                $dtEndingTime = false;
            } else {
                $dtEndingTime   = $this->getDateTime($vEvent->getDtend(true));
            }
            $dtDuration     = $vEvent->getDuration(false, true); //DateInterval

            call_user_func(
                $this->Logger_Dbg,
                __FUNCTION__,
                sprintf(
                    '#Event# dtStartingTime: %s, dtEndingTime: %s, diDuration: %s',
                    json_encode($dtStartingTime),
                    json_encode($dtEndingTime),
                    json_encode($dtDuration)
                )
            );

            $tsStartingTime = $dtStartingTime->getTimestamp();

            if ($dtDuration !== false) {
                $dtStart= new DateTime();
                $dtStart->setTimestamp($dtStartingTime->getTimestamp());
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

            $dtStartingTime = $vEvent->getDtstart();
            $dtEndingTime   = $vEvent->getDtend();
            $dtDuration     = $vEvent->getDuration(false, false);

            call_user_func(
                $this->Logger_Dbg,
                __FUNCTION__,
                sprintf(
                    '#Event_RRULE# dtStartingTime: %s, dtEndingTime: %s, diDuration: %s',
                    json_encode($dtStartingTime),
                    json_encode($dtEndingTime),
                    json_encode($dtDuration)
                )
            );


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
                    unset($day);
                    // rules like every 1. Monday of the month
                    if (isset($CalRRule['BYDAY'][0], $CalRRule['BYDAY']['DAY'])) {
                        $CalRRule['BYDAY']['DAY'] = $CalRRule['BYDAY'][0] . $CalRRule['BYDAY']['DAY'];
                        unset($CalRRule['BYDAY'][0]);
                    }

                }

                call_user_func($this->Logger_Dbg, __FUNCTION__, sprintf('CalRRule \'%s\': %s', $vEvent->getSummary(), json_encode($CalRRule)));

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
                    $dtExDates[] = $this->iCalDateTimeArrayToDateTime(['value' => $exDateValue, 'params' => $exDates['params']]);
                }
            }
            call_user_func($this->Logger_Dbg, __FUNCTION__, sprintf('ExDates: %s', json_encode($dtExDates)));

            //get the occurrences
            $dtOccurences = $RRule->getOccurrencesBetween($CacheDateTimeFrom, $CacheDateTimeUntil);
            call_user_func($this->Logger_Dbg, __FUNCTION__, sprintf('dtOccurrences: %s', json_encode($dtOccurences)));
            foreach ($dtOccurences as $dtOccurrence) {
                if (!($dtOccurrence instanceof DateTime)) {
                    throw new RuntimeException('Component is not of type DateTime');
                }

                //check if occurrence was deleted
                call_user_func($this->Logger_Dbg, __FUNCTION__, sprintf('dtOccurence: %s', json_encode($dtOccurrence)));
                if (in_array($dtOccurrence, $dtExDates, false)) { //compare the content, not the instance
                    call_user_func($this->Logger_Dbg, __FUNCTION__, 'excluded');
                    continue;
                }

                //check if occurrence was changed
                $changedEvent = $this->getChangedEvent($vEvents_with_Recurrence_id, (string)$vEvent->getUid(), $dtOccurrence);

                if ($changedEvent) {
                    $dtStartingTime = $changedEvent->getDtstart();
                    $dtEndingTime   = $changedEvent->getDtend();
                    $eventArray[] = $this->GetEventAttributes($changedEvent, ($changedEvent->getDtstart())->getTimestamp(), ($changedEvent->getDtend())->getTimestamp());
                } else {
                    $tsFrom = $dtOccurrence->getTimestamp();
                    if ($dtDuration !== false) {
                        $dtStart= new DateTime();
                        $dtStart->setTimestamp($dtStartingTime->getTimestamp());
                        $tsTo = ($dtStart->add($dtDuration))->getTimestamp();
                    } else {
                        $tsTo = $tsFrom + ($dtEndingTime->getTimestamp() - $dtStartingTime->getTimestamp());
                    }
                    $eventArray[] = $this->GetEventAttributes($vEvent, $tsFrom, $tsTo);
                }
            }
        }

        foreach ($eventArray as $ThisEvent) {
            if (($ThisEvent['To'] < $CacheDateTimeFrom->getTimestamp()) || ($ThisEvent['From'] > $CacheDateTimeUntil->getTimestamp())
                || (($ThisEvent['allDay'] === true) && (($ThisEvent['To'] === $CacheDateTimeFrom->getTimestamp()) || ($ThisEvent['From'] === $CacheDateTimeUntil->getTimestamp()) ))) {
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
            $iCalCalendarArray, static function ($a, $b) {
            return $a['From'] - $b['From'];
        }
        );
        return $iCalCalendarArray;
    }

    private function getDateTime(array $dateTimeWithParams):DateTime
    {
        //var_dump($dateTimeWithParams);
        $params = $dateTimeWithParams['params'];
        if ((isset($params['VALUE']) && $params['VALUE'] === 'DATE') || (isset($params['ISLOCALTIME']) && ($params['ISLOCALTIME']))){
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
        $status        = $vEvent->getStatus();
        if ($status){
            $Event['Status'] = $vEvent->getStatus();
        } else {
            $Event['Status']    = '';
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

        $Event['From']  = $tsFrom;
        $Event['To']    = $tsTo;
        $Event['FromS'] = date(DATE_ATOM, $tsFrom);
        $Event['ToS']   = date(DATE_ATOM, $tsTo);
        $Event['allDay'] = $this->GetAllDayAtribute($vEvent);

        call_user_func(
            $this->Logger_Dbg, __FUNCTION__, sprintf('Event: %s', json_encode($Event))
        );

        return $Event;
    }

    private function GetAllDayAtribute(Kigkonsult\Icalcreator\Vevent $vEvent):bool{
        $propDtstart    = $vEvent->getDtstart(true); // incl. params
        $propDtend    = $vEvent->getDtend(true); // incl. params

        if ($propDtstart) {
            if (isset($propDtstart['params']['VALUE']) && ($propDtstart['params']['VALUE'] === 'DATE')) {
                return true;
            }
            if ($propDtend && ($propDtend['value']->format('H:i:s') ==='00:00:00') && ($propDtend['value']->format('H:i:s') ==='00:00:00')){
                return true;
            }
        }
        return false;
    }
}
