<?php

declare(strict_types=1);

/**
 * Telenot Kontakt: ein einzelnes Zustandsbit der Zentrale – Melder (ab 0x0000),
 * Ausgang oder Meldebereich (ab 0x0500). Bit = 0 heißt aktiv (active-low).
 */
class TelenotContact extends IPSModule
{
    const GATEWAY_GUID = '{F3393CE7-1768-4FBA-93FD-BB5348FC2CEC}';
    const IF_FROM_CHILD = '{99A612CC-0F87-4A39-9E2E-C6026E1053A8}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Address', 0x0010);
        $this->RegisterPropertyString('Kind', 'Melder');
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
        $this->RegisterVariableBoolean('Active', $this->CaptionForKind(), $this->ProfileName('Active'), 1);

        $this->SetStatus(102);
        if ($this->HasActiveParent()) {
            $this->SendDataToParent(json_encode(array(
                'DataID' => self::IF_FROM_CHILD,
                'Buffer' => array('Type' => 'Get')
            )));
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
        if ($type !== 'State') {
            return;
        }

        $offset = $this->ReadPropertyInteger('Address') - (int) $msg['Base'];
        $bytes = hex2bin((string) $msg['Data']);
        if ($offset < 0 || intdiv($offset, 8) >= strlen($bytes)) {
            return; // Adresse liegt im anderen Block
        }
        $byte = ord($bytes[intdiv($offset, 8)]);
        $this->SetValueIfChanged('Online', true);
        $this->SetValueIfChanged('Active', (($byte >> ($offset % 8)) & 1) === 0);
    }

    private function CaptionForKind()
    {
        switch ($this->ReadPropertyString('Kind')) {
            case 'Ausgang':
                return 'Ausgang';
            case 'Meldebereich':
                return 'Meldebereich';
        }
        return 'Melder';
    }

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

        $p = $this->ProfileName('Active');
        if (!IPS_VariableProfileExists($p)) {
            IPS_CreateVariableProfile($p, VARIABLETYPE_BOOLEAN);
        }
        if ($this->ReadPropertyString('Kind') === 'Ausgang') {
            IPS_SetVariableProfileAssociation($p, false, 'Aus', '', -1);
            IPS_SetVariableProfileAssociation($p, true, 'Ein', '', 0x3A7BD5);
            IPS_SetVariableProfileIcon($p, 'Power');
        } else {
            IPS_SetVariableProfileAssociation($p, false, 'Ruhe', '', -1);
            IPS_SetVariableProfileAssociation($p, true, 'Ausgelöst', '', 0xF5A623);
            IPS_SetVariableProfileIcon($p, 'Motion');
        }
    }
}
