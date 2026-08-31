# Dual-Source Confidence Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Score the PWA's sunrise/sunset forecast against both Open-Meteo and Met.no and show the span between them as a range, so the width of the range is the confidence signal.

**Architecture:** PHP attaches Met.no cloud readings to the hours around each sun event in the existing Open-Meteo payload, without mutating any Open-Meteo value. JavaScript scores the same formula twice — once on each source's cloud layers — and returns `{low, high, sources}`. The Outlook ring fills solidly to `low` and continues as a faded arc to `high`; the day-view meter does the same in bar form.

**Tech Stack:** WordPress plugin PHP (no framework, no Composer), vanilla JavaScript IIFE modules (no build step), CSS custom properties. Tests are plain `node` and `php` scripts run by `tests/run.sh`.

**Spec:** `docs/superpowers/specs/2026-09-01-dual-source-confidence-design.md`

## Global Constraints

- No build step. PHP and vanilla JS run directly; never introduce a bundler, transpiler, or package manager.
- No test dependencies. `tests/run.sh` uses only `node` and `php`.
- All payload changes are **additive only**. A cached payload from before this change has no `met_no` keys and no `met_no_available`, and must render exactly as it does today rather than throwing. Stale-while-revalidate has a 12-hour grace window, so old-shape payloads *will* be served after deploy.
- `CLOUD_COVER_FORECAST_VERSION` in `cloud-cover-forecast.php:25` must be bumped to `1.2.0`. Asset URLs carry `?v=CLOUD_COVER_FORECAST_VERSION`; without the bump, browsers serve stale JS and CSS.
- Text domain for all `__()` calls is `cloud-cover-forecast`.
- Per `CLAUDE.md`: update `reference.md` with new files, changed relationships, new constants, and a changelog entry.
- The band word and card colour follow `range.low` (spec decision 4). This rule lives in exactly one function, `bandScore()`.
- Never call `merge_cloud_cover_rows()` from the PWA path. It overwrites values with `max()` and would destroy the Open-Meteo readings the range needs.

### Deviation from the spec, deliberate

The spec sketched `attach_met_no_readings( $hourly, $daily, $timezone, $lat, $lon )` doing its own fetching. This plan splits that into a **pure** `attach_met_no_readings( $hourly, $daily, $timezone, $metno_hourly )` plus a fetch at the call site. Same behaviour, but the method under test makes no HTTP request, so the PHP tests need no WordPress and no network. Everything else follows the spec as written.

---

### Task 1: PHP — attach Met.no readings to event hours

**Files:**
- Modify: `includes/class-api.php` (add two private methods; wire into `fetch_extended_forecast()` at `:385`)
- Test: `tests/dual-source.test.php` (create)

**Interfaces:**
- Consumes: `fetch_met_no_complete( float $lat, float $lon )` at `class-api.php:1070`, which returns `array( 'hourly' => array, 'source_url' => string, 'updated_at' => ?string )` or `WP_Error`. Its `hourly` map is keyed by `gmdate( 'Y-m-d H', $ts )` (**real UTC**) with values `array( 'ts' => int, 'total' => ?int, 'low' => ?int, 'mid' => ?int, 'high' => ?int )`.
- Produces:
  - `private function met_no_hour_indices( array $hourly, array $daily ): array` — sorted list of integer indices into `$hourly`.
  - `private function attach_met_no_readings( array $hourly, array $daily, string $timezone, array $metno_hourly ): array` — returns `$hourly` with `met_no` keys added.
  - Payload gains `$hour['met_no'] = array( 'total'=>?int, 'low'=>?int, 'mid'=>?int, 'high'=>?int, 'offset_hours'=>int )` on selected hours, and top-level `'met_no_available' => bool`.
  - Class constants `MET_NO_WINDOW_BEFORE = 1`, `MET_NO_WINDOW_AFTER = 2`, `MET_NO_MAX_OFFSET = 10800`.

**Critical:** Open-Meteo is called with `timezone=auto`, so `$hour['time']` is an **offset-less local wall-clock stamp** (`2026-10-24T20:00`). Met.no's map is keyed in **UTC**. Converting with `strtotime()` or `gmdate()` uses the server's timezone and is wrong — this is the same defect class as the v1.1.1 timezone bug. Use `DateTimeImmutable` with an explicit `DateTimeZone`.

- [ ] **Step 1: Write the failing test**

Create `tests/dual-source.test.php`:

