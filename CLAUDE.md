# Super FPL - Claude Instructions

## Project Overview

Fantasy Premier League analytics application with a PHP backend API and React/TypeScript frontend.

## Quick Start

```bash
cd /Users/mal/projects/personal/superfpl

# First time setup
npm install                    # Install all deps (frontend via workspaces)
npm run up:build               # Build containers, install PHP deps, start dev server

# Daily development
npm run up                     # Start API + frontend dev server
npm run down                   # Stop API containers
```

| Command | Description |
|---------|-------------|
| `npm run up` | Start API containers + frontend dev server |
| `npm run up:build` | Rebuild containers + start (use after Dockerfile changes) |
| `npm run down` | Stop API containers |
| `npm run api` | Start only API containers |
| `npm run api:logs` | Tail API container logs |
| `npm run dev` | Start only frontend dev server |

The Docker entrypoint automatically runs `composer install` if the vendor directory is missing.

## Project Structure

```
super-fpl/
├── api/                    # PHP backend
│   ├── public/index.php    # Main router - all endpoints defined here
│   ├── src/
│   │   ├── Services/       # Business logic services
│   │   └── Sync/           # FPL API data sync
│   └── data/schema.sql     # SQLite schema
├── frontend/               # React/TypeScript frontend
│   └── src/
│       ├── pages/          # Page components (4 tabs)
│       ├── components/     # Reusable components
│       ├── hooks/          # React Query hooks
│       └── api/client.ts   # API client with types
├── packages/
│   └── fpl-client/         # FPL API client library
└── deploy/                 # Docker/nginx configs
```

## Visual Identity

The frontend uses a **BBC Ceefax / Teletext aesthetic** — black background, pixel font, strict 8-color palette, block graphics, square corners, no gradients, no shadows. The site should feel like navigating Ceefax pages on a CRT television.

### Design Principles
- **Authentic Teletext**: Block characters, 1px solid borders, flat colors only
- **Information-Dense**: Data-first layouts, no decorative elements
- **Retro-Digital**: Square corners everywhere, no rounded edges, no shadows

### Typography

**Two fonts, clear roles:**
- **VT323** (`font-teletext`) — the *voice*. Default body font. All headings, labels, navigation, buttons, player names, body text. Everything that *speaks* to the user.
- **JetBrains Mono** (`font-mono`) — the *data*. Opt-in via `font-mono` class. Table cell values, stat panel numbers, form inputs, prices, scores, ranks. Everything that *shows* numbers.

**Rule**: If it's a word, it's VT323. If it's a number in a data context, it's JetBrains Mono.

**Size scale** (base 112.5% = 18px):
| Ceefax Concept | Tailwind | Usage |
|---|---|---|
| Double-height title | `text-3xl` | App name, page numbers |
| Double-height header | `text-2xl` | Page section titles |
| Emphasized | `text-xl` | Key stat values |
| Normal text | `text-base` | Body text, descriptions |
| Dense data | `text-sm` | Tables, nav, labels, buttons |
| Fine print | `text-xs` | Timestamps, attribution |

**VT323 readability rule**: Never use VT323 smaller than `text-sm`. For dense data that needs smaller text, use `font-mono`.

Font toggle stored in `localStorage` key `superfpl-font`. Toggle component in App footer. Modes: `DATA` (VT323 + JetBrains Mono for numbers) and `CEEFAX` (all VT323, `html.font-pixel-mode` class).

### Color Palette (Teletext 8-Color)
| Token | Value | Usage |
|-------|-------|-------|
| `tt-black` | `#000000` | Background |
| `tt-white` | `#FFFFFF` | Primary text |
| `tt-cyan` | `#00FFFF` | Headers, labels, links |
| `tt-green` | `#00FF00` | Success, positive values |
| `tt-yellow` | `#FFFF00` | Points, captain badges, highlights |
| `tt-red` | `#FF0000` | Live indicators, negative values, warnings |
| `tt-blue` | `#0000FF` | Secondary accent, info |
| `tt-magenta` | `#FF00FF` | Chips, special features |
| `tt-dim` | `#666666` | Muted/disabled text (only non-palette exception) |

### UI Components (`frontend/src/components/ui/`)
- `StatPanel` - Cyan labels, yellow/white values, no accent bar
- `BroadcastCard` - Solid color header blocks (cyan/yellow/red/blue/magenta), 1px borders
- `TeletextText` / `GradientText` - Flat colored text (no gradients), color prop maps to tt-* tokens
- `LiveIndicator` - Blinking red `●` with `animate-blink` (step-end timing)
- `TabNav` - Colored-key navigation (CSS nth-child assigns teletext colors)
- `EmptyState` - `─` dividers, cyan title, text-only
- `SkeletonLoader` - Blinking `█` block characters instead of shimmer gradients

