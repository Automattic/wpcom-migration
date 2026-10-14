#!/usr/bin/env bash
#
# Tests the release scripts in bin/ against throwaway git repositories and
# copies of the plugin files that carry the version.
#
# Usage: tests/release/run.sh
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BIN="$REPO_ROOT/bin"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/wpcom-migration-release-tests.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

# The version the source carries now. Tests stamp 99.1, which is later.
OLD="$(sed -n 's/^Version: //p' "$REPO_ROOT/plugin/wpcom_migration.php")"

failures=0

pass() {
    printf 'ok   %s\n' "$1"
}

fail() {
    printf 'FAIL %s\n' "$1" >&2
    failures=$((failures + 1))
}

# expect_output <name> <expected-output> <command...>
expect_output() {
    local name="$1" expected="$2" actual
    shift 2
    if actual="$("$@" 2>&1)" && [ "$actual" = "$expected" ]; then
        pass "$name"
    else
        fail "$name"
        printf '  expected: %s\n  actual:   %s\n' "$expected" "$actual" >&2
    fi
}

# expect_failure <name> <text-the-output-must-contain> <command...>
expect_failure() {
    local name="$1" needle="$2" output
    shift 2
    if output="$("$@" 2>&1)"; then
        fail "$name (exited 0)"
        printf '  output: %s\n' "$output" >&2
    elif [[ $output != *"$needle"* ]]; then
        fail "$name"
        printf '  expected output containing: %s\n  actual: %s\n' "$needle" "$output" >&2
    else
        pass "$name"
    fi
}

# expect_true <name> <command...>
expect_true() {
    local name="$1"
    shift
    if "$@" >/dev/null 2>&1; then
        pass "$name"
    else
        fail "$name"
    fi
}

# in_dir <dir> <command...>
in_dir() {
    local dir="$1"
    shift
    (cd "$dir" && "$@")
}

# new_repo <name>: prints the path of an empty git repository on trunk.
new_repo() {
    local dir="$WORK/$1"
    git init -q "$dir"
    git -C "$dir" symbolic-ref HEAD refs/heads/trunk
    git -C "$dir" config user.name Test
    git -C "$dir" config user.email test@example.com
    git -C "$dir" config commit.gpgsign false
    git -C "$dir" config tag.gpgsign false
    printf '%s\n' "$dir"
}

# commit <repo> <subject>
commit() {
    git -C "$1" commit -q --allow-empty -m "$2"
}

# plugin_copy <name>: prints the path of a copy of the plugin files that carry the version.
plugin_copy() {
    local dir="$WORK/$1"
    mkdir -p "$dir"
    cp "$REPO_ROOT/plugin/wpcom_migration.php" "$REPO_ROOT/plugin/info.php" "$REPO_ROOT/plugin/readme.txt" "$dir/"
    printf '%s\n' "$dir"
}

# count_lines <file> <line>: how many lines equal <line>.
count_lines() {
    grep -cFx -- "$2" "$1" || true
}

# first_lines_of_changelog <readme> <count>
first_lines_of_changelog() {
    awk -v count="$2" '/^== Changelog ==$/ { on = 1; next } on && n < count { print; n++ }' "$1"
}

printf '* First change\n* Second change\n' > "$WORK/bullets.txt"
printf '* Replacement\n' > "$WORK/replacement.txt"
: > "$WORK/empty.txt"
printf -- '- Not a bullet\n' > "$WORK/dash.txt"

echo '# bin/release-lib.php'

expect_true 'reads and writes the readme without changing it' \
    php -r 'require $argv[1]; $readme = file_get_contents( $argv[2] ); exit( wpcom_migration_release_render_changelog( wpcom_migration_release_parse_changelog( $readme ) ) === $readme ? 0 : 1 );' \
    "$BIN/release-lib.php" "$REPO_ROOT/plugin/readme.txt"

echo '# bin/stamp-release.php'

dir="$(plugin_copy stamp)"
expect_true 'stamps a release' php "$BIN/stamp-release.php" "$dir" 99.1 "$WORK/bullets.txt"
expect_true 'sets the plugin header' grep -qFx 'Version: 99.1' "$dir/wpcom_migration.php"
expect_true 'sets WPCOMInfo::$version' grep -qF "public \$version = '99.1';" "$dir/info.php"
expect_true 'sets the Stable tag' grep -qFx 'Stable tag: 99.1' "$dir/readme.txt"
expect_output 'puts the entry first and keeps older entries' \
    "$(printf '= 99.1 =\n* First change\n* Second change\n\n= %s =' "$OLD")" \
    first_lines_of_changelog "$dir/readme.txt" 5

expect_true 'stamps the same version again' php "$BIN/stamp-release.php" "$dir" 99.1 "$WORK/replacement.txt"
expect_output 'replaces the entry instead of adding one' '1' count_lines "$dir/readme.txt" '= 99.1 ='
expect_output 'keeps the replacement bullets only' \
    "$(printf '= 99.1 =\n* Replacement\n\n= %s =' "$OLD")" \
    first_lines_of_changelog "$dir/readme.txt" 4