```php
<?php
/**
 * Met.no reading attachment for the PWA payload.
 *
 * Loads class-api.php outside WordPress and reaches the private methods by
 * reflection, matching tests/solar.test.php. The methods under test touch no
 * WordPress APIs and make no HTTP requests.
 *
 * @package CloudCoverForecast
 */

define( 'ABSPATH', __DIR__ );
foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
	'MONTH_IN_SECONDS'  => 2592000,
	'YEAR_IN_SECONDS'   => 31536000,
) as $name => $value ) {
	define( $name, $value );
}
require dirname( __DIR__ ) . '/includes/class-api.php';

$reflection = new ReflectionClass( 'Cloud_Cover_Forecast_API' );
$api        = $reflection->newInstanceWithoutConstructor();
$attach     = $reflection->getMethod( 'attach_met_no_readings' );
$indices    = $reflection->getMethod( 'met_no_hour_indices' );

$passed = 0;
$failed = 0;
$assert = function ( $name, $ok ) use ( &$passed, &$failed ) {
	echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . "\n";
	if ( $ok ) {
		$passed++;
	} else {
		$failed++;
	}
};

/** Build 24 hourly entries of local wall-clock stamps for one date. */
function ccf_hours( $date ) {
	$hourly = array();
	for ( $h = 0; $h < 24; $h++ ) {
		$hourly[] = array(
			'time'      => $date . 'T' . str_pad( (string) $h, 2, '0', STR_PAD_LEFT ) . ':00',
			'cloud_low' => 40,
			'cloud_mid' => 91,
			'cloud_high' => 59,
		);
	}
	return $hourly;
}

/** Build a Met.no map from [utc_timestamp => [low, mid, high]] pairs. */
function ccf_metno( array $entries ) {
	$map = array();
	foreach ( $entries as $ts => $vals ) {
		$map[ gmdate( 'Y-m-d H', $ts ) ] = array(
			'ts'    => $ts,
			'total' => $vals[3] ?? 90,
			'low'   => $vals[0],
			'mid'   => $vals[1],
			'high'  => $vals[2],
		);
	}
	return $map;
}

// --- DST: the same wall clock is a different UTC hour ---------------------
// Ireland leaves IST (UTC+1) for GMT (UTC+0) on 2026-10-25.
echo "\nLocal wall-clock to UTC across a DST boundary:\n";

$ist_day = array( array( 'date' => '2026-10-24', 'twilight' => array( 'sunset' => '20:27' ) ) );
$gmt_day = array( array( 'date' => '2026-10-26', 'twilight' => array( 'sunset' => '20:27' ) ) );

// One Met.no sample at 19:00 UTC and one at 20:00 UTC, on each date.
$samples = ccf_metno( array(
	strtotime( '2026-10-24T19:00:00Z' ) => array( 8, 70, 61 ),
	strtotime( '2026-10-24T20:00:00Z' ) => array( 88, 10, 11 ),
	strtotime( '2026-10-26T19:00:00Z' ) => array( 88, 10, 11 ),
	strtotime( '2026-10-26T20:00:00Z' ) => array( 8, 70, 61 ),
) );

$ist = $attach->invoke( $api, ccf_hours( '2026-10-24' ), $ist_day, 'Europe/Dublin', $samples );
$gmt = $attach->invoke( $api, ccf_hours( '2026-10-26' ), $gmt_day, 'Europe/Dublin', $samples );

// 20:00 local on 24 Oct is 19:00 UTC (IST, +1).
$assert( 'IST 20:00 local matches the 19:00 UTC sample', 8 === $ist[20]['met_no']['low'] );
// 20:00 local on 26 Oct is 20:00 UTC (GMT, +0).
$assert( 'GMT 20:00 local matches the 20:00 UTC sample', 8 === $gmt[20]['met_no']['low'] );
$assert( 'both matched exactly, offset 0',
	0 === $ist[20]['met_no']['offset_hours'] && 0 === $gmt[20]['met_no']['offset_hours'] );

// --- Nearest sample within three hours ------------------------------------
echo "\nNearest-sample selection:\n";
$day = array( array( 'date' => '2026-07-01', 'twilight' => array( 'sunset' => '21:00' ) ) );

$exactly_3h = $attach->invoke( $api, ccf_hours( '2026-07-01' ), $day, 'Europe/Dublin',
	ccf_metno( array( strtotime( '2026-07-01T17:00:00Z' ) => array( 8, 70, 61 ) ) ) );
// 21:00 IST = 20:00 UTC; the sample is three hours earlier.
$assert( 'a sample exactly 3h away is accepted', isset( $exactly_3h[21]['met_no'] ) );
$assert( 'and reports offset_hours 3', 3 === $exactly_3h[21]['met_no']['offset_hours'] );

$past_3h = $attach->invoke( $api, ccf_hours( '2026-07-01' ), $day, 'Europe/Dublin',
	ccf_metno( array( strtotime( '2026-07-01T16:59:00Z' ) => array( 8, 70, 61 ) ) ) );
$assert( 'a sample 3h01m away is rejected', ! isset( $past_3h[21]['met_no'] ) );

$tie = $attach->invoke( $api, ccf_hours( '2026-07-01' ), $day, 'Europe/Dublin',
	ccf_metno( array(
		strtotime( '2026-07-01T18:00:00Z' ) => array( 8, 70, 61 ),
		strtotime( '2026-07-01T22:00:00Z' ) => array( 88, 10, 11 ),
	) ) );
$assert( 'an equidistant tie resolves to the earlier sample', 8 === $tie[21]['met_no']['low'] );

// --- Null layers fall back per layer --------------------------------------
echo "\nNull layer handling:\n";
$partial = ccf_metno( array( strtotime( '2026-07-01T20:00:00Z' ) => array( 8, null, 61 ) ) );
$got     = $attach->invoke( $api, ccf_hours( '2026-07-01' ), $day, 'Europe/Dublin', $partial );
$assert( 'a null layer is carried through as null, not zero', null === $got[21]['met_no']['mid'] );
$assert( 'the other layers are present', 8 === $got[21]['met_no']['low'] );

// --- No Met.no data leaves the payload untouched --------------------------
echo "\nMissing Met.no data:\n";
$original  = ccf_hours( '2026-07-01' );
$unchanged = $attach->invoke( $api, $original, $day, 'Europe/Dublin', array() );
$assert( 'an empty Met.no map returns the payload unchanged', $original === $unchanged );

$bad_tz = $attach->invoke( $api, $original, $day, 'Not/AZone',
	ccf_metno( array( strtotime( '2026-07-01T20:00:00Z' ) => array( 8, 70, 61 ) ) ) );
$assert( 'an invalid timezone returns the payload unchanged', $original === $bad_tz );

// --- Which hours get a reading --------------------------------------------
echo "\nHour window:\n";
$window = $indices->invoke( $api, ccf_hours( '2026-07-01' ), $day );
$assert( 'the window is eventIndex-1 .. eventIndex+2', array( 20, 21, 22, 23 ) === $window );

$two_events = array( array(
	'date'     => '2026-07-01',
	'twilight' => array( 'sunrise' => '05:15', 'sunset' => '21:00' ),
) );
$both = $indices->invoke( $api, ccf_hours( '2026-07-01' ), $two_events );
$assert( 'sunrise and sunset both contribute windows',
	array( 4, 5, 6, 7, 20, 21, 22, 23 ) === $both );

$no_event = $indices->invoke( $api, ccf_hours( '2026-07-01' ),
	array( array( 'date' => '2026-07-01', 'twilight' => array( 'sunset' => null ) ) ) );
$assert( 'a polar day with no sunset yields no hours', array() === $no_event );

echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/dual-source.test.php`
Expected: fatal error, `Method attach_met_no_readings does not exist`.

- [ ] **Step 3: Add the constants and the two methods**

In `includes/class-api.php`, add to the class constants near the top of the class body:

```php
	/**
	 * Hours either side of a sun event that carry a Met.no reading.
	 *
	 * Wider than the two hours sunriseSunsetRange() samples in
	 * assets/js/forecast-scoring.js, so a small change to the JS sampling
	 * window does not silently strand hours without a reading. See
	 * MET_NO_SAMPLE_OFFSETS there; tests/range.test.js asserts they agree.
	 *
	 * @var int
	 */
	private const MET_NO_WINDOW_BEFORE = 1;
	private const MET_NO_WINDOW_AFTER  = 2;

	/**
	 * Furthest a Met.no sample may sit from the hour it is matched to.
	 *
	 * Met.no drops to 6-hourly resolution after about 2.6 days, so days 3-7
	 * match the nearest sample rather than an exact hour. Measured cost of a
	 * 3h offset is about 5 points of extra apparent disagreement, against
	 * 23.6 points of weather change alone -- errors add in quadrature, so the
	 * comparison still predominantly measures source disagreement.
	 *
	 * @var int
	 */
	private const MET_NO_MAX_OFFSET = 10800;
```

Then add both methods (place them beside `fetch_met_no_complete()`):

```php
	/**
	 * Indices of the hours that should carry a Met.no reading.
	 *
	 * Mirrors findHourIndex() in assets/js/forecast-scoring.js: a string
	 * prefix match of "{date}T{HH}" against the local wall-clock stamp. No
	 * arithmetic, no rounding, so the two languages cannot disagree on a
	 * given input.
	 *
	 * @since 1.2.0
	 * @param array $hourly Hourly rows with local wall-clock 'time' values.
	 * @param array $daily  Daily rows carrying 'date' and 'twilight'.
	 * @return array Sorted integer indices into $hourly.
	 */
	private function met_no_hour_indices( array $hourly, array $daily ): array {
		$count   = count( $hourly );
		$indices = array();

		foreach ( $daily as $day ) {
			$date = $day['date'] ?? null;
			if ( ! $date ) {
				continue;
			}

			foreach ( array( 'sunrise', 'sunset' ) as $event ) {
				$time = $day['twilight'][ $event ] ?? null;
				if ( ! $time ) {
					continue;
				}

				$prefix = $date . 'T' . substr( $time, 0, 2 );
				for ( $i = 0; $i < $count; $i++ ) {
					if ( ! isset( $hourly[ $i ]['time'] ) || 0 !== strpos( $hourly[ $i ]['time'], $prefix ) ) {
						continue;
					}

					for ( $offset = -self::MET_NO_WINDOW_BEFORE; $offset <= self::MET_NO_WINDOW_AFTER; $offset++ ) {
						$j = $i + $offset;
						if ( $j >= 0 && $j < $count ) {
							$indices[ $j ] = true;
						}
					}
					break;
				}
			}
		}

		ksort( $indices );
		return array_keys( $indices );
	}

	/**
	 * Attach Met.no cloud readings to the hours around each sun event.
	 *
	 * Open-Meteo values are never modified. The reading is attached alongside
	 * them so the PWA can score both sources and show the span. This is
	 * deliberately not merge_cloud_cover_rows(), which overwrites values with
	 * max() and would destroy the readings a range needs.
	 *
	 * @since 1.2.0
	 * @param array  $hourly       Hourly rows with local wall-clock 'time' values.
	 * @param array  $daily        Daily rows carrying 'date' and 'twilight'.
	 * @param string $timezone     IANA timezone the wall-clock stamps are in.
	 * @param array  $metno_hourly Map from fetch_met_no_complete()['hourly'].
	 * @return array $hourly with 'met_no' keys added to selected rows.
	 */
	private function attach_met_no_readings( array $hourly, array $daily, string $timezone, array $metno_hourly ): array {
		if ( empty( $metno_hourly ) ) {
			return $hourly;
		}

		try {
			$tz = new DateTimeZone( $timezone );
		} catch ( Exception $e ) {
			return $hourly;
		}

		// Ascending so an equidistant tie resolves to the earlier sample.
		$samples = array_values( $metno_hourly );
		usort(
			$samples,
			static function ( $a, $b ) {
				return $a['ts'] <=> $b['ts'];
			}
		);

		foreach ( $this->met_no_hour_indices( $hourly, $daily ) as $i ) {
			$stamp = $hourly[ $i ]['time'] ?? null;
			if ( ! $stamp ) {
				continue;
			}

			// The stamp has no offset, so the zone must be supplied. Doing
			// this with strtotime() or gmdate() uses the server's timezone
			// and is wrong by exactly that offset.
			try {
				$target = ( new DateTimeImmutable( $stamp, $tz ) )->getTimestamp();
			} catch ( Exception $e ) {
				continue;
			}

			$best       = null;
			$best_delta = null;
			foreach ( $samples as $sample ) {
				$delta = abs( $sample['ts'] - $target );
				if ( $delta > self::MET_NO_MAX_OFFSET ) {
					continue;
				}
				if ( null === $best_delta || $delta < $best_delta ) {
					$best       = $sample;
					$best_delta = $delta;
				}
			}

			if ( null === $best ) {
				continue;
			}

			$hourly[ $i ]['met_no'] = array(
				'total'        => $best['total'],
				'low'          => $best['low'],
				'mid'          => $best['mid'],
				'high'         => $best['high'],
				'offset_hours' => intdiv( $best_delta, HOUR_IN_SECONDS ),
			);
		}

		return $hourly;
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/dual-source.test.php`
Expected: `17 passed, 0 failed`

