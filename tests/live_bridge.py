#!/usr/bin/env python3
"""Live-Test des Moduls an der echten Zentrale über einen USB-RS232-Adapter.

Startet tests/live.php (Modul in den Symcon-Stubs) und reicht die serielle
Schnittstelle durch – wie es in Symcon der Client Socket mit dem IP-Wandler tut.

Aufruf: live_bridge.py <device> <sekunden> [<zeit>:<CMD ...> ...]
  z. B. live_bridge.py /dev/cu.usbserial-210 60 "5:state 2 0" "20:state 2 2"
Zeiten in Sekunden ab Start. Baudrate fest 9600 8N1 (GMS lite).
"""
import os, sys, time, termios, select, subprocess

dev, dauer = sys.argv[1], float(sys.argv[2])
plan = []
for a in sys.argv[3:]:
    t, cmd = a.split(":", 1)
    plan.append((float(t), cmd))
plan.sort()

fd = os.open(dev, os.O_RDWR | os.O_NOCTTY | os.O_NONBLOCK)
attrs = termios.tcgetattr(fd)
attrs[0] = attrs[1] = attrs[3] = 0
attrs[2] = termios.CS8 | termios.CREAD | termios.CLOCAL
attrs[4] = attrs[5] = termios.B9600
termios.tcsetattr(fd, termios.TCSANOW, attrs)
termios.tcflush(fd, termios.TCIFLUSH)

php = subprocess.Popen(["php", os.path.join(os.path.dirname(os.path.abspath(__file__)), "live.php")],
                       stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True, bufsize=1)
start = time.time()

def stamp():
    t = time.time()
    return f"{time.strftime('%H:%M:%S')}.{int(t % 1 * 1000):03d}"

def send(line):
    php.stdin.write(line + "\n"); php.stdin.flush()
    while True:
        out = php.stdout.readline()
        if not out:
            raise SystemExit("php beendet")
        out = out.rstrip("\n")
        if out == "END":
            return
        if out.startswith("TX "):
            data = bytes.fromhex(out[3:])
            os.write(fd, data)
            if data != bytes.fromhex("6802026800020216"):
                print(f"{stamp()} TX {data.hex(' ')}", flush=True)
        else:
            print(f"{stamp()} {out}", flush=True)

while time.time() - start < dauer:
    while plan and time.time() - start >= plan[0][0]:
        _, cmd = plan.pop(0)
        print(f"{stamp()} CMD {cmd}", flush=True)
        send("CMD " + cmd)
    r, _, _ = select.select([fd], [], [], 0.1)
    if r:
        try:
            chunk = os.read(fd, 4096)
        except BlockingIOError:
            chunk = b""
        if chunk:
            if os.environ.get("VERBOSE") and chunk != bytes.fromhex("6802026840024216"):
                print(f"{stamp()} RX {chunk.hex(' ')}", flush=True)
            send("RX " + chunk.hex())
send("CMD contacts")
php.stdin.close()
php.wait()
os.close(fd)
