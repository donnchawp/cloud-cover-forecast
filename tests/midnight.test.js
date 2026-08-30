/**
 * Durrus in June: no astronomical dawn at all, and dusk after local midnight.
 * The solar sweep found 181 such location-days in a test year.
 */
'use strict';
const harness = require('./harness');
const t = harness.install({
  forecast: harness.buildForecast({
    twilight: {
      sunrise: '05:20', sunset: '21:57', civil_dawn: '04:32', civil_dusk: '22:46',
      blue_hour_dawn_start: '04:32', blue_hour_dawn_end: '04:55',
      golden_hour_dawn_start: '04:55', golden_hour_dawn_end: '06:14',
      golden_hour_dusk_start: '21:05', golden_hour_dusk_end: '22:22',
      blue_hour_dusk_start: '22:22', blue_hour_dusk_end: '22:46',
      astronomical_dawn: null, astronomical_dusk: '00:23',
    },
  }),
});

setTimeout(() => {
  t.tabs.day();
  const rows = t.phaseRows();

  t.section('Irish June: no astronomical dawn, dusk past midnight');
  rows.forEach((r) => console.log('    ' + r[0].padEnd(14) + r[1]));

  t.assert('the null First Light row is dropped, not blank',
    8 === rows.length && !rows.some((r) => 'First Light' === r[0]));
  t.assert('Last Light 00:23 is present',
    rows.some((r) => 'Last Light' === r[0] && '00:23' === r[1]));

  const afterLastLight = t.rendered.slice(t.rendered.lastIndexOf('Last Light'));
  t.assert('the after-midnight time is marked +1', afterLastLight.includes('phase-next-day'));

  const sunsetToLastLight = t.rendered.slice(t.rendered.indexOf('>Sunset<'), t.rendered.lastIndexOf('Last Light'));
  t.assert('same-day rows are not marked', !sunsetToLastLight.includes('phase-next-day'));

  t.assert('order stays logical rather than sorted by time',
    'Blue Hour|Golden Hour|Sunrise|Daytime|Golden Hour|Sunset|Blue Hour|Last Light'
      === rows.map((r) => r[0]).join('|'));

  console.log('\n' + t.passed + ' passed, ' + t.failed + ' failed');
}, 50);
