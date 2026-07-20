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

## ✅ Done (test-enforced; 66 tests / 509 assertions in CI + 19 black-box conformance checks)

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

## 🔨 In design / next to build (code exists for none of this yet)

- **SMART-on-FHIR interop** — so existing health apps connect with standard launch/scopes
- **Passkey authentication** for patient accounts
- **Bulk FHIR `$export` streaming** for large vaults
- **The open-source EHR application** around this record layer (charting UI, scheduling, intake, documents) — the largest remaining build, measured in months
- **Legacy Gateway** (record ingestion from existing EHRs: C-CDA/document parsing, terminology normalization, clinician verification workflow) — specified in detail; deterministic-parse pipeline buildable now; AI-assisted extraction additionally gated by a signed model-vendor BAA

## 🤝 Requires partners/process — no code closes these

- Business Associate Agreements with model, hosting, storage, fax, and identity-proofing vendors (**0 signed today**; AI features run fail-closed OFF until then, by design)
- E-prescribing (Surescripts + EPCS), lab interfaces, clearinghouse enrollment, ONC certification — each is a contract + certification program; none are faked in code (transmission without a live rail lands in an honest queue, never a fabricated "sent")
- Independent third-party penetration test; formal security-officer designation; production hosting under a HIPAA BAA

## 🧭 Deliberate non-goals

No blockchain. No cross-vault patient matching. No CLA. No private API tier for the
standard's authors — our commercial products consume the same public surface
documented here (GOVERNANCE.md §4).
