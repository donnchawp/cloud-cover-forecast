# Dual-Source Cleanup Plan

Cleanup pass on `feat/dual-source-confidence` (v1.2.0, 8 commits, suite green,
not merged). Findings 1-5 from a `/simplify` review. **Quality only — no
behaviour changes.** Every item must leave `./tests/run.sh` reporting
`ALL TESTS PASSED`.

Line numbers are as at commit `1c472a9` and will drift as edits land. Work the
tasks in order; each is independently committable.

## Context that is easy to lose

The feature scores each sunrise/sunset against Open-Meteo and Met.no and shows
the span as a range `{low, high, sources}`. **Only high cloud is compared** —
the providers define their layers over different altitudes (Open-Meteo low is
0-3 km, Met.no 0-2 km), so low and mid are not the same measurement and stay
Open-Meteo's. Do not "restore" the low/mid substitution while simplifying;
`tests/range.test.js` has a test named *"Met.no low and mid cloud are
deliberately ignored"* that exists to stop exactly that.

Verification command throughout: `./tests/run.sh` (node + php, no deps, runs
the JS tests under `TZ=UTC` and `TZ=Pacific/Auckland`).

---

## Task 1: Use the existing timezone helper

**File:** `includes/class-api.php:1204`

`attach_met_no_readings()` inlines its own parse. The class already has
`to_timestamp_in_timezone( string $time_string, DateTimeZone $timezone ): ?int`
at `:1711`, with five existing callers, doing the same job with the same
`catch ( Exception )`.

- [ ] **Step 1: Replace the inline parse**

Current:

```php
			// The stamp carries no offset, so the zone must be supplied.
			// Doing this with strtotime() or gmdate() uses the server's own
			// timezone and is wrong by exactly that offset.
			try {
				$target = ( new DateTimeImmutable( $stamp, $tz ) )->getTimestamp();
			} catch ( Exception $e ) {
				continue;
			}
```

Replace with:

```php
			// The stamp carries no offset, so the zone must be supplied.
			// Doing this with strtotime() or gmdate() uses the server's own
			// timezone and is wrong by exactly that offset.
			$target = $this->to_timestamp_in_timezone( $stamp, $tz );
			if ( null === $target ) {
				continue;
			}
```

`$tz` is already built above at `:1180`. Keep the comment — it is the reason
the DST negative control exists.

- [ ] **Step 2: Verify**

`php tests/dual-source.test.php` → `14 passed, 0 failed`.

The helper uses `DateTime` where the removed code used `DateTimeImmutable`.
Both resolve a zone-qualified wall-clock stamp identically; the DST assertions
in `tests/dual-source.test.php` are what prove it. If the IST/GMT assertions
fail, stop — do not paper over it.

- [ ] **Step 3: Re-run the DST negative control**

Temporarily change the helper call to `strtotime( $stamp . 'Z' )`. Expect
**FAIL** on `IST 20:00 local matches the 19:00 UTC sample` and PASS on the GMT
one. Restore and confirm green. If it does not fail, the test stopped testing.

- [ ] **Step 4: Commit**

```bash
git add includes/class-api.php
git commit -m "Use the existing timezone helper in attach_met_no_readings

to_timestamp_in_timezone() at class-api.php:1711 already does this, with
five callers. The inline DateTimeImmutable gave the class two answers to
'parse a local wall-clock stamp', so a future fix to the shared one would
have silently missed this path."
```

---

## Task 2: Use `formatValue()` in the Cloud by source table

**File:** `assets/js/forecast-app.js:1335-1336`

The table renders `&mdash;` for a missing reading. Every other cloud
percentage in the app goes through `formatValue()` (`:1852`), which renders
`-`. Same absent reading, two glyphs.

```js
  function formatValue(value, suffix = '', decimals = 0) {
    if (value == null) return '-';
    const formatted = decimals > 0 ? value.toFixed(decimals) : Math.round(value);
    return formatted + suffix;
  }
```

