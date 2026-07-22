# Build Plan — The Open-Source EHR Application

*Status: planned, not coded. This is the largest single build in the project —
measured in months. The plan's job is to make it a sequence of demoable,
test-enforced increments rather than a monolith that's "90% done" forever.*

> **Scope revision (2026-07-22).** The **backend** of this plan — practice-ops APIs
> (patients, scheduling, encounters/notes, documents, intake, cash-pay billing) —
> is confirmed open-source and is tracked as milestone **F4** in the ROADMAP.
> The **UI milestones in this document are superseded**: client applications are a
> separate layer above the public APIs. Our own client is a commercial product;
> anyone is free to build a client of their own against the same APIs, and the
> APIs this plan defines are the contract both kinds of client get. E1–E4's
> screen-level content should be read as *what a client needs the backend to
> support*, not as a commitment to ship that UI here.

## What it is

The practice-facing application built **around** the vault server: patients,
charting, scheduling, encounters/notes, documents, intake, and cash-pay billing —
everything a small practice (DPC, cash-pay, behavioral health, small specialty)
needs to *run*, free and open source. The record layer underneath is the vault;
the EHR is the first full **Contributor + Custodian** consumer of the standard.

## Architecture decision (made): separate app, API-only coupling

```
┌────────────────────────┐        HTTP (the public OPR + FHIR APIs only)
│  EHR app (Laravel)     │ ◀──────────────────────────────────────────┐
│  practice-ops DB:      │                                            │
│  schedules, tasks,     │        ┌──────────────────────────────┐    │
│  invoices, forms,      │        │  Vault server (this repo)    │────┘
│  practice users/roles  │        │  vaults, entries, grants,    │
│  (= the ProviderCopy)  │        │  audit, FHIR, witness        │
└────────────────────────┘        └──────────────────────────────┘
```

- The EHR **never touches the vault database**. It reads and commits through the
  same public API any competitor would use. This is the governance charter's
  same-rails commitment made structural — and it means every gap the EHR build
  hits is, by definition, a gap in the public API that gets fixed publicly.
- The EHR's own database holds *operational* practice data (scheduling, billing,
  tasks, form templates, practice staff + roles). Clinical facts live in vaults;
  the EHR caches read models where needed but the vault is canonical.
- Stack: Laravel + PostgreSQL (UUID PKs) backend, **Vue 3 SPA** (Vite/TS,
  Tailwind) frontend. Same conventions as the vault server; one contributor
  skill-set covers both.
- Repo layout: `ehr/` sibling of `reference-impl/` in this repository (single
  repo while the API is still co-evolving; splittable later without history
  surgery because coupling is HTTP-only).

## Patient identity model (the one genuinely new design area)

Two flows, both required:

1. **Practice-created (day one):** the practice registers a patient; the vault
   subject account is created in a *practice-managed* state (email optional).
   The practice holds an implicit treatment grant recorded as a real AccessGrant
   (nothing bypasses the grant model — "implicit" means auto-minted at
   registration with the patient's signed intake consent, revocable like any
   grant).
2. **Patient-claimed (the custody story):** the patient later claims the account
   (email verification now; identity-proofing vendor before claiming is offered
   for records already containing sensitive data — flagged as a deployment-policy
   gate). Claiming flips control: the patient now manages grants; the practice's
   access continues under its treatment grant.

## Milestones

Each milestone ends with (a) the full test suite green, (b) an end-to-end script
driving a real running stack (the lesson: unit tests don't catch what E2E does),
and (c) a scripted demo — if it can't be demoed, it isn't done.

### E1 — "The chart" (~4 weeks)
Practice onboarding (org, staff, roles). Patient registration → vault creation +
auto-minted treatment grant. Chart screens: problem list, medications, allergies,
immunizations, vitals — all reading the vault **current view** via FHIR; manual
entry commits with provenance (`manual-entry`) and the committing clinician
recorded. Document upload → `DocumentReference` entries. Superseding corrections
from the UI.
*Demo: register a patient, build their chart, correct a mistake, show the
patient's own vault reflects everything with provenance.*

### E2 — "A day at the clinic" (~4 weeks)
Scheduling: appointment types, provider availability, booking, day/week views.
Encounters: open → SOAP note → sign → committed as a note entry (signed hash,
`verified-source`). Intake: practice-configurable forms; submissions become
entries or operational data per field mapping; consent capture with signature.
In-practice task list. Practice-staff roles enforce minimum-necessary views
(front desk ≠ clinician).
*Demo: book, room, document, and sign a visit; the patient portal side shows it
live.*

### E3 — "Get paid, leave no excuse" (~4 weeks)
Cash-pay billing: fee schedule, invoice/superbill from the signed encounter (CPT +
ICD-10 lines), payment recording, statements. DPC membership billing (recurring).
Print/fax prescription and referral documents — every outbound item lands in an
**honest transmission ledger** (`queued`/`printed`/`faxed` with operator
attribution; nothing ever fabricates "sent" — this pattern is a hard invariant
inherited from production lessons). CSV/statement exports.
*Demo: visit → superbill → recorded payment → month-end export.*

### E4 — "First real practice" (~3 weeks)
Hardening for a pilot: audit-view UI for practices, backup/restore procedure
documented AND rehearsed, `docker-compose` deployment (EHR + vault + Postgres) with
a 30-minute quickstart, seed/demo dataset, accessibility pass (labels, roles,
keyboard), load smoke test. Security review checklist against every route (the
compiled-route gate pattern: any route touching patient data must carry its
authorization check, enforced by a CI script, not by review vigilance).

## Explicit non-goals (stated so nobody "helpfully" fakes them)
- e-prescribing, lab interfaces, claims clearinghouse: **not in the free EHR
  build** — they are contract-gated rails; the honest ledger queues them.
- AI documentation (scribe class): commercial layer; it plugs in through the same
  public APIs, proving the extension surface.
- Inpatient, multi-site enterprise features, ONC certification itself
  (architecture stays certification-*ready*: USCDI-complete chart, FHIR API
  already mandatory at the vault).

## Risks & mitigations
| Risk | Mitigation |
|---|---|
| API-only coupling too slow for chart UX | Read-model caching in the EHR DB keyed on vault chain head (cache valid ⇔ head unchanged — the hash chain doubles as a cache invalidation token) |
| Scope creep toward "big EHR" | Non-goals list above is normative; new scope requires removing something of equal size from the same milestone |
| Solo-maintainer bus factor | Everything demoable + documented per milestone; contributors can enter at any milestone boundary |
