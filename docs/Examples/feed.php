<?php

// version: 2.20 build 16
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

// correct instance?
if (IPS_GetInstance($InstanceID)['ModuleInfo']['ModuleID'] !== '{5127CDDC-2859-4223-A870-4D26AC83622C}') { // reader instance
    // no job for us
    doReturn();
}

/** @noinspection PhpUndefinedFunctionInspection */
$CalendarFeed = json_decode(ICCR_GetCachedCalendar($InstanceID), true);

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
