# Microi System Structure

## 1. Overview
Microi (`micro.micromek.net`) is an enterprise multi-tenant GPS vehicle tracking platform engineered for MicroMek. Built on Laravel 13, Livewire 4, Flux UI, Leaflet Maps, and Tailwind CSS v4, Microi provides real-time vehicle tracking, historical journey replay, organization isolation, and automated GPS telemetry ingestion.

## 2. Directory Structure
```
microi2/
├── app/
│   ├── Actions/             # Fortify auth actions and team creation actions
│   ├── Concerns/            # Shared traits (e.g., HasTeams, HasRoles)
│   ├── Enums/               # Status enums (DeviceStatus, RequestStatus, etc.)
│   ├── Http/
│   │   ├── Controllers/     # Web & REST API Controllers (Device Telemetry API)
│   │   └── Middleware/      # Tenant & role enforcement middleware
│   ├── Livewire/            # Real-time reactive components
│   ├── Models/              # Eloquent models with tenancy & relations
│   ├── Notifications/       # Multi-channel alerts and team notifications
│   ├── Policies/            # Authorization policies
│   └── Services/            # Telemetry ingestion, geo calculations, audit logging
├── database/
│   ├── factories/           # Model factories
│   ├── migrations/          # Normalized relational schema
│   └── seeders/             # Baseline seeders (SuperAdmin, Demo Org, Devices)
├── docs/                    # Architectural & operational documentation
├── resources/
│   ├── css/                 # Tailwind CSS styles & Leaflet overrides
│   ├── js/                  # Leaflet map helpers, real-time polling/livewire hooks
│   └── views/               # Blade & Livewire components
└── tests/                   # Pest Feature & Unit test suites
```

## 3. Multi-Tenancy Architecture
- **Tenant Scope:** Organizations (`teams` table) represent tenants. Every vehicle, member, request, and active device assignment is bound to an organization (`team_id`) or an individual owner (`user_id`).
- **Global Personnel:** MicroMek System Administrators and MicroMek Staff possess cross-tenant authorization capabilities.
- **Data Isolation:** Enforced via query scoping, Laravel Policies (`VehiclePolicy`, `DevicePolicy`, `DeviceRequestPolicy`), and database foreign keys.
