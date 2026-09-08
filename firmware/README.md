# Firmware

ESP32 (XIAO-ESP32-S3) firmware bridging JK BMS (BLE) → this app's ingest API
(`POST /api/ingest`, header `X-Device-Key`) over WiFi every 30s.

Out of scope for this repository's web stack decision — installed and flashed
locally per device (Arduino/PlatformIO), not deployed alongside the web app.
Firmware source lands here once implementation starts (see
`context/foundation/prd.md` FR-001/FR-002).

## Ingest contract (draft)

`POST /api/ingest`
Header: `X-Device-Key: <per-device key from the devices table>`
Body (JSON), all fields optional:

```json
{
  "soc_percent": 82.5,
  "pack_voltage": 53.2,
  "current_amps": -12.4,
  "temp_max_c": 28.0,
  "cell_voltage_min_mv": 3298,
  "cell_voltage_max_mv": 3312
}
```

Any additional JK BMS parameters can be included in the same body — the
server stores the full payload (`raw_json`) even for fields it doesn't index
as dedicated columns (FR-001: read everything available).
