#!/usr/bin/env bash
# Run every test. No dependencies beyond node and php.
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

for f in tests/*.test.js; do
  echo
  echo "=== $f ==="
  node "$f" || status=1
done

echo
echo "=== tests/solar.test.php ==="
php tests/solar.test.php 2>/dev/null || status=1

echo
if [ "$status" -eq 0 ]; then echo "ALL TESTS PASSED"; else echo "FAILURES"; fi
exit "$status"
