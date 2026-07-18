# Open Patient Record — Custody Layer Specification

**Version 0.1-draft · Status: pre-implementation working draft · License: CC0-1.0**

The key words MUST, MUST NOT, SHOULD, MAY are to be interpreted as in RFC 2119.
Clinical content representation is FHIR R4; nothing in this spec redefines clinical
data structures. Rationale lives in `docs/design/`; this document is normative.

---

## 1. Definitions

- **Vault** — the canonical health record of exactly one **Subject** (the patient).
  A set of immutable, hash-chained **Entries** plus their Provenance and audit records,
  under the control of the Subject (or their lawful delegate).
- **Custodian** — a system hosting Vaults and enforcing this spec's grant, integrity,
  audit, and portability requirements.
- **Contributor** — a system (typically a provider's EHR) that commits Entries to a
  Vault and holds its own operational copy of clinical data (**ProviderCopy**).
  ProviderCopy internals are out of scope.
- **Entry** — one committed FHIR R4 resource (or contained bundle) plus its OPR
  metadata (§4.2).
- **AccessGrant** — a Subject-authorized, scoped, expiring, revocable capability to
  read from and/or write to a Vault (§3).
- **ShareSession** — an ephemeral, pre-scoped, read-only AccessGrant (§3.6).

## 2. Custody model

2.1 A Vault MUST have exactly one Subject. Cross-vault or probabilistic patient
matching is out of scope of this specification.

2.2 The Subject controls grant minting and revocation for their Vault. A Custodian
MUST NOT disclose Vault content except under an in-effect AccessGrant, a ShareSession,
break-glass access per §3.7, or the Subject's own authenticated access.

2.3 Custody does not alter contributors' independent legal record-retention
obligations. A Contributor retains its ProviderCopy regardless of grant status.
Nothing in this spec requires a Contributor to delete operational records.

2.4 Committed Entries are part of the canonical record and MUST NOT be modified or
deleted. Corrections are made by committing a superseding Entry that references the
superseded Entry (FHIR `replaces` linkage / `Provenance`). Subject amendment requests
(e.g., HIPAA §164.526) MUST be implemented as supersession, never mutation.

2.5 **Conformance levels.**
- **Contributor conformance:** §4.2 (entry metadata), §5 (provenance), §3 grant
  honoring for its reads/writes.
- **Custodian conformance:** §3 (grants), §4 (integrity), §5 (provenance enforcement),
  §6 (audit), §7 (portability).
- **Full conformance:** both.

## 3. AccessGrant protocol

3.1 **Minting.** Only the Subject (or lawful delegate) may mint an AccessGrant. A
grant MUST specify: grantee (organization, individual, or bearer), scope (FHIR resource
types and/or record sections), permissions (`read`, `write`), expiry, and maximum uses.
A grant MUST be serializable as a profiled FHIR `Consent` resource.

3.2 **Sensitive categories.** Entries tagged with a sensitive category (jurisdictional
list; in the US at minimum: substance-use-disorder records under 42 CFR Part 2, mental
health, HIV, reproductive health, genetic data) MUST be excluded from a grant's scope
unless that category is explicitly included in the grant. An unrecognized category tag
MUST be treated as sensitive and excluded. Jurisdictions with single-use consent
requirements (42 CFR Part 2) MUST be honored with single-use grant semantics: the
authorizing consent is consumed atomically at redemption.

3.3 **Redemption.** Grants are redeemed for short-lived access tokens (SMART-on-FHIR
compatible). Token scopes MUST be derived from and MUST NOT exceed the grant. Token
lifetime MUST NOT exceed 30 minutes. Redemption endpoints MUST be rate-limited, MUST
store only hashed grant secrets, and MUST NOT expose an enumeration or timing oracle
distinguishing "unknown grant" from "wrong secret."

3.4 **Revocation.** The Subject may revoke any grant at any time. On revocation a
Custodian MUST refuse new redemptions immediately and MUST invalidate outstanding
derived tokens within 5 minutes. Revocation MUST be recorded in the audit log.
Previously synced copies at the grantee are outside the Custodian's control; this spec
does not pretend otherwise.

3.5 **Fail-closed.** Any error while evaluating a grant, scope, or sensitive-category
rule MUST result in denial.

3.6 **ShareSession.** A ShareSession is an AccessGrant constrained to: read-only,
pre-selected scope, lifetime ≤ 60 minutes, single redemption. Intended for
point-of-care handoff (e.g., QR presentation).

3.7 **Break-glass.** A Custodian MAY support emergency access as a distinct grant type
requiring: recorded accessor identity, recorded reason, mandatory audit flagging as
emergency access, and notification to the Subject. Break-glass access MUST NOT include
sensitive-category entries unless the jurisdiction's emergency provisions apply.

## 4. Integrity

4.1 A Custodian MUST reject in-place update or delete of committed Entries at every
interface, including its FHIR surface (`PUT`/`DELETE` on committed clinical resources
MUST fail with a defined `OperationOutcome`).

4.2 **Entry metadata.** Every Entry MUST carry: a content hash (SHA-256 of its
canonical serialization); the hash of the Vault's preceding Entry (forming a per-vault
hash chain); commit timestamp; contributor identity; and a **verification tier**:
`verified-source` (committed by the originating clinical system),
`clinician-verified` (imported content verified by an identified clinician), or
`unverified-import` (imported content not yet verified).

4.3 Consuming implementations MUST visibly distinguish `unverified-import` Entries and
MUST exclude them from automated clinical decision support (including interaction
checking) until verified.

4.4 The hash chain provides tamper *evidence*, not distributed consensus. This
specification does not define, require, or endorse any blockchain mechanism.

## 5. Provenance

Every Entry MUST have an associated FHIR `Provenance` naming: contributing
organization; author, where known; source system; and, for Entries derived from
imported material: a reference to the immutably retained source artifact, the
extraction method (`structured-parse`, `ai-extraction`, or `manual-entry`), and the
verifier's identity for `clinician-verified` Entries.

## 6. Audit

A Custodian MUST record every Vault access — Subject, grantee, break-glass, and
administrative — as an append-only audit event (FHIR `AuditEvent` compatible)
capturing accessor, grant/authority relied upon, scope touched, and timestamp. Audit
stores MUST be protected against update, delete, and truncation. The Subject MUST be
able to review the access history of their own Vault at no charge.

## 7. Portability

7.1 A Custodian MUST provide the Subject a complete export of their Vault — every
Entry, every version, all Provenance, and all audit events the Subject is entitled to —
in machine-readable form (Bulk-FHIR-compatible plus OPR metadata), at no charge, within
72 hours of request.

7.2 **Round-trip conformance.** Export from one conformant Custodian MUST import into
another with no loss of semantic content, verification tiers, or provenance. The public
conformance suite tests this mechanically; failing it is failing custodianship.

7.3 **Custodian migration.** A Custodian MUST support the Subject's transfer of their
Vault to another custodian, with the receiving vault anchoring the terminal hash of the
source chain so record integrity survives the move. This requirement applies to every
Custodian, including any operated by this specification's authors.

---

*Changes to this document follow the process in `GOVERNANCE.md`. Discussion via RFC
issues. Version 0.1-draft; nothing is stable until 1.0.*
