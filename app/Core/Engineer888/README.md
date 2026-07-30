# Engineer888

Two commands. Both read-only. Both deterministic — no model is called, so every
conclusion can be re-derived by hand and does not change between runs.

## `php artisan engineering:brief`

What is the state of the platform, and what changed since the last time this ran.

| flag | effect |
|---|---|
| `--json` | machine-readable output only |
| `--no-store` | do not persist a snapshot |
| `--quiet-ok` | print nothing when there is nothing to report |

Exit codes: `0` clean · `1` warnings · `2` critical. Scheduled daily at 06:00
(`bootstrap/app.php`), with `--quiet-ok` so anything in the scheduler log is worth
reading.

Sensors: git delivery state, AI runtime health, supervisor and queue depth (from
**Redis** — the `jobs` table is permanently empty on this platform), disk,
scheduler heartbeat, 24h task outcomes, and new error-log entries counted forward
from the previous run's byte offset.

Snapshots persist to `engineering_snapshots`. The first run is a **baseline** and
says so — with no prior offset the log scan covers history, and reporting that as
"new" was a real defect found on the first live run.

Errors are counted per Monolog channel. Test runs write into the production log
file, so `testing.*` entries are excluded from the production count and reported
separately as `LOG-FOREIGN-CHANNEL`.

## `php artisan engineering:repo`

What should be done next with the working tree.

| flag | effect |
|---|---|
| `--json` | machine-readable output only |
| `--section=NAME` | repeatable: `summary`, `classification`, `commit`, `risks`, `cleanup`, `dependencies`, `priority` |
| `--subsystem=NAME` | analyse one subsystem only |
| `--files` | per-file classification table |
| `--limit=N` | rows in that table (default 40) |

Exit codes: `0` nothing blocking · `1` warnings · `2` critical risks.

Not scheduled. It is run when deciding what to commit, and takes ~3s (a full
tokenizer pass over every non-vendor PHP file, rebuilt every run — a cache that
could go stale would make the graph lie).

### What it does

1. **Working tree** — `git status --porcelain -uall`. The `-uall` matters: the
   default form collapses an untracked *directory* to one entry, which understated
   this repository's changed set by 2.2×.
2. **Classification** — every changed file gets one primary classification and any
   number of flags. Precedence and the reasoning behind it are documented on
   `FileClassifier`.
3. **Dependency graph** — tokenizer, two passes: collect declared classes, then
   keep only references that resolve to one of them. A class name inside a string
   is data, not an import, and the tokenizer is what makes that distinction.
4. **Risks** — relationships, not single files: a deleted file still referenced, a
   duplicate class name, a migration on disk that has never run.
5. **Commit plan** — grouped, ordered, each group explaining *why* its files
   belong together, with schema before the code that reads it.
6. **Priority** — the ordered answer to "what next".

### Two grouping rules worth knowing

**Co-creation beats name matching.** Files that are all new, share a directory,
and were created within 48 hours of each other are one commit even when their
names suggest different subsystems. Without this, the admin multi-page conversion
produced twenty single-file commits for one change. Directories mixing
long-standing and new files are *not* merged — that is separate work sharing a
flat directory.

**Order is derived, then honestly broken.** Commit order follows dependency
direction and schema-first. A codebase this connected always contains cycles, so
when no valid order remains the planner commits the group with the fewest unmet
dependencies anyway and names each constraint that choice breaks. A usable order
plus an explicit list of what it violates beats a correct refusal.

## Testing

```bash
php artisan test -c phpunit.e888.xml
```

The dedicated config exists because both suites failed repeatedly against the
shared `levelup_test` while another session was migrating it. Fixture tests build
throwaway git repositories to assert exact results; live tests assert invariants
(every file classified, every file in exactly one commit, identical plan on two
consecutive runs) because exact numbers change hourly.

## Extending

A new sensor is a class implementing `Signals\Signal`, added to the collector list
in `EngineeringBrief`. `collect()` must never throw — an unreadable sensor reports
`available => false` with a reason, because "we could not measure this" is a useful
brief entry and a crashed brief is not.

A new subsystem needs no code change: `ArchitectureMap` discovers the vocabulary
from the directory tree. Only genuinely ambiguous attribution — a migration whose
table name does not contain its feature name — needs a line in `fromTableName()`.
