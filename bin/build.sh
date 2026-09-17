#!/usr/bin/env bash
#
# Builds the shippable plugin tree and ZIP from plugin/.
#
# The plugin is copied to a temporary staging directory, the autoload
# manifest is checked there, and the result lands in build/. The source
# tree is never rewritten.
#
# Usage: bin/build.sh
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_SRC="$REPO_ROOT/plugin"
BUILD_DIR="$REPO_ROOT/build"
SLUG="wpcom-migration"

for command_name in php composer rsync zip; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "Error: $command_name not found in PATH." >&2
        exit 1
    fi
done

composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --working-dir="$PLUGIN_SRC"

STAGING="$(mktemp -d "${TMPDIR:-/tmp}/wpcom-migration-build.XXXXXX")"
trap 'rm -rf "$STAGING"' EXIT

# Composer manifests describe the source; the ZIP carries only vendor/.
rsync -a \
    --exclude '/composer.json' \
    --exclude '/composer.lock' \
    --exclude '.DS_Store' \
    "$PLUGIN_SRC/" "$STAGING/$SLUG/"

php "$REPO_ROOT/bin/check-autoload-manifest.php" "$STAGING/$SLUG"

rm -rf "$BUILD_DIR/$SLUG" "$BUILD_DIR/$SLUG.zip"
mkdir -p "$BUILD_DIR"
cp -R "$STAGING/$SLUG" "$BUILD_DIR/$SLUG"

# A top-level wpcom-migration/ folder, matching the wp.org slug.
(
    cd "$BUILD_DIR"
    zip -qr "$SLUG.zip" "$SLUG"
)

echo "Built $BUILD_DIR/$SLUG/ and $BUILD_DIR/$SLUG.zip"
