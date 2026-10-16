<?php

declare(strict_types=1);
include_once __DIR__ . '/../VirtualIO/module.php';

class ClientSocket extends VirtualIO
{
    // Ergänzt (Symcon-Telenot): der echte Client Socket hat Host und Port
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 0);
    }

    // Ergänzt (Symcon-Telenot): im Live-Test sofort senden wie der echte Socket,
    // statt bis zum Ende des Aufrufs zu puffern (sonst kommt jede Quittung zu spät)
    public function ForwardData($JSONString)
    {
        if (isset($GLOBALS['TLN_LIVE_TX'])) {
            $data = json_decode($JSONString, true);
            call_user_func($GLOBALS['TLN_LIVE_TX'], utf8_decode($data['Buffer']));
            return;
        }
        parent::ForwardData($JSONString);
    }
}
