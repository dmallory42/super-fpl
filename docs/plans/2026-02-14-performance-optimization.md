# Performance Optimization Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Eliminate the performance regressions introduced by recent feature work — reduce query counts, add missing indexes, fix frontend overfetching, and consolidate heavy live-page computations.

**Architecture:** Ten independent tasks organized backend-first (database indexes, query batching, caching) then frontend (hook guards, query key fixes, computation consolidation). Each task is self-contained and can be committed independently.

**Tech Stack:** PHP 8.2 / SQLite / Redis (backend), React 18 / TanStack Query v5 / TypeScript (frontend)

---

## Task 1: Add Missing Composite Database Indexes

**Files:**
- Modify: `api/data/schema.sql:256-262`
- Create: `api/data/migrations/add-performance-indexes.sql`

**Context:** The schema has only single-column indexes on `gameweek` for `manager_picks`, `player_gameweek_history`, and no indexes at all on `player_predictions` or `prediction_snapshots` for the `(player_id, gameweek)` lookups used everywhere. Every query filtering by `(manager_id, gameweek)` or `(player_id, gameweek)` does a table scan after the first column filter.

**Step 1: Create migration file**

Create `api/data/migrations/add-performance-indexes.sql`:

```sql
-- Composite indexes for common query patterns
-- manager_picks: queried by (manager_id, gameweek) in 20+ endpoints
CREATE INDEX IF NOT EXISTS idx_manager_picks_mgr_gw ON manager_picks(manager_id, gameweek);

-- player_gameweek_history: queried by (player_id, gameweek) in season analysis, predictions
CREATE INDEX IF NOT EXISTS idx_player_history_pid_gw ON player_gameweek_history(player_id, gameweek);

-- player_predictions: queried by (player_id, gameweek) in prediction lookups
CREATE INDEX IF NOT EXISTS idx_player_predictions_pid_gw ON player_predictions(player_id, gameweek);

-- prediction_snapshots: queried by (player_id, gameweek) in season analysis
CREATE INDEX IF NOT EXISTS idx_prediction_snapshots_pid_gw ON prediction_snapshots(player_id, gameweek);

-- Odds tables: queried by (player_id, fixture_id) in prediction generation
CREATE INDEX IF NOT EXISTS idx_goalscorer_odds_pid_fid ON player_goalscorer_odds(player_id, fixture_id);
CREATE INDEX IF NOT EXISTS idx_assist_odds_pid_fid ON player_assist_odds(player_id, fixture_id);
```

**Step 2: Update schema.sql to include indexes**

Add to `api/data/schema.sql` after line 261 (after `idx_manager_picks_gw`):

```sql
CREATE INDEX idx_manager_picks_mgr_gw ON manager_picks(manager_id, gameweek);
CREATE INDEX idx_player_history_pid_gw ON player_gameweek_history(player_id, gameweek);
CREATE INDEX idx_player_predictions_pid_gw ON player_predictions(player_id, gameweek);
CREATE INDEX idx_prediction_snapshots_pid_gw ON prediction_snapshots(player_id, gameweek);
CREATE INDEX idx_goalscorer_odds_pid_fid ON player_goalscorer_odds(player_id, fixture_id);
CREATE INDEX idx_assist_odds_pid_fid ON player_assist_odds(player_id, fixture_id);
```

**Step 3: Apply migration to existing database**

Add index application to `Database::init()` or Docker entrypoint so existing databases get the indexes. The `IF NOT EXISTS` clause makes this safe to run repeatedly.

Run: `docker compose exec php php -r "require 'vendor/autoload.php'; \$db = new SuperFPL\Api\Database('/data/fpl.db'); \$db->getPdo()->exec(file_get_contents('/app/data/migrations/add-performance-indexes.sql'));"`

**Step 4: Commit**

```bash
git add api/data/schema.sql api/data/migrations/add-performance-indexes.sql
git commit -m "perf: add composite indexes for common query patterns"
```

---

## Task 2: Filter Player Fetch in LiveService to Squad IDs

**Files:**
- Modify: `api/src/Services/LiveService.php:151-171`
- Modify: `api/tests/Services/LiveServiceTest.php`

**Context:** `getManagerLivePointsEnhanced()` at line 167 runs `SELECT id, web_name, club_id as team, position as element_type FROM players` — fetching all ~650 players when only the 15 in the manager's squad are needed. The squad player IDs are already available from `$baseData['players']`.

