#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PROTO="$ROOT/docs/prototypes/task-4b0-connector-account"
OUT="$PROTO/screenshots"
CHROME="${CHROME:-google-chrome}"
FILE_URL="file://$PROTO/index.html"

mkdir -p "$OUT"

shot() {
  local name="$1"
  local hash="$2"
  local width="$3"
  local height="$4"
  timeout 30 "$CHROME" --headless=new --no-sandbox --disable-dev-shm-usage --disable-gpu \
    --hide-scrollbars --user-data-dir="$CHROME_USER" --virtual-time-budget=8000 \
    --window-size="${width},${height}" \
    --screenshot="$OUT/$name" \
    "${BASE_URL}${hash}" >/dev/null 2>&1 || true
  if [[ -f "$OUT/$name" ]]; then
    echo "wrote $OUT/$name"
  else
    echo "FAILED $OUT/$name" >&2
    exit 1
  fi
}

CHROME_USER="/tmp/chrome-shots-$$"
BASE_URL="http://127.0.0.1:8765/index.html"

# Desktop 1440 — all six states
shot "01-connections-desktop-1440.png" "#connections" 1440 900
shot "02-settings-desktop-1440.png" "#settings" 1440 900
shot "03-check-error-auth-desktop-1440.png" "#check-auth" 1440 900
shot "03-check-error-forbidden-desktop-1440.png" "#check-forbidden" 1440 900
shot "04-discovery-desktop-1440.png" "#discovery" 1440 900
shot "05-diff-desktop-1440.png" "#diff" 1440 900
shot "06-history-desktop-1440.png" "#history" 1440 900

# Mobile 375
shot "02-settings-mobile-375.png" "#settings" 375 812
shot "03-check-error-auth-mobile-375.png" "#check-auth" 375 812
shot "04-discovery-mobile-375.png" "#discovery" 375 812
shot "06-history-mobile-375.png" "#history" 375 812

# Boundary toolbar
shot "04-discovery-boundary-767.png" "#discovery" 767 900
shot "04-discovery-boundary-768.png" "#discovery" 768 900

# Dark mode
shot "01-connections-dark-1440.png" "#connections-dark" 1440 900
shot "04-discovery-dark-1440.png" "#discovery-dark" 1440 900

echo "Done. $(ls -1 "$OUT" | wc -l) screenshots in $OUT"
