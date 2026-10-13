/**
 * Microi IoT Sequential Live Tracker - Standalone Raw AT Command Engine
 * =========================================================================
 * Board: Arduino Uno R3 (ATmega328P, 5V, 16MHz)
 * Shield: DFRobot SIM808 GPS/GPRS/GSM Shield (SKU: TEL0097)
 * Dependencies: NONE (Pure C++ / Arduino.h - no third-party libraries needed!)
 *
 * Hardware Notes (Stacked Directly on Uno):
 * -------------------------------------------------------------------------
 * - Hardware Serial: Pins D0 (RX) & D1 (TX) connect directly to SIM808.
 * - Pin D12: Connected to SIM808 module power GPIO (PWRKEY).
 * - Function Switch on Shield:
 *     [ None ]   -> MUST be in this position when downloading/uploading sketch!
 *     [ Arduino ]-> Move to this position AFTER upload to communicate with SIM808!
 *     [ USB_DBG ]-> Direct PC debugging mode.
 * - Power: External 7-12V (2A) DC power supply plugged into Arduino barrel jack.
 *
 * Operational Strategy (Time-Multiplexed 3-Minute Cadence):
 * -------------------------------------------------------------------------
 * 1. Turn ON GNSS engine:
 *      AT+CGNSPWR=1
 * 2. Poll coordinates on-demand (no continuous UART spam):
 *      AT+CGNSINF
 *      Parse: +CGNSINF: 1,1,YYYYMMDDhhmmss.sss,lat,lon,alt,speed,heading,...
 * 3. Turn OFF GNSS immediately once fixed to free UART and drop power:
 *      AT+CGNSPWR=0
 * 4. Bring up GPRS connection:
 *      AT+SAPBR=3,1,"CONTYPE","GPRS"
 *      AT+SAPBR=3,1,"APN","internet"
 *      AT+SAPBR=1,1
 * 5. Ingest telemetry into Microi Platform via HTTP GET:
 *      AT+HTTPINIT
 *      AT+HTTPPARA="CID",1
 *      AT+HTTPPARA="URL","http://microi.micromek.net/api/v1/device/telemetry?device_id=...&api_key=...&latitude=...&longitude=...&speed=...&heading=...&altitude=...&recorded_at=..."
 *      AT+HTTPACTION=0
 *      AT+HTTPTERM
 * 6. Shut down GPRS bearer:
 *      AT+SAPBR=0,1
 * 7. Sleep for 3 minutes (180,000 ms) before next sequential run.
 */

#include <Arduino.h>

// TEL0097 Shield Pin Definition
#define PIN_SIM808_PWR 12 // Digital pin connected to SIM808 power GPIO (PWRKEY)

// ==============================================================================
// DEVICE CREDENTIALS & SERVER CONFIGURATION
// ==============================================================================
// Microi Provisioned Device Credentials
const char DEVICE_ID[] PROGMEM   = "MEK-SIM808-01";
const char DEVICE_KEY[] PROGMEM  = "MEK_9GHVCK5ROYFIWGCR7ZTQB5AA";

// Cellular APN (Malawi Airtel / TNM: "internet")
const char CELLULAR_APN[] PROGMEM = "internet";

// Microi Ingestion API Target
const char SERVER_URL[] PROGMEM   = "http://microi.micromek.net/api/v1/device/telemetry";

// Cadence: Every 3 minutes (180,000 milliseconds)
const unsigned long TRACKING_INTERVAL_MS = 180000UL;

// ==============================================================================
// RUNTIME DATA & COMPACT AT BUFFER
// ==============================================================================
unsigned long lastCycleTime = 0;

struct TelemetrySnapshot {
  float latitude;
  float longitude;
  float speedKph;
  float heading;
  float altitude;
  char  isoTimestamp[22];
};

TelemetrySnapshot currentData;

// Shared circular AT response buffer (160 bytes keeps Uno 2KB SRAM safe)
#define RX_BUFFER_SIZE 160
char rxBuf[RX_BUFFER_SIZE];

