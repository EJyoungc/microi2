# Microi Changelog

All notable changes to the Microi platform are documented in this file.

## [1.0.0] - 2026-10-12

### Initial Enterprise Release
- **Initial Workspace Inspection & Baseline Stabilization:**
  - Inspected existing `microi` folder and identified reusable Leaflet map configurations and database schema patterns.
  - Stabilized baseline test suite to 100% passing state.
  - Installed Leaflet and Leaflet type definitions for modern Vite bundling.
- **Data Architecture & Schema Migrations:**
  - Created `create_microi_core_tables` migration: vehicles, devices, device assignments, device requests, location histories (append-only telemetry), and audit logs.
  - Extended `users` table with `system_role`, `account_type`, `phone_number`, `physical_address`, and `onboarding_completed`.
  - Extended `teams` table with `status`, `org_type`, `registration_number`, `contact_email`, `contact_phone`, and `rejection_reason`.
- **Authentication & Multi-Tenant Onboarding:**
  - Implemented dual-mode registration (Individual vs Organization Fleet).
  - Added organization pending approval lifecycle.
  - Implemented first-login mandatory contact completion modal (`OnboardingModal`).
- **Administrative Modules for MicroMek Staff:**
  - Created `OrganizationsLivewire` for cross-tenant review, approvals, rejections, and suspensions.
  - Created `DevicesLivewire` for GPS hardware provisioning, one-time secret key generation, rotation, and vehicle assignments.
  - Created `DeviceRequestsLivewire` for reviewing customer tracking hardware requests and fulfillment.
- **Customer & Fleet Modules:**
  - Created `VehiclesLivewire` with tenant isolation, full vehicle CRUD, and one-click GPS device requests.
  - Created `DashboardOverview` with real-time operational metrics and tabbed navigation.
- **Leaflet Map Tracking & Journey Playback:**
  - Created `LiveMapLivewire` featuring auto-refreshing polling (10s), custom status markers, popup details, and vehicle selection focus.
  - Created `HistoryMapLivewire` featuring date selection, polyline route drawing, start/end markers, and individual coordinate log review.
- **GPS Device REST API:**
  - Implemented versioned `/api/v1/device/telemetry` endpoint with hash verification, coordinate validation, out-of-order protection, and transaction safety.
  - Implemented `/api/v1/device/heartbeat` for stationary diagnostics.
- **Public Landing Page & Branding:**
  - Created polished landing page for `micro.micromek.net` integrating verified MicroMek company information (CEO Dumisani Kaliati, Blantyre HQ, contact details, UAV healthcare mission).
- **Testing & Verification:**
  - 83/83 Pest tests passing with zero regressions covering tenancy boundaries, registration, API security, and hardware workflows.
- **Comprehensive Documentation Suite:**
  - Created README, SYSTEM_STRUCTURE, SYSTEM_FUNCTIONALITY, WORKFLOWS, ROLES_AND_PERMISSIONS, DATABASE_SCHEMA, API, DEPLOYMENT, and TESTING guides.
