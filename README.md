# Telenot für IP-Symcon

Einbindung einer Telenot-Einbruchmelderzentrale (hiplex 8400H, complex 400H) über die
serielle GMS-Schnittstelle. Im Normalfall hängt die Zentrale an einem IP-RS232-Wandler
(z. B. USR-TCP232-302), den Symcon über einen Client Socket anspricht.

## Funktionen

- **Sicherungsbereiche:** Zustand unscharf / intern scharf / extern scharf (schaltbar), Alarm
  (zurücksetzbar), Störung, bereit intern / extern, interner Signalgeber
- **Melder, Ausgänge, Meldebereiche:** je eine Boolean-Variable; Ausgänge (Schaltaktionen) sind schaltbar
  (`TLN_SwitchOutput($id, $Adresse, $Ein)` oder über die Variable der Instanz), Meldebereiche sperrbar
  (`TLN_SetBlocked($id, $Adresse, $Gesperrt)` oder über die Variable der Instanz)
- **Ereignisprotokoll** mit Klartext aus der Zentrale („Extern scharf – GMS-Schnittst. (RS232) / Bestattungshaus“)
- **Automatische Anlage:** Der Konfigurator liest die belegten Adressen und deren Namen aus
  der Zentrale und legt die Instanzen per Klick an
- **Online-Variable** im Gateway und in jeder Instanz

## Module

| Modul | Typ | Aufgabe |
|---|---|---|
| Telenot Gateway | Splitter | Protokoll, Quittungen, Befehlswarteschlange, Auslesen, Ereignisprotokoll |
| Telenot Area | Gerät | ein Sicherungsbereich (1–8) |
| Telenot Contact | Gerät | ein Melder, Ausgang oder Meldebereich (Adresse) |
| Telenot Configurator | Konfigurator | zeigt alles aus der Zentrale, legt Instanzen an |

## Einrichtung

1. **compasX:** Die serielle Schnittstelle der Zentrale auf **GMS lite** stellen (9600 Baud, 8N1).
   Unter **GMS+** (115200 Baud) kommen zwar alle Zustände an, die Zentrale lehnt aber jeden
   Schaltbefehl ab, und ein abgelehnter Befehl kann die Schnittstelle bis zum Neustart der
   Zentrale stumm schalten.
2. **IP-RS232-Wandler:** in seiner Weboberfläche als **TCP-Server**, **9600 8N1** einstellen.
   Live in Symcon im Einsatz: Waveshare RS232/485/422 TO POE ETH (B), Port 4196.
3. **Symcon:** Instanz „Telenot Gateway“ anlegen, IP und Port des Wandlers eintragen. Der Client
   Socket wird automatisch angelegt und bekommt Host/Port vom Gateway. Zentralentyp wählen.
4. Instanz „Telenot Configurator“ anlegen (hängt sich an das Gateway), **„Aus der Zentrale
   lesen“** drücken. Das dauert pro Kontakt etwa einen Abfragezyklus (ca. 3 s), bei 80 Kontakten
   also gut 4 Minuten. Danach das Formular neu öffnen und die gewünschten Instanzen anlegen.

Ein Serial Port statt Client Socket funktioniert genauso (gleicher Datenfluss).

**Sicherer: Wandler als TCP-Client.** Als TCP-Server nimmt der Wandler Verbindungen von jedem Gerät im
Netz an – und die GMS-Schnittstelle kennt keinen Code. Besser: Wandler auf **TCP Client** mit Ziel
SymBox-IP und Port stellen und in Symcon statt des Client Sockets einen **Server Socket** (gleicher
Port, **Limit = 1**) als Parent des Gateways verwenden. Dann hält der Wandler die einzige zulässige
Verbindung; die Selbstheilung des Gateways setzt bei Stille auch den Server Socket zurück.
Zusätzlich das Web-Passwort des Wandlers ändern (Werkseinstellung bei Waveshare: `admin`).

## Protokoll (GMS)

