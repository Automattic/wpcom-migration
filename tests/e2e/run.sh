#!/usr/bin/env bash
#
# Boots the built plugin in WordPress Playground once per credential state
# and checks the export endpoint's answers, then once more to drive the
# settings screen through its form handlers, and once more to provision
# through the REST routes with application passwords.
#
# Usage: tests/e2e/run.sh <built-plugin-dir>
#   e.g. tests/e2e/run.sh build/wpcom-migration
#
# Environment:
#   E2E_PORT         Port for the Playground server (default 9400).
#   E2E_SCENARIOS    Space-separated subset of scenarios to run
#                    (default: all of them).
#   PLAYGROUND_CLI   Command that runs the Playground CLI
#                    (default: npx --yes @wp-playground/cli@3.1.54).
#
set -euo pipefail

if [ $# -ne 1 ]; then
    echo "Usage: tests/e2e/run.sh <built-plugin-dir>" >&2
    exit 1
fi

PLUGIN_DIR="$(cd "$1" && pwd)"
E2E_DIR="$(cd "$(dirname "$0")" && pwd)"
PORT="${E2E_PORT:-9400}"
BASE_URL="http://127.0.0.1:$PORT"
PLAYGROUND_CLI="${PLAYGROUND_CLI:-npx --yes @wp-playground/cli@3.1.54}"
# shellcheck disable=SC2206 # A space-separated scenario list, split on purpose.
SCENARIOS=(${E2E_SCENARIOS:-open closed secret-hash-deleted enabled-hash-deleted screen provisioning connection menu})

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

# npx wraps the real server in a child process, and a signal to the wrapper
# does not always reach it. Signal every descendant, deepest first.
kill_tree() {
    local pid="$1" child
    for child in $(pgrep -P "$pid" 2>/dev/null); do
        kill_tree "$child"
    done
    kill -TERM "$pid" 2>/dev/null || true
}

port_in_use() {
    (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null
}

stop_server() {
    if [ -n "$server_pid" ]; then
        kill_tree "$server_pid"
        wait "$server_pid" 2>/dev/null || true
        server_pid=""
    fi
    if [ -n "$server_log" ]; then
        rm -f "$server_log"
        server_log=""
    fi
}
trap stop_server EXIT

# The next boot needs the port; a server that is still shutting down holds it.
wait_for_port_free() {
    local _
    for _ in $(seq 1 30); do
        if ! port_in_use; then
            return 0
        fi
        sleep 1
    done
    echo "Port $PORT is still in use after 30s; is another Playground running?" >&2
    exit 1
}

wait_for_port_free

for scenario in "${SCENARIOS[@]}"; do
    echo "== $scenario"
    server_log="$(mktemp)"

    # shellcheck disable=SC2086 # PLAYGROUND_CLI is a command line, split on purpose.
    $PLAYGROUND_CLI server \
        --port="$PORT" \
        --blueprint="$E2E_DIR/blueprint-$scenario.json" \
        --mount="$PLUGIN_DIR:/wordpress/wp-content/plugins/wpcom-migration" \
        --mount="$E2E_DIR:/wordpress/wp-content/wpcom-migration-e2e" \
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

    if ! php "$E2E_DIR/request.php" "$BASE_URL" "$PLUGIN_DIR" "$scenario"; then
        echo "Playground log for '$scenario':" >&2
        cat "$server_log" >&2
        exit 1
    fi

    stop_server
    wait_for_port_free
done

echo "E2E passed."
