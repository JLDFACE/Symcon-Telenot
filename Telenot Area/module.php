<?php

declare(strict_types=1);

/**
 * Telenot Sicherungsbereich. Liest das Bereichsbyte aus dem Zustandsblock des
 * Gateways und schaltet über dessen Befehlswarteschlange.
 *
 * Bereichsbyte (active-low, Bit = 0 heißt „trifft zu“):
 *  Bit 0 unscharf, 1 intern scharf, 2 extern scharf, 3 Alarm, 4 Störung,
 *  5 bereit intern, 6 bereit extern, 7 interner Signalgeber.
 *  An der hiplex verifiziert: 0x9E unscharf, 0x9B extern scharf, 0x76/0xF6 Sabotage.
 */
class TelenotArea extends IPSModule
{
    const GATEWAY_GUID = '{F3393CE7-1768-4FBA-93FD-BB5348FC2CEC}';
    const IF_FROM_CHILD = '{99A612CC-0F87-4A39-9E2E-C6026E1053A8}';

    const STATE_DISARMED = 0;
    const STATE_INTERNAL = 1;
    const STATE_EXTERNAL = 2;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Area', 1);
        $this->RegisterPropertyInteger('GatewayID', 0);

        $this->ConnectParent(self::GATEWAY_GUID);
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

        $this->EnsureGatewayConnection();
        $this->EnsureProfiles();

        $this->RegisterVariableBoolean('Online', 'Online', $this->ProfileName('Online'), 0);
        $this->RegisterVariableInteger('State', 'Zustand', $this->ProfileName('State'), 1);
        $this->RegisterVariableBoolean('Alarm', 'Alarm', $this->ProfileName('Alarm'), 2);
        $this->RegisterVariableBoolean('Malfunction', 'Störung', $this->ProfileName('Malfunction'), 3);
        $this->RegisterVariableBoolean('ReadyInternal', 'Bereit intern scharf', $this->ProfileName('Ready'), 4);
        $this->RegisterVariableBoolean('ReadyExternal', 'Bereit extern scharf', $this->ProfileName('Ready'), 5);
        $this->RegisterVariableBoolean('InternalHorn', 'Interner Signalgeber', $this->ProfileName('Horn'), 6);
        $this->EnableAction('State');
        $this->EnableAction('Alarm');

        $area = $this->ReadPropertyInteger('Area');
        if ($area < 1 || $area > 8) {
            $this->SetStatus(201);
            return;
        }
        $this->SetStatus(102);
        $this->RequestSync();
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'State':
                $map = array(
                    self::STATE_DISARMED => 'Disarm',
                    self::STATE_INTERNAL => 'ArmInternal',
                    self::STATE_EXTERNAL => 'ArmExternal'
                );
                $v = (int) $Value;
                if (!isset($map[$v])) {
                    return;
                }
                // Kein optimistisches Setzen: die Zentrale meldet den echten Zustand
                // ca. 1–3 s später, bei fehlender Bereitschaft bleibt er stehen.
                $this->SendCommand($map[$v]);
                break;

