# ADMIN AUDIT BASELINE — 2026-07-26

**Immutable "before" snapshot of the LevelUp Growth platform, captured immediately prior to the
Laravel Admin Forensic Audit.**

> **This is a forensic baseline. It is not an audit.**
> It contains no findings, no recommendations, no judgements, and no proposed changes.
> Nothing was modified, fixed, or reconfigured to produce it. Every statement carries the
> command, file, endpoint, or query that produced it.
>
> **Capture window:** 2026-07-26 10:08:44 – 10:22 UTC
> **Capture method:** read-only shell commands, read-only SQL, read-only HTTP probes.

---

## 1. SOURCE CONTROL

### 1.1 Laravel repository
| Item | Value |
|---|---|
| Path | `/var/www/levelup-staging` |
| Remote | `git@github-app:mahckrocks24/boss888-laravel-app.git` (fetch + push) |
| **Current branch** | **`feature/studio888-mrc-2a`** |
| **Current commit** | **`416686e2fb75cca4c0a245a0d60f230ced178429`** |
| Commit date | 2026-07-26 07:53:23 +0000 |
| Commit author | `BOSS888 Deploy` |
| Commit subject | `test(studio): characterize execution shadow comparison and compiler readiness` |
| Laravel version | **11.51.0** |
| PHP version | **8.3.32 (cli, NTS)** |

*Evidence: `git rev-parse --abbrev-ref HEAD`, `git rev-parse HEAD`, `git log -1 --format=...`,
`git remote -v`, `php artisan --version`, `php -v`.*

### 1.2 ⚠️ Feature-branch warning
The working checkout is **not a mainline branch**. `master` and `remotes/origin/master` exist but
are **not** checked out. HEAD sits on the STUDIO888 MRC-2A freeze commit.

**Branches present:**
```
* feature/studio888-mrc-2a      ← CHECKED OUT
  master
  preserve/mrc1-live-baseline
  preserve/studio888-mrc-2a-pre-deepseek-v4
  remediation/baseline
  remotes/origin/master
```
**Tags present:** `studio888-mrc-2a-pre-deepseek-v4`

*Evidence: `git branch -a`, `git tag`.*

### 1.3 Deployment tag / freeze point
`studio888-mrc-2a-pre-deepseek-v4` and branch `preserve/studio888-mrc-2a-pre-deepseek-v4` both
point at the Studio freeze state. Per `STUDIO888-DEEPSEEK-V4-IMPACT-REGISTER.md` the freeze point
is `416686e` — which is also current HEAD.

### 1.4 Git status — uncommitted files
```
 M BOSS888-MASTERCONTEXT-2026-07-20.md
 M BOSS888-STATE.md
?? ARCHITECTURE-AI-EXECUTION-LAYER.md
?? INCIDENT-REGISTER.md
?? RUNTIME-2.37.4-DEEPSEEK-V4-DEPLOY-AND-ROLLBACK.md
?? START-HERE-2026-07-26.md
```
All six are **documentation only**, produced by the 2026-07-26 documentation-sync task.
**No application code is uncommitted.**

*Evidence: `git status --porcelain`.*

### 1.5 Commit history (last 10)
```
416686e test(studio): characterize execution shadow comparison and compiler readiness
918654b feat(studio): capture provider prompt and persist compiler-readiness in shadow mode
ccc4ade feat(studio): introduce deterministic prompt comparison and readiness aggregation
538b74f test(studio): characterize Prompt Guardrail shadow behavior
6921a92 feat(studio): persist guardrail reports in shadow mode
38ec645 feat(studio): introduce PromptGuardrailService (deterministic shadow rule engine)
7a50f21 test(studio): characterize Prompt Compiler shadow behavior
70e01d2 feat(studio): persist compiled prompts in shadow mode
ed23aac feat(studio): introduce PromptCompilerService (deterministic shadow compiler)
6a1b1e7 test(studio): characterize creative_jobs observational lifecycle
```
**Observation of fact:** at 07:41 UTC on 2026-07-26 HEAD was `538b74f` with three Studio files
dirty. By 10:08 UTC HEAD was `416686e` with a clean code tree. Three commits (`ccc4ade`,
`918654b`, `416686e`) were authored at 07:53:23 by `BOSS888 Deploy` — i.e. **the Studio freeze
was committed during this session by a process other than this one.**

*Evidence: `git status` at 07:41 (session transcript) vs `git log -1` at 10:08.*

### 1.6 Runtime repository
| Item | Value |
|---|---|
| **Repository** | **NONE — no `.git` directory exists.** Source is distributed as archives only. |
| Commit hash | **Not applicable — no version control** |
| Deployed version (`/health`) | **`2.37.3`** |
| Package content version | **v2.37.4** (DeepSeek V4 restoration, deployed 2026-07-26 ~08:45 UTC) |
| `package.json` version field | `2.37.3` |
| Local package (current) | `C:\Users\markr\LVL\runtime-v2.37.4-deepseek-v4\levelup-runtime2-main\` (89 files) |
| Local package (prior) | `C:\Users\markr\LVL\runtime-pkg-2.37.3\levelup-runtime2-main\` (88 files) |
| Lockfile | **None** |

⚠️ The runtime has **no commit hash to record**. Its identity is the archive contents plus the
behavioural probe in §3.4. `/health` reporting `2.37.3` while running v2.37.4 content is a known
recorded condition (version constant deliberately not bumped).

*Evidence: `ls -la` on the package dir (no `.git`), `curl /health`, `package.json`.*

---

## 2. ENVIRONMENT

### 2.1 Host
| Item | Value |
|---|---|
| Hostname | `Level-Up-Growth` |
| IP | `134.209.93.41` (DigitalOcean droplet) |
| OS | Ubuntu 22.04.5 LTS |
| Kernel | `5.15.0-171-generic #181-Ubuntu SMP` x86_64 |
| Uptime | 82 days, 10:27 (at capture) |
| Load avg | 0.09 / 0.15 / 0.19 |
| Memory | 1.9 GiB total · 959 MiB used · 738 MiB available |
| Swap | 4.0 GiB total · 562 MiB used |
| Disk `/` | 49 G total · 36 G used · 13 G available · **74 % used** |

*Evidence: `hostname`, `uname -a`, `lsb_release -d`, `uptime`, `free -h`, `df -h`.*

### 2.2 Environment topology
**There is exactly one Laravel environment.** `APP_ENV=staging`, but it serves
`levelupgrowth.io` and live customer domains. There is no separate production Laravel instance.

| Environment | Reality |
|---|---|
| "Production" | Same instance as staging — `/var/www/levelup-staging`, `APP_ENV=staging` |
| "Staging" | Same instance (no separate staging deployment exists) |
| Runtime | External — Railway, `levelup-runtime2-production.up.railway.app` |

| Config | Value |
|---|---|
| `APP_ENV` | `staging` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://staging.levelupgrowth.io` |

*Evidence: `config('app.env')`, `config('app.debug')`, `config('app.url')` via bootstrapped container.*

### 2.3 Services
| Service | Version | State |
|---|---|---|
| nginx | 1.18.0 (Ubuntu) | active |
| PHP-FPM | 8.3 | active |
| MySQL | 8.0.46-0ubuntu0.22.04.3 | active |
| PostgreSQL | 14.23 | active |
| Redis | 6.0.16 | active, uptime 82 days |
| Supervisor | — | active (3 worker processes) |
| Node.js | v20.20.2 | present (CLI only, not serving) |
| npm | 10.8.2 | present |

