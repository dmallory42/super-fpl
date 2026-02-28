# Super FPL Backend Migration to Maia Framework

**Date:** 2026-02-28
**Status:** Approved

## Goals

1. Validate Maia as a production-ready PHP framework using a real application
2. Improve Super FPL backend architecture (routing, DI, testability, code organization)
3. Track framework gaps and batch Maia improvements between migration phases

## Approach

Big-bang migration on a feature branch. Replace the entire backend while preserving the API contract (same URLs, same JSON response shapes). The frontend should work identically without changes.

## Target Architecture

```
api/
├── bootstrap.php              # App::create(), register controllers, middleware
├── public/index.php           # Slim entry: require bootstrap, $app->run()
├── config/config.php          # Existing config (unchanged format)
├── src/
│   ├── Controllers/           # 8 domain controllers (attribute-routed)
│   │   ├── HealthController.php
│   │   ├── PlayerController.php
│   │   ├── FixtureController.php
│   │   ├── ManagerController.php
│   │   ├── LeagueController.php
│   │   ├── PredictionController.php
│   │   ├── LiveController.php
│   │   ├── TransferController.php
│   │   └── AdminController.php
│   ├── Models/                # Maia ORM models (14 models)
│   ├── Middleware/             # Custom middleware
│   │   ├── AdminAuthMiddleware.php
│   │   └── ResponseCacheMiddleware.php
│   ├── Services/              # Existing services (refactored for ORM)
│   ├── Prediction/            # Existing prediction engine (minimal changes)
│   ├── Sync/                  # Existing sync classes (adapted for new DB layer)
│   └── Clients/               # Existing API clients (unchanged)
├── migrations/                # Maia migrations (converted from schema.sql)
├── data/                      # SQLite DB file
└── tests/                     # Maia TestCase-based tests
```

## Controllers & Routing

Attribute-based routing via Maia's `#[Controller]` and `#[Route]` attributes. Admin-protected endpoints use `#[Middleware(AdminAuthMiddleware::class)]` at the method level.

| Controller | Prefix | Endpoints | Notes |
|-----------|--------|-----------|-------|
| HealthController | `/` | 3 | Health, sync status |
| PlayerController | `/players` | 7 | CRUD + xmins/penalty overrides (admin-protected) |
| FixtureController | `/fixtures` | 2 | List + status |
| ManagerController | `/managers` | 4 | Profile, picks, history, analysis |
| LeagueController | `/leagues` | 4 | Standings, analysis |
| PredictionController | `/predictions` | 5 | Predictions, accuracy, methodology |
| LiveController | `/live` | 6 | Live data, bonus, samples |
| TransferController | `/transfers`, `/planner` | 5 | Suggestions, optimizer, chips |
| AdminController | `/admin` | 6 | Login, session, sync triggers, penalty takers |

**Total: ~48 endpoints** (matching current API exactly)

## Middleware Pipeline

| Order | Middleware | Scope | Source |
|-------|-----------|-------|--------|
| 1 | CorsMiddleware | Global | Maia built-in |
| 2 | SecurityHeadersMiddleware | Global | Maia built-in |
| 3 | ResponseCacheMiddleware | Per-route | Custom (wraps Redis/Predis) |
| 4 | AdminAuthMiddleware | Per-method | Custom (replaces withAdminToken) |

## ORM Models

| Model | Table | Key Relationships |
|-------|-------|-------------------|
| Club | clubs | HasMany Player, HasMany Fixture |
| Player | players | BelongsTo Club, HasMany PlayerPrediction, HasMany GameweekHistory |
| Fixture | fixtures | BelongsTo Club (home), BelongsTo Club (away) |
| Manager | managers | HasMany ManagerPick, HasMany ManagerHistory |
| ManagerPick | manager_picks | BelongsTo Manager, BelongsTo Player |
| ManagerHistory | manager_history | BelongsTo Manager |
| League | leagues | HasMany LeagueMember |
| LeagueMember | league_members | BelongsTo League, BelongsTo Manager |
| PlayerPrediction | player_predictions | BelongsTo Player |
| PredictionSnapshot | prediction_snapshots | BelongsTo Player |
| FixtureOdds | fixture_odds | BelongsTo Fixture |
| GoalscorerOdds | player_goalscorer_odds | BelongsTo Player |
| AssistOdds | player_assist_odds | BelongsTo Player |
| SamplePick | sample_picks | BelongsTo Player |

## Raw SQL Boundaries

The ORM handles standard CRUD and simple queries. Raw SQL via `Connection::query()` used for:

- **Prediction engine** — complex multi-table calculations
- **Path solver** — beam search scoring queries
- **Aggregation queries** — ownership calculations, season analysis (need GROUP BY/HAVING)
- **Sync upserts** — INSERT OR REPLACE until Maia adds upsert support
- **Complex joins** — queries joining 3+ tables with calculated fields

## Maia Framework Gaps

Track in `docs/maia-gaps.md`. Known gaps at design time:

| Gap | Impact | Priority |
|-----|--------|----------|
| No groupBy()/having() | Aggregation queries need raw SQL | High |
| No upsert() | All sync operations need raw SQL | High |
| No whereRaw() / raw expressions | Complex WHERE clauses | Medium |
| No join() support | Multi-table queries | Medium |
| No response caching middleware | Must build custom | Medium |
| No index() in schema builder | Raw SQL in migrations | Low |
| No SQLite pragma config | Raw SQL at boot | Low |

## Migration Phases

All work on a single feature branch. Each phase is a commit point.

### Phase 1: Scaffold
- Add Maia as composer dependency (path repository)
- Create bootstrap.php with App::create()
- Configure container bindings (Database/Connection, FplClient, Config, Redis)
- SQLite pragmas via raw SQL at boot

### Phase 2: Models
- Create all 14 ORM models with table mappings and relationships
- Convert schema.sql into Maia migration files (indexes via raw SQL)

### Phase 3: Middleware
- CorsMiddleware (Maia built-in, configured with allowed origins from config)
- SecurityHeadersMiddleware (Maia built-in)
- AdminAuthMiddleware (custom, port token/cookie/CSRF logic)
- ResponseCacheMiddleware (custom, port Redis caching with TTL per-route)

### Phase 4: Controllers
- Create 8 controllers with attribute-based routing
- Initially call existing service methods directly
- Verify all 48 endpoints return correct responses

### Phase 5: Service Refactor
- Update services to use Models and QueryBuilder instead of raw $db calls
- Keep raw SQL for complex queries (predictions, aggregations)
- Ensure response shapes remain identical

### Phase 6: Sync Refactor
- Update sync classes to use Connection for upserts
- Adapt to new bootstrap/config patterns

### Phase 7: Tests
- Rewrite tests using Maia TestCase (HTTP testing, in-memory SQLite)
- API contract tests for all endpoints (verify JSON shapes)
- Adapted service tests using Models

### Phase 8: Cleanup
- Remove old index.php router (2,400 lines)
- Remove old Database.php wrapper
- Remove dead code
- Update Docker entrypoint if needed
- Update CLAUDE.md project docs

## Testing Strategy

- **API contract tests:** For each endpoint, assert same response keys and value types
- **Service tests:** Existing tests adapted for ORM
- **Frontend smoke test:** Run the React app against the new backend
- **Type consistency:** Verify integer vs string handling (SQLite + ORM)

## Risk Mitigation

- Feature branch with clean revert path
- Frontend unchanged — same API contract
- Raw SQL fallback for any ORM limitation
- Existing tests adapted, not discarded
- Maia gaps tracked, not blocked on
