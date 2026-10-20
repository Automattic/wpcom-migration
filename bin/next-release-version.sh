#!/usr/bin/env bash
#
# Prints the version after <latest>: X.(Y+1) for minor, (X+1).0 for major,
# or a custom X.Y that sorts after <latest>.
#
# Usage: bin/next-release-version.sh <latest> <minor|major|custom> [custom-version]
#
set -euo pipefail

if [ $# -lt 2 ] || [ $# -gt 3 ]; then
    echo "Usage: bin/next-release-version.sh <latest> <minor|major|custom> [custom-version]" >&2
    exit 1
fi

latest="$1"
bump="$2"
custom="${3:-}"

if ! [[ $latest =~ ^[0-9]+\.[0-9]+$ ]]; then
    echo "Latest version must look like X.Y: '$latest'" >&2
    exit 1
fi

major="${latest%%.*}"
minor="${latest#*.}"

case "$bump" in
    minor)
        echo "$major.$((10#$minor + 1))"
        ;;
    major)
        echo "$((10#$major + 1)).0"
        ;;
    custom)
        if ! [[ $custom =~ ^[0-9]+\.[0-9]+$ ]]; then
            echo "Custom version must look like X.Y: '$custom'" >&2
            exit 1
        fi
        if [ "$custom" = "$latest" ] || [ "$(printf '%s\n%s\n' "$latest" "$custom" | sort -V | tail -n 1)" != "$custom" ]; then
            echo "Custom version $custom must come after $latest." >&2
            exit 1
        fi
        echo "$custom"
        ;;
    *)
        echo "Bump must be minor, major or custom: '$bump'" >&2
        exit 1
        ;;
esac
