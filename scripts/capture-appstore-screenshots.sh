#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

DEVICE_CLASS="${1:-iphone-6.9}"
LOCALE="${2:-en-US}"
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

mkdir -p "$OUT_DIR"

echo "Output directory: $OUT_DIR"
echo ""
echo "Before continuing:"
echo "1) Boot your target simulator (6.9\" iPhone class recommended)."
echo "2) Open Calvary Caravan in the simulator."
echo "3) Navigate to each screen when prompted."
echo ""

for shot in "${shots[@]}"; do
  read -r -p "Prepare screen for ${shot}. Press Enter to capture... " _
  target="$OUT_DIR/${shot}.png"
  xcrun simctl io booted screenshot "$target"
  echo "Saved: $target"
done

echo ""
echo "Captured ${#shots[@]} screenshots."
echo "Review files in: $OUT_DIR"
