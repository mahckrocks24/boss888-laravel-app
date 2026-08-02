# Engineering Coordination — multi-session rules

Several Claude Code engineering sessions work in this repository at the same time.
Your knowledge of the tree goes stale the moment you stop looking at it. These are
the minimum rules that keep concurrent work from destroying itself.

Two events produced these rules, and both are worth knowing:

- **2026-07-30** — `levelup_test` was dropped and recreated while another engineer was
  mid-migration against it. Isolation had been inferred from a `ps` check taken seconds
  earlier.
- **2026-07-31** — commit `c42ea4b` captured 33 documents, at least 9 written by the
  PlatformEvents engineer. The technical grouping was correct; the commit was not.

## 1. Test databases

**Convention:** `levelup_<session>_test`, one per session, with a matching
`phpunit.<session>.xml`.

| Session | Database | Config |
|---|---|---|
| e888 | `levelup_e888_test` | `phpunit.e888.xml` |
| chat | `levelup_chat_test` | `phpunit.chat.xml` |
| p1e1 | `levelup_p1e1_test` | `phpunit.p1e1.xml` |

`levelup_test`, `levelup_clean_test` and `boss888_test` are **shared** and are refused
as destructive targets. `levelup_staging` is production and is refused always, even if a
manifest claims it.

Every `phpunit.*.xml` must set `DB_DATABASE` with `force="true"`. Without force, PHPUnit
leaves an already-set variable alone, and a nested run arrives with one already set.

**A process check is not proof of isolation.** It answers "is anyone running *right
now*", which is a different question from "is this database mine".

## 2. Sprint ownership manifest

One JSON file per sprint in `.engineer888/sprints/`. It constrains execution; it is not
a planning document and it is not a lock service.

```json
{
  "manifest_version": 1,
  "engineer": "Engineer888",
  "session": "e888",
  "sprint": "2026-08-01-coordination-hardening",
  "opened_at": "2026-08-01T10:11:49Z",
  "test_database": "levelup_e888_test",
  "phpunit_config": "phpunit.e888.xml",
  "owned_paths": ["app/Core/Engineer888/**"],
  "owned_files": ["tools/exec-safety-audit.php"],
  "shared_files": [
    { "path": "tests/TestCase.php",
      "policy": "ANNOUNCE BEFORE EDIT",
      "reason": "why this shared edit was unavoidable",
      "backup": "storage/app/ads-backups/...",
      "unavoidable_because": "..." }
  ],
  "exclusions": ["storage/**"],
  "coordination_notes": ["anything another engineer would want to know"]
}
```

Resolution: `E888_SPRINT_MANIFEST` if set, otherwise the single manifest in the
directory. **Two manifests and no explicit selection is ambiguous and resolves to
nothing** — callers then fail closed rather than guessing.

Ownership statuses: `OWNED` and `SHARED_DECLARED` may be staged. `EXCLUDED` and
`UNKNOWN` may not. Exclusions beat ownership globs, which is the only way to carve an
exception out of a directory you otherwise own.

## 3. Governed shared files

Listed in `config/governed_files.php`. A file belongs there when changing it affects
engineers who did not change it. The list is deliberately short — governance that covers
everything gets ignored, and an ignored control is worse than none because it looks like
protection.

Currently governed: `tests/TestCase.php`, `bootstrap/app.php`, `phpunit.xml`,
`composer.json`, `.env`.

Before editing one:

1. inspect modification time and current git diff
2. inspect recent commits touching it
3. confirm no other engineer is mid-run — processes **and** database connections
4. state why the shared edit is unavoidable
5. use an anchor that appears exactly once
6. back the file up
7. apply the smallest patch that works
8. verify syntax, and that surrounding content is unchanged
9. declare it in the manifest under `shared_files`, with the reason
10. report it explicitly in the sprint report

The enforcement point is staging: a governed file that is not declared in the manifest
is excluded from every commit group and refused by preflight.

## 4. Migrations

Enumerate all pending migrations. Run **only** your own, with
`--path=database/migrations/<your file>.php`. A bare `php artisan migrate` runs every
pending migration, including other engineers' unreviewed work.

Report unrelated pending migrations. Do not run them.

## 5. Documents

Historical root-level documents are staying where they are — reorganising 33 files
across three sprints creates churn without reducing risk.

**New** documents need one of: a scoped directory, an ownership marker in the file, or
an entry in the sprint manifest. Without one of those, a document is `UNKNOWN` and
cannot be committed by anyone.

## 6. Before every consequential action

Refresh, then act:

```bash
php artisan engineering:coordination              # who am I, what may I destroy, who else is here
php artisan engineering:coordination --owns=path  # may I stage this file?
git status --porcelain -uall                      # what actually changed
php artisan migrate:status | grep -i pending      # whose migrations are waiting
```

## 7. When another engineer's work blocks you

Do not rewrite it. Do not absorb it. Do not work around it silently.

Report the conflict and name the smallest coordination point that would unblock you.

## Accepted coordination debt

- **`c42ea4b`** contains other engineers' documents. Left unchanged by CEO decision on
  2026-08-01: docs-only, work preserved, and rewriting history is the larger risk.
- **`levelup_test`** was dropped on 2026-07-30 during another engineer's run. Nothing
  was permanently lost; the controls above exist so it cannot recur.
