#!/usr/bin/env bash
# Disposable WordPress/Apache integration runner for issue #31.
set -euo pipefail

if [[ "$ESP_ALLOW_DISPOSABLE_DB" != 1 || "$CI" != true ]]; then
  echo "Refusing to run outside explicitly disposable CI" >&2
  exit 1
fi

# Run the EXIT-trap regression before starting disposable Docker/WordPress.
bash tests/test-wordpress-apache-cleanup.sh

# Preserve audit/performance evidence as CI artifacts after disposable cleanup.
report_root="$RUNNER_TEMP/esp-media-index-e2e"
mkdir -p "$report_root"
fixture_root="$(mktemp -d "$RUNNER_TEMP/esp31-apache-http.XXXXXXXX")"
container="esp31-http-$GITHUB_RUN_ID-$$"
started=0
q01_proxy_pid=0
q01_wpdb_proxy_pid=0
cleanup() {
  # Capture the failing command's status BEFORE any proxy teardown changes $?.
  local result=$?
  if [[ $q01_wpdb_proxy_pid -gt 0 ]]; then
    kill "$q01_wpdb_proxy_pid" >/dev/null 2>&1 || true
    wait "$q01_wpdb_proxy_pid" >/dev/null 2>&1 || true
  fi
  if [[ $q01_proxy_pid -gt 0 ]]; then
    kill "$q01_proxy_pid" >/dev/null 2>&1 || true
    wait "$q01_proxy_pid" >/dev/null 2>&1 || true
  fi
  if [[ $result -ne 0 && $started -eq 1 ]]; then
    docker logs "$container" >&2 || true
  fi
  if [[ $started -eq 1 ]]; then
    # WordPress/Apache may create www-data-owned uploads in the bind mount.
    # Restore the host runner's ownership before deleting the disposable tree.
    docker exec "$container" chown -R "$(id -u):$(id -g)" /var/www/html >/dev/null 2>&1 || true
    docker rm -f "$container" >/dev/null 2>&1 || true
  fi
  rm -rf "$fixture_root"
}
trap cleanup EXIT

# Match the pinned WordPress core version used by the existing update CI.
curl --fail --location --retry 2 --silent --show-error \
  https://wordpress.org/wordpress-7.1.3.tar.gz |
  tar -xz -C "$fixture_root"
curl --fail --location --retry 2 --silent --show-error \
  https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
  -o "$fixture_root/wp-cli.phar"

# Install the PR source into a disposable WordPress tree.
plugin_dir="$fixture_root/wordpress/wp-content/plugins/esp44-custom"
mkdir -p "$plugin_dir"
tar --exclude='./.git' --exclude='./dist' -cf - . | tar -xf - -C "$plugin_dir"
docker build --quiet -f tests/fixtures/apache.Dockerfile -t esp31-apache-http-ci .
docker run --detach --rm --name "$container" \
  --add-host=host.docker.internal:host-gateway \
  --publish 127.0.0.1:8094:80 \
  --mount "type=bind,src=$fixture_root/wordpress,dst=/var/www/html" \
  --mount "type=bind,src=$fixture_root/wp-cli.phar,dst=/opt/wp-cli.phar,readonly" \
  esp31-apache-http-ci >/dev/null
started=1

# CLI and Apache access the same disposable MySQL service.
docker exec "$container" php /opt/wp-cli.phar --allow-root --path=/var/www/html \
  config create --dbname=esp_wp31 --dbuser=root --dbpass= \
  --dbhost=host.docker.internal --skip-check
docker exec "$container" php /opt/wp-cli.phar --allow-root --path=/var/www/html \
  core install --url=http://localhost:8094 --title=ESP31 \
  --admin_user=esp-test --admin_password=disposable-ci-test-only \
  --admin_email=esp@example.test --skip-email
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-http-prepare.php
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-pdf-unique-fixture.php

docker exec "$container" test -r /var/www/html/wp-content/uploads/esp31-protected.jpg
docker exec "$container" grep -q '# BEGIN ESP Media Protection' \
  /var/www/html/wp-content/uploads/.htaccess

# Real Apache requests including Rewrite, WordPress auth, headers and bytes.
ESP42_PDF_MANIFEST="$fixture_root/wordpress/wp-content/uploads/.esp42-pdf-fixture.json" \
  python3 tests/wordpress-http-check.py

# Test index bootstrap, lock contention, AJAX capabilities/nonce, and WP-CLI.
for phase in bootstrap cron-lock; do
  docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
    ESP_INDEX_DRIVER_PHASE="$phase" \
    php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-drivers-ci.php
