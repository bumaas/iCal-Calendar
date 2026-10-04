<?php

/** @noinspection AutoloadingIssuesInspection */

/*
Anmerkungen: aktuelle iCalcreator-Versionen gibt es unter https://github.com/iCalcreator/iCalcreator
derzeit verwendet: v2.41.92

aber mit folgenden Modifikationen (jeweils mit "bumaas" markiert):

src/Util/CalAddressFactory.php  assertCalAddress(): sofortiges return
    Kalender mit ungültigen ORGANIZER-/ATTENDEE-Adressen tolerieren
    (z. B. iCloud-Principal-URLs wie "ORGANIZER;...:/aODMyNTYxNz.../principal/")

src/Util/HttpFactory.php  assertUrl(): sofortiges return
    Kalender mit ungültigen URL-Properties tolerieren
    (z. B. "URL;VALUE=URI:message:%3C...", "URL;VALUE=URI:" leer)

src/Util/DateTimeZoneFactory.php  assertDateTimeZone(): class_exists-Guard um IntlTimeZone
    das Symcon-PHP hat kein ext-intl; ohne Guard würde der Windows-Zeitzonen-Fallback
    mit "Class IntlTimeZone not found" fatal enden statt InvalidArgumentException zu werfen

src/Util/DateTimeFactory.php  isStringAndDate(): 32-Bit-Sonderfall
    auf 32-Bit-Systemen scheitert strtotime außerhalb 1901-2038; solche Datumsangaben
    werden trotzdem als gültig akzeptiert

Die bis v2.40.10 gepatchte RegulateTimezoneFactory (Umschreibung von Windows-/Exchange-
Zeitzonen vor dem Parsen) ist mit 2.41.57 aus der Lib entfallen; ihre Aufgabe übernimmt
jetzt iCalImporter::regulateTimezones() (dort auch die früheren TZID-Bereinigungen
um '"' und '\' sowie das Mapping "(UTC+01:00) ..." -> PHP-Zeitzone).
 */
declare(strict_types=1);

// Autoloader für iCalcreator laden
require_once __DIR__ . '/../libs/iCalcreator-master/autoload.php';

