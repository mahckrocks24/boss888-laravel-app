<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Core\Platform\Admin\AdminRegistry;
use App\Engines\Infrastructure\Email\Admin\BusinessEmailAdminGate as Gate;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * INFRA888 · E3.1 — ADMIN SCREENS AND NAVIGATION.
 *
 * ─── THE ORDER THESE WERE BUILT IN MATTERS ──────────────────────────────────
 *
 * `AdminPageController` aborts 500 when a registered slug has no view. E3
 * therefore declined to register navigation it could not back, and E3.1 built
 * the eight views FIRST. This file asserts the invariant permanently: every
 * registered Business Email slug resolves to a view that compiles.
 *
 * ─── AND WHY THE NAVIGATION IS INVISIBLE TODAY ──────────────────────────────
 *
 * Every entry is capability-gated. While the gate is closed — which it is on
 * every installation — `AdminRegistry` removes the whole group before the
 * sidebar is built, before `window.ADMIN_PAGES` is serialised, and before the
 * browser receives anything. Nothing is hidden by JavaScript.
 */
class BusinessEmailAdminScreensTest extends TestCase
{
    /** The eight screens, keyed as registered. */
    private const SCREENS = [
        'businessEmailOverview'       => 'business-email/overview',
        'businessEmailDomains'        => 'business-email/domains',
        'businessEmailMailboxes'      => 'business-email/mailboxes',
        'businessEmailRouting'        => 'business-email/routing',
        'businessEmailOperations'     => 'business-email/operations',
        'businessEmailReconciliation' => 'business-email/reconciliation',
        'businessEmailProviders'      => 'business-email/providers',
        'businessEmailHealth'         => 'business-email/health',
    ];

    // ── registration ─────────────────────────────────────────────────────────

    public function test_all_eight_screens_are_registered_in_the_canonical_registry(): void
    {
        $registry = AdminRegistry::all();

        foreach (self::SCREENS as $key => $slug) {
            $this->assertArrayHasKey($key, $registry, "{$key} is not registered.");
            $this->assertSame($slug, $registry[$key]['slug']);
            $this->assertSame(
                'business_email.read',
                $registry[$key]['capability'] ?? null,
                "{$key} is not capability-gated and would be visible with the area switched off."
            );
        }
    }

    public function test_every_registered_slug_resolves_to_a_view_that_exists(): void
    {
        // The exact invariant AdminPageController enforces at runtime with a
        // 500. Asserting it here means a registration can never ship ahead of
        // its renderer.
        foreach (self::SCREENS as $key => $slug) {
            $viewPath = 'admin.pages.' . str_replace('/', '.', $slug);

            $this->assertTrue(
                view()->exists($viewPath),
                "{$key} is registered but {$viewPath} does not exist — AdminPageController would return 500."
            );
        }
    }

    public function test_every_screen_compiles(): void
    {
        // A view that exists but does not compile fails at render time, in
        // front of an operator, with a blank page.
        foreach (self::SCREENS as $key => $slug) {
            $file = '/var/www/levelup-staging/resources/views/admin/pages/' . $slug . '.blade.php';

            $this->assertFileExists($file);

            $compiled = Blade::compileString((string) file_get_contents($file));

            $this->assertNotSame('', trim($compiled), "{$key} compiled to nothing.");
        }
    }

    public function test_every_screen_includes_the_shared_helpers_and_defines_a_renderer(): void
    {
        foreach (self::SCREENS as $key => $slug) {
            $source = (string) file_get_contents(
                '/var/www/levelup-staging/resources/views/admin/pages/' . $slug . '.blade.php'
            );

            $this->assertStringContainsString('admin.pages.business-email._helpers', $source,
                "{$key} does not include the shared helpers.");

            // The shell reports "no renderer" rather than rendering nothing;
            // this makes that impossible for a Business Email page.
            $this->assertStringContainsString('window.page', $source, "{$key} defines no renderer.");
        }
    }

    // ── the gate controls visibility, not JavaScript ─────────────────────────

