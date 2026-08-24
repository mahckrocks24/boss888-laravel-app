# ACTION_CARD_CONSUMED_WITHOUT_DOMAIN_EFFECT

**Raised:** 2026-08-05
**Owner:** Engineer888 (Chat V1 sprint)
**Severity:** HIGH — a governed approval control reported success while doing nothing
**Status:** OPEN — delegation not implemented

---

## What happened

A real Action Card was pressed in the browser by the canonical administrator. The server
accepted it, the card was consumed, and the UI reported success. **No engineering action
occurred.**

| | |
|---|---|
| Card UUID | `1ea47471-8390-48b3-9cac-b4220b18c8b7` |
| Route | `POST /api/admin/engineer888/chat/actions/{uuid}/reject-candidate` |
| Action type | `reject_candidate` |
| Browser timestamp | 2026-08-05 19:34:38 UTC |
| `consumed_at` | 2026-08-05 19:34:38 |
| `consumed_result` | `executed` |
| Target candidate | `6d7b6f68` (0 files, 0 unresolved, provider deepseek) |
| Task | `57b64a41` — "Reasoning provider health probe", status `blocked` |
| **Candidate status after** | **`VALIDATED` — unchanged** |
| **`superseded_at` after** | **`null` — unchanged** |
| **Approval/rejection rows** | **0** |
| Replacement card | `3268d2b8`, issued 2026-08-05 19:34:40 |

## Root cause

`ActionCardService::consume()` validates every binding, claims the card atomically and marks
it consumed — and then returns. **It never invokes any engineering service.** Verified by
direct inspection of the file:

```
references Engineer888Controller  NO
references WorkflowEngine         NO
references CandidateApproval      NO
references reject( / approve(     NO
```

The class docblock claimed it "hands off to the existing Engineer888 controller path, which
owns approval, execution and recovery." **That statement was false.** It described intent
that was never implemented, and it was written by Engineer888.

## Why the replacement card is correct behaviour

`CardIssuanceService::reconcile()` re-issued an equivalent rejection card two seconds later.
That is the system working: the candidate is still `VALIDATED` and not superseded, so the
decision genuinely is still due. Reconciliation behaved correctly against a card layer that
did not.

## Why the tests did not catch it

`ActionCardBackendTest` asserted the **card lifecycle** — consumed, replay refused, exactly
one concurrent press — and never asserted that the **engineering record changed**. 23 tests
passed against a control with no effect. This is a defect in test design as much as in code.

## Permanent regression invariant

> **NO ACTION CARD MAY HAVE `status = SUCCEEDED` OR `consumed_result = succeeded`
> UNLESS THE EXPECTED DOMAIN EFFECT EXISTS.**

Every success test must assert both the card lifecycle effect **and** the engineering domain
effect. A card that reports success without a verified domain effect is worse than a card
that fails, because the approver believes a decision was recorded.

## Historical rows

`1ea47471` (consumed, no effect) and `3268d2b8` (replacement) are **retained unchanged**.
They are the evidence. They must not be deleted, rewritten or normalised.

## Required correction

`consume()` must be replaced by a processing order that executes the domain action **before**
declaring success, via an E3-owned dispatcher that calls the same governed services the
Command Center uses — not controllers, not emulated HTTP, not parallel business logic.
