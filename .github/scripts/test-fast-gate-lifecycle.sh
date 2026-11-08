#!/usr/bin/env bash
# Real published Stable -> PR ZIP WordPress lifecycle & disposable Apache benchmark.
set -euo pipefail
if [[ "${CI:-}" != "true" || "${ESP_ALLOW_DISPOSABLE_DB:-}" != "1" ]]; then
    echo "Refusing non-disposable Fast Gate lifecycle" >&2
    exit 1
fi
report_root="${RUNNER_TEMP:-/tmp}/esp-fast-gate-lifecycle"
mkdir -p "$report_root"
root="$(mktemp -d "${RUNNER_TEMP:-/tmp}/esp-fg-life.XXXXXXXX")"
container="esp-fg-bench-${GITHUB_RUN_ID:-local}-$$"
started=0
race_pid=""
cleanup() {
    rc=$?
    if [[ -n "$race_pid" ]]; then
        kill "$race_pid" >/dev/null 2>&1 || true
        wait "$race_pid" >/dev/null 2>&1 || true
    fi
    if [[ $started -eq 1 ]]; then
        docker logs "$container" >"$report_root/apache.log" 2>&1 || true
        docker rm -f "$container" >/dev/null 2>&1 || true
    fi
    # Apache workers use www-data, but the checkout/fixture belongs to
    # the GitHub runner. Restore ownership after the isolated HTTP test.
    if [[ -d "$root" ]]; then
        sudo chown -R "$(id -u):$(id -g)" "$root" >/dev/null 2>&1 || true
    fi
    rm -rf "$root"
    return "$rc"
}
trap cleanup EXIT
# WordPress release CDN sometimes returns intermittent 504 on Actions.
# Fall back to its separately hosted tagged source archive, and assert the
# exact WordPress core version after extraction (never use "latest").
echo "FG_DOWNLOAD WordPress core exact release 7.1.3"
wordpress_archive="$root/wp-7.1.3.tar.gz"
if curl --fail --silent --show-error --location --retry 3 --retry-all-errors \
    --retry-delay 2 https://wordpress.org/wordpress-7.1.3.tar.gz \
    -o "$wordpress_archive"; then
    tar -xz -C "$root" -f "$wordpress_archive"
else
    echo "WordPress.org download unavailable; trying tagged GitHub source" >&2
    curl --fail --silent --show-error --location --retry 3 --retry-all-errors \
      --retry-delay 2 \
      https://github.com/WordPress/WordPress/archive/refs/tags/7.1.3.tar.gz \
      -o "$wordpress_archive"
    tar -xz -C "$root" -f "$wordpress_archive"
    mv "$root/WordPress-7.1.3" "$root/wordpress"
fi
grep -Eq "wp_version = '7\\.1\\.3';" "$root/wordpress/wp-includes/version.php" || {
    echo "Downloaded WordPress source has an unexpected version" >&2
    exit 1
}
echo "FG_DOWNLOAD WP-CLI PHAR"
curl -fsSL --retry 6 --retry-all-errors --retry-delay 2 \
    https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    -o "$root/wp-cli.phar"
echo "FG_DOWNLOAD WP-CLI downloaded"
mkdir -p "$root/release"
echo "FG_DOWNLOAD published v0.7.41 ZIP"
curl -fsSL --retry 6 --retry-all-errors --retry-delay 2 \
    https://github.com/ponpaku/wp-easy-slug-protect/releases/download/v0.7.41/easy-slug-protect-0.7.41.zip \
    -o "$root/release/easy-slug-protect-0.7.41.zip"
echo "FG_DOWNLOAD release ZIP downloaded"
echo "FG_DOWNLOAD published SHA256 manifest"
curl -fsSL --retry 6 --retry-all-errors --retry-delay 2 \
    https://github.com/ponpaku/wp-easy-slug-protect/releases/download/v0.7.41/easy-slug-protect-0.7.41.zip.sha256 \
    -o "$root/release/easy-slug-protect-0.7.41.zip.sha256"
echo "FG_DOWNLOAD SHA256 manifest downloaded"
(cd "$root/release"; sha256sum -c easy-slug-protect-0.7.41.zip.sha256)
echo 'FG_LIFECYCLE Verified genuine published v0.7.41 ZIP digest' | tee -a "$report_root/stages.log"

