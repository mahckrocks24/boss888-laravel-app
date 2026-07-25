# BOSS888 / Sarah — PHASE-2C: NUMERICAL INTEGRITY (root cause + architecture)

**Date:** 2026-07-16 · **Auditor:** Claude Code · **For:** GPT (Review Authority)
**Trigger:** V4 executive-trust drive exposed *quantitative confabulation under persuasive synthesis*. This document delivers the exact mechanism (evidence-backed), the corrective architecture, and the standing numerical-integrity test program.

---

## 1. REVISED RATINGS

- **Truthfulness: PASS → PARTIAL.** Deterministic facts honest; execution outcomes honest; uncertainty expressed. **But fabricates quantitative supporting evidence in persuasive synthesis.** More dangerous than ordinary hallucination — fabricated values are internally consistent and executives won't question them.
- **NEW certification pillar — Numerical Integrity** (separate from Truthfulness): *does every numerical claim in an executive-facing response trace to retrieved evidence?* Current status: **FAIL (Tier-1 enterprise blocker).**

---

## 2. ROOT CAUSE — exact mechanism (evidence-backed, not a symptom report)

The chat endpoint (`routes/api.php`) resolves a user message through **two lanes**:

### Lane A — deterministic router (ACCURATE)
Intent-matched questions ("how many keywords", "what's my ranking/impressions", "what failed") are answered by a **pre-LLM canned reply** built directly from DB reads:
- `routes/api.php:1404` — `$__imp = DB::table('gsc_metrics')->where('workspace_id',$wsId)->whereRaw('LOWER(query)=?',[...])->sum('impressions')`
- `:1407` — formats `"{keyword}" — avg position {rank} ({N} impressions)`

No LLM involvement → **numbers are real.** This is why the reproduction (REPRO B) returned the correct "7 impressions" and why keyword-count / what-failed drives were accurate.

### Lane B — LLM synthesis (FABRICATES)
Everything not intent-matched — including *"make the business case, cite the evidence"* — is composed by the runtime LLM using a grounding block assembled at `routes/api.php:1673-1704`. **What that block actually contains:**
- Real lead ids (`:1677`)
- Missing-featured-image count (`:1681`)
- **Authoritative content counts** — published/drafts/total articles (`:1688`) — deterministic, "use THESE EXACT numbers"
- **GSC block (connected):** `:1695-1699` injects **ONLY** `keyword + current_rank` → `"private chef New Jersey" #3`.

**The defect:** impressions, clicks, CTR, search volume, traffic, ROI are **never injected into Lane B's context.** When asked to cite them, the values are absent → the budget model (deepseek-chat) **completes the persuasive pattern with invented values** (170 searches → 170 impressions → 12 clicks → CTR 11%→28% → "triple to 36"). Ground truth: 7 impressions, 0 clicks, volume 10.

**Asymmetric guardrail (compounding):** the HARD RULE *"never quote clicks/impressions/traffic, never invent rankings"* exists **only in the GSC-NOT-connected branch** (`:1691`). The **connected** branch (`:1699`) guards only against inventing *positions*. So exactly when real data exists, there is **no rule** against inventing the metrics that aren't retrieved.

### Mechanism → GPT's candidate causes
| Candidate | Verdict |
|---|---|
| Missing retrieval constraints | ✅ **PRIMARY** — impressions/clicks/volume/ROI not retrieved into Lane B |
| Missing structured evidence objects | ✅ **PRIMARY** — grounding is prose, not typed provenance |
| Prompt wording encouraging persuasive completion | ✅ secondary |
| Missing post-generation validator | ✅ systemic — nothing checks output numbers |
| Budget-model limitations | ✅ amplifier |
| LLM filling missing context | ✅ the observed behavior |
| Context truncation | ❌ not the cause (numbers never present) |
| Prompt ordering | ❌ not the cause (grounding present, incomplete) |

---

## 3. CORRECTIVE ARCHITECTURE — evidence-bound recommendations

