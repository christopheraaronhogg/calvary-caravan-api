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

check_cmd() {
  local cmd="$1"
  local label="$2"
  local verify_cmd="${3:-}"

  if ! command -v "$cmd" >/dev/null 2>&1; then
    fail "$label is missing"
    return
  fi

  if [[ -n "$verify_cmd" ]]; then
    if eval "$verify_cmd" >/dev/null 2>&1; then
      pass "$label ($(command -v "$cmd"))"
    else
      fail "$label is installed but not functional"
    fi
    return
  fi

  pass "$label ($(command -v "$cmd"))"
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
  local required_value="${2:-true}"
  local value

  value="$(env_value "$key")"

  if [[ -z "$value" ]]; then
    if [[ "$required_value" == "required" ]]; then
      fail ".env missing ${key}"
    else
      warn ".env missing ${key}"
    fi
    return
  fi

  if [[ "$value" == "" ]]; then
    if [[ "$required_value" == "required" ]]; then
      fail ".env ${key} is empty"
    else
      warn ".env ${key} is empty"
    fi
    return
  fi

  pass ".env ${key} is set"
}

header "NativePHP preflight (Calvary Caravan API)"

header "Core tools"
check_cmd php "PHP" "php -v"
check_cmd composer "Composer" "composer --version"
check_cmd xcodebuild "Xcode" "xcodebuild -version"
check_cmd pod "CocoaPods" "pod --version"
check_cmd java "Java runtime" "java -version"
check_cmd keytool "Java keytool" "keytool -help"
check_cmd adb "Android platform tools (adb)" "adb version"

header "Android SDK"
if [[ -d "$HOME/Library/Android/sdk" ]]; then
  pass "Android SDK directory exists at $HOME/Library/Android/sdk"
else
  fail "Android SDK directory missing at $HOME/Library/Android/sdk"
fi

header "NativePHP install"
if php artisan native:version >/dev/null 2>&1; then
  version=$(php artisan native:version | tr -d '\r' || true)
  pass "$version"
else
  fail "NativePHP artisan commands are unavailable"
fi

header "Env keys (required for packaging)"
if check_env_file_readable; then
  check_env_key APP_KEY required
  check_env_key NATIVEPHP_APP_ID required
  check_env_key NATIVEPHP_APP_VERSION required
  check_env_key NATIVEPHP_APP_VERSION_CODE required
  check_env_key NATIVEPHP_START_URL required
  check_env_key IOS_TEAM_ID required
  check_env_key ANDROID_KEYSTORE_FILE required
  check_env_key ANDROID_KEYSTORE_PASSWORD required
  check_env_key ANDROID_KEY_ALIAS required
  check_env_key ANDROID_KEY_PASSWORD required

  header "Store-upload keys (recommended)"
  check_env_key APP_STORE_API_KEY_PATH optional
  check_env_key APP_STORE_API_KEY_ID optional
  check_env_key APP_STORE_API_ISSUER_ID optional
  check_env_key GOOGLE_SERVICE_KEY optional
else
  warn "Skipping key-level env checks because .env is unreadable"
fi

header "Apple code-signing identities"
identity_count=$(security find-identity -v -p codesigning 2>/dev/null | awk '/valid identities found/{print $1; exit}' || echo "0")
if [[ -n "$identity_count" && "$identity_count" =~ ^[0-9]+$ && "$identity_count" -gt 0 ]]; then
  pass "$identity_count valid code-signing identity(ies) in keychain"
else
  fail "No valid Apple code-signing identities found in keychain"
fi

printf "\nSummary: %b%s failure(s)%b, %b%s warning(s)%b\n" "$RED" "$fail_count" "$NC" "$YELLOW" "$warn_count" "$NC"

if [[ "$fail_count" -gt 0 ]]; then
  exit 1
fi

exit 0
