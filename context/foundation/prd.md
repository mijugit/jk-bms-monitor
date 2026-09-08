---
project: "JK BMS Monitor"
version: 1
status: draft
created: 2026-09-07
context_type: greenfield
product_type: web-app
target_scale:
  users: small
  qps: low
  data_volume: small
timeline_budget:
  mvp_weeks: 3
  hard_deadline: null
  after_hours_only: true
---

## Vision & Problem Statement

The owner of several LiFePO4 energy banks (home, plot of land, boat), each managed by a JK BMS, has no remote or centralized visibility into their state today. The default JK BMS app connects only locally over BLE — it requires physical presence within radio range, keeps no history of readings, and cannot aggregate multiple locations into one place. In edge cases (e.g. a parameter going out of range on the boat while no one is there), the absence of any automatic reaction means the problem is discovered too late, or not at all.

The manufacturer offers no cloud solution for this BMS model — a gap that can be filled with a purpose-built system: a WiFi bridge (ESP32) that reads data over BLE and forwards it to a self-hosted backend, with a single web view over all locations at once.

## User & Persona

**Primary persona:** The installation owner — someone who assembled LiFePO4 energy banks themselves across several locations (home, plot of land, boat) and wants one centralized, remote view of their state without physically connecting over BLE to each one individually.

### Secondary persona
Trusted family/friends with access to selected locations (e.g. someone checking on the boat while the owner is away). Scope and access-sharing mechanics are deferred — see Open Questions and Non-Goals (no multi-user accounts in MVP).

## Success Criteria

### Primary
- At least one energy bank continuously reports parameters every 30s through ESP32 → WiFi → API → database, with current values and a simple history chart visible in the web application after password login.

### Secondary
- MVP supports more than one location/bank running in parallel (not just a single test device).
- Visible online/offline status of the ESP32 device — whether a given device is currently reporting or has "gone quiet."

### Guardrails
- Data continuity: a temporary WiFi outage must not create permanent, invisible gaps in history — recording resumes once connectivity returns.
- The password/API must not leak publicly: the API endpoint and login page require a password/key; the database is not publicly readable.
- The web application is responsive — usable from a phone, not just a computer.

## User Stories

### US-01: Owner checks battery bank state remotely

- **Given** an ESP32 device is running at a location, connected via BLE to the JK BMS and reporting over WiFi
- **When** the owner logs into the web app with the shared password
- **Then** they see the current parameter values for that location and a simple history chart of a selected parameter, without needing physical BLE proximity

#### Acceptance Criteria
- Current values reflect a reading no older than ~30-60s under normal connectivity
- The device's online/offline status is visible next to its data
- If WiFi drops temporarily, the gap is visible in the history chart rather than silently interpolated

## Functional Requirements

### Firmware (ESP32)
- FR-001: ESP32 can connect via BLE to JK BMS and read all available parameters (voltage, current, SOC, temperatures, per-cell voltages, etc.). Priority: must-have
  > Socrates: Counter-argument considered: "reading every parameter increases BLE reverse-engineering effort and dev time — could scope to voltage/current/SOC/temp only." Resolution: kept as-is; JK BMS BLE protocol is already well-documented in the community.
- FR-002: ESP32 can send the read parameters every 30s over WiFi to the backend API. Priority: must-have
  > Socrates: Counter-argument considered: "frequent requests from many devices could strain cheap shared hosting — buffer locally and send less often." Resolution: kept at 30s; a handful of devices won't strain hosting, revisit if it becomes a real problem.

### Backend / API
- FR-003: Backend can persist every reading to the database with a timestamp and a location/device identifier. Priority: must-have
  > Socrates: Counter-argument considered: "30s x many params x many devices could fill a shared-hosting DB fast — aggregate/downsample older data now." Resolution: kept as-is for MVP; full granularity is more valuable now than storage savings, retention/aggregation policy deferred until real usage is observed.

### Frontend
- FR-004: User can log in to the web application with a shared password. Priority: must-have
  > Socrates: Counter-argument considered: "a single shared password can't revoke one person's access without rotating it for everyone." Resolution: kept as-is; MVP has one user (the owner), per-user accounts are already deferred to post-MVP.
- FR-005: Logged-in user can see current parameter values for each location. Priority: must-have
  > Socrates: Counter-argument considered: "showing every read parameter could overload the first view — scope to a key subset (SOC/voltage/current/temp)." Resolution: kept as-is; show everything read, mirroring the reference JK BMS app.