            case 'Alarm':
                if (!(bool) $Value) {
                    $this->SendCommand('ResetAlarm');
                }
                break;
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['Buffer']) || !is_array($data['Buffer'])) {
            return;
        }
        $msg = $data['Buffer'];
        $type = isset($msg['Type']) ? $msg['Type'] : '';

        if ($type === 'Online') {
            $this->SetValueIfChanged('Online', (bool) $msg['Value']);
            return;
        }
        if ($type !== 'State' || $msg['Block'] !== 'SB') {
            return;
        }

        $offset = (int) $msg['AreaBase'] + 8 * ($this->ReadPropertyInteger('Area') - 1) - (int) $msg['Base'];
        $bytes = hex2bin((string) $msg['Data']);
        $idx = intdiv($offset, 8);
        if ($offset < 0 || $idx >= strlen($bytes)) {
            return;
        }
        $byte = ord($bytes[$idx]);
        $on = function ($bit) use ($byte) {
            return (($byte >> $bit) & 1) === 0;
        };

        $state = self::STATE_DISARMED;
        if ($on(2)) {
            $state = self::STATE_EXTERNAL;
        } elseif ($on(1)) {
            $state = self::STATE_INTERNAL;
        }
        $this->SetValueIfChanged('Online', true);
        $this->SetValueIfChanged('State', $state);
        $this->SetValueIfChanged('Alarm', $on(3));
        $this->SetValueIfChanged('Malfunction', $on(4));
        $this->SetValueIfChanged('ReadyInternal', $on(5));
        $this->SetValueIfChanged('ReadyExternal', $on(6));
        $this->SetValueIfChanged('InternalHorn', $on(7));
    }

    private function SendCommand($action)
    {
        if (!$this->HasActiveParent()) {
            $this->SendDebug('Befehl', 'kein aktives Gateway', 0);
            return;
        }
        $this->SendDebug('Befehl', $action, 0);
        $this->SendDataToParent(json_encode(array(
            'DataID' => self::IF_FROM_CHILD,
            'Buffer' => array('Type' => 'Command', 'Action' => $action, 'Area' => $this->ReadPropertyInteger('Area'))
        )));
    }

    private function RequestSync()
    {
        if (!$this->HasActiveParent()) {
            return; // das Gateway schickt den Stand ohnehin spätestens nach 60 s
        }
        $this->SendDataToParent(json_encode(array(
            'DataID' => self::IF_FROM_CHILD,
            'Buffer' => array('Type' => 'Get')
        )));
    }

    // Per Konfigurator angelegte Instanzen hängen je nach Symcon-Version an keinem
    // Parent (ConnectionID 0, siehe QSys). Dann an das mitgegebene Gateway hängen.
    private function EnsureGatewayConnection()
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        if (!is_array($inst) || (int) $inst['ConnectionID'] > 0) {
            return;
        }
        $gw = $this->ReadPropertyInteger('GatewayID');
        if ($gw <= 0 || !@IPS_InstanceExists($gw)) {
            $all = @IPS_GetInstanceListByModuleID(self::GATEWAY_GUID);
            if (!is_array($all) || count($all) !== 1) {
                return;
            }
            $gw = $all[0];
        }
        @IPS_ConnectInstance($this->InstanceID, $gw);
    }

    private function SetValueIfChanged($ident, $value)
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === 0 || $vid === false) {
            return;
        }
        if (GetValue($vid) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function ProfileName($name)
    {
        return 'TLN.' . $this->InstanceID . '.' . $name;
    }

    private function EnsureProfiles()
    {
        $p = $this->ProfileName('Online');
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileAssociation($p, false, 'Offline', 'Close', 0xFF0000);
        IPS_SetVariableProfileAssociation($p, true, 'Online', 'Ok', 0x00CC00);
        IPS_SetVariableProfileIcon($p, 'Network');

        $p = $this->ProfileName('State');
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileValues($p, 0, 2, 0);
        IPS_SetVariableProfileAssociation($p, self::STATE_DISARMED, 'Unscharf', 'LockOpen', -1);
        IPS_SetVariableProfileAssociation($p, self::STATE_INTERNAL, 'Intern scharf', 'Lock', -1);
        IPS_SetVariableProfileAssociation($p, self::STATE_EXTERNAL, 'Extern scharf', 'Lock', -1);
        IPS_SetVariableProfileIcon($p, 'Lock');

        $p = $this->ProfileName('Alarm');
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileAssociation($p, false, 'Ruhe', '', -1);
        IPS_SetVariableProfileAssociation($p, true, 'Alarm', 'Alert', 0xF5A623);
        IPS_SetVariableProfileIcon($p, 'Alert');

        $p = $this->ProfileName('Malfunction');
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileAssociation($p, false, 'OK', '', -1);
        IPS_SetVariableProfileAssociation($p, true, 'Störung', 'Warning', 0xF5A623);
        IPS_SetVariableProfileIcon($p, 'Warning');

        $p = $this->ProfileName('Ready');
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileAssociation($p, false, 'Nicht bereit', '', -1);
        IPS_SetVariableProfileAssociation($p, true, 'Bereit', '', -1);
        IPS_SetVariableProfileIcon($p, 'Ok');

        $p = $this->ProfileName('Horn');
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileAssociation($p, false, 'Aus', '', -1);
        IPS_SetVariableProfileAssociation($p, true, 'Ein', '', -1);
        IPS_SetVariableProfileIcon($p, 'Speaker');
    }
}