*Evidence: `nginx -v`, `php -v`, `mysql --version`, `psql --version`, `redis-server --version`,
`systemctl is-active <svc>`, `node --version`, `npm --version`.*

### 2.4 PHP extensions loaded
```
bcmath calendar Core ctype curl date dom exif FFI fileinfo filter ftp gd gettext hash iconv
intl json libxml mbstring mysqli mysqlnd openssl pcntl pcre PDO pdo_mysql pdo_pgsql pdo_sqlite
pgsql Phar posix random readline redis Reflection session shmop SimpleXML sockets sodium SPL
sqlite3 standard sysvmsg sysvsem sysvshm tokenizer xml xmlreader xmlwriter xsl Zend OPcache zip zlib
```
*Evidence: `php -m`.*

### 2.5 nginx sites enabled
```
clutter-angels
levelup-staging
levelup-staging.bak-w6b-20260722
markraymundo.com
ptaa-staging
```
**server_name directives served:**
```
134.209.93.41 levelupgrowth.io *.levelupgrowth.io
amgtravelandtours.com www.amgtravelandtours.com
chefredraymundo.com / www.chefredraymundo.com
clutter-angels.levelupgrowth.io
markraymundo.com www.markraymundo.com
ptaa-staging.local
```
*Evidence: `ls /etc/nginx/sites-enabled/`, `grep server_name /etc/nginx/sites-enabled/*`.*

### 2.6 Database
| Item | Value |
|---|---|
| Default connection | `mysql` |
| Database | `levelup_staging` |
| Tables | **178** |
| Migrations run | **210** (latest batch 100) |
| Latest migration | `2026_07_24_170000_add_session_id_to_device_tokens` |

*Evidence: `config('database.*')`, `SHOW TABLES`, `SELECT COUNT(*) FROM migrations`.*

### 2.7 Redis
```
redis_version:6.0.16
uptime_in_days:82
db0:keys=3013,expires=3005
db1:keys=160,expires=159
db3:keys=24,expires=24
db9:keys=10,expires=0     ← this session's isolated test DB
```
*Evidence: `redis-cli INFO server`, `redis-cli INFO keyspace`.*

### 2.8 Queue workers (Supervisor)
```
levelup-worker:levelup-worker_00   RUNNING   pid 1488598
levelup-worker:levelup-worker_01   RUNNING   pid 1488603
levelup-worker:levelup-worker_02   RUNNING   pid 1488593
```
Command: `php /var/www/levelup-staging/artisan queue:work --queue=tasks-high,tasks,tasks-low,default --sleep=3 --tries=4 --max-time=3600`
Config: `/etc/supervisor/conf.d/levelup-worker.conf`, `numprocs=3`, `user=www-data`,
`autostart=true`, `autorestart=true`, `stopwaitsecs=3600`.

A **separate** PTAA worker also runs on this box: `/var/www/ptaa/artisan queue:work redis` (pid 1488516).

*Evidence: `supervisorctl status`, `ps aux | grep queue:work`, `cat /etc/supervisor/conf.d/levelup-worker.conf`.*

### 2.9 Scheduler
**Laravel scheduler runs under the `www-data` crontab, not root:**
```
* * * * * cd /var/www/levelup-staging && php artisan schedule:run >> /var/log/laravel-scheduler.log 2>&1
```
**root crontab (host-level, not Laravel):**
```
0 3 * * *  certbot renew --quiet --post-hook "systemctl reload nginx"
0 1 * * *  /root/daily-backup.sh >> /var/log/daily-backup.log 2>&1
0 2 * * *  DO_API_TOKEN=$(cat /root/.do-token) /root/do-snapshot.sh
* * * * *  cd /var/www/ptaa && php artisan schedule:run
30 1 * * * /root/backup-files.sh
0 4 * * 0  /root/backup-files.sh --verify
```
*Evidence: `crontab -u www-data -l`, `crontab -l`.*

### 2.10 Storage
| Item | Value |
|---|---|
| `storage/` | 1.7 G |
| `public/` | 4.5 M |
| Storage symlink | `public/storage -> /var/www/levelup-staging/storage/app/public` |
| Object storage | DigitalOcean Spaces — bucket `boss888-backups`, region `ams3`, endpoint `https://ams3.digitaloceanspaces.com` |
| `config/filesystems.php` | **not present** |
| Log files | `storage/logs/laravel.log` (6.07 MB, single file — not dated) · `worker.log` (637 KB) |

*Evidence: `du -sh`, `ls -la public/storage`, `grep DO_SPACES .env`, `ls -la storage/logs/`.*

### 2.11 Config cache state
```
bootstrap/cache/  →  .gitkeep, packages.php, services.php
```
**`config.php` is absent** — Laravel config is **not** cached. (`config:cache` is a recorded
prohibition on this platform because `RuntimeClient` reads `env()` directly.)

*Evidence: `ls -la bootstrap/cache/`.*

---

## 3. RUNTIME

### 3.1 Identity
| Item | Value |
|---|---|
| Host | `levelup-runtime2-production.up.railway.app` |
| Platform | Railway · NIXPACKS · `node index.js` |
| `/health` version | **`2.37.3`** |
| Content version | **v2.37.4** (DeepSeek V4 restoration) |
| Health status | `ok` |
| Phase | `2` |

### 3.2 Registered agents (10)
```
dmm, james, alex, diana, ryan, sofia, priya, nora, elena, max
```

### 3.3 Registered tools (43)
```
serp_analysis, ai_report, deep_audit, ai_status, improve_draft, write_article,
link_suggestions, insert_link, dismiss_link, outbound_links, check_outbound,
autonomous_goal, agent_status, list_goals, pause_goal, create_lead, get_lead,
update_lead, list_leads, move_lead, log_activity, add_note, create_event,
list_events, update_event, check_availability, create_booking_slot,
list_builder_pages, get_builder_page, ai_builder_action, generate_page_layout,
publish_builder_page, import_html_page, generate_design, pick_design_template,
list_design_templates, get_site_pages, get_site_page, search_site_content,
scan_site_url, create_website, generate_funnel_blueprint, analyze_funnel_structure
```
**`ai_run_tasks` advertised (12):** `seo_content_generation, image_generation, builder_generate,
competitor_analysis, write_article, improve_draft, serp_analysis, competitor_keywords,
chat_json, studio_design, studio_template_pick, studio_copy_variants`

**`internal_routes` advertised (8):** `/internal/health, /internal/image/generate,
/internal/vision/analyze, /internal/sarah/synthesize-daily, /internal/sarah/synthesize-weekly,
/internal/sarah/synthesize-monthly, /internal/agent-search, /internal/scanner`

**`config` block:** `redis: true, llm: true, wp_secret: true, lu_secret: true`

*Evidence: `curl -s https://levelup-runtime2-production.up.railway.app/health`.*

### 3.4 Provider registry — deployed behaviour (live probe)
| Probe | Response |
|---|---|
| `model: "deepseek-chat"` | `HTTP 400` · `{"code":"DEEPSEEK_INVALID_MODEL","error":"DeepSeek model \"deepseek-chat\" was retired on 2026-07-24 and is rejected by the API. Use one of: deepseek-v4-flash, deepseek-v4-pro"}` |
| `tier: "turbo"` | `HTTP 400` · `{"code":"DEEPSEEK_INVALID_MODEL","error":"Unknown DeepSeek tier \"turbo\". Valid tiers: flash, pro"}` |

