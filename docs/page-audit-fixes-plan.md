# Page Audit Fix Plan

## Purpose
This document captures the next round of fixes for the core product pages (`Live`, `League`, `Season`, `Planner`) based on a critical functionality audit. Each issue includes acceptance criteria and explicit assertions for verification.

## Prioritization
- `P1` = foundational data/model fixes (unblockers)
- `P2` = high-impact user-facing improvements
- `P3` = strategic depth and decision-quality analytics
- `P4` = advanced planner capability and explainability

---

## P1 Foundation (API + Shared Data Contracts)

### P1-1: Manager Season Analysis Endpoint
- Issue: Season page cannot answer luck vs skill, expected vs actual, or transfer quality rigorously with current history payload.
- Scope: Add `GET /managers/:id/season-analysis`.
- Deliverables:
  - Per-GW: `actual_points`, `expected_points`, `luck_delta`.
  - Transfer analytics: `foresight_gain`, `hindsight_gain`, `net_gain`.
  - Captain/chip impact metrics.

Acceptance Criteria:
- Endpoint returns valid payload for active manager IDs with partial and full seasons.
- Endpoint supports completed and in-progress GW states without schema changes.
- Response fields are stable and documented in frontend types.

Assertions:
- Assert `sum(gw.luck_delta)` equals `(sum(actual_points) - sum(expected_points))` within rounding tolerance.
- Assert transfer rows exist for GWs where `event_transfers > 0`.
- Assert no 500s when manager has no chips or no hits.

---

### P1-2: League Season Analysis Endpoint
- Issue: League page is mostly GW snapshot and cannot show season narrative or decision quality over time.
- Scope: Add `GET /leagues/:id/season-analysis`.
- Deliverables:
  - Per-manager season trajectory.
  - League median/mean benchmarks.
  - Decision-quality aggregates (captain gains, hit ROI, chip value).

Acceptance Criteria:
- Endpoint returns top-N league managers with consistent season arrays.
- Supports optional `gw_from` / `gw_to` query params.
- Missing manager weeks are represented deterministically (no shape drift).

Assertions:
- Assert manager list ordering is stable (rank or configured ordering).
- Assert benchmark arrays align to same GW axis as managers.
- Assert league with <2 managers returns a clear 4xx error message.

---

### P1-3: Shared DGW-Safe Fixture Mapping Contract
- Issue: Live page components can mis-assign team events under DGW/multi-fixture edge conditions.
- Scope: Expose fixture-safe mapping support in API and centralize frontend utility.
- Deliverables:
  - Shared helper for team+fixture assignment.
  - Canonical handling for `boolean` vs `0/1` status fields.

Acceptance Criteria:
- All live components consume one fixture mapping utility.
- DGW scenarios (`live + upcoming`, `two active fixtures`) produce deterministic behavior.

Assertions:
- Assert no upcoming fixture renders events from a different fixture ID.
- Assert no numeric text-node artifacts from `0 &&` JSX patterns.
- Assert fixture grouping is identical across all live widgets.

---

### P1-4: Frontend Type + Hook Expansion
- Issue: UI cannot consume new analysis payloads until typed contracts/hooks exist.
- Scope: Extend `frontend/src/api/client.ts` and add hooks for new endpoints.

Acceptance Criteria:
- New interfaces compile and are consumed by page-level hooks.
- Loading/error states are surfaced uniformly.

Assertions:
- Assert `npm test` and TS checks pass with no `any` escapes for new data.
- Assert query keys include all user-controlled parameters.

---

## P2 High-Impact UX + Reliability

### P2-1: Live Data Confidence Strip
- Issue: User cannot easily tell if comparisons are based on real sample data or estimates.
- Scope: Add confidence/provenance strip on `Live`.

Acceptance Criteria:
- Shows sample source (`real` or `estimated`), sample size, and update timestamp.
- Updates correctly during polling.

Assertions:
- Assert source badge changes as underlying API source changes.
- Assert stale state is visible if data age exceeds threshold.

---

### P2-2: Live Rank Swing Decomposition
- Issue: “How much risk/reward am I taking?” is distributed across modules and hard to parse quickly.
- Scope: Add decomposition card (captain, differential EO, fixture swing, fades).

Acceptance Criteria:
- Shows additive components and net swing.
- Tier toggle re-computes decomposition.

Assertions:
- Assert decomposition sum equals net displayed swing within tolerance.
- Assert positive/negative signs are directionally correct.

---

### P2-3: Cross-Widget DGW Consistency Refactor
- Issue: Fixes made in one live module can drift from others.
- Scope: Replace duplicate fixture-status logic with shared utility across live widgets.

Acceptance Criteria:
- `FixtureScores`, pitch/status cards, and threat modules use the same resolver.

