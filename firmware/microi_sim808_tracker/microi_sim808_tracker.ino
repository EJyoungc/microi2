/**
 * Microi IoT Live Tracker Firmware for Arduino Uno + DFRobot SIM808 Shield (TEL0097)
 * ===================================================================================
 * Built using the official DFRobot_sim808 library:
 *   #include <DFRobot_sim808.h>
 *   DFRobot_SIM808 sim808(&Serial);
 *
 * Hardware Pin Mapping (TEL0097):
 *   - D0 : RX (SIM808 TX)
 *   - D1 : TX (SIM808 RX)
 *   - D12: Power GPIO / PWRKEY (Pulse HIGH/LOW to power ON / OFF module programmatically)
 *   - D2 : Optional Hardware SOS Button (to GND)
 *
 * TEL0097 Function Switch:
 *   [ None ]   : Disconnects serial pins -> MUST BE ON 'None' WHEN DOWNLOADING SKETCH!
 *   [ Arduino ]: Connects SIM808 to Arduino (D0/D1) -> MOVE TO 'Arduino' TO RUN!
 *   [ USB_DBG ]: Connects SIM808 to PC USB -> Use for direct AT command debugging!
 *
 * Power LEDs:
 *   - ON   : Power indicator (External 7-12V 2A DC supply required!)
 *   - Net  : GSM signal (Fast flash = Searching | Slow flash 3s = Registered)
 * ===================================================================================
 */

#include <Arduino.h>
#include <DFRobot_SIM808.h>

// TEL0097 Occupied Pins
#define SIM808_PWR_PIN 12    // Digital pin connected to SIM808 power GPIO
#define SOS_BUTTON_PIN 2     // Optional emergency button (D2 to GND)

// Instantiate the official DFRobot_SIM808 driver bound to Hardware Serial
DFRobot_SIM808 sim808(&Serial);

// ==============================================================================
// CONFIGURATION & CREDENTIALS
// ==============================================================================
// Microi Provisioned Device Credentials
const char DEVICE_ID[] PROGMEM   = "MEK-SIM808-01";
const char DEVICE_KEY[] PROGMEM  = "MEK_9GHVCK5ROYFIWGCR7ZTQB5AA";

// Cellular APN (Access Point Name)
#define CELLULAR_APN "internet"

// Microi Server Configuration
const char SERVER_HOST[] PROGMEM = "microi.micromek.net";
const int  SERVER_PORT           = 80;
const char SERVER_PATH[] PROGMEM = "/api/v1/device/telemetry";

// Emergency Alert Phone Number (for SOS calls & text alerts)
const char EMERGENCY_PHONE[] PROGMEM = "0995936887";

// Interval between HTTP telemetry reports (in milliseconds)
const unsigned long TELEMETRY_INTERVAL_MS = 15000; // 15 seconds

// ==============================================================================
// GLOBAL STATE & BUFFER
// ==============================================================================
bool isGprsJoined = false;
unsigned long lastTelemetryTime = 0;
unsigned long lastSmsCheckTime = 0;

// Reusable network response buffer (keeps SRAM usage safe on Uno)
char networkBuffer[384];

// Forward Declarations
void powerOnSIM808();
bool setupGPRS();
bool sendTelemetryHTTP();
void checkIncomingSMS();
void sendLocationSMS(const char* targetPhone);
void triggerSOSAlert();

// ==============================================================================
// SETUP ROUTINE
// ==============================================================================
void setup() {
  pinMode(SOS_BUTTON_PIN, INPUT_PULLUP);
  pinMode(SIM808_PWR_PIN, OUTPUT);

  // Initialize hardware serial for SIM808 module (standard 9600 baud)
  Serial.begin(9600);

  // 1. Programmatic Power-on pulse using Pin D12 (in case module is off)
  powerOnSIM808();
  delay(3000);

  // 2. Initialize SIM808 module
  while (!sim808.init()) {
    delay(1000);
  }

  // 3. Turn on GPS receiver power
  sim808.attachGPS();

  // 4. Join GPRS cellular network
  setupGPRS();
}

// ==============================================================================
// MAIN RUN LOOP
// ==============================================================================
void loop() {
  unsigned long now = millis();

  // 1. Check SOS emergency button (Active LOW)
  if (digitalRead(SOS_BUTTON_PIN) == LOW) {
    delay(50); // Debounce
    if (digitalRead(SOS_BUTTON_PIN) == LOW) {
      triggerSOSAlert();
      while (digitalRead(SOS_BUTTON_PIN) == LOW) {
        delay(100);
      }
    }
  }

  // 2. Poll GPS Coordinates and send HTTP Telemetry
  if (now - lastTelemetryTime >= TELEMETRY_INTERVAL_MS) {
    lastTelemetryTime = now;

    if (sim808.getGPS()) {
      sendTelemetryHTTP();
    }
  }

  // 3. Check for incoming SMS commands every 10 seconds
  if (now - lastSmsCheckTime >= 10000) {
    lastSmsCheckTime = now;
    checkIncomingSMS();
  }
}

// ==============================================================================
// HARDWARE POWER CONTROL (PIN D12)
// ==============================================================================
void powerOnSIM808() {
  // Pin D12 short pulse (1.2 seconds) turns on the SIM808 module
  digitalWrite(SIM808_PWR_PIN, LOW);
  delay(100);
  digitalWrite(SIM808_PWR_PIN, HIGH);
  delay(1200);
  digitalWrite(SIM808_PWR_PIN, LOW);
  delay(2000);
}

