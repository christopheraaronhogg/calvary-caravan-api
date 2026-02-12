#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

HOST="127.0.0.1"
PORT="${1:-8091}"
BASE_URL="http://${HOST}:${PORT}"
APP_KEY_VALUE="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
SERVER_LOG="$(mktemp -t caravan-web-health.XXXXXX.log)"

RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

fail_count=0
warn_count=0

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

check_http_code() {
  local path="$1"
  local expected="$2"
  local label="$3"

  local code
  code="$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}${path}" || true)"

  if [[ "$code" == "$expected" ]]; then
    pass "${label} (${path} => HTTP ${code})"
  else
    fail "${label} (${path} => HTTP ${code}, expected ${expected})"
  fi
}

check_http_contains() {
  local path="$1"
  local needle="$2"
  local label="$3"

  if curl -s "${BASE_URL}${path}" | grep -q "$needle"; then
    pass "${label}"
  else
    fail "${label}"
  fi
}

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

header "Laravel mobile web path health"

header "Route wiring"
if APP_KEY="$APP_KEY_VALUE" php artisan route:list --path=mobile | grep -Fq 'mobile/{path?}'; then
  pass "Laravel route list includes /mobile/{path?} fallback"
else
  fail "Laravel route list missing /mobile/{path?} fallback"
fi

header "HTTP checks"
APP_KEY="$APP_KEY_VALUE" php artisan serve --host="$HOST" --port="$PORT" >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!

for _ in {1..30}; do
  if curl -s -o /dev/null "${BASE_URL}/mobile"; then
    break
  fi
  sleep 0.5
done

if ! curl -s -o /dev/null "${BASE_URL}/mobile"; then
  fail "Laravel server did not come up at ${BASE_URL}"
  warn "Server log: ${SERVER_LOG}"
else
  pass "Laravel server started at ${BASE_URL}"
fi

check_http_code "/mobile" "200" "Mobile shell path responds"
check_http_code "/mobile/index.html" "200" "Mobile static index responds"
check_http_code "/mobile/_app/version.json" "200" "Mobile asset manifest is served"

mobile_deeplink_code="$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/mobile/retreat/map" || true)"
if [[ "$mobile_deeplink_code" == "200" ]]; then
  pass "Deep-link path falls back to mobile index (/mobile/retreat/map)"
  check_http_contains "/mobile/retreat/map" "<html" "Deep-link response renders HTML shell"
else
  index_php_code="$(curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/index.php/mobile/retreat/map" || true)"
  if [[ "$index_php_code" == "200" ]]; then
    warn "Built-in php server returned ${mobile_deeplink_code} for /mobile/retreat/map; fallback route works via /index.php/mobile/retreat/map"
    check_http_contains "/index.php/mobile/retreat/map" "<html" "Deep-link response renders HTML shell via Laravel route"
  else
    fail "Deep-link fallback failed (/mobile/retreat/map => ${mobile_deeplink_code}, /index.php/mobile/retreat/map => ${index_php_code})"
  fi
fi

printf "\nSummary: %b%s failure(s)%b, %b%s warning(s)%b\n" "$RED" "$fail_count" "$NC" "$YELLOW" "$warn_count" "$NC"
printf "Server log: %s\n" "$SERVER_LOG"

if [[ "$fail_count" -gt 0 ]]; then
  exit 1
fi

exit 0
