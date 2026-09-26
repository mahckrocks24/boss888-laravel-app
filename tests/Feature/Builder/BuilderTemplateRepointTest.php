<?php

namespace Tests\Feature\Builder;

use App\Engines\Builder\Services\ArthurService;
use Tests\TestCase;

/**
 * ARTHUR888 F-ARTHUR-D-TEMPLATES (2026-09-03): several on-disk template manifests
 * (restaurant, catering, resort, short_term_rental, travel_agency, tutoring,
 * online_courses, retail_shop, ecommerce) were un-customised DENTAL clones — a
 * restaurant would render "Doctors & Team / Book a Consultation". They are now
 * re-pointed to genuinely appropriate existing templates. This locks that in.
 */
class BuilderTemplateRepointTest extends TestCase
{
    private function resolve(string $industry): string
    {
        $svc = app(ArthurService::class);
        $m = new \ReflectionMethod($svc, 'resolveTemplateSlug');
        $m->setAccessible(true);
        return (string) $m->invoke($svc, $industry);
    }

    private function dentalWordCount(string $slug): int
    {
        $p = storage_path("templates/{$slug}/manifest.json");
        if (!is_file($p)) return 0;
        // 2026-09-06: count DEFAULT VALUES only — the nine un-shadowed clones keep the skeleton's variable KEYS
        // (doctor_1_name…, stripped at build by TemplateArchetypes) but their default text must read like the industry.
        $m = json_decode((string) file_get_contents($p), true) ?: [];
        $n = 0;
        foreach (($m['variables'] ?? []) as $spec) {
            if (!is_array($spec) || !is_string($spec['default'] ?? null)) continue;
            $raw = strtolower($spec['default']);
            $n += substr_count($raw, 'doctor') + substr_count($raw, 'patient') + substr_count($raw, 'consultation');
        }
        return $n;
    }

    public function test_clone_industries_no_longer_resolve_to_dental_content(): void
    {
        foreach (['restaurant', 'catering', 'resort', 'short_term_rental', 'travel_agency',
                  'tutoring', 'online_courses', 'retail_shop', 'ecommerce'] as $industry) {
            $slug = $this->resolve($industry);
            $this->assertLessThan(5, $this->dentalWordCount($slug),
                "{$industry} still resolves to a dental-heavy template ({$slug})");
        }
    }

    /** 2026-09-05: the clones were un-shadowed into real templates (Owner decision) — each industry resolves to ITSELF. */
    public function test_clone_industries_resolve_to_their_own_template(): void
    {
        foreach (['restaurant', 'catering', 'resort', 'short_term_rental', 'travel_agency', 'tutoring', 'online_courses', 'retail_shop', 'ecommerce'] as $industry) {
            $this->assertSame($industry, $this->resolve($industry));
            $this->assertFileExists(storage_path("templates/{$industry}/template.html"));
        }
    }

    public function test_good_templates_unchanged(): void
    {
        $this->assertSame('dental', $this->resolve('dental'));
        $this->assertSame('cafe', $this->resolve('cafe'));
        $this->assertSame('gym', $this->resolve('gym'));
        $this->assertSame('hotel', $this->resolve('hotel'));
    }
}
