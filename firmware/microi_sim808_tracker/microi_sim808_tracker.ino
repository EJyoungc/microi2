/**
 * Microi IoT Tracker Firmware for Arduino Uno + DFRobot SIM808 Shield (TEL0097)
 * ==============================================================================
 * Target Hardware:
 *   - Arduino Uno R3 (ATmega328P, 5V, 16MHz)
 *   - DFRobot SIM808 GPS/GPRS/GSM Shield (SKU: TEL0097)
 *   - Docs: https://wiki.dfrobot.com/tel0097/
 *
 * Capabilities:
 *   1. Hardware UART / SoftwareSerial support for DFRobot TEL0097 shield.
 *   2. Integrated GPS power management & autonomous coordinate parsing (lat, lon, alt, speed, heading).
 *   3. GPRS connection & robust HTTP POST telemetry ingestion into Microi API endpoint:
 *        POST /api/v1/device/telemetry
 *        Payload: {"device_id": "...", "api_key": "...", "latitude": ..., "longitude": ..., ...}
 *   4. SMS bidirectional control:
 *        - Reply with location coordinates & Google Maps link on "LOCATION" / "GPS" text request.
 *        - Supports sending automated SMS alerts to configured emergency numbers.
 *   5. Voice Calling:
 *        - Auto-answers incoming calls (hands-free audio interface on SIM808 shield 3.5mm jack).
 *        - Outgoing voice call trigger on button press or emergency command.
 *
 * ==============================================================================
 * IMPORTANT HARDWARE SWITCH CONFIGURATION FOR TEL0097:
 *   - The TEL0097 shield has a 3-position slide switch ("Function Switch"):
 *       Position 'None'    : Disconnects RX/TX. (REQUIRED when uploading code via Arduino IDE!)
 *       Position 'Arduino' : Connects SIM808 to Arduino Hardware UART (Pins 0/RX & 1/TX).
 *       Position 'USB_DBG' : Connects SIM808 directly to USB debug port.
 *
 *   If using Hardware Serial (Default on TEL0097 stack):
 *     1. Set switch to 'None' -> Click Upload in Arduino IDE.
 *     2. After upload finishes -> Slide switch to 'Arduino'.
 *     3. Power the Uno via external 7-12V 2A DC supply (SIM808 GSM bursts need ~2A).
 *
 *   If using SoftwareSerial (e.g. Pin 10 RX, Pin 11 TX):
 *     - Set switch to 'None' and route jumper wires from module headers to D10/D11.
 * ==============================================================================
 */

//   Device ID: MEK-SIM808-01
// API Key: MEK_OMSPK7WWDYJCKLDSPZXHAW2R 

#include <Arduino.h>

// ==============================================================================
// 1. HARDWARE COMMUNICATION MODE CONFIGURATION
// ==============================================================================
// Set to 0 to use Hardware Serial (Pins 0/1 - native TEL0097 shield position 'Arduino')
// Set to 1 to use SoftwareSerial (e.g., if you jumpered pins to D10 and D11 for debugging)
#define USE_SOFTWARE_SERIAL 0

#if USE_SOFTWARE_SERIAL
  #include <SoftwareSerial.h>
  #define SS_RX_PIN 10
  #define SS_TX_PIN 11
  SoftwareSerial simSerial(SS_RX_PIN, SS_TX_PIN);
  #define MODEM_PORT simSerial
  #define DEBUG_PORT Serial
  #define HAS_DEBUG  1
#else
  #define MODEM_PORT Serial
  #define HAS_DEBUG  0
#endif

// Optional hardware trigger pin (e.g. SOS / Emergency Push Button wired between D2 and GND)
#define SOS_BUTTON_PIN 2

// ==============================================================================
// 2. USER CREDENTIALS & NETWORK SETTINGS
// ==============================================================================
// Microi Device Authentication (Match values created in Microi Admin > Devices)
const char DEVICE_ID[] PROGMEM   = "MEK-SIM808-01";
const char DEVICE_KEY[] PROGMEM  = "MEK_OMSPK7WWDYJCKLDSPZXHAW2R";

