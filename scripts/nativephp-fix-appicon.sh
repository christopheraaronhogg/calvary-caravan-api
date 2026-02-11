#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

ICON_PATH="nativephp/ios/NativePHP/Assets.xcassets/AppIcon.appiconset/icon.png"

if [[ ! -f "$ICON_PATH" ]]; then
  echo "Icon not found at $ICON_PATH" >&2
  echo "Run: php artisan native:install --force" >&2
  exit 1
fi

if ! command -v ffmpeg >/dev/null 2>&1; then
  echo "ffmpeg is required to strip icon alpha channel." >&2
  exit 1
fi

tmp="${ICON_PATH%.png}.tmp-noalpha.png"
ffmpeg -y -i "$ICON_PATH" -vf format=rgb24 -frames:v 1 "$tmp" >/dev/null 2>&1
mv "$tmp" "$ICON_PATH"

alpha=$(sips -g hasAlpha "$ICON_PATH" 2>/dev/null | awk '/hasAlpha/{print tolower($2)}')
w=$(sips -g pixelWidth "$ICON_PATH" 2>/dev/null | awk '/pixelWidth/{print $2}')
h=$(sips -g pixelHeight "$ICON_PATH" 2>/dev/null | awk '/pixelHeight/{print $2}')

echo "Updated icon: $ICON_PATH"
echo "Dimensions: ${w}x${h}"
echo "Has alpha: ${alpha}"

if [[ "$alpha" != "no" ]]; then
  echo "Failed to remove alpha channel." >&2
  exit 1
fi