done
denied="$(docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
    ESP_INDEX_DRIVER_PHASE=ajax-denied \
    php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-drivers-ci.php)"
printf '%s' "$denied" | grep -q '"success":false'
printf '%s' "$denied" | grep -q '"Forbidden"'
bad_nonce="$(docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
    ESP_INDEX_DRIVER_PHASE=ajax-bad-nonce \
    php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-drivers-ci.php)"
[[ "$bad_nonce" == "-1" ]]
allowed="$(docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
    ESP_INDEX_DRIVER_PHASE=ajax-allowed \
    php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-drivers-ci.php)"
printf '%s' "$allowed" | python3 -c 'import json,sys; a=json.load(sys.stdin); assert a["success"] is True and a["data"]["installed"] is True and a["data"]["enabled"] is False, a'
echo "PASS: real registered admin AJAX callback, capability, nonce and bounded slice"

# Restart via a different PHP/WordPress entrypoint: CLI sees persisted progress.
docker exec "$container" php /opt/wp-cli.phar --allow-root --path=/var/www/html \
    esp media-index status | python3 -c 'import json,sys; x=json.load(sys.stdin); assert x["installed"] is True and x["enabled"] is False, x'
docker exec "$container" php /opt/wp-cli.phar --allow-root --path=/var/www/html \
    esp media-index build --max-seconds=15
docker exec "$container" php /opt/wp-cli.phar --allow-root --path=/var/www/html \
    esp media-index status | python3 -c 'import json,sys; x=json.load(sys.stdin); assert x["installed"] is True and x["ready"] is True and x["enabled"] is False, x'
echo "PASS: real WP-CLI status/build resumes AJAX work and preserves strict OFF"

# Simulate an unsupported legacy server version while executing against the
# real disposable MySQL service. No old, unsupported daemon runs in CI.
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_INDEX_DRIVER_PHASE=db-unsupported \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-drivers-ci.php
unsupported_ajax="$(docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_INDEX_DRIVER_PHASE=ajax-unsupported \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-drivers-ci.php)"
printf '%s' "$unsupported_ajax" | python3 -c 'import json,sys; x=json.load(sys.stdin); assert x["success"] is False and "Unsupported DB" in x["data"]["message"], x'
echo "PASS: unsupported old DB rejects media-index AJAX with explicit minimum version"

# Real HTTP admin workflow (actual wp-login cookie, settings form HTML, nonce,
# capability checks, and admin-ajax endpoint), not just direct PHP callbacks.
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_UI_PHASE=seed \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-ui-fixture.php
python3 tests/wordpress-media-index-ui-http.py | tee "$report_root/admin-http.log"
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_UI_PHASE=verify \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-ui-fixture.php

# F02: global READY is not "all owners READY". Prepare an eligible dirty
# attachment in a fresh worker; verify real settings-page button and repair
# it via the same authenticated WordPress AJAX action as normal admin work.
for phase in repair-seed repair-check; do
  docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
    ESP_INDEX_DRIVER_PHASE="$phase" \
    php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-drivers-ci.php
done
repaired="$(docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_INDEX_DRIVER_PHASE=ajax-allowed \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-drivers-ci.php)"
printf '%s' "$repaired" | python3 -c '
import json,sys
x=json.load(sys.stdin)
assert x["success"] is True, x
assert x["data"]["ready"] is True, x
assert x["data"]["repairable_dirty"] is False, x
# Display-only dirty counts are cached for 15s. The authoritative repairable
# flag must already be false even when the approximate count is stale.
'
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_INDEX_DRIVER_PHASE=repair-verify \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-drivers-ci.php
echo "PASS: F02 admin manual repair after global READY with backoff/pending safety"

# F01: real WP-CLI must repair >200 eligible owners across multiple
# run_batch() slices, then stop with a warning for pending/backoff rows.
cli_fixture="/var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-cli-ci.php"
for phase in seed; do
  docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
    ESP_INDEX_CLI_PHASE="$phase" php "$cli_fixture"
done
cli_repair_output="$(timeout --signal=TERM 120s docker exec "$container" \
  php /opt/wp-cli.phar --allow-root --path=/var/www/html \
  esp media-index build --max-seconds=100 2>&1)"