// Cellular APN (Access Point Name) - Change to match your SIM card provider:
// Examples:
//   - Malawi Airtel: "internet"
//   - Malawi TNM   : "internet"
//   - Vodacom SA   : "internet"
//   - MTN          : "internet"
//   - Safaricom    : "safaricom"
const char GPRS_APN[] PROGMEM    = "internet";
const char GPRS_USER[] PROGMEM   = "";
const char GPRS_PASS[] PROGMEM   = "";

// Target Microi Server Configuration
// Note: When testing locally via Laragon, use your local Wi-Fi / public IP or ngrok tunnel
// Production Microi endpoint:
const char SERVER_HOST[] PROGMEM = "micro.micromek.net";
const int  SERVER_PORT           = 80; // Use 80 for HTTP
const char SERVER_PATH[] PROGMEM = "/api/v1/device/telemetry";

// Emergency Alert Phone Number (E.164 format with country code)
const char EMERGENCY_PHONE[] PROGMEM = "0995936887";

// Interval between HTTP telemetry reports (in milliseconds)
const unsigned long TELEMETRY_INTERVAL_MS = 15000; // 15 seconds

// ==============================================================================
// 3. GLOBAL STATE & BUFFERS (Optimized for Uno 2KB SRAM)
// ==============================================================================
struct GPSData {
  bool  hasFix;
  float latitude;
  float longitude;
  float altitude;
  float speedKph;
  float heading;
  char  isoTimestamp[21]; // "YYYY-MM-DDTHH:MM:SSZ"
};

GPSData currentGPS = { false, 0.0, 0.0, 0.0, 0.0, 0.0, "" };

unsigned long lastTelemetryTime = 0;
unsigned long lastSmsCheckTime = 0;
unsigned long lastGpsQueryTime = 0;
bool gprsConnected = false;

// Shared AT response buffer
#define RX_BUFFER_SIZE 128
char rxBuffer[RX_BUFFER_SIZE];

// Forward Declarations
void modemPowerInit();
bool sendATCommand(const char* cmd, const char* expected, unsigned long timeoutMs = 2000);
bool sendATCommand_P(PGM_P cmd_P, const char* expected, unsigned long timeoutMs = 2000);
void flushModemRx();
bool queryGPS();
bool initGPRS();
bool sendTelemetryHTTP();
void checkIncomingSMS();
void sendLocationSMS(const char* recipientNumber);
void makeVoiceCall(const char* recipientNumber);
void handleIncomingCalls();

