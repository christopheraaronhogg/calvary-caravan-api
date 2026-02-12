#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODULE_DIR="$ROOT_DIR/spacetimedb-location/spacetimedb"
cd "$ROOT_DIR"

DB_NAME="${1:-calvary-laravel-dualwrite-$(date +%Y%m%d%H%M%S)}"
SERVER_NAME="${SPACETIME_SERVER:-local}"
LISTEN_ADDR="${SPACETIME_LISTEN_ADDR:-127.0.0.1:3000}"
API_PORT="${LARAVEL_SMOKE_PORT:-8093}"
API_BASE="http://127.0.0.1:${API_PORT}"
APP_KEY_VALUE="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
SQLITE_DB="$(mktemp -t caravan-dualwrite.XXXXXX.sqlite)"
SERVER_LOG="$(mktemp -t caravan-dualwrite-api.XXXXXX.log)"
SPACETIME_LOG=""
started_spacetime=0
spacetime_pid=""
laravel_pid=""

cleanup() {
  if [[ -n "$laravel_pid" ]]; then
    kill "$laravel_pid" >/dev/null 2>&1 || true
  fi
  if [[ "$started_spacetime" -eq 1 && -n "$spacetime_pid" ]]; then
    kill "$spacetime_pid" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

if ! command -v spacetime >/dev/null 2>&1; then
  echo "Missing spacetime CLI" >&2
  exit 1
fi

if ! command -v sqlite3 >/dev/null 2>&1; then
  echo "Missing sqlite3" >&2
  exit 1
fi

if ! spacetime list --server "$SERVER_NAME" >/dev/null 2>&1; then
  SPACETIME_LOG="$(mktemp -t caravan-dualwrite-spacetime.XXXXXX.log)"
  spacetime start --in-memory --listen-addr "$LISTEN_ADDR" --non-interactive >"$SPACETIME_LOG" 2>&1 &
  spacetime_pid=$!
  started_spacetime=1

  for _ in {1..30}; do
    if spacetime list --server "$SERVER_NAME" >/dev/null 2>&1; then
      break
    fi
    sleep 0.5
  done
fi

if ! spacetime list --server "$SERVER_NAME" >/dev/null 2>&1; then
  echo "Unable to reach Spacetime server '$SERVER_NAME'" >&2
  [[ -n "$SPACETIME_LOG" ]] && echo "Spacetime log: $SPACETIME_LOG" >&2
  exit 1
fi

echo "[dualwrite] Building sidecar module"
(
  cd "$MODULE_DIR"
  npm run build >/dev/null
)

echo "[dualwrite] Publishing database: $DB_NAME"
spacetime publish "$DB_NAME" --project-path "$MODULE_DIR" --server "$SERVER_NAME" --anonymous -y --delete-data=always >/dev/null

ENV_PREFIX=(
  APP_KEY="$APP_KEY_VALUE"
  DB_CONNECTION=sqlite
  DB_DATABASE="$SQLITE_DB"
  SPACETIME_LOCATION_MIRROR_ENABLED=true
  SPACETIME_SERVER="$SERVER_NAME"
  SPACETIME_DATABASE="$DB_NAME"
  SPACETIME_ANONYMOUS=true
)

echo "[dualwrite] Running migrations + seeding smoke retreat"
env "${ENV_PREFIX[@]}" php artisan migrate --force >/dev/null
env "${ENV_PREFIX[@]}" php artisan retreat:create "Dualwrite Smoke Retreat" --code=SMOKE1 --destination="Branson" --lat=36.611158 --lng=-93.306554 --starts="2026-02-13 00:00:00" --ends="2026-02-14 23:59:59" >/dev/null

echo "[dualwrite] Starting Laravel API on $API_BASE"
env "${ENV_PREFIX[@]}" php artisan serve --host=127.0.0.1 --port="$API_PORT" >"$SERVER_LOG" 2>&1 &
laravel_pid=$!

for _ in {1..30}; do
  if curl -s -o /dev/null "${API_BASE}/api/health"; then
    break
  fi
  sleep 0.5
done

if ! curl -s -o /dev/null "${API_BASE}/api/health"; then
  echo "Laravel API failed to start. Log: $SERVER_LOG" >&2
  exit 1
fi

join_response="$(curl -s -X POST "${API_BASE}/api/v1/retreat/join" -H "Content-Type: application/json" -d '{"code":"SMOKE1","name":"Mirror Tester"}')"
token="$(php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["device_token"] ?? "";' <<<"$join_response")"
participant_id="$(php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["participant_id"] ?? "";' <<<"$join_response")"

if [[ -z "$token" || -z "$participant_id" ]]; then
  echo "Join flow failed: $join_response" >&2
  exit 1
fi

recorded_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
location_response="$(curl -s -X POST "${API_BASE}/api/v1/retreat/location" -H "Content-Type: application/json" -H "X-Device-Token: ${token}" -d "{\"latitude\":36.611158,\"longitude\":-93.306554,\"accuracy\":5,\"speed\":0,\"heading\":180,\"altitude\":300,\"recorded_at\":\"${recorded_at}\"}")"
recorded_flag="$(php -r '$j=json_decode(stream_get_contents(STDIN),true); echo ($j["data"]["recorded"] ?? false) ? "true" : "false";' <<<"$location_response")"

if [[ "$recorded_flag" != "true" ]]; then
  echo "Location update failed: $location_response" >&2
  exit 1
fi

laravel_source_count="$(sqlite3 "$SQLITE_DB" "select count(*) from participant_locations;")"
spacetime_rows="$(spacetime call --server "$SERVER_NAME" --anonymous -y "$DB_NAME" list_latest_locations_for_retreat 1)"

if [[ "$spacetime_rows" != *"${participant_id}"* ]]; then
  echo "Spacetime mirror missing participant ${participant_id}: ${spacetime_rows}" >&2
  exit 1
fi

echo "[dualwrite] participant_locations rows (Laravel source of truth): ${laravel_source_count}"
echo "[dualwrite] Spacetime mirrored rows: ${spacetime_rows}"
echo "[dualwrite] PASS"

echo "Laravel log: $SERVER_LOG"
if [[ -n "$SPACETIME_LOG" ]]; then
  echo "Spacetime log: $SPACETIME_LOG"
fi
