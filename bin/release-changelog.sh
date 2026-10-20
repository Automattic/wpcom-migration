#!/usr/bin/env bash
#
# Prints a changelog bullet for each pull request merged between two refs,
# newest first. A squash merge counts when its subject ends in "(#NN)" and is
# printed as is. A merge commit counts by the pull request title on its second
# line, with its number added. Release commits and direct pushes are left
# out. The walk follows first parents and stops at a merge of unrelated
# histories: the merge that joined the old wporg-mirror branch to trunk.
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

while IFS=$'\t' read -r sha parents subject; do
    if [[ $parents == *' '* ]]; then
        # shellcheck disable=SC2086 # Two parent SHAs, split on purpose.
        if ! git merge-base $parents >/dev/null; then
            break
        fi
        if [[ $subject =~ ^Merge\ pull\ request\ \#([0-9]+)\  ]]; then
            number="${BASH_REMATCH[1]}"
            title="$(git log -1 --format=%b "$sha" | sed -n '/[^[:space:]]/{p;q;}')"
            if [ -n "$title" ]; then
                printf '* %s (#%s)\n' "$title" "$number"
            fi
        fi
    elif [[ $subject =~ \(#[0-9]+\)$ ]]; then
        printf '* %s\n' "$subject"
    fi
done < <(git log --first-parent --format='%H%x09%P%x09%s' "$1..$2")
