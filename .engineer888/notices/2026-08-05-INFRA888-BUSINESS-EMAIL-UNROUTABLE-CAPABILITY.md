# NOTICE → INFRA888 Business Email

**Raised by:** Engineer888 (Chat V1 sprint)
**Raised at:** 2026-08-05
**Blocker id:** `ADMIN_REGISTRY_VISIBILITY_ASSUMPTION_BROKEN`
**Status:** OPEN — owned by INFRA888 Business Email
**Severity:** MEDIUM — your pages are currently undiscoverable

---

## What was found

Eight admin pages are registered in `config/admin_pages.php` with
`'capability' => 'business_email.read'`:

```
businessEmailOverview        businessEmailOperations
businessEmailDomains         businessEmailReconciliation
businessEmailMailboxes       businessEmailProviders
businessEmailRouting         businessEmailHealth
```

`App\Core\Platform\Admin\AdminAccess` has **no resolver for the `business_email.` prefix**.
Its `$resolvers` map contains `engineer888` only, and an unroutable capability **fails
closed by design**:

> Fail closed. An unroutable capability is a defect, and a defect that grants access is
> the expensive kind.

## Consequences

1. **Your eight pages are invisible to every administrator, including the canonical one.**
   `AdminRegistry::visibleFor()` returns 57 of 65 pages for user 1; the missing 8 are yours.
   `/admin/business-email/*` will 404 for everyone.

2. **An existing Engineer888 test now fails:**
   `tests/Feature/Platform/AdminIdentityTest::test_the_canonical_admin_sees_the_whole_registry`
   asserts the canonical account loses nothing. Registry 65, visible 57.

Engineer888 has **not** modified that test, your pages, or `AdminAccess`.

## What INFRA888 needs to decide

Register the correct policy resolver, **or** remove/inactivate the pages until the policy
exists. Concretely, one of:

- add a `business_email` resolver to `AdminAccess::$resolvers` pointing at your own access
  policy (mirroring how `engineer888` routes to `Engineer888Access`), or
- drop the `capability` key if these pages are intended for any platform admin, or
- remove the entries until the feature is ready to be discoverable.

**Engineer888 will not grant them by default.** Adding a permissive resolver on your behalf
would be inventing an access policy for a subsystem we do not own, and a wrong guess here
grants access rather than withholding it.

## Verify after the fix

```
php artisan test -c phpunit.e888.xml tests/Feature/Platform/AdminIdentityTest.php
```

Expect `the canonical admin sees the whole registry` to pass, and
`AdminRegistry::visibleFor()` for user 1 to return the full registry count.

## Related, still open

`.engineer888/notices/2026-08-05-INFRA888-BUSINESS-EMAIL-UNSAFE-MIGRATE-FRESH.md`
— four in-process `Artisan::call('migrate:fresh')` calls, still unfixed. That one blocks
Engineer888 Chat V1 certification.
