# Signup Source Schema Recovery

**Status:** `BLOCKED_PENDING_AUTHORITATIVE_SPEC`
**Raised:** 2026-08-04
**Raised by:** Release Engineer (Platform Security 1.0 activation session)
**Authorised by:** CEO — compatibility guard only; migration explicitly withheld

---

## What is missing

`users.signup_source`. No migration in `database/migrations/` adds it. It is absent from
all 88 database backups on this server spanning 2026-05-09 → 2026-08-04 (scan validated by
finding known columns in the same dumps). It has never existed here.

It arrived as consumer code in the same 2026-05-01 "SEO-only product mode" batch that
shipped the WP Connector without its schema — see `WP-CONNECTOR-SCHEMA-RECOVERY.md`. The two
are separate features with a shared cause, and are tracked separately so they can be
resolved independently.

## What it broke

`GET /api/admin/workspaces` returned **HTTP 500** even after the WP Connector guard had
fixed every other query on that endpoint, because `listWorkspaces` read the column
unconditionally when enriching each row with `owner_source`.

`listUsers` was unaffected in normal use: it reads the column only when a
`?signup_source=` filter is supplied, which the console never sends.

## What was done instead (TEMPORARY — not this task)

`App\Core\Platform\Schema\SignupSourceSchema` — deliberately separate from
`WpConnectorSchema`. Two guarded call sites:

| call site | absent behaviour |
|---|---|
| `listWorkspaces` → `owner_source` | `null` — never `"unknown"`, `"manual"`, or `""` |
| `listUsers` → `?signup_source=` filter | filter **skipped**, not faked, not thrown on |

Reported once per operation per process under `ADMIN_SIGNUP_SOURCE_SCHEMA_MISSING`.

**Note the judgement call on the filter.** Requirement 4 specified `null` for
`owner_source` but said nothing about the filter. Three options existed: throw (rejected —
requirement 4), return zero rows (truthful in one sense, since no user can have a value, but
indistinguishable from "no matches" and therefore misleading), or skip the filter and report
it. The third was chosen: an unfiltered result is never silently presented as filtered
because the skip is always logged. If the desired behaviour is different, this is the one
decision in the remediation worth revisiting.

## Why this task is blocked

There is no authoritative specification. The column's type, length, nullability, default,
and — most importantly — its **permitted value domain** are unknown. Nothing in the codebase
enumerates valid signup sources. Reconstructing it from two call sites would produce a column
shaped by the fact that something once read it, which is not a specification.

Observed usage — a starting point for review, **not** a specification:

- read as a scalar string via `->value('signup_source')`
- filtered by exact match: `where('signup_source', $value)`
- surfaced on workspace rows as `owner_source`
- no writer anywhere in the codebase — nothing ever sets it

That last point is the significant one: **no code writes this column.** Even with the column
present, every value would be null unless some external process populated it. That strongly
suggests the feature was never completed, not merely that a migration was lost.

## Also worth knowing

`owner_source` has **no frontend consumer**. A grep across `resources/views/admin/` and
`public/js/` finds no reference. The field is computed, serialised, and returned to a client
that never reads it. Whatever is decided about the column, the enrichment may be removable.

## To unblock

1. Find the authoritative spec — the original "SEO-only product mode" design, or a schema
   from an environment where it ran.
2. If none exists, decide deliberately: specify signup-source tracking properly (including
   who writes it and when), or **retire the consumer code**. Given nothing writes it and
   nothing displays it, retirement is the likely correct answer.
3. Only then write the migration.

## Definition of done

- `SignupSourceSchema::available()` returns true in production, **or** the consumer code is
  retired and the guard removed with it
- Both call sites resume original behaviour with **no code change** — already proven by
  `test_when_the_column_is_available_the_real_query_runs`
- `ADMIN_SIGNUP_SOURCE_SCHEMA_MISSING` stops appearing in logs
