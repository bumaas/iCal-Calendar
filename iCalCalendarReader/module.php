<?php
//declare(strict_types=1);

include_once __DIR__ . '/../libs/includes.php';

include_once __DIR__ . '/../libs/iCalcreator-master/autoload.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RRuleTrait.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RRuleInterface.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RfcParser.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RRule.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RSet.php';

use RRule\RRule;

define('ICCR_DEBUG', true);

class DebugClass
{
    //private $sendDebug = null; // <--- Neu

    public function __construct(callable $sendDebug)
    {
        $this->sendDebug = $sendDebug;
    }

    public function DoIt()
    {
        echo (int) is_callable($this->sendDebug);
        $this->sendDebug('message', 'data', KL_DEBUG);
    }
}
/***********************************************************************
 * iCal importer class
 ************************************************************************/
class ICCR_iCalImporter
{

    private $Timezone;

    private $NowTimestamp;

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
    public function __construct(int $PostNotifyMinutes, int $DaysToCacheAhead, int $DaysToCacheBack, callable $Logger_Dbg, callable $Logger_Err)
    {
        $this->Timezone         = date_default_timezone_get();
        $this->NowTimestamp     = date_timestamp_get(date_create());
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
            $Daylight = $vTimezone->getComponent('DAYLIGHT');

            if (($Standard === false) || ($Daylight === false)) {
                call_user_func(
                    $this->Logger_Err, sprintf(
                                         'Uncomplete vtimezone: %s, STANDARD: %s, DAYLIGHT: %s', $vTimezone->getTzid(), json_encode($Standard),
                                         json_encode($Daylight)
                                     )
                );

                throw new RuntimeException('Standard or Daylight component is missing');
                continue;
            }

            if (!($Standard instanceof Kigkonsult\Icalcreator\Standard)) {
                throw new RuntimeException('Component is not of type Standard');
            }
            if (!($Daylight instanceof Kigkonsult\Icalcreator\Daylight)) {
                throw new RuntimeException('Component is not of type Daylight');
            }

            $ProvidedTZ                   = [];
            $ProvidedTZ['TZID']           = $vTimezone->getTzid();
            $ProvidedTZ['DAYLIGHT_RRULE'] = $Daylight->getRrule();
            $ProvidedTZ['STANDARD_RRULE'] = $Standard->getRrule();
            $ProvidedTZ['TZOFFSETTO']     = $Standard->getTzoffsetto(); //todo
            $ProvidedTZ['TZOFFSETFROM']   = $Standard->getTzoffsetfrom(); //todo

            call_user_func($this->Logger_Dbg, __FUNCTION__, 'ProvidedTZ: ' . print_r($ProvidedTZ, true));
            $this->CalendarTimezones[] = $ProvidedTZ;
        }

        //get different kind of events
        $vEvents                    = [];
        $vEvents_with_RRULE         = [];
        $vEvents_with_Recurrence_id = [];

        while ($vEvent = $vCalendar->getComponent('vevent')) {

            if (!($vEvent instanceof Kigkonsult\Icalcreator\Vevent)) {
                throw new RuntimeException('Component is not of type vevent');
            }


            $propDtstart    = $vEvent->getDtstart(true); // incl. params
            $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart);