**Step 1: Write a failing test**

Add to `api/tests/Services/LiveServiceTest.php` a test that verifies `getManagerLivePointsEnhanced` only queries the players in the squad, not the full table. Since this is a refactor for efficiency, the test should verify the output stays correct after the change — use an integration-style test with an in-memory DB.

**Step 2: Modify `getManagerLivePointsEnhanced` to filter by squad IDs**

Replace line 167 in `api/src/Services/LiveService.php`:

```php
// Before:
$players = $this->db->fetchAll("SELECT id, web_name, club_id as team, position as element_type FROM players");

// After:
$squadIds = array_map(fn($p) => (int) $p['player_id'], $baseData['players']);
if (empty($squadIds)) {
    $players = [];
} else {
    $placeholders = implode(',', array_fill(0, count($squadIds), '?'));
    $players = $this->db->fetchAll(
        "SELECT id, web_name, club_id as team, position as element_type FROM players WHERE id IN ($placeholders)",
        $squadIds
    );
}
```

**Step 3: Run tests**

Run: `cd api && ./vendor/bin/phpunit tests/Services/LiveServiceTest.php`

**Step 4: Commit**

```bash
git add api/src/Services/LiveService.php api/tests/Services/LiveServiceTest.php
git commit -m "perf: filter LiveService player fetch to squad IDs only"
```

---

## Task 3: Batch Gameweek DGW/BGW Queries

**Files:**
- Modify: `api/src/Services/GameweekService.php:131-167`
- Modify: `api/public/index.php:681-709`
- Modify: `api/tests/Services/GameweekServiceTest.php`

**Context:** `handleCurrentGameweek()` loops through 6 upcoming GWs calling `getDoubleGameweekTeams($gw)` and `getBlankGameweekTeams($gw)` individually. Each call runs its own fixture queries. That's 12+ queries that could be 1-2.

**Step 1: Write failing tests for batch methods**

Add to `api/tests/Services/GameweekServiceTest.php`:

```php
public function testGetMultipleDoubleGameweekTeams(): void
{
    // Insert DGW fixture data for GW 30 (team 1 has 2 home fixtures)
    // Assert batch method returns same result as calling individual method per GW
}

public function testGetMultipleBlankGameweekTeams(): void
{
    // Insert clubs with no fixtures in GW 31
    // Assert batch method returns same result as calling individual method per GW
}
```

**Step 2: Add batch methods to GameweekService**

Add to `api/src/Services/GameweekService.php`:

```php
/**
 * Get DGW teams for multiple gameweeks in a single query batch.
 * @param int[] $gameweeks
 * @return array<int, int[]> Map of gameweek => team IDs with 2+ fixtures
 */
public function getMultipleDoubleGameweekTeams(array $gameweeks): array
{
    $counts = $this->getFixtureCounts($gameweeks);
    $result = [];
    foreach ($counts as $gw => $teams) {
        $dgw = array_keys(array_filter($teams, fn($count) => $count >= 2));
        if (!empty($dgw)) {
            $result[$gw] = $dgw;
        }
    }
    return $result;
}

/**
 * Get BGW teams for multiple gameweeks in a single query batch.
 * @param int[] $gameweeks
 * @return array<int, int[]> Map of gameweek => team IDs with no fixtures
 */
public function getMultipleBlankGameweekTeams(array $gameweeks): array
{
    if (empty($gameweeks)) {
        return [];
    }

    $allTeams = $this->db->fetchAll("SELECT id FROM clubs");
    $allTeamIds = array_map(fn($t) => (int) $t['id'], $allTeams);

    $counts = $this->getFixtureCounts($gameweeks);
    $result = [];
    foreach ($gameweeks as $gw) {
        $teamsWithFixtures = array_keys($counts[$gw] ?? []);
        $blank = array_values(array_diff($allTeamIds, $teamsWithFixtures));
        if (!empty($blank)) {
            $result[$gw] = $blank;
        }
    }
    return $result;
}
```

**Step 3: Update `handleCurrentGameweek` in index.php**

Replace `api/public/index.php` lines 688-700:

```php
// Before: loop calling individual methods
// After: single batch calls
$dgwTeams = $service->getMultipleDoubleGameweekTeams($upcoming);
$bgwTeams = $service->getMultipleBlankGameweekTeams($upcoming);
```

