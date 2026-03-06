# Maia Framework Gaps

Discovered during the Super FPL backend migration to Maia (February-March 2026).

## High Priority

- Query builder `upsert()` support is missing.
  - Current workaround: manual `INSERT OR REPLACE` or `ON CONFLICT ... DO UPDATE`.
  - Impact: sync jobs and prediction caching require repeated SQL boilerplate.
- Query builder support for richer aggregation is limited (`GROUP BY` + `HAVING` workflows).
  - Current workaround: raw SQL via `Connection::query()`.
  - Impact: gameweek analytics and prediction accuracy/reporting queries stay SQL-heavy.

## Medium Priority

- Query builder support for expressive joins and union-heavy reporting queries is still limited for this codebase.
  - Current workaround: explicit SQL strings in service classes.
- No framework-level schema migrator is used in this app path.
  - Current workaround: project-local `SuperFPL\Api\SchemaMigrator`.
  - Impact: migration logic is app-owned instead of framework-owned.
- No first-class response cache middleware abstraction for app-specific keying/TTL behavior.
  - Current workaround: custom `ResponseCacheMiddleware` and file-based live-data cache.

## Low Priority

- SQLite pragma bootstrap (foreign keys, busy timeout, WAL, synchronous) is manual at app boot.
- Composite-key-heavy flows are workable, but still SQL-first (for example `manager_picks`, `league_members`, snapshot tables).

