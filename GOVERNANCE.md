# OPR Governance Charter (v0.1)

This charter states how the Open Patient Record standard is governed, and the
commitments that make its neutrality real rather than rhetorical. It binds the
project's originator (Fibonacco / 4doctors.ai, "the originator") first and most.

## 1. Licenses — irrevocable by construction

- **Spec text:** CC0-1.0. Implementable by anyone, for any purpose, with zero legal
  diligence and no attribution requirement.
- **Reference implementation & conformance suite:** Apache-2.0, chosen for its explicit
  patent grant and patent-retaliation clause.
- What has been published under these licenses stays published. There is no mechanism —
  and by design can be no mechanism — to retract or relicense released versions.

## 2. Contributions — DCO, never a CLA

All code and spec contributions require a Developer Certificate of Origin sign-off
(`git commit -s`). There is no Contributor License Agreement and there will not be one.
Consequence, stated deliberately: because contributors retain their copyrights under
inbound=outbound terms, **the originator cannot unilaterally relicense the aggregate
work** — the failure mode by which past open healthcare infrastructure (e.g. Mirth
Connect) was closed is structurally unavailable here, including to us.

## 3. Conformance and the mark

- The conformance test suite is public and mechanically defines what "OPR Conformant"
  means at each level (Contributor / Custodian / Full).
- The name and conformance mark are held by the originator and licensed at no cost,
  under published non-discriminatory terms, to **any implementation that passes the
  public suite** — explicitly including competitors of the originator.
- The suite, not marketing, is the arbiter. An implementation that fails the
  portability requirements is not conformant no matter whose logo is on it — again,
  including ours.

## 4. Same-rails commitment

The originator's commercial products consume vaults exclusively through the public API
surface defined in the spec. No private extensions, no privileged tier. The reference
implementation's API surface **is** the spec's; divergence is a bug in one of them and
is treated as such publicly.

## 5. Portability is reflexive

The core promise of the standard — the patient can always take the complete record and
leave — applies to every conformant custodian, including any custodian service operated
by the originator. Conformance requires supporting migration *to a competing custodian*
with record integrity (hash-chain anchoring) preserved.

## 6. Change process

- Spec changes happen in public: RFC issues, a published comment window (minimum 14
  days for normative changes), versioned releases under semver. Breaking changes
  require a major version and a published migration note.
- **Stage 0 (current):** the originator is the maintainer and final arbiter, under
  this charter's constraints. Pretending a multi-party foundation exists before a
  second implementer does would be theater; this charter is the honest alternative.
- **Stage 1 (automatic trigger):** when a second independent implementation passes
  Custodian-level conformance, a technical steering group forms — one seat per
  independent conformant implementer plus one for the originator; normative spec
  changes then require a majority. From the second external seat onward the originator
  is a structural minority. This trigger is a standing offer, not a discretionary one.
- **Stage 2 (stated intent):** if the standard achieves multi-implementer adoption at
  scale, the spec and mark move to a neutral home (a public-health-oriented foundation
  or an HL7-affiliated process for the FHIR-profile components). Intent recorded here
  so it can be held against us.

## 7. Scope discipline

The spec covers record custody: vault semantics, grants, integrity, provenance,
portability. It does not and will not absorb EHR product features (scheduling, billing,
documentation UX), e-prescribing/lab/claims rails, or cross-vault patient matching.
Scope creep is how standards die; scope objections are always in-order in the RFC
process.
