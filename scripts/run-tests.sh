#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_DIR=$(cd "${SCRIPT_DIR}/.." && pwd)

TEST_SUITE=${1:-}
PS_VERSION=${2:-latest}
PHPUNIT_IMAGE=${PHPUNIT_IMAGE:-prestashop/prestashop:${PS_VERSION}}
MYSQL_IMAGE=${MYSQL_IMAGE:-mysql:8.4}
PS_ROOT_DIR_HOST=${PS_ROOT_DIR_HOST:-${REPO_DIR}/../prestashop}
PS_INSTALL_TIMEOUT=${PS_INSTALL_TIMEOUT:-900}

RUN_ID="${TEST_SUITE:-unknown}-$$"
PS_CONTAINER="temp-ps-phpunit-${RUN_ID}"
DB_CONTAINER="temp-ps-db-${RUN_ID}"
PS_VOLUME="ps-phpunit-volume-${RUN_ID}"
DB_VOLUME="ps-phpunit-db-volume-${RUN_ID}"
TMP_VOLUME="ps-phpunit-tmp-volume-${RUN_ID}"
PS_NETWORK="ps-phpunit-network-${RUN_ID}"

usage() {
  cat <<'EOF'
Usage: ./scripts/run-tests.sh <unit|integration> [prestashop-tag]

Environment variables:
- PS_ROOT_DIR_HOST: path to a PrestaShop checkout with vendor/bin/phpunit installed
- PHPUNIT_IMAGE: override the PrestaShop Docker image used for the test environment
- MYSQL_IMAGE: override the MySQL Docker image used for integration tests
- PS_INSTALL_TIMEOUT: maximum time in seconds to wait for the PrestaShop auto-install during integration tests
EOF
}

cleanup() {
  docker rm -f "${PS_CONTAINER}" "${DB_CONTAINER}" >/dev/null 2>&1 || true
  docker volume rm -f "${PS_VOLUME}" "${DB_VOLUME}" "${TMP_VOLUME}" >/dev/null 2>&1 || true
  docker network rm "${PS_NETWORK}" >/dev/null 2>&1 || true
}

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 1
  fi
}

prepare_prestashop_volume() {
  echo "Prepare PrestaShop files from ${PS_ROOT_DIR_HOST}"

  # Copy the prepared PrestaShop checkout into an ephemeral Docker volume.
  # Every later container reads /var/www/html from this volume, so local runs
  # and CI execute against the same isolated filesystem layout.
  docker volume create "${PS_VOLUME}" >/dev/null

  if [ "${TEST_SUITE}" = "integration" ]; then
    # Integration preparation writes temporary artifacts to /tmp.
    # The install container, the DB preparation container and the final PHPUnit
    # container must all read the same /tmp content, so integration needs a
    # dedicated shared tmp volume.
    docker volume create "${TMP_VOLUME}" >/dev/null
  fi

  docker run --rm \
    -v "${PS_ROOT_DIR_HOST}:/source:ro" \
    -v "${REPO_DIR}:/module-source:ro" \
    -v "${PS_VOLUME}:/var/www/html" \
    alpine sh -lc '
      cp -a /source/. /var/www/html/ &&
      rm -rf /var/www/html/modules/ps_onepagecheckout &&
      mkdir -p /var/www/html/modules/ps_onepagecheckout &&
      cp -a /module-source/. /var/www/html/modules/ps_onepagecheckout/ &&
      chown -R 33:33 /var/www/html
    '
}

wait_for_prestashop_install() {
  local deadline
  deadline=$((SECONDS + PS_INSTALL_TIMEOUT))

  while true; do
    if docker exec "${PS_CONTAINER}" sh -lc 'test -f /var/www/html/app/config/parameters.php || test -f /var/www/html/config/settings.inc.php' >/dev/null 2>&1; then
      return 0
    fi

    if [ "$(docker inspect -f '{{.State.Running}}' "${PS_CONTAINER}" 2>/dev/null || echo false)" != "true" ]; then
      echo "PrestaShop container stopped before installation completed." >&2
      docker logs "${PS_CONTAINER}" || true
      exit 1
    fi

    if [ "${SECONDS}" -ge "${deadline}" ]; then
      echo "Timed out after ${PS_INSTALL_TIMEOUT}s waiting for PrestaShop installation." >&2
      docker logs "${PS_CONTAINER}" || true
      exit 1
    fi

    sleep 5
  done
}

wait_for_mysql_database() {
  local database_name=$1
  local deadline
  deadline=$((SECONDS + PS_INSTALL_TIMEOUT))

  echo "Wait for MySQL database ${database_name}"

  while true; do
    if docker exec "${DB_CONTAINER}" sh -lc \
      "mysql -uroot -pprestashop -Nse \"SHOW DATABASES LIKE '${database_name}'\" | grep -Fx '${database_name}'" \
      >/dev/null 2>&1; then
      return 0
    fi

    if [ "$(docker inspect -f '{{.State.Running}}' "${DB_CONTAINER}" 2>/dev/null || echo false)" != "true" ]; then
      echo "MySQL container stopped before database ${database_name} became available." >&2
      docker logs "${DB_CONTAINER}" || true
      exit 1
    fi

    if [ "${SECONDS}" -ge "${deadline}" ]; then
      echo "Timed out after ${PS_INSTALL_TIMEOUT}s waiting for MySQL database ${database_name}." >&2
      docker logs "${DB_CONTAINER}" || true
      exit 1
    fi

    sleep 2
  done
}

