<?php

namespace App\Engines\Infrastructure\Policies;

use App\Engines\Infrastructure\Models\InfraHostingAccount;
use App\Engines\Infrastructure\Models\InfraSubscription;
use Illuminate\Support\Facades\Gate;

/**
 * Explicit policy registration.
 *
 * Laravel's convention-based policy discovery looks for App\Policies\{Model}Policy.
 * INFRA888 models live under App\Engines\Infrastructure\Models, so discovery will
 * NOT find them — registration must be explicit or every policy silently fails
 * open, which is the worst possible failure mode here.
 *
 * Called from AppServiceProvider::boot(). That is a two-line edit to one shared
 * file, deliberately kept minimal: the working tree already carries 117 uncommitted
 * modifications, so shared-file surface area is kept as small as possible.
 */
final class InfrastructurePolicyRegistrar
{
    /** @var array<class-string,class-string> */
    private const POLICIES = [
        InfraHostingAccount::class => InfraHostingAccountPolicy::class,
        InfraSubscription::class   => InfraSubscriptionPolicy::class,
    ];

    public static function register(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }

    /** Used by the registration-drift test. */
    public static function policies(): array
    {
        return self::POLICIES;
    }
}
