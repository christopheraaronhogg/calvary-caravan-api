# Calvary Caravan SpacetimeDB Sidecar

This module mirrors **latest caravan locations** for realtime fan-out while Laravel/Postgres remains source of truth.

## Build

```bash
npm install
npm run build
```

## Local smoke

From repo root:

```bash
./scripts/spacetime-smoke.sh
```

Or manually:

```bash
spacetime start --in-memory --listen-addr 127.0.0.1:3000 --non-interactive
spacetime publish calvary-location-smoke --project-path spacetimedb-location/spacetimedb --server local --anonymous -y --delete-data=always
spacetime call --server local --anonymous -y calvary-location-smoke upsert_location -- 1 1 35.1001 -90.2202 6.5 0 180 220 1739380200000
spacetime call --server local --anonymous -y calvary-location-smoke list_latest_locations_for_retreat 1
```

## Integration in Laravel

Laravel mirrors `/api/v1/retreat/location` writes only when feature flag is enabled:

- `SPACETIME_LOCATION_MIRROR_ENABLED=true`
- `SPACETIME_SERVER=local`
- `SPACETIME_DATABASE=<published-db-name>`
- `SPACETIME_ANONYMOUS=true` (optional)

If mirror calls fail, Laravel keeps accepting location writes into Postgres and logs warnings.