run_prestashop_image() {
  local workdir=${1:-/var/www/html}
  shift || true

  local docker_run_args=(
    --rm
    -v "${PS_VOLUME}:/var/www/html"
    --workdir="${workdir}"
    --entrypoint=bash
  )

  if [ "${TEST_SUITE}" = "integration" ]; then
    # Unit tests only need the copied PrestaShop files.
    # Integration also needs:
    # - the shared /tmp volume populated during DB preparation
    # - the private Docker network used by PrestaShop and MySQL
    docker_run_args+=(-v "${TMP_VOLUME}:/tmp")
    docker_run_args+=(--network "${PS_NETWORK}")
  fi

  docker run "${docker_run_args[@]}" "${PHPUNIT_IMAGE}" "$@"
}

prepare_integration_database() {
  # These PrestaShop helpers create the dedicated test database and write the
  # SQL dump files expected by the integration bootstrap into /tmp.
  # /tmp must therefore be shared with the later PHPUnit container.
  run_prestashop_image /var/www/html \
    -lc 'php ./tests/bin/create-test-db.php && php ./tests/bin/create-test-tables-dump.php'
}

start_integration_services() {
  echo "Start MySQL"

  docker network create "${PS_NETWORK}" >/dev/null
  docker volume create "${DB_VOLUME}" >/dev/null

  docker run -d --rm \
    --network "${PS_NETWORK}" \
    -v "${DB_VOLUME}:/var/lib/mysql" \
    --name "${DB_CONTAINER}" \
    -e MYSQL_ROOT_PASSWORD=prestashop \
    -e MYSQL_DATABASE=prestashop \
    "${MYSQL_IMAGE}" >/dev/null

  wait_for_mysql_database prestashop

  docker run --rm \
    -v "${PS_VOLUME}:/var/www/html" \
    alpine sh -lc 'rm -f /var/www/html/app/config/parameters.php /var/www/html/config/settings.inc.php'

  echo "Start PrestaShop with automatic install"

  docker run -tid \
    --network "${PS_NETWORK}" \
    -v "${PS_VOLUME}:/var/www/html" \
    -v "${TMP_VOLUME}:/tmp" \
    --name "${PS_CONTAINER}" \
    -e PS_INSTALL_AUTO=1 \
    -e PS_INSTALL_DB=1 \
    -e PS_ERASE_DB=1 \
    -e DB_SERVER="${DB_CONTAINER}" \
    -e DB_NAME=prestashop \
    -e DB_PASSWD=prestashop \
    -e DB_PREFIX=ps_ \
    -e PS_DOMAIN=localhost \
    -e PS_FOLDER_INSTALL=install-dev \
    -e PS_FOLDER_ADMIN=admin-dev \
    -e PS_DEV_MODE=1 \
    "${PHPUNIT_IMAGE}" >/dev/null

  echo "Wait for PrestaShop installation"
  wait_for_prestashop_install

  echo "Initialize PHPUnit integration database"
  prepare_integration_database
}

run_phpunit() {
  echo "Run PHPUnit (${TEST_SUITE})"

  local docker_run_args=(
    --rm
    -v "${PS_VOLUME}:/var/www/html"
    -v "${REPO_DIR}:/var/www/html/modules/ps_onepagecheckout"
    --workdir=/var/www/html/modules/ps_onepagecheckout
    --entrypoint=bash
  )

  if [ "${TEST_SUITE}" = "integration" ]; then
    # PHPUnit integration must read the same /tmp dump files created by
    # create-test-db.php and create-test-tables-dump.php.
    docker_run_args+=(-v "${TMP_VOLUME}:/tmp")
    docker_run_args+=(--network "${PS_NETWORK}")
  fi

  docker run "${docker_run_args[@]}" "${PHPUNIT_IMAGE}" \
    -lc "if command -v composer >/dev/null 2>&1; then composer dump-autoload -o --no-interaction; fi; SYMFONY_DEPRECATIONS_HELPER=disabled /var/www/html/vendor/bin/phpunit -c ${PHPUNIT_CONFIG}"
}

case "${TEST_SUITE}" in
  unit)
    PHPUNIT_CONFIG=/var/www/html/modules/ps_onepagecheckout/tests/php/phpunit.xml
    ;;
  integration)
    PHPUNIT_CONFIG=/var/www/html/modules/ps_onepagecheckout/tests/php/phpunit-integration.xml
    ;;
  *)
    usage >&2
    exit 1
    ;;
esac

require_command docker

if [ ! -d "${PS_ROOT_DIR_HOST}" ]; then
  echo "PrestaShop checkout directory not found: ${PS_ROOT_DIR_HOST}" >&2
  echo "Set PS_ROOT_DIR_HOST to override the default ../prestashop path." >&2
  exit 1
fi

PS_ROOT_DIR_HOST=$(cd "${PS_ROOT_DIR_HOST}" && pwd)

if [ ! -f "${PS_ROOT_DIR_HOST}/vendor/bin/phpunit" ] || [ ! -f "${PS_ROOT_DIR_HOST}/tests/Unit/bootstrap.php" ]; then
  echo "Unable to locate a PrestaShop checkout with PHPUnit dependencies in ${PS_ROOT_DIR_HOST}" >&2
  echo "Run composer install in the PrestaShop checkout or set PS_ROOT_DIR_HOST to a prepared checkout." >&2
  exit 1
fi

trap cleanup EXIT

cleanup
prepare_prestashop_volume

if [ "${TEST_SUITE}" = "integration" ]; then
  start_integration_services
fi

run_phpunit
