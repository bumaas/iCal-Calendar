<?php

/*
Anmerkungen: aktuelle iCalcreator-master Versionen gibt es unter https://github.com/iCalcreator/iCalcreator/commits/master
derzeit verwendet: v2.40.10

aber mit folgenden Modifikationen

src\Traits\ATTENDEEtrait.php    Zeile 128
            //    CalAddressFactory::assertCalAddress( $value ); //bumaas

scr\Util\DateTimeZoneFactory.php    ab Zeile 94

            if (strpos($tzString, '(UTC+01:00)') !== false){
                $tzString = str_replace('(UTC+01:00)', '(UTC +01:00)', $tzString);
                //bumaas: Exchange2016 reports "(UTC+01:00) Amsterdam ..." (SimonS)
                //echo sprintf('invalid DateTimeZone (without " ") was corrected: %s -> %s', $org, $tzString) . PHP_EOL;
            }
            if (strpos($tzString, '"') !== false){
                $tzString = str_replace('"', '', $tzString);
                echo sprintf('invalid character " found. %s -> %s', $org, $tzString) . PHP_EOL;
            }

src\Util\DateTimeFactory.php    ab Zeile 629

        if (8 > strlen( $string )){
            return false;
        }

        //bumaas: different check on 32 bit system
        if ((int) 10000000000 == 10000000000){//64 bit System?
            return ( false !== strtotime ( $string ));
        }

        if ((substr($string,0,8) > '19011213') && (substr($string,0,8) < '20380119')){
            return ( false !== strtotime ( $string ));
        }

        return true;

src\Util\CalAddressFactory.php  ab Zeile 90

        return; //bumaas
        //Example todo:
BEGIN:VEVENT
CREATED:20191229T194615Z
DTEND;TZID=Europe/Berlin:20201030T100000
DTSTAMP:20191229T194616Z
DTSTART;TZID=Europe/Berlin:20201030T090000
LAST-MODIFIED:20191229T194615Z
ORGANIZER;CN="Joachim Päper";EMAIL=j.p@p.com:/aODMyNTYxNz
 k4NjgzMjU2MVoHEZowxAfFMrUCmnQ2QkArD73WdqLg2rSemg8aiWIi/principal/
SEQUENCE:0
SUMMARY:🌺 - Impftermin absprechen
UID:C804A283-FAFE-4DE2-9E71-E64DCEF7D0A0
URL;VALUE=URI:
END:VEVENT
        //

src\Util\HttpFactory.php ab Zeile 149

    public static function assertUrl( $url )
    {
        return;

src\Util\StringFactory.php Zeile 387ff
            if (!isset($tmp[$x])){
                break;
                echo sprintf('bumaas %s-> inLen=%s, outLen=%s, x=%s, $tmp="%s"', __FUNCTION__, $inLen, $outLen, $x, $tmp). PHP_EOL;
            }

            //bumaas: sometimes the TZID string containes '"'
            $propAttr[IcalInterface::TZID] = trim( $propAttr[IcalInterface::TZID], '"' );

src\Util\RegulateTimezoneFactory
    322ff
     //bumaas: sometimes the TZID string containes '"'
     $propAttr[IcalInterface::TZID] = trim( $propAttr[IcalInterface::TZID], '"' );

    398ff
    //bumaas: the TZID string sometimes contains `\`
    $value = str_replace('\\', '', $value);

*/
declare(strict_types=1);

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
class iCalCalendarReader extends IPSModuleStrict
{
    private const STATUS_INST_INVALID_URL           = 201;
    private const STATUS_INST_SSL_ERROR             = 202;
    private const STATUS_INST_INVALID_USER_PASSWORD = 203;
    private const STATUS_INST_CONNECTION_ERROR      = 204;
    private const STATUS_INST_UNEXPECTED_RESPONSE   = 205;
    private const STATUS_INST_INVALID_MEDIA_CONTENT = 206;
    private const STATUS_INST_OPERATION_TIMED_OUT = 207;

    private const ICCR_PROPERTY_ACTIVE                             = 'active';
    private const ICCR_PROPERTY_CALENDAR_URL                       = 'CalendarServerURL';
    private const ICCR_PROPERTY_USERNAME                           = 'Username';
    private const ICCR_PROPERTY_PASSWORD                           = 'Password';
    private const ICCR_PROPERTY_DISABLE_SSL_VERIFYPEER             = 'DisableSSLVerifyPeer';
    private const ICCR_PROPERTY_DAYSTOCACHE                        = 'DaysToCache';
    private const ICCR_PROPERTY_DAYSTOCACHEBACK                    = 'DaysToCacheBack';
    private const ICCR_PROPERTY_UPDATE_FREQUENCY                   = 'UpdateFrequency';
    private const ICCR_PROPERTY_WRITE_DEBUG_INFORMATION_TO_LOGFILE = 'WriteDebugInformationToLogfile';
    private const ICCR_PROPERTY_ICAL_MEDIA_ID                      = 'iCalMediaID';

