<?php

namespace App\Engines\Infrastructure\Email\Customer;

use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;

/**
 * INFRA888 · E4 — the switch that keeps the customer portal off.
 *
 * ─── SEPARATE FROM THE ADMIN GATE, DELIBERATELY ─────────────────────────────
 *
 * An operator console backed by a fake provider is defensible: the operator
 * knows what they are looking at, and E3's banner says so on every screen. A
 * CUSTOMER portal backed by a fake provider is not defensible under any
 * framing — it would show a paying customer mailboxes that do not exist.
 *
 * So the customer gate is its own flag, off by default, and it can be closed
 * while the admin gate is open. The reverse is deliberately impossible: the
 * customer portal additionally requires the admin gate, because shipping a
 * customer surface for a feature no operator can see or repair is how a
 * support request becomes unanswerable.
 *
 * ─── AND IT IS NOT AN ENVIRONMENT CHECK, FOR THE SAME REASON AS E3 ──────────
 *
 * APP_ENV here is `staging`, and this Laravel serves levelupgrowth.io and live
 * customer domains. An `environment('production')` test would leave the portal
 * switched ON for real customers.
 *
 * ─── WHAT "OFF" MEANS FOR THE CUSTOMER, PRECISELY ───────────────────────────
 *
 * Exactly what customers see today: the HOSTING → Email Accounts navigation
 * item stays visible and its page keeps its existing "coming soon" empty state.
 * E4 changes NOTHING a customer can observe until the flag is set. That was the
 * only reading that did not require guessing at a product decision that has
 * been open since E1 (masterplan §14 decision 2), and guessing it would have
 * changed live behaviour for every workspace.
 */
final class BusinessEmailCustomerGate
{
    /** Is the customer portal live on this installation? */
    public static function isEnabled(): bool
    {
        // Requires BOTH. A customer surface for a feature no operator can see
        // or repair produces support requests nobody can answer.
        return (bool) config('business_email.customer_enabled', false)
            && (bool) config('business_email.admin_enabled', false);
    }

    /** Reason the portal is unavailable, or null when it is available. */
    public static function unavailableReason(): ?string
    {
        return self::isEnabled() ? null : 'Business Email is not available on this account yet.';
    }

    /**
     * Capabilities a CUSTOMER may invoke.
     *
     * Derived from the E1 registry's own `customer_available` fact rather than
     * re-decided here — the registry is the single declaration of what each
     * capability is, and a second list would drift from it. `usage.sync` and
     * `health.observe` are declared admin-only there and stay admin-only here;
     * the customer sees their RESULTS on the Storage and Health screens without
     * being able to trigger a provider read on demand.
     *
     * @return array<int,string>
     */
    public static function customerCapabilities(): array
    {
        return Registry::customerFacing();
    }

    public static function allowsCapability(string $capability): bool
    {
        return in_array($capability, self::customerCapabilities(), true);
    }
}
