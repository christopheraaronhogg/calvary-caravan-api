# Calvary Caravan API (Laravel 12)

Standalone backend for the **Calvary Caravan** mobile app (Expo/React Native). This is intentionally **not coupled** to any other church app codebase so it can deploy and iterate independently.

## API Base URL

All endpoints are under:

`/api/v1/retreat`

Auth uses the `X-Device-Token` header returned from `POST /join`.

## Local dev

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

Create a test retreat:

```bash
php artisan retreat:create "Couples Retreat 2026" --code=TEST26 --destination="Chateau on the Lake" --lat=36.611158 --lng=-93.306554 --starts="2026-02-13 00:00:00" --ends="2026-02-14 23:59:59"
```

Run tests:

```bash
php artisan test
```

## NativePHP mobile (iOS + Android)

This repo includes NativePHP scaffolding for shipping a Laravel-powered mobile build.

Quick start (NativePHP shell):

```bash
./scripts/nativephp-svelte-bootstrap.sh
./scripts/nativephp-bootstrap.sh
./scripts/nativephp-preflight.sh
```

Svelte-first UI bootstrap (recommended for Caravan, with Runed utilities + shadcn-svelte components):

```bash
./scripts/nativephp-svelte-bootstrap.sh
```

Detailed guides:

- `docs/nativephp-mobile-bootstrap.md`
- `docs/nativephp-svelte-bootstrap.md`
- `docs/app-store/README.md` (Apple App Store metadata/review/screenshots readiness kit)
- `docs/google-play/README.md` (Google Play metadata/policy/readiness kit)

Submission readiness checks:

```bash
./scripts/nativephp-preflight.sh
./scripts/nativephp-fix-appicon.sh
./scripts/appstore-readiness.sh
./scripts/playstore-readiness.sh
```

Web/mobile shell health checks:

```bash
./scripts/web-health-check.sh
```

`/mobile` deep links are routed back to `public/mobile/index.html` so static Svelte routes continue to work under Laravel/Forge.

## Laravel Forge notes

- Point Forge’s “Git Repository” to **this** repo (not the Expo mobile repo).
- Set app env vars (`APP_KEY`, DB creds, etc.) in Forge.
- Ensure the deploy script runs:
  - `composer install --no-dev --optimize-autoloader`
  - `php artisan migrate --force`

## SpacetimeDB location sidecar (hardening path)

A SpacetimeDB location sidecar prototype is included at:

- `spacetimedb-location/spacetimedb`

Run quick local smoke:

```bash
./scripts/spacetime-smoke.sh
```

Or run the manual flow:

```bash
cd spacetimedb-location/spacetimedb
npm run build
spacetime start --in-memory --listen-addr 127.0.0.1:3000 --non-interactive
spacetime publish calvary-location-tracker-smoke --project-path . --server local --anonymous -y --delete-data=always
spacetime call --server local --anonymous -y calvary-location-tracker-smoke upsert_location -- 1 1 35.1001 -90.2202 6.5 0 180 220 1739380200000
spacetime call --server local --anonymous -y calvary-location-tracker-smoke list_latest_locations_for_retreat 1
```

Laravel can optionally dual-write accepted `/api/v1/retreat/location` updates to SpacetimeDB when enabled:

- `SPACETIME_LOCATION_MIRROR_ENABLED=true`
- `SPACETIME_SERVER=<local|maincloud|...>`
- `SPACETIME_DATABASE=<database-name>`
- `SPACETIME_ANONYMOUS=<true|false>`

