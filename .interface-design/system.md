# Cloud Cover Forecast - Design System

**Established:** 2026-01-23
**Product:** PWA weather forecast app for photographers

---

## Intent

**Who is this human?**
A photographer — outdoors at dawn or dusk, checking their phone to decide: do I go out this morning? Is the sunset worth the drive? Time-sensitive decisions where golden hour waits for no one. Planning shoots for the coming week.

**What must they accomplish?**
Decide whether weather conditions are favorable for photography in the next critical window (sunrise/sunset today) AND plan shoots for the coming days.

**What should this feel like?**
Like a field instrument — functional, information-dense but scannable. The aesthetic of a well-designed pilot's weather briefing or a tide chart. Muted, professional, with color only where it signals something important. Trustworthy, not flashy.

---

## Palette

### Foundation Colors
```css
/* Light theme */
--bg-primary: #f5f5f5;      /* Page background */
--bg-secondary: #ffffff;     /* Cards, panels */
--bg-tertiary: #e5e7eb;      /* Section headers, muted areas */
--text-primary: #111827;     /* Main text */
--text-secondary: #1f2937;   /* Secondary text */
--text-muted: #4b5563;       /* Labels, hints */
--border-color: #d1d5db;     /* Subtle borders */

/* Dark theme */
--bg-primary: #0f172a;
--bg-secondary: #1e293b;
--bg-tertiary: #334155;
--text-primary: #f8fafc;
--text-secondary: #cbd5e1;
--text-muted: #94a3b8;
--border-color: #475569;
```

### Accent Colors
```css
--accent-primary: #16a34a;   /* Green - primary actions, "good" state */
--accent-secondary: #2563eb; /* Blue - links, info */
--accent-warning: #d97706;   /* Amber - warnings, stars */
--accent-danger: #dc2626;    /* Red - errors, "poor" state */
```

### Status Colors (Quality Indicators)
```css
/* Light theme */
--color-excellent: #16a34a;  /* Green */
--color-good: #65a30d;       /* Lime */
--color-fair: #ca8a04;       /* Yellow */
--color-poor: #dc2626;       /* Red */

/* Dark theme (brighter for contrast) */
--color-excellent: #4ade80;
--color-good: #a3e635;
--color-fair: #facc15;
--color-poor: #f87171;
```

### Sunlight Colors (Time of Day)
```css
--sunlight-day: #fbbf24;       /* Yellow - daytime */
--sunlight-civil: #f97316;     /* Orange - civil twilight */
--sunlight-nautical: #a855f7;  /* Purple - nautical twilight */
--sunlight-astro: #6366f1;     /* Indigo - astronomical twilight */
--sunlight-night: #1e1b4b;     /* Dark indigo - night */
```

**Why these colors?**
The sky palette — dawn oranges, dusk purples, night indigo. Green for clear/good conditions (go shoot). Red for poor conditions (stay home). Amber for warnings and star ratings. The palette connects to what photographers see when they look up.

---

## Typography

```css
font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
```

**Why?** System fonts for performance and native feel. This is a utility app, not a brand showcase.

### Scale (5 sizes, user-adjustable)
```css
/* Base (medium) */
--font-size-xs: 10px;
--font-size-sm: 12px;
--font-size-md: 14px;
--font-size-lg: 16px;
--font-size-xl: 18px;

/* Small (-3px) */
--font-size-xs: 7px; ... --font-size-xl: 15px;

/* Large (+3px) */
--font-size-xs: 13px; ... --font-size-xl: 21px;
```

---

## Spacing

```css
--spacing-xs: 4px;
--spacing-sm: 8px;
--spacing-md: 16px;
--spacing-lg: 24px;
--spacing-xl: 32px;
```

**Base unit:** 4px grid

---

## Depth & Surfaces

**Approach:** Minimal shadows. Borders define surfaces.

```css
/* Cards */
border: 1px solid var(--border-color);
border-radius: 12px;

/* Buttons */
border-radius: 8px;

/* Small elements (badges, toggles) */
border-radius: 6px;

/* Pills */
border-radius: 9999px;
```

**Elevation:**
1. Page background (`--bg-primary`)
2. Cards/panels (`--bg-secondary`) with 1px border
3. Section headers within cards (`--bg-tertiary`)

**Why?** Flat design with subtle borders. Shadows are used sparingly — only for modals and dropdowns. The interface shouldn't compete with the data.

---

## Components

### Hero Card
Purpose: Answer "should I go out today?" at a glance.

