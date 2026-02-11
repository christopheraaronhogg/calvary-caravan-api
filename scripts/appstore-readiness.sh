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
    fail "${key} file not found (${value})"
  fi
}

header "Calvary Caravan App Store readiness"

header "Metadata docs"
check_file "docs/app-store/README.md" "Readiness kit overview"
check_file "docs/app-store/metadata.en-US.md" "Metadata draft"
check_file "docs/app-store/review-info.en-US.md" "App Review notes draft"
check_file "docs/app-store/screenshot-shotlist.md" "Screenshot shot list"
check_file "docs/app-store/submission-checklist.csv" "Submission checklist CSV"

header "Public URLs"
check_url "https://calvarycaravan.on-forge.com/privacy" "Privacy policy URL"
check_url "https://calvarycaravan.on-forge.com/support" "Support URL"

header "iOS AppIcon requirements"
ICON_PATH="nativephp/ios/NativePHP/Assets.xcassets/AppIcon.appiconset/icon.png"
check_file "$ICON_PATH" "iOS app icon"

if [[ -f "$ICON_PATH" ]]; then
  width=$(sips -g pixelWidth "$ICON_PATH" 2>/dev/null | awk '/pixelWidth/{print $2}')
  height=$(sips -g pixelHeight "$ICON_PATH" 2>/dev/null | awk '/pixelHeight/{print $2}')
  alpha=$(sips -g hasAlpha "$ICON_PATH" 2>/dev/null | awk '/hasAlpha/{print tolower($2)}')

  if [[ "$width" == "1024" && "$height" == "1024" ]]; then
    pass "App icon is 1024x1024"
  else
    fail "App icon must be 1024x1024 (current: ${width}x${height})"
  fi

  if [[ "$alpha" == "no" ]]; then
    pass "App icon has no alpha channel"
  else
    fail "App icon still has alpha channel"
  fi
fi

header "Screenshot assets"
SCREEN_DIR="artifacts/app-store/ios/iphone-6.9/en-US"
if [[ -d "$SCREEN_DIR" ]]; then
  count=$(find "$SCREEN_DIR" -type f \( -name '*.png' -o -name '*.jpg' -o -name '*.jpeg' \) | wc -l | tr -d ' ')
  if [[ "$count" -ge 1 ]]; then
    pass "At least one screenshot present (${count}) in ${SCREEN_DIR}"
    if [[ "$count" -lt 5 ]]; then
      warn "Only ${count} screenshot(s) found; recommended 5 for first submission"
    fi
  else
    fail "No screenshots found in ${SCREEN_DIR}"
  fi
else
  fail "Screenshot directory missing (${SCREEN_DIR})"
fi

header "iOS signing and App Store upload env"
check_env_key IOS_TEAM_ID required
check_env_key IOS_DISTRIBUTION_CERTIFICATE_PASSWORD required
check_env_key APP_STORE_API_KEY_ID required
check_env_key APP_STORE_API_ISSUER_ID required
check_env_file_path IOS_DISTRIBUTION_CERTIFICATE_PATH required
check_env_file_path IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH required
check_env_file_path APP_STORE_API_KEY_PATH required

header "Apple keychain identities"
identity_count=$(security find-identity -v -p codesigning 2>/dev/null | awk '/valid identities found/{print $1; exit}' || echo "0")
if [[ -n "$identity_count" && "$identity_count" =~ ^[0-9]+$ && "$identity_count" -gt 0 ]]; then
  pass "$identity_count valid code-signing identity(ies) present"
else
  fail "No valid Apple code-signing identities found"
fi

header "Account-holder manual steps"
warn "Apple Developer Program payment/renewal must be completed by account holder"
warn "Any pending legal agreements in App Store Connect must be accepted by account holder"
warn "Final 'Submit for Review' click remains manual in App Store Connect"

printf "\nSummary: %b%s failure(s)%b, %b%s warning(s)%b\n" "$RED" "$fail_count" "$NC" "$YELLOW" "$warn_count" "$NC"

if [[ "$fail_count" -gt 0 ]]; then
  exit 1
fi

exit 0