These responses exist only in v2.37.4 and constitute the deployed-version proof.

*Evidence: `POST /ai/run` with `X-LevelUp-Secret`, read-only, 2 requests.*

### 3.5 Default provider / model
| Item | Value |
|---|---|
| Default provider | `deepseek` (`LLM_PROVIDER` unset → runtime default) |
| **Default model** | **`deepseek-v4-flash`** (`DEEPSEEK_DEFAULT_MODEL`) |
| Pro tier model | `deepseek-v4-pro` (`DEEPSEEK_PRO_MODEL`) — **explicit selection only** |
| Retired / rejected | `deepseek-chat`, `deepseek-reasoner`, `deepseek-coder` |

### 3.6 Available models (live provider registry)
```
GET https://api.deepseek.com/models
{"object":"list","data":[
  {"id":"deepseek-v4-flash","object":"model","owned_by":"deepseek"},
  {"id":"deepseek-v4-pro","object":"model","owned_by":"deepseek"}]}
```
**Exactly two models exist.**

*Evidence: authenticated `GET /models`, read-only.*

### 3.7 Reasoning configuration
| Item | Value |
|---|---|
| Reasoning mode | **Always on; cannot be disabled** on either tier |
| Token accounting | `reasoning_tokens` counted **inside** `completion_tokens`, therefore consume `max_tokens` |
| `REASONING_HEADROOM` | `2000` (env `DEEPSEEK_REASONING_HEADROOM`) |
| `MAX_TOKENS_CAP` | `8192` (env `DEEPSEEK_MAX_TOKENS_CAP`) |
| Empty-content handling | HTTP 200 + empty content rejected as `DEEPSEEK_EMPTY_FINAL_CONTENT` (tool-call turns exempt) |
| Structured output | Validated; failures raise `DEEPSEEK_INVALID_STRUCTURED_OUTPUT` (HTTP 502) |

Observed usage split on a live call (`/ai/run` chat_json):
`{input_tokens: 67, completion_tokens: 115, reasoning_tokens: 100, final_output_tokens: 15, total_tokens: 182, cached_tokens: 0}`

### 3.8 Streaming support status
| Route | Registered | Reachable from Laravel |
|---|---|---|
| `/internal/write/stream-init` | `index.js:271` | **No** — Laravel never calls it |
| `/internal/write/stream` | `index.js:2981` | **No** — Laravel never calls it |
| `/stream/poll/:token` | `index.js:301` | browser-facing |
| `/stream/sse/:token` | `index.js:332` | browser-facing |
| `/stream/cancel/:token` | `index.js:377` | browser-facing |

`grep -rn "internal/write/stream" app routes` → **no matches**.
`RuntimeClient` has **no streaming method** (`grep -n "function .*[Ss]tream" app/Connectors/RuntimeClient.php` → no matches).
Laravel's own `api/internal/write/stream-chunk` and `api/internal/write/stream-poll` routes exist
as **receivers** (`routes/api.php:14423`, `:14426`).

### 3.9 Known runtime limitations (state of record, not findings)
| Condition | Evidence |
|---|---|
| `/internal/write/stream-init` returns `"title or brief required"` for any body | Route registered `index.js:271`, body parser `app.use(bodyLimitDefault)` at `index.js:438`. Present identically in the v2.37.3 baseline. |
| `lu-worker-manager.js:53` requires `./lu-task-worker-fn`, a file that does not exist; try/caught → `processors.agent_user = null` | `grep -n "lu-task-worker-fn"`; file absent from `ls` |
| Startup banner prints `v2.28.0` while `/health` reports `2.37.3` | Railway deploy log |
| No lockfile — NIXPACKS resolves `^` ranges per build | `ls` of package (no `package-lock.json`) |
| No git repository for runtime source | `ls -la` (no `.git`) |

---

## 4. AI ARCHITECTURE (as implemented, 2026-07-26)

### 4.1 Execution chain
```
LARAVEL  /var/www/levelup-staging  (DigitalOcean droplet)
  Orchestrator → Engine Services → RuntimeClient
  tenancy · credits · usage metering · persistence · auth
        │  HTTPS + X-LevelUp-Secret   (RUNTIME_URL / RUNTIME_SECRET, timeout 120s)
        ▼
RAILWAY NODE RUNTIME  levelup-runtime  (v2.37.4 content)
  prompt assembly · agent loop · tool execution · model selection
  response validation · provider fallback
        │
        ├──────────────► api.deepseek.com/v1/chat/completions
        │                deepseek-v4-flash (default) · deepseek-v4-pro (tier)
        └──────────────► api.openai.com/v1/...
                         gpt-image-1 · gpt-4o · gpt-4o-mini (fallback only)
```

Laravel-side config (`config/llm.php`) resolves to:
```
llm.default            = deepseek
llm.deepseek.model     = deepseek-chat      ← Laravel-side value; the direct-call path
llm.deepseek.base_url  = https://api.deepseek.com
llm.openai.model       = gpt-4o
RUNTIME_URL            = https://levelup-runtime2-production.up.railway.app
INTELLIGENCE_VIA_RUNTIME = true
API keys present       : deepseek=YES  openai=YES
```
*Evidence: bootstrapped `config()` reads; `.env` key names.*

### 4.2 Provider inventory
| Provider | Status | Evidence |
|---|---|---|
| **DeepSeek** | **Active — primary text/JSON/tools provider** | live `/models`, `api_usage_logs`, runtime probes |
| **OpenAI** | **Active** — `gpt-image-1` (images), `gpt-4o` (vision), `gpt-4o-mini` (fallback on `/ai/run` only) | `OPENAI_API_KEY` set, `api_usage_logs` shows 70 `gpt-4o-mini` rows |
| **Gemini** | **NOT an integration.** 3 file matches are AEO bot-detection strings (`gemini.google.com`, `Google-Extended`) and a filename regex | `grep -rn -i gemini app config routes` |
| **Claude / Anthropic** | **NOT an integration.** 11 + 6 matches are AEO bot strings (`ClaudeBot`, `anthropic-ai`), a chatbot guardrail regex, and a white-label scrub map (`'Anthropic' => 'LevelUp AI'`) | `grep -rn -i claude\|anthropic app config routes` |
| **Cohere** | **NOT an integration.** 3 matches: one AEO bot string (`cohere-ai`), two are the word "coherent" | `grep -rn -i cohere` |
| MiniMax | Referenced in 11 files; `MINIMAX_API_KEY` present | `.env`, grep |
| DataForSEO | 13 files; `DATAFORSEO_LOGIN`/`_PASSWORD` present | `.env`, grep |
| Postmark | 11 files; `MAIL_MAILER=postmark`, `POSTMARK_TOKEN` present | `.env` |
| Stripe | 23 files; `STRIPE_MODE`, `STRIPE_PUBLISHABLE_KEY` present | `.env` |
| Cloudflare | 21 files; `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ZONE_ID` present | `.env` |
| Runway | `RUNWAY_API_KEY` present | `.env` |
| Facebook | `FACEBOOK_APP_ID`/`_SECRET`/`_REDIRECT_URI` present | `.env` |
| Google Search Console | `GSC_CLIENT_ID`/`_SECRET`/`_REDIRECT_URI` present | `.env` |

