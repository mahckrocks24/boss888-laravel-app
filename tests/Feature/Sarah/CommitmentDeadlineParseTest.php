<?php

namespace Tests\Feature\Sarah;

use App\Core\Sarah888\CommitmentExtractor;
use Tests\TestCase;

/**
 * U4 (2026-09-02): relative deadline phrases ("by this Friday", "by tomorrow",
 * "by Monday") must resolve to an absolute date in the extracted commitment —
 * previously only absolute "14 September" dates parsed, so Sarah claimed a
 * deadline was set while sarah_commitments.deadline stayed NULL.
 */
class CommitmentDeadlineParseTest extends TestCase
{
    private function deadlineFor(string $text): ?string
    {
        $items = (new CommitmentExtractor())->extract($text);
        foreach ($items as $i) {
            if (!empty($i['deadline'])) return $i['deadline'];
        }
        return null;
    }

    public function test_this_friday_resolves(): void
    {
        $this->assertSame(date('Y-m-d', strtotime('friday')),
            $this->deadlineFor('Publish the winter menu page by this Friday.'));
    }

    public function test_tomorrow_resolves(): void
    {
        $this->assertSame(date('Y-m-d', strtotime('+1 day')),
            $this->deadlineFor('Ship the boxes by tomorrow.'));
    }

    public function test_bare_weekday_resolves(): void
    {
        $this->assertSame(date('Y-m-d', strtotime('monday')),
            $this->deadlineFor('Finish the report by Monday.'));
    }

    public function test_next_weekday_resolves(): void
    {
        $this->assertSame(date('Y-m-d', strtotime('next tuesday')),
            $this->deadlineFor('Send the proposal by next Tuesday.'));
    }

    public function test_absolute_date_still_resolves(): void
    {
        $this->assertNotNull($this->deadlineFor('Launch the site by 14 September.'));
    }

    public function test_no_date_stays_null(): void
    {
        $this->assertNull($this->deadlineFor('Improve the homepage copy.'));
    }
}
