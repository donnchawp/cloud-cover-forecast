#!/usr/bin/env bash
# Run every test. No dependencies beyond node and php.
#
# The JS tests run under two timezones. A whole class of bug here is invisible
# on UTC — the old wall-clock conversion was wrong by exactly the viewer's own
# offset, so it looked perfect in a default CI container and was an hour out
# in Ireland all summer.
set -u
cd "$(dirname "$0")/.."

status=0

echo "=== syntax ==="
for f in assets/js/forecast-app.js assets/js/forecast-scoring.js pwa/service-worker.js; do
  node --check "$f" || status=1
done
for f in includes/*.php templates/*.php cloud-cover-forecast.php; do
  php -l "$f" > /dev/null || status=1
done
echo "ok"

for tz in UTC Pacific/Auckland; do
  echo
  echo "############ TZ=$tz ############"
  for f in tests/*.test.js; do
    echo
    echo "=== $f ==="
    TZ="$tz" node "$f" || status=1
  done
done

for f in tests/*.test.php; do
  echo
  echo "=== $f ==="
  php "$f" 2>/dev/null || status=1
done

echo
if [ "$status" -eq 0 ]; then echo "ALL TESTS PASSED"; else echo "FAILURES"; fi
exit "$status"
