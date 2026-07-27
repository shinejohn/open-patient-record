# Scheduling `bin/sync.php`

There is no daemon. The gateway sync CLI is a one-shot batch job — you run it
repeatedly (cron, systemd timer, Railway/Heroku scheduler, etc.) and it picks
up wherever the last successful run left off, using the persisted
`SyncStateStore` checkpoint (`--state=sync-state.json`).

## cron example

```cron
# Every 15 minutes, poll the incumbent EHR's live FHIR API and sync into the
# vault. Runs unattended: --acknowledge-unresolved lets the run complete even
# when some resources couldn't be classified (they are recorded, never
# dropped, and remain visible in the plan output / logs for follow-up).
*/15 * * * * /usr/bin/php /opt/opr-gateway/bin/sync.php \
    --fhir-base=https://ehr.example/fhir --fhir-token="$(cat /etc/opr/ehr-token)" \
    --state=/var/opr/sync-state.json --source-id=incumbent-ehr \
    --types=MedicationStatement,AllergyIntolerance,Condition,Immunization,Observation,DiagnosticReport \
    --base-url=https://vault.example --vault="$VAULT_ID" --token="$(cat /etc/opr/vault-token)" \
    --verifier-id=svc-sync --verifier-name="Automated Sync" \
    --acknowledge-unresolved \
    >> /var/log/opr/sync.log 2>&1
```

Adjust the interval to the incumbent's rate limits and your staleness
tolerance. There is nothing time-critical about the schedule itself — the
checkpoint means a missed or delayed run just means the next run's `$since`
window is wider, not that anything is lost.

## Concurrency: the flock lock

`bin/sync.php` takes an exclusive, non-blocking `flock()` on
`{state}.lock` before touching the state file. Two overlapping runs would
otherwise race on a full-file read-modify-write of the JSON state, silently
losing one run's updates and re-committing already-synced resources as
duplicates. If a run is still in flight when cron fires again, the new
invocation fails fast (`exit(3)`, "another sync run holds ... refusing to
run concurrently") instead of corrupting state. This is expected and safe:
just let the next scheduled tick pick it up.

If runs are consistently overlapping (the interval is shorter than a run
takes), that's a signal to lengthen the interval or shard `--types` across
separate `--source-id` / `--state` pairs, not to remove the lock.

## Failure semantics — safe to re-run

- **A failed pull (`HttpFhirSource` / `SnapshotFileFhirSource` throwing)
  aborts the run before any classification, any candidate, or any commit.**
  Nothing is recorded and the checkpoint (`markPulled()`) is never called —
  the next run re-pulls from the exact same `$since` it would have used this
  time. A transient EHR outage or timeout is always safe to retry; no window
  of resources is ever silently skipped because of a failed fetch.
- **A failure partway through committing** (network error to the vault mid
  batch) leaves the checkpoint unadvanced too — `markPulled()` is the very
  last call in the script, after all commits. Already-committed entries in
  that batch ARE recorded immediately per-entry (`recordCommitted()` inside
  the commit loop), so a crash mid-batch re-fetches the same source window on
  the next run but re-classifies already-committed resources as `unchanged`
  (fingerprint match) rather than re-creating them — safe to re-run, not
  purely idempotent-by-accident.
- **Sign-off blocked by unresolved mentions** (`exit(1)`) also advances
  nothing. Re-run with `--acknowledge-unresolved` once you've reviewed them,
  or leave it unattended (see the cron example) if unresolved items are
  expected to require human triage out-of-band.

In short: every failure mode of this CLI fails closed on the checkpoint. It
is always correct to just re-run it; it is never correct to manually edit
`sync-state.json` to "unstick" a run without understanding why it failed.