// Function Prototypes
void powerOnHardware();
bool sendAT(const char* cmd, const char* expected, unsigned long timeoutMs = 2000);
bool sendAT_P(PGM_P cmd_P, const char* expected, unsigned long timeoutMs = 2000);
void flushSerialRx();
bool acquireGNSSLocation(unsigned long timeoutMs = 60000UL);
bool transmitTelemetryHTTP();

// ==============================================================================
// SETUP ROUTINE
// ==============================================================================
void setup() {
  pinMode(PIN_SIM808_PWR, OUTPUT);

  // Initialize Hardware Serial at 9600 baud (TEL0097 factory default)
  Serial.begin(9600);

  // 1. Programmatic Power-on pulse on Pin D12 (guarantees module is turned ON)
  powerOnHardware();
  delay(3000);

  // 2. Wait for modem readiness
  int retry = 0;
  while (!sendAT_P(PSTR("AT"), "OK", 1000) && retry < 10) {
    delay(500);
    retry++;
  }

  // 3. Disable echo to avoid extra serial clutter
  sendAT_P(PSTR("ATE0"), "OK", 1000);

  // 4. Verify SIM card is ready
  sendAT_P(PSTR("AT+CPIN?"), "READY", 3000);

  // Trigger immediate tracking cycle upon startup
  lastCycleTime = millis() - TRACKING_INTERVAL_MS;
}

// ==============================================================================
// MAIN RUN LOOP - 3-MINUTE SEQUENTIAL CADENCE
// ==============================================================================
void loop() {
  unsigned long currentMillis = millis();

  // Run the sequential cycle every 3 minutes
  if (currentMillis - lastCycleTime >= TRACKING_INTERVAL_MS) {
    lastCycleTime = currentMillis;

    // STEP 1 & 2: Turn on GNSS, poll until fix acquired, then turn off GNSS
    bool hasFix = acquireGNSSLocation(60000UL); // Wait up to 60 seconds

    // STEP 3: If valid GPS fix obtained, connect GPRS and post to Microi
    if (hasFix) {
      transmitTelemetryHTTP();
    }

    // Module remains idle until next 3-minute interval
  }
}

// ==============================================================================
// HARDWARE POWER CONTROL (PIN D12)
// ==============================================================================
void powerOnHardware() {
  // A 1.2-second HIGH pulse on pin D12 toggles PWRKEY to power on SIM808
  digitalWrite(PIN_SIM808_PWR, LOW);
  delay(100);
  digitalWrite(PIN_SIM808_PWR, HIGH);
  delay(1200);
  digitalWrite(PIN_SIM808_PWR, LOW);
  delay(2000);
}