            if (strtotime(sprintf('- %s days', $this->DaysToCacheAhead), $dtStartingTime->getTimestamp()) > $this->NowTimestamp) {
                // event is too far in the future, ignore
                call_user_func(
                    $this->Logger_Dbg, __FUNCTION__, 'Event \'' . $vEvent->getSummary() . '\' is too far in the future, ignoring'
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
            $dtEndingTime   = $this->iCalDateTimeArrayToDateTime($propDtend);


            $CalRRule = $vEvent->getRrule();

            if (array_key_exists('UNTIL', $CalRRule)) {
                // replace iCal date array with datetime object

                $CalRRule['UNTIL'] = $this->iCalDateTimeArrayToDateTime(['value' => $CalRRule['UNTIL']]);
            }
            // replace/set iCal date array with datetime object
            $CalRRule['DTSTART'] = $dtStartingTime;

            try {
                call_user_func($this->Logger_Dbg, __FUNCTION__, sprintf('CalRRule \'%s\': %s', $vEvent->getSummary(), json_encode($CalRRule)));

                $RRule = new RRule($CalRRule);
            }
            catch(Exception $e) {
                call_user_func($this->Logger_Err, sprintf('Error in CalRRule \'%s\': %s', $vEvent->getSummary(), json_encode($CalRRule)));
                continue;
            }
            $CacheSizeDateTimeFrom  = date_timestamp_set(date_create(), strtotime('- ' . $this->DaysToCacheBack . ' days', $this->NowTimestamp));
            $CacheSizeDateTimeUntil = date_timestamp_set(date_create(), strtotime('+ ' . $this->DaysToCacheAhead . ' days', $this->NowTimestamp));

            //get the EXDATES
            $dtExDates = [];
            if ($exDates = $vEvent->getExdate(null, true)) {
                foreach ($exDates['value'] as $exDateValue) {
                    $dtExDates[] = $this->iCalDateTimeArrayToDateTime(['value' => $exDateValue, 'params' => $exDates['params']]);
                }
            }


            //get the occurrences
            foreach ($RRule->getOccurrencesBetween($CacheSizeDateTimeFrom, $CacheSizeDateTimeUntil) as $dtOccurrence) {
                if (!($dtOccurrence instanceof DateTime)) {
                    throw new RuntimeException('Component is not of type DateTime');
                }

                //check if occurrence was deleted
                if (in_array($dtOccurrence, $dtExDates, false)) { //compare the content, not the instance
                    continue;
                }

                //check if occurrence was changed
                $changedEvent = $this->getChangedEvent($vEvents_with_Recurrence_id, (string) $vEvent->getUid(), $dtOccurrence);

                if ($changedEvent instanceof Kigkonsult\Icalcreator\Vevent) {
                    $propDtstart    = $changedEvent->getDtstart(true); // incl. params
                    $propDtend      = $changedEvent->getDtend(true);   // incl. params
                    $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart);
                    $dtEndingTime   = $this->iCalDateTimeArrayToDateTime($propDtend);

                    $eventArray[] = $this->GetEventAttributes($changedEvent, $dtStartingTime->getTimestamp(), $dtEndingTime->getTimestamp());

                } else {
                    $tsFrom = $dtOccurrence->getTimestamp();
                    $tsTo   = $tsFrom + $dtEndingTime->getTimestamp() - $dtStartingTime->getTimestamp();

                    $eventArray[] = $this->GetEventAttributes($vEvent, $tsFrom, $tsTo);
                }
            }
        }

