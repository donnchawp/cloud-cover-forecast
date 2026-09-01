/**
 * Dual-source score ranges.
 *
 * Only Met.no's HIGH cloud feeds the second score. The providers define their
 * layers over different altitudes -- Open-Meteo low is 0-3 km against Met.no's
 * 0-2 km -- so the low and mid figures are not the same quantity and cannot be
 * substituted into a formula tuned on Open-Meteo's. See the comment on
 * sunriseSunsetRange().
 *
 * The range is min/max of two per-source means, not the mean of per-hour
 * min/max. Those give different answers whenever the sources disagree in
 * opposite directions across the two sampled hours, and one fixture below is
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

// Kilkenny, 2026-09-01 sunset, as both APIs actually reported it. Chosen
// because its low cloud is 1%, so the horizon gate is open and high cloud can
// actually move the score. See the gate test below for why that matters.
const OPEN_METEO = { low: 1, mid: 100, high: 100 };
const MET_NO = { low: 5, mid: 100, high: 2 };

/** 24 hours of one sky, with optional met_no overlays keyed by hour. */
function buildHours(sky, metByHour = {}) {
  const hours = [];
  for (let h = 0; h < 24; h++) {
    const hour = {
      time: `${DATE}T${String(h).padStart(2, '0')}:00`,
      cloud_low: sky.low, cloud_mid: sky.mid, cloud_high: sky.high,
      cloud_total: 100, rain_chance: 0, visibility: 20000,
    };
    if (metByHour[h]) hour.met_no = Object.assign({}, metByHour[h]);
    hours.push(hour);
  }
  return hours;
}

const day = { date: DATE, twilight: { sunset: '21:00', sunrise: '05:15' } };
/** The two hours sunriseSunsetRange samples for a sunset at 21:00. */
const bothHours = (met) => ({ 21: met, 22: met });

console.log('\nSingle source, no Met.no data:');
const soloRange = S.sunriseSunsetRange(buildHours(OPEN_METEO), day, 'sunset');
assert('returns sources: 1', 1 === soloRange.sources);
assert('low equals high', soloRange.low === soloRange.high);
assert('bandScore returns the low', S.bandScore(soloRange) === soloRange.low);

console.log('\nBoth sources, disagreeing on high cloud:');
const bothRange = S.sunriseSunsetRange(
  buildHours(OPEN_METEO, bothHours(MET_NO)), day, 'sunset');
assert('returns sources: 2', 2 === bothRange.sources);
assert('the range has width', bothRange.high > bothRange.low);
assert('the single-source score is one end of the range',
  bothRange.low === soloRange.low || bothRange.high === soloRange.low);
assert('Met.no seeing no cirrus is the pessimistic end', bothRange.high === soloRange.low);
console.log(`    range: ${bothRange.low}-${bothRange.high}`);

console.log('\nMet.no low and mid cloud are deliberately ignored:');
// Met.no disagrees violently on low and mid but matches on high. Because the
// bands are not the same quantity, that must produce no range at all. If this
// starts failing, someone has reintroduced the layer substitution.
const highAgrees = S.sunriseSunsetRange(
  buildHours(OPEN_METEO, bothHours({ low: 90, mid: 5, high: 100 })), day, 'sunset');
assert('an 89-point low-cloud gap opens no range', highAgrees.high === highAgrees.low);
assert('and it is still counted as two sources', 2 === highAgrees.sources);
assert('the score matches the Open-Meteo-only score', highAgrees.low === soloRange.low);

console.log('\nPer-source averaging, not per-hour:');
// Each source sees cirrus for one of the two sampled hours and none for the
// other, so both means land in the middle and the range is narrow. Per-hour
// min/max would take the clear hour's high and the cloudy hour's low.
const swapped = buildHours(OPEN_METEO, {
  21: { low: 5, mid: 100, high: 0 },
  22: { low: 5, mid: 100, high: 100 },
});
swapped[22].cloud_high = 0;
const mixed = S.sunriseSunsetRange(swapped, day, 'sunset');
assert('opposite disagreements average out to a narrow range', (mixed.high - mixed.low) < 10);
console.log(`    mixed: ${mixed.low}-${mixed.high}`);

console.log('\nPartial Met.no coverage:');
const partial = S.sunriseSunsetRange(
  buildHours(OPEN_METEO, { 21: MET_NO }), day, 'sunset');
assert('one of two sampled hours is not a comparison', 1 === partial.sources);
assert('and collapses to the Open-Meteo score', partial.low === soloRange.low);