- [ ] **Step 1: Replace both cells**

Current:

```js
                <td>${null == open ? '&mdash;' : `${open}%`}<span class="cloud-by-source-band">${openBand}</span></td>
                <td>${null == metValue ? '&mdash;' : `${metValue}%`}<span class="cloud-by-source-band">${metBand}</span></td>
```

Replace with:

```js
                <td>${formatValue(open, '%')}<span class="cloud-by-source-band">${openBand}</span></td>
                <td>${formatValue(metValue, '%')}<span class="cloud-by-source-band">${metBand}</span></td>
```

Leave `:1087` (`outlook-card is-empty`) alone — that is an em-dash used as a
decorative placeholder for a whole card, not a missing data value.

- [ ] **Step 2: Verify**

`node tests/day.test.js`. The assertions `it shows the Open-Meteo low cloud
reading` and `it shows the Met.no low cloud reading` match `>1%<span` and
`>5%<span`, which `formatValue` still produces. Expect no change.

- [ ] **Step 3: Add a null-reading assertion**

The existing tests never exercise a null cloud value in this table, so nothing
would have caught the glyph mismatch. In `tests/day.test.js`, inside
`runDualSourceChecks`, after the existing "Cloud by source panel" block:

```js
    // A missing Met.no layer must render the same placeholder the rest of
    // the app uses. Nothing caught the em-dash because no test had a null.
    const nulled = install({
      forecast: buildForecast({
        skies: fill(OPEN_METEO_SKY),
        metNoSkies: fill({ low: null, mid: 100, high: 2 }),
      }),
    });
    await tick();
    nulled.tabs.outlook();
    nulled.click({ action: 'open-day', day: '0', event: 'sunset' });

    t.section('Cloud by source, a layer missing:');
    t.assert('renders the app-wide placeholder, not a stray em-dash',
      nulled.rendered.includes('>-<span') && !nulled.rendered.includes('&mdash;<span'));
```

Run it against the OLD markup first if you still have it staged — it must fail
on `&mdash;`. Then apply Step 1 and confirm it passes.

- [ ] **Step 4: Commit**

```bash
git add assets/js/forecast-app.js tests/day.test.js
git commit -m "Render missing cloud readings with formatValue

The new table printed an em-dash where the rest of the app prints '-'.
Adds the null-layer test that should have caught it; no existing test
put a null in that table."
```

---

## Task 3: Extract `describeRange()`

**Files:** `assets/js/forecast-app.js:1113-1124` and `:1257-1269`

`renderOutlookCard` and `renderDayHero` derive the same values independently,
including the fallback literals `'%1$s to %2$s percent'`, `'two sources'` and
`'one source'` written out twice. `bandScore()`'s docblock claims the labelling
rule lives in one place; the phrasing around it does not.

Three of the four review agents flagged this independently.

- [ ] **Step 1: Add the helper**

In `assets/js/forecast-app.js`, immediately after `scoreBandLabel()`:

```js
  /**
   * Everything the views need to present a score range.
   *
   * Both renderers derived these independently, including the translated
   * fallbacks, so the accessible phrasing and the en-dash display rule each
   * lived in two places and could drift. bandScore() centralises which end of
   * the range is banded on; this centralises how the range is spoken and
   * written.
   *
   * @param {Object} range - A sunriseSunsetRange() result.
   * @returns {Object} {band, label, isRange, text, value, sourceNote}.
   */
  function describeRange(range) {
    const band = bandScore(range);
    const isRange = range.high > range.low;
    return {
      band,
      label: scoreBandLabel(band),
      isRange,
      // Display form: an en-dash span, or a plain percentage when the
      // sources agree.
      text: isRange ? `${range.low}&#8211;${range.high}` : `${band}%`,
      // Spoken form. Agreement and single-source both draw a bare number;
      // the dashed track separates them visually, and sourceNote is the only
      // thing that separates them for a screen reader.
      value: isRange
        ? formatString(strings.scoreRange || '%1$s to %2$s percent', range.low, range.high)
        : `${band} percent`,
      sourceNote: 2 === range.sources
        ? (strings.twoSources || 'two sources')
        : (strings.oneSource || 'one source'),
    };
  }
