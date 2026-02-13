#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

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

check_file() {
  local path="$1"
  local label="$2"

  if [[ -f "$path" ]]; then
    pass "$label ($path)"
  else
    fail "$label missing ($path)"
  fi
}

check_contains() {
  local path="$1"
  local pattern="$2"
  local label="$3"

  if [[ ! -f "$path" ]]; then
    fail "$label (file missing: $path)"
    return
  fi

  if grep -qE "$pattern" "$path"; then
    pass "$label"
  else
    fail "$label"
  fi
}

check_url() {
  local url="$1"
  local label="$2"
  local code

  code=$(curl -L -s -o /dev/null -w "%{http_code}" "$url" || true)
  if [[ "$code" == "200" ]]; then
    pass "$label ($url)"
  else
    fail "$label unreachable ($url) [HTTP $code]"
  fi
}

env_value() {
  local key="$1"
  grep -E "^${key}=" .env 2>/dev/null | head -n 1 | cut -d'=' -f2- || true
}

check_env_file_readable() {
  if [[ ! -f .env ]]; then
    fail ".env file is missing"
    warn "Create one with: cp .env.example .env"
    return 1
  fi

  if ! head -c 1 .env >/dev/null 2>&1; then
    fail ".env exists but is unreadable (possible iCloud dataless/deadlock)"
    warn "Recommended workaround: copy repo to a non-iCloud path, then restore .env and rerun checks"
    return 1
  fi

  pass ".env file is readable"
  return 0
}

check_env_key() {
  local key="$1"
  local mode="${2:-required}"
  local value
  value="$(env_value "$key")"

  if [[ -z "$value" ]]; then
    if [[ "$mode" == "required" ]]; then
      fail ".env missing ${key}"
    else
      warn ".env missing ${key}"
    fi
    return
  fi

  pass ".env ${key} is set"
}

check_env_file_path() {
  local key="$1"
  local mode="${2:-required}"
  local value
  value="$(env_value "$key")"

  if [[ -z "$value" ]]; then
    if [[ "$mode" == "required" ]]; then
      fail ".env missing ${key}"
    else
      warn ".env missing ${key}"
    fi
    return
  fi

  if [[ -f "$value" ]]; then
    pass "${key} file exists (${value})"
  else
    if [[ "$mode" == "required" ]]; then
      fail "${key} file not found (${value})"
    else
      warn "${key} file not found (${value})"
    fi
  fi
}

header "Calvary Caravan Google Play readiness"

header "Google Play docs"
check_file "docs/google-play/README.md" "Google Play readiness overview"
check_file "docs/google-play/metadata.en-US.md" "Google Play metadata draft"
check_file "docs/google-play/review-notes.en-US.md" "Google Play reviewer notes"
check_file "docs/google-play/data-safety-and-permissions.md" "Data safety + permission rationale"
check_file "docs/google-play/submission-checklist.csv" "Google Play submission checklist"

header "Public policy/support URLs"
check_url "https://calvarycaravan.on-forge.com/privacy" "Privacy policy URL"
check_url "https://calvarycaravan.on-forge.com/support" "Support URL"
check_url "https://calvarycaravan.on-forge.com/account-deletion" "Account deletion URL"

header "Phone identity + deletion implementation wiring"
check_contains "app/Http/Requests/Api/JoinRetreatRequest.php" "phone_number" "Join request validates phone_number"
check_contains "app/Http/Controllers/Api/V1/RetreatController.php" "phone_e164" "Join flow persists phone identity"
check_contains "frontend/src/routes/+page.svelte" "phone_number" "Frontend join payload includes phone_number"
check_contains "routes/api.php" "/account" "API route includes account deletion endpoint"
check_contains "app/Http/Controllers/Api/V1/RetreatController.php" "function deleteAccount" "Controller handles account deletion"
check_contains "resources/views/account-deletion.blade.php" "DELETE /api/v1/retreat/account" "Public account deletion instructions include API path"

header "Permission UX + rationale coverage"
check_contains "frontend/src/routes/+page.svelte" "Allow location while using" "In-app copy explains foreground location permission"
check_contains "config/nativephp.php" "push_notifications" "NativePHP push notification permission is configured"
check_contains "docs/google-play/data-safety-and-permissions.md" "foreground" "Data safety doc explicitly states foreground location use"

header "Android build + Play upload environment"
if check_env_file_readable; then
  check_env_key NATIVEPHP_APP_ID required
  check_env_key NATIVEPHP_APP_VERSION required
  check_env_key NATIVEPHP_APP_VERSION_CODE required
  check_env_file_path ANDROID_KEYSTORE_FILE required
  check_env_key ANDROID_KEYSTORE_PASSWORD required
  check_env_key ANDROID_KEY_ALIAS required
  check_env_key ANDROID_KEY_PASSWORD required

  header "Play upload automation env (optional but recommended)"
  check_env_file_path GOOGLE_SERVICE_KEY optional
else
  warn "Skipping key-level Android env checks because .env is unreadable"
fi

header "Owner-only console actions"
warn "Google Play Console developer account must be active and paid"
warn "Play listing policy declarations (Data safety, app access, content rating) must be completed in Console"
warn "Production release creation and final Submit to Google Play is owner-only"

printf "\nSummary: %b%s failure(s)%b, %b%s warning(s)%b\n" "$RED" "$fail_count" "$NC" "$YELLOW" "$warn_count" "$NC"

if [[ "$fail_count" -gt 0 ]]; then
  exit 1
fi

exit 0
