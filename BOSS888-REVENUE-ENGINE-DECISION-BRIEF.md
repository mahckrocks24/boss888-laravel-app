# BOSS888 — REVENUE ENGINE DECISION BRIEF

**Date:** 2026-07-29 · **Status:** DECISION BRIEF — no schema change proposed for execution
**Constraint:** `domain_order_items` must not be migrated until this is approved

---

## Current state (verified)

| Spine | Tables | Rows | Stripe | Status |
|---|---|---|---|---|
| Plan subscriptions | `plans`, `subscriptions` | 10 / 24 | wired (`mode: subscription`) | **LIVE** |
| Domain orders | `domain_orders`, `domain_order_items` | 2 / 2 | wired (`mode: payment`) | **LIVE** |
| Infra billing | `infra_plans`, `infra_subscriptions`, `infra_plan_entitlements`, `infra_entitlement_definitions`, `infra_renewals`, `infra_usage_records`, `infra_cost_entries`, `infra_subscription_migrations` | **all 0** | **not wired** | **DORMANT** |

Two entitlement resolvers exist: `FeatureGateService` (over `plans.features_json`, live) and `EntitlementResolver` (over `infra_plan_entitlements`, dormant).

`domain_order_items` columns are domain-shaped: `domain`, `tld`, `years`, `registrar_cost_minor`, `is_premium`, `provider_order_id`, `provider_transaction_id`.

The dormant infra spine is **not junk**. It contains design the live spine lacks: `price_locked_until`, `renewal_amount_minor`, `term_start_at`/`term_end_at`, `setup_fee_minor`, `plan_version_at_purchase`, plus usage and cost-entry tables. Someone thought carefully about recurring infrastructure billing and never wired it.

---

## Options

### Option 1 — One Unified Revenue Engine

Single `orders` + `order_items` (generic, `product_type` discriminator, `metadata_json`), single `subscriptions`, single entitlement resolver, single ledger.

### Option 2 — Separate Commerce and Subscription engines sharing a ledger

`orders` for one-time; `subscriptions` for recurring; both post to a shared `revenue_ledger`. Entitlements resolve from subscriptions only.

### Option 3 — Keep domain orders; converge gradually

No structural change now. Add `product_type` to `domain_order_items` when the second product type arrives; decide then.

---

## Evaluation

| Requirement | Opt 1 Unified | Opt 2 Split + ledger | Opt 3 Gradual |
|---|---|---|---|
| One-time sales | ✔ native | ✔ native | ✔ today |
| Subscriptions | ✔ | ✔ native | ✖ separate system |
| Metered consumption | ✔ as line type | ✔ via subscription usage | ✖ none |
| **One customer invoice** | ✔ **natural** | ⚠ requires ledger join | ✖ impossible across spines |
| Refunds | ✔ one path | ⚠ two paths | ⚠ domain only |
| Renewals | ✔ order generated from subscription | ✔ native | ✖ absent |
| Taxes | ✔ one calculation point | ⚠ two | ⚠ one, domain only |
| Credits / discounts / bundles | ✔ line-level | ⚠ per engine | ✖ |
| **Cost of goods / margin** | ✔ already modelled per line | ✔ ledger | ✔ domain only |
| Provider provisioning trigger | ✔ event per line | ✔ | ✔ |
| Plan entitlements | ✔ one resolver | ✔ | ✖ two resolvers persist |
| Multi-currency future | ✔ one place | ⚠ two | ⚠ |
| Accounting / reporting | ✔ single source | ✔ ledger is the source | ✖ union queries forever |
| Migration cost **today** | Medium (2 orders) | High | **Zero** |
| Migration cost in 12 months | High | High | **Very high** |

---

## Ownership boundary (applies to every option)

This boundary is the durable part of the decision and should be ratified regardless of which option wins.

| Concern | Owner |
|---|---|
| What was purchased, quantity, term | **Commerce** |
| Price, discount, tax, currency | **Commerce** |
| Payment, invoice, refund | **Commerce** |
| Revenue, cost of goods, margin | **Commerce** |
| Entitlement granted by a purchase | **Commerce** → published as an event |
| Whether fulfilment may proceed | **Capability + Approval** |
| How fulfilment happens | **Engine** |
| Provider result, verification, retry | **Engine + Provider** |
| Operational state of the delivered thing | **Engine** (`customer_domains`, `infra_hosting_accounts`) |

**Commerce must never call a provider. An engine must never set a price.**

Today's implementation already honours this: `DomainCommerceService` freezes price and marks paid; `RegisterDomainJob` provisions. The boundary is correct — only the *plumbing between them* (direct dispatch instead of Task + event) is wrong.

---

## Recommendation

**Option 1 — One Unified Revenue Engine — but not yet, and not as a migration.**

Sequenced:

1. **Ratify the ownership boundary now.** Zero code. It is the constraint that keeps every future module honest.
2. **Freeze `domain_order_items` as-is.** Two rows. Migrating now buys nothing; the schema is not yet in anyone's way.
3. **Build the unified engine when the second product type is actually funded** — hosting or email. At that point design `orders`/`order_items` properly, and migrate the 2 domain orders as the first test case.
4. **Harvest the dormant infra spine into that design** — `price_locked_until`, term boundaries, renewal amounts, `plan_version_at_purchase`, usage and cost entries. Then retire the eight dormant tables in one deliberate act.
5. **Retire the second entitlement resolver** in the same milestone. Two resolvers is worse than either one.

**Why not Option 3 permanently:** it guarantees a second spine when hosting is sold, and by then the argument for a third is precedent.

**Why not Option 2:** a shared ledger with split engines still cannot produce one invoice without a join, and "one customer invoice" is a hard requirement for a business operating system. Splitting is the shape you adopt when one-time and recurring are run by different teams. Boss888 has one team.

**What would change my recommendation:** if hosting is not on the 12-month roadmap, Option 3 is defensible indefinitely — a unified engine with one product type is speculative generality.

---

## Decision required from Mark

1. **Ratify the ownership boundary** (recommended: yes, now, costs nothing).
2. **Confirm `domain_order_items` is frozen** until the second product type is funded.
3. **Confirm the trigger** for building the unified engine: *the funding decision for hosting or email*, not a date.
4. **Fate of the eight dormant infra billing tables:** harvest-then-retire (recommended) versus retire now. They cost nothing but ambiguity — and ambiguity is what makes the next engineer build a fourth spine.
