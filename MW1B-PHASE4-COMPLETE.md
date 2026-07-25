# MW-1(b) — Phase 4: Product Experience Alignment
**VERDICT: COMPLETE WITH CONDITIONS** · 2026-07-24 · Live production · MW-1(a) gate intact, `PLATFORM_PUBLIC_LAUNCHED=false`.

## 1–2. Executive summary
Every product page now sits in one connected ownership ecosystem. A **data-driven journey block** (implemented once in `site.js`) renders "Your next step + Related products" on **7/7** product pages, so no page dead-ends. **Business Email and Email Marketing are now unmistakably separate.** A truthful **SEO page** shipped. The four AI pages are **consolidated behind server-side 301s** (replacing a client-side meta-refresh) with canonicals. `results.html` became an honest "stories when they're real" page. **Four additional fabricated claims were found and removed on pages I hadn't been asked to touch** (3 invented testimonials, a "10x" claim, a fabricated activity-stat row, and a "99.9% uptime guaranteed" SLA). Matrix **6/6 PASS**, 17/17 links 200, 0 app/staging links.

## 3. Product Experience Map / 4. Relationship diagram / 5. Hierarchy
Journey: **Launch → Own → Grow → Operate → Scale**, mapped Website → Domain → Business Email → Managed Hosting → SEO → CRM → Automation → AI Workforce.

| Page | Next step | Related |
|---|---|---|
| Website (`builder`) | **Connect your own domain** *(Coming soon)* | Business Email•, Managed Hosting•, Email Marketing |
| **SEO** (new) | Capture the leads it brings → CRM | Website, Email Marketing, AI Workforce |
| CRM | Let your AI Workforce run it → AI Workforce | Calendar, Email Marketing, SEO |
| Email Marketing | Organise the leads you generate → CRM | SEO, Creative, AI Workforce |
| Creative | Turn creative into video → Video | Email Marketing, SEO, Website |
| Video | Put it to work in campaigns → Email Marketing | Creative, SEO, CRM |
| Calendar | Track who books with you → CRM | Website, AI Workforce, Email Marketing |
| AI Workforce | See what each plan includes → Pricing | SEO, CRM, Website |
| Why LevelUp | See what each plan includes → Pricing | Website, SEO, AI Workforce |
| Results | See the honest case → Why LevelUp | Website, SEO, Pricing |
• = Coming-soon chip, non-clickable.

## 6. Email disambiguation audit
- **Nav/footer:** "Email Marketing" (growth layer) vs **"Business Email" — Coming soon** (identity layer) — separate entries, never adjacent in meaning.
- **`/pages/email/`:** title → "Email Marketing — campaigns, drafted by AI"; H1 → "Email Marketing drafted by AI"; meta description explicitly distinguishes; **inline callout**: *"Looking for mailboxes? This is Email Marketing (campaigns). Business Email — you@yourbrand.com — is a separate product, coming soon."*
- **Homepage Ownership Stack:** Business Email (identity, Coming soon) and Email Marketing (growth) are separate cards.
- Metadata, headings, CTAs, internal links all reviewed. **No remaining ambiguity.**

## 7. SEO page summary (`/pages/seo/` → 200)
Technical SEO · Content · Monitoring · Reporting, plus "AI assists — you decide" (researches, drafts, surfaces, recommends — **you approve**). Explicit disclaimer: *"We don't claim SEO runs itself, and we don't promise rankings."* Self-canonical. In nav (Own your growth) + footer + journey.

## 8. AI consolidation report
Hub = **`/pages/ai-agents/`** (retained URL to preserve equity), retitled **"AI Workforce — the team that runs what you own"**, self-canonical, count claims removed ("Six specialized agents" → role-based; "20 additional specialists" + "Browse All 20 Specialists" CTA removed — it self-redirected and was unverified). `assistant.html`'s client-side meta-refresh **upgraded to a real 301**. Nav/footer show one "AI Workforce" entry.

## 9. Redirect audit (all single-hop, no chains/loops)
| From | Status | To | Hops |
|---|---|---|---|
| `/pages/ai-assistant/`, `/pages/assistant/`, `/pages/specialists/` | **301** | `/pages/ai-agents` | 1 → 200 |
| `/ai-assistant`, `/assistant`, `/specialists` | **301** | `/pages/ai-agents` | 1 → 200 |
Target `/pages/ai-agents` (with and without slash) → **200 directly** (verified no second hop). Last internal link to `/pages/specialists/` removed (17→16 targets).

## 10. Canonical audit
Present + correct on `/pages/seo/`, `/pages/results/`, `/pages/why-levelup/`, `/pages/email/`, `/pages/ai-agents/`. Remaining pages → Phase 5.

## 11. Internal linking audit
**17/17 internal targets → HTTP 200, 0 non-200, 0 404s.** No links to redirecting URLs. 0 staging links on production. Journey + nav + footer all reinforce the same hierarchy.

## 12. Results page decision — **Option B**
Kept the URL (preserves equity), replaced content with an honest **"Customer stories, when they're real"** page: states we're pre-launch, publishes nothing invented, and documents the standard we'll hold results to (named consenting customers, stated baselines, timeframes, no projections-as-outcomes, failures reported too). Links to Why LevelUp.

## 13. CTA matrix (production)
All product-page primary CTAs resolve to **"Get notified"** via the gate (`ai-agents`, `email` dead `href="#"` CTAs replaced with the gated pattern). Journey CTAs are internal navigation or Coming-soon non-links. **0 `/app` links on production**; staging retains full app entry (5 links) for testing.

## 14. MW-1(a) verification matrix — **6/6 PASS**
`/app`, `/app/#signup`, Hero CTA, Pricing CTA, typed `/sign-up`, deep link — production gated / staging functional. 4× consistency: `nav=1, footer=1, appLinks=0, stagingLinks=0, comingSoonClickable=0, consoleErrors=[]`.

## 15. Desktop / mobile / accessibility
Desktop verified; mobile (390) menu toggles; nav dropdown trigger is a focusable `<button>`; existing design language, responsive behaviour, shared nav/footer preserved — no redesign.

## 16. Remaining Phase-5 work
1. **Pre-existing `builder.html` console error** `"Unexpected identifier 's'"` — **confirmed not introduced** (diff vs backup showed only the cache-bust version string). Needs an inline-script fix.
2. Canonicals for all remaining static pages; sitemap final pass.
3. Full **products-first pricing rebuild** (entitlement matrix, enterprise/sales path).
4. Dedicated **Domains / Business Email / Managed Hosting** pages when those products approach release (currently Coming-soon non-links by design).
5. Benign residuals intentionally left (not claims): "update content instantly" (UI), "stored instantly" (lead capture), a leftover `/* Testimonials */` CSS comment, FAQ example mentioning a "testimonials section".

## 17. Rollback
Backup **`/root/mw1b-p4-20260724-111347/`** (site.js, web.php, **all** pages). Restore `routes/web.php` (removes 301s + sitemap changes), `public/marketing/js/site.js` (removes journey + SEO nav), and any page from `backup/pages/`; delete `pages/seo.html`; revert version query `2.4.1-mw1b-p4`→`2.3.0-mw1b-p3`; `chown www-data`. No DB/nginx/config change; gate flag untouched (`false`).

## 18. Evidence directory
`C:\Users\markr\LVL\handoff-2026-07-24\screenshots-mw1a\` (P2/P3 shots) + this session's redirect/canonical/link/claim-scan and matrix logs. Checksums: `site.js fc9619f7…`, `ai-agents.html bc438fbe…`; pre-change `site.js 9d3fdedd…`, `web.php 03d7b147…`.
