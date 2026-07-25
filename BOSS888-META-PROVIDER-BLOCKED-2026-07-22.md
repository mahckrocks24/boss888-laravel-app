# PUBLISHER PROVIDER STATUS: BLOCKED — META PERMISSIONS OR TEST ASSETS
## Phase 1 findings, 2026-07-22. Nothing transmitted. No route changed.

## META APP FACTS (verified live against Graph v19.0)
| Fact | Value |
|---|---|
| App name / ID | `Level Up Growth` / `1553342953023029` |
| Credentials | VALID — client_credentials app token issued OK |
| `app_type` | **0 (consumer app)** — not a Business-type app |
| Roles | **1 administrator only** (uid 10244285799782406). No developers, no testers |
| Test users | **`{"data":[]}` — none** |
| App domains | staging.levelupgrowth.io, levelupgrowth.io |
| privacy_policy_url | **ABSENT** |
| terms_of_service_url | **ABSENT** |
| Registered redirect | `https://staging.levelupgrowth.io/api/social/oauth/facebook/callback` |
| That redirect, live | **HTTP 404 `not_in_product`** (W4 blocks it) |
| Dev vs Live mode | NOT exposed by Graph — must be read from the App Dashboard |

## BLOCKER 1 — PUBLISHING PERMISSIONS WERE NEVER REQUESTED
`SocialConnector::getAuthUrl()` requests exactly:
    public_profile, pages_show_list
The code says why, in its own comment:
    "v5.5.4 — narrowed to scopes that auto-approve in Development mode.
     Broader publishing scopes (pages_manage_posts, instagram_content_publish)
     require the Facebook app to pass App Review before they can be granted."

MISSING and REQUIRED:
  - `pages_manage_posts`        -> without it POST /{page-id}/feed and /photos are impossible
  - `pages_read_engagement`     -> Page reads used during account discovery
  - `instagram_basic`           -> resolve the IG professional account
  - `instagram_content_publish` -> create + publish IG media containers
  - `business_management`       -> typically required for Page/IG asset access

Credentials being present is NOT permission. Publishing cannot work today.

## BLOCKER 2 — APP TYPE AND REVIEW PREREQUISITES
`app_type: 0` is a consumer app; Instagram Content Publishing in practice requires a
Business-type app. App Review additionally requires, before submission is even possible:
  - a public Privacy Policy URL (ABSENT)
  - a Terms of Service URL (ABSENT)
  - Business Verification
  - a screencast demonstrating each requested permission in a working flow
That last item is circular with the redirect being 404 (see Blocker 4).

## BLOCKER 3 — NO DESIGNATED TEST ASSETS
social_accounts = 0. No Facebook test Page, no Instagram professional test account, no
test media, no cleanup procedure. Zero test users on the app. Per the increment's own
stop condition, transmission must not be attempted. Customer Pages (Chef Red, AMG Travel,
Benjelloun & Partners) are explicitly excluded and were not touched.

## BLOCKER 4 — THE REGISTERED REDIRECT IS BLOCKED BY W4
Meta will redirect to `/api/social/oauth/facebook/callback`, which W4 404s. Even a
Development-mode connect cannot complete until the narrow carve-out lands. This must be
fixed BEFORE App Review, because the review screencast has to show a working connect.

## SECURITY FINDING — THE EXISTING OAUTH FLOW MUST BE REBUILT, NOT REUSED
`$state = "{$workspaceId}_{$nonce}"` puts the **workspace id in an unsigned query
parameter** — precisely what the Publisher spec forbids. `handleCallback($code, $state,
$workspaceId)` then takes the workspace from the caller while the cache key derives from
the attacker-supplied state string. One-time use via `cache()->pull()` is correct; the
binding is not. The Publisher connection flow must sign state (workspace + user + provider
+ nonce + issued-at) rather than concatenate it. This work is INDEPENDENT of Meta.

## WHAT IS UNBLOCKED (Meta-independent, buildable now)
  - signed OAuth state + Publisher connection controller (no route opening required to build)
  - FacebookPublisherConnector / InstagramPublisherConnector against a mock transport
  - approval-token binding to caption hash + media hash + account + platform + schedule
  - uncertain-result state (`publishing_unknown`) + reconciliation-before-retry
  - connection health states (connected/expiring/expired/revoked/insufficient_permissions/disconnected)
  - retry classification, credit reconciliation, the 54-point test matrix minus real proof

## BOSS ACTIONS TO UNBLOCK (in order)
1. Confirm the app's Dev/Live mode in the App Dashboard.
2. Decide: convert to / create a **Business-type** app (needed for IG publishing).
3. Publish a Privacy Policy + Terms URL and set them on the app.
4. Complete Business Verification.
5. Create a **designated test Facebook Page** + a linked **Instagram professional test
   account** (NOT a customer brand, not the main personal profile). Record Page ID + IG ID.
6. Add the app admin as a tester, or create Meta test users.
7. Approve the narrow route carve-out so connect can complete.
8. Submit App Review for pages_manage_posts + instagram_basic + instagram_content_publish
   (+ pages_read_engagement, business_management as required).

