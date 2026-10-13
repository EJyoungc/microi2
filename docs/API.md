# Microi GPS Device Telemetry REST API

## 1. Specification & Protocol Overview
- **Base URL:** `https://micro.micromek.net/api/v1/device`
- **Authentication:** Header or Request Body API key matched against cryptographic bcrypt/Argon2 hashes stored in the Microi database.
- **Data Format:** JSON `application/json`

---

## 2. Telemetry Ingestion Endpoint

### `POST /api/v1/device/telemetry`

Transmits location, speed, altitude, heading, and battery health data.

#### Request Headers
```http
Content-Type: application/json
Accept: application/json
```

#### Request Payload
```json
{
  "device_id": "MEK-GPS-101",
  "api_key": "MEK_SECRET_KEY_ALPHA_01",
  "latitude": -15.786111,
  "longitude": 35.005833,
  "speed": 45.2,
  "heading": 120.0,
  "altitude": 1040.0,
  "accuracy": 3.5,
  "satellites": 12,
  "battery": 96.0,
  "recorded_at": "2026-10-12T12:00:00Z"
}
```

#### Field Specifications
| Parameter | Type | Required | Units / Range | Description |
|---|---|:---:|---|---|
| `device_id` | string | Yes | Unique string (max: 64) | Hardware ID provisioned in Microi |
| `api_key` | string | Yes | Plaintext string | Secret key issued by MicroMek staff |
| `latitude` | decimal | Yes | Degrees (-90.0 to 90.0) | WGS-84 Latitude coordinate |
| `longitude` | decimal | Yes | Degrees (-180.0 to 180.0) | WGS-84 Longitude coordinate |
| `speed` | float | No | Kilometers per hour (km/h) | Ground velocity |
| `heading` | float | No | Degrees (0.0 to 360.0) | Direction of travel |
| `altitude` | float | No | Meters above sea level | Barometric or GPS altitude |
| `accuracy` | float | No | Meters (HDOP) | Horizontal accuracy radius |
| `satellites` | integer | No | Count | Visible GNSS satellites in fix |
| `battery` | float | No | Percentage (0 to 100) | Internal tracker battery charge |
| `recorded_at` | string (ISO-8601) | No | UTC Timestamp | Hardware capture timestamp |

#### Successful Response (`200 OK`)
```json
{
  "status": "success",
  "message": "Telemetry ingested successfully.",
  "assigned_vehicle_id": 1,
  "recorded_at": "2026-10-12T12:00:00+00:00"
}
```

#### Error Responses
- **`401 Unauthorized`**: If `device_id` is unrecognized, inactive, suspended, or `api_key` hash verification fails.
  ```json
  {
    "status": "error",
    "message": "Invalid device credentials."
  }
  ```
- **`422 Unprocessable Entity`**: If coordinates are malformed or out of geographic bounds.
  ```json
  {
    "status": "error",
    "message": "Validation failed.",
    "errors": {
      "latitude": ["The latitude field must be between -90 and 90."]
    }
  }
  ```

---

## 3. Device Heartbeat Endpoint

### `POST /api/v1/device/heartbeat`

Sends periodic diagnostic telemetry when stationary.

#### Request Payload
```json
{
  "device_id": "MEK-GPS-101",
  "api_key": "MEK_SECRET_KEY_ALPHA_01",
  "battery": 94.5
}
```

#### Response (`200 OK`)
```json
{
  "status": "success",
  "server_time": "2026-10-12T12:45:00+00:00"
}
```
