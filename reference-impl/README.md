# OPR Reference Implementation

**Status: not started — the spec draft precedes it deliberately.**

This directory will contain:

1. **The reference vault server** — a Full-conformance (Custodian + Contributor)
   implementation of [`spec/custody-layer.md`](../spec/custody-layer.md):
   FHIR R4 read surface, append-only hash-chained entry store, AccessGrant
   mint/redeem/revoke, ShareSessions, audit, and the portability export/import pair.
   Planned stack: PHP 8.3+ / Laravel / PostgreSQL (UUID keys, append-only enforcement
   at the database layer — triggers, not app-code promises).
2. **The conformance test suite** — the mechanical definition of "OPR Conformant" at
   each level, runnable against *any* implementation over its public API (black-box:
   HTTP in, assertions out). The round-trip portability test (spec §7.2) is the
   suite's centerpiece and lands first.

Sequencing note: the suite is developed *with* the server, red-before-green — every
normative MUST in the spec gets a failing test before the reference server makes it
pass. A spec requirement with no test is treated as a spec bug.

License: Apache-2.0 (repository [`LICENSE`](../LICENSE)). Contributions: DCO
sign-off (`git commit -s`) — see [`GOVERNANCE.md`](../GOVERNANCE.md).