export ESP_WP_ROOT="$root/wordpress"
driver="$PWD/tests/wordpress-fast-gate-lifecycle-ci.php"
wp() { php "$root/wp-cli.phar" --allow-root --path="$ESP_WP_ROOT" "$@"; }
stage() {
    local phase="$1"
    ESP_FG_PHASE="$phase" php "$driver" | tee -a "$report_root/stages.log"
}
measure() {
    local name="$1"; shift
    local start end
    # Wall-clock time can step backward on shared CI runners.
    # A monotonic counter across processes avoids negative elapsed times.
    start="$(python3 -c 'import time; print(time.monotonic_ns()//1000000)')"
    "$@"
    end="$(python3 -c 'import time; print(time.monotonic_ns()//1000000)')"
    local elapsed="$(( end-start ))"
    if (( elapsed < 0 )); then
        echo "Invalid negative monotonic elapsed time for $name" >&2
        return 1
    fi
    printf '%s,%s\n' "$name" "$elapsed" | tee -a "$report_root/timings.csv"
}
printf 'stage,elapsed_ms\n' >"$report_root/timings.csv"

wp config create --dbname=esp_fg_lifecycle --dbuser=root --dbpass= \
    --dbhost=127.0.0.1 --skip-check
wp core install --url=http://127.0.0.1:8096 --title=ESP-Gate-Life \
    --admin_user=fg-ci --admin_password=disposable-CI-only \
    --admin_email=fg-ci@example.test --skip-email
# Stable ZIP is installed in a temporary host subdirectory named "wordpress",
# whereas Apache serves it as /. Make the actual uploads URL explicit BEFORE
# keys.php captures its immutable URL prefix.
wp option update home http://127.0.0.1:8096 >/dev/null
wp option update siteurl http://127.0.0.1:8096 >/dev/null
wp option update upload_url_path http://127.0.0.1:8096/wp-content/uploads >/dev/null
[[ "$(wp option get upload_url_path)" == "http://127.0.0.1:8096/wp-content/uploads" ]] ||
    { echo "Disposable WordPress uploads URL did not persist" >&2; exit 1; }
measure release_zip_install wp plugin install \
    "$root/release/easy-slug-protect-0.7.41.zip" --activate
measure release_fixture_create stage seed-release

# The PR still has the same 0.7.41 WordPress version header. Therefore this
# is a REAL WP-CLI/WordPress ZIP code replacement with --force, not a version
# number migration. The existing WP upgrader tests separately cover upgrades.
python3 tools/build-release.py --tag v0.7.41 >"$report_root/pr-zip.log"
pr_zip="$PWD/dist/easy-slug-protect-0.7.41.zip"
measure real_wordpress_zip_upgrade wp plugin install "$pr_zip" --force
wp plugin is-active easy-slug-protect
[[ -f "$ESP_WP_ROOT/wp-content/plugins/easy-slug-protect/gate/fast-gate.php" &&
   -f "$ESP_WP_ROOT/wp-content/plugins/easy-slug-protect/gate/class-esp-fast-gate-server.php" ]] ||
   { echo "Updated ZIP omitted standalone Fast Gate runtime files" >&2; exit 1; }
measure release_upgrade_integrity stage after-upgrade
wp option get esp_fast_gate_v2_active >/dev/null 2>&1 &&
    { echo "active option unexpected after upgrade" >&2; exit 1; } || true

# Do not equate an initial 200-row Cron pass with full coverage.
wp cron event list --field=hook | grep -Fxq esp_media_index_v1_batch
measure media_index_first_cron wp cron event run esp_media_index_v1_batch
stage index-status
measure media_index_resume_cli wp esp media-index build --max-seconds=120
stage index-status
wp option update esp_media_index_v1_enabled 1
measure gate_provision_cli wp esp gate-ledger provision
wp esp gate-ledger status | tee -a "$report_root/stages.log"
measure gate_build_cli wp esp gate-ledger build
stage catalog-status
measure gate_build_manual_api stage manual-build

# Interrupt the actual catalog builder between READY=0 and reading the
# canonical MySQL file list. The process exits, then a new CLI worker resumes.
set +e
ESP_FG_PHASE=interrupt-build php "$driver" \
    >"$report_root/intentional-build-crash.log" 2>&1