// ==============================================================================
// GPRS NETWORK SETUP
// ==============================================================================
bool setupGPRS() {
  if (isGprsJoined) return true;

  if (sim808.join(F(CELLULAR_APN))) {
    isGprsJoined = true;
    return true;
  }

  isGprsJoined = false;
  return false;
}

// ==============================================================================
// HTTP TELEMETRY INGESTION (Using sim808.connect / send / recv)
// ==============================================================================
bool sendTelemetryHTTP() {
  if (!isGprsJoined) {
    if (!setupGPRS()) return false;
  }

  char hostBuf[32];
  strcpy_P(hostBuf, SERVER_HOST);

  // 1. Connect to Microi server via TCP Socket on port 80
  if (!sim808.connect(TCP, hostBuf, SERVER_PORT)) {
    isGprsJoined = false;
    sim808.close();
    return false;
  }

  // 2. Format Coordinates and Timestamp
  char latStr[12];
  char lonStr[12];
  char spdStr[8];
  char hdgStr[8];

  dtostrf(sim808.GPSdata.lat, 4, 6, latStr);
  dtostrf(sim808.GPSdata.lon, 4, 6, lonStr);
  dtostrf(sim808.GPSdata.speed_kph, 3, 1, spdStr);
  dtostrf(sim808.GPSdata.heading, 3, 1, hdgStr);

  char timeIso[22];
  snprintf(timeIso, sizeof(timeIso), "%04d-%02d-%02dT%02d:%02d:%02dZ",
    sim808.GPSdata.year,
    sim808.GPSdata.month,
    sim808.GPSdata.day,
    sim808.GPSdata.hour,
    sim808.GPSdata.minute,
    sim808.GPSdata.second
  );

  char devId[24];
  char devKey[40];
  char pathBuf[32];
  strcpy_P(devId, DEVICE_ID);
  strcpy_P(devKey, DEVICE_KEY);
  strcpy_P(pathBuf, SERVER_PATH);

  // 3. Construct JSON Payload
  // {"device_id":"...","api_key":"...","latitude":-15.78,"longitude":35.00,"speed":10.5,"heading":180,"altitude":1040,"recorded_at":"..."}
  char jsonPayload[200];
  snprintf(jsonPayload, sizeof(jsonPayload),
    "{\"device_id\":\"%s\",\"api_key\":\"%s\",\"latitude\":%s,\"longitude\":%s,\"speed\":%s,\"heading\":%s,\"altitude\":%.1f,\"recorded_at\":\"%s\"}",
    devId, devKey, latStr, lonStr, spdStr, hdgStr, sim808.GPSdata.altitude, timeIso
  );

  int bodyLen = strlen(jsonPayload);

  // 4. Construct HTTP/1.1 POST Request Header and Body
  char httpReq[380];
  snprintf(httpReq, sizeof(httpReq),
    "POST %s HTTP/1.1\r\nHost: %s\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
    pathBuf, hostBuf, bodyLen, jsonPayload
  );

  // 5. Send payload over TCP socket
  sim808.send(httpReq, strlen(httpReq));

  // 6. Read server response
  int ret = sim808.recv(networkBuffer, sizeof(networkBuffer) - 1);
  if (ret > 0) {
    networkBuffer[ret] = '\0';
  }

  // 7. Close TCP socket to finish HTTP transaction
  sim808.close();
  return true;
}

// ==============================================================================
// SMS HANDLER & INTERACTIVE LOCATION REPLY
// ==============================================================================
void checkIncomingSMS() {
  char msgIndex = sim808.isSMSunread();

  if (msgIndex > 0) {
    char smsContent[100];
    char senderPhone[20];
    char smsDate[24];

    sim808.readSMS(msgIndex, smsContent, sizeof(smsContent), senderPhone, smsDate);

    for (int i = 0; smsContent[i]; i++) {
      smsContent[i] = toupper(smsContent[i]);
    }

    if (strstr(smsContent, "LOCATION") != NULL || strstr(smsContent, "GPS") != NULL || strstr(smsContent, "WHERE") != NULL) {
      sendLocationSMS(senderPhone);
    } else if (strstr(smsContent, "CALL") != NULL) {
      sim808.callUp(senderPhone);
      delay(15000); // Ring for 15 seconds
      sim808.hangup();
    }

    sim808.deleteSMS(msgIndex);
  }
}

// Send SMS with current GPS coordinates and Google Maps link
void sendLocationSMS(const char* targetPhone) {
  char latStr[12];
  char lonStr[12];
  char spdStr[8];
  char devId[24];

  strcpy_P(devId, DEVICE_ID);
  dtostrf(sim808.GPSdata.lat, 4, 6, latStr);
  dtostrf(sim808.GPSdata.lon, 4, 6, lonStr);
  dtostrf(sim808.GPSdata.speed_kph, 3, 1, spdStr);

  char replyMsg[140];
  snprintf(replyMsg, sizeof(replyMsg),
    "Microi Alert [%s]\nLat:%s\nLon:%s\nSpeed:%skm/h\nhttps://maps.google.com/?q=%s,%s",
    devId, latStr, lonStr, spdStr, latStr, lonStr
  );

  sim808.sendSMS((char*)targetPhone, replyMsg);
}

// Trigger emergency voice call and SMS on hardware button press
void triggerSOSAlert() {
  char phoneBuf[20];
  strcpy_P(phoneBuf, EMERGENCY_PHONE);

  sendLocationSMS(phoneBuf);
  delay(1000);

  sim808.callUp(phoneBuf);
  delay(20000); // Ring for 20 seconds
  sim808.hangup();
}
