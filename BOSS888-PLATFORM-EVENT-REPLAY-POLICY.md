# BOSS888 — PLATFORM EVENT REPLAY POLICY

**Date:** 2026-07-29/30 · **Status:** POLICY + implemented mechanism, **not exposed**
**Implementation:** `App\Core\PlatformEvents\EventReplay`
**Exposure:** no route, no console command, no UI — code-only by design

---

## 1. Why replay exists

Normal delivery is **prospective**. Fan-out only creates delivery rows for events recorded at or after a subscriber's `activatedAt` timestamp.

That rule prevents the failure it was written for: wiring up a notification subscriber and instantly emailing customers about orders from three months ago, or rebuilding organisational memory from history nobody asked to revisit.

Replay is the **only** sanctioned way past that boundary. It exists so that reaching historical events is a deliberate, audited, dry-runnable operation — not something achieved by hand-editing delivery rows in a database console.

---

## 2. Hard guarantees

Replay **never**:

| Guarantee | Mechanism |
|---|---|
| modifies an event payload | replay only inserts delivery rows; events are immutable |
| deletes or overwrites a prior delivery result | insert-only; a unique-constraint collision is swallowed |
| creates a duplicate delivery identity | `unique(event_id, subscriber_key, subscriber_version)` |
| targets all history implicitly | both window bounds are mandatory and format-validated |
| runs without an event-type filter | an empty filter is rejected |
| accepts a type the subscriber does not declare | validated against the declaration |
| runs a customer-facing subscriber | refused outright when `emitsCustomerFacingSideEffects` is true |
| executes without confirmation | requires the exact token derived from the plan |
| executes anonymously | requires an authorising user id |
| goes unrecorded | writes `platform_events.replay` to `audit_logs` |

---

## 3. Required inputs

| Input | Required | Notes |
|---|---|---|
| `subscriberKey` | ✔ | must be declared in `SubscriberRegistry` |
| `subscriberVersion` | implicit | taken from the declaration — a replay always targets the current behaviour |
| `eventTypes` | ✔ | non-empty; each must be accepted by the subscriber |
| `fromRecordedAt` / `toRecordedAt` | ✔ | explicit timestamps, ordered |
| `workspaceId` | optional | omit for platform-wide, supply to scope to one tenant |
| `authorisedByUserId` | ✔ for execute | recorded in the audit trail |
| `confirmationToken` | ✔ for execute | from `confirmationTokenFor()` |

---

## 4. Two-step protocol

### Step 1 — `plan()` (always safe, writes nothing)

```php
$plan = (new EventReplay())->plan(
    'platform.audit', ['domain.order.created'],
    '2026-07-01 00:00:00', '2026-07-29 20:00:00'
);
```

Returns: `eligible`, `already_delivered`, **`would_create`**, the window, the types, and `dry_run: true`.

`would_create` is the number that matters. If it is larger than expected, the window or filter is wrong.

### Step 2 — `execute()` (requires the matching token)

```php
$token = $replay->confirmationTokenFor('platform.audit', ['domain.order.created'], $from, $to, null);

$replay->execute(
    'platform.audit', ['domain.order.created'], $from, $to,
    authorisedByUserId: <real user id>,
    confirmationToken: $token
);
```

The token is a hash of the exact scope. A caller cannot execute a replay they have not first planned, and cannot widen the scope after planning without the token changing.

Execution inserts `pending` delivery rows. It does **not** run subscribers — the delivery worker does that on its normal schedule, under its normal retry, tenancy and idempotency rules.

---

## 5. Customer-facing subscribers are refused

```php
if ($decl->emitsCustomerFacingSideEffects) {
    throw new RuntimeException("Refusing to replay '{$decl->key}': …");
}
```

`platform.audit` declares `false`, so it is replayable. A future notification subscriber will declare `true` and be **refused by this mechanism entirely** — replaying it would contact customers about historical events. Enabling that requires separate, explicit authorisation and a different code path, not a flag.

This guard is implemented and tested, but has **never been exercised against a real customer-facing subscriber**, because none exists yet.

---

## 6. Audit record

Every execution writes:

```
action:      platform_events.replay
entity_type: platform_event_subscriber
metadata:    subscriber_key, subscriber_version, event_types,
             window {from, to}, workspace_id,
             planned_would_create, actually_created
user_id:     the authorising user
```

`planned_would_create` versus `actually_created` is the reconciliation record: a gap means rows already existed, which is expected and benign.

---

## 7. Operational policy

**When a replay is appropriate**

- a subscriber's mapping was wrong and a version bump warrants re-delivery
- a subscriber was activated later than intended and a known window was missed
- a permanent failure has been fixed at the root and the affected window should be re-attempted

**When it is not**

- to "catch up" a newly added subscriber on all history — that is what prospective activation deliberately prevents
- to work around a subscriber bug without fixing it
- for any subscriber that contacts customers

**Approval.** A replay that changes what a customer or an auditor sees requires Mark's approval before execution. A replay of `platform.audit` within a narrow window, for a known cause, is an operator-level action — but still audited.

---

## 8. Current production position

**No production replay is required or possible in any meaningful sense: production holds 0 platform events and 0 deliveries.**

Replay behaviour was tested in the test database only:

| Test | Asserts |
|---|---|
| `test_replay_requires_an_explicit_window` | no implicit "all history" |
| `test_replay_requires_an_explicit_event_type_filter` | no unfiltered replay |
| `test_replay_rejects_an_event_type_the_subscriber_does_not_accept` | declaration is authoritative |
| `test_replay_plan_is_a_dry_run_that_writes_nothing` | `plan()` is side-effect free |
| `test_replay_execution_requires_the_matching_confirmation_token` | no execution without a plan |
| `test_replay_requires_an_authorising_user` | no anonymous replay |
| `test_replay_is_not_exposed_by_any_route_or_command` | code-only exposure |

---

## 9. Open decision

**How replay should eventually be exposed.** Options:

1. **Artisan command with interactive confirmation** — recommended: replay is an operator action, not a customer one, and a console already implies deliberateness.
2. Admin-only endpoint — adds an attack surface for a rarely-used operation.
3. Leave code-only — safest, but requires a developer for what may become a routine operational task.

Until that is decided, replay requires a developer with console access. That is an acceptable constraint while production holds zero events.
