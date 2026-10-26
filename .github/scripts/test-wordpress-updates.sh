#!/usr/bin/env bash
set -euo pipefail
fixture_root="${RUNNER_TEMP:-/tmp}/esp44-wordpress"
mkdir -p "$fixture_root"
curl --fail --location --retry 2 https://wordpress.org/wordpress-7.1.3.tar.gz | tar -xz -C "$fixture_root"
curl --fail --location --retry 2 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$fixture_root/wp-cli.phar"
php "$fixture_root/wp-cli.phar" --allow-root --path="$fixture_root/wordpress" config create --dbname=esp_wp44 --dbuser=root --dbpass= --dbhost=127.0.0.1 --skip-check
php "$fixture_root/wp-cli.phar" --allow-root --path="$fixture_root/wordpress" core install --url=http://esp44.example.test --title=ESP44 --admin_user=esp-test --admin_password=disposable-ci-test-only --admin_email=esp@example.test --skip-email
export ESP_WP_ROOT="$fixture_root/wordpress" ESP_ALLOW_DISPOSABLE_DB=1
# These are actual historical commits, not a synthetic version string.
# The PR's installable ZIP is built from exactly the tested checkout.
python3 tools/build-release.py
export ESP_PR_ZIP="$PWD/dist/easy-slug-protect-0.7.40.zip"
plugin_dir="$ESP_WP_ROOT/wp-content/plugins/easy-slug-protect"
for version_sha in \
  "0.7.38:f2b8ca54130be4530bcb5e8a0663be12111a16b0" \
  "0.7.39:b6315e837e50c4ed8713c5fb60f7f0ea2b87b323"; do
  version="${version_sha%%:*}"
  sha="${version_sha#*:}"
  rm -rf "$plugin_dir"
  mkdir -p "$plugin_dir"
  git archive "$sha" | tar -x -C "$plugin_dir"
  export ESP_LEGACY_VERSION="$version"
  for stage in prepare install verify; do
    ESP_LEGACY_STAGE="$stage" php tests/wordpress-legacy-upgrade-integration.php
  done
done
php tests/wordpress-update-integration.php
# Fresh PHP processes avoid the WP_CLI shortcut used by the migration fixture.
for entry in ajax post cron; do
  ESP_TEST_ADMIN_ENTRY="$entry" php tests/wordpress-unauthorized-update-test.php
done
for fixture_folder in easy-slug-protect esp44-custom; do
  for automatic in 0 1; do
    ESP_TEST_FOLDER="$fixture_folder" ESP_TEST_AUTOMATIC="$automatic" php tests/wordpress-release-integration.php
    php tests/wordpress-updated-code-check.php
  done
done
ESP_TEST_AUTOMATIC=1 ESP_TEST_FATAL_ROLLBACK=1 php tests/wordpress-release-integration.php
ESP_EXPECT_VERSION=0.7.40 php tests/wordpress-updated-code-check.php