printf '%s\n' "$cli_repair_output"
printf '%s\n' "$cli_repair_output" | grep -q 'remaining attachments are pending or in retry backoff'
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_INDEX_CLI_PHASE=verify-deferred php "$cli_fixture"
# Running again with only deferred rows must not spin until --max-seconds.
cli_deferred_output="$(timeout --signal=TERM 45s docker exec "$container" \
  php /opt/wp-cli.phar --allow-root --path=/var/www/html \
  esp media-index build --max-seconds=30 2>&1)"
printf '%s\n' "$cli_deferred_output" | grep -q 'remaining attachments are pending or in retry backoff'
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_INDEX_CLI_PHASE=unblock php "$cli_fixture"
cli_complete_output="$(timeout --signal=TERM 45s docker exec "$container" \
  php /opt/wp-cli.phar --allow-root --path=/var/www/html \
  esp media-index build --max-seconds=30 2>&1)"
printf '%s\n' "$cli_complete_output"
printf '%s\n' "$cli_complete_output" | grep -q 'Media index READY and eligible repairs complete'
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_INDEX_CLI_PHASE=verify-complete php "$cli_fixture"
echo 'PASS: F01 real WP-CLI repairs 201 owners across batches and reports deferred rows'

# Q01: deliberately discard the MySQL OK packet after an UPDATE commits.
# The packet relay runs on the disposable GitHub Actions host, not production.
cat > "$fixture_root/q01-mysql-proxy.py" <<'PY'
#!/usr/bin/env python3
import socket
import socketserver
import struct
import sys
import threading

TARGET = b'ESP_Q01_DROP_ACK'
lock = threading.Lock()
dropped = False

def read_exact(sock, count):
    data = b''
    while len(data) < count:
        chunk = sock.recv(count - len(data))
        if not chunk:
            raise EOFError('peer closed')
        data += chunk
    return data

def read_packet(sock):
    h = read_exact(sock, 4)
    return h + read_exact(sock, int.from_bytes(h[:3], 'little'))

class Handler(socketserver.BaseRequestHandler):
    def handle(self):
        global dropped
        server = socket.create_connection(('127.0.0.1', 3306), timeout=10)
        client = self.request
        target = threading.Event()
        finished = threading.Event()
        def client_to_server():
            try:
                while not finished.is_set():
                    p = read_packet(client)
                    if len(p) > 5 and p[4] == 3 and TARGET in p:
                        target.set()
                        print('Q01_PROXY_TARGET_QUERY_SEEN', flush=True)
                    server.sendall(p)
            except (EOFError, OSError):
                pass
            finally:
                finished.set()
        thread = threading.Thread(target=client_to_server, daemon=True)
        thread.start()
        try:
            while not finished.is_set():
                p = read_packet(server)
                if target.is_set() and len(p) > 4 and p[4] == 0:
                    with lock:
                        drop = not dropped
                        if drop:
                            dropped = True
                    if drop:
                        print('Q01_PROXY_DROPPED_UPDATE_OK', flush=True)
                        client.setsockopt(socket.SOL_SOCKET, socket.SO_LINGER, struct.pack('ii', 1, 0))
                        break
                client.sendall(p)
        except (EOFError, OSError):
            pass
        finally:
            finished.set()
            for s in (client, server):
                try:
                    s.close()
                except OSError:
                    pass
            thread.join(timeout=1)

class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

with Server(('0.0.0.0', int(sys.argv[1])), Handler) as srv:
    print('Q01_PROXY_LISTENING', flush=True)
    srv.serve_forever()
PY
python3 -u "$fixture_root/q01-mysql-proxy.py" 33307 > "$fixture_root/q01-proxy.log" 2>&1 &
q01_proxy_pid=$!
python3 -u "$fixture_root/q01-mysql-proxy.py" 33308 > "$fixture_root/q01-wpdb-proxy.log" 2>&1 &
q01_wpdb_proxy_pid=$!
for _ in $(seq 1 50); do
  if grep -q Q01_PROXY_LISTENING "$fixture_root/q01-proxy.log" &&
     grep -q Q01_PROXY_LISTENING "$fixture_root/q01-wpdb-proxy.log"; then break; fi
  sleep 0.1
done
if ! grep -q Q01_PROXY_LISTENING "$fixture_root/q01-proxy.log" ||
   ! grep -q Q01_PROXY_LISTENING "$fixture_root/q01-wpdb-proxy.log"; then
  cat "$fixture_root/q01-proxy.log" >&2
  cat "$fixture_root/q01-wpdb-proxy.log" >&2
  exit 1
fi

