#!/usr/bin/env bash
# Fast Gate v2: real WordPress core + real MySQL + real SQLite integration.
# NEVER run this against a non-disposable installation.
set -euo pipefail
if [[ "${CI:-}" != "true" || "${ESP_ALLOW_DISPOSABLE_DB:-}" != "1" ]]; then
  echo "Refusing Fast Gate WordPress test outside disposable CI" >&2
  exit 1
fi
fixture_root="$(mktemp -d "${RUNNER_TEMP:-/tmp}/esp-fast-gate-wp.XXXXXXXX")"
trap 'rm -rf "$fixture_root"' EXIT
curl --fail --location --retry 2 --silent --show-error \
  https://wordpress.org/wordpress-7.1.3.tar.gz | tar -xz -C "$fixture_root"
curl --fail --location --retry 2 --silent --show-error \
  https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
  -o "$fixture_root/wp-cli.phar"

wp() {
  php "$fixture_root/wp-cli.phar" --allow-root --path="$fixture_root/wordpress" "$@"
}
wp config create --dbname=esp_fast_gate_wp --dbuser=root --dbpass= \
  --dbhost=127.0.0.1 --skip-check
wp core install --url=http://esp-gate.example.test \
  --title=ESP-Fast-Gate-CI \
  --admin_user=esp-gate-ci --admin_password=disposable-ci-only \
  --admin_email=esp-gate@example.test --skip-email
plugin_dir="$fixture_root/wordpress/wp-content/plugins/easy-slug-protect"
mkdir -p "$plugin_dir"
tar --exclude='./.git' --exclude='./dist' -cf - . | tar -xf - -C "$plugin_dir"
wp plugin activate easy-slug-protect
echo "Real WordPress version: $(wp core version)"
echo "Real PHP SQLite3: $(php -r 'echo SQLite3::version()["versionString"];')"
export ESP_WP_ROOT="$fixture_root/wordpress"
php tests/wordpress-fast-gate-v2-ci.php
echo "PASS: Fast Gate v2 real WordPress/MySQL/SQLite integration"