```

- [ ] **Step 2: Use it in `renderOutlookCard`**

Replace lines `1113-1124` (from `const band = bandScore(range);` through the
`const aria = ...` line) with:

```js
    const { band, label, value, sourceNote } = describeRange(range);
    const aria = `${eventName} ${dayLabel(day.date, dayIndex)} ${time}, ${label}, ${value}, ${sourceNote}`;
```

The rest of the function already uses `band` and `label`; leave it.

- [ ] **Step 3: Use it in `renderDayHero`**

Replace lines `1257-1269` (from `const band = bandScore(range);` through the
`sourceNote` ternary) with:

```js
    const { band, label, isRange, text, value, sourceNote } = describeRange(range);
```

Keep the tail-geometry comment where it is used, on the meter markup:

```js
          <!-- tail runs zero to high behind the solid zero-to-low fill -->
```

or move it into `renderDayHero`'s body above the `return`. Do not lose it — it
explains why the tail is `width: high` rather than a low-to-high span.

- [ ] **Step 4: Verify**

`./tests/run.sh` → `ALL TESTS PASSED`. Nothing about the rendered markup should
change; this is a pure extraction. If any assertion moves, you changed
behaviour — revert and redo.

- [ ] **Step 5: Commit**

```bash
git add assets/js/forecast-app.js
git commit -m "Extract describeRange() from the two range renderers

renderOutlookCard and renderDayHero each derived band, label, display
text, aria phrasing and the source note independently, with the
translated fallbacks written out twice. The aria wording could be changed
in one and not the other and no test would catch it -- both assert
includes('two sources') against whole-page markup.

Pure extraction, no markup change."
```

---

## Task 4: Drop the dead payload fields and window slack

**Files:** `includes/class-api.php:1226-1232`, `:32`, `:40`, plus tests

Three things are written and never read:

| Field | Writer | Readers |
|-------|--------|---------|
| `met_no.total` | `class-api.php:1227` | none anywhere |
| `met_no.offset_hours` | `class-api.php:1231` | only tests added by this branch |
| hours `-1` and `+2` | `MET_NO_WINDOW_BEFORE/AFTER` | none — JS reads offsets `[0, 1]` and the event hour only |

Verified by grep: no `met_no.total` or `met.total` reader exists in `assets/`.

**Judgement call — read before acting.** The window slack is defended in the
code comment as protection against the JS sampling window widening. Task 5
shows that protection is weaker than claimed. Two defensible positions:

- **Trim it** (`0 .. +1`, matching `MET_NO_SAMPLE_OFFSETS`): removes ~28 unread
  hours of payload and the cross-language slack fiction.
- **Keep it** and rely on Task 5's strengthened guard.

**Recommendation: keep the window, drop the two dead fields.** The window costs
little and genuinely absorbs an off-by-one; `total` and `offset_hours` buy
nothing at all. If you disagree, trimming is a two-constant change plus updating
`tests/dual-source.test.php:154` (`array( 20, 21, 22, 23 )` → `array( 21, 22 )`)
and `:179`.

- [ ] **Step 1: Drop `total` and `offset_hours`**

`includes/class-api.php:1226-1232`, current:

```php
			$hourly[ $i ]['met_no'] = array(
				'total'        => $best['total'],
				'low'          => $best['low'],
				'mid'          => $best['mid'],
				'high'         => $best['high'],
				'offset_hours' => intdiv( $best_delta, HOUR_IN_SECONDS ),
			);
