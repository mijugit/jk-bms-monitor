# Firmware

PlatformIO project for the Seeed XIAO ESP32-S3: bridges JK BMS (BLE) → this
app's ingest API (`POST /api/ingest`, header `X-Device-Key`) over WiFi.
**Verified against real hardware** (see below) — this is not a from-docs
guess, the protocol details here were confirmed against a live pack.

Not part of the web/hosting stack decision (`context/foundation/tech-stack.md`)
— built and flashed locally per device, not deployed to CyberFolks.

## Setup

```bash
cd firmware
cp include/secrets.h.example include/secrets.h
# edit include/secrets.h: WIFI_SSID, WIFI_PASSWORD, DEVICE_KEY, INGEST_URL
python -m platformio run --target upload --upload-port COMx
python -m platformio device monitor --port COMx --baud 115200
```

(`pio` alone may not be on PATH depending on how PlatformIO was installed —
`python -m platformio` always works if `pip install platformio` succeeded.)

`DEVICE_KEY` must match a row already inserted into the server's `devices`
table (see the main `README.md` § "Adding a device").

> ⚠️ **Only one BLE connection at a time.** The JK BMS's BLE module accepts
> a single active connection — if the official JK BMS phone app is
> connected, this firmware will not see fresh cell-info (`0x02`) frames
> (or will see stale/inconsistent data) until the phone disconnects, and
> vice versa. Close the phone app before testing/debugging this firmware
> against a live pack.

## How it works

- `src/jk_bms_parser.h` — JK BMS BLE protocol: builds the request command
  frame, reassembles the (MTU-fragmented) 300-byte response, validates its
  checksum, and extracts pack voltage, current, SOC, temperature, per-cell
  voltages/resistances, balance current/status, and MOSFET state.
- `src/main.cpp` — connects WiFi and BLE (service `0xFFE0`), sends the
  enable + device-info + cell-info request sequence (see below), and POSTs
  each parsed reading as JSON to `INGEST_URL`. Reconnects both WiFi and BLE
  on their own after a drop. In practice the BMS free-streams a `0x02`
  frame roughly once a second once it's in the right state, so readings
  post far more often than the nominal 30s cycle (FR-002) — the 30s timer
  only governs how often *we* re-send the request command, not how often we
  post.

## BLE handshake — this is not just "send 0x96"

On real hardware, writing the bare cell-info request (`0x96`) never
produced a `0x02` response — the BMS kept disconnecting after only sending
its own auto-pushed `0x01` (settings) / `0x03` (device info) frames. The
sequence that unlocked `0x02` responses, verified working, and mirrored
from a confirmed-working independent implementation
([schweizp/jkbms_ble](https://github.com/schweizp/jkbms_ble), Python):

1. Subscribe to notifications (CCCD) on `0xFFE1`.
2. Write a raw `01 00` "enable" frame directly to `0xFFE1` (separate from
   the CCCD subscribe — this is a data write, not a descriptor write).
3. Request device info (`0x97`) and wait briefly.
4. *Then* request cell info (`0x96`) — only after step 3 does the BMS
   start answering with `0x02` frames.

All of this happens once, right after connecting, in
`connectBleIfNeeded()`.

## Protocol source & offsets — confirmed against real hardware

Base protocol (service/characteristic UUIDs, command framing, general frame
shape) follows the community reverse-engineering in
[syssi/esphome-jk-bms](https://github.com/syssi/esphome-jk-bms) (the
`JK02_24S` variant). **However, this specific unit's field offsets are +32
bytes versus that doc** — confirmed two independent ways:

- Pack voltage (offset 150, not the doc's 118) matched the sum of the
  actual per-cell voltages to within 1mV.
- Full capacity (offset 178, not 146) read exactly 360.0 Ah, matching the
  owner's known battery bank capacity exactly.
- Per-cell resistances (offset 80, 8 populated slots, mΩ) matched the
  official JK BMS app's "Balance Wire Resistance" readout exactly
  (0.036–0.048 Ω).

All offsets in `jk_bms_parser.h` already reflect this correction. If you
adapt this firmware for a *different* JK BMS unit, don't assume these
offsets transfer — re-verify using the same method: request a reading, log
`debug_raw_frame_hex` (see git history for the diagnostic code that did
this — removed once offsets were confirmed, temporarily reintroduce it if
you need to re-derive offsets for different hardware), and cross-check
candidate offsets against values you can independently read off the
official app (pack voltage ≈ sum of cell voltages; full capacity = your
known bank size).

**Known simplifications / unverified corners:**

- **Current sign** and **temperature sign** are plain two's-complement
  reads. Charge vs. discharge should flip the sign of current; a cold
  reading should go negative for temperature — not yet confirmed at the
  extremes (only tested near room temperature, moderate discharge).
- **No TLS certificate pinning** — `WiFiClientSecure::setInsecure()`.
  Acceptable for this PoC.
- `temp_mosfet_c` reads 0.0 on this unit — likely means "sensor not
  present" rather than an actual 0°C reading; don't feed it into alerting
  without checking for that special case.

## XIAO ESP32-S3-specific gotchas (if you're bringing up a new board)

- **`Serial` needs explicit USB CDC flags.** XIAO ESP32-S3 has no separate
  UART-USB bridge chip. Without `-D ARDUINO_USB_MODE=1 -D
  ARDUINO_USB_CDC_ON_BOOT=1` (already in `platformio.ini`), `Serial.print()`
  silently goes to the physically unconnected UART0 pins instead of the
  native USB port.
- **`composer`-equivalent gotcha doesn't apply here** (that's the web
  stack), but the analogous PlatformIO one: if `esptool`'s bootloader step
  fails with `ModuleNotFoundError: No module named 'intelhex'`, run `pip
  install intelhex` — a pip-installed PlatformIO doesn't always pull this
  esptool dependency in automatically.
- If upload fails with a Windows `PermissionError` / "port is busy": close
  any other program holding the COM port (another `device monitor`,
  Arduino IDE, etc.) — only one process can own the port at a time.
- If the COM port number changes between plugs (e.g. COM4 → COM5), that's
  normal Windows USB re-enumeration — check Device Manager or
  `Get-CimInstance -ClassName Win32_PnPEntity | Where-Object Name -match 'COM\d+'`.

## Ingest contract

`POST /api/ingest`
Header: `X-Device-Key: <per-device key from the devices table>`
Body (JSON) — `main.cpp` sends all of these; the server stores anything else
you add in the payload too (`raw_json`), even fields it doesn't index as
dedicated columns (FR-001: read everything available):

```json
{
  "soc_percent": 95,
  "pack_voltage": 26.52,
  "current_amps": -5.50,
  "temp_max_c": 23.8,
  "cell_voltage_min_mv": 3312,
  "cell_voltage_max_mv": 3317,
  "remaining_capacity_ah": 342.9,
  "full_capacity_ah": 360.0,
  "charge_mosfet_on": true,
  "discharge_mosfet_on": true,
  "temp_sensor_1_c": 23.8,
  "temp_sensor_2_c": 23.4,
  "temp_mosfet_c": 0,
  "balance_current_amps": 0,
  "balancer_status": 0,
  "cell_voltages_mv": [3317, 3314, 3314, 3312, 3316, 3315, 3315, 3316],
  "cell_resistances_ohm": [0.036, 0.038, 0.039, 0.041, 0.043, 0.045, 0.047, 0.048]
}
```

`balancer_status`: `0` = off, `1` = charging balance, `2` = discharging
balance.
