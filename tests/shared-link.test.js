/** A shared ?lat=&lon= link selects that location rather than home. */
'use strict';
const t = require('./harness').install({
  search: '?lat=51.4527&lon=-9.8189&loc=Mizen%20Head&region=Cork',
});

setTimeout(() => {
  t.section('Shared link');
  t.assert('the shared location is selected', t.rendered.includes('Mizen Head'));
  t.assert('home is not what is shown', !t.rendered.includes('Durrus, Cork'));
  t.assert('lands on the Hours view', /class="tab-btn active" data-tab="hours"/.test(t.rendered));
  t.assert('the picker does not open over it', !t.rendered.includes('location-picker-overlay'));

  t.tabs.outlook();
  t.assert('works in the Outlook view', 7 === (t.rendered.match(/<li class="outlook-row">/g) || []).length);
  t.tabs.day();
  t.assert('works in the Day view', t.rendered.includes('phase-list'));

  console.log('\n' + t.passed + ' passed, ' + t.failed + ' failed');
}, 50);