```
┌─────────────────────────────────────────────────┐
│  [Icon] TITLE                    ★★★★☆ LABEL   │ ← Header (bg-tertiary)
├─────────────────────────────────────────────────┤
│                                                 │
│   [Window 1]          │    [Window 2]          │ ← Body (bg-secondary)
│   Primary info        │    Primary info        │
│   Secondary info      │    Secondary info      │
│   Rating              │    Rating              │
│                                                 │
│   ⏱ Countdown to next event                    │ ← Optional countdown
│                                                 │
├─────────────────────────────────────────────────┤
│  [Icon] Compact supplementary info              │ ← Footer (bg-tertiary)
└─────────────────────────────────────────────────┘
```

### View Toggle
Purpose: Switch between detail levels.

```css
.view-toggle {
  display: flex;
  background: var(--bg-tertiary);
  border-radius: 8px;
  padding: 2px;
}

.view-toggle-btn.active {
  background: var(--bg-secondary);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}
```

### Day Cards (Grid)
Purpose: Weekly overview for planning.

```css
.day-cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: var(--spacing-sm);
}
```

Card structure:
- Header: Day name + date
- Rating: Star display
- Data rows: Sunrise, sunset, weather metrics
- Action: Expand button

### Data Grid
Purpose: Dense hourly data for detailed analysis.

- Fixed left column for labels (sticky)
- Horizontal scroll for time columns
- Color-coded cells based on thresholds
- Current hour highlighted with green border/background
- Past hours at 50% opacity

### Score Indicator
Purpose: At-a-glance quality rating.

```css
.photo-score-cell {
  position: relative;
}

.score-bar {
  position: absolute;
  bottom: 0;
  height: 4px;
  width: 100%;
}

.score-fill {
  height: 100%;
  width: [score]%;
  background: var(--color-[level]);
}
```

### Jump Buttons
Purpose: Quick navigation in large datasets.

```css
.jump-btn {
  padding: var(--spacing-xs) var(--spacing-sm);
  background: var(--bg-tertiary);
  border-radius: 6px;
  font-size: var(--font-size-xs);
}
```

---

## Patterns

### Quality Rating System
| Score | Class | Stars | Label |
|-------|-------|-------|-------|
| 80-100 | excellent | ★★★★★ | Excellent |
| 60-79 | good | ★★★★☆ | Good |
| 40-59 | fair | ★★★☆☆ | Fair |
| 0-39 | poor | ★★☆☆☆ | Poor |

### Color-Coded Thresholds
```javascript
cloud: [[25, 'excellent'], [50, 'good'], [75, 'fair'], [100, 'poor']]
rain: [[10, 'excellent'], [30, 'good'], [60, 'fair'], [100, 'poor']]
visibility: [[1000, 'poor'], [5000, 'fair'], [10000, 'good'], [Infinity, 'excellent']]
```

### Photography Score Algorithm
```javascript
score = 100
score -= cloudLow * 0.8      // Low clouds heavily penalized
score -= cloudMid * 0.4      // Mid clouds moderate penalty
score -= cloudHigh * 0.3     // High clouds mild penalty
score -= rainChance * 0.5    // Rain penalty
score -= (5000 - visibility) / 100  // Visibility penalty if < 5km
score -= (windSpeed - 30) * 0.3     // Wind penalty if > 30 km/h
score += 15 (golden hour)    // Bonus for optimal light
score += 10 (blue hour)      // Bonus for blue hour
```

---

## Responsive Breakpoints

```css
/* Mobile first (default) */
.day-cards-grid { grid-template-columns: repeat(2, 1fr); }

/* Tablet */
@media (min-width: 768px) {
  .day-cards-grid { grid-template-columns: repeat(4, 1fr); }
  --grid-cell-width: 56px;
}

/* Desktop */
@media (min-width: 1024px) {
  .day-cards-grid { grid-template-columns: repeat(7, 1fr); }
  --grid-cell-width: 64px;
}
```

---

## Interaction States

```css
/* Hover */
:hover { background: var(--bg-card); }

/* Active/pressed */
:active { transform: scale(0.98); }

/* Focus (keyboard) */
:focus { outline: 2px solid var(--accent-secondary); }

/* Disabled */
:disabled { opacity: 0.6; cursor: not-allowed; }
```

---

## Signature Element

The **Photography Score** — a single at-a-glance indicator that synthesizes weather conditions into an actionable "go/no-go" signal. This is what makes this app different from generic weather apps.
