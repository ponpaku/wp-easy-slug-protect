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
cleanup() {
    rc=$?
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
curl -fsSL --retry 2 https://wordpress.org/wordpress-7.1.3.tar.gz |
    tar -xz -C "$root"
curl -fsSL --retry 2 \
    https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    -o "$root/wp-cli.phar"
mkdir -p "$root/release"
curl -fsSL --retry 2 \
    https://github.com/ponpaku/wp-easy-slug-protect/releases/download/v0.7.41/easy-slug-protect-0.7.41.zip \
    -o "$root/release/easy-slug-protect-0.7.41.zip"
curl -fsSL --retry 2 \
    https://github.com/ponpaku/wp-easy-slug-protect/releases/download/v0.7.41/easy-slug-protect-0.7.41.zip.sha256 \
    -o "$root/release/easy-slug-protect-0.7.41.zip.sha256"
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
    start="$(date +%s%3N)"
    "$@"
    end="$(date +%s%3N)"
    printf '%s,%s\n' "$name" "$(( end-start ))" | tee -a "$report_root/timings.csv"
}
printf 'stage,elapsed_ms\n' >"$report_root/timings.csv"

wp config create --dbname=esp_fg_lifecycle --dbuser=root --dbpass= \
    --dbhost=127.0.0.1 --skip-check
wp core install --url=http://127.0.0.1:8096 --title=ESP-Gate-Life \
    --admin_user=fg-ci --admin_password=disposable-CI-only \
    --admin_email=fg-ci@example.test --skip-email
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
sudo chown -R 33:33 "$ESP_WP_ROOT/wp-content/esp-fast-gate-v2"
docker build --progress=plain -f tests/fixtures/fast-gate-apache.Dockerfile \
    -t esp-fast-gate-apache-ci . 2>&1 | tee "$report_root/apache-build.log"
docker run --detach --rm --name "$container" \
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
[[ "$code" == 200 ]] || { echo "Fast Gate preflight failed" >&2; exit 1; }
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
echo 'PASS: real v0.7.41 to PR, staged index/ledger, CLI/manual/Cron/crash/WAL, and Apache HTTP performance'
