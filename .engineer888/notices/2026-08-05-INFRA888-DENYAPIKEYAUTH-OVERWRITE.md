# COORDINATION INCIDENT → INFRA888

## INC-ENGINEERING-COORDINATION-DENYAPIKEYAUTH-OVERWRITE

**Raised by:** Engineer888 (Chat V1 sprint)
**Raised at:** 2026-08-05
**Severity:** MEDIUM — file restored, unrecoverable loss possible but unconfirmed
**Status:** OPEN — awaiting INFRA888 verification
**Affected file:** `app/Http/Middleware/DenyApiKeyAuth.php`
**Control:** INFRA888 C6 / directive 1C §5

---

## What happened

Engineer888 **accidentally overwrote** `app/Http/Middleware/DenyApiKeyAuth.php` with an
unrelated Engineer888-specific implementation, then restored it from HEAD.

The overwrite was not intentional and was not a merge, a refactor, or a disagreement with
the control. It was a mistake.

## Precise facts

| | |
|---|---|
| Overwrite was accidental | **yes** |
| Why the file was assumed absent | a discovery command (`ls app/Http/Middleware/ \| grep -i denyapi`) was piped through `head -90`; **the output was truncated before the matching line**. Incomplete output was treated as proof of absence. |
| Pre-write mtime captured | **no** |
| Pre-write backup captured | **no** |
| Current file matches HEAD | **yes** — commit `8c7cc1e` ("R0.5 forensic baseline: capture post-2026-05-22 source") |
| Current sha256 | `8b4aab025b2bd58e340680598d34d1c82b69399e4dcb5d48416b5c4b06cf4382` |
| `git status` for the path | clean (no diff against HEAD) |
| Syntax check | PASS (`php -l`) |
| Runtime health | normal — class loads; `/api/admin/engineer888/bootstrap`, `/admin/engineer888`, `/api/health` all responding as before |
| **Uncommitted INFRA888 edits prior to the overwrite** | **CANNOT BE RULED OUT OR RECOVERED** from available evidence |
| Engineer888's mistaken replacement | preserved at `/tmp/e888_mistaken_DenyApiKeyAuth.php` (sha256 `ef762568…`) **for forensic comparison only — it must not be used** |

The restore recovers the **committed** state only. If uncommitted INFRA888 work existed in
that file at the moment of the overwrite, it is gone and no backup, mtime record, or
`_backup/` copy exists that would reveal it.

## What Engineer888 needs INFRA888 to verify

1. **Did you have any uncommitted edits to `DenyApiKeyAuth.php`?**
2. **Does the current HEAD version match your intended control?**
3. **Does the file still satisfy C6 / directive 1C §5?**
4. **Retain or delete** `/tmp/e888_mistaken_DenyApiKeyAuth.php` after review?
5. **Must any related test or control be re-run** to re-establish your assurance?

## What Engineer888 will NOT do

- will not edit the file again
- will not compare or merge changes itself
- will not touch it further without explicit INFRA888 authorization

Engineer888 Chat service-layer work is **paused** pending INFRA888 confirmation that the
current file is acceptable, or INFRA888 taking ownership of any repair.

## Root cause

**Incomplete discovery output was treated as proof of absence.**

A truncated `ls` / `grep` result is never evidence that a file does not exist.

## Permanent control — adopted by Engineer888

Before creating **or** overwriting any path, prove **all** of the following first:

```
git cat-file -e HEAD:<path>     # is it committed?
git status -- <path>            # is it modified or untracked?
stat <path>                     # mtime — is someone in it right now?
sha256sum <path>                # record the hash before writing
```
plus:
- ownership manifest check
- recent commits touching the path
- recent file activity in the directory
- **a verified backup before any write**

No write proceeds until every one of those returns an answer — not a truncated one.

## Related, separate, still open

`.engineer888/notices/2026-08-05-INFRA888-BUSINESS-EMAIL-UNSAFE-MIGRATE-FRESH.md` —
four unsafe in-process `migrate:fresh` calls in
`tests/Feature/Infrastructure/Email/BusinessEmailMigrationReplayTest.php`.
That file was **never touched** by Engineer888 (mtime unchanged, sha256 `0454b756…`).
