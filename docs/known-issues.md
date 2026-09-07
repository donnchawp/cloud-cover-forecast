# Known issues

Defects found by work on this repo, with the evidence that found them, and
what was decided about each. Entries stay here once resolved so the reasoning
survives; the status line says where each one stands.

---

## 1. The dual-source range collapses when the horizon gate shuts

**Status:** fixed for genuine disagreements on 2026-09-05; still collapses, correctly,
when band geometry explains the gap. Recorded 2026-09-01, twice corrected.

`scoreLightHour()` computes `clarity = max(0, 1 - cloudLow / 70)` and reaches
high cloud only through `canvas * clarity`. Once Open-Meteo's low cloud hits 70
the canvas term is multiplied by zero, so substituting Met.no's high cloud
provably cannot change the score. Both sources land on the same number and the
range collapses to a point:

| Open-Meteo low | score at high=0 | at high=100 | spread |
|---|---|---|---|
| 40% | 34 | 42 | 8 |
| 65% | 30 | 32 | 2 |
| **70%** | 30 | 30 | **0** |
| **82%** | 28 | 28 | **0** |

### This was a known consequence, not an oversight

An earlier revision of this file presented the collapse as an undiscovered
defect. It was not. `docs/superpowers/specs/2026-09-01-dual-source-confidence-design.md`
has a section headed *"Consequence: the range is usually narrow"* that names
this exact mechanism, measures it (mean range width 1.1, median 0), and
concludes: *"This is the honest width. The wide ranges the first implementation
produced were mostly a unit mismatch."*

### Why substituting Met.no's low cloud would be a regression

The gate models cloud blocking light along a path that skims the horizon.
Open-Meteo's low band is 0–3 km; Met.no's is 0–2 km. A deck at 2–3 km blocks
low-angle light and is exactly what Met.no's band excludes, so for a *horizon*
gate the wider band is the more appropriate measurement. Met.no's low
under-measures the thing the gate cares about and its low+mid (0–5 km)
over-measures it. The design doc's probe agrees: Open-Meteo read higher in 18 of
20 locations, which is what band geometry alone predicts. The two sources agree
on total cloud to 10.1 points while differing 51.9 on low — they agree how much
cloud there is and disagree about where it sits.

So the observed Ahakista case (Open-Meteo low 82%, Met.no low 10%) is consistent
with a deck at 2–3 km that both models see and file differently. Feeding Met.no's
low into the gate would reintroduce precisely the unit mismatch the design doc's
"Correction" section removed.

### What was actually wrong, and what was done

The defect was in the presentation, not the arithmetic. A collapsed range means
"the two sources agree". Under a shut gate it also means "the second source
could not act" — a different claim, drawn identically. The card read as
corroborated when it was merely unexamined.

`sunriseSunsetRange()` now returns `horizonClosed`, true when two sources were
found and every sampled hour sat at or above the gate. It drives three things:

- the Outlook ring track gets `is-horizon-closed`, a finer dash in the same
  family as the single-source dash, since both mean "not corroborated";
- the day view's "Cloud by source" note explains that the high row could not
  change the score;
- the accessible label says "two sources, horizon closed" rather than the bare
  "two sources".

No score changed. `tests/range.test.js` covers the flag, including the exact
threshold boundary, and asserts the flag is never set on a range with width.

### Corrected again, 2026-09-05: the band argument does not cover every case

Real readings from Westminster broke the reasoning above. Open-Meteo read low
cloud at 77% while Met.no read low 0% **and mid 0%** — its entire 0–5 km column
empty. No band geometry reconciles that: any cloud in Open-Meteo's 0–3 km band
must appear in Met.no's 0–2 km or 2–5 km band. One model was simply wrong, and
the app showed the pessimistic one with no range at all.

Contrast the Ahakista case that motivated the original argument: there Met.no
read low 10% but mid **100%**, so a deck at 2–3 km explains the whole gap. The
band argument was right there and was over-generalised to every case.

That gives a discriminator:

```
band artefact        if OM_low <= max(MET_low, MET_mid)   -> compare high only, as before
genuine disagreement if OM_low >  max(MET_low, MET_mid)   -> score Met.no's own low and mid too
```

Westminster sunset scored 28–28 before and 28–78 after. Ahakista is unchanged at
28–28, and is kept as a control in `tests/range.test.js` precisely so a future
change that "fixes" it gets caught. Equality is not a violation — the cover is
exactly accounted for — and that boundary has its own test, because `>` versus
`>=` passed the first draft of the suite.

`horizonClosed` was narrowed to match: an hour counts as inert only when the gate
is shut *and* high cloud is the only field being swapped. A geometry violation
swaps the low too, so the gate itself differs between the two scores and the
comparison does act.

### Still open

Whether the range is worth its complexity at all. The design doc measured
"band differs across range: 0%" after the correction — the range essentially
never changes the word shown to the reader. That is a product question, not a
bug.

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

## 3. The Day view gave a sighted viewer no uncorroborated signal

**Status:** fixed 2026-09-07.

The Outlook ring marks an uncorroborated card on its track: dashed when there
was no second source, dotted when the horizon gate stopped the second source
acting. The day hero meter had neither, so three different situations — the
sources agreeing, only one source, and a shut gate — were drawn identically.
The distinction lived only in the `aria-label`, which is the wrong way round:
a screen reader was told and a sighted viewer was not.

The meter now carries the same two states, in the same
`--ring-track-uncorroborated` token and the same two rhythms (1:1 and 1:3),
painted as a `repeating-linear-gradient` behind the solid ground so the fill
and tail cover it the way the ring's arcs cover its track. The state itself
comes from `rangeTrackState()`, shared by both renderers so the views cannot
drift apart. `tests/theme.test.php` asserts both views use the one token and
that the two rhythms stay distinguishable.

Note for anyone reading an earlier revision of this file: the day hero *tail*
was never missing. It has been rendered whenever `isRange` since the range
feature landed, and `tests/day.test.js` has always covered it. Only the
uncorroborated signal was absent.