### Pitch Components (`frontend/src/components/pitch/`)
- `PitchLayout` - Black bg, dim green (`#006600`) field lines, `─── BENCH ───` label
- `PitchPlayerCard` - Text blocks with 1px borders, no shirt graphics

### Animation Guidelines
- **No entry animations** — no `animate-fade-in-up`, no staggered delays
- Live elements: `animate-blink` with `step-end` timing function (authentic CRT blink)
- Loading: blinking `█` characters
- Keep animations minimal — teletext didn't animate

### Styling Patterns
```tsx
// Headlines — uppercase, cyan
<h2 className="text-tt-cyan text-2xl font-bold uppercase tracking-wider">

// Stats — yellow for values
<span className="text-tt-yellow text-2xl font-bold">

// Inputs
<input className="input-broadcast" />  /* black bg, 1px cyan border */

// Buttons
<button className="btn-primary">   /* tt-cyan bg, black text */
<button className="btn-secondary"> /* 1px border, tt-cyan text */

// Cards with sections
<BroadcastCard title="Section Title" accentColor="cyan">

// Player cards — bordered text blocks
<div className="border border-tt-cyan bg-black px-2 py-1">
// Captain badges: text-tt-yellow (C), Vice: text-tt-cyan (V)
```

### Ceefax Page Header
The app header shows page numbers (P101, P201, P301, P401) per tab and a live clock — mimicking Ceefax page navigation.

### Don'ts
- Don't use rounded corners (all borderRadius forced to 0px in Tailwind config)
- Don't use gradients or shadows
- Don't use entry/reveal animations
- Don't use colors outside the 8-color teletext palette (+ tt-dim)
- Don't use `font-display` or `font-body` — they don't exist. Use `font-teletext` (VT323) or `font-mono` (JetBrains Mono)
- Don't use `font-mono` for words/labels — only for numeric data values
- Don't render VT323 text smaller than `text-sm` — it becomes unreadable
- Don't use opacity for hover states — use color changes instead

## Workflow

- **Atomic commits**: Commit after each completed feature or bug fix, not in bulk. Each commit should have passing tests and a clean lint/typecheck.
- **Always add a test** for any new or changed behaviour — no exceptions. If you're changing how something works, update or add a test that covers it.
- **TDD preferred**: Write the failing test first when practical, then implement.

## Code Conventions

### Code Style

Prettier enforces formatting (runs on pre-commit via Husky + lint-staged):
- No semicolons
- Single quotes
- 100 char line width
- Trailing commas in ES5 positions

ESLint v9 flat config in `frontend/eslint.config.js` — TypeScript, React hooks, React refresh.