```

Replace with:

```php
			// Only these three are read. 'low' and 'mid' feed the day view's
			// comparison table; 'high' is the only layer the score compares,
			// because the providers' bands are not the same measurement.
			$hourly[ $i ]['met_no'] = array(
				'low'  => $best['low'],
				'mid'  => $best['mid'],
				'high' => $best['high'],
			);
```

`$best_delta` is still needed by the `MET_NO_MAX_OFFSET` bound — do not remove
it, only its use here.

- [ ] **Step 2: Update the PHP tests**

In `tests/dual-source.test.php`, remove the two `offset_hours` assertions:

- `:104` — `'both matched exactly, offset 0'`
- `:120` — `'and reports offset_hours 3'`

Keep `:119` (`'a sample exactly 3h away is accepted'`) and the 3h01m rejection —
those test the `MET_NO_MAX_OFFSET` bound, which still matters. Expected count
drops from 14 to 12.

- [ ] **Step 3: Update the fixtures**

Remove `total:` from the `met_no` object in `tests/harness.js` (~`:84`) and
`offset_hours` from `tests/harness.js`, `tests/range.test.js` (~`:44`) and
`tests/dual-source.test.php`'s `ccf_metno()` helper. A fixture field nothing
reads teaches the next reader that the field matters.

- [ ] **Step 4: Verify**

`./tests/run.sh` → `ALL TESTS PASSED`.

- [ ] **Step 5: Update `reference.md`**

The payload description records `hourly[].met_no` with five keys. Correct it to
three, and note in the changelog entry that `total`/`offset_hours` were dropped
as unread.

- [ ] **Step 6: Commit**

```bash
git add includes/class-api.php tests reference.md
git commit -m "Drop unread met_no payload fields

met_no.total has no reader anywhere in assets/; offset_hours was read
only by the tests that asserted it. Both were persisted in the cached
transient and shipped to the browser on every load.

Keeps the +/-1 hour window slack, which genuinely absorbs an off-by-one."
```

---

## Task 5: Make the PHP/JS coupling guard real, or drop it

**Files:** `tests/range.test.js:136-137`, `assets/js/forecast-scoring.js:418,541`,
`includes/class-api.php:32,40`

The test asserts:

```js
  Math.min(...S.MET_NO_SAMPLE_OFFSETS) >= -1 && Math.max(...S.MET_NO_SAMPLE_OFFSETS) <= 2);
```

`-1` and `2` are **hand-copied literals** from `MET_NO_WINDOW_BEFORE` and
`MET_NO_WINDOW_AFTER`. Those are `private const` in PHP and unreadable from
Node, so the test cannot see the PHP side at all.

It does catch the failure originally described — the JS window widening past
what PHP populates. It does **not** catch the PHP window narrowing to `0..0`,
which would leave the suite green while every card silently degraded to
single-source. The plan and commit message for the original work claimed more
than the test delivers. Correct the record either way.

- [ ] **Step 1: Choose**

**Option A (recommended) — parse the PHP constants in a PHP test.** Move the
guard to `tests/dual-source.test.php`, where the PHP constants are reachable by
reflection, and read the JS constant out of the source file by regex:

```php
// --- The JS sampling window must sit inside the PHP one -------------------
echo "\nPHP/JS window coupling:\n";
$scoring = file_get_contents( dirname( __DIR__ ) . '/assets/js/forecast-scoring.js' );
preg_match( '/MET_NO_SAMPLE_OFFSETS\s*=\s*\[([^\]]*)\]/', $scoring, $m );
$offsets = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $m[1] ?? '' ) ), 'strlen' ) );
$before  = $reflection->getConstant( 'MET_NO_WINDOW_BEFORE' );
$after   = $reflection->getConstant( 'MET_NO_WINDOW_AFTER' );

