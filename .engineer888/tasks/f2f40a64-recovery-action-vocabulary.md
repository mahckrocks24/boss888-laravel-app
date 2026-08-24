# Recovery action vocabulary

- task: `f2f40a64-cde9-41b3-9e33-398e8782361a`
- project: LevelUp Growth / LevelUp Growth Platform
- kind: feature · priority: normal
- requested by: Mark <admin@levelupgrowth.io>
- approved by: not approved

## Description

Engineer888 records recovery actions as the constants on App\Core\Engineer888\Recovery\RecoveryCandidate: REMOVE_CREATED_FILE, RESTORE_UPDATED_FILE, RESTORE_DELETED_FILE, NO_ACTION and MANUAL_ONLY, of which only the first three appear in RecoveryCandidate::EXECUTABLE. Operators reading the audit trail see the raw constant, which does not say whether Engineer888 will act on it. Add a small read-only helper that turns one of those constants into a short human sentence and reports whether it is an action Engineer888 performs automatically. Choose the class name, method names and wording yourself. It must make no network call, no filesystem write and no shell call, and an unrecognised value must not throw.

## Files changed

- `app/Core/Engineer888/Recovery/RecoveryActionHelper.php`
- `tests/Feature/Engineer888/RecoveryActionHelperTest.php`

## Estimate

- complexity: n/a
- risk: n/a
- confidence: n/a

## Risks

- `app/Core/Engineer888/Recovery/RecoveryActionHelper.php` — content authored by a reasoning provider (breaks: nothing by itself — but this file has been read by no human until the approval gate, so approval is a review and not a formality)
- `tests/Feature/Engineer888/RecoveryActionHelperTest.php` — content authored by a reasoning provider (breaks: nothing by itself — but this file has been read by no human until the approval gate, so approval is a review and not a formality)

## Verification

- PASS — syntax
- PASS — class-load
- PASS — syntax
- PASS — class-load
- PASS — engineer888-safety-suite
- PASS — full-suite

## Deployment verification

- verdict: HEALTHY_IDENTITY_UNPROVEN