Assertions:
- Assert no duplicated ad-hoc fixture matching remains in live components.
- Assert DGW integration tests pass for all affected widgets.

---

### P2-4: Live Regression Test Suite Expansion
- Issue: DGW/fixture edge regressions are easy to reintroduce.
- Scope: Add tests for upcoming/live/finished edge combinations and status typing.

Acceptance Criteria:
- New test coverage includes `started/finished` booleans and numeric variants.
- Includes event assignment and rendering assertions.

Assertions:
- Assert failing tests reproduce known historical bugs before fix.
- Assert all added tests pass post-fix.

---

## P3 Decision Quality + Season Narrative

### P3-1: League Page Information Architecture Refresh
- Issue: Current league page is a dense snapshot; season and decisions are not separate user workflows.
- Scope: Split into tabs: `This GW`, `Season`, `Decisions`.

Acceptance Criteria:
- State (league ID, GW selection) persists across tabs.
- Existing GW functionality remains intact.

Assertions:
- Assert tab switch does not trigger unnecessary full refetches when data already cached.
- Assert deep links preserve selected tab.

---

### P3-2: League Decision Delta Module
- Issue: User cannot evaluate "how good were my decisions vs friends".
- Scope: Add comparative decision module (captain, hits, transfer ROI, chip timing).

Acceptance Criteria:
- Shows manager deltas vs league median.
- Includes sortable metrics and concise definitions.

Assertions:
- Assert rankings by metric are deterministic.
- Assert each displayed metric maps to a backend field (no inferred placeholders).

---

### P3-3: Season Expected vs Actual + Luck Trend
- Issue: Current season review is descriptive, not diagnostic.
- Scope: Add expected vs actual chart and cumulative luck panel.

Acceptance Criteria:
- User can view per-GW and cumulative deltas.
- Supports benchmark toggle (`overall`, `top_10k`, optional league median).

Assertions:
- Assert cumulative curve equals running sum of per-GW deltas.
- Assert benchmark switch updates values without schema mismatch.

---

### P3-4: Transfer Quality Scorecard (Foresight + Hindsight)
- Issue: Transfer performance is not measured against expected and realized outcomes.
- Scope: Add transfer decision table with dual-evaluation columns.

Acceptance Criteria:
- For each transfer: expected gain at decision time, realized gain after outcomes, net ROI.
- Aggregate summary across season.

Assertions:
- Assert totals equal sum of row-level metrics.
- Assert no rows for weeks without transfers.

---

## P4 Planner Strategy Depth

### P4-1: Solver Objective Modes
- Issue: Planner optimizes one objective and does not expose risk profile control.
- Scope: Add objective modes: `expected`, `floor`, `ceiling`.

Acceptance Criteria:
- Solver endpoint and UI support objective selection.
- Output paths reflect chosen objective.

Assertions:
- Assert objective parameter is present in request and cached query key.
- Assert path ranking changes for at least one seeded scenario across modes.

---

### P4-2: Planner Constraints Layer
- Issue: Users cannot encode practical strategy constraints.
- Scope: Add lock/avoid players, max hits, chip windows.

Acceptance Criteria:
- Constraints are editable, validated, and applied by solver.
- Constraint state persists (URL/local state).

Assertions:
- Assert infeasible constraints return a clear user-facing explanation.
- Assert solver never returns a plan violating active constraints.

---

### P4-3: Per-GW Rationale Panel
- Issue: Plans are numerically ranked but not explainable enough.
- Scope: Add rationale for each GW action (`bank`, transfer(s), chip).

Acceptance Criteria:
- Rationale includes expected gain, cost/risk tradeoffs, and selected objective context.
- Works for both manual and auto-generated actions.

Assertions:
- Assert every displayed action has a rationale entry.
- Assert rationale values match underlying plan fields.

---

### P4-4: A/B Plan Comparison
- Issue: No direct side-by-side strategy comparison.
- Scope: Compare two plans by GW totals, transfers, chips, and cumulative delta.

Acceptance Criteria:
- User can pin/select two plans and see deltas.
- Comparison updates when objective/constraints change.

Assertions:
- Assert cumulative delta equals sum of GW deltas.
- Assert chip and transfer differences are explicitly listed (not implied).

---

## Suggested Execution Order
1. `P1-1`, `P1-3`, `P1-4`
2. `P2-1`, `P2-2`, `P2-3`, `P2-4`
3. `P1-2`, `P3-1`, `P3-2`
4. `P3-3`, `P3-4`
5. `P4-1`, `P4-2`, `P4-3`, `P4-4`

## Definition of Done (Global)
- Feature-level tests added or updated.
- No regressions on existing page flows.
- Analytics definitions documented in-code and in API type comments.
- All user-visible metrics have deterministic source fields and consistent units.
