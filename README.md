# JK BMS Monitor

Remote monitoring for LiFePO4 energy banks managed by JK BMS. An ESP32
(XIAO-ESP32-S3) bridge reads parameters over BLE and posts them every 30s to
this app's ingest API; the web app shows current values, a status
classification, and a history chart per location.

See `context/foundation/prd.md` for the full product spec, `context/foundation/tech-stack.md`
for the stack decision record.

## Stack

Vanilla PHP 8.2, no framework — PSR-4 autoload, server-rendered PHP
templates, MySQL, Composer. Matches the stack already used in other
CyberFolks-hosted projects (see `context/foundation/tech-stack.md`).

## Setup

```bash
composer install
cp .env.example .env
# edit .env: APP_PASSWORD, DB_* credentials
```

Run the migrations in `database/migrations/` against your MySQL database, in
order (001, 002, ...).

Point your web server's document root at `public/`, or use PHP's built-in
server for local development:

```bash
php -S localhost:8000 -t public
```

## Adding a device

Insert a row into `devices` with a location `name` and a random
`device_key` (used by the ESP32 firmware to authenticate `POST /api/ingest`
via the `X-Device-Key` header — see `firmware/README.md`).

## Tests & static analysis

```bash
composer test   # PHPUnit
composer stan   # PHPStan (level 6)
```

## Deploy

Shared hosting (CyberFolks), same mechanics as other projects on this host:
`git push` + manual `git pull` / FTP on the server, `composer install
--no-dev --optimize-autoloader`. No CI/CD auto-deploy pipeline in MVP —
GitHub Actions runs checks only, promotion is manual.
