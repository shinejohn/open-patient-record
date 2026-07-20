# Build Plan — Vault Server M3: SMART on FHIR, Passkeys, Bulk Export

*Status: planned, not coded. Decisions below are made (not options); deviations
require a written reason in the PR. Every milestone's acceptance criteria are
tests — a milestone without a failing-then-passing test isn't done.*

## Sequencing & dependencies

```
M3a Passkeys ──────────────┐            (independent)
M3b Bulk $export streaming ┼──▶ M3c SMART on FHIR
                           │    (SMART consent screen reuses passkey session;
                           │     SMART clients commonly probe $export)
```

M3a and M3b are independent and can proceed in parallel. M3c lands last.

Two new runtime dependencies are required and must be approved before work starts
(this project treats dependency additions as decisions, not defaults):

| Dependency | For | Why not hand-rolled |
|---|---|---|
| `web-auth/webauthn-lib` | M3a | WebAuthn attestation/assertion parsing is a security-critical format zoo; hand-rolling it is how reference implementations get CVEs |
| `league/oauth2-server` | M3c | Same argument for OAuth2/PKCE token machinery (it's what Laravel Passport wraps; we use it directly to keep the surface small) |

---

## M3a — Passkeys (WebAuthn)

**Goal:** passwordless patient authentication. Kills phishing and credential
stuffing — the vault's scariest attack is patient account takeover (key-management
doc: the patient's authenticator is one of the few things they cryptographically
hold).

### Schema
`user_credentials`: `id` (uuid PK), `user_id` (FK, RESTRICT), `credential_id`
(base64url, unique), `public_key`, `sign_count` (bigint), `transports` (jsonb),
`aaguid`, `nickname`, `last_used_at`, `created_at`.

### Endpoints
| Route | Behavior |
|---|---|
| `POST /webauthn/register/options` (auth) | Challenge + creation options; residentKey `preferred` (usernameless later), userVerification `preferred` |
| `POST /webauthn/register` (auth) | Verify attestation, store credential; audit `credential.added` on the user's vault if one exists |
| `POST /webauthn/login/options` | Assertion challenge (email-first; discoverable-credential usernameless flow accepted when offered) |
| `POST /webauthn/login` | Verify assertion + sign_count regression check (cloned-authenticator detection → deny + audit); issue standard token |
| `POST /webauthn/credentials/{id}/revoke` (auth) | Remove a credential; audit |

### Decisions
- **Password fallback stays in M3** — removing it is an M4 decision after recovery
  UX is proven. Passkeys are additive first.
- **Recovery:** email magic-link with 24h cooldown + notification + audit event
  (`account.recovered`). Recovery is the attack path passkeys displace — it gets
  the same no-oracle treatment as grant redemption (identical response whether or
  not the email exists).
- **sign_count regression = hard deny**, audited. False positives (some platform
  authenticators always report 0) handled per spec: only enforce when counter was
  ever non-zero.

### Acceptance tests
Registration/assertion round-trip with library-generated fixture authenticators;
sign-count regression denial; revoked credential can't assert; recovery no-oracle;
audit events present. Feature-test count target: ~10.

---

## M3b — Bulk FHIR `$export` (streaming)

**Goal:** spec §7.1's "complete export … Bulk-FHIR-compatible" at scale, without
memory blowups, in the standard async pattern SMART/Bulk clients expect.

### Flow (FHIR Async Request pattern)
1. `GET /fhir/{vault}/Patient/$export` (subject or delegate, `Accept:
   application/fhir+json`, `Prefer: respond-async`) → `202 Accepted` +
   `Content-Location: /fhir/{vault}/$export-status/{job}`.
2. Status endpoint → `202` + `X-Progress` while running → `200` + manifest
   (`output: [{type, url, count}]`, plus an `extension` entry for the OPR
   metadata file) when done.