$assert( 'the JS sampling offsets were found', ! empty( $offsets ) );
$assert(
	'every JS sampled offset falls inside the PHP window',
	! empty( $offsets ) && min( $offsets ) >= -$before && max( $offsets ) <= $after
);
```

`ReflectionClass::getConstant()` reads private constants without
`setAccessible`. This guard bites in **both** directions: widen the JS array or
narrow either PHP constant and it fails.

Then delete the assertion at `tests/range.test.js:136-137` and its comment, and
consider whether `MET_NO_SAMPLE_OFFSETS` still needs to be exported at
`forecast-scoring.js:541` — the PHP test reads the source text, not the export.
Keep the constant itself; it is used at `:478` and its comment is the
documentation.

**Option B — delete the guard and the claim.** Inline
`const indices = [eventIndex, eventIndex + 1];`, drop the export, drop the test,
and state in `tests/README.md` that the coupling is documented in comments only
and unenforced. Cheaper and honest, but gives up a real invariant.

- [ ] **Step 2: Verify the guard actually bites**

Whichever option, if a guard remains it must fail against **both** breakages:

1. Change `MET_NO_SAMPLE_OFFSETS` to `[0, 1, 2, 3]` → must FAIL.
2. Change `MET_NO_WINDOW_AFTER` to `0` → must FAIL.

Run both. The second is the one the current test misses. If either passes,
the guard is still theatre — fix it before committing.

- [ ] **Step 3: Correct the record**

Add to `tests/README.md`, in the "What these tests are not" section:

```markdown
**The PHP/JS window coupling guard was once weaker than advertised.** The
original assertion lived in `tests/range.test.js` and compared the JS
constant against `-1` and `2` typed as literals — hand-copied from PHP
constants Node cannot read. It caught the JS window widening but not the
PHP window narrowing, which would have degraded every card to
single-source with the suite still green. It now lives in
`tests/dual-source.test.php`, which can reach both sides.
```

Adjust the wording if you took Option B.

- [ ] **Step 4: Verify and commit**

`./tests/run.sh` → `ALL TESTS PASSED`.

```bash
git add tests assets/js/forecast-scoring.js
git commit -m "Make the PHP/JS window guard read both sides

The assertion compared the JS constant against -1 and 2 typed as
literals, hand-copied from PHP constants Node cannot read. It caught the
JS window widening but not the PHP window narrowing to 0..0, which would
leave every card silently single-source with the suite green.

Moved to the PHP test, where reflection reaches the real constants and
the JS array is read from source. Verified it fails against both
breakages, not just one.

The original plan and commit claimed this guard was stronger than it was;
tests/README.md now records what happened."
```

---

## Explicitly out of scope

- **The cold Met.no fetch** adds 200-600 ms to the first forecast for a new
  location. `schedule_refresh()` at `class-api.php:1436` already exists to defer
  it, and `met_no_available: false` is already a handled state. Real, but a
  behaviour change, not a cleanup. Separate branch.
- **`met_no_hour_indices()` rescanning** (1,204 `strpos` calls) and
  **`renderCloudBySource()` repeating `findHourIndex()`**. Both measured at
  ~0.03 ms. Take them for readability if you touch the code anyway; not worth a
  commit on their own.
- **The band mismatch on the shortcode path.** `merge_cloud_cover_rows()` still
  takes `max()` across low and mid, and `class-photography-renderer.php:1147`
  renders it as a "Δ 47%" badge with an "Open-Meteo: X% · Met.no: Y%" tooltip —
  presenting a definitional artefact as forecast disagreement, under help text
  that calls low cloud "0-3 km" while the value shown may be Met.no's 0-2 km
  figure. **This is a correctness bug on a live path**, discovered by this work
  and fixed only in the new consumer. Its own change, and it needs a decision
  about what the shortcode should show.
- **CSS band-colour duplication** (eight four-line blocks) and the
  **duplicated PHP test bootstrap** across `dual-source.test.php`,
  `solar.test.php` and `theme.test.php`. Both pre-existing patterns; collapsing
  them is worth doing but is not this branch's mess.
