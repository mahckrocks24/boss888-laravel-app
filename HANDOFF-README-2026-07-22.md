# LEVELUP GROWTH — HANDOFF 2026-07-22

Everything from today's session, pulled off the droplet. **Nothing was published to any social
provider at any point.** Baseline restored: `publisher_posts 0 · social_posts 0 · social_accounts 0
· jobs 0 · failed_jobs 0`.

## READ IN THIS ORDER

1. **`docs/BOSS888-STATE.md`** — running state. Today's entries are at the top, newest first.
2. **`docs/BOSS888-META-PROVIDER-BLOCKED-2026-07-22.md`** — **the thing that needs you.**
3. `docs/BOSS888-W6-REPORT-2026-07-22.md` — frontend sanitation (latest work).
4. `docs/BOSS888-PUBLISHER-SCOPE-CHANGE-2026-07-22.md` — Content Publisher scope change.
5. `docs/BOSS888-W5-REPORT-2026-07-22.md` — article distribution.

## WHERE THINGS STAND

| Workstream | Status |
|---|---|
| W3 runtime 2.37.3 | ✅ deployed + probed (41/42; the 1 fail is a pre-existing `export_website` map/registry mismatch, not a regression) |
| W4 routes | ✅ live — 83 routes 404 `not_in_product` |
| W5 article distribution | ✅ complete with conditions |
| Content Publisher core | ✅ live, hidden from customers |
| Meta provider transmission | ⛔ **BLOCKED ON YOU** |
| W6 frontend sanitation | ✅ complete with conditions |
| W7 marketing/pricing truth | ⏭ next |

Total green today: **166 checks** (132 automated + 34 real headless-browser QA).

## ⛔ WHAT IS BLOCKING PUBLISHING — ALL YOUR SIDE

Publishing scopes were **never requested**, let alone approved. The OAuth URL asks only for
`public_profile, pages_show_list`. Missing and mandatory: `pages_manage_posts`,
`pages_read_engagement`, `instagram_basic`, `instagram_content_publish`.

Checklist, in order:

- [ ] Confirm the Meta app's Development vs Live mode on the dashboard
- [ ] Convert to / create a **Business-type** app (app is currently `app_type: 0`, a consumer app; Instagram publishing needs Business)
- [ ] Publish a **Privacy Policy URL** and set it on the app — currently absent, and App Review cannot be submitted without it
- [ ] Publish a **Terms of Service URL** — same
- [ ] Complete **Business Verification**
- [ ] Create a **designated test Facebook Page** (NOT Chef Red, AMG Travel, B&P, or your personal profile)
- [ ] Link an **Instagram professional test account** to that Page
- [ ] Add yourself as an app tester, or create Meta test users (currently 0)
- [ ] Approve the narrow route carve-out (the registered callback is 404'd by W4 — which also blocks the App Review screencast)
- [ ] Submit App Review for the four permissions above

App: `Level Up Growth` / `1553342953023029`. Credentials are valid — they are just not permissioned.

## WHAT I NEED FROM YOU TO FINISH W6

Authenticated browser QA could not run — I have no test login. Not visually verified while logged in:
dashboard cards, Strategy Room, task-assignee dropdowns, billing/plan copy, onboarding flow.
A staging test account gets this closed.

## BUNDLE CONTENTS

```
docs/     the reports above + the 07-21 handoff + runtime deploy/rollback steps
logs/     laravel-2026-07-22.log      today's server log only (full log is 2.8 MB of history)
          articleshare.log            every [ArticleShare] audit line
          publisher.log               every [Publisher] audit line
          launchscope.log             every [LaunchScope] block / token rejection / language correction
          sarah-transcript-w6.tsv     the eight excluded-request replies, before and after W6
          worker-tail.log             last 400 worker lines
tests/    all five harnesses, runnable on the droplet:
            w5-verify.php + .sh       article distribution (42 + 5 live)
            pub-verify.php            Publisher core, approval boundary (30)
            hardening-verify.php      provider hardening, mock transport only (32)
            w6-verify.php             frontend sanitation (23)
            qa.js                     headless Chromium QA — run as qa.cjs (34)
            sarah-test.sh             the eight excluded requests, live
src/      the new modules as shipped (Distribution, Publisher, LaunchScope, frontend guard)
backups-manifest/backups.txt   which /root/backups tarball restores what
ENVIRONMENT.txt                host, workers, routes, runtime version, table counts at capture time
```

## RUNNING THE TESTS AGAIN

```bash
ssh staging
cd /var/www/levelup-staging
cp /root/handoff-20260722/tests/w6-verify.php ./x.php && php x.php; rm x.php
cp /root/handoff-20260722/tests/qa.js ./qa.cjs && node qa.cjs; rm qa.cjs   # .cjs — package.json is type:module
```

## TWO STANDING RULES

- **Never run `php artisan config:cache`** — `RuntimeClient` reads `env()` directly and it breaks the
  runtime connection. Use `config:clear`.
- **This droplet's "staging" is production.** `/var/www/levelup-staging` serves `levelupgrowth.io`
  **and live customer domains** (`chefredraymundo.com`, `amgtravelandtours.com`). There is no separate
  production Laravel.

## NEXT STEP

W7 — public marketing and pricing truth, in
`resources/views/marketing/{home,pricing,features,faq,specialists}.blade.php`.
Check `specialists.blade.php` first; it almost certainly still lists removed agents.
Do not advertise Publisher, Facebook or Instagram publishing until real provider proof exists.