### 4.3 Connectors (Laravel)
```
app/Connectors/BaseConnector.php
app/Connectors/ConnectorResolver.php
app/Connectors/CreativeConnector.php
app/Connectors/DataForSeoConnector.php
app/Connectors/DeepSeekConnector.php
app/Connectors/DryRunSocialConnector.php
app/Connectors/EmailConnector.php
app/Connectors/RuntimeClient.php
app/Connectors/SocialConnector.php
```
*Evidence: `ls app/Connectors/*.php` (excluding `.bak`).*

### 4.4 Fallback routing
| Path | Fallback |
|---|---|
| `/ai/run` (12 task types) | **Yes** → OpenAI `gpt-4o-mini` (`index.js:756-801`) |
| `/internal/write/draft` | **None** |
| `llm.js callLLM()` | **None** |
| `lu-planner.js` | **None** |
| `lu-worker-manager.js` | **None** |

Fallback response fields emitted by the runtime: `requested_provider`, `actual_provider`,
`requested_model`, `actual_model`, `fallback_used`, `fallback_reason`, `usage`.

Laravel-side recording: `RuntimeClient::logApiUsage()` is called with a **hardcoded** provider
string `'deepseek'` (`app/Connectors/RuntimeClient.php:373`); `estimateApiCost()`
(`RuntimeClient.php:1203-1217`) matches on `$provider` and uses rates `$0.14`/`$0.28` per 1M for
DeepSeek. `api_usage_logs` currently holds 70 rows with `provider='deepseek', model='gpt-4o-mini'`.

### 4.5 Model & reasoning routing
- Model selection is centralised in the runtime's `deepseek-models.js`.
- Tier selection: `/ai/run` accepts `tier: "flash"|"pro"`; `lu-planner.js` reads `PLANNER_TIER`
  (default `flash`).
- Reasoning is not routed — it is an inherent, always-on capability of both V4 models.

---

## 5. ADMIN PLATFORM — INVENTORY ONLY

### 5.1 Admin controllers (10)
```
app/Http/Controllers/Api/Admin/AdminAnalyticsController.php
app/Http/Controllers/Api/Admin/AdminChatbotController.php
app/Http/Controllers/Api/Admin/AdminContentController.php
app/Http/Controllers/Api/Admin/AdminController.php
app/Http/Controllers/Api/Admin/AdminEngineController.php
app/Http/Controllers/Api/Admin/AdminIntelligenceController.php
app/Http/Controllers/Api/Admin/AdminMediaController.php
app/Http/Controllers/Api/Admin/AdminNotificationController.php
app/Http/Controllers/Api/Admin/AdminTemplatesController.php
app/Http/Controllers/Api/Admin/BellaController.php
```

### 5.2 Controller directories
```
app/Http/Controllers
app/Http/Controllers/Api
app/Http/Controllers/Api/Admin
app/Http/Controllers/Api/Debug
app/Http/Controllers/Api/Widget
app/Http/Controllers/Auth
```

### 5.3 Routes
**Total registered routes: 978.** `routes/api.php` contains **999** `Route::` occurrences and is
the primary route file. `routes/` also contains `web.php`, `exec-api.php`, and **56 `.bak-*`
copies of `api.php`**.

Route count by top-level segment (top 40):
```
152 api/seo          60 api/crm        47 api/studio     41 api/connector
132 api/admin        51 api/marketing  45 api/builder    23 api/internal
23 api/sarah         23 api/social     20 api/projects   20 api/workspace
16 api/chatbot       16 api/infrastructure  15 api/creative  15 api/write
13 api/mentions      13 api/public     13 api/tasks      10 api/auth
10 api/billing        9 api/approvals   8 api/intelligence  7 api/experiments
 7 api/team           6 api/agents      6 api/meeting     6 api/notifications
 6 api/settings       6 api/system      5 api/agent       5 api/beforeafter
 5 api/calendar       5 api/content-packs  5 api/manualedit  5 api/media
 5 api/publish-queue  5 api/traffic     4 api/email       4 api/policy
```
**Admin route groups** (`routes/api.php`): `admin/orchestration` (:127), `admin/plans` (:171),
`admin/knowledge` (:196), `admin/strategy` (:208), `admin/agents` (:232), `admin` (:258),
plus a group at :13792 and two `DenyApiKeyAuth`-protected groups at :19286 and :19311.

*Evidence: `php artisan route:list --json`, `grep -n "prefix('admin')" routes/api.php`.*

### 5.4 Engines (`app/Engines/`) — 17
```
BeforeAfter · Builder · CRM · Calendar · Chatbot · Content · Creative ·
Infrastructure · ManualEdit · Marketing · Mention · SEO · Social · Studio ·
TrafficDefense · Web · Write
```

### 5.5 Core modules (`app/Core/`) — 27
```
Admin · Agent · Agents · Audit · Auth · Billing · Brand · DesignTokens ·
Distribution · EngineKernel · Governance · Integrity · Intelligence · LLM ·
LaunchScope · Meetings · Memory · Notifications · Orchestration · PlanGating ·
Projects · Publisher · Strategy · SystemHealth · TaskSystem · Tenancy · Workspaces
```

