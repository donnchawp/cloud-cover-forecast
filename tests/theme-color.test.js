/**
 * The browser theme-color must follow an explicit theme choice.
 *
 * Both meta tags are qualified by prefers-color-scheme, so left alone they
 * follow the system and ignore the toggle: dark on a light phone left the
 * status bar light while the app went dark.
 */
'use strict';
const path = require('path');

const metas = {
  light: { media: '(prefers-color-scheme: light)', content: '#f5f5f5',
    setAttribute(k, v) { this[k] = v; } },
  dark: { media: '(prefers-color-scheme: dark)', content: '#0f172a',
    setAttribute(k, v) { this[k] = v; } },
};
let theme = 'auto';
const store = { 'ccf-theme': 'auto' };

let rendered = '';
const listeners = {};
const appEl = {
  set innerHTML(v) { rendered = v; }, get innerHTML() { return rendered; },
  addEventListener(type, fn) { listeners[type] = fn; },
  querySelector() { return null; }, querySelectorAll() { return []; },
};
global.window = global;
global.document = {
  getElementById: (id) => ('app' === id ? appEl : null),
  querySelector: (sel) => {
    if (sel.includes('theme-color') && sel.includes('light')) return metas.light;
    if (sel.includes('theme-color') && sel.includes('dark')) return metas.dark;
    return null;
  },
  createElement: () => ({ set textContent(v) { this._t = v; }, get innerHTML() { return this._t || ''; } }),
  body: { appendChild() {}, removeChild() {} },
  documentElement: { classList: { add() {}, remove() {} }, style: { setProperty() {} }, setAttribute() {} },
  addEventListener() {},
};
global.localStorage = {
  getItem: (k) => (k in store ? store[k] : null),
  setItem: (k, v) => { store[k] = v; }, removeItem() {},
};
global.navigator = { onLine: true, userAgent: 'node', serviceWorker: {} };
global.location = { search: '', pathname: '/forecast-app/' };
global.history = { replaceState() {} };
global.requestAnimationFrame = (fn) => fn();
global.matchMedia = () => ({ matches: false, addEventListener() {}, addListener() {} });
global.addEventListener = () => {};
global.ForecastStorage = {
  async openDatabase() {}, async getLocations() { return []; },
  async getHomeLocation() { return null; }, async cleanExpiredCache() {},
};
global.CCF_CONFIG = { ajaxUrl: '/ajax', strings: { error: 'Error', retry: 'Retry', appTitle: 'App' } };

require(path.resolve(__dirname, '..', 'assets', 'js', 'forecast-scoring.js'));
require(path.resolve(__dirname, '..', 'assets', 'js', 'forecast-app.js'));

let passed = 0, failed = 0;
const assert = (name, ok) => {
  console.log((ok ? '  PASS  ' : '  FAIL  ') + name);
  if (ok) { passed++; } else { failed++; process.exitCode = 1; }
};
const toggle = () => listeners.click({
  target: {
    closest: () => ({ dataset: { action: 'toggle-theme' }, classList: { contains: () => false } }),
    classList: { contains: () => false },
  },
});

setTimeout(() => {
  console.log('\nStarting on auto:');
  assert('light tag keeps the light colour', '#f5f5f5' === metas.light.content);
  assert('dark tag keeps the dark colour', '#0f172a' === metas.dark.content);

  // toggleTheme cycles auto -> light -> dark -> auto.
  const seen = [];
  for (let i = 0; i < 3; i++) {
    toggle();
    seen.push({ theme: store['ccf-theme'], light: metas.light.content, dark: metas.dark.content });
  }

  console.log('\nAfter each toggle:');
  for (const s of seen) {
    console.log('    theme=' + String(s.theme).padEnd(6) + ' light-tag=' + s.light + '  dark-tag=' + s.dark);
  }

  const explicit = seen.filter((s) => 'auto' !== s.theme);
  assert('an explicit choice points both tags at one colour',
    explicit.length > 0 && explicit.every((s) => s.light === s.dark));
  assert('choosing dark gives the dark colour',
    explicit.every((s) => 'dark' !== s.theme || '#0f172a' === s.light));
  assert('choosing light gives the light colour',
    explicit.every((s) => 'light' !== s.theme || '#f5f5f5' === s.light));

  const auto = seen.filter((s) => 'auto' === s.theme);
  assert('returning to auto restores the split pair',
    auto.every((s) => '#f5f5f5' === s.light && '#0f172a' === s.dark));

  console.log('\n' + passed + ' passed, ' + failed + ' failed');
}, 50);
