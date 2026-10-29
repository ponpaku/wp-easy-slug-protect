#!/usr/bin/env bash
# Disposable WordPress/Apache integration runner for issue #31.
set -euo pipefail

if [[ "$ESP_ALLOW_DISPOSABLE_DB" != 1 || "$CI" != true ]]; then
  echo "Refusing to run outside explicitly disposable CI" >&2
  exit 1
fi

fixture_root="$(mktemp -d "$RUNNER_TEMP/esp31-apache-http.XXXXXXXX")"
container="esp31-http-$GITHUB_RUN_ID-$$"
started=0
cleanup() {
  local result=$?
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

# #63: exercise actual WordPress dbDelta/backfill and opt-in strict PHP delivery.
docker exec "$container" env ESP_ALLOW_DISPOSABLE_DB=1 ESP_WP_ROOT=/var/www/html \
  php /var/www/html/wp-content/plugins/esp44-custom/tests/wordpress-media-index-ci.php
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
assert_http esp63-unregistered.jpg 403
echo 'PASS: issue 63 real WordPress/Apache strict index HTTP smoke suite'
echo 'PASS: disposable Apache/WordPress media HTTP regression suite'
