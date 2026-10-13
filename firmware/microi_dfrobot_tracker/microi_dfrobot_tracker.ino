/**
 * Microi IoT Sequential Tracker - Direct Stacked Shield Implementation
 * =========================================================================
 * Board: Arduino Uno R3 (ATmega328P)
 * Shield: DFRobot SIM808 GPS/GPRS/GSM Shield (SKU: TEL0097)
 * Library: DFRobot_sim808 (https://github.com/DFRobot/DFRobot_SIM808)
 *
 * NO JUMPER WIRES REQUIRED:
 * -------------------------------------------------------------------------
 * When the TEL0097 shield is plugged directly into the Arduino Uno headers:
 *   - The SIM808 communicates through Arduino Hardware Serial (Pins D0/RX & D1/TX).
 *   - Pin D12 is connected to the SIM808 Power GPIO (PWRKEY).
 *   - The sketch uses the standard driver: DFRobot_SIM808 sim808(&Serial);
 *
 * TEL0097 Function Switch (Slide Switch on the Shield):
 * -------------------------------------------------------------------------
 *   Step 1: Set switch to [ None ]
 *           -> Click "Upload" in the Arduino IDE.
 *           (Position 'None' disconnects D0/D1 so the USB upload succeeds).
 *
 *   Step 2: After upload says "Done uploading", slide switch to [ Arduino ]
 *           (Position 'Arduino' connects D0/D1 to the SIM808 so the sketch runs).
 *
 *   Step 3: Connect external 7V-12V (2A) power adapter to the Arduino barrel jack.
 *
 * Operational Strategy (Sequential 3-Minute Cycle):
 * -------------------------------------------------------------------------
 * The SIM808 shares a single internal UART and high peak-current draw.
 * Running GPS and GPRS simultaneously causes buffer corruption and resets.
 * This sketch runs a clean sequential cycle every 3 minutes:
 *   1. Turn ON GPS receiver (`sim808.attachGPS()`).
 *   2. Wait until valid satellite fix is acquired (`sim808.getGPS()`).
 *   3. Turn OFF GPS receiver immediately (`sim808.detachGPS()`) to free serial & save power.
 *   4. Connect GPRS data (`sim808.join(F("internet"))`).
 *   5. Connect TCP socket (`sim808.connect(TCP, host, 80)`).
 *   6. Send HTTP/1.1 POST JSON payload to Microi API endpoint.
 *   7. Close TCP and disconnect GPRS (`sim808.close()`, `sim808.disconnect()`).
 *   8. Wait 3 minutes (180,000 ms) before repeating the cycle.
 *
 * SMS and voice calls are completely disabled/commented out.
 */

#include <Arduino.h>
#include <DFRobot_SIM808.h>

// TEL0097 Hardwired Pin
#define PIN_SIM808_PWR  12   // Onboard hardwired power GPIO (PWRKEY) for SIM808

// Instantiate official DFRobot SIM808 driver on standard Hardware Serial (&Serial)
DFRobot_SIM808 sim808(&Serial);

// ==============================================================================
// DEVICE CREDENTIALS & SERVER CONFIGURATION
// ==============================================================================
// Microi Device Credentials (Created in Microi Dashboard)
const char DEVICE_ID[] PROGMEM   = "MEK-SIM808-01";
const char DEVICE_KEY[] PROGMEM  = "MEK_9GHVCK5ROYFIWGCR7ZTQB5AA";

// Cellular APN (Malawi Airtel / TNM: "internet")
#define CELLULAR_APN "internet"

// Microi Ingestion API Endpoint
const char SERVER_HOST[] PROGMEM = "microi.micromek.net";
const int  SERVER_PORT           = 80;
const char SERVER_PATH[] PROGMEM = "/api/v1/device/telemetry";

// Cycle Interval: Every 3 minutes (180,000 milliseconds)
const unsigned long TRACKING_INTERVAL_MS = 180000UL;

// ==============================================================================
// RUNTIME STATE & BUFFER
// ==============================================================================
unsigned long lastCycleTime = 0;

// Preserved GPS reading for current cycle
struct TelemetrySnapshot {
  float latitude;
  float longitude;
  float speedKph;
  float heading;
  float altitude;
  char  isoTimestamp[22];
};

TelemetrySnapshot currentData;

// Reusable network response buffer (keeps SRAM usage safe on ATmega328P)
char httpResponseBuffer[256];

// Function Prototypes
void powerOnModule();
bool acquireGPSLocation(unsigned long timeoutMs = 60000UL);
bool transmitTelemetryHttp();

// ==============================================================================
// SETUP ROUTINE
// ==============================================================================
void setup() {
  pinMode(PIN_SIM808_PWR, OUTPUT);

  // Initialize Hardware Serial at 9600 baud (Pins D0 & D1 to SIM808)
  Serial.begin(9600);

  // 1. Programmatic Power-on pulse using Pin D12 (guarantees module boots up)
  powerOnModule();
  delay(3000);

  // 2. Initialize SIM808 driver
  while (!sim808.init()) {
    delay(1000);
  }

  // Trigger immediate first tracking cycle upon boot
  lastCycleTime = millis() - TRACKING_INTERVAL_MS;
}

