#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   bash scripts/tag-package-release.sh v1.0.0

if [[ $# -lt 1 ]]; then
  echo "Usage: bash scripts/tag-package-release.sh <tag>"
  exit 1
fi

TAG="$1"
PKG_REMOTE_NAME="package"
PKG_BRANCH="main"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if ! git remote | grep -q "^${PKG_REMOTE_NAME}$"; then
  echo "Error: package remote not found. Run split-package.sh first."
  exit 1
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

echo "Cloning package remote..."
git clone --depth=1 --branch "$PKG_BRANCH" "$(git remote get-url "$PKG_REMOTE_NAME")" "$TMP_DIR"

cd "$TMP_DIR"

git tag -a "$TAG" -m "Release $TAG"
git push origin "$TAG"

echo "Tag pushed: $TAG"
