# Known issues

Real defects found and deliberately not fixed yet, with the evidence that
found them. Each needs a design decision, not just a patch.

---

## 1. The dual-source range collapses exactly when the sources disagree most

**Status:** open. Found 2026-09-01 while looking at Ahakista in the PWA.
**Path:** `assets/js/forecast-scoring.js`, `sunriseSunsetRange()` and
`scoreLightHour()`.

`sunriseSunsetRange()` substitutes only Met.no's `cloud_high` into the score.
That is correct as far as it goes — the providers band the sky differently
(Open-Meteo low is 0–3 km, Met.no 0–2 km), so their low and mid figures are not
the same measurement and cannot be dropped into a formula tuned on Open-Meteo's.
`tests/range.test.js` has a test named *"Met.no low and mid cloud are
deliberately ignored"* guarding that.

The problem is what `scoreLightHour()` then does with high cloud:

```js
const canvas  = min(MAX_CANVAS, interpolate(cloudHigh, ...) * (glow ? 1.5 : 1)
                                + interpolate(cloudMid, ...));
const clarity = Math.max(0, 1 - (cloudLow / HORIZON_BLOCKED_AT));  // 70
let   score   = 40 + (canvas * clarity);
```

High cloud only reaches the score through `canvas * clarity`. Once **Open-Meteo's**
low cloud reaches `HORIZON_BLOCKED_AT` (70), `clarity` is 0 and high cloud
contributes nothing at all. Both sources then return an identical score, the
range collapses to a point, and the card renders as a single confident number
with no tail.

Measured, holding mid at 100 and sweeping high cloud from 0 to 100:

| Open-Meteo low | score at high=0 | at high=100 | spread |
|---|---|---|---|
| 0% | 40 | 58 | 18 |
| 40% | 34 | 42 | 8 |
| 55% | 32 | 36 | 4 |
| 65% | 30 | 32 | 2 |
| **69%** | 30 | 30 | **0** |
| **82%** | 28 | 28 | **0** |

### Why this matters

The observed case: Ahakista sunset, 2026-09-01. Open-Meteo read low cloud at
**82%**, Met.no at **10%** — a 72-point disagreement on the layer that gates the
whole score. The card rendered "Poor, 9%" with no range at all, because the only
layer being compared had already been multiplied by zero.

That is the feature's own reason for existing. From `reference.md`:

> Open-Meteo reads low cloud 16 points cloudier (mean 69.9 vs 53.4). Low cloud
> is the gate in `scoreLightHour()`, so the PWA was systematically pessimistic
> by construction.

Open-Meteo's **mean** low-cloud reading across that probe was 69.9%, sitting
right on the 70 gate. So the collapse is not an edge case: it happens on roughly
half of all events, and specifically on the pessimistic ones the range was built
to put a question mark against.

### What a fix has to decide

Not "compare low cloud too" — that is the substitution the band mismatch already
rules out. The real question is what the range should express when the two
sources disagree about whether the horizon is open at all. Sketches, none chosen:

- Gate on a **range of clarity** rather than Open-Meteo's single figure, deriving
  each source's gate from its own low reading and accepting that the two gates
  are measured over different altitudes — an approximation, but a declared one.
- Keep the score single-source and surface low-cloud disagreement as a separate
  explicit signal, rather than trying to push it through the score.
- Recalibrate `HORIZON_BLOCKED_AT` per source.

Whatever is chosen needs a note in the UI: a collapsed range currently means
"the sources agree", and under this bug it can also mean "we could not tell".

---

## 2. The shortcode path presents a band mismatch as forecast disagreement

**Status:** open. Found 2026-09-01 during the dual-source cleanup.
**Path:** `includes/class-api.php`, `merge_cloud_cover_rows()`;
`includes/class-photography-renderer.php:1147`.

`merge_cloud_cover_rows()` still takes `max()` across low and mid from both
sources and the renderer shows the result as a "Δ 47%" badge with an
"Open-Meteo: X% · Met.no: Y%" tooltip — presenting a definitional artefact as
though it were the two services disagreeing about the weather. The help text
alongside calls low cloud "0–3 km" while the figure displayed may be Met.no's
0–2 km reading.

This is the same band mismatch the PWA path fixed by comparing only high cloud.
It was fixed only in the new consumer; the shortcode and blocks still ship it.
Needs a decision about what the shortcode should show, not just a code change.

---

## 3. The Day view gives a sighted viewer no single-source signal

**Status:** open, minor. Found 2026-09-01.
**Path:** `assets/js/forecast-app.js`, `renderDayHero()`.

The Outlook ring marks a single-source card with a dashed track. The day hero
meter has no equivalent — `renderDayHero()` draws a tail only when
`isRange`, and single-source is otherwise indistinguishable from the two
sources agreeing. The information is in the `aria-label` ("one source"), so a
screen reader gets it and a sighted viewer does not, which is the wrong way
round from the usual gap.

The ring track's contrast was fixed on 2026-09-01
(`--ring-track-single`, `tests/theme.test.php`); this is the remaining half of
the same signal.