- [ ] **Step 5: Negative control — prove the DST test bites**

Temporarily replace the `DateTimeImmutable` line with the naive form:

```php
			$target = strtotime( $stamp . 'Z' );
```

Run: `php tests/dual-source.test.php`
Expected: **FAIL** on `IST 20:00 local matches the 19:00 UTC sample`. If it still passes, the test is not testing anything — fix the test before continuing.

Then restore the `DateTimeImmutable` line and re-run to confirm green.

- [ ] **Step 6: Wire it into `fetch_extended_forecast()`**

In `includes/class-api.php`, inside `fetch_extended_forecast()`, after the twilight loop (`unset( $day );` following `$day['twilight'] = ...`) and before the moon-data loop, insert:

```php
		// Attach the second source's cloud readings around each sun event.
		// Met.no keeps its own transient and rate-limit bucket, so an outage
		// here never invalidates a good Open-Meteo forecast.
		$met_no_available = false;
		$metno            = $this->fetch_met_no_complete( $lat, $lon );
		if ( ! is_wp_error( $metno ) && ! empty( $metno['hourly'] ) ) {
			$hourly_data      = $this->attach_met_no_readings( $hourly_data, $daily_data, $timezone, $metno['hourly'] );
			$met_no_available = true;
		}
```

Then add the key to the returned array, after `'moon' => $moon_data,`:

```php
			'met_no_available' => $met_no_available,
```

- [ ] **Step 7: Run the full suite**

Run: `./tests/run.sh`
Expected: `ALL TESTS PASSED`. The syntax stage must show no `php -l` errors for `class-api.php`.

- [ ] **Step 8: Commit**

```bash
git add includes/class-api.php tests/dual-source.test.php
git commit -m "Attach Met.no cloud readings to PWA event hours

Adds attach_met_no_readings() and met_no_hour_indices() to the PWA data
path, which was single-source Open-Meteo. Open-Meteo values are never
modified; the second source is attached alongside them.

Open-Meteo is fetched with timezone=auto so its stamps are offset-less
local wall clock, while Met.no is keyed in UTC. Conversion goes through
DateTimeImmutable with an explicit zone; a negative control confirms the
naive strtotime form fails the DST test.

Spec: docs/superpowers/specs/2026-09-01-dual-source-confidence-design.md"
```

---

### Task 2: JavaScript — score both sources as a range

**Files:**
- Modify: `assets/js/forecast-scoring.js` (replace `sunriseSunsetScore`, add `bandScore`, add `MET_NO_SAMPLE_OFFSETS`, update the exports block at the bottom)
- Modify: `assets/js/forecast-app.js:34-45` (import block only; the two call sites move in Tasks 3 and 4)
- Test: `tests/range.test.js` (create)

**Interfaces:**
- Consumes: `scoreLightHour( hour, isGlowHour )` and `findHourIndex( hourly, dateStr, timeStr )`, both already in `forecast-scoring.js`. Payload key `hour.met_no` from Task 1.
- Produces:
  - `sunriseSunsetRange( hourly, dayData, event )` → `{ low: number, high: number, sources: 1|2 }` or `null`.
  - `bandScore( range )` → `number` or `null`. **The single place the "label the low" rule lives.**
  - `MET_NO_SAMPLE_OFFSETS` → `[0, 1]`, exported so the coupling test can check it against the PHP window.
  - `sunriseSunsetScore` **no longer exists**. Task 3 and Task 4 update its two call sites.

- [ ] **Step 1: Write the failing test**

Create `tests/range.test.js`:

