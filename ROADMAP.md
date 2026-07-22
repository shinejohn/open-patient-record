# OPR — Roadmap & Honest Status

*Updated 2026-07-22. This document deliberately overstates nothing. Where something
is not built, it says so. Every "done" claim below is enforced by an automated test
you can run yourself.*

> **2026-07-22 correction.** Earlier revisions listed "FHIR R4 read surface" under Done in a way
> that read as "the FHIR layer is done." It is not — see **Built but partial** below for exactly
> what is missing. The custody milestones (M1–M3) were complete; no milestone had ever been defined
> for the FHIR *clinical* layer, so "M3 complete" was true and still misleading. This revision adds
> that milestone (F1–F3) instead of leaving the gap unnamed.

## How to see the state of the project in 5 minutes

```bash
cd reference-impl/demo && ./demo.sh
```

One command: boots a real vault server on a scratch database and walks the entire
patient-owned-record story — permissioned contribution, personal FHIR endpoint, QR
sharing, break-glass, live tamper detection, full custodian migration with chain
anchoring, the public witness log — then certifies the same server with the
black-box conformance runner. Everything you see is real HTTP against real code.

## ✅ Done (test-enforced; 100 tests / 814 assertions in CI + 19 black-box conformance checks)

| Area | State |
|---|---|
| **Custody-layer spec v0.1-draft** | Published (CC0): custody model, AccessGrant/ShareSession protocol, integrity + verification tiers, provenance, portability conformance, anchoring |
| **Governance** | Charter published: DCO-not-CLA (structurally blocks relicensing — including by us), conformance-mark policy, automatic steering-group trigger at the second implementer |
| **Vault server: custody core** | Append-only hash-chained storage (DB-level triggers incl. TRUNCATE guard), supersession-never-mutation, per-vault envelope encryption (one leaked key = one vault), mandatory provenance |
| **Grants** | Subject-only minting, purposes, scoped read/write, sensitive-category opt-in (unknown = sensitive, provably), no-oracle redemption, immediate revocation of derived tokens, fail-closed throughout |
| **ShareSessions** | QR flow: read-only, ≤60 min, single redemption — server-enforced |
| **Break-glass** | Recorded identity + substantive reason, sensitive excluded, emergency-flagged in the audit trail the patient reads |
| **Delegation** | Guardians/proxies with subject-equivalent authority; delegate management never delegates; full attribution in audit |
| **FHIR R4 read surface (partial — see below)** | Every vault is its own FHIR base URL; `$everything`; verification tier as `meta.tag`. **Read-only, no search parameters, no profile validation** — the honest detail is in *Built but partial*. |
| **Portability** | Complete export; round-trip import with every hash recomputed; tamper-in-transit rejected atomically; custodian migration anchored on the source chain head |
| **Public witness** | Daily signed Merkle digest of all chain heads; per-vault inclusion proofs; dual-signed key-rotation rollover |
| **Conformance runner** | Dependency-free black-box checks against ANY implementation; this server passes: 16/16 MUST + 3/3 SHOULD. **Scope: custody semantics only** — it does not test FHIR resource conformance (that arrives with F1). |
| **Bulk FHIR `$export`** | Async pattern, streamed ndjson + OPR metadata + chain head, memory-bounded, 24h expiry |
| **Passkeys (WebAuthn)** | Passwordless auth, cloned-authenticator detection, no-oracle login + recovery |
| **SMART on FHIR** | Standalone launch, auth-code+PKCE, RS256 id_token; a SMART authorization *is* an AccessGrant (one consent/revocation/audit model) |

## ✅ Also done — Legacy Gateway G0 (deterministic ingestion)

Runnable, test-enforced (14 tests / 82 assertions + a live end-to-end demo):
FHIR-bundle and C-CDA parsers → human verification (medication reconciliation,
completeness accounting, no silent drops) → commit into a vault via the public
API. No AI, no BAAs. `cd gateway/demo && ./gateway-demo.sh` ingests a C-CDA and an
Apple Health export into one patient's vault and proves the merged, verified,
provenance-linked chart. AI narrative extraction (G1) is the next tier, BAA-gated.

