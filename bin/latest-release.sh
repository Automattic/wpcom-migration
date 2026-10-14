#!/usr/bin/env bash
#
# Prints the latest release: the highest X.Y tag. Older three-part tags
# are not releases of this plugin's current line and are ignored.
#
# Usage: bin/latest-release.sh
#
set -euo pipefail

latest="$(git tag --list | { grep -E '^[0-9]+\.[0-9]+$' || true; } | sort -V | tail -n 1)"

if [ -z "$latest" ]; then
    echo "No X.Y release tag found." >&2
    exit 1
fi

echo "$latest"
