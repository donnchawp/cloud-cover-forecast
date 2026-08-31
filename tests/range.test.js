/**
 * Dual-source score ranges.
 *
 * The range is min/max of two per-source means, not the mean of per-hour
 * min/max. Those give different answers whenever the sources disagree in
 * opposite directions across the two sampled hours, and the fixture below is
 * built so they do.
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

const DATE = '2026-07-01';

/** 24 hours of one sky, with optional met_no overlays keyed by hour. */
function buildHours(sky, metByHour = {}) {
  const hours = [];
  for (let h = 0; h < 24; h++) {
    const hour = {
      time: `${DATE}T${String(h).padStart(2, '0')}:00`,
      cloud_low: sky.low, cloud_mid: sky.mid, cloud_high: sky.high,
      cloud_total: 90, rain_chance: 0, visibility: 20000,
    };
    if (metByHour[h]) hour.met_no = metByHour[h];
    hours.push(hour);
  }
  return hours;
}

const day = { date: DATE, twilight: { sunset: '21:00', sunrise: '05:15' } };

console.log('\nSingle source, no Met.no data:');
const soloRange = S.sunriseSunsetRange(buildHours({ low: 40, mid: 91, high: 59 }), day, 'sunset');
assert('returns sources: 1', 1 === soloRange.sources);
assert('low equals high', soloRange.low === soloRange.high);
assert('bandScore returns the low', S.bandScore(soloRange) === soloRange.low);

console.log('\nBoth sources, disagreeing:');
// Open-Meteo sees heavy low cloud; Met.no sees a clear horizon.
const bothRange = S.sunriseSunsetRange(
  buildHours({ low: 40, mid: 91, high: 59 }, {
    21: { low: 8, mid: 70, high: 61, offset_hours: 0 },
    22: { low: 8, mid: 70, high: 61, offset_hours: 0 },
  }), day, 'sunset');
assert('returns sources: 2', 2 === bothRange.sources);
assert('the range has width', bothRange.high > bothRange.low);
assert('Open-Meteo is the pessimistic end here', bothRange.low === soloRange.low);
console.log(`    range: ${bothRange.low}-${bothRange.high}`);

console.log('\nPer-source averaging, not per-hour:');
// Each source is clear for one of the two sampled hours and clouded for the
// other, so both means land in the middle and the range is narrow. Per-hour
// min/max would take the clear hour's high and the clouded hour's low and
// produce a wide one.
const swapped = buildHours({ low: 0, mid: 30, high: 60 }, {
  21: { low: 90, mid: 30, high: 60, offset_hours: 0 },
  22: { low: 0, mid: 30, high: 60, offset_hours: 0 },
});
swapped[22].cloud_low = 90;
const mixed = S.sunriseSunsetRange(swapped, day, 'sunset');
assert('opposite disagreements average out to a narrow range', (mixed.high - mixed.low) < 10);
console.log(`    mixed: ${mixed.low}-${mixed.high}`);

console.log('\nPartial Met.no coverage:');
const partial = S.sunriseSunsetRange(
  buildHours({ low: 40, mid: 91, high: 59 }, {
    21: { low: 8, mid: 70, high: 61, offset_hours: 0 },
  }), day, 'sunset');
assert('one of two sampled hours is not a comparison', 1 === partial.sources);
assert('and collapses to the Open-Meteo score', partial.low === soloRange.low);

console.log('\nNull layers fall back per layer:');
const nulled = S.sunriseSunsetRange(
  buildHours({ low: 40, mid: 91, high: 59 }, {
    21: { low: 8, mid: null, high: null, offset_hours: 0 },
    22: { low: 8, mid: null, high: null, offset_hours: 0 },
  }), day, 'sunset');
assert('a null layer does not read as zero cloud', 2 === nulled.sources);
assert('the range still opens on the low-cloud difference', nulled.high > nulled.low);

console.log('\nAgreement collapses to a point:');
const agreed = S.sunriseSunsetRange(
  buildHours({ low: 40, mid: 91, high: 59 }, {
    21: { low: 40, mid: 91, high: 59, offset_hours: 0 },
    22: { low: 40, mid: 91, high: 59, offset_hours: 0 },
  }), day, 'sunset');
assert('identical readings give zero width', agreed.high === agreed.low);
assert('but still report two sources', 2 === agreed.sources);

console.log('\nMissing data:');
assert('no twilight time returns null',
  null === S.sunriseSunsetRange(buildHours({ low: 0, mid: 0, high: 0 }), { date: DATE, twilight: {} }, 'sunset'));
assert('an empty hourly array returns null',
  null === S.sunriseSunsetRange([], day, 'sunset'));
assert('bandScore of null is null', null === S.bandScore(null));

console.log('\nPHP/JS window coupling:');
// includes/class-api.php populates eventIndex-1 .. eventIndex+2. If the JS
// sampling window is widened past that, the extra hours silently carry no
// met_no key and every card degrades to single-source without an error.
assert('every sampled offset falls inside the PHP window',
  Math.min(...S.MET_NO_SAMPLE_OFFSETS) >= -1 && Math.max(...S.MET_NO_SAMPLE_OFFSETS) <= 2);

console.log(`\n${passed} passed, ${failed} failed`);