# #63: exercise actual WordPress dbDelta/backfill and opt-in strict PHP delivery.
if ! timeout --signal=TERM 90s docker exec "$container" env \
  ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_Q01_PROXY_HOST=host.docker.internal:33307 \
  ESP_Q01_WPDB_PROXY_HOST=host.docker.internal:33308 \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-ci.php; then
  echo 'Q01 WordPress/packet-proxy probe failed or timed out' >&2
  cat "$fixture_root/q01-proxy.log" >&2
  cat "$fixture_root/q01-wpdb-proxy.log" >&2
  exit 1
fi
cat "$fixture_root/q01-proxy.log"
cat "$fixture_root/q01-wpdb-proxy.log"
# The packet-loss experiment is explicitly observational (Issue #67).
# Never mislabel it a passing fix for Q01.
grep -q Q01_PROXY_DROPPED_UPDATE_OK "$fixture_root/q01-proxy.log"
grep -q Q01_PROXY_DROPPED_UPDATE_OK "$fixture_root/q01-wpdb-proxy.log"
assert_http() {
  local path="$1" expected="$2"
  local status
  status="$(curl --silent --show-error --output "$fixture_root/http-probe-body" \
    --write-out '%{http_code}' --max-time 10 "http://127.0.0.1:8094/wp-content/uploads/$path")"
  if [[ "$status" != "$expected" ]]; then
    echo "Issue 63 HTTP probe $path: expected $expected, got $status" >&2
    cat "$fixture_root/http-probe-body" >&2
    exit 1
  fi
}
# Once the existing WordPress strict-mode fixture has been explicitly enabled,
# measure real Apache image GET/HEAD/Range and parallel delivery while making
# byte-level privacy assertions. These measurements do not define SLOs.
python3 tests/wordpress-media-index-real-http-perf.py | tee "$report_root/media-http-perf.log"
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-real-db-perf.php \
  | tee "$report_root/media-db-perf.log"
assert_http esp31-protected-150x150.jpg 302
assert_http esp31-public-150x150.jpg 200
# The new protected owner is visible as a dirty reservation BEFORE Cron runs.
# A previously public image at the same path must no longer return 200.
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_COLLISION_PHASE=prepare \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-collision-ci.php
assert_http esp31-public-150x150.jpg 503
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_COLLISION_PHASE=restore \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-collision-ci.php
assert_http esp31-public-150x150.jpg 200

# by-mid metadata update: sanitize_post_meta_* changes the stored file
# AFTER the pre-hook. Verify the actual sanitized protected owner blocks
# anonymous HTTP before any media-index rebuild.
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_COLLISION_PHASE=by_mid_prepare \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-collision-ci.php
assert_http esp31-public-150x150.jpg 503
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_COLLISION_PHASE=by_mid_restore \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-collision-ci.php
assert_http esp31-public-150x150.jpg 200

# Same by-mid hazard via serialized _wp_attachment_metadata after sanitize.
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_COLLISION_PHASE=by_mid_metadata_prepare \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-collision-ci.php
assert_http esp31-public-150x150.jpg 503
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  ESP_COLLISION_PHASE=by_mid_metadata_restore \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-collision-ci.php
assert_http esp31-public-150x150.jpg 200

assert_http esp63-unregistered.jpg 403

# Issue #43: formal WordPress-owned media scope, with no unregistered-file
# whitelist or automatic strict migration. Only the disposable CI DB changes.
scope_fixture="/var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-scope-policy-ci.php"
scope_phase() {
  docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
    ESP_SCOPE_PHASE="$1" php "$scope_fixture"
}
scope_phase compat
assert_http esp63-unregistered.jpg 200
grep -q ESP63_UNREGISTERED_BUT_REAL_FILE "$fixture_root/http-probe-body"
assert_http esp31-protected-150x150.jpg 302
scope_phase strict
assert_http esp63-unregistered.jpg 403
! grep -q ESP63_UNREGISTERED_BUT_REAL_FILE "$fixture_root/http-probe-body"
assert_http esp31-protected-150x150.jpg 302
# #43 R01: confirm WordPress's existing upload_files privileged bypass
# without an ESP password, contrasting a logged-in Subscriber and anonymous.
# These disposable test users do not exist outside this temporary WordPress.
docker exec "$container" php /opt/wp-cli.phar --allow-root --path=/var/www/html \
  user create esp-ci-author esp-ci-author@example.test --role=author \
  --user_pass=esp-ci-author-only --porcelain >/dev/null