## ⚠️ Built but partial — the FHIR clinical layer

The custody engine underneath is done and test-enforced. The FHIR surface over it is
**FHIR-shaped, not yet FHIR-conformant**. Missing today, stated plainly:

- **No FHIR write API.** Contributions go through the native OPR envelope
  (`POST /api/vaults/{vault}/entries`); a stock FHIR client cannot POST a resource.
- **No search parameters** (`_id`, `_lastUpdated`, `_count`, `patient`, `code`, `date`, …)
  and no pagination — a type search returns the entire current view.
- **No resource validation.** `resource_type` is a free string; payloads are not checked
  against FHIR structures.
- **CapabilityStatement declares no `resource` array** — it does not yet advertise what the
  server supports.
- **No US Core profiles, no `meta.profile`, no USCDI mapping.**
- **No terminology service** — no RxNorm/LOINC/SNOMED/CVX/ICD-10 tables or importers
  (the Gateway's `Terminology` class validates code *shape* only).
- **Patient demographics are synthesized** from the account name; Encounter, Procedure,
  DocumentReference and CarePlan are unexercised.

## 🔨 In progress — making it one complete open piece (F1–F3)

Decision (2026-07-22): **the open-source scope is the full EHR backend** — vault + FHIR +
Gateway + practice-operations APIs, one piece that a practice can actually run. Client
applications (UIs) are a separate layer; ours is commercial, and anyone else is free to
build their own against the same public APIs — which is the point.

| Milestone | Contents |
|---|---|
| **F1 — FHIR layer becomes real** | ✅ *Landed 2026-07-22:* FHIR create (`POST /fhir/{vault}/{type}`) — 12 registry-supported types (incl. Encounter, Procedure, DocumentReference, CarePlan), R4 required/choice-element validation, server-assigned ids, actor-derived verification tier (subject → unverified-import, grant system → verified-source), sensitive-tag intake, provenance from the authenticated actor, CapabilityStatement now enumerates resources + interactions. Every FHIR write rides the one hash-chained commit path. **Remaining:** transaction Bundles, search parameters + pagination, real Patient demographics, US Core profile stamping |
| **F2 — Terminology service** | RxNorm / LOINC / SNOMED CT / CVX / ICD-10 importers (NLM/CDC releases), `$validate-code`, `$lookup` |
| **F3 — Gateway as one piece** | Gateway ships and deploys with the server; then **continuous sync + the write-side** — the live bridge that reads history from an incumbent EHR while all new data lands here, so replacement is a parallel run ended by archiving the incumbent in place, never a cutover |
| **F4 — Practice-operations backend** | Scheduling, encounter workflow, cash-pay billing, tasks, intake — open, per the [EHR application plan](docs/plan/ehr-application.md)'s backend scope (its UI milestones are superseded: clients are a separate layer) |

## 🔨 Planned — after F1–F4

- **Gateway G1** — AI-assisted narrative extraction. Additionally gated by a signed
  model-vendor BAA and ships fail-closed OFF until then.

## 🤝 Requires partners/process — no code closes these

- Business Associate Agreements with model, hosting, storage, fax, and identity-proofing vendors (**0 signed today**; AI features run fail-closed OFF until then, by design)
- E-prescribing (Surescripts + EPCS), lab interfaces, clearinghouse enrollment, ONC certification — each is a contract + certification program; none are faked in code (transmission without a live rail lands in an honest queue, never a fabricated "sent")
- Independent third-party penetration test; formal security-officer designation; production hosting under a HIPAA BAA

## 🧭 Deliberate non-goals

No blockchain. No cross-vault patient matching. No CLA. No private API tier for the
standard's authors — our commercial products consume the same public surface
documented here (GOVERNANCE.md §4).
