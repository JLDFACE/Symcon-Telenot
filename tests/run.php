<?php

declare(strict_types=1);

// Test gegen die Symcon-Stubs mit echten Telegrammen der hiplex 8400H.
// Aufruf: php tests/run.php

error_reporting(E_ALL & ~E_DEPRECATED);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';
include_once __DIR__ . '/stubs/ConstantStubs.php';

const GW = '{F3393CE7-1768-4FBA-93FD-BB5348FC2CEC}';
const AREA = '{F7AA885C-2F32-43BF-94C0-8B3EE679DAC8}';
const CONTACT = '{31DD9AF7-803E-4CF9-B4D2-605CC3EC42BB}';
const CONF = '{0BB39F1E-DA1E-420C-86EC-F605BE293D3C}';

$fx = json_decode(file_get_contents(__DIR__ . '/fixtures.json'), true);
$fails = 0;
$checks = 0;

function check($cond, $what)
{
    global $fails, $checks;
    $checks++;
    echo ($cond ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$cond) {
        $fails++;
    }
}

function hexbin($h)
{
    return hex2bin(str_replace(' ', '', $h));
}

function frame($payloadHex)
{
    $p = hexbin($payloadHex);
    $sum = 0;
    for ($i = 0; $i < strlen($p); $i++) {
        $sum += ord($p[$i]);
    }
    return "\x68" . chr(strlen($p)) . chr(strlen($p)) . "\x68" . $p . chr($sum & 0xFF) . "\x16";
}

function sbFrame($area1, $area2)
{
    return frame('73 02 1f 24 00 05 00 02 ' . $area1 . ' ' . $area2 . str_repeat(' ff', 25) . ' 06 56 52 80 f9 ff ff ff');
}

function mpFrame($bytes)
{
    return frame('73 02 0a 24 00 00 00 01 ' . $bytes . ' 06 56 52 80 f9 ff ff ff');
}

function io($id)
{
    return IPS\InstanceManager::getInstanceInterface($id);
}

/** Schickt Bytes an das Gateway und liefert alles, was es an die Zentrale zurückschreibt. */
function push($sock, $bin)
{
    io($sock)->PushText($bin);
    $out = array();
    while (io($sock)->HasText()) {
        $out[] = bin2hex(io($sock)->PopText());
    }
    return $out;
}

function val($inst, $ident)
{
    return GetValue(IPS_GetObjectIDByIdent($ident, $inst));
}

const ACK = '6802026800020216';
const POLL = "\x68\x02\x02\x68\x40\x02\x42\x16";

IPS\Kernel::reset();
IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/IOStubs/library.json');
IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
IPS_CreateVariableProfile('~UnixTimestamp', 1);

$gw = IPS_CreateInstance(GW);
IPS_SetProperty($gw, 'Host', '192.168.10.50');
IPS_SetProperty($gw, 'Port', 8234);
IPS_ApplyChanges($gw);
$sock = IPS_GetInstance($gw)['ConnectionID'];

echo "Gateway / Socket\n";
check($sock > 0, 'Client Socket als Parent angelegt');
check(IPS_GetProperty($sock, 'Host') === '192.168.10.50' && IPS_GetProperty($sock, 'Port') === 8234, 'Host/Port an den Socket übergeben');
check(IPS_GetProperty($sock, 'Open') === true, 'Socket geöffnet');
check(val($gw, 'Online') === false, 'vor dem ersten Telegramm offline');

$a1 = IPS_CreateInstance(AREA);
IPS_SetProperty($a1, 'Area', 1);
IPS_ApplyChanges($a1);
$a2 = IPS_CreateInstance(AREA);
IPS_SetProperty($a2, 'Area', 2);
IPS_ApplyChanges($a2);
$sab = IPS_CreateInstance(CONTACT);
IPS_SetProperty($sab, 'Address', 0x0010);
IPS_ApplyChanges($sab);
check(IPS_GetInstance($a1)['ConnectionID'] === $gw, 'Bereich hängt am Gateway');