An einer hiplex 8400H mitgeschnitten und verifiziert; Befehlsaufbau nach dem
[SmartHome/J-Telenot-Binding](https://github.com/smarthomej/addons/tree/main/bundles/org.smarthomej.binding.telenot).

- Rahmen `68 L L 68 <Daten> CS 16`, CS = Summe der Daten & 0xFF
- Die Zentrale fragt alle 3 s mit `68 02 02 68 40 02 42 16` an. Ohne Quittung
  `68 02 02 68 00 02 02 16` schickt sie keine Daten. Jeder Datenrahmen wird ebenfalls quittiert,
  und zwar **sofort** – eine um 250 ms verspätete Quittung reicht, damit die Zentrale den
  folgenden Befehl verwirft.
- Zustandsblöcke (Typ `0x24`): Melder ab Adresse `0x0000`, Bereiche/Ausgänge/Meldebereiche ab
  `0x0500`. Jede Adresse ist ein Bit, LSB zuerst, **0 = aktiv**.
- Bereichsbyte: Bit 0 unscharf, 1 intern scharf, 2 extern scharf, 3 Alarm, 4 Störung,
  5 bereit intern, 6 bereit extern, 7 interner Signalgeber
  (`0x9E` unscharf, `0x9B` extern scharf, `0x76` Sabotage).
- Zwischen der Quittung des Bereichsblocks und dem Befehl muss eine kurze Pause liegen. Kommen
  beide im selben Paket an, verwirft die Zentrale den Befehl (50 ms bis 2 s funktionieren). Weil
  der Client Socket erst sendet, wenn `ReceiveData` zurückkehrt, schickt das Gateway Befehle über
  einen eigenen Timer (200 ms) statt mit `IPS_Sleep` in `ReceiveData`.
- Befehle werden nach der Quittung des Bereichsblocks gesendet:
  `68 09 09 68 73 01 05 02 00 <Adresse> 02 <Code> CS 16`
  mit Adresse = Bereichsbasis + 8·(Bereich−1) + Offset:
  unscharf +0/`E1`, intern +1/`62`, extern +2/`61`, Rückstellung +3/`52`.
  Bereichsbasis: hiplex `0x0500`, complex 400 `0x0530`.
- Ausgang / Schaltaktion: `68 09 09 68 73 01 05 02 00 <Adresse> 02 <00 = ein | 80 = aus> CS 16`.
  An der hiplex 8400H unter GMS lite live verifiziert (0x057B „Motorschloss“ ein → Tür entriegelt,
  aus → wieder zu). Bei der hiplex sind das die Schaltaktionen aus hipas (Typ Ein/Aus); die Adresse steht
  im Konfigurator unter „Ausgänge“.
- Meldebereich sperren / freigeben: `68 09 09 68 73 01 05 02 00 <Adresse> 02 <51 = sperren | D1 = freigeben> CS 16`.
  An der hiplex 8400H unter GMS lite live verifiziert (Bentheim, 09.11.2026, 0x05BF „Fenster Gäste WC“):
  die Zentrale quittiert, meldet das Ereignis 0x51/0xD1 mit „IP-Symcon Haussteuerung“ und setzt bzw.
  löscht das Bit im Bereichsblock (Bit aktiv = gesperrt). Sperrt man am Bedienteil oder in BuildSec
  („AT-Taste n“), kommen dieselben Ereignisse und Bits. Die Meldebereiche meldet die Zentrale nicht als
  belegt; ihre Namen liest `TLN_ReadNames($gw, 0x05B0, 0x05D7)`.
  `TLN_SetBlocked($gw, $Adresse, $Gesperrt)` oder die Variable einer Kontakt-Instanz vom Typ Meldebereich.
- Auslesen: `73 02 05 10 00 00 00 71 24` liefert die belegten Adressen,
  `73 02 05 10 00 <Adresse> 73 0C` den Namen einer Adresse.

### Adresslayout hiplex 8400H (Demo-Zentrale)

| Adressen | Inhalt |
|---|---|
| `0x0010`–`0x002F` | Meldergruppen |
| `0x0500`–`0x053F` | Sicherungsbereiche 1–8 |
| `0x0540`–`0x05B7` | Ausgänge / Schaltfunktionen |
| ab `0x05B8` | Meldebereiche |

Die Grenzen für Ausgänge und Meldebereiche sind aus einer einzigen Zentrale abgeleitet.

## Stand

- **Live verifiziert** (hiplex 8400H, GMS lite, USB-RS232 über `tests/live_bridge.py`): Zustände,
  Ereignisse, extern scharf / unscharf über das Modul, Auslesen der Namen.
- **Nicht live getestet:** intern scharf, Alarm zurücksetzen (gleicher Befehlsaufbau, andere
  Codes), complex 400, Betrieb über den USR-TCP232-302 in einer echten Symcon-Instanz.

## Tests

```
php tests/run.php                                        # Stubs + echte Telegramme
python3 tests/live_bridge.py /dev/cu.usbserial-XXX 60 "5:state 1 2"   # live an der Zentrale
```
