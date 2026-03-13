# Visual Redesign: Vintage Football Programme — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the dark broadcast dashboard aesthetic with a warm, light, vintage football programme identity.

**Architecture:** The reskin flows top-down: foundation tokens (CSS variables, Tailwind config, fonts) cascade into component classes (index.css), then into UI components (TSX), then pages. Changing the foundation first means ~60% of the visual change propagates automatically. Component and page tasks mop up inline Tailwind classes and component-specific logic.

**Tech Stack:** Tailwind CSS, React/TypeScript, Google Fonts (Playfair Display, Inter, IBM Plex Mono)

**Spec:** `docs/superpowers/specs/2026-03-13-visual-redesign-design.md`

---

## Chunk 1: Foundation

### Task 1: Update font imports

**Files:**
- Modify: `frontend/index.html`

- [ ] **Step 1: Replace Google Fonts link**

Replace the current font import (line 11) with:

```html
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@400;700;900&display=swap" rel="stylesheet">
```

Also update the comment on line 8 from "Athletic typography system" to "Programme typography system".

- [ ] **Step 2: Verify fonts load**

Run: `cd frontend && npx vite build`
Expected: Build succeeds (font loading is runtime, but ensures no syntax errors)

- [ ] **Step 3: Commit**

```bash
git add frontend/index.html
git commit -m "chore: swap fonts to Playfair Display, Inter, IBM Plex Mono"
```

---

### Task 2: Rewrite CSS custom properties

**Files:**
- Modify: `frontend/src/index.css` (lines 1-55, the `:root` block)

- [ ] **Step 1: Replace the `:root` variables**

Replace the entire `:root` block (lines 6-54) with:

```css
:root {
    /* Typography */
    --font-display: 'Playfair Display', serif;
    --font-body: 'Inter', sans-serif;
    --font-mono: 'IBM Plex Mono', monospace;

    /* Palette — coolors.co/palette/264653-2a9d8f-e9c46a-f4a261-e76f51 */
    --deep-teal: 196 37% 24%;       /* #264653 */
    --teal: 170 47% 39%;            /* #2A9D8F */
    --gold: 42 78% 66%;             /* #E9C46A */
    --orange: 27 90% 67%;           /* #F4A261 */
    --terracotta: 14 77% 61%;       /* #E76F51 */

    /* Surfaces — light cream base */
    --background: 34 47% 96%;       /* #FAF6F1 warm cream */
    --surface: 0 0% 100%;           /* #FFFFFF white cards */
    --surface-elevated: 34 23% 93%; /* #F0EBE3 warm gray */
    --surface-hover: 34 23% 90%;    /* Slightly darker on hover */

    /* Text */
    --foreground: 196 37% 24%;      /* Deep teal for primary text */
    --foreground-muted: 196 37% 24% / 0.6;
    --foreground-dim: 196 37% 24% / 0.4;

    /* Semantic colors */
    --primary: var(--teal);
    --primary-foreground: 34 47% 96%;
    --secondary: var(--deep-teal);
    --secondary-foreground: 34 47% 96%;
    --muted: 34 23% 93%;
    --muted-foreground: 196 37% 24% / 0.6;
    --accent: var(--gold);
    --accent-foreground: 196 37% 24%;
    --destructive: var(--terracotta);
    --destructive-foreground: 34 47% 96%;

    /* UI elements */
    --border: 196 37% 24% / 0.15;
    --input: 0 0% 100%;
    --ring: var(--teal);
    --radius: 0.25rem;

    /* Card styles */
    --card: 0 0% 100%;
    --card-foreground: 196 37% 24%;
    --popover: 0 0% 100%;
    --popover-foreground: 196 37% 24%;
  }
```

- [ ] **Step 2: Verify build**