dir="$(plugin_copy leftover)"
printf "<?php\ndefine( 'WPCOM_MIGRATION_VERSION', '%s' );\n" "$OLD" > "$dir/extra.php"
expect_failure 'fails on a version string nobody listed' 'extra.php:2' \
    php "$BIN/stamp-release.php" "$dir" 99.1 "$WORK/bullets.txt"
expect_true 'writes nothing when it fails' grep -qFx "Version: $OLD" "$dir/wpcom_migration.php"

dir="$(plugin_copy vendor)"
mkdir -p "$dir/vendor/some/package"
printf 'Tested with %s\n' "$OLD" > "$dir/vendor/some/package/README"
expect_true 'ignores vendor/' php "$BIN/stamp-release.php" "$dir" 99.1 "$WORK/bullets.txt"

dir="$(plugin_copy php-collision)"
cp "$REPO_ROOT/plugin/composer.json" "$dir/"
printf '* Earlier\n' > "$WORK/earlier.txt"
php "$BIN/stamp-release.php" "$dir" 7.4 "$WORK/earlier.txt" >/dev/null
expect_true 'ignores Requires PHP, Tested up to and composer files' php "$BIN/stamp-release.php" "$dir" 7.5 "$WORK/bullets.txt"
sed -i.bak 's/^Tested up to: .*/Tested up to: 7.5/' "$dir/readme.txt" && rm "$dir/readme.txt.bak"
expect_true 'ignores Tested up to when it equals the old version' php "$BIN/stamp-release.php" "$dir" 7.6 "$WORK/bullets.txt"

dir="$(plugin_copy missing-line)"
grep -vF 'public $version' "$REPO_ROOT/plugin/info.php" > "$dir/info.php"
expect_failure 'fails when a place is missing' 'info.php: expected exactly one line' \
    php "$BIN/stamp-release.php" "$dir" 99.1 "$WORK/bullets.txt"

dir="$(plugin_copy duplicate-line)"
printf 'Stable tag: %s\n' "$OLD" >> "$dir/readme.txt"
expect_failure 'fails when a place matches twice' 'readme.txt: expected exactly one line' \
    php "$BIN/stamp-release.php" "$dir" 99.1 "$WORK/bullets.txt"

dir="$(plugin_copy bad-input)"
expect_failure 'rejects a three-part version' 'Not an X.Y version: 99.1.1' \
    php "$BIN/stamp-release.php" "$dir" 99.1.1 "$WORK/bullets.txt"
expect_failure 'rejects an empty changelog' 'The changelog is empty.' \
    php "$BIN/stamp-release.php" "$dir" 99.1 "$WORK/empty.txt"
expect_failure 'rejects a line that is not a bullet' 'Not a changelog bullet: - Not a bullet' \
    php "$BIN/stamp-release.php" "$dir" 99.1 "$WORK/dash.txt"
grep -vFx '== Changelog ==' "$REPO_ROOT/plugin/readme.txt" > "$dir/readme.txt"
expect_failure 'fails without a changelog section' '"== Changelog ==" line, found 0' \
    php "$BIN/stamp-release.php" "$dir" 99.1 "$WORK/bullets.txt"

echo '# bin/check-release.php'

dir="$(plugin_copy check)"
php "$BIN/stamp-release.php" "$dir" 99.1 "$WORK/bullets.txt" >/dev/null
expect_output 'passes on a stamped tree' 'Release check passed: 99.1' php "$BIN/check-release.php" "$dir" 99.1
expect_failure 'fails on another version' 'wpcom_migration.php: "Version: 99.1" should carry 99.2' \
    php "$BIN/check-release.php" "$dir" 99.2
expect_failure 'rejects a three-part version' 'Not an X.Y version: 99.1.1' php "$BIN/check-release.php" "$dir" 99.1.1
sed -i.bak "s/'99.1'/'$OLD'/" "$dir/info.php" && rm "$dir/info.php.bak"
expect_failure 'names the one place that disagrees' "info.php: \"public \$version = '$OLD';\" should carry 99.1" \
    php "$BIN/check-release.php" "$dir" 99.1
expect_failure 'fails when the changelog is behind' "the first changelog entry is $OLD, expected 99.1" \
    php "$BIN/check-release.php" "$(plugin_copy check-unstamped)" 99.1

echo '# bin/release-notes.php'

dir="$(plugin_copy notes)"
php "$BIN/stamp-release.php" "$dir" 99.1 "$WORK/bullets.txt" >/dev/null
expect_output 'prints the entry' "$(printf '* First change\n* Second change')" php "$BIN/release-notes.php" "$dir/readme.txt" 99.1
expect_failure 'fails without an entry' 'readme.txt has no changelog entry for 98.0.' \
    php "$BIN/release-notes.php" "$dir/readme.txt" 98.0

