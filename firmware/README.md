# Firmware

PlatformIO project for the Seeed XIAO ESP32-S3: bridges JK BMS (BLE) → this
app's ingest API (`POST /api/ingest`, header `X-Device-Key`) over WiFi every
30s (FR-002).

Not part of the web/hosting stack decision (`context/foundation/tech-stack.md`)
— built and flashed locally per device, not deployed to CyberFolks.

## Setup

```bash
cd firmware
cp include/secrets.h.example include/secrets.h
# edit include/secrets.h: WIFI_SSID, WIFI_PASSWORD, DEVICE_KEY, INGEST_URL
pio run --target upload
pio device monitor
```

`DEVICE_KEY` must match a row already inserted into the server's `devices`
table (see the main `README.md` § "Adding a device").

## How it works

- `src/jk_bms_parser.h` — JK BMS BLE protocol: builds the request command
  frame, reassembles the (MTU-fragmented) 300-byte response, validates its
  checksum, and extracts pack voltage, current, SOC, temperature, and
  min/max cell voltage.
- `src/main.cpp` — connects WiFi and BLE (service `0xFFE0`), requests a
  reading every 30s, and POSTs the parsed values as JSON to `INGEST_URL`.
  Reconnects both WiFi and BLE on their own after a drop.

## Protocol source & known simplifications

The BLE protocol (service/characteristic UUIDs, command framing, response
field offsets) follows the community reverse-engineering in
[syssi/esphome-jk-bms](https://github.com/syssi/esphome-jk-bms) (the
`JK02_24S` variant — hardware versions roughly 6.0–11.0, the common case for
a self-assembled pack with ≤24 cells). This firmware has **not yet been
tested against real hardware** — verify before trusting it:

- **Current sign** (`current_amps`) and **temperature sign** (`temp_max_c`)
  are read as plain two's-complement values. Community sources describe the
  scale/offset for these fields but not a confirmed sign convention for this
  specific protocol variant — charge vs. discharge should flip the sign of
  current; a cold reading should go negative for temperature. If either
  comes out inverted or wrong against your real bank, fix the two spots
  marked in `jk_bms_parser.h`.
- **No TLS certificate pinning** — `WiFiClientSecure::setInsecure()` is used
  for the HTTPS POST. Acceptable for this PoC; revisit if this firmware
  outlives the PoC phase.
- If your BMS hardware/software version is outside the `JK02_24S` range
  (e.g. newer 32S boards), several byte offsets in `jk_bms_parser.h` shift —
  check the offsets against `syssi/esphome-jk-bms`'s protocol table for your
  specific version before trusting the parsed values.

## Ingest contract

`POST /api/ingest`
Header: `X-Device-Key: <per-device key from the devices table>`
Body (JSON) — `main.cpp` sends all of these; the server stores anything else
you add in the payload too (`raw_json`), even fields it doesn't index as
dedicated columns (FR-001: read everything available):

```json
{
  "soc_percent": 82.5,
  "pack_voltage": 53.2,
  "current_amps": -12.4,
  "temp_max_c": 28.0,
  "cell_voltage_min_mv": 3298,
  "cell_voltage_max_mv": 3312,
  "remaining_capacity_ah": 280.4,
  "full_capacity_ah": 360.0,
  "charge_mosfet_on": true,
  "discharge_mosfet_on": true
}
```
