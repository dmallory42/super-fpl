# Live Page Redesign

## Overview

Transform the Live page from a simple grid display into a broadcast-style live tracker with formation view, player status indicators, and comparisons against sample tiers (top 10k, 100k, 1M, overall).

## Key Features

1. **Auto-GW detection** - Automatically show current/relevant gameweek
2. **Formation pitch view** - Players displayed in actual formation (4-4-2, 3-5-2, etc.)
3. **Player status indicators** - Visual distinction for yet-to-play, playing now, finished
4. **Sample comparisons** - Compare points against top 10k, 100k, 1M, overall averages
5. **Live rank with movement** - Estimated rank + direction arrow
6. **Effective ownership** - Show EO per player from sampled tiers

## Page Layout

```
┌─────────────────────────────────────────────────────────┐
│ [LIVE INDICATOR] GW24 • 3/10 matches complete           │
│ Manager ID input (collapsed after first load)           │
├─────────────────────────────────────────────────────────┤
│ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐        │
│ │ 67 pts  │ │ ↑ 89K   │ │ vs 10K  │ │ vs Avg  │        │
│ │ Live    │ │ Rank    │ │ -8 pts  │ │ +12 pts │        │
│ └─────────┘ └─────────┘ └─────────┘ └─────────┘        │
├─────────────────────────────────────────────────────────┤
│                                                         │
│              [FORMATION PITCH - LARGE]                  │
│                     (GK row)                            │
│                   (DEF row)                             │
│                   (MID row)                             │
│                   (FWD row)                             │
│                    ─────────                            │
│                     Bench                               │
│                                                         │
├─────────────────────────────────────────────────────────┤
│ [Comparison Bars]          │  [Bonus Predictions]       │
│ Top 10K: ████████░░ 72pts  │  Salah +3                  │
│ Top 100K: ██████░░░ 58pts  │  Haaland +2                │
│ You: ███████░░░ 67pts      │  Palmer +1                 │
│ Overall: ████░░░░░ 42pts   │                            │
└─────────────────────────────────────────────────────────┘
```

## Player Card Design

**Always show points** (0 if not yet started). Status conveyed through visual treatment:

| Status | Jersey Style | Border | Animation |
|--------|-------------|--------|-----------|
| Yet to play | Dimmed, outline only | Gray dashed | None |
| Playing now | Solid green | Green ring | Pulsing glow |
| Finished | Solid green | None | None |

Match minute shown on hover/tap as tooltip (not cluttering the card).

## Auto-GW Detection Logic

- Fetch fixtures for current + next GW
- GW is "active" if: `now >= first_kickoff - 90min` AND `now <= last_kickoff + 12hrs`
- If no active GW, show the most recently completed one
- Remove manual GW selector (or hide in advanced options)

## Sample Data Architecture

### Caching Strategy

Picks are locked at deadline, so ownership doesn't change during the GW:

1. **At GW deadline + 75 minutes**: Sample 2,000 managers per tier, store their picks
2. **Cache for entire GW**: EO calculated once from this snapshot
3. **During live GW**: Apply live points to cached picks to compute real-time averages

### Effective Ownership Calculation

EO accounts for captaincy multipliers:
- Normal ownership: ×1
- Captain: ×2
- Triple Captain: ×3

Example: If 50% own Haaland and 40% have him captained:
- EO = 50% + 40% (extra for captains) = 90%

### Data Flow

**Cached once per GW (backend job):**
- 2,000 manager picks per tier (top 10k, 100k, 1M, overall)
- Effective ownership calculated from this sample
- Stored in DB

**Fetched live (existing + new endpoints):**
- `/live/{gw}` - Live player points
- `/live/{gw}/manager/{id}` - User's picks + live points
- `/live/{gw}/samples` (new) - Cached EO + calculated avg points

**Frontend computes:**
- Points differential (you vs each tier)
- Estimated rank movement
- Per-player EO impact

## Implementation Plan

### Backend Changes

#### 1. New DB table: `sample_picks`