**Step 4: Run tests**

Run: `cd api && ./vendor/bin/phpunit tests/Services/GameweekServiceTest.php`

**Step 5: Commit**

```bash
git add api/src/Services/GameweekService.php api/public/index.php api/tests/Services/GameweekServiceTest.php
git commit -m "perf: batch DGW/BGW queries in gameweek endpoint"
```

---

## Task 4: Batch Prediction Queries in ManagerSeasonAnalysisService

**Files:**
- Modify: `api/src/Services/ManagerSeasonAnalysisService.php:274-327`
- Modify: `api/tests/Services/ManagerSeasonAnalysisServiceTest.php`

**Context:** `getPredictedPoints()` and `getActualPlayerPoints()` each run individual `SELECT` queries per player per gameweek. With 38 GWs × 15 players, that's ~570 queries per manager for predictions alone, plus ~570 for actuals. The in-memory cache (`$expectedPointCache`, `$actualPointCache`) helps within a request but still fires one query per unique (player, gw) pair. The fix: preload all predictions and actuals for the season in bulk at the start of `analyze()`.

**Step 1: Write a test that verifies batch-loaded results match individual lookups**

Add to `api/tests/Services/ManagerSeasonAnalysisServiceTest.php`:

```php
public function testBulkPreloadMatchesIndividualQueries(): void
{
    // Seed prediction_snapshots and player_gameweek_history for 3 GWs × 3 players
    // Call analyze() and verify expected_points and actual_points match expected values
    // This ensures the bulk preload produces identical results to individual queries
}
```

**Step 2: Add bulk preload methods**

Add to `api/src/Services/ManagerSeasonAnalysisService.php`:

```php
/**
 * Preload all predicted points for a set of player IDs across all gameweeks.
 * Populates $this->expectedPointCache to avoid per-player queries.
 */
private function preloadPredictions(array $playerIds): void
{
    if (empty($playerIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($playerIds), '?'));

    // Load from snapshots first (preferred source)
    $snapshots = $this->db->fetchAll(
        "SELECT player_id, gameweek, predicted_points FROM prediction_snapshots WHERE player_id IN ($placeholders)",
        $playerIds
    );
    foreach ($snapshots as $row) {
        $this->expectedPointCache[(int) $row['gameweek']][(int) $row['player_id']] = (float) $row['predicted_points'];
    }

    // Load fallback predictions for any gaps
    $predictions = $this->db->fetchAll(
        "SELECT player_id, gameweek, predicted_points, predicted_if_fit, expected_mins, expected_mins_if_fit
         FROM player_predictions WHERE player_id IN ($placeholders)",
        $playerIds
    );
    foreach ($predictions as $row) {
        $gw = (int) $row['gameweek'];
        $pid = (int) $row['player_id'];
        if (isset($this->expectedPointCache[$gw][$pid])) {
            continue; // Snapshot takes priority
        }

        $value = (float) ($row['predicted_points'] ?? 0);
        $predictedIfFit = isset($row['predicted_if_fit']) ? (float) $row['predicted_if_fit'] : null;
        $expectedMins = isset($row['expected_mins']) ? (float) $row['expected_mins'] : null;
        $expectedMinsIfFit = isset($row['expected_mins_if_fit']) ? (float) $row['expected_mins_if_fit'] : null;

        if (
            $predictedIfFit !== null
            && (
                $value <= 0.05
                || (
                    $expectedMins !== null
                    && $expectedMinsIfFit !== null
                    && $expectedMins < 15.0
                    && $expectedMinsIfFit >= 45.0
                )
            )
        ) {
            $value = $predictedIfFit;
        }

        $this->expectedPointCache[$gw][$pid] = $value;
    }
}

/**
 * Preload all actual points for a set of player IDs across all gameweeks.
 * Populates $this->actualPointCache to avoid per-player queries.
 */
private function preloadActuals(array $playerIds): void
{
    if (empty($playerIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
    $rows = $this->db->fetchAll(
        "SELECT player_id, gameweek, total_points FROM player_gameweek_history WHERE player_id IN ($placeholders)",
        $playerIds
    );
    foreach ($rows as $row) {
        $this->actualPointCache[(int) $row['gameweek']][(int) $row['player_id']] = (float) $row['total_points'];
    }
}
```

**Step 3: Call preloaders at the start of `analyze()`**

In `analyze()`, after fetching the manager's history and transfers (line 37), collect all player IDs and preload:

```php
// Collect all player IDs from picks and transfers for bulk preload
$allPlayerIds = [];
foreach ($history['current'] as $gwEntry) {
    $gw = (int) ($gwEntry['event'] ?? 0);
    if ($gw <= 0) continue;
    $picks = $this->getManagerPicks($managerId, $gw);
    foreach ($picks as $pick) {
        $pid = (int) ($pick['element'] ?? 0);
        if ($pid > 0) $allPlayerIds[$pid] = true;
    }
}
foreach ($transfersByGw as $transfers) {
    foreach ($transfers as $t) {
        $in = (int) ($t['element_in'] ?? 0);
        $out = (int) ($t['element_out'] ?? 0);
        if ($in > 0) $allPlayerIds[$in] = true;
        if ($out > 0) $allPlayerIds[$out] = true;
    }
}
$this->preloadPredictions(array_keys($allPlayerIds));
$this->preloadActuals(array_keys($allPlayerIds));
```

The existing `getPredictedPoints()` and `getActualPlayerPoints()` methods already check the cache first, so they'll hit the preloaded data instead of querying. No changes needed to those methods.

**Step 4: Run tests**

Run: `cd api && ./vendor/bin/phpunit tests/Services/ManagerSeasonAnalysisServiceTest.php`

**Step 5: Commit**

```bash
git add api/src/Services/ManagerSeasonAnalysisService.php api/tests/Services/ManagerSeasonAnalysisServiceTest.php
git commit -m "perf: batch prediction/actual queries in season analysis"
```

---

## Task 5: Deduplicate Fixture Queries in LiveService

**Files:**
- Modify: `api/src/Services/LiveService.php:242-265`

**Context:** `applyProvisionalBonusToLiveData()` (line 262) queries `SELECT * FROM fixtures WHERE gameweek = ?`, and `getBonusPredictions()` (line 245) runs the exact same query. When `getLiveData()` calls `applyProvisionalBonusToLiveData`, and then the bonus endpoint calls `getBonusPredictions`, the same fixture data is fetched twice per gameweek cycle. Additionally, the cached path (line 36) also calls `applyProvisionalBonusToLiveData` which queries fixtures again.

**Step 1: Modify `applyProvisionalBonusToLiveData` to accept optional fixtures**

```php
private function applyProvisionalBonusToLiveData(array $liveData, int $gameweek, ?array $fixtures = null): array
{
    $elements = $liveData['elements'] ?? [];
    if ($fixtures === null) {
        $fixtures = $this->db->fetchAll(
            'SELECT * FROM fixtures WHERE gameweek = ?',
            [$gameweek]
        );
    }
    // ... rest unchanged
}
```

**Step 2: Modify `getBonusPredictions` similarly**

```php
public function getBonusPredictions(int $gameweek, ?array $fixtures = null): array
{
    if ($fixtures === null) {
        $fixtures = $this->db->fetchAll(
            'SELECT * FROM fixtures WHERE gameweek = ?',
            [$gameweek]
        );
    }
    return $this->calculateProvisionalBonusPredictions($gameweek, $fixtures);
}
```

**Step 3: In `getLiveData`, fetch fixtures once and pass through**

```php
public function getLiveData(int $gameweek): array
{
    $cached = $this->getFromCache($gameweek);
    if ($cached !== null) {
        $fixtures = $this->db->fetchAll('SELECT * FROM fixtures WHERE gameweek = ?', [$gameweek]);
        $cached = $this->applyProvisionalBonusToLiveData($cached, $gameweek, $fixtures);
        $this->saveToCache($gameweek, $cached);
        return $cached;
    }

    $liveData = $this->fplClient->live($gameweek)->get();
    $fixtures = $this->db->fetchAll('SELECT * FROM fixtures WHERE gameweek = ?', [$gameweek]);

    $enriched = $this->enrichLiveData($liveData);
    $enriched = $this->applyProvisionalBonusToLiveData($enriched, $gameweek, $fixtures);

    $this->saveToCache($gameweek, $enriched);
    return $enriched;
}
```

**Step 4: Run tests**

Run: `cd api && ./vendor/bin/phpunit tests/Services/LiveServiceTest.php`

**Step 5: Commit**

```bash
git add api/src/Services/LiveService.php
git commit -m "perf: deduplicate fixture queries in LiveService"
```

---

