# Design Rationale — What FHIR Provides vs. What OPR Adds

*Position: OPR is a FHIR R4 profile plus a custody layer — not a new clinical format.
FHIR won the wire-format question and is legally mandated in the US via the Cures Act
API requirements. Reinventing the clinical data model would be malpractice. This doc
records exactly where FHIR ends and OPR begins, so the spec stays small.*

## 1. Adopted outright from FHIR R4 (OPR defines nothing here)

| Need | FHIR answer |
|---|---|
| Clinical data model | R4 resources: `Patient`, `Condition`, `MedicationRequest`/`MedicationStatement`, `AllergyIntolerance`, `Observation`, `Immunization`, `Procedure`, `Encounter`, `DocumentReference`, `DiagnosticReport`, `CarePlan` |
| Minimum data floor (US) | USCDI data classes via US Core profiles — bound in a **separate conformance module** so non-US implementations aren't forced through them |
| Terminology | LOINC (labs), SNOMED CT (problems), RxNorm (meds), ICD-10, CVX (immunizations) |
| Wire format / API | FHIR REST + JSON; Bulk FHIR (`$export`) for population/complete export |
| Authorization sessions | SMART on FHIR (OAuth2, scopes) — as the *session* layer beneath OPR grants |
| Document interop | Existing C-CDA ↔ FHIR mapping guidance |
| Attribution primitives | `Provenance`, `AuditEvent`, `Signature` |
| Consent data structure | `Consent` resource — as the *serialization* of an OPR AccessGrant |

## 2. Where FHIR half-answers (OPR profiles, but stays inside FHIR)

1. **Versioning ≠ immutability.** FHIR has `_history` but permits update/delete and
   offers no tamper evidence. OPR constrains: committed entries are append-only,
   content-hashed, per-vault hash-chained. A conformant vault is still a conformant
   FHIR server — one that refuses destructive writes with a defined `OperationOutcome`.
2. **Provenance is optional and freeform.** OPR makes it mandatory and constrained:
   every clinical entry names its contributing organization, author where known, source
   system, and — for imported data — the source document, extraction method, and
   verifier. No anonymous facts in a canonical record.
3. **Consent is a noun, not a verb.** FHIR `Consent` records that consent exists;
   nothing defines what a server must *do* on revocation, how fast, or what a durable
   grant looks like. That protocol is OPR's core contribution (spec §3).
4. **Export exists; completeness is unenforced.** `$export` defines streaming, not
   that the export equals the record. OPR's portability conformance closes this — it
   is, ideologically, the entire point of the standard.
5. **Patient matching.** FHIR punts (no national identifier). OPR dissolves rather
   than solves it: a vault has exactly one subject, and contributors link to the
   vault. Probabilistic cross-vault matching is explicitly out of scope.

## 3. What OPR defines (the spec's five sections)

1. **Custody semantics** — Vault (canonical, patient-controlled) vs. ProviderCopy
   (contributor's operational store; internals out of scope). Contribution, immutable
   commit, supersession-not-mutation (which also maps HIPAA §164.526 amendment rights
   cleanly onto an append-only store). Custody ≠ confiscation: provider retention
   obligations are unaffected, and the spec says so in those words.
2. **AccessGrant / ShareSession protocol** — patient-minted, scoped, expiring,
   revocable capabilities; redemption to short-lived SMART-style tokens whose scopes
   never exceed the grant; bounded revocation latency; ephemeral ShareSessions
   (QR-style) as constrained grants; break-glass as a defined, audited, patient-notified
   grant type; sensitive-category content (e.g., US 42 CFR Part 2) strictly opt-in per
   grant.
3. **Integrity** — append-only commits, hash chain, tamper evidence. Explicitly not a
   blockchain: one custodian, no consensus. Plus a **verification tier** on every entry
   (`verified-source` / `clinician-verified` / `unverified-import`): imported-but-
   unverified data is visibly badged and excluded from decision support until a
   clinician verifies it. Migration safety is a schema concern, not a UI nicety.
4. **Provenance conformance** — the mandatory constraints of §2.2.
5. **Portability conformance** — complete export (every resource, version, provenance
   and audit entry the patient is entitled to), at no charge, within bounded time;
   a public round-trip test (export from custodian A → import at custodian B → semantic
   diff = empty); custodian migration with hash-chain anchoring.

## 4. Design decisions worth recording

- **Conformance levels (Contributor / Custodian / Full)** exist because a standard
  adoptable only in totality gets zero adopters. An existing EHR can add "commit to
  vault + honor grants" (Contributor) without re-platforming.
- **Patient-held encryption keys are permitted, not required.** The maximalist reading
  of patient ownership (custodian cannot decrypt) fails two clinical safety cases:
  key loss destroying a canonical medical record, and emergency access for an
  incapacitated patient. v1 addresses the malicious-custodian threat with portability,
  audit transparency, and conformance instead of cryptography that breaks safety.
  Implementations may offer patient-held-key vaults; conformance doesn't demand it.
- **Unknown sensitive-category = sensitive.** Deny-by-default is uniform: an
  unrecognized category tag must never be treated as shareable.