```sql
CREATE TABLE sample_picks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    gameweek INTEGER NOT NULL,
    tier TEXT NOT NULL,  -- 'top_10k', 'top_100k', 'top_1m', 'overall'
    manager_id INTEGER NOT NULL,
    player_id INTEGER NOT NULL,
    multiplier INTEGER NOT NULL DEFAULT 1,  -- 1=normal, 2=captain, 3=triple_captain
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(gameweek, tier, manager_id, player_id)
);

CREATE INDEX idx_sample_picks_gw_tier ON sample_picks(gameweek, tier);
```

#### 2. New service: `SampleService.php`

- `sampleManagersForTier($gameweek, $tier, $count)` - Fetch random managers by rank range
- `calculateEffectiveOwnership($gameweek, $tier)` - Compute EO from stored picks
- `calculateTierAveragePoints($gameweek, $tier, $liveData)` - Apply live points to cached picks
- `getSampleData($gameweek)` - Return all tier data for frontend

#### 3. New sync job: `SampleSync.php`

- Triggers at GW deadline + 75 minutes
- Samples 2,000 managers per tier based on rank ranges:
  - top_10k: ranks 1-10,000
  - top_100k: ranks 1-100,000
  - top_1m: ranks 1-1,000,000
  - overall: random sample across all ranks
- Stores picks with multipliers

#### 4. New endpoint: `GET /live/{gw}/samples`

Response:
```json
{
  "gameweek": 24,
  "samples": {
    "top_10k": {
      "avg_points": 72,
      "sample_size": 2000,
      "effective_ownership": {
        "317": 185.5,
        "328": 78.2
      }
    },
    "top_100k": { ... },
    "top_1m": { ... },
    "overall": { ... }
  },
  "updated_at": "2024-01-15T16:45:00Z"
}
```

#### 5. Update `GET /live/{gw}/manager/{id}`

Add manager's starting rank for movement calculation.

### Frontend Changes

#### 1. New hook: `useCurrentGameweek.ts`

```typescript
export function useCurrentGameweek() {
  // Fetch fixtures, determine active GW based on:
  // - now >= first_kickoff - 90min
  // - now <= last_kickoff + 12hrs
  // Returns { gameweek, isLive, matchesPlayed, totalMatches }
}
```

#### 2. New hook: `useLiveSamples.ts`

```typescript
export function useLiveSamples(gameweek: number) {
  // Fetch /live/{gw}/samples
  // Returns tier averages, EO data
}
```

#### 3. New component: `LiveFormationPitch.tsx`

- Formation layout with GK/DEF/MID/FWD rows
- Uses fixture data to determine player status
- Player cards with visual status indicators
- Tooltip showing match minute for in-progress players
- Bench displayed in dugout-style bar

#### 4. New component: `PlayerStatusCard.tsx`

- Points always displayed (0 if not started)
- Visual states: dimmed (upcoming), glowing (playing), solid (finished)
- Captain/VC badges
- Hover tooltip for match details

#### 5. New component: `ComparisonBars.tsx`

- Horizontal bars for each tier
- User's points highlighted with marker
- Color coding: green if above avg, red if below

#### 6. Refactored `Live.tsx`

- Remove manual GW selector
- Auto-detect current GW
- Compact stats header
- Large pitch view as main focus
- Comparison + bonus sections below

### File Manifest

```
Backend:
- api/src/Services/SampleService.php (new)
- api/src/Sync/SampleSync.php (new)
- api/data/schema.sql (add sample_picks table)
- api/public/index.php (add /live/{gw}/samples endpoint)

Frontend:
- src/hooks/useCurrentGameweek.ts (new)
- src/hooks/useLiveSamples.ts (new)
- src/components/live/LiveFormationPitch.tsx (new)
- src/components/live/PlayerStatusCard.tsx (new)
- src/components/live/ComparisonBars.tsx (new)
- src/pages/Live.tsx (refactor)
- e2e/live.spec.ts (new tests)
```

## Testing Strategy

### E2E Tests (Playwright)

1. Auto-GW detection shows correct gameweek
2. Formation pitch displays players in correct positions
3. Player status indicators match fixture state
4. Comparison bars render with sample data
5. Rank movement displays correctly
6. Tooltip shows match minute on hover

### Unit Tests

1. `useCurrentGameweek` - GW detection logic
2. `SampleService` - EO calculation accuracy
3. Player status determination from fixture data