// ==============================================================================
// SETUP ROUTINE
// ==============================================================================
void setup() {
  pinMode(SOS_BUTTON_PIN, INPUT_PULLUP);

#if HAS_DEBUG
  DEBUG_PORT.begin(9600);
  DEBUG_PORT.println(F("[MICROI] Starting SIM808 GPS/GPRS/GSM Tracker..."));
#endif

  MODEM_PORT.begin(9600);
  delay(3000); // Allow SIM808 power-on stabilization

  modemPowerInit();

  // Configure SMS text mode
  sendATCommand_P(PSTR("AT+CMGF=1"), "OK", 1000);

  // Configure incoming caller ID presentation
  sendATCommand_P(PSTR("AT+CLIP=1"), "OK", 1000);

  // Auto-answer incoming calls after 2 rings (handy for two-way audio monitoring)
  sendATCommand_P(PSTR("ATS0=2"), "OK", 1000);

  // Turn on GPS receiver power (SIM808 TEL0097)
  // Both AT+CGNSPWR=1 and AT+CGPSPWR=1 are attempted for broad firmware compatibility
  sendATCommand_P(PSTR("AT+CGNSPWR=1"), "OK", 1500);
  sendATCommand_P(PSTR("AT+CGPSPWR=1"), "OK", 1500);

  // Initialize GPRS PDP bearer
  initGPRS();

#if HAS_DEBUG
  DEBUG_PORT.println(F("[MICROI] Setup complete. Ready for live tracking."));
#endif
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
#if HAS_DEBUG
      DEBUG_PORT.println(F("[SOS] Button triggered! Sending location SMS & placing call..."));
#endif
      char phoneBuf[20];
      strcpy_P(phoneBuf, EMERGENCY_PHONE);
      sendLocationSMS(phoneBuf);
      delay(2000);
      makeVoiceCall(phoneBuf);

      // Wait until button is released
      while (digitalRead(SOS_BUTTON_PIN) == LOW) {
        delay(100);
      }
    }
  }

  // 2. Poll GPS Coordinates every 4 seconds
  if (now - lastGpsQueryTime >= 4000) {
    lastGpsQueryTime = now;
    queryGPS();
  }

  // 3. Periodic HTTP Telemetry Upload to Microi API
  if (now - lastTelemetryTime >= TELEMETRY_INTERVAL_MS) {
    lastTelemetryTime = now;
    if (currentGPS.hasFix) {
      sendTelemetryHTTP();
    } else {
#if HAS_DEBUG
      DEBUG_PORT.println(F("[MICROI] Waiting for valid GPS fix before sending telemetry..."));
#endif
    }
  }

  // 4. Check for incoming SMS commands every 8 seconds
  if (now - lastSmsCheckTime >= 8000) {
    lastSmsCheckTime = now;
    checkIncomingSMS();
  }

  // 5. Check if modem emitted any asynchronous URC (e.g. RING)
  handleIncomingCalls();
}

// ==============================================================================
// MODEM INITIALIZATION & POWER MANAGEMENT
// ==============================================================================
void modemPowerInit() {
  // Test basic AT responsiveness
  int attempts = 0;
  while (!sendATCommand_P(PSTR("AT"), "OK", 1000) && attempts < 10) {
    delay(500);
    attempts++;
  }

  // Echo off to save serial processing
  sendATCommand_P(PSTR("ATE0"), "OK", 1000);

  // Inquire SIM card status
  sendATCommand_P(PSTR("AT+CPIN?"), "READY", 3000);
}

