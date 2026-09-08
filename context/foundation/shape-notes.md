---
project: "JK BMS Monitor"
context_type: greenfield
created: 2026-09-07
updated: 2026-09-07
product_type: web-app
target_scale:
  users: small
  qps: low
  data_volume: small
timeline_budget:
  mvp_weeks: 3
  hard_deadline: null
  after_hours_only: true
checkpoint:
  current_phase: 8
  phases_completed: [1, 2, 3, 4, 5, 6, 7]
  gray_areas_resolved:
    - topic: "pain category"
      decision: "brak zdalnej widoczności + brak reakcji na stany awaryjne + brak scentralizowanego widoku wielu lokalizacji + brak historii"
    - topic: "insight"
      decision: "domyślna aplikacja JK BMS jest lokalna/BLE-only — nie oferuje chmury, zdalnego dostępu ani agregacji wielu lokalizacji"
    - topic: "primary persona scope"
      decision: "właściciel (Ty) + zaufana rodzina/znajomi z dostępem do wybranych lokalizacji (post-MVP)"
    - topic: "access control MVP"
      decision: "pojedyncze hasło do strony (zaszyte w .env na serwerze), brak kont/rejestracji, brak ról — jeden użytkownik (właściciel) widzi wszystkie lokalizacje"
  frs_drafted: 8
  quality_check_status: accepted
---

## Vision & Problem Statement

Właściciel kilku banków energii LiFePO4 (dom, działka, jacht), z których każdy zarządzany jest przez JK BMS, nie ma dziś żadnego zdalnego ani scentralizowanego wglądu w ich stan. Domyślna aplikacja JK BMS łączy się wyłącznie lokalnie przez BLE — wymaga fizycznej obecności w zasięgu radiowym, nie zapisuje historii pomiarów i nie potrafi zagregować wielu lokalizacji w jednym miejscu. W warunkach skrajnych (np. przekroczenie parametru na jachcie, gdy nikt nie jest na miejscu) brak jakiejkolwiek automatycznej reakcji oznacza, że problem zostaje wykryty za późno albo wcale.

Producent nie oferuje rozwiązania chmurowego dla tego modelu BMS — to nisza, którą można zapełnić własnym systemem: mostek WiFi (ESP32) odczytujący dane przez BLE i wysyłający je do własnego backendu, z jednym webowym widokiem na wszystkie lokalizacje naraz.

## User & Persona

**Primary persona:** Właściciel instalacji (Ty) — osoba, która samodzielnie zmontowała banki energii LiFePO4 w kilku lokalizacjach (dom, działka, jacht) i chce mieć jeden scentralizowany, zdalny wgląd w ich stan bez konieczności fizycznego podłączania się przez BLE do każdego z osobna.

### Secondary persona
Zaufana rodzina/znajomi z dostępem do wybranych lokalizacji (np. ktoś, kto doglądałby jachtu pod nieobecność właściciela). Zakres i sposób udostępniania dostępu doprecyzowany w fazie Access Control.

## Business Logic

The system classifies each reading's overall bank status as normal, warning, or critical by comparing state-of-charge, voltage (pack and per-cell), temperature, and charge/discharge current against fixed safety thresholds.

The rule consumes the four parameter groups the owner already sees as raw values (SOC, voltage, temperature, current) from the most recent reading of a given location. Its output is a single status label attached to that location, shown alongside the raw values so the owner doesn't have to mentally compare numbers against safe ranges themselves. The owner encounters it as a status badge/color next to each location in the current-values view, and as a visual marker on the history chart when a reading crossed into warning/critical.

Thresholds are fixed defaults for MVP (not user-configurable per location) — configurability is deferred, see Forward section.

## Non-Functional Requirements

- A logged-in user sees a current value that is no more than ~2 minutes old under normal device connectivity.
- The web application is reachable from any internet connection — no VPN or local-network requirement.
- Historical readings are retained indefinitely in MVP; no automatic deletion or aggregation of older data.
- A temporary loss of BLE or WiFi connectivity does not leave the device in a permanently failed state — it resumes reporting on its own once connectivity returns, without manual intervention.

## Access Control

Single shared password gating the web application, stored server-side as an environment variable (`.env`) — no user accounts, no registration flow, no per-user credentials. One authenticated session sees all locations/banks. No role separation in MVP: the only "role" is "has the password."

Post-MVP: per-user accounts with role separation (admin = owner, viewer = per-location access for trusted family/friends) — deferred, see Forward section below.

## Success Criteria

### Primary
- Co najmniej jeden bank energii nieprzerwanie raportuje parametry co 30s przez ESP32 → WiFi → API → baza, a aktualne wartości oraz prosty wykres historii są widoczne w aplikacji webowej po zalogowaniu hasłem.

