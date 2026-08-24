# WP Connector Schema Recovery

**Status:** `BLOCKED_PENDING_AUTHORITATIVE_SPEC`
**Raised:** 2026-08-04
**Raised by:** Release Engineer (Platform Security 1.0 activation session)
**Authorised by:** CEO — remediation limited to a compatibility guard; schema reconstruction explicitly withheld

---

## What is missing

The WP Connector's consumer code shipped on 2026-05-05 (commit `66146f2`, "SEO-only
product mode 2026-05-01") without any of its storage. Three objects are absent:

| object | state |
|---|---|
| `wp_site_connections` (table) | never existed |
| `api_keys.site_connection_id` (column) | never existed |
| `App\Models\WpSiteConnection` (class) | never existed |

No migration in `database/migrations/` creates any of them. A scan of all 88 database
backups on this server, 2026-05-09 through 2026-08-04, found the table in none of them —
the scan was validated by finding `users` in the same files.

## What it broke

`GET /api/admin/users` and `GET /api/admin/workspaces` returned **HTTP 500** for three
months. The failure stayed invisible because until the 2026-07-29 multi-page refactor every
`/admin/*` URL rendered the same hardcoded dashboard, so the Users and Workspaces pages were
never actually opened as their own URLs. The Platform Security browser certification on
2026-08-04 is what opened them.

The two billing helpers were also failing on every subscription event, but their existing
`catch (\Throwable)` logged "non-fatal" and moved on — so the defect generated log noise
rather than incidents.

## What was done instead (TEMPORARY — not this task)

`App\Core\Platform\Connector\WpConnectorSchema` — one capability check, six guarded call
sites. Absent storage now degrades to truthful zeros and an explicit
`connector_available: false`, reported once per operation under the structured marker
`WP_CONNECTOR_SCHEMA_MISSING`.

**The guard is not the fix.** It makes an absent optional feature degrade instead of throw.
The feature remains unavailable.

## Why this task is blocked

There is no authoritative specification for the schema. Reconstructing it from its consumers
would produce a table shaped by what six call sites happen to read, which is not the same as
what the feature requires. Column types, nullability, indexes, foreign keys, the status
enum's full domain, and the `api_keys` relationship would all be guesses. A subtly wrong
table is worse than an absent one: it would accept writes the real feature cannot honour.

Columns *observed* in use — a starting point for review, **not** a specification:

- `id`, `workspace_id`, `site_url`, `status`, `last_push_at`, `last_push_status`,
  `created_at`, `updated_at`
- `status` values seen: `active`, `disconnected`, `failed`, `billing_suspended`
- `api_keys.site_connection_id` — nullable FK to `wp_site_connections.id`

## To unblock

1. Locate the authoritative spec — the original WP Connector design doc, the plugin's
   expected API contract, or a database from an environment where the feature ran.
2. If none exists, decide deliberately: specify the feature afresh, or **retire the
   consumer code**. Six call sites and a Stripe integration currently reference a feature
   the platform does not have. Retirement may be the honest answer.
3. Only then write the migration and the model.

## Definition of done

- `WpConnectorSchema::available()` returns true in production
- Every guarded call site resumes original behaviour with **no code change** — this is
  already proven by `test_when_the_schema_exists_every_call_site_behaves_normally_again`
- `WP_CONNECTOR_SCHEMA_MISSING` stops appearing in logs
- The guard is then reviewed for removal, or kept deliberately as a degradation path

## Related, NOT covered by the guard

`users.signup_source` is a **separate** never-shipped column from the same 2026-05-01 batch.
It is read unconditionally by `listWorkspaces` and conditionally by `listUsers`. It is
outside the authorised scope of the connector remediation and is reported separately.