    private const ICCR_PROPERTY_NOTIFIERS              = 'Notifiers';
    private const ICCR_PROPERTY_NOTIFIER_IDENT         = 'Ident';
    private const ICCR_PROPERTY_NOTIFIER_NAME          = 'Name';
    private const ICCR_PROPERTY_NOTIFIER_FIND          = 'Find';
    private const ICCR_PROPERTY_NOTIFIER_REGEXPRESSION = 'RegExpression';
    private const ICCR_PROPERTY_NOTIFIER_PRENOTIFY     = 'Prenotify';
    private const ICCR_PROPERTY_NOTIFIER_POSTNOTIFY    = 'Postnotify';

    private const ICCR_ATTRIBUTE_CALENDAR_BUFFER = 'CalendarBuffer';
    private const ICCR_ATTRIBUTE_NOTIFICATIONS   = 'Notifications';

    private const TIMER_TRIGGERNOTIFICATIONS = 'TriggerCalendarNotifications';
    private const TIMER_UPDATECALENDAR       = 'UpdateCalendar';

    /***********************************************************************
     * standard module methods
     ************************************************************************/

    /*
        basic setup
    */
    public function __construct($InstanceID)
    {
        ini_set('memory_limit', '256M');

        parent::__construct($InstanceID);
    }

