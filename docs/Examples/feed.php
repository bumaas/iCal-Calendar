<?php

// version: 2.10 build 15
declare(strict_types=1);

// get instance ID
if (!isset($_GET['InstanceID'])) {
    doReturn();
}

$InstanceID = (int) $_GET['InstanceID'];

// instance existing?
if (!IPS_ObjectExists($InstanceID)) {
    doReturn();
}

// calendar reader or calendar notifier?
$InstanceInfo = IPS_GetInstance($InstanceID);

switch ($InstanceInfo['ModuleInfo']['ModuleID']) {
    case '{5127CDDC-2859-4223-A870-4D26AC83622C}': // reader instance
        /** @noinspection PhpUndefinedFunctionInspection */
        $CalendarFeed = json_decode(ICCR_GetCachedCalendar($InstanceID), true);
        break;
    case '{F22703FF-8576-4AB1-A0E7-02E3116CD3BA}': // notifier instance
        /** @noinspection PhpUndefinedFunctionInspection */
        $CalendarFeed = json_decode(ICCN_GetNotifierPresenceReason($InstanceID), true);
        break;
    default:
        // no job for us
        doReturn();
}

$result = [];
// convert to calendar format
foreach ($CalendarFeed as $Event) {
    $CalEvent          = [];
    $CalEvent['id']    = $Event['UID'];
    $CalEvent['title'] = $Event['Name'];
    $CalEvent['start'] = $Event['FromS'];
    $CalEvent['end']   = $Event['ToS'];
    if (isset($Event['allDay'])) {
        $CalEvent['allDay'] = $Event['allDay'];
    }
    $result[] = $CalEvent;
}

echo json_encode($result);

function doReturn()
{
    exit(json_encode([]));
}
