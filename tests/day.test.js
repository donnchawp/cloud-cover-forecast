/** Day view: pager, heroes, phase list, stale-cache fallback. */
'use strict';
const harness = require('./harness');
const t = harness.install();

setTimeout(() => {
  t.tabs.day();

  t.section('Day view');
  t.assert('pager shows Today', /day-pager-label">Today</.test(t.rendered));
  t.assert('prev disabled on the first day', /data-action="day-prev" disabled/.test(t.rendered));
  t.assert('next enabled on the first day', /data-action="day-next" (?!disabled)/.test(t.rendered));
  t.assert('both heroes render, sunrise above sunset',
    t.rendered.includes('>Sunrise<') && t.rendered.includes('>Sunset<')
    && t.rendered.indexOf('>Sunrise<') < t.rendered.indexOf('>Sunset<'));

  // Capture greedily to the next tag then trim; a lazy [^<]+? matches one char.
  const rows = t.phaseRows();
  console.log('\n  Phase list:');
  rows.forEach((r) => console.log('    ' + r[0].padEnd(14) + r[1]));

  t.assert('nine phases', 9 === rows.length);
  t.assert('in the reference app\'s order',
    'First Light|Blue Hour|Golden Hour|Sunrise|Daytime|Golden Hour|Sunset|Blue Hour|Last Light'
      === rows.map((r) => r[0]).join('|'));
  t.assert('golden hour is a range', rows[2][1].includes('–'));
  t.assert('sunrise matches the solar calculation (06:49)', '06:49' === rows[3][1]);
  t.assert('dusk golden hour starts at +6 degrees (19:42)', rows[5][1].startsWith('19:42'));

  t.section('Paging');
  t.click({ action: 'day-next' });
  t.assert('advances to Tomorrow', /day-pager-label">Tomorrow</.test(t.rendered));
  for (let i = 0; i < 10; i++) t.click({ action: 'day-next' });
  t.assert('stops at the last day', /data-action="day-next" disabled/.test(t.rendered));
  t.assert('never runs off the end', !t.rendered.includes('undefined'));
  for (let i = 0; i < 10; i++) t.click({ action: 'day-prev' });
  t.assert('and back to Today', /day-pager-label">Today</.test(t.rendered));

  t.section('Stale cache, before the solar fields existed');
  t.forecast.daily.forEach((d) => {
    ['blue_hour_dawn_start', 'blue_hour_dawn_end', 'golden_hour_dawn_start',
      'golden_hour_dawn_end', 'golden_hour_dusk_start', 'golden_hour_dusk_end',
      'blue_hour_dusk_start', 'blue_hour_dusk_end'].forEach((k) => delete d.twilight[k]);
  });
  t.click({ action: 'day-next' });
  t.click({ action: 'day-prev' });
  t.assert('still renders nine phases from the fixed offsets', 9 === t.phaseRows().length);
  t.assert('golden hour falls back to sunset-60 (19:27)', t.rendered.includes('19:27'));

  console.log('\n' + t.passed + ' passed, ' + t.failed + ' failed');
}, 50);