```js
/**
 * Dual-source score ranges.
 *
 * The range is min/max of two per-source means, not the mean of per-hour
 * min/max. Those give different answers whenever the sources disagree in
 * opposite directions across the two sampled hours, and the fixture below is
 * built so they do.
 */
'use strict';
const path = require('path');
global.window = global;
require(path.resolve(__dirname, '..', 'assets', 'js', 'forecast-scoring.js'));
const S = global.ForecastScoring;

let passed = 0, failed = 0;
const assert = (name, ok) => {
  console.log((ok ? '  PASS  ' : '  FAIL  ') + name);
  if (ok) { passed++; } else { failed++; process.exitCode = 1; }
};

const DATE = '2026-07-01';

/** 24 hours of one sky, with optional met_no overlays keyed by hour. */
function buildHours(sky, metByHour = {}) {
  const hours = [];
  for (let h = 0; h < 24; h++) {
    const hour = {
      time: `${DATE}T${String(h).padStart(2, '0')}:00`,
      cloud_low: sky.low, cloud_mid: sky.mid, cloud_high: sky.high,
      cloud_total: 90, rain_chance: 0, visibility: 20000,
    };
    if (metByHour[h]) hour.met_no = metByHour[h];
    hours.push(hour);
  }
  return hours;
}

const day = { date: DATE, twilight: { sunset: '21:00', sunrise: '05:15' } };

console.log('\nSingle source, no Met.no data:');
const soloRange = S.sunriseSunsetRange(buildHours({ low: 40, mid: 91, high: 59 }), day, 'sunset');
assert('returns sources: 1', 1 === soloRange.sources);
assert('low equals high', soloRange.low === soloRange.high);
assert('bandScore returns the low', S.bandScore(soloRange) === soloRange.low);

console.log('\nBoth sources, disagreeing:');
// Open-Meteo sees heavy low cloud; Met.no sees a clear horizon.
const bothRange = S.sunriseSunsetRange(
  buildHours({ low: 40, mid: 91, high: 59 }, {
    21: { low: 8, mid: 70, high: 61, offset_hours: 0 },
    22: { low: 8, mid: 70, high: 61, offset_hours: 0 },
  }), day, 'sunset');
assert('returns sources: 2', 2 === bothRange.sources);
assert('the range has width', bothRange.high > bothRange.low);
assert('Open-Meteo is the pessimistic end here', bothRange.low === soloRange.low);
console.log(`    range: ${bothRange.low}-${bothRange.high}`);

console.log('\nPer-source averaging, not per-hour:');
// Hour 21: Open-Meteo clear, Met.no clouded. Hour 22: the reverse.
// Per-source means are close together; per-hour min/max would be far apart.
const crossed = S.sunriseSunsetRange(
  buildHours({ low: 0, mid: 30, high: 60 }, {
    21: { low: 90, mid: 30, high: 60, offset_hours: 0 },
    22: { low: 90, mid: 30, high: 60, offset_hours: 0 },
  }), day, 'sunset');
const swapped = buildHours({ low: 0, mid: 30, high: 60 }, {
  21: { low: 90, mid: 30, high: 60, offset_hours: 0 },
  22: { low: 0, mid: 30, high: 60, offset_hours: 0 },
});
swapped[22].cloud_low = 90;
const mixed = S.sunriseSunsetRange(swapped, day, 'sunset');
// Each source is clear for one hour and clouded for the other, so both means
// land in the middle and the range is narrow. Per-hour min/max would take the
// clear hour's high and the clouded hour's low and produce a wide one.
assert('opposite disagreements average out to a narrow range', (mixed.high - mixed.low) < 10);
console.log(`    crossed: ${crossed.low}-${crossed.high}, mixed: ${mixed.low}-${mixed.high}`);

console.log('\nPartial Met.no coverage:');
const partial = S.sunriseSunsetRange(
  buildHours({ low: 40, mid: 91, high: 59 }, {
    21: { low: 8, mid: 70, high: 61, offset_hours: 0 },
  }), day, 'sunset');
assert('one of two sampled hours is not a comparison', 1 === partial.sources);
assert('and collapses to the Open-Meteo score', partial.low === soloRange.low);

console.log('\nNull layers fall back per layer:');
const nulled = S.sunriseSunsetRange(
  buildHours({ low: 40, mid: 91, high: 59 }, {
    21: { low: 8, mid: null, high: null, offset_hours: 0 },
    22: { low: 8, mid: null, high: null, offset_hours: 0 },
  }), day, 'sunset');
assert('a null layer does not read as zero cloud', 2 === nulled.sources);
assert('the range still opens on the low-cloud difference', nulled.high > nulled.low);

console.log('\nAgreement collapses to a point:');
const agreed = S.sunriseSunsetRange(
  buildHours({ low: 40, mid: 91, high: 59 }, {
    21: { low: 40, mid: 91, high: 59, offset_hours: 0 },
    22: { low: 40, mid: 91, high: 59, offset_hours: 0 },
  }), day, 'sunset');
assert('identical readings give zero width', agreed.high === agreed.low);
assert('but still report two sources', 2 === agreed.sources);

console.log('\nMissing data:');
assert('no twilight time returns null',
  null === S.sunriseSunsetRange(buildHours({ low: 0, mid: 0, high: 0 }), { date: DATE, twilight: {} }, 'sunset'));
assert('an empty hourly array returns null',
  null === S.sunriseSunsetRange([], day, 'sunset'));
assert('bandScore of null is null', null === S.bandScore(null));

console.log('\nPHP/JS window coupling:');
// includes/class-api.php populates eventIndex-1 .. eventIndex+2. If the JS
// sampling window is widened past that, the extra hours silently carry no
// met_no key and every card degrades to single-source without an error.
assert('every sampled offset falls inside the PHP window',
  Math.min(...S.MET_NO_SAMPLE_OFFSETS) >= -1 && Math.max(...S.MET_NO_SAMPLE_OFFSETS) <= 2);

console.log(`\n${passed} passed, ${failed} failed`);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node tests/range.test.js`
Expected: `TypeError: S.sunriseSunsetRange is not a function`

- [ ] **Step 3: Replace `sunriseSunsetScore` with `sunriseSunsetRange`**

In `assets/js/forecast-scoring.js`, delete the whole `sunriseSunsetScore` function and put this in its place:

```js
  /**
   * Offsets from the event hour that the score samples.
   *
   * PHP attaches Met.no readings to eventIndex-1 .. eventIndex+2 (see
   * MET_NO_WINDOW_BEFORE in includes/class-api.php). Widening this past that
   * window strands hours without a reading and silently degrades every card
   * to single-source, so tests/range.test.js asserts the two agree.
   */
  const MET_NO_SAMPLE_OFFSETS = [0, 1];

  /**
   * Score a sunrise or sunset against both forecast sources.
   *
   * Met.no's three cloud layers are overlaid on the Open-Meteo hour and the
   * identical formula is run again. Visibility and rain chance stay
   * Open-Meteo's in both variants: only cloud was captured from Met.no, and
   * holding everything else constant means the range measures cloud
   * disagreement and nothing else.
   *
   * Averaging is per source, not per hour. Each source gets its own mean
   * across the sampled hours and the range is min/max of those two means.
   * Taking min/max hour by hour would mix sources and invent a range wider
   * than either source supports.
   *
   * @param {Array}  hourly  Hourly rows.
   * @param {Object} dayData Daily row with date and twilight.
   * @param {string} event   'sunrise' or 'sunset'.
   * @returns {Object|null} {low, high, sources} or null when there is no event.
   */
  function sunriseSunsetRange(hourly, dayData, event) {
    if (!Array.isArray(hourly) || !hourly.length || !dayData) return null;

    const twilight = dayData.twilight || {};
    const timeStr = twilight[event] || null;
    if (!timeStr) return null;

    const eventIndex = findHourIndex(hourly, dayData.date, timeStr);
    if (eventIndex < 0) return null;

    // The glow hour is whichever sits further from midday: after sunset,
    // before sunrise.
    const glowIndex = 'sunset' === event ? eventIndex + 1 : eventIndex;

    let openTotal = 0;
    let metTotal = 0;
    let count = 0;
    let metCount = 0;

    for (const offset of MET_NO_SAMPLE_OFFSETS) {
      const i = eventIndex + offset;
      if (i < 0 || i >= hourly.length) continue;

      const hour = hourly[i];
      const isGlow = i === glowIndex;

      openTotal += scoreLightHour(hour, isGlow);
      count++;

      const met = hour.met_no;
      if (!met) continue;

      metTotal += scoreLightHour(Object.assign({}, hour, {
        cloud_low: met.low ?? hour.cloud_low,
        cloud_mid: met.mid ?? hour.cloud_mid,
        cloud_high: met.high ?? hour.cloud_high,
      }), isGlow);
      metCount++;
    }

    if (!count) return null;

    const open = Math.round(openTotal / count);

    // A range needs Met.no for every sampled hour. Partial coverage would
    // compare a two-hour mean against a one-hour mean, which is not a
    // comparison.
    if (metCount !== count) {
      return { low: open, high: open, sources: 1 };
    }

    const met = Math.round(metTotal / metCount);
    return { low: Math.min(open, met), high: Math.max(open, met), sources: 2 };
  }

  /**
   * The score a range is labelled and coloured by.
   *
   * The low end, so the band word always describes the number the solid arc
   * draws and the two can never contradict. This is the only place that rule
   * lives; changing it here changes every view at once.
   *
   * @param {Object|null} range - A sunriseSunsetRange() result.
   * @returns {number|null} The score to band on.
   */
  function bandScore(range) {
    return range ? range.low : null;
  }
```

- [ ] **Step 4: Update the exports block**

At the bottom of `assets/js/forecast-scoring.js`, in the exported object, replace the `sunriseSunsetScore,` line with:

```js
    sunriseSunsetRange,
    bandScore,
    MET_NO_SAMPLE_OFFSETS,
```

- [ ] **Step 5: Update the import block in the app**

In `assets/js/forecast-app.js`, replace the destructure at lines 34-45 with:

```js
  const {
    parseTimeToTimestamp,
    parseHourTimestamp,
    nowInTimezone,
    findHourIndex,
    getSunlightClass,
    calculatePhotoScore,
    getScoreClass,
    getScoreLabel,
    sunriseSunsetRange,
    bandScore,
  } = ForecastScoring;
```

`calculateWindowScore` is dropped — it was imported and never called, dead since the view rewrite. `sunriseSunsetScore` is dropped because it no longer exists; its two call sites are fixed in Tasks 3 and 4, so the app is briefly broken between here and Task 3. That is expected and the JS tests will say so.

- [ ] **Step 6: Run the new test to verify it passes**

Run: `node tests/range.test.js`
Expected: `17 passed, 0 failed`

- [ ] **Step 7: Negative control — prove the averaging test bites**