console.log('\nA missing high reading is not a comparison:');
const noHigh = S.sunriseSunsetRange(
  buildHours(OPEN_METEO, bothHours({ low: 5, mid: 100, high: null })), day, 'sunset');
assert('a null high layer yields a single source', 1 === noHigh.sources);
assert('and does not read as zero cirrus', noHigh.low === soloRange.low);

console.log('\nAgreement collapses to a point:');
const agreed = S.sunriseSunsetRange(
  buildHours(OPEN_METEO, bothHours({ low: 5, mid: 100, high: 100 })), day, 'sunset');
assert('identical readings give zero width', agreed.high === agreed.low);
assert('but still report two sources', 2 === agreed.sources);

console.log('\nA shut horizon gate collapses the range:');
// clarity = max(0, 1 - cloudLow / 70), so at 70% low cloud or more the canvas
// term is multiplied by zero and high cloud cannot move the score at all.
// Both sources then land on the same number however much they disagree about
// cirrus. This is a property of scoreLightHour(), not of the comparison.
const shut = S.sunriseSunsetRange(
  buildHours({ low: 73, mid: 100, high: 100 }, bothHours(MET_NO)), day, 'sunset');
assert('at 73% low cloud the range has no width', shut.high === shut.low);
assert('even though the sources differ by 98 points of cirrus', 2 === shut.sources);
const open = S.sunriseSunsetRange(
  buildHours({ low: 20, mid: 100, high: 100 }, bothHours(MET_NO)), day, 'sunset');
assert('the same disagreement opens a range at 20% low cloud', open.high > open.low);
console.log(`    gate shut: ${shut.low}-${shut.high}   gate open: ${open.low}-${open.high}`);

console.log('\nThe shut gate is reported, not just suffered:');
// A collapsed range means "the two sources agree". Under a shut gate it also
// means "the second source could not act", which is a different claim: the
// numbers match because Met.no's high cloud was multiplied by zero, not
// because Met.no corroborated anything. The views cannot tell those apart
// without being told.
assert('a shut gate is flagged', true === shut.horizonClosed);
assert('an open gate is not', false === open.horizonClosed);
assert('nor is a plain agreement with the gate open', false === agreed.horizonClosed);

// One source is already signalled by sources:1 and drawn as a dashed track.
// Flagging it here too would put two marks on one card for one fact.
assert('a single source is not flagged', false === soloRange.horizonClosed);

// The flag claims the comparison was inert. If it is ever set on a range with
// width, that claim is false and the note the day view prints is a lie.
assert('the flag is never set on a range that has width',
  !shut.horizonClosed || shut.low === shut.high);

// clarity is max(0, 1 - cloudLow / 70), so at exactly 70 it is zero and the
// gate is already shut. Nothing else in this file sits on the boundary, so
// >= vs > in the check was previously untestable.
const onBoundary = S.sunriseSunsetRange(
  buildHours({ low: 70, mid: 100, high: 100 }, bothHours(MET_NO)), day, 'sunset');
assert('exactly at the threshold the gate is already shut', true === onBoundary.horizonClosed);
assert('and the range really does have no width there',
  onBoundary.high === onBoundary.low);
const justUnder = S.sunriseSunsetRange(
  buildHours({ low: 69, mid: 100, high: 100 }, bothHours(MET_NO)), day, 'sunset');
assert('one point under, it is not', false === justUnder.horizonClosed);

// Both sampled hours must be shut. If either one is open, Met.no's high cloud
// can still move that hour's score, so the comparison did run.
const halfShut = [];
for (let h = 0; h < 24; h++) {
  const low = 21 === h ? 73 : 20;
  halfShut.push({
    time: `${DATE}T${String(h).padStart(2, '0')}:00`,
    cloud_low: low, cloud_mid: 100, cloud_high: 100,
    cloud_total: 100, rain_chance: 0, visibility: 20000,
    met_no: Object.assign({}, MET_NO),
  });
}
const halfShutRange = S.sunriseSunsetRange(halfShut, day, 'sunset');
assert('one open hour out of two is not a shut gate', false === halfShutRange.horizonClosed);
assert('and that open hour still opens a range', halfShutRange.high > halfShutRange.low);

console.log('\nMissing data:');
assert('no twilight time returns null',
  null === S.sunriseSunsetRange(buildHours(OPEN_METEO), { date: DATE, twilight: {} }, 'sunset'));
assert('an empty hourly array returns null',
  null === S.sunriseSunsetRange([], day, 'sunset'));
assert('bandScore of null is null', null === S.bandScore(null));

console.log(`\n${passed} passed, ${failed} failed`);
