# OPR Legacy Gateway — G0 (deterministic ingestion)

Turns legacy health records into verified [OPR vault](../reference-impl) entries.
**G0 is the deterministic tier: it needs no AI and no BAAs.** It parses formats
that are already structured, extracts only what it can prove, counts everything it
can't, and never fabricates a clinical fact.

```
legacy record ──▶ classify ──▶ deterministic parse ──▶ human verify ──▶ commit to vault
                                        │                                (public API)
                                        └─ narrative-only mentions ─▶ counted as unresolved,
                                                                       never invented
```

## What G0 ingests today

| Format | Handling | Risk |
|---|---|---|
| **FHIR R4 Bundle** (e.g. Apple Health export) | Direct resource → candidate mapping; `source-coded` | None — content is already FHIR |
| **C-CDA** (Continuity of Care Document) | Structured section parsing: medications, allergies, problems, immunizations, results | None on coded entries; narrative-only entries are counted `unresolved`, not extracted |
| Anything else (PDF, scan, free text) | Reported `needs-manual-entry` | Honest boundary — nothing fabricated |

Narrative extraction from C-CDA text, OCR'd PDFs, and scans is **G1**, which is
additionally gated by a signed model-vendor BAA and is not in this tier.

## The three rules that make it trustworthy

1. **Validate, never guess codes.** Codes present in the source are validated
   against their system (RxNorm/SNOMED/LOINC/ICD-10/CVX shape); unrecognized codes
   become uncoded text. A wrong code is worse than no code.
2. **Completeness accounting.** Every domain reports `found / extracted /
   unresolved`. Sign-off is *blocked* while any mention is unresolved unless the
   reviewer explicitly acknowledges the set. Silent drops are structurally
   impossible.
3. **Human verification is medication reconciliation.** Deterministic candidates
   may be batch-accepted; a clinician signs off; only then do entries commit at
   `clinician-verified` with the verifier recorded in provenance. Sensitive
   categories (42 CFR Part 2, mental health, HIV, reproductive, genetic) are
   flagged and carried, never leaked.

## Run the live demo

```bash
cd gateway && composer install
cd ../reference-impl/server && composer install   # the vault server
cd ../../gateway/demo && ./gateway-demo.sh
```

Boots a real vault server on a scratch database, ingests a C-CDA **and** an Apple
Health FHIR bundle for one patient, runs the verification workflow, commits into
her vault via the public API, and proves the result: chain verifies, her personal
FHIR endpoint returns the merged chart, and provenance names both the source
organization and the verifying clinician. (This demo is what caught — and the
regression test now guards — a real provenance-truncation bug in the vault server.)

## CLI

```bash
php bin/import.php --file=record.xml --dry-run \
  --verifier-id=u1 --verifier-name="Dr. Okafor"        # preview

php bin/import.php --file=record.xml --acknowledge-unresolved \
  --base-url=http://localhost:8000 --vault=<uuid> --token=<write-grant-token> \
  --verifier-id=u1 --verifier-name="Dr. Okafor"        # commit
```

## Tests

```bash
./vendor/bin/phpunit    # 14 tests: parsers, terminology, completeness, verification
```

License: Apache-2.0. Contributions: DCO sign-off (`git commit -s`).
The Gateway's deterministic core is open; AI-assisted extraction (G1) and the
productized migration service are the commercial tier.