Temporarily rewrite the accumulation to take per-hour min/max instead of per-source means. Replace the two `Math.round` lines and the return with:

```js
    // NEGATIVE CONTROL ONLY — do not keep.
    const open = Math.round(openTotal / count);
    const met = Math.round(metTotal / metCount);
    return { low: Math.min(open, met) - 15, high: Math.max(open, met) + 15, sources: 2 };
```

Run: `node tests/range.test.js`
Expected: **FAIL** on `opposite disagreements average out to a narrow range`. If it passes, the fixture does not actually distinguish the two implementations — fix the fixture before continuing.

Restore the correct code and re-run to confirm green.

- [ ] **Step 8: Commit**

```bash
git add assets/js/forecast-scoring.js assets/js/forecast-app.js tests/range.test.js
git commit -m "Score sunrise and sunset against both sources as a range

sunriseSunsetScore becomes sunriseSunsetRange, returning {low, high,
sources}. Met.no's cloud layers are overlaid on the Open-Meteo hour and
the identical formula runs again; visibility and rain stay Open-Meteo's
so the range measures cloud disagreement alone.

bandScore() isolates the 'label the low' rule to one function. Also drops
the calculateWindowScore import, dead since the view rewrite.

Renderers still call the old name and are fixed in the next two commits.

Spec: docs/superpowers/specs/2026-09-01-dual-source-confidence-design.md"
```

---

### Task 3: Outlook view — ring tail, dashed track, aria, global notice

**Files:**
- Modify: `assets/js/forecast-app.js` (`renderScoreRing` at `:1033`, `renderOutlookCard` at `:1055`, `renderOutlookView` at `:1105`; add `formatString` helper)
- Modify: `assets/css/forecast-app.css` (after `:1841`)
- Modify: `templates/pwa-app.php` (strings block, near `:201`)
- Test: `tests/outlook.test.js` (extend), `tests/harness.js` (extend)

**Interfaces:**
- Consumes: `sunriseSunsetRange`, `bandScore`, `MET_NO_SAMPLE_OFFSETS` from Task 2. Payload key `met_no_available` from Task 1.
- Produces: `renderScoreRing( range )` — **signature changed, now takes the range object rather than a number.** Task 4 does not use it (the day hero uses a meter), so this is the only consumer.

- [ ] **Step 1: Add the harness support for Met.no fixtures**

In `tests/harness.js`, `buildForecast()` currently builds hours with no `met_no`. Add an option. Inside the `for (let h = 0; h < 24; h++)` loop, after the `hourly.push({...})` call, the pushed object is the last element, so append:

```js
      if (options.metNoSkies && options.metNoSkies[d]) {
        const m = options.metNoSkies[d];
        hourly[hourly.length - 1].met_no = {
          low: m.low, mid: m.mid, high: m.high,
          total: Math.max(m.low, m.mid, m.high), offset_hours: 0,
        };
      }
```

And in the returned object, replace `hourly, daily, moon: {},` with:

```js
    hourly, daily, moon: {},
    met_no_available: options.metNoAvailable !== false,
```

Then add the new strings to `STRINGS` in the same file:

```js
  cloudBySource: 'Cloud by source', sourceOpenMeteo: 'Open-Meteo', sourceMetNo: 'Met.no',
  secondSourceUnavailable: 'Second forecast source unavailable',
  oneSource: 'one source', twoSources: 'two sources',
  scoreRange: '%1$s to %2$s percent',
  low: 'Low', mid: 'Mid', high: 'High',
```

- [ ] **Step 2: Write the failing test**

Append to `tests/outlook.test.js`, before its final summary line:

```js
// --- Dual-source ranges ---------------------------------------------------
{
  const { install, buildForecast } = require('./harness.js');

  // Open-Meteo sees low cloud everywhere; Met.no sees a clear horizon.
  const ranged = install({
    forecast: buildForecast({
      skies: new Array(7).fill({ low: 40, mid: 91, high: 59 }),
      metNoSkies: new Array(7).fill({ low: 8, mid: 70, high: 61 }),
    }),
  });
  ranged.tabs.outlook();

  ranged.section('Outlook card, sources disagree:');
  ranged.assert('draws a tail arc', ranged.rendered.includes('score-ring-tail'));
  ranged.assert('the ring is marked as carrying a range', ranged.rendered.includes('score-ring has-range'));
  ranged.assert('the text is a range, not a percentage',
    /<text class="score-ring-text"[^>]*>\d+&#8211;\d+<\/text>|<text class="score-ring-text"[^>]*>\d+–\d+<\/text>/.test(ranged.rendered));
  ranged.assert('the track is not dashed', !ranged.rendered.includes('is-single-source'));
  ranged.assert('the aria label names two sources', ranged.rendered.includes('two sources'));
  ranged.assert('the aria label spells out the range', ranged.rendered.includes('to') && / \d+ to \d+ percent/.test(ranged.rendered));
  ranged.assert('no global notice when the source is available',
    !ranged.rendered.includes('outlook-notice'));

  // Both sources identical: the range must collapse.
  const agreed = install({
    forecast: buildForecast({
      skies: new Array(7).fill({ low: 40, mid: 91, high: 59 }),
      metNoSkies: new Array(7).fill({ low: 40, mid: 91, high: 59 }),
    }),
  });
  agreed.tabs.outlook();

  agreed.section('Outlook card, sources agree:');
  agreed.assert('draws no tail arc', !agreed.rendered.includes('score-ring-tail'));
  agreed.assert('the text is a plain percentage', /<text class="score-ring-text"[^>]*>\d+%<\/text>/.test(agreed.rendered));
  agreed.assert('the track is not dashed', !agreed.rendered.includes('is-single-source'));
  agreed.assert('but the aria label still says two sources', agreed.rendered.includes('two sources'));

  // Met.no unavailable.
  const solo = install({
    forecast: buildForecast({
      skies: new Array(7).fill({ low: 40, mid: 91, high: 59 }),
      metNoAvailable: false,
    }),
  });
  solo.tabs.outlook();

  solo.section('Outlook, second source unavailable:');
  solo.assert('the track is dashed', solo.rendered.includes('is-single-source'));
  solo.assert('the aria label says one source', solo.rendered.includes('one source'));
  solo.assert('and never says two', !solo.rendered.includes('two sources'));
  solo.assert('a global notice appears once', solo.rendered.includes('outlook-notice'));
  solo.assert('the notice explains why',
    solo.rendered.includes('Second forecast source unavailable'));

  // A payload from before this feature: no met_no keys, no met_no_available.
  const legacyForecast = buildForecast({ skies: new Array(7).fill({ low: 40, mid: 91, high: 59 }) });
  delete legacyForecast.met_no_available;
  const legacy = install({ forecast: legacyForecast });
  legacy.tabs.outlook();

  legacy.section('Outlook, cached payload from before this feature:');
  legacy.assert('renders without throwing', legacy.rendered.includes('outlook-card'));
  legacy.assert('shows a plain percentage', /<text class="score-ring-text"[^>]*>\d+%<\/text>/.test(legacy.rendered));
  legacy.assert('shows no global notice', !legacy.rendered.includes('outlook-notice'));
  legacy.assert('but marks the cards single-source', legacy.rendered.includes('is-single-source'));
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `node tests/outlook.test.js`
Expected: FAIL — the app throws because `sunriseSunsetScore` no longer exists after Task 2.

- [ ] **Step 4: Add the `formatString` helper**

In `assets/js/forecast-app.js`, beside the other small helpers (immediately before `scoreBandLabel`), add:

```js
  /**
   * Fill a translated string's positional placeholders.
   *
   * Translators reorder arguments, so the pattern uses %1$s / %2$s rather
   * than bare %s.
   *
   * @param {string} pattern - Pattern containing %1$s style placeholders.
   * @param {...*} args - Values, in order.
   * @returns {string} The filled string.
   */
  function formatString(pattern, ...args) {
    return String(pattern).replace(/%(\d+)\$s/g, (match, n) => {
      const value = args[Number(n) - 1];
      return undefined === value ? match : String(value);
    });
  }
