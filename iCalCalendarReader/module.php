<?php
declare(strict_types=1);

include_once __DIR__ . '/../libs/base.php';
include_once __DIR__ . '/../libs/includes.php';

include_once __DIR__ . '/../libs/iCalcreator-master/autoload.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RRuleTrait.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RRuleInterface.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RfcParser.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RRule.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RSet.php';

use RRule\RRule;

define('ICCR_DEBUG', true);


/***********************************************************************
 * iCal importer class
 ************************************************************************/
class ICCR_iCalImporter
{

    private $Timezone;

    private $NowTimestamp;

    private $PostNotifySeconds;

    private $DaysToCache;

    private $CalendarTimezones;

    /*
        debug method, depending on defined constant
    */
    private function LogDebug($Debug): void
    {
        if (ICCR_DEBUG) {
            IPS_LogMessage('iCalImporter Debug', $Debug);
        }
    }

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
    private function iCalDateTimeArrayToDateTime(array $value, array $params = null): DateTime
    {

        // whole-day, this is not timezone relevant!
        $WholeDay = (isset($params) && array_key_exists('VALUE', $params) && ('DATE' === $params['VALUE']));

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
    public function __construct(int $PostNotifyMinutes, int $DaysToCache)
    {
        $this->Timezone          = date_default_timezone_get();
        $this->NowTimestamp      = date_timestamp_get(date_create());
        $this->PostNotifySeconds = $PostNotifyMinutes * 60;
        $this->DaysToCache       = $DaysToCache;
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
            $this->LogDebug($e->getMessage());
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
                $this->LogDebug(
                    sprintf(
                        'Uncomplete vtimezone: %s, STANDARD: %s, DAYLIGHT: %s', $vTimezone->getTzid(), json_encode($Standard), json_encode($Daylight)
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

            $this->LogDebug('ProvidedTZ: ' . print_r($ProvidedTZ, true));
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


            $propDtstart = $vEvent->getDtstart(true); // incl. params
            if (isset($propDtstart['params'])) {
                $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart['value'], $propDtstart['params']);
            } else {
                $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart['value']);
            }

            if (strtotime(sprintf('- %s days', $this->DaysToCache), $dtStartingTime->getTimestamp()) > $this->NowTimestamp) {
                // event is too far in the future, ignore
                $this->LogDebug('Event \'' . $vEvent->getSummary() . '\' is too far in the future, ignoring');
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
            if ($propDtend === false){
                $propDtend = $propDtstart;
            }

            $this->LogDebug(sprintf('dtStartingTime %s, dtEndingTime%s', json_encode($propDtstart), json_encode($propDtend)));
            if (isset($propDtstart['params'])) {
                $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart['value'], $propDtstart['params']);
            } else {
                $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart['value']);
            }
            if (isset($propDtend['params'])) {
                $dtEndingTime = $this->iCalDateTimeArrayToDateTime($propDtend['value'], $propDtend['params']);
            } else {
                $dtEndingTime = $this->iCalDateTimeArrayToDateTime($propDtend['value']);
            }

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


            $this->LogDebug(sprintf('dtStartingTime %s, dtEndingTime%s', json_encode($propDtstart), json_encode($propDtend)));
            if (isset($propDtstart['params'])) {
                $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart['value'], $propDtstart['params']);
            } else {
                $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart['value']);
            }
            if (isset($propDtend['params'])) {
                $dtEndingTime = $this->iCalDateTimeArrayToDateTime($propDtend['value'], $propDtend['params']);
            } else {
                $dtEndingTime = $this->iCalDateTimeArrayToDateTime($propDtend['value']);
            }


            $CalRRule = $vEvent->getRrule();

            if (array_key_exists('UNTIL', $CalRRule)) {
                // replace iCal date array with datetime object
                $CalRRule['UNTIL'] = $this->iCalDateTimeArrayToDateTime($CalRRule['UNTIL']);
            }
            // replace/set iCal date array with datetime object
            $CalRRule['DTSTART'] = $dtStartingTime;
            $this->LogDebug('Recurring event: ' . print_r($CalRRule, true));

            try {
                $this->LogDebug(sprintf('CalRRule: %s', json_encode($CalRRule)));
                $RRule = new RRule($CalRRule);
            }
            catch(Exception $e) {
                $this->LogDebug(sprintf('Error in CalRRule: %s', json_encode($CalRRule)));
                continue;
            }
            $CacheSizeDateTimeFrom  = date_timestamp_set(date_create(), strtotime('- ' . $this->DaysToCache . ' days', $this->NowTimestamp));
            $CacheSizeDateTimeUntil = date_timestamp_set(date_create(), strtotime('+ ' . $this->DaysToCache . ' days', $this->NowTimestamp));

            //get the EXDATES
            $dtExDates = [];
            if ($exDates = $vEvent->getExdate(null, true)) {
                foreach ($exDates['value'] as $exDateValue) {
                    if (isset($exDates['params'])) {
                        $dtExDates[] = $this->iCalDateTimeArrayToDateTime($exDateValue, $exDates['params']);
                    } else {
                        $dtExDates[] = $this->iCalDateTimeArrayToDateTime($exDateValue);
                    }
                }
            }


            //get the occurrences
            $this->LogDebug(
                sprintf('Occurrences beetween %s and %s: %s', $CacheSizeDateTimeFrom->format('Y-m-d H:i:s'), $CacheSizeDateTimeUntil->format('Y-m-d H:i:s') ,print_r($RRule->getOccurrencesBetween($CacheSizeDateTimeFrom, $CacheSizeDateTimeUntil), true))
            );

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
                    $propDtstart = $changedEvent->getDtstart(true); // incl. params
                    $propDtend   = $changedEvent->getDtend(true);   // incl. params
                    $this->LogDebug(sprintf('dtStartingTime %s, dtEndingTime%s', json_encode($propDtstart), json_encode($propDtend)));
                    if (isset($propDtstart['params'])) {
                        $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart['value'], $propDtstart['params']);
                    } else {
                        $dtStartingTime = $this->iCalDateTimeArrayToDateTime($propDtstart['value']);
                    }
                    if (isset($propDtend['params'])) {
                        $dtEndingTime = $this->iCalDateTimeArrayToDateTime($propDtend['value'], $propDtend['params']);
                    } else {
                        $dtEndingTime = $this->iCalDateTimeArrayToDateTime($propDtend['value']);
                    }

                    $eventArray[] = $this->GetEventAttributes($changedEvent, $dtStartingTime->getTimestamp(), $dtEndingTime->getTimestamp());

                } else {
                    $tsFrom = $dtOccurrence->getTimestamp();
                    $tsTo   = $tsFrom + $dtEndingTime->getTimestamp() - $dtStartingTime->getTimestamp();

                    $eventArray[] = $this->GetEventAttributes($vEvent, $tsFrom, $tsTo);
                }
            }
        }

        foreach ($eventArray as $ThisEvent) {
            if ($this->NowTimestamp > ($ThisEvent['To'] + $this->PostNotifySeconds)) {
                // event is past notification times, ignore
                $this->LogDebug('Event ' . $ThisEvent['Name'] . ' is past the notification times, ignoring');
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
                $recurrenceId = $vEvent->getRecurrenceid(true);
                $dtFound      = $this->iCalDateTimeArrayToDateTime($recurrenceId['value'], $recurrenceId['params']);
                if ($dtOccurrence == $dtFound) {
                    $this->LogDebug(sprintf('ChangedEvent found: %s', $dtOccurrence->getTimestamp()));
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


        $this->LogDebug(sprintf('Event: %s', json_encode($Event)));
        return $Event;
    }
}


/***********************************************************************
 * module class
 ************************************************************************/
class iCalCalendarReader extends ErgoIPSModule
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
    private const ICCR_PROPERTY_UPDATE_FREQUENCY = 'UpdateFrequency';


    /***********************************************************************
     * customized debug methods
     ***********************************************************************
     *
     * @return bool
     */

    /*
        debug on/off is a defined constant
    */
    protected function IsDebug(): bool
    {
        return ICCR_DEBUG;
    }

    /*
        sender for debug messages is set
    */
    protected function GetLogID(): string
    {
        return IPS_GetName($this->InstanceID);
    }


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

        $this->RegisterPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHE, 365);
        $this->RegisterPropertyInteger(self::ICCR_PROPERTY_UPDATE_FREQUENCY, 15);

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
        $this->LogDebug(sprintf('Entering %s()', __FUNCTION__));

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
        $this->LogDebug(sprintf('Entering %s()', __FUNCTION__));
        // empty configuration buffer
        $Notifications  = [];
        $ChildInstances = IPS_GetInstanceListByModuleID(ICCN_INSTANCE_GUID);
        if (count($ChildInstances) === 0) {
            return;
        }
        // transfer configuration
        $this->LogDebug('Transfering configuration from notifier children');
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

        $this->LogDebug(sprintf('Entering %s(\'%s\')', __FUNCTION__, $url));

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
            $this->LogError(sprintf('Error on connect - (%s) %s for %s', $curl_error_nr, $curl_error_str, $url));
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
                    $this->LogError(sprintf('Error: %s - Message: %s', $exception, $message));
                    $result = self::STATUS_INST_INVALID_USER_PASSWORD;
                }
            } // synology sends plain text
            else if (strpos($curl_result, 'Please log in') === 0) {
                $this->LogError('Error logging on - invalid user/password combination for ' . $url);
                $result = self::STATUS_INST_INVALID_USER_PASSWORD;
            } // everything else goes here
            else {
                $this->LogError('Error on connect - this is not a valid calendar URL: ' . $url);
                $result = self::STATUS_INST_INVALID_URL;
            }
        }

        if ($result === IS_ACTIVE) {
            $this->LogDebug('curl_result: ' . $curl_result);
            $this->LogDebug('Successfully loaded');
        } elseif (!empty($curl_result)) {
            $this->LogDebug('Error, curl_result: ' . $curl_result);
        }
        $curl_result = 'BEGIN:VCALENDAR
