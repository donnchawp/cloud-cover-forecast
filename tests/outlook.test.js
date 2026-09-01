/** Outlook view: seven day rows, score rings, past events. */
'use strict';
const t = require('./harness').install();

setTimeout(() => {
  t.tabs.outlook();

  t.section('Outlook view');
  const rows = t.rendered.match(/<li class="outlook-row">[^]*?<\/li>/g) || [];
  t.assert('one row per forecast day (7)', 7 === rows.length);
  t.assert('shows Today and Tomorrow', t.rendered.includes('>Today<') && t.rendered.includes('>Tomorrow<'));

  // Match opening tags, not the class substring: outlook-card-band and
  // outlook-card-time share the prefix.
  const score = t.rendered.match(/<button class="outlook-card /g) || [];
  const past = t.rendered.match(/<div class="outlook-card is-past">/g) || [];
  const empty = t.rendered.match(/<div class="outlook-card is-empty/g) || [];
  t.assert('two cards per row (14)', 14 === score.length + past.length + empty.length);

  const pastBlocks = t.rendered.match(/<div class="outlook-card is-past">[^]*?<\/div>/g) || [];
  t.assert('past events show a clock and no ring',
    pastBlocks.every((b) => b.includes('&#128339;') && !b.includes('score-ring')));

  t.assert('rings use the circumference-100 circle', t.rendered.includes('r="15.915"'));
  t.assert('the value is text, not only an arc', /score-ring-text[^>]*>\d+%</.test(t.rendered));
  t.assert('cards are buttons carrying a label',
    /<button class="outlook-card [^"]*" data-action="open-day"[^>]*aria-label="[^"]+"/.test(t.rendered));

  const bands = new Set([...t.rendered.matchAll(/outlook-card-band">([^<]+)</g)].map((m) => m[1]));
  t.assert('different skies produce different bands (' + [...bands].join(', ') + ')', bands.size >= 3);

  t.section('Tapping a card');
  t.click({ action: 'open-day', day: '3', event: 'sunset' });
  t.assert('opens the Day view', /class="tab-btn active" data-tab="day"/.test(t.rendered));

  runDualSourceChecks(t);
}, 50);

/**
 * Dual-source ranges.
 *
 * Each scenario needs its own app instance, so install() is called again.
 * The harness drops the module cache so the IIFE genuinely re-runs; a probe
 * confirmed two installs render different scores from different fixtures.
 */
function runDualSourceChecks(t) {
  const { install, buildForecast } = require('./harness.js');
  const tick = () => new Promise((r) => setTimeout(r, 50));
  // Kilkenny, 2026-09-01 sunset, real API values. Low cloud is 1% so the
  // horizon gate is open and the high-cloud disagreement can move the score.
  const OPEN_METEO_SKY = { low: 1, mid: 100, high: 100 };
  const MET_NO_SKY = { low: 5, mid: 100, high: 2 };
  const fill = (sky) => new Array(7).fill(sky);

  (async () => {
    // Open-Meteo sees low cloud everywhere; Met.no sees a clear horizon.
    const ranged = install({
      forecast: buildForecast({
        skies: fill(OPEN_METEO_SKY),
        metNoSkies: fill(MET_NO_SKY),
      }),
    });
    await tick();
    ranged.tabs.outlook();

    t.section('Outlook card, sources disagree:');
    t.assert('draws a tail arc', ranged.rendered.includes('score-ring-tail'));
    t.assert('the ring is marked as carrying a range', ranged.rendered.includes('score-ring has-range'));
    t.assert('the text is a range, not a percentage',
      /<text class="score-ring-text"[^>]*>\d+&#8211;\d+<\/text>/.test(ranged.rendered));
    t.assert('the track is not dashed', !ranged.rendered.includes('is-single-source'));
    t.assert('the aria label names two sources', ranged.rendered.includes('two sources'));
    t.assert('the aria label spells out the range', /\d+ to \d+ percent/.test(ranged.rendered));
    t.assert('no global notice when the source is available',
      !ranged.rendered.includes('outlook-notice'));

    // Both sources identical: the range must collapse.
    const agreed = install({
      forecast: buildForecast({
        skies: fill(OPEN_METEO_SKY),
        metNoSkies: fill(OPEN_METEO_SKY),
      }),
    });
    await tick();
    agreed.tabs.outlook();

    t.section('Outlook card, sources agree:');
    t.assert('draws no tail arc', !agreed.rendered.includes('score-ring-tail'));
    t.assert('the text is a plain percentage',
      /<text class="score-ring-text"[^>]*>\d+%<\/text>/.test(agreed.rendered));
    t.assert('the track is not dashed', !agreed.rendered.includes('is-single-source'));
    t.assert('but the aria label still says two sources', agreed.rendered.includes('two sources'));

    // Met.no unavailable.
    const solo = install({
      forecast: buildForecast({ skies: fill(OPEN_METEO_SKY), metNoAvailable: false }),
    });
    await tick();
    solo.tabs.outlook();

    t.section('Outlook, second source unavailable:');
    t.assert('the track is dashed', solo.rendered.includes('is-single-source'));
    t.assert('the aria label says one source', solo.rendered.includes('one source'));
    t.assert('and never says two', !solo.rendered.includes('two sources'));
    t.assert('a global notice appears', solo.rendered.includes('outlook-notice'));
    t.assert('the notice explains why',
      solo.rendered.includes('Second forecast source unavailable'));

    // Open-Meteo sees 85% low cloud, so the horizon gate is shut and Met.no's
    // high cloud cannot move the score however much it disagrees. The card
    // must not read as corroborated.
    const closed = install({
      forecast: buildForecast({
        skies: fill({ low: 85, mid: 100, high: 100 }),
        metNoSkies: fill({ low: 5, mid: 100, high: 2 }),
      }),
    });
    await tick();
    closed.tabs.outlook();

    t.section('Outlook, horizon gate shut:');
    t.assert('the track is marked horizon-closed', closed.rendered.includes('is-horizon-closed'));
    t.assert('and not marked single-source, which it is not',
      !closed.rendered.includes('is-single-source'));
    t.assert('draws no tail arc, because the range has no width',
      !closed.rendered.includes('score-ring-tail'));
    // The aria label is an attribute, so it ends at a quote, not a tag. An
    // earlier version of this assertion matched against '<' and could never
    // fail. "two sources" alone is the claim that must not stand here.
    t.assert('the aria label never leaves "two sources" unqualified',
      !/two sources(?!,\s*horizon closed)/.test(closed.rendered));
    t.assert('it says the horizon was closed',
      closed.rendered.includes('two sources, horizon closed'));

    // A payload cached before this feature: no met_no keys, no flag at all.
    const legacyForecast = buildForecast({ skies: fill(OPEN_METEO_SKY) });
    delete legacyForecast.met_no_available;
    const legacy = install({ forecast: legacyForecast });
    await tick();
    legacy.tabs.outlook();

    t.section('Outlook, cached payload from before this feature:');
    t.assert('renders without throwing', legacy.rendered.includes('outlook-card'));
    t.assert('shows a plain percentage',
      /<text class="score-ring-text"[^>]*>\d+%<\/text>/.test(legacy.rendered));
    t.assert('shows no global notice', !legacy.rendered.includes('outlook-notice'));
    t.assert('but marks the cards single-source', legacy.rendered.includes('is-single-source'));

    console.log('\n' + t.passed + ' passed, ' + t.failed + ' failed');
  })();
}
