# OPR Vault Server — Reference Implementation

The official open-source **vault server**: the software a custodian runs to host
patients' individual vaults, implementing [`spec/custody-layer.md`](../spec/custody-layer.md).
(Plain-English terms: [`GLOSSARY.md`](../GLOSSARY.md).)

**Status: M2-in-progress — custody core + FHIR read surface, test-enforced.** 40
tests / 268 assertions, run against real PostgreSQL in CI on every push.

## What M1 implements

| Spec section | Implemented as |
|---|---|
| §2 custody model | One vault per subject; supersession (`replaces_entry_id`), never mutation; cross-vault supersession rejected |
| §3 AccessGrants | Subject-only minting; purposes; scoped read/write; 8-digit one-time secret (hash stored, plaintext shown once); no-oracle redemption (identical failure payload, dummy-hash timing defense); max-uses + expiry under row lock; revocation kills derived tokens **immediately**; fail-closed everywhere |
| §3.2 sensitive categories | Excluded from grants by default; explicit per-category opt-in; **unknown category = sensitive**, provably |
| §4 integrity | Append-only via PG row triggers + TRUNCATE statement triggers + RESTRICT FKs + model guards; per-vault SHA-256 hash chain over canonicalized payloads; `verify` endpoint detects tampering (tested by literally disabling the trigger and rewriting history) |
| §4.5 anchoring | Chain head in every entries/sync response and export |
| §5 provenance | Mandatory on every commit (organization required) |
| §6 audit | Append-only audit events for every access; denied redemptions record the true reason in audit only; subject reads their full access history free |
| §7.1 export | Complete export: entries, provenance, audit, chain head |
| FHIR R4 read surface | **Every vault is its own FHIR base URL** (`/api/fhir/{vault}`): `CapabilityStatement` at `/api/fhir/metadata`, `Patient/$everything`, type search, single-resource read. Verification tier rides `meta.tag` (`urn:opr:verification-tier`) so consumers can exclude `unverified-import` from decision support (§4.3). Current-view semantics (superseded entries excluded from searches; history stays in the export). Grant scope + sensitive filtering + audit apply identically; invisible = nonexistent (404, no oracle); mutation gets the §4.1 `OperationOutcome`. |

## Not yet (M2 remainder +)

Round-trip import (§7.2) and custodian migration (§7.3), the black-box conformance
runner extracted from the test suite, SMART-on-FHIR launch/scopes interop,
ShareSessions, break-glass flow, per-patient envelope encryption, witness-log
publishing, passkey auth.

## Run it

Requirements: PHP 8.3+, Composer, PostgreSQL 14+.

```bash
cd reference-impl/server
composer install
cp .env.example .env && php artisan key:generate
createdb opr_vault          # dev database
php artisan migrate
php artisan serve           # API at http://localhost:8000/api
```

Tests (PostgreSQL required — the append-only triggers ARE the product; there is no
sqlite mode by design):

```bash
createdb opr_vault_test
./vendor/bin/phpunit        # defaults: postgres/postgres@127.0.0.1
# or override: DB_USERNAME=$(whoami) DB_PASSWORD= ./vendor/bin/phpunit
```

## Design notes

- **The database enforces the spec, not just the app.** UPDATE/DELETE/TRUNCATE on
  committed entries or audit rows fail at the PostgreSQL layer even for buggy or
  malicious application code. The tests prove tamper *detection* too: they disable
  the trigger, rewrite history, and assert verification catches it.
- **`DB_TIMEZONE=UTC` is load-bearing.** A non-UTC session timezone silently corrupts
  `timestamptz` round-trips — grant expiry drifts by the UTC offset (a test caught
  exactly this during development; it stays pinned in config).
- **Every failure is fail-closed** and every redemption failure returns one identical
  response; the true reason is recorded only in the vault's audit log, where the
  subject can read it.
- Contributions: DCO sign-off (`git commit -s`); tests must accompany any normative
  behavior. A spec MUST without a test is treated as a bug.
