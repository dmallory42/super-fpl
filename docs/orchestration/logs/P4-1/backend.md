# P4-1 Backend Agent Log

- Status: done
- Notes:
  - Added planner objective modes: `expected`, `floor`, `ceiling`.
  - API now parses `objective` query param and returns `objective_mode` in optimize payload.
  - Path solver scoring now supports objective-aware evaluation using prediction confidence and expected minutes.