docker exec "$container" php /opt/wp-cli.phar --allow-root --path=/var/www/html \
  user create esp-ci-subscriber esp-ci-subscriber@example.test --role=subscriber \
  --user_pass=esp-ci-subscriber-only --porcelain >/dev/null
python3 tests/wordpress-media-role-http.py | tee "$report_root/media-role-http.log"
# Missing index row does not prove a WordPress derivative is public.
scope_phase index-gap
assert_http esp31-protected-150x150.jpg 403
! grep -q ESP42_PROTECTED_THUMB "$fixture_root/http-probe-body"
scope_phase restore
assert_http esp31-protected-150x150.jpg 302
# Confirm the independent dirty authorization path also fails closed.
scope_phase dirty
assert_http esp31-protected-150x150.jpg 503
! grep -q ESP42_PROTECTED_THUMB "$fixture_root/http-probe-body"
scope_phase restore
assert_http esp31-protected-150x150.jpg 302
echo 'PASS: #43 WordPress-owned scope: compatible outside-scope access, strict 403/503 and repair'

# F01: two independent WordPress/PHP/MySQL workers; B waits after its pre-hook
# while A triggers two real updated_post_meta hooks for a single Core mutation.
f01_php="/var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-f01-ci.php"
f01_phase() {
  docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
    ESP_F01_PHASE="$1" php "$f01_php"
}
f01_phase seed
f01_phase hold-b >"$report_root/f01-worker-b.log" 2>&1 &
f01_b_pid=$!
f01_signal="$fixture_root/wordpress/wp-content/uploads/.esp-f01-ci"
f01_ready=0
for attempt in $(seq 1 120); do
  if [[ -f "$f01_signal/b-prehook-ready" ]]; then f01_ready=1; break; fi
  if ! kill -0 "$f01_b_pid" 2>/dev/null; then break; fi
  sleep 0.2
done
if (( f01_ready != 1 )); then
  echo 'F01 independent PHP worker B failed to reach post-prehook barrier' >&2
  cat "$report_root/f01-worker-b.log" >&2
  exit 1
fi
f01_phase update-a | tee "$report_root/f01-worker-a.log"
assert_http esp31-protected.jpg 503
! grep -q ESP31_PROTECTED_JPEG "$fixture_root/http-probe-body"
docker exec "$container" touch /var/www/html/wp-content/uploads/.esp-f01-ci/b-resume
wait "$f01_b_pid"
cat "$report_root/f01-worker-b.log"
f01_phase restore | tee "$report_root/f01-recovery.log"
assert_http esp31-protected.jpg 302
echo 'PASS: F01 two real WordPress workers, duplicate post-hooks, pending isolation and protected HTTP recovery'

# R01: a normal post already carrying protection metadata is promoted to an
# attachment via WordPress Core. A second PHP/MySQL worker and real HTTP probe
# check the pre-write and post-Cron ownership fences.
type_fixture="/var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-post-type-ci.php"
type_phase() {
  docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
    ESP_TYPE_PHASE="$1" php "$type_fixture"
}
type_phase seed | tee "$report_root/post-type-seed.log"
assert_http esp31-public-150x150.jpg 200
type_phase convert >"$report_root/post-type-worker.log" 2>&1 &
type_pid=$!
type_signal="$fixture_root/wordpress/wp-content/uploads/.esp-type-transition-ci"
type_ready=0
for attempt in $(seq 1 120); do
  if [[ -f "$type_signal/prehook" ]]; then type_ready=1; break; fi
  if ! kill -0 "$type_pid" 2>/dev/null; then break; fi
  sleep 0.2
done
if (( type_ready != 1 )); then
  echo 'R01 post-type conversion failed to reach pre-write barrier' >&2
  cat "$report_root/post-type-worker.log" >&2
  exit 1
fi
type_phase during | tee "$report_root/post-type-during.log"
assert_http esp31-public-150x150.jpg 503
! grep -q ESP31_PUBLIC "$fixture_root/http-probe-body"
docker exec "$container" touch /var/www/html/wp-content/uploads/.esp-type-transition-ci/resume
wait "$type_pid"
cat "$report_root/post-type-worker.log"
type_phase after | tee "$report_root/post-type-after.log"
assert_http esp31-public-150x150.jpg 403
! grep -q ESP31_PUBLIC "$fixture_root/http-probe-body"
echo 'PASS: R01 post->attachment change fenced before Core update, during Cron and after collision repair'

echo 'PASS: issue 63 real WordPress/Apache strict index HTTP smoke suite'
echo 'PASS: disposable Apache/WordPress media HTTP regression suite'