Run: `cd frontend && npx vite build`
Expected: Build succeeds. The site will look broken at this point — that's expected.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/index.css
git commit -m "style: replace CSS custom properties with programme palette"
```

---

### Task 3: Update Tailwind config

**Files:**
- Modify: `frontend/tailwind.config.js`

- [ ] **Step 1: Remove `darkMode` and update theme**

Remove `darkMode: ["class"]` (line 3).

Replace the `fontFamily` block (lines 17-21) with:

```js
fontFamily: {
  display: ['Playfair Display', 'serif'],
  body: ['Inter', 'sans-serif'],
  mono: ['IBM Plex Mono', 'monospace'],
},
```

Add new palette colors alongside the existing semantic colors. After the existing `highlight` block (line 68), add:

```js
programme: {
  'deep-teal': '#264653',
  teal: '#2A9D8F',
  gold: '#E9C46A',
  orange: '#F4A261',
  terracotta: '#E76F51',
  cream: '#FAF6F1',
  'warm-gray': '#F0EBE3',
  pitch: '#4A8C5C',
},
```

Replace the `borderRadius` block (lines 70-74) — change the base `--radius` to `0.25rem`:

```js
borderRadius: {
  lg: "var(--radius)",
  md: "calc(var(--radius) - 1px)",
  sm: "calc(var(--radius) - 2px)",
},
```

Remove the old `fpl` and `highlight` color blocks (lines 59-68) entirely — this way, any missed class references will produce visible build warnings rather than silently compiling.

Replace the entire `animation` and `keyframes` blocks (lines 75-123) with:

```js
animation: {
  shimmer: 'shimmer 2s infinite linear',
  'pulse-dot': 'pulseDot 1.5s ease-in-out infinite',
},
keyframes: {
  shimmer: {
    '0%': { backgroundPosition: '-200% 0' },
    '100%': { backgroundPosition: '200% 0' },
  },
  pulseDot: {
    '0%, 100%': { opacity: '1', transform: 'scale(1)' },
    '50%': { opacity: '0.5', transform: 'scale(1.2)' },
  },
},
```

- [ ] **Step 2: Verify build**

Run: `cd frontend && npx vite build`
Expected: Build succeeds (may have warnings about unused classes — that's fine)

- [ ] **Step 3: Commit**

```bash
git add frontend/tailwind.config.js
git commit -m "style: update tailwind config for programme theme"
```

---

### Task 4: Rewrite base typography rules

**Files:**
- Modify: `frontend/src/index.css` (lines 57-91, the second `@layer base` block)

- [ ] **Step 1: Replace the base typography rules**

Replace lines 80-91 (the `h1,h2,h3,.font-display` and `.font-mono` rules) with:

```css
  /* Programme display typography — no forced uppercase */
  h1, h2, h3, .font-display {
    font-family: var(--font-display);
    letter-spacing: 0.01em;
  }

  /* Mono for stats and numbers */
  .font-mono, code, pre {
    font-family: var(--font-mono);
  }
```

Note: the key change is removing `text-transform: uppercase` from display typography.

- [ ] **Step 2: Commit**

```bash
git add frontend/src/index.css
git commit -m "style: remove forced uppercase from display typography"
```

---

### Task 5: Replace animations and utilities

**Files:**
- Modify: `frontend/src/index.css` (lines 93-313, keyframes + utilities)

- [ ] **Step 1: Replace all keyframes (lines 93-199)**

Delete the existing keyframes block and replace with:

```css
/* Keyframe Animations — minimal set */
@keyframes shimmer {
  0% { background-position: -200% 0; }
  100% { background-position: 200% 0; }
}

@keyframes pulseDot {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.5; transform: scale(1.2); }
}

