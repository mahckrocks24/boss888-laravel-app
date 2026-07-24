# Running the test suite

INFRA888 Phase 1D §9 — reproducible from a clean clone with **no undocumented
manual edits**.

---

## Prerequisites

- PHP 8.3 (`pdo_mysql` required; **sqlite is NOT required** — see "Why MySQL")
- MySQL 8 reachable from the machine running the tests
- Composer 2

---

## Setup

```bash
# 1. Install dependencies INCLUDING dev (do NOT use --no-dev)
composer install

# 2. Create the dedicated test database.
#    Must NOT be the staging or production database.
mysql -e "CREATE DATABASE IF NOT EXISTS levelup_test
          CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
          GRANT ALL ON levelup_test.* TO 'YOUR_DB_USER'@'localhost';
          FLUSH PRIVILEGES;"

# 3. Point a local .env at the test database (or export the vars).
#    phpunit.xml force-overrides the risky values anyway, but the base
#    connection credentials come from .env.
cp .env.example .env    # if you do not already have one
php artisan key:generate

# 4. Build the schema. The chain migrates cleanly from an EMPTY database
#    as of 2026-07-18 (Phase 1D §8 repair).
DB_DATABASE=levelup_test php artisan migrate --force
```

## Run

```bash
# everything
php vendor/bin/phpunit

# INFRA888 only
php vendor/bin/phpunit --testsuite=Infrastructure
```

---

## What the configuration guarantees

`phpunit.xml` sets every one of these with `force="true"`, so a stray `.env`
cannot leak production resources into a test run:

| Concern | Guarantee |
|---|---|
| Production database | `DB_DATABASE=levelup_test` — never the staging database |
| Production Redis | `CACHE_STORE=array`, `SESSION_DRIVER=array` |
| Real queue workers | `QUEUE_CONNECTION=sync` |
| Real email | `MAIL_MAILER=array` |
| Real providers | Null connectors forced; Cloudflare / Stripe / Postmark / OpenAI / DeepSeek / runtime credentials blanked |
| Shared admin token | `BELLA_ADMIN_TOKEN=""` |

---

## Why MySQL and not sqlite

The previous `phpunit.xml` declared `DB_CONNECTION=sqlite` with `:memory:`, but
**the hosts this project runs on have no sqlite PHP extension** — `php -m` lists
neither `sqlite3` nor `pdo_sqlite`. Every database-backed test errored before
reaching an assertion, which is why the suite had never actually run.

Two options existed: install `php8.3-sqlite3`, or target MySQL. MySQL was chosen
because:

1. Installing a PHP extension is a system-level change to the same PHP that
   serves live traffic.
2. Production runs MySQL. Testing on sqlite would exercise different engine
   behaviour — including the key-length and identifier-length limits that caused
   the clean-install migration failures repaired in §8. Those defects were
   invisible on sqlite and would have stayed invisible.

---

## Known limitations (honest)

- **`mockery/mockery` was missing** from `require-dev` until 2026-07-18. Any
  environment built from an older `composer.json` cannot run `RefreshDatabase`
  tests. Re-run `composer install` after pulling.
- **`RefreshDatabase` runs `migrate:fresh`**, which drops and rebuilds the test
  database. Never point the test database at anything you care about.
- Two INFRA888 tests (`WorkspaceIsolationTest`, `PermissionDenialTest`) use
  `DatabaseTransactions` rather than `RefreshDatabase` because they were written
  while the migration chain could not rebuild from empty. Now that §8 is
  repaired they could be moved back to `RefreshDatabase`; that has not been done
  yet and is deliberately recorded rather than silently changed.
- The standalone verification scripts under `scripts/infra888-verify-*.php` are
  **not** part of this suite. They exercise live HTTP against a running staging
  instance and must never be counted alongside framework test totals.
