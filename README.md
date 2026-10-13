# Microi — GPS Vehicle Tracking Platform for MicroMek

**Domain:** `micro.micromek.net`  
**Organization:** MicroMek  
**Technology Stack:** Laravel 13, Livewire 4, Flux UI, Leaflet Maps, Tailwind CSS v4, SQLite / MySQL, REST API.

Microi is an enterprise-grade multi-tenant GPS vehicle tracking platform engineered for MicroMek. The system enables individuals and organizations across Malawi to manage vehicles, request GPS hardware, monitor live positions on interactive Leaflet maps, and review historical movement trajectories.

---

## Key Features

- **Multi-Tenant Fleet Isolation:** Organizations and individual owners maintain strict data boundaries enforced via query scoping and authorization policies.
- **MicroMek Administrative Control:** Cross-tenant operations for MicroMek staff to approve organizations, provision GPS devices, rotate secret keys, and fulfill hardware requests.
- **Real-Time Interactive Maps:** Leaflet map integration with automated 10-second polling refresh, vehicle status indicators, speed metrics, and camera focus.
- **Historical Route Playback:** Calendar date selector rendering continuous polyline routes, start/end locations, and timestamped telemetry logs.
- **Versioned Telemetry API (`/api/v1/device/`):** High-performance REST endpoints for GPS devices to submit coordinates with cryptographic token verification and out-of-order packet protection.
- **First-Login Onboarding Modal:** Ensures complete user contact records before granting access to vehicle tracking.
- **Comprehensive Audit Trail:** Logs significant administrative and hardware actions.

---

## Quick Setup & Development

### 1. Requirements
- PHP 8.3+
- Node.js 20+ & npm
- Composer

### 2. Installation
```bash
git clone <repository_url> microi2
cd microi2
composer install
npm install
npm run build
```

### 3. Database Migration & Seed
```bash
php artisan migrate
php artisan db:seed --class=MicroiDatabaseSeeder
```

### 4. Running the Development Server
```bash
php artisan serve
```

---

## Seeded Administrative & Test Accounts

| Account | Email | Password | Role |
|---|---|---|---|
| **MicroMek Super Admin** | `admin@micromek.net` | `password` | Super Administrator |
| **MicroMek Staff** | `staff@micromek.net` | `password` | Staff Operator |
| **Apex Logistics Fleet Manager** | `manager@apexlogistics.mw` | `password` | Organization Owner |
| **Individual Vehicle Owner** | `customer@gmail.com` | `password` | Individual Customer |

---

## Test Suite Execution
```bash
# Execute entire test suite
php artisan test --compact

# Execute specific suites
vendor/bin/pest tests/Feature/Api/DeviceTelemetryTest.php
vendor/bin/pest tests/Feature/MicroiRegistrationTest.php
vendor/bin/pest tests/Feature/MicroiTenancyAndPermissionsTest.php
```

---

## Documentation Library

Detailed documentation is available in the [`docs/`](docs/) directory:
- [System Structure](docs/SYSTEM_STRUCTURE.md)
- [System Functionality](docs/SYSTEM_FUNCTIONALITY.md)
- [Workflows & Diagrams](docs/WORKFLOWS.md)
- [Roles & Permissions](docs/ROLES_AND_PERMISSIONS.md)
- [Database Schema](docs/DATABASE_SCHEMA.md)
- [Telemetry REST API Contract](docs/API.md)
- [Deployment Guide](docs/DEPLOYMENT.md)
- [Testing & Quality Assurance](docs/TESTING.md)
- [Changelog](docs/CHANGELOG.md)
