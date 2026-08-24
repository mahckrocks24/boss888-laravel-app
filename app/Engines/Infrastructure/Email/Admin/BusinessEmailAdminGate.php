<?php

namespace App\Engines\Infrastructure\Email\Admin;

/**
 * INFRA888 · E3 — the switch that keeps a half-operational admin surface off.
 *
 * ─── WHY THIS IS NOT AN ENVIRONMENT CHECK ───────────────────────────────────
 *
 * The obvious implementation is `! app()->environment('production')`. On this
 * install that is not merely imperfect, it is backwards: APP_ENV is `staging`,
 * and the same Laravel serves levelupgrowth.io and live customer domains. An
 * environment test would leave the entire Business Email admin area switched
 * ON for real operators looking at real customer workspaces — backed by a fake
 * provider that creates nothing.
 *
 * So the gate is an explicit opt-in, off on every environment until someone
 * sets it, and a test asserts the default. E3 ships a complete admin surface
 * that is inert until E5 gives it something real to talk to.
 *
 * ─── WHAT "OFF" MEANS, PRECISELY ────────────────────────────────────────────
 *
 * Not hidden — absent. The navigation entries are filtered out by
 * AdminRegistry before the sidebar is built, so the browser never receives
 * their slugs. Every admin API route resolves to a typed refusal. Every
 * capability check returns false. There is nothing to find and nothing to call.
 *
 * ─── AND A SECOND, INDEPENDENT CONDITION ────────────────────────────────────
 *
 * `canOperate()` additionally requires a resolvable provider. An admin surface
 * that can be reached but whose every action returns "not configured" is the
 * half-operational surface this milestone was told not to ship, so reads stay
 * available for diagnosis while actions refuse early and say why.
 */
final class BusinessEmailAdminGate
{
    /** Is the Business Email admin area present at all? */
    public static function isEnabled(): bool
    {
        return (bool) config('business_email.admin_enabled', false);
    }

    /**
     * May controlled fake-provider scenarios be selected?
     *
     * Requires BOTH its own flag and the admin gate, so it cannot be reached on
     * an install where Business Email admin is off — which is every install
     * today. A test asserts the conjunction rather than either half.
     */
    public static function allowsScenarioControl(): bool
    {
        return self::isEnabled() && (bool) config('business_email.allow_scenario_control', false);
    }

    /**
     * The reason the area is unavailable, or null when it is available.
     *
     * Returned to the caller verbatim. It names a configuration state, never a
     * provider and never a vendor.
     */
    public static function unavailableReason(): ?string
    {
        return self::isEnabled()
            ? null
            : 'The Business Email admin area is not enabled on this installation.';
    }
}
