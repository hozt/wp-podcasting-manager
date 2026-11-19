#!/usr/bin/env bash
# Downloads a pristine WordPress core for the PHPUnit suite.
# Usage: tests/bin/install-wordpress.sh
# Env: PODCAST_TEST_WP_DIR (default: <plugin>/vendor/wordpress)
#      PODCAST_TEST_WP_VERSION (default: 7.1.5, should match wp-phpunit)
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DIR="${PODCAST_TEST_WP_DIR:-$PLUGIN_DIR/vendor/wordpress}"
VERSION="${PODCAST_TEST_WP_VERSION:-7.1.5}"

installed="$(php -r '$f = $argv[1] . "/wp-includes/version.php"; if (is_file($f)) { include $f; echo $wp_version ?? ""; }' "$DIR" 2>/dev/null || true)"
if [ "$installed" = "$VERSION" ]; then
    echo "WordPress $VERSION already installed in $DIR"
    exit 0
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
echo "Downloading WordPress $VERSION..."
curl -fsSL -o "$TMP/wordpress.zip" "https://wordpress.org/wordpress-$VERSION.zip"
unzip -q "$TMP/wordpress.zip" -d "$TMP"
rm -rf "$DIR"
mkdir -p "$DIR"
cp -a "$TMP/wordpress/." "$DIR/"
echo "Installed WordPress $VERSION in $DIR"
