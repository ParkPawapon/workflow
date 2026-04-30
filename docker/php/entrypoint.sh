#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

if command -v git >/dev/null 2>&1; then
  git config --global --add safe.directory /var/www/html || true
fi

if [[ ! -f vendor/autoload.php ]] && command -v composer >/dev/null 2>&1; then
  composer install --no-interaction --prefer-dist
fi

mkdir -p storage/uploads public/uploads tmp

wait_for_db() {
  local host="${DB_HOST:-}"
  local port="${DB_PORT:-3306}"
  local db_name="${DB_NAME:-}"
  local db_user="${DB_USER:-root}"
  local db_pass="${DB_PASS:-}"
  local attempt=0

  [[ -n "$host" ]] || return 0

  echo "Waiting for database ${host}:${port}/${db_name} ..."

  until MYSQL_PWD="$db_pass" mariadb --protocol=TCP -h "$host" -P "$port" -u "$db_user" -D "$db_name" -e "SELECT 1" >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if (( attempt >= 90 )); then
      echo "Database is not ready after waiting." >&2
      exit 1
    fi
    sleep 2
  done
}

wait_for_db

if [[ -f scripts/migrate.php ]]; then
  php scripts/migrate.php
fi

exec "$@"
