#!/usr/bin/env bash
#
# OPR one-command live demo. Boots the vault server on a scratch PostgreSQL
# database and runs the full patient-owned-record story against it, ending with
# the black-box conformance certification.
#
#   ./demo.sh            # requires: PHP 8.2+, composer install done, PostgreSQL running
#
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
SERVER="$HERE/../server"
PORT="${DEMO_PORT:-8797}"
DB="${DEMO_DB:-opr_vault_demo}"
DBUSER="${DEMO_DB_USER:-$(whoami)}"

command -v psql >/dev/null || { echo "psql not found"; exit 1; }
[ -d "$SERVER/vendor" ] || { echo "run: cd reference-impl/server && composer install"; exit 1; }

echo "▸ preparing scratch database ($DB)..."
dropdb --if-exists "$DB" 2>/dev/null || true
createdb "$DB"

export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
       DB_DATABASE="$DB" DB_USERNAME="$DBUSER" DB_PASSWORD= DB_TIMEZONE=UTC \
       DEMO_DB="$DB" DEMO_DB_USER="$DBUSER"

( cd "$SERVER" && php artisan migrate --force --no-interaction >/dev/null )

echo "▸ starting vault server on port $PORT..."
# php -S directly (not `artisan serve`): the serve wrapper re-reads .env in its
# worker and would ignore our scratch-database environment. The built-in server
# inherits the exported env, and real env vars beat .env in Laravel.
( cd "$SERVER" && php -S 127.0.0.1:"$PORT" -t public >/dev/null 2>&1 ) &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null || true' EXIT
sleep 2

php "$HERE/demo.php" "http://127.0.0.1:$PORT/api"
