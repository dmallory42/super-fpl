#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 2 ]]; then
  echo "Usage: $0 <ISSUE_KEY> <slug>"
  echo "Example: $0 P1-1 manager-season-analysis"
  exit 1
fi

ISSUE_KEY="$1"
SLUG="$2"

ISSUE_KEY_LC="$(echo "$ISSUE_KEY" | tr '[:upper:]' '[:lower:]')"
BRANCH="audit/${ISSUE_KEY_LC}-${SLUG}"
WORKTREE=".worktrees/${ISSUE_KEY_LC}-${SLUG}"
LOG_DIR="docs/orchestration/logs/${ISSUE_KEY}"

if git rev-parse --verify --quiet "$BRANCH" >/dev/null; then
  echo "Branch exists: $BRANCH"
else
  git branch "$BRANCH" main
  echo "Created branch: $BRANCH"
fi

if [[ -d "$WORKTREE" ]]; then
  echo "Worktree exists: $WORKTREE"
else
  git worktree add "$WORKTREE" "$BRANCH"
  echo "Created worktree: $WORKTREE"
fi

mkdir -p "$LOG_DIR"

cat > "${LOG_DIR}/backend.md" <<EOF
# ${ISSUE_KEY} Backend Agent Log

- Status: in_progress
- Notes:
EOF

cat > "${LOG_DIR}/frontend.md" <<EOF
# ${ISSUE_KEY} Frontend Agent Log

- Status: pending
- Notes:
EOF

cat > "${LOG_DIR}/tester.md" <<EOF
# ${ISSUE_KEY} Tester Agent Log

- Status: pending
- Notes:
EOF

cat > "${LOG_DIR}/review.md" <<EOF
# ${ISSUE_KEY} Reviewer Agent Log

- Status: pending
- Findings:
EOF

echo "Bootstrap complete for ${ISSUE_KEY}"
echo "Branch: ${BRANCH}"
echo "Worktree: ${WORKTREE}"
echo "Logs: ${LOG_DIR}"