### 5.6 Data inventory (178 tables — row counts at capture)
| Domain | Tables (rows) |
|---|---|
| **Users / access** | `users` (11) · `sessions` (936) · `api_keys` (3) · `workspace_users` (28) · `pending_invites` (0) · `password_reset_tokens` (1) |
| **Workspaces / projects** | `workspaces` (25) · `projects` (3) · `websites` (3) · `pages` (10) · `workspace_goals` (1) · `workspace_agents` (188) |
| **Agents / orchestration** | `agents` (20) · `agent_capabilities` (381) · `agent_delegations` (92) · `agent_messages` (1666) · `agent_experience_stats` (47) · `agent_workspace_memory` (147) · `agent_web_activity` (106) · `agent_execution_plans` (0) · `execution_plans` (11) |
| **Tasks / approvals** | `tasks` (2411) · `task_events` (12561) · `plan_tasks` (164) · `approvals` (125) · `jobs` (0) · `failed_jobs` (0) · `scheduled_actions` (0) |
| **SEO** | `seo_links` (4806) · `seo_serp_results` (3774) · `seo_audit_items` (3523) · `seo_activity_log` (3443) · `seo_link_graph` (2152) · `seo_audits` (576) · `seo_content_index` (510) · `seo_outbound_links` (399) · `seo_cluster_members` (363) · `seo_images` (255) · `seo_audit_snapshots` (175) · `seo_clusters` (83) · `seo_keywords` (65) · `seo_insights` (29) · `seo_score_weights` (12) · `seo_settings` (10) · `seo_redirects` (4) · `seo_ai_reports` (2) · `seo_goals` (0) · `seo_404_log` (0) |
| **AEO** | `aeo_traffic` (1881) · `aeo_score_snapshots` (234) · `aeo_audits` (197) · `aeo_settings` (4) |
| **Content / media** | `articles` (380) · `article_versions` (439) · `media` (758) · `assets` (392) · `content_packs` (1) · `blog_categories` (0) |
| **CRM** | `leads` (29) · `pipeline_stages` (20) · `contacts` (8) · `notes` (1) · `deals` (0) · `activities` (10) |
| **Chatbot** | `chatbot_knowledge_chunks` (328) · `chatbot_messages` (197) · `chatbot_sessions` (63) · `chatbot_knowledge_sources` (57) · `chatbot_settings` (24) · `chatbot_widget_tokens` (15) · `chatbot_usage_logs` (9) · `chatbot_escalations` (1) |
| **Creative / Studio** | `creative_memory_records` (1126) · `studio_video_templates` (49) · `studio_designs` (27) · `studio_brand_kits` (1) · `creative_brand_identities` (1) · `creative_blueprints` (0) · `creative_video_jobs` (0) · `studio_templates` (0) · `studio_elements` (0) · `studio_design_history` (0) · `design_tokens` (0) · `ba_designs` (0) |
| **Infrastructure (INFRA888)** | `infra_monitor_results` (1749) · `infra_certification_checks` (30) · `infra_events` (10) · `infra_provider_events` (9) · `infra_monitor_daily` (7) · `infra_operations` (4) · `infra_assets` (3) · `infra_provider_capabilities` (3) · `infra_hosting_accounts` (2) · `infra_provider_certifications` (2) · `infra_asset_relationships` (2) · `infra_hosted_sites` (1) · `infra_governance_state` (1) · `infra_monitor_checks` (1) · `infra_providers` (1) · `infra_provider_resources` (1) · **+13 infra tables at 0 rows** |
| **Billing / plans** | `credit_transactions` (4296) · `subscriptions` (24) · `credits` (16) · `plans` (10) |
| **Marketing / email** | `email_blocks` (742) · `email_templates` (115) · `campaigns` (0) · `sequences` (0) · `sequence_steps` (0) · `sequence_enrollments` (0) · `email_links` (0) · `email_campaigns_log` (0) |
| **Social** | `social_accounts` (0) · `social_posts` (0) |
| **Strategy / intelligence** | `strategy_proposals` (1474) · `workspace_knowledge` (1228) · `engine_intelligence` (587) · `meeting_messages` (105) · `meetings` (12) · `meeting_participants` (49) · `meeting_tasks` (23) · `workspace_memory` (41) · `engine_registry` (1) |
| **Observability** | `traffic_logs` (298830) · `audit_logs` (7557) · `api_usage_logs` (3749) · `notifications` (3051) · `dfs_usage_log` (253) · `automation_events` (41) |
| **Bella** | `bella_conversations` (0) · `bella_memory` (0) |
| **Brand monitoring** | `brand_mentions` (89) · `brand_mention_scan_runs` (50) · `brand_watchlist` (3) |
| **Misc** | `gsc_metrics` (529) · `keyword_rank_history` (54) · `builder_default_assets` (31) · `plan_publish_queue` (7) · `canvas_states` (2) · `gsc_connections` (1) · `admin_appointments` (0) · `platform_settings` (0) · and others at 0 rows |

*Evidence: `SHOW TABLES` + `SELECT COUNT(*)` per table via bootstrapped container.*

### 5.7 Console commands (40 registered)
```
AeoCleanupTrafficCommand · AeoSnapshotCommand · AgentReportCompletionsCommand ·
AuditAgentCapabilitiesCommand · BackfillFeaturedImagesCommand · BackfillThumbnails ·
GscSyncAllCommand · HouseAccountProactiveCommand · InfraReportCommand ·
IntelligenceAuditCommand · IntelligenceSeedCommand · MentionScanDailyCommand ·
PruneDeviceTokensCommand · PurgeNotifications · QueueHealthReportCommand ·
ReapStuckRendersCommand · ReconcileCustomDomains · RecoverStaleTasksCommand ·
RefreshKeywordVolumeCommand · ReindexBuilderPagesCommand · ReplenishHouseCreditsCommand ·
RunDuePlanTasksCommand · RunScheduledActions · RunSequences · RuntimePingCommand ·
SarahAutoExecuteCommand · SarahMonthlyStrategyCommand · SarahMorningBriefCommand ·
SarahProactiveCheck · SarahWeeklyReviewCommand · SeoAuthorityScoreCommand ·
SeoInsightWatcherCommand · SeoOutboundCheckCommand · SeoPurgeChatHistoryCommand ·
SeoRankTrack · SeoSemanticClusterCommand · SeoSerpRefresh · SmokeTestCommand ·
SyncChatbotWebsiteContentCommand · SyncGscRanksCommand
```

### 5.8 Scheduled tasks (29 entries)
```
0 6 * * *      trial:expire
0 2 * * 1      seo:track-ranks
15 2 1 * *     seo:refresh-volume
20 * * * *     agents:report-completions
0 4 * * *      aeo:snapshot
0 5 * * *      gsc:sync-all
20 5 * * *     seo:sync-gsc-ranks
30 4 * * *     seo:reindex-builder-pages
15 4 * * *     aeo:cleanup-traffic
30 2 * * 1     seo:serp-refresh
0 0 * * *      seo:insights
2 3 * * *      seo:authority-score
2 4 * * *      seo:purge-chat-history
0 2,14 * * *   seo:outbound-check
0 4 * * 0      seo:cluster
0 0 1 * *      credits:replenish-house
0 4 * * 1      house:weekly-proactive
0 * * * *      sarah:morning-brief
0 * * * *      sarah:weekly-review
0 * * * *      sarah:monthly-strategy
5 0 * * *      lu:notifications:purge
0 0 * * 0      builder:prune-snapshots
0 * * * *      credits:reap-orphans
*/15 * * * *   tasks:recover-orphans
*/15 * * * *   proposals:sync-approvals
*/15 * * * *   infra:reap-operations
0 * * * *      infra:sweep-credential-expiry
*/5 * * * *    infra:run-monitor-checks
0 * * * *      infra:rollup-monitor-daily
```
*Evidence: `php artisan schedule:list`.*

### 5.9 Commercial state
**Plans (10):** Free · Starter · AI Lite · Growth · Pro · Agency · WP SEO Bundle · WP Growth ·
WP Pro · WP Agency
**Subscriptions:** 24 total, **all `active`**

### 5.10 Hidden / non-obvious modules present
| Module | Evidence |
|---|---|
| `app/Http/Controllers/Api/Debug/` | directory exists |
| `app/Http/Controllers/Api/Widget/` | directory exists |
| `routes/exec-api.php` | separate route file |
| `app/Core/LaunchScope/` | launch-scope enforcement layer |
| `app/Core/Integrity/`, `app/Core/Governance/`, `app/Core/SystemHealth/` | core modules |
| `public/admin/` | `index.php` = `<?php header("Location: /admin/dashboard"); exit;` (50 bytes); sibling dir literally named `{js,css}` and empty |
| `BOSS888_DEBUG_ENABLED` | env key present |

---

## 6. INFRASTRUCTURE