### Secondary
- MVP obsługuje więcej niż jedną lokalizację/bank równolegle (nie tylko pojedyncze urządzenie testowe).
- Widoczny status online/offline urządzenia ESP32 — czy dane urządzenie aktualnie raportuje, czy "ucichło".

### Guardrails
- Ciągłość danych: chwilowa utrata WiFi nie może tworzyć trwałych, niewidocznych luk w historii — po powrocie łączności zapis jest wznawiany.
- Hasło/API nie mogą wyciec publicznie: endpoint API i strona logowania wymagają hasła/klucza; baza nie jest publicznie czytelna.
- Aplikacja webowa jest responsywna — używalna również z telefonu, nie tylko z komputera.

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
  > Socrates: Counter-argument considered: "frequent requests from many devices could strain cheap shared hosting (CyberFolks) — buffer locally and send less often." Resolution: kept at 30s; a handful of devices won't strain hosting, revisit if it becomes a real problem.

### Backend / API
- FR-003: Backend can persist every reading to the database with a timestamp and a location/device identifier. Priority: must-have
  > Socrates: Counter-argument considered: "30s x many params x many devices could fill a shared-hosting DB fast — aggregate/downsample older data now." Resolution: kept as-is for MVP; full granularity is more valuable now than storage savings, retention/aggregation policy deferred until real usage is observed.

### Frontend
- FR-004: User can log in to the web application with a shared password (stored server-side in `.env`). Priority: must-have
  > Socrates: Counter-argument considered: "a single shared password can't revoke one person's access without rotating it for everyone." Resolution: kept as-is; MVP has one user (the owner), per-user accounts are already deferred to post-MVP.
- FR-005: Logged-in user can see current parameter values for each location. Priority: must-have
  > Socrates: Counter-argument considered: "showing every read parameter could overload the first view — scope to a key subset (SOC/voltage/current/temp)." Resolution: kept as-is; show everything read, mirroring the reference JK BMS app.
- FR-006: Logged-in user can see a simple history chart of a selected parameter over time. Priority: must-have
  > Socrates: Counter-argument considered: "charting adds complexity (charting library, aggregation queries) — could push to a step-2 release." Resolution: kept in MVP; the user explicitly wanted at least one chart to prove history is actually captured and usable.
- FR-007: Logged-in user can see the online/offline status of each ESP32 device. Priority: must-have
  > Socrates: Counter-argument considered: "requires defining a timeout/heartbeat — added logic for MVP." Resolution: kept as-is; directly supports the "data continuity" guardrail — without it, silence from a device is invisible.
- FR-008: System supports more than one location/bank running in parallel. Priority: nice-to-have
  > Socrates: Counter-argument considered: "supporting multiple locations from day one widens MVP test surface (need >1 device configured) — ship one location fully working first." Resolution: demoted to nice-to-have; MVP proves the flow end-to-end on one location, but the data model (location/device identifier, already in FR-003) is built multi-location-ready from the start to avoid a costly refactor later.

## Non-Goals

- **No write/control commands back to the BMS** — read-only for MVP. Turning the bank on/off or changing its configuration remotely is out of scope; rationale: it's a separate, higher-risk capability (safety implications) that isn't needed to prove the monitoring flow works.
- **No active notifications/alerts** (push, email, SMS) — the status classification (normal/warning/critical) is computed and shown in the UI, but MVP does not push anything to the user proactively. Rationale: this is the single biggest post-MVP driver, but it needs the read pipeline proven first.
- **No multi-user accounts or roles** — one shared password, no per-user identity, no role separation. Rationale: MVP has one real user (the owner); per-user access is deferred until family/friends actually need scoped access.
- **No configurable alarm thresholds per location** — classification thresholds are fixed in code, not editable via UI. Rationale: keeps MVP scope small; configurability is only worth building once real threshold values have been validated in practice.
- FR-008 (multi-location support) is demoted to nice-to-have — MVP proves the flow on one location; multi-location is not a hard requirement for MVP success, though the data model is built to support it from the start (see FR-003 Socrates note).

## Forward: post-MVP roadmap (nie wchodzi do PRD wprost — kontekst dla Non-Goals / Open Questions)
- Zapis/konfiguracja zwrotna do BMS (write commands: włącz/wyłącz, zmiana parametrów) — poza zakresem MVP.
- Powiadomienia/alerty przy przekroczeniu progów parametrów — poza zakresem MVP, ale to główny długoterminowy motywator projektu.
- Analizy i wykresy historyczne (bazujące na zebranej historii) — poza zakresem MVP, naturalny krok 2 po zebraniu danych.
