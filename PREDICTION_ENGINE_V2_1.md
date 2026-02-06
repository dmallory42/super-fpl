# Prediction Engine v2.1 — Improvements

## Context

Backtesting against league 28637 (21 skilled managers, top OR ~3K) revealed several gaps in the prediction model:

1. **No historical predictions** — past GW predictions returned 400, making proper calibration impossible
2. **Unavailable players pollute analysis** — injured/suspended players get 0.0 predicted with no way to distinguish from genuinely low-rated players
3. **Apparent under-prediction in low buckets** — comparison against season per-90 averages suggested conservative estimates for rotation/backup players
4. **Captain selection is naive** — `max(predicted_points)` with no shortlist when candidates are close

---

## 1. Historical Prediction Snapshots + Accuracy Endpoint

### Problem
Past GW predictions returned HTTP 400. Without stored historical predictions, we can never do real pred-vs-actual calibration.

### Solution

**New table** `prediction_snapshots` (added to `Database::migrate()`):
- `player_id`, `gameweek`, `predicted_points`, `confidence`, `breakdown` (JSON), `model_version`, `snapped_at`
- Primary key: `(player_id, gameweek)`

**New PredictionService methods:**
- `snapshotPredictions(int $gw): int` — copies from `player_predictions` using `INSERT OR IGNORE` (idempotent)
- `getSnapshotPredictions(int $gw): array` — retrieves snapshot data joined with player info
- `getAccuracy(int $gw): array` — joins snapshots against `player_gameweek_history`, computes MAE, bias, per-player deltas, and bucket breakdowns (0-2, 2-5, 5-8, 8+)

**Route changes** (`api/public/index.php`):
- `GET /predictions/{gw}` for past GWs now serves from snapshots (404 if none exist, with `"source": "snapshot"`)
- **Lazy snapshotting**: when serving current GW, automatically snapshots previous GW if not already done
- New route: `GET /predictions/{gw}/accuracy` — returns pred-vs-actual stats