| Component | State | Evidence |
|---|---|---|
| **Redis** | v6.0.16, active, 82 d uptime; db0 3013 keys, db1 160, db3 24, db9 10 | `redis-cli INFO` |
| **Queues** | `tasks-high`, `tasks`, `tasks-low`, `default` — **all depth 0** | `Redis::llen("queues:*")` |
| **Supervisor** | `levelup-worker:00/01/02` RUNNING | `supervisorctl status` |
| **Cron** | root: 6 entries (certbot, backups, snapshots, PTAA scheduler); www-data: 1 (Laravel scheduler) | `crontab -l`, `crontab -u www-data -l` |
| **Scheduler** | 29 scheduled commands, firing (next due timers observed) | `schedule:list` |
| **Workers** | 3 LevelUp + 1 PTAA | `ps aux` |
| **Storage** | `storage/` 1.7 G; symlink intact; DO Spaces `boss888-backups` @ ams3 | `du`, `ls -la`, `.env` |
| **Backups** | `/root/backups` = **14 G**; nightly `db-YYYYMMDD-0100.sql.gz` + `mr-*.sql.gz` present for 07-24, 07-25, 07-26; `daily-backup.sh`, `backup-files.sh`, `do-snapshot.sh` | `du -sh`, `ls -1t` |
| **Email** | `MAIL_MAILER=postmark`, `EMAIL_CONNECTOR_DRIVER=postmark`, from `hello@levelupgrowth.io` | `.env` |
| **Domains** | 7 `server_name` blocks incl. `levelupgrowth.io`, `*.levelupgrowth.io`, `chefredraymundo.com`, `amgtravelandtours.com`, `markraymundo.com`, `clutter-angels.levelupgrowth.io` | nginx configs |
| **Hosting (INFRA888)** | `infra_hosting_accounts` 2 · `infra_hosted_sites` 1 · `infra_providers` 1 · 13 infra tables at 0 rows | SQL counts |
| **Cloudflare** | `CLOUDFLARE_API_TOKEN` + `CLOUDFLARE_ZONE_ID` set; `config/cloudflare.php` exists; 21 files reference it | `.env`, `ls config/` |
| **Sentry** | `SENTRY_LARAVEL_DSN` + `SENTRY_TRACES_SAMPLE_RATE` set; `config/sentry.php` exists | `.env` |
| **PostgreSQL** | active (v14.23) — not the Laravel default connection | `systemctl`, `config('database.default')=mysql` |

**Config files present (18):** `app, auth, billing, cache, cloudflare, connectors, database,
execution, infrastructure, intelligence, llm, marketing, queue, queue_control, redis, scale,
sentry, studio` (plus 3 `.bak` variants). **`config/filesystems.php` is absent.**

---

## 7. CURRENT OPERATIONAL STATE

### 7.1 Queues
| Queue | Depth |
|---|---|
| `queues:tasks-high` | 0 |
| `queues:tasks` | 0 |
| `queues:tasks-low` | 0 |
| `queues:default` | 0 |
| `jobs` table | 0 |
| **`failed_jobs` table** | **0** |

### 7.2 Task state (all time, `tasks` = 2411 rows)
| status | count |
|---|---|
| completed | 2020 |
| **failed** | **213** |
| cancelled | 91 |
| blocked | 63 |
| pending | 19 |
| degraded | 5 |

### 7.3 State since the v2.37.4 runtime deploy (08:45 UTC → capture)
| status | count |
|---|---|
| completed | **88** |
| failed | **0** |

`'supported API model names'` occurrences in `laravel.log` since 08:45 UTC: **0**.

### 7.4 Observed transient condition (recorded, not assessed)
`request_timeout` (HTTP 503 from the runtime) appears in `laravel.log`:
**42 lifetime · 12 on 2026-07-26 · 8 since 08:45 UTC.**
Example: task 2606 (workspace 26, `write_article`) logged `request_timeout` at 09:00:53 and
subsequently reached status `completed` at 09:01:48. No task since 08:45 UTC is in `failed`.

*Evidence: `grep -c request_timeout storage/logs/laravel.log`; `SELECT` on `tasks` id 2606.*

### 7.5 api_usage_logs by model
| provider | model | rows | last seen |
|---|---|---|---|
| deepseek | **deepseek-v4-flash** | 28 | 2026-07-26 09:05:05 |
| deepseek | gpt-4o-mini | 70 | 2026-07-26 05:00:28 |
| deepseek | deepseek-chat | 3651 | 2026-07-24 13:37:23 |

### 7.6 Recent deployments
| When | What | Evidence |
|---|---|---|
| 2026-07-26 ~08:45 UTC | **Runtime v2.37.4** (DeepSeek V4 restoration) deployed to Railway | behavioural probe §3.4 |
| 2026-07-26 07:53:23 | Laravel commits `ccc4ade`, `918654b`, `416686e` (Studio freeze) | `git log` |
| 2026-07-24 | Last Laravel `.bak` change markers (`Orchestrator.php.bak-b28-20260724-230734` etc.) | `find -newermt` |
| 2026-07-21 | Runtime v2.37.3 deployed | `RUNTIME-2.37.3-DEPLOY-AND-ROLLBACK.md` |

### 7.7 Known incidents
**INC-2026-001 — DeepSeek model retirement** · P1 · **RESOLVED 2026-07-26**.
Onset 2026-07-24 13:37 UTC, detected 2026-07-26 ~07:45 UTC (~42 h undetected), resolved
~08:45 UTC. 36 failed tasks / 72 h across 10 workspaces. Full record: `INCIDENT-REGISTER.md`.

### 7.8 Known technical debt (13 items on the register at capture)
`TD-01` Laravel provider attribution · `TD-02` V4 pricing unverified · `TD-03` cost keyed on
provider not model · `TD-04` no provider-error alerting · `TD-05` `stream-init` body-parser ·
`TD-06` runtime streaming unverified · `TD-07` V4 vision unverified · `TD-08` no lockfile ·
`TD-09` runtime has no git repo · `TD-10` 213 failed + 78 blocked tasks unreplayed ·
`TD-11` version drift · `TD-12` `lu-task-worker-fn` missing · `TD-13` no Laravel DeepSeek tests.
Source: `START-HERE-2026-07-26.md` §4.

### 7.9 Open TODOs / outstanding risks of record
1. Replay policy for 213 failed + 78 blocked tasks — **awaiting Boss decision**
2. DeepSeek V4 pricing lookup — **awaiting Boss action**
3. OpenAI fallback retention decision — **awaiting Boss decision**
4. Whether to bump `/health` to `2.37.4` — **awaiting Boss decision**
5. Disk at **74 %** (13 G free) with `/root/backups` at 14 G
6. Runtime deploys require Boss action — no Railway CLI/token in the working environment
7. STUDIO888 MRC-2A paused at `416686e`; pre-V4 / post-V4 readiness data must stay segmented

---

## 8. SECURITY — INVENTORY ONLY

### 8.1 Authentication
| Mechanism | Evidence |
|---|---|
| JWT | `app/Http/Middleware/JwtAuthMiddleware.php`; `JWT_SECRET`, `JWT_TTL`, `JWT_REFRESH_TTL` in `.env` |
| API key | `app/Http/Middleware/ApiKeyAuth.php`; `api_keys` table (3 rows, 3 active) |
| API-key denial | `app/Http/Middleware/DenyApiKeyAuth.php` (applied to 2 admin route groups) |
| MFA step-up | `app/Http/Middleware/RequireMfaStepUp.php`; `users.mfa_enabled/mfa_secret_encrypted/mfa_recovery_codes_encrypted/mfa_confirmed_at/mfa_last_verified_at` |
| Admin panel token | `POST /admin/auth` compares `BELLA_ADMIN_TOKEN` via `hash_equals`, then issues a JWT **for user id 1** |
| Session | `sessions` table (936 rows); guards `web` and `api` both use the `session` driver |

**Laravel auth guards** (`config/auth.php`): default guard `web`; `api` guard is explicitly a
session driver (comment states it exists so `Auth::guard('api')` does not throw for legacy code).

