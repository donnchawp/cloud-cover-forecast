/** App shell: view tabs, header location switcher, location picker. */
'use strict';
const t = require('./harness').install({
  locations: [
    { id: 1, name: 'Durrus', admin1: 'Cork', lat: 51.6236, lon: -9.5236, isHome: true },
    { id: 2, name: 'Mizen Head', admin1: 'Cork', lat: 51.4527, lon: -9.8189 },
  ],
});

setTimeout(async () => {
  t.section('Shell after init');
  t.assert('header shows the selected location', t.rendered.includes('Durrus, Cork'));
  t.assert('bottom bar has all three view tabs',
    /data-tab="hours"/.test(t.rendered) && /data-tab="outlook"/.test(t.rendered) && /data-tab="day"/.test(t.rendered));
  t.assert('nav sits after main, so it renders at the bottom',
    t.rendered.indexOf('</main>') < t.rendered.indexOf('app-tabs'));
  t.assert('old location tabs are gone',
    !/data-tab="home"/.test(t.rendered) && !/data-tab="current"/.test(t.rendered) && !/data-tab="locations"/.test(t.rendered));
  t.assert('Hours is the default view', /class="tab-btn active" data-tab="hours"/.test(t.rendered));
  t.assert('picker starts closed', !t.rendered.includes('location-picker-overlay'));

  t.section('Location picker');
  await t.click({ action: 'open-location-picker' });
  t.assert('opens as a labelled dialog', /role="dialog"[^>]*aria-modal="true"/.test(t.rendered));
  t.assert('offers Use my location', t.rendered.includes('Use my location'));
  t.assert('lists saved locations', t.rendered.includes('Mizen Head'));

  t.section('Selecting and deleting');
  await t.click({ action: 'view-location', id: '2' });
  t.assert('selection follows the tap', t.rendered.includes('Mizen Head, Cork'));
  t.assert('picker closes on select', !t.rendered.includes('location-picker-overlay'));

  // NOT a regression test for the homeLocation bug. Reintroducing the old
  // assignment still passes this, because loadSavedLocations() refreshes
  // homeLocation from storage before the fallback reads it. Verified by
  // negative control. What it covers is that deleting the location on screen
  // leaves the app pointing somewhere valid.
  await t.click({ action: 'delete-location', id: '2' });
  t.assert('deleting the on-screen location falls back to home',
    t.rendered.includes('Durrus, Cork') && !t.rendered.includes('Mizen Head, Cork'));

  await t.click({ action: 'open-location-picker' });
  const items = t.rendered.match(/<li class="location-item[^]*?<\/li>/g) || [];
  t.assert('Durrus remains the only location, still marked home',
    1 === items.length && items[0].includes('is-home') && items[0].includes('Durrus'));

  console.log('\n' + t.passed + ' passed, ' + t.failed + ' failed');
}, 50);