PRODID:-//Google Inc//Google Calendar 70.9054//EN
VERSION:2.0
CALSCALE:GREGORIAN
METHOD:PUBLISH
X-WR-CALNAME:Nachtschicht
X-WR-TIMEZONE:Europe/Berlin
BEGIN:VTIMEZONE
TZID:Europe/Berlin
X-LIC-LOCATION:Europe/Berlin
BEGIN:DAYLIGHT
TZOFFSETFROM:+0100
TZOFFSETTO:+0200
TZNAME:CEST
DTSTART:19700329T020000
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU
END:DAYLIGHT
BEGIN:STANDARD
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
TZNAME:CET
DTSTART:19701025T030000
RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
DTSTART;TZID=Europe/Berlin:20181201T224500
DTEND;TZID=Europe/Berlin:20181202T064500
RRULE:FREQ=DAILY;INTERVAL=10
DTSTAMP:20190518T200351Z
UID:3dn1ejfqub6hr44euomg6u01nf@google.com
CREATED:20190518T164158Z
DESCRIPTION:
LAST-MODIFIED:20190518T164158Z
LOCATION:
SEQUENCE:0
STATUS:CONFIRMED
SUMMARY:Nachtschicht
TRANSP:OPAQUE
END:VEVENT
BEGIN:VEVENT
DTSTART;TZID=Europe/Berlin:20181130T224500
DTEND;TZID=Europe/Berlin:20181201T064500
RRULE:FREQ=DAILY;INTERVAL=10
DTSTAMP:20190518T200351Z
UID:2rt32lc8k15crc1egp5dpueke1@google.com
CREATED:20190518T164109Z
DESCRIPTION:
LAST-MODIFIED:20190518T164109Z
LOCATION:
SEQUENCE:0
STATUS:CONFIRMED
SUMMARY:Nachtschicht
TRANSP:OPAQUE
END:VEVENT
END:VCALENDAR
';
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

        $MyImporter        =
            new ICCR_iCalImporter($this->ReadAttributeInteger('MaxPostNotifySeconds'), $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHE));
        $iCalCalendarArray = $MyImporter->ImportCalendar($curl_result);
        return json_encode($iCalCalendarArray);
    }

    /*
        entry point for the periodic calendar update timer
        also used to trigger manual calendar updates after configuration changes
        accessible for external scripts
    */
    public function UpdateCalendar(): void
    {
        $this->LogDebug(sprintf('Entering %s()', __FUNCTION__));

        if (!$this->ReadPropertyBoolean('active')) {
            $this->LogDebug('Instance is inactive');
            return;
        }

        $TheOldCalendar = $this->ReadAttributeString('CalendarBuffer');
        $TheNewCalendar = $this->ReadCalendar();

        if ($TheNewCalendar === null) {
            $this->LogDebug('Failed to load calendar');
            return;
        }
        if (strcmp($TheOldCalendar, $TheNewCalendar) !== 0) {
            $this->LogDebug('Updating internal calendar');
            $this->WriteAttributeString('CalendarBuffer', $TheNewCalendar);
        } else {
            $this->LogDebug('Calendar still in sync');
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
        $this->LogDebug('Entering TriggerNotifications()');

        $Notifications = json_decode($this->ReadAttributeString('Notifications'), true);
        if (empty($Notifications)) {
            return;
        }

        $this->LogDebug('Processing notifications');
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
        return $this->ReadAttributeString('CalendarBuffer');
    }

}


