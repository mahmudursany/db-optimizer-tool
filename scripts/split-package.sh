#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   bash scripts/split-package.sh https://github.com/<owner>/db-optimizer-agent.git

if [[ $# -lt 1 ]]; then
  echo "Usage: bash scripts/split-package.sh <package-repo-url>"
  exit 1
fi

PKG_REMOTE_URL="$1"
PKG_REMOTE_NAME="package"
PKG_PREFIX="packages/db-optimizer-agent"
PKG_BRANCH="main"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [[ ! -d .git ]]; then
  echo "Error: run this from git repository root."
  exit 1
fi

if git remote | grep -q "^${PKG_REMOTE_NAME}$"; then
  git remote set-url "$PKG_REMOTE_NAME" "$PKG_REMOTE_URL"
else
  git remote add "$PKG_REMOTE_NAME" "$PKG_REMOTE_URL"
fi

echo "[1/3] Splitting subtree '${PKG_PREFIX}'..."
SPLIT_SHA="$(git subtree split --prefix="$PKG_PREFIX")"

echo "[2/3] Pushing split commit ${SPLIT_SHA} to ${PKG_REMOTE_URL} (${PKG_BRANCH})..."
git push "$PKG_REMOTE_NAME" "${SPLIT_SHA}:refs/heads/${PKG_BRANCH}" --force

echo "[3/3] Done. Package branch updated."
echo "Now tag package repo and publish to Packagist."