build_rc=$?
set -e
[[ "$build_rc" -eq 78 ]] || { cat "$report_root/intentional-build-crash.log"; exit 1; }
stage catalog-status | grep -q '"ready":false'
measure gate_build_resume_cli wp esp gate-ledger build
stage catalog-status | grep -q '"ready":true'

# Simulated abrupt process death during an uncommitted native SQLite WAL tx.
set +e
ESP_FG_PHASE=sqlite-crash php "$driver" \
    >"$report_root/intentional-sqlite-crash.log" 2>&1
sqlite_rc=$?
set -e
[[ "$sqlite_rc" -eq 79 ]] || { cat "$report_root/intentional-sqlite-crash.log"; exit 1; }
stage sqlite-check

stage cron-seed
# The production active flag cannot be enabled yet. A MU plugin is injected
# ONLY into this disposable fixture and ONLY for the cron process that uses
# ESP_FG_CI_FORCE_GATE=1. No persistent option is changed.
mkdir -p "$ESP_WP_ROOT/wp-content/mu-plugins"
cat > "$ESP_WP_ROOT/wp-content/mu-plugins/fg-disposable-cron.php" <<'PHP'
<?php
if (getenv('ESP_FG_CI_FORCE_GATE') === '1') {
    add_filter('pre_option_esp_fast_gate_v2_active', static function() { return '1'; });
}
PHP
measure gate_cron_repair_gc env ESP_FG_CI_FORCE_GATE=1 \
    php "$root/wp-cli.phar" --allow-root --path="$ESP_WP_ROOT" \
    cron event run esp_media_index_v1_batch
stage cron-check

# R01: use separate real PHP/WordPress connections with a deterministic
# rendezvous. B holds the OLD public MySQL snapshot while A commits a real
# protected metadata/index update, then B must fail its shared-path CAS.
# No new production hooks or full-site locks are introduced.
race_dir="$root/fg-r01-rendezvous"
mkdir -p "$race_dir"
export ESP_FG_RACE_SYNC="$race_dir"
export ESP_FG_CI_RACE=1
race_driver="$PWD/tests/wordpress-fast-gate-cross-owner-race-ci.php"
env ESP_FG_RACE_PHASE=setup php "$race_driver" \
    | tee -a "$report_root/stages.log"
env ESP_FG_RACE_PHASE=stale-b php "$race_driver" \
    >"$report_root/r01-stale-b.log" 2>&1 &
race_pid=$!
for i in $(seq 1 400); do
    [[ -f "$race_dir/b-ready" ]] && break
    if ! kill -0 "$race_pid" >/dev/null 2>&1; then
        cat "$report_root/r01-stale-b.log"
        echo "B worker terminated before the R01 barrier" >&2
        exit 1
    fi
    sleep .05
done
if [[ ! -f "$race_dir/b-ready" ]]; then
    cat "$report_root/r01-stale-b.log"
    echo "R01 B worker did not reach the deterministic barrier" >&2
    exit 1
fi
env ESP_FG_RACE_PHASE=protect-a php "$race_driver" \
    | tee -a "$report_root/stages.log"
touch "$race_dir/a-done"
wait "$race_pid"
race_pid=""
cat "$report_root/r01-stale-b.log" | tee -a "$report_root/stages.log"
env ESP_FG_RACE_PHASE=verify php "$race_driver" \
    | tee -a "$report_root/stages.log"
unset ESP_FG_RACE_SYNC ESP_FG_CI_RACE
stage catalog-status

# Confirm recurring Cron registration survives execution and the standalone
# WP-CLI GC operates on the same completed ledger without dropping any
# unconfirmed revocation.
wp cron event list --field=hook | grep -Fxq esp_media_index_v1_batch
measure gate_gc_cli wp esp gate-ledger gc
# Ordinary WordPress still cannot have an active Fast Gate option.
stage catalog-status

# The test-only temporary serving state is not production activation.
cookie_file="$root/private-cookie-header"
measure http_test_prepare env ESP_FG_COOKIE_FILE="$cookie_file" \
    ESP_FG_PHASE=http-prepare php "$driver"
chmod 0600 "$cookie_file"