// ==============================================================================
// GPS PARSER (Supports both AT+CGNSINF and AT+CGPSINF)
// ==============================================================================
bool queryGPS() {
  flushModemRx();
  MODEM_PORT.println(F("AT+CGNSINF"));
  
  unsigned long start = millis();
  int idx = 0;
  memset(rxBuffer, 0, sizeof(rxBuffer));

  while (millis() - start < 2000) {
    while (MODEM_PORT.available()) {
      char c = MODEM_PORT.read();
      if (idx < RX_BUFFER_SIZE - 1) {
        rxBuffer[idx++] = c;
      }
    }
  }
  rxBuffer[idx] = '\0';

  // Check if +CGNSINF returned
  char* tag = strstr(rxBuffer, "+CGNSINF:");
  if (tag != NULL) {
    // Format: +CGNSINF: <GNSS_run_status>,<Fix_status>,<UTC_DateTime>,<Latitude>,<Longitude>,<Altitude>,<Speed>,<Course>...
    // Example: +CGNSINF: 1,1,20261012153022.000,-15.786111,35.005833,1045.2,12.5,180.0...
    char* p = tag + 9;
    while (*p == ' ') p++;

    int runStatus = atoi(p);
    p = strchr(p, ',');
    if (!p) return false;
    p++;

    int fixStatus = atoi(p);
    p = strchr(p, ',');
    if (!p) return false;
    p++;

    if (fixStatus == 1) {
      // Fix valid!
      currentGPS.hasFix = true;

      // Extract UTC: YYYYMMDDHHMMSS.sss
      char utcRaw[20];
      int uIdx = 0;
      while (*p != ',' && *p != '\0' && uIdx < 19) {
        utcRaw[uIdx++] = *p++;
      }
      utcRaw[uIdx] = '\0';
      if (*p == ',') p++;

      // Convert to ISO 8601: "YYYY-MM-DDTHH:MM:SSZ"
      if (strlen(utcRaw) >= 14) {
        snprintf(currentGPS.isoTimestamp, sizeof(currentGPS.isoTimestamp),
          "%.4s-%.2s-%.2sT%.2s:%.2s:%.2sZ",
          utcRaw, utcRaw + 4, utcRaw + 6, utcRaw + 8, utcRaw + 10, utcRaw + 12);
      } else {
        strcpy_P(currentGPS.isoTimestamp, PSTR("2026-10-12T00:00:00Z"));
      }

      // Latitude
      currentGPS.latitude = atof(p);
      p = strchr(p, ',');
      if (p) p++;

      // Longitude
      if (p) {
        currentGPS.longitude = atof(p);
        p = strchr(p, ',');
        if (p) p++;
      }

      // Altitude
      if (p) {
        currentGPS.altitude = atof(p);
        p = strchr(p, ',');
        if (p) p++;
      }

      // Speed in km/h
      if (p) {
        currentGPS.speedKph = atof(p);
        p = strchr(p, ',');
        if (p) p++;
      }

      // Course/Heading
      if (p) {
        currentGPS.heading = atof(p);
      }

#if HAS_DEBUG
      DEBUG_PORT.print(F("[GPS FIX] Lat: "));
      DEBUG_PORT.print(currentGPS.latitude, 6);
      DEBUG_PORT.print(F(", Lon: "));
      DEBUG_PORT.print(currentGPS.longitude, 6);
      DEBUG_PORT.print(F(", Speed: "));
      DEBUG_PORT.println(currentGPS.speedKph);
#endif
      return true;
    } else {
      currentGPS.hasFix = false;
      return false;
    }
  }

  // Fallback: If firmware uses legacy AT+CGPSINF
  flushModemRx();
  MODEM_PORT.println(F("AT+CGPSINF=0"));
  start = millis();
  idx = 0;
  memset(rxBuffer, 0, sizeof(rxBuffer));
  while (millis() - start < 2000) {
    while (MODEM_PORT.available()) {
      char c = MODEM_PORT.read();
      if (idx < RX_BUFFER_SIZE - 1) {
        rxBuffer[idx++] = c;
      }
    }
  }
  rxBuffer[idx] = '\0';

  tag = strstr(rxBuffer, "+CGPSINF:");
  if (tag != NULL) {
    // Mode 0: +CGPSINF: 0,lat,lon,alt,UTC,speed,heading...
    // Parse coordinates and flag fix
    char* p = tag + 9;
    while (*p == ' ') p++;
    p = strchr(p, ',');
    if (p) {
      p++;
      float lat = atof(p);
      p = strchr(p, ',');
      if (p) {
        p++;
        float lon = atof(p);
        if (lat != 0.0 && lon != 0.0) {
          currentGPS.hasFix = true;
          currentGPS.latitude = lat;
          currentGPS.longitude = lon;
          return true;
        }
      }
    }
  }

  return false;
}

// ==============================================================================
// GPRS INTERNET INITIALIZATION (SAPBR Context)
// ==============================================================================
bool initGPRS() {
  sendATCommand_P(PSTR("AT+SAPBR=3,1,\"CONTYPE\",\"GPRS\""), "OK", 2000);

  // Set APN
  MODEM_PORT.print(F("AT+SAPBR=3,1,\"APN\",\""));
  char apnBuf[32];
  strcpy_P(apnBuf, GPRS_APN);
  MODEM_PORT.print(apnBuf);
  MODEM_PORT.println(F("\""));
  sendATCommand_P(PSTR(""), "OK", 2000);

  // Open bearer
  sendATCommand_P(PSTR("AT+SAPBR=1,1"), "OK", 5000);

  // Check bearer status
  if (sendATCommand_P(PSTR("AT+SAPBR=2,1"), "+SAPBR: 1,1", 3000)) {
    gprsConnected = true;
#if HAS_DEBUG
    DEBUG_PORT.println(F("[GPRS] Connected to cellular data."));
#endif
    return true;
  }

  gprsConnected = false;
  return false;
}

