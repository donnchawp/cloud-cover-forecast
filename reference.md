# Cloud Cover Forecast - Codebase Reference

This document serves as the source of truth for the Cloud Cover Forecast WordPress plugin codebase. It indexes all source files with descriptions of their purpose and key functionality.

**Last Updated:** 2026-08-12

---

## Table of Contents

- [PHP Source Files](#php-source-files)
- [JavaScript Files](#javascript-files)
- [CSS Files](#css-files)
- [PWA Assets](#pwa-assets)
- [Templates](#templates)
- [Configuration Files](#configuration-files)
- [Class Relationships](#class-relationships)
- [Data Flow](#data-flow)
- [API Endpoints](#api-endpoints)
- [Constants Reference](#constants-reference)

---

## PHP Source Files

### Main Plugin File

| File | Description |
|------|-------------|
| `cloud-cover-forecast.php` | **Main plugin entry point.** Defines plugin constants (`CLOUD_COVER_FORECAST_VERSION`, `CLOUD_COVER_FORECAST_PLUGIN_DIR`, `CLOUD_COVER_FORECAST_PLUGIN_URL`). Contains `Cloud_Cover_Forecast_Plugin` class that initializes all components, manages settings, handles plugin activation/deactivation, and provides cache versioning for transients. |

### Includes Directory (`includes/`)

| File | Class | Description |
|------|-------|-------------|
| `class-admin.php` | `Cloud_Cover_Forecast_Admin` | **WordPress admin functionality.** Handles settings page registration, settings field rendering, Gutenberg block registration, AJAX geocoding endpoint (`ccf_geocode`), input sanitization, and PWA settings (path, noindex). Renders the admin settings page at `/wp-admin/options-general.php?page=cloud-cover-forecast-settings`. |
| `class-api.php` | `Cloud_Cover_Forecast_API` | **External API integration.** Fetches weather data from Open-Meteo and Met.no APIs, performs geocoding (forward and reverse), fetches moon data from IPGeolocation API. Implements rate limiting per service, response caching, coordinate validation, and twilight time calculations. Merges cloud cover data from multiple sources using worst-case values. |
| `class-assets.php` | `Cloud_Cover_Forecast_Assets` | **Asset management.** Enqueues frontend CSS (`forecast-block.css`), admin scripts (jQuery on settings page), and block editor assets. `register_tokens()` registers the shared `forecast-tokens.css` handle that frontend stylesheets depend on. Provides localized strings for both block editor scripts. |
| `class-autoloader.php` | `Cloud_Cover_Forecast_Autoloader` | **PSR-4-style autoloader.** Automatically loads plugin classes from `includes/` directory based on class name convention (`Cloud_Cover_Forecast_*` → `class-*.php`). |
| `class-location-search-form.php` | `Cloud_Cover_Forecast_Location_Search_Form` | **Reusable location search UI component.** Static class that renders a location search form with Open-Meteo geocoding. Supports two modes: `redirect` (adds query params) and `ajax` (fires custom event). Used by sunrise-sunset block. |
| `class-photography-renderer.php` | `Cloud_Cover_Forecast_Photography_Renderer` | **Photography-focused rendering and calculations.** Calculates astronomical twilight times, golden hour, blue hour, and Milky Way core rise times. Rates photography conditions for sunset, sunrise, astrophotography, and Milky Way shooting. Renders the photography-focused forecast widget with hourly cloud cover table, event markers, and photo condition descriptions. |
| `class-public-block.php` | `Cloud_Cover_Forecast_Public_Block` | **Public-facing location lookup block.** Registers `cloud-cover-forecast/public-lookup` Gutenberg block. Handles AJAX endpoints for public forecast lookup and geocoding with IP-based rate limiting (10 requests per 5 minutes). Renders search form and forecast results. |
| `class-pwa.php` | `Cloud_Cover_Forecast_PWA` | **Progressive Web App handler.** Registers URL rewrite rules for configurable PWA endpoint, serves dynamic manifest.json and service-worker.js, handles template rendering. Provides AJAX endpoints for extended forecast (`ccf_pwa_forecast`), geocoding (`ccf_pwa_geocode`), and reverse geocoding (`ccf_pwa_reverse_geocode`). |
| `class-shortcode.php` | `Cloud_Cover_Forecast_Shortcode` | **Shortcode handler.** Registers `[cloud_cover]` shortcode with support for `lat`, `lon`, `hours`, `label`, `show_chart`, and `location` attributes. Handles coordinate validation, location geocoding, cache management, and delegates rendering to photography renderer. |
| `class-sunrise-sunset-block.php` | `Cloud_Cover_Forecast_Sunrise_Sunset_Block` | **3-day sunrise/sunset forecast block.** Registers `cloud-cover-forecast/sunrise-sunset` Gutenberg block. Displays sunrise/sunset times with cloud cover analysis and shooting condition summaries for photographers. Supports URL-based location parameters and uses shared location search form. |

### Uninstall

| File | Description |
|------|-------------|
| `uninstall.php` | **Plugin cleanup script.** Removes all plugin data on uninstall: options (`cloud_cover_forecast_settings_v1`, `cloud_cover_forecast_cache_version`), all transients with plugin prefixes. Handles multisite cleanup. |

---

## JavaScript Files

### Root Directory

| File | Description |
|------|-------------|
| `block.js` | **Main Gutenberg block editor script.** Registers `cloud-cover-forecast/block` for the block editor. Provides location search, coordinate input fields, and block preview. Uses `wp.blocks`, `wp.element`, `wp.blockEditor`, `wp.components`. |
| `public-block.js` | **Public lookup block editor script.** Registers `cloud-cover-forecast/public-lookup` for the block editor. Provides block settings for title, placeholder, button text, and photography mode toggle. |
| `sunrise-sunset-block.js` | **Sunrise/sunset block editor script.** Registers `cloud-cover-forecast/sunrise-sunset` for the block editor. Provides location search in the editor sidebar. |

### Assets Directory (`assets/js/`)

| File | Description |
|------|-------------|
| `forecast-app.js` | **PWA main application logic.** Handles location management (saved locations, current location), tab navigation, forecast fetching and display, settings management (font size, preferences), and share functionality. |
| `forecast-scoring.js` | **PWA scoring and light phases.** Pure functions with no DOM or app-state access: time parsing, sunlight/golden/blue-hour classification, and photography scores. Exports `sunriseSunsetRange()` (returns `{low, high, sources}` scored against both forecast sources), `bandScore()` (the single place the "label the low score" rule lives) and `MET_NO_SAMPLE_OFFSETS`. Must load before `forecast-app.js`, which consumes it via `window.ForecastScoring`. |
| `forecast-storage.js` | **PWA storage utilities.** Manages IndexedDB and localStorage for offline data persistence. Handles saved locations, cached forecasts, and user preferences. |
| `public-block.js` | **Frontend public block script.** Handles user interactions for the public lookup block: location search submission, geocoding API calls, forecast fetching, result display, and error handling with rate limit feedback. |

### PWA Directory (`pwa/`)

| File | Description |
|------|-------------|
| `service-worker.js` | **PWA service worker.** Implements caching strategy for offline support. Caches static assets and API responses. Handles fetch events with network-first strategy for API calls and cache-first for static assets. |

---

## CSS Files

### Assets Directory (`assets/css/`)

| File | Description |
|------|-------------|
| `forecast-tokens.css` | **Shared design tokens.** Single source of truth for colour, shape and elevation across the shortcode, photography widget and public lookup block. Defines `--ccf-*` custom properties for light theme on `:root`, dark theme under `prefers-color-scheme: dark`, plus explicit `[data-ccf-theme]` overrides. Registered as handle `cloud-cover-forecast-tokens` and pulled in as a dependency by the two stylesheets below. |
| `forecast-block.css` | **Shortcode and block styles.** Card layout, tables, photography widget, event row tints, notices and skeleton states. Replaced the minified CSS string previously returned by `Assets::get_css()`. Consumes `forecast-tokens.css`. |
| `forecast-app.css` | **PWA application styles.** Styles for the forecast PWA including layout, typography, responsive design, dark mode support, and weather condition visualizations. Includes an accessibility section: `:focus-visible` rings on all interactive controls, 44px minimum touch targets, a `.visually-hidden` utility, and `prefers-reduced-motion` handling. |
| `public-block.css` | **Public block frontend styles.** Styles for the public location lookup block including search form, results display, loading states, and error messages. Consumes `forecast-tokens.css`; contains no hardcoded colours apart from the `prefers-contrast: high` block. |

---

## PWA Assets

### PWA Directory (`pwa/`)

| File | Description |
|------|-------------|
| `manifest.json` | **Web app manifest.** Defines PWA metadata: name, icons, start URL, display mode (standalone), theme colors. Icon paths are dynamically updated when served. |
| `icons/icon-192.svg` | **PWA icon (192x192).** SVG icon for PWA installation and home screen. |
| `icons/icon-512.svg` | **PWA icon (512x512).** Large SVG icon for PWA splash screens. |

---

## Templates

### Templates Directory (`templates/`)

| File | Description |
|------|-------------|
| `pwa-app.php` | **PWA HTML template.** Full HTML document for the PWA with meta tags, viewport settings, manifest link, CSS/JS includes, and noindex meta tag (if enabled). Provides initial app shell with loading state. |

---

## Configuration Files

| File | Description |
|------|-------------|
| `readme.txt` | **WordPress plugin readme.** Standard WordPress plugin readme with installation instructions, changelog, and FAQ. |
| `README.md` | **GitHub readme.** Project documentation for GitHub including features, installation, usage examples, and API information. |
| `.gitignore` | **Git ignore rules.** Excludes IDE files, OS files, and development artifacts. |
| `LICENSE` | **GPL-2.0 license.** Standard WordPress plugin license. |

---

## Class Relationships

```
Cloud_Cover_Forecast_Plugin (main orchestrator)
├── Cloud_Cover_Forecast_Autoloader (autoloading)
├── Cloud_Cover_Forecast_Assets (styles/scripts)
├── Cloud_Cover_Forecast_API (external APIs)
├── Cloud_Cover_Forecast_Photography_Renderer (calculations + rendering)
├── Cloud_Cover_Forecast_Shortcode (shortcode handler)
│   └── uses: API, Photography_Renderer
├── Cloud_Cover_Forecast_Admin (admin settings)
│   └── uses: API, Shortcode (for block rendering)
├── Cloud_Cover_Forecast_Public_Block (public lookup)
│   └── uses: API, Photography_Renderer
├── Cloud_Cover_Forecast_Sunrise_Sunset_Block (sunrise/sunset)
│   └── uses: API, Location_Search_Form
└── Cloud_Cover_Forecast_PWA (progressive web app)
    └── uses: API
```

---

## Data Flow

### Shortcode/Block Rendering
1. User requests page with `[cloud_cover]` shortcode or block
2. Shortcode handler checks for cached data (transient)
3. If no cache: API fetches weather from Open-Meteo + Met.no
4. Data merged (worst-case cloud values)
5. Photography times calculated (twilight, golden hour, etc.)
6. Photography renderer outputs HTML table with conditions

### PWA Forecast Request
1. User opens PWA at configurable endpoint (default: `/forecast-app/`)
2. User searches location → geocoding API called
3. Location selected → extended forecast API called (`fetch_extended_forecast()`)
4. Met.no `locationforecast/2.0/complete` fetched and its cloud readings attached
   to the hours around each sun event (`attach_met_no_readings()`). Open-Meteo
   values are never modified; the second source is stored alongside them as
   `hourly[].met_no` (`low`, `mid`, `high`), with `met_no_available` at the top
   level. This is *not*
   `merge_cloud_cover_rows()`, which overwrites with `max()` and still serves
   only the shortcode and blocks
5. Forecast stored in IndexedDB for offline access
6. Service worker caches responses

### Admin Settings
1. Admin visits Settings → Cloud Cover
2. Location search uses AJAX → Open-Meteo geocoding
3. Coordinates auto-populated in form
4. Settings saved to `cloud_cover_forecast_settings_v1` option

---

## API Endpoints

### AJAX Actions (WordPress)

| Action | Handler | Access | Description |
|--------|---------|--------|-------------|
| `ccf_geocode` | `Admin::ajax_geocode_location` | Admin only | Geocode location for admin settings |
| `cloud_cover_forecast_public_lookup` | `Public_Block::handle_ajax_lookup` | Public | Fetch forecast for public block |
| `cloud_cover_forecast_public_geocode` | `Public_Block::handle_ajax_geocode` | Public | Geocode for public block |
| `ccf_pwa_forecast` | `PWA::ajax_extended_forecast` | Public | Extended forecast for PWA |
| `ccf_pwa_geocode` | `PWA::ajax_geocode` | Public | Geocode for PWA |
| `ccf_pwa_reverse_geocode` | `PWA::ajax_reverse_geocode` | Public | Reverse geocode for PWA |
| `sunrise_sunset_geocode` | `Sunrise_Sunset_Block::handle_ajax_geocode` | Public | Geocode for sunrise block |

### External APIs Used

Internal budgets are set below each provider's published ceiling. Both Open-Meteo
endpoints draw on one shared provider quota (600/min, 5,000/hour, 10,000/day), so
their budgets are allocated to total 420/min, 2,800/hour and 9,000/day.

| Service | Endpoint | Internal Budget | Provider Limit | Cache TTL |
|---------|----------|-----------------|----------------|-----------|
| Open-Meteo Forecast | `api.open-meteo.com/v1/forecast` | 300/min, 2,000/hour, 7,000/day | 600/min, 5,000/hour, 10,000/day (shared) | 15 min fresh + 12 h stale |
| Open-Meteo Geocoding | `geocoding-api.open-meteo.com/v1/search` | 120/min, 800/hour, 2,000/day | shared with above | 15 min |
| Met.no Forecast | `api.met.no/weatherapi/locationforecast/2.0/complete` | 200/min, 3,000/hour | 20 req/sec | 15 min fresh + 12 h stale |
| IPGeolocation Astronomy | `api.ipgeolocation.io/astronomy` | 100/hour, 900/day | 1,000/day (free tier) | 24 hours |
| Nominatim Reverse Geocode | `nominatim.openstreetmap.org/reverse` | 1 req/sec | 1 req/sec (absolute max) | 24 hours |

Rate counters live in transients keyed `TRANSIENT_PREFIX . 'rate_' . service . '_' . window`.
They are deliberately **not** cache-versioned, so clearing the plugin cache cannot
reset them and let the site exceed a provider's limits.

---

## Constants Reference

### Plugin Constants (defined in `cloud-cover-forecast.php`)

| Constant | Description |
|----------|-------------|
| `CLOUD_COVER_FORECAST_VERSION` | Plugin version (e.g., `1.0.1`) |
| `CLOUD_COVER_FORECAST_PLUGIN_FILE` | Full path to main plugin file |
| `CLOUD_COVER_FORECAST_PLUGIN_DIR` | Plugin directory path (with trailing slash) |
| `CLOUD_COVER_FORECAST_PLUGIN_URL` | Plugin URL (with trailing slash) |

### Class Constants (in `Cloud_Cover_Forecast_Plugin`)

| Constant | Value | Description |
|----------|-------|-------------|
| `OPTION_KEY` | `cloud_cover_forecast_settings_v1` | Main settings option name |
| `TRANSIENT_PREFIX` | `cloud_cover_forecast_cache_` | Weather cache transient prefix |
| `GEOCODING_PREFIX` | `cloud_cover_forecast_geocoding_` | Geocoding cache transient prefix |
| `CACHE_VERSION_OPTION` | `cloud_cover_forecast_cache_version` | Cache version for busting |
| `RATE_LIMIT_PREFIX` | `cloud_cover_forecast_rate_limit_` | IP rate limit transient prefix |

### Class Constants (in `Cloud_Cover_Forecast_API`)

| Constant | Value | Description |
|----------|-------|-------------|
| `MET_NO_WINDOW_BEFORE` | `1` | Hours before a sun event that carry a Met.no reading |
| `MET_NO_WINDOW_AFTER` | `2` | Hours after a sun event that carry a Met.no reading. Deliberately wider than the two hours `sunriseSunsetRange()` samples, so a change to the JS window does not silently strand hours; `tests/range.test.js` asserts the two agree |
| `MET_NO_MAX_OFFSET` | `10800` | Furthest (seconds) a Met.no sample may sit from the hour it is matched to. Met.no drops to 6-hourly after ~2.6 days, so days 3-7 match the nearest sample |

### Default Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `lat` | `51.8986` | Default latitude (Cork, Ireland) |
| `lon` | `-8.4756` | Default longitude |
| `hours` | `48` | Forecast hours |
| `cache_ttl` | `15` | Cache TTL in minutes |
| `show_chart` | `1` | Show chart (disabled for blocks) |
| `provider` | `open-meteo` | Weather provider |
| `astro_api_key` | `''` | IPGeolocation API key |
| `pwa_path` | `forecast-app` | PWA URL endpoint |
| `pwa_noindex` | `1` | Discourage search engine indexing |

---

## Changelog

This section should be updated when committing changes to track modifications.

### Dual-source confidence for the PWA score (2026-09-01, v1.2.0)

`fetch_extended_forecast()` -- the PWA's data path -- was single-source
Open-Meteo, while the README advertised dual-source as a headline feature. The
Met.no merge lived in `fetch_weather_data()` and reached only the shortcode and
blocks.

A probe across 20 Irish locations and 101 sunrise/sunset hours found the two
sources disagree enough to flip the band label in **43% of cases**, with a
systematic bias rather than noise: Open-Meteo reads low cloud 16 points cloudier
(mean 69.9 vs 53.4). Low cloud is the gate in `scoreLightHour()`, so the PWA was
systematically pessimistic by construction.

Rather than adjudicate between two sources neither of which is known to be more
accurate, the score became a range. New in `class-api.php`:
`attach_met_no_readings()` and `met_no_hour_indices()`, plus the three
`MET_NO_*` constants. Payload gains `hourly[].met_no` (`low`, `mid`, `high`)
and `met_no_available`, both additive so payloads cached before this change
still render. A cleanup pass dropped two further keys that were written and
never read: `met_no.total` had no reader anywhere in `assets/`, and
`met_no.offset_hours` was read only by the tests asserting it. Both were
persisted in the cached transient and shipped to the browser on every load.

`sunriseSunsetScore()` became `sunriseSunsetRange()`, returning
`{low, high, sources}`; `bandScore()` was added to isolate the "label the low"
rule to one function. The Outlook ring gained a faded tail and a dashed
single-source track; the day hero meter gained a matching pale fill; a "Cloud by
source" table was added under each hero. The dead `calculateWindowScore` import
was removed from `forecast-app.js`.

Two traps worth remembering. Open-Meteo is fetched with `timezone=auto`, so its
stamps are offset-less local wall clock while Met.no is keyed in UTC --
converting with `strtotime()`/`gmdate()` is wrong by the server's own offset,
and a negative control confirms the naive form passes a GMT test while failing
an IST one. And averaging must be per source, not per hour: a control
implementing the per-hour alternative reported 27-85 on a fixture where the
correct code reports 56-56.

New tests: `tests/dual-source.test.php`, `tests/range.test.js`. `tests/harness.js`
now drops the module cache so one test file can install more than once.

Cleanup pass over the branch (`docs/superpowers/plans/2026-09-01-dual-source-cleanup.md`):
`attach_met_no_readings()` now uses the existing `to_timestamp_in_timezone()`
helper rather than a second inline parse; the "Cloud by source" table renders a
missing reading through `formatValue()` like the rest of the app, with a
null-layer test that the earlier fixtures never exercised; `describeRange()` in
`forecast-app.js` centralises the band, label, display text, aria phrasing and
source note that `renderOutlookCard()` and `renderDayHero()` each derived
separately. The PHP/JS sampling-window guard moved from `tests/range.test.js`,
where `-1` and `2` were hand-copied literals, to `tests/dual-source.test.php`,
which reads `MET_NO_WINDOW_BEFORE`/`AFTER` by reflection and
`MET_NO_SAMPLE_OFFSETS` from the JS source -- so it now fails if either side
moves, not just the JS one. `MET_NO_SAMPLE_OFFSETS` is no longer exported.

Deferred, deliberately: band thresholds stay at 80/60/40, and the double penalty
on low cloud in `scoreLightHour()` is untouched. Because Open-Meteo is almost
always the pessimistic end and the band word follows the low score, this feature
does not on its own make the app read less gloomy -- it makes it honest, and it
starts recording the per-source data that calibration will need.

### Fix dark mode when it is chosen rather than inherited (2026-08-31)

Choosing dark while the device was set to light left most text dark on a dark
background. Anything with an explicit `color` was fine, which is why the score
bands and times still read while the headings, day label and phase labels did
not.

`forecast-app.css` sets `body { color: var(--text-primary) }`, but the critical
CSS inlined in `pwa-app.php` sits *after* the stylesheet link and also sets
`body { color }`. Equal specificity, so the later one wins — and it only
handled `@media (prefers-color-scheme: dark)`, never the `.dark-mode` and
`.light-mode` classes `applyTheme()` puts on `<html>`. On a light system those
media rules never matched, so `body` kept the light text colour while the app
painted dark backgrounds.

- Critical CSS gains `.dark-mode body` and `.light-mode body` rules.
- `applyThemeColor()` (new) points both `theme-color` meta tags at the chosen
  colour, so an explicit choice reaches the browser status bar. They are
  qualified by `prefers-color-scheme`, so on their own they ignored the toggle.
- `tests/theme.test.php` checks the inline values against the stylesheet's
  tokens, since the two now have to be kept in step by hand.
  `tests/theme-color.test.js` covers the meta tags across the toggle cycle.

### Fix timezone conversion throughout the PWA (2026-08-31)

Every wall-clock time in the PWA was converted wrongly, by exactly the
*viewer's* UTC offset. Sunrise at 06:49 showed as "in 22 minutes" at 05:28.

`parseTimeToTimestamp()` built a `Date` from an offset-less string (parsed as
browser-local), then derived a correction from two `toLocaleString()`
round-trips. The result was `W - B - T` where the answer is `W - T`, leaving it
wrong by the browser's own offset `B` — zero on UTC, an hour in Ireland during
summer time, ten hours from Sydney.

`new Date(hour.time)` had the same flaw: Open-Meteo returns local stamps with
no offset, so they were read as the viewer's local time. `getSunlightClass()`
compared two differently-skewed values, so the golden and blue hour shading in
the hourly grid was shifted too. That bug predates the Alpenglow work.

- `timezoneOffset()` (new) measures a zone's offset via
  `Intl.DateTimeFormat.formatToParts`, independent of the browser's zone.
- `parseTimeToTimestamp()` rewritten on top of it, settling in two passes so
  DST changes resolve correctly.
- `parseHourTimestamp()` (new) converts Open-Meteo's hourly stamps.
- `nowInTimezone()` (new) returns the current date and hour at a location as
  strings, compared directly against the API's stamps. Replaces a
  `toLocaleString()` -> `new Date()` -> `toISOString()` chain that reapplied
  the viewer's offset a second time.
- Grid day boundaries now read `hour.time.split('T')[0]` rather than
  converting; the string already carries the location's date.

`tests/timezone.test.js` covers six zones including a +5:45 offset and both
sides of a DST change, and `tests/run.sh` now runs every JS test under
`TZ=UTC` and `TZ=Pacific/Auckland`.

### Version 1.1.0 and cache busting (2026-08-30)

- `CLOUD_COVER_FORECAST_VERSION` bumped to 1.1.0, with the plugin header and
  `readme.txt` stable tag to match. Asset URLs carry `?v=` this constant, so
  without the bump the browser's own HTTP cache could serve the old JS and CSS
  no matter what the service worker did.
- `forecast-app.js` now checks `ForecastScoring` is present before using it. A
  page cached before `forecast-scoring.js` existed does not load it, and the
  destructure would otherwise throw and leave the loading spinner up for ever.
  Covered by `tests/stale-shell.test.js`.

### Test suite (2026-08-30)

The plugin had no tests. `tests/` adds a small suite with no dependencies
beyond node and php, run with `tests/run.sh` (69 assertions).

| File | Covers |
|------|--------|
| `harness.js` | DOM stubs enough to run the real `forecast-app.js` under node |
| `shell.test.js` | App shell, view tabs, location switcher, picker, delete fallback |
| `outlook.test.js` | Outlook rows and cards, score rings, past events |
| `day.test.js` | Day pager, heroes, phase order and times, stale-cache fallback |
| `midnight.test.js` | Irish June: no astronomical dawn, dusk after midnight |
| `shared-link.test.js` | `?lat=&lon=` deep links |
| `scoring.test.js` | Colour score ordering and missing-data handling |
| `solar.test.php` | Solar times vs Alpenglow, plus a worldwide year-long sweep |

`.distignore` keeps `tests/` and the working docs out of a built plugin.

**These check markup and logic, never pixels.** No CSS is rendered anywhere in
the suite. `tests/README.md` records the rest of the caveats, including the one
guarantee that is structural rather than tested.

### Day view (2026-08-30)

A single day: prev/next pager, stacked sunrise and sunset hero cards, and the
ordered light-phase list.

- `renderDayView()`, `renderDayHero()`, `renderPhaseList()`, plus `phaseTimes()`,
  `shiftTime()` and `relativeTime()`.
- Nine phases in the reference app's order: First Light, Blue Hour, Golden
  Hour, Sunrise, Daytime, Golden Hour, Sunset, Blue Hour, Last Light. Rows
  with no time are dropped rather than rendered blank.
- The list renders in **logical** order, never sorted on the time string.
  Dusk phases can fall after local midnight (Irish June nautical dusk is
  00:02); those rows carry a `+1` marker.
- `relativeTime()` uses `Intl.RelativeTimeFormat`, so "in 8 hours" pluralises
  and localises without extra strings.
- `phaseTimes()` fills golden and blue hour from the old fixed offsets when
  the computed fields are missing, covering forecasts cached before the solar
  work landed (they stay serveable for 12 hours).
- New strings: `firstLight`, `blueHour`, `goldenHour`, `daytime`, `lastLight`.

Against the Alpenglow screenshot for Durrus, 2026-08-31 — five of nine phase
times exact, four out by one minute.

### Outlook view (2026-08-30)

Seven day-rows, each with a sunrise and a sunset card carrying a band label,
a score ring and the time. Tapping a card opens that day in the Day view.

- `renderOutlookView()`, `renderOutlookCard()`, `renderScoreRing()`, plus
  helpers `scoreBandLabel()`, `eventTime()` and `dayLabel()`.
- Events already past render a clock and no score, matching the reference app.
- Rings are SVG with `r="15.915"`, so the circumference is 100 and
  `stroke-dasharray` takes the score directly. The value is drawn as `<text>`
  inside the ring and each card carries an `aria-label`, so the number is
  never only graphical.
- New strings: `scoreExcellent`, `scoreGood`, `scoreFair`, `scorePoor`, `past`.

### PWA navigation: view tabs instead of location tabs (2026-08-30)

Tabs used to select a *location* (Home / Current / Locations). They now select
a *view* of one selected location, with location switching moved to the header.

- New state: `activeView` ('hours' | 'outlook' | 'day'), `selectedLocation`,
  `selectedDayIndex`, `showLocationPicker`. `currentLocation` and
  `sharedLocation` are gone — GPS results and shared URLs are just locations
  that get selected like any other.
- `selectLocation()` is the single path by which a location reaches the
  screen, whether from the picker, a shared URL, GPS or launch.
  `forecastKey()` keys the forecast cache on id, or on rounded coordinates for
  anything unsaved, replacing the magic `'current'` and `'shared'` keys.
- `viewLocation()` no longer assigns `state.homeLocation`. That assignment was
  why the Home tab showed whichever location was last tapped.
  `state.homeLocation` is now written only in `loadSavedLocations()`, from
  storage.
- The tab bar moved to the bottom of the app shell, with
  `padding-bottom: var(--safe-bottom)` to clear the iOS home indicator, and
  the active marker moved to the top edge of the button.
- `renderLocationsTab()` became `renderLocationPicker()`, a labelled dialog
  over the active view, with "Use my location" added above the saved list.
- New UI strings: `hours`, `outlook`, `day`, `changeLocation`, `useMyLocation`.

`renderOutlookView()` and `renderDayView()` are placeholders in this change.

### Sunrise/sunset colour score (2026-08-30)

`forecast-scoring.js` gains `sunriseSunsetScore( hourly, dayData, event )`,
scoring a sunrise or sunset 0-100 for colour, plus `scoreLightHour()` and
`findHourIndex()` behind it.

- Samples the hour holding the event and the hour after it. The hour further
  from midday is the "glow hour", where high cloud counts 1.5x because cirrus
  stays lit after the sun is down.
- High cloud is the main positive (peaks 40-70%), mid cloud secondary
  (peaks 30-50%), and **low cloud gates rather than subtracts**: it scales the
  whole cloud bonus toward zero, reaching zero at 70% cover. The sun lights
  high cloud along a path skimming the horizon, so cloud on that horizon stops
  the light before it arrives.
- `findHourIndex()` matches Open-Meteo's local time strings directly instead
  of parsing them to a `Date`, which would interpret them in the viewer's
  timezone rather than the location's.

**The weights are not calibrated.** They are plausible meteorology, not a
model fitted to observations, and will disagree with dedicated apps. Behaviour
across representative skies (mean of the two sampled hours):

| Sky | Score | Band |
|-----|-------|------|
| Cloudless blue | 40 | fair |
| Good cirrus deck, clear horizon | 78 | good |
| Cirrus + mid cloud, clear horizon | 85 | excellent |
| Same cirrus, 40% low cloud | 53 | fair |
| Same cirrus, 65% low cloud | 33 | poor |
| Full low stratus with rain | 5 | poor |

### Real solar elevation for golden and blue hour (2026-08-30)

Golden hour was a fixed sunrise/sunset +/- 60 minutes and blue hour a fixed
+15 to +45 minutes. Both are wrong away from the equinoxes, increasingly so
at Irish latitudes through summer.

- `Cloud_Cover_Forecast_API::solar_event_times()` (new, private) solves the
  times the sun crosses any elevation, from standard low-precision solar
  position formulae. `normalize_degrees_signed()` supports it.
- `calculate_twilight_times()` now derives every boundary from that solver
  and returns eight new fields: `blue_hour_dawn_start`/`_end`,
  `golden_hour_dawn_start`/`_end`, `golden_hour_dusk_start`/`_end`,
  `blue_hour_dusk_start`/`_end`. Golden hour spans +6 to -4 degrees, blue
  hour -4 to -6. Any field is null where the sun never reaches that
  elevation, which is ordinary at high latitude.
- Two bugs fixed along the way. Local noon was built with `strtotime()` in
  the *server's* timezone, landing on the wrong day for distant locations.
  And `date_sun_info()`, previously the source of the twilight fields,
  anchors to the UTC day, so it answered for the previous local date at
  UTC+13 and beyond; it is no longer used here.
- Verified against Alpenglow for Durrus on 2026-08-31: ten of twelve
  boundaries exact, two out by a minute. Chronological ordering holds across
  3,650 location-days spanning ten locations and every day of 2026.

**Note:** 181 of those location-days have a dusk phase falling after local
midnight (an Irish June nautical dusk is 00:02). Times are formatted `H:i`,
so consumers must render the phase list in logical order rather than sorting
on the string.

**Deploying:** forecasts now outlive freshness by 12 hours, so entries cached
before this change can be served without the new fields for half a day. Clear
the cache from the plugin settings page after deploying.

### Extract PWA scoring into its own module (2026-08-30)

Groundwork for an Alpenglow-style sunrise/sunset outlook. No behaviour change.

- New `assets/js/forecast-scoring.js` holds the pure functions moved out of
  `forecast-app.js`: `parseTimeToTimestamp`, `getSunlightFallback`,
  `getSunlightClass`, `calculatePhotoScore`, `calculateWindowScore`,
  `getScoreClass`, `getScoreLabel`. Bodies are byte-identical to the originals.
- `forecast-app.js` drops 220 lines and reaches them via `window.ForecastScoring`.
- Dropped `getStarRating()`, which had no callers anywhere in the plugin.
- `templates/pwa-app.php` loads the new script before the app; the service
  worker precaches it and its `CACHE_VERSION` moves to `v21`.

### Rate limits, caching, theming and accessibility (2026-08-12)

**API rate limits (`class-api.php`)**
- `SERVICE_RATE_LIMITS` now holds a *list* of windows per service instead of a single window, so per-minute, per-hour and per-day provider ceilings are all enforced.
- Budgets raised to realistic values. The previous caps (45 Open-Meteo req/hour, 15 Met.no req/hour) were roughly 100x below what the providers allow, and being site-wide counters they left the block and PWA broken for all visitors once exhausted.
- Rate counters moved off cache-versioned transient keys via `get_rate_limit_key()`, so a cache flush no longer resets them.

**Stale-while-revalidate caching (`class-api.php`)**
- New `get_cached_remote()` / `fetch_and_cache()` / `store_response()` layer. Cached entries carry `fresh_until` and `last_modified` alongside the response; the transient outlives freshness by `STALE_GRACE` (12 h).
- Stale entries are served immediately and refreshed by a background cron event (`REFRESH_HOOK`, guarded by a lock transient), so only the first-ever request for a location waits on the network.
- Conditional requests: `If-Modified-Since` is sent when a `Last-Modified` is known, and 304 responses reuse the cached body. Met.no's terms of service ask consumers to do this.
- Provider errors, timeouts and rate-limit rejections now fall back to stale data instead of surfacing an error whenever usable data exists.
- Request timeouts consolidated into `get_request_args()` and cut from 12-15s to 5s; the three page-render fetchers (`fetch_weather_data`, `fetch_extended_forecast`, `fetch_met_no_complete`) all route through the new layer.

**Theming**
- New `assets/css/forecast-tokens.css` holds all `--ccf-*` tokens with light and dark palettes.
- New `assets/css/forecast-block.css` replaces the 4KB minified CSS string formerly returned by `Assets::get_css()` (method removed). The old blob was light-mode only, so the forecast card rendered dark-on-dark under any dark theme.
- `public-block.css` converted to tokens; no hardcoded colours remain except the intentional `prefers-contrast: high` block.
- `Assets::register_tokens()` registers the shared handle; both stylesheets declare it as a dependency so it loads once regardless of which enqueues first.

**Accessibility (`forecast-app.js`, `forecast-app.css`, `pwa-app.php`)**
- `aria-label` added to 16 icon-only buttons and links that previously carried only a `title`.
- Tab nav labelled, with `aria-current="page"` on the active tab; both modals given `role="dialog"`, `aria-modal` and `aria-labelledby`; search input labelled and its results wrapped in a polite live region; loading state given `role="status"` and error state `role="alert"`; decorative glyphs and SVGs marked `aria-hidden`.
- `:focus-visible` rings on all interactive controls, 44px minimum touch targets, a `.visually-hidden` utility, and a `prefers-reduced-motion` block.
- `scrollBehavior()` makes the three programmatic `scrollTo()` calls honour the motion preference, which CSS cannot reach.
- Fixed the iOS Share glyph in the install instructions: it was `&#61512;` (U+F048, Unicode Private Use Area), which renders as a blank box outside Apple's icon fonts. Replaced with inline SVG.
- Added the 25 UI strings that `forecast-app.js` referenced but `pwa-app.php` never defined; they were falling back to hardcoded English and could not be translated.

### PWA Navigation Day Display (2026-01-23)
- Added day of week (3-letter) and date display to PWA navigation row, left-justified
- Navigation buttons centered in remaining space using `.jump-buttons-nav` wrapper
- Day display updates dynamically as user scrolls or uses prev/next day buttons
- New functions: `updateCurrentDayDisplay()`, `setupGridScrollListener()`
- New CSS classes: `.current-day-display`, `.jump-buttons-nav`

### PWA Photography UI Redesign (2026-01-23)
- Added hero summary card showing today's photography outlook with sunrise/sunset quality ratings
- Added photography score row to hourly forecast grid (calculates 0-100 score based on clouds, rain, visibility, wind)
- Added 24H / 7-Day view toggle for quick switching between detailed hourly and weekly overview
- Added 7-day view with day cards showing daily summaries and quality ratings
- Added jump buttons for quick navigation to sunrise/sunset/current hour
- Added countdown timer to next golden hour / blue hour event
- New CSS classes: `.hero-card`, `.photo-score-cell`, `.view-toggle`, `.day-card`, `.jump-btn`
- New strings in pwa-app.php: todaysOutlook, goldenHour, photoScore, hourlyView, weeklyView, etc.
- New state properties: `viewMode` ('24h' or '7day'), `selectedDay`
- New functions: `calculatePhotoScore()`, `renderHeroCard()`, `renderSevenDayView()`, `renderViewToggle()`, `renderJumpButtons()`

### Initial Documentation (2026-01-23)
- Created comprehensive codebase reference
- Documented all 13 PHP files, 7 JS files, 2 CSS files
- Added class relationships, data flow, and API reference
