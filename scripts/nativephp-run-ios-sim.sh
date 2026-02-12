#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

START_URL="${NATIVEPHP_START_URL:-/mobile/index.html}"
BUILD_PATH_DEFAULT="$HOME/.cache/calvary-caravan/native-build"
BUILD_PATH="${NATIVEPHP_BUILD_PATH:-$BUILD_PATH_DEFAULT}"
PREFERRED_DEVICE="${1:-}"

require_cmd() {
  local cmd="$1"
  local label="$2"

  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "Missing ${label} (${cmd})" >&2
    exit 1
  fi
}

pick_simulator_udid() {
  local preferred="$1"

  if [[ -n "$preferred" ]]; then
    if xcrun simctl list devices available | grep -q "$preferred"; then
      xcrun simctl list devices available | awk -v token="$preferred" '$0 ~ token {match($0, /\(([0-9A-F-]{36})\)/, m); if (m[1] != "") {print m[1]; exit}}'
      return
    fi

    echo "Requested simulator '${preferred}' not found among available devices." >&2
    exit 1
  fi

  local booted
  booted="$(xcrun simctl list devices booted available | awk '/\(Booted\)/ {match($0, /\(([0-9A-F-]{36})\)/, m); if (m[1] != "") {print m[1]; exit}}')"
  if [[ -n "$booted" ]]; then
    echo "$booted"
    return
  fi

  xcrun simctl list devices available | awk '/iPhone/ {match($0, /\(([0-9A-F-]{36})\)/, m); if (m[1] != "") {print m[1]; exit}}'
}

require_cmd php "PHP"
require_cmd xcrun "Xcode simulator tools"

if ! php artisan native:version >/dev/null 2>&1; then
  echo "NativePHP artisan commands are unavailable. Run ./scripts/nativephp-bootstrap.sh first." >&2
  exit 1
fi

SIMULATOR_UDID="$(pick_simulator_udid "$PREFERRED_DEVICE")"
if [[ -z "$SIMULATOR_UDID" ]]; then
  echo "No available iPhone simulator found." >&2
  exit 1
fi

mkdir -p "$BUILD_PATH"

echo "Using simulator: ${SIMULATOR_UDID}"
echo "Using build path: ${BUILD_PATH}"
echo "Using start URL: ${START_URL}"

# Ensure simulator is booted and responsive before build/run.
xcrun simctl boot "$SIMULATOR_UDID" >/dev/null 2>&1 || true
xcrun simctl bootstatus "$SIMULATOR_UDID" -b

# Reduce locale-related build variance.
xcrun simctl spawn "$SIMULATOR_UDID" defaults write -g AppleLocale en_US >/dev/null 2>&1 || true
xcrun simctl spawn "$SIMULATOR_UDID" defaults write -g AppleLanguages '(en)' >/dev/null 2>&1 || true

NATIVEPHP_BUILD_PATH="$BUILD_PATH" php artisan native:run ios "$SIMULATOR_UDID" \
  --build=debug \
  --start-url="$START_URL" \
  --no-tty
