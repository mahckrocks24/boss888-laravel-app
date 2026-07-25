# MW-1(b) — Phase 3: Homepage Re-sequence + Why LevelUp + Truthfulness Cleanup
**VERDICT: COMPLETE WITH CONDITIONS** · 2026-07-24 · Live production change · MW-1(a) gate untouched, `PLATFORM_PUBLIC_LAUNCHED=false`.

## 1. Executive summary
The homepage now leads with **Business Ownership** ("Own your entire business online. Run by AI.") instead of the AI-tool narrative, the **Ownership Stack** is presented with truthful availability, and every fabricated claim has been removed or relabelled. A new **Why LevelUp** strategic page ships with the approved structure. Entry-point isolation is unchanged: matrix **6/6 PASS**, **0 `/app` links on production**, 0 console errors, single nav/footer, mobile + keyboard verified. Conditions are scope-deferred items (pricing depth, `results.html`, AI consolidation, remaining canonicals) that you assigned to Phases 4–5.

## 2. Exact files changed
- `public/marketing/index.html` — homepage re-sequenced + rewritten + claims removed.
- `public/marketing/pages/why-levelup.html` — **NEW** strategic page (served by existing `/pages/{slug}` route — no route edit needed).
- `public/marketing/js/site.js` — added "Why LevelUp" to desktop + mobile nav **only after the page was deployed**. Gate logic untouched.
- 18× `public/marketing/**.html` — cache-bust `site.js?v=2.2.0-mw1b` → `?v=2.3.0-mw1b-p3`.

## 3. Backup & checksums
- Backup: **`/root/mw1b-p3-20260724-104823/`** (`index.html`, `web.php`, `site.js`).
- `index.html` md5 **pre `3bdb3aad02b1748d5d8a1b70281f56f7`**. Post-deploy checksums captured during deploy (`19826cd4…` first pass; re-deployed after the "Live"-claim correction). `why-levelup.html` `2f2042cb…`, `site.js` `9d3fdedd…`.

## 4. Homepage before → after narrative map
| # | Before | After |
|---|---|---|
| Hero | "Build, Launch, and Grow Your Business — **Powered by AI**"; "…AI agents working **24/7**"; badge "AI Marketing Operating System"; CTA "Start Free" (dead `href="#"`) | **"Own your entire business online. Run by AI."**; ownership subhead (website, domain, business email, hosting, marketing, customer data); badge **"The Business Ownership Platform"**; CTA gated → **"Get notified"**; trust chips → *You own your website & domain / Your customer data stays yours / No agencies* |
| Hero card | "6 Active", **"Live Activity"**, "James: Running SERP analysis…", **142 / 38 / 29** | **"Product preview"**, **"Example workflow"**, generic workflow steps, **Your website / Your domain / Your customers**, caption *"Illustrative preview — not live customer data"* |
| Problem | "Marketing Today Is Too Complicated" (4 marketing tools) | **"Your business is scattered across a dozen providers"** — Website provider / Domain registrar / Email host / CRM & marketing |
| Solution | "One Platform. One AI Marketing Team." | **"One platform. Everything you own."** — ownership checklist (own site, domain+email, marketing, customer data, AI workforce, one login/bill) |
| Demo | "Watch Your AI Team **Work Live**", "**● LIVE**", "LevelUpGrowth AI — **Live Demo**", CTA "Try It For Real" | **"Product preview"** tag, "See how your AI workforce **would work**", **"EXAMPLE"** badge, explicit *"product preview, not live customer data"*, CTA → **"See how it works →"** |
| Products | "Everything You Need to Grow Online" (8 tool cards, "running 24/7") | **"The Ownership Stack — everything you own, in one place"** (10 cards, ownership-worded, availability states) |
| AI | "Meet Your **6-Agent** AI Marketing Team" | **"Your AI Workforce runs what you own"** — AI as the operating engine, human approval retained |
| Proof | "Real Results…" **24/7 · 10x Faster · 6+** + "View Results →" | **"You own it — not us, not an agency"** — you own site / domain+email / customer data + **"Why LevelUp →"** |
| Strip | "not just software… your AI marketing team" | **"You own your business online. LevelUp is the AI workforce that runs it."** |
| Pricing | "Start Free. Scale with AI." | **"Products first. AI credits scale."** + products-first intro (full rebuild deferred to P5 per your instruction) |
| Closing | "Your AI Marketing Team Is Ready to Work" + dead CTA | **"Own your business online. Let AI run it."** + gated CTA + **Why LevelUp** |