    public function test_the_whole_group_is_absent_from_the_registry_while_the_gate_is_closed(): void
    {
        config(['business_email.admin_enabled' => false]);
        $this->assertFalse(Gate::isEnabled());

        $visible = AdminRegistry::make()->visibleFor($this->platformAdminRequest());

        foreach (array_keys(self::SCREENS) as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $visible,
                "{$key} is visible to a platform admin with the area switched off."
            );
        }

        // And the slug map the browser receives carries none of them.
        $slugMap = AdminRegistry::make()->slugMapFor($this->platformAdminRequest());

        foreach (self::SCREENS as $key => $slug) {
            $this->assertArrayNotHasKey($key, $slugMap);
            $this->assertNotContains($slug, $slugMap);
        }
    }

    public function test_the_group_appears_once_the_gate_is_opened(): void
    {
        config(['business_email.admin_enabled' => true]);

        $visible = AdminRegistry::make()->visibleFor($this->platformAdminRequest());

        foreach (array_keys(self::SCREENS) as $key) {
            $this->assertArrayHasKey($key, $visible, "{$key} is missing with the area switched on.");
        }
    }

    public function test_a_non_admin_never_sees_the_group_even_with_the_gate_open(): void
    {
        config(['business_email.admin_enabled' => true]);

        $visible = AdminRegistry::make()->visibleFor($this->requestFor($this->makeUser(false)));

        foreach (array_keys(self::SCREENS) as $key) {
            $this->assertArrayNotHasKey($key, $visible);
        }
    }

    public function test_slug_lookup_treats_hidden_and_nonexistent_identically(): void
    {
        config(['business_email.admin_enabled' => false]);

        $registry = AdminRegistry::make();
        $request = $this->platformAdminRequest();

        // "Not visible" and "does not exist" must return the same thing, so the
        // person typing the URL cannot distinguish a hidden module from a typo.
        $this->assertNull($registry->keyForSlug($request, 'business-email/overview'));
        $this->assertNull($registry->keyForSlug($request, 'business-email/does-not-exist'));
    }

    // ── the screens must not leak ────────────────────────────────────────────

    public function test_no_screen_hardcodes_a_provider_identity(): void
    {
        foreach (self::SCREENS as $key => $slug) {
            $source = (string) file_get_contents(
                '/var/www/levelup-staging/resources/views/admin/pages/' . $slug . '.blade.php'
            );

            foreach (['m' . 'igadu', 'z' . 'oho', 'f' . 'astmail', 'g' . 'oogle workspace'] as $fragment) {
                $this->assertStringNotContainsStringIgnoringCase($fragment, $source,
                    "{$key} names a vendor.");
            }
        }
    }

    public function test_no_screen_fabricates_a_confirmed_result(): void
    {
        $helpers = (string) file_get_contents(
            '/var/www/levelup-staging/resources/views/admin/pages/business-email/_helpers.blade.php'
        );

        // The renderer may only call something completed when the SERVER said
        // verified. A UI that decides for itself is exactly the fabricated
        // success E1 and E2 spent two milestones making impossible.
        $this->assertStringContainsString('b.verified === true', $helpers);
        $this->assertStringContainsString('Not yet confirmed', $helpers);
    }

    public function test_no_screen_persists_anything_to_browser_storage(): void
    {
        foreach (self::SCREENS as $key => $slug) {
            $source = (string) file_get_contents(
                '/var/www/levelup-staging/resources/views/admin/pages/' . $slug . '.blade.php'
            );

            foreach (['localStorage.setItem', 'sessionStorage.setItem', 'document.cookie'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source,
                    "{$key} writes to browser storage. Nothing from this console may be persisted client-side.");
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeUser(bool $platformAdmin): User
    {
        return User::create([
            'name'              => 'screens-' . ($platformAdmin ? 'admin' : 'user'),
            'email'             => 'screens-' . Str::random(10) . '@test.local',
            'password'          => Hash::make(Str::random(32)),
            'is_admin'          => $platformAdmin ? 1 : 0,
            'is_platform_admin' => $platformAdmin ? 1 : 0,
        ]);
    }

    private function platformAdminRequest(): Request
    {
        return $this->requestFor($this->makeUser(true));
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create('/admin/business-email/overview');
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
