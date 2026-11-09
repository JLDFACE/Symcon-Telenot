<?php

declare(strict_types=1);

/**
 * Telenot Gateway – GMS-Schnittstelle einer Telenot hiplex / complex 400.
 *
 * Protokoll (an der hiplex 8400H mitgeschnitten, Befehle nach SmartHome/J-Binding):
 *  - Rahmen FT1.2-artig: 68 L L 68 <Daten> CS 16, CS = Summe der Daten & 0xFF.
 *  - Die Zentrale ist Master und fragt alle 3 s mit 68 02 02 68 40 02 42 16 an.
 *    Ohne Quittung (68 02 02 68 00 02 02 16) schickt sie keine Daten.
 *  - Jeder Datenrahmen (C = 0x73) wird ebenfalls quittiert.
 *  - Befehle gehen nach der Quittung des Bereichs-Zustandsblocks raus, nicht als
 *    Antwort auf die Abfrage (das legt unter GMS+ die Schnittstelle lahm).
 *  - Schalten funktioniert nur im Modus „GMS lite" (9600 Baud). GMS+ (115200)
 *    liefert zwar alle Zustände, lehnt die Befehle aber ab.
 *  - Adressen sind Bits: Melder ab 0x0000, Sicherungsbereiche/Ausgänge ab 0x0500,
 *    jeweils LSB zuerst, gesetzter Zustand = Bit 0 (active-low).
 */
class TelenotGateway extends IPSModule
{
    const SOCKET_GUID = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    const SERIAL_GUID = '{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}';
    const SERVER_GUID = '{8062CF2B-600E-41D6-AD4B-1BA66C32D6ED}';
    const IO_TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    const IF_FROM_CHILD = '{99A612CC-0F87-4A39-9E2E-C6026E1053A8}';
    const IF_TO_CHILD = '{A05F3750-F1CA-49AE-A531-0E1D2A0A0CB4}';

    const STATUS_OK = 102;
    const STATUS_NO_DATA = 200;

    const FRAME_POLL = "\x68\x02\x02\x68\x40\x02\x42\x16";
    const FRAME_ACK = "\x68\x02\x02\x68\x00\x02\x02\x16";

    // Nutzdaten der Abfragen (Länge und Prüfsumme ergänzt BuildFrame)
    const REQ_USED_STATE = "\x73\x02\x05\x10\x00\x00\x00\x71\x24";

    // Offsets im 8-Bit-Block eines Sicherungsbereichs → Befehlscode
    const AREA_CMD = array(
        'Disarm' => array(0, 0xE1),
        'ArmInternal' => array(1, 0x62),
        'ArmExternal' => array(2, 0x61),
        'ResetAlarm' => array(3, 0x52)
    );