Until 1-8, real publishing proof is impossible and the Publisher correctly continues to
terminate at DryRunSocialConnector without ever claiming a post was published.

## BASELINE AT TIME OF REPORT (unchanged by this phase)
publisher_posts 0 · social_posts 0 · social_accounts 0 · jobs 0 · failed_jobs 0 ·
calendar_events 0 · workers 3/3 RUNNING · 970 routes · no customer publishing traffic.

---

# ADDENDUM — META-INDEPENDENT HARDENING SHIPPED (same day)
Status unchanged: **BLOCKED — META PERMISSIONS OR TEST ASSETS**. Nothing transmitted.
**NO ROUTE WAS ADDED OR REOPENED.** All 12 probed routes (incl. `api/publisher/*`) still 404.

## Tests: 109 green
W5 42/42 + live 5/5 · Publisher core 30/30 · Provider hardening 32/32.
All transactional and rolled back; baseline restored (publisher_posts/social_posts/social_accounts = 0).

## Shipped
| Component | Purpose |
|---|---|
| `SignedOAuthState` | HMAC-signed, single-use state. Fixes the finding that workspace id travelled unsigned |
| `Transport` / `MockTransport` / `HttpTransport` | The only seam to the outside. Live sending OFF by default |
| `MetaErrorMap` | Normalises Graph responses to ok / failed / retryable / **uncertain**; redacts tokens |
| `FacebookPublisherConnector` | /feed (text+link) and /photos (single image). Video+carousel deliberately absent |
| `InstagramPublisherConnector` | Real container workflow: create -> poll status -> media_publish |
| `ConnectionHealth` | connected / expiring / expired / revoked / insufficient_permissions / disconnected + encrypted credential envelope |

## Approval now binds to the artefact
Manual-publish token claims: workspace, publisher_post, platform, social_account, idempotency_key,
approval_method, approved_by, **caption_hash, media_hash, schedule_key**. Changing caption, media,
platform, account or schedule voids the approval. A Facebook token cannot authorise Instagram; a
token for Page A cannot authorise Page B; a token for image v1 cannot authorise a replacement.

## Uncertain outcomes
A timeout produces `publishing_unknown`, never a blind retry. `PublisherService::reconcile()` asks
the provider what actually exists:
  found      -> marked published with the real provider id
  not found  -> `retry_pending` (only now is a retry safe)
  unresolved -> stays `publishing_unknown`

## THREE REAL BUGS THE TESTS CAUGHT
1. **`failure_class` was varchar(32)**; drift codes are 39 chars, so MySQL threw
   *inside the failure handler* — a post failing for a legitimate reason would have raised a DB
   exception instead of recording why. Widened to 96 (migration `..._002100_widen_failure_class`).
2. **Honesty regression I introduced**: a connector "success" over the mock transport was marking
   rows `published` and storing the mock id in `external_post_id`. With live sending off by default
   that would have told users their content went out when nothing was sent. Now a dry run yields
   `execution_status='dry_run_ok'`, post status **`validated`** (a distinct state), `external_post_id`
   NULL, and the simulated id kept separately as evidence.
3. **Future-facing skip bug**: `dry_run_ok` was treated as terminal unconditionally, so the day live
   transport was enabled every previously-validated post would have been skipped and never published.
   Now `dry_run_ok` is terminal only while the transport is dry.

## Live sending guard
`config('publisher.live_transport', false)` — there is no `config/publisher.php`, so the default
false applies. `MockTransport` contains no HTTP client, curl, or stream function (asserted by test 32).
Enabling live sending is a deliberate, reviewable one-line change.

## Files
NEW  app/Core/Publisher/{SignedOAuthState,Transport,MockTransport,HttpTransport,MetaErrorMap,
     ConnectionHealth,FacebookPublisherConnector,InstagramPublisherConnector}.php
NEW  database/migrations/2026_07_22_002000_publisher_provider_hardening.php
NEW  database/migrations/2026_07_22_002100_widen_failure_class.php
MOD  app/Core/Distribution/PublisherService.php · app/Core/LaunchScope/ArticleShareToken.php
Backups: `.bak-hard-*`, `.bak-honesty-*`, `.bak-skip-*`, `.bak-ep-*` +
/root/backups/hardening-presrc-20260722-122205.tar.gz

## Schema
social_accounts += health_state, token_expires_at, granted_scopes_json, health_checked_at,
health_detail, connected_by, provider_account_name, linked_page_id, credentials_encrypted
social_posts    += approved_caption_hash, approved_media_hash, provider_error_code,
provider_status_class, reconcile_attempts, provider_request_id; failure_class 32->96
publisher_posts += approved_schedule_key

## Rollback
`php artisan migrate:rollback --step=2`, restore the `.bak-*` copies of PublisherService.php and
ArticleShareToken.php, `rm -rf app/Core/Publisher`, `php artisan config:clear`. NEVER `config:cache`.

## STILL BLOCKED ON YOU (unchanged)
Meta App Review + Business-type app + privacy/ToS URLs + Business Verification + a designated
non-customer test Page and Instagram professional account. Until then: no route carve-out, no
transmission, no real proof.
