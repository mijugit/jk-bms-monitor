// JK BMS BLE protocol — command framing + response parsing.
//
// Protocol reference: community reverse-engineering in syssi/esphome-jk-bms
// (components/jk_bms_ble/jk_bms_ble.cpp), the JK02_24S variant (hardware
// versions ~6.0-11.0, the common case for a self-assembled 24S-or-fewer
// LiFePO4 pack). If your BMS reports garbage, check its hardware/software
// version against that project's protocol table — newer 32S boards (JK02_32S)
// shift several offsets used below.
//
// NOT independently verified against real hardware by this codebase yet —
// current sign and temperature sign are implemented as plain two's-complement
// reads (the most defensible default absent a confirmed spec for those two
// fields specifically). Verify against a real pack: charge vs. discharge
// should flip the sign of current_amps; a cold reading should go negative
// for temp_max_c. Adjust the two spots marked below if reality disagrees.

#pragma once

#include <Arduino.h>
#include <cstring>

// BLE UART-bridge service JK BMS boards expose (Nordic-style HM-10 clone).
static const char *JK_BLE_SERVICE_UUID = "0000ffe0-0000-1000-8000-00805f9b34fb";
static const char *JK_BLE_CHAR_UUID    = "0000ffe1-0000-1000-8000-00805f9b34fb";

static const uint8_t JK_COMMAND_CELL_INFO = 0x96;
static const uint8_t JK_COMMAND_DEVICE_INFO = 0x97;

static const uint8_t JK_RESPONSE_PREAMBLE[4] = {0x55, 0xAA, 0xEB, 0x90};
static const size_t JK_RESPONSE_FRAME_LENGTH = 300;
static const uint8_t JK_RESPONSE_TYPE_CELL_INFO = 0x02;

struct JkBmsReading {
    bool valid = false;
    float packVoltage = 0;        // V
    float currentAmps = 0;        // A (+ charge / - discharge, unverified — see file header)
    uint8_t socPercent = 0;       // %
    float tempMaxC = -999;        // highest of the reported temp sensors
    uint16_t cellVoltageMinMv = 0;
    uint16_t cellVoltageMaxMv = 0;
    float remainingCapacityAh = 0;
    float fullCapacityAh = 0;
    bool chargeMosfetOn = false;
    bool dischargeMosfetOn = false;
};

// Builds the 20-byte command frame requesting a data dump for `command`
// (see jk_bms_ble.cpp: header AA 55 90 EB, address, length, 32-bit value,
// zero padding, then a trailing checksum byte = sum of all prior bytes).
inline void jkBuildRequestFrame(uint8_t command, uint8_t out[20]) {
    memset(out, 0, 20);
    out[0] = 0xAA;
    out[1] = 0x55;
    out[2] = 0x90;
    out[3] = 0xEB;
    out[4] = command;
    out[5] = 0x00; // length of the 32-bit value field below (0 = no extra param for a read request)
    // out[6..9] = 0 (32-bit value, unused for a plain read request)
    // out[10..18] = 0 (padding)

    uint32_t sum = 0;
    for (int i = 0; i < 19; i++) {
        sum += out[i];
    }
    out[19] = (uint8_t) (sum & 0xFF);
}

inline bool jkFrameHasValidPreamble(const uint8_t *buf) {
    return memcmp(buf, JK_RESPONSE_PREAMBLE, 4) == 0;
}

inline bool jkFrameChecksumValid(const uint8_t *buf, size_t len) {
    uint32_t sum = 0;
    for (size_t i = 0; i < len - 1; i++) {
        sum += buf[i];
    }
    return (uint8_t) (sum & 0xFF) == buf[len - 1];
}

inline uint16_t readLE16(const uint8_t *p) {
    return (uint16_t) p[0] | ((uint16_t) p[1] << 8);
}

inline int32_t readLE32(const uint8_t *p) {
    return (int32_t) ((uint32_t) p[0] | ((uint32_t) p[1] << 8) | ((uint32_t) p[2] << 16) | ((uint32_t) p[3] << 24));
}

// Diagnostic dump for a frame that failed validation — prints enough to tell
// apart "wrong preamble" (garbage/desync) vs "wrong type" (e.g. an auth
// challenge or activation frame instead of cell-info) vs "right shape but
// bad checksum" (real parsing bug), rather than a single opaque "dropped".
inline void jkLogFrameDiagnostics(const uint8_t *frame, size_t len) {
    Serial.printf("  frame diagnostics: length=%u (expected %u)\n", (unsigned) len, (unsigned) JK_RESPONSE_FRAME_LENGTH);
    Serial.print("  first 16 bytes: ");
    for (size_t i = 0; i < 16 && i < len; i++) {
        Serial.printf("%02X ", frame[i]);
    }
    Serial.println();
    if (len >= 4) {
        Serial.printf("  preamble valid: %s\n", jkFrameHasValidPreamble(frame) ? "yes" : "NO");
    }
    if (len >= 5) {
        Serial.printf("  type byte (offset 4): 0x%02X (expected 0x%02X for cell info)\n", frame[4], JK_RESPONSE_TYPE_CELL_INFO);
    }
    if (len == JK_RESPONSE_FRAME_LENGTH) {
        uint32_t sum = 0;
        for (size_t i = 0; i < len - 1; i++) sum += frame[i];
        Serial.printf("  checksum: computed=0x%02X received=0x%02X\n", (uint8_t) (sum & 0xFF), frame[len - 1]);
    }
}

// Parses a complete, checksum-validated 300-byte cell-info (0x02) frame.
// Returns a JkBmsReading with valid=false if the frame doesn't look right.
inline JkBmsReading jkParseCellInfoFrame(const uint8_t *frame, size_t len) {
    JkBmsReading r;

    if (len != JK_RESPONSE_FRAME_LENGTH) return r;
    if (!jkFrameHasValidPreamble(frame)) return r;
    if (frame[4] != JK_RESPONSE_TYPE_CELL_INFO) return r;
    if (!jkFrameChecksumValid(frame, len)) return r;

    // Cell voltages: 24 slots x 2 bytes LE, offset 6..53, millivolts.
    // A slot reading 0 means "not populated" (fewer than 24 cells wired).
    uint16_t minMv = 0;
    uint16_t maxMv = 0;
    bool any = false;
    for (int cell = 0; cell < 24; cell++) {
        uint16_t mv = readLE16(frame + 6 + cell * 2);
        if (mv == 0) continue;
        if (!any || mv < minMv) minMv = mv;
        if (!any || mv > maxMv) maxMv = mv;
        any = true;
    }
    r.cellVoltageMinMv = minMv;
    r.cellVoltageMaxMv = maxMv;

    r.packVoltage = readLE32(frame + 118) * 0.001f;
    r.currentAmps = readLE32(frame + 126) * 0.001f; // sign: see file header note

    float t1 = (int16_t) readLE16(frame + 130) * 0.1f; // sign: see file header note
    float t2 = (int16_t) readLE16(frame + 132) * 0.1f;
    float tMos = (int16_t) readLE16(frame + 134) * 0.1f;
    r.tempMaxC = max(t1, max(t2, tMos));

    r.socPercent = frame[141];
    r.remainingCapacityAh = readLE32(frame + 142) * 0.001f;
    r.fullCapacityAh = readLE32(frame + 146) * 0.001f;
    r.chargeMosfetOn = frame[166] != 0;
    r.dischargeMosfetOn = frame[167] != 0;

    r.valid = true;
    return r;
}