    const QUEUE_MAX = 20;
    const DISCOVERY_TRIES = 3;
    const LOG_LINES = 50;
    const REFRESH_SECONDS = 60;
    // Abstand zwischen Quittung des Bereichsblocks und Befehl. Kommen beide ohne
    // Pause an, verwirft die Zentrale den Befehl (live über den Waveshare-Wandler
    // geprüft: gleichzeitig nein, 50 ms bis 2 s ja).
    const SEND_DELAY_MS = 200;
    const RECONNECT_AFTER = 60;     // s ohne Daten bei offenem Socket → neu verbinden

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 23);
        $this->RegisterPropertyString('PanelType', 'hiplex');
        $this->RegisterPropertyInteger('OfflineAfter', 15);

        $this->RegisterAttributeString('Contacts', '{}');
        $this->RegisterAttributeString('UsedInputs', '[]');
        $this->RegisterAttributeString('UsedOutputs', '[]');

        $this->RegisterTimer('Watchdog', 0, 'TLN_Watchdog($_IPS["TARGET"]);');
        $this->RegisterTimer('Send', 0, 'TLN_SendPending($_IPS["TARGET"]);');

        // Normalfall: IP-RS232-Wandler (z. B. USR-TCP232-302) im TCP-Server-Modus.
        // Ein Serial Port als Parent funktioniert genauso (gleicher Datenfluss).
        $this->RequireParent(self::SOCKET_GUID);
    }

    public function Destroy()
    {
        $prefix = 'TLN.' . $this->InstanceID . '.';
        foreach (IPS_GetVariableProfileList() as $p) {
            if (strpos($p, $prefix) === 0) {
                @IPS_DeleteVariableProfile($p);
            }
        }
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();
        $this->RegisterVariableBoolean('Online', 'Online', $this->ProfileName('Online'), 1);
        $this->RegisterVariableString('LastEvent', 'Letztes Ereignis', '', 2);
        $this->RegisterVariableString('EventLog', 'Ereignisprotokoll', '', 3);
        IPS_SetIcon($this->GetIDForIdent('LastEvent'), 'Information');
        IPS_SetIcon($this->GetIDForIdent('EventLog'), 'Database');

        $this->SyncSocket();

        // Frisch (neu) gestartet: erst nach RECONNECT_AFTER ohne Daten neu verbinden
        $this->SetBuffer('LastReconnect', (string) microtime(true));
        $this->SetTimerInterval('Watchdog', 5000);
        $this->Watchdog();
    }

    /**
     * Host/Port aus dem Gateway an den Client Socket übergeben. Nur aus ApplyChanges
     * heraus: bei Create() hat RequireParent noch keine ConnectionID geliefert.
     */
    private function SyncSocket()
    {
        $parent = $this->ParentID();
        $host = trim($this->ReadPropertyString('Host'));
        if ($parent <= 0 || $host === '') {
            return;
        }
        $inst = @IPS_GetInstance($parent);
        if (!is_array($inst) || $inst['ModuleInfo']['ModuleID'] !== self::SOCKET_GUID) {
            return;
        }
        $port = $this->ReadPropertyInteger('Port');
        $changed = false;
        if ((string) @IPS_GetProperty($parent, 'Host') !== $host) {
            @IPS_SetProperty($parent, 'Host', $host);
            $changed = true;
        }
        if ((int) @IPS_GetProperty($parent, 'Port') !== $port) {
            @IPS_SetProperty($parent, 'Port', $port);
            $changed = true;
        }
        if (!(bool) @IPS_GetProperty($parent, 'Open')) {
            @IPS_SetProperty($parent, 'Open', true);
            $changed = true;
        }
        if ($changed) {
            @IPS_ApplyChanges($parent);
        }
    }

    public function GetConfigurationForParent()
    {
        // Nur einem Serial Port Werte vorgeben. Beim IP-RS232-Wandler stellt man die
        // Schnittstelle in dessen Weboberfläche ein, Host/Port setzt SyncSocket().
        $parent = $this->ParentID();
        if ($parent > 0) {
            $inst = @IPS_GetInstance($parent);
            if (is_array($inst) && $inst['ModuleInfo']['ModuleID'] === self::SERIAL_GUID) {
                return json_encode(array(
                    'BaudRate' => '9600',
                    'DataBits' => '8',
                    'StopBits' => '1',
                    'Parity' => 'None'
                ));
            }
        }
        return '{}';
    }

    // -----------------------------------------------------------------
    // Öffentliche Funktionen
    // -----------------------------------------------------------------

    public function ArmExternal(int $Area)
    {
        return $this->QueueAreaCommand('ArmExternal', $Area);
    }

    public function ArmInternal(int $Area)
    {
        return $this->QueueAreaCommand('ArmInternal', $Area);
    }

    public function Disarm(int $Area)
    {
        return $this->QueueAreaCommand('Disarm', $Area);
    }

    public function ResetAlarm(int $Area)
    {
        return $this->QueueAreaCommand('ResetAlarm', $Area);
    }

    /** Ausgang / Schaltaktion schalten (Adresse wie im Konfigurator, z. B. 0x057B). */
    public function SwitchOutput(int $Address, bool $On)
    {
        return $this->QueueOutputCommand($Address, $On);
    }

    /**
     * Meldebereich sperren / freigeben (Meldungsart 0x51 / 0xD1), z. B. 0x05BF.
     * An der hiplex 8400H (GMS lite) am 09.11.2026 live verifiziert: die Zentrale quittiert,
     * meldet „Fenster Gaeste WC / IP-Symcon Haussteuerung“ und setzt das Bit im Bereichsblock.
     */
    public function SetBlocked(int $Address, bool $Blocked)
    {
        return $this->QueueBlockCommand($Address, $Blocked);
    }

    /** Liest nur die Namen eines Adressbereichs (z. B. Meldebereiche, die die Zentrale nicht als belegt meldet). */
    public function ReadNames(int $From, int $To)
    {
        if ($From < 0 || $To < $From || $To - $From > 255) {
            return false;
        }
        $disc = $this->GetDisc();
        $pending = isset($disc['Pending']) ? $disc['Pending'] : array();
        for ($a = $From; $a <= $To; $a++) {
            if (!in_array($a, $pending, true)) {
                $pending[] = $a;
            }
        }
        $this->SetDisc(array(
            'Phase' => 'names',
            'GotIn' => true,
            'GotOut' => true,
            'Tries' => 0,
            'Pending' => $pending,
            'PendingTries' => array(),
            'Total' => count($pending),
            'Started' => time(),
            'SkipNameless' => true
        ));
        $this->SendDebug('Discovery', sprintf('Namen 0x%04X–0x%04X angefragt', $From, $To), 0);
        return true;
    }

    /** Liest benutzte Melder, Bereiche und Ausgänge samt Namen aus der Zentrale. */
    public function StartDiscovery()
    {
        $this->WriteAttributeString('UsedInputs', '[]');
        $this->WriteAttributeString('UsedOutputs', '[]');
        $this->SetDisc(array(
            'Phase' => 'used',
            'GotIn' => false,
            'GotOut' => false,
            'Tries' => 0,
            'Pending' => array(),
            'PendingTries' => array(),
            'Total' => 0,
            'Started' => time()
        ));
        $this->SendDebug('Discovery', 'gestartet', 0);
        return true;
    }

    /** Für den Konfigurator: bekannte Kontakte, Discovery-Stand und Adresslayout. */
    public function GetContacts()
    {
        $disc = $this->GetDisc();
        return json_encode(array(
            'PanelType' => $this->ReadPropertyString('PanelType'),
            'AreaBase' => $this->AreaBase(),
            'Online' => (bool) $this->GetValue('Online'),
            'Contacts' => $this->GetContactMap(),
            'Discovery' => array(
                'Phase' => isset($disc['Phase']) ? $disc['Phase'] : 'idle',
                'Total' => isset($disc['Total']) ? (int) $disc['Total'] : 0,
                'Open' => isset($disc['Pending']) ? count($disc['Pending']) : 0
            )
        ));
    }

    public function Watchdog()
    {
        $last = (float) $this->GetBuffer('LastRx');
        $limit = max(5, $this->ReadPropertyInteger('OfflineAfter'));
        $online = $this->ParentActive() && $last > 0 && (microtime(true) - $last) <= $limit;
        $this->SetDeviceOnline($online);
        if (!$online) {
            $this->ReconnectIfSilent();
        }
    }

    /**
     * Der Socket meldet „verbunden“, aber die Zentrale schweigt: so sieht eine halb offene
     * TCP-Verbindung aus, etwa nach einem Neustart des Wandlers (live am 06.11.2026 erlebt,
     * 25 min ohne Daten). Dann den Client Socket neu verbinden, höchstens einmal pro Minute.
     * Gilt auch für einen Server Socket (Wandler im Modus „TCP Client“): Schließen/Öffnen
     * trennt die hängende Verbindung, der Wandler verbindet sich neu – wichtig bei Limit = 1.
     */
    private function ReconnectIfSilent()
    {
        $parent = $this->ParentID();
        if ($parent <= 0 || !$this->ParentActive()) {
            return;
        }
        if (!in_array(IPS_GetInstance($parent)['ModuleInfo']['ModuleID'], array(self::SOCKET_GUID, self::SERVER_GUID), true)) {
            return;
        }
        $since = max((float) $this->GetBuffer('LastRx'), (float) $this->GetBuffer('LastReconnect'));
        if (microtime(true) - $since < self::RECONNECT_AFTER) {
            return;
        }
        $this->SetBuffer('LastReconnect', (string) microtime(true));
        $this->LogMessage('Telenot: keine Daten von der Zentrale, Verbindung zum Wandler wird neu aufgebaut', KL_WARNING);
        @IPS_SetProperty($parent, 'Open', false);
        @IPS_ApplyChanges($parent);
        @IPS_SetProperty($parent, 'Open', true);
        @IPS_ApplyChanges($parent);
    }

    // -----------------------------------------------------------------
    // Datenfluss
    // -----------------------------------------------------------------

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['Buffer'])) {
            return;
        }
        $buf = hex2bin($this->GetBuffer('Rx')) . $this->FromIO((string) $data['Buffer']);

        $frames = array();
        while (strlen($buf) > 0) {
            if (ord($buf[0]) !== 0x68) {
                $buf = substr($buf, 1);
                continue;
            }
            if (strlen($buf) < 4) {
                break;
            }
            $l = ord($buf[1]);
            if (ord($buf[2]) !== $l || ord($buf[3]) !== 0x68) {
                $buf = substr($buf, 1);
                continue;
            }
            if (strlen($buf) < $l + 6) {
                break;
            }
            $frame = substr($buf, 0, $l + 6);
            $payload = substr($frame, 4, $l);
            if (ord($frame[$l + 5]) !== 0x16 || ord($frame[$l + 4]) !== $this->Checksum($payload)) {
                // Kein gültiger Rahmen: um ein Byte weiterschieben und neu synchronisieren
                $this->SendDebug('Rx verworfen', bin2hex($frame), 0);
                $buf = substr($buf, 1);
                continue;
            }
            $frames[] = $frame;
            $buf = (string) substr($buf, $l + 6);
        }
        if (strlen($buf) > 1024) {
            $buf = '';
        }
        $this->SetBuffer('Rx', bin2hex($buf));

        foreach ($frames as $frame) {
            $this->HandleFrame($frame);
        }
    }

    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['Buffer'])) {
            return '';
        }
        $req = $data['Buffer'];
        if (is_string($req)) {
            $req = json_decode($req, true);
        }
        if (!is_array($req) || !isset($req['Type'])) {
            return '';
        }

        switch ($req['Type']) {
            case 'Get':
                // Kind ist (neu) da: aktuellen Stand sofort schicken
                $this->FanOutOnline((bool) $this->GetValue('Online'));
                foreach (array('MP', 'SB') as $block) {
                    $this->FanOutBlock($block);
                }
                break;

            case 'Command':
                $action = isset($req['Action']) ? (string) $req['Action'] : '';
                $area = isset($req['Area']) ? (int) $req['Area'] : 0;
                return json_encode($this->QueueAreaCommand($action, $area));

            case 'Output':
                return json_encode($this->QueueOutputCommand((int) ($req['Address'] ?? 0), (bool) ($req['Value'] ?? false)));

            case 'Block':
                return json_encode($this->QueueBlockCommand((int) ($req['Address'] ?? 0), (bool) ($req['Value'] ?? false)));
        }
        return '';
    }

    // -----------------------------------------------------------------
    // Rahmenauswertung
    // -----------------------------------------------------------------

    private function HandleFrame($frame)
    {
        // Quittung zuerst und ohne Umweg: kommt sie spürbar zu spät, schickt die
        // Zentrale keine weitere Abfrage und verwirft den folgenden Befehl
        // (im Live-Test mit 250 ms Verzug reproduziert). Quittiert wird jeder
        // Rahmen außer ihrer eigenen Quittung – auch die Befehlsbestätigung (C = 0x00).
        if ($frame !== self::FRAME_ACK) {
            $this->SendToPanel(self::FRAME_ACK);
        }

        $this->SetBuffer('LastRx', (string) microtime(true));
        if (!$this->GetValue('Online')) {
            $this->SetDeviceOnline(true);
        }

        if ($frame === self::FRAME_POLL) {
            return;
        }
        if ($frame === self::FRAME_ACK) {
            $this->SendDebug('Rx', 'Quittung der Zentrale', 0);
            return;
        }
        $this->SendDebug('Rx', bin2hex($frame), 0);

        $b = array_values(unpack('C*', $frame));
        if (count($b) < 10 || $b[4] !== 0x73) {
            return;
        }

        if ($b[7] === 0x24 && count($b) >= 14) {
            $addr = ($b[9] << 8) | $b[10];
            $kind = $b[11];
            $data = (string) substr($frame, 12, max(0, $b[6] - 4));
            if ($kind === 0x01 && $addr === 0x0000) {
                $this->HandleBlock('MP', $data);
            } elseif ($kind === 0x02 && $addr === 0x0500) {
                $this->HandleBlock('SB', $data);
                // Zeitfenster für Befehle: nach dem Bereichsblock wartet die Zentrale.
                // Nicht hier senden: der Client Socket schickt erst ab, wenn
                // ReceiveData zurückkehrt – Quittung und Befehl kämen dann zusammen
                // und zu spät an. Der Timer sendet in einem eigenen Durchlauf.
                if ($this->HasPendingSend()) {
                    $this->SetTimerInterval('Send', self::SEND_DELAY_MS);
                }
            } elseif ($kind === 0x71) {
                $this->HandleUsed('In', 0x0000, $data);
            } elseif ($kind === 0x72) {
                $this->HandleUsed('Out', 0x0500, $data);
            }
            return;
        }

        if ($b[6] === 0x05 && $b[7] === 0x02 && count($b) >= 16) {
            $this->HandleEvent($b);
            return;
        }

        if ($b[7] === 0x0C) {
            $this->HandleContactInfo($b);
        }
    }

    private function HandleBlock($block, $data)
    {
        $old = $this->GetBuffer('Block_' . $block);
        $hex = bin2hex($data);
        $this->SetBuffer('Block_' . $block, $hex);

        $lastFanout = (float) $this->GetBuffer('LastFanout_' . $block);
        if ($old !== $hex || (time() - $lastFanout) >= self::REFRESH_SECONDS) {
            if ($old !== $hex) {
                $this->SendDebug('Zustand ' . $block, $hex, 0);
            }
            $this->FanOutBlock($block);
        }
    }

    private function HandleEvent(array $b)
    {
        $area = $b[8];
        $addr = ($b[9] << 8) | $b[10];
        $code = $b[12];

        // Hinter dem Code folgt ein Zeitblock 07 50 yy cc mm dd hh mi ss, danach
        // Textlänge, 'T' und der Text. Unter GMS+ steckt davor noch ein Zusatzblock,
        // deshalb suchen statt fester Offsets.
        $time = time();
        $text = '';
        for ($i = 13; $i + 10 < count($b); $i++) {
            if ($b[$i] === 0x07 && $b[$i + 1] === 0x50) {
                $t = @mktime($b[$i + 6], $b[$i + 7], $b[$i + 8], $b[$i + 4], $b[$i + 5], 2000 + $b[$i + 2]);
                if ($t !== false) {
                    $time = $t;
                }
                $len = $b[$i + 9];
                $start = $i + 11;
                $raw = '';
                for ($k = $start; $k < $start + $len && $k < count($b); $k++) {
                    $raw .= chr($b[$k]);
                }
                $text = $this->FormatEventText($this->DecodeText($raw));
                break;
            }
        }

        $line = date('d.m.Y H:i:s', $time) . '  ' . $this->CodeName($code);
        if ($text !== '') {
            $line .= ' – ' . $text;
        }
        $this->SendDebug('Ereignis', sprintf('Bereich %d, Adresse 0x%04X, Code 0x%02X: %s', $area, $addr, $code, $line), 0);

        $this->SetValue('LastEvent', $line);
        $log = (string) $this->GetValue('EventLog');
        $lines = $log === '' ? array() : explode("\n", $log);
        array_unshift($lines, $line);
        $this->SetValue('EventLog', implode("\n", array_slice($lines, 0, self::LOG_LINES)));
    }

    private function HandleUsed($which, $base, $data)
    {
        $disc = $this->GetDisc();
        if (!isset($disc['Phase']) || $disc['Phase'] !== 'used') {
            return;
        }
        $used = array();
        $bytes = array_values(unpack('C*', $data));
        foreach ($bytes as $i => $byte) {
            for ($bit = 0; $bit < 8; $bit++) {
                if ((($byte >> $bit) & 1) === 0) {
                    $used[] = $base + $i * 8 + $bit;
                }
            }
        }
        $this->SendDebug('Discovery', sprintf('%s: %d Adressen benutzt', $which, count($used)), 0);
        $this->WriteAttributeString('Used' . ($which === 'In' ? 'Inputs' : 'Outputs'), json_encode($used));
        $disc['Got' . $which] = true;

        if ($disc['GotIn'] && $disc['GotOut']) {
            $pending = json_decode($this->ReadAttributeString('UsedInputs'), true);
            $areaBase = $this->AreaBase();
            foreach (json_decode($this->ReadAttributeString('UsedOutputs'), true) as $a) {
                $kind = $this->Classify($a);
                if ($kind === 'Bereich') {
                    // Von einem Bereich nur die Basisadresse – sie trägt den Namen
                    $a = $areaBase + intdiv($a - $areaBase, 8) * 8;
                }
                if (!in_array($a, $pending, true)) {
                    $pending[] = $a;
                }
            }
            $disc['Phase'] = 'names';
            $disc['Pending'] = $pending;
            $disc['PendingTries'] = array();
            $disc['Total'] = count($pending);
        }
        $this->SetDisc($disc);
    }

    private function HandleContactInfo(array $b)
    {
        if (count($b) < 16) {
            return;
        }
        $addr = ($b[9] << 8) | $b[10];
        $st = $b[6];
        $nameLen = isset($b[8 + $st]) ? $b[8 + $st] : 0;
        $raw = '';
        for ($k = 10 + $st; $k < 10 + $st + $nameLen && $k < count($b); $k++) {
            $raw .= chr($b[$k]);
        }
        $name = trim(preg_replace('/\s+/', ' ', $this->DecodeText($raw)));
        $this->SendDebug('Name', sprintf('0x%04X = %s', $addr, $name), 0);

        $map = $this->GetContactMap();
        $map[(string) $addr] = array('Name' => $name, 'Kind' => $this->Classify($addr));
        $this->WriteAttributeString('Contacts', json_encode($map));

        $disc = $this->GetDisc();
        if (isset($disc['Pending'])) {
            $disc['Pending'] = array_values(array_filter($disc['Pending'], function ($a) use ($addr) {
                return (int) $a !== $addr;
            }));
            if ($disc['Phase'] === 'names' && count($disc['Pending']) === 0) {
                $disc['Phase'] = 'idle';
                $this->LogMessage(sprintf('Telenot: %d Kontakte ausgelesen', count($map)), KL_MESSAGE);
            }
            $this->SetDisc($disc);
        }
    }

    // -----------------------------------------------------------------
    // Senden
    // -----------------------------------------------------------------

    private function QueueAreaCommand($action, $area)
    {
        if (!isset(self::AREA_CMD[$action]) || $area < 1 || $area > 8) {
            $this->SendDebug('Befehl', 'ungültig: ' . $action . ' ' . $area, 0);
            return false;
        }
        list($offset, $code) = self::AREA_CMD[$action];
        $addr = $this->AreaBase() + 8 * ($area - 1) + $offset;
        $frame = $this->BuildFrame("\x73\x01\x05\x02\x00" . chr($addr >> 8) . chr($addr & 0xFF) . "\x02" . chr($code));
        $this->SendDebug('Befehl', sprintf('%s Bereich %d eingereiht: %s', $action, $area, bin2hex($frame)), 0);
        return $this->Enqueue($frame);
    }

    /**
     * Ausgang / Schaltaktion ein- oder ausschalten: Meldungsart 0x00 = ein, 0x80 = aus.
     * An der hiplex 8400H (GMS lite) am 09.11.2026 live verifiziert mit 0x057B „Motorschloss“;
     * die Schaltaktion muss in hipas als Ein/Aus angelegt sein.
     */
    private function QueueOutputCommand($address, $on)
    {
        if ($address < 0x0500 || $address > 0x06FF) {
            $this->SendDebug('Befehl', sprintf('Ausgang 0x%04X ungültig', $address), 0);
            return false;
        }
        $frame = $this->BuildFrame("\x73\x01\x05\x02\x00" . chr($address >> 8) . chr($address & 0xFF) . "\x02" . ($on ? "\x00" : "\x80"));
        $this->SendDebug('Befehl', sprintf('Ausgang 0x%04X %s eingereiht: %s', $address, $on ? 'ein' : 'aus', bin2hex($frame)), 0);
        return $this->Enqueue($frame);
    }

    /** Meldebereich sperren (0x51) / freigeben (0xD1). */
    private function QueueBlockCommand($address, $blocked)
    {
        if ($address < 0x0500 || $address > 0x06FF) {
            $this->SendDebug('Befehl', sprintf('Meldebereich 0x%04X ungültig', $address), 0);
            return false;
        }
        $frame = $this->BuildFrame("\x73\x01\x05\x02\x00" . chr($address >> 8) . chr($address & 0xFF) . "\x02" . ($blocked ? "\x51" : "\xD1"));
        $this->SendDebug('Befehl', sprintf('Meldebereich 0x%04X %s eingereiht: %s', $address, $blocked ? 'sperren' : 'freigeben', bin2hex($frame)), 0);
        return $this->Enqueue($frame);
    }

    private function Enqueue($frame)
    {
        $ok = false;
        $this->WithLock(function () use ($frame, &$ok) {
            $queue = json_decode($this->GetBuffer('Queue'), true);
            if (!is_array($queue)) {
                $queue = array();
            }
            $hex = bin2hex($frame);
            if (!in_array($hex, $queue, true) && count($queue) < self::QUEUE_MAX) {
                $queue[] = $hex;
            }
            $this->SetBuffer('Queue', json_encode($queue));
            $ok = true;
        });
        return $ok;
    }

    /** Timer: einen Befehl oder eine Discovery-Abfrage nach dem Bereichsblock senden. */
    public function SendPending()
    {
        $this->SetTimerInterval('Send', 0);
        $this->SendNextCommand();
    }

    private function HasPendingSend()
    {
        $queue = json_decode($this->GetBuffer('Queue'), true);
        if (is_array($queue) && count($queue) > 0) {
            return true;
        }
        $disc = $this->GetDisc();
        return isset($disc['Phase']) && $disc['Phase'] !== 'idle';
    }

    private function SendNextCommand()
    {
        $frame = '';
        $this->WithLock(function () use (&$frame) {
            $queue = json_decode($this->GetBuffer('Queue'), true);
            if (is_array($queue) && count($queue) > 0) {
                $frame = hex2bin((string) array_shift($queue));
                $this->SetBuffer('Queue', json_encode($queue));
            }
        });
        if ($frame === '') {
            $frame = $this->NextDiscoveryFrame();
        }
        if ($frame === '') {
            return;
        }
        $this->SendDebug('Tx', bin2hex($frame), 0);
        $this->SendToPanel($frame);
    }

    private function NextDiscoveryFrame()
    {
        $disc = $this->GetDisc();
        if (!isset($disc['Phase'])) {
            return '';
        }
        if ($disc['Phase'] === 'used') {
            if ($disc['Tries'] >= self::DISCOVERY_TRIES) {
                $disc['Phase'] = 'idle';
                $this->SetDisc($disc);
                $this->LogMessage('Telenot: Zentrale beantwortet die Kontaktabfrage nicht', KL_WARNING);
                return '';
            }
            $disc['Tries']++;
            $this->SetDisc($disc);
            return $this->BuildFrame(self::REQ_USED_STATE);
        }
        if ($disc['Phase'] === 'names') {
            while (count($disc['Pending']) > 0) {
                $addr = (int) $disc['Pending'][0];
                $key = (string) $addr;
                $tries = isset($disc['PendingTries'][$key]) ? (int) $disc['PendingTries'][$key] : 0;
                if ($tries >= self::DISCOVERY_TRIES) {
                    // Keine Antwort – ohne Namen übernehmen und weiter
                    array_shift($disc['Pending']);
                    $map = $this->GetContactMap();
                    if (!isset($map[$key]) && empty($disc['SkipNameless'])) {
                        $map[$key] = array('Name' => '', 'Kind' => $this->Classify($addr));
                        $this->WriteAttributeString('Contacts', json_encode($map));
                    }
                    continue;
                }
                $disc['PendingTries'][$key] = $tries + 1;
                $this->SetDisc($disc);
                return $this->BuildFrame("\x73\x02\x05\x10\x00" . chr($addr >> 8) . chr($addr & 0xFF) . "\x73\x0C");
            }
            $disc['Phase'] = 'idle';
            $this->SetDisc($disc);
        }
        return '';
    }

    private function SendToPanel($frame)
    {
        if (!$this->ParentActive()) {
            return;
        }
        $this->SendDataToParent(json_encode(array(
            'DataID' => self::IO_TX,
            'Buffer' => $this->ToIO($frame)
        )));
    }

    private function BuildFrame($payload)
    {
        $l = strlen($payload);
        return "\x68" . chr($l) . chr($l) . "\x68" . $payload . chr($this->Checksum($payload)) . "\x16";
    }

    private function Checksum($payload)
    {
        $sum = 0;
        $n = strlen($payload);
        for ($i = 0; $i < $n; $i++) {
            $sum += ord($payload[$i]);
        }
        return $sum & 0xFF;
    }

    // -----------------------------------------------------------------
    // Kinder
    // -----------------------------------------------------------------

    private function FanOutBlock($block)
    {
        $hex = $this->GetBuffer('Block_' . $block);
        if ($hex === '') {
            return;
        }
        $this->SetBuffer('LastFanout_' . $block, (string) time());
        $this->SendDataToChildren(json_encode(array(
            'DataID' => self::IF_TO_CHILD,
            'Buffer' => array(
                'Type' => 'State',
                'Block' => $block,
                'Base' => $block === 'MP' ? 0x0000 : 0x0500,
                'AreaBase' => $this->AreaBase(),
                'Data' => $hex
            )
        )));
    }

    private function FanOutOnline($online)
    {
        $this->SendDataToChildren(json_encode(array(
            'DataID' => self::IF_TO_CHILD,
            'Buffer' => array('Type' => 'Online', 'Value' => $online)
        )));
    }

    private function SetDeviceOnline($online)
    {
        $online = (bool) $online;
        if ($this->GetStatus() !== ($online ? self::STATUS_OK : self::STATUS_NO_DATA)) {
            $this->SetStatus($online ? self::STATUS_OK : self::STATUS_NO_DATA);
        }
        if ((bool) $this->GetValue('Online') !== $online) {
            $this->SetValue('Online', $online);
            $this->FanOutOnline($online);
        }
    }

    // -----------------------------------------------------------------
    // Hilfen
    // -----------------------------------------------------------------

    private function AreaBase()
    {
        return $this->ReadPropertyString('PanelType') === 'complex' ? 0x0530 : 0x0500;
    }

    /** Ordnet eine Adresse einer Kontaktart zu (Layout je Zentralentyp). */
    private function Classify($addr)
    {
        if ($addr < 0x0500) {
            return 'Melder';
        }
        if ($this->ReadPropertyString('PanelType') === 'complex') {
            if ($addr < 0x0530) {
                return 'Ausgang';
            }
            if ($addr < 0x0570) {
                return 'Bereich';
            }
            return 'Meldebereich';
        }
        // hiplex 8400H: Bereiche 0x0500–0x053F, Ausgänge danach, Meldebereiche ab 0x05B8
        if ($addr < 0x0540) {
            return 'Bereich';
        }
        if ($addr < 0x05B8) {
            return 'Ausgang';
        }
        return 'Meldebereich';
    }

    private function CodeName($code)
    {
        $names = array(
            0x61 => 'Extern scharf',
            0x62 => 'Intern scharf',
            0xE1 => 'Unscharf',
            0x52 => 'Rückstellung',
            0x51 => 'Gesperrt',
            0xD1 => 'Freigegeben',
            0x53 => 'Neustart',
            0x22 => 'Alarm',
            0x23 => 'Sabotage',
            0x30 => 'Störung'
        );
        if (isset($names[$code])) {
            return $names[$code];
        }
        // Bit 7 = gehend (z. B. 0xA3 = Sabotage beendet)
        if (($code & 0x80) && isset($names[$code & 0x7F])) {
            return $names[$code & 0x7F] . ' beendet';
        }
        return sprintf('Ereignis 0x%02X', $code);
    }

    /** Telenot-Zeichensatz: Latin-1 mit eigenen Umlautcodes, \r trennt Zeilen. */
    private function DecodeText($raw)
    {
        $out = '';
        $map = array(0xE1 => 'ä', 0xEF => 'ö', 0xF5 => 'ü', 0x0D => ' ', 0x0A => ' ');
        $n = strlen($raw);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($raw[$i]);
            if (isset($map[$c])) {
                $out .= $map[$c];
            } elseif ($c >= 0x20 && $c < 0x7F) {
                $out .= chr($c);
            } elseif ($c >= 0xA0) {
                $out .= $this->ToUtf8(chr($c));
            }
        }
        return $out;
    }

    /** "/GMS-Schnittst. (RS232)/-- /Bestattungshaus" → "GMS-Schnittst. (RS232) / Bestattungshaus" */
    private function FormatEventText($text)
    {
        $parts = array();
        foreach (explode('/', $text) as $p) {
            $p = trim($p);
            if ($p !== '' && $p !== '--') {
                $parts[] = $p;
            }
        }
        return implode(' / ', $parts);
    }

    private function GetContactMap()
    {
        $map = json_decode($this->ReadAttributeString('Contacts'), true);
        return is_array($map) ? $map : array();
    }

    private function GetDisc()
    {
        $disc = json_decode($this->GetBuffer('Disc'), true);
        return is_array($disc) ? $disc : array('Phase' => 'idle');
    }

    private function SetDisc(array $disc)
    {
        $this->SetBuffer('Disc', json_encode($disc));
    }

    // Status des Sockets direkt prüfen: HasActiveParent() der Test-Stubs wertet
    // auch den eigenen Status aus, der vor dem ersten Telegramm noch auf 200 steht.
    private function ParentActive()
    {
        $parent = $this->ParentID();
        if ($parent <= 0) {
            return false;
        }
        $inst = @IPS_GetInstance($parent);
        return is_array($inst) && (int) $inst['InstanceStatus'] === 102;
    }

    private function ParentID()
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        return is_array($inst) ? (int) $inst['ConnectionID'] : 0;
    }

    private function ProfileName($name)
    {
        return 'TLN.' . $this->InstanceID . '.' . $name;
    }

    private function EnsureProfiles()
    {
        // Anlegen bedingt, konfigurieren immer (Destroy() räumt bei jedem Modul-Update ab)
        $p = $this->ProfileName('Online');
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileAssociation($p, false, 'Offline', 'Close', 0xFF0000);
        IPS_SetVariableProfileAssociation($p, true, 'Online', 'Ok', 0x00CC00);
        IPS_SetVariableProfileIcon($p, 'Network');
    }

    private function WithLock($fn)
    {
        $key = 'TLN_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($key, 500)) {
            return false;
        }
        try {
            $fn();
        } finally {
            IPS_SemaphoreLeave($key);
        }
        return true;
    }

    // Symcon reicht Binärdaten der IO-Instanzen als UTF-8-kodiertes Latin-1 durch
    private function FromIO($s)
    {
        return function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8') : utf8_decode($s);
    }

    private function ToIO($s)
    {
        return $this->ToUtf8($s);
    }

    private function ToUtf8($s)
    {
        return function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1') : utf8_encode($s);
    }
}
