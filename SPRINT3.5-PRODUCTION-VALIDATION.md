# Sprint 3.5 — Domains Production Launch Validation (Release Candidate)

**Purpose:** prove the **Connect Domain** journey works 100% in real Cloudflare before a paying customer touches it. **No new features, no UI, no architecture.** Exit only when the answer to *"Would I let a paying customer connect their domain today?"* is an unqualified **YES**.

**Definition of done ([[levelup-ship-not-code]]):** a real customer can Connect Domain → verify → HTTPS-live → disconnect, unaided, with failure paths recovering safely and a runbook in place. Until then S3 is *honestly gated*, not done. **S4 (Business Email) does NOT start until this sprint passes.**

**Current gate:** `CLOUDFLARE_SAAS_ENABLED=false`; `custom_domains` prod migration NOT run. Both flip only inside this sprint, with Boss authorization.

---

## A. Pre-flight — infrastructure readiness (blocking; needs Boss credential)
- [ ] **Scoped Cloudflare token** provisioned — Zone→SSL and Certificates→Edit + Zone→Zone→Read (+ Zone→DNS→Edit if managing fallback/routing records), zone-scoped to `levelupgrowth.io`, NOT a Global key. Set `CLOUDFLARE_API_TOKEN`.
- [ ] **Cloudflare for SaaS enabled** on the zone. Verify: `GET /zones/{zone}/custom_hostnames` returns `success:true` (not `10000`).
- [ ] **Fallback origin** set (proxied A/AAAA/CNAME in the zone) via `PUT /custom_hostnames/fallback_origin`; **routing target** published; set `CLOUDFLARE_SAAS_FALLBACK_ORIGIN` / `CLOUDFLARE_SAAS_ROUTING_TARGET`.
- [ ] **Origin app serves custom hostnames** — confirm the LevelUp origin routes an incoming `Host: <custom domain>` to the correct tenant website (this is the routing_status the lifecycle reports; must be real, not assumed).
- [ ] **Rate limits / API quotas** — confirm CF Custom Hostnames rate limits; the adapter already retries 429/5xx with backoff. Confirm the 100-free-hostname allowance is sufficient for launch volume; add alerting near the cap.
- [ ] **Logging / audit trail** — confirm `domains.*` correlation-id logs land in `laravel.log` (single file) and are queryable; every provider mutation logs workspace/website/hostname/action/result/correlation_id. Confirm no secrets logged.
- [ ] **Cleanup** — `domains:reconcile` scheduled (cron) to sync in-flight + retry orphan compensation + age-out never-created rows.

## B. Enablement steps (the runbook — execute in order)
1. Backup: `cp app/Services/CustomDomainService.php` + dump `custom_domains`+`websites` domain cols. (Backups dir: `/root/domains-sprint3-*`.)
2. **Run the additive prod migration (Boss authorization required):** `php artisan migrate --path=database/migrations/2026_07_24_070000_create_custom_domains_table.php`. Verify `Schema::hasTable('custom_domains')`.
3. Set env `CLOUDFLARE_SAAS_ENABLED=true` (+ token/fallback/routing). `php artisan config:clear` (NEVER `config:cache`).
4. Smoke: a read-only `getCustomHostname` on a nonexistent id → clean null (token works).
5. Keep the **customer UI still gated** (`infrastructure.js` — Connect button stays disabled for customers) until Section C+D pass. Validation uses a disposable hostname WE own, not the customer path.

## C. Live validation matrix — a DISPOSABLE hostname we own (never a customer's primary domain)
Use e.g. `qa-<rand>.levelupgrowth-owned-test-domain`. For each: assert state + provider truth + logs.
- [ ] **Create** → hostname created, provider id persisted immediately, state `awaiting_dns`, DNS/TXT instructions returned.
- [ ] **Retrieve / status sync** (`verify`) reflects real CF status.
- [ ] **DNS instructions** correct (routing CNAME + ownership TXT + SSL DCV) and addable at a real registrar.
- [ ] **Ownership validation** passes after records added → state advances.
- [ ] **Certificate issuance** → `ssl_status` progresses pending_validation→pending_issuance→pending_deployment→active; `ssl_issued_at` set.
- [ ] **Origin routing** → request to the custom hostname reaches the correct tenant website.
- [ ] **HTTPS response** → `https://<hostname>` returns 200 with a valid cert (no warning).
- [ ] **Status synchronization** → `active` only when ownership+SSL+routing all live (no premature "Connected").
- [ ] **Duplicate connect** → same hostname twice = idempotent, one CF hostname, no dupe.
- [ ] **Disconnect** → CF hostname deleted, columns cleared, state `disconnected`.
- [ ] **Provider deletion verified** → `getCustomHostname` returns null after disconnect.
- [ ] **Reconnect** → connecting again after disconnect succeeds cleanly.
- [ ] **Cleanup** → no orphaned CF hostnames left in the zone (list + assert).

## D. Failure-recovery scenarios (must recover safely, no orphans, honest UI)
- [ ] **Wrong DNS / expired DNS** → stays `awaiting_dns`/`validating` with actionable guidance; never false "Connected".
- [ ] **SSL pending / SSL failed** → surfaced as its own stage; failed shows guidance, retryable via `verify`.
- [ ] **Provider timeout** → adapter retries (429/5xx), then `failed` with retryable classification; `domains:reconcile` recovers.
- [ ] **Cloudflare outage** → healthCheck/degraded path returns state not exception; no partial writes.
- [ ] **Partial success** (CF create OK, local persist fails) → compensating delete runs; if compensation fails, row marked `orphaned_provider_hostname:*` and `domains:reconcile` retries the delete. (Unit-proven; re-prove live.)
- [ ] **Rollback / retry / job replay / idempotency** → replaying connect/verify/disconnect is safe; idempotency keys prevent dupes; reconcile is re-runnable.

## E. Documentation deliverables (author here)
- [ ] **Runbook** (Section B) — enable, migrate, gate, reconcile, monitor.
- [ ] **Rollback procedure:** set `CLOUDFLARE_SAAS_ENABLED=false`; for any live hostnames, `disconnect` (idempotent) or `domains:reconcile`; restore service from `/root/domains-sprint3-*`; the migration is additive (leave the table; or `migrate:rollback --path=` if fully reverting). Legacy `websites.custom_domain` stays consistent.
- [ ] **Incident recovery:** orphaned CF hostname → `domains:reconcile` (retries compensating delete) or manual `DELETE /custom_hostnames/{id}`; stuck `validating` → re-run `verify`; cert stuck → check DCV records; cross-tenant concern → tenant scope proven, but audit logs by correlation_id.
- [ ] **Support flow:** customer says "domain not working" → check `custom_domains.state` + `last_error` + `last_checked_at`; map state→customer message; "Check status" re-syncs; escalation = correlation_id in `laravel.log`.

## F. Release-Candidate exit criteria (ALL green → open customer gate)
fallback/routing configured · scoped token works · custom-hostname API succeeds · ownership validates · SSL active · HTTPS routes to the right tenant · no cross-tenant hostname access · duplicate idempotent · disconnect cleans up · reconciliation handles partial failure · **real browser journey passes (connect→verify→HTTPS→disconnect→reconnect)** · regression green · **runbook + rollback tested**. Only then: un-gate the customer Connect button (`infrastructure.js`) → **Customer Live**. Then S4 may begin.

**What I can do now (no credential):** everything in Section E (docs) + prep the live-test harness. **What needs Boss:** Section A token/zone + Section B migration authorization → then I execute C/D live and report the RC verdict.