// ==============================================================================
// GNSS ACQUISITION (Turn On -> Query AT+CGNSINF -> Turn Off)
// ==============================================================================
bool acquireGNSSLocation(unsigned long timeoutMs) {
  // 1. Turn ON the GNSS engine
  if (!sendAT_P(PSTR("AT+CGNSPWR=1"), "OK", 2000)) {
    return false;
  }

  unsigned long start = millis();
  bool fixFound = false;

  // 2. Query AT+CGNSINF until fix status == 1
  while (millis() - start < timeoutMs) {
    flushSerialRx();
    Serial.println(F("AT+CGNSINF"));

    unsigned long readStart = millis();
    int idx = 0;
    memset(rxBuf, 0, sizeof(rxBuf));

    while (millis() - readStart < 1500) {
      while (Serial.available()) {
        char c = Serial.read();
        if (idx < RX_BUFFER_SIZE - 1) {
          rxBuf[idx++] = c;
        }
      }
    }
    rxBuf[idx] = '\0';

    // Parse response: +CGNSINF: <run>,<fix>,<UTC>,<lat>,<lon>,<alt>,<speed>,<heading>...
    char* tag = strstr(rxBuf, "+CGNSINF:");
    if (tag != NULL) {
      char* p = tag + 9;
      while (*p == ' ') p++;

      // skip run status
      p = strchr(p, ',');
      if (p) {
        p++;
        int fixStatus = atoi(p);

        if (fixStatus == 1) {
          // Valid fix acquired!
          p = strchr(p, ','); // skip fix
          if (p) {
            p++;
            // Parse UTC timestamp: YYYYMMDDhhmmss.sss
            char utcRaw[20];
            int uIdx = 0;
            while (*p != ',' && *p != '\0' && uIdx < 18) {
              utcRaw[uIdx++] = *p++;
            }
            utcRaw[uIdx] = '\0';
            if (*p == ',') p++;

            // Convert to ISO 8601 UTC string: YYYY-MM-DDTHH:MM:SSZ
            if (strlen(utcRaw) >= 14) {
              snprintf(currentData.isoTimestamp, sizeof(currentData.isoTimestamp),
                "%.4s-%.2s-%.2sT%.2s:%.2s:%.2sZ",
                utcRaw, utcRaw + 4, utcRaw + 6, utcRaw + 8, utcRaw + 10, utcRaw + 12);
            } else {
              strcpy_P(currentData.isoTimestamp, PSTR("2026-10-13T00:00:00Z"));
            }

            // Latitude
            currentData.latitude = atof(p);
            p = strchr(p, ',');
            if (p) p++;

            // Longitude
            if (p) {
              currentData.longitude = atof(p);
              p = strchr(p, ',');
              if (p) p++;
            }

            // Altitude
            if (p) {
              currentData.altitude = atof(p);
              p = strchr(p, ',');
              if (p) p++;
            }

            // Speed (km/h)
            if (p) {
              currentData.speedKph = atof(p);
              p = strchr(p, ',');
              if (p) p++;
            }

            // Course / Heading (deg)
            if (p) {
              currentData.heading = atof(p);
            }

            fixFound = true;
            break;
          }
        }
      }
    }

    delay(1000); // Poll once per second
  }

  // 3. Immediately turn OFF GNSS power to free UART stream & drop current consumption
  sendAT_P(PSTR("AT+CGNSPWR=0"), "OK", 1500);

  return fixFound;
}

