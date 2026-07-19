# OPR Conformance Runner

Black-box checks for the custody layer, runnable against **any** implementation over
plain HTTP — no framework, no dependencies, one PHP file.

```bash
# Against a running vault server (self-provisions test accounts via POST /users):
php run.php --base-url=http://localhost:8000/api

# Against a server with its own account provisioning — pre-create three subjects:
php run.php --base-url=https://vault.example.com/api \
  --token-a=<subject-under-test> --token-b=<migration-destination> --token-c=<stranger>
```

- **MUST** checks map to spec normative requirements (custody, §3 grants, §4
  integrity, §5 provenance, §7 portability incl. the round-trip anchor). Any MUST
  failure → exit 1, not conformant.
- **SHLD** checks (ShareSessions, break-glass, witness log) report but do not fail
  the run.

This runner is the seed of the official conformance suite; the reference server's
own feature tests are its superset. v0.1-draft checks — the suite versions with the
spec.
