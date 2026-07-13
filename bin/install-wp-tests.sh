#!/usr/bin/env bash
# Installs the WordPress test suite + a test DB, and (optionally) WooCommerce
# into the test WP so the WC-backed integration tests can run.
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]

set -euo pipefail

DB_NAME=${1-}
DB_USER=${2-}
DB_PASS=${3-}
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
  echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]"
  exit 1
fi

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}
WC_VERSION=${WC_VERSION-latest}

download() {
  if command -v curl >/dev/null; then curl -s "$1" >"$2"; else wget -nv -O "$2" "$1"; fi
}

if [[ "$WP_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
  WP_TESTS_TAG="tags/$WP_VERSION"
elif [ "$WP_VERSION" == "latest" ]; then
  download http://api.wordpress.org/core/version-check/1.7/ "$TMPDIR/wp-latest.json"
  LATEST_VERSION=$(grep -o '"version":"[^"]*' "$TMPDIR/wp-latest.json" | sed 's/"version":"//' | head -1)
  WP_TESTS_TAG="tags/$LATEST_VERSION"
else
  WP_TESTS_TAG="trunk"
fi

install_wp() {
  [ -d "$WP_CORE_DIR" ] && return
  mkdir -p "$WP_CORE_DIR"
  local ARCHIVE="wordpress-${WP_VERSION}.tar.gz"
  [ "$WP_VERSION" == "latest" ] && ARCHIVE="latest.tar.gz"
  download "https://wordpress.org/${ARCHIVE}" "$TMPDIR/wordpress.tar.gz"
  tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
  download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
  mkdir -p "$WP_TESTS_DIR"
  rm -rf "$WP_TESTS_DIR/includes" "$WP_TESTS_DIR/data"
  svn export -q --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
  svn export -q --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"

  download https://develop.svn.wordpress.org/"${WP_TESTS_TAG}"/wp-tests-config-sample.php "$WP_TESTS_DIR/wp-tests-config.php"
  local PORT
  PORT=$(echo "$DB_HOST" | grep -o ':[0-9]*$' | tr -d ':' || true)
  local HOST=$DB_HOST
  sed -i.bak "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i.bak "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i.bak "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i.bak "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i.bak "s|localhost|${HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
}

install_db() {
  [ "$SKIP_DB_CREATE" == "true" ] && return
  local EXTRA=""
  if [[ "$DB_HOST" == *":"* ]]; then
    local IP=${DB_HOST%:*}
    local PORT=${DB_HOST##*:}
    EXTRA="--host=$IP --port=$PORT --protocol=tcp"
  else
    EXTRA="--host=$DB_HOST --protocol=tcp"
  fi
  mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" $EXTRA || true
}

# Optionally drop WooCommerce into the test WP so WC-backed tests don't skip.
install_woocommerce() {
  local PLUGINS_DIR="$WP_CORE_DIR/wp-content/plugins"
  [ -d "$PLUGINS_DIR/woocommerce" ] && return
  mkdir -p "$PLUGINS_DIR"
  local URL="https://downloads.wordpress.org/plugin/woocommerce.zip"
  [ "$WC_VERSION" != "latest" ] && URL="https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION}.zip"
  download "$URL" "$TMPDIR/woocommerce.zip"
  unzip -q -o "$TMPDIR/woocommerce.zip" -d "$PLUGINS_DIR"
}

install_wp
install_test_suite
install_db
install_woocommerce
echo "WP test env ready at $WP_TESTS_DIR (WooCommerce in $WP_CORE_DIR/wp-content/plugins)."