// php-rrule manuell laden (da kein Autoloader vorhanden oder genutzt wird)
// Die Reihenfolge ist hier wichtig (Interfaces zuerst)
$rruleDir = __DIR__ . '/../libs/php-rrule-master/src/';
require_once $rruleDir . 'RRuleInterface.php';
require_once $rruleDir . 'RRuleTrait.php';
require_once $rruleDir . 'RfcParser.php';
require_once $rruleDir . 'RRule.php';
require_once $rruleDir . 'RSet.php';

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
    private const STATUS_INST_INVALID_NOTIFIERS     = 208;

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
    private const ICCR_ATTRIBUTE_LOGGED_PROBLEM  = 'LoggedProblem'; // zuletzt im Log gemeldete Störung, '' = keine
    private const ICCR_ATTRIBUTE_LOGGED_IMPORT   = 'LoggedImportProblems'; // Prüfsumme der gemeldeten Importprobleme, '' = keine

    private const DEBUG_MAX_BYTES    = 300; // längere Debug-Daten werden gekürzt
    private const IMPORT_DEBUG_LINES = 20;  // Debug-Zeilen des Importers je Abruf

    private const TIMER_TRIGGERNOTIFICATIONS = 'TriggerCalendarNotifications';
    private const TIMER_UPDATECALENDAR       = 'UpdateCalendar';

    /** Ursache der letzten gescheiterten Quelle (curl-Meldung, Serverantwort) für die Log-Meldung */
    protected string $errorDetail = '';

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
        $this->RegisterAttributeString(self::ICCR_ATTRIBUTE_LOGGED_PROBLEM, '');
        $this->RegisterAttributeString(self::ICCR_ATTRIBUTE_LOGGED_IMPORT, '');

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

        // determineStatus prüft nur die Konfiguration; gelesen wird unten genau einmal
        $Status = $this->determineStatus();

        $this->updateSummary();

        // bei ungültiger Liste die vorhandenen Variablen nicht anfassen (nichts löschen)
        $propNotifiers = $this->readNotifiers();
        if ($propNotifiers !== null) {
            $this->ValidateNotifierPatterns($propNotifiers);
            $this->syncNotifierVariables($propNotifiers);
        }

        $this->RegisterReferences();

        if ($Status !== IS_ACTIVE) {
            $this->setInstanceStatus($Status);
            $this->SetTimerInterval(self::TIMER_UPDATECALENDAR, 0);
            $this->SetTimerInterval(self::TIMER_TRIGGERNOTIFICATIONS, 0);
            return;
        }

        // Timer vor dem Lesen setzen: scheitert der Abruf, findet der in der Log-Warnung
        // angekündigte erneute Versuch trotzdem statt
        $this->SetTimerInterval(self::TIMER_UPDATECALENDAR, $this->ReadPropertyInteger(self::ICCR_PROPERTY_UPDATE_FREQUENCY) * 1000 * 60);
        $this->SetTimerInterval(self::TIMER_TRIGGERNOTIFICATIONS, 1000 * 60); //jede Minute werden die Notifications getriggert

        // Kalender sofort lesen und die Meldevariablen nachziehen - sonst schalten sie bis zum
        // nächsten Abruf (bis zu Stunden, bei Intervall 0 nie) nach dem alten oder leeren Cache
        $this->refreshCalendar();
        $this->TriggerNotifications();
    }

    /*
        Notifier-Liste lesen und prüfen: null, wenn die Property keine JSON-Liste von Einträgen
        ist (z. B. doppelt kodiert). Fehlende optionale Felder bekommen Standardwerte.
     */
    private function readNotifiers(): ?array
    {
        $list = json_decode($this->ReadPropertyString(self::ICCR_PROPERTY_NOTIFIERS), true);
        if (!is_array($list) || !array_is_list($list)) {
            return null;
        }
        $notifiers = [];
        foreach ($list as $row) {
            if (!is_array($row) || !isset($row[self::ICCR_PROPERTY_NOTIFIER_IDENT]) || !is_string($row[self::ICCR_PROPERTY_NOTIFIER_IDENT])) {
                return null;
            }
            $notifiers[] = [
                self::ICCR_PROPERTY_NOTIFIER_IDENT         => $row[self::ICCR_PROPERTY_NOTIFIER_IDENT],
                self::ICCR_PROPERTY_NOTIFIER_NAME          => (string) ($row[self::ICCR_PROPERTY_NOTIFIER_NAME] ?? ''),
                self::ICCR_PROPERTY_NOTIFIER_FIND          => (string) ($row[self::ICCR_PROPERTY_NOTIFIER_FIND] ?? ''),
                self::ICCR_PROPERTY_NOTIFIER_REGEXPRESSION => (bool) ($row[self::ICCR_PROPERTY_NOTIFIER_REGEXPRESSION] ?? false),
                self::ICCR_PROPERTY_NOTIFIER_PRENOTIFY     => (int) ($row[self::ICCR_PROPERTY_NOTIFIER_PRENOTIFY] ?? 0),
                self::ICCR_PROPERTY_NOTIFIER_POSTNOTIFY    => (int) ($row[self::ICCR_PROPERTY_NOTIFIER_POSTNOTIFY] ?? 0),
            ];
        }
        return $notifiers;
    }

    private function determineStatus(): int
    {
        if (!$this->ReadPropertyBoolean(self::ICCR_PROPERTY_ACTIVE)) {
            return IS_INACTIVE;
        }

        if ($this->CheckCalendarMediaID()) {
            return $this->readNotifiers() === null ? self::STATUS_INST_INVALID_NOTIFIERS : IS_ACTIVE;
        }

        if (!$this->CheckCalendarURLSyntax()) {
            return self::STATUS_INST_INVALID_URL;
        }

        return $this->readNotifiers() === null ? self::STATUS_INST_INVALID_NOTIFIERS : IS_ACTIVE;
    }

    private function updateSummary(): void
    {
        $iCalMediaID = $this->ReadPropertyInteger(self::ICCR_PROPERTY_ICAL_MEDIA_ID);
        if ($iCalMediaID >= 10000) {
            $this->SetSummary(IPS_GetName($iCalMediaID));
        } else {
            $this->SetSummary($this->ReadPropertyString(self::ICCR_PROPERTY_CALENDAR_URL));
        }
    }

    private function ValidateNotifierPatterns(array $propNotifiers): void
    {
        foreach ($propNotifiers as $notifier) {
            if (empty($notifier[self::ICCR_PROPERTY_NOTIFIER_REGEXPRESSION])) {
                continue;
            }

            $find = $notifier[self::ICCR_PROPERTY_NOTIFIER_FIND] ?? '';
            if ($find === '') {
                continue;
            }

            $normalized = $this->NormalizeRegexPattern($find);
            if (@preg_match($normalized, '') === false) {
                $ident = $notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT] ?? '';
                $this->LogMessage(
                    sprintf("Notifier '%s': invalid regular expression in Find '%s'", $ident, $find),
                    KL_WARNING
                );
            }
        }
    }

    private function syncNotifierVariables(array $propNotifiers): void
    {
        $this->DeleteUnusedVariables($propNotifiers);

        foreach ($propNotifiers as $notifier) {
            $ident = $notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT];
            if (str_starts_with($ident, 'NOTIFIER')) {
                $name = sprintf('%s (%s)', $this->Translate('Notifier'), substr($ident, 8));
                $this->RegisterVariableBoolean($ident, $name, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 0);
            }
        }
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
                'caption' => 'Run self test (changes nothing)',
                'onClick' => 'echo ICCR_RunSelfTest($id);'
            ],
            [
                'type'    => 'Label',
                'visible' => false,
                'caption' => 'For scripts: ICCR_RunSelfTest($InstanceID) returns a self test as text (source, import, cache, notifiers; one line per check, last line "N errors, M warnings"). It reads the calendar but changes nothing: no status, no variables, no cache.'
            ],
            [
                'type'    => 'Label',
                'visible' => false,
                'caption' => 'ICCR_UpdateCalendar($InstanceID) reads the calendar now, stores it as cache and returns all dates of the cache window as a JSON list (Name, Location, Description, Categories, From/To as Unix time, FromS/ToS as text, allDay, Status, UID, Alarms in seconds relative to From), or null if reading failed (the instance status says why). ICCR_GetCachedCalendar($InstanceID) returns the same list from the last read without contacting the server; it is empty while the instance is not active.'
            ],
            [
                'type'    => 'Label',
                'visible' => false,
                'caption' => 'Notifiers: each row of the list creates a boolean variable with the ident NOTIFIER<n>. It is true while a date matches, from "Prenotify" minutes before its start until "Postnotify" minutes after its end. "Find" is a case-sensitive part of the date title, or a PCRE pattern if "Regular Expression" is ticked (delimiters are added when missing); an empty "Find" matches every date. The notifiers are evaluated every minute against the cache; ICCR_TriggerNotifications($InstanceID) evaluates them now. ICCR_GetNotifierPresenceReason($InstanceID, "<ident, e.g. NOTIFIER1>") returns as JSON the date that made this notifier active at the last evaluation, or [] if it was inactive; an unknown ident is reported as error with the valid idents. To set the list by script, pass the JSON text of the list once: IPS_SetProperty($InstanceID, "Notifiers", json_encode([["Ident" => "NOTIFIER1", "Find" => "Papiertonne", "RegExpression" => false, "Prenotify" => 360, "Postnotify" => 0]])), then IPS_ApplyChanges($InstanceID); missing fields default to false/0, the variable name is not part of the list. Applying the changes reads the calendar and evaluates the notifiers at once.'
            ],
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
                'type'    => 'Label',
                'caption' => 'Example: Papiertonne',
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
                            $pattern = ICCR_NormalizeRegexPattern($id, $Pattern2);
                            $invalid = (@preg_match($pattern, "") === false);
                            foreach ($calendar as $event){
                                if (!$invalid && @preg_match($pattern, $event[\'Name\'])){
                                    echo sprintf (\'%s - %s\', date(\'d.m.Y h:i:s\', $event[\'From\']), $event[\'Name\']). PHP_EOL;
                                    $hits++;
                                } 
                            }

                            if ($invalid) {
                                echo $module->Translate("Invalid regular expression") . ": " . $pattern;
                            } else {
                                echo PHP_EOL . $hits . \' \' . $module->translate(\'Hits\') . PHP_EOL;
                            }                        '
                    ]
                ],
                'visible' => $this->GetStatus() === IS_ACTIVE,
            ],
            [
                'type'    => 'Label',
                'caption' => 'Example: (Papier|Bio)tonne',
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
                            $pattern = ICCR_NormalizeRegexPattern($id, $Pattern);
                            $result = @preg_match($pattern, $Subject);
                            if ($result === false) {
                                echo $module->Translate("Invalid regular expression") . ": " . $pattern;
                            } elseif ($result > 0) {
                                echo $module->Translate("Hit!");
                            } else {
                                echo $module->Translate("No Hit!");
                            }
                        '
                    ]
                ],
                'visible' => $this->GetStatus() === IS_ACTIVE,
            ],
            [
                'type'    => 'Label',
                'caption' => 'Example: Pattern (Papier|Bio)tonne, Subject Papiertonne',
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
            ['code' => self::STATUS_INST_OPERATION_TIMED_OUT, 'icon' => 'error', 'caption' => 'Operation timed out'],
            ['code' => self::STATUS_INST_INVALID_NOTIFIERS, 'icon' => 'error', 'caption' => 'Notifier list is invalid, see log for details']
        ];

        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    public function RequestAction($Ident, $Value): void
    {
        $this->Logger_Dbg(__FUNCTION__, sprintf('Ident: %s, Value: %s', $Ident, $Value));

        switch ($Ident) {
            case self::ICCR_PROPERTY_NOTIFIERS . '_onAdd':
                $notifiers = json_decode($Value, true, 512, JSON_THROW_ON_ERROR);
                foreach ($notifiers as $key => $notifier) {
                    if ($notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT] === '') {
                        $notifiers[$key][self::ICCR_PROPERTY_NOTIFIER_IDENT] =
                            'NOTIFIER' . $this->GetNextFreeNotifierNumber(array_column($notifiers, self::ICCR_PROPERTY_NOTIFIER_IDENT));
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
        $savedNotifiers = $this->readNotifiers() ?? [];
        $listValues = [];

        foreach ($savedNotifiers as $notifier){
            $row = $notifier;
            $ident = $notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT];

            $id = ($ident !== '') ? @$this->GetIDForIdent($ident) : 0;

            if ($id !== 0) {
                $row[self::ICCR_PROPERTY_NOTIFIER_NAME] = IPS_GetObject($id)['ObjectName'];
            } else {
                $row[self::ICCR_PROPERTY_NOTIFIER_IDENT] = ''; // Markieren als ungültig für die UI
                $row[self::ICCR_PROPERTY_NOTIFIER_NAME] = $this->Translate('invalid or missing variable');
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

        return ($objMedia['MediaType'] === MEDIATYPE_DOCUMENT) && $objMedia['MediaIsAvailable'];
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

    protected function LoadCalendarFile(string &$content): int
    {
        $iCalMediaId = $this->ReadPropertyInteger(self::ICCR_PROPERTY_ICAL_MEDIA_ID);

        $content = base64_decode(@IPS_GetMediaContent($iCalMediaId));
        $this->Logger_Dbg(__FUNCTION__, sprintf('media object #%d: %d bytes, %d VEVENT', $iCalMediaId, strlen($content), substr_count($content, 'BEGIN:VEVENT')));

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
    protected function LoadCalendarURL(string &$content): int
    {
        $instStatus        = IS_ACTIVE;
        $url               = $this->ReadPropertyString(self::ICCR_PROPERTY_CALENDAR_URL);
        $this->errorDetail = '';

        $this->Logger_Dbg(__FUNCTION__, sprintf('Entering %s(\'%s\')', __FUNCTION__, $url));

        [$result, $curl_error_nr, $curl_error_str] = $this->fetchUrl(
            $url,
            $this->ReadPropertyString(self::ICCR_PROPERTY_USERNAME),
            $this->ReadPropertyString(self::ICCR_PROPERTY_PASSWORD),
            $this->ReadPropertyBoolean(self::ICCR_PROPERTY_DISABLE_SSL_VERIFYPEER)
        );
        $content = is_string($result) ? $result : '';

        // Fehler nicht hier protokollieren: setInstanceStatus() meldet sie einmal je Störung
        if ($curl_error_nr) {
            $this->errorDetail = sprintf('curl error %d: %s', $curl_error_nr, $curl_error_str);
            $instStatus        = $this->MapCurlErrorToStatus($curl_error_nr);
        } elseif (!str_contains($content, 'BEGIN:VCALENDAR')) {
            $instStatus = $this->AnalyzeUnexpectedResponse($content);
        }

        if ($instStatus === IS_ACTIVE) {
            $this->Logger_Dbg(__FUNCTION__, sprintf('loaded: %d bytes, %d VEVENT', strlen($content), substr_count($content, 'BEGIN:VEVENT')));
        } else {
            $this->Logger_Dbg(__FUNCTION__, sprintf('Error: %s, response: %s', $this->errorDetail, $content === '' ? 'empty' : $this->quoteForeignText($content)));
        }
        return $instStatus;
    }

    /*
        der eigentliche Netzzugriff - einzige Naht für Tests
        liefert [Inhalt oder false, curl-Fehlernummer, curl-Fehlertext]
     */
    protected function fetchUrl(string $url, string $username, string $password, bool $disableSslVerification): array
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        if (stripos($url, 'https:') === 0) {
            if ($disableSslVerification) {
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
            // Server-Auth-Verfahren automatisch aushandeln (Basic oder Digest).
            // Ohne CURLOPT_HTTPAUTH nutzt curl nur Basic; Digest-Server (z.B. Baikal/SabreDAV)
            // antworten dann mit "No 'Authorization: Digest' header found".
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($curl, CURLOPT_USERPWD, $username . ':' . $password);
        }

        $content = curl_exec($curl);

        $curl_error_nr  = curl_errno($curl);
        $curl_error_str = curl_error($curl);
        curl_close($curl);

        return [$content, $curl_error_nr, $curl_error_str];
    }

    /*
        curl-Fehlernummer auf einen Instanzstatus abbilden
        (nur unterschieden nach ungültiger URL, Verbindung, SSL und Authentifizierung)
     */
    private function MapCurlErrorToStatus(int $curlErrorNr): int
    {
        return match ($curlErrorNr) {
            CURLE_OPERATION_TIMEOUTED,
            CURLE_SSL_CONNECT_ERROR     => self::STATUS_INST_OPERATION_TIMED_OUT,

            CURLE_UNSUPPORTED_PROTOCOL,
            CURLE_URL_MALFORMAT,
            CURLE_URL_MALFORMAT_USER    => self::STATUS_INST_INVALID_URL,

            CURLE_SSL_ENGINE_NOTFOUND,
            CURLE_SSL_ENGINE_SETFAILED,
            CURLE_SSL_CERTPROBLEM,
            CURLE_SSL_CIPHER,
            CURLE_SSL_CACERT,
            CURLE_SSL_CACERT_BADFILE    => self::STATUS_INST_SSL_ERROR,

            67 /* CURLE_LOGIN_DENIED */ => self::STATUS_INST_INVALID_USER_PASSWORD,

            default                     => self::STATUS_INST_CONNECTION_ERROR,
        };
    }

    /*
        eine Antwort ohne "BEGIN:VCALENDAR" untersuchen: bekannte Fehlerdokumente
        (ownCloud/SabreDAV-XML, Synology-Klartext) erkennen und Status ableiten
     */
    private function AnalyzeUnexpectedResponse(string $content): int
    {
        // ownCloud/SabreDAV meldet Fehler als XML-Dokument
        libxml_use_internal_errors(true);
        $XML = $content === '' ? false : simplexml_load_string($content);

        if ($XML !== false) {
            $XML->registerXPathNamespace('d', 'DAV:');
            if (count($XML->xpath('//d:error')) > 0) {
                $children          = $XML->children('http://sabredav.org/ns');
                $this->errorDetail = $this->quoteForeignText(sprintf('%s: %s', $children->exception ?? '', $children->message ?? ''));
                return self::STATUS_INST_INVALID_USER_PASSWORD;
            }
            $this->errorDetail = 'XML response without calendar: ' . $this->quoteForeignText($content);
            return self::STATUS_INST_UNEXPECTED_RESPONSE;
        }

        // Synology meldet Fehler als Klartext
        if (str_starts_with($content, 'Please log in')) {
            $this->errorDetail = 'server asks to log in';
            return self::STATUS_INST_INVALID_USER_PASSWORD;
        }

        $this->errorDetail = $content === '' ? 'empty response' : 'response starts with ' . $this->quoteForeignText($content);
        return self::STATUS_INST_UNEXPECTED_RESPONSE;
    }

    /* fremder Text (Serverantwort) gekürzt und als Zitat gekennzeichnet - MCP-Regel 17 */
    private function quoteForeignText(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        return '"' . (mb_strlen($text) > 100 ? mb_substr($text, 0, 100) . '…' : $text) . '"';
    }

    /*
        Status setzen und Störungen protokollieren (MCP-Regeln 3, 4, 16): jeder Wechsel in eine
        Störung einmal als Warnung mit Art und nächstem Schritt, die Behebung einmal als Meldung.
        Dieselbe Störung bei jedem Abruf bleibt still. Was gemeldet ist, steht im Attribut und
        überlebt so auch einen Neustart.
     */
    private function setInstanceStatus(int $status): void
    {
        $this->SetStatus($status);

        // Reload-Fenster: Code schon neu, Attribut noch nicht registriert - dann liefert der Kernel
        // false statt eines Strings (am nuc 04.10.2026: falsche Behebungsmeldung, leerer ERROR).
        // Dann nur den Status setzen und nichts protokollieren.
        $logged = $this->ReadAttributeString(self::ICCR_ATTRIBUTE_LOGGED_PROBLEM);
        if (!is_string($logged)) {
            return;
        }
        if ($status < IS_EBASE) {
            if ($logged !== '' && $status === IS_ACTIVE) {
                $this->LogMessage(sprintf('Calendar can be read again (problem was: %s)', $this->statusText((int) $logged)), KL_MESSAGE);
            }
            $this->WriteAttributeString(self::ICCR_ATTRIBUTE_LOGGED_PROBLEM, '');
            return;
        }

        [$key, $message] = $this->problemMessage($status);
        if ($key !== $logged) {
            $this->LogMessage($message, KL_WARNING);
            $this->WriteAttributeString(self::ICCR_ATTRIBUTE_LOGGED_PROBLEM, $key);
        } else {
            $this->Logger_Dbg(__FUNCTION__, 'still: ' . $message);
        }
    }

    /* Schlüssel (Status, bei 201 mit Unterart) und Log-Text einer Störung */
    private function problemMessage(int $status): array
    {
        $url     = $this->ReadPropertyString(self::ICCR_PROPERTY_CALENDAR_URL);
        $mediaId = $this->ReadPropertyInteger(self::ICCR_PROPERTY_ICAL_MEDIA_ID);
        $server  = $this->maskUrl($url);
        $detail  = $this->errorDetail === '' ? '' : ' (' . $this->errorDetail . ')';
        $minutes = $this->ReadPropertyInteger(self::ICCR_PROPERTY_UPDATE_FREQUENCY);
        $retry   = $minutes > 0
            ? sprintf('temporary, reading is retried every %d minutes', $minutes)
            : 'reading is not repeated automatically (update interval 0), call ICCR_UpdateCalendar';

        return match ($status) {
            self::STATUS_INST_INVALID_URL => match (true) {
                $url === '' && $mediaId !== 0 => [
                    '201-media',
                    sprintf('Media object #%d is not a usable document and no calendar URL is configured - configuration error: select a media object of type document or enter the iCal URL', $mediaId)
                ],
                $url === '' => [
                    '201-missing',
                    'No calendar URL and no media object configured - configuration error: enter the iCal URL of the calendar or select a media object'
                ],
                default => [
                    '201-invalid',
                    sprintf('Calendar URL %s is not a valid URL%s - configuration error: correct the URL (http/https)', $server, $detail)
                ],
            },
            self::STATUS_INST_SSL_ERROR => [
                (string) $status,
                sprintf('SSL error reading the calendar from %s%s - check the server certificate or tick "Disable Verification of SSL Certificate"', $server, $detail)
            ],
            self::STATUS_INST_INVALID_USER_PASSWORD => [
                (string) $status,
                sprintf('Calendar server %s rejected user name or password%s - configuration error: correct user name and password and apply the changes (reading is paused until the changes are applied)', $server, $detail)
            ],
            self::STATUS_INST_CONNECTION_ERROR => [
                (string) $status,
                sprintf('Calendar server %s not reachable%s - %s', $server, $detail, $retry)
            ],
            self::STATUS_INST_OPERATION_TIMED_OUT => [
                (string) $status,
                sprintf('Timeout reading the calendar from %s%s - %s', $server, $detail, $retry)
            ],
            self::STATUS_INST_UNEXPECTED_RESPONSE => [
                (string) $status,
                sprintf('Calendar server %s did not return an iCal calendar%s - configuration error: use the export/subscription link of the calendar and apply the changes (reading is paused until the changes are applied)', $server, $detail)
            ],
            self::STATUS_INST_INVALID_NOTIFIERS => [
                (string) $status,
                'Notifier list (property Notifiers) is not a JSON array of entries - configuration error: set it to a list such as [{"Ident":"NOTIFIER1","Find":"Paper","Prenotify":360}]; with IPS_SetProperty pass json_encode($list) once (not a twice encoded string) and apply the changes'
            ],
            self::STATUS_INST_INVALID_MEDIA_CONTENT => [
                (string) $status,
                sprintf('Media object #%d contains no iCal data (BEGIN:VCALENDAR missing) - fill it with an .ics file', $mediaId)
            ],
            default => [(string) $status, sprintf('Instance status %d', $status)],
        };
    }

    /* Server einer URL ohne Pfad und Zugangsdaten - der Pfad trägt bei iCloud & Co. das Zugriffstoken */
    private function maskUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return $this->quoteForeignText($url);
        }
        return sprintf('%s://%s%s/…', $parts['scheme'] ?? 'http', $parts['host'], isset($parts['port']) ? ':' . $parts['port'] : '');
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

        $this->setInstanceStatus($result);

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

        // Der Importer meldet je Termin und je Vorkommen eine Zeile; der Debug-Puffer der Anlage
        // ist aber für alle Module gemeinsam. Deshalb nur die ersten Zeilen, dann das Ergebnis.
        $importProblems = [];
        $debugLines     = 0;
        $MyImporter     = new iCalImporter(
            $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHEBACK),
            $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHE),
            function (string $message, string $data) use (&$debugLines) {
                if (++$debugLines <= self::IMPORT_DEBUG_LINES) {
                    $this->Logger_Dbg($message, $data);
                }
            },
            function (string $message) use (&$importProblems) {
                if (count($importProblems) < self::IMPORT_DEBUG_LINES) {
                    $this->Logger_Dbg('IMPORT_PROBLEM', $message);
                }
                $importProblems[] = $message;
            }
        );

        $iCalCalendarArray = $MyImporter->ImportCalendar($content);
        $this->Logger_Dbg(
            __FUNCTION__,
            sprintf(
                '%d dates imported, %d import problem(s)%s',
                count($iCalCalendarArray),
                count($importProblems),
                $debugLines > self::IMPORT_DEBUG_LINES ? sprintf(', %d further importer debug lines suppressed', $debugLines - self::IMPORT_DEBUG_LINES) : ''
            )
        );
        $this->reportImportProblems($importProblems);

        return json_encode($iCalCalendarArray, JSON_THROW_ON_ERROR + JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /*
        Importprobleme (unbekannte Zeitzone, unlesbare Wiederholungsregel …) wie Störungen
        behandeln: einmal als Warnung, solange sie sich nicht ändern; ihr Verschwinden einmal
        als Meldung. Die Texte stammen aus dem Kalender (Titel, TZID) - nur gekürzt ins Log.
     */
    private function reportImportProblems(array $problems): void
    {
        $logged = $this->ReadAttributeString(self::ICCR_ATTRIBUTE_LOGGED_IMPORT);
        if (!is_string($logged)) {
            return; // Reload-Fenster, siehe setInstanceStatus()
        }
        $problems = array_values(array_unique($problems));
        $key      = $problems === [] ? '' : md5(implode("\n", $problems));
        if ($key === $logged) {
            return;
        }
        $this->WriteAttributeString(self::ICCR_ATTRIBUTE_LOGGED_IMPORT, $key);

        if ($key === '') {
            $this->LogMessage('Calendar imported without problems again', KL_MESSAGE);
            return;
        }
        $this->LogMessage(
            sprintf(
                'Calendar import: %d problem(s), affected dates may be missing or shifted - check the calendar at its source; first: %s (all problems in the debug output, the first also in ICCR_RunSelfTest)',
                count($problems),
                $this->quoteForeignText($problems[0])
            ),
            KL_WARNING
        );
    }

    private function Logger_Dbg(string $message, string $data): void
    {
        if (strlen($data) > self::DEBUG_MAX_BYTES) {
            $data = mb_strcut($data, 0, self::DEBUG_MAX_BYTES) . sprintf('… (%d bytes)', strlen($data));
        }
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

        return $this->refreshCalendar();
    }

    /* Kalender lesen und den Cache erneuern - ohne Statusprüfung, auch aus ApplyChanges */
    private function refreshCalendar(): ?string
    {
        $TheOldCalendar = $this->ReadAttributeString(self::ICCR_ATTRIBUTE_CALENDAR_BUFFER);
        $TheNewCalendar = $this->ReadCalendar();
        $this->Logger_Dbg(
            __FUNCTION__,
            sprintf(
                'cache: %d dates before, %s now',
                count(json_decode($TheOldCalendar, true, 512, JSON_THROW_ON_ERROR)),
                $TheNewCalendar === null ? '-' : count(json_decode($TheNewCalendar, true, 512, JSON_THROW_ON_ERROR))
            )
        );

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
        string $subject,
        int $startTime,
        int $endTime,
        string $searchPattern,
        bool $useRegExpression,
        int $offsetBefore,
        int $offsetAfter
    ): bool {
        $ts = time();

        // Zeitfenster prüfen (unter Berücksichtigung der Offsets in Sekunden)
        if ($ts < ($startTime - $offsetBefore) || $ts >= ($endTime + $offsetAfter)) {
            return false;
        }

        if ($subject === '' || $searchPattern === '') {
            return $searchPattern === '';
        }

        if ($useRegExpression) {
            $normalized = $this->NormalizeRegexPattern($searchPattern);
            $result     = @preg_match($normalized, $subject);
            if ($result === false) {
                $this->Logger_Dbg(
                    __FUNCTION__,
                    sprintf('Invalid regex pattern. raw: \'%s\', normalized: \'%s\'', $searchPattern, $normalized)
                );
                return false;
            }
            return $result > 0;
        }

        return str_contains($subject, $searchPattern);
    }
    public function NormalizeRegexPattern(string $pattern): string
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return $pattern;
        }

        // If pattern already has valid delimiters, keep as-is
        if (preg_match('/^([^\w\\\\\\0]).*\\1[imsxeADSUXJu]*$/', $pattern) === 1) {
            return $pattern;
        }

        // Choose a delimiter that does not occur in the pattern
        foreach (['#', '~', '%', '!', '/'] as $delimiter) {
            if (strpos($pattern, $delimiter) === false) {
                return $delimiter . $pattern . $delimiter;
            }
        }

        // Fallback: escape the default delimiter
        return '/' . str_replace('/', '\\/', $pattern) . '/';
    }

    /*
        the entry point for the periodic 1m notifications timer
        also used to trigger manual updates after configuration changes
        accessible for external scripts
     */
    public function TriggerNotifications(): void
    {
        $this->Logger_Dbg(__FUNCTION__, 'Entering TriggerNotifications()');

        $Notifiers = $this->readNotifiers();
        if (empty($Notifiers)) {
            return;
        }

        $this->Logger_Dbg(__FUNCTION__, 'Processing notifications');
        $notifications = [];
        $calendarData = json_decode($this->ReadAttributeString(self::ICCR_ATTRIBUTE_CALENDAR_BUFFER), true, 512, JSON_THROW_ON_ERROR);

        foreach ($Notifiers as $notifier) {
            $active                                                        = false;
            $notifications[$notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT]] = [];
            foreach ($calendarData as $iCalItem) {
                $active = $this->CheckPresence(
                    $iCalItem['Name'],
                    $iCalItem['From'],
                    $iCalItem['To'],
                    $notifier[self::ICCR_PROPERTY_NOTIFIER_FIND],
                    $notifier[self::ICCR_PROPERTY_NOTIFIER_REGEXPRESSION],
                    $notifier[self::ICCR_PROPERTY_NOTIFIER_PRENOTIFY] * 60,
                    $notifier[self::ICCR_PROPERTY_NOTIFIER_POSTNOTIFY] * 60
                );
                if ($active) {
                    $notifications[$notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT]] = $iCalItem;
                    break;
                }
            }
            $ident   = $notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT];
            $changed = false;
            if (@$this->GetIDForIdent($ident)) {
                $changed = $this->GetValue($ident) !== $active;
                $this->SetValue($ident, $active);
            }
            $this->Logger_Dbg(__FUNCTION__, sprintf('%s: %s%s', $ident, $this->describeReason($notifications[$ident]), $changed ? ', variable changed' : ''));
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
        $this->Logger_Dbg(__FUNCTION__, sprintf('%d dates', count(json_decode($CalendarBuffer, true, 512, JSON_THROW_ON_ERROR))));
        return $CalendarBuffer;
    }

    /* ein Notifier-Ergebnis für das Debug: Titel nur gekürzt in Anführungszeichen (MCP-Regel 17) */
    private function describeReason(array $event): string
    {
        if ($event === []) {
            return 'inactive';
        }
        return sprintf('active by %s (%s - %s)', $this->quoteForeignText((string) $event['Name']), date('Y-m-d H:i', $event['From']), date('Y-m-d H:i', $event['To']));
    }

    public function GetNotifierPresenceReason(string $ident): string
    {

        $idents = array_column($this->readNotifiers() ?? [], self::ICCR_PROPERTY_NOTIFIER_IDENT);
        if (!in_array($ident, $idents, true)) {
            trigger_error(
                sprintf(
                    "Unknown notifier ident '%s' - valid idents: %s",
                    $ident,
                    $idents === [] ? '(no notifiers configured)' : implode(', ', $idents)
                ),
                E_USER_WARNING
            );
            return json_encode(null, JSON_THROW_ON_ERROR);
        }

        // noch nicht ausgewertet (z. B. direkt nach dem Anlegen) ist gleichbedeutend mit inaktiv
        $notifications = json_decode($this->ReadAttributeString(self::ICCR_ATTRIBUTE_NOTIFICATIONS), true, 512, JSON_THROW_ON_ERROR);
        $this->Logger_Dbg(__FUNCTION__, sprintf('%s: %s', $ident, $this->describeReason($notifications[$ident] ?? [])));
        return json_encode($notifications[$ident] ?? [], JSON_THROW_ON_ERROR);
    }

    /*
        Selbsttest für Skripte und KI-Assistenten: liest den Kalender, verändert aber nichts
        (kein Status, keine Variablen, kein Cache). Text, eine Zeile je Prüfung, letzte Zeile
        "N errors, M warnings".
     */
    public function RunSelfTest(): string
    {
        $lines    = [];
        $errors   = 0;
        $warnings = 0;
        $add      = static function (string $level, string $label, string $hint = '') use (&$lines, &$errors, &$warnings): void {
            $lines[] = match ($level) {
                'ok'    => '✓',
                'warn'  => '⚠',
                'error' => '✗',
                default => '•',
            } . ' ' . $label;
            if ($hint !== '') {
                $lines[] = '   → ' . $hint;
            }
            $errors += $level === 'error' ? 1 : 0;
            $warnings += $level === 'warn' ? 1 : 0;
        };
        $summary = static function () use (&$lines, &$errors, &$warnings): string {
            $lines[] = sprintf('%d errors, %d warnings', $errors, $warnings);
            return implode("\n", $lines);
        };

        if (!$this->ReadPropertyBoolean(self::ICCR_PROPERTY_ACTIVE)) {
            $add('warn', 'Instance is switched off (property active = false), the calendar is not read', 'Switch it on and apply the changes.');
            return $summary();
        }

        // Quelle lesen - wie beim regulären Abruf, aber ohne Status und Cache zu setzen
        $content = '';
        $mediaId = $this->ReadPropertyInteger(self::ICCR_PROPERTY_ICAL_MEDIA_ID);
        if ($mediaId !== 0) {
            if (!IPS_MediaExists($mediaId) || IPS_GetMedia($mediaId)['MediaType'] !== MEDIATYPE_DOCUMENT) {
                $add('error', sprintf('Media object #%d does not exist or is not a document', $mediaId), 'Select a media object of type document, or clear the field to use the URL.');
                return $summary();
            }
            if ($this->LoadCalendarFile($content) !== IS_ACTIVE) {
                $add('error', sprintf('Media object #%d contains no iCal data (BEGIN:VCALENDAR missing)', $mediaId), 'Fill the media object with an .ics file.');
                return $summary();
            }
            $add('ok', sprintf('Source: media object #%d, %d bytes', $mediaId, strlen($content)));
        } else {
            if (!$this->CheckCalendarURLSyntax()) {
                $add('error', 'No valid calendar URL configured and no media object selected', 'Enter the iCal URL of the calendar (http/https) or select a media object, then apply the changes.');
                return $summary();
            }
            $status = $this->LoadCalendarURL($content);
            if ($status !== IS_ACTIVE) {
                $add('error', sprintf('Calendar URL could not be read: %s (status %d)', $this->statusText($status), $status), $this->statusHint($status));
                return $summary();
            }
            $add('ok', sprintf('Source: URL readable, %d bytes', strlen($content)));
        }

        // Import ins Cache-Fenster, Probleme sammeln statt protokollieren
        $importProblems = [];
        $daysBack       = $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHEBACK);
        $daysAhead      = $this->ReadPropertyInteger(self::ICCR_PROPERTY_DAYSTOCACHE);
        try {
            $importer = new iCalImporter(
                $daysBack,
                $daysAhead,
                static function (string $message, string $data): void {
                },
                static function (string $message) use (&$importProblems): void {
                    $importProblems[] = $message;
                }
            );
            $events = $importer->ImportCalendar($content);
        } catch (Throwable $t) {
            $add('error', 'Import failed: ' . mb_substr($t->getMessage(), 0, 200), 'The calendar data cannot be read; the debug output of "Load calendar" shows details.');
            return $summary();
        }
        $add('ok', sprintf('Import: %d dates between %d days back and %d days ahead', count($events), $daysBack, $daysAhead));
        if ($importProblems !== []) {
            $add('warn', sprintf('%d import problem(s), first: %s', count($importProblems), mb_substr($importProblems[0], 0, 200)), 'The affected dates may be missing.');
        }
        $next = null;
        foreach ($events as $event) {
            if ($event['From'] > time() && ($next === null || $event['From'] < $next)) {
                $next = $event['From'];
            }
        }
        $add('info', $next === null ? 'No future date in the cache window' : 'Next date starts ' . date('Y-m-d H:i', $next));

        // Instanzstatus und Cache
        $instanceStatus = $this->GetStatus();
        if ($instanceStatus !== IS_ACTIVE) {
            $add('warn', sprintf('Instance status is %d (%s), the cache is not updated', $instanceStatus, $this->statusText($instanceStatus)), 'Apply the changes or call ICCR_UpdateCalendar to read the calendar again.');
        }
        $cached = json_decode($this->ReadAttributeString(self::ICCR_ATTRIBUTE_CALENDAR_BUFFER), true, 512, JSON_THROW_ON_ERROR);
        $interval = $this->ReadPropertyInteger(self::ICCR_PROPERTY_UPDATE_FREQUENCY);
        $add(
            count($cached) === count($events) ? 'ok' : 'info',
            sprintf(
                'Cache: %d dates, %s%s',
                count($cached),
                $interval > 0 ? sprintf('read every %d minutes', $interval) : 'not read automatically (update interval 0)',
                count($cached) === count($events) ? '' : ' (differs from the current read; ICCR_UpdateCalendar refreshes it now)'
            )
        );

        // Notifier gegen den frisch gelesenen Kalender
        $notifiers = $this->readNotifiers();
        if ($notifiers === null) {
            $add('error', 'Notifier list is not a JSON array of entries', 'Set the property Notifiers to a list, e.g. IPS_SetProperty($id, \'Notifiers\', json_encode([[\'Ident\' => \'NOTIFIER1\', \'Find\' => \'Paper\']])) - encode once, not twice - and apply the changes.');
            $notifiers = [];
        } elseif ($notifiers === []) {
            $add('info', 'No notifiers configured');
        }
        foreach ($notifiers as $notifier) {
            $ident = (string) $notifier[self::ICCR_PROPERTY_NOTIFIER_IDENT];
            $find  = (string) $notifier[self::ICCR_PROPERTY_NOTIFIER_FIND];
            $regex = (bool) $notifier[self::ICCR_PROPERTY_NOTIFIER_REGEXPRESSION];
            $label = sprintf('%s (%s "%s")', $ident, $regex ? 'pattern' : 'text', mb_substr($find, 0, 60));
            if (@$this->GetIDForIdent($ident) === false) {
                $add('warn', $label . ': variable missing', 'Apply the changes to create it.');
                continue;
            }
            if ($regex && $find !== '' && @preg_match($this->NormalizeRegexPattern($find), '') === false) {
                $add('error', $label . ': invalid regular expression, never matches', 'Correct the pattern in the notifier list.');
                continue;
            }
            $matches = 0;
            $activeNow = false;
            foreach ($events as $event) {
                $matches += ($find === '' || ($regex ? @preg_match($this->NormalizeRegexPattern($find), $event['Name']) > 0 : str_contains($event['Name'], $find))) ? 1 : 0;
                $activeNow = $activeNow || $this->CheckPresence(
                    $event['Name'],
                    $event['From'],
                    $event['To'],
                    $find,
                    $regex,
                    $notifier[self::ICCR_PROPERTY_NOTIFIER_PRENOTIFY] * 60,
                    $notifier[self::ICCR_PROPERTY_NOTIFIER_POSTNOTIFY] * 60
                );
            }
            $variable = $this->GetValue($ident);
            $add(
                $matches === 0 ? 'warn' : 'ok',
                sprintf('%s: %d matching date(s) in the cache window, active now: %s, variable: %s', $label, $matches, $activeNow ? 'yes' : 'no', $variable ? 'true' : 'false'),
                $matches === 0 ? 'No date title contains this search text; check spelling and case.' : ''
            );
        }

        return $summary();
    }

    private function statusText(int $status): string
    {
        return match ($status) {
            IS_ACTIVE                               => 'active',
            IS_INACTIVE                             => 'inactive',
            self::STATUS_INST_INVALID_URL           => 'invalid URL',
            self::STATUS_INST_SSL_ERROR             => 'SSL error',
            self::STATUS_INST_INVALID_USER_PASSWORD => 'invalid user or password',
            self::STATUS_INST_CONNECTION_ERROR      => 'connection error',
            self::STATUS_INST_UNEXPECTED_RESPONSE   => 'unexpected response, not an iCal calendar',
            self::STATUS_INST_INVALID_MEDIA_CONTENT => 'media object without iCal data',
            self::STATUS_INST_OPERATION_TIMED_OUT   => 'timeout',
            self::STATUS_INST_INVALID_NOTIFIERS     => 'invalid notifier list',
            default                                 => 'unknown status',
        };
    }

    private function statusHint(int $status): string
    {
        return match ($status) {
            self::STATUS_INST_INVALID_URL           => 'Configuration: correct the URL.',
            self::STATUS_INST_SSL_ERROR             => 'Configuration: check the server certificate, or tick "Disable Verification of SSL Certificate".',
            self::STATUS_INST_INVALID_USER_PASSWORD => 'Configuration: correct user name and password.',
            self::STATUS_INST_CONNECTION_ERROR,
            self::STATUS_INST_OPERATION_TIMED_OUT   => 'Server not reachable: try again later; if it persists, check the address.',
            self::STATUS_INST_UNEXPECTED_RESPONSE   => 'Configuration: the URL does not return an iCal calendar; use the export/subscription link of the calendar.',
            default                                 => '',
        };
    }
}
