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

docker exec "$container" test -r /var/www/html/wp-content/uploads/esp31-protected.jpg
docker exec "$container" grep -q '# BEGIN ESP Media Protection' \
  /var/www/html/wp-content/uploads/.htaccess

# Real Apache requests including Rewrite, WordPress auth, headers and bytes.
python3 tests/wordpress-http-check.py
echo 'PASS: disposable Apache/WordPress media HTTP regression suite'