## Task 6: Batch Enrich Live Data (Fix N+1 Player Lookup)

**Files:**
- Modify: `api/src/Services/LiveService.php:480-505`

**Context:** `enrichLiveData()` runs a `SELECT ... FROM players WHERE id = ?` inside a loop for every live element (~650 players). That's 650 individual queries. Should be a single bulk fetch.

**Step 1: Replace the N+1 loop with a bulk fetch**

Replace `enrichLiveData()`:

```php
private function enrichLiveData(array $liveData): array
{
    $elements = $liveData['elements'] ?? [];
    if (empty($elements)) {
        return $liveData;
    }

    // Bulk fetch all player info
    $playerIds = array_map(fn($e) => (int) ($e['id'] ?? 0), $elements);
    $playerIds = array_filter($playerIds, fn($id) => $id > 0);

    if (empty($playerIds)) {
        $liveData['elements'] = $elements;
        return $liveData;
    }

    $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
    $players = $this->db->fetchAll(
        "SELECT id, web_name, club_id, position FROM players WHERE id IN ($placeholders)",
        array_values($playerIds)
    );

    $playerMap = [];
    foreach ($players as $p) {
        $playerMap[(int) $p['id']] = $p;
    }

    foreach ($elements as &$element) {
        $playerId = (int) ($element['id'] ?? 0);
        $player = $playerMap[$playerId] ?? null;
        if ($player) {
            $element['web_name'] = $player['web_name'];
            $element['team'] = $player['club_id'];
            $element['position'] = $player['position'];
        }
    }
    unset($element);

    $liveData['elements'] = $elements;
    return $liveData;
}
```

**Step 2: Run tests**

Run: `cd api && ./vendor/bin/phpunit tests/Services/LiveServiceTest.php`

**Step 3: Commit**

```bash
git add api/src/Services/LiveService.php
git commit -m "perf: batch player enrichment in LiveService (650 queries → 1)"
```

---

## Task 7: Cache Prediction Accuracy Endpoint

**Files:**
- Modify: `api/public/index.php:549-567`

**Context:** `handlePredictionAccuracy()` has no Redis caching, unlike other expensive endpoints. It joins predictions with actuals — historical data that changes only after a sync.

**Step 1: Wrap with `withResponseCache`**

Replace `handlePredictionAccuracy`:

```php
function handlePredictionAccuracy(Database $db, int $gameweek): void
{
    global $config;
    withResponseCache($config ?? [], 'prediction-accuracy', 600, function () use ($db, $gameweek): void {
        $service = new PredictionService($db);
        $accuracy = $service->getAccuracy($gameweek);

        if ($accuracy['summary']['count'] === 0) {
            http_response_code(404);
            echo json_encode([
                'error' => 'No accuracy data available for this gameweek',
                'gameweek' => $gameweek,
            ]);
            return;
        }

        echo json_encode([
            'gameweek' => $gameweek,
            'accuracy' => $accuracy,
        ]);
    });
}
```

Note: the function signature needs `$config` passed in. Check the route match line (98) — if it doesn't pass `$config`, update it:

```php
preg_match('#^/predictions/(\d+)/accuracy$#', $uri, $m) === 1 => handlePredictionAccuracy($db, (int) $m[1], $config),
```

And update the function signature to: `function handlePredictionAccuracy(Database $db, int $gameweek, array $config): void`

**Step 2: Commit**

```bash
git add api/public/index.php
git commit -m "perf: add Redis caching to prediction accuracy endpoint"
```

---

## Task 8: Add `enabled` Guard to `usePredictionsRange`

**Files:**
- Modify: `frontend/src/hooks/usePredictionsRange.ts:4-9`
- Modify: `frontend/src/pages/Planner.tsx` (the call site)

**Context:** `usePredictionsRange()` fires unconditionally on mount, even before a manager is selected. On the Planner page (line 364), it fetches multi-GW predictions for all players regardless of whether the data is needed yet.

**Step 1: The Planner page should only fetch predictions when a manager is loaded**

Check how `predictionsRange` is used in `Planner.tsx`. It's used to determine `selectedGameweek` (line 371-378) and passed to `useLiveSamples`. The fetch should be gated on having a valid `managerId`.

Modify `frontend/src/hooks/usePredictionsRange.ts`:

