#!/usr/bin/env bash
# Recover STREAMLIT_SECRETS_TOML from GitHub Actions into .streamlit/secrets.toml.
#
# Triggers export-secret.yml, waits, downloads the artifact, then deletes it.
# Requirements: gh (logged in: `gh auth login`). The workflow must be on main.
#
# Usage: scripts/recover_secret.sh
set -euo pipefail

WORKFLOW="export-secret.yml"
ARTIFACT="streamlit-secrets"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/.streamlit/secrets.toml"

command -v gh >/dev/null || { echo "Missing 'gh'. Install it first." >&2; exit 1; }
[ ! -e "$OUT" ] || { echo "$OUT already exists; move it away first." >&2; exit 1; }

BEFORE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
gh workflow run "$WORKFLOW" --ref main
echo "Waiting for run to appear..."
RUN_ID=""
for _ in $(seq 1 30); do
  sleep 3
  RUN_ID="$(gh run list --workflow="$WORKFLOW" --event workflow_dispatch --limit 5 \
    --json databaseId,createdAt \
    -q "[.[] | select(.createdAt >= \"$BEFORE\")][0].databaseId // empty")"
  [ -n "$RUN_ID" ] && break
done
[ -n "$RUN_ID" ] || { echo "Could not find the triggered run." >&2; exit 1; }

gh run watch "$RUN_ID" --exit-status

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
gh run download "$RUN_ID" -n "$ARTIFACT" -D "$TMP"
mkdir -p "$(dirname "$OUT")"
( umask 077; cp "$TMP/secrets.toml" "$OUT" )
echo "Wrote $OUT"

for id in $(gh api "repos/{owner}/{repo}/actions/runs/$RUN_ID/artifacts" -q '.artifacts[].id'); do
  gh api -X DELETE "repos/{owner}/{repo}/actions/artifacts/$id" && echo "Deleted artifact $id"
done

echo "Sections found:"; grep -E '^\[' "$OUT" || true
