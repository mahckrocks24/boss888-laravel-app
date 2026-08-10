<?php

namespace App\Core\Platform\Admin;

use App\Core\Engineer888\Access\Engineer888Access;
use Illuminate\Http\Request;

/**
 * One question the whole admin platform asks: may this person see this module?
 *
 * It owns no policy of its own. A capability string names the module that owns
 * the decision, and this class routes to it — `engineer888.*` to
 * Engineer888Access, and nothing else today. Duplicating a module's rules here
 * would create the second source of truth that the Engineer888 sprints spent
 * two rounds eliminating.
 *
 * THE DEFAULT IS DELIBERATE. A registry entry with no capability is visible to
 * any platform admin, which is exactly how all 54 pre-existing pages behave
 * today. This layer is additive: it can hide a module that asks to be hidden,
 * and it changes nothing for a module that does not.
 */
final class AdminAccess
{
    /**
     * Prefix => resolver. A capability whose prefix is unknown is refused
     * rather than allowed: a module that names a policy nobody implements has
     * a bug, and failing open would hide it behind a working screen.
     *
     * @var array<string,callable|null>
     */
    private array $resolvers = [];

    public function __construct()
    {
        $this->resolvers['engineer888'] = fn (Request $request, string $capability)
            => (new Engineer888Access())->allows($request, $capability);
        // INFRA888 registers its own `business_email` resolver here in the
        // shared tree. It is deliberately NOT carried into this commit: the
        // class it names, BusinessEmailAdminAccess, belongs to INFRA888 and is
        // not on this branch, so importing the line would fatal every admin
        // page with a class-not-found. An unrouted prefix already fails closed
        // below, which is the correct interim behaviour — and INFRA888 re-adds
        // its own line when it commits its own work.
    }

    /**
     * May this request use this capability?
     *
     * A null capability means the module never asked to be restricted.
     */
    public function allows(?Request $request, ?string $capability): bool
    {
        if ($capability === null || $capability === '') {
            return $this->isPlatformAdmin($request);
        }

        if (! $this->isPlatformAdmin($request)) {
            return false;
        }

        $prefix = explode('.', $capability)[0] ?? '';
        $resolver = $this->resolvers[$prefix] ?? null;

        if ($resolver === null) {
            // Fail closed. An unroutable capability is a defect, and a defect
            // that grants access is the expensive kind.
            return false;
        }

        return (bool) $resolver($request, $capability);
    }

    /** @return array<int,string> the capability prefixes this platform knows */
    public function knownPrefixes(): array
    {
        return array_keys($this->resolvers);
    }

    /**
     * The floor every admin surface stands on.
     *
     * Server-side and read from the resolved user, never from anything the
     * client sends.
     */
    private function isPlatformAdmin(?Request $request): bool
    {
        $user = $request?->user();

        return $user !== null && (bool) ($user->is_platform_admin ?? false);
    }
}