```typescript
export function usePredictionsRange(startGw?: number, endGw?: number, enabled = true) {
  return useQuery<PredictionsRangeResponse>({
    queryKey: ['predictions-range', startGw, endGw],
    queryFn: () => fetchPredictionsRange(startGw, endGw),
    staleTime: 1000 * 60 * 10,
    enabled,
  })
}
```

Then in `Planner.tsx` line 364, pass the guard:

```typescript
const { data: predictionsRange } = usePredictionsRange(undefined, undefined, !!managerId)
```

**Step 2: Run tests**

Run: `cd frontend && npm test -- src/hooks/usePredictionsRange`
(If no test file exists, verify the app still works by running `npm run build`)

**Step 3: Commit**

```bash
git add frontend/src/hooks/usePredictionsRange.ts frontend/src/pages/Planner.tsx
git commit -m "perf: gate usePredictionsRange fetch on manager selection"
```

---

## Task 9: Fix Planner Query Key Serialization

**Files:**
- Modify: `frontend/src/hooks/usePlannerOptimize.ts:28-43`

**Context:** Query keys use `JSON.stringify()` for 4 object parameters (chipPlan, xMinsOverrides, fixedTransfers, constraints). TanStack Query v5 already does deep structural comparison on query keys — passing objects directly is correct and avoids the problem of property-order-sensitive JSON string comparisons causing cache misses.

**Step 1: Remove JSON.stringify from query keys**

Replace lines 28-43:

```typescript
return useQuery<PlannerOptimizeResponse>({
  queryKey: [
    'planner-optimize',
    managerId,
    freeTransfers,
    chipPlan,
    xMinsOverrides,
    fixedTransfers,
    ftValue,
    depth,
    skipSolve,
    chipMode,
    objectiveMode,
    constraints,
    chipCompare,
  ],
```

**Step 2: Run tests and type check**

Run: `cd frontend && npm run build`

**Step 3: Commit**

```bash
git add frontend/src/hooks/usePlannerOptimize.ts
git commit -m "perf: remove JSON.stringify from planner query keys"
```

---

## Task 10: Consolidate Live Page Impact Computations

**Files:**
- Modify: `frontend/src/pages/Live.tsx:162-391`

**Context:** Three separate `useMemo` blocks iterate over the same data (processedSquad, liveData.elements, tierEO) to compute `playerImpacts` (lines 162-236), `fixtureImpacts` (lines 311-356), and `differentialData` (lines 359-391). The `differentialData` computation is nearly identical to the owned-player portion of `playerImpacts`. Each iterates 900+ live elements on every 30s poll.

**Step 1: Extract shared computation into a single useMemo**

Create a single unified memo that computes all impact data in one pass:

