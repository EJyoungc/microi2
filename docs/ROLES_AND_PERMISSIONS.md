# Microi Roles & Permissions Matrix

## 1. Role Categories

| Role Key | Name | Scope | Description |
|---|---|---|---|
| `super_admin` | MicroMek System Administrator | Global / Cross-Tenant | Unrestricted platform authority, user management, audit review, and system configuration. |
| `micromek_staff` | MicroMek Staff Engineer | Global / Cross-Tenant | Operational access to review organizations, provision devices, assign hardware, and fulfill customer requests. |
| `owner` (Team) | Organization Administrator | Organization Tenant | Manages organization profile, invites members, adds fleet vehicles, requests GPS trackers, and views organization maps. |
| `member` (Team) | Organization Member | Organization Tenant | Views assigned vehicles, real-time map positions, and playback history according to granted permissions. |
| `customer` | Individual Customer | Individual Account | Manages personal vehicles, requests trackers, views personal maps and historical routes. |

---

## 2. Capability Matrix

| Capability | Super Admin | MicroMek Staff | Org Admin | Org Member | Individual |
|---|:---:|:---:|:---:|:---:|:---:|
| **Review & Approve Organizations** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Suspend / Restore Organizations** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Register & Provision GPS Hardware** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Rotate Device Secret Keys** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Assign / Reassign GPS Units** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Fulfill GPS Requests** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Access System-wide Audit Logs** | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Create & Edit Fleet Vehicles** | ✅ (All) | ✅ (All) | ✅ (Own) | ❌ | ✅ (Own) |
| **Submit Device Request** | ✅ | ✅ | ✅ | ❌ | ✅ |
| **View Real-Time Leaflet Tracking** | ✅ (All) | ✅ (All) | ✅ (Own Org) | ✅ (Own Org) | ✅ (Own) |
| **Inspect Historical Route Playback** | ✅ (All) | ✅ (All) | ✅ (Own Org) | ✅ (Own Org) | ✅ (Own) |
| **Invite Team Members** | ✅ | ✅ | ✅ (Own Org) | ❌ | ❌ |
