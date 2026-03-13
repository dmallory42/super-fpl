# Visual Redesign: Vintage Football Programme

**Date:** 2026-03-13
**Status:** Approved
**Scope:** Full visual reskin — colors, typography, component styling, animations, layout feel. Component structure and functionality unchanged.

## Direction

Replace the current dark-mode sports broadcast dashboard aesthetic with a **vintage football programme** identity. Light cream base, warm earthy palette, serif headlines, flat bordered components, minimal animation. The site should feel like reading a well-designed matchday programme, not staring at a tech dashboard.

**Fallback:** If the retro programme feel ends up too heavy-handed, pivot to a modern editorial style (The Athletic-inspired) using the same palette and typography.

## Color System

### Palette (from Coolors)

| Role | Name | Hex | Usage |
|------|------|-----|-------|
| Primary text | Deep Teal | `#264653` | Headers, nav background, primary text, borders |
| Interactive | Persian Green | `#2A9D8F` | Links, active states, pitch accents, default accent bars |
| Highlight | Saffron | `#E9C46A` | Captain badges, featured stats, active tab indicator |
| Secondary accent | Sandy Brown | `#F4A261` | Warnings, mid-tier indicators |
| Emphasis | Burnt Sienna | `#E76F51` | Alerts, negative trends, live indicator |

### Surfaces

| Surface | Color | Usage |
|---------|-------|-------|
| Page background | `#FAF6F1` (warm cream) | Main background |
| Card background | `#FFFFFF` | Content panels |
| Card border | `#264653` at 15% opacity | Panel edges |
| Muted background | `#F0EBE3` (warm gray) | Table stripes, secondary sections |
| Foreground muted | `#264653` at 60% opacity | Secondary text |

No gradients. All fills are flat solid colors. No glass morphism, no backdrop blur, no box shadows. Light mode only (dark mode dropped).

## Typography

| Role | Font | Style | Usage |
|------|------|-------|-------|
| Display | Playfair Display | Serif, bold, normal case | Headlines, section titles, page names |
| Body | Inter | Sans-serif, regular/medium | Body text, descriptions, labels |
| Mono | IBM Plex Mono | Monospace, medium | Stats, numbers, prices, points |

### Key rules

- No forced uppercase anywhere. Headlines use normal title case.
- No gradient text. Color emphasis uses solid teal or gold. The `GradientText` component should be replaced with an `AccentText` component that renders solid colored text (teal or gold).
- Page titles: Playfair Display bold, 2rem, deep teal.
- Card titles: Playfair Display bold, 1.25rem, deep teal.
- Stat labels: Inter medium, 0.75rem, muted text, letter-spacing 0.05em.
- Stat values: IBM Plex Mono medium, 1.5rem, deep teal.

## Component Styling

### Cards (replacing BroadcastCard)

- White background, 1.5px solid border (deep teal at 15% opacity)
- Solid colored top border (3px) instead of gradient header bars: teal for standard, gold for featured, terracotta for alerts
- Generous inner padding
- Max 4px border-radius — sharp edges for print feel

### Stat Panels

- White background with thin border (same as cards)
- Solid color left accent bar (no gradient): teal default, gold for highlights
- Values in IBM Plex Mono, deep teal
- Highlighted values: solid gold or teal text
- Trend indicators: `+` / `-` prefix in teal/terracotta (no triangle arrows)

### Player Cards / Stickers

- Thick border (2-3px) in deep teal — the "sticker edge"
- Cream background (`#FAF6F1`)
- Player name in solid deep teal bar at bottom
- Captain badge: solid gold circle, deep teal text, no animation
- Vice-captain: outlined circle, same colors
- Team shirt on cream background (no gradient behind it)

### Buttons

- Primary: solid deep teal background, cream text, no gradient/shadow
- Secondary: cream background, deep teal border and text
- Both: Inter medium, normal case

### Tables

- Horizontal rules only (no vertical borders, no left accent bar)
- Alternating rows: white / warm gray (`#F0EBE3`)
- Header row: deep teal background, cream text, Playfair Display

### Form Inputs

- White background, 1.5px solid border (deep teal at 15% opacity)
- On focus: border transitions to solid teal (`#2A9D8F`)
- Inter regular, deep teal text
- No animated borders or glow effects
- Select dropdowns: same styling, simple chevron indicator

### Navigation

- Deep teal background bar (solid, no glass/blur)
- Active tab: gold underline (solid 3px)
- Tab labels: Inter medium, cream text, normal case

## Pitch & Formation View

Structure and functionality unchanged. Visual reskin only.

### Pitch surface

- Solid muted green (`#4A8C5C`) with slightly lighter center area
- No repeating gradient lines or texture overlay
- Field lines: cream/white at 40% opacity

### Players on pitch

- Same sticker treatment: cream background, thick deep teal border
- Name bar at bottom in solid deep teal
- Captain: solid gold badge
- Points overlay: IBM Plex Mono in small bordered box (top-right)

### Bench

- Separated by horizontal rule (deep teal at 20% opacity)
- "Bench" label: Inter medium, deep teal, normal case
- Slightly smaller stickers, same style

### Auto-sub indicators

- Simple teal arrow icon (no animation)
- Cream pill with teal border showing the sub pair

## Animations & Interactions

### Removed

- `fade-in-up` entry animations on all content
- `pulse-glow` on captain badges
- Staggered `animation-delay` on card lists
- `scale-in`, `slide-in-right`, `slide-in-left` transitions
- Glass morphism hover effects on stat panels

### Kept (restyled)

- Loading skeleton shimmer: warm cream/gray sweep instead of dark surface
- Live indicator: terracotta dot with simple pulse (replaces red)

### New

- Simple CSS transitions (150ms) on hover: border color change, subtle background tint
- Tab switches: instant, no animation
- Buttons: slight darken on hover via background color shift
- Tables: row highlight on hover with warm gray background

Philosophy: **print doesn't animate.** Interactions are responsive but understated.

## Layout

### Header

- "Super FPL" in Playfair Display bold, deep teal on cream (no gradient text)
- Horizontal nav bar, deep teal background, flush to header
- Not sticky — scrolls with the page

### Page structure

- Max-width container with generous vertical spacing between sections
- Sections separated by thin horizontal rules (deep teal at 15% opacity) instead of card nesting
- Less card wrapping — stats and tables can sit directly on page background with a rule above
- More vertical flow (reading down a page) rather than grid-of-panels where practical

### Footer

- Simple deep teal bar, cream text, minimal

### Empty states

- Simple line art icon in deep teal
- Playfair Display heading, Inter body text
- No animation
