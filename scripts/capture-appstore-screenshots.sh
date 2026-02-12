#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

DEVICE_CLASS="${1:-iphone-6.9}"
LOCALE="${2:-en-US}"
MODE="${3:-interactive}"
DELAY_SECONDS="${SCREENSHOT_DELAY_SECONDS:-2}"
OUT_DIR="artifacts/app-store/ios/${DEVICE_CLASS}/${LOCALE}"

shots=(
  "01-join-retreat"
  "02-live-group-status"
  "03-group-chat"
  "04-location-sharing"
  "05-profile-and-settings"
)

if ! command -v xcrun >/dev/null 2>&1; then
  echo "xcrun not found. Install Xcode command line tools first." >&2
  exit 1
fi

if ! xcrun simctl list devices booted | grep -q "(Booted)"; then
  echo "No booted simulator detected. Boot one first, then rerun." >&2
  exit 1
fi

mkdir -p "$OUT_DIR"

echo "Output directory: $OUT_DIR"
echo ""
echo "Before continuing:"
echo "1) Boot your target simulator (6.9\" iPhone class recommended)."
echo "2) Open Calvary Caravan in the simulator."
echo "3) Navigate to each screen when prompted."
if [[ "$MODE" == "--auto" || "$MODE" == "auto" ]]; then
  echo "Auto mode enabled: captures every ${DELAY_SECONDS}s without prompts."
fi
echo ""

for shot in "${shots[@]}"; do
  if [[ "$MODE" == "--auto" || "$MODE" == "auto" ]]; then
    echo "Capturing ${shot} in ${DELAY_SECONDS}s..."
    sleep "$DELAY_SECONDS"
  else
    read -r -p "Prepare screen for ${shot}. Press Enter to capture... " _
  fi

  target="$OUT_DIR/${shot}.png"
  xcrun simctl io booted screenshot "$target"
  echo "Saved: $target"
done

echo ""
echo "Captured ${#shots[@]} screenshots."
echo "Review files in: $OUT_DIR"
