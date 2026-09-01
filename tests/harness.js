/**
 * Minimal DOM harness for the PWA.
 *
 * The app is a plain IIFE that talks to document/window directly, so these
 * stubs are enough to run the real thing under node and assert on what it
 * renders. No dependencies, no build step, matching the rest of the project.
 */

'use strict';

const path = require('path');

const ASSETS = path.resolve(__dirname, '..', 'assets', 'js');

/** Build a stub element. escapeHtml() round-trips textContent through
 *  innerHTML, so the stub has to actually escape or every string renders
 *  empty — which looks exactly like a broken template. */
function stubElement() {
  let text = '';
  return {
    set textContent(v) { text = String(v); },
    get textContent() { return text; },
    get innerHTML() {
      return text.replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    },
    set innerHTML(v) { text = v; },
    addEventListener() {}, querySelector() { return null; },
    querySelectorAll() { return []; },
    classList: { contains() { return false; }, add() {}, remove() {} },
    style: {}, setAttribute() {}, remove() {},
  };
}

/**
 * Seven days of hourly weather, one sky per day.
 *
 * @param {Object} options - timezone, skies, twilight overrides.
 * @returns {Object} A forecast payload shaped like the PWA endpoint's.
 */
function buildForecast(options = {}) {
  const timezone = options.timezone || 'Europe/Dublin';
  const skies = options.skies || [
    { low: 0, mid: 0, high: 0 },     // cloudless
    { low: 0, mid: 35, high: 60 },   // textbook
    { low: 40, mid: 30, high: 60 },  // low cloud on the horizon
    { low: 0, mid: 0, high: 30 },    // thin cirrus
    { low: 95, mid: 20, high: 0 },   // stratus
    { low: 0, mid: 10, high: 95 },   // cirrostratus
    { low: 65, mid: 30, high: 60 },  // heavy low cloud
  ];

  const isoDate = (d) => new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(d);

  // Durrus, 2026-08-31, as computed by class-api.php.
  const TWILIGHT = {
    sunrise: '06:49', sunset: '20:27', civil_dawn: '06:14', civil_dusk: '21:02',
    blue_hour_dawn_start: '06:14', blue_hour_dawn_end: '06:27',
    golden_hour_dawn_start: '06:27', golden_hour_dawn_end: '07:33',
    golden_hour_dusk_start: '19:42', golden_hour_dusk_end: '20:49',
    blue_hour_dusk_start: '20:49', blue_hour_dusk_end: '21:02',
    astronomical_dawn: '04:42', astronomical_dusk: '22:33',
  };

  const daily = [];
  const hourly = [];
  for (let d = 0; d < skies.length; d++) {
    const date = isoDate(new Date(Date.now() + (d * 86400000)));
    const sky = skies[d];
    for (let h = 0; h < 24; h++) {
      hourly.push({
        time: date + 'T' + String(h).padStart(2, '0') + ':00',
        cloud_low: sky.low, cloud_mid: sky.mid, cloud_high: sky.high,
        cloud_total: Math.max(sky.low, sky.mid, sky.high),
        rain_chance: options.rainChance || 0, visibility: 20000, wind_speed: 8,
        temperature: 14, is_day: (h > 6 && h < 20) ? 1 : 0,
      });
      if (options.metNoSkies && options.metNoSkies[d]) {
        const m = options.metNoSkies[d];
        hourly[hourly.length - 1].met_no = {
          low: m.low, mid: m.mid, high: m.high,
          total: Math.max(m.low, m.mid, m.high), offset_hours: 0,
        };
      }
    }
    daily.push({
      date,
      sunrise: date + 'T' + TWILIGHT.sunrise,
      sunset: date + 'T' + TWILIGHT.sunset,
      twilight: Object.assign({}, TWILIGHT, options.twilight || {}),
    });
  }

  return {
    location: { lat: 51.6236, lon: -9.5236, timezone, timezone_abbr: 'IST' },
    hourly, daily, moon: {},
    met_no_available: options.metNoAvailable !== false,
  };
}

const STRINGS = {
  appTitle: 'Cloud Cover Forecast', hours: 'Hours', outlook: 'Outlook', day: 'Day',
  changeLocation: 'Change location', useMyLocation: 'Use my location',
  today: 'Today', tomorrow: 'Tomorrow', sunrise: 'Sunrise', sunset: 'Sunset',
  scoreExcellent: 'Excellent', scoreGood: 'Good', scoreFair: 'Fair', scorePoor: 'Poor',
  past: 'already passed', error: 'Error', locations: 'Locations', close: 'Close',
  firstLight: 'First Light', blueHour: 'Blue Hour', goldenHour: 'Golden Hour',
  daytime: 'Daytime', lastLight: 'Last Light',
  previousDay: 'Previous day', nextDay: 'Next day',
  noHomeLocation: 'No home location set', addFirstLocation: 'Add one',
  searchLocation: 'Search', loading: 'Loading...', share: 'Share',
  export: 'Export', import: 'Import', noLocations: 'No saved locations',
  setAsHome: 'Set as Home', delete: 'Delete', edit: 'Edit',
  cloudBySource: 'Cloud by source', sourceOpenMeteo: 'Open-Meteo', sourceMetNo: 'Met.no',
  secondSourceUnavailable: 'Second forecast source unavailable',
  oneSource: 'one source', twoSources: 'two sources',
  scoreRange: '%1$s to %2$s percent',
  low: 'Low', mid: 'Mid', high: 'High',
  bandsDiffer: 'The two sources divide the sky at different altitudes, so only the high row is compared in the score.',
};

