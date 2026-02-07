# PathSolver — Multi-Gameweek Transfer Path Solver

## What It Does

Given a manager's current squad, bank, free transfers, and 6-GW prediction horizon, the solver finds the **top 3 optimal transfer strategies** across all gameweeks simultaneously. Unlike the greedy single-step optimizer, it considers how this week's moves affect next week's options.

## Algorithm: Beam Search

Beam search is a bounded breadth-first search. At each gameweek, it expands the B best states into children (bank, single transfer, multi-transfer), deduplicates, scores, and keeps the top B for the next gameweek.

```
beam = [initial_state]

for each GW in horizon:
  children = []
  for each state in beam:
    children += generate_children(state, GW)
  children = deduplicate(children)      # by sorted squad IDs + FT count
  children = sort_by_score(children)
  beam = top_B(children)

return select_diverse_top_3(beam)
```

### Why Beam Search

- Pure PHP, no external solvers or extensions needed
- Predictable O(B x C x G) performance
- Configurable depth for accuracy/speed tradeoff
- Simple to test and reason about

## Depth Modes

| Mode     | Beam Width | Candidates/Pos | Max Transfers/GW | Est. Time |
|----------|-----------|----------------|-------------------|-----------|
| Quick    | 15        | 8              | 2                 | ~0.5s     |
| Standard | 30        | 10             | 3                 | ~1.5s     |
| Deep     | 60        | 15             | 4                 | ~5s       |

## State Representation

Each beam state contains:

- `squad_ids` — 15 player IDs
- `bank` — balance in millions
- `ft` — free transfers available for current GW
- `score` — cumulative internal score (includes FT value adjustments)
- `display_score` — cumulative actual predicted points (no FT bonus, used for output)
- `transfers_by_gw` — record of actions taken per GW
- `total_hits` — cumulative hit count

## Child Generation

For each state at each GW, three types of children are generated:

### 1. Bank (0 transfers)
- Next GW gets `min(ft + 1, 5)` free transfers
- Internal score gets `+ftValue` bonus (rewards flexibility)

### 2. Single Transfer
- For each of the 15 weakest squad players x top N candidates at that position
- Applies FPL constraints: budget, team limit (max 3), position match
- Consumes 1 FT (or incurs a hit if FTs exhausted)

### 3. Multi-Transfer (2+ moves in one GW)
- Tries pairs from top 5 weakest x top 5 candidates per position
- Only allows hits when projected gain exceeds `HIT_THRESHOLD` (6 pts) over the horizon
- Capped at `maxTransfersPerGw` (depth-dependent)

## FT Rollover Model

```
Made N transfers with F free transfers:
  if N <= F: next_ft = min(F - N + 1, 5), hit_cost = 0
  if N > F:  next_ft = 1, hit_cost = (N - F) * 4
```

## FT Value

Saving a free transfer has value because it gives future flexibility:

- **Banking**: internal score gets `+ftValue` (default 1.5 pts)
- **Spending a FT**: internal score gets `-ftValue` (opportunity cost)
- **Hits**: cost -4 actual points directly, no FT consumed

The `ftValue` parameter is user-adjustable (0-5). Higher = more conservative (favors banking).

This only affects internal scoring for beam ranking. The `display_score` (and final output `total_score`) always reflects actual predicted points with hit deductions.

## Squad Evaluation

Each state is scored by:

1. Selecting the optimal starting 11 (1 GK, 3-5 DEF, 2-5 MID, 1-3 FWD)
2. Summing their predicted points for the GW
3. Adding captain bonus (highest predicted player's points count twice)

Uses the shared static `selectOptimalStarting11()` method (same logic as `TransferOptimizerService`).

## Candidate Pools

Before search begins, candidate pools are built per position:

1. **Overall pool**: Top N non-squad players by total predicted points across all remaining GWs
2. **Per-GW overlay**: Top 3 players per position for each specific GW (catches DGW spikes)
3. **Filters**: Skip players with `chance_of_playing = 0` or `total_predicted < 5.0`

## Fixed Transfers

User-specified transfers that must appear in every path:

1. Applied first at the relevant GW before generating optional children
2. States where the fixed transfer is invalid (budget, team limit) are pruned
3. Fixed transfers consume FTs normally (or incur hits)

## Hit Threshold

Hits cost -4 points each. The solver only explores taking hits when the projected net gain from the transfer (over the remaining horizon) exceeds `HIT_THRESHOLD` (6 pts). This means a hit transfer needs to gain at least 10 points total (6 threshold + 4 hit cost) to be considered.

## Path Diversity

The top 3 returned paths must be meaningfully different. Two paths are "similar" if their combined set of (out, in) player pairs differs by fewer than 2 moves. The selection walks the sorted beam and skips states similar to already-selected paths.

## Deduplication

States are deduplicated by hashing `sorted(squad_ids) + ":" + ft_count`. When duplicates exist, only the highest-scoring state is kept.

## API Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `ft_value` | float | 1.5 | Value of saving a free transfer (0-5) |
| `depth` | string | standard | Search depth: quick, standard, deep |
| `fixed_transfers` | JSON array | [] | `[{gameweek, out, in}]` forced moves |

## Output Structure

Each path contains:

```
{
  id: 1,
  total_score: 285.3,      // actual predicted points over horizon
  score_vs_hold: 12.7,     // improvement over making no transfers
  total_hits: 0,           // number of -4pt hits taken
  transfers_by_gw: {
    30: {
      action: "bank" | "transfer",
      ft_available: 1,
      ft_after: 2,
      moves: [{out_id, out_name, in_id, in_name, gain, is_free}],
      hit_cost: 0,
      gw_score: 47.2,
      squad_ids: [...]
    },
    ...
  }
}
```
