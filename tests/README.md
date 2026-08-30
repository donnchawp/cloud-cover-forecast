# Tests

No framework and no dependencies — plain node and php, matching a plugin that
has no build step. Run everything:

```sh
tests/run.sh
```

Or one at a time: `node tests/day.test.js`, `php tests/solar.test.php`.

## What is covered

| File | Covers |
|------|--------|
| `shell.test.js` | App shell, view tabs, header location switcher, picker, delete fallback |
| `outlook.test.js` | Outlook rows and cards, score rings, past events, tapping through to a day |
| `day.test.js` | Day pager and bounds, hero cards, phase list order and times, stale-cache fallback |
| `midnight.test.js` | An Irish June: no astronomical dawn, dusk after local midnight |
| `shared-link.test.js` | `?lat=&lon=` deep links selecting a location |
| `scoring.test.js` | Sunrise/sunset colour score ordering and missing-data handling |
| `solar.test.php` | Solar phase times vs Alpenglow, plus a worldwide year-long sweep |

`harness.js` stubs just enough DOM to run the real `forecast-app.js` under
node and read back what it rendered.

## What these tests are not

**They check markup and logic, never pixels.** Nothing here renders CSS. The
bottom tab bar, the safe-area inset, the picker overlay, the score rings and
dark mode are all unverified by this suite; only a browser will tell you.

**`solar.test.php` compares against one screenshot.** Twelve boundaries for one
place on one date. It would not catch an error that happens to be right for
Durrus in August. The year-long sweep checks ordering and day-anchoring
invariants, not accuracy.

**The scoring weights are uncalibrated.** `scoring.test.js` pins the ordering
the formula is meant to express — high cloud beats none, low cloud gates the
bonus — not that any number is correct. Tuning the weights should change the
printed scores; only the ordering assertions should hold.

**One thing deliberately not tested.** `viewLocation()` used to overwrite
`state.homeLocation`. That cannot be regression-tested through the rendered
output: `loadSavedLocations()` refreshes `homeLocation` from storage before
anything observes the corruption, and the home badge renders from the stored
`isHome` flag. Reintroducing the old assignment as a negative control still
passed every assertion. The guarantee is structural instead — `homeLocation`
is written in exactly one function:

```sh
grep -n 'state\.homeLocation *=' assets/js/forecast-app.js
```

## Writing more

Assertions here match on rendered HTML, which is easy to get subtly wrong.
Two real examples from writing this suite, both of which passed against
correct output while asserting nothing useful:

- `class="outlook-card[^"]*"` also matches `outlook-card-band` and
  `outlook-card-time`. Match opening tags instead.
- A lazy `[^]*?` spans the whole document, not one element. Scope the match to
  a single element first, then assert inside it.

When an assertion passes first time, break the code deliberately and check it
fails.
