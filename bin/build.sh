#!/usr/bin/env bash
#
# Builds the shippable plugin tree and ZIP from plugin/.
#
# The source tree is never rewritten: the plugin is copied to a temporary
# staging directory, vendor/ and reprint/ are downgraded to PHP 7.1 syntax
# there with Rector, and the autoload manifest is checked before the result
# lands in build/.
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

if [ ! -x "$REPO_ROOT/vendor/bin/rector" ]; then
    echo "Error: Rector is not installed." >&2
    echo "Run: composer install" >&2
    exit 1
fi

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

# Belt and braces: the staging dir comes from mktemp, so this cannot be the
# source tree, but Rector rewrites in place and the check is free.
if [ "$(cd "$STAGING/$SLUG" && pwd -P)" = "$(cd "$PLUGIN_SRC" && pwd -P)" ]; then
    echo "Error: refusing to downgrade the source tree." >&2
    exit 1
fi

"$REPO_ROOT/vendor/bin/rector" process \
    "$STAGING/$SLUG/vendor" \
    "$STAGING/$SLUG/reprint" \
    --config "$REPO_ROOT/rector.php" \
    --no-progress-bar \
    --no-diffs \
    --clear-cache
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
