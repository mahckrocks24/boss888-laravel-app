# SCOPE CHANGE — CONTENT PUBLISHER (2026-07-22)
Supersedes the launch-scope decision that retained only article sharing.

## BOUNDARY
RETAIN: Content Publisher · manual publishing (now/scheduled) · publishing calendar · drafts ·
caption generation + improvement + PER-PLATFORM VARIATIONS · media upload · Media Library ·
AI Studio asset selection · article sharing · approval workflow · publishing history/status ·
provider connectors + retries · audit · idempotency · calendar · Sarah collaboration.

REMOVE (unchanged): autonomous/recurring publishing · AI social campaigns · social strategy ·
listening · sentiment · competitor monitoring · engagement · comment replies · inbox ·
social analytics dashboards · social AI specialists · email marketing/automation/newsletters/sequences.

## PLATFORM SCOPE — DECIDED: FACEBOOK + INSTAGRAM ONLY
Audit of what actually exists (2026-07-22):
| Platform | OAuth | Creds | Publish impl | Launch |
|---|---|---|---|---|
| Facebook | yes | yes | none (being built) | **SELECTABLE** |
| Instagram | yes (via FB Pages) | yes (FB app) | none (being built) | **SELECTABLE** |
| LinkedIn | yes | NO | none | hidden — LINKEDIN_CREDENTIALS_NOT_CONFIGURED |
| X/Twitter | yes | NO | none | hidden — X_CREDENTIALS_NOT_CONFIGURED (also needs paid API tier) |
| Threads | NO CODE | NO | none | hidden — THREADS_CONNECTOR_NOT_IMPLEMENTED |
| Google Business | NO CODE | NO | none | hidden — GBP_CONNECTOR_NOT_IMPLEMENTED |

**Correction to the W5 report:** SocialConnector's private createPost/publishPost POST to
`{SOCIAL_CONNECTOR_URL}/api/social/posts` — an external microservice that was never built (env var
empty). There has NEVER been a Graph API call in this product. Manual publishing is a greenfield
build, not a removed capability being restored.

## ARCHITECTURE
Publisher : drafts, captions, variations, publishing, scheduling, provider execution, history, status
Calendar  : schedules, reminders, publishing tasks, approvals
Media Lib : uploaded assets, Studio assets, reusable media
AI Studio : image + video generation
Sarah     : orchestration, collaboration, conversation, reminders, calendar coordination

MODEL: `publisher_posts` = the user's unit of work (media + base caption + platforms + ONE approval).
`social_posts` = one row per platform (the caption variation + publication record). REUSED, not
duplicated — it already carried platform/content/status/scheduled_at/external_post_id plus the W5
columns. Platforms succeed and fail independently (published / partially_published / failed).

## THE APPROVAL BOUNDARY — STRUCTURAL, NOT POLICY
`ArticleShareToken` (retained, extended to v2) now carries an `intent`:
  INTENT_ARTICLE_SHARE   ws + article + platform + account + canonical_url + idem
  INTENT_MANUAL_PUBLISH  ws + publisher_post + platform + account + idem
                         + approval_method + approved_by
A manual-publish token CANNOT be minted unless approval_method is in
`ArticleShareToken::EXPLICIT_APPROVALS` = publish_now | schedule | ui_publish_button |
ui_schedule_button | approval_click, AND an approver id is present. Re-asserted at verification.
execute() cannot run without that token.

Therefore: uploading media, generating a caption, and editing a variation are STRUCTURALLY incapable
of publishing. Autonomous publishing is unrepresentable, not merely forbidden. Editing any variation
CLEARS the approval and forces re-approval. `dueForPublishing()` only ever returns explicitly
approved posts.

## FILES
NEW  app/Core/Distribution/PublisherService.php
NEW  database/migrations/2026_07_22_001000_create_publisher_posts.php
MOD  app/Core/Distribution/PlatformPolicy.php      (6 platforms, intent-aware, launch_selectable)
MOD  app/Core/LaunchScope/ArticleShareToken.php    (v2: intent + approval claims)
MOD  app/Connectors/DryRunSocialConnector.php      (media, Instagram 2-step, intent-aware)
MOD  app/Core/Distribution/ShareCaptionService.php (+adaptForPlatform)
MOD  app/Core/Distribution/ArticleDistributionService.php · PostPublishCoordinator.php (intent arg)
MOD  app/Core/LaunchScope/LaunchScopePolicy.php    (boundary restated)
Backups: `.bak-pub-<stamp>` per file + /root/backups/publisher-presrc-20260722-112552.tar.gz

## TESTS — 77/77
W5 regression 42/42 + live checks 5/5 + Publisher 30/30. All transactional, always rolled back.
Baseline verified restored: publisher_posts=0 social_posts=0 social_accounts=0.

## STILL TO DO
1. **Real Facebook + Instagram transmission** (Graph /feed + /photos; IG container -> media_publish).
   Until then the Publisher terminates at DryRunSocialConnector and never claims "published".
2. **Reopen exactly two route groups in W4**: `api/social/oauth/facebook/callback` and a new narrow
   `api/publisher/*`. All 83 other blocked routes stay 404.
3. **Sarah collaboration**: media detection in chat, draft creation, variation generation,
   publish-now/schedule confirmation dialogue. She may NEVER approve on the user's behalf.
4. **W6** nav rename Social -> Content Publisher; remove Social Management / Intelligence /
   Campaigns / Engagement / Inbox / Listening / Competitor UI only.
5. **W7** copy: "Create, schedule and publish your content from one place." Never "we manage your
   social media", never autonomous anything, always state that publishing needs explicit approval.
   Only claim Facebook + Instagram.