# Boot an isolated Apache with actual PHP 8.3, mysqli, SQLite and Rewrite.
# WordPress CLI runs on the host; Apache connects to the same disposable
# MySQL service via host-gateway. Set DB_HOST after all CLI checks.
wp option update home http://127.0.0.1:8096
wp option update siteurl http://127.0.0.1:8096
uploads="$ESP_WP_ROOT/wp-content/uploads"
[[ -f "$uploads/.htaccess" ]] || {
    echo 'Released PHP media rewrite missing from uploads/.htaccess' >&2; exit 1;
}
cp "$uploads/.htaccess" "$root/legacy-uploads.htaccess"
cat > "$ESP_WP_ROOT/.htaccess" <<'HTACCESS'
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
HTACCESS
wp config set DB_HOST host.docker.internal --type=constant
# Add a disposable, environment-gated HTTP driver to the REAL WordPress
# fixture, not to the distributable plugin ZIP.
mkdir -p "$ESP_WP_ROOT/wp-content/mu-plugins"
cp tests/fixtures/fast-gate-http-auth-mu.php \
    "$ESP_WP_ROOT/wp-content/mu-plugins/fg-disposable-http-auth.php"
sudo chown -R 33:33 "$ESP_WP_ROOT/wp-content/esp-fast-gate-v2"
docker build --progress=plain -f tests/fixtures/fast-gate-apache.Dockerfile \
    -t esp-fast-gate-apache-ci . 2>&1 | tee "$report_root/apache-build.log"
docker run --detach --rm --name "$container" \
    --env ESP_FG_CI_HTTP_AUTH=1 \
    --add-host=host.docker.internal:host-gateway \
    --publish 127.0.0.1:8096:80 \
    --mount "type=bind,src=$ESP_WP_ROOT,dst=/var/www/html" \
    --mount "type=bind,src=$ESP_WP_ROOT,dst=$ESP_WP_ROOT" \
    esp-fast-gate-apache-ci >/dev/null
started=1
for i in $(seq 1 80); do
    if curl --silent --output /dev/null http://127.0.0.1:8096/; then break; fi
    sleep .2
done
# Preflight real Apache, MySQL, and WordPress routing before timing.
docker exec "$container" php -r '
$m=@new mysqli("host.docker.internal","root","","esp_fg_lifecycle",3306);
if ($m->connect_errno) {
    fwrite(STDERR,"FG_HTTP mysql unreachable: ".$m->connect_error."\n");exit(1);
}
echo "FG_HTTP mysql connection OK\n";
'
docker exec "$container" php -r '
$_SERVER["SERVER_SOFTWARE"]="Apache/2.4";
require "/var/www/html/wp-load.php";
$uploads=wp_upload_dir();
$r=ESP_Media_Index::resolve($uploads["basedir"]."/fg-life-public.jpg");
echo "FG_HTTP WordPress media index: ".json_encode($r)."\n";
echo "FG_HTTP runtime: ".json_encode(get_option("esp_runtime_state"))."\n";
'
curl --silent --show-error --dump-header "$report_root/legacy-preflight-headers.txt" \
    --output "$report_root/legacy-preflight-body.txt" \
    http://127.0.0.1:8096/wp-content/uploads/fg-life-public.jpg
echo "FG_HTTP legacy public response headers:"
head -n 18 "$report_root/legacy-preflight-headers.txt"
echo "FG_HTTP legacy public response body digest:"
sha256sum "$report_root/legacy-preflight-body.txt"

# Baseline: the released PHP media-protection Rewrite (not a fake handler).
python3 tests/fast-gate-http-benchmark.py --mode legacy \
    --cookie-file "$cookie_file" --output "$report_root/legacy-http.json" \
    | tee -a "$report_root/stages.log"

# Rewrite rules in Apache's main server config are not inherited by
# VirtualHosts. Install a disposable test-only vhost so routing executes
# BEFORE per-directory uploads/.htaccess.
docker exec -i "$container" tee /etc/apache2/sites-enabled/000-default.conf \
    >"$report_root/apache-routing.conf" <<'APACHE'
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /var/www/html
    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
    LogLevel warn rewrite:trace2
    RewriteEngine On
    RewriteRule ^/wp-content/uploads/(.+)$ /wp-content/plugins/easy-slug-protect/gate/fast-gate.php [PT,END]
</VirtualHost>
APACHE
docker exec "$container" apache2ctl configtest
docker exec "$container" apache2ctl graceful
sleep 1
# Verify the route is really different before any benchmark is accepted.
code="$(curl -sS -o "$report_root/fast-preflight-body" \
    -D "$report_root/fast-preflight-headers" -w '%{http_code}' \
    -H "Cookie: $(cat "$cookie_file")" \
    http://127.0.0.1:8096/wp-content/uploads/fg-life-protected.jpg)"