```

- [ ] **Step 5: Rewrite `renderScoreRing`**

Replace the whole function at `assets/js/forecast-app.js:1033` with:

```js
  /**
   * Render a score range as a ring.
   *
   * The solid arc always starts at zero and runs to the low score, so
   * fullness still reads as "how good" — a tight range high up the scale
   * still draws a nearly full ring. The faded tail extends to the high
   * score, so its length reads as "how unsure". When the sources agree
   * there is no tail and the result is identical to a point score.
   *
   * @param {Object} range - A sunriseSunsetRange() result.
   * @returns {string} SVG markup.
   */
  function renderScoreRing(range) {
    const { low, high, sources } = range;
    const isRange = high > low;
    // r chosen so the circumference is 100 and stroke-dasharray takes the
    // score directly.
    return `
      <svg class="score-ring${isRange ? ' has-range' : ''}" viewBox="0 0 36 36" aria-hidden="true" focusable="false">
        <circle class="score-ring-track${1 === sources ? ' is-single-source' : ''}" cx="18" cy="18" r="15.915" fill="none" stroke-width="3"></circle>
        ${isRange ? `<circle class="score-ring-tail" cx="18" cy="18" r="15.915" fill="none" stroke-width="3"
          stroke-dasharray="${high - low} 100" stroke-dashoffset="-${low}" transform="rotate(-90 18 18)"></circle>` : ''}
        <circle class="score-ring-value" cx="18" cy="18" r="15.915" fill="none" stroke-width="3"
          stroke-dasharray="${low} 100" stroke-linecap="round" transform="rotate(-90 18 18)"></circle>
        <text class="score-ring-text" x="18" y="18" text-anchor="middle" dominant-baseline="central">${isRange ? `${low}–${high}` : `${low}%`}</text>
      </svg>
    `;
  }
```

- [ ] **Step 6: Update `renderOutlookCard`**

In `assets/js/forecast-app.js`, replace everything from `const score = sunriseSunsetScore(...)` to the end of the function with:

```js
    const range = sunriseSunsetRange(forecast.hourly, day, event);
    if (null === range) {
      return `
        <div class="outlook-card is-empty">
          <span class="outlook-card-time">${escapeHtml(time)}</span>
        </div>
      `;
    }

    const band = bandScore(range);
    const label = scoreBandLabel(band);
    // Agreement and single-source both draw a bare number. The dashed track
    // separates them visually; only these words separate them for a screen
    // reader, which never sees the ring.
    const value = range.high > range.low
      ? formatString(strings.scoreRange || '%1$s to %2$s percent', range.low, range.high)
      : `${band} percent`;
    const sourceNote = 2 === range.sources
      ? (strings.twoSources || 'two sources')
      : (strings.oneSource || 'one source');
    const aria = `${eventName} ${dayLabel(day.date, dayIndex)} ${time}, ${label}, ${value}, ${sourceNote}`;

    return `
      <button class="outlook-card ${getScoreClass(band)}" data-action="open-day"
        data-day="${dayIndex}" data-event="${event}" aria-label="${escapeHtml(aria)}">
        <span class="outlook-card-band">${escapeHtml(label)}</span>
        ${renderScoreRing(range)}
        <span class="outlook-card-time">${escapeHtml(time)}</span>
      </button>
    `;
```

- [ ] **Step 7: Add the global notice to `renderOutlookView`**

In `renderOutlookView`, insert the notice immediately after `<div class="outlook-view">`:

```js
        ${false === forecast.met_no_available ? `<p class="outlook-notice">${escapeHtml(strings.secondSourceUnavailable || 'Second forecast source unavailable')}</p>` : ''}
```

The `false ===` comparison is deliberate: a cached payload from before this feature has `met_no_available` undefined and must show no notice.

- [ ] **Step 8: Add the CSS**

In `assets/css/forecast-app.css`, immediately after the `.outlook-card.score-poor .score-ring-value` line at `:1841`, add:

```css
/* The faded tail spans the low score to the high score. Same band colour as
   the solid arc, so a range never introduces a second hue. */
.outlook-card.score-excellent .score-ring-tail { stroke: var(--color-excellent); }
.outlook-card.score-good .score-ring-tail      { stroke: var(--color-good); }
.outlook-card.score-fair .score-ring-tail      { stroke: var(--color-fair); }
.outlook-card.score-poor .score-ring-tail      { stroke: var(--color-poor); }

.score-ring-tail {
  stroke-opacity: 0.35;
}

/* A dashed track means only one source was available for this card, whether
   because Met.no was unreachable or because it had no sample within three
   hours of this event. */
.score-ring-track.is-single-source {
  stroke-dasharray: 2 2;
}

/* "37-75" is five glyphs where "100%" is four, against an inner diameter of
   about 28.8 units in the 36-unit viewBox. */
.score-ring.has-range .score-ring-text {
  font-size: 8px;
}

.outlook-notice {
  margin: 0 0 var(--spacing-sm);
  padding: var(--spacing-xs) var(--spacing-sm);
  border-radius: 6px;
  background: var(--bg-tertiary);
  color: var(--text-secondary);
  font-size: var(--font-size-sm);
}
```

Token names are verified against the definitions at `forecast-app.css:17-70`: the spacing scale is `--spacing-*`, not `--space-*`, and there is no radius scale at all. (`--radius-md` is used at `:1564` but never defined — a pre-existing bug where that border-radius silently resolves to nothing. Out of scope here; do not fix it in this branch.)

- [ ] **Step 9: Add the strings**

In `templates/pwa-app.php`, after the `photoScore` line near `:201`, add:

```php
				// Dual-source confidence
				cloudBySource: <?php echo wp_json_encode( __( 'Cloud by source', 'cloud-cover-forecast' ) ); ?>,
				sourceOpenMeteo: <?php echo wp_json_encode( __( 'Open-Meteo', 'cloud-cover-forecast' ) ); ?>,
				sourceMetNo: <?php echo wp_json_encode( __( 'Met.no', 'cloud-cover-forecast' ) ); ?>,
				secondSourceUnavailable: <?php echo wp_json_encode( __( 'Second forecast source unavailable', 'cloud-cover-forecast' ) ); ?>,
				oneSource: <?php echo wp_json_encode( __( 'one source', 'cloud-cover-forecast' ) ); ?>,
				twoSources: <?php echo wp_json_encode( __( 'two sources', 'cloud-cover-forecast' ) ); ?>,
				/* translators: 1: low score, 2: high score. */
				scoreRange: <?php echo wp_json_encode( __( '%1$s to %2$s percent', 'cloud-cover-forecast' ) ); ?>,
```

- [ ] **Step 10: Run the tests**

Run: `node tests/outlook.test.js`
Expected: all assertions pass, including the four legacy-payload ones.

Run: `./tests/run.sh`
Expected: `ALL TESTS PASSED` under both `TZ=UTC` and `TZ=Pacific/Auckland`.

- [ ] **Step 11: Commit**

```bash
git add assets/js/forecast-app.js assets/css/forecast-app.css templates/pwa-app.php tests/outlook.test.js tests/harness.js
git commit -m "Show score ranges on the Outlook cards

The ring fills solidly to the low score and continues as a faded arc to
the high score, so fullness still reads as quality and the tail length
reads as uncertainty. Agreement draws no tail and is identical to today.

A single-source card draws a dashed track. Because a screen reader never
sees that, the aria label also distinguishes 'one source' from 'two
sources' -- a bare number must not be ambiguous for either audience.

Payloads cached before this change have no met_no_available key and are
tested to render as single-source rather than throw.

