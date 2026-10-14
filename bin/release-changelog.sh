#!/usr/bin/env bash
#
# Prints a changelog bullet for each pull request squash-merged between two
# refs, newest first. Only subjects ending in "(#NN)" count, so release
# commits and direct pushes are left out. The walk follows first parents
# and stops at the first merge commit: the merge that joined the old
# wporg-mirror branch to trunk.
#
# Usage: bin/release-changelog.sh <from> <to>
#
set -euo pipefail

if [ $# -ne 2 ]; then
    echo "Usage: bin/release-changelog.sh <from> <to>" >&2
    exit 1
fi

for ref in "$1" "$2"; do
    if ! git rev-parse --verify --quiet "$ref^{commit}" >/dev/null; then
        echo "Unknown ref: $ref" >&2
        exit 1
    fi
done

while IFS=$'\t' read -r parents subject; do
    case "$parents" in
        *' '*) break ;;
    esac
    if [[ $subject =~ ^(.*[^[:space:]])[[:space:]]+\(#[0-9]+\)$ ]]; then
        printf '* %s\n' "${BASH_REMATCH[1]}"
    fi
done < <(git log --first-parent --format='%P%x09%s' "$1..$2")
