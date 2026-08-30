/**
 * A page cached before forecast-scoring.js existed loads forecast-app.js
 * without it. That must fail visibly rather than throwing and leaving the
 * loading spinner up for ever.
 */
'use strict';
const path = require('path');

let rendered = '';
const appEl = {
  set innerHTML(v) { rendered = v; },
  get innerHTML() { return rendered; },
  addEventListener() {}, querySelector() { return null; }, querySelectorAll() { return []; },
};
global.window = global;
global.document = {
  getElementById: (id) => ('app' === id ? appEl : null),
  querySelector: () => null,
  createElement: () => ({ set textContent(v) { this._t = v; }, get innerHTML() { return this._t || ''; } }),
  body: { appendChild() {}, removeChild() {} },
  documentElement: { classList: { add() {}, remove() {} }, style: { setProperty() {} }, setAttribute() {} },
  addEventListener() {},
};
global.localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
global.navigator = { onLine: true, userAgent: 'node', serviceWorker: {} };
global.location = { search: '', pathname: '/forecast-app/', reload() {} };
global.history = { replaceState() {} };
global.requestAnimationFrame = (fn) => fn();
global.addEventListener = () => {};
global.ForecastStorage = { async openDatabase() {}, async getLocations() { return []; } };
global.CCF_CONFIG = { ajaxUrl: '/ajax', strings: { error: 'Error', retry: 'Retry' } };

// Deliberately do NOT load forecast-scoring.js.
let threw = null;
try {
  require(path.resolve(__dirname, '..', 'assets', 'js', 'forecast-app.js'));
} catch (e) {
  threw = e;
}

let passed = 0, failed = 0;
const assert = (name, ok) => {
  console.log((ok ? '  PASS  ' : '  FAIL  ') + name);
  if (ok) { passed++; } else { failed++; process.exitCode = 1; }
};

console.log('\nStale shell without forecast-scoring.js:');
assert('the app does not throw', null === threw);
assert('it renders a message instead of a blank spinner', rendered.includes('Error'));
assert('it offers a way to reload', rendered.includes('location.reload'));

console.log('\n' + passed + ' passed, ' + failed + ' failed');
