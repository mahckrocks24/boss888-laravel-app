# Provider readiness report

- task: `1cc89200-089f-4473-9bc4-740112aab3c1`
- project: LevelUp Growth / LevelUp Growth Platform
- kind: feature · priority: normal
- requested by: Mark (CEO directive Sprint 7)
- approved by: Mark (CEO)

## Description

Add a read-only class at app/Core/Engineer888/Reasoning/ProviderReadiness.php reporting whether each registered reasoning provider can run right now. Obtain the registry with ProviderRegistry::fromConfig(); do not construct it directly. For each name from names(), build it with make() and read its name(), isAvailable(), unavailableReason() and describe(). describe() returns an array with the keys model, endpoint, deterministic and notes. Return a plain array of rows with the keys name, available, reason, model and endpoint, taking model and endpoint from describe(). Every value must come from a method call; never write a bare constant. Perform no network call, no filesystem write and no shell call, read no API key, and name no specific provider.

## Files changed

- `app/Core/Engineer888/Reasoning/ProviderReadiness.php`

## Estimate

- complexity: n/a
- risk: n/a
- confidence: n/a

## Risks

- `app/Core/Engineer888/Reasoning/ProviderReadiness.php` — content authored by a reasoning provider (breaks: nothing by itself — but this file has been read by no human until the approval gate, so approval is a review and not a formality)

## Verification

- PASS — php -l app/Core/Engineer888/Reasoning/ProviderReadiness.php
- PASS — php artisan test -c phpunit.e888.xml

## Deployment verification

- verdict: HEALTHY_IDENTITY_UNPROVEN