// ==============================================================================
// HTTP TELEMETRY INGESTION (Sends JSON to Microi API)
// ==============================================================================
bool sendTelemetryHTTP() {
  if (!gprsConnected) {
    if (!initGPRS()) return false;
  }

  // 1. Initialize HTTP Service
  sendATCommand_P(PSTR("AT+HTTPTERM"), "OK", 1000); // Reset prior session if any
  if (!sendATCommand_P(PSTR("AT+HTTPINIT"), "OK", 2000)) {
    return false;
  }

  // 2. Set Bearer Profile CID
  sendATCommand_P(PSTR("AT+HTTPPARA=\"CID\",1"), "OK", 1500);

  // 3. Set Target Endpoint URL
  MODEM_PORT.print(F("AT+HTTPPARA=\"URL\",\"http://"));
  char hostBuf[40];
  strcpy_P(hostBuf, SERVER_HOST);
  MODEM_PORT.print(hostBuf);
  if (SERVER_PORT != 80) {
    MODEM_PORT.print(F(":"));
    MODEM_PORT.print(SERVER_PORT);
  }
  char pathBuf[40];
  strcpy_P(pathBuf, SERVER_PATH);
  MODEM_PORT.print(pathBuf);
  MODEM_PORT.println(F("\""));
  sendATCommand_P(PSTR(""), "OK", 2000);

  // 4. Set Content-Type: application/json
  sendATCommand_P(PSTR("AT+HTTPPARA=\"CONTENT\",\"application/json\""), "OK", 1500);

  // 5. Build Compact JSON Payload in memory
  // Structure required by Microi:
  // {"device_id":"...","api_key":"...","latitude":-15.78,"longitude":35.00,"speed":10.5,"heading":180,"altitude":1040,"recorded_at":"..."}
  char payload[220];
  char devId[24];
  char devKey[40];
  strcpy_P(devId, DEVICE_ID);
  strcpy_P(devKey, DEVICE_KEY);

  char latStr[12];
  char lonStr[12];
  char spdStr[8];
  char hdgStr[8];
  char altStr[8];

  dtostrf(currentGPS.latitude, 4, 6, latStr);
  dtostrf(currentGPS.longitude, 4, 6, lonStr);
  dtostrf(currentGPS.speedKph, 3, 1, spdStr);
  dtostrf(currentGPS.heading, 3, 1, hdgStr);
  dtostrf(currentGPS.altitude, 4, 1, altStr);

  snprintf(payload, sizeof(payload),
    "{\"device_id\":\"%s\",\"api_key\":\"%s\",\"latitude\":%s,\"longitude\":%s,\"speed\":%s,\"heading\":%s,\"altitude\":%s,\"recorded_at\":\"%s\"}",
    devId, devKey, latStr, lonStr, spdStr, hdgStr, altStr, currentGPS.isoTimestamp
  );

  int payloadLen = strlen(payload);

  // 6. Provide Data Length to Modem
  MODEM_PORT.print(F("AT+HTTPDATA="));
  MODEM_PORT.print(payloadLen);
  MODEM_PORT.println(F(",10000"));

  if (!sendATCommand_P(PSTR(""), "DOWNLOAD", 3000)) {
    sendATCommand_P(PSTR("AT+HTTPTERM"), "OK", 1000);
    return false;
  }

  // 7. Write the JSON payload bytes
  MODEM_PORT.print(payload);
  delay(200);

  // 8. Execute HTTP POST (Method 1)
  flushModemRx();
  MODEM_PORT.println(F("AT+HTTPACTION=1"));

  // Wait for +HTTPACTION: 1,<status_code>,<data_len>
  unsigned long start = millis();
  bool postSuccess = false;
  int statusCode = 0;

  while (millis() - start < 15000) {
    if (MODEM_PORT.available()) {
      String line = MODEM_PORT.readStringUntil('\n');
      if (line.indexOf(F("+HTTPACTION: 1,")) >= 0) {
        int firstComma = line.indexOf(',');
        int secondComma = line.indexOf(',', firstComma + 1);
        if (firstComma > 0 && secondComma > firstComma) {
          statusCode = line.substring(firstComma + 1, secondComma).toInt();
          if (statusCode >= 200 && statusCode < 300) {
            postSuccess = true;
          }
        }
        break;
      }
    }
  }

#if HAS_DEBUG
  DEBUG_PORT.print(F("[HTTP POST] Status code: "));
  DEBUG_PORT.println(statusCode);
#endif

  // Terminate HTTP session
  sendATCommand_P(PSTR("AT+HTTPTERM"), "OK", 1000);

  return postSuccess;
}

