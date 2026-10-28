#!/usr/bin/env bash
# Disposable GitHub Actions-only performance comparison; never a production load test.
set -euo pipefail
if [[ "$CI" != true || "$ESP_ALLOW_DISPOSABLE_DB" != 1 ]]; then
  echo "Must run in explicitly disposable GitHub Actions CI" >&2
  exit 2
fi
results="$RUNNER_TEMP/esp42-benchmark-results"
mkdir -p "$results"
root="$(mktemp -d "$RUNNER_TEMP/esp42-benchmark.XXXXXXXX")"
container="esp42-perf-$GITHUB_RUN_ID-$$"
started=0
cleanup() {
  local status=$?
  if [[ $status -ne 0 && $started -eq 1 ]]; then
    docker logs "$container" >"$results/apache-container.log" 2>&1 || true
  fi
  if [[ $started -eq 1 ]]; then
    docker exec "$container" chown -R "$(id -u):$(id -g)" /var/www/html >/dev/null 2>&1 || true
    docker rm -f "$container" >/dev/null 2>&1 || true
  fi
  rm -rf "$root"
}
trap cleanup EXIT

# WordPress/MySQL versions match the project integration tests.
curl --fail --location --retry 2 --silent --show-error \
  https://wordpress.org/wordpress-7.1.3.tar.gz | tar -xz -C "$root"
curl --fail --location --retry 2 --silent --show-error \
  https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
  -o "$root/wp-cli.phar"

plugins="$root/wordpress/wp-content/plugins"
mkdir -p "$plugins/esp44-custom" "$plugins/esp42-main"
tar --exclude='./.git' --exclude='./dist' -cf - . |
  tar -xf - -C "$plugins/esp44-custom"
# The reviewed main commit is the baseline, not a reinterpretation of its SQL.
git archive a5c0e3f518e8204d085bdaf492fcc203bf8bca87 |
  tar -xf - -C "$plugins/esp42-main"
cp -a "$plugins/esp44-custom" "$plugins/esp42-pr"

docker build --quiet -f tests/fixtures/apache.Dockerfile -t esp42-media-perf-ci .
docker run --detach --rm --name "$container" \
  --add-host=host.docker.internal:host-gateway \
  --publish 127.0.0.1:8094:80 \
  --mount "type=bind,src=$root/wordpress,dst=/var/www/html" \
  --mount "type=bind,src=$root/wp-cli.phar,dst=/opt/wp-cli.phar,readonly" \
  esp42-media-perf-ci >/dev/null
started=1
docker exec "$container" php /opt/wp-cli.phar --allow-root --path=/var/www/html \
  config create --dbname=esp_wp31 --dbuser=root --dbpass= \
  --dbhost=host.docker.internal --skip-check
docker exec "$container" php /opt/wp-cli.phar --allow-root --path=/var/www/html \
  core install --url=http://localhost:8094 --title=ESP42-PERF \
  --admin_user=esp-test --admin_password=disposable-ci-test-only \
  --admin_email=esp@example.test --skip-email
docker exec "$container" env CI=true ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-http-prepare.php

# The SQL comparisons always run under identical records and WordPress core.
for count in 10000 100000; do
  echo "### Seeding test data up to $count attachments"
  docker exec "$container" env CI=true ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
    php /var/www/html/wp-content/plugins/esp42-pr/tests/benchmark-media-resolver.php \
      seed "$count" >"$results/seed-$count.json"
  docker exec "$container" env CI=true ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
    php /var/www/html/wp-content/plugins/esp42-pr/tests/benchmark-media-resolver.php \
      measure "$count" >"$results/sql-$count.json"

  # Swap the *complete* plugin code while keeping the same WP/MySQL site,
  # active plugin path, uploads, Apache config, and connection port.
  for mode in main pr62; do
    if [[ "$mode" == main ]]; then
      source=esp42-main
    else
      source=esp42-pr
    fi
    docker exec "$container" bash -ec \
      "rm -rf /var/www/html/wp-content/plugins/esp44-custom &&
       cp -a /var/www/html/wp-content/plugins/$source /var/www/html/wp-content/plugins/esp44-custom"
    echo "### HTTP: $count attachments using $mode plugin source"
    python3 tests/benchmark-media-resolver-http.py \
      --version "$mode" --dataset "$count" >"$results/http-$count-$mode.json"
  done
  python3 - "$results" "$count" <<'PY'
import json, pathlib, sys
folder, count = pathlib.Path(sys.argv[1]), sys.argv[2]
sql=json.loads((folder/f'sql-{count}.json').read_text())['result']
print(f'### DB fixture: {count} attachments')
for name,d in sql['cases'].items():
    print(f"SQL {name:21s} main p95={d['baseline']['p95_ms']:8.2f} ms, "
          f"PR p95={d['pr62']['p95_ms']:8.2f} ms, ratio={d['p95_ratio']:6.2f}x")
for case in ('public_original','protected_original','unregistered'):
    main=json.loads((folder/f'http-{count}-main.json').read_text())['http'][case]
    pr=json.loads((folder/f'http-{count}-pr62.json').read_text())['http'][case]
    for level in ('serial','parallel8'):
        a=main[level]['p95_ms'];b=pr[level]['p95_ms']
        print(f"HTTP {case:21s} {level:9s} main p95={a:8.2f} ms, "
              f"PR p95={b:8.2f} ms, ratio={b/max(a,.001):6.2f}x")
PY
done
echo "Benchmark completed; JSON artifacts contain SQL plans, samples and HTTP status checks."
