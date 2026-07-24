<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\Registry\CertificationChecklistRegistry;
use App\Engines\Infrastructure\States\CertificationLevel;
use Tests\TestCase;

/**
 * Phase 2B-R1 WS11 — guards the SINGLE authority on level requirements.
 *
 * `CertificationLevel::requiredDomains()` was a second, weaker representation of
 * the same policy. It is removed. This test fails if any weaker alternate policy
 * method reappears, so the drift cannot silently return.
 */
class CertificationPolicyAuthorityTest extends TestCase
{
    /** The removed method must stay removed. */
    public function test_no_duplicate_required_domains_method_exists(): void
    {
        $this->assertFalse(
            method_exists(CertificationLevel::class, 'requiredDomains'),
            'requiredDomains() was a second, weaker representation of level policy and '
            . 'must not return. forLevel() is the single authority.'
        );
    }

    /** forLevel() is monotonic: a higher level requires a superset of a lower one. */
    public function test_for_level_is_monotonic(): void
    {
        $prev = [];
        foreach (CertificationLevel::all() as $level) {
            $keys = array_keys(CertificationChecklistRegistry::forLevel($level, 'dns'));
            foreach ($prev as $earlier) {
                $this->assertContains($earlier, $keys,
                    "Level {$level} must require everything a lower level requires.");
            }
            $prev = $keys;
        }
    }

    /** Level 1 genuinely requires more than one domain, contradicting the old A-C shortcut. */
    public function test_level_one_requirements_span_the_expected_domains(): void
    {
        $checks = CertificationChecklistRegistry::forLevel(
            CertificationLevel::READ_ONLY_VERIFIED, 'dns'
        );
        $domains = array_unique(array_column($checks, 'domain'));

        // Ownership, credentials, capability AND security all appear at level 1.
        foreach (['A', 'B', 'C', 'E'] as $expected) {
            $this->assertContains($expected, $domains);
        }
    }

    public function test_only_level_four_permits_production(): void
    {
        $this->assertFalse(CertificationLevel::permitsProductionSelection(CertificationLevel::STAGING_CERTIFIED));
        $this->assertTrue(CertificationLevel::permitsProductionSelection(CertificationLevel::PRODUCTION_APPROVED));
    }
}