### PHP Backend
- PSR-4 autoloading, namespace: `SuperFPL\Api\`
- Maia attribute controllers in `api/src/Controllers/` (routes are no longer hand-wired in a giant index file)
- Services in `api/src/Services/` for business logic
- Prediction internals in `api/src/Prediction/`
- Shared DB connection via `Maia\Orm\Connection` from `api/bootstrap.php`
- FplClient uses fluent API: `$fplClient->entry($id)->getRaw()`
- SQLite schema in `api/data/schema.sql`, incremental migrations in `SuperFPL\Api\SchemaMigrator`

### TypeScript Frontend
- React functional components with hooks
- TanStack Query for all server state (hooks in `src/hooks/`). No Redux, Zustand, or Context API — local UI state uses `useState`
- Tailwind CSS for styling
- Types defined in `src/types.ts` and `src/api/client.ts`
- Import alias: `@/` → `./src/` (configured in vite + tsconfig)

## Dev Architecture

Frontend (Vite :5173) proxies `/api/*` to nginx (:8080) → PHP-FPM. SQLite DB auto-migrates on container start via Docker entrypoint.

## Building & Linting

```bash
cd frontend && npm run build   # TypeScript check + Vite build
cd frontend && npm run lint    # ESLint
php -l api/src/Services/SomeService.php  # PHP syntax check
```

## Debug Logs

- API errors/exceptions are written to `api/cache/logs/api-error.log`.
- Use `npm run api:logs` or `docker compose logs -f nginx php cron` for container-level logs.
- If an API 500 response includes `request_id`, search that ID in `api/cache/logs/api-error.log` first.

## Testing

**IMPORTANT: Use Test-Driven Development (TDD) for all new features.**

1. Write tests BEFORE implementing new functionality
2. Run tests to verify they fail (red)
3. Implement the minimum code to pass tests (green)
4. Refactor while keeping tests green

For bug fixes, write a failing test that reproduces the bug first, then fix it.

### Running Tests

```bash
# Backend tests (PHPUnit)
cd api && ./vendor/bin/phpunit

# Frontend tests (Vitest)
cd frontend && npm test

# Run specific test file
cd api && ./vendor/bin/phpunit tests/Services/GameweekServiceTest.php
cd frontend && npm test -- src/hooks/useLive.test.tsx
```

### Test Coverage Requirements

- **New API endpoints**: Add service tests in `api/tests/Services/`
- **New React components**: Add component tests with `@testing-library/react`
- **New hooks**: Test conditional fetching and data transformation
- **Utility functions**: Unit test all pure functions

### Test Structure

```php
// Backend: api/tests/Services/ExampleServiceTest.php
use Maia\Core\Testing\TestCase;

class ExampleServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->execute('CREATE TABLE ...');
    }
}
```

```typescript
// Frontend: src/components/Example.test.tsx
import { render, screen } from '../test/utils'
// Use custom render with QueryClientProvider
// Vitest uses jsdom + globals (no need to import describe/it/expect)
```

## Data Sync

Cron runs automatically in Docker. To manually sync:

| Command | What it does |
|---------|-------------|
| `npm run sync:pre` | Pre-deadline: players, fixtures, odds, predictions |
| `npm run sync:post` | Post-gameweek: results, history |
| `npm run sync:slow` | Expensive: season history, appearances |
| `npm run sync:samples` | Sample managers for comparisons |
| `npm run sync:snapshot` | Snapshot current predictions |

Regenerate predictions after model changes: `docker compose exec php php cron/predictions.php <GW>`

### Cron Schedule (UTC)

Configured in `deploy/crontab` and executed by the `cron` container in `docker-compose.yml`.

- `01:00` daily: `php cron/fixtures-refresh.php`
- `HH:05` hourly: `php cron/fixtures-post-deadline-refresh.php`
- `09:00` daily: `php cron/sync-all.php --phase=pre-deadline`
- `23:59` daily: `php cron/sync-all.php --phase=post-gameweek`
- `04:00` Monday: `php cron/sync-all.php --phase=slow`
- `12:00` Saturday: `php cron/sync-all.php --phase=samples`

### Important: Applying Cron Changes

`deploy/crontab` is copied into the image at build time (see `deploy/Dockerfile.php`), so schedule changes do not apply until the cron image is rebuilt.

```bash
docker compose build cron
docker compose up -d cron
```

## Key Business Logic

| Component | Location | Purpose |
|-----------|----------|---------|
| PredictionEngine | `api/src/Prediction/PredictionEngine.php` | Per-player xPts calculation |
| MinutesProbability | `api/src/Prediction/MinutesProbability.php` | Playing time model |
| PathSolver | `api/src/Services/PathSolver.php` | Multi-GW transfer optimizer (beam search) |
| applyAutoSubs | `frontend/src/hooks/useLive.ts` | Live auto-sub simulation |
| buildFormation | `frontend/src/hooks/usePlannerOptimize.ts` | XI selection + captain pick |

## Key Features

1. **Team Analyzer** - Analyze FPL manager squads
2. **League Analyzer** - Mini-league analysis with differentials and risk
3. **Live** - Live gameweek tracker with formation pitch view
4. **Planner** - Multi-week transfer optimization with chip suggestions

## API Endpoints

Key endpoints (registered via Maia controllers):
- `GET /players` - All players with team data
- `GET /predictions/{gw}` - Point predictions for gameweek
- `GET /live/{gw}/manager/{id}` - Live points for manager
- `GET /leagues/{id}/analysis` - League analysis with comparisons
- `GET /planner/optimize?manager={id}&ft={n}` - Transfer optimization

## Database Schema

Players table key columns:
- `id`, `web_name`, `team`, `element_type` (position 1-4)
- `now_cost` (price in 0.1m units), `total_points`, `form`
- `expected_goals`, `expected_assists`, `selected_by_percent`

## After Making Changes

Always ensure services are running after code changes:
```bash
docker-compose up -d                    # Restart API if PHP changed
cd frontend && npm run build && npm run dev  # Rebuild frontend
```

## Common Issues

- **FplClient methods**: Use fluent API - `$fplClient->entry($id)->getRaw()` not `$fplClient->getEntry($id)`
- **Null checks**: Always check for undefined array keys in PHP when accessing player/squad data
- **Type mismatches**: Frontend interfaces must match API response structures
- **Frontend tests require Node 22+**: Use `fnm use 22` if you hit `ERR_REQUIRE_ESM` from `@exodus/bytes`
- **Don't mix native DOM listeners with React events**: Use `data-*` attributes + `target.closest()` for click-outside patterns
- **Don't introduce state management libraries**: No Context, Redux, or Zustand — TanStack Query + useState only
