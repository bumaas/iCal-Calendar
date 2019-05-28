<?php
declare(strict_types=1);

include_once __DIR__ . '/../libs/includes.php';

include_once __DIR__ . '/../libs/iCalcreator-master/autoload.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RRuleTrait.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RRuleInterface.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RfcParser.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RRule.php';
include_once __DIR__ . '/../libs/php-rrule-master/src/RSet.php';

require_once 'iCalImporter.php';


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
    private const ICCR_PROPERTY_NOTIFIERS = 'Notifiers';


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
        $this->RegisterPropertyString(self::ICCR_PROPERTY_NOTIFIERS, '');

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

    public function GetConfigurationForm()
    {
        $form['elements'] = [
            ['type' => 'CheckBox', 'name' => 'active', 'caption' => 'active'],
            ['type' => 'Label', 'caption' => 'Calendar access'],
            ['type' => 'ValidationTextBox', 'name' => 'CalendarServerURL', 'caption' => 'Calendar URL'],
            ['type' => 'ValidationTextBox', 'name' => 'Username', 'caption' => 'Username'],
            ['type' => 'PasswordTextBox', 'name' => 'Password', 'caption' => 'Password'],
            ['type' => 'Label', 'caption' => 'Synchronization'],
            [
                'type'  => 'RowLayout',
                'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'DaysToCacheBack', 'caption' => 'Cachesize (Past)', 'suffix' => 'days'],
                    ['type' => 'NumberSpinner', 'name' => 'DaysToCache', 'caption' => 'Cachesize (Future)', 'suffix' => 'days'],]]];

        $form['elements'][] = [
            'type'     => 'List',
            'name'     => 'Notifiers',
            'caption'  => 'Notifiers',
            'rowCount' => '15',
            'add'      => true,
            'delete'   => true,
            'sort'     => ['column' => 'Notifier', 'direction' => 'ascending'],
            'columns'  => [
                ['caption' => 'Ident', 'name' => 'Ident', 'width' => '50px', 'add' => $this->Translate('new')],
                ['caption' => 'Name', 'name' => 'Notifier', 'width' => '250px', 'add' => '', 'edit' =>['type' => 'ValidationTextBox']],
                ['caption' => 'Eventtext', 'name' => 'Eventtext', 'width' => '100px', 'add' => '', 'edit' =>['type' => 'ValidationTextBox']],
                ['caption' => 'Prenotify', 'name' => 'Prenotify', 'width' => '100px', 'add' => 0, 'edit' =>['type' => 'NumberSpinner', 'suffix' => 'minutes']],
                ['caption' => 'Postnotify', 'name' => 'Postnotify', 'width' => '100px', 'add' => 0, 'edit' =>['type' => 'NumberSpinner', 'suffix' => 'minutes']]],
            'values'   => $this->GetListValues()];


        $form['elements'][] = [
            'type'    => 'ExpansionPanel',
            'caption' => 'Expert Parameters',

            'items' => [
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'UpdateFrequency',
                    'caption' => 'Update Interval',
                    'suffix'  => 'Minutes'],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'WriteDebugInformationToLogfile',
                    'caption' => 'Debug information are written additionally to standard logfile']]];

        $form['actions'] = [
            [
                'type'    => 'Button',
                'caption' => 'Check calendar URL',
                'onClick' => '$data = ""; $module = new IPSModule($id); if (ICCR_LoadCalendarURL($id, $data) !== IS_ACTIVE){echo $module->Translate("Fehler");} else {echo $module->Translate("OK");}'],
            [
                'type'    => 'Button',
                'caption' => 'Update calendar',
                'onClick' => '$module = new IPSModule($id); if (ICCR_UpdateCalendar($id) === false){echo $module->Translate("Fehler");} else {echo $module->Translate("OK");}'],

        ];

        $form['status'] = [
            ['code' => self::STATUS_INST_INVALID_URL, 'icon' => 'error', 'caption' => 'Invalid URL, see log for details'],
            ['code' => self::STATUS_INST_SSL_ERROR, 'icon' => 'error', 'caption' => 'SSL error, see log for details'],
            ['code' => self::STATUS_INST_INVALID_USER_PASSWORD, 'icon' => 'error', 'caption' => 'Invalid user or password'],
            ['code' => self::STATUS_INST_CONNECTION_ERROR, 'icon' => 'error', 'caption' => 'Connection error, see log for details'],
            [
                'code'    => self::STATUS_INST_UNEXPECTED_RESPONSE,
                'icon'    => 'error',
                'caption' => 'Unexpected response from calendar server, please check calendar URL'],];


        return json_encode($form);

    }


    private function GetListValues(): array
    {
        $listValues = [];
        $notifiers  = $this->ReadPropertyString(self::ICCR_PROPERTY_NOTIFIERS);
        if ($notifiers !== '') {
            //Annotate existing elements
            $notifiers = json_decode($notifiers, true);
            foreach ($notifiers as $notifier) {
                $listValues[] = ['Ident' => 'Ident todo',
                    'Notifier' => 'Notifier todo',
                    'Eventtext' => $notifier['Eventtext'],
                    'Prenotify' => $notifier['Prenotify'],
                    'Postnotify' => $notifier['Postnotify']];
                //We only need to add annotations. Remaining data is merged from persistance automatically.
                //Order is determinted by the order of array elements
                /*                if (IPS_InstanceExists($shutter['InstanceID'])) {
                                    $listValues[] = [
                                        'InstanceID' => $shutter['InstanceID'],
                                        'ObjectID'   => $shutter['InstanceID'],
                                        'Location'   => IPS_GetLocation($shutter['InstanceID']),
                                        'Selected'   => $shutter['Selected']];
                                } else {
                                    $listValues[] = [
                                        'InstanceID' => $shutter['InstanceID'],
                                        'ObjectID'   => $shutter['InstanceID'],
                                        'Location'   => 'Not found!',
                                        'Selected'   => $shutter['Selected']];
                                }
                */
            }
        }
        return $listValues;
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
    private function CheckCalendarURLSyntax(): bool
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
    public function LoadCalendarURL(string &$curl_result): int
    {
        $instStatus = IS_ACTIVE;
        $url        = $this->ReadPropertyString(self::ICCR_PROPERTY_CALENDAR_URL);
        $username   = $this->ReadPropertyString(self::ICCR_PROPERTY_USERNAME);
        $password   = $this->ReadPropertyString(self::ICCR_PROPERTY_PASSWORD);

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
                    $instStatus = self::STATUS_INST_INVALID_URL;
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
                    $instStatus = self::STATUS_INST_SSL_ERROR;
                    break;
                case 67:
                    // auth error
                    $instStatus = self::STATUS_INST_INVALID_USER_PASSWORD;
                    break;
                default:
                    // connect error
                    $instStatus = self::STATUS_INST_CONNECTION_ERROR;
                    break;
            }
        } // no curl error, continue
        elseif (strpos($curl_result, 'BEGIN:VCALENDAR') === false) {
            // handle error document
            $instStatus = self::STATUS_INST_UNEXPECTED_RESPONSE;

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
                    $instStatus = self::STATUS_INST_INVALID_USER_PASSWORD;
                }
            } // synology sends plain text
            else if (strpos($curl_result, 'Please log in') === 0) {
                $this->Logger_Err('Error logging on - invalid user/password combination for ' . $url);
                $instStatus = self::STATUS_INST_INVALID_USER_PASSWORD;
            } // everything else goes here
            else {
                $this->Logger_Err('Error on connect - this is not a valid calendar URL: ' . $url);
                $instStatus = self::STATUS_INST_INVALID_URL;
            }
        }

        if ($instStatus === IS_ACTIVE) {
            $this->Logger_Dbg(__FUNCTION__, 'curl_result: ' . $curl_result);
            $this->Logger_Dbg(__FUNCTION__, 'Successfully loaded');
        } elseif (!empty($curl_result)) {
            $this->Logger_Dbg(__FUNCTION__, 'Error, curl_result: ' . $curl_result);
        }
        return $instStatus;
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

        $MyImporter        = new iCalImporter(
            $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHEBACK), $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHE),
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

    public function UpdateCalendar(): bool
    {
        $this->Logger_Dbg(__FUNCTION__, sprintf('Entering %s()', __FUNCTION__));

        if (!$this->ReadPropertyBoolean('active')) {
            $this->Logger_Dbg(__FUNCTION__, 'Instance is inactive');
            return false;
        }

        $TheOldCalendar = $this->ReadAttributeString('CalendarBuffer');
        $TheNewCalendar = $this->ReadCalendar();
        $this->Logger_Dbg(__FUNCTION__, sprintf('Buffered Calendar: %s', $TheOldCalendar));
        $this->Logger_Dbg(__FUNCTION__, sprintf('New Calendar: %s', $TheNewCalendar));


        if ($TheNewCalendar === null) {
            $this->Logger_Dbg(__FUNCTION__, 'Failed to load calendar');
            return false;
        }
        if (strcmp($TheOldCalendar, $TheNewCalendar) !== 0) {
            $this->Logger_Dbg(__FUNCTION__, 'Updating internal calendar');
            $this->WriteAttributeString('CalendarBuffer', $TheNewCalendar);
        } else {
            $this->Logger_Dbg(__FUNCTION__, 'Calendar still in sync');
        }
        return true;
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


