<?php

namespace App\Core\Chat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * P2-B — the SEO assistant's existing pipeline, unchanged, behind the gate.
 *
 * This exists so the route stays thin and so the pipeline it runs is provably
 * the SAME code path as before: metering, persistence-on-refusal (P2-A) and
 * the SeoService call are reproduced here verbatim in the same order. The only
 * difference is that the gate decides whether this runs at all.
 *
 * @return array{0:array,1:int} [body, http status]
 */
final class SeoAssistantIdempotentRunner
{
    public static function run(Request $r, int $wsId, array $data, string $correlationId): array
    {
        $meter = app(\App\Core\Billing\CreditService::class)->meterChat($wsId, 'assistant_message');

        if (!$meter['sufficient']) {
            // P2-A behaviour, preserved exactly: the user's message survives a
            // refusal (clause P-02), and the failure is logged, never swallowed.
            $saved = false;
            try {
                DB::table('seo_assistant_messages')->insert([
                    'workspace_id' => $wsId,
                    'user_id'      => $r->user()?->id,
                    'role'         => 'user',
                    'content'      => mb_substr($data['message'], 0, 65535),
                    'created_at'   => now(),
                ]);
                $saved = true;
            } catch (\Throwable $e) {
                Log::error('chat.persist_failed', [
                    'stage' => 'seo_assistant_refusal', 'workspace_id' => $wsId,
                    'correlation_id' => $correlationId, 'exception' => $e->getMessage(),
                ]);
            }

            $copy = $saved
                ? "This workspace is out of credits, so the assistant can't reply right now. Your message has been saved — add credits and it'll pick up right where you left off."
                : "This workspace is out of credits, so the assistant can't reply right now. Add credits and it'll pick up right where you left off.";

            return [[
                'success'          => false,
                'error'            => $copy,
                'required_credits' => 1,
                'chat_counter'     => $meter['counter'],
                'message_saved'    => $saved,
                'chat_error'       => [
                    'code'            => 'CHAT_INSUFFICIENT_CREDITS',
                    'message'         => $copy,
                    'retryable'       => false,
                    'provider_called' => false,
                    'correlation_id'  => $correlationId,
                    'persistence'     => ['user_message_saved' => $saved, 'assistant_message_saved' => false],
                    'action'          => ['label' => 'Top up credits', 'href' => '/app/billing'],
                ],
            ], 402];
        }

        $context = $data['context'] ?? [];
        $context['user_id'] = $r->user()?->id;
        if (!empty($data['site_url'])) {
            $context['site_url'] = $data['site_url'];
        } elseif (empty($context['site_url']) && $h = $r->header('X-Lgse-Active-Site')) {
            $context['site_url'] = $h;
        }

        $result = app(\App\Engines\SEO\Services\SeoService::class)
            ->assistantMessage($wsId, $data['message'], $context);

        return [[
            'success'    => true,
            'data'       => $result,
            'chat_meter' => [
                'counter'        => $meter['counter'],
                'debited'        => $meter['debited'],
                'effective_cost' => '0.1 cr',
                'threshold'      => 10,
            ],
        ], 200];
    }
}