        foreach ($eventArray as $ThisEvent) {
            if ((strtotime(sprintf('+ %s days', $this->DaysToCacheAhead), $ThisEvent['To']) < $this->NowTimestamp)
                || (strtotime(sprintf('- %s days', $this->DaysToCacheBack), $ThisEvent['From']) > $this->NowTimestamp)) {

                // event not in the cached time, ignore
                call_user_func(
                    $this->Logger_Dbg, __FUNCTION__, sprintf(
                    'Event \'%s\' (%s - %s) is outside the cached time (DaysToCacheBack: %s, DaysToCache: %s), ignoring', $ThisEvent['Name'], $ThisEvent['FromS'],
                    $ThisEvent['ToS'], $this->DaysToCacheBack, $this->DaysToCacheAhead
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
        if ($vEvent->getLocation()) {
            $Event['Location'] = $vEvent->getLocation();
        } else {
            $Event['Location'] = '';
        }
        if ($vEvent->getDescription()) {
            $Event['Description'] = $vEvent->getDescription();
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


/***********************************************************************
 * module class
 ************************************************************************/
class iCalCalendarReader extends IPSModule
{

    private const STATUS_INST_INVALID_URL = 201;
    private const STATUS_INST_SSL_ERROR = 202;
    private const STATUS_INST_INVALID_USER_PASSWORD = 203;
    private const STATUS_INST_CONNECTION_ERROR = 204;
    private const STATUS_INST_UNEXPECTED_RESPONSE = 205;

    private const ICCR_PROPERTY_CALENDAR_URL = 'CalendarServerURL';
    private const ICCR_PROPERTY_USERNAME = 'Username';
    private const ICCR_PROPERTY_PASSWORD = 'Password';
    private const ICCR_PROPERTY_DAYSTOCACHE = 'DaysToCache';
    private const ICCR_PROPERTY_DAYSTOCACHEBACK = 'DaysToCacheBack';
    private const ICCR_PROPERTY_UPDATE_FREQUENCY = 'UpdateFrequency';
    private const ICCR_PROPERTY_WRITE_DEBUG_INFORMATION_TO_LOGFILE = 'WriteDebugInformationToLogfile';


    /***********************************************************************
     * standard module methods
     ************************************************************************/

    /*
        basic setup
    */
    public function Create()
    {
        parent::Create();

        // create configuration properties
        $this->RegisterPropertyBoolean('active', true);
        $this->RegisterPropertyString(self::ICCR_PROPERTY_CALENDAR_URL, '');
        $this->RegisterPropertyString(self::ICCR_PROPERTY_USERNAME, '');
        $this->RegisterPropertyString(self::ICCR_PROPERTY_PASSWORD, '');

        $this->RegisterPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHE, 30);
        $this->RegisterPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHEBACK, 30);
        $this->RegisterPropertyInteger(self::ICCR_PROPERTY_UPDATE_FREQUENCY, 15);
        $this->RegisterPropertyBoolean(self::ICCR_PROPERTY_WRITE_DEBUG_INFORMATION_TO_LOGFILE, false);

        // create Attributes
        $this->RegisterAttributeString('CalendarBuffer', '');
        $this->RegisterAttributeString('Notifications', '');
        $this->RegisterAttributeInteger('MaxPreNotifySeconds', 0);
        $this->RegisterAttributeInteger('MaxPostNotifySeconds', 0);

        // create timer
        $this->RegisterTimer('Update', 0, 'ICCR_UpdateCalendar( $_IPS["TARGET"] );'); // no update on init
        $this->RegisterTimer('Cron5', 0, 'ICCR_UpdateClientConfig( $_IPS["TARGET"] );'); // cron runs every 5 minutes, when active
        $this->RegisterTimer('Cron1', 1000 * 60, 'ICCR_TriggerNotifications( $_IPS["TARGET"] );'); // cron runs every minute
    }

    /*
        react on user configuration dialog
    */
    public function ApplyChanges(): bool
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyBoolean('active')) {
            //validate Configuration
            if (!$this->CheckCalendarURLSyntax()) {
                $Status = self::STATUS_INST_INVALID_URL;
            } else {
                $curl_result = '';
                $Status      = $this->LoadCalendarURL($curl_result);
            }
            $this->SetStatus($Status);
        } else {
            $Status = IS_INACTIVE;
        }

        $this->SetStatus($Status);

        // ready to run an update?
        if (($Status === IS_ACTIVE) && (IPS_GetKernelRunlevel() === KR_READY)) {
            $this->SetTimerInterval('Update', $this->ReadPropertyInteger(self::ICCR_PROPERTY_UPDATE_FREQUENCY) * 1000 * 60);
            $this->SetTimerInterval('Cron5', 5000 * 60);
            $this->UpdateClientConfig();
            return true;
        }

        $this->SetTimerInterval('Update', 0);
        $this->SetTimerInterval('Cron5', 0);
        return false;
    }


    /*
        save notifications and find the extremum
    */
    private function SetNotifications(array $notifications): void
    {
        $this->WriteAttributeString('Notifications', json_encode($notifications));
        $MaxPreNS  = 0;
        $MaxPostNS = 0;

        foreach ($notifications as $notification) {
            if (array_key_exists('PreNS', $notification) && $notification['PreNS'] > $MaxPreNS) {
                $MaxPreNS = $notification['PreNS'];
            }
            if (array_key_exists('PostNS', $notification) && $notification['PostNS'] > $MaxPostNS) {
                $MaxPostNS = $notification['PostNS'];
            }
        }

        $this->WriteAttributeInteger('MaxPreNotifySeconds', $MaxPreNS);
        $this->WriteAttributeInteger('MaxPostNotifySeconds', $MaxPostNS);
    }


    /*
        check if calendar URL syntax is valid
    */
    public function CheckCalendarURLSyntax(): bool
    {
        $this->Logger_Dbg(__FUNCTION__, sprintf('Entering %s()', __FUNCTION__));

        // validate saved properties
        $calendarServerURL = $this->ReadPropertyString(self::ICCR_PROPERTY_CALENDAR_URL);
        return (($calendarServerURL !== '') && filter_var($calendarServerURL, FILTER_VALIDATE_URL));
    }


    /***********************************************************************
     * configuration helper
     ************************************************************************/

    /*
        get all notifications information from connected child instances
    */
    private function GetChildrenConfig(): void
    {
        $this->Logger_Dbg(__FUNCTION__, sprintf('Entering %s()', __FUNCTION__));
        // empty configuration buffer
        $Notifications  = [];
        $ChildInstances = IPS_GetInstanceListByModuleID(ICCN_INSTANCE_GUID);
        if (count($ChildInstances) === 0) {
            return;
        }
        // transfer configuration
        $this->Logger_Dbg(__FUNCTION__, 'Transfering configuration from notifier children');
        foreach ($ChildInstances as $ChInstance) {
            if (IPS_GetInstance($ChInstance)['ConnectionID'] === $this->InstanceID) {
                $ClientConfig            = json_decode(IPS_GetConfiguration($ChInstance), true);
                $ClientPreNotifyMinutes  = $ClientConfig['PreNotifyMinutes'];
                $ClientPostNotifyMinutes = $ClientConfig['PostNotifyMinutes'];
                // new entry
                $Notifications[$ChInstance] = [
                    'PreNS'  => $ClientPreNotifyMinutes * 60,
                    'PostNS' => $ClientPostNotifyMinutes * 60,
                    'Status' => 0,
                    'Reason' => []];
            }
        }
        $this->SetNotifications($Notifications);
    }


    /***********************************************************************
     * calendar loading and conversion methods
     ***********************************************************************
     *
     * @param string $curl_result
     *
     * @return int
     */

    /*
        load calendar from URL into $this->curl_result, returns IPS status value
    */
    private function LoadCalendarURL(string &$curl_result): int
    {
        $result   = IS_ACTIVE;
        $url      = $this->ReadPropertyString(self::ICCR_PROPERTY_CALENDAR_URL);
        $username = $this->ReadPropertyString(self::ICCR_PROPERTY_USERNAME);
        $password = $this->ReadPropertyString(self::ICCR_PROPERTY_PASSWORD);

        $this->Logger_Dbg(__FUNCTION__, sprintf('Entering %s(\'%s\')', __FUNCTION__, $url));

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true); // yes, easy but lazy
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 2); // 30s maximum script execution time
        curl_setopt($curl, CURLOPT_TIMEOUT, 10); // 30s maximum script execution time
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 5); // educated guess
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        if ($username !== '') {
            curl_setopt($curl, CURLOPT_USERPWD, $username . ':' . $password);
        }
        $curl_result    = curl_exec($curl);
        $curl_error_nr  = curl_errno($curl);
        $curl_error_str = curl_error($curl);
        curl_close($curl);