**Tests** (6 in `PredictionSnapshotTest.php`):
- Snapshot copies predictions correctly
- Snapshot is idempotent (re-running doesn't duplicate)
- Retrieval returns data sorted by predicted_points DESC
- Empty GW returns empty array
- Accuracy computes correct MAE and bias
- Bucket breakdown produces correct per-range stats

---

## 2. Player Availability Field

### Problem
A prediction of 0.0 could mean "injured" or "genuinely bad player". The API response gave no way to distinguish.

### Solution

**New private method** `PredictionService::deriveAvailability(array $player): string`:

| Condition | Status |
|-----------|--------|
| `chance_of_playing` is null AND no news | `available` |
| `chance_of_playing` = 0, news contains "suspend" | `suspended` |
| `chance_of_playing` = 0 | `unavailable` |
| `chance_of_playing` <= 25 | `injured` |
| `chance_of_playing` <= 75 | `doubtful` |
| Otherwise | `available` |

**Wiring:**
- Added `availability` field to `generatePredictions()` output (line 129)
- Updated `getCachedPredictions()` to join `chance_of_playing` and `news`, compute availability in PHP loop, then strip raw fields
- Frontend: `availability?: 'available' | 'unavailable' | 'injured' | 'doubtful' | 'suspended'` added to `PlayerPrediction` interface
- `source?: 'snapshot'` added to `PredictionsResponse` interface

**Tests** (6 in `PredictionSnapshotTest.php`):
- All six availability states tested via reflection on `deriveAvailability()`

---

## 3. Calibration Infrastructure

### Problem
Backtesting showed apparent under-prediction in the 1-4 point range. Appearance points were being deflated by conservative `prob_any` estimates for rotation/backup players.

### Solution

**New private method** `PredictionEngine::calibrate(float $rawPoints): float`:

Piecewise linear adjustment:
- `< 1.0` — no change (preserves 0.0 for unavailable players)
- `>= 5.0` — no change (premium players already well-calibrated)
- `1.0 - 5.0` — triangular bump centered at 3.0, peak +0.8 points

This is a conservative initial curve. The real calibration will be data-driven once we accumulate enough snapshots.

**Future infrastructure** — `PredictionService::computeCalibrationCurve(array $gameweeks): array`:
- Queries snapshots vs actuals across multiple GWs
- Returns fine-grained bucket adjustments (0-1, 1-2, 2-3, ..., 8+)
- For manual analysis or future automated recalibration

**Tests** (3 in `PredictionEngineTest.php`):
- Zero predictions stay at zero
- High predictions (premium FWD archetype) stay in 3-10 range
- Low-mid predictions (rotation GK archetype) remain in 0-4 range
- All existing archetype tests continue to pass

---

## 4. Captain Candidates Shortlist

### Problem
Captain selection was `max(predicted_points)` — when the top 2-3 options are within ~0.5 pts, a single recommendation is misleadingly precise.

### Solution

**New static method** `TransferOptimizerService::findCaptainCandidates(array $starting11, float $threshold = 0.5): array`:
- Sorts starting 11 by prediction descending
- Returns all players within `threshold` of the top scorer
- Each candidate includes `player_id`, `predicted_points`, and `margin` (distance from top)

**Wiring:**
- Called in `buildFormationData()`, result added as `captain_candidates` to formation response
- Frontend: `CaptainCandidate` interface and `captain_candidates` field on `FormationData`
- **Captain Decision panel** in Planner page: renders when >1 candidate, shows each with their margin, top candidate highlighted

**Tests** (4 in `CaptainCandidatesTest.php`):
- Single clear winner (2+ pts ahead) returns 1 candidate
- Multiple close options (within 0.5) returns all
- Empty input returns empty array
- Margin calculation verified

---

## Files Changed

### Backend
| File | Changes |
|------|---------|
| `api/src/Database.php` | Added `prediction_snapshots` CREATE TABLE to `migrate()` |
| `api/src/Services/PredictionService.php` | Added `snapshotPredictions()`, `getSnapshotPredictions()`, `getAccuracy()`, `computeCalibrationCurve()`, `deriveAvailability()`, wired availability into output |
| `api/src/Prediction/PredictionEngine.php` | Added `calibrate()`, called before rounding in `predict()` |
| `api/src/Services/TransferOptimizerService.php` | Added `findCaptainCandidates()`, wired into `buildFormationData()` |
| `api/public/index.php` | Rewrote `handlePredictions()` for snapshot serving + lazy snapshotting, added `handlePredictionAccuracy()` route |

### Frontend
| File | Changes |
|------|---------|
| `frontend/src/api/client.ts` | Added `availability` to `PlayerPrediction`, `source` to `PredictionsResponse`, `CaptainCandidate` interface, `captain_candidates` to `FormationData` |
| `frontend/src/pages/Planner.tsx` | Added captain decision panel (renders when >1 candidate) |

### New Test Files
| File | Tests |
|------|-------|
| `api/tests/Services/PredictionSnapshotTest.php` | 12 tests (snapshots + availability) |
| `api/tests/Services/CaptainCandidatesTest.php` | 4 tests |
| `api/tests/Prediction/PredictionEngineTest.php` | 3 new calibration tests added |

**Total: 19 new tests, 111 backend + 174 frontend all passing**

---

## Verification

```bash
# Backend
cd /Users/mal/projects/super-fpl && vendor/bin/phpunit --testsuite API
# 111 tests, 295 assertions, all green

# Frontend types
cd frontend && npx tsc --noEmit
# Clean

# Frontend tests
cd frontend && npm test -- --run
# 174 tests, all passing
```

## Next Steps

- Accumulate 3-5 GWs of snapshots, then run `computeCalibrationCurve()` to derive data-driven calibration
- Consider auto-triggering snapshots via cron/sync endpoint rather than relying on lazy snapshotting
- Use availability field in league analysis to filter out unavailable players from differential calculations
- Captain candidates panel could be enhanced with form/fixture context
