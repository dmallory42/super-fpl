# Page Audit Agent Workflow

This workflow runs each issue in `docs/page-audit-fixes-plan.md` as an isolated branch/worktree with four role passes:

1. `backend` agent: API/service/schema updates.
2. `frontend` agent: UI/types/hooks updates.
3. `tester` agent: adds/updates tests and runs targeted checks.
4. `reviewer` agent: code review pass; files feedback for coder.

## Branch + Worktree Convention

- Branch: `audit/<issue-key>-<slug>`
- Worktree: `.worktrees/<issue-key>-<slug>`
- Role log: `docs/orchestration/logs/<issue-key>/`

Example for `P1-1`:
- Branch: `audit/p1-1-manager-season-analysis`
- Worktree: `.worktrees/p1-1-manager-season-analysis`

## Review Loop

1. `backend` + `frontend` implement.
2. `tester` runs checks and records failures.
3. `reviewer` reports findings in `review.md`.
4. `backend`/`frontend` fix findings.
5. Repeat steps 2-4 until reviewer has no findings.
6. Commit and open PR for that issue branch.

## Minimum Required Checks Per Issue

- Backend touched: `cd api && vendor/bin/phpunit`
- Frontend touched: `cd frontend && npm test -- --run`
- Type safety for frontend changes: `cd frontend && npm run build`

## Tracking

Use `docs/orchestration/page-audit-tracker.md` to keep status by issue and role.
