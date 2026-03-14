# Teletext Redesign — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development or superpowers:executing-plans.

**Goal:** Replace the dark broadcast dashboard aesthetic with an authentic BBC Ceefax / Teletext identity.

**Architecture:** Same top-down approach: foundation (fonts, CSS vars, Tailwind, component CSS) → UI components → pitch → app shell → pages. Foundation changes cascade automatically.

**Spec:** `docs/superpowers/specs/2026-03-14-teletext-redesign-design.md`

---

## Chunk 1: Foundation

### Task 1: Fonts

**Files:** `frontend/index.html`

Replace Google Fonts link with:
```html
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600;700&family=VT323&display=swap" rel="stylesheet">
```

Commit: `chore: swap fonts to VT323 + JetBrains Mono`

### Task 2: CSS custom properties

**Files:** `frontend/src/index.css` — replace `:root` block

```css
:root {
    --font-teletext: 'VT323', monospace;
    --font-teletext-alt: 'JetBrains Mono', monospace;

    /* Teletext 8-color palette */
    --tt-black: 0 0% 0%;
    --tt-white: 0 0% 100%;
    --tt-cyan: 180 100% 50%;
    --tt-green: 120 100% 50%;
    --tt-yellow: 60 100% 50%;
    --tt-red: 0 100% 50%;
    --tt-blue: 240 100% 50%;
    --tt-magenta: 300 100% 50%;

    /* Semantic mapping */
    --background: var(--tt-black);
    --surface: 0 0% 4%;            /* #0A0A0A - very slight lift */
    --surface-elevated: 0 0% 7%;   /* #121212 */
    --surface-hover: 0 0% 7%;

    --foreground: var(--tt-white);
    --foreground-muted: 0 0% 40%;  /* #666 dim text */
    --foreground-dim: 0 0% 25%;    /* #404040 */

    --primary: var(--tt-cyan);
    --primary-foreground: var(--tt-black);
    --secondary: var(--tt-blue);
    --secondary-foreground: var(--tt-white);
    --accent: var(--tt-yellow);
    --accent-foreground: var(--tt-black);
    --destructive: var(--tt-red);
    --destructive-foreground: var(--tt-white);
    --muted: 0 0% 7%;
    --muted-foreground: 0 0% 40%;

    --border: 0 0% 20%;     /* #333 */
    --input: 0 0% 0%;
    --ring: var(--tt-cyan);
    --radius: 0px;           /* no rounded corners */

    --card: 0 0% 0%;
    --card-foreground: var(--tt-white);
    --popover: 0 0% 0%;
    --popover-foreground: var(--tt-white);
  }
```

Commit: `style: replace CSS vars with teletext palette`

### Task 3: Tailwind config

**Files:** `frontend/tailwind.config.js`

- Remove `darkMode`
- Replace fontFamily with single `teletext` family: `['var(--font-teletext)', 'monospace']`
  - Keep `mono` mapped to same thing
  - `display` and `body` also map to `var(--font-teletext)` (everything is one font)
- Add teletext color tokens:
```js
tt: {
  black: '#000000',
  white: '#FFFFFF',
  cyan: '#00FFFF',
  green: '#00FF00',
  yellow: '#FFFF00',
  red: '#FF0000',
  blue: '#0000FF',
  magenta: '#FF00FF',
  dim: '#333333',
},
```
- Remove `fpl` and `highlight` color blocks
- Set `borderRadius` all to `0px` (everything square)
- Strip animations to just `shimmer` and a new `blink`:
```js
animation: {
  shimmer: 'shimmer 2s infinite linear',
  blink: 'blink 1s step-end infinite',
},
keyframes: {
  shimmer: { ... },
  blink: {
    '50%': { opacity: '0' },
  },
},
```

Commit: `style: update tailwind config for teletext theme`

### Task 4: Base typography + body

**Files:** `frontend/src/index.css` — second `@layer base` block

- All elements use `font-family: var(--font-teletext)`
- `h1, h2, h3, .font-display` — `text-transform: uppercase` (Ceefax headings ARE uppercase)
- `.font-mono` — same font (no separate mono font in teletext)
- Body: `font-size: 112.5%` stays, `line-height: 1.5` for VT323 readability

Commit: `style: set teletext typography for all elements`

### Task 5: Animations + utilities

**Files:** `frontend/src/index.css` — keyframes + utilities blocks

Strip down to:
- `shimmer` keyframe (for skeleton loading, using black/dim colors)
- `blink` keyframe (for live indicator)
- `fadeIn` and `drawerSlideIn` (kept for functional drawers)
- Utility classes: `.animate-shimmer`, `.animate-blink`, `.animate-fade-in`, `.animate-drawer-slide-in`
- Remove: all fade-in-up, slide-in, pulse-glow, scale-in, count-up, draw-path, animation-delay-*, gradient-text, clip-angular, glass
- `.pitch-texture` → `background: #000000` (black, no grass)

Commit: `style: strip animations to blink + shimmer`

### Task 6: Component classes

**Files:** `frontend/src/index.css` — `@layer components` block

Replace entirely with teletext-themed versions:

