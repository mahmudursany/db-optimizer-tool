#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   bash scripts/preflight-target-project.sh /absolute/path/to/laravel-project

if [[ $# -lt 1 ]]; then
  echo "Usage: bash scripts/preflight-target-project.sh <target-laravel-project-path>"
  exit 1
fi

TARGET="$1"

if [[ ! -d "$TARGET" ]]; then
  echo "Error: target path not found: $TARGET"
  exit 1
fi

cd "$TARGET"

if [[ ! -f artisan || ! -f composer.json ]]; then
  echo "Error: not a Laravel project directory."
  exit 1
fi

echo "Checking PHP and Composer..."
php -v | head -n 1
composer --version

echo "Running composer validate..."
composer validate --no-check-all --no-check-publish || true

echo "Listing Laravel version..."
php artisan --version

echo "Preflight done."
