# OPR — Roadmap & Honest Status

*Updated 2026-07-19. This document deliberately overstates nothing. Where something
is not built, it says so. Every "done" claim below is enforced by an automated test
you can run yourself.*

## How to see the state of the project in 5 minutes

```bash
cd reference-impl/demo && ./demo.sh
```

One command: boots a real vault server on a scratch database and walks the entire
patient-owned-record story — permissioned contribution, personal FHIR endpoint, QR
sharing, break-glass, live tamper detection, full custodian migration with chain
anchoring, the public witness log — then certifies the same server with the
black-box conformance runner. Everything you see is real HTTP against real code.

## ✅ Done (test-enforced; 88 tests / 723 assertions in CI + 19 black-box conformance checks)

| Area | State |
|---|---|
| **Custody-layer spec v0.1-draft** | Published (CC0): custody model, AccessGrant/ShareSession protocol, integrity + verification tiers, provenance, portability conformance, anchoring |
| **Governance** | Charter published: DCO-not-CLA (structurally blocks relicensing — including by us), conformance-mark policy, automatic steering-group trigger at the second implementer |
| **Vault server: custody core** | Append-only hash-chained storage (DB-level triggers incl. TRUNCATE guard), supersession-never-mutation, per-vault envelope encryption (one leaked key = one vault), mandatory provenance |
| **Grants** | Subject-only minting, purposes, scoped read/write, sensitive-category opt-in (unknown = sensitive, provably), no-oracle redemption, immediate revocation of derived tokens, fail-closed throughout |
| **ShareSessions** | QR flow: read-only, ≤60 min, single redemption — server-enforced |
| **Break-glass** | Recorded identity + substantive reason, sensitive excluded, emergency-flagged in the audit trail the patient reads |
| **Delegation** | Guardians/proxies with subject-equivalent authority; delegate management never delegates; full attribution in audit |
| **FHIR R4 read surface** | Every vault is its own FHIR base URL; `$everything`; verification tier as `meta.tag`; CapabilityStatement |
| **Portability** | Complete export; round-trip import with every hash recomputed; tamper-in-transit rejected atomically; custodian migration anchored on the source chain head |
| **Public witness** | Daily signed Merkle digest of all chain heads; per-vault inclusion proofs; dual-signed key-rotation rollover |
| **Conformance runner** | Dependency-free black-box checks against ANY implementation; this server passes: 16/16 MUST + 3/3 SHOULD |
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

## 🔨 Planned — detailed build plans exist, code does not

- **The open-source EHR application** — [full plan](docs/plan/ehr-application.md):
  separate app coupled to the vault by the public API only (the same-rails
  commitment made structural), practice-ops DB for scheduling/billing/tasks,
  milestones E1–E4 (~15 weeks), each ending in a runnable demo and an E2E script.
- **Legacy Gateway** (record ingestion from existing EHRs) — product spec and
  engineering plan complete (maintained privately; it's the commercial layer).
  Deterministic-parse phase is buildable now with zero external dependencies;
  AI-assisted extraction is additionally gated by a signed model-vendor BAA and
  ships fail-closed OFF until then.

## 🤝 Requires partners/process — no code closes these

- Business Associate Agreements with model, hosting, storage, fax, and identity-proofing vendors (**0 signed today**; AI features run fail-closed OFF until then, by design)
- E-prescribing (Surescripts + EPCS), lab interfaces, clearinghouse enrollment, ONC certification — each is a contract + certification program; none are faked in code (transmission without a live rail lands in an honest queue, never a fabricated "sent")
- Independent third-party penetration test; formal security-officer designation; production hosting under a HIPAA BAA

## 🧭 Deliberate non-goals

No blockchain. No cross-vault patient matching. No CLA. No private API tier for the
standard's authors — our commercial products consume the same public surface
documented here (GOVERNANCE.md §4).