Spec: docs/superpowers/specs/2026-09-01-dual-source-confidence-design.md"
```

---

### Task 4: Day view — meter range and the "Cloud by source" panel

**Files:**
- Modify: `assets/js/forecast-app.js` (`renderDayHero` at `:1201`; add `renderCloudBySource`; call it from `renderDayView`)
- Modify: `assets/css/forecast-app.css` (after `:1930`)
- Test: `tests/day.test.js` (extend)

**Interfaces:**
- Consumes: `sunriseSunsetRange`, `bandScore` from Task 2; `findHourIndex` and `formatString`, both already imported/defined; `strings.low`, `strings.mid`, `strings.high`, which already exist for the Hours grid.
- Produces: `renderCloudBySource( forecast, day, event )` → HTML string, empty when the event hour carries no `met_no`.

- [ ] **Step 1: Write the failing test**

Append to `tests/day.test.js`, before its summary line:

```js
// --- Dual-source day view -------------------------------------------------
{
  const { install, buildForecast } = require('./harness.js');

  const ranged = install({
    forecast: buildForecast({
      skies: new Array(7).fill({ low: 40, mid: 91, high: 59 }),
      metNoSkies: new Array(7).fill({ low: 8, mid: 70, high: 61 }),
    }),
  });
  ranged.tabs.outlook();
  ranged.click({ action: 'open-day', day: '0', event: 'sunset' });

  ranged.section('Day hero, sources disagree:');
  ranged.assert('draws a tail fill', ranged.rendered.includes('day-hero-meter-tail'));
  ranged.assert('the meter label is a range', /day-hero-meter-fill[^>]*>\s*<span>\d+(&#8211;|–)\d+<\/span>/.test(ranged.rendered));
  ranged.assert('the aria label names two sources', ranged.rendered.includes('two sources'));

  ranged.section('Cloud by source panel:');
  ranged.assert('the panel is rendered', ranged.rendered.includes('cloud-by-source'));
  ranged.assert('it names both sources',
    ranged.rendered.includes('Open-Meteo') && ranged.rendered.includes('Met.no'));
  ranged.assert('it shows the Open-Meteo low cloud reading', ranged.rendered.includes('>40%<'));
  ranged.assert('it shows the Met.no low cloud reading', ranged.rendered.includes('>8%<'));
  ranged.assert('the heading does not assert disagreement',
    ranged.rendered.includes('Cloud by source') && !ranged.rendered.includes('Sources disagree'));

  const agreed = install({
    forecast: buildForecast({
      skies: new Array(7).fill({ low: 40, mid: 91, high: 59 }),
      metNoSkies: new Array(7).fill({ low: 40, mid: 91, high: 59 }),
    }),
  });
  agreed.tabs.outlook();
  agreed.click({ action: 'open-day', day: '0', event: 'sunset' });

  agreed.section('Day view, sources agree:');
  agreed.assert('draws no tail fill', !agreed.rendered.includes('day-hero-meter-tail'));
  agreed.assert('but still shows the comparison panel', agreed.rendered.includes('cloud-by-source'));

  const solo = install({
    forecast: buildForecast({
      skies: new Array(7).fill({ low: 40, mid: 91, high: 59 }),
      metNoAvailable: false,
    }),
  });
  solo.tabs.outlook();
  solo.click({ action: 'open-day', day: '0', event: 'sunset' });

  solo.section('Day view, second source unavailable:');
  solo.assert('draws no tail fill', !solo.rendered.includes('day-hero-meter-tail'));
  solo.assert('shows no comparison panel', !solo.rendered.includes('cloud-by-source'));
  solo.assert('the aria label says one source', solo.rendered.includes('one source'));
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node tests/day.test.js`
Expected: FAIL — `renderDayHero` still calls `sunriseSunsetScore`, which no longer exists.

- [ ] **Step 3: Rewrite `renderDayHero`**

In `assets/js/forecast-app.js`, replace from `const score = sunriseSunsetScore(...)` to the end of `renderDayHero` with:

```js
    const range = sunriseSunsetRange(forecast.hourly, day, event);
    const relative = relativeTime(parseTimeToTimestamp(day.date, time, timezone));

    if (null === range) {
      return `
        <div class="day-hero">
          <h2 class="day-hero-title">${escapeHtml(name)}</h2>
          <p class="day-hero-time">${escapeHtml(time)}</p>
          ${relative ? `<p class="day-hero-relative">${escapeHtml(relative)}</p>` : ''}
        </div>
      `;
    }

    const band = bandScore(range);
    const label = scoreBandLabel(band);
    const isRange = range.high > range.low;
    const text = isRange ? `${range.low}–${range.high}` : `${band}%`;
    const value = isRange
      ? formatString(strings.scoreRange || '%1$s to %2$s percent', range.low, range.high)
      : `${band} percent`;
    const sourceNote = 2 === range.sources
      ? (strings.twoSources || 'two sources')
      : (strings.oneSource || 'one source');

    return `
      <div class="day-hero ${getScoreClass(band)}">
        <h2 class="day-hero-title">${escapeHtml(name)}</h2>
        <p class="day-hero-band">${escapeHtml(label)}</p>
        <div class="day-hero-meter" role="img" aria-label="${escapeHtml(`${label}, ${value}, ${sourceNote}`)}">
          ${isRange ? `<div class="day-hero-meter-tail" style="width: ${range.high}%"></div>` : ''}
          <div class="day-hero-meter-fill" style="width: ${range.low}%"><span>${text}</span></div>
        </div>
        <p class="day-hero-time">${escapeHtml(time)}</p>
        ${relative ? `<p class="day-hero-relative">${escapeHtml(relative)}</p>` : ''}
      </div>
    `;
```

The tail runs 0→high behind the solid 0→low fill, rather than spanning low→high. Visually identical, and it avoids positioning a floated segment inside a flow layout.

- [ ] **Step 4: Add `renderCloudBySource`**

Immediately after `renderDayHero` in `assets/js/forecast-app.js`:

```js
  /**
   * What each source read at the event hour.
   *
   * The heading is "Cloud by source" rather than "Sources disagree": the
   * latter is wrong on the days they agree, and knowing that both sources
   * see a clear horizon is worth as much as knowing they differ.
   *
   * @param {Object} forecast - Forecast data.
   * @param {Object} day - Daily data.
   * @param {string} event - 'sunrise' or 'sunset'.
   * @returns {string} HTML string, empty when there is no second reading.
   */
  function renderCloudBySource(forecast, day, event) {
    const time = (day.twilight || {})[event];
    if (!time) return '';

    const index = findHourIndex(forecast.hourly || [], day.date, time);
    if (index < 0) return '';

    const hour = forecast.hourly[index];
    const met = hour && hour.met_no;
    if (!met) return '';

    const rows = [
      [strings.low || 'Low', hour.cloud_low, met.low],
      [strings.mid || 'Mid', hour.cloud_mid, met.mid],
      [strings.high || 'High', hour.cloud_high, met.high],
    ];

    return `
      <section class="cloud-by-source">
        <h3 class="cloud-by-source-title">${escapeHtml(strings.cloudBySource || 'Cloud by source')}</h3>
        <table class="cloud-by-source-table">
          <thead>
            <tr>
              <th scope="col"></th>
              <th scope="col">${escapeHtml(strings.sourceOpenMeteo || 'Open-Meteo')}</th>
              <th scope="col">${escapeHtml(strings.sourceMetNo || 'Met.no')}</th>
            </tr>
          </thead>
          <tbody>
            ${rows.map(([label, open, metValue]) => `
              <tr>
                <th scope="row">${escapeHtml(label)}</th>
                <td>${null == open ? '&mdash;' : `${open}%`}</td>
                <td>${null == metValue ? '&mdash;' : `${metValue}%`}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </section>
    `;
  }
```

- [ ] **Step 5: Call it from `renderDayView`**

Find `renderDayView` in `assets/js/forecast-app.js` and insert the panel between the hero and the phase list — immediately after the `${renderDayHero(...)}` interpolation:

```js
        ${renderCloudBySource(forecast, day, event)}
```

Match the existing argument names at that call site; if the hero is called with different local variable names, use those.

- [ ] **Step 6: Add the CSS**

In `assets/css/forecast-app.css`, after the `.day-hero.score-poor .day-hero-meter-fill` line at `:1930`, add:

```css
/* The tail runs from zero to the high score behind the solid fill, so the
   solid portion reads as the score and the pale remainder as the upside the
   second source sees. */
.day-hero-meter {
  position: relative;
}

.day-hero-meter-tail {
  position: absolute;
  top: 0;
  left: 0;
  height: 100%;
  opacity: 0.35;
  border-radius: inherit;
}

.day-hero.score-excellent .day-hero-meter-tail { background: var(--color-excellent); }
.day-hero.score-good      .day-hero-meter-tail { background: var(--color-good); }
.day-hero.score-fair      .day-hero-meter-tail { background: var(--color-fair); }
.day-hero.score-poor      .day-hero-meter-tail { background: var(--color-poor); }

.day-hero-meter-fill {
  position: relative;
}

.cloud-by-source {
  margin: var(--spacing-md) 0;
}

.cloud-by-source-title {
  margin: 0 0 var(--spacing-xs);
  font-size: var(--font-size-sm);
  font-weight: 600;
  color: var(--text-secondary);
}

.cloud-by-source-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--font-size-sm);
}

.cloud-by-source-table th,
.cloud-by-source-table td {
  padding: var(--spacing-xs);
  text-align: right;
  border-bottom: 1px solid var(--border-color);
}

.cloud-by-source-table th[scope="row"] {
  text-align: left;
  font-weight: 400;
  color: var(--text-secondary);
}
```

`.day-hero-meter-fill` needs `position: relative` so it paints above the absolutely positioned tail. Token names are verified against `forecast-app.css:17-70`.

- [ ] **Step 7: Run the tests**

Run: `node tests/day.test.js`
Expected: all assertions pass.

Run: `./tests/run.sh`
Expected: `ALL TESTS PASSED`

- [ ] **Step 8: Commit**

```bash
git add assets/js/forecast-app.js assets/css/forecast-app.css tests/day.test.js
git commit -m "Show the range and the per-source readings in the day view

The day hero meter gains a pale fill to the high score behind the solid
fill to the low score, the bar-shaped equivalent of the Outlook ring's
faded tail.

A 'Cloud by source' table under the hero shows what each source read at
the event hour, so a range is explicable rather than arbitrary next to
the Hours grid's single numbers. The heading does not assert
disagreement, because it renders on the days they agree too.

Spec: docs/superpowers/specs/2026-09-01-dual-source-confidence-design.md"
```

---

### Task 5: Version bump, documentation, manual verification

**Files:**
- Modify: `cloud-cover-forecast.php:25`
- Modify: `readme.txt`
- Modify: `reference.md`
- Modify: `tests/README.md`

**Interfaces:**
- Consumes: everything from Tasks 1-4. Produces no code interface.

- [ ] **Step 1: Bump the version**

In `cloud-cover-forecast.php`, change line 25:

```php
define( 'CLOUD_COVER_FORECAST_VERSION', '1.2.0' );
```

Also update the `Version:` header at the top of the same file to `1.2.0`, and the `Stable tag:` in `readme.txt`.

Without this, asset URLs carrying `?v=CLOUD_COVER_FORECAST_VERSION` are unchanged and browsers serve the old JS and CSS from HTTP cache. This has bitten this project before.

- [ ] **Step 2: Update `reference.md`**

Per `CLAUDE.md`, record:

- New constants: `MET_NO_WINDOW_BEFORE`, `MET_NO_WINDOW_AFTER`, `MET_NO_MAX_OFFSET` in `Cloud_Cover_Forecast_API`.
- New methods: `attach_met_no_readings()`, `met_no_hour_indices()`.
- Changed: `fetch_extended_forecast()` now calls `fetch_met_no_complete()`, so the PWA path is dual-source. Note explicitly that it does **not** use `merge_cloud_cover_rows()`, which remains shortcode/block-only.
- New payload fields: `hourly[].met_no`, top-level `met_no_available`.
- JS: `sunriseSunsetScore` renamed to `sunriseSunsetRange` with a changed return type; `bandScore` and `MET_NO_SAMPLE_OFFSETS` added; `calculateWindowScore` import removed from `forecast-app.js`.
- New test files: `tests/dual-source.test.php`, `tests/range.test.js`.
- A changelog entry for 1.2.0.

- [ ] **Step 3: Update `tests/README.md`**

Add to the "what the suite does not cover" section:

```markdown
- The ring tail, the dashed single-source track and the day-hero tail fill
  are asserted as markup only. No CSS is rendered anywhere in this suite, so
  whether the tail is actually visible, whether `stroke-dashoffset` puts it
  in the right place, and whether it has enough contrast in dark mode are all
  browser-only questions.
- No test checks that a CSS custom property referenced by a rule is actually
  defined. `--radius-md` has been referenced at `forecast-app.css:1564` and
  undefined for the whole life of the file, and the suite is green. A typo in
  a token name fails silently and always will.
```

- [ ] **Step 4: Run the full suite**

Run: `./tests/run.sh`
Expected: `ALL TESTS PASSED`

- [ ] **Step 5: Verify in a browser — the suite cannot do this**

Deploy and check each of these. The suite renders no CSS, so every visual claim in this plan is unverified until now:

- [ ] an Outlook card where the sources disagree: solid arc to the low score, visible faded tail beyond it, `37–75` legible at 8px
- [ ] a card where the sources agree: indistinguishable from the current build
- [ ] a card that is single-source: dashed track, clearly different from the agreeing card
- [ ] all three in **dark mode** and in **light mode** — the tail is 35% opacity over a dark ground and may vanish
- [ ] the global notice with Met.no unreachable (temporarily point `fetch_met_no_complete()` at an invalid host)
- [ ] the day hero meter with a range, and the "Cloud by source" table
- [ ] the whole Outlook grid at a narrow width — the ring text is the tightest fit in the layout

- [ ] **Step 6: Commit**

```bash
git add cloud-cover-forecast.php readme.txt reference.md tests/README.md
git commit -m "Bump to 1.2.0 and document dual-source confidence

Version bump is load-bearing: asset URLs carry
?v=CLOUD_COVER_FORECAST_VERSION and browsers serve stale JS and CSS
without it.

Records in tests/README.md that the ring tail and dashed track are
asserted as markup only -- the suite renders no CSS, so their visibility
and dark-mode contrast remain browser-only questions.

Spec: docs/superpowers/specs/2026-09-01-dual-source-confidence-design.md"
```

---

## Self-Review

**Spec coverage.** Every spec section maps to a task: PHP data flow and the hour-key trap → Task 1; the JS range, per-source averaging, full-coverage rule and `bandScore` → Task 2; ring, dashed track, global notice, aria and strings → Task 3; day hero meter and "Cloud by source" → Task 4; version bump, `reference.md`, manual browser checks → Task 5. The spec's five failure modes are covered by tests in Tasks 1, 2, 3 and 4. The dead `calculateWindowScore` import is removed in Task 2. The PHP/JS coupling mitigation is in Task 1 (window slack, cross-referencing comments) and Task 2 (the assertion).

**Deliberate deviation.** `attach_met_no_readings()` takes an already-fetched Met.no map rather than fetching, so the PHP tests need no network and no WordPress. Documented above.

**Type consistency.** `sunriseSunsetRange` returns `{low, high, sources}` in Task 2 and is destructured with those names in Tasks 3 and 4. `bandScore(range)` returns a number and is passed to `getScoreClass` and `scoreBandLabel`, both of which take numbers and are unchanged. `renderScoreRing` takes the range object in Task 3 and has exactly one caller. `met_no` carries `total/low/mid/high/offset_hours` in Task 1 and is read with those names in Tasks 2 and 4. `MET_NO_SAMPLE_OFFSETS = [0, 1]` in JS sits inside PHP's `-1..+2` window, which is what the Task 2 assertion checks.

**Known unverified.** Every visual claim. The suite renders no CSS, so the ring tail's position, the dashed track's legibility at 46px, and dark-mode contrast on a 35%-opacity stroke are settled only by Task 5, Step 5. The CSS token names in Tasks 3 and 4 were checked against `forecast-app.css:17-70` while writing this plan; nothing will re-check them if the stylesheet changes.