echo "FG_HTTP fast preflight HTTP=$code"
head -n 14 "$report_root/fast-preflight-headers"
if [[ "$code" != 200 ]]; then
    echo "Fast Gate preflight rejected the test credential: $code" >&2
    # The PHP/SQLite CLI check uses *known disposable credentials*, never
    # outputs secrets. It distinguishes ledger/token state from HTTP routing.
    docker exec "$container" php -r '
    $dir="/var/www/html/wp-content/plugins/easy-slug-protect";
    require $dir."/includes/class-esp-fast-gate-token.php";
    require $dir."/includes/class-esp-fast-gate-store.php";
    require $dir."/gate/class-esp-fast-gate-server.php";
    $private="/var/www/html/wp-content/esp-fast-gate-v2";
    $keys=require $private."/keys.php";
    $db=ESP_Fast_Gate_Store::open($private."/gate-ledger.sqlite",false);
    $token=str_repeat("x",64);
    $ticket=ESP_Fast_Gate_Token::issue($keys["site"],"fg_path",
        "session","",$token,1,time()+86400,$keys["sig"],$keys["id"]);
    $cookies=["esp_auth_fg_path"=>$token,"esp_gate_fg_path"=>$ticket];
    $verify=ESP_Fast_Gate_Token::verify($ticket,$keys["site"],
        "fg_path",$cookies,1,$keys["sig"],$keys["id"]);
    $decision=ESP_Fast_Gate_Server::authorize(
        "/wp-content/uploads/fg-life-protected.jpg",$cookies,
        $keys["site"],$keys["root"],$keys["prefix"],
        $keys["sig"],$keys["id"],$db);
    echo "FG_DEBUG_CLI ".json_encode([
        "serving"=>$db->serving(),
        "protected_media"=>$db->get_media("fg-life-protected.jpg"),
        "path"=>$db->get_path("fg_path"),
        "token_valid"=>$verify!==null,
        "auth_status"=>$decision["status"],
        "url_prefix"=>$keys["prefix"]])."\n";
    '
    # Temporarily substitute a diagnostic script inside ONLY the disposable
    # fixture to observe PHP's post-Rewrite URI/cookie NAMES (no secret data).
    docker exec "$container" cp \
        /var/www/html/wp-content/plugins/easy-slug-protect/gate/fast-gate.php \
        /tmp/esp-fg-original-php
    docker exec -i "$container" tee \
        /var/www/html/wp-content/plugins/easy-slug-protect/gate/fast-gate.php \
        >/dev/null <<'DIAG'
<?php
header('Content-Type: application/json');
echo json_encode([
    'request_uri'=>$_SERVER['REQUEST_URI']??null,
    'redirect_url'=>$_SERVER['REDIRECT_URL']??null,
    'script_name'=>$_SERVER['SCRIPT_NAME']??null,
    'cookie_names'=>array_keys($_COOKIE),
    'remote_addr'=>$_SERVER['REMOTE_ADDR']??null,
]);
DIAG
    curl -sS -H "Cookie: $(cat "$cookie_file")" \
      http://127.0.0.1:8096/wp-content/uploads/fg-life-protected.jpg
    echo
    docker exec "$container" cp /tmp/esp-fg-original-php \
        /var/www/html/wp-content/plugins/easy-slug-protect/gate/fast-gate.php
    exit 1
fi
grep -qi '^cache-control:.*no-store' "$report_root/fast-preflight-headers" ||
    { echo "Standalone cache header absent; Rewrite bypassed" >&2; exit 1; }
if grep -qi '^content-disposition:' "$report_root/fast-preflight-headers"; then
    echo "Legacy handler still served file; Rewrite bypassed" >&2
    docker exec "$container" apache2ctl -S 2>&1 || true
    docker exec "$container" cat /etc/apache2/sites-enabled/000-default.conf || true
    docker logs --tail 100 "$container" >&2 || true
    exit 1
fi
python3 tests/fast-gate-http-benchmark.py --mode fast \
    --cookie-file "$cookie_file" --output "$report_root/fast-http.json" \
    | tee -a "$report_root/stages.log"
