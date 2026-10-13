# Arduino Uno + DFRobot SIM808 Shield (TEL0097) Integration Guide

This guide describes how to connect, configure, and operate the **Arduino Uno** with the **DFRobot SIM808 GPS/GPRS/GSM Shield (SKU: TEL0097)** to transmit real-time telemetry into the **Microi Platform** (`micro.micromek.net`), send location SMS alerts, and execute voice calls.

Official Hardware Documentation: [https://wiki.dfrobot.com/tel0097/](https://wiki.dfrobot.com/tel0097/)

---

## 1. Hardware Overview & Requirements

| Component | Specification | Notes |
| :--- | :--- | :--- |
| **Microcontroller** | Arduino Uno R3 (ATmega328P) | 5V logic, 32KB Flash, 2KB SRAM |
| **Cellular Shield** | DFRobot SIM808 Shield (SKU: TEL0097) | Quad-band GSM/GPRS (850/900/1800/1900 MHz) + GNSS/GPS |
| **Power Supply** | 7V – 12V DC Adapter (2A peak) | **Critical:** Peak GSM bursts draw up to 2A. Do not rely solely on computer USB power! |
| **SIM Card** | Standard 2G/3G/4G Mini-SIM (with 2G fallback) | Unlocked, active data & SMS balance, PIN removed |
| **Antennas** | 1x Quad-band GSM Antenna + 1x Active GPS Antenna | Connect GPS antenna to the GPS port and place near window or outdoors |

---

## 2. Onboard Function Switch (Crucial Step!)

The TEL0097 shield has a 3-position physical slide switch labeled **Function Switch**:

```
+------------------------------------------+
|  [ None ]   [ Arduino ]   [ USB_DBG ]    |  <-- Function Switch
+------------------------------------------+
```

1. **`None` (Upload / Programming Mode):**
   * Disconnects the SIM808 serial lines from the Arduino.
   * **You MUST set the switch to `None` before clicking Upload in the Arduino IDE.** If you leave it on `Arduino`, the upload will fail with `avrdude: stk500_getsync()` error.
2. **`Arduino` (Operation Mode):**
   * Connects the SIM808 module's TX/RX directly to Arduino Hardware Serial (`D0` / `D1`).
   * **After the sketch uploads successfully**, flip the switch to **`Arduino`** and press the Arduino Reset button.
3. **`USB_DBG` (Direct PC AT Debug Mode):**
   * Connects the SIM808 directly to the onboard USB port for manual AT command debugging with a serial monitor.

---

## 3. Microi API Integration Contract

The firmware utilizes the official **`DFRobot_sim808`** library to:
1. Power up GPS: `sim808.attachGPS()` and parse with `sim808.getGPS()`.
2. Connect GPRS & TCP: `sim808.join(F("internet"))` and `sim808.connect(TCP, "microi.micromek.net", 80)`.
3. Dispatch HTTP/1.1 POST telemetry requests:
* **Endpoint:** `http://microi.micromek.net/api/v1/device/telemetry`
* **Method:** `POST`
* **Header:** `Content-Type: application/json`
* **Payload Structure:**
```json
{
  "device_id": "MEK-SIM808-01",
  "api_key": "MEK_9GHVCK5ROYFIWGCR7ZTQB5AA",
  "latitude": -15.786111,
  "longitude": 35.005833,
  "altitude": 1045.2,
  "speed": 12.5,
  "heading": 180.0,
  "recorded_at": "2026-10-13T12:30:22Z"
}
```

### Response:
```json
{
  "status": "success",
  "message": "Telemetry ingested successfully.",
  "assigned_vehicle_id": 1,
  "recorded_at": "2026-10-12T15:30:22+00:00"
}
```

---

## 4. Firmware Configuration

Open `firmware/microi_sim808_tracker/microi_sim808_tracker.ino` and edit the configuration variables:

```cpp
// 1. Device credentials (Provisioned in Microi Web Dashboard -> Admin -> Devices)
const char DEVICE_ID[] PROGMEM   = "MEK-SIM808-01";
const char DEVICE_KEY[] PROGMEM  = "YOUR_DEVICE_SECRET_KEY";

// 2. Cellular APN for your telecom provider
// Airtel Malawi: "internet" | TNM Malawi: "internet" | Vodacom: "internet"
const char GPRS_APN[] PROGMEM    = "internet";

// 3. Platform Server Host (Use public IP or domain)
const char SERVER_HOST[] PROGMEM = "micro.micromek.net";
const int  SERVER_PORT           = 80;
const char SERVER_PATH[] PROGMEM = "/api/v1/device/telemetry";

// 4. Emergency phone number for SOS alerts and incoming triggers
const char EMERGENCY_PHONE[] PROGMEM = "+265999000000";
```

---

## 5. Step-by-Step Flashing & Setup

1. **Insert SIM Card & Antennas:**
   * Insert an active Mini-SIM into the SIM socket underneath the shield.
   * Screw in the GSM antenna and attach the active GPS patch antenna.
2. **Mount Shield:**
   * Mount the TEL0097 shield onto the Arduino Uno headers carefully.
3. **Set Switch to `None`:**
   * Slide the Function Switch to **`None`**.
4. **Plug in USB & Open Arduino IDE:**
   * Select Board: **Arduino Uno**.
   * Select Port: Your Arduino COM port.
   * Open `firmware/microi_sim808_tracker/microi_sim808_tracker.ino`.
   * Click **Upload**.
5. **Switch to `Arduino`:**
   * Once upload displays *Done uploading*, slide the switch to **`Arduino`**.
6. **Connect External Power:**
   * Plug in a 9V or 12V 2A DC adapter to the Arduino barrel jack.
   * Press the shield power key (or let pin D12 trigger power on) until the `NET` LED starts blinking.
   * `NET` LED blinking slowly (~every 3 seconds) indicates successful cellular network registration.

---

## 6. Features & Testing

### 1. Real-time GPS Telemetry
* Once the GPS antenna acquires satellite signals, the sketch automatically transmits live latitude, longitude, speed, and heading to `micro.micromek.net` every 15 seconds.
* View live tracking moving in real time on the Microi web dashboard:
  * Open `/tracking` to watch the marker update and auto-follow.
  * Open `/history` to review the travel trail and playback scrubber.

### 2. Location Request via SMS
* Send an SMS with the word **`LOCATION`**, **`GPS`**, or **`WHERE`** from any phone to the SIM card number.
* The Arduino SIM808 shield will reply with an SMS containing:
  ```text
  MICROI LIVE TRACKER ALERT
  Device: MEK-SIM808-01
  Lat: -15.786111
  Lon: 35.005833
  Speed: 14.2 km/h
  Maps: https://maps.google.com/?q=-15.786111,35.005833
  ```

### 3. Voice Calling & Auto-Answer
* **Incoming Call:** The shield is configured with `ATS0=2` to automatically answer incoming calls after 2 rings, allowing remote two-way audio monitoring using the onboard 3.5mm microphone/earphone jacks.
* **Outgoing Call Trigger:**
  * Send an SMS with the word **`CALL`** to trigger the device to call your phone number.
  * Connect an emergency pushbutton between **Pin D2** and **GND**. Pressing the button will immediately send an emergency SMS with coordinates to `EMERGENCY_PHONE` and dial the phone number.
