<?php

// version: 2.1 build 116
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
$rawPayload = ICCR_GetCachedCalendar($InstanceID);
$CalendarFeed = json_decode($rawPayload, true);

if (!is_array($CalendarFeed)) {
    doReturn();
}

// convert to calendar format
$result = array_map(static function (array $Event) {
    return array_filter([
                            'id'     => $Event['UID'] ?? null,
                            'title'  => $Event['Name'] ?? '',
                            'start'  => $Event['FromS'] ?? '',
                            'end'    => $Event['ToS'] ?? '',
                            'allDay' => $Event['allDay'] ?? null,
                        ], static fn($value) => $value !== null);
}, $CalendarFeed);

header('Content-Type: application/json');
echo json_encode($result);

function doReturn()
{
    header('Content-Type: application/json');
    exit(json_encode([]));
}