# Real WordPress over HTTP issues BOTH cookie kinds, refreshes Remember Me,
# logs out via the existing plugin, and rotates a live path password version.
# Run after the baseline since this mutates the test's MySQL credentials.
python3 tests/fast-gate-http-auth-lifecycle.py \
    --output "$report_root/auth-lifecycle-http.json" \
    | tee -a "$report_root/stages.log"
# Confirm direct HTTP cannot expose the SQLite file in this Apache setup.
code="$(curl -sS -o /dev/null -w '%{http_code}' \
    http://127.0.0.1:8096/wp-content/esp-fast-gate-v2/gate-ledger.sqlite)"
[[ "$code" == 403 ]] || { echo "SQLite exposed over HTTP: status=$code" >&2; exit 1; }
docker exec --user www-data "$container" php -r '
require "/var/www/html/wp-content/plugins/easy-slug-protect/includes/class-esp-fast-gate-store.php";
$db=ESP_Fast_Gate_Store::open("/var/www/html/wp-content/esp-fast-gate-v2/gate-ledger.sqlite",true);
if (!$db || !$db->set_serving(false)) exit(1);
'
code="$(curl -sS -o /dev/null -w '%{http_code}' \
    http://127.0.0.1:8096/wp-content/uploads/fg-life-public.jpg)"
[[ "$code" == 503 ]] || { echo "Disabled gate should fail closed, got $code" >&2; exit 1; }

python3 - "$report_root" <<'PY'
import json, pathlib, sys
r=pathlib.Path(sys.argv[1])
legacy=json.loads((r/'legacy-http.json').read_text())
fast=json.loads((r/'fast-http.json').read_text())
comparison={}
for name in ('fg-life-public.jpg','fg-life-protected.jpg'):
    a,b=legacy['samples'][name],fast['samples'][name]
    assert a['sha256']==b['sha256'] and a['bytes']==b['bytes'], name
    comparison[name]={
        'legacy_median_ms':a['median_ms'],
        'fast_median_ms':b['median_ms'],
        'median_speedup_x':round(a['median_ms']/b['median_ms'],3),
        'legacy_p95_ms':a['p95_ms'],
        'fast_p95_ms':b['p95_ms'],
        'p95_speedup_x':round(a['p95_ms']/b['p95_ms'],3),
    }
result={'environment':'GitHub Actions shared runner, disposable Apache 2.4/PHP 8.3/MySQL 8/SQLite',
        'sample_count':legacy['sample_count'],
        'caveat':'single runner, local loopback, temporary test-only Fast Gate Rewrite, not production routing',
        'comparison':comparison}
(r/'comparison.json').write_text(json.dumps(result,indent=2)+'\n')
print('FG_HTTP_COMPARISON '+json.dumps(result))
PY
# Exercise the REAL product activation controller, not the test-only
# VirtualHost rule used for the independent throughput benchmark above.
# The WordPress install URL is localhost:8096. Give Apache a local listener
# on the same port so wp_remote_get() loopback can probe its installed route.
docker exec "$container" sh -c "printf 'Listen 80\nListen 8096\n' > /etc/apache2/ports.conf"
docker exec -i "$container" tee /etc/apache2/sites-enabled/000-default.conf \
    >/dev/null <<'APACHE'
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /var/www/html
    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
<VirtualHost *:8096>
    ServerName localhost
    DocumentRoot /var/www/html
    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
APACHE
docker exec "$container" apache2ctl configtest
docker exec "$container" apache2ctl graceful
sleep 1
# Real Apache PHP needs write access to the existing ESP-managed
# uploads/.htaccess; the initial WordPress WP-CLI install ran as the runner.
sudo chown -R 33:33 "$uploads"
# Check that no test-only server-level rewrite remains before deployment.
grep -Fq 'RewriteRule ^(.+)$' "$uploads/.htaccess"
! grep -Fq '# Fast Gate: no static-file existence shortcut' "$uploads/.htaccess"
python3 tests/fast-gate-activation-e2e.py \
    | tee -a "$report_root/stages.log"
# After the actual product deactivation, the managed ESP Rewrite must be
# restored and the separate option marker must not remain "fast".
! grep -Fq '# Fast Gate: no static-file existence shortcut' "$uploads/.htaccess"
grep -Fq 'index.php?esp-media=' "$uploads/.htaccess"
echo 'PASS: released upgrade, ledger/Cron/WAL, HTTP benchmark, AND verified product activation/rollback'

