# Tests

No framework and no dependencies — plain node and php, matching a plugin that
has no build step. Run everything:

```sh
tests/run.sh
```

Or one at a time: `node tests/day.test.js`, `php tests/solar.test.php`.

The JS tests run twice, under `TZ=UTC` and `TZ=Pacific/Auckland`. That is not
belt-and-braces: the wall-clock conversion was once wrong by exactly the
viewer's own UTC offset, which is zero on UTC. It looked perfect in a default
container and was an hour out in Ireland every summer. Any new test touching
times must pass under both.

## What is covered

| File | Covers |
|------|--------|
| `shell.test.js` | App shell, view tabs, header location switcher, picker, delete fallback |
| `outlook.test.js` | Outlook rows and cards, score rings, past events, tapping through to a day |
| `day.test.js` | Day pager and bounds, hero cards, phase list order and times, stale-cache fallback |
| `midnight.test.js` | An Irish June: no astronomical dawn, dusk after local midnight |
| `shared-link.test.js` | `?lat=&lon=` deep links selecting a location |
| `scoring.test.js` | Sunrise/sunset colour score ordering and missing-data handling |
| `stale-shell.test.js` | A page cached before `forecast-scoring.js` existed fails visibly |
| `timezone.test.js` | Wall-clock conversion, DST boundaries, current-hour matching |
| `theme-color.test.js` | Browser theme-color follows an explicit theme choice |
| `theme.test.php` | Critical CSS covers all three theme states and matches the tokens |
| `solar.test.php` | Solar phase times vs Alpenglow, plus a worldwide year-long sweep |

`harness.js` stubs just enough DOM to run the real `forecast-app.js` under
node and read back what it rendered.

## What these tests are not

**They check markup and logic, never pixels.** `theme.test.php` compares
colour *values* between two files; it cannot tell you whether the result looks
right, only that the two stay in step. Nothing here renders CSS. The
bottom tab bar, the safe-area inset, the picker overlay, the score rings and
dark mode are all unverified by this suite; only a browser will tell you.

**The range visuals are markup only.** `outlook.test.js` and `day.test.js`
assert that a `score-ring-tail` circle exists, that a single-source track
carries `is-single-source`, and that the day hero gets a
`day-hero-meter-tail`. Whether the tail is actually *visible* — whether
`stroke-dashoffset="-37"` puts it where it should be, whether 35% opacity has
enough contrast in dark mode, whether `37–75` fits inside a 46px ring at 8px —
is a browser question this suite cannot reach.

**No test checks that a CSS custom property exists.** `--radius-md` has been
referenced at `forecast-app.css:1564` and never defined for the whole life of
the file, and the suite is green. A typo in a token name fails silently and
always will.

**A file can print "0 failed" and still be broken.** `scoring.test.js` threw
partway through after `sunriseSunsetScore` was renamed, having already printed
`16 passed, 0 failed` for the assertions it reached. Only `run.sh` checking the
exit code caught it. Never read the summary line alone — the runner's final
`ALL TESTS PASSED` is the one that counts.

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