## 5. Why LevelUp page structure (`/pages/why-levelup/` → 200)
Hero ("Stop stitching your business together") → **The status quo** ("Eight providers. Eight logins. Eight bills." — website provider, domain registrar, email provider, hosting, CRM, marketing, automation, AI) → **The real cost** (disconnected data, repeated setup, multiple bills/logins, inconsistent ownership, integration maintenance, no shared operating context — **no invented monetary savings**) → **The LevelUp approach** (Presence · Identity · Platform · Growth · Customers · Operations · AI Workforce) → **Why it's different** (not an agency dependency / not disconnected point tools / not hosting-only / not an AI wrapper) → **Honest about readiness** (Domains, Business Email, Managed Hosting = Coming Soon, "you can't buy or activate them yet") → conversion (**Get notified** + **View pricing**). Uses existing design system only.

## 6. Claims removed / retained / relabelled
**Removed:** 142 Tasks Done · 38 Content Live · 29 Leads · "10x Faster" · "24/7" (all instances) · "Real Results from AI-Powered Marketing" proof block · link to `/pages/results/` · "6-Agent" count · "AI agents working 24/7".
**Relabelled:** hero card → "Product preview" / "Example workflow" + "not live customer data"; simulation → "Product preview" / "EXAMPLE" / explicitly illustrative.
**Retained (permitted):** AI personas (Sarah/James/Priya/…) as **product roles inside a labelled preview**; 3-step "how it works" (human-approval framing — "recommends actions you can approve").
**Corrected mid-phase:** I initially labelled built products **"Live · Explore →"** on the Ownership Stack. Since nothing is customer-usable on production pre-launch (gated), that was an unsupported "Live" claim — **removed** (built = "Explore →"; unbuilt = "Coming soon"). Verified: 0 "Live ·" badges, 7× "Explore →".

## 7. Product availability matrix (homepage + Why LevelUp)
| Product | State shown | Form |
|---|---|---|
| Website, Email Marketing, Creative, Video, CRM, Calendar, AI Workforce | *no Live claim* — "Explore →" | clickable card |
| **Domains** | **Coming soon** | non-link card |
| **Business Email** | **Coming soon** | non-link card |
| **Managed Hosting** | **Coming soon** | non-link card |
| SEO | not presented as a product | (Phase 4) |

## 8. CTA & entry-point verification matrix (final)
| Test | Production | Staging | Result |
|---|---|---|---|
| `/app` | Redirects → home | Opens app | ✅ |
| `/app/#signup` | Redirects → home | Opens signup | ✅ |
| Hero CTA | Waitlist ("Get notified") | Opens app | ✅ |
| Pricing CTA | Waitlist (0 /app links) | Opens signup | ✅ |
| Typed `/sign-up` | Blocked → home | Works | ✅ |
| Deep link `/app/dashboard` | Blocked → home | Works | ✅ |
4× prod consistency: `navCount=1, footerCount=1, appLinks=0, stagingLinks=0, comingSoonClickable=0, consoleErrors=[]`. Staging: `appLinks=5` (testable). Pricing plan buttons given `class="plan-cta"` so the gate resolves them to "Get notified" on production.

## 9. Broken-link results
All nav/footer destinations **200** (13 previously verified) + **`/pages/why-levelup/` → 200** + homepage **200**. No `/pages/results/`, `/pages/assistant/`, or dead `href="#" onclick="return false"` remain on the homepage. 0 staging links on production.

## 10. Desktop / mobile / keyboard
Desktop 1280 verified (screenshots). Mobile 390 menu toggles open. Nav dropdown trigger is a focusable `<button>`. Existing responsive system preserved (no new design language).

## 11. Console & rendering
0 console errors (excluding pre-existing unrelated 401s) on homepage ×4 + staging + Why LevelUp. Single nav + single footer on all pages (no duplicate injection). No layout shift observed.

## 12. Remaining Phase-4 issues
`results.html` still exists with **50%/65% + testimonials** (now unlinked from nav/footer/homepage — needs content fix or 301). AI consolidation + redirects/canonicals for `ai-agents`/`ai-assistant`/`assistant`/`specialists`. **SEO product page**. "20 Specialists" verification. Full **products-first pricing rebuild** (P5). **Canonicals on remaining static pages + sitemap cleanup** (P5) — Why LevelUp already has a self-canonical.

## 13. Rollback
Restore `index.html` (+ `site.js` if needed) from `/root/mw1b-p3-20260724-104823/`; delete `public/marketing/pages/why-levelup.html` and remove the "Why LevelUp" nav entry from `site.js`; revert version query `2.3.0-mw1b-p3`→`2.2.0-mw1b`; `chown www-data`. No DB/nginx/route/config change. Gate flag stays `false`.

## 14. Evidence directory
`C:\Users\markr\LVL\handoff-2026-07-24\screenshots-mw1a\` — `MW1B-P3-home-hero.png` (ownership hero + Product-preview card), `MW1B-P3-home-stack.png` (Ownership Stack), `MW1B-P3-why-levelup.png`. Plus P2 nav/footer shots. Matrix + structural logs captured this session.
