#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
OUT="$ROOT/docs/prototypes/task-4b0-connector-account/screenshots"
CHROME="${CHROME:-google-chrome}"
BASE_URL="${BASE_URL:-http://127.0.0.1:8765/index.html}"

mkdir -p "$OUT"

# Each shot uses an isolated Chrome profile so viewport/window-size changes
# cannot reuse a previous render cache from the same session.
shot() {
  local name="$1"
  local hash="$2"
  local width="$3"
  local height="$4"
  local chrome_user="/tmp/chrome-shot-${name}-$$"
  rm -rf "$chrome_user"

  if ! timeout 45 "$CHROME" --headless=new --no-sandbox --disable-dev-shm-usage --disable-gpu \
    --hide-scrollbars --force-device-scale-factor=1 \
    --user-data-dir="$chrome_user" --virtual-time-budget=10000 \
    --window-size="${width},${height}" \
    --screenshot="$OUT/$name" \
    "${BASE_URL}${hash}" >/dev/null 2>&1; then
    echo "FAILED capture: $name (${width}x${height})" >&2
    rm -rf "$chrome_user"
    exit 1
  fi

  rm -rf "$chrome_user"

  if [[ ! -f "$OUT/$name" ]]; then
    echo "FAILED missing file: $OUT/$name" >&2
    exit 1
  fi

  echo "wrote $OUT/$name (${width}x${height})"
}

verify_desktop_mobile_pairs() {
  local failed=0
  for pair in \
    "02-settings-desktop-1440.png:02-settings-mobile-375.png" \
    "03-check-error-auth-desktop-1440.png:03-check-error-auth-mobile-375.png" \
    "04-discovery-desktop-1440.png:04-discovery-mobile-375.png" \
    "06-history-desktop-1440.png:06-history-mobile-375.png"; do
    local desktop="${pair%%:*}"
    local mobile="${pair##*:}"
    local h1 h2
    h1=$(sha256sum "$OUT/$desktop" | awk '{print $1}')
    h2=$(sha256sum "$OUT/$mobile" | awk '{print $1}')
    if [[ "$h1" == "$h2" ]]; then
      echo "FAIL: $desktop and $mobile are byte-identical (not a real distinct capture)"
      failed=1
    else
      echo "OK: $desktop != $mobile"
    fi
  done

  if [[ "$failed" -ne 0 ]]; then
    exit 1
  fi

  echo "OK: all desktop/mobile screenshot pairs are genuinely distinct"
}

# Desktop 1440 — all six states
shot "01-connections-desktop-1440.png" "#connections" 1440 900
shot "02-settings-desktop-1440.png" "#settings" 1440 900
shot "03-check-error-auth-desktop-1440.png" "#check-auth" 1440 900
shot "03-check-error-forbidden-desktop-1440.png" "#check-forbidden" 1440 900
shot "04-discovery-desktop-1440.png" "#discovery" 1440 900
shot "05-diff-desktop-1440.png" "#diff" 1440 900
shot "06-history-desktop-1440.png" "#history" 1440 900

# Mobile 375 — fresh Chrome profile per file (see shot())
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

verify_desktop_mobile_pairs

echo "Done. $(ls -1 "$OUT" | wc -l) screenshots in $OUT"
