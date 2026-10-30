#!/usr/bin/env bash
# F02: exercise the runner's ACTUAL EXIT trap with a fake Docker command.
# No container or network access; verify a failed command exposes diagnostics.
set -euo pipefail

runner=".github/scripts/test-wordpress-apache-http.sh"
cleanup_source="$(sed -n '/^cleanup() {/,/^}/p' "$runner")"
if [[ -z "$cleanup_source" ]]; then
  echo "FAIL: could not extract CI runner cleanup function" >&2
  exit 1
fi
# The function is loaded from the real script, not rewritten in the test.
source <(printf '%s\n' "$cleanup_source")
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT

fixture_root="$scratch/fixture"
mkdir -p "$fixture_root"
container="disposable-fixture-only"
started=1
q01_proxy_pid=0
q01_wpdb_proxy_pid=0
docker() {
  if [[ "$1" == "logs" ]]; then
    printf 'DOCKER_LOGS_ON_FAILURE\n' > "$scratch/docker-logs"
  fi
  return 0
}

set +e
(
  set -e
  trap cleanup EXIT
  false
)
failure_status=$?
set -e
if [[ $failure_status -ne 1 || ! -f "$scratch/docker-logs" ]]; then
  echo "FAIL: EXIT trap swallowed status or did not emit Docker diagnostics" >&2
  exit 1
fi

rm "$scratch/docker-logs"
mkdir -p "$fixture_root"
(
  set -e
  trap cleanup EXIT
  true
)
if [[ -f "$scratch/docker-logs" ]]; then
  echo "FAIL: successful CI cleanup emitted failure-only Docker diagnostics" >&2
  exit 1
fi
echo "PASS: F02 CI cleanup preserves failing status and emits Docker logs only on failure"
