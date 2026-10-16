<?php

declare(strict_types=1);

/**
 * Telenot Konfigurator: zeigt die vom Gateway ausgelesenen Bereiche, Melder,
 * Ausgänge und Meldebereiche mit ihren Namen aus der Zentrale und legt die
 * passenden Instanzen an.
 */
class TelenotConfigurator extends IPSModule
{
    const GATEWAY_GUID = '{F3393CE7-1768-4FBA-93FD-BB5348FC2CEC}';
    const AREA_GUID = '{F7AA885C-2F32-43BF-94C0-8B3EE679DAC8}';
    const CONTACT_GUID = '{31DD9AF7-803E-4CF9-B4D2-605CC3EC42BB}';

    const GROUPS = array(
        'Bereich' => array(1, 'Sicherungsbereiche'),
        'Melder' => array(2, 'Melder'),
        'Ausgang' => array(3, 'Ausgänge'),
        'Meldebereich' => array(4, 'Meldebereiche')
    );

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::GATEWAY_GUID);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->EnsureGatewayConnection();
    }

    public function ReceiveData($JSONString)
    {
        // Zustände braucht der Konfigurator nicht
    }

    /** Startet das Auslesen im Gateway (Button im Formular). */
    public function ReadContacts()
    {
        $gw = $this->GatewayID();
        if ($gw <= 0) {
            echo 'Kein Gateway verbunden.';
            return false;
        }
        TLN_StartDiscovery($gw);
        echo 'Auslesen gestartet. Es läuft im Hintergrund (ca. 1–3 s pro Kontakt) – Formular danach neu öffnen.';
        return true;
    }

    public function GetConfigurationForm()
    {
        $gw = $this->GatewayID();
        $elements = array();
        $values = array();

        if ($gw <= 0) {
            $elements[] = array('type' => 'Label', 'caption' => 'Bitte zuerst mit einem Telenot Gateway verbinden.');
            return json_encode(array('elements' => $elements, 'actions' => array(), 'status' => array()));
        }

        $info = json_decode(TLN_GetContacts($gw), true);
        $contacts = isset($info['Contacts']) && is_array($info['Contacts']) ? $info['Contacts'] : array();
        $areaBase = isset($info['AreaBase']) ? (int) $info['AreaBase'] : 0x0500;
        $disc = isset($info['Discovery']) ? $info['Discovery'] : array('Phase' => 'idle', 'Total' => 0, 'Open' => 0);

        if ($disc['Phase'] === 'used') {
            $status = 'Auslesen läuft: frage benutzte Adressen ab …';
        } elseif ($disc['Phase'] === 'names') {
            $status = sprintf('Auslesen läuft: %d von %d Namen gelesen', $disc['Total'] - $disc['Open'], $disc['Total']);
        } elseif (count($contacts) === 0) {
            $status = 'Noch nichts ausgelesen. „Aus der Zentrale lesen“ drücken (Zentrale muss online sein).';
        } else {
            $status = sprintf('%d Kontakte aus der Zentrale bekannt.', count($contacts));
        }
        if (empty($info['Online'])) {
            $status .= '  Achtung: Gateway ist offline.';
        }

        $elements[] = array(
            'type' => 'RowLayout',
            'items' => array(
                array('type' => 'Button', 'caption' => 'Aus der Zentrale lesen', 'onClick' => 'TLN_ReadContacts($id);'),
                array('type' => 'Label', 'caption' => $status)
            )
        );

        foreach (self::GROUPS as $kind => $g) {
            $values[] = array(
                'id' => $g[0],
                'parent' => 0,
                'address' => '',
                'name' => $g[1],
                'kind' => '',
                'expanded' => $kind === 'Bereich',
                'instanceID' => 0
            );
        }

        $addrs = array_map('intval', array_keys($contacts));
        sort($addrs);
        foreach ($addrs as $addr) {
            $c = $contacts[(string) $addr];
            $kind = isset($c['Kind']) ? $c['Kind'] : 'Melder';
            if (!isset(self::GROUPS[$kind])) {
                continue;
            }
            $name = isset($c['Name']) ? trim((string) $c['Name']) : '';

            if ($kind === 'Bereich') {
                $area = intdiv($addr - $areaBase, 8) + 1;
                $label = $name !== '' ? $name : 'Bereich ' . $area;
                $values[] = array(
                    'id' => 1000 + $addr,
                    'parent' => self::GROUPS[$kind][0],
                    'address' => (string) $area,
                    'name' => $label,
                    'kind' => 'Sicherungsbereich',
                    'instanceID' => $this->FindInstance(self::AREA_GUID, 'Area', $area, $gw),
                    'create' => array(
                        'moduleID' => self::AREA_GUID,
                        'name' => $label,
                        'configuration' => array('Area' => $area, 'GatewayID' => $gw)
                    )
                );
                continue;
            }

            $label = $name !== '' ? $name : sprintf('%s 0x%04X', $kind, $addr);
            $values[] = array(
                'id' => 1000 + $addr,
                'parent' => self::GROUPS[$kind][0],
                'address' => sprintf('0x%04X', $addr),
                'name' => $label,
                'kind' => $kind,
                'instanceID' => $this->FindInstance(self::CONTACT_GUID, 'Address', $addr, $gw),
                'create' => array(
                    'moduleID' => self::CONTACT_GUID,
                    'name' => $label,
                    'configuration' => array('Address' => $addr, 'Kind' => $kind, 'GatewayID' => $gw)
                )
            );
        }

        $elements[] = array(
            'type' => 'Configurator',
            'name' => 'Contacts',
            'caption' => 'Telenot Zentrale',
            'rowCount' => 20,
            'add' => false,
            'delete' => true,
            'columns' => array(
                array('caption' => 'Adresse / Nr.', 'name' => 'address', 'width' => '120px'),
                array('caption' => 'Name', 'name' => 'name', 'width' => 'auto'),
                array('caption' => 'Art', 'name' => 'kind', 'width' => '160px')
            ),
            'values' => $values
        );

        return json_encode(array('elements' => $elements, 'actions' => array(), 'status' => array()));
    }

    private function FindInstance($moduleID, $property, $value, $gw)
    {
        $ids = @IPS_GetInstanceListByModuleID($moduleID);
        if (!is_array($ids)) {
            return 0;
        }
        foreach ($ids as $id) {
            $inst = @IPS_GetInstance($id);
            $parent = is_array($inst) ? (int) $inst['ConnectionID'] : 0;
            $belongs = $parent === $gw || ($parent === 0 && (int) @IPS_GetProperty($id, 'GatewayID') === $gw);
            if ($belongs && (int) @IPS_GetProperty($id, $property) === (int) $value) {
                return (int) $id;
            }
        }
        return 0;
    }

    private function GatewayID()
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        return is_array($inst) ? (int) $inst['ConnectionID'] : 0;
    }

    private function EnsureGatewayConnection()
    {
        if ($this->GatewayID() > 0) {
            return;
        }
        $all = @IPS_GetInstanceListByModuleID(self::GATEWAY_GUID);
        if (is_array($all) && count($all) === 1) {
            @IPS_ConnectInstance($this->InstanceID, $all[0]);
        }
    }
}