// ==============================================================================
// GPRS INGESTION (AT+SAPBR -> AT+HTTP -> GET with Query Params -> Terminate)
// ==============================================================================
bool transmitTelemetryHTTP() {
  // 1. Configure GPRS connection bearer
  sendAT_P(PSTR("AT+SAPBR=3,1,\"CONTYPE\",\"GPRS\""), "OK", 2000);

  Serial.print(F("AT+SAPBR=3,1,\"APN\",\""));
  char apn[24];
  strcpy_P(apn, CELLULAR_APN);
  Serial.print(apn);
  Serial.println(F("\""));
  sendAT_P(PSTR(""), "OK", 2000);

  // 2. Open GPRS bearer
  sendAT_P(PSTR("AT+SAPBR=1,1"), "OK", 6000);

  // 3. Initialize HTTP client service
  sendAT_P(PSTR("AT+HTTPTERM"), "OK", 1000); // Reset any prior hung session
  if (!sendAT_P(PSTR("AT+HTTPINIT"), "OK", 2000)) {
    sendAT_P(PSTR("AT+SAPBR=0,1"), "OK", 2000);
    return false;
  }

  // 4. Set CID parameter
  sendAT_P(PSTR("AT+HTTPPARA=\"CID\",1"), "OK", 1500);

  // 5. Convert numeric metrics to strings
  char latStr[12];
  char lonStr[12];
  char spdStr[8];
  char hdgStr[8];
  char altStr[8];

  dtostrf(currentData.latitude, 4, 6, latStr);
  dtostrf(currentData.longitude, 4, 6, lonStr);
  dtostrf(currentData.speedKph, 3, 1, spdStr);
  dtostrf(currentData.heading, 3, 1, hdgStr);
  dtostrf(currentData.altitude, 3, 1, altStr);

  char devId[24];
  char devKey[40];
  char urlBase[64];
  strcpy_P(devId, DEVICE_ID);
  strcpy_P(devKey, DEVICE_KEY);
  strcpy_P(urlBase, SERVER_URL);

  // 6. Set Target Microi GET URL directly via streaming to avoid large RAM buffers:
  // AT+HTTPPARA="URL","http://.../telemetry?device_id=...&api_key=...&latitude=...&longitude=...&speed=...&heading=...&altitude=...&recorded_at=..."
  flushSerialRx();
  Serial.print(F("AT+HTTPPARA=\"URL\",\""));
  Serial.print(urlBase);
  Serial.print(F("?device_id="));
  Serial.print(devId);
  Serial.print(F("&api_key="));
  Serial.print(devKey);
  Serial.print(F("&latitude="));
  Serial.print(latStr);
  Serial.print(F("&longitude="));
  Serial.print(lonStr);
  Serial.print(F("&speed="));
  Serial.print(spdStr);
  Serial.print(F("&heading="));
  Serial.print(hdgStr);
  Serial.print(F("&altitude="));
  Serial.print(altStr);
  Serial.print(F("&recorded_at="));
  Serial.print(currentData.isoTimestamp);
  Serial.println(F("\""));

  if (!sendAT_P(PSTR(""), "OK", 2000)) {
    sendAT_P(PSTR("AT+HTTPTERM"), "OK", 1000);
    sendAT_P(PSTR("AT+SAPBR=0,1"), "OK", 2000);
    return false;
  }

  // 7. Execute HTTP GET (Method 0 = GET)
  flushSerialRx();
  Serial.println(F("AT+HTTPACTION=0"));

  // Wait up to 15s for +HTTPACTION: 0,<status_code>,<data_len>
  unsigned long start = millis();
  bool success = false;

  while (millis() - start < 15000) {
    if (Serial.available()) {
      String line = Serial.readStringUntil('\n');
      if (line.indexOf(F("+HTTPACTION: 0,")) >= 0) {
        int firstComma = line.indexOf(',');
        int secondComma = line.indexOf(',', firstComma + 1);
        if (firstComma > 0 && secondComma > firstComma) {
          int statusCode = line.substring(firstComma + 1, secondComma).toInt();
          if (statusCode >= 200 && statusCode < 300) {
            success = true;
          }
        }
        break;
      }
    }
  }

  // 8. Clean up HTTP session and close GPRS bearer
  sendAT_P(PSTR("AT+HTTPTERM"), "OK", 1000);
  sendAT_P(PSTR("AT+SAPBR=0,1"), "OK", 2000);

  return success;
}

// ==============================================================================
// RAW AT COMMAND HELPER UTILITIES
// ==============================================================================
bool sendAT(const char* cmd, const char* expected, unsigned long timeoutMs) {
  flushSerialRx();
  if (strlen(cmd) > 0) {
    Serial.println(cmd);
  }

  unsigned long start = millis();
  int idx = 0;
  memset(rxBuf, 0, sizeof(rxBuf));

  while (millis() - start < timeoutMs) {
    while (Serial.available()) {
      char c = Serial.read();
      if (idx < RX_BUFFER_SIZE - 1) {
        rxBuf[idx++] = c;
      }
      rxBuf[idx] = '\0';
      if (strstr(rxBuf, expected) != NULL) {
        return true;
      }
    }
  }

  return false;
}

bool sendAT_P(PGM_P cmd_P, const char* expected, unsigned long timeoutMs) {
  flushSerialRx();
  if (strlen_P(cmd_P) > 0) {
    char cmd[64];
    strncpy_P(cmd, cmd_P, sizeof(cmd));
    cmd[sizeof(cmd) - 1] = '\0';
    Serial.println(cmd);
  }

  unsigned long start = millis();
  int idx = 0;
  memset(rxBuf, 0, sizeof(rxBuf));

  while (millis() - start < timeoutMs) {
    while (Serial.available()) {
      char c = Serial.read();
      if (idx < RX_BUFFER_SIZE - 1) {
        rxBuf[idx++] = c;
      }
      rxBuf[idx] = '\0';
      if (strstr(rxBuf, expected) != NULL) {
        return true;
      }
    }
  }

  return false;
}

void flushSerialRx() {
  while (Serial.available() > 0) {
    Serial.read();
  }
}
