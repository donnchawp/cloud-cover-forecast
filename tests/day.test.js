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

  runDualSourceChecks(t);
}, 50);

/**
 * Dual-source ranges in the day view.
 *
 * The day view renders a hero for sunrise and one for sunset, so the
 * comparison table is rendered per hero rather than once per day: each
 * table sits directly under the range it explains.
 */
function runDualSourceChecks(t) {
  const { install, buildForecast } = require('./harness.js');
  const tick = () => new Promise((r) => setTimeout(r, 50));
  const OPEN_METEO_SKY = { low: 40, mid: 91, high: 59 };
  const MET_NO_SKY = { low: 8, mid: 70, high: 61 };
  const fill = (sky) => new Array(7).fill(sky);

  (async () => {
    const ranged = install({
      forecast: buildForecast({ skies: fill(OPEN_METEO_SKY), metNoSkies: fill(MET_NO_SKY) }),
    });
    await tick();
    ranged.tabs.outlook();
    ranged.click({ action: 'open-day', day: '0', event: 'sunset' });

    t.section('Day hero, sources disagree:');
    t.assert('draws a tail fill', ranged.rendered.includes('day-hero-meter-tail'));
    t.assert('the meter label is a range',
      /day-hero-meter-fill[^>]*><span>\d+&#8211;\d+<\/span>/.test(ranged.rendered));
    t.assert('the aria label names two sources', ranged.rendered.includes('two sources'));

    t.section('Cloud by source panel:');
    t.assert('the panel is rendered', ranged.rendered.includes('cloud-by-source'));
    t.assert('it names both sources',
      ranged.rendered.includes('Open-Meteo') && ranged.rendered.includes('Met.no'));
    t.assert('it shows the Open-Meteo low cloud reading', ranged.rendered.includes('>40%<'));
    t.assert('it shows the Met.no low cloud reading', ranged.rendered.includes('>8%<'));
    t.assert('the heading does not assert disagreement',
      ranged.rendered.includes('Cloud by source') && !ranged.rendered.includes('Sources disagree'));

    const agreed = install({
      forecast: buildForecast({ skies: fill(OPEN_METEO_SKY), metNoSkies: fill(OPEN_METEO_SKY) }),
    });
    await tick();
    agreed.tabs.outlook();
    agreed.click({ action: 'open-day', day: '0', event: 'sunset' });

    t.section('Day view, sources agree:');
    t.assert('draws no tail fill', !agreed.rendered.includes('day-hero-meter-tail'));
    t.assert('but still shows the comparison panel', agreed.rendered.includes('cloud-by-source'));

    const solo = install({
      forecast: buildForecast({ skies: fill(OPEN_METEO_SKY), metNoAvailable: false }),
    });
    await tick();
    solo.tabs.outlook();
    solo.click({ action: 'open-day', day: '0', event: 'sunset' });

    t.section('Day view, second source unavailable:');
    t.assert('draws no tail fill', !solo.rendered.includes('day-hero-meter-tail'));
    t.assert('shows no comparison panel', !solo.rendered.includes('cloud-by-source'));
    t.assert('the aria label says one source', solo.rendered.includes('one source'));

    console.log('\n' + t.passed + ' passed, ' + t.failed + ' failed');
  })();
}
