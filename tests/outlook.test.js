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

  console.log('\n' + t.passed + ' passed, ' + t.failed + ' failed');
}, 50);
