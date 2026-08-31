# Dual-source confidence for the PWA sunrise/sunset score

Working note, mid-brainstorm. Not a spec yet. Repo is on `main` at v1.1.2,
everything merged and deployed; this is new work, nothing started.

## Why

The app scored a sunset 37% "Poor" that turned out good. Investigation showed
the model read the sky correctly — the good sunset came from a localised break
to the west that no point forecast can see. But it also surfaced a real gap.

## Spike findings (done, throwaway scripts, not kept)

`fetch_extended_forecast()` (class-api.php:385) — the PWA's data path — is
**single-source Open-Meteo**. The Met.no merge lives at class-api.php:308 in
`fetch_weather_data()`, which serves only the shortcode and blocks. README
advertises dual-source as a headline feature; it never reaches the PWA.

Probe across 20 Irish locations, 101 sunrise/sunset hours:

| layer | mean diff | median | >25 pts | >50 pts |
|-------|-----------|--------|---------|---------|
| low   | 29.7 pts  | 20.7   | 47%     | 24%     |
| mid   | 19.5 pts  | 7.7    | 29%     | 13%     |
| high  | 26.9 pts  | 16.4   | 37%     | 21%     |

- **Band label differs in 43% of cases.**
- Systematic bias, not noise: Open-Meteo reads low cloud 16 points cloudier
  (mean 69.9 vs 53.4); mid 8 points cloudier; high effectively identical.
- Low cloud is the gate in `scoreLightHour()`, so the PWA is **systematically
  pessimistic by construction**. This may explain why our bands read gloomier
  than Alpenglow's, rather than the thresholds being wrong.
- Neither source is known to be more accurate. That uncertainty is the argument
  for showing disagreement rather than silently merging.

Met.no resolution (`locationforecast/2.0/complete`): hourly for ~2.6 days, then
6-hourly out to 10 days. **All entries carry layered cloud**, so the full 7-day
window is usable.

Offset contamination, measured over 520 samples:

```
offset   OM vs Met.no    Met.no vs itself (weather change alone)
  0h        30.9 pts              -
  3h        35.6 pts           23.6 pts
```

A 3-hour offset inflates apparent disagreement by only ~5 points; errors add in
quadrature. Offset comparison still predominantly measures source disagreement.

## Decisions so far

1. **The score becomes a range**, low and high from the two sources. When they
   agree it collapses to a single number, so the *width is the confidence* —
   no badge, no new vocabulary.
2. **Days 3-7 use the nearest 6-hourly Met.no reading** (up to 3h away), so the
   whole Outlook is covered. Accepted cost: those ranges run ~5 points wider
   than justified.

## Open questions

- How an Outlook card shows a range. The current SVG ring (r=15.915,
  stroke-dasharray = score) was designed for a point value. Options: arc
  segment spanning low->high, ring at midpoint with range as text, or replace
  the ring with a bar. This is the question we stopped on.
- Band label for a range crossing bands ("Poor to Good"? midpoint? nothing?).
- Met.no fetch failure: must render as single-source and be visually distinct
  from "sources agree" — a bare number must never be ambiguous between the two.
- Whether to also revisit band thresholds. Ours: 80/60/40. Alpenglow implies
  Good starts ~45-50 and Fair reaches down to ~27. Deferred — may be moot if
  the pessimism is an input problem rather than a threshold problem.

## Constraints

- Met.no requires an identifying User-Agent; `get_met_no_user_agent()` exists.
- Rate limits are per-service and generous (Met.no 20/sec).
- Forecasts cache with stale-while-revalidate, 12h grace, manual clear from
  settings. New payload fields need a version bump; asset URLs carry
  `?v=CLOUD_COVER_FORECAST_VERSION`.
- Tests: `tests/run.sh`, 111 assertions, node + php. JS tests run under two
  timezones. Add coverage for anything new.

## Next step

Resume the brainstorm at the Outlook card question, then the remaining open
questions, then design -> spec -> plan.