// ==============================================================================
// MAIN RUN LOOP - 3-MINUTE SEQUENTIAL CADENCE
// ==============================================================================
void loop() {
  unsigned long currentMillis = millis();

  // Run the sequential GPS -> Turn Off -> GPRS HTTP -> Sleep cycle every 3 minutes
  if (currentMillis - lastCycleTime >= TRACKING_INTERVAL_MS) {
    lastCycleTime = currentMillis;

    // STEP 1 & 2: Turn on GPS, poll until fix acquired, then turn off GPS
    bool hasFix = acquireGPSLocation(60000UL); // Wait up to 60s for satellite fix

    // STEP 3: If valid GPS fix obtained, connect GPRS and send to Microi
    if (hasFix) {
      transmitTelemetryHttp();
    }

    // Module remains idle until next 3-minute interval
  }
}

// ==============================================================================
// HARDWARE POWER CONTROL (PIN D12)
// ==============================================================================
void powerOnModule() {
  // A 1.2-second HIGH pulse on pin D12 toggles PWRKEY to turn the module ON
  digitalWrite(PIN_SIM808_PWR, LOW);
  delay(100);
  digitalWrite(PIN_SIM808_PWR, HIGH);
  delay(1200);
  digitalWrite(PIN_SIM808_PWR, LOW);
  delay(2000);
}

// ==============================================================================
// GPS ACQUISITION (Turn On -> Read -> Turn Off)
// ==============================================================================
bool acquireGPSLocation(unsigned long timeoutMs) {
  // 1. Turn ON the GPS receiver hardware
  if (!sim808.attachGPS()) {
    return false;
  }

  unsigned long start = millis();
  bool fixFound = false;

  // 2. Poll until a valid satellite fix is retrieved or timeout expires
  while (millis() - start < timeoutMs) {
    if (sim808.getGPS()) {
      // Validate that coordinates are non-zero
      if (sim808.GPSdata.lat != 0.0 && sim808.GPSdata.lon != 0.0) {
        currentData.latitude  = sim808.GPSdata.lat;
        currentData.longitude = sim808.GPSdata.lon;
        currentData.speedKph  = sim808.GPSdata.speed_kph;
        currentData.heading   = sim808.GPSdata.heading;
        currentData.altitude  = sim808.GPSdata.altitude;

        // Build ISO 8601 UTC timestamp: YYYY-MM-DDTHH:MM:SSZ
        snprintf(currentData.isoTimestamp, sizeof(currentData.isoTimestamp),
          "%04d-%02d-%02dT%02d:%02d:%02dZ",
          sim808.GPSdata.year,
          sim808.GPSdata.month,
          sim808.GPSdata.day,
          sim808.GPSdata.hour,
          sim808.GPSdata.minute,
          sim808.GPSdata.second
        );

        fixFound = true;
        break;
      }
    }
    delay(1000); // Wait 1s between GPS poll attempts
  }

  // 3. Immediately turn OFF GPS power to clear the serial stream and save battery
  sim808.detachGPS();
  delay(500);

  return fixFound;
}

// ==============================================================================
// GPRS TRANSMISSION (Connect -> Send POST -> Disconnect)
// ==============================================================================
bool transmitTelemetryHttp() {
  // 1. Join GPRS cellular network with APN
  if (!sim808.join(F(CELLULAR_APN))) {
    sim808.disconnect();
    return false;
  }

  char host[32];
  strcpy_P(host, SERVER_HOST);

  // 2. Connect to Microi server via TCP port 80
  if (!sim808.connect(TCP, host, SERVER_PORT)) {
    sim808.close();
    sim808.disconnect();
    return false;
  }

  // 3. Format coordinates and build JSON Payload
  char latStr[12];
  char lonStr[12];
  char speedStr[8];
  char headingStr[8];

  dtostrf(currentData.latitude, 4, 6, latStr);
  dtostrf(currentData.longitude, 4, 6, lonStr);
  dtostrf(currentData.speedKph, 3, 1, speedStr);
  dtostrf(currentData.heading, 3, 1, headingStr);

  char devId[24];
  char devKey[40];
  char path[32];
  strcpy_P(devId, DEVICE_ID);
  strcpy_P(devKey, DEVICE_KEY);
  strcpy_P(path, SERVER_PATH);

  // Payload: {"device_id":"...","api_key":"...","latitude":-15.78,"longitude":35.00,"speed":10.5,"heading":180,"altitude":1040,"recorded_at":"..."}
  char jsonBody[200];
  snprintf(jsonBody, sizeof(jsonBody),
    "{\"device_id\":\"%s\",\"api_key\":\"%s\",\"latitude\":%s,\"longitude\":%s,\"speed\":%s,\"heading\":%s,\"altitude\":%.1f,\"recorded_at\":\"%s\"}",
    devId, devKey, latStr, lonStr, speedStr, headingStr, currentData.altitude, currentData.isoTimestamp
  );

  int bodyLength = strlen(jsonBody);

  // 4. Construct HTTP/1.1 POST Request
  char httpRequest[380];
  snprintf(httpRequest, sizeof(httpRequest),
    "POST %s HTTP/1.1\r\nHost: %s\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
    path, host, bodyLength, jsonBody
  );

  // 5. Send HTTP request over TCP socket
  sim808.send(httpRequest, strlen(httpRequest));

  // 6. Read server response
  int bytesRead = sim808.recv(httpResponseBuffer, sizeof(httpResponseBuffer) - 1);
  if (bytesRead > 0) {
    httpResponseBuffer[bytesRead] = '\0';
  }

  // 7. Clean up TCP connection and disconnect GPRS
  sim808.close();
  sim808.disconnect();

  return true;
}
