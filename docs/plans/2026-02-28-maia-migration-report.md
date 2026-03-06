# Maia Migration Report

Date: March 6, 2026  
Scope: Super FPL API backend migration from procedural routing + custom DB wrapper to Maia controllers + Maia ORM `Connection`.

## 1) Quantitative Changes

- Migration diff (branch vs pre-migration base): `73 files changed, +5915 / -2659`.
- Old procedural router replacement:
  - `api/public/index.php` reduced from `2424` lines to `90` lines.
- Backend structure now includes:
  - `10` controllers (`api/src/Controllers`)
  - `16` models (`api/src/Models`)
  - `2` middleware classes (`api/src/Middleware`)
- Test suite status:
  - `316` backend tests passing (`api/tests`).

## 2) Architecture Improvements

- Routing moved to Maia attribute controllers; endpoint behavior now maps directly to controller classes.
- Dependency wiring centralized in `api/bootstrap.php` with container-managed `Connection`, config, and `FplClient`.
- Legacy `Database.php` removed. Runtime code now uses `Maia\Orm\Connection` consistently.
- Schema initialization/migrations are explicit via `SuperFPL\Api\SchemaMigrator`.
- Service boundaries stayed intact while database plumbing was modernized.

## 3) Framework Validation (Maia in a Real App)

What worked well:
- Attribute routing and controller registration.
- Container-based dependency injection for controllers/services.
- ORM models for straightforward entity access.
- Lightweight test harness integration (`Maia\Core\Testing\TestCase`).

What remained SQL-first:
- Analytics, snapshots, and sync-heavy operations still rely on raw SQL for clarity/performance/control.

## 4) Gaps Impact

- Raw SQL remains significant in core services/sync flows (`~55` direct `query()` call sites in `api/src`).
- Upsert-heavy paths required manual SQL:
  - `INSERT OR REPLACE` occurrences: `4`
  - `ON CONFLICT ... DO UPDATE` occurrences: `3`
- Gap details and priority are documented in [docs/maia-gaps.md](/Users/mal/projects/personal/superfpl/docs/maia-gaps.md).

## 5) Developer Experience

Positives:
- Clearer routing and dependency wiring than the old procedural index.
- Better testability with `Connection`-based composition and Maia test utilities.

Pain points:
- Upsert and complex query ergonomics still require manual SQL helpers.
- Migration logic had to be implemented in-app (`SchemaMigrator`) instead of a framework-native migration path.

## 6) Performance

- No controlled before/after benchmark was run in this migration pass.
- Qualitative expectation: routing/DI clarity improved significantly; query performance is mostly unchanged because complex SQL paths were intentionally preserved.

## 7) Recommendation

Migration is worth it for maintainability and architectural clarity:

- Keep Maia as the backend foundation.
- Prioritize framework additions for:
  1. Query-builder upsert support.
  2. Better aggregate/join ergonomics for analytics queries.
  3. Optional first-class migration workflow to reduce app-local schema plumbing.