// ==============================================================================
// SMS COMMAND & INTERACTIVE LOCATION RESPONSE
// ==============================================================================
void checkIncomingSMS() {
  flushModemRx();
  MODEM_PORT.println(F("AT+CMGL=\"REC UNREAD\""));

  unsigned long start = millis();
  String buffer = "";
  while (millis() - start < 3000) {
    while (MODEM_PORT.available()) {
      buffer += (char)MODEM_PORT.read();
    }
  }

  if (buffer.indexOf(F("+CMGL:")) >= 0) {
    // Parse SMS index and phone number
    // Example: +CMGL: 1,"REC UNREAD","+265999123456",,"26/10/12,14:00:00+08"
    int idxPos = buffer.indexOf(F("+CMGL: "));
    if (idxPos >= 0) {
      int commaPos = buffer.indexOf(',', idxPos);
      int msgIndex = buffer.substring(idxPos + 7, commaPos).toInt();

      int numStart = buffer.indexOf('"', commaPos + 1);
      numStart = buffer.indexOf('"', numStart + 1); // skip status
      int numEnd = buffer.indexOf('"', numStart + 1);
      String senderNum = buffer.substring(numStart + 1, numEnd);

      // Check if SMS requests location
      String upperBuf = buffer;
      upperBuf.toUpperCase();

      if (upperBuf.indexOf(F("LOCATION")) >= 0 || upperBuf.indexOf(F("GPS")) >= 0 || upperBuf.indexOf(F("WHERE")) >= 0) {
#if HAS_DEBUG
        DEBUG_PORT.print(F("[SMS] Received LOCATION command from: "));
        DEBUG_PORT.println(senderNum);
#endif
        sendLocationSMS(senderNum.c_str());
      } else if (upperBuf.indexOf(F("CALL")) >= 0) {
#if HAS_DEBUG
        DEBUG_PORT.print(F("[SMS] Received CALL command from: "));
        DEBUG_PORT.println(senderNum);
#endif
        makeVoiceCall(senderNum.c_str());
      }

      // Delete processed SMS from SIM card memory
      MODEM_PORT.print(F("AT+CMGD="));
      MODEM_PORT.println(msgIndex);
      sendATCommand_P(PSTR(""), "OK", 2000);
    }
  }
}