```typescript
const { playerImpacts, fixtureImpacts, differentialData } = useMemo(() => {
  const empty = { playerImpacts: [] as PlayerImpact[], fixtureImpacts: [] as FixtureImpact[], differentialData: [] as DifferentialPlayer[] }
  if (!processedSquad || !tierEO || !playersMap) return empty

  const ownedPlayerIds = new Set(processedSquad.players.map((p) => p.player_id))
  const startingXI = processedSquad.players.filter((p) => p.position <= 11)

  // --- Differential data (owned XI only, no liveData iteration needed) ---
  const diffPlayers: DifferentialPlayer[] = []
  const ownedImpacts: PlayerImpact[] = []

  for (const player of startingXI) {
    const info = playersMap.get(player.player_id)
    if (!info) continue

    const eo = tierEO[player.player_id] ?? 0
    const multiplier = player.multiplier || 1
    const impact = player.effective_points * (1 - eo / (100 * multiplier))

    diffPlayers.push({
      playerId: player.player_id,
      name: info.web_name,
      points: player.effective_points,
      eo,
      impact,
      multiplier,
    })

    ownedImpacts.push({
      playerId: player.player_id,
      name: info.web_name,
      points: player.effective_points,
      eo,
      relativeEO: multiplier * 100 - eo,
      impact,
      owned: true,
    })
  }

  // --- Not-owned impacts (single pass over liveData.elements) ---
  const notOwnedImpacts: PlayerImpact[] = []

  // Also accumulate per-fixture tier avg points in the same pass
  const fixtureTierPoints = new Map<number, number>()

  if (liveData?.elements) {
    for (const element of liveData.elements) {
      const info = playersMap.get(element.id)
      if (!info) continue

      const eo = tierEO[element.id] ?? 0
      const points = element.stats?.total_points ?? 0

      // Accumulate fixture tier points (for fixture impacts)
      if (gameweekData?.fixtures) {
        for (const f of gameweekData.fixtures) {
          if (info.team === f.home_club_id || info.team === f.away_club_id) {
            fixtureTierPoints.set(f.id, (fixtureTierPoints.get(f.id) ?? 0) + (points * eo) / 100)
          }
        }
      }

      // Not-owned player impact
      if (!ownedPlayerIds.has(element.id) && eo >= 15 && points > 2) {
        notOwnedImpacts.push({
          playerId: element.id,
          name: info.web_name,
          points,
          eo,
          relativeEO: -eo,
          impact: -((points * eo) / 100),
          owned: false,
        })
      }
    }
  }

  // --- Assemble playerImpacts ---
  const gainers = ownedImpacts.filter((p) => p.impact > 0.5).sort((a, b) => b.impact - a.impact).slice(0, 5)
  const allLosers = [...ownedImpacts.filter((p) => p.impact < -0.5), ...notOwnedImpacts]
    .sort((a, b) => a.impact - b.impact)
    .slice(0, 5)

  // --- Assemble fixtureImpacts ---
  const fixtureImpactList = (gameweekData?.fixtures ?? []).map((fixture) => {
    let userPoints = 0
    let hasUserPlayer = false
    for (const player of startingXI) {
      const info = playersMap.get(player.player_id)
      if (!info) continue
      if (info.team === fixture.home_club_id || info.team === fixture.away_club_id) {
        userPoints += player.effective_points
        hasUserPlayer = true
      }
    }

    const tierAvgPoints = fixtureTierPoints.get(fixture.id) ?? 0

    return {
      fixtureId: fixture.id,
      homeTeam: teamsMap.get(fixture.home_club_id) ?? '???',
      awayTeam: teamsMap.get(fixture.away_club_id) ?? '???',
      userPoints,
      tierAvgPoints,
      impact: userPoints - tierAvgPoints,
      isLive: toBoolFlag(fixture.started) && !toBoolFlag(fixture.finished),
      isFinished: toBoolFlag(fixture.finished),
      hasUserPlayer,
    }
  })

  return {
    playerImpacts: [...gainers, ...allLosers],
    fixtureImpacts: fixtureImpactList,
    differentialData: diffPlayers,
  }
}, [processedSquad, tierEO, playersMap, liveData, gameweekData, teamsMap])
```

**Step 2: Remove the three individual useMemo blocks**

Delete the original `playerImpacts` (lines 162-236), `fixtureImpacts` (lines 311-356), and `differentialData` (lines 359-391) memo blocks.

**Step 3: Verify types match what consuming components expect**

Check that `ComparisonBars`, `FixtureThreatIndex`, and `DifferentialAnalysis` components receive props of the same shape. Add the `DifferentialPlayer` and `FixtureImpact` type aliases if they don't exist.

**Step 4: Run tests and type check**

Run: `cd frontend && npm run build && npm test`

**Step 5: Commit**

```bash
git add frontend/src/pages/Live.tsx
git commit -m "perf: consolidate live page impact computations into single pass"
```

---

## Summary

| Task | Type | Impact | Est. Effort |
|------|------|--------|-------------|
| 1. Add composite DB indexes | Backend | High — speeds up every endpoint | 15 min |
| 2. Filter LiveService player fetch | Backend | High — 650 rows → 15 | 15 min |
| 3. Batch DGW/BGW queries | Backend | High — 12 queries → 2 | 30 min |
| 4. Batch season analysis predictions | Backend | Very high — 570+ queries → 2 | 45 min |
| 5. Deduplicate fixture queries | Backend | Medium — eliminates redundant IO | 15 min |
| 6. Batch live data enrichment | Backend | High — 650 queries → 1 | 15 min |
| 7. Cache prediction accuracy | Backend | Medium — eliminates repeated JOINs | 10 min |
| 8. Guard usePredictionsRange | Frontend | Medium — eliminates wasted fetch | 10 min |
| 9. Fix planner query keys | Frontend | Medium — fixes cache misses | 5 min |
| 10. Consolidate live computations | Frontend | High — 3 passes → 1, removes duplication | 30 min |

**Dependencies:** Tasks 1-10 are all independent and can be executed in any order. Task 1 (indexes) benefits all subsequent backend tasks.
