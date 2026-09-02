<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\CommitmentExtractor;
use Tests\TestCase;

/**
 * P2 #4 (2026-09-02): a structured entity-add ("Add a lead: name, email, note") must not shatter into a
 * commitment per attribute. It is one record; the CRM/calendar tool creates it. Genuine work lists still expand.
 */
class CommitmentExtractorEntityTest extends TestCase
{
    private function x(): CommitmentExtractor
    {
        return app(CommitmentExtractor::class);
    }

    private function titles(array $hits): array
    {
        return array_map(fn ($h) => (string) ($h['title'] ?? $h['objective'] ?? $h['description'] ?? ''), $hits);
    }

    public function test_lead_add_does_not_shatter_into_fragments(): void
    {
        $hits = $this->x()->extract('Add a lead: Diego Marquez, diego.marquez@example.com, wants a custom anniversary cake for 12.');
        $titles = $this->titles($hits);
        // The email and bare name must NEVER become their own commitment.
        foreach ($titles as $t) {
            $this->assertStringNotContainsString('@example.com', $t === 'diego.marquez@example.com' ? $t : '', 'email must not be a standalone commitment');
        }
        $this->assertNotContains('diego.marquez@example.com', $titles, 'email is not a commitment');
        $this->assertNotContains('Diego Marquez', $titles, 'bare name is not a commitment');
        $this->assertLessThanOrEqual(1, count($hits), 'a lead-add yields at most one coherent commitment, never fragments');
    }

    public function test_contact_add_with_phone_does_not_shatter(): void
    {
        $hits = $this->x()->extract('Create a contact: Jane Roe, jane@roe.co, +1 555 123 4567, interested in bulk orders.');
        $this->assertNotContains('jane@roe.co', $this->titles($hits));
        $this->assertLessThanOrEqual(1, count($hits));
    }

    public function test_genuine_work_list_still_expands(): void
    {
        $hits = $this->x()->extract('Add these to the list: run sheet, seating plan, menu costing, wine pairings.');
        $titles = $this->titles($hits);
        $this->assertContains('run sheet', $titles);
        $this->assertContains('wine pairings', $titles);
        $this->assertGreaterThanOrEqual(3, count($hits), 'a real dictated list must still expand');
    }
}