// Send SMS with current GPS coordinates and Google Maps Link
void sendLocationSMS(const char* recipientNumber) {
  MODEM_PORT.print(F("AT+CMGS=\""));
  MODEM_PORT.print(recipientNumber);
  MODEM_PORT.println(F("\""));

  if (!sendATCommand_P(PSTR(""), ">", 3000)) {
    return;
  }

  char latStr[12];
  char lonStr[12];
  char spdStr[8];
  dtostrf(currentGPS.latitude, 4, 6, latStr);
  dtostrf(currentGPS.longitude, 4, 6, lonStr);
  dtostrf(currentGPS.speedKph, 3, 1, spdStr);

  MODEM_PORT.print(F("MICROI LIVE TRACKER ALERT\n"));
  MODEM_PORT.print(F("Device: "));
  char devId[24];
  strcpy_P(devId, DEVICE_ID);
  MODEM_PORT.println(devId);

  if (currentGPS.hasFix) {
    MODEM_PORT.print(F("Lat: ")); MODEM_PORT.println(latStr);
    MODEM_PORT.print(F("Lon: ")); MODEM_PORT.println(lonStr);
    MODEM_PORT.print(F("Speed: ")); MODEM_PORT.print(spdStr); MODEM_PORT.println(F(" km/h"));
    MODEM_PORT.print(F("Maps: https://maps.google.com/?q="));
    MODEM_PORT.print(latStr);
    MODEM_PORT.print(F(","));
    MODEM_PORT.println(lonStr);
  } else {
    MODEM_PORT.println(F("GPS searching for satellite fix..."));
  }

  // Ctrl+Z (ASCII 26) sends the SMS
  MODEM_PORT.write(26);
  sendATCommand_P(PSTR(""), "+CMGS:", 10000);

#if HAS_DEBUG
  DEBUG_PORT.println(F("[SMS] Location SMS sent successfully."));
#endif
}

// ==============================================================================
// VOICE CALLING FUNCTIONS
// ==============================================================================
void makeVoiceCall(const char* recipientNumber) {
#if HAS_DEBUG
  DEBUG_PORT.print(F("[CALL] Dialing: "));
  DEBUG_PORT.println(recipientNumber);
#endif

  // Voice dial command: ATD<number>;
  MODEM_PORT.print(F("ATD"));
  MODEM_PORT.print(recipientNumber);
  MODEM_PORT.println(F(";"));

  sendATCommand_P(PSTR(""), "OK", 5000);
}

void handleIncomingCalls() {
  while (MODEM_PORT.available()) {
    String line = MODEM_PORT.readStringUntil('\n');
    line.trim();

    if (line.indexOf(F("RING")) >= 0) {
#if HAS_DEBUG
      DEBUG_PORT.println(F("[CALL] Incoming call detected."));
#endif
      // Answer automatically if ATS0 didn't catch it
      // MODEM_PORT.println(F("ATA"));
    }
  }
}

// ==============================================================================
// UTILITY AT COMMAND HELPERS
// ==============================================================================
bool sendATCommand(const char* cmd, const char* expected, unsigned long timeoutMs) {
  flushModemRx();
  if (strlen(cmd) > 0) {
    MODEM_PORT.println(cmd);
  }

  unsigned long start = millis();
  int idx = 0;
  memset(rxBuffer, 0, sizeof(rxBuffer));

  while (millis() - start < timeoutMs) {
    while (MODEM_PORT.available()) {
      char c = MODEM_PORT.read();
      if (idx < RX_BUFFER_SIZE - 1) {
        rxBuffer[idx++] = c;
      }
      rxBuffer[idx] = '\0';
      if (strstr(rxBuffer, expected) != NULL) {
        return true;
      }
    }
  }

  return false;
}

bool sendATCommand_P(PGM_P cmd_P, const char* expected, unsigned long timeoutMs) {
  flushModemRx();
  if (strlen_P(cmd_P) > 0) {
    char cmdBuf[64];
    strncpy_P(cmdBuf, cmd_P, sizeof(cmdBuf));
    cmdBuf[sizeof(cmdBuf) - 1] = '\0';
    MODEM_PORT.println(cmdBuf);
  }

  unsigned long start = millis();
  int idx = 0;
  memset(rxBuffer, 0, sizeof(rxBuffer));

  while (millis() - start < timeoutMs) {
    while (MODEM_PORT.available()) {
      char c = MODEM_PORT.read();
      if (idx < RX_BUFFER_SIZE - 1) {
        rxBuffer[idx++] = c;
      }
      rxBuffer[idx] = '\0';
      if (strstr(rxBuffer, expected) != NULL) {
        return true;
      }
    }
  }

  return false;
}

void flushModemRx() {
  while (MODEM_PORT.available() > 0) {
    MODEM_PORT.read();
  }
}