echo "Abfrage / Quittung\n";
$tx = push($sock, POLL);
check($tx === array(ACK), 'Abfrage wird mit ACK beantwortet: ' . implode(',', $tx));
check(val($gw, 'Online') === true, 'nach Telegramm online');

echo "Bereiche\n";
$tx = push($sock, sbFrame('9e', '9e'));
check($tx === array(ACK), 'Bereichsblock quittiert, kein Befehl offen');
check(val($a1, 'State') === 0 && val($a2, 'State') === 0, '9e 9e → beide unscharf');
check(val($a1, 'ReadyExternal') === true && val($a1, 'Alarm') === false, 'unscharf, bereit, kein Alarm');
push($sock, sbFrame('9b', '9e'));
check(val($a1, 'State') === 2 && val($a2, 'State') === 0, '9b 9e → Bereich 1 extern scharf');

echo "Sabotage\n";
push($sock, hexbin($fx['mp_sabotage']));
push($sock, hexbin($fx['sb_sabotage']));
check(val($sab, 'Active') === true, 'Melder 0x0010 (Sabotage allgemein) aktiv');
check(val($a1, 'Alarm') === true && val($a1, 'ReadyExternal') === false, '0x76 → Alarm, nicht bereit');
check(val($a1, 'InternalHorn') === true, '0x76 → interner Signalgeber an');
push($sock, mpFrame('ff ff ff ff ff ff'));
check(val($sab, 'Active') === false, 'Melder wieder in Ruhe');

echo "Ereignisse\n";
push($sock, hexbin($fx['event_arm_gmslite']));
$ev = val($gw, 'LastEvent');
check($ev === '16.10.2026 12:53:45  Extern scharf – GMS-Schnittst. (RS232) / Bestattungshaus', 'GMS lite Scharf-Ereignis: ' . $ev);
push($sock, hexbin($fx['event_sabotage_gmsplus']));
$ev = val($gw, 'LastEvent');
check(strpos($ev, 'Sabotage – Gehaeuseueberwach. (hiplex) / Bestattungshaus') !== false, 'GMS+ Sabotage-Ereignis: ' . $ev);
push($sock, hexbin($fx['event_restart_gmslite']));
check(strpos(val($gw, 'LastEvent'), 'Neustart') !== false, 'Neustart-Ereignis ohne Text: ' . val($gw, 'LastEvent'));
check(count(explode("\n", val($gw, 'EventLog'))) === 3, 'Protokoll hat 3 Zeilen');

echo "Befehle\n";
io($a1)->RequestAction('State', 2);
$tx = push($sock, POLL);
check($tx === array(ACK), 'Befehl geht nicht als Antwort auf die Abfrage raus');
$tx = push($sock, sbFrame('9e', '9e'));
check($tx === array(ACK, '68090968730105020005020261e516'), 'nach Bereichsblock: ACK + Extern scharf Bereich 1');
io($a2)->RequestAction('State', 0);
$tx = push($sock, sbFrame('9b', '9b'));
check(isset($tx[1]) && $tx[1] === '680909687301050200050802e16b16', 'Unscharf Bereich 2: ' . (isset($tx[1]) ? $tx[1] : '-'));
TLN_ArmInternal($gw, 1);
TLN_ArmInternal($gw, 1);
$tx = push($sock, sbFrame('9e', '9e'));
check(isset($tx[1]) && $tx[1] === '68090968730105020005010262e516', 'Intern scharf Bereich 1: ' . (isset($tx[1]) ? $tx[1] : '-'));
$tx = push($sock, sbFrame('9e', '9e'));
check($tx === array(ACK), 'doppelter Befehl nur einmal gesendet');
$tx = push($sock, hexbin($fx['cmd_confirm']));
check($tx === array(ACK), 'Befehlsbestätigung (C=0x00) wird quittiert wie im Live-Test');

