#!/usr/bin/env bash
#
# Exits non-zero unless a release tag is in the history of a trunk ref.
# Only commits on trunk are released.
#
# Usage: bin/check-tag-on-trunk.sh <tag> <trunk-ref>
#   e.g. bin/check-tag-on-trunk.sh 6.73 origin/trunk
#
set -euo pipefail

if [ $# -ne 2 ]; then
    echo "Usage: bin/check-tag-on-trunk.sh <tag> <trunk-ref>" >&2
    exit 1
fi

tag="$1"
trunk="$2"

if ! git rev-parse --verify --quiet "refs/tags/$tag^{commit}" >/dev/null; then
    echo "Tag $tag does not exist." >&2
    exit 1
fi

if ! git merge-base --is-ancestor "refs/tags/$tag" "$trunk"; then
    echo "Tag $tag is not in the history of $trunk. Only trunk is released." >&2
    exit 1
fi

echo "Tag $tag is on $trunk."
