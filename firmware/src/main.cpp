// JK BMS Monitor — ESP32 bridge firmware.
//
// Scans for a JK BMS over BLE (service 0xFFE0), requests a cell-info dump
// every 30s (FR-002), parses it (see jk_bms_parser.h), and POSTs the reading
// to the backend ingest API (FR-002/FR-003). Reconnects WiFi and BLE on its
// own after a drop (NFR: resumes without manual intervention).
//
// Board: Seeed XIAO ESP32-S3. Library: NimBLE-Arduino (lighter/more stable
// than the stock ESP32 BLE stack for a long-running unattended device).

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <NimBLEDevice.h>

#include "secrets.h"
#include "jk_bms_parser.h"

static const uint32_t READING_INTERVAL_MS = 30000; // FR-002
static const uint32_t BLE_SCAN_SECONDS = 10;
static const uint32_t WIFI_RECONNECT_INTERVAL_MS = 5000;

// ---------------------------------------------------------------------
// BLE state
// ---------------------------------------------------------------------

static NimBLEAdvertisedDevice *targetDevice = nullptr;
static NimBLEClient *bleClient = nullptr;
static NimBLERemoteCharacteristic *bleChar = nullptr;
static bool bleScanning = false;

static uint8_t frameBuf[JK_RESPONSE_FRAME_LENGTH];
static size_t frameBufLen = 0;
static volatile bool frameReady = false;
static JkBmsReading latestReading;

class JkScanCallbacks : public NimBLEAdvertisedDeviceCallbacks {
    void onResult(NimBLEAdvertisedDevice *advertisedDevice) override {
        if (advertisedDevice->isAdvertisingService(NimBLEUUID(JK_BLE_SERVICE_UUID))) {
            Serial.printf("Found JK BMS candidate: %s\n", advertisedDevice->getAddress().toString().c_str());
            NimBLEDevice::getScan()->stop();
            targetDevice = new NimBLEAdvertisedDevice(*advertisedDevice);
        }
    }
};

class JkClientCallbacks : public NimBLEClientCallbacks {
    void onDisconnect(NimBLEClient *pClient) override {
        Serial.println("BLE disconnected — will rescan.");
        bleChar = nullptr;
    }
};

void onNotify(NimBLERemoteCharacteristic *pChar, uint8_t *pData, size_t length, bool isNotify) {
    // A full frame is JK_RESPONSE_FRAME_LENGTH bytes; BLE notifications
    // arrive in small (MTU-limited) chunks — buffer and reassemble here.
    if (frameBufLen == 0) {
        if (length < 4 || !jkFrameHasValidPreamble(pData)) {
            return; // stray chunk that isn't the start of a frame — discard
        }
    }
    for (size_t i = 0; i < length && frameBufLen < JK_RESPONSE_FRAME_LENGTH; i++) {
        frameBuf[frameBufLen++] = pData[i];
    }
    if (frameBufLen >= JK_RESPONSE_FRAME_LENGTH) {
        uint8_t frameType = frameBuf[4];
        if (frameType != JK_RESPONSE_TYPE_CELL_INFO) {
            // The BMS also auto-pushes 0x01 (settings) and 0x03 (device info)
            // frames — not an error, just not what we're looking for.
            Serial.printf("Received frame type 0x%02X (not cell info 0x%02X) — ignoring.\n", frameType, JK_RESPONSE_TYPE_CELL_INFO);
            frameBufLen = 0;
            return;
        }

        JkBmsReading r = jkParseCellInfoFrame(frameBuf, JK_RESPONSE_FRAME_LENGTH);
        if (r.valid) {
            latestReading = r;
            frameReady = true;
        } else {
            Serial.println("Cell-info frame received but failed checksum validation — dropped.");
            jkLogFrameDiagnostics(frameBuf, JK_RESPONSE_FRAME_LENGTH);
        }
        frameBufLen = 0;
    }
}

bool bleIsReady() {
    return bleClient != nullptr && bleClient->isConnected() && bleChar != nullptr;
}

void requestReading(); // forward decl — used by connectBleIfNeeded() below, defined further down

void startBleScan() {
    if (bleScanning || targetDevice != nullptr) return;
    Serial.println("Scanning for JK BMS...");
    NimBLEScan *scan = NimBLEDevice::getScan();
    scan->setAdvertisedDeviceCallbacks(new JkScanCallbacks());
    scan->setActiveScan(true);
    scan->setInterval(100);
    scan->setWindow(99);
    bleScanning = true;
    scan->start(BLE_SCAN_SECONDS, [](NimBLEScanResults) {
        bleScanning = false;
    });
}

