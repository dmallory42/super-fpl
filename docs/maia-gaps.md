# Maia Framework Gaps

Original migration gaps were reassessed against `maia/framework v0.2.0` on March 10, 2026.

## Resolved Upstream

- Query builder `upsert()` now exists.
- Query builder now supports `join()`, `leftJoin()`, `groupBy()`, and `having()`.
- SQLite bootstrap helpers now exist via `Connection::sqlite()` and `configureSqlite()`.
- Framework response-cache middleware now exists in `Maia\Core\Middleware\ResponseCacheMiddleware`.

## Remaining App-Level Gaps

- Super FPL still owns schema bootstrap and incremental DB migration logic in `SuperFPL\Api\SchemaMigrator`.
- Several analytics and reporting paths remain raw-SQL-first by choice because they are clearer as explicit SQL in this codebase.