echo '# bin/latest-release.sh'

repo="$(new_repo latest)"
commit "$repo" 'First'
for tag in 0.1.0 1.0.1 2.0.0 6.65 6.72; do
    git -C "$repo" tag "$tag"
done
expect_output 'ignores three-part tags' '6.72' in_dir "$repo" "$BIN/latest-release.sh"

repo="$(new_repo latest-numeric)"
commit "$repo" 'First'
git -C "$repo" tag 6.9
git -C "$repo" tag 6.10
expect_output 'compares versions as numbers' '6.10' in_dir "$repo" "$BIN/latest-release.sh"

repo="$(new_repo latest-none)"
commit "$repo" 'First'
git -C "$repo" tag 1.0.0
expect_failure 'fails without an X.Y tag' 'No X.Y release tag found.' in_dir "$repo" "$BIN/latest-release.sh"

echo '# bin/next-release-version.sh'

expect_output 'minor' '6.73' "$BIN/next-release-version.sh" 6.72 minor
expect_output 'minor past nine' '6.10' "$BIN/next-release-version.sh" 6.9 minor
expect_output 'minor past ten' '6.11' "$BIN/next-release-version.sh" 6.10 minor
expect_output 'major' '7.0' "$BIN/next-release-version.sh" 6.72 major
expect_output 'custom' '6.80' "$BIN/next-release-version.sh" 6.72 custom 6.80
expect_failure 'custom equal to the latest' 'must come after 6.72' "$BIN/next-release-version.sh" 6.72 custom 6.72
expect_failure 'custom below the latest' 'must come after 6.72' "$BIN/next-release-version.sh" 6.72 custom 6.8
expect_failure 'custom three-part' 'must look like X.Y' "$BIN/next-release-version.sh" 6.72 custom 6.73.1
expect_failure 'custom with a v' 'must look like X.Y' "$BIN/next-release-version.sh" 6.72 custom v6.80
expect_failure 'custom without a version' 'must look like X.Y' "$BIN/next-release-version.sh" 6.72 custom
expect_failure 'unknown bump' 'Bump must be minor, major or custom' "$BIN/next-release-version.sh" 6.72 patch
expect_failure 'bad latest' 'Latest version must look like X.Y' "$BIN/next-release-version.sh" 6.72.1 minor

echo '# bin/release-changelog.sh'

# Shaped like trunk: Jetpack history, then the wporg-mirror merge that
# brought in the 6.72 tag, then this repository's pull requests.
repo="$(new_repo changelog)"
commit "$repo" 'Jetpack: unrelated change (#40167)'
git -C "$repo" checkout -q --orphan wporg-mirror
commit "$repo" 'Updating and Tagging 6.72 version'
git -C "$repo" tag 6.72
git -C "$repo" checkout -q trunk
git -C "$repo" merge -q --allow-unrelated-histories --no-edit -m "Merge branch 'wporg-mirror' into trunk" wporg-mirror
commit "$repo" 'Update README.md'
commit "$repo" 'Add the export server (#1)'
commit "$repo" 'Mention (#2) in the middle'
commit "$repo" 'Provision with an application password (#3)'
expect_output 'stops at the wporg-mirror merge and keeps only pull requests' \
    "$(printf '* Provision with an application password\n* Add the export server')" \
    in_dir "$repo" "$BIN/release-changelog.sh" 6.72 HEAD

commit "$repo" 'Release 6.73'
git -C "$repo" tag 6.73
expect_output 'prints nothing when nothing was merged' '' in_dir "$repo" "$BIN/release-changelog.sh" 6.73 HEAD
commit "$repo" 'Fix the screen (#4)'
expect_output 'skips release commits' '* Fix the screen' in_dir "$repo" "$BIN/release-changelog.sh" 6.73 HEAD
git -C "$repo" checkout -q -b feature
commit "$repo" 'Work in progress'
git -C "$repo" checkout -q trunk
commit "$repo" 'Tidy the screen (#6)'
git -C "$repo" merge -q --no-ff -m 'Merge pull request #5 from Automattic/feature' -m 'Add the merged feature' feature
expect_output 'takes a merge commit pull request from its title and keeps going' \
    "$(printf '* Add the merged feature\n* Tidy the screen\n* Fix the screen')" \
    in_dir "$repo" "$BIN/release-changelog.sh" 6.73 HEAD
expect_failure 'fails on an unknown ref' 'Unknown ref: 9.9' in_dir "$repo" "$BIN/release-changelog.sh" 9.9 HEAD

# Summary

if [ "$failures" -ne 0 ]; then
    printf '\n%d release script test(s) failed.\n' "$failures" >&2
    exit 1
fi
printf '\nAll release script tests passed.\n'
