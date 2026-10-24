#!/usr/bin/env bash
set -euo pipefail
fixture_root="${RUNNER_TEMP:-/tmp}/esp44-wordpress"
mkdir -p "$fixture_root"
curl --fail --location --retry 2 https://wordpress.org/wordpress-7.1.3.tar.gz | tar -xz -C "$fixture_root"
curl --fail --location --retry 2 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$fixture_root/wp-cli.phar"
php "$fixture_root/wp-cli.phar" --allow-root --path="$fixture_root/wordpress" config create --dbname=esp_wp44 --dbuser=root --dbpass= --dbhost=127.0.0.1 --skip-check
php "$fixture_root/wp-cli.phar" --allow-root --path="$fixture_root/wordpress" core install --url=http://esp44.example.test --title=ESP44 --admin_user=esp-test --admin_password=disposable-ci-test-only --admin_email=esp@example.test --skip-email
export ESP_WP_ROOT="$fixture_root/wordpress" ESP_ALLOW_DISPOSABLE_DB=1
php tests/wordpress-update-integration.php
for fixture_folder in easy-slug-protect esp44-custom; do
  for automatic in 0 1; do
    ESP_TEST_FOLDER="$fixture_folder" ESP_TEST_AUTOMATIC="$automatic" php tests/wordpress-release-integration.php
    php tests/wordpress-updated-code-check.php
  done
done
ESP_TEST_AUTOMATIC=1 ESP_TEST_FATAL_ROLLBACK=1 php tests/wordpress-release-integration.php
ESP_EXPECT_VERSION=0.7.40 php tests/wordpress-updated-code-check.php