        // check on curl error
        if ($curl_error_nr) {
            $this->Logger_Err(sprintf('Error on connect - (%s) %s for %s', $curl_error_nr, $curl_error_str, $url));
            // only differentiate between invalid, connect, SSL and auth
            switch ($curl_error_nr) {
                case 1:
                case 3:
                case 4:
                    // invalid URL
                    $result = self::STATUS_INST_INVALID_URL;
                    break;
                case 35:
                case 53:
                case 54:
                case 58:
                case 59:
                case 60:
                case 64:
                case 66:
                case 77:
                case 80:
                case 82:
                case 83:
                    // SSL error
                    $result = self::STATUS_INST_SSL_ERROR;
                    break;
                case 67:
                    // auth error
                    $result = self::STATUS_INST_INVALID_USER_PASSWORD;
                    break;
                default:
                    // connect error
                    $result = self::STATUS_INST_CONNECTION_ERROR;
                    break;
            }
        } // no curl error, continue
        elseif (strpos($curl_result, 'BEGIN:VCALENDAR') === false) {
            // handle error document
            $result = self::STATUS_INST_UNEXPECTED_RESPONSE;

            // ownCloud sends XML error messages
            libxml_use_internal_errors(true);
            $XML = simplexml_load_string($curl_result);

            // owncloud error?
            if ($XML !== false) {
                $XML->registerXPathNamespace('d', 'DAV:');
                if (count($XML->xpath('//d:error')) > 0) {
                    // XML error document
                    $children = $XML->children('http://sabredav.org/ns');
                    /** @noinspection PhpUndefinedFieldInspection */
                    $exception = $children->exception;
                    /** @noinspection PhpUndefinedFieldInspection */
                    $message = $XML->children('http://sabredav.org/ns')->message;
                    $this->Logger_Err(sprintf('Error: %s - Message: %s', $exception, $message));
                    $result = self::STATUS_INST_INVALID_USER_PASSWORD;
                }
            } // synology sends plain text
            else if (strpos($curl_result, 'Please log in') === 0) {
                $this->Logger_Err('Error logging on - invalid user/password combination for ' . $url);
                $result = self::STATUS_INST_INVALID_USER_PASSWORD;
            } // everything else goes here
            else {
                $this->Logger_Err('Error on connect - this is not a valid calendar URL: ' . $url);
                $result = self::STATUS_INST_INVALID_URL;
            }
        }