echo "Zerstückelte Telegramme\n";
$f = sbFrame('9b', '9e');
$tx1 = push($sock, "\x00\x01\x16" . substr($f, 0, 7));
$tx2 = push($sock, substr($f, 7, 20));
$tx3 = push($sock, substr($f, 27));
check($tx1 === array() && $tx2 === array() && $tx3 === array(ACK), 'Störbytes davor, Rahmen über 3 Pakete zusammengesetzt');
check(val($a1, 'State') === 2, 'Zustand aus zusammengesetztem Rahmen');
$tx = push($sock, "\x68\x02\x02\x68\x40\x02\x43\x16" . POLL);
check($tx === array(ACK), 'falsche Prüfsumme verworfen, folgende Abfrage erkannt');

echo "Discovery\n";
TLN_StartDiscovery($gw);
$tx = push($sock, sbFrame('9e', '9e'));
check(isset($tx[1]) && $tx[1] === '68090968730205100000007124' . '1f16', 'Abfrage benutzter Kontakte: ' . (isset($tx[1]) ? $tx[1] : '-'));
push($sock, hexbin($fx['used_inputs']));
push($sock, hexbin($fx['used_outputs']));
$info = json_decode(TLN_GetContacts($gw), true);
check($info['Discovery']['Phase'] === 'names' && $info['Discovery']['Total'] === 32 + 2 + 15 + 32, 'Namensliste: ' . $info['Discovery']['Total'] . ' Einträge');
$tx = push($sock, sbFrame('9e', '9e'));
check(isset($tx[1]) && $tx[1] === '6809096873020510000010730c1916', 'Namensabfrage 0x0010: ' . (isset($tx[1]) ? $tx[1] : '-'));
push($sock, hexbin($fx['info_0010']));
push($sock, hexbin($fx['info_0500']));
$info = json_decode(TLN_GetContacts($gw), true);
check($info['Contacts']['16']['Name'] === 'Sabotage allgemein' && $info['Contacts']['16']['Kind'] === 'Melder', 'Name 0x0010: ' . $info['Contacts']['16']['Name']);
check($info['Contacts']['1280']['Name'] === 'Bestattungshaus' && $info['Contacts']['1280']['Kind'] === 'Bereich', 'Name Bereich 1: ' . $info['Contacts']['1280']['Name']);
check($info['Discovery']['Open'] === 79, 'noch 79 offen');

// Unbeantwortete Abfragen: nach 3 Versuchen ohne Namen übernehmen und weiter
$tx = push($sock, sbFrame('9e', '9e'));
$first = isset($tx[1]) ? $tx[1] : '';
push($sock, sbFrame('9e', '9e'));
push($sock, sbFrame('9e', '9e'));
$tx = push($sock, sbFrame('9e', '9e'));
check($first === '6809096873020510000011730c1a16' && isset($tx[1]) && $tx[1] !== $first, 'nach 3 Versuchen nächste Adresse: ' . (isset($tx[1]) ? $tx[1] : '-'));

echo "Konfigurator\n";
$conf = IPS_CreateInstance(CONF);
IPS_ApplyChanges($conf);
check(IPS_GetInstance($conf)['ConnectionID'] === $gw, 'Konfigurator hängt am Gateway');
$form = json_decode(io($conf)->GetConfigurationForm(), true);
$rows = array();
foreach ($form['elements'] as $e) {
    if ($e['type'] === 'Configurator') {
        $rows = $e['values'];
    }
}
$byName = array();
foreach ($rows as $r) {
    $byName[$r['name']] = $r;
}
check(isset($byName['Bestattungshaus']) && $byName['Bestattungshaus']['instanceID'] === $a1, 'Bereich 1 mit bestehender Instanz verknüpft');
check(isset($byName['Sabotage allgemein']) && $byName['Sabotage allgemein']['instanceID'] === $sab, 'Melder 0x0010 mit bestehender Instanz verknüpft');
check(isset($byName['Bestattungshaus']['create']['configuration']['Area']) && $byName['Bestattungshaus']['create']['configuration']['Area'] === 1, 'Create-Konfiguration Bereich');

echo "\n" . ($checks - $fails) . "/$checks Prüfungen bestanden\n";
exit($fails > 0 ? 1 : 0);
