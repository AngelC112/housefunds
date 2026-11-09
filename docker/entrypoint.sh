#!/bin/bash
# ============================================================
# HouseFunds container entrypoint
#
# - Prepares /data (config.php, logs/, tmp/, mysql/).
# - DB_HOST not set: starts the embedded MariaDB. On the first start it
#   creates the database and a user with a random password, saved in
#   /data/config.php and reused on every following start.
# - DB_HOST set: uses that external MySQL/MariaDB or PostgreSQL server.
# - Then runs Apache in the foreground.
# ============================================================
set -euo pipefail

DATA="${HF_DATA_DIR:-/data}"
CONFIG="$DATA/config.php"
MYSQL_DATA="$DATA/mysql"
SOCKET=/run/mysqld/mysqld.sock
INTERNAL_DB_NAME=housefunds
INTERNAL_DB_USER=housefunds

log() { echo "[housefunds] $*"; }

mkdir -p "$DATA/logs" "$DATA/tmp"
chown www-data:www-data "$DATA" "$DATA/logs" "$DATA/tmp" 2> /dev/null \
  || log "WARNING: cannot change the owner of $DATA (check the volume permissions)"

# Value of a key in config.php ('' if missing)
config_get() {
  [ -f "$CONFIG" ] || return 0
  php -r '$c = @include $argv[1]; echo is_array($c) ? (string)($c[$argv[2]] ?? "") : "";' "$CONFIG" "$1"
}

# Embedded database: no external DB_HOST, and the config (if any) was written by us
use_internal_db() {
  [ -z "${DB_HOST:-}" ] || return 1
  [ ! -f "$CONFIG" ] && return 0
  [ "$(config_get DB_INTERNAL)" = "1" ]
}

MARIADB_PID=""

start_internal_db() {
  mkdir -p "$MYSQL_DATA" /run/mysqld
  chown -R mysql:mysql "$MYSQL_DATA" /run/mysqld 2> /dev/null \
    || log "WARNING: cannot change the owner of $MYSQL_DATA (MariaDB needs to write there)"

  if [ ! -d "$MYSQL_DATA/mysql" ]; then
    log "Initialising the embedded MariaDB in $MYSQL_DATA"
    mariadb-install-db --user=mysql --datadir="$MYSQL_DATA" \
      --auth-root-authentication-method=socket --skip-test-db > /dev/null
  fi

  log "Starting the embedded MariaDB"
  mariadbd-safe --user=mysql --datadir="$MYSQL_DATA" --socket="$SOCKET" \
    --bind-address=127.0.0.1 --port=3306 \
    --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci \
    --log-error="$DATA/logs/mariadb.log" > /dev/null 2>&1 &
  MARIADB_PID=$!

  for _ in $(seq 1 60); do
    if mariadb-admin --socket="$SOCKET" ping > /dev/null 2>&1; then break; fi
    sleep 1
  done
  if ! mariadb-admin --socket="$SOCKET" ping > /dev/null 2>&1; then
    log "ERROR: MariaDB did not start. See $DATA/logs/mariadb.log"
    exit 1
  fi

  # Password: generated once, then always read back from config.php
  local pass
  pass="$(config_get DB_PASS)"
  if [ -z "$pass" ]; then
    pass="$(php -r 'echo bin2hex(random_bytes(24));')"
    log "First start: created the database with a generated password (stored in $CONFIG)"
  fi

  # Idempotent: also realigns the password with config.php
  mariadb --socket="$SOCKET" <<SQL
CREATE DATABASE IF NOT EXISTS \`$INTERNAL_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$INTERNAL_DB_USER'@'127.0.0.1' IDENTIFIED BY '$pass';
CREATE USER IF NOT EXISTS '$INTERNAL_DB_USER'@'localhost' IDENTIFIED BY '$pass';
ALTER USER '$INTERNAL_DB_USER'@'127.0.0.1' IDENTIFIED BY '$pass';
ALTER USER '$INTERNAL_DB_USER'@'localhost' IDENTIFIED BY '$pass';
GRANT ALL PRIVILEGES ON \`$INTERNAL_DB_NAME\`.* TO '$INTERNAL_DB_USER'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`$INTERNAL_DB_NAME\`.* TO '$INTERNAL_DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

  if [ ! -f "$CONFIG" ]; then
    php -r '
      $c = [
        "DB_INTERNAL" => "1",
        "DB_DRIVER"   => "mysql",
        "DB_HOST"     => "127.0.0.1",
        "DB_PORT"     => "3306",
        "DB_NAME"     => $argv[2],
        "DB_USER"     => $argv[3],
        "DB_PASS"     => $argv[4],
      ];
      $src = "<?php\n// HouseFunds configuration, created by the Docker entrypoint.\n"
           . "// Embedded MariaDB credentials: keep this file with the data in /data.\n"
           . "// Environment variables with the same names take precedence.\n"
           . "return " . var_export($c, true) . ";\n";
      file_put_contents($argv[1], $src, LOCK_EX);
    ' "$CONFIG" "$INTERNAL_DB_NAME" "$INTERNAL_DB_USER" "$pass"
  fi
}

if use_internal_db; then
  start_internal_db
else
  log "Using the external database ${DB_DRIVER:-mysql} at ${DB_HOST:-(from config.php)}"
fi

if [ -f "$CONFIG" ]; then
  chown root:www-data "$CONFIG" 2> /dev/null || true
  chmod 640 "$CONFIG" 2> /dev/null || true
fi

# ── Apache in the foreground, clean shutdown of both services ──
shutdown() {
  log "Stopping"
  [ -n "${APACHE_PID:-}" ] && kill -WINCH "$APACHE_PID" 2> /dev/null || true
  if [ -n "$MARIADB_PID" ]; then
    mariadb-admin --socket="$SOCKET" shutdown > /dev/null 2>&1 || true
  fi
  wait || true
  exit 0
}
trap shutdown TERM INT

apache2-foreground "$@" &
APACHE_PID=$!
wait "$APACHE_PID"