        if ($result === IS_ACTIVE) {
            $this->Logger_Dbg(__FUNCTION__, 'curl_result: ' . $curl_result);
            $this->Logger_Dbg(__FUNCTION__, 'Successfully loaded');
        } elseif (!empty($curl_result)) {
            $this->Logger_Dbg(__FUNCTION__, 'Error, curl_result: ' . $curl_result);
        }
        return $result;
    }

    /*
        load calendar, convert calendar, return event array of false
    */
    private function ReadCalendar(): ?string
    {
        $curl_result = '';
        $result      = $this->LoadCalendarURL($curl_result);
        if ($result !== IS_ACTIVE) {
            $this->SetStatus($result);
            return null;
        }

        $MyObject = new DebugClass(
            function($Message, $Data, $Format) {
                $this->SendDebug($Message, $Data, $Format);
            }
        );

        //$MyObject->DoIt();
        $MyImporter        = new ICCR_iCalImporter(
            $this->ReadAttributeInteger('MaxPostNotifySeconds'),
            $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHEBACK),
            $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHE),
            function(string $message, string $data) {
                $this->Logger_Dbg($message, $data);
            }, function(string $message) {
            $this->Logger_Err($message);
        }
        );
        $iCalCalendarArray = $MyImporter->ImportCalendar($curl_result);
        return json_encode($iCalCalendarArray);
    }

    /*
        entry point for the periodic calendar update timer
        also used to trigger manual calendar updates after configuration changes
        accessible for external scripts
    */


    private function Logger_Err(string $message): void
    {
        $this->SendDebug('LOG_ERR', $message, 0);
        /*
        if (function_exists('IPSLogger_Err') && $this->ReadPropertyBoolean('WriteLogInformationToIPSLogger')) {
            IPSLogger_Err(__CLASS__, $message);
        }
        */
        $this->LogMessage($message, KL_ERROR);

    }

    private function Logger_Dbg(string $message, string $data): void
    {
        $this->SendDebug($message, $data, 0);
        /*
        if (function_exists('IPSLogger_Dbg') && $this->ReadPropertyBoolean('WriteDebugInformationToIPSLogger')) {
            IPSLogger_Dbg(__CLASS__ . '.' . IPS_GetObject($this->InstanceID)['ObjectName'] . '.' . $message, $data);
        }
        */
        if ($this->ReadPropertyBoolean(self::ICCR_PROPERTY_WRITE_DEBUG_INFORMATION_TO_LOGFILE)) {
            $this->LogMessage(sprintf('%s: %s', $message, $data), KL_DEBUG);
        }
    }

    public function UpdateCalendar(): void
    {
        $this->Logger_Dbg(__FUNCTION__, sprintf('Entering %s()', __FUNCTION__));

        if (!$this->ReadPropertyBoolean('active')) {
            $this->Logger_Dbg(__FUNCTION__, 'Instance is inactive');
            return;
        }

        $TheOldCalendar = $this->ReadAttributeString('CalendarBuffer');
        $TheNewCalendar = $this->ReadCalendar();
        $this->Logger_Dbg(__FUNCTION__, sprintf('Buffered Calendar: %s', $TheOldCalendar));
        $this->Logger_Dbg(__FUNCTION__, sprintf('New Calendar: %s', $TheNewCalendar));


        if ($TheNewCalendar === null) {
            $this->Logger_Dbg(__FUNCTION__, 'Failed to load calendar');
            return;
        }
        if (strcmp($TheOldCalendar, $TheNewCalendar) !== 0) {
            $this->Logger_Dbg(__FUNCTION__, 'Updating internal calendar');
            $this->WriteAttributeString('CalendarBuffer', $TheNewCalendar);
        } else {
            $this->Logger_Dbg(__FUNCTION__, 'Calendar still in sync');
        }
    }


    /***********************************************************************
     * calendar notifications methods
     ***********************************************************************
     *
     * @param $Start
     * @param $End
     * @param $Pre
     * @param $Post
     *
     * @return bool
     */

    /*
        check if event is triggering a presence notification
    */
    private function CheckPresence(int $Start, int $End, int $Pre, int $Post): bool
    {
        $ts = time();
        return (($Start - $Pre) < $ts) && ($End + $Post) > $ts;
    }

    /*
        entry point for the periodic 1m notifications timer
        also used to trigger manual updates after configuration changes
        accessible for external scripts
    */
    public function TriggerNotifications(): void
    {
        $this->Logger_Dbg(__FUNCTION__, 'Entering TriggerNotifications()');

        $Notifications = json_decode($this->ReadAttributeString('Notifications'), true);
        if (empty($Notifications)) {
            return;
        }

        $this->Logger_Dbg(__FUNCTION__, 'Processing notifications');
        foreach ($Notifications as $Notification) {
            $Notification['Status'] = false;
            $Notification['Reason'] = [];
        }

        $TheCalendar       = $this->ReadAttributeString('CalendarBuffer');
        $iCalCalendarArray = json_decode($TheCalendar, true);
        if (!empty($iCalCalendarArray)) {
            foreach ($iCalCalendarArray as $iCalItem) {
                foreach ($Notifications as $ChInstanceID => $Notification) {
                    if ($this->CheckPresence($iCalItem['From'], $iCalItem['To'], $Notification['PreNS'], $Notification['PostNS'])) {
                        // append status and reason to the corresponding notification
                        $Notifications[$ChInstanceID]['Status']   = true;
                        $Notifications[$ChInstanceID]['Reason'][] = $iCalItem;
                    }
                }
            }
        }

        // set status back to children
        foreach ($Notifications as $ChInstanceID => $Notification) {
            $this->SendDataToChildren(
                json_encode(
                    [
                        'DataID'     => ICCR_TX,
                        'InstanceID' => $ChInstanceID,
                        'Notify'     => [
                            'Status' => $Notification['Status'],
                            'Reason' => $Notification['Reason']]]
                )
            );
        }
    }

    /*
        entry point for a child to inform the parent to update its children configuration
        accessible for external scripts
    */
    public function UpdateClientConfig(): void
    {
        $this->GetChildrenConfig();
        $this->UpdateCalendar();
        $this->TriggerNotifications();
    }

    /***********************************************************************
     * methods for script access
     ************************************************************************/

    /*
        returns the registered notifications structure
    */
    public function GetClientConfig()
    {
        return json_decode($this->ReadAttributeString('Notifications'), true);
    }

    /*
        returns the internal calendar structure
    */
    public function GetCachedCalendar(): string
    {
        $CalendarBuffer = $this->ReadAttributeString('CalendarBuffer');
        $this->Logger_Dbg(__FUNCTION__, $CalendarBuffer);
        return $CalendarBuffer;
    }

}


