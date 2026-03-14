# Visual Redesign: Teletext / Ceefax

**Date:** 2026-03-14
**Status:** Approved
**Scope:** Full visual reskin — colors, typography, component styling, animations, layout feel. Component structure and functionality unchanged. Replaces the previous programme theme spec (2026-03-13).

## Direction

Replace the current dark-mode sports broadcast dashboard aesthetic with an authentic **BBC Ceefax / Teletext** identity. Black background, pixel monospace font, strict 8-color palette, block graphics, square corners, no gradients, no shadows. The site should feel like navigating Ceefax pages on a CRT television.

## Font

**Single font for everything:** `VT323` from Google Fonts — closest to authentic Ceefax/Teletext available as a web font.

**Readability toggle:** A small toggle (in footer or header) lets users swap to `JetBrains Mono` for improved readability on data-heavy pages. Preference stored in `localStorage`. The toggle swaps a CSS custom property; all elements use the same font variable.

```css
--font-teletext: 'VT323', monospace;        /* default: pixel */
--font-teletext-alt: 'JetBrains Mono', monospace; /* toggle: readable */
```

**Typography rules:**
- Everything uses `var(--font-teletext)` — headings, body, stats, labels
- No font-family mixing. One font everywhere.
- Headings are simply larger text (1.5rem–2rem), not a different font
- ALL TEXT IS UPPERCASE for headings and labels (authentic Ceefax style)
- Body text and data values can be mixed case

## Color Palette

The classic Teletext 8-color palette only. No other colors.

| Name | Hex | CSS Variable | Usage |
|------|-----|-------------|-------|
| Black | `#000000` | `--tt-black` | Page background |
| White | `#FFFFFF` | `--tt-white` | Primary body text, default text |
| Cyan | `#00FFFF` | `--tt-cyan` | Headlines, links, interactive accents |
| Green | `#00FF00` | `--tt-green` | Positive values, success, FPL points |
| Yellow | `#FFFF00` | `--tt-yellow` | Highlights, captain badges, featured stats |
| Red | `#FF0000` | `--tt-red` | Negative values, alerts, live indicator |
| Blue | `#0000FF` | `--tt-blue` | Section header backgrounds, nav key |
| Magenta | `#FF00FF` | `--tt-magenta` | Secondary accent, special features |

**Rules:**
- No gradients anywhere. All fills are flat solid colors.
- No opacity/transparency on colors (authentic teletext has no alpha).
- No shadows, no blur, no glass morphism.
- Text is always a teletext color on black, or black text on a teletext color block.

## Component Styling

### Page Layout

- Black background everywhere
- Max-width container (same as current ~1400px)
- Content is text-on-black, not wrapped in cards

### Header (Ceefax page header)

```
P101  SUPERFPL           Sat 15 Mar  14:22
```

- White text on black
- Page number prefix (P101 for Season, P201 for League, P301 for Live, P401 for Planner)
- Site name in cyan
- Date/time right-aligned in white
- Below: colored-key navigation bar

### Navigation (Ceefax colored keys)

```
 Season    League    Live    Planner
```

Each tab rendered as a colored block (red/green/yellow/blue background with black text), mimicking the four Ceefax fastext keys. Active tab gets white text on the colored background with a subtle indicator (e.g. `>>` prefix or brighter block).

### Section Headers

Full-width colored background bar with black text (like Ceefax section banners):
- Default: cyan background, black text
- Alert/live: red background, white text
- Feature: yellow background, black text
- Secondary: blue background, white text

No rounded corners. Square blocks.

### Stat Panels → Stat Blocks

No bordered panels. Just colored text on black:
```
TOTAL POINTS        128
GAMEWEEK RANK    12,451
TEAM VALUE       £104.2m
```
- Labels in cyan, values in white or green (for highlighted)
- Captain/highlight values in yellow
- Negative values in red

### Cards → Sections

No card containers. Sections are separated by:
- A colored section header bar (as described above)
- Or a horizontal rule made of `─` characters in cyan
- Content flows directly on black background

### Buttons

Colored background blocks with black text:
- Primary: cyan background, black text
- Destructive: red background, white text
- Secondary: white text on black with cyan border (1px solid)

Square corners. No rounded anything.

### Tables

- Header row: cyan text (or cyan background with black text for emphasis)
- Data rows: white text, separated by thin horizontal lines (`border-bottom: 1px solid` in dim color like `#333`)
- Alternating row colors: not needed (black background throughout)
- Hover: row background becomes very dark gray (`#111`)
- Numbers right-aligned in green/white

### Form Inputs

- Black background, white text, 1px cyan border
- Focus: border changes to yellow
- Placeholder text in dim gray (`#666`)
- No rounded corners

### Pills/Tags

- Colored text on black with 1px colored border, square corners
- Lock: green text/border
- Avoid: red text/border
- Team: magenta text/border

### Live Indicator

Red `●` character (or block `█`) with simple blink animation (CSS `animation: blink 1s step-end infinite`). Text "LIVE" in red next to it.

### Empty States

Simple text message in cyan. No SVG illustrations. Maybe a teletext-style block art character if we're feeling fancy.

### Skeleton Loaders

Blinking `█` block characters in dim gray, simulating teletext page loading.

## Pitch & Formation View

Structure unchanged. Visual reskin:

### Pitch Surface
- Pure black background (no green, no grass texture)
- Field lines drawn with dim green (`#006600`) thin borders
- Or: use block characters `│` `─` `┌` `┐` `└` `┘` for field markings in dim green

### Players on Pitch
- Player name in green text on black
- Points overlay: yellow text
- Captain: yellow `C` prefix before name, or yellow block background
- Vice-captain: cyan `V` prefix
- No shirt graphics on the teletext view — just colored text blocks
- Each player rendered as a small text block:
  ```
  ┌──────────┐
  │ 128  (C) │
  │ Haaland  │
  │ MCI      │
  └──────────┘
  ```
  Border in green, text in white, points in yellow, captain marker in yellow

### Bench
- Separated by `─── BENCH ───` text rule in cyan
- Same player block style but dimmer (gray text instead of white)

## Animations

**Almost none.** Teletext doesn't animate.

- Live indicator: CSS `blink` animation (step-end, authentic CRT feel)
- Skeleton loading: blinking blocks
- No entry animations, no transitions, no hover effects beyond row highlight
- Tab switches: instant, no animation

## Font Toggle

- Small text link in the header or footer: `[A] FONT: PIXEL` / `[A] FONT: MONO`
- Clicking toggles between VT323 and JetBrains Mono
- Stored in `localStorage` key `superfpl-font`
- Implemented by toggling a CSS class on `<html>` element
- All font references use `var(--font-teletext)` which changes based on the class

## Responsive Behavior

- Same breakpoints as current
- On mobile, the colored-key nav wraps or stacks
- Tables get horizontal scroll as currently
- Player blocks on pitch may shrink text size

## AccentText / GradientText

Replace with simple colored `<span>` elements. No component needed — just apply `text-tt-cyan` / `text-tt-yellow` / etc. directly. The AccentText/GradientText component can be simplified to just apply a teletext color class.