void connectBleIfNeeded() {
    if (bleIsReady()) return;

    if (targetDevice == nullptr) {
        startBleScan();
        return;
    }

    Serial.println("Connecting to JK BMS...");
    if (bleClient == nullptr) {
        bleClient = NimBLEDevice::createClient();
        bleClient->setClientCallbacks(new JkClientCallbacks());
    }

    if (!bleClient->connect(targetDevice)) {
        Serial.println("BLE connect failed — will retry.");
        delete targetDevice;
        targetDevice = nullptr;
        return;
    }

    NimBLERemoteService *service = bleClient->getService(JK_BLE_SERVICE_UUID);
    if (service == nullptr) {
        Serial.println("JK BLE service not found on connected device — disconnecting.");
        bleClient->disconnect();
        delete targetDevice;
        targetDevice = nullptr;
        return;
    }

    bleChar = service->getCharacteristic(JK_BLE_CHAR_UUID);
    if (bleChar == nullptr || !bleChar->canNotify()) {
        Serial.println("JK BLE characteristic not usable — disconnecting.");
        bleClient->disconnect();
        bleChar = nullptr;
        delete targetDevice;
        targetDevice = nullptr;
        return;
    }

    bleChar->subscribe(true, onNotify);
    Serial.println("BLE connected and subscribed.");

    // Mirrors the working sequence in schweizp/jkbms_ble (Python): a raw
    // "01 00" enable write to the characteristic itself (distinct from the
    // CCCD subscribe above), then request device info (0x97) BEFORE cell
    // info (0x96) — on real hardware, 0x96 alone never produced a 0x02
    // response; this ordering is what a confirmed-working implementation
    // does before the BMS starts answering 0x96 requests.
    uint8_t enableFrame[2] = {0x01, 0x00};
    bleChar->writeValue(enableFrame, sizeof(enableFrame), false);
    delay(200);

    uint8_t deviceInfoFrame[20];
    jkBuildRequestFrame(JK_COMMAND_DEVICE_INFO, deviceInfoFrame);
    bleChar->writeValue(deviceInfoFrame, sizeof(deviceInfoFrame), false);
    Serial.println("Sent enable + device-info (0x97) request.");
    delay(500);

    requestReading();
}

void requestReading() {
    if (!bleIsReady()) {
        Serial.println("requestReading: BLE not ready, skipping.");
        return;
    }
    uint8_t frame[20];
    jkBuildRequestFrame(JK_COMMAND_CELL_INFO, frame);

    Serial.printf(
        "requestReading: canWrite=%d canWriteNoResponse=%d — frame: ",
        bleChar->canWrite(), bleChar->canWriteNoResponse()
    );
    for (int i = 0; i < 20; i++) Serial.printf("%02X ", frame[i]);
    Serial.println();

    bool ok = bleChar->writeValue(frame, sizeof(frame), false);
    Serial.printf("requestReading: writeValue (no response) returned %s\n", ok ? "true" : "false");

    if (!ok) {
        bool ok2 = bleChar->writeValue(frame, sizeof(frame), true);
        Serial.printf("requestReading: retried with response, returned %s\n", ok2 ? "true" : "false");
    }
}

// ---------------------------------------------------------------------
// WiFi + HTTP
// ---------------------------------------------------------------------

void connectWifiIfNeeded() {
    if (WiFi.status() == WL_CONNECTED) return;
    static uint32_t lastAttempt = 0;
    if (millis() - lastAttempt < WIFI_RECONNECT_INTERVAL_MS) return;
    lastAttempt = millis();
    Serial.println("Connecting to WiFi...");
    WiFi.mode(WIFI_STA);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
}

void postReading(const JkBmsReading &r) {
    if (WiFi.status() != WL_CONNECTED) return;

    JsonDocument doc;
    doc["soc_percent"] = r.socPercent;
    doc["pack_voltage"] = r.packVoltage;
    doc["current_amps"] = r.currentAmps;
    doc["temp_max_c"] = r.tempMaxC;
    doc["cell_voltage_min_mv"] = r.cellVoltageMinMv;
    doc["cell_voltage_max_mv"] = r.cellVoltageMaxMv;
    doc["remaining_capacity_ah"] = r.remainingCapacityAh;
    doc["full_capacity_ah"] = r.fullCapacityAh;
    doc["charge_mosfet_on"] = r.chargeMosfetOn;
    doc["discharge_mosfet_on"] = r.dischargeMosfetOn;

    String body;
    serializeJson(doc, body);

    WiFiClientSecure client;
    client.setInsecure(); // PoC: no cert pinning yet — see README "Known simplifications"

    HTTPClient http;
    http.begin(client, INGEST_URL);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Device-Key", DEVICE_KEY);

    int status = http.POST(body);
    Serial.printf("POST /api/ingest -> HTTP %d\n", status);
    if (status <= 0) {
        Serial.printf("  error: %s\n", http.errorToString(status).c_str());
    }
    http.end();
}

// ---------------------------------------------------------------------
// Arduino entry points
// ---------------------------------------------------------------------

void setup() {
    Serial.begin(115200);
    delay(2000); // let the native USB CDC endpoint enumerate before printing
    while (!Serial && millis() < 10000) {
        delay(100);
    }
    Serial.println("\nJK BMS Monitor firmware starting...");

    WiFi.mode(WIFI_STA);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

    NimBLEDevice::init("JKBMS-Bridge");
}

void loop() {
    connectWifiIfNeeded();
    connectBleIfNeeded();

    static uint32_t lastRequest = 0;
    if (bleIsReady() && millis() - lastRequest >= READING_INTERVAL_MS) {
        lastRequest = millis();
        requestReading();
    }

    if (frameReady) {
        frameReady = false;
        postReading(latestReading);
    }

    delay(20);
}
