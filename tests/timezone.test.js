/**
 * Timezone conversion.
 *
 * These functions must not depend on the timezone the viewer's browser is in.
 * Reading a forecast for somewhere else is the normal case. The old code was
 * wrong by exactly the viewer's own UTC offset, which is zero on UTC — so it
 * looked correct in a British winter and under a default CI container, and was
 * an hour out all summer in Ireland.
 *
 * Run under several TZ values; tests/run.sh does that automatically.
 */
'use strict';
const path = require('path');
global.window = global;
require(path.resolve(__dirname, '..', 'assets', 'js', 'forecast-scoring.js'));
const S = global.ForecastScoring;

let passed = 0, failed = 0;
const assert = (name, ok) => {
  console.log((ok ? '  PASS  ' : '  FAIL  ') + name);
  if (ok) { passed++; } else { failed++; process.exitCode = 1; }
};

console.log('\nRunning under TZ=' + (process.env.TZ || '(system default)'));

console.log('\nWall clock to instant:');
const CASES = [
  ['Durrus sunrise, IST (+1)', '2026-08-31', '06:49', 'Europe/Dublin', Date.UTC(2026, 7, 31, 5, 49)],
  ['Durrus, GMT (winter, +0)', '2026-01-15', '08:39', 'Europe/Dublin', Date.UTC(2026, 0, 15, 8, 39)],
  ['New York, EDT (-4)', '2026-08-31', '06:20', 'America/New_York', Date.UTC(2026, 7, 31, 10, 20)],
  ['Auckland, NZST (+12)', '2026-08-31', '07:00', 'Pacific/Auckland', Date.UTC(2026, 7, 30, 19, 0)],
  ['Auckland, NZDT (+13)', '2026-01-15', '06:18', 'Pacific/Auckland', Date.UTC(2026, 0, 14, 17, 18)],
  ['Kathmandu (+5:45)', '2026-08-31', '06:00', 'Asia/Kathmandu', Date.UTC(2026, 7, 31, 0, 15)],
];
for (const [label, date, time, zone, want] of CASES) {
  const got = S.parseTimeToTimestamp(date, time, zone);
  assert(label + ' -> ' + new Date(want).toISOString().slice(11, 16) + 'Z',
    got === want);
}

console.log('\nAcross the DST boundary (Europe/Dublin, 2026-10-25 02:00):');
assert('the hour before is still IST (+1)',
  S.parseTimeToTimestamp('2026-10-25', '00:30', 'Europe/Dublin') === Date.UTC(2026, 9, 24, 23, 30));
assert('the hour after is GMT (+0)',
  S.parseTimeToTimestamp('2026-10-25', '03:30', 'Europe/Dublin') === Date.UTC(2026, 9, 25, 3, 30));

console.log('\nHourly stamps from the API:');
assert('parses a local stamp with no offset',
  S.parseHourTimestamp('2026-08-31T20:00', 'Europe/Dublin') === Date.UTC(2026, 7, 31, 19, 0));
assert('rejects rubbish', null === S.parseHourTimestamp('nonsense', 'Europe/Dublin'));
assert('rejects a non-string', null === S.parseHourTimestamp(undefined, 'Europe/Dublin'));

console.log('\nThe reported bug — 05:28 local, sunrise 06:49:');
const nowMs = Date.UTC(2026, 7, 31, 4, 28); // 05:28 IST
const sunriseMs = S.parseTimeToTimestamp('2026-08-31', '06:49', 'Europe/Dublin');
const minutes = Math.round((sunriseMs - nowMs) / 60000);
console.log('    minutes until sunrise: ' + minutes);
assert('is 81 minutes, not 21', 81 === minutes);
assert('reads as roughly an hour away',
  'in 1 hour' === new Intl.RelativeTimeFormat('en', { numeric: 'auto' }).format(Math.round(minutes / 60), 'hour'));

console.log('\nCurrent-hour matching:');
const now = S.nowInTimezone('Europe/Dublin');
assert('returns a YYYY-MM-DD date', /^\d{4}-\d{2}-\d{2}$/.test(now.date));
assert('returns a two-digit hour 00-23', /^([01]\d|2[0-3])$/.test(now.hour));
const hourly = [];
for (let h = 0; h < 24; h++) {
  hourly.push({ time: now.date + 'T' + String(h).padStart(2, '0') + ':00' });
}
assert('finds the current hour in the location\'s own stamps',
  S.findHourIndex(hourly, now.date, now.hour + ':00') === Number(now.hour));

console.log('\n' + passed + ' passed, ' + failed + ' failed');
