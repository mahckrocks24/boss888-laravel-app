<?php

namespace App\Core\Engineer888\Reasoning;

use App\Core\Engineer888\Coordination\OwnershipManifest;

/**
 * Tests are part of the implementation, not a follow-up.
 *
 * Sprint 9 made INSUFFICIENT_EVIDENCE stop a workflow, which was correct and
 * one-sided: it punished a candidate for a gap the candidate was never asked to
 * fill. So the contract now asks. A proposal that changes executable behaviour
 * arrives with the test that would catch it going wrong, or with a justified
 * statement that it cannot be tested — and "it is a small change" is not that
 * statement.
 *
 * WHAT THIS CLASS WILL NOT DO is claim to know whether a test is *good*. It can
 * see that a test file exists, that it names the class it claims to cover, that
 * its assertions are not placeholders and that it does not depend on the state
 * of the working tree. Whether it asserts the right behaviour is a human's
 * judgement, and pretending otherwise would put a rubber stamp where a review
 * belongs.
 */
final class TestContract
{
    public const NON_TESTABLE = 'NON_TESTABLE';

    /** Paths whose change does not alter executable behaviour. */
    public const NON_EXECUTABLE_SUFFIXES = ['.md', '.txt', '.json', '.lock', '.yml', '.yaml'];

    /**
     * Assertions that assert nothing. A test built from these passes whatever
     * the implementation does, which is worse than no test because it reports
     * coverage.
     */
    public const PLACEHOLDER_ASSERTIONS = [
        'assertTrue(true)', 'assertTrue( true )', 'assertNotNull($this)',
        'assertSame(1, 1)', 'markTestIncomplete', 'markTestSkipped',
    ];

    /**
     * Calls that make a test depend on the repository as it happens to be right
     * now. Sprint 7 shipped one of these and it broke the moment the tree moved.
     */
    public const TRANSIENT_STATE = [
        'base_path(', 'app_path(', 'shell_exec', 'RepositoryIntelligence', 'WorkingTree',
    ];

    /** Databases a test may never target. */
    public const FORBIDDEN_DATABASES = [
        'levelup_staging', 'levelup_production', 'levelup_prod', 'levelup',
        'levelup_test', 'levelup_clean_test', 'boss888_test',
    ];

    /**
     * Does this candidate change executable behaviour?
     *
     * @param array<int,string> $paths
     */
    public static function changesBehaviour(array $paths): bool
    {
        foreach ($paths as $path) {
            if (str_starts_with($path, 'tests/')) { continue; }
            foreach (self::NON_EXECUTABLE_SUFFIXES as $suffix) {
                if (str_ends_with($path, $suffix)) { continue 2; }
            }

            return true;
        }

        return false;
    }

    /** @param array<int,string> $paths */
    public static function testPaths(array $paths): array
    {
        return array_values(array_filter($paths,
            fn ($p) => str_starts_with($p, 'tests/') && str_ends_with($p, 'Test.php')));
    }

    /** @param array<int,string> $paths */
    public static function implementationPaths(array $paths): array
    {
        return array_values(array_filter($paths, fn ($p) => ! str_starts_with($p, 'tests/')));
    }

