#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODULE_DIR="$ROOT_DIR/spacetimedb-location/spacetimedb"

SERVER_NAME="${SPACETIME_SERVER:-local}"
LISTEN_ADDR="${SPACETIME_LISTEN_ADDR:-127.0.0.1:3000}"
DB_NAME="${1:-calvary-location-smoke-$(date +%Y%m%d%H%M%S)}"
ANON_FLAG="${SPACETIME_ANONYMOUS:-true}"
START_LOCAL_SERVER="${SPACETIME_SMOKE_START_LOCAL:-true}"

RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

fail_count=0
warn_count=0
started_server=0
server_pid=""
server_log=""

pass() {
  printf "%b✔%b %s\n" "$GREEN" "$NC" "$1"
}

fail() {
  printf "%b✖%b %s\n" "$RED" "$NC" "$1"
  fail_count=$((fail_count + 1))
}

warn() {
  printf "%b!%b %s\n" "$YELLOW" "$NC" "$1"
  warn_count=$((warn_count + 1))
}

header() {
  printf "\n%b%s%b\n" "$BLUE" "$1" "$NC"
}

cleanup() {
  if [[ "$started_server" -eq 1 && -n "$server_pid" ]]; then
    kill "$server_pid" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

require_cmd() {
  local cmd="$1"
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "Missing required command: $cmd" >&2
    exit 1
  fi
}

check_local_server() {
  spacetime list --server "$SERVER_NAME" >/dev/null 2>&1
}

start_server_if_needed() {
  if check_local_server; then
    pass "Spacetime server '$SERVER_NAME' already reachable"
    return
  fi

  if [[ "$START_LOCAL_SERVER" != "true" ]]; then
    fail "Spacetime server '$SERVER_NAME' is not reachable"
    warn "Start one manually or rerun with SPACETIME_SMOKE_START_LOCAL=true"
    return
  fi

  server_log="$(mktemp -t caravan-spacetime.XXXXXX.log)"
  spacetime start --in-memory --listen-addr "$LISTEN_ADDR" --non-interactive >"$server_log" 2>&1 &
  server_pid=$!
  started_server=1

  for _ in {1..30}; do
    if check_local_server; then
      pass "Started local Spacetime server at ${LISTEN_ADDR}"
      return
    fi
    sleep 0.5
  done

  fail "Unable to start local Spacetime server"
  warn "Server log: ${server_log}"
}

require_cmd spacetime
require_cmd npm

header "Spacetime smoke"
start_server_if_needed

if [[ "$fail_count" -gt 0 ]]; then
  printf "\nSummary: %b%s failure(s)%b, %b%s warning(s)%b\n" "$RED" "$fail_count" "$NC" "$YELLOW" "$warn_count" "$NC"
  exit 1
fi

header "Build sidecar module"
(
  cd "$MODULE_DIR"
  npm run build
)
pass "Sidecar module build succeeded"

header "Publish smoke database"
anon_args=()
if [[ "$ANON_FLAG" == "true" ]]; then
  anon_args+=(--anonymous)
fi

spacetime publish "$DB_NAME" --project-path "$MODULE_DIR" --server "$SERVER_NAME" "${anon_args[@]}" -y --delete-data=always >/dev/null
pass "Published database: ${DB_NAME}"

header "Reducer smoke"
spacetime call --server "$SERVER_NAME" "${anon_args[@]}" -y "$DB_NAME" upsert_location -- 1 1 35.1001 -90.2202 6.5 0 180 220 1739380200000 >/dev/null
pass "upsert_location reducer call succeeded"

rows="$(spacetime call --server "$SERVER_NAME" "${anon_args[@]}" -y "$DB_NAME" list_latest_locations_for_retreat 1)"
if [[ "$rows" == *"participant_id"* || "$rows" == *"1"* ]]; then
  pass "list_latest_locations_for_retreat returned rows"
else
  fail "list_latest_locations_for_retreat returned no rows"
fi

echo "$rows"

printf "\nSummary: %b%s failure(s)%b, %b%s warning(s)%b\n" "$RED" "$fail_count" "$NC" "$YELLOW" "$warn_count" "$NC"
if [[ -n "$server_log" ]]; then
  printf "Server log: %s\n" "$server_log"
fi

if [[ "$fail_count" -gt 0 ]]; then
  exit 1
fi

exit 0
