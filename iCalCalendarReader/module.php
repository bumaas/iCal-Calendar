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

    private $NowDateTime;

    private $NowTimestamp;

    private $PostNotifySeconds;

    private $DaysToCache;

    private $CacheSizeDateTime;

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
                $DSTStartDateTime = $this->TZRRuleToDateTime($CalendarTimezone['DSTSTART'], $EventDateTime->format('Y'));
                $DSTEndDateTime   = $this->TZRRuleToDateTime($CalendarTimezone['DSTEND'], $EventDateTime->format('Y'));

                // between these dates?
                if (($EventDateTime > $DSTStartDateTime) && ($EventDateTime < $DSTEndDateTime)) {
                    $EventDateTime->add(DateInterval::createFromDateString(strtotime($CalendarTimezone['DSTOFFSET'])));
                } else {
                    $EventDateTime->add(DateInterval::createFromDateString(strtotime($CalendarTimezone['OFFSET'])));
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
    private function iCalDateTimeArrayToDateTime(array $DT): DateTime
    {
        $Year  = (int) $DT['value']['year'];
        $Month = (int) $DT['value']['month'];
        $Day   = (int) $DT['value']['day'];

        $WholeDay = false;
        // whole-day, this is not timezone relevant!
        if (array_key_exists('params', $DT) && array_key_exists('VALUE', $DT['params']) && ('DATE' === $DT['params']['VALUE'])) {
            $WholeDay = true;
        }

        if (array_key_exists('hour', $DT['value'])) {
            $Hour = (int) $DT['value']['hour'];
        } else {
            $Hour = 0;
        }
        if (array_key_exists('min', $DT['value'])) {
            $Min = (int) $DT['value']['min'];
        } else {
            $Min = 0;
        }
        if (array_key_exists('sec', $DT['value'])) {
            $Sec = (int) $DT['value']['sec'];
        } else {
            $Sec = 0;
        }
        // owncloud calendar
        if (array_key_exists('params', $DT) && array_key_exists('TZID', $DT['params'])) {
            $Timezone = $DT['params']['TZID'];
        } // google calendar
        else if (array_key_exists('tz', $DT['value'])) {
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
            if (false === $SetTZResult) {
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
        $this->NowDateTime       = date_create();
        $this->NowTimestamp      = date_timestamp_get($this->NowDateTime);
        $this->PostNotifySeconds = $PostNotifyMinutes * 60;
        $this->DaysToCache       = $DaysToCache;
        $this->CacheSizeDateTime = date_timestamp_set(date_create(), $this->NowTimestamp + $this->DaysToCache);
    }

    /*
        main import method
    */
    public function ImportCalendar(string $iCalData): array
    {
        $iCalCalendarArray       = [];
        $this->CalendarTimezones = [];

        $Config    = [
            'unique_id'     => 'ergomation.de',
            'TZID'          => $this->Timezone,
            'X-WR-TIMEZONE' => $this->Timezone
        ];
        $vCalendar = new Kigkonsult\Icalcreator\Vcalendar();
        $vCalendar->parse($iCalData);

        // get calendar supplied timezones
        while ($Comp = $vCalendar->getComponent('vtimezone')) {
            $ProvidedTZ = [];
            $Standard   = $Comp->getComponent('STANDARD');
            $Daylight   = $Comp->getComponent('DAYLIGHT');

            if (($Standard !== false) && ($Daylight !== false)) {
                $ProvidedTZ['TZID']      = $Comp->getProperty('TZID');
                $ProvidedTZ['DSTSTART']  = $Daylight->getProperty('rrule', false, false);
                $ProvidedTZ['DSTEND']    = $Standard->getProperty('rrule', false, false);
                $ProvidedTZ['OFFSET']    = $Standard->getProperty('TZOFFSETTO');
                $ProvidedTZ['DSTOFFSET'] = $Standard->getProperty('TZOFFSETFROM');

                $this->CalendarTimezones[] = $ProvidedTZ;
            }
        }

        while ($Comp = $vCalendar->getComponent('vevent')) {
            $ThisEventArray           = [];
            $ThisEvent                = [];
            $ThisEvent['UID']         = $Comp->getProperty('uid', false);
            $ThisEvent['Name']        = $Comp->getProperty('summary', false);
            $ThisEvent['Location']    = $Comp->getProperty('location', false);
            $ThisEvent['Description'] = $Comp->getProperty('description', false);
            $this->LogDebug('event: ' . print_r($ThisEvent, true));
            //$this->LogDebug('Component objName: ' . $Comp->objName);
            $dtstart = $Comp->getProperty('dtstart', false, true);
            $dtend = $Comp->getProperty('dtend', false, true);

            if (($dtstart === false) || ($dtend === false)){
                $this->LogDebug(sprintf('Uncomplete vevent: %s, DTSTART: %s, DTEND: %s', json_encode($ThisEvent), json_encode($dtstart), json_encode($dtend)));
                trigger_error(sprintf('Uncomplete vevent: %s, DTSTART: %s, DTEND: %s', json_encode($ThisEvent), json_encode($dtstart), json_encode($dtend)), E_USER_WARNING);
                continue;
            }
            $StartingTime      = $this->iCalDateTimeArrayToDateTime($dtstart);
            $EndingTime        = $this->iCalDateTimeArrayToDateTime($dtend);
            $StartingTimestamp = date_timestamp_get($StartingTime);
            $EndingTimestamp   = date_timestamp_get($EndingTime);
            $Duration          = $EndingTimestamp - $StartingTimestamp;

            if ($this->NowTimestamp < strtotime(sprintf('- %s days', $this->DaysToCache), $StartingTimestamp)) {
                // event is too far in the future, ignore
                $this->LogDebug('Event ' . $ThisEvent['Name'] . 'is too far in the future, ignoring');
            } else {
                // check if recurring
                $CalRRule = $Comp->getProperty('rrule', false);
                if (is_array($CalRRule)) {
                    $this->LogDebug('Recurring event: ' . print_r($CalRRule, true));
                    if (array_key_exists('UNTIL', $CalRRule)) {
                        $UntilDateTime = $this->iCalDateTimeArrayToDateTime(['value' => $CalRRule['UNTIL']]);
                        // replace iCal date array with datetime object
                        $CalRRule['UNTIL'] = $UntilDateTime;
                    }
                    // replace/set iCal date array with datetime object
                    $CalRRule['DTSTART'] = $StartingTime;

                    try{
                        $RRule               = new RRule($CalRRule);
                    } catch (Exception $e){
                        $this->LogDebug(sprintf('Error in CalRRule: %s', json_encode($CalRRule)));
                        continue;
                    }
                    foreach ($RRule->getOccurrencesBetween($this->NowDateTime, $this->CacheSizeDateTime) as $Occurrence) {
                        $ThisEvent['From']  = date_timestamp_get($Occurrence);
                        $ThisEvent['To']    = $ThisEvent['From'] + $Duration;
                        $ThisEvent['FromS'] = date('Y-m-d H:i:s', $ThisEvent['From']);
                        $ThisEvent['ToS']   = date('Y-m-d H:i:s', $ThisEvent['To']);
                        $ThisEventArray[]   = $ThisEvent;
                    }
                } else {
                    $ThisEvent['From']  = $StartingTimestamp;
                    $ThisEvent['To']    = $EndingTimestamp;
                    $ThisEvent['FromS'] = date('Y-m-d H:i:s', $ThisEvent['From']);
                    $ThisEvent['ToS']   = date('Y-m-d H:i:s', $ThisEvent['To']);
                    $ThisEventArray[]   = $ThisEvent;
                }
                foreach ($ThisEventArray as $ThisEvent) {
                    if ($this->NowTimestamp > ($ThisEvent['To'] + $this->PostNotifySeconds)) {
                        // event is past notification times, ignore
                        $this->LogDebug('Event ' . $ThisEvent['Name'] . ' is past the notification times, ignoring');
                    } else {
                        // insert event(s)
                        $iCalCalendarArray[] = $ThisEvent;
                    }
                }
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
        $this->RegisterTimer('Cron1', 1000 * 60, 'ICCR_TriggerNotifications( $_IPS["TARGET"] );'); // cron runs every minute
        $this->RegisterTimer( 'Cron5', 5000 * 60 , 'ICCR_UpdateClientConfig( $_IPS["TARGET"] );' ); // cron runs every 5 minutes
    }

    /*
        react on user configuration dialog
    */
    public function ApplyChanges():bool
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyBoolean('active')) {
            //validate Configuration
            if (!$this->CheckCalendarURLSyntax()){
                $Status = self::STATUS_INST_INVALID_URL;
            } else{
                $curl_result = '';
                $Status = $this->LoadCalendarURL($curl_result);
            }
            $this->SetStatus($Status);
        } else {
            $Status = IS_INACTIVE;
        }

        $this->SetStatus($Status);

        // ready to run an update?
        if ($Status === IS_ACTIVE){
            $this->SetTimerInterval('Update', $this->ReadPropertyInteger(self::ICCR_PROPERTY_UPDATE_FREQUENCY) * 1000 * 60);
            $this->UpdateClientConfig();
            return true;
        }

        $this->SetTimerInterval('Update', 0);
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
        $this->LogDebug( 'Transfering configuration from notifier children' );
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
        $url = $this->ReadPropertyString(self::ICCR_PROPERTY_CALENDAR_URL);
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
        $curl_result = curl_exec($curl);
        $curl_error_nr     = curl_errno($curl);
        $curl_error_str    = curl_error($curl);
        curl_close($curl);

        // check on curl error
        if ($curl_error_nr) {
            $this->LogError(sprintf('Error on connect - (%s) %s for %s' , $curl_error_nr, $curl_error_str, $url));
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
                    $children  = $XML->children('http://sabredav.org/ns');
                    /** @noinspection PhpUndefinedFieldInspection */
                    $exception = $children->exception;
                    /** @noinspection PhpUndefinedFieldInspection */
                    $message = $XML->children('http://sabredav.org/ns')->message;
                    $this->LogError(sprintf('Error: %s - Message: %s',$exception, $message));
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
        } elseif (!empty( $curl_result)) {
            $this->LogDebug('Error, curl_result: ' . $curl_result);
        }

        return $result;
    }

    /*
        load calendar, convert calendar, return event array of false
    */
    private function ReadCalendar(): ?string
    {
        $curl_result = '';
        $result = $this->LoadCalendarURL($curl_result);
        if ($result !== IS_ACTIVE) {
            $this->SetStatus($result);
            return null;
        }

        $MyImporter        = new ICCR_iCalImporter($this->ReadAttributeInteger('MaxPostNotifySeconds'), $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHE));
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

        if(!$this->ReadPropertyBoolean('active')){
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

        $this->LogDebug( 'Processing notifications' );
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