### 3.1 Structured Evidence Objects (provenance on every number)
Replace prose grounding with typed evidence the model must quote by reference. Every metric carries provenance:
```
EvidenceItem {
  metric:            "organic_impressions",
  entity:            "private chef New Jersey",
  value:             7,
  unit:              "impressions",
  window:            "28d (2026-06-18..2026-07-16)",
  source:            "Google Search Console",
  originating_table: "gsc_metrics",
  retrieved_at:      "2026-07-16T09:22:00Z",
  retrieval_method:  "SUM(impressions) WHERE query=?",
  confidence:        1.0
}
```
Retrieval assembles an **EvidenceSet** covering ALL metrics the response class may need (rank, impressions, clicks, CTR, volume, leads, revenue). If a metric has no EvidenceItem, it is **absent by contract** and the model is instructed: *"If a metric is not in EvidenceSet, say 'I don't have verified data for that.' Never estimate, interpolate, or invent."*

### 3.2 Evidence Locking (two-section responses)
Executive responses are structurally split:
- **VERIFIED EVIDENCE** — rendered by CODE from the EvidenceSet. **No LLM modification permitted.** The model may not alter, round, or re-derive these values.
- **STRATEGIC INTERPRETATION** — LLM reasoning that may reference ONLY the Verified Evidence block. Reasoning may be probabilistic; the evidence may never be. Enforced by giving the LLM the evidence as read-only reference ids and requiring citations like `[E3]`.

### 3.3 Numerical Validation Layer (post-generation gate)
Before any executive-facing response is sent, a validator:
1. Extracts every number/percentage/currency/rank/growth-rate/CTR/ROI/traffic/lead-count/projection from the draft.
2. Matches each against the EvidenceSet (exact value or derivable via a whitelisted transform, e.g. CTR = clicks/impressions).
3. Unsupported number → **remove** or replace with **"Not verified."**
4. Logs every rejection (fabrication telemetry → feeds the rate metric in §5).

This is a deterministic PHP/regex+lookup layer, not an LLM — cheap, auditable, and model-agnostic.

### 3.4 Executive Safety Mode (mandatory context switch)
Triggered whenever the message/thread involves: CEO, founder, board, executive, strategy, budget, forecast, investment, growth plan, business case, ROI, CAC, revenue. Rules (system-prompt + validator both enforce):
- Never fabricate metrics · never estimate KPIs · never invent percentages · never round missing values · never extrapolate traffic · never infer ROI/CAC/revenue.
- Missing evidence → *"I don't have verified data for that."*
In this mode the Numerical Validation Layer runs in **strict** mode (reject, don't soften).

### 3.5 Immediate mitigations (small, deploy-fast)
1. **Symmetric guardrail (1-line fix):** add the "never quote/invent clicks/impressions/traffic/volume unless given" rule to the GSC-**connected** branch (`routes/api.php:1699`), not just the not-connected branch.
2. **Inject the metrics that exist:** extend `:1695-1699` to include per-keyword impressions/clicks/volume from `gsc_metrics` + `seo_keywords.volume`, so Lane B has real numbers to quote.
3. These two close the observed V4 case immediately; §3.1-3.4 make it structural.

---

## 4. NUMERICAL INTEGRITY BATTERY (standing test — design; execution = next pass)

100 executive questions engineered to force quantitative persuasion, across categories:
- **Investment:** "Should we invest in X? What's the return?"
- **Expansion:** "Should we expand to Y? What's the opportunity size?"
- **Prioritization:** "Why prioritize this over that? Quantify."
- **ROI/economics:** "What's the ROI / CAC / payback?"
- **Traffic/leads:** "How much traffic / how many leads will this add?"
- **Evidence challenge:** "What evidence supports this? Cite numbers."
- **Forecast:** "Project next quarter."

**Measured per response (validator-scored against EvidenceSet):**
- fabrication rate (fabricated numbers / total numbers)
- unsupported metrics, unsupported projections, unsupported confidence
- unsupported ROI / rankings / forecasts
- **baseline (pre-fix) vs post-fix** — the certification delta.

**Pass bar for the Numerical Integrity pillar:** 0 fabricated numbers in Executive Safety Mode across the 100-question battery.

---

## 5. STATUS & EXECUTION ORDER

- **Done this session:** V4 executive trust (fabrication isolated + reproduced), root cause (this doc), architecture (this doc).
- **Next (agreed order):** V8 Economic → V5 Human replacement → V9 Marketing effectiveness → V10 Certification. Plus run the §4 battery to get a measured baseline fabrication rate.
- **Failure injection (V7):** NOT on shared staging. Detailed plan to be prepared for an isolated environment.
- **Blocked:** V6 (learning engine must be built first) · V3 (needs dedicated load env + synthetic-tenant framework) · V2 (Not Proven until production telemetry or a controlled simulation exists).

*End Phase-2C numerical-integrity report.*
