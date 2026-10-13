# Microi — System Functionality

## 1. Authentication & Onboarding
- **Individual Registration:** Directly provisions a personal workspace for individual vehicle tracking.
- **Organization Registration:** Captures business registration number, physical address, and organization category. Automatically establishes a `pending` organization workspace awaiting MicroMek administrative approval.
- **First-Login Modal:** Enforces phone number and physical address entry on initial access, setting `onboarding_completed = true` upon submission.

## 2. Multi-Tenant Fleet Architecture
- Tenants are modeled through `teams` with explicit tenant isolation on vehicles, device requests, and active GPS telemetry.
- MicroMek internal staff possess cross-tenant capabilities to review all customer hardware, vehicles, and incoming coordinates.

## 3. Vehicle & Fleet Management
- Full CRUD capabilities for vehicles with plate numbers, models, categories (sedan, SUV, truck, van, motorcycle, drone UAV), and operational notes.
- In-place GPS device request submission allowing one-click hardware ordering for any registered fleet vehicle.

## 4. GPS Hardware Provisioning & Secrets Management
- Centralized hardware registry supporting Device ID and Serial Number bindings.
- One-time secure plain API key generation (`MEK_<RANDOM>`) with salted cryptographic hashing (`api_key_hash`) stored on disk.
- Instant credential rotation with automated invalidation of stale keys.
- Safe vehicle assignment and reassignment preventing duplicate active devices on vehicles.

## 5. Live Leaflet Tracking Map
- Real-time vehicle positions plotted via Leaflet Maps with 10-second polling refresh.
- Color-coded online/stale status indicators based on telemetry heartbeat.
- Vehicle selection drawer with auto-zoom `flyTo` functionality.

## 6. Historical Journey Route Playback
- Calendar date picker for any selected vehicle.
- Renders movement trajectory as continuous smooth Leaflet polyline overlays.
- Pinpoints starting location, terminal stopping location, and individual velocity/timestamp events.

## 7. Versioned Telemetry API (`/api/v1/device/`)
- Ingests incoming coordinates via authenticated HTTP POST requests.
- Validates latitude, longitude, velocity, heading, and battery health.
- Prevents out-of-order older messages from overriding newer device coordinates.