### 8.2 Authorization
| Mechanism | Evidence |
|---|---|
| `AdminMiddleware` | `app/Http/Middleware/AdminMiddleware.php:18` → `if (! $user->is_platform_admin)` |
| `TeamRoleMiddleware` | `app/Http/Middleware/TeamRoleMiddleware.php` |
| `PlanMiddleware`, `AeoPlanGate` | plan-based gating middleware |
| `LaunchScopeRoutes` | launch-scope route guard |
| **`app/Policies/`** | **directory does not exist** |
| Role/permission tables | **none** — closest matches are `agent_capabilities` (381) and `infra_provider_capabilities` (3) |
| Role model | column-based on `users`: `is_admin`, `is_platform_admin`, `account_classification` |

**User counts:** total 11 · `is_admin=1` → 2 · `is_platform_admin=1` → 2 · `mfa_enabled=1` → **0**

### 8.3 Middleware inventory (excluding `.bak`)
```
AdminMiddleware · AeoPlanGate · ApiKeyAuth · ConnectorBrandFilter · CorsMiddleware ·
DenyApiKeyAuth · JwtAuthMiddleware · LaunchScopeRoutes · PlanMiddleware ·
PublishedSiteMiddleware · RequireMfaStepUp · RuntimeSecretMiddleware ·
SecurityHeadersMiddleware · StagingNoindex · TeamRoleMiddleware ·
TrafficDefenseMiddleware · TrustProxies
```

### 8.4 Secrets handling / environment strategy
- **83 environment keys** in `.env`. Single `.env` file; no per-environment variants in use
  (`.env.example`, `.env.apr25-secrets`, `.env.bak-gsc-creds-20260612` also exist on disk).
- **No values were read or recorded** during this capture — key names only.
- Secret-bearing key names present: `APP_KEY`, `JWT_SECRET`, `DB_PASSWORD`, `REDIS_PASSWORD`,
  `DEEPSEEK_API_KEY`, `OPENAI_API_KEY`, `MINIMAX_API_KEY`, `RUNWAY_API_KEY`, `POSTMARK_TOKEN`,
  `CLOUDFLARE_API_TOKEN`, `DO_SPACES_KEY`, `DO_SPACES_SECRET`, `DATAFORSEO_PASSWORD`,
  `FACEBOOK_APP_SECRET`, `GSC_CLIENT_SECRET`, `LU_SECRET`, `RUNTIME_SECRET`,
  `BELLA_ADMIN_TOKEN`, `SOCIAL_CONNECTOR_API_KEY`, `SENTRY_LARAVEL_DSN`,
  `STRIPE_PUBLISHABLE_KEY`, `DB_SSL_CA`.
- **`platform_settings` table exists but is empty (0 rows)** — `DeepSeekConnector` reads an
  encrypted `api_key_deepseek` from it as a fallback when the env key is empty.
- `api_keys` table columns: `id, workspace_id, user_id, key, name, type, scopes, last_used_at,
  expires_at, is_active, created_at, updated_at`.

### 8.5 Audit logging
| Table | Rows | Last entry |
|---|---|---|
| `audit_logs` | 7557 | 2026-07-26 09:08:24 |
| `task_events` | 12561 | 2026-07-26 09:08:24 |
| `seo_activity_log` | 3443 | 2026-07-26 09:07:18 |
| `api_usage_logs` | 3749 | 2026-07-26 09:05:05 |
| `traffic_logs` | 298830 | 2026-07-25 15:45:29 |
| `agent_web_activity` | 106 | 2026-07-20 00:00:15 |
| `automation_events` | 41 | 2026-07-24 12:36:27 |
| `dfs_usage_log` | 253 | 2026-07-24 19:27:26 |
| `infra_events` | 10 | 2026-07-23 18:02:40 |
| `infra_provider_events` | 9 | 2026-07-19 19:01:37 |

Additional security-relevant middleware: `SecurityHeadersMiddleware` (sets CSP including
`connect-src` for `api.stripe.com`, `api.openai.com`, `api.deepseek.com`), `StagingNoindex`,
`TrafficDefenseMiddleware`, `CorsMiddleware`.

---

## 9. ENGINEERING READINESS

Classification key — **Implemented** (working code + schema + routes) · **Partial** (some layers
present, others absent) · **Placeholder** (named only, no logic) · **Not present** (no artefact).

| Capability | Status | Evidence |
|---|---|---|
| **Engineering888** | **NOT PRESENT** | `grep -rIl -i 'engineer888\|engineering888' /var/www /root --include=*.php --include=*.js --include=*.md --include=*.json` returns **only the 2026-07-26 documentation files that name it as future work**. `find app database routes -iname '*engineer*'` → empty. No table matches `engineer`. |
| **Engineering dashboard** | **NOT PRESENT** | no controller, route, or view matches |
| **Engineering tables** | **NOT PRESENT** | `SHOW TABLES` — no table matching `engineer` |
| **Engineering scheduler** | **NOT PRESENT** | `schedule:list` — no engineering entries among the 29 |
| **Engineering reports** | **NOT PRESENT** | no matching command or controller |
| **Engineering queues** | **NOT PRESENT** | queues are `tasks-high, tasks, tasks-low, default` only |
| **Engineering database** | **NOT PRESENT** | single `levelup_staging` MySQL DB; no engineering schema |
| **Engineering permissions** | **NOT PRESENT** | no role/permission table; access is `is_admin`/`is_platform_admin` columns |
| **Engineering runtime** | **NOT PRESENT** | runtime `/health` advertises 10 agents / 43 tools, none engineering-related |
| **Bella** | **IMPLEMENTED** (unused) | see §9.1 |

### 9.1 Bella — implemented, currently unused
> **Correction of record:** earlier session notes described Bella as "an admin persona and a
> controller". The evidence shows a substantially larger implementation. Recorded here as fact.

| Layer | State | Evidence |
|---|---|---|
| Controller | **1,748 lines**, 38 methods | `wc -l`, `grep "function"` on `app/Http/Controllers/Api/Admin/BellaController.php` |
| Constructor | injects `RuntimeClient` (not `DeepSeekConnector` — removed in PATCH 4, 2026-05-08) | file header comment |
| Schema | `bella_conversations` (session_id, user_id, role, content, action_executed, action_result) · `bella_memory` (category, key, value, confidence, source, expires_at; unique on category+key) | `database/migrations/2026_04_09_200000_create_bella_tables.php` |
| **Data** | **`bella_conversations` = 0 rows · `bella_memory` = 0 rows** | SQL counts |
| Routes | `POST /api/admin/bella` · `POST /api/admin/bella/vision` · `GET /api/admin/bella/artifacts` · `GET /api/admin/bella/video-status/{assetId}` | `routes/api.php:14061-14086` |
| Auth | `BELLA_ADMIN_TOKEN` env key; `POST /admin/auth` verifies it and issues a JWT for user id 1 | `routes/api.php:88-100` |
| Capabilities implemented | persistent memory (store/forget/parse), schema map, safe SQL execution, platform context gathering, recent log errors, system prompt builder, action allow-list and dispatcher | method list |
| Actions implemented | `query_database`, `remember`, `forget`, `list_users`, `get_analytics`, `get_workspace`, **`adjust_credits`**, `get_queue`, `generate_report`, `get_audit_logs`, `get_engine_status`, **`suspend_user`** | method list |
| Generation | image, document, presentation, video (with `video-status` polling) | `handleImageGeneration`, `handleDocumentGeneration`, `handlePresentationGeneration`, `handleVideoGeneration` |
| Admin panel entry | `public/admin/index.php` → `header("Location: /admin/dashboard")` (50 bytes). Sibling directory literally named `{js,css}` and **empty**. | `cat`, `ls -la` |

