<?php

declare(strict_types=1);

// Live-Test: das Modul (in den Symcon-Stubs) an der echten Zentrale.
// Wird von live_bridge.py gestartet, das die serielle Schnittstelle bedient.
// Protokoll über stdin/stdout, je Zeile:
//   rein: "RX <hex>" | "CMD <befehl> [<arg>]"     raus: "TX <hex>"* "LOG <text>"* "END"

error_reporting(E_ALL & ~E_DEPRECATED);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';

IPS\Kernel::reset();
IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/IOStubs/library.json');
IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

function io($id)
{
    return IPS\InstanceManager::getInstanceInterface($id);
}

$GLOBALS['TLN_LIVE_TX'] = function ($bin) {
    echo 'TX ' . bin2hex($bin) . "\n";
    fflush(STDOUT);
};

$gw = IPS_CreateInstance('{F3393CE7-1768-4FBA-93FD-BB5348FC2CEC}');
IPS_SetProperty($gw, 'Host', 'live');
IPS_ApplyChanges($gw);
$sock = IPS_GetInstance($gw)['ConnectionID'];

$areas = array();
foreach (array(1, 2) as $n) {
    $areas[$n] = IPS_CreateInstance('{F7AA885C-2F32-43BF-94C0-8B3EE679DAC8}');
    IPS_SetProperty($areas[$n], 'Area', $n);
    IPS_ApplyChanges($areas[$n]);
}
$contact = IPS_CreateInstance('{31DD9AF7-803E-4CF9-B4D2-605CC3EC42BB}');
IPS_SetProperty($contact, 'Address', 0x0010);
IPS_ApplyChanges($contact);

$watch = array(
    'GW Online' => array($gw, 'Online'),
    'GW Ereignis' => array($gw, 'LastEvent'),
    'B1 Zustand' => array($areas[1], 'State'),
    'B1 Alarm' => array($areas[1], 'Alarm'),
    'B1 bereit ext' => array($areas[1], 'ReadyExternal'),
    'B2 Zustand' => array($areas[2], 'State'),
    'B2 Alarm' => array($areas[2], 'Alarm'),
    'Melder 0x10' => array($contact, 'Active')
);
$last = array();

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if (strpos($line, 'RX ') === 0) {
        io($sock)->PushText(hex2bin(substr($line, 3)));
    } elseif (strpos($line, 'CMD ') === 0) {
        $p = explode(' ', substr($line, 4));
        switch ($p[0]) {
            case 'state':     // CMD state <bereich> <0|1|2>
                io($areas[(int) $p[1]])->RequestAction('State', (int) $p[2]);
                break;
            case 'discovery':
                TLN_StartDiscovery($gw);
                break;
            case 'contacts':
                echo 'LOG CONTACTS ' . TLN_GetContacts($gw) . "\n";
                break;
        }
    }
    while (io($sock)->HasText()) {
        echo 'TX ' . bin2hex(io($sock)->PopText()) . "\n";
    }
    foreach ($watch as $label => $w) {
        $v = GetValue(IPS_GetObjectIDByIdent($w[1], $w[0]));
        if (!array_key_exists($label, $last) || $last[$label] !== $v) {
            echo 'LOG ' . $label . ' = ' . var_export($v, true) . "\n";
            $last[$label] = $v;
        }
    }
    echo "END\n";
    flush();
}
