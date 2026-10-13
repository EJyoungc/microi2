# Microi Testing & Quality Assurance

## 1. Test Suite Overview
Microi utilizes **Pest PHP** for behavioral, unit, and end-to-end feature testing. All tests are integrated into Laravel and execute against in-memory or SQLite database transactions.

## 2. Running Automated Tests
```bash
# Run complete test suite
php artisan test --compact

# Run specific domain test suites
vendor/bin/pest tests/Feature/Api/DeviceTelemetryTest.php
vendor/bin/pest tests/Feature/MicroiRegistrationTest.php
vendor/bin/pest tests/Feature/MicroiTenancyAndPermissionsTest.php
vendor/bin/pest tests/Feature/Teams/TeamInvitationTest.php
```

## 3. Test Coverage Summary
- **Authentication & Registration (`MicroiRegistrationTest`):**
  - Individual registration flow.
  - Organization registration creating pending status teams.
  - Profile onboarding flag checks.
- **Tenant Isolation & Security (`MicroiTenancyAndPermissionsTest`):**
  - Organization A prohibited from accessing Organization B's vehicles.
  - Organization B blocked with 403 on attempted cross-tenant modifications.
  - MicroMek internal staff cross-tenant visibility.
  - Clean device assignment and conflict-free reassignment.
- **Device Telemetry REST API (`DeviceTelemetryTest`):**
  - Authenticated ingestion with valid GPS payloads.
  - 401 rejection on invalid secret tokens.
  - 401 rejection on inactive or suspended devices.
  - 422 validation on impossible geographic coordinates.
  - Out-of-order packet safety preventing older timestamps from overwriting newer positions.
- **Teams & Invitations (`TeamInvitationTest`):**
  - Email invitations, acceptance, cancellation, and duplicate prevention.