    public function Create():void
    {
        parent::Create();

        // create configuration properties
        $this->RegisterPropertyBoolean(self::ICCR_PROPERTY_ACTIVE, true);
        $this->RegisterPropertyString(self::ICCR_PROPERTY_CALENDAR_URL, '');
        $this->RegisterPropertyString(self::ICCR_PROPERTY_USERNAME, '');
        $this->RegisterPropertyString(self::ICCR_PROPERTY_PASSWORD, '');
        $this->RegisterPropertyBoolean(self::ICCR_PROPERTY_DISABLE_SSL_VERIFYPEER, false);
        $this->RegisterPropertyInteger(self::ICCR_PROPERTY_ICAL_MEDIA_ID, 0);

        $this->RegisterPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHE, 30);
        $this->RegisterPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHEBACK, 30);
        $this->RegisterPropertyInteger(self::ICCR_PROPERTY_UPDATE_FREQUENCY, 15);
        $this->RegisterPropertyBoolean(self::ICCR_PROPERTY_WRITE_DEBUG_INFORMATION_TO_LOGFILE, false);
        $this->RegisterPropertyString(self::ICCR_PROPERTY_NOTIFIERS, json_encode([], JSON_THROW_ON_ERROR));

        // create Attributes
        $this->RegisterAttributeString(self::ICCR_ATTRIBUTE_CALENDAR_BUFFER, json_encode([], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ICCR_ATTRIBUTE_NOTIFICATIONS, json_encode([], JSON_THROW_ON_ERROR));

        // create timer
        $this->RegisterTimer(self::TIMER_UPDATECALENDAR, 0, 'ICCR_UpdateCalendar($_IPS["TARGET"] );'); // timer to fetch the calendar data
        $this->RegisterTimer(self::TIMER_TRIGGERNOTIFICATIONS, 0, 'ICCR_TriggerNotifications($_IPS["TARGET"] );'); // timer to trigger the notifications

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    /*
        react on the user configuration dialog
    */
    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        if ($this->ReadPropertyBoolean(self::ICCR_PROPERTY_ACTIVE)) {
            //validate Configuration
            if ($this->CheckCalendarMediaID()){
                $Status = IS_ACTIVE;
            } elseif (!$this->CheckCalendarURLSyntax()) {
                $Status = self::STATUS_INST_INVALID_URL;
            } else {
                $curl_result = '';
                $Status      = $this->LoadCalendarURL($curl_result);
            }
            $this->SetStatus($Status);

        } else {
            $Status = IS_INACTIVE;
        }

        $iCalMediaID = $this->ReadPropertyInteger(self::ICCR_PROPERTY_ICAL_MEDIA_ID);
        if ($iCalMediaID >= 10000){
            $this->SetSummary(IPS_GetName($iCalMediaID));
        } else {
            $this->SetSummary($this->ReadPropertyString(self::ICCR_PROPERTY_CALENDAR_URL));
        }

        $this->SetStatus($Status);

        $propNotifiers = json_decode($this->ReadPropertyString(self::ICCR_PROPERTY_NOTIFIERS), true, 512, JSON_THROW_ON_ERROR);

        $this->DeleteUnusedVariables($propNotifiers);

        //Meldevariablen registrieren
        foreach ($propNotifiers as $notifier) {
            if (str_starts_with($notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT], 'NOTIFIER')){
                if ($this->RegisterVariableBoolean(
                    $notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT],
                    sprintf('%s (%s)',$this->Translate('Notifier'), substr($notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT], 8)),
                    '~Switch', 0
                )){
                    $this->Logger_Dbg(__FUNCTION__, sprintf('Variable %s registriert', $notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT]));
                } else {
                    $this->Logger_Dbg(__FUNCTION__, sprintf('Variable %s konnte nicht registriert werden!', $notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT]));
                }
            }
        }

        $this->RegisterReferences();

        if ($Status !== IS_ACTIVE) {
            $this->SetTimerInterval(self::TIMER_TRIGGERNOTIFICATIONS, 0);
            return;
        }

        $this->SetTimerInterval(self::TIMER_UPDATECALENDAR, $this->ReadPropertyInteger(self::ICCR_PROPERTY_UPDATE_FREQUENCY) * 1000 * 60);
        $this->SetTimerInterval(self::TIMER_TRIGGERNOTIFICATIONS, 1000 * 60); //jede Minute werden die Notifications getriggert

    }

    private function RegisterReferences(): void
    {
        $objectIDs = [
            $this->ReadPropertyInteger(self::ICCR_PROPERTY_ICAL_MEDIA_ID),
        ];

        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }

        foreach ($objectIDs as $id) {
            if ($id !== 0) {
                $this->RegisterReference($id);
            }
        }
    }

    private function GetNextFreeNotifierNumber(array $usedIdents): ?int
    {
        for ($i = 1; $i < 100; $i++) {
            $nextIdent = 'NOTIFIER' . $i;
            if (!in_array($nextIdent, $usedIdents, true) && (@$this->GetIDForIdent($nextIdent) >= 0)) {
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
                && str_starts_with($obj['ObjectIdent'], 'NOTIFIER')
                && !in_array($obj['ObjectIdent'], $idents, true)) {
                $idUtilControl = IPS_GetInstanceListByModuleID('{B69010EA-96D5-46DF-B885-24821B8C8DBD}')[0];
                if (empty(UC_FindReferences($idUtilControl, $childrenId))) {
                    $this->Logger_Dbg(__FUNCTION__, sprintf('Variable %s (#%s) gelöscht', $obj['ObjectName'], $childrenId));
                    IPS_DeleteVariable($childrenId);
                } else {
                    $this->Logger_Dbg(
                        __FUNCTION__,
                        sprintf(
                            'Variable %s (#%s) nicht gelöscht, da referenziert (%s)',
                            $obj['ObjectName'],
                            $childrenId,
                            print_r(UC_FindReferences($idUtilControl, $childrenId), true)
                        )
                    );
                }
            }
        }
    }

    public function GetConfigurationForm(): string
    {
        $form['elements'] = [
            [
                'type'    => 'Label',
                'caption' => 'In this instance, the parameters for a single calendar access are set.'
            ],
            ['type' => 'CheckBox', 'name' => self::ICCR_PROPERTY_ACTIVE, 'caption' => 'active']
        ];

        $form['elements'][] = [
            'type'    => 'ExpansionPanel',
            'caption' => 'Calendar access',
            'items'   => [
                ['type' => 'ValidationTextBox', 'name' => self::ICCR_PROPERTY_CALENDAR_URL, 'caption' => 'Calendar URL'],
                ['type' => 'ValidationTextBox', 'name' => self::ICCR_PROPERTY_USERNAME, 'caption' => 'Username'],
                ['type' => 'PasswordTextBox', 'name' => self::ICCR_PROPERTY_PASSWORD, 'caption' => 'Password'],
                ['type' => 'CheckBox', 'name' => self::ICCR_PROPERTY_DISABLE_SSL_VERIFYPEER, 'caption' => 'Disable Verification of SSL Certificate'],
                ['type' => 'Label', 'caption' => 'As an alternative to a URL, a calendar file stored in a media object can also be specified:'],
                ['type' => 'SelectMedia', 'name' => self::ICCR_PROPERTY_ICAL_MEDIA_ID]
            ]
        ];

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
                            'suffix'  => 'days',
                            'minimum' => 0
                        ],
                        [
                            'type'    => 'NumberSpinner',
                            'name'    => self::ICCR_PROPERTY_DAYSTOCACHE,
                            'caption' => 'Cache size (Future)',
                            'suffix'  => 'days',
                            'minimum' => 0
                        ]
                    ]
                ]
            ]
        ];

        $form['elements'][] = [
            'type'     => 'List',
            'name'     => self::ICCR_PROPERTY_NOTIFIERS,
            'caption'  => 'Notifiers',
            'rowCount' => '15',
            'add'      => true,
            'delete'   => true,
            'sort'     => ['column' => self::ICCR_PROPERTY_NOTIFIER_NAME, 'direction' => 'ascending'],
            'onAdd'    => sprintf('IPS_RequestAction($id, "%s_onAdd", json_encode(array_values((array) $%s)[2]));', self::ICCR_PROPERTY_NOTIFIERS, self::ICCR_PROPERTY_NOTIFIERS),
            'columns'  => [
                [
                    'caption' => 'Ident',
                    'name'    => self::ICCR_PROPERTY_NOTIFIER_IDENT,
                    'visible' => false,
                    'add'     => '',
                    'save'    => true
                ],
                [
                    'caption' => 'Name',
                    'name'    => self::ICCR_PROPERTY_NOTIFIER_NAME,
                    'width'   => 'auto',
                    'add'     => 'new',
                    'save'    => false
                ],
                [
                    'caption' => 'Find',
                    'name'    => self::ICCR_PROPERTY_NOTIFIER_FIND,
                    'width'   => '150px',
                    'add'     => '',
                    'edit'    => ['type' => 'ValidationTextBox']
                ],
                [
                    'caption' => 'Regular Expression',
                    'name'    => self::ICCR_PROPERTY_NOTIFIER_REGEXPRESSION,
                    'width'   => '100px',
                    'add'     => false,
                    'edit'    => ['type' => 'CheckBox']
                ],
                [
                    'caption' => 'Prenotify',
                    'name'    => self::ICCR_PROPERTY_NOTIFIER_PRENOTIFY,
                    'width'   => '100px',
                    'add'     => 0,
                    'edit'    => ['type' => 'NumberSpinner', 'suffix' => ' minutes']
                ],
                [
                    'caption' => 'Postnotify',
                    'name'    => self::ICCR_PROPERTY_NOTIFIER_POSTNOTIFY,
                    'width'   => '100px',
                    'add'     => 0,
                    'edit'    => ['type' => 'NumberSpinner', 'suffix' => ' minutes']
                ]
            ],
            'values' => $this->getNotifierListValues()
        ];

        $form['elements'][] = [
            'type'    => 'ExpansionPanel',
            'caption' => 'Expert Parameters',

            'items' => [
                [
                    'type'    => 'NumberSpinner',
                    'name'    => self::ICCR_PROPERTY_UPDATE_FREQUENCY,
                    'caption' => 'Update Interval',
                    'suffix'  => 'Minutes'
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => self::ICCR_PROPERTY_WRITE_DEBUG_INFORMATION_TO_LOGFILE,
                    'caption' => 'Debug information are written additionally to standard logfile'
                ]
            ]
        ];

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
                 '
            ],
            [
                'type'    => 'Button',
                'caption' => 'Check Notifications',
                'onClick' => '
                    $module = new IPSModule($id);
                    ICCR_TriggerNotifications($id);
                    echo $module->Translate("Finished!");
                ',
                'visible' => $this->GetStatus() === IS_ACTIVE,
            ],
            [
                'type'  => 'RowLayout',
                'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'Needle', 'caption' => 'Find'],
                    [
                        'type'    => 'Button',
                        'caption' => 'Search the calendar with a search string',
                        'onClick' => '
                            $calendar = json_decode(ICCR_GetCachedCalendar($id), true);
                            
                            $hits = 0;
                            $module = new IPSModule($id);
                            foreach ($calendar as $event){
                                if (str_contains($event[\'Name\'], $Needle)){
                                    echo sprintf (\'%s - %s\', date(\'d.m.Y h:i:s\', $event[\'From\']), $event[\'Name\']). PHP_EOL;
                                    $hits++;
                                } 
                            }

                            echo PHP_EOL . $hits . \' \' . $module->translate(\'Hits\') . PHP_EOL;                        '
                    ]
                ],
                'visible' => $this->GetStatus() === IS_ACTIVE,
            ],
            [
                'type'  => 'RowLayout',
                'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'Pattern2', 'caption' => 'Pattern'],
                    [
                        'type'    => 'Button',
                        'caption' => 'Search the calendar with a search pattern',
                        'onClick' => '
                            $calendar = json_decode(ICCR_GetCachedCalendar($id), true);
                            
                            $hits = 0;
                            $module = new IPSModule($id);
                            foreach ($calendar as $event){
                                if (@preg_match($Pattern2, $event[\'Name\'])){
                                    echo sprintf (\'%s - %s\', date(\'d.m.Y h:i:s\', $event[\'From\']), $event[\'Name\']). PHP_EOL;
                                    $hits++;
                                } 
                            }

                            echo PHP_EOL . $hits . \' \' . $module->translate(\'Hits\') . PHP_EOL;                        '
                    ]
                ],
                'visible' => $this->GetStatus() === IS_ACTIVE,
            ],
            [
                'type'  => 'RowLayout',
                'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'Pattern', 'caption' => 'Pattern'],
                    ['type' => 'ValidationTextBox', 'name' => 'Subject', 'caption' => 'Subject'],
                    [
                        'type'    => 'Button',
                        'caption' => 'Test Regular Expression',
                        'onClick' => '
                            $module = new IPSModule($id);
                            if (@preg_match($Pattern, $Subject)){
                                echo $module->Translate("Hit!");
                            } else {
                                echo $module->Translate("No Hit!");
                            }
                        '
                    ]
                ],
                'visible' => $this->GetStatus() === IS_ACTIVE,
            ]
        ];

        $form['status'] = [
            ['code' => self::STATUS_INST_INVALID_URL, 'icon' => 'error', 'caption' => 'Invalid URL, see log for details'],
            ['code' => self::STATUS_INST_SSL_ERROR, 'icon' => 'error', 'caption' => 'SSL error, see log for details'],
            ['code' => self::STATUS_INST_INVALID_USER_PASSWORD, 'icon' => 'error', 'caption' => 'Invalid user or password'],
            ['code' => self::STATUS_INST_CONNECTION_ERROR, 'icon' => 'error', 'caption' => 'Connection error, see log for details'],
            ['code' => self::STATUS_INST_UNEXPECTED_RESPONSE, 'icon' => 'error', 'caption' => 'Unexpected response from calendar server'],
            ['code' => self::STATUS_INST_INVALID_MEDIA_CONTENT, 'icon' => 'error', 'caption' => 'Media Document has invalid content'],
            ['code' => self::STATUS_INST_OPERATION_TIMED_OUT, 'icon' => 'error', 'caption' => 'Operation timed out']
        ];

        return json_encode($form, JSON_THROW_ON_ERROR);
    }

 public function RequestAction($Ident, $Value): void
 {
    $this->Logger_Dbg(__FUNCTION__, sprintf('Ident: %s, Value: %s', $Ident, $Value));

    switch ($Ident){
        case self::ICCR_PROPERTY_NOTIFIERS . '_onAdd':
            $notifiers = json_decode($Value, true, 512, JSON_THROW_ON_ERROR);
            foreach ($notifiers as $key=>$notifier){
                if ($notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT] === ''){
                    $notifiers[$key][self::ICCR_PROPERTY_NOTIFIER_IDENT] = 'NOTIFIER' . $this->GetNextFreeNotifierNumber(array_column($notifiers, self::ICCR_PROPERTY_NOTIFIER_IDENT));
                }
            }
            $this->UpdateFormField(self::ICCR_PROPERTY_NOTIFIERS, 'values', json_encode($notifiers, JSON_THROW_ON_ERROR));
            break;

        default:
            trigger_error(sprintf('unexpected Ident: %s', $Ident), E_USER_WARNING);
    }

 }

    private function getNotifierListValues():array
    {
        $savedNotifiers = json_decode($this->ReadPropertyString(self::ICCR_PROPERTY_NOTIFIERS), true, 512, JSON_THROW_ON_ERROR);

        //Sonderprüfung: Prüfen, ob Idents doppelt vorkommen. Sollte nicht sein, aber schon einmal gesehen ...
        $idents = array_column($savedNotifiers, self::ICCR_PROPERTY_NOTIFIER_IDENT);
        for ($i = count($idents) -1; $i > 0;$i--){
            if (in_array($idents[$i], array_slice($idents, 0, ($i - 1)), true)){
                $savedNotifiers[$i][self::ICCR_PROPERTY_NOTIFIER_IDENT] = '';
                $this->Logger_Err(sprintf('not unique ident \'%s\'', $idents[$i]));
            }
        }
        //Ende Sonderprüfung - kann später gelöscht werden, wenn es keine Vorkommnisse gab

        $listValues = [];

         foreach ($savedNotifiers as $notifier){
            $row = $notifier;
            $id = @$this->GetIDForIdent($notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT]);
            if ($id){
                $row[self::ICCR_PROPERTY_NOTIFIER_NAME] = IPS_GetObject($id)['ObjectName'];
            } else {
                $row[self::ICCR_PROPERTY_NOTIFIER_IDENT] = '';
                $row[self::ICCR_PROPERTY_NOTIFIER_NAME] = $this->Translate('invalid ident') . ' ' . $notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT];
            }
            $listValues[] = $row;
        }

        return $listValues;
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data):void
    {
        $this->Logger_Dbg(__FUNCTION__, 'SenderID: ' . $SenderID . ', Message: ' . $Message . ', Data:' . json_encode($Data, JSON_THROW_ON_ERROR));
        /** @noinspection DegradedSwitchInspection */
        switch ($Message) {
            case IPS_KERNELMESSAGE:
                if ($Data[0] === KR_READY) {
                    $this->ApplyChanges();
                }
        }
    }

    /*
    check if calendar Media Object is valid
*/
    private function CheckCalendarMediaID(): bool
    {
        $this->Logger_Dbg(__FUNCTION__, sprintf('Entering %s()', __FUNCTION__));

        // validate saved properties
        $iCalMediaID = $this->ReadPropertyInteger(self::ICCR_PROPERTY_ICAL_MEDIA_ID);

        if ($iCalMediaID < 10000){
            return false;
        }

        $objMedia = IPS_GetMedia($iCalMediaID);

        return (($objMedia['MediaType'] === MEDIATYPE_DOCUMENT) && $objMedia['MediaIsAvailable']);
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

    public function LoadCalendarFile(string &$content): int
    {
        $iCalMediaId = $this->ReadPropertyInteger(self::ICCR_PROPERTY_ICAL_MEDIA_ID);

        $content = base64_decode(@IPS_GetMediaContent($iCalMediaId));
        $this->Logger_Dbg(__FUNCTION__, sprintf('Media Document Content: %s', json_encode($content, JSON_THROW_ON_ERROR)));

        if ($content && (str_contains($content, 'BEGIN:VCALENDAR'))){
            return IS_ACTIVE;
        }

        $this->Logger_Dbg(__FUNCTION__, 'BEGIN:VCALENDAR not found');

        return self::STATUS_INST_INVALID_MEDIA_CONTENT;
    }
    /***********************************************************************
     * calendar loading and conversion methods
     ***********************************************************************
     *
     * @param string $content
     *
     * @return int
     */

    /*
        load calendar from URL into $this->curl_result, returns IPS status value
    */
    public function LoadCalendarURL(string &$content): int
    {
        $test = false;
        $instStatus = IS_ACTIVE;
        $url        = $this->ReadPropertyString(self::ICCR_PROPERTY_CALENDAR_URL);
        $username   = $this->ReadPropertyString(self::ICCR_PROPERTY_USERNAME);
        $password   = $this->ReadPropertyString(self::ICCR_PROPERTY_PASSWORD);

        $this->Logger_Dbg(__FUNCTION__, sprintf('Entering %s(\'%s\')', __FUNCTION__, $url));

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        if (stripos($url, 'https:') === 0) {
            if ($this->ReadPropertyBoolean(self::ICCR_PROPERTY_DISABLE_SSL_VERIFYPEER)) {
                /** @noinspection CurlSslServerSpoofingInspection */
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
                /** @noinspection CurlSslServerSpoofingInspection */
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            } else {
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
            }
        }
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_MAXREDIRS, 5); // educated guess
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115 Safari/537.36');

        if ($username !== '') {
            curl_setopt($curl, CURLOPT_USERPWD, $username . ':' . $password);
        }

        if ($test){
            $content = file_get_contents(__DIR__ . '/../docs/Examples/Testdaten/TestDaten_Joachim_Exception.txt');
            $this->Logger_Dbg(__FUNCTION__, sprintf('%s', strlen($content)));
            $content = str_replace('<CR><LF>', PHP_EOL, $content);
        } else {
            $content = curl_exec($curl);
        }

        $curl_error_nr  = curl_errno($curl);
        $curl_error_str = curl_error($curl);
        curl_close($curl);

        // check on curl error
        if ($curl_error_nr) {
            $this->Logger_Err(sprintf('Error (%s) on connect - %s for %s', $curl_error_nr, $curl_error_str, $url));
            // only differentiate between invalid, connect, SSL and auth
            switch ($curl_error_nr) {
                case CURLE_OPERATION_TIMEOUTED:
                case CURLE_SSL_CONNECT_ERROR:
                    $instStatus = self::STATUS_INST_OPERATION_TIMED_OUT;
                    break;
                case CURLE_UNSUPPORTED_PROTOCOL:
                case CURLE_URL_MALFORMAT:
                case CURLE_URL_MALFORMAT_USER:
                    // invalid URL
                    $instStatus = self::STATUS_INST_INVALID_URL;
                    break;
                case CURLE_SSL_ENGINE_NOTFOUND:
                case CURLE_SSL_ENGINE_SETFAILED:
                case CURLE_SSL_CERTPROBLEM:
                case CURLE_SSL_CIPHER:
                case CURLE_SSL_CACERT:
                case CURLE_SSL_CACERT_BADFILE:
                    // SSL error
                    $instStatus = self::STATUS_INST_SSL_ERROR;
                    break;
                case 67: //CURLE_LOGIN_DENIED
                    // auth error
                    $instStatus = self::STATUS_INST_INVALID_USER_PASSWORD;
                    break;
                default:
                    // connect error
                    $instStatus = self::STATUS_INST_CONNECTION_ERROR;
                    break;
            }
        } // no curl error, continue
        elseif (!str_contains($content, 'BEGIN:VCALENDAR')) {
            // handle error document
            $instStatus = self::STATUS_INST_UNEXPECTED_RESPONSE;

            // ownCloud sends XML error messages
            libxml_use_internal_errors(true);
            $XML = simplexml_load_string($content);

            // owncloud error?
            if ($XML !== false) {
                $XML->registerXPathNamespace('d', 'DAV:');
                if (count($XML->xpath('//d:error')) > 0) {
                    // XML error document
                    $children = $XML->children('http://sabredav.org/ns');
                    if (isset($children)){
                        $this->Logger_Err(sprintf('Error: %s - Message: %s', $children->exception, $children->message));
                    }
                    $instStatus = self::STATUS_INST_INVALID_USER_PASSWORD;
                }
            } // synology sends plain text
            elseif (str_starts_with($content, 'Please log in')) {
                $this->Logger_Err('Error logging on - invalid user/password combination for ' . $url);
                $instStatus = self::STATUS_INST_INVALID_USER_PASSWORD;
            } // everything else goes here
            else {
                $this->Logger_Err(sprintf('Error on connect - this is not a valid response (URL: %s, response: %s', $url, $content));
            }
        }

        if ($instStatus === IS_ACTIVE) {
            $this->Logger_Dbg(__FUNCTION__, 'curl_result: ' . $content);
            $this->Logger_Dbg(__FUNCTION__, 'Successfully loaded');
        } elseif (!empty($content)) {
            $this->Logger_Dbg(__FUNCTION__, 'Error, curl_result: ' . $content);
        } else {
            $this->Logger_Dbg(__FUNCTION__, 'Error, curl_result: empty');
        }
        return $instStatus;
    }

    /*
        load calendar, convert calendar, return event array of false
    */
    private function ReadCalendar(): ?string
    {
        $content = '';

        if ($this->ReadPropertyInteger(self::ICCR_PROPERTY_ICAL_MEDIA_ID) !== 0){
            $result      = $this->LoadCalendarFile($content);
        } else {
            $result      = $this->LoadCalendarURL($content);
        }

        $this->SetStatus($result);

        if ($result !== IS_ACTIVE) {
            return null;
        }

        $this->Logger_Dbg(
            __FUNCTION__,
            sprintf(
                'Calendar Statistic - Length: %s, VEVENT: %s, STANDARD: %s, VTIMEZONE: %s, DAYLIGHT: %s',
                strlen($content),
                substr_count($content, 'BEGIN:VEVENT'),
                substr_count($content, 'BEGIN:STANDARD'),
                substr_count($content, 'BEGIN:VTIMEZONE'),
                substr_count($content, 'BEGIN:DAYLIGHT')
            )
        );

        $MyImporter = new iCalImporter(
            $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHEBACK),
            $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHE),
            function (string $message, string $data) {
                $this->Logger_Dbg($message, $data);
            },
            function (string $message) {
                $this->Logger_Err($message);
            }
        );

        $iCalCalendarArray = $MyImporter->ImportCalendar($content);

        return json_encode($iCalCalendarArray, JSON_THROW_ON_ERROR + JSON_INVALID_UTF8_SUBSTITUTE);
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

        if (!in_array($this->GetStatus(), [IS_ACTIVE,
                                           self::STATUS_INST_OPERATION_TIMED_OUT,
                                           self::STATUS_INST_CONNECTION_ERROR,
                                           self::STATUS_INST_INVALID_MEDIA_CONTENT,
                                           self::STATUS_INST_SSL_ERROR], true)) {
            $this->Logger_Dbg(__FUNCTION__, 'Instance is not active');
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
        check if an event is triggering a presence notification
    */
    private function CheckPresence(
        string $calDescription,
        int $calStart,
        int $calEnd,
        string $notFind,
        bool $notRegExpression,
        int $notPre,
        int $notPost
    ): bool {
        $ts = time();
        $this->Logger_Dbg(
            __FUNCTION__,
            sprintf(
                '\'%s\' - Now: %s, Start: %s, End: %s, Pre: %s, $Post: %s',
                $calDescription,
                $this->formatDate($ts),
                $this->formatDate($calStart),
                $this->formatDate($calEnd),
                $notPre,
                $notPost
            )
        );

        if ((($calStart - $notPre) <= $ts) && (($calEnd + $notPost) > $ts)) {
            $this->Logger_Dbg(__FUNCTION__, sprintf('find: \'%s\', description: \'%s\'', $notFind, $calDescription));
            if ($calDescription !== '' && $notFind !== '') {
                if ($notRegExpression) {
                    return @preg_match($notFind, $calDescription) > 0;
                }
                $this->Logger_Dbg(__FUNCTION__, sprintf('strpos: %s', (int)strpos($notFind, $calDescription)));
                return str_contains($calDescription, $notFind);
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
        the entry point for the periodic 1m notifications timer
        also used to trigger manual updates after configuration changes
        accessible for external scripts
    */
    public function TriggerNotifications(): void
    {
        $this->Logger_Dbg(__FUNCTION__, 'Entering TriggerNotifications()');

        $Notifiers = json_decode($this->ReadPropertyString(self::ICCR_PROPERTY_NOTIFIERS), true, 512, JSON_THROW_ON_ERROR);
        if (empty($Notifiers)) {
            return;
        }

        $this->Logger_Dbg(__FUNCTION__, 'Processing notifications');
        $notifications = [];
        foreach ($Notifiers as $notifier) {
            $this->Logger_Dbg(__FUNCTION__, 'Process notifier: ' . json_encode($notifier, JSON_THROW_ON_ERROR));
            $active                            = false;
            $notifications[$notifier['Ident']] = [];
            foreach (json_decode($this->ReadAttributeString(self::ICCR_ATTRIBUTE_CALENDAR_BUFFER), true, 512, JSON_THROW_ON_ERROR) as $iCalItem) {
                $active = $this->CheckPresence(
                    $iCalItem['Name'],
                    $iCalItem['From'],
                    $iCalItem['To'],
                    $notifier['Find'],
                    $notifier['RegExpression'],
                    $notifier[self::ICCR_PROPERTY_NOTIFIER_PRENOTIFY] * 60,
                    $notifier[self::ICCR_PROPERTY_NOTIFIER_POSTNOTIFY] * 60
                );
                $this->Logger_Dbg(__FUNCTION__, 'Result: ' . (int)$active);
                if ($active) {
                    $notifications[$notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT]] = $iCalItem;
                    break;
                }
            }
            if ($idNotifier = @$this->GetIDForIdent($notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT])) {
                if ($this->GetValue($notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT]) !== $active){
                    $this->Logger_Dbg(
                        __FUNCTION__,
                        sprintf(
                            'Ident \'%s\' (#%s) auf %s gesetzt',
                            $notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT],
                            $idNotifier,
                            (int)$active
                        )
                    );
                }

                $this->SetValue($notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT], $active);
            }
        }

        $this->WriteAttributeString(self::ICCR_ATTRIBUTE_NOTIFICATIONS, json_encode($notifications, JSON_THROW_ON_ERROR));
    }

    /***********************************************************************
     * methods for script access
     ************************************************************************/

    /*
        returns the internal calendar structure
    */
    public function GetCachedCalendar(): string
    {
        if ($this->GetStatus() !== IS_ACTIVE) {
            return json_encode([], JSON_THROW_ON_ERROR);
        }
        $CalendarBuffer = $this->ReadAttributeString(self::ICCR_ATTRIBUTE_CALENDAR_BUFFER);
        $this->Logger_Dbg(__FUNCTION__, $CalendarBuffer);
        return $CalendarBuffer;
    }

    public function GetNotifierPresenceReason(string $ident): string
    {
        $this->Logger_Dbg(__FUNCTION__, sprintf('Notifications: %s', $this->ReadAttributeString(self::ICCR_ATTRIBUTE_NOTIFICATIONS)));

        $notifications = json_decode($this->ReadAttributeString(self::ICCR_ATTRIBUTE_NOTIFICATIONS), true, 512, JSON_THROW_ON_ERROR)[$ident];
        return json_encode($notifications, JSON_THROW_ON_ERROR);
    }
}