/**
 * Install the stubs and load the app.
 *
 * @param {Object} options - search (URL query), forecast, locations.
 * @returns {Object} Test context.
 */
function install(options = {}) {
  const ctx = {
    rendered: '',
    listeners: {},
    tabs: {},
    forecast: options.forecast || buildForecast(),
    passed: 0,
    failed: 0,
  };

  const home = { id: 1, name: 'Durrus', admin1: 'Cork', lat: 51.6236, lon: -9.5236, isHome: true };
  let saved = options.locations || [home];

  const appEl = {
    set innerHTML(v) { ctx.rendered = v; },
    get innerHTML() { return ctx.rendered; },
    addEventListener(type, fn) { ctx.listeners[type] = fn; },
    querySelector() { return null; },
    querySelectorAll(selector) {
      if ('.tab-btn' !== selector) return [];
      return ['hours', 'outlook', 'day'].map((tab) => ({
        dataset: { tab },
        addEventListener(_event, fn) { ctx.tabs[tab] = fn; },
      }));
    },
  };

  global.window = global;
  global.document = {
    getElementById: (id) => ('app' === id ? appEl : null),
    querySelector: () => null,
    createElement: stubElement,
    body: { appendChild() {}, removeChild() {} },
    documentElement: {
      setAttribute() {}, style: { setProperty() {} },
      classList: { remove() {}, add() {}, contains() { return false; } },
    },
    addEventListener() {},
  };
  global.localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
  global.navigator = { onLine: true, userAgent: 'node', serviceWorker: {}, geolocation: {} };
  global.location = {
    search: options.search || '',
    pathname: '/forecast-app/',
    href: 'https://example.test/forecast-app/',
  };
  global.history = { replaceState() {} };
  global.requestAnimationFrame = (fn) => fn();
  global.matchMedia = () => ({ matches: false, addEventListener() {}, addListener() {} });
  global.addEventListener = () => {};
  global.fetch = async () => ({
    ok: true, json: async () => ({ success: true, data: ctx.forecast }),
  });

  global.ForecastStorage = {
    async openDatabase() {}, async cleanExpiredCache() {},
    async getLocations() { return saved.slice(); },
    async getHomeLocation() { return saved.find((l) => l.isHome) || null; },
    async getCachedForecast() { return null; }, async cacheForecast() {},
    async deleteLocation(id) { saved = saved.filter((l) => l.id !== id); },
  };
  global.CCF_CONFIG = {
    ajaxUrl: '/ajax', pluginUrl: '/plugin/',
    strings: Object.assign({}, STRINGS, options.strings || {}),
  };

  // Drop the module cache so a test file can install more than once. Without
  // this the second install() sets fresh globals but never re-runs the app's
  // IIFE, so it keeps rendering into the *first* install's element and every
  // assertion silently reads stale markup.
  for (const file of ['forecast-scoring.js', 'forecast-app.js']) {
    delete require.cache[require.resolve(path.join(ASSETS, file))];
  }

  require(path.join(ASSETS, 'forecast-scoring.js'));
  require(path.join(ASSETS, 'forecast-app.js'));

  /** Fire a synthetic click through the app's delegated handler. */
  ctx.click = (dataset, classes = []) => {
    const button = { dataset, classList: { contains: (c) => classes.includes(c) } };
    return ctx.listeners.click({
      target: { closest: () => button, classList: { contains: () => false } },
    });
  };

  ctx.assert = (name, condition) => {
    console.log((condition ? '  PASS  ' : '  FAIL  ') + name);
    if (condition) { ctx.passed++; } else { ctx.failed++; process.exitCode = 1; }
  };

  ctx.section = (name) => console.log('\n' + name);

  /** Phase list rows as [label, time] pairs. */
  ctx.phaseRows = () => [...ctx.rendered.matchAll(
    /<span class="phase-label">([^<]+)<\/span>\s*<span class="phase-time">([^<]*)/g
  )].map((m) => [m[1], m[2].trim()]);

  return ctx;
}

module.exports = { install, buildForecast, STRINGS };
