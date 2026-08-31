/**
 * Sunrise/sunset colour score.
 *
 * The weights are uncalibrated, so this pins behaviour rather than accuracy:
 * it asserts the ordering that the formula is *supposed* to express, and
 * prints the numbers so they can be argued with.
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

const sky = (low, mid, high, rain = 0, vis = 20000) =>
  ({ cloud_low: low, cloud_mid: mid, cloud_high: high, rain_chance: rain, visibility: vis });
const mean = (s) => Math.round((S.scoreLightHour(s, false) + S.scoreLightHour(s, true)) / 2);

const CASES = [
  ['Cloudless blue', sky(0, 0, 0)],
  ['Thin high cirrus', sky(0, 0, 30)],
  ['Good cirrus deck', sky(0, 0, 55)],
  ['Cirrus + mid cloud', sky(0, 40, 55)],
  ['High + mid, clear horizon', sky(5, 35, 60)],
  ['Overcast cirrostratus', sky(0, 10, 95)],
  ['Mid deck only', sky(0, 70, 0)],
  ['Cirrus, 40% low cloud', sky(40, 30, 60)],
  ['Cirrus, 65% low cloud', sky(65, 30, 60)],
  ['Full low stratus', sky(95, 20, 0, 60, 4000)],
  ['Rain, everything grey', sky(85, 80, 40, 90, 3000)],
];

console.log('\nScores by sky (mean of the event hour and the glow hour):');
const scores = {};
for (const [name, s] of CASES) {
  scores[name] = mean(s);
  console.log('    ' + name.padEnd(28) + String(scores[name]).padStart(3) + '  ' + S.getScoreLabel(scores[name]));
}

console.log('\nOrdering the formula is meant to express:');
assert('a cloudless sky is unremarkable, not excellent', scores['Cloudless blue'] < 60);
assert('high cloud beats no cloud', scores['Good cirrus deck'] > scores['Cloudless blue']);
assert('high cloud beats mid cloud alone', scores['Good cirrus deck'] > scores['Mid deck only']);
assert('low cloud gates the bonus: 40% halves a good deck',
  scores['Cirrus, 40% low cloud'] < scores['Good cirrus deck'] - 20);
assert('65% low cloud makes the same deck poor', scores['Cirrus, 65% low cloud'] < 40);
assert('full stratus with rain is near zero', scores['Full low stratus'] < 10);
assert('nothing saturates at 100', Math.max(...Object.values(scores)) <= 90);
assert('the glow hour counts high cloud for more',
  S.scoreLightHour(sky(0, 0, 55), true) > S.scoreLightHour(sky(0, 0, 55), false));
assert('but only when there is high cloud to light',
  S.scoreLightHour(sky(0, 0, 0), true) === S.scoreLightHour(sky(0, 0, 0), false));

console.log('\nWindow selection and missing data:');
const hourly = [];
for (let h = 17; h <= 23; h++) {
  hourly.push(Object.assign({ time: '2026-08-31T' + String(h).padStart(2, '0') + ':00' }, sky(5, 35, 60)));
}
const day = { date: '2026-08-31', twilight: { sunrise: '06:49', sunset: '20:27' } };
assert('scores a sunset from the hours present', null !== S.sunriseSunsetRange(hourly, day, 'sunset'));
assert('returns null when the event hour is absent', null === S.sunriseSunsetRange(hourly, day, 'sunrise'));
assert('returns null without daily data', null === S.sunriseSunsetRange(hourly, null, 'sunset'));
assert('returns null with no hourly data', null === S.sunriseSunsetRange([], day, 'sunset'));
assert('returns null without twilight times', null === S.sunriseSunsetRange(hourly, { date: '2026-08-31' }, 'sunset'));

console.log('\n' + passed + ' passed, ' + failed + ' failed');