- `.stat-panel` — no border/bg, just padding. Hover: bg `#111`
- `.broadcast-card` — no border. Just a section container.
- `.broadcast-card-header` — cyan background, black text, uppercase, square
- `.input-broadcast` — black bg, white text, 1px cyan border, square. Focus: yellow border
- `.form-section-card` — black bg, 1px dim border
- `.form-label` — cyan text, uppercase
- `.form-pill-lock` — green text/border on black
- `.form-pill-avoid` — red text/border on black
- `.form-pill-team` — magenta text/border on black
- `.btn-primary` — cyan bg, black text, square, uppercase
- `.btn-secondary` — black bg, cyan border, cyan text, square
- `.tab-nav` — flex row, gap. No background container.
- `.tab-nav-item` — colored block (red/green/yellow/blue per tab position), black text. Active: white text + slightly brighter.
- `.tab-nav-item.active::after` — no underline needed (color block is sufficient)
- `.table-broadcast thead` — cyan text on black (no background fill)
- `.table-broadcast tbody tr` — border-bottom 1px `#333`, hover bg `#111`
- `.table-broadcast td/th` — same padding

Commit: `style: rewrite component classes for teletext theme`

---

## Chunk 2: UI Components

### Task 7: GradientText → TeletextText

Replace `GradientText.tsx` with a simple colored text component:
```tsx
export function TeletextText({ children, color = 'cyan', ... }) {
  return <Component className={`text-tt-${color}`}>{children}</Component>
}
export const GradientText = TeletextText // backwards compat
```
Update all consumers. Update test file.

Commit: `refactor: replace GradientText with TeletextText`

### Task 8: BroadcastCard

Replace accent colors with teletext colors: `cyan` (default), `yellow`, `red`, `blue`, `magenta`.
Header becomes colored background block with black text.
Remove animations. Square corners.

Commit: `style: restyle BroadcastCard as teletext section`

### Task 9: StatPanel

Remove bordered panel styling. Just colored text layout:
- Label in cyan, value in white/green/yellow
- Accent bar: 1px left border in cyan (or remove entirely)
- Trend: green `+` / red `-`
- No ring, no gradient-text

Commit: `style: restyle StatPanel as teletext text block`

### Task 10: TabNav

Implement Ceefax colored-key navigation:
- 4 tabs get specific colors: red, green, yellow, blue (mapped by index/id)
- Each tab is a colored background block with black text
- Active tab: white text on the colored block
- Square corners

Commit: `style: restyle TabNav as Ceefax colored keys`

### Task 11: LiveIndicator

Red `●` with CSS `animate-blink`. Text "LIVE" in red. Remove ping animation, remove font-display.

Commit: `style: restyle LiveIndicator as blinking teletext`

### Task 12: SkeletonLoader

Blinking `█` block characters in dim gray. Simple, authentic.

Commit: `style: restyle skeleton loader as teletext blocks`

### Task 13: EmptyState

Plain text message in cyan on black. Remove SVG icons. Simple `---` divider above/below.

Commit: `style: restyle EmptyState as teletext message`

---

## Chunk 3: Pitch + App Shell

### Task 14: PitchLayout

- Black background (no grass texture)
- Field lines: dim green (`#006600`) thin borders
- Bench label: cyan text, `─── BENCH ───` style
- Square corners everywhere

Commit: `style: restyle pitch as teletext block display`

### Task 15: PitchPlayerCard

Replace shirt graphic view with text block:
```
┌──────────┐
│ 128  (C) │
│ Haaland  │
│ MCI      │
└──────────┘
```
- Border in green (dim for bench)
- Points in yellow, name in white, team in cyan
- Captain: yellow `(C)`, vice: cyan `(V)`
- No shirt image, no gradients

Commit: `style: restyle player cards as teletext text blocks`

### Task 16: App.tsx

Header: Ceefax-style page header bar
```
P101  SUPERFPL           Sat 15 Mar  14:22
```
- White text on black, site name in cyan
- Below: colored-key TabNav

Footer: simple text in dim gray
- Font toggle link: `[A] FONT: PIXEL` / `[A] FONT: MONO`
- Implement font toggle: click toggles class on `<html>`, reads/writes `localStorage`

Remove sticky header, glass, all animations.

Commit: `style: restyle app shell as teletext page`

---

## Chunk 4: Page Cleanup

Same approach as before — systematic class replacements across all pages.

**Key patterns:**
- `animate-fade-in-up opacity-0` → remove
- All `animate-*` and `animation-delay-*` → remove
- `text-fpl-green` → `text-tt-green`
- `text-fpl-purple` → `text-tt-magenta`
- `text-highlight` → `text-tt-red`
- `bg-fpl-green` → `bg-tt-green`
- `border-fpl-green` → `border-tt-green`
- `yellow-400` → `tt-yellow`
- `text-red-500/600` → `text-tt-red`
- `rounded-lg` / `rounded` → remove (radius is 0)
- `glass` → remove
- `font-display uppercase tracking-wider` → `uppercase` (font is already teletext)
- `gradient-text` → `text-tt-cyan`
- All `bg-gradient-to-*` → solid teletext color
- `accentColor="green"` → `accentColor="cyan"`
- `accentColor="purple"` → `accentColor="magenta"`
- `accentColor="highlight"` → `accentColor="red"`

### Task 17: TeamAnalyzer + sub-components
### Task 18: LeagueAnalyzer + sub-components
### Task 19: Live + sub-components
### Task 20: Planner + sub-components
### Task 21: Admin + remaining components

Each task: apply patterns, update test files, run tests, commit.

---

## Chunk 5: Verification & Docs

### Task 22: Full verification
- `cd frontend && npm test -- --run` (all pass)
- `cd frontend && npx vite build` (succeeds)
- `cd frontend && npx tsc --noEmit` (no errors)
- `cd frontend && npm run lint` (no new errors)

### Task 23: Update CLAUDE.md Visual Identity section

### Task 24: Visual smoke test
