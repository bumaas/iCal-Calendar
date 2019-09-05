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
    private const STATUS_INST_INVALID_URL           = 201;
    private const STATUS_INST_SSL_ERROR             = 202;
    private const STATUS_INST_INVALID_USER_PASSWORD = 203;
    private const STATUS_INST_CONNECTION_ERROR      = 204;
    private const STATUS_INST_UNEXPECTED_RESPONSE   = 205;

    private const ICCR_PROPERTY_CALENDAR_URL                       = 'CalendarServerURL';
    private const ICCR_PROPERTY_USERNAME                           = 'Username';
    private const ICCR_PROPERTY_PASSWORD                           = 'Password';
    private const ICCR_PROPERTY_DAYSTOCACHE                        = 'DaysToCache';
    private const ICCR_PROPERTY_DAYSTOCACHEBACK                    = 'DaysToCacheBack';
    private const ICCR_PROPERTY_UPDATE_FREQUENCY                   = 'UpdateFrequency';
    private const ICCR_PROPERTY_WRITE_DEBUG_INFORMATION_TO_LOGFILE = 'WriteDebugInformationToLogfile';
    private const ICCR_PROPERTY_NOTIFIERS                          = 'Notifiers';
    private const ICCR_PROPERTY_NOTIFIER_IDENT                     = 'Ident';
    private const ICCR_PROPERTY_NOTIFIER_NAME                      = 'Name';
    private const ICCR_PROPERTY_NOTIFIER_FIND                      = 'Find';
    private const ICCR_PROPERTY_NOTIFIER_REGEXPRESSION             = 'RegExpression';
    private const ICCR_PROPERTY_NOTIFIER_PRENOTIFY                 = 'Prenotify';
    private const ICCR_PROPERTY_NOTIFIER_POSTNOTIFY                = 'Postnotify';

    private const ICCR_ATTRIBUTE_CALENDAR_BUFFER = 'CalendarBuffer';
    private const ICCR_ATTRIBUTE_NOTIFICATIONS   = 'Notifications';

    private const TIMER_CRON1          = 'Cron1';
    private const TIMER_UPDATECALENDAR = 'UpdateCalendar';

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
        $this->RegisterPropertyString(self::ICCR_PROPERTY_NOTIFIERS, json_encode([]));

        // create Attributes
        $this->RegisterAttributeString(self::ICCR_ATTRIBUTE_CALENDAR_BUFFER, json_encode([]));
        $this->RegisterAttributeString(self::ICCR_ATTRIBUTE_NOTIFICATIONS, json_encode([]));

        // create timer
        $this->RegisterTimer(self::TIMER_UPDATECALENDAR, 0, 'ICCR_UpdateCalendar($_IPS["TARGET"] );'); // cron runs every 5 minutes, when active
        $this->RegisterTimer(self::TIMER_CRON1, 0, 'ICCR_TriggerNotifications($_IPS["TARGET"] );'); // cron runs every minute

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    /*
        react on user configuration dialog
    */
    public function ApplyChanges(): bool
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return false;
        }

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
        if ($Status === IS_ACTIVE) {

            //print_r(json_decode($this->ReadPropertyString(self::ICCR_PROPERTY_NOTIFIERS), true));
            $prop          = [];
            $propNotifiers = json_decode($this->ReadPropertyString(self::ICCR_PROPERTY_NOTIFIERS), true);

            foreach ($propNotifiers as $key => $notifier) {
                //Anlegen eines neuen Notifiers
                if ($notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT] === $this->Translate('new')) {
                    $notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT] = 'NOTIFIER' . $this->GetNextFreeNotifier();
                }

                //Variable registrieren
                $this->RegisterVariableBoolean(
                    $notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT], $notifier[self::ICCR_PROPERTY_NOTIFIER_NAME], '~Switch'
                );

                //Variablennamen auslesen
                $notifier[self::ICCR_PROPERTY_NOTIFIER_NAME] =
                    IPS_GetObject($this->GetIDForIdent($notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT]))['ObjectName'];

                $prop[] = $notifier;
            }

            if (json_encode($prop) !== $this->ReadPropertyString(self::ICCR_PROPERTY_NOTIFIERS)) {
                IPS_SetProperty($this->InstanceID, self::ICCR_PROPERTY_NOTIFIERS, json_encode($prop));
                IPS_ApplyChanges($this->InstanceID);
            }

            $this->DeleteUnusedVariables($propNotifiers);
            $this->SetTimerInterval(self::TIMER_UPDATECALENDAR, $this->ReadPropertyInteger(self::ICCR_PROPERTY_UPDATE_FREQUENCY) * 1000 * 60);
            $this->SetTimerInterval(self::TIMER_CRON1, 1000 * 60);
            return true;
        }

        $this->SetTimerInterval(self::TIMER_CRON1, 0);
        return false;
    }

    private function GetNextFreeNotifier(): ?int
    {
        for ($i = 1; $i < 100; $i++) {
            if (@$this->GetIDForIdent('NOTIFIER' . $i) === false) {
                return $i;
            }
        }
        return null;
    }

    private function DeleteUnusedVariables(array $propNotifiers): void
    {
        $idents = array_column($propNotifiers, 'Ident');

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childrenId) {
            $obj = IPS_GetObject($childrenId);
            if ($obj['ObjectType'] === OBJECTTYPE_VARIABLE
                && strpos($obj['ObjectIdent'], 'NOTIFIER') === 0
                && !in_array($obj['ObjectIdent'], $idents, true)) {

                $idUtilControl = IPS_GetInstanceListByModuleID('{B69010EA-96D5-46DF-B885-24821B8C8DBD}')[0];
                if (empty(UC_FindReferences($idUtilControl, $childrenId))) {
                    $this->Logger_Dbg(__FUNCTION__, sprintf('Variable %s (#%s) gelöscht', $obj['ObjectName'], $childrenId));
                    IPS_DeleteVariable($childrenId);
                } else {
                    $this->Logger_Dbg(__FUNCTION__, sprintf('Variable %s (#%s) nicht gelöscht, da referenziert', $obj['ObjectName'], $childrenId));
                }
            }
        }
    }

    public function GetConfigurationForm()
    {
        $form['elements'] = [
            [
                'type'  => 'RowLayout',
                'items' => [
                    [
                        'type'    => 'Label',
                        'caption' => 'In this instance, the parameters for a single calendar access are set. The description of the individual parameters can be found in the documentation.'],
                    [
                        'type'    => 'Button',
                        'caption' => 'Show Documentation',
                        'onClick' => 'echo \'https://github.com/bumaas/iCal-Calendar/blob/master/readme.md\';',
                        'link'    => true]]],
            ['type' => 'CheckBox', 'name' => 'active', 'caption' => 'active']

        ];

        $form['elements'][] = [
            'type'    => 'ExpansionPanel',
            'caption' => 'Calendar access',
            'items'   => [
                ['type' => 'ValidationTextBox', 'name' => self::ICCR_PROPERTY_CALENDAR_URL, 'caption' => 'Calendar URL'],
                ['type' => 'ValidationTextBox', 'name' => self::ICCR_PROPERTY_USERNAME, 'caption' => 'Username'],
                ['type' => 'PasswordTextBox', 'name' => self::ICCR_PROPERTY_PASSWORD, 'caption' => 'Password']]];

        $form['elements'][] = [
            'type'    => 'ExpansionPanel',
            'caption' => 'Synchronization',
            'items'   => [
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type'    => 'NumberSpinner',
                            'name'    => self::ICCR_PROPERTY_DAYSTOCACHEBACK,
                            'caption' => 'Cache size (Past)',
                            'suffix'  => 'days'],
                        [
                            'type'    => 'NumberSpinner',
                            'name'    => self::ICCR_PROPERTY_DAYSTOCACHE,
                            'caption' => 'Cache size (Future)',
                            'suffix'  => 'days']]]]];

        $form['elements'][] = [
            'type'     => 'List',
            'name'     => self::ICCR_PROPERTY_NOTIFIERS,
            'caption'  => 'Notifiers',
            'rowCount' => '15',
            'add'      => true,
            'delete'   => true,
            'sort'     => ['column' => self::ICCR_PROPERTY_NOTIFIER_NAME, 'direction' => 'ascending'],
            'columns'  => [
                [
                    'caption' => 'Ident',
                    'name'    => self::ICCR_PROPERTY_NOTIFIER_IDENT,
                    'width'   => '100px',
                    'add'     => $this->Translate('new'),
                    'save'    => true],
                [
                    'caption' => 'Name',
                    'name'    => self::ICCR_PROPERTY_NOTIFIER_NAME,
                    'width'   => '150px',
                    'add'     => '',
                    'edit'    => ['type' => 'ValidationTextBox']],
                [
                    'caption' => 'Find',
                    'name'    => self::ICCR_PROPERTY_NOTIFIER_FIND,
                    'width'   => '150px',
                    'add'     => '',
                    'edit'    => ['type' => 'ValidationTextBox']],
                [
                    'caption' => 'Regular Expression',
                    'name'    => self::ICCR_PROPERTY_NOTIFIER_REGEXPRESSION,
                    'width'   => '100px',
                    'add'     => false,
                    'edit'    => ['type' => 'CheckBox']],
                [
                    'caption' => 'Prenotify',
                    'name'    => self::ICCR_PROPERTY_NOTIFIER_PRENOTIFY,
                    'width'   => '100px',
                    'add'     => 0,
                    'edit'    => ['type' => 'NumberSpinner', 'suffix' => ' minutes']],
                [
                    'caption' => 'Postnotify',
                    'name'    => self::ICCR_PROPERTY_NOTIFIER_POSTNOTIFY,
                    'width'   => '100px',
                    'add'     => 0,
                    'edit'    => ['type' => 'NumberSpinner', 'suffix' => ' minutes']]]];

        $form['elements'][] = [
            'type'    => 'ExpansionPanel',
            'caption' => 'Expert Parameters',

            'items' => [
                [
                    'type'    => 'NumberSpinner',
                    'name'    => self::ICCR_PROPERTY_UPDATE_FREQUENCY,
                    'caption' => 'Update Interval',
                    'suffix'  => 'Minutes'],
                [
                    'type'    => 'CheckBox',
                    'name'    => self::ICCR_PROPERTY_WRITE_DEBUG_INFORMATION_TO_LOGFILE,
                    'caption' => 'Debug information are written additionally to standard logfile']]];

        $form['actions'] = [
            [
                'type'    => 'Button',
                'caption' => 'Load calendar',
                'onClick' => '
                 $module = new IPSModule($id);
                 $calendarReturn = ICCR_UpdateCalendar($id);
                 if ($calendarReturn === null){
                    echo $module->Translate("Error!");
                 } else {
                    $calendarEntries = json_decode($calendarReturn, true);
                    if (count($calendarEntries)){
                        echo $module->Translate("The following dates are read:") . PHP_EOL . PHP_EOL;
                        print_r($calendarEntries);
                    } else { 
                        echo $module->Translate("No dates are found");
                    }
                 }
                 '],
            [
                'type'    => 'Button',
                'caption' => 'Check Notifications',
                'onClick' => '$module = new IPSModule($id); ICCR_TriggerNotifications($id); echo $module->Translate("Finished!");'],
            [
                'type'  => 'RowLayout',
                'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'Pattern', 'caption' => 'Pattern'],
                    ['type' => 'ValidationTextBox', 'name' => 'Subject', 'caption' => 'Subject'],
                    [
                        'type'    => 'Button',
                        'caption' => 'Test Regular Expression',
                        'onClick' => '$module = new IPSModule($id);if (@preg_match($Pattern, $Subject)){echo $module->Translate("Hit!");} else {echo $module->Translate("No Hit!");}']]]];

        $form['status'] = [
            ['code' => self::STATUS_INST_INVALID_URL, 'icon' => 'error', 'caption' => 'Invalid URL, see log for details'],
            ['code' => self::STATUS_INST_SSL_ERROR, 'icon' => 'error', 'caption' => 'SSL error, see log for details'],
            ['code' => self::STATUS_INST_INVALID_USER_PASSWORD, 'icon' => 'error', 'caption' => 'Invalid user or password'],
            ['code' => self::STATUS_INST_CONNECTION_ERROR, 'icon' => 'error', 'caption' => 'Connection error, see log for details'],
            ['code' => self::STATUS_INST_UNEXPECTED_RESPONSE, 'icon' => 'error', 'caption' => 'Unexpected response from calendar server']];

        return json_encode($form);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->Logger_Dbg(__FUNCTION__, 'SenderID: ' . $SenderID . ', Message: ' . $Message . ', Data:' . json_encode($Data));
        /** @noinspection DegradedSwitchInspection */
        switch ($Message) {
            case IPS_KERNELMESSAGE:
                if ($Data[0] === KR_READY) {
                    $this->ApplyChanges();
                }
        }
    }

    /*
        check if calendar URL syntax is valid
    */
    private function CheckCalendarURLSyntax(): bool
    {
        $this->Logger_Dbg(__FUNCTION__, sprintf('Entering %s()', __FUNCTION__));

        // validate saved properties
        $calendarServerURL = $this->ReadPropertyString(self::ICCR_PROPERTY_CALENDAR_URL);
        return ($calendarServerURL !== '') && filter_var($calendarServerURL, FILTER_VALIDATE_URL);
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
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 5); // educated guess
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        if ($username !== '') {
            curl_setopt($curl, CURLOPT_USERPWD, $username . ':' . $password);
        }

        $curl_result = curl_exec($curl);

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
            elseif (strpos($curl_result, 'Please log in') === 0) {
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
        $this->SetStatus($result);

        if ($result !== IS_ACTIVE) {
            return null;
        }

        $MyImporter        = new iCalImporter(
            $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHEBACK), $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHE),
            function (string $message, string $data) {
                $this->Logger_Dbg($message, $data);
            }, function (string $message) {
            $this->Logger_Err($message);
        }
        );
        $iCalCalendarArray = $MyImporter->ImportCalendar($curl_result);
        return json_encode($iCalCalendarArray);
    }

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

        if ($this->ReadPropertyBoolean(self::ICCR_PROPERTY_WRITE_DEBUG_INFORMATION_TO_LOGFILE)) {
            $this->LogMessage(sprintf('%s: %s', $message, $data), KL_DEBUG);
        }
    }

    public function UpdateCalendar(): ?string
    {
        $this->Logger_Dbg(__FUNCTION__, sprintf('Entering %s()', __FUNCTION__));

        if (!$this->ReadPropertyBoolean('active')) {
            $this->Logger_Dbg(__FUNCTION__, 'Instance is inactive');
            return null;
        }

        $TheOldCalendar = $this->ReadAttributeString(self::ICCR_ATTRIBUTE_CALENDAR_BUFFER);
        $TheNewCalendar = $this->ReadCalendar();
        $this->Logger_Dbg(__FUNCTION__, sprintf('Buffered Calendar: %s', $TheOldCalendar));
        $this->Logger_Dbg(__FUNCTION__, sprintf('New Calendar: %s', $TheNewCalendar));

        if ($TheNewCalendar === null) {
            $this->Logger_Dbg(__FUNCTION__, 'Failed to load calendar');
            return null;
        }
        if (strcmp($TheOldCalendar, $TheNewCalendar) !== 0) {
            $this->Logger_Dbg(__FUNCTION__, 'Updating internal calendar');
            $this->WriteAttributeString(self::ICCR_ATTRIBUTE_CALENDAR_BUFFER, $TheNewCalendar);
        } else {
            $this->Logger_Dbg(__FUNCTION__, 'Calendar still in sync');
        }
        return $TheNewCalendar;
    }

    /*
        check if event is triggering a presence notification
    */
    private function CheckPresence(string $calDescription, int $calStart, int $calEnd, string $notFind, bool $notRegExpression, int $notPre,
                                   int $notPost): bool
    {
        $ts = time();
        $this->Logger_Dbg(
            __FUNCTION__, sprintf(
                            'Now: %s, Start: %s, End: %s, Pre: %s, $Post: %s', $this->formatDate($ts), $this->formatDate($calStart),
                            $this->formatDate($calEnd), $notPre, $notPost
                        )
        );

        if ((($calStart - $notPre) < $ts) && (($calEnd + $notPost) > $ts)) {
            $this->Logger_Dbg(__FUNCTION__, sprintf('find: \'%s\', description: \'%s\'', $notFind, $calDescription));
            if ($calDescription !== '' && $notFind !== '') {
                if ($notRegExpression) {
                    return @preg_match($notFind, $calDescription) !== false;
                }
                $this->Logger_Dbg(__FUNCTION__, sprintf('strpos: %s', (int) strpos($notFind, $calDescription)));
                return strpos($calDescription, $notFind) !== false;
            }
            return $notFind === '';
        }

        return false;
    }

    private function formatDate(int $ts): string
    {
        return date('Y-m-d H:i:s', $ts);
    }

    /*
        entry point for the periodic 1m notifications timer
        also used to trigger manual updates after configuration changes
        accessible for external scripts
    */
    public function TriggerNotifications(): void
    {
        $this->Logger_Dbg(__FUNCTION__, 'Entering TriggerNotifications()');

        $Notifiers = json_decode($this->ReadPropertyString(self::ICCR_PROPERTY_NOTIFIERS), true);
        if (empty($Notifiers)) {
            return;
        }

        $this->Logger_Dbg(__FUNCTION__, 'Processing notifications');
        $notifications = [];
        foreach ($Notifiers as $notifier) {
            $this->Logger_Dbg(__FUNCTION__, 'Process notifier: ' . json_encode($notifier));
            $active                            = false;
            $notifications[$notifier['Ident']] = [];
            foreach (json_decode($this->ReadAttributeString(self::ICCR_ATTRIBUTE_CALENDAR_BUFFER), true) as $iCalItem) {
                $active = $this->CheckPresence(
                    $iCalItem['Name'], $iCalItem['From'], $iCalItem['To'], $notifier['Find'], $notifier['RegExpression'],
                    $notifier[self::ICCR_PROPERTY_NOTIFIER_PRENOTIFY] * 60, $notifier[self::ICCR_PROPERTY_NOTIFIER_POSTNOTIFY] * 60
                );
                $this->Logger_Dbg(__FUNCTION__, 'Result: ' . (int) $active);
                if ($active) {
                    $notifications[$notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT]] = $iCalItem;
                    break;
                }
            }
            $idNotifier = @$this->GetIDForIdent($notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT]);
            if ($idNotifier && ($this->GetValue($notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT]) !== $active)) {
                $this->Logger_Dbg(
                    __FUNCTION__, sprintf(
                                    'Ident \'%s\' (#%s) auf %s gesetzt', $notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT], $idNotifier, (int) $active
                                )
                );

                $this->SetValue($notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT], $active);
            }
        }

        $this->WriteAttributeString(self::ICCR_ATTRIBUTE_NOTIFICATIONS, json_encode($notifications));
    }

    /***********************************************************************
     * methods for script access
     ************************************************************************/

    /*
        returns the internal calendar structure
    */
    public function GetCachedCalendar(): string
    {
        $CalendarBuffer = $this->ReadAttributeString(self::ICCR_ATTRIBUTE_CALENDAR_BUFFER);
        $this->Logger_Dbg(__FUNCTION__, $CalendarBuffer);
        return $CalendarBuffer;
    }

    public function GetNotifierPresenceReason(string $ident): string
    {
        $this->Logger_Dbg(__FUNCTION__, sprintf('Notifications: %s', $this->ReadAttributeString(self::ICCR_ATTRIBUTE_NOTIFICATIONS)));

        $notifications = json_decode($this->ReadAttributeString(self::ICCR_ATTRIBUTE_NOTIFICATIONS), true)[$ident];
        return json_encode($notifications);
    }
}
