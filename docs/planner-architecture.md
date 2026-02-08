# Planner Architecture

## Overview

The Planner page lets users view their FPL squad, make manual transfers, run the optimizer to find multi-gameweek transfer paths, and save/compare plans. It uses an **on-demand solve** pattern — the squad loads immediately, and the solver only runs when the user explicitly clicks "Find Plans".

## Data Flow

```
┌──────────────┐   skipSolve=1    ┌───────────────────┐
│  Squad Query  │ ───────────────> │  Backend returns   │
│  (always on)  │                  │  squad, formations │
└──────────────┘                  │  predictions, NO   │
                                  │  paths             │
┌──────────────┐   skipSolve=0    ├───────────────────┤
│  Solve Query  │ ───────────────> │  Backend returns   │
│  (on demand)  │                  │  full response     │
└──────────────┘                  │  WITH paths        │
                                  └───────────────────┘
```

### Two-Query Pattern

```ts
// Squad data — always active
usePlannerOptimize(managerId, ..., true /* skipSolve */)

// Solver — only when solveRequested is true
usePlannerOptimize(
  solveRequested ? managerId : null, // null disables query
  ..., solveTransfers, ..., false /* run solver */
)
```

The squad query uses `userTransfers` (current state). The solve query uses `solveTransfers` (snapshot taken when "Find Plans" was clicked). This lets us detect staleness.

## State Model

### Core State

| State | Type | Purpose |
|-------|------|---------|
| `userTransfers` | `FixedTransfer[]` | All transfers the user has made (manually or via plan selection) |
| `solveRequested` | `boolean` | Whether the solver should run |
| `solveTransfers` | `FixedTransfer[]` | Snapshot of userTransfers when "Find Plans" was clicked |
| `selectedPathIndex` | `number \| null` | Which solver plan is currently selected |
| `savedPlans` | `SavedPlan[]` | Plans saved to localStorage |

### Stale Detection

```ts
const isStale = solveRequested
  && JSON.stringify(userTransfers) !== JSON.stringify(solveTransfers)
```

When stale, the "Find Plans" button shows "Re-solve" with a yellow ring.

### Plan Selection Auto-Applies Transfers

When a user selects a plan, ALL the plan's moves across ALL gameweeks are converted into `userTransfers`:

```ts
const handleSelectPlan = (idx) => {
  const path = paths[idx]
  const planTransfers = []
  for (const [gwStr, gwData] of Object.entries(path.transfers_by_gw)) {
    for (const move of gwData.moves) {
      planTransfers.push({ gameweek: Number(gwStr), out: move.out_id, in: move.in_id })
    }
  }
  setUserTransfers(planTransfers)
  setSelectedPathIndex(idx)
}
```

### GW-Aware Squad

`effectiveSquad` and `effectiveBank` only apply transfers up to and including `selectedGameweek`. Switching GW tabs shows the squad as it would be at that point in the plan.

```ts
for (const gw of gameweeks) {
  if (gw > selectedGameweek) break
  // apply transfers for this GW
}
```

`squadPredictions` similarly rebuilds the squad per-GW for accurate projected point totals.

## Backend: `skip_solve` Parameter

### `api/public/index.php`
Parses `skip_solve=1` from query params.

### `api/src/Services/TransferOptimizerService.php`
`getOptimalPlan(..., bool $skipSolve = false)` — when true, skips PathSolver and returns `'paths' => []`. Everything else (squad data, formations, predictions, recommendations, chip suggestions) is still computed.

## UI Layout

### Controls Row (top)
- Manager ID input + Load button
- Free Transfers dropdown
- **Find Plans** button (green, shows spinner/stale state) + Reset button
- Solver settings (FT Value slider, Depth toggle)

### Main Content (left 2/3)
- **Stat panels**: Squad Value, Bank (GW-aware), Transfers (FT/hits), Projected points
- **Recommended Plans**: Only shown after solving. Clicking a plan auto-applies its transfers
- **Gameweek Selector**: GW tabs with point projections, highlights for transfers/hits
- **Squad Formation**: Pitch view with transfer-out click handling
- **Captain Decision**: Shown when multiple candidates within 0.5 pts

### Sidebar (right 1/3)
- **Your Transfers**: User's transfers grouped by GW, each with remove button. "Save Plan" button when a solver plan is selected
- **Replacement Picker**: Shown when a player is clicked for transfer out
- **Saved Plans**: Plans from localStorage with Load/Delete buttons

### Player Explorer (bottom, collapsible)
- Full player table with predictions, independent of planner state

## Saved Plans

```ts
interface SavedPlan {
  id: string          // `plan-${Date.now()}`
  name: string        // "Plan 1", "Plan 2", etc.
  transfers: FixedTransfer[]
  score: number       // total_score from solver
  scoreVsHold: number // score_vs_hold from solver
}
```

Persisted in `localStorage` under key `fpl_saved_plans`. Loading a saved plan sets `userTransfers` and clears the solve state.

## Files

| File | Role |
|------|------|
| `api/public/index.php` | Route handler, parses `skip_solve` |
| `api/src/Services/TransferOptimizerService.php` | `$skipSolve` param, skips PathSolver |
| `frontend/src/api/client.ts` | `fetchPlannerOptimize` with `skipSolve` param |
| `frontend/src/hooks/usePlannerOptimize.ts` | React Query hook with `skipSolve` in query key |
| `frontend/src/pages/Planner.tsx` | All UI and state logic |
