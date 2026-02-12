# Planner Chip Solver Plan

## Goal
Add chip-aware planning to the transfer solver so multi-week optimization can account for:
- `wildcard`
- `free_hit`
- `bench_boost`
- `triple_captain`

## Status
### Step 1: Fixed chip weeks in solver
Implemented.

What is done:
- `PathSolver` now accepts `chipPlan` in `solve(...)`.
- GW chip effects are applied in solver transitions:
  - `wildcard`: optimized squad selected and persisted to future GWs.
  - `free_hit`: optimized temporary squad for that GW only, then revert.
  - `bench_boost`: bench points included in GW score.
  - `triple_captain`: captain multiplier is x3 for that GW.
- `chip_played` is included in `transfers_by_gw[gw]`.
- `TransferOptimizerService` passes chip plan into `PathSolver`.
- Test coverage added for TC, BB, FH revert, and WC persistence.
- Full PHPUnit suite currently passing.

### Step 2: Chip timing suggestion pre-pass
Implemented.

What is done:
- Added `GET /planner/chips/suggest`.
- Supports:
  - `manager` (required)
  - optional `ft`
  - optional `chip_plan` (JSON), `chip_allow` (JSON array), `chip_forbid` (JSON object)
- Returns:
  - `current_gameweek`
  - `planning_horizon`
  - `requested_chip_plan`
  - ranked `suggestions` per chip (top candidates)
  - `recommended_plan`
- Uses deterministic heuristic scoring (captain/bench/fixture-upside window), not combinatorial full search.

### Step 3: Integrate chip strategy into optimize flow
Implemented (backend/API contract).

What is done:
- Extended `GET /planner/optimize` to accept:
  - `chip_mode=none|locked|auto`
  - `chip_plan` JSON (legacy `*_gw` params still supported)
  - `chip_allow`, `chip_forbid`
  - `chip_compare=1`
- Response now includes:
  - `chip_mode`
  - `requested_chip_plan`
  - `resolved_chip_plan`
  - `chip_suggestions_ranked`
  - `comparisons` (`no_chip_total_score`, `chip_plan_total_score`, `chip_delta`) when requested
- Existing `chip_plan` and `chip_suggestions` fields retained for backward compatibility.

## Next Steps
- Frontend planner UI controls for chip mode and chip locking/unlocking.
- Display `comparisons` and ranked chip suggestions in solver results.

## API Contract Draft
### `GET /planner/optimize` (additional query params)
- `chip_mode`: `none|locked|auto`
- `chip_plan`: JSON object
- `chip_allow`: JSON array of chip names
- `chip_forbid`: JSON object chip -> disallowed weeks
- `chip_compare`: `0|1`

### `GET /planner/chips/suggest`
Returns:
- `current_gameweek`
- `planning_horizon`
- `suggestions` (chip -> ranked candidates)
- `recommended_plan`

## Risks / Constraints
- Joint optimization of transfer paths + free-form chip timing has high state explosion.
- Keep auto-chip step as pre-pass to constrain complexity and preserve runtime.
- Preserve manual control via locked chip weeks.
