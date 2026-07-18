# Open Patient Record (OPR)

**An open standard for patient-owned health records: a custody layer over FHIR R4,
with a reference implementation.**

> Status: **v0.1 draft — pre-implementation.** The spec is being written in the open;
> the reference implementation follows. Nothing here is stable yet. Issues and
> discussion welcome.

## The idea

Provider-held records are a legacy artifact — of old technology limits and of business
models where whoever holds the record controls the patient. The **P in HIPAA stands for
Portability**; the industry built the opposite. Since the 21st Century Cures Act,
information blocking is illegal and every certified EHR must expose patient data over
standard FHIR APIs. The law already freed the data. What's missing is somewhere for it
to live that the *patient* controls.

OPR defines that place:

- **The patient holds the canonical record (the vault).** Providers contribute entries
  to it and keep their own operational copies — custody is not confiscation, and
  provider record-retention obligations are unaffected.
- **Committed entries are immutable**, hash-chained, and carry mandatory provenance —
  who contributed what fact, from where. Corrections supersede; they never overwrite.
- **Access is granted by the patient** through a defined AccessGrant protocol —
  scoped, time-limited, revocable, auditable — not by vendor policy.
- **Portability is a conformance requirement.** A conformant custodian must export the
  complete record — and must let the patient leave, including leaving for a competing
  custodian, with integrity of the record's hash chain preserved.

OPR is **not** a new clinical data format. Clinical content is FHIR R4 (with US Core /
USCDI bindings as a separate conformance module). OPR adds only what FHIR doesn't
define: custody, grant semantics, immutability, provenance requirements, and
portability guarantees. See [`docs/design/fhir-custody-gap-analysis.md`](docs/design/fhir-custody-gap-analysis.md)
for exactly where that line sits. It's also not a blockchain — one custodian, no
consensus, no tokens; just append-only records with tamper-evident hashes.

## Conformance levels

Designed for piecemeal adoption — an existing EHR shouldn't have to re-platform to
participate:

| Level | Meaning |
|---|---|
| **Contributor** | Can commit entries to a vault with required provenance; honors AccessGrants |
| **Custodian** | Hosts vaults: grant minting/redemption/revocation, append-only integrity, audit, full portability |
| **Full** | Both |

Conformance is defined mechanically by the public test suite (in
[`reference-impl/`](reference-impl/), alongside the reference vault). Anything that
passes may use the conformance mark — including competitors of the standard's authors.
That's the point.

## Repository layout

| Path | Contents | License |
|---|---|---|
| [`spec/`](spec/) | The standard: custody model, AccessGrant/ShareSession protocol, integrity + provenance + portability conformance | [CC0-1.0](spec/LICENSE) |
| [`reference-impl/`](reference-impl/) | Reference vault server + conformance test suite (in progress) | [Apache-2.0](LICENSE) |
| [`docs/design/`](docs/design/) | Design rationale | Apache-2.0 |
| [`GOVERNANCE.md`](GOVERNANCE.md) | How the spec changes, and the neutrality commitments | — |

## What's open and what isn't (said plainly)

The record layer — spec, reference implementation, conformance suite — is open and
stays open (CC0 / Apache-2.0, DCO inbound so it cannot be quietly relicensed, by us or
anyone). The authors (Fibonacco / 4doctors.ai) build commercial products *on top of*
this layer — hosting, migration services, AI clinical tools — through the same public
APIs defined here, with no privileged private extensions. Visible monetization above an
open commons, not hidden monetization inside it. Details in
[`GOVERNANCE.md`](GOVERNANCE.md).

## Contributing

Spec discussion via issues/RFCs (see GOVERNANCE.md for the change process).
Code contributions: DCO sign-off required (`git commit -s`). No CLA, ever — that's a
feature, not an oversight.

## Naming note

"OPR / Open Patient Record" is a working name. It is deliberately not "openEHR" —
[openEHR](https://openehr.org) is a long-established and unrelated clinical modeling
standard; we use FHIR R4 for clinical content and defer to existing terminologies
(LOINC, SNOMED CT, RxNorm, CVX) throughout.