- FR-006: Logged-in user can see a simple history chart of a selected parameter over time. Priority: must-have
  > Socrates: Counter-argument considered: "charting adds complexity — could push to a step-2 release." Resolution: kept in MVP; the user explicitly wanted at least one chart to prove history is actually captured and usable.
- FR-007: Logged-in user can see the online/offline status of each ESP32 device. Priority: must-have
  > Socrates: Counter-argument considered: "requires defining a timeout/heartbeat — added logic for MVP." Resolution: kept as-is; directly supports the "data continuity" guardrail — without it, silence from a device is invisible.
- FR-008: System supports more than one location/bank running in parallel. Priority: nice-to-have
  > Socrates: Counter-argument considered: "supporting multiple locations from day one widens MVP test surface (need >1 device configured) — ship one location fully working first." Resolution: demoted to nice-to-have; MVP proves the flow end-to-end on one location, but the data model (location/device identifier, already in FR-003) is built multi-location-ready from the start to avoid a costly refactor later.

## Non-Functional Requirements

- A logged-in user sees a current value that is no more than ~2 minutes old under normal device connectivity.
- The web application is reachable from any internet connection — no VPN or local-network requirement.
- Historical readings are retained indefinitely in MVP; no automatic deletion or aggregation of older data.
- A temporary loss of BLE or WiFi connectivity does not leave the device in a permanently failed state — it resumes reporting on its own once connectivity returns, without manual intervention.

## Business Logic

The system classifies each reading's overall bank status as normal, warning, or critical by comparing state-of-charge, voltage (pack and per-cell), temperature, and charge/discharge current against fixed safety thresholds.

The rule consumes the four parameter groups the owner already sees as raw values (SOC, voltage, temperature, current) from the most recent reading of a given location. Its output is a single status label attached to that location, shown alongside the raw values so the owner doesn't have to mentally compare numbers against safe ranges themselves. The owner encounters it as a status badge/color next to each location in the current-values view, and as a visual marker on the history chart when a reading crossed into warning/critical.

Thresholds are fixed defaults for MVP (not user-configurable per location) — see Non-Goals.

## Access Control

A single shared password gates the web application — no user accounts, no registration flow, no per-user credentials. One authenticated session sees all locations/banks. No role separation in MVP: the only "role" is "has the password." The password is never exposed in the application's distributed code or inspectable by a visitor — it can only be verified, not read, by anyone without it.

Post-MVP: per-user accounts with role separation (admin = owner, viewer = per-location access for trusted family/friends) — see Non-Goals.

## Non-Goals

- **No write/control commands back to the BMS** — read-only for MVP. Turning the bank on/off or changing its configuration remotely is out of scope; rationale: it's a separate, higher-risk capability (safety implications) that isn't needed to prove the monitoring flow works.
- **No active notifications/alerts** (push, email, SMS) — the status classification (normal/warning/critical) is computed and shown in the UI, but MVP does not push anything to the user proactively. Rationale: this is the single biggest post-MVP driver, but it needs the read pipeline proven first.
- **No multi-user accounts or roles** — one shared password, no per-user identity, no role separation. Rationale: MVP has one real user (the owner); per-user access is deferred until family/friends actually need scoped access.
- **No configurable alarm thresholds per location** — classification thresholds are fixed, not editable via UI. Rationale: keeps MVP scope small; configurability is only worth building once real threshold values have been validated in practice.
- **Multi-location support is nice-to-have, not required** (FR-008) — MVP proves the flow on one location; the data model is built to support more from the start, but shipping several locations in parallel is not a hard requirement for MVP success.
- **No historical analytics beyond a single simple chart** — deeper analysis (trends, comparisons across locations, seasonal views) is out of scope; it's a natural step 2 once history has accumulated.

## Open Questions

1. **How will access for trusted family/friends actually be shared and scoped, once introduced post-MVP?** — Owner: user. By: before per-user accounts are designed (post-MVP). Not blocking MVP (see Non-Goals: no multi-user accounts in MVP).
2. **What are the exact safety thresholds for the normal/warning/critical classification (SOC, voltage, temperature, current)?** — Owner: user. By: before implementation of the Business Logic rule begins. Not blocking PRD sign-off, but blocking implementation of FR-005/FR-006 status display.