**Summary of state:** code path **Implemented**; data **empty**; front-end assets **absent**.

---

## 10. EVIDENCE INDEX

Every claim above derives from one of the following, all executed read-only between
2026-07-26 10:08:44 and 10:22 UTC.

| # | Evidence source | Used for |
|---|---|---|
| E1 | `git rev-parse`, `git log`, `git status --porcelain`, `git branch -a`, `git tag`, `git remote -v` | §1 |
| E2 | `php artisan --version`, `php -v`, `php -m` | §1, §2 |
| E3 | `hostname`, `uname -a`, `lsb_release -d`, `uptime`, `free -h`, `df -h` | §2.1 |
| E4 | `systemctl is-active`, `nginx -v`, `mysql --version`, `psql --version`, `redis-server --version`, `node --version`, `npm --version` | §2.3 |
| E5 | `ls /etc/nginx/sites-enabled/`, `grep server_name /etc/nginx/sites-enabled/*` | §2.5, §6 |
| E6 | `redis-cli INFO server`, `redis-cli INFO keyspace` | §2.7, §6 |
| E7 | `supervisorctl status`, `ps aux \| grep queue:work`, `cat /etc/supervisor/conf.d/levelup-worker.conf` | §2.8 |
| E8 | `crontab -l`, `crontab -u www-data -l`, `systemctl list-timers` | §2.9 |
| E9 | `du -sh storage/ public/ /root/backups`, `ls -la public/storage`, `ls -1t /root/backups/` | §2.10, §6 |
| E10 | `ls -la bootstrap/cache/` | §2.11 |
| E11 | Bootstrapped Laravel container: `config('app.*')`, `config('database.*')`, `config('llm.*')`, `env(...)` | §2.2, §2.6, §4.1 |
| E12 | `SHOW TABLES` + `SELECT COUNT(*)` per table (178 tables) | §5.6 |
| E13 | `SELECT COUNT(*) FROM migrations`, latest 8 rows | §2.6 |
| E14 | `Schema::getColumnListing('users')`, `('api_keys')`; grouped counts on `is_admin` | §8 |
| E15 | `curl -s https://levelup-runtime2-production.up.railway.app/health` | §3.1–3.3 |
| E16 | `POST /ai/run` with `model:"deepseek-chat"` and `tier:"turbo"` (2 read-only probes) | §3.4 |
| E17 | `GET https://api.deepseek.com/models` (authenticated, read-only) | §3.6 |
| E18 | `grep -rn -i` for `gemini\|claude\|anthropic\|cohere\|mistral\|ollama\|llama` over `app config routes` | §4.2 |
| E19 | `ls app/Connectors/*.php`, `ls app/Engines/`, `ls app/Core/`, `find app/Http/Controllers -ipath '*Admin*'` | §4.3, §5 |
| E20 | `php artisan route:list --json` (978 routes, aggregated by segment) | §5.3 |
| E21 | `php artisan schedule:list` (29 entries) | §5.8 |
| E22 | `ls app/Console/Commands/*.php` | §5.7 |
| E23 | `ls app/Http/Middleware/`, `ls app/Policies/` (absent), `config/auth.php` | §8 |
| E24 | `grep -oE '^[A-Z0-9_]+=' .env` — **key names only, no values read** | §8.4 |
| E25 | `Redis::llen("queues:*")`, `SELECT COUNT(*)` on `jobs`/`failed_jobs` | §7.1 |
| E26 | `SELECT status, COUNT(*) FROM tasks GROUP BY status` (all-time and since 08:45) | §7.2, §7.3 |
| E27 | `grep -c 'request_timeout' storage/logs/laravel.log`; `SELECT` on task 2606 | §7.4 |
| E28 | `SELECT provider, model, COUNT(*), MAX(created_at) FROM api_usage_logs GROUP BY ...` | §7.5 |
| E29 | `wc -l` + `grep "function"` on `BellaController.php`; `cat` of the Bella migration; `grep -n bella routes/api.php`; `cat public/admin/index.php` | §9.1 |
| E30 | `grep -rIl -i 'engineer888\|engineering888' /var/www /root`; `find -iname '*engineer*'` | §9 |
| E31 | `SELECT` on `plans`, `subscriptions` | §5.9 |
| E32 | `find app routes config -name '*.bak-*' -newermt '2026-07-20'` | §7.6 |

**No configuration was changed. No file was written to the platform. No service was restarted.
No code was modified. No fix was applied.**

---

## 11. FREEZE

```
════════════════════════════════════════════════════════════════════
ADMIN AUDIT BASELINE COMPLETE
════════════════════════════════════════════════════════════════════

Date              : 2026-07-26
Time              : 10:08:44 – 10:22:00 UTC (capture window)

Environment       : Single-instance Laravel — APP_ENV=staging, serving
                    levelupgrowth.io and live customer domains.
                    Host: Level-Up-Growth / 134.209.93.41 (DigitalOcean,
                    Ubuntu 22.04.5 LTS). No separate production instance.

Runtime version   : /health reports  2.37.3
                    Deployed content  v2.37.4 (DeepSeek V4 restoration,
                    deployed 2026-07-26 ~08:45 UTC)
                    Verified by behavioural probe: retired model name
                    returns DEEPSEEK_INVALID_MODEL

Laravel commit    : 416686e2fb75cca4c0a245a0d60f230ced178429
                    2026-07-26 07:53:23 +0000 — "BOSS888 Deploy"
                    "test(studio): characterize execution shadow comparison
                     and compiler readiness"

Runtime commit    : NONE — the runtime has no git repository.
                    Identity is archive contents + behavioural probe.
                    Package: LVL\runtime-v2.37.4-deepseek-v4\ (89 files)

Branch            : feature/studio888-mrc-2a
                    ⚠ NOT a mainline branch. master exists, not checked out.
                    Tag studio888-mrc-2a-pre-deepseek-v4 and branch
                    preserve/studio888-mrc-2a-pre-deepseek-v4 mark this point.

Git status        : 2 modified, 4 untracked — ALL documentation.
                     M BOSS888-MASTERCONTEXT-2026-07-20.md
                     M BOSS888-STATE.md
                    ?? ARCHITECTURE-AI-EXECUTION-LAYER.md
                    ?? INCIDENT-REGISTER.md
                    ?? RUNTIME-2.37.4-DEEPSEEK-V4-DEPLOY-AND-ROLLBACK.md
                    ?? START-HERE-2026-07-26.md
                    NO application code is uncommitted.

Scale at freeze   : 178 tables · 210 migrations · 978 routes · 17 engines ·
                    27 core modules · 10 admin controllers · 40 console
                    commands · 29 scheduled tasks · 25 workspaces · 11 users ·
                    2411 tasks · 24 active subscriptions across 10 plans

Operational       : queues 0/0/0/0 · failed_jobs 0 · 88 tasks completed and
                    0 failed since the 08:45 UTC runtime deploy ·
                    0 DeepSeek model errors since deploy

PLATFORM STATE FROZEN.
════════════════════════════════════════════════════════════════════
```

**This baseline is the official "before" state for the Laravel Admin Forensic Audit and all
future Engineering888 work. It contains no findings and no recommendations by design.**

**The Laravel Admin Forensic Audit has NOT been started.**
