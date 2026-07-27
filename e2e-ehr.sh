#!/bin/bash
# E4: live two-app end-to-end — the EHR practice-ops backend driving a REAL
# vault server over real HTTP. This exists because contract fakes cannot catch
# what they don't model (the 30-min derived-token TTL bug survived every faked
# test and fell to review, not fakes). Everything here is real: real Postgres
# under the vault, real chain hashes, real grants, real transaction Bundles.
#
# Usage: ./e2e-ehr.sh      (from the repo root; needs local PostgreSQL + PHP)
set -uo pipefail

VAULT_PORT=8801
EHR_PORT=8802
VAULT_URL="http://127.0.0.1:${VAULT_PORT}"
EHR_URL="http://127.0.0.1:${EHR_PORT}"
VAULT_DB="opr_e2e_vault"
EHR_DB="$(mktemp -t opr-e2e-ehr).sqlite"
ROOT="$(cd "$(dirname "$0")" && pwd)"
PASS=0; FAIL=0
VAULT_PID=""; EHR_PID=""

cleanup() {
  [[ -n "$VAULT_PID" ]] && kill "$VAULT_PID" 2>/dev/null
  [[ -n "$EHR_PID" ]] && kill "$EHR_PID" 2>/dev/null
  # Restore any .env files we set aside (non-destructive, like e2e-scribemd).
  [[ -f "$ROOT/reference-impl/server/.env.e2e-backup" ]] && mv "$ROOT/reference-impl/server/.env.e2e-backup" "$ROOT/reference-impl/server/.env"
  [[ -f "$ROOT/ehr/.env.e2e-backup" ]] && mv "$ROOT/ehr/.env.e2e-backup" "$ROOT/ehr/.env"
  dropdb "$VAULT_DB" 2>/dev/null
  rm -f "$EHR_DB"
}
trap cleanup EXIT

# `artisan serve` workers load the app's .env, which overrides this script's
# exported environment — migrate would run against the scratch DB while the
# server quietly used .env's database. Set .env aside for the run.
[[ -f "$ROOT/reference-impl/server/.env" ]] && mv "$ROOT/reference-impl/server/.env" "$ROOT/reference-impl/server/.env.e2e-backup"
[[ -f "$ROOT/ehr/.env" ]] && mv "$ROOT/ehr/.env" "$ROOT/ehr/.env.e2e-backup"

check() { # check <name> <actual> <expected-substring-or-value>
  if [[ "$2" == *"$3"* ]]; then PASS=$((PASS+1)); echo "  [PASS] $1";
  else FAIL=$((FAIL+1)); echo "  [FAIL] $1 — got: ${2:0:180}"; fi
}

echo "== booting the vault server (PostgreSQL) =="
dropdb "$VAULT_DB" 2>/dev/null; createdb "$VAULT_DB" || exit 1
cd "$ROOT/reference-impl/server"
export APP_ENV=local APP_DEBUG=true LOG_CHANNEL=stderr
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE="$VAULT_DB" DB_USERNAME="$(whoami)" DB_PASSWORD=
php artisan migrate --force --no-interaction >/dev/null || exit 1
php artisan serve --host=127.0.0.1 --port=$VAULT_PORT >/dev/null 2>&1 & VAULT_PID=$!

echo "== booting the EHR app (sqlite) =="
cd "$ROOT/ehr"
touch "$EHR_DB"
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export DB_CONNECTION=sqlite DB_DATABASE="$EHR_DB"
export OPR_VAULT_BASE_URL="$VAULT_URL"
php artisan migrate --force --no-interaction >/dev/null || exit 1
php artisan serve --host=127.0.0.1 --port=$EHR_PORT >/dev/null 2>&1 & EHR_PID=$!

for i in $(seq 1 30); do
  curl -sf "$VAULT_URL/api/fhir/metadata" >/dev/null && curl -sf "$EHR_URL/up" >/dev/null && break
  sleep 0.5
done

J='-H Content-Type:application/json -H Accept:application/json'

echo "== practice + staff =="
REG=$(curl -s $J -X POST "$EHR_URL/api/register-practice" -d '{"practice_name":"Riverbend Family Medicine","name":"Dr. Okafor","email":"okafor@riverbend.test","password":"correct-horse-battery"}')
TOKEN=$(echo "$REG" | jq -r .token)
check "practice registered, owner token issued" "$TOKEN" "|"

STAFF=$(curl -s $J -X POST "$EHR_URL/api/staff" -H "Authorization: Bearer $TOKEN" -d '{"name":"Front Desk","email":"desk@riverbend.test","password":"correct-horse-battery","role":"staff"}')
STAFF_TOKEN=$(echo "$STAFF" | jq -r .token)
check "staff onboarded" "$STAFF_TOKEN" "|"

echo "== patient registration provisions a REAL vault =="
PATIENT=$(curl -s $J -X POST "$EHR_URL/api/patients" -H "Authorization: Bearer $TOKEN" -d '{"name":"Ana Rivera","email":"ana@example.test","birth_date":"1987-03-14","gender":"female"}')
PID=$(echo "$PATIENT" | jq -r .id); VID=$(echo "$PATIENT" | jq -r .vault_id)
check "patient created with a vault id" "$VID" "-"
check "demographics committed to the vault" "$(echo "$PATIENT" | jq -r .demographics_committed)" "true"

