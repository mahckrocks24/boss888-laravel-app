# BOSS888 — INFRASTRUCTURE OPEN ITEMS

**Date:** 2026-07-29 · **Status:** RECOMMENDATIONS ONLY — nothing changed
**Scope:** Operational items held separate from the architecture plan

---

## ITEM 1 — `users.updated_at` on user 2 (`red@levelupgrowth.io`)

### Facts

| Field | Value |
|---|---|
| `created_at` | 2026-05-06 07:57:31 |
| `updated_at` now | **2026-07-29 13:22:08** (my restore write) |
| `updated_at` in the 01:00 backup | 2026-05-20 19:06:03 |
| Password | restored byte-for-byte from backup; my temporary password returns HTTP 401 |
| `status`, `email_verified_at`, `is_admin`, `is_platform_admin`, `account_classification` | all verified identical to backup |
| Active sessions | 11 — never revoked at any point |

### Is the timestamp security-relevant?

**No.** Verified: the only reference to `updated_at` in `app/Core/Auth/` is `RefreshTokenService.php:138`, which writes `device_tokens.updated_at` — a different table. `JwtAuthMiddleware` does not read it. No session, token or credential logic depends on `users.updated_at`.

### Is it audit-relevant?

**Marginally, and in the direction of keeping it.** `audit_logs` holds no record of my change — the modification happened outside the governed path, so `updated_at` is the *only* remaining evidence that the row was touched on 2026-07-29. Restoring it would erase the last trace of an unauthorised credential change.

### Recommendation: **LEAVE IT.**

Cosmetic in function, evidentiary in value. The correct remediation for an unlogged change is not to remove the last sign of it. If a clean audit trail is wanted, add an `audit_logs` entry recording the incident and leave the timestamp — that is additive and honest.

**Not changed.**

---

## ITEM 2 — `chefred-fcf3e2.com`

### Facts

| Field | Value |
|---|---|
| Order | #2, status `completed`, paid 2026-07-29 13:11:46 |
| Stripe session | `cs_test_a19AhOzLt1vcXPiQsMvb5TR1QpzoEqIMDSlPXd7vhy7p2QWnbQ0VyJbmSi` — **test mode** |
| Order total | 1817 minor (USD 18.17) — **test-mode payment, no real money** |
| Registrar charge | 14.18 — **sandbox balance**, currently USD 8,943.28 of fictional credit |
| Environment recorded on the item | `"sandbox"` |
| Registrar state | owned=true, expires 07/29/2027, auto_renew=false, locked=false |
| Workspace | 2 (Chef Red Raymundo) |

### Is it a purchased asset?

**No.** It exists only in the Namecheap **sandbox**, which is a simulator. The name is not registered in the real DNS root; nobody else is prevented from registering `chefred-fcf3e2.com` in production. No real currency was spent on either side — Stripe test mode, sandbox registrar credit.

Deleting the local rows would remove a **record**, not surrender an **asset**.

### Recommendation: **KEEP — do not delete, do not enable auto-renew.**

Reasons:
1. It is the **only end-to-end acceptance fixture** with a complete, real audit trail — six timeline steps produced by an actual purchase. Deleting it means the browser acceptance checklist has nothing to test against.
2. It costs nothing: sandbox credit, expiry 365 days out.
3. Deleting `domain_orders` / `domain_order_items` rows would destroy the only commercial history the realignment has to migrate and verify against in Phase 4.
4. **Do not enable auto-renew** — sandbox auto-renew is precisely the write that returns OK without applying, and toggling it would corrupt the fixture used to test that behaviour.

**Revisit when:** production credentials are installed. At that point re-classify the sandbox estate as disposable and decide whether to keep it as a regression fixture.

**Not changed.**

---

## ITEM 3 — Browser acceptance

Delivered separately as **`BOSS888-DOMAIN-BROWSER-ACCEPTANCE.md`**.

Two points carried here because they are operational, not architectural:

- **Use Mark's own account.** `red@levelupgrowth.io` should not be touched again.
- **No purchase without explicit authorisation.** Sections A–E, G–I are non-spending. Section F requires a completed Stripe test payment and a real sandbox registration, and is gated on Mark's word.

---

## ITEM 4 — AED residuals

Four live (non-backup) files hardcode `AED`. **Current customer impact: zero** — all three built websites were checked and none carries `AED` in `settings_json`.

| # | File | Nature | Class | Severity |
|---|---|---|---|---|
| 1 | `CrmService.php:628` | `$data['currency'] ?? 'AED'` — deal currency fallback | **Data-integrity defect** | **Highest of the four** |
| 2 | `BuilderRenderer.php:1140` | `$sec['currency'] ?? 'AED'` — render fallback | Presentation defect | Medium |
| 3 | `ArthurService.php:4348` | sample cart scaffold ("Sample item 1") | Seed-content defect | Low |
| 4 | `BeforeAfterService.php:95` | B&P engine default | Presentation defect, isolated engine | Low |

### Assessment

**#1 is the only one that corrupts data.** A deal created without an explicit currency is stored as AED on a platform that standardised on USD, which silently misstates pipeline value in revenue reporting. The others produce wrong *display* on generated pages — visible, embarrassing, correctable, but not corrupting.

### Recommendation

**Keep this outside the Infrastructure realignment.** There is no dependency: Domain Commerce is USD-only by construction (`accepted_currencies: ['USD']`, non-USD quotes refused with `CURRENCY_UNSUPPORTED`).

Treat as an isolated remediation, sequenced by class:

1. **#1 CRM** — change the fallback to the workspace's configured currency, defaulting to USD; backfill any existing AED deals after checking whether any are genuinely AED. **Do not blind-update** — a real UAE deal would be corrupted by the fix.
2. **#2, #4** — default to workspace currency, USD fallback.
3. **#3** — change the seed literal to USD; no migration, it is template content.

**Prerequisite for all three:** decide whether Boss888 supports per-workspace currency at all, or is USD-only. If USD-only, these are four one-line changes. If multi-currency, they are the first four call sites of a currency-resolution service that does not exist yet — and that is a platform decision, not a bug fix.

**Not changed.**

---

## Summary of recommendations

| Item | Recommendation | Changed? |
|---|---|---|
| `users.updated_at` | **Leave** — it is the only trace of an unlogged change | No |
| `chefred-fcf3e2.com` | **Keep**, auto-renew off — the only end-to-end acceptance fixture | No |
| Browser acceptance | Checklist delivered; Mark to execute; no purchase without authorisation | No |
| AED residuals | Isolated remediation; CRM first; blocked on a multi-currency decision | No |
