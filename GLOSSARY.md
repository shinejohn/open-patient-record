# Glossary — Plain-English Definitions

Every term this project uses, defined once. If a term isn't here, it shouldn't be in
the docs. (Normative definitions for implementers live in `spec/custody-layer.md` §1;
this file is the human-readable version.)

| Term | Plain meaning |
|---|---|
| **Vault** | One individual's complete health record, stored in one place they control. One person, one vault. |
| **Subject** | The person the vault is about — the patient. The vault's owner. |
| **Entry** | One fact committed into a vault: a diagnosis, a medication, a lab result, a visit note, a document. Once committed, an entry is permanent — corrections are made by adding a newer entry that supersedes it, never by editing history. |
| **Custodian** | An organization that runs vault servers and safeguards vaults — enforcing the owner's access decisions, keeping the tamper-evident history, and guaranteeing the owner can always export everything and leave. |
| **Contributor** | A system that feeds entries into a vault — typically a provider's EHR. Contributors keep their own working copies (see ProviderCopy); contributing to the vault doesn't change their own record-keeping obligations. |
| **Vault server** | The software a custodian runs to host vaults. This repository publishes the official open-source one. |
| **Reference implementation** | Standards jargon for "the official working software that proves the standard": the published vault server that does everything the spec requires, which anyone can run, copy, or test their own software against. |
| **ProviderCopy** | A provider's own operational copy of clinical data for a patient. Clinical work happens there day-to-day; it syncs with the vault. |
| **AccessGrant** | A permission slip the patient creates: *this* organization or person may see (or add to) *these* parts of my record, for *this* purpose, until *this* date — revocable at any time. |
| **ShareSession** | A short-lived, read-only AccessGrant for point-of-care moments — e.g., showing a QR code at a new doctor's check-in that opens your history for minutes, then expires. |
| **Break-glass** | Emergency access when the patient can't consent (e.g., unconscious in the ER): allowed, but always with recorded identity and reason, flagged in the audit log, and reported to the patient afterward. |
| **Purpose** | What a grant is *for*: treatment, personal sharing, research, emergency, or operations. A grant made for one purpose cannot be used for another. |
| **Sensitive category** | Record types the law protects extra strongly (substance-use treatment, mental health, HIV, reproductive health, genetic data). Excluded from every grant unless the patient explicitly includes them. Anything unrecognized is treated as sensitive. |
| **Verification tier** | How trustworthy an entry is marked: came straight from the clinical system that created it; imported and then verified by a clinician; or imported and not yet verified (visibly badged, and excluded from automated clinical decision-making until checked). |
| **Provenance** | The mandatory "where did this fact come from" attached to every entry: which organization, which author, which source system — and for imported data, which document and who verified it. |
| **Hash chain** | The tamper-evidence mechanism: every entry carries a cryptographic fingerprint that includes the previous entry's fingerprint, so altering any past entry breaks every fingerprint after it. Like a wax seal on each page that also seals the page before it. Not a blockchain — one custodian, no cryptocurrency, no consensus machinery. |
| **Chain head** | The newest fingerprint in a vault's hash chain — a single value that effectively summarizes the entire history. |
| **Anchoring** | Publishing the chain head where the custodian can't retroactively change it (the patient's own device, every export, a public daily digest), so even the custodian can't secretly rewrite history. |
| **Portability** | The enforceable guarantee that the patient can take their complete vault — every entry, all provenance, the full access history — and move to a different custodian, free, promptly, with the tamper-evident history intact. |
| **Conformance suite** | The public, automated test battery that defines what "implements this standard" means. Software either passes it or it doesn't — no marketing department involved. |
| **Conformance levels** | How much of the standard a system implements: **Contributor** (can feed vaults properly), **Custodian** (can host vaults properly), **Full** (both). Lets existing systems adopt the standard piece by piece. |
| **Gateway** | Software that gets records *out* of existing systems and *into* vaults — via the FHIR APIs the law requires EHR vendors to expose, via standard clinical documents, or (last resort) by extracting from PDFs and scans with human verification. |
| **FHIR** | The healthcare industry's standard format for exchanging clinical data (HL7 FHIR R4), legally mandated in the US. OPR stores clinical content as FHIR resources rather than inventing a new format. |
| **OPR** | Open Patient Record — the working name of this standard. |