3. `GET .../$export-file/{job}/{file}` → `application/fhir+ndjson`, one resource
   per line; files are per resource type; plus `opr-metadata.ndjson` (per-entry:
   seq, hashes, verification tier, provenance, supersession) and the chain head —
   so a Bulk export is also a valid §7.2 import source when recombined.
4. `DELETE .../$export-status/{job}` cancels; jobs and files expire after 24h
   (scheduled cleanup).

### Implementation
- `export_jobs` table (uuid PK, vault_id, status, progress, manifest jsonb,
  expires_at); Horizon job iterates `entries()->cursor()` writing ndjson through a
  stream — **at no point is the vault materialized in memory** (acceptance test
  seeds 1,000 entries and asserts peak memory under a fixed bound).
- Files on the `local` disk under a per-job directory; downloads streamed with
  single-use signed URLs; auth identical to export (subject/delegate only).
- Every kickoff audited (`export` action, `context.mode: bulk`).

### Acceptance tests
Kickoff→poll→download happy path; ndjson line validity; manifest counts ==
entry_count; metadata file completeness; import-from-bulk round trip equals the
JSON export round trip; memory bound; expiry cleanup; stranger/grant-token denied.
Target: ~9.

---

## M3c — SMART on FHIR (standalone launch, read-only v1)

**Goal:** any SMART-capable app (the Apple Health Records class) connects to a
vault using the industry-standard flow — no OPR-proprietary client code.

### The one load-bearing design decision
**A SMART authorization IS an AccessGrant.** The consent screen is a grant-minting
UI; approving an app mints a grant (purpose `personal-share`, scope mapped from
requested SMART scopes); access/refresh tokens are *derived from the grant*
exactly like OTP-redeemed tokens are today. Consequences, all deliberate:
- Revoking the grant revokes the app — one revocation model, already built and
  tested, patient-visible in the same UI.
- SMART scope enforcement rides the existing `visibleEntries` filtering; sensitive
  categories remain opt-in per grant regardless of what scopes the app asks for.
- The audit trail shows app accesses like any other grant accesses.

### Surface
| Piece | Detail |
|---|---|
| Discovery | `GET /fhir/{vault}/.well-known/smart-configuration` + `capabilities` in the CapabilityStatement (`launch-standalone`, `client-public`, `client-confidential-symmetric`, `permission-patient`) |
| Client registration | Manual/API registration of OAuth clients (`oauth_clients`: redirect URIs, confidential?, PKCE required for public clients — always) |
| Authorize | `GET /oauth/authorize` → login (passkey) → consent screen listing requested scopes mapped to plain-English record sections + explicit sensitive-category checkboxes (default OFF) → mints grant |
| Token | `POST /oauth/token` — authorization-code + PKCE; refresh tokens bound to the grant; access-token TTL ≤ the existing 30-min derived-token cap |
| Scopes v1 | `patient/<Type>.read` + `patient/*.read` (SMART v1 style; v2 `.rs` accepted as synonyms), `offline_access`, `openid fhirUser` |
| ID token | Minimal OIDC id_token with `fhirUser` = the vault's Patient resource URL |

### Explicit non-goals (v1)
EHR-launch context (no EHR to launch from yet), write scopes (Contributor apps
come with the EHR app work), backend-services/system scopes (custodian-to-custodian
comes later), dynamic client registration.

### Acceptance tests
Full authorization-code+PKCE flow black-box (code interception fails without
verifier); scope→grant mapping including sensitive-default-off; refresh; grant
revocation kills refresh+access immediately; discovery document contents; and an
**Inferno smoke run** (ONC's public SMART test kit) against a local instance —
result recorded in the PR, failures triaged to spec-vs-implementation.

---

## Estimate & order of work

| Milestone | Estimate (focused sessions) |
|---|---|
| M3a passkeys | 2–3 |
| M3b bulk export | 2–3 |
| M3c SMART | 4–6 (auth server + consent UI + Inferno triage) |

All three keep the invariants that are now house law: fail-closed authorization,
no-oracle error surfaces, every access audited, DB-enforced append-only, UTC
timestamps, and red-before-green tests.
