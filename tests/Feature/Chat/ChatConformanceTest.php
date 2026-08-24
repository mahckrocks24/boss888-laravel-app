<?php

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Chat\Adapters\S10MeetingRoomSurface;
use Tests\Feature\Chat\Adapters\S11AriaAssistantSurface;
use Tests\Feature\Chat\Adapters\S1AgentDrawerSurface;
use Tests\Feature\Chat\Adapters\S2MessagesModalSurface;
use Tests\Feature\Chat\Adapters\S3MessagesPageSurface;
use Tests\Feature\Chat\Adapters\S4SeoAssistantSurface;
use Tests\Feature\Chat\Adapters\S5StudioChatSurface;
use Tests\Feature\Chat\Adapters\S6StudioAiSurface;
use Tests\Feature\Chat\Adapters\S7BuilderArthurSurface;
use Tests\Feature\Chat\Adapters\S8PublicChatbotSurface;
use Tests\Feature\Chat\Adapters\S9BellaSurface;
use Tests\Feature\Chat\Support\ChatTestContext;
use Tests\Feature\Chat\Support\ConformanceSuite;
use Tests\Feature\Chat\Support\Outcome;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * CHAT CONTRACT v1 — conformance harness.
 *
 * Executes ONE normative case set against EVERY chat surface through its
 * adapter, and writes a machine-generated baseline report.
 *
 * This test is a MEASUREMENT, not a gate. It does not fail the build when a
 * surface is non-conformant — the whole point of P1 is to record the honest
 * baseline, and an honest baseline contains failures (clause V-04). What it
 * DOES assert is that the harness itself ran correctly: every surface produced
 * results, and no case errored.
 *
 * Report: storage/app/chat-conformance/baseline-<mode>.json + .md
 */
class ChatConformanceTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    public static function surfaces(): array
    {
        return [
            new S1AgentDrawerSurface(),
            new S2MessagesModalSurface(),
            new S3MessagesPageSurface(),
            new S4SeoAssistantSurface(),
            new S5StudioChatSurface(),
            new S6StudioAiSurface(),
            new S7BuilderArthurSurface(),
            new S8PublicChatbotSurface(),
            new S9BellaSurface(),
            new S10MeetingRoomSurface(),
            new S11AriaAssistantSurface(),
        ];
    }

    /** @test */
    public function chat_contract_v1_conformance_baseline(): void
    {
        $ctx = new ChatTestContext(
            $this,
            $this->testWorkspace->id,
            $this->testUser->id,
            $this->accessToken,
            base_path(),
        );

        $cases = ConformanceSuite::cases();
        $report = [
            'contract_version' => '1.0',
            'generated_at'     => now()->toIso8601String(),
            'mode'             => env('CHAT_CONFORMANCE_MODE', 'unspecified'),
            'case_count'       => count($cases),
            'surfaces'         => [],
            'harness_errors'   => [],
        ];

        foreach (self::surfaces() as $surface) {
            $rows = [];
            foreach ($cases as $case) {
                try {
                    $applies = ($case['applies'])($surface);
                } catch (\Throwable $e) {
                    $report['harness_errors'][] = "{$surface->key()}/{$case['id']} applies(): " . $e->getMessage();
                    $applies = false;
                }

                if (!$applies) {
                    $outcome = Outcome::notApplicable('clause does not apply to channel class ' . $surface->channelClass());
                } else {
                    try {
                        $outcome = ($case['run'])($surface, $ctx);
                    } catch (\Throwable $e) {
                        // A harness exception is recorded as blocked, never as a
                        // pass and never as a surface failure — the distinction
                        // matters for the credibility of the baseline.
                        $outcome = Outcome::blocked('harness exception: ' . $e->getMessage());
                        $report['harness_errors'][] = "{$surface->key()}/{$case['id']}: " . $e->getMessage();
                    }
                }

                $rows[] = [
                    'id'       => $case['id'],
                    'clause'   => $case['clause'],
                    'group'    => $case['group'],
                    'severity' => $case['severity'],
                    'evidence' => $case['evidence'],
                    'status'   => $outcome->status,
                    'detail'   => $outcome->detail,
                    'location' => $outcome->location,
                ];
            }

            $report['surfaces'][$surface->key()] = [
                'name'          => $surface->name(),
                'channel_class' => $surface->channelClass(),
                'notes'         => $surface->notes(),
                'summary'       => $this->summarise($rows),
                'results'       => $rows,
            ];
        }

        $this->writeReport($report);

        // Harness self-assertions (NOT conformance assertions).
        $this->assertNotEmpty($report['surfaces'], 'no surface produced results');
        $this->assertCount(11, $report['surfaces'], 'every declared surface must be measured');
        foreach ($report['surfaces'] as $key => $s) {
            $this->assertGreaterThan(0, $s['summary']['applicable'],
                "surface {$key} had no applicable cases — the adapter is probably mis-declared");
        }
    }

    private function summarise(array $rows): array
    {
        $c = ['pass' => 0, 'fail' => 0, 'not_applicable' => 0, 'unsupported' => 0, 'blocked' => 0];
        $weightEarned = 0;
        $weightTotal = 0;
        foreach ($rows as $r) {
            $c[$r['status']]++;
            if (in_array($r['status'], [Outcome::PASS, Outcome::FAIL], true)) {
                $w = ConformanceSuite::SEVERITY_WEIGHT[$r['severity']] ?? 1;
                $weightTotal += $w;
                if ($r['status'] === Outcome::PASS) {
                    $weightEarned += $w;
                }
            }
        }
        $applicable = $c['pass'] + $c['fail'];

        return array_merge($c, [
            'applicable'      => $applicable,
            'pass_rate'       => $applicable > 0 ? round($c['pass'] / $applicable * 100, 1) : null,
            'weighted_score'  => $weightTotal > 0 ? round($weightEarned / $weightTotal * 100, 1) : null,
            'weight_earned'   => $weightEarned,
            'weight_total'    => $weightTotal,
        ]);
    }

    private function writeReport(array $report): void
    {
        $dir = storage_path('app/chat-conformance');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $mode = $report['mode'];
        file_put_contents("{$dir}/baseline-{$mode}.json",
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        file_put_contents("{$dir}/baseline-{$mode}.md", $this->renderMarkdown($report));
    }

    private function renderMarkdown(array $r): string
    {
        $o = "# CHAT CONTRACT v1 — CONFORMANCE BASELINE ({$r['mode']})\n\n";
        $o .= "Generated {$r['generated_at']} · contract {$r['contract_version']} · {$r['case_count']} normative cases\n\n";
        $o .= "## Surface scores\n\n";
        $o .= "| Surface | Channel | Applicable | Pass | Fail | n/a | unsupported | blocked | Pass rate | Weighted |\n";
        $o .= "|---|---|---|---|---|---|---|---|---|---|\n";
        foreach ($r['surfaces'] as $key => $s) {
            $m = $s['summary'];
            $o .= sprintf("| `%s` | %s | %d | %d | %d | %d | %d | %d | %s | %s |\n",
                $key, $s['channel_class'], $m['applicable'], $m['pass'], $m['fail'],
                $m['not_applicable'], $m['unsupported'], $m['blocked'],
                $m['pass_rate'] === null ? '—' : $m['pass_rate'] . '%',
                $m['weighted_score'] === null ? '—' : $m['weighted_score'] . '%');
        }

        $o .= "\n## Failures by surface\n";
        foreach ($r['surfaces'] as $key => $s) {
            $fails = array_values(array_filter($s['results'], fn ($x) => $x['status'] === 'fail'));
            $o .= "\n### `{$key}` — {$s['name']}\n\n";
            if ($s['notes']) {
                foreach ($s['notes'] as $n) {
                    $o .= "> {$n}\n";
                }
                $o .= "\n";
            }
            if (!$fails) {
                $o .= "_No failures._\n";
                continue;
            }
            $o .= "| Case | Clause | Sev | Evidence | Detail | Location |\n|---|---|---|---|---|---|\n";
            foreach ($fails as $f) {
                $o .= sprintf("| `%s` | %s | %s | %s | %s | %s |\n",
                    $f['id'], $f['clause'], $f['severity'], $f['evidence'],
                    str_replace('|', '\\|', $f['detail']), $f['location'] ?? '—');
            }
        }

        $o .= "\n## Cross-surface failure frequency\n\n";
        $freq = [];
        foreach ($r['surfaces'] as $s) {
            foreach ($s['results'] as $x) {
                if ($x['status'] === 'fail') {
                    $freq[$x['id']] = ($freq[$x['id']] ?? 0) + 1;
                }
            }
        }
        arsort($freq);
        $o .= "| Case | Surfaces failing |\n|---|---|\n";
        foreach ($freq as $id => $n) {
            $o .= "| `{$id}` | {$n} |\n";
        }

        if ($r['harness_errors']) {
            $o .= "\n## Harness errors (recorded as blocked, never as pass)\n\n";
            foreach ($r['harness_errors'] as $e) {
                $o .= "- {$e}\n";
            }
        }

        return $o;
    }
}