    /**
     * Everything wrong with the coverage half of a candidate.
     *
     * @param array<string,mixed> $payload
     * @param array<string,string> $changeSet path => proposed content
     * @return array<int,array{rule:string,detail:string}>
     */
    public static function violations(array $payload, array $changeSet, string $repoPath): array
    {
        $violations = [];
        $paths = array_keys($changeSet);

        if (! self::changesBehaviour($paths)) {
            return $violations;   // documentation and configuration are not code
        }

        $coverage = $payload['test_coverage'] ?? null;

        if (! is_array($coverage)) {
            return [['rule' => 'missing_test_coverage',
                     'detail' => 'this candidate changes executable behaviour and returned no '
                               . 'test_coverage section. Tests are part of the implementation.']];
        }

        foreach (['behaviour_changed', 'regression_prevented', 'confidence'] as $key) {
            if (! isset($coverage[$key]) || trim((string) $coverage[$key]) === '') {
                $violations[] = ['rule' => 'vague_test_coverage',
                                 'detail' => "test_coverage.{$key} is empty; a blank coverage claim reads "
                                           . 'downstream as a satisfied one'];
            }
        }

        $proposed = self::testPaths($paths);
        $relied = array_values(array_filter((array) ($coverage['existing_tests'] ?? []), 'is_string'));
        $nonTestable = strtoupper((string) ($coverage['non_testable_reason'] ?? '')) !== ''
            || strtoupper((string) ($coverage['classification'] ?? '')) === self::NON_TESTABLE;

        if ($proposed === [] && $relied === [] && ! $nonTestable) {
            $violations[] = ['rule' => 'no_coverage',
                             'detail' => 'no test file is proposed, no existing test is named, and no '
                                       . 'NON_TESTABLE justification is given'];
        }

        if ($nonTestable) {
            foreach (['non_testable_reason', 'alternative_verification', 'risk'] as $key) {
                if (trim((string) ($coverage[$key] ?? '')) === '') {
                    $violations[] = ['rule' => 'unjustified_non_testable',
                                     'detail' => "NON_TESTABLE requires {$key}"];
                }
            }
            $reason = strtolower((string) ($coverage['non_testable_reason'] ?? ''));
            if (str_contains($reason, 'small change') || str_contains($reason, 'trivial')
                || str_contains($reason, 'simple change')) {
                $violations[] = ['rule' => 'unjustified_non_testable',
                                 'detail' => 'size is not a reason a change cannot be tested'];
            }
        }

        // UNKNOWN is a sanctioned answer everywhere else in this contract, and a
        // provider that writes it into a list is admitting it does not know —
        // not inventing a file called UNKNOWN. Observed on the first real-model
        // run of this contract, 2026-08-02.
        $relied = array_values(array_filter($relied,
            fn ($t) => strtoupper(trim($t)) !== CandidateImplementation::UNKNOWN && trim($t) !== ''));

        // An existing test that is relied on must actually exist.
        foreach ($relied as $existing) {
            if (! is_file(rtrim($repoPath, '/') . '/' . ltrim($existing, '/'))) {
                $violations[] = ['rule' => 'invented_coverage',
                                 'detail' => "existing test {$existing} does not exist in this repository"];
            }
        }

        $implementation = self::implementationPaths($paths);

        foreach ($proposed as $testPath) {
            $content = (string) ($changeSet[$testPath] ?? '');

            foreach (self::PLACEHOLDER_ASSERTIONS as $placeholder) {
                if (str_contains($content, $placeholder)) {
                    $violations[] = ['rule' => 'placeholder_assertion',
                                     'detail' => "{$testPath} contains '{$placeholder}', which passes "
                                               . 'whatever the implementation does'];
                }
            }

            foreach (self::TRANSIENT_STATE as $transient) {
                if (str_contains($content, $transient)) {
                    $violations[] = ['rule' => 'transient_state_test',
                                     'detail' => "{$testPath} depends on {$transient}, so it asserts the "
                                               . 'state of the working tree rather than the behaviour'];
                }
            }

            foreach (self::FORBIDDEN_DATABASES as $database) {
                if (preg_match('/[\'"]' . preg_quote($database, '/') . '[\'"]/', $content)) {
                    $violations[] = ['rule' => 'forbidden_test_database',
                                     'detail' => "{$testPath} names {$database}; a test must use the "
                                               . 'database assigned to this session and no other'];
                }
            }

            // Does it mention anything it claims to cover? This is a weak check
            // and it is stated as one — it catches a test attached to the wrong
            // change, not a test that asserts the wrong thing.
            if ($implementation !== [] && ! self::referencesAny($content, $implementation)) {
                $violations[] = ['rule' => 'unrelated_test',
                                 'detail' => "{$testPath} does not reference any changed implementation "
                                           . 'file or class, so it cannot be shown to exercise this change'];
            }
        }

        return $violations;
    }

    /**
     * Ownership of proposed test files, checked separately.
     *
     * @param array<int,string> $paths
     * @return array<int,array{rule:string,detail:string}>
     */
    public static function ownershipViolations(array $paths, ?OwnershipManifest $manifest): array
    {
        $violations = [];

        foreach (self::testPaths($paths) as $testPath) {
            $status = $manifest?->classify($testPath)['status'] ?? OwnershipManifest::UNKNOWN;

            if (! OwnershipManifest::isCommittable($status)) {
                $violations[] = ['rule' => 'unowned_test_path',
                                 'detail' => "{$testPath} is not owned by this sprint ({$status}); a test "
                                           . 'placed in another engineer\'s directory is their problem, not coverage'];
            }
        }

        return $violations;
    }

    /**
     * Does this test mention anything it claims to cover?
     *
     * Word boundaries, and the test's OWN declaration is removed first: a
     * file called ThingTest contains the string 'Thing', so a substring
     * search reported coverage for a test that referenced nothing but
     * itself. Caught by the suite, which is where it should be caught.
     *
     * @param array<int,string> $implementation
     */
    private static function referencesAny(string $content, array $implementation): bool
    {
        $body = preg_replace('/^\s*(?:final\s+|abstract\s+)?class\s+\w+.*$/m', '', $content) ?? $content;

        foreach ($implementation as $path) {
            if (str_contains($body, $path)) { return true; }

            $class = basename($path, '.php');
            if ($class === '') { continue; }
            if (preg_match('/\b' . preg_quote($class, '/') . '\b/', $body)) { return true; }
        }

        return false;
    }
}
