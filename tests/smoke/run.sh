#!/usr/bin/env bash
#
# Boots the built plugin in WordPress Playground once per credential state
# and checks the export endpoint's answers, then once more to drive the
# settings screen through its form handlers.
#
# Usage: tests/smoke/run.sh <built-plugin-dir>
#   e.g. tests/smoke/run.sh build/wpcom-migration
#
# Environment:
#   SMOKE_PORT       Port for the Playground server (default 9400).
#   PLAYGROUND_CLI   Command that runs the Playground CLI
#                    (default: npx --yes @wp-playground/cli@3.1.54).
#
set -euo pipefail

if [ $# -ne 1 ]; then
    echo "Usage: tests/smoke/run.sh <built-plugin-dir>" >&2
    exit 1
fi

PLUGIN_DIR="$(cd "$1" && pwd)"
SMOKE_DIR="$(cd "$(dirname "$0")" && pwd)"
PORT="${SMOKE_PORT:-9400}"
BASE_URL="http://127.0.0.1:$PORT"
PLAYGROUND_CLI="${PLAYGROUND_CLI:-npx --yes @wp-playground/cli@3.1.54}"
SCENARIOS=(open closed secret-hash-deleted enabled-hash-deleted screen)

for command_name in php npx; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "Error: $command_name not found in PATH." >&2
        exit 1
    fi
done

if [ ! -f "$PLUGIN_DIR/wpcom_migration.php" ] || [ ! -f "$PLUGIN_DIR/vendor/autoload_packages.php" ]; then
    echo "Error: $PLUGIN_DIR is not a built plugin tree. Run bin/build.sh first." >&2
    exit 1
fi

server_pid=""
server_log=""

stop_server() {
    if [ -n "$server_pid" ]; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
        server_pid=""
    fi
    if [ -n "$server_log" ]; then
        rm -f "$server_log"
        server_log=""
    fi
}
trap stop_server EXIT

for scenario in "${SCENARIOS[@]}"; do
    echo "== $scenario"
    server_log="$(mktemp)"

    # shellcheck disable=SC2086 # PLAYGROUND_CLI is a command line, split on purpose.
    $PLAYGROUND_CLI server \
        --port="$PORT" \
        --blueprint="$SMOKE_DIR/blueprint-$scenario.json" \
        --mount="$PLUGIN_DIR:/wordpress/wp-content/plugins/wpcom-migration" \
        --mount="$SMOKE_DIR:/wordpress/wp-content/wpcom-migration-smoke" \
        >"$server_log" 2>&1 &
    server_pid=$!

    ready=0
    for _ in $(seq 1 180); do
        if grep -q "WordPress is running on" "$server_log"; then
            ready=1
            break
        fi
        if ! kill -0 "$server_pid" 2>/dev/null; then
            break
        fi
        sleep 1
    done

    if [ "$ready" -ne 1 ]; then
        echo "Playground did not become ready for '$scenario':" >&2
        cat "$server_log" >&2
        exit 1
    fi

    if ! php "$SMOKE_DIR/request.php" "$BASE_URL" "$PLUGIN_DIR" "$scenario"; then
        echo "Playground log for '$scenario':" >&2
        cat "$server_log" >&2
        exit 1
    fi

    stop_server
done

echo "Smoke test passed."
