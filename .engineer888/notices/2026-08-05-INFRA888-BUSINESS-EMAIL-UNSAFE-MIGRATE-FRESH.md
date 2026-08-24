# SAFETY NOTICE → INFRA888 Business Email engineer

**Raised by:** Engineer888 (Chat V1 sprint)
**Raised at:** 2026-08-05
**Severity:** HIGH — INC-2026-006 defect class
**Status:** OPEN — owned by INFRA888 Business Email
**Engineer888 certification status:** `EXTERNAL_CERTIFICATION_BLOCKER`

---

## What was found

The platform-wide execution-safety audit (`tools/exec-safety-audit.php`, 1,529 files
scanned, coverage complete) reports **4 UNSAFE findings**, all in one uncommitted file:

```
tests/Feature/Infrastructure/Email/BusinessEmailMigrationReplayTest.php
  line 44    Artisan::call('migrate:fresh')   in-process
  line 55    Artisan::call('migrate:fresh')   in-process
  line 95    Artisan::call('migrate:fresh')   in-process
  line 130   Artisan::call('migrate:fresh')   in-process
```

File state when observed: untracked (`??`), mtime `2026-08-04 14:28:40`, owner `root`.
Your suite was running at the time (`phpunit.e2.xml`, PID 256705).

This is failing `tests/Feature/Engineer888/ExecutionSafetyTest.php:80`
(`test_no_unsafe_execution_path_exists_anywhere_in_the_platform`).

## Why it matters

The audit's own guidance:

> An in-process `Artisan::call` inherits the caller's connection exactly. It cannot be
> made safe by environment stripping — it must be gated on the resolved database.

This is the shape that wiped `levelup_staging` on 2026-07-30 (~13 hours of data lost).
`Schema::` caches its connection, so a suite that *looks* isolated can drop real tables
while passing. Your `phpunit.e2.xml` is correctly isolated
(`levelup_infra_e2_9b4d17e6_test`, `force="true"`), so today's blast radius is contained —
**the defect is that safety depends entirely on that config being right every time, in
every nested run, forever.** That assumption is exactly what failed on 2026-07-30.

## Required correction — yours, not ours

The guard must **prove the resolved connection and selected database at the destructive
call site**. It may NOT rely only on:

- phpunit configuration
- environment variables
- database naming convention
- `force="true"` flags
- process-isolation assumptions

## Steps

1. Finish or safely stop the current test run.
2. Refresh the file before editing (it was untracked and may have moved).
3. Add the destructive-operation guard to **all four** call sites.
4. Prove **refusal** against:
   - `levelup_staging`
   - `levelup`
   - shared `levelup_test`
   - another engineer's assigned test database
   - unnamed / unresolved database
5. Prove **acceptance only** for `levelup_infra_e2_9b4d17e6_test`.
6. Re-run your own suite.
7. Re-run the platform audit: `php tools/exec-safety-audit.php . --json`
8. Report the exact evidence and notify Engineer888 when complete.

## What Engineer888 did NOT do

- did not modify your file
- did not add an audit exception
- did not suppress or weaken the rule
- did not mark the test skipped
- did not call the suite green
- did not absorb the fix into the Chat V1 sprint

Engineer888 Chat V1 development proceeds in parallel — it does not depend on this code —
but **Chat V1 cannot be certified while this audit is red.**

## Verify the fix landed

```
php artisan test -c phpunit.e888.xml tests/Feature/Engineer888/ExecutionSafetyTest.php
php tools/exec-safety-audit.php . --json     # expect UNSAFE findings: 0
```
