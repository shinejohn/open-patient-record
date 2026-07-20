#!/usr/bin/env bash
#
# Gateway G0 live demo: boots the OPR vault server on a scratch database, then
# ingests two real legacy record fixtures (C-CDA + FHIR bundle), verifies them,
# and commits them into a patient vault via the public API.
#
#   ./gateway-demo.sh     # requires: PHP 8.4+, composer installs done, PostgreSQL
#
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
SERVER="$HERE/../../reference-impl/server"
PORT="${DEMO_PORT:-8798}"
DB="${DEMO_DB:-opr_gateway_demo}"
DBUSER="${DEMO_DB_USER:-$(whoami)}"

command -v psql >/dev/null || { echo "psql not found"; exit 1; }
[ -d "$SERVER/vendor" ] || { echo "run: cd reference-impl/server && composer install"; exit 1; }
[ -d "$HERE/../vendor" ] || { echo "run: cd gateway && composer install"; exit 1; }

echo "> preparing scratch database ($DB)..."
dropdb --if-exists "$DB" 2>/dev/null || true
createdb "$DB"

export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
       DB_DATABASE="$DB" DB_USERNAME="$DBUSER" DB_PASSWORD= DB_TIMEZONE=UTC

( cd "$SERVER" && php artisan migrate --force --no-interaction >/dev/null )

echo "> starting vault server on port $PORT..."
( cd "$SERVER" && php -S 127.0.0.1:"$PORT" -t public >/dev/null 2>&1 ) &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null || true' EXIT
sleep 2

php "$HERE/gateway-demo.php" "http://127.0.0.1:$PORT"