@keyframes drawerSlideIn {
  from { transform: translateX(100%); }
  to { transform: translateX(0); }
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
```

- [ ] **Step 2: Replace the utilities block (lines 201-313)**

Delete the existing `@layer utilities` block and replace with:

```css
@layer utilities {
  .animate-shimmer {
    animation: shimmer 2s infinite linear;
    background: linear-gradient(
      90deg,
      hsl(var(--surface-elevated)) 0%,
      hsl(var(--background)) 50%,
      hsl(var(--surface-elevated)) 100%
    );
    background-size: 200% 100%;
  }

  .animate-pulse-dot {
    animation: pulseDot 1.5s ease-in-out infinite;
  }

  .animate-fade-in {
    animation: fadeIn 0.2s ease-out forwards;
  }

  .animate-drawer-slide-in {
    animation: drawerSlideIn 0.3s cubic-bezier(0.32, 0.72, 0, 1) forwards;
  }

  /* Pitch surface — flat muted green */
  .pitch-texture {
    background: hsl(142 40% 35%);
  }
}
```

This removes: `fade-in-up`, `slide-in-right`, `slide-in-left`, `pulse-glow`, `count-up`, `scale-in`, `draw-path`, all animation delays, `gradient-text`, `gradient-text-accent`, `clip-angular`, and `glass`.

- [ ] **Step 3: Verify build**

Run: `cd frontend && npx vite build`
Expected: Build succeeds. There will be references to removed classes in TSX files — these become no-ops (Tailwind just ignores unknown classes). We'll clean them up in later tasks.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/index.css
git commit -m "style: strip animations down to shimmer and pulse-dot only"
```

---

### Task 6: Rewrite component classes in index.css

**Files:**
- Modify: `frontend/src/index.css` (lines 315-503, the `@layer components` block)

- [ ] **Step 1: Replace the entire `@layer components` block**

```css
@layer components {
  /* Accent bar — solid teal */
  .accent-bar {
    @apply relative;
  }

  .accent-bar::before {
    content: '';
    @apply absolute left-0 top-0 bottom-0 w-1 rounded-l;
    background: hsl(var(--teal));
  }

  /* Stat panel — flat with border */
  .stat-panel {
    @apply relative bg-surface rounded p-4 overflow-hidden;
    border: 1.5px solid hsl(var(--border));
  }

  .stat-panel:hover {
    border-color: hsl(var(--teal) / 0.4);
    transition: border-color 150ms;
  }

  /* Card — flat with colored top border */
  .broadcast-card {
    @apply bg-surface rounded overflow-hidden;
    border: 1.5px solid hsl(var(--border));
  }

  .broadcast-card-header {
    @apply px-4 py-3 font-display text-sm;
    background: hsl(var(--background));
    border-bottom: 1.5px solid hsl(var(--border));
  }

  /* Input styling */
  .input-broadcast {
    @apply w-full px-4 py-2.5 bg-surface border rounded text-foreground;
    border-color: hsl(var(--border));
    @apply placeholder:text-foreground-dim;
    @apply focus:outline-none focus:border-[hsl(var(--teal))] focus:ring-1 focus:ring-[hsl(var(--teal))]/50;
    @apply transition-colors duration-150;
  }

  /* Form primitives */
  .form-section-card {
    @apply rounded border bg-surface p-4;
    border-color: hsl(var(--border));
  }

  .form-section-title {
    @apply font-body text-[11px] font-semibold uppercase tracking-[0.08em] text-foreground-muted;
  }

  .form-help-text {
    @apply text-sm text-foreground-dim;
  }

  .form-field {
    @apply space-y-1.5;
  }

  .form-label {
    @apply font-body text-[11px] font-medium uppercase tracking-[0.08em] text-foreground-muted;
  }

  .form-control {
    @apply input-broadcast h-11 py-2.5 text-sm;
  }

  .form-results {
    @apply max-h-36 overflow-y-auto rounded border bg-surface;
    border-color: hsl(var(--border));
  }

  .form-result-item {
    @apply flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-surface-hover;
  }

  .form-empty-text {
    @apply text-xs text-foreground-dim;
  }

  .form-pill-list {
    @apply min-h-[2rem] flex flex-wrap gap-1.5;
  }

  .form-pill {
    @apply inline-flex items-center gap-1 rounded border px-2 py-1 text-xs;
  }

  .form-pill-lock {
    @apply border-programme-teal/30 bg-programme-teal/10 text-programme-teal;
  }

  .form-pill-avoid {
    @apply border-programme-terracotta/30 bg-programme-terracotta/10 text-programme-terracotta;
  }

  .form-pill-team {
    @apply border-programme-orange/30 bg-programme-orange/10 text-programme-orange;
  }

  .form-error-text {
    @apply text-xs text-programme-terracotta;
  }

  /* Button variants — flat, no gradients */
  .btn-primary {
    @apply px-6 py-2.5 font-body font-medium text-sm;
    @apply bg-programme-deep-teal text-programme-cream rounded;
    @apply hover:bg-[#1d3640] transition-colors duration-150;
    @apply disabled:opacity-50 disabled:cursor-not-allowed;
  }

  .btn-secondary {
    @apply px-6 py-2.5 font-body font-medium text-sm;
    @apply bg-programme-cream border text-programme-deep-teal rounded;
    border-color: hsl(var(--border));
    @apply hover:bg-programme-warm-gray transition-colors duration-150;
  }

  /* Tab navigation — deep teal bar */
  .tab-nav {
    @apply flex gap-1 p-1 bg-programme-deep-teal rounded;
  }

  .tab-nav-item {
    @apply relative px-4 py-2 font-body text-sm font-medium;
    @apply text-programme-cream/70 rounded transition-colors duration-150;
  }

  .tab-nav-item:hover {
    @apply text-programme-cream bg-white/10;
  }

  .tab-nav-item.active {
    @apply text-programme-cream;
  }

  .tab-nav-item.active::after {
    content: '';
    @apply absolute bottom-0 left-2 right-2 h-0.5 rounded-full;
    background: hsl(var(--gold));
  }

  /* Table styling — horizontal rules, alternating rows */
  .table-broadcast {
    @apply w-full text-sm;
  }

  .table-broadcast thead {
    @apply bg-programme-deep-teal text-programme-cream;
  }

  .table-broadcast th {
    @apply px-4 py-3 text-left font-display text-xs tracking-wide;
  }

  .table-broadcast th.text-right,
  .table-broadcast td.text-right {
    text-align: right;
  }

  .table-broadcast th.text-center,
  .table-broadcast td.text-center {
    text-align: center;
  }

  .table-broadcast th:first-child {
    @apply pl-4;
  }

  .table-broadcast tbody tr {
    @apply border-b transition-colors;
    border-color: hsl(var(--border));
  }

  .table-broadcast tbody tr:nth-child(even) {
    @apply bg-programme-warm-gray;
  }

  .table-broadcast tbody tr:hover {
    @apply bg-programme-warm-gray;
  }

  .table-broadcast td {
    @apply px-4 py-3;
  }
}
```

- [ ] **Step 2: Verify build**

Run: `cd frontend && npx vite build`
Expected: Build succeeds.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/index.css
git commit -m "style: rewrite component classes for programme theme"
```

---

## Chunk 2: UI Components

### Task 7: Replace GradientText with AccentText

**Files:**
- Modify: `frontend/src/components/ui/GradientText.tsx` (rename to AccentText)
- Modify: `frontend/src/components/ui/GradientText.test.tsx`
- Modify: 5 consumer files (App.tsx, Admin.tsx, LeagueAnalyzer.tsx, TeamAnalyzer.tsx, PlayerDetailPanel.tsx)

- [ ] **Step 1: Rewrite GradientText.tsx as AccentText**

Replace the entire file content with:

```tsx
import { ReactNode } from 'react'

interface AccentTextProps {
  children: ReactNode
  color?: 'teal' | 'gold' | 'terracotta'
  className?: string
  as?: 'span' | 'h1' | 'h2' | 'h3' | 'p' | 'div'
}

export function AccentText({
  children,
  color = 'teal',
  className = '',
  as: Component = 'span',
}: AccentTextProps) {
  const colors = {
    teal: 'text-programme-teal',
    gold: 'text-programme-gold',
    terracotta: 'text-programme-terracotta',
  }

  return <Component className={`${colors[color]} ${className}`}>{children}</Component>
}

// Backwards-compatible alias during migration
export const GradientText = AccentText
```

- [ ] **Step 2: Update the test file**

Update `GradientText.test.tsx` to test the new AccentText component. Replace gradient assertions with solid color class assertions.

- [ ] **Step 3: Update consumer files**

In each consumer, replace `<GradientText>` with `<AccentText>` and remove any `variant` props (replace with `color` prop where a non-default color is needed). The files:
- `frontend/src/App.tsx` (line 79)
- `frontend/src/pages/Admin.tsx`
- `frontend/src/pages/LeagueAnalyzer.tsx`
- `frontend/src/pages/TeamAnalyzer.tsx`
- `frontend/src/components/planner/PlayerDetailPanel.tsx`

- [ ] **Step 4: Run tests**

Run: `cd frontend && npm test -- --run`
Expected: All tests pass (or update snapshots if needed)

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/ui/GradientText.tsx frontend/src/components/ui/GradientText.test.tsx frontend/src/App.tsx frontend/src/pages/Admin.tsx frontend/src/pages/LeagueAnalyzer.tsx frontend/src/pages/TeamAnalyzer.tsx frontend/src/components/planner/PlayerDetailPanel.tsx
git commit -m "refactor: replace GradientText with AccentText (solid colors)"
```

---

### Task 8: Update BroadcastCard

**Files:**
- Modify: `frontend/src/components/ui/BroadcastCard.tsx`

- [ ] **Step 1: Replace accent colors and remove animations**

Replace the entire component with:

```tsx
import { ReactNode } from 'react'

interface BroadcastCardProps {
  title?: string
  children: ReactNode
  className?: string
  headerAction?: ReactNode
  accentColor?: 'teal' | 'gold' | 'terracotta'
  animate?: boolean
  animationDelay?: number
}

export function BroadcastCard({
  title,
  children,
  className = '',
  headerAction,
  accentColor = 'teal',
}: BroadcastCardProps) {
  const topBorders = {
    teal: 'border-t-programme-teal',
    gold: 'border-t-programme-gold',
    terracotta: 'border-t-programme-terracotta',
  }

  return (
    <div className={`broadcast-card border-t-[3px] ${topBorders[accentColor]} ${className}`}>
      {title && (
        <div className="broadcast-card-header flex items-center justify-between">
          <h3 className="font-display text-sm text-foreground font-bold">{title}</h3>
          {headerAction && <div>{headerAction}</div>}
        </div>
      )}
      <div className="p-3 md:p-4">{children}</div>
    </div>
  )
}

interface BroadcastCardSectionProps {
  children: ReactNode
  className?: string
  divided?: boolean
}

export function BroadcastCardSection({
  children,
  className = '',
  divided = false,
}: BroadcastCardSectionProps) {
  return (
    <div
      className={`
        ${divided ? 'border-t pt-4 mt-4' : ''}
        ${className}
      `}
      style={divided ? { borderColor: 'hsl(var(--border))' } : undefined}
    >
      {children}
    </div>
  )
}
```

Note: `animate` and `animationDelay` props are kept in the interface for backwards compatibility but ignored. They can be removed in a cleanup pass later.

- [ ] **Step 2: Run tests**

Run: `cd frontend && npm test -- --run`
Expected: Tests pass (update snapshots if needed)

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/ui/BroadcastCard.tsx
git commit -m "style: restyle BroadcastCard with flat borders, no gradients"
```

---

### Task 9: Update StatPanel

**Files:**
- Modify: `frontend/src/components/ui/StatPanel.tsx`

- [ ] **Step 1: Replace component**

```tsx
interface StatPanelProps {
  label: string
  value: string | number
  highlight?: boolean
  trend?: 'up' | 'down' | 'neutral'
  subValue?: string
  className?: string
  animationDelay?: number
}

export function StatPanel({
  label,
  value,
  highlight = false,
  trend,
  subValue,
  className = '',
}: StatPanelProps) {
  const trendColors = {
    up: 'text-programme-teal',
    down: 'text-programme-terracotta',
    neutral: 'text-foreground-muted',
  }

  const trendPrefixes = {
    up: '+',
    down: '\u2212', // minus sign
    neutral: '',
  }

  return (
    <div
      className={`stat-panel group ${highlight ? 'border-programme-teal' : ''} ${className}`}
    >
      {/* Accent bar — solid color */}
      <div
        className={`absolute left-0 top-0 bottom-0 w-1 rounded-l transition-colors duration-150 ${
          highlight ? 'bg-programme-gold' : 'bg-programme-teal/30 group-hover:bg-programme-teal'
        }`}
      />

      {/* Content */}
      <div className="pl-3">
        <div className="flex items-baseline gap-2">
          <span
            className={`font-mono text-xl md:text-2xl font-bold tracking-tight ${
              highlight ? 'text-programme-teal' : 'text-foreground'
            }`}
          >
            {value}
          </span>
          {trend && (
            <span className={`text-xs font-medium ${trendColors[trend]}`}>
              {trendPrefixes[trend]}
            </span>
          )}
        </div>
        <div className="text-[10px] md:text-xs font-body font-medium uppercase tracking-wide text-foreground-muted mt-1">
          {label}
        </div>
        {subValue && (
          <div className="text-[11px] md:text-xs text-foreground-dim mt-0.5">{subValue}</div>
        )}
      </div>
    </div>
  )
}

interface StatPanelGridProps {
  children: React.ReactNode
  className?: string
}

export function StatPanelGrid({ children, className = '' }: StatPanelGridProps) {
  return <div className={`grid grid-cols-2 md:grid-cols-4 gap-4 ${className}`}>{children}</div>
}
```

- [ ] **Step 2: Run tests and commit**

Run: `cd frontend && npm test -- --run`

```bash
git add frontend/src/components/ui/StatPanel.tsx
git commit -m "style: restyle StatPanel with flat borders and solid accent bars"
```

---

### Task 10: Update TabNav

**Files:**
- Modify: `frontend/src/components/ui/TabNav.tsx`

- [ ] **Step 1: Remove uppercase from tab labels**

The CSS class changes in Task 6 already handle most of the restyling. The only TSX change is to remove any inline `uppercase` or `tracking-wider` classes if present. The current component delegates to CSS classes (`tab-nav`, `tab-nav-item`) which were already updated.

Review the component — it should work as-is with the new CSS. No TSX changes needed.

- [ ] **Step 2: Verify visually**

The tab nav should now show: deep teal background, cream text, gold underline on active tab, Inter font, normal case.

---

### Task 11: Update LiveIndicator

**Files:**
- Modify: `frontend/src/components/ui/LiveIndicator.tsx`

- [ ] **Step 1: Replace red with terracotta, remove uppercase**

```tsx
interface LiveIndicatorProps {
  className?: string
  size?: 'sm' | 'md' | 'lg'
  showText?: boolean
}

export function LiveIndicator({
  className = '',
  size = 'md',
  showText = true,
}: LiveIndicatorProps) {
  const sizes = {
    sm: 'w-1.5 h-1.5',
    md: 'w-2 h-2',
    lg: 'w-2.5 h-2.5',
  }

  const textSizes = {
    sm: 'text-[10px]',
    md: 'text-xs',
    lg: 'text-sm',
  }

  return (
    <span className={`inline-flex items-center gap-1.5 ${className}`}>
      <span className="relative flex">
        <span className={`${sizes[size]} rounded-full bg-programme-terracotta animate-pulse-dot`} />
      </span>
      {showText && (
        <span className={`font-body font-semibold text-programme-terracotta ${textSizes[size]}`}>
          Live
        </span>
      )}
    </span>
  )
}
```

Key changes: terracotta instead of red, removed the `animate-ping` secondary dot, removed `uppercase tracking-wider`, `font-display` → `font-body`.

- [ ] **Step 2: Run tests and commit**

Run: `cd frontend && npm test -- --run`

```bash
git add frontend/src/components/ui/LiveIndicator.tsx
git commit -m "style: restyle LiveIndicator with terracotta, simpler pulse"
```

---

### Task 12: Update SkeletonLoader

**Files:**
- Modify: `frontend/src/components/ui/SkeletonLoader.tsx`

- [ ] **Step 1: Update shimmer colors**

The base `animate-shimmer` class was already updated in Task 5 to use warm cream/gray. Review the component and update any inline color references:
- Replace `bg-surface` / `bg-surface-elevated` with `bg-programme-warm-gray` for skeleton shapes
- Remove any `rounded-lg` and replace with `rounded` (the new smaller radius)

- [ ] **Step 2: Run tests and commit**

Run: `cd frontend && npm test -- --run`

```bash
git add frontend/src/components/ui/SkeletonLoader.tsx
git commit -m "style: update skeleton loader for warm cream theme"
```

---

### Task 13: Update EmptyState

**Files:**
- Modify: `frontend/src/components/ui/EmptyState.tsx`

- [ ] **Step 1: Update colors and typography**

- Change SVG icon strokes from current colors to `stroke="currentColor"` with `text-programme-deep-teal` wrapper class
- Change title to use `font-display` (Playfair Display now, no uppercase)
- Change body text to `text-foreground-muted`
- Change action button to use `btn-primary` class
- Remove any animation classes

- [ ] **Step 2: Run tests and commit**

Run: `cd frontend && npm test -- --run`

```bash
git add frontend/src/components/ui/EmptyState.tsx
git commit -m "style: update EmptyState for programme theme"
```

---

## Chunk 3: Pitch Components

### Task 14: Update PitchLayout

**Files:**
- Modify: `frontend/src/components/pitch/PitchLayout.tsx`

- [ ] **Step 1: Flatten the pitch surface**

The `pitch-texture` class was already simplified in Task 5. Review the component for any additional inline styling:
- Field lines: change opacity from `white/20` to `white/40`
- "BENCH" label: change from `font-display uppercase` to `font-body font-medium` and remove uppercase
- Replace any `rounded-lg` with `rounded`
- Remove any gradient references

- [ ] **Step 2: Run tests and commit**

Run: `cd frontend && npm test -- --run`

```bash
git add frontend/src/components/pitch/PitchLayout.tsx
git commit -m "style: flatten pitch surface, update field lines and bench label"
```

---

### Task 15: Update PitchPlayerCard (sticker treatment)

**Files:**
- Modify: `frontend/src/components/pitch/PitchPlayerCard.tsx`

- [ ] **Step 1: Apply sticker styling**

This is the signature visual element. Update:
- Outer wrapper: `bg-programme-cream border-2 border-programme-deep-teal rounded` (the "sticker edge")
- Name bar: `bg-programme-deep-teal text-programme-cream` (solid, no blur/transparency)
- Captain badge: `bg-programme-gold text-programme-deep-teal` (solid circle, no glow/pulse animation)
- Vice-captain badge: `border-2 border-programme-gold text-programme-gold bg-transparent`
- Points overlay: `font-mono bg-programme-cream border border-programme-deep-teal rounded text-xs`
- Remove: `animate-pulse-glow`, `animate-fade-in-up`, any gradient classes
- Remove: `bg-surface/90 backdrop-blur` on name area

- [ ] **Step 2: Run tests and commit**

Run: `cd frontend && npm test -- --run`

```bash
git add frontend/src/components/pitch/PitchPlayerCard.tsx
git commit -m "style: apply sticker treatment to pitch player cards"
```

---

## Chunk 4: App Shell

### Task 16: Update App.tsx

**Files:**
- Modify: `frontend/src/App.tsx`

- [ ] **Step 1: Restyle header, content, and footer**

```tsx
{/* Header */}
<header className="border-b" style={{ borderColor: 'hsl(var(--border))' }}>
  <div className="container mx-auto px-4 py-4">
    <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
      {/* Logo */}
      <div>
        <h1 className="font-display text-2xl sm:text-3xl font-bold text-foreground">
          Super FPL
        </h1>
        <p className="text-xs sm:text-sm text-foreground-muted">
          Fantasy Analytics
        </p>
      </div>

      {/* Navigation */}
      <TabNav
        tabs={tabs}
        activeTab={page}
        onTabChange={(id) => handlePageChange(id as Page)}
        className="w-full sm:w-auto"
      />
    </div>
  </div>
</header>
```

Key changes:
- Remove `sticky top-0 z-50` (header scrolls with page)
- Remove `glass` class
- Remove `animate-fade-in-up` and `animation-delay-100`
- Replace `<GradientText>SUPERFPL</GradientText>` with plain text `Super FPL`
- Remove GradientText import (if no longer used elsewhere in App.tsx)

Footer:
```tsx
<footer className="bg-programme-deep-teal mt-auto">
  <div className="container mx-auto px-4 py-4 text-center">
    <p className="text-xs text-programme-cream/60">Data from Official FPL API</p>
  </div>
</footer>
```

- [ ] **Step 2: Run tests and commit**

Run: `cd frontend && npm test -- --run`

```bash
git add frontend/src/App.tsx
git commit -m "style: restyle app shell — non-sticky header, programme footer"
```

---

## Chunk 5: Page-by-page class cleanup

Each page and its sub-components need inline Tailwind classes cleaned up. The pattern is the same across all pages:

**Search and replace these patterns in each file:**
- `animate-fade-in-up opacity-0` → remove entirely
- `animate-fade-in-up` → remove
- `animate-pulse-glow` → remove
- `animate-scale-in` → remove
- `animate-slide-in-right` → remove
- `animate-slide-in-left` → remove
- `animation-delay-{100,200,300,400,500}` → remove
- `font-display text-xs uppercase tracking-wider` → `font-body text-xs font-medium`
- `font-display text-sm uppercase tracking-wider` → `font-body text-sm font-medium`
- `font-display uppercase tracking-wider` → `font-display` (Playfair, no uppercase)
- `font-display uppercase tracking-wide` → `font-display`
- `tracking-wider` → remove (unless on a form label)
- `text-fpl-green` → `text-programme-teal`
- `text-fpl-purple` → `text-programme-deep-teal`
- `text-highlight` → `text-programme-terracotta`
- `bg-fpl-green` → `bg-programme-teal`
- `bg-fpl-purple` → `bg-programme-deep-teal`
- `border-fpl-green` → `border-programme-teal`
- `border-fpl-purple` → `border-programme-deep-teal`
- `border-highlight` → `border-programme-terracotta`
- `from-fpl-green` / `to-fpl-green` → replace gradient with solid `bg-programme-teal`
- `gradient-text` (CSS class) → `text-programme-teal`
- `bg-gradient-to-*` on player cards → `bg-programme-cream`
- `yellow-400` (captain badges) → `programme-gold`
- `text-red-500` / `text-red-600` / `bg-red-500` / `bg-red-600` → `text-programme-terracotta` / `bg-programme-terracotta`
- `bg-surface/30` → `bg-programme-cream/30`
- `emerald-*` classes (e.g. `to-emerald-600`) → replace gradient with solid `bg-programme-teal`
- `rounded-lg` → `rounded` (on cards/panels — leave buttons and small elements alone)
- `glass` → remove
- `accentColor="green"` → `accentColor="teal"`
- `accentColor="purple"` → `accentColor="teal"`
- `accentColor="highlight"` → `accentColor="terracotta"`

### Task 17: Clean up TeamAnalyzer page + sub-components

**Files:**
- Modify: `frontend/src/pages/TeamAnalyzer.tsx`
- Modify: `frontend/src/components/team-analyzer/ManagerSearch.tsx`
- Modify: `frontend/src/components/team-analyzer/SquadPitch.tsx`
- Modify: `frontend/src/components/team-analyzer/SeasonReview.tsx`
- Modify: `frontend/src/components/team-analyzer/TransferQualityScorecard.tsx`
- Modify: `frontend/src/components/team-analyzer/SquadStats.tsx`
- Modify: `frontend/src/components/team-analyzer/ExpectedActualLuckPanel.tsx`

- [ ] **Step 1: Apply class replacements** (use pattern list above)
- [ ] **Step 2: Update test files** in the same directories — replace old class name assertions (`fpl-green`, `yellow-400`, etc.) with new programme equivalents
- [ ] **Step 3: Run tests**: `cd frontend && npm test -- --run`
- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/TeamAnalyzer.tsx frontend/src/components/team-analyzer/
git commit -m "style: apply programme theme to TeamAnalyzer page"
```

---

### Task 18: Clean up LeagueAnalyzer page + sub-components

**Files:**
- Modify: `frontend/src/pages/LeagueAnalyzer.tsx`
- Modify: `frontend/src/components/comparator/RiskMeter.tsx`
- Modify: `frontend/src/components/comparator/OwnershipMatrix.tsx`
- Modify: `frontend/src/components/league/DecisionDeltaModule.tsx`

- [ ] **Step 1: Apply class replacements**
- [ ] **Step 2: Update test files** with new class name assertions
- [ ] **Step 3: Run tests**: `cd frontend && npm test -- --run`
- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/LeagueAnalyzer.tsx frontend/src/components/comparator/ frontend/src/components/league/
git commit -m "style: apply programme theme to LeagueAnalyzer page"
```

---

### Task 19: Clean up Live page + sub-components

This is the largest page (~796 lines + 11 sub-components). Work through each file.

**Files:**
- Modify: `frontend/src/pages/Live.tsx`
- Modify: `frontend/src/components/live/FormationPitch.tsx`
- Modify: `frontend/src/components/live/LiveFormationPitch.tsx`
- Modify: `frontend/src/components/live/PlayerStatusCard.tsx`
- Modify: `frontend/src/components/live/FixtureScores.tsx`
- Modify: `frontend/src/components/live/CaptainBattle.tsx`
- Modify: `frontend/src/components/live/ComparisonBars.tsx`
- Modify: `frontend/src/components/live/VarianceAnalysis.tsx`
- Modify: `frontend/src/components/live/RankProjection.tsx`
- Modify: `frontend/src/components/live/DifferentialAnalysis.tsx`
- Modify: `frontend/src/components/live/FixtureThreatIndex.tsx`
- Modify: `frontend/src/components/live/PlayersRemaining.tsx`
- Modify: `frontend/src/components/live/GoodWeekBanner.tsx`

- [ ] **Step 1: Apply class replacements to Live.tsx**
- [ ] **Step 2: Apply class replacements to each sub-component** — pay special attention to `LiveFormationPitch.tsx` which has `bg-gradient-to-r`, `rounded-lg`, and `bg-surface/30` that all need updating. Also update auto-sub indicators: replace animated arrows with a simple teal arrow icon, and style sub pills as `bg-programme-cream border border-programme-teal text-programme-teal`.
- [ ] **Step 3: Update test files** with new class name assertions
- [ ] **Step 4: Run tests**: `cd frontend && npm test -- --run`
- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/Live.tsx frontend/src/components/live/
git commit -m "style: apply programme theme to Live page"
```

---

### Task 20: Clean up Planner page + sub-components

**Files:**
- Modify: `frontend/src/pages/Planner.tsx`
- Modify: `frontend/src/components/planner/PlayerExplorer.tsx`
- Modify: `frontend/src/components/planner/PlayerDetailPanel.tsx`

- [ ] **Step 1: Apply class replacements**
- [ ] **Step 2: Update test files** with new class name assertions
- [ ] **Step 3: Run tests**: `cd frontend && npm test -- --run`
- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/Planner.tsx frontend/src/components/planner/
git commit -m "style: apply programme theme to Planner page"
```

---

### Task 21: Clean up Admin page + remaining components

**Files:**
- Modify: `frontend/src/pages/Admin.tsx`
- Modify: `frontend/src/components/common/PositionBadge.tsx`
- Modify: `frontend/src/components/common/PlayerCard.tsx`
- Modify: `frontend/src/components/predictor/PredictionTable.tsx`
- Modify: `frontend/src/components/live/TeamShirt.tsx` (if it has color references)

- [ ] **Step 1: Apply class replacements**
- [ ] **Step 2: Run tests**: `cd frontend && npm test -- --run`
- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/Admin.tsx frontend/src/components/common/ frontend/src/components/predictor/ frontend/src/components/live/TeamShirt.tsx
git commit -m "style: apply programme theme to Admin and remaining components"
```

---

## Chunk 6: Verification & Documentation

### Task 22: Full test suite and build verification

- [ ] **Step 1: Run full test suite**

Run: `cd frontend && npm test -- --run`
Expected: All tests pass. Fix any failures.

- [ ] **Step 2: Run production build**

Run: `cd frontend && npx vite build`
Expected: Build succeeds with no errors.

- [ ] **Step 3: Run TypeScript check**

Run: `cd frontend && npx tsc --noEmit`
Expected: No type errors.

- [ ] **Step 4: Run linter**

Run: `cd frontend && npm run lint`
Expected: No lint errors.

---

### Task 23: Update CLAUDE.md Visual Identity section

**Files:**
- Modify: `CLAUDE.md` (project root)

- [ ] **Step 1: Replace the Visual Identity section**

Update the entire `## Visual Identity` section and its subsections to document the new programme theme: palette, typography (Playfair Display / Inter / IBM Plex Mono), component patterns (flat borders, sticker cards, solid accent bars), and the "don'ts" list (don't add gradients, don't use uppercase on headings, don't add entry animations, etc.).

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: update CLAUDE.md visual identity for programme theme"
```

---

### Task 24: Visual smoke test

- [ ] **Step 1: Start the dev server**

Run: `npm run up`

- [ ] **Step 2: Check each tab**

Walk through all four tabs (Season, League, Live, Planner) and verify:
- Light cream background throughout
- Deep teal header, gold active tab underline
- Playfair Display headings in normal case
- Flat bordered cards with colored top borders
- Player stickers on pitch with cream bg and thick teal borders
- No gradients, no glass morphism, no entry animations
- Terracotta live indicator
- Tables with deep teal headers and alternating row stripes

- [ ] **Step 3: Fix any visual issues found**

- [ ] **Step 4: Final commit if fixes were needed**

```bash
git add -A
git commit -m "fix: visual polish from smoke test"
```