CHART=$(curl -s "$EHR_URL/api/patients/$PID/chart" -H "Authorization: Bearer $TOKEN")
check "chart is a real Bundle from the vault" "$(echo "$CHART" | jq -r .resourceType)" "Bundle"
check "anchor carries committed demographics" "$(echo "$CHART" | jq -r '.entry[0].resource.birthDate')" "1987-03-14"
check "staff are denied the chart (role gate)" "$(curl -s -o /dev/null -w '%{http_code}' "$EHR_URL/api/patients/$PID/chart" -H "Authorization: Bearer $STAFF_TOKEN")" "403"

echo "== scheduling =="
APPT=$(curl -s $J -X POST "$EHR_URL/api/appointments" -H "Authorization: Bearer $STAFF_TOKEN" -d "{\"patient_id\":\"$PID\",\"starts_at\":\"2026-08-03T09:00:00Z\",\"ends_at\":\"2026-08-03T09:30:00Z\",\"reason\":\"follow-up\"}")
check "staff can book" "$(echo "$APPT" | jq -r .id)" "-"

echo "== encounter: SOAP -> sign -> REAL transaction Bundle on the chain =="
ENC=$(curl -s $J -X POST "$EHR_URL/api/patients/$PID/encounters" -H "Authorization: Bearer $TOKEN" -d '{"subjective":"Headache x3 days.","objective":"BP 120/80."}')
EID=$(echo "$ENC" | jq -r .id)
curl -s $J -X PATCH "$EHR_URL/api/encounters/$EID" -H "Authorization: Bearer $TOKEN" -d '{"assessment":"Tension headache.","plan":"Hydration; ibuprofen PRN."}' >/dev/null
SIGN=$(curl -s $J -X POST "$EHR_URL/api/encounters/$EID/sign" -H "Authorization: Bearer $TOKEN")
check "encounter signed" "$(echo "$SIGN" | jq -r .status)" "signed"

CHART2=$(curl -s "$EHR_URL/api/patients/$PID/chart" -H "Authorization: Bearer $TOKEN")
check "vault chart now holds the Encounter" "$(echo "$CHART2" | jq '[.entry[].resource.resourceType] | index("Encounter")')" ""
check "vault chart now holds the note (DocumentReference)" "$(echo "$CHART2" | jq -r '[.entry[].resource.resourceType]|join(",")')" "DocumentReference"
NOTE=$(echo "$CHART2" | jq -r '.entry[] | select(.resource.resourceType=="DocumentReference") | .resource.content[0].attachment.data' | base64 -d 2>/dev/null)
check "the note round-trips (SOAP content intact)" "$NOTE" "Tension headache."

echo "== billing =="
curl -s $J -X POST "$EHR_URL/api/fee-schedule" -H "Authorization: Bearer $TOKEN" -d '{"cpt_code":"99213","description":"Office visit","price_cents":12500}' >/dev/null
INV=$(curl -s $J -X POST "$EHR_URL/api/invoices" -H "Authorization: Bearer $TOKEN" -d "{\"encounter_id\":\"$EID\",\"lines\":[{\"cpt_code\":\"99213\",\"icd10_code\":\"R51.9\"}]}")
IID=$(echo "$INV" | jq -r .id)
check "invoice priced from the fee schedule" "$(echo "$INV" | jq -r .total_cents)" "12500"
PAY1=$(curl -s $J -X POST "$EHR_URL/api/invoices/$IID/payments" -H "Authorization: Bearer $TOKEN" -d '{"amount_cents":5000,"method":"card"}')
check "partial payment keeps the invoice open" "$(echo "$PAY1" | jq -r .invoice_status)" "open"
PAY2=$(curl -s $J -X POST "$EHR_URL/api/invoices/$IID/payments" -H "Authorization: Bearer $TOKEN" -d '{"amount_cents":7500,"method":"cash"}')
check "full payment settles it" "$(echo "$PAY2" | jq -r .invoice_status)" "paid"

echo "== honest transmission ledger =="
TX=$(curl -s $J -X POST "$EHR_URL/api/transmissions" -H "Authorization: Bearer $TOKEN" -d "{\"patient_id\":\"$PID\",\"channel\":\"print\",\"kind\":\"prescription\",\"summary\":\"Ibuprofen 400mg PRN\"}")
TXID=$(echo "$TX" | jq -r .id)
check "transmission queued (never 'sent')" "$(echo "$TX" | jq -r .status)" "queued"
DONE=$(curl -s $J -X POST "$EHR_URL/api/transmissions/$TXID/complete" -H "Authorization: Bearer $TOKEN")
check "completion is 'printed', not a fabricated send" "$(echo "$DONE" | jq -r .status)" "printed"

echo
echo "RESULT: $PASS passed, $FAIL failed."
[[ $FAIL -eq 0 ]] && echo "E2E: the EHR drove a real vault end-to-end — provisioning, grants, chart, signing onto the chain, billing, honest ledger." || exit 1
